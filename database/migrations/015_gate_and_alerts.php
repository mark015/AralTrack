<?php
// Gate attendance and alerts to parents:
//  - gate_logs: learners scanned in and out at the school gate (with the guard's phone or a tablet),
//  - a "Gate guard" staff role that can only use the gate scanner and the gate log,
//  - push_subscriptions: parents' phones that get alerts (web push, free),
//  - sms_messages: text messages to parents (queued, sent through the school's SMS provider, kept as a log),
//  - parent notifications "arrived" and "left", and a flag for notifications already sent to phones.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('superadmin','admin','teacher','principal','guidance','guard') NOT NULL DEFAULT 'teacher'");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS gate_logs (
           id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           workspace_id INT UNSIGNED NOT NULL,
           student_id   INT UNSIGNED NOT NULL,
           direction    ENUM('in','out') NOT NULL,
           scanned_at   DATETIME     NOT NULL,
           late         TINYINT(1)   NOT NULL DEFAULT 0,
           source       ENUM('scan','offline','manual') NOT NULL DEFAULT 'scan',
           recorded_by  INT UNSIGNED NULL,
           client_uuid  VARCHAR(40)  NULL,
           created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           UNIQUE KEY uq_gate_client (workspace_id, client_uuid),
           KEY idx_gate_day (workspace_id, scanned_at),
           KEY idx_gate_student (student_id, scanned_at),
           CONSTRAINT fk_gate_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS push_subscriptions (
           id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           workspace_id  INT UNSIGNED NOT NULL,
           parent_id     INT UNSIGNED NOT NULL,
           endpoint      VARCHAR(1000) NOT NULL,
           endpoint_hash CHAR(64)     NOT NULL,
           p256dh        VARCHAR(120) NOT NULL,
           auth          VARCHAR(60)  NOT NULL,
           device        VARCHAR(120) NULL,
           failures      TINYINT UNSIGNED NOT NULL DEFAULT 0,
           created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           last_sent_at  DATETIME     NULL,
           UNIQUE KEY uq_push_parent_endpoint (parent_id, endpoint_hash),
           KEY idx_push_workspace (workspace_id),
           CONSTRAINT fk_push_parent FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sms_messages (
           id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           workspace_id INT UNSIGNED NOT NULL,
           student_id   INT UNSIGNED NULL,
           parent_id    INT UNSIGNED NULL,
           number       VARCHAR(20)  NOT NULL,
           message      VARCHAR(480) NOT NULL,
           event        VARCHAR(20)  NOT NULL,
           dedupe_key   VARCHAR(120) NULL,
           status       ENUM('queued','sending','sent','failed','skipped') NOT NULL DEFAULT 'queued',
           test         TINYINT(1)   NOT NULL DEFAULT 0,
           attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
           next_try_at  DATETIME     NULL,
           claimed_at   DATETIME     NULL,
           provider_ref VARCHAR(60)  NULL,
           error        VARCHAR(255) NULL,
           created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           sent_at      DATETIME     NULL,
           UNIQUE KEY uq_sms_dedupe (workspace_id, dedupe_key),
           KEY idx_sms_queue (status, next_try_at),
           KEY idx_sms_workspace (workspace_id, created_at),
           KEY idx_sms_parent (parent_id),
           KEY idx_sms_student (student_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "ALTER TABLE parent_notifications MODIFY type
           ENUM('absent','late','changed','grades','announcement','excuse','request','message','arrived','left') NOT NULL"
    );
    if (!column_exists($pdo, 'parent_notifications', 'alerted')) {
        // Notifications made before this change were only shown in the portal: they are not sent to phones now.
        $pdo->exec('ALTER TABLE parent_notifications ADD COLUMN alerted TINYINT(1) NOT NULL DEFAULT 0');
        $pdo->exec('UPDATE parent_notifications SET alerted = 1');
    }
    if (!index_exists($pdo, 'parent_notifications', 'idx_notifications_alerted')) {
        $pdo->exec('ALTER TABLE parent_notifications ADD KEY idx_notifications_alerted (alerted, id)');
    }
};
