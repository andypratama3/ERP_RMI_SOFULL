<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac_guard.php';
require_login();
if (function_exists('require_any_permission')) {
    $allowed = function_exists('can_any') && can_any(['PURCHASES.AP_PAYMENT_VIEW', 'PURCHASES.AP_PAYMENT_CREATE', 'PURCHASES.AP_PAYMENT_EDIT','PURCHASES.AP_INVOICE_VIEW', 'PURCHASES.AP_INVOICE_CREATE', 'PURCHASES.AP_INVOICE_EDIT','PURCHASES.VIEW']);
    if (!$allowed && function_exists('require_role')) {
        require_role(['FIN','ADMIN','SUPERADMIN','SYS','PQP','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
    } elseif (!$allowed) {
        http_response_code(403); echo 'Forbidden'; exit;
    }
} else {
    require_role(['FIN','ADMIN','SUPERADMIN','SYS','PQP','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
require_once __DIR__ . '/../master/_audit_master.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

// Opening balance AP lama: tabel terpisah agar tidak mengubah alur AP ERP/PO/GR yang sudah berjalan.
function ap_opening_payment_ensure(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS fin_ap_opening (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_code VARCHAR(100) NULL, supplier_name VARCHAR(200) NOT NULL, office_code VARCHAR(32) NULL,
    legacy_document_no VARCHAR(100) NOT NULL, document_date DATE NOT NULL, due_date DATE NULL,
    original_amount DECIMAL(18,2) NOT NULL DEFAULT 0, paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    outstanding_amount DECIMAL(18,2) NOT NULL DEFAULT 0, payment_date DATE NULL,
    status ENUM('UNPAID','PARTIAL','PAID','CANCELLED') NOT NULL DEFAULT 'UNPAID', currency VARCHAR(12) NOT NULL DEFAULT 'IDR',
    notes TEXT NULL, source VARCHAR(30) NOT NULL DEFAULT 'IMPORT_LEGACY', import_batch VARCHAR(80) NULL,
    created_by VARCHAR(100) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
    PRIMARY KEY (id), UNIQUE KEY uq_fin_ap_legacy (legacy_document_no, supplier_name),
    KEY idx_fin_ap_supplier (supplier_code, supplier_name), KEY idx_fin_ap_due_date (due_date), KEY idx_fin_ap_status (status), KEY idx_fin_ap_office (office_code)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS fin_ap_opening_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, opening_ap_id BIGINT UNSIGNED NOT NULL, pay_code VARCHAR(100) NOT NULL,
    pay_date DATE NOT NULL, amount DECIMAL(18,2) NOT NULL DEFAULT 0, method VARCHAR(30) NOT NULL DEFAULT 'TRANSFER',
    bank_name VARCHAR(150) NULL, reference VARCHAR(150) NULL, note TEXT NULL, doc_path VARCHAR(500) NULL,
    created_by VARCHAR(100) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
    PRIMARY KEY (id), UNIQUE KEY uq_fin_ap_opening_pay_code (pay_code), KEY idx_fin_ap_opening_pay_ap (opening_ap_id), KEY idx_fin_ap_opening_pay_date (pay_date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ap_opening_recalc(PDO $pdo, int $id): void {
  $st=$pdo->prepare("SELECT original_amount, paid_amount FROM fin_ap_opening WHERE id=?"); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC);
  if(!$r) return;
  $st=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_ap_opening_payments WHERE opening_ap_id=? AND deleted_at IS NULL"); $st->execute([$id]);
  $extra=(float)$st->fetchColumn(); $orig=(float)$r['original_amount']; $paid=(float)$r['paid_amount']+$extra; $out=max(0,$orig-$paid);
  $status=$out<=0.01?'PAID':($paid>0?'PARTIAL':'UNPAID');
  $pdo->prepare("UPDATE fin_ap_opening SET outstanding_amount=?, status=?, payment_date=CASE WHEN ?='PAID' THEN COALESCE((SELECT MAX(pay_date) FROM fin_ap_opening_payments WHERE opening_ap_id=? AND deleted_at IS NULL),payment_date) ELSE payment_date END, updated_at=NOW() WHERE id=?")
      ->execute([$out,$status,$status,$id,$id]);
}
ap_opening_payment_ensure($pdo);

if (!p_can_fin_ops()) { http_response_code(403); echo "Access denied."; exit; }

$flash = p_flash_get();

function pay_upload_dir($pay_code){
  $dir = __DIR__ . '/uploads/payment/' . $pay_code;
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}

$ap_id_prefill=(int)($_GET['ap_id'] ?? 0);
$opening_ap_id_prefill=(int)($_GET['opening_ap_id'] ?? 0);

// Hutang lama hasil import (terpisah dari purchases_invoice_ap).
$openingInvoices=[];
try {
  $openingInvoices=$pdo->query("SELECT o.*, COALESCE(px.paid_extra,0) paid_extra, GREATEST(o.original_amount-COALESCE(o.paid_amount,0)-COALESCE(px.paid_extra,0),0) outstanding FROM fin_ap_opening o LEFT JOIN (SELECT opening_ap_id,SUM(amount) paid_extra FROM fin_ap_opening_payments WHERE deleted_at IS NULL GROUP BY opening_ap_id) px ON px.opening_ap_id=o.id WHERE UPPER(COALESCE(o.status,'UNPAID')) IN ('UNPAID','PARTIAL') ORDER BY o.due_date IS NULL, o.due_date, o.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e) {}

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
      AND (ap.po_id IS NULL OR UPPER(COALESCE(po.status,'')) NOT IN ('CANCELLED','CANCELED','VOID'))
    ORDER BY ap.id DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Create payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  rbac_guard_require_csrf_post();
}

if (isset($_POST['create_opening_pay'])) {
  rbac_guard_require_fin_central_approver();
  $openingId=(int)($_POST['opening_ap_id'] ?? 0);
  $pay_date=trim((string)($_POST['opening_pay_date'] ?? date('Y-m-d')));
  $amount=(float)($_POST['opening_amount'] ?? 0);
  $method=trim((string)($_POST['opening_method'] ?? 'TRANSFER'));
  $bank_name=trim((string)($_POST['opening_bank_name'] ?? ''));
  $reference=trim((string)($_POST['opening_reference'] ?? ''));
  $note=trim((string)($_POST['opening_note'] ?? ''));
  if($openingId<=0 || $amount<=0){ p_flash_set('danger','Hutang lama dan nominal pembayaran wajib.'); rmi_redirect('purchases_payment_ap.php'); }
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$pay_date)){ p_flash_set('danger','Tanggal pembayaran hutang lama tidak valid.'); rmi_redirect('purchases_payment_ap.php'); }

  $st=$pdo->prepare("SELECT o.*, COALESCE(px.paid_extra,0) paid_extra, GREATEST(o.original_amount-COALESCE(o.paid_amount,0)-COALESCE(px.paid_extra,0),0) outstanding FROM fin_ap_opening o LEFT JOIN (SELECT opening_ap_id,SUM(amount) paid_extra FROM fin_ap_opening_payments WHERE deleted_at IS NULL GROUP BY opening_ap_id) px ON px.opening_ap_id=o.id WHERE o.id=? AND UPPER(COALESCE(o.status,'UNPAID'))<>'CANCELLED'");
  $st->execute([$openingId]); $oi=$st->fetch(PDO::FETCH_ASSOC);
  if(!$oi){ p_flash_set('danger','Data hutang lama tidak ditemukan.'); rmi_redirect('purchases_payment_ap.php'); }
  $outstanding=(float)($oi['outstanding'] ?? 0);
  if($outstanding<=0.01){ p_flash_set('warning','Hutang lama ini sudah lunas.'); rmi_redirect('purchases_payment_ap.php'); }
  if($amount>$outstanding+0.01){ p_flash_set('danger','Amount melebihi outstanding hutang lama.'); rmi_redirect('purchases_payment_ap.php'); }
  if($reference!==''){
    $st=$pdo->prepare("SELECT pay_code FROM fin_ap_opening_payments WHERE opening_ap_id=? AND UPPER(COALESCE(reference,''))=UPPER(?) AND deleted_at IS NULL LIMIT 1");
    $st->execute([$openingId,$reference]); if($st->fetchColumn()){ p_flash_set('danger','Reference pembayaran duplikat untuk hutang lama ini.'); rmi_redirect('purchases_payment_ap.php'); }
  }
  $office=up($oi['office_code'] ?? 'OFF'); $dateYmd=date('ymd',strtotime($pay_date));
  $prefix='APLEG-PAY-'.$office.'-'.$dateYmd.'-';
  $pay_code=p_generate_code($pdo,'fin_ap_opening_payments','pay_code',$prefix);
  $doc_path=null;
  if(!empty($_FILES['opening_doc']['tmp_name'])){
    $dir=pay_upload_dir($pay_code); $safeName=rmi_safe_filename((string)($_FILES['opening_doc']['name'] ?? ''));
    $ext=strtolower(pathinfo($safeName,PATHINFO_EXTENSION));
    if($safeName==='' || !in_array($ext,['pdf','jpg','jpeg','png'],true)){ p_flash_set('danger','Dokumen hutang lama hanya PDF/JPG/PNG.'); rmi_redirect('purchases_payment_ap.php'); }
    $target=$dir.'/proof.'.$ext; if(move_uploaded_file($_FILES['opening_doc']['tmp_name'],$target)) $doc_path='purchases/uploads/payment/'.$pay_code.'/proof.'.$ext;
  }
  try {
    $pdo->prepare("INSERT INTO fin_ap_opening_payments (opening_ap_id,pay_code,pay_date,amount,method,bank_name,reference,note,doc_path,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$openingId,$pay_code,$pay_date,$amount,$method,$bank_name,$reference,$note,$doc_path,p_username()]);
    ap_opening_recalc($pdo,$openingId);
    if(function_exists('master_audit')) master_audit($pdo,'fin_ap_opening_payments','fin_ap_opening_payments','CREATE',(int)$pdo->lastInsertId(),$pay_code,'Legacy AP payment created: '.$pay_code,['opening_ap_id'=>$openingId,'amount'=>$amount]);
    p_flash_set('success','Payment hutang lama dibuat: '.$pay_code);
  } catch(Throwable $e){ if(function_exists('rmi_log_module_error')) rmi_log_module_error('fin_ap_opening_payments',$e,['opening_ap_id'=>$openingId]); p_flash_set('danger','Gagal membuat pembayaran hutang lama.'); }
  rmi_redirect('purchases_payment_ap.php');
}

if (isset($_POST['create_pay'])) {
  // FIN Central Approver guard — posting payment is a cash-outflow action.
  // Only MgrFIN_BGR (FIN Pusat) + SYS may execute. All other FIN managers/staff → 403.
  rbac_guard_require_fin_central_approver();
  $ap_id=(int)($_POST['ap_id'] ?? 0);
  $pay_date=$_POST['pay_date'] ?? date('Y-m-d');
  $amount=(float)($_POST['amount'] ?? 0);
  $percent_of_po=(float)($_POST['percent_of_po'] ?? 0);
  $method=trim((string)($_POST['method'] ?? 'TRANSFER'));
  $bank_name=trim((string)($_POST['bank_name'] ?? ''));
  $reference=trim((string)($_POST['reference'] ?? ''));
  $note=trim((string)($_POST['note'] ?? ''));

  if ($ap_id<=0) { p_flash_set('danger','Invoice wajib.'); rmi_redirect("purchases_payment_ap.php"); }
  if ($amount<=0 && $percent_of_po<=0) { p_flash_set('danger','Amount wajib (atau isi % of PO).'); rmi_redirect("purchases_payment_ap.php"); }

  // Load invoice info (for safety + optional % of PO helper)
  $stInv = $pdo->prepare("
    SELECT ap.id, ap.office_code, ap.total_amount, ap.currency, ap.invoice_type, ap.po_id, ap.status,
      COALESCE(paid.paid_amount,0) AS paid_amount,
      (ap.total_amount - COALESCE(paid.paid_amount,0)) AS outstanding,
      po.total_amount AS po_total_amount, po.status AS po_status
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
  if (!$invInfo) { p_flash_set('danger','Invoice tidak ditemukan.'); rmi_redirect("purchases_payment_ap.php"); }
  $poStatus = strtoupper(trim((string)($invInfo['po_status'] ?? '')));
  if (in_array($poStatus, ['CANCELLED','CANCELED','VOID'], true)) {
    p_flash_set('danger','PO terkait sudah CANCEL - SELESAI. Payment AP tidak dapat diproses.');
    rmi_redirect("purchases_payment_ap.php");
  }
  if (strtoupper((string)($invInfo['status'] ?? '')) === 'HOLD_3WM') {
    p_flash_set('danger','Invoice masih HOLD_3WM (3-way match mismatch), tidak bisa dibayar.');
    rmi_redirect("purchases_payment_ap.php");
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

  if ($amount<=0) { p_flash_set('danger','Amount tidak valid.'); rmi_redirect("purchases_payment_ap.php"); }
  if ($outstanding>0 && $amount > ($outstanding + 0.01)) {
    p_flash_set('danger','Amount melebihi outstanding invoice.'); rmi_redirect("purchases_payment_ap.php");
  }
  if ($pay_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $pay_date) !== 1) {
    p_flash_set('danger','Format tanggal pembayaran tidak valid.');
    rmi_redirect("purchases_payment_ap.php");
  }
  if ($method === '') {
    p_flash_set('danger','Metode pembayaran wajib diisi.');
    rmi_redirect("purchases_payment_ap.php");
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
      rmi_redirect("purchases_payment_ap.php");
    }
  }



  // Office from invoice
  $office = up($invInfo['office_code'] ?? 'OFF');

  $dateYmd=date('ymd', strtotime($pay_date));
  if (!function_exists('doc_prefix_pay')) require_once __DIR__ . '/../config/doc_numbering.php';
  $prefix=doc_prefix_pay($office, $dateYmd);
  $pay_code=p_generate_code($pdo,'purchases_payment_ap','pay_code',$prefix);

  $doc_path=null;
  if (!empty($_FILES['doc']['tmp_name'])) {
    $dir=pay_upload_dir($pay_code);
    $origName = (string)($_FILES['doc']['name'] ?? '');
    $safeName = rmi_safe_filename($origName);
    if ($safeName === '') { p_flash_set('danger','Nama dokumen tidak valid.'); rmi_redirect("purchases_payment_ap.php"); }
    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    if (!in_array($ext,['pdf','jpg','jpeg','png'],true)) { p_flash_set('danger','Dokumen hanya PDF/JPG/PNG.'); rmi_redirect("purchases_payment_ap.php"); }
    $target=$dir.'/proof.'.$ext;
    if (move_uploaded_file($_FILES['doc']['tmp_name'], $target)) $doc_path='purchases/uploads/payment/'.$pay_code.'/proof.'.$ext;
  }

  try {
    $pdo->prepare("INSERT INTO purchases_payment_ap (pay_code, ap_id, pay_date, amount, method, bank_name, reference, note, doc_path, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$pay_code,$ap_id,$pay_date,$amount,$method,$bank_name,$reference,$note,$doc_path,p_username()]);
    $pay_id = (int)$pdo->lastInsertId();
    p_recalc_ap_status($pdo,$ap_id);
    p_audit($pdo,'PAY',$pay_code,'CREATE',['ap_id'=>$ap_id,'amount'=>$amount]);
    if (function_exists('master_audit')) {
      master_audit($pdo, 'purchases_payment_ap', 'purchases_payment_ap', 'CREATE', $pay_id, $pay_code, "AP payment created: {$pay_code}", ['ap_id' => $ap_id, 'amount' => $amount]);
    }
    if (function_exists('auth_audit_event')) {
      auth_audit_event('PURCHASES.AP_PAYMENT_APPROVE_POST', 'purchases_payment_ap', $pay_code, [
        'ap_id' => $ap_id, 'amount' => $amount, 'pay_id' => $pay_id, 'method' => $method,
      ]);
    }
    p_flash_set('success',"Payment dibuat: {$pay_code}");
  } catch (Throwable $e) {
    if (function_exists('rmi_log_module_error')) rmi_log_module_error('purchases_payment_ap', $e, ['action' => 'AP_PAYMENT_CREATE', 'ap_id' => $ap_id ?? null]);
    p_flash_set('danger','Gagal membuat payment AP. Periksa data pembayaran.');
  }
  rmi_redirect("purchases_payment_ap.php");
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

$openingPaymentRows=[];
try {
  $openingPaymentRows=$pdo->query("SELECT p.*,o.legacy_document_no,o.supplier_name,o.office_code,o.currency FROM fin_ap_opening_payments p JOIN fin_ap_opening o ON o.id=p.opening_ap_id WHERE p.deleted_at IS NULL ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e) {}

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'purchases_payment_ap' ORDER BY created_at DESC LIMIT 50");
  $st->execute();
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Payment AP', [
  'active' => 'purchases_ap',
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
    <a class="btn btn-soft btn-sm" href="../dashboards/finance/ap_rekap.php">Rekap Hutang</a>
    <a class="btn btn-soft btn-sm" href="purchases_ap_import.php">Import Hutang Lama</a>
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

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-1">Pembayaran Hutang Lama (Import)</div>
    <div class="muted mb-2">Terpisah dari AP Invoice ERP. Pembayaran ini hanya mengurangi outstanding <code>fin_ap_opening</code>.</div>
    <form method="post" enctype="multipart/form-data" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="col-md-5"><label class="form-label">Hutang Lama (UNPAID/PARTIAL)</label><select class="form-select form-select-sm" name="opening_ap_id" id="opening_ap_id" required><option value="">-- pilih hutang lama --</option><?php foreach($openingInvoices as $oi): ?><option value="<?=h($oi['id'])?>" data-outstanding="<?=h($oi['outstanding'])?>" <?=($opening_ap_id_prefill===(int)$oi['id'])?'selected':''?>><?=h(($oi['legacy_document_no']??'').' | '.($oi['supplier_name']??'').' | '.($oi['office_code']??'').' | outstanding '.p_money($oi['outstanding'],$oi['currency']??'IDR'))?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label">Pay Date</label><input class="form-control form-control-sm" type="date" name="opening_pay_date" value="<?=h(date('Y-m-d'))?>" required></div>
      <div class="col-md-2"><label class="form-label">Amount</label><input class="form-control form-control-sm text-end" type="number" step="0.01" name="opening_amount" id="opening_amount" placeholder="auto outstanding" required></div>
      <div class="col-md-3"><label class="form-label">Method</label><select class="form-select form-select-sm" name="opening_method"><option>TRANSFER</option><option>CASH</option><option>GIRO</option></select></div>
      <div class="col-md-4"><label class="form-label">Bank (optional)</label><input class="form-control form-control-sm" name="opening_bank_name"></div>
      <div class="col-md-4"><label class="form-label">Reference (optional)</label><input class="form-control form-control-sm" name="opening_reference"></div>
      <div class="col-md-4"><label class="form-label">Dokumen</label><input class="form-control form-control-sm" type="file" name="opening_doc" accept=".pdf,.jpg,.jpeg,.png"></div>
      <div class="col-12"><label class="form-label">Note</label><textarea class="form-control form-control-sm" name="opening_note" rows="2"></textarea></div>
      <div class="col-12"><button class="btn btn-warning btn-sm" name="create_opening_pay">Save Payment Hutang Lama</button></div>
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

<div class="card mt-3 mb-3"><div class="card-body"><div class="fw-semibold mb-2">Payment Hutang Lama</div><div class="table-responsive"><table class="table table-sm table-dark align-middle"><thead><tr><th>Pay Code</th><th>Dokumen Lama</th><th>Supplier</th><th>Office</th><th>Pay Date</th><th class="num">Amount</th><th>Method</th><th>Reference</th><th>Doc</th></tr></thead><tbody><?php foreach($openingPaymentRows as $r): ?><tr><td><b><?=h($r['pay_code'])?></b></td><td><?=h($r['legacy_document_no']??'')?></td><td><?=h($r['supplier_name']??'')?></td><td><?=h($r['office_code']??'')?></td><td><?=h($r['pay_date']??'')?></td><td class="num"><?=h(p_money($r['amount'],$r['currency']??'IDR'))?></td><td><?=h($r['method']??'')?></td><td><?=h($r['reference']??'')?></td><td><?php if(!empty($r['doc_path'])): ?><a class="btn btn-soft btn-sm" href="../<?=h($r['doc_path'])?>" target="_blank">View</a><?php else: ?><span class="muted">-</span><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$openingPaymentRows): ?><tr><td colspan="9" class="muted text-center">Belum ada pembayaran hutang lama.</td></tr><?php endif; ?></tbody></table></div></div></div>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Audit Log <span class="muted">(Last 50 events)</span></div>
  <?php if (empty($audit_rows)): ?>
    <div class="muted">Belum ada audit log.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-dark align-middle">
        <thead><tr><th style="width:180px">Time</th><th style="width:120px">Action</th><th style="width:140px">Code</th><th style="width:120px">User</th><th>Description</th></tr></thead>
        <tbody>
        <?php foreach ($audit_rows as $a): ?>
          <tr><td><?= h($a['created_at'] ?? '') ?></td><td><?= h($a['action'] ?? '') ?></td><td><?= h($a['record_code'] ?? '') ?></td><td><?= h($a['username'] ?? '') ?></td><td><?= h($a['description'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
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
    const openingSel=document.getElementById('opening_ap_id');
    const openingAmt=document.getElementById('opening_amount');
    const fillOpening=()=>{ if(openingSel && openingAmt){ const o=openingSel.options[openingSel.selectedIndex]; if(o && o.dataset.outstanding) openingAmt.value=parseFloat(o.dataset.outstanding||'0').toFixed(2); } };
    if(openingSel) openingSel.addEventListener('change',fillOpening);
    fillOpening();

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
