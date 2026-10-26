<?php
declare(strict_types=1);

namespace App;

/** Minimal cURL wrapper for platform APIs. */
final class Http
{
    /**
     * @param array $options headers => [k => v], json => array, form => array, multipart => array, body => string, timeout => int
     * @return array{status:int, body:string, json:array, headers:array}
     */
    /** Test hook: when set, receives (method, url, options) and returns a response array instead of calling cURL. */
    public static ?\Closure $fake = null;

    public static function request(string $method, string $url, array $options = []): array
    {
        if (self::$fake !== null) {
            if (isset($options['query']) && $options['query']) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
            }
            $r = (self::$fake)(strtoupper($method), $url, $options);
            return $r + ['status' => 200, 'body' => json_encode($r['json'] ?? []), 'json' => [], 'headers' => []];
        }
        $ch = curl_init();
        $headers = [];
        foreach ($options['headers'] ?? [] as $k => $v) {
            $headers[] = "$k: $v";
        }

        if (isset($options['json'])) {
            $body = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        } elseif (isset($options['form'])) {
            $body = http_build_query($options['form']);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (isset($options['multipart'])) {
            $body = $options['multipart'];
        } else {
            $body = $options['body'] ?? null;
        }

        if (isset($options['query']) && $options['query']) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
        }

        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 60,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: MixCreate/1.0'], $headers),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            // Send an explicit empty body so servers get Content-Length: 0.
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Request to " . parse_url($url, PHP_URL_HOST) . " failed: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = json_decode($raw, true);
        return ['status' => $status, 'body' => $raw, 'json' => is_array($json) ? $json : [], 'headers' => $respHeaders];
    }

    public static function get(string $url, array $options = []): array
    {
        return self::request('GET', $url, $options);
    }

    public static function post(string $url, array $options = []): array
    {
        return self::request('POST', $url, $options);
    }

    public static function ok(array $response): bool
    {
        return $response['status'] >= 200 && $response['status'] < 300;
    }
}
