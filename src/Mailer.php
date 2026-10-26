<?php
declare(strict_types=1);

namespace App;

/**
 * Sends email through the SMTP server in Settings → Integrations, or PHP's mail() when none is set.
 * Supports SSL (port 465), STARTTLS (587) and plain connections, with AUTH LOGIN.
 */
final class Mailer
{
    /** @param array<array{name: string, mime: string, data: string}> $attachments */
    public static function send(array $to, string $subject, string $html, array $attachments = []): void
    {
        $s = Settings::get('smtp');
        $fromEmail = $s['from_email'] ?? '';
        if ($fromEmail === '') {
            throw new \RuntimeException('Set a sender address under Integrations → Email first.');
        }
        $fromName = $s['from_name'] ?? Config::get('app_name', 'MixCreate');
        $to = array_values(array_filter(array_map('trim', $to), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if (!$to) {
            throw new \RuntimeException('No valid recipients.');
        }

        $boundary = 'mc' . bin2hex(random_bytes(8));
        $alt = 'alt' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . self::encodeHeader($fromName) . " <$fromEmail>",
            'To: ' . implode(', ', $to),
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $fromEmail)[1] ?? 'mixcreate') . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"$boundary\"",
        ];
        $text = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h\d|/tr|/li)[^>]*>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $body = "--$boundary\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n"
            . "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
            . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
            . "--$alt--\r\n";
        foreach ($attachments as $att) {
            $body .= "--$boundary\r\nContent-Type: {$att['mime']}; name=\"{$att['name']}\"\r\nContent-Disposition: attachment; filename=\"{$att['name']}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($att['data'])) . "\r\n";
        }
        $body .= "--$boundary--\r\n";

        if (empty($s['host'])) {
            $plainHeaders = array_filter($headers, fn($h) => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:'));
            if (!mail(implode(', ', $to), self::encodeHeader($subject), $body, implode("\r\n", $plainHeaders))) {
                throw new \RuntimeException('PHP mail() failed. Configure an SMTP server under Integrations → Email.');
            }
            return;
        }
        self::smtp($s, $fromEmail, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
    }

    private static function smtp(array $s, string $from, array $to, string $message): void
    {
        $port = (int) ($s['port'] ?? 587);
        $enc = $s['encryption'] ?? ($port === 465 ? 'ssl' : 'tls');
        $host = ($enc === 'ssl' ? 'ssl://' : '') . $s['host'];
        $fp = @stream_socket_client("$host:$port", $errno, $errstr, 20);
        if (!$fp) {
            throw new \RuntimeException("Couldn’t connect to the mail server: $errstr");
        }
        stream_set_timeout($fp, 30);
        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function (string $c, array $expect) use ($fp, $read): string {
            if ($c !== '') {
                fwrite($fp, $c . "\r\n");
            }
            $resp = $read();
            if (!in_array((int) substr($resp, 0, 3), $expect, true)) {
                $shown = str_starts_with($c, 'AUTH') || strlen($c) > 60 ? strtok($c, ' ') : $c;
                throw new \RuntimeException('Mail server rejected ' . ($shown ?: 'the connection') . ': ' . trim($resp));
            }
            return $resp;
        };
        $ehloHost = parse_url(abs_url(''), PHP_URL_HOST) ?: 'localhost';
        $cmd('', [220]);
        $cmd("EHLO $ehloHost", [250]);
        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed.');
            }
            $cmd("EHLO $ehloHost", [250]);
        }
        if (!empty($s['username'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($s['username']), [334]);
            $cmd(base64_encode((string) ($s['password'] ?? '')), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        foreach ($to as $rcpt) {
            $cmd("RCPT TO:<$rcpt>", [250, 251]);
        }
        $cmd('DATA', [354]);
        // Dot-stuffing per RFC 5321.
        $message = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $message));
        $cmd($message . "\r\n.", [250]);
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }
}
