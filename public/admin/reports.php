<?php
// School reports for the principal and registrar: enrollment, grade distribution, results per subject,
// attendance per section and grade submission per teacher. ?sy=ID&q=1..4
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin', 'principal');
$years = db_all('SELECT * FROM school_years ORDER BY label DESC');
$active = active_school_year();
$syId = get_int('sy') ?: (int) ($active['id'] ?? ($years[0]['id'] ?? 0));
$sy = null;
foreach ($years as $y) {
    if ((int) $y['id'] === $syId) {
        $sy = $y;
    }
}
if (!$sy) {
    abort(404, 'Add a school year first (Setup → School years).');
}
$passing = (int) config('passing_grade');

// Default quarter: the latest one with grades this school year
$latest = (int) db_value(
    'SELECT MAX(g.quarter) FROM grades g JOIN class_assignments ca ON ca.id = g.class_assignment_id JOIN sections sec ON sec.id = ca.section_id WHERE sec.school_year_id = ?',
    [$syId]
);
$quarter = in_array(get_int('q'), QUARTERS_ALL, true) ? get_int('q') : max(1, $latest);
$semester = $quarter <= 2 ? 1 : 2;

// ---- enrollment
$enrollment = db_all(
    'SELECT sec.grade_level, COUNT(e.student_id) AS learners, COUNT(DISTINCT sec.id) AS sections,
            SUM(st.sex = \'M\') AS male, SUM(st.sex = \'F\') AS female
       FROM sections sec
       LEFT JOIN enrollments e ON e.section_id = sec.id
       LEFT JOIN students st   ON st.id = e.student_id
      WHERE sec.school_year_id = ? GROUP BY sec.grade_level ORDER BY sec.grade_level',
    [$syId]
);
$totalLearners = array_sum(array_column($enrollment, 'learners'));
$teachers = (int) db_value('SELECT COUNT(DISTINCT ca.teacher_id) FROM class_assignments ca JOIN sections sec ON sec.id = ca.section_id WHERE sec.school_year_id = ?', [$syId]);

// ---- grade distribution for the quarter
$buckets = ['Outstanding' => [90, 101], 'Very Satisfactory' => [85, 90], 'Satisfactory' => [80, 85], 'Fairly Satisfactory' => [75, 80], 'Did Not Meet Expectations' => [0, 75]];
$distribution = array_fill_keys(array_keys($buckets), 0);
foreach (db_all(
    'SELECT g.grade FROM grades g JOIN class_assignments ca ON ca.id = g.class_assignment_id JOIN sections sec ON sec.id = ca.section_id
      WHERE sec.school_year_id = ? AND g.quarter = ?',
    [$syId, $quarter]
) as $g) {
    $grade = (float) $g['grade'];
    foreach ($buckets as $label => [$from, $to]) {
        if ($grade >= $from && $grade < $to) {
            $distribution[$label]++;
            break;
        }
    }
}
$gradeCount = array_sum($distribution);

// ---- results per class (subject × section) for the quarter
$classes = db_all(
    'SELECT ca.id, sub.code AS subject_code, sub.name AS subject_name, sec.grade_level, sec.strand, sec.name AS section_name,
            u.id AS teacher_id, u.full_name AS teacher_name,
            (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = sec.id) AS learners,
            COUNT(g.grade) AS graded, AVG(g.grade) AS average, SUM(g.grade >= ?) AS passed,
            MAX(gp.published_at) AS published_at
       FROM class_assignments ca
       JOIN subjects sub ON sub.id = ca.subject_id
       JOIN sections sec ON sec.id = ca.section_id
       JOIN users u      ON u.id = ca.teacher_id
       LEFT JOIN grades g ON g.class_assignment_id = ca.id AND g.quarter = ?
       LEFT JOIN grade_publications gp ON gp.class_assignment_id = ca.id AND gp.quarter = ?
      WHERE sec.school_year_id = ? AND ca.semester = ?
      GROUP BY ca.id
      ORDER BY sec.grade_level, sec.name, sub.type = \'core\' DESC, sub.sort_order',
    [$passing, $quarter, $quarter, $syId, $semester]
);
$failing = 0;
$byTeacher = [];
foreach ($classes as $c) {
    $failing += (int) $c['graded'] - (int) $c['passed'];
    $t = &$byTeacher[(int) $c['teacher_id']];
    $t ??= ['name' => $c['teacher_name'], 'classes' => 0, 'complete' => 0, 'published' => 0, 'missing' => 0];
    $t['classes']++;
    $t['complete'] += (int) ((int) $c['learners'] > 0 && (int) $c['graded'] >= (int) $c['learners']);
    $t['published'] += (int) ($c['published_at'] !== null);
    $t['missing'] += max(0, (int) $c['learners'] - (int) $c['graded']);
    unset($t);
}
uasort($byTeacher, static fn ($a, $b) => [$a['published'] / max(1, $a['classes']), $a['name']] <=> [$b['published'] / max(1, $b['classes']), $b['name']]);

// ---- attendance per section (whole school year, finished class meetings)
$attendance = db_all(
    'SELECT sec.id, sec.grade_level, sec.strand, sec.name AS section_name, COUNT(DISTINCT cs.id) AS meetings,
            COUNT(sa.student_id) AS marks, SUM(sa.status = \'present\') AS present, SUM(sa.status = \'late\') AS late,
            SUM(sa.status = \'absent\') AS absent, SUM(sa.status = \'excused\') AS excused
       FROM sections sec
       LEFT JOIN class_assignments ca ON ca.section_id = sec.id
       LEFT JOIN class_sessions cs    ON cs.class_assignment_id = ca.id AND cs.finished_at IS NOT NULL
       LEFT JOIN session_attendance sa ON sa.session_id = cs.id
      WHERE sec.school_year_id = ?
      GROUP BY sec.id ORDER BY sec.grade_level, sec.name',
    [$syId]
);
$allMarks = array_sum(array_column($attendance, 'marks'));
$attended = array_sum(array_column($attendance, 'present')) + array_sum(array_column($attendance, 'late'));
$attendanceRate = $allMarks ? (int) round($attended / $allMarks * 100) : null;

// ---- learners needing follow-up (any class this school year)
$classIds = array_map('intval', array_column(db_all('SELECT ca.id FROM class_assignments ca JOIN sections sec ON sec.id = ca.section_id WHERE sec.school_year_id = ?', [$syId]), 'id'));
$atRisk = count(array_unique(array_map(static fn ($r) => (int) $r['id'], AtRisk::find($classIds))));

$pct = static fn (int|float $part, int|float $whole): int => $whole > 0 ? (int) round($part / $whole * 100) : 0;
$tone = ['Outstanding' => '#14946b', 'Very Satisfactory' => '#2a4fd6', 'Satisfactory' => '#22a6b3', 'Fairly Satisfactory' => '#e0a000', 'Did Not Meet Expectations' => '#d9364a'];

$title = 'Reports';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Reports</h1>
        <p class="sub">S.Y. <?= e($sy['label']) ?> · Quarter <?= $quarter ?> (<?= e(semester_label($semester)) ?>) · as of <?= e(date('M j, Y g:i A')) ?></p>
    </div>
    <form class="d-flex flex-wrap gap-2 d-print-none" method="get" action="<?= e(url('admin/reports.php')) ?>">
        <select class="form-select w-auto" name="sy" aria-label="School year" onchange="this.form.submit()">
            <?php foreach ($years as $y): ?><option value="<?= (int) $y['id'] ?>" <?= (int) $y['id'] === $syId ? 'selected' : '' ?>>S.Y. <?= e($y['label']) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select w-auto" name="q" aria-label="Quarter" onchange="this.form.submit()">
            <?php foreach (QUARTERS_ALL as $q): ?><option value="<?= $q ?>" <?= $q === $quarter ? 'selected' : '' ?>>Quarter <?= $q ?></option><?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-outline-primary text-nowrap" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
    </form>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Learners', $totalLearners, implode(' · ', array_map(static fn ($r) => 'G' . $r['grade_level'] . ' ' . (int) $r['learners'], $enrollment)), 'people', ''],
        ['Teachers', $teachers, count($classes) . ' classes this semester', 'person-badge', 'violet'],
        ['Attendance', $attendanceRate === null ? '–' : $attendanceRate . '%', 'present or late, whole year', 'calendar2-check', 'green'],
        ['Failing grades', $failing, "below {$passing} in Quarter {$quarter}", 'exclamation-triangle', 'rose'],
        ['Need follow-up', $atRisk, 'learners at risk', 'exclamation-diamond', 'gold'],
    ] as [$label, $value, $note, $icon, $bubble]): ?>
        <div class="col-6 col-lg">
            <div class="summary-tile">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="label"><?= e($label) ?></div>
                    <div class="icon-bubble sm <?= $bubble ?>"><i class="bi bi-<?= $icon ?>"></i></div>
                </div>
                <div class="value"><?= e((string) $value) ?></div>
                <div class="note"><?= e($note) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6">Grade distribution · Quarter <?= $quarter ?></h2>
                <p class="small text-secondary"><?= $gradeCount ?> quarterly grades, grouped by the DepEd descriptors.</p>
                <?php if (!$gradeCount): ?>
                    <p class="text-secondary mb-0">No Quarter <?= $quarter ?> grades yet.</p>
                <?php else: ?>
                    <?php foreach ($distribution as $label => $n): $share = $pct($n, $gradeCount); ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small"><span><?= e($label) ?> <span class="text-secondary">(<?= $buckets[$label][0] ?><?= $buckets[$label][1] > 100 ? '–100' : '–' . ($buckets[$label][1] - 1) ?>)</span></span><span class="fw-semibold"><?= $n ?> · <?= $share ?>%</span></div>
                            <div class="progress progress-thin" role="img" aria-label="<?= e($label) ?>: <?= $share ?>%"><div class="progress-bar" style="width: <?= $share ?>%; background: <?= $tone[$label] ?>"></div></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6">Attendance by section</h2>
                <p class="small text-secondary">Every finished class meeting this school year.</p>
                <?php foreach ($attendance as $a):
                    $m = (int) $a['marks']; ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small">
                            <span><?= e(section_label($a)) ?></span>
                            <span class="fw-semibold"><?= $m ? $pct((int) $a['present'] + (int) $a['late'], $m) . '% attended' : 'no meetings yet' ?> <span class="text-secondary fw-normal">· <?= (int) $a['meetings'] ?> meetings</span></span>
                        </div>
                        <?php if ($m): ?>
                            <div class="progress progress-thin" role="img" aria-label="Present <?= $pct((int) $a['present'], $m) ?>%, late <?= $pct((int) $a['late'], $m) ?>%, absent <?= $pct((int) $a['absent'], $m) ?>%, excused <?= $pct((int) $a['excused'], $m) ?>%">
                                <div class="progress-bar" style="width: <?= $a['present'] / $m * 100 ?>%; background: #14946b" title="Present"></div>
                                <div class="progress-bar" style="width: <?= $a['late'] / $m * 100 ?>%; background: #e0a000" title="Late"></div>
                                <div class="progress-bar" style="width: <?= $a['excused'] / $m * 100 ?>%; background: #1f9bcf" title="Excused"></div>
                                <div class="progress-bar" style="width: <?= $a['absent'] / $m * 100 ?>%; background: #d9364a" title="Absent"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="d-flex flex-wrap gap-3 small text-secondary mt-3">
                    <span><span class="legend-dot" style="background: #14946b"></span>Present</span>
                    <span><span class="legend-dot" style="background: #e0a000"></span>Late</span>
                    <span><span class="legend-dot" style="background: #1f9bcf"></span>Excused</span>
                    <span><span class="legend-dot" style="background: #d9364a"></span>Absent</span>
                </div>
            </div>
        </div>
    </div>
</div>

<h2 class="h5">Grade submission · Quarter <?= $quarter ?></h2>
<p class="small text-secondary">Classes with every learner graded, and classes whose grades are published to learners and parents<?= ($d = Grading::label($syId, $quarter)) !== '' ? ' · ' . e($d) : '' ?>.</p>
<div class="table-responsive mb-4">
    <table class="table align-middle shadow-sm">
        <thead class="table-light"><tr><th>Teacher</th><th class="text-center">Classes</th><th class="text-center">Fully graded</th><th class="text-center">Published</th><th class="text-center">Grades missing</th></tr></thead>
        <tbody>
        <?php foreach ($byTeacher as $t): ?>
            <tr>
                <td><?= e($t['name']) ?></td>
                <td class="text-center"><?= $t['classes'] ?></td>
                <td class="text-center"><?= $t['complete'] ?>/<?= $t['classes'] ?></td>
                <td class="text-center"><?= $t['published'] === $t['classes'] ? '<span class="badge text-bg-success">All</span>' : $t['published'] . '/' . $t['classes'] ?></td>
                <td class="text-center <?= $t['missing'] ? 'text-danger fw-semibold' : 'text-secondary' ?>"><?= $t['missing'] ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$byTeacher): ?><tr><td colspan="5" class="text-secondary">No classes this semester.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<h2 class="h5">Results by subject · Quarter <?= $quarter ?></h2>
