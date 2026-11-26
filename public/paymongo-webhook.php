<?php
// PayMongo calls this when a checkout is paid ("checkout_session.payment.paid"). The Paymongo-Signature header is
// checked with the webhook secret (config 'paymongo' → 'webhook_secret'); a call without the right signature is
// refused. Safe to receive the same event twice. Always answers quickly, so PayMongo doesn't retry needlessly.
define('SKIP_CSRF', true);
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$raw = (string) file_get_contents('php://input');
$event = PayMongo::webhookEvent($raw, (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? ''));
if ($event === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad signature']);
    exit;
}
$session = PayMongo::paidSession($event);
if ($session !== null) {
    $payment = db_one("SELECT * FROM payments WHERE provider = 'paymongo' AND provider_ref = ?", [$session['id']]);
    if ($payment) {
        Billing::markPaid((int) $payment['id'], PayMongo::methodIfPaid($session) ?? 'online'); // a signed "paid" event
    } elseif (!$payment) {
        error_log('PayMongo webhook: no payment for checkout ' . $session['id']);
    }
}
echo json_encode(['ok' => true]);
