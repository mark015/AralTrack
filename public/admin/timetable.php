<?php
// A section's weekly timetable (admin).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin', 'principal', 'guidance');
$section = db_one('SELECT s.*, s.name AS section_name, sy.label AS sy_label FROM sections s JOIN school_years sy ON sy.id = s.school_year_id WHERE s.id = ?', [get_int('section')]);
if (!$section) {
    abort(404, 'That section does not exist.');
}
$slots = Schedule::forSection((int) $section['id']);
$classes = db_all(
    'SELECT ca.id, ca.semester, sub.name AS subject_name, u.full_name AS teacher_name,
            (SELECT COUNT(*) FROM class_schedules cs WHERE cs.class_assignment_id = ca.id) AS slots
       FROM class_assignments ca JOIN subjects sub ON sub.id = ca.subject_id JOIN users u ON u.id = ca.teacher_id
      WHERE ca.section_id = ? ORDER BY ca.semester, sub.sort_order',
    [$section['id']]
);

$title = 'Timetable · ' . section_label($section);
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= e(url('admin/section.php?id=' . $section['id'])) ?>"><?= e(section_label($section)) ?></a></li>
    <li class="breadcrumb-item active">Timetable</li></ol></nav>
<div class="page-head"><div><h1 class="h3">Timetable</h1><p class="sub"><?= e(section_label($section)) ?> · S.Y. <?= e($section['sy_label']) ?></p></div></div>
<?php
$showSection = false;
$showTeacher = true;
require APP_ROOT . '/templates/timetable.php';
?>
<h2 class="section-title mt-4">Set meeting times per subject</h2>
<div class="table-responsive">
    <table class="table align-middle shadow-sm">
        <thead><tr><th>Subject</th><th>Semester</th><th>Teacher</th><th class="text-center">Meetings / week</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($classes as $c): ?>
            <tr>
                <td><?= e($c['subject_name']) ?></td>
                <td class="small"><?= e(semester_label((int) $c['semester'])) ?></td>
                <td class="small"><?= e($c['teacher_name']) ?></td>
                <td class="text-center"><?= (int) $c['slots'] ?: '<span class="text-secondary">none</span>' ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('teacher/schedule-edit.php?id=' . $c['id'])) ?>"><i class="bi bi-clock me-1"></i>Edit times</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
