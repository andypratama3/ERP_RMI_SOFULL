<?php
/**
 * Migration 149: Vendor duplicate index — dukung multi-cabang
 * Drop UNIQUE (vendors_name_normalized), add UNIQUE (vendors_name_normalized, office_code).
 * BARAKA EXPRESS BGR/SLO/SMG = bukan duplikat.
 *
 * CLI: php tools/run_migration_149.php
 * Browser: tools/run_migration_149.php (Admin only)
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
    // Drop index lama
    $pdo->exec("ALTER TABLE master_vendors DROP INDEX uq_vendors_name_norm");
    $msg = 'Index uq_vendors_name_norm di-drop. ';
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "check that it exists") !== false || strpos($e->getMessage(), "1091") !== false) {
        $msg = 'Index uq_vendors_name_norm tidak ada (sudah di-drop). ';
    } else {
        throw $e;
    }
}

try {
    $pdo->exec("ALTER TABLE master_vendors ADD UNIQUE INDEX uq_vendors_name_norm_office (vendors_name_normalized, office_code)");
    $msg .= 'Index uq_vendors_name_norm_office (vendors_name_normalized, office_code) ditambahkan.';
    $ok = true;
} catch (PDOException $e) {
    $err = $e->getMessage();
    if (strpos($err, 'Duplicate') !== false || strpos($err, '1061') !== false || strpos($err, 'already exists') !== false) {
        $msg .= 'Index uq_vendors_name_norm_office sudah ada.';
        $ok = true;
    } else {
        $msg .= 'Error: ' . $err;
    }
}

if ($cli) {
    echo $msg . "\n";
    exit($ok ? 0 : 1);
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration 149 - Vendor Dup Index</title></head>
<body>
<h4>Migration 149: Vendor Duplicate Index (Multi-Cabang)</h4>
<p><?= htmlspecialchars($msg) ?></p>
<p><a href="../master/master_vendors.php">← Kembali ke Master Vendors</a></p>
</body>
</html>
