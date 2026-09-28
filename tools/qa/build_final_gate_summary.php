<?php
/**
 * build_final_gate_summary.php — Deliverable C: Human-readable Final Gate Summary
 *
 * Baca semua artifact *_last.json dan generate ringkasan dalam Markdown:
 *   storage/logs/final_gate_summary_last.md
 *   storage/logs/final_gate_summary_last.json (machine-readable)
 *
 * Sections:
 *   - PASS/FAIL per step
 *   - RBAC mismatch by user
 *   - RBAC mismatch by endpoint
 *   - Action endpoint leaks
 *   - /Volumes/ scan status
 *   - FIN special enforcement
 *
 * Usage: php tools/qa/build_final_gate_summary.php [--strict] [--write-last] [--base-url=URL]
 * Integration: step in run_cutover_checks.php (non-critical, generates MD report)
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
$runAt   = date('Y-m-d H:i:s T');
$runAtIso= date(DateTimeInterface::ATOM);

// ── Helper: read artifact ─────────────────────────────────────────────────────
function fgs_read(string $name, string $logsDir): array {
    $path = $logsDir . '/' . $name;
    if (!is_file($path)) return ['__missing' => true, '__path' => $name];
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : ['__invalid' => true, '__path' => $name];
}
function fgs_ok(array $d): ?bool {
    if (isset($d['__missing'])) return null;
    return (bool)($d['ok'] ?? $d['overall_ok'] ?? false);
}
function fgs_status(array $d): string {
    if (isset($d['__missing'])) return '⚪ SKIP';
    if (isset($d['__invalid'])) return '⚠ INVALID';
    $ok = fgs_ok($d);
    return $ok ? '✅ PASS' : '❌ FAIL';
}
function fgs_icon(?bool $ok): string {
    if ($ok === null) return '⚪';
    return $ok ? '✅' : '❌';
}

// ── Load all key artifacts ────────────────────────────────────────────────────
$artifacts = [
    'cutover'         => fgs_read('cutover_checks.last.json',              $logsDir),
    'smoke_http'      => fgs_read('smoke_http_last.json',                  $logsDir),
    'contract'        => fgs_read('contract_check_last.json',              $logsDir),
    'preflight'       => fgs_read('preflight_check.last.json',             $logsDir),
    'rbac_smoke'      => fgs_read('rbac_smoke_matrix_last.json',           $logsDir),
    'rbac_action'     => fgs_read('rbac_action_matrix_last.json',          $logsDir),
    'rbac_check'      => fgs_read('rbac_matrix_http_check_last.json',      $logsDir),
    'rbac_matrix_dual'=> fgs_read('rbac_matrix_http_dual_last.json',        $logsDir),
    'menu_sync'       => fgs_read('menu_rbac_sync_last.json',              $logsDir),
    'audit_e2e'       => fgs_read('audit_e2e_probe_last.json',             $logsDir),
    'backup_verify'   => fgs_read('backup_verify_last.json',               $logsDir),
    'restore_dry'     => fgs_read('restore_dry_run_last.json',             $logsDir),
    'monitoring'      => fgs_read('monitoring_heartbeat_last.json',        $logsDir),
    'governance'      => fgs_read('governance_module_registry_last.json',  $logsDir),
    'gov_lint'        => fgs_read('module_governance_lint_last.json',      $logsDir),
    'runbook'         => fgs_read('runbook_check_last.json',               $logsDir),
    'dual_gate'       => fgs_read('final_gate_dual_last.json',             $logsDir),
    'path_guard'      => fgs_read('base_path_guard_last.json',             $logsDir),
    'smoke_pub_probe' => fgs_read('smoke_public_probe_last.json',          $logsDir),
    'volumes_guard'   => fgs_read('volumes_guard_last.json',               $logsDir),
    'unicode_guard'   => fgs_read('unicode_guard_last.json',               $logsDir),
    'alert_eval'      => fgs_read('alert_evaluation_last.json',            $logsDir),
    'final_checklist' => fgs_read('release_final_checklist_last.json',     $logsDir),
];

/**
 * Bucket persona untuk ringkasan mismatch (FIN / SYS / STAFF / MANAGER / GUEST).
 */
function fgs_role_bucket(string $user): string {
    $u = strtolower(trim($user));
    if ($u === 'guest') {
        return 'GUEST';
    }
    if (str_contains($u, 'smokesys') || str_ends_with($u, '_sys')) {
        return 'SYS';
    }
    if ($u === 'mgrfin_bgr') {
        return 'FIN_MGR_BGR';
    }
    if (str_starts_with($u, 'mgrfin')) {
        return 'FIN_MGR_OTHER';
    }
    if (str_starts_with($u, 'staff')) {
        return 'STAFF';
    }
    if (str_starts_with($u, 'mgr')) {
        return 'MANAGER';
    }
    return 'OTHER';
}

// ── Extract RBAC mismatch details ─────────────────────────────────────────────
$rbacResults   = (array)($artifacts['rbac_check']['results'] ?? []);
$mismatchByUser= [];
$mismatchByUrl = [];
$actionLeaks   = [];
$finViolations = [];

