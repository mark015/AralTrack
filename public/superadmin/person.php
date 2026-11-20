<?php
// Super Admin: one person's access. Sign-in (on/off, sign out, unlock, password) and which parts of the
// portal this teacher, learner or parent can use. A part is available only when it is ON in the control
// panel AND not turned off here.
//   superadmin/person.php?type=staff|parent|learner&id=N
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('superadmin');
$type = in_array(get_str('type'), ['staff', 'parent', 'learner'], true) ? get_str('type') : 'staff';
$id = get_int('id');
$tab = ['staff' => 'staff', 'parent' => 'parents', 'learner' => 'learners'][$type];
$here = "superadmin/person.php?type={$type}&id={$id}";

$person = match ($type) {
    'staff' => db_one('SELECT id, username AS ident, LOWER(username) AS lock_key, full_name AS name, role, is_active, blocked_reason, last_login_at, last_login_device, last_login_ip FROM users WHERE id = ?', [$id]),
    'parent' => db_one(
        "SELECT p.id, p.login AS ident, p.login AS lock_key, p.full_name AS name, NULL AS role, p.is_active, p.blocked_reason, p.last_login_at, p.last_login_device, p.last_login_ip,
                (SELECT GROUP_CONCAT(CONCAT(st.first_name, ' ', st.last_name) ORDER BY st.first_name SEPARATOR ', ') FROM parent_students ps JOIN students st ON st.id = ps.student_id WHERE ps.parent_id = p.id) AS detail
           FROM parents p WHERE p.id = ?",
        [$id]
    ),
    default => db_one(
        "SELECT s.id, s.lrn AS ident, s.lrn AS lock_key, CONCAT(s.first_name, ' ', s.last_name) AS name, s.status AS role, 1 - s.login_blocked AS is_active, s.blocked_reason,
                s.last_login_at, s.last_login_device, s.last_login_ip
           FROM students s WHERE s.id = ?",
        [$id]
    ),
} ?? abort(404, 'That account does not exist.');
$isSuper = $type === 'staff' && $person['role'] === 'superadmin';
$parts = Access::PERSON_PARTS[$type];

if (is_post() && post('action') === 'parts') {
    if ($isSuper) {
        flash('info', 'Super Admins can always use every part.');
        redirect($here);
    }
    $before = array_keys(Access::blocksFor($type, $id));
    $off = array_values(array_filter(array_keys($parts), static fn ($p) => !isset($_POST['part'][$p])));
    $note = mb_substr(trim(post('note')), 0, 255);
    Access::setBlocks($type, $id, $off, $note, $staff['id']);
    $turnedOff = array_diff($off, $before);
    $turnedOn = array_diff($before, $off);
    if ($turnedOff || $turnedOn) {
        $words = [];
        if ($turnedOff) {
            $words[] = 'turned off: ' . implode(', ', array_map(static fn ($p) => $parts[$p], $turnedOff));
        }
        if ($turnedOn) {
            $words[] = 'turned back on: ' . implode(', ', array_map(static fn ($p) => $parts[$p], $turnedOn));
        }
        Access::log('access', "Changed what {$person['name']} can use — " . implode('; ', $words) . ($note && $off ? " (reason: {$note})" : ''), $type, $id, ['off' => [$before, $off]]);
        flash('success', "Saved. {$person['name']}: " . implode('; ', $words) . '. It applies on their next click.');
    } else {
        flash('info', 'Nothing was changed.');
    }
    redirect($here);
}

$blocks = Access::blocksFor($type, $id);
$noteNow = $blocks ? (string) reset($blocks) : '';
$locked = isset(RateLimiter::lockedIdentifiers(['staff' => 'staff', 'parent' => 'parent', 'learner' => 'student'][$type])[$person['lock_key']]);
$self = $type === 'staff' && (int) $person['id'] === $staff['id'];
$subtitle = match ($type) {
    'staff' => STAFF_ROLES[$person['role']] ?? $person['role'],
    'parent' => $person['detail'] ? 'Parent of ' . $person['detail'] : 'No child linked yet',
    default => (current_section_of($id) ? section_label(current_section_of($id)) : 'Not enrolled this school year') . ($person['role'] !== 'active' ? ' · ' . ucfirst(str_replace('_', ' ', $person['role'])) : ''),
};
$editLink = match ($type) {
    'staff' => 'admin/users.php?edit=' . $id,
    'parent' => 'admin/parent-edit.php?id=' . $id,
    default => 'admin/student-edit.php?id=' . $id,
};

$title = $person['name'] . ' · Access';
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('superadmin/accounts.php?tab=' . $tab)) ?>">Accounts &amp; sign-in</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($person['name']) ?></li>
    </ol>
