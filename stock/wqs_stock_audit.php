<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once dirname(__DIR__, 1) . '/master/auth.php';
require_once dirname(__DIR__, 1) . '/_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['STOCK.AUDIT', 'WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT', 'STOCK.CREATE']);
} else {
    require_role(['WQS', 'ADMIN', 'SUPERADMIN', 'SCM', 'PQP', 'MANAGER']);
}
// -------------------------------------------------------------

// stock/wqs_stock_audit.php (ENTERPRISE - READABLE / AUDIT-READY)
// Reconcile: STOCK_SNAPSHOT + Incoming - Picking + Adjustment VS wqs_stock. Lihat docs/BASELINES.md.

date_default_timezone_set('Asia/Jakarta');

// --- Load project config / DB connection (preferred) ---
$tryFiles = [
  __DIR__ . '/../config.php',
  __DIR__ . '/../config/db.php',
  __DIR__ . '/../config/database.php',
  __DIR__ . '/../system/db.php',
  __DIR__ . '/../includes/db.php',
  __DIR__ . '/../master/db.php',
];
foreach ($tryFiles as $f) { if (is_file($f)) { @require_once $f; } }

// Helpers (fallback only)
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('up')) { function up($v){ return strtoupper(trim((string)($v ?? ''))); } }

// --- DB (centralized) ---
$pdo = db_pdo();

// --- Utilities ---
function table_cols(PDO $pdo, string $table): array {
  $cols = [];
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `$table`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (!empty($r['Field'])) $cols[] = $r['Field'];
  } catch (Exception $e) {}
  return $cols;
}
function has_table(PDO $pdo, string $table): bool {
  try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; } catch (Exception $e) { return false; }
}
function pick_col(array $cols, array $candidates, ?string $fallback=null): ?string {
  $set = array_flip($cols);
  foreach ($candidates as $c) if (isset($set[$c])) return $c;
  return ($fallback && isset($set[$fallback])) ? $fallback : null;
}
function safe_date_col(PDO $pdo, string $table): ?string {
  $cols = table_cols($pdo, $table);
  return pick_col($cols, ['created_at','updated_at','received_at','incoming_date','date','handover_at'], null);
}

// --- Detect STOCK_SNAPSHOT lock time (if any) ---
$locked_at = null;
try {
  $r = $pdo->query("SELECT locked_at FROM wqs_stock_baseline_lock WHERE id=1 LIMIT 1")->fetch();
  if ($r && !empty($r['locked_at'])) $locked_at = $r['locked_at'];
} catch (Exception $e) {}

$hasSnapshot = has_table($pdo, 'wqs_stock_snapshot');
$hasIncoming = has_table($pdo, 'wqs_incoming') && has_table($pdo, 'wqs_incoming_items');
$hasPicking  = has_table($pdo, 'wqs_picking') && has_table($pdo, 'wqs_picking_items');
$hasAdj      = has_table($pdo, 'wqs_stock_adjustments');
$hasStock    = has_table($pdo, 'wqs_stock');
$hasMP       = has_table($pdo, 'master_products');

$MP_TABLE = 'master_products';
$MP_ID_COL = 'id';
$MP_SKU_COL = 'sku';
$MP_NAME_COL = null;

if ($hasMP) {
  $mpCols = table_cols($pdo, $MP_TABLE);
  $MP_ID_COL = pick_col($mpCols, ['id','product_id','products_id'], 'id') ?? 'id';
  $MP_SKU_COL = pick_col($mpCols, ['sku','product_sku','product_code','item_code','kode','code','products_code'], 'sku') ?? 'sku';
  $MP_NAME_COL = pick_col($mpCols, ['product_name','name','item_name','nama','product_title'], null);
}

function build_qty_sum(PDO $pdo, string $table, array $qtyCandidates): ?string {
  $cols = table_cols($pdo, $table);
  $qtyCol = pick_col($cols, $qtyCandidates, null);
  return $qtyCol;
}

