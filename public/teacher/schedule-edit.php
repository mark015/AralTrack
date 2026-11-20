<?php
// The weekly meeting times of one class (set by its teacher or an admin).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$class = managed_class_or_abort(get_int('id'), $staff);
$classId = (int) $class['id'];
$here = 'teacher/schedule-edit.php?id=' . $classId;

if (is_post()) {
    if (post('action') === 'add') {
        $day = post_int('weekday');
        $start = post('starts_at');
        $end = post('ends_at');
        $room = mb_substr(post('room'), 0, 50);
        $timeOk = static fn ($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
        if (!isset(Schedule::DAYS[$day]) || !$timeOk($start) || !$timeOk($end) || $end <= $start) {
            flash('danger', 'Choose a day and a start time before the end time.');
        } elseif ($clash = Schedule::conflict($class, $day, $start . ':00', $end . ':00')) {
            flash('danger', $clash);
        } else {
            db_exec(
                'INSERT INTO class_schedules (class_assignment_id, weekday, starts_at, ends_at, room) VALUES (?, ?, ?, ?, ?)',
                [$classId, $day, $start . ':00', $end . ':00', $room ?: null]
            );
            flash('success', Schedule::DAYS[$day] . ' ' . Schedule::time($start) . ' added.');
        }
    } elseif (post('action') === 'delete') {
        db_exec('DELETE FROM class_schedules WHERE id = ? AND class_assignment_id = ?', [post_int('slot_id'), $classId]);
        flash('success', 'Removed.');
    }
    redirect($here);
}

$slots = Schedule::forClass($classId);
$isTeacher = (int) $class['teacher_id'] === $staff['id'];

$title = 'Schedule · ' . $class['subject_name'];
require APP_ROOT . '/templates/header.php';
if ($isTeacher) {
    $activeTab = 'schedule';
    $learnerCount = count(section_roster((int) $class['section_id']));
    require APP_ROOT . '/templates/class-tabs.php';
} else { ?>
    <nav aria-label="breadcrumb"><ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('admin/section.php?id=' . $class['section_id'])) ?>"><?= e(section_label($class)) ?></a></li>
        <li class="breadcrumb-item active">Schedule</li></ol></nav>
    <h1 class="h3"><?= e($class['subject_name']) ?> <span class="text-secondary fw-normal fs-5">· <?= e($class['teacher_name']) ?></span></h1>
<?php } ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <h2 class="h6"><i class="bi bi-clock me-2"></i>Add a class meeting</h2>
                <form method="post" action="<?= e(url($here)) ?>" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="col-12">
                        <select class="form-select" name="weekday" aria-label="Day">
                            <?php foreach (Schedule::DAYS as $n => $day): ?><option value="<?= $n ?>"><?= e($day) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><label class="form-label small" for="s">Starts</label><input class="form-control" type="time" id="s" name="starts_at" value="08:00" required></div>
                    <div class="col-6"><label class="form-label small" for="t">Ends</label><input class="form-control" type="time" id="t" name="ends_at" value="09:00" required></div>
                    <div class="col-12"><input class="form-control" name="room" maxlength="50" placeholder="Room (optional)"></div>
                    <div class="col-12"><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
                </form>
                <p class="small text-secondary mt-3 mb-0">
                    The schedule fills in the start time when taking attendance, and shows on the teacher's,
                    learners' and parents' "today" lists. Clashes with the same section or teacher are blocked.
                </p>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="table-responsive">
            <table class="table align-middle shadow-sm">
                <thead><tr><th>Day</th><th>Time</th><th>Room</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($slots as $s): ?>
                    <tr>
                        <td class="fw-semibold"><?= e(Schedule::DAYS[(int) $s['weekday']]) ?></td>
                        <td><?= e(Schedule::time($s['starts_at'])) ?> – <?= e(Schedule::time($s['ends_at'])) ?></td>
                        <td><?= e($s['room'] ?? '—') ?></td>
                        <td class="text-end">
                            <form method="post" action="<?= e(url($here)) ?>" class="m-0"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete"><input type="hidden" name="slot_id" value="<?= (int) $s['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash"></i></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$slots): ?><tr><td colspan="4" class="text-secondary">No meeting times yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
