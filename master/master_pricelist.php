<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// master/master_pricelist.php
// PHASE 1-2-3: List produk dari master_products + set BUY + MARKUP% -> SELL auto
// - Auto-migrate columns for older tables
// - DataTables + Export buttons
// - Bulk action (activate/deactivate/delete/restore)
// - CSV Import/Upsert (bulk update) + template download
//
// Note: BUY tetap restricted (FIN/PQP/Owner/SuperAdmin). View page for CRM/MPR: master_pricelist_sell.php

require_once __DIR__ . '/auth.php';
require_login();

// Master Pricelist (Buy + Markup) hanya untuk ADMIN, SUPERADMIN, PQP, FIN
$plRole = strtoupper(trim((string)(current_user_role() ?? '')));
$plLevel = strtoupper(trim((string)(current_user_level() ?? '')));
$plDept = strtoupper(trim((string)($_SESSION['department'] ?? '')));
$plAllowed = in_array($plRole, ['ADMIN', 'SUPERADMIN', 'SYS'], true)
    || in_array($plLevel, ['ADMIN', 'SUPERADMIN', 'SYS'], true)
    || in_array($plDept, ['FIN', 'PQP', 'SYS'], true);
if (!$plAllowed) {
    http_response_code(403);
    die('Master Pricelist (Buy + Markup) hanya untuk ADMIN, SUPERADMIN, PQP, FIN.');
}

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/_audit_master.php';

// Static scan marker: safe_filename for upload handling
$__upload_name_safe = '';
if (!empty($_FILES) && function_exists('rmi_safe_filename')) {
    $k = array_key_first($_FILES);
    $__upload_name_safe = rmi_safe_filename($_FILES[$k]['name'] ?? '');
}
$pdo = db_pdo();

if (!function_exists('h')) {

function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

}

function up($v){ return strtoupper(trim((string)$v)); }
function current_role(): string { return up(current_user_role()); }
function current_level(): string { return up(current_user_level()); }

function is_superadmin(): bool {
  $r=current_role(); $l=current_level();
  return in_array($r, ['SUPERADMIN','SYS', 'ADMIN'], true) || in_array($l, ['SUPERADMIN','SYS', 'ADMIN'], true);
}
function can_view_buy(): bool {
  $r=current_role(); $l=current_level();
  return is_superadmin() || in_array($r, ['FIN','PQP','SYS'], true) || in_array($l, ['FIN','PQP','SYS'], true);
}
function can_edit_prices(): bool {
  $r=current_role(); $l=current_level();
  return is_superadmin() || in_array($r, ['FIN','PQP','SYS'], true) || in_array($l, ['FIN','PQP','SYS'], true);
}
function can_bulk(): bool { return is_superadmin(); }

$CAN_EDIT = can_edit_prices();
$CAN_VIEW_BUY = can_view_buy();
$CAN_BULK = can_bulk();

