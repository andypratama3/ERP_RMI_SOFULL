<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// stock/wqs_stock.php (ENTERPRISE - STABLE)
// Fixes: helper redeclare, parse errors, master_products schema differences, and missing wqs_stock.source column.

date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    // WQS Stock (Enterprise) = operasional gudang (STOCK_SNAPSHOT lock, import, adjustment). Lihat docs/BASELINES.md.
    // CRM hanya punya STOCK.VIEW (cek stok ringan) — tidak boleh akses halaman ini.
    require_any_permission(['WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT', 'STOCK.CREATE', 'WQS.PR_VIEW', 'WQS.PR_CREATE', 'WQS.PR_EDIT', 'WQS.PICKING_VIEW', 'WQS.PICKING_CREATE', 'WQS.PICKING_EDIT']);
} else {
    require_role(['MANAGER','ADMIN','SUPERADMIN','WQS','SCM','PQP']);
}
require_once __DIR__ . '/../_shared/helpers.php'; // ensure safe_filename/csrf helpers
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_stock_office_helper.php';


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


/**
 * FIX FIFO MULTI-ROW:
 * Pastikan tabel wqs_stock_by_office punya kolom id unik untuk stok per lot/baris.
 * Aman dijalankan berulang; jika id sudah ada, dilewati.
 */
function wqs_stock_ensure_wqs_stock_by_office_id(PDO $pdo): void
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wqs_stock_by_office'");
        $st->execute();
        if ((int)$st->fetchColumn() === 0) return;

        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wqs_stock_by_office' AND column_name = 'id'");
        $st->execute();
        if ((int)$st->fetchColumn() > 0) return;

        $pdo->exec("ALTER TABLE `wqs_stock_by_office` ADD COLUMN `id` BIGINT UNSIGNED NULL FIRST");
        $pdo->exec("SET @rmi_wso_id := 0");
        $pdo->exec("UPDATE `wqs_stock_by_office` SET `id` = (@rmi_wso_id := @rmi_wso_id + 1) WHERE `id` IS NULL ORDER BY `office_code`, `product_id`");
        $pdo->exec("ALTER TABLE `wqs_stock_by_office` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL");
        try { $pdo->exec("ALTER TABLE `wqs_stock_by_office` ADD UNIQUE KEY `uq_wso_id` (`id`)"); } catch (Throwable $e) {}
        $pdo->exec("ALTER TABLE `wqs_stock_by_office` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
        try { $pdo->exec("ALTER TABLE `wqs_stock_by_office` ADD INDEX `idx_wso_office_product` (`office_code`, `product_id`)"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE `wqs_stock_by_office` ADD INDEX `idx_wso_product_office_qty` (`product_id`, `office_code`, `stock_qty`)"); } catch (Throwable $e) {}
    } catch (Throwable $e) {
        // Fail-soft: halaman tetap dibuka, tetapi Sales DO tetap membutuhkan SQL fix jika auto ALTER diblokir.
    }
}

try { wqs_stock_ensure_wqs_stock_by_office_id($pdo); } catch (Throwable $e) {}
erp_audit_ensure($pdo);

// --- Utilities ---
function ensure_table(PDO $pdo, string $sql): void { try { $pdo->exec($sql); } catch (Exception $e) {} }
function ensure_col(PDO $pdo, string $table, string $col, string $ddlAdd): void {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    $r = $st->fetch();
    if (!$r) { $pdo->exec("ALTER TABLE `$table` ADD {$ddlAdd}"); }
  } catch (Exception $e) {}
}
function table_cols(PDO $pdo, string $table): array {
  $cols = [];
  try {
    $st = $pdo->query("SHOW COLUMNS FROM {$table}");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (!empty($r['Field'])) $cols[] = $r['Field'];
  } catch (Exception $e) {}
  return $cols;
}
function pick_col(array $cols, array $candidates, ?string $fallback=null): ?string {
  $set = array_flip($cols);
  foreach ($candidates as $c) if (isset($set[$c])) return $c;
  return $fallback && isset($set[$fallback]) ? $fallback : null;
}

