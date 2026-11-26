<?php
// Super Admin: every sign-in attempt (staff, parents, learners) and every account, access or settings
// change, with filters and an Excel download.
//   superadmin/activity.php?tab=signins|changes&q=…&who=staff|parent|learner&result=ok|failed&from=…&to=…&page=N[&export=1]
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$staff = require_staff('superadmin');
const LOG_PER_PAGE = 100;
const WHO = ['staff' => 'Teachers & staff', 'parent' => 'Parents', 'learner' => 'Learners'];

if (is_post() && post('action') === 'keep') {
    $days = max(30, min(3650, post_int('days')));
    $old = Access::keepDays();
    save_settings(['log_keep_days' => (string) $days]);
    Access::log('settings', "Activity log kept for {$days} days (was {$old})", 'settings', null, ['log_keep_days' => [$old, $days]]);
    Access::prune();
    flash('success', "Log entries are now kept for {$days} days.");
    redirect('superadmin/activity.php?tab=' . (post('tab') === 'changes' ? 'changes' : 'signins'));
}

$tab = get_str('tab') === 'changes' ? 'changes' : 'signins';
$q = trim(get_str('q'));
$who = isset(WHO[get_str('who')]) ? get_str('who') : '';
$result = in_array(get_str('result'), ['ok', 'failed'], true) ? get_str('result') : '';
$from = valid_ymd(get_str('from')) ? get_str('from') : '';
$to = valid_ymd(get_str('to')) ? get_str('to') : '';
$page = max(1, get_int('page'));
$export = get_int('export') === 1;

$where = ['x.workspace_id = ?']; // this school's sign-ins and changes only
$params = [ws()];
if ($from !== '') {
    $where[] = 'x.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'x.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
if ($tab === 'signins') {
    if ($who !== '') {
        $where[] = 'x.account_type = ?';
        $params[] = $who;
    }
    if ($result !== '') {
        $where[] = 'x.success = ' . ($result === 'ok' ? '1' : '0');
    }
    if ($q !== '') {
        $where[] = '(x.identifier LIKE ? OR u.full_name LIKE ? OR p.full_name LIKE ? OR CONCAT(s.first_name, \' \', s.last_name) LIKE ? OR x.ip = ?)';
        array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%", $q);
    }
    $from_sql = "FROM login_events x
                 LEFT JOIN users u    ON x.account_type = 'staff' AND u.id = x.account_id
                 LEFT JOIN parents p  ON x.account_type = 'parent' AND p.id = x.account_id
                 LEFT JOIN students s ON x.account_type = 'learner' AND s.id = x.account_id";
    $select = "SELECT x.*, COALESCE(u.full_name, p.full_name, CONCAT(s.first_name, ' ', s.last_name)) AS name";
} else {
    if ($q !== '') {
        $where[] = '(x.actor_name LIKE ? OR x.summary LIKE ? OR x.action LIKE ?)';
        array_push($params, "%{$q}%", "%{$q}%", "%{$q}%");
    }
    $from_sql = 'FROM activity_log x';
    $select = 'SELECT x.*';
}
$whereSql = implode(' AND ', $where);

if ($export) {
    $rows = db_all("{$select} {$from_sql} WHERE {$whereSql} ORDER BY x.id DESC LIMIT 20000", $params);
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    if ($tab === 'signins') {
        $sheet->setTitle('Sign-ins');
        $sheet->fromArray(['Date and time', 'Who', 'Group', 'Typed', 'Result', 'Reason', 'IP address', 'Device'], null, 'A1');
        $data = array_map(static fn ($r) => [$r['created_at'], (string) $r['name'], WHO[$r['account_type']] ?? $r['account_type'], $r['identifier'], $r['success'] ? 'Signed in' : 'Failed', (string) $r['reason'], (string) $r['ip'], (string) $r['device']], $rows);
    } else {
        $sheet->setTitle('Changes');
        $sheet->fromArray(['Date and time', 'By', 'Action', 'What happened', 'IP address'], null, 'A1');
        $data = array_map(static fn ($r) => [$r['created_at'], $r['actor_name'], $r['action'], $r['summary'], (string) $r['ip']], $rows);
    }
    if ($data) {
        $sheet->fromArray($data, null, 'A2', true);
    }
    $sheet->getStyle('A1:H1')->getFont()->setBold(true);
    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    Access::log('export', 'Downloaded the ' . ($tab === 'signins' ? 'sign-in history' : 'activity log') . ' (' . count($rows) . ' rows)', null, null);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . ($tab === 'signins' ? 'sign-ins' : 'activity-log') . '-' . date('Ymd-His') . '.xlsx"');
    (new Xlsx($book))->save('php://output');
    exit;
}

