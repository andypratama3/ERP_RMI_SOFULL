<?php
/**
 * run_final_gate_dual.php — Deliverable E: Dual Layer Gate (INTERNAL + PUBLIC)
 *
 * Runs all gate checks against TWO base URLs:
 *   INTERNAL: http://10.10.60.20/ERP_RMI_SOFULL  (LAN)
 *   PUBLIC:   https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
 *
 * PASS = KEDUANYA overall_ok=true AND mismatch_count=0 AND /Volumes/ hits=0.
 *
 * Usage (on NAS):
 *   cd /volume4/web/ERP_RMI_SOFULL
 *   ./tools/nas/erp.sh php tools/qa/run_final_gate_dual.php --strict --write-last
 *
 * Artifacts:
 *   storage/logs/final_gate_dual_last.json          ← main machine-readable
 *   storage/logs/final_gate_dual_summary_last.md    ← human-readable MD ← NEW
 *   storage/logs/cutover_checks_internal_last.json
 *   storage/logs/cutover_checks_public_last.json
 *   storage/logs/cutover_checks.internal.last.json  (dot notation alias)
 *   storage/logs/cutover_checks.public.last.json    (dot notation alias)
 *   storage/logs/smoke_http_internal_last.json
 *   storage/logs/smoke_http_public_last.json
 *   storage/logs/contract_check_internal_last.json
 *   storage/logs/contract_check_public_last.json
 *   storage/logs/rbac_smoke_matrix_internal_last.json (+ .csv)
 *   storage/logs/rbac_smoke_matrix_public_last.json   (+ .csv)
 *   storage/logs/rbac_action_matrix_internal_last.json
 *   storage/logs/rbac_action_matrix_public_last.json
 *   storage/logs/rbac_matrix_last.json               (canonical alias)
 *   storage/logs/rbac_action_matrix_last.json        (canonical alias)
 *
 * ACCEPTANCE:
 *   - final_gate_dual_last.json overall_ok=true
 *   - rbac_smoke_matrix_* mismatch_count=0 (both layers)
 *   - rbac_action_matrix_* mismatch_count=0 (both layers)
 *   - /Volumes/ hits = 0 (CRITICAL)
 *   - FIN special: no violations
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/_shared/app_root_guard.php';
tools_assert_expected_app_root();
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/_shared/tools_bootstrap.php';

$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
$logsDir = ts_storage_logs_dir();
$runAt   = date(DateTimeInterface::ATOM);
$t0Total = microtime(true);

// ── Resolve dual URLs (canonical, no trailing slash; single-slash path join) ─
$internalUrl = (string)(
    getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('INTERNAL_BASE_URL') ?: getenv('TOOLS_BASE_URL') ?: 'http://10.10.60.20/ERP_RMI_SOFULL'
);
$publicUrl = (string)(
    getenv('TOOLS_BASE_URL_PUBLIC') ?: getenv('PUBLIC_BASE_URL') ?: getenv('APP_PUBLIC_URL') ?: 'https://erp.rizqullahmediska.com/ERP_RMI_SOFULL'
);
try {
    $internalUrl = normalize_base_url($internalUrl);
} catch (Throwable $e) {
    $internalUrl = rtrim($internalUrl, '/');
}
try {
    $publicUrl = normalize_base_url($publicUrl);
} catch (Throwable $e) {
    $publicUrl = rtrim($publicUrl, '/');
}

// Critical: URLs must not contain /Volumes/
foreach ([$internalUrl, $publicUrl] as $u) {
    if (stripos($u, '/Volumes/') !== false) {
        fwrite(STDERR, "CRITICAL FAIL: /Volumes/ in base URL: {$u}\n");
        exit(3);
    }
}

echo str_repeat('=', 64) . "\n";
echo "FINAL GATE DUAL-LAYER — ERP_RMI_SOFULL\n";
echo str_repeat('=', 64) . "\n";
echo "INTERNAL : {$internalUrl}\n";
echo "PUBLIC   : {$publicUrl}\n\n";

// ── Helpers ────────────────────────────────────────────────────────────────
function fgd_read(string $path): array {
    if (!is_file($path)) return [];
    return (array)(json_decode((string)file_get_contents($path), true) ?: []);
}
function fgd_cp(string $src, string $dst, bool $write): void {
    if ($write && is_file($src)) @copy($src, $dst);
}
function fgd_write(string $path, array $data, bool $write): void {
    if ($write) @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ── Run one layer ──────────────────────────────────────────────────────────
function run_layer_fgd(string $label, string $baseUrl, string $root, string $phpBin, string $logsDir, bool $writeLast): array {
    $sfx    = strtolower($label);
    $envPfx = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl)
            . ' TOOLS_BASE_URL='          . escapeshellarg($baseUrl)
            . ' SMOKE_BASE_URL='          . escapeshellarg($baseUrl)
            . ' APP_URL='                 . escapeshellarg($baseUrl) . ' ';

    echo str_repeat('-', 50) . "\n";
    echo "LAYER [{$label}] → {$baseUrl}\n";
    echo str_repeat('-', 50) . "\n";

    $t0 = microtime(true);

    // Step 1: Full cutover
    echo "  [1/4] cutover_checks...\n";
    $lines = []; $code = 1;
    @exec($envPfx . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/run_cutover_checks.php') . ' --strict --write-last 2>&1', $lines, $code);
    $cutoverSrc = $logsDir . '/cutover_checks.last.json';
    fgd_cp($cutoverSrc, $logsDir . "/cutover_checks_{$sfx}_last.json", $writeLast);
    fgd_cp($cutoverSrc, $logsDir . "/cutover_checks.{$sfx}.last.json", $writeLast);
    $cutoverData = fgd_read($cutoverSrc);
    $cutoverOk   = (bool)($cutoverData['overall_ok'] ?? ((int)$code === 0));
    $failCount   = (int)($cutoverData['summary']['fail_count'] ?? 0);
    echo "  [1/4] cutover => " . ($cutoverOk ? 'PASS' : 'FAIL') . " (fail:{$failCount})\n";

    // Step 2: Smoke HTTP
    echo "  [2/4] smoke_http...\n";
    $linesS = []; $codeS = 1;
    @exec($envPfx . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/smoke_http.php') . ' --strict --write-last 2>&1', $linesS, $codeS);
    $smokeSrc = $logsDir . '/smoke_http_last.json';
    fgd_cp($smokeSrc, $logsDir . "/smoke_http_{$sfx}_last.json", $writeLast);
    $smokeData = fgd_read($smokeSrc);
    $smokeOk   = (bool)($smokeData['ok'] ?? ((int)$codeS === 0));
    $smokeFail = (int)($smokeData['fail_count'] ?? $smokeData['summary']['fail'] ?? 0);
    echo "  [2/4] smoke_http => " . ($smokeOk ? 'PASS' : 'FAIL') . " (fail:{$smokeFail})\n";

    // Step 3: Contract
    echo "  [3/4] contract_check...\n";
    $linesC = []; $codeC = 1;
    @exec($envPfx . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/contract_check.php') . ' --strict --write-last 2>&1', $linesC, $codeC);
    $contractSrc = $logsDir . '/contract_check_last.json';
    fgd_cp($contractSrc, $logsDir . "/contract_check_{$sfx}_last.json", $writeLast);
    $contractData = fgd_read($contractSrc);
    $contractOk   = (bool)($contractData['ok'] ?? ((int)$codeC === 0));
    echo "  [3/4] contract => " . ($contractOk ? 'PASS' : 'FAIL') . "\n";

    // Step 4: RBAC matrix
    echo "  [4/4] rbac_matrix_http_check...\n";
    $linesR = []; $codeR = 1;
    @exec($envPfx . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/rbac_matrix_http_check.php') . ' --strict --write-last 2>&1', $linesR, $codeR);
    foreach (['rbac_smoke_matrix'=>"rbac_smoke_matrix_{$sfx}",'rbac_action_smoke_matrix'=>"rbac_action_matrix_{$sfx}"] as $src=>$dst) {
        fgd_cp($logsDir . "/{$src}_last.json", $logsDir . "/{$dst}_last.json", $writeLast);
        fgd_cp($logsDir . "/{$src}_last.csv",  $logsDir . "/{$dst}_last.csv",  $writeLast);
    }
    // Canonical aliases (written on last layer — overwritten each time, final = public)
    fgd_cp($logsDir . '/rbac_smoke_matrix_last.json',        $logsDir . '/rbac_matrix_last.json',        $writeLast);
    fgd_cp($logsDir . '/rbac_action_smoke_matrix_last.json', $logsDir . '/rbac_action_matrix_last.json', $writeLast);
    $rbacData     = fgd_read($logsDir . '/rbac_smoke_matrix_last.json');
    $rbacMismatch = (int)($rbacData['mismatch_count'] ?? ($codeR !== 0 ? 1 : 0));
    $actionData   = fgd_read($logsDir . '/rbac_action_smoke_matrix_last.json');
    $actionMismatch = (int)($actionData['mismatch_count'] ?? 0);
    // FIN violations
    $finViol = 0;
    foreach ((array)($rbacData['results'] ?? []) as $ep) {
        if (!($ep['cash_out'] ?? false)) continue;
        foreach ((array)($ep['role_tests'] ?? []) as $user => $rt) {
            $expected = (int)($rt['expected'] ?? 403);
            $actual   = (int)($rt['actual']   ?? 0);
            $uLow = strtolower((string)$user);
            if ($uLow !== 'mgrfin_bgr' && $uLow !== 'smokesys_sys' && $expected === 403 && $actual !== 403) {
                $finViol++;
            }
        }
    }
    echo "  [4/4] rbac => " . ($rbacMismatch === 0 ? 'PASS' : 'FAIL')
         . " (mismatch:{$rbacMismatch}, action:{$actionMismatch}, fin_viol:{$finViol})\n";

    $ms = (int)round((microtime(true) - $t0) * 1000);
    $layerOk = $cutoverOk && $smokeOk && $contractOk && $rbacMismatch === 0 && $actionMismatch === 0 && $finViol === 0;
    echo "  LAYER [{$label}] => " . ($layerOk ? '✅ PASS' : '❌ FAIL') . " ({$ms}ms)\n\n";

    return [
        'layer'           => $label,
        'base_url_masked' => ts_mask($baseUrl),
        'ok'              => $layerOk,
        'cutover_ok'      => $cutoverOk,
        'smoke_ok'        => $smokeOk,
        'contract_ok'     => $contractOk,
        'rbac_mismatch'   => $rbacMismatch,
        'action_mismatch' => $actionMismatch,
        'fin_violations'  => $finViol,
        'fail_count'      => $failCount,
        'duration_ms'     => $ms,
        'artifacts'       => [
            'cutover'  => ts_mask($logsDir . "/cutover_checks_{$sfx}_last.json"),
            'smoke'    => ts_mask($logsDir . "/smoke_http_{$sfx}_last.json"),
            'contract' => ts_mask($logsDir . "/contract_check_{$sfx}_last.json"),
            'rbac'     => ts_mask($logsDir . "/rbac_smoke_matrix_{$sfx}_last.json"),
            'action'   => ts_mask($logsDir . "/rbac_action_matrix_{$sfx}_last.json"),
        ],
    ];
}

// ── Shared: evaluate_alerts + restore_dry_run ─────────────────────────────
echo str_repeat('-', 50) . "\n";
echo "SHARED CHECKS\n";
echo str_repeat('-', 50) . "\n";
echo "  [A] evaluate_alerts...\n";
$evalLines = []; $evalCode = 1;
@exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/evaluate_alerts.php') . ' --write-last 2>&1', $evalLines, $evalCode);
$alertData     = fgd_read($logsDir . '/alert_evaluation_last.json');
$alertOk       = (bool)($alertData['ok'] ?? ((int)$evalCode === 0));
$criticalCount = (int)($alertData['critical_count'] ?? 0);
echo "  [A] alerts => " . ($alertOk ? 'PASS' : 'FAIL') . " (critical:{$criticalCount})\n";

echo "  [B] restore_dry_run...\n";
$rdLines = []; $rdCode = 1;
@exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/restore_dry_run.php') . ' --from-latest --write-last 2>&1', $rdLines, $rdCode);
$rdData    = fgd_read($logsDir . '/restore_dry_run_last.json');
$restoreOk = (bool)($rdData['ok'] ?? ((int)$rdCode === 0));
echo "  [B] restore => " . ($restoreOk ? 'PASS' : 'FAIL') . "\n\n";

// ── Run both layers ───────────────────────────────────────────────────────
$layerInt = run_layer_fgd('internal', $internalUrl, $root, $phpBin, $logsDir, $writeLast);
$layerPub = run_layer_fgd('public',   $publicUrl,   $root, $phpBin, $logsDir, $writeLast);

// ── Generate final_gate_summary (MD) ─────────────────────────────────────
echo "  [SUMMARY] Building final_gate_summary...\n";
$sumLines = []; $sumCode = 1;
@exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/build_final_gate_summary.php') . ' --write-last 2>&1', $sumLines, $sumCode);
$summaryOk = ((int)$sumCode === 0);
echo "  [SUMMARY] => " . ($summaryOk ? 'ok' : 'warn') . "\n\n";

// ── Shared artifact statuses ─────────────────────────────────────────────
$sharedArtifacts = [
    'menu_rbac_sync'         => $logsDir . '/menu_rbac_sync_last.json',
    'audit_e2e_probe'        => $logsDir . '/audit_e2e_probe_last.json',
    'backup_verify'          => $logsDir . '/backup_verify_last.json',
    'restore_dry_run'        => $logsDir . '/restore_dry_run_last.json',
    'module_governance_lint' => $logsDir . '/module_governance_lint_last.json',
    'alert_evaluation'       => $logsDir . '/alert_evaluation_last.json',
    'final_gate_summary_md'  => $logsDir . '/final_gate_summary_last.md',
    'final_gate_summary_json'=> $logsDir . '/final_gate_summary_last.json',
];
$sharedStatus = [];
foreach ($sharedArtifacts as $name => $path) {
    $d = fgd_read($path);
    $sharedStatus[$name] = ['exists' => is_file($path), 'ok' => (bool)($d['ok'] ?? null), 'path_masked' => ts_mask($path)];
}

// ── /Volumes/ path guard ─────────────────────────────────────────────────
$pgData = fgd_read($logsDir . '/base_path_guard_last.json');
$volumesHits = 0;
if (!empty($pgData['violations'])) {
    foreach ((array)$pgData['violations'] as $v) {
        if (stripos((string)($v['token'] ?? $v['detail'] ?? ''), '/Volumes/') !== false) $volumesHits++;
    }
}

// ── Overall ───────────────────────────────────────────────────────────────
$overallOk = $layerInt['ok'] && $layerPub['ok'] && $volumesHits === 0;
$msTotalMs = (int)round((microtime(true) - $t0Total) * 1000);

// ── JSON payload ──────────────────────────────────────────────────────────
$payload = [
    'state_version' => 1,
    'ok'            => $overallOk,
    'run_at'        => $runAt,
    'duration_ms'   => $msTotalMs,
    'internal'      => $layerInt,
    'public'        => $layerPub,
    'shared'        => $sharedStatus,
    'volumes_hits'  => $volumesHits,
    'summary' => [
        'overall_ok'               => $overallOk,
        'internal_ok'              => $layerInt['ok'],
        'public_ok'                => $layerPub['ok'],
        'rbac_mismatch_internal'   => $layerInt['rbac_mismatch'],
        'rbac_mismatch_public'     => $layerPub['rbac_mismatch'],
        'action_mismatch_internal' => $layerInt['action_mismatch'],
        'action_mismatch_public'   => $layerPub['action_mismatch'],
        'fin_violations_internal'  => $layerInt['fin_violations'],
        'fin_violations_public'    => $layerPub['fin_violations'],
        'alert_critical_count'     => $criticalCount,
        'volumes_hits'             => $volumesHits,
        'alert_ok'                 => $alertOk,
        'restore_ok'               => $restoreOk,
    ],
];
fgd_write($logsDir . '/final_gate_dual_last.json', $payload, $writeLast);
fgd_write($logsDir . '/cutover_dual_last.json',    $payload, $writeLast); // legacy alias

// ── Generate dual MD summary ──────────────────────────────────────────────
$runAtHuman = date('Y-m-d H:i:s T');
$go = $overallOk ? '✅ GO' : '🚫 NO-GO';
$mdDual  = "# Final Gate Dual-Layer Summary — ERP_RMI_SOFULL\n\n";
$mdDual .= "**Generated:** {$runAtHuman}  \n";
$mdDual .= "**Decision:** **{$go}**  \n";
$mdDual .= "**Duration:** {$msTotalMs}ms\n\n";
$mdDual .= "---\n\n";
$mdDual .= "## Layer Results\n\n";
$mdDual .= "| Layer | URL | Overall | Cutover | Smoke | Contract | RBAC Mismatch | Action Mismatch | FIN Viol |\n";
$mdDual .= "|-------|-----|---------|---------|-------|----------|---------------|-----------------|----------|\n";
foreach ([$layerInt, $layerPub] as $l) {
    $u = (string)$l['base_url_masked'];
    $mdDual .= "| {$l['layer']} | `{$u}` | " . ($l['ok'] ? '✅' : '❌') . " | " . ($l['cutover_ok'] ? '✅' : '❌')
             . " | " . ($l['smoke_ok'] ? '✅' : '❌') . " | " . ($l['contract_ok'] ? '✅' : '❌')
             . " | {$l['rbac_mismatch']} | {$l['action_mismatch']} | {$l['fin_violations']} |\n";
}
$mdDual .= "\n";
$mdDual .= "## Shared Checks\n\n";
$mdDual .= "| Check | Status |\n|-------|--------|\n";
$mdDual .= "| Alert evaluation | " . ($alertOk ? '✅ PASS' : "❌ FAIL (critical:{$criticalCount})") . " |\n";
$mdDual .= "| Restore dry-run | " . ($restoreOk ? '✅ PASS' : '❌ FAIL') . " |\n";
$mdDual .= "| /Volumes/ hits | " . ($volumesHits === 0 ? '✅ 0' : "🚨 {$volumesHits} CRITICAL") . " |\n";
$mdDual .= "\n## Artifacts\n\n";
$mdDual .= "| Artifact | Exists |\n|----------|--------|\n";
foreach ($sharedStatus as $name => $s) {
    $mdDual .= "| `{$name}` | " . ($s['exists'] ? '✅' : '⚪ missing') . " |\n";
}
$mdDual .= "\n---\n\n";
$mdDual .= "## FIN Special Rule\n\n";
$mdDual .= "- `MgrFIN_BGR` + SYS: AP_PAYMENT_APPROVE allowed ✅\n";
$mdDual .= "- All other users: AP_PAYMENT_APPROVE must return 403\n";
$finTotalViol = $layerInt['fin_violations'] + $layerPub['fin_violations'];
$mdDual .= "- Violations: " . ($finTotalViol === 0 ? '✅ 0' : "🚨 {$finTotalViol}") . "\n\n";
$mdDual .= "---\n\n_Generated by `tools/qa/run_final_gate_dual.php`_\n";

if ($writeLast) {
    @file_put_contents($logsDir . '/final_gate_dual_summary_last.md', $mdDual);
}

// ── Print result ──────────────────────────────────────────────────────────
echo str_repeat('=', 64) . "\n";
echo "FINAL GATE DUAL-LAYER RESULT\n";
echo str_repeat('=', 64) . "\n";
echo "INTERNAL  : " . ($layerInt['ok'] ? '✅ PASS' : '❌ FAIL') . "  (rbac_mismatch:" . $layerInt['rbac_mismatch'] . ", fin_viol:" . $layerInt['fin_violations'] . ")\n";
echo "PUBLIC    : " . ($layerPub['ok'] ? '✅ PASS' : '❌ FAIL') . "  (rbac_mismatch:" . $layerPub['rbac_mismatch'] . ", fin_viol:" . $layerPub['fin_violations'] . ")\n";
echo "ALERTS    : " . ($alertOk ? '✅ PASS' : "❌ FAIL (critical:{$criticalCount})") . "\n";
echo "RESTORE   : " . ($restoreOk ? '✅ PASS' : '❌ FAIL') . "\n";
echo "/VOLUMES/ : " . ($volumesHits === 0 ? '✅ 0 hits' : "🚨 CRITICAL: {$volumesHits} hits!") . "\n";
echo str_repeat('-', 64) . "\n";
echo "OVERALL   : " . ($overallOk ? '✅ PASS' : '❌ FAIL') . "  ({$msTotalMs}ms)\n";
if ($writeLast) {
    echo "ARTIFACTS :\n";
    echo "  JSON: " . ts_mask($logsDir . '/final_gate_dual_last.json') . "\n";
    echo "  MD:   " . ts_mask($logsDir . '/final_gate_dual_summary_last.md') . "\n";
}

exit($overallOk ? 0 : ($strict ? 2 : 1));
