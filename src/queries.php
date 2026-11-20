<?php
declare(strict_types=1);

function active_school_year(): ?array
{
    return db_one('SELECT * FROM school_years WHERE is_active = 1 LIMIT 1');
}

/** One class (subject taught in a section) with its names, or null. */
function class_info(int $classId): ?array
{
    return db_one(
        'SELECT ca.id, ca.section_id, ca.subject_id, ca.teacher_id, ca.semester,
                sub.code AS subject_code, sub.name AS subject_name, sub.type AS subject_type,
                sec.grade_level, sec.strand, sec.name AS section_name, sec.school_year_id,
                sy.label AS sy_label, u.full_name AS teacher_name
           FROM class_assignments ca
           JOIN subjects sub    ON sub.id = ca.subject_id
           JOIN sections sec    ON sec.id = ca.section_id
           JOIN school_years sy ON sy.id = sec.school_year_id
           JOIN users u         ON u.id = ca.teacher_id
          WHERE ca.id = ?',
        [$classId]
    );
}

/** The two quarters a class is graded in, from its semester. */
function class_quarters(array $class): array
{
    return semester_quarters((int) $class['semester']);
}

/** Stops with 404 unless $quarter belongs to the class's semester. */
function class_quarter_or_abort(array $class, int $quarter): int
{
    $quarters = class_quarters($class);
    if (!in_array($quarter, $quarters, true)) {
        abort(404, 'This subject is in the ' . semester_label((int) $class['semester'])
            . ', so it is graded in Quarters ' . implode(' and ', $quarters) . ' only.');
    }
    return $quarter;
}

/** The class, if the signed-in teacher is the one assigned to it. Otherwise stops with 404/403. */
function teacher_class_or_abort(int $classId, array $staff): array
{
    $class = $classId > 0 ? class_info($classId) : null;
    if (!$class) {
        abort(404, 'That class does not exist.');
    }
    if ((int) $class['teacher_id'] !== $staff['id']) {
        abort(403, 'You are not the teacher assigned to this class.');
    }
    return $class;
}

/** The class, if the signed-in staff member teaches it or is an admin. Otherwise stops with 404/403. */
function managed_class_or_abort(int $classId, array $staff): array
{
    $class = $classId > 0 ? class_info($classId) : null;
    if (!$class) {
        abort(404, 'That class does not exist.');
    }
    if ((int) $class['teacher_id'] !== $staff['id'] && $staff['role'] !== 'admin') {
        abort(403, 'Only the class teacher or an admin can change this.');
    }
    return $class;
}

/** An attendance session of a class the signed-in teacher is assigned to. Otherwise stops with 404/403. */
function teacher_session_or_abort(int $sessionId, array $staff): array
{
    $session = $sessionId > 0 ? Attendance::session($sessionId) : null;
    if (!$session) {
        abort(404, 'That attendance session does not exist.');
    }
    if ((int) $session['class']['teacher_id'] !== $staff['id']) {
        abort(403, 'You are not the teacher assigned to this class.');
    }
    return $session;
}

/** Learners enrolled in a section, alphabetical. */
function section_roster(int $sectionId): array
{
    return db_all(
        'SELECT st.id, st.lrn, st.last_name, st.first_name, st.middle_name, st.sex
           FROM enrollments e
           JOIN students st ON st.id = e.student_id
          WHERE e.section_id = ?
          ORDER BY st.last_name, st.first_name, st.middle_name',
        [$sectionId]
    );
}

/** @return array<int, array<int, float>> student_id => [quarter => grade] */
function class_grades(int $classId): array
{
    $grades = [];
    foreach (db_all('SELECT student_id, quarter, grade FROM grades WHERE class_assignment_id = ?', [$classId]) as $row) {
        $grades[(int) $row['student_id']][(int) $row['quarter']] = (float) $row['grade'];
    }
    return $grades;
}