<div class="table-responsive">
    <table class="table table-sm align-middle shadow-sm">
        <thead class="table-light"><tr><th>Section</th><th>Subject</th><th>Teacher</th><th class="text-center">Graded</th><th class="text-center">Average</th><th class="text-center">Passed</th><th class="text-center">Status</th></tr></thead>
        <tbody>
        <?php foreach ($classes as $c):
            $graded = (int) $c['graded'];
            $passRate = $graded ? $pct((int) $c['passed'], $graded) : null; ?>
            <tr>
                <td class="small text-nowrap"><?= e(section_label($c)) ?></td>
                <td><span class="subject-chip" style="--hue: <?= color_hue($c['subject_code']) ?>"><?= e($c['subject_code']) ?></span> <span class="small"><?= e($c['subject_name']) ?></span></td>
                <td class="small"><?= e($c['teacher_name']) ?></td>
                <td class="text-center small"><?= $graded ?>/<?= (int) $c['learners'] ?></td>
                <td class="text-center"><?= $c['average'] === null ? '–' : number_format((float) $c['average'], 1) ?></td>
                <td class="text-center <?= $passRate !== null && $passRate < 80 ? 'text-danger fw-semibold' : '' ?>"><?= $passRate === null ? '–' : $passRate . '%' ?></td>
                <td class="text-center"><?= $c['published_at'] ? '<span class="badge text-bg-success">Published</span>' : ($graded ? '<span class="badge text-bg-warning">Not published</span>' : '<span class="badge text-bg-secondary">No grades</span>') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
