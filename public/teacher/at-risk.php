<?php
// Learners who need follow-up: failing grades or many absences. Teachers see their classes and
// advisory; the admin, principal and guidance counselor see the whole school.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$sy = active_school_year();
$all = sees_all_learners($staff);

if (is_post() && post('action') === 'note') {
    $studentId = post_int('student_id');
    $note = mb_substr(post('note'), 0, 1000);
    if ($note !== '' && staff_can_see_learner($staff, $studentId)) {
        db_exec(
            'INSERT INTO interventions (student_id, class_assignment_id, note, created_by) VALUES (?, ?, ?, ?)',
            [$studentId, post_int('class_id') ?: null, $note, $staff['id']]
        );
        flash('success', 'Follow-up note saved.');
    }
    redirect('teacher/at-risk.php' . ($all ? '' : ''));
}

$classIds = [];
if ($sy) {
    $classIds = array_column(db_all(
        $all
            ? 'SELECT ca.id FROM class_assignments ca JOIN sections s ON s.id = ca.section_id WHERE s.school_year_id = ?'
            : 'SELECT ca.id FROM class_assignments ca JOIN sections s ON s.id = ca.section_id
                WHERE s.school_year_id = ? AND (ca.teacher_id = ? OR s.adviser_id = ?)',
        $all ? [$sy['id']] : [$sy['id'], $staff['id'], $staff['id']]
    ), 'id');
}
$rows = AtRisk::find($classIds);
$latest = AtRisk::latestInterventions(array_column($rows, 'id'));
$history = [];
if ($rows) {
    $ids = implode(',', array_map('intval', array_unique(array_column($rows, 'id'))));
    foreach (db_all("SELECT i.*, u.full_name FROM interventions i LEFT JOIN users u ON u.id = i.created_by WHERE i.student_id IN ({$ids}) ORDER BY i.id DESC") as $i) {
        $history[(int) $i['student_id']][] = $i;
    }
}
$learnerCount = count(array_unique(array_column($rows, 'id')));

$title = 'At-risk learners';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">At-risk learners</h1>
        <p class="sub">
            <?= $all ? 'Whole school' : 'Your classes and advisory' ?> · A grade below <?= (int) config('passing_grade') ?>,
            or <?= (int) setting('at_risk_absences') ?>+ absences in a subject · <?= $learnerCount ?> learner<?= $learnerCount === 1 ? '' : 's' ?>
        </p>
    </div>
</div>
<?php if (!$rows): ?>
    <div class="card"><div class="card-body text-center py-5"><div class="icon-bubble green mx-auto mb-2"><i class="bi bi-emoji-smile"></i></div>No learners are at risk right now.</div></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle shadow-sm">
            <thead><tr><th>Learner</th><th>Subject</th><th>Why</th><th>Follow-up</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $sid = (int) $r['id']; $last = $latest[$sid] ?? null; ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2"><?= avatar($r['first_name'] . ' ' . $r['last_name']) ?>
                            <div><?= e(student_name($r)) ?><div class="small text-secondary"><?= e(section_label($r['class'])) ?></div></div></div>
                    </td>
                    <td class="small"><?= e($r['class']['subject_name']) ?><div class="text-secondary"><?= e($r['class']['teacher_name']) ?></div></td>
                    <td><?php foreach ($r['reasons'] as $reason): ?><span class="badge <?= str_contains($reason, 'absence') ? 'text-bg-warning' : 'text-bg-danger' ?> me-1 mb-1"><?= e($reason) ?></span><?php endforeach; ?></td>
                    <td style="min-width: 16rem">
                        <?php if ($last): ?>
                            <div class="small"><i class="bi bi-chat-left-text me-1"></i><?= e(mb_strimwidth($last['note'], 0, 90, '…')) ?></div>
                            <div class="small text-secondary"><?= e($last['full_name'] ?? '') ?> · <?= e(when_label($last['created_at'])) ?></div>
                        <?php else: ?>
                            <span class="small text-secondary">No follow-up yet</span>
                        <?php endif; ?>
                        <details class="mt-1">
                            <summary class="small text-primary" style="cursor: pointer">Add a note<?= count($history[$sid] ?? []) > 1 ? ' · history (' . count($history[$sid]) . ')' : '' ?></summary>
                            <form method="post" action="<?= e(url('teacher/at-risk.php')) ?>" class="mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="note">
                                <input type="hidden" name="student_id" value="<?= $sid ?>">
                                <input type="hidden" name="class_id" value="<?= (int) $r['class']['id'] ?>">
                                <textarea class="form-control form-control-sm mb-1" name="note" rows="2" maxlength="1000" placeholder="e.g. Talked to the parent; remedial class on Saturdays" required></textarea>
                                <button class="btn btn-sm btn-primary">Save note</button>
                            </form>
                            <?php foreach (array_slice($history[$sid] ?? [], 0, 6) as $h): ?>
                                <div class="small border-start ps-2 mt-2"><?= e($h['note']) ?><div class="text-secondary"><?= e($h['full_name'] ?? '') ?> · <?= e(when_label($h['created_at'])) ?></div></div>
                            <?php endforeach; ?>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
