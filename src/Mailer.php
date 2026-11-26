<?php
declare(strict_types=1);

/**
 * Sends plain-text emails (sign-up codes, password resets). Settings: 'mail' in config/config.php.
 *   smtp : through a mailbox, e.g. a Hostinger email account (SSL on port 465, or STARTTLS on 587)
 *   mail : PHP's mail()
 *   log  : nothing is sent; each email is saved in storage/mail/ (testing on your own computer, debug on)
 */
final class Mailer
{
    /** True when emails can really be sent (or, on a test computer with debug on, saved to storage/mail/). */
    public static function ready(): bool
    {
        $c = self::config();
        return match ($c['transport']) {
            'smtp' => $c['host'] !== '' && $c['from'] !== '' && $c['username'] !== '' && $c['password'] !== '' && self::appUrl() !== null,
            'mail' => $c['from'] !== '' && self::appUrl() !== null,
            'log' => (bool) config('debug'),
            default => false,
        };
    }

    /** True when emails are only saved to storage/mail/ (a test computer). */
    public static function isTestMode(): bool
    {
        return self::config()['transport'] === 'log';
    }

    /**
     * The site's address for links in emails. Never taken from the visitor's request on a live server, so a
     * forged Host header can't put another site's address in a password-reset link.
     */
    public static function appUrl(): ?string
    {
        $url = rtrim((string) config('app_url'), '/');
        if ($url !== '') {
            return $url;
        }
        if (config('debug')) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim((string) config('base_url'), '/');
        }
        return null;
    }

    /** Sends one email. Throws RuntimeException when it can't be sent. */
    public static function send(string $to, string $toName, string $subject, string $text): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $toName . $subject)) {
            throw new RuntimeException('Invalid email address or subject.');
        }
        $c = self::config();
        $from = $c['from'] !== '' ? $c['from'] : 'no-reply@localhost';
        $headers = [
            'From' => self::address($from, $c['from_name']),
            'To' => self::address($to, $toName),
            'Subject' => self::encode($subject),
            'Date' => date('r'),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . (substr(strrchr($from, '@') ?: '@localhost', 1)) . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ];
        $body = rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $text)), 76, "\r\n"));

        match ($c['transport']) {
            'smtp' => self::smtp($c, $from, $to, $headers, $body),
            'mail' => self::phpMail($from, $to, $headers, $body),
            'log' => self::log($headers, $text),
            default => throw new RuntimeException('Unknown mail transport.'),
        };
    }

    private static function config(): array
    {
        return array_merge(
            ['transport' => 'log', 'from' => '', 'from_name' => (string) config('app_name'), 'host' => '', 'port' => 465, 'encryption' => 'ssl', 'username' => '', 'password' => ''],
            (array) config('mail')
        );
    }

    private static function address(string $email, string $name): string
    {
        return $name === '' ? $email : self::encode($name) . ' <' . $email . '>';
    }

    private static function encode(string $text): string
    {
        return preg_match('/[^\x20-\x7e]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
    }

    private static function headerBlock(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        return implode("\r\n", $lines);
    }

    private static function log(array $headers, string $text): void
    {
        $dir = APP_ROOT . '/storage/mail';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create storage/mail.');
        }
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.txt';
        $plain = $headers;
        unset($plain['Content-Transfer-Encoding']);
        file_put_contents($dir . '/' . $name, self::headerBlock($plain) . "\r\n\r\n" . $text . "\r\n");
    }

    private static function phpMail(string $from, string $to, array $headers, string $body): void
    {
        $extra = $headers;
        unset($extra['To'], $extra['Subject']);
        if (!mail($to, $headers['Subject'], $body, self::headerBlock($extra), '-f' . $from)) {
            throw new RuntimeException('PHP mail() could not send the email.');
        }
    }

    /** A small SMTP client: implicit TLS (465), STARTTLS (587) or plain, with AUTH LOGIN. */
    private static function smtp(array $c, string $from, string $to, array $headers, string $body): void
    {
        $encryption = strtolower((string) $c['encryption']);
        $host = (string) $c['host'];
        $context = stream_context_create(['ssl' => [
            'peer_name' => $c['peer_name'] ?? $host,
            'verify_peer' => $c['verify_peer'] ?? true,
            'verify_peer_name' => $c['verify_peer'] ?? true,
            'allow_self_signed' => !($c['verify_peer'] ?? true),
        ]]);
        $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . (int) $c['port'];
        $socket = @stream_socket_client($target, $errno, $error, 20, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new RuntimeException("Could not connect to the mail server {$host}: {$error}");
        }
        stream_set_timeout($socket, 20);
        try {
            $read = static function () use ($socket): array {
                $lines = '';
                while (($line = fgets($socket, 1024)) !== false) {
                    $lines .= $line;
                    if (strlen($line) < 4 || $line[3] === ' ') {
                        break; // the last line of a reply: "250 OK" (a dash after the code means more lines follow)
                    }
                }
                return [(int) substr($lines, 0, 3), trim($lines)];
            };
            $command = static function (string $line, array $expect, bool $secret = false) use ($socket, $read): string {
                fwrite($socket, $line . "\r\n");
                [$code, $reply] = $read();
                if (!in_array($code, $expect, true)) {
                    // never the user name or password in an error message (it goes to the server's log)
                    $shown = $secret ? 'sign-in' : (strlen($line) > 60 ? 'the message' : $line);
                    throw new RuntimeException("The mail server refused \"{$shown}\": {$reply}");
                }
                return $reply;
            };

            [$code, $reply] = $read();
            if ($code !== 220) {
                throw new RuntimeException("The mail server did not greet: {$reply}");
            }
            $me = preg_replace('/[^A-Za-z0-9.-]/', '', (string) parse_url((string) self::appUrl(), PHP_URL_HOST)) ?: 'localhost';
            $features = $command('EHLO ' . $me, [250]);
            if ($encryption === 'tls') {
                $command('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('Could not start an encrypted connection with the mail server.');
                }
                $features = $command('EHLO ' . $me, [250]);
            }
            if ($c['username'] !== '') {
                if (stripos($features, 'AUTH') === false) {
                    throw new RuntimeException('The mail server does not offer sign-in on this port.');
                }
                $command('AUTH LOGIN', [334]);
                $command(base64_encode((string) $c['username']), [334], true);
                $command(base64_encode((string) $c['password']), [235], true);
            }
            $command('MAIL FROM:<' . $from . '>', [250]);
            $command('RCPT TO:<' . $to . '>', [250, 251]);
            $command('DATA', [354]);
            // A line that starts with "." gets a second one (SMTP "dot-stuffing"); base64 lines never do.
            $data = preg_replace('/^\./m', '..', self::headerBlock($headers) . "\r\n\r\n" . $body);
            $command($data . "\r\n.", [250]);
            fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }
}
