<?php
// Parent accounts: find, create (for parents who need help signing up), open to edit.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$form = ['full_name' => '', 'login' => '', 'lrn' => '', 'relationship' => 'mother'];
$errors = [];

if (is_post() && post('action') === 'create') {
    $form = ['full_name' => post('full_name'), 'login' => post('login'), 'lrn' => post('lrn'), 'relationship' => post('relationship')];
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $login = normalize_parent_login($form['login']);
    $student = null;

    if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 150) {
        $errors[] = 'Enter the parent\'s full name.';
    }
    if ($login === null) {
        $errors[] = 'Enter a mobile number (09XX XXX XXXX) or an email address.';
    } elseif (db_value('SELECT id FROM parents WHERE login = ?', [$login])) {
        $errors[] = 'A parent account with this mobile number or email already exists.';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'The temporary password must be at least 8 characters.';
    }
    if (!isset(RELATIONSHIPS[$form['relationship']])) {
        $errors[] = 'Choose a relationship.';
    }
    if ($form['lrn'] !== '') {
        $student = db_one('SELECT * FROM students WHERE lrn = ?', [normalize_lrn($form['lrn'])]);
        if (!$student) {
            $errors[] = 'No learner has that LRN.';
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_exec(
                'INSERT INTO parents (full_name, login, password_hash, must_change_password) VALUES (?, ?, ?, 1)',
                [$form['full_name'], $login, password_hash($password, PASSWORD_DEFAULT)]
            );
            $parentId = (int) $pdo->lastInsertId();
            if ($student) {
                db_exec('INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)', [$parentId, $student['id'], $form['relationship']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('success', "Account created. Give {$form['full_name']} the sign-in \"{$login}\" and the temporary password; they will be asked to change it.");
        redirect('admin/parent-edit.php?id=' . $parentId);
    }
}

$q = get_str('q');
$params = [];
$where = '';
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where = 'WHERE p.full_name LIKE ? OR p.login LIKE ? OR st.lrn LIKE ? OR st.last_name LIKE ? OR st.first_name LIKE ?';
    $params = [$like, $like, $like, $like, $like];
}
$parents = db_all(
    "SELECT p.*, GROUP_CONCAT(CONCAT(st.first_name, ' ', st.last_name) ORDER BY st.first_name SEPARATOR ', ') AS children
       FROM parents p
       LEFT JOIN parent_students ps ON ps.parent_id = p.id
       LEFT JOIN students st        ON st.id = ps.student_id
       {$where}
      GROUP BY p.id
      ORDER BY p.full_name
      LIMIT 200",
    $params
);

$title = 'Parent accounts';
require APP_ROOT . '/templates/header.php';
?>
<h1 class="h3">Parent accounts</h1>
<p class="text-secondary">
    Parents usually create their own account with their child's LRN and the link code on the slip
    (print slips from a section's page). Create an account here for parents who need help.
</p>
<div class="row g-4">
    <div class="col-xl-8">
        <form method="get" action="<?= e(url('admin/parents.php')) ?>" class="d-flex gap-2 mb-3" role="search">
            <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by parent, mobile/email, learner name or LRN" aria-label="Search parents">
            <button class="btn btn-outline-primary">Search</button>
            <?php if ($q !== ''): ?><a class="btn btn-link" href="<?= e(url('admin/parents.php')) ?>">Clear</a><?php endif; ?>
        </form>
        <div class="table-responsive">
            <table class="table table-sm bg-white shadow-sm align-middle">
                <thead class="table-light"><tr><th scope="col">Parent</th><th scope="col">Signs in with</th><th scope="col">Children</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php foreach ($parents as $p): ?>
                    <tr class="<?= $p['is_active'] ? '' : 'text-secondary' ?>">
                        <td><?= e($p['full_name']) ?> <?= $p['is_active'] ? '' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                        <td><?= e($p['login']) ?></td>
                        <td class="small"><?= e($p['children'] ?? '—') ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/parent-edit.php?id=' . $p['id'])) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$parents): ?><tr><td colspan="4" class="text-secondary"><?= $q !== '' ? 'No parent accounts match.' : 'No parent accounts yet.' ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Create a parent account</h2>
                <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="post" action="<?= e(url('admin/parents.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-2">
                        <label class="form-label small" for="full_name">Full name</label>
                        <input class="form-control" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="150" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small" for="login">Mobile number or email</label>
                        <input class="form-control" id="login" name="login" value="<?= e($form['login']) ?>" maxlength="150" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small" for="password">Temporary password</label>
                        <input class="form-control" id="password" name="password" type="text" minlength="8" autocomplete="off" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label small" for="lrn">Child's LRN <span class="text-secondary">(optional)</span></label>
                            <input class="form-control lrn" id="lrn" name="lrn" value="<?= e($form['lrn']) ?>" inputmode="numeric" maxlength="16">
                        </div>
                        <div class="col-5">
                            <label class="form-label small" for="relationship">Relationship</label>
                            <select class="form-select" id="relationship" name="relationship">
                                <?php foreach (RELATIONSHIPS as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $form['relationship'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button class="btn btn-primary">Create account</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
