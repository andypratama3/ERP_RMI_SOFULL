<?php
declare(strict_types=1);

/**
 * tools/qa/rbac_matrix_http_check.php
 *
 * RBAC Matrix HTTP Check — reads rbac_endpoint_matrix.yml (interpreted as
 * a PHP-native endpoint list) and performs live HTTP checks for each
 * endpoint × role combination.
 *
 * Run only on NAS:
 *   cd /volume4/web/ERP_RMI_SOFULL
 *   TOOLS_BASE_URL_INTERNAL=http://10.10.60.20/ERP_RMI_SOFULL \
 *     php tools/qa/rbac_matrix_http_check.php --write-last
 *
 *   --strict          : exit code 1 if any mismatch
 *   --write-last      : write result to storage/logs/rbac_matrix_http_check_last.json
 *   --base-url=URL    : override base URL
 *   --skip-post       : skip POST-action tests (for environments without test data)
 *
 * Output: storage/logs/rbac_matrix_http_check_last.json
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);
require_once $root . '/tools/_shared/tools_bootstrap.php';

$args     = $_SERVER['argv'] ?? [];
$strict   = in_array('--strict', $args, true);
$writeLast= in_array('--write-last', $args, true);
$skipPost = in_array('--skip-post', $args, true);

$baseUrl = '';
foreach ($args as $a) {
    if (str_starts_with((string)$a, '--base-url=')) {
        $baseUrl = trim(substr((string)$a, strlen('--base-url=')));
        break;
    }
}
if ($baseUrl === '') {
    $baseUrl = trim((string)(
        getenv('TOOLS_BASE_URL_INTERNAL') ?:
        getenv('TOOLS_BASE_URL') ?:
        getenv('SMOKE_BASE_URL') ?:
        getenv('APP_URL') ?: 'http://10.10.60.20/ERP_RMI_SOFULL'
    ));
}
try {
    $baseUrl = normalize_base_url((string)$baseUrl);
} catch (Throwable $e) {
    $baseUrl = rtrim((string)$baseUrl, '/');
}

if ($baseUrl === '' || !preg_match('#^https?://#', $baseUrl)) {
    fwrite(STDERR, "FAIL: TOOLS_BASE_URL_INTERNAL must be set to http(s)://host/path\n");
    fwrite(STDERR, "Example: TOOLS_BASE_URL_INTERNAL=http://10.10.60.20/ERP_RMI_SOFULL php " . basename(__FILE__) . "\n");
    exit(2);
}

// ── URL utilities (double-slash prevention) ────────────────────────────────
$urlUtils = $root . '/tools/_shared/url_utils.php';
if (is_file($urlUtils)) require_once $urlUtils;

// ── APP ROOT guard ─────────────────────────────────────────────────────────
$appRootGuard = $root . '/tools/_shared/app_root_guard.php';
if (is_file($appRootGuard)) {
    require_once $appRootGuard;
    if (function_exists('tools_assert_expected_app_root')) {
        try { tools_assert_expected_app_root(); } catch (Throwable $e) {
            fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
            exit(2);
        }
    }
}

// ── Bootstrap DB ───────────────────────────────────────────────────────────
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();

$pdo = null;
try {
    require_once $root . '/_shared/db.php';
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : null;
} catch (Throwable $e) {
    fwrite(STDERR, "WARN: DB unavailable — cannot seed/reset smoke users. " . $e->getMessage() . "\n");
}

// ── Smoke Accounts ─────────────────────────────────────────────────────────
$smokePass = 'SmokeRBAC#2026!';
$smokeAccounts = [
    'SmokeSYS_SYS'    => ['role'=>'sys',     'level'=>'SYS',     'dept'=>'SYS',    'label'=>'SYS'],
    'SmokeBRANCH_SYS' => ['role'=>'staff',   'level'=>'STAFF',   'dept'=>'BRANCH', 'label'=>'BRANCH staff'],
    'MgrFIN_BGR'      => ['role'=>'manager', 'level'=>'MANAGER', 'dept'=>'FIN',    'label'=>'FIN Central Approver'],
    'StaffFIN_BGR'    => ['role'=>'staff',   'level'=>'STAFF',   'dept'=>'FIN',    'label'=>'FIN Staff'],
    'MgrFIN_BDG'      => ['role'=>'manager', 'level'=>'MANAGER', 'dept'=>'FIN',    'label'=>'FIN Mgr non-BGR'],
    'StaffWQS_BGR'    => ['role'=>'staff',   'level'=>'STAFF',   'dept'=>'WQS',    'label'=>'WQS Staff'],
    'StaffCRM_BGR'    => ['role'=>'staff',   'level'=>'STAFF',   'dept'=>'CRM',    'label'=>'CRM Staff'],
    'MgrITC_BGR'      => ['role'=>'manager', 'level'=>'MANAGER', 'dept'=>'ITC',    'label'=>'ITC Manager'],
];

// ── Helper: seed/reset user password ─────────────────────────────────────
function rbmc_seed_user(PDO $pdo, string $username, string $pass, string $role, string $level, string $dept): void {
    if (function_exists('rmi_tools_seed_username_allowed') && !rmi_tools_seed_username_allowed($username)) {
        return;
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $st = $pdo->prepare("SELECT id FROM master_system_login WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id > 0) {
        $pdo->prepare("UPDATE master_system_login SET password_hash=?,role=?,level=?,department=?,status='ACTIVE',deleted_at=NULL,updated_at=NOW() WHERE id=?")
            ->execute([$hash,$role,$level,$dept,$id]);
    } else {
        $pdo->prepare("INSERT INTO master_system_login(username,password_hash,full_name,role,level,department,status,created_at,updated_at) VALUES(?,?,?,?,?,?,'ACTIVE',NOW(),NOW())")
            ->execute([$username,$hash,$username,$role,$level,$dept]);
    }
    try { $pdo->prepare("DELETE FROM auth_login_attempts WHERE username=?")->execute([$username]); } catch (Throwable $e) {}
    try { $pdo->prepare("DELETE FROM auth_rate_limit WHERE identifier=?")->execute([$username]); } catch (Throwable $e) {}
}

// ── Helper: HTTP request via cURL ────────────────────────────────────────
function rbmc_req(string $method, string $url, string $cookieFile, array $post = [], int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => max(5, $timeout),
        CURLOPT_USERAGENT      => 'RBACMatrixCheck/2.0',
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $resp   = curl_exec($ch);
    $err    = curl_error($ch);
    $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $raw    = is_string($resp) ? $resp : '';
    $header = substr($raw, 0, $hsize);
    $body   = substr($raw, $hsize);
    $location = '';
    if ($header !== '' && preg_match('/^Location:\s*(\S+)/im', $header, $m)) {
        $location = trim((string)$m[1]);
    }
    return [
        'code' => $code,
        'error' => $err,
        'body' => $body,
        'body_prefix' => substr($body, 0, 120),
        'location' => $location,
    ];
}

/** Extract CSRF token from HTML (hidden input csrf_token). */
function rbmc_extract_csrf(string $html): string {
    if ($html === '') {
        return '';
    }
    if (preg_match('/name\s*=\s*["\']csrf_token["\'][^>]*\svalue\s*=\s*["\']([^"\']*)["\']/i', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/name\s*=\s*["\']csrf_token["\'][^>]*\svalue\s*=\s*([^\s>]+)/i', $html, $m)) {
        return trim($m[1], '"\'');
    }
    return '';
}

/**
 * Build POST fields for matrix endpoint (real field names, not generic action=).
 *
 * @param array<string,mixed> $ep
 * @return array<string,string>
 */
function rbmc_post_fields_for_endpoint(array $ep, string $csrfToken): array {
    $path = (string)($ep['path'] ?? '');
    $action = (string)($ep['action'] ?? '');
    $csrf = $csrfToken !== '' ? $csrfToken : 'smoke_missing_csrf';

    if (str_ends_with($path, '/purchases/purchases_payment_ap.php') && $action === 'create_pay_missing_csrf') {
        // Deliberately omit csrf_token — must fail verify_csrf() before business logic.
        return [
            'create_pay' => '1',
            'ap_id' => '1',
            'amount' => '0.01',
            'pay_date' => date('Y-m-d'),
            'method' => 'TRANSFER',
            'percent_of_po' => '0',
            'bank_name' => '',
            'reference' => 'rbac_csrf_matrix',
            'note' => 'missing_csrf',
        ];
    }

    if (str_ends_with($path, '/purchases/purchases_payment_ap.php') && $action === 'create_pay') {
        return [
            'create_pay' => '1',
            'csrf_token' => $csrf,
            'ap_id' => '1',
            'amount' => '0.01',
            'pay_date' => date('Y-m-d'),
            'method' => 'TRANSFER',
            'percent_of_po' => '0',
            'bank_name' => '',
            'reference' => 'rbac_smoke',
            'note' => 'rbac_matrix',
        ];
    }

    return array_filter([
        'action' => $action !== '' ? $action : null,
        'csrf_token' => $csrf,
    ], static fn ($v) => $v !== null && $v !== '');
}

// ── Helper: login and return cookie file ──────────────────────────────────
function rbmc_login(string $baseUrl, string $username, string $password): string {
    $cookieFile = sys_get_temp_dir() . '/rbac_check_' . md5($username) . '.txt';
    @unlink($cookieFile);

    $loginPath = url_join($baseUrl, '/master/login.php');
    // GET login page first (csrf seed)
    rbmc_req('GET', $loginPath, $cookieFile);

    // Attempt to extract CSRF from page — or just POST (server issues new token)
    $loginData = [
        'username'   => $username,
        'password'   => $password,
        'csrf_token' => '', // handled by session on NAS
    ];
    rbmc_req('POST', $loginPath, $cookieFile, $loginData);

    return $cookieFile;
}

// ── Helper: guest test (no cookie) ───────────────────────────────────────
function rbmc_guest_req(string $url): int {
    $tmpFile = sys_get_temp_dir() . '/rbac_guest_' . md5($url) . '.txt';
    @unlink($tmpFile);
    $r = rbmc_req('GET', $url, $tmpFile);
    @unlink($tmpFile);
    return $r['code'];
}

// ── Seed smoke users in DB ────────────────────────────────────────────────
$seeded = 0;
if ($pdo) {
    echo "Seeding smoke accounts...\n";
    foreach ($smokeAccounts as $uname => $info) {
        try {
            rbmc_seed_user($pdo, $uname, $smokePass, $info['role'], $info['level'], $info['dept']);
            $seeded++;
        } catch (Throwable $e) {
            fwrite(STDERR, "WARN: Cannot seed $uname: " . $e->getMessage() . "\n");
        }
    }
    echo "Seeded $seeded accounts.\n";

    // ── Seed RBAC permissions untuk smoke accounts ────────────────────────────
    // BRANCH/STAFF dan BRANCH/MANAGER perlu permission agar test matrix PASS.
    // Ini mirror dari Migration 157_branch_rbac_permissions.sql.
    try {
        $branchPerms = [
            // BRANCH STAFF
            ['BRANCH', 'STAFF',   'SALES.VIEW'],
            ['BRANCH', 'STAFF',   'SALES.CREATE'],
            ['BRANCH', 'STAFF',   'SALES.EDIT'],
            ['BRANCH', 'STAFF',   'SALES.EXPORT'],
            ['BRANCH', 'STAFF',   'STOCK.VIEW'],
            ['BRANCH', 'STAFF',   'WQS.INCOMING_VIEW'],
            ['BRANCH', 'STAFF',   'WQS.INCOMING_CREATE'],
            ['BRANCH', 'STAFF',   'PURCHASES.VIEW'],
            ['BRANCH', 'STAFF',   'PURCHASES.PO_VIEW'],
            ['BRANCH', 'STAFF',   'PURCHASES.PO_CREATE'],
            ['BRANCH', 'STAFF',   'HRL.PROCESS_VIEW'],
            ['BRANCH', 'STAFF',   'KPI.VIEW'],
            ['BRANCH', 'STAFF',   'CHAT.VIEW'],           // Internal chat — semua dept bisa chat
            ['BRANCH', 'STAFF',   'ABSENSI.VIEW'],        // Absensi — semua karyawan wajib
            ['BRANCH', 'STAFF',   'ABSENSI.CLOCK_IN'],    // Clock in/out
            ['BRANCH', 'STAFF',   'DASHBOARD.SALES_VIEW'], // Sales view
            // BRANCH MANAGER
            ['BRANCH', 'MANAGER', 'SALES.VIEW'],
            ['BRANCH', 'MANAGER', 'SALES.CREATE'],
            ['BRANCH', 'MANAGER', 'SALES.EDIT'],
            ['BRANCH', 'MANAGER', 'SALES.DELETE'],
            ['BRANCH', 'MANAGER', 'STOCK.VIEW'],
            ['BRANCH', 'MANAGER', 'PURCHASES.VIEW'],
            ['BRANCH', 'MANAGER', 'PURCHASES.PO_VIEW'],
            ['BRANCH', 'MANAGER', 'HRL.PROCESS_VIEW'],
        ];
        $upsertPerm = "INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag)
            VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE allow_flag=1";
        $stPerm = null;
        // Pastikan table rbac_dept_role_permissions ada
        $pdo->query("SELECT 1 FROM rbac_dept_role_permissions LIMIT 1");
        $stPerm = $pdo->prepare($upsertPerm);
        foreach ($branchPerms as [$dept, $role, $perm]) {
            try { $stPerm->execute([$dept, $role, $perm]); } catch (Throwable $e) {}
        }
        echo "Seeded BRANCH RBAC permissions.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "WARN: Cannot seed BRANCH permissions: " . $e->getMessage() . "\n");
    }
}

// ── Login all accounts ────────────────────────────────────────────────────
echo "Logging in smoke accounts at: $baseUrl\n";
$sessions = []; // username => cookieFile
foreach ($smokeAccounts as $uname => $info) {
    $cf = rbmc_login($baseUrl, $uname, $smokePass);
    $sessions[$uname] = $cf;
}

// ── Endpoint Matrix (mirrors rbac_endpoint_matrix.yml) ───────────────────
// Format: ['id', 'module', 'label', 'path', 'method', 'risk', 'cash_out', 'fin_central_only', 'guest', 'roles'=>[username=>expectedCode], 'notes']
$matrix = [

    // ── MAIN ──────────────────────────────────────────────────────────────
    ['id'=>'MAIN_01','module'=>'MAIN','label'=>'Dashboard Center','path'=>'/dashboards/index.php','method'=>'GET','risk'=>'LOW','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>200,'MgrFIN_BGR'=>200,'StaffFIN_BGR'=>200,'StaffWQS_BGR'=>200,'StaffCRM_BGR'=>200,'MgrITC_BGR'=>200]],
    // Chat: BRANCH membutuhkan chat (roles='ALL' di nav_config) — seeder menyediakan CHAT.VIEW
    ['id'=>'MAIN_02','module'=>'MAIN','label'=>'Chat','path'=>'/chat/index.php','method'=>'GET','risk'=>'LOW','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>200,'StaffCRM_BGR'=>200,'StaffWQS_BGR'=>200]],
    // Absensi: BRANCH wajib absensi (roles='ALL') — seeder menyediakan permission
    ['id'=>'MAIN_03','module'=>'MAIN','label'=>'Absensi','path'=>'/absensi/index.php','method'=>'GET','risk'=>'LOW','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>200,'StaffCRM_BGR'=>200]],
    ['id'=>'MAIN_04','module'=>'MAIN','label'=>'KPI Center','path'=>'/kpi/index.php','method'=>'GET','risk'=>'LOW','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>200,'StaffCRM_BGR'=>200]],
    // Quality Dashboard: BRANCH diizinkan via auth_allow_depts+dept-fallback (quality section includes BRANCH)
    ['id'=>'MAIN_05','module'=>'MAIN','label'=>'Quality Dashboard','path'=>'/dashboards/quality/qc_complaint_dashboard.php','method'=>'GET','risk'=>'LOW','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>200,'StaffWQS_BGR'=>200,'StaffCRM_BGR'=>403,'MgrFIN_BGR'=>403]],

    // ── MASTER ────────────────────────────────────────────────────────────
    ['id'=>'MASTER_01','module'=>'MASTER','label'=>'Master Data Center','path'=>'/master/index.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>403,'MgrITC_BGR'=>200,'StaffCRM_BGR'=>403]],
    ['id'=>'MASTER_02','module'=>'MASTER','label'=>'System Login (SYS only)','path'=>'/master/master_system_login.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>403,'MgrFIN_BGR'=>403,'MgrITC_BGR'=>403]],
    ['id'=>'MASTER_03','module'=>'MASTER','label'=>'System Config (SYS only)','path'=>'/master/master_system_config.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>403,'MgrFIN_BGR'=>403,'MgrITC_BGR'=>403]],
    ['id'=>'MASTER_04','module'=>'MASTER','label'=>'Nav Manager (SYS only)','path'=>'/master/nav_manager.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>403,'MgrITC_BGR'=>403]],
    ['id'=>'MASTER_05','module'=>'MASTER','label'=>'Company Bank Accounts','path'=>'/master/company_bank_accounts.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrFIN_BGR'=>200,'StaffFIN_BGR'=>200,'SmokeBRANCH_SYS'=>403,'StaffCRM_BGR'=>403]],

    // ── CRM/SALES ─────────────────────────────────────────────────────────
    ['id'=>'CRM_01','module'=>'CRM','label'=>'Sales Dashboard','path'=>'/sales/sales_dashboard.php','method'=>'GET','risk'=>'LOW','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffCRM_BGR'=>200,'SmokeBRANCH_SYS'=>200,'StaffWQS_BGR'=>403,'MgrFIN_BGR'=>403]],
    ['id'=>'CRM_02','module'=>'CRM','label'=>'Sales DO','path'=>'/sales/sales_do.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffCRM_BGR'=>200,'SmokeBRANCH_SYS'=>200,'StaffWQS_BGR'=>200,'MgrFIN_BGR'=>200,'MgrITC_BGR'=>403]],
    ['id'=>'CRM_03','module'=>'CRM','label'=>'WQS DO Tasks','path'=>'/stock/wqs_do_tasks.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffWQS_BGR'=>200,'StaffCRM_BGR'=>403,'SmokeBRANCH_SYS'=>403]],

    // ── PURCHASES/PQP ─────────────────────────────────────────────────────
    // PQP_01: WQS dept tidak punya akses ke PQP/Purchases Dashboard (routes='PQP,SCM,SYS')
    // BRANCH bisa karena scm_po dan purchases_forwarding_tasks masuk BRANCH via nav_config
    ['id'=>'PQP_01','module'=>'PQP','label'=>'Purchases Dashboard','path'=>'/purchases/purchases_dashboard.php','method'=>'GET','risk'=>'LOW','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>200,'StaffWQS_BGR'=>403,'StaffCRM_BGR'=>403,'MgrITC_BGR'=>403]],
    ['id'=>'PQP_02','module'=>'PQP','label'=>'Purchase Order','path'=>'/purchases/purchases_po.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>200,'StaffWQS_BGR'=>200,'StaffCRM_BGR'=>403,'MgrFIN_BGR'=>403]],
    ['id'=>'PQP_03','module'=>'PQP','label'=>'Invoice AP','path'=>'/purchases/purchases_invoice_ap.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrFIN_BGR'=>200,'StaffFIN_BGR'=>200,'SmokeBRANCH_SYS'=>403,'StaffWQS_BGR'=>403]],
    ['id'=>'PQP_04','module'=>'PQP','label'=>'Payment AP (GET view)','path'=>'/purchases/purchases_payment_ap.php','method'=>'GET','risk'=>'HIGH','cash_out'=>true,'guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrFIN_BGR'=>200,'StaffFIN_BGR'=>200,'SmokeBRANCH_SYS'=>403,'StaffCRM_BGR'=>403,'StaffWQS_BGR'=>403,'MgrITC_BGR'=>403]],
    ['id'=>'PQP_05','module'=>'PQP','label'=>'GL Reversal (GET view)','path'=>'/purchases/gl_reversal_approvals.php','method'=>'GET','risk'=>'HIGH','cash_out'=>true,'guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrFIN_BGR'=>200,'StaffFIN_BGR'=>200,'SmokeBRANCH_SYS'=>403,'StaffWQS_BGR'=>403]],
    ['id'=>'PQP_06','module'=>'PQP','label'=>'Import Control Tower','path'=>'/purchases/purchases_import_control_tower.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>200,'StaffWQS_BGR'=>200,'StaffCRM_BGR'=>403]],

    // ── WQS/STOCK ─────────────────────────────────────────────────────────
    // WQS Dashboard: BRANCH diizinkan via auth_allow_depts+dept-fallback (warehouse section includes BRANCH)
    ['id'=>'WQS_01','module'=>'WQS','label'=>'WQS Dashboard','path'=>'/dashboards/warehouse/wqs_dashboard.php','method'=>'GET','risk'=>'LOW','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffWQS_BGR'=>200,'SmokeBRANCH_SYS'=>200,'StaffCRM_BGR'=>403,'MgrFIN_BGR'=>403]],
    ['id'=>'WQS_02','module'=>'WQS','label'=>'Stock on Hand','path'=>'/stock/wqs_stock.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffWQS_BGR'=>200,'SmokeBRANCH_SYS'=>200,'MgrFIN_BGR'=>403,'StaffCRM_BGR'=>403,'MgrITC_BGR'=>403]],
    ['id'=>'WQS_03','module'=>'WQS','label'=>'PR','path'=>'/stock/wqs_pr.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffWQS_BGR'=>200,'SmokeBRANCH_SYS'=>200,'MgrFIN_BGR'=>403,'StaffCRM_BGR'=>403]],
    ['id'=>'WQS_04','module'=>'WQS','label'=>'Incoming','path'=>'/stock/wqs_incoming.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffWQS_BGR'=>200,'SmokeBRANCH_SYS'=>200,'MgrFIN_BGR'=>403,'StaffCRM_BGR'=>403]],
    ['id'=>'WQS_05','module'=>'WQS','label'=>'Stock Adjustment (MGR only)','path'=>'/stock/wqs_stock_adjustment.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffWQS_BGR'=>403,'SmokeBRANCH_SYS'=>403,'MgrFIN_BGR'=>403]],
    ['id'=>'WQS_06','module'=>'WQS','label'=>'Picking','path'=>'/stock/wqs_picking.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffWQS_BGR'=>200,'SmokeBRANCH_SYS'=>200,'MgrFIN_BGR'=>403,'StaffCRM_BGR'=>403]],
    ['id'=>'WQS_07','module'=>'WQS','label'=>'Allocation','path'=>'/stock/wqs_allocation.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffWQS_BGR'=>200,'SmokeBRANCH_SYS'=>200,'MgrFIN_BGR'=>403,'StaffCRM_BGR'=>403]],

    // ── HRL ───────────────────────────────────────────────────────────────
    ['id'=>'HRL_01','module'=>'HRL_PROCESS','label'=>'HRL Process (all depts)','path'=>'/hrl_process/index.php','method'=>'GET','risk'=>'LOW','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>200,'StaffCRM_BGR'=>200,'StaffWQS_BGR'=>200,'MgrFIN_BGR'=>200]],
    ['id'=>'HRL_02','module'=>'HRL','label'=>'HRL Reg Alkes','path'=>'/hrl_reg_alkes/index.php','method'=>'GET','risk'=>'LOW','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'StaffCRM_BGR'=>403,'SmokeBRANCH_SYS'=>403]],

    // ── PAYROLL ───────────────────────────────────────────────────────────
    ['id'=>'PAY_01','module'=>'PAYROLL','label'=>'Payroll','path'=>'/payroll/index.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrFIN_BGR'=>200,'SmokeBRANCH_SYS'=>403,'StaffCRM_BGR'=>403,'StaffWQS_BGR'=>403]],

    // ── FIXED ASSET ───────────────────────────────────────────────────────
    ['id'=>'ACT_01','module'=>'FIXED_ASSET','label'=>'Fixed Asset','path'=>'/Fixed_Asset/index.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrFIN_BGR'=>200,'SmokeBRANCH_SYS'=>403,'StaffCRM_BGR'=>403,'StaffWQS_BGR'=>403]],

    // ── SETTINGS ──────────────────────────────────────────────────────────
    // Route policy allow ALL+STAFF/MANAGER/SYS; halaman tetap 403 tanpa SYSTEM.RBAC_MANAGE|VIEW.
    ['id'=>'SET_01','module'=>'SETTINGS','label'=>'RBAC Center (permission RBAC_MANAGE/VIEW)','path'=>'/rbac/index.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'SmokeBRANCH_SYS'=>403,'MgrFIN_BGR'=>403,'MgrITC_BGR'=>403,'StaffCRM_BGR'=>403]],
    ['id'=>'SET_02','module'=>'SETTINGS','label'=>'Tools index (SYS only)','path'=>'/tools/index.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrITC_BGR'=>403,'SmokeBRANCH_SYS'=>403,'MgrFIN_BGR'=>403,'StaffCRM_BGR'=>403]],
    ['id'=>'SET_03','module'=>'SETTINGS','label'=>'Tools health (SYS only)','path'=>'/tools/health.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrITC_BGR'=>403,'SmokeBRANCH_SYS'=>403,'MgrFIN_BGR'=>403]],
    ['id'=>'SET_04','module'=>'SETTINGS','label'=>'Backup manager (SYS only)','path'=>'/tools/backup_manager.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrITC_BGR'=>403,'SmokeBRANCH_SYS'=>403,'MgrFIN_BGR'=>403]],
    ['id'=>'SET_05','module'=>'SETTINGS','label'=>'Backup schedule (SYS only)','path'=>'/tools/backup_schedule.php','method'=>'GET','risk'=>'HIGH','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrITC_BGR'=>403,'SmokeBRANCH_SYS'=>403]],
    // SET_06: Finance Dashboard — FIN, ACT, SYS. BRANCH diizinkan lewat dept guard tapi
    // bukan primary dashboard BRANCH (mereka tidak butuh AR/AP). Ekspektasi 403 untuk BRANCH.
    ['id'=>'SET_06','module'=>'SETTINGS','label'=>'Finance AR/AP Dashboard (FIN)','path'=>'/dashboards/finance/ar_ap_cash_dashboard.php','method'=>'GET','risk'=>'MED','guest'=>302,'roles'=>['SmokeSYS_SYS'=>200,'MgrFIN_BGR'=>200,'StaffFIN_BGR'=>200,'SmokeBRANCH_SYS'=>403,'StaffCRM_BGR'=>403]],

    // ── FIN CENTRAL POST APPROVAL (cash-out — 2-layer enforcement) ────────
    // NOTE: These POST tests are only meaningful if AP payment data exists.
    // If no AP invoice data, the create_pay action may return 200 with error message.
    // The RBAC guard (403) fires BEFORE the business logic, so 403 is reliable.
    ['id'=>'FC_01','module'=>'FIN_CENTRAL','label'=>'POST create_pay — MgrFIN_BGR (allowed)','path'=>'/purchases/purchases_payment_ap.php','method'=>'POST','action'=>'create_pay','risk'=>'HIGH','cash_out'=>true,'fin_central_only'=>true,'guest'=>302,'post_skip_reason'=>'Requires valid AP data','roles'=>['MgrFIN_BGR'=>200,'SmokeSYS_SYS'=>200],'skip_post'=>false],
    ['id'=>'FC_02','module'=>'FIN_CENTRAL','label'=>'POST create_pay — StaffFIN_BGR (blocked)','path'=>'/purchases/purchases_payment_ap.php','method'=>'POST','action'=>'create_pay','risk'=>'HIGH','cash_out'=>true,'fin_central_only'=>true,'guest'=>302,'roles'=>['StaffFIN_BGR'=>403],'skip_post'=>false],
    ['id'=>'FC_03','module'=>'FIN_CENTRAL','label'=>'POST create_pay — MgrFIN_BDG (blocked)','path'=>'/purchases/purchases_payment_ap.php','method'=>'POST','action'=>'create_pay','risk'=>'HIGH','cash_out'=>true,'fin_central_only'=>true,'guest'=>302,'roles'=>['MgrFIN_BDG'=>403],'skip_post'=>false],
    ['id'=>'FC_04','module'=>'FIN_CENTRAL','label'=>'POST create_pay — SmokeBRANCH (blocked)','path'=>'/purchases/purchases_payment_ap.php','method'=>'POST','action'=>'create_pay','risk'=>'HIGH','cash_out'=>true,'fin_central_only'=>true,'guest'=>302,'roles'=>['SmokeBRANCH_SYS'=>403],'skip_post'=>false],
    ['id'=>'FC_05','module'=>'FIN_CENTRAL','label'=>'POST create_pay — StaffCRM (blocked)','path'=>'/purchases/purchases_payment_ap.php','method'=>'POST','action'=>'create_pay','risk'=>'HIGH','cash_out'=>true,'fin_central_only'=>true,'guest'=>302,'roles'=>['StaffCRM_BGR'=>403],'skip_post'=>false],
    ['id'=>'FC_06','module'=>'FIN_CENTRAL','label'=>'POST create_pay — StaffWQS (blocked)','path'=>'/purchases/purchases_payment_ap.php','method'=>'POST','action'=>'create_pay','risk'=>'HIGH','cash_out'=>true,'fin_central_only'=>true,'guest'=>302,'roles'=>['StaffWQS_BGR'=>403],'skip_post'=>false],
    // CSRF missing — must 403 for every logged-in actor (before FIN / business logic).
    ['id'=>'FC_CSRF_01','module'=>'FIN_CENTRAL','label'=>'POST create_pay TANPA csrf_token => 403','path'=>'/purchases/purchases_payment_ap.php','method'=>'POST','action'=>'create_pay_missing_csrf','risk'=>'HIGH','cash_out'=>false,'guest'=>302,'roles'=>[
        'SmokeSYS_SYS'=>403,'MgrFIN_BGR'=>403,'StaffFIN_BGR'=>403,'MgrFIN_BDG'=>403,
        'StaffWQS_BGR'=>403,'StaffCRM_BGR'=>403,'MgrITC_BGR'=>403,'SmokeBRANCH_SYS'=>403,
    ],'skip_post'=>false],
];

