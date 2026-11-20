<?php
/**
 * Adds demo content for the newer features (timetable, announcements, calendar, deadlines, class records,
 * class posts, messages, document requests) to an existing database. Keeps all other data.
 *   php database/demo-extras.php
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
    $c['user'], $c['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
prepare_db_session($pdo);
run_migrations($pdo);
seed_demo_extras($pdo);
seed_demo_classroom($pdo);
echo "Demo timetable, announcements, calendar, deadlines, class records, class posts, a message and a document request added.\n";