$total = (int) db_value("SELECT COUNT(*) {$from_sql} WHERE {$whereSql}", $params);
$pages = max(1, (int) ceil($total / LOG_PER_PAGE));
$page = min($page, $pages);
$rows = db_all("{$select} {$from_sql} WHERE {$whereSql} ORDER BY x.id DESC LIMIT " . LOG_PER_PAGE . ' OFFSET ' . (($page - 1) * LOG_PER_PAGE), $params);
$query = static fn (array $extra = []): string => http_build_query(array_filter(['tab' => $tab, 'q' => $q, 'who' => $who, 'result' => $result, 'from' => $from, 'to' => $to] + $extra, static fn ($v) => $v !== '' && $v !== null));

$title = 'Activity log';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Activity log</h1>
        <p class="sub">Every sign-in attempt, and every account, access and settings change. Kept for <?= Access::keepDays() ?> days.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= e(url('superadmin/activity.php?' . $query(['export' => 1]))) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Download (Excel)</a>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link<?= $tab === 'signins' ? ' active" aria-current="page' : '' ?>" href="<?= e(url('superadmin/activity.php?tab=signins')) ?>"><i class="bi bi-door-open"></i>Sign-ins</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'changes' ? ' active" aria-current="page' : '' ?>" href="<?= e(url('superadmin/activity.php?tab=changes')) ?>"><i class="bi bi-pencil-square"></i>Changes</a></li>
</ul>

<form class="row g-2 mb-3" method="get" action="<?= e(url('superadmin/activity.php')) ?>">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="col-md-4"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= $tab === 'signins' ? 'Name, username, LRN or IP address' : 'Name or what changed' ?>" aria-label="Search"></div>
    <?php if ($tab === 'signins'): ?>
        <div class="col-6 col-md-2">
            <select class="form-select" name="who" aria-label="Group"><option value="">Everyone</option>
                <?php foreach (WHO as $key => $label): ?><option value="<?= e($key) ?>" <?= $who === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select class="form-select" name="result" aria-label="Result"><option value="">Any result</option>
                <option value="ok" <?= $result === 'ok' ? 'selected' : '' ?>>Signed in</option>
                <option value="failed" <?= $result === 'failed' ? 'selected' : '' ?>>Failed</option>
            </select>
        </div>
    <?php endif; ?>
    <div class="col-6 col-md-<?= $tab === 'signins' ? '1' : '2' ?>"><input class="form-control" type="date" name="from" value="<?= e($from) ?>" aria-label="From"></div>
    <div class="col-6 col-md-<?= $tab === 'signins' ? '1' : '2' ?>"><input class="form-control" type="date" name="to" value="<?= e($to) ?>" aria-label="To"></div>
    <div class="col-md-2 d-grid"><button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button></div>
</form>

<p class="small text-secondary mb-2"><?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?><?= $pages > 1 ? " · page {$page} of {$pages}" : '' ?></p>
<?php if (!$rows): ?>
    <p class="text-secondary">Nothing here yet.</p>
<?php elseif ($tab === 'signins'): ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle shadow-sm">
            <thead class="table-light"><tr><th>When</th><th>Who</th><th>Result</th><th>Device</th><th>IP address</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(when_label($r['created_at'])) ?></td>
                    <td><?= $r['name'] ? e($r['name']) : '<span class="text-secondary">Unknown</span>' ?><div class="small text-secondary"><?= e(WHO[$r['account_type']] ?? '') ?> · <span class="<?= $r['account_type'] === 'learner' ? 'lrn' : '' ?>"><?= e($r['identifier']) ?></span></div></td>
                    <td class="small"><?= $r['success'] ? '<span class="badge text-bg-success">Signed in</span>' : '<span class="badge text-bg-danger">Failed</span>' ?><?= $r['reason'] ? '<div class="text-secondary">' . e($r['reason']) . '</div>' : '' ?></td>
                    <td class="small"><?= e((string) $r['device']) ?></td>
                    <td class="small text-secondary"><?= e((string) $r['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle shadow-sm">
            <thead class="table-light"><tr><th>When</th><th>By</th><th>What happened</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(when_label($r['created_at'])) ?></td>
                    <td class="small"><?= e($r['actor_name']) ?><div class="text-secondary"><?= e((string) $r['ip']) ?></div></td>
                    <td class="small"><?= e($r['summary']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php if ($pages > 1): ?>
    <nav aria-label="Pages"><ul class="pagination pagination-sm flex-wrap">
        <?php for ($p = max(1, $page - 5); $p <= min($pages, $page + 5); $p++): ?>
            <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e(url('superadmin/activity.php?' . $query(['page' => $p]))) ?>"><?= $p ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<form method="post" action="<?= e(url('superadmin/activity.php')) ?>" class="d-flex flex-wrap align-items-center gap-2 mt-4 small">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="keep">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label for="keep-days" class="text-secondary">Keep log entries for</label>
    <input class="form-control form-control-sm" style="width: 6rem" type="number" min="30" max="3650" id="keep-days" name="days" value="<?= Access::keepDays() ?>">
    <span class="text-secondary">days</span>
    <button class="btn btn-sm btn-outline-primary">Save</button>
</form>
<?php require APP_ROOT . '/templates/footer.php'; ?>
