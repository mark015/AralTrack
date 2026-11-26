<?php
declare(strict_types=1);

/**
 * Grading periods: each quarter of a school year can have a deadline. After the deadline
 * the quarter is locked — teachers can't change its grades, only request a change.
 */
final class Grading
{
    /** @return array<int, string> quarter => deadline (Y-m-d) */
    public static function deadlines(int $schoolYearId): array
    {
        static $cache = [];
        if (!isset($cache[$schoolYearId])) {
            $cache[$schoolYearId] = [];
            foreach (db_all('SELECT quarter, deadline FROM grade_deadlines WHERE school_year_id = ?', [$schoolYearId]) as $row) {
                $cache[$schoolYearId][(int) $row['quarter']] = $row['deadline'];
            }
        }
        return $cache[$schoolYearId];
    }

    public static function deadline(int $schoolYearId, int $quarter): ?string
    {
        return self::deadlines($schoolYearId)[$quarter] ?? null;
    }

    /** Locked = the deadline day has passed. */
    public static function isLocked(int $schoolYearId, int $quarter): bool
    {
        $deadline = self::deadline($schoolYearId, $quarter);
        return $deadline !== null && date('Y-m-d') > $deadline;
    }

    /** "Due Nov 28" / "Locked since Nov 28" / "" */
    public static function label(int $schoolYearId, int $quarter): string
    {
        $deadline = self::deadline($schoolYearId, $quarter);
        if ($deadline === null) {
            return '';
        }
        $day = date('M j', strtotime($deadline));
        return self::isLocked($schoolYearId, $quarter) ? "Locked since {$day}" : "Due {$day}";
    }

    /** Pending grade change requests (for the admin / principal badge). */
    public static function pendingRequests(): int
    {
        return (int) db_value("SELECT COUNT(*) FROM grade_change_requests WHERE status = 'pending' AND workspace_id = ?", [ws()]);
    }

    /** Approves a change request: applies the new grade (bypassing the lock) and records who approved it. */
    public static function approve(array $request, int $reviewerId, string $note): void
    {
        save_grade_changes(
            (int) $request['class_assignment_id'],
            [[(int) $request['student_id'], (int) $request['quarter'], $request['new_grade'] === null ? null : (float) $request['new_grade']]],
            $reviewerId,
            'change_request'
        );
        db_exec(
            "UPDATE grade_change_requests SET status = 'approved', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?",
            [$reviewerId, date('Y-m-d H:i:s'), $note ?: null, $request['id']]
        );
    }
}
