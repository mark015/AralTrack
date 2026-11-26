<?php
// Announcements and the school calendar, for every signed-in user.
require dirname(__DIR__) . '/src/bootstrap.php';

$staff = current_staff();
if ($staff) {
    $staff = require_staff();
} elseif (current_parent()) {
    require_parent();
} elseif (current_student_id() === null) {
    flash('warning', 'Please sign in to see announcements.');
    redirect('learner/login.php');
}
$viewer = $staff ? 'staff' : 'families';

$month = get_str('month');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
    $month = date('Y-m');
}
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));
$events = News::between($from, $to);
$announcements = News::announcements($viewer, 50);
Access::requireModule('news');
$canEdit = $staff && (is_admin($staff) || $staff['role'] === 'principal');

$title = 'Announcements and calendar';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Announcements and calendar</h1>
        <p class="sub">News from the school, holidays, class suspensions, exams and events.</p>
    </div>
    <?php if ($canEdit): ?>
        <a class="btn btn-primary" href="<?= e(url('admin/announcements.php')) ?>"><i class="bi bi-plus-lg me-1"></i>Post or add an event</a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <h2 class="section-title">Announcements</h2>
        <?php if (!$announcements): ?>
            <div class="card"><div class="card-body text-secondary text-center py-5"><i class="bi bi-megaphone fs-2 d-block mb-2"></i>No announcements yet.</div></div>
        <?php endif; ?>
        <?php foreach ($announcements as $a): ?>
            <article class="card mb-3" id="a<?= (int) $a['id'] ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2 mb-1">
                        <h3 class="h5 mb-0"><?php if ($a['pinned']): ?><i class="bi bi-pin-angle-fill text-warning me-1" title="Pinned"></i><?php endif; ?><?= e($a['title']) ?></h3>
                        <?php if ($staff && $a['audience'] !== 'everyone'): ?>
                            <span class="badge text-bg-secondary align-self-start"><?= $a['audience'] === 'staff' ? 'Staff only' : 'Learners & parents' ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-secondary mb-2"><?= e($a['author'] ?? 'School') ?> · <?= e(when_label($a['created_at'])) ?></div>
                    <div style="white-space: pre-line"><?= e($a['body']) ?></div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <div class="col-lg-5" id="calendar">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h2 class="section-title mb-0">Calendar</h2>
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary" href="<?= e(url('news.php?month=' . date('Y-m', strtotime($from . ' -1 month')) . '#calendar')) ?>" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
                <span class="btn btn-outline-secondary disabled fw-semibold"><?= e(date('F Y', strtotime($from))) ?></span>
                <a class="btn btn-outline-secondary" href="<?= e(url('news.php?month=' . date('Y-m', strtotime($from . ' +1 month')) . '#calendar')) ?>" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
        <div class="card">
            <ul class="list-group list-group-flush">
                <?php foreach ($events as $ev): [$label, $tone, $icon] = News::EVENT_TYPES[$ev['type']]; ?>
                    <li class="list-group-item d-flex gap-3">
                        <div class="text-center" style="width: 3rem">
                            <div class="small text-secondary text-uppercase"><?= e(date('M', strtotime($ev['starts_on']))) ?></div>
                            <div class="fs-4 fw-bold lh-1"><?= e(date('j', strtotime($ev['starts_on']))) ?></div>
                        </div>
                        <div>
                            <span class="badge text-bg-<?= e($tone) ?>"><i class="bi bi-<?= e($icon) ?>"></i><?= e($label) ?></span>
                            <div class="fw-semibold mt-1"><?= e($ev['title']) ?></div>
                            <div class="small text-secondary"><?= e(News::dateRange($ev)) ?><?= $ev['details'] ? ' · ' . e($ev['details']) : '' ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$events): ?><li class="list-group-item text-secondary small">Nothing on the calendar in <?= e(date('F', strtotime($from))) ?>.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
