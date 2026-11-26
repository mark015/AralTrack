<?php
// Accepting a school's invitation (src/Invites.php). Without an account: create one in the school. Signed in as a
// teacher who uses the system on their own: see what will move, match sections, then bring everything into the
// school (src/WorkspaceMerge.php).
require dirname(__DIR__) . '/src/bootstrap.php';

$token = is_post() ? post('token') : get_str('token');
$invite = Invites::find($token);
$staff = current_staff() ? require_staff() : null;
$me = $staff ? db_one('SELECT * FROM users WHERE id = ?', [$staff['id']]) : null;
$errors = [];
$plan = null;
$form = ['full_name' => ''];
$choices = [];
foreach ((array) ($_POST['sections'] ?? []) as $from => $to) {
    $choices[(int) $from] = (int) $to;
}

$state = match (true) {
    $invite === null => 'invalid',
    $invite['school_status'] !== 'active' => 'paused',
    $staff === null && db_value('SELECT 1 FROM users WHERE email = ?', [$invite['email']]) => 'sign-in',
    $staff === null => 'create',
    mb_strtolower((string) $me['email']) !== $invite['email'] => 'other-account',
    (int) $me['workspace_id'] === (int) $invite['workspace_id'] => 'already',
    Workspace::isSolo() => 'bring',
    default => 'other-school',
};
if ($state === 'sign-in') {
    $_SESSION['join_token'] = $token; // staff/login.php comes back here after signing in
}

if (is_post() && $state === 'create') {
    $form['full_name'] = post('full_name');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    if (mb_strlen($form['full_name']) < 2 || mb_strlen($form['full_name']) > 150) {
        $errors[] = 'Enter your full name.';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'Choose a password of at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    if (!$errors) {
        $base = substr((string) preg_replace('/[^a-z0-9._-]/', '', strtolower((string) strstr($invite['email'], '@', true))), 0, 30);
        $username = strlen($base) >= 3 ? $base : 'staff';
        while (db_value('SELECT 1 FROM users WHERE username = ?', [$username])) {
            $username = $base . random_int(10, 99999);
        }
        // The link went to this email address, so it counts as confirmed
        db_exec(
            'INSERT INTO users (workspace_id, username, email, email_verified_at, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$invite['workspace_id'], $username, $invite['email'], date('Y-m-d H:i:s'), password_hash($password, PASSWORD_DEFAULT), $form['full_name'], $invite['role']]
        );
        $user = db_one('SELECT * FROM users WHERE id = ?', [(int) db()->lastInsertId()]);
        Invites::markAccepted((int) $invite['id'], (int) $user['id']);
        login_staff($user);
        Access::logLogin('staff', (int) $user['id'], $invite['email'], true, 'invitation');
        Access::log('account', "{$user['full_name']} accepted the invitation and created their account (" . (STAFF_ROLES[$user['role']] ?? $user['role']) . ')', 'staff', (int) $user['id']);
        flash('success', "Welcome to {$invite['school_name']}! Sign in next time with {$invite['email']}.");
        redirect(home_for(current_staff()));
    }
}

if ($state === 'bring') {
    $plan = WorkspaceMerge::plan(ws(), (int) $invite['workspace_id'], $choices);
    if (is_post() && post('action') === 'join') {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (!password_verify($password, (string) $me['password_hash'])) {
            $errors[] = 'Your password is not correct.';
        } elseif ($plan['problems']) {
            $errors[] = 'Solve the problems below first.';
        } else {
            $from = ws();
            $counts = [count($plan['classes']), count($plan['students'])];
            WorkspaceMerge::run($plan, (int) $me['id'], (string) $invite['role']);
            Invites::markAccepted((int) $invite['id'], (int) $me['id']);
            Workspace::reset();
            login_staff(db_one('SELECT * FROM users WHERE id = ?', [$me['id']]));
            Access::log('account', "{$me['full_name']} joined the school and brought {$counts[0]} class(es) and {$counts[1]} learner record(s)", 'staff', (int) $me['id']);
            platform_log('join', "{$me['full_name']} ({$me['email']}) moved their classes into {$invite['school_name']}", (int) $invite['workspace_id']);
            flash('success', "You're now part of {$invite['school_name']}. Your classes, learners and records are here.");
            redirect(home_for(current_staff()));
        }
    }
}

