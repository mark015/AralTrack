<?php
// Gate log: who came in and went out on a day, and who has not arrived yet. The registrar, principal, guidance
// counselor, Super Admin and gate guard see every section; an adviser sees their advisory section.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
Gate::requireAvailable();
$visible = Gate::visibleSections($staff);
if ($visible === []) {
    abort(403, 'The gate log is for advisers (their advisory section), the registrar, the principal, guidance and the gate guard.');
}
$mayScan = Gate::mayScan($staff);
$mayDelete = is_admin($staff);
$showContact = $staff['role'] !== 'guard'; // the guard only needs names

$date = get_str('date');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false || $date > date('Y-m-d')) {
    $date = date('Y-m-d');
}
$view = get_str('view') === 'missing' ? 'missing' : 'scans';
$search = mb_substr(trim(get_str('q')), 0, 60);
$sy = active_school_year();
$sections = $sy ? db_all(
    'SELECT id, grade_level, strand, name AS section_name FROM sections WHERE school_year_id = ? AND workspace_id = ? ORDER BY grade_level, name',
    [$sy['id'], ws()]
) : [];
if ($visible !== null) {
    $sections = array_values(array_filter($sections, static fn ($s) => in_array((int) $s['id'], $visible, true)));
}
$sectionId = get_int('section');
$sectionIds = $visible;
if ($sectionId && in_array($sectionId, array_map(static fn ($s) => (int) $s['id'], $sections), true)) {
    $sectionIds = [$sectionId];
} else {
    $sectionId = 0;
}
$here = 'gate/log.php?' . http_build_query(array_filter(['date' => $date, 'section' => $sectionId ?: null, 'view' => $view === 'missing' ? 'missing' : null, 'q' => $search ?: null]));

if (is_post()) {
    $action = post('action');
    if ($action === 'manual' && $mayScan && $date === date('Y-m-d')) {
        $student = db_one('SELECT * FROM students WHERE id = ? AND workspace_id = ?', [post_int('student_id'), ws()]);
        $allowed = $student && ($sectionIds === null || db_value(
            'SELECT 1 FROM enrollments WHERE student_id = ? AND section_id IN (' . implode(',', array_map('intval', $sectionIds)) . ')',
            [$student['id']]
        ));
        if (!$allowed) {
            flash('danger', 'Learner not found.');
        } else {
            $result = Gate::record($student, post('direction') === 'out' ? 'out' : 'in', time(), $staff['id'], 'manual');
            flash($result['accepted'] ? 'success' : 'info', $result['message'] . ($result['accepted'] ? '. Parents were told.' : ''));
        }
    } elseif ($action === 'delete' && $mayDelete) {
        $log = db_one(
            'SELECT g.*, st.last_name, st.first_name, st.middle_name FROM gate_logs g JOIN students st ON st.id = g.student_id WHERE g.id = ? AND g.workspace_id = ?',
            [post_int('log_id'), ws()]
        );
        if ($log) {
            db_exec('DELETE FROM gate_logs WHERE id = ? AND workspace_id = ?', [$log['id'], ws()]);
            Access::log('gate', 'Removed a gate scan: ' . student_name($log) . ', ' . ($log['direction'] === 'in' ? 'in' : 'out') . ' at ' . $log['scanned_at'], 'learner', (int) $log['student_id']);
            flash('success', 'The scan was removed. (Parents already got the alert for it.)');
        }
    }
    redirect($here);
}

$counts = Gate::counts($date, $sectionIds);
$rows = $view === 'scans' ? Gate::day($date, $sectionIds, $search) : [];
$missing = $view === 'missing' ? Gate::notArrived($date, $sectionIds) : [];
if ($view === 'missing' && $search !== '') {
    $needle = mb_strtolower($search);
    $missing = array_values(array_filter($missing, static fn ($m) => str_contains(mb_strtolower($m['last_name'] . ' ' . $m['first_name'] . ' ' . $m['lrn']), $needle)));
}
$isToday = $date === date('Y-m-d');
$link = static fn (array $change): string => url('gate/log.php?' . http_build_query(array_filter(array_merge(
    ['date' => $date, 'section' => $sectionId ?: null, 'view' => $view === 'missing' ? 'missing' : null, 'q' => $search ?: null],
    $change
), static fn ($v) => $v !== null && $v !== '')));

$title = 'Gate log';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Gate log</h1>
        <p class="sub"><?= e(date('l, F j, Y', strtotime($date))) ?><?= $visible !== null ? ' · your advisory section' . (count($visible) === 1 ? '' : 's') : '' ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2 d-print-none">
        <?php if ($mayScan): ?><a class="btn btn-primary" href="<?= e(url('gate/index.php')) ?>"><i class="bi bi-qr-code-scan me-1"></i>Gate scanner</a><?php endif; ?>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
    </div>
</div>

