<?php
// Demo requests sent from the public home page.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$owner = require_owner();
const LEAD_STATUS = ['new' => ['New', 'danger'], 'contacted' => ['Contacted', 'primary'], 'closed' => ['Closed', 'secondary']];
const LEAD_KINDS = ['school' => 'School', 'teacher' => 'Teacher', 'other' => 'Other'];

if (is_post()) {
    $lead = db_one('SELECT * FROM platform_leads WHERE id = ?', [post_int('id')]);
    $status = post('status');
    if ($lead && post('action') === 'delete') {
        db_exec('DELETE FROM platform_leads WHERE id = ?', [$lead['id']]);
        platform_log('lead', "Deleted the demo request from {$lead['name']}");
        flash('success', 'Request deleted.');
    } elseif ($lead && isset(LEAD_STATUS[$status])) {
        db_exec('UPDATE platform_leads SET status = ? WHERE id = ?', [$status, $lead['id']]);
        flash('success', "Marked as " . strtolower(LEAD_STATUS[$status][0]) . '.');
    }
    redirect('platform/leads.php' . (get_str('show') !== '' ? '?show=' . rawurlencode(get_str('show')) : ''));
}

$show = isset(LEAD_STATUS[get_str('show')]) ? get_str('show') : '';
$leads = db_all(
    'SELECT * FROM platform_leads' . ($show !== '' ? ' WHERE status = ?' : '') . " ORDER BY status = 'new' DESC, id DESC LIMIT 300",
    $show !== '' ? [$show] : []
);

$title = 'Demo requests';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Demo requests</h1>
        <p class="sub">Sent with the form on the <a href="<?= e(url('index.php#start')) ?>">home page</a>. Reply by email or text, then mark them.</p>
    </div>
    <div class="btn-group" role="group" aria-label="Show">
        <a class="btn btn-sm btn-outline-secondary<?= $show === '' ? ' active' : '' ?>" href="<?= e(url('platform/leads.php')) ?>">All</a>
        <?php foreach (LEAD_STATUS as $value => [$label]): ?>
            <a class="btn btn-sm btn-outline-secondary<?= $show === $value ? ' active' : '' ?>" href="<?= e(url('platform/leads.php?show=' . $value)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!$leads): ?>
    <div class="card shadow-sm"><div class="card-body text-secondary">No demo requests<?= $show !== '' ? ' here' : ' yet' ?>.</div></div>
<?php endif; ?>
<div class="row g-3">
    <?php foreach ($leads as $lead): [$label, $tone] = LEAD_STATUS[$lead['status']]; ?>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2 mb-2">
                        <div>
                            <div class="fw-semibold"><?= e($lead['name']) ?> <span class="badge text-bg-light"><?= e(LEAD_KINDS[$lead['kind']] ?? $lead['kind']) ?></span></div>
                            <div class="small text-secondary"><?= e(when_label($lead['created_at'])) ?><?= $lead['school_name'] ? ' · ' . e($lead['school_name']) : '' ?><?= $lead['learners'] ? ' · about ' . e($lead['learners']) . ' learners' : '' ?></div>
                        </div>
                        <span class="badge text-bg-<?= e($tone) ?> align-self-start"><?= e($label) ?></span>
                    </div>
                    <div class="small mb-2">
                        <?php if ($lead['email']): ?><a href="mailto:<?= e($lead['email']) ?>"><i class="bi bi-envelope me-1" aria-hidden="true"></i><?= e($lead['email']) ?></a><?php endif; ?>
                        <?php if ($lead['phone']): ?><span class="ms-2"><i class="bi bi-phone me-1" aria-hidden="true"></i><?= e($lead['phone']) ?></span><?php endif; ?>
                    </div>
                    <?php if ($lead['message']): ?><p class="small mb-3" style="white-space: pre-line"><?= e($lead['message']) ?></p><?php endif; ?>
                    <form method="post" action="<?= e(url('platform/leads.php' . ($show !== '' ? '?show=' . $show : ''))) ?>" class="d-flex flex-wrap gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                        <?php foreach (LEAD_STATUS as $value => [$statusLabel]): if ($value === $lead['status']) continue; ?>
                            <button class="btn btn-sm btn-outline-primary" name="status" value="<?= e($value) ?>">Mark <?= e(strtolower($statusLabel)) ?></button>
                        <?php endforeach; ?>
                        <button class="btn btn-sm btn-outline-danger ms-auto" name="action" value="delete" onclick="return confirm('Delete this request?')">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
