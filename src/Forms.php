<?php
declare(strict_types=1);

/**
 * Data for the DepEd school forms (SF1 register, SF2 attendance, SF5 promotion, SF9 report card,
 * SF10 permanent record) and the honor roll. Official forms use every grade entered by teachers,
 * published or not.
 */
final class Forms
{
    /** SF9 "Learner's Observed Values" (DepEd Order No. 8, s. 2015): statement number => [core value, behavior statement] */
    public const VALUES = [
        1 => ['Maka-Diyos', "Expresses one's spiritual beliefs while respecting the spiritual beliefs of others"],
        2 => ['Maka-Diyos', 'Shows adherence to ethical principles by upholding truth'],
        3 => ['Makatao', 'Is sensitive to individual, social, and cultural differences'],
        4 => ['Makatao', 'Demonstrates contributions toward solidarity'],
        5 => ['Makakalikasan', 'Cares for the environment and utilizes resources wisely, judiciously, and economically'],
        6 => ['Makabansa', 'Demonstrates pride in being a Filipino; exercises the rights and responsibilities of a Filipino citizen'],
        7 => ['Makabansa', 'Demonstrates appropriate behavior in carrying out activities in the school, community, and country'],
    ];
    public const RATINGS = ['AO' => 'Always Observed', 'SO' => 'Sometimes Observed', 'RO' => 'Rarely Observed', 'NO' => 'Not Observed'];

    /** A section with its school year. */
    public static function section(int $sectionId): ?array
    {
        return db_one(
            'SELECT s.*, s.name AS section_name, sy.label AS sy_label, u.full_name AS adviser
               FROM sections s JOIN school_years sy ON sy.id = s.school_year_id LEFT JOIN users u ON u.id = s.adviser_id
              WHERE s.id = ? AND s.workspace_id = ?',
            [$sectionId, ws()]
        );
    }

    /** Stops unless the staff member may print this section's forms (admin, principal, guidance, or its adviser). */
    public static function sectionOrAbort(int $sectionId, array $staff): array
    {
        $section = $sectionId > 0 ? self::section($sectionId) : null;
        if (!$section) {
            abort(404, 'That section does not exist.');
        }
        if (!sees_all_learners($staff) && (int) $section['adviser_id'] !== $staff['id']) {
            abort(403, 'Only the adviser, the registrar, the principal and the guidance counselor can open these forms.');
        }
        return $section;
    }

    /**
     * Grades by semester for one learner in one section (all entered grades).
     * @return array<int, array{subjects: list<array>, average: ?int, failed: list<string>, complete: bool}>
     */
    public static function semesterResults(int $studentId, int $sectionId): array
    {
        $classes = db_all(
            "SELECT ca.id, ca.semester, sub.name, sub.type FROM class_assignments ca JOIN subjects sub ON sub.id = ca.subject_id
              WHERE ca.section_id = ? ORDER BY ca.semester, sub.type = 'core' DESC, sub.sort_order, sub.name",
            [$sectionId]
        );
        $grades = [];
        foreach (db_all(
            'SELECT g.class_assignment_id, g.quarter, g.grade FROM grades g JOIN class_assignments ca ON ca.id = g.class_assignment_id
              WHERE g.student_id = ? AND ca.section_id = ?',
            [$studentId, $sectionId]
        ) as $g) {
            $grades[(int) $g['class_assignment_id']][(int) $g['quarter']] = (float) $g['grade'];
        }
        $out = [];
        foreach (SEMESTERS as $sem) {
            $out[$sem] = ['subjects' => [], 'average' => null, 'failed' => [], 'complete' => false];
        }
        foreach ($classes as $c) {
            $sem = (int) $c['semester'];
            $quarters = semester_quarters($sem);
            $byQ = $grades[(int) $c['id']] ?? [];
            $final = final_grade($byQ, $quarters);
            $out[$sem]['subjects'][] = [
                'name' => $c['name'], 'type' => $c['type'],
                'q' => [$byQ[$quarters[0]] ?? null, $byQ[$quarters[1]] ?? null],
                'final' => $final, 'remarks' => remarks($final),
            ];
            if ($final !== null && $final < (int) config('passing_grade')) {
                $out[$sem]['failed'][] = $c['name'];
            }
        }
        foreach ($out as $sem => $data) {
            $finals = array_filter(array_column($data['subjects'], 'final'), static fn ($f) => $f !== null);
            if ($data['subjects'] && count($finals) === count($data['subjects'])) {
                $out[$sem]['average'] = (int) round(array_sum($finals) / count($finals));
                $out[$sem]['complete'] = !$data['failed'];
            }
        }
        return $out;
    }

