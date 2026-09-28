<?php
/**
 * Migration 151: pqp_rfq, pqp_rfq_quotations — RFQ & quotation comparison
 *
 * CLI (NAS): ./tools/nas/erp.sh php tools/run_migration_151.php
 * Web: tools/run_migration_151.php (Admin only)
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
    $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pqp_rfq'");
    if ($chk && $chk->fetch()) {
        $msg = 'Tabel pqp_rfq sudah ada.';
        $ok = true;
    } else {
        $sql = file_get_contents(__DIR__ . '/../sql/migrations/151_pqp_rfq_quotations.sql');
        if ($sql) {
            $pdo->exec($sql);
            $msg = 'Tabel pqp_rfq dan pqp_rfq_quotations berhasil dibuat.';
            $ok = true;
        } else {
            $msg = 'File migration tidak ditemukan.';
        }
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
<html><head><meta charset="utf-8"><title>Migration 151</title></head>
<body>
<p><?= htmlspecialchars($msg) ?></p>
<p><a href="../manufacturer_portal/login.php">← Manufacturer Portal</a></p>
</body>
</html>
