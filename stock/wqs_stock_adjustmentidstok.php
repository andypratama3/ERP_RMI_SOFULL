<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

require_once __DIR__ . '/../bootstrap.php';
require_login();
// stock/wqs_stock_adjustment.php
// Enterprise: Stock Adjustment (audit-friendly correction)
require_once __DIR__ . '/_wqs_bootstrap.php';
require_once __DIR__ . '/_stock_office_helper.php';

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';

if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('up')) { function up($s): string { return strtoupper(trim((string)$s)); } }
// Manager-only module
require_any_permission(['STOCK.CREATE']);

// Compat helpers: some legacy installs may not load _shared/db.php correctly.
// Keep these functions guarded to avoid redeclare.
if (!function_exists('table_cols')) {
  function table_cols(PDO $pdo, string $table): array {
    $table = trim($table);
    if ($table === '') return [];
    try {
      $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
      $st->execute([$table]);
      $cols = $st->fetchAll(PDO::FETCH_COLUMN, 0);
      return is_array($cols) ? $cols : [];
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('pick_col')) {
  function pick_col(array $cols, array $candidates, $fallback = null) {
    $map = [];
    foreach ($cols as $c) {
      if (!is_string($c)) continue;
      $map[strtolower($c)] = $c;
    }
    foreach ($candidates as $cand) {
      if (!is_string($cand) || $cand === '') continue;
      $k = strtolower($cand);
      if (isset($map[$k])) return $map[$k];
    }
    return $fallback;
  }
}

// NOTE: audit_log() core sudah disediakan di PATCH_1_CORE.
// --- KONEKSI DB --- (prefer project config, fallback local)
$pdo = db_pdo();

// RBAC: write actions require STOCK.CREATE
$can_adjust = true;
if (function_exists('rbac_can')) {
  try { $can_adjust = rbac_can($pdo, 'STOCK.CREATE'); } catch (Throwable $e) { $can_adjust = false; }
} elseif (function_exists('rbac_can2')) {
  $can_adjust = rbac_can2('STOCK.CREATE');
}


function table_exists(PDO $pdo, string $table): bool {
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn()) > 0;
  } catch (Throwable $e) {
    return false;
  }
}

$schemaErrors = [];
foreach (['wqs_stock', 'wqs_stock_adjustments', 'wqs_stock_baseline_lock'] as $tableName) {
  if (!table_exists($pdo, $tableName)) {
    $schemaErrors[] = "Tabel {$tableName} belum tersedia. Jalankan migration terbaru.";
  }
}
$baseline_lock = false;
try {
  if (table_exists($pdo, 'wqs_stock_baseline_lock')) {
    $baseline_lock = $pdo->query("SELECT * FROM wqs_stock_baseline_lock ORDER BY id DESC LIMIT 1")->fetch();
  }
} catch (Throwable $e) {
  $baseline_lock = false;
}
$baseline_locked = $baseline_lock ? true : false;
// ===== Master Products resolver (kolom bisa beda antar project) =====
$MP_TABLE = 'master_products';
$mpCols = [];
try {
  $mpCols = table_cols($pdo, $MP_TABLE);
} catch (Throwable $e) {
  // fallback legacy
  $MP_TABLE = 'products';
  $mpCols = table_cols($pdo, $MP_TABLE);
}

$MP_ID_COL       = pick_col($mpCols, ['id','product_id'], 'id');
$MP_SKU_COL      = pick_col($mpCols, ['sku','product_code','code'], 'sku');
// Kalau tidak ada kolom nama, fallback pakai sku supaya query tetap jalan
$MP_NAME_COL     = pick_col($mpCols, ['products_name','product_name','name','item_name','product','nama','nama_barang','product_title','title','description'], $MP_SKU_COL);

$MP_UNIT_COL     = pick_col($mpCols, ['unit','uom','unit_name'], null);
$MP_BARCODE_COL  = pick_col($mpCols, ['barcode','ean','upc'], null);
$MP_MNF_COL      = pick_col($mpCols, ['manufacture_code','mnf','mfg_code','manufacture','manufacturer_code'], null);
$MP_TYPE_COL     = pick_col($mpCols, ['product_type','type','tipe','product_type_name','category','kategori'], null);

