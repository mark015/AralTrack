<?php
declare(strict_types=1);

/**
 * Teacher sign-up and email codes. A teacher who signs up gets a workspace of their own (type "teacher") with
 * one account: theirs, which runs that workspace. The email address is confirmed with a 6-digit code before the
 * first sign-in, and a forgotten password is reset with a link sent to it. Codes and links are stored as hashes.
 */
final class Signup
{
    public const CODE_MINUTES = 30;
    public const RESET_MINUTES = 60;
    private const MAX_TRIES = 5;

    /** True when teachers can sign up on this server (turned on, and emails can be sent). */
    public static function open(): bool
    {
        return config('teacher_signup') !== false && Mailer::ready();
    }

    /** Creates the teacher's workspace and account (email not confirmed yet). Returns the account. */
    public static function createTeacher(string $name, string $email, string $password, string $schoolName): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_exec(
                "INSERT INTO workspaces (type, name, plan, trial_ends_at, contact_name, contact_email) VALUES ('teacher', ?, 'trial', ?, ?, ?)",
                [$schoolName, Billing::trialEnd(), $name, $email]
            );
            $workspaceId = (int) $pdo->lastInsertId();
            db_exec("INSERT INTO settings (workspace_id, name, value) VALUES (?, 'school_name', ?), (?, 'setup_done', '0')", [$workspaceId, $schoolName, $workspaceId]);
            db_exec(
                "INSERT INTO users (workspace_id, username, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?, 'superadmin')",
                [$workspaceId, self::username($email), $email, password_hash($password, PASSWORD_DEFAULT), $name]
            );
            $userId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        platform_log('signup', "{$name} ({$email}) signed up as a teacher at {$schoolName}", $workspaceId);
        return db_one('SELECT * FROM users WHERE id = ?', [$userId]);
    }

    /** A free username made from the email address (the teacher signs in with the email anyway). */
    private static function username(string $email): string
    {
        $base = (string) preg_replace('/[^a-z0-9._-]/', '', strtolower((string) strstr($email, '@', true)));
        $base = substr(strlen($base) >= 3 ? $base : 'teacher', 0, 30);
        for ($i = 0; $i < 25; $i++) {
            $candidate = $i === 0 ? $base : $base . random_int(10, 99999);
            if (!db_value('SELECT 1 FROM users WHERE username = ?', [$candidate])) {
                return $candidate;
            }
        }
        return $base . bin2hex(random_bytes(4));
    }

    // ------------------------------------------------------------ confirming the email address

    /** Emails a new 6-digit code; older codes stop working. Returns the code (shown on screen only in test mode). */
    public static function sendVerifyCode(array $user): string
    {
        self::throttle((int) $user['id'], 'verify');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        self::store((int) $user['id'], 'verify', self::codeHash((int) $user['id'], $code), self::CODE_MINUTES);
        $app = config('app_name');
        Mailer::send(
            (string) $user['email'],
            (string) $user['full_name'],
            "Your {$app} code: {$code}",
            "Hi {$user['full_name']},\n\nYour code to confirm your email address is:\n\n    {$code}\n\n"
            . 'It works for ' . self::CODE_MINUTES . " minutes. If you didn't sign up for {$app}, you can ignore this email.\n\n— {$app}"
        );
        return $code;
    }

    /** Checks a typed code; on success the email address is confirmed. */
    public static function confirm(int $userId, string $typed): bool
    {
        $row = db_one(
            "SELECT * FROM email_codes WHERE user_id = ? AND purpose = 'verify' AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1",
            [$userId, date('Y-m-d H:i:s')]
        );
        if (!$row || (int) $row['attempts'] >= self::MAX_TRIES) {
            return false;
        }
        $code = (string) preg_replace('/\D/', '', $typed);
        if (!hash_equals($row['code_hash'], self::codeHash($userId, $code))) {
            db_exec('UPDATE email_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            return false;
        }
        db_exec('UPDATE email_codes SET used_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $row['id']]);
        db_exec('UPDATE users SET email_verified_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $userId]);
        return true;
    }

    // ------------------------------------------------------------ forgotten password

    /**
     * Emails a password-reset link when a confirmed account has this email. Says nothing either way, so the form
     * can't be used to find out who has an account. Returns the link only in test mode (shown on screen there).
     */
    public static function sendReset(string $email): ?string
    {
        $user = db_one('SELECT * FROM users WHERE email = ? AND email_verified_at IS NOT NULL AND is_active = 1', [mb_strtolower(trim($email))]);
        if (!$user) {
            return null;
        }
        try {
            self::throttle((int) $user['id'], 'reset');
        } catch (UserFacingException) {
            return null;
        }
        $token = bin2hex(random_bytes(24));
        self::store((int) $user['id'], 'reset', hash('sha256', $token), self::RESET_MINUTES);
        $link = Mailer::appUrl() . '/staff/reset.php?token=' . $token;
        $app = config('app_name');
        Mailer::send(
            (string) $user['email'],
            (string) $user['full_name'],
            "Reset your {$app} password",
            "Hi {$user['full_name']},\n\nTo choose a new password, open this link:\n\n{$link}\n\n"
            . 'It works for ' . self::RESET_MINUTES . " minutes, once. If you didn't ask for this, you can ignore this email; your password stays the same.\n\n— {$app}"
        );
        return Mailer::isTestMode() ? $link : null;
    }

    /** The account a reset link belongs to (with 'reset_id'), or null when the link is wrong, used or expired. */
    public static function resetAccount(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
            return null;
        }
        $row = db_one(
            "SELECT id, user_id FROM email_codes WHERE code_hash = ? AND purpose = 'reset' AND used_at IS NULL AND expires_at > ?",
            [hash('sha256', $token), date('Y-m-d H:i:s')]
        );
        $user = $row ? db_one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$row['user_id']]) : null;
        return $user ? $user + ['reset_id' => (int) $row['id']] : null;
    }

    /** Sets the new password from a reset link. The link and the account's other sessions stop working. */
    public static function resetPassword(array $account, string $password): void
    {
        $now = date('Y-m-d H:i:s');
        db_exec("UPDATE email_codes SET used_at = ? WHERE user_id = ? AND purpose = 'reset' AND used_at IS NULL", [$now, $account['id']]);
        db_exec(
            'UPDATE users SET password_hash = ?, must_change_password = 0, session_version = session_version + 1 WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $account['id']]
        );
        db_exec("DELETE FROM login_attempts WHERE scope = 'staff' AND identifier IN (?, ?)", [mb_strtolower((string) $account['username']), mb_strtolower((string) $account['email'])]);
    }

    // ------------------------------------------------------------

    private static function codeHash(int $userId, string $code): string
    {
        return hash('sha256', $userId . ':' . $code);
    }

    private static function store(int $userId, string $purpose, string $hash, int $minutes): void
    {
        db_exec('UPDATE email_codes SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [date('Y-m-d H:i:s'), $userId, $purpose]);
        db_exec(
            'INSERT INTO email_codes (user_id, purpose, code_hash, expires_at, ip) VALUES (?, ?, ?, ?, ?)',
            [$userId, $purpose, $hash, date('Y-m-d H:i:s', time() + $minutes * 60), client_ip()]
        );
    }

    /** At most one email a minute and five an hour to the same account. */
    private static function throttle(int $userId, string $purpose): void
    {
        $last = db_value('SELECT MAX(created_at) FROM email_codes WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
        if ($last && strtotime((string) $last) > time() - 60) {
            throw new UserFacingException('An email was just sent. Please wait a minute before asking for another one.');
        }
        $lastHour = (int) db_value(
            'SELECT COUNT(*) FROM email_codes WHERE user_id = ? AND purpose = ? AND created_at > ?',
            [$userId, $purpose, date('Y-m-d H:i:s', time() - 3600)]
        );
        if ($lastHour >= 5) {
            throw new UserFacingException('Too many emails were sent to this address. Please try again in an hour.');
        }
    }
}