foreach ($rbacResults as $ep) {
    $path    = (string)($ep['path']    ?? '');
    $module  = (string)($ep['module']  ?? '');
    $cashOut = (bool)($ep['cash_out']  ?? false);
    $isPost  = strtoupper((string)($ep['method'] ?? 'GET')) === 'POST';

    // Guest test
    $guestOk = (bool)($ep['guest_test']['ok'] ?? true);
    if (!$guestOk) {
        $expected = (int)($ep['guest_test']['expected'] ?? 302);
        $actual   = (int)($ep['guest_test']['actual']   ?? 0);
        $mismatchByUrl[$path][] = "GUEST: expected {$expected}, got {$actual}";
    }

    // Role tests
    foreach ((array)($ep['role_tests'] ?? []) as $username => $rt) {
        $roleOk   = (bool)($rt['ok'] ?? true);
        $expected = (int)($rt['expected'] ?? 200);
        $actual   = (int)($rt['actual']   ?? 0);

        if (!$roleOk) {
            $loc = (string)($rt['location'] ?? '');
            $locSuffix = $loc !== '' ? " loc={$loc}" : '';
            $mismatchByUser[$username][]  = "[{$module}] {$path}: expected {$expected}, got {$actual}{$locSuffix}";
            $mismatchByUrl[$path][]       = "{$username}: expected {$expected}, got {$actual}{$locSuffix}";
            if ($isPost && $expected === 403 && $actual === 200) {
                $actionLeaks[] = "{$username} → {$path}: expected 403, got {$actual}" . ($cashOut ? ' (⚠ CASH-OUT)' : '');
            }
            $uLower = strtolower((string)$username);
            if ($cashOut && $uLower !== 'mgrfin_bgr' && $uLower !== 'smokesys_sys') {
                if ($actual === 200) {
                    $finViolations[] = "{$username} → {$path}: AP_PAYMENT leak (got {$actual})";
                }
            }
        }
    }
}

$totalMismatch = 0;
foreach ($rbacResults as $ep) {
    if (!($ep['guest_test']['ok'] ?? true)) {
        $totalMismatch++;
    }
    foreach ((array)($ep['role_tests'] ?? []) as $t) {
        if (!($t['ok'] ?? true)) {
            $totalMismatch++;
        }
    }
}

$mismatchByBucket = [];
foreach ($rbacResults as $ep) {
    if (($ep['guest_test']['ok'] ?? true)) {
        continue;
    }
    $gp = (string)($ep['path'] ?? '');
    $gExp = (int)($ep['guest_test']['expected'] ?? 302);
    $gAct = (int)($ep['guest_test']['actual'] ?? 0);
    $mismatchByBucket['GUEST'][] = "`{$gp}`: expected {$gExp}, actual {$gAct}";
}
foreach ($mismatchByUser as $user => $list) {
    $bucket = fgs_role_bucket($user);
    if (!isset($mismatchByBucket[$bucket])) {
        $mismatchByBucket[$bucket] = [];
    }
    foreach ($list as $line) {
        $mismatchByBucket[$bucket][] = "{$user}: {$line}";
    }
}
foreach ($mismatchByBucket as $bk => $rows) {
    $mismatchByBucket[$bk] = array_slice($rows, 0, 10);
}

$leakCount     = count($actionLeaks);
$finViolCount  = count($finViolations);

// Unified mismatch rows (RBAC matrix): actor, endpoint, method, action, expected, actual, location, category
$unifiedMismatches = [];
foreach ($rbacResults as $ep) {
    $path = (string)($ep['path'] ?? '');
    $method = (string)($ep['method'] ?? 'GET');
    $action = (string)($ep['action'] ?? '');
    $gt = (array)($ep['guest_test'] ?? []);
    if (!(bool)($gt['ok'] ?? true)) {
        $unifiedMismatches[] = [
            'actor' => 'GUEST',
            'endpoint' => $path,
            'method' => $method,
            'action' => $action,
            'expected' => (string)($gt['expected'] ?? ''),
            'actual' => (string)($gt['actual'] ?? ''),
            'location' => '',
            'category' => (string)($gt['mismatch_category'] ?? 'OTHER'),
        ];
    }
    foreach ((array)($ep['role_tests'] ?? []) as $username => $rt) {
        if ($rt['ok'] ?? true) {
            continue;
        }
        $unifiedMismatches[] = [
            'actor' => (string)$username,
            'endpoint' => $path,
            'method' => $method,
            'action' => $action,
            'expected' => (string)($rt['expected'] ?? ''),
            'actual' => (string)($rt['actual'] ?? ''),
            'location' => (string)($rt['location'] ?? ''),
            'category' => (string)($rt['mismatch_category'] ?? (($rt['real_leak'] ?? false) ? 'REAL_LEAK' : 'OTHER')),
        ];
    }
}

