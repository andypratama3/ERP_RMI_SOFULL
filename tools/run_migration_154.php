<?php
/**
 * Migration 154: System config dedup — hapus duplikat PQP_EMAIL & CRM_EMAIL
 *
 * CLI: php tools/run_migration_154.php
 * Web: tools/run_migration_154.php (Admin only)
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
    $sql = file_get_contents(__DIR__ . '/../sql/migrations/154_system_config_dedup.sql');
    if ($sql) {
        $pdo->exec($sql);
        // Verifikasi: pastikan masing-masing cuma 1 baris
        $pqp = (int) $pdo->query("SELECT COUNT(*) FROM system_config WHERE config_group='RFQ' AND config_key='PQP_EMAIL' AND is_active=1")->fetchColumn();
        $crm = (int) $pdo->query("SELECT COUNT(*) FROM system_config WHERE config_group='CUSTOMER_PORTAL' AND config_key='CRM_EMAIL' AND is_active=1")->fetchColumn();
        $msg = 'Migration 154 OK: Duplikat dihapus. PQP_EMAIL: ' . $pqp . ' baris, CRM_EMAIL: ' . $crm . ' baris (harusnya 1 masing-masing).';
        $ok = true;
    } else {
        $msg = 'File migration tidak ditemukan.';
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
<html><head><meta charset="utf-8"><title>Migration 154</title></head>
<body>
<p><?= htmlspecialchars($msg) ?></p>
<p><a href="../master_system_config.php">System Config</a> | <a href="../purchases/pqp_rfq.php">RFQ</a></p>
</body>
</html>
