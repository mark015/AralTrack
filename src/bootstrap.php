<?php
// Loaded at the top of every page in public/.
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/queries.php';
require __DIR__ . '/RateLimiter.php';
require __DIR__ . '/SheetReader.php';
require __DIR__ . '/GradeImporter.php';
require __DIR__ . '/StudentImporter.php';
require __DIR__ . '/Templates.php';
require __DIR__ . '/Attendance.php';
require __DIR__ . '/Notifications.php';
require __DIR__ . '/ReportCard.php';
require __DIR__ . '/Grading.php';
require __DIR__ . '/Files.php';
require __DIR__ . '/AtRisk.php';
require __DIR__ . '/Schedule.php';
require __DIR__ . '/News.php';
require __DIR__ . '/Navigation.php';
foreach (['Forms', 'FormSheets', 'Messages', 'DocumentRequests', 'ClassRecord', 'Backup'] as $optional) {
    if (is_file(__DIR__ . "/{$optional}.php")) {
        require __DIR__ . "/{$optional}.php";
    }
}
require APP_ROOT . '/database/migrations.php';

date_default_timezone_set((string) config('timezone'));
ini_set('display_errors', config('debug') ? '1' : '0');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e): void {
    error_log((string) $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo config('debug')
        ? '<pre style="white-space:pre-wrap">' . e((string) $e) . '</pre>'
        : 'Something went wrong. Please try again later.';
});

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, max-age=0'); // shared lab computers: never cache grade pages

require_current_schema();
start_session();

if (is_post()) {
    verify_csrf();
}

Attendance::finishStaleSessions();

/** Stops with a clear message when the database hasn't been updated for this version of the code. */
function require_current_schema(): void
{
    $files = migration_files();
    $latest = $files ? array_key_last($files) : null;
    try {
        $current = $latest === null ? null : db_value('SELECT MAX(version) FROM schema_migrations');
    } catch (PDOException) {
        $current = null; // table doesn't exist yet
    }
    if ($current === $latest) {
        return;
    }
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Update needed</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
        . '<h1 style="font-size:1.4rem">The database needs an update</h1>'
        . '<p>On the server, open a terminal in the <code>student-portal</code> folder and run:</p>'
        . '<pre style="background:#f1f3f5;padding:.75rem">php database/migrate.php</pre>'
        . '<p>This keeps all existing data.</p></div>';
    exit;
}
