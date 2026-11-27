<?php
// One school (or solo teacher) in the platform console: details, pause/resume, admin password reset, and
// opening the school's portal to help them (logged in the school's own activity log).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$owner = require_owner();
$id = get_int('id') ?: post_int('id');
$workspace = Workspace::find($id) ?? abort(404, 'That school does not exist.');
$here = 'platform/workspace.php?id=' . $id;
$errors = [];

/** A staff account of this workspace that may run it (Super Admin or admin), or null. */
$adminAccount = static fn (int $userId): ?array => db_one(
    "SELECT * FROM users WHERE id = ? AND workspace_id = ? AND role IN ('superadmin', 'admin')",
    [$userId, $id]
);

if (is_post()) {
    $action = post('action');

    if ($action === 'save') {
        $data = [
            'type' => post('type'), 'name' => post('name'), 'plan' => post('plan'), 'contact_name' => post('contact_name'),
            'contact_email' => post('contact_email'), 'contact_phone' => post('contact_phone'), 'notes' => mb_substr(post('notes'), 0, 5000),
            'trial_ends_at' => post('trial_ends_at'), 'paid_until' => post('paid_until'),
        ];
        foreach (['trial_ends_at', 'paid_until'] as $dateField) {
            if ($data[$dateField] !== '' && !valid_ymd($data[$dateField])) {
                $errors[] = 'Check the trial and paid-until dates.';
            }
        }
        if (!isset(Workspace::TYPES[$data['type']]) || !isset(Workspace::PLANS[$data['plan']])) {
            $errors[] = 'Choose a type and a plan.';
        }
        if ($data['name'] === '' || mb_strlen($data['name']) > 150) {
            $errors[] = 'Enter the name.';
        }
        if ($data['contact_email'] !== '' && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The contact email doesn\'t look right.';
        }
        if (mb_strlen($data['contact_name']) > 150 || mb_strlen($data['contact_phone']) > 30) {
            $errors[] = 'The contact name or phone is too long.';
        }
        if (!$errors) {
            db_exec(
                'UPDATE workspaces SET type = ?, name = ?, plan = ?, contact_name = ?, contact_email = ?, contact_phone = ?, notes = ?, trial_ends_at = ?, paid_until = ? WHERE id = ?',
                [$data['type'], $data['name'], $data['plan'], $data['contact_name'] ?: null, $data['contact_email'] ?: null, $data['contact_phone'] ?: null, $data['notes'] ?: null,
                 $data['trial_ends_at'] ?: null, $data['paid_until'] ?: null, $id]
            );
            // The name printed on report cards and forms follows
            db_exec(
                "INSERT INTO settings (workspace_id, name, value) VALUES (?, 'school_name', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$id, $data['name']]
            );
            $changed = array_keys(array_diff_assoc(array_map('strval', $data), array_map('strval', array_intersect_key($workspace, $data))));
            platform_log('workspace', "Updated {$data['name']}" . ($changed ? ' (' . implode(', ', $changed) . ')' : ''), $id);
            flash('success', 'Saved.');
            redirect($here);
        }
    }

    if ($action === 'pause' || $action === 'resume') {
        $reason = mb_substr(post('reason'), 0, 255);
        if ($action === 'pause') {
            db_exec("UPDATE workspaces SET status = 'suspended', suspended_reason = ? WHERE id = ?", [$reason ?: null, $id]);
            platform_log('workspace', "Paused {$workspace['name']}" . ($reason !== '' ? ": {$reason}" : ''), $id);
            flash('success', "{$workspace['name']} is paused. Its staff, learners and parents can't sign in, and anyone signed in is stopped on their next click. Nothing is deleted.");
        } else {
            db_exec("UPDATE workspaces SET status = 'active', suspended_reason = NULL WHERE id = ?", [$id]);
            platform_log('workspace', "Resumed {$workspace['name']}", $id);
            flash('success', "{$workspace['name']} can sign in again.");
        }
        redirect($here);
    }

    if ($action === 'password') {
        $account = $adminAccount(post_int('user_id'));
        if (!$account) {
            flash('danger', 'Choose one of this school\'s admin accounts.');
            redirect($here);
        }
        $password = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
        db_exec(
            'UPDATE users SET password_hash = ?, must_change_password = 1, is_active = 1, blocked_reason = NULL, session_version = session_version + 1 WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $account['id']]
        );
        db_exec("DELETE FROM login_attempts WHERE scope = 'staff' AND identifier = ?", [mb_strtolower($account['username'])]);
        platform_log('password', "Gave {$account['username']} of {$workspace['name']} a temporary password", $id);
        flash('success', "Temporary password for {$account['username']}: \"{$password}\" (shown only now). They must choose a new one when they sign in.");
        redirect($here);
    }

    if ($action === 'export') {
        platform_log('export', "Downloaded all data of {$workspace['name']}", $id);
        Privacy::sendExport($id);
    }

    if ($action === 'delete_data') {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $ownerRow = db_one('SELECT password_hash FROM platform_owners WHERE id = ?', [$owner['id']]);
        if (!password_verify($password, (string) $ownerRow['password_hash'])) {
            flash('danger', 'Your password is not correct.');
        } elseif (trim(post('confirm_name')) !== $workspace['name']) {
            flash('danger', 'Type the name of the school exactly as shown to confirm.');
        } else {
            try {
                $count = Privacy::deleteWorkspace($id);
                platform_log('deleted', "Deleted all data of {$workspace['name']} ({$count} records); payments kept", $id);
                flash('success', "All data of {$workspace['name']} was deleted ({$count} records and its files). Its payments are kept for your accounts.");
            } catch (UserFacingException $e) {
                flash('danger', $e->getMessage());
            }
        }
        redirect($here);
    }

    if ($action === 'open') {
        $account = $adminAccount(post_int('user_id'));
        if (!$account || !$account['is_active']) {
            flash('danger', 'Choose an active admin account of this school.');
            redirect($here);
        }
        // Signs in as that account in this browser. The school sees the visit in its activity log.
        login_staff($account);
        $_SESSION['acting_owner'] = (int) $owner['id'];
        Access::log('owner-visit', "The " . config('app_name') . " platform owner ({$owner['full_name']}) opened the portal as {$account['username']} to help the school", 'staff', (int) $account['id']);
        platform_log('open', "Opened {$workspace['name']} as {$account['username']}", $id);
        redirect($account['must_change_password'] ? 'staff/password.php' : home_for(current_staff()));
    }
}

