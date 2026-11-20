<?php
declare(strict_types=1);

/** A problem the user can fix (wrong file, missing column). Its message is shown on screen. */
final class UserFacingException extends RuntimeException
{
}

const SEMESTERS = [1, 2];
const QUARTERS_ALL = [1, 2, 3, 4];

/** Senior High report card: the 1st semester has Quarters 1–2, the 2nd has Quarters 3–4. */
function semester_quarters(int $semester): array
{
    return $semester === 2 ? [3, 4] : [1, 2];
}

function semester_label(int $semester): string
{
    return $semester === 2 ? '2nd Semester' : '1st Semester';
}

const SUBJECT_TYPES = ['core' => 'Core', 'applied' => 'Applied', 'specialized' => 'Specialized'];

function config(string $key): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require APP_ROOT . '/config/config.php';
        // Server-specific settings (database password, base_url, debug) go in config/config.local.php,
        // which is not in Git, so passwords stay off GitHub. See config/config.local.example.php.
        $localFile = APP_ROOT . '/config/config.local.php';
        if (is_file($localFile)) {
            foreach ((array) require $localFile as $name => $value) {
                $config[$name] = is_array($value) && is_array($config[$name] ?? null) && !array_is_list($value)
                    ? array_replace($config[$name], $value) // e.g. 'db' => ['pass' => …] keeps the other db settings
                    : $value;
            }
        }
    }
    return $config[$key] ?? null;
}

/**
 * Makes a database connection use the portal's time zone (shared hosts often run MySQL on UTC), and turns off
 * ONLY_FULL_GROUP_BY, which MySQL 8 has on by default, so the portal behaves the same on MySQL and MariaDB.
 */
