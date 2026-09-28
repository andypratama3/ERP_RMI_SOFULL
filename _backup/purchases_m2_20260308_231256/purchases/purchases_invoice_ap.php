<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_login();
require_any_permission(['PURCHASES.AP_INVOICE_CRUD','PURCHASES.AP_PAYMENT_CRUD','PURCHASES.VIEW']);

require_once __DIR__ . '/_purchases_lib.php';
require_once __DIR__ . '/../_shared/app_init.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

if (!p_can_fin_ops()) { http_response_code(403); echo "Access denied."; exit; }

$flash = p_flash_get();


// Void (soft-delete) AP invoice (hanya jika belum ada payment)
if (isset($_POST['void_ap'])) {
  require_post();
  verify_csrf((string)($_POST['csrf_token'] ?? ''));
  $id = (int)($_POST['id'] ?? 0);
  if ($id<=0) {
    p_flash_set('danger','Invalid invoice id.');
    header('Location: purchases_invoice_ap.php');
    exit;
  }

  $st = $pdo->prepare("SELECT id, ap_code, note FROM purchases_invoice_ap WHERE id=? AND deleted_at IS NULL LIMIT 1");
  $st->execute([$id]);
  $ap = $st->fetch(PDO::FETCH_ASSOC);
  if (!$ap) {
    p_flash_set('danger','Invoice tidak ditemukan / sudah di-void.');
    header('Location: purchases_invoice_ap.php');
    exit;
  }

  $stPaid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM purchases_payment_ap WHERE ap_id=? AND deleted_at IS NULL");
  $stPaid->execute([$id]);
  $paid = (float)$stPaid->fetchColumn();
  if ($paid > 0) {
    p_flash_set('danger','Tidak bisa void: invoice sudah ada pembayaran. (Gunakan reversal/adjustment).');
    header('Location: purchases_invoice_ap.php');
    exit;
  }

  $note = trim((string)($ap['note'] ?? ''));
  $suffix = '[VOID ' . date('Y-m-d H:i') . ']';
  $newNote = trim($note . ' ' . $suffix);

  $pdo->prepare("UPDATE purchases_invoice_ap SET deleted_at=NOW(), note=? WHERE id=?")->execute([$newNote,$id]);
  if (class_exists(\App\Accounting\GLPostingService::class)) {
    try {
      $gl = new \App\Accounting\GLPostingService();
      $revId = $gl->reverseBySource(
        $pdo,
        'PURCHASES',
        'AP_INVOICE_CREATED',
        (string)($ap['ap_code'] ?? ''),
        'AP invoice void',
        (int)($_SESSION['user_id'] ?? 0)
      );
      p_audit($pdo,'AP',$ap['ap_code'] ?? null,'GL_REVERSE_ON_VOID',['reversal_header_id'=>$revId]);
    } catch (Throwable $e) {
      p_audit($pdo,'AP',$ap['ap_code'] ?? null,'GL_REVERSE_FAILED',['error'=>$e->getMessage()]);
    }
  }
  p_flash_set('success','Invoice void: ' . ($ap['ap_code'] ?? ''));
  header('Location: purchases_invoice_ap.php');
  exit;
}



$pref_po_id = (int)($_GET['po_id'] ?? 0);
$pref_invoice_type = up($_GET['invoice_type'] ?? '');
$pref_percent = (float)($_GET['percent'] ?? 0);


