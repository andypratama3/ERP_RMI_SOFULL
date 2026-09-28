<?php
/**
 * dashboards/_dashboard_bootstrap.php
 * Bootstrap untuk semua dashboard (owner & manager).
 *
 * Tujuan:
 * - Dashboard bisa diletakkan di folder /dashboards/...
 * - Include ke /master & /kpi tetap aman (tanpa path relatif rapuh)
 * - BASE_PROJECT URL tetap benar walau project dipasang di subfolder
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// --- RBAC (Accurate-like) untuk dashboards ---
// Semua dashboard wajib login.
// Akses dashboard ditentukan oleh folder /dashboards/{section}/...
// - SYS/SUPERADMIN/ADMIN: akses semua dashboard
// - MANAGER: akses view dashboard (read-only)
// - Role departemen: akses dashboard departemennya
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/_manager_scope.php';
require_login();

$__script = $_SERVER['SCRIPT_NAME'] ?? '';
$__section = '';
if (preg_match('~\/dashboards\/([^\/]+)\/~', $__script, $__m)) {
    $__section = strtolower($__m[1]);
}

$__base = ['SYS','SUPERADMIN','ADMIN']; // SYS = System Admin, akses penuh
$__viewer  = ['MANAGER','STAFF']; // manager/staff boleh view dashboard sesuai scope
$__allowed = array_merge($__base, $__viewer);

switch ($__section) {
    case 'owner':
        $__allowed = array_merge($__base, $__viewer);
        break;
    case 'sales':
        $__allowed = array_merge($__base, $__viewer, ['CRM','BRANCH']);
        break;
    case 'warehouse':
        $__allowed = array_merge($__base, $__viewer, ['WQS','BRANCH']);
        break;
    case 'procurement':
    case 'purchases':
        $__allowed = array_merge($__base, $__viewer, ['PQP','SCM','ACT','FIN','WQS','BRANCH']);
        break;
    case 'finance':
        $__allowed = array_merge($__base, $__viewer, ['FIN','ACT','BRANCH']);
        break;
    case 'regulatory':
        // Tidak ada departemen REG terpisah. Sesuai struktur user:
        // HRL_REG_ALKES = PQP + HRL + FIN (dokumen & control tower)
        $__allowed = array_merge($__base, $__viewer, ['PQP','HRL','FIN','ACT','BRANCH']);
        break;
    case 'quality':
        // Tidak ada departemen QA terpisah. Quality masuk ke PQP dan terkait WQS (incoming) / SCM (delivery).
        $__allowed = array_merge($__base, $__viewer, ['PQP','WQS','SCM','ACT','BRANCH']);
        break;
    case 'scm':
        // SCM dashboard bukan dashboard lintas-departemen.
        // WQS berhenti di READY SCM; proses ON DELIVERY/DELIVERED adalah tanggung jawab SCM.
        $__allowed = array_merge($__base, ['SCM','BRANCH']);
        break;
    case 'hrl':
        $__allowed = array_merge($__base, $__viewer, ['HRL','BRANCH']);
        break;
    case 'itc':
        $__allowed = array_merge($__base, $__viewer, ['ITC']);
        break;
    case 'act':
        $__allowed = array_merge($__base, $__viewer, ['ACT', 'FIN','BRANCH']);
        break;
    case 'branch':
        $__allowed = array_merge($__base, $__viewer, ['BRANCH']);
        break;
    default:
        // default: treat as dashboard umum (owner/manager)
        $__allowed = array_merge($__base, $__viewer);
        break;
}

// RBAC: Admin selalu allow. Section-specific (finance, act, scm, dll) HARUS punya permission dari RBAC Center.
// Role/dept allow list TIDAK dipakai untuk section-specific — agar pengaturan RBAC Center dihormati.
$__sectionPerm = [
    'scm' => 'DASHBOARD.SCM_VIEW',
    'sales' => 'DASHBOARD.SALES_VIEW',
    'warehouse' => 'DASHBOARD.WAREHOUSE_VIEW',
    'finance' => 'DASHBOARD.FINANCE_VIEW',
    'procurement' => 'DASHBOARD.PROCUREMENT_VIEW',
    'purchases' => 'DASHBOARD.PROCUREMENT_VIEW',
    'regulatory' => 'DASHBOARD.REGULATORY_VIEW',
    'quality' => 'DASHBOARD.QUALITY_VIEW',
    'hrl' => 'DASHBOARD.HRL_VIEW',
    'itc' => 'DASHBOARD.ITC_VIEW',
    'act' => 'DASHBOARD.ACT_VIEW',
    'branch' => 'DASHBOARD.BRANCH_VIEW',
    'owner' => 'DASHBOARD.OWNER_VIEW',
];
$__permToCheck = $__sectionPerm[$__section] ?? null;
$__role = function_exists('auth_role') ? strtoupper(auth_role()) : strtoupper(trim((string)($_SESSION['role'] ?? $_SESSION['level'] ?? '')));
$__level = function_exists('auth_level') ? strtoupper(auth_level()) : strtoupper(trim((string)($_SESSION['level'] ?? '')));
$__dept = function_exists('auth_dept') ? strtoupper(auth_dept()) : strtoupper(trim((string)($_SESSION['department'] ?? $_SESSION['dept'] ?? '')));
$__allowedUpper = array_map('strtoupper', $__allowed);

/* Rekap Sales DO adalah shared Sales/CRM meski file fisiknya di folder finance. */
$__scriptPath = str_replace('\\\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$__isSalesDoRekap = (bool)preg_match('~/dashboards/finance/sales_do_rekap\\.php$~i', $__scriptPath);
$__isCrmManagerSalesRekap = $__isSalesDoRekap && $__dept === 'CRM'
    && (in_array($__level, ['MANAGER','MGR'], true) || in_array($__role, ['MANAGER','MGR'], true));

if (auth_is_admin()) {
    $roleOk = true;
} elseif ($__isCrmManagerSalesRekap) {
    $roleOk = true;
} elseif ($__permToCheck && function_exists('can_any')) {
    // Section-specific: permission RBAC adalah sumber utama.
    // Fallback department hanya untuk department yang memang memiliki dashboard tersebut.
    // Jangan gunakan role STAFF/MANAGER sebagai fallback universal karena dapat membuka
    // dashboard departemen lain (contoh: WQS STAFF masuk SCM).
    $roleOk = can_any([$__permToCheck])
        || in_array($__dept, $__allowedUpper, true);
} else {
    // Section umum (default/owner): fallback ke DASHBOARD.VIEW atau role/dept
    $roleOk = (function_exists('can_any') && can_any(['DASHBOARD.VIEW']))
        || in_array($__role, $__allowedUpper, true)
        || in_array($__dept, $__allowedUpper, true);
}
if (!$roleOk) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}



