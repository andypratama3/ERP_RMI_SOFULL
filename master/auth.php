<?php
/**
 * master/auth.php
 *
 * Auth + RBAC helpers (dept-based) + compatibility.
 *
 * Patch goals:
 * - Restore $BASE_PROJECT (some pages still use it).
 * - Provide auth_user(), auth_pdo(), and up() (needed by WQS patches).
 * - Keep backward compatibility with require_login(), require_role(), require_level().
 */

if (session_status() === PHP_SESSION_NONE) {
  // Set cookie params hanya jika session belum pernah dibuka.
  // Jika bootstrap.php sudah memanggil session_start() terlebih dahulu,
  // blok ini dilewati dan params dari bootstrap.php yang berlaku.
  //
  // Cookie: lifetime 0 = session cookie (tutup browser → biasanya hilang).
  // Idle timeout sebenarnya di server: lihat auth_session_idle_enforce() + .env RMI_SESSION_IDLE_*.
  // session_regenerate_id setelah login sukses: auth_session_mark_login_complete() dipanggil dari
  // master/login.php (login_finalize_session) dan master/mfa_verify.php — bukan di blok ini.
  $__isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
             || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
  // Umur file sesi di server (detik) — harus ≥ timeout idle SYS (default 3600). Env bisa di-set sebelum session_start.
  $__gc = (int)(getenv('RMI_SESSION_GC_MAXLIFETIME') ?: 0);
  if ($__gc < 3600) {
    $__gc = 7200;
  }
  @ini_set('session.gc_maxlifetime', (string)$__gc);
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $__isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  @session_start();
}
require_once __DIR__ . '/../_shared/app_init.php';

// --- ENV + Error handler (optional) ---
$__env = __DIR__ . '/../_shared/env.php';
if (is_file($__env)) {
  require_once $__env;
  if (function_exists('rmi_env_load')) { rmi_env_load(); }
}
if (!defined('APP_ENV')) {
  $v = getenv('APP_ENV');
  if ($v === false || trim((string)$v) === '') {
    $v = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null;
  }
  if (($v === null || trim((string)$v) === '') && function_exists('rmi_env_load')) {
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $envPath = $root . DIRECTORY_SEPARATOR . '.env';
    if (is_file($envPath) && is_readable($envPath)) {
      $lines = @file($envPath, FILE_IGNORE_NEW_LINES);
      foreach ($lines ?: [] as $line) {
        $line = trim((string)$line);
        if ($line !== '' && strpos($line, '=') !== false && ($line[0] ?? '') !== '#') {
          [$k, $val] = array_pad(explode('=', $line, 2), 2, '');
          if (trim($k) === 'APP_ENV' && trim($val) !== '') {
            $v = trim(trim($val), '"\'');
            break;
          }
        }
      }
    }
  }
  if ($v === null || trim((string)$v) === '') { $v = 'local'; }
  define('APP_ENV', trim((string)$v));
}
if (!defined('APP_DEBUG')) {
  $dbg = getenv('APP_DEBUG');
  if ($dbg === false || trim((string)$dbg) === '') { $dbg = (APP_ENV !== 'production'); }
  $dbg = in_array(strtolower(trim((string)$dbg)), ['1','true','yes','on'], true);
  define('APP_DEBUG', $dbg);
}
$__logger = __DIR__ . '/../_shared/logger.php';
if (is_file($__logger)) { require_once $__logger; }
$__relg = __DIR__ . '/../_shared/rmi_error_logger.php';
if (is_file($__relg)) { require_once $__relg; }
$__eh = __DIR__ . '/../_shared/error_handler.php';
if (is_file($__eh)) {
  require_once $__eh;
  if (function_exists('rmi_register_error_handlers')) { rmi_register_error_handlers(); }
}


// Error reporting mengikuti APP_DEBUG (konsisten di semua halaman yang hanya include master/auth.php)
if (defined('APP_DEBUG') && APP_DEBUG) {
  @ini_set('display_errors', '1');
  @ini_set('display_startup_errors', '1');
  @error_reporting(E_ALL);
} else {
  @ini_set('display_errors', '0');
  @ini_set('display_startup_errors', '0');
  @error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
}

// Bootstrap shared helpers + DB (needed for csrf_token(), h(), and $pdo).
require_once __DIR__ . '/../_shared/helpers.php';
$__rmiSysGate = __DIR__ . '/../_shared/rmi_sys_gate.php';
if (is_file($__rmiSysGate)) {
  require_once $__rmiSysGate;
}
require_once __DIR__ . '/../_shared/db.php';
// Optional RBAC engine (permission-based)
$__rbac = __DIR__ . '/../_shared/rbac.php';
if (is_file($__rbac)) {
  require_once $__rbac;
}
// Module guards (rmi_require_module, rmi_require_admin)
$__rbacGuard = __DIR__ . '/../_shared/rbac_module_guard.php';
if (is_file($__rbacGuard)) {
  require_once $__rbacGuard;
}
$__prg = __DIR__ . '/../_shared/rmi_page_registry_guard.php';
if (is_file($__prg)) {
  require_once $__prg;
}


// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------

if (!function_exists('auth_up')) {
  function auth_up($s): string {
    return strtoupper(trim((string)$s));
  }
}

/**
 * True jika session = level SYS (gate mutasi kebijakan / master berbahaya).
 * Sumber kebenaran: rmi_sys_gate.php — jangan duplikasi logika di modul.
 */
if (!function_exists('auth_is_sys')) {
  function auth_is_sys(): bool {
    return function_exists('rmi_is_sys_session') && rmi_is_sys_session();
  }
}

/**
 * Session privileged setara SYS — konsisten dengan `require_role(['SYS','ADMIN','SUPERADMIN'])` / tools matrix.
 * RBAC proyek: SYS = ADMIN = SUPERADMIN (privileged); dipakai untuk UI admin chat, dsb.
 * Untuk gate internal yang hanya canonical SYS, tetap pakai `auth_is_sys()` / `rmi_is_sys_session()`.
 */
if (!function_exists('auth_is_sys_tier')) {
  function auth_is_sys_tier(): bool {
    if (function_exists('auth_is_sys') && auth_is_sys()) {
      return true;
    }
    $r = strtoupper(trim((string)($_SESSION['role'] ?? '')));
    $l = strtoupper(trim((string)($_SESSION['level'] ?? '')));

    return in_array($r, ['ADMIN', 'SUPERADMIN'], true) || in_array($l, ['ADMIN', 'SUPERADMIN'], true);
  }
}

// Compatibility alias (a lot of older code uses up())
if (!function_exists('up')) {
  function up($s): string {
    return auth_up($s);
  }
}

/**
 * Detect base project path for links like /master/login.php.
 *
 * Logic:
 * - If constant BASE_PROJECT already exists, use it.
 * - Else: take SCRIPT_NAME dir, then go 1 level up.
 *   Example: /ERP_RMI_SOFULL/stock/wqs_stock.php -> /ERP_RMI_SOFULL
 *   Example: /ERP_RMI_SOFULL/master/login.php -> /ERP_RMI_SOFULL
 */
if (!function_exists('auth_base_project')) {
  function auth_base_project(): string {
  // Determine base path of project so links always absolute (no /master/master/...).
  // Works whether project is at webroot ("/") or inside subfolder (e.g. "/ERP_RMI_SOFULL").
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  if ($script === '') return '';
  $script = str_replace('\\', '/', $script);
  $script = preg_replace('~/+~', '/', $script);

  // Known top-level module dirs in this ERP project.
  $known = '(master|stock|dashboards|kpi|purchases|sales|hrl|hrl_process|hrl_reg_alkes|absensi|payroll|mpr|Fixed_Asset|rbac|tools|chat|docs|uploads|api|assets|public)';
  if (preg_match('~^(.*?)/' . $known . '(?:/|$)~i', $script, $m)) {
    $base = rtrim($m[1], '/');
    return ($base === '/' ? '' : $base);
  }

  // Fallback: dirname(SCRIPT_NAME). (If it is "/", treat as webroot)
  $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
  if ($dir === '' || $dir === '/' || $dir === '.') return '';
  return $dir;
}
}

// Provide $BASE_PROJECT for legacy pages
if (!isset($BASE_PROJECT) || !is_string($BASE_PROJECT) || $BASE_PROJECT === '') {
  $BASE_PROJECT = auth_base_project();
}

// Also define BASE_PROJECT constant if not defined (some pages use constant)
if (!defined('BASE_PROJECT')) {
  define('BASE_PROJECT', $BASE_PROJECT);
}

// ------------------------------------------------------------
// Session -> user helpers
// ------------------------------------------------------------

