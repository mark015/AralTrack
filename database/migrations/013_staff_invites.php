<?php
// A school invites a teacher by email. The invitation link (kept only as a hash) lets the teacher create their
// account in the school, or, if they already use the system on their own, bring their classes into the school.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff_invites (
           id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           workspace_id     INT UNSIGNED NOT NULL,
           email            VARCHAR(150) NOT NULL,
           role             ENUM('teacher','admin','principal','guidance') NOT NULL DEFAULT 'teacher',
           token_hash       CHAR(64)     NOT NULL,
           created_by       INT UNSIGNED NULL,
           expires_at       DATETIME     NOT NULL,
           accepted_at      DATETIME     NULL,
           accepted_user_id INT UNSIGNED NULL,
           cancelled_at     DATETIME     NULL,
           created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_invites_workspace (workspace_id, created_at),
           KEY idx_invites_token (token_hash),
           CONSTRAINT fk_invites_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id)
         ) ENGINE=InnoDB"
    );
};
