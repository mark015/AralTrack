<?php
declare(strict_types=1);

// A valid bcrypt hash of a throwaway string. Checking a password against it when the
// username doesn't exist makes that reply take as long as a real check.
const DUMMY_PASSWORD_HASH = '$2y$10$nI5kqkexLn0WS4BD5tpxwerelSAcsp26lAtCAKdUhoT89bKmAdjLe';

// ---------------------------------------------------------------- staff (admin and teachers)

function current_staff(): ?array
{
    return $_SESSION['staff'] ?? null;
}

function staff_session(array $user): array
{
    return [
        'id'        => (int) $user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
    ];
}

const STAFF_ROLES = [
    'admin'     => 'Admin · Registrar',
    'teacher'   => 'Teacher',
    'principal' => 'Principal',
    'guidance'  => 'Guidance counselor',
];

function home_for(array $staff): string
{
    return match ($staff['role']) {
        'admin' => 'admin/index.php',
        'principal' => is_file(APP_ROOT . '/public/admin/reports.php') ? 'admin/reports.php' : 'teacher/index.php',
        'guidance' => 'teacher/at-risk.php',
        default => 'teacher/index.php',
    };
}

/**
 * Stops unless a staff member is signed in (and has one of $roles, when given).
 * Re-reads the account each time so a deactivated account is locked out at once.
 */
function require_staff(string ...$roles): array
{
    $id = $_SESSION['staff']['id'] ?? null;
    $user = $id ? db_one('SELECT * FROM users WHERE id = ?', [$id]) : null;
    if (!$user || !$user['is_active']) {
        unset($_SESSION['staff']);
        flash('warning', 'Please sign in to continue.');
        redirect('staff/login.php');
    }
    $_SESSION['staff'] = staff_session($user);

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if ($user['must_change_password'] && !str_ends_with($script, '/staff/password.php')) {
        flash('warning', 'Please choose a new password before continuing.');
        redirect('staff/password.php');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        abort(403, 'Your account cannot open this page.');
    }
    return $_SESSION['staff'];
}

function login_staff(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['staff'] = staff_session($user);
}

function logout_staff(): void
{
    unset($_SESSION['staff'], $_SESSION['grade_import']);
    session_regenerate_id(true);
}

// ---------------------------------------------------------------- parents and guardians

function current_parent(): ?array
{
    return $_SESSION['parent'] ?? null;
}

/** Stops unless a parent is signed in. Re-reads the account so a deactivated one is locked out at once. */
function require_parent(): array
{
    $id = $_SESSION['parent']['id'] ?? null;
    $parent = $id ? db_one('SELECT * FROM parents WHERE id = ?', [$id]) : null;
    if (!$parent || !$parent['is_active']) {
        unset($_SESSION['parent']);
        flash('warning', 'Please sign in to continue.');
        redirect('parent/login.php');
    }
    $_SESSION['parent'] = ['id' => (int) $parent['id'], 'full_name' => $parent['full_name']];

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if ($parent['must_change_password'] && !str_ends_with($script, '/parent/password.php')) {
        flash('warning', 'Please choose a new password before continuing.');
        redirect('parent/password.php');
    }
    return $_SESSION['parent'];
}

function login_parent(array $parent): void
{
    session_regenerate_id(true);
    $_SESSION['parent'] = ['id' => (int) $parent['id'], 'full_name' => $parent['full_name']];
}

function logout_parent(): void
{
    unset($_SESSION['parent']);
    session_regenerate_id(true);
}

/** A learner linked to this parent's account, with the relationship. Otherwise stops with 403. */
function parent_child_or_abort(array $parent, int $studentId): array
{
    $child = $studentId > 0 ? db_one(
        'SELECT st.*, ps.relationship FROM parent_students ps JOIN students st ON st.id = ps.student_id
          WHERE ps.parent_id = ? AND ps.student_id = ?',
        [$parent['id'], $studentId]
    ) : null;
    if (!$child) {
        abort(403, 'This learner is not linked to your account.');
    }
    return $child;
}

// ---------------------------------------------------------------- learners

/** The signed-in learner's record, or back to the sign-in page. */
function require_student(): array
{
    $id = current_student_id();
    $student = $id !== null ? db_one('SELECT * FROM students WHERE id = ?', [$id]) : null;
    if (!$student) {
        logout_student();
        flash('warning', 'Please sign in to continue.');
        redirect('index.php');
    }
    return $student;
}

/** The learner's section in the active school year (with the school year label), or null. */
function current_section_of(int $studentId): ?array
{
    return db_one(
        'SELECT sec.id, sec.grade_level, sec.strand, sec.name AS section_name, sec.adviser_id, sy.id AS school_year_id, sy.label AS sy_label, u.full_name AS adviser
           FROM enrollments e
           JOIN sections sec    ON sec.id = e.section_id
           JOIN school_years sy ON sy.id = e.school_year_id AND sy.is_active = 1
           LEFT JOIN users u    ON u.id = sec.adviser_id
          WHERE e.student_id = ?',
        [$studentId]
    );
}

function current_student_id(): ?int
{
    return isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : null;
}

function login_student(int $studentId): void
{
    session_regenerate_id(true);
    $_SESSION['student_id'] = $studentId;
}

function logout_student(): void
{
    unset($_SESSION['student_id']);
    session_regenerate_id(true);
}
