<?php
declare(strict_types=1);

/**
 * Diagnose public vs internal HTTP (403 blanket / WAF / vhost).
 *
 * Usage (NAS):
 *   php tools/qa/smoke_public_probe.php --write-last
 *
 * Writes: storage/logs/smoke_public_probe_last.json
 * Compares GET /master/login.php and /api/v1/health.php on internal vs public base URLs.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/_shared/tools_bootstrap.php';
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);

$baseInt = trim((string)(getenv('SMOKE_BASE_URL') ?: getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('APP_URL') ?: ''));
if ($baseInt === '' && function_exists('rmi_tools_base_url')) {
    $baseInt = rmi_tools_base_url('internal');
}
$basePub = trim((string)(getenv('TOOLS_BASE_URL_PUBLIC') ?: getenv('APP_PUBLIC_URL') ?: ''));
if ($basePub === '' && function_exists('rmi_tools_base_url')) {
    $basePub = rmi_tools_base_url('public');
}
if ($baseInt !== '' && function_exists('normalize_base_url')) {
    try {
        $baseInt = normalize_base_url($baseInt);
    } catch (Throwable $e) {
        $baseInt = rtrim($baseInt, '/');
    }
}
if ($basePub !== '' && function_exists('normalize_base_url')) {
    try {
        $basePub = normalize_base_url($basePub);
    } catch (Throwable $e) {
        $basePub = rtrim($basePub, '/');
    }
}

$probe = static function (string $base, string $path) use ($root): array {
    $url = function_exists('url_join') ? url_join($base, $path) : rtrim($base, '/') . ($path[0] === '/' ? $path : '/' . $path);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/122.0.0.0 ERP-SmokeProbe/1.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/json;q=0.9,*/*;q=0.8',
        'Accept-Language: id-ID,id;q=0.9,en;q=0.8',
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $header = is_string($raw) ? substr($raw, 0, $hsize) : '';
    $body = is_string($raw) ? substr($raw, $hsize) : '';
    $lines = preg_split("/\r\n|\n|\r/", $header) ?: [];
    $interesting = [];
    foreach ($lines as $ln) {
        $l = strtolower($ln);
        if (str_starts_with($l, 'server:')
            || str_starts_with($l, 'via:')
            || str_starts_with($l, 'cf-')
            || str_starts_with($l, 'location:')
            || str_starts_with($l, 'content-type:')) {
            $interesting[] = trim($ln);
        }
    }
    $bodySnippet = strtolower(substr($body, 0, 4000));
    $suggestsWaf = str_contains($bodySnippet, 'cloudflare')
        || str_contains($bodySnippet, 'cf-ray')
        || str_contains($bodySnippet, 'attention required')
        || str_contains($bodySnippet, 'forbidden');

    return [
        'url' => $url,
        'http_code' => $code,
        'headers_interesting' => $interesting,
        'body_len' => strlen($body),
        'suggests_edge_block' => $suggestsWaf && !in_array($code, [200, 302], true),
    ];
};

$paths = ['/master/login.php', '/api/v1/health.php', '/'];
$out = [
    'run_at' => date(DateTimeInterface::ATOM),
    'base_internal' => $baseInt,
    'base_public' => $basePub,
    'comparison' => [],
];
foreach ($paths as $p) {
    $out['comparison'][] = [
        'path' => $p,
        'internal' => $baseInt !== '' ? $probe($baseInt, $p) : null,
        'public' => $basePub !== '' ? $probe($basePub, $p) : null,
    ];
}
$out['interpretation'] = 'If public http_code is 403 while internal is 200/302, origin is likely blocked at reverse proxy/WAF (not PHP RBAC). Whitelist NAS egress IP on Cloudflare or allow ERP path.';

$logsDir = $root . '/storage/logs';
if ($writeLast) {
    @mkdir($logsDir, 0775, true);
    @file_put_contents($logsDir . '/smoke_public_probe_last.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