if (!function_exists('auth_user')) {
  /**
   * Return current user from session, normalized.
   * Keys are designed to be compatible with older patches:
   * - user_id, username, full_name, role, level, department, office_code
   */
  function auth_user(): array {
    // Accept multiple session key variants (legacy + new + nested $_SESSION['user'])
    $su = $_SESSION['user'] ?? null;
    $suArr = is_array($su) ? $su : [];

    $userId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? ($suArr['id'] ?? null);

    $username = (string)($_SESSION['username'] ?? $suArr['username'] ?? '');
    if ($username === '' && is_string($su) && $su !== '') {
      $username = $su;
    }

    $fullName = (string)(
      $_SESSION['full_name'] ?? $_SESSION['fullname'] ?? $_SESSION['name']
      ?? $suArr['full_name'] ?? ''
    );
    $role = (string)(
      $_SESSION['role'] ?? $_SESSION['user_role'] ?? $suArr['role'] ?? ''
    );
    $level = (string)(
      $_SESSION['level'] ?? $_SESSION['user_level'] ?? $suArr['level'] ?? ''
    );
    $dept = (string)(
      $_SESSION['department'] ?? $_SESSION['dept'] ?? $_SESSION['user_dept']
      ?? $suArr['department'] ?? ''
    );
    $office = (string)(
      $_SESSION['office_code'] ?? $_SESSION['office'] ?? $_SESSION['user_office']
      ?? $suArr['office_code'] ?? ''
    );

    return [
      'user_id' => $userId,
      'username' => $username,
      'full_name' => $fullName,
      'role' => auth_up($role),
      'level' => auth_up($level),
      'department' => auth_up($dept),
      'office_code' => auth_up($office),
    ];
  }
}

if (!function_exists('auth_username')) {
  function auth_username(): string {
    $u = auth_user();
    return (string)($u['username'] ?? '');
  }
}

if (!function_exists('current_actor_username')) {
  function current_actor_username(): string {
    $u = trim((string)($_SESSION['username'] ?? ($_SESSION['user']['username'] ?? '')));
    if ($u !== '') return $u;
    return 'SYSTEM';
  }
}

if (!function_exists('auth_role')) {
  function auth_role(): string {
    $u = auth_user();
    return auth_up($u['role'] ?? '');
  }
}

if (!function_exists('auth_level')) {
  function auth_level(): string {
    $u = auth_user();
    return (string)($u['level'] ?? '');
  }
}

if (!function_exists('auth_dept')) {
  function auth_dept(): string {
    $u = auth_user();
    return auth_up($u['department'] ?? '');
  }
}

if (!function_exists('auth_office')) {
  function auth_office(): string {
    $u = auth_user();
    return auth_up($u['office_code'] ?? '');
  }
}

if (!function_exists('auth_is_admin')) {
  function auth_is_admin(): bool {
    $r = auth_role();
    if (in_array($r, ['SYS'], true)) return true;
    $l = auth_up(auth_level());
    if (in_array($l, ['SYS'], true)) return true;
    $d = auth_dept();
    if ($d === 'SYS') return true;
    return false;
  }
}

if (!function_exists('auth_landing_override_url_safe')) {
  /**
   * True if override path is safe for same-origin redirect (no open redirect).
   * Allowed: single leading slash, app path only (no scheme, no //, no ..).
   */
  function auth_landing_override_url_safe(string $url): bool {
    if ($url === '' || ($url[0] ?? '') !== '/') {
      return false;
    }
    if (str_starts_with($url, '//')) {
      return false;
    }
    if (str_contains($url, '..') || str_contains($url, '://') || str_contains($url, "\0")) {
      return false;
    }
    // Hanya path aplikasi (huruf, angka, _, ., /, -)
    return (bool)preg_match('#^/[A-Za-z0-9_./\-]+$#', $url);
  }
}

if (!function_exists('auth_landing_path_for_dept')) {
  /**
   * Landing path by department (leading slash). Caller prepends BASE_PROJECT.
   *
   * Dipakai setelah login untuk **semua** user (termasuk SYS/ADMIN/SUPERADMIN) agar konsisten
   * dengan Nav Manager → tab Landing (`nav_overrides.json`).
   *
   * Priority:
   *   1) nav_overrides.json → landing_pages → dept
   *   2) Built-in defaults (per dept; SYS → /dashboards/index.php)
   */
  function auth_landing_path_for_dept(string $dept): string {
    $d = auth_up($dept);

    // Branch Depo KAL/JGY mempunyai landing dashboard operasional sendiri.
    // Pencapaian tetap halaman terpisah; Branch internal tetap mengikuti landing BRANCH lama / Nav Manager.
    if ($d === 'BRANCH') {
      $office = auth_up((string)($_SESSION['office_code'] ?? ''));
      if (in_array($office, ['KAL','JGY'], true)) {
        return '/dashboards/branch/depo_dashboard.php';
      }
    }

    // 1) Check nav_overrides.json (managed by Nav Manager landing page feature)
    static $ovCache = null;
    if ($ovCache === null) {
      $ovFile = dirname(__DIR__) . '/_shared/nav_overrides.json';
      $ovCache = [];
      if (is_file($ovFile)) {
        $dec = json_decode((string)@file_get_contents($ovFile), true);
        if (is_array($dec) && isset($dec['landing_pages']) && is_array($dec['landing_pages'])) {
          $ovCache = $dec['landing_pages'];
        }
      }
    }
    if (isset($ovCache[$d]['url']) && $ovCache[$d]['url'] !== '') {
      $url = (string)$ovCache[$d]['url'];
      if (auth_landing_override_url_safe($url)) {
        return $url;
      }
    }

    // 2) Built-in defaults — sinkron dengan LANDING_PAGE_PER_DEPT.md
    $map = [
      'CRM'    => '/sales/sales_dashboard.php',
      'WQS'    => '/dashboards/warehouse/wqs_dashboard.php',
      // PQP Staff/Manager landing wajib ke Dashboard PQP.
      // Import Control Tower butuh permission khusus PURCHASES.IMPORT_CONTROL,
      // jadi tidak boleh menjadi landing default untuk semua akun PQP.
      'PQP'    => '/purchases/purchases_dashboard.php',
      'SCM'    => '/dashboards/scm/scm_dashboard.php',
      'FIN'    => '/dashboards/finance/ar_ap_cash_dashboard.php',
      'ACT'    => '/dashboards/act/act_dashboard.php',
      'HRL'    => '/dashboards/hrl/hrl_dashboard.php',
      'ITC'    => '/dashboards/itc/itc_dashboard.php',
      'MPR'    => '/mpr/mpr_dashboard.php',
      'BRANCH' => '/dashboards/branch/branch_dashboard.php',
    ];
    return $map[$d] ?? '/dashboards/index.php';
  }
}

if (!function_exists('auth_landing_defaults')) {
  /** Expose default landing map (used by Nav Manager UI). */
  function auth_landing_defaults(): array {
    // Sinkron dengan auth_landing_path_for_dept() dan LANDING_PAGE_PER_DEPT.md
    return [
      'CRM'    => '/sales/sales_dashboard.php',
      'WQS'    => '/dashboards/warehouse/wqs_dashboard.php',
      'PQP'    => '/purchases/purchases_dashboard.php',
      'SCM'    => '/dashboards/scm/scm_dashboard.php',
      'FIN'    => '/dashboards/finance/ar_ap_cash_dashboard.php',
      'ACT'    => '/dashboards/act/act_dashboard.php',
      'HRL'    => '/dashboards/hrl/hrl_dashboard.php',
      'ITC'    => '/dashboards/itc/itc_dashboard.php',
      'MPR'    => '/mpr/mpr_dashboard.php',
      'BRANCH' => '/dashboards/branch/branch_dashboard.php',
      'SYS'    => '/dashboards/index.php',
    ];
  }
}

if (!function_exists('auth_post_login_landing_path')) {
  /**
   * Path redirect setelah login / MFA / WebAuthn bila tidak ada ?next= khusus.
   * Alias ke auth_landing_path_for_dept — satu sumber kebenaran dengan Nav Manager.
   */
  function auth_post_login_landing_path(string $department): string {
    return auth_landing_path_for_dept($department);
  }
}


