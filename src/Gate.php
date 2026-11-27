<?php
declare(strict_types=1);

/**
 * Gate attendance: learners scan their ID card at the school entrance when they arrive and when they leave,
 * on a phone or tablet run by the guard (gate/index.php). Each scan is saved in gate_logs and tells the parents
 * (a portal notification, a phone alert, and a text message when the school turned SMS on).
 *
 * This is separate from class attendance: being at school doesn't mark a learner present in a class.
 * Schools only (a teacher on their own has no gate).
 */
final class Gate
{
    public const MODES = ['auto' => 'Auto', 'in' => 'Arriving', 'out' => 'Leaving'];
    public const SOURCES = ['scan' => 'Card', 'offline' => 'Card (saved offline)', 'manual' => 'Typed LRN'];

    /** The same card again within this time is ignored (cameras read a card many times a second) */
    private const REPEAT_SECONDS = 120;
    /** In Auto mode, a second scan counts as leaving only this long after arriving */
    private const AUTO_OUT_MINUTES = 30;
    /** Scans saved on a phone without internet are accepted for this many days */
    private const OFFLINE_MAX_DAYS = 3;
    /** A scan that reaches the server later than this (saved offline) is not texted any more */
    private const SMS_MAX_DELAY = 7200;

    public static function available(): bool
    {
        return Workspace::known() && !Workspace::isSolo() && Access::on('gate');
    }

    /** Stops a gate page when the gate can't be used here. */
    public static function requireAvailable(): void
    {
        if (Workspace::isSolo()) {
            Access::stopPage('Gate attendance is for schools', 'A teacher account has no school gate. Your classes still have their own QR attendance.');
        }
        Access::requireModule('gate');
    }

    /** Who may run the gate scanner */
    public static function mayScan(array $staff): bool
    {
        return in_array($staff['role'], ['superadmin', 'admin', 'principal', 'guard'], true);
    }

    /** Section ids whose gate log this staff member may see; null = every section. Empty = none. */
    public static function visibleSections(array $staff): ?array
    {
        if (sees_all_learners($staff) || $staff['role'] === 'guard') {
            return null;
        }
        return advised_section_ids($staff);
    }

    /** "07:30", or null when arrivals are never marked late */
    public static function lateAfter(): ?string
    {
        $time = trim((string) setting('gate_late_after'));
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : null;
    }

    // ------------------------------------------------------------ scanning

    /** The learner of a scanned card, or of a typed 12-digit LRN. */
    public static function learnerFor(string $scanned): ?array
    {
        $token = token_from_scan($scanned);
        if ($token !== null) {
            return db_one('SELECT * FROM students WHERE qr_token = ? AND workspace_id = ?', [$token, ws()]);
        }
        $digits = (string) preg_replace('/\D+/', '', $scanned);
        return strlen($digits) === 12 ? db_one('SELECT * FROM students WHERE lrn = ? AND workspace_id = ?', [$digits, ws()]) : null;
    }

    /** A card scanned (or LRN typed) at the gate now. */
    public static function scan(string $scanned, string $mode, int $userId): array
    {
        $student = self::learnerFor($scanned);
        if (!$student) {
            return ['kind' => 'error', 'accepted' => false,
                'message' => token_from_scan($scanned) !== null
                    ? 'Unknown card. It may be an old card that was replaced, or a card from another school.'
                    : 'Not a learner ID card. Scan the QR code, or type the learner\'s 12-digit LRN.'];
        }
        return self::record($student, $mode, time(), $userId, token_from_scan($scanned) !== null ? 'scan' : 'manual');
    }

