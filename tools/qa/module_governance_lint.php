<?php
/**
 * module_governance_lint.php — Cek modul baru punya entry di docs + RBAC + smoke + audit.
 *
 * Usage: php tools/qa/module_governance_lint.php [--write-last] [--strict]
 * Artifact: storage/logs/module_governance_lint_last.json
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

$errors = [];
$warnings = [];

// 1) ERP_MENU_WORKFLOW_REFERENCE.md exists
$menuDoc = $root . '/docs/ERP_MENU_WORKFLOW_REFERENCE.md';
if (!is_file($menuDoc)) {
    $errors[] = 'ERP_MENU_WORKFLOW_REFERENCE.md not found';
} else {
    $menuContent = (string)file_get_contents($menuDoc);
    if (strpos($menuContent, '## ') === false) {
        $warnings[] = 'ERP_MENU_WORKFLOW_REFERENCE.md seems empty or malformed';
    }
}

// 2) RBAC_MATRIX_RMI_v1.md exists
$rbacMatrixPath = $root . '/docs/governance/RBAC_MATRIX_RMI_v1.md';
if (!is_file($rbacMatrixPath)) {
    $rbacMatrixPath = $root . '/docs/governance/RBAC_MATRIX_RMI.md';
}
if (!is_file($rbacMatrixPath)) {
    $errors[] = 'RBAC_MATRIX_RMI_v1.md (or RBAC_MATRIX_RMI.md) not found';
}

// 3) RBAC_ALL_MODULES_V1.md exists
$rbacAll = $root . '/docs/governance/RBAC_ALL_MODULES_V1.md';
if (!is_file($rbacAll)) {
    $errors[] = 'RBAC_ALL_MODULES_V1.md not found';
}

// 4) rbac_matrix_http_check has matrix entries
$matrixCheck = $root . '/tools/qa/rbac_matrix_http_check.php';
if (is_file($matrixCheck)) {
    $mcContent = (string)file_get_contents($matrixCheck);
    if (strpos($mcContent, "'path'") === false && strpos($mcContent, '"path"') === false) {
        $warnings[] = 'rbac_matrix_http_check.php may have empty matrix';
    }
}

// 5) erp_audit / master_audit used in codebase
$auditFiles = array_merge(
    glob($root . '/purchases/*.php') ?: [],
    glob($root . '/sales/*.php') ?: [],
    glob($root . '/stock/*.php') ?: []
);
$hasAudit = false;
foreach (array_slice($auditFiles, 0, 20) as $f) {
    if (strpos((string)@file_get_contents($f), 'erp_audit') !== false || strpos((string)@file_get_contents($f), 'master_audit') !== false) {
        $hasAudit = true;
        break;
    }
}
if (!$hasAudit && count($auditFiles) > 0) {
    $warnings[] = 'Some module files may lack erp_audit/master_audit (sampling)';
}

$ok = count($errors) === 0;
$result = [
    'ok' => $ok,
    'run_at' => date(DateTimeInterface::ATOM),
    'errors' => $errors,
    'warnings' => $warnings,
    'checks' => [
        'menu_doc' => is_file($menuDoc),
        'rbac_matrix' => is_file($rbacMatrixPath),
        'rbac_all' => is_file($rbacAll),
        'smoke_matrix' => is_file($matrixCheck),
    ],
];

if ($writeLast) {
    $logsDir = ts_storage_logs_dir();
    @file_put_contents($logsDir . '/module_governance_lint_last.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
