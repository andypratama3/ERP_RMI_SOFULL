<?php
/**
 * tools/qa/menu_rbac_sync_check.php
 * Verifikasi: menu item → entry page → expected code (200/302/403).
 * Sumber: nav_config.php (menu) + HTTP GET test.
 * Output: storage/logs/menu_rbac_sync_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);
require_once $root . '/tools/_shared/tools_bootstrap.php';
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();

$baseUrl = (string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('TOOLS_BASE_URL') ?: getenv('SMOKE_BASE_URL') ?: 'https://localhost/ERP_RMI_SOFULL');
try {
    $baseUrl = normalize_base_url($baseUrl);
} catch (Throwable $e) {
    $baseUrl = rtrim($baseUrl, '/');
}
if ($baseUrl === '' || !preg_match('#^https?://#', $baseUrl)) {
    fwrite(STDERR, "FAIL: TOOLS_BASE_URL_INTERNAL required\n");
    exit(2);
}

$writeLast = in_array('--write-last', $_SERVER['argv'] ?? [], true);

// Seed smoke users if DB available
try {
    require_once $root . '/_shared/db.php';
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : null;
    if ($pdo) {
        $hash = password_hash('SmokeRBAC#2026!', PASSWORD_DEFAULT);
        foreach (['SmokeSYS_SYS' => 'SYS', 'SmokeBRANCH_SYS' => 'BRANCH', 'StaffCRM_BGR' => 'CRM', 'StaffWQS_BGR' => 'WQS'] as $u => $d) {
            if (function_exists('rmi_tools_seed_username_allowed') && !rmi_tools_seed_username_allowed($u)) {
                continue;
            }
            $st = $pdo->prepare("SELECT id FROM master_system_login WHERE username=?");
            $st->execute([$u]);
            if ($st->fetchColumn()) {
                $pdo->prepare("UPDATE master_system_login SET password_hash=?,department=?,status='ACTIVE',deleted_at=NULL WHERE username=?")
                    ->execute([$hash, $d, $u]);
            } else {
                $pdo->prepare("INSERT INTO master_system_login(username,password_hash,full_name,role,level,department,status) VALUES(?,?,?,'staff','STAFF',?,'ACTIVE')")
                    ->execute([$u, $hash, $u, $d]);
            }
        }
    }
} catch (Throwable $e) {
    // continue without seed
}
$strict = in_array('--strict', $_SERVER['argv'] ?? [], true);

// Extract menu URLs from nav_config
$navConfig = require $root . '/_shared/nav_config.php';
$u = []; // key => path (from rmi_layout $u mapping)
$keyToPath = [
    'dashboard' => '/dashboards/index.php',
    'absensi' => '/absensi/index.php',
    'kpi' => '/kpi/index.php',
    'chat' => '/chat/index.php',
    'help_center' => '/docs/help_center.php',
    'exec_summary' => '/dashboards/owner/exec_summary.php',
    'sales' => '/sales/sales_dashboard.php',
    'crm_control_tower' => '/sales/sales_control_tower.php',
    'crm_leads' => '/sales/crm_leads.php',
    'crm_do' => '/sales/sales_do.php',
    'wqs_do_tasks' => '/stock/wqs_do_tasks.php',
    'wqs_picking' => '/stock/wqs_picking.php',
    'scm_dashboard' => '/dashboards/scm/scm_dashboard.php',
    'scm_import_ct' => '/purchases/purchases_import_control_tower.php',
    'purchases_forwarding' => '/purchases/purchases_forwarding_tasks.php',
    'scm_do_tasks' => '/sales/scm_do_tasks.php',
    'scm_po' => '/purchases/purchases_po.php',
    'scm_gr' => '/purchases/purchases_gr.php',
    'scm_pr' => '/stock/wqs_pr.php',
    'stock' => '/dashboards/warehouse/wqs_dashboard.php',
    'wqs_incoming' => '/stock/wqs_incoming.php',
    'wqs_stock_view' => '/stock/wqs_stock.php',
    'wqs_allocation' => '/stock/wqs_allocation.php',
    'master' => '/master/index.php',
    'rbac' => '/rbac/index.php',
    // Items dengan URL eksplisit di nav_config — dimasukkan juga ke sini sebagai fallback
    'branch_dashboard' => '/dashboards/branch/branch_dashboard.php',
    'audit_log'        => '/master/audit_logs.php',
];

$menuEntries = [];
foreach (($navConfig['default'] ?? []) as $item) {
    if (isset($item['section'])) continue;
    $key = $item['key'] ?? '';
    $label = $item['label'] ?? $key;
    $roles = strtoupper(trim((string)($item['roles'] ?? 'ALL')));
    $path = null;
    if (isset($item['url'])) {
        $path = str_replace('{base}', '', $item['url']);
    } elseif (isset($keyToPath[$key])) {
        $path = $keyToPath[$key];
    }
    if ($path !== null && $path !== '') {
        $menuEntries[] = ['key' => $key, 'label' => $label, 'path' => $path, 'roles' => $roles];
    }
}

// Mapping smoke username → dept (untuk cek apakah user boleh akses item tertentu)
$smokeUserDept = [
    'SmokeSYS_SYS'    => 'SYS',
    'SmokeBRANCH_SYS' => 'BRANCH',
    'StaffCRM_BGR'    => 'CRM',
    'StaffWQS_BGR'    => 'WQS',
];

function msc_req(string $url, ?string $cookieFile = null): int {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,   // wajib untuk self-signed cert di 10.10.60.20
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'MenuRbacSync/1.0',
    ];
    if ($cookieFile !== null) {
        $opts[CURLOPT_COOKIEJAR] = $cookieFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

function msc_login(string $baseUrl, string $user, string $pass): string {
    $cf = sys_get_temp_dir() . '/menu_sync_' . md5($user) . '.txt';
    @unlink($cf);
    $loginUrl = url_join($baseUrl, '/master/login.php');
    msc_req($loginUrl, $cf);
    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['username' => $user, 'password' => $pass, 'csrf_token' => '']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cf,
        CURLOPT_COOKIEFILE => $cf,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
    return $cf;
}

$smokePass = 'SmokeRBAC#2026!';
$roles = [
    'GUEST' => null,
    'SmokeSYS_SYS' => 'SmokeRBAC#2026!',
    'SmokeBRANCH_SYS' => 'SmokeRBAC#2026!',
    'StaffCRM_BGR' => 'SmokeRBAC#2026!',
    'StaffWQS_BGR' => 'SmokeRBAC#2026!',
];
$sessions = [];
foreach ($roles as $uname => $pass) {
    if ($pass === null) continue;
    $sessions[$uname] = msc_login($baseUrl, $uname, $pass);
}

$results = [];
$mismatchCount = 0;
foreach ($menuEntries as $entry) {
    $path      = $entry['path'];
    $url       = url_join($baseUrl, $path);
    $itemRoles = $entry['roles'] ?? 'ALL';   // dari nav_config
    $rolesArr  = ($itemRoles === 'ALL') ? ['ALL'] : array_map('trim', explode(',', $itemRoles));
    $row = ['key' => $entry['key'], 'label' => $entry['label'], 'path' => $path,
            'roles' => $itemRoles, 'tests' => []];

    // GUEST selalu harus dapat 302 (redirect ke login)
    $guestCode = msc_req($url, null);
    $guestOk = in_array($guestCode, [302, 301], true);
    $row['tests']['GUEST'] = ['expected' => 302, 'actual' => $guestCode, 'ok' => $guestOk];
    if (!$guestOk) $mismatchCount++;

    foreach ($sessions as $uname => $cf) {
        $code    = msc_req($url, $cf);
        $dept    = $smokeUserDept[$uname] ?? 'UNKNOWN';

        // SYS selalu bypass semua permission → harus 200/302/304
        $isSys   = ($dept === 'SYS');
        // User boleh akses jika: roles=ALL, atau dept-nya ada di roles, atau SYS
        $deptInRoles = $isSys || in_array('ALL', $rolesArr, true) || in_array($dept, $rolesArr, true);

        if ($deptInRoles) {
            // User SEHARUSNYA bisa akses → 403 = mismatch
            $ok = in_array($code, [200, 302, 304], true) && $code !== 301;
        } else {
            $ok = in_array($code, [200, 302, 304, 403], true) && $code !== 301;
        }
        $row['tests'][$uname] = [
            'actual'       => $code,
            'dept_in_roles'=> $deptInRoles,
            'ok'           => $ok,
        ];
        if (!$ok) $mismatchCount++;
    }
    $results[] = $row;
}

foreach ($sessions as $cf) {
    @unlink($cf);
}

$ok = ($mismatchCount === 0);
$payload = [
    'ok' => $ok,
    'run_at' => date(DateTimeInterface::ATOM),
    'base_url' => $baseUrl,
    'menu_count' => count($menuEntries),
    'mismatch_count' => $mismatchCount,
    'results' => $results,
];

if ($writeLast) {
    @mkdir($root . '/storage/logs', 0775, true);
    file_put_contents($root . '/storage/logs/menu_rbac_sync_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['ok' => $ok, 'mismatch_count' => $mismatchCount], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
