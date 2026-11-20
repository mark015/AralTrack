<?php
/**
 * Demo data for trying the portal: staff, a Senior High STEM load, learners and grades
 * (seed_demo_school), plus parent accounts and two weeks of class attendance
 * (seed_demo_attendance). Used by install.php --seed and demo-attendance.php.
 */
declare(strict_types=1);

const DEMO_SUMMARY = <<<TXT

Demo data added (Senior High, S.Y. 2025-2026 and 2026-2027, 20 learners, grades, 2 weeks of attendance).

Staff sign-in (http://localhost/student-portal/staff/login.php)
  superadmin / super123  Super Admin: control panel (parts of the portal, sign-in), accounts, activity log
  admin    / admin123     registrar (admin)
  msantos  / teacher123   teacher: math and science subjects; adviser of Grade 12 STEM – Einstein
  jreyes   / teacher123   teacher: language, social science and PE subjects; adviser of Grade 11 STEM – Newton
  principal / principal123 principal: reports, records, grade change approvals
  guidance / guidance123  guidance counselor: learner profiles, at-risk learners, excuse letters

Parent sign-in (http://localhost/student-portal/parent/login.php)
  09171234567              / parent123   Maria Dela Cruz, mother of Juan (Grade 12)
  ramon.aquino@example.com / parent123   Ramon Aquino, father of Rafael (Grade 11)

Learner sign-in (http://localhost/student-portal/)
  LRN 100000260001  (Juan Dela Cruz, Grade 12 STEM – Einstein)
  LRN 100000260011  (Rafael Aquino, Grade 11 STEM – Newton)

These passwords are for testing only.
TXT;

function seed_demo_school(PDO $pdo): void
{
    $staff = [
        // username, password, full name, role
        ['superadmin', 'super123', 'System Administrator', 'superadmin'],
        ['admin', 'admin123', 'School Registrar', 'admin'],
        ['msantos', 'teacher123', 'Maria Santos', 'teacher'],
        ['jreyes', 'teacher123', 'Jose Reyes', 'teacher'],
        ['principal', 'principal123', 'Elena Ramos', 'principal'],
        ['guidance', 'guidance123', 'Carlo Mendoza', 'guidance'],
    ];
    $userId = [];
    $insert = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)');
    foreach ($staff as [$username, $password, $name, $role]) {
        $insert->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, $role]);
        $userId[$username] = (int) $pdo->lastInsertId();
    }

    $pdo->exec("INSERT INTO school_years (label, is_active) VALUES ('2025-2026', 0), ('2026-2027', 1)");
    $yearId = array_column($pdo->query('SELECT id, label FROM school_years')->fetchAll(), 'id', 'label');

    // Sample Senior High subjects (edit them under Admin → Subjects).
    // code, name, type, order, semester, teacher
    $subjects = [
        // Grade 11
        ['ORALCOM', 'Oral Communication in Context', 'core', 1, 1, 'jreyes'],
        ['KOMPAN', 'Komunikasyon at Pananaliksik sa Wika at Kulturang Pilipino', 'core', 2, 1, 'jreyes'],
        ['GENMATH', 'General Mathematics', 'core', 3, 1, 'msantos'],
        ['EARTHSCI', 'Earth Science', 'core', 4, 1, 'msantos'],
        ['PEH1', 'Physical Education and Health 1', 'core', 5, 1, 'jreyes'],
        ['EMPTECH', 'Empowerment Technologies', 'applied', 6, 1, 'msantos'],
        ['PRECAL', 'Pre-Calculus', 'specialized', 7, 1, 'msantos'],
        ['RWS', 'Reading and Writing Skills', 'core', 8, 2, 'jreyes'],
        ['PAGBASA', 'Pagbasa at Pagsusuri ng Iba\'t Ibang Teksto Tungo sa Pananaliksik', 'core', 9, 2, 'jreyes'],
        ['STATPROB', 'Statistics and Probability', 'core', 10, 2, 'msantos'],
        ['PHILO', 'Introduction to the Philosophy of the Human Person', 'core', 11, 2, 'jreyes'],
        ['PEH2', 'Physical Education and Health 2', 'core', 12, 2, 'jreyes'],
        ['EAPP', 'English for Academic and Professional Purposes', 'applied', 13, 2, 'jreyes'],
        ['BASICCAL', 'Basic Calculus', 'specialized', 14, 2, 'msantos'],
        // Grade 12
        ['LIT21', '21st Century Literature from the Philippines and the World', 'core', 15, 1, 'jreyes'],
        ['UCSP', 'Understanding Culture, Society and Politics', 'core', 16, 1, 'jreyes'],
        ['PEH3', 'Physical Education and Health 3', 'core', 17, 1, 'jreyes'],
        ['PR2', 'Practical Research 2', 'applied', 18, 1, 'msantos'],
        ['GENPHYS1', 'General Physics 1', 'specialized', 19, 1, 'msantos'],
        ['GENCHEM1', 'General Chemistry 1', 'specialized', 20, 1, 'msantos'],
        ['MIL', 'Media and Information Literacy', 'core', 21, 2, 'jreyes'],
        ['CPAR', 'Contemporary Philippine Arts from the Regions', 'core', 22, 2, 'jreyes'],
        ['PEH4', 'Physical Education and Health 4', 'core', 23, 2, 'jreyes'],
        ['ENTREP', 'Entrepreneurship', 'applied', 24, 2, 'msantos'],
        ['GENPHYS2', 'General Physics 2', 'specialized', 25, 2, 'msantos'],
        ['GENBIO2', 'General Biology 2', 'specialized', 26, 2, 'msantos'],
    ];
    $grade11 = array_slice($subjects, 0, 14);
    $grade12 = array_slice($subjects, 14);
    $subjectId = [];
    $insert = $pdo->prepare('INSERT INTO subjects (code, name, type, sort_order) VALUES (?, ?, ?, ?)');
    foreach ($subjects as [$code, $name, $type, $order]) {
        $insert->execute([$code, $name, $type, $order]);
        $subjectId[$code] = (int) $pdo->lastInsertId();
    }

    // key => [school year, grade level, strand, name, adviser, subjects]
    $sections = [
        'einstein11' => ['2025-2026', 11, 'STEM', 'Einstein', 'msantos', $grade11],
        'einstein12' => ['2026-2027', 12, 'STEM', 'Einstein', 'msantos', $grade12],
        'newton11'   => ['2026-2027', 11, 'STEM', 'Newton', 'jreyes', $grade11],
    ];
    $sectionId = [];
    $classes = []; // section key => list of [class id, teacher id, semester]
    $insertSection = $pdo->prepare('INSERT INTO sections (school_year_id, grade_level, strand, name, adviser_id) VALUES (?, ?, ?, ?, ?)');
    $insertClass = $pdo->prepare('INSERT INTO class_assignments (section_id, subject_id, teacher_id, semester) VALUES (?, ?, ?, ?)');
    foreach ($sections as $key => [$year, $level, $strand, $name, $adviser, $sectionSubjects]) {
        $insertSection->execute([$yearId[$year], $level, $strand, $name, $userId[$adviser]]);
        $sectionId[$key] = (int) $pdo->lastInsertId();
        foreach ($sectionSubjects as [$code, , , , $semester, $teacher]) {
            $insertClass->execute([$sectionId[$key], $subjectId[$code], $userId[$teacher], $semester]);
            $classes[$key][] = [(int) $pdo->lastInsertId(), $userId[$teacher], $semester];
        }
    }

    // Fictional learners. LRN, last, first, middle, sex, birthdate
    // The first 10 are Grade 12 this year (Grade 11 last year); the rest are Grade 11.
    $learners = [
        ['100000260001', 'Dela Cruz', 'Juan', 'Santos', 'M', '2009-03-14'],
        ['100000260002', 'Reyes', 'Maria Clara', 'Lopez', 'F', '2009-07-02'],
        ['100000260003', 'Bautista', 'Miguel', 'Ramos', 'M', '2008-11-23'],
        ['100000260004', 'Garcia', 'Andrea', 'Villanueva', 'F', '2009-01-30'],
        ['100000260005', 'Mendoza', 'Paolo', 'Cruz', 'M', '2009-05-18'],
        ['100000260006', 'Torres', 'Bea', 'Aquino', 'F', '2009-09-09'],
        ['100000260007', 'Flores', 'Carlo', 'Navarro', 'M', '2008-12-05'],
        ['100000260008', 'Castillo', 'Isabel', 'Domingo', 'F', '2009-02-27'],
        ['100000260009', 'Ramos', 'Gabriel', 'Mercado', 'M', '2009-08-11'],
        ['100000260010', 'Peña', 'Sofia', 'Salazar', 'F', '2009-04-21'],
        ['100000260011', 'Aquino', 'Rafael', 'Ignacio', 'M', '2010-06-06'],
        ['100000260012', 'Villanueva', 'Camille', 'Rivera', 'F', '2009-10-15'],
        ['100000260013', 'Navarro', 'Joshua', 'Pascual', 'M', '2010-03-03'],
        ['100000260014', 'Santiago', 'Angela', 'Morales', 'F', '2010-11-28'],
        ['100000260015', 'Gonzales', 'Daniel', 'Fernandez', 'M', '2010-01-12'],
        ['100000260016', 'Rivera', 'Nicole', 'De Leon', 'F', '2010-05-25'],
        ['100000260017', 'Lim', 'Christian', 'Tan', 'M', '2009-09-30'],
        ['100000260018', 'Morales', 'Patricia', 'Ocampo', 'F', '2010-07-19'],
        ['100000260019', 'Fernandez', 'Mark', 'Valdez', 'M', '2010-02-08'],
        ['100000260020', 'Ocampo', 'Jasmine', 'Castro', 'F', '2010-10-01'],
    ];
    $insertStudent = $pdo->prepare(
        'INSERT INTO students (lrn, last_name, first_name, middle_name, sex, birthdate, qr_token, parent_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $enroll = $pdo->prepare('INSERT INTO enrollments (student_id, section_id, school_year_id) VALUES (?, ?, ?)');
    $studentIds = [];
    foreach ($learners as $i => $learner) {
        $insertStudent->execute([...$learner, new_qr_token(), new_parent_code()]);
        $id = (int) $pdo->lastInsertId();
        $studentIds[$i] = $id;
        if ($i < 10) {
            $enroll->execute([$id, $sectionId['einstein11'], $yearId['2025-2026']]);
            $enroll->execute([$id, $sectionId['einstein12'], $yearId['2026-2027']]);
        } else {
            $enroll->execute([$id, $sectionId['newton11'], $yearId['2026-2027']]);
        }
    }

    // Grades:
    //  - Grade 11 Einstein (last year): both semesters complete and published
    //  - Grade 12 Einstein (this year): 1st semester complete and published
    //  - Grade 11 Newton (this year): Q1 published; Q2 entered for Santos's subjects but not yet published
    mt_srand(2026);
    $insertGrade = $pdo->prepare('INSERT INTO grades (student_id, class_assignment_id, quarter, grade, updated_by) VALUES (?, ?, ?, ?, ?)');
    $publish = $pdo->prepare('INSERT IGNORE INTO grade_publications (class_assignment_id, quarter, published_by) VALUES (?, ?, ?)');
    $base = [];
    foreach ($studentIds as $i => $id) {
        $base[$i] = $i === 6 ? 74 : mt_rand(78, 94); // one learner near the passing line
    }
    $grade = static fn (int $i): int => max(65, min(99, $base[$i] + mt_rand(-4, 4)));

    $plan = [
        // section, learners, quarters, only this teacher's subjects, publish?
        ['einstein11', range(0, 9), [1, 2, 3, 4], null, true],
        ['einstein12', range(0, 9), [1, 2], null, true],
        ['newton11', range(10, 19), [1], null, true],
        ['newton11', range(10, 19), [2], 'msantos', false],
    ];
    foreach ($plan as [$key, $learnerIndexes, $quarters, $onlyTeacher, $publishIt]) {
        foreach ($classes[$key] as [$classId, $teacherId, $semester]) {
            if ($onlyTeacher !== null && $teacherId !== $userId[$onlyTeacher]) {
                continue;
            }
            foreach (array_intersect($quarters, semester_quarters($semester)) as $q) {
                foreach ($learnerIndexes as $i) {
                    $insertGrade->execute([$studentIds[$i], $classId, $q, $grade($i), $teacherId]);
                }
                if ($publishIt) {
                    $publish->execute([$classId, $q, $teacherId]);
                }
            }
        }
    }
}

/**
 * Two demo parent accounts, and the last 10 school days of class attendance for the
 * active school year's 1st-semester classes, with the matching parent notifications.
 * Needs the demo learners from seed_demo_school(). Returns false if it can't run.
 */
function seed_demo_attendance(PDO $pdo): bool
{
    $findStudent = $pdo->prepare('SELECT * FROM students WHERE lrn = ?');
    $findStudent->execute(['100000260001']);
    $juan = $findStudent->fetch();
    $findStudent->execute(['100000260011']);
    $rafael = $findStudent->fetch();
    $syId = $pdo->query('SELECT id FROM school_years WHERE is_active = 1')->fetchColumn();
    if (!$juan || !$rafael || !$syId) {
        echo "The demo learners or the active school year were not found, so no demo attendance was added.\n";
        return false;
    }
    $exists = $pdo->prepare('SELECT COUNT(*) FROM parents WHERE login IN (?, ?)');
    $exists->execute(['09171234567', 'ramon.aquino@example.com']);
    if ((int) $exists->fetchColumn() > 0) {
        echo "The demo parent accounts already exist, so no demo attendance was added.\n";
        return false;
    }

    // Parents
    $insertParent = $pdo->prepare('INSERT INTO parents (full_name, login, password_hash) VALUES (?, ?, ?)');
    $link = $pdo->prepare('INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)');
    $parentOf = [];
    foreach ([['Maria Dela Cruz', '09171234567', $juan, 'mother'], ['Ramon Aquino', 'ramon.aquino@example.com', $rafael, 'father']] as [$name, $login, $child, $relationship]) {
        $insertParent->execute([$name, $login, password_hash('parent123', PASSWORD_DEFAULT)]);
        $parentId = (int) $pdo->lastInsertId();
        $link->execute([$parentId, $child['id'], $relationship]);
        $parentOf[(int) $child['id']] = $parentId;
    }
    $firstName = [(int) $juan['id'] => $juan['first_name'], (int) $rafael['id'] => $rafael['first_name']];

    // The 10 school days (Mon–Fri) before today
    $days = [];
    for ($d = new DateTimeImmutable('yesterday'); count($days) < 10; $d = $d->modify('-1 day')) {
        if ((int) $d->format('N') <= 5) {
            $days[] = $d->format('Y-m-d');
        }
    }
    $days = array_reverse($days);
    $lastDay = end($days);
    $startTimes = ['07:30', '08:30', '09:30', '10:30', '13:00', '14:00', '15:00'];

    $insertSession = $pdo->prepare('INSERT INTO class_sessions (class_assignment_id, session_date, starts_at, opened_by, finished_at) VALUES (?, ?, ?, ?, ?)');
    $insertMark = $pdo->prepare('INSERT INTO session_attendance (session_id, student_id, status, source, marked_at, note, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $notify = $pdo->prepare('INSERT INTO parent_notifications (parent_id, student_id, type, message, link, created_at, read_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $twoDaysAgo = date('Y-m-d', strtotime('-2 days'));

    mt_srand(77);
    $sections = $pdo->prepare('SELECT id FROM sections WHERE school_year_id = ?');
    $sections->execute([$syId]);
    foreach ($sections->fetchAll(PDO::FETCH_COLUMN) as $sectionId) {
        $classes = $pdo->prepare(
            'SELECT ca.id, ca.teacher_id, sub.name FROM class_assignments ca JOIN subjects sub ON sub.id = ca.subject_id
              WHERE ca.section_id = ? AND ca.semester = 1 ORDER BY sub.sort_order'
        );
        $classes->execute([$sectionId]);
        $classes = $classes->fetchAll();
        $roster = $pdo->prepare('SELECT student_id FROM enrollments WHERE section_id = ?');
        $roster->execute([$sectionId]);
        $roster = array_map('intval', $roster->fetchAll(PDO::FETCH_COLUMN));

        foreach ($days as $day) {
            foreach ($classes as $k => $class) {
                $start = strtotime("{$day} {$startTimes[$k % count($startTimes)]}:00");
                $finished = date('Y-m-d H:i:s', $start + 3600);
                $insertSession->execute([$class['id'], $day, date('Y-m-d H:i:s', $start), $class['teacher_id'], $finished]);
                $sessionId = (int) $pdo->lastInsertId();

                foreach ($roster as $studentId) {
                    $roll = mt_rand(1, 100);
                    $status = $roll <= 88 ? 'present' : ($roll <= 94 ? 'late' : ($roll <= 98 ? 'absent' : 'excused'));
                    if ($studentId === (int) $rafael['id'] && $day === $lastDay) {
                        $status = [0 => 'absent', 2 => 'late'][$k] ?? 'present'; // something for his father to see
                    }
                    [$source, $markedAt, $note] = match ($status) {
                        'present' => ['scan', $start + mt_rand(0, 9) * 60, null],
                        'late' => ['scan', $start + mt_rand(16, 35) * 60, null],
                        'absent' => ['auto', $start + 3600, null],
                        'excused' => ['manual', $start + 3600, 'School activity (journalism contest)'],
                    };
                    $insertMark->execute([$sessionId, $studentId, $status, $source, date('Y-m-d H:i:s', $markedAt), $note, $class['teacher_id']]);

                    if (isset($parentOf[$studentId]) && in_array($status, ['late', 'absent'], true)) {
                        $message = $status === 'late'
                            ? sprintf('%s was late to %s: arrived %s, class started %s.', $firstName[$studentId], $class['name'], date('g:i A', $markedAt), date('g:i A', $start))
                            : sprintf('%s was marked absent in %s (%s).', $firstName[$studentId], $class['name'], date('M j, g:i A', $start));
                        $created = date('Y-m-d H:i:s', $markedAt);
                        $readAt = $day < $twoDaysAgo ? date('Y-m-d H:i:s', $markedAt + 3600) : null;
                        $notify->execute([$parentOf[$studentId], $studentId, $status, $message, "parent/child.php?id={$studentId}&month=" . substr($day, 0, 7), $created, $readAt]);
                    }
                }
            }
        }
    }

    // A "grades released" notice for Juan's mother
    $notify->execute([
        $parentOf[(int) $juan['id']], $juan['id'], 'grades', 'Quarter 2 grades for General Physics 1 are now available.',
        'parent/child.php?tab=grades&id=' . $juan['id'], date('Y-m-d H:i:s', strtotime('-5 days 16:00')), date('Y-m-d H:i:s', strtotime('-5 days 18:00')),
    ]);
    return true;
}

/**
 * Demo content for the newer features: a weekly timetable for this school year's sections,
 * announcements, calendar events and quarter deadlines. Safe to run again (it replaces its own rows).
 */
function seed_demo_extras(PDO $pdo): void
{
    $syId = (int) $pdo->query('SELECT id FROM school_years WHERE is_active = 1')->fetchColumn();
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'superadmin') ORDER BY id LIMIT 1")->fetchColumn() ?: null;
    if (!$syId) {
        return;
    }

    // Timetable: 7 periods; every class meets Monday–Thursday, the first four also on Friday.
    $periods = [['07:30', '08:30'], ['08:30', '09:30'], ['09:45', '10:45'], ['10:45', '11:45'], ['13:00', '14:00'], ['14:00', '15:00'], ['15:00', '16:00']];
    $sections = $pdo->prepare('SELECT id, name FROM sections WHERE school_year_id = ?');
    $sections->execute([$syId]);
    $classes = $pdo->prepare('SELECT ca.id, ca.semester FROM class_assignments ca JOIN subjects s ON s.id = ca.subject_id WHERE ca.section_id = ? ORDER BY ca.semester, s.sort_order');
    $clear = $pdo->prepare('DELETE cs FROM class_schedules cs JOIN class_assignments ca ON ca.id = cs.class_assignment_id WHERE ca.section_id = ?');
    $insert = $pdo->prepare('INSERT INTO class_schedules (class_assignment_id, weekday, starts_at, ends_at, room) VALUES (?, ?, ?, ?, ?)');
    foreach ($sections->fetchAll() as $i => $section) {
        $clear->execute([$section['id']]);
        $classes->execute([$section['id']]);
        $bySemester = [];
        foreach ($classes->fetchAll() as $class) {
            $bySemester[(int) $class['semester']][] = (int) $class['id'];
        }
        $room = (string) (201 + $i * 100);
        foreach ($bySemester as $classIds) {
            foreach ($classIds as $p => $classId) {
                if (!isset($periods[$p])) {
                    continue;
                }
                foreach ($p < 4 ? [1, 2, 3, 4, 5] : [1, 2, 3, 4] as $day) {
                    $insert->execute([$classId, $day, $periods[$p][0] . ':00', $periods[$p][1] . ':00', $room]);
                }
            }
        }
    }

    // Quarter deadlines: Q1 is over (locked), Q2 ends before the Christmas break.
    $deadline = $pdo->prepare('INSERT INTO grade_deadlines (school_year_id, quarter, deadline) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE deadline = VALUES(deadline)');
    foreach ([1 => '2026-10-30', 2 => '2026-12-18', 3 => '2027-03-12', 4 => '2027-05-21'] as $q => $date) {
        $deadline->execute([$syId, $q, $date]);
    }

    // Calendar
    $pdo->exec("DELETE FROM calendar_events WHERE details LIKE '%(demo)%'");
    $event = $pdo->prepare('INSERT INTO calendar_events (title, details, type, starts_on, ends_on, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([
        ['Parent-Teacher Conference', '1st semester report cards, 1:00 PM in each classroom (demo)', 'meeting', '2026-11-28', '2026-11-28'],
        ['Bonifacio Day', 'Regular holiday, no classes (demo)', 'holiday', '2026-11-30', '2026-11-30'],
        ['2nd Quarter examinations', 'Schedule posted by each adviser (demo)', 'exam', '2026-12-14', '2026-12-17'],
        ['Q2 grade submission deadline', 'Grades lock the next day (demo)', 'deadline', '2026-12-18', '2026-12-18'],
        ['Christmas break', 'Classes resume January 4 (demo)', 'holiday', '2026-12-19', '2027-01-03'],
    ] as [$title, $details, $type, $from, $to]) {
        $event->execute([$title, $details, $type, $from, $to, $adminId]);
    }

    // Announcements (only if there are none yet)
    if (!(int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn()) {
        $post = $pdo->prepare('INSERT INTO announcements (title, body, audience, pinned, posted_by, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $post->execute(['Parent-Teacher Conference on Nov 28', "1st semester report cards will be discussed.\nPlease come to your child's classroom at 1:00 PM.", 'everyone', 1, $adminId, date('Y-m-d H:i:s', strtotime('-2 days'))]);
        $post->execute(['QR ID cards are now used for class attendance', 'Subject teachers scan your QR ID at the start of every class. Bring your ID every day. Lost cards can be replaced at the registrar.', 'families', 0, $adminId, date('Y-m-d H:i:s', strtotime('-6 days'))]);
        $post->execute(['Q2 grades due December 18', 'Please upload and publish Quarter 2 grades by December 18. After that, changes need a change request.', 'staff', 0, $adminId, date('Y-m-d H:i:s', strtotime('-4 days'))]);
    }
}

/** Demo class record, class posts, a parent–teacher conversation and a document request (Phase 3 features). */
function seed_demo_classroom(PDO $pdo): void
{
    $one = static function (string $sql, array $params = []) use ($pdo): mixed {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    };
    $class = static fn (string $code, string $section): int => (int) $one(
        'SELECT ca.id FROM class_assignments ca JOIN subjects s ON s.id = ca.subject_id JOIN sections sec ON sec.id = ca.section_id
           JOIN school_years sy ON sy.id = sec.school_year_id AND sy.is_active = 1 WHERE s.code = ? AND sec.name = ?',
        [$code, $section]
    );

    // Class record: Quarter 2 of General Physics 1 (Einstein) and General Mathematics (Newton)
    mt_srand(2026);
    $addAssessment = $pdo->prepare('INSERT INTO assessments (class_assignment_id, quarter, component, title, max_score, taken_on) VALUES (?, 2, ?, ?, ?, ?)');
    $addScore = $pdo->prepare('INSERT INTO assessment_scores (assessment_id, student_id, score) VALUES (?, ?, ?)');
    $roster = $pdo->prepare('SELECT e.student_id FROM enrollments e JOIN class_assignments ca ON ca.section_id = e.section_id WHERE ca.id = ?');
    foreach (['GENPHYS1' => 'Einstein', 'GENMATH' => 'Newton'] as $code => $section) {
        $classId = $class($code, $section);
        if (!$classId || (int) $one('SELECT COUNT(*) FROM assessments WHERE class_assignment_id = ?', [$classId])) {
            continue;
        }
        $roster->execute([$classId]);
        $learners = array_map('intval', $roster->fetchAll(PDO::FETCH_COLUMN));
        $ability = [];
        foreach ($learners as $sid) {
            $ability[$sid] = mt_rand(62, 97) / 100;
        }
        foreach ([
            ['ww', 'Quiz 1', 20, '2026-11-03'], ['ww', 'Quiz 2', 25, '2026-11-10'], ['ww', 'Seatwork', 15, '2026-11-13'],
            ['pt', 'Performance task 1', 50, '2026-11-06'], ['pt', 'Group output', 40, '2026-11-17'],
            ['qa', 'Quarterly examination', 60, null],
        ] as [$component, $title, $max, $date]) {
            $addAssessment->execute([$classId, $component, $title, $max, $date]);
            $assessmentId = (int) $pdo->lastInsertId();
            if ($component === 'qa') {
                continue; // the exam is not given yet
            }
            foreach ($learners as $sid) {
                $score = round(min($max, max(0, $max * ($ability[$sid] + mt_rand(-10, 8) / 100))));
                $addScore->execute([$assessmentId, $sid, $score]);
            }
        }
    }

    // Class posts
    if (!(int) $one('SELECT COUNT(*) FROM class_posts')) {
        $post = $pdo->prepare('INSERT INTO class_posts (class_assignment_id, title, body, created_by, created_at) SELECT ca.id, ?, ?, ca.teacher_id, ? FROM class_assignments ca WHERE ca.id = ?');
        foreach ([
            ['GENPHYS1', 'Einstein', 'Module 4: Work, Energy and Power', "Read Module 4, pages 45–60, and answer the activity on page 61.\nSubmit your answers on Friday.", '-3 days'],
            ['GENPHYS1', 'Einstein', 'Reminder: bring a scientific calculator', 'We will solve problems in class on Monday.', '-1 day'],
            ['GENMATH', 'Newton', 'Reviewer for the Q2 exam', 'Topics: simple and compound interest, annuities, and loans. Practice items will be given in class.', '-2 days'],
        ] as [$code, $section, $title, $body, $when]) {
            $post->execute([$title, $body, date('Y-m-d H:i:s', strtotime($when)), $class($code, $section)]);
        }
    }

    // A conversation between Maria Dela Cruz (parent) and Juan's adviser
    $parentId = (int) $one("SELECT id FROM parents WHERE login = '09171234567'");
    $staffId = (int) $one("SELECT id FROM users WHERE username = 'msantos'");
    $juan = (int) $one("SELECT id FROM students WHERE lrn = '100000260001'");
    if ($parentId && $staffId && $juan && !(int) $one('SELECT COUNT(*) FROM message_threads')) {
        $pdo->prepare('INSERT INTO message_threads (parent_id, staff_id, student_id, subject, created_at, last_message_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$parentId, $staffId, $juan, 'Missed quiz on Monday', date('Y-m-d H:i:s', strtotime('-1 day 09:10')), date('Y-m-d H:i:s', strtotime('-1 day 13:25'))]);
        $threadId = (int) $pdo->lastInsertId();
        $message = $pdo->prepare('INSERT INTO messages (thread_id, sender_type, body, created_at, read_at) VALUES (?, ?, ?, ?, ?)');
        $message->execute([$threadId, 'parent', "Good morning po, Ma'am. Juan had a fever on Monday so he missed the General Physics quiz. May he take it this week?", date('Y-m-d H:i:s', strtotime('-1 day 09:10')), date('Y-m-d H:i:s', strtotime('-1 day 13:20'))]);
        $message->execute([$threadId, 'staff', 'Good afternoon po. Yes, Juan can take the quiz on Thursday during our class. Please also send an excuse letter through the portal.', date('Y-m-d H:i:s', strtotime('-1 day 13:25')), null]);
    }

    // A document request waiting for the registrar
    if ($juan && $parentId && !(int) $one('SELECT COUNT(*) FROM document_requests')) {
        $pdo->prepare("INSERT INTO document_requests (student_id, requested_by_type, requested_by_id, doc_type, purpose, copies) VALUES (?, 'parent', ?, 'good_moral', 'College admission', 1)")
            ->execute([$juan, $parentId]);
    }
}
