<?php
// Choosing a new password from the link in a "forgot your password" email. The link works once, for an hour.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$token = is_post() ? post('token') : get_str('token');
$account = Signup::resetAccount($token);
$error = null;

if ($account && is_post()) {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    if (mb_strlen($password) < 8) {
        $error = 'Choose a password of at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        Signup::resetPassword($account, $password);
        $user = db_one('SELECT * FROM users WHERE id = ?', [$account['id']]);
        login_staff($user);
        Access::logLogin('staff', (int) $user['id'], (string) $user['email'], true, 'password reset');
        Access::log('password', "{$user['full_name']} chose a new password with an email link", 'staff', (int) $user['id']);
        flash('success', 'Your new password is saved. Other devices signed in to your account were signed out.');
        redirect(home_for(current_staff()));
    }
}

$title = 'Choose a new password';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center auth-wrap">
    <div class="col-md-6 col-lg-4">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></div>
                <?php if (!$account): ?>
                    <h1 class="h4 mb-1">This link doesn't work anymore</h1>
                    <p class="text-secondary mb-3">It was already used, it is more than <?= Signup::RESET_MINUTES ?> minutes old, or a newer link was sent. Ask for a new one.</p>
                    <a class="btn btn-primary" href="<?= e(url('staff/forgot.php')) ?>">Send a new link</a>
                <?php else: ?>
                    <h1 class="h4 mb-1">Choose a new password</h1>
                    <p class="text-secondary small mb-4">For <?= e((string) $account['email']) ?>.</p>
                    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                    <form method="post" action="<?= e(url('staff/reset.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="password">New password</label>
                            <input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" required autofocus>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="confirm">New password again</label>
                            <input class="form-control" type="password" id="confirm" name="confirm" minlength="8" autocomplete="new-password" required>
                        </div>
                        <button class="btn btn-primary w-100">Save my new password</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
