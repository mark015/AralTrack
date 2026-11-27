<?php
/**
 * Sends the phone alerts and text messages that are waiting. Optional: the portal already sends them right
 * after each scan, and on page loads. A cron job makes sure nothing waits when nobody is using the portal:
 *
 *   * * * * *  php /path/to/student-portal/bin/send-alerts.php
 *
 * (Hostinger: hPanel → Advanced → Cron Jobs, "Every minute".)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

$done = Alerts::run(50);
if (in_array('-v', $argv, true)) {
    echo date('Y-m-d H:i:s') . " phone alerts: {$done['push']}, texts: {$done['sms']}\n";
}
