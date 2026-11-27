<?php
declare(strict_types=1);

/**
 * Text messages (SMS) to parents, through the school's own SMS account. Set up by the Super Admin under
 * Control → Gate & SMS alerts; off until it is turned on. "Test mode" lists the texts without sending them.
 *
 * Texts are queued in sms_messages and sent after the page has answered (Alerts::kick), so scanning at the
 * gate never waits for the SMS provider. A text that fails is tried again up to 3 times. sms_messages is also
 * the log the Super Admin sees.
 */
final class Sms
{
    /** event => what the Super Admin ticks */
    public const EVENTS = [
        'gate_in'  => 'Arrives at school (first gate scan of the day)',
        'gate_out' => 'Leaves school (gate scan)',
        'late'     => 'Late to a class',
        'absent'   => 'Absent from a class',
    ];
    public const DEFAULT_EVENTS = 'gate_in,gate_out';

    public const PROVIDERS = [
        'test'      => 'Test mode (nothing is sent; the texts are only listed below)',
        'semaphore' => 'Semaphore (semaphore.co)',
    ];

    /** Settings that are secrets: never shown, exported or written to the activity log */
    public const SECRET_SETTINGS = ['sms_api_key'];

    private const SEMAPHORE = 'https://api.semaphore.co/api/v4/';
    private const MAX_ATTEMPTS = 3;
    /** A text not sent within this time (e.g. the provider was down) is dropped: "arrived at 7:12" is news only that morning */
    private const STALE_HOURS = 6;

    public static function on(): bool
    {
        return (string) setting('sms_enabled') === '1';
    }

    public static function provider(): string
    {
        $provider = (string) setting('sms_provider');
        return isset(self::PROVIDERS[$provider]) ? $provider : 'test';
    }

    public static function testMode(): bool
    {
        return self::provider() === 'test';
    }

    public static function apiKey(): string
    {
        return trim((string) setting('sms_api_key'));
    }

    /** @return list<string> events that are texted */
    public static function events(): array
    {
        $saved = settings_cache()['sms_events'] ?? null; // never saved: the default; saved empty: none
        $list = explode(',', $saved === null ? self::DEFAULT_EVENTS : (string) $saved);
        return array_values(array_intersect(array_keys(self::EVENTS), array_map('trim', $list)));
    }

    public static function sends(string $event): bool
    {
        return self::on() && in_array($event, self::events(), true) && (self::testMode() || self::apiKey() !== '');
    }

    /** 0 = no limit */
    public static function monthlyLimit(): int
    {
        return max(0, (int) setting('sms_monthly_limit'));
    }

    // ------------------------------------------------------------ numbers and text

