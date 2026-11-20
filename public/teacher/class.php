<?php
// One class: status of its two quarters, template/upload/publish buttons, an editable grade grid,
// and grade change requests for quarters that are locked (past their deadline).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$class = teacher_class_or_abort(get_int('id'), $staff);
$classId = (int) $class['id'];
$syId = (int) $class['school_year_id'];
$quarters = class_quarters($class);
$locked = array_values(array_filter($quarters, static fn ($q) => Grading::isLocked($syId, $q)));
$here = 'teacher/class.php?id=' . $classId;

$roster = section_roster((int) $class['section_id']);
$rosterById = array_column($roster, null, 'id');
$grades = class_grades($classId);
$published = class_publications($classId);
$min = (float) config('grade_min');
$max = (float) config('grade_max');

$errors = [];
$typed = null; // what the teacher typed, shown again when there are errors
$encodingOpen = Access::gradeEncodingOpen();
if (is_post() && !$encodingOpen) {
    flash('warning', 'Grade encoding is turned off by the school right now, so grades are read-only.');
    redirect($here);
}

if (is_post() && post('action') === 'request_change') {
    $studentId = post_int('student_id');
    $quarter = post_int('quarter');
    $raw = post('new_grade');
    $reason = mb_substr(post('reason'), 0, 1000);
    $newGrade = $raw === '' ? null : (is_numeric($raw) ? round((float) $raw, 2) : false);
    if (!isset($rosterById[$studentId]) || !in_array($quarter, $locked, true)) {
        flash('danger', 'Choose a learner and a locked quarter.');
    } elseif ($newGrade === false || ($newGrade !== null && ($newGrade < $min || $newGrade > $max))) {
        flash('danger', 'The new grade must be a number from ' . format_grade($min) . ' to ' . format_grade($max) . ', or blank to remove the grade.');
    } elseif (mb_strlen($reason) < 5) {
        flash('danger', 'Explain the reason for the change.');
    } else {
        db_exec(
            'INSERT INTO grade_change_requests (student_id, class_assignment_id, quarter, old_grade, new_grade, reason, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$studentId, $classId, $quarter, $grades[$studentId][$quarter] ?? null, $newGrade, $reason, $staff['id']]
        );
        flash('success', 'Change request sent to the registrar for approval.');
    }
    redirect($here . '#change-requests');
}

if (is_post() && post('action') === 'save_grid') {
    $submitted = is_array($_POST['g'] ?? null) ? $_POST['g'] : [];
    $typed = [];
    $changes = [];
    foreach ($roster as $student) {
        $sid = (int) $student['id'];
        $row = is_array($submitted[$sid] ?? null) ? $submitted[$sid] : [];
        foreach ($quarters as $q) {
            if (in_array($q, $locked, true) || !array_key_exists($q, $row) || !is_string($row[$q])) {
                continue; // locked, or not on the form (e.g. learner added after the page loaded): leave as is
            }
            $raw = trim($row[$q]);
            $typed[$sid][$q] = $raw;
            $current = $grades[$sid][$q] ?? null;

            if ($raw === '') {
                if ($current !== null) {
                    $changes[] = [$sid, $q, null];
                }
                continue;
            }
            if (!is_numeric($raw)) {
                $errors[] = student_name($student) . " — Q{$q}: \"{$raw}\" is not a number.";
                continue;
            }
            $grade = round((float) $raw, 2);
            if ($grade < $min || $grade > $max) {
                $errors[] = student_name($student) . " — Q{$q}: {$raw} is outside " . format_grade($min) . '–' . format_grade($max) . '.';
                continue;
            }
            if ($current === null || abs($current - $grade) >= 0.005) {
                $changes[] = [$sid, $q, $grade];
            }
        }
    }

    if (!$errors) {
        if (!$changes) {
            flash('info', 'Nothing was changed.');
        } else {
            save_grade_changes($classId, $changes, $staff['id'], 'grid');
            flash('success', count($changes) . ' grade' . (count($changes) === 1 ? '' : 's') . ' saved.');
        }
        redirect($here);
    }
}

$total = count($roster);
$gradedCount = array_fill_keys($quarters, 0);
foreach ($roster as $student) {
    foreach ($quarters as $q) {
        if (isset($grades[(int) $student['id']][$q])) {
            $gradedCount[$q]++;
        }
    }
}
$requests = db_all(
    'SELECT r.*, st.last_name, st.first_name, st.middle_name FROM grade_change_requests r JOIN students st ON st.id = r.student_id
      WHERE r.class_assignment_id = ? ORDER BY r.id DESC LIMIT 20',
    [$classId]
);