function prepare_db_session(PDO $pdo): void
{
    $modes = array_filter(
        explode(',', (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()),
        static fn (string $mode): bool => $mode !== '' && $mode !== 'ONLY_FULL_GROUP_BY'
    );
    $offset = (new DateTime('now', new DateTimeZone((string) (config('timezone') ?: 'Asia/Manila'))))->format('P');
    $pdo->exec('SET time_zone = ' . $pdo->quote($offset) . ', sql_mode = ' . $pdo->quote(implode(',', $modes)));
}

/**
 * A setting the admin can change under Setup → Settings (stored in the settings table).
 * Falls back to config/config.php, then to a built-in default.
 */
function setting(string $key): mixed
{
    $values = settings_cache();
    if (isset($values[$key]) && $values[$key] !== '') {
        return $values[$key];
    }
    return match ($key) {
        'learner_login' => config('require_birthdate') ? 'lrn_birthdate' : 'lrn',
        'at_risk_absences' => 3,
        'current_semester' => 1,
        default => config($key) ?? '',
    };
}

/** @return array<string, string> */
function settings_cache(?array $replace = null): array
{
    static $values = null;
    if ($replace !== null) {
        $values = $replace;
    }
    if ($values === null) {
        $values = [];
        try {
            foreach (db_all('SELECT name, value FROM settings') as $row) {
                $values[$row['name']] = (string) $row['value'];
            }
        } catch (Throwable) {
            // settings table not created yet
        }
    }
    return $values;
}

function save_settings(array $pairs): void
{
    foreach ($pairs as $name => $value) {
        db_exec('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, (string) $value]);
    }
    settings_cache(array_merge(settings_cache(), array_map('strval', $pairs)));
}

/** Escape text for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) config('base_url'), '/') . '/' . ltrim($path, '/');
}

/**
 * URL of a file in public/ with its modification time added (?v=…), so browsers
 * download it again whenever it changes instead of using an old cached copy.
 */
function asset(string $path): string
{
    $file = APP_ROOT . '/public/' . ltrim($path, '/');
    return url($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    exit;
}

/** Stop and show an error page. Only call before any output. */
function abort(int $status, string $message): never
{
    http_response_code($status);
    $title = match ($status) {
        400 => 'Request not accepted',
        403 => 'Access denied',
        404 => 'Not found',
        default => 'Error',
    };
    require APP_ROOT . '/templates/header.php';
    echo '<div class="alert alert-danger"><h1 class="h5">' . e($title) . '</h1><p class="mb-0">' . e($message) . '</p></div>';
    require APP_ROOT . '/templates/footer.php';
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** A trimmed string from $_POST ('' when missing or not a string). */
function post(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function post_int(string $key): int
{
    $value = post($key);
    return ctype_digit($value) ? (int) $value : 0;
}

function get_int(string $key): int
{
    $value = $_GET[$key] ?? '';
    return is_string($value) && ctype_digit($value) ? (int) $value : 0;
}

function get_str(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

// ---------------------------------------------------------------- sessions

function start_session(): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('GRADEPORTAL');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim((string) config('base_url'), '/') . '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    $idle = $now - (int) ($_SESSION['last_activity'] ?? $now);
    $expired = false;
    if (isset($_SESSION['staff']) && $idle > (int) config('staff_idle_minutes') * 60) {
        unset($_SESSION['staff']);
        $expired = true;
    }
    if (isset($_SESSION['student_id']) && $idle > (int) config('student_idle_minutes') * 60) {
        unset($_SESSION['student_id']);
        $expired = true;
    }
    if (isset($_SESSION['parent']) && $idle > (int) config('parent_idle_minutes') * 60) {
        unset($_SESSION['parent']);
        $expired = true;
    }
    if ($expired) {
        session_regenerate_id(true);
        flash('warning', 'You were signed out because of inactivity. Please sign in again.');
    }
    $_SESSION['last_activity'] = $now;
}

/** $type is a Bootstrap colour: success, info, warning, danger. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Runs on every POST (see bootstrap.php). */
function verify_csrf(): void
{
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        abort(400, 'The upload was too large. Files must be ' . max_upload_label() . ' or smaller.');
    }
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        abort(400, 'This form expired. Go back, reload the page and try again.');
    }
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function max_upload_label(): string
{
    return round((int) config('upload_max_bytes') / 1048576, 1) . ' MB';
}

// ---------------------------------------------------------------- learners and grades

function is_valid_lrn(string $lrn): bool
{
    return preg_match('/^\d{12}$/', $lrn) === 1;
}

/** Turn a typed or spreadsheet LRN into a plain string of digits (spaces, dashes and Excel's ' prefix removed). */
function normalize_lrn(mixed $value): string
{
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        return floor($value) === $value ? sprintf('%.0f', $value) : (string) $value;
    }
    $text = ltrim(trim((string) $value), "'");
    return (string) preg_replace('/[\s-]+/', '', $text);
}

/** True for values like "1.23457E+11" — what Excel writes when an LRN column isn't formatted as Text. */
function looks_like_scientific(string $value): bool
{
    return preg_match('/^\d(\.\d+)?E\+?\d+$/i', $value) === 1;
}

function valid_ymd(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/** Accepts Excel date serials, YYYY-MM-DD, YYYY/MM/DD and MM/DD/YYYY. Returns YYYY-MM-DD or null. */
function parse_date(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value) || is_float($value)) {
        if ($value < 1 || $value > 2958465) {
            return null;
        }
        return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
    }
    $text = trim((string) $value);
    foreach (['Y-m-d', 'Y/m/d', 'm/d/Y', 'n/j/Y', 'm-d-Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $text);
        if ($date !== false && $date->format($format) === $text) {
            return $date->format('Y-m-d');
        }
    }
    return null;
}

function plausible_birthdate(string $ymd): bool
{
    return $ymd >= '1950-01-01' && $ymd < date('Y-m-d');
}

/** "DELA CRUZ, Juan S." */
function student_name(array $student): string
{
    $name = mb_strtoupper($student['last_name']) . ', ' . $student['first_name'];
    if (!empty($student['middle_name'])) {
        $name .= ' ' . mb_strtoupper(mb_substr($student['middle_name'], 0, 1)) . '.';
    }
    return $name;
}

/** "Grade 11 STEM – Einstein". Expects keys grade_level and section_name, and strand if there is one. */
function section_label(array $row): string
{
    $strand = trim((string) ($row['strand'] ?? ''));
    return 'Grade ' . $row['grade_level'] . ($strand !== '' ? ' ' . $strand : '') . ' – ' . $row['section_name'];
}

/** 88 → "88", 88.5 → "88.5", null → "". */
function format_grade(float|int|string|null $grade): string
{
    if ($grade === null || $grade === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float) $grade, 2, '.', ''), '0'), '.');
}

