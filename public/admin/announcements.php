<?php
// Post announcements and manage the school calendar (admin and principal).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin', 'principal');
$here = 'admin/announcements.php';

if (is_post()) {
    switch (post('action')) {
        case 'post':
            $title = post('title');
            $body = post('body');
            $audience = post('audience');
            if ($title === '' || mb_strlen($title) > 200 || $body === '' || !isset(News::AUDIENCES[$audience])) {
                flash('danger', 'Enter a title (up to 200 characters), the message, and who it is for.');
                break;
            }
            News::post($title, mb_substr($body, 0, 10000), $audience, post('pinned') === '1', $staff['id']);
            flash('success', 'Announcement posted.' . ($audience !== 'staff' ? ' Parents got a notification.' : ''));
            break;
        case 'delete_post':
            db_exec('DELETE FROM announcements WHERE id = ?', [post_int('id')]);
            flash('success', 'Announcement deleted.');
            break;
        case 'pin':
            db_exec('UPDATE announcements SET pinned = 1 - pinned WHERE id = ?', [post_int('id')]);
            break;
        case 'event':
            $title = post('title');
            $type = post('type');
            $start = post('starts_on');
            $end = post('ends_on') ?: $start;
            if ($title === '' || mb_strlen($title) > 200 || !isset(News::EVENT_TYPES[$type]) || !valid_ymd($start) || !valid_ymd($end) || $end < $start) {
                flash('danger', 'Enter a title, a type, and valid dates (the end date can\'t be before the start).');
                break;
            }
            db_exec(
                'INSERT INTO calendar_events (title, details, type, starts_on, ends_on, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                [$title, mb_substr(post('details'), 0, 500) ?: null, $type, $start, $end, $staff['id']]
            );
            flash('success', 'Added to the calendar.');
            break;
        case 'delete_event':
            db_exec('DELETE FROM calendar_events WHERE id = ?', [post_int('id')]);
            flash('success', 'Removed from the calendar.');
            break;
    }
    redirect($here);
}

$posts = db_all('SELECT a.*, u.full_name AS author FROM announcements a LEFT JOIN users u ON u.id = a.posted_by ORDER BY a.pinned DESC, a.id DESC LIMIT 50');
$events = db_all('SELECT * FROM calendar_events WHERE ends_on >= ? ORDER BY starts_on LIMIT 100', [date('Y-m-d', strtotime('-30 days'))]);

$title = 'Announcements';
require APP_ROOT . '/templates/header.php';
?>
<?= Access::offNotice('news') ?>
<div class="page-head">
    <div>
        <h1 class="h3">Announcements and calendar</h1>
        <p class="sub">Posts appear on everyone's dashboard. Parents get a notification for posts meant for them.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= e(url('news.php')) ?>"><i class="bi bi-eye me-1"></i>See what others see</a>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6"><i class="bi bi-megaphone me-2"></i>New announcement</h2>
                <form method="post" action="<?= e(url($here)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="post">
                    <input class="form-control mb-2" name="title" maxlength="200" placeholder="Title, e.g. Parent-Teacher Conference on Nov 28" required>
                    <textarea class="form-control mb-2" name="body" rows="4" placeholder="Message" required></textarea>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <select class="form-select w-auto" name="audience" aria-label="Who it is for">
                            <?php foreach (News::AUDIENCES as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="pinned" value="1" id="pinned">
                            <label class="form-check-label small" for="pinned">Pin to the top</label>
                        </div>
                        <button class="btn btn-primary ms-auto"><i class="bi bi-send me-1"></i>Post</button>
                    </div>
                </form>
            </div>
        </div>
        <?php foreach ($posts as $a): ?>
            <div class="card mb-2">
                <div class="card-body py-3 d-flex gap-3">
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?php if ($a['pinned']): ?><i class="bi bi-pin-angle-fill text-warning me-1"></i><?php endif; ?><?= e($a['title']) ?></div>
                        <div class="small text-secondary"><?= e(News::AUDIENCES[$a['audience']]) ?> · <?= e($a['author'] ?? '') ?> · <?= e(when_label($a['created_at'])) ?></div>
                    </div>
                    <form method="post" action="<?= e(url($here)) ?>" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="pin"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary" title="<?= $a['pinned'] ? 'Unpin' : 'Pin' ?>"><i class="bi bi-pin<?= $a['pinned'] ? '-fill' : '' ?>"></i></button></form>
                    <form method="post" action="<?= e(url($here)) ?>" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="delete_post"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this announcement?')" title="Delete"><i class="bi bi-trash"></i></button></form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="col-xl-5">
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6"><i class="bi bi-calendar-plus me-2"></i>Add to the calendar</h2>
                <form method="post" action="<?= e(url($here)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="event">
                    <input class="form-control mb-2" name="title" maxlength="200" placeholder="e.g. Typhoon class suspension" required>
                    <div class="row g-2 mb-2">
                        <div class="col-12">
                            <select class="form-select" name="type" aria-label="Type">
                                <?php foreach (News::EVENT_TYPES as $value => [$label]): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6"><label class="form-label small" for="from">From</label><input class="form-control" type="date" id="from" name="starts_on" required></div>
                        <div class="col-6"><label class="form-label small" for="to">To <span class="text-secondary">(optional)</span></label><input class="form-control" type="date" id="to" name="ends_on"></div>
                    </div>
                    <input class="form-control mb-2" name="details" maxlength="500" placeholder="Details (optional)">
                    <button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add</button>
                </form>
                <p class="small text-secondary mt-2 mb-0">On holidays and class suspensions, teachers see a reminder before taking attendance.</p>
            </div>
        </div>
        <div class="card">
            <ul class="list-group list-group-flush">
                <?php foreach ($events as $ev): [$label, $tone, $icon] = News::EVENT_TYPES[$ev['type']]; ?>
                    <li class="list-group-item d-flex gap-2 align-items-center">
                        <span class="badge text-bg-<?= e($tone) ?>"><i class="bi bi-<?= e($icon) ?>"></i><?= e($label) ?></span>
                        <span class="flex-grow-1 small"><strong><?= e($ev['title']) ?></strong><br><span class="text-secondary"><?= e(News::dateRange($ev)) ?></span></span>
                        <form method="post" action="<?= e(url($here)) ?>" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="delete_event"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this from the calendar?')" title="Remove"><i class="bi bi-trash"></i></button></form>
                    </li>
                <?php endforeach; ?>
                <?php if (!$events): ?><li class="list-group-item small text-secondary">No upcoming events.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
