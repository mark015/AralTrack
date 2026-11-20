<?php
declare(strict_types=1);

/**
 * Uploaded files (excuse letters, class materials, learner photos). They are stored in
 * storage/ — outside public/ — under random names, and only sent through public/file.php
 * after a permission check.
 */
final class Files
{
    private const OFFICE = ['application/zip', 'application/octet-stream'];

    /** kind => folder, size limit, extension => accepted detected MIME types */
    private const KINDS = [
        'excuse' => ['dir' => 'excuses', 'max' => 5 * 1048576, 'types' => [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'],
        ]],
        'material' => ['dir' => 'materials', 'max' => 10 * 1048576, 'types' => [
            'pdf' => ['application/pdf'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::OFFICE],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::OFFICE],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::OFFICE],
            'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/x-ole-storage'],
            'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'],
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'txt' => ['text/plain'],
        ]],
        'photo' => ['dir' => 'photos', 'max' => 3 * 1048576, 'types' => [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        ]],
    ];

    /** Content-Type sent for each extension */
    private const SEND_AS = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'doc' => 'application/msword', 'ppt' => 'application/vnd.ms-powerpoint', 'xls' => 'application/vnd.ms-excel', 'txt' => 'text/plain',
    ];

    public static function accept(string $kind): string
    {
        return implode(',', array_map(static fn ($ext) => '.' . $ext, array_keys(self::KINDS[$kind]['types'])));
    }

    public static function limitLabel(string $kind): string
    {
        return (self::KINDS[$kind]['max'] / 1048576) . ' MB';
    }

    /**
     * Checks and stores an upload. Returns null when no file was chosen (and none is required).
     * @return array{path: string, name: string, mime: string}|null
     */
    public static function store(mixed $file, string $kind, bool $required = false): ?array
    {
        $rules = self::KINDS[$kind];
        if (!is_array($file) || !isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                throw new UserFacingException('Choose a file to upload.');
            }
            return null;
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > $rules['max']) {
            throw new UserFacingException('The file is too large. The limit is ' . self::limitLabel($kind) . '.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] === 0) {
            throw new UserFacingException('The upload did not finish. Please try again.');
        }
        $name = mb_substr(basename(str_replace('\\', '/', (string) $file['name'])), 0, 200);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset($rules['types'][$ext])) {
            throw new UserFacingException('This type of file is not accepted. Allowed: ' . implode(', ', array_keys($rules['types'])) . '.');
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, $rules['types'][$ext], true)) {
            throw new UserFacingException("This doesn't look like a real .{$ext} file.");
        }

        $dir = APP_ROOT . '/storage/' . $rules['dir'];
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the upload folder ' . $dir);
        }
        $stored = $rules['dir'] . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], APP_ROOT . '/storage/' . $stored)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }
        return ['path' => $stored, 'name' => $name, 'mime' => self::SEND_AS[$ext]];
    }

    /** Streams a stored file. Images and PDFs open in the browser; other files download. */
    public static function send(string $path, string $downloadName): never
    {
        $full = realpath(APP_ROOT . '/storage/' . $path);
        $root = realpath(APP_ROOT . '/storage');
        if ($full === false || $root === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) {
            abort(404, 'That file is no longer available.');
        }
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $type = self::SEND_AS[$ext] ?? 'application/octet-stream';
        $inline = str_starts_with($type, 'image/') || $type === 'application/pdf';
        $safeName = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $downloadName);
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        readfile($full);
        exit;
    }

    public static function delete(?string $path): void
    {
        if ($path && preg_match('#^[a-z]+/[0-9a-f]{32}\.[a-z]+$#', $path)) {
            @unlink(APP_ROOT . '/storage/' . $path);
        }
    }
}