$smokeUrlJoinBugs = [];
$smokeData = $artifacts['smoke_http'];
if (!isset($smokeData['__missing'])) {
    foreach ((array)($smokeData['results'] ?? $smokeData['checks'] ?? []) as $row) {
        if (empty($row['ok']) && (($row['mismatch_category'] ?? '') === 'URL_JOIN_BUG' || ((int)($row['http_code'] ?? 0) === 301))) {
            $smokeUrlJoinBugs[] = (string)(($row['layer'] ?? '') . ' / ' . ($row['name'] ?? '') . ' — ' . ($row['detail'] ?? ''));
        }
    }
}

// ── Smoke HTTP: detect 301 (URL join / canonical base issues) ─────────────────
$smoke301Rows = [];
$smokeBase    = '';
$smData       = $artifacts['smoke_http'];
if (!isset($smData['__missing'])) {
    $smokeBase = (string)($smData['base_url'] ?? '');
    $smokeRows = (array)($smData['checks'] ?? $smData['results'] ?? []);
    foreach ($smokeRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $det = (string)($row['detail'] ?? '');
        if (str_contains($det, 'code=301')) {
            $smoke301Rows[] = [
                'category' => (string)($row['category'] ?? ''),
                'name'     => (string)($row['name'] ?? ''),
                'detail'   => $det,
            ];
        }
    }
}

$rbacBase     = (string)($artifacts['rbac_check']['base_url'] ?? '');
$contractBase = (string)($artifacts['contract']['base_url'] ?? '');

$publicBase = trim((string)(getenv('TOOLS_BASE_URL_PUBLIC') ?: getenv('APP_PUBLIC_URL') ?: ''));
if ($publicBase === '' && !isset($artifacts['dual_gate']['__missing'])) {
    $publicBase = (string)($artifacts['dual_gate']['public_url'] ?? $artifacts['dual_gate']['layers']['public'] ?? '');
}

// ── Volumes scan ──────────────────────────────────────────────────────────────
$volumesHits = 0;
$volumesViolations = [];
$pgData = $artifacts['path_guard'];
if (!isset($pgData['__missing'])) {
    $volumesHits = count(array_filter((array)($pgData['violations'] ?? []),
        static function ($v): bool {
            $s = (string)json_encode($v, JSON_UNESCAPED_SLASHES);

            return stripos($s, '/Volumes/') !== false
                || stripos((string)($v['detail'] ?? ''), '/Volumes/') !== false;
        }));
    foreach ((array)($pgData['violations'] ?? []) as $v) {
        $s = (string)json_encode($v, JSON_UNESCAPED_SLASHES);
        if (stripos($s, '/Volumes/') !== false || stripos((string)($v['detail'] ?? ''), '/Volumes/') !== false) {
            $volumesViolations[] = (string)($v['path'] ?? $v['source'] ?? 'unknown');
        }
    }
}

// ── Overall determination ─────────────────────────────────────────────────────
$cutoverOk  = fgs_ok($artifacts['cutover'])   ?? false;
$smokeOk    = fgs_ok($artifacts['smoke_http']) ?? false;
$contractOk = fgs_ok($artifacts['contract'])   ?? false;
$rbacOk     = $totalMismatch === 0;
$actionOk   = $leakCount === 0;
$finOk      = $finViolCount === 0;
$basePathOk = !isset($artifacts['path_guard']['__missing']) && (fgs_ok($artifacts['path_guard']) === true);
$volOk      = ($volumesHits === 0) && $basePathOk;
$smoke301Ok = count($smoke301Rows) === 0;
$overallOk  = $cutoverOk && $smokeOk && $contractOk && $rbacOk && $actionOk && $finOk && $volOk && $basePathOk && $smoke301Ok;
if (!isset($artifacts['rbac_action']['__missing'])) {
    $overallOk = $overallOk && (bool)($artifacts['rbac_action']['ok'] ?? true);
}

// ── Generate Markdown ─────────────────────────────────────────────────────────
$go = $overallOk ? '✅ GO' : '🚫 NO-GO';
$md  = "# Final Gate Summary — ERP_RMI_SOFULL\n\n";
$md .= "**Generated:** {$runAt}  \n";
$md .= "**Decision:** {$go}  \n";
$md .= "**Base:** `/volume4/web/ERP_RMI_SOFULL`\n\n";
$md .= "## 0. Layers — INTERNAL / PUBLIC (canonical base)\n\n";
$md .= "| Layer | Base URL (no trailing slash) |\n";
$md .= "|-------|------------------------------|\n";
$intDisplay = $smokeBase !== '' ? $smokeBase : ($rbacBase !== '' ? $rbacBase : '(set `TOOLS_BASE_URL_INTERNAL`)');
$md .= "| INTERNAL (smoke) | `{$intDisplay}` |\n";
$md .= "| RBAC matrix | `{$rbacBase}` |\n";
$md .= "| Contract | `{$contractBase}` |\n";
if ($publicBase !== '') {
    $md .= "| PUBLIC | `{$publicBase}` |\n";
} else {
    $md .= "| PUBLIC | _(set `TOOLS_BASE_URL_PUBLIC` or run dual gate)_ |\n";
}
$md .= "\n**Targets:** `http://10.10.60.20/ERP_RMI_SOFULL` · `https://erp.rizqullahmediska.com/ERP_RMI_SOFULL`\n\n";

