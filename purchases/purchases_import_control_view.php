<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_route_access')) {
    require_route_access(['PURCHASES.IMPORT_CONTROL']);
} elseif (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.IMPORT_CONTROL']);
} else {
    require_role(['ADMIN','SUPERADMIN','SYS','MANAGER','PQP','FIN','SCM','ACT','WQS','STAFF']);
}
require_once __DIR__ . '/../_shared/rmi_branch_guard.php';
rmi_block_branch('Detail Import Control hanya untuk SCM/PQP/FIN atau Admin.');

require_once __DIR__ . '/_purchases_lib.php';
require_once __DIR__ . '/../master/_audit_master.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$flash = p_flash_get();

$raw_id = $_GET['id'] ?? '';
$raw_po_code = trim((string)($_GET['po_code'] ?? ''));

// id bisa berupa angka (po_id) atau po_code (RMI-PO-...)
$id = (int)$raw_id;
if ($id <= 0) {
  $lookup = $raw_po_code !== '' ? $raw_po_code : trim((string)$raw_id);
  if ($lookup !== '') {
    try {
      $stL = $pdo->prepare("SELECT id FROM purchases_po WHERE po_code=? AND deleted_at IS NULL LIMIT 1");
      $stL->execute([$lookup]);
      $id = (int)($stL->fetchColumn() ?: 0);
    } catch (Throwable $e) { $id = 0; }
  }
}

if ($id <= 0) { echo "PO ID tidak valid. Gunakan ?id=angka atau ?po_code=RMI-PO-..."; exit; }