// --- Detect qty & FK cols for incoming/picking ---
$inc_qty_col = $hasIncoming ? build_qty_sum($pdo, 'wqs_incoming_items', ['qty','quantity','stock_qty','incoming_qty','in_qty','qty_in']) : null;
$inc_pid_col = $hasIncoming ? pick_col(table_cols($pdo,'wqs_incoming_items'), ['product_id','products_id','item_id'], null) : null;
$inc_sku_col = $hasIncoming ? pick_col(table_cols($pdo,'wqs_incoming_items'), ['sku','product_sku','product_code','item_code','kode'], null) : null;
$inc_fk_col  = $hasIncoming ? pick_col(table_cols($pdo,'wqs_incoming_items'), ['incoming_id','inc_id','header_id','wqs_incoming_id'], null) : null;
$inc_date_col = $hasIncoming ? safe_date_col($pdo, 'wqs_incoming') : null;

$pick_qty_col = $hasPicking ? build_qty_sum($pdo, 'wqs_picking_items', ['qty','quantity','pick_qty','qty_pick','out_qty']) : null;
$pick_pid_col = $hasPicking ? pick_col(table_cols($pdo,'wqs_picking_items'), ['product_id','products_id','item_id'], null) : null;
$pick_sku_col = $hasPicking ? pick_col(table_cols($pdo,'wqs_picking_items'), ['sku','product_sku','product_code','item_code','kode'], null) : null;
$pick_fk_col  = $hasPicking ? pick_col(table_cols($pdo,'wqs_picking_items'), ['picking_id','pick_id','header_id','wqs_picking_id'], null) : null;
$pick_date_col = $hasPicking ? safe_date_col($pdo, 'wqs_picking') : null;

// Adjustments
$adj_pid_col = $hasAdj ? pick_col(table_cols($pdo,'wqs_stock_adjustments'), ['product_id','products_id','item_id'], null) : null;
$adj_sku_col = $hasAdj ? pick_col(table_cols($pdo,'wqs_stock_adjustments'), ['sku','product_sku','product_code','item_code'], null) : null;
$adj_delta_col = $hasAdj ? pick_col(table_cols($pdo,'wqs_stock_adjustments'), ['delta_qty','qty','delta','adjust_qty'], null) : null;
$adj_date_col = $hasAdj ? safe_date_col($pdo, 'wqs_stock_adjustments') : null;

// Stock columns
$stock_pid_col = $hasStock ? pick_col(table_cols($pdo,'wqs_stock'), ['product_id','products_id','item_id'], 'product_id') : 'product_id';
$stock_qty_col = $hasStock ? pick_col(table_cols($pdo,'wqs_stock'), ['stock_qty','qty','quantity'], 'stock_qty') : 'stock_qty';

// Filters
$onlyDiff = isset($_GET['only_diff']) && $_GET['only_diff']=='1';
$skuFilter = up($_GET['sku'] ?? '');
$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to'] ?? '';

$lockTime = $locked_at ?: null;

// For enterprise audit, default range starts at locked_at if exists
if ($lockTime && $dateFrom==='') $dateFrom = substr($lockTime, 0, 10);

// --- Build aggregate subqueries (robust) ---
/*
We will aggregate by product_id if possible; fallback to sku.
*/

$baseAgg = [];
$warn = [];
if (!$lockTime) $warn[] = "Stock Snapshot belum di-lock (wqs_stock_baseline_lock kosong). Audit tetap jalan, tapi snapshot dianggap 0.";

if (!$hasMP) $warn[] = "Tabel master_products tidak terdeteksi. Nama produk mungkin tidak tampil (audit tetap jalan).";
if (!$hasStock) $warn[] = "Tabel wqs_stock tidak terdeteksi. Tidak bisa bandingkan dengan stok aktual.";
if ($hasIncoming && (!$inc_qty_col || (!$inc_pid_col && !$inc_sku_col))) $warn[] = "Schema wqs_incoming_items tidak lengkap (qty/product_id/sku). Incoming mungkin tidak terhitung.";
if ($hasPicking && (!$pick_qty_col || (!$pick_pid_col && !$pick_sku_col))) $warn[] = "Schema wqs_picking_items tidak lengkap (qty/product_id/sku). Picking mungkin tidak terhitung.";

