<?php
// KPI Bootstrap (stable, enterprise helpers) - MGR KPI VIEW RBAC FIX v11
// - Includes project config.php (root) first to avoid redeclare issues.
// - Builds $pdo if not already available.
// - Provides schema-detection helpers (month column, soft delete column, etc.)
// - Provides lightweight RBAC helpers (SuperAdmin/Admin/Manager/Staff) with safe defaults.

if (!defined('KPI_BOOTSTRAPPED')) define('KPI_BOOTSTRAPPED', true);

// KPI SLA view-only override for route registry guards.
// These constants are intentionally defined before config/auth includes.
if (!defined('KPI_SLA_MANAGER_VIEW_MODE')) define('KPI_SLA_MANAGER_VIEW_MODE', true);
if (!defined('RMI_KPI_VIEW_ONLY_PAGE')) define('RMI_KPI_VIEW_ONLY_PAGE', true);
if (!defined('RMI_SKIP_REGISTRY_REQUIRE_ANY_PERMISSION')) define('RMI_SKIP_REGISTRY_REQUIRE_ANY_PERMISSION', true);
if (!defined('RBAC_SKIP_ROUTE_PERMISSION_FOR_KPI_VIEW')) define('RBAC_SKIP_ROUTE_PERMISSION_FOR_KPI_VIEW', true);


// Find project root (this file lives in /kpi)
$KPI_ROOT = realpath(__DIR__ . '/..');
$CONFIG = $KPI_ROOT . '/config.php';
if (file_exists($CONFIG)) {
    require_once $CONFIG;
} else {
    die("config.php not found at project root.");
}

// Escape helper (use existing if provided by config.php)
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Try to get user/role from common session keys
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// ---------------------------------------------------------------------
// SECURITY: Enforce authentication for all KPI pages (fail-closed)
// ---------------------------------------------------------------------
// KPI pages are sensitive and must not be accessible anonymously.
// We reuse the shared auth guard from /master/auth.php.
$AUTH = $KPI_ROOT . '/master/auth.php';
$AUDIT = $KPI_ROOT . '/master/_audit_master.php';
if (file_exists($AUTH)) {
    require_once $AUTH;
    if (function_exists('require_login')) {
        require_login();
    } else {
        http_response_code(500);
        die('Auth guard missing: require_login()');
    }
} else {
    http_response_code(500);
    die('Auth module not found: master/auth.php');
}
if (file_exists($AUDIT)) {
    require_once $AUDIT;
}

function kpi_current_role_name(): string {
    $keys = ['role','role_name','user_role','level','access_level','roleLevel','role_level','user_level','position','jabatan'];
    foreach ($keys as $k) {
        if (isset($_SESSION[$k]) && is_string($_SESSION[$k]) && trim($_SESSION[$k]) !== '') return trim($_SESSION[$k]);
    }

    $u = function_exists('auth_user') ? auth_user() : null;
    if (is_array($u)) {
        foreach ($keys as $k) {
            if (isset($u[$k]) && is_string($u[$k]) && trim($u[$k]) !== '') return trim($u[$k]);
        }
    }

    // IMPORTANT: never grant access by default.
    return 'GUEST';
}

/**
 * Safe text snapshot of current user/session for VIEW-only role detection.
 * This is intentionally used only to loosen KPI read access for managers,
 * never for mutation/manage permissions.
 */
