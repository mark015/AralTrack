<?php
declare(strict_types=1);

/**
 * Notifications for parents: shown in the parent portal, and sent to the parent's phone as an alert when they
 * turned alerts on (Alerts, web push). Gate scans, lates and absences can also be texted (Sms), when the school
 * turned text messages on. Each one goes to every parent linked to the learner.
 */
final class Notifications
{
    public static function late(array $student, array $session, int $scannedAt): void
    {
        if (!Access::on('attendance')) {
            return;
        }
        $message = sprintf(
            '%s was late to %s: arrived %s, class started %s.',
            $student['first_name'],
            $session['class']['subject_name'],
            date('g:i A', $scannedAt),
            format_time($session['starts_at'])
        );
        self::toParents((int) $student['id'], 'late', $message, self::attendanceLink((int) $student['id'], $session['session_date']));
        Sms::forLearner($student, 'late', sprintf(
            '%s was late to %s: arrived %s (class started %s).',
            $student['first_name'] . ' ' . $student['last_name'],
            $session['class']['subject_name'],
            date('g:i A', $scannedAt),
            format_time($session['starts_at'])
        ), 'late:' . $session['id'] . ':' . $student['id']);
    }

    /** A learner scanned in or out at the school gate. */
    public static function gate(array $student, string $direction, string $message, int $at): void
    {
        if (!Access::on('gate')) {
            return;
        }
        self::toParents((int) $student['id'], $direction === 'in' ? 'arrived' : 'left', $message, self::attendanceLink((int) $student['id'], date('Y-m-d', $at)));
    }

    public static function changed(array $student, array $session, ?string $old, string $new, string $note): void
    {
        if (!Access::on('attendance')) {
            return;
        }
        $message = sprintf(
            "%s's attendance in %s on %s was changed from %s to %s.",
            $student['first_name'],
            $session['class']['subject_name'],
            date('M j', strtotime($session['session_date'])),
            ATTENDANCE_STATUSES[$old ?? ''] ?? 'not marked',
            ATTENDANCE_STATUSES[$new]
        );
        if ($note !== '') {
            $message .= ' Note: "' . $note . '"';
        }
        self::toParents((int) $student['id'], 'changed', mb_substr($message, 0, 500), self::attendanceLink((int) $student['id'], $session['session_date']));
    }

    /** One notification per absent learner in a finished session. */
    public static function absences(array $session): void
    {
        if (!Access::on('attendance')) {
            return;
        }
        db_exec(
            "INSERT INTO parent_notifications (parent_id, student_id, type, message, link)
             SELECT ps.parent_id, ps.student_id, 'absent',
                    CONCAT(st.first_name, ' was marked absent in ', ?, ' (', ?, ').'),
                    CONCAT('parent/child.php?id=', ps.student_id, '&month=', ?)
               FROM session_attendance sa
               JOIN parent_students ps ON ps.student_id = sa.student_id
               JOIN students st        ON st.id = sa.student_id
              WHERE sa.session_id = ? AND sa.status = 'absent' AND " . Access::parentAllowedSql('attendance', 'ps.parent_id'),
            [
                $session['class']['subject_name'],
                date('M j, g:i A', strtotime($session['starts_at'])),
                substr($session['session_date'], 0, 7),
                $session['id'],
            ]
        );
        Alerts::kick();
        if (Sms::sends('absent')) {
            $absent = db_all(
                "SELECT st.* FROM session_attendance sa JOIN students st ON st.id = sa.student_id WHERE sa.session_id = ? AND sa.status = 'absent'",
                [$session['id']]
            );
            foreach ($absent as $student) {
                Sms::forLearner($student, 'absent', sprintf(
                    '%s was marked absent in %s (%s).',
                    $student['first_name'] . ' ' . $student['last_name'],
                    $session['class']['subject_name'],
                    date('M j, g:i A', strtotime($session['starts_at']))
                ), 'absent:' . $session['id'] . ':' . $student['id']);
            }
        }
    }

    /** When a teacher publishes a quarter: parents of learners who have a grade in it. */
    public static function gradesReleased(array $class, int $quarter): void
    {
        if (!Access::parentsSeeGrades()) {
            return;
        }
        db_exec(
            "INSERT INTO parent_notifications (parent_id, student_id, type, message, link)
             SELECT ps.parent_id, ps.student_id, 'grades', ?, CONCAT('parent/child.php?tab=grades&id=', ps.student_id)
               FROM parent_students ps
               JOIN enrollments e ON e.student_id = ps.student_id AND e.section_id = ?
               JOIN grades g      ON g.student_id = ps.student_id AND g.class_assignment_id = ? AND g.quarter = ?
              WHERE " . Access::parentAllowedSql('grades', 'ps.parent_id'),
            [
                "Quarter {$quarter} grades for {$class['subject_name']} are now available.",
                $class['section_id'],
                $class['id'],
                $quarter,
            ]
        );
        Alerts::kick();
    }

    public static function unreadCount(int $parentId): int
    {
        return (int) db_value('SELECT COUNT(*) FROM parent_notifications WHERE parent_id = ? AND read_at IS NULL', [$parentId]);
    }

    public static function toParents(int $studentId, string $type, string $message, string $link): void
    {
        // A parent who has the matching part turned off (Super Admin → per person) doesn't get it.
        $part = ['late' => 'attendance', 'changed' => 'attendance', 'absent' => 'attendance', 'arrived' => 'attendance', 'left' => 'attendance', 'grades' => 'grades',
            'announcement' => 'news', 'excuse' => 'excuses', 'request' => 'requests', 'message' => 'messages'][$type] ?? null;
        db_exec(
            'INSERT INTO parent_notifications (parent_id, student_id, type, message, link)
             SELECT parent_id, student_id, ?, ?, ? FROM parent_students WHERE student_id = ?'
                . ($part ? ' AND ' . Access::parentAllowedSql($part, 'parent_id') : ''),
            [$type, $message, $link, $studentId]
        );
        Alerts::kick();
    }

    private static function attendanceLink(int $studentId, string $date): string
    {
        return 'parent/child.php?id=' . $studentId . '&month=' . substr($date, 0, 7);
    }
}