$counts = db_one(
    'SELECT (SELECT COUNT(*) FROM users WHERE workspace_id = ? AND is_active = 1) AS staff,
            (SELECT COUNT(*) FROM students WHERE workspace_id = ?) AS learners,
            (SELECT COUNT(*) FROM parents WHERE workspace_id = ?) AS parents,
            (SELECT COUNT(*) FROM sections WHERE workspace_id = ?) AS sections,
            (SELECT COUNT(*) FROM class_sessions WHERE workspace_id = ?) AS sessions,
            (SELECT MAX(created_at) FROM login_events WHERE workspace_id = ? AND success = 1) AS last_sign_in',
    array_fill(0, 6, $id)
);
$admins = db_all(
    "SELECT id, username, full_name, role, is_active, must_change_password, last_login_at FROM users
      WHERE workspace_id = ? AND role IN ('superadmin', 'admin') ORDER BY role = 'superadmin' DESC, full_name",
    [$id]
);
$log = db_all('SELECT * FROM platform_log WHERE workspace_id = ? ORDER BY id DESC LIMIT 15', [$id]);
$w = $errors ? array_merge($workspace, array_intersect_key($_POST, $workspace)) : $workspace;

$title = $workspace['name'];
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= e(url('platform/index.php')) ?>">Schools</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($workspace['name']) ?></li></ol></nav>
<?php if ($workspace['deletion_requested_at'] && $workspace['status'] !== 'deleted'): ?>
    <div class="alert alert-warning" role="status"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <div>This school asked to close its account and delete its data on <?= e(date('F j, Y', strtotime($workspace['deletion_requested_at']))) ?>.
            Contact them, pause the school, then delete its data below.</div></div>
