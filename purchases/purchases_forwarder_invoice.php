<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.AP_INVOICE_VIEW', 'PURCHASES.AP_INVOICE_CREATE', 'PURCHASES.AP_INVOICE_EDIT', 'PURCHASES.FORWARDING_VIEW', 'PURCHASES.FORWARDING_CREATE', 'PURCHASES.FORWARDING_EDIT']);
} else {
    require_role(['FIN','ADMIN','SUPERADMIN','SYS','MANAGER','PQP','SCM','WQS','BRANCH','STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
require_once __DIR__ . '/../master/_audit_master.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

if (!function_exists('require_any_permission') && !p_can_fin_ops() && !p_is_admin_plus()) { http_response_code(403); echo "Access denied."; exit; }

$flash = p_flash_get();

function fap_upload_dir($fap_code){
  $dir = __DIR__ . '/uploads/forwarder_invoice/' . $fap_code;
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}

$po_id_prefill = (int)($_GET['po_id'] ?? 0);

// Vendors (hanya Forwarding & Logistic/Expedisi — SCM)
$vendors=[];
try {
  $vendors = $pdo->query("
    SELECT id, vendors_code, vendors_name FROM master_vendors
    WHERE status='active'
    AND (vendor_type = 'Forwarding' OR vendor_type = 'Forwarder'
         OR vendor_type IN ('Logistic/Expedisi','Logistics','Logistic','Ekspedisi','Expedisi'))
    ORDER BY vendors_name
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// PO list
$pos=[];
try {
  $pos = $pdo->query("SELECT id, po_code, office_code, status FROM purchases_po WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Offices
$offices=[];
try { $offices=$pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

function go(){ rmi_redirect("purchases_forwarder_invoice.php"); }

// Create invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
}

if (isset($_POST['create_fap'])) {
  try {
    $invoice_type = up($_POST['invoice_type'] ?? 'FORWARDER');
    if (!in_array($invoice_type,['FORWARDER','WAREHOUSE_DELIVERY','OTHER'],true)) $invoice_type='FORWARDER';

    $po_id = (int)($_POST['po_id'] ?? 0);
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $invoice_number = trim((string)($_POST['invoice_number'] ?? ''));
    $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
    $due_date = $_POST['due_date'] ?? null;
    $currency = up($_POST['currency'] ?? 'IDR');
    $subtotal = (float)($_POST['subtotal'] ?? 0);
    $tax_percent = (float)($_POST['tax_percent'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));

    if ($vendor_id<=0) throw new Exception("Vendor wajib.");
    if ($subtotal<=0) throw new Exception("Subtotal wajib > 0.");
    if ($currency==='') $currency='IDR';

    // office_code source
    $office_code = up($_POST['office_code'] ?? '');
    if ($po_id>0) {
      $st=$pdo->prepare("SELECT office_code FROM purchases_po WHERE id=?");
      $st->execute([$po_id]);
      $office_code = up($st->fetchColumn() ?: $office_code);
    }
    if ($office_code==='') $office_code='OFF';

    $tax_amount = $subtotal * ($tax_percent/100.0);
    $total = $subtotal + $tax_amount;

    $dateYmd = date('ymd', strtotime($invoice_date));
    if (!function_exists('doc_prefix_fap')) require_once __DIR__ . '/../config/doc_numbering.php';
    $prefix = doc_prefix_fap($office_code, $dateYmd);
    $fap_code = p_generate_code($pdo,'purchases_forwarder_invoice','fap_code',$prefix);

    // upload doc (optional)
    $doc_path = null;
    if (!empty($_FILES['doc']['tmp_name'])) {
      $dir = fap_upload_dir($fap_code);
      $origName = (string)($_FILES['doc']['name'] ?? '');
      $safeName = rmi_safe_filename($origName);
      if ($safeName === '') throw new Exception('Nama dokumen tidak valid.');
      $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
      if (!in_array($ext,['pdf','jpg','jpeg','png'],true)) throw new Exception("Dokumen hanya PDF/JPG/PNG.");
      $target = $dir.'/invoice.'.$ext;
      if (move_uploaded_file($_FILES['doc']['tmp_name'], $target)) {
        $doc_path = 'purchases/uploads/forwarder_invoice/'.$fap_code.'/invoice.'.$ext;
      }
    }

    $pdo->prepare("INSERT INTO purchases_forwarder_invoice
      (fap_code, invoice_type, invoice_number, invoice_date, due_date, vendor_id, office_code, po_id, currency,
       subtotal, tax_percent, tax_amount, total_amount, status, note, doc_path, created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$fap_code,$invoice_type,($invoice_number!==''?$invoice_number:null),$invoice_date,$due_date,$vendor_id,$office_code,($po_id>0?$po_id:null),$currency,
                $subtotal,$tax_percent,$tax_amount,$total,'UNPAID',$note,$doc_path,p_username()]);
    $fap_id = (int)$pdo->lastInsertId();
    p_audit($pdo,'FAP',$fap_code,'CREATE',['vendor_id'=>$vendor_id,'po_id'=>$po_id,'total'=>$total,'currency'=>$currency]);
    if (function_exists('master_audit')) {
      master_audit($pdo, 'purchases_forwarder_invoice', 'purchases_forwarder_invoice', 'CREATE', $fap_id, $fap_code, "Forwarder invoice created: {$fap_code}", ['vendor_id' => $vendor_id, 'po_id' => $po_id, 'total' => $total]);
    }
    p_flash_set('success',"Forwarder invoice dibuat: {$fap_code}");
  } catch (Throwable $e) {
    p_flash_set('danger',$e->getMessage());
  }
  go();
}

// List invoices
$where = "f.deleted_at IS NULL";
$params = [];
if ($po_id_prefill>0) { $where .= " AND f.po_id=?"; $params[] = $po_id_prefill; }

$rows=[];
try {
  $st=$pdo->prepare("
    SELECT f.*, v.vendors_name, po.po_code,
      COALESCE(paid.paid_amount,0) AS paid_amount,
      (f.total_amount - COALESCE(paid.paid_amount,0)) AS outstanding
    FROM purchases_forwarder_invoice f
    LEFT JOIN master_vendors v ON v.id=f.vendor_id
    LEFT JOIN purchases_po po ON po.id=f.po_id
    LEFT JOIN (
      SELECT fap_id, SUM(amount) paid_amount
      FROM purchases_forwarder_payment
      WHERE deleted_at IS NULL
      GROUP BY fap_id
    ) paid ON paid.fap_id = f.id
    WHERE {$where}
    ORDER BY f.id DESC
  ");
  $st->execute($params);
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'purchases_forwarder_invoice' ORDER BY created_at DESC LIMIT 50");
  $st->execute();
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $audit_rows = []; }
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Forwarder Invoice', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Forwarder Invoice',
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
    <div class="muted">Purchases • FIN</div>
    <h3 class="mb-0">Forwarder Invoice</h3>
    <div class="muted">Catat tagihan forwarder (freight/handling/dll) per PO</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-soft btn-sm" href="purchases_import_control_tower.php">← Control Tower</a>
    <a class="btn btn-soft btn-sm" href="purchases_forwarder_payment.php">Forwarder Payment</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-2">Create Forwarder Invoice</div>
    <form method="post" enctype="multipart/form-data" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="col-md-3">
        <label class="form-label muted">Type</label>
        <select class="form-select form-select-sm" name="invoice_type">
          <option>FORWARDER</option>
          <option>WAREHOUSE_DELIVERY</option>
          <option>OTHER</option>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label muted">Vendor</label>
        <select class="form-select form-select-sm" name="vendor_id" required>
          <option value="">-- pilih vendor --</option>
          <?php foreach($vendors as $v): ?>
            <option value="<?=h($v['id'])?>"><?=h($v['vendors_name'].' ('.$v['vendors_code'].')')?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label muted">PO (optional)</label>
        <select class="form-select form-select-sm" name="po_id">
          <option value="">-- none --</option>
          <?php foreach($pos as $p): ?>
            <option value="<?=h($p['id'])?>" <?= ($po_id_prefill===(int)$p['id'])?'selected':'' ?>>
              <?=h($p['po_code'].' • '.$p['office_code'].' • '.$p['status'])?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label muted">Invoice No</label>
        <input class="form-control form-control-sm" name="invoice_number">
      </div>
      <div class="col-md-3">
        <label class="form-label muted">Invoice Date</label>
        <input class="form-control form-control-sm" type="date" name="invoice_date" value="<?=h(date('Y-m-d'))?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label muted">Due Date</label>
        <input class="form-control form-control-sm" type="date" name="due_date">
      </div>
      <div class="col-md-3">
        <label class="form-label muted">Office (if PO empty)</label>
        <select class="form-select form-select-sm" name="office_code">
          <option value="">-- auto --</option>
          <?php foreach($offices as $o): ?>
            <option value="<?=h($o['office_code'])?>"><?=h($o['office_name'].' ('.$o['office_code'].')')?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label muted">Currency</label>
        <select class="form-select form-select-sm" name="currency">
          <option>IDR</option><option>USD</option><option>CNY</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label muted">Subtotal</label>
        <input class="form-control form-control-sm text-end" type="number" step="0.01" name="subtotal" required>
      </div>
      <div class="col-md-2">
        <label class="form-label muted">Tax %</label>
        <input class="form-control form-control-sm text-end" type="number" step="0.01" name="tax_percent" value="0">
      </div>
      <div class="col-md-4">
        <label class="form-label muted">Doc (PDF/JPG/PNG)</label>
        <input class="form-control form-control-sm" type="file" name="doc" accept=".pdf,.jpg,.jpeg,.png">
      </div>

      <div class="col-12">
        <label class="form-label muted">Note</label>
        <textarea class="form-control form-control-sm" name="note" rows="2"></textarea>
      </div>
      <div class="col-12">
        <button class="btn btn-primary btn-sm" name="create_fap">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">Invoice List</div>
    <div class="table-responsive">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <th>FAP Code</th>
            <th>Type</th>
            <th>Vendor</th>
            <th>PO</th>
            <th>Invoice Date</th>
            <th class="num">Total</th>
            <th class="num">Paid</th>
            <th class="num">OS</th>
            <th>Status</th>
            <th>Doc</th>
            <th>Pay</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><b><?=h($r['fap_code'])?></b></td>
              <td><?=h($r['invoice_type'])?></td>
              <td><?=h($r['vendors_name'] ?? '')?></td>
              <td><?=h($r['po_code'] ?? '-')?></td>
              <td><?=h($r['invoice_date'] ?? '')?></td>
              <td class="num"><?=h(p_money($r['total_amount'], $r['currency']))?></td>
              <td class="num"><?=h(p_money($r['paid_amount'], $r['currency']))?></td>
              <td class="num"><?=h(p_money($r['outstanding'], $r['currency']))?></td>
              <td><?=h($r['status'])?></td>
              <td>
                <?php if (!empty($r['doc_path'])): ?>
                  <a class="btn btn-soft btn-sm" href="../<?=h($r['doc_path'])?>" target="_blank">Open</a>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
              <td>
                <a class="btn btn-success btn-sm" href="purchases_forwarder_payment.php?fap_id=<?=h($r['id'])?>">Pay</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-2">Audit Log <span class="muted">(Last 50 events)</span></div>
    <?php if (empty($audit_rows)): ?>
      <div class="muted">Belum ada audit log.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-dark align-middle">
          <thead><tr><th style="width:180px">Time</th><th style="width:120px">Action</th><th style="width:160px">Code</th><th style="width:120px">User</th><th>Description</th></tr></thead>
          <tbody>
          <?php foreach ($audit_rows as $a): ?>
            <tr><td><?= h($a['created_at'] ?? '') ?></td><td><?= h($a['action'] ?? '') ?></td><td><?= h($a['record_code'] ?? '') ?></td><td><?= h($a['username'] ?? '') ?></td><td><?= h($a['description'] ?? '') ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
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
