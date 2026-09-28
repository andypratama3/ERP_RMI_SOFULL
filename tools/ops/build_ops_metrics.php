<?php
/**
 * Build Ops Metrics — CLI. Aggregate readiness, contract, smoke, backup.
 * Output: storage/logs/ops_metrics_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("CLI only\n");
}

require_once __DIR__ . '/../_lib/tools_paths.php';
tools_assert_app_root_locked_cli();

require_once __DIR__ . '/_lib/ops_helpers.php';

$logsDir = ops_logs_dir();
$writeLast = !in_array('--no-write-last', $_SERVER['argv'] ?? [], true);

$readiness = ops_read_json_safe($logsDir . '/readiness_report_last.json');
$contract = ops_read_json_safe($logsDir . '/contract_check_last.json');
if (!$contract['ok']) {
    $contract = ops_read_json_safe($logsDir . '/contract_check.last.json');
}
$smoke = ops_read_json_safe($logsDir . '/smoke_http_last.json');

$readinessScore = (int)($readiness['data']['score'] ?? $readiness['data']['summary']['score'] ?? 0);
$contractOk = (bool)($contract['data']['ok'] ?? false);
$smokeFail = (int)($smoke['data']['fail_count'] ?? $smoke['data']['summary']['failed'] ?? 0);

$backupAge = function_exists('ops_get_backup_age_hours') ? ops_get_backup_age_hours() : null;
if ($backupAge === null) {
    $backupManifest = glob($logsDir . '/backup_manifest_*.json') ?: [];
    if (!empty($backupManifest)) {
        rsort($backupManifest);
        $latest = ops_read_json_safe($backupManifest[0]);
        if ($latest['ok'] && isset($latest['data']['created_at'])) {
            $backupAge = (time() - strtotime((string)$latest['data']['created_at'])) / 3600.0;
        }
    }
}

$status = 'UNKNOWN';
if ($readiness['ok'] || $smoke['ok'] || $contract['ok']) {
    $status = ($contractOk === false || $smokeFail > 0 || ($backupAge !== null && $backupAge > 72))
        ? 'CRITICAL' : (($readinessScore < 100 || ($backupAge !== null && $backupAge > 24)) ? 'ATTENTION' : 'HEALTHY');
}

$metrics = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'readiness_score' => $readinessScore,
    'contract_ok' => $contractOk,
    'smoke_fail' => $smokeFail,
    'backup_age_hours' => $backupAge,
    'status' => $status,
    'artifacts_masked' => [
        'readiness' => '[APP_ROOT]/storage/logs/readiness_report_last.json',
        'contract' => '[APP_ROOT]/storage/logs/contract_check_last.json',
        'smoke' => '[APP_ROOT]/storage/logs/smoke_http_last.json',
    ],
];

if ($writeLast) {
    $path = $logsDir . '/ops_metrics_last.json';
    if (!is_dir($logsDir)) @mkdir($logsDir, 0775, true);
    ops_write_json($path, $metrics);
}

echo json_encode($metrics, JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