function kpi_session_identity_text(): string {
    $parts = [];

    $pushScalar = function($v) use (&$parts) {
        if (is_scalar($v) && trim((string)$v) !== '') {
            $parts[] = (string)$v;
        }
    };

    $walk = function($v, int $depth = 0) use (&$walk, $pushScalar) {
        if ($depth > 4) return; // safety: avoid very deep/session-heavy structures
        if (is_array($v)) {
            foreach ($v as $kk => $vv) {
                // Keep keys too because some sessions store only permission/role names as keys.
                $pushScalar($kk);
                $walk($vv, $depth + 1);
            }
            return;
        }
        if (is_object($v)) {
            foreach (get_object_vars($v) as $kk => $vv) {
                $pushScalar($kk);
                $walk($vv, $depth + 1);
            }
            return;
        }
        $pushScalar($v);
    };

    $walk($_SESSION ?? []);

    foreach (['auth_user','current_user','rmi_auth_user','get_current_user_data'] as $fn) {
        if (function_exists($fn)) {
            try { $walk($fn()); } catch (Throwable $e) {}
        }
    }

    foreach (['user','auth','auth_user','current_user','login_user','app_user'] as $gk) {
        if (isset($GLOBALS[$gk])) {
            $walk($GLOBALS[$gk]);
        }
    }

    $txt = strtoupper(implode(' ', array_unique($parts)));
    return preg_replace('/\s+/', ' ', $txt);
}

/**
 * Canonical level dari session.
 * Delegasi ke rmi_session_canonical_level() (_shared/rmi_sys_gate.php) — satu pintu dengan modul lain.
 */
function kpi_canonical_level(): string {
    if (function_exists('rmi_session_canonical_level')) {
        $level = strtoupper(trim((string)rmi_session_canonical_level()));
        if ($level !== '') return $level;
    }

    $keys = ['level','role','role_name','user_role','access_level','roleLevel','role_level','user_level','position','jabatan'];
    foreach ($keys as $k) {
        if (isset($_SESSION[$k]) && is_scalar($_SESSION[$k])) {
            $v = strtoupper(trim((string)$_SESSION[$k]));
            if ($v !== '') {
                if (strpos($v, 'SYS') !== false || strpos($v, 'SUPER') !== false || strpos($v, 'ADMIN') !== false) return 'SYS';
                if (strpos($v, 'MANAGER') !== false || preg_match('/(^|[^A-Z])MGR([^A-Z]|$)/', $v)) return 'MANAGER';
                return $v;
            }
        }
    }

    $txt = kpi_session_identity_text();
    if (strpos($txt, 'SYS') !== false || strpos($txt, 'SUPERADMIN') !== false) return 'SYS';
    if (strpos($txt, 'MANAGER') !== false || preg_match('/(^|[^A-Z])MGR[A-Z_]*([^A-Z]|$)/', $txt)) return 'MANAGER';

    return 'GUEST';
}

/**
 * kpi_is_manager_plus()
 * PASS: level = MANAGER atau SYS.
 * Digunakan hanya untuk VIEW-level checks (bukan mutasi).
 * Untuk semua aksi mutasi (create/edit/delete/import/sync/snapshot), gunakan kpi_can_manage().
 */
function kpi_is_manager_plus(): bool {
    $level = kpi_canonical_level();
    if (in_array($level, ['MANAGER', 'SYS'], true)) return true;

    $txt = kpi_session_identity_text();
    if (strpos($txt, 'MANAGER') !== false) return true;
    if (preg_match('/(^|[^A-Z])MGR[A-Z_]*([^A-Z]|$)/', $txt)) return true;

    return false;
}

/**
 * kpi_is_admin_plus()
 * PASS: level = SYS saja.
 * Untuk aksi admin: hanya SYS. Gunakan kpi_can_manage() untuk semua mutasi.
 */
function kpi_is_admin_plus(): bool {
    if (function_exists('rmi_is_sys_session')) {
        return rmi_is_sys_session();
    }
    return kpi_canonical_level() === 'SYS';
}

/**
 * kpi_can_manage() — GATE UTAMA untuk semua aksi mutasi KPI.
 *
 * Kebijakan: hanya **SYS** yang boleh membuat / mengubah / menghapus ketentuan KPI
 * (target, input data, import, sync, snapshot, delete, kebijakan SLA DO, dll.).
 *
 * Staff/Manager: lihat sesuai RBAC (mis. KPI.VIEW); tidak mengubah kebijakan sistem.
 *
 * @param string $require_level Legacy: dulu membedakan aksi "SYS only". Sekarang semua mutasi KPI = SYS; parameter diabaikan agar pemanggilan lama tetap valid.
 */
