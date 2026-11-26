<?php
// Printable sheets for a section (or one learner):
//   type=cards  QR ID cards, credit-card size, 10 per A4 page
//   type=slips  parent link slips with each learner's link code
require dirname(__DIR__, 2) . '/src/bootstrap.php';

require_staff('admin');
$type = get_str('type') === 'slips' ? 'slips' : 'cards';
$sy = active_school_year();

if ($sectionId = get_int('section')) {
    $section = db_one(
        'SELECT s.*, s.name AS section_name, sy.label AS sy_label FROM sections s JOIN school_years sy ON sy.id = s.school_year_id WHERE s.id = ? AND s.workspace_id = ?',
        [$sectionId, ws()]
    );
    if (!$section) {
        abort(404, 'That section does not exist.');
    }
    $learners = array_map(
        static fn ($st) => $st + ['section' => $section],
        db_all('SELECT st.* FROM enrollments e JOIN students st ON st.id = e.student_id WHERE e.section_id = ? ORDER BY st.last_name, st.first_name', [$sectionId])
    );
    $heading = section_label($section);
    $back = 'admin/section.php?id=' . $sectionId;
} elseif ($studentId = get_int('student')) {
    $student = db_one('SELECT * FROM students WHERE id = ? AND workspace_id = ?', [$studentId, ws()]);
    if (!$student) {
        abort(404, 'That learner does not exist.');
    }
    $section = $sy ? db_one(
        'SELECT s.*, s.name AS section_name, ? AS sy_label FROM enrollments e JOIN sections s ON s.id = e.section_id
          WHERE e.student_id = ? AND e.school_year_id = ?',
        [$sy['label'], $studentId, $sy['id']]
    ) : null;
    $learners = [$student + ['section' => $section]];
    $heading = student_name($student);
    $back = 'admin/student-edit.php?id=' . $studentId;
} else {
    abort(404, 'Choose a section or a learner to print.');
}

$registerUrl = public_url('parent/register.php');
$registerQr = $type === 'slips' ? qr_image_uri($registerUrl) : '';
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e(($type === 'cards' ? 'QR ID cards' : 'Parent link slips') . ' · ' . $heading) ?></title>
    <style>
        @page { size: A4; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, "Segoe UI", Arial, sans-serif; color: #000; background: #fff; }
        .toolbar { padding: 12px 16px; background: #f1f3f5; border-bottom: 1px solid #dee2e6; font-size: 14px; }
        .toolbar button { font-size: 14px; padding: 4px 12px; margin-right: 8px; }
        .sheet { display: grid; grid-template-columns: repeat(2, auto); gap: 3mm; justify-content: center; padding: 6mm 0; }
        .id-card { width: 85.6mm; height: 54mm; border: 0.3mm dashed #999; padding: 3mm; display: flex; gap: 3mm; break-inside: avoid; }
        .id-card .qr { width: 36mm; text-align: center; font-size: 6.5pt; }
        .id-card .qr img { width: 36mm; height: 36mm; display: block; }
        .id-card .info { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .school { font-size: 6.5pt; text-transform: uppercase; letter-spacing: .03em; color: #444; }
        .name { font-weight: 700; font-size: 10pt; line-height: 1.15; margin: 1.5mm 0 1mm; overflow-wrap: anywhere; }
        .meta { font-size: 7.5pt; line-height: 1.35; }
        .lrn { font-family: Consolas, "Courier New", monospace; }
        .slip { width: 96mm; min-height: 62mm; border: 0.3mm dashed #999; padding: 4mm; break-inside: avoid; font-size: 8.5pt; line-height: 1.35; position: relative; }
        .slip h2 { font-size: 10pt; margin: 0 0 1.5mm; }
        .slip .code { font-family: Consolas, "Courier New", monospace; font-size: 15pt; font-weight: 700; letter-spacing: .08em; margin: 1.5mm 0; }
        .slip .reg-qr { position: absolute; right: 4mm; top: 4mm; width: 20mm; height: 20mm; }
        .slip ol { margin: 1mm 0 1.5mm; padding-left: 4.5mm; }
        .slip .url { overflow-wrap: anywhere; font-weight: 600; }
        .private { font-size: 7pt; color: #444; }
        @media print { .toolbar { display: none; } .sheet { padding: 0; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">Print</button>
    <a href="<?= e(url($back)) ?>">Back</a>
    · <?= count($learners) ?> <?= $type === 'cards' ? 'card' : 'slip' ?><?= count($learners) === 1 ? '' : 's' ?> for <?= e($heading) ?>.
    <?= $type === 'cards'
        ? 'Cards are ID-card size (85.6 × 54 mm). Cut along the dashed lines and laminate, or slip into an ID holder.'
        : 'Give each slip to the learner\'s parent or guardian. The code lets them create a parent account.' ?>
</div>

<?php if (!$learners): ?>
    <p style="padding:16px">No learners are enrolled in this section yet.</p>
<?php endif; ?>

<div class="sheet">
    <?php foreach ($learners as $st): $sec = $st['section']; ?>
        <?php if ($type === 'cards'): ?>
            <div class="id-card">
                <div class="qr">
                    <img src="<?= e(qr_image_uri(qr_payload($st['qr_token']))) ?>" alt="">
                    Scan for class attendance
                </div>
                <div class="info">
                    <div class="school"><?= e(setting('school_name')) ?></div>
                    <div class="name"><?= e(student_name($st)) ?></div>
                    <div class="meta">
                        LRN <span class="lrn"><?= e($st['lrn']) ?></span><br>
                        <?php if ($sec): ?><?= e(section_label($sec)) ?><br>S.Y. <?= e($sec['sy_label']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="slip">
                <img class="reg-qr" src="<?= e($registerQr) ?>" alt="">
                <h2>Parent portal link slip</h2>
                <div><?= e(setting('school_name')) ?></div>
                <div style="margin-top:1.5mm"><strong><?= e(student_name($st)) ?></strong></div>
                <div>LRN <span class="lrn"><?= e($st['lrn']) ?></span><?= $sec ? ' · ' . e(section_label($sec)) : '' ?></div>
                <div style="margin-top:1.5mm">Link code:</div>
                <div class="code"><?= e(format_parent_code($st['parent_code'])) ?></div>
                <ol>
                    <li>Open <span class="url"><?= e($registerUrl) ?></span> (or scan the QR code).</li>
                    <li>Create an account with your mobile number or email.</li>
                    <li>Enter the LRN and link code above.</li>
                </ol>
                <div class="private">Keep this code private: it lets someone see this learner's attendance and grades.</div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
</body>
</html>
