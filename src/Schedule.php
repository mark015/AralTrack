<?php
declare(strict_types=1);

/** Weekly class timetable (weekday 1 = Monday … 7 = Sunday). */
final class Schedule
{
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    private const SELECT = 'SELECT cs.*, ca.section_id, ca.teacher_id, ca.semester, sub.code AS subject_code, sub.name AS subject_name,
                                   sec.grade_level, sec.strand, sec.name AS section_name, u.full_name AS teacher_name
                              FROM class_schedules cs
                              JOIN class_assignments ca ON ca.id = cs.class_assignment_id
                              JOIN subjects sub         ON sub.id = ca.subject_id
                              JOIN sections sec         ON sec.id = ca.section_id
                              JOIN users u              ON u.id = ca.teacher_id';

    public static function forClass(int $classId): array
    {
        return db_all(self::SELECT . ' WHERE cs.class_assignment_id = ? ORDER BY cs.weekday, cs.starts_at', [$classId]);
    }

    public static function forSection(int $sectionId): array
    {
        return db_all(self::SELECT . ' WHERE ca.section_id = ? ORDER BY cs.weekday, cs.starts_at', [$sectionId]);
    }

    public static function forTeacher(int $teacherId, int $schoolYearId): array
    {
        return db_all(self::SELECT . ' WHERE ca.teacher_id = ? AND sec.school_year_id = ? ORDER BY cs.weekday, cs.starts_at', [$teacherId, $schoolYearId]);
    }

    /** Slots on a given day (default today), optionally only one semester's classes. */
    public static function onDay(array $slots, ?int $weekday = null, ?int $semester = null): array
    {
        $weekday ??= (int) date('N');
        return array_values(array_filter(
            $slots,
            static fn ($s) => (int) $s['weekday'] === $weekday && ($semester === null || (int) $s['semester'] === $semester)
        ));
    }

    /** @return array<int, list<array>> weekday => slots (only days that have classes, plus Mon–Fri) */
    public static function byDay(array $slots): array
    {
        $week = [1 => [], 2 => [], 3 => [], 4 => [], 5 => []];
        foreach ($slots as $slot) {
            $week[(int) $slot['weekday']][] = $slot;
        }
        ksort($week);
        return $week;
    }

    /** A message describing a clash with another class in the same section or of the same teacher, or null. */
    public static function conflict(array $class, int $weekday, string $start, string $end, int $exceptId = 0): ?string
    {
        $clash = db_one(
            self::SELECT . ' WHERE cs.weekday = ? AND cs.id <> ? AND cs.starts_at < ? AND cs.ends_at > ?
                               AND (ca.section_id = ? OR ca.teacher_id = ?) AND ca.id <> ? LIMIT 1',
            [$weekday, $exceptId, $end, $start, $class['section_id'], $class['teacher_id'], $class['id']]
        );
        if (!$clash) {
            return null;
        }
        $who = (int) $clash['section_id'] === (int) $class['section_id'] ? 'this section' : $clash['teacher_name'];
        return sprintf(
            'That overlaps %s (%s, %s–%s) for %s.',
            $clash['subject_name'],
            self::DAYS[$weekday],
            self::time($clash['starts_at']),
            self::time($clash['ends_at']),
            $who
        );
    }

    public static function time(string $hms): string
    {
        return date('g:i A', strtotime('2000-01-01 ' . $hms));
    }
}
