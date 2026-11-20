<?php
// Every grade change: who changed it, when, old → new, and how (grid, upload, change request, class record).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin', 'principal');
$q = get_str('q');
$classId = get_int('class');
$where = [];
$params = [];
if ($classId) {
    $where[] = 'h.class_assignment_id = ?';
    $params[] = $classId;
}
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(st.lrn LIKE ? OR st.last_name LIKE ? OR st.first_name LIKE ?)';
    array_push($params, $like, $like, $like);
}
$rows = db_all(
    'SELECT h.*, st.lrn, st.last_name, st.first_name, st.middle_name, sub.name AS subject_name,
            sec.grade_level, sec.strand, sec.name AS section_name, u.full_name AS changed_by_name
       FROM grade_history h
       JOIN students st          ON st.id = h.student_id
       JOIN class_assignments ca ON ca.id = h.class_assignment_id
       JOIN subjects sub         ON sub.id = ca.subject_id
       JOIN sections sec         ON sec.id = ca.section_id
       LEFT JOIN users u         ON u.id = h.changed_by'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY h.id DESC LIMIT 300',
    $params
);
$sources = ['grid' => ['Grade table', 'secondary'], 'upload' => ['Excel upload', 'primary'], 'change_request' => ['Change request', 'warning'], 'class_record' => ['Class record', 'info']];

$title = 'Grade history';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Grade history</h1>
        <p class="sub">Every grade that was entered, changed or removed. Newest first (up to 300).</p>
    </div>
    <form method="get" action="<?= e(url('admin/grade-history.php')) ?>" class="d-flex gap-2" role="search">
        <?php if ($classId): ?><input type="hidden" name="class" value="<?= $classId ?>"><?php endif; ?>
        <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Learner name or LRN" aria-label="Search">
        <button class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
        <?php if ($q !== '' || $classId): ?><a class="btn btn-link" href="<?= e(url('admin/grade-history.php')) ?>">Clear</a><?php endif; ?>
    </form>
</div>
<div class="table-responsive">
    <table class="table table-sm align-middle shadow-sm">
        <thead><tr><th>When</th><th>Learner</th><th>Class</th><th>Q</th><th>Change</th><th>How</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $h): [$label, $tone] = $sources[$h['source']]; ?>
            <tr>
                <td class="small text-nowrap"><?= e(when_label($h['created_at'])) ?></td>
                <td><?= e(student_name($h)) ?><div class="lrn small text-secondary"><?= e($h['lrn']) ?></div></td>
                <td class="small"><a href="<?= e(url('admin/grade-history.php?class=' . $h['class_assignment_id'])) ?>"><?= e($h['subject_name']) ?></a><div class="text-secondary"><?= e(section_label($h)) ?></div></td>
                <td>Q<?= (int) $h['quarter'] ?></td>
                <td class="text-nowrap"><?= $h['old_grade'] === null ? '<span class="text-secondary">new</span>' : e(format_grade($h['old_grade'])) ?> → <strong><?= $h['new_grade'] === null ? 'removed' : e(format_grade($h['new_grade'])) ?></strong></td>
                <td><span class="badge text-bg-<?= e($tone) ?>"><?= e($label) ?></span></td>
                <td class="small"><?= e($h['changed_by_name'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-secondary">No grade changes recorded<?= $q !== '' || $classId ? ' for this search' : ' yet. Changes are recorded from now on' ?>.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
