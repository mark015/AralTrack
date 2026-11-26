<?php
declare(strict_types=1);

/**
 * Attendance per subject class. A teacher starts a session when the class begins, scans
 * learners' QR cards (or marks them by hand) and presses Finish. Learners not marked by
 * then are Absent. Sessions left open are finished automatically after their day ends.
 */
final class Attendance
{
    /** Today's unfinished session for a class, if there is one. */
    public static function openSessionFor(int $classId): ?array
    {
        return db_one(
            'SELECT * FROM class_sessions WHERE class_assignment_id = ? AND session_date = ? AND finished_at IS NULL ORDER BY id DESC LIMIT 1',
            [$classId, date('Y-m-d')]
        );
    }

    /** Starts today's session (or returns the one already open). $time is "HH:MM". */
    public static function start(array $class, string $time, int $userId): int
    {
        if ($open = self::openSessionFor((int) $class['id'])) {
            return (int) $open['id'];
        }
        $today = date('Y-m-d');
        db_exec(
            'INSERT INTO class_sessions (workspace_id, class_assignment_id, session_date, starts_at, opened_by) VALUES (?, ?, ?, ?, ?)',
            [ws(), $class['id'], $today, "{$today} {$time}:00", $userId]
        );
        return (int) db()->lastInsertId();
    }

    /** A session with its class details under 'class', or null. */
    public static function session(int $sessionId): ?array
    {
        $session = db_one('SELECT * FROM class_sessions WHERE id = ? AND workspace_id = ?', [$sessionId, ws()]);
        $class = $session ? class_info((int) $session['class_assignment_id']) : null;
        return $class ? $session + ['class' => $class] : null;
    }

    /** @return array<int, array> student_id => attendance row */
    public static function marks(int $sessionId): array
    {
        $marks = [];
        foreach (db_all('SELECT * FROM session_attendance WHERE session_id = ?', [$sessionId]) as $row) {
            $marks[(int) $row['student_id']] = $row;
        }
        return $marks;
    }

    /** Status counts for a session, plus 'pending' (not marked yet) and 'total' (learners in the class). */
    public static function counts(array $session): array
    {
        $sectionId = $session['class']['section_id'];
        $counts = array_fill_keys(array_keys(ATTENDANCE_STATUSES), 0);
        $rows = db_all(
            'SELECT sa.status, COUNT(*) AS n FROM session_attendance sa
               JOIN enrollments e ON e.student_id = sa.student_id AND e.section_id = ?
              WHERE sa.session_id = ? GROUP BY sa.status',
            [$sectionId, $session['id']]
        );
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        $total = (int) db_value('SELECT COUNT(*) FROM enrollments WHERE section_id = ?', [$sectionId]);
        $counts['pending'] = max(0, $total - array_sum($counts));
        $counts['total'] = $total;
        return $counts;
    }

    /** Scans after this moment (Unix time) are Late. */
    public static function lateAfter(array $session): int
    {
        return strtotime($session['starts_at']) + (int) setting('late_after_minutes') * 60;
    }

    /** @return array{kind: string, accepted: bool, message: string, student?: array} */
    public static function scan(array $session, string $scanned, int $userId): array
    {
        $log = static function (string $result, ?int $studentId = null) use ($session, $scanned, $userId): void {
            db_exec(
                'INSERT INTO scan_log (session_id, user_id, code, student_id, result) VALUES (?, ?, ?, ?, ?)',
                [$session['id'], $userId, mb_substr(trim($scanned), 0, 100), $studentId, $result]
            );
        };

        if ($session['finished_at'] !== null) {
            $log('finished');
            return ['kind' => 'error', 'accepted' => false, 'message' => 'Attendance for this class is already finished.'];
        }
        $token = token_from_scan($scanned);
        $student = $token !== null ? db_one('SELECT * FROM students WHERE qr_token = ? AND workspace_id = ?', [$token, ws()]) : null;
        if (!$student) {
            $log('unknown');
            return ['kind' => 'error', 'accepted' => false, 'message' => 'Unknown QR code. It may be an old card that was replaced, or not a learner ID.'];
        }
        $studentId = (int) $student['id'];
        if (!db_value('SELECT 1 FROM enrollments WHERE student_id = ? AND section_id = ?', [$studentId, $session['class']['section_id']])) {
            $log('not_in_class', $studentId);
            return ['kind' => 'error', 'accepted' => false, 'message' => student_name($student) . ' is not in this class.'];
        }

        $current = self::markRow((int) $session['id'], $studentId);
        if ($current && in_array($current['status'], ['present', 'late'], true)) {
            $log('duplicate', $studentId);
            return [
                'kind' => 'info', 'accepted' => false,
                'message' => student_name($student) . ' is already marked ' . strtolower(ATTENDANCE_STATUSES[$current['status']]) . ' (' . format_time($current['marked_at']) . ').',
                'student' => self::rowForDisplay($student, $current),
            ];
        }

        $now = time();
        $status = $now > self::lateAfter($session) ? 'late' : 'present';
        db_exec(
            'INSERT INTO session_attendance (session_id, student_id, status, source, marked_at, note, recorded_by)
             VALUES (?, ?, ?, ?, ?, NULL, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), source = VALUES(source), marked_at = VALUES(marked_at),
                                     note = NULL, recorded_by = VALUES(recorded_by)',
            [$session['id'], $studentId, $status, 'scan', date('Y-m-d H:i:s', $now), $userId]
        );
        if ($status === 'late') {
            Notifications::late($student, $session, $now);
        }
        $log('ok', $studentId);

        return [
            'kind' => $status === 'late' ? 'warning' : 'success',
            'accepted' => true,
            'message' => student_name($student) . ' — ' . ATTENDANCE_STATUSES[$status] . ' at ' . date('g:i A', $now),
            'student' => self::rowForDisplay($student, self::markRow((int) $session['id'], $studentId)),
        ];
    }

