<?php
declare(strict_types=1);

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

if (!function_exists('rpd_h')) {
    function rpd_h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rpd_category')) {
    function rpd_category(string $code): string
    {
        $c = strtoupper(trim($code));
        if ($c === '') return 'OTHER';
        if (str_ends_with($c, '.VIEW') || str_ends_with($c, '_VIEW') || str_contains($c, '.VIEW_')) return 'VIEW';
        if (str_ends_with($c, '.CRUD') || str_ends_with($c, '_CRUD') || str_contains($c, '.CRUD_')) return 'CRUD';
        if (str_ends_with($c, '.CREATE') || str_ends_with($c, '_CREATE') || str_contains($c, '.CREATE_')) return 'CREATE';
        if (str_ends_with($c, '.EDIT') || str_ends_with($c, '_EDIT') || str_contains($c, '.EDIT_')) return 'EDIT';
        if (str_ends_with($c, '.DELETE') || str_ends_with($c, '_DELETE') || str_contains($c, '.DELETE_')) return 'DELETE';
        if (str_ends_with($c, '.APPROVE') || str_ends_with($c, '_APPROVE') || str_contains($c, '.APPROVE_')) return 'APPROVE';
        if (str_ends_with($c, '.IMPORT') || str_ends_with($c, '_IMPORT') || str_contains($c, '.IMPORT_')) return 'IMPORT';
        if (str_ends_with($c, '.EXPORT') || str_ends_with($c, '_EXPORT') || str_contains($c, '.EXPORT_')) return 'EXPORT';
        if (str_starts_with($c, 'TOOLS.RUN.') || str_contains($c, '.RUN_') || str_ends_with($c, '.RUN')) return 'RUN';
        if (str_contains($c, '.TASK') || str_contains($c, '_TASK')) return 'TASK';
        if (str_starts_with($c, 'API.') || str_contains($c, '.API_') || str_ends_with($c, '.API')) return 'API';
        if (str_contains($c, '.SETTINGS') || str_contains($c, '.CONFIG')) return 'SETTINGS';
        if (str_contains($c, '.ADMIN') || str_contains($c, '_ADMIN')) return 'ADMIN';
        return 'OTHER';
    }
}

$categories = ['VIEW', 'CRUD', 'CREATE', 'EDIT', 'DELETE', 'APPROVE', 'IMPORT', 'EXPORT', 'RUN', 'TASK', 'API', 'ADMIN', 'OTHER', 'SETTINGS'];
$configPath = $root . '/config/rbac_permissions.php';
if (!is_file($configPath)) {
    $msg = 'config/rbac_permissions.php tidak ditemukan.';
    if ($isCli) {
        fwrite(STDERR, "ERROR: {$msg}\n");
    } else {
        http_response_code(500);
        echo rpd_h($msg);
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
        echo rpd_h($msg);
    }
    exit(1);
}

$permMap = [];
$catalogCategoryTotals = array_fill_keys($categories, 0);
$catalogByModule = [];
foreach ($rows as $r) {
    $code = strtoupper(trim((string)($r[0] ?? '')));
    if ($code === '') continue;
    $name = trim((string)($r[1] ?? ''));
    $module = strtoupper(trim((string)($r[2] ?? '')));
    $desc = trim((string)($r[3] ?? ''));
    if ($module === '') $module = explode('.', $code, 2)[0] ?: 'UNKNOWN';
    $cat = rpd_category($code);
    $permMap[$code] = ['module' => $module, 'name' => $name, 'description' => $desc, 'category' => $cat];
    $catalogCategoryTotals[$cat] = (int)$catalogCategoryTotals[$cat] + 1;
    if (!isset($catalogByModule[$module])) {
        $catalogByModule[$module] = ['counts' => array_fill_keys($categories, 0), 'items' => array_fill_keys($categories, [])];
    }
    $catalogByModule[$module]['counts'][$cat] = (int)$catalogByModule[$module]['counts'][$cat] + 1;
    $catalogByModule[$module]['items'][$cat][] = $code;
}
ksort($catalogByModule);
foreach ($catalogByModule as $m => $data) {
    foreach ($categories as $cat) {
        sort($data['items'][$cat]);
    }
    $catalogByModule[$m] = $data;
}

