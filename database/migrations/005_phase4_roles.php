<?php
// Phase 4: principal (school head) and guidance counselor accounts.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','teacher','principal','guidance') NOT NULL DEFAULT 'teacher'");
};
