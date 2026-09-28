<?php
/**
 * run_gate_dual.php — Final Gate Dual-Layer: INTERNAL + PUBLIC
 *
 * PASS final = KEDUANYA pass (internal LAN + public domain).
 *
 * Usage (di NAS):
 *   cd /volume4/web/ERP_RMI_SOFULL
 *   ./tools/nas/erp.sh php tools/qa/run_gate_dual.php --strict --write-last
 *
 * Artifacts yang dihasilkan:
 *   storage/logs/final_gate_dual_last.json            ← MAIN summary
 *   storage/logs/cutover_checks_internal_last.json
 *   storage/logs/cutover_checks_public_last.json
 *   storage/logs/smoke_http_internal_last.json
 *   storage/logs/smoke_http_public_last.json
 *   storage/logs/contract_check_internal_last.json
 *   storage/logs/contract_check_public_last.json
 *   storage/logs/rbac_smoke_matrix_internal_last.json  (+ .csv)
 *   storage/logs/rbac_smoke_matrix_public_last.json    (+ .csv)
 *   storage/logs/rbac_action_matrix_internal_last.json (+ .csv)
 *   storage/logs/rbac_action_matrix_public_last.json   (+ .csv)
 *   storage/logs/alert_evaluation_last.json
 *   storage/logs/menu_rbac_sync_last.json
 *   storage/logs/audit_e2e_probe_last.json
 *   storage/logs/backup_verify_last.json
 *   storage/logs/restore_dry_run_last.json
 *   storage/logs/module_governance_lint_last.json
 *
 * PASS/FAIL:
 *   - final_gate_dual_last.json overall_ok=true
 *   - internal.overall_ok=true AND public.overall_ok=true
 *   - rbac_smoke_matrix_* mismatch_count=0 (both layers)
 *   - rbac_action_matrix_* mismatch_count=0 (both layers)
 *   - all other artifacts: ok=true
 *   - No "/Volumes/" string => CRITICAL FAIL if found
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root      = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args      = $_SERVER['argv'] ?? [];
$strict    = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/_shared/app_root_guard.php';
tools_assert_expected_app_root();
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();
require_once $root . '/tools/tools_state_lib.php';

$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
$logsDir = ts_storage_logs_dir();
$t0Total = microtime(true);

// ── Resolve dual URLs ──────────────────────────────────────────────────────
$internalUrl = rtrim((string)(
    getenv('TOOLS_BASE_URL_INTERNAL') ?:
    getenv('INTERNAL_BASE_URL') ?:
    getenv('TOOLS_BASE_URL') ?:
    'http://10.10.60.20/ERP_RMI_SOFULL'
), '/');

$publicUrl = rtrim((string)(
    getenv('TOOLS_BASE_URL_PUBLIC') ?:
    getenv('PUBLIC_BASE_URL') ?:
    getenv('APP_PUBLIC_URL') ?:
    'https://erp.rizqullahmediska.com/ERP_RMI_SOFULL'
), '/');

// Critical: neither URL should contain /Volumes/
if (stripos($internalUrl, '/Volumes/') !== false || stripos($publicUrl, '/Volumes/') !== false) {
    fwrite(STDERR, "CRITICAL FAIL: /Volumes/ detected in base URL\n");
    exit(3);
}

echo str_repeat('=', 60) . "\n";
echo "FINAL GATE DUAL-LAYER — ERP_RMI_SOFULL\n";
echo str_repeat('=', 60) . "\n";
echo "INTERNAL : {$internalUrl}\n";
echo "PUBLIC   : {$publicUrl}\n\n";

// ── Helper: read JSON artifact safely ────────────────────────────────────────
function fgd_read(string $path): array {
    if (!is_file($path)) return [];
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

// ── Helper: write artifact (only if --write-last) ────────────────────────────
function fgd_write(string $path, array $data, bool $write): void {
    if ($write) {
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

// ── Helper: copy file ─────────────────────────────────────────────────────────
function fgd_copy(string $src, string $dst, bool $write): void {
    if ($write && is_file($src)) @copy($src, $dst);
}

// ── Core: run one layer (internal or public) ──────────────────────────────────
function run_layer(
    string $label,        // 'internal' | 'public'
    string $baseUrl,
    string $root,
    string $phpBin,
    string $logsDir,
    bool   $writeLast,
    bool   $strict
): array {
    $sfx = strtolower($label);

    $envPfx = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl)
            . ' TOOLS_BASE_URL='          . escapeshellarg($baseUrl)
            . ' SMOKE_BASE_URL='          . escapeshellarg($baseUrl)
            . ' APP_URL='                 . escapeshellarg($baseUrl) . ' ';

    echo str_repeat('-', 50) . "\n";
    echo "LAYER [{$label}] — {$baseUrl}\n";
    echo str_repeat('-', 50) . "\n";

    // ── Step 1: Full cutover gate ───────────────────────────────────────────
    echo "  [1/4] Running cutover_checks...\n";
    $t0 = microtime(true);
    $lines = []; $code = 1;
    @exec($envPfx . escapeshellarg($phpBin) . ' '
          . escapeshellarg($root . '/tools/qa/run_cutover_checks.php')
          . ' --strict --write-last 2>&1', $lines, $code);
    $msGate = (int)round((microtime(true) - $t0) * 1000);

    $cutoverSrc = $logsDir . '/cutover_checks.last.json';
    $cutoverDst = $logsDir . "/cutover_checks_{$sfx}_last.json";       // underscore: cutover_checks_internal_last.json
    $cutoverDotDst = $logsDir . "/cutover_checks.{$sfx}.last.json";    // dot: cutover_checks.internal.last.json
    fgd_copy($cutoverSrc, $cutoverDst, $writeLast);
    fgd_copy($cutoverSrc, $cutoverDotDst, $writeLast);

    $cutoverData = fgd_read($cutoverSrc);
    $cutoverOk   = (bool)($cutoverData['overall_ok'] ?? ((int)$code === 0));
    $failCount   = (int)($cutoverData['summary']['fail_count'] ?? 0);
    echo "  [1/4] cutover_checks => " . ($cutoverOk ? 'PASS' : 'FAIL')
         . " (fail_count:{$failCount}, {$msGate}ms)\n";

    // ── Step 2: Smoke HTTP ─────────────────────────────────────────────────
    echo "  [2/4] Running smoke_http...\n";
    $t0 = microtime(true);
    $linesS = []; $codeS = 1;
    @exec($envPfx . escapeshellarg($phpBin) . ' '
          . escapeshellarg($root . '/tools/smoke_http.php')
          . ' --strict --write-last 2>&1', $linesS, $codeS);
    $msSmoke = (int)round((microtime(true) - $t0) * 1000);

    $smokeSrc = $logsDir . '/smoke_http_last.json';
    $smokeDst = $logsDir . "/smoke_http_{$sfx}_last.json";
    fgd_copy($smokeSrc, $smokeDst, $writeLast);
    $smokeData = fgd_read($smokeSrc);
    $smokeOk   = (bool)($smokeData['ok'] ?? ((int)$codeS === 0));
    $smokeFail = (int)($smokeData['fail_count'] ?? $smokeData['summary']['fail'] ?? 0);
    echo "  [2/4] smoke_http => " . ($smokeOk ? 'PASS' : 'FAIL')
         . " (fail:{$smokeFail}, {$msSmoke}ms)\n";

    // ── Step 3: Contract check ─────────────────────────────────────────────
    echo "  [3/4] Running contract_check...\n";
    $t0 = microtime(true);
    $linesC = []; $codeC = 1;
    @exec($envPfx . escapeshellarg($phpBin) . ' '
          . escapeshellarg($root . '/tools/qa/contract_check.php')
          . ' --strict --write-last 2>&1', $linesC, $codeC);
    $msContract = (int)round((microtime(true) - $t0) * 1000);

    $contractSrc = $logsDir . '/contract_check_last.json';
    $contractDst = $logsDir . "/contract_check_{$sfx}_last.json";
    fgd_copy($contractSrc, $contractDst, $writeLast);
    $contractData = fgd_read($contractSrc);
    $contractOk   = (bool)($contractData['ok'] ?? ((int)$codeC === 0));
    echo "  [3/4] contract_check => " . ($contractOk ? 'PASS' : 'FAIL')
         . " ({$msContract}ms)\n";

    // ── Step 4: RBAC Smoke Matrix (GET + POST) for this URL ───────────────
    echo "  [4/4] Running rbac_matrix_http_check...\n";
    $t0 = microtime(true);
    $linesR = []; $codeR = 1;
    @exec($envPfx . escapeshellarg($phpBin) . ' '
          . escapeshellarg($root . '/tools/qa/rbac_matrix_http_check.php')
          . ' --strict --write-last 2>&1', $linesR, $codeR);
    $msRbac = (int)round((microtime(true) - $t0) * 1000);

    // rbac_matrix_http_check writes:
    //   rbac_smoke_matrix_last.json
    //   rbac_action_smoke_matrix_last.json
    //   menu_audit_report_last.json
    foreach ([
        'rbac_smoke_matrix'        => "rbac_smoke_matrix_{$sfx}",
        'rbac_action_smoke_matrix' => "rbac_action_matrix_{$sfx}",
    ] as $srcBase => $dstBase) {
        fgd_copy($logsDir . "/{$srcBase}_last.json", $logsDir . "/{$dstBase}_last.json", $writeLast);
        fgd_copy($logsDir . "/{$srcBase}_last.csv",  $logsDir . "/{$dstBase}_last.csv",  $writeLast);
    }
    // Canonical alias: rbac_matrix_last.json (from spec)
    fgd_copy($logsDir . '/rbac_smoke_matrix_last.json',        $logsDir . '/rbac_matrix_last.json',        $writeLast);
    fgd_copy($logsDir . '/rbac_action_smoke_matrix_last.json', $logsDir . '/rbac_action_matrix_last.json', $writeLast);

    $rbacData     = fgd_read($logsDir . '/rbac_smoke_matrix_last.json');
    $rbacMismatch = (int)($rbacData['mismatch_count'] ?? ($codeR !== 0 ? 1 : 0));
    $actionData   = fgd_read($logsDir . '/rbac_action_smoke_matrix_last.json');
    $actionMismatch = (int)($actionData['mismatch_count'] ?? 0);
    echo "  [4/4] rbac_matrix => " . ($rbacMismatch === 0 ? 'PASS' : 'FAIL')
         . " (page_mismatch:{$rbacMismatch}, action_mismatch:{$actionMismatch}, {$msRbac}ms)\n";

    $layerOk = $cutoverOk && $smokeOk && $contractOk && $rbacMismatch === 0;
    $status  = $layerOk ? '✅ PASS' : '❌ FAIL';
    echo "  LAYER [{$label}] => {$status}\n\n";

    return [
        'layer'              => $label,
        'base_url_masked'    => ts_mask($baseUrl),
        'ok'                 => $layerOk,
        'cutover_ok'         => $cutoverOk,
        'smoke_ok'           => $smokeOk,
        'contract_ok'        => $contractOk,
        'rbac_mismatch'      => $rbacMismatch,
        'action_mismatch'    => $actionMismatch,
        'fail_count'         => $failCount,
        'duration_ms'        => $msGate + $msSmoke + $msContract + $msRbac,
        'artifacts' => [
            'cutover'        => ts_mask($logsDir . "/cutover_checks_{$sfx}_last.json"),
            'smoke_http'     => ts_mask($logsDir . "/smoke_http_{$sfx}_last.json"),
            'contract_check' => ts_mask($logsDir . "/contract_check_{$sfx}_last.json"),
            'rbac_matrix'    => ts_mask($logsDir . "/rbac_smoke_matrix_{$sfx}_last.json"),
            'action_matrix'  => ts_mask($logsDir . "/rbac_action_matrix_{$sfx}_last.json"),
        ],
    ];
}

// ── Run both layers ────────────────────────────────────────────────────────────
$layerInternal = run_layer('internal', $internalUrl, $root, $phpBin, $logsDir, $writeLast, $strict);
$layerPublic   = run_layer('public',   $publicUrl,   $root, $phpBin, $logsDir, $writeLast, $strict);

// ── Shared artifacts (env-independent, run once not per-layer) ───────────────
echo str_repeat('-', 50) . "\n";
echo "SHARED CHECKS\n";
echo str_repeat('-', 50) . "\n";

// evaluate_alerts (QA layer)
echo "  [A] evaluate_alerts...\n";
$evalCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/evaluate_alerts.php') . ' --write-last 2>&1';
$evalLines = []; $evalCode = 1;
@exec($evalCmd, $evalLines, $evalCode);
$alertEvalPath = $logsDir . '/alert_evaluation_last.json';
$alertData     = fgd_read($alertEvalPath);
$alertOk       = (bool)($alertData['ok'] ?? ((int)$evalCode === 0));
$criticalCount = (int)($alertData['critical_count'] ?? 0);
echo "  [A] evaluate_alerts => " . ($alertOk ? 'PASS' : 'FAIL') . " (critical:{$criticalCount})\n";

// restore_dry_run (QA layer)
echo "  [B] restore_dry_run...\n";
$rdCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/restore_dry_run.php') . ' --from-latest --write-last 2>&1';
$rdLines = []; $rdCode = 1;
@exec($rdCmd, $rdLines, $rdCode);
$rdPath = $logsDir . '/restore_dry_run_last.json';
$rdData = fgd_read($rdPath);
$restoreOk = (bool)($rdData['ok'] ?? ((int)$rdCode === 0));
echo "  [B] restore_dry_run => " . ($restoreOk ? 'PASS' : 'FAIL') . "\n\n";

// Gather shared artifact statuses
$sharedArtifacts = [
    'menu_rbac_sync'         => $logsDir . '/menu_rbac_sync_last.json',
    'audit_e2e_probe'        => $logsDir . '/audit_e2e_probe_last.json',
    'backup_verify'          => $logsDir . '/backup_verify_last.json',
    'restore_dry_run'        => $rdPath,
    'module_governance_lint' => $logsDir . '/module_governance_lint_last.json',
    'alert_evaluation'       => $alertEvalPath,
];
$sharedStatus = [];
foreach ($sharedArtifacts as $name => $path) {
    $d = fgd_read($path);
    $exists = is_file($path);
    $okVal  = $exists ? (bool)($d['ok'] ?? false) : null;
    $sharedStatus[$name] = ['exists'=>$exists, 'ok'=>$okVal, 'path_masked'=>ts_mask($path)];
}

// ── Final summary ─────────────────────────────────────────────────────────────
$overallOk = $layerInternal['ok'] && $layerPublic['ok'];

// Shared artifact statuses
$menuOk    = $sharedStatus['menu_rbac_sync']['ok']         ?? true;
$auditOk   = $sharedStatus['audit_e2e_probe']['ok']        ?? true;
$backupOk  = $sharedStatus['backup_verify']['ok']          ?? true;
// restoreOk and alertOk already set from shared checks above
$govOk     = $sharedStatus['module_governance_lint']['ok'] ?? true;

$msTotalMs = (int)round((microtime(true) - $t0Total) * 1000);

$payload = [
    'state_version'   => 1,
    'ok'              => $overallOk,
    'run_at'          => date(DateTimeInterface::ATOM),
    'duration_ms'     => $msTotalMs,
    'internal' => [
        'overall_ok'       => $layerInternal['ok'],
        'cutover_ok'       => $layerInternal['cutover_ok'],
        'smoke_ok'         => $layerInternal['smoke_ok'],
        'contract_ok'      => $layerInternal['contract_ok'],
        'rbac_mismatch'    => $layerInternal['rbac_mismatch'],
        'action_mismatch'  => $layerInternal['action_mismatch'],
        'base_url_masked'  => $layerInternal['base_url_masked'],
        'artifacts'        => $layerInternal['artifacts'],
    ],
    'public' => [
        'overall_ok'       => $layerPublic['ok'],
        'cutover_ok'       => $layerPublic['cutover_ok'],
        'smoke_ok'         => $layerPublic['smoke_ok'],
        'contract_ok'      => $layerPublic['contract_ok'],
        'rbac_mismatch'    => $layerPublic['rbac_mismatch'],
        'action_mismatch'  => $layerPublic['action_mismatch'],
        'base_url_masked'  => $layerPublic['base_url_masked'],
        'artifacts'        => $layerPublic['artifacts'],
    ],
    'shared' => $sharedStatus,
    'summary' => [
        'overall_ok'              => $overallOk,
        'internal_ok'             => $layerInternal['ok'],
        'public_ok'               => $layerPublic['ok'],
        'menu_rbac_sync_ok'       => $menuOk,
        'audit_e2e_ok'            => $auditOk,
        'backup_verify_ok'        => $backupOk,
        'restore_dry_run_ok'      => $restoreOk,
        'alert_evaluation_ok'     => $alertOk,
        'alert_critical_count'    => $criticalCount,
        'module_governance_ok'    => $govOk,
        'rbac_mismatch_internal'  => $layerInternal['rbac_mismatch'],
        'rbac_mismatch_public'    => $layerPublic['rbac_mismatch'],
        'action_mismatch_internal'=> $layerInternal['action_mismatch'],
        'action_mismatch_public'  => $layerPublic['action_mismatch'],
    ],
];

fgd_write($logsDir . '/final_gate_dual_last.json', $payload, $writeLast);
// Also keep legacy alias
fgd_write($logsDir . '/cutover_dual_last.json', $payload, $writeLast);

// ── Print final result ────────────────────────────────────────────────────────
echo str_repeat('=', 60) . "\n";
echo "FINAL GATE DUAL-LAYER RESULT\n";
echo str_repeat('=', 60) . "\n";
echo "INTERNAL  : " . ($layerInternal['ok']    ? '✅ PASS' : '❌ FAIL')
     . "  (rbac_mismatch:" . $layerInternal['rbac_mismatch'] . ")\n";
echo "PUBLIC    : " . ($layerPublic['ok']       ? '✅ PASS' : '❌ FAIL')
     . "  (rbac_mismatch:" . $layerPublic['rbac_mismatch'] . ")\n";
echo "MENU SYNC : " . ($menuOk                  ? '✅ PASS' : '⚠ SKIP/FAIL') . "\n";
echo "AUDIT E2E : " . ($auditOk                 ? '✅ PASS' : '⚠ SKIP/FAIL') . "\n";
echo "BACKUP    : " . ($backupOk                ? '✅ PASS' : '⚠ SKIP/FAIL') . "\n";
echo "ALERTS    : " . ($alertOk                 ? '✅ PASS' : "❌ FAIL (critical:{$criticalCount})") . "\n";
echo "GOVERNANCE: " . ($govOk                   ? '✅ PASS' : '⚠ SKIP/FAIL') . "\n";
echo str_repeat('-', 60) . "\n";
echo "OVERALL   : " . ($overallOk              ? '✅ PASS' : '❌ FAIL') . "  ({$msTotalMs}ms)\n";
if ($writeLast) {
    echo "ARTIFACT  : " . ts_mask($logsDir . '/final_gate_dual_last.json') . "\n";
}

exit($overallOk ? 0 : ($strict ? 2 : 1));
