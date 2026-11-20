<?php
declare(strict_types=1);

/**
 * Checks every row of an uploaded grade sheet against the class list. Saves nothing:
 * the teacher sees the result as a preview and confirms before anything is written.
 */
final class GradeImporter
{
    /**
     * @param list<list<mixed>> $sheetRows rows from SheetReader
     * @param array<string, array{id: int, name: string}> $roster LRN => enrolled learner
     * @param array<int, float> $existing student_id => grade already saved for this quarter
     * @return array{rows: list<array>, errors: list<array>, skipped: int}
     */
    public static function analyze(array $sheetRows, array $roster, array $existing): array
    {
        [$headerIndex, $lrnCol, $gradeCol] = self::findHeader($sheetRows);
        $min = (float) config('grade_min');
        $max = (float) config('grade_max');

        $rows = [];
        $errors = [];
        $skipped = 0;
        $seenOnLine = [];

        for ($i = $headerIndex + 1, $n = count($sheetRows); $i < $n; $i++) {
            $line = $i + 1; // row number as shown in Excel
            $rawLrn = $sheetRows[$i][$lrnCol] ?? null;
            $rawGrade = $sheetRows[$i][$gradeCol] ?? null;
            $lrnBlank = trim((string) $rawLrn) === '';
            $gradeBlank = trim((string) $rawGrade) === '';

            if ($lrnBlank && $gradeBlank) {
                continue;
            }
            $lrn = normalize_lrn($rawLrn);
            $fail = static function (string $message) use (&$errors, $line, $lrn): void {
                $errors[] = ['line' => $line, 'lrn' => $lrn, 'message' => $message];
            };

            if ($lrnBlank) {
                $fail('The LRN is missing.');
                continue;
            }
            if (looks_like_scientific($lrn)) {
                $fail('Excel changed this LRN into scientific notation (like 1.23E+11). Use the .xlsx template, or format the LRN column as Text.');
                continue;
            }
            if (!is_valid_lrn($lrn)) {
                $fail('An LRN must be exactly 12 digits.');
                continue;
            }
            if (isset($seenOnLine[$lrn])) {
                $fail("This LRN is also on row {$seenOnLine[$lrn]}.");
                continue;
            }
            $seenOnLine[$lrn] = $line;

            if (!isset($roster[$lrn])) {
                $fail('No learner with this LRN is enrolled in this section.');
                continue;
            }
            $learner = $roster[$lrn];

            if ($gradeBlank) {
                $skipped++;
                continue;
            }
            $value = is_string($rawGrade) ? trim($rawGrade) : $rawGrade;
            if (!is_numeric($value)) {
                $fail('The grade "' . $value . '" is not a number.');
                continue;
            }
            $grade = round((float) $value, 2);
            if ($grade < $min || $grade > $max) {
                $fail('The grade ' . format_grade($grade) . ' is outside ' . format_grade($min) . '–' . format_grade($max) . '.');
                continue;
            }

            $old = $existing[$learner['id']] ?? null;
            $rows[] = [
                'line'       => $line,
                'student_id' => $learner['id'],
                'lrn'        => $lrn,
                'name'       => $learner['name'],
                'grade'      => $grade,
                'old'        => $old,
                'status'     => $old === null ? 'new' : (abs($old - $grade) < 0.005 ? 'same' : 'changed'),
            ];
        }

        return ['rows' => $rows, 'errors' => $errors, 'skipped' => $skipped];
    }

    /** @return array{0: int, 1: int, 2: int} [header row index, LRN column, Grade column] */
    private static function findHeader(array $sheetRows): array
    {
        foreach (array_slice($sheetRows, 0, 15, true) as $index => $row) {
            $lrnCol = null;
            $gradeCol = null;
            foreach ($row as $col => $value) {
                $key = SheetReader::headerKey($value);
                if ($key === 'lrn') {
                    $lrnCol ??= $col;
                } elseif (in_array($key, ['grade', 'quarterly grade', 'qg'], true)) {
                    $gradeCol ??= $col;
                }
            }
            if ($lrnCol !== null && $gradeCol !== null) {
                return [$index, $lrnCol, $gradeCol];
            }
        }
        throw new UserFacingException(
            'Could not find the header row. The file needs a column named "LRN" and a column named "Grade". '
            . 'Download the template for this class to get the right layout.'
        );
    }
}
