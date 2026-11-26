<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$errors = [];
$label = '';

if (is_post()) {
    if (post('action') === 'add') {
        $label = post('label');
        if (!preg_match('/^(\d{4})-(\d{4})$/', $label, $m) || (int) $m[2] !== (int) $m[1] + 1) {
            $errors[] = 'Write the school year like 2026-2027.';
        } else {
            $hasActive = (int) db_value('SELECT COUNT(*) FROM school_years WHERE is_active = 1 AND workspace_id = ?', [ws()]) > 0;
            try {
                db_exec('INSERT INTO school_years (workspace_id, label, is_active) VALUES (?, ?, ?)', [ws(), $label, $hasActive ? 0 : 1]);
                flash('success', "S.Y. {$label} added." . ($hasActive ? '' : ' It is now the active school year.'));
                redirect('admin/school-years.php');
            } catch (PDOException $e) {
                if (!is_duplicate_error($e)) {
                    throw $e;
                }
                $errors[] = "S.Y. {$label} already exists.";
            }
        }
    } elseif (post('action') === 'activate') {
        $year = db_one('SELECT * FROM school_years WHERE id = ? AND workspace_id = ?', [post_int('id'), ws()]);
        if ($year) {
            db_exec('UPDATE school_years SET is_active = (id = ?) WHERE workspace_id = ?', [$year['id'], ws()]);
            flash('success', "S.Y. {$year['label']} is now the active school year. Teachers see its classes.");
        }
        redirect('admin/school-years.php');
    }
}

$years = db_all(
    'SELECT sy.*,
            (SELECT COUNT(*) FROM sections s WHERE s.school_year_id = sy.id) AS sections,
            (SELECT COUNT(*) FROM enrollments e WHERE e.school_year_id = sy.id) AS learners
       FROM school_years sy
      WHERE sy.workspace_id = ?
      ORDER BY sy.label DESC',
    [ws()]
);

$title = 'School years';
require APP_ROOT . '/templates/header.php';
?>
<h1 class="h3">School years</h1>
<p class="text-secondary">The active school year is the one teachers grade and the one new enrollments go into. Learners can still view past years.</p>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="table-responsive">
            <table class="table bg-white shadow-sm align-middle">
                <thead class="table-light"><tr><th scope="col">School year</th><th scope="col" class="text-center">Sections</th><th scope="col" class="text-center">Learners</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php foreach ($years as $year): ?>
                    <tr>
                        <td>S.Y. <?= e($year['label']) ?> <?= $year['is_active'] ? '<span class="badge text-bg-success ms-1">Active</span>' : '' ?></td>
                        <td class="text-center"><?= (int) $year['sections'] ?></td>
                        <td class="text-center"><?= (int) $year['learners'] ?></td>
                        <td class="text-end">
                            <?php if (!$year['is_active']): ?>
                                <form method="post" action="<?= e(url('admin/school-years.php')) ?>" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="id" value="<?= (int) $year['id'] ?>">
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="return confirm('Make S.Y. <?= e($year['label']) ?> the active school year?')">Make active</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$years): ?>
                    <tr><td colspan="4" class="text-secondary">No school years yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Add a school year</h2>
                <?php if ($errors): ?><div class="alert alert-danger"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('admin/school-years.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <label class="form-label" for="label">School year</label>
                    <input class="form-control mb-3" id="label" name="label" value="<?= e($label) ?>" placeholder="<?= date('Y') ?>-<?= date('Y') + 1 ?>" pattern="\d{4}-\d{4}" required>
                    <button class="btn btn-primary">Add</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