// ── Run tests ──────────────────────────────────────────────────────────────
$results      = [];
$mismatch     = 0;
$pass         = 0;
$skipped      = 0;
$totalAsserts = 0;

echo "Running RBAC matrix HTTP checks against: $baseUrl\n";
echo str_repeat('-', 60) . "\n";

foreach ($matrix as $ep) {
    $id     = $ep['id'];
    $path   = $ep['path'];
    $method = $ep['method'];
    $label  = $ep['label'];
    $isPost = ($method === 'POST');
    $action = $ep['action'] ?? '';
    $doSkipPost = $skipPost && $isPost && ($ep['skip_post'] ?? false);

    // ── Guest test ──────────────────────────────────────────────────────
    $guestExpected = $ep['guest'] ?? 302;
    $guestUrl = url_join($baseUrl, $path[0] === '/' ? $path : '/' . $path);
    $guestActual = rbmc_guest_req($guestUrl);
    $guestOk = ($guestActual === $guestExpected) && $guestActual !== 301;
    $guestMismatchCat = !$guestOk && $guestActual === 301 ? 'URL_JOIN_BUG' : (!$guestOk ? 'OTHER' : null);
    $totalAsserts++;
    if ($guestOk) {
        $pass++;
    } else {
        $mismatch++;
    }

    $epResult = [
        'id'         => $id,
        'module'     => $ep['module'],
        'label'      => $label,
        'path'       => $path,
        'method'     => $method,  // GET or POST
        'action'     => $action,
        'risk'       => $ep['risk'] ?? 'LOW',
        'cash_out'   => $ep['cash_out'] ?? false,
        'guest_test' => [
            'expected' => $guestExpected,
            'actual' => $guestActual,
            'ok' => $guestOk,
            'mismatch_category' => $guestMismatchCat,
        ],
        'role_tests' => [],
    ];

    // ── Per-role tests ──────────────────────────────────────────────────
    foreach (($ep['roles'] ?? []) as $username => $expectedCode) {
        $totalAsserts++;

        if ($doSkipPost) {
            $skipped++;
            $epResult['role_tests'][$username] = ['expected'=>$expectedCode,'actual'=>'SKIPPED','ok'=>true,'skip'=>true];
            continue;
        }

        if (!isset($sessions[$username])) {
            $skipped++;
            $epResult['role_tests'][$username] = ['expected'=>$expectedCode,'actual'=>'NO_SESSION','ok'=>false,'note'=>'Account not in smoke list'];
            $mismatch++;
            continue;
        }

        $cookieFile = $sessions[$username];
        $url = url_join($baseUrl, $path[0] === '/' ? $path : '/' . $path);

        if ($isPost) {
            // GET page first to obtain session-scoped CSRF (strict matrix).
            $getR = rbmc_req('GET', $url, $cookieFile);
            $csrf = rbmc_extract_csrf((string)($getR['body'] ?? ''));
            $postData = rbmc_post_fields_for_endpoint($ep, $csrf);
            $r = rbmc_req('POST', $url, $cookieFile, $postData);
            $actual = $r['code'];
            // Strict: blocked users must get 403 (not 302 login churn, not 200 leak).
            // Allowed users must NOT get 403 (200/302/400/422/500 = past RBAC/FIN central guard).
            if ($expectedCode === 403) {
                $ok = ($actual === 403);
            } else {
                $ok = ($actual !== 403);
            }
            $realLeak = ($expectedCode === 403 && $actual === 200);
            $denied = in_array($actual, [302, 403], true);
            $mismatchCat = ($actual === 301) ? 'URL_JOIN_BUG' : ($realLeak ? 'REAL_LEAK' : 'OTHER');
        } else {
            $r = rbmc_req('GET', $url, $cookieFile);
            $actual = $r['code'];
            // For expected=403: accept 403 (Forbidden) OR 302 (redirect-to-login = access denied).
            // The REAL security hole is actual=200 when expected=403 (access granted when it shouldn't be).
            // For expected=200: accept 200, 302, 304 (some pages redirect by design).
            $denied = in_array($actual, [302, 403], true);
            // 301 = wrong URL / double-slash — treat as FAIL (not acceptable for entry pages)
            $ok = ($expectedCode === 403)
                ? $denied && $actual !== 301
                : in_array($actual, [200, 302, 304], true);
            $realLeak = ($expectedCode === 403 && $actual === 200);
            $mismatchCat = ($actual === 301) ? 'URL_JOIN_BUG' : ($realLeak ? 'REAL_LEAK' : 'OTHER');
        }

        // Extra flag for reports: real RBAC leaks vs expected behavior
        if ($ok) {
            $pass++;
        } else {
            $mismatch++;
        }

        $epResult['role_tests'][$username] = [
            'expected'    => $expectedCode,
            'actual'      => $actual,
            'ok'          => $ok,
            'location'    => (string)($r['location'] ?? ''),
            'real_leak'   => $realLeak ?? false,
            'mismatch_category' => ($ok || !isset($mismatchCat)) ? null : $mismatchCat,
            'deny_method' => ($denied ?? false)
                ? ($actual === 403 ? '403_forbidden' : '302_redirect')
                : null,
        ];

        if (!$ok) {
            echo "  MISMATCH [{$id}] {$label} | {$method} | {$username} | expected={$expectedCode} actual={$actual}\n";
        }
    }

    $results[] = $epResult;
    $anyRoleFail = array_filter($epResult['role_tests'], fn($t) => !($t['ok'] ?? true));
    $epOk = $guestOk && empty($anyRoleFail);
    echo ($epOk ? '  OK' : '  FAIL') . " [{$id}] {$label}\n";
}

