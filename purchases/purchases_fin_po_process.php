<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/_purchases_lib.php';

$user = function_exists('auth_user') ? (array)auth_user() : [];

$deptCandidates = [
    function_exists('auth_dept') ? (string)auth_dept() : '',
    (string)($user['department'] ?? ''),
    (string)($user['dept'] ?? ''),
    (string)($_SESSION['department'] ?? ''),
    (string)($_SESSION['dept'] ?? ''),
];
$dept = '';
foreach ($deptCandidates as $d) {
    $d = strtoupper(trim($d));
    if ($d !== '') { $dept = $d; break; }
}
$role = strtoupper(trim((string)($user['role'] ?? ($_SESSION['role'] ?? ''))));
$level = strtoupper(trim((string)($user['level'] ?? ($_SESSION['level'] ?? ''))));
$username = strtoupper(trim((string)($user['username'] ?? ($_SESSION['username'] ?? ($_SESSION['user_name'] ?? '')))));

$isSys = in_array($dept,['SYS','SYSTEM','IT'],true)
      || in_array($role,['SYS','SYSTEM','ADMIN','SUPERADMIN'],true)
      || in_array($level,['SYS','SYSTEM','ADMIN','SUPERADMIN'],true)
      || strpos($username,'SYS') !== false;

$isPqp = !$isSys && (($dept === 'PQP') || strpos($username,'PQP') !== false);

$isFin = !$isSys && !$isPqp && (
    in_array($dept,['FIN','FINANCE'],true)
    || strpos($username,'FIN') !== false
    || strpos($role,'FIN') !== false
);

// Shared process page:
// FIN = AP/Payment execution
// PQP = view full flow for final check
// SYS = full visibility/support
if (!$isFin && !$isPqp && !$isSys) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = p_pdo();
p_ensure_schema($pdo);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'PO tidak valid.'; exit; }

