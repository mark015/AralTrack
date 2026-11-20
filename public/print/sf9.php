<?php
// SF9 — Learner's Progress Report Card (Senior High), two pages per learner.
// ?section=ID prints the whole section; add &student=ID for one learner.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$section = Forms::sectionOrAbort(get_int('section'), $staff);
$sectionId = (int) $section['id'];
$learners = Forms::roster($sectionId);
if ($studentId = get_int('student')) {
    $learners = array_values(array_filter($learners, static fn ($l) => (int) $l['id'] === $studentId));
}
[$startYear] = array_map('intval', explode('-', $section['sy_label']));
$months = [];
for ($m = 6; $m <= 15; $m++) { // June … March
    $months[] = sprintf('%04d-%02d', $startYear + intdiv($m - 1, 12), ($m - 1) % 12 + 1);
}
$acks = [];
foreach (db_all(
    'SELECT ra.*, p.full_name FROM report_acknowledgements ra JOIN parents p ON p.id = ra.parent_id WHERE ra.school_year_id = ?',
    [$section['school_year_id']]
) as $a) {
    $acks[(int) $a['student_id']][(int) $a['semester']][] = $a;
}

$printTitle = 'SF9 · ' . section_label($section);
$printBack = 'admin/forms.php?sy=' . $section['school_year_id'];
$printNote = count($learners) . ' report card(s) · ' . section_label($section) . ' · S.Y. ' . $section['sy_label'] . ' · Each learner prints on 2 pages';
require APP_ROOT . '/templates/print-head.php';