    /** DepEd Order No. 36, s. 2016 academic excellence award for a general average, or null. */
    public static function honor(?int $average, bool $allPassed = true): ?string
    {
        if ($average === null || !$allPassed) {
            return null;
        }
        return match (true) {
            $average >= 98 => 'With Highest Honors',
            $average >= 95 => 'With High Honors',
            $average >= 90 => 'With Honors',
            default => null,
        };
    }

    /**
     * Attendance per day for a section (from class meetings): [dates, [student_id][date] => status].
     * A day counts as a school day when at least one class met. The learner is present that day when
     * present or late in at least one class; late when late in the first class they attended; absent when
     * absent from every class (excused when every mark is excused).
     */
    public static function dailyStatus(int $sectionId, string $from, string $to): array
    {
        $rows = db_all(
            'SELECT cs.session_date, cs.starts_at, sa.student_id, sa.status
               FROM session_attendance sa
               JOIN class_sessions cs    ON cs.id = sa.session_id
               JOIN class_assignments ca ON ca.id = cs.class_assignment_id
              WHERE ca.section_id = ? AND cs.session_date BETWEEN ? AND ? AND cs.finished_at IS NOT NULL
              ORDER BY cs.session_date, cs.starts_at',
            [$sectionId, $from, $to]
        );
        $marks = [];
        $dates = [];
        foreach ($rows as $r) {
            $dates[$r['session_date']] = true;
            $marks[(int) $r['student_id']][$r['session_date']][] = $r['status'];
        }
        $status = [];
        foreach ($marks as $sid => $days) {
            foreach ($days as $date => $list) {
                $attended = array_values(array_filter($list, static fn ($s) => $s === 'present' || $s === 'late'));
                $status[$sid][$date] = match (true) {
                    $attended !== [] => $attended[0] === 'late' ? 'late' : 'present',
                    !in_array('absent', $list, true) => 'excused',
                    default => 'absent',
                };
            }
        }
        return [array_keys($dates), $status];
    }

    /** @return array<string, array{days: int, present: int, absent: int}> 'Y-m' => totals, for SF9 */
    public static function monthlyAttendance(int $studentId, int $sectionId, string $schoolYearLabel): array
    {
        [$startYear] = array_map('intval', explode('-', $schoolYearLabel));
        [$dates, $status] = self::dailyStatus($sectionId, "{$startYear}-06-01", ($startYear + 1) . '-05-31');
        $months = [];
        foreach ($dates as $date) {
            $m = substr($date, 0, 7);
            $months[$m] ??= ['days' => 0, 'present' => 0, 'absent' => 0];
            $months[$m]['days']++;
            $s = $status[$studentId][$date] ?? null;
            if ($s === 'present' || $s === 'late') {
                $months[$m]['present']++;
            } elseif ($s !== null) {
                $months[$m]['absent']++;
            }
        }
        ksort($months);
        return $months;
    }

    /** Observed values for one learner and year: [statement][quarter] => rating */
    public static function observedValues(int $studentId, int $schoolYearId): array
    {
        $out = [];
        foreach (db_all('SELECT statement, quarter, rating FROM observed_values WHERE student_id = ? AND school_year_id = ?', [$studentId, $schoolYearId]) as $r) {
            $out[(int) $r['statement']][(int) $r['quarter']] = $r['rating'];
        }
        return $out;
    }

    /** Age in completed years on a date. */
    public static function age(string $birthdate, string $asOf): int
    {
        return (int) (new DateTimeImmutable($birthdate))->diff(new DateTimeImmutable($asOf))->y;
    }

    /** Learners of a section, alphabetical, males first (as in DepEd forms). */
    public static function roster(int $sectionId): array
    {
        return db_all(
            "SELECT st.* FROM enrollments e JOIN students st ON st.id = e.student_id
              WHERE e.section_id = ? ORDER BY st.sex = 'F', st.last_name, st.first_name",
            [$sectionId]
        );
    }
}
