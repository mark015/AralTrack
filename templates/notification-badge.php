<?php
/** Small coloured label for a notification. @var array $n parent_notifications row */
$notificationLabel = [
    'absent' => ['Absent', 'text-bg-danger', 'x-circle-fill'],
    'late' => ['Late', 'text-bg-warning', 'clock-fill'],
    'changed' => ['Updated', 'text-bg-info', 'pencil-square'],
    'grades' => ['Grades', 'text-bg-success', 'journal-check'],
    'announcement' => ['News', 'text-bg-primary', 'megaphone-fill'],
    'excuse' => ['Excuse letter', 'text-bg-info', 'envelope-check'],
    'request' => ['Request', 'text-bg-info', 'file-earmark-text'],
    'message' => ['Message', 'text-bg-primary', 'chat-dots-fill'],
    'arrived' => ['At school', 'text-bg-success', 'box-arrow-in-right'],
    'left' => ['Left school', 'text-bg-secondary', 'box-arrow-right'],
][$n['type']] ?? ['Notice', 'text-bg-secondary', 'bell'];
?>
<span class="badge <?= e($notificationLabel[1]) ?> me-1"><i class="bi bi-<?= e($notificationLabel[2]) ?>" aria-hidden="true"></i><?= e($notificationLabel[0]) ?></span>
