<?php
// Phase 1: announcements and calendar, class schedules, excuse letters, grade deadlines,
// grade change requests and history, at-risk interventions, settings, learner passwords.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS settings (
           name  VARCHAR(64) NOT NULL PRIMARY KEY,
           value TEXT        NULL
         ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS announcements (
           id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           title      VARCHAR(200) NOT NULL,
           body       TEXT         NOT NULL,
           audience   ENUM('everyone','staff','families') NOT NULL DEFAULT 'everyone',
           pinned     TINYINT(1)   NOT NULL DEFAULT 0,
           posted_by  INT UNSIGNED NULL,
           created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_announcements_created (created_at),
           CONSTRAINT fk_announcements_user FOREIGN KEY (posted_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS calendar_events (
           id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           title      VARCHAR(200) NOT NULL,
           details    VARCHAR(500) NULL,
           type       ENUM('event','holiday','suspension','exam','meeting','deadline') NOT NULL DEFAULT 'event',
           starts_on  DATE         NOT NULL,
           ends_on    DATE         NOT NULL,
           created_by INT UNSIGNED NULL,
           created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_events_dates (starts_on, ends_on),
           CONSTRAINT fk_events_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );

    // Weekly timetable: weekday 1 = Monday … 7 = Sunday
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS class_schedules (
           id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           class_assignment_id INT UNSIGNED     NOT NULL,
           weekday             TINYINT UNSIGNED NOT NULL,
           starts_at           TIME             NOT NULL,
           ends_at             TIME             NOT NULL,
           room                VARCHAR(50)      NULL,
           KEY idx_schedule_class (class_assignment_id),
           KEY idx_schedule_day (weekday, starts_at),
           CONSTRAINT fk_schedule_class FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE,
           CONSTRAINT chk_schedule_day CHECK (weekday BETWEEN 1 AND 7)
         ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS excuse_letters (
           id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           student_id  INT UNSIGNED NOT NULL,
           parent_id   INT UNSIGNED NULL,
           date_from   DATE         NOT NULL,
           date_to     DATE         NOT NULL,
           reason      VARCHAR(1000) NOT NULL,
           file_path   VARCHAR(255) NULL,
           file_name   VARCHAR(255) NULL,
           file_mime   VARCHAR(100) NULL,
           status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
           reviewed_by INT UNSIGNED NULL,
           reviewed_at DATETIME     NULL,
           review_note VARCHAR(500) NULL,
           created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_excuse_student (student_id, status),
           CONSTRAINT fk_excuse_student  FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
           CONSTRAINT fk_excuse_parent   FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE SET NULL,
           CONSTRAINT fk_excuse_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );

    // Last day teachers can change each quarter's grades (per school year)
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS grade_deadlines (
           school_year_id INT UNSIGNED     NOT NULL,
           quarter        TINYINT UNSIGNED NOT NULL,
           deadline       DATE             NOT NULL,
           PRIMARY KEY (school_year_id, quarter),
           CONSTRAINT fk_deadline_year FOREIGN KEY (school_year_id) REFERENCES school_years (id) ON DELETE CASCADE
         ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS grade_change_requests (
           id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           student_id          INT UNSIGNED     NOT NULL,
           class_assignment_id INT UNSIGNED     NOT NULL,
           quarter             TINYINT UNSIGNED NOT NULL,
           old_grade           DECIMAL(5,2)     NULL,
           new_grade           DECIMAL(5,2)     NULL,
           reason              VARCHAR(1000)    NOT NULL,
           status              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
           requested_by        INT UNSIGNED     NULL,
           reviewed_by         INT UNSIGNED     NULL,
           reviewed_at         DATETIME         NULL,
           review_note         VARCHAR(500)     NULL,
           created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_gcr_status (status),
           CONSTRAINT fk_gcr_student  FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
           CONSTRAINT fk_gcr_class    FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE,
           CONSTRAINT fk_gcr_by       FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
           CONSTRAINT fk_gcr_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );

    // Every grade change: who, when, old → new, and how
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS grade_history (
           id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           student_id          INT UNSIGNED     NOT NULL,
           class_assignment_id INT UNSIGNED     NOT NULL,
           quarter             TINYINT UNSIGNED NOT NULL,
           old_grade           DECIMAL(5,2)     NULL,
           new_grade           DECIMAL(5,2)     NULL,
           source              ENUM('grid','upload','change_request','class_record') NOT NULL,
           changed_by          INT UNSIGNED     NULL,
           created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_history_class (class_assignment_id, quarter),
           KEY idx_history_student (student_id),
           CONSTRAINT fk_history_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
           CONSTRAINT fk_history_class   FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE,
           CONSTRAINT fk_history_user    FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );

    // Follow-up notes for at-risk learners
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS interventions (
           id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           student_id          INT UNSIGNED  NOT NULL,
           class_assignment_id INT UNSIGNED  NULL,
           note                VARCHAR(1000) NOT NULL,
           created_by          INT UNSIGNED  NULL,
           created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_interventions_student (student_id),
           CONSTRAINT fk_interventions_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
           CONSTRAINT fk_interventions_class   FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE SET NULL,
           CONSTRAINT fk_interventions_user    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );

    if (!column_exists($pdo, 'students', 'password_hash')) {
        $pdo->exec('ALTER TABLE students ADD COLUMN password_hash VARCHAR(255) NULL AFTER parent_code');
    }
    if (!column_exists($pdo, 'parents', 'privacy_accepted_at')) {
        $pdo->exec('ALTER TABLE parents ADD COLUMN privacy_accepted_at DATETIME NULL AFTER must_change_password');
    }

    // Notifications can now be school-wide (announcements), not only about one learner.
    $pdo->exec('ALTER TABLE parent_notifications MODIFY student_id INT UNSIGNED NULL');
    $pdo->exec(
        "ALTER TABLE parent_notifications MODIFY type
           ENUM('absent','late','changed','grades','announcement','excuse','request','message') NOT NULL"
    );
};
