<?php
// One section: its details, which teacher handles each subject, and its learners.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$sectionId = get_int('id');
$section = db_one(
    'SELECT s.*, s.name AS section_name, sy.label AS sy_label
       FROM sections s JOIN school_years sy ON sy.id = s.school_year_id
      WHERE s.id = ? AND s.workspace_id = ?',
    [$sectionId, ws()]
);
if (!$section) {
    abort(404, 'That section does not exist.');
}
$here = 'admin/section.php?id=' . $sectionId;
$staffOptions = staff_options();
$staffIds = array_map('intval', array_column($staffOptions, 'id'));

/** The class row, if it belongs to this section. */
$findClass = static fn (int $classId): ?array => db_one(
    'SELECT ca.*, sub.name AS subject_name, (SELECT COUNT(*) FROM grades g WHERE g.class_assignment_id = ca.id) AS grade_count
       FROM class_assignments ca JOIN subjects sub ON sub.id = ca.subject_id
      WHERE ca.id = ? AND ca.section_id = ?',
    [$classId, $sectionId]
);

if (is_post()) {
    switch (post('action')) {
        case 'update':
            $level = (int) post('grade_level');
            $strand = post('strand');
            $name = post('name');
            $adviser = post_int('adviser_id');
            if (!in_array($level, grade_level_options(), true) || $name === '' || mb_strlen($name) > 50 || mb_strlen($strand) > 50
                || ($adviser && !in_array($adviser, $staffIds, true))) {
                flash('danger', 'Check the grade level, strand, section name and adviser.');
                break;
            }
            try {
                db_exec('UPDATE sections SET grade_level = ?, strand = ?, name = ?, adviser_id = ? WHERE id = ? AND workspace_id = ?', [$level, $strand, $name, $adviser ?: null, $sectionId, ws()]);
                flash('success', 'Section details saved.');
            } catch (PDOException $e) {
                if (!is_duplicate_error($e)) {
                    throw $e;
                }
                flash('danger', "Grade {$level} – {$name} already exists in this school year.");
            }
            break;

        case 'assign':
            $subjectId = post_int('subject_id');
            $teacherId = post_int('teacher_id');
            $semester = post_int('semester');
            if (!db_value('SELECT id FROM subjects WHERE id = ? AND workspace_id = ?', [$subjectId, ws()]) || !in_array($teacherId, $staffIds, true)
                || !in_array($semester, SEMESTERS, true)) {
                flash('danger', 'Choose a subject, a semester and a teacher.');
                break;
            }
            if ($limit = Billing::classLimitMessage()) {
                flash('warning', $limit);
                break;
            }
            try {
                db_exec('INSERT INTO class_assignments (workspace_id, section_id, subject_id, teacher_id, semester) VALUES (?, ?, ?, ?, ?)', [ws(), $sectionId, $subjectId, $teacherId, $semester]);
                flash('success', 'Subject added to this section.');
            } catch (PDOException $e) {
                if (!is_duplicate_error($e)) {
                    throw $e;
                }
                flash('danger', 'That subject is already in this section.');
            }
            break;

        case 'reassign':
            $class = $findClass(post_int('class_id'));
            $teacherId = post_int('teacher_id');
            $semester = post_int('semester');
            if (!$class || !in_array($teacherId, $staffIds, true) || !in_array($semester, SEMESTERS, true)) {
                flash('danger', 'Choose a teacher and a semester.');
                break;
            }
            if ($semester !== (int) $class['semester'] && (int) $class['grade_count'] > 0) {
                flash('danger', "{$class['subject_name']} already has grades for the " . semester_label((int) $class['semester']) . ', so its semester cannot be changed.');
                break;
            }
            db_exec('UPDATE class_assignments SET teacher_id = ?, semester = ? WHERE id = ?', [$teacherId, $semester, $class['id']]);
            flash('success', "{$class['subject_name']} saved. Grades already entered stay.");
            break;

        case 'unassign':
            $class = $findClass(post_int('class_id'));
            if ($class && (int) $class['grade_count'] === 0) {
                db_exec('DELETE FROM class_assignments WHERE id = ?', [$class['id']]);
                flash('success', "{$class['subject_name']} removed from this section.");
            } elseif ($class) {
                flash('danger', "{$class['subject_name']} already has grades, so it cannot be removed. Change the teacher instead.");
            }
            break;

        case 'enroll':
            $lrns = array_unique(array_filter(array_map('normalize_lrn', preg_split('/[\s,;]+/', post('lrns')) ?: [])));
            $added = [];
            $problems = [];
            foreach ($lrns as $lrn) {
                if (!is_valid_lrn($lrn)) {
                    $problems[] = "{$lrn} (not a 12-digit LRN)";
                    continue;
                }
                $student = db_one('SELECT * FROM students WHERE lrn = ? AND workspace_id = ?', [$lrn, ws()]);
                if (!$student) {
                    $problems[] = "{$lrn} (no learner with this LRN — add them under Learners first)";
                    continue;
                }
                $current = db_one(
                    'SELECT s.id, s.grade_level, s.strand, s.name AS section_name FROM enrollments e JOIN sections s ON s.id = e.section_id
                      WHERE e.student_id = ? AND e.school_year_id = ?',
                    [$student['id'], $section['school_year_id']]
                );
                if ($current && (int) $current['id'] === $sectionId) {
                    continue; // already here
                }
                if ($current) {
                    $problems[] = "{$lrn} (already in " . section_label($current) . '; change it from the learner\'s page)';
                    continue;
                }
                enroll_student((int) $student['id'], $sectionId);
                $added[] = student_name($student);
            }
            if ($added) {
                flash('success', 'Enrolled: ' . implode('; ', $added) . '.');
            }
            if ($problems) {
                flash('danger', 'Not enrolled: ' . implode(', ', $problems) . '.');
            }
            if (!$added && !$problems) {
                flash('info', 'No new learners to enroll.');
            }
            break;

        case 'unenroll':
            $removed = db_exec('DELETE FROM enrollments WHERE student_id = ? AND section_id = ?', [post_int('student_id'), $sectionId]);
            flash($removed ? 'success' : 'warning', $removed ? 'Learner removed from this section.' : 'That learner was not in this section.');
            break;
    }
    redirect($here);
}