// Build Stock Snapshot from wqs_stock_snapshot (if available)
$baseline_sql = "SELECT product_id, sku, SUM(stock_qty) baseline_qty
                 FROM wqs_stock_snapshot";
$baseline_where = [];
$baseline_params = [];
if ($lockTime) { $baseline_where[] = "locked_at = (SELECT locked_at FROM wqs_stock_baseline_lock WHERE id=1 LIMIT 1)"; }
if ($skuFilter !== '') { $baseline_where[] = "UPPER(sku)=UPPER(?)"; $baseline_params[] = $skuFilter; }
if ($baseline_where) $baseline_sql .= " WHERE ".implode(" AND ", $baseline_where);
$baseline_sql .= " GROUP BY product_id, sku";

$incoming_sql = "SELECT ".
  ($inc_pid_col ? "ii.{$inc_pid_col} AS product_id," : "NULL AS product_id,").
  ($inc_sku_col ? "ii.{$inc_sku_col} AS sku," : "NULL AS sku,").
  "SUM(ii.{$inc_qty_col}) AS incoming_qty
  FROM wqs_incoming_items ii
  ".($inc_fk_col ? "LEFT JOIN wqs_incoming ih ON ih.id = ii.{$inc_fk_col}" : "LEFT JOIN wqs_incoming ih ON ih.id = ii.incoming_id")."
  WHERE 1=1";
$incoming_params = [];
if ($dateFrom !== '' && $inc_date_col) { $incoming_sql .= " AND DATE(ih.{$inc_date_col}) >= ?"; $incoming_params[] = $dateFrom; }
if ($dateTo   !== '' && $inc_date_col) { $incoming_sql .= " AND DATE(ih.{$inc_date_col}) <= ?"; $incoming_params[] = $dateTo; }
if ($skuFilter !== '') {
  if ($inc_sku_col) { $incoming_sql .= " AND UPPER(ii.{$inc_sku_col})=UPPER(?)"; $incoming_params[] = $skuFilter; }
}
$incoming_sql .= " GROUP BY ".($inc_pid_col ? "ii.{$inc_pid_col}," : "").($inc_sku_col ? "ii.{$inc_sku_col}" : "ii.id");

$picking_sql = "SELECT ".
  ($pick_pid_col ? "pi.{$pick_pid_col} AS product_id," : "NULL AS product_id,").
  ($pick_sku_col ? "pi.{$pick_sku_col} AS sku," : "NULL AS sku,").
  "SUM(pi.{$pick_qty_col}) AS picking_qty
  FROM wqs_picking_items pi
  ".($pick_fk_col ? "LEFT JOIN wqs_picking ph ON ph.id = pi.{$pick_fk_col}" : "LEFT JOIN wqs_picking ph ON ph.id = pi.picking_id")."
  WHERE 1=1";
$picking_params = [];
if ($dateFrom !== '' && $pick_date_col) { $picking_sql .= " AND DATE(ph.{$pick_date_col}) >= ?"; $picking_params[] = $dateFrom; }
if ($dateTo   !== '' && $pick_date_col) { $picking_sql .= " AND DATE(ph.{$pick_date_col}) <= ?"; $picking_params[] = $dateTo; }
if ($skuFilter !== '') {
  if ($pick_sku_col) { $picking_sql .= " AND UPPER(pi.{$pick_sku_col})=UPPER(?)"; $picking_params[] = $skuFilter; }
}
$picking_sql .= " GROUP BY ".($pick_pid_col ? "pi.{$pick_pid_col}," : "").($pick_sku_col ? "pi.{$pick_sku_col}" : "pi.id");

$adj_sql = "SELECT ".
  ($adj_pid_col ? "a.{$adj_pid_col} AS product_id," : "NULL AS product_id,").
  ($adj_sku_col ? "a.{$adj_sku_col} AS sku," : "NULL AS sku,").
  "SUM(a.{$adj_delta_col}) AS adj_qty
  FROM wqs_stock_adjustments a
  WHERE 1=1";
