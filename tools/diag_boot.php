<?php
// --- WEB guard (admin-only) ---
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../master/auth.php';
    if (function_exists('require_login')) {
        require_login();
    } elseif (function_exists('auth_require_login')) {
        auth_require_login();
    } else {
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
        if (empty($_SESSION['user']) && empty($_SESSION['username'])) {
            http_response_code(401);
            die('Unauthorized');
        }
    }

    // Only SUPERADMIN/ADMIN can run tools
    $role  = strtoupper(trim((string)($_SESSION['role'] ?? '')));
    $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
    $user  = strtolower(trim((string)($_SESSION['username'] ?? ($_SESSION['user'] ?? ''))));
    $isAdmin = in_array($role, ['ADMIN','SUPERADMIN'], true)
        || in_array($level, ['ADMIN','SUPERADMIN'], true)
        || in_array($user, ['admin','superadmin'], true);

    if (!$isAdmin) {
        http_response_code(403);
        die('Forbidden');
    }
}
// --- /WEB guard ---

/**
 * tools/diag_boot.php
 *
 * Tujuan:
 * - Membuktikan "bootstrap + auth + db" sudah satu pintu dan tidak blank.
 * - Menampilkan function penting ada/tidak.
 */

// Paksa tampilkan error di halaman ini (khusus tools)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

echo "<pre>";
echo "RMI BOOT DIAG\n";
echo "================\n";
echo "PHP_VERSION: " . PHP_VERSION . "\n";
echo "SAPI      : " . PHP_SAPI . "\n";

// Load bootstrap jika ada
$bootstrap = $root . '/_shared/bootstrap.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap;
    echo "bootstrap : OK\n";
} else {
    echo "bootstrap : MISSING ({$bootstrap})\n";
}

$vals = [
    'RMI_ENV' => defined('RMI_ENV') ? RMI_ENV : (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? null)),
    'DB_HOST' => defined('DB_HOST') ? DB_HOST : null,
    'DB_PORT' => defined('DB_PORT') ? (string)DB_PORT : null,
    'DB_NAME' => defined('DB_NAME') ? DB_NAME : null,
    'DB_USER' => defined('DB_USER') ? DB_USER : null,
    'AUTH_LOGIN_URL' => defined('AUTH_LOGIN_URL') ? AUTH_LOGIN_URL : null,
];
if (function_exists('rmi_db_config')) {
    $cfg = rmi_db_config();
    if (!isset($vals['DB_HOST']) || $vals['DB_HOST'] === null) $vals['DB_HOST'] = $cfg['host'] ?? null;
    if (!isset($vals['DB_PORT']) || $vals['DB_PORT'] === null) $vals['DB_PORT'] = (string)($cfg['port'] ?? null);
    if (!isset($vals['DB_NAME']) || $vals['DB_NAME'] === null) $vals['DB_NAME'] = $cfg['name'] ?? null;
    if (!isset($vals['DB_USER']) || $vals['DB_USER'] === null) $vals['DB_USER'] = $cfg['user'] ?? null;
}

echo "\nCONFIG\n------\n";
foreach ($vals as $k => $v) {
    echo str_pad($k, 14) . ': ' . ($v === null || $v === '' ? '(undefined)' : $v) . "\n";
}

echo "\nPHP ini\n------\n";
echo "display_errors: " . ini_get('display_errors') . "\n";
echo "error_reporting: " . error_reporting() . "\n";

echo "\nFUNCTIONS\n---------\n";
$fns = [
    'rmi_db_pdo',
    'db_pdo',
    'require_login',
    'require_level',
    'rbac_ensure_tables',
    'csrf_token',
    'verify_csrf',
    'rmi_flash_get',
];
foreach ($fns as $fn) {
    echo str_pad($fn, 18) . ': ' . (function_exists($fn) ? 'OK' : 'MISSING') . "\n";
}

echo "\nDB TEST\n-------\n";
try {
    if (function_exists('rmi_db_pdo')) {
        $pdo = rmi_db_pdo();
    } elseif (function_exists('db_pdo')) {
        $pdo = db_pdo();
    } else {
        throw new RuntimeException('No DB connector function found');
    }

    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    $now = $pdo->query('SELECT NOW()')->fetchColumn();
    echo "OK - MySQL version: {$ver}\n";
    echo "NOW(): {$now}\n";
} catch (Throwable $e) {
    echo "FAIL - " . get_class($e) . "\n";
    echo $e->getMessage() . "\n";
}

echo "</pre>";