function get_product_by_sku(PDO $pdo, string $sku, string $MP_TABLE, string $MP_ID_COL, string $MP_SKU_COL, string $MP_NAME_COL, ?string $MP_UNIT_COL=null, ?string $MP_BARCODE_COL=null, ?string $MP_MNF_COL=null, ?string $MP_TYPE_COL=null): ?array {
  $select = [
    "p.`{$MP_ID_COL}` AS id",
    "p.`{$MP_SKU_COL}` AS sku",
    "p.`{$MP_NAME_COL}` AS product_name",
  ];
  $select[] = $MP_UNIT_COL ? "p.`{$MP_UNIT_COL}` AS unit" : "NULL AS unit";
  $select[] = $MP_BARCODE_COL ? "p.`{$MP_BARCODE_COL}` AS barcode" : "NULL AS barcode";
  $select[] = $MP_MNF_COL ? "p.`{$MP_MNF_COL}` AS manufacture_id" : "NULL AS manufacture_id";
  $select[] = $MP_TYPE_COL ? "p.`{$MP_TYPE_COL}` AS product_type" : "NULL AS product_type";

  $sql = 'SELECT ' . implode(', ', $select) . " FROM {$MP_TABLE} p WHERE p.`{$MP_SKU_COL}` = ? LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$sku]);
  $r = $st->fetch();
  return $r ?: null;
}
function get_stock(PDO $pdo, int $pid, string $officeCode = ''): float {
  if ($officeCode !== '' && function_exists('wqs_stock_office_get')) {
    return wqs_stock_office_get($pdo, $pid, $officeCode);
  }
  $stmt=$pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
  $stmt->execute([$pid]);
  $r=$stmt->fetch();
  return $r?(float)$r['stock_qty']:0.0;
}
function set_stock(PDO $pdo, int $pid, int $qty, string $officeCode = ''): void {
  $qty=max(0,(int)$qty);
  if ($officeCode !== '' && function_exists('wqs_stock_office_apply_delta')) {
    $cur = wqs_stock_office_get($pdo, $pid, $officeCode);
    $delta = $qty - $cur;
    wqs_stock_office_apply_delta($pdo, $pid, $delta, $officeCode);
    return;
  }
  $stmt=$pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty, updated_at) VALUES (?,?,NOW())
                       ON DUPLICATE KEY UPDATE stock_qty=VALUES(stock_qty), updated_at=NOW()");
  $stmt->execute([$pid,$qty]);
}
$useStockByOffice = function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo);
$userDept = function_exists('auth_dept') ? strtoupper(auth_dept()) : '';
$userOffice = function_exists('auth_office_code') ? strtoupper(trim(auth_office_code())) : '';

