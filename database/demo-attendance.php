<?php
/**
 * Adds the demo parent accounts and two weeks of demo class attendance to an existing
 * database that has the demo learners (from install.php --seed). Keeps all other data.
 *
 *   php database/demo-attendance.php
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
require __DIR__ . '/demo.php';

$c = config('db');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']),
    $c['user'],
    $c['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
run_migrations($pdo);

$pdo->beginTransaction();
if (seed_demo_attendance($pdo)) {
    $pdo->commit();
    echo DEMO_SUMMARY, "\n";
} else {
    $pdo->rollBack();
}
