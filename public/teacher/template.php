<?php
// Download an .xlsx grade sheet for one class and quarter, pre-filled with the class list.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$class = teacher_class_or_abort(get_int('id'), $staff);
if (!Access::can('encoding')) {
    flash('warning', 'Grade encoding is turned off by the school right now, so grades are read-only.');
    redirect('teacher/class.php?id=' . $class['id']);
}
$quarter = class_quarter_or_abort($class, get_int('q'));

$roster = section_roster((int) $class['section_id']);
if (!$roster) {
    flash('warning', 'No learners are enrolled in this section yet, so there is nothing to put in a template.');
    redirect('teacher/class.php?id=' . $class['id']);
}

$grades = class_grades((int) $class['id']);
$existing = [];
foreach ($roster as $student) {
    $existing[(int) $student['id']] = $grades[(int) $student['id']][$quarter] ?? null;
}

$book = Templates::gradeSheet($class, $quarter, $roster, $existing);
$filename = sprintf('grades_G%d-%s_%s_Q%d.xlsx', $class['grade_level'], $class['section_name'], $class['subject_code'], $quarter);
Templates::download($book, $filename);
