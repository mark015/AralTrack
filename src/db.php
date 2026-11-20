<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        prepare_db_session($pdo);
    } catch (PDOException $e) {
        throw new RuntimeException(
            'Could not connect to the database "' . $c['name'] . '". On XAMPP, start MySQL in the XAMPP Control Panel. '
            . 'On web hosting, check the database settings in config/config.local.php. Then run: php database/install.php',
            0,
            $e
        );
    }
    return $pdo;
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

/** Runs an INSERT/UPDATE/DELETE and returns the number of affected rows. */
function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** True when the exception is a duplicate-key error (e.g. a UNIQUE column). */
function is_duplicate_error(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? null) === 1062;
}

/** True when a row can't be deleted because other rows still point to it. */
function is_foreign_key_error(PDOException $e): bool
{
    return in_array($e->errorInfo[1] ?? null, [1451, 1452], true);
}
