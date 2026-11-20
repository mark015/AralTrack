<?php
/**
 * Latest announcements and upcoming calendar events, for dashboards.
 * @var string $newsViewer 'staff' or 'families'
 */
if (!Access::can('news')) {
    return; // News & calendar is turned off by the Super Admin
}
$widgetNews = News::announcements($newsViewer, 3);
$widgetEvents = News::upcoming(5);
?>
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0"><i class="bi bi-megaphone-fill text-primary me-2"></i>Announcements</h2>
                    <a class="small fw-semibold" href="<?= e(url('news.php')) ?>">All <i class="bi bi-arrow-right"></i></a>
                </div>
                <?php if (!$widgetNews): ?>
                    <p class="small text-secondary mb-0">No announcements yet.</p>
                <?php endif; ?>
                <?php foreach ($widgetNews as $a): ?>
                    <a class="d-block text-decoration-none text-body py-2 border-bottom" href="<?= e(url('news.php#a' . $a['id'])) ?>">
                        <?php if ($a['pinned']): ?><i class="bi bi-pin-angle-fill text-warning me-1"></i><?php endif; ?>
                        <span class="fw-semibold"><?= e($a['title']) ?></span>
                        <span class="small text-secondary">· <?= e(when_label($a['created_at'])) ?></span>
                        <div class="small text-secondary text-truncate"><?= e($a['body']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0"><i class="bi bi-calendar3 text-primary me-2"></i>Coming up</h2>
                    <a class="small fw-semibold" href="<?= e(url('news.php')) ?>#calendar">Calendar <i class="bi bi-arrow-right"></i></a>
                </div>
                <?php if (!$widgetEvents): ?>
                    <p class="small text-secondary mb-0">Nothing on the school calendar yet.</p>
                <?php endif; ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($widgetEvents as $ev): [$label, $tone, $icon] = News::EVENT_TYPES[$ev['type']]; ?>
                        <li class="d-flex gap-2 align-items-start py-2 border-bottom">
                            <span class="badge text-bg-<?= e($tone) ?> mt-1"><i class="bi bi-<?= e($icon) ?>"></i><?= e($label) ?></span>
                            <span><span class="fw-semibold small"><?= e($ev['title']) ?></span><br><span class="small text-secondary"><?= e(News::dateRange($ev)) ?></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
