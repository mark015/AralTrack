<?php
// One person, several schools. A parent can have a record at more than one school (or teacher) with the same
// mobile number or email, and a learner's records at several schools can share one QR ID card. Each school still
// keeps its own record; they are joined at sign-in (same password) or when a card is scanned in a class where a
// learner with the same LRN is enrolled.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $unique = [
        'parents'  => ['uq_parents_login', 'login'],
        'students' => ['uq_students_qr', 'qr_token'],
    ];
    foreach ($unique as $table => [$index, $column]) {
        $columns = $pdo->query(
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$index}'"
        )->fetchColumn();
        if ($columns === "workspace_id,{$column}") {
            continue;
        }
        if ($columns) {
            $pdo->exec("ALTER TABLE {$table} DROP INDEX {$index}");
        }
        $pdo->exec("ALTER TABLE {$table} ADD UNIQUE KEY {$index} (workspace_id, {$column})");
    }
    // Finding every record of one person quickly
    if (!index_exists($pdo, 'parents', 'idx_parents_login')) {
        $pdo->exec('ALTER TABLE parents ADD KEY idx_parents_login (login)');
    }
    if (!index_exists($pdo, 'students', 'idx_students_lrn')) {
        $pdo->exec('ALTER TABLE students ADD KEY idx_students_lrn (lrn)');
    }
    if (!index_exists($pdo, 'students', 'idx_students_qr')) {
        $pdo->exec('ALTER TABLE students ADD KEY idx_students_qr (qr_token)');
    }
};
