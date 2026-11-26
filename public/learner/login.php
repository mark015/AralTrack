<?php
// Learner sign-in. The LRN finds the learner's records; each school decides what else it asks (Setup → Settings):
// nothing (LRN only), the birthdate, or a password. A learner with records at several schools (or teachers) is
// signed in to every record whose school's rule is met, and switches between them (src/Accounts.php).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (current_student_id() !== null) {
    redirect('learner/index.php');
}

$error = null;
$needsActivation = false;
$lrn = normalize_lrn(get_str('lrn'));
$birthdate = '';
$askBirthdate = false;
$askPassword = false;
$optional = false; // some of the learner's schools ask nothing more, so the extra boxes may stay empty

if (is_post()) {
    $lrn = normalize_lrn(post('lrn'));
    $birthdate = post('birthdate');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $secondStep = post('step') === '2';
    $ip = client_ip();

    if (!is_valid_lrn($lrn)) {
        $error = 'Enter your 12-digit LRN.';
    } elseif (RateLimiter::isLocked('student', $lrn, $ip)) {
        $error = 'Too many unsuccessful tries. Please wait ' . config('login_window_min') . ' minutes and try again.';
        Access::logLogin('learner', null, $lrn, false, 'locked');
    } else {
        // Each record with its school's rule; records at paused or closed schools, or turned off, can't sign in
        $records = [];
        $refusal = null;
        foreach (learner_records_for_lrn($lrn) as $record) {
            $check = Workspace::within((int) $record['workspace_id'], static fn (): array => [
                'mode' => (string) setting('learner_login'),
                'refusal' => match (true) {
                    Workspace::isSuspended() => [Workspace::suspendedMessage(), 'school paused'],
                    !Access::loginOpen('learner') => [Access::closedMessage('learner'), Access::maintenance() ? 'maintenance' : 'sign-in off'],
                    default => null,
                },
            ]);
            if ($check['refusal'] !== null) {
                $refusal ??= $check['refusal'][0];
                Access::logLogin('learner', (int) $record['id'], $lrn, false, $check['refusal'][1]);
                continue;
            }
            $records[] = $record + ['login_mode' => $check['mode']];
        }
        $modes = array_column($records, 'login_mode');
        $askBirthdate = in_array('lrn_birthdate', $modes, true);
        $askPassword = in_array('password', $modes, true);
        $optional = in_array('lrn', $modes, true) && ($askBirthdate || $askPassword);
        $activation = array_filter($records, static fn ($r) => $r['login_mode'] === 'password' && $r['password_hash'] === null);

        if (!$records) {
            if ($refusal !== null) {
                $error = $refusal;
            } else {
                RateLimiter::recordFailure('student', $lrn, $ip);
                Access::logLogin('learner', null, $lrn, false, 'no match');
                $error = 'No learner with this LRN was found. Check the number on your report card, or ask your adviser.';
            }
        } elseif (($askBirthdate || $askPassword) && !$secondStep) {
            // The form shows the birthdate and/or password box next
            $needsActivation = $activation && count($activation) === count($records);
        } else {
            $passed = array_values(array_filter($records, static fn (array $r): bool => match ($r['login_mode']) {
                'lrn_birthdate' => valid_ymd($birthdate) && hash_equals((string) $r['birthdate'], $birthdate),
                'password' => $r['password_hash'] !== null && $password !== '' && password_verify($password, $r['password_hash']),
                default => true,
            }));
            $blocked = array_filter($passed, static fn ($r) => (bool) $r['login_blocked']);
            $open = array_values(array_filter($passed, static fn ($r) => !$r['login_blocked']));
            if ($open) {
                RateLimiter::clear('student', $lrn);
                login_student((int) $open[0]['id']);
                Accounts::remember('learner', $open);
                foreach ($open as $record) {
                    Access::logLogin('learner', (int) $record['id'], $lrn, true);
                }
                redirect('learner/index.php');
            } elseif ($blocked) {
                $first = reset($blocked);
                $error = 'Your access to the portal was turned off by the school.' . ($first['blocked_reason'] ? ' Reason: ' . $first['blocked_reason'] . '.' : '') . ' Please talk to your adviser.';
                Access::logLogin('learner', (int) $first['id'], $lrn, false, 'turned off');
            } else {
                RateLimiter::recordFailure('student', $lrn, $ip);
                Access::logLogin('learner', (int) $records[0]['id'], $lrn, false, 'no match');
                $error = match (true) {
                    $askPassword && $askBirthdate => 'The LRN, birthdate and password do not match.',
                    $askPassword => 'The LRN and password do not match.',
                    default => 'The LRN and birthdate do not match our records.',
                };
                $needsActivation = $activation && $password === '' && !$askBirthdate;
            }
        }
    }
}
$secondBox = $askBirthdate || $askPassword;

$title = 'Learner sign-in';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-6 col-lg-4">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1">Check your grades</h1>
                <p class="text-secondary small mb-4">Learners sign in with their LRN.</p>
                <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                <?php if ($needsActivation): ?>
                    <div class="alert alert-info" role="alert">
                        Your account isn't activated yet. <a class="fw-semibold" href="<?= e(url('learner/activate.php?lrn=' . $lrn)) ?>">Activate it with your birthdate</a> and choose a password.
                    </div>
                <?php endif; ?>
                <form method="post" action="<?= e(url('learner/login.php')) ?>">
                    <?= csrf_field() ?>
                    <?php if ($secondBox): ?><input type="hidden" name="step" value="2"><?php endif; ?>
                    <div class="mb-3">
                        <label for="lrn" class="form-label">Learner Reference Number (LRN)</label>
                        <input type="text" class="form-control form-control-lg lrn" id="lrn" name="lrn"
                               value="<?= e($lrn) ?>" inputmode="numeric" autocomplete="off" maxlength="16"
                               placeholder="12 digits" required <?= $secondBox ? '' : 'autofocus' ?> aria-describedby="lrnHelp">
                        <div id="lrnHelp" class="form-text">It's on your report card and school ID.</div>
                    </div>
                    <?php if ($askBirthdate): ?>
                        <div class="mb-3">
                            <label for="birthdate" class="form-label">Birthdate</label>
                            <input type="date" class="form-control form-control-lg" id="birthdate" name="birthdate"
                                   value="<?= e($birthdate) ?>" max="<?= e(date('Y-m-d')) ?>" <?= $optional ? '' : 'required' ?> autofocus>
                            <div class="form-text"><?= $optional ? 'Only if your school asks for it.' : 'Your school asks for your birthdate too.' ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($askPassword): ?>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control form-control-lg" id="password" name="password" autocomplete="current-password"
                                   <?= $optional || $askBirthdate ? '' : 'required' ?> <?= $askBirthdate ? '' : 'autofocus' ?>>
                            <div class="form-text"><?= $optional || $askBirthdate ? 'Only if your school gave you one. ' : '' ?>First time here? <a href="<?= e(url('learner/activate.php?lrn=' . $lrn)) ?>">Activate your account</a>.</div>
                        </div>
                    <?php endif; ?>
                    <button class="btn btn-primary btn-lg w-100 mt-2">
                        <?= $secondBox ? 'View my grades' : 'Continue' ?> <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                    </button>
                </form>
            </div>
        </div>
        <p class="text-center small text-secondary mt-3">
            Parent or guardian? <a href="<?= e(url('parent/login.php')) ?>">Parent sign-in</a> ·
            Teacher or staff? <a href="<?= e(url('staff/login.php')) ?>">Staff sign-in</a>
        </p>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
