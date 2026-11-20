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
        $pdo = null;
        throw DatabaseUnavailable::from($e);
    }
    return $pdo;
}

/**
 * The portal can't connect to its database. The message says why in plain words, without user names,
 * hosts, paths or passwords, so it is safe to show on the error page even with debug off.
 */
final class DatabaseUnavailable extends RuntimeException
{
    public static function from(PDOException $e): self
    {
        $code = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) ? (int) $m[1] : 0;
        $reason = match ($code) {
            1045 => 'The database user name or password is wrong.',
            1044 => 'The database user has no access to this database. Add the user to the database (Hostinger: hPanel → Databases → MySQL Databases).',
            1049 => 'There is no database with this name. Check the database name, including the u123456789_ prefix on Hostinger.',
            2002, 2003, 2005, 2006 => 'The database server could not be reached. Check the database host (on Hostinger it is localhost; on XAMPP, start MySQL).',
            default => 'The connection to the database failed.',
        };
        if (!is_file(APP_ROOT . '/config/config.local.php')) {
            $reason .= ' config/config.local.php was not found on this server, so the default XAMPP settings were used.';
        }
        return new self($reason, 0, $e);
    }
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