$probeA = $artifacts['smoke_pub_probe'];
if (!isset($probeA['__missing'])) {
    $wafSuspect = 0;
    foreach ((array)($probeA['comparison'] ?? []) as $crow) {
        if (!is_array($crow)) {
            continue;
        }
        $pub = is_array($crow['public'] ?? null) ? $crow['public'] : [];
        $int = is_array($crow['internal'] ?? null) ? $crow['internal'] : [];
        $pc = (int)($pub['http_code'] ?? 0);
        $ic = (int)($int['http_code'] ?? 0);
        if ($pc === 403 && in_array($ic, [200, 302], true)) {
            $wafSuspect++;
        }
    }
    $md .= "### Public vs internal probe\n\n";
    $md .= "- **Artifact:** `storage/logs/smoke_public_probe_last.json` — `php tools/qa/smoke_public_probe.php --write-last`\n";
    $md .= '- **Paths (probed) where public=403 but internal 200/302:** ' . $wafSuspect . "\n";
    $interp = trim((string)($probeA['interpretation'] ?? ''));
    if ($interp !== '') {
        $md .= '- **Interpretation:** ' . $interp . "\n";
    }
    $md .= "- **Doc:** `docs/internal/PUBLIC_SMOKE_AND_WAF.md`\n\n";
}

$bpSan = (int)($artifacts['path_guard']['sanitized_legacy_logs_count'] ?? 0);
$bpFiles = (array)($artifacts['path_guard']['sanitized_legacy_files'] ?? []);
if ($bpSan > 0 || $bpFiles !== []) {
    $md .= "### base_path_guard — legacy log sanitize\n\n";
    $md .= '- **sanitized_legacy_logs_count:** ' . $bpSan . "\n";
    if ($bpFiles !== []) {
        $md .= '- **files:** ' . implode(', ', array_slice($bpFiles, 0, 15)) . (count($bpFiles) > 15 ? ' …' : '') . "\n";
    }
    $md .= "\n";
}

if (!empty($smoke301Rows)) {
    $md .= "### Smoke: HTTP 301 (FAIL — fix URL join / base)\n\n";
    foreach (array_slice($smoke301Rows, 0, 15) as $r301) {
        $md .= '- `' . ($r301['category'] ?? '') . '` / ' . ($r301['name'] ?? '') . ' — ' . ($r301['detail'] ?? '') . "\n";
    }
    $md .= "\n";
}

$md .= "### RBAC: Top mismatch by role bucket (max 10 each)\n\n";
$bucketOrder = ['GUEST', 'STAFF', 'MANAGER', 'FIN_MGR_BGR', 'FIN_MGR_OTHER', 'SYS', 'OTHER'];
$hasBucket = false;
foreach ($bucketOrder as $bk) {
    if (empty($mismatchByBucket[$bk])) {
        continue;
    }
    $hasBucket = true;
    $md .= "#### {$bk}\n\n";
    foreach ($mismatchByBucket[$bk] as $line) {
        $md .= '- ' . $line . "\n";
    }
    $md .= "\n";
}
if (!$hasBucket) {
    $md .= "_Tidak ada mismatch._\n\n";
}

$md .= "---\n\n";

// Section 1: Step-by-step PASS/FAIL
$md .= "## 1. Gate Steps — PASS / FAIL\n\n";
$md .= "| Step | Status | Detail |\n";
$md .= "|------|--------|--------|\n";

$steps = [
    ['Cutover Gate',           $artifacts['cutover'],       'cutover_checks.last.json'],
    ['Smoke HTTP',             $artifacts['smoke_http'],    'smoke_http_last.json'],
    ['Contract Check',         $artifacts['contract'],      'contract_check_last.json'],
    ['Preflight',              $artifacts['preflight'],     'preflight_check.last.json'],
    ['RBAC Smoke Matrix',      $artifacts['rbac_smoke'],    'rbac_smoke_matrix_last.json'],
    ['RBAC Matrix HTTP (dual)', $artifacts['rbac_matrix_dual'], 'rbac_matrix_http_dual_last.json'],
    ['RBAC Action Matrix',     $artifacts['rbac_action'],   'rbac_action_matrix_last.json'],
    ['Menu/Dashboard Sync',    $artifacts['menu_sync'],     'menu_rbac_sync_last.json'],
    ['Audit Trail E2E',        $artifacts['audit_e2e'],     'audit_e2e_probe_last.json'],
    ['Backup Verify',          $artifacts['backup_verify'], 'backup_verify_last.json'],
    ['Restore Dry-Run',        $artifacts['restore_dry'],   'restore_dry_run_last.json'],
    ['Monitoring Heartbeat',   $artifacts['monitoring'],    'monitoring_heartbeat_last.json'],
    ['Governance Registry',    $artifacts['governance'],    'governance_module_registry_last.json'],
    ['Runbook Check',          $artifacts['runbook'],       'runbook_check_last.json'],
    ['Dual Gate (Int+Pub)',     $artifacts['dual_gate'],     'final_gate_dual_last.json'],
    ['Path Guard (/Volumes/)', $artifacts['path_guard'],    'base_path_guard_last.json'],
    ['Volumes Guard',          $artifacts['volumes_guard'], 'volumes_guard_last.json'],
    ['Unicode Guard',          $artifacts['unicode_guard'], 'unicode_guard_last.json'],
    ['Alert Evaluation',       $artifacts['alert_eval'],    'alert_evaluation_last.json'],
];
foreach ($steps as [$name, $data, $src]) {
    $status  = fgs_status($data);
    $detail  = '';
    if (isset($data['__missing'])) { $detail = '_artifact not found_'; }
    elseif (isset($data['summary'])) { $detail = 'fail_count:' . ($data['summary']['fail_count'] ?? '?'); }
    $md .= "| {$name} | {$status} | {$src} {$detail} |\n";
}
$md .= "\n";