/** @return array<int, array> quarter => publication row, only for published quarters */
function class_publications(int $classId): array
{
    $published = [];
    foreach (db_all('SELECT * FROM grade_publications WHERE class_assignment_id = ?', [$classId]) as $row) {
        $published[(int) $row['quarter']] = $row;
    }
    return $published;
}

/**
 * Saves grade edits for one class in a single transaction, and records each change in grade_history.
 * Refuses changes to a locked quarter (past its deadline) unless it comes from an approved change request.
 * @param list<array{0:int, 1:int, 2:?float}> $changes [student_id, quarter, grade]; a null grade deletes it
 * @param string $source grid | upload | change_request | class_record
 */
function save_grade_changes(int $classId, array $changes, int $userId, string $source = 'grid'): void
{
    $schoolYearId = (int) db_value(
        'SELECT s.school_year_id FROM class_assignments ca JOIN sections s ON s.id = ca.section_id WHERE ca.id = ?',
        [$classId]
    );
    if ($source !== 'change_request') {
        foreach ($changes as [, $quarter]) {
            if (Grading::isLocked($schoolYearId, (int) $quarter)) {
                throw new UserFacingException("Quarter {$quarter} grades are locked. Send a grade change request instead.");
            }
        }
    }

    $pdo = db();
    $current = $pdo->prepare('SELECT grade FROM grades WHERE student_id = ? AND class_assignment_id = ? AND quarter = ?');
    $upsert = $pdo->prepare(
        'INSERT INTO grades (student_id, class_assignment_id, quarter, grade, updated_by) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE grade = VALUES(grade), updated_by = VALUES(updated_by)'
    );
    $delete = $pdo->prepare('DELETE FROM grades WHERE student_id = ? AND class_assignment_id = ? AND quarter = ?');
    $history = $pdo->prepare(
        'INSERT INTO grade_history (student_id, class_assignment_id, quarter, old_grade, new_grade, source, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($changes as [$studentId, $quarter, $grade]) {
            $current->execute([$studentId, $classId, $quarter]);
            $old = $current->fetchColumn();
            $old = $old === false ? null : (string) $old;
            $new = $grade === null ? null : number_format($grade, 2, '.', '');
            if ($old === $new) {
                continue;
            }
            if ($new === null) {
                $delete->execute([$studentId, $classId, $quarter]);
            } else {
                $upsert->execute([$studentId, $classId, $quarter, $new, $userId]);
            }
            $history->execute([$studentId, $classId, $quarter, $old, $new, $source, $userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Checks a learner's LRN + parent link code, as typed by a parent. Locks after repeated failures.
 * @return array{0: ?array, 1: ?string} [learner, error message]
 */
function check_parent_link_code(string $lrnTyped, string $codeTyped): array
{
    $lrn = normalize_lrn($lrnTyped);
    $code = normalize_parent_code($codeTyped);
    $ip = client_ip();
    if (!is_valid_lrn($lrn)) {
        return [null, "Enter the learner's 12-digit LRN."];
    }
    if (strlen($code) !== 8) {
        return [null, 'Enter the 8-character link code from the slip the school gave you.'];
    }
    if (RateLimiter::isLocked('link', $lrn, $ip)) {
        return [null, 'Too many unsuccessful tries. Please wait ' . config('login_window_min') . ' minutes and try again.'];
    }
    $student = db_one('SELECT * FROM students WHERE lrn = ?', [$lrn]);
    if (!$student || !hash_equals($student['parent_code'], $code)) {
        RateLimiter::recordFailure('link', $lrn, $ip);
        return [null, "The LRN and link code don't match. Check the slip from the school, or ask the adviser for a new code."];
    }
    RateLimiter::clear('link', $lrn);
    return [$student, null];
}

/** Staff who see every learner (not just their own classes or advisory). */
function sees_all_learners(array $staff): bool
{
    return in_array($staff['role'], ['admin', 'principal', 'guidance'], true);
}

/** Section ids the staff member advises in the active school year. */
function advised_section_ids(array $staff): array
{
    return array_map('intval', array_column(db_all(
        'SELECT s.id FROM sections s JOIN school_years sy ON sy.id = s.school_year_id AND sy.is_active = 1 WHERE s.adviser_id = ?',
        [$staff['id']]
    ), 'id'));
}

/** True if the staff member may see this learner's private records (adviser, subject teacher, admin, principal, guidance). */
function staff_can_see_learner(array $staff, int $studentId): bool
{
    if (sees_all_learners($staff)) {
        return true;
    }
    return (bool) db_value(
        'SELECT 1 FROM enrollments e JOIN sections s ON s.id = e.section_id
           LEFT JOIN class_assignments ca ON ca.section_id = s.id AND ca.teacher_id = ?
          WHERE e.student_id = ? AND (s.adviser_id = ? OR ca.id IS NOT NULL) LIMIT 1',
        [$staff['id'], $studentId, $staff['id']]
    );
}

/** "Today 9:05 AM", "Yesterday 3:10 PM", "Nov 12, 9:05 AM" */
function when_label(string $datetime): string
{
    $time = strtotime($datetime);
    return match (date('Y-m-d', $time)) {
        date('Y-m-d') => 'Today ' . date('g:i A', $time),
        date('Y-m-d', strtotime('-1 day')) => 'Yesterday ' . date('g:i A', $time),
        default => date('M j, g:i A', $time),
    };
}

/** Active staff accounts, for "adviser" and "teacher" dropdowns. */
function staff_options(): array
{
    return db_all('SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name');
}

/** Sections in a school year, for dropdowns. */
function section_options(int $schoolYearId): array
{
    return db_all(
        'SELECT id, grade_level, strand, name AS section_name FROM sections WHERE school_year_id = ? ORDER BY grade_level, strand, name',
        [$schoolYearId]
    );
}

/**
 * Puts a learner in a section for that section's school year. A learner already in
 * another section that year is moved.
 */
function enroll_student(int $studentId, int $sectionId): void
{
    db_exec(
        'INSERT INTO enrollments (student_id, section_id, school_year_id)
         SELECT ?, id, school_year_id FROM sections WHERE id = ?
         ON DUPLICATE KEY UPDATE section_id = VALUES(section_id)',
        [$studentId, $sectionId]
    );
}

/**
 * Reads and checks the learner form (templates/student-fields.php).
 * @return array{0: array, 1: list<string>} [clean values, error messages]
 */
function validate_student_form(int $exceptId = 0): array
{
    $data = [
        'lrn'         => normalize_lrn(post('lrn')),
        'last_name'   => post('last_name'),
        'first_name'  => post('first_name'),
        'middle_name' => post('middle_name'),
        'sex'         => post('sex'),
        'birthdate'   => post('birthdate'),
    ];
    $errors = [];
    if (!is_valid_lrn($data['lrn'])) {
        $errors[] = 'The LRN must be exactly 12 digits.';
    } elseif (db_value('SELECT id FROM students WHERE lrn = ? AND id <> ?', [$data['lrn'], $exceptId])) {
        $errors[] = 'Another learner already has this LRN.';
    }
    if ($data['last_name'] === '' || $data['first_name'] === '') {
        $errors[] = 'Last name and first name are required.';
    }
    if (max(mb_strlen($data['last_name']), mb_strlen($data['first_name']), mb_strlen($data['middle_name'])) > 80) {
        $errors[] = 'Names must be 80 characters or fewer.';
    }
    if (!in_array($data['sex'], ['', 'M', 'F'], true)) {
        $errors[] = 'Choose M or F for sex.';
    }
    if (!valid_ymd($data['birthdate']) || !plausible_birthdate($data['birthdate'])) {
        $errors[] = 'Enter a valid birthdate.';
    }
    return [$data, $errors];
}
