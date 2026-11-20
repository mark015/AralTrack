<?php
// One month of a class's attendance as an Excel sheet (learners × class meetings).
require dirname(__DIR__, 2) . '/src/bootstrap.php';

$staff = require_staff();
$class = teacher_class_or_abort(get_int('id'), $staff);
$month = get_str('month');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
    abort(404, 'Choose a month.');
}
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));

$sessions = db_all(
    'SELECT * FROM class_sessions WHERE class_assignment_id = ? AND session_date BETWEEN ? AND ? ORDER BY starts_at',
    [$class['id'], $from, $to]
);
$marks = [];
$rows = db_all(
    'SELECT sa.session_id, sa.student_id, sa.status FROM session_attendance sa
       JOIN class_sessions cs ON cs.id = sa.session_id
      WHERE cs.class_assignment_id = ? AND cs.session_date BETWEEN ? AND ?',
    [$class['id'], $from, $to]
);
foreach ($rows as $row) {
    $marks[(int) $row['session_id']][(int) $row['student_id']] = $row['status'];
}

$book = Templates::attendanceSheet($class, $month, $sessions, section_roster((int) $class['section_id']), $marks);
Templates::download($book, sprintf('attendance_%s_G%d-%s_%s.xlsx', $class['subject_code'], $class['grade_level'], $class['section_name'], $month));
