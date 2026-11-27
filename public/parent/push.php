<?php
// Phone alerts for a parent (assets/push.js). Answers are JSON.
//   POST action=subscribe    this browser's push subscription: endpoint, p256dh, auth
//   POST action=unsubscribe  endpoint
//   POST action=test         sends a test alert to this browser: endpoint
require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$reply = static function (array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
if (!is_post()) {
    $reply(['ok' => false, 'message' => 'Send a POST request.'], 405);
}
if (current_parent() === null) {
    $reply(['ok' => false, 'message' => 'You were signed out. Sign in again.'], 401);
}
$parent = require_parent();
// The same person's accounts at other schools (signed in together) get alerts on this phone too
$ids = array_values(array_unique(array_merge([(int) $parent['id']], Accounts::ids('parent'))));
$endpoint = mb_substr(trim(post('endpoint')), 0, 1000);

switch (post('action')) {
    case 'subscribe':
        if (!Alerts::pushOn()) {
            $reply(['ok' => false, 'message' => 'The school has turned phone alerts off.']);
        }
        if (!Alerts::subscribe($ids, $endpoint, post('p256dh'), post('auth'), Access::device())) {
            $reply(['ok' => false, 'message' => 'This browser\'s alert service is not supported. Try Chrome, Edge, Firefox or Safari.'], 400);
        }
        $reply(['ok' => true, 'message' => 'Alerts are on for this phone.']);
        // no break: $reply stops
    case 'unsubscribe':
        Alerts::unsubscribe($ids, $endpoint);
        $reply(['ok' => true, 'message' => 'Alerts are off for this phone.']);
        // no break
    case 'test':
        $device = db_one('SELECT * FROM push_subscriptions WHERE parent_id = ? AND endpoint_hash = ?', [$parent['id'], hash('sha256', $endpoint)]);
        if (!$device) {
            $reply(['ok' => false, 'message' => 'Alerts are not on for this phone yet.'], 404);
        }
        $status = Alerts::deliver($device, [
            'title' => 'Alerts are working',
            'body' => 'This is how ' . setting('school_name') . ' will tell you when your child arrives at school, is late or absent, and more.',
            'url' => 'parent/notifications.php',
            'tag' => 'test',
        ], 'high');
        $reply($status >= 200 && $status < 300
            ? ['ok' => true, 'message' => 'A test alert was sent. It should appear in a few seconds.']
            : ['ok' => false, 'message' => $status === 0 ? 'The alert service could not be reached. Try again in a moment.' : 'This phone no longer accepts alerts. Turn them on again.']);
        // no break
    default:
        $reply(['ok' => false, 'message' => 'Unknown action.'], 400);
}
