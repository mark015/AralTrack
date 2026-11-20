<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/**
 * Checks an uploaded spreadsheet and reads it into plain rows of cell values.
 * The upload is read straight from PHP's temp folder and never saved anywhere.
 */
final class SheetReader
{
    private const MAX_ROWS = 3000;
    private const MAX_COLS = 30;

    /** @return array{path: string, ext: string, name: string} */
    public static function validateUpload(mixed $file): array
    {
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            throw new UserFacingException('Choose a file to upload.');
        }
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new UserFacingException('Choose a file to upload.');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new UserFacingException('The file is too large. The limit is ' . max_upload_label() . '.');
            default:
                throw new UserFacingException('The upload did not finish. Please try again.');
        }
        if ($file['size'] > (int) config('upload_max_bytes')) {
            throw new UserFacingException('The file is too large. The limit is ' . max_upload_label() . '.');
        }
        if ($file['size'] === 0) {
            throw new UserFacingException('The file is empty.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new UserFacingException('The upload did not finish. Please try again.');
        }

        $name = basename(str_replace('\\', '/', (string) $file['name']));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            throw new UserFacingException('Upload an Excel file (.xlsx or .xls) or a CSV file.');
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $looksRight = match ($ext) {
            'xlsx' => in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true),
            'xls'  => in_array($mime, ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'], true),
            'csv'  => str_starts_with($mime, 'text/') || in_array($mime, ['application/csv', 'application/vnd.ms-excel'], true),
        };
        if (!$looksRight) {
            throw new UserFacingException("This doesn't look like a real .{$ext} file. Save it again from Excel and retry.");
        }

        return ['path' => $file['tmp_name'], 'ext' => $ext, 'name' => mb_substr($name, 0, 255)];
    }

    /** @return list<list<mixed>> rows of raw cell values (string, int, float or null) */
    public static function read(string $path, string $ext): array
    {
        try {
            return $ext === 'csv'
                ? self::readCsv($path)
                : self::readSpreadsheet($path, $ext === 'xls' ? 'Xls' : 'Xlsx');
        } catch (UserFacingException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('Spreadsheet read failed: ' . $e->getMessage());
            throw new UserFacingException('The file could not be read. Make sure it is a valid Excel or CSV file and is not password-protected.');
        }
    }

    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new UserFacingException('The file could not be opened.');
        }
        $firstLine = (string) fgets($handle);
        // Excel in some regions saves CSV with semicolons.
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $rows = [];
        while (count($rows) < self::MAX_ROWS && ($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = $row === [null] ? [] : array_slice($row, 0, self::MAX_COLS);
        }
        fclose($handle);

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($r === 0 && $c === 0) {
                    $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value); // UTF-8 byte-order mark
                }
                // "CSV (Comma delimited)" from Excel on Windows is Windows-1252, not UTF-8 (matters for ñ).
                if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                }
                $rows[$r][$c] = $value;
            }
        }
        return $rows;
    }

    private static function readSpreadsheet(string $path, string $type): array
    {
        $reader = IOFactory::createReader($type);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $sheet = $book->getActiveSheet();

        $maxRow = min($sheet->getHighestDataRow(), self::MAX_ROWS);
        $maxCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), self::MAX_COLS);

        $rows = [];
        for ($r = 1; $r <= $maxRow; $r++) {
            $cells = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                if (!$sheet->cellExists([$c, $r])) {
                    $cells[] = null;
                    continue;
                }
                $cell = $sheet->getCell([$c, $r]);
                // For formulas, use the result Excel saved in the file.
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                if ($value instanceof RichText) {
                    $value = $value->getPlainText();
                }
                $cells[] = $value;
            }
            $rows[] = $cells;
        }
        $book->disconnectWorksheets();
        return $rows;
    }

    /** Lower-case a header cell and keep only letters and spaces: "Birthdate (YYYY-MM-DD)" → "birthdate yyyymmdd". */
    public static function headerKey(mixed $value): string
    {
        $text = strtolower(trim((string) $value));
        $text = (string) preg_replace('/[^a-z ]+/', '', $text);
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
