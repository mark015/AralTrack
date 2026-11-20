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

/**
 * Makes sure the database matches this version of the code. After new code arrives (for example an automatic
 * deploy from GitHub), the first page load applies the new database changes (config 'auto_migrate', on by
 * default). They only add tables and columns and keep all data. Otherwise stops with a clear message.
 */
function require_current_schema(): void
{
    $files = migration_files();
    $latest = $files ? array_key_last($files) : null;
    $current = static function (): ?string {
        try {
            return db_value('SELECT MAX(version) FROM schema_migrations');
        } catch (PDOException) {
            return null; // table doesn't exist yet
        }
    };
    if ($latest === null || $current() === $latest) {
        return;
    }

    $installed = (bool) db_value("SHOW TABLES LIKE 'users'");
    $problem = null;
    if (!$installed) {
        $problem = ['The database is empty', 'Create the tables first. On the server, in the project folder, run:', 'php database/install.php'];
    } elseif (config('auto_migrate') !== false) {
        // One request applies the changes; others arriving at the same moment wait for it.
        if ((int) db_value("SELECT GET_LOCK('portal_migrations', 60)") === 1) {
            try {
                if ($current() !== $latest) {
                    $applied = run_migrations(db());
                    error_log('Portal database updated: ' . implode(', ', $applied));
                }
                return;
            } catch (Throwable $e) {
                error_log('Portal database update failed: ' . $e);
                $problem = ['The database update did not finish', 'The error is in the server error log. Fix it, then run in the project folder:', 'php database/migrate.php'];
            } finally {
                db_value("SELECT RELEASE_LOCK('portal_migrations')");
            }
        } else {
            $problem = ['The portal is being updated', 'Please try again in a minute.', null];
            header('Retry-After: 60');
        }
    } else {
        $problem = ['The database needs an update', 'On the server, open a terminal in the project folder and run:', 'php database/migrate.php'];
    }

    http_response_code(503);
    [$heading, $text, $command] = $problem;
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($heading) . '</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
        . '<h1 style="font-size:1.4rem">' . e($heading) . '</h1><p>' . e($text) . '</p>'
        . ($command ? '<pre style="background:#f1f3f5;padding:.75rem">' . e($command) . '</pre>' : '')
        . '<p>This keeps all existing data.</p></div>';
    exit;
}
