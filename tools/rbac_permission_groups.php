<?php
declare(strict_types=1);

/**
 * RBAC Permission Grouping Report
 * - Sumber: config/rbac_permissions.php
 * - Tujuan: memudahkan pengelompokan VIEW vs non-VIEW untuk pengaturan RBAC
 *
 * Usage (CLI):
 *   php tools/rbac_permission_groups.php
 *   php tools/rbac_permission_groups.php --format=json
 *   php tools/rbac_permission_groups.php --format=md
 *
 * Usage (WEB):
 *   /tools/rbac_permission_groups.php?format=json
 *   /tools/rbac_permission_groups.php?format=md
 */

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$isCli = (PHP_SAPI === 'cli');

if ($isCli) {
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) {
        rmi_env_load();
    }
} else {
    require_once $root . '/master/auth.php';
    require_login();
    if (function_exists('require_any_permission')) {
        require_any_permission(['SYSTEM.RBAC_MANAGE', 'TOOLS.VIEW']);
    } else {
        require_role(['SYS', 'SUPERADMIN', 'ADMIN']);
    }
}

require_once $root . '/tools/tools_state_lib.php';

if (!function_exists('rpg_h')) {
    function rpg_h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rpg_category')) {
    function rpg_category(string $code): string
    {
        $c = strtoupper(trim($code));
        if ($c === '') {
            return 'OTHER';
        }
        if (str_ends_with($c, '.VIEW') || str_ends_with($c, '_VIEW') || str_contains($c, '.VIEW_')) {
            return 'VIEW';
        }
        if (str_ends_with($c, '.CRUD') || str_ends_with($c, '_CRUD') || str_contains($c, '.CRUD_')) return 'CRUD';
        if (str_ends_with($c, '.CREATE') || str_ends_with($c, '_CREATE') || str_contains($c, '.CREATE_')) return 'CREATE';
        if (str_ends_with($c, '.EDIT') || str_ends_with($c, '_EDIT') || str_contains($c, '.EDIT_')) return 'EDIT';
        if (str_ends_with($c, '.DELETE') || str_ends_with($c, '_DELETE') || str_contains($c, '.DELETE_')) return 'DELETE';
        if (str_ends_with($c, '.APPROVE') || str_ends_with($c, '_APPROVE') || str_contains($c, '.APPROVE_')) return 'APPROVE';
        if (str_ends_with($c, '.IMPORT') || str_ends_with($c, '_IMPORT') || str_contains($c, '.IMPORT_')) return 'IMPORT';
        if (str_ends_with($c, '.EXPORT') || str_ends_with($c, '_EXPORT') || str_contains($c, '.EXPORT_')) return 'EXPORT';
        if (str_starts_with($c, 'API.') || str_contains($c, '.API_') || str_ends_with($c, '.API')) return 'API';
        if (str_starts_with($c, 'TOOLS.RUN.') || str_contains($c, '.RUN_') || str_ends_with($c, '.RUN')) return 'RUN';
        if (str_contains($c, '.TASK') || str_contains($c, '_TASK')) return 'TASK';
        if (str_contains($c, '.SETTINGS') || str_contains($c, '.CONFIG')) return 'SETTINGS';
        if (str_contains($c, '.ADMIN') || str_contains($c, '_ADMIN')) return 'ADMIN';
        return 'OTHER';
    }
}

$configPath = $root . '/config/rbac_permissions.php';
if (!is_file($configPath)) {
    $msg = 'config/rbac_permissions.php tidak ditemukan.';
    if ($isCli) {
        fwrite(STDERR, "ERROR: {$msg}\n");
    } else {
        http_response_code(500);
        echo rpg_h($msg);
    }
    exit(1);
}

$rows = require $configPath;
if (!is_array($rows)) {
    $msg = 'Format config/rbac_permissions.php tidak valid (harus array).';
    if ($isCli) {
        fwrite(STDERR, "ERROR: {$msg}\n");
    } else {
        http_response_code(500);
        echo rpg_h($msg);
    }
    exit(1);
}

$modules = [];
$categoryTotals = [];
$total = 0;