/**
 * Cari root project berdasarkan struktur folder.
 * Root = folder yang punya config.php + folder master + folder kpi.
 */
function erp_find_root(string $startDir): string {
    $dir = realpath($startDir) ?: $startDir;
    for ($i = 0; $i < 10; $i++) {
        if (file_exists($dir . '/config.php') && is_dir($dir . '/master') && is_dir($dir . '/kpi')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return realpath($startDir) ?: $startDir;
}

/**
 * Ambil base URL path project (untuk link antar modul).
 * Contoh hasil: "" atau "/ERP_RMI_SOFULL" tergantung folder deploy.
 */
function erp_base_project_url(): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    if (!$sn) return '';
    $sn = str_replace('\\', '/', $sn);

    // marker path yang biasanya ada di ERP ini
    $markers = [
        '/dashboards/', '/kpi/', '/sales/', '/purchases/', '/stock/', '/master/',
        '/payroll/', '/absensi/', '/Fixed_Asset/', '/hrl_reg_alkes/'
    ];
    foreach ($markers as $m) {
        $pos = strpos($sn, $m);
        if ($pos !== false) {
            return rtrim(substr($sn, 0, $pos), '/');
        }
    }

    // fallback: asumsikan script 2 level di bawah root
    $scriptDir = rtrim(dirname($sn), '/');
    return rtrim(dirname($scriptDir), '/');
}

$ERP_ROOT = erp_find_root(__DIR__);
$BASE_PROJECT = erp_base_project_url();

$GLOBALS['ERP_ROOT'] = $ERP_ROOT;
$GLOBALS['BASE_PROJECT'] = $BASE_PROJECT;

// --- Auth (optional but recommended) ---
$auth = $ERP_ROOT . '/master/auth.php';
if (file_exists($auth)) {
    require_once $auth;

    // auth.php menghitung BASE_PROJECT dari folder "/master", jadi kalau file ada di /dashboards/
    // link login bisa salah. Kita override agar selalu benar.
    $GLOBALS['BASE_PROJECT']   = $BASE_PROJECT;
    $GLOBALS['AUTH_LOGIN_URL'] = $BASE_PROJECT . '/master/login.php';
    $GLOBALS['AUTH_LOGOUT_URL']= $BASE_PROJECT . '/master/logout.php';
}

// --- KPI Policy + PDO (stable helper dari repo) ---
$kpiPolicy = $ERP_ROOT . '/kpi/_kpi_policy.php';
if (!file_exists($kpiPolicy)) {
    http_response_code(500);
    die('Missing KPI policy: ' . htmlspecialchars($kpiPolicy));
}
require_once $kpiPolicy;

// ---------------------------------------------------------------------
// KPI URL helpers (konsisten untuk semua dashboard)
// ---------------------------------------------------------------------
if (!function_exists('erp_kpi_center_url')) {
    function erp_kpi_center_url(): string {
        $bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
        return rtrim($bp, '/') . '/kpi/kpi_center.php';
    }
}
if (!function_exists('erp_kpi_daily_url')) {
    function erp_kpi_daily_url(): string {
        $bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
        return rtrim($bp, '/') . '/kpi/kpi_dashboard_daily.php';
    }
}
if (!function_exists('erp_kpi_monthly_url')) {
    function erp_kpi_monthly_url(): string {
        $bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
        return rtrim($bp, '/') . '/kpi/kpi_dashboard_monthly.php';
    }
}

// ---------------------------------------------------------------------
// Compat helpers
// ---------------------------------------------------------------------
// Beberapa halaman dashboard memakai helper bernama safe_table_exists().
// Di repo, cek table yang aman disediakan oleh kpi_policy_table_exists().
// Untuk menjaga kompatibilitas lintas halaman (owner summary, quality, dll),
// sediakan wrapper ini di bootstrap dashboard.
if (!function_exists('safe_table_exists')) {
    function safe_table_exists($pdo, string $tableName): bool
    {
        try {
            return kpi_policy_table_exists($pdo, $tableName);
        } catch (Throwable $e) {
            return false;
        }
    }
}

try {
    $pdo = kpi_require_pdo();
    $GLOBALS['pdo'] = $pdo;
} catch (Throwable $e) {
    http_response_code(500);
    die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
}

// Optional: shared dashboard helpers
$kpiLib = $ERP_ROOT . '/kpi/kpi_lib.php';
if (file_exists($kpiLib)) {
    require_once $kpiLib;
}
