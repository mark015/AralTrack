<?php
declare(strict_types=1);

/** Conversations between a parent and a teacher or other staff member, kept inside the portal. */
final class Messages
{
    /** Unread messages for a parent ('parent') or staff member ('staff'). */
    public static function unread(string $who, int $id): int
    {
        $column = $who === 'parent' ? 'parent_id' : 'staff_id';
        $from = $who === 'parent' ? 'staff' : 'parent';
        return (int) db_value(
            "SELECT COUNT(*) FROM messages m JOIN message_threads t ON t.id = m.thread_id
              WHERE t.{$column} = ? AND m.sender_type = ? AND m.read_at IS NULL",
            [$id, $from]
        );
    }

    /** Threads with the other person's name and the number of unread messages. */
    public static function threads(string $who, int $id): array
    {
        $column = $who === 'parent' ? 'parent_id' : 'staff_id';
        $from = $who === 'parent' ? 'staff' : 'parent';
        return db_all(
            "SELECT t.*, p.full_name AS parent_name, u.full_name AS staff_name, st.first_name, st.last_name,
                    (SELECT COUNT(*) FROM messages m WHERE m.thread_id = t.id AND m.sender_type = ? AND m.read_at IS NULL) AS unread,
                    (SELECT body FROM messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_body
               FROM message_threads t
               JOIN parents p        ON p.id = t.parent_id
               JOIN users u          ON u.id = t.staff_id
               LEFT JOIN students st ON st.id = t.student_id
              WHERE t.{$column} = ?
              ORDER BY t.last_message_at DESC",
            [$from, $id]
        );
    }

    /** Staff a parent may write to about a child: the adviser and the child's subject teachers this year. */
    public static function staffForChild(int $studentId): array
    {
        $rows = db_all(
            "SELECT u.id, u.full_name, sec.adviser_id = u.id AS is_adviser, GROUP_CONCAT(DISTINCT sub.name ORDER BY sub.sort_order SEPARATOR '|') AS subjects
               FROM enrollments e
               JOIN school_years sy ON sy.id = e.school_year_id AND sy.is_active = 1
               JOIN sections sec    ON sec.id = e.section_id
               LEFT JOIN class_assignments ca ON ca.section_id = sec.id
               JOIN users u         ON u.is_active = 1 AND (u.id = sec.adviser_id OR u.id = ca.teacher_id)
               LEFT JOIN subjects sub ON sub.id = ca.subject_id AND ca.teacher_id = u.id
              WHERE e.student_id = ?
              GROUP BY u.id, u.full_name, sec.adviser_id
              ORDER BY sec.adviser_id = u.id DESC, u.full_name",
            [$studentId]
        );
        foreach ($rows as &$row) {
            $subjects = $row['subjects'] ? explode('|', $row['subjects']) : [];
            $list = implode(', ', array_slice($subjects, 0, 2)) . (count($subjects) > 2 ? ' +' . (count($subjects) - 2) . ' more' : '');
            $row['role_label'] = $row['is_adviser'] ? 'Adviser' . ($subjects ? ' · ' . $list : '') : ($list ?: 'Teacher');
        }
        return $rows;
    }

    /**
     * Parents a staff member may write to: parents of learners in the classes they teach or advise this year
     * (every enrolled learner for admin, principal and guidance). One row per parent–learner link.
     */
    public static function parentsForStaff(array $staff): array
    {
        $scope = sees_all_learners($staff) ? '' : ' AND (sec.adviser_id = ? OR EXISTS (SELECT 1 FROM class_assignments ca WHERE ca.section_id = sec.id AND ca.teacher_id = ?))';
        return db_all(
            "SELECT ps.parent_id, ps.student_id, ps.relationship, p.full_name AS parent_name, st.last_name, st.first_name, st.middle_name,
                    sec.grade_level, sec.strand, sec.name AS section_name
               FROM parent_students ps
               JOIN parents p       ON p.id = ps.parent_id AND p.is_active = 1
               JOIN students st     ON st.id = ps.student_id
               JOIN enrollments e   ON e.student_id = st.id
               JOIN school_years sy ON sy.id = e.school_year_id AND sy.is_active = 1
               JOIN sections sec    ON sec.id = e.section_id
              WHERE 1 = 1{$scope}
              ORDER BY sec.grade_level, sec.name, st.last_name, st.first_name, p.full_name",
            $scope === '' ? [] : [$staff['id'], $staff['id']]
        );
    }

    /** The thread if this parent or staff member is part of it, else null. */
    public static function thread(string $who, int $id, int $threadId): ?array
    {
        $column = $who === 'parent' ? 'parent_id' : 'staff_id';
        return db_one(
            "SELECT t.*, p.full_name AS parent_name, u.full_name AS staff_name, st.first_name, st.last_name, st.middle_name
               FROM message_threads t
               JOIN parents p        ON p.id = t.parent_id
               JOIN users u          ON u.id = t.staff_id
               LEFT JOIN students st ON st.id = t.student_id
              WHERE t.id = ? AND t.{$column} = ?",
            [$threadId, $id]
        );
    }

    /** Marks the other side's messages in a thread as read. */
    public static function markRead(int $threadId, string $who): void
    {
        db_exec(
            'UPDATE messages SET read_at = ? WHERE thread_id = ? AND sender_type = ? AND read_at IS NULL',
            [date('Y-m-d H:i:s'), $threadId, $who === 'parent' ? 'staff' : 'parent']
        );
    }

    /** Messages this person sent in the last hour (a simple guard against floods). */
    public static function sentLastHour(string $who, int $id): int
    {
        $column = $who === 'parent' ? 'parent_id' : 'staff_id';
        return (int) db_value(
            "SELECT COUNT(*) FROM messages m JOIN message_threads t ON t.id = m.thread_id
              WHERE t.{$column} = ? AND m.sender_type = ? AND m.created_at > ?",
            [$id, $who, date('Y-m-d H:i:s', time() - 3600)]
        );
    }

    public static function send(int $threadId, string $senderType, string $body): void
    {
        db_exec('INSERT INTO messages (thread_id, sender_type, body) VALUES (?, ?, ?)', [$threadId, $senderType, $body]);
        db_exec('UPDATE message_threads SET last_message_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $threadId]);
    }
}