if (!function_exists('auth_post_login_target_normalize')) {
  /**
   * Normalize target setelah login/MFA.
   * Problem lama: akun PQP bisa membawa ?next=/purchases/purchases_import_control_tower.php
   * atau landing override menuju Import Control Tower, padahal staff PQP tidak punya
   * permission PURCHASES.IMPORT_CONTROL. Maka staff/manager PQP diarahkan ke dashboard.
   */
  function auth_post_login_target_normalize(string $target, array $user = []): string {
    $dept = auth_up((string)($user['department'] ?? ($_SESSION['department'] ?? '')));
    $role = auth_up((string)($user['role'] ?? ($_SESSION['role'] ?? '')));
    $level = auth_up((string)($user['level'] ?? ($_SESSION['level'] ?? '')));
    $office = auth_up((string)($user['office_code'] ?? ($_SESSION['office_code'] ?? '')));

    // Akun BRANCH Depo (KAL/JGY) tidak memakai landing Branch internal.
    // Jika membawa ?next= ke modul di luar scope, paksa kembali ke Pencapaian restricted.
    if ($dept === 'BRANCH' && in_array($office, ['KAL','JGY'], true)) {
      $pathOnly = parse_url($target, PHP_URL_PATH);
      $pathOnly = is_string($pathOnly) ? $pathOnly : $target;
      $allowedNext = [
        '/dashboards/branch/depo_dashboard.php',
        '/dashboards/finance/dashboard_detail.php',
        '/sales/sales_do.php',
        '/sales/sales_do_view.php',
        '/stock/wqs_do_tasks.php',
        '/sales/scm_do_tasks.php',
        '/sales/scm_tracker_mobile.php',
        '/mpr/index.php',
        '/mpr/mpr_dashboard.php',
        '/mpr/mpr_plans.php',
        '/mpr/mpr_plan_view.php',
        '/mpr/panduan.php',
        '/docs/help_center.php',
      ];
      $isAllowedNext = false;
      foreach ($allowedNext as $allowedPath) {
        if (stripos($pathOnly, $allowedPath) !== false) { $isAllowedNext = true; break; }
      }
      if (!$isAllowedNext) {
        $bp = function_exists('auth_base_project') ? auth_base_project() : '';
        return rtrim($bp, '/') . '/dashboards/branch/depo_dashboard.php';
      }
    }

    if ($dept === 'PQP') {
      $pathOnly = parse_url($target, PHP_URL_PATH);
      $pathOnly = is_string($pathOnly) ? $pathOnly : $target;

      // Import Control Tower hanya untuk user yang punya permission khusus.
      // SYS/Admin tetap boleh. Staff/Manager PQP biasa diarahkan ke dashboard PQP.
      if (stripos($pathOnly, '/purchases/purchases_import_control_tower.php') !== false) {
        $isPrivileged = in_array($role, ['SYS','ADMIN','SUPERADMIN'], true)
                     || in_array($level, ['SYS','ADMIN','SUPERADMIN'], true);

        $hasImportPerm = false;
        if ($isPrivileged) {
          $hasImportPerm = true;
        } elseif (function_exists('can')) {
          try { $hasImportPerm = can('PURCHASES.IMPORT_CONTROL'); } catch (Throwable $e) { $hasImportPerm = false; }
        }

        if (!$hasImportPerm) {
          $bp = function_exists('auth_base_project') ? auth_base_project() : '';
          return rtrim($bp, '/') . '/purchases/purchases_dashboard.php';
        }
      }
    }

    return $target;
  }
}


if (!function_exists('auth_rbac_ready')) {
  function auth_rbac_ready(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $ready = false;
    if (!function_exists('rbac_can2')) return false;
    $pdo = auth_pdo();
    if (!$pdo) return false;
    try {
      $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
      if ($db === '' || $db === false) return false;
      $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name IN ('rbac_permissions','rbac_dept_role_permissions')");
      $st->execute([$db]);
      $ready = ((int)$st->fetchColumn() >= 2);
    } catch (Throwable $e) {
      $ready = false;
    }
    return $ready;
  }
}

if (!function_exists('auth_legacy_perm_allow_depts')) {
  /**
   * Fallback jika RBAC DB belum ready — hanya SYS. Normal: izin lewat can() / data user (RBAC Center).
   */
  function auth_legacy_perm_allow_depts(string $permCode): array {
    return ['SYS'];
  }
}

if (!function_exists('can_dept')) {
  function can_dept(string $dept): bool {
    if (auth_is_admin()) return true;
    $d = auth_up($dept);
    if ($d === '') return false;
    return auth_dept() === $d || auth_role() === $d;
  }
}


if (!function_exists('auth_is_manager_or_sys_any')) {
  /**
   * Deteksi semua akun level manager/admin/sys lintas cabang/kantor.
   * Contoh yang harus lolos: MgrMPR_BKS, MgrHRL_BGR, MgrCRM_BGR, MgrFIN_BDG.
   */
  function auth_is_manager_or_sys_any(): bool {
    $u = function_exists('auth_user') ? auth_user() : [];
    $vals = [];

    foreach ([
      'username','role','level','department','dept','office_code',
      'user_role','user_level','perm_level','access_level',
      'position','jabatan','title','name','full_name'
    ] as $k) {
      if (isset($_SESSION[$k])) $vals[] = strtoupper((string)$_SESSION[$k]);
      if (isset($u[$k])) $vals[] = strtoupper((string)$u[$k]);
    }

    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
      foreach ($_SESSION['user'] as $v) {
        if (is_scalar($v)) $vals[] = strtoupper((string)$v);
      }
    }

    if (function_exists('auth_role'))     $vals[] = strtoupper((string)auth_role());
    if (function_exists('auth_level'))    $vals[] = strtoupper((string)auth_level());
    if (function_exists('auth_dept'))     $vals[] = strtoupper((string)auth_dept());
    if (function_exists('auth_username')) $vals[] = strtoupper((string)auth_username());

    $joined = implode('|', array_filter($vals, fn($x) => $x !== ''));

    return str_contains($joined, 'MANAGER')
        || str_contains($joined, 'MGR')
        || str_contains($joined, 'ADMIN')
        || preg_match('/(^|[|_\\-])SYS([|_\\-]|$)/', $joined) === 1;
  }
}


/**
 * KPI read-only access override for all Manager/MGR/SYS accounts.
 * Purpose: allow Manager FIN/CRM/WQS/SCM/ACT/HRL/PQP/MPR/etc. to open KPI pages,
 * while write/manage actions still depend on their own stricter checks.
 */
if (!function_exists('auth_is_kpi_read_manager_any')) {
  function auth_is_kpi_read_manager_any(): bool {
    return function_exists('auth_is_manager_or_sys_any') && auth_is_manager_or_sys_any();
  }
}


if (!function_exists('auth_is_mgr_hrl_any')) {
  /**
   * Deteksi akun Manager HRL lintas cabang/kantor.
   * Contoh yang harus lolos: MgrHRL_BGR, MgrHRL_TGR, MgrHRL_BKS.
   */
  function auth_is_mgr_hrl_any(): bool {
    $u = function_exists('auth_user') ? auth_user() : [];

    $username = strtoupper(trim((string)(
      $_SESSION['username']
      ?? ($_SESSION['user']['username'] ?? '')
      ?? ($u['username'] ?? '')
    )));

    $role = strtoupper(trim((string)(
      $_SESSION['role']
      ?? ($_SESSION['user']['role'] ?? '')
      ?? ($u['role'] ?? '')
    )));

    $level = strtoupper(trim((string)(
      $_SESSION['level']
      ?? ($_SESSION['user']['level'] ?? '')
      ?? ($u['level'] ?? '')
    )));

    $dept = strtoupper(trim((string)(
      $_SESSION['department']
      ?? $_SESSION['dept']
      ?? ($_SESSION['user']['department'] ?? '')
      ?? ($_SESSION['user']['dept'] ?? '')
      ?? ($u['department'] ?? '')
    )));

    $isManager = in_array($level, ['MANAGER','MGR'], true)
              || in_array($role, ['MANAGER','MGR'], true)
              || strpos($username, 'MGR') !== false
              || strpos($username, 'MANAGER') !== false;

    $isHrl = ($dept === 'HRL') || strpos($username, 'HRL') !== false;

    return $isManager && $isHrl;
  }
}

if (!function_exists('can')) {
  function can(string $permCode): bool {
    // HRL Process open-access:
    // Semua user login, semua level, semua cabang/kantor boleh permission HRL.REQ_*.
    if (str_starts_with(auth_up($permCode), 'HRL.REQ_')) {
      return true;
    }

    $permCode = auth_up($permCode);
    if ($permCode === '') return false;
    if (auth_is_admin()) return true;

    // KPI read-only override:
    // Semua akun Manager/MGR/SYS boleh membuka halaman KPI dashboard/SLA sebagai VIEW.
    // Tidak membuka akses edit/simpan kebijakan karena action mutasi tetap digate terpisah.
    if (function_exists('auth_is_kpi_read_manager_any')
        && auth_is_kpi_read_manager_any()
        && in_array($permCode, ['KPI.DO_VIEW', 'KPI.DO_SLA_VIEW', 'KPI.VIEW', 'KPI.SLA_VIEW'], true)) {
      return true;
    }

    // HRL Docs override:
    // Semua akun Manager HRL lintas cabang/kantor boleh akses/mengelola HRL Docs.
    // Memperbaiki Forbidden: REGISTRY_REQUIRE_PERMISSION perm=HRL.DOC_VIEW.
    if (
      function_exists('auth_is_mgr_hrl_any')
      && auth_is_mgr_hrl_any()
      && (
        str_starts_with($permCode, 'HRL.DOC_')
        || $permCode === 'HRL.VIEW'
      )
    ) {
      return true;
    }

    // HRL Process override:
    // Semua akun MANAGER/MGR/ADMIN/SYS lintas cabang/kantor boleh permission HRL.REQ_*.
    // Ini memperbaiki Forbidden: REGISTRY_REQUIRE_PERMISSION perm=HRL.REQ_CUTI_VIEW.
    if (str_starts_with($permCode, 'HRL.REQ_')) {
      return true;
    }

    // Preferred path: permission-based RBAC.
    if (auth_rbac_ready() && function_exists('rbac_can2')) {
      try { return rbac_can2($permCode); } catch (Throwable $e) { return false; }
    }

    // Incremental fallback path (legacy dept-based).
    $allowDepts = auth_legacy_perm_allow_depts($permCode);
    if (!$allowDepts) return false;
    foreach ($allowDepts as $dep) {
      if (can_dept((string)$dep)) return true;
    }
    return false;
  }
}

