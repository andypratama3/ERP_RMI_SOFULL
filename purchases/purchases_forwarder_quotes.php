<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.FORWARDING_VIEW', 'PURCHASES.FORWARDING_CREATE', 'PURCHASES.FORWARDING_EDIT']);
} else {
    require_role(['SCM','ADMIN','SUPERADMIN','SYS','MANAGER','PQP','FIN','WQS','BRANCH','STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
require_once __DIR__ . '/../master/_audit_master.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

if (!function_exists('require_any_permission') && !p_can_scm_ops() && !p_is_admin_plus()) { http_response_code(403); echo "Access denied."; exit; }

$flash = p_flash_get();

if (isset($_GET['download_template'])) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="forwarder_quotes_template.csv"');
  echo "po_code,quote_date,forwarder_vendor_code,currency,total_cost,leadtime_days,note\n";
  echo "RMI-PO-XXX,2025-01-31,FWD001,IDR,25000000,14,\"contoh\"\n";
  exit;
}

$po_id = (int)($_GET['po_id'] ?? 0);

// POs for dropdown
$pos=[];
try {
  $pos = $pdo->query("
    SELECT id, po_code, status
    FROM purchases_po
    WHERE deleted_at IS NULL
    ORDER BY id DESC
    LIMIT 300
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Vendors for dropdown (hanya Forwarding & Logistic/Expedisi — SCM)
$vendors=[];
try {
  $vendors = $pdo->query("
    SELECT id, vendors_code, vendors_name, vendor_type, category FROM master_vendors
    WHERE status='active'
    AND (vendor_type = 'Forwarding' OR vendor_type = 'Forwarder'
         OR vendor_type IN ('Logistic/Expedisi','Logistics','Logistic','Ekspedisi','Expedisi'))
    ORDER BY vendors_name
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

function go($po_id): void {
  rmi_redirect('purchases_forwarder_quotes.php' . ($po_id > 0 ? ('?po_id=' . $po_id) : ''));
}

// Import CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
}

if (isset($_POST['import_csv'])) {
  try {
    if (empty($_FILES['csv']['tmp_name'])) throw new Exception("File CSV belum dipilih.");
    if ((($_FILES['csv']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK)) throw new Exception("Upload CSV gagal.");
    $origName = (string)($_FILES['csv']['name'] ?? '');
    $safeName = rmi_safe_filename($origName);
    if ($safeName === '') throw new Exception("Nama file CSV tidak valid.");
    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    if ($ext !== 'csv') throw new Exception("Format file harus .csv.");
    if ((int)($_FILES['csv']['size'] ?? 0) > 3_000_000) throw new Exception("File terlalu besar (maks 3MB).");
    $tmp = $_FILES['csv']['tmp_name'];
    $row=0; $ok=0; $fail=0; $errs=[];
    if (($h = fopen($tmp,'r'))!==false) {
      $pdo->beginTransaction();
      while (($data = fgetcsv($h, 0, ',')) !== false) {
        $row++;
        if ($row===1) continue; // header
        if (count($data) < 6) { $fail++; continue; }
        $po_code = trim((string)($data[0] ?? ''));
        $quote_date = trim((string)($data[1] ?? ''));
        $vendor_code = trim((string)($data[2] ?? ''));
        $currency = up($data[3] ?? 'IDR');
        $total_cost = (float)($data[4] ?? 0);
        $leadtime = (int)($data[5] ?? 0);
        $note = trim((string)($data[6] ?? ''));

        if ($po_code==='' || $vendor_code==='' || $quote_date==='') { $fail++; continue; }

        $st=$pdo->prepare("SELECT id FROM purchases_po WHERE po_code=? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$po_code]);
        $poid=(int)$st->fetchColumn();
        if ($poid<=0) { $fail++; $errs[]="Row {$row}: PO tidak ditemukan ({$po_code})"; continue; }

        $st=$pdo->prepare("SELECT id FROM master_vendors WHERE vendors_code=? LIMIT 1");
        $st->execute([$vendor_code]);
        $vid=(int)$st->fetchColumn();
        if ($vid<=0) { $fail++; $errs[]="Row {$row}: Vendor tidak ditemukan ({$vendor_code})"; continue; }

        if ($currency==='') $currency='IDR';

        $pdo->prepare("INSERT INTO purchases_forwarder_quotes (po_id, vendor_id, quote_date, currency, total_cost, leadtime_days, note, status, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$poid,$vid,$quote_date,$currency,$total_cost,$leadtime,$note,'DRAFT',p_username()]);
        $ok++;
      }
      $pdo->commit();
      fclose($h);
    }
    if ($ok > 0 && function_exists('master_audit')) {
      master_audit($pdo, 'purchases_forwarder_quotes', 'purchases_forwarder_quotes', 'IMPORT_CSV', null, 'IMPORT', "Forwarder quotes import: OK={$ok}, FAIL={$fail}", ['ok' => $ok, 'fail' => $fail]);
    }
    p_flash_set('success',"Import selesai. OK={$ok}, FAIL={$fail}");
    if ($errs) p_flash_set('warning', implode(" | ", array_slice($errs,0,5)));
  } catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $x) {}
    p_flash_set('danger',$e->getMessage());
  }
  go($po_id);
}

// Add quote
if (isset($_POST['add_quote'])) {
  try {
    $po_id = (int)($_POST['po_id'] ?? 0);
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $quote_date = $_POST['quote_date'] ?? date('Y-m-d');
    $currency = up($_POST['currency'] ?? 'IDR');
    $total_cost = (float)($_POST['total_cost'] ?? 0);
    $leadtime = (int)($_POST['leadtime_days'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));

    if ($po_id<=0 || $vendor_id<=0) throw new Exception("PO & Forwarder wajib.");
    if ($total_cost<=0) throw new Exception("Total cost wajib > 0.");

    $pdo->prepare("INSERT INTO purchases_forwarder_quotes (po_id, vendor_id, quote_date, currency, total_cost, leadtime_days, note, status, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$po_id,$vendor_id,$quote_date,$currency,$total_cost,$leadtime,$note,'DRAFT',p_username()]);
    $qid = (int)$pdo->lastInsertId();
    p_audit($pdo,'FWD_QUOTE', (string)$po_id, 'CREATE', ['vendor_id'=>$vendor_id,'total_cost'=>$total_cost,'currency'=>$currency]);
    if (function_exists('master_audit')) {
      $poCode = '';
      try { $st = $pdo->prepare("SELECT po_code FROM purchases_po WHERE id=? LIMIT 1"); $st->execute([$po_id]); $poCode = (string)($st->fetchColumn() ?: ''); } catch (Throwable $e) {}
      master_audit($pdo, 'purchases_forwarder_quotes', 'purchases_forwarder_quotes', 'CREATE', $qid, 'PO:' . $po_id, "Forwarder quote created for PO {$poCode}", ['vendor_id' => $vendor_id, 'total_cost' => $total_cost]);
    }
    p_flash_set('success',"Quote tersimpan.");
  } catch (Throwable $e) {
    p_flash_set('danger',$e->getMessage());
  }
  go($po_id);
}

// Select quote
if (isset($_POST['select_quote'])) {
  try {
    $qid = (int)($_POST['quote_id'] ?? 0);
    if ($qid<=0) throw new Exception("Quote invalid.");
    $st=$pdo->prepare("SELECT * FROM purchases_forwarder_quotes WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$qid]);
    $qrow=$st->fetch(PDO::FETCH_ASSOC);
    if (!$qrow) throw new Exception("Quote tidak ditemukan.");

    $po_id = (int)$qrow['po_id'];
    $vendor_id = (int)$qrow['vendor_id'];

    $pdo->beginTransaction();
    // mark selected & others rejected
    $pdo->prepare("UPDATE purchases_forwarder_quotes SET status='REJECTED' WHERE po_id=? AND deleted_at IS NULL")->execute([$po_id]);
    $pdo->prepare("UPDATE purchases_forwarder_quotes SET status='SELECTED' WHERE id=?")->execute([$qid]);

    // set PO forwarder
    $pdo->prepare("UPDATE purchases_po SET forwarder_vendor_id=?, forwarder_status='IN_PROGRESS', updated_at=NOW() WHERE id=?")
        ->execute([$vendor_id,$po_id]);

    $pdo->commit();

    p_audit($pdo,'FWD_QUOTE', (string)$po_id, 'SELECT', ['quote_id'=>$qid,'vendor_id'=>$vendor_id]);
    if (function_exists('master_audit')) {
      $poCode = '';
      try { $st = $pdo->prepare("SELECT po_code FROM purchases_po WHERE id=? LIMIT 1"); $st->execute([$po_id]); $poCode = (string)($st->fetchColumn() ?: ''); } catch (Throwable $e) {}
      master_audit($pdo, 'purchases_forwarder_quotes', 'purchases_forwarder_quotes', 'SELECT', $qid, 'PO:' . $po_id, "Forwarder quote selected for PO {$poCode}", ['quote_id' => $qid, 'vendor_id' => $vendor_id]);
    }
    p_flash_set('success',"Forwarder dipilih dan PO di-update.");
  } catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $x) {}
    p_flash_set('danger',$e->getMessage());
  }
  go($po_id);
}

// Load selected PO info
$po=null;
if ($po_id>0) {
  try {
    $st=$pdo->prepare("SELECT po.*, o.office_name, m.manufacture_name, v.vendors_name AS forwarder_name
                       FROM purchases_po po
                       LEFT JOIN master_office o ON o.office_code=po.office_code
                       LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
                       LEFT JOIN master_vendors v ON v.id=po.forwarder_vendor_id
                       WHERE po.id=? LIMIT 1");
    $st->execute([$po_id]);
    $po=$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {}
}

// Load quotes list
$quotes=[];
if ($po_id>0) {
  try {
    $st=$pdo->prepare("
      SELECT q.*, v.vendors_code, v.vendors_name
      FROM purchases_forwarder_quotes q
      LEFT JOIN master_vendors v ON v.id=q.vendor_id
      WHERE q.deleted_at IS NULL AND q.po_id=?
      ORDER BY q.status='SELECTED' DESC, q.total_cost ASC, q.id DESC
    ");
    $st->execute([$po_id]);
    $quotes=$st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {}
}

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'purchases_forwarder_quotes' ORDER BY created_at DESC LIMIT 50");
  $st->execute();
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $audit_rows = []; }
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Forwarder Quotes', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Forwarder Quotes',
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
    <div class="muted">Purchases • SCM</div>
    <h3 class="mb-0">Forwarder Quotes</h3>
    <div class="muted">Input beberapa penawaran forwarder → pilih 1 → PO otomatis update</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-soft btn-sm" href="purchases_import_control_tower.php">← Control Tower</a>
    <a class="btn btn-soft btn-sm" href="purchases_forwarding_tasks.php">Forwarding Tasks</a>
    <a class="btn btn-soft btn-sm" href="?download_template=1">Download CSV Template</a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="row g-2">
      <div class="col-md-6">
        <form method="get">
          <label class="form-label muted">Pilih PO</label>
          <select class="form-select form-select-sm" name="po_id" onchange="this.form.submit()">
            <option value="">-- pilih PO --</option>
            <?php foreach($pos as $p): ?>
              <option value="<?=h($p['id'])?>" <?=($po_id===(int)$p['id']?'selected':'')?>>
                <?=h($p['po_code'].' • '.$p['status'])?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <div class="col-md-6">
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <div class="col-12">
            <label class="form-label muted">Import CSV (optional)</label>
          </div>
          <div class="col-8">
            <input type="file" name="csv" class="form-control form-control-sm" accept=".csv" required>
          </div>
          <div class="col-4">
            <button class="btn btn-primary btn-sm w-100" name="import_csv">Import</button>
          </div>
          <div class="col-12 muted">Format: po_code, quote_date, forwarder_vendor_code, currency, total_cost, leadtime_days, note</div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($po): ?>
  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card mb-3">
        <div class="card-body">
          <div class="fw-semibold mb-1">PO Info</div>
          <div><b><?=h($po['po_code'])?></b> • <?=h($po['status'])?></div>
          <div class="muted">Office: <?=h($po['office_name'] ?? $po['office_code'])?></div>
          <div class="muted">Manufacture: <?=h($po['manufacture_name'] ?? '-')?></div>
          <div class="muted">Forwarder selected: <?=h($po['forwarder_name'] ?? '-')?></div>

          <hr class="border-secondary">

          <div class="fw-semibold mb-2">Tambah Quote</div>
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="po_id" value="<?=h($po_id)?>">
            <div class="col-md-6">
              <label class="form-label muted">Forwarder Vendor</label>
              <select class="form-select form-select-sm" name="vendor_id" required>
                <option value="">-- pilih vendor --</option>
                <?php foreach($vendors as $v): ?>
                  <option value="<?=h($v['id'])?>"><?=h($v['vendors_name'].' ('.$v['vendors_code'].')')?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label muted">Quote Date</label>
              <input class="form-control form-control-sm" type="date" name="quote_date" value="<?=h(date('Y-m-d'))?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label muted">Currency</label>
              <select class="form-select form-select-sm" name="currency">
                <option>IDR</option><option>USD</option><option>CNY</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label muted">Total Cost</label>
              <input class="form-control form-control-sm text-end" type="number" step="0.01" name="total_cost" required>
            </div>
            <div class="col-md-6">
              <label class="form-label muted">Leadtime (days)</label>
              <input class="form-control form-control-sm" type="number" name="leadtime_days" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label muted">Note</label>
              <input class="form-control form-control-sm" name="note" placeholder="opsional">
            </div>
            <div class="col-12">
              <button class="btn btn-primary btn-sm" name="add_quote">Save Quote</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card">
        <div class="card-body">
          <div class="fw-semibold mb-2">Quotes List</div>
          <div class="table-responsive">
            <table id="tbl" class="display" style="width:100%">
              <thead>
                <tr>
                  <th>Status</th>
                  <th>Vendor</th>
                  <th>Quote Date</th>
                  <th>Currency</th>
                  <th class="num">Total Cost</th>
                  <th class="num">Leadtime</th>
                  <th>Note</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($quotes as $q): ?>
                  <tr>
                    <td><b><?=h($q['status'])?></b></td>
                    <td><?=h($q['vendors_name'] ?? '')?></td>
                    <td><?=h($q['quote_date'])?></td>
                    <td><?=h($q['currency'])?></td>
                    <td class="num"><?=h(p_money($q['total_cost'], $q['currency']))?></td>
                    <td class="num"><?=h($q['leadtime_days'])?></td>
                    <td class="muted"><?=h($q['note'] ?? '')?></td>
                    <td>
                      <?php if (strtoupper($q['status'])!=='SELECTED'): ?>
                        <form method="post" onsubmit="return confirm('Pilih quote ini sebagai forwarder?')">
                          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                          <input type="hidden" name="quote_id" value="<?=h($q['id'])?>">
                          <button class="btn btn-success btn-sm" name="select_quote">Select</button>
                        </form>
                      <?php else: ?>
                        <span class="muted">Selected</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="muted mt-2">Tips: setelah Select, PO forwarder akan otomatis terisi (untuk dipakai di Forwarding Tasks & Control Tower).</div>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="muted">Pilih PO dulu untuk melihat/menambah quotes.</div>
<?php endif; ?>

<div class="card mb-3 mt-3">
  <div class="card-body">
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

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script>
$(function(){
  if ($('#tbl').length){
    $('#tbl').DataTable({pageLength:25, order:[[4,'asc']], dom:'Bfrtip', buttons:['copy','csv','excel','print']});
  }
});
</script>
<?php rmi_footer(); ?>