    /**
     * Records a learner passing the gate at $at (Unix time).
     * $mode: auto (arriving, or leaving if they already came in), in or out.
     * @return array{kind: string, accepted: bool, message: string, direction?: string, student?: array}
     */
    public static function record(array $student, string $mode, int $at, int $userId, string $source, ?string $clientUuid = null): array
    {
        $mode = isset(self::MODES[$mode]) ? $mode : 'auto';
        $studentId = (int) $student['id'];
        $section = current_section_of($studentId);
        $name = student_name($student);
        if (!$section || $student['status'] !== 'active') {
            return ['kind' => 'error', 'accepted' => false, 'message' => $name . ' is not enrolled this school year.', 'student' => self::display($student, null)];
        }
        $date = date('Y-m-d', $at);
        $moment = date('Y-m-d H:i:s', $at);
        $last = db_one(
            'SELECT * FROM gate_logs WHERE student_id = ? AND scanned_at BETWEEN ? AND ? ORDER BY scanned_at DESC, id DESC LIMIT 1',
            [$studentId, "{$date} 00:00:00", $moment]
        );
        $since = $last ? $at - strtotime($last['scanned_at']) : null;
        $display = self::display($student, $section);
        $lastTime = $last ? format_time($last['scanned_at']) : '';
        $lastWord = $last && $last['direction'] === 'in' ? 'arrived' : 'left';

        if ($last && $since < self::REPEAT_SECONDS) {
            return ['kind' => 'info', 'accepted' => false, 'message' => "{$name} already scanned: {$lastWord} at {$lastTime}.",
                'student' => $display + ['direction' => $last['direction'], 'time' => $lastTime]];
        }
        $direction = match ($mode) {
            'in' => 'in',
            'out' => 'out',
            default => $last && $last['direction'] === 'in' ? 'out' : 'in',
        };
        if ($last && $mode !== 'auto' && $last['direction'] === $direction) {
            return ['kind' => 'info', 'accepted' => false, 'message' => "{$name} already {$lastWord} at {$lastTime}.",
                'student' => $display + ['direction' => $last['direction'], 'time' => $lastTime]];
        }
        if ($mode === 'auto' && $direction === 'out' && $since < self::AUTO_OUT_MINUTES * 60) {
            $from = date('g:i A', strtotime($last['scanned_at']) + self::AUTO_OUT_MINUTES * 60);
            return ['kind' => 'info', 'accepted' => false,
                'message' => "{$name} arrived at {$lastTime}. In Auto, leaving is recorded from {$from}; to record it now, choose Leaving.",
                'student' => $display + ['direction' => 'in', 'time' => $lastTime]];
        }

        $firstIn = $direction === 'in' && !db_value(
            "SELECT 1 FROM gate_logs WHERE student_id = ? AND direction = 'in' AND scanned_at BETWEEN ? AND ? LIMIT 1",
            [$studentId, "{$date} 00:00:00", $moment]
        );
        $lateAfter = self::lateAfter();
        $late = $firstIn && $lateAfter !== null && $at > strtotime("{$date} {$lateAfter}:00");
        try {
            db_exec(
                'INSERT INTO gate_logs (workspace_id, student_id, direction, scanned_at, late, source, recorded_by, client_uuid) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [ws(), $studentId, $direction, $moment, $late ? 1 : 0, $source, $userId, $clientUuid]
            );
        } catch (PDOException $e) {
            if ($clientUuid !== null && is_duplicate_error($e)) {
                return ['kind' => 'info', 'accepted' => false, 'message' => 'Already saved.', 'saved' => true];
            }
            throw $e;
        }
        $logId = (int) db()->lastInsertId();

        $time = date('g:i A', $at);
        $first = $student['first_name'];
        $full = $student['first_name'] . ' ' . $student['last_name'];
        if ($direction === 'in') {
            $notice = match (true) {
                !$firstIn => "{$first} came back to school at {$time}.",
                $late => "{$first} arrived at school late, at {$time} (on time until " . date('g:i A', strtotime("{$date} {$lateAfter}:00")) . ').',
                default => "{$first} arrived at school at {$time}.",
            };
        } else {
            $notice = "{$first} left school at {$time}.";
        }
        Notifications::gate($student, $direction, $notice, $at);
        if ($at >= time() - self::SMS_MAX_DELAY) {
            $day = date('M j', $at);
            if ($direction === 'in' && $firstIn) {
                Sms::forLearner($student, 'gate_in', "{$full} arrived at school " . ($late ? 'LATE ' : '') . "at {$time}, {$day}.", "gate_in:{$studentId}:{$date}");
            } elseif ($direction === 'out') {
                Sms::forLearner($student, 'gate_out', "{$full} left school at {$time}, {$day}.", "gate_out:{$logId}");
            }
        }

        $word = $direction === 'in' ? ($firstIn ? 'Arrived' : 'Came back') : 'Left';
        return [
            'kind' => $late ? 'warning' : 'success',
            'accepted' => true,
            'direction' => $direction,
            'message' => "{$name} — {$word}" . ($late ? ' (late)' : '') . " at {$time}",
            'student' => $display + ['direction' => $direction, 'time' => $time, 'late' => $late],
        ];
    }

