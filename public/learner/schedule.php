<?php
// The learner's weekly class schedule.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$student = require_student();
$section = current_section_of((int) $student['id']);
$semester = get_int('sem') ?: (int) setting('current_semester');
$semester = $semester === 2 ? 2 : 1;
$slots = $section ? array_values(array_filter(Schedule::forSection((int) $section['id']), static fn ($s) => (int) $s['semester'] === $semester)) : [];

$title = 'My schedule';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">My schedule</h1>
        <p class="sub"><?= $section ? e(section_label($section)) : 'Not enrolled this school year' ?> · <?= e(semester_label($semester)) ?></p>
    </div>
    <div class="btn-group">
        <?php foreach (SEMESTERS as $sem): ?>
            <a class="btn btn-sm <?= $sem === $semester ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url('learner/schedule.php?sem=' . $sem)) ?>"><?= e(semester_label($sem)) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php
$showSection = false;
$showTeacher = true;
require APP_ROOT . '/templates/timetable.php';
?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
