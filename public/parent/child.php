<?php
// One child: attendance by month (with arrival times and teacher notes), or the report card.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$parent = require_parent();
$child = parent_child_or_abort($parent, get_int('id'));
$childId = (int) $child['id'];
$attendanceOn = Access::can('attendance');
$gradesOn = Access::can('grades');
if (!$attendanceOn && !$gradesOn) {
    Access::personalBlock('grades') !== null || Access::personalBlock('attendance') !== null
        ? Access::stopPage('Attendance and grades are turned off for your account', Access::personalMessage(Access::personalBlock('grades') !== null ? 'grades' : 'attendance'))
        : Access::stopPage('Attendance and grades are not available right now', 'The school has turned off attendance and report cards for now. Please check again later.');
}
$tab = get_str('tab') === 'grades' ? 'grades' : 'attendance';
if ($tab === 'grades' && !$gradesOn) {
    $tab = 'attendance';
} elseif ($tab === 'attendance' && !$attendanceOn) {
    $tab = 'grades';
}

$month = get_str('month');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
    $month = date('Y-m');
}
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));

$title = $child['first_name'] . ' · ' . ($tab === 'grades' ? 'Grades' : 'Attendance');
if ($tab === 'grades') {
    $card = ReportCard::build($childId, get_int('sy'));
    $ackYear = (int) ($card['selected']['id'] ?? 0);
    if (is_post() && post('action') === 'ack') {
        $sem = post_int('semester');
        if ($ackYear && isset($card['semesters'][$sem]) && $card['semesters'][$sem]['average'] !== null) {
            db_exec(
                'INSERT IGNORE INTO report_acknowledgements (student_id, school_year_id, semester, parent_id) VALUES (?, ?, ?, ?)',
                [$childId, $ackYear, $sem, $parent['id']]
            );
            flash('success', 'Thank you. The adviser can see that you have seen the ' . semester_label($sem) . ' report card.');
        }
        redirect('parent/child.php?tab=grades&id=' . $childId . '&sy=' . $ackYear);
    }
    $acknowledged = [];
    foreach (db_all('SELECT semester, acknowledged_at FROM report_acknowledgements WHERE student_id = ? AND school_year_id = ? AND parent_id = ?', [$childId, $ackYear, $parent['id']]) as $a) {
        $acknowledged[(int) $a['semester']] = $a['acknowledged_at'];
    }
} else {
    $sy = active_school_year();
    $section = $sy ? db_one(
        'SELECT sec.id, sec.grade_level, sec.strand, sec.name AS section_name FROM enrollments e
           JOIN sections sec ON sec.id = e.section_id WHERE e.student_id = ? AND e.school_year_id = ?',
        [$childId, $sy['id']]
    ) : null;
    $sessions = $section ? Attendance::studentSessions($childId, (int) $section['id'], $from, $to) : [];
    $byDate = [];
    foreach ($sessions as $s) {
        $byDate[$s['session_date']][] = $s;
    }
    // Arriving at and leaving school (gate scans), shown on the same days
    $gateDays = $section && Gate::available() ? Gate::forStudent($childId, $from, $to) : [];
    foreach (array_keys($gateDays) as $day) {
        $byDate[$day] ??= [];
    }
    krsort($byDate);
    $totals = $section ? ReportCard::build($childId)['attendance'] : [];
}
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('parent/index.php')) ?>">My children</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($child['first_name']) ?></li>
    </ol>
</nav>
<ul class="nav nav-tabs mb-4 d-print-none">
    <?php if ($attendanceOn): ?><li class="nav-item"><a class="nav-link<?= $tab === 'attendance' ? ' active' : '' ?>" href="<?= e(url('parent/child.php?id=' . $childId)) ?>">Attendance</a></li><?php endif; ?>
    <?php if ($gradesOn): ?><li class="nav-item"><a class="nav-link<?= $tab === 'grades' ? ' active' : '' ?>" href="<?= e(url('parent/child.php?tab=grades&id=' . $childId)) ?>">Grades</a></li><?php endif; ?>
</ul>

