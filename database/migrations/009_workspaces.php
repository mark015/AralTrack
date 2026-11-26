<?php
// One system, many schools and teachers. Each school (or teacher working alone) has a workspace, and every
// record that belongs to a school is tagged with its workspace, so one school can never see another's data.
// The school already in this database becomes workspace 1: nothing changes for its users.
// The platform owner (the company running the system) has separate accounts in platform_owners.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS workspaces (
           id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           type             ENUM('school','teacher') NOT NULL DEFAULT 'school',
           name             VARCHAR(150) NOT NULL,
           status           ENUM('active','suspended') NOT NULL DEFAULT 'active',
           suspended_reason VARCHAR(255) NULL,
           plan             VARCHAR(30)  NOT NULL DEFAULT 'free',
           contact_name     VARCHAR(150) NULL,
           contact_email    VARCHAR(150) NULL,
           contact_phone    VARCHAR(30)  NULL,
           notes            TEXT         NULL,
           created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_workspaces_status (status)
         ) ENGINE=InnoDB"
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS platform_owners (
           id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           username        VARCHAR(50)  NOT NULL,
           password_hash   VARCHAR(255) NOT NULL,
           full_name       VARCHAR(150) NOT NULL,
           is_active       TINYINT(1)   NOT NULL DEFAULT 1,
           session_version INT UNSIGNED NOT NULL DEFAULT 0,
           last_login_at   DATETIME     NULL,
           created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           UNIQUE KEY uq_platform_owners_username (username)
         ) ENGINE=InnoDB'
    );

    // What the platform owner did: schools added or suspended, "open as school", owner sign-ins
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS platform_log (
           id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           owner_id     INT UNSIGNED NULL,
           owner_name   VARCHAR(150) NOT NULL,
           workspace_id INT UNSIGNED NULL,
           action       VARCHAR(60)  NOT NULL,
           summary      VARCHAR(500) NOT NULL,
           ip           VARCHAR(45)  NULL,
           created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_platform_log_time (created_at)
         ) ENGINE=InnoDB'
    );

    // "Request a demo" messages sent from the public home page, read by the platform owner
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS platform_leads (
           id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           name        VARCHAR(150) NOT NULL,
           kind        ENUM('school','teacher','other') NOT NULL DEFAULT 'school',
           school_name VARCHAR(150) NULL,
           email       VARCHAR(150) NULL,
           phone       VARCHAR(30)  NULL,
           learners    VARCHAR(30)  NULL,
           message     TEXT         NULL,
           status      ENUM('new','contacted','closed') NOT NULL DEFAULT 'new',
           ip          VARCHAR(45)  NULL,
           created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_platform_leads_status (status, created_at)
         ) ENGINE=InnoDB"
    );

    // The school already here becomes workspace 1
    if (!(int) $pdo->query('SELECT COUNT(*) FROM workspaces')->fetchColumn()) {
        $name = '';
        try {
            $name = (string) $pdo->query("SELECT value FROM settings WHERE name = 'school_name'")->fetchColumn();
        } catch (PDOException) {
            // no settings table yet (fresh install)
        }
        if (trim($name) === '') {
            $name = (string) (config('school_name') ?: 'My school');
        }
        $pdo->prepare("INSERT INTO workspaces (id, type, name) VALUES (1, 'school', ?)")->execute([$name]);
    }

    // Records tagged with their workspace. Rows inside these (grades, scores, attendance, messages, ...)
    // belong to the workspace of the class, learner or thread they hang from.
    $tables = [
        'users', 'students', 'parents', 'school_years', 'subjects', 'sections', 'class_assignments', 'class_sessions',
        'settings', 'announcements', 'calendar_events', 'document_requests', 'message_threads', 'excuse_letters',
        'grade_change_requests', 'grade_history', 'class_posts', 'interventions', 'activity_log', 'access_blocks',
    ];
    foreach ($tables as $table) {
        if (column_exists($pdo, $table, 'workspace_id')) {
            continue;
        }
        // Existing rows get workspace 1; afterwards there is no default, so new rows must say their workspace.
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN workspace_id INT UNSIGNED NOT NULL DEFAULT 1");
        $pdo->exec("ALTER TABLE {$table} ALTER COLUMN workspace_id DROP DEFAULT");
        $pdo->exec("ALTER TABLE {$table} ADD KEY idx_ws_{$table} (workspace_id),"
            . " ADD CONSTRAINT fk_ws_{$table} FOREIGN KEY (workspace_id) REFERENCES workspaces (id)");
    }
    // A sign-in with an unknown user name belongs to no school
    if (!column_exists($pdo, 'login_events', 'workspace_id')) {
        $pdo->exec('ALTER TABLE login_events ADD COLUMN workspace_id INT UNSIGNED NULL, ADD KEY idx_ws_login_events (workspace_id, created_at)');
        $pdo->exec('UPDATE login_events SET workspace_id = 1 WHERE account_id IS NOT NULL');
    }

    // Names and codes only need to be unique inside one school
    $perWorkspace = [
        'school_years' => ['uq_school_years_label', 'label'],
        'subjects'     => ['uq_subjects_code', 'code'],
        'students'     => ['uq_students_lrn', 'lrn'],
    ];
    foreach ($perWorkspace as $table => [$index, $column]) {
        if (index_exists($pdo, $table, $index)) {
            $pdo->exec("ALTER TABLE {$table} DROP INDEX {$index}");
        }
        $pdo->exec("ALTER TABLE {$table} ADD UNIQUE KEY {$index} (workspace_id, {$column})");
    }
    $pk = $pdo->query(
        "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND INDEX_NAME = 'PRIMARY'"
    )->fetchColumn();
    if ($pk !== 'workspace_id,name') {
        $pdo->exec('ALTER TABLE settings DROP PRIMARY KEY, ADD PRIMARY KEY (workspace_id, name)');
    }
    // Learner lists are sorted by name inside one school
    if (!index_exists($pdo, 'students', 'idx_students_ws_name')) {
        $pdo->exec('ALTER TABLE students ADD KEY idx_students_ws_name (workspace_id, last_name, first_name)');
    }
};