$excludeContains = ['/vendor/', '/storage/', '/exports/', '/node_modules/', '/.git/'];
$guardPatterns = [
    'require_any_permission' => '/\brequire_any_permission\s*\(/',
    'require_permission' => '/\brequire_permission\s*\(/',
    'require_role' => '/\brequire_role\s*\(/',
    'opsgov_require_admin' => '/\bopsgov_require_admin\s*\(/',
    'tools_require_admin' => '/\btools_require_admin\s*\(/',
    'require_admin_critical' => '/\brequire_admin_critical\s*\(/',
    'require_login' => '/\brequire_login\s*\(/',
];

$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$literalUsageTotals = array_fill_keys($categories, 0);
$literalByModule = [];
$usedLiteralPermissions = [];
$perFile = [];
$stats = [
    'php_files_scanned' => 0,
    'files_with_literal_permissions' => 0,
    'files_with_guard_calls' => 0,
    'files_guard_only' => 0,
    'files_no_guard_no_literal' => 0,
];

foreach ($iter as $f) {
    $path = (string)$f->getPathname();
    if (substr($path, -4) !== '.php') continue;
    $skip = false;
    foreach ($excludeContains as $needle) {
        if (str_contains($path, $needle)) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;
    $stats['php_files_scanned']++;
    $txt = @file_get_contents($path);
    if (!is_string($txt)) continue;

    $guards = [];
    foreach ($guardPatterns as $guardName => $guardRegex) {
        if (preg_match($guardRegex, $txt) === 1) {
            $guards[] = $guardName;
        }
    }
    sort($guards);

    $realLiteral = [];
    if (preg_match_all('/[\"\']([A-Z][A-Z0-9_]*(?:\.[A-Z0-9_]+)+)[\"\']/', $txt, $m) === 1 || !empty($m[1])) {
        $hits = array_values(array_unique($m[1] ?? []));
        foreach ($hits as $hit) {
            $code = strtoupper(trim((string)$hit));
            if (isset($permMap[$code])) {
                $realLiteral[] = $code;
            }
        }
    }
    $realLiteral = array_values(array_unique($realLiteral));
    sort($realLiteral);

    $hasLiteral = !empty($realLiteral);
    $hasGuard = !empty($guards);
    if (!$hasLiteral && !$hasGuard) {
        $stats['files_no_guard_no_literal']++;
        continue;
    }

    $rel = ltrim(str_replace($root, '', $path), '/');
    $byCategory = array_fill_keys($categories, []);
    $byModule = [];
    foreach ($realLiteral as $code) {
        $cat = (string)$permMap[$code]['category'];
        $mod = (string)$permMap[$code]['module'];
        $byCategory[$cat][] = $code;
        $byModule[$mod][] = $code;
        $usedLiteralPermissions[$code] = true;
    }
    ksort($byModule);
    foreach ($byModule as $mod => $codes) {
        $byModule[$mod] = array_values(array_unique($codes));
        sort($byModule[$mod]);
    }
    foreach ($categories as $cat) {
        $byCategory[$cat] = array_values(array_unique($byCategory[$cat]));
        sort($byCategory[$cat]);
    }

    if ($hasLiteral) $stats['files_with_literal_permissions']++;
    if ($hasGuard) $stats['files_with_guard_calls']++;
    if (!$hasLiteral && $hasGuard) $stats['files_guard_only']++;

    $perFile[$rel] = [
        'has_literal_permissions' => $hasLiteral,
        'has_guard_calls' => $hasGuard,
        'guard_calls' => $guards,
        'literal_permissions' => $realLiteral,
        'by_category' => $byCategory,
        'by_module' => $byModule,
    ];
}

ksort($perFile);

foreach (array_keys($usedLiteralPermissions) as $code) {
    $cat = (string)$permMap[$code]['category'];
    $mod = (string)$permMap[$code]['module'];
    $literalUsageTotals[$cat] = (int)$literalUsageTotals[$cat] + 1;
    if (!isset($literalByModule[$mod])) {
        $literalByModule[$mod] = ['counts' => array_fill_keys($categories, 0), 'items' => []];
    }
    $literalByModule[$mod]['counts'][$cat] = (int)$literalByModule[$mod]['counts'][$cat] + 1;
    $literalByModule[$mod]['items'][$cat][] = $code;
}
ksort($literalByModule);
foreach ($literalByModule as $m => $data) {
    foreach ($categories as $cat) {
        $data['items'][$cat] = array_values(array_unique($data['items'][$cat] ?? []));
        sort($data['items'][$cat]);
    }
    $literalByModule[$m] = $data;
}

$payload = [
    'state_version' => 2,
    'run_at' => date(DateTimeInterface::ATOM),
    'source' => '[APP_ROOT]/config/rbac_permissions.php + php file scan',
    'scope' => [
        'include_ext' => ['.php'],
        'exclude_path_contains' => $excludeContains,
    ],
    'catalog' => [
        'total_permissions' => count($permMap),
        'category_totals' => $catalogCategoryTotals,
        'modules' => $catalogByModule,
    ],
    'usage_literal' => [
        'total_permissions' => count($usedLiteralPermissions),
        'category_totals' => $literalUsageTotals,
        'modules' => $literalByModule,
    ],
    'file_coverage' => [
        'stats' => $stats,
        'files' => $perFile,
    ],
];

$logsDir = ts_storage_logs_dir();
$jsonPath = $logsDir . '/rbac_permission_detail.last.json';
$mdPath = $logsDir . '/rbac_permission_detail.last.md';
ts_write_json($jsonPath, $payload);

$md = [];
$md[] = '# RBAC Permission Detail';
$md[] = '';
$md[] = '- Run at: ' . $payload['run_at'];
$md[] = '- Source: `config/rbac_permissions.php` + scan file PHP';
$md[] = '- Scope exclude: `vendor`, `storage`, `exports`, `node_modules`, `.git`';
$md[] = '';
$md[] = '## Catalog Totals (Full 263)';
foreach ($categories as $cat) {
    $md[] = '- ' . $cat . ': ' . (int)($catalogCategoryTotals[$cat] ?? 0);
}
$md[] = '';
$md[] = '## Literal Usage Totals (Used in code as literal)';
foreach ($categories as $cat) {
    $md[] = '- ' . $cat . ': ' . (int)($literalUsageTotals[$cat] ?? 0);
}
$md[] = '';
$md[] = '## File Coverage Stats';
$md[] = '- PHP files scanned: ' . (int)$stats['php_files_scanned'];
$md[] = '- Files with literal permissions: ' . (int)$stats['files_with_literal_permissions'];
$md[] = '- Files with guard calls: ' . (int)$stats['files_with_guard_calls'];
$md[] = '- Files guard-only (no literal): ' . (int)$stats['files_guard_only'];
$md[] = '- Files no guard and no literal: ' . (int)$stats['files_no_guard_no_literal'];
$md[] = '';
$md[] = '## Per Modul (Catalog)';
foreach ($catalogByModule as $module => $data) {
    $parts = [];
    foreach ($categories as $cat) {
        $n = (int)($data['counts'][$cat] ?? 0);
        if ($n > 0) $parts[] = $cat . '=' . $n;
    }
    $md[] = '- ' . $module . ': ' . implode(', ', $parts);
}
$md[] = '';
$md[] = '## Per File';
foreach ($perFile as $file => $f) {
    $md[] = '### ' . $file;
    $md[] = '- has_literal_permissions: ' . (!empty($f['has_literal_permissions']) ? 'true' : 'false');
    $md[] = '- has_guard_calls: ' . (!empty($f['has_guard_calls']) ? 'true' : 'false');
    $md[] = '- guard_calls: ' . (!empty($f['guard_calls']) ? implode(', ', (array)$f['guard_calls']) : '-');
    foreach ($categories as $cat) {
        $codes = (array)($f['by_category'][$cat] ?? []);
        if (!empty($codes)) {
            $md[] = '- ' . $cat . ' (' . count($codes) . '): ' . implode(', ', $codes);
        }
    }
    $md[] = '';
}
@file_put_contents($mdPath, implode("\n", $md) . "\n");

ts_append_run_history('rbac_permission_detail', 'OK', [
    'source' => 'tools/rbac_permission_detail.php',
    'catalog_total' => count($permMap),
    'usage_literal_total' => count($usedLiteralPermissions),
    'files_scanned' => (int)$stats['php_files_scanned'],
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
echo "RBAC permission detail generated.\n";
echo "Catalog total: " . count($permMap) . "\n";
echo "Literal usage total: " . count($usedLiteralPermissions) . "\n";
echo "Files scanned: " . (int)$stats['php_files_scanned'] . "\n";
echo "Files with literal permissions: " . (int)$stats['files_with_literal_permissions'] . "\n";
echo "Files with guard calls: " . (int)$stats['files_with_guard_calls'] . "\n";
echo "Files guard-only: " . (int)$stats['files_guard_only'] . "\n";
echo "Artifacts:\n";
echo "- [APP_ROOT]/storage/logs/rbac_permission_detail.last.json\n";
echo "- [APP_ROOT]/storage/logs/rbac_permission_detail.last.md\n";
