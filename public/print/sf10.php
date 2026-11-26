<?php
// SF10 — Learner's Permanent Academic Record (Senior High): every school year and semester.
// ?student=ID for one learner, or ?section=ID for every learner in a section.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
if ($sectionId = get_int('section')) {
    $section = Forms::sectionOrAbort($sectionId, $staff);
    $learners = Forms::roster($sectionId);
    $back = 'admin/forms.php?sy=' . $section['school_year_id'];
} else {
    $student = db_one('SELECT * FROM students WHERE id = ? AND workspace_id = ?', [get_int('student'), ws()]);
    if (!$student || !staff_can_see_learner($staff, (int) $student['id'])) {
        abort(404, 'That learner does not exist, or is not one of yours.');
    }
    $learners = [$student];
    $back = 'admin/student-edit.php?id=' . $student['id'];
}

$printTitle = 'SF10 · Permanent record';
$printBack = $back;
$printNote = count($learners) . ' learner record(s)';
require APP_ROOT . '/templates/print-head.php';

foreach ($learners as $st):
    $sid = (int) $st['id'];
    $enrollments = db_all(
        'SELECT sec.id, sec.grade_level, sec.strand, sec.name AS section_name, sy.label AS sy_label, u.full_name AS adviser
           FROM enrollments e JOIN sections sec ON sec.id = e.section_id JOIN school_years sy ON sy.id = e.school_year_id
           LEFT JOIN users u ON u.id = sec.adviser_id
          WHERE e.student_id = ? ORDER BY sy.label',
        [$sid]
    );
    ?>
    <section class="page">
        <?php require APP_ROOT . '/templates/deped-header.php'; ?>
        <h1>LEARNER'S PERMANENT ACADEMIC RECORD (SF10)</h1>
        <div class="info">
            <div><span>Last name</span><strong><?= e(mb_strtoupper($st['last_name'])) ?></strong></div>
            <div><span>First name</span><strong><?= e($st['first_name']) ?></strong></div>
            <div><span>Middle name</span><?= e($st['middle_name']) ?></div>
            <div><span>LRN</span><?= e($st['lrn']) ?></div>
            <div><span>Birthdate</span><?= e(date('F j, Y', strtotime($st['birthdate']))) ?></div>
            <div><span>Sex</span><?= e($st['sex'] === 'F' ? 'Female' : ($st['sex'] === 'M' ? 'Male' : '')) ?></div>
            <div><span>Status</span><?= e(ucwords(str_replace('_', ' ', $st['status']))) ?><?= $st['status_note'] ? ' — ' . e($st['status_note']) : '' ?></div>
            <div><span>Address</span><?= e($st['address']) ?></div>
        </div>

        <?php foreach ($enrollments as $en):
            $results = Forms::semesterResults($sid, (int) $en['id']);
            foreach ($results as $sem => $r):
                if (!$r['subjects']) { continue; }
                $quarters = semester_quarters($sem); ?>
                <h2>Grade <?= (int) $en['grade_level'] ?> · <?= e(semester_label($sem)) ?> · S.Y. <?= e($en['sy_label']) ?></h2>
                <p class="small" style="margin: 0 0 3px">School: <?= e(setting('school_name')) ?> · Track/strand: <?= e($en['strand']) ?> · Section: <?= e($en['section_name']) ?> · Adviser: <?= e($en['adviser'] ?? '') ?></p>
                <table>
                    <tr><th style="width: 15%">Indicate if subject is Core, Applied or Specialized</th><th>Subjects</th>
                        <?php foreach ($quarters as $q): ?><th class="c" style="width: 9%">Q<?= $q ?></th><?php endforeach; ?>
                        <th class="c" style="width: 11%">Sem final grade</th><th class="c" style="width: 11%">Action taken</th></tr>
                    <?php foreach ($r['subjects'] as $s): ?>
                        <tr><td class="small"><?= e(SUBJECT_TYPES[$s['type']] ?? '') ?></td><td><?= e($s['name']) ?></td>
                            <td class="c"><?= e(format_grade($s['q'][0])) ?></td><td class="c"><?= e(format_grade($s['q'][1])) ?></td>
                            <td class="c"><strong><?= $s['final'] ?? '' ?></strong></td><td class="c"><?= e($s['final'] === null ? '' : mb_strtoupper($s['remarks'])) ?></td></tr>
                    <?php endforeach; ?>
                    <tr><td colspan="4" style="text-align: right"><strong>General average for the semester</strong></td><td class="c"><strong><?= $r['average'] ?? '' ?></strong></td><td class="c"><?= e($r['average'] !== null ? mb_strtoupper(remarks($r['average'])) : '') ?></td></tr>
                </table>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!$enrollments): ?><p>No school records yet.</p><?php endif; ?>

        <div class="sign">
            <div>Prepared by: Registrar</div>
            <div><?= e(setting('principal_name')) ?><br>School Head</div>
        </div>
        <p class="small" style="margin-top: 10px">Printed <?= e(date('F j, Y')) ?> from the <?= e(config('app_name')) ?>.</p>
    </section>
<?php endforeach; ?>
</body>
</html>
