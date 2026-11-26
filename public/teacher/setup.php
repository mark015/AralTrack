<?php
// Setup guide for a teacher who signed up alone: 1 school year, 2 classes, 3 learners, 4 QR cards.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
if (!Workspace::isSolo()) {
    redirect(home_for($staff)); // schools are set up by their registrar
}
$here = 'teacher/setup.php';
$errors = [];
$importResult = null;
$classForm = ['grade_level' => '', 'strand' => '', 'section' => '', 'subject' => '', 'other' => '', 'semester' => (string) setting('current_semester'), 'adviser' => ''];
$pasteForm = ['section_id' => 0, 'pasted' => ''];

if (is_post()) {
    $action = post('action');
    try {
        if ($action === 'school') {
            TeacherSetup::saveSchool(post('school_name'), post('year'), post_int('semester'));
            flash('success', 'Saved. Next, add the classes you teach.');
            redirect($here . '#classes');
        }
        if ($action === 'class') {
            foreach (array_keys($classForm) as $field) {
                $classForm[$field] = post($field);
            }
            $class = TeacherSetup::addClass(
                $staff, (int) $classForm['grade_level'], $classForm['strand'], $classForm['section'],
                $classForm['subject'], $classForm['other'], (int) $classForm['semester'], $classForm['adviser'] === '1'
            );
            Access::log('class', "Added the class {$class['subject_name']} – " . section_label($class), 'class', (int) $class['id']);
            flash('success', "Added {$class['subject_name']} for " . section_label($class) . '. Add another class, or go on to your learners.');
            redirect($here . '#classes');
        }
        if ($action === 'learners') {
            $pasteForm = ['section_id' => post_int('section_id'), 'pasted' => (string) ($_POST['pasted'] ?? '')];
            $section = null;
            foreach (TeacherSetup::sections($staff) as $s) {
                if ((int) $s['id'] === $pasteForm['section_id']) {
                    $section = $s;
                }
            }
            if (!$section) {
                throw new UserFacingException('Choose one of your sections.');
            }
            $importResult = TeacherSetup::importPasted($pasteForm['pasted'], $section);
            if (!$importResult['errors']) {
                $count = $importResult['created'] + $importResult['updated'] + $importResult['unchanged'];
                Access::log('learners', "Added {$count} learner(s) to " . section_label($section) . ' from the setup guide', 'section', (int) $section['id']);
                flash('success', "{$count} learner(s) are in " . section_label($section) . '. Add another section, or print the QR cards.');
                redirect($here . '#learners');
            }
            $errors[] = 'Nothing was saved. Fix the lines below and paste again.';
        }
        if ($action === 'finish') {
            save_settings(['setup_done' => '1']);
            flash('success', 'You\'re all set. Open a class to take attendance or keep its class record.');
            redirect('teacher/index.php');
        }
    } catch (UserFacingException $e) {
        $errors[] = $e->getMessage();
    }
}

$sy = active_school_year();
$classes = $sy ? db_all(
    'SELECT ca.id, ca.semester, sub.name AS subject_name, sec.grade_level, sec.strand, sec.name AS section_name
       FROM class_assignments ca JOIN subjects sub ON sub.id = ca.subject_id JOIN sections sec ON sec.id = ca.section_id
      WHERE ca.teacher_id = ? AND sec.school_year_id = ? AND ca.workspace_id = ?
      ORDER BY ca.semester, sec.grade_level, sec.name, sub.name',
    [$staff['id'], $sy['id'], ws()]
) : [];
$sections = TeacherSetup::sections($staff);
$learnerTotal = array_sum(array_map(static fn ($s) => (int) $s['learners'], $sections));
$mySubjects = db_all('SELECT id, code, name FROM subjects WHERE workspace_id = ? ORDER BY sort_order, name', [ws()]);
$haveCodes = array_column($mySubjects, 'code');
$steps = [
    'school'   => $sy !== null,
    'classes'  => (bool) $classes,
    'learners' => $learnerTotal > 0,
];
$step = match (false) {
    $steps['school'] => 1,
    $steps['classes'] => 2,
    $steps['learners'] => 3,
    default => 4,
};
$badge = static fn (bool $done, int $number) => $done
    ? '<span class="step-num bg-success" aria-label="Done"><i class="bi bi-check-lg" aria-hidden="true"></i></span>'
    : '<span class="step-num">' . $number . '</span>';

$title = 'Setup guide';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Welcome, <?= e(explode(' ', $staff['full_name'])[0]) ?>!</h1>
        <p class="sub">Four steps and your classes are ready. You can change everything later under Setup.</p>
    </div>
    <?php if ($step === 4): ?><a class="btn btn-outline-secondary" href="<?= e(url('teacher/index.php')) ?>">Go to my classes</a><?php endif; ?>
</div>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><i class="bi bi-x-octagon-fill" aria-hidden="true"></i><div><?= e(implode(' ', $errors)) ?></div></div><?php endif; ?>