    /**
     * Scans saved on a phone without internet, sent when it is back. Each has the phone's id for it, so sending
     * the same scans twice saves them once.
     * @param list<array{uuid: string, studentId: int, at: int, mode: string}> $events
     * @return list<array{uuid: string, ok: bool, message: string}>
     */
    public static function sync(array $events, int $userId): array
    {
        usort($events, static fn ($a, $b) => ((int) ($a['at'] ?? 0)) <=> ((int) ($b['at'] ?? 0)));
        $results = [];
        foreach (array_slice($events, 0, 500) as $event) {
            $uuid = is_string($event['uuid'] ?? null) && preg_match('/^[\w-]{8,40}$/', $event['uuid']) ? $event['uuid'] : null;
            if ($uuid === null) {
                continue;
            }
            $at = (int) ($event['at'] ?? 0);
            $student = db_one('SELECT * FROM students WHERE id = ? AND workspace_id = ?', [(int) ($event['studentId'] ?? 0), ws()]);
            if ($at > time() + 300 || $at < time() - self::OFFLINE_MAX_DAYS * 86400) {
                $results[] = ['uuid' => $uuid, 'ok' => true, 'message' => 'Not saved: the phone\'s clock was wrong or the scan is too old.'];
                continue;
            }
            if (!$student) {
                $results[] = ['uuid' => $uuid, 'ok' => true, 'message' => 'Not saved: learner not found.'];
                continue;
            }
            if (db_value('SELECT 1 FROM gate_logs WHERE workspace_id = ? AND client_uuid = ?', [ws(), $uuid])) {
                $results[] = ['uuid' => $uuid, 'ok' => true, 'message' => 'Already saved.'];
                continue;
            }
            $result = self::record($student, (string) ($event['mode'] ?? 'auto'), min($at, time()), $userId, 'offline', $uuid);
            $results[] = ['uuid' => $uuid, 'ok' => true, 'message' => $result['message']];
        }
        return $results;
    }

    /** What the scanner shows about a learner. */
    private static function display(array $student, ?array $section): array
    {
        return [
            'id'      => (int) $student['id'],
            'name'    => student_name($student),
            'section' => $section ? section_label($section) : '',
            'photo'   => !empty($student['photo_path']) ? url('file.php?kind=photo&id=' . (int) $student['id']) : null,
        ];
    }

    // ------------------------------------------------------------ lists

