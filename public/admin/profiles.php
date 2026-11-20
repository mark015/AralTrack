<?php
// Learner profiles for guidance counselors, the principal and the registrar (read-only, plus follow-up notes).
//   admin/profiles.php                 search learners this school year
//   admin/profiles.php?id=ID[&sy=…]    one learner: details, family, attendance, report card, notes, excuse letters
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin', 'principal', 'guidance');
$sy = active_school_year();

if ($studentId = get_int('id')) {
    $student = db_one('SELECT * FROM students WHERE id = ?', [$studentId]) ?? abort(404, 'That learner does not exist.');
    $here = 'admin/profiles.php?id=' . $studentId;

    if (is_post() && post('action') === 'note') {
        $note = mb_substr(trim(post('note')), 0, 1000);
        if (mb_strlen($note) < 3) {
            flash('danger', 'Write the note first.');
        } else {
            db_exec('INSERT INTO interventions (student_id, class_assignment_id, note, created_by) VALUES (?, NULL, ?, ?)', [$studentId, $note, $staff['id']]);
            flash('success', 'Note saved. Advisers and subject teachers see it on the At-risk learners page.');
        }
        redirect($here . '#notes');
    }

    $section = current_section_of($studentId);
    $parents = db_all(
        'SELECT p.id, p.full_name, p.login, p.is_active, ps.relationship FROM parent_students ps JOIN parents p ON p.id = ps.parent_id WHERE ps.student_id = ? ORDER BY p.full_name',
        [$studentId]
    );
    $attendance = [];
    if ($section) {
        $totals = Attendance::studentTotals($studentId, (int) $section['id']);
        foreach (db_all(
            'SELECT ca.id, sub.name FROM class_assignments ca JOIN subjects sub ON sub.id = ca.subject_id WHERE ca.section_id = ? ORDER BY ca.semester, sub.sort_order',
            [$section['id']]
        ) as $class) {
            if (isset($totals[(int) $class['id']])) {
                $attendance[] = ['subject' => $class['name']] + $totals[(int) $class['id']];
            }
        }
    }
    $notes = db_all(
        'SELECT i.*, u.full_name, sub.name AS subject_name FROM interventions i
           LEFT JOIN users u ON u.id = i.created_by
           LEFT JOIN class_assignments ca ON ca.id = i.class_assignment_id
           LEFT JOIN subjects sub ON sub.id = ca.subject_id
          WHERE i.student_id = ? ORDER BY i.id DESC LIMIT 30',
        [$studentId]
    );
    $excuses = db_all('SELECT * FROM excuse_letters WHERE student_id = ? ORDER BY id DESC LIMIT 10', [$studentId]);
    $card = ReportCard::build($studentId, get_int('sy'));
    $age = $student['birthdate'] ? (new DateTime($student['birthdate']))->diff(new DateTime('today'))->y : null;
    $statusLabels = ['active' => 'Active', 'graduated' => 'Graduated', 'transferred_out' => 'Transferred out', 'dropped' => 'Dropped'];

    $title = student_name($student);
    require APP_ROOT . '/templates/header.php';
    ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('admin/profiles.php')) ?>">Learner profiles</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e(student_name($student)) ?></li>
        </ol>
    </nav>
    <div class="page-head">
        <div class="d-flex align-items-center gap-3">
            <?php if ($student['photo_path']): ?>
                <img src="<?= e(url('file.php?kind=photo&id=' . $studentId)) ?>" alt="" class="rounded-circle" width="64" height="64" style="object-fit: cover">
            <?php else: ?>
                <?= avatar($student['first_name'] . ' ' . $student['last_name'], 'avatar-lg') ?>
            <?php endif; ?>
            <div>
                <h1 class="h3"><?= e(student_name($student)) ?></h1>
                <p class="sub">
                    LRN <span class="lrn"><?= e($student['lrn']) ?></span>
                    · <?= $section ? e(section_label($section)) . ($section['adviser'] ? ' · Adviser: ' . e($section['adviser']) : '') : 'Not enrolled this school year' ?>
                    <?php if (($student['status'] ?? 'active') !== 'active'): ?> · <span class="badge text-bg-secondary"><?= e($statusLabels[$student['status']] ?? $student['status']) ?></span><?php endif; ?>
                </p>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if (is_admin($staff)): ?><a class="btn btn-outline-primary" href="<?= e(url('admin/student-edit.php?id=' . $studentId)) ?>"><i class="bi bi-pencil me-1"></i>Edit</a><?php endif; ?>
            <a class="btn btn-outline-primary" href="<?= e(url('print/sf10.php?student=' . $studentId)) ?>" target="_blank"><i class="bi bi-printer me-1"></i>SF10</a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6"><i class="bi bi-person-vcard me-2"></i>Details</h2>
                    <dl class="row small mb-0">
                        <dt class="col-5 fw-normal text-secondary">Sex</dt><dd class="col-7"><?= $student['sex'] === 'M' ? 'Male' : ($student['sex'] === 'F' ? 'Female' : '–') ?></dd>
                        <dt class="col-5 fw-normal text-secondary">Birthdate</dt><dd class="col-7"><?= $student['birthdate'] ? e(date('M j, Y', strtotime($student['birthdate']))) . " ({$age})" : '–' ?></dd>
                        <dt class="col-5 fw-normal text-secondary">Address</dt><dd class="col-7"><?= e($student['address'] ?: '–') ?></dd>
                        <dt class="col-5 fw-normal text-secondary">Contact no.</dt><dd class="col-7"><?= e($student['contact_number'] ?: '–') ?></dd>
                        <dt class="col-5 fw-normal text-secondary">Mother</dt><dd class="col-7"><?= e($student['mother_name'] ?: '–') ?></dd>
                        <dt class="col-5 fw-normal text-secondary">Father</dt><dd class="col-7"><?= e($student['father_name'] ?: '–') ?></dd>
                        <dt class="col-5 fw-normal text-secondary">Guardian</dt><dd class="col-7"><?= e($student['guardian_name'] ? $student['guardian_name'] . ($student['guardian_relationship'] ? ' (' . $student['guardian_relationship'] . ')' : '') : '–') ?></dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6"><i class="bi bi-person-hearts me-2"></i>Parent accounts</h2>
                    <?php if (!$parents): ?>
                        <p class="small text-secondary mb-0">No parent has linked this learner yet.</p>
                    <?php endif; ?>
                    <?php foreach ($parents as $p): ?>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?= avatar($p['full_name']) ?>
                            <div class="flex-grow-1 lh-sm">
                                <div class="fw-semibold small"><?= e($p['full_name']) ?></div>
                                <div class="small text-secondary"><?= e(RELATIONSHIPS[$p['relationship']] ?? $p['relationship']) ?><?= $p['is_active'] ? '' : ' · deactivated' ?></div>
                            </div>
                            <?php if (Access::can('messages')): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('messages.php?new=1&to=' . $studentId . ':' . $p['id'])) ?>" title="Send a message"><i class="bi bi-chat-dots"></i></a><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6"><i class="bi bi-calendar2-check me-2"></i>Attendance this school year</h2>
                    <?php if (!$attendance): ?>
                        <p class="small text-secondary mb-0">No finished class meetings yet.</p>
                    <?php else:
                        $sum = array_fill_keys(['held', 'present', 'late', 'absent', 'excused'], 0);
                        foreach ($attendance as $row) {
                            foreach ($sum as $k => $_) {
                                $sum[$k] += $row[$k];
                            }
                        }
                        $rate = $sum['held'] ? (int) round(($sum['present'] + $sum['late']) / $sum['held'] * 100) : null; ?>
                        <div class="d-flex gap-3 mb-2">
                            <div><div class="fs-4 fw-bold"><?= $rate ?? '–' ?>%</div><div class="small text-secondary">attended</div></div>
                            <div><div class="fs-4 fw-bold text-danger"><?= $sum['absent'] ?></div><div class="small text-secondary">absent</div></div>
                            <div><div class="fs-4 fw-bold text-warning"><?= $sum['late'] ?></div><div class="small text-secondary">late</div></div>
                            <div><div class="fs-4 fw-bold text-info"><?= $sum['excused'] ?></div><div class="small text-secondary">excused</div></div>
                        </div>
                        <details class="small">
                            <summary>By subject</summary>
                            <table class="table table-sm mt-2 mb-0">
                                <thead><tr><th>Subject</th><th class="text-center">Held</th><th class="text-center">Abs</th><th class="text-center">Late</th></tr></thead>
                                <tbody>
                                <?php foreach ($attendance as $row): ?>
                                    <tr><td><?= e($row['subject']) ?></td><td class="text-center"><?= $row['held'] ?></td><td class="text-center <?= $row['absent'] ? 'text-danger fw-semibold' : '' ?>"><?= $row['absent'] ?></td><td class="text-center"><?= $row['late'] ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-7" id="notes">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6"><i class="bi bi-journal-medical me-2"></i>Follow-up notes</h2>
                    <form method="post" action="<?= e(url($here)) ?>" class="mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="note">
                        <textarea class="form-control form-control-sm mb-2" name="note" rows="2" maxlength="1000" required placeholder="e.g. Talked with the learner and the mother; agreed on a study schedule. Follow up on Dec 5."></textarea>
                        <button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add note</button>
                    </form>
                    <?php if (!$notes): ?><p class="small text-secondary mb-0">No notes yet.</p><?php endif; ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($notes as $n): ?>
                            <li class="border-top py-2 small">
                                <?= e($n['note']) ?>
                                <div class="text-secondary"><?= e($n['full_name'] ?? 'Staff') ?><?= $n['subject_name'] ? ' · ' . e($n['subject_name']) : '' ?> · <?= e(when_label($n['created_at'])) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6"><i class="bi bi-envelope-paper me-2"></i>Excuse letters</h2>
                    <?php if (!$excuses): ?><p class="small text-secondary mb-0">None.</p><?php endif; ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($excuses as $x): ?>
                            <li class="border-top py-2 small d-flex justify-content-between gap-2">
                                <span>
                                    <?= e(date('M j', strtotime($x['date_from']))) ?><?= $x['date_to'] !== $x['date_from'] ? '–' . e(date('M j', strtotime($x['date_to']))) : '' ?>: <?= e($x['reason']) ?>
                                    <?php if ($x['file_path']): ?> <a href="<?= e(url('file.php?kind=excuse&id=' . $x['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                                </span>
                                <span class="badge text-bg-<?= ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$x['status']] ?? 'secondary' ?> align-self-start"><?= e(ucfirst($x['status'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <?php if ($card):
        $yearFormAction = 'admin/profiles.php';
        $yearFormHidden = ['id' => $studentId];
        require APP_ROOT . '/templates/report-card.php';
    endif; ?>
    <?php
    require APP_ROOT . '/templates/footer.php';
    exit;
}

// ---- search
$q = trim(get_str('q'));
$sectionId = get_int('section');
$sections = $sy ? section_options((int) $sy['id']) : [];
$where = ['1 = 1'];
$params = [$sy['id'] ?? 0];
if ($q !== '') {
    $where[] = '(st.lrn = ? OR CONCAT(st.first_name, \' \', st.last_name) LIKE ? OR st.last_name LIKE ?)';
    array_push($params, $q, '%' . $q . '%', $q . '%');
}
if ($sectionId) {
    $where[] = 'sec.id = ?';
    $params[] = $sectionId;
}
$learners = ($q !== '' || $sectionId) ? db_all(
    'SELECT st.id, st.lrn, st.last_name, st.first_name, st.middle_name, st.status, sec.grade_level, sec.strand, sec.name AS section_name,
            (SELECT COUNT(*) FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id JOIN class_assignments ca ON ca.id = cs.class_assignment_id
              WHERE sa.student_id = st.id AND ca.section_id = sec.id AND sa.status = \'absent\') AS absences,
            (SELECT COUNT(*) FROM interventions i WHERE i.student_id = st.id) AS notes
       FROM students st
       LEFT JOIN enrollments e ON e.student_id = st.id AND e.school_year_id = ?
       LEFT JOIN sections sec  ON sec.id = e.section_id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY sec.grade_level, sec.name, st.last_name, st.first_name
      LIMIT 200',
    $params
) : [];

$title = 'Learner profiles';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Learner profiles</h1>
        <p class="sub">Look up a learner's details, family, attendance, grades and follow-up notes.</p>
    </div>
</div>
<form class="row g-2 mb-4" method="get" action="<?= e(url('admin/profiles.php')) ?>">
    <div class="col-md-5"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Name or LRN" aria-label="Name or LRN" autofocus></div>
    <div class="col-md-4">
        <select class="form-select" name="section" aria-label="Section">
            <option value="0">Any section</option>
            <?php foreach ($sections as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $sectionId ? 'selected' : '' ?>><?= e(section_label($s)) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3 d-grid"><button class="btn btn-primary"><i class="bi bi-search me-1"></i>Search</button></div>
</form>
<?php if ($q === '' && !$sectionId): ?>
    <p class="text-secondary">Type a name or LRN, or choose a section.</p>
<?php elseif (!$learners): ?>
    <p class="text-secondary">No learners found.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle shadow-sm">
            <thead class="table-light"><tr><th>Learner</th><th>LRN</th><th>Section</th><th class="text-center">Absences</th><th class="text-center">Notes</th></tr></thead>
            <tbody>
            <?php foreach ($learners as $l): ?>
                <tr>
                    <td><a href="<?= e(url('admin/profiles.php?id=' . $l['id'])) ?>" class="fw-semibold"><?= e(student_name($l)) ?></a><?= $l['status'] !== 'active' ? ' <span class="badge text-bg-secondary">' . e(str_replace('_', ' ', $l['status'])) . '</span>' : '' ?></td>
                    <td class="lrn"><?= e($l['lrn']) ?></td>
                    <td class="small"><?= $l['section_name'] ? e(section_label($l)) : '<span class="text-secondary">Not enrolled</span>' ?></td>
                    <td class="text-center <?= $l['absences'] >= (int) setting('at_risk_absences') ? 'text-danger fw-semibold' : '' ?>"><?= (int) $l['absences'] ?></td>
                    <td class="text-center"><?= (int) $l['notes'] ?: '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