$title = $class['subject_name'] . ' · ' . section_label($class);
require APP_ROOT . '/templates/header.php';
$activeTab = 'grades';
$learnerCount = $total;
require APP_ROOT . '/templates/class-tabs.php';
?>
<?php if (!$encodingOpen): ?>
    <div class="alert alert-warning"><i class="bi bi-lock-fill" aria-hidden="true"></i><div>
        <?= Access::on('grading') ? '<strong>Grade encoding is turned off</strong> by the school right now. You can see the grades, but not change, upload or publish them.' : '<strong>Grading is turned off</strong> by the school right now. Grades are read-only.' ?>
    </div></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <i class="bi bi-x-octagon-fill"></i>
        <div><strong>Nothing was saved.</strong> Fix these and press Save again:
            <ul class="mb-0 mt-1"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    </div>
<?php endif; ?>

<?php if (!$roster): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle-fill"></i><div>No learners are enrolled in this section yet. The registrar adds them under Admin → Sections.</div></div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <?php foreach ($quarters as $q):
            $isPublished = isset($published[$q]);
            $isLocked = in_array($q, $locked, true);
            $deadline = Grading::label($syId, $q); ?>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-1 gap-2">
                            <h2 class="h6 mb-0">Quarter <?= $q ?></h2>
                            <div class="d-flex gap-1 flex-wrap justify-content-end">
                                <?php if ($deadline !== ''): ?>
                                    <span class="badge <?= $isLocked ? 'text-bg-danger' : 'text-bg-warning' ?>"><i class="bi bi-<?= $isLocked ? 'lock-fill' : 'calendar-event' ?>"></i><?= e($deadline) ?></span>
                                <?php endif; ?>
                                <?= $isPublished ? '<span class="badge text-bg-success"><i class="bi bi-eye-fill"></i>Published</span>' : '<span class="badge text-bg-secondary">Not published</span>' ?>
                            </div>
                        </div>
                        <p class="small text-secondary mb-3">
                            <?= $gradedCount[$q] ?> of <?= $total ?> graded
                            <?php if ($isPublished): ?> · since <?= e(date('M j, Y', strtotime($published[$q]['published_at']))) ?><?php endif; ?>
                        </p>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (Access::on('class_record')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url("teacher/class-record.php?id={$classId}&q={$q}")) ?>"><i class="bi bi-table me-1"></i>Class record</a>
                            <?php endif; ?>
                            <?php if (!$encodingOpen): ?>
                            <?php elseif (!$isLocked): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url("teacher/template.php?id={$classId}&q={$q}")) ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Template</a>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url("teacher/upload.php?id={$classId}&q={$q}")) ?>"><i class="bi bi-upload me-1"></i>Upload</a>
                            <?php else: ?>
                                <a class="btn btn-sm btn-outline-secondary" href="#change-requests"><i class="bi bi-pencil-square me-1"></i>Request a change</a>
                            <?php endif; ?>
                            <?php if ($encodingOpen): ?>
                            <form method="post" action="<?= e(url('teacher/publish.php')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $classId ?>">
                                <input type="hidden" name="q" value="<?= $q ?>">
                                <?php if ($isPublished): ?>
                                    <input type="hidden" name="action" value="unpublish">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="return confirm('Hide Quarter <?= $q ?> grades from learners?')">Unpublish</button>
                                <?php else:
                                    $confirm = $gradedCount[$q] < $total
                                        ? "Only {$gradedCount[$q]} of {$total} learners have a Quarter {$q} grade. Publish anyway?"
                                        : "Learners will be able to see their Quarter {$q} grades. Publish now?"; ?>
                                    <input type="hidden" name="action" value="publish">
                                    <button class="btn btn-sm btn-success" <?= $gradedCount[$q] === 0 ? 'disabled' : '' ?> onclick="return confirm('<?= e($confirm) ?>')">Publish</button>
                                <?php endif; ?>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <h2 class="h5">Grades</h2>
    <p class="small text-secondary">
        Type or correct grades here, then press <strong>Save</strong>. Clear a cell to remove that grade.
        The final grade is the average of Quarters <?= implode(' and ', $quarters) ?>.
        <?php if ($locked): ?><i class="bi bi-lock-fill"></i> Locked quarters can only be changed through a change request.<?php endif; ?>
    </p>
    <form method="post" action="<?= e(url($here)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_grid">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle shadow-sm table-grades mb-0">
                <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">LRN</th>
                    <th scope="col">Learner</th>
                    <?php foreach ($quarters as $q): ?>
                        <th scope="col" class="q">Q<?= $q ?><?= in_array($q, $locked, true) ? ' <i class="bi bi-lock-fill" title="Locked"></i>' : '' ?><?= isset($published[$q]) ? ' <span class="text-success" title="Published">●</span>' : '' ?></th>
                    <?php endforeach; ?>
                    <th scope="col" class="q">Final</th>
                    <th scope="col">Remarks</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($roster as $i => $student):
                    $sid = (int) $student['id'];
                    $saved = $grades[$sid] ?? [];
                    $final = final_grade($saved, $quarters); ?>
                    <tr>
                        <td class="text-secondary small"><?= $i + 1 ?></td>
                        <td class="lrn"><?= e($student['lrn']) ?></td>
                        <td><?= e(student_name($student)) ?></td>
                        <?php foreach ($quarters as $q):
                            $value = $typed[$sid][$q] ?? format_grade($saved[$q] ?? null);
                            $isLocked = in_array($q, $locked, true); ?>
                            <td class="q">
                                <input type="text" inputmode="decimal" maxlength="6" class="form-control form-control-sm grade-input mx-auto"
                                       name="g[<?= $sid ?>][<?= $q ?>]" value="<?= e($value) ?>" <?= $isLocked || !$encodingOpen ? 'disabled' : '' ?>
                                       aria-label="Quarter <?= $q ?> grade for <?= e(student_name($student)) ?>">
                            </td>
                        <?php endforeach; ?>
                        <td class="q fw-semibold <?= remarks($final) === 'Failed' ? 'text-danger' : '' ?>"><?= $final ?? '–' ?></td>
                        <td class="small"><?= e(remarks($final)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="sticky-actions d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="small text-secondary">Grades <?= e(format_grade($min)) ?>–<?= e(format_grade($max)) ?>. Edits to a published quarter are visible to learners right away.</span>
            <button class="btn btn-primary" <?= count($locked) === count($quarters) || !$encodingOpen ? 'disabled' : '' ?>><i class="bi bi-check2 me-1"></i>Save grades</button>
        </div>
    </form>

    <h2 class="h5 mt-5" id="change-requests">Grade change requests</h2>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <?php if (!$encodingOpen): ?>
                        <p class="small text-secondary mb-0"><i class="bi bi-lock me-1"></i>Grade encoding is turned off by the school, so change requests can't be sent right now.</p>
                    <?php elseif (!$locked): ?>
                        <p class="small text-secondary mb-0"><i class="bi bi-unlock me-1"></i>No quarter is locked yet, so you can still edit grades directly above. After a quarter's deadline, request changes here.</p>
                    <?php else: ?>
                        <form method="post" action="<?= e(url($here)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="request_change">
                            <div class="mb-2">
                                <label class="form-label small" for="rc-student">Learner</label>
                                <select class="form-select form-select-sm" id="rc-student" name="student_id" required>
                                    <?php foreach ($roster as $st): ?><option value="<?= (int) $st['id'] ?>"><?= e(student_name($st)) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small" for="rc-q">Quarter</label>
                                    <select class="form-select form-select-sm" id="rc-q" name="quarter">
                                        <?php foreach ($locked as $q): ?><option value="<?= $q ?>">Quarter <?= $q ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small" for="rc-grade">New grade</label>
                                    <input class="form-control form-control-sm" id="rc-grade" name="new_grade" inputmode="decimal" placeholder="blank = remove">
                                </div>
                            </div>
                            <label class="form-label small" for="rc-reason">Reason</label>
                            <textarea class="form-control form-control-sm mb-2" id="rc-reason" name="reason" rows="2" maxlength="1000" required placeholder="e.g. Late requirement submitted with an approved excuse"></textarea>
                            <button class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Send request</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <?php if (!$requests): ?>
                <p class="small text-secondary">No change requests for this class.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle shadow-sm">
                        <thead><tr><th>Learner</th><th>Q</th><th>Change</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td><?= e(student_name($r)) ?><div class="small text-secondary"><?= e($r['reason']) ?></div></td>
                                <td>Q<?= (int) $r['quarter'] ?></td>
                                <td class="text-nowrap"><?= $r['old_grade'] === null ? '–' : e(format_grade($r['old_grade'])) ?> → <strong><?= $r['new_grade'] === null ? 'removed' : e(format_grade($r['new_grade'])) ?></strong></td>
                                <td>
                                    <?= ['pending' => '<span class="badge text-bg-warning">Pending</span>', 'approved' => '<span class="badge text-bg-success">Approved</span>', 'rejected' => '<span class="badge text-bg-danger">Rejected</span>'][$r['status']] ?>
                                    <?php if ($r['review_note']): ?><div class="small text-secondary"><?= e($r['review_note']) ?></div><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
