<?php
declare(strict_types=1);

/**
 * The setup guide for a teacher who signed up alone (teacher/setup.php): school year, the classes they teach,
 * their learners (pasted from a spreadsheet) and the QR cards. Uses the same tables as a school: the teacher's
 * workspace has its own school year, sections, subjects and learners.
 */
final class TeacherSetup
{
    /** Common Senior High School subjects: code => [name, type] */
    public const SUBJECTS = [
        'ORALCOM'  => ['Oral Communication in Context', 'core'],
        'RWS'      => ['Reading and Writing Skills', 'core'],
        'KOMPAN'   => ['Komunikasyon at Pananaliksik sa Wika at Kulturang Pilipino', 'core'],
        'PAGBASA'  => ['Pagbasa at Pagsusuri ng Iba\'t Ibang Teksto Tungo sa Pananaliksik', 'core'],
        'LIT21'    => ['21st Century Literature from the Philippines and the World', 'core'],
        'CPAR'     => ['Contemporary Philippine Arts from the Regions', 'core'],
        'MIL'      => ['Media and Information Literacy', 'core'],
        'GENMATH'  => ['General Mathematics', 'core'],
        'STATPROB' => ['Statistics and Probability', 'core'],
        'ELS'      => ['Earth and Life Science', 'core'],
        'EARTHSCI' => ['Earth Science', 'core'],
        'PHYSCI'   => ['Physical Science', 'core'],
        'DRRR'     => ['Disaster Readiness and Risk Reduction', 'core'],
        'PHILO'    => ['Introduction to the Philosophy of the Human Person', 'core'],
        'PERDEV'   => ['Personal Development', 'core'],
        'UCSP'     => ['Understanding Culture, Society and Politics', 'core'],
        'PEH1'     => ['Physical Education and Health 1', 'core'],
        'PEH2'     => ['Physical Education and Health 2', 'core'],
        'PEH3'     => ['Physical Education and Health 3', 'core'],
        'PEH4'     => ['Physical Education and Health 4', 'core'],
        'EAPP'     => ['English for Academic and Professional Purposes', 'applied'],
        'PR1'      => ['Practical Research 1', 'applied'],
        'PR2'      => ['Practical Research 2', 'applied'],
        'FILPL'    => ['Filipino sa Piling Larang', 'applied'],
        'EMPTECH'  => ['Empowerment Technologies', 'applied'],
        'ENTREP'   => ['Entrepreneurship', 'applied'],
        'III'      => ['Inquiries, Investigations and Immersion', 'applied'],
        'PRECAL'   => ['Pre-Calculus', 'specialized'],
        'BASICCAL' => ['Basic Calculus', 'specialized'],
        'GENBIO1'  => ['General Biology 1', 'specialized'],
        'GENBIO2'  => ['General Biology 2', 'specialized'],
        'GENCHEM1' => ['General Chemistry 1', 'specialized'],
        'GENCHEM2' => ['General Chemistry 2', 'specialized'],
        'GENPHYS1' => ['General Physics 1', 'specialized'],
        'GENPHYS2' => ['General Physics 2', 'specialized'],
    ];

    /** The school year a teacher is most likely in now, e.g. "2026-2027" from June onwards. */
    public static function suggestedYear(): string
    {
        $start = (int) date('n') >= 6 ? (int) date('Y') : (int) date('Y') - 1;
        return $start . '-' . ($start + 1);
    }

    /** The school name, the school year (made active) and the current semester. */
    public static function saveSchool(string $schoolName, string $yearLabel, int $semester): void
    {
        if (mb_strlen($schoolName) < 2 || mb_strlen($schoolName) > 150) {
            throw new UserFacingException('Enter the name of the school where you teach.');
        }
        save_settings(['school_name' => $schoolName, 'current_semester' => $semester === 2 ? '2' : '1']);
        db_exec('UPDATE workspaces SET name = ? WHERE id = ?', [$schoolName, ws()]);
        if (active_school_year()) {
            return;
        }
        if (!preg_match('/^(\d{4})-(\d{4})$/', $yearLabel, $m) || (int) $m[2] !== (int) $m[1] + 1) {
            throw new UserFacingException('Write the school year like ' . self::suggestedYear() . '.');
        }
        $existing = db_value('SELECT id FROM school_years WHERE workspace_id = ? AND label = ?', [ws(), $yearLabel]);
        if ($existing) {
            db_exec('UPDATE school_years SET is_active = (id = ?) WHERE workspace_id = ?', [$existing, ws()]);
        } else {
            db_exec('INSERT INTO school_years (workspace_id, label, is_active) VALUES (?, ?, 1)', [ws(), $yearLabel]);
        }
    }