</nav>
<div class="page-head">
    <div class="d-flex align-items-center gap-3">
        <?= avatar($person['name'], 'avatar-lg') ?>
        <div>
            <h1 class="h3"><?= e($person['name']) ?></h1>
            <p class="sub"><span class="<?= $type === 'learner' ? 'lrn' : '' ?>"><?= e($person['ident']) ?></span> · <?= e($subtitle) ?></p>
        </div>
    </div>
    <a class="btn btn-outline-primary" href="<?= e(url($editLink)) ?>"><i class="bi bi-pencil me-1"></i>Open the account</a>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6 mb-3"><i class="bi bi-door-open me-2"></i>Sign-in</h2>
                <p class="mb-2">
                    <?= $person['is_active'] ? '<span class="badge text-bg-success">Can sign in</span>' : '<span class="badge text-bg-danger">Turned off</span>' ?>
                    <?= $locked ? ' <span class="badge text-bg-warning"><i class="bi bi-lock-fill"></i>Locked</span>' : '' ?>
                    <?= !Access::loginOpen($type) ? ' <span class="badge text-bg-secondary">Group sign-in off</span>' : '' ?>
                </p>
                <?php if (!$person['is_active'] && $person['blocked_reason']): ?><p class="small text-secondary">Reason: <?= e($person['blocked_reason']) ?></p><?php endif; ?>
                <p class="small text-secondary">Last sign-in: <?= $person['last_login_at'] ? e(when_label($person['last_login_at'])) . ' · ' . e((string) $person['last_login_device']) . ($person['last_login_ip'] ? ' · ' . e($person['last_login_ip']) : '') : 'never' ?></p>

                <?php if ($self): ?>
                    <p class="small text-secondary mb-0">This is your own account.</p>
                <?php else: ?>
                    <?php if ($person['is_active']): ?>
                        <form method="post" action="<?= e(url('superadmin/accounts.php?tab=' . $tab)) ?>" class="mb-3">
                            <?= csrf_field() ?><input type="hidden" name="action" value="turn_off"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="return" value="person">
                            <label class="form-label small" for="reason">Turn off sign-in — reason (shown to them)</label>
                            <div class="input-group input-group-sm">
                                <input class="form-control" id="reason" name="reason" maxlength="255" placeholder="e.g. Suspended until Dec 5">
                                <button class="btn btn-danger">Turn off</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('superadmin/accounts.php?tab=' . $tab)) ?>" class="mb-3">
                            <?= csrf_field() ?><input type="hidden" name="action" value="turn_on"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="return" value="person">
                            <button class="btn btn-sm btn-success"><i class="bi bi-check-circle me-1"></i>Turn sign-in back on</button>
                        </form>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (array_filter([
                            ['sign_out', 'box-arrow-right', 'Sign out of every device', null],
                            $locked ? ['unlock', 'unlock', 'Unlock', null] : null,
                            ['reset_password', 'key', $type === 'learner' ? 'Clear password' : 'Reset password', $type === 'learner' ? 'Clear this learner\'s password?' : 'Make a new temporary password?'],
                        ]) as [$act, $icon, $label, $confirm]): ?>
                            <form method="post" action="<?= e(url('superadmin/accounts.php?tab=' . $tab)) ?>" class="m-0">
                                <?= csrf_field() ?><input type="hidden" name="action" value="<?= e($act) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="return" value="person">
                                <button class="btn btn-sm btn-outline-primary"<?= $confirm ? ' onclick="return confirm(' . e(json_encode($confirm)) . ')"' : '' ?>><i class="bi bi-<?= e($icon) ?> me-1"></i><?= e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <form method="post" action="<?= e(url($here)) ?>" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="parts">
            <div class="card-body">
                <h2 class="h6 mb-1"><i class="bi bi-ui-checks-grid me-2"></i>What <?= e(explode(' ', $person['name'])[0]) ?> can use</h2>
                <p class="small text-secondary">Only for this person. Turn a part off and it disappears for them on their next click; nothing is deleted. Parts that are off in the <a href="<?= e(url('superadmin/index.php')) ?>">control panel</a> are off for everyone.</p>
                <?php if ($isSuper): ?>
                    <p class="mb-0">Super Admins can always use every part.</p>
                <?php else: ?>
                    <div class="switch-list">
                        <?php foreach ($parts as $part => $label):
                            $global = Access::globalOn($part, $type);
                            $checked = !isset($blocks[$part]); ?>
                            <div class="switch-row">
                                <div class="flex-grow-1">
                                    <label class="fw-semibold" for="part-<?= e($part) ?>"><?= e($label) ?></label>
                                    <div class="small text-secondary">
                                        <?php if (!$global): ?><i class="bi bi-slash-circle me-1"></i>Off for everyone in the control panel<?= $checked ? '' : ' (and off for this person)' ?>
                                        <?php elseif (!$checked): ?><span class="text-danger"><i class="bi bi-person-x me-1"></i>Turned off for this person</span>
                                        <?php else: ?>On<?php endif; ?>
                                    </div>
                                </div>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="part-<?= e($part) ?>" name="part[<?= e($part) ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-label small mt-3" for="note">Reason they see when something is off <span class="text-secondary">(optional)</span></label>
                    <input class="form-control form-control-sm" id="note" name="note" maxlength="255" value="<?= e($noteNow) ?>" placeholder="<?= $type === 'learner' ? 'e.g. Please settle your requirements at the registrar' : 'e.g. Ask the principal' ?>">
                <?php endif; ?>
            </div>
            <?php if (!$isSuper): ?>
                <div class="card-footer bg-transparent d-flex justify-content-end">
                    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save</button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