    /**
     * Learners enrolled this school year, for recognising cards without internet: cards only as SHA-256
     * fingerprints (the card code itself stays on the server).
     * @return list<array{id: int, name: string, section: string, hash: string}>
     */
    public static function roster(): array
    {
        $rows = db_all(
            "SELECT st.id, st.last_name, st.first_name, st.middle_name, st.qr_token, sec.grade_level, sec.strand, sec.name AS section_name
               FROM enrollments e
               JOIN school_years sy ON sy.id = e.school_year_id AND sy.is_active = 1
               JOIN students st     ON st.id = e.student_id
               JOIN sections sec    ON sec.id = e.section_id
              WHERE st.workspace_id = ? AND st.status = 'active'
              ORDER BY st.last_name, st.first_name",
            [ws()]
        );
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => student_name($r),
            'section' => section_label($r),
            'hash' => hash('sha256', (string) $r['qr_token']),
        ], $rows);
    }

    /** SQL condition limiting learners (column $studentColumn) to some sections of the active school year. */
    private static function sectionFilter(?array $sectionIds, string $studentColumn): string
    {
        if ($sectionIds === null) {
            return '';
        }
        return " AND {$studentColumn} IN (SELECT e.student_id FROM enrollments e WHERE e.section_id IN (" . (implode(',', array_map('intval', $sectionIds)) ?: '0') . '))';
    }

    /** @return array{arrived: int, late: int, left: int, inside: int, enrolled: int} */
    public static function counts(string $date, ?array $sectionIds = null): array
    {
        $range = ["{$date} 00:00:00", "{$date} 23:59:59"];
        $filter = self::sectionFilter($sectionIds, 'g.student_id');
        $row = db_one(
            "SELECT COUNT(DISTINCT CASE WHEN g.direction = 'in' THEN g.student_id END) AS arrived,
                    COUNT(DISTINCT CASE WHEN g.late = 1 THEN g.student_id END) AS late,
                    COUNT(DISTINCT CASE WHEN g.direction = 'out' THEN g.student_id END) AS left_school
               FROM gate_logs g WHERE g.workspace_id = ? AND g.scanned_at BETWEEN ? AND ?{$filter}",
            [ws(), ...$range]
        );
        // Inside now: their last scan of the day was "in"
        $inside = (int) db_value(
            "SELECT COUNT(*) FROM gate_logs g
              WHERE g.workspace_id = ? AND g.direction = 'in' AND g.scanned_at BETWEEN ? AND ?{$filter}
                AND NOT EXISTS (SELECT 1 FROM gate_logs later WHERE later.student_id = g.student_id AND later.scanned_at BETWEEN ? AND ?
                                   AND (later.scanned_at > g.scanned_at OR (later.scanned_at = g.scanned_at AND later.id > g.id)))",
            [ws(), ...$range, ...$range]
        );
        $enrolled = (int) db_value(
            "SELECT COUNT(*) FROM enrollments e JOIN school_years sy ON sy.id = e.school_year_id AND sy.is_active = 1
               JOIN students st ON st.id = e.student_id
              WHERE st.workspace_id = ? AND st.status = 'active'" . self::sectionFilter($sectionIds, 'e.student_id'),
            [ws()]
        );
        return ['arrived' => (int) $row['arrived'], 'late' => (int) $row['late'], 'left' => (int) $row['left_school'], 'inside' => $inside, 'enrolled' => $enrolled];
    }

    /** Every scan of a day, newest first, with the learner's section this school year. */
    public static function day(string $date, ?array $sectionIds = null, string $search = '', int $limit = 1000): array
    {
        $params = [ws(), "{$date} 00:00:00", "{$date} 23:59:59"];
        $where = '';
        if ($search !== '') {
            $where = ' AND (st.last_name LIKE ? OR st.first_name LIKE ? OR st.lrn LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        return db_all(
            "SELECT g.*, st.lrn, st.last_name, st.first_name, st.middle_name, sec.grade_level, sec.strand, sec.name AS section_name, u.full_name AS recorded_by_name
               FROM gate_logs g
               JOIN students st ON st.id = g.student_id
               LEFT JOIN school_years sy ON sy.workspace_id = st.workspace_id AND sy.is_active = 1
               LEFT JOIN enrollments e   ON e.student_id = st.id AND e.school_year_id = sy.id
               LEFT JOIN sections sec    ON sec.id = e.section_id
               LEFT JOIN users u         ON u.id = g.recorded_by
              WHERE g.workspace_id = ? AND g.scanned_at BETWEEN ? AND ?" . self::sectionFilter($sectionIds, 'g.student_id') . $where . '
              ORDER BY g.scanned_at DESC, g.id DESC LIMIT ' . max(1, $limit),
            $params
        );
    }

    /** Learners enrolled this school year who have not come in through the gate that day. */
    public static function notArrived(string $date, ?array $sectionIds = null): array
    {
        return db_all(
            "SELECT st.id, st.lrn, st.last_name, st.first_name, st.middle_name, st.contact_number, sec.id AS section_id, sec.grade_level, sec.strand, sec.name AS section_name
               FROM enrollments e
               JOIN school_years sy ON sy.id = e.school_year_id AND sy.is_active = 1
               JOIN students st     ON st.id = e.student_id
               JOIN sections sec    ON sec.id = e.section_id
              WHERE st.workspace_id = ? AND st.status = 'active'" . self::sectionFilter($sectionIds, 'e.student_id') . "
                AND NOT EXISTS (SELECT 1 FROM gate_logs g WHERE g.student_id = st.id AND g.direction = 'in' AND g.scanned_at BETWEEN ? AND ?)
              ORDER BY sec.grade_level, sec.name, st.last_name, st.first_name",
            [ws(), "{$date} 00:00:00", "{$date} 23:59:59"]
        );
    }

    /** One learner's gate scans between two dates: date => scans (oldest first). */
    public static function forStudent(int $studentId, string $from, string $to): array
    {
        $days = [];
        foreach (db_all(
            'SELECT direction, scanned_at, late FROM gate_logs WHERE student_id = ? AND workspace_id = ? AND scanned_at BETWEEN ? AND ? ORDER BY scanned_at',
            [$studentId, ws(), "{$from} 00:00:00", "{$to} 23:59:59"]
        ) as $row) {
            $days[substr($row['scanned_at'], 0, 10)][] = $row;
        }
        return $days;
    }

    /** "In 7:12 AM (late) · Out 4:05 PM" */
    public static function summary(array $scans): string
    {
        return implode(' · ', array_map(
            static fn ($s) => ($s['direction'] === 'in' ? 'In ' : 'Out ') . format_time($s['scanned_at']) . ((int) $s['late'] ? ' (late)' : ''),
            $scans
        ));
    }
}
