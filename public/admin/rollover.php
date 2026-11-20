<?php
// End of the school year: promote Grade 11 learners to Grade 12, graduate Grade 12, retain those who
// didn't pass, and (optionally) create next year's sections with their subjects and teachers.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$years = db_all('SELECT * FROM school_years ORDER BY label');
$from = db_one('SELECT * FROM school_years WHERE id = ?', [get_int('from') ?: (int) (active_school_year()['id'] ?? 0)]);
$to = db_one('SELECT * FROM school_years WHERE id = ?', [get_int('to')]);
if (!$to && $from) {
    $to = db_one('SELECT * FROM school_years WHERE label > ? ORDER BY label LIMIT 1', [$from['label']]);
}
$here = 'admin/rollover.php?from=' . ($from['id'] ?? 0) . '&to=' . ($to['id'] ?? 0);

if (is_post() && post('action') === 'create_year') {
    if ($from && preg_match('/^(\d{4})-(\d{4})$/', $from['label'], $m)) {
        $label = ($m[1] + 1) . '-' . ($m[2] + 1);
        db_exec('INSERT IGNORE INTO school_years (label, is_active) VALUES (?, 0)', [$label]);
        $new = db_one('SELECT * FROM school_years WHERE label = ?', [$label]);
        flash('success', "S.Y. {$label} is ready. It stays inactive until you make it active under School years.");
        redirect('admin/rollover.php?from=' . $from['id'] . '&to=' . $new['id']);
    }
}

/** next year's section for a learner: same strand and name, the given grade level (created when asked) */
$targetFor = static function (array $section, int $grade) use ($to): ?array {
    return $to ? db_one(
        'SELECT * FROM sections WHERE school_year_id = ? AND grade_level = ? AND strand = ? AND name = ?',
        [$to['id'], $grade, $section['strand'], $section['name']]
    ) : null;
};

if (is_post() && post('action') === 'create_sections' && $from && $to) {
    $created = 0;
    $copied = 0;
    foreach (db_all('SELECT * FROM sections WHERE school_year_id = ?', [$from['id']]) as $sec) {
        // Grade 11 X → Grade 12 X for the promoted learners, plus a new Grade 11 X for next year's incoming learners.
        $grades = (int) $sec['grade_level'] < 12 ? [(int) $sec['grade_level'] + 1, (int) $sec['grade_level']] : [];
        foreach ($grades as $grade) {
            if ($targetFor($sec, $grade)) {
                continue;
            }
            db_exec('INSERT INTO sections (school_year_id, grade_level, strand, name, adviser_id) VALUES (?, ?, ?, ?, ?)', [$to['id'], $grade, $sec['strand'], $sec['name'], $sec['adviser_id']]);
            $newId = (int) db()->lastInsertId();
            $created++;
            if (post('copy') === '1') {
                // Copy subjects and teachers from this year's section of the same grade and strand
                $model = db_one(
                    'SELECT s.id FROM sections s WHERE s.school_year_id = ? AND s.grade_level = ? AND s.strand = ?
                      ORDER BY s.name = ? DESC, (SELECT COUNT(*) FROM class_assignments ca WHERE ca.section_id = s.id) DESC LIMIT 1',
                    [$from['id'], $grade, $sec['strand'], $sec['name']]
                );
                if ($model) {
                    $copied += db_exec(
                        'INSERT INTO class_assignments (section_id, subject_id, teacher_id, semester)
                         SELECT ?, subject_id, teacher_id, semester FROM class_assignments WHERE section_id = ?',
                        [$newId, $model['id']]
                    );
                }
            }
        }
    }
    flash('success', "Created {$created} section(s) in S.Y. {$to['label']}" . ($copied ? " with {$copied} subject assignment(s) copied" : '') . '. Remove any you don\'t need under Sections.');
    redirect($here);
}

// Every learner in the source year, with a suggested action
$learners = [];
if ($from) {
    foreach (db_all('SELECT s.*, s.name AS section_name FROM sections s WHERE s.school_year_id = ? ORDER BY s.grade_level, s.strand, s.name', [$from['id']]) as $sec) {
        foreach (Forms::roster((int) $sec['id']) as $st) {
            $r = Forms::semesterResults((int) $st['id'], (int) $sec['id']);
            $complete = $r[1]['average'] !== null && $r[2]['average'] !== null;
            $passed = $complete && $r[1]['complete'] && $r[2]['complete'];
            $grade = (int) $sec['grade_level'];
            $suggest = match (true) {
                $st['status'] !== 'active' => 'skip',
                !$complete => 'review',
                $grade >= 12 => $passed ? 'graduate' : 'retain',
                default => $passed ? 'promote' : 'retain',
            };
            $already = $to ? db_value('SELECT section_id FROM enrollments WHERE student_id = ? AND school_year_id = ?', [$st['id'], $to['id']]) : null;
            $learners[] = ['student' => $st, 'section' => $sec, 'results' => $r, 'suggest' => $suggest, 'already' => $already];
        }
    }
}
$targetSections = $to ? section_options((int) $to['id']) : [];

