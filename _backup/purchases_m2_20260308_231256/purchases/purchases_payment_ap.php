<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_login();
require_any_permission(['PURCHASES.AP_PAYMENT_CRUD','PURCHASES.AP_INVOICE_CRUD','PURCHASES.VIEW']);

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

if (!p_can_fin_ops()) { http_response_code(403); echo "Access denied."; exit; }

$flash = p_flash_get();

function pay_upload_dir($pay_code){
  $dir = __DIR__ . '/uploads/payment/' . $pay_code;
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}

$ap_id_prefill=(int)($_GET['ap_id'] ?? 0);

// Unpaid/partial invoices
$invoices=[];
try {
  $invoices = $pdo->query("
    SELECT ap.id, ap.ap_code, ap.currency, ap.total_amount, ap.invoice_type, ap.po_id,
      po.po_code, po.total_amount AS po_total_amount,
      COALESCE(paid.paid_amount,0) AS paid_amount,
      (ap.total_amount - COALESCE(paid.paid_amount,0)) AS outstanding,
      m.manufacture_name,
      m.bank_name, m.bank_account_name, m.bank_account_number, m.bank_swift_code, m.bank_iban, m.bank_currency
    FROM purchases_invoice_ap ap
    LEFT JOIN master_manufactures m ON m.id = ap.manufacture_id
    LEFT JOIN purchases_po po ON po.id = ap.po_id
    LEFT JOIN (
      SELECT ap_id, SUM(amount) paid_amount
      FROM purchases_payment_ap
      WHERE deleted_at IS NULL
      GROUP BY ap_id
    ) paid ON paid.ap_id = ap.id
    WHERE ap.deleted_at IS NULL AND ap.status IN ('UNPAID','PARTIAL')
    ORDER BY ap.id DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Create payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
}

if (isset($_POST['create_pay'])) {
  $ap_id=(int)($_POST['ap_id'] ?? 0);
  $pay_date=$_POST['pay_date'] ?? date('Y-m-d');
  $amount=(float)($_POST['amount'] ?? 0);
  $percent_of_po=(float)($_POST['percent_of_po'] ?? 0);
  $method=trim((string)($_POST['method'] ?? 'TRANSFER'));
  $bank_name=trim((string)($_POST['bank_name'] ?? ''));
  $reference=trim((string)($_POST['reference'] ?? ''));
  $note=trim((string)($_POST['note'] ?? ''));

  if ($ap_id<=0) { p_flash_set('danger','Invoice wajib.'); header("Location: purchases_payment_ap.php"); exit; }
  if ($amount<=0 && $percent_of_po<=0) { p_flash_set('danger','Amount wajib (atau isi % of PO).'); header("Location: purchases_payment_ap.php"); exit; }

  // Load invoice info (for safety + optional % of PO helper)
  $stInv = $pdo->prepare("
    SELECT ap.id, ap.office_code, ap.total_amount, ap.currency, ap.invoice_type, ap.po_id, ap.status,
      COALESCE(paid.paid_amount,0) AS paid_amount,
      (ap.total_amount - COALESCE(paid.paid_amount,0)) AS outstanding,
      po.total_amount AS po_total_amount
    FROM purchases_invoice_ap ap
    LEFT JOIN (
      SELECT ap_id, SUM(amount) paid_amount
      FROM purchases_payment_ap
      WHERE deleted_at IS NULL
      GROUP BY ap_id
    ) paid ON paid.ap_id = ap.id
    LEFT JOIN purchases_po po ON po.id = ap.po_id
    WHERE ap.id = ?
  ");
  $stInv->execute([$ap_id]);
  $invInfo = $stInv->fetch(PDO::FETCH_ASSOC);
  if (!$invInfo) { p_flash_set('danger','Invoice tidak ditemukan.'); header("Location: purchases_payment_ap.php"); exit; }
  if (strtoupper((string)($invInfo['status'] ?? '')) === 'HOLD_3WM') {
    p_flash_set('danger','Invoice masih HOLD_3WM (3-way match mismatch), tidak bisa dibayar.');
    header("Location: purchases_payment_ap.php");
    exit;
  }

  $outstanding = (float)($invInfo['outstanding'] ?? 0);

  // Auto-calc amount from % of PO (optional)
  if ($amount<=0 && $percent_of_po>0) {
    $po_total = (float)($invInfo['po_total_amount'] ?? 0);
    if ($po_total>0) {
      if ($percent_of_po>100) $percent_of_po = 100;
      $amount = $po_total * ($percent_of_po/100);
      if ($amount > $outstanding) $amount = $outstanding;
    }
  }

  if ($amount<=0) { p_flash_set('danger','Amount tidak valid.'); header("Location: purchases_payment_ap.php"); exit; }
  if ($outstanding>0 && $amount > ($outstanding + 0.01)) {
    p_flash_set('danger','Amount melebihi outstanding invoice.'); header("Location: purchases_payment_ap.php"); exit;
  }
  if ($pay_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $pay_date) !== 1) {
    p_flash_set('danger','Format tanggal pembayaran tidak valid.');
    header("Location: purchases_payment_ap.php");
    exit;
  }
  if ($method === '') {
    p_flash_set('danger','Metode pembayaran wajib diisi.');
    header("Location: purchases_payment_ap.php");
    exit;
  }
  if ($reference !== '') {
    $stDupPay = $pdo->prepare("
      SELECT pay_code
      FROM purchases_payment_ap
      WHERE deleted_at IS NULL
        AND ap_id = ?
        AND UPPER(reference) = UPPER(?)
      LIMIT 1
    ");
    $stDupPay->execute([$ap_id, $reference]);
    $dupPay = (string)($stDupPay->fetchColumn() ?: '');
    if ($dupPay !== '') {
      p_flash_set('danger', 'Reference pembayaran duplikat untuk invoice ini: ' . $dupPay);
      header("Location: purchases_payment_ap.php");
      exit;
    }
  }



  // Office from invoice
  $office = up($invInfo['office_code'] ?? 'OFF');

  $dateYmd=date('ymd', strtotime($pay_date));
  $prefix="RMI-PAY-{$office}-{$dateYmd}-";
  $pay_code=p_generate_code($pdo,'purchases_payment_ap','pay_code',$prefix);

  $doc_path=null;
  if (!empty($_FILES['doc']['tmp_name'])) {
    $dir=pay_upload_dir($pay_code);
    $origName = (string)($_FILES['doc']['name'] ?? '');
    $safeName = rmi_safe_filename($origName);
    if ($safeName === '') { p_flash_set('danger','Nama dokumen tidak valid.'); header("Location: purchases_payment_ap.php"); exit; }
    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    if (!in_array($ext,['pdf','jpg','jpeg','png'],true)) { p_flash_set('danger','Dokumen hanya PDF/JPG/PNG.'); header("Location: purchases_payment_ap.php"); exit; }
    $target=$dir.'/proof.'.$ext;
    if (move_uploaded_file($_FILES['doc']['tmp_name'], $target)) $doc_path='purchases/uploads/payment/'.$pay_code.'/proof.'.$ext;
  }

  try {
    $pdo->prepare("INSERT INTO purchases_payment_ap (pay_code, ap_id, pay_date, amount, method, bank_name, reference, note, doc_path, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$pay_code,$ap_id,$pay_date,$amount,$method,$bank_name,$reference,$note,$doc_path,p_username()]);
    p_recalc_ap_status($pdo,$ap_id);
    p_audit($pdo,'PAY',$pay_code,'CREATE',['ap_id'=>$ap_id,'amount'=>$amount]);
    p_flash_set('success',"Payment dibuat: {$pay_code}");
  } catch (Throwable $e) {
    p_flash_set('danger','Gagal membuat payment AP. Periksa data pembayaran.');
  }
  header("Location: purchases_payment_ap.php"); exit;
}

// List payments
$rows=[];
try {
  $rows = $pdo->query("
    SELECT pay.*, ap.ap_code, ap.currency, ap.invoice_type, m.manufacture_name,
      m.bank_name, m.bank_account_name, m.bank_account_number, m.bank_swift_code, m.bank_iban, m.bank_currency
    FROM purchases_payment_ap pay
    LEFT JOIN purchases_invoice_ap ap ON ap.id = pay.ap_id
    LEFT JOIN master_manufactures m ON m.id = ap.manufacture_id
    LEFT JOIN purchases_po po ON po.id = ap.po_id
    WHERE pay.deleted_at IS NULL
    ORDER BY pay.id DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Payment AP', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Payment AP',
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
    <h3 class="mb-0">Payment AP</h3>
    <div class="muted">DP / Pelunasan / PIB</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-soft btn-sm" href="purchases_dashboard.php">← Dashboard</a>
    <a class="btn btn-soft btn-sm" href="purchases_invoice_ap.php">AP Invoice</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-2">Create Payment</div>
    <form method="post" enctype="multipart/form-data" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="col-md-4">
        <label class="form-label">Invoice (UNPAID/PARTIAL)</label>
        <select class="form-select form-select-sm" name="ap_id" required>
          <option value="">-- pilih invoice --</option>
          <?php foreach($invoices as $inv): ?>
            <option value="<?=h($inv['id'])?>" <?= ($ap_id_prefill===(int)$inv['id'])?'selected':'' ?>>
              <?=h($inv['ap_code'].' | '.$inv['invoice_type'].' | '.$inv['manufacture_name'].' | outstanding '.p_money($inv['outstanding'],$inv['currency']).' '.$inv['currency'])?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-8">
        <label class="form-label">Supplier Bank Info (auto)</label>
        <div class="input-group input-group-sm">
          <input class="form-control" id="sup_bank_name" placeholder="Bank" readonly>
          <input class="form-control" id="sup_account_name" placeholder="Account Name" readonly>
          <input class="form-control" id="sup_account_no" placeholder="Account No" readonly>
        </div>
        <div class="input-group input-group-sm mt-1">
          <input class="form-control" id="sup_swift" placeholder="SWIFT" readonly>
          <input class="form-control" id="sup_iban" placeholder="IBAN" readonly>
          <input class="form-control" id="sup_bank_currency" placeholder="Currency" readonly>
        </div>
        <div class="form-text">Diambil dari <code>master_manufactures</code>. Jika kosong, lengkapi bank supplier/OEM di Master → Manufactures.</div>
      </div>

      <div class="col-md-2">
        <label class="form-label">Pay Date</label>
        <input class="form-control form-control-sm" type="date" name="pay_date" value="<?=h(date('Y-m-d'))?>" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">% of PO (optional)</label>
        <input class="form-control form-control-sm text-end" type="number" step="0.01" name="percent_of_po" id="percent_of_po" placeholder="30">
        <div class="form-text">Opsional. Jika invoice terhubung PO, amount akan dihitung dari % x total PO (maks = outstanding).</div>
      </div>
      <div class="col-md-2">
        <label class="form-label">Amount</label>
        <input class="form-control form-control-sm text-end" type="number" step="0.01" name="amount" id="amount" placeholder="auto = outstanding">
      </div>
      <div class="col-md-2">
        <label class="form-label">Method</label>
        <select class="form-select form-select-sm" name="method">
          <option>TRANSFER</option><option>CASH</option><option>GIRO</option>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Bank (optional)</label>
        <input class="form-control form-control-sm" name="bank_name">
      </div>
      <div class="col-md-4">
        <label class="form-label">Reference (optional)</label>
        <input class="form-control form-control-sm" name="reference">
      </div>
      <div class="col-md-4">
        <label class="form-label">Dokumen (PDF/JPG/PNG)</label>
        <input class="form-control form-control-sm" type="file" name="doc" accept=".pdf,.jpg,.jpeg,.png">
      </div>

      <div class="col-12">
        <label class="form-label">Note</label>
        <textarea class="form-control form-control-sm" name="note" rows="2"></textarea>
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
            <th>AP Code</th>
            <th>Type</th>
            <th>Manufacture</th>
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
              <td><?=h($r['ap_code'] ?? '')?></td>
              <td><?=h($r['invoice_type'] ?? '')?></td>
              <td><?=h($r['manufacture_name'] ?? '')?></td>
              <td><?=h($r['pay_date'])?></td>
              <td class="num"><?=h(p_money($r['amount'], $r['currency'] ?? 'IDR'))?></td>
              <td><?=h($r['method'] ?? '')?></td>
              <td><?=h($r['reference'] ?? '')?></td>
              <td>
                <?php if (!empty($r['doc_path'])): ?><a class="btn btn-soft btn-sm" href="../<?=h($r['doc_path'])?>" target="_blank">View</a>
                <?php else: ?><span class="muted">-</span><?php endif; ?>
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
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>

<script>
  const INV_MAP = {};
  <?php foreach($invoices as $inv): ?>
    INV_MAP[<?= (int)$inv['id'] ?>] = {
      outstanding: <?= json_encode((float)$inv['outstanding']) ?>,
      po_total: <?= json_encode((float)($inv['po_total_amount'] ?? 0)) ?>,
      bank_name: <?= json_encode($inv['bank_name'] ?? '') ?>,
      bank_account_name: <?= json_encode($inv['bank_account_name'] ?? '') ?>,
      bank_account_number: <?= json_encode($inv['bank_account_number'] ?? '') ?>,
      bank_swift_code: <?= json_encode($inv['bank_swift_code'] ?? '') ?>,
      bank_iban: <?= json_encode($inv['bank_iban'] ?? '') ?>,
      bank_currency: <?= json_encode($inv['bank_currency'] ?? '') ?>
    };
  <?php endforeach; ?>

  function applyAutoAmount(){
    const sel = document.querySelector('select[name="ap_id"]');
    const amt = document.getElementById('amount');
    const pct = document.getElementById('percent_of_po');
    if (!sel || !amt) return;

    const id = parseInt(sel.value || '0');
    const m = INV_MAP[id];
    if (!m) return;

    // Auto-fill supplier bank info (from master_manufactures)
    const sbn = document.getElementById('sup_bank_name');
    const san = document.getElementById('sup_account_name');
    const sno = document.getElementById('sup_account_no');
    const ssw = document.getElementById('sup_swift');
    const sib = document.getElementById('sup_iban');
    const scur = document.getElementById('sup_bank_currency');
    if (sbn) sbn.value = (m.bank_name || '');
    if (san) san.value = (m.bank_account_name || '');
    if (sno) sno.value = (m.bank_account_number || '');
    if (ssw) ssw.value = (m.bank_swift_code || '');
    if (sib) sib.value = (m.bank_iban || '');
    if (scur) scur.value = (m.bank_currency || '');

    const out = parseFloat(m.outstanding || 0);
    let val = out;

    if (pct && pct.value) {
      const p = parseFloat(pct.value || '0');
      const poTotal = parseFloat(m.po_total || 0);
      if (p > 0 && poTotal > 0) {
        val = poTotal * p / 100;
        if (val > out) val = out;
      }
    }
    if (isFinite(val)) amt.value = val.toFixed(2);
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    const sel = document.querySelector('select[name="ap_id"]');
    const pct = document.getElementById('percent_of_po');
    if (sel) sel.addEventListener('change', applyAutoAmount);
    if (pct) pct.addEventListener('input', applyAutoAmount);
    applyAutoAmount();
  });
</script>

<script>
  new DataTable('#tbl', { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'] });
</script>
<?php rmi_footer(); ?>
