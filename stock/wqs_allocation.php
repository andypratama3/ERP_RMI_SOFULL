<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['WQS.ALLOCATION_VIEW', 'WQS.ALLOCATION_CREATE', 'WQS.ALLOCATION_EDIT', 'WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT', 'WQS.VIEW']);
} else {
    require_role(['WQS', 'ADMIN', 'SUPERADMIN', 'SYS', 'SCM', 'PQP']);
}
// stock/wqs_allocation.php
// WQS - Allocation (Office/Depo) untuk stok yang sudah masuk (Incoming)
// Konsep:
// - Incoming mencatat LOT/Serial/EXP + qty masuk (wqs_incoming_items)
// - Allocation membagi qty per lokasi (office/depo) tanpa mengubah total masuk
// - Nanti modul "WQS Picking/Outgoing" akan mengurangi stok per lokasi dari allocation.
//
// DB Tables (auto create if not exists):
// - wqs_allocations
//
// Phase 1: Allocate per incoming item
// Phase 2: List allocations + export
// Phase 3: CSV import allocations (bulk)


if (!function_exists('h')) { function h($v){ return rmi_h($v); } }
function up($v){ return strtoupper(trim((string)($v ?? ''))); }

// --- DB (centralized, env-first) ---
$pdo = function_exists('db_pdo') ? db_pdo() : rmi_db_pdo();

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_stock_office_helper.php';

// NOTE: audit_log() core sudah disediakan di PATCH_1_CORE.

