<?php
/**
 * Migration 120: RBAC HRL Process permissions — PQP, ACT, CRM, MPR, SCM, WQS, ITC, REG, BRANCH
 *
 * Memperbaiki 403 Forbidden saat user PQP/dept lain akses HRL Process.
 *
 * CLI: php tools/run_migration_120.php
 * Web: tools/run_migration_120.php (Admin only)
 */
declare(strict_types=1);

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    require_once __DIR__ . '/../_shared/bootstrap.php';
    require_once __DIR__ . '/../master/auth.php';
    require_login();
    if (!function_exists('auth_is_admin') || !auth_is_admin()) {
        http_response_code(403);
        die('Admin only.');
    }
}

require_once __DIR__ . '/../_shared/db.php';
$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();

$ok = false;
$msg = '';

try {
    $sqlPath = __DIR__ . '/../sql/migrations/120_rbac_hrl_process_permissions.sql';
    $sql = is_file($sqlPath) ? file_get_contents($sqlPath) : '';
    if ($sql) {
        $pdo->exec($sql);
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM rbac_dept_role_permissions WHERE perm_code='HRL.PROCESS_VIEW' AND dept_code='PQP'")->fetchColumn();
        $msg = "Migration 120 OK: HRL Process permissions seeded. PQP+HRL.PROCESS_VIEW: {$cnt} baris.";
        $ok = true;
    } else {
        $msg = 'File sql/migrations/120_rbac_hrl_process_permissions.sql tidak ditemukan.';
    }
} catch (PDOException $e) {
    $msg = 'Error: ' . $e->getMessage();
}

if ($cli) {
    echo $msg . "\n";
    exit($ok ? 0 : 1);
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration 120</title></head>
<body>
<p><?= htmlspecialchars($msg) ?></p>
<p><a href="../hrl_process/index.php">HRL Process</a> | <a href="../purchases/purchases_dashboard.php">PQP Dashboard</a> | <a href="../master_system_config.php">System Config</a></p>
</body>
</html>
