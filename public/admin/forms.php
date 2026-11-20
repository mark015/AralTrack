<?php
// DepEd school forms for a section: SF1, SF2, SF5 (Excel) and SF9, SF10 (print).
// The registrar, principal and guidance counselor see every section; advisers see their own.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$all = sees_all_learners($staff);
$years = db_all('SELECT * FROM school_years ORDER BY label DESC');
$syId = get_int('sy') ?: (int) (active_school_year()['id'] ?? 0);
$sections = db_all(
    'SELECT s.*, s.name AS section_name, u.full_name AS adviser, (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id) AS learners
       FROM sections s LEFT JOIN users u ON u.id = s.adviser_id
      WHERE s.school_year_id = ?' . ($all ? '' : ' AND s.adviser_id = ' . (int) $staff['id']) . '
      ORDER BY s.grade_level, s.strand, s.name',
    [$syId]
);
if (!$all && !$sections && !advised_section_ids($staff)) {
    abort(403, 'Only advisers, the registrar, the principal and the guidance counselor can open school forms.');
}
$months = [];
$sy = db_one('SELECT * FROM school_years WHERE id = ?', [$syId]);
if ($sy) {
    [$start] = array_map('intval', explode('-', $sy['label']));
    for ($m = 6; $m <= 17; $m++) {
        $months[] = sprintf('%04d-%02d', $start + intdiv($m - 1, 12), ($m - 1) % 12 + 1);
    }
}
$defaultMonth = in_array(date('Y-m'), $months, true) ? date('Y-m') : ($months[0] ?? date('Y-m'));

$title = 'DepEd forms';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">DepEd school forms</h1>
        <p class="sub">Made from the grades, attendance and learner records in the portal. Excel files open in Excel or Google Sheets.</p>
    </div>
    <?php if ($all && count($years) > 1): ?>
        <form method="get" class="d-flex gap-2 align-items-center">
            <label class="small text-secondary" for="sy">School year</label>
            <select class="form-select form-select-sm w-auto" id="sy" name="sy" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?><option value="<?= (int) $y['id'] ?>" <?= (int) $y['id'] === $syId ? 'selected' : '' ?>><?= e($y['label']) ?></option><?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['SF1', 'School Register', 'Learner list with birthdate, age, parents and address.', 'file-earmark-person', ''],
        ['SF2', 'Daily Attendance', 'Per month: present, absent (x), tardy (T) per day.', 'calendar-check', 'teal'],
        ['SF5', 'Promotion Report', 'Per semester: general average, back subjects, action taken.', 'graph-up-arrow', 'violet'],
        ['SF9', 'Report Card', 'Printable report cards for the whole section.', 'journal-text', 'gold'],
        ['SF10', 'Permanent Record', 'All school years and semesters of one learner.', 'archive', 'green'],
    ] as [$code, $name, $about, $icon, $tone]): ?>
        <div class="col-md-6 col-xl">
            <div class="card h-100"><div class="card-body">
                <div class="icon-bubble sm <?= e($tone) ?> mb-2"><i class="bi bi-<?= e($icon) ?>"></i></div>
                <div class="fw-bold"><?= e($code) ?> · <?= e($name) ?></div>
                <div class="small text-secondary"><?= e($about) ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="table-responsive">
    <table class="table align-middle shadow-sm">
        <thead><tr><th>Section</th><th class="text-center">Learners</th><th>SF1</th><th>SF2 (month)</th><th>SF5 (semester)</th><th>SF9</th><th>SF10</th></tr></thead>
        <tbody>
        <?php foreach ($sections as $s): $sid = (int) $s['id']; ?>
            <tr>
                <td><span class="fw-semibold"><?= e(section_label($s)) ?></span><div class="small text-secondary"><?= e($s['adviser'] ?? 'No adviser') ?></div></td>
                <td class="text-center"><?= (int) $s['learners'] ?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url("admin/form-export.php?form=sf1&section={$sid}")) ?>"><i class="bi bi-download"></i> Excel</a></td>
                <td>
                    <form method="get" action="<?= e(url('admin/form-export.php')) ?>" class="d-flex gap-1 m-0">
                        <input type="hidden" name="form" value="sf2"><input type="hidden" name="section" value="<?= $sid ?>">
                        <select class="form-select form-select-sm" name="month" aria-label="Month">
                            <?php foreach ($months as $m): ?><option value="<?= e($m) ?>" <?= $m === $defaultMonth ? 'selected' : '' ?>><?= e(date('M Y', strtotime($m . '-01'))) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-primary" title="Download"><i class="bi bi-download"></i></button>
                    </form>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <?php foreach (SEMESTERS as $sem): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url("admin/form-export.php?form=sf5&section={$sid}&semester={$sem}")) ?>"><?= $sem === 1 ? '1st' : '2nd' ?></a>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td><a class="btn btn-sm btn-primary" href="<?= e(url("print/sf9.php?section={$sid}")) ?>" target="_blank"><i class="bi bi-printer"></i> Print all</a></td>
                <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url("print/sf10.php?section={$sid}")) ?>" target="_blank"><i class="bi bi-printer"></i> Print</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$sections): ?><tr><td colspan="7" class="text-secondary">No sections in this school year.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<p class="small text-secondary"><i class="bi bi-info-circle me-1"></i>Forms use every grade teachers have entered, published or not. Observed values for SF9 are entered by advisers under <strong>My advisory → Observed values</strong>.</p>
<?php require APP_ROOT . '/templates/footer.php'; ?>
