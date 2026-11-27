<?php
declare(strict_types=1);

/**
 * Sends what is waiting for parents' phones: every new parent notification as a phone alert (web push, free;
 * the school can turn it off) and the queued text messages (Sms).
 *
 * Nothing is sent while a page is being made. Alerts::kick() asks for a send run after the page has answered
 * (the browser doesn't wait where the server allows it: PHP-FPM or LiteSpeed). Alerts::sweep() starts a run at
 * most once a minute on any page load when something is still waiting, and bin/send-alerts.php can be run by
 * cron. Only one run works at a time (a database lock).
 */
final class Alerts
{
    /** Phone alerts for notifications older than this are not sent any more */
    private const PUSH_MAX_AGE = 86400;

    private static bool $kicked = false;

    public static function pushOn(): bool
    {
        return (string) setting('push_enabled') !== '0';
    }

    /** Sends what is waiting once this request has answered. */
    public static function kick(): void
    {
        if (self::$kicked || PHP_SAPI === 'cli') {
            return;
        }
        self::$kicked = true;
        register_shutdown_function(static function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close(); // the person's next click must not wait for this
            }
            ignore_user_abort(true);
            @set_time_limit(60);
            $answered = false;
            if (function_exists('fastcgi_finish_request')) {
                $answered = fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                $answered = (bool) litespeed_finish_request();
            }
            try {
                // Where the browser still waits (e.g. Apache with mod_php), only a short run; the rest goes later
                self::run($answered ? 20 : 4);
            } catch (Throwable $e) {
                error_log('Alerts: ' . $e->getMessage());
            }
        });
    }

    /** On any page load, at most once a minute: start a send run when something is still waiting. */
    public static function sweep(): void
    {
        $mark = APP_ROOT . '/storage/alerts-sweep';
        $last = @filemtime($mark);
        if ($last !== false && $last > time() - 60) {
            return;
        }
        if (!@touch($mark)) {
            return;
        }
        try {
            $waiting = db_value('SELECT 1 FROM parent_notifications WHERE alerted = 0 LIMIT 1')
                || db_value("SELECT 1 FROM sms_messages WHERE status = 'queued' AND (next_try_at IS NULL OR next_try_at <= ?) LIMIT 1", [date('Y-m-d H:i:s')]);
        } catch (Throwable) {
            return; // tables not there yet (before the migration)
        }
        if ($waiting) {
            self::kick();
        }
    }

    /** @return array{push: int, sms: int} alerts and texts handled */
    public static function run(float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        $lock = 'araltrack_alerts_' . md5((string) db_value('SELECT DATABASE()'));
        // Waits a moment for a run already working (it may just be finishing), then leaves the rest to it.
        // A short run (someone may be waiting for the page) doesn't wait.
        if (!(int) db_value('SELECT GET_LOCK(?, ?)', [$lock, $seconds >= 10 ? 3 : 0])) {
            return ['push' => 0, 'sms' => 0];
        }
        try {
            $push = self::pushPending($deadline);
            $sms = Sms::flush($deadline);
            // Something may have been added while sending: one more round if there is time
            if (microtime(true) < $deadline - 2) {
                $push += self::pushPending($deadline);
                $sms += Sms::flush($deadline);
            }
            return ['push' => $push, 'sms' => $sms];
        } finally {
            db_value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Sends each new parent notification to the parent's phones. */
    private static function pushPending(float $deadline): int
    {
        $handled = 0;
        while (microtime(true) < $deadline) {
            $rows = db_all(
                'SELECT n.id, n.parent_id, n.type, n.message, n.link, n.created_at, p.workspace_id
                   FROM parent_notifications n JOIN parents p ON p.id = n.parent_id
                  WHERE n.alerted = 0 ORDER BY n.id LIMIT 50'
            );
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }
                if (!db_exec('UPDATE parent_notifications SET alerted = 1 WHERE id = ? AND alerted = 0', [$row['id']])) {
                    continue; // another run has it
                }
                $handled++;
                if (strtotime($row['created_at']) < time() - self::PUSH_MAX_AGE) {
                    continue;
                }
                Workspace::within((int) $row['workspace_id'], static function () use ($row): void {
                    if (!self::pushOn()) {
                        return;
                    }
                    $payload = [
                        'title' => self::title($row['type']),
                        'body'  => $row['message'],
                        'url'   => $row['link'] !== '' ? $row['link'] : 'parent/notifications.php',
                        'tag'   => 'n' . $row['id'],
                    ];
                    $urgent = in_array($row['type'], ['arrived', 'left', 'late', 'absent'], true);
                    foreach (db_all('SELECT * FROM push_subscriptions WHERE parent_id = ?', [$row['parent_id']]) as $device) {
                        self::deliver($device, $payload, $urgent ? 'high' : 'normal');
                    }
                });
            }
        }
        return $handled;
    }

    /**
     * Sends one alert to one device and keeps the device list clean.
     * @return int the push service's HTTP status (201 = sent)
     */
    public static function deliver(array $device, array $payload, string $urgency = 'normal'): int
    {
        try {
            $status = WebPush::send($device, $payload, $urgency);
        } catch (Throwable $e) {
            error_log('Phone alert: ' . $e->getMessage());
            $status = 0;
        }
        if ($status >= 200 && $status < 300) {
            db_exec('UPDATE push_subscriptions SET last_sent_at = ?, failures = 0 WHERE id = ?', [date('Y-m-d H:i:s'), $device['id']]);
        } elseif (in_array($status, [400, 403, 404, 410, 413], true) || (int) $device['failures'] >= 9) {
            // The phone turned alerts off, the browser was reset, or it keeps failing: forget the device
            db_exec('DELETE FROM push_subscriptions WHERE id = ?', [$device['id']]);
        } else {
            db_exec('UPDATE push_subscriptions SET failures = failures + 1 WHERE id = ?', [$device['id']]);
        }
        return $status;
    }

    public static function title(string $type): string
    {
        return [
            'arrived' => 'Arrived at school', 'left' => 'Left school', 'late' => 'Late to class', 'absent' => 'Absent from class',
            'changed' => 'Attendance changed', 'grades' => 'New grades', 'announcement' => 'School news', 'excuse' => 'Excuse letter',
            'request' => 'Document request', 'message' => 'New message',
        ][$type] ?? (string) config('app_name');
    }

    // ------------------------------------------------------------ parents' devices

    /** Saves this browser for the parent's accounts (one per school). */
    public static function subscribe(array $parentIds, string $endpoint, string $p256dh, string $auth, string $device): bool
    {
        if (!WebPush::allowedEndpoint($endpoint) || strlen(WebPush::unb64($p256dh)) !== 65 || strlen(WebPush::unb64($auth)) < 16) {
            return false;
        }
        $hash = hash('sha256', $endpoint);
        foreach ($parentIds as $parentId) {
            $workspace = db_value('SELECT workspace_id FROM parents WHERE id = ?', [$parentId]);
            if ($workspace === null) {
                continue;
            }
            db_exec(
                'INSERT INTO push_subscriptions (workspace_id, parent_id, endpoint, endpoint_hash, p256dh, auth, device)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth), device = VALUES(device), failures = 0',
                [(int) $workspace, $parentId, $endpoint, $hash, $p256dh, $auth, mb_substr($device, 0, 120)]
            );
        }
        return true;
    }

    public static function unsubscribe(array $parentIds, string $endpoint): void
    {
        if ($parentIds) {
            db_exec(
                'DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND parent_id IN (' . implode(',', array_map('intval', $parentIds)) . ')',
                [hash('sha256', $endpoint)]
            );
        }
    }
}