<?php endif; ?>
<?php if ($workspace['status'] === 'deleted'): ?>
    <div class="alert alert-secondary" role="status"><i class="bi bi-trash3" aria-hidden="true"></i>
        <div>All data of this school was deleted on <?= e(date('F j, Y', strtotime((string) $workspace['deleted_at']))) ?>. Only its name, contact and payments are kept.</div></div>
<?php endif; ?>
<div class="page-head">
    <div>
        <h1 class="h3"><?= e($workspace['name']) ?>
            <?= $workspace['status'] === 'active' ? '<span class="badge text-bg-success align-middle fs-6">Active</span>' : '<span class="badge text-bg-danger align-middle fs-6">Paused</span>' ?></h1>
        <?php $bill = Billing::state($workspace); ?>
        <p class="sub"><?= e(Workspace::TYPES[$workspace['type']] ?? $workspace['type']) ?> · <?= e($bill['label']) ?><?= $bill['ends'] ? ' until ' . e(date('M j, Y', strtotime($bill['ends']))) : '' ?><?= $bill['read_only'] ? ' · <span class="text-danger fw-semibold">read-only</span>' : '' ?> · added <?= e(date('M j, Y', strtotime($workspace['created_at']))) ?></p>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([['Staff', $counts['staff']], ['Learners', $counts['learners']], ['Parents', $counts['parents']], ['Sections', $counts['sections']], ['Classes held', $counts['sessions']]] as [$label, $value]): ?>
        <div class="col-6 col-md">
            <div class="card shadow-sm h-100"><div class="card-body"><div class="h4 mb-0"><?= number_format((int) $value) ?></div><div class="small text-secondary"><?= e($label) ?></div></div></div>
        </div>
    <?php endforeach; ?>
    <div class="col-12 col-md">
        <div class="card shadow-sm h-100"><div class="card-body"><div class="h6 mb-1"><?= $counts['last_sign_in'] ? e(when_label($counts['last_sign_in'])) : 'Never' ?></div><div class="small text-secondary">Last sign-in</div></div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Admin accounts</h2>
                <p class="small text-secondary">To help the school, open its portal as one of these accounts. The school's activity log records the visit.</p>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th scope="col">Account</th><th scope="col">Last sign-in</th><th scope="col"></th></tr></thead>
                        <tbody>
                        <?php foreach ($admins as $a): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($a['full_name']) ?></div>
                                    <div class="small text-secondary"><?= e($a['username']) ?> · <?= e(STAFF_ROLES[$a['role']] ?? $a['role']) ?>
                                        <?= !$a['is_active'] ? ' · <span class="text-danger">turned off</span>' : '' ?><?= $a['must_change_password'] ? ' · must choose a password' : '' ?></div>
                                </td>
                                <td class="small text-secondary"><?= $a['last_login_at'] ? e(when_label($a['last_login_at'])) : 'Never' ?></td>
                                <td class="text-end text-nowrap">
                                    <form method="post" action="<?= e(url($here)) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $id ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" name="action" value="password"
                                                onclick="return confirm('Give <?= e($a['username']) ?> a new temporary password? Their current password stops working.')">Reset password</button>
                                        <?php if ($a['is_active']): ?>
                                            <button class="btn btn-sm btn-primary" name="action" value="open">Open portal</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$admins): ?><tr><td colspan="3" class="text-secondary">No admin accounts.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Details</h2>
                <?php if ($errors): ?><div class="alert alert-danger" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url($here)) ?>" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <div class="col-sm-8">
                        <label class="form-label small" for="name">Name</label>
                        <input class="form-control" id="name" name="name" value="<?= e($w['name']) ?>" maxlength="150" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="type">Type</label>
                        <select class="form-select" id="type" name="type">
                            <?php foreach (Workspace::TYPES as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $w['type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="contact_name">Contact person</label>
                        <input class="form-control" id="contact_name" name="contact_name" value="<?= e((string) $w['contact_name']) ?>" maxlength="150">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="contact_email">Contact email</label>
                        <input class="form-control" type="email" id="contact_email" name="contact_email" value="<?= e((string) $w['contact_email']) ?>" maxlength="150">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="contact_phone">Contact mobile</label>
                        <input class="form-control" id="contact_phone" name="contact_phone" value="<?= e((string) $w['contact_phone']) ?>" maxlength="30">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="plan">Plan</label>
                        <select class="form-select" id="plan" name="plan">
                            <?php foreach (Workspace::PLANS as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $w['plan'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="trial_ends_at">Trial ends</label>
                        <input class="form-control" type="date" id="trial_ends_at" name="trial_ends_at" value="<?= e((string) $w['trial_ends_at']) ?>">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="paid_until">Paid until</label>
                        <input class="form-control" type="date" id="paid_until" name="paid_until" value="<?= e((string) $w['paid_until']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small" for="notes">Notes (only you see these)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="5000"><?= e((string) $w['notes']) ?></textarea>
                    </div>
                    <div class="col-12"><button class="btn btn-primary">Save</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($workspace['status'] !== 'deleted'): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Data</h2>
                <p class="small text-secondary">All of this school's records (CSV) and uploaded files, in one ZIP. Passwords and card codes are left out. The download is logged.</p>
                <form method="post" action="<?= e(url($here)) ?>" class="mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <input type="hidden" name="action" value="export">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1" aria-hidden="true"></i>Download all data</button>
                </form>
                <?php if ($workspace['status'] === 'suspended' && $id !== 1): ?>
                    <h3 class="h6 text-danger">Delete all data</h3>
                    <p class="small text-secondary">For good: learners, grades, attendance, parents, staff, messages and files. Only the name, contact and payments stay. This can't be undone.</p>
                    <form method="post" action="<?= e(url($here)) ?>" class="row g-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $id ?>">
                        <input type="hidden" name="action" value="delete_data">
                        <div class="col-12"><label class="form-label small" for="confirm_name">Type the school's name: <strong><?= e($workspace['name']) ?></strong></label><input class="form-control form-control-sm" id="confirm_name" name="confirm_name" autocomplete="off" required></div>
                        <div class="col-12"><label class="form-label small" for="owner-password">Your password</label><input class="form-control form-control-sm" type="password" id="owner-password" name="password" autocomplete="current-password" required></div>
                        <div class="col-12"><button class="btn btn-sm btn-danger" onclick="return confirm('Delete all data of this school for good?')">Delete all data</button></div>
                    </form>
                <?php elseif ($id !== 1): ?>
                    <p class="small text-secondary mb-0">To delete all of its data, pause the school first.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <?php if ($workspace['status'] === 'active'): ?>
                    <h2 class="h5">Pause this school</h2>
                    <p class="small text-secondary">For example when the subscription ends. Nobody in the school can sign in until you resume it. Nothing is deleted.</p>
                    <form method="post" action="<?= e(url($here)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $id ?>">
                        <label class="form-label small" for="reason">Reason shown to the school (optional)</label>
                        <input class="form-control mb-3" id="reason" name="reason" maxlength="255" placeholder="e.g. the subscription ended on June 30">
                        <button class="btn btn-outline-danger" name="action" value="pause"
                                onclick="return confirm('Pause <?= e($workspace['name']) ?>? Everyone in the school is signed out.')">Pause</button>
                    </form>
                <?php else: ?>
                    <h2 class="h5">This school is paused</h2>
                    <p class="small text-secondary mb-3"><?= $workspace['suspended_reason'] ? 'Reason: ' . e($workspace['suspended_reason']) : 'No reason given.' ?></p>
                    <form method="post" action="<?= e(url($here)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $id ?>">
                        <button class="btn btn-success" name="action" value="resume">Resume</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Console activity for this school</h2>
                <?php if (!$log): ?><p class="text-secondary small mb-0">Nothing yet.</p><?php endif; ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($log as $row): ?>
                        <li class="py-2 border-bottom"><div><?= e($row['summary']) ?></div><div class="text-secondary"><?= e(when_label($row['created_at'])) ?> · <?= e($row['owner_name']) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
