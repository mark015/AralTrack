<?php
/**
 * Heading and Grades/Attendance tabs for a teacher's class page.
 * @var array $class
 * @var string $activeTab 'grades' or 'attendance'
 * @var int $learnerCount
 */
$classId = (int) $class['id'];
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('teacher/index.php')) ?>">My classes</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($class['subject_name']) ?></li>
    </ol>
</nav>
<div class="d-flex align-items-center gap-2 mb-1">
    <span class="subject-chip" style="--hue: <?= color_hue($class['subject_code']) ?>"><?= e($class['subject_code']) ?></span>
    <span class="small text-secondary"><?= e(SUBJECT_TYPES[$class['subject_type'] ?? ''] ?? '') ?></span>
</div>
<h1 class="h3 mb-0"><?= e($class['subject_name']) ?></h1>
<p class="text-secondary">
    <?= e(section_label($class)) ?> · <?= e(semester_label((int) $class['semester'])) ?> · S.Y. <?= e($class['sy_label']) ?>
    · <?= $learnerCount ?> learner<?= $learnerCount === 1 ? '' : 's' ?>
</p>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link<?= $activeTab === 'grades' ? ' active" aria-current="page' : '' ?>" href="<?= e(url('teacher/class.php?id=' . $classId)) ?>"><i class="bi bi-journal-check"></i>Grades</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $activeTab === 'attendance' ? ' active" aria-current="page' : '' ?>" href="<?= e(url('teacher/attendance.php?id=' . $classId)) ?>"><i class="bi bi-qr-code-scan"></i>Attendance</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $activeTab === 'posts' ? ' active" aria-current="page' : '' ?>" href="<?= e(url('teacher/posts.php?id=' . $classId)) ?>"><i class="bi bi-folder2-open"></i>Materials</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $activeTab === 'schedule' ? ' active" aria-current="page' : '' ?>" href="<?= e(url('teacher/schedule-edit.php?id=' . $classId)) ?>"><i class="bi bi-clock"></i>Schedule</a>
    </li>
</ul>