// --- Ensure required tables/columns ---
ensure_table($pdo, "CREATE TABLE IF NOT EXISTS wqs_stock (
  product_id INT NOT NULL,
  stock_qty DECIMAL(18,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  source VARCHAR(40) NULL,
  PRIMARY KEY (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Attempt to add columns (if ALTER is blocked, we will fallback in SELECT/UPDATE)
ensure_col($pdo, 'wqs_stock', 'updated_at', "updated_at DATETIME NULL");
ensure_col($pdo, 'wqs_stock', 'source', "source VARCHAR(40) NULL");

// detect actual existing columns
$wqsCols = table_cols($pdo, 'wqs_stock');
$HAS_UPDATED_AT = in_array('updated_at', $wqsCols, true);
$HAS_SOURCE = in_array('source', $wqsCols, true);

ensure_table($pdo, "CREATE TABLE IF NOT EXISTS wqs_stock_baseline_lock (
  id TINYINT NOT NULL PRIMARY KEY,
  locked_at DATETIME NOT NULL,
  note VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

ensure_table($pdo, "CREATE TABLE IF NOT EXISTS wqs_stock_snapshot (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  locked_at DATETIME NOT NULL,
  product_id INT NOT NULL,
  sku VARCHAR(80) NULL,
  stock_qty DECIMAL(18,2) NOT NULL DEFAULT 0,
  KEY idx_pid (product_id),
  KEY idx_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

ensure_table($pdo, "CREATE TABLE IF NOT EXISTS wqs_stock_adjustments (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  adj_code VARCHAR(40) NOT NULL,
  product_id INT NOT NULL,
  sku VARCHAR(80) NULL,
  delta_qty DECIMAL(18,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(60) NULL,
  KEY idx_code (adj_code),
  KEY idx_pid (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// --- Master products schema detect ---
$MP_TABLE = 'master_products';
$mpCols = table_cols($pdo, $MP_TABLE);

$MP_ID_COL  = pick_col($mpCols, ['id','product_id','products_id'], 'id') ?? 'id';
$MP_SKU_COL = pick_col($mpCols, ['sku','product_sku','product_code','item_code','kode','code','products_code'], 'sku');
$MP_NAME_COL = pick_col($mpCols, ['products_name','product_name','name','item_name','nama','product_title'], null);
$MP_UNIT_COL = pick_col($mpCols, ['unit','uom','satuan'], null);
$MP_BARCODE_COL = pick_col($mpCols, ['barcode','ean','upc'], null);
$MP_MNF_CODE_COL = pick_col($mpCols, ['manufacture_code','manufacturer_code','mnf_code'], null);
$MP_MNF_NAME_COL = pick_col($mpCols, ['manufacture_name','manufacturer_name','mnf_name'], null);
$MP_CATEGORY_COL = pick_col($mpCols, ['category','product_category','item_category','jenis_produk','type'], null);
$MP_BUSINESS_GROUP_COL = pick_col($mpCols, ['business_group'], null);

if (!$MP_SKU_COL) { $MP_SKU_COL = $MP_ID_COL; } // last resort

// --- STOCK_SNAPSHOT lock status (docs/BASELINES.md) ---
$lock = null;
try { $lock = $pdo->query("SELECT * FROM wqs_stock_baseline_lock WHERE id=1 LIMIT 1")->fetch(); } catch (Exception $e) {}
$isLocked = !!$lock;

// --- Actions ---
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
}

$flash = '';

function upsert_stock(PDO $pdo, int $pid, float $qty, string $source, bool $HAS_UPDATED_AT, bool $HAS_SOURCE): void {
  $now = date('Y-m-d H:i:s');
  if ($HAS_UPDATED_AT && $HAS_SOURCE) {
    $pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty, updated_at, source)
                   VALUES (?,?,?,?)
                   ON DUPLICATE KEY UPDATE stock_qty=VALUES(stock_qty), updated_at=VALUES(updated_at), source=VALUES(source)")
        ->execute([$pid, $qty, $now, $source]);
  } elseif ($HAS_UPDATED_AT && !$HAS_SOURCE) {
    $pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty, updated_at)
                   VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE stock_qty=VALUES(stock_qty), updated_at=VALUES(updated_at)")
        ->execute([$pid, $qty, $now]);
  } elseif (!$HAS_UPDATED_AT && $HAS_SOURCE) {
    $pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty, source)
                   VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE stock_qty=VALUES(stock_qty), source=VALUES(source)")
        ->execute([$pid, $qty, $source]);
  } else {
    $pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty)
                   VALUES (?,?)
                   ON DUPLICATE KEY UPDATE stock_qty=VALUES(stock_qty)")
        ->execute([$pid, $qty]);
  }
}

if ($action === 'lock_baseline') {
  if ($isLocked) {
    $flash = "Stock Snapshot sudah terkunci.";
  } else {
    $note = trim($_POST['note'] ?? '');
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
      $pdo->prepare("INSERT INTO wqs_stock_baseline_lock (id, locked_at, note) VALUES (1, ?, ?)")->execute([$now, ($note ?: null)]);
      $pdo->exec("TRUNCATE TABLE wqs_stock_snapshot");
      $sql = "INSERT INTO wqs_stock_snapshot (locked_at, product_id, sku, stock_qty)
              SELECT ?, p.{$MP_ID_COL} AS product_id, p.{$MP_SKU_COL} AS sku, COALESCE(s.stock_qty,0) AS stock_qty
              FROM {$MP_TABLE} p
              LEFT JOIN wqs_stock s ON s.product_id = p.{$MP_ID_COL}";
      $pdo->prepare($sql)->execute([$now]);
      $pdo->commit();
      erp_audit($pdo, 'WQS_STOCK', 'BASELINE#1', 'LOCK_BASELINE', ['locked_at' => $now, 'note' => $note]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'wqs_stock', 'wqs_stock_baseline_lock', 'LOCK_BASELINE', 1, 'BASELINE#1', 'Stock snapshot locked', ['locked_at' => $now, 'note' => $note]);
      }
      $flash = "Stock Snapshot terkunci & snapshot dibuat.";
      $isLocked = true;
    } catch (Exception $e) {
      $pdo->rollBack();
      $flash = "Gagal lock Stock Snapshot: ".$e->getMessage();
    }
  }
}

if ($action === 'set_stock' && !$isLocked) {
  $pid = (int)($_POST['product_id'] ?? 0);
  $qty = (float)($_POST['stock_qty'] ?? 0);
  if ($pid > 0) {
    upsert_stock($pdo, $pid, $qty, 'BASELINE_MANUAL', $HAS_UPDATED_AT, $HAS_SOURCE);
    erp_audit($pdo, 'WQS_STOCK', 'PRODUCT#'.$pid, 'SET_STOCK', ['qty' => $qty, 'source' => 'BASELINE_MANUAL']);
    if (function_exists('master_audit')) {
      master_audit($pdo, 'wqs_stock', 'wqs_stock', 'SET_STOCK', $pid, 'PRODUCT#'.$pid, "Stock snapshot manual: product_id {$pid} qty {$qty}", ['qty' => $qty, 'source' => 'BASELINE_MANUAL']);
    }
    $flash = "Stock updated (Stock Snapshot manual).";
  }
}

if ($action === 'import_csv' && !$isLocked) {
  if (!empty($_FILES['csv']['tmp_name'])) {
    $tmp = $_FILES['csv']['tmp_name'];
    $origName = (string)($_FILES['csv']['name'] ?? 'import.csv');
    $safeName = safe_filename($origName);
    $extCheck = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    if ($extCheck !== 'csv') { $flash = 'File harus CSV'; } else {
    $fh = fopen($tmp, 'r');
    $header = fgetcsv($fh);
    $map = [];
    foreach ($header as $i=>$hcol) { $map[strtolower(trim($hcol))] = $i; }
    $ok=0; $skip=0;
    while (($row = fgetcsv($fh)) !== false) {
      $sku = '';
      if (isset($map['sku'])) $sku = trim($row[$map['sku']] ?? '');
      if ($sku==='') { $skip++; continue; }
      $qty = 0;
      if (isset($map['stock_qty'])) $qty = (float)($row[$map['stock_qty']] ?? 0);
      elseif (isset($map['qty'])) $qty = (float)($row[$map['qty']] ?? 0);

      $pid = 0;
      if (isset($map['product_id'])) $pid = (int)($row[$map['product_id']] ?? 0);
      if ($pid<=0) {
        $st = $pdo->prepare("SELECT {$MP_ID_COL} FROM {$MP_TABLE} WHERE {$MP_SKU_COL}=? LIMIT 1");
        $st->execute([$sku]);
        $pid = (int)($st->fetchColumn() ?: 0);
      }
      if ($pid<=0) { $skip++; continue; }

      upsert_stock($pdo, $pid, $qty, 'BASELINE_CSV', $HAS_UPDATED_AT, $HAS_SOURCE);
      $ok++;
    fclose($fh);
    }
    erp_audit($pdo, 'WQS_STOCK', 'IMPORT#BASELINE', 'IMPORT_CSV', ['ok' => $ok, 'skip' => $skip, 'file' => $safeName]);
    if (function_exists('master_audit')) {
      master_audit($pdo, 'wqs_stock', 'wqs_stock', 'IMPORT_CSV', null, "IMPORT-{$ok}", "Stock baseline import CSV: OK={$ok}, skip={$skip}", ['ok' => $ok, 'skip' => $skip, 'file' => $safeName]);
    }
    $flash = "Import CSV selesai. OK={$ok}, skip={$skip}.";
  }
    }
}

// Filters
$q = trim($_GET['q'] ?? '');
$mnf = trim($_GET['mnf'] ?? '');
$onlyZero = isset($_GET['zero']) && $_GET['zero']=='1';

// Filter jenis produk final: BMHP / ALKES / AKSESORIS.
// UNIT_ACC adalah unit bisnis/office, bukan kategori produk yang ditampilkan.
$productType = strtoupper(trim((string)($_GET['product_type'] ?? 'ALL')));
if (!in_array($productType, ['ALL','BMHP','ALKES','AKSESORIS'], true)) $productType = 'ALL';
$businessGroup = strtoupper(trim((string)($_GET['business_group'] ?? 'ALL')));
if (!in_array($businessGroup, ['ALL','BMHP','UNIT_ACC'], true)) $businessGroup = 'ALL';

// Stock per office: BRANCH hanya office sendiri; WQS/Admin bisa filter satu office atau semua office.
$useStockByOffice = function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo);
$userDept = function_exists('auth_dept') ? strtoupper(auth_dept()) : '';
$userOffice = function_exists('auth_office_code') ? strtoupper(trim(auth_office_code())) : '';
$officeFilter = '';
if ($useStockByOffice) {
  $defaultOffice = wqs_stock_default_office();

  if ($userDept === 'BRANCH' && $userOffice !== '') {
    // Security: akun BRANCH tidak boleh melihat office lain / ALL.
    $officeFilter = rmi_office_canonical($pdo, $userOffice);
  } else {
    $requestedOffice = strtoupper(trim((string)($_GET['office'] ?? $defaultOffice)));
    if ($requestedOffice === 'ALL') {
      $officeFilter = 'ALL';
    } else {
      if ($requestedOffice === '') $requestedOffice = $defaultOffice;
      $officeFilter = rmi_office_canonical($pdo, $requestedOffice);

      // Validasi hanya office aktif/terdaftar dari master_office.
      try {
        $st = $pdo->prepare("SELECT 1 FROM master_office WHERE UPPER(office_code)=UPPER(?) LIMIT 1");
        $st->execute([$officeFilter]);
        if (!$st->fetch()) $officeFilter = $defaultOffice;
      } catch (Throwable $e) { $officeFilter = $defaultOffice; }
    }
  }
}