// master data
$manufactures=[]; $offices=[]; $po_list=[];
try { $manufactures = $pdo->query("SELECT id, manufacture_code, manufacture_name FROM master_manufactures WHERE status=1 OR status='active' ORDER BY manufacture_name")->fetchAll(); } catch (Throwable $e) {}
try { $offices = $pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name")->fetchAll(); } catch (Throwable $e) {}
try {
  $po_list = $pdo->query("SELECT id, po_code, manufacture_id, office_code, currency, total_amount
                          FROM purchases_po
                          WHERE deleted_at IS NULL
                          ORDER BY id DESC LIMIT 500")->fetchAll();
} catch (Throwable $e) {}

function inv_upload_dir($ap_code){
  $dir = __DIR__ . '/uploads/invoice/' . $ap_code;
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

if (isset($_POST['create_ap'])) {
  $invoice_type = up($_POST['invoice_type'] ?? 'PROFORMA');
  if (!in_array($invoice_type,['PROFORMA','FINAL','PIB','OTHER'],true)) $invoice_type='PROFORMA';

  $invoice_number = trim((string)($_POST['invoice_number'] ?? ''));
  $invoice_date = trim((string)($_POST['invoice_date'] ?? ''));
  if ($invoice_date==='') $invoice_date = date('Y-m-d');
  $due_date = trim((string)($_POST['due_date'] ?? ''));
  if ($due_date==='') $due_date = null;
  $manufacture_id = (int)($_POST['manufacture_id'] ?? 0);
  $office_code = up($_POST['office_code'] ?? '');
  $po_id = (int)($_POST['po_id'] ?? 0);
  $currency = up($_POST['currency'] ?? 'IDR');
  $subtotal = (float)($_POST['subtotal'] ?? 0);
  $percent_of_po = (float)($_POST['percent_of_po'] ?? 0);

  // DP/FINAL harus link ke PO (agar nilai dihitung dari PO)
  if (in_array($invoice_type,['PROFORMA','FINAL'],true) && $po_id<=0) {
    p_flash_set('danger','Untuk PROFORMA/FINAL wajib pilih PO. Nilai invoice akan dihitung otomatis dari total PO.');
    header('Location: purchases_invoice_ap.php?invoice_type=' . urlencode($invoice_type));
    exit;
  }

  // Default % jika PO sudah dipilih tapi % kosong
  if ($po_id>0 && $percent_of_po<=0) {
    if ($invoice_type==='PROFORMA') $percent_of_po = 30;
    else if ($invoice_type==='FINAL') $percent_of_po = 70;
  }

  // Guard: mencegah DP/FINAL double untuk PO yang sama (enterprise-safe)
  if ($po_id>0 && in_array($invoice_type,['PROFORMA','FINAL'],true)) {
    $stDup = $pdo->prepare("SELECT ap_code, status FROM purchases_invoice_ap WHERE po_id=? AND invoice_type=? AND deleted_at IS NULL LIMIT 1");
    $stDup->execute([$po_id,$invoice_type]);
    $dup = $stDup->fetch(PDO::FETCH_ASSOC);
    if ($dup) {
      $label = ($invoice_type==='PROFORMA') ? 'DP (PROFORMA)' : 'FINAL';
      p_flash_set('danger', $label.' untuk PO ini sudah ada: '.$dup['ap_code'].' (status: '.$dup['status'].'). Jangan buat dobel. Gunakan invoice yang sudah ada (Pay/Edit) atau Void dulu jika itu duplikat.');
      header('Location: purchases_invoice_ap.php');
      exit;
    }
  }

  $tax_percent = (float)($_POST['tax_percent'] ?? 0);
  $note = trim((string)($_POST['note'] ?? ''));

  if ($po_id > 0) {
    foreach ($po_list as $po) {
      if ((int)$po['id'] === $po_id) {
        if ($manufacture_id<=0) $manufacture_id = (int)$po['manufacture_id'];
        if ($office_code==='') $office_code = up($po['office_code']);
        $currency = up($po['currency'] ?? $currency);
        if ($subtotal <= 0) $subtotal = (float)$po['total_amount'];
      }
    }
  }

  if ($manufacture_id<=0 || $office_code==='') {
    p_flash_set('danger','Manufacture & Office wajib.');
    header("Location: purchases_invoice_ap.php"); exit;
  }

  $dateYmd = date('ymd', strtotime($invoice_date));
  $prefix = "RMI-AP-{$office_code}-{$dateYmd}-";
  $ap_code = p_generate_code($pdo,'purchases_invoice_ap','ap_code',$prefix);


  // Auto-calc subtotal from % of PO (optional)
  if ($po_id>0 && $percent_of_po>0) {
    if ($percent_of_po>100) $percent_of_po = 100;
    $stPo = $pdo->prepare("SELECT total_amount FROM purchases_po WHERE id=?");
    $stPo->execute([$po_id]);
    $po_total = (float)($stPo->fetchColumn() ?: 0);
    if ($po_total>0) {
      $subtotal = $po_total * ($percent_of_po/100);
    } else {
      // PO total masih 0 → DP/FINAL berbasis % tidak bisa dihitung
      if ($subtotal <= 0) {
        p_flash_set('danger','Total PO masih 0. Isi harga beli (unit price) di PO terlebih dahulu agar DP/FINAL bisa dihitung otomatis.');
        header('Location: purchases_invoice_ap.php');
        exit;
      }
    }
  }

  $tax_amount = $subtotal * ($tax_percent/100);
  $total_amount = $subtotal + $tax_amount;

  if ($manufacture_id > 0 && $invoice_number !== '') {
    $stDupInv = $pdo->prepare("
      SELECT ap_code
      FROM purchases_invoice_ap
      WHERE deleted_at IS NULL
        AND manufacture_id = ?
        AND UPPER(invoice_number) = UPPER(?)
        AND invoice_date = ?
      LIMIT 1
    ");
    $stDupInv->execute([$manufacture_id, $invoice_number, $invoice_date]);
    $dupInv = (string)($stDupInv->fetchColumn() ?: '');
    if ($dupInv !== '') {
      p_flash_set('danger', 'Invoice vendor duplikat terdeteksi: ' . $dupInv . '. Gunakan invoice existing atau lakukan koreksi.');
      header('Location: purchases_invoice_ap.php');
      exit;
    }
  }

  if ($total_amount <= 0) {
    p_flash_set('danger','Nilai invoice harus > 0. Pastikan pilih PO dan/atau isi % of PO / subtotal.');
    header('Location: purchases_invoice_ap.php');
    exit;
  }

  $apStatus = 'UNPAID';
  if ($po_id > 0 && in_array($invoice_type, ['FINAL','PIB','OTHER'], true) && class_exists(\App\Accounting\ThreeWayMatchValidator::class)) {
    try {
      $validator = new \App\Accounting\ThreeWayMatchValidator();
      $check = $validator->validateApAgainstPo($pdo, $po_id, $total_amount, 0.0, 0.0);
      if (empty($check['ok'])) {
        $apStatus = 'HOLD_3WM';
        $note .= ($note !== '' ? "\n" : '') . '[AUTO HOLD 3WM] ' . (string)($check['reason'] ?? 'Mismatch');
      }
    } catch (Throwable $e) {
      // fail-safe: keep existing behavior if validator runtime fails
    }
  }

  $doc_path = null;
  if (!empty($_FILES['doc']['tmp_name'])) {
    $dir = inv_upload_dir($ap_code);
    $origName = (string)($_FILES['doc']['name'] ?? '');
    $safeName = rmi_safe_filename($origName);
    if ($safeName === '') {
        p_flash_set('danger', 'Nama dokumen tidak valid.');
        header('Location: purchases_invoice_ap.php');
        exit;
    }
    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    if (!in_array($ext,['pdf','jpg','jpeg','png'], true)) {
      p_flash_set('danger','Dokumen hanya PDF/JPG/PNG.');
      header("Location: purchases_invoice_ap.php"); exit;
    }
    $target = $dir . '/invoice.' . $ext;
    if (move_uploaded_file($_FILES['doc']['tmp_name'], $target)) {
      $doc_path = 'purchases/uploads/invoice/'.$ap_code.'/invoice.'.$ext;
    }
  }

  try {
    $st = $pdo->prepare("INSERT INTO purchases_invoice_ap (ap_code, invoice_type, invoice_number, invoice_date, due_date, manufacture_id, office_code, po_id, currency, subtotal, tax_percent, tax_amount, total_amount, status, note, doc_path, created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$ap_code,$invoice_type,$invoice_number,$invoice_date,$due_date?:null,$manufacture_id,$office_code,$po_id?:null,$currency,$subtotal,$tax_percent,$tax_amount,$total_amount,$apStatus,$note,$doc_path,p_username()]);
    p_audit($pdo,'AP',$ap_code,'CREATE',['po_id'=>$po_id,'type'=>$invoice_type,'total'=>$total_amount]);
    if ($apStatus === 'HOLD_3WM') {
      p_audit($pdo,'AP',$ap_code,'HOLD_3WM',['po_id'=>$po_id,'total'=>$total_amount]);
      p_flash_set('warning',"AP Invoice dibuat dengan status HOLD_3WM: {$ap_code}. Lakukan review 3-way match sebelum posting/payment.");
    } else {
      if (class_exists(\App\Accounting\GLPostingService::class)) {
        try {
          $gl = new \App\Accounting\GLPostingService();
          $gl->createJournalFromMapping(
            $pdo,
            'PURCHASES',
            'AP_INVOICE_CREATED',
            $ap_code,
            $total_amount,
            'Auto-post AP invoice ' . $ap_code,
            (int)($_SESSION['user_id'] ?? 0)
          );
        } catch (Throwable $e) {
          // journaling mapping can be empty on first rollout; do not fail AP creation
          p_audit($pdo,'AP',$ap_code,'GL_POST_SKIP',['error'=>$e->getMessage()]);
        }
      }
      p_flash_set('success',"AP Invoice dibuat: {$ap_code}");
    }
  } catch (Throwable $e) {
    p_flash_set('danger','Gagal menyimpan AP invoice. Periksa input lalu coba lagi.');
  }
  header("Location: purchases_invoice_ap.php"); exit;
}

// List
$rows=[];
try {
  $rows = $pdo->query("
    SELECT ap.*, m.manufacture_name,
      COALESCE(paid.paid_amount,0) AS paid_amount,
      (ap.total_amount - COALESCE(paid.paid_amount,0)) AS outstanding
    FROM purchases_invoice_ap ap
    LEFT JOIN master_manufactures m ON m.id = ap.manufacture_id
    LEFT JOIN (
      SELECT ap_id, SUM(amount) paid_amount
      FROM purchases_payment_ap
      WHERE deleted_at IS NULL
      GROUP BY ap_id
    ) paid ON paid.ap_id = ap.id
    WHERE ap.deleted_at IS NULL
    ORDER BY ap.id DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Invoice Supplier (AP)', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Invoice Supplier (AP)',
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
    <h3 class="mb-0">Invoice Supplier (AP)</h3>
    <div class="muted">Proforma (DP) / Final / PIB / Other</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-soft btn-sm" href="purchases_dashboard.php">← Dashboard</a>
    <a class="btn btn-soft btn-sm" href="purchases_payment_ap.php">Payment</a>
    <a class="btn btn-soft btn-sm" href="../chat/index.php?context=INVOICE:AP_LIST">Open Chat (Invoice)</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-2">Create AP Invoice</div>
    <form method="post" enctype="multipart/form-data" class="row g-2">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="col-md-2">
        <label class="form-label">Type</label>
        <select class="form-select form-select-sm" name="invoice_type" id="invoice_type">
          <option value="PROFORMA" <?=($pref_invoice_type==='PROFORMA'||$pref_invoice_type==='')?'selected':''?>>PROFORMA (DP)</option>
          <option value="FINAL" <?=($pref_invoice_type==='FINAL')?'selected':''?>>FINAL (Pelunasan)</option>
          <option value="PIB" <?=($pref_invoice_type==='PIB')?'selected':''?>>PIB</option>
          <option value="OTHER" <?=($pref_invoice_type==='OTHER')?'selected':''?>>OTHER</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Invoice Number</label>
        <input class="form-control form-control-sm" name="invoice_number" placeholder="nomor invoice">
      </div>
      <div class="col-md-2">
        <label class="form-label">Invoice Date</label>
        <input class="form-control form-control-sm" type="date" name="invoice_date" value="<?=h(date('Y-m-d'))?>" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Due Date</label>
        <input class="form-control form-control-sm" type="date" name="due_date">
      </div>

      <div class="col-md-3">
        <label class="form-label">Link PO <span class="text-danger">*</span></label>
        <div class="form-text">Wajib untuk <b>PROFORMA (DP)</b> / <b>FINAL</b> agar nilai invoice dihitung otomatis dari total PO.</div>
        <select class="form-select form-select-sm" name="po_id" id="po_id">
          <option value="0">-- none --</option>
          <?php foreach($po_list as $po): ?>
            <option value="<?=h($po['id'])?>" <?=($pref_po_id===(int)$po['id'])?'selected':''?>
              data-m="<?=h($po['manufacture_id'])?>"
              data-office="<?=h($po['office_code'])?>"
              data-cur="<?=h($po['currency'])?>"
              data-total="<?=h($po['total_amount'])?>"
            ><?=h($po['po_code'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Manufacture</label>
        <select class="form-select form-select-sm" name="manufacture_id" id="manufacture_id" required>
          <option value="">-- pilih pabrikan --</option>
          <?php foreach($manufactures as $m): ?>
            <option value="<?=h($m['id'])?>"><?=h(($m['manufacture_code']??'')." - ".($m['manufacture_name']??''))?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Office</label>
        <select class="form-select form-select-sm" name="office_code" id="office_code" required>
          <option value="">-- pilih office --</option>
          <?php foreach($offices as $o): ?>
            <option value="<?=h($o['office_code'])?>"><?=h($o['office_name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">Currency</label>
        <select class="form-select form-select-sm" name="currency" id="currency">
          <?php foreach(['IDR','USD','CNY'] as $c): ?><option value="<?=h($c)?>"><?=h($c)?></option><?php endforeach; ?>
        </select>
      </div>


      <div class="col-md-2">
        <label class="form-label">% of PO (optional)</label>
        <input class="form-control form-control-sm text-end" type="number" step="0.01" name="percent_of_po" id="percent_of_po"
               value="<?=h($pref_percent>0 ? $pref_percent : '')?>" placeholder="30">
        <div class="form-text">Isi 30 untuk DP, 70 untuk final. Subtotal akan otomatis dihitung dari total PO.</div>
      </div>

      <div class="col-md-2">
        <label class="form-label">Subtotal</label>
        <input class="form-control form-control-sm text-end" type="number" step="0.01" name="subtotal" id="subtotal" value="0">
      </div>
      <div class="col-md-2">
        <label class="form-label">Tax %</label>
        <input class="form-control form-control-sm text-end" type="number" step="0.01" name="tax_percent" id="tax_percent" value="0">
      </div>
      <div class="col-md-1">
        <label class="form-label">Total</label>
        <input class="form-control form-control-sm text-end" type="text" id="total_preview" value="0" disabled>
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
        <button class="btn btn-primary btn-sm" name="create_ap">Save AP Invoice</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">AP Invoice List</div>
    <div class="table-responsive">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <th>AP Code</th>
            <th>Type</th>
            <th>Manufacture</th>
            <th>Inv No</th>
            <th>Inv Date</th>
            <th>Due</th>
            <th>Status</th>
            <th class="num">Total</th>
            <th class="num">Paid</th>
            <th class="num">Outstanding</th>
            <th>Doc</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><b><?=h($r['ap_code'])?></b></td>
              <td><?=h($r['invoice_type'])?></td>
              <td><?=h($r['manufacture_name'] ?? '')?></td>
              <td><?=h($r['invoice_number'] ?? '')?></td>
              <td><?=h($r['invoice_date'] ?? '')?></td>
              <td><?=h($r['due_date'] ?? '')?></td>
              <td><?=h($r['status'])?></td>
              <td class="num"><?=h(p_money($r['total_amount'], $r['currency']))?></td>
              <td class="num"><?=h(p_money($r['paid_amount'], $r['currency']))?></td>
              <td class="num"><b><?=h(p_money($r['outstanding'], $r['currency']))?></b></td>
              <td>
                <?php if (!empty($r['doc_path'])): ?><a class="btn btn-soft btn-sm" href="../<?=h($r['doc_path'])?>" target="_blank">View</a>
                <?php else: ?><span class="muted">-</span><?php endif; ?>
              </td>
              <td class="d-flex gap-1 align-items-center">
                <?php if ((float)$r['outstanding'] > 0.00001): ?>
                  <a class="btn btn-soft btn-sm" href="purchases_payment_ap.php?ap_id=<?=h($r['id'])?>">Pay</a>
                <?php else: ?>
                  <button class="btn btn-success btn-sm" type="button" disabled>Paid</button>
                <?php endif; ?>

                <?php if ((float)$r['paid_amount'] <= 0.00001): ?>
                  <a class="btn btn-outline-light btn-sm" href="purchases_invoice_ap_edit.php?id=<?=h($r['id'])?>">Edit</a>
                  <form method="post" style="display:inline" onsubmit="return confirm('Void invoice <?=h($r['ap_code'])?> ?');">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="void_ap" value="1">
                    <input type="hidden" name="id" value="<?=h($r['id'])?>">
                    <button class="btn btn-outline-danger btn-sm" type="submit">Void</button>
                  </form>
                <?php else: ?>
                  <button class="btn btn-outline-light btn-sm" type="button" disabled>Edit</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function recalcTotal(){
  const sub = parseFloat(document.getElementById('subtotal').value||'0');
  const taxp = parseFloat(document.getElementById('tax_percent').value||'0');
  const total = sub + (sub*taxp/100);
  document.getElementById('total_preview').value = total.toLocaleString('id-ID',{maximumFractionDigits:2});
}
document.getElementById('subtotal').addEventListener('input',recalcTotal);
document.getElementById('tax_percent').addEventListener('input',recalcTotal);
recalcTotal();

document.getElementById('po_id').addEventListener('change', ()=>{
  const opt = document.getElementById('po_id').options[document.getElementById('po_id').selectedIndex];
  if (!opt) return;
  const mid = opt.getAttribute('data-m');
  const office = opt.getAttribute('data-office');
  const cur = opt.getAttribute('data-cur');
  const total = opt.getAttribute('data-total');
  if (mid) document.getElementById('manufacture_id').value = mid;
  if (office) document.getElementById('office_code').value = office;
  if (cur) document.getElementById('currency').value = cur;
  // Apply default % based on invoice type if percent is empty
  const pctEl = document.getElementById('percent_of_po');
  const it = document.getElementById('invoice_type').value;
  if (pctEl && (!pctEl.value || parseFloat(pctEl.value||'0')<=0)) {
    if (it==='PROFORMA') pctEl.value = '30';
    else if (it==='FINAL') pctEl.value = '70';
  }
  applyPercent();
});

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

function applyPercent(){
  const poSel = document.getElementById('po_id');
  const opt = poSel.options[poSel.selectedIndex];
  const total = parseFloat(opt?.getAttribute('data-total')||'0');
  const pct = parseFloat(document.getElementById('percent_of_po').value||'0');
  if (total>0 && pct>0) {
    document.getElementById('subtotal').value = (total * pct/100).toFixed(2);
  } else if (total>0 && (!document.getElementById('subtotal').value || parseFloat(document.getElementById('subtotal').value||'0')===0)) {
    // default: full PO if subtotal still 0
    document.getElementById('subtotal').value = total.toFixed(2);
  }
  recalcTotal();
}

// initial apply (handles prefilled po_id)
applyPercent();
</script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>
<script>
  new DataTable('#tbl', { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'] });
</script>
<?php rmi_footer(); ?>
