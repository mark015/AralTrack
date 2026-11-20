<?php
// Parent home: each linked child's classes today, this month's attendance, and new notifications.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$parent = require_parent();
$sy = active_school_year();
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$children = db_all(
    'SELECT st.*, ps.relationship FROM parent_students ps JOIN students st ON st.id = ps.student_id
      WHERE ps.parent_id = ? ORDER BY st.first_name, st.last_name',
    [$parent['id']]
);
foreach ($children as &$child) {
    $child['section'] = $sy ? db_one(
        'SELECT sec.id, sec.grade_level, sec.strand, sec.name AS section_name FROM enrollments e
           JOIN sections sec ON sec.id = e.section_id WHERE e.student_id = ? AND e.school_year_id = ?',
        [$child['id'], $sy['id']]
    ) : null;
    $child['today'] = [];
    $child['month'] = array_fill_keys(array_keys(ATTENDANCE_STATUSES), 0);
    if ($child['section']) {
        $child['today'] = array_reverse(Attendance::studentSessions((int) $child['id'], (int) $child['section']['id'], $today, $today));
        $rows = db_all(
            'SELECT sa.status, COUNT(*) AS n FROM session_attendance sa
               JOIN class_sessions cs    ON cs.id = sa.session_id
               JOIN class_assignments ca ON ca.id = cs.class_assignment_id
              WHERE sa.student_id = ? AND ca.section_id = ? AND cs.session_date >= ? AND cs.finished_at IS NOT NULL
              GROUP BY sa.status',
            [$child['id'], $child['section']['id'], $monthStart]
        );
        foreach ($rows as $row) {
            $child['month'][$row['status']] = (int) $row['n'];
        }
    }
}
unset($child);

$unread = db_all(
    'SELECT n.*, st.first_name FROM parent_notifications n LEFT JOIN students st ON st.id = n.student_id
      WHERE n.parent_id = ? AND n.read_at IS NULL ORDER BY n.id DESC LIMIT 5',
    [$parent['id']]
);
$unreadTotal = Notifications::unreadCount($parent['id']);

$title = 'My children';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h3">Hello, <?= e(explode(' ', $parent['full_name'])[0]) ?></h1>
        <p class="sub"><?= e(date('l, F j')) ?> · Here's how your <?= count($children) === 1 ? 'child is' : 'children are' ?> doing</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= e(url('parent/link.php')) ?>"><i class="bi bi-person-plus me-1"></i>Add a child</a>
</div>

<?php if ($unread): ?>
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0"><i class="bi bi-bell-fill text-warning me-2"></i>New notifications</h2>
                <a class="small fw-semibold" href="<?= e(url('parent/notifications.php')) ?>">See all<?= $unreadTotal > count($unread) ? " ({$unreadTotal} new)" : '' ?> <i class="bi bi-arrow-right"></i></a>
            </div>
            <ul class="list-unstyled mb-0">
                <?php foreach ($unread as $n): ?>
                    <li class="py-2 border-bottom d-flex flex-wrap align-items-center gap-1">
                        <?php require APP_ROOT . '/templates/notification-badge.php'; ?>
                        <a class="text-body text-decoration-none" href="<?= e(url($n['link'])) ?>"><?= e($n['message']) ?></a>
                        <span class="small text-secondary">· <?= e(when_label($n['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php $newsViewer = 'families'; require APP_ROOT . '/templates/news-widget.php'; ?>

<?php if (!$children): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <div class="icon-bubble gold mx-auto mb-3"><i class="bi bi-person-plus-fill"></i></div>
            <h2 class="h5">Link your child to get started</h2>
            <p class="text-secondary">You need your child's LRN and the link code on the slip from the school.</p>
            <a class="btn btn-primary" href="<?= e(url('parent/link.php')) ?>">Add a child</a>
        </div>
    </div>
<?php endif; ?>

<?php $attendanceOn = Access::on('attendance'); $gradesOn = Access::parentsSeeGrades(); ?>
<div class="row g-4">
    <?php foreach ($children as $child):
        $marked = array_sum($child['month']);
        $rate = $marked ? (int) round(($child['month']['present'] + $child['month']['late']) / $marked * 100) : null;
        $ringColor = $rate === null ? '#c3cad9' : ($rate >= 90 ? '#14946b' : ($rate >= 80 ? '#e0a000' : '#d9364a')); ?>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <?= avatar($child['first_name'] . ' ' . $child['last_name'], 'avatar-lg') ?>
                        <div class="flex-grow-1 min-w-0">
                            <h2 class="h5 mb-0"><?= e(student_name($child)) ?></h2>
                            <div class="small text-secondary">
                                <?= $child['section'] ? e(section_label($child['section'])) : 'Not enrolled this school year' ?>
                                · <?= e(RELATIONSHIPS[$child['relationship']]) ?>
                            </div>
                        </div>
                        <?php if ($attendanceOn): ?>
                        <div class="ring" style="--pct: <?= (int) $rate ?>; --ring: <?= $ringColor ?>" title="Attended <?= $rate ?? 0 ?>% of class meetings in <?= e(date('F')) ?>">
                            <span><?= $rate === null ? '–' : $rate . '%' ?><small><?= e(date('M')) ?></small></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($attendanceOn): ?>
                    <div class="section-title mb-2">Today's classes</div>
                    <?php if (!$child['today']): ?>
                        <p class="small text-secondary"><i class="bi bi-calendar-x me-1"></i>No class attendance taken yet today.</p>
                    <?php else: ?>
                        <ul class="timeline mb-4">
                            <?php foreach ($child['today'] as $s): ?>
                                <li class="<?= e($s['status'] ?? 'pending') ?>">
                                    <span><span class="text-secondary"><?= e(format_time($s['starts_at'])) ?></span> · <?= e($s['subject_name']) ?></span>
                                    <span class="text-nowrap">
                                        <?php if ($s['status'] === null): ?>
                                            <span class="badge text-bg-primary"><i class="bi bi-hourglass-split"></i>In class now</span>
                                        <?php else: ?>
                                            <?php if ($s['source'] === 'scan'): ?><span class="small text-secondary me-1"><?= e(format_time($s['marked_at'])) ?></span><?php endif; ?>
                                            <?= attendance_badge($s['status']) ?>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="section-title mb-2"><?= e(date('F')) ?> so far</div>
                    <div class="row g-2 mb-4 text-center">
                        <?php foreach (ATTENDANCE_STATUSES as $status => $label): ?>
                            <div class="col-3">
                                <div class="summary-tile py-2 px-1">
                                    <div class="fs-5 fw-bold"><?= $child['month'][$status] ?></div>
                                    <div class="small"><?= attendance_badge($status) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <?php if ($attendanceOn): ?><a class="btn btn-primary" href="<?= e(url('parent/child.php?id=' . $child['id'])) ?>"><i class="bi bi-calendar-check me-1"></i>Attendance</a><?php endif; ?>
                        <?php if ($gradesOn): ?><a class="btn btn-outline-primary" href="<?= e(url('parent/child.php?tab=grades&id=' . $child['id'])) ?>"><i class="bi bi-journal-text me-1"></i>Grades</a><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
