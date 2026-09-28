<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_route_access')) {
    require_route_access(['PURCHASES.CEISA_VIEW', 'PURCHASES.CEISA_PIB']);
} elseif (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.CEISA_VIEW', 'PURCHASES.CEISA_PIB']);
} else {
    require_role(['ACT','FIN','ADMIN','SUPERADMIN','SYS','MANAGER','PQP','SCM','STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
require_once __DIR__ . '/../master/_audit_master.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$flash = p_flash_get();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo "PO ID tidak valid."; exit; }

// Load PO
$po=null;
try {
  $st=$pdo->prepare("
    SELECT po.*, o.office_name, m.manufacture_name
    FROM purchases_po po
    LEFT JOIN master_office o ON o.office_code=po.office_code
    LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
    WHERE po.id=? AND po.deleted_at IS NULL
    LIMIT 1
  ");
  $st->execute([$id]);
  $po=$st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (!$po) { echo "PO tidak ditemukan."; exit; }

$po_code = (string)$po['po_code'];
$office_code = up($po['office_code'] ?? 'OFF');

$role = strtoupper(current_user_role() ?: current_user_level());
$canEdit = in_array($role, ['ACT','ADMIN','SUPERADMIN','SYS'], true);

// TAMBAHAN: izinkan MANAGER ACT edit
if ($role === 'MANAGER' && strpos(strtoupper(p_username()), 'ACT') !== false) {
    $canEdit = true;
}
$canPay  = in_array($role, ['FIN','ADMIN','SUPERADMIN','SYS'], true)
    || ($role === 'MANAGER' && strpos(strtoupper(p_username()), 'FIN') !== false);

function pib_pay_upload_dir($pay_code){
  $dir = __DIR__ . '/uploads/pib_payment/' . $pay_code;
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}

function doc_upload_dir($po_code){
  $dir = __DIR__ . '/../uploads/purchases_forwarding/' . preg_replace('/[^A-Za-z0-9_\-]/','_', $po_code);
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}
function save_doc_file($po_code, $file){
  if (empty($file['tmp_name'])) return null;
  $origName = (string)($file['name'] ?? '');
  $safeName = rmi_safe_filename($origName);
  if ($safeName === '') throw new Exception("Nama file tidak valid.");
  $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
  $allowed = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','zip','rar'];
  if (!in_array($ext, $allowed, true)) return null;
  $dir = doc_upload_dir($po_code);
  $safe = preg_replace('/[^A-Za-z0-9_\.\-]/','_', $safeName);
  $target = $dir . '/' . date('Ymd_His') . '_' . $safe;
  if (@move_uploaded_file($file['tmp_name'], $target)) {
    return 'uploads/purchases_forwarding/' . preg_replace('/[^A-Za-z0-9_\-]/','_', $po_code) . '/' . basename($target);
  }
  return null;
}

function go($id): void {
  rmi_redirect('purchases_ceisa_pib_view.php?id=' . $id);
}


// Actions
if ($_SERVER['REQUEST_METHOD']==='POST') {
  require_post();
  verify_csrf();

  // Ensure CEISA row exists (POST-only)
  try {
    $pdo->prepare("INSERT INTO purchases_ceisa_pib (po_id, updated_by) VALUES (?,?) ON DUPLICATE KEY UPDATE po_id=po_id")
        ->execute([$id, p_username()]);
  } catch (Throwable $e) {}

  try {
    $action = $_POST['action'] ?? '';

    if ($action==='save_ceisa' && $canEdit) {
      $ceisa_status = up($_POST['ceisa_status'] ?? 'DRAFT');
      if (!in_array($ceisa_status,['DRAFT','SUBMITTED','REJECTED','APPROVED'],true)) $ceisa_status='DRAFT';

      $submitted_date = $_POST['submitted_date'] ?: null;
      $reject_count = (int)($_POST['reject_count'] ?? 0);
      $reject_reason = trim((string)($_POST['reject_reason'] ?? ''));

      $bc11_no = trim((string)($_POST['bc11_no'] ?? ''));
      $bc11_date = $_POST['bc11_date'] ?: null;
      $noa_no = trim((string)($_POST['noa_no'] ?? ''));
      $noa_date = $_POST['noa_date'] ?: null;

      $billing_aju_no = trim((string)($_POST['billing_aju_no'] ?? ''));
      $billing_aju_date = $_POST['billing_aju_date'] ?: null;
      $billing_amount = (float)($_POST['billing_amount'] ?? 0);

      $sppb_no = trim((string)($_POST['sppb_no'] ?? ''));
      $sppb_date = $_POST['sppb_date'] ?: null;

      $final_pib_no = trim((string)($_POST['final_pib_no'] ?? ''));
      $final_pib_date = $_POST['final_pib_date'] ?: null;

      $note = trim((string)($_POST['note'] ?? ''));

      $pdo->prepare("UPDATE purchases_ceisa_pib
                     SET ceisa_status=?, submitted_date=?, reject_count=?, reject_reason=?,
                         bc11_no=?, bc11_date=?, noa_no=?, noa_date=?,
                         billing_aju_no=?, billing_aju_date=?, billing_amount=?,
                         sppb_no=?, sppb_date=?, final_pib_no=?, final_pib_date=?,
                         note=?, updated_by=?, updated_at=NOW()
                     WHERE po_id=?")
          ->execute([$ceisa_status,$submitted_date,$reject_count,$reject_reason,
                    ($bc11_no!==''?$bc11_no:null),$bc11_date,($noa_no!==''?$noa_no:null),$noa_date,
                    ($billing_aju_no!==''?$billing_aju_no:null),$billing_aju_date,$billing_amount,
                    ($sppb_no!==''?$sppb_no:null),$sppb_date,($final_pib_no!==''?$final_pib_no:null),$final_pib_date,
                    $note,p_username(),$id]);
      p_audit($pdo,'CEISA',$po_code,'UPDATE',['status'=>$ceisa_status,'billing'=>$billing_amount]);
      if (function_exists('master_audit')) {
        $stPib = $pdo->prepare("SELECT id FROM purchases_ceisa_pib WHERE po_id=? LIMIT 1");
        $stPib->execute([$id]);
        $pib_id = (int)($stPib->fetchColumn() ?: 0);
        master_audit($pdo, 'purchases_ceisa_pib', 'purchases_ceisa_pib', 'UPDATE', $pib_id ?: $id, $po_code, "CEISA/PIB updated: {$po_code}", ['status' => $ceisa_status, 'billing' => $billing_amount]);
      }
      p_flash_set('success','Data CEISA/PIB tersimpan.');
      go($id);
    }

    if ($action==='upload_doc' && $canEdit) {
      $doc_type = up($_POST['doc_type'] ?? 'OTHER');
      $allowedTypes = ['BC11','NOA','BILLING_AJU','SPPB','PIB','OTHER'];
      if (!in_array($doc_type,$allowedTypes,true)) $doc_type='OTHER';

      $doc_number = trim((string)($_POST['doc_number'] ?? ''));
      $doc_date = $_POST['doc_date'] ?: null;
      $note = trim((string)($_POST['doc_note'] ?? ''));

      $path = null;
      if (!empty($_FILES['doc_file']['name'])) {
        $path = save_doc_file($po_code, $_FILES['doc_file']);
        if (!$path) throw new Exception("Upload gagal / tipe file tidak didukung.");
      }

      $pdo->prepare("INSERT INTO purchases_forwarding_docs(po_id, doc_type, doc_number, doc_date, file_path, note, uploaded_by)
                     VALUES (?,?,?,?,?,?,?)")
          ->execute([$id,$doc_type,($doc_number!==''?$doc_number:null),$doc_date,$path,$note,p_username()]);
      $doc_id = (int)$pdo->lastInsertId();
      p_audit($pdo,'CEISA_DOC',$po_code,'UPLOAD',['doc_type'=>$doc_type,'doc_number'=>$doc_number]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'purchases_ceisa_pib', 'purchases_forwarding_docs', 'UPLOAD', $doc_id, $po_code, "CEISA doc uploaded: {$po_code} {$doc_type}", ['doc_type' => $doc_type, 'doc_number' => $doc_number]);
      }
      p_flash_set('success','Dokumen tersimpan.');
      go($id);
    }

    if ($action==='add_pay' && $canPay) {
      $pay_date = $_POST['pay_date'] ?? date('Y-m-d');
      $amount = (float)($_POST['amount'] ?? 0);
      $method = trim((string)($_POST['method'] ?? 'TRANSFER'));
      $bank_name = trim((string)($_POST['bank_name'] ?? ''));
      $reference = trim((string)($_POST['reference'] ?? ''));
      $note = trim((string)($_POST['pay_note'] ?? ''));

      if ($amount<=0) throw new Exception("Amount wajib > 0.");

      // get pib_id + billing amount for overpayment check
      $st=$pdo->prepare("SELECT id, billing_amount FROM purchases_ceisa_pib WHERE po_id=? LIMIT 1");
      $st->execute([$id]);
      $pibCheck = $st->fetch(PDO::FETCH_ASSOC);
      $pib_id = (int)($pibCheck['id'] ?? 0);
      if ($pib_id<=0) throw new Exception("PIB record belum ada.");
      $billingAmt = (float)($pibCheck['billing_amount'] ?? 0);
      if ($billingAmt <= 0.001) throw new Exception("Billing amount masih 0. Isi billing amount di form CEISA terlebih dahulu.");

      $stPaidSum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM purchases_ceisa_payment WHERE pib_id=? AND deleted_at IS NULL");
      $stPaidSum->execute([$pib_id]);
      $alreadyPaid = (float)$stPaidSum->fetchColumn();
      $remainingOS = $billingAmt - $alreadyPaid;
      if ($amount > $remainingOS + 0.01) {
        throw new Exception("Amount (".number_format($amount,2).") melebihi outstanding (".number_format($remainingOS,2).").");
      }

      $dateYmd = date('ymd', strtotime($pay_date));
      if (!function_exists('doc_prefix_pib_pay')) require_once __DIR__ . '/../config/doc_numbering.php';
      $prefix = doc_prefix_pib_pay($office_code, $dateYmd);
      $pay_code = p_generate_code($pdo,'purchases_ceisa_payment','pay_code',$prefix);

      $doc_path = null;
      if (!empty($_FILES['pay_doc']['tmp_name'])) {
        $dir=pib_pay_upload_dir($pay_code);
        $origName = (string)($_FILES['pay_doc']['name'] ?? '');
        $safeName = rmi_safe_filename($origName);
        if ($safeName === '') throw new Exception("Nama dokumen tidak valid.");
        $ext=strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if (!in_array($ext,['pdf','jpg','jpeg','png'],true)) throw new Exception("Dokumen hanya PDF/JPG/PNG.");
        $target=$dir.'/proof.'.$ext;
        if (move_uploaded_file($_FILES['pay_doc']['tmp_name'],$target)) $doc_path='purchases/uploads/pib_payment/'.$pay_code.'/proof.'.$ext;
      }

      $pdo->prepare("INSERT INTO purchases_ceisa_payment (pay_code, pib_id, pay_date, amount, method, bank_name, reference, note, doc_path, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?)")
          ->execute([$pay_code,$pib_id,$pay_date,$amount,$method,$bank_name,$reference,$note,$doc_path,p_username()]);
      $pay_id = (int)$pdo->lastInsertId();
      p_audit($pdo,'PIB_PAY',$po_code,'CREATE',['pay_code'=>$pay_code,'amount'=>$amount]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'purchases_ceisa_pib', 'purchases_ceisa_payment', 'CREATE', $pay_id, $pay_code, "PIB payment created: {$pay_code}", ['amount' => $amount]);
      }
      p_flash_set('success',"Payment PIB tersimpan: {$pay_code}");
      go($id);
    }

  } catch (Throwable $e) {
    p_flash_set('danger',$e->getMessage());
    go($id);
  }
}

// Load pib record
$pib=[];
try {
  $st=$pdo->prepare("SELECT * FROM purchases_ceisa_pib WHERE po_id=? LIMIT 1");
  $st->execute([$id]);
  $pib=$st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $pib=[]; }

// Payments
$pays=[];
try {
  $st=$pdo->prepare("SELECT * FROM purchases_ceisa_payment WHERE pib_id=? AND deleted_at IS NULL ORDER BY id DESC");
  $st->execute([(int)($pib['id'] ?? 0)]);
  $pays=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $pays=[]; }

$paid_total=0;
foreach($pays as $p){ $paid_total += (float)($p['amount'] ?? 0); }
$billing_amount = (float)($pib['billing_amount'] ?? 0);
$outstanding = $billing_amount - $paid_total;

// Related docs (only customs types)
$docs=[];
try {
  $st=$pdo->prepare("SELECT * FROM purchases_forwarding_docs
                     WHERE po_id=? AND deleted_at IS NULL AND doc_type IN ('BC11','NOA','BILLING_AJU','SPPB','PIB','OTHER')
                     ORDER BY uploaded_at DESC,id DESC");
  $st->execute([$id]);
  $docs=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $docs=[]; }

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $payCodes = array_column($pays, 'pay_code');
  $sql = "SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'purchases_ceisa_pib' AND (record_code = ?";
  $params = [$po_code];
  if (!empty($payCodes)) {
    $sql .= " OR record_code IN (" . implode(',', array_fill(0, count($payCodes), '?')) . ")";
    $params = array_merge($params, $payCodes);
  }
  $sql .= ") ORDER BY created_at DESC LIMIT 50";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Purchases Ceisa Pib View', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Purchases Ceisa Pib View',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{background:radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
    .muted{color:#9ca3af;font-size:12px}
    .form-control,.form-select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.14)!important}
    .btn-soft{border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e5e7eb}
    .btn-soft:hover{background:rgba(255,255,255,.10);color:#fff}
    .num{text-align:right}
    .badge-soft{border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);border-radius:999px;padding:3px 8px;font-size:12px}</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted">CEISA / PIB</div>
    <h3 class="mb-0"><?=h($po_code)?> <span class="badge-soft"><?=h($po['status'])?></span></h3>
    <div class="muted">Office: <?=h($po['office_name'] ?? $po['office_code'])?> • Manufacture: <?=h($po['manufacture_name'] ?? '-')?></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-soft btn-sm" href="purchases_ceisa_pib.php">← List</a>
    <a class="btn btn-soft btn-sm" href="purchases_import_control_view.php?id=<?=h($id)?>">Control Detail</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-body">
        <div class="fw-semibold mb-2">Status & Dokumen CEISA</div>

        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_ceisa">
          <div class="col-md-4">
            <label class="form-label muted">CEISA Status</label>
            <select class="form-select form-select-sm" name="ceisa_status" <?= $canEdit?'':'disabled' ?>>
              <?php foreach(['DRAFT','SUBMITTED','REJECTED','APPROVED'] as $s): ?>
                <option value="<?=$s?>" <?= (up($pib['ceisa_status'] ?? 'DRAFT')===$s)?'selected':'' ?>><?=$s?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label muted">Submitted Date</label>
            <input class="form-control form-control-sm" type="date" name="submitted_date" value="<?=h($pib['submitted_date'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-4">
            <label class="form-label muted">Reject Count</label>
            <input class="form-control form-control-sm" type="number" name="reject_count" value="<?=h($pib['reject_count'] ?? 0)?>" <?= $canEdit?'':'disabled' ?>>
          </div>

          <div class="col-12">
            <label class="form-label muted">Reject Reason (if any)</label>
            <textarea class="form-control form-control-sm" name="reject_reason" rows="2" <?= $canEdit?'':'disabled' ?>><?=h($pib['reject_reason'] ?? '')?></textarea>
          </div>

          <div class="col-md-6">
            <label class="form-label muted">BC 1.1 No</label>
            <input class="form-control form-control-sm" name="bc11_no" value="<?=h($pib['bc11_no'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">BC 1.1 Date</label>
            <input class="form-control form-control-sm" type="date" name="bc11_date" value="<?=h($pib['bc11_date'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">NOA No</label>
            <input class="form-control form-control-sm" name="noa_no" value="<?=h($pib['noa_no'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">NOA Date</label>
            <input class="form-control form-control-sm" type="date" name="noa_date" value="<?=h($pib['noa_date'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>

          <div class="col-md-6">
            <label class="form-label muted">ID Billing Aju</label>
            <input class="form-control form-control-sm" name="billing_aju_no" value="<?=h($pib['billing_aju_no'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-3">
            <label class="form-label muted">Billing Date</label>
            <input class="form-control form-control-sm" type="date" name="billing_aju_date" value="<?=h($pib['billing_aju_date'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-3">
            <label class="form-label muted">Billing Amount (IDR)</label>
            <input class="form-control form-control-sm text-end" type="number" step="0.01" name="billing_amount" value="<?=h($pib['billing_amount'] ?? 0)?>" <?= $canEdit?'':'disabled' ?>>
          </div>

          <div class="col-md-6">
            <label class="form-label muted">SPPB No</label>
            <input class="form-control form-control-sm" name="sppb_no" value="<?=h($pib['sppb_no'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">SPPB Date</label>
            <input class="form-control form-control-sm" type="date" name="sppb_date" value="<?=h($pib['sppb_date'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>

          <div class="col-md-6">
            <label class="form-label muted">Final PIB No</label>
            <input class="form-control form-control-sm" name="final_pib_no" value="<?=h($pib['final_pib_no'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">Final PIB Date</label>
            <input class="form-control form-control-sm" type="date" name="final_pib_date" value="<?=h($pib['final_pib_date'] ?? '')?>" <?= $canEdit?'':'disabled' ?>>
          </div>

          <div class="col-12">
            <label class="form-label muted">Note</label>
            <textarea class="form-control form-control-sm" name="note" rows="2" <?= $canEdit?'':'disabled' ?>><?=h($pib['note'] ?? '')?></textarea>
          </div>

          <div class="col-12">
            <?php if ($canEdit): ?><button class="btn btn-primary btn-sm">Save</button>
            <?php else: ?><div class="muted">Read-only</div><?php endif; ?>
          </div>
        </form>

        <hr class="border-secondary">

        <div class="fw-semibold mb-2">Upload Dokumen (BC11/NOA/Billing/SPPB/PIB)</div>
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="upload_doc">
          <div class="col-md-4">
            <label class="form-label muted">File</label>
            <input class="form-control form-control-sm" type="file" name="doc_file" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-3">
            <label class="form-label muted">Type</label>
            <select class="form-select form-select-sm" name="doc_type" <?= $canEdit?'':'disabled' ?>>
              <?php foreach(['BC11','NOA','BILLING_AJU','SPPB','PIB','OTHER'] as $t): ?>
                <option value="<?=$t?>"><?=$t?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label muted">Doc No</label>
            <input class="form-control form-control-sm" name="doc_number" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-3">
            <label class="form-label muted">Doc Date</label>
            <input class="form-control form-control-sm" type="date" name="doc_date" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label muted">Note</label>
            <input class="form-control form-control-sm" name="doc_note" <?= $canEdit?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <?php if ($canEdit): ?><button class="btn btn-primary btn-sm">Upload</button>
            <?php else: ?><div class="muted">Read-only</div><?php endif; ?>
          </div>
        </form>

        <div class="table-responsive mt-2">
          <table class="table table-sm table-dark align-middle">
            <thead><tr><th>Type</th><th>No</th><th>Date</th><th>Note</th><th>File</th><th>By</th></tr></thead>
            <tbody>
              <?php foreach($docs as $d): ?>
                <tr>
                  <td><span class="badge-soft"><?=h($d['doc_type'])?></span></td>
                  <td><?=h($d['doc_number'] ?? '—')?></td>
                  <td><?=h($d['doc_date'] ?? '—')?></td>
                  <td class="muted"><?=h($d['note'] ?? '')?></td>
                  <td>
                    <?php if (!empty($d['file_path'])): ?>
                      <a class="btn btn-soft btn-sm" href="../<?=h($d['file_path'])?>" target="_blank">Open</a>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                  </td>
                  <td class="muted"><?=h($d['uploaded_by'] ?? '')?><div class="muted"><?=h($d['uploaded_at'] ?? '')?></div></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$docs): ?><tr><td colspan="6" class="muted">Belum ada dokumen.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">Payment PIB (FIN)</div>

        <div class="d-flex justify-content-between">
          <div class="muted">Billing Amount</div>
          <div><b><?=h(p_money($billing_amount,'IDR'))?></b></div>
        </div>
        <div class="d-flex justify-content-between">
          <div class="muted">Paid</div>
          <div><b><?=h(p_money($paid_total,'IDR'))?></b></div>
        </div>
        <div class="d-flex justify-content-between mb-3">
          <div class="muted">Outstanding</div>
          <div><b><?=h(p_money($outstanding,'IDR'))?></b></div>
        </div>

        <form method="post" enctype="multipart/form-data" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="add_pay">
          <div class="col-md-6">
            <label class="form-label muted">Pay Date</label>
            <input class="form-control form-control-sm" type="date" name="pay_date" value="<?=h(date('Y-m-d'))?>" <?= $canPay?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">Amount</label>
            <input class="form-control form-control-sm text-end" type="number" step="0.01" name="amount" <?= $canPay?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">Method</label>
            <select class="form-select form-select-sm" name="method" <?= $canPay?'':'disabled' ?>>
              <option>TRANSFER</option><option>CASH</option><option>GIRO</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">Bank</label>
            <input class="form-control form-control-sm" name="bank_name" <?= $canPay?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label muted">Reference</label>
            <input class="form-control form-control-sm" name="reference" <?= $canPay?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label muted">Proof Doc (PDF/JPG/PNG)</label>
            <input class="form-control form-control-sm" type="file" name="pay_doc" accept=".pdf,.jpg,.jpeg,.png" <?= $canPay?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label muted">Note</label>
            <input class="form-control form-control-sm" name="pay_note" <?= $canPay?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <?php if ($canPay): ?><button class="btn btn-primary btn-sm">Add Payment</button>
            <?php else: ?><div class="muted">Read-only</div><?php endif; ?>
          </div>
        </form>

        <hr class="border-secondary">

        <div class="fw-semibold mb-2">Payment History</div>
        <div class="table-responsive">
          <table class="table table-sm table-dark align-middle">
            <thead><tr><th>Pay Code</th><th>Date</th><th class="num">Amount</th><th>Method</th><th>Ref</th><th>Doc</th></tr></thead>
            <tbody>
              <?php foreach($pays as $x): ?>
                <tr>
                  <td><b><?=h($x['pay_code'])?></b></td>
                  <td><?=h($x['pay_date'])?></td>
                  <td class="num"><?=h(p_money($x['amount'],'IDR'))?></td>
                  <td><?=h($x['method'] ?? '')?></td>
                  <td class="muted"><?=h($x['reference'] ?? '')?></td>
                  <td>
                    <?php if (!empty($x['doc_path'])): ?>
                      <a class="btn btn-soft btn-sm" href="../<?=h($x['doc_path'])?>" target="_blank">Open</a>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$pays): ?><tr><td colspan="6" class="muted">Belum ada payment.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
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
  </div>
</div>
<?php rmi_footer(); ?>