<form method="get" action="<?= e(url('gate/log.php')) ?>" class="card mb-3 d-print-none">
    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <div>
            <label class="form-label small mb-1" for="f-date">Day</label>
            <input type="date" class="form-control" id="f-date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>">
        </div>
        <?php if (count($sections) > 1): ?>
            <div>
                <label class="form-label small mb-1" for="f-section">Section</label>
                <select class="form-select" id="f-section" name="section">
                    <option value="0">All sections</option>
                    <?php foreach ($sections as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $sectionId ? 'selected' : '' ?>><?= e(section_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="flex-grow-1" style="min-width: 12rem">
            <label class="form-label small mb-1" for="f-q">Learner</label>
            <input class="form-control" id="f-q" name="q" value="<?= e($search) ?>" placeholder="Name or LRN">
        </div>
        <?php if ($view === 'missing'): ?><input type="hidden" name="view" value="missing"><?php endif; ?>
        <button class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Show</button>
    </div>
</form>

<div class="row g-2 mb-3">
    <?php foreach ([
        ['Arrived', $counts['arrived'], 'green'], ['Late', $counts['late'], 'gold'], ['Left', $counts['left'], 'teal'],
        [$isToday ? 'Inside now' : 'Did not leave by scan', $counts['inside'], ''], ['Not arrived', max(0, $counts['enrolled'] - $counts['arrived']), 'rose'],
    ] as [$label, $value, $tone]): ?>
        <div class="col-6 col-md">
            <div class="summary-tile gate-tile <?= $tone ?>"><div class="label"><?= e($label) ?></div><div class="value"><?= (int) $value ?></div></div>
        </div>
    <?php endforeach; ?>
</div>

<ul class="nav nav-tabs mb-3 d-print-none">
    <li class="nav-item"><a class="nav-link<?= $view === 'scans' ? ' active' : '' ?>" href="<?= e($link(['view' => null])) ?>">Scans</a></li>
    <li class="nav-item"><a class="nav-link<?= $view === 'missing' ? ' active' : '' ?>" href="<?= e($link(['view' => 'missing'])) ?>">Not arrived (<?= max(0, $counts['enrolled'] - $counts['arrived']) ?>)</a></li>
</ul>

<?php if ($view === 'scans'): ?>
    <?php if (!$rows): ?>
        <div class="card"><div class="card-body text-center text-secondary py-5"><i class="bi bi-door-closed fs-2 d-block mb-2"></i>No gate scans<?= $search !== '' ? ' match "' . e($search) . '"' : '' ?> on this day.</div></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle bg-white shadow-sm">
                <thead class="table-light">
                <tr><th scope="col">Time</th><th scope="col">Learner</th><th scope="col">Section</th><th scope="col">Gate</th><th scope="col" class="d-none d-md-table-cell">Recorded</th><?php if ($mayDelete): ?><th scope="col" class="d-print-none"><span class="visually-hidden">Remove</span></th><?php endif; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= e(format_time($r['scanned_at'])) ?></td>
                        <td><?= e(student_name($r)) ?><div class="lrn small text-secondary"><?= e($r['lrn']) ?></div></td>
                        <td class="small"><?= $r['section_name'] !== null ? e(section_label($r)) : '' ?></td>
                        <td class="text-nowrap"><?= gate_badge($r['direction'], (bool) $r['late']) ?></td>
                        <td class="small text-secondary d-none d-md-table-cell"><?= e(Gate::SOURCES[$r['source']] ?? $r['source']) ?><?= $r['recorded_by_name'] ? ' · ' . e($r['recorded_by_name']) : '' ?></td>
                        <?php if ($mayDelete): ?>
                            <td class="text-end d-print-none">
                                <form method="post" action="<?= e(url($here)) ?>" class="m-0" onsubmit="return confirm('Remove this gate scan? Parents already got the alert for it.')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="log_id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" aria-label="Remove this scan"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php else: ?>
    <?php if (!$missing): ?>
        <div class="card"><div class="card-body text-center text-secondary py-5"><i class="bi bi-check2-circle fs-2 d-block mb-2"></i>Every learner<?= $search !== '' ? ' matching "' . e($search) . '"' : '' ?> came in through the gate.</div></div>
    <?php else: ?>
        <p class="small text-secondary">Learners enrolled this school year who were not scanned coming in. Some may have entered without scanning, or be absent.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle bg-white shadow-sm">
                <thead class="table-light">
                <tr><th scope="col">Learner</th><th scope="col">Section</th><?php if ($showContact): ?><th scope="col">Contact number</th><?php endif; ?><?php if ($mayScan && $isToday): ?><th scope="col" class="d-print-none"><span class="visually-hidden">Record</span></th><?php endif; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($missing as $m): ?>
                    <tr>
                        <td><?= e(student_name($m)) ?><div class="lrn small text-secondary"><?= e($m['lrn']) ?></div></td>
                        <td class="small"><?= e(section_label($m)) ?></td>
                        <?php if ($showContact): ?><td class="small"><?= e((string) $m['contact_number']) ?></td><?php endif; ?>
                        <?php if ($mayScan && $isToday): ?>
                            <td class="text-end d-print-none">
                                <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="manual"><input type="hidden" name="direction" value="in"><input type="hidden" name="student_id" value="<?= (int) $m['id'] ?>">
                                    <button class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-box-arrow-in-right me-1"></i>Arrived now</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