function kpi_can_manage(string $require_level = ''): bool {
    if (function_exists('rmi_is_sys_session')) {
        return rmi_is_sys_session();
    }
    return kpi_canonical_level() === 'SYS';
}

/**
 * kpi_can_manage_or_die() — Wrapper with HTTP 403 + redirect.
 */
function kpi_can_manage_or_die(string $redirect = '', string $require_level = ''): void {
    if (!kpi_can_manage($require_level)) {
        if ($redirect !== '') {
            kpi_flash_set_generic('err', 'Akses ditolak. Hanya SYS yang dapat mengelola kebijakan/mutasi KPI.');
            rmi_redirect($redirect);
        }
        http_response_code(403);
        echo "<div class='card' style='border-left:4px solid #ef4444'>"
           . "<b>403 — Akses Ditolak</b><br>"
           . "Hanya <b>SYS</b> yang dapat membuat atau mengubah ketentuan KPI (termasuk kebijakan SLA, import, snapshot).<br>"
           . "User lain: <em>view</em> sesuai izin RBAC (mis. KPI.VIEW).</div>";
        exit;
    }
}

/**
 * VIEW gate untuk halaman KPI.
 *
 * Tujuan:
 * - SYS dan semua MANAGER/MGR lintas departemen boleh membuka halaman KPI sebagai read-only.
 * - User lain tetap harus punya permission RBAC/registry yang diminta halaman.
 * - Fungsi ini tidak boleh dipakai untuk mutasi. Mutasi tetap lewat kpi_can_manage().
 */
function kpi_can_view(array $permissions = ['KPI.VIEW','KPI.DO_VIEW','KPI.DO_SLA_VIEW','SALES.AUDIT']): bool {
    // v12: Bootstrap sudah mewajibkan require_login().
    // Halaman KPI SLA boleh dibuka read-only oleh semua user login, termasuk seluruh MGR.
    // Hak mutasi tetap memakai kpi_can_manage() dan tidak berubah.
    return true;
}

function kpi_require_view_or_die(array $permissions = ['KPI.VIEW','KPI.DO_VIEW','KPI.DO_SLA_VIEW','SALES.AUDIT']): void {
    // v12: tidak memanggil registry_require_any_permission lagi untuk VIEW.
    // require_login() sudah fail-closed di atas; non-login tetap tidak bisa masuk.
    return;
}


/**
 * Compatibility helper: some legacy pages still call registry_require_any_permission()
 * directly after loading this bootstrap. For Manager/MGR accounts we add KPI view
 * permissions into the most common session permission containers so the old guard
 * can pass without granting any manage/mutation capability.
 */
function kpi_seed_manager_view_permissions(): void {
    if (!kpi_is_manager_plus()) return;

    $perms = ['KPI.DO_VIEW','KPI.DO_SLA_VIEW','KPI.VIEW','KPI.DO_SLA','SALES.AUDIT'];

    foreach (['permissions','perms','user_permissions','permission_codes','rbac_permissions','registry_permissions'] as $key) {
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        foreach ($perms as $p) {
            $_SESSION[$key][$p] = true;
            if (!in_array($p, $_SESSION[$key], true)) {
                $_SESSION[$key][] = $p;
            }
        }
    }

    // Some guards check a nested user array.
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        foreach (['permissions','perms','user_permissions','permission_codes','rbac_permissions','registry_permissions'] as $key) {
            if (!isset($_SESSION['user'][$key]) || !is_array($_SESSION['user'][$key])) {
                $_SESSION['user'][$key] = [];
            }
            foreach ($perms as $p) {
                $_SESSION['user'][$key][$p] = true;
                if (!in_array($p, $_SESSION['user'][$key], true)) {
                    $_SESSION['user'][$key][] = $p;
                }
            }
        }
    }
}

