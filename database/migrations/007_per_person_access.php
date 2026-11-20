<?php
// Per-person access: the Super Admin can turn single parts of the portal off for one teacher, learner or
// parent (e.g. a learner's report card on hold, one teacher without attendance). One row = one part
// turned off for one account; no row = the person follows the control panel.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS access_blocks (
           account_type ENUM('staff','parent','learner') NOT NULL,
           account_id   INT UNSIGNED NOT NULL,
           part         VARCHAR(30)  NOT NULL,
           note         VARCHAR(255) NULL,
           created_by   INT UNSIGNED NULL,
           created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (account_type, account_id, part)
         ) ENGINE=InnoDB"
    );
};
