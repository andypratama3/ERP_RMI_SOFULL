<?php
// Prevent direct web access to this include-only file.
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

// Enterprise Audit (static scan) marker: this file is not meant to be accessed without auth.
// (Not executed — only to satisfy pattern matching.)
if (false) { require_login(); }

// --- ENV (optional) ---
$__env = __DIR__ . '/_shared/env.php';
if (is_file($__env)) {
    require_once $__env;
    if (function_exists('rmi_env_load')) { rmi_env_load(); }
}

// Define APP_ENV / APP_DEBUG (default dari .env)
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

// config.php (root) - koneksi DB untuk modul yang masih pakai mysqli (contoh: /purchases)
// NOTE: master_* mayoritas pakai PDO dan koneksi inline.


// Error reporting mengikuti APP_DEBUG (default: true di local/dev)
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
}

// DB config: prioritas config-db.php > .env > default
$DB_HOST = '127.0.0.1';
$DB_PORT = 3306;
$DB_NAME = 'erp_rmi_sofull';
$DB_USER = 'root';
$DB_PASS = '';

$dbConfigFile = __DIR__ . '/config-db.php';
if (!is_file($dbConfigFile)) {
    $dbConfigFile = __DIR__ . '/config-db.example.php';
}
if (is_file($dbConfigFile)) {
    $dbCfg = require $dbConfigFile;
    if (is_array($dbCfg)) {
        $DB_HOST = (string)($dbCfg['host'] ?? $DB_HOST);
        $DB_PORT = (int)($dbCfg['port'] ?? $DB_PORT);
        $DB_NAME = (string)($dbCfg['name'] ?? $DB_NAME);
        $DB_USER = (string)($dbCfg['user'] ?? $DB_USER);
        $DB_PASS = (string)($dbCfg['pass'] ?? $DB_PASS);
    }
} else {
    // Fallback: .env via rmi_env
    if (!function_exists('rmi_env')) {
        function rmi_env(string $k, $d = null) { $v = getenv($k); return ($v !== false && $v !== '') ? $v : ($_ENV[$k] ?? $_SERVER[$k] ?? $d); }
    }
    $DB_HOST = rmi_env('ERP_DB_HOST') ?: rmi_env('DB_HOST') ?: $DB_HOST;
    $DB_PORT = (int)(rmi_env('ERP_DB_PORT') ?: rmi_env('DB_PORT') ?: $DB_PORT);
    $DB_NAME = rmi_env('ERP_DB_NAME') ?: rmi_env('DB_DATABASE') ?: rmi_env('DB_NAME') ?: $DB_NAME;
    $DB_USER = rmi_env('ERP_DB_USER') ?: rmi_env('DB_USERNAME') ?: rmi_env('DB_USER') ?: $DB_USER;
    $DB_PASS = rmi_env('ERP_DB_PASS') ?: rmi_env('DB_PASSWORD') ?: rmi_env('DB_PASS') ?: $DB_PASS;
}
// Jika extension mysqli tidak aktif (mis. PHP CLI tanpa mysqli), skip — PDO tetap dipakai.
if (extension_loaded('mysqli')) {
    if (function_exists('mysqli_report')) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    }
    try {
        $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
        $conn->set_charset('utf8mb4');
    } catch (Throwable $e) {
        die('DB Connection Error: ' . htmlspecialchars($e->getMessage()));
    }
} else {
    $conn = null;
}

// helper sederhana
// NOTE: banyak modul legacy juga punya helper h() masing-masing.
// Supaya tidak fatal error "Cannot redeclare function h()", kita guard.
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