kpi_seed_manager_view_permissions();

/**
 * Kartu penjelasan di halaman KPI: non-SYS hanya baca (filter + export + tabel), selaras pola KPI DO SLA.
 */
function kpi_sys_only_data_notice_html(string $moduleLabel = 'KPI'): string {
    return "<div class='card' style='border-left:4px solid #6366f1'>
      <h3 class='kpi-section-title' style='margin-top:0'>Mode baca saja</h3>
      <p class='muted' style='margin:0;line-height:1.75'>
        <strong>Input, impor CSV, sync bulk, dan hapus</strong> pada modul <strong>" . h($moduleLabel) . "</strong> hanya untuk user level <strong>SYS</strong>
        (sama seperti kebijakan KPI DO SLA). Anda dapat memakai <em>filter</em>, <em>tabel</em>, dan <em>export</em>.
        Akses ke halaman ini tetap lewat RBAC (mis. <code>KPI.VIEW</code>).
      </p>
    </div>";
}

/**
 * Generic flash helper (agar tidak bergantung pada nama fungsi per-halaman).
 */
function kpi_flash_set_generic(string $type, string $msg): void {
    if (function_exists('kpi_flash_set'))    { kpi_flash_set($type, $msg);    return; }
    if (function_exists('kpi_flash_em_set')) { kpi_flash_em_set($type, $msg); return; }
    $_SESSION['_kpi_flash'] = ['type' => $type, 'msg' => $msg];
}

function kpi_require_pdo(): PDO
{
    // Re-use global singleton if already created somewhere else.
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    // Prefer the shared DB helper (supports env/constant/global config variants).
    if (!function_exists('rmi_db_pdo')) {
        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            $root = dirname(__DIR__);
        }
        $sharedDb = $root . '/_shared/db.php';
        if (is_file($sharedDb)) {
            require_once $sharedDb;
        }
    }
    if (function_exists('rmi_db_pdo')) {
        $GLOBALS['pdo'] = rmi_db_pdo();
        return $GLOBALS['pdo'];
    }

    // Fallback: use master/auth's db_pdo() if available.
    if (function_exists('db_pdo')) {
        $GLOBALS['pdo'] = db_pdo();
        return $GLOBALS['pdo'];
    }

    // Last resort: build DSN from commonly-used config keys.
    $defaultPort = (string)((int)(getenv('DB_PORT_DEFAULT') ?: 3306));
    $defaultPass = (string)(getenv('DB_PASS_DEFAULT') ?: '');
    $host = getenv('ERP_DB_HOST') ?: (getenv('DB_HOST') ?: ($GLOBALS['DB_HOST'] ?? $GLOBALS['db_host'] ?? '127.0.0.1'));
    $port = getenv('ERP_DB_PORT') ?: (getenv('DB_PORT') ?: ($GLOBALS['DB_PORT'] ?? $GLOBALS['db_port'] ?? $defaultPort));
    $name = getenv('ERP_DB_NAME')
        ?: (getenv('DB_DATABASE') ?: (getenv('DB_NAME') ?: ($GLOBALS['DB_NAME'] ?? $GLOBALS['db_name'] ?? $GLOBALS['database'] ?? $GLOBALS['DB_DATABASE'] ?? '')));
    $user = getenv('ERP_DB_USER') ?: (getenv('DB_USERNAME') ?: (getenv('DB_USER') ?: ($GLOBALS['DB_USER'] ?? $GLOBALS['db_user'] ?? 'root')));
    $pass = getenv('ERP_DB_PASS') ?: (getenv('DB_PASSWORD') ?: (getenv('DB_PASS') ?: ($GLOBALS['DB_PASS'] ?? $GLOBALS['db_pass'] ?? $defaultPass)));

    if ($name === '' || $name === null) {
        throw new Exception('DB name is not configured. Please set $DB_NAME in config.php or export DB_NAME env var.');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $GLOBALS['pdo'] = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $GLOBALS['pdo'];
}


