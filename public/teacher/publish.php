<?php
// Publish or unpublish one quarter of one class. Learners only see published quarters.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (!is_post()) {
    redirect('teacher/index.php');
}
$staff = require_staff();
$class = teacher_class_or_abort(post_int('id'), $staff);
if (!Access::gradeEncodingOpen()) {
    flash('warning', 'Grade encoding is turned off by the school right now, so grades are read-only.');
    redirect('teacher/class.php?id=' . $class['id']);
}
$quarter = class_quarter_or_abort($class, post_int('q'));

if (post('action') === 'publish') {
    $newlyPublished = db_exec(
        'INSERT IGNORE INTO grade_publications (class_assignment_id, quarter, published_by) VALUES (?, ?, ?)',
        [$class['id'], $quarter, $staff['id']]
    ) === 1;
    if ($newlyPublished) {
        Notifications::gradesReleased($class, $quarter);
    }
    flash('success', "Quarter {$quarter} grades are now visible to learners and their parents.");
} else {
    db_exec('DELETE FROM grade_publications WHERE class_assignment_id = ? AND quarter = ?', [$class['id'], $quarter]);
    flash('info', "Quarter {$quarter} grades are hidden from learners again.");
}
redirect('teacher/class.php?id=' . $class['id']);
