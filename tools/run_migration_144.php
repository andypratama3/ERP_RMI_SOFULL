<?php
/**
 * Jalankan migration 144: master_vendors.office_code
 * CLI: php tools/run_migration_144.php
 * Atau akses via browser (untuk NAS tanpa CLI): tools/run_migration_144.php
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
    $stmt = $pdo->prepare("SHOW COLUMNS FROM master_vendors LIKE 'office_code'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        $msg = 'Kolom office_code sudah ada. Tidak perlu migrasi.';
        $ok = true;
    } else {
        $pdo->exec("ALTER TABLE master_vendors ADD COLUMN office_code VARCHAR(10) DEFAULT NULL AFTER vendors_name");
        $msg = 'Kolom office_code berhasil ditambahkan ke master_vendors.';
        $ok = true;
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
<html><head><meta charset="utf-8"><title>Migration 144</title></head>
<body>
<p><?= htmlspecialchars($msg) ?></p>
<p><a href="../master/master_vendors.php">← Kembali ke Master Vendors</a></p>
</body>
</html>