foreach ($learners as $st):
    $sid = (int) $st['id'];
    $results = Forms::semesterResults($sid, $sectionId);
    $attendance = Forms::monthlyAttendance($sid, $sectionId, $section['sy_label']);
    $values = Forms::observedValues($sid, (int) $section['school_year_id']);
    $totals = ['days' => 0, 'present' => 0, 'absent' => 0];
    foreach ($attendance as $a) {
        foreach ($totals as $k => $v) {
            $totals[$k] += $a[$k];
        }
    }
    ?>
    <section class="page">
        <?php require APP_ROOT . '/templates/deped-header.php'; ?>
        <h1>LEARNER'S PROGRESS REPORT CARD</h1>
        <div class="info">
            <div><span>Name</span><strong><?= e(mb_strtoupper($st['last_name']) . ', ' . $st['first_name'] . ' ' . $st['middle_name']) ?></strong></div>
            <div><span>LRN</span><?= e($st['lrn']) ?></div>
            <div><span>Age</span><?= Forms::age($st['birthdate'], "{$startYear}-06-01") ?></div>
            <div><span>Sex</span><?= e($st['sex'] === 'F' ? 'Female' : ($st['sex'] === 'M' ? 'Male' : '')) ?></div>
            <div><span>Grade</span><?= (int) $section['grade_level'] ?></div>
            <div><span>Section</span><?= e($section['section_name']) ?></div>
            <div><span>Track / strand</span><?= e($section['strand']) ?></div>
            <div><span>School year</span><?= e($section['sy_label']) ?></div>
        </div>

        <h2>Report on attendance</h2>
        <table>
            <tr><th></th><?php foreach ($months as $m): ?><th class="c"><?= e(date('M', strtotime($m . '-01'))) ?></th><?php endforeach; ?><th class="c">Total</th></tr>
            <?php foreach (['days' => 'No. of school days', 'present' => 'No. of days present', 'absent' => 'No. of days absent'] as $key => $label): ?>
                <tr><td><?= e($label) ?></td>
                    <?php foreach ($months as $m): ?><td class="c"><?= isset($attendance[$m]) ? (int) $attendance[$m][$key] : '' ?></td><?php endforeach; ?>
                    <td class="c"><strong><?= $totals[$key] ?></strong></td></tr>
            <?php endforeach; ?>
        </table>
        <p class="small">A school day is a day when at least one class met; "present" means present or late in at least one class.</p>

        <h2>Parent / guardian's signature</h2>
        <table>
            <?php foreach (SEMESTERS as $sem): ?>
                <tr><td style="width: 30%"><?= e(semester_label($sem)) ?></td>
                    <td style="height: 26px">
                        <?php foreach ($acks[$sid][$sem] ?? [] as $a): ?>
                            <span class="small">Seen online by <?= e($a['full_name']) ?> on <?= e(date('M j, Y', strtotime($a['acknowledged_at']))) ?></span><br>
                        <?php endforeach; ?>
                    </td></tr>
            <?php endforeach; ?>
        </table>

        <p style="margin-top: 14px">Dear Parent,<br>
            This report card shows the ability and the progress your child has made in the different learning areas as well as his/her core values.
            The school welcomes you should you desire to know more about your child's progress.</p>
        <div class="sign">
            <div><?= e($section['adviser'] ?? '') ?><br>Adviser</div>
            <div><?= e(setting('principal_name')) ?><br>School Head</div>
        </div>
    </section>

    <section class="page">
        <?php foreach ($results as $sem => $r): $quarters = semester_quarters($sem); ?>
            <h2><?= e(semester_label($sem)) ?></h2>
            <table>
                <tr><th>Subjects</th><?php foreach ($quarters as $q): ?><th class="c" style="width: 12%">Quarter <?= $q ?></th><?php endforeach; ?><th class="c" style="width: 13%">Semester final grade</th><th class="c" style="width: 11%">Remarks</th></tr>
                <?php foreach (['core' => 'Core subjects', 'other' => 'Applied and specialized subjects'] as $group => $label):
                    $subjects = array_filter($r['subjects'], static fn ($s) => ($s['type'] === 'core') === ($group === 'core'));
                    if (!$subjects) { continue; } ?>
                    <tr class="group"><td colspan="5"><?= e($label) ?></td></tr>
                    <?php foreach ($subjects as $s): ?>
                        <tr><td><?= e($s['name']) ?></td>
                            <td class="c"><?= e(format_grade($s['q'][0])) ?></td><td class="c"><?= e(format_grade($s['q'][1])) ?></td>
                            <td class="c"><strong><?= $s['final'] ?? '' ?></strong></td><td class="c"><?= e($s['remarks']) ?></td></tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <tr><td colspan="3" style="text-align: right"><strong>General average for the semester</strong></td>
                    <td class="c"><strong><?= $r['average'] ?? '' ?></strong></td>
                    <td class="c"><?= e($r['average'] !== null ? remarks($r['average']) : '') ?></td></tr>
            </table>
            <?php if ($honor = Forms::honor($r['average'], $r['complete'])): ?><p class="small"><strong>Award:</strong> <?= e($honor) ?></p><?php endif; ?>
        <?php endforeach; ?>

        <h2>Report on learner's observed values</h2>
        <table>
            <tr><th style="width: 16%">Core values</th><th>Behavior statements</th><?php foreach (QUARTERS_ALL as $q): ?><th class="c" style="width: 7%">Q<?= $q ?></th><?php endforeach; ?></tr>
            <?php $lastValue = null; foreach (Forms::VALUES as $n => [$value, $statement]): ?>
                <tr><td><?= $value !== $lastValue ? e($value) : '' ?></td><td class="small"><?= e($statement) ?></td>
                    <?php foreach (QUARTERS_ALL as $q): ?><td class="c"><?= e($values[$n][$q] ?? '') ?></td><?php endforeach; ?></tr>
            <?php $lastValue = $value; endforeach; ?>
        </table>

        <div class="legend">
            <table>
                <tr><th>Descriptors</th><th>Grading scale</th><th>Remarks</th></tr>
                <tr><td>Outstanding</td><td class="c">90–100</td><td>Passed</td></tr>
                <tr><td>Very Satisfactory</td><td class="c">85–89</td><td>Passed</td></tr>
                <tr><td>Satisfactory</td><td class="c">80–84</td><td>Passed</td></tr>
                <tr><td>Fairly Satisfactory</td><td class="c">75–79</td><td>Passed</td></tr>
                <tr><td>Did Not Meet Expectations</td><td class="c">Below 75</td><td>Failed</td></tr>
            </table>
            <table>
                <tr><th>Marking</th><th>Non-numerical rating</th></tr>
                <?php foreach (Forms::RATINGS as $code => $label): ?><tr><td class="c"><?= e($code) ?></td><td><?= e($label) ?></td></tr><?php endforeach; ?>
            </table>
        </div>
    </section>
<?php endforeach; ?>
<?php if (!$learners): ?><section class="page"><p>No learners in this section.</p></section><?php endif; ?>
</body>
</html>
