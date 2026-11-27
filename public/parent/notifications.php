<?php
// All notifications for this parent, newest first. Opening the page marks them as read.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$parent = require_parent();
$items = db_all(
    'SELECT n.*, st.first_name FROM parent_notifications n LEFT JOIN students st ON st.id = n.student_id
      WHERE n.parent_id = ? ORDER BY n.id DESC LIMIT 100',
    [$parent['id']]
);
db_exec('UPDATE parent_notifications SET read_at = ? WHERE parent_id = ? AND read_at IS NULL', [date('Y-m-d H:i:s'), $parent['id']]);

$title = 'Notifications';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Notifications</h1>
        <p class="sub">Arriving at and leaving school, absences, lates, changed attendance marks, new grades and messages.</p>
    </div>
</div>
<?php if (Alerts::pushOn()): ?>
    <div class="card mb-4" id="push-card" hidden data-api="<?= e(url('parent/push.php')) ?>" data-csrf="<?= e(csrf_token()) ?>"
         data-key="<?= e(WebPush::publicKey()) ?>" data-sw="<?= e(url('sw.js')) ?>">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="icon-bubble teal"><i class="bi bi-phone-vibrate" aria-hidden="true"></i></div>
            <div class="flex-grow-1" style="min-width: 14rem">
                <h2 class="h6 mb-1">Alerts on this phone</h2>
                <div class="small text-secondary" data-push-status></div>
                <div class="small fw-semibold mt-1" data-push-note role="status"></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" data-push-on hidden><i class="bi bi-bell me-1"></i>Turn on alerts on this phone</button>
                <button type="button" class="btn btn-outline-primary" data-push-test hidden>Send a test alert</button>
                <button type="button" class="btn btn-outline-secondary" data-push-off hidden>Turn off</button>
            </div>
        </div>
    </div>
    <script src="<?= e(asset('assets/push.js')) ?>" defer></script>
<?php endif; ?>
<?php if (!$items): ?>
    <div class="card"><div class="card-body text-center text-secondary py-5"><i class="bi bi-bell-slash fs-2 d-block mb-2"></i>No notifications yet.</div></div>
<?php else: ?>
    <div class="list-group shadow-sm">
        <?php foreach ($items as $n):
            [$tone, $icon] = ['absent' => ['rose', 'x-circle-fill'], 'late' => ['gold', 'clock-fill'], 'changed' => ['teal', 'pencil-square'],
                'grades' => ['green', 'journal-check'], 'announcement' => ['', 'megaphone-fill'], 'excuse' => ['violet', 'envelope-check'],
                'request' => ['teal', 'file-earmark-text'], 'message' => ['', 'chat-dots-fill'],
                'arrived' => ['green', 'box-arrow-in-right'], 'left' => ['', 'box-arrow-right']][$n['type']] ?? ['', 'bell']; ?>
            <a class="list-group-item list-group-item-action notification py-3<?= $n['read_at'] === null ? ' unread' : '' ?>" href="<?= e(url($n['link'])) ?>">
                <div class="icon-bubble sm <?= e($tone) ?>"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></div>
                <div class="flex-grow-1">
                    <div><?= e($n['message']) ?></div>
                    <div class="small text-secondary mt-1">
                        <?= e($n['first_name'] ?? 'School') ?> · <?= e(when_label($n['created_at'])) ?>
                        <?php if ($n['read_at'] === null): ?><span class="badge text-bg-primary ms-1">New</span><?php endif; ?>
                    </div>
                </div>
                <i class="bi bi-chevron-right text-secondary align-self-center" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