// Data fetch (select safe columns based on actual wqs_stock schema)
$selUpdated = $HAS_UPDATED_AT ? ", s.updated_at" : ", NULL AS updated_at";
$selSource  = $HAS_SOURCE ? ", s.source" : ", NULL AS source";

if ($useStockByOffice && $officeFilter !== '') {
  $sql = "SELECT p.{$MP_ID_COL} AS product_id,
                 p.{$MP_SKU_COL} AS sku".
         ($MP_NAME_COL ? ", p.{$MP_NAME_COL} AS product_name" : ", p.{$MP_SKU_COL} AS product_name").
         ($MP_UNIT_COL ? ", p.{$MP_UNIT_COL} AS unit" : ", NULL AS unit").
         ($MP_BARCODE_COL ? ", p.{$MP_BARCODE_COL} AS barcode" : ", NULL AS barcode").
         ($MP_MNF_CODE_COL ? ", p.{$MP_MNF_CODE_COL} AS manufacture_code" : ", NULL AS manufacture_code").
         ($MP_MNF_NAME_COL ? ", p.{$MP_MNF_NAME_COL} AS manufacture_name" : ", NULL AS manufacture_name").
         ($MP_CATEGORY_COL ? ", UPPER(TRIM(COALESCE(p.{$MP_CATEGORY_COL},''))) AS product_category" : ", '' AS product_category").
         ($MP_BUSINESS_GROUP_COL ? ", UPPER(TRIM(COALESCE(p.{$MP_BUSINESS_GROUP_COL},'BMHP'))) AS business_group" : ", 'BMHP' AS business_group").
         ", UPPER(TRIM(COALESCE(s.office_code,''))) AS stock_office_code,
            s.stock_qty AS stock_qty, s.updated_at, NULL AS source
          FROM wqs_stock_by_office s
          INNER JOIN {$MP_TABLE} p ON p.{$MP_ID_COL} = s.product_id
          WHERE 1=1";
  $params = [];

  // ALL hanya untuk user non-BRANCH. Untuk office spesifik tetap gunakan helper alias/canonical existing.
  if ($officeFilter !== 'ALL') {
    $officeWhere = rmi_office_in_sql($pdo, 's.office_code', $officeFilter, $params);
    $sql .= " AND {$officeWhere}";
  }
} else {
  $sql = "SELECT p.{$MP_ID_COL} AS product_id,
                 p.{$MP_SKU_COL} AS sku".
         ($MP_NAME_COL ? ", p.{$MP_NAME_COL} AS product_name" : ", p.{$MP_SKU_COL} AS product_name").
         ($MP_UNIT_COL ? ", p.{$MP_UNIT_COL} AS unit" : ", NULL AS unit").
         ($MP_BARCODE_COL ? ", p.{$MP_BARCODE_COL} AS barcode" : ", NULL AS barcode").
         ($MP_MNF_CODE_COL ? ", p.{$MP_MNF_CODE_COL} AS manufacture_code" : ", NULL AS manufacture_code").
         ($MP_MNF_NAME_COL ? ", p.{$MP_MNF_NAME_COL} AS manufacture_name" : ", NULL AS manufacture_name").
         ($MP_CATEGORY_COL ? ", UPPER(TRIM(COALESCE(p.{$MP_CATEGORY_COL},''))) AS product_category" : ", '' AS product_category").
         ($MP_BUSINESS_GROUP_COL ? ", UPPER(TRIM(COALESCE(p.{$MP_BUSINESS_GROUP_COL},'BMHP'))) AS business_group" : ", 'BMHP' AS business_group").
         ", '' AS stock_office_code,
            COALESCE(s.stock_qty,0) AS stock_qty{$selUpdated}{$selSource}
          FROM {$MP_TABLE} p
          LEFT JOIN wqs_stock s ON s.product_id=p.{$MP_ID_COL}
          WHERE 1=1";
  $params = [];
}

