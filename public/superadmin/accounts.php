<?php
// Super Admin: every account (teachers & staff, parents, learners) with its last sign-in, and per person:
// turn sign-in off or on (with a reason), sign out of every device, unlock after wrong tries, reset password.
//   superadmin/accounts.php?tab=staff|parents|learners&q=…&status=all|on|off|locked|never&page=N
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('superadmin');
const ACCOUNT_TABS = ['staff' => 'Teachers & staff', 'parents' => 'Parents', 'learners' => 'Learners'];
const ACCOUNT_FILTERS = ['all' => 'All', 'on' => 'Can sign in', 'off' => 'Turned off', 'locked' => 'Locked', 'never' => 'Never signed in'];
const PER_PAGE = 50;

$tab = isset(ACCOUNT_TABS[get_str('tab')]) ? get_str('tab') : 'staff';
$status = isset(ACCOUNT_FILTERS[get_str('status')]) ? get_str('status') : 'all';
$q = trim(get_str('q'));
$page = max(1, get_int('page'));
$here = 'superadmin/accounts.php?' . http_build_query(array_filter(['tab' => $tab, 'q' => $q, 'status' => $status === 'all' ? null : $status, 'page' => $page > 1 ? $page : null]));
$type = ['staff' => 'staff', 'parents' => 'parent', 'learners' => 'learner'][$tab];

/** A temporary password that is easy to read out (no 0/O, 1/l). */
$tempPassword = static function (): string {
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
};

