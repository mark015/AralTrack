<?php
// A parent accepts the school's privacy notice: the first time they use their account (for example when the
// registrar made it), and again when the school changes the notice and asks again. Each acceptance is recorded.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$parent = require_parent();
if (!Privacy::parentMustAccept((int) $parent['id'])) {
    redirect('parent/index.php');
}
$error = null;
if (is_post()) {
    if (post('agree') !== '1') {
        $error = 'Tick the box to agree, or sign out.';
    } else {
        Privacy::record('parent', (int) $parent['id'], ws());
        db_exec('UPDATE parents SET privacy_accepted_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $parent['id']]);
        flash('success', 'Thank you.');
        redirect('parent/index.php');
    }
}
$changed = (int) (db_value("SELECT MAX(version) FROM consents WHERE account_type = 'parent' AND account_id = ?", [$parent['id']]) ?? 0) > 0;

$title = 'Privacy notice';
require APP_ROOT . '/templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card auth-card">
            <div class="card-body">
                <div class="auth-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></div>
                <h1 class="h4 mb-1"><?= $changed ? 'The privacy notice was updated' : 'Before you continue' ?></h1>
                <p class="text-secondary"><?= e(setting('school_name')) ?> keeps your child's records in this portal and follows the Data Privacy Act of 2012.
                    <?= $changed ? 'The school changed its privacy notice. Please read it and agree to continue.' : 'Please read the privacy notice and agree to continue.' ?></p>
                <ul class="small">
                    <li>The school keeps your name, mobile number or email, the children linked to your account, and your messages to teachers.</li>
                    <li>You see only the children linked to your account. Teachers see the learners in their classes.</li>
                    <li>Nothing is sold or shared outside the school, except when DepEd or the law requires it.</li>
                    <li>You can download your data or delete your account at any time under <strong>My data</strong>.</li>
                </ul>
                <p><a href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener">Read the full privacy notice <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a></p>
                <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('parent/consent.php')) ?>">
                    <?= csrf_field() ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="agree" name="agree" value="1" required>
                        <label class="form-check-label" for="agree">I have read the privacy notice and I agree.</label>
                    </div>
                    <button class="btn btn-primary">Continue</button>
                </form>
                <form method="post" action="<?= e(url('parent/logout.php')) ?>" class="mt-3">
                    <?= csrf_field() ?>
                    <button class="btn btn-link btn-sm p-0">I don't agree: sign out</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
