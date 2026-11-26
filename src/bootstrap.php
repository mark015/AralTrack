<?php
// Loaded at the top of every page in public/.
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/Workspace.php';
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
require __DIR__ . '/Access.php';
require __DIR__ . '/OfflinePages.php';
foreach (['Forms', 'FormSheets', 'Messages', 'DocumentRequests', 'ClassRecord', 'Backup'] as $optional) {
    if (is_file(__DIR__ . "/{$optional}.php")) {
        require __DIR__ . "/{$optional}.php";
    }
}
require APP_ROOT . '/database/migrations.php';

// Set before the first config() call, so a mistake in config/config.local.php is reported too.
set_exception_handler(static function (Throwable $e): void {
    error_log((string) $e);
    try {
        $debug = (bool) config('debug');
    } catch (Throwable) {
        $debug = false; // the config file itself is broken
    }
    if (!headers_sent()) {
        http_response_code($e instanceof DatabaseUnavailable ? 503 : 500);
    }
    if ($debug) {
        echo '<pre style="white-space:pre-wrap">' . e((string) $e) . '</pre>';
        return;
    }
    // Setup problems are explained without names, paths or passwords; anything else stays a plain message.
    $problem = match (true) {
        $e instanceof DatabaseUnavailable => ["The portal can't connect to its database", $e->getMessage()],
        str_ends_with(str_replace('\\', '/', $e->getFile()), 'config/config.local.php') => [
            'There is a mistake in config/config.local.php',
            'Line ' . $e->getLine() . ': ' . $e->getMessage() . '. Compare it with config/config.local.example.php.',
        ],
        default => null,
    };
    if ($problem === null) {
        echo 'Something went wrong. Please try again later.';
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($problem[0]) . '</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:42rem;margin:4rem auto;padding:0 1rem;line-height:1.5">'
        . '<h1 style="font-size:1.35rem">' . e($problem[0]) . '</h1><p>' . e($problem[1]) . '</p>'
        . '<p style="color:#555">If you manage this website: the database settings are in <code>config/config.local.php</code> '
        . '(copy <code>config/config.local.example.php</code>). More detail is in the server\'s error log.</p></div>';
});

date_default_timezone_set((string) config('timezone'));
ini_set('display_errors', config('debug') ? '1' : '0');
error_reporting(E_ALL);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, max-age=0'); // shared lab computers: never cache grade pages

require_current_schema();
start_session();
if (!isset($_SESSION['workspace_id']) && (isset($_SESSION['staff']) || isset($_SESSION['parent']) || isset($_SESSION['student_id']))) {
    $_SESSION['workspace_id'] = 1; // signed in before workspaces existed: all those accounts are in the first school
}

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
