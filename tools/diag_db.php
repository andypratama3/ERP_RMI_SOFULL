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

// Simple DB diagnostic tool
// URL: http://127.0.0.1/tools/diag_db.php (atau APP_URL/tools/diag_db.php)

require_once __DIR__ . '/../_shared/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "RMI DB DIAG\n";
echo "==========\n\n";

$cfg = rmi_db_config();
foreach ($cfg as $k => $v) {
    if ($k === 'pass' && $v !== null) {
        $v = str_repeat('*', 8);
    }
    echo str_pad($k, 10) . ": " . $v . "\n";
}

echo "\nTrying rmi_db_pdo() ...\n";
try {
    $pdo = rmi_db_pdo();
    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "OK - MySQL version: {$ver}\n";

    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo "DB: {$db}\n";

    $now = $pdo->query('SELECT NOW()')->fetchColumn();
    echo "NOW(): {$now}\n";

} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n\n";
    echo "Jika error kamu: SQLSTATE[HY000] [2002] Connection refused\n";
    echo "- Pastikan MySQL service nyala (port 3306).\n";
    echo "- Coba set DB_HOST='localhost' di config.php (socket)\n";
    echo "- Pastikan DB_PORT sesuai (lihat phpMyAdmin header: localhost:PORT)\n";
    exit(1);
}
