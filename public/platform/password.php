<?php
// The platform owner changes their password. Other signed-in owner sessions end.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$session = require_owner();
$error = null;

if (is_post()) {
    $owner = db_one('SELECT * FROM platform_owners WHERE id = ?', [$session['id']]);
    $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
    $new = is_string($_POST['new'] ?? null) ? $_POST['new'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    if (!password_verify($current, $owner['password_hash'])) {
        $error = 'Your current password is not correct.';
    } elseif (mb_strlen($new) < 10) {
        $error = 'Choose a new password of at least 10 characters.';
    } elseif ($new !== $confirm) {
        $error = 'The two new passwords do not match.';
    } else {
        db_exec('UPDATE platform_owners SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $owner['id']]);
        login_owner(db_one('SELECT * FROM platform_owners WHERE id = ?', [$owner['id']]));
        platform_log('owner', 'Changed the owner password');
        flash('success', 'Password changed. Other devices signed in to the console were signed out.');
        redirect('platform/index.php');
    }
}

$title = 'Change password';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <h1 class="h3">Change password</h1>
        <div class="card shadow-sm">
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('platform/password.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="current">Current password</label>
                        <input class="form-control" type="password" id="current" name="current" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new">New password <span class="text-secondary small">(at least 10 characters)</span></label>
                        <input class="form-control" type="password" id="new" name="new" autocomplete="new-password" minlength="10" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="confirm">New password again</label>
                        <input class="form-control" type="password" id="confirm" name="confirm" autocomplete="new-password" minlength="10" required>
                    </div>
                    <button class="btn btn-primary">Change password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
