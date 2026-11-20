<?php
// Registrar: document requests from learners and parents. Change the status (parents are notified)
// and print the document.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin', 'principal');
const REQUEST_FILTERS = [
    'open'  => ['Open', "r.status IN ('pending', 'processing')"],
    'ready' => ['Ready for pick-up', "r.status = 'ready'"],
    'done'  => ['Released / not approved', "r.status IN ('released', 'rejected')"],
    'all'   => ['All', '1 = 1'],
];
$filter = isset(REQUEST_FILTERS[get_str('status')]) ? get_str('status') : 'open';
$search = trim(get_str('q'));
$here = 'admin/requests.php?status=' . $filter . ($search !== '' ? '&q=' . rawurlencode($search) : '');

if (is_post() && post('action') === 'update') {
    $request = db_one(
        'SELECT r.*, st.first_name FROM document_requests r JOIN students st ON st.id = r.student_id WHERE r.id = ?',
        [post_int('request_id')]
    );
    $status = post('status');
    $note = mb_substr(trim(post('admin_note')), 0, 500);
    if (!$request || !isset(DocumentRequests::STATUSES[$status])) {
        flash('danger', 'Choose a request and a status.');
    } elseif ($status === 'rejected' && $note === '') {
        flash('danger', 'Add a note explaining why the request was not approved.');
    } else {
        db_exec(
            'UPDATE document_requests SET status = ?, admin_note = ?, handled_by = ? WHERE id = ?',
            [$status, $note !== '' ? $note : null, $staff['id'], $request['id']]
        );
        if ($status !== $request['status']) {
            $doc = DocumentRequests::TYPES[$request['doc_type']] ?? 'document';
            $message = match ($status) {
                'processing' => "The registrar is preparing the {$doc} for {$request['first_name']}.",
                'ready'      => "The {$doc} for {$request['first_name']} is ready for pick-up at the registrar's office.",
                'released'   => "The {$doc} for {$request['first_name']} has been released.",
                'rejected'   => "The request for a {$doc} for {$request['first_name']} was not approved: {$note}",
                default      => "The request for a {$doc} for {$request['first_name']} was updated.",
            };
            Notifications::toParents((int) $request['student_id'], 'request', mb_substr($message, 0, 500), 'requests.php');
        }
        flash('success', 'Request updated' . ($status !== $request['status'] ? ' — parents were notified.' : '.'));
    }
    redirect($here);
}

$where = REQUEST_FILTERS[$filter][1];
$params = [];
if ($search !== '') {
    $where .= ' AND (st.lrn = ? OR CONCAT(st.first_name, \' \', st.last_name) LIKE ? OR st.last_name LIKE ?)';
    array_push($params, $search, '%' . $search . '%', $search . '%');
}
$requests = db_all(
    "SELECT r.*, st.lrn, st.first_name, st.last_name, st.middle_name, p.full_name AS parent_name, u.full_name AS handler,
            (SELECT e.section_id FROM enrollments e JOIN school_years sy ON sy.id = e.school_year_id AND sy.is_active = 1 WHERE e.student_id = r.student_id LIMIT 1) AS section_id
       FROM document_requests r
       JOIN students st   ON st.id = r.student_id
       LEFT JOIN parents p ON r.requested_by_type = 'parent' AND p.id = r.requested_by_id
       LEFT JOIN users u   ON u.id = r.handled_by
      WHERE {$where}
      ORDER BY FIELD(r.status, 'pending', 'processing', 'ready', 'released', 'rejected'), r.id DESC
      LIMIT 200",
    $params
);
$counts = [];
foreach (db_all('SELECT status, COUNT(*) AS n FROM document_requests GROUP BY status') as $row) {
    $counts[$row['status']] = (int) $row['n'];
}
$countFor = static fn (string $key): int => match ($key) {
    'open' => ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0),
    'ready' => $counts['ready'] ?? 0,
    'done' => ($counts['released'] ?? 0) + ($counts['rejected'] ?? 0),
    default => array_sum($counts),
};