if (!function_exists('can_any')) {
  function can_any(array $permCodes): bool {
    foreach ($permCodes as $perm) {
      if (can((string)$perm)) return true;
    }
    return false;
  }
}

if (!function_exists('require_permission')) {
  function require_permission(string $permCode): void {
    $pcOpen = auth_up($permCode);
    // HRL Process open-access:
    // Jangan forbidden untuk HRL.REQ_* bagi semua user login.
    if (str_starts_with($pcOpen, 'HRL.REQ_')) {
      return;
    }

    $pc = auth_up($permCode);

    // HRL Process override:
    // Jangan hard-forbidden HRL.REQ_* untuk manager/admin/sys.
    // HRL Docs override:
    // Jangan forbidden HRL.DOC_* / HRL.VIEW untuk Manager HRL lintas cabang/kantor.
    if (
      function_exists('auth_is_mgr_hrl_any')
      && auth_is_mgr_hrl_any()
      && (
        str_starts_with($pc, 'HRL.DOC_')
        || $pc === 'HRL.VIEW'
      )
    ) {
      return;
    }

    if (str_starts_with($pc, 'HRL.REQ_')
        && function_exists('auth_is_manager_or_sys_any')
        && auth_is_manager_or_sys_any()) {
      return;
    }

    if (!can($pc)) {
      $route = function_exists('auth_rbac_route') ? auth_rbac_route() : (string)($_SERVER['REQUEST_URI'] ?? '');
      auth_rbac_forbidden_exit('REGISTRY_REQUIRE_PERMISSION', $route, 'perm=' . $pc);
    }
  }
}

if (!function_exists('require_any_permission')) {
  /**
   * OR antar kode: cukup satu permission yang cocok.
   */
  function require_any_permission(array $permCodes): void {
    // HRL Process open-access:
    // Jika daftar permission berisi HRL.REQ_*, izinkan semua user login.
    foreach (array_map('auth_up', $permCodes) as $pOpen) {
      if (str_starts_with($pOpen, 'HRL.REQ_')) {
        return;
      }
    }

    $norm = array_values(array_unique(array_map('auth_up', $permCodes)));

    // KPI read-only override for page registry OR-list, e.g. KPI.DO_VIEW or SALES.AUDIT.
    if (function_exists('auth_is_kpi_read_manager_any')
        && auth_is_kpi_read_manager_any()
        && array_intersect($norm, ['KPI.DO_VIEW', 'KPI.DO_SLA_VIEW', 'KPI.VIEW', 'KPI.SLA_VIEW'])) {
      return;
    }

    // HRL Docs override:
    // Jika daftar permission berisi HRL.DOC_* / HRL.VIEW dan user Manager HRL, izinkan.
    if (function_exists('auth_is_mgr_hrl_any') && auth_is_mgr_hrl_any()) {
      foreach ($norm as $p) {
        if (str_starts_with($p, 'HRL.DOC_') || $p === 'HRL.VIEW') {
          return;
        }
      }
    }

    // HRL Process override:
    // Jika daftar permission berisi HRL.REQ_* dan user manager/admin/sys, izinkan.
    if (function_exists('auth_is_manager_or_sys_any') && auth_is_manager_or_sys_any()) {
      foreach ($norm as $p) {
        if (str_starts_with($p, 'HRL.REQ_')) {
          return;
        }
      }
    }

    if (!can_any($norm)) {
      $route = function_exists('auth_rbac_route') ? auth_rbac_route() : (string)($_SERVER['REQUEST_URI'] ?? '');
      auth_rbac_forbidden_exit('REGISTRY_REQUIRE_ANY_PERMISSION', $route, 'need_any_of=' . implode(',', $norm));
    }
  }
}

if (!function_exists('require_all_permissions')) {
  /** Semua kode wajib terpenuhi (AND). */
  function require_all_permissions(array $permCodes): void {
    foreach ($permCodes as $perm) {
      require_permission((string) $perm);
    }
  }
}

if (!function_exists('require_route_access')) {
  /**
   * Gate buka halaman/URL: hanya permission tingkat akses route (OR di antara kode setara).
   * Jangan sertakan *.VIEW lebar di sini jika registry memisahkan ACCESS vs VIEW.
   */
  function require_route_access(array $accessTierPermCodes): void {
    require_any_permission($accessTierPermCodes);
  }
}

if (!function_exists('require_content_view')) {
  /** Gate konten baca: terpisah dari akses route (setelah require_route_access). */
  function require_content_view(string $viewPermCode): void {
    require_permission($viewPermCode);
  }
}

// ------------------------------------------------------------
// Guards
// ------------------------------------------------------------

if (!function_exists('auth_session_ip_guard')) {
  /**
   * Session-level IP guard untuk akun built-in admin/superadmin.
   * Dipanggil di setiap request setelah user terdeteksi sudah login.
   * Jika IP berubah ke luar whitelist → session dicabut, redirect login.
   */
  function auth_session_ip_guard(): void {
    $username = strtolower(trim((string)($_SESSION['username'] ?? '')));
    if (!in_array($username, ['admin', 'superadmin'], true)) return;

    // Baca IP client (X-Forwarded-For utama, fallback ke REMOTE_ADDR)
    $rawIp = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $parts = explode(',', $rawIp);
    $ip    = trim((string)($parts[0] ?? ''));

    // Baca whitelist dari env
    $list = '';
    if (function_exists('rmi_env')) {
      $list = (string)(rmi_env('ADMIN_BUILTIN_ALLOWED_IPS') ?: '');
    }
    if ($list === '') {
      $list = (string)(getenv('ADMIN_BUILTIN_ALLOWED_IPS') ?: ($_ENV['ADMIN_BUILTIN_ALLOWED_IPS'] ?? ''));
    }
    if (trim($list) === '') return; // Whitelist kosong = tidak aktif

    $allowed = array_filter(array_map('trim', explode(',', $list)));
    foreach ($allowed as $entry) {
      if (strpos($entry, '/') !== false) {
        // CIDR check
        [$subnet, $bits] = explode('/', $entry, 2);
        $bits = (int)$bits;
        if ($bits >= 0 && $bits <= 32 && strpos($ip, ':') === false) {
          $ipL  = ip2long($ip);
          $subL = ip2long($subnet);
          if ($ipL !== false && $subL !== false) {
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
            if (($ipL & $mask) === ($subL & $mask)) return; // IP diizinkan
          }
        }
      } else {
        if ($ip === $entry) return; // IP diizinkan
      }
    }

    // IP tidak diizinkan — catat audit, cabut session
    try {
      if (function_exists('db_pdo')) {
        $pdo = db_pdo();
        $actor = (string)($_SESSION['username'] ?? $username);
        $descr = "Session terminated: IP {$ip} tidak ada di whitelist admin.";
        $pdo->prepare("
          INSERT INTO system_audit_logs
            (module, action, record_table, record_code, description, username, ip, created_at)
          VALUES ('auth','SESSION_EXPIRED','master_system_login',?,?,?,?,NOW())
        ")->execute(["USER#{$actor}", $descr, $actor, substr($ip, 0, 45)]);
      }
    } catch (Throwable $e) { /* fail-soft */ }

    session_destroy();
    $loginUrl = (function_exists('auth_base_project') ? auth_base_project() : '') . '/master/login.php';
    rmi_redirect($loginUrl . '?reason=session_ip');
  }
}

// ------------------------------------------------------------
// Session hardening: regenerate ID (panggil dari login/MFA), idle timeout server-side
// ------------------------------------------------------------

if (!function_exists('auth_session_idle_env_int')) {
  function auth_session_idle_env_int(string $key, int $default): int {
    if (function_exists('rmi_env')) {
      $raw = rmi_env($key);
      if ($raw !== null && $raw !== '') {
        $n = (int) $raw;
        if ($n > 0) {
          return $n;
        }
      }
    }
    $e = getenv($key);
    if ($e !== false && trim((string) $e) !== '') {
      $n = (int) $e;
      if ($n > 0) {
        return $n;
      }
    }
    return $default;
  }
}

if (!function_exists('auth_session_apply_gc_maxlifetime')) {
  /**
   * Sinkronkan session.gc_maxlifetime dengan env (setelah .env terbaca).
   * Cookie tetap lifetime=0; ini hanya umur data sesi di storage server.
   */
  function auth_session_apply_gc_maxlifetime(): void {
    $gc = auth_session_idle_env_int('RMI_SESSION_GC_MAXLIFETIME', 7200);
    if ($gc < 3600) {
      $gc = 7200;
    }
    @ini_set('session.gc_maxlifetime', (string) $gc);
  }
}

if (!function_exists('auth_session_mark_login_complete')) {
  /**
   * Panggil setelah autentikasi sukses (password OK atau MFA OK).
   * - session_regenerate_id(true) → mitigasi session fixation
   * - stempel waktu untuk idle timeout
   */
  function auth_session_mark_login_complete(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_regenerate_id(true);
    }
    $_SESSION['_rmi_last_activity'] = time();
  }
}

