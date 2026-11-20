<?php
// Sends an uploaded file after checking that the signed-in person may see it.
//   ?kind=excuse&id=…    excuse letter attachment: the parent who sent it, linked parents, staff who see the learner
//   ?kind=material&id=…  class material: the class teacher, learners in the section and their parents, admins
//   ?kind=photo&id=…     learner photo (id = learner): staff, the learner, linked parents
require dirname(__DIR__) . '/src/bootstrap.php';

$kind = get_str('kind');
$id = get_int('id');
$staff = current_staff() ? require_staff() : null;
$parent = !$staff && current_parent() ? require_parent() : null;
$learnerId = current_student_id();

/** True when the signed-in person may see this learner's files. */
$canSeeLearner = static function (int $studentId) use ($staff, $parent, $learnerId): bool {
    if ($staff) {
        return staff_can_see_learner($staff, $studentId);
    }
    if ($parent) {
        return (bool) db_value('SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ?', [$parent['id'], $studentId]);
    }
    return $learnerId === $studentId;
};

switch ($kind) {
    case 'excuse':
        $row = db_one('SELECT * FROM excuse_letters WHERE id = ?', [$id]);
        if ($row && $row['file_path'] && $canSeeLearner((int) $row['student_id'])) {
            Files::send($row['file_path'], $row['file_name'] ?? 'attachment');
        }
        break;

    case 'material':
        if (!Access::can('materials')) {
            break;
        }
        $row = db_one(
            'SELECT cp.*, ca.section_id, ca.teacher_id FROM class_posts cp JOIN class_assignments ca ON ca.id = cp.class_assignment_id WHERE cp.id = ?',
            [$id]
        );
        if ($row && $row['file_path']) {
            $allowed = match (true) {
                $staff !== null => (int) $row['teacher_id'] === $staff['id'] || sees_all_learners($staff),
                $parent !== null => (bool) db_value(
                    'SELECT 1 FROM enrollments e JOIN parent_students ps ON ps.student_id = e.student_id AND ps.parent_id = ? WHERE e.section_id = ? LIMIT 1',
                    [$parent['id'], $row['section_id']]
                ),
                $learnerId !== null => (bool) db_value('SELECT 1 FROM enrollments WHERE section_id = ? AND student_id = ?', [$row['section_id'], $learnerId]),
                default => false,
            };
            if ($allowed) {
                Files::send($row['file_path'], $row['file_name'] ?? 'material');
            }
        }
        break;

    case 'photo':
        $row = db_one('SELECT id, photo_path, lrn FROM students WHERE id = ?', [$id]);
        if ($row && $row['photo_path'] && ($staff !== null || $canSeeLearner((int) $row['id']))) {
            Files::send($row['photo_path'], 'learner-' . $row['lrn'] . '.' . pathinfo($row['photo_path'], PATHINFO_EXTENSION));
        }
        break;
}
abort(404, 'That file does not exist, or you do not have access to it.');