$flash=['type'=>'','msg'=>''];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  require_post();
  verify_csrf();
  if ($schemaErrors) {
    $flash = ['type' => 'danger', 'msg' => implode(' ', $schemaErrors)];
  }
  $action=$_POST['action']??'';
  if (!$schemaErrors && $action==='add_adjustment') {
    // Enforce permission for write action
    if (function_exists('rbac_require')) { rbac_require($pdo, 'STOCK.CREATE'); }
    if (!$baseline_locked) {
      $flash=['type'=>'warning','msg'=>'Stock Snapshot belum di-lock. Adjustment disarankan setelah Lock (go-live).'];
    }
    $sku = up($_POST['sku']??'');
    $delta = (int)($_POST['delta_qty']??0);
    $reason = trim((string)($_POST['reason']??''));
    $office_code = up($_POST['office_code'] ?? '');
    if ($useStockByOffice) {
      if ($office_code === '') $office_code = wqs_stock_default_office();
      if ($userDept === 'BRANCH' && $userOffice !== '' && $office_code !== $userOffice) {
        $flash=['type'=>'danger','msg'=>'Staff BRANCH hanya boleh adjustment untuk kantor ' . $userOffice . '.'];
        $office_code = '';
      }
    }
    if ($sku==='' || $delta===0 || $reason==='') {
      $flash=['type'=>'danger','msg'=>'SKU, Delta Qty (tidak boleh 0), dan Reason wajib diisi.'];
    } elseif ($useStockByOffice && $office_code === '') {
      $flash=['type'=>'danger','msg'=>'Office tidak valid untuk adjustment.'];
    } else {
      $actorRole = strtoupper((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? ''));
      if ($delta < 0 && !in_array($actorRole, ['ADMIN','SUPERADMIN'], true)) {
        $flash=['type'=>'danger','msg'=>'Pengurangan stok (delta negatif) hanya boleh oleh ADMIN/SUPERADMIN (maker-checker guard).'];
      } else {
      $p = get_product_by_sku($pdo, $sku, $MP_TABLE, $MP_ID_COL, $MP_SKU_COL, $MP_NAME_COL, $MP_UNIT_COL, $MP_BARCODE_COL, $MP_MNF_COL, $MP_TYPE_COL);
      if (!$p) {
        $flash=['type'=>'danger','msg'=>"SKU {$sku} tidak ditemukan di master_products."];
      } else {
        $pid=(int)$p['id'];
        $current = $useStockByOffice ? get_stock($pdo, $pid, $office_code) : get_stock($pdo, $pid);
        $new=$current + $delta;
        if ($new<0) {
          $flash=['type'=>'danger','msg'=>"Stok tidak boleh minus. Current={$current}, Delta={$delta}."];
          @file_put_contents(dirname(__DIR__) . '/storage/logs/stock_anomaly_last.json', json_encode([
            'state_version' => 1,
            'ts' => date(DateTimeInterface::ATOM),
            'type' => 'NEGATIVE_STOCK_BLOCKED',
            'sku' => $sku,
            'current_qty' => $current,
            'delta_qty' => $delta,
            'actor_username' => (string)($_SESSION['username'] ?? 'SYSTEM'),
            'result' => 'FAIL',
          ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        } else {
          $pdo->beginTransaction();
          try {
            $hasOfficeCol = $useStockByOffice && in_array('office_code', table_cols($pdo, 'wqs_stock_adjustments'), true);
            if ($hasOfficeCol) {
              $stIns = $pdo->prepare("INSERT INTO wqs_stock_adjustments (product_id, sku, delta_qty, reason, office_code) VALUES (?,?,?,?,?)");
              $stIns->execute([$pid, $sku, $delta, $reason, $office_code]);
            } else {
              $stIns = $pdo->prepare("INSERT INTO wqs_stock_adjustments (product_id, sku, delta_qty, reason) VALUES (?,?,?,?)");
              $stIns->execute([$pid, $sku, $delta, $reason]);
            }
            $adj_id = (int)$pdo->lastInsertId();
            set_stock($pdo, $pid, $new, $useStockByOffice ? $office_code : '');
            $pdo->commit();

            // PATCH_3_AUDIT
            $adjRow = null;
            try {
              $stA = $pdo->prepare("SELECT * FROM wqs_stock_adjustments WHERE id=? LIMIT 1");
              $stA->execute([$adj_id]);
              $adjRow = $stA->fetch();
            } catch (Throwable $e) {
              $adjRow = null;
            }

            rmi_audit_safe('POSTING', 'STOCK.WQS_STOCK_ADJUSTMENT', $adj_id, [
              'event' => 'add_adjustment',
              'product' => $p,
              'sku' => $sku,
              'delta_qty' => $delta,
              'reason' => $reason,
              'stock_before' => $current,
              'baseline_locked' => $baseline_locked,
            ], [
              'stock_after' => $new,
              'adjustment' => $adjRow,
            ], [
              'product_id' => $pid,
            ]);
            if (function_exists('master_audit')) {
              master_audit($pdo, 'wqs_stock_adjustment', 'wqs_stock_adjustments', 'ADD_ADJUSTMENT', $adj_id, $sku, "Stock adjustment: {$sku} delta {$delta}", ['stock_before' => $current, 'stock_after' => $new, 'reason' => $reason]);
            }
            if (abs($delta) >= 50) {
              @file_put_contents(dirname(__DIR__) . '/storage/logs/stock_anomaly_last.json', json_encode([
                'state_version' => 1,
                'ts' => date(DateTimeInterface::ATOM),
                'type' => 'LARGE_STOCK_ADJUSTMENT',
                'sku' => $sku,
                'current_qty' => $current,
                'delta_qty' => $delta,
                'new_qty' => $new,
                'actor_username' => (string)($_SESSION['username'] ?? 'SYSTEM'),
                'result' => 'WARN',
              ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
            }
            $flash=['type'=>'success','msg'=>"Adjustment tersimpan. {$sku}: {$current} -> {$new} (delta {$delta})"];
          } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (function_exists('rmi_log_module_error')) rmi_log_module_error('wqs_stock_adjustment', $e, ['action' => 'ADD_ADJUSTMENT', 'sku' => $sku ?? null, 'delta' => $delta ?? null]);
            $flash=['type'=>'danger','msg'=>'Gagal simpan adjustment: '.$e->getMessage()];
          }
        }
      }
      }
    }
  }
}
// AJAX list for SKUs dropdown
if (($_GET['action'] ?? '') === 'list_skus') {
  header('Content-Type: application/json');
  try {
    // Limit to avoid huge payloads
    $limit = 500;
    $st = $pdo->query("SELECT DISTINCT `{$MP_SKU_COL}` AS sku FROM {$MP_TABLE} WHERE `{$MP_SKU_COL}` IS NOT NULL AND `{$MP_SKU_COL}` <> '' ORDER BY `{$MP_SKU_COL}` ASC LIMIT {$limit}");
    $skus = [];
    while ($row = $st->fetch()) {
      $skus[] = (string)$row['sku'];
    }
    echo json_encode(['ok' => true, 'skus' => $skus]);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'query_failed']);
  }
  exit;
}
// AJAX lookup for SKU -> product name
if (($_GET['action'] ?? '') === 'lookup_sku') {
  header('Content-Type: application/json');
  $sku = up($_GET['sku'] ?? '');
  if ($sku==='') { echo json_encode(['ok'=>false,'error'=>'empty_sku']); exit; }
  $p = get_product_by_sku($pdo, $sku, $MP_TABLE, $MP_ID_COL, $MP_SKU_COL, $MP_NAME_COL, $MP_UNIT_COL, $MP_BARCODE_COL, $MP_MNF_COL, $MP_TYPE_COL);
  if (!$p) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
  echo json_encode(['ok'=>true,'name'=>($p['product_name'] ?? ''), 'sku'=>$p['sku'], 'product_type'=>($p['product_type'] ?? ''), 'unit'=>($p['unit'] ?? ''), 'barcode'=>($p['barcode'] ?? ''), 'manufacture_id'=>($p['manufacture_id'] ?? '')]);
  exit;
}
$nameExpr = $MP_NAME_COL ? "p.`{$MP_NAME_COL}`" : "p.`{$MP_SKU_COL}`";
$officesForAdj = [];
if ($useStockByOffice) {
  try {
    $officesForAdj = $pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name")->fetchAll();
    if ($userDept === 'BRANCH' && $userOffice !== '') {
      $officesForAdj = array_values(array_filter($officesForAdj, fn($o) => strtoupper(trim($o['office_code'] ?? '')) === $userOffice));
    }
  } catch (Throwable $e) {}
}
$rows = [];
try {
  if (table_exists($pdo, 'wqs_stock_adjustments')) {
    $rows=$pdo->query("SELECT a.*, ".$nameExpr." AS product_name, "
      . ($MP_TYPE_COL ? "p.`{$MP_TYPE_COL}` AS product_type, " : "NULL AS product_type, ")
      . ($MP_UNIT_COL ? "p.`{$MP_UNIT_COL}` AS unit, " : "NULL AS unit, ")
      . ($MP_BARCODE_COL ? "p.`{$MP_BARCODE_COL}` AS barcode, " : "NULL AS barcode, ")
      . ($MP_MNF_COL ? "p.`{$MP_MNF_COL}` AS manufacture_id " : "NULL AS manufacture_id ")
      . "FROM wqs_stock_adjustments a LEFT JOIN {$MP_TABLE} p ON p.`{$MP_ID_COL}`=a.product_id ORDER BY a.id DESC LIMIT 5000")->fetchAll();
  }
} catch (Throwable $e) {
  $rows = [];
}
$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'wqs_stock_adjustment' ORDER BY created_at DESC LIMIT 50");
  $st->execute();
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('WQS Stock Adjustment', [
  'active' => 'stock',
  'breadcrumbs' => [
    ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
    'WQS Stock Adjustment',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body { background: #0b1020; color: #e8e8e8; }
    .card { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); }
    .muted { color: rgba(255,255,255,0.65); }
    .btn-ghost { border:1px solid rgba(255,255,255,0.35); color:#fff; background: transparent; }
    .btn-ghost:hover { background: rgba(255,255,255,0.08); }
    .form-control[readonly] { background: rgba(255,255,255,0.06); color: #eaeaea; cursor: not-allowed; }
    .label-muted { font-size: 12px; color: rgba(255,255,255,0.65); }
    .lookup-status { font-size: 12px; margin-top: 4px; display: inline-block; }
    .lookup-status.badge { border: 1px solid rgba(255,255,255,0.25); background: rgba(255,255,255,0.06); color: #eaeaea; }
    td.col-reason { white-space: normal; word-break: break-word; }

    /* Improve overall text contrast */
    body { color: #f2f2f2; }
    .muted, .label-muted { color: rgba(255,255,255,0.8); }
    .card { color: #f2f2f2; }

    /* Form readability */
    .form-label { color: #ffffff; font-weight: 500; }
    .form-control { color: #ffffff; background-color: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.25); }
    .form-control::placeholder { color: rgba(255,255,255,0.6); }
    .form-control[readonly] { background: rgba(255,255,255,0.12); color: #ffffff; }

    /* DataTable readability */
    table.dataTable thead th { color: #ffffff; }
    table.dataTable tbody td { color: #f2f2f2; }
    table.dataTable.stripe tbody tr.odd, table.dataTable.display tbody tr.odd { background-color: rgba(255,255,255,0.03); }
    table.dataTable.display tbody tr.even>.sorting_1, table.dataTable.order-column.stripe tbody tr.even>.sorting_1 { background-color: rgba(255,255,255,0.02); }
    table.dataTable.display tbody tr.odd>.sorting_1, table.dataTable.order-column.stripe tbody tr.odd>.sorting_1 { background-color: rgba(255,255,255,0.04); }

    /* Better line height for readability */
    body, .form-control, table { line-height: 1.4; }

    /* Ensure table text wraps where needed */
    table.dataTable tbody td { white-space: normal; word-break: break-word; }

    /* Column width adjustments */
    th.col-unit, td.col-unit { width: 60px; min-width: 50px; }
    th.col-mnf, td.col-mnf { width: 300px; min-width: 260px; }
    th.col-product, td.col-product { width: 320px; min-width: 280px; }</style>',
]);
?>

<div class="container-fluid" style="max-width: 1100px;">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="text-uppercase muted">WQS</div>
      <h2 class="mb-1">Stock Adjustment</h2>
      <div class="muted">Koreksi stok wajib pakai alasan. Disimpan ke log + update wqs_stock.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-ghost" href="wqs_stock.php">Stock Summary</a>
      <a class="btn btn-ghost" href="wqs_stock_audit.php">Audit</a>
      <a class="btn btn-ghost" href="../master/master_data.php">Master Data</a>
      <a class="btn btn-ghost btn-sm" href="../master/master_products.php">Master Products</a>
      <a class="btn btn-ghost btn-sm" href="../master/logout.php">Logout</a>
    </div>
  </div>
  <?php if ($flash['msg']): ?>
    <div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div>
  <?php endif; ?>
  <?php if ($schemaErrors): ?>
    <div class="alert alert-danger"><?= h(implode(' ', $schemaErrors)) ?></div>
  <?php endif; ?>
  <div class="card p-3 mb-3">
    <div class="text-uppercase muted" style="font-size:12px;">Tambah Adjustment</div>
    <form method="post" class="row g-2 mt-1">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_adjustment">
      <div class="col-md-4">
        <label class="form-label">SKU</label>
        <input class="form-control" name="sku" list="sku_list" placeholder="contoh: 01070108">
        <span id="sku_status" class="lookup-status badge mt-1" style="display:none;"></span>
        <datalist id="sku_list"></datalist>
      </div>
      <div class="col-md-2">
        <label class="form-label">Delta Qty (+/-)</label>
        <input type="number" class="form-control" name="delta_qty" value="0" step="1" min="-999999" max="999999">
        <div class="muted" style="font-size:12px;">+ menambah, - mengurangi</div>
      </div>
      <div class="col-12">
        <label class="form-label">Reason (wajib)</label>
        <input class="form-control" name="reason" placeholder="contoh: koreksi fisik opname / barang rusak / salah input">
      </div>
      <?php if ($useStockByOffice): ?>
      <div class="col-md-3">
        <label class="form-label">Office</label>
        <?php if ($userDept === 'BRANCH' && $userOffice !== ''): ?>
          <input type="hidden" name="office_code" value="<?= h($userOffice) ?>">
          <input class="form-control" value="<?= h($userOffice) ?>" readonly>
        <?php else: ?>
        <select class="form-select" name="office_code" required>
          <?php foreach ($officesForAdj as $o): ?>
            <option value="<?= h($o['office_code'] ?? '') ?>"><?= h($o['office_name'] ?? $o['office_code']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="col-12 mt-2"><div class="label-muted text-uppercase">Info Produk (otomatis)</div></div>
      <div class="col-12">
        <label class="form-label">Product Name <span class="label-muted">(otomatis)</span></label>
        <input class="form-control" name="product_name_preview" id="product_name_preview" placeholder="akan muncul otomatis" readonly>
      </div>
      <div class="col-md-6">
        <label class="form-label">Type <span class="label-muted">(otomatis)</span></label>
        <input class="form-control" name="product_type_preview" id="product_type_preview" placeholder="akan muncul otomatis" readonly>
      </div>
      <div class="col-md-6">
        <label class="form-label">Unit <span class="label-muted">(otomatis)</span></label>
        <input class="form-control" name="unit_preview" id="unit_preview" placeholder="akan muncul otomatis" readonly>
      </div>
      <div class="col-md-6">
        <label class="form-label">Barcode <span class="label-muted">(otomatis)</span></label>
        <input class="form-control" name="barcode_preview" id="barcode_preview" placeholder="akan muncul otomatis" readonly>
      </div>
      <div class="col-md-6">
        <label class="form-label">Manufacture ID <span class="label-muted">(otomatis)</span></label>
        <input class="form-control" name="manufacture_id_preview" id="manufacture_id_preview" placeholder="akan muncul otomatis" readonly>
      </div>
<div class="col-12 mt-2">
  <button class="btn btn-primary" type="submit" <?php if(!$can_adjust) echo "disabled"; ?>>Simpan Adjustment</button>
  <?php if(!$can_adjust): ?>
    <div class="muted" style="font-size:12px;">Butuh permission <b>STOCK.CREATE</b> untuk menyimpan perubahan.</div>
  <?php endif; ?>
</div>
    </form>
  </div>
  <div class="card p-3">
    <table id="t" class="display nowrap" style="width:100%">
      <thead>
        <tr>
          <th>ID</th>
          <th>SKU</th>
          <th class="col-product">Product</th>
          <th>Type</th>
          <th class="col-unit">Unit</th>
          <th>Barcode</th>
          <th class="col-mnf">Manufacture ID</th>
          <th>Delta</th>
          <th>Reason</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?=h($r['id'])?></td>
            <td><?=h(strtoupper($r['sku'] ?? ''))?></td>
            <td class="col-product"><?=h(strtoupper($r['product_name'] ?? ''))?></td>
            <td><?=h(strtoupper($r['product_type'] ?? ''))?></td>
            <td class="col-unit"><?=h(strtoupper($r['unit'] ?? 'UNIT'))?></td>
            <td><?=h(strtoupper($r['barcode'] ?? ''))?></td>
            <td class="col-mnf"><?=h($r['manufacture_id'] ?? '')?></td>
            <td><?=h($r['delta_qty'])?></td>
            <td class="col-reason"><?=h($r['reason'])?></td>
            <td><?=h($r['created_at'])?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card p-3 mb-3 mt-3">
    <div class="fw-semibold mb-2">Audit Log <span class="muted">(Last 50 events)</span></div>
    <?php if (empty($audit_rows)): ?>
      <div class="muted">Belum ada audit log.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
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
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script>
$(function(){
  $('#t').DataTable({
    dom: 'Bfrtip',
    buttons: ['copy','csv','excel','print'],
    pageLength: 25,
    scrollX: true
  });

  // Populate SKU datalist
  function populateSkuDatalist() {
    $.get(window.location.pathname, { action: 'list_skus' }, function(res){
      if (!res || !res.ok || !Array.isArray(res.skus)) return;
      var dl = document.getElementById('sku_list');
      if (!dl) return;
      dl.innerHTML = '';
      res.skus.forEach(function(s){
        var opt = document.createElement('option');
        opt.value = s;
        dl.appendChild(opt);
      });
    }, 'json');
  }
  populateSkuDatalist();

  var lookupTimer = null;
  function setSkuStatus(text, type) {
    var el = $('#sku_status');
    if (!text) { el.hide().text(''); return; }
    el.text(text).show();
  }
  function doLookup() {
    var sku = $("input[name='sku']").val().trim();
    if (!sku) {
      setSkuStatus('', '');
      $('#product_name_preview').val('');
      $('#product_type_preview').val('');
      $('#unit_preview').val('');
      $('#barcode_preview').val('');
      $('#manufacture_id_preview').val('');
      return;
    }
    setSkuStatus('Mencari…');
    $.get(window.location.pathname, { action: 'lookup_sku', sku: sku }, function(res){
      if (res && res.ok) {
        $('#product_name_preview').val(res.name || '');
        $('#product_type_preview').val(res.product_type || '');
        $('#unit_preview').val(res.unit || '');
        $('#barcode_preview').val(res.barcode || '');
        $('#manufacture_id_preview').val(res.manufacture_id || '');
        setSkuStatus('Ditemukan');
      } else {
        $('#product_name_preview').val('');
        $('#product_type_preview').val('');
        $('#unit_preview').val('');
        $('#barcode_preview').val('');
        $('#manufacture_id_preview').val('');
        setSkuStatus('Tidak ditemukan');
      }
    }, 'json').fail(function(){
      setSkuStatus('Gagal mencari');
    });
  }
  function scheduleLookup() {
    clearTimeout(lookupTimer);
    lookupTimer = setTimeout(doLookup, 300);
  }
  $("input[name='sku']").on('input change blur', function(){ scheduleLookup(); });
  // clear on form reset
  $("form").on('reset', function(){
    setTimeout(function(){
      setSkuStatus('', '');
      $('#product_name_preview').val('');
      $('#product_type_preview').val('');
      $('#unit_preview').val('');
      $('#barcode_preview').val('');
      $('#manufacture_id_preview').val('');
    }, 0);
  });
});
</script>
<?php rmi_footer(); ?>
