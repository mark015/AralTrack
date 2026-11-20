<?php
// QR attendance per subject class, parent accounts and parent notifications.
declare(strict_types=1);

return static function (PDO $pdo): void {
    // Each learner gets a secret QR code (for attendance) and a link code (for parents).
    if (!column_exists($pdo, 'students', 'qr_token')) {
        $pdo->exec('ALTER TABLE students ADD COLUMN qr_token CHAR(32) NULL AFTER birthdate');
    }
    if (!column_exists($pdo, 'students', 'parent_code')) {
        $pdo->exec('ALTER TABLE students ADD COLUMN parent_code CHAR(8) NULL AFTER qr_token');
    }
    $missing = $pdo->query('SELECT id FROM students WHERE qr_token IS NULL OR parent_code IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    $fill = $pdo->prepare('UPDATE students SET qr_token = COALESCE(qr_token, ?), parent_code = COALESCE(parent_code, ?) WHERE id = ?');
    foreach ($missing as $id) {
        $fill->execute([new_qr_token(), new_parent_code(), $id]);
    }
    $pdo->exec('ALTER TABLE students MODIFY qr_token CHAR(32) NOT NULL, MODIFY parent_code CHAR(8) NOT NULL');
    if (!index_exists($pdo, 'students', 'uq_students_qr')) {
        $pdo->exec('ALTER TABLE students ADD UNIQUE KEY uq_students_qr (qr_token)');
    }
    if (!index_exists($pdo, 'students', 'uq_students_parent_code')) {
        $pdo->exec('ALTER TABLE students ADD UNIQUE KEY uq_students_parent_code (parent_code)');
    }

    // One row per class meeting. Attendance is only counted for classes that actually met.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_sessions (
           id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           class_assignment_id INT UNSIGNED NOT NULL,
           session_date        DATE         NOT NULL,
           starts_at           DATETIME     NOT NULL,
           opened_by           INT UNSIGNED NULL,
           finished_at         DATETIME     NULL,
           created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_sessions_class (class_assignment_id, session_date),
           KEY idx_sessions_open (finished_at, session_date),
           CONSTRAINT fk_sessions_class FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE,
           CONSTRAINT fk_sessions_user  FOREIGN KEY (opened_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB'
    );

    // A learner's mark for one class meeting.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS session_attendance (
           session_id  INT UNSIGNED NOT NULL,
           student_id  INT UNSIGNED NOT NULL,
           status      ENUM('present','late','absent','excused') NOT NULL,
           source      ENUM('scan','manual','auto') NOT NULL,
           marked_at   DATETIME     NOT NULL,
           note        VARCHAR(255) NULL,
           recorded_by INT UNSIGNED NULL,
           PRIMARY KEY (session_id, student_id),
           KEY idx_attendance_student (student_id),
           CONSTRAINT fk_attendance_session FOREIGN KEY (session_id) REFERENCES class_sessions (id) ON DELETE CASCADE,
           CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
           CONSTRAINT fk_attendance_user    FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );

    // Every scan attempt, for troubleshooting ("I scanned but it didn't count").
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS scan_log (
           id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           session_id INT UNSIGNED NULL,
           user_id    INT UNSIGNED NULL,
           code       VARCHAR(100) NOT NULL,
           student_id INT UNSIGNED NULL,
           result     VARCHAR(20)  NOT NULL,
           created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_scan_session (session_id)
         ) ENGINE=InnoDB'
    );

    // Parent and guardian accounts. They sign in with a mobile number or email.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS parents (
           id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           full_name            VARCHAR(150) NOT NULL,
           login                VARCHAR(150) NOT NULL,
           password_hash        VARCHAR(255) NOT NULL,
           is_active            TINYINT(1)   NOT NULL DEFAULT 1,
           must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
           created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           UNIQUE KEY uq_parents_login (login)
         ) ENGINE=InnoDB'
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS parent_students (
           parent_id    INT UNSIGNED NOT NULL,
           student_id   INT UNSIGNED NOT NULL,
           relationship ENUM('mother','father','guardian') NOT NULL DEFAULT 'guardian',
           created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (parent_id, student_id),
           KEY idx_parent_students_student (student_id),
           CONSTRAINT fk_ps_parent  FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE CASCADE,
           CONSTRAINT fk_ps_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
         ) ENGINE=InnoDB"
    );

    // Notifications shown inside the parent portal (no email, no SMS).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS parent_notifications (
           id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           parent_id  INT UNSIGNED NOT NULL,
           student_id INT UNSIGNED NOT NULL,
           type       ENUM('absent','late','changed','grades') NOT NULL,
           message    VARCHAR(500) NOT NULL,
           link       VARCHAR(255) NOT NULL DEFAULT '',
           created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           read_at    DATETIME     NULL,
           KEY idx_notifications_parent (parent_id, read_at),
           CONSTRAINT fk_notifications_parent  FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE CASCADE,
           CONSTRAINT fk_notifications_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
         ) ENGINE=InnoDB"
    );
};
