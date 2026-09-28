<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.PO_CRUD', 'PURCHASES.VIEW']);
} else {
    require_role(['ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$id=(int)($_GET['id'] ?? 0);
if ($id<=0) { echo "Invalid."; exit; }

$po=null; $items=[];
try {
  $st=$pdo->prepare("SELECT po.*, m.manufacture_name, m.manufacture_code, o.office_name, pr.pr_code
                     FROM purchases_po po
                     LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
                     LEFT JOIN master_office o ON o.office_code=po.office_code
                     LEFT JOIN wqs_pr pr ON pr.id=po.pr_id
                     WHERE po.id=? LIMIT 1");
  $st->execute([$id]); $po=$st->fetch(PDO::FETCH_ASSOC);

  $st2=$pdo->prepare("SELECT * FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL ORDER BY line_no ASC");
  $st2->execute([$id]); $items=$st2->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!$po) { echo "PO not found."; exit; }
$canPrice = p_can_view_buy_price();

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Print PO', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Print PO',
  ],
  'extra_head' => '<style>
  body{font-family:Arial, sans-serif; font-size:12px; color:#111; margin:20px;}
  .top{display:flex; justify-content:space-between; align-items:flex-start;}
  h2{margin:0 0 6px 0;}
  table{width:100%; border-collapse:collapse; margin-top:12px;}
  th,td{border:1px solid #333; padding:6px;}
  th{background:#f2f2f2;}
  .right{text-align:right;}
  .muted{color:#666;}
  @media print {.no-print{display:none}}
</style>',
]);
?>

<div class="no-print" style="margin-bottom:10px">
  <button onclick="window.print()">Print</button>
</div>

<div class="top">
  <div>
    <h2>PURCHASE ORDER</h2>
    <div class="muted">Rizqullah Mediska Indonesia</div>
  </div>
  <div style="text-align:right">
    <div><b><?=h($po['po_code'])?></b></div>
    <div>Date: <?=h($po['po_date'])?></div>
    <div>Status: <?=h($po['status'])?></div>
  </div>
</div>

<hr>

<table style="margin-top:8px">
  <tr>
    <td style="width:50%">
      <b>Manufacture (Pabrikan)</b><br>
      <?=h($po['manufacture_name'] ?? '')?><br>
      <span class="muted"><?=h($po['manufacture_code'] ?? '')?></span>
    </td>
    <td style="width:50%">
      <b>Office</b><br>
      <?=h($po['office_name'] ?? $po['office_code'])?><br>
      <span class="muted"><?=h($po['office_code'])?></span>
    </td>
  </tr>
</table>

<table style="margin-top:8px">
  <tr>
    <td style="width:50%"><b>PR</b><br><?=h($po['pr_code'] ?? '-')?></td>
    <td style="width:50%"><b>Payment Term</b><br><?=h($po['payment_term'] ?? '-')?></td>
  </tr>
</table>

<table>
  <thead>
    <tr>
      <th style="width:40px">No</th>
      <th style="width:90px">SKU</th>
      <th>Product</th>
      <th style="width:90px" class="right">Qty</th>
      <th style="width:70px">Unit</th>
      <?php if ($canPrice): ?>
        <th style="width:120px" class="right">Unit Price</th>
        <th style="width:130px" class="right">Subtotal</th>
      <?php else: ?>
        <th style="width:120px">Unit Price</th>
        <th style="width:130px" class="right">Subtotal</th>
      <?php endif; ?>
    </tr>
  </thead>
  <tbody>
    <?php $no=1; foreach($items as $it): ?>
      <tr>
        <td class="right"><?=h($no++)?></td>
        <td><?=h(rmi_sku($it['sku']))?></td>
        <td><?=h(rmi_product_name($it['products_name']))?></td>
        <td class="right"><?=h($it['qty'])?></td>
        <td><?=h($it['unit'])?></td>
        <?php if ($canPrice): ?>
          <td class="right"><?=h(p_money($it['unit_price'], $po['currency']))?></td>
          <td class="right"><?=h(p_money($it['subtotal'], $po['currency']))?></td>
        <?php else: ?>
          <td class="muted">Restricted</td>
          <td class="right">0</td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <th colspan="6" class="right">Total</th>
      <th class="right"><?=h($canPrice ? p_money($po['total_amount'],$po['currency']) : '0')?></th>
    </tr>
  </tfoot>
</table>

<div style="margin-top:14px">
  <b>Note:</b><br>
  <?=nl2br(h($po['note'] ?? ''))?>
</div>

<div style="margin-top:30px; display:flex; gap:40px">
  <div style="text-align:center; width:240px">
    <div>Prepared By (PQP)</div>
    <div style="margin-top:60px">(__________________)</div>
  </div>
  <div style="text-align:center; width:240px">
    <div>Approved By</div>
    <div style="margin-top:60px">(__________________)</div>
  </div>
</div>
<?php rmi_footer(); ?>
