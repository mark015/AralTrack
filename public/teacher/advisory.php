<?php
// Adviser overview: absences of every learner in my advisory section(s), across all subjects,
// with the linked parents' contact details for follow-up.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$sections = db_all(
    'SELECT s.*, s.name AS section_name, sy.label AS sy_label
       FROM sections s JOIN school_years sy ON sy.id = s.school_year_id AND sy.is_active = 1
      WHERE s.adviser_id = ?
      ORDER BY s.grade_level, s.strand, s.name',
    [$staff['id']]
);

$title = 'My advisory';
require APP_ROOT . '/templates/header.php';
?>
<h1 class="h3">My advisory</h1>
<?php if (!$sections): ?>
    <div class="alert alert-info">You are not the adviser of a section this school year.</div>
<?php endif; ?>

<?php foreach ($sections as $section):
    $sectionId = (int) $section['id'];
    $roster = section_roster($sectionId);
    $classes = db_all(
        'SELECT ca.id, ca.semester, sub.code, sub.name FROM class_assignments ca JOIN subjects sub ON sub.id = ca.subject_id
          WHERE ca.section_id = ? ORDER BY ca.semester, sub.type = \'core\' DESC, sub.sort_order, sub.name',
        [$sectionId]
    );
    $counts = [];
    $rows = db_all(
        "SELECT sa.student_id, cs.class_assignment_id, SUM(sa.status = 'absent') AS absent, SUM(sa.status = 'late') AS late
           FROM session_attendance sa
           JOIN class_sessions cs    ON cs.id = sa.session_id
           JOIN class_assignments ca ON ca.id = cs.class_assignment_id
          WHERE ca.section_id = ? AND cs.finished_at IS NOT NULL
          GROUP BY sa.student_id, cs.class_assignment_id",
        [$sectionId]
    );
    foreach ($rows as $row) {
        $counts[(int) $row['student_id']][(int) $row['class_assignment_id']] = ['absent' => (int) $row['absent'], 'late' => (int) $row['late']];
    }
    $parents = [];
    $rows = db_all(
        'SELECT ps.student_id, ps.relationship, p.full_name, p.login FROM parent_students ps
           JOIN parents p ON p.id = ps.parent_id AND p.is_active = 1
           JOIN enrollments e ON e.student_id = ps.student_id AND e.section_id = ?
          ORDER BY p.full_name',
        [$sectionId]
    );
    foreach ($rows as $row) {
        $parents[(int) $row['student_id']][] = $row;
    }
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4 mb-2">
        <h2 class="h5 mb-0"><?= e(section_label($section)) ?> <span class="text-secondary fw-normal small">S.Y. <?= e($section['sy_label']) ?></span></h2>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('teacher/values.php?section=' . $sectionId)) ?>"><i class="bi bi-stars me-1"></i>Observed values</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('print/sf9.php?section=' . $sectionId)) ?>" target="_blank"><i class="bi bi-printer me-1"></i>Print report cards (SF9)</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/forms.php')) ?>"><i class="bi bi-file-earmark-ruled me-1"></i>DepEd forms</a>
        </div>
    </div>
    <p class="small text-secondary">Absences per subject (lates in small text). Cells in red have 3 or more absences.</p>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-bordered align-middle bg-white">
            <thead class="table-light">
            <tr>
                <th scope="col">Learner</th>
                <?php foreach ($classes as $c): ?>
                    <th scope="col" class="text-center small" title="<?= e($c['name'] . ' · ' . semester_label((int) $c['semester'])) ?>">
                        <?= e($c['code']) ?><div class="fw-normal text-secondary"><?= (int) $c['semester'] === 1 ? '1st' : '2nd' ?></div>
                    </th>
                <?php endforeach; ?>
                <th scope="col" class="text-center">Total absences</th>
                <th scope="col">Parents</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($roster as $student):
                $sid = (int) $student['id'];
                $total = 0; ?>
                <tr>
                    <td><?= e(student_name($student)) ?></td>
                    <?php foreach ($classes as $c):
                        $cell = $counts[$sid][(int) $c['id']] ?? ['absent' => 0, 'late' => 0];
                        $total += $cell['absent']; ?>
                        <td class="text-center <?= $cell['absent'] >= 3 ? 'table-danger fw-semibold' : '' ?>">
                            <?= $cell['absent'] ?: '<span class="text-secondary">0</span>' ?>
                            <?php if ($cell['late']): ?><div class="small text-secondary"><?= $cell['late'] ?> late</div><?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="text-center fw-semibold"><?= $total ?></td>
                    <td class="small">
                        <?php foreach ($parents[$sid] ?? [] as $p): ?>
                            <div><?= e($p['full_name']) ?> (<?= e(RELATIONSHIPS[$p['relationship']]) ?>) · <?= e($p['login']) ?></div>
                        <?php endforeach; ?>
                        <?php if (empty($parents[$sid])): ?><span class="text-secondary">No parent account yet</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
