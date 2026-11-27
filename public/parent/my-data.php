<?php
// A parent's rights under the Data Privacy Act: download their own data, or delete their account at this school.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$session = require_parent();
$parent = db_one('SELECT * FROM parents WHERE id = ?', [$session['id']]);
$error = null;

if (is_post() && post('action') === 'download') {
    $data = Privacy::parentData((int) $parent['id']);
    Access::logLogin('parent', (int) $parent['id'], (string) $parent['login'], true, 'downloaded own data');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-data-' . date('Ymd') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (is_post() && post('action') === 'delete') {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (!password_verify($password, (string) $parent['password_hash'])) {
        $error = 'Your password is not correct.';
    } elseif (post('confirm') !== 'DELETE') {
        $error = 'Type DELETE in capital letters to confirm.';
    } else {
        $school = setting('school_name');
        Privacy::deleteParent((int) $parent['id']);
        logout_parent();
        flash('success', "Your parent account at {$school} was deleted. Accounts you have at other schools are not affected.");
        redirect('parent/login.php');
    }
}

$children = db_all('SELECT st.first_name, st.last_name, st.middle_name FROM parent_students ps JOIN students st ON st.id = ps.student_id WHERE ps.parent_id = ?', [$parent['id']]);
$consent = db_one("SELECT version, accepted_at FROM consents WHERE account_type = 'parent' AND account_id = ? ORDER BY id DESC LIMIT 1", [$parent['id']]);

$title = 'My data';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">My data</h1>
        <p class="sub"><?= e(setting('school_name')) ?> · Your rights under the Data Privacy Act of 2012</p>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">What the school keeps about you</h2>
                <ul class="small">
                    <li>Your name and sign-in: <?= e($parent['full_name']) ?>, <?= e($parent['login']) ?></li>
                    <li>Children linked to your account: <?= $children ? e(implode(', ', array_map('student_name', $children))) : 'none' ?></li>
                    <li>Your notifications, messages to teachers, excuse letters and document requests</li>
                    <li>When you signed in, from which device</li>
                </ul>
                <p class="small text-secondary">
                    <?= $consent ? 'You agreed to the privacy notice on ' . e(date('F j, Y', strtotime($consent['accepted_at']))) . '.' : '' ?>
                    <a href="<?= e(url('privacy.php')) ?>">Read the privacy notice</a>.
                </p>
                <form method="post" action="<?= e(url('parent/my-data.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="download">
                    <button class="btn btn-outline-primary"><i class="bi bi-download me-1" aria-hidden="true"></i>Download my data</button>
                </form>
                <p class="small text-secondary mt-2 mb-0">Your children's grades and attendance are school records: see them in the portal, or ask the school for a copy.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100 border-danger-subtle">
            <div class="card-body">
                <h2 class="h5">Delete my account</h2>
                <p class="small text-secondary">
                    Your name, sign-in and the links to your children are removed, and you are signed out. Messages and letters
                    you sent stay with the school as part of its records, under "Deleted parent". Your children's records stay
                    with the school. To use the portal again later, register again with the slip from the school.
                </p>
                <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('parent/my-data.php')) ?>" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <div class="col-sm-6"><label class="form-label small" for="password">Your password</label><input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required></div>
                    <div class="col-sm-6"><label class="form-label small" for="confirm">Type DELETE</label><input class="form-control" id="confirm" name="confirm" autocomplete="off" required pattern="DELETE"></div>
                    <div class="col-12"><button class="btn btn-outline-danger">Delete my account</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
