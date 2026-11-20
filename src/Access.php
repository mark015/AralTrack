<?php
declare(strict_types=1);

/**
 * What the Super Admin controls (Super Admin → Control panel):
 *  - parts of the portal turned on or off (attendance, grading, messages, …),
 *  - which groups may sign in (teachers & staff, learners, parents) and maintenance mode,
 * plus the sign-in history and the log of account and settings changes.
 * Every switch is ON unless the Super Admin turns it off, so a new install works as before.
 */
final class Access
{
    /** module => [label, icon, what happens when it is OFF] */
    public const MODULES = [
        'attendance'   => ['Attendance (QR scanning)', 'qr-code-scan', 'Teachers can\'t take attendance or scan QR codes. Learners and parents don\'t see attendance, and no late or absent notifications are sent.'],
        'grading'      => ['Grading', 'journal-check', 'Teachers can\'t type, upload or publish grades, and learners and parents don\'t see report cards.'],
        'class_record' => ['Class record (scores)', 'table', 'Teachers don\'t see the Class record, and learners don\'t see My scores.'],
        'excuses'      => ['Excuse letters', 'envelope-paper', 'Parents can\'t send excuse letters, and advisers don\'t see the Excuse letters page.'],
        'messages'     => ['Messages', 'chat-dots', 'Parents and teachers can\'t send or read messages.'],
        'materials'    => ['Class materials', 'folder2-open', 'Teachers can\'t post materials, and learners can\'t see them.'],
        'requests'     => ['Document requests', 'file-earmark-text', 'Learners and parents can\'t request documents. The registrar can still see earlier requests.'],
        'news'         => ['News & calendar', 'megaphone', 'Announcements and the calendar are hidden, and parents aren\'t notified of new announcements.'],
    ];

    /** Finer grading switches (only matter while Grading is on): key => [label, what it does] */
    public const GRADING = [
        'grading_encoding' => ['Grade encoding', 'Teachers can type, upload and publish grades. Off: grades are read-only, e.g. until the grading period starts.'],
        'grades_learners'  => ['Learners see their grades', 'The report card in the learner portal.'],
        'grades_parents'   => ['Parents see grades', 'The report card in the parent portal, and "grades released" notifications.'],
    ];

    /** group => label */
    public const LOGIN_GROUPS = ['staff' => 'Teachers & staff', 'learner' => 'Learners', 'parent' => 'Parents'];

    public const LEARNER_LOGIN_MODES = [
        'lrn'           => 'LRN only (anyone who knows the LRN can see the grades)',
        'lrn_birthdate' => 'LRN + birthdate',
        'password'      => 'LRN + password (learners activate their account once with their birthdate)',
    ];

    private static function flag(string $key): bool
    {
        return (string) setting($key) !== '0';
    }

    public static function on(string $module): bool
    {
        return self::flag('module_' . $module);
    }

    public static function gradeEncodingOpen(): bool
    {
        return self::on('grading') && self::flag('grading_encoding');
    }

    public static function learnersSeeGrades(): bool
    {
        return self::on('grading') && self::flag('grades_learners');
    }

    public static function parentsSeeGrades(): bool
    {
        return self::on('grading') && self::flag('grades_parents');
    }

    public static function maintenance(): bool
    {
        return (string) setting('maintenance_mode') === '1';
    }

    public static function maintenanceMessage(): string
    {
        $message = trim((string) setting('maintenance_message'));
        return $message !== '' ? $message : 'The portal is closed for maintenance. Please try again later.';
    }

    /** Whether this group may sign in now. The Super Admin can always sign in. */
    public static function loginOpen(string $group): bool
    {
        return !self::maintenance() && self::flag('login_' . $group);
    }

    public static function parentRegistrationOpen(): bool
    {
        return self::loginOpen('parent') && self::flag('parent_registration');
    }

    /** Why a group can't sign in, in plain words. */
    public static function closedMessage(string $group): string
    {
        if (self::maintenance()) {
            return self::maintenanceMessage();
        }
        return match ($group) {
            'staff'   => 'Sign-in for teachers and staff is turned off by the school right now.',
            'learner' => 'Sign-in for learners is turned off by the school right now.',
            default   => 'Sign-in for parents is turned off by the school right now.',
        };
    }