/** Where to print the requested document, or null if it is prepared outside the portal. */
$printLink = static function (array $r): ?string {
    return match ($r['doc_type']) {
        'enrollment', 'good_moral' => 'print/certificate.php?request=' . $r['id'],
        'sf9' => $r['section_id'] ? 'print/sf9.php?section=' . $r['section_id'] . '&student=' . $r['student_id'] : null,
        'sf10' => 'print/sf10.php?student=' . $r['student_id'],
        default => null,
    };
};

$title = 'Document requests';
require APP_ROOT . '/templates/header.php';
?>
<?= Access::offNotice('requests') ?>
<div class="page-head">
    <div>
        <h1 class="h3">Document requests</h1>
        <p class="sub">From learners and parents. When you change a status, the learner's parents get a notification.</p>
    </div>
    <form class="d-flex gap-2" method="get" action="<?= e(url('admin/requests.php')) ?>">
        <input type="hidden" name="status" value="<?= e($filter) ?>">
        <input class="form-control" type="search" name="q" value="<?= e($search) ?>" placeholder="Name or LRN" aria-label="Search by name or LRN">
        <button class="btn btn-outline-primary" aria-label="Search"><i class="bi bi-search"></i></button>
    </form>
</div>
<ul class="nav nav-tabs mb-3">
    <?php foreach (REQUEST_FILTERS as $key => [$label]): ?>
        <li class="nav-item">
            <a class="nav-link<?= $filter === $key ? ' active" aria-current="page' : '' ?>" href="<?= e(url('admin/requests.php?status=' . $key)) ?>"><?= e($label) ?> <span class="badge rounded-pill text-bg-light border"><?= $countFor($key) ?></span></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (!$requests): ?>
    <p class="text-secondary">No requests here.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle shadow-sm">
            <thead class="table-light"><tr><th>Sent</th><th>Learner</th><th>Document</th><th>Requested by</th><th style="min-width: 22rem">Status</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $r):
                $link = $printLink($r); ?>
                <tr>
                    <td class="small text-nowrap"><?= e(date('M j, Y', strtotime($r['created_at']))) ?><div class="text-secondary"><?= e(date('g:i A', strtotime($r['created_at']))) ?></div></td>
                    <td>
                        <a href="<?= e(url('admin/student-edit.php?id=' . $r['student_id'])) ?>"><?= e(student_name($r)) ?></a>
                        <div class="small lrn text-secondary"><?= e($r['lrn']) ?></div>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= e(DocumentRequests::TYPES[$r['doc_type']] ?? $r['doc_type']) ?><?= (int) $r['copies'] > 1 ? ' × ' . (int) $r['copies'] : '' ?></div>
                        <div class="small text-secondary"><?= e($r['purpose']) ?></div>
                        <?php if ($link): ?><a class="btn btn-sm btn-outline-primary mt-1" href="<?= e(url($link)) ?>" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Print</a><?php endif; ?>
                    </td>
                    <td class="small"><?= $r['requested_by_type'] === 'parent' ? e($r['parent_name'] ?? 'Parent') . '<div class="text-secondary">Parent</div>' : 'The learner' ?></td>
                    <td>
                        <form method="post" action="<?= e(url($here)) ?>" class="d-flex flex-wrap gap-2 m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                            <select class="form-select form-select-sm" name="status" style="max-width: 11rem" aria-label="Status">
                                <?php foreach (DocumentRequests::STATUSES as $value => [$label]): ?><option value="<?= e($value) ?>" <?= $r['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select>
                            <input class="form-control form-control-sm" name="admin_note" value="<?= e($r['admin_note'] ?? '') ?>" maxlength="500" placeholder="Note to the family (optional)" style="max-width: 16rem" aria-label="Note">
                            <button class="btn btn-sm btn-primary">Save</button>
                        </form>
                        <div class="small text-secondary mt-1"><?= DocumentRequests::badge($r['status']) ?><?php if ($r['handler']): ?> · <?= e($r['handler']) ?>, <?= e(when_label($r['updated_at'])) ?><?php endif; ?></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
