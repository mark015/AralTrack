<?php
// Grading periods: quarter deadlines (after which grades lock) and grade change requests.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin', 'principal');
$sy = active_school_year();
$here = 'admin/grading.php';

if (is_post()) {
    if (post('action') === 'deadlines' && $sy && is_admin($staff)) {
        foreach (QUARTERS_ALL as $q) {
            $date = post('q' . $q);
            if ($date === '') {
                db_exec('DELETE FROM grade_deadlines WHERE school_year_id = ? AND quarter = ?', [$sy['id'], $q]);
            } elseif (valid_ymd($date)) {
                db_exec(
                    'INSERT INTO grade_deadlines (school_year_id, quarter, deadline) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE deadline = VALUES(deadline)',
                    [$sy['id'], $q, $date]
                );
            }
        }
        Access::log('grades', 'Saved the grading deadlines for S.Y. ' . $sy['label'], 'school_year', (int) $sy['id']);
        flash('success', 'Deadlines saved. Each quarter locks the day after its deadline.');
    } elseif (in_array(post('action'), ['approve', 'reject'], true)) {
        $request = db_one("SELECT * FROM grade_change_requests WHERE id = ? AND status = 'pending' AND workspace_id = ?", [post_int('id'), ws()]);
        $note = mb_substr(post('note'), 0, 500);
        if (!$request) {
            flash('warning', 'That request was already reviewed.');
        } elseif (post('action') === 'approve') {
            Grading::approve($request, $staff['id'], $note);
            Access::log('grades', 'Approved a grade change request (Q' . $request['quarter'] . ': ' . format_grade($request['old_grade']) . ' → ' . ($request['new_grade'] === null ? 'removed' : format_grade($request['new_grade'])) . ')', 'grade_request', (int) $request['id']);
            flash('success', 'Approved. The grade was changed and recorded in the grade history.');
        } else {
            db_exec(
                "UPDATE grade_change_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?",
                [$staff['id'], date('Y-m-d H:i:s'), $note ?: null, $request['id']]
            );
            Access::log('grades', 'Rejected a grade change request (Q' . $request['quarter'] . ')', 'grade_request', (int) $request['id']);
            flash('info', 'Request rejected. The teacher sees your note on the class page.');
        }
    }
    redirect($here);
}

$deadlines = $sy ? Grading::deadlines((int) $sy['id']) : [];
$requestSql = 'SELECT r.*, st.last_name, st.first_name, st.middle_name, sub.name AS subject_name,
                      sec.grade_level, sec.strand, sec.name AS section_name, u.full_name AS teacher, rv.full_name AS reviewer
                 FROM grade_change_requests r
                 JOIN students st          ON st.id = r.student_id
                 JOIN class_assignments ca ON ca.id = r.class_assignment_id
                 JOIN subjects sub         ON sub.id = ca.subject_id
                 JOIN sections sec         ON sec.id = ca.section_id
                 LEFT JOIN users u         ON u.id = r.requested_by
                 LEFT JOIN users rv        ON rv.id = r.reviewed_by';
$pending = db_all($requestSql . " WHERE r.status = 'pending' AND r.workspace_id = ? ORDER BY r.id", [ws()]);
$recent = db_all($requestSql . " WHERE r.status <> 'pending' AND r.workspace_id = ? ORDER BY r.reviewed_at DESC LIMIT 15", [ws()]);

$title = 'Grading periods';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Grading periods and grade changes</h1>
        <p class="sub">After a quarter's deadline its grades lock. Teachers then send change requests, which you approve here.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= e(url('admin/grade-history.php')) ?>"><i class="bi bi-clock-history me-1"></i>Grade history</a>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h2 class="h6"><i class="bi bi-calendar-check me-2"></i>Deadlines<?= $sy ? ' · S.Y. ' . e($sy['label']) : '' ?></h2>
                <?php if (!$sy): ?>
                    <p class="small text-secondary mb-0">Set an active school year first.</p>
                <?php else: ?>
                    <form method="post" action="<?= e(url($here)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="deadlines">
                        <?php foreach (QUARTERS_ALL as $q): ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <label class="form-label mb-0" style="width: 6rem" for="q<?= $q ?>">Quarter <?= $q ?></label>
                                <input class="form-control form-control-sm" type="date" id="q<?= $q ?>" name="q<?= $q ?>" value="<?= e($deadlines[$q] ?? '') ?>" <?= is_admin($staff) ? '' : 'disabled' ?>>
                                <?php if (Grading::isLocked((int) $sy['id'], $q)): ?><i class="bi bi-lock-fill text-danger" title="Locked"></i><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <p class="small text-secondary">Leave a date blank for no deadline. Q1–Q2 are 1st semester, Q3–Q4 are 2nd semester.</p>
                        <?php if (is_admin($staff)): ?><button class="btn btn-primary btn-sm">Save deadlines</button><?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <h2 class="section-title">Waiting for approval (<?= count($pending) ?>)</h2>
        <?php if (!$pending): ?>
            <div class="card mb-4"><div class="card-body text-secondary"><i class="bi bi-check2-all me-2"></i>No pending change requests.</div></div>
        <?php endif; ?>
        <?php foreach ($pending as $r): ?>
            <div class="card mb-2 border-warning">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold"><?= e(student_name($r)) ?> · <?= e($r['subject_name']) ?> Q<?= (int) $r['quarter'] ?></div>
                            <div class="small text-secondary"><?= e(section_label($r)) ?> · requested by <?= e($r['teacher'] ?? '') ?> · <?= e(when_label($r['created_at'])) ?></div>
                        </div>
                        <div class="fs-5 text-nowrap"><?= $r['old_grade'] === null ? '–' : e(format_grade($r['old_grade'])) ?> <i class="bi bi-arrow-right"></i> <strong><?= $r['new_grade'] === null ? 'remove' : e(format_grade($r['new_grade'])) ?></strong></div>
                    </div>
                    <div class="small mt-2"><i class="bi bi-chat-left-text me-1"></i><?= e($r['reason']) ?></div>
                    <form method="post" action="<?= e(url($here)) ?>" class="d-flex flex-wrap gap-2 mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <input class="form-control form-control-sm flex-grow-1" style="min-width: 12rem" name="note" maxlength="500" placeholder="Note to the teacher (optional)">
                        <button class="btn btn-sm btn-success" name="action" value="approve"><i class="bi bi-check-lg me-1"></i>Approve</button>
                        <button class="btn btn-sm btn-outline-danger" name="action" value="reject">Reject</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($recent): ?>
            <h2 class="section-title mt-4">Recently decided</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle shadow-sm">
                    <thead><tr><th>Learner</th><th>Class</th><th>Change</th><th>Decision</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><?= e(student_name($r)) ?></td>
                            <td class="small"><?= e($r['subject_name']) ?> Q<?= (int) $r['quarter'] ?><div class="text-secondary"><?= e(section_label($r)) ?></div></td>
                            <td class="text-nowrap"><?= $r['old_grade'] === null ? '–' : e(format_grade($r['old_grade'])) ?> → <?= $r['new_grade'] === null ? 'removed' : e(format_grade($r['new_grade'])) ?></td>
                            <td class="small"><?= $r['status'] === 'approved' ? '<span class="badge text-bg-success">Approved</span>' : '<span class="badge text-bg-danger">Rejected</span>' ?> <?= e($r['reviewer'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
