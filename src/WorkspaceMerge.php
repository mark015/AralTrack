<?php
declare(strict_types=1);

/**
 * A teacher who used the system on their own joins a school: everything in their workspace moves into the school's.
 *
 * plan()  shows what will happen, without changing anything: school years and subjects are matched by label and
 *         code, the teacher's sections by the teacher's choice (default: same grade and name), learners by LRN and
 *         parents by sign-in name. A matched record is joined with the school's; anything else moves in.
 *         Problems that would lose data (the school already has that subject in that section, with grades or
 *         attendance) stop the move until they're solved.
 * run()   does it in one transaction, checks that every link between tables still holds, and undoes everything if
 *         one doesn't. Afterwards the teacher is a staff account of the school and their own workspace is closed.
 */
final class WorkspaceMerge
{
    /** Tables that point to a learner, with the column. */
    private const STUDENT_REFS = [
        ['grades', 'student_id'], ['session_attendance', 'student_id'], ['scan_log', 'student_id'], ['assessment_scores', 'student_id'],
        ['observed_values', 'student_id'], ['report_acknowledgements', 'student_id'], ['parent_students', 'student_id'],
        ['parent_notifications', 'student_id'], ['excuse_letters', 'student_id'], ['document_requests', 'student_id'],
        ['interventions', 'student_id'], ['grade_change_requests', 'student_id'], ['grade_history', 'student_id'], ['message_threads', 'student_id'],
    ];
    /** Tables that point to a class, with the column. */
    private const CLASS_REFS = [
        ['grades', 'class_assignment_id'], ['grade_publications', 'class_assignment_id'], ['upload_log', 'class_assignment_id'],
        ['class_sessions', 'class_assignment_id'], ['class_schedules', 'class_assignment_id'], ['assessments', 'class_assignment_id'],
        ['class_posts', 'class_assignment_id'], ['interventions', 'class_assignment_id'], ['grade_change_requests', 'class_assignment_id'],
        ['grade_history', 'class_assignment_id'],
    ];
    /** Tables that point to a parent, with the column. */
    private const PARENT_REFS = [
        ['parent_students', 'parent_id'], ['parent_notifications', 'parent_id'], ['message_threads', 'parent_id'],
        ['excuse_letters', 'parent_id'], ['report_acknowledgements', 'parent_id'],
    ];
    /** Records of the teacher's workspace that move into the school as they are (after the ids above are updated). */
    private const MOVE_TABLES = ['class_sessions', 'class_posts', 'grade_history', 'grade_change_requests', 'interventions', 'excuse_letters', 'document_requests', 'message_threads'];

