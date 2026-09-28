<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_login();
if (function_exists('require_any_permission')) {
    // Tanpa PURCHASES.VIEW lebar — sesuai registry ACCESS=AP_INVOICE_EDIT (route edit).
    $allowed = function_exists('can_any') && can_any(['PURCHASES.AP_INVOICE_VIEW', 'PURCHASES.AP_INVOICE_CREATE', 'PURCHASES.AP_INVOICE_EDIT', 'PURCHASES.AP_PAYMENT_VIEW', 'PURCHASES.AP_PAYMENT_CREATE', 'PURCHASES.AP_PAYMENT_EDIT']);
    if (!$allowed && function_exists('require_role')) {
        require_role(['FIN','ADMIN','SUPERADMIN','SYS','PQP','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
    } elseif (!$allowed) {
        http_response_code(403); echo 'Forbidden'; exit;
    }
} else {
    require_role(['FIN','ADMIN','SUPERADMIN','SYS','PQP','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

// Izin route sudah di page_registry + blok can_any di atas; jangan gate lagi dengan p_can_fin_ops (lebih sempit dari route_any).

$flash = p_flash_get();

function p_dir_invoice_doc_local(string $ap_code): string {
  $dir = __DIR__ . '/uploads/invoice/' . $ap_code;
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { http_response_code(400); echo "Missing id"; exit; }

$st = $pdo->prepare("SELECT ap.*, m.manufacture_name
  FROM purchases_invoice_ap ap
  LEFT JOIN master_manufactures m ON m.id = ap.manufacture_id
  WHERE ap.id=? AND ap.deleted_at IS NULL
  LIMIT 1");
$st->execute([$id]);
$ap = $st->fetch(PDO::FETCH_ASSOC);
if (!$ap) { http_response_code(404); echo "Invoice not found"; exit; }

// payment sum
$stPaid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM purchases_payment_ap WHERE ap_id=? AND deleted_at IS NULL");
$stPaid->execute([$id]);
$paid_amount = (float)$stPaid->fetchColumn();

// master data (follow existing ERP table names)
$manufactures = []; $offices = []; $po_list = [];
try {
  $manufactures = $pdo->query("SELECT id, manufacture_code, manufacture_name FROM master_manufactures WHERE status=1 OR status='active' ORDER BY manufacture_name")->fetchAll();
} catch (Throwable $e) { $manufactures = []; }
try {
  $offices = $pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name")->fetchAll();
} catch (Throwable $e) { $offices = []; }
try {
  $po_list = $pdo->query("SELECT id, po_code, manufacture_id, office_code, currency, total_amount FROM purchases_po WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 500")->fetchAll();
} catch (Throwable $e) { $po_list = []; }
// derive percent (for display only)
$po_total = 0.0;
if (!empty($ap['po_id'])) {
  $stPo = $pdo->prepare("SELECT total_amount FROM purchases_po WHERE id=?");
  $stPo->execute([(int)$ap['po_id']]);
  $po_total = (float)($stPo->fetchColumn() ?: 0);
}
$percent_guess = ($po_total>0) ? round(((float)$ap['subtotal'] / $po_total)*100, 2) : 0;


$percent_default = $percent_guess;
if ($percent_default<=0) {
  if (($ap['invoice_type'] ?? '')==='PROFORMA') $percent_default = 30;
  else if (($ap['invoice_type'] ?? '')==='FINAL') $percent_default = 70;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
}

if (isset($_POST['update_ap'])) {
  if ($paid_amount>0) {
    p_flash_set('danger','Invoice sudah ada pembayaran, tidak bisa di-edit amount/PO. (Untuk koreksi: buat adjustment / reversal).');
    rmi_redirect("purchases_invoice_ap.php");
  }

  $invoice_type = up($_POST['invoice_type'] ?? ($ap['invoice_type'] ?? 'PROFORMA'));
  if (!in_array($invoice_type,['PROFORMA','FINAL','PIB','OTHER'],true)) $invoice_type='PROFORMA';

  $invoice_number = trim((string)($_POST['invoice_number'] ?? ''));
  $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
  $due_date = trim((string)($_POST['due_date'] ?? ''));
  if ($due_date === '') $due_date = null;
$manufacture_id = (int)($_POST['manufacture_id'] ?? 0);
  $office_code = up($_POST['office_code'] ?? '');
  $po_id = (int)($_POST['po_id'] ?? 0);
  $currency = up($_POST['currency'] ?? 'IDR');
  $subtotal = (float)($_POST['subtotal'] ?? 0);
  $percent_of_po = (float)($_POST['percent_of_po'] ?? 0);
  $tax_percent = (float)($_POST['tax_percent'] ?? 0);
  $note = trim((string)($_POST['note'] ?? ''));

  // DP/FINAL requires PO
  if (in_array($invoice_type,['PROFORMA','FINAL'],true) && $po_id<=0) {
    p_flash_set('danger','Untuk PROFORMA/FINAL wajib pilih PO.');
    rmi_redirect('purchases_invoice_ap_edit.php?id=' . $id);
  }

  // Default % if blank
  if ($po_id>0 && $percent_of_po<=0) {
    if ($invoice_type==='PROFORMA') $percent_of_po = 30;
    else if ($invoice_type==='FINAL') $percent_of_po = 70;
  }

  // If PO linked, derive manufacture/office/currency and compute subtotal from % (if set)
  if ($po_id > 0) {
    foreach ($po_list as $po) {
      if ((int)$po['id'] === $po_id) {
        if ($manufacture_id<=0) $manufacture_id = (int)$po['manufacture_id'];
        if ($office_code==='') $office_code = up($po['office_code']);
        $currency = up($po['currency'] ?? $currency);
        if ($subtotal <= 0 && $percent_of_po<=0) $subtotal = (float)$po['total_amount'];
      }
    }
    if ($percent_of_po>0) {
      if ($percent_of_po>100) $percent_of_po=100;
      $stPo = $pdo->prepare("SELECT total_amount FROM purchases_po WHERE id=?");
      $stPo->execute([$po_id]);
      $po_total2 = (float)($stPo->fetchColumn() ?: 0);
      if ($po_total2>0) $subtotal = $po_total2 * ($percent_of_po/100);
    }
  }

  if ($manufacture_id<=0 || $office_code==='') {
    p_flash_set('danger','Manufacture & Office wajib.');
    rmi_redirect('purchases_invoice_ap_edit.php?id=' . $id);
  }

  $tax_amount = $subtotal * ($tax_percent/100);
  $total_amount = $subtotal + $tax_amount;
  if ($total_amount <= 0) {
    p_flash_set('danger','Nilai invoice harus > 0. Pastikan PO punya total (harga buy price sudah diinput di PO) atau isi Subtotal manual.');
    rmi_redirect('purchases_invoice_ap_edit.php?id=' . $id);
  }

  // optional doc upload
  $doc_path = $ap['doc_path'] ?? null;
  if (!empty($_FILES['doc_file']['tmp_name'])) {
    $dir = p_dir_invoice_doc_local($ap['ap_code']);
    $origName = (string)($_FILES['doc_file']['name'] ?? '');
    $safeName = rmi_safe_filename($origName);
    if ($safeName === '') {
      p_flash_set('danger','Nama dokumen tidak valid.');
      rmi_redirect('purchases_invoice_ap_edit.php?id=' . $id);
    }
    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','jpg','jpeg','png'], true)) {
      p_flash_set('danger','Dokumen harus PDF/JPG/PNG.');
      rmi_redirect('purchases_invoice_ap_edit.php?id=' . $id);
    }
    $fname = 'doc_' . date('Ymd_His') . '.' . $ext;
    $dest = $dir . '/' . $fname;
    if (!move_uploaded_file($_FILES['doc_file']['tmp_name'], $dest)) {
      p_flash_set('danger','Gagal upload dokumen.');
      rmi_redirect('purchases_invoice_ap_edit.php?id=' . $id);
    }
    $doc_path = 'uploads/invoice/' . $ap['ap_code'] . '/' . $fname;
  }

  $pdo->prepare("UPDATE purchases_invoice_ap SET invoice_type=?, invoice_number=?, invoice_date=?, due_date=?, manufacture_id=?, office_code=?, po_id=?, currency=?, subtotal=?, tax_percent=?, tax_amount=?, total_amount=?, note=?, doc_path=? WHERE id=?")
      ->execute([$invoice_type,$invoice_number,$invoice_date,$due_date,$manufacture_id,$office_code,($po_id>0?$po_id:null),$currency,$subtotal,$tax_percent,$tax_amount,$total_amount,$note,$doc_path,$id]);

  if (function_exists('master_audit')) {
    $apCode = (string)($ap['ap_code'] ?? 'AP-' . $id);
    master_audit($pdo, 'purchases_invoice_ap', 'purchases_invoice_ap', 'UPDATE', $id, $apCode, "AP Invoice updated: {$apCode}", []);
  }
  p_flash_set('success','AP Invoice updated: '.($ap['ap_code'] ?? ''));
  rmi_redirect("purchases_invoice_ap.php");
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Edit Invoice Supplier (AP)', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Edit Invoice Supplier (AP)',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{background:radial-gradient(1200px 600px at 20% 10%, #0b1220 0%, #05070d 55%, #02030a 100%); color:#e5e7eb}
    .card{background:rgba(17,24,39,.7); border:1px solid rgba(255,255,255,.06)}
    .form-control, .form-select{background:rgba(2,6,23,.6); border:1px solid rgba(148,163,184,.25); color:#e5e7eb}
    .form-text{color:#9ca3af}
    .btn-soft{background:rgba(59,130,246,.14); border:1px solid rgba(59,130,246,.35); color:#e5e7eb}</style>',
]);
?>

<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <div class="small text-secondary">Purchases</div>
      <h4 class="mb-0">Edit Invoice Supplier (AP)</h4>
      <div class="small text-secondary"><?=h($ap['ap_code'] ?? '')?> • Status: <?=h($ap['status'] ?? '')?> • Paid: <?=number_format($paid_amount,2,',','.')?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-soft btn-sm" href="purchases_invoice_ap.php">← Back</a>
      <a class="btn btn-outline-light btn-sm" href="<?= h($baseProject . '/chat/index.php?context=AP:' . (int)$id) ?>">Open Chat</a>
    </div>
  </div>

  <?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

  <div class="card p-3">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="update_ap" value="1">
      <div class="row g-2">
        <div class="col-md-2">
          <label class="form-label">Type</label>
          <select class="form-select form-select-sm" name="invoice_type" id="invoice_type">
            <option value="PROFORMA" <?=($ap['invoice_type']==='PROFORMA'?'selected':'')?>>PROFORMA (DP)</option>
            <option value="FINAL" <?=($ap['invoice_type']==='FINAL'?'selected':'')?>>FINAL (Pelunasan)</option>
            <option value="PIB" <?=($ap['invoice_type']==='PIB'?'selected':'')?>>PIB</option>
            <option value="OTHER" <?=($ap['invoice_type']==='OTHER'?'selected':'')?>>Other</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Invoice Number</label>
          <input class="form-control form-control-sm" name="invoice_number" value="<?=h($ap['invoice_number'] ?? '')?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Invoice Date</label>
          <input class="form-control form-control-sm" type="date" name="invoice_date" value="<?=h($ap['invoice_date'] ?? date('Y-m-d'))?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Due Date</label>
          <input class="form-control form-control-sm" type="date" name="due_date" value="<?=h($ap['due_date'] ?? '')?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Link PO</label>
          <select class="form-select form-select-sm" name="po_id" id="po_id">
            <option value="0">-- none --</option>
            <?php foreach($po_list as $po): ?>
              <option value="<?=h($po['id'])?>"
                data-m="<?=h($po['manufacture_id'])?>"
                data-office="<?=h($po['office_code'])?>"
                data-cur="<?=h($po['currency'])?>"
                data-total="<?=h($po['total_amount'])?>"
                <?=((int)$ap['po_id']===(int)$po['id']?'selected':'')?>
              ><?=h($po['po_code'])?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Untuk DP/FINAL wajib pilih PO.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label">Manufacture</label>
          <select class="form-select form-select-sm" name="manufacture_id" id="manufacture_id">
            <option value="">-- pilih pabrikan --</option>
            <?php foreach($manufactures as $m): ?>
              <option value="<?=h($m['id'])?>" <?=((int)$ap['manufacture_id']===(int)$m['id']?'selected':'')?>>
                <?=h(($m['manufacture_code']??'')." - ".($m['manufacture_name']??''))?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Office</label>
          <select class="form-select form-select-sm" name="office_code" id="office_code">
            <option value="">-- pilih office --</option>
            <?php foreach($offices as $o): ?>
              <option value="<?=h($o['office_code'])?>" <?=((string)$ap['office_code']===(string)$o['office_code']?'selected':'')?>>
                <?=h(($o['office_code']??'')." - ".($o['office_name']??''))?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Currency</label>
          <select class="form-select form-select-sm" name="currency" id="currency">
            <?php foreach(['IDR','USD','CNY'] as $c): ?>
              <option value="<?=h($c)?>" <?=((string)$ap['currency']===(string)$c?'selected':'')?>><?=h($c)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">% of PO (optional)</label>
          <input class="form-control form-control-sm text-end" type="number" step="0.01" name="percent_of_po" id="percent_of_po" value="<?=h($percent_default>0?$percent_default:'')?>" placeholder="30">
          <div class="form-text">Isi 30 untuk DP, 70 untuk final.</div>
        </div>

        <div class="col-md-2">
          <label class="form-label">PO Total</label>
          <input class="form-control form-control-sm text-end" value="<?=number_format($po_total,2,',','.')?>" readonly>
          <div class="form-text">Nilai total PO (untuk hitung DP/Final).</div>
        </div>

        <div class="col-md-2">
          <label class="form-label">Subtotal</label>
          <input class="form-control form-control-sm text-end" type="number" step="0.01" name="subtotal" id="subtotal" value="<?=h($ap['subtotal'] ?? 0)?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Tax %</label>
          <input class="form-control form-control-sm text-end" type="number" step="0.01" name="tax_percent" id="tax_percent" value="<?=h($ap['tax_percent'] ?? 0)?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Dokumen (PDF/JPG/PNG)</label>
          <input class="form-control form-control-sm" type="file" name="doc_file">
          <div class="form-text">Kosongkan jika tidak ganti dokumen.</div>
        </div>
        <div class="col-md-12">
          <label class="form-label">Note</label>
          <textarea class="form-control form-control-sm" rows="2" name="note"><?=h($ap['note'] ?? '')?></textarea>
        </div>
      </div>

      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-soft btn-sm" type="submit">Save Changes</button>
        <a class="btn btn-outline-light btn-sm" href="purchases_invoice_ap.php">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
function recalcTotal(){
  // no live total field here; kept for parity
}

function applyPercent(){
  const poSel = document.getElementById('po_id');
  const opt = poSel.options[poSel.selectedIndex];
  const total = parseFloat(opt?.getAttribute('data-total')||'0');
  const pctEl = document.getElementById('percent_of_po');
  const pct = parseFloat((pctEl?.value||'0'));
  if (total>0 && pct>0) {
    document.getElementById('subtotal').value = (total * pct/100).toFixed(2);
  }
}

// Auto fill from PO selected option (manufacture, office, currency)
function syncFromPO(){
  const poSel = document.getElementById('po_id');
  const opt = poSel.options[poSel.selectedIndex];
  if (!opt) return;

  const mid = opt.getAttribute('data-m');
  const office = opt.getAttribute('data-office');
  const cur = opt.getAttribute('data-cur');

  const mEl = document.getElementById('manufacture_id');
  const oEl = document.getElementById('office_code');
  const cEl = document.getElementById('currency');

  if (mid && mEl && (!mEl.value || parseInt(mEl.value||'0')<=0)) mEl.value = mid;
  if (office && oEl && (!oEl.value)) oEl.value = office;
  if (cur && cEl && (!cEl.value)) cEl.value = cur;

  // default percent based on type if empty
  const pctEl = document.getElementById('percent_of_po');
  const it = document.getElementById('invoice_type').value;
  if (pctEl && (!pctEl.value || parseFloat(pctEl.value||'0')<=0)) {
    if (it==='PROFORMA') pctEl.value = '30';
    else if (it==='FINAL') pctEl.value = '70';
  }

  applyPercent();
}

document.getElementById('po_id').addEventListener('change', syncFromPO);

document.getElementById('invoice_type').addEventListener('change', ()=>{
  const pctEl = document.getElementById('percent_of_po');
  const it = document.getElementById('invoice_type').value;
  if (pctEl && (!pctEl.value || parseFloat(pctEl.value||'0')<=0)) {
    if (it==='PROFORMA') pctEl.value = '30';
    else if (it==='FINAL') pctEl.value = '70';
    else pctEl.value = '';
  }
  applyPercent();
});

document.getElementById('percent_of_po').addEventListener('input', applyPercent);

// initial sync (handles prefilled po_id)
syncFromPO();
</script>
<?php rmi_footer(); ?>
