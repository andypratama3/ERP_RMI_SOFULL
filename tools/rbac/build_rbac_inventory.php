<?php
declare(strict_types=1);

/**
 * tools/rbac/build_rbac_inventory.php
 *
 * Scans the repo for all accessible .php files, detects current guards,
 * cross-references with rbac_endpoint_matrix.yml, and writes
 * docs/governance/rbac_inventory.json.
 *
 * Usage (NAS only):
 *   cd /volume4/web/ERP_RMI_SOFULL
 *   php tools/rbac/build_rbac_inventory.php
 *   php tools/rbac/build_rbac_inventory.php --write-last
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true) || in_array('--output', $args, true);

$pubDirs = ['dashboards','sales','purchases','stock','hrl','hrl_process','hrl_reg_alkes',
            'absensi','kpi','payroll','mpr','Fixed_Asset','fixed_asset','rbac','tools',
            'master','chat','docs','api'];

$skipDirs = ['vendor','node_modules','_backup','exports','storage','tools/qa/_artifacts'];
$skipFiles = ['config.php','config-db.php','.env'];

// ── Guard patterns to detect ──────────────────────────────────────────────
$guardPatterns = [
    'require_login'            => '/require_login\s*\(/i',
    'require_permission'       => '/require_permission\s*\(/i',
    'require_any_permission'   => '/require_any_permission\s*\(/i',
    'require_role'             => '/require_role\s*\(/i',
    'require_admin_critical'   => '/require_admin_critical\s*\(/i',
    'auth_allow_depts'         => '/auth_allow_depts\s*\(/i',
    'auth_is_admin'            => '/auth_is_admin\s*\(/i',
    'require_fin_manager'      => '/require_fin_manager_or_admin\s*\(/i',
    'auth_require_fin_central' => '/auth_require_fin_central_approver\s*\(/i',
    'tools_require_access'     => '/tools_require_access\s*\(/i',
    'verify_csrf'              => '/verify_csrf\s*\(/i',
];

// ── Expected guards per path pattern ─────────────────────────────────────
$expectedGuards = [
    '~^(tools|rbac)/~'          => ['require_login', 'SYS_ONLY'],
    '~^master/master_system_~'  => ['require_login', 'SYS_ONLY'],
    '~^master/mfa_~'            => ['require_login', 'SYS_ONLY'],
    '~^master/nav_manager~'     => ['require_login', 'SYS_ONLY'],
    '~^purchases/purchases_payment_ap~' => ['require_login', 'FIN_ONLY'],
    '~^purchases/gl_reversal_~' => ['require_login', 'FIN_ONLY'],
    '~^purchases/purchases_forwarder_payment~' => ['require_login', 'FIN_ONLY'],
    '~^(absensi|kpi|chat)/~'    => ['require_login'],
    '~^sales/~'                 => ['require_login', 'require_any_permission'],
    '~^purchases/~'             => ['require_login'],
    '~^stock/~'                 => ['require_login'],
    '~^hrl/~'                   => ['require_login'],
    '~^payroll/~'               => ['require_login'],
    '~^mpr/~'                   => ['require_login'],
    '~^Fixed_Asset/~'           => ['require_login'],
    '~^dashboards/~'            => ['require_login'],
    '~^master/~'                => ['require_login'],
];

function detect_guards(string $filePath, array $patterns): array {
    $content = @file_get_contents($filePath);
    if ($content === false) return [];
    $found = [];
    foreach ($patterns as $name => $regex) {
        if (preg_match($regex, $content)) {
            $found[] = $name;
        }
    }
    return $found;
}

function get_expected_guards(string $relPath, array $expectedMap): array {
    foreach ($expectedMap as $pattern => $guards) {
        if (preg_match($pattern, $relPath)) return $guards;
    }
    return [];
}

function risk_level(string $relPath, bool $hasFin): string {
    if (preg_match('~(tools|rbac|master_system|mfa|nav_manager|backup|restore|gl_reversal|payment_ap)~i', $relPath)) return 'HIGH';
    if ($hasFin || preg_match('~(invoice_ap|purchases_po|payroll|adjustment|stock_transfer|sales_do)~i', $relPath)) return 'MED';
    return 'LOW';
}

// ── Scan all public PHP files ─────────────────────────────────────────────
$inventory = [];
foreach ($pubDirs as $dir) {
    $fullDir = $root . '/' . $dir;
    if (!is_dir($fullDir)) continue;

    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;

        $fullPath = str_replace('\\', '/', $file->getPathname());
        $relPath  = ltrim(str_replace($root . '/', '', $fullPath), '/');

        // Skip unwanted dirs/files
        $skip = false;
        foreach ($skipDirs as $sd) {
            if (str_starts_with($relPath, $sd . '/') || str_starts_with($relPath, $sd)) {
                $skip = true; break;
            }
        }
        foreach ($skipFiles as $sf) {
            if (basename($relPath) === $sf) { $skip = true; break; }
        }
        if ($skip) continue;

        $guards = detect_guards($fullPath, $guardPatterns);
        $hasFin = in_array('auth_require_fin_central', $guards);
        $expectedGs = get_expected_guards($relPath, $expectedGuards);

        $missing = [];
        foreach ($expectedGs as $eg) {
            if (!in_array($eg, ['SYS_ONLY','FIN_ONLY']) && !in_array($eg, $guards)) {
                $missing[] = $eg;
            }
        }

        // Special checks
        $sysOnly = in_array('SYS_ONLY', $expectedGs);
        $finOnly = in_array('FIN_ONLY', $expectedGs);
        $hasLogin = in_array('require_login', $guards);
        $hasCsrf = in_array('verify_csrf', $guards);

        $status = 'OK';
        $issues = [];
        if (!$hasLogin && !in_array('tools_require_access', $guards)) {
            $issues[] = 'MISSING_LOGIN_GUARD';
            $status = 'WARN';
        }
        if ($missing) {
            foreach ($missing as $m) $issues[] = 'MISSING_GUARD:' . $m;
            $status = 'WARN';
        }

        $inventory[] = [
            'path'              => $relPath,
            'url_path'          => '/' . $relPath,
            'module'            => explode('/', $relPath)[0],
            'risk'              => risk_level($relPath, $hasFin),
            'has_login_guard'   => $hasLogin,
            'has_csrf_guard'    => $hasCsrf,
            'fin_central_guard' => $hasFin,
            'guards_found'      => $guards,
            'expected_guards'   => $expectedGs,
            'missing_guards'    => $missing,
            'status'            => $status,
            'issues'            => $issues,
        ];
    }
}

// Sort by risk desc, then path
usort($inventory, function ($a, $b) {
    $riskOrder = ['HIGH' => 0, 'MED' => 1, 'LOW' => 2];
    $rc = ($riskOrder[$a['risk']] ?? 9) <=> ($riskOrder[$b['risk']] ?? 9);
    return $rc !== 0 ? $rc : strcmp($a['path'], $b['path']);
});

// ── Summary ───────────────────────────────────────────────────────────────
$total  = count($inventory);
$warn   = count(array_filter($inventory, fn($r) => $r['status'] === 'WARN'));
$high   = count(array_filter($inventory, fn($r) => $r['risk'] === 'HIGH'));
$med    = count(array_filter($inventory, fn($r) => $r['risk'] === 'MED'));
$missingLogin = count(array_filter($inventory, fn($r) => in_array('MISSING_LOGIN_GUARD', $r['issues'])));

$output = [
    '_meta' => [
        'generated_at'     => date(DateTime::ATOM),
        'generator'        => 'tools/rbac/build_rbac_inventory.php',
        'total_files'      => $total,
        'warn_count'       => $warn,
        'risk_high'        => $high,
        'risk_med'         => $med,
        'missing_login'    => $missingLogin,
        'ok'               => ($missingLogin === 0),
    ],
    'inventory' => $inventory,
];

// Write to docs/governance/
$outDir = $root . '/docs/governance';
if (!is_dir($outDir)) @mkdir($outDir, 0775, true);
$outPath = $outDir . '/rbac_inventory.json';
$ok = file_put_contents($outPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// Also write to storage/logs if --write-last
if ($writeLast) {
    $logsDir = $root . '/storage/logs';
    @mkdir($logsDir, 0775, true);
    file_put_contents($logsDir . '/rbac_inventory_last.json', json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode([
    'ok'            => ($missingLogin === 0),
    'total_files'   => $total,
    'warn_count'    => $warn,
    'missing_login' => $missingLogin,
    'risk_high'     => $high,
    'output_path'   => $outPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($missingLogin > 0 ? 1 : 0);
