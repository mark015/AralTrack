<?php
// First sign-in when learners use passwords: confirm the account with LRN + birthdate, then choose a password.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (setting('learner_login') !== 'password') {
    redirect('index.php');
}
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
        $student = db_one('SELECT * FROM students WHERE lrn = ?', [$lrn]);
        if ($student && $student['password_hash'] !== null) {
            $error = 'This account is already activated. Sign in with your password, or ask the registrar to reset it.';
        } elseif ($student && hash_equals($student['birthdate'], $birthdate)) {
            db_exec('UPDATE students SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $student['id']]);
            RateLimiter::clear('student', $lrn);
            login_student((int) $student['id']);
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