if (is_post()) {
    $action = post('action');
    $id = post_int('id');
    $reason = mb_substr(trim(post('reason')), 0, 255);

    if ($action === 'turn_off_inactive_learners') {
        $n = db_exec("UPDATE students SET login_blocked = 1, blocked_reason = CONCAT('Learner status: ', REPLACE(status, '_', ' ')), session_version = session_version + 1
                        WHERE status <> 'active' AND login_blocked = 0");
        Access::log('account', "Turned off sign-in for {$n} graduated, transferred or dropped learner(s)", 'learner', null);
        flash('success', $n ? "Sign-in turned off for {$n} learner(s) who graduated, transferred out or dropped." : 'All of them were already turned off.');
        redirect($here);
    }

    $account = match ($type) {
        'staff' => db_one('SELECT id, username AS ident, full_name AS name, role, is_active FROM users WHERE id = ?', [$id]),
        'parent' => db_one('SELECT id, login AS ident, full_name AS name, is_active FROM parents WHERE id = ?', [$id]),
        default => db_one("SELECT id, lrn AS ident, CONCAT(first_name, ' ', last_name) AS name, 1 - login_blocked AS is_active FROM students WHERE id = ?", [$id]),
    };
    if (!$account) {
        flash('danger', 'That account does not exist.');
        redirect($here);
    }
    $name = $account['name'];
    $back = post('return') === 'person' ? "superadmin/person.php?type={$type}&id={$id}" : $here;
    $isSelf = $type === 'staff' && (int) $account['id'] === $staff['id'];
    $lastSuper = $type === 'staff' && $account['role'] === 'superadmin' && (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND is_active = 1") <= 1;
    $scope = ['staff' => 'staff', 'parent' => 'parent', 'learner' => 'student'][$type];
    $identifier = $type === 'staff' ? mb_strtolower($account['ident']) : $account['ident'];

    switch ($action) {
        case 'turn_off':
            if ($isSelf || $lastSuper) {
                flash('danger', $isSelf ? 'You cannot turn off your own account.' : 'This is the last Super Admin account, so it cannot be turned off.');
                break;
            }
            match ($type) {
                'staff' => db_exec('UPDATE users SET is_active = 0, blocked_reason = ? WHERE id = ?', [$reason ?: null, $id]),
                'parent' => db_exec('UPDATE parents SET is_active = 0, blocked_reason = ? WHERE id = ?', [$reason ?: null, $id]),
                default => db_exec('UPDATE students SET login_blocked = 1, blocked_reason = ? WHERE id = ?', [$reason ?: null, $id]),
            };
            Access::signOut($type, $id);
            Access::log('account', "Turned off sign-in for {$name}" . ($reason ? " (reason: {$reason})" : ''), $type, $id);
            flash('success', "{$name} can no longer sign in, and was signed out.");
            break;

        case 'turn_on':
            match ($type) {
                'staff' => db_exec('UPDATE users SET is_active = 1, blocked_reason = NULL WHERE id = ?', [$id]),
                'parent' => db_exec('UPDATE parents SET is_active = 1, blocked_reason = NULL WHERE id = ?', [$id]),
                default => db_exec('UPDATE students SET login_blocked = 0, blocked_reason = NULL WHERE id = ?', [$id]),
            };
            Access::log('account', "Turned on sign-in for {$name}", $type, $id);
            flash('success', "{$name} can sign in again.");
            break;

        case 'sign_out':
            if ($isSelf) {
                flash('info', 'To sign yourself out, use Sign out in the menu at your name.');
                break;
            }
            Access::signOut($type, $id);
            Access::log('account', "Signed {$name} out of every device", $type, $id);
            flash('success', "{$name} was signed out of every device.");
            break;

        case 'unlock':
            RateLimiter::clear($scope, $identifier);
            Access::log('account', "Unlocked sign-in for {$name} after failed tries", $type, $id);
            flash('success', "{$name} can try to sign in again now.");
            break;

        case 'reset_password':
            if ($isSelf) {
                flash('info', 'To change your own password, use Change password in the menu at your name.');
                break;
            }
            if ($type === 'learner') {
                db_exec('UPDATE students SET password_hash = NULL WHERE id = ?', [$id]);
                RateLimiter::clear($scope, $identifier);
                Access::signOut($type, $id);
                Access::log('account', "Cleared the password of {$name} (the learner activates the account again)", $type, $id);
                flash('success', "{$name}'s password was cleared. When learners sign in with passwords, they activate the account again with their LRN and birthdate.");
                break;
            }
            $temp = $tempPassword();
            db_exec(
                'UPDATE ' . ($type === 'staff' ? 'users' : 'parents') . ' SET password_hash = ?, must_change_password = 1 WHERE id = ?',
                [password_hash($temp, PASSWORD_DEFAULT), $id]
            );
            RateLimiter::clear($scope, $identifier);
            Access::signOut($type, $id);
            Access::log('account', "Reset the password of {$name}", $type, $id);
            flash('success', "New temporary password for {$name}: {$temp} — give it to them privately. They must choose a new one when they sign in. It is not shown again.");
            break;
    }
    redirect($back);
}

// ---- list
$locked = RateLimiter::lockedIdentifiers(['staff' => 'staff', 'parent' => 'parent', 'learner' => 'student'][$type]);
$where = ['1 = 1'];
$params = [];
switch ($type) {
    case 'staff':
        $base = 'FROM users u';
        $select = "SELECT u.id, u.username AS ident, LOWER(u.username) AS lock_key, u.full_name AS name, u.role, u.is_active, u.blocked_reason,
                          u.last_login_at, u.last_login_device, u.last_login_ip, u.must_change_password, NULL AS detail";
        if ($q !== '') {
            $where[] = '(u.username LIKE ? OR u.full_name LIKE ?)';
            array_push($params, "%{$q}%", "%{$q}%");
        }
        $onCol = 'u.is_active = 1';
        $lastCol = 'u.last_login_at';
        $keyCol = 'LOWER(u.username)';
        $order = "FIELD(u.role, 'superadmin', 'admin', 'principal', 'guidance', 'teacher'), u.full_name";
        break;
    case 'parent':
        $base = 'FROM parents p';
        $select = "SELECT p.id, p.login AS ident, p.login AS lock_key, p.full_name AS name, NULL AS role, p.is_active, p.blocked_reason,
                          p.last_login_at, p.last_login_device, p.last_login_ip, p.must_change_password,
                          (SELECT GROUP_CONCAT(CONCAT(st.first_name, ' ', st.last_name) ORDER BY st.first_name SEPARATOR ', ')
                             FROM parent_students ps JOIN students st ON st.id = ps.student_id WHERE ps.parent_id = p.id) AS detail";
        if ($q !== '') {
            $where[] = '(p.login LIKE ? OR p.full_name LIKE ?)';
            array_push($params, "%{$q}%", "%{$q}%");
        }
        $onCol = 'p.is_active = 1';
        $lastCol = 'p.last_login_at';
        $keyCol = 'p.login';
        $order = 'p.full_name';
        break;
    default:
        $base = 'FROM students s
                 LEFT JOIN enrollments e ON e.student_id = s.id AND e.school_year_id = (SELECT id FROM school_years WHERE is_active = 1 LIMIT 1)
                 LEFT JOIN sections sec ON sec.id = e.section_id';
        $select = "SELECT s.id, s.lrn AS ident, s.lrn AS lock_key, CONCAT(s.last_name, ', ', s.first_name) AS name, s.status AS role, 1 - s.login_blocked AS is_active,
                          s.blocked_reason, s.last_login_at, s.last_login_device, s.last_login_ip, 0 AS must_change_password,
                          CASE WHEN sec.id IS NULL THEN 'Not enrolled this year' ELSE CONCAT('Grade ', sec.grade_level, IF(sec.strand IS NULL OR sec.strand = '', '', CONCAT(' ', sec.strand)), ' – ', sec.name) END AS detail,
                          s.password_hash IS NOT NULL AS has_password";
        if ($q !== '') {
            $where[] = '(s.lrn = ? OR CONCAT(s.first_name, \' \', s.last_name) LIKE ? OR s.last_name LIKE ?)';
            array_push($params, $q, "%{$q}%", "{$q}%");
        }
        $onCol = 's.login_blocked = 0';
        $lastCol = 's.last_login_at';
        $keyCol = 's.lrn';
        $order = 's.last_name, s.first_name';
}
$lockedList = $locked ? implode(',', array_map(static fn ($k) => db()->quote((string) $k), array_keys($locked))) : "''";
$where[] = match ($status) {
    'on' => $onCol,
    'off' => "NOT ({$onCol})",
    'locked' => "{$keyCol} IN ({$lockedList})",
    'never' => "{$lastCol} IS NULL",
    default => '1 = 1',
};
$whereSql = implode(' AND ', $where);
$total = (int) db_value("SELECT COUNT(*) {$base} WHERE {$whereSql}", $params);
$pages = max(1, (int) ceil($total / PER_PAGE));
$page = min($page, $pages);
$rows = db_all("{$select} {$base} WHERE {$whereSql} ORDER BY {$order} LIMIT " . PER_PAGE . ' OFFSET ' . (($page - 1) * PER_PAGE), $params);
$partsOff = [];
if ($rows) {
    foreach (db_all(
        'SELECT account_id, COUNT(*) AS n FROM access_blocks WHERE account_type = ? AND account_id IN (' . implode(',', array_map(static fn ($r) => (int) $r['id'], $rows)) . ') GROUP BY account_id',
        [$type]
    ) as $row) {
        $partsOff[(int) $row['account_id']] = (int) $row['n'];
    }
}

$counts = [
    'staff' => db_one('SELECT COUNT(*) AS n, SUM(is_active = 0) AS off FROM users'),
    'parents' => db_one('SELECT COUNT(*) AS n, SUM(is_active = 0) AS off FROM parents'),
    'learners' => db_one('SELECT COUNT(*) AS n, SUM(login_blocked = 1) AS off FROM students'),
];
$inactiveOpen = $type === 'learner' ? (int) db_value("SELECT COUNT(*) FROM students WHERE status <> 'active' AND login_blocked = 0") : 0;
$groupOpen = Access::loginOpen($type);
$editLink = static fn (array $r): string => match ($type) {
    'staff' => 'admin/users.php?edit=' . $r['id'],
    'parent' => 'admin/parent-edit.php?id=' . $r['id'],
    default => 'admin/student-edit.php?id=' . $r['id'],
};
$roleLabel = static fn (array $r): string => match ($type) {
    'staff' => STAFF_ROLES[$r['role']] ?? $r['role'],
    'parent' => $r['detail'] ? 'Parent of ' . $r['detail'] : 'No child linked yet',
    default => $r['detail'] . ($r['role'] !== 'active' ? ' · ' . ucfirst(str_replace('_', ' ', $r['role'])) : ''),
};

$title = 'Accounts & sign-in';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Accounts &amp; sign-in</h1>
        <p class="sub">Who can sign in and when they last did. Press <strong>Manage</strong> to control one person: their sign-in, and which parts of the portal they can use. Every action is written to the activity log.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($type === 'staff'): ?><a class="btn btn-primary" href="<?= e(url('admin/users.php')) ?>"><i class="bi bi-person-plus me-1"></i>New staff account</a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= e(url('superadmin/index.php')) ?>"><i class="bi bi-toggles me-1"></i>Control panel</a>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach (ACCOUNT_TABS as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link<?= $tab === $key ? ' active" aria-current="page' : '' ?>" href="<?= e(url('superadmin/accounts.php?tab=' . $key)) ?>">
                <?= e($label) ?> <span class="badge rounded-pill text-bg-light border"><?= (int) $counts[$key]['n'] ?></span>
                <?php if ((int) $counts[$key]['off']): ?><span class="badge text-bg-secondary"><?= (int) $counts[$key]['off'] ?> off</span><?php endif; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (!$groupOpen): ?>
    <div class="alert alert-warning"><i class="bi bi-slash-circle" aria-hidden="true"></i><div><?= e(Access::closedMessage($type)) ?> Change it in the <a href="<?= e(url('superadmin/index.php')) ?>">control panel</a>.</div></div>
<?php endif; ?>
<?php if ($inactiveOpen): ?>
    <form method="post" action="<?= e(url($here)) ?>" class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="turn_off_inactive_learners">
        <span><i class="bi bi-info-circle-fill me-1" aria-hidden="true"></i><?= $inactiveOpen ?> learner(s) who graduated, transferred out or dropped can still sign in.</span>
        <button class="btn btn-sm btn-outline-primary" onclick="return confirm('Turn off sign-in for these <?= $inactiveOpen ?> learner(s)?')">Turn their sign-in off</button>
    </form>
<?php endif; ?>

<form class="row g-2 mb-3" method="get" action="<?= e(url('superadmin/accounts.php')) ?>">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="col-md-6"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= $type === 'learner' ? 'Name or LRN' : ($type === 'parent' ? 'Name, mobile number or email' : 'Name or username') ?>" aria-label="Search"></div>
    <div class="col-md-3">
        <select class="form-select" name="status" aria-label="Show">
            <?php foreach (ACCOUNT_FILTERS as $key => $label): ?><option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Search</button></div>
</form>

<p class="small text-secondary mb-2"><?= $total ?> account<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? " · page {$page} of {$pages}" : '' ?></p>
<?php if (!$rows): ?>
    <p class="text-secondary">No accounts match.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle shadow-sm">
            <thead class="table-light"><tr><th>Name</th><th>Status</th><th>Last sign-in</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $on = (bool) $r['is_active'];
                $isLocked = isset($locked[$r['lock_key']]);
                $self = $type === 'staff' && (int) $r['id'] === $staff['id']; ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($r['name']) ?><?= $self ? ' <span class="badge text-bg-primary">You</span>' : '' ?></div>
                        <div class="small text-secondary"><span class="<?= $type === 'learner' ? 'lrn' : '' ?>"><?= e($r['ident']) ?></span> · <?= e($roleLabel($r)) ?></div>
                    </td>
                    <td class="small">
                        <?php if ($on): ?><span class="badge text-bg-success">Can sign in</span><?php else: ?><span class="badge text-bg-danger">Turned off</span><?php endif; ?>
                        <?php if ($isLocked): ?> <span class="badge text-bg-warning"><i class="bi bi-lock-fill"></i>Locked</span><?php endif; ?>
                        <?php if ($r['must_change_password']): ?> <span class="badge text-bg-info">Temporary password</span><?php endif; ?>
                        <?php if (!$on && $r['blocked_reason']): ?><div class="text-secondary mt-1"><?= e($r['blocked_reason']) ?></div><?php endif; ?>
                        <?php if ($partsOff[(int) $r['id']] ?? 0): ?><div class="mt-1"><a class="badge text-bg-warning text-decoration-none" href="<?= e(url('superadmin/person.php?type=' . $type . '&id=' . $r['id'])) ?>"><i class="bi bi-person-x"></i><?= $partsOff[(int) $r['id']] ?> part<?= $partsOff[(int) $r['id']] === 1 ? '' : 's' ?> off</a></div><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($r['last_login_at']): ?>
                            <?= e(when_label($r['last_login_at'])) ?><div class="text-secondary"><?= e((string) $r['last_login_device']) ?></div>
                        <?php else: ?><span class="text-secondary">Never</span><?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-primary" href="<?= e(url('superadmin/person.php?type=' . $type . '&id=' . $r['id'])) ?>" title="Control this person's access"><i class="bi bi-sliders me-1"></i>Manage</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($editLink($r))) ?>" title="Open the account" aria-label="Open <?= e($r['name']) ?>"><i class="bi bi-pencil"></i></a>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">Access</button>
                            <div class="dropdown-menu dropdown-menu-end p-2" style="min-width: 17rem">
                                <?php if ($on && !$self): ?>
                                    <form method="post" action="<?= e(url($here)) ?>" class="mb-2">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="turn_off"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <label class="form-label small mb-1" for="reason-<?= (int) $r['id'] ?>">Turn off sign-in — reason (shown to them)</label>
                                        <input class="form-control form-control-sm mb-1" id="reason-<?= (int) $r['id'] ?>" name="reason" maxlength="255" placeholder="e.g. Suspended until Dec 5">
                                        <button class="btn btn-sm btn-danger w-100"><i class="bi bi-slash-circle me-1"></i>Turn off sign-in</button>
                                    </form>
                                <?php elseif (!$on): ?>
                                    <form method="post" action="<?= e(url($here)) ?>" class="mb-2">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="turn_on"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button class="btn btn-sm btn-success w-100"><i class="bi bi-check-circle me-1"></i>Turn sign-in back on</button>
                                    </form>
                                <?php endif; ?>
                                <?php foreach (array_filter([
                                    !$self ? ['sign_out', 'box-arrow-right', 'Sign out of every device', null] : null,
                                    $isLocked ? ['unlock', 'unlock', 'Unlock (clear wrong tries)', null] : null,
                                    !$self ? ['reset_password', 'key', $type === 'learner' ? 'Clear password (activate again)' : 'Reset password', $type === 'learner' ? 'Clear this learner\'s password?' : 'Make a new temporary password for this person?'] : null,
                                ]) as [$act, $icon, $label, $confirm]): ?>
                                    <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="<?= e($act) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button class="dropdown-item"<?= $confirm ? ' onclick="return confirm(' . e(json_encode($confirm)) . ')"' : '' ?>><i class="bi bi-<?= e($icon) ?>"></i><?= e($label) ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <?php if ($self): ?><p class="small text-secondary m-0 px-2">This is your own account.</p><?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <nav aria-label="Pages"><ul class="pagination pagination-sm">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e(url('superadmin/accounts.php?' . http_build_query(['tab' => $tab, 'q' => $q, 'status' => $status, 'page' => $p]))) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
        </ul></nav>
    <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