$po=null; $items=[]; $incoming=[]; $aps=[];
try {
  $st=$pdo->prepare("SELECT po.*,o.office_name,m.manufacture_name,pr.pr_code
    FROM purchases_po po
    LEFT JOIN master_office o ON o.office_code=po.office_code
    LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
    LEFT JOIN wqs_pr pr ON pr.id=po.pr_id
    WHERE po.id=? AND po.deleted_at IS NULL LIMIT 1");
  $st->execute([$id]); $po=$st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch(Throwable $e) {}
if (!$po) { http_response_code(404); echo 'PO tidak ditemukan.'; exit; }
if (function_exists('p_po_is_import') && p_po_is_import($po)) {
  http_response_code(409); echo 'PO IMPORT diproses melalui Import Control Tower.'; exit;
}

try { $st=$pdo->prepare("SELECT * FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL ORDER BY line_no,id"); $st->execute([$id]); $items=$st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch(Throwable $e) {}
try {
  $cols=p_cols($pdo,'wqs_incoming');
  $del=in_array('deleted_at',$cols,true)?' AND deleted_at IS NULL':'';
  $st=$pdo->prepare("SELECT * FROM wqs_incoming WHERE po_code=?{$del} ORDER BY id DESC LIMIT 20");
  $st->execute([(string)$po['po_code']]); $incoming=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e) {}
try {
  $st=$pdo->prepare("SELECT ap.*, COALESCE((SELECT SUM(p.amount) FROM purchases_payment_ap p WHERE p.ap_id=ap.id AND p.deleted_at IS NULL),0) paid_amount
    FROM purchases_invoice_ap ap WHERE ap.po_id=? AND ap.deleted_at IS NULL ORDER BY ap.id DESC");
  $st->execute([$id]); $aps=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e) {}

$receivingOk = count($incoming) > 0;
$apExists = count($aps) > 0;
$apTotal = 0.0; $apPaid = 0.0;
foreach($aps as $a){ $apTotal += (float)($a['total_amount']??0); $apPaid += (float)($a['paid_amount']??0); }
$payOk = $apExists && ($apTotal <= 0.00001 ? $apPaid > 0.00001 : $apPaid + 0.00001 >= $apTotal);
$finDone = $receivingOk && $apExists && $payOk;

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject=rmi_layout_base_project();
rmi_header('Local Purchase Process', [
 'active'=>'purchases',
 'breadcrumbs'=>[
   ['label'=>'Finance Dashboard','url'=>$baseProject.'/dashboards/finance/ar_ap_cash_dashboard.php'],
   ['label'=>'Local Purchase Control Tower','url'=>'purchases_control_tower.php'],
   'FIN Process'
 ],
 'actions'=>array_values(array_filter([
   ['label'=>'← Local Tower','url'=>'purchases_control_tower.php','class'=>'btn btn-sm btn-outline-light'],
   ($isFin || $isSys) ? ['label'=>'AP Invoice','url'=>'purchases_invoice_ap.php?po_id='.$id,'class'=>'btn btn-sm btn-outline-info'] : null,
   ($isFin || $isSys) ? ['label'=>'AP Payment','url'=>'purchases_payment_ap.php?po_id='.$id,'class'=>'btn btn-sm btn-outline-warning'] : null,
   ($isSys || $isPqp) ? ['label'=>'PO Detail / Edit','url'=>'purchases_po_view.php?id='.$id,'class'=>'btn btn-sm btn-outline-light'] : null,
 ])),
 'extra_head'=>'<style>
 body{background:#0b1220;color:#e5e7eb}.card{background:rgba(17,24,39,.86);border:1px solid rgba(255,255,255,.08);border-radius:16px}.muted{color:#94a3b8;font-size:12px}.step{padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.09);background:rgba(255,255,255,.04)}.ok{border-color:rgba(16,185,129,.45)}.wait{border-color:rgba(245,158,11,.45)}.lock{opacity:.65}.num{text-align:right}.pill{display:inline-block;padding:3px 9px;border-radius:999px;border:1px solid rgba(255,255,255,.14);font-size:12px}
 </style>'
]);
?>
<div class="container py-3" style="max-width:1200px">
  <div class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-3">
      <div>
        <div class="muted">LOCAL PURCHASE PROCESS</div>
        <h3 class="mb-1"><?=h($po['po_code'] ?? '')?></h3>
        <span class="pill">LOCAL</span> <span class="pill"><?=h(strtoupper((string)($po['status'] ?? '')))?></span>
        <div class="muted mt-2">PR <?=h($po['pr_code'] ?? '-')?> · <?=h($po['manufacture_name'] ?? '-')?> · <?=h($po['office_name'] ?? $po['office_code'] ?? '-')?></div>
      </div>
      <div class="text-end"><div class="muted">PO Total</div><div style="font-size:24px;font-weight:800"><?=h(p_money($po['total_amount']??0,$po['currency']??'IDR'))?></div></div>
    </div>
  </div></div>

  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="step <?=$receivingOk?'ok':'wait'?>">
      <div class="muted">STEP 1 · WQS</div><h5>Receiving</h5>
      <b><?=$receivingOk?rmi_icon('tick').' Barang sudah diterima':'Menunggu receiving'?></b>
      <div class="muted mt-1">FIN mulai AP setelah receiving tersedia.</div>
    </div></div>
    <div class="col-md-4"><div class="step <?=$apExists?'ok':($receivingOk?'wait':'lock')?>">
      <div class="muted">STEP 2 · FIN</div><h5>AP Invoice</h5>
      <b><?=$apExists?rmi_icon('tick').' AP tercatat':($receivingOk?'AP belum dibuat':'Terkunci — tunggu receiving')?></b><br>
      <?php if(($isFin || $isSys) && $receivingOk && !$apExists): ?><a class="btn btn-info btn-sm mt-2" href="purchases_invoice_ap.php?po_id=<?=$id?>">Buat / Registrasi AP</a><?php endif; ?>
    </div></div>
    <div class="col-md-4"><div class="step <?=$payOk?'ok':($apExists?'wait':'lock')?>">
      <div class="muted">STEP 3 · FIN</div><h5>AP Payment</h5>
      <b><?=$payOk?rmi_icon('tick').' Payment lunas':($apExists?'Menunggu payment':'Terkunci — AP belum ada')?></b><br>
      <?php if(($isFin || $isSys) && $apExists && !$payOk): ?><a class="btn btn-warning btn-sm mt-2" href="purchases_payment_ap.php?po_id=<?=$id?>">Proses Payment</a><?php endif; ?>
    </div></div>
  </div>

  <?php if($finDone): ?>
  <div class="alert alert-success"><b>AP & PAYMENT DONE.</b> Receiving, AP Invoice, dan Payment sudah selesai. Selanjutnya <b>PQP/SYS melakukan final check dan Close PO dari Local Purchase Control Tower</b>.</div>
  <?php elseif($isFin): ?>
  <div class="alert alert-secondary"><b>Tugas FIN:</b> AP Invoice dan AP Payment. Setelah lunas, handoff kembali ke PQP untuk closing.</div>
  <?php elseif($isPqp): ?>
  <div class="alert alert-secondary"><b>Tugas PQP:</b> monitor Receiving dan status FIN. Setelah AP + Payment selesai, kembali ke Local Purchase Control Tower untuk final Close PO.</div>
  <?php elseif($isSys): ?>
  <div class="alert alert-info"><b>SYS full access:</b> dapat melihat seluruh alur, membuka AP/Payment, PO detail, dan melakukan Close PO dari Local Purchase Control Tower.</div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-body">
    <h5>PO Items — Read Only</h5>
    <div class="table-responsive"><table class="table table-dark table-sm align-middle">
      <thead><tr><th>SKU</th><th>Product</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit Price</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach($items as $it): ?>
      <tr><td><?=h($it['sku']??'')?></td><td><?=h($it['products_name']??'')?></td><td class="num"><?=h($it['qty']??0)?></td><td><?=h($it['unit']??'')?></td><td class="num"><?=h(p_money($it['unit_price']??0,$po['currency']??'IDR'))?></td><td class="num"><?=h(p_money($it['total_after_tax']??$it['subtotal']??0,$po['currency']??'IDR'))?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div></div>

  <div class="card"><div class="card-body">
    <h5>AP / Payment Summary</h5>
    <?php if(!$aps): ?><div class="muted">Belum ada AP untuk PO ini.</div><?php endif; ?>
    <?php foreach($aps as $a): ?>
      <div style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.08)">
        <b><?=h($a['ap_code']??'')?></b> · <?=h(strtoupper((string)($a['status']??'')))?> · Total <?=h(p_money($a['total_amount']??0,$a['currency']??'IDR'))?> · Paid <?=h(p_money($a['paid_amount']??0,$a['currency']??'IDR'))?>
      </div>
    <?php endforeach; ?>
  </div></div>
</div>
<?php rmi_footer(); ?>