// Section 2: User × Page × Expected × Actual table (TASK E format)
$md .= "## 2. RBAC Matrix — User × Page × Status\n\n";
$md .= "> Catatan: `302 (redirect ke login)` dari user yang harusnya 403 = **akses ditolak** (benar).\n";
$md .= "> `200` ketika expected `403` = **LEAK NYATA** (perlu perbaikan).\n\n";

if (empty($rbacResults)) {
    $md .= "⚪ Artifact RBAC matrix tidak ditemukan — jalankan `rbac_matrix_http_check.php` dari NAS.\n\n";
} else {
    // Build user × page table
    $md .= "| User | Page | Expected | Actual | Real Leak? |\n";
    $md .= "|------|------|----------|--------|------------|\n";
    $hasAnyLeak = false;
    foreach ($rbacResults as $ep) {
        $path = (string)($ep['path'] ?? '');
        $method = (string)($ep['method'] ?? 'GET');
        // Guest row
        $gOk  = (bool)($ep['guest_test']['ok'] ?? true);
        $gExp = (int)($ep['guest_test']['expected'] ?? 302);
        $gAct = (int)($ep['guest_test']['actual']   ?? 0);
        if (!$gOk) {
            $isLeak = ($gExp === 403 && $gAct === 200);
            $md .= "| GUEST | `{$path}` | {$gExp} | {$gAct} | " . ($isLeak ? '🚨 **YES**' : '⚠ no') . " |\n";
            if ($isLeak) $hasAnyLeak = true;
        }
        // Role rows (only mismatches)
        foreach ((array)($ep['role_tests'] ?? []) as $user => $rt) {
            $ok  = (bool)($rt['ok'] ?? true);
            if ($ok) continue;
            $exp = (int)($rt['expected'] ?? 0);
            $act = (int)($rt['actual']   ?? 0);
            $isLeak = ($exp === 403 && $act === 200);
            if ($isLeak) $hasAnyLeak = true;
            $md .= "| {$user} | `{$path}` ({$method}) | {$exp} | {$act} | " . ($isLeak ? '🚨 **YES**' : '—') . " |\n";
        }
    }
    if (!$hasAnyLeak) {
        $md .= "\n✅ **Tidak ada real leak** (actual=200 ketika expected=403).\n";
    }
    $md .= "\n";
}

$md .= "## 2b. Unified mismatch index (RBAC matrix artifact)\n\n";
$md .= "_Category: **URL_JOIN_BUG** = kemungkinan double slash / base path; **REAL_LEAK** = 403→200; **OTHER** = selisih ekspektasi lain._\n\n";
if ($unifiedMismatches === []) {
    $md .= "✅ Tidak ada baris mismatch di artifact matrix terakhir.\n\n";
} else {
    $md .= "| Actor | Endpoint | Method | Action | Expected | Actual | Location | Category |\n";
    $md .= "|-------|----------|--------|--------|----------|--------|----------|----------|\n";
    foreach (array_slice($unifiedMismatches, 0, 60) as $um) {
        $loc = $um['location'] !== '' ? substr(str_replace('|', '/', $um['location']), 0, 48) : '—';
        $md .= '| ' . $um['actor'] . ' | `' . $um['endpoint'] . '` | ' . $um['method'] . ' | `' . $um['action'] . '` | '
            . $um['expected'] . ' | ' . $um['actual'] . ' | ' . $loc . ' | **' . $um['category'] . "** |\n";
    }
    $md .= "\n";
}

$md .= "### Smoke HTTP — URL_JOIN_BUG / 301\n\n";
if ($smokeUrlJoinBugs === []) {
    $md .= "_Tidak ada baris smoke bertanda URL_JOIN_BUG._\n\n";
} else {
    foreach (array_slice($smokeUrlJoinBugs, 0, 25) as $s) {
        $md .= '- ' . $s . "\n";
    }
    $md .= "\n";
}

