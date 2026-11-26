<?php
declare(strict_types=1);

/**
 * The workspace this request works in: one school, or one teacher working alone. Every query on school
 * records is limited to ws(), so one school never sees another school's data.
 *
 * A browser session belongs to one workspace at a time. Signing in to an account of another workspace
 * signs out the accounts of the first one in that browser (see enter()).
 */
final class Workspace
{
    public const TYPES = ['school' => 'School', 'teacher' => 'Teacher (solo)'];
    public const PLANS = ['trial' => 'Free trial', 'free' => 'Free', 'paid' => 'Paid'];

    private static ?int $id = null;
    private static ?array $row = null;

    /** The workspace id for this request. Stops with an error when no account is signed in. */
    public static function id(): int
    {
        if (self::$id === null) {
            $fromSession = self::fromSession();
            if ($fromSession === null) {
                throw new LogicException('No school is known for this request (nobody is signed in).');
            }
            self::$id = $fromSession;
        }
        return self::$id;
    }

    /** True when the request works in a workspace (someone is signed in, or a sign-in found the account). */
    public static function known(): bool
    {
        return self::$id !== null || self::fromSession() !== null;
    }

    /** An account of this workspace signed in: the browser session now belongs to it. */
    public static function enter(int $id): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && (int) ($_SESSION['workspace_id'] ?? 0) !== $id) {
            unset($_SESSION['staff'], $_SESSION['parent'], $_SESSION['student_id'], $_SESSION['grade_import'], $_SESSION['acting_owner']);
            $_SESSION['workspace_id'] = $id;
        }
        self::switchTo($id);
    }

    /**
     * Works in this workspace for the rest of the request without signing anyone in: a sign-in form checking
     * the account it found, the offline/attendance APIs, or command-line scripts.
     */
    public static function switchTo(int $id): void
    {
        if (self::$id !== $id) {
            self::$id = $id;
            self::$row = null;
        }
    }

    /** Runs $work inside another workspace, then returns to the request's own (e.g. cleanup for every school). */
    public static function within(int $id, callable $work): mixed
    {
        [$id0, $row0] = [self::$id, self::$row];
        self::switchTo($id);
        try {
            return $work();
        } finally {
            [self::$id, self::$row] = [$id0, $row0];
        }
    }

    /** The workspace row (name, type, status, plan, ...). */
    public static function row(): array
    {
        if (self::$row === null || (int) self::$row['id'] !== self::id()) {
            self::$row = self::find(self::id()) ?? throw new LogicException('This school no longer exists.');
        }
        return self::$row;
    }

    public static function find(int $id): ?array
    {
        return db_one('SELECT * FROM workspaces WHERE id = ?', [$id]);
    }

    public static function name(): string
    {
        return (string) self::row()['name'];
    }

    /** A teacher working alone (no school admin, principal or advisers). */
    public static function isSolo(): bool
    {
        return self::row()['type'] === 'teacher';
    }

    public static function isSuspended(): bool
    {
        return self::row()['status'] !== 'active';
    }

    public static function suspendedMessage(): string
    {
        $reason = trim((string) (self::row()['suspended_reason'] ?? ''));
        return 'This school\'s account is paused' . ($reason !== '' ? ': ' . $reason : '.')
            . ' Please contact ' . config('app_name') . ' support.';
    }

    /** Forgets the request's workspace (tests and scripts that check several workspaces). */
    public static function reset(): void
    {
        self::$id = null;
        self::$row = null;
    }

    private static function fromSession(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['workspace_id'])) {
            return null;
        }
        $signedIn = isset($_SESSION['staff']) || isset($_SESSION['parent']) || isset($_SESSION['student_id']);
        return $signedIn ? (int) $_SESSION['workspace_id'] : null;
    }
}

/** The current workspace id: add "workspace_id = ?" with ws() to every query on school records. */
function ws(): int
{
    return Workspace::id();
}
