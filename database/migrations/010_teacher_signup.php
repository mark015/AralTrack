<?php
// Teacher sign-up: staff accounts can have an email address (sign in with it, confirm it with a code, reset a
// forgotten password by email). Codes and reset links are kept only as hashes.
declare(strict_types=1);

return static function (PDO $pdo): void {
    if (!column_exists($pdo, 'users', 'email')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER username, ADD COLUMN email_verified_at DATETIME NULL AFTER email');
    }
    if (!index_exists($pdo, 'users', 'uq_users_email')) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_codes (
           id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           user_id    INT UNSIGNED NOT NULL,
           purpose    ENUM('verify','reset') NOT NULL,
           code_hash  CHAR(64)     NOT NULL,
           attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
           expires_at DATETIME     NOT NULL,
           used_at    DATETIME     NULL,
           ip         VARCHAR(45)  NULL,
           created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY idx_email_codes_user (user_id, purpose, created_at),
           KEY idx_email_codes_hash (code_hash),
           CONSTRAINT fk_email_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
         ) ENGINE=InnoDB"
    );
};