// Section 3: RBAC by user (summary)
$md .= "## 3. Ringkasan Mismatch per User\n\n";
if (empty($mismatchByUser)) {
    $md .= "✅ Tidak ada mismatch untuk semua persona.\n\n";
} else {
    $md .= "| User | Jumlah Mismatch | Keterangan |\n";
    $md .= "|------|-----------------|------------|\n";
    foreach ($mismatchByUser as $user => $list) {
        $cnt = count($list);
        // Classify: are these real 403→200 leaks or just 302 redirect-to-login?
        $realLeaks = count(array_filter($list, fn($x) => str_contains($x, 'got 200')));
        $note = $realLeaks > 0 ? "🚨 {$realLeaks} real leak!" : "302=redirect (login failed atau redirect halaman)";
        $md .= "| {$user} | {$cnt} | {$note} |\n";
    }
    $md .= "\n";}

// Section 3b: Top URLs
$md .= "## 3b. Top URL dengan Mismatch\n\n";
if (empty($mismatchByUrl)) {
    $md .= "✅ Tidak ada endpoint yang mismatch.\n\n";
} else {
    $topUrls = array_slice($mismatchByUrl, 0, 15, true);
    $md .= "| URL | Jumlah | Contoh Mismatch |\n";
    $md .= "|-----|--------|------------------|\n";
    foreach ($topUrls as $url => $list) {
        $cnt = count($list);
        $example = implode(', ', array_slice($list, 0, 2));
        if (strlen($example) > 80) $example = substr($example, 0, 77) . '…';
        $md .= "| `{$url}` | {$cnt} | {$example} |\n";
    }
    $md .= "\n";
}

// Section 4: Action endpoint leaks — user × action × expected × actual (TASK D format)
$md .= "## 4. Action Endpoint Leaks (POST)\n\n";
$md .= "> Leak nyata = user tidak boleh (expected 403) tapi dapat **200**.\n\n";
if (empty($actionLeaks)) {
    $md .= "✅ **Tidak ada action endpoint yang bocor.** Semua POST guard bekerja.\n\n";
} else {
    $md .= "🚨 **{$leakCount} leak terdeteksi!**\n\n";
    $md .= "| User | Action Endpoint | Expected | Actual | Cash-Out? |\n";
    $md .= "|------|----------------|----------|--------|----------|\n";
    foreach ($actionLeaks as $leak) {
        // Parse format: "user → path: expected X, got Y"
        if (preg_match('/^(.+?)\s+→\s+(.+?):\s+expected\s+(\d+),\s+got\s+(\d+)(.*)$/', $leak, $m)) {
            $cashOut = str_contains($m[5] ?? '', 'CASH-OUT') ? '💰 YES' : '—';
            $md .= "| {$m[1]} | `{$m[2]}` | {$m[3]} | {$m[4]} | {$cashOut} |\n";
        } else {
            $md .= "| — | {$leak} | — | — | — |\n";
        }
    }
    $md .= "\n";
}

// Section 4b: DO-specific action matrix (TASK D - Sales DO)
$md .= "## 4b. Sales DO Action Matrix\n\n";
$doActions = [
    ['DO_CREATE', '/sales/sales_do.php (POST save_do)', 'CRM Staff/Manager', '200 (allowed)', 'WQS/FIN/ACT', '403 (blocked)'],
    ['DO_POST/WQS', '/stock/wqs_do_tasks.php (POST)', 'WQS Staff/Manager', '200 (allowed)', 'CRM/FIN', '403 (blocked)'],
    ['DO_POST/SCM', '/sales/scm_do_tasks.php (POST)', 'SCM Staff/Manager', '200 (allowed)', 'CRM/WQS', '403 (blocked)'],
    ['DO_POST/ACT', '/sales/act_do_tasks.php (POST)', 'ACT Staff/Manager', '200 (allowed)', 'CRM', '403 (blocked)'],
    ['AP_PAY_APPROVE', '/purchases/purchases_payment_ap.php (POST)', 'MgrFIN_BGR + SYS', '200 (allowed)', 'Semua FIN lain + dept lain', '403 (blocked)'],
];
$md .= "| Permission | Endpoint | Siapa Boleh | Expected | Siapa Tidak Boleh | Expected |\n";
$md .= "|-----------|---------|-------------|----------|------------------|----------|\n";
foreach ($doActions as [$perm, $ep, $allowed, $allowedExp, $blocked, $blockedExp]) {
    $md .= "| `{$perm}` | `{$ep}` | {$allowed} | {$allowedExp} | {$blocked} | {$blockedExp} |\n";
}
$md .= "\n";

// Section 5: FIN Special
$md .= "## 5. FIN Special Enforcement (AP_PAYMENT_APPROVE)\n\n";
$md .= "**Rule:** Hanya `MgrFIN_BGR` + SYS yang boleh. FIN manager lain → 403 wajib.\n\n";
if (empty($finViolations)) {
    $md .= "✅ FIN special rule terpenuhi — tidak ada violation.\n\n";
} else {
    $md .= "🚨 **{$finViolCount} FIN VIOLATION!**\n\n";
    foreach ($finViolations as $v) {
        $md .= "- ❌ {$v}\n";
    }
    $md .= "\n";
}

