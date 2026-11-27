<?php
// Privacy tools (Data Privacy Act, RA 10173): a record of every acceptance of a school's privacy notice (with the
// notice's version), and a school asking to close its account and have its data deleted.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS consents (
           id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           workspace_id INT UNSIGNED NOT NULL,
           account_type ENUM('parent','staff','learner') NOT NULL,
           account_id   INT UNSIGNED NOT NULL,
           document     VARCHAR(30)  NOT NULL DEFAULT 'privacy',
           version      INT UNSIGNED NOT NULL DEFAULT 1,
           accepted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           ip           VARCHAR(45)  NULL,
           KEY idx_consents_account (account_type, account_id, document),
           KEY idx_consents_workspace (workspace_id, accepted_at)
         ) ENGINE=InnoDB"
    );
    // Parents who already agreed when they registered
    if (!(int) $pdo->query('SELECT COUNT(*) FROM consents')->fetchColumn()) {
        $pdo->exec(
            "INSERT INTO consents (workspace_id, account_type, account_id, document, version, accepted_at)
             SELECT workspace_id, 'parent', id, 'privacy', 1, privacy_accepted_at FROM parents WHERE privacy_accepted_at IS NOT NULL"
        );
    }

    foreach (['deletion_requested_at' => 'DATETIME NULL', 'deleted_at' => 'DATETIME NULL'] as $name => $definition) {
        if (!column_exists($pdo, 'workspaces', $name)) {
            $pdo->exec("ALTER TABLE workspaces ADD COLUMN {$name} {$definition}");
        }
    }
    $pdo->exec("ALTER TABLE workspaces MODIFY status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active'");
};
