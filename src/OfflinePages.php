<?php
declare(strict_types=1);

/**
 * Offline viewing. Pages a signed-in learner, parent or teacher opens are saved on their device by the app
 * (public/sw.js and assets/offline-pages.js) and shown read-only, with a "saved copy" bar, when there is no
 * internet. On by default in the installed app (usually the person's own phone); in a normal browser only after
 * the person turns it on in their menu. Signing out deletes every saved page.
 *
 * The server decides what may be saved: read-only pages of the person's own portal, never admin, Super Admin or
 * platform pages, sign-in forms or the live attendance page. It also lists the main pages the device saves by
 * itself after sign-in, so they work offline before they were ever opened.
 */
final class OfflinePages
{
    /** Folders and pages that may be saved (paths under public/) */
    private const ALLOW = ['learner/', 'parent/', 'teacher/', 'grades.php', 'news.php', 'messages.php', 'requests.php'];

    /** Never saved: sign-in and account forms, live attendance, uploads, downloads */
    private const DENY = [
        'learner/login.php', 'learner/activate.php', 'learner/password.php',
        'parent/login.php', 'parent/register.php', 'parent/link.php', 'parent/password.php', 'parent/logout.php', 'parent/notifications.php',
        'teacher/session.php', 'teacher/session-api.php', 'teacher/offline-api.php', 'teacher/upload.php', 'teacher/template.php',
        'teacher/attendance-export.php', 'teacher/publish.php', 'teacher/schedule-edit.php',
    ];

    /** Most pages saved by themselves after sign-in */
    private const PRELOAD_LIMIT = 30;

    /**
     * A key for the signed-in account on this device, or null when nobody is signed in. A different person
     * signing in, or "sign out everywhere", gives another key, and the device then deletes the old pages.
     */
    public static function accountKey(?array $staff, ?array $parent, ?int $learnerId): ?string
    {
        [$type, $id] = match (true) {
            $staff !== null => ['staff', (int) $staff['id']],
            $parent !== null => ['parent', (int) $parent['id']],
            $learnerId !== null => ['learner', $learnerId],
            default => [null, 0],
        };
        if ($type === null || !Workspace::known()) {
            return null;
        }
        $version = (int) ($_SESSION['session_version'][$type] ?? 0);
        return substr(hash('sha256', "{$type}:{$id}:" . ws() . ":{$version}"), 0, 24);
    }

    /** The page being shown, as a path under public/ (e.g. "teacher/class.php"), however the server maps the URL. */
    public static function currentPage(): string
    {
        $public = realpath(APP_ROOT . '/public');
        $file = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($public === false || $file === false || !str_starts_with($file, $public)) {
            return '';
        }
        return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($public))), '/');
    }

    /** True when the page (path under public/, e.g. "teacher/class.php") may be kept on the device. */
    public static function maySave(string $page): bool
    {
        if (in_array($page, self::DENY, true)) {
            return false;
        }
        foreach (self::ALLOW as $allowed) {
            if ($page === $allowed || (str_ends_with($allowed, '/') && str_starts_with($page, $allowed))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The main pages of this person's portal, saved by the device after sign-in (paths under public/).
     * @return list<string>
     */
    public static function preload(?array $staff, ?array $parent, ?int $learnerId): array
    {
        if ($learnerId !== null && $staff === null && $parent === null) {
            $pages = ['learner/index.php', 'learner/schedule.php'];
            if (Access::can('grades')) {
                $pages[] = 'grades.php';
            }
            if (Access::can('materials') || Access::can('class_record')) {
                $pages[] = 'learner/subjects.php';
            }
            return $pages;
        }
        if ($parent !== null && $staff === null) {
            $pages = ['parent/index.php'];
            $children = db_all(
                'SELECT ps.student_id FROM parent_students ps JOIN students st ON st.id = ps.student_id
                  WHERE ps.parent_id = ? AND st.workspace_id = ? ORDER BY st.first_name',
                [$parent['id'], ws()]
            );
            foreach ($children as $child) {
                $id = (int) $child['student_id'];
                if (Access::can('attendance')) {
                    $pages[] = 'parent/child.php?id=' . $id;
                }
                if (Access::can('grades')) {
                    $pages[] = 'parent/child.php?tab=grades&id=' . $id;
                }
            }
            return array_slice($pages, 0, self::PRELOAD_LIMIT);
        }
        if ($staff !== null) {
            $sy = active_school_year();
            $classes = $sy ? db_all(
                'SELECT ca.id FROM class_assignments ca JOIN sections s ON s.id = ca.section_id
                  WHERE ca.teacher_id = ? AND s.school_year_id = ? AND ca.workspace_id = ?
                  ORDER BY ca.semester, s.grade_level, s.name',
                [$staff['id'], $sy['id'], ws()]
            ) : [];
            if (!$classes && !advised_section_ids($staff)) {
                return []; // admins, principals and guidance counselors without classes: their pages aren't saved
            }
            $pages = ['teacher/index.php', 'teacher/schedule.php'];
            if (advised_section_ids($staff)) {
                $pages[] = 'teacher/advisory.php';
            }
            foreach ($classes as $class) {
                $pages[] = 'teacher/class.php?id=' . (int) $class['id'];
                if (Access::can('attendance')) {
                    $pages[] = 'teacher/attendance.php?id=' . (int) $class['id'];
                }
            }
            return array_slice($pages, 0, self::PRELOAD_LIMIT);
        }
        return [];
    }
}