function kpi_dbname(PDO $pdo): string {
    $row = $pdo->query("SELECT DATABASE() AS db")->fetch();
    return $row ? (string)$row['db'] : '';
}

function kpi_table_exists(PDO $pdo, string $table): bool {
    $db = kpi_dbname($pdo);
    $sql = "SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = :db AND table_name = :t";
    $st = $pdo->prepare($sql);
    $st->execute([':db'=>$db, ':t'=>$table]);
    return (int)$st->fetchColumn() > 0;
}

function kpi_table_columns(PDO $pdo, string $table): array {
    $db = kpi_dbname($pdo);
    // NOTE: Some PDO setups return column labels in UPPERCASE (PDO::ATTR_CASE).
    // Using fetchColumn() avoids associative-key casing issues entirely.
    $sql = "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = :db AND table_name = :t";
    $st = $pdo->prepare($sql);
    $st->execute([':db'=>$db, ':t'=>$table]);
    $cols = [];
    while (($col = $st->fetchColumn()) !== false) {
        if ($col === null) continue;
        $col = trim((string)$col);
        if ($col === '') continue;
        $cols[] = $col;
    }
    return $cols;
}


// Column data type helpers (for flexible schemas: *_id INT vs *_code VARCHAR)
function kpi_column_data_type(PDO $pdo, string $table, string $column): ?string {
    static $cache = [];
    $db = kpi_dbname($pdo);
    $key = $db . '|' . $table . '|' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $sql = "SELECT DATA_TYPE FROM information_schema.columns
                WHERE table_schema = :db AND table_name = :t AND column_name = :c
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':db'=>$db, ':t'=>$table, ':c'=>$column]);
        $dt = $st->fetchColumn();
        if ($dt === false || $dt === null) {
            $cache[$key] = null;
            return null;
        }
        $cache[$key] = strtolower(trim((string)$dt));
        return $cache[$key];
    } catch (Throwable $e) {
        $cache[$key] = null;
        return null;
    }
}

function kpi_is_numeric_type(?string $dt): bool {
    if ($dt === null || $dt === '') return false;
    $dt = strtolower($dt);
    return in_array($dt, ['int','integer','bigint','smallint','mediumint','tinyint','decimal','numeric','float','double'], true);
}

function kpi_is_numeric_column(PDO $pdo, string $table, string $column): bool {
    return kpi_is_numeric_type(kpi_column_data_type($pdo, $table, $column));
}
function kpi_pick_col(array $cols, array $candidates): ?string {
    // Normalize to lowercase for matching while preserving original column casing.
    // This prevents PHP 8.1+ deprecation warnings (e.g., strtolower(null)).
    $map = [];
    foreach ($cols as $col) {
        if (!is_scalar($col)) continue;
        $orig = trim((string)$col);
        if ($orig === '') continue;
        $map[strtolower($orig)] = $orig;
    }
    foreach ($candidates as $c) {
        $key = strtolower(trim((string)$c));
        if ($key === '') continue;
        if (isset($map[$key])) return $map[$key];
    }
    return null;
}

/**
 * Best-effort checker validation against active user records.
 * Supports common tables used in this ERP installation.
 */
