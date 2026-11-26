<?php
// Offline attendance: a session started on a teacher's phone without internet gets a random id there.
// When the phone sends it, client_uuid makes sure it is saved once, even if it is sent again.
declare(strict_types=1);

return static function (PDO $pdo): void {
    if (!column_exists($pdo, 'class_sessions', 'client_uuid')) {
        $pdo->exec('ALTER TABLE class_sessions ADD COLUMN client_uuid VARCHAR(40) NULL');
    }
    if (!index_exists($pdo, 'class_sessions', 'uq_class_sessions_client_uuid')) {
        $pdo->exec('CREATE UNIQUE INDEX uq_class_sessions_client_uuid ON class_sessions (client_uuid)');
    }
};
