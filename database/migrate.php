<?php
/**
 * Brings an existing database up to date without erasing data. Run from the project folder:
 *
 *   php database/migrate.php
 *
 * Safe to run any number of times.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
require APP_ROOT . '/src/helpers.php';
require __DIR__ . '/migrations.php';

$c = config('db');
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']),
        $c['user'],
        $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot open database \"{$c['name']}\": {$e->getMessage()}\n"
        . "Start MySQL in the XAMPP Control Panel. If the database doesn't exist yet, run: php database/install.php\n");
    exit(1);
}

$applied = run_migrations($pdo);
echo $applied
    ? 'Updated the database: ' . implode(', ', $applied) . "\n"
    : "The database is already up to date.\n";
