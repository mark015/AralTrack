<?php
// Learners and parents ask the registrar for a document (certificate of enrollment, good moral, SF9, SF10)
// and follow its status. Parents get a notification when the status changes.
require dirname(__DIR__) . '/src/bootstrap.php';

if (current_staff()) {
    redirect('admin/requests.php');
}
if (current_parent()) {
    $parent = require_parent();
    $who = 'parent';
    $requesterId = (int) $parent['id'];
    $children = db_all(
        'SELECT st.* FROM parent_students ps JOIN students st ON st.id = ps.student_id WHERE ps.parent_id = ? ORDER BY st.first_name',
        [$parent['id']]
    );
} else {
    $student = require_student();
    $who = 'learner';
    $requesterId = (int) $student['id'];
    $children = [$student];
}
Access::requireModule('requests');
$childIds = array_map('intval', array_column($children, 'id'));
$error = null;
$form = ['student_id' => $childIds[0] ?? 0, 'doc_type' => 'enrollment', 'purpose' => '', 'copies' => 1];
const OPEN_REQUESTS_LIMIT = 5;

if (is_post()) {
    if (post('action') === 'cancel') {
        $count = db_exec(
            "DELETE FROM document_requests WHERE id = ? AND status = 'pending' AND requested_by_type = ? AND requested_by_id = ?",
            [post_int('request_id'), $who, $requesterId]
        );
        flash($count ? 'success' : 'warning', $count ? 'Request cancelled.' : 'That request is already being prepared, so it can no longer be cancelled here. Contact the registrar.');
        redirect('requests.php');
    }

    $form = ['student_id' => post_int('student_id'), 'doc_type' => post('doc_type'), 'purpose' => mb_substr(trim(post('purpose')), 0, 255), 'copies' => post_int('copies')];
    if (!in_array($form['student_id'], $childIds, true)) {
        $error = 'Choose the learner.';
    } elseif (!isset(DocumentRequests::TYPES[$form['doc_type']])) {
        $error = 'Choose a document.';
    } elseif (mb_strlen($form['purpose']) < 3) {
        $error = $form['doc_type'] === 'other' ? 'Write which document you need and why.' : 'Write the purpose, e.g. "Scholarship application" or "Transfer to another school".';
    } elseif ($form['copies'] < 1 || $form['copies'] > 5) {
        $error = 'You can ask for 1 to 5 copies.';
    } elseif ((int) db_value("SELECT COUNT(*) FROM document_requests WHERE student_id = ? AND status IN ('pending', 'processing')", [$form['student_id']]) >= OPEN_REQUESTS_LIMIT) {
        $error = 'There are already ' . OPEN_REQUESTS_LIMIT . ' open requests for this learner. Wait for them to be ready first.';
    } else {
        db_exec(
            'INSERT INTO document_requests (student_id, requested_by_type, requested_by_id, doc_type, purpose, copies) VALUES (?, ?, ?, ?, ?, ?)',
            [$form['student_id'], $who, $requesterId, $form['doc_type'], $form['purpose'], $form['copies']]
        );
        flash('success', 'Request sent to the registrar. ' . ($who === 'parent' ? 'You will get a notification when it is ready.' : 'Check this page for updates.'));
        redirect('requests.php');
    }
}

$requests = $childIds ? db_all(
    'SELECT r.*, st.first_name, st.last_name FROM document_requests r JOIN students st ON st.id = r.student_id
      WHERE r.student_id IN (' . implode(',', $childIds) . ') ORDER BY r.id DESC LIMIT 50'
) : [];

$title = 'Document requests';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Document requests</h1>
        <p class="sub">Ask the registrar for a certificate or a copy of school records, then pick it up when it is ready.</p>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <h2 class="h6"><i class="bi bi-file-earmark-plus me-2"></i>New request</h2>
                <?php if (!$children): ?>
                    <p class="small text-secondary mb-0">Link your child first under <a href="<?= e(url('parent/link.php')) ?>">Add a child</a>.</p>
                <?php else: ?>
                    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-x-octagon-fill"></i><div><?= e($error) ?></div></div><?php endif; ?>
                    <form method="post" action="<?= e(url('requests.php')) ?>">
                        <?= csrf_field() ?>
                        <?php if ($who === 'parent'): ?>
                            <label class="form-label small" for="child">Learner</label>
                            <select class="form-select mb-2" id="child" name="student_id">
                                <?php foreach ($children as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $form['student_id'] ? 'selected' : '' ?>><?= e(student_name($c)) ?></option><?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="student_id" value="<?= (int) $children[0]['id'] ?>">
                        <?php endif; ?>
                        <label class="form-label small" for="doc">Document</label>
                        <select class="form-select mb-2" id="doc" name="doc_type">
                            <?php foreach (DocumentRequests::TYPES as $value => $label): ?><option value="<?= e($value) ?>" <?= $form['doc_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                        <label class="form-label small" for="purpose">Purpose</label>
                        <input class="form-control mb-2" id="purpose" name="purpose" maxlength="255" required value="<?= e($form['purpose']) ?>" placeholder="e.g. Scholarship application">
                        <label class="form-label small" for="copies">Copies</label>
                        <select class="form-select mb-3" id="copies" name="copies" style="max-width: 7rem">
                            <?php for ($n = 1; $n <= 5; $n++): ?><option <?= $form['copies'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endfor; ?>
                        </select>
                        <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Send request</button>
                    </form>
                    <p class="small text-secondary mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Documents are released at the registrar's office. Bring a valid ID; a parent or guardian may claim it for the learner.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <?php if (!$requests): ?>
            <div class="card"><div class="card-body text-center py-5">
                <div class="icon-bubble mx-auto mb-3"><i class="bi bi-file-earmark-text"></i></div>
                <p class="fw-semibold mb-1">No requests yet.</p>
                <p class="small text-secondary mb-0">Your requests and their status will be listed here.</p>
            </div></div>
        <?php else: ?>
            <div class="list-group shadow-sm">
                <?php foreach ($requests as $r): ?>
                    <div class="list-group-item py-3">
                        <div class="d-flex justify-content-between gap-2 flex-wrap">
                            <div>
                                <div class="fw-semibold"><?= e(DocumentRequests::TYPES[$r['doc_type']] ?? $r['doc_type']) ?><?= (int) $r['copies'] > 1 ? ' × ' . (int) $r['copies'] : '' ?></div>
                                <div class="small text-secondary">
                                    <?= $who === 'parent' ? e($r['first_name']) . ' · ' : '' ?><?= e($r['purpose']) ?> · sent <?= e(date('M j, Y', strtotime($r['created_at']))) ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <?= DocumentRequests::badge($r['status']) ?>
                                <?php if ($r['status'] !== 'pending'): ?><div class="small text-secondary"><?= e(when_label($r['updated_at'])) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <?php if ($r['admin_note']): ?><div class="small mt-2"><i class="bi bi-chat-left-text me-1 text-secondary"></i><?= e($r['admin_note']) ?></div><?php endif; ?>
                        <?php if ($r['status'] === 'pending' && $r['requested_by_type'] === $who && (int) $r['requested_by_id'] === $requesterId): ?>
                            <form method="post" action="<?= e(url('requests.php')) ?>" class="mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-sm btn-link text-danger p-0" onclick="return confirm('Cancel this request?')">Cancel request</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
