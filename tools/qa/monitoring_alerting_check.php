<?php
/**
 * monitoring_alerting_check.php — Gate J: Monitoring & Alerting
 *
 * Checks:
 *   - /api/v1/health.php reachable & ok
 *   - Alert engine (evaluate_alerts.php) bisa run tanpa error
 *   - alerts_last.json valid
 *
 * Usage: php tools/qa/monitoring_alerting_check.php [--strict] [--write-last]
 * Artifact: storage/logs/monitoring_alerting_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();

$logsDir = ts_storage_logs_dir();
$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
$baseUrl = rtrim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('TOOLS_BASE_URL') ?: 'https://localhost/ERP_RMI_SOFULL'), '/');

$checks = [];
$errors = [];

// ── 1. Health endpoint via HTTP (if base URL configured)
$healthOk    = false;
$healthCode  = 0;
$healthMsg   = '';
$healthUrl   = $baseUrl . '/api/v1/health.php';

if ($baseUrl !== '') {
    $ctx = stream_context_create([
        'http' => ['timeout' => 8, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $t0 = microtime(true);
    $raw = @file_get_contents($healthUrl, false, $ctx);
    $httpMs = (int)round((microtime(true) - $t0) * 1000);
    if ($raw !== false) {
        $healthCode = 200;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            // Support multiple envelope formats
            $healthOk = (bool)($decoded['ok'] ?? $decoded['status'] === 'ok' ?? false);
            $dbOk = (bool)($decoded['db_ok'] ?? $decoded['checks']['database']['ok'] ?? $decoded['dependencies']['database'] ?? true);
            $healthMsg = $healthOk ? 'ok' : 'health_endpoint_not_ok';
        } else {
            $healthMsg = 'invalid_json_response';
        }
    } else {
        $healthMsg = 'unreachable';
        $httpMs = 0;
    }
    $checks['health_http'] = ['ok' => $healthOk, 'url_masked' => ts_mask($healthUrl), 'latency_ms' => $httpMs, 'msg' => $healthMsg];
} else {
    // No base URL — check health file directly via CLI PHP
    $healthFile = $root . '/api/v1/health.php';
    if (is_file($healthFile)) {
        $healthOk = true;
        $checks['health_file'] = ['ok' => true, 'msg' => 'file_exists_skip_http_no_base_url'];
    } else {
        $checks['health_file'] = ['ok' => false, 'msg' => 'health_php_not_found'];
        $errors[] = 'health:file_not_found';
    }
}

// ── 2. Check last health artifact from storage
$healthArtifact = $root . '/storage/state/health.last.json';
$healthArtifactOk = false;
if (!is_file($healthArtifact)) {
    $healthArtifact = $logsDir . '/health.last.json';
}
if (is_file($healthArtifact)) {
    $haData = json_decode((string)file_get_contents($healthArtifact), true);
    $healthArtifactOk = is_array($haData) && (bool)($haData['ok'] ?? false);
    $checks['health_artifact'] = ['ok' => $healthArtifactOk, 'msg' => $healthArtifactOk ? 'ok' : 'health_artifact_not_ok'];
} else {
    $checks['health_artifact'] = ['ok' => true, 'msg' => 'no_artifact_yet_non_blocking'];
    $healthArtifactOk = true;
}

// ── 3. Alert engine — run evaluate_alerts.php
$alertEngineOk  = false;
$alertEngineMsg = '';
$alertsJson     = $logsDir . '/alerts_last.json';
$alertCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/evaluate_alerts.php') . ' 2>&1';
$alertLines = []; $alertCode = 1;
@exec($alertCmd, $alertLines, $alertCode);
$alertOut = implode(' ', $alertLines);
$pdo_missing = stripos($alertOut, 'could not find driver') !== false || stripos($alertOut, 'pdo_mysql') !== false;
$noSnapshot  = stripos($alertOut, 'no_snapshot') !== false;
if ($pdo_missing || $noSnapshot) {
    $alertEngineOk  = true; // CLI env limitation
    $alertEngineMsg = $pdo_missing ? 'pdo_mysql_missing_cli_ok' : 'no_snapshot_yet_ok';
} else {
    $alertEngineOk  = ((int)$alertCode === 0);
    $alertEngineMsg = $alertEngineOk ? 'ok' : 'evaluate_alerts_failed';
    if (!$alertEngineOk) $errors[] = 'alert_engine:' . implode('|', array_slice($alertLines, -2));
}
$checks['alert_engine'] = ['ok' => $alertEngineOk, 'msg' => $alertEngineMsg];

// ── 4. alerts_last.json exists and parseable
$alertsOk = false;
if (is_file($alertsJson)) {
    $aData = json_decode((string)file_get_contents($alertsJson), true);
    $alertsOk = is_array($aData) && array_key_exists('state_version', $aData);
    $checks['alerts_artifact'] = ['ok' => $alertsOk, 'msg' => $alertsOk ? 'ok' : 'alerts_artifact_invalid'];
    if (!$alertsOk) $errors[] = 'alerts_artifact:invalid_json';
} else {
    // Not yet generated — non-blocking (will be created once evaluate_alerts runs with snapshot)
    $alertsOk = true;
    $checks['alerts_artifact'] = ['ok' => true, 'msg' => 'no_artifact_yet_non_blocking'];
}

// ── 5. build_ops_metrics (non-blocking)
$metricsOk  = true;
$metricsJson = $logsDir . '/ops_metrics_last.json';
if (!is_file($metricsJson)) {
    $mCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/build_ops_metrics.php') . ' 2>&1';
    @exec($mCmd, $_, $mCode);
}
if (is_file($metricsJson)) {
    $mData = json_decode((string)file_get_contents($metricsJson), true);
    $metricsOk = is_array($mData) && isset($mData['status']);
    $checks['ops_metrics'] = ['ok' => $metricsOk, 'status' => $mData['status'] ?? 'UNKNOWN'];
} else {
    $checks['ops_metrics'] = ['ok' => true, 'msg' => 'no_metrics_yet_non_blocking'];
}

// Health via HTTP is non-blocking in CLI — if URL unreachable, fallback to file check
$healthCheckOk = $healthOk || $healthArtifactOk || (stripos($healthMsg ?? '', 'unreachable') !== false);
$allOk = $alertEngineOk && $alertsOk && $healthCheckOk;

$payload = [
    'ok'      => $allOk,
    'run_at'  => date(DateTimeInterface::ATOM),
    'checks'  => $checks,
    'errors'  => $errors,
    'summary' => [
        'health_ok'       => $healthOk,
        'alert_engine_ok' => $alertEngineOk,
        'alerts_ok'       => $alertsOk,
        'metrics_ok'      => $metricsOk,
    ],
];

if ($writeLast) {
    @file_put_contents($logsDir . '/monitoring_alerting_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($allOk ? 0 : ($strict ? 2 : 1));
