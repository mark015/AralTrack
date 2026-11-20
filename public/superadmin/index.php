<?php
// Super Admin control panel: turn parts of the portal and sign-in on or off, maintenance mode,
// and signing everyone out. Every change takes effect at once and is written to the activity log.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('superadmin');

$moduleKeys = array_map(static fn ($m) => 'module_' . $m, array_keys(Access::MODULES));
$gradingKeys = array_keys(Access::GRADING);
$loginKeys = ['login_staff', 'login_learner', 'login_parent', 'parent_registration'];
$current = static function (array $keys): array {
    $out = [];
    foreach ($keys as $key) {
        $out[$key] = $key === 'maintenance_mode'
            ? (Access::maintenance() ? '1' : '0')              // off unless turned on
            : ((string) setting($key) === '0' ? '0' : '1');    // every other switch is on unless turned off
    }
    return $out;
};
$labels = [];
foreach (Access::MODULES as $key => [$label]) {
    $labels['module_' . $key] = $label;
}
foreach (Access::GRADING as $key => [$label]) {
    $labels[$key] = $label;
}
$labels += ['login_staff' => 'Teachers & staff sign-in', 'login_learner' => 'Learner sign-in', 'login_parent' => 'Parent sign-in',
    'parent_registration' => 'New parent accounts', 'learner_login' => 'Learner sign-in method', 'maintenance_mode' => 'Maintenance mode',
    'maintenance_message' => 'Maintenance message'];

/** Saves switches from checkboxes, logs what changed, and says so. */
$saveSwitches = static function (array $keys, string $what, array $extra = []) use ($current, $labels): void {
    $before = $current($keys) + array_map(static fn ($k) => (string) setting($k), array_combine(array_keys($extra), array_keys($extra)) ?: []);
    $after = [];
    foreach ($keys as $key) {
        $after[$key] = isset($_POST[$key]) ? '1' : '0';
    }
    $after += $extra;
    $changes = Access::diff($before, $after);
    if (!$changes) {
        flash('info', 'Nothing was changed.');
        return;
    }
    save_settings($after);
    settings_cache(null);
    $words = [];
    foreach ($changes as $key => [$old, $new]) {
        $words[] = ($labels[$key] ?? $key) . ': ' . match (true) {
            $new === '1' => 'ON',
            $new === '0' => 'OFF',
            default => '"' . mb_substr($new, 0, 60) . '"',
        };
    }
    Access::log('switches', $what . ' changed — ' . implode('; ', $words), 'settings', null, $changes);
    flash('success', $what . ' saved: ' . implode('; ', $words) . '.');
};

if (is_post()) {
    $action = post('action');
    if ($action === 'modules') {
        $saveSwitches([...$moduleKeys, ...$gradingKeys], 'Parts of the system');
    } elseif ($action === 'signin') {
        $mode = post('learner_login');
        $saveSwitches($loginKeys, 'Sign-in', ['learner_login' => isset(Access::LEARNER_LOGIN_MODES[$mode]) ? $mode : 'lrn']);
    } elseif ($action === 'maintenance') {
        $saveSwitches(['maintenance_mode'], 'Maintenance mode', ['maintenance_message' => mb_substr(trim(post('maintenance_message')), 0, 300)]);
    } elseif ($action === 'signout_all') {
        if (post('confirm') !== 'yes') {
            flash('danger', 'Tick the box to confirm.');
        } else {
            Access::signOutEveryone($staff['id']);
            Access::log('sign_out_all', 'Signed out everyone (all staff, parents and learners) except ' . $staff['full_name'], null, null);
            flash('success', 'Everyone else has been signed out. They can sign in again (if their sign-in is on).');
        }
    }
    redirect('superadmin/index.php');
}

