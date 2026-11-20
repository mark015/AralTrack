<?php
// Staff accounts: teachers, admins (registrar), the principal and guidance counselors.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$errors = [];
$editing = null;
$form = ['id' => 0, 'username' => '', 'full_name' => '', 'role' => 'teacher'];

if ($editId = get_int('edit')) {
    $editing = db_one('SELECT * FROM users WHERE id = ?', [$editId]);
    if ($editing) {
        $form = ['id' => (int) $editing['id'], 'username' => $editing['username'], 'full_name' => $editing['full_name'], 'role' => $editing['role']];
    }
}

if (is_post()) {
    $action = post('action');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($action === 'save') {
        $id = post_int('id');
        $form = ['id' => $id, 'username' => post('username'), 'full_name' => post('full_name'), 'role' => post('role')];
        if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 150) {
            $errors[] = 'Enter the full name (up to 150 characters).';
        }
        if (!isset(STAFF_ROLES[$form['role']])) {
            $errors[] = 'Choose a role.';
        }
        if ($id === $staff['id'] && $form['role'] !== 'admin') {
            $errors[] = 'You cannot remove your own admin role.';
        }
        if (!$id) {
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $form['username'])) {
                $errors[] = 'The username needs 3–50 letters, numbers, dots, dashes or underscores.';
            }
            if (mb_strlen($password) < 8) {
                $errors[] = 'The temporary password must be at least 8 characters.';
            }
        }
        if (!$errors) {
            try {
                if ($id) {
                    db_exec('UPDATE users SET full_name = ?, role = ? WHERE id = ?', [$form['full_name'], $form['role'], $id]);
                    flash('success', "{$form['full_name']}'s account was updated.");
                } else {
                    db_exec(
                        'INSERT INTO users (username, password_hash, full_name, role, must_change_password) VALUES (?, ?, ?, ?, 1)',
                        [$form['username'], password_hash($password, PASSWORD_DEFAULT), $form['full_name'], $form['role']]
                    );
                    flash('success', "Account created. Give {$form['full_name']} the username \"{$form['username']}\" and the temporary password; they will be asked to change it.");
                }
                redirect('admin/users.php');
            } catch (PDOException $e) {
                if (!is_duplicate_error($e)) {
                    throw $e;
                }
                $errors[] = 'That username is already taken.';
            }
        }
        $editing = $id ? $form : null;
    } elseif ($action === 'reset') {
        $user = db_one('SELECT * FROM users WHERE id = ?', [post_int('id')]);
        if ($user && mb_strlen($password) >= 8) {
            db_exec('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            db_exec("DELETE FROM login_attempts WHERE scope = 'staff' AND identifier = ?", [mb_strtolower($user['username'])]);
            flash('success', "{$user['full_name']}'s password was reset. They will be asked to choose a new one when they sign in.");
        } else {
            flash('danger', 'The temporary password must be at least 8 characters.');
        }
        redirect('admin/users.php?edit=' . post_int('id'));
    } elseif ($action === 'toggle') {
        $user = db_one('SELECT * FROM users WHERE id = ?', [post_int('id')]);
        if ($user && (int) $user['id'] === $staff['id']) {
            flash('danger', 'You cannot deactivate your own account.');
        } elseif ($user) {
            db_exec('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$user['id']]);
            flash('success', $user['full_name'] . ($user['is_active'] ? ' can no longer sign in.' : ' can sign in again.'));
        }
        redirect('admin/users.php');
    }
}

$users = db_all(
    'SELECT u.*, (SELECT COUNT(*) FROM class_assignments ca JOIN sections s ON s.id = ca.section_id
                   JOIN school_years sy ON sy.id = s.school_year_id AND sy.is_active = 1
                  WHERE ca.teacher_id = u.id) AS classes
       FROM users u ORDER BY u.is_active DESC, u.full_name'
);

$title = 'Staff accounts';
require APP_ROOT . '/templates/header.php';
?>
<h1 class="h3">Staff accounts</h1>
<p class="text-secondary">Teachers upload grades for the classes assigned to them. Admins manage everything on this site.</p>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="table-responsive">
            <table class="table bg-white shadow-sm align-middle">
                <thead class="table-light"><tr><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Role</th><th scope="col" class="text-center">Classes</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr class="<?= $u['is_active'] ? '' : 'text-secondary' ?>">
                        <td><?= e($u['full_name']) ?> <?= $u['is_active'] ? '' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                        <td><?= e($u['username']) ?></td>
                        <td><?= e(STAFF_ROLES[$u['role']] ?? ucfirst($u['role'])) ?></td>
                        <td class="text-center"><?= (int) $u['classes'] ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/users.php?edit=' . $u['id'])) ?>">Edit</a>
                            <?php if ((int) $u['id'] !== $staff['id']): ?>
                                <form method="post" action="<?= e(url('admin/users.php')) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                        <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h5"><?= $editing ? 'Edit ' . e($form['full_name']) : 'Add a staff account' ?></h2>
                <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="post" action="<?= e(url('admin/users.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label" for="full_name">Full name</label>
                        <input class="form-control" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="150" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <input class="form-control" id="username" name="username" value="<?= e($form['username']) ?>" maxlength="50"
                               <?= $editing ? 'disabled' : 'required pattern="[A-Za-z0-9._\-]{3,50}"' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="role">Role</label>
                        <select class="form-select" id="role" name="role">
                            <?php foreach (STAFF_ROLES as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $form['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Teachers see only their classes and advisory. The principal sees reports and all records and can approve grade changes.
                            Guidance counselors see learner profiles, attendance, excuse letters and at-risk learners.
                        </div>
                    </div>
                    <?php if (!$editing): ?>
                        <div class="mb-3">
                            <label class="form-label" for="password">Temporary password</label>
                            <input class="form-control" id="password" name="password" type="text" minlength="8" autocomplete="off" required>
                            <div class="form-text">At least 8 characters. They must change it at first sign-in.</div>
                        </div>
                    <?php endif; ?>
                    <button class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create account' ?></button>
                    <?php if ($editing): ?><a class="btn btn-link" href="<?= e(url('admin/users.php')) ?>">Cancel</a><?php endif; ?>
                </form>
            </div>
        </div>
        <?php if ($editing): ?>
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Reset password</h2>
                    <form method="post" action="<?= e(url('admin/users.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
                        <label class="form-label" for="reset-password">New temporary password</label>
                        <input class="form-control mb-2" id="reset-password" name="password" type="text" minlength="8" autocomplete="off" required>
                        <button class="btn btn-outline-danger btn-sm">Reset password</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
