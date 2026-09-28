<?php
/**
 * customer_portal/do_print.php
 * Print DO (hanya untuk order milik customer portal user).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_portal_login();

$id = (int)($_GET['id'] ?? 0);
$pdo = rmi_db_pdo();
$user = portal_user();

$stmt = $pdo->prepare("
    SELECT d.*, c.customers_name, c.address AS cust_address, o.office_name
    FROM sales_do d
    LEFT JOIN master_customers c ON c.customers_code = d.customers_code
    LEFT JOIN master_office o ON o.office_code = d.office_code
    WHERE d.id = ? AND d.customers_code = ?
");
$stmt->execute([$id, $user['customers_code']]);
$do = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$do) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body><p>DO tidak ditemukan atau tidak ada akses.</p></body></html>';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM sales_do_items WHERE do_id = ? ORDER BY line_no");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$do_code = rmi_h($do['do_code']);
$do_date = date('d-m-Y', strtotime($do['do_date'] ?? 'now'));
$cust_name = rmi_h($do['customers_name'] ?? $do['customers_code']);
$ship_addr = nl2br(rmi_h($do['shipping_address'] ?? $do['cust_address'] ?? ''));
$note = rmi_h($do['note'] ?? '');
$grand = number_format((float)($do['grand_total'] ?? 0), 0, ',', '.');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>DO <?= $do_code ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; max-width: 800px; margin: 20px auto; padding: 20px; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 15px; }
        .company { font-weight: bold; font-size: 14px; }
        .do-code { font-size: 16px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #f5f5f5; }
        .text-right { text-align: right; }
        .total { font-weight: bold; font-size: 14px; margin-top: 10px; }
        .note { margin-top: 15px; font-size: 11px; color: #666; }
        @media print { body { margin: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="no-print" style="margin-bottom: 15px;">
    <a href="javascript:window.print()" style="padding: 8px 16px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px;">Print</a>
    <a href="order_detail.php?id=<?= $id ?>" style="margin-left: 8px; padding: 8px 16px; background: #6b7280; color: white; text-decoration: none; border-radius: 6px;">Kembali</a>
</div>
<div class="header">
    <div>
        <div class="company">Rizqullah Mediska Indonesia</div>
        <div>Delivery Order</div>
    </div>
    <div>
        <div class="do-code"><?= $do_code ?></div>
        <div>Tanggal: <?= $do_date ?></div>
    </div>
</div>
<div>
    <strong>Customer:</strong> <?= $cust_name ?><br>
    <strong>Alamat Kirim:</strong><br><?= $ship_addr ?>
</div>
<table>
    <thead><tr><th>#</th><th>SKU</th><th>Produk</th><th>Qty</th><th>Unit</th><th>Harga</th><th>Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i => $it): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= rmi_h($it['sku']) ?></td>
            <td><?= rmi_h($it['products_name']) ?></td>
            <td class="text-right"><?= (int)$it['qty'] ?></td>
            <td><?= rmi_h($it['unit']) ?></td>
            <td class="text-right"><?= number_format((float)$it['unit_price'], 0, ',', '.') ?></td>
            <td class="text-right"><?= number_format((float)$it['subtotal'], 0, ',', '.') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<div class="total text-right">Total: Rp <?= $grand ?></div>
<?php if ($note !== ''): ?><div class="note"><strong>Catatan:</strong> <?= $note ?></div><?php endif; ?>
</body>
</html>
