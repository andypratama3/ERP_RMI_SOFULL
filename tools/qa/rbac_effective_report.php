<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_state_lib.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$root = dirname(__DIR__, 2);
$pdo = function_exists('auth_pdo') ? auth_pdo() : (function_exists('rmi_db_pdo') ? rmi_db_pdo() : null);
if (!$pdo instanceof PDO) {
    echo json_encode(['ok' => false, 'message' => 'db_unavailable'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$summary = [
    'state_version' => 1,
    'checked_at' => date(DateTimeInterface::ATOM),
    'by_dept_role' => [],
    'top_permissions' => [],
];

try {
    $rows = $pdo->query("
        SELECT dept_code, role_code, COUNT(*) AS perm_count
        FROM rbac_dept_role_permissions
        WHERE allow_flag = 1
        GROUP BY dept_code, role_code
        ORDER BY dept_code, role_code
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $summary['by_dept_role'][] = [
            'dept_code' => (string)($r['dept_code'] ?? ''),
            'role_code' => (string)($r['role_code'] ?? ''),
            'perm_count' => (int)($r['perm_count'] ?? 0),
        ];
    }

    $top = $pdo->query("
        SELECT perm_code, COUNT(*) AS assigned_count
        FROM rbac_dept_role_permissions
        WHERE allow_flag = 1
        GROUP BY perm_code
        ORDER BY assigned_count DESC, perm_code ASC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($top as $r) {
        $summary['top_permissions'][] = [
            'perm_code' => (string)($r['perm_code'] ?? ''),
            'assigned_count' => (int)($r['assigned_count'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'rbac_query_failed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$jsonPath = $root . '/storage/logs/rbac_effective_permissions.last.json';
@file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$md = [];
$md[] = '# RBAC Effective Permission Report';
$md[] = '';
$md[] = '- Checked at: ' . $summary['checked_at'];
$md[] = '- Dept/role pairs: ' . count($summary['by_dept_role']);
$md[] = '- Top permissions listed: ' . count($summary['top_permissions']);
$md[] = '';
$md[] = '## Dept Role Summary';
foreach ($summary['by_dept_role'] as $r) {
    $md[] = '- ' . $r['dept_code'] . ' / ' . $r['role_code'] . ': ' . $r['perm_count'] . ' perms';
}
$md[] = '';
$md[] = '## Top Permissions';
foreach ($summary['top_permissions'] as $r) {
    $md[] = '- ' . $r['perm_code'] . ': ' . $r['assigned_count'];
}
@file_put_contents($root . '/storage/logs/rbac_effective_permissions.last.md', implode("\n", $md) . "\n");

ts_append_run_history('rbac_effective_report', 'OK', [
    'module' => 'tools.qa',
    'action' => 'rbac_effective_report',
    'result' => 'OK',
    'rows' => count($summary['by_dept_role']),
]);

echo json_encode(['ok' => true, 'path' => '[APP_ROOT]/storage/logs/rbac_effective_permissions.last.json'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