$classes = db_all(
    'SELECT ca.id, ca.teacher_id, ca.semester, sub.code, sub.name AS subject_name, sub.type,
            (SELECT COUNT(*) FROM grades g WHERE g.class_assignment_id = ca.id) AS grade_count
       FROM class_assignments ca JOIN subjects sub ON sub.id = ca.subject_id
      WHERE ca.section_id = ?
      ORDER BY ca.semester, sub.type = \'core\' DESC, sub.sort_order, sub.name',
    [$sectionId]
);
$freeSubjects = db_all(
    'SELECT * FROM subjects WHERE workspace_id = ? AND id NOT IN (SELECT subject_id FROM class_assignments WHERE section_id = ?)
      ORDER BY type = \'core\' DESC, sort_order, name',
    [ws(), $sectionId]
);
$semesterCount = array_fill_keys(SEMESTERS, 0);
foreach ($classes as $c) {
    $semesterCount[(int) $c['semester']]++;
}

$semesterSelect = static function (int $selected, string $id): string {
    $html = '<select class="form-select form-select-sm" name="semester" id="' . e($id) . '" required>';
    foreach (SEMESTERS as $sem) {
        $html .= '<option value="' . $sem . '"' . ($sem === $selected ? ' selected' : '') . '>' . e(semester_label($sem)) . '</option>';
    }
    return $html . '</select>';
};
$roster = section_roster($sectionId);

$staffSelect = static function (string $name, int $selected, string $id) use ($staffOptions): string {
    $html = '<select class="form-select form-select-sm" name="' . e($name) . '" id="' . e($id) . '" required>';
    foreach ($staffOptions as $opt) {
        $html .= '<option value="' . (int) $opt['id'] . '"' . ((int) $opt['id'] === $selected ? ' selected' : '') . '>' . e($opt['full_name']) . '</option>';
    }
    return $html . '</select>';
};

$title = section_label($section);
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('admin/sections.php?sy=' . $section['school_year_id'])) ?>">Sections</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e(section_label($section)) ?></li>
    </ol>
</nav>
<h1 class="h3 mb-0"><?= e(section_label($section)) ?></h1>
<p class="text-secondary">
    S.Y. <?= e($section['sy_label']) ?> · <?= count($roster) ?> learners ·
    <?= $semesterCount[1] ?> subjects in the 1st semester, <?= $semesterCount[2] ?> in the 2nd
</p>