if (is_post() && post('action') === 'apply' && $from && $to) {
    $actions = is_array($_POST['do'] ?? null) ? $_POST['do'] : [];
    $targets = is_array($_POST['target'] ?? null) ? $_POST['target'] : [];
    $valid = array_map('intval', array_column($targetSections, 'id'));
    $done = ['promote' => 0, 'retain' => 0, 'graduate' => 0, 'transferred_out' => 0, 'dropped' => 0];
    $missing = 0;
    foreach ($learners as $l) {
        $sid = (int) $l['student']['id'];
        $do = is_string($actions[$sid] ?? null) ? $actions[$sid] : 'skip';
        $target = (int) ($targets[$sid] ?? 0);
        if (in_array($do, ['promote', 'retain'], true)) {
            if (!in_array($target, $valid, true)) {
                $missing++;
                continue;
            }
            enroll_student($sid, $target);
            $done[$do]++;
        } elseif ($do === 'graduate') {
            db_exec("UPDATE students SET status = 'graduated', status_note = ? WHERE id = ?", ['Graduated S.Y. ' . $from['label'], $sid]);
            $done['graduate']++;
        } elseif (in_array($do, ['transferred_out', 'dropped'], true)) {
            db_exec('UPDATE students SET status = ?, status_note = ? WHERE id = ?', [$do, 'S.Y. ' . $from['label'], $sid]);
            $done[$do]++;
        }
    }
    Access::log('rollover', sprintf('Year-end rollover to S.Y. %s: %d promoted, %d retained, %d graduated, %d transferred out, %d dropped', $to['label'],
        $done['promote'], $done['retain'], $done['graduate'], $done['transferred_out'], $done['dropped']), 'school_year', (int) $to['id']);
    flash('success', sprintf('Done: %d promoted, %d retained, %d graduated, %d transferred out, %d dropped.%s Make S.Y. %s active under School years when classes start.',
        $done['promote'], $done['retain'], $done['graduate'], $done['transferred_out'], $done['dropped'],
        $missing ? " {$missing} learner(s) were skipped because no section was chosen." : '', $to['label']));
    redirect($here);
}

