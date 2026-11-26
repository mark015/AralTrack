<?php
// First sign-in when learners use passwords: confirm the account with LRN + birthdate, then choose a password.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$error = null;
$lrn = normalize_lrn(get_str('lrn'));

if (is_post()) {
    $lrn = normalize_lrn(post('lrn'));
    $birthdate = post('birthdate');
    $new = is_string($_POST['new'] ?? null) ? $_POST['new'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    $ip = client_ip();
    if (!is_valid_lrn($lrn) || !valid_ymd($birthdate)) {
        $error = 'Enter your 12-digit LRN and your birthdate.';
    } elseif (mb_strlen($new) < 8) {
        $error = 'Choose a password of at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'The two passwords do not match.';
    } elseif (RateLimiter::isLocked('student', $lrn, $ip)) {
        $error = 'Too many unsuccessful tries. Please wait ' . config('login_window_min') . ' minutes and try again.';
    } else {
        // The record to activate: at a school that uses learner passwords, not activated yet (else the first one)
        $records = learner_records_for_lrn($lrn);
        $modes = [];
        foreach ($records as $record) {
            $modes[(int) $record['id']] = Workspace::within((int) $record['workspace_id'], static fn () => (string) setting('learner_login'));
        }
        $student = null;
        foreach ($records as $record) {
            if ($modes[(int) $record['id']] === 'password' && $record['password_hash'] === null) {
                $student = $record;
                break;
            }
        }
        $student ??= $records[0] ?? null;
        if ($student) {
            Workspace::switchTo((int) $student['workspace_id']); // the learner's school decides
        }
        if ($student && (Workspace::isSuspended() || !Access::loginOpen('learner'))) {
            $error = Workspace::isSuspended() ? Workspace::suspendedMessage() : Access::closedMessage('learner');
        } elseif ($student && setting('learner_login') !== 'password') {
            $error = 'Your school does not use learner passwords. Go back and sign in with your LRN.';
        } elseif ($student && $student['password_hash'] !== null) {
            $error = 'This account is already activated. Sign in with your password, or ask the registrar to reset it.';
        } elseif ($student && hash_equals($student['birthdate'], $birthdate) && $student['login_blocked']) {
            $error = 'Your access to the portal was turned off by the school. Please talk to your adviser.';
            Access::logLogin('learner', (int) $student['id'], $lrn, false, 'turned off');
        } elseif ($student && hash_equals($student['birthdate'], $birthdate)) {
            // The same password for this learner's other records that wait for one (same birthdate)
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $activated = [];
            foreach ($records as $record) {
                if ((int) $record['id'] === (int) $student['id'] || ($modes[(int) $record['id']] === 'password'
                        && $record['password_hash'] === null && hash_equals((string) $record['birthdate'], $birthdate) && !$record['login_blocked'])) {
                    db_exec('UPDATE students SET password_hash = ? WHERE id = ?', [$hash, $record['id']]);
                    $activated[] = $record;
                }
            }
            RateLimiter::clear('student', $lrn);
            login_student((int) $student['id']);
            Accounts::remember('learner', $activated);
            Access::logLogin('learner', (int) $student['id'], $lrn, true, 'activated');
            flash('success', 'Your account is ready. Next time, sign in with your LRN and this password.');
            redirect('learner/index.php');
        } else {
            RateLimiter::recordFailure('student', $lrn, $ip);
            $error = 'The LRN and birthdate do not match our records.';
        }
    }
}

$title = 'Activate your account';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-7 col-lg-5">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-person-check"></i></div>
                <h1 class="h4 mb-1">Activate your account</h1>
                <p class="text-secondary small mb-4">Confirm it's you with your birthdate, then choose your own password.</p>
                <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-x-octagon-fill"></i><div><?= e($error) ?></div></div><?php endif; ?>
                <form method="post" action="<?= e(url('learner/activate.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="lrn">LRN</label>
                        <input class="form-control lrn" id="lrn" name="lrn" value="<?= e($lrn) ?>" inputmode="numeric" maxlength="16" required></div>
                    <div class="mb-3"><label class="form-label" for="birthdate">Birthdate</label>
                        <input class="form-control" type="date" id="birthdate" name="birthdate" max="<?= e(date('Y-m-d')) ?>" required></div>
                    <div class="row g-2 mb-4">
                        <div class="col-sm-6"><label class="form-label" for="new">New password</label><input class="form-control" type="password" id="new" name="new" minlength="8" autocomplete="new-password" required></div>
                        <div class="col-sm-6"><label class="form-label" for="confirm">Type it again</label><input class="form-control" type="password" id="confirm" name="confirm" minlength="8" autocomplete="new-password" required></div>
                    </div>
                    <button class="btn btn-primary w-100">Activate and sign in</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
