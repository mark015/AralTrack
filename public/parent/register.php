<?php
// Parents create their own account using their child's LRN and the link code from the school.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (current_parent()) {
    redirect('parent/index.php');
}
if (!Access::parentRegistrationOpen()) {
    Access::stopPage(
        'New parent accounts are closed',
        Access::loginOpen('parent')
            ? 'The school is not accepting new parent accounts right now. If you already have an account, sign in. Otherwise, ask the registrar.'
            : Access::closedMessage('parent')
    );
}

$form = ['full_name' => '', 'login' => '', 'lrn' => '', 'code' => '', 'relationship' => 'mother'];
$errors = [];

if (is_post()) {
    $form = [
        'full_name'    => post('full_name'),
        'login'        => post('login'),
        'lrn'          => post('lrn'),
        'code'         => post('code'),
        'relationship' => post('relationship'),
    ];
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    $login = normalize_parent_login($form['login']);

    if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 150) {
        $errors[] = 'Enter your full name.';
    }
    if ($login === null) {
        $errors[] = 'Enter a mobile number (like 0917 123 4567) or an email address.';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'Choose a password of at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    if (!isset(RELATIONSHIPS[$form['relationship']])) {
        $errors[] = 'Choose your relationship to the learner.';
    }
    if (post('privacy') !== '1') {
        $errors[] = 'Please read and agree to the privacy notice.';
    }

    $student = null;
    if (!$errors) {
        [$student, $error] = check_parent_link_code($form['lrn'], $form['code']);
        if ($error) {
            $errors[] = $error;
        } else {
            Workspace::switchTo((int) $student['workspace_id']); // the learner's school decides
            if (Workspace::isSuspended()) {
                $errors[] = Workspace::suspendedMessage();
            } elseif (!Access::parentRegistrationOpen()) {
                $errors[] = 'The school is not accepting new parent accounts right now. Please ask the registrar.';
            } elseif (db_value('SELECT 1 FROM parents WHERE workspace_id = ? AND login = ?', [ws(), $login])) {
                $errors[] = 'An account with this mobile number or email already exists at this school. Sign in, then use "Add a child".';
            } else {
                // The same number or email at another school (or teacher): one password opens all of them, so it
                // must be that account's password. Otherwise: sign in there and use "Add a child".
                $elsewhere = db_all('SELECT password_hash FROM parents WHERE login = ?', [$login]);
                if ($elsewhere && !array_filter($elsewhere, static fn ($r) => password_verify($password, $r['password_hash']))) {
                    $errors[] = 'This mobile number or email already has a parent account at another school. Use the same password as that account, '
                        . 'so one sign-in opens both. Or sign in with it and use "Add a child".';
                }
            }
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_exec(
                'INSERT INTO parents (workspace_id, full_name, login, password_hash, privacy_accepted_at) VALUES (?, ?, ?, ?, ?)',
                [ws(), $form['full_name'], $login, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]
            );
            $parentId = (int) $pdo->lastInsertId();
            db_exec('INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)', [$parentId, $student['id'], $form['relationship']]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if (!is_duplicate_error($e)) {
                throw $e;
            }
            $errors[] = 'An account with this mobile number or email already exists at this school. Sign in, then use "Add a child".';
        }
        if (!$errors) {
            Privacy::record('parent', $parentId, ws()); // agreed to the privacy notice on this form
            login_parent(['id' => $parentId, 'full_name' => $form['full_name'], 'workspace_id' => ws()]);
            // Together with the same parent's records at other schools (same password)
            $mine = array_filter(db_all('SELECT * FROM parents WHERE login = ? AND is_active = 1', [$login]), static fn ($r) => password_verify($password, $r['password_hash']));
            usort($mine, static fn ($a, $b) => ((int) $b['id'] === $parentId) <=> ((int) $a['id'] === $parentId)); // this school first
            Accounts::remember('parent', $mine);
            Access::logLogin('parent', $parentId, (string) normalize_parent_login($form['login']), true, 'registered');
            flash('success', "Your account is ready. {$student['first_name']}'s attendance and grades are below.");
            redirect('parent/index.php');
        }
    }
}

$title = 'Create a parent account';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon gold"><i class="bi bi-person-plus-fill" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1">Create a parent account</h1>
                <p class="text-secondary">
                    You need your child's <strong>LRN</strong> and the <strong>link code</strong> on the slip from the school.
                    Ask the adviser if you don't have one.
                </p>
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="post" action="<?= e(url('parent/register.php')) ?>">
                    <?= csrf_field() ?>
                    <h2 class="h6 mt-2">About you</h2>
                    <div class="mb-3">
                        <label class="form-label" for="full_name">Your full name</label>
                        <input class="form-control" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="150" autocomplete="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="login">Mobile number or email</label>
                        <input class="form-control" id="login" name="login" value="<?= e($form['login']) ?>" maxlength="150" autocomplete="username" required aria-describedby="loginHelp">
                        <div id="loginHelp" class="form-text">You will use this to sign in. Nothing is sent to it.</div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="confirm">Type it again</label>
                            <input class="form-control" type="password" id="confirm" name="confirm" minlength="8" autocomplete="new-password" required>
                        </div>
                    </div>
                    <h2 class="h6 mt-4">Your child</h2>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="lrn">Learner's LRN</label>
                            <input class="form-control lrn" id="lrn" name="lrn" value="<?= e($form['lrn']) ?>" inputmode="numeric" maxlength="16" autocomplete="off" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="code">Link code</label>
                            <input class="form-control lrn text-uppercase" id="code" name="code" value="<?= e($form['code']) ?>" maxlength="9" placeholder="ABCD-EFGH" autocomplete="off" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="relationship">You are the learner's</label>
                        <select class="form-select" id="relationship" name="relationship">
                            <?php foreach (RELATIONSHIPS as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $form['relationship'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="privacy" value="1" id="privacy" required>
                        <label class="form-check-label small" for="privacy">
                            I have read the <a href="<?= e(url('privacy.php')) ?>" target="_blank">privacy notice</a> and agree to the school
                            keeping my account details and showing me my child's records (Data Privacy Act, RA 10173).
                        </label>
                    </div>
                    <button class="btn btn-primary w-100">Create account</button>
                </form>
            </div>
        </div>
        <p class="text-center small text-secondary mt-3">Already have an account? <a href="<?= e(url('parent/login.php')) ?>">Sign in</a>.</p>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
