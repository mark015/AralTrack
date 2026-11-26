<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff('admin');
$sy = active_school_year();
$syId = $sy['id'] ?? 0;
$today = date('Y-m-d');

$counts = [
    'subjects' => (int) db_value('SELECT COUNT(*) FROM subjects WHERE workspace_id = ?', [ws()]),
    'teachers' => (int) db_value('SELECT COUNT(*) FROM users WHERE is_active = 1 AND workspace_id = ?', [ws()]),
    'sections' => (int) db_value('SELECT COUNT(*) FROM sections WHERE school_year_id = ?', [$syId]),
    'classes'  => (int) db_value('SELECT COUNT(*) FROM class_assignments ca JOIN sections s ON s.id = ca.section_id WHERE s.school_year_id = ?', [$syId]),
    'learners' => (int) db_value('SELECT COUNT(*) FROM students WHERE workspace_id = ?', [ws()]),
    'enrolled' => (int) db_value('SELECT COUNT(*) FROM enrollments WHERE school_year_id = ?', [$syId]),
    'parents'  => (int) db_value('SELECT COUNT(*) FROM parents WHERE is_active = 1 AND workspace_id = ?', [ws()]),
];

$setup = [
    ['An active school year', $sy !== null, 'admin/school-years.php'],
    ['Subjects', $counts['subjects'] > 0, 'admin/subjects.php'],
    ['Teacher accounts', $counts['teachers'] > 1, 'admin/users.php'],
    ['Sections for this school year', $counts['sections'] > 0, 'admin/sections.php'],
    ['Subjects and teachers assigned to sections', $counts['classes'] > 0, 'admin/sections.php'],
    ['Learners enrolled in sections', $counts['enrolled'] > 0, 'admin/students.php'],
];
$setupCount = count(array_filter(array_column($setup, 1)));
$setupDone = $setupCount === count($setup);

// Attendance rate per school day (present + late out of all marks), last 10 days with classes
$trend = array_slice(db_all(
    "SELECT cs.session_date AS day, SUM(sa.status IN ('present', 'late')) AS attended, COUNT(*) AS marks
       FROM session_attendance sa
       JOIN class_sessions cs    ON cs.id = sa.session_id
       JOIN class_assignments ca ON ca.id = cs.class_assignment_id
       JOIN sections s           ON s.id = ca.section_id
      WHERE s.school_year_id = ? AND cs.session_date >= ?
      GROUP BY cs.session_date
      ORDER BY cs.session_date",
    [$syId, date('Y-m-d', strtotime('-30 days'))]
), -10);

$todayMarks = array_fill_keys(array_keys(ATTENDANCE_STATUSES), 0);
foreach (db_all(
    'SELECT sa.status, COUNT(*) AS n FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
      WHERE cs.session_date = ? AND cs.workspace_id = ? GROUP BY sa.status',
    [$today, ws()]
) as $row) {
    $todayMarks[$row['status']] = (int) $row['n'];
}
$todaySessions = (int) db_value('SELECT COUNT(*) FROM class_sessions WHERE session_date = ? AND workspace_id = ?', [$today, ws()]);
$openSessions = (int) db_value('SELECT COUNT(*) FROM class_sessions WHERE session_date = ? AND finished_at IS NULL AND workspace_id = ?', [$today, ws()]);

$uploads = db_all(
    'SELECT ul.*, u.full_name, sub.name AS subject_name, sec.grade_level, sec.strand, sec.name AS section_name
       FROM upload_log ul
       JOIN users u              ON u.id = ul.user_id
       JOIN class_assignments ca ON ca.id = ul.class_assignment_id
       JOIN subjects sub         ON sub.id = ca.subject_id
       JOIN sections sec         ON sec.id = ca.section_id
      WHERE ca.workspace_id = ?
      ORDER BY ul.id DESC
      LIMIT 8',
    [ws()]
);

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = $staff['full_name'];