    /** A Philippine mobile number as 639XXXXXXXXX, or null. Accepts 0917…, +63 917…, 63917…, 917… */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if (preg_match('/^09\d{9}$/', $digits)) {
            return '63' . substr($digits, 1);
        }
        if (preg_match('/^639\d{9}$/', $digits)) {
            return $digits;
        }
        if (preg_match('/^9\d{9}$/', $digits)) {
            return '63' . $digits;
        }
        return null;
    }

    /** The first mobile number written in a text such as "0917 123 4567 / 0918…" (the learner record's contact). */
    public static function firstNumber(?string $text): ?string
    {
        if (preg_match('/(?:\+?63|0)?\s*9\d{2}[\s.-]*\d{3}[\s.-]*\d{4}/', (string) $text, $m)) {
            return self::normalize($m[0]);
        }
        return null;
    }

    /** 0917 123 4567 */
    public static function display(string $number): string
    {
        return preg_match('/^63(9\d{2})(\d{3})(\d{4})$/', $number, $m) ? "0{$m[1]} {$m[2]} {$m[3]}" : $number;
    }

    /**
     * The text as it is sent: plain letters (so one text is 160 characters, not 70), the school's name in front,
     * at most 160 characters. Semaphore silently drops texts that start with "TEST", so none does.
     */
    public static function compose(string $text): string
    {
        $label = trim((string) setting('sms_label')) ?: mb_substr((string) setting('school_name'), 0, 30);
        $message = self::plain(($label !== '' ? $label . ': ' : '') . $text);
        if (preg_match('/^\W*test/i', $message)) {
            $message = 'From ' . $message;
        }
        return strlen($message) > 160 ? rtrim(substr($message, 0, 157)) . '...' : $message;
    }

    /** ASCII only: accents removed (Peña → Pena), other characters dropped. */
    public static function plain(string $text): string
    {
        $text = strtr($text, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ñ' => 'n', 'Ñ' => 'N', 'ç' => 'c', 'Ç' => 'C', '—' => '-', '–' => '-', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '·' => '-',
        ]);
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7E]/', '', $text)));
    }

    // ------------------------------------------------------------ queueing

    /**
     * Queues a text about a learner to their parents' mobile numbers (parent accounts that sign in with a mobile
     * number and may see attendance) and, when the school allows it, the contact number on the learner's record.
     * The same event is texted once per number ($dedupe, e.g. "gate_in:15:2026-11-27").
     * @return int texts queued
     */
    public static function forLearner(array $student, string $event, string $text, string $dedupe): int
    {
        if (!self::sends($event)) {
            return 0;
        }
        $studentId = (int) $student['id'];
        $numbers = []; // number => parent id (null for the record's contact number)
        $blocked = [];
        $parents = db_all(
            'SELECT p.id, p.login, (' . Access::parentAllowedSql('attendance', 'p.id') . ') AS allowed
               FROM parent_students ps JOIN parents p ON p.id = ps.parent_id
              WHERE ps.student_id = ? AND p.is_active = 1 AND p.workspace_id = ?',
            [$studentId, ws()]
        );
        foreach ($parents as $parent) {
            $number = self::normalize($parent['login']);
            if ($number === null) {
                continue;
            }
            if ((int) $parent['allowed'] === 1) {
                $numbers[$number] ??= (int) $parent['id'];
            } else {
                $blocked[$number] = true; // this parent's attendance alerts were turned off by the school
            }
        }
        if ((string) setting('sms_to_contact') !== '0') {
            $contact = self::firstNumber(db_value('SELECT contact_number FROM students WHERE id = ?', [$studentId]));
            if ($contact !== null && !isset($numbers[$contact]) && !isset($blocked[$contact])) {
                $numbers[$contact] = null;
            }
        }
        $queued = 0;
        $message = self::compose($text);
        foreach ($numbers as $number => $parentId) {
            $queued += self::queue($studentId, $parentId, (string) $number, $event, $message, $dedupe . ':' . $number) ? 1 : 0;
        }
        if ($queued) {
            Alerts::kick();
        }
        return $queued;
    }

    /** Adds one text to the queue. False when the same text (dedupe key) was already queued. */
    public static function queue(?int $studentId, ?int $parentId, string $number, string $event, string $message, ?string $dedupe): bool
    {
        return db_exec(
            'INSERT IGNORE INTO sms_messages (workspace_id, student_id, parent_id, number, message, event, dedupe_key, test)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [ws(), $studentId, $parentId, $number, mb_substr($message, 0, 480), $event, $dedupe !== null ? mb_substr($dedupe, 0, 120) : null, self::testMode() ? 1 : 0]
        ) > 0;
    }

    // ------------------------------------------------------------ sending

    /**
     * Sends queued texts of every school until $deadline (microtime). Each text is sent with its own school's
     * settings. @return int texts handled
     */
    public static function flush(float $deadline, ?int $workspaceId = null): int
    {
        // A text left "sending" by a request that stopped half-way goes back to the queue
        db_exec(
            "UPDATE sms_messages SET status = 'queued' WHERE status = 'sending' AND claimed_at < ? AND attempts < ?",
            [date('Y-m-d H:i:s', time() - 600), self::MAX_ATTEMPTS]
        );
        $handled = 0;
        while (microtime(true) < $deadline) {
            $rows = db_all(
                "SELECT id, workspace_id FROM sms_messages
                  WHERE status = 'queued' AND (next_try_at IS NULL OR next_try_at <= ?)" . ($workspaceId ? ' AND workspace_id = ' . (int) $workspaceId : '') . '
                  ORDER BY id LIMIT 20',
                [date('Y-m-d H:i:s')]
            );
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }
                // Claim it, so two senders running at once never send the same text twice
                $claimed = db_exec(
                    "UPDATE sms_messages SET status = 'sending', attempts = attempts + 1, claimed_at = ? WHERE id = ? AND status = 'queued'",
                    [date('Y-m-d H:i:s'), $row['id']]
                );
                if ($claimed) {
                    Workspace::within((int) $row['workspace_id'], static fn () => self::deliver((int) $row['id']));
                    $handled++;
                }
            }
        }
        return $handled;
    }

    /** Sends one claimed text with the current school's settings and records the outcome. */
    private static function deliver(int $id): void
    {
        $sms = db_one('SELECT * FROM sms_messages WHERE id = ?', [$id]);
        if (!$sms) {
            return;
        }
        $finish = static function (string $status, ?string $error = null, ?string $ref = null) use ($id): void {
            db_exec(
                'UPDATE sms_messages SET status = ?, error = ?, provider_ref = ?, sent_at = IF(? = \'sent\', ?, sent_at) WHERE id = ?',
                [$status, $error !== null ? mb_substr($error, 0, 255) : null, $ref, $status, date('Y-m-d H:i:s'), $id]
            );
        };
        $skip = match (true) {
            !self::on() => 'Text messages were turned off before this was sent.',
            strtotime($sms['created_at']) < time() - self::STALE_HOURS * 3600 => 'Not sent in time (more than ' . self::STALE_HOURS . ' hours late), so it was dropped.',
            self::monthlyLimit() > 0 && self::sentThisMonth() >= self::monthlyLimit() => 'The monthly limit of ' . self::monthlyLimit() . ' texts was reached.',
            default => null,
        };
        if ($skip !== null) {
            $finish('skipped', $skip);
            return;
        }
        if ((int) $sms['test'] === 1 || self::testMode()) {
            db_exec('UPDATE sms_messages SET test = 1 WHERE id = ?', [$id]);
            $finish('sent', null, 'test');
            return;
        }
        $result = self::sendNow($sms['number'], $sms['message']);
        if ($result['ok']) {
            $finish('sent', null, $result['ref']);
        } elseif ((int) $sms['attempts'] < self::MAX_ATTEMPTS && $result['retry']) {
            db_exec(
                "UPDATE sms_messages SET status = 'queued', error = ?, next_try_at = ? WHERE id = ?",
                [mb_substr((string) $result['error'], 0, 255), date('Y-m-d H:i:s', time() + 120 * (int) $sms['attempts']), $id]
            );
        } else {
            $finish('failed', $result['error']);
        }
    }

    public static function sentThisMonth(): int
    {
        return (int) db_value(
            "SELECT COUNT(*) FROM sms_messages WHERE workspace_id = ? AND status = 'sent' AND test = 0 AND sent_at >= ?",
            [ws(), date('Y-m-01 00:00:00')]
        );
    }

    /**
     * Sends one text now with the school's provider.
     * @return array{ok: bool, ref: ?string, error: ?string, retry: bool}
     */
    public static function sendNow(string $number, string $message): array
    {
        if (self::testMode()) {
            return ['ok' => true, 'ref' => 'test', 'error' => null, 'retry' => false];
        }
        $key = self::apiKey();
        if ($key === '') {
            return ['ok' => false, 'ref' => null, 'error' => 'No API key is saved.', 'retry' => false];
        }
        $fields = ['apikey' => $key, 'number' => $number, 'message' => $message];
        if (($sender = trim((string) setting('sms_sender'))) !== '') {
            $fields['sendername'] = $sender;
        }
        [$status, $body] = self::http('POST', self::SEMAPHORE . 'messages', $fields);
        $data = json_decode($body, true);
        $first = is_array($data) && array_is_list($data) ? ($data[0] ?? null) : null;
        if ($status >= 200 && $status < 300 && is_array($first) && isset($first['message_id'])) {
            if (strcasecmp((string) ($first['status'] ?? ''), 'Failed') === 0) {
                return ['ok' => false, 'ref' => (string) $first['message_id'], 'error' => 'The network rejected the text.', 'retry' => false];
            }
            return ['ok' => true, 'ref' => (string) $first['message_id'], 'error' => null, 'retry' => false];
        }
        return ['ok' => false, 'ref' => null, 'error' => self::explain($status, $data, $body, $key), 'retry' => $status === 0 || $status === 429 || $status >= 500];
    }

    /**
     * The SMS account's credits.
     * @return array{ok: bool, credits: ?float, account: ?string, error: ?string}
     */
    public static function balance(): array
    {
        if (self::testMode()) {
            return ['ok' => false, 'credits' => null, 'account' => null, 'error' => 'Test mode has no account. Choose Semaphore and save your API key first.'];
        }
        $key = self::apiKey();
        if ($key === '') {
            return ['ok' => false, 'credits' => null, 'account' => null, 'error' => 'No API key is saved.'];
        }
        [$status, $body] = self::http('GET', self::SEMAPHORE . 'account?' . http_build_query(['apikey' => $key]));
        $data = json_decode($body, true);
        if ($status === 200 && is_array($data) && isset($data['credit_balance'])) {
            return ['ok' => true, 'credits' => (float) $data['credit_balance'], 'account' => (string) ($data['account_name'] ?? ''), 'error' => null];
        }
        return ['ok' => false, 'credits' => null, 'account' => null, 'error' => self::explain($status, $data, $body, $key)];
    }

    /** The provider's answer in plain words, never including the API key. */
    private static function explain(int $status, mixed $data, string $body, string $key): string
    {
        $detail = '';
        if (is_array($data)) {
            $flat = [];
            array_walk_recursive($data, static function ($value) use (&$flat): void {
                if (is_scalar($value)) {
                    $flat[] = (string) $value;
                }
            });
            $detail = implode(' ', $flat);
        } elseif (trim(strip_tags($body)) !== '') {
            $detail = trim(strip_tags($body));
        }
        $detail = mb_substr(str_replace($key, '***', $detail), 0, 160);
        return match (true) {
            $status === 0 => 'Could not reach the SMS provider (no internet on the server, or it is down).',
            $status === 401 || $status === 403 => 'The SMS provider did not accept the API key.' . ($detail !== '' ? " ({$detail})" : ''),
            $status === 429 => 'The SMS provider is busy (too many requests). Try again in a minute; waiting texts are tried again by themselves.',
            str_contains(strtolower($detail), 'apikey') => 'The SMS provider did not accept the API key' . ($detail !== '' ? " ({$detail})" : '.'),
            $status >= 200 && $status < 300 => 'The SMS provider did not send it' . ($detail !== '' ? ": {$detail}" : '.'),
            default => 'The SMS provider answered with an error' . ($status ? " ({$status})" : '') . ($detail !== '' ? ": {$detail}" : '.'),
        };
    }

    /** @return array{0: int, 1: string} HTTP status (0 when unreachable) and body */
    private static function http(string $method, string $url, array $fields = []): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = curl_exec($curl);
        $status = $body === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        return [$status, is_string($body) ? $body : ''];
    }
}