    /**
     * @param array<int, int> $sectionChoices teacher's section id => school section id (0 = bring it as a new section)
     */
    public static function plan(int $from, int $to, array $sectionChoices = []): array
    {
        $first = static fn (string $sql, array $params): int => (int) (db_value($sql, $params) ?? 0);

        $years = db_all('SELECT * FROM school_years WHERE workspace_id = ? ORDER BY label DESC', [$from]);
        $yearMap = [];
        foreach ($years as $y) {
            $yearMap[(int) $y['id']] = $first('SELECT id FROM school_years WHERE workspace_id = ? AND label = ?', [$to, $y['label']]);
        }

        $subjects = db_all('SELECT * FROM subjects WHERE workspace_id = ?', [$from]);
        $subjectMap = [];
        foreach ($subjects as $s) {
            $subjectMap[(int) $s['id']] = $first('SELECT id FROM subjects WHERE workspace_id = ? AND code = ?', [$to, $s['code']])
                ?: $first('SELECT id FROM subjects WHERE workspace_id = ? AND LOWER(name) = LOWER(?)', [$to, $s['name']]);
        }

        $sections = db_all(
            'SELECT s.*, s.name AS section_name, sy.label AS sy_label,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id) AS learners
               FROM sections s JOIN school_years sy ON sy.id = s.school_year_id
              WHERE s.workspace_id = ? ORDER BY sy.label DESC, s.grade_level, s.name',
            [$from]
        );
        $sectionMap = [];
        $sectionOptions = [];
        foreach ($sections as $sec) {
            $id = (int) $sec['id'];
            $year = $yearMap[(int) $sec['school_year_id']];
            $options = $year ? db_all(
                'SELECT id, grade_level, strand, name AS section_name FROM sections WHERE workspace_id = ? AND school_year_id = ? ORDER BY grade_level, strand, name',
                [$to, $year]
            ) : [];
            $sectionOptions[$id] = $options;
            $ids = array_map('intval', array_column($options, 'id'));
            if (array_key_exists($id, $sectionChoices)) {
                $sectionMap[$id] = in_array((int) $sectionChoices[$id], $ids, true) ? (int) $sectionChoices[$id] : 0;
            } else {
                $sectionMap[$id] = 0;
                foreach ($options as $o) {
                    if ((int) $o['grade_level'] === (int) $sec['grade_level'] && mb_strtolower(trim($o['section_name'])) === mb_strtolower(trim($sec['name']))) {
                        $sectionMap[$id] = (int) $o['id'];
                    }
                }
            }
        }

        $problems = [];
        $warnings = [];
        // A section brought as new whose name the school already uses gets the teacher's name added
        $teacher = (string) (db_value('SELECT full_name FROM users WHERE workspace_id = ? ORDER BY id LIMIT 1', [$from]) ?? 'Teacher');
        $renames = [];
        foreach ($sections as $sec) {
            $year = $yearMap[(int) $sec['school_year_id']];
            if (!$sectionMap[(int) $sec['id']] && $year && db_value(
                'SELECT 1 FROM sections WHERE workspace_id = ? AND school_year_id = ? AND grade_level = ? AND name = ?',
                [$to, $year, $sec['grade_level'], $sec['name']]
            )) {
                $renames[(int) $sec['id']] = mb_substr($sec['name'] . ' (' . explode(' ', trim($teacher))[0] . ')', 0, 50);
                $warnings[] = "The school already has a section named {$sec['name']}; yours will be called \"{$renames[(int) $sec['id']]}\". Or match it to the school's section.";
            }
        }

        $students = db_all('SELECT id, lrn, last_name, first_name, middle_name FROM students WHERE workspace_id = ?', [$from]);
        $studentMap = [];
        foreach ($students as $st) {
            $studentMap[(int) $st['id']] = $first('SELECT id FROM students WHERE workspace_id = ? AND lrn = ?', [$to, $st['lrn']]);
        }

        $parents = db_all('SELECT id, login FROM parents WHERE workspace_id = ?', [$from]);
        $parentMap = [];
        foreach ($parents as $p) {
            $parentMap[(int) $p['id']] = $first('SELECT id FROM parents WHERE workspace_id = ? AND login = ?', [$to, $p['login']]);
        }

        // Classes: into a new or existing section; a class already at the school is taken over only while it's empty
        $classes = db_all(
            'SELECT ca.*, sub.name AS subject_name, sec.grade_level, sec.strand, sec.name AS section_name
               FROM class_assignments ca JOIN subjects sub ON sub.id = ca.subject_id JOIN sections sec ON sec.id = ca.section_id
              WHERE ca.workspace_id = ?',
            [$from]
        );
        $classMap = [];
        $usedPairs = [];
        foreach ($classes as $c) {
            $id = (int) $c['id'];
            $section = $sectionMap[(int) $c['section_id']] ?: 'own' . $c['section_id'];
            $subject = $subjectMap[(int) $c['subject_id']] ?: 'own' . $c['subject_id'];
            $pair = $section . '|' . $subject;
            if (isset($usedPairs[$pair])) {
                $problems[] = "Two of your sections are matched to the same school section and both have {$c['subject_name']}. Bring one of them as a new section.";
            }
            $usedPairs[$pair] = true;
            $classMap[$id] = 0;
            if (is_int($section) && is_int($subject)) {
                $existing = db_one(
                    'SELECT ca.id, u.full_name FROM class_assignments ca JOIN users u ON u.id = ca.teacher_id WHERE ca.section_id = ? AND ca.subject_id = ?',
                    [$section, $subject]
                );
                if ($existing) {
                    $used = $first(
                        'SELECT (SELECT COUNT(*) FROM grades WHERE class_assignment_id = ?) + (SELECT COUNT(*) FROM class_sessions WHERE class_assignment_id = ?)
                              + (SELECT COUNT(*) FROM assessments WHERE class_assignment_id = ?) + (SELECT COUNT(*) FROM class_posts WHERE class_assignment_id = ?)',
                        array_fill(0, 4, $existing['id'])
                    );
                    if ($used > 0) {
                        $problems[] = "The school already has {$c['subject_name']} in that section, with grades or attendance (teacher: {$existing['full_name']}). "
                            . 'Ask the school\'s admin to move it, or bring your section as a new section.';
                    } else {
                        $classMap[$id] = (int) $existing['id']; // an empty class the registrar set up: the teacher takes it over
                        $warnings[] = "You take over the school's {$c['subject_name']} class in that section (set up for {$existing['full_name']}, still empty).";
                    }
                }
            }
        }

        // Learners who are in another section at the school that year keep that section
        foreach (db_all('SELECT e.*, st.first_name, st.last_name FROM enrollments e JOIN students st ON st.id = e.student_id WHERE st.workspace_id = ?', [$from]) as $e) {
            $target = $studentMap[(int) $e['student_id']] ?? 0;
            $year = $yearMap[(int) $e['school_year_id']] ?? 0;
            $section = $sectionMap[(int) $e['section_id']] ?? 0;
            if ($target && $year) {
                $theirs = $first('SELECT section_id FROM enrollments WHERE student_id = ? AND school_year_id = ?', [$target, $year]);
                if ($theirs && $theirs !== $section) {
                    $warnings[] = "{$e['first_name']} {$e['last_name']} is in another section at the school. Their grades and attendance come along, "
                        . 'but they stay in the school\'s section (so they won\'t be in your class list for that section).';
                }
            }
        }

        return [
            'from' => $from, 'to' => $to,
            'years' => $years, 'yearMap' => $yearMap,
            'subjects' => $subjects, 'subjectMap' => $subjectMap,
            'sections' => $sections, 'sectionMap' => $sectionMap, 'sectionOptions' => $sectionOptions, 'renames' => $renames,
            'students' => $students, 'studentMap' => $studentMap,
            'parents' => $parents, 'parentMap' => $parentMap,
            'classes' => $classes, 'classMap' => $classMap,
            'problems' => array_values(array_unique($problems)), 'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** Moves the teacher's workspace into the school (see the class comment). $userId becomes a school account with $role. */
    public static function run(array $plan, int $userId, string $role): void
    {
        if ($plan['problems']) {
            throw new UserFacingException(implode(' ', $plan['problems']));
        }
        $from = (int) $plan['from'];
        $to = (int) $plan['to'];
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0'); // ids are updated table by table; check() verifies every link at the end
        try {
            $repoint = static function (array $refs, int $old, int $new): void {
                foreach ($refs as [$table, $column]) {
                    // IGNORE: a row the school already has (same key) stays; the teacher's duplicate is removed below
                    db_exec("UPDATE IGNORE {$table} SET {$column} = ? WHERE {$column} = ?", [$new, $old]);
                    db_exec("DELETE FROM {$table} WHERE {$column} = ?", [$old]);
                }
            };

            // Classes the school already set up (empty) for this subject and section: the teacher's class joins it
            foreach ($plan['classMap'] as $old => $new) {
                if ($new) {
                    $semester = (int) db_value('SELECT semester FROM class_assignments WHERE id = ?', [$old]);
                    $repoint(self::CLASS_REFS, $old, $new);
                    db_exec('UPDATE class_assignments SET teacher_id = ?, semester = ? WHERE id = ?', [$userId, $semester, $new]);
                    db_exec('DELETE FROM class_assignments WHERE id = ?', [$old]);
                }
            }

            foreach ($plan['renames'] as $sectionId => $name) {
                db_exec('UPDATE sections SET name = ? WHERE id = ?', [$name, $sectionId]);
            }

            // School years: the same label joins; others move in (active only if the school has no active year)
            $schoolHasActive = (bool) db_value('SELECT 1 FROM school_years WHERE workspace_id = ? AND is_active = 1', [$to]);
            foreach ($plan['yearMap'] as $old => $new) {
                if ($new) {
                    foreach ([['sections', 'school_year_id'], ['enrollments', 'school_year_id'], ['observed_values', 'school_year_id'], ['report_acknowledgements', 'school_year_id']] as [$table, $column]) {
                        db_exec("UPDATE IGNORE {$table} SET {$column} = ? WHERE {$column} = ?", [$new, $old]);
                    }
                    db_exec('DELETE FROM enrollments WHERE school_year_id = ?', [$old]); // learner already in a section that year at the school
                    db_exec('DELETE FROM observed_values WHERE school_year_id = ?', [$old]);
                    db_exec('DELETE FROM report_acknowledgements WHERE school_year_id = ?', [$old]);
                    db_exec('DELETE FROM grade_deadlines WHERE school_year_id = ?', [$old]); // the school's deadlines apply
                    db_exec('DELETE FROM school_years WHERE id = ?', [$old]);
                } else {
                    db_exec('UPDATE school_years SET workspace_id = ?, is_active = IF(?, 0, is_active) WHERE id = ?', [$to, $schoolHasActive ? 1 : 0, $old]);
                }
            }

            // Subjects: the same code (or name) joins; others move in
            foreach ($plan['subjectMap'] as $old => $new) {
                if ($new) {
                    db_exec('UPDATE class_assignments SET subject_id = ? WHERE subject_id = ?', [$new, $old]);
                    db_exec('DELETE FROM subjects WHERE id = ?', [$old]);
                } else {
                    db_exec('UPDATE subjects SET workspace_id = ? WHERE id = ?', [$to, $old]);
                }
            }

            // Sections: the chosen school section, or the teacher's section moves in
            foreach ($plan['sectionMap'] as $old => $new) {
                if ($new) {
                    db_exec('UPDATE class_assignments SET section_id = ? WHERE section_id = ?', [$new, $old]);
                    db_exec('UPDATE IGNORE enrollments SET section_id = ? WHERE section_id = ?', [$new, $old]);
                    db_exec('DELETE FROM enrollments WHERE section_id = ?', [$old]);
                    db_exec('DELETE FROM sections WHERE id = ?', [$old]);
                } else {
                    db_exec('UPDATE sections SET workspace_id = ? WHERE id = ?', [$to, $old]);
                }
            }

            // The other classes move in (their section and subject were updated above)
            db_exec('UPDATE class_assignments SET workspace_id = ? WHERE workspace_id = ?', [$to, $from]);

            // Learners: the same LRN joins the school's record; others move in
            foreach ($plan['studentMap'] as $old => $new) {
                if ($new) {
                    // one section per school year: the school's enrollment stays
                    db_exec(
                        'DELETE e FROM enrollments e JOIN enrollments mine ON mine.school_year_id = e.school_year_id AND mine.student_id = ?
                          WHERE e.student_id = ?',
                        [$new, $old]
                    );
                    db_exec('UPDATE enrollments SET student_id = ? WHERE student_id = ?', [$new, $old]);
                    $repoint(self::STUDENT_REFS, $old, $new);
                    db_exec('DELETE FROM students WHERE id = ?', [$old]);
                } else {
                    db_exec('UPDATE students SET workspace_id = ? WHERE id = ?', [$to, $old]);
                }
            }

            // Parents: the same mobile number or email joins the school's record; others move in
            foreach ($plan['parentMap'] as $old => $new) {
                if ($new) {
                    $repoint(self::PARENT_REFS, $old, $new);
                    db_exec("UPDATE document_requests SET requested_by_id = ? WHERE requested_by_type = 'parent' AND requested_by_id = ?", [$new, $old]);
                    db_exec('DELETE FROM parents WHERE id = ?', [$old]);
                } else {
                    db_exec('UPDATE parents SET workspace_id = ? WHERE id = ?', [$to, $old]);
                }
            }

            // Everything else that belongs to the classes and learners
            foreach (self::MOVE_TABLES as $table) {
                db_exec("UPDATE {$table} SET workspace_id = ? WHERE workspace_id = ?", [$to, $from]);
            }

            // The teacher's own sign-in limits for learners and parents don't carry over to the school
            db_exec('DELETE FROM access_blocks WHERE workspace_id = ?', [$from]);

            // The teacher becomes a staff account of the school; their own workspace closes
            db_exec('UPDATE users SET workspace_id = ?, role = ?, session_version = session_version + 1 WHERE id = ?', [$to, $role, $userId]);
            $school = (string) db_value('SELECT name FROM workspaces WHERE id = ?', [$to]);
            db_exec(
                "UPDATE workspaces SET status = 'suspended', suspended_reason = ? WHERE id = ?",
                ['The teacher moved their classes into ' . $school . ' on ' . date('F j, Y'), $from]
            );

            self::check($to, $from);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            throw $e;
        }
    }

    /** Stops (so everything is undone) when any link between tables is broken or crosses schools. */
    private static function check(int $to, int $from): void
    {
        $broken = [
            'enrollment without its learner or section' => 'SELECT COUNT(*) FROM enrollments e LEFT JOIN students st ON st.id = e.student_id
                LEFT JOIN sections s ON s.id = e.section_id AND s.school_year_id = e.school_year_id WHERE st.id IS NULL OR s.id IS NULL',
            'learner and section in different schools' => 'SELECT COUNT(*) FROM enrollments e JOIN students st ON st.id = e.student_id
                JOIN sections s ON s.id = e.section_id WHERE st.workspace_id <> s.workspace_id',
            'class with a section, subject or teacher of another school' => 'SELECT COUNT(*) FROM class_assignments ca
                LEFT JOIN sections s ON s.id = ca.section_id LEFT JOIN subjects sub ON sub.id = ca.subject_id LEFT JOIN users u ON u.id = ca.teacher_id
                WHERE s.id IS NULL OR sub.id IS NULL OR u.id IS NULL OR s.workspace_id <> ca.workspace_id OR sub.workspace_id <> ca.workspace_id OR u.workspace_id <> ca.workspace_id',
            'section without its school year' => 'SELECT COUNT(*) FROM sections s LEFT JOIN school_years sy ON sy.id = s.school_year_id
                WHERE sy.id IS NULL OR sy.workspace_id <> s.workspace_id',
            'grade without its learner or class' => 'SELECT COUNT(*) FROM grades g LEFT JOIN students st ON st.id = g.student_id
                LEFT JOIN class_assignments ca ON ca.id = g.class_assignment_id WHERE st.id IS NULL OR ca.id IS NULL OR st.workspace_id <> ca.workspace_id',
            'attendance without its learner or class meeting' => 'SELECT COUNT(*) FROM session_attendance sa LEFT JOIN students st ON st.id = sa.student_id
                LEFT JOIN class_sessions cs ON cs.id = sa.session_id WHERE st.id IS NULL OR cs.id IS NULL OR st.workspace_id <> cs.workspace_id',
            'class meeting without its class' => 'SELECT COUNT(*) FROM class_sessions cs LEFT JOIN class_assignments ca ON ca.id = cs.class_assignment_id
                WHERE ca.id IS NULL OR ca.workspace_id <> cs.workspace_id',
            'score without its learner or item' => 'SELECT COUNT(*) FROM assessment_scores x LEFT JOIN students st ON st.id = x.student_id
                LEFT JOIN assessments a ON a.id = x.assessment_id WHERE st.id IS NULL OR a.id IS NULL',
            'class record item without its class' => 'SELECT COUNT(*) FROM assessments a LEFT JOIN class_assignments ca ON ca.id = a.class_assignment_id WHERE ca.id IS NULL',
            'parent link across schools' => 'SELECT COUNT(*) FROM parent_students ps LEFT JOIN parents p ON p.id = ps.parent_id
                LEFT JOIN students st ON st.id = ps.student_id WHERE p.id IS NULL OR st.id IS NULL OR p.workspace_id <> st.workspace_id',
            'message thread across schools' => 'SELECT COUNT(*) FROM message_threads t LEFT JOIN parents p ON p.id = t.parent_id
                LEFT JOIN users u ON u.id = t.staff_id WHERE p.id IS NULL OR u.id IS NULL OR p.workspace_id <> t.workspace_id OR u.workspace_id <> t.workspace_id',
            'other records left behind' => 'SELECT (SELECT COUNT(*) FROM class_assignments WHERE workspace_id = ' . $from . ')
                + (SELECT COUNT(*) FROM students WHERE workspace_id = ' . $from . ') + (SELECT COUNT(*) FROM sections WHERE workspace_id = ' . $from . ')',
        ];
        foreach ($broken as $what => $sql) {
            $count = (int) db_value($sql);
            if ($count > 0) {
                throw new RuntimeException("Moving the classes stopped: {$count} × {$what}. Nothing was changed.");
            }
        }
    }
}
