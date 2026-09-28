<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.AP_PAYMENT_CRUD', 'PURCHASES.FORWARDING_CRUD', 'PURCHASES.VIEW']);
} else {
    require_role(['FIN','ADMIN','SUPERADMIN','SYS','MANAGER']);
}

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

if (!function_exists('require_any_permission') && !p_can_fin_ops() && !p_is_admin_plus()) { http_response_code(403); echo "Access denied."; exit; }

$flash = p_flash_get();

function fpay_upload_dir($pay_code){
  $dir = __DIR__ . '/uploads/forwarder_payment/' . $pay_code;
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}

$fap_id_prefill=(int)($_GET['fap_id'] ?? 0);

// Unpaid/partial invoices
$invoices=[];
try {
  $invoices = $pdo->query("
    SELECT f.id, f.fap_code, f.currency, f.total_amount, f.invoice_type, f.office_code,
      v.vendors_name,
      COALESCE(paid.paid_amount,0) AS paid_amount,
      (f.total_amount - COALESCE(paid.paid_amount,0)) AS outstanding
    FROM purchases_forwarder_invoice f
    LEFT JOIN master_vendors v ON v.id=f.vendor_id
    LEFT JOIN (
      SELECT fap_id, SUM(amount) paid_amount
      FROM purchases_forwarder_payment
      WHERE deleted_at IS NULL
      GROUP BY fap_id
    ) paid ON paid.fap_id = f.id
    WHERE f.deleted_at IS NULL AND f.status IN ('UNPAID','PARTIAL')
    ORDER BY f.id DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Create payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
}

if (isset($_POST['create_pay'])) {
  $fap_id=(int)($_POST['fap_id'] ?? 0);
  $pay_date=$_POST['pay_date'] ?? date('Y-m-d');
  $amount=(float)($_POST['amount'] ?? 0);
  $method=trim((string)($_POST['method'] ?? 'TRANSFER'));
  $bank_name=trim((string)($_POST['bank_name'] ?? ''));
  $reference=trim((string)($_POST['reference'] ?? ''));
  $note=trim((string)($_POST['note'] ?? ''));

  if ($fap_id<=0 || $amount<=0) { p_flash_set('danger','Invoice & amount wajib.'); header("Location: purchases_forwarder_payment.php"); exit; }

  // Office from invoice
  $st=$pdo->prepare("SELECT office_code, fap_code FROM purchases_forwarder_invoice WHERE id=?");
  $st->execute([$fap_id]);
  $row=$st->fetch(PDO::FETCH_ASSOC);
  $office=up($row['office_code'] ?? 'OFF');

  $dateYmd=date('ymd', strtotime($pay_date));
  $prefix="RMI-FPAY-{$office}-{$dateYmd}-";
  $pay_code=p_generate_code($pdo,'purchases_forwarder_payment','pay_code',$prefix);

  $doc_path=null;
  if (!empty($_FILES['doc']['tmp_name'])) {
    $dir=fpay_upload_dir($pay_code);
    $origName = (string)($_FILES['doc']['name'] ?? '');
    $safeName = rmi_safe_filename($origName);
    if ($safeName === '') { p_flash_set('danger','Nama dokumen tidak valid.'); header("Location: purchases_forwarder_payment.php"); exit; }
    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    if (!in_array($ext,['pdf','jpg','jpeg','png'],true)) { p_flash_set('danger','Dokumen hanya PDF/JPG/PNG.'); header("Location: purchases_forwarder_payment.php"); exit; }
    $target=$dir.'/proof.'.$ext;
    if (move_uploaded_file($_FILES['doc']['tmp_name'], $target)) $doc_path='purchases/uploads/forwarder_payment/'.$pay_code.'/proof.'.$ext;
  }

  try {
    $pdo->prepare("INSERT INTO purchases_forwarder_payment (pay_code, fap_id, pay_date, amount, method, bank_name, reference, note, doc_path, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$pay_code,$fap_id,$pay_date,$amount,$method,$bank_name,$reference,$note,$doc_path,p_username()]);
    p_recalc_fap_status($pdo,$fap_id);
    p_audit($pdo,'FAP_PAY',$pay_code,'CREATE',['fap_id'=>$fap_id,'amount'=>$amount]);
    p_flash_set('success',"Payment dibuat: {$pay_code}");
  } catch (Throwable $e) {
    p_flash_set('danger','Gagal: '.$e->getMessage());
  }
  header("Location: purchases_forwarder_payment.php"); exit;
}

// List payments
$rows=[];
try {
  $rows = $pdo->query("
    SELECT pay.*, f.fap_code, f.currency, f.invoice_type, v.vendors_name
    FROM purchases_forwarder_payment pay
    LEFT JOIN purchases_forwarder_invoice f ON f.id = pay.fap_id
    LEFT JOIN master_vendors v ON v.id = f.vendor_id
    WHERE pay.deleted_at IS NULL
    ORDER BY pay.id DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Forwarder Payment', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Forwarder Payment',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{background:radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
    .muted{color:#9ca3af;font-size:12px}
    .form-control,.form-select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.14)!important}
    .btn-soft{border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e5e7eb}
    .btn-soft:hover{background:rgba(255,255,255,.10);color:#fff}
    table.dataTable thead th,table.dataTable tbody td{color:#e5e7eb}
    .dt-buttons .btn,.dataTables_wrapper .dt-buttons button{border-radius:10px!important;border:1px solid rgba(255,255,255,.15)!important;background:rgba(255,255,255,.08)!important;color:#e5e7eb!important;padding:6px 10px!important;font-size:12px!important;}
    .dataTables_wrapper .dataTables_filter input,.dataTables_wrapper .dataTables_length select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.12)!important;border-radius:10px!important;}
    .num{text-align:right}</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted">Purchases</div>
    <h3 class="mb-0">Forwarder Payment</h3>
    <div class="muted">Pembayaran invoice forwarder</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-soft btn-sm" href="purchases_import_control_tower.php">← Control Tower</a>
    <a class="btn btn-soft btn-sm" href="purchases_forwarder_invoice.php">Forwarder Invoice</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-2">Create Payment</div>
    <form method="post" enctype="multipart/form-data" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="col-md-7">
        <label class="form-label">Invoice (UNPAID/PARTIAL)</label>
        <select class="form-select form-select-sm" name="fap_id" required>
          <option value="">-- pilih invoice --</option>
          <?php foreach($invoices as $inv): ?>
            <option value="<?=h($inv['id'])?>" <?= ($fap_id_prefill===(int)$inv['id'])?'selected':'' ?>>
              <?=h($inv['fap_code'].' | '.$inv['invoice_type'].' | '.$inv['vendors_name'].' | outstanding '.p_money($inv['outstanding'],$inv['currency']).' '.$inv['currency'])?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Pay Date</label>
        <input class="form-control form-control-sm" type="date" name="pay_date" value="<?=h(date('Y-m-d'))?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Amount</label>
        <input class="form-control form-control-sm text-end" type="number" step="0.01" name="amount" required>
      </div>

      <div class="col-md-3">
        <label class="form-label">Method</label>
        <select class="form-select form-select-sm" name="method">
          <option>TRANSFER</option><option>CASH</option><option>GIRO</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Bank (optional)</label>
        <input class="form-control form-control-sm" name="bank_name">
      </div>
      <div class="col-md-5">
        <label class="form-label">Reference (optional)</label>
        <input class="form-control form-control-sm" name="reference">
      </div>

      <div class="col-md-6">
        <label class="form-label">Dokumen (PDF/JPG/PNG)</label>
        <input class="form-control form-control-sm" type="file" name="doc" accept=".pdf,.jpg,.jpeg,.png">
      </div>
      <div class="col-md-6">
        <label class="form-label">Note</label>
        <input class="form-control form-control-sm" name="note">
      </div>

      <div class="col-12">
        <button class="btn btn-primary btn-sm" name="create_pay">Save Payment</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">Payment List</div>
    <div class="table-responsive">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <th>Pay Code</th>
            <th>FAP Code</th>
            <th>Type</th>
            <th>Vendor</th>
            <th>Pay Date</th>
            <th class="num">Amount</th>
            <th>Method</th>
            <th>Reference</th>
            <th>Doc</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><b><?=h($r['pay_code'])?></b></td>
              <td><?=h($r['fap_code'] ?? '')?></td>
              <td><?=h($r['invoice_type'] ?? '')?></td>
              <td><?=h($r['vendors_name'] ?? '')?></td>
              <td><?=h($r['pay_date'])?></td>
              <td class="num"><?=h(p_money($r['amount'], $r['currency'] ?? 'IDR'))?></td>
              <td><?=h($r['method'] ?? '')?></td>
              <td><?=h($r['reference'] ?? '')?></td>
              <td>
                <?php if (!empty($r['doc_path'])): ?>
                  <a class="btn btn-soft btn-sm" href="../<?=h($r['doc_path'])?>" target="_blank">Open</a>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script>
$(function(){
  $('#tbl').DataTable({pageLength:25, order:[[0,'desc']], dom:'Bfrtip', buttons:['copy','csv','excel','print']});
});
</script>
<?php rmi_footer(); ?>
