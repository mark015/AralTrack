<?php
// My weekly teaching schedule.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$sy = active_school_year();
$slots = $sy ? Schedule::forTeacher($staff['id'], (int) $sy['id']) : [];
$semester = get_int('sem') === 2 ? 2 : (get_int('sem') === 1 ? 1 : null);
if ($semester) {
    $slots = array_values(array_filter($slots, static fn ($s) => (int) $s['semester'] === $semester));
}

$title = 'My schedule';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">My schedule</h1>
        <p class="sub"><?= $sy ? 'S.Y. ' . e($sy['label']) : '' ?> · Set each class's times from its <strong>Schedule</strong> tab</p>
    </div>
    <div class="btn-group">
        <a class="btn btn-sm <?= $semester === null ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url('teacher/schedule.php')) ?>">Both semesters</a>
        <a class="btn btn-sm <?= $semester === 1 ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url('teacher/schedule.php?sem=1')) ?>">1st</a>
        <a class="btn btn-sm <?= $semester === 2 ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url('teacher/schedule.php?sem=2')) ?>">2nd</a>
    </div>
</div>
<?php
$showSection = true;
$showTeacher = false;
require APP_ROOT . '/templates/timetable.php';
?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