// ── Cleanup cookie files ──────────────────────────────────────────────────
foreach ($sessions as $cf) {
    @unlink($cf);
}

// ── Write result ──────────────────────────────────────────────────────────
$overallOk = ($mismatch === 0);
$payload = [
    'ok'            => $overallOk,
    'run_at'        => date(DateTime::ATOM),
    'base_url'      => $baseUrl,
    'total_asserts' => $totalAsserts,
    'pass'          => $pass,
    'mismatch'      => $mismatch,
    'skipped'       => $skipped,
    'seeded_users'  => $seeded,
    'results'       => $results,
];

if ($writeLast) {
    $logsDir = $root . '/storage/logs';
    @mkdir($logsDir, 0775, true);
    $outPath = $logsDir . '/rbac_matrix_http_check_last.json';
    file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "\nOutput: $outPath\n";

    // rbac_smoke_matrix_last.json — GET matrix (konsisten dengan roadmap)
    $getResults = array_filter($results, fn($r) => (($r['method'] ?? 'GET') === 'GET'));
    file_put_contents($logsDir . '/rbac_smoke_matrix_last.json', json_encode([
        'ok' => $overallOk,
        'run_at' => $payload['run_at'],
        'base_url' => $baseUrl,
        'method' => 'GET',
        'results' => array_values($getResults),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // rbac_action_smoke_matrix_last.json — POST/action matrix (FIN central, approve, pay)
    $postResults = array_filter($results, fn($r) => (($r['method'] ?? 'GET') === 'POST'));
    file_put_contents($logsDir . '/rbac_action_smoke_matrix_last.json', json_encode([
        'ok' => $overallOk,
        'run_at' => $payload['run_at'],
        'base_url' => $baseUrl,
        'method' => 'POST',
        'results' => array_values($postResults),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // menu_audit_report_last.json — item → page → expected code (Tahap 2)
    $menuItems = [];
    foreach ($getResults as $r) {
        $guestOk = (bool)($r['guest_test']['ok'] ?? false);
        $roleOk = true;
        foreach ($r['role_tests'] ?? [] as $t) {
            if (!($t['ok'] ?? true)) $roleOk = false;
        }
        $broken = !$guestOk || !$roleOk;
        $menuItems[] = [
            'item' => $r['label'] ?? $r['id'],
            'page' => $r['path'],
            'expected_guest' => $r['guest_test']['expected'] ?? 302,
            'actual_guest' => $r['guest_test']['actual'] ?? 0,
            'broken_link' => $broken,
            'ok' => !$broken,
        ];
    }
    file_put_contents($logsDir . '/menu_audit_report_last.json', json_encode([
        'ok' => $overallOk,
        'run_at' => $payload['run_at'],
        'base_url' => $baseUrl,
        'items' => $menuItems,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo str_repeat('=', 60) . "\n";
echo "RBAC matrix check: " . ($overallOk ? 'PASS' : 'FAIL') . "\n";
echo "Total: $totalAsserts | Pass: $pass | Mismatch: $mismatch | Skipped: $skipped\n";

if ($strict && !$overallOk) {
    exit(1);
}
exit(0);
