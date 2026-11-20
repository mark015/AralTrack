<?php
// Phase 3: class record (Written Works / Performance Tasks / Quarterly Assessment), learning materials,
// parent–teacher messages, and document requests.
declare(strict_types=1);

return static function (PDO $pdo): void {
    // Component weights per subject (percent). Empty = DepEd default for the subject type.
    foreach (['ww_weight', 'pt_weight', 'qa_weight'] as $column) {
        if (!column_exists($pdo, 'subjects', $column)) {
            $pdo->exec("ALTER TABLE subjects ADD COLUMN {$column} DECIMAL(5,2) NULL");
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS assessments (
           id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           class_assignment_id INT UNSIGNED     NOT NULL,
           quarter             TINYINT UNSIGNED NOT NULL,
           component           ENUM('ww','pt','qa') NOT NULL,
           title               VARCHAR(120)     NOT NULL,
           max_score           DECIMAL(7,2)     NOT NULL,
           taken_on            DATE             NULL,
           created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_assessments_class (class_assignment_id, quarter),
           CONSTRAINT fk_assessments_class FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE
         ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS assessment_scores (
           assessment_id INT UNSIGNED NOT NULL,
           student_id    INT UNSIGNED NOT NULL,
           score         DECIMAL(7,2) NOT NULL,
           PRIMARY KEY (assessment_id, student_id),
           CONSTRAINT fk_scores_assessment FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE,
           CONSTRAINT fk_scores_student    FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
         ) ENGINE=InnoDB"
    );

    // Class announcements and learning materials (handouts, modules)
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS class_posts (
           id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           class_assignment_id INT UNSIGNED NOT NULL,
           title               VARCHAR(200) NOT NULL,
           body                TEXT         NULL,
           file_path           VARCHAR(255) NULL,
           file_name           VARCHAR(255) NULL,
           created_by          INT UNSIGNED NULL,
           created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_posts_class (class_assignment_id),
           CONSTRAINT fk_posts_class FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE,
           CONSTRAINT fk_posts_user  FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );

    // Parent ↔ teacher conversations
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS message_threads (
           id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           parent_id       INT UNSIGNED NOT NULL,
           staff_id        INT UNSIGNED NOT NULL,
           student_id      INT UNSIGNED NULL,
           subject         VARCHAR(200) NOT NULL,
           created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           last_message_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_threads_parent (parent_id, last_message_at),
           KEY idx_threads_staff (staff_id, last_message_at),
           CONSTRAINT fk_threads_parent  FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE CASCADE,
           CONSTRAINT fk_threads_staff   FOREIGN KEY (staff_id) REFERENCES users (id) ON DELETE CASCADE,
           CONSTRAINT fk_threads_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS messages (
           id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           thread_id   INT UNSIGNED NOT NULL,
           sender_type ENUM('parent','staff') NOT NULL,
           body        TEXT         NOT NULL,
           created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           read_at     DATETIME     NULL,
           KEY idx_messages_thread (thread_id, id),
           CONSTRAINT fk_messages_thread FOREIGN KEY (thread_id) REFERENCES message_threads (id) ON DELETE CASCADE
         ) ENGINE=InnoDB"
    );

    // Learners and parents ask the registrar for documents
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS document_requests (
           id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           student_id        INT UNSIGNED     NOT NULL,
           requested_by_type ENUM('learner','parent') NOT NULL,
           requested_by_id   INT UNSIGNED     NOT NULL,
           doc_type          VARCHAR(40)      NOT NULL,
           purpose           VARCHAR(255)     NOT NULL,
           copies            TINYINT UNSIGNED NOT NULL DEFAULT 1,
           status            ENUM('pending','processing','ready','released','rejected') NOT NULL DEFAULT 'pending',
           admin_note        VARCHAR(500)     NULL,
           handled_by        INT UNSIGNED     NULL,
           created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
           updated_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           KEY idx_requests_status (status),
           CONSTRAINT fk_requests_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
           CONSTRAINT fk_requests_user    FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );
};
