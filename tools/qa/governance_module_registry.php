<?php
/**
 * governance_module_registry.php — Gate 8: Governance Module Registry
 *
 * Memastikan tidak ada modul "liar" — setiap modul yang terdaftar di repo
 * harus punya entry di:
 *   - docs/governance/module_registry.json
 *   - ERP_MENU_WORKFLOW_REFERENCE.md (entry URL)
 *   - RBAC_ALL_MODULES_V1.md (permission codes)
 *   - RBAC_MATRIX_RMI_v1.md (dept access matrix)
 *
 * Usage: php tools/qa/governance_module_registry.php [--strict] [--write-last]
 * Artifact: storage/logs/governance_module_registry_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/tools_state_lib.php';
$logsDir = ts_storage_logs_dir();
$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');

$errors = []; $warnings = []; $modules = []; $unregistered = [];

// ── 1. Load module_registry.json ────────────────────────────────────────
$registryFile = $root . '/docs/governance/module_registry.json';
if (!is_file($registryFile)) {
    $errors[] = 'module_registry.json:not_found';
} else {
    $registry = json_decode((string)file_get_contents($registryFile), true);
    if (!is_array($registry) || empty($registry['modules'])) {
        $errors[] = 'module_registry.json:invalid_or_empty';
    } else {
        $modules = (array)$registry['modules'];
    }
}

// ── 2. Check source of truth documents exist ─────────────────────────────
$sotDocs = [
    'ERP_MENU_WORKFLOW_REFERENCE.md'    => $root . '/docs/ERP_MENU_WORKFLOW_REFERENCE.md',
    'RBAC_ALL_MODULES_V1.md'            => $root . '/docs/governance/RBAC_ALL_MODULES_V1.md',
    'RBAC_MATRIX_RMI_v1.md'            => $root . '/docs/governance/RBAC_MATRIX_RMI_v1.md',
];
$sotStatus = [];
foreach ($sotDocs as $name => $path) {
    $exists = is_file($path);
    $sotStatus[$name] = $exists;
    if (!$exists) $errors[] = "source_of_truth:{$name}:not_found";
}

// ── 3. Validate each registered module ──────────────────────────────────
$menuContent  = is_file($sotDocs['ERP_MENU_WORKFLOW_REFERENCE.md'])  ? (string)file_get_contents($sotDocs['ERP_MENU_WORKFLOW_REFERENCE.md'])  : '';
$rbacContent  = is_file($sotDocs['RBAC_ALL_MODULES_V1.md'])          ? (string)file_get_contents($sotDocs['RBAC_ALL_MODULES_V1.md'])          : '';
$matrixContent= is_file($sotDocs['RBAC_MATRIX_RMI_v1.md'])           ? (string)file_get_contents($sotDocs['RBAC_MATRIX_RMI_v1.md'])           : '';

$moduleResults = [];
foreach ($modules as $mod) {
    $mid    = (string)($mod['module_id'] ?? 'UNKNOWN');
    $mErr   = []; $mWarn = [];

    // Check required fields
    foreach (['module_id','name','entry_dir','owner_dept','permissions'] as $f) {
        if (empty($mod[$f])) $mErr[] = "missing:{$f}";
    }

    // Entry dir exists
    $entryDir = (string)($mod['entry_dir'] ?? '');
    if ($entryDir && !is_dir($root . '/' . $entryDir)) {
        $mErr[] = "entry_dir_not_found:{$entryDir}";
        $unregistered[] = $mid;
    }

    // Permission codes present in RBAC_ALL_MODULES_V1.md (sampling)
    if (!empty($mod['permissions']) && is_array($mod['permissions']) && $rbacContent !== '') {
        $perms = (array)$mod['permissions'];
        $firstPerm = $perms[0] ?? '';
        $permBase  = explode('.', $firstPerm)[0] ?? $mid;
        if (!str_contains($rbacContent, $permBase)) {
            $mWarn[] = "permission_not_in_rbac_dict:{$firstPerm}";
        }
    }

    // No panduan yet — warn only
    if (empty($mod['panduan']) || (!empty($mod['panduan']) && !is_file($root . '/' . $mod['panduan']))) {
        $mWarn[] = 'panduan_missing';
    }

    $moduleResults[$mid] = [
        'ok'       => count($mErr) === 0,
        'errors'   => $mErr,
        'warnings' => $mWarn,
    ];
    foreach ($mErr as $e) $errors[] = "{$mid}:{$e}";
    foreach ($mWarn as $w) $warnings[] = "{$mid}:{$w}";
}

// ── 4. Delegate to module_governance_lint for additional checks ───────────
$lintJson = $logsDir . '/module_governance_lint_last.json';
if (!is_file($lintJson)) {
    @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/module_governance_lint.php') . ' --write-last 2>&1');
}
$lintOk = false;
if (is_file($lintJson)) {
    $ld = json_decode((string)file_get_contents($lintJson), true);
    $lintOk = is_array($ld) && (bool)($ld['ok'] ?? false);
    if (!$lintOk) $warnings[] = 'module_governance_lint:non_ok';
}

$ok = count($errors) === 0;

$payload = [
    'ok'              => $ok,
    'run_at'          => date(DateTimeInterface::ATOM),
    'registry_file'   => ts_mask($registryFile),
    'module_count'    => count($modules),
    'error_count'     => count($errors),
    'warning_count'   => count($warnings),
    'unregistered'    => $unregistered,
    'sot_docs'        => $sotStatus,
    'modules'         => $moduleResults,
    'lint_ok'         => $lintOk,
    'errors'          => $errors,
    'warnings'        => $warnings,
];

if ($writeLast) {
    @file_put_contents($logsDir . '/governance_module_registry_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Alias for governance_module_guard_last.json
    @file_put_contents($logsDir . '/governance_module_guard_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['ok' => $ok, 'module_count' => count($modules), 'error_count' => count($errors), 'warning_count' => count($warnings)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
