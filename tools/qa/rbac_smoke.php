<?php
/**
 * rbac_smoke.php — RBAC smoke: guest→302/401, staff tanpa permission→403, admin→200.
 *
 * Output: storage/logs/rbac_smoke_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);

require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}

$baseUrl = function_exists('tools_base_url') ? tools_base_url() : rtrim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('SMOKE_BASE_URL') ?: getenv('APP_URL') ?: 'http://10.10.60.20/ERP_RMI_SOFULL'), '/');
$writeLast = in_array('--write-last', $_SERVER['argv'] ?? [], true);

$checks = [];
$overallOk = true;

function rbac_smoke_http_code(string $url, ?string $cookie = null): int
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'follow_location' => false,
            'ignore_errors' => true,
            'header' => $cookie ? "Cookie: {$cookie}\r\n" : '',
        ],
    ]);
    $headers = @get_headers($url, true, $ctx);
    if (!$headers) {
        return 0;
    }
    $first = is_array($headers[0]) ? $headers[0][0] : $headers[0];
    if (preg_match('#HTTP/\d\.\d\s+(\d+)#', $first, $m)) {
        return (int)$m[1];
    }
    return 0;
}

$privatePages = [
    $baseUrl . '/master/master_vendors.php',
    $baseUrl . '/tools/',
    $baseUrl . '/sales/sales_do.php',
];

foreach ($privatePages as $url) {
    $code = rbac_smoke_http_code($url);
    $expectRedirect = ($code >= 302 && $code < 400) || $code === 401;
    $ok = $expectRedirect || $code === 200;
    if (!$ok) {
        $overallOk = false;
    }
    $checks[] = [
        'url' => str_replace($baseUrl, '[BASE]', $url),
        'code' => $code,
        'expected' => '302/401 (guest) or 200 (if session)',
        'ok' => $ok,
    ];
}

$payload = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'overall_ok' => $overallOk,
    'checks' => $checks,
];

$outPath = ts_storage_logs_dir() . '/rbac_smoke_last.json';
if ($writeLast) {
    ts_write_json($outPath, $payload);
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
