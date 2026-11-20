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
        <p class="sub">Absences, lates, changed attendance marks and new grades. Shown here only; nothing is sent by text or email.</p>
    </div>
</div>
<?php if (!$items): ?>
    <div class="card"><div class="card-body text-center text-secondary py-5"><i class="bi bi-bell-slash fs-2 d-block mb-2"></i>No notifications yet.</div></div>
<?php else: ?>
    <div class="list-group shadow-sm">
        <?php foreach ($items as $n):
            [$tone, $icon] = ['absent' => ['rose', 'x-circle-fill'], 'late' => ['gold', 'clock-fill'], 'changed' => ['teal', 'pencil-square'],
                'grades' => ['green', 'journal-check'], 'announcement' => ['', 'megaphone-fill'], 'excuse' => ['violet', 'envelope-check'],
                'request' => ['teal', 'file-earmark-text'], 'message' => ['', 'chat-dots-fill']][$n['type']] ?? ['', 'bell']; ?>
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
