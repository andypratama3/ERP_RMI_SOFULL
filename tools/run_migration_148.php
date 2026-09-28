<?php
/**
 * Migration 148: Vendor category LOGISTIC → SCM
 * Kategori seharusnya hanya kode Dept (SCM, PQP, dll). LOGISTIC = vendor_type, bukan dept.
 *
 * CLI: php tools/run_migration_148.php
 * Browser: tools/run_migration_148.php (Admin only)
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
    $st = $pdo->prepare("UPDATE master_vendors SET category = 'SCM' WHERE UPPER(TRIM(COALESCE(category,''))) IN ('LOGISTIC', 'FORWARDING LOGISTIC')");
    $st->execute();
    $cnt = $st->rowCount();
    $msg = $cnt > 0
        ? "Berhasil: {$cnt} vendor dengan category LOGISTIC/Forwarding LOGISTIC diperbaiki ke SCM."
        : "Tidak ada vendor dengan category LOGISTIC. Sudah benar.";
    $ok = true;
} catch (Throwable $e) {
    $msg = 'Error: ' . $e->getMessage();
}

if ($cli) {
    echo $msg . "\n";
    exit($ok ? 0 : 1);
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration 148 - Vendor Category Fix</title></head>
<body>
<h4>Migration 148: Vendor Category (LOGISTIC → SCM)</h4>
<p><?= htmlspecialchars($msg) ?></p>
<p><a href="../master/master_vendors.php">← Kembali ke Master Vendors</a></p>
</body>
</html>
