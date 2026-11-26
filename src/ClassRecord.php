<?php
declare(strict_types=1);

/**
 * DepEd class record (DepEd Order No. 8, s. 2015): Written Works (WW), Performance Tasks (PT) and
 * Quarterly Assessment (QA). For each component: percentage score = total raw score ÷ total highest
 * possible score × 100, weighted score = percentage × weight. Initial grade = sum of weighted scores,
 * then transmuted to the quarterly grade with the DepEd transmutation table.
 */
final class ClassRecord
{
    public const COMPONENTS = ['ww' => 'Written Works', 'pt' => 'Performance Tasks', 'qa' => 'Quarterly Assessment'];

    /** Default Senior High weights by subject type (percent). */
    public const DEFAULT_WEIGHTS = [
        'core'        => ['ww' => 25, 'pt' => 50, 'qa' => 25],
        'applied'     => ['ww' => 25, 'pt' => 45, 'qa' => 30],
        'specialized' => ['ww' => 25, 'pt' => 45, 'qa' => 30],
    ];

    /** @return array{ww: float, pt: float, qa: float} */
    public static function weights(int $subjectId): array
    {
        $s = db_one('SELECT type, ww_weight, pt_weight, qa_weight FROM subjects WHERE id = ? AND workspace_id = ?', [$subjectId, ws()]);
        $default = self::DEFAULT_WEIGHTS[$s['type'] ?? 'core'] ?? self::DEFAULT_WEIGHTS['core'];
        if ($s && $s['ww_weight'] !== null && $s['pt_weight'] !== null && $s['qa_weight'] !== null) {
            return ['ww' => (float) $s['ww_weight'], 'pt' => (float) $s['pt_weight'], 'qa' => (float) $s['qa_weight']];
        }
        return array_map('floatval', $default);
    }

    /** True when the subject uses its own weights instead of the DepEd default for its type. */
    public static function hasCustomWeights(int $subjectId): bool
    {
        return (bool) db_value('SELECT ww_weight IS NOT NULL AND pt_weight IS NOT NULL AND qa_weight IS NOT NULL FROM subjects WHERE id = ? AND workspace_id = ?', [$subjectId, ws()]);
    }

    /**
     * Totals of the highest possible scores per component. With $scores, only assessments that are already
     * given (at least one learner has a score) count. @return array{ww: float, pt: float, qa: float}
     */
    public static function highestPossible(array $assessments, ?array $scores = null): array
    {
        $max = ['ww' => 0.0, 'pt' => 0.0, 'qa' => 0.0];
        foreach ($scores === null ? $assessments : self::given($assessments, $scores) as $a) {
            $max[$a['component']] += (float) $a['max_score'];
        }
        return $max;
    }

    /** Assessments that at least one learner has a score in. One that nobody has a score in yet (e.g. an exam not given yet) does not count. */
    public static function given(array $assessments, array $scores): array
    {
        return array_values(array_filter($assessments, static fn ($a) => !empty($scores[(int) $a['id']])));
    }

    /** DepEd transmutation table: initial grade → quarterly grade (60–100). */
    public static function transmute(float $initial): int
    {
        $initial = max(0.0, min(100.0, round($initial, 2)));
        if ($initial >= 60) {
            return min(100, 75 + (int) floor(($initial - 60) / 1.6 + 1e-9));
        }
        return 60 + (int) floor($initial / 4 + 1e-9);
    }

    public static function assessments(int $classId, int $quarter): array
    {
        return db_all(
            "SELECT * FROM assessments WHERE class_assignment_id = ? AND quarter = ? ORDER BY FIELD(component, 'ww', 'pt', 'qa'), COALESCE(taken_on, created_at), id",
            [$classId, $quarter]
        );
    }

    /** @return array<int, array<int, float>> assessment id => [student id => score] */
    public static function scores(array $assessments): array
    {
        if (!$assessments) {
            return [];
        }
        $ids = implode(',', array_map('intval', array_column($assessments, 'id')));
        $out = [];
        foreach (db_all("SELECT * FROM assessment_scores WHERE assessment_id IN ({$ids})") as $row) {
            $out[(int) $row['assessment_id']][(int) $row['student_id']] = (float) $row['score'];
        }
        return $out;
    }

    /**
     * Computes one learner's quarter. Only assessments already given count (see given()); in those, a
     * missing score counts as 0. A component with nothing given yet is left out and the other weights are
     * scaled up, so a partly filled record still shows a grade (the page flags it).
     * @return array{ps: array<string, ?float>, initial: ?float, grade: ?int, missing: list<string>, blanks: int}
     */
    public static function compute(array $assessments, array $scores, int $studentId, array $weights): array
    {
        $assessments = self::given($assessments, $scores);
        $raw = ['ww' => 0.0, 'pt' => 0.0, 'qa' => 0.0];
        $max = ['ww' => 0.0, 'pt' => 0.0, 'qa' => 0.0];
        $blanks = 0;
        foreach ($assessments as $a) {
            $c = $a['component'];
            $max[$c] += (float) $a['max_score'];
            if (isset($scores[(int) $a['id']][$studentId])) {
                $raw[$c] += $scores[(int) $a['id']][$studentId];
            } else {
                $blanks++;
            }
        }
        $ps = [];
        $weighted = 0.0;
        $usedWeight = 0.0;
        $missing = [];
        foreach (array_keys(self::COMPONENTS) as $c) {
            if ($max[$c] <= 0) {
                $ps[$c] = null;
                $missing[] = self::COMPONENTS[$c];
                continue;
            }
            $ps[$c] = round($raw[$c] / $max[$c] * 100, 2);
            $weighted += $ps[$c] * $weights[$c] / 100;
            $usedWeight += $weights[$c];
        }
        if ($usedWeight <= 0 || $blanks === count($assessments)) {
            // nothing to compute yet, or no score at all for this learner (e.g. a transferee): no grade
            return ['ps' => $ps, 'initial' => null, 'grade' => null, 'missing' => $missing, 'blanks' => $blanks];
        }
        $initial = round($weighted * 100 / $usedWeight, 2);
        return ['ps' => $ps, 'initial' => $initial, 'grade' => self::transmute($initial), 'missing' => $missing, 'blanks' => $blanks];
    }
}
