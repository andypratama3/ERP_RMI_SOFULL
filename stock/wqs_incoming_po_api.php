<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT', 'PURCHASES.GR_PROCESS', 'WQS.VIEW']);
} else {
    require_role(['WQS', 'ADMIN', 'SUPERADMIN', 'SYS', 'SCM', 'PQP']);
}

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('wqs_json')) {
    function wqs_json(array $payload, int $status = 200): void {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function up($v): string { return strtoupper(trim((string)($v ?? ''))); }

try {
    $pdo = db_pdo();
} catch (Throwable $e) {
    wqs_json(['ok' => false, 'msg' => 'DB unavailable'], 500);
}

$poCode = trim((string)($_GET['po_code'] ?? ''));
if ($poCode === '') {
    wqs_json(['ok' => false, 'msg' => 'po_code wajib diisi'], 400);
}

try {
    $stPo = $pdo->prepare("
      SELECT p.id, p.po_code, p.office_code,
             COALESCE(p.note, p.forwarder_note, '') AS note,
             COALESCE(v.vendors_name, '') AS vendor_name
      FROM purchases_po p
      LEFT JOIN master_vendors v ON v.id = p.forwarder_vendor_id
      WHERE UPPER(TRIM(p.po_code)) = UPPER(TRIM(?))
      LIMIT 1
    ");
    $stPo->execute([$poCode]);
    $po = $stPo->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    wqs_json(['ok' => false, 'msg' => 'Gagal membaca PO'], 500);
}

if (!$po) {
    wqs_json(['ok' => false, 'msg' => 'PO tidak ditemukan'], 404);
}

$poId = (int)($po['id'] ?? 0);
if ($poId <= 0) {
    wqs_json(['ok' => false, 'msg' => 'PO tidak valid'], 400);
}

try {
    $sql = "
      SELECT
        ppi.id AS po_item_id,
        UPPER(COALESCE(NULLIF(TRIM(p.sku),''), NULLIF(TRIM(ppi.sku),''), '')) AS sku,
        COALESCE(ppi.products_name, p.products_name, '') AS products_name,
        COALESCE(ppi.qty, 0) AS po_qty,
        COALESCE(rcv.received_qty, 0) AS received_qty
      FROM purchases_po_items ppi
      LEFT JOIN master_products p ON p.id = ppi.product_id
      LEFT JOIN (
        SELECT
          COALESCE(wii.po_item_id, 0) AS po_item_id,
          COALESCE(wii.sku, '') AS sku,
          SUM(COALESCE(wii.qty, 0)) AS received_qty
        FROM wqs_incoming_items wii
        INNER JOIN wqs_incoming wi ON wi.id = wii.incoming_id
        WHERE wi.deleted_at IS NULL
        GROUP BY COALESCE(wii.po_item_id, 0), COALESCE(wii.sku, '')
      ) rcv
        ON (rcv.po_item_id > 0 AND rcv.po_item_id = ppi.id)
        OR (rcv.po_item_id = 0 AND rcv.sku <> '' AND rcv.sku = ppi.sku)
      WHERE ppi.po_id = ?
        AND (ppi.deleted_at IS NULL OR ppi.deleted_at = '0000-00-00 00:00:00')
      ORDER BY ppi.id ASC
    ";
    $stItems = $pdo->prepare($sql);
    $stItems->execute([$poId]);
    $rows = $stItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    wqs_json(['ok' => false, 'msg' => 'Gagal membaca item PO'], 500);
}

$items = [];
foreach ($rows as $r) {
    $sku = up(trim((string)($r['sku'] ?? '')));
    if ($sku === '') continue;
    $poQty = (float)($r['po_qty'] ?? 0);
    $received = (float)($r['received_qty'] ?? 0);
    $outstanding = max(0.0, $poQty - $received);
    if ($outstanding <= 0) continue;
    $items[] = [
        'po_item_id' => (int)($r['po_item_id'] ?? 0),
        'sku' => $sku,
        'name' => up(trim((string)($r['products_name'] ?? ''))),
        'qty' => $outstanding,
        'outstanding_qty' => $outstanding,
    ];
}

wqs_json([
    'ok' => true,
    'po_code' => (string)($po['po_code'] ?? $poCode),
    'office_code' => up($po['office_code'] ?? ''),
    'vendor_name' => (string)($po['vendor_name'] ?? ''),
    'note' => (string)($po['note'] ?? ''),
    'items' => $items,
]);

