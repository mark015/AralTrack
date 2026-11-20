<?php
declare(strict_types=1);

/**
 * Counts failed sign-ins so nobody can guess birthdates or passwords by trying many times.
 * Limits apply per LRN/username and, more loosely, per IP address.
 */
final class RateLimiter
{
    public static function isLocked(string $scope, string $identifier, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - (int) config('login_window_min') * 60);

        $byIdentifier = (int) db_value(
            'SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND identifier = ? AND attempted_at > ?',
            [$scope, $identifier, $since]
        );
        if ($byIdentifier >= (int) config('login_max_per_id')) {
            return true;
        }

        $byIp = (int) db_value(
            'SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND ip = ? AND attempted_at > ?',
            [$scope, $ip, $since]
        );
        return $byIp >= (int) config('login_max_per_ip');
    }

    public static function recordFailure(string $scope, string $identifier, string $ip): void
    {
        db_exec(
            'INSERT INTO login_attempts (scope, identifier, ip, attempted_at) VALUES (?, ?, ?, ?)',
            [$scope, mb_substr($identifier, 0, 100), $ip, date('Y-m-d H:i:s')]
        );
        // Now and then, throw away records older than a day.
        if (random_int(1, 50) === 1) {
            db_exec('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        }
    }

    public static function clear(string $scope, string $identifier): void
    {
        db_exec('DELETE FROM login_attempts WHERE scope = ? AND identifier = ?', [$scope, $identifier]);
    }
}
