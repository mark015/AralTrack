<?php
// Class record for one class and quarter (DepEd Order No. 8, s. 2015): scores in Written Works, Performance
// Tasks and the Quarterly Assessment → percentage score → weighted score → initial grade → transmuted grade.
// The teacher can then copy the computed grades into the grade sheet.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$class = teacher_class_or_abort(get_int('id'), $staff);
$classId = (int) $class['id'];
$quarters = class_quarters($class);
$quarter = class_quarter_or_abort($class, get_int('q') ?: $quarters[0]);
$locked = Grading::isLocked((int) $class['school_year_id'], $quarter);
$here = "teacher/class-record.php?id={$classId}&q={$quarter}";
$weights = ClassRecord::weights((int) $class['subject_id']);
$roster = section_roster((int) $class['section_id']);
$assessments = ClassRecord::assessments($classId, $quarter);
$assessmentById = array_column($assessments, null, 'id');
$scores = ClassRecord::scores($assessments);
$errors = [];
$typed = []; // what the teacher typed, shown again when there are errors

/** Reads and checks the assessment fields of an add/edit form. */
$readAssessment = static function () use (&$errors): ?array {
    $title = mb_substr(trim(post('title')), 0, 120);
    $max = post('max_score');
    $date = post('taken_on');
    if ($title === '') {
        $errors[] = 'Give the assessment a title, e.g. "Quiz 1".';
    } elseif (!is_numeric($max) || (float) $max <= 0 || (float) $max > 1000) {
        $errors[] = 'The highest possible score must be a number from 1 to 1000.';
    } elseif ($date !== '' && !valid_ymd($date)) {
        $errors[] = 'Enter a valid date, or leave it blank.';
    } else {
        return ['title' => $title, 'max' => round((float) $max, 2), 'date' => $date ?: null];
    }
    return null;
};

if (is_post()) {
    $action = post('action');
    if ($locked) {
        flash('warning', "Quarter {$quarter} is locked because its deadline has passed. Send a grade change request instead.");
        redirect($here);
    }

    if ($action === 'add') {
        $component = post('component');
        $fields = $readAssessment();
        if (!isset(ClassRecord::COMPONENTS[$component])) {
            $errors[] = 'Choose Written Works, Performance Tasks or Quarterly Assessment.';
        } elseif ($fields) {
            db_exec(
                'INSERT INTO assessments (class_assignment_id, quarter, component, title, max_score, taken_on) VALUES (?, ?, ?, ?, ?, ?)',
                [$classId, $quarter, $component, $fields['title'], $fields['max'], $fields['date']]
            );
            flash('success', '"' . $fields['title'] . '" added. Type the scores in its column below.');
            redirect($here . '#record');
        }
    }

    if ($action === 'edit' || $action === 'delete') {
        $assessment = $assessmentById[post_int('assessment_id')] ?? null;
        if (!$assessment) {
            flash('danger', 'That assessment is not in this class record.');
            redirect($here);
        }
        $aid = (int) $assessment['id'];
        if ($action === 'delete') {
            db_exec('DELETE FROM assessments WHERE id = ?', [$aid]);
            flash('success', '"' . $assessment['title'] . '" and its scores were deleted.');
            redirect($here . '#manage');
        }
        $fields = $readAssessment();
        $highest = (float) db_value('SELECT COALESCE(MAX(score), 0) FROM assessment_scores WHERE assessment_id = ?', [$aid]);
        if ($fields && $fields['max'] < $highest) {
            $errors[] = "A learner already scored {$highest} in \"{$assessment['title']}\", so the highest possible score can't be lower than that.";
        } elseif ($fields) {
            db_exec('UPDATE assessments SET title = ?, max_score = ?, taken_on = ? WHERE id = ?', [$fields['title'], $fields['max'], $fields['date'], $aid]);
            flash('success', '"' . $fields['title'] . '" updated.');
            redirect($here . '#manage');
        }
    }

    if ($action === 'save_scores') {
        $submitted = is_array($_POST['s'] ?? null) ? $_POST['s'] : [];
        $upserts = [];
        $deletes = [];
        foreach ($assessments as $a) {
            $aid = (int) $a['id'];
            $column = is_array($submitted[$aid] ?? null) ? $submitted[$aid] : [];
            foreach ($roster as $student) {
                $sid = (int) $student['id'];
                if (!isset($column[$sid]) || !is_string($column[$sid])) {
                    continue; // not on the form (added after the page loaded): leave as is
                }
                $raw = trim($column[$sid]);
                $typed[$aid][$sid] = $raw;
                $current = $scores[$aid][$sid] ?? null;
                if ($raw === '') {
                    if ($current !== null) {
                        $deletes[] = [$aid, $sid];
                    }
                    continue;
                }
                if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > (float) $a['max_score']) {
                    $errors[] = student_name($student) . " — {$a['title']}: \"{$raw}\" must be a number from 0 to " . format_grade($a['max_score']) . '.';
                    continue;
                }
                $score = round((float) $raw, 2);
                if ($current === null || abs($current - $score) >= 0.005) {
                    $upserts[] = [$aid, $sid, $score];
                }
            }
        }
        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $up = $pdo->prepare('INSERT INTO assessment_scores (assessment_id, student_id, score) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score)');
                $del = $pdo->prepare('DELETE FROM assessment_scores WHERE assessment_id = ? AND student_id = ?');
                foreach ($upserts as $row) {
                    $up->execute($row);
                }
                foreach ($deletes as $row) {
                    $del->execute($row);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $count = count($upserts) + count($deletes);
            flash($count ? 'success' : 'info', $count ? "{$count} score" . ($count === 1 ? '' : 's') . ' saved.' : 'Nothing was changed.');
            redirect($here . '#record');
        }
    }

    if ($action === 'apply') {
        $saved = class_grades($classId);
        $changes = [];
        foreach ($roster as $student) {
            $sid = (int) $student['id'];
            $result = ClassRecord::compute($assessments, $scores, $sid, $weights);
            if ($result['grade'] === null) {
                continue;
            }
            $current = $saved[$sid][$quarter] ?? null;
            if ($current === null || abs($current - $result['grade']) >= 0.005) {
                $changes[] = [$sid, $quarter, (float) $result['grade']];
            }
        }
        if (!$changes) {
            flash('info', "The grade sheet already matches the class record for Quarter {$quarter}.");
        } else {
            try {
                save_grade_changes($classId, $changes, $staff['id'], 'class_record');
                flash('success', count($changes) . " Quarter {$quarter} grade" . (count($changes) === 1 ? '' : 's') . ' copied to the grade sheet. Publish the quarter when you are ready for learners and parents to see them.');
            } catch (UserFacingException $e) {
                flash('danger', $e->getMessage());
            }
        }
        redirect($here);
    }
}

