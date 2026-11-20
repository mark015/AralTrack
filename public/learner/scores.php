<?php
// Learner: own scores in one subject's class record (quizzes, performance tasks, quarterly exam), per quarter.
// The official quarterly grade is shown only after the teacher publishes the quarter.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$student = require_student();
Access::requireModule('class_record');
$studentId = (int) $student['id'];
$section = current_section_of($studentId);
$class = ($classId = get_int('class')) ? class_info($classId) : null;
if (!$class || !$section || (int) $class['section_id'] !== (int) $section['id']) {
    abort(404, 'That subject is not in your class this school year.');
}
$weights = ClassRecord::weights((int) $class['subject_id']);
$published = class_publications($classId);
$grades = class_grades($classId)[$studentId] ?? [];

$quarters = [];
foreach (class_quarters($class) as $q) {
    $assessments = ClassRecord::assessments($classId, $q);
    $scores = ClassRecord::scores($assessments);
    $quarters[$q] = [
        'assessments' => $assessments,
        'scores' => $scores,
        'hps' => ClassRecord::highestPossible($assessments, $scores), // items already given
        'result' => ClassRecord::compute($assessments, $scores, $studentId, $weights),
    ];
}

$title = 'My scores · ' . $class['subject_name'];
require APP_ROOT . '/templates/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('learner/subjects.php')) ?>">Subjects</a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('learner/subjects.php?class=' . $classId)) ?>"><?= e($class['subject_name']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page">My scores</li>
    </ol>
</nav>
<div class="page-head">
    <div>
        <span class="subject-chip mb-1" style="--hue: <?= color_hue($class['subject_code']) ?>"><?= e($class['subject_code']) ?></span>
        <h1 class="h3">My scores · <?= e($class['subject_name']) ?></h1>
        <p class="sub"><?= e($class['teacher_name']) ?> · Written Works <?= format_grade($weights['ww']) ?>%, Performance Tasks <?= format_grade($weights['pt']) ?>%, Quarterly Assessment <?= format_grade($weights['qa']) ?>%</p>
    </div>
</div>

<div class="row g-4">
    <?php foreach ($quarters as $q => $data): ?>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <h2 class="h6 mb-0">Quarter <?= $q ?></h2>
                        <?php if (isset($published[$q]) && isset($grades[$q]) && Access::can('grades')): ?>
                            <span class="badge text-bg-success fs-6">Grade <?= e(format_grade($grades[$q])) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Grade not yet released</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$data['assessments']): ?>
                        <p class="small text-secondary mb-0">Your teacher hasn't recorded any scores for this quarter yet.</p>
                    <?php else: ?>
                        <?php foreach (ClassRecord::COMPONENTS as $c => $label):
                            $items = array_filter($data['assessments'], static fn ($a) => $a['component'] === $c);
                            if (!$items) {
                                continue;
                            }
                            $raw = 0.0; ?>
                            <h3 class="small fw-bold text-secondary text-uppercase mt-3 mb-1"><?= e($label) ?></h3>
                            <table class="table table-sm mb-1">
                                <tbody>
                                <?php foreach ($items as $a):
                                    $score = $data['scores'][(int) $a['id']][$studentId] ?? null;
                                    $raw += $score ?? 0; ?>
                                    <tr>
                                        <td><?= e($a['title']) ?><?php if ($a['taken_on']): ?> <span class="small text-secondary">· <?= e(date('M j', strtotime($a['taken_on']))) ?></span><?php endif; ?></td>
                                        <td class="text-end text-nowrap"><?php if (empty($data['scores'][(int) $a['id']])): ?><span class="text-secondary fst-italic">not given yet</span><?php else: ?><?= $score === null ? '<span class="text-secondary">no score</span>' : '<strong>' . e(format_grade($score)) . '</strong>' ?> / <?= e(format_grade($a['max_score'])) ?><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <?php if ($data['hps'][$c] > 0): ?><tfoot>
                                <tr class="small">
                                    <td class="text-secondary">Total</td>
                                    <td class="text-end"><?= e(format_grade($raw)) ?> / <?= e(format_grade($data['hps'][$c])) ?> · <?= number_format($data['hps'][$c] > 0 ? $raw / $data['hps'][$c] * 100 : 0, 1) ?>%</td>
                                </tr>
                                </tfoot><?php endif; ?>
                            </table>
                        <?php endforeach; ?>
                        <?php if ($data['result']['blanks']): ?>
                            <p class="small text-secondary mb-0 mt-2"><i class="bi bi-info-circle me-1"></i><?= (int) $data['result']['blanks'] ?> item<?= $data['result']['blanks'] === 1 ? '' : 's' ?> without a score. If you think a score is missing, talk to your teacher.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<p class="small text-secondary mt-3">Your official grades are on your <a href="<?= e(url('grades.php')) ?>">report card</a>.</p>
<?php require APP_ROOT . '/templates/footer.php'; ?>
