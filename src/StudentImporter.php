<?php
declare(strict_types=1);

/**
 * Bulk-adds learners from a spreadsheet and, when a section is given, enrolls them in the
 * active school year. All or nothing: if any row has a problem, nothing is saved.
 */
final class StudentImporter
{
    /** Accepted header names for each field (matched on the start of the header text). */
    private const COLUMNS = [
        'lrn'         => ['lrn'],
        'last_name'   => ['last name', 'lastname', 'surname', 'family name'],
        'first_name'  => ['first name', 'firstname', 'given name'],
        'middle_name' => ['middle name', 'middlename'],
        'sex'         => ['sex', 'gender'],
        'birthdate'   => ['birthdate', 'birth date', 'birthday', 'date of birth'],
        'grade_level' => ['grade level', 'grade', 'level'],
        'section'     => ['section'],
    ];
    private const REQUIRED = ['lrn' => 'LRN', 'last_name' => 'Last Name', 'first_name' => 'First Name', 'birthdate' => 'Birthdate'];

    /**
     * @return array{errors: list<array{line: int, lrn: string, message: string}>, created: int, updated: int, unchanged: int, enrolled: int}
     */
    public static function import(array $sheetRows, ?array $schoolYear): array
    {
        [$headerIndex, $map] = self::findHeader($sheetRows);

        $sections = [];
        if ($schoolYear) {
            foreach (section_options((int) $schoolYear['id']) as $s) {
                $sections[$s['grade_level'] . '|' . mb_strtolower(trim($s['section_name']))] = (int) $s['id'];
            }
        }

        $records = [];
        $errors = [];
        $seenOnLine = [];

        for ($i = $headerIndex + 1, $n = count($sheetRows); $i < $n; $i++) {
            $row = $sheetRows[$i];
            if (trim(implode('', array_map(static fn ($v) => (string) $v, $row))) === '') {
                continue; // blank row
            }
            $line = $i + 1;
            $cell = static fn (string $field): mixed => isset($map[$field]) ? ($row[$map[$field]] ?? null) : null;
            $text = static fn (string $field): string => trim((string) $cell($field));

            $lrn = normalize_lrn($cell('lrn'));
            $fail = static function (string $message) use (&$errors, $line, $lrn): void {
                $errors[] = ['line' => $line, 'lrn' => $lrn, 'message' => $message];
            };

            if ($lrn === '') {
                $fail('The LRN is missing.');
                continue;
            }
            if (looks_like_scientific($lrn)) {
                $fail('Excel changed this LRN into scientific notation. Format the LRN column as Text and type it again.');
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

            $last = $text('last_name');
            $first = $text('first_name');
            $middle = $text('middle_name');
            if ($last === '' || $first === '') {
                $fail('Last name and first name are required.');
                continue;
            }
            if (max(mb_strlen($last), mb_strlen($first), mb_strlen($middle)) > 80) {
                $fail('Names must be 80 characters or fewer.');
                continue;
            }

            $sexText = strtoupper($text('sex'));
            $sex = match ($sexText) {
                '' => null,
                'M', 'MALE' => 'M',
                'F', 'FEMALE' => 'F',
                default => false,
            };
            if ($sex === false) {
                $fail('Sex must be M or F.');
                continue;
            }

            $birthdate = parse_date($cell('birthdate'));
            if ($birthdate === null || !plausible_birthdate($birthdate)) {
                $fail('The birthdate is missing or not a real date. Use YYYY-MM-DD or MM/DD/YYYY.');
                continue;
            }

            $sectionId = null;
            $sectionName = $text('section');
            if ($sectionName !== '') {
                if (!$schoolYear) {
                    $fail('There is no active school year, so learners cannot be put in sections yet.');
                    continue;
                }
                $level = (string) preg_replace('/^grade\s*/i', '', $text('grade_level'));
                if (!ctype_digit($level)) {
                    $fail('The grade level is required when a section is given.');
                    continue;
                }
                $key = (int) $level . '|' . mb_strtolower($sectionName);
                if (!isset($sections[$key])) {
                    $fail("There is no section \"Grade {$level} – {$sectionName}\" in S.Y. {$schoolYear['label']}.");
                    continue;
                }
                $sectionId = $sections[$key];
            }

            $records[] = compact('lrn', 'last', 'first', 'middle', 'sex', 'birthdate', 'sectionId');
        }

        $result = ['errors' => $errors, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'enrolled' => 0];
        if ($errors) {
            return $result;
        }
        if (!$records) {
            throw new UserFacingException('The file has no learner rows under the header row.');
        }

        $pdo = db();
        $upsert = $pdo->prepare(
            'INSERT INTO students (lrn, last_name, first_name, middle_name, sex, birthdate, qr_token, parent_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_name = VALUES(last_name), first_name = VALUES(first_name),
                 middle_name = VALUES(middle_name), sex = COALESCE(VALUES(sex), sex), birthdate = VALUES(birthdate)'
        );
        $findId = $pdo->prepare('SELECT id FROM students WHERE lrn = ?');
        $enroll = $pdo->prepare(
            'INSERT INTO enrollments (student_id, section_id, school_year_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE section_id = VALUES(section_id)'
        );

        $pdo->beginTransaction();
        try {
            foreach ($records as $r) {
                // New learners get a QR card code and a parent link code; existing ones keep theirs.
                $upsert->execute([$r['lrn'], $r['last'], $r['first'], $r['middle'] ?: null, $r['sex'], $r['birthdate'], new_qr_token(), new_parent_code()]);
                // MariaDB reports 1 for a new row, 2 for an updated row, 0 when nothing changed.
                match ($upsert->rowCount()) {
                    1 => $result['created']++,
                    2 => $result['updated']++,
                    default => $result['unchanged']++,
                };
                if ($r['sectionId'] !== null) {
                    $findId->execute([$r['lrn']]);
                    $enroll->execute([(int) $findId->fetchColumn(), $r['sectionId'], $schoolYear['id']]);
                    $result['enrolled']++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $result;
    }

    /** @return array{0: int, 1: array<string, int>} [header row index, field => column] */
    private static function findHeader(array $sheetRows): array
    {
        foreach (array_slice($sheetRows, 0, 15, true) as $index => $row) {
            $keys = array_map([SheetReader::class, 'headerKey'], $row);
            if (!in_array('lrn', $keys, true)) {
                continue;
            }
            $map = [];
            foreach ($keys as $col => $key) {
                if ($key === '') {
                    continue;
                }
                foreach (self::COLUMNS as $field => $aliases) {
                    if (isset($map[$field])) {
                        continue;
                    }
                    foreach ($aliases as $alias) {
                        if ($key === $alias || str_starts_with($key, $alias . ' ')) {
                            $map[$field] = $col;
                            continue 3;
                        }
                    }
                }
            }
            $missing = array_diff_key(self::REQUIRED, $map);
            if ($missing) {
                throw new UserFacingException('The file is missing these columns: ' . implode(', ', $missing) . '. Download the template to see the layout.');
            }
            return [$index, $map];
        }
        throw new UserFacingException('Could not find the header row (a row with an "LRN" column). Download the template to see the layout.');
    }
}
