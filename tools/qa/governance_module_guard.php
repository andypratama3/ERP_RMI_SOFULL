<?php
/**
 * governance_module_guard.php — Gate L: Governance Modul Baru
 *
 * Checks:
 *   - module_registry.json exists & valid
 *   - Setiap modul di registry punya: owner_dept, entry_pages, permissions, audit_actions
 *   - Setiap entry_dir ada di filesystem
 *   - Modul dengan smoke_coverage=false diberi warning
 *   - Modul tanpa panduan diberi warning
 *
 * Usage: php tools/qa/governance_module_guard.php [--strict] [--write-last]
 * Artifact: storage/logs/governance_module_guard_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/tools_state_lib.php';
$logsDir = ts_storage_logs_dir();

$errors   = [];
$warnings = [];
$modules  = [];

// ── 1. Load module_registry.json
$registryFile = $root . '/docs/governance/module_registry.json';
if (!is_file($registryFile)) {
    $errors[] = 'module_registry.json:not_found at docs/governance/module_registry.json';
    $payload = [
        'ok'       => false,
        'run_at'   => date(DateTimeInterface::ATOM),
        'errors'   => $errors,
        'warnings' => $warnings,
        'modules'  => [],
    ];
    if ($writeLast) @file_put_contents($logsDir . '/governance_module_guard_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($strict ? 2 : 1);
}

$registry = json_decode((string)file_get_contents($registryFile), true);
if (!is_array($registry) || empty($registry['modules'])) {
    $errors[] = 'module_registry.json:invalid_json_or_empty';
} else {
    $rawModules = (array)$registry['modules'];

    foreach ($rawModules as $mod) {
        $mid    = (string)($mod['module_id'] ?? 'UNKNOWN');
        $modErr = [];
        $modWrn = [];

        // Required fields
        $requiredFields = ['module_id', 'name', 'entry_dir', 'owner_dept', 'entry_pages', 'permissions', 'audit_actions'];
        foreach ($requiredFields as $f) {
            if (!array_key_exists($f, $mod) || $mod[$f] === null || $mod[$f] === '') {
                $modErr[] = "missing_field:{$f}";
            }
        }

        // entry_dir exists in filesystem
        $entryDir = (string)($mod['entry_dir'] ?? '');
        if ($entryDir !== '' && !is_dir($root . '/' . $entryDir)) {
            $modErr[] = "entry_dir_not_found:{$entryDir}";
        }

        // entry_pages: at least 1
        if (empty($mod['entry_pages'])) {
            $modErr[] = 'no_entry_pages';
        }

        // permissions: at least 1
        if (empty($mod['permissions'])) {
            $modWrn[] = 'no_permissions_listed';
        }

        // audit_actions: warning if empty for transactional modules
        if (empty($mod['audit_actions']) && !in_array($mid, ['CHAT','RBAC'], true)) {
            $modWrn[] = 'no_audit_actions_listed';
        }

        // smoke_coverage
        if (empty($mod['smoke_coverage'])) {
            $modWrn[] = 'smoke_coverage_missing';
        }

        // panduan
        if (empty($mod['panduan'])) {
            $modWrn[] = 'no_panduan_php';
        } elseif (!is_file($root . '/' . $mod['panduan'])) {
            $modWrn[] = 'panduan_file_not_found:' . $mod['panduan'];
        }

        // rbac_matrix_entry
        if (empty($mod['rbac_matrix_entry'])) {
            $modWrn[] = 'not_in_rbac_matrix';
        }

        $modules[$mid] = [
            'ok'       => count($modErr) === 0,
            'name'     => (string)($mod['name'] ?? $mid),
            'entry_dir'=> $entryDir,
            'errors'   => $modErr,
            'warnings' => $modWrn,
        ];

        foreach ($modErr as $e) $errors[] = "{$mid}:{$e}";
        foreach ($modWrn as $w) $warnings[] = "{$mid}:{$w}";
    }
}

// ── 2. Also run module_governance_lint for cross-check
$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
$lintCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/module_governance_lint.php') . ' --write-last 2>&1';
@exec($lintCmd, $_, $lintCode);
$lintOk = ((int)$lintCode === 0);
if (!$lintOk) $warnings[] = 'module_governance_lint:non_zero_exit';

$ok = count($errors) === 0;

$payload = [
    'ok'           => $ok,
    'run_at'       => date(DateTimeInterface::ATOM),
    'registry_file'=> ts_mask($registryFile),
    'module_count' => count($modules),
    'error_count'  => count($errors),
    'warning_count'=> count($warnings),
    'modules'      => $modules,
    'errors'       => $errors,
    'warnings'     => $warnings,
    'lint_ok'      => $lintOk,
];

if ($writeLast) {
    @file_put_contents($logsDir . '/governance_module_guard_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['ok' => $ok, 'module_count' => count($modules), 'error_count' => count($errors), 'warning_count' => count($warnings)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
