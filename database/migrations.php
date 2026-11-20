<?php
/**
 * Database changes made after the first release. Each file in database/migrations/
 * runs once, in name order, and is recorded in the schema_migrations table, so
 * running them again never repeats a change or erases data.
 */
declare(strict_types=1);

/** @return list<string> names of the migrations that were applied now */
function run_migrations(PDO $pdo): array
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
           version    VARCHAR(100) NOT NULL PRIMARY KEY,
           applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB'
    );
    $done = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = [];
    foreach (migration_files() as $version => $file) {
        if (in_array($version, $done, true)) {
            continue;
        }
        $migration = require $file;
        $migration($pdo);
        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
        $applied[] = $version;
    }
    return $applied;
}

/** @return array<string, string> version => file path, in order */
function migration_files(): array
{
    $files = [];
    foreach (glob(__DIR__ . '/migrations/*.php') ?: [] as $file) {
        $files[basename($file, '.php')] = $file;
    }
    ksort($files);
    return $files;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}