$adj_params = [];
if ($dateFrom !== '' && $adj_date_col) { $adj_sql .= " AND DATE(a.{$adj_date_col}) >= ?"; $adj_params[] = $dateFrom; }
if ($dateTo   !== '' && $adj_date_col) { $adj_sql .= " AND DATE(a.{$adj_date_col}) <= ?"; $adj_params[] = $dateTo; }
if ($skuFilter !== '') {
  if ($adj_sku_col) { $adj_sql .= " AND UPPER(a.{$adj_sku_col})=UPPER(?)"; $adj_params[] = $skuFilter; }
}
$adj_sql .= " GROUP BY ".($adj_pid_col ? "a.{$adj_pid_col}," : "").($adj_sku_col ? "a.{$adj_sku_col}" : "a.id");

// Actual stock
$stock_sql = "SELECT s.{$stock_pid_col} AS product_id, s.{$stock_qty_col} AS actual_qty FROM wqs_stock s";

// Final reconcile query: use master_products as base when available, else union distinct keys
if ($hasMP) {
  $nameSel = $MP_NAME_COL ? "p.{$MP_NAME_COL} AS product_name" : "NULL AS product_name";
  $final = "SELECT
      p.{$MP_ID_COL} AS product_id,
      p.{$MP_SKU_COL} AS sku,
      {$nameSel},
      COALESCE(b.baseline_qty,0) AS baseline_qty,
      COALESCE(i.incoming_qty,0) AS incoming_qty,
      COALESCE(k.picking_qty,0) AS picking_qty,
      COALESCE(a.adj_qty,0) AS adj_qty,
      (COALESCE(b.baseline_qty,0) + COALESCE(i.incoming_qty,0) - COALESCE(k.picking_qty,0) + COALESCE(a.adj_qty,0)) AS expected_qty,
      COALESCE(st.actual_qty,0) AS actual_qty,
      (COALESCE(st.actual_qty,0) - (COALESCE(b.baseline_qty,0) + COALESCE(i.incoming_qty,0) - COALESCE(k.picking_qty,0) + COALESCE(a.adj_qty,0))) AS diff_qty
    FROM {$MP_TABLE} p
    LEFT JOIN ({$baseline_sql}) b ON b.product_id = p.{$MP_ID_COL}
    LEFT JOIN ({$incoming_sql}) i ON (".($inc_pid_col ? "i.product_id = p.{$MP_ID_COL}" : "1=0")." OR (i.sku IS NOT NULL AND UPPER(i.sku)=UPPER(p.{$MP_SKU_COL})))
    LEFT JOIN ({$picking_sql}) k ON (".($pick_pid_col ? "k.product_id = p.{$MP_ID_COL}" : "1=0")." OR (k.sku IS NOT NULL AND UPPER(k.sku)=UPPER(p.{$MP_SKU_COL})))
    LEFT JOIN ({$adj_sql}) a ON (".($adj_pid_col ? "a.product_id = p.{$MP_ID_COL}" : "1=0")." OR (a.sku IS NOT NULL AND UPPER(a.sku)=UPPER(p.{$MP_SKU_COL})))
    LEFT JOIN ({$stock_sql}) st ON st.product_id = p.{$MP_ID_COL}
    WHERE 1=1";
  $params = array_merge($baseline_params, $incoming_params, $picking_params, $adj_params);
  if ($skuFilter !== '') { $final .= " AND UPPER(p.{$MP_SKU_COL})=UPPER(?)"; $params[] = $skuFilter; }
  if ($onlyDiff) { $final .= " AND (COALESCE(st.actual_qty,0) - (COALESCE(b.baseline_qty,0) + COALESCE(i.incoming_qty,0) - COALESCE(k.picking_qty,0) + COALESCE(a.adj_qty,0))) <> 0"; }
  $final .= " ORDER BY p.{$MP_SKU_COL} ASC";
} else {
  // minimal fallback: based on wqs_stock keys
  $final = "SELECT
      st.product_id,
      NULL AS sku,
      NULL AS product_name,
      0 AS baseline_qty,
      0 AS incoming_qty,
      0 AS picking_qty,
      0 AS adj_qty,
      0 AS expected_qty,
      COALESCE(st.actual_qty,0) AS actual_qty,
      0 AS diff_qty
    FROM ({$stock_sql}) st";
  $params = [];
}

