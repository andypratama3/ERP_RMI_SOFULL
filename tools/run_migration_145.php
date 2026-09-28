<?php
/**
 * Jalankan migration 145: Vendor deduplication
 * FOR001 → FOR008 (PT NOATUM), FOR004 → FOR010 (PT ZEIST)
 *
 * CLI: php tools/run_migration_145.php
 * Browser: tools/run_migration_145.php (Admin only)
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
$logs = [];
$msg = '';

function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $st->execute([$table, $col]);
        return (bool)$st->fetch();
    } catch (Throwable $e) { return false; }
}

function table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute([$table]);
        return (bool)$st->fetch();
    } catch (Throwable $e) { return false; }
}

try {
    $pairs = [
        ['del' => 'FOR001', 'keep' => 'FOR008', 'name' => 'PT NOATUM'],
        ['del' => 'FOR004', 'keep' => 'FOR010', 'name' => 'PT ZEIST'],
    ];

    foreach ($pairs as $p) {
        $delCode = $p['del'];
        $keepCode = $p['keep'];

        $stKeep = $pdo->prepare("SELECT id FROM master_vendors WHERE vendors_code = ? LIMIT 1");
        $stKeep->execute([$keepCode]);
        $keepId = $stKeep->fetchColumn();
        $stDel = $pdo->prepare("SELECT id FROM master_vendors WHERE vendors_code = ? LIMIT 1");
        $stDel->execute([$delCode]);
        $delId = $stDel->fetchColumn();

        if (!$keepId || !$delId) {
            $logs[] = "Skip {$delCode}→{$keepCode}: " . (!$keepId ? "{$keepCode} tidak ada" : "{$delCode} tidak ada");
            continue;
        }
        $keepId = (int)$keepId;
        $delId = (int)$delId;

        $pdo->beginTransaction();
        try {
            if (table_exists($pdo, 'sales_do') && col_exists($pdo, 'sales_do', 'delivery_vendor_id')) {
                $u = $pdo->prepare("UPDATE sales_do SET delivery_vendor_id = ? WHERE delivery_vendor_id = ?");
                $u->execute([$keepId, $delId]);
                $logs[] = "sales_do.delivery_vendor_id: " . $u->rowCount() . " row(s)";
            }
            if (table_exists($pdo, 'purchases_forwarder_invoice') && col_exists($pdo, 'purchases_forwarder_invoice', 'vendor_id')) {
                $u = $pdo->prepare("UPDATE purchases_forwarder_invoice SET vendor_id = ? WHERE vendor_id = ?");
                $u->execute([$keepId, $delId]);
                $logs[] = "purchases_forwarder_invoice.vendor_id: " . $u->rowCount() . " row(s)";
            }
            if (table_exists($pdo, 'purchases_forwarder_quotes') && col_exists($pdo, 'purchases_forwarder_quotes', 'vendor_id')) {
                $u = $pdo->prepare("UPDATE purchases_forwarder_quotes SET vendor_id = ? WHERE vendor_id = ?");
                $u->execute([$keepId, $delId]);
                $logs[] = "purchases_forwarder_quotes.vendor_id: " . $u->rowCount() . " row(s)";
            }
            if (table_exists($pdo, 'purchases_po') && col_exists($pdo, 'purchases_po', 'forwarder_vendor_id')) {
                $u = $pdo->prepare("UPDATE purchases_po SET forwarder_vendor_id = ? WHERE forwarder_vendor_id = ?");
                $u->execute([$keepId, $delId]);
                $logs[] = "purchases_po.forwarder_vendor_id: " . $u->rowCount() . " row(s)";
            }

            $del = $pdo->prepare("DELETE FROM master_vendors WHERE vendors_code = ?");
            $del->execute([$delCode]);
            $logs[] = "Deleted {$delCode} (" . $del->rowCount() . " row)";
            $pdo->commit();
            $logs[] = "OK: {$delCode} → {$keepCode} ({$p['name']})";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $logs[] = "Error {$delCode}→{$keepCode}: " . $e->getMessage();
        }
    }

    $msg = implode("\n", $logs);
    $ok = !empty($logs) && (strpos($msg, 'OK:') !== false || strpos($msg, 'Skip') !== false);
} catch (Throwable $e) {
    $msg = 'Error: ' . $e->getMessage();
}

if ($cli) {
    echo $msg . "\n";
    exit($ok ? 0 : 1);
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration 145 - Vendor Dedup</title></head>
<body>
<h4>Migration 145: Vendor Deduplication</h4>
<pre><?= htmlspecialchars($msg) ?></pre>
<p><a href="../master/master_vendors.php">← Kembali ke Master Vendors</a></p>
</body>
</html>