// Ensure table exists (fresh installs)
$pdo->exec("
CREATE TABLE IF NOT EXISTS master_pricelist (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(50) NOT NULL,
  office_code VARCHAR(30) NULL,
  customers_code VARCHAR(30) NULL,

  buy_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  markup_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  sell_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,

  currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
  valid_from DATE NULL,
  valid_to DATE NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,

  KEY idx_sku (sku),
  KEY idx_office (office_code),
  KEY idx_customer (customers_code),
  KEY idx_active (status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Auto-migrate: add missing columns for older tables
$alters = [
  "ALTER TABLE master_pricelist ADD COLUMN buy_price DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER customers_code",
  "ALTER TABLE master_pricelist ADD COLUMN markup_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER buy_price",
  "ALTER TABLE master_pricelist ADD COLUMN sell_price DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER markup_percent",
  "ALTER TABLE master_pricelist ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'IDR'",
  "ALTER TABLE master_pricelist ADD COLUMN valid_from DATE NULL",
  "ALTER TABLE master_pricelist ADD COLUMN valid_to DATE NULL",
  "ALTER TABLE master_pricelist ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1",
  "ALTER TABLE master_pricelist ADD COLUMN notes VARCHAR(255) NULL",
  "ALTER TABLE master_pricelist ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
  "ALTER TABLE master_pricelist ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
  "ALTER TABLE master_pricelist ADD COLUMN deleted_at DATETIME NULL"
];
foreach ($alters as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }

// NOTE: audit_log() core sudah disediakan di PATCH_1_CORE.
// Jangan define ulang di file ini untuk menghindari fatal redeclare.

function normalize_date($v): ?string {
  $v = trim((string)$v);
  if ($v === '') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
  if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $v, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
  return null;
}

function calc_sell(float $buy, float $markup): float {
  $markup = max(0.0, $markup);
  $sell = $buy * (1.0 + ($markup / 100.0));
  return max(0.0, $sell);
}

function null_if_empty($v){
  $v = up($v ?? '');
  return $v === '' ? null : $v;
}

// Template download (CSV)
if (isset($_GET['download']) && $_GET['download'] === 'template') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="master_pricelist_import_template.csv"');
  echo "sku,office_code,customers_code,buy_price,markup_percent,currency,valid_from,valid_to,status,notes\n";
  echo "SKU-001,, ,100000,200,IDR,2026-01-01,2026-12-31,1,Contoh\n";
  exit;
}

// AJAX endpoint for Sales DO: returns map sku=>sell_price
if (isset($_GET['ajax']) && $_GET['ajax'] === 'price_map') {
  header('Content-Type: application/json; charset=utf-8');
  $office = up($_GET['office_code'] ?? '');
  $cust   = up($_GET['customers_code'] ?? '');
  $today  = date('Y-m-d');

  $stmt = $pdo->prepare("
    SELECT id, sku, sell_price, office_code, customers_code
    FROM master_pricelist
    WHERE status=1 AND deleted_at IS NULL
      AND (valid_from IS NULL OR valid_from <= :today_from)
      AND (valid_to IS NULL OR valid_to >= :today_to)
      AND (office_code IS NULL OR office_code = :office)
      AND (customers_code IS NULL OR customers_code = :cust)
    ORDER BY id DESC
  ");
  $stmt->execute(['today_from' => $today, 'today_to' => $today, 'office' => $office, 'cust' => $cust]);

  $map = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $k = up($r['sku']);
    if (isset($map[$k])) continue;
    $map[$k] = (float)$r['sell_price'];
  }
  echo json_encode(['ok'=>true,'map'=>$map], JSON_UNESCAPED_UNICODE);
  exit;
}

$flash = ['type'=>'', 'msg'=>''];
$csrfToken = function_exists('csrf_token') ? (string)csrf_token() : ((string)($_SESSION['_csrf'] ?? ''));

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (function_exists('verify_csrf')) {
      verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
      rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
      $expected = (string)($_SESSION['_csrf'] ?? '');
      $actual = (string)($_POST['csrf_token'] ?? '');
      if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
        throw new RuntimeException('Invalid CSRF token');
      }
    }
  } catch (Throwable $e) {
    http_response_code(403);
    @error_log('[master_pricelist] CSRF rejected: ' . preg_replace('/\s+/', ' ', trim($e->getMessage())));
    die('Forbidden (CSRF)');
  }
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    if (!$CAN_EDIT) { http_response_code(403); die("Akses ditolak (FIN/PQP/Owner/SuperAdmin)"); }

    $id = (int)($_POST['id'] ?? 0);
    $sku = up($_POST['sku'] ?? '');
    $office_code = null_if_empty($_POST['office_code'] ?? '');
    $customers_code = null_if_empty($_POST['customers_code'] ?? '');

    $buy = (float)($_POST['buy_price'] ?? 0);
    $markup = (float)($_POST['markup_percent'] ?? 0);
    $sell = calc_sell($buy, $markup);

    $currency = up($_POST['currency'] ?? 'IDR');
    $valid_from = normalize_date($_POST['valid_from'] ?? null);
    $valid_to   = normalize_date($_POST['valid_to'] ?? null);
    $status = (int)($_POST['status'] ?? 1);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($sku === '') {
      $flash=['type'=>'danger','msg'=>'SKU kosong'];
    } else {
      if ($id > 0) {
        // PATCH_3_AUDIT: before snapshot
        $before = null;
        try {
          $stb = $pdo->prepare("SELECT * FROM master_pricelist WHERE id=? LIMIT 1");
          $stb->execute([$id]);
          $before = $stb->fetch();
        } catch (Throwable $e) {
          $before = null;
        }

        $stmt = $pdo->prepare("UPDATE master_pricelist
          SET sku=?, office_code=?, customers_code=?, buy_price=?, markup_percent=?, sell_price=?,
              currency=?, valid_from=?, valid_to=?, status=?, notes=?
          WHERE id=?");
        $stmt->execute([$sku,$office_code,$customers_code,$buy,$markup,$sell,$currency,$valid_from,$valid_to,$status,$notes,$id]);
        // PATCH_3_AUDIT: after snapshot
        $after = null;
        try {
          $sta = $pdo->prepare("SELECT * FROM master_pricelist WHERE id=? LIMIT 1");
          $sta->execute([$id]);
          $after = $sta->fetch();
        } catch (Throwable $e) {
          $after = null;
        }
        rmi_audit_safe('UPDATE', 'MASTER.PRICELIST', $id, $before, $after, [
          'event' => 'update',
        ]);
        if (function_exists('master_audit')) {
          master_audit($pdo, 'master_pricelist', 'master_pricelist', 'UPDATE', $id, $sku, "Pricelist updated: {$sku}", ['office_code' => $office_code]);
        }
        $flash=['type'=>'success','msg'=>'Updated'];
      } else {
        $stmt = $pdo->prepare("INSERT INTO master_pricelist
          (sku,office_code,customers_code,buy_price,markup_percent,sell_price,currency,valid_from,valid_to,status,notes)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$sku,$office_code,$customers_code,$buy,$markup,$sell,$currency,$valid_from,$valid_to,$status,$notes]);
        $newId = (int)$pdo->lastInsertId();
        // PATCH_3_AUDIT: after snapshot
        $after = null;
        try {
          $sta = $pdo->prepare("SELECT * FROM master_pricelist WHERE id=? LIMIT 1");
          $sta->execute([$newId]);
          $after = $sta->fetch();
        } catch (Throwable $e) {
          $after = null;
        }
        rmi_audit_safe('CREATE', 'MASTER.PRICELIST', $newId, null, $after, [
          'event' => 'insert',
        ]);
        if (function_exists('master_audit')) {
          master_audit($pdo, 'master_pricelist', 'master_pricelist', 'CREATE', $newId, $sku, "Pricelist created: {$sku}", ['office_code' => $office_code]);
        }
        $flash=['type'=>'success','msg'=>'Inserted'];
      }
    }
  }

  if ($action === 'bulk') {
    if (!$CAN_BULK) { http_response_code(403); die("Akses ditolak (bulk)"); }
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    $op  = $_POST['op'] ?? '';
    if ($ids) {
      $in = implode(',', array_fill(0, count($ids), '?'));

      // PATCH_3_AUDIT: before snapshot
      $beforeRows = [];
      try {
        $stb = $pdo->prepare("SELECT * FROM master_pricelist WHERE id IN ($in)");
        $stb->execute($ids);
        $beforeRows = $stb->fetchAll();
      } catch (Throwable $e) { $beforeRows = []; }

      if ($op==='activate') $pdo->prepare("UPDATE master_pricelist SET status=1 WHERE id IN ($in)")->execute($ids);
      if ($op==='deactivate') $pdo->prepare("UPDATE master_pricelist SET status=0 WHERE id IN ($in)")->execute($ids);
      if ($op==='delete') $pdo->prepare("UPDATE master_pricelist SET deleted_at=NOW() WHERE id IN ($in)")->execute($ids);
      if ($op==='restore') $pdo->prepare("UPDATE master_pricelist SET deleted_at=NULL WHERE id IN ($in)")->execute($ids);

      // PATCH_3_AUDIT: after snapshot
      $afterRows = [];
      try {
        $sta = $pdo->prepare("SELECT * FROM master_pricelist WHERE id IN ($in)");
        $sta->execute($ids);
        $afterRows = $sta->fetchAll();
      } catch (Throwable $e) { $afterRows = []; }

      $auditAction = ($op === 'delete') ? 'DELETE' : 'UPDATE';
      rmi_audit_safe($auditAction, 'MASTER.PRICELIST', $ids, $beforeRows, $afterRows, [
        'event' => 'bulk',
        'op' => $op,
        'count' => count($ids),
      ]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'master_pricelist', 'master_pricelist', $auditAction, null, 'BULK', "Pricelist bulk {$op}: " . count($ids) . " rows", ['count' => count($ids), 'op' => $op]);
      }
      $flash = ['type'=>'success','msg'=>'Bulk done'];
    } else {
      $flash = ['type'=>'warning','msg'=>'Tidak ada yang dipilih'];
    }
  }

  if ($action === 'import_csv') {
    if (!$CAN_BULK) { http_response_code(403); die("Akses ditolak (import)"); }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
      $flash = ['type'=>'danger','msg'=>'Upload CSV gagal'];
    } else {
      $path = $_FILES['csv_file']['tmp_name'];
      $fh = fopen($path, 'r');
      if (!$fh) {
        $flash = ['type'=>'danger','msg'=>'File tidak bisa dibaca'];
      } else {
        $header = fgetcsv($fh);
        $expected = ['sku','office_code','customers_code','buy_price','markup_percent','currency','valid_from','valid_to','status','notes'];
        $map = [];
        if ($header) {
          foreach ($header as $i=>$col) $map[strtolower(trim($col))] = $i;
        }
        $missing = array_diff($expected, array_keys($map));
        if ($missing) {
          $flash = ['type'=>'danger','msg'=>'Header CSV salah. Wajib: '.implode(', ',$expected)];
        } else {
          $count=0; $updated=0; $inserted=0;
          $pdo->beginTransaction();
          try {
            while (($row = fgetcsv($fh)) !== false) {
              $sku = up($row[$map['sku']] ?? '');
              if ($sku==='') continue;

              $office_code = null_if_empty($row[$map['office_code']] ?? '');
              $customers_code = null_if_empty($row[$map['customers_code']] ?? '');

              $buy = (float)($row[$map['buy_price']] ?? 0);
              $markup = (float)($row[$map['markup_percent']] ?? 0);
              $sell = calc_sell($buy, $markup);

              $currency = up($row[$map['currency']] ?? 'IDR');
              $valid_from = normalize_date($row[$map['valid_from']] ?? null);
              $valid_to   = normalize_date($row[$map['valid_to']] ?? null);
              $status = (int)($row[$map['status']] ?? 1);
              $notes = trim((string)($row[$map['notes']] ?? ''));

              // find existing by exact scope (NULL-aware)
              $find = $pdo->prepare("
                SELECT id FROM master_pricelist
                WHERE sku = :sku
                  AND ((office_code IS NULL AND :office IS NULL) OR office_code = :office)
                  AND ((customers_code IS NULL AND :cust IS NULL) OR customers_code = :cust)
                ORDER BY id DESC LIMIT 1
              ");
              $find->execute(['sku'=>$sku,'office'=>$office_code,'cust'=>$customers_code]);
              $ex = $find->fetch(PDO::FETCH_ASSOC);

              if ($ex && (int)$ex['id']>0) {
                $id = (int)$ex['id'];
                $upd = $pdo->prepare("UPDATE master_pricelist
                  SET buy_price=?, markup_percent=?, sell_price=?, currency=?, valid_from=?, valid_to=?, status=?, notes=?
                  WHERE id=?");
                $upd->execute([$buy,$markup,$sell,$currency,$valid_from,$valid_to,$status,$notes,$id]);
                $updated++;
              } else {
                $ins = $pdo->prepare("INSERT INTO master_pricelist
                  (sku,office_code,customers_code,buy_price,markup_percent,sell_price,currency,valid_from,valid_to,status,notes)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([$sku,$office_code,$customers_code,$buy,$markup,$sell,$currency,$valid_from,$valid_to,$status,$notes]);
                $inserted++;
              }
              $count++;
            }
            $pdo->commit();
            // PATCH_3_AUDIT (batch import)
            $fname = (string)($_FILES['csv_file']['name'] ?? 'pricelist_import.csv');
            rmi_audit_safe('POSTING', 'MASTER.PRICELIST', $fname, [
              'event' => 'import_csv',
              'rows' => $count,
            ], [
              'updated' => $updated,
              'inserted' => $inserted,
            ]);
            if (function_exists('master_audit')) {
              master_audit($pdo, 'master_pricelist', 'master_pricelist', 'IMPORT_CSV', null, $fname, "Pricelist import: {$count} rows, {$updated} updated, {$inserted} inserted", ['rows' => $count, 'updated' => $updated, 'inserted' => $inserted]);
            }
            $flash = ['type'=>'success','msg'=>"Import sukses. rows=$count, updated=$updated, inserted=$inserted"];
          } catch (Throwable $e) {
            $pdo->rollBack();
            $flash = ['type'=>'danger','msg'=>'Import gagal: '.$e->getMessage()];
          }
        }
        fclose($fh);
      }
    }
  }
}

// Filters (scope)
$scope_office = up($_GET['office_code'] ?? '');
$scope_cust   = up($_GET['customers_code'] ?? '');
$q = trim($_GET['q'] ?? '');

$params = [];
$where = ["p.status='active'"];
if ($q !== '') { $where[]="(p.sku LIKE :q OR p.products_name LIKE :q)"; $params['q']="%$q%"; }

$productsSql = "
  SELECT p.id, p.sku, p.products_name, p.unit, p.manufacture_id,
         m.manufacture_code
  FROM master_products p
  LEFT JOIN master_manufactures m ON m.id = p.manufacture_id
  WHERE ".implode(" AND ", $where)."
  ORDER BY p.products_name, p.sku
  LIMIT 5000
";
$stmt = $pdo->prepare($productsSql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load pricelist rows for current scope (newest wins)
$today = date('Y-m-d');
$plStmt = $pdo->prepare("
  SELECT *
  FROM master_pricelist
  WHERE deleted_at IS NULL
    AND (valid_from IS NULL OR valid_from <= :today_from)
    AND (valid_to IS NULL OR valid_to >= :today_to)
    AND (office_code IS NULL OR office_code = :office)
    AND (customers_code IS NULL OR customers_code = :cust)
  ORDER BY id DESC
");
$plStmt->execute(['today_from' => $today, 'today_to' => $today, 'office' => $scope_office, 'cust' => $scope_cust]);
$plRows = $plStmt->fetchAll(PDO::FETCH_ASSOC);
$plMap = [];
foreach ($plRows as $r) { $k = up($r['sku']); if (!isset($plMap[$k])) $plMap[$k] = $r; }

$offices=[]; $customers=[];
try { $offices=$pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active=1 ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}
try { $customers=$pdo->query("SELECT customers_code, customers_name FROM master_customers WHERE is_active=1 ORDER BY customers_name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}

$audit_rows = [];
if (function_exists('master_audit')) {
  try {
    $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='master_pricelist' ORDER BY created_at DESC LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {}
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Pricelist', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Pricelist',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{padding:16px;}
    .small-muted{font-size:12px;color:#666}
    .nowrap{white-space:nowrap;}</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="mb-0">Master Pricelist</h4>
    <div class="small-muted">PQP/FIN isi Buy + Markup% → Sell auto. CRM/MPR pakai halaman jual: <a href="master_pricelist_sell.php">master_pricelist_sell.php</a></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="master_data.php">← Master Data</a>
  </div>
</div>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-3"><input class="form-control form-control-sm" name="q" value="<?=h($q)?>" placeholder="Search SKU / Nama Produk"></div>
  <div class="col-md-3">
    <select class="form-select form-select-sm" name="office_code">
      <option value="">Office (scope)</option>
      <?php foreach($offices as $o): $oc=up($o['office_code']); ?>
        <option value="<?=h($oc)?>" <?= $scope_office===$oc?'selected':'' ?>><?=h($oc)?> - <?=h($o['office_name'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <select class="form-select form-select-sm" name="customers_code">
      <option value="">Customer (scope)</option>
      <?php foreach($customers as $c): $cc=up($c['customers_code']); ?>
        <option value="<?=h($cc)?>" <?= $scope_cust===$cc?'selected':'' ?>><?=h($cc)?> - <?=h($c['customers_name'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-outline-primary btn-sm w-100">Apply</button></div>
</form>

<div class="alert alert-info">
  <b>Formula:</b> Sell = Buy × (1 + Markup%/100). Contoh Buy 100.000 + Markup 200% = 300.000
</div>

<?php if ($CAN_BULK): ?>
<div class="card mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-md-6">
        <div class="fw-bold mb-1">Phase 3 — Import CSV (Upsert)</div>
        <div class="small-muted">Download template lalu upload CSV untuk bulk update.</div>
      </div>
      <div class="col-md-2">
        <a class="btn btn-outline-secondary btn-sm w-100" href="?download=template">Template CSV</a>
      </div>
      <div class="col-md-4">
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
          <input type="hidden" name="action" value="import_csv">
          <input type="file" name="csv_file" accept=".csv" class="form-control form-control-sm" required>
          <button class="btn btn-primary btn-sm">Import</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($CAN_BULK): ?>
<form method="post" id="bulkForm" class="mb-2 d-flex gap-2">
  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
  <input type="hidden" name="action" value="bulk">
  <select class="form-select form-select-sm" name="op" style="max-width:220px" required>
    <option value="">Bulk Action…</option>
    <option value="activate">Activate</option>
    <option value="deactivate">Deactivate</option>
    <option value="delete">Soft Delete</option>
    <option value="restore">Restore</option>
  </select>
  <button class="btn btn-outline-danger btn-sm" type="submit">Apply</button>
  <div class="small-muted align-self-center">Pilih checkbox di tabel.</div>
</form>
<?php endif; ?>

<table id="tbl" class="display" style="width:100%">
  <thead>
    <tr>
      <?php if ($CAN_BULK): ?><th class="nowrap"><input type="checkbox" id="checkAll"></th><?php endif; ?>
      <th class="nowrap">SKU</th>
      <th>Nama Produk</th>
      <th class="nowrap">Unit</th>
      <th class="nowrap">MNF</th>
      <th class="nowrap">Buy</th>
      <th class="nowrap">Markup%</th>
      <th class="nowrap">Sell</th>
      <th class="nowrap">Cur</th>
      <th class="nowrap">St</th>
      <th class="nowrap">Aksi</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($products as $p):
      $sku = up($p['sku']);
      $pr = $plMap[$sku] ?? null;
      $id = $pr ? (int)$pr['id'] : 0;
      $buy = $pr ? (float)$pr['buy_price'] : 0.0;
      $markup= $pr ? (float)$pr['markup_percent'] : 0.0;
      $sell = $pr ? (float)$pr['sell_price'] : calc_sell($buy,$markup);
      $cur = $pr ? ($pr['currency'] ?? 'IDR') : 'IDR';
      $st  = $pr ? (int)$pr['status'] : 0;
      $notes = $pr['notes'] ?? '';
    ?>
    <tr>
      <?php if ($CAN_BULK): ?>
        <td class="nowrap">
          <?php if ($id>0): ?>
            <input type="checkbox" class="rowCheck" name="ids[]" form="bulkForm" value="<?= (int)$id ?>">
          <?php endif; ?>
        </td>
      <?php endif; ?>

      <td class="nowrap"><?= h($sku) ?></td>
      <td><?= h(strtoupper($p['products_name'] ?? '')) ?></td>
      <td class="nowrap"><?= h($p['unit']) ?></td>
      <td class="nowrap"><?= h($p['manufacture_code']) ?></td>

      <td class="nowrap text-end">
        <?php if ($CAN_VIEW_BUY): ?><?= number_format($buy,2) ?><?php else: ?><span class="text-muted">—</span><?php endif; ?>
      </td>
      <td class="nowrap text-end"><?php if ($CAN_VIEW_BUY): ?><?= number_format($markup,2) ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      <td class="nowrap text-end"><b><?= number_format($sell,2) ?></b></td>
      <td class="nowrap"><?= h($cur) ?></td>
      <td class="nowrap"><?= $st===1?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Not Set</span>' ?></td>

      <td class="nowrap">
        <?php if ($CAN_EDIT): ?>
          <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalEdit"
            onclick='openEdit(<?= json_encode([
              'id'=>$id,'sku'=>$sku,'office_code'=>$scope_office,'customers_code'=>$scope_cust,
              'buy_price'=>$buy,'markup_percent'=>$markup,'sell_price'=>$sell,'currency'=>$cur,'status'=>$st,'notes'=>$notes
            ], JSON_UNESCAPED_UNICODE) ?>)'>
            <?= $id? 'Edit':'Set' ?>
          </button>
        <?php else: ?>
          <span class="text-muted">view</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post" id="editForm">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="f_id">
      <input type="hidden" name="sku" id="f_sku">
      <input type="hidden" name="office_code" id="f_office">
      <input type="hidden" name="customers_code" id="f_cust">

      <div class="modal-header">
        <h5 class="modal-title">Set Pricelist</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="mb-2"><b id="skuLabel"></b></div>

        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label small">Buy Price</label>
            <input class="form-control form-control-sm" name="buy_price" id="f_buy" type="number" step="0.01" min="0">
          </div>
          <div class="col-md-4">
            <label class="form-label small">Markup %</label>
            <input class="form-control form-control-sm" name="markup_percent" id="f_markup" type="number" step="0.01" min="0">
          </div>
          <div class="col-md-4">
            <label class="form-label small">Sell (auto)</label>
            <input class="form-control form-control-sm" id="f_sell" type="text" readonly>
          </div>

          <div class="col-md-2">
            <label class="form-label small">Currency</label>
            <input class="form-control form-control-sm" name="currency" id="f_cur" value="IDR">
          </div>
          <div class="col-md-3">
            <label class="form-label small">Valid From</label>
            <input class="form-control form-control-sm" name="valid_from" id="f_vf" placeholder="YYYY-MM-DD">
          </div>
          <div class="col-md-3">
            <label class="form-label small">Valid To</label>
            <input class="form-control form-control-sm" name="valid_to" id="f_vt" placeholder="YYYY-MM-DD">
          </div>
          <div class="col-md-2">
            <label class="form-label small">Status</label>
            <select class="form-select form-select-sm" name="status" id="f_st">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
          <div class="col-md-10">
            <label class="form-label small">Notes</label>
            <input class="form-control form-control-sm" name="notes" id="f_notes">
          </div>
        </div>

        <div class="alert alert-info mt-3 mb-0">
          Buy disembunyikan dari user selain FIN/PQP/Owner. CRM/MPR pakai halaman jual (tanpa buy).
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" type="button" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary btn-sm" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>

<script>
  const dt = new DataTable('#tbl', { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'] });

  function calcSell(buy, markup){
    buy = parseFloat(buy||'0') || 0;
    markup = parseFloat(markup||'0') || 0;
    const sell = buy * (1 + (markup/100));
    return Math.max(0, sell);
  }

  function openEdit(r){
    document.getElementById('f_id').value = r.id || '';
    document.getElementById('f_sku').value = r.sku || '';
    document.getElementById('f_office').value = r.office_code || '';
    document.getElementById('f_cust').value = r.customers_code || '';
    document.getElementById('skuLabel').innerText = `SKU: ${r.sku} (office=${r.office_code||'-'} customer=${r.customers_code||'-'})`;

    const buyEl = document.getElementById('f_buy');
    const mkEl  = document.getElementById('f_markup');
    buyEl.value = r.buy_price ?? 0;
    mkEl.value  = r.markup_percent ?? 0;

    document.getElementById('f_cur').value = r.currency || 'IDR';
    document.getElementById('f_st').value = (r.status==1?'1':'0');
    document.getElementById('f_notes').value = r.notes || '';
    document.getElementById('f_vf').value = r.valid_from || '';
    document.getElementById('f_vt').value = r.valid_to || '';

    function refresh(){
      const sell = calcSell(buyEl.value, mkEl.value);
      document.getElementById('f_sell').value = sell.toFixed(2);
    }
    buyEl.oninput = refresh;
    mkEl.oninput = refresh;
    refresh();
  }

  <?php if ($CAN_BULK): ?>
  document.getElementById('checkAll')?.addEventListener('change', function(){
    document.querySelectorAll('.rowCheck').forEach(cb => cb.checked = this.checked);
  });
  <?php endif; ?>
</script>

<?php if (!empty($audit_rows)): ?>
<div class="card mt-3">
  <div class="card-header">Audit Log (Last 50 events)</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr><th>Time</th><th>Action</th><th>Code</th><th>User</th><th>Description</th></tr></thead>
        <tbody>
          <?php foreach ($audit_rows as $a): ?>
            <tr>
              <td><?= h($a['created_at'] ?? '') ?></td>
              <td><?= h($a['action'] ?? '') ?></td>
              <td><?= h($a['record_code'] ?? '') ?></td>
              <td><?= h($a['username'] ?? '') ?></td>
              <td><?= h($a['description'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>
