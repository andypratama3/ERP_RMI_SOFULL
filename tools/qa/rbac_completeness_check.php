<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);
require_once $root . '/_shared/db.php';
require_once $root . '/_shared/rbac.php';

$args = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);
$invPath = $root . '/storage/logs/rbac_pages_inventory.json';

$critical = [];
$warn = [];

$forbiddenMount = '/' . 'Volumes' . '/';

if (!is_file($invPath)) {
    $cmd = 'php ' . escapeshellarg($root . '/tools/rbac/build_pages_inventory.php');
    @exec($cmd . ' 2>&1', $_out, $code);
    if ($code !== 0 || !is_file($invPath)) {
        $critical[] = ['code' => 'INVENTORY_MISSING', 'message' => 'Cannot build rbac_pages_inventory.json'];
    }
}

$inventory = ['items' => []];
if (is_file($invPath)) {
    $raw = (string)file_get_contents($invPath);
    $dec = json_decode($raw, true);
    if (is_array($dec)) $inventory = $dec;
}

$items = (array)($inventory['items'] ?? []);
foreach ($items as $it) {
    if (empty($it['exists']) || empty($it['resolved_file'])) {
        $critical[] = ['code' => 'ROUTE_MISSING_FILE', 'message' => 'Route points to missing file', 'meta' => $it];
        continue;
    }
    $rel = (string)$it['resolved_file'];
    $abs = $root . '/' . $rel;
    $content = @file_get_contents($abs);
    if (!is_string($content)) continue;
    $hasGuard = str_contains($content, 'require_login(')
        || str_contains($content, 'require_rbac(')
        || str_contains($content, 'rmi_require_module(')
        || str_contains($content, 'require_any_permission(')
        || str_contains($content, 'rbac_require(');
    if (!$hasGuard) {
        $warn[] = ['code' => 'MISSING_GUARD', 'file' => $rel];
    }
}

// Permission references must exist in DB registry.
$refPerms = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $f) {
    if (!$f->isFile() || !str_ends_with($f->getFilename(), '.php')) continue;
    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
    if (str_starts_with($rel, 'vendor/') || str_starts_with($rel, 'exports/')) continue;
    $c = (string)@file_get_contents($f->getPathname());
    if ($c === '') continue;
    if (preg_match_all('/[\'"]([A-Z][A-Z0-9_]*\.[A-Z0-9_]+)[\'"]/', $c, $m)) {
        foreach ($m[1] as $p) $refPerms[strtoupper((string)$p)] = true;
    }
}

$dbPerms = [];
try {
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
    $st = $pdo->query("SELECT perm_code FROM rbac_permissions");
    foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $r) {
        $pc = strtoupper((string)($r['perm_code'] ?? ''));
        if ($pc !== '') $dbPerms[$pc] = true;
    }
} catch (Throwable $e) {
    $critical[] = ['code' => 'DB_PERMISSION_CHECK_FAILED', 'message' => $e->getMessage()];
}

foreach (array_keys($refPerms) as $p) {
    if (!isset($dbPerms[$p])) {
        $warn[] = ['code' => 'PERMISSION_NOT_IN_DB', 'perm' => $p];
    }
}

// SYS-only pages policy check in rbac_policy.php
$policyPath = $root . '/_shared/rbac_policy.php';
if (!is_file($policyPath)) {
    $critical[] = ['code' => 'RBAC_POLICY_MISSING', 'message' => '_shared/rbac_policy.php not found'];
} else {
    require_once $policyPath;
    $cfg = function_exists('rmi_rbac_policy_config') ? rmi_rbac_policy_config() : null;
    $rules = is_array($cfg) ? (array)($cfg['rules'] ?? []) : [];
    $sysOnlyRoutes = ['/master/master_system_login.php', '/master/nav_manager.php'];
    foreach ($sysOnlyRoutes as $r) {
        $ok = false;
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            if (($rule['route'] ?? '') === $r) {
                $depts = array_map('strtoupper', (array)($rule['depts'] ?? []));
                $levels = array_map('strtoupper', (array)($rule['levels'] ?? []));
                if ($depts === ['SYS'] && $levels === ['SYS']) {
                    $ok = true;
                }
            }
        }
        if (!$ok) {
            $critical[] = ['code' => 'SYS_ONLY_POLICY_MISSING', 'route' => $r];
        }
    }
    $rbacIndexOk = false;
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        if (($rule['route'] ?? '') !== '/rbac/index.php') {
            continue;
        }
        $depts = array_map('strtoupper', (array)($rule['depts'] ?? []));
        $levels = array_map('strtoupper', (array)($rule['levels'] ?? []));
        if (in_array('ALL', $depts, true)
            && in_array('STAFF', $levels, true) && in_array('MANAGER', $levels, true) && in_array('SYS', $levels, true)) {
            $rbacIndexOk = true;
        }
        break;
    }
    if (!$rbacIndexOk) {
        $warn[] = ['code' => 'RBAC_ROUTE_POLICY_MISMATCH', 'message' => '/rbac/index.php harus allow depts ALL + levels STAFF,MANAGER,SYS (izin halaman = RBAC_MANAGE/RBAC_VIEW di app)'];
    }
}

