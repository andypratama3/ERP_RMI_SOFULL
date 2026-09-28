<?php
/**
 * Tools HTTP — base URL, cURL-based GET (no shell_exec).
 */
declare(strict_types=1);

if (!function_exists('tools_get_base_url')) {
    /**
     * Base URL for smoke/CLI (default: internal). FAIL if empty.
     * Priority: TOOLS_BASE_URL_INTERNAL > TOOLS_BASE_URL > APP_BASE_URL > APP_URL
     */
    function tools_get_base_url(): ?string
    {
        $raw = trim((string)(
            getenv('TOOLS_BASE_URL_INTERNAL') ?:
            getenv('TOOLS_BASE_URL') ?:
            getenv('APP_BASE_URL') ?:
            getenv('APP_URL') ?: ''
        ));
        if ($raw === '') return null;
        return rtrim($raw, '/');
    }
}

if (!function_exists('tools_get_base_url_public')) {
    /**
     * Public base URL for UI links. Fallback to tools_get_base_url().
     * Priority: TOOLS_BASE_URL_PUBLIC > APP_PUBLIC_URL > tools_get_base_url()
     */
    function tools_get_base_url_public(): ?string
    {
        $raw = trim((string)(
            getenv('TOOLS_BASE_URL_PUBLIC') ?:
            getenv('APP_PUBLIC_URL') ?: ''
        ));
        if ($raw !== '') return rtrim($raw, '/');
        return tools_get_base_url();
    }
}

if (!function_exists('tools_url_join')) {
    /**
     * Join base + path for smoke/contract/QA (single slash, no //path, canonical /ERP_RMI_SOFULL).
     * Delegates to rmi_canonical_url_join when url_utils is available.
     */
    function tools_url_join(?string $base, string $path): string
    {
        $baseStr = $base !== null ? rtrim($base, '/') : '';
        $utils = dirname(__DIR__) . '/_shared/url_utils.php';
        if (is_file($utils) && !function_exists('rmi_canonical_url_join')) {
            require_once $utils;
        }
        if (function_exists('rmi_canonical_url_join')) {
            $p = '/' . ltrim($path, '/');
            return rmi_canonical_url_join($baseStr, $p);
        }
        $path = '/' . ltrim($path, '/');
        return $baseStr . $path;
    }
}

if (!function_exists('tools_http_get')) {
    function tools_http_get(string $url, int $timeoutSec = 10): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // don't follow 302/login redirects (needed for auth smoke tests)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // allow Cloudflare Origin cert / self-signed
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $raw = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $err = curl_error($ch);
            if ($hsize <= 0) $hsize = strpos($raw, "\r\n\r\n") ?: 0;
            $body = substr($raw, $hsize);
            return [
                'ok' => $err === '' && $code > 0,
                'status' => $code,
                'body' => $body,
                'err' => $err ?: '',
            ];
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeoutSec]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'err' => 'file_get_contents failed'];
        }
        $headers = $http_response_header ?? [];
        $code = 0;
        foreach ($headers as $h) {
            if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $h, $m)) {
                $code = (int)$m[1];
                break;
            }
        }
        return ['ok' => $code > 0, 'status' => $code, 'body' => $body, 'err' => ''];
    }
}

if (!function_exists('tools_http_get_json')) {
    function tools_http_get_json(string $url, int $timeoutSec = 10): array
    {
        $r = tools_http_get($url, $timeoutSec);
        if (!$r['ok']) {
            return ['ok' => false, 'status' => $r['status'], 'json' => null, 'err' => $r['err']];
        }
        $json = json_decode($r['body'], true);
        return [
            'ok' => is_array($json),
            'status' => $r['status'],
            'json' => is_array($json) ? $json : null,
            'err' => is_array($json) ? '' : 'invalid_json',
        ];
    }
}

if (!function_exists('tools_http_post_json')) {
    /** POST JSON body, return status + parsed body. Optional extra headers. */
    function tools_http_post_json(string $url, array $body, int $timeoutSec = 15, array $extraHeaders = []): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'err' => 'curl unavailable'];
        }
        $ch = curl_init($url);
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type: application/json', 'Content-Length: ' . strlen($payload)];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = (is_int($k) ? $v : $k . ': ' . $v);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);
        $bodyStr = $hsize > 0 ? substr($raw, $hsize) : $raw;
        $json = json_decode($bodyStr, true);
        return [
            'ok' => $err === '' && $code > 0,
            'status' => $code,
            'body' => $bodyStr,
            'json' => is_array($json) ? $json : null,
            'err' => $err ?: '',
        ];
    }
}
