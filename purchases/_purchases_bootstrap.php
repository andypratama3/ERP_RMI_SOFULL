<?php
/**
 * purchases/_purchases_bootstrap.php
 *
 * Tujuan (M2):
 * - Satu pintu bootstrap untuk modul Purchases
 * - Pastikan shared bootstrap (helpers + db + auth) ter-load
 * - Sediakan helper kecil untuk login + PDO
 *
 * Catatan:
 * - File ini kompatibel dengan code lama yang masih pakai master/auth.php + _purchases_lib.php.
 * - Tidak memaksa refactor query di file-file purchases yang sudah jalan.
 */

declare(strict_types=1);

if (!defined('RMI_PURCHASES_BOOTSTRAPPED')) {
    define('RMI_PURCHASES_BOOTSTRAPPED', true);
}

// Load shared bootstrap (helpers + db + auth)
require_once __DIR__ . '/../_shared/bootstrap.php';

// Load purchases library if exists
$lib = __DIR__ . '/_purchases_lib.php';
if (is_file($lib)) {
    require_once $lib;
}

if (!function_exists('purchases_require_login')) {
    /**
     * Wajib login untuk halaman purchases.
     * - Kalau CLI: skip (biar script internal tidak ke-redirect).
     * - Kalau ada require_login() dari master/auth.php: pakai itu.
     */
    function purchases_require_login(): void {
        if (PHP_SAPI === 'cli') return;

        if (function_exists('require_login')) {
            require_login();
            return;
        }

        // Fallback sangat sederhana
        $hasSession = !empty($_SESSION['user_id']) || !empty($_SESSION['username']) || !empty($_SESSION['user_name']);
        if ($hasSession) return;

        $next = $_SERVER['REQUEST_URI'] ?? '/purchases/purchases_dashboard.php';
        $login = '/master/login.php?next=' . urlencode($next);
        rmi_redirect($login);
    }
}

if (!function_exists('purchases_pdo')) {
    /**
     * Ambil PDO dari shared db.
     * Kalau project kamu punya db_pdo() di master/auth.php, rmi_db_pdo() akan proxy ke sana.
     */
    function purchases_pdo(): PDO {
        return rmi_db_pdo();
    }
}

// Convenience: expose $pdo global (optional)
if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
    try {
        $GLOBALS['pdo'] = purchases_pdo();
    } catch (Throwable $e) {
        // Biarkan halaman yang handle; jangan fatal di bootstrap.
    }
}
