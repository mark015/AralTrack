<?php
// One parent account: details, password reset, active/inactive, and linked children.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$parent = db_one('SELECT * FROM parents WHERE id = ? AND workspace_id = ?', [get_int('id'), ws()]);
if (!$parent) {
    abort(404, 'That parent account does not exist.');
}
$parentId = (int) $parent['id'];
$here = 'admin/parent-edit.php?id=' . $parentId;

if (is_post()) {
    switch (post('action')) {
        case 'save':
            $name = post('full_name');
            $login = normalize_parent_login(post('login'));
            if ($name === '' || mb_strlen($name) > 150 || $login === null) {
                flash('danger', 'Enter a name and a valid mobile number or email.');
                break;
            }
            try {
                db_exec('UPDATE parents SET full_name = ?, login = ? WHERE id = ? AND workspace_id = ?', [$name, $login, $parentId, ws()]);
                Access::log('account', "Updated the parent account of {$name}", 'parent', $parentId, ['full_name' => [$parent['full_name'], $name], 'login' => [$parent['login'], $login]]);
                flash('success', 'Saved.');
            } catch (PDOException $e) {
                if (!is_duplicate_error($e)) {
                    throw $e;
                }
                flash('danger', 'Another parent account already uses that mobile number or email.');
            }
            break;

        case 'reset':
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if (mb_strlen($password) < 8) {
                flash('danger', 'The temporary password must be at least 8 characters.');
                break;
            }
            db_exec('UPDATE parents SET password_hash = ?, must_change_password = 1 WHERE id = ? AND workspace_id = ?', [password_hash($password, PASSWORD_DEFAULT), $parentId, ws()]);
            db_exec("DELETE FROM login_attempts WHERE scope = 'parent' AND identifier = ?", [$parent['login']]);
            Access::signOut('parent', $parentId);
            Access::log('account', "Reset the password of parent {$parent['full_name']}", 'parent', $parentId);
            flash('success', 'Password reset. They will be asked to choose a new one when they sign in.');
            break;

        case 'toggle':
            db_exec('UPDATE parents SET is_active = 1 - is_active, blocked_reason = NULL WHERE id = ? AND workspace_id = ?', [$parentId, ws()]);
            Access::log('account', ($parent['is_active'] ? 'Deactivated' : 'Activated') . " the parent account of {$parent['full_name']}", 'parent', $parentId);
            flash('success', $parent['is_active'] ? 'This parent can no longer sign in.' : 'This parent can sign in again.');
            break;

        case 'link':
            $student = db_one('SELECT * FROM students WHERE lrn = ? AND workspace_id = ?', [normalize_lrn(post('lrn')), ws()]);
            $relationship = post('relationship');
            if (!$student || !isset(RELATIONSHIPS[$relationship])) {
                flash('danger', 'Enter a learner\'s LRN and choose the relationship.');
                break;
            }
            $added = db_exec('INSERT IGNORE INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)', [$parentId, $student['id'], $relationship]);
            if ($added) {
                Access::log('account', "Linked " . student_name($student) . " to parent {$parent['full_name']}", 'parent', $parentId);
            }
            flash($added ? 'success' : 'info', $added ? student_name($student) . ' linked.' : 'That learner is already linked.');
            break;

        case 'unlink':
            $unlinked = db_one('SELECT * FROM students WHERE id = ? AND workspace_id = ?', [post_int('student_id'), ws()]);
            db_exec('DELETE FROM parent_students WHERE parent_id = ? AND student_id = ?', [$parentId, post_int('student_id')]);
            Access::log('account', 'Unlinked ' . ($unlinked ? student_name($unlinked) : 'a learner') . " from parent {$parent['full_name']}", 'parent', $parentId);
            flash('success', 'Learner unlinked. This parent can no longer see their attendance and grades.');
            break;
    }
    redirect($here);
}

$children = db_all(
    'SELECT st.*, ps.relationship FROM parent_students ps JOIN students st ON st.id = ps.student_id WHERE ps.parent_id = ? ORDER BY st.first_name',
    [$parentId]
);

$title = $parent['full_name'];
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('admin/parents.php')) ?>">Parent accounts</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($parent['full_name']) ?></li>
    </ol>
</nav>
<h1 class="h3"><?= e($parent['full_name']) ?> <?= $parent['is_active'] ? '' : '<span class="badge text-bg-secondary fs-6 align-middle">Inactive</span>' ?></h1>
<p class="text-secondary small">Account created <?= e(date('M j, Y', strtotime($parent['created_at']))) ?></p>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Linked children</h2>
                <?php if (!$children): ?><p class="text-secondary small">No learners linked yet.</p><?php endif; ?>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($children as $c): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>
                                <a href="<?= e(url('admin/student-edit.php?id=' . $c['id'])) ?>"><?= e(student_name($c)) ?></a>
                                <span class="small text-secondary">· LRN <?= e($c['lrn']) ?> · <?= e(RELATIONSHIPS[$c['relationship']]) ?></span>
                            </span>
                            <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="unlink">
                                <input type="hidden" name="student_id" value="<?= (int) $c['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Unlink this learner from the parent account?')">Unlink</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" action="<?= e(url($here)) ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="link">
                    <div class="col-sm-6">
                        <label class="form-label small" for="lrn">Link a learner by LRN</label>
                        <input class="form-control form-control-sm lrn" id="lrn" name="lrn" inputmode="numeric" maxlength="16" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="relationship">Relationship</label>
                        <select class="form-select form-select-sm" id="relationship" name="relationship">
                            <?php foreach (RELATIONSHIPS as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-2 d-grid"><button class="btn btn-sm btn-primary">Link</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">Account</h2>
                <form method="post" action="<?= e(url($here)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <label class="form-label small" for="full_name">Full name</label>
                    <input class="form-control form-control-sm mb-2" id="full_name" name="full_name" value="<?= e($parent['full_name']) ?>" maxlength="150" required>
                    <label class="form-label small" for="login">Mobile number or email (used to sign in)</label>
                    <input class="form-control form-control-sm mb-3" id="login" name="login" value="<?= e($parent['login']) ?>" maxlength="150" required>
                    <button class="btn btn-sm btn-primary">Save</button>
                </form>
            </div>
        </div>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">Reset password</h2>
                <form method="post" action="<?= e(url($here)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset">
                    <label class="form-label small" for="password">New temporary password</label>
                    <input class="form-control form-control-sm mb-2" id="password" name="password" type="text" minlength="8" autocomplete="off" required>
                    <button class="btn btn-sm btn-outline-danger">Reset password</button>
                </form>
            </div>
        </div>
        <form method="post" action="<?= e(url($here)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <button class="btn btn-sm <?= $parent['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                <?= $parent['is_active'] ? 'Deactivate account' : 'Activate account' ?>
            </button>
        </form>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