if (!function_exists('auth_session_idle_limit_seconds')) {
  /**
   * Batas idle: non-SYS default 30 menit; SYS (level/role SYS) default 60 menit.
   * Override: RMI_SESSION_IDLE_SECONDS, RMI_SESSION_IDLE_SYS_SECONDS (.env).
   */
  function auth_session_idle_limit_seconds(): int {
    $sysSec = auth_session_idle_env_int('RMI_SESSION_IDLE_SYS_SECONDS', 3600);
    $defSec = auth_session_idle_env_int('RMI_SESSION_IDLE_SECONDS', 1800);
    if ($sysSec < 300) {
      $sysSec = 3600;
    }
    if ($defSec < 300) {
      $defSec = 1800;
    }
    $dept = auth_up((string) ($_SESSION['department'] ?? ''));
    $isSysBucket = (function_exists('rmi_is_sys_session') && rmi_is_sys_session())
                || $dept === 'SYS';
    if ($isSysBucket) {
      return $sysSec;
    }
    return $defSec;
  }
}

if (!function_exists('auth_login_expects_json_401')) {
  function auth_login_expects_json_401(): bool {
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $isApiPath = (strpos($uri, '/api/v1/mobile/') !== false) || (strpos($uri, '/api/v1/') !== false);
    return $isApiPath && (strpos($accept, 'application/json') !== false);
  }
}

if (!function_exists('auth_session_idle_enforce')) {
  /**
   * Cabut sesi jika tidak ada aktivitas melebihi batas (rolling window per request).
   */
  function auth_session_idle_enforce(): void {
    $now = time();
    $limit = auth_session_idle_limit_seconds();
    $last = $_SESSION['_rmi_last_activity'] ?? null;

    if ($last === null || !is_int($last)) {
      $_SESSION['_rmi_last_activity'] = $now;
      return;
    }

    if (($now - $last) <= $limit) {
      $_SESSION['_rmi_last_activity'] = $now;
      return;
    }

    $actor = (string) ($_SESSION['username'] ?? '-');
    $ipRaw = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ipParts = explode(',', $ipRaw);
    $ip = substr(trim((string) ($ipParts[0] ?? '')), 0, 45);

    try {
      if (function_exists('db_pdo')) {
        $pdo = db_pdo();
        $pdo->prepare(
          "INSERT INTO system_audit_logs
            (module, action, record_table, record_code, description, username, ip, created_at)
           VALUES ('auth','SESSION_IDLE_EXPIRED','master_system_login',?,?,?,?,NOW())"
        )->execute([
          'USER#' . $actor,
          'Sesi berakhir karena tidak ada aktivitas (' . (int) $limit . 's): ' . $actor,
          $actor,
          $ip,
        ]);
      }
    } catch (Throwable $e) {
    }

    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_destroy();
    }

    if (auth_login_expects_json_401()) {
      header('Content-Type: application/json; charset=utf-8');
      http_response_code(401);
      echo json_encode([
        'ok' => false,
        'code' => 'ERR_SESSION_EXPIRED',
        'message' => 'Session expired (idle timeout).',
        'request_id' => trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '')),
      ], JSON_UNESCAPED_SLASHES);
      exit;
    }

    $loginUrl = (function_exists('auth_base_project') ? auth_base_project() : '') . '/master/login.php';
    rmi_redirect($loginUrl . '?reason=session_idle');
  }
}

// Terapkan gc_maxlifetime dari .env jika sudah ter-load (override getenv di awal file).
auth_session_apply_gc_maxlifetime();

if (!function_exists('auth_require_login')) {
  function auth_require_login(string $redirect_to = ''): void {
    $hasUser = isset($_SESSION['user']) || isset($_SESSION['username']);
    if ($hasUser) {
      auth_session_idle_enforce();
      auth_session_ip_guard(); // Cek IP setiap request untuk admin/superadmin
      return;
    }

    // API clients (Accept: application/json) expect 401 JSON, not redirect.
    // Mobile Quality Gate: auth/me without token must return ERR_UNAUTHENTICATED 401.
    if (auth_login_expects_json_401()) {
      header('Content-Type: application/json; charset=utf-8');
      http_response_code(401);
      echo json_encode([
        'ok' => false,
        'code' => 'ERR_UNAUTHENTICATED',
        'message' => 'Missing bearer token.',
        'request_id' => trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '')),
      ], JSON_UNESCAPED_SLASHES);
      exit;
    }

    if ($redirect_to === '') {
      $redirect_to = auth_base_project() . '/master/login.php';
    }

    $next = $_SERVER['REQUEST_URI'] ?? '';
    $sep = (strpos($redirect_to, '?') !== false) ? '&' : '?';
    rmi_redirect($redirect_to . $sep . 'next=' . urlencode($next));
  }
}

// Backward compatible alias
// Urutan gate RBAC setelah login: docs/internal/RBAC_RUNTIME_LAYERS.md
if (!function_exists('require_login')) {
  function require_login(string $redirect_to = ''): void {
    auth_require_login($redirect_to);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    // QA: SYS + X-RBAC-PROBE:1 may POST without CSRF (read-only permission probe; no mutation).
    $rbacProbe = trim((string)($_SERVER['HTTP_X_RBAC_PROBE'] ?? '')) === '1';
    $probeCsrfSkip = $rbacProbe
      && strtoupper(trim((string)($_SESSION['department'] ?? ''))) === 'SYS'
      && (function_exists('auth_is_admin') ? auth_is_admin() : false);
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !$probeCsrfSkip) {
      verify_csrf();
    }
    if (function_exists('require_rbac')) {
      require_rbac();
    }
    if (function_exists('rmi_page_registry_guard_try')) {
      rmi_page_registry_guard_try();
    }
  }
}

if (!function_exists('auth_rbac_route')) {
  function auth_rbac_route(): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') return '/';
    $base = rtrim(auth_base_project(), '/');
    if ($base !== '' && str_starts_with($script, $base)) {
      $script = substr($script, strlen($base));
    }
    if ($script === '' || $script[0] !== '/') $script = '/' . ltrim($script, '/');
    return $script;
  }
}

if (!function_exists('auth_rbac_is_public')) {
  function auth_rbac_is_public(string $route): bool {
    $public = [
      '/master/login.php', '/master/logout.php', '/master/mfa_verify.php',
      '/api/health.php', '/api/v1/health.php',
      '/docs/help_sop_map_json.php',
      '/customer_portal/login.php', '/customer_portal/logout.php',
      '/manufacturer_portal/login.php', '/manufacturer_portal/logout.php',
    ];
    if (in_array($route, $public, true)) return true;
    return str_contains($route, 'tracking_public') || str_contains($route, 'public_tracking');
  }
}

if (!function_exists('auth_rbac_match_rule')) {
  function auth_rbac_match_rule(string $route, array $rules): ?array {
    foreach ($rules as $rule) {
      if (!is_array($rule) || empty($rule['route'])) continue;
      $pat = (string)$rule['route'];
      if (str_ends_with($pat, '/*')) {
        $prefix = substr($pat, 0, -1); // keep trailing slash
        if (str_starts_with($route, $prefix)) return $rule;
      } elseif ($pat === $route) {
        return $rule;
      }
    }
    return null;
  }
}

if (!function_exists('auth_rbac_action_name')) {
  function auth_rbac_action_name(): string {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') return 'read';
    $action = strtolower(trim((string)($_POST['action'] ?? $_POST['do'] ?? '')));
    if ($action !== '') return $action;
    foreach (['approve','reject','post','apply','pay','disburse','submit','create','edit','delete','sync','import','export'] as $k) {
      if (isset($_POST[$k])) return $k;
    }
    return 'form_submit';
  }
}

if (!function_exists('auth_rbac_audit_deny')) {
  function auth_rbac_audit_deny(string $reason, string $route): void {
    // IP asli: utamakan X-Forwarded-For (di balik proxy/Cloudflare)
    $rawIp    = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ipParts  = explode(',', $rawIp);
    $clientIp = trim((string)($ipParts[0] ?? ''));
    $username = (string)($_SESSION['username'] ?? '-');
    $dept     = (string)($_SESSION['department'] ?? '-');
    $level    = (string)($_SESSION['level'] ?? '-');

    // 1) Tulis ke file (legacy — tetap dipertahankan untuk debug)
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $log  = $root . '/storage/logs/rbac_apply.log';
    @mkdir(dirname($log), 0775, true);
    $line = date(DateTimeInterface::ATOM)
      . " RBAC_DENY route={$route} user={$username}"
      . " dept={$dept} level={$level} reason={$reason} ip={$clientIp}\n";
    @file_put_contents($log, $line, FILE_APPEND);

    // 2) Dual-write ke system_audit_logs agar tampil di Audit Log UI
    try {
      if (function_exists('db_pdo')) {
        $pdo = db_pdo();
        if (function_exists('master_audit_ensure_table')) master_audit_ensure_table($pdo);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role   = strtoupper((string)($_SESSION['role'] ?? ''));
        $ua     = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $descr  = "RBAC Denied: {$reason} — route={$route} user={$username} dept={$dept} level={$level}";
        $pdo->prepare("
          INSERT INTO system_audit_logs
            (module, action, record_table, record_code, description, details,
             user_id, username, role, level, ip, user_agent, created_at)
          VALUES ('rbac','RBAC_DENY',?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
          'rbac_policy',
          $route,
          $descr,
          json_encode(['reason' => $reason, 'route' => $route, 'dept' => $dept, 'level' => $level], JSON_UNESCAPED_SLASHES),
          $userId,
          $username,
          $role,
          strtoupper($level),
          substr($clientIp, 0, 45),
          $ua,
        ]);
      }
    } catch (Throwable $e) { /* fail-soft */ }
  }
}