$modules = $current($moduleKeys);
$grading = $current($gradingKeys);
$login = $current($loginKeys);
$maintenance = Access::maintenance();
$today = date('Y-m-d 00:00:00');
$stats = db_one(
    'SELECT COUNT(DISTINCT CASE WHEN success = 1 THEN CONCAT(account_type, account_id) END) AS people,
            SUM(success = 0) AS failed
       FROM login_events WHERE created_at >= ?',
    [$today]
);
$locked = count(RateLimiter::lockedIdentifiers('staff')) + count(RateLimiter::lockedIdentifiers('parent')) + count(RateLimiter::lockedIdentifiers('student'));
$partsOn = count(array_filter($modules, static fn ($v) => $v === '1'));
$groupsOpen = $maintenance ? 0 : count(array_filter([$login['login_staff'], $login['login_learner'], $login['login_parent']], static fn ($v) => $v === '1'));
$recent = db_all('SELECT * FROM activity_log ORDER BY id DESC LIMIT 6');

/** One on/off switch row. */
$switch = static function (string $name, bool $checked, string $label, string $help, string $icon = '', bool $sub = false): string {
    $id = 'sw-' . $name;
    return '<div class="switch-row' . ($sub ? ' sub' : '') . '">'
        . ($icon ? '<div class="icon-bubble sm"><i class="bi bi-' . e($icon) . '" aria-hidden="true"></i></div>' : '')
        . '<div class="flex-grow-1"><label class="fw-semibold" for="' . e($id) . '">' . e($label) . '</label>'
        . '<div class="small text-secondary">' . e($help) . '</div></div>'
        . '<div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '></div>'
        . '</div>';
};

$title = 'Control panel';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Control panel</h1>
        <p class="sub">Turn parts of the portal and sign-in on or off. Changes take effect at once; turning something off never deletes data.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('superadmin/accounts.php')) ?>"><i class="bi bi-person-lock me-1"></i>Accounts &amp; sign-in</a>
        <a class="btn btn-outline-primary" href="<?= e(url('superadmin/activity.php')) ?>"><i class="bi bi-journal-text me-1"></i>Activity log</a>
    </div>
</div>

