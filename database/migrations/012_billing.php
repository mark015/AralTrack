<?php
// Billing: plans with a free trial, payments (PayMongo: GCash, Maya, card, QR Ph; or bank transfer recorded by the
// platform owner), and the platform's prices. A school whose trial or paid period is over becomes read-only;
// a teacher on their own falls back to the free plan.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = [
        'trial_ends_at' => 'DATE NULL',
        'paid_until'    => 'DATE NULL',
        'billing_cycle' => 'VARCHAR(10) NULL',
        'seats'         => 'SMALLINT UNSIGNED NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (!column_exists($pdo, 'workspaces', $name)) {
            $pdo->exec("ALTER TABLE workspaces ADD COLUMN {$name} {$definition}");
        }
    }
    // Workspaces already on a trial get 30 days from when they were made
    $pdo->exec("UPDATE workspaces SET trial_ends_at = DATE_ADD(DATE(created_at), INTERVAL 30 DAY) WHERE plan = 'trial' AND trial_ends_at IS NULL");

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS platform_settings (
           name  VARCHAR(60) NOT NULL PRIMARY KEY,
           value TEXT        NULL
         ) ENGINE=InnoDB'
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS payments (
           id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           workspace_id INT UNSIGNED NOT NULL,
           provider     ENUM('paymongo','manual','test') NOT NULL,
           provider_ref VARCHAR(100) NULL,
           description  VARCHAR(255) NOT NULL,
           amount       INT UNSIGNED NOT NULL COMMENT 'centavos',
           months       SMALLINT UNSIGNED NOT NULL,
           seats        SMALLINT UNSIGNED NULL,
           status       ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
           method       VARCHAR(30)  NULL COMMENT 'gcash, paymaya, card, bank, ...',
           note         VARCHAR(255) NULL,
           created_by   VARCHAR(150) NULL,
           paid_at      DATETIME     NULL,
           created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           UNIQUE KEY uq_payments_ref (provider, provider_ref),
           KEY idx_payments_workspace (workspace_id, created_at),
           CONSTRAINT fk_payments_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id)
         ) ENGINE=InnoDB"
    );
};