/**
 * Semester final grade: the average of the semester's two quarterly grades, rounded to a
 * whole number. Null until both exist.
 */
function final_grade(array $byQuarter, array $quarters): ?int
{
    if (!$quarters) {
        return null;
    }
    $sum = 0.0;
    foreach ($quarters as $q) {
        if (!isset($byQuarter[$q])) {
            return null;
        }
        $sum += (float) $byQuarter[$q];
    }
    return (int) round($sum / count($quarters));
}

function remarks(?int $final): string
{
    if ($final === null) {
        return '';
    }
    return $final >= (int) config('passing_grade') ? 'Passed' : 'Failed';
}

/** DepEd descriptors (DepEd Order No. 8, s. 2015). */
function grade_descriptor(?int $grade): string
{
    return match (true) {
        $grade === null => '',
        $grade >= 90 => 'Outstanding',
        $grade >= 85 => 'Very Satisfactory',
        $grade >= 80 => 'Satisfactory',
        $grade >= 75 => 'Fairly Satisfactory',
        default => 'Did Not Meet Expectations',
    };
}

function grade_level_options(): array
{
    return (array) config('grade_levels');
}

// ---------------------------------------------------------------- QR cards, parent link codes, attendance

const QR_PREFIX = 'SGP';

function new_qr_token(): string
{
    return bin2hex(random_bytes(16));
}

/** What a learner's QR card contains. Letters and digits only, so USB scanners type it correctly. */
function qr_payload(string $token): string
{
    return QR_PREFIX . $token;
}

/** The token inside a scanned code, or null if it isn't one of this portal's cards. */
function token_from_scan(string $scanned): ?string
{
    $code = strtolower((string) preg_replace('/\s+/', '', $scanned));
    $prefix = strtolower(QR_PREFIX);
    if (str_starts_with($code, $prefix)) {
        $code = substr($code, strlen($prefix));
    }
    return preg_match('/^[0-9a-f]{32}$/', $code) === 1 ? $code : null;
}

/** QR image as a data: URI (SVG) for an <img src>. */
function qr_image_uri(string $data): string
{
    $options = new \chillerlan\QRCode\QROptions([
        'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::M,
        'addQuietzone' => true,
        'connectPaths' => true,
    ]);
    return (new \chillerlan\QRCode\QRCode($options))->render($data);
}

const PARENT_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I/L, which are easy to mix up

function new_parent_code(): string
{
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= PARENT_CODE_ALPHABET[random_int(0, strlen(PARENT_CODE_ALPHABET) - 1)];
    }
    return $code;
}

/** "ABCD-EFGH" */
function format_parent_code(string $code): string
{
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

function normalize_parent_code(string $typed): string
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $typed));
}

/**
 * Parents sign in with a mobile number or an email address.
 * Mobile numbers are stored as 09XXXXXXXXX (+63 9XX… is accepted). Returns null if it's neither.
 */
function normalize_parent_login(string $typed): ?string
{
    $typed = trim($typed);
    if (str_contains($typed, '@')) {
        $email = mb_strtolower($typed);
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 150 ? $email : null;
    }
    $digits = (string) preg_replace('/\D/', '', $typed);
    if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
        $digits = '0' . substr($digits, 2);
    } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        $digits = '0' . $digits;
    }
    return preg_match('/^09\d{9}$/', $digits) === 1 ? $digits : null;
}

