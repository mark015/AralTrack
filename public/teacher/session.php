<?php
// Taking attendance for one class meeting: scan QR cards (camera or USB scanner) or mark by hand.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
Access::requireModule('attendance');
$session = teacher_session_or_abort(get_int('id'), $staff);
$class = $session['class'];
$sessionId = (int) $session['id'];
$here = 'teacher/session.php?id=' . $sessionId;

if (is_post() && post('action') === 'finish') {
    $absent = Attendance::finish($session, $staff['id']);
    $c = Attendance::counts(Attendance::session($sessionId));
    flash('success', sprintf(
        'Attendance finished: %d present, %d late, %d absent, %d excused.%s',
        $c['present'], $c['late'], $c['absent'], $c['excused'],
        $absent ? ' Parents of absent learners have been notified.' : ''
    ));
    redirect($here);
}

$roster = section_roster((int) $class['section_id']);
$marks = Attendance::marks($sessionId);
$counts = Attendance::counts($session);
$isOpen = $session['finished_at'] === null;

$title = 'Attendance · ' . $class['subject_name'];
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('teacher/index.php')) ?>">My classes</a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('teacher/attendance.php?id=' . $class['id'])) ?>"><?= e($class['subject_name']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e(date('M j', strtotime($session['session_date']))) ?></li>
    </ol>
</nav>
<h1 class="h4 mb-0"><?= e($class['subject_name']) ?> · <?= e(section_label($class)) ?></h1>
<p class="text-secondary">
    <?= e(date('l, F j, Y', strtotime($session['session_date']))) ?> · started <?= e(format_time($session['starts_at'])) ?>
    · Late after <?= e(date('g:i A', Attendance::lateAfter($session))) ?>
</p>

<div id="attendance-page" data-api="<?= e(url('teacher/session-api.php')) ?>" data-session="<?= $sessionId ?>" data-csrf="<?= e(csrf_token()) ?>">
    <?php if ($isOpen): ?>
        <div class="row g-3 mb-4">
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div id="camera" class="camera-box mb-2"></div>
                        <p id="camera-note" class="small text-secondary mb-2">Point the camera at a learner's QR card.</p>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="camera-toggle">Start camera</button>
                        <hr>
                        <label for="scan-input" class="form-label small mb-1">USB scanner (or type the card code and press Enter)</label>
                        <input id="scan-input" class="form-control" autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div id="scan-result" class="scan-result alert alert-secondary mb-3" role="status" aria-live="polite"><i class="bi bi-qr-code-scan" aria-hidden="true"></i>Ready. Scan a learner's QR card.</div>
                <?php require APP_ROOT . '/templates/attendance-counts.php'; ?>
                <form method="post" action="<?= e(url($here)) ?>" class="mt-3"
                      onsubmit="return confirm('Finish attendance? Learners not marked yet will be marked absent, and their parents will be notified.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="finish">
                    <button class="btn btn-danger">Finish attendance</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-light border">
            Finished at <?= e(format_time($session['finished_at'])) ?>. You can still correct marks below; parents are notified of every change.
        </div>
        <div class="mb-4"><?php require APP_ROOT . '/templates/attendance-counts.php'; ?></div>
    <?php endif; ?>

    <h2 class="h5">Learners</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle bg-white shadow-sm">
            <thead class="table-light">
            <tr><th scope="col">#</th><th scope="col">Learner</th><th scope="col">Status</th><th scope="col">Time</th><th scope="col">Mark by hand</th></tr>
            </thead>
            <tbody>
            <?php foreach ($roster as $i => $student):
                $sid = (int) $student['id'];
                $mark = $marks[$sid] ?? null; ?>
                <tr data-student-row="<?= $sid ?>">
                    <td class="text-secondary small"><?= $i + 1 ?></td>
                    <td><?= e(student_name($student)) ?><div class="lrn small text-secondary"><?= e($student['lrn']) ?></div></td>
                    <td class="js-status"><?= attendance_badge($mark['status'] ?? null) ?></td>
                    <td class="js-time small text-secondary">
                        <?= $mark ? e(match ($mark['source']) { 'scan' => format_time($mark['marked_at']), 'auto' => 'not scanned', default => 'by teacher' }) : '' ?>
                    </td>
                    <td>
                        <form method="post" action="<?= e(url('teacher/session-api.php')) ?>" class="mark-form d-flex flex-wrap gap-1 m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark">
                            <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                            <input type="hidden" name="student_id" value="<?= $sid ?>">
                            <select name="status" class="form-select form-select-sm w-auto" aria-label="Status for <?= e(student_name($student)) ?>" required>
                                <?php if (!$mark): ?><option value="" selected disabled>Choose…</option><?php endif; ?>
                                <?php foreach (ATTENDANCE_STATUSES as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= ($mark['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="note" class="form-control form-control-sm note-input" maxlength="255" placeholder="Note (optional)"
                                   value="<?= e($mark['note'] ?? '') ?>" aria-label="Note for <?= e(student_name($student)) ?>">
                            <button class="btn btn-sm btn-outline-primary">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isOpen): ?>
    <script src="<?= e(asset('assets/vendor/html5-qrcode.min.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset('assets/attendance.js')) ?>"></script>
<?php require APP_ROOT . '/templates/footer.php'; ?>