$saved = class_grades($classId);
$hpsAll = ClassRecord::highestPossible($assessments);
$hps = ClassRecord::highestPossible($assessments, $scores); // only items already given
$byComponent = array_fill_keys(array_keys(ClassRecord::COMPONENTS), []);
foreach ($assessments as $a) {
    $byComponent[$a['component']][] = $a;
}
$results = [];
$differs = 0;
$withGrade = 0;
foreach ($roster as $student) {
    $sid = (int) $student['id'];
    $results[$sid] = ClassRecord::compute($assessments, $scores, $sid, $weights);
    if ($results[$sid]['grade'] !== null) {
        $withGrade++;
        $current = $saved[$sid][$quarter] ?? null;
        if ($current === null || abs($current - $results[$sid]['grade']) >= 0.005) {
            $differs++;
        }
    }
}
$missingComponents = array_keys(array_filter($hps, static fn ($v) => $v <= 0));
$blankCells = array_sum(array_column($results, 'blanks'));
$custom = ClassRecord::hasCustomWeights((int) $class['subject_id']);
$componentShort = ['ww' => 'WW', 'pt' => 'PT', 'qa' => 'QA'];
$componentTone = ['ww' => 'violet', 'pt' => 'teal', 'qa' => 'gold'];

$title = 'Class record · ' . $class['subject_name'];
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('teacher/index.php')) ?>">My classes</a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('teacher/class.php?id=' . $classId)) ?>"><?= e($class['subject_name']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page">Class record</li>
    </ol>
</nav>
<div class="page-head">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="subject-chip" style="--hue: <?= color_hue($class['subject_code']) ?>"><?= e($class['subject_code']) ?></span>
            <span class="small text-secondary"><?= e(section_label($class)) ?> · S.Y. <?= e($class['sy_label']) ?></span>
        </div>
        <h1 class="h3">Class record · Quarter <?= $quarter ?></h1>
        <p class="sub">
            Weights: <strong>WW <?= format_grade($weights['ww']) ?>%</strong> · <strong>PT <?= format_grade($weights['pt']) ?>%</strong> · <strong>QA <?= format_grade($weights['qa']) ?>%</strong>
            <span class="small">(<?= $custom ? 'set by the registrar for this subject' : 'DepEd default for ' . e(strtolower(SUBJECT_TYPES[$class['subject_type']] ?? 'core')) . ' subjects' ?>)</span>
        </p>
    </div>
    <div class="btn-group" role="group" aria-label="Quarter">
        <?php foreach ($quarters as $q): ?>
            <a class="btn btn-sm <?= $q === $quarter ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url("teacher/class-record.php?id={$classId}&q={$q}")) ?>">Quarter <?= $q ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($locked): ?>
    <div class="alert alert-warning"><i class="bi bi-lock-fill"></i><div>Quarter <?= $quarter ?> is locked (<?= e(Grading::label((int) $class['school_year_id'], $quarter)) ?>). The class record is read-only. To change a grade, send a <a href="<?= e(url('teacher/class.php?id=' . $classId . '#change-requests')) ?>">grade change request</a>.</div></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <i class="bi bi-x-octagon-fill"></i>
        <div><strong>Nothing was saved.</strong>
            <ul class="mb-0 mt-1"><?php foreach (array_slice($errors, 0, 15) as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
            <?php if (count($errors) > 15): ?><div class="small">…and <?= count($errors) - 15 ?> more.</div><?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach (ClassRecord::COMPONENTS as $c => $label): ?>
        <div class="col-sm-4">
            <div class="summary-tile d-flex gap-3 align-items-center">
                <div class="icon-bubble sm <?= $componentTone[$c] ?>"><i class="bi bi-<?= ['ww' => 'pencil', 'pt' => 'easel', 'qa' => 'journal-check'][$c] ?>"></i></div>
                <div>
                    <div class="small text-secondary"><?= e($label) ?> · <?= format_grade($weights[$c]) ?>%</div>
                    <div class="fw-semibold"><?= count($byComponent[$c]) ?> item<?= count($byComponent[$c]) === 1 ? '' : 's' ?> · <?= format_grade($hpsAll[$c]) ?> pts</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!$locked): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6"><i class="bi bi-plus-circle me-2"></i>Add an assessment</h2>
            <form method="post" action="<?= e(url($here)) ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="col-md-3">
                    <label class="form-label small" for="component">Component</label>
                    <select class="form-select" id="component" name="component">
                        <?php foreach (ClassRecord::COMPONENTS as $c => $label): ?><option value="<?= $c ?>" <?= post('component') === $c ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="a-title">Title</label>
                    <input class="form-control" id="a-title" name="title" maxlength="120" required placeholder="e.g. Quiz 1, Lab report, Quarterly exam" value="<?= e(post('action') === 'add' ? post('title') : '') ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="a-max">Highest possible score</label>
                    <input class="form-control" id="a-max" name="max_score" inputmode="decimal" required placeholder="e.g. 20" value="<?= e(post('action') === 'add' ? post('max_score') : '') ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="a-date">Date <span class="text-secondary">(optional)</span></label>
                    <input class="form-control" type="date" id="a-date" name="taken_on" value="<?= e(post('action') === 'add' ? post('taken_on') : '') ?>">
                </div>
                <div class="col-md-1 d-grid">
                    <button class="btn btn-primary"><i class="bi bi-plus-lg"></i><span class="d-md-none ms-1">Add</span></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<h2 class="h5" id="record">Scores</h2>
<?php if (!$roster): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle-fill"></i><div>No learners are enrolled in this section yet.</div></div>
<?php elseif (!$assessments): ?>
    <div class="empty-state card"><div class="card-body text-center py-5">
        <div class="icon-bubble mx-auto mb-3"><i class="bi bi-table"></i></div>
        <p class="mb-1 fw-semibold">No assessments yet for Quarter <?= $quarter ?>.</p>
        <p class="small text-secondary mb-0">Add quizzes, outputs and the quarterly exam above. Each one becomes a column where you type the learners' scores.</p>
    </div></div>
<?php else: ?>
    <p class="small text-secondary">
        An item nobody has a score in yet (e.g. an exam not given yet) is not counted. In the other items, a blank counts as 0. PS = percentage score, WS = weighted score.
        The quarterly grade is the initial grade converted with the DepEd transmutation table. Totals update as you type.
    </p>
    <form method="post" action="<?= e(url($here)) ?>" id="record-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_scores">
        <div class="table-responsive record-wrap">
            <table class="table table-sm table-bordered align-middle table-record mb-0" data-weights="<?= e(json_encode($weights)) ?>">
                <thead>
                <tr>
                    <th rowspan="2" class="sticky-col">Learner</th>
                    <?php foreach (ClassRecord::COMPONENTS as $c => $label):
                        $span = max(1, count($byComponent[$c])) + 3; ?>
                        <th colspan="<?= $span ?>" class="text-center comp-<?= $c ?>"><?= e($label) ?> <span class="fw-normal">(<?= format_grade($weights[$c]) ?>%)</span></th>
                    <?php endforeach; ?>
                    <th rowspan="2" class="text-center">Initial<br>grade</th>
                    <th rowspan="2" class="text-center">Quarterly<br>grade</th>
                    <th rowspan="2" class="text-center">In grade<br>sheet</th>
                </tr>
                <tr class="small">
                    <?php foreach (ClassRecord::COMPONENTS as $c => $label): ?>
                        <?php if (!$byComponent[$c]): ?>
                            <th class="text-center text-secondary fw-normal comp-<?= $c ?>">none yet</th>
                        <?php endif; ?>
                        <?php foreach ($byComponent[$c] as $i => $a): ?>
                            <th class="text-center comp-<?= $c ?> a-col" title="<?= e($a['title'] . ($a['taken_on'] ? ' · ' . date('M j', strtotime($a['taken_on'])) : '')) ?>">
                                <div class="a-title"><?= e($a['title']) ?></div>
                                <div class="text-secondary fw-normal"><?= format_grade($a['max_score']) ?><?= empty($scores[(int) $a['id']]) ? ' · <span class="fst-italic">not given yet</span>' : '' ?></div>
                            </th>
                        <?php endforeach; ?>
                        <th class="text-center comp-<?= $c ?>">Total<div class="text-secondary fw-normal"><?= format_grade($hps[$c]) ?></div></th>
                        <th class="text-center comp-<?= $c ?>">PS</th>
                        <th class="text-center comp-<?= $c ?>">WS</th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($roster as $student):
                    $sid = (int) $student['id'];
                    $r = $results[$sid];
                    $current = $saved[$sid][$quarter] ?? null;
                    $mismatch = $r['grade'] !== null && ($current === null || abs($current - $r['grade']) >= 0.005);
                    $noScores = $r['grade'] === null; ?>
                    <tr data-row>
                        <th scope="row" class="sticky-col fw-normal text-nowrap"><?= e(student_name($student)) ?></th>
                        <?php foreach (ClassRecord::COMPONENTS as $c => $label):
                            $total = 0.0; ?>
                            <?php if (!$byComponent[$c]): ?><td class="text-center text-secondary">–</td><?php endif; ?>
                            <?php foreach ($byComponent[$c] as $a):
                                $aid = (int) $a['id'];
                                $value = $typed[$aid][$sid] ?? (isset($scores[$aid][$sid]) ? format_grade($scores[$aid][$sid]) : '');
                                $total += $scores[$aid][$sid] ?? 0; ?>
                                <td class="text-center p-1">
                                    <input type="text" inputmode="decimal" maxlength="7" class="form-control form-control-sm score-input"
                                           name="s[<?= $aid ?>][<?= $sid ?>]" value="<?= e($value) ?>" data-a="<?= $aid ?>" data-c="<?= $c ?>" data-max="<?= e($a['max_score']) ?>"
                                           aria-label="<?= e($a['title']) ?> score for <?= e(student_name($student)) ?>" <?= $locked ? 'disabled' : '' ?>>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center" data-out="<?= $c ?>-total"><?= $byComponent[$c] && !$noScores ? format_grade($total) : '–' ?></td>
                            <td class="text-center" data-out="<?= $c ?>-ps"><?= $r['ps'][$c] === null || $noScores ? '–' : number_format($r['ps'][$c], 2) ?></td>
                            <td class="text-center fw-semibold" data-out="<?= $c ?>-ws"><?= $r['ps'][$c] === null || $noScores ? '–' : number_format($r['ps'][$c] * $weights[$c] / 100, 2) ?></td>
                        <?php endforeach; ?>
                        <td class="text-center" data-out="initial"><?= $r['initial'] === null ? '–' : number_format($r['initial'], 2) ?></td>
                        <td class="text-center fw-bold <?= $r['grade'] !== null && $r['grade'] < (int) config('passing_grade') ? 'text-danger' : '' ?>" data-out="grade"><?= $r['grade'] ?? '–' ?></td>
                        <td class="text-center <?= $mismatch ? 'table-warning' : '' ?>" title="<?= $mismatch ? 'Different from the class record' : '' ?>"><?= $current === null ? '–' : e(format_grade($current)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$locked): ?>
            <div class="sticky-actions d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small text-secondary">Scores are private to you until you copy the grades to the grade sheet and publish the quarter.</span>
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save scores</button>
            </div>
        <?php endif; ?>
    </form>

    <div class="card mt-4">
        <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div>
                <h2 class="h6 mb-1"><i class="bi bi-box-arrow-in-right me-2"></i>Copy to the grade sheet</h2>
                <p class="small text-secondary mb-0">
                    <?= $withGrade ?> of <?= count($roster) ?> learners have a computed grade; <?= $differs ?> differ<?= $differs === 1 ? 's' : '' ?> from the grade sheet.
                    <?php if ($missingComponents): ?><br><i class="bi bi-exclamation-triangle text-warning"></i> No <?= e(implode(' or ', array_map(static fn ($c) => ClassRecord::COMPONENTS[$c], $missingComponents))) ?> yet — the grade uses the other components only.<?php endif; ?>
                    <?php if ($blankCells): ?><br><i class="bi bi-exclamation-triangle text-warning"></i> <?= $blankCells ?> blank score<?= $blankCells === 1 ? '' : 's' ?> in items already given (counted as 0). Save your scores before copying.<?php endif; ?>
                </p>
            </div>
            <?php if (!$locked): ?>
                <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply">
                    <button class="btn btn-success" <?= $differs === 0 ? 'disabled' : '' ?> onclick="return confirm('Replace <?= $differs ?> Quarter <?= $quarter ?> grade(s) in the grade sheet with the computed grades? Unsaved score changes on this page will be lost.')">
                        <i class="bi bi-arrow-right-circle me-1"></i>Use computed grades
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <h2 class="h5 mt-5" id="manage">Assessments</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle shadow-sm">
            <thead><tr><th>Component</th><th>Title</th><th>Highest score</th><th>Date</th><th>Scored</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($assessments as $a):
                $aid = (int) $a['id'];
                $scored = count($scores[$aid] ?? []); ?>
                <tr>
                    <td><span class="badge text-bg-light border"><?= $componentShort[$a['component']] ?></span></td>
                    <?php if ($locked): ?>
                        <td><?= e($a['title']) ?></td>
                        <td><?= format_grade($a['max_score']) ?></td>
                        <td><?= $a['taken_on'] ? e(date('M j, Y', strtotime($a['taken_on']))) : '–' ?></td>
                        <td><?= $scored ?>/<?= count($roster) ?></td>
                        <td></td>
                    <?php else: ?>
                        <td colspan="3">
                            <form method="post" action="<?= e(url($here)) ?>" class="d-flex flex-wrap gap-2 m-0" id="edit-<?= $aid ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="assessment_id" value="<?= $aid ?>">
                                <input class="form-control form-control-sm" style="max-width: 16rem" name="title" value="<?= e($a['title']) ?>" maxlength="120" required aria-label="Title">
                                <input class="form-control form-control-sm" style="max-width: 6rem" name="max_score" value="<?= e(format_grade($a['max_score'])) ?>" inputmode="decimal" required aria-label="Highest possible score">
                                <input class="form-control form-control-sm" style="max-width: 10rem" type="date" name="taken_on" value="<?= e($a['taken_on'] ?? '') ?>" aria-label="Date">
                                <button class="btn btn-sm btn-outline-primary">Save</button>
                            </form>
                        </td>
                        <td><?= $scored ?>/<?= count($roster) ?></td>
                        <td class="text-end">
                            <form method="post" action="<?= e(url($here)) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="assessment_id" value="<?= $aid ?>">
                                <button class="btn btn-sm btn-outline-danger" onclick="return confirm(<?= e(json_encode('Delete "' . $a['title'] . '" and all its scores?')) ?>)" aria-label="Delete <?= e($a['title']) ?>"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<script src="<?= e(asset('assets/class-record.js')) ?>" defer></script>
<?php require APP_ROOT . '/templates/footer.php'; ?>
