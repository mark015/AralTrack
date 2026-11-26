<?php
declare(strict_types=1);

/**
 * Database backups made with PHP only (no mysqldump needed), and restoring them.
 * A backup is a plain .sql file: DROP + CREATE TABLE and INSERTs for every table.
 * Copies saved on the server go to storage/backups/ (not reachable from the web).
 */
final class Backup
{
    public const HEADER = '-- Student Grade Portal database backup';
    private const ROWS_PER_INSERT = 200;

    public static function dir(): string
    {
        $dir = APP_ROOT . '/storage/backups';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir);
        }
        return $dir;
    }

    /** Writes a full backup to a stream (php://output for a download, or a file handle). */
    public static function write($out): void
    {
        $pdo = db();
        $c = config('db');
        fwrite($out, self::HEADER . "\n-- Database: {$c['name']} · Made: " . date('Y-m-d H:i:s') . ' · App: ' . config('app_name') . "\n");
        fwrite($out, "-- Restore it from the platform console (Backups), or: mysql -u root {$c['name']} < this-file.sql\n\n");
        fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        // A second, unbuffered connection reads rows one by one, so big tables don't fill the memory.
        $reader = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']),
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
        );

        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM)[1];
            fwrite($out, "-- Table {$table}\nDROP TABLE IF EXISTS `{$table}`;\n" . $create . ";\n");

            $rows = $reader->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`', PDO::FETCH_NUM);
            $batch = [];
            $columns = null;
            foreach ($rows as $row) {
                if ($columns === null) {
                    $columns = [];
                    for ($i = 0; $i < $rows->columnCount(); $i++) {
                        $columns[] = '`' . $rows->getColumnMeta($i)['name'] . '`';
                    }
                }
                $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) >= self::ROWS_PER_INSERT) {
                    fwrite($out, "INSERT INTO `{$table}` (" . implode(',', $columns) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($out, "INSERT INTO `{$table}` (" . implode(',', $columns) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $rows->closeCursor();
            fwrite($out, "\n");
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n");
    }

    /** Saves a backup in storage/backups and returns its file name. */
    public static function saveCopy(string $label): string
    {
        $name = 'backup-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9-]+/', '-', strtolower($label)) . '.sql';
        $handle = fopen(self::dir() . '/' . $name, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not write the backup file.');
        }
        try {
            self::write($handle);
        } finally {
            fclose($handle);
        }
        return $name;
    }

    /** @return list<array{name: string, size: int, time: int}> newest first */
    public static function copies(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.sql') ?: [] as $path) {
            $out[] = ['name' => basename($path), 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
        }
        usort($out, static fn ($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    /** Full path of a saved copy, or null if the name is not one of them. */
    public static function copyPath(string $name): ?string
    {
        if (!preg_match('/^backup-[0-9]{8}-[0-9]{6}-[a-z0-9-]+\.sql$/', $name)) {
            return null;
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /**
     * Replaces the whole database with a backup file made by this portal.
     * @throws UserFacingException when the file is not such a backup
     */
    public static function restore(string $path): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not read the backup file.');
        }
        try {
            $first = (string) fgets($handle);
            if (!str_starts_with($first, self::HEADER)) {
                throw new UserFacingException('This is not a backup made by the portal (Platform console → Backups). Nothing was changed.');
            }
            $pdo = db();
            $statements = 0;
            $buffer = '';
            while (($line = fgets($handle)) !== false) {
                if ($buffer === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                    continue;
                }
                $buffer .= $line;
                // Values are escaped (no raw line breaks inside strings), so a statement ends at ";" + line end.
                if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                    $pdo->exec($buffer);
                    $statements++;
                    $buffer = '';
                }
            }
            if (trim($buffer) !== '') {
                throw new UserFacingException('The backup file is incomplete (it may have been cut off). The database may be partly restored — restore the automatic copy made just before.');
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            return $statements;
        } finally {
            fclose($handle);
        }
    }

    /** Streams a zip of all uploaded files (excuse letters, class materials, photos). */
    public static function sendFilesZip(): never
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sgp');
        $zip = new ZipArchive();
        if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the zip file.');
        }
        $count = 0;
        foreach (['excuses', 'materials', 'photos'] as $folder) {
            $dir = APP_ROOT . '/storage/' . $folder;
            foreach (is_dir($dir) ? (glob($dir . '/*') ?: []) : [] as $file) {
                if (is_file($file)) {
                    $zip->addFile($file, $folder . '/' . basename($file));
                    $count++;
                }
            }
        }
        $zip->addFromString('README.txt', "Uploaded files from the Student Grade Portal (" . date('Y-m-d H:i') . ", {$count} files).\nTo restore, copy these folders into the portal's storage/ folder.\n");
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="portal-files-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }
}
