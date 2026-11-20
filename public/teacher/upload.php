<?php
// Upload a grade sheet → preview every row → confirm to save.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$class = teacher_class_or_abort(get_int('id'), $staff);
if (!Access::gradeEncodingOpen()) {
    flash('warning', 'Grade encoding is turned off by the school right now, so grades are read-only.');
    redirect('teacher/class.php?id=' . $class['id']);
}
$classId = (int) $class['id'];
$quarter = class_quarter_or_abort($class, get_int('q'));
if (Grading::isLocked((int) $class['school_year_id'], $quarter)) {
    flash('warning', "Quarter {$quarter} is locked because its deadline has passed. Send a grade change request instead.");
    redirect('teacher/class.php?id=' . $class['id'] . '#change-requests');
}
$here = "teacher/upload.php?id={$classId}&q={$quarter}";
$classPage = 'teacher/class.php?id=' . $classId;
$roster = section_roster((int) $class['section_id']);
$error = null;

/** The preview saved in the session, if it belongs to this class, quarter and token. */
$loadPreview = static function (string $token) use ($classId, $quarter): ?array {
    $import = $_SESSION['grade_import'] ?? null;
    $valid = is_array($import)
        && $token !== ''
        && hash_equals($import['token'], $token)
        && $import['class_id'] === $classId
        && $import['quarter'] === $quarter
        && time() - $import['created'] < 1800;
    return $valid ? $import : null;
};

