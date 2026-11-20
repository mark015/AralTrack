<?php
// Attendance for one class: start today's session, see past sessions and each learner's totals.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$class = teacher_class_or_abort(get_int('id'), $staff);
Access::requireModule('attendance');
$classId = (int) $class['id'];
$here = 'teacher/attendance.php?id=' . $classId;

if (is_post() && post('action') === 'start') {
    $time = post('starts_at');
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
        flash('danger', 'Enter the time the class started, for example 09:00.');
        redirect($here);
    }
    redirect('teacher/session.php?id=' . Attendance::start($class, $time, $staff['id']));
}

$roster = section_roster((int) $class['section_id']);
$open = Attendance::openSessionFor($classId);
$sessions = db_all(
    "SELECT cs.*, SUM(sa.status = 'present') AS present, SUM(sa.status = 'late') AS late,
            SUM(sa.status = 'absent') AS absent, SUM(sa.status = 'excused') AS excused
       FROM class_sessions cs
       LEFT JOIN session_attendance sa ON sa.session_id = cs.id
      WHERE cs.class_assignment_id = ?
      GROUP BY cs.id
      ORDER BY cs.starts_at DESC
      LIMIT 200",
    [$classId]
);
$held = count(array_filter($sessions, static fn ($s) => $s['finished_at'] !== null));

// Default start time: today's scheduled meeting closest to now, else the current time.
$defaultStart = date('H:i');
$todaySlots = Schedule::onDay(Schedule::forClass($classId));
if ($todaySlots) {
    usort($todaySlots, static fn ($a, $b) => abs(strtotime($a['starts_at']) - time()) <=> abs(strtotime($b['starts_at']) - time()));
    $defaultStart = substr($todaySlots[0]['starts_at'], 0, 5);
}
$noClass = News::noClassEvent(date('Y-m-d'));
$totals = Attendance::classTotals($classId);
$months = db_all(
    "SELECT DISTINCT DATE_FORMAT(session_date, '%Y-%m') AS month FROM class_sessions WHERE class_assignment_id = ? ORDER BY month DESC",
    [$classId]
);

$title = 'Attendance · ' . $class['subject_name'];
require APP_ROOT . '/templates/header.php';
$activeTab = 'attendance';
$learnerCount = count($roster);
require APP_ROOT . '/templates/class-tabs.php';
?>

<?php if (!$roster): ?>
    <div class="alert alert-info">No learners are enrolled in this section yet.</div>
<?php elseif ($open): ?>
    <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Attendance is in progress for today's class (started <?= e(format_time($open['starts_at'])) ?>).</span>
        <a class="btn btn-success" href="<?= e(url('teacher/session.php?id=' . $open['id'])) ?>">Continue taking attendance</a>
    </div>
<?php else: ?>
    <?php if ($noClass): ?>
        <div class="alert alert-warning"><i class="bi bi-<?= e(News::EVENT_TYPES[$noClass['type']][2]) ?>"></i>
            <div>Today is on the school calendar as <strong><?= e(News::EVENT_TYPES[$noClass['type']][0]) ?>: <?= e($noClass['title']) ?></strong>. Only take attendance if your class is really meeting.</div></div>
    <?php endif; ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5">Take attendance</h2>
            <?php if ($todaySlots): ?><p class="small text-secondary mb-2"><i class="bi bi-clock me-1"></i>Scheduled today: <?= e(implode(', ', array_map(static fn ($s) => Schedule::time($s['starts_at']) . ($s['room'] ? ' (Room ' . $s['room'] . ')' : ''), Schedule::onDay(Schedule::forClass($classId))))) ?></p><?php endif; ?>
            <form method="post" action="<?= e(url($here)) ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="start">
                <div class="col-auto">
                    <label class="form-label small" for="starts_at">Class started at (today, <?= e(date('M j')) ?>)</label>
                    <input class="form-control" type="time" id="starts_at" name="starts_at" value="<?= e($defaultStart) ?>" required>
                </div>
                <div class="col-auto"><button class="btn btn-primary">Start</button></div>
            </form>
            <p class="small text-secondary mt-2 mb-0">
                Learners scanned more than <?= (int) setting('late_after_minutes') ?> minutes after this time are marked Late.
                When you press Finish, learners not marked yet are marked Absent and their parents are notified.
            </p>
        </div>
    </div>
<?php endif; ?>

<?php if ($roster): ?>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">
        <h2 class="h5 mb-0">Learner totals <span class="text-secondary fw-normal small">(<?= $held ?> finished class<?= $held === 1 ? '' : 'es' ?>)</span></h2>
        <?php if ($months): ?>
            <form method="get" action="<?= e(url('teacher/attendance-export.php')) ?>" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="id" value="<?= $classId ?>">
                <label class="small text-secondary" for="month">Excel sheet for</label>
                <select class="form-select form-select-sm w-auto" id="month" name="month">
                    <?php foreach ($months as $m): ?>
                        <option value="<?= e($m['month']) ?>"><?= e(date('F Y', strtotime($m['month'] . '-01'))) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-primary">Download</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-hover align-middle bg-white shadow-sm">
            <thead class="table-light">
            <tr><th scope="col">#</th><th scope="col">LRN</th><th scope="col">Learner</th>
                <?php foreach (ATTENDANCE_STATUSES as $label): ?><th scope="col" class="text-center"><?= e($label) ?></th><?php endforeach; ?>
                <th scope="col" class="text-center">Attended</th></tr>
            </thead>
            <tbody>
            <?php foreach ($roster as $i => $student):
                $t = $totals[(int) $student['id']] ?? [];
                $attended = ($t['present'] ?? 0) + ($t['late'] ?? 0);
                $absent = $t['absent'] ?? 0; ?>
                <tr class="<?= $absent >= 3 ? 'table-danger' : '' ?>">
                    <td class="text-secondary small"><?= $i + 1 ?></td>
                    <td class="lrn"><?= e($student['lrn']) ?></td>
                    <td><?= e(student_name($student)) ?></td>
                    <?php foreach (array_keys(ATTENDANCE_STATUSES) as $status): ?>
                        <td class="text-center"><?= (int) ($t[$status] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="text-center small"><?= $held ? round($attended / $held * 100) . '%' : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="small text-secondary">Rows in red have 3 or more absences in this subject.</p>

    <h2 class="h5">Class meetings</h2>
    <?php if (!$sessions): ?>
        <p class="text-secondary">No attendance taken yet for this class.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle bg-white shadow-sm">
                <thead class="table-light">
                <tr><th scope="col">Date</th><th scope="col">Started</th>
                    <?php foreach (ATTENDANCE_STATUSES as $label): ?><th scope="col" class="text-center"><?= e($label) ?></th><?php endforeach; ?>
                    <th scope="col"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td><?= e(date('D, M j, Y', strtotime($s['session_date']))) ?></td>
                        <td><?= e(format_time($s['starts_at'])) ?></td>
                        <?php foreach (array_keys(ATTENDANCE_STATUSES) as $status): ?>
                            <td class="text-center"><?= (int) $s[$status] ?></td>
                        <?php endforeach; ?>
                        <td class="text-end">
                            <?php if ($s['finished_at'] === null): ?><span class="badge text-bg-success me-1">In progress</span><?php endif; ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('teacher/session.php?id=' . $s['id'])) ?>"><?= $s['finished_at'] === null ? 'Continue' : 'View / correct' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
