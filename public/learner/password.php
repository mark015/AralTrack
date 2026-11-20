<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$student = require_student();
if (setting('learner_login') !== 'password') {
    redirect('learner/index.php');
}
$errors = [];
if (is_post()) {
    $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
    $new = is_string($_POST['new'] ?? null) ? $_POST['new'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    if (!password_verify($current, (string) $student['password_hash'])) {
        $errors[] = 'Your current password is not correct.';
    }
    if (mb_strlen($new) < 8) {
        $errors[] = 'The new password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'The two new passwords do not match.';
    }
    if (!$errors) {
        db_exec('UPDATE students SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $student['id']]);
        session_regenerate_id(true);
        flash('success', 'Your password has been changed.');
        redirect('learner/index.php');
    }
}

$title = 'Change password';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-key"></i></div>
                <h1 class="h4 mb-3">Change password</h1>
                <?php if ($errors): ?><div class="alert alert-danger"><i class="bi bi-x-octagon-fill"></i><div><?= e(implode(' ', $errors)) ?></div></div><?php endif; ?>
                <form method="post" action="<?= e(url('learner/password.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="current">Current password</label><input class="form-control" type="password" id="current" name="current" required></div>
                    <div class="mb-3"><label class="form-label" for="new">New password</label><input class="form-control" type="password" id="new" name="new" minlength="8" required></div>
                    <div class="mb-4"><label class="form-label" for="confirm">Type it again</label><input class="form-control" type="password" id="confirm" name="confirm" minlength="8" required></div>
                    <button class="btn btn-primary">Save new password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
