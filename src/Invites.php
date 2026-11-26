<?php
declare(strict_types=1);

/**
 * A school invites a staff member by email (Staff accounts). The link works once, for 14 days, and only for that
 * email address. join.php lets the person create their account in the school, or, when they already use the system
 * on their own, bring their classes into the school (src/WorkspaceMerge.php). Links are stored only as hashes.
 */
final class Invites
{
    public const DAYS = 14;
    public const ROLES = ['teacher' => 'Teacher', 'admin' => 'Admin · Registrar', 'principal' => 'Principal', 'guidance' => 'Guidance counselor'];

    /** Makes an invitation (an older open one for the same email is replaced). Returns [invite, link]. */
    public static function create(int $workspaceId, string $email, string $role, int $byUserId): array
    {
        $email = mb_strtolower(trim($email));
        db_exec(
            'UPDATE staff_invites SET cancelled_at = ? WHERE workspace_id = ? AND email = ? AND accepted_at IS NULL AND cancelled_at IS NULL',
            [date('Y-m-d H:i:s'), $workspaceId, $email]
        );
        $token = bin2hex(random_bytes(24));
        db_exec(
            'INSERT INTO staff_invites (workspace_id, email, role, token_hash, created_by, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$workspaceId, $email, $role, hash('sha256', $token), $byUserId, date('Y-m-d H:i:s', time() + self::DAYS * 86400)]
        );
        $invite = db_one('SELECT * FROM staff_invites WHERE id = ?', [(int) db()->lastInsertId()]);
        return [$invite, self::link($token)];
    }

    public static function link(string $token): string
    {
        return (Mailer::appUrl() ?? rtrim(public_url(''), '/')) . '/join.php?token=' . $token;
    }

    /** Emails the invitation when email is set up. Returns true when it was sent. */
    public static function send(array $invite, string $link, string $schoolName, string $fromName): bool
    {
        if (!Mailer::ready() || Mailer::isTestMode()) {
            return false;
        }
        $app = config('app_name');
        $role = strtolower(self::ROLES[$invite['role']] ?? 'staff member');
        try {
            Mailer::send(
                (string) $invite['email'],
                '',
                "{$schoolName} invites you to {$app}",
                "Hello,\n\n{$fromName} invites you to join {$schoolName} on {$app} as a {$role}.\n\n"
                . "Open this link to accept (it works for " . self::DAYS . " days):\n\n{$link}\n\n"
                . "If you already use {$app} for your own classes, you can bring them into the school.\n\n— {$app}"
            );
            return true;
        } catch (RuntimeException $e) {
            error_log('Invitation email failed: ' . $e->getMessage());
            return false;
        }
    }

    /** An open invitation for this link (with the school's name and type), or null. */
    public static function find(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
            return null;
        }
        return db_one(
            "SELECT i.*, w.name AS school_name, w.type AS school_type, w.status AS school_status FROM staff_invites i
               JOIN workspaces w ON w.id = i.workspace_id
              WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.cancelled_at IS NULL AND i.expires_at > ?",
            [hash('sha256', $token), date('Y-m-d H:i:s')]
        );
    }

    public static function markAccepted(int $inviteId, int $userId): void
    {
        db_exec('UPDATE staff_invites SET accepted_at = ?, accepted_user_id = ? WHERE id = ?', [date('Y-m-d H:i:s'), $userId, $inviteId]);
    }

    /** Open invitations of the current school. */
    public static function open(): array
    {
        return db_all(
            'SELECT i.*, u.full_name AS invited_by FROM staff_invites i LEFT JOIN users u ON u.id = i.created_by
              WHERE i.workspace_id = ? AND i.accepted_at IS NULL AND i.cancelled_at IS NULL AND i.expires_at > ? ORDER BY i.id DESC',
            [ws(), date('Y-m-d H:i:s')]
        );
    }
}
