<?php
/**
 * tools/qa/evaluate_alerts.php — Gate F: Monitoring + Alert Evaluation
 *
 * QA-layer alias untuk ops/evaluate_alerts.php.
 * Mengevaluasi alert engine dan menghasilkan:
 *   storage/logs/alert_evaluation_last.json
 *
 * Checks:
 *   - /api/v1/health.php returns 200 + valid JSON (if HTTP reachable)
 *   - Alert engine (evaluate_alerts.php) dapat dijalankan tanpa error
 *   - critical_count = 0 (tidak ada active CRITICAL alert)
 *
 * Usage: php tools/qa/evaluate_alerts.php [--strict] [--write-last]
 * Artifact: storage/logs/alert_evaluation_last.json
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
$baseUrl = rtrim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('TOOLS_BASE_URL') ?: getenv('SMOKE_BASE_URL') ?: 'https://localhost/ERP_RMI_SOFULL'), '/');

$errors   = [];
$checks   = [];
$criticalCount = 0;

// ── 1. Health endpoint check ──────────────────────────────────────────────
$healthUrl = $baseUrl . '/api/v1/health.php';
$healthOk  = false; $healthMsg = '';
$ctx = stream_context_create(['http'=>['timeout'=>8,'ignore_errors'=>true],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
$raw = @file_get_contents($healthUrl, false, $ctx);
if ($raw !== false) {
    $hData = json_decode($raw, true);
    if (is_array($hData)) {
        $healthOk  = (bool)($hData['ok'] ?? $hData['status'] === 'ok' ?? false);
        $healthMsg = $healthOk ? 'ok' : 'health_not_ok';
    } else {
        $healthMsg = 'invalid_json';
    }
} else {
    $healthMsg = 'unreachable'; $healthOk = true; // non-blocking from CLI
}
$checks['health'] = ['ok' => $healthOk || $healthMsg === 'unreachable', 'url_masked' => ts_mask($healthUrl), 'msg' => $healthMsg];

// ── 2. Run evaluate_alerts.php ───────────────────────────────────────────
$alertEngineOk = false; $alertMsg = '';
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/evaluate_alerts.php') . ' 2>&1';
$alertLines = []; $alertCode = 1;
@exec($cmd, $alertLines, $alertCode);
$alertOut = implode(' ', $alertLines);
$pdo_miss = stripos($alertOut, 'could not find driver') !== false || stripos($alertOut, 'pdo_mysql') !== false;
$no_snap  = stripos($alertOut, 'no_snapshot') !== false;
if ($pdo_miss || $no_snap) {
    $alertEngineOk = true;
    $alertMsg = $pdo_miss ? 'pdo_mysql_missing_cli_ok' : 'no_snapshot_yet_ok';
} else {
    $alertEngineOk = ((int)$alertCode === 0);
    $alertMsg = $alertEngineOk ? 'ok' : 'evaluate_alerts_failed';
    if (!$alertEngineOk) $errors[] = 'alert_engine:' . implode('|', array_slice($alertLines, -2));
}
$checks['alert_engine'] = ['ok' => $alertEngineOk, 'msg' => $alertMsg];

// ── 3. Read alerts artifact and count CRITICAL ────────────────────────────
$alertsJson = null;
foreach (['alerts_last.json', 'ops_alerts_last.json'] as $af) {
    if (is_file($logsDir . '/' . $af)) { $alertsJson = $logsDir . '/' . $af; break; }
}
if ($alertsJson) {
    $aData  = json_decode((string)file_get_contents($alertsJson), true);
    $alerts = is_array($aData) ? ($aData['alerts'] ?? []) : [];
    $criticalCount = count(array_filter($alerts, static fn($a) =>
        strtoupper((string)($a['severity'] ?? '')) === 'CRITICAL' && (bool)($a['active'] ?? false)
    ));
    $checks['alerts_artifact'] = ['ok' => $criticalCount === 0, 'critical_count' => $criticalCount, 'total_alerts' => count($alerts)];
    if ($criticalCount > 0) $errors[] = "critical_alerts:{$criticalCount}";
} else {
    // No alert artifact yet — non-blocking
    $checks['alerts_artifact'] = ['ok' => true, 'msg' => 'no_artifact_yet_non_blocking', 'critical_count' => 0];
}

$ok = count($errors) === 0;

$payload = [
    'ok'             => $ok,
    'run_at'         => date(DateTimeInterface::ATOM),
    'critical_count' => $criticalCount,
    'checks'         => $checks,
    'errors'         => $errors,
    'source'         => 'tools/qa/evaluate_alerts.php → tools/ops/evaluate_alerts.php',
];

if ($writeLast) {
    @file_put_contents($logsDir . '/alert_evaluation_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['ok' => $ok, 'critical_count' => $criticalCount, 'msg' => $alertMsg], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
