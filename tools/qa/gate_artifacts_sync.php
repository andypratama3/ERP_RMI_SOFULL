<?php
/**
 * gate_artifacts_sync.php — Ensure all canonical checklist artifact filenames are present.
 *
 * Writes alias/derived artifacts for the FINAL_GATE_CHECKLIST:
 * - monitoring_check_last.json  (from ops_metrics_last.json / build_ops_metrics)
 * - runbook_onboarding_check_last.json (verify docs exist)
 * - governance_check_last.json (from module_governance_lint_last.json)
 * - menu_dashboard_sync_last.json (from menu_rbac_sync_last.json)
 * - audit_trail_check_last.json (from audit_e2e_probe_last.json)
 * - ops_monitoring_last.json (from ops_metrics_last.json)
 *
 * Usage: php tools/qa/gate_artifacts_sync.php [--write-last] [--strict]
 * Artifact: storage/logs/gate_artifacts_sync_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);
$strict = in_array('--strict', $args, true);
$logsDir = ts_storage_logs_dir();

$errors = [];
$synced = [];
$warnings = [];

/** Copy JSON artifact from source to alias name. Missing source = warning only. */
function sync_artifact(string $src, string $dst, string $alias, array &$synced, array &$errors, array &$warnings): void
{
    if (is_file($src)) {
        $data = (string)@file_get_contents($src);
        if (@file_put_contents($dst, $data) !== false) {
            $synced[] = $alias . ':ok';
        } else {
            $errors[] = $alias . ':write_failed';
        }
    } else {
        // Source not yet generated — write a stub and log as warning
        $stub = json_encode(['ok' => false, 'run_at' => date(DateTimeInterface::ATOM), 'note' => 'source_not_yet_generated'], JSON_UNESCAPED_SLASHES);
        @file_put_contents($dst, $stub);
        $warnings[] = $alias . ':source_missing(stub_written)';
    }
}

/** Write a derived artifact from a data array */
function write_artifact(string $path, array $data, string $alias, array &$synced, array &$errors): void
{
    if (@file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
        $synced[] = $alias . ':ok';
    } else {
        $errors[] = $alias . ':write_failed';
    }
}

// 1) monitoring_check_last.json + ops_monitoring_last.json
//    Run build_ops_metrics if ops_metrics_last.json missing or stale
$opsMetrics = $logsDir . '/ops_metrics_last.json';
if (!is_file($opsMetrics) || (time() - @filemtime($opsMetrics)) > 3600) {
    $phpBin = PHP_BINARY ?: 'php';
    @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/build_ops_metrics.php') . ' 2>&1', $_, $mc);
}
$monitoringPayload = is_file($opsMetrics)
    ? json_decode((string)@file_get_contents($opsMetrics), true)
    : ['ok' => false, 'error' => 'ops_metrics_last.json not available'];
if (!is_array($monitoringPayload)) $monitoringPayload = ['ok' => false, 'error' => 'invalid_json'];
if (!array_key_exists('ok', $monitoringPayload)) {
    // ops_metrics has 'status' not 'ok' - derive ok
    $monitoringPayload['ok'] = ($monitoringPayload['status'] ?? 'UNKNOWN') !== 'CRITICAL';
}
write_artifact($logsDir . '/monitoring_check_last.json', $monitoringPayload, 'monitoring_check_last.json', $synced, $errors);
write_artifact($logsDir . '/ops_monitoring_last.json', $monitoringPayload, 'ops_monitoring_last.json', $synced, $errors);

// 2) runbook_onboarding_check_last.json (check docs exist)
$docsToCheck = [
    'docs/RUNBOOK_OPS.md',
    'docs/ONBOARDING_USER.md',
    'docs/GOVERNANCE_MODULE.md',
    'docs/runbook/README.md',
    'docs/onboarding/ONBOARDING.md',
    'docs/governance/NEW_MODULE.md',
];
$docsMissing = [];
foreach ($docsToCheck as $doc) {
    if (!is_file($root . '/' . $doc)) {
        $docsMissing[] = $doc;
    }
}
$runbookOk = count($docsMissing) === 0;
write_artifact($logsDir . '/runbook_onboarding_check_last.json', [
    'ok' => $runbookOk,
    'run_at' => date(DateTimeInterface::ATOM),
    'docs_checked' => $docsToCheck,
    'missing' => $docsMissing,
], 'runbook_onboarding_check_last.json', $synced, $errors);
if (!$runbookOk) $errors[] = 'runbook_check:docs_missing=' . implode(',', $docsMissing);

// 3) governance_check_last.json (alias from module_governance_lint_last.json)
$govSrc = $logsDir . '/module_governance_lint_last.json';
if (is_file($govSrc)) {
    sync_artifact($govSrc, $logsDir . '/governance_check_last.json', 'governance_check_last.json', $synced, $errors, $warnings);
} else {
    // Run module_governance_lint
    $phpBin = PHP_BINARY ?: 'php';
    @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/module_governance_lint.php') . ' --write-last 2>&1');
    sync_artifact($govSrc, $logsDir . '/governance_check_last.json', 'governance_check_last.json', $synced, $errors, $warnings);
}

// 4) menu_dashboard_sync_last.json (alias from menu_rbac_sync_last.json)
sync_artifact(
    $logsDir . '/menu_rbac_sync_last.json',
    $logsDir . '/menu_dashboard_sync_last.json',
    'menu_dashboard_sync_last.json',
    $synced,
    $errors,
    $warnings
);

