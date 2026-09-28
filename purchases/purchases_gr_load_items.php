<?php
declare(strict_types=1);

require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../master/auth.php';
require_login();
require_any_permission(['PURCHASES.GR_PROCESS', 'WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT']);
if (defined('APP_DEBUG') && APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

$po_id = isset($_GET['po_id']) ? trim((string)$_GET['po_id']) : '';
if ($po_id === '') {
    http_response_code(400);
    exit('Missing po_id');
}

$pdo = rmi_db_pdo();
$stmt = $pdo->prepare("
    SELECT i.*, p.products_name
    FROM purchases_po_items i
    LEFT JOIN master_products p ON i.product_id = p.id
    WHERE i.po_id = ? AND i.deleted_at IS NULL
");
$stmt->execute([$po_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "
<table>
<tr>
    <th>Produk</th>
    <th>Qty PO</th>
    <th>Qty Diterima</th>
    <th>Unit</th>
</tr>
";

foreach ($rows as $row) {
    $productsName = rmi_h(rmi_product_name($row['products_name'] ?? ''));
    $qty = rmi_h($row['qty'] ?? '');
    $id = rmi_h($row['id'] ?? '');
    $productId = rmi_h($row['product_id'] ?? '');
    $unit = rmi_h($row['unit'] ?? 'UNIT');
    echo "
    <tr>
        <td>{$productsName}</td>
        <td>{$qty}</td>
        <td>
            <input type='hidden' name='po_item_id[]' value='{$id}'>
            <input type='hidden' name='product_id[]' value='{$productId}'>
            <input type='number' name='qty_received[]' step='0.01' required>
        </td>
        <td><input type='text' name='unit[]' value='{$unit}'></td>
    </tr>
    ";
}

echo "</table>";
