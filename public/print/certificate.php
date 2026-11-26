<?php
// Printable Certificate of Enrollment or Certificate of Good Moral Character.
//   ?request=ID                       for a document request
//   ?student=ID&type=enrollment|good_moral
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin', 'principal');
$request = null;
if ($requestId = get_int('request')) {
    $request = db_one('SELECT * FROM document_requests WHERE id = ? AND workspace_id = ?', [$requestId, ws()]) ?? abort(404, 'That request does not exist.');
    $type = $request['doc_type'];
    $studentId = (int) $request['student_id'];
} else {
    $type = get_str('type');
    $studentId = get_int('student');
}
if (!in_array($type, ['enrollment', 'good_moral'], true)) {
    abort(404, 'Only certificates of enrollment and good moral character are printed here.');
}
$student = db_one('SELECT * FROM students WHERE id = ? AND workspace_id = ?', [$studentId, ws()]) ?? abort(404, 'That learner does not exist.');
$section = current_section_of($studentId);
$last = $section ?: db_one(
    'SELECT sec.grade_level, sec.strand, sec.name AS section_name, sy.label AS sy_label
       FROM enrollments e JOIN sections sec ON sec.id = e.section_id JOIN school_years sy ON sy.id = e.school_year_id
      WHERE e.student_id = ? ORDER BY sy.label DESC LIMIT 1',
    [$studentId]
);
if ($type === 'enrollment' && !$section) {
    abort(409, 'This learner is not enrolled in the active school year, so a certificate of enrollment cannot be issued.');
}

$name = mb_strtoupper(trim($student['first_name'] . ' ' . ($student['middle_name'] ? mb_substr($student['middle_name'], 0, 1) . '. ' : '') . $student['last_name']));
[$his, $he] = match ($student['sex']) { 'M' => ['his', 'he'], 'F' => ['her', 'she'], default => ['the learner\'s', 'the learner'] };
$strand = $last && $last['strand'] ? $last['strand'] . ' strand' : '';
$requester = null;
if ($request) {
    $requester = $request['requested_by_type'] === 'parent'
        ? db_value('SELECT full_name FROM parents WHERE id = ?', [$request['requested_by_id']])
        : trim($student['first_name'] . ' ' . $student['last_name']);
}
$purpose = $request['purpose'] ?? null;
$copies = (int) ($request['copies'] ?? 1);
$principal = setting('principal_name') ?: '______________________________';
$address = setting('school_address');
$issued = 'Issued this ' . date('jS') . ' day of ' . date('F Y') . ($address ? ' at ' . $address : '') . '.';

$printTitle = $type === 'enrollment' ? 'Certificate of Enrollment' : 'Certificate of Good Moral Character';
$printBack = $request ? 'admin/requests.php' : 'admin/student-edit.php?id=' . $studentId;
$printNote = $printTitle . ' · ' . $name . ($copies > 1 ? " · {$copies} copies" : '');
require APP_ROOT . '/templates/print-head.php';
?>
<style>
    .cert { font: 12pt/1.8 Georgia, "Times New Roman", serif; }
    .cert h1 { font: 700 17pt/1.2 Georgia, serif; letter-spacing: .12em; margin: 26px 0 30px; }
    .cert p { text-indent: 3em; text-align: justify; margin: 0 0 14px; }
    .cert .to { text-indent: 0; font-weight: 700; margin-bottom: 18px; }
    .cert .signatory { margin: 70px 0 0 auto; width: 18rem; text-align: center; line-height: 1.3; }
    .cert .signatory strong { display: block; border-top: 1px solid #000; padding-top: 4px; }
    .cert .seal { margin-top: 60px; font-size: 9pt; color: #555; text-indent: 0; }
</style>
<?php for ($copy = 1; $copy <= $copies; $copy++): ?>
    <section class="page cert">
        <?php require APP_ROOT . '/templates/deped-header.php'; ?>
        <h1 style="text-align: center"><?= $type === 'enrollment' ? 'CERTIFICATE OF ENROLLMENT' : 'CERTIFICATE OF GOOD MORAL CHARACTER' ?></h1>
        <p class="to">TO WHOM IT MAY CONCERN:</p>
        <?php if ($type === 'enrollment'): ?>
            <p>
                This is to certify that <strong><?= e($name) ?></strong>, with Learner Reference Number (LRN) <strong><?= e($student['lrn']) ?></strong>,
                is officially enrolled as a <strong>Grade <?= (int) $section['grade_level'] ?></strong> learner<?= $strand ? ' under the <strong>' . e($strand) . '</strong>' : '' ?>,
                section <strong><?= e($section['section_name']) ?></strong>, of this school for School Year <strong><?= e($section['sy_label']) ?></strong>.
            </p>
        <?php else: ?>
            <p>
                This is to certify that <strong><?= e($name) ?></strong>, with Learner Reference Number (LRN) <strong><?= e($student['lrn']) ?></strong>,
                <?= $section ? 'is a bona fide learner' : 'was a learner' ?> of this school<?= $last ? ', Grade ' . (int) $last['grade_level'] . ($strand ? ' ' . e($strand) : '') . ', School Year ' . e($last['sy_label']) : '' ?>.
            </p>
            <p>
                Based on the school's records, <?= e($he) ?> has shown good moral character and has not been subjected to any disciplinary action
                during <?= e($his) ?> stay in this school.
            </p>
        <?php endif; ?>
        <p>
            This certification is issued<?= $requester ? ' upon the request of <strong>' . e($requester) . '</strong>' : '' ?><?= $purpose ? ' for <strong>' . e($purpose) . '</strong>' : ' for whatever legal purpose it may serve' ?>.
        </p>
        <p><?= e($issued) ?></p>
        <div class="signatory">
            <strong><?= e($principal) ?></strong>
            School Principal
        </div>
        <p class="seal">Not valid without the school seal. <?= $copies > 1 ? "Copy {$copy} of {$copies}." : '' ?></p>
    </section>
<?php endfor; ?>
</body>
</html>
