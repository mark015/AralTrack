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
        'workspace_id' => (int) $user['workspace_id'],
    ];
}

const STAFF_ROLES = [
    'superadmin' => 'Super Admin',
    'admin'     => 'Admin · Registrar',
    'teacher'   => 'Teacher',
    'principal' => 'Principal',
    'guidance'  => 'Guidance counselor',
];

/** Admin (registrar) or Super Admin: both run the school's records. */
function is_admin(array $staff): bool
{
    return in_array($staff['role'], ['admin', 'superadmin'], true);
}

function home_for(array $staff): string
{
    if (Workspace::known() && Workspace::isSolo()) {
        // A teacher on their own: the setup guide until they finish it, then their classes
        return TeacherSetup::done() ? 'teacher/index.php' : 'teacher/setup.php';
    }
    return match ($staff['role']) {
        'superadmin' => is_file(APP_ROOT . '/public/superadmin/index.php') ? 'superadmin/index.php' : 'admin/index.php',
        'admin' => 'admin/index.php',
        'principal' => is_file(APP_ROOT . '/public/admin/reports.php') ? 'admin/reports.php' : 'teacher/index.php',
        'guidance' => 'teacher/at-risk.php',
        default => 'teacher/index.php',
    };
}

/**
 * Stops unless a staff member is signed in (and has one of $roles, when given; the Super Admin may open
 * every staff page). Re-reads the account each time, so a deactivated account, "sign out now" or staff
 * sign-in being turned off takes effect on the next click.
 */
function require_staff(string ...$roles): array
{
    $id = $_SESSION['staff']['id'] ?? null;
    $user = $id ? db_one('SELECT * FROM users WHERE id = ? AND workspace_id = ?', [$id, (int) ($_SESSION['workspace_id'] ?? 0)]) : null;
    if ($user) {
        Workspace::switchTo((int) $user['workspace_id']);
    }
    $message = match (true) {
        !$user => 'Please sign in to continue.',
        Workspace::isSuspended() && empty($_SESSION['acting_owner']) => Workspace::suspendedMessage(),
        !$user['is_active'] => 'Your account was turned off by the school.',
        Access::sessionRevoked('staff', $user) => 'You were signed out by the school. Please sign in again.',
        $user['role'] !== 'superadmin' && !Access::loginOpen('staff') => Access::closedMessage('staff'),
        default => null,
    };
    if ($message !== null) {
        unset($_SESSION['staff'], $_SESSION['grade_import']);
        flash('warning', $message);
        redirect('staff/login.php');
    }
    $_SESSION['staff'] = staff_session($user);

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if ($user['must_change_password'] && !str_ends_with($script, '/staff/password.php')) {
        flash('warning', 'Please choose a new password before continuing.');
        redirect('staff/password.php');
    }
    if ($roles && $user['role'] !== 'superadmin' && !in_array($user['role'], $roles, true)) {
        abort(403, 'Your account cannot open this page.');
    }
    return $_SESSION['staff'];
}

function login_staff(array $user): void
{
    Workspace::enter((int) $user['workspace_id']);
    session_regenerate_id(true);
    $_SESSION['staff'] = staff_session($user);
    Access::stampSession('staff', (int) ($user['session_version'] ?? 0));
}

function logout_staff(): void
{
    unset($_SESSION['staff'], $_SESSION['grade_import'], $_SESSION['acting_owner']);
    session_regenerate_id(true);
}

// ---------------------------------------------------------------- parents and guardians

function current_parent(): ?array
{
    return $_SESSION['parent'] ?? null;
}

/**
 * Stops unless a parent is signed in. Re-reads the account, so a deactivated account, "sign out now" or
 * parent sign-in being turned off takes effect at once.
 */
