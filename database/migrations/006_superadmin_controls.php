<?php
// Super Admin: turns parts of the portal and sign-in on or off, controls each account's access,
// and sees every sign-in and every account or settings change.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('superadmin','admin','teacher','principal','guidance') NOT NULL DEFAULT 'teacher'");

    // Per account: "sign out now" raises session_version; the last sign-in; why access was turned off.
    $columns = [
        'users'    => ['session_version' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'last_login_at' => 'DATETIME NULL', 'last_login_ip' => 'VARCHAR(45) NULL', 'last_login_device' => 'VARCHAR(120) NULL', 'blocked_reason' => 'VARCHAR(255) NULL'],
        'parents'  => ['session_version' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'last_login_at' => 'DATETIME NULL', 'last_login_ip' => 'VARCHAR(45) NULL', 'last_login_device' => 'VARCHAR(120) NULL', 'blocked_reason' => 'VARCHAR(255) NULL'],
        'students' => ['session_version' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'last_login_at' => 'DATETIME NULL', 'last_login_ip' => 'VARCHAR(45) NULL', 'last_login_device' => 'VARCHAR(120) NULL', 'login_blocked' => 'TINYINT(1) NOT NULL DEFAULT 0', 'blocked_reason' => 'VARCHAR(255) NULL'],
    ];
    foreach ($columns as $table => $list) {
        foreach ($list as $name => $definition) {
            if (!column_exists($pdo, $table, $name)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
            }
        }
    }

    // Every sign-in attempt: staff, parents and learners
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS login_events (
           id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           account_type ENUM('staff','parent','learner') NOT NULL,
           account_id   INT UNSIGNED NULL,
           identifier   VARCHAR(150) NOT NULL,
           success      TINYINT(1)   NOT NULL,
           reason       VARCHAR(40)  NULL,
           ip           VARCHAR(45)  NULL,
           device       VARCHAR(120) NULL,
           created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_login_events_time (created_at),
           KEY idx_login_events_account (account_type, account_id)
         ) ENGINE=InnoDB"
    );

    // Account, access and settings changes made by the Super Admin and admins
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS activity_log (
           id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           actor_id    INT UNSIGNED NULL,
           actor_name  VARCHAR(150) NOT NULL,
           action      VARCHAR(60)  NOT NULL,
           target_type VARCHAR(30)  NULL,
           target_id   INT UNSIGNED NULL,
           summary     VARCHAR(500) NOT NULL,
           details     TEXT         NULL,
           ip          VARCHAR(45)  NULL,
           created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_activity_time (created_at),
           KEY idx_activity_actor (actor_id)
         ) ENGINE=InnoDB"
    );

    // The first active admin becomes the Super Admin (an existing school keeps one account in charge).
    $hasSuper = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
    if (!$hasSuper) {
        $first = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
        if ($first) {
            $pdo->prepare("UPDATE users SET role = 'superadmin' WHERE id = ?")->execute([(int) $first]);
        }
    }
};