foreach ($rows as $r) {
    $code = strtoupper(trim((string)($r[0] ?? '')));
    if ($code === '') continue;
    $name = trim((string)($r[1] ?? ''));
    $module = strtoupper(trim((string)($r[2] ?? '')));
    $desc = trim((string)($r[3] ?? ''));
    if ($module === '') {
        $module = explode('.', $code, 2)[0] ?: 'UNKNOWN';
    }
    $cat = rpg_category($code);

    if (!isset($modules[$module])) {
        $modules[$module] = [
            'counts' => [],
            'items' => [],
        ];
    }
    $modules[$module]['counts'][$cat] = (int)($modules[$module]['counts'][$cat] ?? 0) + 1;
    $modules[$module]['items'][$cat][] = [
        'code' => $code,
        'name' => $name,
        'description' => $desc,
    ];

    $categoryTotals[$cat] = (int)($categoryTotals[$cat] ?? 0) + 1;
    $total++;
}

ksort($modules);
ksort($categoryTotals);
foreach ($modules as $m => $data) {
    ksort($data['counts']);
    ksort($data['items']);
    foreach ($data['items'] as $cat => $items) {
        usort($items, static fn(array $a, array $b): int => strcmp((string)$a['code'], (string)$b['code']));
        $modules[$m]['items'][$cat] = $items;
    }
}

$payload = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'source' => '[APP_ROOT]/config/rbac_permissions.php',
    'total_permissions' => $total,
    'category_totals' => $categoryTotals,
    'modules' => $modules,
];

$logsDir = ts_storage_logs_dir();
$jsonPath = $logsDir . '/rbac_permission_groups.last.json';
$mdPath = $logsDir . '/rbac_permission_groups.last.md';
ts_write_json($jsonPath, $payload);

$md = [];
$md[] = '# RBAC Permission Groups';
$md[] = '';
$md[] = '- Run at: ' . $payload['run_at'];
$md[] = '- Source: `config/rbac_permissions.php`';
$md[] = '- Total permissions: ' . $total;
$md[] = '';
$md[] = '## Category Totals';
foreach ($categoryTotals as $cat => $n) {
    $md[] = '- ' . $cat . ': ' . $n;
}
$md[] = '';
$md[] = '## Per Module Summary';
foreach ($modules as $module => $data) {
    $parts = [];
    foreach (($data['counts'] ?? []) as $cat => $n) {
        $parts[] = $cat . '=' . $n;
    }
    $md[] = '- ' . $module . ': ' . implode(', ', $parts);
}
$md[] = '';
$md[] = '## Detail by Module & Category';
foreach ($modules as $module => $data) {
    $md[] = '';
    $md[] = '### ' . $module;
    foreach (($data['items'] ?? []) as $cat => $items) {
        $md[] = '- **' . $cat . '** (' . count($items) . ')';
        foreach ($items as $it) {
            $line = '  - `' . (string)$it['code'] . '`';
            if ((string)$it['name'] !== '') {
                $line .= ' — ' . (string)$it['name'];
            }
            $md[] = $line;
        }
    }
}
@file_put_contents($mdPath, implode("\n", $md) . "\n");

ts_append_run_history('rbac_permission_groups', 'OK', [
    'source' => 'tools/rbac_permission_groups.php',
    'total_permissions' => $total,
    'modules' => count($modules),
]);

$format = 'summary';
if ($isCli) {
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
        if (is_string($arg) && str_starts_with($arg, '--format=')) {
            $format = strtolower(trim(substr($arg, 9)));
        }
    }
} else {
    $format = strtolower(trim((string)($_GET['format'] ?? 'summary')));
}

if ($format === 'json') {
    if (!$isCli) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($format === 'md') {
    if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", $md) . PHP_EOL;
    exit(0);
}

if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "RBAC permission grouping generated.\n";
echo "Total permissions: {$total}\n";
echo "Modules: " . count($modules) . "\n";
echo "Artifacts:\n";
echo "- [APP_ROOT]/storage/logs/rbac_permission_groups.last.json\n";
echo "- [APP_ROOT]/storage/logs/rbac_permission_groups.last.md\n";
echo "\nCategory totals:\n";
foreach ($categoryTotals as $cat => $n) {
    echo "- {$cat}: {$n}\n";
}