<div class="setup-steps">
    <!-- 1. School year -->
    <section class="card shadow-sm mb-3" id="school">
        <div class="card-body">
            <div class="d-flex gap-3 align-items-start">
                <?= $badge($steps['school'], 1) ?>
                <div class="flex-grow-1">
                    <h2 class="h5 mb-1">Your school and school year</h2>
                    <p class="small text-secondary">The school name is printed on QR cards and report cards.</p>
                    <form method="post" action="<?= e(url($here)) ?>#school" class="row g-3 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="school">
                        <div class="col-md-5">
                            <label class="form-label small" for="school_name">School</label>
                            <input class="form-control" id="school_name" name="school_name" value="<?= e(setting('school_name')) ?>" maxlength="150" required>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small" for="year">School year</label>
                            <?php if ($sy): ?>
                                <input class="form-control" id="year" value="S.Y. <?= e($sy['label']) ?>" disabled>
                            <?php else: ?>
                                <input class="form-control" id="year" name="year" value="<?= e(TeacherSetup::suggestedYear()) ?>" pattern="\d{4}-\d{4}" required>
                            <?php endif; ?>
                        </div>
                        <div class="col-sm-6 col-md-2">
                            <label class="form-label small" for="semester">Semester now</label>
                            <select class="form-select" id="semester" name="semester">
                                <option value="1"<?= setting('current_semester') == 2 ? '' : ' selected' ?>>1st</option>
                                <option value="2"<?= setting('current_semester') == 2 ? ' selected' : '' ?>>2nd</option>
                            </select>
                        </div>
                        <div class="col-md-2"><button class="btn <?= $step === 1 ? 'btn-primary' : 'btn-outline-primary' ?> w-100">Save</button></div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- 2. Classes -->
    <section class="card shadow-sm mb-3" id="classes">
        <div class="card-body">
            <div class="d-flex gap-3 align-items-start">
                <?= $badge($steps['classes'], 2) ?>
                <div class="flex-grow-1 min-w-0">
                    <h2 class="h5 mb-1">The classes you teach</h2>
                    <p class="small text-secondary">One subject in one section is one class. Add each of them.</p>
                    <?php if ($classes): ?>
                        <ul class="list-group list-group-flush mb-3">
                            <?php foreach ($classes as $c): ?>
                                <li class="list-group-item px-0 d-flex justify-content-between gap-2">
                                    <span><strong><?= e($c['subject_name']) ?></strong> · <?= e(section_label($c)) ?></span>
                                    <span class="small text-secondary text-nowrap"><?= e(semester_label((int) $c['semester'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($sy): ?>
                        <form method="post" action="<?= e(url($here)) ?>#classes" class="row g-3 align-items-end">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="class">
                            <div class="col-6 col-md-2">
                                <label class="form-label small" for="grade_level">Grade</label>
                                <select class="form-select" id="grade_level" name="grade_level" required>
                                    <?php foreach ((array) config('grade_levels') as $g): ?>
                                        <option value="<?= (int) $g ?>"<?= (string) $classForm['grade_level'] === (string) $g ? ' selected' : '' ?>>Grade <?= (int) $g ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small" for="strand">Strand</label>
                                <input class="form-control" id="strand" name="strand" value="<?= e($classForm['strand']) ?>" list="strands" maxlength="50" placeholder="e.g. STEM">
                                <?= strand_datalist('strands') ?>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="section">Section</label>
                                <input class="form-control" id="section" name="section" value="<?= e($classForm['section']) ?>" maxlength="50" list="my-sections" placeholder="e.g. Rizal" required>
                                <datalist id="my-sections"><?php foreach ($sections as $s): ?><option value="<?= e($s['section_name']) ?>"><?php endforeach; ?></datalist>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small" for="subject">Subject</label>
                                <select class="form-select" id="subject" name="subject" data-other-toggle>
                                    <?php if ($mySubjects): ?>
                                        <optgroup label="Your subjects">
                                            <?php foreach ($mySubjects as $sub): ?>
                                                <option value="id:<?= (int) $sub['id'] ?>"<?= $classForm['subject'] === 'id:' . $sub['id'] ? ' selected' : '' ?>><?= e($sub['name']) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                    <optgroup label="Senior High School subjects">
                                        <?php foreach (TeacherSetup::SUBJECTS as $code => [$name]): if (in_array($code, $haveCodes, true)) continue; ?>
                                            <option value="code:<?= e($code) ?>"<?= $classForm['subject'] === 'code:' . $code ? ' selected' : '' ?>><?= e($name) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <option value=""<?= $classForm['subject'] === '' && $classForm['other'] !== '' ? ' selected' : '' ?>>Another subject (type its name)</option>
                                </select>
                            </div>
                            <div class="col-md-5" data-other-box<?= $classForm['subject'] === '' && $classForm['other'] !== '' ? '' : ' hidden' ?>>
                                <label class="form-label small" for="other">Subject name</label>
                                <input class="form-control" id="other" name="other" value="<?= e($classForm['other']) ?>" maxlength="100">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small" for="class-semester">Semester</label>
                                <select class="form-select" id="class-semester" name="semester">
                                    <option value="1"<?= $classForm['semester'] == 2 ? '' : ' selected' ?>>1st</option>
                                    <option value="2"<?= $classForm['semester'] == 2 ? ' selected' : '' ?>>2nd</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="adviser" name="adviser" value="1"<?= $classForm['adviser'] === '1' ? ' checked' : '' ?>>
                                    <label class="form-check-label small" for="adviser">I'm this section's adviser</label>
                                </div>
                            </div>
                            <div class="col-md-2"><button class="btn <?= $step === 2 ? 'btn-primary' : 'btn-outline-primary' ?> w-100">Add class</button></div>
                        </form>
                    <?php else: ?>
                        <p class="small text-secondary mb-0">Save step 1 first.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- 3. Learners -->
    <section class="card shadow-sm mb-3" id="learners">
        <div class="card-body">
            <div class="d-flex gap-3 align-items-start">
                <?= $badge($steps['learners'], 3) ?>
                <div class="flex-grow-1 min-w-0">
                    <h2 class="h5 mb-1">Your learners</h2>
                    <?php if (!$sections): ?>
                        <p class="small text-secondary mb-0">Add a class first.</p>
                    <?php else: ?>
                        <p class="small text-secondary">
                            Copy the rows from your class list in Excel or Google Sheets and paste them here, one learner per line, in this order:
                            <strong>LRN, last name, first name, middle name, sex (M/F), birthdate</strong>.
                            Leave a cell empty when you don't have it, except the LRN, names and birthdate.
                        </p>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <?php foreach ($sections as $s): ?>
                                <span class="badge rounded-pill text-bg-light border"><?= e(section_label($s)) ?>: <?= (int) $s['learners'] ?> learner<?= (int) $s['learners'] === 1 ? '' : 's' ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($importResult && $importResult['errors']): ?>
                            <div class="alert alert-warning small" role="alert">
                                <ul class="mb-0 ps-3">
                                    <?php foreach (array_slice($importResult['errors'], 0, 15) as $err): ?>
                                        <li>Line <?= (int) $err['line'] ?><?= $err['lrn'] !== '' ? ' (LRN ' . e($err['lrn']) . ')' : '' ?>: <?= e($err['message']) ?></li>
                                    <?php endforeach; ?>
                                    <?php if (count($importResult['errors']) > 15): ?><li>…and <?= count($importResult['errors']) - 15 ?> more.</li><?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url($here)) ?>#learners">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="learners">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label small" for="section_id">Section</label>
                                    <select class="form-select" id="section_id" name="section_id">
                                        <?php foreach ($sections as $s): ?>
                                            <option value="<?= (int) $s['id'] ?>"<?= $pasteForm['section_id'] === (int) $s['id'] ? ' selected' : '' ?>><?= e(section_label($s)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small" for="pasted">Learners</label>
                                    <textarea class="form-control font-monospace small" id="pasted" name="pasted" rows="6" spellcheck="false"
                                              placeholder="100000260001&#9;Dela Cruz&#9;Juan&#9;Santos&#9;M&#9;2009-04-15"><?= e($pasteForm['pasted']) ?></textarea>
                                </div>
                                <div class="col-12 d-flex flex-wrap gap-3 align-items-center">
                                    <button class="btn <?= $step === 3 ? 'btn-primary' : 'btn-outline-primary' ?>">Add learners</button>
                                    <span class="small text-secondary">Or use an Excel file: <a href="<?= e(url('admin/students.php')) ?>">Learners → Import</a>.</span>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- 4. QR cards -->
    <section class="card shadow-sm mb-3" id="print">
        <div class="card-body">
            <div class="d-flex gap-3 align-items-start">
                <?= $badge(TeacherSetup::done(), 4) ?>
                <div class="flex-grow-1">
                    <h2 class="h5 mb-1">QR ID cards and parent slips</h2>
                    <p class="small text-secondary">
                        Print a QR card for each learner (you scan it at the start of class) and a slip for each parent
                        (with the code to create their account). Ten per A4 page.
                    </p>
                    <?php if ($learnerTotal > 0): ?>
                        <div class="d-flex flex-column gap-2 mb-3">
                            <?php foreach ($sections as $s): if (!(int) $s['learners']) continue; ?>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="me-2"><?= e(section_label($s)) ?></span>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/print.php?section=' . (int) $s['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-qr-code me-1" aria-hidden="true"></i>QR ID cards</a>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/print.php?type=slips&section=' . (int) $s['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-people me-1" aria-hidden="true"></i>Parent slips</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url($here)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="finish">
                        <button class="btn <?= $step === 4 ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $step === 4 ? 'Finish' : 'Skip the rest for now' ?></button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>
<script>
    // "Another subject" shows the box for its name
    document.querySelectorAll('[data-other-toggle]').forEach(function (select) {
        var box = document.querySelector('[data-other-box]');
        var sync = function () { if (box) box.hidden = select.value !== ''; };
        select.addEventListener('change', sync);
        sync();
    });
</script>
<?php require APP_ROOT . '/templates/footer.php'; ?>