if (!function_exists('auth_rbac_verbose_deny')) {
  /** True = body 403 boleh berisi alasan singkat (bukan prod default). */
  function auth_rbac_verbose_deny(): bool {
    return (defined('APP_DEBUG') && APP_DEBUG)
      || (function_exists('rmi_env') && rmi_env('RMI_RBAC_VERBOSE_DENY', '') === '1');
  }
}

if (!function_exists('auth_rbac_forbidden_exit')) {
  /**
   * Satu pintu keluar 403 RBAC: selalu dual-write audit (rbac_apply.log + system_audit_logs jika ada).
   * Body: "Forbidden" saja kecuali auth_rbac_verbose_deny() + $detail tidak kosong.
   */
  function auth_rbac_forbidden_exit(string $reason, string $route, string $detail = ''): void {
    $auditReason = $detail !== '' ? ($reason . ' | ' . $detail) : $reason;
    if (function_exists('auth_rbac_audit_deny')) {
      auth_rbac_audit_deny($auditReason, $route);
    }
    http_response_code(403);
    if (function_exists('auth_rbac_verbose_deny') && auth_rbac_verbose_deny() && $detail !== '') {
      header('Content-Type: text/plain; charset=UTF-8');
      echo 'Forbidden' . "\n\n" . $reason . "\n" . $detail;
    } else {
      echo 'Forbidden';
    }
    exit;
  }
}

if (!function_exists('auth_sales_do_transition_allowed')) {
  function auth_sales_do_transition_allowed(string $from, string $to, string $dept): bool {
    $from = strtolower(trim($from));
    $to = strtolower(trim($to));
    $dept = auth_up($dept);
    if ($dept === 'SYS') return true;
    $map = [
      'CRM' => [['draft','crm_to_wqs'], ['crm_to_wqs','crm_to_wqs']],
      'WQS' => [['crm_to_wqs','wqs_processing'], ['wqs_processing','ready_scm'], ['ready_scm','ready_scm']],
      'BRANCH' => [['crm_to_wqs','wqs_processing'], ['wqs_processing','ready_scm'], ['ready_scm','ready_scm']],
      'SCM' => [['ready_scm','on_delivery'], ['on_delivery','delivered'], ['delivered','delivered']],
      'ACT' => [['delivered','wait_payment'], ['wait_payment','wait_payment']],
      'FIN' => [['wait_payment','paid'], ['paid','paid']],
    ];
    foreach ($map[$dept] ?? [] as $rule) {
      if ($from === $rule[0] && $to === $rule[1]) return true;
    }
    return false;
  }
}

if (!function_exists('auth_sales_do_require_transition')) {
  function auth_sales_do_require_transition(string $from, string $to, ?string $reason = null): void {
    $dept = auth_dept();
    if (auth_sales_do_transition_allowed($from, $to, $dept)) return;
    $route = auth_rbac_route();
    $detail = 'from=' . $from . ' to=' . $to . ' dept=' . $dept . ' reason=' . (string)$reason;
    auth_rbac_forbidden_exit('DO_TRANSITION_BLOCK', $route, $detail);
  }
}

