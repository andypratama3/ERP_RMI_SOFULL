<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
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

$flash = p_flash_get();
$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { echo "PO ID tidak valid."; exit; }

$po=null;
try {
  $st=$pdo->prepare("SELECT po.*, m.manufacture_name, m.manufacture_code, o.office_name, pr.pr_code
                     FROM purchases_po po
                     LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
                     LEFT JOIN master_office o ON o.office_code=po.office_code
                     LEFT JOIN wqs_pr pr ON pr.id=po.pr_id
                     WHERE po.id=? LIMIT 1");
  $st->execute([$id]);
  $po=$st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (!$po) { echo "PO tidak ditemukan."; exit; }

$canPrice = p_can_view_buy_price();
$canEdit = p_can_create_po() || p_is_admin_plus();

function reload_here($id){ header("Location: purchases_po_view.php?id=".$id); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
}

// Update header (PQP/Admin)
if (isset($_POST['update_header']) && $canEdit) {
  $po_date = $_POST['po_date'] ?? $po['po_date'];
  $note = trim((string)($_POST['note'] ?? $po['note']));
  $payment_term = up($_POST['payment_term'] ?? $po['payment_term']);
  $status = up($_POST['status'] ?? $po['status']);
  if (!in_array($status,['DRAFT','OPEN','IN_PRODUCTION','READY','CLOSED','CANCELLED'],true)) $status = $po['status'];
  $currentStatus = up((string)($po['status'] ?? 'OPEN'));
  $allowedTransitions = [
    'DRAFT' => ['OPEN', 'CANCELLED'],
    'OPEN' => ['IN_PRODUCTION', 'CANCELLED'],
    'IN_PRODUCTION' => ['READY', 'CANCELLED'],
    'READY' => ['CLOSED', 'CANCELLED'],
    'CLOSED' => [],
    'CANCELLED' => [],
  ];
  if ($status !== $currentStatus) {
    $nextAllowed = $allowedTransitions[$currentStatus] ?? [];
    if (!in_array($status, $nextAllowed, true)) {
      p_flash_set('danger', 'Transisi status tidak valid: ' . $currentStatus . ' -> ' . $status);
      reload_here($id);
    }
  }

  // Guard: jangan lanjut status produksi kalau total PO masih 0 (harga belum diinput)
  $po_total_now = (float)($po['total_amount'] ?? 0);
  if (in_array($status,['IN_PRODUCTION','READY','CLOSED'],true) && $po_total_now <= 0) {
    p_flash_set('danger','Tidak bisa set status '.$status.' karena Total PO masih 0. Isi harga beli (unit price) di item PO terlebih dahulu.');
    reload_here($id);
  }
  if ($status==='OPEN' && $po_total_now <= 0) {
    p_flash_set('warning','PO diset OPEN tapi Total PO masih 0. DP invoice otomatis tidak bisa dihitung sebelum harga beli diisi.');
  }


  try {
    $pdo->prepare("UPDATE purchases_po SET po_date=?, payment_term=?, note=?, status=? WHERE id=?")
        ->execute([$po_date,$payment_term,$note,$status,$id]);
    p_audit($pdo,'PO',$po['po_code'],'UPDATE_HEADER',['id'=>$id,'status'=>$status]);
    p_flash_set('success','Header PO updated.');
  } catch (Throwable $e) { p_flash_set('danger','Gagal update header PO.'); }
  reload_here($id);
}

// Save items
if (isset($_POST['save_items']) && $canEdit) {
  $item_ids = $_POST['item_id'] ?? [];
  $qtys = $_POST['qty'] ?? [];
  $units = $_POST['unit'] ?? [];
  $prices = $_POST['unit_price'] ?? [];
  $zeroPriceCount = 0;

  $pdo->beginTransaction();
  try {
    for ($i=0;$i<count($item_ids);$i++){
      $iid=(int)$item_ids[$i];
      $qty=(float)($qtys[$i] ?? 0);
      $unit=trim((string)($units[$i] ?? 'pcs'));
      $price=(float)($prices[$i] ?? 0);
      if (!$canPrice) $price=0;
      $subtotal=$qty*$price;
      $pdo->prepare("UPDATE purchases_po_items SET qty=?, unit=?, unit_price=?, subtotal=? WHERE id=? AND po_id=?")
          ->execute([$qty,$unit,$price,$subtotal,$iid,$id]);
    }
    p_recalc_po_total($pdo,$id);
    $pdo->commit();
    p_audit($pdo,'PO',$po['po_code'],'UPDATE_ITEMS',['count'=>count($item_ids)]);
    if ($canPrice && $zeroPriceCount > 0) {
      p_flash_set('warning','Ada '.$zeroPriceCount.' item dengan harga 0. Total PO bisa 0 dan DP invoice otomatis tidak bisa dihitung.');
    }
    p_flash_set('success','Items updated.');
  } catch (Throwable $e) {
    $pdo->rollBack();
    p_flash_set('danger','Gagal update item PO.');
  }
  reload_here($id);
}

// Items load
$items=[];
try {
  $st=$pdo->prepare("SELECT * FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL ORDER BY line_no ASC,id ASC");
  $st->execute([$id]);
  $items=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$terms=[];
try { $terms=$pdo->query("SELECT term_code FROM master_payment_terms ORDER BY term_code")->fetchAll(); } catch (Throwable $e) {}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$extraHead = '<style>
    body{background:radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
    .muted{color:#9ca3af;font-size:12px}
    .form-control,.form-select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.14)!important}
    label{color:#cbd5e1;font-size:12px}
    .btn-soft{border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e5e7eb}
    .btn-soft:hover{background:rgba(255,255,255,.10);color:#fff}
    .num{text-align:right}
  </style>';

rmi_header('PO View', 'purchases', [
  'subtitle' => 'Detail Purchase Order.',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    ['label' => 'PO List', 'url' => $baseProject . '/purchases/purchases_po.php'],
    'PO View',
  ],
  'actions' => [
    ['label' => 'PO List', 'url' => 'purchases_po.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Print', 'url' => 'purchases_po_print.php?id=' . h($id), 'class' => 'btn btn-sm btn-outline-light', 'attrs' => 'target="_blank"'],
    ['label' => 'WQS Incoming', 'url' => '../stock/wqs_incoming.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Open Chat', 'url' => $baseProject . '/chat/index.php?context=PO:' . (int)$id, 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'extra_head' => $extraHead,
]);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted">PO</div>
    <h3 class="mb-0"><?=h(strtoupper($po['po_code'] ?? ''))?></h3>
    <div class="muted">PR: <?=h(strtoupper($po['pr_code'] ?? '-'))?> • Manufacture: <?=h(strtoupper($po['manufacture_name'] ?? '-'))?> • Office: <?=h(strtoupper($po['office_name'] ?? $po['office_code'] ?? ''))?></div>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">Header</div>
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="update_header" value="1">
          <div class="col-md-4">
            <label class="form-label">PO Date</label>
            <input class="form-control form-control-sm" type="date" name="po_date" value="<?=h($po['po_date'])?>" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select class="form-select form-select-sm" name="status" <?= $canEdit?'':'disabled' ?>>
              <?php foreach(['OPEN','IN_PRODUCTION','READY','CLOSED','CANCELLED'] as $s): ?>
                <option value="<?=h($s)?>" <?= up($po['status'])===$s?'selected':'' ?>><?=h($s)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Payment Term</label>
            <select class="form-select form-select-sm" name="payment_term" <?= $canEdit?'':'disabled' ?>>
              <option value="">--</option>
              <?php foreach($terms as $t): ?>
                <option value="<?=h(strtoupper($t['term_code']??''))?>" <?= up($po['payment_term'] ?? '')===up($t['term_code'])?'selected':'' ?>><?=h(strtoupper($t['term_code']??''))?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Note</label>
            <textarea class="form-control form-control-sm" name="note" rows="2" <?= $canEdit?'':'disabled' ?>><?=h($po['note'])?></textarea>
          </div>
          <div class="col-12">
            <?php if ($canEdit): ?>
              <button class="btn btn-primary btn-sm">Save Header</button>
            <?php else: ?>
              <div class="muted">Read-only</div>
            <?php endif; ?>
          </div>
        </form>

        <hr>
        <div class="fw-semibold">Total</div>
        <div class="display-6"><?=h($canPrice ? p_money($po['total_amount'], $po['currency']) : '0')?></div>
        <div class="muted">Buy price visible: <?= $canPrice ? 'YES' : 'NO' ?></div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">Items</div>

        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="save_items" value="1">
          <div class="table-responsive">
            <table class="table table-sm table-dark align-middle">
              <thead>
                <tr>
                  <th>#</th><th>SKU</th><th>Product</th>
                  <th class="num">Qty</th><th>Unit</th>
                  <th class="num">Unit Price</th><th class="num">Subtotal</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($items as $it): ?>
                  <tr>
                    <td><?=h($it['line_no'])?><input type="hidden" name="item_id[]" value="<?=h($it['id'])?>"></td>
                    <td><b><?=h(rmi_sku($it['sku']))?></b></td>
                    <td><?=h(rmi_product_name($it['products_name']))?></td>
                    <td class="num"><input class="form-control form-control-sm text-end" type="number" step="0.01" name="qty[]" value="<?=h($it['qty'])?>" <?= $canEdit?'':'disabled' ?>></td>
                    <td><input class="form-control form-control-sm" name="unit[]" value="<?=h(strtoupper($it['unit'] ?? ''))?>" <?= $canEdit?'':'disabled' ?>></td>
                    <?php if ($canPrice): ?>
                      <td class="num"><input class="form-control form-control-sm text-end" type="number" step="0.01" name="unit_price[]" value="<?=h($it['unit_price'])?>" <?= $canEdit?'':'disabled' ?>></td>
                      <td class="num"><?=h(p_money($it['subtotal'], $po['currency']))?></td>
                    <?php else: ?>
                      <td class="muted">Restricted<input type="hidden" name="unit_price[]" value="0"></td>
                      <td class="num">0</td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?><tr><td colspan="7" class="muted">Tidak ada item.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($canEdit): ?>
            <button class="btn btn-primary btn-sm">Save Items</button>
          <?php endif; ?>
        </form>

      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