// Load PO
$po = null;
try {
  $st = $pdo->prepare("
    SELECT po.*, m.manufacture_name, o.office_name, v.vendors_name AS forwarder_name
    FROM purchases_po po
    LEFT JOIN master_manufactures m ON m.id = po.manufacture_id
    LEFT JOIN master_office o ON o.office_code = po.office_code
    LEFT JOIN master_vendors v ON v.id = po.forwarder_vendor_id
    WHERE po.id=? AND po.deleted_at IS NULL
    LIMIT 1
  ");
  $st->execute([$id]);
  $po = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (!$po) { echo "PO tidak ditemukan."; exit; }

$po_code = (string)$po['po_code'];


// Helpers & permissions
$canProd = p_can_create_po() || p_is_admin_plus();      // PQP/Admin
$canShip = p_can_scm_ops() || p_is_admin_plus();        // SCM/Admin
$canDoc  = in_array(strtoupper(current_user_role() ?: current_user_level()), ['PQP','SCM','ACT','ADMIN','SUPERADMIN','SYS','MANAGER'], true);
$canDeleteDoc = p_is_admin_plus();

function ic_upload_dir($po_code){
  $dir = __DIR__ . '/../uploads/purchases_forwarding/' . preg_replace('/[^A-Za-z0-9_\-]/','_', $po_code);
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}
function ic_save_upload($po_code, $file){
  if (empty($file['tmp_name'])) return null;
  $origName = (string)($file['name'] ?? '');
  $safeName = rmi_safe_filename($origName);
  if ($safeName === '') return null;
  $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
  $allowed = ['pdf','jpg','jpeg','png','webp','mp4','mov','avi','mkv','doc','docx','xls','xlsx','zip','rar'];
  if (!in_array($ext, $allowed, true)) return null;

  $dir = ic_upload_dir($po_code);
  $target = $dir . '/' . date('Ymd_His') . '_' . $safeName;
  if (@move_uploaded_file($file['tmp_name'], $target)) {
    // Return path relative to project root
    $rel = 'uploads/purchases_forwarding/' . preg_replace('/[^A-Za-z0-9_\-]/','_', $po_code) . '/' . basename($target);
    return $rel;
  }
  return null;
}

function go($id): void {
  rmi_redirect('purchases_import_control_view.php?id=' . $id);
}

// --- Actions ---
if ($_SERVER['REQUEST_METHOD']==='POST') {
  require_post();
  verify_csrf();

  // Ensure import_control row exists (POST-only)
  try {
    $pdo->prepare("INSERT INTO purchases_import_control (po_id, updated_by) VALUES (?,?) ON DUPLICATE KEY UPDATE po_id=po_id")
        ->execute([$id, p_username()]);
  } catch (Throwable $e) {}

  try {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_prod' && $canProd) {
      $ps = $_POST['production_start_date'] ?: null;
      $pd = $_POST['production_done_date'] ?: null;
      $note = trim((string)($_POST['note_prod'] ?? ''));
      $pdo->prepare("UPDATE purchases_import_control
                     SET production_start_date=?, production_done_date=?, note_prod=?, updated_by=?, updated_at=NOW()
                     WHERE po_id=?")
          ->execute([$ps,$pd,$note,p_username(),$id]);
      p_audit($pdo,'IMPORT_CTRL',$po_code,'UPDATE_PRODUCTION',['start'=>$ps,'done'=>$pd]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'purchases_import_control', 'purchases_import_control', 'UPDATE_PRODUCTION', $id, $po_code, "Import control production updated: {$po_code}", ['start' => $ps, 'done' => $pd]);
      }
      p_flash_set('success','Production milestone tersimpan.');
      go($id);
    }

    if ($action === 'save_ship' && $canShip) {
      $pickup = $_POST['pickup_date'] ?: null;
      $etd = $_POST['etd'] ?: null;
      $eta = $_POST['eta'] ?: null;
      $arr_id = $_POST['arrived_id_date'] ?: null;
      $arr_wh = $_POST['arrived_warehouse_date'] ?: null;
      $note = trim((string)($_POST['note_ship'] ?? ''));
      $pdo->prepare("UPDATE purchases_import_control
                     SET pickup_date=?, etd=?, eta=?, arrived_id_date=?, arrived_warehouse_date=?, note_ship=?, updated_by=?, updated_at=NOW()
                     WHERE po_id=?")
          ->execute([$pickup,$etd,$eta,$arr_id,$arr_wh,$note,p_username(),$id]);
      p_audit($pdo,'IMPORT_CTRL',$po_code,'UPDATE_SHIPPING',['pickup'=>$pickup,'etd'=>$etd,'eta'=>$eta,'arrived_wh'=>$arr_wh]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'purchases_import_control', 'purchases_import_control', 'UPDATE_SHIPPING', $id, $po_code, "Import control shipping updated: {$po_code}", ['pickup' => $pickup, 'etd' => $etd, 'eta' => $eta]);
      }
      p_flash_set('success','Shipping milestone tersimpan.');
      go($id);
    }
if ($action === 'upload_prod_media' && $canProd) {
  $note = trim((string)($_POST['prod_media_note'] ?? ''));

  if (empty($_FILES['prod_media_file']['name'])) {
    throw new Exception("File foto/video wajib dipilih.");
  }

  $path = ic_save_upload($po_code, $_FILES['prod_media_file']);
  if (!$path) throw new Exception("Upload gagal / tipe file foto-video tidak didukung.");

  $pdo->prepare("INSERT INTO purchases_forwarding_docs
    (po_id, doc_type, doc_number, doc_date, file_path, note, uploaded_by)
    VALUES (?,?,?,?,?,?,?)")
    ->execute([$id, 'PROD_MEDIA', null, date('Y-m-d'), $path, $note, p_username()]);

  p_flash_set('success','Foto/video production tersimpan.');
  go($id);
}
    if ($action === 'upload_doc' && $canDoc) {
      $doc_type = strtoupper(trim((string)($_POST['doc_type'] ?? 'OTHER')));
      $allowedTypes = ['CIPL','BL_DRAFT','BL_FINAL','FORM_E','BC11','NOA','BILLING_AJU','SPPB','PIB','NIE_AKL','HS_CODE','OTHER'];
      if (!in_array($doc_type, $allowedTypes, true)) $doc_type = 'OTHER';

      $doc_number = trim((string)($_POST['doc_number'] ?? ''));
      $doc_date = $_POST['doc_date'] ?: null;
      $note = trim((string)($_POST['doc_note'] ?? ''));

      $path = null;
      if (!empty($_FILES['doc_file']['name'])) {
        $path = ic_save_upload($po_code, $_FILES['doc_file']);
        if (!$path) throw new Exception("Upload gagal / tipe file tidak didukung.");
      }

      $pdo->prepare("INSERT INTO purchases_forwarding_docs(po_id, doc_type, doc_number, doc_date, file_path, note, uploaded_by)
                     VALUES (?,?,?,?,?,?,?)")
          ->execute([$id,$doc_type,($doc_number!==''?$doc_number:null),$doc_date,$path,$note,p_username()]);
      $doc_id = (int)$pdo->lastInsertId();
      p_audit($pdo,'IMPORT_DOC',$po_code,'UPLOAD',['doc_type'=>$doc_type,'doc_number'=>$doc_number]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'purchases_import_control', 'purchases_forwarding_docs', 'UPLOAD', $doc_id, $po_code, "Import doc uploaded: {$po_code} {$doc_type}", ['doc_type' => $doc_type, 'doc_number' => $doc_number]);
      }
      p_flash_set('success','Dokumen tersimpan.');
      go($id);
    }

    if ($action === 'delete_doc' && $canDeleteDoc) {
      $doc_id = (int)($_POST['doc_id'] ?? 0);
      if ($doc_id<=0) throw new Exception("Doc ID invalid.");
      $pdo->prepare("UPDATE purchases_forwarding_docs SET deleted_at=NOW() WHERE id=?")->execute([$doc_id]);
      p_audit($pdo,'IMPORT_DOC',$po_code,'DELETE',['doc_id'=>$doc_id]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'purchases_import_control', 'purchases_forwarding_docs', 'DELETE', $doc_id, $po_code, "Import doc deleted: {$po_code}", ['doc_id' => $doc_id]);
      }
      p_flash_set('success','Doc dihapus (soft delete).');
      go($id);
    }

    // Sync Landed Cost ke Master Pricelist (Buy Price) — hanya ADMIN, SUPERADMIN, FIN, PQP
    if ($action === 'sync_to_pricelist') {
      $syncRole = strtoupper(current_user_role() ?: current_user_level() ?: '');
      if (!in_array($syncRole, ['ADMIN','SUPERADMIN','FIN','PQP'], true)) {
        throw new Exception("Akses ditolak. Hanya ADMIN, SUPERADMIN, FIN, PQP.");
      }
      $stPo = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? AND deleted_at IS NULL LIMIT 1");
      $stPo->execute([$id]);
      $poRow = $stPo->fetch(PDO::FETCH_ASSOC);
      if (!$poRow) throw new Exception("PO tidak ditemukan.");
      $poTotal = (float)($poRow['total_amount'] ?? 0);
      $poCurrency = $poRow['currency'] ?? 'IDR';
      $officeCode = $poRow['office_code'] ? strtoupper(trim($poRow['office_code'])) : null;
      if ($officeCode === '') $officeCode = null;

      $fwdSum = 0.0;
      $stF = $pdo->prepare("SELECT total_amount FROM purchases_forwarder_invoice WHERE po_id=? AND deleted_at IS NULL");
      $stF->execute([$id]);
      while ($r = $stF->fetch(PDO::FETCH_ASSOC)) { $fwdSum += (float)($r['total_amount'] ?? 0); }

      $ceisaSum = 0.0;
      $stP = $pdo->prepare("SELECT billing_amount FROM purchases_ceisa_pib WHERE po_id=? LIMIT 1");
      $stP->execute([$id]);
      $pibRow = $stP->fetch(PDO::FETCH_ASSOC);
      if ($pibRow) $ceisaSum = (float)($pibRow['billing_amount'] ?? 0);

      $additionalCost = $fwdSum + $ceisaSum;

      $stItems = $pdo->prepare("SELECT id, sku, qty, unit_price, subtotal FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL ORDER BY line_no");
      $stItems->execute([$id]);
      $items = $stItems->fetchAll(PDO::FETCH_ASSOC);
      if (!$items) throw new Exception("Tidak ada item PO.");

      $today = date('Y-m-d');
      $updated = 0;
      foreach ($items as $it) {
        $qty = (float)($it['qty'] ?? 0);
        if ($qty <= 0) continue;
        $subtotal = (float)($it['subtotal'] ?? 0);
        $ratio = ($poTotal > 0.00001) ? ($subtotal / $poTotal) : 0;
        $itemAdditional = $ratio * $additionalCost;
        $landedPerUnit = ($subtotal + $itemAdditional) / $qty;
        $sku = strtoupper(trim($it['sku'] ?? ''));
        if ($sku === '') continue;

        $buyPrice = round($landedPerUnit, 2);

        $stFind = $pdo->prepare("SELECT id, markup_percent FROM master_pricelist WHERE deleted_at IS NULL AND sku=? AND (office_code<=>?) AND (customers_code IS NULL OR customers_code='') ORDER BY id DESC LIMIT 1");
        $stFind->execute([$sku, $officeCode]);
        $existing = $stFind->fetch(PDO::FETCH_ASSOC);
        $markupPct = $existing ? (float)($existing['markup_percent'] ?? 0) : 0;
        $newSell = ($markupPct > 0) ? round($buyPrice * (1 + $markupPct / 100), 2) : $buyPrice;
        if ($existing) {
          $pdo->prepare("UPDATE master_pricelist SET buy_price=?, sell_price=?, updated_at=NOW() WHERE id=?")
              ->execute([$buyPrice, $newSell, (int)$existing['id']]);
        } else {
          $pdo->prepare("INSERT INTO master_pricelist (sku, office_code, customers_code, buy_price, markup_percent, sell_price, currency, valid_from, valid_to, status, notes) VALUES (?,?,NULL,?,?,?,?,?,?,1,?)")
              ->execute([$sku, $officeCode, $buyPrice, $markupPct, $newSell, $poCurrency, $today, '2099-12-31', "Landed cost dari PO {$po_code}"]);
        }
        $updated++;
      }
      p_audit($pdo,'LANDED_COST',$po_code,'SYNC_PRICELIST',['items'=>$updated,'po_total'=>$poTotal,'fwd'=>$fwdSum,'ceisa'=>$ceisaSum]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'purchases_import_control', 'master_pricelist', 'SYNC_PRICELIST', $id, $po_code, "Landed cost sync to pricelist: {$po_code} {$updated} SKU", ['items' => $updated, 'po_total' => $poTotal]);
      }
      p_flash_set('success', "Landed cost disinkronkan ke Master Pricelist: {$updated} SKU.");
      go($id);
    }

  } catch (Throwable $e) {
    p_flash_set('danger', $e->getMessage());
    go($id);
  }
}

