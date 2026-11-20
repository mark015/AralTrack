<?php
/**
 * Creates the database and its tables. Run from the project folder:
 *
 *   php database/install.php            tables + an "admin" account with a random password
 *   php database/install.php --seed     tables + demo staff, learners, grades, parents and attendance
 *   php database/install.php --fresh    delete the database first — ALL DATA IS LOST
 *   --db=NAME                           use another database name than config/config.php (e.g. for a test copy)
 *
 * To update an existing database instead, run: php database/migrate.php
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

$args = array_slice($argv, 1);
$fresh = in_array('--fresh', $args, true);
$seed = in_array('--seed', $args, true);

$c = config('db');
$db = $c['name'];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $db = substr($arg, 5);
    }
}
if (!preg_match('/^\w+$/', $db)) {
    fwrite(STDERR, "The database name may only use letters, numbers and underscores.\n");
    exit(1);
}
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']),
        $c['user'],
        $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    prepare_db_session($pdo);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to MySQL: {$e->getMessage()}\nStart MySQL in the XAMPP Control Panel and try again.\n"
        . "On web hosting, check the database settings in config/config.local.php.\n");
    exit(1);
}

if ($fresh) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
    echo "Deleted database {$db}.\n";
}
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException) {
    // Shared hosting (e.g. Hostinger) doesn't let the account create databases; it must already exist (checked next).
}
try {
    $pdo->exec("USE `{$db}`");
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot open the database \"{$db}\": {$e->getMessage()}\n"
        . "On web hosting, first create the database and its user in the control panel\n"
        . "(Hostinger: hPanel → Databases → MySQL Databases), then put the database name, user and password\n"
        . "in config/config.local.php (copy config/config.local.example.php).\n");
    exit(1);
}

if ($pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()) {
    fwrite(STDERR, "The database \"{$db}\" is already installed. To update it, run: php database/migrate.php\n"
        . "(Add --fresh only if you want to delete everything and start over.)\n");
    exit(1);
}

$sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(__DIR__ . '/schema.sql'));
foreach (preg_split('/;\s*$/m', $sql) as $statement) {
    if (trim($statement) !== '') {
        $pdo->exec($statement);
    }
}
run_migrations($pdo);
echo "Created the tables in database \"{$db}\".\n";

if ($seed) {
    seed_demo_school($pdo);
    seed_demo_attendance($pdo);
    seed_demo_extras($pdo);
    seed_demo_classroom($pdo);
    echo DEMO_SUMMARY, "\n";
} else {
    $password = bin2hex(random_bytes(6));
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, must_change_password) VALUES (?, ?, ?, ?, 1)')
        ->execute(['admin', password_hash($password, PASSWORD_DEFAULT), 'Administrator', 'admin']);
    echo "\nAdmin account (you will be asked to change the password at first sign-in):\n";
    echo "  username: admin\n  password: {$password}\n";
}
echo "\nOpen http://localhost" . config('base_url') . "/\n";
