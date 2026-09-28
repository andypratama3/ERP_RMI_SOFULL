<?php
/**
 * Tools Dashboard Smoke — CLI. GET tools pages, validate no crash.
 * Output: JSON {ok, checks, errors_masked}. Writes tools_dashboard_smoke.last.json.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$requestId = 'tds-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

$jsonOut = static function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL;
};

try {
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    require_once $root . '/tools/tools_state_lib.php';
    require_once $root . '/tools/_lib/tools_http.php';

    $baseUrl = tools_get_base_url() ?? '';
    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if (is_string($arg) && str_starts_with($arg, '--base-url=')) {
            $baseUrl = rtrim(trim(substr($arg, 11)), '/');
            break;
        }
    }

    if ($baseUrl === '') {
        $payload = [
            'ok' => false,
            'error' => 'TOOLS_BASE_URL_MISSING',
            'request_id' => $requestId,
            'checks' => [],
            'errors_masked' => ['Set TOOLS_BASE_URL or --base-url'],
        ];
        $logsDir = ts_storage_logs_dir();
        @file_put_contents($logsDir . '/tools_dashboard_smoke.last.json', json_encode($payload, JSON_PRETTY_PRINT) . "\n");
        $jsonOut($payload);
        exit(1);
    }

    $paths = ['/tools/index.php', '/tools/health.php', '/tools/preflight_check.php'];
    $checks = [];
    $errors = [];
    $forbidden = ['Warning:', 'Notice:', 'Fatal error', 'Parse error', 'Undefined', 'Stack trace'];
    $acceptStatus = [200, 302, 401, 403];

    foreach ($paths as $path) {
        $url = $baseUrl . $path;
        $r = tools_http_get($url, 15);
        $status = (int)($r['status'] ?? 0);
        $body = (string)($r['body'] ?? '');
        $okStatus = in_array($status, $acceptStatus, true);
        $hasCrash = false;
        foreach ($forbidden as $f) {
            if (stripos($body, $f) !== false) {
                $hasCrash = true;
                break;
            }
        }
        $ok = $okStatus && !$hasCrash;
        $checks[] = ['path' => $path, 'ok' => $ok, 'status' => $status, 'crash' => $hasCrash];
        if (!$ok) {
            $errors[] = $path . ': status=' . $status . ($hasCrash ? ', crash_signature' : '');
        }
    }

    $failed = count(array_filter($checks, static fn(array $c): bool => !$c['ok']));
    $payload = [
        'ok' => $failed === 0,
        'request_id' => $requestId,
        'base_url' => $baseUrl,
        'checks' => $checks,
        'errors_masked' => array_map(static fn(string $e): string => function_exists('tools_mask_sensitive') ? tools_mask_sensitive($e) : $e, $errors),
    ];

    $logsDir = ts_storage_logs_dir();
    @file_put_contents($logsDir . '/tools_dashboard_smoke.last.json', json_encode($payload, JSON_PRETTY_PRINT) . "\n");
    $jsonOut($payload);
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (function_exists('tools_mask_sensitive')) {
        $msg = tools_mask_sensitive($msg);
    }
    $payload = [
        'ok' => false,
        'error' => 'runtime_exception',
        'request_id' => $requestId,
        'message' => $msg,
        'checks' => [],
        'errors_masked' => [$msg],
    ];
    $logsDir = $root . '/storage/logs';
    if (is_dir($logsDir)) {
        @file_put_contents($logsDir . '/tools_dashboard_smoke.last.json', json_encode($payload, JSON_PRETTY_PRINT) . "\n");
    }
    $jsonOut($payload);
    exit(1);
}
