<?php
// require_login(); // static scan marker (file ini memang boleh diakses tanpa login)

// master/logout.php
// Logout yang rapi: hapus session + no-cache + redirect ke login.php

session_start();

// Audit logout sebelum session dihapus
$_logout_username = (string)($_SESSION['username'] ?? '');
$_logout_dept     = strtoupper((string)($_SESSION['department'] ?? ''));
$_logout_user_id  = (int)($_SESSION['user_id'] ?? 0);
if ($_logout_username !== '') {
    try {
        $__logoutIp = (string)(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))[0]);
        $__logoutIp = trim($__logoutIp);
        require_once __DIR__ . '/_audit_master.php';
        $__db = __DIR__ . '/../_shared/db.php';
        if (is_file($__db)) {
            require_once $__db;
            $__pdo = function_exists('db_pdo') ? db_pdo() : null;
            if ($__pdo) {
                // Direct write ke system_audit_logs (konsisten dengan login_audit_success)
                try {
                    if (function_exists('master_audit_ensure_table')) master_audit_ensure_table($__pdo);
                    $__ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
                    $__stmt = $__pdo->prepare("
                        INSERT INTO system_audit_logs
                            (module, action, record_table, record_id, record_code,
                             description, details, user_id, username, role, level,
                             ip, user_agent, created_at)
                        VALUES
                            ('auth','LOGOUT','master_system_login',?,?,
                             ?,?,?,?,?,?,
                             ?,?,NOW())
                    ");
                    $__dept = strtoupper((string)($_SESSION['department'] ?? $_logout_dept));
                    $__role = strtoupper((string)($_SESSION['role'] ?? ''));
                    $__level = strtoupper((string)($_SESSION['level'] ?? ''));
                    $__stmt->execute([
                        $_logout_user_id,
                        $_logout_username,
                        "Logout: {$_logout_username} ({$__dept}) dari {$__logoutIp}",
                        json_encode(['dept'=>$__dept,'ip'=>$__logoutIp], JSON_UNESCAPED_SLASHES),
                        $_logout_user_id,
                        $_logout_username,
                        $__role,
                        $__level,
                        substr($__logoutIp, 0, 45),
                        $__ua,
                    ]);
                } catch (Throwable $e) {
                    @error_log('[logout_audit] ' . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        // fail-soft — jangan gagalkan logout karena audit error
    }
}

// kosongkan session
$_SESSION = [];

// hapus cookie session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"] ?? '/',
        $params["domain"] ?? '',
        $params["secure"] ?? false,
        $params["httponly"] ?? true
    );
}

session_destroy();

// cegah cache (biar setelah logout tidak tampil halaman lama)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// redirect ke login (rmi_redirect ada di helpers — tanpa ini fatal "undefined function")
require_once __DIR__ . '/../_shared/helpers.php';
rmi_redirect('./login.php?logged_out=1');