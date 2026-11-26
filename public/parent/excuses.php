<?php
// Parents send an excuse letter for days their child missed (or will miss). The adviser reviews it.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$parent = require_parent();
Access::requireModule('excuses');
$children = db_all(
    'SELECT st.* FROM parent_students ps JOIN students st ON st.id = ps.student_id WHERE ps.parent_id = ? ORDER BY st.first_name',
    [$parent['id']]
);
$childIds = array_map('intval', array_column($children, 'id'));
$error = null;
$form = ['student_id' => $childIds[0] ?? 0, 'date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d'), 'reason' => ''];

if (is_post()) {
    $form = ['student_id' => post_int('student_id'), 'date_from' => post('date_from'), 'date_to' => post('date_to') ?: post('date_from'), 'reason' => post('reason')];
    if (!in_array($form['student_id'], $childIds, true)) {
        $error = 'Choose your child.';
    } elseif (!valid_ymd($form['date_from']) || !valid_ymd($form['date_to']) || $form['date_to'] < $form['date_from']) {
        $error = 'Enter valid dates (the last day can\'t be before the first).';
    } elseif ((strtotime($form['date_to']) - strtotime($form['date_from'])) / 86400 > 31) {
        $error = 'One letter can cover at most a month.';
    } elseif ($form['date_from'] < date('Y-m-d', strtotime('-60 days'))) {
        $error = 'Letters can only be sent for the last 60 days.';
    } elseif (mb_strlen($form['reason']) < 5) {
        $error = 'Write the reason for the absence.';
    } else {
        try {
            $file = Files::store($_FILES['file'] ?? null, 'excuse');
            db_exec(
                'INSERT INTO excuse_letters (workspace_id, student_id, parent_id, date_from, date_to, reason, file_path, file_name, file_mime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [ws(), $form['student_id'], $parent['id'], $form['date_from'], $form['date_to'], mb_substr($form['reason'], 0, 1000), $file['path'] ?? null, $file['name'] ?? null, $file['mime'] ?? null]
            );
            flash('success', 'Excuse letter sent. The adviser will review it, and you will get a notification.');
            redirect('parent/excuses.php');
        } catch (UserFacingException $e) {
            $error = $e->getMessage();
        }
    }
}

$letters = $childIds ? db_all(
    'SELECT el.*, st.first_name, u.full_name AS reviewer FROM excuse_letters el JOIN students st ON st.id = el.student_id
       LEFT JOIN users u ON u.id = el.reviewed_by
      WHERE el.student_id IN (' . implode(',', $childIds) . ') ORDER BY el.id DESC LIMIT 50'
) : [];

$title = 'Excuse letters';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Excuse letters</h1>
        <p class="sub">Tell the adviser why your child was (or will be) absent. Once approved, the absences show as Excused.</p>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <h2 class="h6"><i class="bi bi-envelope-paper me-2"></i>New excuse letter</h2>
                <?php if (!$children): ?>
                    <p class="small text-secondary mb-0">Link your child first under <a href="<?= e(url('parent/link.php')) ?>">Add a child</a>.</p>
                <?php else: ?>
                    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-x-octagon-fill"></i><div><?= e($error) ?></div></div><?php endif; ?>
                    <form method="post" action="<?= e(url('parent/excuses.php')) ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="child">Child</label>
                        <select class="form-select mb-2" id="child" name="student_id">
                            <?php foreach ($children as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $form['student_id'] ? 'selected' : '' ?>><?= e(student_name($c)) ?></option><?php endforeach; ?>
                        </select>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small" for="from">First day absent</label><input class="form-control" type="date" id="from" name="date_from" value="<?= e($form['date_from']) ?>" required></div>
                            <div class="col-6"><label class="form-label small" for="to">Last day</label><input class="form-control" type="date" id="to" name="date_to" value="<?= e($form['date_to']) ?>"></div>
                        </div>
                        <label class="form-label small" for="reason">Reason</label>
                        <textarea class="form-control mb-2" id="reason" name="reason" rows="3" maxlength="1000" required placeholder="e.g. Fever; seen by a doctor on Nov 18"><?= e($form['reason']) ?></textarea>
                        <label class="form-label small" for="file">Attachment <span class="text-secondary">(optional: photo of a medical certificate or a signed letter)</span></label>
                        <input class="form-control mb-3" type="file" id="file" name="file" accept="<?= e(Files::accept('excuse')) ?>">
                        <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Send to the adviser</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <?php if (!$letters): ?>
            <div class="card"><div class="card-body text-secondary text-center py-5"><i class="bi bi-envelope fs-2 d-block mb-2"></i>No excuse letters yet.</div></div>
        <?php endif; ?>
        <?php foreach ($letters as $l): ?>
            <div class="card mb-2">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="fw-semibold"><?= e($l['first_name']) ?> · <?= e(News::dateRange(['starts_on' => $l['date_from'], 'ends_on' => $l['date_to']])) ?></div>
                        <?= ['pending' => '<span class="badge text-bg-warning"><i class="bi bi-hourglass-split"></i>Waiting for review</span>', 'approved' => '<span class="badge text-bg-success"><i class="bi bi-check-circle-fill"></i>Approved</span>', 'rejected' => '<span class="badge text-bg-danger"><i class="bi bi-x-circle-fill"></i>Not approved</span>'][$l['status']] ?>
                    </div>
                    <div class="small mt-1"><?= e($l['reason']) ?></div>
                    <div class="small text-secondary mt-1">
                        Sent <?= e(when_label($l['created_at'])) ?>
                        <?php if ($l['file_path']): ?> · <a href="<?= e(url('file.php?kind=excuse&id=' . $l['id'])) ?>" target="_blank"><i class="bi bi-paperclip"></i><?= e($l['file_name']) ?></a><?php endif; ?>
                        <?php if ($l['reviewer']): ?> · Reviewed by <?= e($l['reviewer']) ?><?= $l['review_note'] ? ': "' . e($l['review_note']) . '"' : '' ?><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
