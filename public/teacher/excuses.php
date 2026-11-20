<?php
// Advisers (and the admin, principal and guidance counselor) review parents' excuse letters.
// Approving one turns the learner's absences in those days into Excused.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
Access::requireModule('excuses');
$all = sees_all_learners($staff);
$sections = advised_section_ids($staff);
if (!$all && !$sections) {
    abort(403, 'Only advisers, the registrar, the principal and the guidance counselor review excuse letters.');
}
$scope = $all ? '' : 'AND e.section_id IN (' . implode(',', $sections) . ')';
$sy = active_school_year();

$find = static function (int $id) use ($scope, $sy): ?array {
    return db_one(
        "SELECT el.*, st.first_name FROM excuse_letters el JOIN students st ON st.id = el.student_id
           JOIN enrollments e ON e.student_id = el.student_id AND e.school_year_id = ?
          WHERE el.id = ? {$scope}",
        [$sy['id'] ?? 0, $id]
    );
};

if (is_post()) {
    $letter = $find(post_int('id'));
    $note = mb_substr(post('note'), 0, 500);
    if (!$letter || $letter['status'] !== 'pending') {
        flash('warning', 'That letter was already reviewed or is not for your learners.');
    } elseif (post('action') === 'approve') {
        $range = News::dateRange(['starts_on' => $letter['date_from'], 'ends_on' => $letter['date_to']]);
        $marks = db_all(
            "SELECT sa.session_id FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
              WHERE sa.student_id = ? AND sa.status = 'absent' AND cs.session_date BETWEEN ? AND ?",
            [$letter['student_id'], $letter['date_from'], $letter['date_to']]
        );
        foreach ($marks as $m) {
            if ($session = Attendance::session((int) $m['session_id'])) {
                Attendance::mark($session, (int) $letter['student_id'], 'excused', 'Excuse letter: ' . $letter['reason'], $staff['id'], false);
            }
        }
        db_exec(
            "UPDATE excuse_letters SET status = 'approved', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?",
            [$staff['id'], date('Y-m-d H:i:s'), $note ?: null, $letter['id']]
        );
        Notifications::toParents((int) $letter['student_id'], 'excuse', sprintf(
            'Your excuse letter for %s (%s) was approved.%s',
            $letter['first_name'],
            $range,
            $marks ? ' ' . count($marks) . ' absence' . (count($marks) === 1 ? ' was' : 's were') . ' changed to Excused.' : ''
        ), 'parent/excuses.php');
        flash('success', 'Approved.' . ($marks ? ' ' . count($marks) . ' absence(s) changed to Excused.' : ' Classes in those days will be marked Excused instead of Absent.'));
    } elseif (post('action') === 'reject') {
        db_exec(
            "UPDATE excuse_letters SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?",
            [$staff['id'], date('Y-m-d H:i:s'), $note ?: null, $letter['id']]
        );
        Notifications::toParents((int) $letter['student_id'], 'excuse', sprintf(
            'Your excuse letter for %s was not approved.%s',
            $letter['first_name'],
            $note ? ' Note: "' . $note . '"' : ''
        ), 'parent/excuses.php');
        flash('info', 'Marked as not approved. The parent was notified.');
    }
    redirect('teacher/excuses.php');
}

$letters = db_all(
    "SELECT el.*, st.last_name, st.first_name, st.middle_name, sec.grade_level, sec.strand, sec.name AS section_name,
            p.full_name AS parent_name, u.full_name AS reviewer
       FROM excuse_letters el
       JOIN students st     ON st.id = el.student_id
       JOIN enrollments e   ON e.student_id = el.student_id AND e.school_year_id = ?
       JOIN sections sec    ON sec.id = e.section_id
       LEFT JOIN parents p  ON p.id = el.parent_id
       LEFT JOIN users u    ON u.id = el.reviewed_by
      WHERE 1 = 1 {$scope}
      ORDER BY el.status = 'pending' DESC, el.id DESC
      LIMIT 100",
    [$sy['id'] ?? 0]
);

$title = 'Excuse letters';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Excuse letters</h1>
        <p class="sub"><?= $all ? 'All learners' : 'Learners in your advisory' ?> · Approving turns those days' absences into Excused and notifies the parent.</p>
    </div>
</div>
<?php if (!$letters): ?>
    <div class="card"><div class="card-body text-secondary text-center py-5"><i class="bi bi-envelope-check fs-2 d-block mb-2"></i>No excuse letters yet.</div></div>
<?php endif; ?>
<div class="row g-3">
    <?php foreach ($letters as $l): ?>
        <div class="col-lg-6">
            <div class="card h-100<?= $l['status'] === 'pending' ? ' border-warning' : '' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold"><?= e(student_name($l)) ?></div>
                            <div class="small text-secondary"><?= e(section_label($l)) ?> · from <?= e($l['parent_name'] ?? 'a parent') ?></div>
                        </div>
                        <?= ['pending' => '<span class="badge text-bg-warning align-self-start">Pending</span>', 'approved' => '<span class="badge text-bg-success align-self-start">Approved</span>', 'rejected' => '<span class="badge text-bg-danger align-self-start">Not approved</span>'][$l['status']] ?>
                    </div>
                    <div class="mt-2"><i class="bi bi-calendar-range me-1"></i><strong><?= e(News::dateRange(['starts_on' => $l['date_from'], 'ends_on' => $l['date_to']])) ?></strong></div>
                    <div class="small mt-1" style="white-space: pre-line"><?= e($l['reason']) ?></div>
                    <?php if ($l['file_path']): ?>
                        <a class="small d-inline-block mt-1" href="<?= e(url('file.php?kind=excuse&id=' . $l['id'])) ?>" target="_blank"><i class="bi bi-paperclip"></i><?= e($l['file_name']) ?></a>
                    <?php endif; ?>
                    <?php if ($l['status'] === 'pending'): ?>
                        <form method="post" action="<?= e(url('teacher/excuses.php')) ?>" class="mt-3 d-flex flex-wrap gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <input class="form-control form-control-sm flex-grow-1" style="min-width: 12rem" name="note" maxlength="500" placeholder="Note to the parent (optional)">
                            <button class="btn btn-sm btn-success" name="action" value="approve"><i class="bi bi-check-lg me-1"></i>Approve</button>
                            <button class="btn btn-sm btn-outline-danger" name="action" value="reject">Don't approve</button>
                        </form>
                    <?php else: ?>
                        <div class="small text-secondary mt-2">Reviewed by <?= e($l['reviewer'] ?? '') ?> · <?= e(when_label((string) $l['reviewed_at'])) ?><?= $l['review_note'] ? ' · "' . e($l['review_note']) . '"' : '' ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
