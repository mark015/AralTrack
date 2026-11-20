<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$session = require_parent();
$parent = db_one('SELECT * FROM parents WHERE id = ?', [$session['id']]);
$errors = [];

if (is_post()) {
    $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
    $new = is_string($_POST['new'] ?? null) ? $_POST['new'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    if (!password_verify($current, $parent['password_hash'])) {
        $errors[] = 'Your current password is not correct.';
    }
    if (mb_strlen($new) < 8) {
        $errors[] = 'The new password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'The two new passwords do not match.';
    } elseif ($new === $current) {
        $errors[] = 'The new password must be different from the current one.';
    }
    if (!$errors) {
        db_exec('UPDATE parents SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $parent['id']]);
        session_regenerate_id(true);
        flash('success', 'Your password has been changed.');
        redirect('parent/index.php');
    }
}

$title = 'Change password';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon gold"><i class="bi bi-key" aria-hidden="true"></i></div>
                <h1 class="h4 mb-3">Change password</h1>
                <?php if ($parent['must_change_password']): ?>
                    <div class="alert alert-warning">The school gave you a temporary password. Choose your own before continuing.</div>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="post" action="<?= e(url('parent/password.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="current" class="form-label">Current password</label>
                        <input type="password" class="form-control" id="current" name="current" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label for="new" class="form-label">New password</label>
                        <input type="password" class="form-control" id="new" name="new" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="mb-4">
                        <label for="confirm" class="form-label">Type the new password again</label>
                        <input type="password" class="form-control" id="confirm" name="confirm" minlength="8" autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-primary">Save new password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