<?php if ($tab === 'grades'): ?>
    <?php
    $yearFormAction = 'parent/child.php';
    $yearFormHidden = ['id' => $childId, 'tab' => 'grades'];
    $showAttendance = $attendanceOn;
    require APP_ROOT . '/templates/report-card.php';
    ?>
    <?php if ($ackYear): ?>
        <div class="card mt-4 d-print-none">
            <div class="card-body">
                <h2 class="h6"><i class="bi bi-pen me-2"></i>Parent's acknowledgment</h2>
                <p class="small text-secondary">Instead of signing the paper report card, confirm here that you have seen each semester's grades.</p>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (SEMESTERS as $sem): ?>
                        <?php if (isset($acknowledged[$sem])): ?>
                            <span class="badge text-bg-success p-2"><i class="bi bi-check2-circle"></i><?= e(semester_label($sem)) ?> seen on <?= e(date('M j, Y', strtotime($acknowledged[$sem]))) ?></span>
                        <?php elseif ($card['semesters'][$sem]['average'] !== null): ?>
                            <form method="post" action="<?= e(url('parent/child.php?tab=grades&id=' . $childId . '&sy=' . $ackYear)) ?>" class="m-0">
                                <?= csrf_field() ?><input type="hidden" name="action" value="ack"><input type="hidden" name="semester" value="<?= $sem ?>">
                                <button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>I have seen the <?= e(semester_label($sem)) ?> report card</button>
                            </form>
                        <?php else: ?>
                            <span class="badge text-bg-light border p-2"><?= e(semester_label($sem)) ?>: grades not complete yet</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <h1 class="h4 mb-0"><?= e(student_name($child)) ?></h1>
    <p class="text-secondary"><?= $section ? e(section_label($section)) . ' · S.Y. ' . e($sy['label']) : 'Not enrolled this school year' ?></p>

    <?php if ($section): ?>
        <div class="d-flex align-items-center gap-2 mb-3">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('parent/child.php?id=' . $childId . '&month=' . date('Y-m', strtotime($from . ' -1 month')))) ?>">‹ Previous</a>
            <h2 class="h5 mb-0 px-2"><?= e(date('F Y', strtotime($from))) ?></h2>
            <?php if ($month < date('Y-m')): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('parent/child.php?id=' . $childId . '&month=' . date('Y-m', strtotime($from . ' +1 month')))) ?>">Next ›</a>
            <?php endif; ?>
        </div>

        <?php if (!$byDate): ?>
            <p class="text-secondary">No attendance recorded in <?= e(date('F Y', strtotime($from))) ?>.</p>
        <?php endif; ?>
        <?php foreach ($byDate as $date => $daySessions): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><?= e(date('l, F j', strtotime($date))) ?></div>
                <ul class="list-group list-group-flush">
                    <?php if (isset($gateDays[$date])): ?>
                        <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                            <span><i class="bi bi-door-open me-1 text-secondary" aria-hidden="true"></i>School gate</span>
                            <span class="text-end">
                                <?php foreach ($gateDays[$date] as $g): ?>
                                    <span class="text-nowrap ms-2"><?= gate_badge($g['direction'], (bool) $g['late']) ?> <span class="small text-secondary"><?= e(format_time($g['scanned_at'])) ?></span></span>
                                <?php endforeach; ?>
                            </span>
                        </li>
                    <?php endif; ?>
                    <?php foreach (array_reverse($daySessions) as $s): ?>
                        <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                            <span><span class="text-secondary"><?= e(format_time($s['starts_at'])) ?></span> · <?= e($s['subject_name']) ?></span>
                            <span class="text-end">
                                <?php if ($s['status'] === null): ?>
                                    <span class="text-secondary small">Class in progress</span>
                                <?php else: ?>
                                    <?= attendance_badge($s['status']) ?>
                                    <?php if ($s['source'] === 'scan'): ?><span class="small text-secondary">arrived <?= e(format_time($s['marked_at'])) ?></span><?php endif; ?>
                                    <?php if ($s['note']): ?><div class="small text-secondary">Teacher's note: <?= e($s['note']) ?></div><?php endif; ?>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <h2 class="h5 mt-4">This school year, by subject</h2>
        <div class="table-responsive">
            <table class="table table-sm table-bordered bg-white align-middle">
                <thead class="table-light">
                <tr><th scope="col">Subject</th><th scope="col">Semester</th><th scope="col" class="text-center">Classes</th>
                    <?php foreach (ATTENDANCE_STATUSES as $label): ?><th scope="col" class="text-center"><?= e($label) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($totals as $t): ?>
                    <tr>
                        <td><?= e($t['subject']) ?></td>
                        <td class="small"><?= (int) $t['semester'] === 1 ? '1st' : '2nd' ?></td>
                        <td class="text-center"><?= (int) $t['held'] ?></td>
                        <?php foreach (array_keys(ATTENDANCE_STATUSES) as $status): ?>
                            <td class="text-center <?= $status === 'absent' && $t['absent'] >= 3 ? 'text-danger fw-semibold' : '' ?>"><?= (int) $t[$status] ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