$count = static fn (array $map, bool $matched): int => count(array_filter($map, static fn ($v) => $matched ? $v > 0 : $v === 0));
$title = $invite ? 'Join ' . $invite['school_name'] : 'Invitation';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center">
    <div class="<?= $state === 'bring' ? 'col-lg-9' : 'col-md-7 col-lg-5' ?>">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-buildings" aria-hidden="true"></i></div>
                <?php if ($errors): ?><div class="alert alert-danger" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

                <?php if ($state === 'invalid'): ?>
                    <h1 class="h4">This invitation doesn't work anymore</h1>
                    <p class="text-secondary mb-0">It was already used, cancelled, or is more than <?= Invites::DAYS ?> days old. Ask the school for a new one.</p>

                <?php elseif ($state === 'paused'): ?>
                    <h1 class="h4"><?= e($invite['school_name']) ?></h1>
                    <p class="text-secondary mb-0">This school's account is paused right now. Please try again later.</p>

                <?php elseif ($state === 'sign-in'): ?>
                    <h1 class="h4">Join <?= e($invite['school_name']) ?></h1>
                    <p class="text-secondary">You already have an account with <?= e($invite['email']) ?>. Sign in, and you'll come back here to accept.</p>
                    <a class="btn btn-primary" href="<?= e(url('staff/login.php')) ?>">Sign in</a>

                <?php elseif ($state === 'create'): ?>
                    <h1 class="h4 mb-1">Join <?= e($invite['school_name']) ?></h1>
                    <p class="text-secondary small mb-4">as <?= e(strtolower(Invites::ROLES[$invite['role']] ?? 'staff')) ?> · You'll sign in with <strong><?= e($invite['email']) ?></strong>.</p>
                    <form method="post" action="<?= e(url('join.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="full_name">Your full name</label>
                            <input class="form-control" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="150" autocomplete="name" required autofocus>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-sm-6"><label class="form-label" for="password">Password</label><input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" required></div>
                            <div class="col-sm-6"><label class="form-label" for="confirm">Password again</label><input class="form-control" type="password" id="confirm" name="confirm" minlength="8" autocomplete="new-password" required></div>
                        </div>
                        <button class="btn btn-primary w-100">Create my account</button>
                    </form>

                <?php elseif ($state === 'other-account'): ?>
                    <h1 class="h4">This invitation is for <?= e($invite['email']) ?></h1>
                    <p class="text-secondary mb-0">You're signed in with another account. Sign out first, then open the link again.</p>

                <?php elseif ($state === 'already'): ?>
                    <h1 class="h4">You're already part of <?= e($invite['school_name']) ?></h1>
                    <a class="btn btn-primary" href="<?= e(url(home_for($staff))) ?>">Go to my classes</a>

                <?php elseif ($state === 'other-school'): ?>
                    <h1 class="h4">Your account belongs to another school</h1>
                    <p class="text-secondary mb-0">An account can be in one school. Ask <?= e($invite['school_name']) ?> to invite another email address, or ask your current school to turn your account off first.</p>

                <?php else: /* bring */ ?>
                    <h1 class="h4 mb-1">Join <?= e($invite['school_name']) ?> and bring your classes</h1>
                    <p class="text-secondary">Everything in your own space moves into the school: your classes with their grades, attendance, class records and materials, your learners and their parents, and your messages. Nothing is lost.</p>

                    <div class="row g-3 mb-4 text-center">
                        <?php foreach ([
                            ['Classes', count($plan['classes']), null],
                            ['Learners', count($plan['students']), $count($plan['studentMap'], true) . ' already at the school (same LRN)'],
                            ['Parents', count($plan['parents']), $count($plan['parentMap'], true) . ' already at the school'],
                            ['Sections', count($plan['sections']), $count($plan['sectionMap'], true) . ' matched to the school\'s'],
                        ] as [$label, $n, $note]): ?>
                            <div class="col-6 col-md-3">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="h4 mb-0"><?= (int) $n ?></div>
                                    <div class="small fw-semibold"><?= e($label) ?></div>
                                    <?php if ($note): ?><div class="small text-secondary"><?= e($note) ?></div><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($plan['problems']): ?>
                        <div class="alert alert-danger" role="alert"><div><strong>To solve first</strong><ul class="mb-0 ps-3"><?php foreach ($plan['problems'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul></div></div>
                    <?php endif; ?>
                    <?php if ($plan['warnings']): ?>
                        <div class="alert alert-warning small" role="status"><div><ul class="mb-0 ps-3"><?php foreach ($plan['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div></div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('join.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <?php if ($plan['sections']): ?>
                            <h2 class="h6 mt-2">Your sections</h2>
                            <p class="small text-secondary">Match each of your sections to the school's section with the same learners, or bring it as a new section.</p>
                            <div class="table-responsive mb-3">
                                <table class="table align-middle mb-0">
                                    <thead><tr><th scope="col">Your section</th><th scope="col">At the school</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($plan['sections'] as $sec): $id = (int) $sec['id']; ?>
                                        <tr>
                                            <td><?= e(section_label($sec)) ?> <span class="small text-secondary">· S.Y. <?= e($sec['sy_label']) ?> · <?= (int) $sec['learners'] ?> learners</span></td>
                                            <td style="min-width: 14rem">
                                                <select class="form-select form-select-sm" name="sections[<?= $id ?>]" aria-label="School section for <?= e(section_label($sec)) ?>">
                                                    <option value="0">Bring as a new section</option>
                                                    <?php foreach ($plan['sectionOptions'][$id] as $o): ?>
                                                        <option value="<?= (int) $o['id'] ?>"<?= $plan['sectionMap'][$id] === (int) $o['id'] ? ' selected' : '' ?>><?= e(section_label($o)) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary mb-4" name="action" value="preview">Update the preview</button>
                        <?php endif; ?>

                        <div class="border-top pt-3">
                            <p class="small text-secondary">
                                Afterwards you sign in the same way, as a <?= e(strtolower(Invites::ROLES[$invite['role']] ?? 'teacher')) ?> of <?= e($invite['school_name']) ?>.
                                Your own space closes and the school's plan covers you. If you leave the school later, the records stay with the school.
                            </p>
                            <div class="row g-2 align-items-end">
                                <div class="col-sm-6">
                                    <label class="form-label small" for="password">Your password, to confirm</label>
                                    <input class="form-control" type="password" id="password" name="password" autocomplete="current-password">
                                </div>
                                <div class="col-sm-6">
                                    <button class="btn btn-primary w-100" name="action" value="join"<?= $plan['problems'] ? ' disabled' : '' ?>>Join and bring my classes</button>
                                </div>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
