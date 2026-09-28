<?php
declare(strict_types=1);

require_once __DIR__ . '/_lib/ops_helpers.php';
require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("Method Not Allowed\n");
}

$notes = [];

$readiness = ops_read_json_safe(ops_state_path('readiness_report_last.json'));
$smoke = ops_read_json_safe(ops_state_path('smoke_http_last.json'));
$contract = ops_read_json_safe(ops_state_path('contract_check_last.json'));
if (!$contract['ok']) {
    $contract = ops_read_json_safe(ops_state_path('contract_check.last.json'));
}
$allChecks = ops_ensure_all_checks_last();

$readinessScore = (int)($readiness['data']['score'] ?? $readiness['data']['summary']['score'] ?? 0);
$smokeFail = (int)($smoke['data']['fail_count'] ?? $smoke['data']['summary']['failed'] ?? 0);
$contractOk = (bool)($contract['data']['ok'] ?? false);
$allChecksOk = isset($allChecks['data']['overall_ok']) ? (bool)$allChecks['data']['overall_ok'] : null;
$backupAge = ops_get_backup_age_hours();
if ($backupAge === null) {
    $notes[] = 'backup_age_hours unavailable from backup metadata [REDACTED]';
}
if (!$readiness['ok']) $notes[] = 'readiness artifact invalid: ' . (string)$readiness['error'];
if (!$smoke['ok']) $notes[] = 'smoke artifact invalid: ' . (string)$smoke['error'];
if (!$contract['ok']) $notes[] = 'contract artifact invalid: ' . (string)$contract['error'];

$today = new DateTimeImmutable('now');
$dateKey = $today->format('Ymd');
$snapshot = [
    'state_version' => 1,
    'date' => $today->format('Y-m-d'),
    'ts' => $today->format(DateTimeInterface::ATOM),
    'env' => (string)(getenv('APP_ENV') ?: 'production'),
    'readiness_score' => $readinessScore,
    'smoke_fail' => $smokeFail,
    'contract_ok' => $contractOk,
    'backup_age_hours' => $backupAge,
    'all_checks_overall_ok' => $allChecksOk,
    'notes_masked' => array_values(array_map('ops_mask', $notes)),
];
$contract = ops_contract_check_types($snapshot, [
    'state_version' => 'int',
    'date' => 'string',
    'ts' => 'string',
    'env' => 'string',
    'readiness_score' => 'int',
    'smoke_fail' => 'int',
    'contract_ok' => 'bool',
    'backup_age_hours' => 'nullable_float',
    'all_checks_overall_ok' => 'nullable_bool',
    'notes_masked' => 'array',
]);
if (!$contract['ok']) {
    fwrite(STDERR, "snapshot contract mismatch\n");
    exit(2);
}

$snapshotPath = ops_state_path('ops_daily_snapshot_' . $dateKey . '.json');
ops_write_json($snapshotPath, $snapshot);

$trend7 = ops_trend_payload(7);
$trend30 = ops_trend_payload(30);
ops_write_json(ops_state_path('ops_trend_7d_last.json'), $trend7);
ops_write_json(ops_state_path('ops_trend_30d_last.json'), $trend30);

$summaryState = [
    'state_version' => 1,
    'ts' => $today->format(DateTimeInterface::ATOM),
    'status' => ($contractOk === false || $smokeFail > 0 || ($backupAge !== null && $backupAge > 72)) ? 'CRITICAL' : (($readinessScore < 100 || ($backupAge !== null && $backupAge > 24)) ? 'ATTENTION' : 'HEALTHY'),
    'latest_snapshot' => '[APP_ROOT]/storage/logs/ops_daily_snapshot_' . $dateKey . '.json',
    'readiness_score' => $readinessScore,
    'smoke_fail' => $smokeFail,
    'backup_age_hours' => $backupAge,
    'contract_ok' => $contractOk,
    'all_checks_overall_ok' => $allChecksOk,
    'trend_7d' => '[APP_ROOT]/storage/logs/ops_trend_7d_last.json',
    'trend_30d' => '[APP_ROOT]/storage/logs/ops_trend_30d_last.json',
];
ops_write_json(ops_state_path('executive_ops_summary_last.json'), $summaryState);

$history = [
    'event' => 'ops_snapshot_generated',
    'status' => 'ok',
    'at' => $today->format(DateTimeInterface::ATOM),
    'date' => $snapshot['date'],
    'readiness_score' => $readinessScore,
    'smoke_fail' => $smokeFail,
    'contract_ok' => $contractOk,
];
ts_append_run_history('ops_snapshot_generated', 'ok', $history);

try {
    $pdo = rmi_db_pdo();
    erp_audit_ensure($pdo);
    erp_audit($pdo, 'OPS', 'SNAPSHOT:' . $snapshot['date'], 'OPS_SNAPSHOT_GENERATED', $history);
} catch (Throwable $e) {
}

echo json_encode([
    'ok' => true,
    'snapshot' => '[APP_ROOT]/storage/logs/ops_daily_snapshot_' . $dateKey . '.json',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