function require_parent(): array
{
    $id = $_SESSION['parent']['id'] ?? null;
    $parent = $id ? db_one('SELECT * FROM parents WHERE id = ? AND workspace_id = ?', [$id, (int) ($_SESSION['workspace_id'] ?? 0)]) : null;
    if ($parent) {
        Workspace::switchTo((int) $parent['workspace_id']);
    }
    $message = match (true) {
        !$parent => 'Please sign in to continue.',
        Workspace::isSuspended() => Workspace::suspendedMessage(),
        !$parent['is_active'] => 'Your account was turned off by the school.',
        Access::sessionRevoked('parent', $parent) => 'You were signed out by the school. Please sign in again.',
        !Access::loginOpen('parent') => Access::closedMessage('parent'),
        default => null,
    };
    if ($message !== null) {
        unset($_SESSION['parent']);
        flash('warning', $message);
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
    Workspace::enter((int) $parent['workspace_id']);
    session_regenerate_id(true);
    $_SESSION['parent'] = ['id' => (int) $parent['id'], 'full_name' => $parent['full_name']];
    Access::stampSession('parent', (int) ($parent['session_version'] ?? 0));
}

function logout_parent(): void
{
    unset($_SESSION['parent'], $_SESSION['parent_rows']);
    session_regenerate_id(true);
}

/** A learner linked to this parent's account, with the relationship. Otherwise stops with 403. */
function parent_child_or_abort(array $parent, int $studentId): array
{
    $child = $studentId > 0 ? db_one(
        'SELECT st.*, ps.relationship FROM parent_students ps JOIN students st ON st.id = ps.student_id
          WHERE ps.parent_id = ? AND ps.student_id = ? AND st.workspace_id = ?',
        [$parent['id'], $studentId, ws()]
    ) : null;
    if (!$child) {
        abort(403, 'This learner is not linked to your account.');
    }
    return $child;
}

// ---------------------------------------------------------------- learners

/** The signed-in learner's record, or back to the sign-in page (also when access was turned off). */
function require_student(): array
{
    $id = current_student_id();
    $student = $id !== null ? db_one('SELECT * FROM students WHERE id = ? AND workspace_id = ?', [$id, (int) ($_SESSION['workspace_id'] ?? 0)]) : null;
    if ($student) {
        Workspace::switchTo((int) $student['workspace_id']);
    }
    $message = match (true) {
        !$student => 'Please sign in to continue.',
        Workspace::isSuspended() => Workspace::suspendedMessage(),
        (bool) ($student['login_blocked'] ?? false) => 'Your access to the portal was turned off by the school. Please talk to your adviser.',
        Access::sessionRevoked('learner', $student) => 'You were signed out by the school. Please sign in again.',
        !Access::loginOpen('learner') => Access::closedMessage('learner'),
        default => null,
    };
    if ($message !== null) {
        logout_student();
        flash('warning', $message);
        redirect('learner/login.php');
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
    $student = db_one('SELECT workspace_id, session_version FROM students WHERE id = ?', [$studentId]);
    Workspace::enter((int) $student['workspace_id']);
    session_regenerate_id(true);
    $_SESSION['student_id'] = $studentId;
    Access::stampSession('learner', (int) $student['session_version']);
}

function logout_student(): void
{
    unset($_SESSION['student_id'], $_SESSION['student_rows']);
    session_regenerate_id(true);
}

// ---------------------------------------------------------------- platform owner (the company running the system)

function current_owner(): ?array
{
    return $_SESSION['owner'] ?? null;
}

/** Stops unless the platform owner is signed in. Re-reads the account, so turning it off or a new password ends other sessions. */
function require_owner(): array
{
    $id = $_SESSION['owner']['id'] ?? null;
    $owner = $id ? db_one('SELECT * FROM platform_owners WHERE id = ?', [$id]) : null;
    if (!$owner || !$owner['is_active'] || (int) $owner['session_version'] !== (int) ($_SESSION['owner']['version'] ?? -1)) {
        unset($_SESSION['owner']);
        flash('warning', 'Please sign in to continue.');
        redirect('platform/login.php');
    }
    return $_SESSION['owner'];
}

function login_owner(array $owner): void
{
    session_regenerate_id(true);
    $_SESSION['owner'] = [
        'id'        => (int) $owner['id'],
        'username'  => $owner['username'],
        'full_name' => $owner['full_name'],
        'version'   => (int) $owner['session_version'],
    ];
    db_exec('UPDATE platform_owners SET last_login_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $owner['id']]);
}

function logout_owner(): void
{
    unset($_SESSION['owner']);
    session_regenerate_id(true);
}

/** Records what the platform owner did (adding or pausing a school, opening a school's portal, ...). */
function platform_log(string $action, string $summary, ?int $workspaceId = null): void
{
    $owner = current_owner();
    db_exec(
        'INSERT INTO platform_log (owner_id, owner_name, workspace_id, action, summary, ip) VALUES (?, ?, ?, ?, ?, ?)',
        [$owner['id'] ?? null, $owner ? $owner['full_name'] . ' (' . $owner['username'] . ')' : 'System', $workspaceId, $action, mb_substr($summary, 0, 500), client_ip()]
    );
}