$title = 'Year-end rollover';
require APP_ROOT . '/templates/header.php';
$actionLabels = ['promote' => 'Promote', 'retain' => 'Retain (same grade)', 'graduate' => 'Graduate', 'transferred_out' => 'Transferred out', 'dropped' => 'Dropped', 'skip' => 'Skip', 'review' => 'Skip'];
?>
<div class="page-head">
    <div>
        <h1 class="h3"><i class="bi bi-arrow-repeat me-2"></i>Year-end rollover</h1>
        <p class="sub">Move learners into next school year's sections in one step. Nothing changes until you press <strong>Apply</strong>.</p>
    </div>
    <form method="get" class="d-flex gap-2 align-items-center">
        <select class="form-select form-select-sm w-auto" name="from" aria-label="From school year"><?php foreach ($years as $y): ?><option value="<?= (int) $y['id'] ?>" <?= (int) $y['id'] === (int) ($from['id'] ?? 0) ? 'selected' : '' ?>>From <?= e($y['label']) ?></option><?php endforeach; ?></select>
        <i class="bi bi-arrow-right"></i>
        <select class="form-select form-select-sm w-auto" name="to" aria-label="To school year"><option value="0">To…</option><?php foreach ($years as $y): ?><option value="<?= (int) $y['id'] ?>" <?= (int) $y['id'] === (int) ($to['id'] ?? 0) ? 'selected' : '' ?>>To <?= e($y['label']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-primary">Show</button>
    </form>
</div>

<?php if (!$from): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle-fill"></i><div>Add a school year first.</div></div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h6"><span class="badge text-bg-primary me-1">1</span>Next school year</h2>
                <?php if ($to): ?>
                    <p class="mb-0">Moving into <strong>S.Y. <?= e($to['label']) ?></strong><?= $to['is_active'] ? ' (active)' : ' (not active yet)' ?>.</p>
                <?php else: ?>
                    <form method="post" action="<?= e(url($here)) ?>" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="create_year">
                        <p class="small text-secondary">There is no school year after <?= e($from['label']) ?> yet.</p>
                        <button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Create the next school year</button></form>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h6"><span class="badge text-bg-primary me-1">2</span>Next year's sections</h2>
                <?php if ($to): ?>
                    <p class="small text-secondary mb-2"><?= count($targetSections) ?> section(s) in S.Y. <?= e($to['label']) ?>. Create matching ones (Grade 11 X → Grade 12 X, same strand and adviser):</p>
                    <form method="post" action="<?= e(url($here)) ?>" class="d-flex flex-wrap gap-2 align-items-center m-0"><?= csrf_field() ?><input type="hidden" name="action" value="create_sections">
                        <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="copy" value="1" id="copy" checked><label class="form-check-label small" for="copy">Copy subjects and teachers</label></div>
                        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-diagram-3 me-1"></i>Create matching sections</button></form>
                <?php else: ?>
                    <p class="small text-secondary mb-0">Create the next school year first.</p>
                <?php endif; ?>
            </div></div>
        </div>
    </div>

    <?php if ($to): ?>
        <h2 class="h6"><span class="badge text-bg-primary me-1">3</span>Learners (<?= count($learners) ?>)</h2>
        <form method="post" action="<?= e(url($here)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply">
            <div class="table-responsive">
                <table class="table table-sm align-middle shadow-sm">
                    <thead><tr><th>Learner</th><th>This year</th><th class="text-center">Sem 1</th><th class="text-center">Sem 2</th><th>Action</th><th>Next year's section</th></tr></thead>
                    <tbody>
                    <?php foreach ($learners as $l):
                        $st = $l['student']; $sid = (int) $st['id']; $sec = $l['section'];
                        $nextGrade = $l['suggest'] === 'retain' ? (int) $sec['grade_level'] : min(12, (int) $sec['grade_level'] + 1);
                        $default = $l['already'] ?: ($targetFor($sec, $nextGrade)['id'] ?? 0); ?>
                        <tr class="<?= $l['suggest'] === 'review' ? 'table-warning' : '' ?>">
                            <td><?= e(student_name($st)) ?><?= $l['already'] ? ' <span class="badge text-bg-success">already enrolled</span>' : '' ?></td>
                            <td class="small"><?= e(section_label($sec)) ?></td>
                            <?php foreach (SEMESTERS as $sem): $r = $l['results'][$sem]; ?>
                                <td class="text-center"><?= $r['average'] === null ? '<span class="text-secondary">–</span>' : '<span class="badge ' . ($r['complete'] ? 'text-bg-success' : 'text-bg-danger') . '">' . (int) $r['average'] . '</span>' ?></td>
                            <?php endforeach; ?>
                            <td>
                                <select class="form-select form-select-sm" name="do[<?= $sid ?>]" aria-label="Action for <?= e(student_name($st)) ?>">
                                    <?php foreach (['promote', 'retain', 'graduate', 'transferred_out', 'dropped', 'skip'] as $a): ?>
                                        <option value="<?= $a ?>" <?= ($l['suggest'] === $a || ($l['suggest'] === 'review' && $a === 'skip')) ? 'selected' : '' ?>><?= e($actionLabels[$a]) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($l['suggest'] === 'review'): ?><div class="small text-secondary">Grades incomplete — check first</div><?php endif; ?>
                            </td>
                            <td>
                                <select class="form-select form-select-sm" name="target[<?= $sid ?>]" aria-label="Next section for <?= e(student_name($st)) ?>">
                                    <option value="0">—</option>
                                    <?php foreach ($targetSections as $ts): ?><option value="<?= (int) $ts['id'] ?>" <?= (int) $ts['id'] === (int) $default ? 'selected' : '' ?>><?= e(section_label($ts)) ?></option><?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="sticky-actions d-flex justify-content-between align-items-center gap-2">
                <span class="small text-secondary">Promote / Retain need a section. Graduate, transferred and dropped learners keep their records and stop appearing in sections next year.</span>
                <button class="btn btn-primary" onclick="return confirm('Apply these changes for S.Y. <?= e($to['label']) ?>?')"><i class="bi bi-check2-all me-1"></i>Apply</button>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
