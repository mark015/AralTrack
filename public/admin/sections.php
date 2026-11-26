<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$years = db_all('SELECT * FROM school_years WHERE workspace_id = ? ORDER BY label DESC', [ws()]);
$sy = null;
foreach ($years as $year) {
    if ((int) $year['id'] === get_int('sy') || (!get_int('sy') && $year['is_active'])) {
        $sy = $year;
    }
}
$sy ??= $years[0] ?? null;

$staffOptions = staff_options();
$errors = [];
$form = ['grade_level' => '', 'strand' => '', 'name' => '', 'adviser_id' => 0];

if (is_post()) {
    if (post('action') === 'add') {
        $year = db_one('SELECT * FROM school_years WHERE id = ? AND workspace_id = ?', [post_int('school_year_id'), ws()]);
        $form = ['grade_level' => post('grade_level'), 'strand' => post('strand'), 'name' => post('name'), 'adviser_id' => post_int('adviser_id')];
        if (mb_strlen($form['strand']) > 50) {
            $errors[] = 'The strand can be up to 50 characters.';
        }
        if (!$year) {
            $errors[] = 'Choose a school year.';
        }
        if (!in_array((int) $form['grade_level'], grade_level_options(), true)) {
            $errors[] = 'Choose a grade level.';
        }
        if ($form['name'] === '' || mb_strlen($form['name']) > 50) {
            $errors[] = 'Enter a section name (up to 50 characters).';
        }
        if ($form['adviser_id'] && !in_array($form['adviser_id'], array_map('intval', array_column($staffOptions, 'id')), true)) {
            $errors[] = 'Choose an adviser from the list.';
        }
        if (!$errors) {
            try {
                db_exec(
                    'INSERT INTO sections (workspace_id, school_year_id, grade_level, strand, name, adviser_id) VALUES (?, ?, ?, ?, ?, ?)',
                    [ws(), $year['id'], (int) $form['grade_level'], $form['strand'], $form['name'], $form['adviser_id'] ?: null]
                );
                $newId = (int) db()->lastInsertId();
                flash('success', section_label($form + ['section_name' => $form['name']]) . ' created. Now add its subjects and learners.');
                redirect('admin/section.php?id=' . $newId);
            } catch (PDOException $e) {
                if (!is_duplicate_error($e)) {
                    throw $e;
                }
                $errors[] = "Grade {$form['grade_level']} – {$form['name']} already exists in S.Y. {$year['label']}.";
            }
        }
    } elseif (post('action') === 'delete') {
        $section = db_one(
            'SELECT s.*, (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id) AS learners,
                    (SELECT COUNT(*) FROM class_assignments ca WHERE ca.section_id = s.id) AS classes
               FROM sections s WHERE s.id = ? AND s.workspace_id = ?',
            [post_int('id'), ws()]
        );
        if ($section && !$section['learners'] && !$section['classes']) {
            db_exec('DELETE FROM sections WHERE id = ?', [$section['id']]);
            flash('success', 'Section deleted.');
        } else {
            flash('danger', 'Only empty sections (no learners, no subjects) can be deleted.');
        }
        redirect('admin/sections.php' . ($sy ? '?sy=' . $sy['id'] : ''));
    }
}

$sections = $sy ? db_all(
    'SELECT s.*, s.name AS section_name, u.full_name AS adviser,
            (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id) AS learners,
            (SELECT COUNT(*) FROM class_assignments ca WHERE ca.section_id = s.id) AS classes
       FROM sections s
       LEFT JOIN users u ON u.id = s.adviser_id
      WHERE s.school_year_id = ? AND s.workspace_id = ?
      ORDER BY s.grade_level, s.strand, s.name',
    [$sy['id'], ws()]
) : [];

$title = 'Sections';
require APP_ROOT . '/templates/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Sections</h1>
        <div class="text-secondary">Open a section to assign subject teachers and enroll learners.</div>
    </div>
    <?php if (count($years) > 1): ?>
        <form method="get" action="<?= e(url('admin/sections.php')) ?>" class="d-flex gap-2 align-items-center">
            <label class="small text-secondary" for="sy">School year</label>
            <select class="form-select form-select-sm w-auto" id="sy" name="sy" onchange="this.form.submit()">
                <?php foreach ($years as $year): ?>
                    <option value="<?= (int) $year['id'] ?>" <?= $sy && $year['id'] === $sy['id'] ? 'selected' : '' ?>><?= e($year['label']) ?><?= $year['is_active'] ? ' (active)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if (!$sy): ?>
    <div class="alert alert-info">Add a school year first under <a href="<?= e(url('admin/school-years.php')) ?>">School years</a>.</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table bg-white shadow-sm align-middle">
                <thead class="table-light"><tr><th scope="col">Section</th><th scope="col">Adviser</th><th scope="col" class="text-center">Learners</th><th scope="col" class="text-center">Subjects</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php foreach ($sections as $s): ?>
                    <tr>
                        <td class="fw-semibold"><?= e(section_label($s)) ?></td>
                        <td><?= e($s['adviser'] ?? '—') ?></td>
                        <td class="text-center"><?= (int) $s['learners'] ?></td>
                        <td class="text-center"><?= (int) $s['classes'] ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-primary" href="<?= e(url('admin/section.php?id=' . $s['id'])) ?>">Manage</a>
                            <?php if (!$s['learners'] && !$s['classes']): ?>
                                <form method="post" action="<?= e(url('admin/sections.php?sy=' . $sy['id'])) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this empty section?')">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sections): ?><tr><td colspan="5" class="text-secondary">No sections in S.Y. <?= e($sy['label']) ?> yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Add a section</h2>
                <p class="small text-secondary">For S.Y. <?= e($sy['label']) ?></p>
                <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="post" action="<?= e(url('admin/sections.php?sy=' . $sy['id'])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="school_year_id" value="<?= (int) $sy['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label" for="grade_level">Grade level</label>
                        <select class="form-select" id="grade_level" name="grade_level" required>
                            <option value="">Choose…</option>
                            <?php foreach (grade_level_options() as $level): ?>
                                <option value="<?= $level ?>" <?= (string) $level === (string) $form['grade_level'] ? 'selected' : '' ?>>Grade <?= $level ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="strand">Track / strand</label>
                        <input class="form-control" id="strand" name="strand" value="<?= e($form['strand']) ?>" maxlength="50"
                               list="strand-list" placeholder="STEM" autocomplete="off">
                        <?= strand_datalist('strand-list') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="name">Section name</label>
                        <input class="form-control" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="50" placeholder="Einstein" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="adviser_id">Adviser <span class="small text-secondary">(optional)</span></label>
                        <select class="form-select" id="adviser_id" name="adviser_id">
                            <option value="0">—</option>
                            <?php foreach ($staffOptions as $opt): ?>
                                <option value="<?= (int) $opt['id'] ?>" <?= (int) $form['adviser_id'] === (int) $opt['id'] ? 'selected' : '' ?>><?= e($opt['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary">Add section</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