// Jenis produk membaca master_products.category; kelompok bisnis membaca master_products.business_group.
// Office tetap lokasi stok/operasional. UNIT_ACC bukan office dan bukan category.
if ($productType !== 'ALL' && $MP_CATEGORY_COL) {
  $sql .= " AND UPPER(TRIM(COALESCE(p.{$MP_CATEGORY_COL},''))) = ?";
  $params[] = $productType;
}
if ($businessGroup !== 'ALL' && $MP_BUSINESS_GROUP_COL) {
  $sql .= " AND UPPER(TRIM(COALESCE(p.{$MP_BUSINESS_GROUP_COL},'BMHP'))) = ?";
  $params[] = $businessGroup;
}

if ($q !== '') {
  $sql .= " AND (p.{$MP_SKU_COL} LIKE ?";
  $params[] = "%{$q}%";
  if ($MP_NAME_COL) { $sql .= " OR p.{$MP_NAME_COL} LIKE ?"; $params[] = "%{$q}%"; }
  if ($MP_BARCODE_COL) { $sql .= " OR p.{$MP_BARCODE_COL} LIKE ?"; $params[] = "%{$q}%"; }
  $sql .= ")";
}
if ($mnf !== '' && $MP_MNF_CODE_COL) {
  $sql .= " AND p.{$MP_MNF_CODE_COL} = ?";
  $params[] = $mnf;
}
if ($onlyZero) {
  $sql .= " AND COALESCE(s.stock_qty,0)=0";
}
$sql .= ($useStockByOffice ? " ORDER BY UPPER(TRIM(COALESCE(s.office_code,''))) ASC, p.{$MP_SKU_COL} ASC" : " ORDER BY p.{$MP_SKU_COL} ASC");

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Manufacture list for filter (optional)
$mnfs = [];
if ($MP_MNF_CODE_COL) {
  try {
    $mnfs = $pdo->query("SELECT DISTINCT {$MP_MNF_CODE_COL} AS code FROM {$MP_TABLE} WHERE {$MP_MNF_CODE_COL} IS NOT NULL AND {$MP_MNF_CODE_COL}<>'' ORDER BY {$MP_MNF_CODE_COL}")->fetchAll();
  } catch (Exception $e) { $mnfs = []; }
}