if (is_post()) {
    $action = post('action');

    if ($action === 'preview') {
        try {
            $file = SheetReader::validateUpload($_FILES['file'] ?? null);
            $sheetRows = SheetReader::read($file['path'], $file['ext']);

            $byLrn = [];
            foreach ($roster as $student) {
                $byLrn[$student['lrn']] = ['id' => (int) $student['id'], 'name' => student_name($student)];
            }
            $existing = [];
            foreach (class_grades($classId) as $studentId => $byQuarter) {
                if (isset($byQuarter[$quarter])) {
                    $existing[$studentId] = $byQuarter[$quarter];
                }
            }
            $result = GradeImporter::analyze($sheetRows, $byLrn, $existing);
            if (!$result['rows'] && !$result['errors']) {
                throw new UserFacingException('No grades were found under the header row. Type the grades in the Grade column and upload again.');
            }

            $token = bin2hex(random_bytes(16));
            $_SESSION['grade_import'] = [
                'token'    => $token,
                'class_id' => $classId,
                'quarter'  => $quarter,
                'filename' => $file['name'],
                'created'  => time(),
            ] + $result;
            redirect($here . '&preview=' . $token);
        } catch (UserFacingException $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'confirm') {
        $import = $loadPreview(post('token'));
        if (!$import) {
            flash('warning', 'That preview has expired. Please upload the file again.');
            redirect($here);
        }
        // Re-check enrollment in case the class list changed after the preview.
        $enrolled = array_flip(array_map('intval', array_column($roster, 'id')));
        $changes = [];
        foreach ($import['rows'] as $row) {
            if ($row['status'] !== 'same' && isset($enrolled[$row['student_id']])) {
                $changes[] = [$row['student_id'], $quarter, (float) $row['grade']];
            }
        }
        if ($changes) {
            save_grade_changes($classId, $changes, $staff['id'], 'upload');
        }
        db_exec(
            'INSERT INTO upload_log (user_id, class_assignment_id, quarter, original_filename, rows_saved, rows_rejected) VALUES (?, ?, ?, ?, ?, ?)',
            [$staff['id'], $classId, $quarter, $import['filename'], count($changes), count($import['errors'])]
        );
        unset($_SESSION['grade_import']);

        $message = count($changes) . ' grade' . (count($changes) === 1 ? '' : 's') . " saved for Quarter {$quarter}.";
        if (isset(class_publications($classId)[$quarter])) {
            $message .= ' This quarter is published, so learners can already see the new grades.';
        }
        flash('success', $message);
        redirect($classPage);
    } elseif ($action === 'cancel') {
        unset($_SESSION['grade_import']);
        flash('info', 'Upload cancelled. Nothing was saved.');
        redirect($classPage);
    }
}

$preview = $loadPreview(get_str('preview'));

$title = 'Upload Quarter ' . $quarter . ' grades';
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('teacher/index.php')) ?>">My classes</a></li>
        <li class="breadcrumb-item"><a href="<?= e(url($classPage)) ?>"><?= e($class['subject_name']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page">Upload Q<?= $quarter ?></li>
    </ol>
</nav>
<h1 class="h3 mb-0">Upload Quarter <?= $quarter ?> grades</h1>
<p class="text-secondary"><?= e($class['subject_name']) ?> · <?= e(section_label($class)) ?> · <?= e(semester_label((int) $class['semester'])) ?> · S.Y. <?= e($class['sy_label']) ?></p>

<?php if (!$preview): ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <ol class="mb-4">
                        <li class="mb-2">
                            <a href="<?= e(url("teacher/template.php?id={$classId}&q={$quarter}")) ?>">Download the Quarter <?= $quarter ?> template</a>.
                            It already lists every learner in this section.
                        </li>
                        <li class="mb-2">Type each learner's grade in the <strong>Grade</strong> column. Leave it blank if there is no grade yet.</li>
                        <li>Save the file and upload it below. You will see a preview before anything is saved.</li>
                    </ol>
                    <form method="post" action="<?= e(url($here)) ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="preview">
                        <label for="file" class="form-label">Grade file</label>
                        <input type="file" class="form-control mb-3" id="file" name="file" accept=".xlsx,.xls,.csv" required>
                        <button class="btn btn-primary">Upload and preview</button>
                        <a class="btn btn-link" href="<?= e(url($classPage)) ?>">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card bg-light border-0">
                <div class="card-body small">
                    <h2 class="h6">What the file needs</h2>
                    <ul class="mb-0">
                        <li>An Excel (.xlsx, .xls) or CSV file, up to <?= e(max_upload_label()) ?>.</li>
                        <li>A column named <strong>LRN</strong> and a column named <strong>Grade</strong>. Other columns are ignored.</li>
                        <li>Grades from <?= e(config('grade_min')) ?> to <?= e(config('grade_max')) ?>. Up to 2 decimal places.</li>
                        <li>Rows are matched by LRN, so their order doesn't matter.</li>
                        <li>Uploading again replaces the Quarter <?= $quarter ?> grades in the file. Learners not in the file keep their grades.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php else:
    $counts = ['new' => 0, 'changed' => 0, 'same' => 0];
    foreach ($preview['rows'] as $row) {
        $counts[$row['status']]++;
    }
    $toSave = $counts['new'] + $counts['changed'];
    $inFile = array_flip(array_column($preview['rows'], 'student_id'));
    $currentGrades = class_grades($classId);
    $stillMissing = array_filter(
        $roster,
        static fn ($s) => !isset($inFile[(int) $s['id']]) && !isset($currentGrades[(int) $s['id']][$quarter])
    );
    ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <p class="mb-2">Preview of <strong><?= e($preview['filename']) ?></strong>. <strong>Nothing has been saved yet.</strong></p>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge text-bg-primary fs-6 fw-normal"><?= $counts['new'] ?> new</span>
                <span class="badge text-bg-warning fs-6 fw-normal"><?= $counts['changed'] ?> changed</span>
                <span class="badge text-bg-light border fs-6 fw-normal"><?= $counts['same'] ?> unchanged</span>
                <span class="badge text-bg-danger fs-6 fw-normal"><?= count($preview['errors']) ?> with problems</span>
                <?php if ($preview['skipped']): ?>
                    <span class="badge text-bg-light border fs-6 fw-normal"><?= (int) $preview['skipped'] ?> blank grade<?= $preview['skipped'] === 1 ? '' : 's' ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($preview['errors']): ?>
        <h2 class="h5 text-danger">Rows that will not be saved</h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm bg-white shadow-sm align-middle">
                <thead class="table-light"><tr><th scope="col">Row</th><th scope="col">LRN</th><th scope="col">Problem</th></tr></thead>
                <tbody>
                <?php foreach ($preview['errors'] as $err): ?>
                    <tr>
                        <td><?= (int) $err['line'] ?></td>
                        <td class="lrn"><?= e($err['lrn']) ?></td>
                        <td><?= e($err['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($preview['rows']): ?>
        <h2 class="h5">Grades in this file</h2>
        <div class="table-responsive mb-3">
            <table class="table table-sm bg-white shadow-sm align-middle">
                <thead class="table-light">
                <tr><th scope="col">Row</th><th scope="col">LRN</th><th scope="col">Learner</th><th scope="col" class="text-center">Current</th><th scope="col" class="text-center">New</th><th scope="col"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($preview['rows'] as $row): ?>
                    <tr class="<?= $row['status'] === 'changed' ? 'table-warning' : '' ?>">
                        <td><?= (int) $row['line'] ?></td>
                        <td class="lrn"><?= e($row['lrn']) ?></td>
                        <td><?= e($row['name']) ?></td>
                        <td class="text-center text-secondary"><?= $row['old'] === null ? '–' : e(format_grade($row['old'])) ?></td>
                        <td class="text-center fw-semibold"><?= e(format_grade($row['grade'])) ?></td>
                        <td class="small">
                            <?= match ($row['status']) {
                                'new' => '<span class="badge text-bg-primary">New</span>',
                                'changed' => '<span class="badge text-bg-warning">Changed</span>',
                                default => '<span class="text-secondary">No change</span>',
                            } ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($stillMissing): ?>
        <div class="alert alert-light border small">
            <strong><?= count($stillMissing) ?> learner<?= count($stillMissing) === 1 ? '' : 's' ?> will still have no Quarter <?= $quarter ?> grade:</strong>
            <?= e(implode('; ', array_map('student_name', $stillMissing))) ?>
        </div>
    <?php endif; ?>

    <div class="sticky-actions d-flex flex-wrap gap-2 align-items-center">
        <form method="post" action="<?= e(url($here)) ?>" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="token" value="<?= e($preview['token']) ?>">
            <button class="btn btn-success" <?= $toSave === 0 ? 'disabled' : '' ?>>
                Save <?= $toSave ?> grade<?= $toSave === 1 ? '' : 's' ?>
            </button>
        </form>
        <form method="post" action="<?= e(url($here)) ?>" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <button class="btn btn-outline-secondary">Cancel</button>
        </form>
        <a class="btn btn-link" href="<?= e(url($here)) ?>">Upload a different file</a>
        <?php if ($preview['errors'] && $toSave > 0): ?>
            <span class="small text-secondary">Rows with problems are skipped. You can fix them and upload again later.</span>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