    /**
     * A teacher sets a learner's mark by hand (no card, a correction, or an excused absence).
     * $notify = false skips the parent notification (used when an excuse letter is approved,
     * which sends one summary notification instead).
     */
    public static function mark(array $session, int $studentId, string $status, string $note, int $userId, bool $notify = true): array
    {
        if (!isset(ATTENDANCE_STATUSES[$status])) {
            return ['kind' => 'error', 'accepted' => false, 'message' => 'Choose Present, Late, Absent or Excused.'];
        }
        $student = db_one(
            'SELECT st.* FROM students st JOIN enrollments e ON e.student_id = st.id WHERE st.id = ? AND e.section_id = ?',
            [$studentId, $session['class']['section_id']]
        );
        if (!$student) {
            return ['kind' => 'error', 'accepted' => false, 'message' => 'That learner is not in this class.'];
        }
        $note = mb_substr(trim($note), 0, 255);
        $current = self::markRow((int) $session['id'], $studentId);
        $old = $current['status'] ?? null;

        if ($current && $old === $status) {
            if ((string) $current['note'] !== $note) {
                db_exec('UPDATE session_attendance SET note = ?, recorded_by = ? WHERE session_id = ? AND student_id = ?', [$note ?: null, $userId, $session['id'], $studentId]);
            }
        } else {
            db_exec(
                'INSERT INTO session_attendance (session_id, student_id, status, source, marked_at, note, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), source = VALUES(source), marked_at = VALUES(marked_at),
                                         note = VALUES(note), recorded_by = VALUES(recorded_by)',
                [$session['id'], $studentId, $status, 'manual', date('Y-m-d H:i:s'), $note ?: null, $userId]
            );
            if (!$notify) {
                // the caller notifies
            } elseif ($session['finished_at'] !== null) {
                Notifications::changed($student, $session, $old, $status, $note);
            } elseif ($status === 'late') {
                Notifications::late($student, $session, time());
            }
        }

        return [
            'kind' => 'success', 'accepted' => true,
            'message' => student_name($student) . ' marked ' . strtolower(ATTENDANCE_STATUSES[$status]) . '.',
            'student' => self::rowForDisplay($student, self::markRow((int) $session['id'], $studentId)),
        ];
    }