<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Subjects, semesters and teachers</h2>
                <p class="small text-secondary">
                    1st semester subjects are graded in Quarters 1–2, 2nd semester subjects in Quarters 3–4.
                    Each teacher can upload grades only for the subjects assigned to them here.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th scope="col">Subject</th><th scope="col">Semester and teacher</th><th scope="col" class="text-center">Grades</th><th scope="col"></th></tr></thead>
                        <tbody>
                        <?php foreach ($classes as $c): ?>
                            <tr>
                                <td>
                                    <?= e($c['subject_name']) ?>
                                    <div class="small text-secondary"><?= e(SUBJECT_TYPES[$c['type']] ?? '') ?></div>
                                </td>
                                <td>
                                    <form method="post" action="<?= e(url($here)) ?>" class="d-flex flex-wrap flex-md-nowrap gap-1 m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reassign">
                                        <input type="hidden" name="class_id" value="<?= (int) $c['id'] ?>">
                                        <?= $semesterSelect((int) $c['semester'], 'semester-' . $c['id']) ?>
                                        <?= $staffSelect('teacher_id', (int) $c['teacher_id'], 'teacher-' . $c['id']) ?>
                                        <button class="btn btn-sm btn-outline-primary">Save</button>
                                    </form>
                                </td>
                                <td class="text-center"><?= (int) $c['grade_count'] ?></td>
                                <td class="text-end">
                                    <?php if (!$c['grade_count']): ?>
                                        <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="unassign">
                                            <input type="hidden" name="class_id" value="<?= (int) $c['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this subject from the section?')">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$classes): ?><tr><td colspan="4" class="text-secondary">No subjects yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($freeSubjects && $staffOptions): ?>
                    <form method="post" action="<?= e(url($here)) ?>" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="assign">
                        <div class="col-md-5">
                            <label class="form-label small" for="subject_id">Add subject</label>
                            <select class="form-select form-select-sm" id="subject_id" name="subject_id" required>
                                <?php foreach ($freeSubjects as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="new-semester">Semester</label>
                            <?= $semesterSelect(1, 'new-semester') ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="new-teacher">Teacher</label>
                            <?= $staffSelect('teacher_id', 0, 'new-teacher') ?>
                        </div>
                        <div class="col-md-1 d-grid"><button class="btn btn-sm btn-primary">Add</button></div>
                    </form>
                <?php elseif (!$freeSubjects): ?>
                    <p class="small text-secondary mb-0">Every subject is assigned. Add more under <a href="<?= e(url('admin/subjects.php')) ?>">Subjects</a>.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Section details</h2>
                <form method="post" action="<?= e(url($here)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <div class="mb-2">
                        <label class="form-label small" for="grade_level">Grade level</label>
                        <select class="form-select form-select-sm" id="grade_level" name="grade_level">
                            <?php foreach (grade_level_options() as $level): ?>
                                <option value="<?= $level ?>" <?= $level === (int) $section['grade_level'] ? 'selected' : '' ?>>Grade <?= $level ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small" for="strand">Track / strand</label>
                        <input class="form-control form-control-sm" id="strand" name="strand" value="<?= e($section['strand']) ?>"
                               maxlength="50" list="strand-list" autocomplete="off">
                        <?= strand_datalist('strand-list') ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small" for="name">Section name</label>
                        <input class="form-control form-control-sm" id="name" name="name" value="<?= e($section['name']) ?>" maxlength="50" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small" for="adviser_id">Adviser</label>
                        <select class="form-select form-select-sm" id="adviser_id" name="adviser_id">
                            <option value="0">—</option>
                            <?php foreach ($staffOptions as $opt): ?>
                                <option value="<?= (int) $opt['id'] ?>" <?= (int) $opt['id'] === (int) $section['adviser_id'] ? 'selected' : '' ?>><?= e($opt['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-sm btn-primary">Save details</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h2 class="h5 mb-0">Learners (<?= count($roster) ?>)</h2>
                    <?php if ($roster): ?>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/print.php?type=cards&section=' . $sectionId)) ?>" target="_blank">Print QR ID cards</a>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/print.php?type=slips&section=' . $sectionId)) ?>" target="_blank">Print parent link slips</a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th scope="col">#</th><th scope="col">LRN</th><th scope="col">Name</th><th scope="col">Sex</th><th scope="col"></th></tr></thead>
                        <tbody>
                        <?php foreach ($roster as $i => $st): ?>
                            <tr>
                                <td class="text-secondary small"><?= $i + 1 ?></td>
                                <td class="lrn"><?= e($st['lrn']) ?></td>
                                <td><a href="<?= e(url('admin/student-edit.php?id=' . $st['id'])) ?>"><?= e(student_name($st)) ?></a></td>
                                <td><?= e($st['sex'] ?? '') ?></td>
                                <td class="text-end">
                                    <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="unenroll">
                                        <input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Remove this learner from the section? Grades already entered will no longer show on their report card.')">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$roster): ?><tr><td colspan="5" class="text-secondary">No learners yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-4">
                <h2 class="h6">Enroll learners</h2>
                <form method="post" action="<?= e(url($here)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enroll">
                    <label class="form-label small" for="lrns">LRNs, one per line</label>
                    <textarea class="form-control lrn mb-2" id="lrns" name="lrns" rows="6" placeholder="123456789012&#10;123456789013" required></textarea>
                    <button class="btn btn-sm btn-primary">Enroll</button>
                </form>
                <p class="small text-secondary mt-3 mb-0">
                    Learners must already exist under <a href="<?= e(url('admin/students.php')) ?>">Learners</a>.
                    To add many at once with their sections, use the Excel import there.
                </p>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