    /**
     * Adds a class the teacher teaches. The section and the subject are found or made.
     * $subject is "id:5" (a subject already here), "code:GENMATH" (a common subject) or "" with $otherName.
     */
    public static function addClass(array $staff, int $grade, string $strand, string $sectionName, string $subject, string $otherName, int $semester, bool $adviser): array
    {
        $sy = active_school_year() ?? throw new UserFacingException('Save the school year first (step 1).');
        $sectionName = trim($sectionName);
        $strand = trim($strand);
        if (!in_array($grade, array_map('intval', (array) config('grade_levels')), true)) {
            throw new UserFacingException('Choose the grade level.');
        }
        if ($sectionName === '' || mb_strlen($sectionName) > 50 || mb_strlen($strand) > 50) {
            throw new UserFacingException('Enter the section name (up to 50 characters).');
        }
        $semester = $semester === 2 ? 2 : 1;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // The section
            $section = db_one(
                'SELECT * FROM sections WHERE workspace_id = ? AND school_year_id = ? AND grade_level = ? AND name = ?',
                [ws(), $sy['id'], $grade, $sectionName]
            );
            if (!$section) {
                db_exec(
                    'INSERT INTO sections (workspace_id, school_year_id, grade_level, strand, name, adviser_id) VALUES (?, ?, ?, ?, ?, ?)',
                    [ws(), $sy['id'], $grade, $strand, $sectionName, $adviser ? $staff['id'] : null]
                );
                $sectionId = (int) $pdo->lastInsertId();
            } else {
                $sectionId = (int) $section['id'];
                if ($adviser) {
                    db_exec('UPDATE sections SET adviser_id = ? WHERE id = ?', [$staff['id'], $sectionId]);
                }
            }

            // The subject
            if (str_starts_with($subject, 'id:')) {
                $subjectId = (int) db_value('SELECT id FROM subjects WHERE id = ? AND workspace_id = ?', [(int) substr($subject, 3), ws()]);
                if (!$subjectId) {
                    throw new UserFacingException('Choose a subject from the list.');
                }
            } elseif (str_starts_with($subject, 'code:') && isset(self::SUBJECTS[substr($subject, 5)])) {
                $code = substr($subject, 5);
                [$name, $type] = self::SUBJECTS[$code];
                $subjectId = (int) db_value('SELECT id FROM subjects WHERE workspace_id = ? AND code = ?', [ws(), $code]);
                if (!$subjectId) {
                    db_exec(
                        'INSERT INTO subjects (workspace_id, code, name, type, sort_order) VALUES (?, ?, ?, ?, ?)',
                        [ws(), $code, $name, $type, array_search($code, array_keys(self::SUBJECTS), true) + 1]
                    );
                    $subjectId = (int) $pdo->lastInsertId();
                }
            } else {
                $name = trim($otherName);
                if ($name === '' || mb_strlen($name) > 100) {
                    throw new UserFacingException('Choose a subject, or type its name.');
                }
                $subjectId = (int) db_value('SELECT id FROM subjects WHERE workspace_id = ? AND name = ?', [ws(), $name]);
                if (!$subjectId) {
                    $base = substr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $name)), 0, 14) ?: 'SUBJ';
                    $code = $base;
                    for ($i = 2; db_value('SELECT 1 FROM subjects WHERE workspace_id = ? AND code = ?', [ws(), $code]); $i++) {
                        $code = $base . $i;
                    }
                    db_exec(
                        "INSERT INTO subjects (workspace_id, code, name, type, sort_order) VALUES (?, ?, ?, 'core', 100)",
                        [ws(), $code, $name]
                    );
                    $subjectId = (int) $pdo->lastInsertId();
                }
            }

            // The class
            if (db_value('SELECT 1 FROM class_assignments WHERE section_id = ? AND subject_id = ?', [$sectionId, $subjectId])) {
                throw new UserFacingException('That subject is already in that section.');
            }
            db_exec(
                'INSERT INTO class_assignments (workspace_id, section_id, subject_id, teacher_id, semester) VALUES (?, ?, ?, ?, ?)',
                [ws(), $sectionId, $subjectId, $staff['id'], $semester]
            );
            $classId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return class_info($classId);
    }

    /**
     * Learners pasted from a spreadsheet into one section, one per line:
     * LRN, last name, first name, middle name, sex, birthdate (tabs from Excel/Sheets, or commas).
     * @return array{errors: list<array{line: int, lrn: string, message: string}>, created: int, updated: int, unchanged: int, enrolled: int}
     */
    public static function importPasted(string $pasted, array $section): array
    {
        $sy = active_school_year() ?? throw new UserFacingException('Save the school year first (step 1).');
        $rows = [['LRN', 'Last Name', 'First Name', 'Middle Name', 'Sex', 'Birthdate', 'Grade Level', 'Section']];
        foreach (preg_split('/\R/', $pasted) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line, ',', '"', ''));
            if (stripos($cells[0] ?? '', 'lrn') !== false) {
                continue; // a header row copied along
            }
            $cells = array_pad(array_slice($cells, 0, 6), 6, '');
            $rows[] = [...$cells, (string) $section['grade_level'], (string) $section['section_name']];
        }
        if (count($rows) === 1) {
            throw new UserFacingException('Paste at least one learner: LRN, last name, first name, middle name, sex and birthdate.');
        }
        if (count($rows) > 401) {
            throw new UserFacingException('Paste up to 400 learners at a time.');
        }
        $result = StudentImporter::import($rows, $sy);
        foreach ($result['errors'] as &$error) {
            $error['line']--; // line 1 = the first learner pasted (the header row above was added here)
        }
        return $result;
    }

    /**
     * The teacher's sections this school year (taught or advised), with learner counts.
     * @return list<array>
     */
    public static function sections(array $staff): array
    {
        $sy = active_school_year();
        if (!$sy) {
            return [];
        }
        return db_all(
            'SELECT s.id, s.grade_level, s.strand, s.name AS section_name,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id) AS learners
               FROM sections s
              WHERE s.workspace_id = ? AND s.school_year_id = ?
                AND (s.adviser_id = ? OR EXISTS (SELECT 1 FROM class_assignments ca WHERE ca.section_id = s.id AND ca.teacher_id = ?))
              ORDER BY s.grade_level, s.name',
            [ws(), $sy['id'], $staff['id'], $staff['id']]
        );
    }

    /** True once the teacher pressed "Finish" in the setup guide. */
    public static function done(): bool
    {
        return setting('setup_done') !== '0';
    }
}