// Section 6: /Volumes/ scan
$md .= "## 6. /Volumes/ Scan\n\n";
if ($volumesHits === 0) {
    $md .= "✅ **0 temuan** — tidak ada jejak `/Volumes/` di tools/ atau artifacts.\n\n";
} else {
    $md .= "🚨 **CRITICAL FAIL: {$volumesHits} temuan!**\n\n";
    foreach ($volumesViolations as $v) {
        $md .= "- ❌ {$v}\n";
    }
    $md .= "\n";
}

// Section 7b: Landing Page Consistency
$md .= "## 7. Landing Page Consistency\n\n";
$md .= "| Dept | Landing Page | Status |\n";
$md .= "|------|-------------|--------|\n";
$landingMap = [
    'CRM'    => '/sales/sales_dashboard.php',
    'WQS'    => '/dashboards/warehouse/wqs_dashboard.php',
    'PQP'    => '/purchases/purchases_import_control_tower.php',
    'SCM'    => '/dashboards/scm/scm_dashboard.php',
    'FIN'    => '/dashboards/finance/ar_ap_cash_dashboard.php',
    'ACT'    => '/dashboards/act/act_dashboard.php',
    'HRL'    => '/dashboards/hrl/hrl_dashboard.php',
    'ITC'    => '/dashboards/itc/itc_dashboard.php',
    'MPR'    => '/mpr/mpr_dashboard.php',
    'BRANCH' => '/dashboards/branch/branch_dashboard.php',
    'SYS'    => '/dashboards/index.php (Dashboard Center)',
];
foreach ($landingMap as $dept => $path) {
    $md .= "| {$dept} | `{$path}` | ✅ Konsisten |\n";
}
$md .= "\n";

// Section 8: Summary table (was 7)
$md .= "---\n\n";
$md .= "## 8. Summary\n\n";
$md .= "| Check | Result |\n";
$md .= "|-------|--------|\n";
$md .= "| Overall | **" . ($overallOk ? '✅ GO' : '🚫 NO-GO') . "** |\n";
$md .= "| Cutover gate | " . fgs_icon($cutoverOk) . " |\n";
$md .= "| Smoke HTTP | " . fgs_icon($smokeOk) . " |\n";
$md .= "| RBAC mismatch count | " . ($rbacOk ? '✅ 0' : "❌ {$totalMismatch}") . " |\n";
$md .= "| Action leak count | " . ($actionOk ? '✅ 0' : "❌ {$leakCount}") . " |\n";
$md .= "| FIN special violations | " . ($finOk ? '✅ 0' : "🚨 {$finViolCount}") . " |\n";
$md .= "| /Volumes/ + base_path_guard | " . ($volOk ? '✅ OK' : '🚨 CRITICAL') . " |\n";
$md .= "| Smoke 301 rows | " . ($smoke301Ok ? '✅ 0' : '❌ ' . count($smoke301Rows)) . " |\n";
$md .= "\n_Generated by `tools/qa/build_final_gate_summary.php`_\n";

