<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$sy = active_school_year();
$sectionOpts = $sy ? section_options((int) $sy['id']) : [];
$sectionIds = array_map('intval', array_column($sectionOpts, 'id'));

$form = ['lrn' => '', 'last_name' => '', 'first_name' => '', 'middle_name' => '', 'sex' => '', 'birthdate' => '', 'section_id' => 0];
$errors = [];
$importError = null;
$importProblems = [];

if (is_post()) {
    if (post('action') === 'add') {
        [$data, $errors] = validate_student_form();
        $sectionId = post_int('section_id');
        if ($sectionId && !in_array($sectionId, $sectionIds, true)) {
            $errors[] = 'Choose a section from the list.';
        }
        $form = $data + ['section_id' => $sectionId];
        if (!$errors) {
            db_exec(
                'INSERT INTO students (workspace_id, lrn, last_name, first_name, middle_name, sex, birthdate, qr_token, parent_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [ws(), $data['lrn'], $data['last_name'], $data['first_name'], $data['middle_name'] ?: null, $data['sex'] ?: null, $data['birthdate'], new_qr_token(), new_parent_code()]
            );
            if ($sectionId) {
                enroll_student((int) db()->lastInsertId(), $sectionId);
            }
            flash('success', 'Added ' . student_name($data) . '.');
            redirect('admin/students.php');
        }
    } elseif (post('action') === 'import') {
        try {
            $file = SheetReader::validateUpload($_FILES['file'] ?? null);
            $result = StudentImporter::import(SheetReader::read($file['path'], $file['ext']), $sy);
            if ($result['errors']) {
                $importProblems = $result['errors'];
            } else {
                $message = sprintf('Import finished: %d new, %d updated, %d unchanged.', $result['created'], $result['updated'], $result['unchanged']);
                if ($result['enrolled']) {
                    $message .= sprintf(' %d enrolled in sections for S.Y. %s.', $result['enrolled'], $sy['label']);
                }
                flash('success', $message);
                redirect('admin/students.php');
            }
        } catch (UserFacingException $e) {
            $importError = $e->getMessage();
        }
    }
}

$q = get_str('q');
$params = [$sy['id'] ?? 0, ws()];
$where = 'WHERE st.workspace_id = ?';
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= " AND (st.lrn LIKE ? OR st.last_name LIKE ? OR st.first_name LIKE ? OR CONCAT(st.first_name, ' ', st.last_name) LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}
$limit = 100;
$students = db_all(
    "SELECT st.*, sec.grade_level, sec.strand, sec.name AS section_name
       FROM students st
       LEFT JOIN enrollments e ON e.student_id = st.id AND e.school_year_id = ?
       LEFT JOIN sections sec  ON sec.id = e.section_id
       {$where}
      ORDER BY st.last_name, st.first_name
      LIMIT " . ($limit + 1),
    $params
);
$more = count($students) > $limit;
$students = array_slice($students, 0, $limit);

$title = 'Learners';
require APP_ROOT . '/templates/header.php';
?>
<h1 class="h3">Learners</h1>
<div class="row g-4">
    <div class="col-xl-8">
        <form method="get" action="<?= e(url('admin/students.php')) ?>" class="d-flex gap-2 mb-3" role="search">
            <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by LRN or name" aria-label="Search learners">
            <button class="btn btn-outline-primary">Search</button>
            <?php if ($q !== ''): ?><a class="btn btn-link" href="<?= e(url('admin/students.php')) ?>">Clear</a><?php endif; ?>
        </form>
        <div class="table-responsive">
            <table class="table table-sm bg-white shadow-sm align-middle">
                <thead class="table-light">
                <tr><th scope="col">LRN</th><th scope="col">Name</th><th scope="col">Sex</th><th scope="col">Birthdate</th><th scope="col">Section<?= $sy ? ' (' . e($sy['label']) . ')' : '' ?></th><th scope="col"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($students as $st): ?>
                    <tr>
                        <td class="lrn"><?= e($st['lrn']) ?></td>
                        <td><?= e(student_name($st)) ?></td>
                        <td><?= e($st['sex'] ?? '') ?></td>
                        <td class="small"><?= e($st['birthdate']) ?></td>
                        <td class="small"><?= $st['section_name'] ? e(section_label($st)) : '<span class="text-secondary">Not enrolled</span>' ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/student-edit.php?id=' . $st['id'])) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$students): ?>
                    <tr><td colspan="6" class="text-secondary"><?= $q !== '' ? 'No learners match your search.' : 'No learners yet. Add one, or import a list.' ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($more): ?><p class="small text-secondary">Showing the first <?= $limit ?>. Search to narrow the list.</p><?php endif; ?>
    </div>

    <div class="col-xl-4">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Import from Excel or CSV</h2>
                <p class="small text-secondary">
                    Add many learners at once and put them in sections.
                    <a href="<?= e(url('admin/student-template.php')) ?>">Download the import template</a>.
                </p>
                <?php if ($importError): ?><div class="alert alert-danger small"><?= e($importError) ?></div><?php endif; ?>
                <?php if ($importProblems): ?>
                    <div class="alert alert-danger small">
                        <strong>Nothing was imported.</strong> Fix these rows and upload again:
                        <ul class="mb-0 mt-1">
                            <?php foreach (array_slice($importProblems, 0, 50) as $p): ?>
                                <li>Row <?= (int) $p['line'] ?><?= $p['lrn'] !== '' ? ' (' . e($p['lrn']) . ')' : '' ?>: <?= e($p['message']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (count($importProblems) > 50): ?><div class="mt-1">…and <?= count($importProblems) - 50 ?> more.</div><?php endif; ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?= e(url('admin/students.php')) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="import">
                    <input class="form-control mb-2" type="file" name="file" accept=".xlsx,.xls,.csv" required aria-label="Learner list file">
                    <button class="btn btn-primary">Import</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Add a learner</h2>
                <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="post" action="<?= e(url('admin/students.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <?php require APP_ROOT . '/templates/student-fields.php'; ?>
                    <button class="btn btn-primary mt-3">Add learner</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