<?php if ($maintenance): ?>
    <div class="alert alert-warning"><i class="bi bi-cone-striped" aria-hidden="true"></i><div><strong>Maintenance mode is ON.</strong> Only Super Admins can sign in. Everyone else sees: "<?= e(Access::maintenanceMessage()) ?>"</div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Parts on', $partsOn . ' / ' . count(Access::MODULES), 'toggles', ''],
        ['Sign-in open', $groupsOpen . ' / 3 groups', 'door-open', $groupsOpen === 3 ? 'green' : 'gold'],
        ['Signed in today', (int) $stats['people'] . ' people', 'person-check', 'teal'],
        ['Failed sign-ins today', (int) $stats['failed'], 'shield-exclamation', (int) $stats['failed'] ? 'rose' : 'green'],
        ['Locked accounts', $locked, 'lock', $locked ? 'rose' : 'green'],
    ] as [$label, $value, $icon, $tone]): ?>
        <div class="col-6 col-lg">
            <div class="summary-tile">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="label"><?= e($label) ?></div>
                    <div class="icon-bubble sm <?= $tone ?>"><i class="bi bi-<?= $icon ?>"></i></div>
                </div>
                <div class="value"><?= e((string) $value) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <form method="post" action="<?= e(url('superadmin/index.php')) ?>" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="modules">
            <div class="card-body">
                <h2 class="h6 mb-1"><i class="bi bi-grid me-2"></i>Parts of the system</h2>
                <p class="small text-secondary">Off hides that part from teachers, learners and parents. The registrar can still see earlier records.</p>
                <div class="switch-list">
                    <?php foreach (Access::MODULES as $key => [$label, $icon, $off]): ?>
                        <?= $switch('module_' . $key, $modules['module_' . $key] === '1', $label, 'When off: ' . $off, $icon) ?>
                        <?php if ($key === 'grading'): ?>
                            <?php foreach (Access::GRADING as $gKey => [$gLabel, $gHelp]): ?>
                                <?= $switch($gKey, $grading[$gKey] === '1', $gLabel, $gHelp, '', true) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer bg-transparent d-flex justify-content-end">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save parts</button>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <form method="post" action="<?= e(url('superadmin/index.php')) ?>" class="card mb-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="signin">
            <div class="card-body">
                <h2 class="h6 mb-1"><i class="bi bi-door-open me-2"></i>Sign-in</h2>
                <p class="small text-secondary">Turning a group off also signs its people out on their next click. Super Admins can always sign in.</p>
                <div class="switch-list">
                    <?= $switch('login_staff', $login['login_staff'] === '1', 'Teachers & staff', 'Teachers, advisers, the registrar, principal and guidance.', 'person-badge') ?>
                    <?= $switch('login_learner', $login['login_learner'] === '1', 'Learners', 'Learner portal (grades, schedule, subjects).', 'mortarboard') ?>
                    <?= $switch('login_parent', $login['login_parent'] === '1', 'Parents', 'Parent portal (attendance, grades, messages).', 'people') ?>
                    <?= $switch('parent_registration', $login['parent_registration'] === '1', 'New parent accounts', 'Parents can create their own account with the link code.', '', true) ?>
                </div>
                <h3 class="small fw-bold text-secondary text-uppercase mt-3 mb-2">How learners sign in</h3>
                <?php foreach (Access::LEARNER_LOGIN_MODES as $value => $label): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="radio" name="learner_login" id="mode-<?= e($value) ?>" value="<?= e($value) ?>" <?= setting('learner_login') === $value ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="mode-<?= e($value) ?>"><?= e($label) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer bg-transparent d-flex justify-content-end">
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save sign-in</button>
            </div>
        </form>

        <form method="post" action="<?= e(url('superadmin/index.php')) ?>" class="card mb-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="maintenance">
            <div class="card-body">
                <div class="switch-list"><?= $switch('maintenance_mode', $maintenance, 'Maintenance mode', 'Only Super Admins can sign in; everyone else is signed out and sees the message below.', 'cone-striped') ?></div>
                <label class="form-label small mt-2" for="m-msg">Message</label>
                <textarea class="form-control form-control-sm" id="m-msg" name="maintenance_message" rows="2" maxlength="300" placeholder="The portal is closed for maintenance. Please try again later."><?= e((string) setting('maintenance_message')) ?></textarea>
            </div>
            <div class="card-footer bg-transparent d-flex justify-content-end">
                <button class="btn btn-primary" onclick="return !document.getElementById('sw-maintenance_mode').checked || <?= $maintenance ? 'true' : "confirm('Turn on maintenance mode? Everyone except Super Admins will be signed out.')" ?>"><i class="bi bi-check2 me-1"></i>Save</button>
            </div>
        </form>

        <form method="post" action="<?= e(url('superadmin/index.php')) ?>" class="card border-danger-subtle">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="signout_all">
            <div class="card-body">
                <h2 class="h6 text-danger mb-1"><i class="bi bi-box-arrow-right me-2"></i>Sign everyone out</h2>
                <p class="small text-secondary mb-2">Ends every session on every phone and computer, except yours. Use it after a password leak, or before a year-end rollover.</p>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="so-confirm" name="confirm" value="yes" required>
                    <label class="form-check-label small" for="so-confirm">Yes, sign out all staff, parents and learners</label>
                </div>
                <button class="btn btn-outline-danger btn-sm">Sign everyone out</button>
            </div>
        </form>
    </div>
</div>

<h2 class="h5 mt-5">Recent changes</h2>
<?php if (!$recent): ?>
    <p class="text-secondary">No changes yet.</p>
<?php else: ?>
    <div class="list-group shadow-sm mb-2">
        <?php foreach ($recent as $r): ?>
            <div class="list-group-item small"><span class="text-secondary"><?= e(when_label($r['created_at'])) ?> · <?= e($r['actor_name']) ?></span><br><?= e($r['summary']) ?></div>
        <?php endforeach; ?>
    </div>
    <a class="small" href="<?= e(url('superadmin/activity.php?tab=changes')) ?>">See the full activity log →</a>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