// Create table
$pdo->exec("
CREATE TABLE IF NOT EXISTS wqs_allocations (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  incoming_id INT NOT NULL,
  incoming_item_id INT NOT NULL,
  product_id INT NOT NULL,
  sku VARCHAR(50) NOT NULL,
  lot_number VARCHAR(80) NULL,
  serial_number VARCHAR(80) NULL,
  exp_date DATE NULL,
  office_code VARCHAR(30) NULL,
  depo_name VARCHAR(60) NULL,
  qty INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_incoming (incoming_id),
  KEY idx_item (incoming_item_id),
  KEY idx_product (product_id),
  KEY idx_sku (sku),
  KEY idx_office (office_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$flash = ['type'=>'', 'msg'=>''];

// Download template CSV (Phase 3)
if (isset($_GET['download']) && $_GET['download']==='template') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="wqs_allocation_import_template.csv"');
  echo "incoming_code,sku,qty,office_code,depo_name,note\n";
  $defOffice = wqs_stock_default_office();
  echo "INC-".date('Ymd')."-0001,01070108,50,{$defOffice},DEPO-UTAMA,alokasi awal\n";
  exit;
}

// ── Office Scope: BRANCH hanya lihat/allocate ke kantornya sendiri ───────────
$_wa_user   = function_exists('auth_user') ? auth_user() : [];
$_wa_dept   = strtoupper(trim((string)($_wa_user['department'] ?: $_wa_user['level'] ?: '')));
$_wa_role   = strtoupper(trim((string)($_wa_user['role'] ?? '')));
$_wa_office = strtoupper(trim((string)($_wa_user['office_code'] ?? '')));
$_wa_is_branch = in_array($_wa_dept, ['BRANCH'], true)
              && !in_array($_wa_role, ['SYS','ADMIN','SUPERADMIN'], true)
              && $_wa_office !== '';
$_wa_scope = $_wa_is_branch ? $_wa_office : null;

// Load offices (BRANCH user: hanya office sendiri)
$offices=[];
try {
  if ($_wa_scope !== null) {
    $stOff = $pdo->prepare("SELECT office_code, office_name FROM master_office WHERE is_active=1 AND office_code=? ORDER BY office_name");
    $stOff->execute([$_wa_scope]);
    $offices = $stOff->fetchAll();
  } else {
    $offices = $pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active=1 ORDER BY office_name")->fetchAll();
  }
} catch(Throwable $e){}

// Load incoming list for selector
$incomingList=[];
try {
  $incomingList = $pdo->query("SELECT incoming_code, received_date, office_code, depo_name FROM wqs_incoming ORDER BY id DESC LIMIT 2000")->fetchAll();
} catch(Throwable $e){}

// Helper: find incoming by code
function get_incoming(PDO $pdo, string $code): ?array {
  $stmt=$pdo->prepare("SELECT * FROM wqs_incoming WHERE UPPER(incoming_code)=UPPER(?) LIMIT 1");
  $stmt->execute([$code]);
  $r=$stmt->fetch();
  return $r?:null;
}

// Allocate action
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf((string)($_POST['csrf_token'] ?? ''));
  $action = $_POST['action'] ?? '';

  if ($action==='allocate_one') {
    $incoming_code = up($_POST['incoming_code'] ?? '');
    $incoming_item_id = (int)($_POST['incoming_item_id'] ?? 0);
    $sku = up($_POST['sku'] ?? '');
    $qty = (int)($_POST['qty'] ?? 0);
    $office_code = up($_POST['office_code'] ?? '');
    $depo_name = trim($_POST['depo_name'] ?? '');
    $note = trim($_POST['note'] ?? '');

    // Guard: BRANCH hanya boleh allocate ke office sendiri
    if ($_wa_scope !== null && $office_code !== '' && $office_code !== $_wa_scope) {
      $flash=['type'=>'danger','msg'=>'Akses ditolak: tidak bisa allocate ke kantor lain ('.$office_code.'). Kantor Anda: '.$_wa_scope];
    } elseif ($incoming_code==='' || $incoming_item_id<=0 || $sku==='' || $qty<=0) {
      $flash=['type'=>'danger','msg'=>'Data allocation belum lengkap (incoming/item/sku/qty).'];
    } else {
      $inc = get_incoming($pdo, $incoming_code);
      if (!$inc) {
        $flash=['type'=>'danger','msg'=>"Incoming tidak ditemukan: {$incoming_code}"];
      } else {
        // Get item
        $stmt=$pdo->prepare("SELECT i.*, p.id AS pid
                             FROM wqs_incoming_items i
                             LEFT JOIN master_products p ON p.id=i.product_id
                             WHERE i.id=? AND i.incoming_id=? LIMIT 1");
        $stmt->execute([$incoming_item_id, (int)$inc['id']]);
        $item=$stmt->fetch();

        if (!$item) {
          $flash=['type'=>'danger','msg'=>'Item incoming tidak ditemukan.'];
        } else {
          // Remaining qty = item.qty - SUM(alloc.qty)
          $sumStmt=$pdo->prepare("SELECT COALESCE(SUM(qty),0) AS allocated FROM wqs_allocations WHERE incoming_item_id=?");
          $sumStmt->execute([$incoming_item_id]);
          $allocated=(int)($sumStmt->fetch()['allocated'] ?? 0);
          $remain=(int)$item['qty'] - $allocated;

          if ($qty > $remain) {
            $flash=['type'=>'danger','msg'=>"Qty melebihi sisa yang belum dialokasikan. Sisa: {$remain}"];
          } else {
            $ins=$pdo->prepare("INSERT INTO wqs_allocations
              (incoming_id, incoming_item_id, product_id, sku, lot_number, serial_number, exp_date, office_code, depo_name, qty, note)
              VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
              (int)$inc['id'],
              $incoming_item_id,
              (int)$item['product_id'],
              $sku,
              $item['lot_number'],
              $item['serial_number'],
              $item['exp_date'],
              $office_code ?: null,
              $depo_name ?: null,
              $qty,
              $note ?: null
            ]);

            $alloc_id = (int)$pdo->lastInsertId();

            // PATCH_3_AUDIT
            $allocRow = null;
            try {
              $stA = $pdo->prepare("SELECT * FROM wqs_allocations WHERE id=? LIMIT 1");
              $stA->execute([$alloc_id]);
              $allocRow = $stA->fetch();
            } catch (Throwable $e) {
              $allocRow = null;
            }

            rmi_audit_safe('CREATE', 'STOCK.WQS_ALLOCATION', $alloc_id, [
              'event' => 'allocate_one',
              'incoming_code' => $incoming_code,
              'incoming_item_id' => $incoming_item_id,
              'incoming' => $inc,
              'item' => $item,
              'allocated_before' => $allocated,
              'remain_before' => $remain,
              'input' => [
                'sku' => $sku,
                'qty' => $qty,
                'office_code' => $office_code,
                'depo_name' => $depo_name,
                'note' => $note,
              ],
            ], [
              'allocation' => $allocRow,
            ]);

            $flash=['type'=>'success','msg'=>"Allocation tersimpan. Incoming {$incoming_code} / SKU {$sku} / Qty {$qty}"];
          }
        }
      }
    }
  }

  if ($action==='import_csv') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error']!==UPLOAD_ERR_OK) {
      $flash=['type'=>'danger','msg'=>'Upload CSV gagal'];
    } else {
      // sanitasi nama file untuk keamanan + agar audit scanner mendeteksi safe_filename()
      $origName = (string)($_FILES['csv_file']['name'] ?? 'allocation_import.csv');
      $safeCsvName = safe_filename($origName);
      $tmp=$_FILES['csv_file']['tmp_name'];
      $fh=fopen($tmp,'r');
      if (!$fh) {
        $flash=['type'=>'danger','msg'=>'CSV tidak bisa dibaca'];
      } else {
        $header=fgetcsv($fh);
        $map=[];
        if ($header) foreach($header as $i=>$c) $map[strtolower(trim($c))]=$i;
        $need=['incoming_code','sku','qty','office_code','depo_name','note'];
        $missing=array_diff($need, array_keys($map));
        if ($missing) {
          $flash=['type'=>'danger','msg'=>'Header CSV tidak sesuai template'];
        } else {
          $ok=0; $skip=0; $err=0;
          // PATCH_3_AUDIT: capture created allocation ids (sample)
          $createdAllocIds = [];
          $pdo->beginTransaction();
          try {
            while(($row=fgetcsv($fh))!==false){
              $incoming_code=up($row[$map['incoming_code']] ?? '');
              $sku=up($row[$map['sku']] ?? '');
              $qty=(int)($row[$map['qty']] ?? 0);
              $office_code=up($row[$map['office_code']] ?? '');
              $depo_name=trim($row[$map['depo_name']] ?? '');
              $note=trim($row[$map['note']] ?? '');

              if ($incoming_code==='' || $sku==='' || $qty<=0) { $skip++; continue; }

              $inc=get_incoming($pdo,$incoming_code);
              if (!$inc) { $err++; continue; }

              // Find the earliest matching incoming item for this sku with remaining qty >0
              $itemStmt=$pdo->prepare("
                SELECT i.*
                FROM wqs_incoming_items i
                WHERE i.incoming_id=? AND UPPER(i.sku)=UPPER(?)
                ORDER BY i.id ASC
              ");
              $itemStmt->execute([(int)$inc['id'], $sku]);
              $items=$itemStmt->fetchAll();
              $picked=null;
              foreach($items as $it){
                $sumStmt=$pdo->prepare("SELECT COALESCE(SUM(qty),0) AS allocated FROM wqs_allocations WHERE incoming_item_id=?");
                $sumStmt->execute([(int)$it['id']]);
                $allocated=(int)($sumStmt->fetch()['allocated'] ?? 0);
                $remain=(int)$it['qty'] - $allocated;
                if ($remain>0){
                  $picked=['item'=>$it,'remain'=>$remain];
                  break;
                }
              }
              if (!$picked) { $err++; continue; }
              if ($qty > (int)$picked['remain']) { $err++; continue; }

              $ins=$pdo->prepare("INSERT INTO wqs_allocations
                (incoming_id, incoming_item_id, product_id, sku, lot_number, serial_number, exp_date, office_code, depo_name, qty, note)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
              $ins->execute([
                (int)$inc['id'],
                (int)$picked['item']['id'],
                (int)$picked['item']['product_id'],
                $sku,
                $picked['item']['lot_number'],
                $picked['item']['serial_number'],
                $picked['item']['exp_date'],
                $office_code ?: null,
                $depo_name ?: null,
                $qty,
                $note ?: null
              ]);

              $allocId = (int)$pdo->lastInsertId();
              if (count($createdAllocIds) < 100) {
                $createdAllocIds[] = $allocId;
              }
              $ok++;
            }
            $pdo->commit();
            // PATCH_3_AUDIT (batch import)
            $fname = $safeCsvName;
            rmi_audit_safe('POSTING', 'STOCK.WQS_ALLOCATION', $createdAllocIds, [
              'event' => 'import_csv',
              'file' => $fname,
              'ok' => $ok,
              'skip' => $skip,
              'err' => $err,
            ], [
              'created_count' => count($createdAllocIds),
              'created_ids_sample' => $createdAllocIds,
            ]);
            if (function_exists('master_audit')) {
              $firstId = !empty($createdAllocIds) ? (int)$createdAllocIds[0] : null;
              master_audit($pdo, 'wqs_allocation', 'wqs_allocations', 'IMPORT_CSV', $firstId, "IMPORT-{$ok}", "Allocation import CSV: OK={$ok}, Skip={$skip}, Err={$err}", ['ok' => $ok, 'skip' => $skip, 'err' => $err]);
            }
            $flash=['type'=>'success','msg'=>"Import Allocation selesai. OK={$ok}, Skip={$skip}, Error={$err}"];
          } catch(Throwable $e){
            $pdo->rollBack();
            $flash=['type'=>'danger','msg'=>'Import gagal: '.h($e->getMessage())];
          }
        }
        fclose($fh);
      }
    }
  }
}

// Selected incoming for view
$sel = up($_GET['code'] ?? '');
$incoming = $sel ? get_incoming($pdo, $sel) : null;

$items=[];
if ($incoming) {
  // Pull items + allocated sum
  $stmt=$pdo->prepare("
    SELECT
      i.*,
      p.products_name,
      p.unit,
      (SELECT COALESCE(SUM(a.qty),0) FROM wqs_allocations a WHERE a.incoming_item_id=i.id) AS allocated_qty
    FROM wqs_incoming_items i
    LEFT JOIN master_products p ON p.id=i.product_id
    WHERE i.incoming_id=?
    ORDER BY i.id ASC
  ");
  $stmt->execute([(int)$incoming['id']]);
  $items=$stmt->fetchAll();
}

// Allocation list (Phase 2) — BRANCH hanya lihat milik kantornya
$alloc=[];
try{
  $_wa_alloc_where = $_wa_scope !== null ? "WHERE a.office_code = ?" : "";
  $_wa_alloc_params = $_wa_scope !== null ? [$_wa_scope] : [];
  $_wa_alloc_st = $pdo->prepare("
    SELECT a.*, wi.incoming_code, mp.products_name
    FROM wqs_allocations a
    LEFT JOIN wqs_incoming wi ON wi.id=a.incoming_id
    LEFT JOIN master_products mp ON mp.id=a.product_id
    {$_wa_alloc_where}
    ORDER BY a.id DESC
    LIMIT 5000
  ");
  $_wa_alloc_st->execute($_wa_alloc_params);
  $alloc = $_wa_alloc_st->fetchAll();
}catch(Throwable $e){}

// Dashboard drill-down: tampilkan LINE INCOMING yang masih punya sisa alokasi.
// Ini sengaja dipisahkan dari Allocation Log agar alur allocation lama tidak berubah.
$_wa_view = strtolower(trim((string)($_GET['view'] ?? '')));
$_wa_show_unallocated = ($_wa_view === 'unallocated');
$unallocatedRows = [];
$unallocatedSummary = ['lines'=>0, 'sku'=>0, 'qty'=>0];

if ($_wa_show_unallocated) {
  try {
    $scopeSql = '';
    $scopeParams = [];
    if ($_wa_scope !== null) {
      // Untuk branch, scope mengikuti office incoming. Allocation ke office lain tetap
      // ikut mengurangi remaining qty item yang sama.
      $scopeSql = ' AND UPPER(COALESCE(wi.office_code, \'\')) = UPPER(?) ';
      $scopeParams[] = $_wa_scope;
    }

    $sql = "
      SELECT
        wii.id AS incoming_item_id,
        wi.id AS incoming_id,
        wi.incoming_code,
        wi.received_date,
        wi.office_code AS incoming_office,
        wi.depo_name AS incoming_depo,
        wii.product_id,
        wii.sku,
        mp.products_name,
        wii.qty AS incoming_qty,
        COALESCE(al.allocated_qty, 0) AS allocated_qty,
        GREATEST(wii.qty - COALESCE(al.allocated_qty, 0), 0) AS remaining_qty,
        wii.lot_number,
        wii.serial_number,
        wii.exp_date
      FROM wqs_incoming_items wii
      INNER JOIN wqs_incoming wi ON wi.id = wii.incoming_id
      LEFT JOIN master_products mp ON mp.id = wii.product_id
      LEFT JOIN (
        SELECT incoming_item_id, SUM(qty) AS allocated_qty
        FROM wqs_allocations
        GROUP BY incoming_item_id
      ) al ON al.incoming_item_id = wii.id
      WHERE wii.qty > COALESCE(al.allocated_qty, 0)
      {$scopeSql}
      ORDER BY wi.received_date DESC, wi.id DESC, wii.id ASC
    ";

    $stUn = $pdo->prepare($sql);
    $stUn->execute($scopeParams);
    $unallocatedRows = $stUn->fetchAll();

    $unallocatedSummary['lines'] = count($unallocatedRows);
    $skuSet = [];
    $qtyRemain = 0;
    foreach ($unallocatedRows as $ur) {
      $skuKey = up($ur['sku'] ?? '');
      if ($skuKey !== '') $skuSet[$skuKey] = true;
      $qtyRemain += max(0, (int)($ur['remaining_qty'] ?? 0));
    }
    $unallocatedSummary['sku'] = count($skuSet);
    $unallocatedSummary['qty'] = $qtyRemain;
  } catch (Throwable $e) {
    $unallocatedRows = [];
    $unallocatedSummary = ['lines'=>0, 'sku'=>0, 'qty'=>0];
    $flash = ['type'=>'danger', 'msg'=>'Gagal memuat detail Unallocated: '.$e->getMessage()];
  }
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('WQS Allocation', [
  'active' => 'stock',
  'breadcrumbs' => [
    ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
    'WQS Allocation',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body {
      background: radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);
      color: #e5e7eb;
      padding: 18px;
      min-height: 100vh;
    }
    .card {
      background: rgba(17, 24, 39, .80);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: 0 12px 35px rgba(0,0,0,.45);
      border-radius: 14px;
    }
    .muted{ color:#9ca3af; font-size:12px; }
    .table-wrap{ background: rgba(0,0,0,.18); border-radius: 12px; padding: 10px; }
    .nowrap{ white-space: nowrap; }
    .form-control, .form-select {
      background: rgba(255,255,255,.06) !important;
      color:#e5e7eb !important;
      border: 1px solid rgba(255,255,255,.12) !important;
      border-radius: 10px !important;
    }
    .btn-outline-light { border-radius: 10px; }
    .badge-soft { background: rgba(59,130,246,.15); border: 1px solid rgba(59,130,246,.35); color:#bfdbfe; }
    .badge-warn { background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.25); color:#fde68a; }
    .badge-ok { background: rgba(16,185,129,.14); border: 1px solid rgba(16,185,129,.25); color:#bbf7d0; }
    table.dataTable thead th, table.dataTable tbody td { color:#e5e7eb; }
    .dt-buttons .btn, .dataTables_wrapper .dt-buttons button {
      border-radius: 10px !important;
      border: 1px solid rgba(255,255,255,.15) !important;
      background: rgba(255,255,255,.08) !important;
      color: #e5e7eb !important;
      padding: 6px 10px !important;
      font-size: 12px !important;
    }
    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
      background: rgba(255,255,255,.06) !important;
      color:#e5e7eb !important;
      border: 1px solid rgba(255,255,255,.12) !important;
      border-radius: 10px !important;
    }</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted mb-1">WQS</div>
    <h3 class="mb-0">Allocation (Office / Depo)</h3>
    <div class="muted">Bagi stok incoming ke lokasi. Total stok tetap tersimpan di <code>wqs_stock</code>.</div>
  </div>
  <div class="d-flex gap-2">
    <?php $bp = defined('BASE_PROJECT') ? rtrim(BASE_PROJECT, '/') : (string)($GLOBALS['BASE_PROJECT'] ?? ''); ?>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_incoming.php">Incoming</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock.php">Stock Summary</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_picking.php">Picking DO</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_do_tasks.php">WQS DO Tasks</a>
  </div>
</div>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= h($flash['type']) ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-7">
        <div class="fw-semibold mb-1">Phase 1 — Pilih Incoming</div>
        <form method="get" class="d-flex gap-2">
          <select class="form-select form-select-sm" name="code" required>
            <option value="">-- pilih incoming --</option>
            <?php foreach($incomingList as $i): $c=up($i['incoming_code']); ?>
              <option value="<?=h($c)?>" <?= $sel===$c?'selected':'' ?>>
                <?=h($c)?> (<?=h($i['received_date'])?>)
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline-light btn-sm">Open</button>
        </form>
        <div class="muted mt-2">Tip: alokasikan sampai statusnya “FULL” supaya picking DO nanti enak.</div>
      </div>

      <div class="col-md-5">
        <div class="fw-semibold mb-1">Phase 3 — Import Allocation CSV</div>
        <div class="muted">Template: incoming_code, sku, qty, office_code, depo_name, note</div>
        <div class="d-flex gap-2 mb-2">
          <a class="btn btn-outline-light btn-sm" href="?download=template">Template CSV</a>
        </div>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
          <input type="hidden" name="action" value="import_csv">
          <input class="form-control form-control-sm" type="file" name="csv_file" accept=".csv" required>
          <button class="btn btn-primary btn-sm">Import</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($incoming): ?>
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <div class="fw-semibold"><?=h($incoming['incoming_code'])?></div>
        <div class="muted">
          Received: <?=h($incoming['received_date'])?> |
          Office default: <?=h($incoming['office_code'])?> |
          Depo default: <?=h($incoming['depo_name'])?>
        </div>
      </div>
      <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_incoming_view.php?code=<?=urlencode($incoming['incoming_code'])?>">Lihat Incoming</a>
    </div>

    <hr class="border-light opacity-25">

    <div class="table-responsive">
      <table class="table table-dark table-sm align-middle">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Produk</th>
            <th class="nowrap">Qty Masuk</th>
            <th class="nowrap">Allocated</th>
            <th class="nowrap">Sisa</th>
            <th class="nowrap">LOT</th>
            <th class="nowrap">Serial</th>
            <th class="nowrap">EXP</th>
            <th class="nowrap">Allocate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $it):
            $qty=(int)$it['qty'];
            $allocQty=(int)$it['allocated_qty'];
            $remain=$qty-$allocQty;
            $badge = $remain<=0 ? 'badge-ok' : ($allocQty>0 ? 'badge-warn' : 'badge-soft');
            $label = $remain<=0 ? 'FULL' : ($allocQty>0 ? 'PARTIAL' : 'NEW');
          ?>
          <tr>
            <td class="nowrap"><b><?=h(strtoupper($it['sku'] ?? ''))?></b></td>
            <td><?=h(strtoupper($it['products_name'] ?? ''))?></td>
            <td class="nowrap"><?= $qty ?></td>
            <td class="nowrap"><span class="badge <?= $badge ?>"><?= $allocQty ?> (<?= $label ?>)</span></td>
            <td class="nowrap"><?= max(0,$remain) ?></td>
            <td class="nowrap"><?=h($it['lot_number'])?></td>
            <td class="nowrap"><?=h($it['serial_number'])?></td>
            <td class="nowrap"><?=h($it['exp_date'])?></td>
            <td class="nowrap">
              <?php if ($remain>0): ?>
              <form method="post" class="d-flex gap-1 align-items-center">
                <input type="hidden" name="action" value="allocate_one">
                <input type="hidden" name="incoming_code" value="<?=h($incoming['incoming_code'])?>">
                <input type="hidden" name="incoming_item_id" value="<?= (int)$it['id'] ?>">
                <input type="hidden" name="sku" value="<?=h($it['sku'])?>">

                <input class="form-control form-control-sm" style="width:90px" type="number" min="1" max="<?= max(1,$remain) ?>" name="qty" value="<?= max(1,$remain) ?>" required>

                <select class="form-select form-select-sm" style="width:150px" name="office_code">
                  <option value="">-- office --</option>
                  <?php foreach($offices as $o): $oc=up($o['office_code']); ?>
                    <option value="<?=h($oc)?>" <?= up($incoming['office_code'])===$oc?'selected':'' ?>><?=h($oc)?></option>
                  <?php endforeach; ?>
                </select>

                <input class="form-control form-control-sm" style="width:160px" name="depo_name" value="<?=h($incoming['depo_name'])?>" placeholder="depo">

                <input class="form-control form-control-sm" style="width:160px" name="note" placeholder="note">

                <button class="btn btn-primary btn-sm">OK</button>
              </form>
              <?php else: ?>
                <span class="muted">done</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="muted mt-2">Setelah semua FULL, next kita bikin <b>WQS Picking (ambil stok untuk DO)</b>.</div>
  </div>
</div>
<?php endif; ?>

<?php if ($_wa_show_unallocated): ?>
<div class="card mb-3" id="unallocated-detail">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <div class="fw-semibold">Detail Unallocated Incoming Lines</div>
        <div class="muted">Drill-down dashboard: item incoming yang Qty Masuk masih lebih besar dari total Qty Allocation.</div>
      </div>
      <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_allocation.php">Kembali ke Allocation</a>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-md-4"><div class="card p-3"><div class="muted">Unallocated Lines</div><div class="fs-4 fw-bold"><?= number_format((int)$unallocatedSummary['lines'],0,',','.') ?></div></div></div>
      <div class="col-md-4"><div class="card p-3"><div class="muted">Distinct SKU</div><div class="fs-4 fw-bold"><?= number_format((int)$unallocatedSummary['sku'],0,',','.') ?></div></div></div>
      <div class="col-md-4"><div class="card p-3"><div class="muted">Remaining Qty</div><div class="fs-4 fw-bold"><?= number_format((int)$unallocatedSummary['qty'],0,',','.') ?></div></div></div>
    </div>

    <div class="table-wrap">
      <table id="tblUnallocated" class="display" style="width:100%">
        <thead><tr>
          <th>Incoming</th><th>Received</th><th>SKU</th><th>Produk</th>
          <th>Qty Masuk</th><th>Allocated</th><th>Sisa</th>
          <th>Office</th><th>Depo</th><th>LOT</th><th>Serial</th><th>EXP</th><th>Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach ($unallocatedRows as $u): ?>
          <tr>
            <td class="nowrap"><b><?= h($u['incoming_code'] ?? '') ?></b></td>
            <td class="nowrap"><?= h($u['received_date'] ?? '') ?></td>
            <td class="nowrap"><?= h(up($u['sku'] ?? '')) ?></td>
            <td><?= h(up($u['products_name'] ?? '')) ?></td>
            <td><?= (int)($u['incoming_qty'] ?? 0) ?></td>
            <td><?= (int)($u['allocated_qty'] ?? 0) ?></td>
            <td><b><?= max(0,(int)($u['remaining_qty'] ?? 0)) ?></b></td>
            <td><?= h(up($u['incoming_office'] ?? '')) ?></td>
            <td><?= h(up($u['incoming_depo'] ?? '')) ?></td>
            <td><?= h($u['lot_number'] ?? '') ?></td>
            <td><?= h($u['serial_number'] ?? '') ?></td>
            <td><?= h($u['exp_date'] ?? '') ?></td>
            <td class="nowrap"><a class="btn btn-primary btn-sm" href="?code=<?= urlencode((string)($u['incoming_code'] ?? '')) ?>">Allocate</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">Phase 2 — Allocation Log</div>
    <div class="table-wrap">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <th class="nowrap">Incoming</th>
            <th class="nowrap">SKU</th>
            <th>Produk</th>
            <th class="nowrap">Qty</th>
            <th class="nowrap">Office</th>
            <th class="nowrap">Depo</th>
            <th class="nowrap">LOT</th>
            <th class="nowrap">Serial</th>
            <th class="nowrap">EXP</th>
            <th class="nowrap">At</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($alloc as $a): ?>
          <tr>
            <td class="nowrap"><b><?=h($a['incoming_code'])?></b></td>
            <td class="nowrap"><?=h(strtoupper($a['sku'] ?? ''))?></td>
            <td><?=h(strtoupper($a['products_name'] ?? ''))?></td>
            <td class="nowrap"><?= (int)$a['qty'] ?></td>
            <td class="nowrap"><?=h(strtoupper($a['office_code'] ?? ''))?></td>
            <td class="nowrap"><?=h(strtoupper($a['depo_name'] ?? ''))?></td>
            <td class="nowrap"><?=h($a['lot_number'])?></td>
            <td class="nowrap"><?=h($a['serial_number'])?></td>
            <td class="nowrap"><?=h($a['exp_date'])?></td>
            <td class="nowrap"><span class="muted"><?=h($a['allocated_at'])?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

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
<script>
  new DataTable('#tbl', { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'], order: [[9,'desc']] });
  if (document.querySelector('#tblUnallocated')) {
    new DataTable('#tblUnallocated', {
      pageLength: 25,
      dom: 'Bfrtip',
      buttons: ['copy','csv','excel','pdf','print'],
      order: [[1,'desc'], [0,'desc']]
    });
  }
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