if (!function_exists('require_rbac')) {
  function require_rbac(?string $pageKey = null, ?string $method = null, ?string $action = null): void {
    $route = $pageKey ?: auth_rbac_route();
    if (auth_rbac_is_public($route)) return;
    if (function_exists('auth_is_sys_tier') && auth_is_sys_tier()) {
      return;
    }

    $policyFile = __DIR__ . '/../_shared/rbac_policy.php';
    if (!is_file($policyFile)) return;
    require_once $policyFile;
    if (!function_exists('rmi_rbac_policy_config')) return;
    $cfg = rmi_rbac_policy_config();
    $rules = (array)($cfg['rules'] ?? []);
    $strict = (bool)($cfg['strict'] ?? false);

    $rule = auth_rbac_match_rule($route, $rules);
    if ($rule === null) {
      if ($strict) {
        auth_rbac_forbidden_exit('STRICT_UNLISTED_ROUTE', $route, 'route not listed in rbac_policy.php');
      }
      return;
    }

    $level = auth_up((string)($_SESSION['level'] ?? ''));
    $effMethod = strtoupper($method ?: (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $effAction = strtolower($action ?: auth_rbac_action_name());

    $levels = array_map('auth_up', (array)($rule['levels'] ?? []));
    $methods = array_map('strtoupper', (array)($rule['methods'] ?? ['GET']));
    $isCashOut = (bool)($rule['cash_out'] ?? false);

    $levelAllowed = ($levels === []) ? true : in_array($level, $levels, true);
    $methodAllowed = in_array($effMethod, $methods, true);

    /*
     * Targeted compatibility fix:
     * Halaman koreksi absensi manual HRL memang memiliki aksi mutasi
     * save/delete. Pada policy lama route ini hanya mencantumkan GET,
     * sehingga POST diblokir walaupun dept, level, dan permission sudah benar.
     *
     * Override ini HANYA membuka method POST untuk route dan action yang sah.
     * Pemeriksaan dept, level, permission, CSRF, dan gate halaman tetap berjalan.
     */
    if (
      !$methodAllowed
      && $route === '/dashboards/hrl/absensi_manual_alpha.php'
      && $effMethod === 'POST'
      && in_array($effAction, ['save', 'delete'], true)
    ) {
      $methodAllowed = true;
    }

    // Dept di user + matrix RBAC Center sudah menentukan izin (can). Filter 'depts' di rbac_policy.php
    // hanya dipakai jika RMI_RBAC_POLICY_DEPT=1 (lapisan ketat opsional — hindari duplikasi aturan).
    $deptCodes = array_map('auth_up', (array)($rule['depts'] ?? []));
    $deptAllowed = true;
    $enforcePolicyDept = function_exists('rmi_env') && rmi_env('RMI_RBAC_POLICY_DEPT', '') === '1';
    if ($enforcePolicyDept && $deptCodes !== [] && !in_array('ALL', $deptCodes, true)) {
      $ud = auth_up(auth_dept());
      $deptAllowed = $ud !== '' && in_array($ud, $deptCodes, true);

      // Exception policy yang sangat sempit untuk akun BRANCH Depo KAL/JGY.
      // Ini menghindari menambah BRANCH ke rbac_policy.php secara global sehingga
      // Branch internal tetap persis mengikuti policy lama ketika POLICY_DEPT=1.
      if (!$deptAllowed && $ud === 'BRANCH') {
        $office = auth_up(auth_office());
        if (in_array($office, ['KAL','JGY'], true)) {
          $depoPolicyRoutes = [
            '/dashboards/branch/depo_dashboard.php',
            '/dashboards/finance/dashboard_detail.php',
            '/sales/scm_do_tasks.php',
            '/stock/wqs_do_tasks.php',
            '/mpr/index.php',
            '/mpr/mpr_dashboard.php',
            '/mpr/mpr_plans.php',
            '/mpr/mpr_plan_view.php',
            '/mpr/panduan.php',
          ];
          if (in_array($route, $depoPolicyRoutes, true)) {
            $deptAllowed = true;
          }
        }
      }
    }

    // Satu sumber untuk kode izin (can / centang user): jika URL ada di page_registry.php,
    // require_rbac tidak mengulang rule['perms'] — rmi_page_registry_guard_try() yang memanggil require_permission*.
    $registryRow = null;
    if (function_exists('rmi_page_registry_resolve_row')) {
      $registryRow = rmi_page_registry_resolve_row();
    }
    $rulePerms = array_values(array_unique((array)($rule['perms'] ?? [])));
    if ($registryRow !== null) {
      $rulePerms = [];
    }
    $permOk = true;
    if ($rulePerms !== [] && function_exists('can_any')) {
      $permOk = (bool)can_any($rulePerms);
    }

    if (!$permOk || !$levelAllowed || !$methodAllowed || !$deptAllowed) {
      $reason = !$deptAllowed ? 'POLICY_DEPT_BLOCK' : 'USER_ACTIVE_PERM_LEVEL_METHOD_BLOCK';
      $detail = 'method=' . $effMethod . ' action=' . $effAction
        . ' level=' . $level
        . ' session_dept=' . auth_up(auth_dept())
        . ' allowed_depts=' . implode(',', $deptCodes)
        . ' allowed_levels=' . implode(',', $levels)
        . ' policy_perm_ok=' . ($permOk ? '1' : '0');
      auth_rbac_forbidden_exit($reason, $route, $detail);
    }

    if ($effMethod !== 'GET') {
      $managerActions = ['approve','reject','post','apply','pay','disburse','lock','delete'];
      if (in_array($effAction, $managerActions, true) && !in_array($level, ['MANAGER', 'SYS'], true)) {
        auth_rbac_forbidden_exit('STAFF_BLOCKED_MANAGER_ACTION', $route, 'action=' . $effAction . ' level=' . $level . ' (need MANAGER or SYS)');
      }
      if ($isCashOut && !in_array($level, ['MANAGER', 'SYS'], true)) {
        auth_rbac_forbidden_exit('CASH_OUT_MANAGER_OR_SYS_ONLY', $route, 'cash_out=1 level=' . $level . ' (need MANAGER or SYS)');
      }
    }
  }
}

// ------------------------------------------------------------
// Legacy method + CSRF compatibility shims
// ------------------------------------------------------------
if (!function_exists('require_post')) {
  function require_post(): void {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
      http_response_code(405);
      echo 'Method Not Allowed';
      exit;
    }
  }
}

if (!function_exists('verify_csrf')) {
  function verify_csrf(?string $token = null): void {
    if ($token === null || $token === '') {
      $token = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? $_POST['csrf'] ?? '');
    }
    if (($token === null || $token === '') && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
      $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (($token === null || $token === '') && isset($_SERVER['HTTP_X_CSRF'])) {
      $token = (string)$_SERVER['HTTP_X_CSRF'];
    }
    if (($token === null || $token === '') && in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['PUT', 'PATCH', 'DELETE'], true)) {
      $raw = (string)file_get_contents('php://input');
      if ($raw !== '') {
        parse_str($raw, $parsed);
        if (is_array($parsed)) {
          $token = (string)($parsed['csrf_token'] ?? $parsed['_csrf'] ?? $parsed['csrf'] ?? '');
        }
        if (($token === null || $token === '') && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
          $dec = json_decode($raw, true);
          if (is_array($dec)) {
            $token = (string)($dec['csrf_token'] ?? $dec['_csrf'] ?? $dec['csrf'] ?? '');
          }
        }
      }
    }
    if (function_exists('rmi_csrf_verify')) {
      rmi_csrf_verify($token);
      return;
    }
    // Fallback minimal check if shared helper unavailable
    if (session_status() === PHP_SESSION_NONE) {
      @session_start();
    }
    $expected = (string)($_SESSION['_csrf'] ?? '');
    $fallbackOk = ($token !== '' && $expected !== '' && hash_equals($expected, (string)$token));
    if (!$fallbackOk && $token !== '' && isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '') {
      $fallbackOk = hash_equals((string)$_SESSION['csrf_token'], (string)$token);
    }
    if (!$fallbackOk) {
      @error_log('CSRF validation failed: method=' . (string)($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' uri=' . (string)($_SERVER['REQUEST_URI'] ?? ''));
      http_response_code(403);
      echo 'Forbidden (CSRF)';
      exit;
    }
  }
}

if (!function_exists('require_admin_critical')) {
  function require_admin_critical(): void {
    require_login();
    $role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
    $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
    $ok = in_array($role, ['SYS'], true) || in_array($level, ['SYS'], true);
    if (!$ok) {
      http_response_code(403);
      echo 'Forbidden';
      exit;
    }
  }
}

/**
 * Cek apakah user saat ini adalah Manager FIN.
 * Manager FIN = dept FIN + role/level manager (atau SYS).
 */
if (!function_exists('auth_is_fin_manager')) {
  function auth_is_fin_manager(): bool {
    $role  = strtoupper(trim((string)($_SESSION['role']  ?? '')));
    $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
    $dept  = strtoupper(trim((string)($_SESSION['department'] ?? '')));
    $user  = strtoupper(trim((string)($_SESSION['username'] ?? '')));

    if (in_array($role, ['SYS'], true)) return true;
    if (in_array($level, ['SYS'], true)) return true;

    if ($dept === 'FIN' && (in_array($role, ['MANAGER'], true) || in_array($level, ['MANAGER'], true))) {
      return true;
    }

    if ($dept === 'FIN' && $user === 'MGRFIN_BGR') {
      return true;
    }

    return false;
  }
}

/**
 * Require FIN Manager atau ADMIN/SUPERADMIN/SYS.
 * Dipakai di halaman keuangan strategis (Finance Dashboard, GL Reversal, Payroll).
 */
if (!function_exists('require_fin_manager_or_admin')) {
  function require_fin_manager_or_admin(): void {
    require_login();
    if (!auth_is_fin_manager()) {
      http_response_code(403);
      echo '<h3>Akses Terbatas</h3><p>Halaman ini hanya untuk <b>Manager FIN</b> atau <b>ADMIN/SUPERADMIN</b>.</p>';
      exit;
    }
  }
}

/**
 * is_mgr_fin_bgr — Check if user is FIN Manager BGR (central approver).
 * Uses office_code from profile when available; username fallback for legacy.
 * Rule: dept=FIN AND level=MANAGER AND office_code=BGR.
 */
if (!function_exists('is_mgr_fin_bgr')) {
  function is_mgr_fin_bgr(?array $user = null): bool {
    if ($user === null) {
      $user = [
        'department' => $_SESSION['department'] ?? '',
        'level'      => $_SESSION['level'] ?? '',
        'office_code'=> $_SESSION['office_code'] ?? $_SESSION['office'] ?? '',
        'role'       => $_SESSION['role'] ?? '',
        'username'   => $_SESSION['username'] ?? '',
      ];
    }
    $dept  = strtoupper(trim((string)($user['department'] ?? '')));
    $level = strtoupper(trim((string)($user['level'] ?? '')));
    $office = strtoupper(trim((string)($user['office_code'] ?? $user['office'] ?? '')));
    $username = trim((string)($user['username'] ?? ''));
    if ($dept !== 'FIN') return false;
    if ($level !== 'MANAGER') return false;
    if ($office === 'BGR') return true;
    return ($username === 'MgrFIN_BGR'); // fallback when office_code not set
  }
}

/**
 * FIN Central Approver — HANYA user MgrFIN_BGR + SYS yang boleh approve/post pembayaran.
 * Semua FIN Manager di office lain (MgrFIN_BDG, MgrFIN_TGR, dll.) DILARANG.
 * Ini menegakkan Segregation of Duty untuk cash outflow final.
 */
if (!function_exists('auth_is_fin_central_approver')) {
  /**
   * FIN central payment approver — strict: SYS override OR username MgrFIN_BGR only.
   * Other FIN managers (even BGR office) must use username MgrFIN_BGR for cash-out actions.
   */
  function auth_is_fin_central_approver(): bool {
    $role  = strtoupper(trim((string)($_SESSION['role'] ?? '')));
    $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
    $dept  = strtoupper(trim((string)($_SESSION['department'] ?? '')));
    if ($dept === 'SYS' || $role === 'SYS' || $level === 'SYS') {
      return true;
    }
    return trim((string)($_SESSION['username'] ?? '')) === 'MgrFIN_BGR';
  }
}

if (!function_exists('auth_require_fin_central_approver')) {
  /**
   * Abort 403 if caller is not username MgrFIN_BGR or SYS (strict — not “any FIN mgr BGR”).
   * Call this before any approve/post payment AP or GL reversal approve action.
   */
  function auth_require_fin_central_approver(): void {
    if (!auth_is_fin_central_approver()) {
      if (function_exists('auth_rbac_audit_deny') && function_exists('auth_rbac_route')) {
        auth_rbac_audit_deny('FIN_CENTRAL_APPROVER_ONLY', auth_rbac_route());
      }
      http_response_code(403);
      echo '<h3>Akses Terbatas — FIN Pusat</h3>'
         . '<p>Approval pembayaran/GL reversal hanya untuk <b>FIN Pusat (MgrFIN_BGR)</b> atau <b>SYS</b>.<br>'
         . 'Manager FIN cabang hanya bisa menyiapkan/menginput data.</p>';
      exit;
    }
  }
}

/**
 * Maker-Checker Enforcement.
 * Block a user from approving/posting a document they created.
 * SYS is exempt but must supply a reason (enforced by caller).
 */
if (!function_exists('auth_maker_checker_require')) {
  /**
   * @param string $doc_created_by  The username who created the document.
   * @param bool   $sys_exempt      If true, SYS may bypass (default true — caller must log reason).
   */
  function auth_maker_checker_require(string $doc_created_by, bool $sys_exempt = true): void {
    $isSys = auth_is_admin();
    if ($isSys && $sys_exempt) return;

    $currentUser = trim((string)($_SESSION['username'] ?? ''));
    if ($currentUser === '') return; // cannot determine, allow but should not happen

    if (strtolower($currentUser) === strtolower(trim($doc_created_by))) {
      if (function_exists('auth_rbac_audit_deny') && function_exists('auth_rbac_route')) {
        auth_rbac_audit_deny('MAKER_CHECKER_SELF_APPROVE doc_by=' . $doc_created_by, auth_rbac_route());
      }
      http_response_code(403);
      echo '<h3>Maker-Checker Violation</h3>'
         . '<p>Tidak boleh approve/post dokumen yang Anda sendiri buat.<br>'
         . 'Pembuat: <b>' . htmlspecialchars($doc_created_by, ENT_QUOTES, 'UTF-8') . '</b></p>';
      exit;
    }
  }
}

/**
 * Scope Enforcement: check document office_code matches user's allowed scope.
 */
if (!function_exists('auth_scope_check_office')) {
  function auth_scope_check_office(string $doc_office_code): bool {
    if (auth_is_admin()) return true;
    $userOffice = auth_office();
    if ($userOffice === '') return false;
    return strtoupper(trim($doc_office_code)) === $userOffice;
  }
}

if (!function_exists('auth_require_office_scope')) {
  function auth_require_office_scope(string $doc_office_code): void {
    if (!auth_scope_check_office($doc_office_code)) {
      if (function_exists('auth_rbac_audit_deny') && function_exists('auth_rbac_route')) {
        auth_rbac_audit_deny('OFFICE_SCOPE_MISMATCH doc_office=' . $doc_office_code, auth_rbac_route());
      }
      http_response_code(403);
      echo 'Akses ditolak: dokumen ini bukan di scope office Anda.';
      exit;
    }
  }
}

/**
 * Structured audit event — writes to erp_audit_events if table exists, else falls back to log file.
 */
if (!function_exists('auth_audit_event')) {
  /**
   * @param array<string,mixed> $meta
   */
  function auth_audit_event(string $action_code, string $object_type, string $object_id, array $meta = []): void {
    $actor     = trim((string)($_SESSION['username'] ?? 'SYSTEM'));
    $requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    if ($requestId === '') $requestId = uniqid('req_', true);
    $officeCode = auth_office();

    $metaFull = array_merge(['office_code' => $officeCode], $meta);

    $pdo = auth_pdo();
    if ($pdo) {
      try {
        $metaJson = json_encode($metaFull, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Use IF EXISTS — table may not exist in all envs
        $check = $pdo->query("SHOW TABLES LIKE 'erp_audit_events'");
        if ($check && $check->fetchColumn() !== false) {
          $st = $pdo->prepare("INSERT INTO erp_audit_events
              (actor_username, action_code, object_type, object_id, request_id, meta, created_at)
              VALUES (?,?,?,?,?,?,NOW())");
          $st->execute([$actor, $action_code, $object_type, $object_id, $requestId, $metaJson]);
          return;
        }
      } catch (Throwable $e) {}
    }

    // Fallback: append-only log
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $log  = $root . '/storage/logs/rbac_apply.log';
    @mkdir(dirname($log), 0775, true);
    $metaFlat = '';
    foreach ($metaFull as $k => $v) {
      $metaFlat .= ' ' . $k . '=' . (is_scalar($v) ? (string)$v : json_encode($v));
    }
    @file_put_contents($log,
      date(DateTimeInterface::ATOM) . " AUDIT actor={$actor} action={$action_code} type={$object_type} id={$object_id} req={$requestId}{$metaFlat}\n",
      FILE_APPEND
    );
  }
}

/**
 * Allow only certain ROLEs (ADMIN/SUPERADMIN always allowed)
 */
if (!function_exists('auth_allow_roles')) {
  function auth_allow_roles(array $allowedRoles): void {
    if (auth_is_admin()) {
      return;
    }
    $r = auth_role();
    $allowed = array_map('auth_up', $allowedRoles);
    if (!in_array($r, $allowed, true)) {
      http_response_code(403);
      echo 'Forbidden';
      exit;
    }
  }
}

/**
 * @deprecated Prefer require_rbac + page_registry guard + RBAC Center.
 * Kept for legacy modules (e.g. stock bootstrap) until migrated.
 *
 * Allow only certain DEPTs (ADMIN/SUPERADMIN always allowed)
 *
 * NOTE: This matches existing project semantics where "role" guard is actually department-based.
 */
if (!function_exists('auth_allow_depts')) {
  function auth_allow_depts(array $allowedDepts): void {
    if (auth_is_admin()) {
      return;
    }
    $d = auth_dept();
    $allowed = array_map('auth_up', $allowedDepts);
    if (!in_array($d, $allowed, true)) {
      http_response_code(403);
      echo 'Forbidden';
      exit;
    }
  }
}

// Backward compatible alias (legacy name in your project)
if (!function_exists('require_role')) {
  function require_role(array $allowedDepts): void {
    auth_allow_depts($allowedDepts);
  }
}

/**
 * Allow only certain LEVELs (ADMIN/SUPERADMIN always allowed)
 *
 * NOTE: Existing code uses level values like: staff / manager / admin (string).
 */
if (!function_exists('require_level')) {
  function require_level(array $allowedLevels): void {
    if (auth_is_admin()) {
      return;
    }
    $lvl = strtolower(trim((string)($_SESSION['level'] ?? auth_level())));
    $allowed = array_map(function ($x) {
      return strtolower(trim((string)$x));
    }, $allowedLevels);

    if (!in_array($lvl, $allowed, true)) {
      http_response_code(403);
      echo 'Forbidden';
      exit;
    }
  }
}

// ------------------------------------------------------------
// PDO helper (for patches that need PDO but project config is mysqli)
// ------------------------------------------------------------

if (!function_exists('db_pdo')) {
    function db_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }

    // Optional: load root config.php (legacy mysqli/$DB_* vars)
    $tryFiles = [
      __DIR__ . '/../config.php',
      dirname(__DIR__) . '/config.php',
      getcwd() . '/config.php',
    ];
    foreach ($tryFiles as $f) {
      if (is_string($f) && $f !== '' && file_exists($f)) {
        require_once $f;
        break;
      }
    }

    // Optional: load config-db.php (single source of truth credential)
    $cfgDb = null;
    $cfgFiles = [
      __DIR__ . '/../config-db.php',
      dirname(__DIR__) . '/config-db.php',
      getcwd() . '/config-db.php',
    ];
    foreach ($cfgFiles as $cf) {
      if (is_string($cf) && $cf !== '' && file_exists($cf)) {
        $tmp = require $cf;
        if (is_array($tmp)) {
          $cfgDb = $tmp;
          break;
        }
      }
    }

    // Prefer shared config loader (.env + globals)
    if (function_exists('rmi_db_config')) {
      $cfg  = rmi_db_config();
      $host = (string)($cfg['host'] ?? '127.0.0.1');
      $port = (int)($cfg['port'] ?? 3306);
      $db   = (string)($cfg['name'] ?? 'erp_rmi_sofull');
      $user = (string)($cfg['user'] ?? 'root');
      $pass = (string)($cfg['pass'] ?? '');
    } elseif (is_array($cfgDb)) {
      $host = (string)($cfgDb['host'] ?? '127.0.0.1');
      $port = (int)($cfgDb['port'] ?? 3306);
      $db   = (string)($cfgDb['name'] ?? 'erp_rmi_sofull');
      $user = (string)($cfgDb['user'] ?? 'root');
      $pass = (string)($cfgDb['pass'] ?? '');
    } else {
      $defaultPort = (int)(rmi_env('DB_PORT_DEFAULT') ?: 3306);
      $defaultPass = (string)(rmi_env('DB_PASS_DEFAULT') ?: '');
      $host = rmi_env('ERP_DB_HOST') ?: rmi_env('DB_HOST') ?: '127.0.0.1';
      $port = (int)(rmi_env('ERP_DB_PORT') ?: rmi_env('DB_PORT') ?: $defaultPort);
      $db   = rmi_env('ERP_DB_NAME') ?: rmi_env('DB_DATABASE') ?: rmi_env('DB_NAME') ?: 'erp_rmi_sofull';
      $user = rmi_env('ERP_DB_USER') ?: rmi_env('DB_USERNAME') ?: rmi_env('DB_USER') ?: 'root';
      $pass = rmi_env('ERP_DB_PASS') ?: rmi_env('DB_PASSWORD') ?: rmi_env('DB_PASS') ?: '' ;
    }

    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
      throw new RuntimeException('PDO MySQL driver tidak tersedia. Aktifkan extension pdo_mysql di PHP.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $db);
    try {
      $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
      ]);
      $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    } catch (Throwable $e) {
      throw new RuntimeException('Koneksi DB gagal ke ' . $host . ':' . $port . '/' . $db . ' — ' . $e->getMessage(), 0, $e);
    }

    $GLOBALS['pdo'] = $pdo;

    return $pdo;
  }

}

if (!function_exists('auth_pdo')) {
  function auth_pdo(): ?PDO {
    try {
      return db_pdo();
    } catch (Throwable $e) {
      return null;
    }
  }
}

// ------------------------------------------------------------
// Backward-compat helpers (some pages call these names)
// ------------------------------------------------------------

if (!function_exists('current_user_role')) {
  function current_user_role(): string {
    return auth_role();
  }
}

if (!function_exists('current_user_level')) {
  function current_user_level(): string {
    return auth_level();
  }
}

if (!function_exists('current_user_dept')) {
  function current_user_dept(): string {
    return auth_dept();
  }
}

