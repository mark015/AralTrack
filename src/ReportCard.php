<?php
declare(strict_types=1);

/** Builds a learner's report card (grades by semester + attendance by subject) for one school year. */
final class ReportCard
{
    /**
     * @param int $requestedYearId 0 = the active school year (or the newest one the learner was enrolled in)
     * @return array{student: array, years: list<array>, selected: ?array, semesters: array, attendance: list<array>}|null
     */
    public static function build(int $studentId, int $requestedYearId = 0): ?array
    {
        $student = db_one('SELECT * FROM students WHERE id = ? AND workspace_id = ?', [$studentId, ws()]);
        if (!$student) {
            return null;
        }

        $years = db_all(
            'SELECT sy.id, sy.label, sy.is_active, sec.id AS section_id, sec.grade_level, sec.strand, sec.name AS section_name,
                    adv.full_name AS adviser
               FROM enrollments e
               JOIN school_years sy ON sy.id = e.school_year_id
               JOIN sections sec    ON sec.id = e.section_id
               LEFT JOIN users adv  ON adv.id = sec.adviser_id
              WHERE e.student_id = ?
              ORDER BY sy.label DESC',
            [$studentId]
        );
        $selected = null;
        foreach ($years as $year) {
            if ((int) $year['id'] === $requestedYearId) {
                $selected = $year;
            }
        }
        if (!$selected) {
            foreach ($years as $year) {
                if ($year['is_active']) {
                    $selected = $year;
                }
            }
        }
        $selected ??= $years[0] ?? null;

        $semesters = [];
        $attendance = [];
        if ($selected) {
            $sectionId = (int) $selected['section_id'];
            $classes = db_all(
                'SELECT ca.id, ca.semester, sub.name AS subject, sub.type, u.full_name AS teacher
                   FROM class_assignments ca
                   JOIN subjects sub ON sub.id = ca.subject_id
                   JOIN users u      ON u.id = ca.teacher_id
                  WHERE ca.section_id = ?
                  ORDER BY ca.semester, sub.type = \'core\' DESC, sub.sort_order, sub.name',
                [$sectionId]
            );
            $published = [];
            $rows = db_all(
                'SELECT g.class_assignment_id, g.quarter, g.grade
                   FROM grades g
                   JOIN grade_publications gp ON gp.class_assignment_id = g.class_assignment_id AND gp.quarter = g.quarter
                   JOIN class_assignments ca  ON ca.id = g.class_assignment_id
                  WHERE g.student_id = ? AND ca.section_id = ?',
                [$studentId, $sectionId]
            );
            foreach ($rows as $row) {
                $published[(int) $row['class_assignment_id']][(int) $row['quarter']] = (float) $row['grade'];
            }

            foreach (SEMESTERS as $sem) {
                $semesters[$sem] = [
                    'groups'  => ['Core subjects' => [], 'Applied and specialized subjects' => []],
                    'total'   => 0,
                    'finals'  => [],
                    'average' => null,
                ];
            }
            $totals = Attendance::studentTotals($studentId, $sectionId);
            foreach ($classes as $class) {
                $sem = (int) $class['semester'];
                $byQuarter = $published[(int) $class['id']] ?? [];
                $final = final_grade($byQuarter, semester_quarters($sem));
                $group = $class['type'] === 'core' ? 'Core subjects' : 'Applied and specialized subjects';
                $semesters[$sem]['groups'][$group][] = [
                    'subject'  => $class['subject'],
                    'teacher'  => $class['teacher'],
                    'quarters' => $byQuarter,
                    'final'    => $final,
                ];
                $semesters[$sem]['total']++;
                if ($final !== null) {
                    $semesters[$sem]['finals'][] = $final;
                }
                $attendance[] = ['subject' => $class['subject'], 'semester' => $sem]
                    + ($totals[(int) $class['id']] ?? ['held' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0]);
            }
            foreach ($semesters as $sem => $data) {
                if ($data['total'] > 0 && count($data['finals']) === $data['total']) {
                    $semesters[$sem]['average'] = (int) round(array_sum($data['finals']) / $data['total']);
                }
                $semesters[$sem]['groups'] = array_filter($data['groups']);
            }
        }

        return compact('student', 'years', 'selected', 'semesters', 'attendance');
    }
}
