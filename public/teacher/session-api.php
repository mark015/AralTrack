<?php
// Saves attendance for the attendance page: a QR scan, or a mark set by hand.
// The page's JavaScript posts with ajax=1 and gets JSON back. Without JavaScript,
// the mark forms post here normally and are sent back to the page.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if (!is_post()) {
    redirect('teacher/index.php');
}
$ajax = post('ajax') === '1';
$sessionId = post_int('session_id');

$respond = static function (array $data, int $status = 200) use ($ajax, $sessionId): never {
    if ($ajax) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $type = ['success' => 'success', 'warning' => 'warning', 'info' => 'info'][$data['kind']] ?? 'danger';
    flash($type, $data['message']);
    redirect('teacher/session.php?id=' . $sessionId);
};

if (current_staff() === null) {
    $respond(['kind' => 'error', 'accepted' => false, 'message' => 'You were signed out. Reload the page and sign in again.'], 401);
}
$staff = require_staff();
$session = teacher_session_or_abort($sessionId, $staff);

$result = match (post('action')) {
    'scan' => Attendance::scan($session, post('code'), $staff['id']),
    'mark' => Attendance::mark($session, post_int('student_id'), post('status'), post('note'), $staff['id']),
    default => ['kind' => 'error', 'accepted' => false, 'message' => 'Unknown action.'],
};
$result['counts'] = Attendance::counts($session);
$respond($result);
