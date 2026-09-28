<?php
/**
 * audit_live.php — LIVE AUDIT (real data + real-time).
 * CLI: php tools/qa/audit_live.php --env=production --write-last --strict
 * Runs for both LAN_BASE_URL and PUBLIC_BASE_URL.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$real = realpath($root);
if ($real !== false && strpos(str_replace('\\', '/', $real), '/Volumes/') !== false) {
    fwrite(STDERR, "CRITICAL FAIL: /Volumes/ path detected. Run from NAS only.\n");
    exit(2);
}

require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/tools_ui_helpers.php';
require_once $root . '/tools/qa/_shared/audit_live_lib.php';
require_once $root . '/tools/qa/_shared/audit_schema_detect.php';
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();
require_once $root . '/_shared/db.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);
$strict = in_array('--strict', $args, true);
$modeCron = in_array('--mode=cron', $args, true);
$env = 'production';
foreach ($args as $a) {
    if (is_string($a) && str_starts_with($a, '--env=')) {
        $env = trim(substr($a, 6)) ?: 'production';
        break;
    }
}

$LAN_BASE_URL = 'http://10.10.60.20/ERP_RMI_SOFULL';
$PUBLIC_BASE_URL = 'https://erp.rizqullahmediska.com/ERP_RMI_SOFULL';

$runId = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
$requestId = 'audit_' . $runId;
$generatedAt = date('c');

$checks = [];
$criticalFailCount = 0;
$warningCount = 0;
$assumptions = [];

function addCheck(array &$checks, string $name, bool $ok, string $status, int $durationMs, string $detail, string $artifact = ''): void {
    $checks[] = [
        'name' => $name,
        'status' => $status,
        'ok' => $ok,
        'duration_ms' => $durationMs,
        'detail_masked' => $detail,
        'artifact' => $artifact,
    ];
}

$t0 = microtime(true);

// 1) Preflight
$preflight = audit_preflight_env($root);
if (!$preflight['ok']) {
    addCheck($checks, 'preflight', false, 'fail', (int)((microtime(true) - $t0) * 1000), $preflight['error'] ?? 'unknown', '');
    $criticalFailCount++;
}
$t0 = microtime(true);

// 2) TOOLS_BASE_URL
$baseUrl = audit_tools_base_url($root);
if ($baseUrl === '') {
    @putenv('TOOLS_BASE_URL_INTERNAL=' . $LAN_BASE_URL);
    $baseUrl = $LAN_BASE_URL;
}
if ($baseUrl === '' || !preg_match('#^https?://#', $baseUrl)) {
    addCheck($checks, 'tools_base_url', false, 'fail', 0, 'TOOLS_BASE_URL missing or invalid', '');
    $criticalFailCount++;
} else {
    addCheck($checks, 'tools_base_url', true, 'pass', (int)((microtime(true) - $t0) * 1000), '[OK]', '');
}

// 3) Unicode guard
$t0 = microtime(true);
$ug = audit_unicode_guard($root);
addCheck($checks, 'unicode_guard', $ug['ok'], $ug['ok'] ? 'pass' : 'fail', (int)((microtime(true) - $t0) * 1000), $ug['ok'] ? 'ok' : ($ug['error'] ?? 'fail'), $ug['artifact'] ?? '');
if (!$ug['ok']) $criticalFailCount++;

// 4) Volumes guard
$t0 = microtime(true);
$vg = audit_volumes_guard($root);
addCheck($checks, 'volumes_guard', $vg['ok'], $vg['ok'] ? 'pass' : 'fail', (int)((microtime(true) - $t0) * 1000), $vg['ok'] ? 'ok' : ('volumes_detected=' . ($vg['volumes_detected'] ?? 1)), $vg['artifact'] ?? '');
if (!$vg['ok'] && !($vg['skipped'] ?? false)) $criticalFailCount++;

// 5) Schema detect
$t0 = microtime(true);
$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
$seedFiles = [
    'stock/wqs_pr.php', 'purchases/purchases_po.php', 'stock/wqs_incoming.php',
    'purchases/purchases_invoice_ap.php', 'purchases/purchases_payment_ap.php',
    'sales/sales_do.php', 'stock/wqs_stock_adjustment.php', 'stock/wqs_stock_opname.php',
];
$schemaDetect = audit_schema_detect($root, $pdo, $seedFiles);
if (!$schemaDetect['ok']) {
    $assumptions = array_merge($assumptions, $schemaDetect['assumptions'] ?? []);
}
addCheck($checks, 'schema_detect', $schemaDetect['ok'], $schemaDetect['ok'] ? 'pass' : 'fail', (int)((microtime(true) - $t0) * 1000), $schemaDetect['ok'] ? 'ok' : implode('; ', $schemaDetect['errors'] ?? []), '');
if (!$schemaDetect['ok']) $criticalFailCount++;

// 6) Finance schema guard
$t0 = microtime(true);
$fsg = audit_finance_schema_guard($pdo);
addCheck($checks, 'finance_schema_guard', $fsg['ok'], $fsg['ok'] ? 'pass' : 'fail', (int)((microtime(true) - $t0) * 1000), $fsg['ok'] ? 'ok' : ($fsg['error'] ?? 'fail'), '');
if (!$fsg['ok']) $criticalFailCount++;

// 7) Sales tracking
$t0 = microtime(true);
$stc = audit_sales_tracking_checks($root);
addCheck($checks, 'sales_tracking_checks', $stc['ok'], $stc['ok'] ? 'pass' : 'fail', (int)((microtime(true) - $t0) * 1000), $stc['ok'] ? 'ok' : ($stc['error'] ?? 'fail'), $stc['artifact'] ?? '');
if (!$stc['ok']) $criticalFailCount++;

// 8) Real data integrity
$policyPath = $root . '/tools/qa/audit_live_policy.json';
$policy = is_file($policyPath) ? json_decode((string)@file_get_contents($policyPath), true) : [];
$policy = is_array($policy) ? $policy : [];
$t0 = microtime(true);
$rdi = audit_real_data_integrity($pdo, $schemaDetect, $policy);
addCheck($checks, 'real_data_integrity', $rdi['ok'], $rdi['ok'] ? 'pass' : 'fail', (int)((microtime(true) - $t0) * 1000),
    $rdi['ok'] ? 'ok' : ("office=" . $rdi['office_missing'] . " depo=" . $rdi['depo_missing'] . " gr_media=" . $rdi['posted_gr_media_missing']), '');
if (!$rdi['ok']) $criticalFailCount++;

// 9) Backup freshness
$t0 = microtime(true);
$bf = audit_backup_freshness($root, (int)($policy['backup_max_age_hours'] ?? 24));
addCheck($checks, 'backup_freshness', $bf['ok'], $bf['ok'] ? 'pass' : 'warn', (int)((microtime(true) - $t0) * 1000), $bf['ok'] ? 'ok' : ('age_hours=' . ($bf['age_hours'] ?? '?')), '');
if (!$bf['ok']) $warningCount++;

// 10) Smoke HTTP + Contract for both URLs
foreach (['lan' => $LAN_BASE_URL, 'public' => $PUBLIC_BASE_URL] as $label => $url) {
    $t0 = microtime(true);
    $smoke = audit_rbac_http_smoke($root, $url);
    addCheck($checks, "smoke_http_{$label}", $smoke['ok'], $smoke['ok'] ? 'pass' : 'fail', (int)((microtime(true) - $t0) * 1000), $smoke['ok'] ? 'ok' : ('fail_count=' . ($smoke['fail_count'] ?? 1)), $smoke['artifact'] ?? '');
    if (!$smoke['ok']) $criticalFailCount++;

    $t0 = microtime(true);
    $contract = audit_contract_check($root, $url);
    addCheck($checks, "contract_check_{$label}", $contract['ok'], $contract['ok'] ? 'pass' : 'fail', (int)((microtime(true) - $t0) * 1000), $contract['ok'] ? 'ok' : ($contract['error'] ?? 'fail'), $contract['artifact'] ?? '');
    if (!$contract['ok']) $criticalFailCount++;
}

$overallOk = $criticalFailCount === 0;

$payload = [
    'state_version' => 'audit_live_v1',
    'run_id' => $runId,
    'generated_at' => $generatedAt,
    'env' => $env,
    'overall_ok' => $overallOk,
    'critical_fail_count' => $criticalFailCount,
    'warning_count' => $warningCount,
    'base_urls' => ['lan' => $LAN_BASE_URL, 'public' => $PUBLIC_BASE_URL],
    'checks' => $checks,
    'assumptions' => $assumptions,
    'request_id' => $requestId,
];

if ($writeLast) {
    ts_write_json(ts_storage_logs_dir() . '/audit_live_last.json', $payload);
    $historyLine = json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(ts_storage_logs_dir() . '/audit_live_history.jsonl', $historyLine, FILE_APPEND | LOCK_EX);
    if (is_file($root . '/tools/ops/build_audit_exec_summary.php')) {
        require_once $root . '/tools/ops/build_audit_exec_summary.php';
        if (function_exists('build_audit_exec_summary')) {
            build_audit_exec_summary($payload);
        }
    }
}

if (function_exists('ts_append_run_history')) {
    ts_append_run_history('audit_live', $overallOk ? 'OK' : 'FAIL', [
        'run_id' => $runId,
        'critical_fail_count' => $criticalFailCount,
        'source' => 'tools/qa/audit_live.php',
    ]);
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
