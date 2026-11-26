<?php
declare(strict_types=1);

/**
 * Online payments through PayMongo's hosted checkout (GCash, Maya, cards, QR Ph). Settings: 'paymongo' in
 * config/config.local.php (secret key sk_test_… or sk_live_…, and the webhook's secret).
 *
 * A payment is confirmed in two ways, whichever comes first: PayMongo calls paymongo-webhook.php
 * ("checkout_session.payment.paid", signed with the webhook secret), and billing.php asks PayMongo when the payer
 * comes back from the checkout page. Both are safe to repeat.
 */
final class PayMongo
{
    private const API = 'https://api.paymongo.com/v1';
    public const METHODS = ['gcash', 'paymaya', 'card', 'qrph'];

    /** True when online payments can be made (a secret key, and the site's address for the return links). */
    public static function ready(): bool
    {
        return self::secret() !== '' && Mailer::appUrl() !== null;
    }

    /** True with test keys (sk_test_…): nothing is really charged. */
    public static function testMode(): bool
    {
        return str_starts_with(self::secret(), 'sk_test_');
    }

    public static function webhookUrl(): ?string
    {
        $base = Mailer::appUrl();
        return $base !== null ? $base . '/paymongo-webhook.php' : null;
    }

    /** Opens a hosted checkout for a payment. @return array{0: string, 1: string} [checkout session id, checkout URL] */
    public static function createCheckout(array $payment, string $email = ''): array
    {
        $base = (string) Mailer::appUrl();
        $attributes = [
            'line_items' => [[
                'name' => mb_substr((string) $payment['description'], 0, 255),
                'amount' => (int) $payment['amount'],
                'currency' => 'PHP',
                'quantity' => 1,
            ]],
            'payment_method_types' => self::METHODS,
            'success_url' => $base . '/billing.php?return=' . (int) $payment['id'],
            'cancel_url' => $base . '/billing.php?cancelled=' . (int) $payment['id'],
            'description' => mb_substr((string) $payment['description'], 0, 255),
            'reference_number' => 'AT-' . (int) $payment['id'],
            'send_email_receipt' => $email !== '',
            'show_description' => true,
            'show_line_items' => true,
            'metadata' => ['payment_id' => (string) $payment['id'], 'workspace_id' => (string) $payment['workspace_id']],
        ];
        if ($email !== '') {
            $attributes['billing'] = ['email' => $email];
        }
        $response = self::request('POST', '/checkout_sessions', ['data' => ['attributes' => $attributes]]);
        $id = (string) ($response['data']['id'] ?? '');
        $url = (string) ($response['data']['attributes']['checkout_url'] ?? '');
        if ($id === '' || $url === '') {
            throw new RuntimeException('PayMongo did not return a checkout page.');
        }
        return [$id, $url];
    }

    /** How a checkout was paid (gcash, card, …), or null when it isn't paid (yet). */
    public static function paidMethod(string $checkoutId): ?string
    {
        if (!preg_match('/^cs_[A-Za-z0-9]+$/', $checkoutId)) {
            return null;
        }
        $response = self::request('GET', '/checkout_sessions/' . $checkoutId);
        return self::methodIfPaid($response['data'] ?? []);
    }

    /** From a checkout session object: the payment method when paid, else null. */
    public static function methodIfPaid(array $session): ?string
    {
        $attributes = $session['attributes'] ?? [];
        foreach ((array) ($attributes['payments'] ?? []) as $payment) {
            if (($payment['attributes']['status'] ?? '') === 'paid') {
                return (string) ($payment['attributes']['source']['type'] ?? 'online');
            }
        }
        if (($attributes['payment_intent']['attributes']['status'] ?? '') === 'succeeded') {
            return 'online';
        }
        return null;
    }

    /**
     * A webhook call, if its Paymongo-Signature header is right: "t=<time>,te=<test sig>,li=<live sig>", where a
     * signature is HMAC-SHA256 of "<time>.<raw body>" with the webhook secret. Returns the event, or null.
     */
    public static function webhookEvent(string $raw, string $header): ?array
    {
        $secret = (string) (config('paymongo')['webhook_secret'] ?? '');
        if ($secret === '' || $raw === '') {
            return null;
        }
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$key] = $value;
        }
        if (!isset($parts['t']) || !ctype_digit($parts['t'])) {
            return null;
        }
        $expected = hash_hmac('sha256', $parts['t'] . '.' . $raw, $secret);
        $matches = false;
        foreach (['te', 'li'] as $key) {
            if (($parts[$key] ?? '') !== '' && hash_equals($expected, $parts[$key])) {
                $matches = true;
            }
        }
        $event = $matches ? json_decode($raw, true) : null;
        return is_array($event) ? $event : null;
    }

    /** The paid checkout session in a "checkout_session.payment.paid" event (either documented layout), or null. */
    public static function paidSession(array $event): ?array
    {
        $type = $event['data']['attributes']['type'] ?? ($event['data']['type'] ?? '');
        if ($type !== 'checkout_session.payment.paid') {
            return null;
        }
        $session = $event['data']['attributes']['data'] ?? ($event['data']['data'] ?? null);
        return is_array($session) && isset($session['id']) ? $session : null;
    }

    private static function secret(): string
    {
        return trim((string) (((array) config('paymongo'))['secret_key'] ?? ''));
    }

    private static function request(string $method, string $path, ?array $body = null): array
    {
        $curl = curl_init(self::API . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => self::secret() . ':',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($raw === false) {
            throw new RuntimeException('Could not reach PayMongo: ' . $error);
        }
        $data = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            $detail = is_array($data) ? (string) ($data['errors'][0]['detail'] ?? '') : '';
            throw new RuntimeException("PayMongo answered {$status}" . ($detail !== '' ? ": {$detail}" : '.'));
        }
        return $data;
    }
}
