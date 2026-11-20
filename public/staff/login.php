<?php
// Sign-in for teachers and the registrar/admin.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if ($staff = current_staff()) {
    redirect(home_for($staff));
}

$error = null;
$username = '';

if (is_post()) {
    $username = post('username');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = client_ip();
    $key = mb_strtolower($username);

    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } elseif (RateLimiter::isLocked('staff', $key, $ip)) {
        $error = 'Too many unsuccessful tries. Please wait ' . config('login_window_min') . ' minutes and try again.';
    } else {
        $user = db_one('SELECT * FROM users WHERE username = ?', [$username]);
        $passwordOk = password_verify($password, $user['password_hash'] ?? DUMMY_PASSWORD_HASH);
        if ($user && $passwordOk && $user['is_active']) {
            RateLimiter::clear('staff', $key);
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            login_staff($user);
            redirect($user['must_change_password'] ? 'staff/password.php' : home_for(current_staff()));
        }
        RateLimiter::recordFailure('staff', $key, $ip);
        $error = 'Incorrect username or password.';
    }
}

$title = 'Staff sign-in';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-6 col-lg-4">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1">Staff sign-in</h1>
                <p class="text-secondary small mb-4">For teachers, advisers and the registrar.</p>
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= e(url('staff/login.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" value="<?= e($username) ?>"
                               autocomplete="username" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password"
                               autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-primary w-100">Sign in</button>
                </form>
            </div>
        </div>
        <p class="text-center small text-secondary mt-3">
            Learners: <a href="<?= e(url('index.php')) ?>">check your grades here</a>.
        </p>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