// 5) audit_trail_check_last.json (alias from audit_e2e_probe_last.json)
$auditSrc = $logsDir . '/audit_e2e_probe_last.json';
if (!is_file($auditSrc)) {
    $auditSrc = $logsDir . '/audit_trail_probe_last.json';
}
sync_artifact($auditSrc, $logsDir . '/audit_trail_check_last.json', 'audit_trail_check_last.json', $synced, $errors, $warnings);

// 6-b) Dual-env RBAC matrix stubs (internal/public) — written by run_gate_dual.php
//  If not yet generated, write stubs so checklist artifacts exist
foreach (['internal', 'public'] as $env) {
    $rbacEnvPath = $logsDir . "/rbac_smoke_matrix_{$env}_last.json";
    if (!is_file($rbacEnvPath)) {
        $stub = json_encode(['ok'=>false,'run_at'=>date(DateTimeInterface::ATOM),'note'=>"not_run_yet_use_run_gate_dual.php",'mismatch_count'=>-1], JSON_UNESCAPED_SLASHES);
        @file_put_contents($rbacEnvPath, $stub);
        $warnings[] = "rbac_smoke_matrix_{$env}:stub_written";
    } else {
        $synced[] = "rbac_smoke_matrix_{$env}_last.json:exists";
    }
    $actionEnvPath = $logsDir . "/rbac_action_matrix_{$env}_last.json";
    if (!is_file($actionEnvPath)) {
        $stub = json_encode(['ok'=>false,'run_at'=>date(DateTimeInterface::ATOM),'note'=>"not_run_yet_use_run_gate_dual.php",'mismatch_count'=>-1], JSON_UNESCAPED_SLASHES);
        @file_put_contents($actionEnvPath, $stub);
        $warnings[] = "rbac_action_matrix_{$env}:stub_written";
    } else {
        $synced[] = "rbac_action_matrix_{$env}_last.json:exists";
    }
}

// 7) New canonical artifact names from spec ─────────────────────────────────
// monitoring_heartbeat_last.json
$mhPath = $logsDir . '/monitoring_heartbeat_last.json';
if (!is_file($mhPath)) {
    $phpBin2 = PHP_BINARY ?: 'php';
    @exec(escapeshellarg($phpBin2) . ' ' . escapeshellarg($root . '/tools/qa/monitoring_heartbeat.php') . ' --write-last 2>&1');
}
is_file($mhPath) ? $synced[] = 'monitoring_heartbeat_last.json:exists' : $warnings[] = 'monitoring_heartbeat_last.json:stub_written';

// runbook_check_last.json
$rcPath = $logsDir . '/runbook_check_last.json';
if (!is_file($rcPath)) {
    $phpBin2 = PHP_BINARY ?: 'php';
    @exec(escapeshellarg($phpBin2) . ' ' . escapeshellarg($root . '/tools/qa/runbook_check.php') . ' --write-last 2>&1');
}
is_file($rcPath) ? $synced[] = 'runbook_check_last.json:exists' : $warnings[] = 'runbook_check_last.json:stub_written';

// governance_module_registry_last.json
$gmrPath = $logsDir . '/governance_module_registry_last.json';
if (!is_file($gmrPath)) {
    $phpBin2 = PHP_BINARY ?: 'php';
    @exec(escapeshellarg($phpBin2) . ' ' . escapeshellarg($root . '/tools/qa/governance_module_registry.php') . ' --write-last 2>&1');
}
is_file($gmrPath) ? $synced[] = 'governance_module_registry_last.json:exists' : $warnings[] = 'governance_module_registry_last.json:stub_written';

// rbac_matrix_last.json (canonical alias)
$rmPath = $logsDir . '/rbac_matrix_last.json';
if (!is_file($rmPath) && is_file($logsDir . '/rbac_smoke_matrix_last.json')) {
    @copy($logsDir . '/rbac_smoke_matrix_last.json', $rmPath);
    $synced[] = 'rbac_matrix_last.json:aliased_from_smoke_matrix';
} elseif (is_file($rmPath)) {
    $synced[] = 'rbac_matrix_last.json:exists';
} else {
    $warnings[] = 'rbac_matrix_last.json:source_missing(stub_written)';
}

// rbac_action_matrix_last.json (canonical alias)
$ramPath = $logsDir . '/rbac_action_matrix_last.json';
if (!is_file($ramPath) && is_file($logsDir . '/rbac_action_smoke_matrix_last.json')) {
    @copy($logsDir . '/rbac_action_smoke_matrix_last.json', $ramPath);
    $synced[] = 'rbac_action_matrix_last.json:aliased';
} elseif (is_file($ramPath)) {
    $synced[] = 'rbac_action_matrix_last.json:exists';
} else {
    $warnings[] = 'rbac_action_matrix_last.json:source_missing(stub_written)';
}

// 6) backup_verify_last.json — run backup_verify_cli if missing
$bvPath = $logsDir . '/backup_verify_last.json';
if (!is_file($bvPath)) {
    $phpBin = PHP_BINARY ?: 'php';
    @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/backup_verify_cli.php') . ' --write-last 2>&1');
}
if (is_file($bvPath)) {
    $synced[] = 'backup_verify_last.json:exists';
} else {
    $errors[] = 'backup_verify_last.json:missing';
}

$ok = count($errors) === 0;
$payload = [
    'ok' => $ok,
    'run_at' => date(DateTimeInterface::ATOM),
    'synced' => $synced,
    'warnings' => $warnings,
    'errors' => $errors,
];

if ($writeLast) {
    @file_put_contents($logsDir . '/gate_artifacts_sync_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
