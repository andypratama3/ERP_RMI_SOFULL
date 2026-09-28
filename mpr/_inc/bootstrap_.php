<?php
// mpr/_inc/bootstrap.php
// Bootstrap + Auth + Schema ensure untuk Modul MPR (Marketing & Project)
// - Mengikuti session dari master/login.php
// - DB via master/auth.php (db_pdo)
// - FIX: base path agar tidak terjadi /mpr/mpr/... (double folder)
// - RBAC: Dept MPR (normal) + Dept FIN (khusus halaman approval budget)

require_once __DIR__ . '/../../master/auth.php';
// FIX HTTPS di balik reverse proxy / Synology / Cloudflare
if (
    isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
    strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
}

if (
    isset($_SERVER['HTTP_X_FORWARDED_SSL']) &&
    strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on'
) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
}

if (
    isset($_SERVER['HTTP_FRONT_END_HTTPS']) &&
    strtolower((string)$_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off'
) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
}

if (isset($_GET['debug_https']) && $_GET['debug_https'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'HTTPS=' . ($_SERVER['HTTPS'] ?? '') . PHP_EOL;
    echo 'SERVER_PORT=' . ($_SERVER['SERVER_PORT'] ?? '') . PHP_EOL;
    echo 'HTTP_X_FORWARDED_PROTO=' . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') . PHP_EOL;
    echo 'HTTP_X_FORWARDED_SSL=' . ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') . PHP_EOL;
    echo 'HTTP_FRONT_END_HTTPS=' . ($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '') . PHP_EOL;
    exit;
}

// Load centralized error logger agar rmi_log_module_error() tersedia di semua halaman MPR
$__mpr_elg = __DIR__ . '/../../_shared/rmi_error_logger.php';
if (is_file($__mpr_elg)) require_once $__mpr_elg;
unset($__mpr_elg);

error_reporting(E_ALL);
if (defined('APP_DEBUG') && APP_DEBUG) { ini_set('display_errors', '1'); } else { ini_set('display_errors', '0'); }

// --- FIX BASE PROJECT untuk modul ini (robust: root atau subfolder) ---
if (!function_exists('mpr_guess_base_project')) {
    function mpr_guess_base_project(): string {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $pos = strrpos($script, '/mpr/');
        if ($pos !== false) return substr($script, 0, $pos);

        $dir = rtrim(dirname($script), '/\\');
        if (preg_match('~/mpr$~', $dir)) return preg_replace('~/mpr$~', '', $dir);

        return '';
    }
}

// Override global base + auth URLs (supaya require_login redirect benar)
$BASE_PROJECT = mpr_guess_base_project();
$AUTH_LOGIN_URL  = $BASE_PROJECT . '/master/login.php';
$AUTH_LOGOUT_URL = $BASE_PROJECT . '/master/logout.php';

// Sekarang aman panggil require_login
require_login();
$pdo = db_pdo();

// Helper escape
if (!function_exists('e')) {
    function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

// Current user (session) — harus didefinisikan SEBELUM gate agar pesan error informatif
$MPR_USER = [
    'id'         => (int)($_SESSION['user_id'] ?? 0),
    'username'   => (string)($_SESSION['username'] ?? ''),
    'role'       => strtoupper((string)($_SESSION['role'] ?? '')),
    'level'      => strtoupper((string)($_SESSION['level'] ?? '')),
    'department' => strtoupper((string)($_SESSION['department'] ?? '')),
    'office_code'=> strtoupper((string)($_SESSION['office_code'] ?? '')),
];

$MPR_IS_ADMIN = in_array($MPR_USER['role'],  ['ADMIN','SUPERADMIN','SYS'], true)
             || in_array($MPR_USER['level'], ['ADMIN','SUPERADMIN','SYS'], true);

$MPR_IS_MPR   = ($MPR_USER['department'] === 'MPR');
$MPR_IS_FIN   = ($MPR_USER['department'] === 'FIN');

// RBAC check — cek permission spesifik (hanya jika RBAC ready)
$MPR_RBAC_FULL = function_exists('can') && can('MPR.VIEW');
$MPR_RBAC_PLAN = function_exists('can_any') && can_any([
    'MPR.VIEW', 'MPR.PLAN_VIEW', 'MPR.PLAN_CREATE', 'MPR.PLAN_APPROVE',
    'MPR.PLAN_EDIT', 'MPR.PLAN_DELETE', 'MPR.PLAN_IMPORT', 'MPR.PLAN_EXPORT',
    'MASTER.ADMIN_CENTER',
]);
$MPR_RBAC_FIN  = function_exists('can_any') && can_any([
    'PURCHASES.AP_PAYMENT_CRUD', 'PURCHASES.AP_PAYMENT_VIEW',
]);

// FIN hanya boleh akses halaman tertentu di modul MPR
$current_page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$FIN_ALLOWED_PAGES = ['mpr_budget_fin.php','mpr_ops_daily_fin.php','mpr_ops_daily_fin_detail.php','mpr_ops_daily_fin_pay.php','mpr_ops_daily_fin_export.php'];

$FIN_CAN_ACCESS_THIS_PAGE = ($MPR_IS_FIN || $MPR_RBAC_FIN)
                           && in_array($current_page, $FIN_ALLOWED_PAGES, true);

// Akses modul: admin, dept MPR, dept FIN (halaman FIN saja), atau punya salah satu MPR permission
$MPR_FULL_ACCESS = $MPR_IS_ADMIN || $MPR_IS_MPR || $MPR_RBAC_PLAN;

if (!$MPR_FULL_ACCESS && !$FIN_CAN_ACCESS_THIS_PAGE) {
    http_response_code(403);
    $bp = rtrim(base_project(), '/');
    echo "<h3>Akses ditolak</h3>";
    echo "<p>Modul MPR memerlukan salah satu: dept <b>MPR</b>, permission <b>MPR.VIEW</b> / <b>MPR.PLAN_*</b>, atau <b>MASTER.ADMIN_CENTER</b>. Halaman budget/ops harian hanya untuk <b>FIN</b> atau <b>Admin</b>.</p>";
    echo "<p>Akun: <b>" . e($MPR_USER['username']) . "</b> | Dept: <b>" . e($MPR_USER['department']) . "</b> | Level: <b>" . e($MPR_USER['level']) . "</b></p>";
    echo "<p>Atur akses di <a href='{$bp}/rbac/index.php'>RBAC Center</a>.</p>";
    exit;
}

// CSRF — gunakan global token (rmi_csrf_token / $_SESSION['_csrf']) agar selaras dengan verify_csrf() di require_login()
$CSRF_TOKEN = function_exists('rmi_csrf_token') ? rmi_csrf_token() : ((string)($_SESSION['_csrf'] ?? bin2hex(random_bytes(32))));

function csrf_check_or_die(): void {
    $token = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? '');
    $expected = function_exists('rmi_csrf_token') ? rmi_csrf_token() : (string)($_SESSION['_csrf'] ?? '');
    $ok = ($token !== '' && $expected !== '' && hash_equals($expected, $token));
    if (!$ok && $token !== '' && isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '') {
        $ok = hash_equals((string)$_SESSION['csrf_token'], $token);
    }
    if (!$ok) {
        http_response_code(400);
        echo "<h3>Bad Request</h3><p>CSRF token tidak valid.</p>";
        exit;
    }
}

// Flash helpers
function flash_set(string $type, string $msg): void {
    $_SESSION['mpr_flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get(): ?array {
    $f = $_SESSION['mpr_flash'] ?? null;
    unset($_SESSION['mpr_flash']);
    return $f ?: null;
}

// URL helpers (ikut base path yang sudah di-fix)
function base_project(): string {
    return (string)($GLOBALS['BASE_PROJECT'] ?? '');
}
function url_mpr(string $path): string {
    $base = rtrim(base_project(), '/');
    return $base . '/mpr/' . ltrim($path, '/');
}
function url_logout(): string {
    $base = rtrim(base_project(), '/');
    return $base . '/master/logout.php';
}
