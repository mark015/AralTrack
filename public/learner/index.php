<?php
// Learner home: today's classes (with attendance), this month's attendance, announcements and the calendar.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$student = require_student();
$studentId = (int) $student['id'];
$section = current_section_of($studentId);
$semester = (int) setting('current_semester');
$attendanceOn = Access::can('attendance');

$today = [];
$month = array_fill_keys(array_keys(ATTENDANCE_STATUSES), 0);
if ($section) {
    $marks = [];
    foreach (Attendance::studentSessions($studentId, (int) $section['id'], date('Y-m-d'), date('Y-m-d')) as $s) {
        $marks[$s['subject_name']] = $s;
    }
    foreach (Schedule::onDay(Schedule::forSection((int) $section['id']), null, $semester) as $slot) {
        $today[] = $slot + ['mark' => $marks[$slot['subject_name']] ?? null];
    }
    foreach (db_all(
        'SELECT sa.status, COUNT(*) AS n FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
           JOIN class_assignments ca ON ca.id = cs.class_assignment_id
          WHERE sa.student_id = ? AND ca.section_id = ? AND cs.session_date >= ? AND cs.finished_at IS NOT NULL GROUP BY sa.status',
        [$studentId, $section['id'], date('Y-m-01')]
    ) as $row) {
        $month[$row['status']] = (int) $row['n'];
    }
}
$marked = array_sum($month);
$rate = $marked ? (int) round(($month['present'] + $month['late']) / $marked * 100) : null;

$title = 'Home';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div class="d-flex align-items-center gap-3">
        <?= avatar($student['first_name'] . ' ' . $student['last_name'], 'avatar-lg') ?>
        <div>
            <h1 class="h3">Hi, <?= e($student['first_name']) ?>!</h1>
            <p class="sub"><?= $section ? e(section_label($section)) . ' · S.Y. ' . e($section['sy_label']) : 'Not enrolled this school year' ?> · <?= e(date('l, F j')) ?></p>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (Access::can('grades')): ?><a class="btn btn-primary" href="<?= e(url('grades.php')) ?>"><i class="bi bi-journal-text me-1"></i>Report card</a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= e(url('learner/schedule.php')) ?>"><i class="bi bi-calendar-week me-1"></i>My schedule</a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6 mb-3"><i class="bi bi-clock me-2 text-primary"></i>Today's classes · <?= e(semester_label($semester)) ?></h2>
                <?php if (!$today): ?>
                    <p class="small text-secondary mb-0"><i class="bi bi-calendar-x me-1"></i>No classes scheduled today.</p>
                <?php else: ?>
                    <ul class="timeline">
                        <?php foreach ($today as $slot): $mark = $slot['mark']; ?>
                            <li class="<?= e($mark['status'] ?? 'pending') ?>">
                                <span><span class="text-secondary"><?= e(Schedule::time($slot['starts_at'])) ?></span> · <?= e($slot['subject_name']) ?>
                                    <span class="small text-secondary"><?= $slot['room'] ? '· Room ' . e($slot['room']) : '' ?></span></span>
                                <?php if ($attendanceOn): ?><span><?= $mark && $mark['status'] ? attendance_badge($mark['status']) : '<span class="small text-secondary">—</span>' ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($attendanceOn): ?>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-4">
                <div class="ring" style="--pct: <?= (int) $rate ?>; --ring: <?= $rate === null ? '#c3cad9' : ($rate >= 90 ? '#14946b' : ($rate >= 80 ? '#e0a000' : '#d9364a')) ?>">
                    <span><?= $rate === null ? '–' : $rate . '%' ?><small><?= e(date('M')) ?></small></span>
                </div>
                <div>
                    <h2 class="h6">My attendance this month</h2>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach (ATTENDANCE_STATUSES as $status => $label): ?>
                            <?= str_replace('</span>', ' ' . $month[$status] . '</span>', attendance_badge($status)) ?>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">Counted per class meeting.</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php $newsViewer = 'families'; require APP_ROOT . '/templates/news-widget.php'; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
