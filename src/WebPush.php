<?php
declare(strict_types=1);

/**
 * Phone alerts without SMS costs: the Web Push standard (RFC 8030), with VAPID sign-in (RFC 8292) and
 * end-to-end encrypted messages (RFC 8291, "aes128gcm"). Works in Chrome, Edge and Firefox on Android and
 * computers, and in Safari on iPhone when the portal was added to the home screen (iOS 16.4 or later).
 *
 * The server's VAPID key pair is made once and kept in platform_settings. Only the push services of the big
 * browsers are contacted (see SERVICES), so a made-up "subscription" can't make the server call other sites.
 */
final class WebPush
{
    /** Hosts of the browsers' push services */
    private const SERVICES = ['fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    /** DER prefix of a P-256 public key (SubjectPublicKeyInfo), followed by the 65-byte uncompressed point */
    private const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Alerts older than this are dropped by the push service if the phone stays offline */
    private const TTL = 43200;

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        $raw = base64_decode(strtr(trim($text), '-_', '+/') . str_repeat('=', (4 - strlen(trim($text)) % 4) % 4), true);
        return $raw === false ? '' : $raw;
    }

    /** True for an https address of a known browser push service. */
    public static function allowedEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port']) || strlen($url) > 1000) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::SERVICES as $service) {
            if ($host === $service || str_ends_with($host, '.' . $service)) {
                return true;
            }
        }
        return false;
    }

    /** The public key browsers need to subscribe (base64url), made on first use. */
    public static function publicKey(): string
    {
        return self::keys()['public'];
    }

    /** @return array{public: string, private: string, subject: string} */
    private static function keys(): array
    {
        $read = static fn (): array => array_column(
            db_all("SELECT name, value FROM platform_settings WHERE name IN ('vapid_public', 'vapid_private', 'vapid_subject')"),
            'value',
            'name'
        );
        $keys = $read();
        if (empty($keys['vapid_public']) || empty($keys['vapid_private'])) {
            $key = self::newKey();
            $details = openssl_pkey_get_details($key);
            if (!openssl_pkey_export($key, $pem, null, self::opensslOptions())) {
                throw new RuntimeException('Could not save the key for phone alerts.');
            }
            $subject = (string) (config('app_url') ?: ((!empty($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'mailto:alerts@localhost')));
            // INSERT IGNORE: if two requests make keys at the same time, the first one is kept by both
            foreach (['vapid_public' => self::b64(self::point($details)), 'vapid_private' => $pem, 'vapid_subject' => $subject] as $name => $value) {
                db_exec('INSERT IGNORE INTO platform_settings (name, value) VALUES (?, ?)', [$name, $value]);
            }
            $keys = $read();
        }
        // Who sends (push services may contact this address): the portal's address when it is set in the config
        $appUrl = (string) (config('app_url') ?? '');
        $subject = str_starts_with($appUrl, 'https://') ? rtrim($appUrl, '/') : (string) ($keys['vapid_subject'] ?? 'mailto:alerts@localhost');
        return ['public' => (string) $keys['vapid_public'], 'private' => (string) $keys['vapid_private'], 'subject' => $subject];
    }

    /**
     * Sends one alert to one phone. Returns the push service's HTTP status (201 = accepted; 404 or 410 = the phone
     * turned alerts off, so the subscription should be deleted), or 0 when the service could not be reached.
     * @param array{endpoint: string, p256dh: string, auth: string} $subscription
     */
    public static function send(array $subscription, array $payload, string $urgency = 'normal'): int
    {
        if (!self::allowedEndpoint($subscription['endpoint'])) {
            return 410;
        }
        try {
            $body = self::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $subscription['p256dh'], $subscription['auth']);
        } catch (Throwable) {
            return 410; // the browser's keys are not valid
        }
        $curl = curl_init($subscription['endpoint']);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: ' . self::TTL,
                'Urgency: ' . (in_array($urgency, ['very-low', 'low', 'normal', 'high'], true) ? $urgency : 'normal'),
                'Authorization: ' . self::vapid($subscription['endpoint']),
            ],
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        return $status;
    }

    /** The "vapid t=…, k=…" header: a signed token saying which server sends, valid for 12 hours. */
    private static function vapid(string $endpoint): string
    {
        $keys = self::keys();
        $parts = parse_url($endpoint);
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64(json_encode([
            'aud' => $parts['scheme'] . '://' . $parts['host'],
            'exp' => time() + 12 * 3600,
            'sub' => $keys['subject'],
        ], JSON_UNESCAPED_SLASHES));
        $private = openssl_pkey_get_private($keys['private']);
        if ($private === false || !openssl_sign("{$header}.{$claims}", $der, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the phone alert.');
        }
        return 'vapid t=' . "{$header}.{$claims}." . self::b64(self::derToRaw($der)) . ', k=' . $keys['public'];
    }

    /**
     * Encrypts a message for one browser (RFC 8291). $localKey and $salt are only passed by tests; normally a new
     * key and salt are made for every message.
     */
    public static function encrypt(string $plaintext, string $p256dh, string $auth, ?OpenSSLAsymmetricKey $localKey = null, ?string $salt = null): string
    {
        $uaPublic = self::unb64($p256dh);
        $authSecret = self::unb64($auth);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) < 16) {
            throw new InvalidArgumentException('Not a valid browser key.');
        }
        $localKey ??= self::newKey();
        $salt ??= random_bytes(16);
        $asPublic = self::point(openssl_pkey_get_details($localKey));

        $uaKey = openssl_pkey_get_public(self::pem($uaPublic));
        $shared = $uaKey !== false ? openssl_pkey_derive($uaKey, $localKey, 32) : false;
        if ($shared === false) {
            throw new InvalidArgumentException('Not a valid browser key.');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // One record: the message, then the 0x02 "last record" delimiter
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Could not encrypt the phone alert.');
        }
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** A new P-256 key. Windows PHP builds may have no OpenSSL config file, so one is given. */
    private static function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + self::opensslOptions());
        if ($key === false) {
            throw new RuntimeException('Could not make a key for phone alerts (OpenSSL).');
        }
        return $key;
    }

    private static function opensslOptions(): array
    {
        $config = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'araltrack-openssl.cnf';
        if (!is_file($config)) {
            @file_put_contents($config, "[req]\ndistinguished_name = dn\ndefault_bits = 2048\n[dn]\n");
        }
        return is_file($config) ? ['config' => $config, 'private_key_bits' => 2048] : ['private_key_bits' => 2048];
    }

    /** The 65-byte uncompressed public point (0x04 || x || y) of a key's details. */
    private static function point(array $details): string
    {
        return "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function pem(string $point): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::P256_SPKI) . $point), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** OpenSSL's DER signature (SEQUENCE of two INTEGERs) as the 64 bytes r || s that JWT uses. */
    private static function derToRaw(string $der): string
    {
        $pos = 2 + ((ord($der[1]) & 0x80) ? (ord($der[1]) & 0x7f) : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$pos + 1]);
            $out .= str_pad(ltrim(substr($der, $pos + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $length;
        }
        return $out;
    }
}
