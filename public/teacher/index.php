<?php
// Teacher home: today's scheduled classes, my classes this school year with grading progress, and school news.
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$attendanceOn = Access::can('attendance');

$staff = require_staff();
$sy = active_school_year();

$classes = [];
$graded = [];
$published = [];
if ($sy) {
    $classes = db_all(
        'SELECT ca.id, ca.semester, sub.code AS subject_code, sub.name AS subject_name, sec.grade_level, sec.strand, sec.name AS section_name,
                (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = ca.section_id) AS learners
           FROM class_assignments ca
           JOIN subjects sub ON sub.id = ca.subject_id
           JOIN sections sec ON sec.id = ca.section_id
          WHERE ca.teacher_id = ? AND sec.school_year_id = ?
          ORDER BY ca.semester, sec.grade_level, sec.strand, sec.name, sub.sort_order, sub.name',
        [$staff['id'], $sy['id']]
    );
    // Graded = learners currently enrolled in the section who have a grade for that quarter.
    $rows = db_all(
        'SELECT g.class_assignment_id, g.quarter, COUNT(*) AS n
           FROM grades g
           JOIN class_assignments ca ON ca.id = g.class_assignment_id
           JOIN enrollments e        ON e.section_id = ca.section_id AND e.student_id = g.student_id
          WHERE ca.teacher_id = ?
          GROUP BY g.class_assignment_id, g.quarter',
        [$staff['id']]
    );
    foreach ($rows as $row) {
        $graded[(int) $row['class_assignment_id']][(int) $row['quarter']] = (int) $row['n'];
    }
    $rows = db_all(
        'SELECT gp.class_assignment_id, gp.quarter
           FROM grade_publications gp
           JOIN class_assignments ca ON ca.id = gp.class_assignment_id
          WHERE ca.teacher_id = ?',
        [$staff['id']]
    );
    foreach ($rows as $row) {
        $published[(int) $row['class_assignment_id']][(int) $row['quarter']] = true;
    }
}

$bySemester = [];
foreach ($classes as $class) {
    $bySemester[(int) $class['semester']][] = $class;
}

// Attendance already taken today, per class
$todayStatus = [];
foreach (db_all(
    'SELECT cs.class_assignment_id, cs.id, cs.finished_at FROM class_sessions cs
       JOIN class_assignments ca ON ca.id = cs.class_assignment_id
      WHERE ca.teacher_id = ? AND cs.session_date = ?',
    [$staff['id'], date('Y-m-d')]
) as $row) {
    $todayStatus[(int) $row['class_assignment_id']] = $row;
}
// Today's scheduled classes (current semester)
$todaySlots = $sy ? Schedule::onDay(Schedule::forTeacher($staff['id'], (int) $sy['id']), null, (int) setting('current_semester')) : [];

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$title = 'My classes';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3"><?= e($greeting) ?>, <?= e(explode(' ', $staff['full_name'])[0]) ?></h1>
        <p class="sub"><?= e(date('l, F j')) ?><?= $sy ? ' · S.Y. ' . e($sy['label']) : '' ?> · <?= count($classes) ?> class<?= count($classes) === 1 ? '' : 'es' ?></p>
    </div>
    <a class="btn btn-outline-primary" href="<?= e(url('teacher/schedule.php')) ?>"><i class="bi bi-calendar-week me-1"></i>My schedule</a>
</div>

<?php if ($todaySlots): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-clock me-2 text-primary"></i>Today's classes</h2>
            <div class="row g-2">
                <?php foreach ($todaySlots as $slot):
                    $session = $todayStatus[(int) $slot['class_assignment_id']] ?? null; ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="d-flex align-items-center gap-2 p-2 rounded border" style="border-left: 4px solid hsl(<?= color_hue($slot['subject_code']) ?> 62% 52%) !important">
                            <div class="flex-grow-1">
                                <div class="small text-secondary"><?= e(Schedule::time($slot['starts_at'])) ?> – <?= e(Schedule::time($slot['ends_at'])) ?><?= $slot['room'] ? ' · Room ' . e($slot['room']) : '' ?></div>
                                <div class="fw-semibold small"><?= e($slot['subject_name']) ?></div>
                                <div class="small text-secondary"><?= e(section_label($slot)) ?></div>
                            </div>
                            <?php if (!$attendanceOn): ?>
                            <?php elseif ($session && $session['finished_at'] !== null): ?>
                                <span class="badge text-bg-success"><i class="bi bi-check2-circle"></i>Done</span>
                            <?php elseif ($session): ?>
                                <a class="btn btn-sm btn-success" href="<?= e(url('teacher/session.php?id=' . $session['id'])) ?>">Continue</a>
                            <?php else: ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('teacher/attendance.php?id=' . $slot['class_assignment_id'])) ?>"><i class="bi bi-qr-code-scan"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$sy): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle-fill"></i><div>No school year is active yet. The registrar sets this under Admin → School years.</div></div>