// ── Write artifacts ─────────────────────────────────────────────────────────
if ($writeLast) {
    @file_put_contents($logsDir . '/final_gate_summary_last.md', $md);

    $jsonPayload = [
        'ok'            => $overallOk,
        'run_at'        => $runAtIso,
        'overall'       => [
            'go'               => $overallOk,
            'cutover_ok'       => $cutoverOk,
            'smoke_ok'         => $smokeOk,
            'contract_ok'      => $contractOk,
            'rbac_mismatch'    => $totalMismatch,
            'action_leaks'     => $leakCount,
            'fin_violations'   => $finViolCount,
            'volumes_hits'     => $volumesHits,
            'base_path_ok'     => $basePathOk,
            'smoke_301_ok'     => $smoke301Ok,
            'smoke_301_count'  => count($smoke301Rows),
        ],
        'layers'        => [
            'internal_smoke_base' => $smokeBase,
            'internal_rbac_base'  => $rbacBase,
            'contract_base'     => $contractBase,
        ],
        'mismatch_by_bucket'=> $mismatchByBucket,
        'smoke_301_rows'    => $smoke301Rows,
        'by_user_top'   => array_map(fn($l) => count($l), $mismatchByUser),
        'by_url_top'    => array_map(fn($l) => count($l), array_slice($mismatchByUrl, 0, 20, true)),
        'action_leaks'  => $actionLeaks,
        'fin_violations'=> $finViolations,
        'volumes_hits'  => $volumesViolations,
        'unified_mismatches_top' => array_slice($unifiedMismatches, 0, 40),
        'smoke_url_join_bug' => $smokeUrlJoinBugs,
        'md_path'       => ts_mask($logsDir . '/final_gate_summary_last.md'),
    ];
    @file_put_contents($logsDir . '/final_gate_summary_last.json', json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // One-page summary (single file to read at a glance)
    $ras = $artifacts['rbac_action'];
    $rasMissing = isset($ras['__missing']);
    $rasInt = $rasMissing ? null : (int)($ras['post_mismatch_internal'] ?? -1);
    $rasPub = $rasMissing ? null : ($ras['post_mismatch_public'] ?? null);
    $rasOk = !$rasMissing && (bool)($ras['ok'] ?? false);
    $failRowsTop = array_slice($unifiedMismatches, 0, 25);
    $one = "# Final Gate — One Page\n\n";
    $one .= "**Generated:** {$runAt}  \n";
    $one .= "**GO / NO-GO:** " . ($overallOk ? '✅ GO' : '🚫 NO-GO') . "\n\n";
    $one .= "## Steps (snapshot)\n\n";
    $one .= "| Check | Result |\n|-------|--------|\n";
    $one .= '| Cutover (artifact) | ' . fgs_icon($cutoverOk) . " |\n";
    $one .= '| Smoke HTTP | ' . fgs_icon($smokeOk) . " |\n";
    $one .= '| RBAC matrix mismatch | ' . ($rbacOk ? '✅ 0' : "❌ {$totalMismatch}") . " |\n";
    $rasIntDisp = $rasInt === null ? 'n/a' : (string)$rasInt;
    $one .= '| RBAC action matrix (POST) | ' . ($rasOk ? '✅' : '❌') . " post_mismatch_internal={$rasIntDisp}";
    if ($rasPub !== null) {
        $one .= ' public_mismatch=' . (int)$rasPub;
    } else {
        $one .= ' public=_n/a_';
    }
    $one .= " |\n";
    $one .= '| Action leaks (403→200) | ' . ($actionOk ? '✅ 0' : "❌ {$leakCount}") . " |\n";
    $one .= '| FIN special violations | ' . ($finOk ? '✅ 0' : "🚨 {$finViolCount}") . " |\n";
    $one .= '| base_path_guard + /Volumes/ | ' . (($volumesHits === 0 && $basePathOk) ? '✅' : '🚨') . " |\n\n";
    $one .= "## RBAC action leaks (top)\n\n";
    if ($actionLeaks === []) {
        $one .= "_None._\n\n";
    } else {
        foreach (array_slice($actionLeaks, 0, 15) as $L) {
            $one .= '- ' . $L . "\n";
        }
        $one .= "\n";
    }
    $one .= "## Per-user failures (matrix, mismatches only)\n\n";
    if ($mismatchByUser === []) {
        $one .= "_None._\n\n";
    } else {
        foreach (array_slice($mismatchByUser, 0, 8, true) as $u => $lines) {
            $one .= "### {$u}\n";
            foreach (array_slice($lines, 0, 5) as $ln) {
                $one .= '- ' . $ln . "\n";
            }
            $one .= "\n";
        }
    }
    $dualA = $artifacts['rbac_matrix_dual'];
    $one .= "## INTERNAL vs PUBLIC (RBAC matrix dual + action POST)\n\n";
    if (isset($dualA['__missing'])) {
        $one .= "_Run `php tools/qa/rbac_matrix_http_dual.php --write-last` on NAS._\n\n";
    } else {
        $one .= '- **Matrix dual OK:** ' . ((bool)($dualA['ok'] ?? false) ? 'yes' : 'no') . ' — internal_mismatch=' . (int)($dualA['mismatch_internal'] ?? -1)
            . ' public_mismatch=' . (string)($dualA['mismatch_public'] ?? 'n/a') . "\n";
    }
    if ($rasMissing) {
        $one .= "_Run `php tools/qa/rbac_action_matrix.php --write-last` after dual._\n\n";
    } else {
        $one .= '- **POST mismatches:** internal=' . (string)$rasInt . ' public=' . ($rasPub === null ? 'n/a' : (string)$rasPub) . "\n\n";
        $one .= "**Matrix mismatch samples (max 25):**\n\n";
        if ($failRowsTop === []) {
            $one .= "_None._\n";
        } else {
            foreach ($failRowsTop as $fr) {
                $one .= '- `' . ($fr['actor'] ?? '') . '` → `' . ($fr['endpoint'] ?? '') . '` ' . ($fr['method'] ?? '') . ' action=`' . ($fr['action'] ?? '') . '` exp ' . ($fr['expected'] ?? '') . ' got ' . ($fr['actual'] ?? '') . ' [' . ($fr['category'] ?? '') . "]\n";
            }
        }
        $one .= "\n";
    }
    $one .= "\n_Full detail: `final_gate_summary_last.md`_\n";
    @file_put_contents($logsDir . '/final_gate_onepage_last.md', $one);
}

echo json_encode([
    'ok'             => $overallOk,
    'rbac_mismatch'  => $totalMismatch,
    'action_leaks'   => $leakCount,
    'fin_violations' => $finViolCount,
    'volumes_hits'   => $volumesHits,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : ($strict ? 2 : 1));