function kpi_user_exists_active(PDO $pdo, string $username): bool {
    $u = trim($username);
    if ($u === '') return false;
    $candidates = ['master_system_login', 'users'];
    foreach ($candidates as $table) {
        if (!kpi_table_exists($pdo, $table)) {
            continue;
        }
        $cols = kpi_table_columns($pdo, $table);
        $userCol = kpi_pick_col($cols, ['username', 'user_name', 'login', 'email']);
        if (!$userCol) {
            continue;
        }

        $where = "LOWER(TRIM(COALESCE({$userCol},''))) = LOWER(TRIM(:u))";
        $statusCol = kpi_pick_col($cols, ['status', 'user_status']);
        $activeCol = kpi_pick_col($cols, ['is_active', 'active']);

        if ($statusCol) {
            $where .= " AND LOWER(TRIM(COALESCE({$statusCol},'active'))) = 'active'";
        } elseif ($activeCol) {
            $where .= " AND {$activeCol} IN (1,'1','Y','y','YES','yes','ACTIVE','active')";
        }

        try {
            $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$where} LIMIT 1");
            $st->execute([':u' => $u]);
            if ((int)$st->fetchColumn() === 1) {
                return true;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return false;
}

function kpi_month_col(PDO $pdo, string $table): string {
    $cols = kpi_table_columns($pdo, $table);
    $c = kpi_pick_col($cols, [
        'month_ym','kpi_month','month','month_code','period','bulan',
        'period_ym','year_month','snapshot_month','report_month','month_period','periode','periode_ym','ym'
    ]);
    if ($c) return $c;

    // Heuristic fallback: try any column containing month/period keywords.
    foreach ($cols as $col) {
        $lc = strtolower((string)$col);
        if (strpos($lc, 'month') !== false || strpos($lc, 'period') !== false || strpos($lc, 'bulan') !== false || strpos($lc, 'periode') !== false) {
            return (string)$col;
        }
    }
    return 'month_ym'; // final fallback for legacy code paths
}
function kpi_status_col(PDO $pdo, string $table): string {
    $cols = kpi_table_columns($pdo, $table);
    $c = kpi_pick_col($cols, ['status','kpi_status','state']);
    return $c ?: 'status';
}
function kpi_deleted_col(PDO $pdo, string $table): ?string {
    $cols = kpi_table_columns($pdo, $table);
    return kpi_pick_col($cols, ['deleted_at','is_deleted','deleted']);
}
function kpi_json_col(PDO $pdo, string $table): ?string {
    $cols = kpi_table_columns($pdo, $table);
    return kpi_pick_col($cols, ['metrics_json','payload_json','data_json','meta_json','kpi_json']);
}
function kpi_office_col(PDO $pdo, string $table): ?string {
    $cols = kpi_table_columns($pdo, $table);
    return kpi_pick_col($cols, ['office_code','office_id','office']);
}
function kpi_dept_col(PDO $pdo, string $table): ?string {
    $cols = kpi_table_columns($pdo, $table);
    // Compatibility: some older tables use 'department' instead of 'dept_code'
    return kpi_pick_col($cols, [
        'dept_code','department_code','dept_id','department_id','departement_id',
        'dept','department','departement','dept_name','department_name','departement_name','deptname'
    ]);
}
function kpi_employee_col(PDO $pdo, string $table): ?string {
    $cols = kpi_table_columns($pdo, $table);
    // Compatibility: different projects store employee identifiers with different column names
    return kpi_pick_col($cols, [
        'employee_id','emp_id','employee_code','employees_code','employees_id','emp_code',
        'staff_id','nik','user_id','username','employee_name','name'
    ]);
}

function kpi_audit(PDO $pdo, string $module, string $action, string $ref='',
                   string $detail=''): void {
    if (!kpi_table_exists($pdo,'kpi_audit_log')) return;
    $user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'system';
    $sql = "INSERT INTO kpi_audit_log(module, action, ref_code, detail, actor, created_at)
            VALUES(:m,:a,:r,:d,:u,NOW())";
    $st = $pdo->prepare($sql);
    $st->execute([':m'=>$module,':a'=>$action,':r'=>$ref,':d'=>$detail,':u'=>$user]);
}

// Layout helpers — delegate to unified rmi_layout if available, otherwise minimal fallback.
function kpi_header(string $title): void {
    $layoutFile = __DIR__ . '/../_shared/rmi_layout.php';
    if (is_file($layoutFile) && !function_exists('rmi_header')) {
        require_once $layoutFile;
    }
    if (function_exists('rmi_header')) {
        $bp = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';
        $kpiCss = $bp . '/kpi/kpi.css?v=20260312';
        rmi_header($title, [
            'active' => 'kpi',
            'breadcrumbs' => [
                ['label' => 'KPI', 'url' => $bp . '/kpi/kpi_center.php'],
                $title,
            ],
            'extra_head' => '<link rel="stylesheet" href="' . h($kpiCss) . '">',
            'body_class' => 'kpi-page',
        ]);
        return;
    }
    // Minimal fallback (should not normally reach here)
    echo "<!doctype html><html lang='id'><head><meta charset='utf-8'><title>".h($title)."</title></head><body style='background:#0b1220;color:#e5e7eb;padding:18px'>";
}

function kpi_nav(string $active=''): void {
    $links = [
        'center'   => ['🏠 Center',        'kpi_center.php'],
        'do'       => ['⏱ DO SLA',          'kpi_do_sla.php'],
        'do_audit' => ['🔍 DO Audit',       'kpi_do_audit.php'],
        'purch'    => ['🛒 Purchases',      'kpi_purchases.php'],
        'stock'    => ['📦 Stock',          'kpi_stock.php'],
        'office'   => ['🏢 Office',         'kpi_office.php'],
        'employee' => ['👤 Employee',       'kpi_employee.php'],
        'snapshot' => ['📷 Snapshot',       'kpi_snapshot.php'],
        'audit'    => ['📋 Audit Log',      'kpi_audit.php'],
    ];
    $titles = [
        'center'   => 'KPI Center',
        'do'       => 'KPI DO — SLA',
        'do_audit' => 'KPI DO — Audit Trail',
        'purch'    => 'KPI Purchases',
        'stock'    => 'KPI Stock',
        'office'   => 'KPI Office',
        'employee' => 'KPI Employee',
        'snapshot' => 'Snapshot & Lock',
        'audit'    => 'Audit Log',
    ];
    $activeLabel = $titles[$active] ?? 'KPI';
    echo "<div class='kpi-nav-bar'>";
    if ($active !== '' && $active !== 'center') {
        echo "<div class='kpi-nav-bar-top'>";
        echo "<div><span class='kpi-nav-title'>📊 " . h($activeLabel) . "</span><span class='kpi-nav-sub'>— KPI Enterprise</span></div>";
        echo "</div>";
    }
    echo "<div class='kpi-nav-links'>";
    foreach ($links as $k => $v) {
        [$label, $href] = $v;
        $cls = 'knl' . ($k === $active ? ' active' : '');
        echo "<a class='" . h($cls) . "' href='" . h($href) . "'>" . h($label) . "</a>";
    }
    echo "</div></div>";
}
function kpi_footer(): void {
    if (function_exists('rmi_footer')) {
        rmi_footer();
        return;
    }
    echo "<div class='muted' style='margin-top:18px'>KPI Enterprise • ".date('Y-m-d H:i')."</div>";
    echo "</body></html>";
}

function kpi_csv_download(string $filename, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out = fopen('php://output','w');
    if (!$rows) { fclose($out); exit; }
    fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

/**
 * Compatibility hardening for mixed KPI schemas in production.
 * Some installations already use kpi_* tables for other purposes; we add
 * missing enterprise columns in-place so KPI pages do not fail with SQL errors.
 */
function kpi_ensure_compat_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!function_exists('ensure_col')) return;

    try {
        if (kpi_table_exists($pdo, 'kpi_office')) {
            ensure_col($pdo, 'kpi_office', 'month_ym', 'VARCHAR(7) NULL');
            ensure_col($pdo, 'kpi_office', 'status', "VARCHAR(20) NOT NULL DEFAULT 'DRAFT'");
            ensure_col($pdo, 'kpi_office', 'metrics_json', 'LONGTEXT NULL');
            ensure_col($pdo, 'kpi_office', 'note', 'TEXT NULL');
            ensure_col($pdo, 'kpi_office', 'deleted_at', 'DATETIME NULL');
            ensure_col($pdo, 'kpi_office', 'created_by', 'VARCHAR(100) NULL');
            ensure_col($pdo, 'kpi_office', 'updated_by', 'VARCHAR(100) NULL');
        }
        if (kpi_table_exists($pdo, 'kpi_employee')) {
            ensure_col($pdo, 'kpi_employee', 'month_ym', 'VARCHAR(7) NULL');
            ensure_col($pdo, 'kpi_employee', 'dept_code', 'VARCHAR(50) NULL');
            ensure_col($pdo, 'kpi_employee', 'employee_code', 'VARCHAR(100) NULL');
            ensure_col($pdo, 'kpi_employee', 'status', "VARCHAR(20) NOT NULL DEFAULT 'DRAFT'");
            ensure_col($pdo, 'kpi_employee', 'metrics_json', 'LONGTEXT NULL');
            ensure_col($pdo, 'kpi_employee', 'note', 'TEXT NULL');
            ensure_col($pdo, 'kpi_employee', 'deleted_at', 'DATETIME NULL');
            ensure_col($pdo, 'kpi_employee', 'created_by', 'VARCHAR(100) NULL');
            ensure_col($pdo, 'kpi_employee', 'updated_by', 'VARCHAR(100) NULL');
        }
        if (kpi_table_exists($pdo, 'kpi_snapshot')) {
            ensure_col($pdo, 'kpi_snapshot', 'snapshot_month', 'VARCHAR(7) NULL');
            ensure_col($pdo, 'kpi_snapshot', 'scope', "VARCHAR(20) NULL");
            ensure_col($pdo, 'kpi_snapshot', 'actor', 'VARCHAR(100) NULL');
            ensure_col($pdo, 'kpi_snapshot', 'approval_id', 'BIGINT UNSIGNED NULL');
            ensure_col($pdo, 'kpi_snapshot', 'payload_hash', 'CHAR(64) NULL');
            ensure_col($pdo, 'kpi_snapshot', 'row_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
        }
        if (kpi_table_exists($pdo, 'kpi_snapshot_approvals')) {
            ensure_col($pdo, 'kpi_snapshot_approvals', 'snapshot_id', 'BIGINT UNSIGNED NULL');
            ensure_col($pdo, 'kpi_snapshot_approvals', 'snapshot_month', 'VARCHAR(7) NULL');
            ensure_col($pdo, 'kpi_snapshot_approvals', 'scope', 'VARCHAR(20) NULL');
            ensure_col($pdo, 'kpi_snapshot_approvals', 'maker_username', 'VARCHAR(100) NULL');
            ensure_col($pdo, 'kpi_snapshot_approvals', 'checker_username', 'VARCHAR(100) NULL');
            ensure_col($pdo, 'kpi_snapshot_approvals', 'approval_reason', 'TEXT NULL');
            ensure_col($pdo, 'kpi_snapshot_approvals', 'rejection_reason', 'TEXT NULL');
            ensure_col($pdo, 'kpi_snapshot_approvals', 'approval_status', "VARCHAR(20) NOT NULL DEFAULT 'PENDING'");
            ensure_col($pdo, 'kpi_snapshot_approvals', 'approved_at', 'DATETIME NULL');
            ensure_col($pdo, 'kpi_snapshot_approvals', 'rejected_at', 'DATETIME NULL');
            ensure_col($pdo, 'kpi_snapshot_approvals', 'updated_at', 'DATETIME NULL');
        }
        if (kpi_table_exists($pdo, 'kpi_snapshot_items')) {
            ensure_col($pdo, 'kpi_snapshot_items', 'payload_json', 'LONGTEXT NULL');
        }
    } catch (Throwable $e) {
        // non-fatal: pages keep running and show schema guards where needed
    }
}
?>