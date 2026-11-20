<?php
declare(strict_types=1);

/**
 * Learners who need follow-up in a class: a quarterly or final grade below passing (75),
 * or at least N absences (Setup → Settings, default 3).
 */
final class AtRisk
{
    /** @return list<array> one row per learner per class, most reasons first */
    public static function find(array $classIds): array
    {
        $classIds = array_values(array_unique(array_map('intval', $classIds)));
        if (!$classIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($classIds), '?'));
        $passing = (float) config('passing_grade');
        $limit = max(1, (int) setting('at_risk_absences'));

        $classes = db_all(
            "SELECT ca.id, ca.semester, ca.section_id, sub.name AS subject_name, sub.code AS subject_code,
                    sec.grade_level, sec.strand, sec.name AS section_name, u.full_name AS teacher_name
               FROM class_assignments ca
               JOIN subjects sub ON sub.id = ca.subject_id
               JOIN sections sec ON sec.id = ca.section_id
               JOIN users u      ON u.id = ca.teacher_id
              WHERE ca.id IN ({$in})",
            $classIds
        );
        $grades = [];
        foreach (db_all("SELECT class_assignment_id, student_id, quarter, grade FROM grades WHERE class_assignment_id IN ({$in})", $classIds) as $g) {
            $grades[(int) $g['class_assignment_id']][(int) $g['student_id']][(int) $g['quarter']] = (float) $g['grade'];
        }
        $absences = [];
        $rows = db_all(
            "SELECT cs.class_assignment_id, sa.student_id, SUM(sa.status = 'absent') AS absent, SUM(sa.status = 'late') AS late
               FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
              WHERE cs.class_assignment_id IN ({$in}) AND cs.finished_at IS NOT NULL
              GROUP BY cs.class_assignment_id, sa.student_id",
            $classIds
        );
        foreach ($rows as $a) {
            $absences[(int) $a['class_assignment_id']][(int) $a['student_id']] = ['absent' => (int) $a['absent'], 'late' => (int) $a['late']];
        }

        $rosters = [];
        $result = [];
        foreach ($classes as $class) {
            $cid = (int) $class['id'];
            $rosters[$class['section_id']] ??= section_roster((int) $class['section_id']);
            $quarters = semester_quarters((int) $class['semester']);
            foreach ($rosters[$class['section_id']] as $student) {
                $sid = (int) $student['id'];
                $reasons = [];
                $mine = $grades[$cid][$sid] ?? [];
                foreach ($quarters as $q) {
                    if (isset($mine[$q]) && $mine[$q] < $passing) {
                        $reasons[] = "Q{$q} grade " . format_grade($mine[$q]);
                    }
                }
                $final = final_grade($mine, $quarters);
                if ($final !== null && $final < $passing) {
                    $reasons[] = "Final grade {$final}";
                }
                $absent = $absences[$cid][$sid]['absent'] ?? 0;
                if ($absent >= $limit) {
                    $reasons[] = "{$absent} absences";
                }
                if ($reasons) {
                    $result[] = $student + [
                        'class' => $class,
                        'reasons' => $reasons,
                        'absent' => $absent,
                        'late' => $absences[$cid][$sid]['late'] ?? 0,
                    ];
                }
            }
        }
        usort($result, static fn ($a, $b) => [count($b['reasons']), $a['last_name']] <=> [count($a['reasons']), $b['last_name']]);
        return $result;
    }

    /** @return array<int, array> student id => latest intervention row */
    public static function latestInterventions(array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        if (!$studentIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($studentIds), '?'));
        $latest = [];
        $rows = db_all(
            "SELECT i.*, u.full_name FROM interventions i LEFT JOIN users u ON u.id = i.created_by
              WHERE i.student_id IN ({$in}) ORDER BY i.id DESC",
            $studentIds
        );
        foreach ($rows as $row) {
            $latest[(int) $row['student_id']] ??= $row;
        }
        return $latest;
    }
}