// Repo forbidden mount token check.
// Scan file PHP/MD/SH/JSON di repo untuk deteksi mount token yang tidak sengaja (lihat $forbiddenMount).
// Direktori/file yang dikecualikan = yang memang MEMBAHAS atau MENSCAN forbidden token secara legitimate.
$repoViolation = 0;
$itRepo = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
    RecursiveIteratorIterator::SELF_FIRST
);
// Dirname prefixes yang dikecualikan — folder yang secara legitimate menyebut path Mac mount
// (lihat docs/SETUP_TIM_EDIT_TERLIHAT.md, .cursor/rules/volume4-paths.mdc, dll.)
$excludeDirPrefixes = [
    'vendor/', 'node_modules/', 'exports/', 'android_app/',
    'docs/',           // Dokumentasi setup path NAS vs Mac
    '.cursor/',        // Workspace rules & agent transcripts (konfigurasi editor)
    'storage/',        // Artifact logs & backups — bukan source code
    'agent-transcripts/', // Agent conversation transcripts (di luar project)
    // Tool infrastructure subdirectories (bukan business code)
    // — file-file ini secara legitimate menyebut path Mac/NAS dalam komentar/docs
    'tools/hardening/', 'tools/rfc/', 'tools/release/', 'tools/audit/',
    'tools/compliance/', 'tools/dev/', 'tools/doctor/', 'tools/rbac/',
    'tools/nas/',      // Bash scripts yang explicitly membahas path mounting
    'tools/ops/',      // Ops scripts yang handle both Mac dan NAS environments
    'tools/qa/',       // QA scripts (run_cutover, volumes_guard, e2e, audit_live, dll.)
    'tools/_shared/',  // Shared tool utilities (url_utils, workspace_lock, etc.)
    'tools/_lib/',     // Tool library files (bootstrap, http helpers, etc.)
];

// Root-level markdown/text files yang mendokumentasikan setup path Mac↔NAS
$excludeRootFiles = [
    'ASSUMPTIONS_LOG.md', 'OPS_FINAL_STATUS.md', 'README.md',
    'CUTOVER_ONE_PAGER.md', 'DEPLOY_PACKAGE_README.md',
    'ONBOARDING.md', 'RUNBOOK.md',
];
// Basename file tools yang dikecualikan (guard/audit files yang scan untuk forbidden mount token)
// Catatan: tools/qa/ sudah di-exclude via excludeDirPrefixes; ini fallback untuk file di luar tools/qa
$excludeToolFiles = [
    'path_guard.php', 'path_police.php', 'volumes_police.php', 'volumes_guard.php',
    'repo_location_guard.php', 'repo_location_audit.php',
    'run_cutover_checks.php', 'run_gate_dual.php', 'run_final_gate_dual.php',
    'audit_live.php', 'audit_realtime_rmi.php', 'build_final_gate_summary.php',
    'testsprite_gate.php', 'testsprite_regression.php',
    'cek_workspace_path.php', 'refresh_control_center.php', 'reset_for_golive.php',
    'gate_artifacts_sync.php', 'monitoring_heartbeat.php', 'menu_dashboard_sync.php',
    'erp.sh', 'assert_app_root.sh', 'base_path_guard.php',
    'e2e_trial_full.sh', 'audit_live_lib.php',
];
foreach ($itRepo as $f) {
    if (!$f->isFile()) continue;
    $ext = strtolower((string)pathinfo($f->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, ['php','md','json','jsonl','sh','yml','yaml','env','ini','mdc'], true)) continue;
    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
    // Skip excluded directories
    $skipDir = false;
    foreach ($excludeDirPrefixes as $pfx) {
        if (str_starts_with($rel, $pfx)) { $skipDir = true; break; }
    }
    if ($skipDir) continue;
    // Skip excluded tool files (anywhere in path)
    if (in_array(basename($rel), $excludeToolFiles, true)) continue;
    // Skip root-level documentation files
    if (!str_contains($rel, '/') && in_array($rel, $excludeRootFiles, true)) continue;
    $c = (string)@file_get_contents($f->getPathname());
    if ($c !== '' && strpos($c, $forbiddenMount) !== false) $repoViolation++;
}
if ($repoViolation > 0) {
    $critical[] = ['code' => 'FORBIDDEN_MOUNT_TOKEN_FOUND', 'count' => $repoViolation];
}

$payload = [
    'run_at' => date(DateTimeInterface::ATOM),
    'ok' => count($critical) === 0,
    'strict' => $strict,
    'inventory_total' => count($items),
    'critical_count' => count($critical),
    'warn_count' => count($warn),
    'critical_findings' => $critical,
    'warn_findings' => $warn,
];

$out = $root . '/storage/logs/rbac_completeness_check_last.json';
if ($writeLast) {
    @mkdir(dirname($out), 0775, true);
    @file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($strict) {
    exit(count($critical) === 0 ? 0 : 1);
}
exit(0);
