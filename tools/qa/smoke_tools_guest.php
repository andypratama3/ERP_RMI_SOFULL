<?php
/**
 * Smoke Tools Guest — GET tools pages via TOOLS_BASE_URL (CLI).
 * Accept 200/302/401/403. Body MUST NOT contain Warning/Notice/Fatal/Parse/Undefined.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/_lib/tools_paths.php';
tools_assert_app_root_locked_cli();

require_once $root . '/tools/_lib/tools_bootstrap.php';
require_once $root . '/tools/_lib/tools_http.php';
if (!function_exists('ts_storage_logs_dir')) {
    function ts_storage_logs_dir(): string {
        $r = defined('APP_ROOT') ? (string)APP_ROOT : (realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
        $d = $r . '/storage/logs';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }
}

$baseUrl = tools_get_base_url();
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (is_string($arg) && str_starts_with($arg, '--base-url=')) {
        $baseUrl = rtrim(trim(substr($arg, 11)), '/');
        break;
    }
}
$writeLast = !in_array('--no-write-last', $_SERVER['argv'] ?? [], true);

if ($baseUrl === null || $baseUrl === '') {
    $payload = ['ok' => false, 'error' => 'TOOLS_BASE_URL_MISSING', 'checks' => []];
    if (function_exists('ts_storage_logs_dir')) {
        $p = ts_storage_logs_dir() . '/smoke_tools_guest_last.json';
        @file_put_contents($p, json_encode($payload, JSON_PRETTY_PRINT) . "\n");
    }
    echo json_encode($payload) . "\n";
    exit(1);
}

$paths = [
    '/tools/index.php',
    '/tools/health.php',
    '/tools/preflight_check.php',
    '/tools/backup_manager.php',
    '/tools/backup_schedule.php',
    '/tools/smoke_schedule.php',
];
if (is_file($root . '/tools/uat_smoke.php')) {
    $paths[] = '/tools/uat_smoke.php';
}

$checks = [];
$passed = 0;
$failed = 0;

$forbidden = ['Warning:', 'Notice:', 'Fatal error', 'Parse error', 'Undefined', 'Stack trace'];
$acceptStatus = [200, 302, 401, 403];

foreach ($paths as $path) {
    $url = $baseUrl . $path;
    $r = tools_http_get($url, 15);
    $okStatus = in_array($r['status'], $acceptStatus, true);
    $body = $r['body'] ?? '';
    $hasCrash = false;
    foreach ($forbidden as $f) {
        if (stripos($body, $f) !== false) {
            $hasCrash = true;
            break;
        }
    }
    $ok = $okStatus && !$hasCrash;
    $checks[] = ['path' => $path, 'ok' => $ok, 'status' => $r['status'], 'crash' => $hasCrash];
    if ($ok) $passed++; else $failed++;
}

$payload = [
    'state_version' => 1,
    'run_at' => date('c'),
    'base_url' => $baseUrl,
    'ok' => $failed === 0,
    'total' => count($checks),
    'pass' => $passed,
    'fail' => $failed,
    'checks' => $checks,
];

if ($writeLast && function_exists('ts_storage_logs_dir')) {
    $p = ts_storage_logs_dir() . '/smoke_tools_guest_last.json';
    @file_put_contents($p, json_encode($payload, JSON_PRETTY_PRINT) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
exit($failed > 0 ? 1 : 0);