// Load import control
$ic = [];
try {
  $st = $pdo->prepare("SELECT * FROM purchases_import_control WHERE po_id=? LIMIT 1");
  $st->execute([$id]);
  $ic = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $ic=[]; }

// Docs list
$docs=[];
try {
  $st=$pdo->prepare("SELECT * FROM purchases_forwarding_docs WHERE po_id=? AND deleted_at IS NULL ORDER BY uploaded_at DESC,id DESC");
  $st->execute([$id]);
  $docs=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$prodMedia = array_values(array_filter($docs, function($d){
  return strtoupper((string)($d['doc_type'] ?? '')) === 'PROD_MEDIA';
}));

// AP invoice summary (DP/FINAL)
$ap=[];
try {
  $ap = $pdo->prepare("
    SELECT ap.*,
      COALESCE(paid.paid_amount,0) AS paid_amount,
      (ap.total_amount - COALESCE(paid.paid_amount,0)) AS outstanding
    FROM purchases_invoice_ap ap
    LEFT JOIN (
      SELECT ap_id, SUM(amount) paid_amount
      FROM purchases_payment_ap
      WHERE deleted_at IS NULL
      GROUP BY ap_id
    ) paid ON paid.ap_id = ap.id
    WHERE ap.deleted_at IS NULL AND ap.po_id=?
    ORDER BY ap.id DESC
  ");
  $ap->execute([$id]);
  $ap = $ap->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $ap=[]; }

// Forwarder invoice summary
$faps=[];
try {
  $st = $pdo->prepare("
    SELECT f.*, v.vendors_name,
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
    WHERE f.deleted_at IS NULL AND f.po_id=?
    ORDER BY f.id DESC
  ");
  $st->execute([$id]);
  $faps = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// CEISA/PIB summary
$pib = null;
$pibPays = [];
try {
  $st=$pdo->prepare("SELECT * FROM purchases_ceisa_pib WHERE po_id=? LIMIT 1");
  $st->execute([$id]);
  $pib=$st->fetch(PDO::FETCH_ASSOC);
  if ($pib) {
    $st2=$pdo->prepare("SELECT * FROM purchases_ceisa_payment WHERE pib_id=? AND deleted_at IS NULL ORDER BY id DESC");
    $st2->execute([(int)$pib['id']]);
    $pibPays=$st2->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {}

// WQS incoming
$incoming=[];
try {
  $st=$pdo->prepare("SELECT * FROM wqs_incoming WHERE po_code=? ORDER BY id DESC LIMIT 20");
  $st->execute([$po_code]);
  $incoming=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Total Landed Cost (ADMIN, SUPERADMIN, FIN, PQP only)
$canViewLandCost = false;
$role = strtoupper(current_user_role() ?: current_user_level() ?: '');
if (in_array($role, ['ADMIN','SUPERADMIN','FIN','PQP'], true)) {
  $canViewLandCost = true;
}
$po_total = (float)($po['total_amount'] ?? 0);
$fwd_total = 0.0;
foreach ($faps as $f) { $fwd_total += (float)($f['total_amount'] ?? 0); }
$ceisa_total = (float)($pib['billing_amount'] ?? 0);
$total_landed = $po_total + $fwd_total + $ceisa_total;
$po_currency = $po['currency'] ?? 'IDR';
$po_items = [];
try {
  $st = $pdo->prepare("SELECT * FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL ORDER BY line_no ASC");
  $st->execute([$id]);
  $po_items = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

function sum_paid($rows){ $s=0; foreach($rows as $r){ $s += (float)($r['amount'] ?? 0); } return $s; }

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'purchases_import_control' AND record_code = ? ORDER BY created_at DESC LIMIT 50");
  $st->execute([$po_code]);
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Purchases Import Control View', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Purchases Import Control View',
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
    <div class="muted">Import Control Tower</div>
    <h3 class="mb-0"><?=h($po_code)?> <span class="badge-soft"><?=h($po['status'])?></span></h3>
    <div class="muted">
      Office: <?=h($po['office_name'] ?? $po['office_code'])?> • Manufacture: <?=h($po['manufacture_name'] ?? '-')?> • Forwarder: <?=h($po['forwarder_name'] ?? '-')?>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-soft btn-sm" href="purchases_import_control_tower.php">← Control Tower</a>
    <a class="btn btn-soft btn-sm" href="purchases_po_view.php?id=<?=h($id)?>">PO View</a>
    <a class="btn btn-soft btn-sm" href="purchases_forwarder_quotes.php?po_id=<?=h($id)?>">Forwarder Quotes</a>
    <a class="btn btn-soft btn-sm" href="purchases_ceisa_pib_view.php?id=<?=h($id)?>">CEISA/PIB</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-body">
        <div class="fw-semibold mb-2">Production Milestone (PQP)</div>
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_prod">
          <div class="col-md-6">
            <label class="form-label muted">Start</label>
            <input class="form-control form-control-sm" type="date" name="production_start_date" value="<?=h($ic['production_start_date'] ?? '')?>" <?= $canProd?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">Done</label>
            <input class="form-control form-control-sm" type="date" name="production_done_date" value="<?=h($ic['production_done_date'] ?? '')?>" <?= $canProd?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label muted">Note</label>
            <textarea class="form-control form-control-sm" name="note_prod" rows="2" <?= $canProd?'':'disabled' ?>><?=h($ic['note_prod'] ?? $ic['note'] ?? '')?></textarea>
          </div>
          <div class="col-12">
            <?php if ($canProd): ?><button class="btn btn-primary btn-sm">Save</button>
            <?php else: ?><div class="muted">Read-only</div><?php endif; ?>
          </div>
        </form>
        <hr class="border-secondary">
<div class="fw-semibold mb-2">Upload Foto / Video Production</div>

<form method="post" enctype="multipart/form-data" class="row g-2">
  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="upload_prod_media">

  <div class="col-12">
    <label class="form-label muted">Foto / Video</label>
    <input class="form-control form-control-sm" type="file" name="prod_media_file" accept="image/*,video/*" <?= $canProd?'':'disabled' ?>>
  </div>

  <div class="col-12">
    <label class="form-label muted">Note</label>
    <input class="form-control form-control-sm" name="prod_media_note" placeholder="catatan foto/video..." <?= $canProd?'':'disabled' ?>>
  </div>

  <div class="col-12">
    <?php if ($canProd): ?><button class="btn btn-primary btn-sm">Upload Media</button><?php endif; ?>
  </div>
</form>

<div class="table-responsive mt-3">
  <table class="table table-sm table-dark align-middle">
    <thead>
      <tr>
        <th>Preview</th>
        <th>Note</th>
        <th>By</th>
        <th>File</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($prodMedia as $m): ?>
        <?php
          $fp = (string)($m['file_path'] ?? '');
          $ext = strtolower(pathinfo($fp, PATHINFO_EXTENSION));
          $url = '../' . $fp;
        ?>
        <tr>
          <td>
            <?php if (in_array($ext, ['jpg','jpeg','png','webp'], true)): ?>
              <img src="<?=h($url)?>" style="max-width:120px;max-height:90px;border-radius:8px;">
            <?php elseif (in_array($ext, ['mp4','mov','avi','mkv'], true)): ?>
              <video src="<?=h($url)?>" controls style="max-width:180px;max-height:120px;"></video>
            <?php else: ?>
              <span class="muted">File</span>
            <?php endif; ?>
          </td>
          <td><?=h($m['note'] ?? '')?></td>
          <td class="muted"><?=h($m['uploaded_by'] ?? '')?><br><?=h($m['uploaded_at'] ?? '')?></td>
          <td>
            <a class="btn btn-soft btn-sm" href="<?=h($url)?>" target="_blank">View</a>
            <a class="btn btn-soft btn-sm" href="<?=h($url)?>" download>Download</a>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$prodMedia): ?>
        <tr><td colspan="4" class="muted">Belum ada foto/video production.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">Shipping & Arrival (SCM)</div>
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_ship">
          <div class="col-md-6">
            <label class="form-label muted">Pickup</label>
            <input class="form-control form-control-sm" type="date" name="pickup_date" value="<?=h($ic['pickup_date'] ?? '')?>" <?= $canShip?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">Arrived Indonesia</label>
            <input class="form-control form-control-sm" type="date" name="arrived_id_date" value="<?=h($ic['arrived_id_date'] ?? '')?>" <?= $canShip?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">ETD</label>
            <input class="form-control form-control-sm" type="date" name="etd" value="<?=h($ic['etd'] ?? '')?>" <?= $canShip?'':'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label muted">ETA</label>
            <input class="form-control form-control-sm" type="date" name="eta" value="<?=h($ic['eta'] ?? '')?>" <?= $canShip?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label muted">Arrived Warehouse</label>
            <input class="form-control form-control-sm" type="date" name="arrived_warehouse_date" value="<?=h($ic['arrived_warehouse_date'] ?? '')?>" <?= $canShip?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label muted">Note</label>
            <textarea class="form-control form-control-sm" name="note_ship" rows="2" <?= $canShip?'':'disabled' ?>><?=h($ic['note_ship'] ?? '')?></textarea>
          </div>
          <div class="col-12">
            <?php if ($canShip): ?><button class="btn btn-primary btn-sm">Save</button>
            <?php else: ?><div class="muted">Read-only</div><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-body">
        <div class="fw-semibold mb-2">Document Repository (PQP/SCM/ACT)</div>

        <form method="post" enctype="multipart/form-data" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="upload_doc">
          <div class="col-md-4">
            <label class="form-label muted">File</label>
            <input class="form-control form-control-sm" type="file" name="doc_file" <?= $canDoc?'':'disabled' ?>>
          </div>
          <div class="col-md-3">
            <label class="form-label muted">Type</label>
            <select class="form-select form-select-sm" name="doc_type" <?= $canDoc?'':'disabled' ?>>
              <?php foreach(['CIPL','BL_DRAFT','BL_FINAL','FORM_E','BC11','NOA','BILLING_AJU','SPPB','PIB','NIE_AKL','HS_CODE','OTHER'] as $t): ?>
                <option value="<?=$t?>"><?=$t?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label muted">Doc No</label>
            <input class="form-control form-control-sm" name="doc_number" <?= $canDoc?'':'disabled' ?>>
          </div>
          <div class="col-md-3">
            <label class="form-label muted">Doc Date</label>
            <input class="form-control form-control-sm" type="date" name="doc_date" <?= $canDoc?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label muted">Note</label>
            <input class="form-control form-control-sm" name="doc_note" <?= $canDoc?'':'disabled' ?>>
          </div>
          <div class="col-12">
            <?php if ($canDoc): ?><button class="btn btn-primary btn-sm">Upload / Save</button>
            <?php else: ?><div class="muted">Read-only</div><?php endif; ?>
          </div>
        </form>

        <hr class="border-secondary">

        <div class="table-responsive">
          <table class="table table-sm table-dark align-middle">
            <thead>
              <tr>
                <th>Type</th>
                <th>Number</th>
                <th>Date</th>
                <th>Note</th>
                <th>File</th>
                <th>By</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($docs as $d): ?>
                <tr>
                  <td><span class="badge-soft"><?=h($d['doc_type'])?></span></td>
                  <td><?=h($d['doc_number'] ?? '-')?></td>
                  <td><?=h($d['doc_date'] ?? '-')?></td>
                  <td class="muted"><?=h($d['note'] ?? '')?></td>
                  <td>
                    <?php if (!empty($d['file_path'])): ?>
                      <a class="btn btn-soft btn-sm" href="../<?=h($d['file_path'])?>" target="_blank">Open</a>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                  </td>
                  <td class="muted"><?=h($d['uploaded_by'] ?? '')?><div class="muted"><?=h($d['uploaded_at'] ?? '')?></div></td>
                  <td class="text-end">
                    <?php if ($canDeleteDoc): ?>
                      <form method="post" onsubmit="return confirm('Hapus doc ini?')">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="delete_doc">
                        <input type="hidden" name="doc_id" value="<?=h($d['id'])?>">
                        <button class="btn btn-danger btn-sm">Delete</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$docs): ?><tr><td colspan="7" class="muted">Belum ada dokumen.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="muted">Tips: doc ini dipakai lintas proses (Forwarder, CEISA, Audit).</div>
      </div>
    </div>

    <?php if ($canViewLandCost): ?>
    <div class="card mb-3 border-primary">
      <div class="card-body">
        <div class="fw-semibold mb-2">Total Landed Cost <span class="badge bg-primary">ADMIN/FIN/PQP only</span></div>
        <div class="row g-2 small">
          <div class="col-md-3">
            <span class="muted">PO (Produk)</span>
            <div class="fw-semibold"><?=h(p_money($po_total, $po_currency))?></div>
          </div>
          <div class="col-md-3">
            <span class="muted">Forwarder</span>
            <div class="fw-semibold"><?=h(p_money($fwd_total, $po_currency))?></div>
          </div>
          <div class="col-md-3">
            <span class="muted">CEISA/PIB</span>
            <div class="fw-semibold"><?=h(p_money($ceisa_total, 'IDR'))?></div>
          </div>
          <div class="col-md-3">
            <span class="muted">Total Landed</span>
            <div class="fw-bold text-primary"><?=h(p_money($total_landed, $po_currency))?></div>
          </div>
        </div>
        <div class="mt-2">
          <form method="post" onsubmit="return confirm('Sinkronkan Landed Cost ke Buy Price di Master Pricelist?')">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="sync_to_pricelist">
            <button type="submit" class="btn btn-primary btn-sm">Sync ke Master Pricelist (Buy Price)</button>
          </form>
        </div>
        <div class="muted mt-1">Landed cost dialokasikan ke tiap SKU berdasarkan proporsi nilai produk. Jika currency berbeda (mis. PO USD, CEISA IDR), konversi manual mungkin diperlukan.</div>
      </div>
    </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-body">
            <div class="fw-semibold mb-2">Finance (AP DP / FINAL)</div>
            <?php if (!$ap): ?>
              <div class="muted">Belum ada AP invoice untuk PO ini.</div>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <a class="btn btn-soft btn-sm" href="purchases_invoice_ap.php?po_id=<?=h($id)?>&invoice_type=PROFORMA&percent=30">Create DP 30%</a>
                <a class="btn btn-outline-light btn-sm" href="purchases_invoice_ap.php?po_id=<?=h($id)?>&invoice_type=FINAL&percent=70">Create FINAL 70%</a>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm table-dark align-middle">
                  <thead><tr><th>AP</th><th>Type</th><th class="num">Total</th><th class="num">Paid</th><th class="num">OS</th><th>Status</th></tr></thead>
                  <tbody>
                    <?php foreach($ap as $x): ?>
                      <tr>
                        <td><b><?=h($x['ap_code'])?></b></td>
                        <td><?=h($x['invoice_type'])?></td>
                        <td class="num"><?=h(p_money($x['total_amount'], $x['currency']))?></td>
                        <td class="num"><?=h(p_money($x['paid_amount'], $x['currency']))?></td>
                        <td class="num"><?=h(p_money($x['outstanding'], $x['currency']))?></td>
                        <td><?=h($x['status'])?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php
                $dp_pay_id = 0;
                foreach($ap as $t){
                  if (($t['invoice_type'] ?? '')==='PROFORMA' && in_array(($t['status'] ?? ''), ['UNPAID','PARTIAL'], true)) { $dp_pay_id = (int)$t['id']; break; }
                }
              ?>
              <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-soft btn-sm" href="purchases_invoice_ap.php?po_id=<?=h($id)?>">AP Invoice</a>
                <?php if ($dp_pay_id>0): ?>
                  <a class="btn btn-primary btn-sm" href="purchases_payment_ap.php?ap_id=<?=h($dp_pay_id)?>">Pay DP</a>
                <?php endif; ?>
                <a class="btn btn-soft btn-sm" href="purchases_payment_ap.php">AP Payment</a>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card">
          <div class="card-body">
            <div class="fw-semibold mb-2">Forwarder Invoice (FIN)</div>
            <?php if (!$faps): ?>
              <div class="muted">Belum ada invoice forwarder untuk PO ini.</div>
              <a class="btn btn-soft btn-sm mt-2" href="purchases_forwarder_invoice.php?po_id=<?=h($id)?>">Create Forwarder Invoice</a>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm table-dark align-middle">
                  <thead><tr><th>FAP</th><th>Vendor</th><th class="num">Total</th><th class="num">Paid</th><th class="num">OS</th><th>Status</th></tr></thead>
                  <tbody>
                    <?php foreach($faps as $f): ?>
                      <tr>
                        <td><b><?=h($f['fap_code'])?></b></td>
                        <td><?=h($f['vendors_name'] ?? '')?></td>
                        <td class="num"><?=h(p_money($f['total_amount'], $f['currency']))?></td>
                        <td class="num"><?=h(p_money($f['paid_amount'], $f['currency']))?></td>
                        <td class="num"><?=h(p_money($f['outstanding'], $f['currency']))?></td>
                        <td><?=h($f['status'])?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-soft btn-sm" href="purchases_forwarder_invoice.php?po_id=<?=h($id)?>">Forwarder Invoice</a>
                <a class="btn btn-soft btn-sm" href="purchases_forwarder_payment.php">Forwarder Payment</a>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <div class="fw-semibold mb-2">WQS Incoming (Receiving)</div>
            <?php if (!$incoming): ?>
              <div class="muted">Belum ada receiving untuk PO ini di WQS Incoming.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm table-dark align-middle">
                  <thead><tr><th>Received</th><th>Supplier</th><th>Doc</th><th>Warehouse</th></tr></thead>
                  <tbody>
                    <?php foreach($incoming as $inc): ?>
                      <tr>
                        <td><?=h($inc['received_date'] ?? '')?></td>
                        <td><?=h($inc['supplier_name'] ?? '')?></td>
                        <td><?=h($inc['doc_ref'] ?? '')?></td>
                        <td><?=h($inc['warehouse'] ?? '')?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
            <a class="btn btn-soft btn-sm" href="../stock/wqs_incoming.php" target="_blank">Open WQS Incoming</a>
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

  </div>
</div>
<?php rmi_footer(); ?>
