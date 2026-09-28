<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.PO_CRUD', 'PURCHASES.VIEW']);
} else {
    require_role(['ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
}

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$flash = p_flash_get();

// master data
$manufactures = [];
$offices = [];
$terms = [];
$pr_list = [];
$products = [];

try { $manufactures = $pdo->query("SELECT id, manufacture_code, manufacture_name FROM master_manufactures WHERE status=1 OR status='active' ORDER BY manufacture_name")->fetchAll(); } catch (Throwable $e) {}
try { $offices = $pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name")->fetchAll(); } catch (Throwable $e) {}
try { $terms = $pdo->query("SELECT term_code FROM master_payment_terms ORDER BY term_code")->fetchAll(); } catch (Throwable $e) {}

try {
  // PR available for PO (SUBMITTED only)
  $pr_list = $pdo->query("SELECT id, pr_code, pr_date, office_code FROM wqs_pr WHERE deleted_at IS NULL AND status='SUBMITTED' ORDER BY id DESC LIMIT 500")->fetchAll();
} catch (Throwable $e) {}

try {
  $skuCol='sku'; $nameCol='products_name';
  try { $pdo->query("SELECT sku FROM master_products LIMIT 1"); } catch (Throwable $e) { $skuCol='products_code'; }
  try { $pdo->query("SELECT products_name FROM master_products LIMIT 1"); } catch (Throwable $e) { $nameCol='product_name'; }
  $products = $pdo->query("SELECT id, {$skuCol} AS sku, {$nameCol} AS products_name, unit FROM master_products WHERE status='active' ORDER BY {$nameCol}")->fetchAll();
} catch (Throwable $e) { $products=[]; }

$action = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  verify_csrf((string)($_POST['csrf_token'] ?? ''));
  $action = (string)($_POST['action'] ?? '');
}

if ($action === 'create_po') {
  if (!p_can_create_po()) {
    p_flash_set('danger','Hanya PQP/Admin yang boleh membuat PO.');
    header("Location: purchases_po.php"); exit;
  }

  $po_date = $_POST['po_date'] ?? date('Y-m-d');
  $pr_id = (int)($_POST['pr_id'] ?? 0);
  $manufacture_id = (int)($_POST['manufacture_id'] ?? 0);
  $office_code = up($_POST['office_code'] ?? '');
  $currency = up($_POST['currency'] ?? 'IDR');
  $payment_term = up($_POST['payment_term'] ?? '');
  $note = trim((string)($_POST['note'] ?? ''));

  if ($pr_id<=0) { p_flash_set('danger','PR wajib dipilih.'); header("Location: purchases_po.php"); exit; }
  if ($manufacture_id<=0) { p_flash_set('danger','Manufacture/Pabrikan wajib dipilih.'); header("Location: purchases_po.php"); exit; }
  if ($office_code==='') {
    // pull from PR
    $st=$pdo->prepare("SELECT office_code FROM wqs_pr WHERE id=?"); $st->execute([$pr_id]);
    $office_code = up($st->fetchColumn() ?: '');
  }
  if ($office_code==='') { p_flash_set('danger','Office wajib.'); header("Location: purchases_po.php"); exit; }

  $dateYmd = date('ymd', strtotime($po_date));
  $prefix = "RMI-PO-{$office_code}-{$dateYmd}-";
  $po_code = p_generate_code($pdo, 'purchases_po', 'po_code', $prefix);

  $pdo->beginTransaction();
  try {
    $pdo->prepare("INSERT INTO purchases_po (po_code, po_date, pr_id, manufacture_id, office_code, currency, payment_term, note, status, total_amount, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$po_code,$po_date,$pr_id,$manufacture_id,$office_code,$currency,$payment_term,$note,'OPEN',0,p_username()]);
    $po_id = (int)$pdo->lastInsertId();

    // Items from posted (autofill PR, may adjust)
    $pids = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $units= $_POST['unit'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    $line=1;
    for ($i=0;$i<count($pids);$i++){
      $pid=(int)$pids[$i];
      $qty=(float)($qtys[$i] ?? 0);
      $unit=trim((string)($units[$i] ?? 'pcs'));
      $price=(float)($prices[$i] ?? 0);

      if ($pid<=0 || $qty<=0) continue;
      if (!p_can_view_buy_price()) $price = 0;

      $sku=''; $pname=''; $punit='pcs';
      foreach($products as $pp){ if ((int)$pp['id']===$pid){ $sku=rmi_sku($pp['sku']); $pname=rmi_product_name($pp['products_name']); $punit=$pp['unit']??'pcs'; break; } }
      if ($unit==='') $unit=$punit;

      $subtotal=$qty*$price;

      $pdo->prepare("INSERT INTO purchases_po_items (po_id,line_no,product_id,sku,products_name,qty,unit,unit_price,subtotal)
                     VALUES (?,?,?,?,?,?,?,?,?)")
          ->execute([$po_id,$line,$pid,$sku,$pname,$qty,$unit,$price,$subtotal]);
      $line++;
    }

    p_recalc_po_total($pdo,$po_id);

    // mark PR as PO_CREATED
    $pdo->prepare("UPDATE wqs_pr SET status='PO_CREATED' WHERE id=?")->execute([$pr_id]);

    $pdo->commit();

    // PATCH_3_AUDIT
    $beforeAudit = [
      'event' => 'create_po',
      'po_code' => $po_code,
      'po_date' => $po_date,
      'pr_id' => $pr_id,
      'manufacture_id' => $manufacture_id,
      'office_code' => $office_code,
      'currency' => $currency,
      'payment_term' => $payment_term,
      'note' => $note,
      'items_inserted' => max(0, $line - 1),
    ];
    $poRow = null;
    $itemsRows = [];
    try {
      $stP = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1");
      $stP->execute([$po_id]);
      $poRow = $stP->fetch();
    } catch (Throwable $e) { $poRow = null; }
    try {
      $stI = $pdo->prepare("SELECT * FROM purchases_po_items WHERE po_id=? ORDER BY line_no");
      $stI->execute([$po_id]);
      $itemsRows = $stI->fetchAll();
    } catch (Throwable $e) { $itemsRows = []; }
    rmi_audit_safe('CREATE', 'PURCHASES.PO', $po_id, $beforeAudit, [
      'po' => $poRow,
      'items' => $itemsRows,
    ]);

    p_audit($pdo,'PO',$po_code,'CREATE',['po_id'=>$po_id,'pr_id'=>$pr_id,'manufacture_id'=>$manufacture_id]);
    p_flash_set('success',"PO dibuat: {$po_code} (dari PR)");
    header("Location: purchases_po_view.php?id=".$po_id); exit;
  } catch (Throwable $e) {
    $pdo->rollBack();
    p_flash_set('danger','Gagal membuat PO: '.$e->getMessage());
    header("Location: purchases_po.php"); exit;
  }
}

// status change (manager+)
if (isset($_GET['set_status']) && isset($_GET['id']) && p_is_manager_plus()) {
  $id=(int)$_GET['id']; $stt=up($_GET['set_status']);
  if (!in_array($stt,['DRAFT','OPEN','IN_PRODUCTION','READY','CLOSED','CANCELLED'],true)) $stt='OPEN';
  try {
    $before = null;
    $after = null;
    $st=$pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $st->execute([$id]); $before=$st->fetch();
    $code=(string)($before['po_code'] ?? '');
    $pdo->prepare("UPDATE purchases_po SET status=? WHERE id=?")->execute([$stt,$id]);
    $st2=$pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $st2->execute([$id]); $after=$st2->fetch();

    // PATCH_3_AUDIT
    rmi_audit_safe('UPDATE', 'PURCHASES.PO', $id, $before, $after, [
      'event' => 'set_status',
      'to_status' => $stt,
      'po_code' => $code,
    ]);
    p_audit($pdo,'PO',$code,'SET_STATUS',['status'=>$stt,'id'=>$id]);
    p_flash_set('success',"Status PO -> {$stt}");
    try {
      require_once __DIR__ . '/../_shared/chat_notice.php';
      $st = $pdo->prepare("SELECT office_code FROM purchases_po WHERE id=? LIMIT 1");
      $st->execute([$id]);
      $docOffice = strtoupper(trim((string)$st->fetchColumn()));
      $pic = $pdo->prepare("SELECT id FROM master_system_login WHERE LOWER(COALESCE(status,'active'))='active' AND UPPER(COALESCE(department,'')) IN ('PQP','SCM') AND (?='' OR UPPER(COALESCE(office_code,''))=?) ORDER BY id ASC LIMIT 5");
      $pic->execute([$docOffice, $docOffice]);
      $userIds = array_map('intval', $pic->fetchAll(PDO::FETCH_COLUMN));
      chat_notice_po($pdo, $id, 'STATUS_' . $stt, 'PO #' . $id . ' (' . $code . ') status -> ' . $stt, $userIds);
    } catch (Throwable $e) { /* non-fatal */ }
  } catch (Throwable $e) { p_flash_set('danger',$e->getMessage()); }
  header("Location: purchases_po.php"); exit;
}

// soft delete/restore admin+
if (isset($_GET['delete']) && p_is_admin_plus()) {
  $id=(int)$_GET['delete'];
  try { $st=$pdo->prepare("SELECT po_code FROM purchases_po WHERE id=?"); $st->execute([$id]); $code=(string)$st->fetchColumn();
    $before = null;
    $after = null;
    try { $stB = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $stB->execute([$id]); $before = $stB->fetch(); } catch (Throwable $e) { $before = null; }
    $pdo->prepare("UPDATE purchases_po SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    try { $stA = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $stA->execute([$id]); $after = $stA->fetch(); } catch (Throwable $e) { $after = null; }

    // PATCH_3_AUDIT
    rmi_audit_safe('DELETE', 'PURCHASES.PO', $id, $before, $after, [
      'event' => 'soft_delete',
      'po_code' => $code,
    ]);
    p_audit($pdo,'PO',$code,'SOFT_DELETE',['id'=>$id]);
    p_flash_set('success','PO soft deleted.');
  } catch (Throwable $e) { p_flash_set('danger',$e->getMessage()); }
  header("Location: purchases_po.php"); exit;
}
if (isset($_GET['restore']) && p_is_admin_plus()) {
  $id=(int)$_GET['restore'];
  try { $st=$pdo->prepare("SELECT po_code FROM purchases_po WHERE id=?"); $st->execute([$id]); $code=(string)$st->fetchColumn();
    $before = null;
    $after = null;
    try { $stB = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $stB->execute([$id]); $before = $stB->fetch(); } catch (Throwable $e) { $before = null; }
    $pdo->prepare("UPDATE purchases_po SET deleted_at=NULL WHERE id=?")->execute([$id]);
    try { $stA = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $stA->execute([$id]); $after = $stA->fetch(); } catch (Throwable $e) { $after = null; }

    // PATCH_3_AUDIT
    rmi_audit_safe('UPDATE', 'PURCHASES.PO', $id, $before, $after, [
      'event' => 'restore',
      'po_code' => $code,
    ]);
    p_audit($pdo,'PO',$code,'RESTORE',['id'=>$id]);
    p_flash_set('success','PO restored.');
  } catch (Throwable $e) { p_flash_set('danger',$e->getMessage()); }
  header("Location: purchases_po.php"); exit;
}

// List
$where=["1=1"]; $params=[];
$show_deleted = ($_GET['show_deleted'] ?? '')==='1';
if (!$show_deleted) $where[]="po.deleted_at IS NULL";
$status_f=up($_GET['status'] ?? '');
if ($status_f!=='' && $status_f!=='ALL'){ $where[]="po.status=?"; $params[]=$status_f; }
$office_f=up($_GET['office_code'] ?? '');
if ($office_f!=='' && $office_f!=='ALL'){ $where[]="po.office_code=?"; $params[]=$office_f; }
$from=$_GET['from'] ?? ''; $to=$_GET['to'] ?? '';
if ($from!==''){ $where[]="po.po_date>=?"; $params[]=$from; }
if ($to!==''){ $where[]="po.po_date<=?"; $params[]=$to; }

$sql="SELECT po.*, o.office_name, m.manufacture_name, pr.pr_code
      FROM purchases_po po
      LEFT JOIN master_office o ON o.office_code=po.office_code
      LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
      LEFT JOIN wqs_pr pr ON pr.id=po.pr_id
      WHERE ".implode(" AND ",$where)."
      ORDER BY po.id DESC";
$rows=[];
try { $st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$canPrice = p_can_view_buy_price();
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$extraHead = '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/jquery.dataTables.min.css?v=20260209" rel="stylesheet">' .
  '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.dataTables.min.css?v=20260209" rel="stylesheet">' .
  '<style>
    body{background:radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
    .muted{color:#9ca3af;font-size:12px}
    .form-control,.form-select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.14)!important}
    label{color:#cbd5e1;font-size:12px}
    .btn-soft{border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e5e7eb}
    .btn-soft:hover{background:rgba(255,255,255,.10);color:#fff}
    table.dataTable thead th,table.dataTable tbody td{color:#e5e7eb}
    .dt-buttons .btn,.dataTables_wrapper .dt-buttons button{border-radius:10px!important;border:1px solid rgba(255,255,255,.15)!important;background:rgba(255,255,255,.08)!important;color:#e5e7eb!important;padding:6px 10px!important;font-size:12px!important;}
    .dataTables_wrapper .dataTables_filter input,.dataTables_wrapper .dataTables_length select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.12)!important;border-radius:10px!important;}
    .num{text-align:right}
    .pill{padding:2px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);font-size:12px}
  </style>';

rmi_header('Purchase Order (PO) - PQP', 'purchases', [
  'subtitle' => 'PO dibuat dari PR (WQS) + pabrikan (master_manufactures).',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Purchase Order',
  ],
  'actions' => [
    ['label' => 'Dashboard', 'url' => 'purchases_dashboard.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'WQS PR', 'url' => '../stock/wqs_pr.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'WQS Incoming', 'url' => '../stock/wqs_incoming.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Open Chat (PO)', 'url' => '../chat/index.php?context=PO:LIST', 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'extra_head' => $extraHead,
]);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted">Purchases</div>
    <h3 class="mb-0">Purchase Order (PO) - PQP</h3>
    <div class="muted">PO dibuat dari PR (WQS) + pabrikan (master_manufactures)</div>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="fw-semibold">Create PO (from PR)</div>
      <div class="muted">Buy price: <?= $canPrice ? "<span class='pill'>VISIBLE</span>" : "<span class='pill'>HIDDEN</span>" ?> • Create: <?= p_can_create_po() ? "<span class='pill'>ALLOWED</span>" : "<span class='pill'>READ-ONLY</span>" ?></div>
    </div>

    <form method="post" class="row g-2" onsubmit="return confirm('Create PO dari PR? PR akan otomatis berubah status PO_CREATED.');">
      <input type="hidden" name="action" value="create_po">

      <div class="col-md-2">
        <label class="form-label">PO Date</label>
        <input class="form-control form-control-sm" type="date" name="po_date" value="<?=h(date('Y-m-d'))?>" required <?= p_can_create_po()?'':'disabled' ?>>
      </div>

      <div class="col-md-4">
        <label class="form-label">PR (SUBMITTED)</label>
        <select class="form-select form-select-sm" name="pr_id" id="pr_id" required <?= p_can_create_po()?'':'disabled' ?>>
          <option value="">-- pilih PR --</option>
          <?php foreach($pr_list as $pr): ?>
            <option value="<?=h($pr['id'])?>"><?=h($pr['pr_code'].' | '.$pr['office_code'].' | '.$pr['pr_date'])?></option>
          <?php endforeach; ?>
        </select>
        <div class="muted">Jika kosong, berarti belum ada PR yang status SUBMITTED.</div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Manufacture / Pabrikan</label>
        <select class="form-select form-select-sm" name="manufacture_id" required <?= p_can_create_po()?'':'disabled' ?>>
          <option value="">-- pilih pabrikan --</option>
          <?php foreach($manufactures as $m): ?>
            <option value="<?=h($m['id'])?>"><?=h(strtoupper($m['manufacture_code']??'')." - ".strtoupper($m['manufacture_name']??''))?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">Office</label>
        <select class="form-select form-select-sm" name="office_code" id="office_code" required <?= p_can_create_po()?'':'disabled' ?>>
          <option value="">-- auto dari PR --</option>
          <?php foreach($offices as $o): ?>
            <option value="<?=h(strtoupper($o['office_code']??''))?>"><?=h(strtoupper($o['office_name']??''))?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">Currency</label>
        <select class="form-select form-select-sm" name="currency" <?= p_can_create_po()?'':'disabled' ?>>
          <option value="IDR">IDR</option>
          <option value="USD">USD</option>
          <option value="CNY">CNY</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Payment Term</label>
        <select class="form-select form-select-sm" name="payment_term" <?= p_can_create_po()?'':'disabled' ?>>
          <option value="">-- optional --</option>
          <?php foreach($terms as $t): ?>
            <option value="<?=h($t['term_code'])?>"><?=h($t['term_code'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-7">
        <label class="form-label">Note</label>
        <input class="form-control form-control-sm" name="note" placeholder="catatan negosiasi/produksi/dokumen..." <?= p_can_create_po()?'':'disabled' ?>>
      </div>

      <div class="col-12 mt-2">
        <div class="fw-semibold mb-1">Items (auto dari PR)</div>
        <div class="muted mb-2">Pilih PR → item otomatis muncul. PQP/FIN bisa isi harga beli.</div>
        <div class="table-responsive">
          <table class="table table-sm table-dark align-middle" id="itemsTable">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Product</th>
                <th style="width:120px" class="num">Qty</th>
                <th style="width:100px">Unit</th>
                <th style="width:150px" class="num">Unit Price</th>
                <th style="width:160px" class="num">Subtotal</th>
                <th style="width:70px">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr><td colspan="7" class="muted">Pilih PR dulu.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2">
          <button type="button" class="btn btn-soft btn-sm" onclick="addRowManual()" <?= p_can_create_po()?'':'disabled' ?>>+ Add Item</button>
          <div class="muted">Total (preview): <b id="totalPreview">0</b></div>
        </div>

      </div>

      <div class="col-12 mt-2">
        <button class="btn btn-primary btn-sm" <?= p_can_create_po()?'':'disabled' ?>>Save PO</button>
      </div>
    </form>

  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="fw-semibold">PO List</div>
      <form class="d-flex gap-2 flex-wrap" method="get">
        <input type="date" class="form-control form-control-sm" name="from" value="<?=h($from)?>">
        <input type="date" class="form-control form-control-sm" name="to" value="<?=h($to)?>">
        <select class="form-select form-select-sm" name="status">
          <option value="ALL">All Status</option>
          <?php foreach(['DRAFT','OPEN','IN_PRODUCTION','READY','CLOSED','CANCELLED'] as $s): ?>
            <option value="<?=h($s)?>" <?= $status_f===$s?'selected':'' ?>><?=h($s)?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" name="office_code">
          <option value="ALL">All Office</option>
          <?php foreach($offices as $o): ?>
            <option value="<?=h(strtoupper($o['office_code']??''))?>" <?= $office_f===up($o['office_code']??'')?'selected':'' ?>><?=h(strtoupper($o['office_name']??''))?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" value="1" id="show_deleted" name="show_deleted" <?= $show_deleted?'checked':'' ?>>
          <label class="form-check-label muted" for="show_deleted">Show deleted</label>
        </div>
        <button class="btn btn-soft btn-sm">Filter</button>
      </form>
    </div>

    <div class="table-responsive">
      <table id="poTable" class="display" style="width:100%">
        <thead>
          <tr>
            <th>PO Code</th>
            <th>Date</th>
            <th>PR</th>
            <th>Manufacture</th>
            <th>Office</th>
            <th>Status</th>
            <th class="num">Total</th>
            <th>Created By</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><b><?=h(strtoupper($r['po_code']??''))?></b><?= $r['deleted_at'] ? " <span class='pill'>DELETED</span>" : "" ?></td>
              <td><?=h($r['po_date'])?></td>
              <td><?=h(strtoupper($r['pr_code'] ?? ''))?></td>
              <td><?=h(strtoupper($r['manufacture_name'] ?? ''))?></td>
              <td><?=h(strtoupper($r['office_name'] ?? $r['office_code'] ?? ''))?></td>
              <td><?=h(strtoupper($r['status'] ?? ''))?></td>
              <td class="num"><?=h($canPrice ? p_money($r['total_amount'], $r['currency'] ?? 'IDR') : '0')?></td>
              <td><?=h($r['created_by'] ?? '')?></td>
              <td>
                <a class="btn btn-soft btn-sm" href="purchases_po_view.php?id=<?=h($r['id'])?>">View</a>
                <a class="btn btn-soft btn-sm" href="purchases_po_print.php?id=<?=h($r['id'])?>" target="_blank">Print</a>
                <?php if (p_is_manager_plus() && !$r['deleted_at']): ?>
                  <div class="btn-group">
                    <button type="button" class="btn btn-soft btn-sm dropdown-toggle" data-bs-toggle="dropdown">Status</button>
                    <ul class="dropdown-menu dropdown-menu-dark">
                      <li><a class="dropdown-item" href="?set_status=OPEN&id=<?=h($r['id'])?>">OPEN</a></li>
                      <li><a class="dropdown-item" href="?set_status=IN_PRODUCTION&id=<?=h($r['id'])?>">IN_PRODUCTION</a></li>
                      <li><a class="dropdown-item" href="?set_status=READY&id=<?=h($r['id'])?>">READY</a></li>
                      <li><a class="dropdown-item" href="?set_status=CLOSED&id=<?=h($r['id'])?>">CLOSED</a></li>
                      <li><a class="dropdown-item" href="?set_status=CANCELLED&id=<?=h($r['id'])?>">CANCELLED</a></li>
                    </ul>
                  </div>
                <?php endif; ?>
                <?php if (p_is_admin_plus()): ?>
                  <?php if (!$r['deleted_at']): ?>
                    <a class="btn btn-danger btn-sm" href="?delete=<?=h($r['id'])?>" onclick="return confirm('Soft delete PO?')">Delete</a>
                  <?php else: ?>
                    <a class="btn btn-success btn-sm" href="?restore=<?=h($r['id'])?>" onclick="return confirm('Restore PO?')">Restore</a>
                  <?php endif; ?>
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
const PRODUCTS = <?php echo json_encode(array_map(fn($p)=>['id'=>$p['id'],'sku'=>rmi_sku($p['sku']),'products_name'=>rmi_product_name($p['products_name']),'unit'=>$p['unit']??'pcs'], $products), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const CAN_PRICE = <?php echo p_can_view_buy_price() ? 'true' : 'false'; ?>;
const CAN_CREATE = <?php echo p_can_create_po() ? 'true' : 'false'; ?>;

function rowHtml(item){
  const pid = item.product_id || '';
  const sku = item.sku || '';
  const name = item.name || '';
  const qty = item.qty ?? '';
  const unit = item.unit || 'pcs';
  const price = CAN_PRICE ? (item.unit_price ?? 0) : 0;
  const subtotal = (parseFloat(qty||0) * parseFloat(price||0));

  return `
    <tr>
      <td><input type="hidden" name="product_id[]" value="${pid}"><b>${(sku||'').toUpperCase()}</b></td>
      <td>${(name||'').toUpperCase()}</td>
      <td class="num"><input class="form-control form-control-sm text-end" type="number" step="0.01" name="qty[]" value="${qty}" ${CAN_CREATE?'':'disabled'}></td>
      <td><input class="form-control form-control-sm" name="unit[]" value="${unit}" ${CAN_CREATE?'':'disabled'}></td>
      <td class="num">
        ${CAN_PRICE ? `<input class="form-control form-control-sm text-end price" type="number" step="0.01" name="unit_price[]" value="${price}" ${CAN_CREATE?'':'disabled'}>` :
          `<span class="muted">Restricted</span><input type="hidden" name="unit_price[]" value="0">`}
      </td>
      <td class="num subtotal">${subtotal.toLocaleString('id-ID',{maximumFractionDigits:2})}</td>
      <td><button type="button" class="btn btn-outline-light btn-sm" onclick="this.closest('tr').remove(); recalcTotal();" ${CAN_CREATE?'':'disabled'}>X</button></td>
    </tr>
  `;
}

function recalcTotal(){
  let total=0;
  document.querySelectorAll('#itemsTable tbody tr').forEach(tr=>{
    const qtyEl = tr.querySelector("input[name='qty[]']");
    const priceEl = tr.querySelector("input[name='unit_price[]']");
    const q = parseFloat(qtyEl?.value||'0');
    const p = CAN_PRICE ? parseFloat(priceEl?.value||'0') : 0;
    const st = q*p;
    const sub = tr.querySelector('.subtotal');
    if (sub) sub.textContent = st.toLocaleString('id-ID',{maximumFractionDigits:2});
    total += st;
  });
  document.getElementById('totalPreview').textContent = total.toLocaleString('id-ID',{maximumFractionDigits:2});
}

function bindPriceInputs(){
  document.querySelectorAll('#itemsTable tbody .price').forEach(el=>{
    el.addEventListener('input', recalcTotal);
  });
  document.querySelectorAll('#itemsTable tbody input[name="qty[]"]').forEach(el=>{
    el.addEventListener('input', recalcTotal);
  });
}

async function loadPR(pr_id){
  const tbody = document.querySelector('#itemsTable tbody');
  tbody.innerHTML = `<tr><td colspan="7" class="muted">Loading...</td></tr>`;
  try{
    const res = await fetch(`purchases_pr_api.php?pr_id=${encodeURIComponent(pr_id)}`);
    const data = await res.json();
    if (!data.ok){
      tbody.innerHTML = `<tr><td colspan="7" class="muted">${data.message||'Gagal load PR'}</td></tr>`;
      return;
    }
    // set office
    if (data.header && data.header.office_code){
      document.getElementById('office_code').value = data.header.office_code;
    }
    // items
    tbody.innerHTML = '';
    if (!data.items || data.items.length===0){
      tbody.innerHTML = `<tr><td colspan="7" class="muted">PR tidak ada item.</td></tr>`;
      return;
    }
    data.items.forEach(it=>{
      tbody.insertAdjacentHTML('beforeend', rowHtml(it));
    });
    bindPriceInputs();
    recalcTotal();
  }catch(e){
    tbody.innerHTML = `<tr><td colspan="7" class="muted">Error: ${e}</td></tr>`;
  }
}

document.getElementById('pr_id').addEventListener('change', (e)=>{
  const pr_id = e.target.value;
  if (!pr_id) return;
  loadPR(pr_id);
});

function addRowManual(){
  if (!CAN_CREATE) return;
  // blank row with first product
  const first = PRODUCTS[0] || {id:'',sku:'',products_name:'',unit:'pcs'};
  const it = {product_id:first.id, sku:first.sku||'', name:(first.products_name||'').toUpperCase(), qty:'', unit:first.unit||'pcs', unit_price:0};
  const tbody = document.querySelector('#itemsTable tbody');
  if (tbody.querySelector('.muted')) tbody.innerHTML='';
  tbody.insertAdjacentHTML('beforeend', rowHtml(it));
  bindPriceInputs(); recalcTotal();
}
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
  new DataTable('#poTable', { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'] });
</script>

<script>
(function () {
  var token = <?= json_encode((string)csrf_token()) ?>;
  document.querySelectorAll('form[method="post"]').forEach(function (f) {
    if (!f.querySelector('input[name="csrf_token"]')) {
      var i = document.createElement('input');
      i.type = 'hidden';
      i.name = 'csrf_token';
      i.value = token;
      f.appendChild(i);
    }
  });
})();
</script>
<?php rmi_footer(); ?>
