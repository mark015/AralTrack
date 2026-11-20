<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (current_parent()) {
    redirect('parent/index.php');
}

$error = null;
$typed = '';

if (is_post()) {
    $typed = post('login');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $login = normalize_parent_login($typed);
    $ip = client_ip();

    if ($login === null || $password === '') {
        $error = 'Enter your mobile number or email, and your password.';
    } elseif (RateLimiter::isLocked('parent', $login, $ip)) {
        $error = 'Too many unsuccessful tries. Please wait ' . config('login_window_min') . ' minutes and try again.';
    } else {
        $parent = db_one('SELECT * FROM parents WHERE login = ?', [$login]);
        $ok = password_verify($password, $parent['password_hash'] ?? DUMMY_PASSWORD_HASH);
        if ($parent && $ok && $parent['is_active']) {
            RateLimiter::clear('parent', $login);
            if (password_needs_rehash($parent['password_hash'], PASSWORD_DEFAULT)) {
                db_exec('UPDATE parents SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $parent['id']]);
            }
            login_parent($parent);
            redirect($parent['must_change_password'] ? 'parent/password.php' : 'parent/index.php');
        }
        RateLimiter::recordFailure('parent', $login, $ip);
        $error = 'Incorrect mobile number/email or password.';
    }
}

$title = 'Parent sign-in';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-6 col-lg-4">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon gold"><i class="bi bi-people-fill" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1">Parent sign-in</h1>
                <p class="text-secondary small mb-4">See your child's class attendance and grades.</p>
                <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('parent/login.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="login" class="form-label">Mobile number or email</label>
                        <input class="form-control" id="login" name="login" value="<?= e($typed) ?>" autocomplete="username" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-primary w-100">Sign in</button>
                </form>
                <p class="small text-secondary mt-3 mb-0">Forgot your password? Ask the school's registrar to reset it.</p>
            </div>
        </div>
        <p class="text-center small text-secondary mt-3">
            New here? <a href="<?= e(url('parent/register.php')) ?>">Create a parent account</a> with your child's LRN and link code.
        </p>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
