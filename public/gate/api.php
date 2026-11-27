<?php
// The gate scanner's server side (gate/index.php). Answers are JSON.
//   POST action=scan   a card scanned (or an LRN typed) now: code, mode (auto | in | out)
//   POST action=sync   scans saved on the phone while there was no internet: events = JSON list
//   GET  action=today  today's counts and latest scans
require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$reply = static function (array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (current_staff() === null) {
    $reply(['ok' => false, 'signin' => true, 'kind' => 'error', 'accepted' => false, 'message' => 'You were signed out. Sign in again to keep scanning.'], 401);
}
$staff = require_staff();
if (Workspace::isSolo() || !Access::on('gate')) {
    $reply(['ok' => false, 'kind' => 'error', 'accepted' => false, 'message' => 'Gate attendance is turned off by the school.'], 403);
}
if (!Gate::mayScan($staff)) {
    $reply(['ok' => false, 'kind' => 'error', 'accepted' => false, 'message' => 'Your account cannot use the gate scanner.'], 403);
}

$recent = static fn (): array => array_map(static fn ($r) => [
    'name' => student_name($r),
    'section' => $r['section_name'] !== null ? section_label($r) : '',
    'direction' => $r['direction'],
    'late' => (bool) $r['late'],
    'time' => format_time($r['scanned_at']),
], Gate::day(date('Y-m-d'), null, '', 8));

$action = is_post() ? post('action') : get_str('action');
if ($action === 'scan') {
    $result = Gate::scan(mb_substr(post('code'), 0, 200), post('mode'), $staff['id']);
    $reply($result + ['ok' => true, 'counts' => Gate::counts(date('Y-m-d')), 'recent' => $result['accepted'] ? $recent() : null]);
}
if ($action === 'sync') {
    $events = json_decode(post('events'), true);
    if (!is_array($events)) {
        $reply(['ok' => false, 'message' => 'Nothing to send.'], 400);
    }
    $results = Gate::sync(array_values(array_filter($events, 'is_array')), $staff['id']);
    $reply(['ok' => true, 'results' => $results, 'counts' => Gate::counts(date('Y-m-d')), 'recent' => $recent()]);
}
if ($action === 'today') {
    $reply(['ok' => true, 'counts' => Gate::counts(date('Y-m-d')), 'recent' => $recent()]);
}
$reply(['ok' => false, 'message' => 'Unknown action.'], 400);