    /** Stops a page that belongs to a part of the portal that is turned off. */
    public static function requireModule(string $module): void
    {
        if (self::on($module)) {
            return;
        }
        if (str_ends_with(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '-api.php')) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => self::MODULES[$module][0] . ' is turned off by the school.']);
            exit;
        }
        self::stopPage(self::MODULES[$module][0] . ' is turned off', 'This part of the portal is turned off by the school for now.');
    }

    /** A friendly "not available" page (HTTP 403) with the normal header and footer. */
    public static function stopPage(string $heading, string $text): never
    {
        http_response_code(403);
        $title = $heading;
        require APP_ROOT . '/templates/header.php';
        echo '<div class="card mx-auto" style="max-width: 36rem"><div class="card-body text-center py-5">'
            . '<div class="icon-bubble gold mx-auto mb-3"><i class="bi bi-slash-circle" aria-hidden="true"></i></div>'
            . '<h1 class="h5">' . e($heading) . '</h1><p class="text-secondary mb-0">' . e($text) . '</p></div></div>';
        require APP_ROOT . '/templates/footer.php';
        exit;
    }

    /** A short note for registrar pages of a part that is off (the page still works for earlier records). */
    public static function offNotice(string $module): string
    {
        if (self::on($module)) {
            return '';
        }
        return '<div class="alert alert-warning"><i class="bi bi-slash-circle" aria-hidden="true"></i><div><strong>'
            . e(self::MODULES[$module][0]) . ' is turned off</strong> in the Super Admin control panel. '
            . e(self::MODULES[$module][2]) . '</div></div>';
    }

    // ------------------------------------------------------------ sign-in history and activity log

    /** "Chrome on Android", "Edge on Windows", … from the browser's user agent. */
    public static function device(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            return PHP_SAPI === 'cli' ? 'Command line' : 'Unknown device';
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'curl/') => 'curl',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };
        return $os !== '' ? "{$browser} on {$os}" : $browser;
    }

    /** Records a sign-in attempt; on success also the account's last sign-in. Never stops a sign-in. */
    public static function logLogin(string $type, ?int $accountId, string $identifier, bool $success, ?string $reason = null): void
    {
        try {
            $now = date('Y-m-d H:i:s');
            db_exec(
                'INSERT INTO login_events (account_type, account_id, identifier, success, reason, ip, device, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$type, $accountId, mb_substr($identifier, 0, 150), $success ? 1 : 0, $reason, client_ip(), self::device(), $now]
            );
            if ($success && $accountId) {
                $table = ['staff' => 'users', 'parent' => 'parents', 'learner' => 'students'][$type];
                db_exec("UPDATE {$table} SET last_login_at = ?, last_login_ip = ?, last_login_device = ? WHERE id = ?", [$now, client_ip(), self::device(), $accountId]);
            }
            if (random_int(1, 200) === 1) {
                self::prune();
            }
        } catch (Throwable $e) {
            error_log('Sign-in log failed: ' . $e->getMessage());
        }
    }

    /** Records an account, access or settings change by the signed-in staff member. */
    public static function log(string $action, string $summary, ?string $targetType = null, ?int $targetId = null, ?array $details = null): void
    {
        try {
            $staff = current_staff();
            db_exec(
                'INSERT INTO activity_log (actor_id, actor_name, action, target_type, target_id, summary, details, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $staff['id'] ?? null,
                    $staff ? $staff['full_name'] . ' (' . $staff['username'] . ')' : 'System',
                    $action,
                    $targetType,
                    $targetId,
                    mb_substr($summary, 0, 500),
                    $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    PHP_SAPI === 'cli' ? null : client_ip(),
                    date('Y-m-d H:i:s'),
                ]
            );
        } catch (Throwable $e) {
            error_log('Activity log failed: ' . $e->getMessage());
        }
    }

    /** Settings that differ between two arrays, as [key => [old, new]], for the activity log. */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            $old = (string) ($before[$key] ?? '');
            if ($old !== (string) $value) {
                $changes[$key] = [$old, (string) $value];
            }
        }
        return $changes;
    }

    public static function keepDays(): int
    {
        return max(30, min(3650, (int) (setting('log_keep_days') ?: 365)));
    }

    /** Deletes log entries older than the kept period. */
    public static function prune(): void
    {
        $cut = date('Y-m-d H:i:s', time() - self::keepDays() * 86400);
        db_exec('DELETE FROM login_events WHERE created_at < ?', [$cut]);
        db_exec('DELETE FROM activity_log WHERE created_at < ?', [$cut]);
    }

    // ------------------------------------------------------------ signing accounts out

    private const ACCOUNT_TABLES = ['staff' => 'users', 'parent' => 'parents', 'learner' => 'students'];

    /** Ends every session of one account on every device (on its next page load). */
    public static function signOut(string $type, int $id): void
    {
        db_exec('UPDATE ' . self::ACCOUNT_TABLES[$type] . ' SET session_version = session_version + 1 WHERE id = ?', [$id]);
    }

    /** Ends everyone's sessions except the person doing it. */
    public static function signOutEveryone(int $exceptStaffId): void
    {
        db_exec('UPDATE users SET session_version = session_version + 1 WHERE id <> ?', [$exceptStaffId]);
        db_exec('UPDATE parents SET session_version = session_version + 1');
        db_exec('UPDATE students SET session_version = session_version + 1');
    }

    /** Remembers which session version this browser signed in with. */
    public static function stampSession(string $type, int $version): void
    {
        $_SESSION['session_version'][$type] = $version;
    }

    /** True when this browser's sign-in has been ended by "sign out now" or "sign out everyone". */
    public static function sessionRevoked(string $type, array $account): bool
    {
        return (int) ($account['session_version'] ?? 0) !== (int) ($_SESSION['session_version'][$type] ?? 0);
    }
}