const RELATIONSHIPS = ['mother' => 'Mother', 'father' => 'Father', 'guardian' => 'Guardian'];

const ATTENDANCE_STATUSES = ['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused'];

/** Badge class and icon per attendance status (kept in step with assets/attendance.js). */
const ATTENDANCE_STYLE = [
    'present' => ['text-bg-success', 'check-circle-fill'],
    'late'    => ['text-bg-warning', 'clock-fill'],
    'absent'  => ['text-bg-danger', 'x-circle-fill'],
    'excused' => ['text-bg-info', 'info-circle-fill'],
];

function attendance_badge(?string $status): string
{
    [$class, $icon] = ATTENDANCE_STYLE[$status] ?? ['text-bg-light border', 'circle'];
    return '<span class="badge ' . $class . '"><i class="bi bi-' . $icon . '" aria-hidden="true"></i>'
        . e(ATTENDANCE_STATUSES[$status] ?? 'Not yet') . '</span>';
}

// ---------------------------------------------------------------- look and feel

/** The school crest used as the logo (inline SVG). */
function brand_mark(int $size = 38): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 40 40" aria-hidden="true" focusable="false">'
        . '<path d="M20 2 34.5 7.2v10.6c0 9.6-6.1 16.9-14.5 20.2C11.6 34.7 5.5 27.4 5.5 17.8V7.2z" fill="#f5b82e"/>'
        . '<path d="M20 5.6 31.3 9.6v8.3c0 7.6-4.6 13.5-11.3 16.6-6.7-3.1-11.3-9-11.3-16.6V9.6z" fill="#102452"/>'
        . '<path d="M11.5 16 20 12.2 28.5 16 20 19.8z" fill="#f5b82e"/>'
        . '<path d="M14.8 18.4v4.3c0 1.7 2.3 3 5.2 3s5.2-1.3 5.2-3v-4.3L20 20.7z" fill="#fff"/>'
        . '<path d="M28.5 16v5.2" stroke="#f5b82e" stroke-width="1.4" stroke-linecap="round"/>'
        . '<circle cx="28.5" cy="22" r="1.2" fill="#f5b82e"/>'
        . '</svg>';
}

function favicon_uri(): string
{
    return 'data:image/svg+xml,' . rawurlencode(brand_mark(64));
}

/** "Maria Santos" → "MS" */
function initials(string $name): string
{
    $words = preg_split('/[\s,]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $first = mb_substr($words[0], 0, 1);
    $last = count($words) > 1 ? mb_substr(end($words), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** A stable colour (hue 0–359) for a name or subject code, so each one always looks the same. */
function color_hue(string $key): int
{
    return crc32(mb_strtolower($key)) % 360;
}

function avatar(string $name, string $extraClass = ''): string
{
    return '<span class="avatar ' . e($extraClass) . '" style="--hue: ' . color_hue($name) . '" aria-hidden="true">' . e(initials($name)) . '</span>';
}

/** "9:05 AM" */
function format_time(?string $datetime): string
{
    return $datetime ? date('g:i A', strtotime($datetime)) : '';
}

/** Full address of a page, for printing (uses 'public_url' from the config when set). */
function public_url(string $path = ''): string
{
    $base = rtrim((string) setting('public_url'), '/');
    if ($base === '') {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim((string) config('base_url'), '/');
    }
    return $base . '/' . ltrim($path, '/');
}

/** <datalist> of common strands, for the section strand box (any text is still allowed). */
function strand_datalist(string $id): string
{
    $html = '<datalist id="' . e($id) . '">';
    foreach ((array) config('strands') as $strand) {
        $html .= '<option value="' . e($strand) . '">';
    }
    return $html . '</datalist>';
}