<?php elseif (!$classes): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle-fill"></i><div>You have no classes assigned for S.Y. <?= e($sy['label']) ?>. The registrar assigns them under Admin → Sections.</div></div>
<?php else: ?>
    <?php foreach ($bySemester as $semester => $semesterClasses): ?>
        <h2 class="section-title mt-2"><?= e(semester_label($semester)) ?> · Quarters <?= implode(' and ', semester_quarters($semester)) ?></h2>
        <div class="row g-3 mb-4">
            <?php foreach ($semesterClasses as $class):
                $id = (int) $class['id'];
                $learners = (int) $class['learners'];
                $session = $todayStatus[$id] ?? null; ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card class-card lift h-100" style="--hue: <?= color_hue($class['subject_code']) ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="subject-chip"><?= e($class['subject_code']) ?></span>
                                <span class="small text-secondary"><i class="bi bi-people me-1"></i><?= $learners ?></span>
                            </div>
                            <h3 class="h6 mb-0"><?= e($class['subject_name']) ?></h3>
                            <div class="small text-secondary mb-3"><?= e(section_label($class)) ?></div>
                            <?php foreach (semester_quarters($semester) as $q):
                                $n = $graded[$id][$q] ?? 0;
                                $pct = $learners ? round($n / $learners * 100) : 0;
                                $isLocked = Grading::isLocked((int) $sy['id'], $q); ?>
                                <div class="d-flex align-items-center gap-2 mb-2 small">
                                    <span class="fw-semibold" style="width: 1.8rem">Q<?= $q ?></span>
                                    <div class="progress progress-thin flex-grow-1" role="progressbar" aria-label="Quarter <?= $q ?> graded" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar" style="width: <?= $pct ?>%"></div>
                                    </div>
                                    <span class="text-secondary text-nowrap" style="width: 2.8rem"><?= $n ?>/<?= $learners ?></span>
                                    <?php if ($isLocked): ?>
                                        <span class="badge text-bg-danger" title="Past the deadline"><i class="bi bi-lock-fill"></i>Locked</span>
                                    <?php elseif (isset($published[$id][$q])): ?>
                                        <span class="badge text-bg-success" title="Learners and parents can see these grades"><i class="bi bi-eye-fill"></i>Live</span>
                                    <?php elseif ($n > 0): ?>
                                        <span class="badge text-bg-secondary" title="Not published yet"><i class="bi bi-eye-slash"></i>Draft</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-light border">Empty</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                            <a class="btn btn-sm btn-primary" href="<?= e(url('teacher/class.php?id=' . $id)) ?>"><i class="bi bi-journal-check me-1"></i>Grades</a>
                            <?php if (!$attendanceOn): ?>
                            <?php elseif ($session && $session['finished_at'] === null): ?>
                                <a class="btn btn-sm btn-success" href="<?= e(url('teacher/session.php?id=' . $session['id'])) ?>"><i class="bi bi-qr-code-scan me-1"></i>Continue attendance</a>
                            <?php else: ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('teacher/attendance.php?id=' . $id)) ?>"><i class="bi bi-qr-code-scan me-1"></i>Attendance</a>
                            <?php endif; ?>
                            <?php if ($attendanceOn && $session && $session['finished_at'] !== null): ?>
                                <span class="small text-success ms-auto"><i class="bi bi-check2-circle"></i> Taken today</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <p class="small text-secondary"><i class="bi bi-info-circle me-1"></i>Bars show how many learners have a grade. Learners and parents only see a quarter after you publish it.</p>
<?php endif; ?>

<?php $newsViewer = 'staff'; require APP_ROOT . '/templates/news-widget.php'; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
