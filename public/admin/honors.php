<?php
// Honor roll (DepEd Order No. 36, s. 2016): With Honors 90–94, With High Honors 95–97, With Highest Honors 98–100.
// Learners need a complete set of final grades with no failing subject.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin', 'principal', 'guidance');
$years = db_all('SELECT * FROM school_years ORDER BY label DESC');
$syId = get_int('sy') ?: (int) (active_school_year()['id'] ?? 0);
$period = in_array(get_str('period'), ['1', '2', 'year'], true) ? get_str('period') : '1';
$level = get_int('grade');

$sections = db_all(
    'SELECT s.*, s.name AS section_name FROM sections s WHERE s.school_year_id = ?' . ($level ? ' AND s.grade_level = ' . $level : '') . ' ORDER BY s.grade_level, s.strand, s.name',
    [$syId]
);
$awards = ['With Highest Honors' => [], 'With High Honors' => [], 'With Honors' => []];
foreach ($sections as $sec) {
    foreach (Forms::roster((int) $sec['id']) as $st) {
        $results = Forms::semesterResults((int) $st['id'], (int) $sec['id']);
        if ($period === 'year') {
            $both = $results[1]['average'] !== null && $results[2]['average'] !== null;
            $average = $both ? (int) round(($results[1]['average'] + $results[2]['average']) / 2) : null;
            $passed = $both && $results[1]['complete'] && $results[2]['complete'];
        } else {
            $average = $results[(int) $period]['average'];
            $passed = $results[(int) $period]['complete'];
        }
        if ($award = Forms::honor($average, $passed)) {
            $awards[$award][] = ['student' => $st, 'section' => $sec, 'average' => $average];
        }
    }
}
foreach ($awards as &$list) {
    usort($list, static fn ($a, $b) => [$b['average'], $a['student']['last_name']] <=> [$a['average'], $b['student']['last_name']]);
}
unset($list);
$quartersInPeriod = $period === 'year' ? QUARTERS_ALL : semester_quarters((int) $period);
$provisional = array_filter($quartersInPeriod, static fn ($q) => !Grading::isLocked($syId, $q));
$periodLabel = $period === 'year' ? 'Whole school year' : semester_label((int) $period);
$sy = db_one('SELECT * FROM school_years WHERE id = ?', [$syId]);

$title = 'Honor roll';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3"><i class="bi bi-award-fill text-warning me-2"></i>Honor roll</h1>
        <p class="sub"><?= e($periodLabel) ?> · S.Y. <?= e($sy['label'] ?? '') ?><?= $level ? ' · Grade ' . $level : '' ?></p>
    </div>
    <form method="get" class="d-flex flex-wrap gap-2 d-print-none">
        <select class="form-select form-select-sm w-auto" name="sy" aria-label="School year"><?php foreach ($years as $y): ?><option value="<?= (int) $y['id'] ?>" <?= (int) $y['id'] === $syId ? 'selected' : '' ?>><?= e($y['label']) ?></option><?php endforeach; ?></select>
        <select class="form-select form-select-sm w-auto" name="period" aria-label="Period">
            <option value="1" <?= $period === '1' ? 'selected' : '' ?>>1st semester</option>
            <option value="2" <?= $period === '2' ? 'selected' : '' ?>>2nd semester</option>
            <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Whole year</option>
        </select>
        <select class="form-select form-select-sm w-auto" name="grade" aria-label="Grade level"><option value="0">All grades</option><?php foreach (grade_level_options() as $g): ?><option value="<?= $g ?>" <?= $g === $level ? 'selected' : '' ?>>Grade <?= $g ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-primary">Show</button>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="bi bi-printer"></i></button>
    </form>
</div>
<?php if ($provisional): ?>
    <div class="alert alert-warning d-print-none"><i class="bi bi-hourglass-split"></i><div>Provisional: Quarter <?= implode(' and ', $provisional) ?> grades are not locked yet, so they can still change.</div></div>
<?php endif; ?>
<div class="row g-4">
    <?php foreach ($awards as $award => $list): ?>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 d-flex justify-content-between"><span><i class="bi bi-award<?= $award === 'With Honors' ? '' : '-fill' ?> text-warning me-1"></i><?= e($award) ?></span><span class="badge text-bg-primary"><?= count($list) ?></span></h2>
                    <div class="small text-secondary mb-2"><?= ['With Highest Honors' => 'General average 98–100', 'With High Honors' => '95–97', 'With Honors' => '90–94'][$award] ?></div>
                    <ol class="mb-0 ps-3">
                        <?php foreach ($list as $a): ?>
                            <li class="mb-1"><span class="fw-semibold"><?= e(student_name($a['student'])) ?></span> <span class="badge text-bg-success"><?= (int) $a['average'] ?></span>
                                <div class="small text-secondary"><?= e(section_label($a['section'])) ?></div></li>
                        <?php endforeach; ?>
                    </ol>
                    <?php if (!$list): ?><p class="small text-secondary mb-0">None.</p><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<p class="small text-secondary mt-3">Based on every final grade entered by teachers. A learner with any failing final grade, or a missing grade, isn't listed.</p>
<?php require APP_ROOT . '/templates/footer.php'; ?>