// Execute
$data = [];
try {
  $st = $pdo->prepare($final);
  $st->execute($params);
  $data = $st->fetchAll();
} catch (Exception $e) {
  $warn[] = "Query audit gagal: ".$e->getMessage();
}

// Detail mode (drilldown)
$detail = null;
$detail_inc = [];
$detail_pick = [];
$detail_adj = [];

if ($skuFilter !== '' && $hasMP) {
  // Stock Snapshot rows (wqs_stock_snapshot)
  try {
    $st = $pdo->prepare("SELECT * FROM wqs_stock_snapshot WHERE UPPER(sku)=UPPER(?) ORDER BY id DESC");
    $st->execute([$skuFilter]);
    $detail = $st->fetchAll();
  } catch (Exception $e) {}

  if ($hasIncoming && $inc_qty_col) {
    try {
      $qinc = "SELECT ih.id incoming_id, ".($inc_date_col ? "ih.{$inc_date_col} AS incoming_date" : "NULL AS incoming_date").", ii.*
              FROM wqs_incoming_items ii
              ".($inc_fk_col ? "LEFT JOIN wqs_incoming ih ON ih.id = ii.{$inc_fk_col}" : "LEFT JOIN wqs_incoming ih ON ih.id = ii.incoming_id")."
              WHERE 1=1";
      $pinc = [];
      if ($inc_sku_col) { $qinc .= " AND UPPER(ii.{$inc_sku_col})=UPPER(?)"; $pinc[] = $skuFilter; }
      if ($dateFrom !== '' && $inc_date_col) { $qinc .= " AND DATE(ih.{$inc_date_col}) >= ?"; $pinc[] = $dateFrom; }
      if ($dateTo   !== '' && $inc_date_col) { $qinc .= " AND DATE(ih.{$inc_date_col}) <= ?"; $pinc[] = $dateTo; }
      $qinc .= " ORDER BY ih.id DESC, ii.id DESC LIMIT 500";
      $st = $pdo->prepare($qinc);
      $st->execute($pinc);
      $detail_inc = $st->fetchAll();
    } catch (Exception $e) {}
  }

  if ($hasPicking && $pick_qty_col) {
    try {
      $qpk = "SELECT ph.id picking_id, ".($pick_date_col ? "ph.{$pick_date_col} AS picking_date" : "NULL AS picking_date").", pi.*
              FROM wqs_picking_items pi
              ".($pick_fk_col ? "LEFT JOIN wqs_picking ph ON ph.id = pi.{$pick_fk_col}" : "LEFT JOIN wqs_picking ph ON ph.id = pi.picking_id")."
              WHERE 1=1";
      $ppk = [];
      if ($pick_sku_col) { $qpk .= " AND UPPER(pi.{$pick_sku_col})=UPPER(?)"; $ppk[] = $skuFilter; }
      if ($dateFrom !== '' && $pick_date_col) { $qpk .= " AND DATE(ph.{$pick_date_col}) >= ?"; $ppk[] = $dateFrom; }
      if ($dateTo   !== '' && $pick_date_col) { $qpk .= " AND DATE(ph.{$pick_date_col}) <= ?"; $ppk[] = $dateTo; }
      $qpk .= " ORDER BY ph.id DESC, pi.id DESC LIMIT 500";
      $st = $pdo->prepare($qpk);
      $st->execute($ppk);
      $detail_pick = $st->fetchAll();
    } catch (Exception $e) {}
  }

  if ($hasAdj && $adj_delta_col) {
    try {
      $qad = "SELECT * FROM wqs_stock_adjustments WHERE 1=1";
      $pad = [];
      if ($adj_sku_col) { $qad .= " AND UPPER({$adj_sku_col})=UPPER(?)"; $pad[] = $skuFilter; }
      if ($dateFrom !== '' && $adj_date_col) { $qad .= " AND DATE({$adj_date_col}) >= ?"; $pad[] = $dateFrom; }
      if ($dateTo   !== '' && $adj_date_col) { $qad .= " AND DATE({$adj_date_col}) <= ?"; $pad[] = $dateTo; }
      $qad .= " ORDER BY id DESC LIMIT 500";
      $st = $pdo->prepare($qad);
      $st->execute($pad);
      $detail_adj = $st->fetchAll();
    } catch (Exception $e) {}
  }
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('WQS Stock Audit', [
  'active' => 'stock',
  'breadcrumbs' => [
    ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
    'WQS Stock Audit',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{background:radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);color:#e5e7eb;padding:18px;min-height:100vh;}
    .card{background:rgba(17,24,39,.80);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:14px;}
    .muted{color:#9ca3af;font-size:12px;}
    .form-control,.form-select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.12)!important;border-radius:10px!important;}
    .btn,.btn-outline-light{border-radius:10px;}
    table.dataTable thead th,table.dataTable tbody td{color:#e5e7eb;}
    .dt-buttons .btn,.dataTables_wrapper .dt-buttons button{border-radius:10px!important;border:1px solid rgba(255,255,255,.15)!important;background:rgba(255,255,255,.08)!important;color:#e5e7eb!important;padding:6px 10px!important;font-size:12px!important;}
    .dataTables_wrapper .dataTables_filter input,.dataTables_wrapper .dataTables_length select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.12)!important;border-radius:10px!important;}
    .pill{padding:2px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);}
    .nowrap{white-space:nowrap;}
    .num{text-align:right;}</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted mb-1">WQS</div>
    <h3 class="mb-0">Stock Audit / Reconcile</h3>
    <div class="muted">
      Formula: <span class="pill">Stock Snapshot</span> + <span class="pill">Incoming</span> − <span class="pill">Picking</span> + <span class="pill">Adjustment</span> = Expected, dibandingkan dengan Actual (wqs_stock).
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php $bp = defined('BASE_PROJECT') ? rtrim(BASE_PROJECT, '/') : (string)($GLOBALS['BASE_PROJECT'] ?? ''); ?>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock.php">← Stock</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock_adjustment.php">Adjustment</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_incoming.php">Incoming</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_picking.php">Picking</a>
  </div>
</div>

<?php if ($warn): ?>
  <div class="alert alert-warning">
    <div class="fw-semibold mb-1">Catatan</div>
    <ul class="mb-0">
      <?php foreach($warn as $w): ?><li><?=h($w)?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-2">Filter Audit</div>
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small">SKU (optional)</label>
        <input class="form-control form-control-sm" name="sku" value="<?=h($skuFilter)?>" placeholder="contoh: 01070108">
      </div>
      <div class="col-md-3">
        <label class="form-label small">From (date)</label>
        <input class="form-control form-control-sm" type="date" name="from" value="<?=h($dateFrom)?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small">To (date)</label>
        <input class="form-control form-control-sm" type="date" name="to" value="<?=h($dateTo)?>">
      </div>
      <div class="col-md-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" value="1" id="only_diff" name="only_diff" <?= $onlyDiff?'checked':'' ?>>
          <label class="form-check-label" for="only_diff">Only diff</label>
        </div>
      </div>
      <div class="col-md-1 d-flex gap-2">
        <button class="btn btn-outline-light btn-sm">Go</button>
      </div>
    </form>
    <div class="muted mt-2">
      <?php if ($locked_at): ?>
        Stock Snapshot locked at: <b><?=h($locked_at)?></b>. Default range start mengikuti tanggal lock.
      <?php else: ?>
        Stock Snapshot belum lock → range default kosong.
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">Reconcile Table</div>
    <div class="table-responsive">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Product</th>
            <th class="num">Snapshot</th>
            <th class="num">Incoming</th>
            <th class="num">Picking</th>
            <th class="num">Adj</th>
            <th class="num">Expected</th>
            <th class="num">Actual</th>
            <th class="num">Diff</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($data as $r): ?>
          <tr>
            <td><b><?=h(strtoupper($r['sku'] ?? ''))?></b></td>
            <td><?=h($r['product_name'] ?? '')?></td>
            <td class="num"><?=h($r['baseline_qty'])?></td>
            <td class="num"><?=h($r['incoming_qty'])?></td>
            <td class="num"><?=h($r['picking_qty'])?></td>
            <td class="num"><?=h($r['adj_qty'])?></td>
            <td class="num"><?=h($r['expected_qty'])?></td>
            <td class="num"><?=h($r['actual_qty'])?></td>
            <td class="num"><b><?=h($r['diff_qty'])?></b></td>
            <td class="nowrap">
              <?php if (!empty($r['sku'])): ?>
                <a class="btn btn-outline-light btn-sm" href="wqs_stock_audit.php?sku=<?=urlencode($r['sku'])?>&from=<?=urlencode($dateFrom)?>&to=<?=urlencode($dateTo)?>&only_diff=<?= $onlyDiff?'1':'0' ?>">Detail</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($skuFilter !== ''): ?>
<div class="row g-3 mt-3">
  <div class="col-12">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold">Detail SKU: <?=h($skuFilter)?></div>
        <div class="muted">Menampilkan snapshot, incoming items, picking items, dan adjustments (maks 500 baris per section).</div>
      </div>
    </div>
  </div>

  <div class="col-md-12">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">Stock Snapshot</div>
        <?php if (!$detail): ?>
          <div class="muted">Tidak ada snapshot untuk SKU ini.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-dark table-sm align-middle">
              <thead><tr><th>locked_at</th><th>stock_qty</th></tr></thead>
              <tbody>
                <?php foreach($detail as $d): ?>
                  <tr><td><?=h($d['locked_at'] ?? '')?></td><td class="num"><?=h($d['stock_qty'] ?? '')?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-12">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">Incoming Items</div>
        <?php if (!$detail_inc): ?>
          <div class="muted">Tidak ada incoming item untuk SKU ini.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-dark table-sm align-middle">
              <thead><tr><th>incoming_id</th><th>incoming_date</th><th>qty</th><th>lot</th><th>serial</th></tr></thead>
              <tbody>
                <?php foreach($detail_inc as $d): ?>
                  <tr>
                    <td><?=h($d['incoming_id'] ?? '')?></td>
                    <td><?=h($d['incoming_date'] ?? '')?></td>
                    <td class="num"><?=h($inc_qty_col ? ($d[$inc_qty_col] ?? '') : '')?></td>
                    <td><?=h($d['lot'] ?? ($d['lot_number'] ?? ''))?></td>
                    <td><?=h($d['serial'] ?? ($d['serial_number'] ?? ''))?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-12">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">Picking Items</div>
        <?php if (!$detail_pick): ?>
          <div class="muted">Tidak ada picking item untuk SKU ini.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-dark table-sm align-middle">
              <thead><tr><th>picking_id</th><th>picking_date</th><th>qty</th><th>do_code</th></tr></thead>
              <tbody>
                <?php foreach($detail_pick as $d): ?>
                  <tr>
                    <td><?=h($d['picking_id'] ?? '')?></td>
                    <td><?=h($d['picking_date'] ?? '')?></td>
                    <td class="num"><?=h($pick_qty_col ? ($d[$pick_qty_col] ?? '') : '')?></td>
                    <td><?=h($d['do_code'] ?? ($d['do_number'] ?? ''))?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-12">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">Adjustments</div>
        <?php if (!$detail_adj): ?>
          <div class="muted">Tidak ada adjustment untuk SKU ini.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-dark table-sm align-middle">
              <thead><tr><th>adj_code</th><th>delta</th><th>reason</th><th>created_at</th></tr></thead>
              <tbody>
                <?php foreach($detail_adj as $d): ?>
                  <tr>
                    <td><?=h($d['adj_code'] ?? '')?></td>
                    <td class="num"><?=h($adj_delta_col ? ($d[$adj_delta_col] ?? '') : '')?></td>
                    <td><?=h($d['reason'] ?? '')?></td>
                    <td><?=h(($adj_date_col && isset($d[$adj_date_col])) ? $d[$adj_date_col] : ($d['created_at'] ?? ''))?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script>
  new DataTable('#tbl', { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'] });
</script>
<?php rmi_footer(); ?>
