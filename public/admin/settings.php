<?php
// School details and portal options (stored in the database; config/config.php gives the defaults).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$fields = [
    'school_name'        => ['School name', 'text', 'Printed on report cards, ID cards and DepEd forms'],
    'school_id'          => ['DepEd School ID', 'text', 'Six digits, e.g. 342001'],
    'school_address'     => ['School address', 'text', ''],
    'school_division'    => ['Division', 'text', 'e.g. Division of Quezon City'],
    'school_region'      => ['Region', 'text', 'e.g. NCR'],
    'principal_name'     => ['School head / principal', 'text', 'Printed on report cards and certificates'],
    'dpo_contact'        => ['Data privacy contact', 'text', 'Who parents contact about their data (shown on the privacy notice)'],
    'public_url'         => ['Portal address for parents', 'url', 'Printed on parent link slips, e.g. https://portal.yourschool.edu.ph/student-portal'],
    'late_after_minutes' => ['Late after (minutes)', 'number', 'A scan this many minutes after class starts is Late'],
    'at_risk_absences'   => ['At-risk absences', 'number', 'Absences in one subject before a learner is listed as at risk'],
];

if (is_post()) {
    $values = [];
    foreach ($fields as $key => [$label, $type]) {
        $value = mb_substr(post($key), 0, 300);
        if ($type === 'number' && $value !== '' && (!ctype_digit($value) || (int) $value < 1 || (int) $value > 240)) {
            flash('danger', "{$label} must be a whole number from 1 to 240.");
            redirect('admin/settings.php');
        }
        $values[$key] = $value;
    }
    $values['current_semester'] = post_int('current_semester') === 2 ? '2' : '1';
    $before = [];
    foreach (array_keys($values) as $key) {
        $before[$key] = settings_cache()[$key] ?? '';
    }
    save_settings($values);
    if ($changes = Access::diff($before, $values)) {
        Access::log('settings', 'Changed settings: ' . implode(', ', array_map(static fn ($k) => $fields[$k][0] ?? ($k === 'current_semester' ? 'Current semester' : $k), array_keys($changes))), 'settings', null, $changes);
    }
    flash('success', 'Settings saved.');
    redirect('admin/settings.php');
}

$title = 'Settings';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Settings</h1>
        <p class="sub">School details and portal options. Empty fields use the defaults from <code>config/config.php</code>.</p>
    </div>
</div>
<form method="post" action="<?= e(url('admin/settings.php')) ?>">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 mb-3"><i class="bi bi-building me-2"></i>School</h2>
                    <?php foreach (array_slice($fields, 0, 8, true) as $key => [$label, $type, $help]): ?>
                        <div class="mb-3">
                            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                            <input class="form-control" type="<?= e($type) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e(settings_cache()[$key] ?? '') ?>" placeholder="<?= e((string) setting($key)) ?>">
                            <?php if ($help): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-2"><i class="bi bi-person-lock me-2"></i>Sign-in and parts of the portal</h2>
                    <p class="small mb-1">Learners sign in with: <strong><?= e(Access::LEARNER_LOGIN_MODES[setting('learner_login')] ?? setting('learner_login')) ?></strong></p>
                    <p class="small text-secondary mb-0">
                        <?php if ($staff['role'] === 'superadmin'): ?>
                            Change it, and turn sign-in or parts of the portal on or off, in the <a href="<?= e(url('superadmin/index.php')) ?>">control panel</a>.
                        <?php else: ?>
                            Sign-in and the parts of the portal (attendance, grading, messages, …) are controlled by the Super Admin.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 mb-3"><i class="bi bi-sliders me-2"></i>Classes and attendance</h2>
                    <div class="mb-3">
                        <label class="form-label" for="current_semester">Current semester</label>
                        <select class="form-select" id="current_semester" name="current_semester">
                            <?php foreach (SEMESTERS as $sem): ?><option value="<?= $sem ?>" <?= (int) setting('current_semester') === $sem ? 'selected' : '' ?>><?= e(semester_label($sem)) ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">Decides which classes show in everyone's "today" list.</div>
                    </div>
                    <?php foreach (array_slice($fields, 8, null, true) as $key => [$label, $type, $help]): ?>
                        <div class="mb-3">
                            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                            <input class="form-control" type="number" min="1" max="240" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e(settings_cache()[$key] ?? '') ?>" placeholder="<?= e((string) setting($key)) ?>">
                            <div class="form-text"><?= e($help) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="sticky-actions mt-3"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save settings</button></div>
</form>
<?php require APP_ROOT . '/templates/footer.php'; ?>