// Product list for manual Stock Snapshot update
$plist = [];
try {
  $sqlp = "SELECT {$MP_ID_COL} AS id, {$MP_SKU_COL} AS sku".($MP_NAME_COL? ", {$MP_NAME_COL} AS name":"")." FROM {$MP_TABLE} ORDER BY {$MP_SKU_COL} ASC LIMIT 5000";
  $plist = $pdo->query($sqlp)->fetchAll();
} catch (Exception $e) {}

// Office list untuk filter (saat stock per office aktif)
// NOTE: Tidak menggunakan HO. Office code hanya dari master_office: BGR, BDG, BKS, TGR, SLO, SMG, KAL, JGY, SYS.
$officesForFilter = [];
$officeFilterName = '';
$officeNameByCode = [];
if ($useStockByOffice) {
  try {
    $officesForFilter = $pdo->query("SELECT office_code, office_name FROM master_office WHERE COALESCE(is_active,1)=1 ORDER BY office_name")->fetchAll();
    foreach ($officesForFilter as $o) {
      $ocKey = strtoupper(trim((string)($o['office_code'] ?? '')));
      if ($ocKey === '') continue;
      $officeNameByCode[$ocKey] = (string)($o['office_name'] ?? $ocKey);
      if ($ocKey === $officeFilter) {
        $officeFilterName = (string)($o['office_name'] ?? $officeFilter);
      }
    }
    if ($officeFilter === 'ALL') $officeFilterName = 'Semua Kantor';
    elseif ($officeFilterName === '') $officeFilterName = $officeFilter;
  } catch (Throwable $e) {}
}

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'wqs_stock' ORDER BY created_at DESC LIMIT 50");
  $st->execute();
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('WQS Stock (Enterprise)', [
  'active' => 'stock',
  'breadcrumbs' => [
    ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
    'WQS Stock (Enterprise)',
  ],
  'actions' => [
    ['label' => '📖 Panduan WQS', 'url' => $baseProject . '/stock/panduan.php', 'class' => 'btn btn-sm btn-outline-light'],
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
    .pill{padding:2px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);}</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted mb-1">WQS</div>
    <h3 class="mb-0">Stock Summary <span class="pill ms-2">Enterprise</span></h3>
    <div class="muted">Stock Snapshot → Lock → Semua perubahan via Incoming / Picking / Adjustment.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php $bp = defined('BASE_PROJECT') ? rtrim(BASE_PROJECT, '/') : (string)($GLOBALS['BASE_PROJECT'] ?? ''); ?>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_incoming.php">Incoming</a>
    <?php $trRole = function_exists('auth_role') ? auth_role() : ($_SESSION['role'] ?? ''); if (in_array(strtoupper(trim((string)$trRole)), ['ADMIN','SUPERADMIN','SYS'], true)): ?>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock_transfer.php">Transfer</a>
    <?php endif; ?>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_allocation.php">Allocation</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_picking.php">Picking</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_do_tasks.php">WQS DO Tasks</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock_adjustment.php">Adjustment</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock_audit.php">Audit</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-info"><?=h($flash)?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <div class="fw-semibold">Stock Snapshot Lock</div>
        <div class="muted">
          Status:
          <?php if ($isLocked): ?>
            <span class="text-success fw-semibold">LOCKED</span> — <?=h($lock['locked_at'] ?? '')?> <?= ($lock && !empty($lock['note'])) ? ' | '.h($lock['note']) : '' ?>
          <?php else: ?>
            <span class="text-warning fw-semibold">UNLOCKED</span> — stock snapshot masih bisa di-set / import.
          <?php endif; ?>
        </div>
      </div>
      <div>
        <?php if (!$isLocked): ?>
          <form method="post" class="d-flex gap-2">
            <input type="hidden" name="action" value="lock_baseline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input class="form-control form-control-sm" name="note" placeholder="catatan lock (opsional)">
            <button class="btn btn-danger btn-sm" onclick="return confirm('Lock stock snapshot? Setelah locked, set manual/import tidak bisa dipakai lagi.')">Lock Snapshot</button>
          </form>
        <?php else: ?>
          <span class="pill">Snapshot locked</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (!$isLocked): ?>
<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="fw-semibold mb-2">Set Stock Snapshot (Manual)</div>
        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="set_stock">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <div class="col-8">
            <select class="form-select form-select-sm" name="product_id" required>
              <option value="">-- pilih product --</option>
              <?php foreach($plist as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?=h(strtoupper($p['sku'] ?? ''))?><?= isset($p['name']) && $p['name'] ? ' — '.h(strtoupper($p['name'])) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-4">
            <input class="form-control form-control-sm" name="stock_qty" type="number" step="0.01" value="0" required>
          </div>
          <div class="col-12">
            <button class="btn btn-primary btn-sm">Simpan Snapshot</button>
          </div>
        </form>
        <div class="muted mt-2">Gunakan ini untuk saldo awal (legacy).</div>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="fw-semibold mb-2">Import Stock Snapshot (CSV)</div>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
          <input type="hidden" name="action" value="import_csv">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input class="form-control form-control-sm" type="file" name="csv" accept=".csv" required>
          <button class="btn btn-primary btn-sm">Import</button>
        </form>
        <div class="muted mt-2">Kolom minimal: <b>sku,stock_qty</b> (opsional: product_id).</div>
      </div>
    </div>
  </div>
</div>
<?php else: ?>
<div class="alert alert-warning">Stock Snapshot sudah <b>LOCKED</b>. Perubahan stok wajib lewat <b>Incoming</b>, <b>Picking</b>, atau <b>Adjustment</b>.</div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-2">Filter</div>
    <form method="get" class="row g-2 align-items-end">
      <?php if ($useStockByOffice): ?>
      <div class="col-md-2">
        <label class="form-label small">Office</label>
        <?php if ($userDept === 'BRANCH'): ?>
          <input class="form-control form-control-sm" type="text" value="<?=h($officeFilter)?>" readonly>
        <?php else: ?>
        <select class="form-select form-select-sm" name="office">
          <option value="ALL" <?= $officeFilter==='ALL'?'selected':'' ?>>-- SEMUA KANTOR --</option>
          <?php foreach ($officesForFilter as $o): $oc = strtoupper(trim((string)($o['office_code'] ?? ''))); ?>
            <option value="<?=h($oc)?>" <?= $officeFilter===$oc?'selected':'' ?>><?=h($o['office_name'] ?? $oc)?> (<?=h($oc)?>)</option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="col-md-2">
        <label class="form-label small">Kelompok Bisnis</label>
        <select class="form-select form-select-sm" name="business_group">
          <option value="ALL" <?= $businessGroup==='ALL'?'selected':'' ?>>-- SEMUA --</option>
          <option value="BMHP" <?= $businessGroup==='BMHP'?'selected':'' ?>>BMHP</option>
          <option value="UNIT_ACC" <?= $businessGroup==='UNIT_ACC'?'selected':'' ?>>UNIT ACC</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small">Jenis Produk</label>
        <select class="form-select form-select-sm" name="product_type">
          <option value="ALL" <?= $productType==='ALL'?'selected':'' ?>>-- SEMUA PRODUK --</option>
          <option value="BMHP" <?= $productType==='BMHP'?'selected':'' ?>>BMHP</option>
          <option value="ALKES" <?= $productType==='ALKES'?'selected':'' ?>>ALAT KESEHATAN</option>
          <option value="AKSESORIS" <?= $productType==='AKSESORIS'?'selected':'' ?>>AKSESORIS</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small">Search (SKU / Name / Barcode)</label>
        <input class="form-control form-control-sm" name="q" value="<?=h($q)?>" placeholder="contoh: 01070108 / suction / barcode">
      </div>
      <div class="col-md-2">
        <label class="form-label small">Manufacture Code</label>
        <select class="form-select form-select-sm" name="mnf">
          <option value="">-- all --</option>
          <?php foreach($mnfs as $m): $c=(string)($m['code'] ?? ''); ?>
            <option value="<?=h($c)?>" <?= $mnf===$c?'selected':'' ?>><?=h($c)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" value="1" id="zero" name="zero" <?= $onlyZero?'checked':'' ?>>
          <label class="form-check-label" for="zero">Only zero stock</label>
        </div>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-outline-light btn-sm">Apply</button>
        <a class="btn btn-outline-light btn-sm" href="wqs_stock.php">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">Stock List<?php if ($useStockByOffice): ?> <span class="badge bg-secondary"><?=h($officeFilter)?> — <?=h($officeFilterName)?></span><?php endif; ?></div>
    <div class="table-responsive">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <?php if ($useStockByOffice): ?><th>Office / Branch</th><?php endif; ?>
            <th>Product ID</th>
            <th>SKU</th>
            <th>Kelompok</th>
            <th>Jenis Produk</th>
            <th>Product</th>
            <th>Unit</th>
            <th>Barcode</th>
            <th>MNF</th>
            <th>Stock Qty</th>
            <th>Updated</th>
            <th>Source</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <tr>
            <?php if ($useStockByOffice): $rowOffice = strtoupper(trim((string)($r['stock_office_code'] ?? $officeFilter))); $rowOfficeName = $officeNameByCode[$rowOffice] ?? $rowOffice; ?><td class="text-nowrap"><b><?=h($rowOffice)?></b> — <?=h($rowOfficeName)?></td><?php endif; ?>
            <td><?= (int)$r['product_id'] ?></td>
            <td><b><?=h(strtoupper($r['sku'] ?? ''))?></b></td>
            <td><span class="badge bg-info text-dark"><?=h(strtoupper($r['business_group'] ?? 'BMHP'))?></span></td>
            <td><span class="badge bg-secondary"><?=h(strtoupper($r['product_category'] ?? ''))?></span></td>
            <td><?=h(strtoupper($r['product_name'] ?? ''))?></td>
            <td><?=h(strtoupper($r['unit'] ?? 'UNIT'))?></td>
            <td><?=h(strtoupper($r['barcode'] ?? ''))?></td>
            <td><?=h(strtoupper($r['manufacture_code'] ?? ''))?></td>
            <td><?=h($r['stock_qty'])?></td>
            <td><?=h($r['updated_at'])?></td>
            <td><?=h(strtoupper($r['source'] ?? ''))?></td>
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
  new DataTable('#tbl', { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'] });
</script>
<?php rmi_footer(); ?>
