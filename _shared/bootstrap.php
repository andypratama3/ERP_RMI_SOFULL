<?php
/**
 * _shared/bootstrap.php
 *
 * Bootstrap minimal yang aman untuk modul baru/bertahap:
 * - session_start (kalau belum)
 * - set konstanta RMI_ROOT
 * - load shared helpers (_shared/helpers.php)
 * - load DB helper (_shared/db.php)
 * - (opsional) load master/auth.php (RBAC, BASE_PROJECT, require_login, dll)
 * - (opsional) load config.php legacy (mysqli + $DB_*). Default: TIDAK auto-include.
 *
 * Cara pakai:
 *   require_once __DIR__ . '/../_shared/bootstrap.php';
 *   $pdo = rmi_db_pdo();
 *
 * Kalau butuh config.php legacy (mysqli/$conn):
 *   define('RMI_BOOTSTRAP_WITH_CONFIG', true);
 *   require_once __DIR__ . '/../_shared/bootstrap.php';
 */

declare(strict_types=1);

require_once __DIR__ . '/app_init.php';





// --- ENV + Error handler (safe, optional) ---
$__env = __DIR__ . '/env.php';
if (is_file($__env)) {
    require_once $__env;
    if (function_exists('rmi_env_load')) { rmi_env_load(); }
}

// Define APP_ENV / APP_DEBUG if not set (default dari .env)
if (!defined('APP_ENV')) {
    $v = getenv('APP_ENV');
    if ($v === false || trim((string)$v) === '') {
        $v = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null;
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

// Logger + handler
$__logger = __DIR__ . '/logger.php';
if (is_file($__logger)) { require_once $__logger; }
$__elg = __DIR__ . '/rmi_error_logger.php';
if (is_file($__elg)) { require_once $__elg; }
$__eh = __DIR__ . '/error_handler.php';
if (is_file($__eh)) {
    require_once $__eh;
    if (function_exists('rmi_register_error_handlers')) { rmi_register_error_handlers(); }
}

// Apply error_reporting only if not set elsewhere (keep legacy compatible)
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    // E_STRICT was removed in newer PHP versions; keep mask portable.
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}
// RMI_GUARD_DIRECT_ACCESS
if (basename(__FILE__) === basename($_SERVER["SCRIPT_FILENAME"] ?? "")) {
    $auth = __DIR__ . "/../master/auth.php";
    if (is_file($auth)) { require_once $auth; }
    if (function_exists("require_login")) { require_login(); }
    http_response_code(403);
    exit("Forbidden");
}

if (session_status() === PHP_SESSION_NONE) {
    // Cookie params wajib di-set SEBELUM session_start() agar berlaku untuk sesi ini.
    // Ini adalah satu-satunya tempat session pertama kali dibuka untuk mayoritas halaman.
    $__isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
               || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    // Double-guarantee: set via ini_set + session_set_cookie_params
    // Ini memastikan HttpOnly dan SameSite selalu aktif regardless PHP version behavior.
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.cookie_path', '/');
    if ($__isHttps) { @ini_set('session.cookie_secure', '1'); }

    $__gc = (int) (getenv('RMI_SESSION_GC_MAXLIFETIME') ?: 0);
    if ($__gc < 3600) {
      $__gc = 7200;
    }
    @ini_set('session.gc_maxlifetime', (string) $__gc);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $__isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Root project path (filesystem)
if (!defined('RMI_ROOT')) {
    $root = realpath(__DIR__ . '/..');
    if (!$root) $root = dirname(__DIR__);
    define('RMI_ROOT', $root);
}

// Shared helpers (M2)
require_once __DIR__ . '/helpers.php';

// DB helpers
require_once __DIR__ . '/db.php';

// RBAC/auth helpers (kalau ada)
$authFile = RMI_ROOT . '/master/auth.php';
if (is_file($authFile)) {
    require_once $authFile;
}

// Config legacy (mysqli + $DB_*). Default: OFF (biar tidak memicu redeclare h()).
$withConfig = defined('RMI_BOOTSTRAP_WITH_CONFIG') && (bool)RMI_BOOTSTRAP_WITH_CONFIG;
if ($withConfig) {
    $cfgFile = RMI_ROOT . '/config.php';
    if (is_file($cfgFile)) {
        require_once $cfgFile;
    }
}
