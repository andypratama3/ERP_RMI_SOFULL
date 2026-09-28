<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/_purchases_lib.php';

$user = function_exists('auth_user') ? auth_user() : [];
$dept = strtoupper(trim((string)($user['department'] ?? ($_SESSION['department'] ?? ''))));
$role = strtoupper(trim((string)($user['role'] ?? ($_SESSION['role'] ?? ''))));
$level = strtoupper(trim((string)($user['level'] ?? ($_SESSION['level'] ?? ''))));
$isPriv = in_array($dept, ['SYS','SYSTEM'], true)
       || in_array($role, ['SYS','ADMIN','SUPERADMIN'], true)
       || in_array($level, ['SYS','ADMIN','SUPERADMIN'], true);
$isFin = in_array($dept, ['FIN','FINANCE'], true) || strpos($role, 'FIN') !== false;
$isPqp = ($dept === 'PQP') || strpos($role, 'PQP') !== false;

// Neutral READ-ONLY PO detail for monitoring.
// No header/item/status/cancel mutation is exposed here.
if (!$isFin && !$isPqp && !$isPriv) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = p_pdo();
p_ensure_schema($pdo);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'PO tidak valid.'; exit; }

$po = null; $items = []; $incoming = []; $aps = [];
try {
    $st = $pdo->prepare("SELECT po.*, o.office_name, m.manufacture_name, pr.pr_code
        FROM purchases_po po
        LEFT JOIN master_office o ON o.office_code=po.office_code
        LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
        LEFT JOIN wqs_pr pr ON pr.id=po.pr_id
        WHERE po.id=? AND po.deleted_at IS NULL LIMIT 1");
    $st->execute([$id]);
    $po = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}
if (!$po) { http_response_code(404); echo 'PO tidak ditemukan.'; exit; }

try {
    $st=$pdo->prepare("SELECT * FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL ORDER BY line_no,id");
    $st->execute([$id]); $items=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e) {}

try {
    $cols = p_cols($pdo,'wqs_incoming');
    $deleted = in_array('deleted_at',$cols,true) ? ' AND deleted_at IS NULL' : '';
    $st=$pdo->prepare("SELECT * FROM wqs_incoming WHERE po_code=?{$deleted} ORDER BY id DESC LIMIT 20");
    $st->execute([(string)$po['po_code']]); $incoming=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e) {}

try {
    $st=$pdo->prepare("SELECT ap.*,
        COALESCE((SELECT SUM(p.amount) FROM purchases_payment_ap p WHERE p.ap_id=ap.id AND p.deleted_at IS NULL),0) AS paid_amount
        FROM purchases_invoice_ap ap
        WHERE ap.po_id=? AND ap.deleted_at IS NULL ORDER BY ap.id DESC");
    $st->execute([$id]); $aps=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e) {}

$flow = p_po_flow($po);
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();
rmi_header('PO Detail - Read Only', [
  'active'=>'purchases',
  'breadcrumbs'=>[
    ['label'=>'Purchases', 'url'=>'purchases_control_tower.php'],
    ['label'=>'Local Purchase Control Tower', 'url'=>'purchases_control_tower.php'],
    'PO Detail - Read Only'
  ],
  'actions'=>[
    ['label'=>'Local Tower','url'=>'purchases_control_tower.php','class'=>'btn btn-sm btn-outline-light'],
  ],
  'extra_head'=>'<style>
    body{background:#0b1220;color:#e5e7eb}.card{background:rgba(17,24,39,.86);border:1px solid rgba(255,255,255,.08);border-radius:16px}.muted{color:#94a3b8;font-size:12px}.pill{display:inline-block;padding:3px 9px;border-radius:999px;border:1px solid rgba(255,255,255,.14);font-size:12px}.num{text-align:right}
  </style>'
]);
?>
<div class="container py-3" style="max-width:1200px">
  <div class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2">
      <div>
        <div class="muted">READ ONLY — halaman monitoring. Tidak ada perubahan header/item/status/cancel PO dari halaman ini.</div>
        <h3 class="mb-1"><?=h($po['po_code'] ?? '')?></h3>
        <span class="pill"><?=h($flow)?></span> <span class="pill"><?=h(strtoupper((string)($po['status'] ?? '')))?></span>
      </div>
      <div class="text-end">
        <div class="muted">Total PO</div>
        <div style="font-size:22px;font-weight:800"><?=h(p_money($po['total_amount'] ?? 0,$po['currency'] ?? 'IDR'))?></div>
      </div>
    </div>
    <hr style="border-color:rgba(255,255,255,.1)">
    <div class="row g-3">
      <div class="col-md-3"><div class="muted">Tanggal</div><b><?=h($po['po_date'] ?? '-')?></b></div>
      <div class="col-md-3"><div class="muted">PR</div><b><?=h($po['pr_code'] ?? '-')?></b></div>
      <div class="col-md-3"><div class="muted">Office</div><b><?=h($po['office_name'] ?? $po['office_code'] ?? '-')?></b></div>
      <div class="col-md-3"><div class="muted">Manufacture</div><b><?=h($po['manufacture_name'] ?? '-')?></b></div>
    </div>
  </div></div>

  <div class="card mb-3"><div class="card-body">
    <h5>Items PO</h5>
    <div class="table-responsive"><table class="table table-dark table-sm align-middle">
      <thead><tr><th>SKU</th><th>Product</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit Price</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach($items as $it): ?>
        <tr>
          <td><?=h($it['sku'] ?? '')?></td><td><?=h($it['products_name'] ?? '')?></td>
          <td class="num"><?=h($it['qty'] ?? 0)?></td><td><?=h($it['unit'] ?? '')?></td>
          <td class="num"><?=h(p_money($it['unit_price'] ?? 0,$po['currency'] ?? 'IDR'))?></td>
          <td class="num"><?=h(p_money(($it['total_after_tax'] ?? $it['subtotal'] ?? 0),$po['currency'] ?? 'IDR'))?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$items): ?><tr><td colspan="6" class="muted">Tidak ada item.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div></div>

  <div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
      <h5>Receiving WQS</h5>
      <?php if(!$incoming): ?><div class="muted">Belum ada receiving.</div><?php else: ?>
        <div class="muted"><?=count($incoming)?> receiving tercatat. FIN cukup memastikan barang sudah diterima sebelum AP diproses.</div>
      <?php endif; ?>
    </div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
      <h5>AP / Payment</h5>
      <?php if(!$aps): ?>
        <div class="muted mb-2">Belum ada AP Invoice untuk PO ini.</div>
        
      <?php else: ?>
        <?php foreach($aps as $ap): $tot=(float)($ap['total_amount']??0); $paid=(float)($ap['paid_amount']??0); ?>
          <div style="border-bottom:1px solid rgba(255,255,255,.08);padding:8px 0">
            <b><?=h($ap['ap_code'] ?? '')?></b> · <?=h(strtoupper((string)($ap['status'] ?? '')))?><br>
            <span class="muted">Total <?=h(p_money($tot,$ap['currency'] ?? 'IDR'))?> · Paid <?=h(p_money($paid,$ap['currency'] ?? 'IDR'))?></span>
          </div>
        <?php endforeach; ?>
        
      <?php endif; ?>
    </div></div></div>
  </div>
</div>
<?php rmi_footer(); ?>