$title = 'Dashboard';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3"><?= e($greeting) ?>, <?= e($firstName) ?></h1>
        <p class="sub"><?= e(date('l, F j, Y')) ?><?= $sy ? ' · S.Y. ' . e($sy['label']) : ' · No active school year' ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('admin/students.php')) ?>"><i class="bi bi-upload me-1"></i>Import learners</a>
        <a class="btn btn-primary" href="<?= e(url('admin/sections.php')) ?>"><i class="bi bi-qr-code me-1"></i>Print QR cards</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Learners enrolled', $counts['enrolled'], 'people-fill', '', 'admin/students.php'],
        ['Sections', $counts['sections'], 'diagram-3-fill', 'violet', 'admin/sections.php'],
        ['Classes (subject × section)', $counts['classes'], 'journal-bookmark-fill', 'teal', 'admin/sections.php'],
        ['Parent accounts', $counts['parents'], 'person-hearts', 'gold', 'admin/parents.php'],
    ] as [$label, $value, $icon, $tone, $link]): ?>
        <div class="col-6 col-xl-3">
            <a class="card lift h-100 text-decoration-none text-body" href="<?= e(url($link)) ?>">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="icon-bubble <?= e($tone) ?>"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></div>
                    <div>
                        <div class="stat"><?= (int) $value ?></div>
                        <div class="stat-label"><?= e($label) ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h2 class="h6 mb-0">Class attendance</h2>
                        <div class="small text-secondary">Share of learners present or late, per school day</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 small">
                        <span class="badge text-bg-primary"><i class="bi bi-easel2"></i><?= $todaySessions ?> classes today<?= $openSessions ? " · {$openSessions} in progress" : '' ?></span>
                        <?php foreach (ATTENDANCE_STATUSES as $status => $label): ?>
                            <?= str_replace('</span>', ' ' . $todayMarks[$status] . '</span>', attendance_badge($status)) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!$trend): ?>
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-bar-chart fs-2 d-block mb-2"></i>
                        No class attendance recorded yet. Teachers take it from <strong>My classes → Attendance</strong>.
                    </div>
                <?php else: ?>
                    <div class="bars" role="img" aria-label="Attendance rate for the last <?= count($trend) ?> school days">
                        <?php foreach ($trend as $day):
                            $rate = $day['marks'] ? (int) round($day['attended'] / $day['marks'] * 100) : 0; ?>
                            <div class="bar<?= $rate < 90 ? ' low' : '' ?>" title="<?= e(date('D, M j', strtotime($day['day']))) ?>: <?= $rate ?>% (<?= (int) $day['attended'] ?> of <?= (int) $day['marks'] ?>)">
                                <span class="pct"><?= $rate ?>%</span>
                                <span class="fill" style="--h: <?= $rate ?>"></span>
                                <small><?= e(date('D', strtotime($day['day']))) ?><br><?= e(date('M j', strtotime($day['day']))) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <?php if (!$setupDone): ?>
                    <h2 class="h6">Setup checklist</h2>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <div class="progress progress-thin flex-grow-1"><div class="progress-bar" style="width: <?= round($setupCount / count($setup) * 100) ?>%"></div></div>
                        <span class="small text-secondary"><?= $setupCount ?>/<?= count($setup) ?></span>
                    </div>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($setup as [$label, $done, $link]): ?>
                            <li class="d-flex align-items-center gap-2 py-1">
                                <i class="bi <?= $done ? 'bi-check-circle-fill text-success' : 'bi-circle text-secondary' ?>"></i>
                                <a class="<?= $done ? 'text-secondary' : '' ?>" href="<?= e(url($link)) ?>"><?= e($label) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <h2 class="h6 mb-3">Quick actions</h2>
                    <div class="d-grid gap-2">
                        <?php foreach ([
                            ['Enroll or import learners', 'person-plus', 'admin/students.php'],
                            ['Assign subjects and teachers', 'diagram-3', 'admin/sections.php'],
                            ['Print QR cards and parent slips', 'printer', 'admin/sections.php'],
                            ['Help a parent with their account', 'person-hearts', 'admin/parents.php'],
                            ['Add a staff account', 'person-badge', 'admin/users.php'],
                        ] as [$label, $icon, $link]): ?>
                            <a class="btn btn-outline-primary text-start" href="<?= e(url($link)) ?>"><i class="bi bi-<?= e($icon) ?> me-2"></i><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<h2 class="section-title">Recent grade uploads</h2>
<?php if (!$uploads): ?>
    <div class="card"><div class="card-body text-secondary"><i class="bi bi-cloud-upload me-2"></i>No uploads yet. Teachers upload grades from <strong>My classes → Grades</strong>.</div></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle shadow-sm">
            <thead><tr><th scope="col">When</th><th scope="col">Teacher</th><th scope="col">Class</th><th scope="col">Quarter</th><th scope="col">File</th><th scope="col" class="text-end">Saved</th><th scope="col" class="text-end">Rejected</th></tr></thead>
            <tbody>
            <?php foreach ($uploads as $u): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(when_label($u['created_at'])) ?></td>
                    <td><span class="d-inline-flex align-items-center gap-2"><?= avatar($u['full_name']) ?><?= e($u['full_name']) ?></span></td>
                    <td><?= e($u['subject_name']) ?> <span class="text-secondary small">· <?= e(section_label($u)) ?></span></td>
                    <td><span class="badge text-bg-primary">Q<?= (int) $u['quarter'] ?></span></td>
                    <td class="small text-break"><?= e($u['original_filename']) ?></td>
                    <td class="text-end"><?= (int) $u['rows_saved'] ?></td>
                    <td class="text-end <?= $u['rows_rejected'] ? 'text-danger fw-semibold' : '' ?>"><?= (int) $u['rows_rejected'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
