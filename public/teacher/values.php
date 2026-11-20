<?php
// Observed values (SF9 core values), rated per quarter by the section's adviser.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$section = Forms::sectionOrAbort(get_int('section'), $staff);
$sectionId = (int) $section['id'];
$syId = (int) $section['school_year_id'];
$quarter = in_array(get_int('q'), QUARTERS_ALL, true) ? get_int('q') : 1;
$here = "teacher/values.php?section={$sectionId}&q={$quarter}";
$roster = Forms::roster($sectionId);

if (is_post()) {
    $ratings = is_array($_POST['r'] ?? null) ? $_POST['r'] : [];
    $fill = post('fill');
    $save = db()->prepare(
        'INSERT INTO observed_values (student_id, school_year_id, quarter, statement, rating, updated_by) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_by = VALUES(updated_by)'
    );
    $clear = db()->prepare('DELETE FROM observed_values WHERE student_id = ? AND school_year_id = ? AND quarter = ? AND statement = ?');
    $saved = 0;
    foreach ($roster as $st) {
        $sid = (int) $st['id'];
        foreach (array_keys(Forms::VALUES) as $n) {
            $value = is_string($ratings[$sid][$n] ?? null) ? $ratings[$sid][$n] : '';
            if ($value === '' && isset(Forms::RATINGS[$fill])) {
                $value = $fill;
            }
            if (isset(Forms::RATINGS[$value])) {
                $save->execute([$sid, $syId, $quarter, $n, $value, $staff['id']]);
                $saved++;
            } elseif ($value === '') {
                $clear->execute([$sid, $syId, $quarter, $n]);
            }
        }
    }
    flash('success', "Quarter {$quarter} observed values saved ({$saved} ratings).");
    redirect($here);
}

$current = [];
foreach (db_all('SELECT student_id, statement, rating FROM observed_values WHERE school_year_id = ? AND quarter = ?', [$syId, $quarter]) as $r) {
    $current[(int) $r['student_id']][(int) $r['statement']] = $r['rating'];
}
$short = [1 => 'MD 1', 2 => 'MD 2', 3 => 'MT 1', 4 => 'MT 2', 5 => 'MK', 6 => 'MB 1', 7 => 'MB 2'];

$title = 'Observed values';
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= e(url('teacher/advisory.php')) ?>">My advisory</a></li>
    <li class="breadcrumb-item active">Observed values</li></ol></nav>
<div class="page-head">
    <div>
        <h1 class="h3">Observed values</h1>
        <p class="sub"><?= e(section_label($section)) ?> · Printed on the report card (SF9)</p>
    </div>
    <div class="btn-group">
        <?php foreach (QUARTERS_ALL as $q): ?>
            <a class="btn btn-sm <?= $q === $quarter ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url("teacher/values.php?section={$sectionId}&q={$q}")) ?>">Q<?= $q ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body small">
        <div class="row g-2">
            <?php foreach (Forms::VALUES as $n => [$value, $statement]): ?>
                <div class="col-md-6"><span class="badge text-bg-primary me-1"><?= e($short[$n]) ?></span><strong><?= e($value) ?>:</strong> <?= e($statement) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="mt-2 text-secondary"><?= implode(' · ', array_map(static fn ($c, $l) => "<strong>{$c}</strong> {$l}", array_keys(Forms::RATINGS), Forms::RATINGS)) ?></div>
    </div>
</div>

<form method="post" action="<?= e(url($here)) ?>">
    <?= csrf_field() ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle shadow-sm">
            <thead><tr><th>Learner</th><?php foreach ($short as $n => $label): ?><th class="text-center" title="<?= e(Forms::VALUES[$n][1]) ?>"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($roster as $st): $sid = (int) $st['id']; ?>
                <tr>
                    <td><?= e(student_name($st)) ?></td>
                    <?php foreach (array_keys(Forms::VALUES) as $n): $v = $current[$sid][$n] ?? ''; ?>
                        <td class="text-center">
                            <select class="form-select form-select-sm mx-auto" style="width: 4.6rem" name="r[<?= $sid ?>][<?= $n ?>]" aria-label="<?= e($short[$n]) ?> for <?= e(student_name($st)) ?>">
                                <option value=""></option>
                                <?php foreach (array_keys(Forms::RATINGS) as $code): ?><option <?= $v === $code ? 'selected' : '' ?>><?= e($code) ?></option><?php endforeach; ?>
                            </select>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="sticky-actions d-flex flex-wrap gap-2 align-items-center">
        <label class="small text-secondary" for="fill">Fill every blank with</label>
        <select class="form-select form-select-sm w-auto" id="fill" name="fill"><option value="">— leave blank —</option><?php foreach (Forms::RATINGS as $code => $label): ?><option value="<?= e($code) ?>"><?= e($code) ?> · <?= e($label) ?></option><?php endforeach; ?></select>
        <button class="btn btn-primary ms-auto"><i class="bi bi-check2 me-1"></i>Save Quarter <?= $quarter ?></button>
    </div>
</form>
<?php require APP_ROOT . '/templates/footer.php'; ?>