    /**
     * Finishes a session: everyone not marked yet becomes Absent, and their parents are notified.
     * Returns the number of learners marked absent (0 if it was already finished).
     */
    public static function finish(array $session, ?int $userId, ?string $finishedAt = null): int
    {
        $finishedAt ??= date('Y-m-d H:i:s');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (db_exec('UPDATE class_sessions SET finished_at = ? WHERE id = ? AND finished_at IS NULL', [$finishedAt, $session['id']]) === 0) {
                $pdo->rollBack();
                return 0; // someone else finished it already
            }
            db_exec(
                "INSERT INTO session_attendance (session_id, student_id, status, source, marked_at, recorded_by)
                 SELECT ?, e.student_id, 'absent', 'auto', ?, ? FROM enrollments e
                  WHERE e.section_id = ?
                    AND NOT EXISTS (SELECT 1 FROM session_attendance sa WHERE sa.session_id = ? AND sa.student_id = e.student_id)",
                [$session['id'], $finishedAt, $userId, $session['class']['section_id'], $session['id']]
            );
            // Absences covered by an approved excuse letter are Excused, not Absent.
            db_exec(
                "UPDATE session_attendance sa
                   JOIN excuse_letters el ON el.student_id = sa.student_id AND el.status = 'approved'
                                         AND ? BETWEEN el.date_from AND el.date_to
                    SET sa.status = 'excused', sa.note = LEFT(CONCAT('Excuse letter: ', el.reason), 255)
                  WHERE sa.session_id = ? AND sa.status = 'absent'",
                [$session['session_date'], $session['id']]
            );
            Notifications::absences($session);
            $absent = (int) db_value("SELECT COUNT(*) FROM session_attendance WHERE session_id = ? AND status = 'absent'", [$session['id']]);
            $pdo->commit();
            return $absent;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Finishes sessions from earlier days that the teacher left open. Runs on every page load (cheap). */
    public static function finishStaleSessions(): void
    {
        $stale = db_all('SELECT id, workspace_id, session_date FROM class_sessions WHERE finished_at IS NULL AND session_date < ?', [date('Y-m-d')]);
        foreach ($stale as $row) {
            // Each school's session is finished inside that school (its settings and parent notices)
            Workspace::within((int) $row['workspace_id'], static function () use ($row): void {
                if ($session = self::session((int) $row['id'])) {
                    self::finish($session, null, $row['session_date'] . ' 23:59:59');
                }
            });
        }
    }

    /** @return array<int, array<string, int>> student_id => [status => count], finished sessions of one class */
    public static function classTotals(int $classId): array
    {
        $totals = [];
        $rows = db_all(
            'SELECT sa.student_id, sa.status, COUNT(*) AS n
               FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
              WHERE cs.class_assignment_id = ? AND cs.finished_at IS NOT NULL
              GROUP BY sa.student_id, sa.status',
            [$classId]
        );
        foreach ($rows as $row) {
            $totals[(int) $row['student_id']][$row['status']] = (int) $row['n'];
        }
        return $totals;
    }

    /**
     * One learner's totals for each class of a section (finished sessions only).
     * @return array<int, array{held: int, present: int, late: int, absent: int, excused: int}> class id => totals
     */
    public static function studentTotals(int $studentId, int $sectionId): array
    {
        $totals = [];
        $held = db_all(
            'SELECT cs.class_assignment_id, COUNT(*) AS n FROM class_sessions cs
               JOIN class_assignments ca ON ca.id = cs.class_assignment_id
              WHERE ca.section_id = ? AND cs.finished_at IS NOT NULL GROUP BY cs.class_assignment_id',
            [$sectionId]
        );
        foreach ($held as $row) {
            $totals[(int) $row['class_assignment_id']] = ['held' => (int) $row['n']] + array_fill_keys(array_keys(ATTENDANCE_STATUSES), 0);
        }
        $marks = db_all(
            'SELECT cs.class_assignment_id, sa.status, COUNT(*) AS n
               FROM session_attendance sa
               JOIN class_sessions cs     ON cs.id = sa.session_id
               JOIN class_assignments ca  ON ca.id = cs.class_assignment_id
              WHERE sa.student_id = ? AND ca.section_id = ? AND cs.finished_at IS NOT NULL
              GROUP BY cs.class_assignment_id, sa.status',
            [$studentId, $sectionId]
        );
        foreach ($marks as $row) {
            $totals[(int) $row['class_assignment_id']][$row['status']] = (int) $row['n'];
        }
        return $totals;
    }

    /** One learner's class meetings between two dates, with their mark (null while not marked yet). */
    public static function studentSessions(int $studentId, int $sectionId, string $from, string $to): array
    {
        return db_all(
            'SELECT cs.id, cs.session_date, cs.starts_at, cs.finished_at, sub.name AS subject_name,
                    sa.status, sa.source, sa.marked_at, sa.note
               FROM class_sessions cs
               JOIN class_assignments ca ON ca.id = cs.class_assignment_id
               JOIN subjects sub         ON sub.id = ca.subject_id
               LEFT JOIN session_attendance sa ON sa.session_id = cs.id AND sa.student_id = ?
              WHERE ca.section_id = ? AND cs.session_date BETWEEN ? AND ?
              ORDER BY cs.starts_at DESC',
            [$studentId, $sectionId, $from, $to]
        );
    }

    // ------------------------------------------------------------ offline attendance (teacher's phone without internet)

    /**
     * The session a phone recorded offline: the one it already belongs to (same client id, or the session id
     * it was started from), today's session still open for the class, or a new one with the phone's start time.
     */
    public static function sessionForOffline(array $class, ?int $serverSessionId, string $clientUuid, string $date, string $time, int $userId): array
    {
        if ($serverSessionId) {
            $session = self::session($serverSessionId);
            if (!$session || (int) $session['class_assignment_id'] !== (int) $class['id']) {
                throw new UserFacingException('That attendance session does not belong to this class.');
            }
            return $session;
        }
        $id = db_value('SELECT id FROM class_sessions WHERE client_uuid = ? AND class_assignment_id = ? AND workspace_id = ?', [$clientUuid, $class['id'], ws()]);
        if ($id) {
            return self::session((int) $id);
        }
        $open = db_one(
            'SELECT id FROM class_sessions WHERE class_assignment_id = ? AND session_date = ? AND finished_at IS NULL ORDER BY id DESC LIMIT 1',
            [$class['id'], $date]
        );
        if ($open) {
            db_exec('UPDATE class_sessions SET client_uuid = COALESCE(client_uuid, ?) WHERE id = ?', [$clientUuid, $open['id']]);
            return self::session((int) $open['id']);
        }
        db_exec(
            'INSERT INTO class_sessions (workspace_id, class_assignment_id, session_date, starts_at, opened_by, client_uuid) VALUES (?, ?, ?, ?, ?, ?)',
            [ws(), $class['id'], $date, "{$date} {$time}", $userId, $clientUuid]
        );
        return self::session((int) db()->lastInsertId());
    }

    /**
     * A card scanned on a phone while offline, at the phone's time. Present or Late is worked out from that
     * time, like a live scan. A learner already marked present or late keeps that mark.
     * @return string saved | late | duplicate | not_in_class | finished
     */
    public static function offlineScan(array $session, int $studentId, int $scannedAt, int $userId): string
    {
        $student = db_one(
            'SELECT st.* FROM students st JOIN enrollments e ON e.student_id = st.id WHERE st.id = ? AND e.section_id = ?',
            [$studentId, $session['class']['section_id']]
        );
        if (!$student) {
            return 'not_in_class';
        }
        $current = self::markRow((int) $session['id'], $studentId);
        if ($current && in_array($current['status'], ['present', 'late'], true)) {
            return 'duplicate';
        }
        if ($current && $session['finished_at'] !== null) {
            return 'finished'; // already marked absent/excused after finishing: a teacher corrects that by hand
        }
        $status = $scannedAt > self::lateAfter($session) ? 'late' : 'present';
        db_exec(
            'INSERT INTO session_attendance (session_id, student_id, status, source, marked_at, note, recorded_by)
             VALUES (?, ?, ?, ?, ?, NULL, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), source = VALUES(source), marked_at = VALUES(marked_at),
                                     note = NULL, recorded_by = VALUES(recorded_by)',
            [$session['id'], $studentId, $status, 'scan', date('Y-m-d H:i:s', $scannedAt), $userId]
        );
        db_exec(
            'INSERT INTO scan_log (session_id, user_id, code, student_id, result) VALUES (?, ?, ?, ?, ?)',
            [$session['id'], $userId, 'offline', $studentId, 'ok']
        );
        if ($status === 'late') {
            Notifications::late($student, $session, $scannedAt);
        }
        return $status === 'late' ? 'late' : 'saved';
    }

    private static function markRow(int $sessionId, int $studentId): ?array
    {
        return db_one('SELECT * FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessionId, $studentId]);
    }

    /** What the scanner page needs to update one learner's row. */
    private static function rowForDisplay(array $student, ?array $mark): array
    {
        return [
            'id'     => (int) $student['id'],
            'name'   => student_name($student),
            'status' => $mark['status'] ?? null,
            'label'  => ATTENDANCE_STATUSES[$mark['status'] ?? ''] ?? 'Not yet',
            'time'   => $mark && $mark['source'] === 'scan' ? format_time($mark['marked_at']) : '',
            'note'   => (string) ($mark['note'] ?? ''),
            // The photo lets the teacher check that the person holding the card is the learner.
            'photo'  => !empty($student['photo_path']) ? url('file.php?kind=photo&id=' . (int) $student['id']) : null,
        ];
    }
}
