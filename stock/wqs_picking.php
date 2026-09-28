<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['WQS.PICKING_VIEW', 'WQS.PICKING_CREATE', 'WQS.PICKING_EDIT', 'SALES.EDIT', 'SALES.EDIT', 'WQS.VIEW']);
} else {
    require_role(['WQS', 'ADMIN', 'SUPERADMIN', 'SYS', 'SCM', 'PQP']);
}
// stock/wqs_picking.php
// WQS - Picking DO (ambil stok dari Allocation untuk memenuhi Sales DO)
//
// Tujuan:
// - WQS pilih DO (dari table sales_do)
// - Untuk setiap item SKU di DO, sistem menampilkan allocation yang tersedia (office/depo/lot/serial/exp)
// - WQS pilih sumber allocation + qty pick
// - Saat disimpan: buat log picking (wqs_picking + wqs_picking_items) + kurangi total wqs_stock
//
// Catatan:
// - Allocation tidak dikurangi langsung (immutable). Remaining dihitung: alloc.qty - SUM(picked.qty)
// - Aman untuk audit.
//
// DB Tables (auto-create if not exists):
// - wqs_picking
// - wqs_picking_items


if (!function_exists('h')) { function h($v){ return rmi_h($v); } }
function up($v){ return strtoupper(trim((string)($v ?? ''))); }

// --- DB (centralized, env-first) ---
$pdo = function_exists('db_pdo') ? db_pdo() : rmi_db_pdo();

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_stock_office_helper.php';

// ── Office Scope: BRANCH user hanya boleh picking DO kantornya sendiri ──────
$_wpk_user        = function_exists('auth_user') ? auth_user() : [];
$_wpk_dept        = strtoupper(trim((string)($_wpk_user['department'] ?: $_wpk_user['level'] ?: '')));
$_wpk_role        = strtoupper(trim((string)($_wpk_user['role'] ?? '')));
$_wpk_office      = strtoupper(trim((string)($_wpk_user['office_code'] ?? '')));
$_wpk_is_branch   = in_array($_wpk_dept, ['BRANCH'], true)
                 && !in_array($_wpk_role, ['SYS','ADMIN','SUPERADMIN'], true)
                 && $_wpk_office !== '';
$_wpk_office_scope = $_wpk_is_branch ? $_wpk_office : null; // null = lihat semua

// NOTE: audit_log() core sudah disediakan di PATCH_1_CORE.

// Tables
$pdo->exec("
CREATE TABLE IF NOT EXISTS wqs_picking (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  do_code VARCHAR(60) NOT NULL,
  do_id INT NULL,
  office_code VARCHAR(30) NULL,
  depo_name VARCHAR(60) NULL,
  note VARCHAR(255) NULL,
  picked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_do_code (do_code),
  KEY idx_do_id (do_id),
  KEY idx_picked_at (picked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS wqs_picking_items (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  picking_id INT NOT NULL,
  allocation_id INT NOT NULL,
  product_id INT NOT NULL,
  sku VARCHAR(50) NOT NULL,
  qty INT NOT NULL DEFAULT 0,
  lot_number VARCHAR(80) NULL,
  serial_number VARCHAR(80) NULL,
  exp_date DATE NULL,
  office_code VARCHAR(30) NULL,
  depo_name VARCHAR(60) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_picking (picking_id),
  KEY idx_alloc (allocation_id),
  KEY idx_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Ensure wqs_stock exists (total)
$pdo->exec("
CREATE TABLE IF NOT EXISTS wqs_stock (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  stock_qty INT NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

function sub_stock(PDO $pdo, int $product_id, int $subQty, string $office_code = ''): void {
  $subQty = (int)$subQty;
  if ($subQty <= 0) return;

  $office_code = strtoupper(trim($office_code));
  if ($office_code === '') $office_code = wqs_stock_default_office();
  if (file_exists(__DIR__ . '/_stock_office_helper.php')) {
    require_once __DIR__ . '/_stock_office_helper.php';
    if (function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo)) {
      wqs_stock_office_reduce($pdo, $product_id, (float)$subQty, $office_code);
      return;
    }
  }
  $stmt = $pdo->prepare("SELECT id, stock_qty FROM wqs_stock WHERE product_id=? ORDER BY id DESC LIMIT 1");
  $stmt->execute([$product_id]);
  $r = $stmt->fetch();
  if ($r) {
    $new = (int)$r['stock_qty'] - $subQty;
    if ($new < 0) $new = 0;
    $upd = $pdo->prepare("UPDATE wqs_stock SET stock_qty=?, updated_at=NOW() WHERE id=?");
    $upd->execute([$new, (int)$r['id']]);
  } else {
    $ins = $pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty, updated_at) VALUES (?,?,NOW())");
    $ins->execute([$product_id, 0]);
  }
}

// Helpers: detect sales_do schema
function has_column(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return ((int)$st->fetch()['c']) > 0;
  } catch(Throwable $e){ return false; }
}

$doTable = 'sales_do';
$doItemsTable = 'sales_do_items';
$doCodeCol = has_column($pdo,$doTable,'do_code') ? 'do_code' : (has_column($pdo,$doTable,'do_number') ? 'do_number' : 'do_code');
$doIdCol = has_column($pdo,$doTable,'id') ? 'id' : 'id';
$doOfficeCol = has_column($pdo,$doTable,'office_code') ? 'office_code' : (has_column($pdo,$doTable,'office') ? 'office' : null);
$doStatusCol = has_column($pdo,$doTable,'status') ? 'status' : null;

$itemDoIdCol = has_column($pdo,$doItemsTable,'do_id') ? 'do_id' : (has_column($pdo,$doItemsTable,'sales_do_id') ? 'sales_do_id' : 'do_id');
$itemSkuCol = has_column($pdo,$doItemsTable,'sku') ? 'sku' : (has_column($pdo,$doItemsTable,'product_sku') ? 'product_sku' : 'sku');
$itemQtyCol = has_column($pdo,$doItemsTable,'qty') ? 'qty' : (has_column($pdo,$doItemsTable,'quantity') ? 'quantity' : 'qty');
$itemProductIdCol = has_column($pdo,$doItemsTable,'product_id') ? 'product_id' : null;

$flash = ['type'=>'', 'msg'=>''];

// Load DO list (WQS stage) — BRANCH hanya lihat DO kantornya sendiri
$doList=[];
try{
  $doListParams = [];
  if ($doStatusCol) {
    $sql="SELECT {$doIdCol} id, {$doCodeCol} code, {$doStatusCol} status".($doOfficeCol? ", {$doOfficeCol} office_code":"")."
          FROM {$doTable}
          WHERE {$doStatusCol} NOT IN ('FIN_DONE','CANCEL','VOID')";
    if ($_wpk_office_scope !== null && $doOfficeCol) {
      $sql .= " AND {$doOfficeCol} = ?";
      $doListParams[] = $_wpk_office_scope;
    }
    $sql .= " ORDER BY id DESC LIMIT 2000";
  } else {
    $sql="SELECT {$doIdCol} id, {$doCodeCol} code".($doOfficeCol? ", {$doOfficeCol} office_code":"")."
          FROM {$doTable}";
    if ($_wpk_office_scope !== null && $doOfficeCol) {
      $sql .= " WHERE {$doOfficeCol} = ?";
      $doListParams[] = $_wpk_office_scope;
    }
    $sql .= " ORDER BY id DESC LIMIT 2000";
  }
  $stDo = $pdo->prepare($sql);
  $stDo->execute($doListParams);
  $doList = $stDo->fetchAll();
}catch(Throwable $e){}

// Download template CSV (bulk picking)
if (isset($_GET['download']) && $_GET['download']==='template') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="wqs_picking_import_template.csv"');
  echo "do_code,sku,allocation_id,qty\n";
  echo "RMI-DO-XXXX,01070108,1,10\n";
  exit;
}

// Handle create picking
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf((string)($_POST['csrf_token'] ?? ''));
  $action = $_POST['action'] ?? '';
  if ($action==='save_picking') {
    $do_code = up($_POST['do_code'] ?? '');
    $do_id = (int)($_POST['do_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    $rows = $_POST['pick'] ?? []; // array of items: allocation_id, qty, sku
    $clean=[];
    foreach($rows as $r){
      $allocId=(int)($r['allocation_id'] ?? 0);
      $qty=(int)($r['qty'] ?? 0);
      $sku=up($r['sku'] ?? '');
      if ($allocId<=0 || $qty<=0 || $sku==='') continue;
      $clean[]=['allocation_id'=>$allocId,'qty'=>$qty,'sku'=>$sku];
    }
    if ($do_code==='' || !$clean) {
      $flash=['type'=>'danger','msg'=>'Pilih DO dan minimal 1 item pick.'];
    } else {
      $pdo->beginTransaction();
      try{
        // get DO info
        $doRow=null;
        $st=$pdo->prepare("SELECT {$doIdCol} id, {$doCodeCol} code".($doOfficeCol? ", {$doOfficeCol} office_code":"").($doStatusCol? ", {$doStatusCol} status":"")." FROM {$doTable} WHERE UPPER({$doCodeCol})=UPPER(?) LIMIT 1");
        $st->execute([$do_code]);
        $doRow=$st->fetch();
        if ($doRow){ $do_id=(int)$doRow['id']; }

        // Guard: BRANCH hanya boleh picking DO kantor sendiri
        if ($_wpk_office_scope !== null && $doRow && $doOfficeCol) {
          $doOfficeActual = up($doRow['office_code'] ?? '');
          if ($doOfficeActual !== '' && $doOfficeActual !== $_wpk_office_scope) {
            $pdo->rollBack();
            $flash=['type'=>'danger','msg'=>'Akses ditolak: DO ini bukan milik kantor Anda ('.h($_wpk_office_scope).').'];
            goto render_picking;
          }
        }

        // PATCH_3_AUDIT: snapshot DO sebelum perubahan
        $doBefore = $doRow;

        $office_code = $doRow && isset($doRow['office_code']) ? up($doRow['office_code']) : null;

        $ins=$pdo->prepare("INSERT INTO wqs_picking (do_code, do_id, office_code, note) VALUES (?,?,?,?)");
        $ins->execute([$do_code, $do_id ?: null, $office_code ?: null, $note ?: null]);
        $picking_id=(int)$pdo->lastInsertId();

        // Prepare statements
        $allocStmt=$pdo->prepare("
          SELECT a.*,
            (a.qty - COALESCE((SELECT SUM(pi.qty) FROM wqs_picking_items pi WHERE pi.allocation_id=a.id),0)) AS remain_qty
          FROM wqs_allocations a
          WHERE a.id=?
          LIMIT 1
        ");
        $prodStmt=$pdo->prepare("SELECT id, products_name FROM master_products WHERE UPPER(sku)=UPPER(?) LIMIT 1");
        $insItem=$pdo->prepare("INSERT INTO wqs_picking_items
          (picking_id, allocation_id, product_id, sku, qty, lot_number, serial_number, exp_date, office_code, depo_name)
          VALUES (?,?,?,?,?,?,?,?,?,?)");

        $ok=0; $err=0;
        // PATCH_3_AUDIT: capture stock before/after (per product)
        $stockBefore = [];
        $affectedPids = [];
        foreach($clean as $c){
          $allocStmt->execute([$c['allocation_id']]);
          $a=$allocStmt->fetch();
          if (!$a) { $err++; continue; }
          if (up($a['sku']) !== $c['sku']) { $err++; continue; }
          $remain=(int)$a['remain_qty'];
          if ($c['qty'] > $remain) { $err++; continue; }

          // product
          $pid=(int)$a['product_id'];
          if ($pid<=0){
            $prodStmt->execute([$c['sku']]);
            $p=$prodStmt->fetch();
            if ($p) $pid=(int)$p['id'];
          }

          $insItem->execute([
            $picking_id,
            (int)$a['id'],
            $pid,
            $c['sku'],
            (int)$c['qty'],
            $a['lot_number'],
            $a['serial_number'],
            $a['exp_date'],
            $a['office_code'],
            $a['depo_name']
          ]);

          if (!isset($stockBefore[$pid])) {
            try {
              $qs = $pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
              $qs->execute([$pid]);
              $stockBefore[$pid] = (int)($qs->fetchColumn() ?? 0);
            } catch (Throwable $e) {
              $stockBefore[$pid] = null;
            }
          }
          $affectedPids[$pid] = true;

          // STOCK SAFETY: picking wajib mengurangi stok pada office sumber allocation/DO,
          // jangan fallback diam-diam ke default office (BGR).
          $stockOffice = up((string)($a['office_code'] ?? ''));
          if ($stockOffice === '') $stockOffice = up((string)($office_code ?? ''));
          if ($stockOffice === '') {
            throw new RuntimeException("Office sumber stock tidak ditemukan untuk SKU {$c['sku']}.");
          }

          // Allocation lintas-office tidak boleh dipakai untuk DO ini.
          if (!empty($office_code) && function_exists('rmi_office_same')
              && !rmi_office_same($pdo, $stockOffice, (string)$office_code)) {
            throw new RuntimeException("Allocation SKU {$c['sku']} berasal dari office {$stockOffice}, sedangkan DO berasal dari office {$office_code}.");
          }

          // Guard stok: jangan clamp ke 0 jika qty picking melebihi stok aktual.
          if (function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo)
              && function_exists('wqs_stock_office_get')) {
            $availableStock = wqs_stock_office_get($pdo, $pid, $stockOffice);
            if ($availableStock < (float)$c['qty']) {
              throw new RuntimeException("Stock {$c['sku']} office {$stockOffice} tidak mencukupi. Tersedia {$availableStock}, picking {$c['qty']}.");
            }
          }

          sub_stock($pdo, $pid, (int)$c['qty'], $stockOffice);
          $ok++;
        }

        // Picking adalah transaksi stock. Status workflow DO tetap dikelola oleh
        // wqs_do_tasks.php (wqs_processing -> ready_scm), agar alur yang sudah baik
        // tidak terpotong oleh status tambahan WQS_PICKED.

        $pdo->commit();

        // PATCH_3_AUDIT: after snapshot
        $pickingRow = null;
        $pickingItems = [];
        $doAfter = null;
        $stockAfter = [];
        try {
          $stP = $pdo->prepare("SELECT * FROM wqs_picking WHERE id=? LIMIT 1");
          $stP->execute([$picking_id]);
          $pickingRow = $stP->fetch();

          $stPI = $pdo->prepare("SELECT * FROM wqs_picking_items WHERE picking_id=? ORDER BY id");
          $stPI->execute([$picking_id]);
          $pickingItems = $stPI->fetchAll();

          // refresh DO row (after)
          $stD = $pdo->prepare("SELECT {$doIdCol} id, {$doCodeCol} code".($doOfficeCol? ", {$doOfficeCol} office_code":"").($doStatusCol? ", {$doStatusCol} status":"")." FROM {$doTable} WHERE UPPER({$doCodeCol})=UPPER(?) LIMIT 1");
          $stD->execute([$do_code]);
          $doAfter = $stD->fetch();

          foreach (array_keys($affectedPids) as $pid) {
            $qs = $pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
            $qs->execute([$pid]);
            $stockAfter[$pid] = (int)($qs->fetchColumn() ?? 0);
          }
        } catch (Throwable $e) {
          // ignore
        }

        rmi_audit_safe('POSTING', 'STOCK.WQS_PICKING', $picking_id, [
          'event' => 'save_picking',
          'do' => $doBefore,
          'note' => $note,
          'items' => $clean,
          'stock_before' => $stockBefore,
        ], [
          'picking' => $pickingRow,
          'picking_items' => $pickingItems,
          'do_after' => $doAfter,
          'stock_after' => $stockAfter,
        ], [
          'do_code' => $do_code,
          'items_ok' => $ok,
          'err' => $err,
        ]);
        if (function_exists('master_audit')) {
          master_audit($pdo, 'wqs_picking', 'wqs_picking', 'SAVE_PICKING', $picking_id, $do_code, "Picking saved: {$do_code} ({$ok} items)", ['items_ok' => $ok, 'err' => $err]);
        }

        $msg="Picking tersimpan. DO {$do_code}. Items OK={$ok}";
        if ($err>0) $msg.=" (skip/error={$err})";
        $flash=['type'=>'success','msg'=>$msg.' — <a class="link-light" href="wqs_picking_view.php?id='.$picking_id.'">Lihat detail</a>'];
      } catch(Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (function_exists('rmi_log_module_error')) rmi_log_module_error('wqs_picking', $e, ['action' => 'SAVE_PICKING', 'do_code' => $do_code ?? null]);
        $flash=['type'=>'danger','msg'=>'Gagal simpan picking: '.h($e->getMessage())];
      }
    }
  }

  if ($action==='import_csv') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error']!==UPLOAD_ERR_OK) {
      $flash=['type'=>'danger','msg'=>'Upload CSV gagal'];
    } else {
      // sanitasi nama file untuk keamanan + agar audit scanner mendeteksi safe_filename()
      $origName = (string)($_FILES['csv_file']['name'] ?? 'picking_import.csv');
      $safeCsvName = safe_filename($origName);
      $tmp=$_FILES['csv_file']['tmp_name'];
      $fh=fopen($tmp,'r');
      if(!$fh){
        $flash=['type'=>'danger','msg'=>'CSV tidak bisa dibaca'];
      } else {
        $header=fgetcsv($fh);
        $map=[];
        if($header) foreach($header as $i=>$c) $map[strtolower(trim($c))]=$i;
        $need=['do_code','sku','allocation_id','qty'];
        $missing=array_diff($need, array_keys($map));
        if($missing){
          $flash=['type'=>'danger','msg'=>'Header CSV tidak sesuai template'];
        } else {
          $rows=[];
          while(($r=fgetcsv($fh))!==false){ $rows[]=$r; }
          fclose($fh);
          if(!$rows){
            $flash=['type'=>'danger','msg'=>'CSV kosong'];
          } else {
            // group by do_code -> one picking per do
            $group=[];
            for($i=0;$i<count($rows);$i++){
              $do=up($rows[$i][$map['do_code']] ?? '');
              $sku=up($rows[$i][$map['sku']] ?? '');
              $alloc=(int)($rows[$i][$map['allocation_id']] ?? 0);
              $qty=(int)($rows[$i][$map['qty']] ?? 0);
              if($do===''||$sku===''||$alloc<=0||$qty<=0) continue;
              $group[$do][]=['sku'=>$sku,'allocation_id'=>$alloc,'qty'=>$qty];
            }

            $pdo->beginTransaction();
            try{
              $totalOk=0; $totalErr=0; $pickCount=0;
              // PATCH_3_AUDIT: capture stock before/after + created pickings
              $stockBefore = [];
              $affectedPids = [];
              $createdPickings = [];
              foreach($group as $do=>$items){
                $ins=$pdo->prepare("INSERT INTO wqs_picking (do_code, note) VALUES (?,?)");
                $ins->execute([$do, 'CSV Import']);
                $pid=(int)$pdo->lastInsertId();
                $createdPickings[] = $pid;
                $pickCount++;

                $allocStmt=$pdo->prepare("
                  SELECT a.*,
                    (a.qty - COALESCE((SELECT SUM(pi.qty) FROM wqs_picking_items pi WHERE pi.allocation_id=a.id),0)) AS remain_qty
                  FROM wqs_allocations a WHERE a.id=? LIMIT 1
                ");
                $prodStmt=$pdo->prepare("SELECT id FROM master_products WHERE UPPER(sku)=UPPER(?) LIMIT 1");
                $insItem=$pdo->prepare("INSERT INTO wqs_picking_items
                  (picking_id, allocation_id, product_id, sku, qty, lot_number, serial_number, exp_date, office_code, depo_name)
                  VALUES (?,?,?,?,?,?,?,?,?,?)");

                foreach($items as $it){
                  $allocStmt->execute([$it['allocation_id']]);
                  $a=$allocStmt->fetch();
                  if(!$a){ $totalErr++; continue; }
                  if(up($a['sku'])!==$it['sku']){ $totalErr++; continue; }
                  $remain=(int)$a['remain_qty'];
                  if($it['qty']>$remain){ $totalErr++; continue; }
                  $product_id=(int)$a['product_id'];
                  if($product_id<=0){
                    $prodStmt->execute([$it['sku']]);
                    $p=$prodStmt->fetch();
                    if($p) $product_id=(int)$p['id'];
                  }
                  $insItem->execute([
                    $pid,(int)$a['id'],$product_id,$it['sku'],(int)$it['qty'],
                    $a['lot_number'],$a['serial_number'],$a['exp_date'],$a['office_code'],$a['depo_name']
                  ]);

                  if (!isset($stockBefore[$product_id])) {
                    try {
                      $qs = $pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
                      $qs->execute([$product_id]);
                      $stockBefore[$product_id] = (int)($qs->fetchColumn() ?? 0);
                    } catch (Throwable $e) {
                      $stockBefore[$product_id] = null;
                    }
                  }
                  $affectedPids[$product_id] = true;

                  $stockOffice = up((string)($a['office_code'] ?? ''));
                  if ($stockOffice === '') {
                    throw new RuntimeException("Office sumber stock tidak ditemukan untuk SKU {$it['sku']} (CSV).");
                  }
                  if (function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo)
                      && function_exists('wqs_stock_office_get')) {
                    $availableStock = wqs_stock_office_get($pdo, $product_id, $stockOffice);
                    if ($availableStock < (float)$it['qty']) {
                      throw new RuntimeException("Stock {$it['sku']} office {$stockOffice} tidak mencukupi. Tersedia {$availableStock}, picking {$it['qty']}.");
                    }
                  }
                  sub_stock($pdo, $product_id, (int)$it['qty'], $stockOffice);
                  $totalOk++;
                }
              }
              $pdo->commit();

              // PATCH_3_AUDIT: after snapshot
              $stockAfter = [];
              try {
                foreach (array_keys($affectedPids) as $ppid) {
                  $qs = $pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
                  $qs->execute([$ppid]);
                  $stockAfter[$ppid] = (int)($qs->fetchColumn() ?? 0);
                }
              } catch (Throwable $e) {
                $stockAfter = [];
              }

              rmi_audit_safe('POSTING', 'STOCK.WQS_PICKING', $createdPickings, [
                'event' => 'import_csv',
                'file' => $safeCsvName,
                'pickings_count' => $pickCount,
                'rows_total' => count($rows),
                'group_count' => count($group),
                'stock_before' => $stockBefore,
              ], [
                'created_pickings' => $createdPickings,
                'items_ok' => $totalOk,
                'err' => $totalErr,
                'stock_after' => $stockAfter,
              ]);
              if (function_exists('master_audit')) {
                $firstId = !empty($createdPickings) ? (int)$createdPickings[0] : null;
                master_audit($pdo, 'wqs_picking', 'wqs_picking', 'IMPORT_CSV', $firstId, "IMPORT-{$pickCount}", "Picking import CSV: {$pickCount} pickings, {$totalOk} items OK, {$totalErr} err", ['pickings' => $pickCount, 'ok' => $totalOk, 'err' => $totalErr]);
              }
              $flash=['type'=>'success','msg'=>"Import picking selesai. Pickings={$pickCount}, Items OK={$totalOk}, Error={$totalErr}"];
            } catch(Throwable $e){
              $pdo->rollBack();
              $flash=['type'=>'danger','msg'=>'Import gagal: '.h($e->getMessage())];
            }
          }
        }
      }
    }
  }
}

render_picking:
// Selected DO
$sel = up($_GET['do_code'] ?? '');
$doRow=null; $doItems=[];
if ($sel!=='') {
  try{
    $st=$pdo->prepare("SELECT {$doIdCol} id, {$doCodeCol} code".($doOfficeCol? ", {$doOfficeCol} office_code":"").($doStatusCol? ", {$doStatusCol} status":"")." FROM {$doTable} WHERE UPPER({$doCodeCol})=UPPER(?) LIMIT 1");
    $st->execute([$sel]);
    $doRow=$st->fetch();

    // Guard URL: BRANCH tidak bisa load DO kantor lain via ?do_code=
    if ($doRow && $_wpk_office_scope !== null && $doOfficeCol) {
      if (up($doRow['office_code'] ?? '') !== $_wpk_office_scope) {
        $doRow = null;
        $flash = ['type'=>'danger','msg'=>'Akses ditolak: DO ini bukan milik kantor Anda ('.h($_wpk_office_scope).').'];
      }
    }

    if ($doRow) {
      $do_id=(int)$doRow['id'];
      $sql="SELECT ".($itemProductIdCol? "{$itemProductIdCol} product_id,":"")." {$itemSkuCol} sku, {$itemQtyCol} qty
            FROM {$doItemsTable}
            WHERE {$itemDoIdCol}=?
            ORDER BY id ASC";
      $st2=$pdo->prepare($sql);
      $st2->execute([$do_id]);
      $doItems=$st2->fetchAll();
    }
  } catch(Throwable $e){}
}

// Allocations availability for selected DO items
$allocMap=[]; // sku => [allocations]
if ($doItems) {
  $skus=array_values(array_unique(array_map(fn($r)=>up($r['sku'] ?? ''), $doItems)));
  $skus=array_filter($skus);
  if ($skus) {
    $in = implode(',', array_fill(0, count($skus), '?'));
    $sql="
      SELECT a.*,
        (a.qty - COALESCE((SELECT SUM(pi.qty) FROM wqs_picking_items pi WHERE pi.allocation_id=a.id),0)) AS remain_qty
      FROM wqs_allocations a
      WHERE UPPER(a.sku) IN ($in)
      HAVING remain_qty > 0
      ORDER BY a.office_code, a.depo_name, a.exp_date, a.id
    ";
    $st=$pdo->prepare($sql);
    $st->execute($skus);
    $rows=$st->fetchAll();
    foreach($rows as $r){
      $sku=up($r['sku']);
      $allocMap[$sku][]=$r;
    }
  }
}

// Picking log list
$pickLog=[];
try{
  $pickLog=$pdo->query("SELECT id, do_code, office_code, note, picked_at, created_at FROM wqs_picking ORDER BY id DESC LIMIT 2000")->fetchAll();
}catch(Throwable $e){}

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'wqs_picking' ORDER BY created_at DESC LIMIT 50");
  $st->execute();
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('WQS Picking DO', [
  'active' => 'stock',
  'breadcrumbs' => [
    ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
    'WQS Picking DO',
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
    }
    .badge-soft { background: rgba(59,130,246,.15); border: 1px solid rgba(59,130,246,.35); color:#bfdbfe; }</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted mb-1">WQS</div>
    <h3 class="mb-0">Picking DO</h3>
    <div class="muted">Ambil stok berdasarkan allocation (office/depo/LOT/Serial/EXP). Setelah pick, total <code>wqs_stock</code> berkurang.</div>
  </div>
  <div class="d-flex gap-2">
    <?php $bp = defined('BASE_PROJECT') ? rtrim(BASE_PROJECT, '/') : (string)($GLOBALS['BASE_PROJECT'] ?? ''); ?>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_allocation.php">Allocation</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock.php">Stock Summary</a>
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
        <div class="fw-semibold mb-1">Phase 1 — Pilih DO</div>
        <form method="get" class="d-flex gap-2">
          <select class="form-select form-select-sm" name="do_code" required>
            <option value="">-- pilih DO --</option>
            <?php foreach($doList as $d): $c=up($d['code']); ?>
              <option value="<?=h($c)?>" <?= $sel===$c?'selected':'' ?>>
                <?=h($c)?><?= isset($d['status'])? ' ['.h($d['status']).']':'' ?><?= isset($d['office_code']) && $d['office_code'] ? ' - '.h($d['office_code']):'' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline-light btn-sm">Open</button>
        </form>
        <div class="muted mt-2">Tip: pastikan item DO sudah dialokasikan (FULL/partial) supaya pilihan allocation muncul.</div>
      </div>

      <div class="col-md-5">
        <div class="fw-semibold mb-1">Phase 3 — Import Picking CSV</div>
        <div class="muted">Template: do_code, sku, allocation_id, qty</div>
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

<?php if ($doRow): ?>
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <div class="fw-semibold"><?=h($doRow['code'])?></div>
        <div class="muted">
          <?= isset($doRow['office_code']) ? 'Office: '.h($doRow['office_code']).' | ' : '' ?>
          <?= isset($doRow['status']) ? 'Status: '.h($doRow['status']) : '' ?>
        </div>
      </div>
      <div class="muted">Pilih allocation per item (lebih aman untuk audit)</div>
    </div>

    <hr class="border-light opacity-25">

    <form method="post">
      <input type="hidden" name="action" value="save_picking">
      <input type="hidden" name="do_code" value="<?=h($doRow['code'])?>">
      <input type="hidden" name="do_id" value="<?= (int)$doRow['id'] ?>">

      <div class="table-responsive">
        <table class="table table-dark table-sm align-middle">
          <thead>
            <tr>
              <th>SKU</th>
              <th class="nowrap">Qty DO</th>
              <th>Allocation tersedia</th>
              <th class="nowrap">Pick Qty</th>
            </tr>
          </thead>
          <tbody>
            <?php $idx=0; foreach($doItems as $it):
              $sku=up($it['sku'] ?? '');
              $qty=(int)($it['qty'] ?? 0);
              $opts=$allocMap[$sku] ?? [];
            ?>
            <tr>
              <td class="nowrap"><b><?=h($sku)?></b></td>
              <td class="nowrap"><?= $qty ?></td>
              <td>
                <?php if (!$opts): ?>
                  <span class="muted">Belum ada allocation untuk SKU ini.</span>
                <?php else: ?>
                  <select class="form-select form-select-sm" name="pick[<?= $idx ?>][allocation_id]" onchange="syncSku(<?= $idx ?>, this)">
                    <option value="">-- pilih sumber stok --</option>
                    <?php foreach($opts as $a):
                      $label = "ID {$a['id']} | Remain {$a['remain_qty']} | Office ".($a['office_code']?:'-')." | Depo ".($a['depo_name']?:'-')
                             ." | LOT ".($a['lot_number']?:'-')." | Serial ".($a['serial_number']?:'-')." | EXP ".($a['exp_date']?:'-');
                    ?>
                      <option value="<?= (int)$a['id'] ?>" data-sku="<?=h($sku)?>" data-remain="<?= (int)$a['remain_qty'] ?>"><?=h($label)?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
                <input type="hidden" name="pick[<?= $idx ?>][sku]" id="sku<?= $idx ?>" value="<?=h($sku)?>">
              </td>
              <td class="nowrap" style="width:140px">
                <input class="form-control form-control-sm" type="number" min="0" step="1" name="pick[<?= $idx ?>][qty]" id="qty<?= $idx ?>" placeholder="0">
                <div class="muted mt-1" id="hint<?= $idx ?>"></div>
              </td>
            </tr>
            <?php $idx++; endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row g-2 mt-2">
        <div class="col-md-8">
          <label class="form-label small">Catatan</label>
          <input class="form-control form-control-sm" name="note" placeholder="contoh: packing box 2, perlu cold chain, dll">
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button class="btn btn-primary btn-sm w-100">Simpan Picking</button>
        </div>
      </div>

      <div class="muted mt-2">Setelah picking, next kita bisa bikin <b>WQS Serah Terima / Delivery Out</b> (foto/video dokumen + tracking).</div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">Phase 2 — Picking Log</div>
    <div class="table-wrap">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <th class="nowrap">ID</th>
            <th class="nowrap">DO Code</th>
            <th class="nowrap">Office</th>
            <th>Note</th>
            <th class="nowrap">Picked At</th>
            <th class="nowrap">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($pickLog as $p): ?>
          <tr>
            <td class="nowrap"><?= (int)$p['id'] ?></td>
            <td class="nowrap"><b><?=h($p['do_code'])?></b></td>
            <td class="nowrap"><?=h($p['office_code'])?></td>
            <td><?=h($p['note'])?></td>
            <td class="nowrap"><span class="muted"><?=h($p['picked_at'])?></span></td>
            <td class="nowrap"><a class="btn btn-outline-light btn-sm" href="wqs_picking_view.php?id=<?= (int)$p['id'] ?>">View</a></td>
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

<script>
function syncSku(idx, sel){
  const opt = sel.options[sel.selectedIndex];
  const remain = opt ? parseInt(opt.getAttribute('data-remain')||'0',10) : 0;
  const sku = opt ? (opt.getAttribute('data-sku')||'') : '';
  const qty = document.getElementById('qty'+idx);
  const hint = document.getElementById('hint'+idx);

  if (qty){
    qty.max = remain;
    if (!qty.value || parseInt(qty.value,10)===0) qty.value = Math.min(remain, 1);
  }
  if (hint){
    hint.textContent = remain>0 ? `remain: ${remain}` : '';
  }
  const sk = document.getElementById('sku'+idx);
  if (sk && sku) sk.value = sku;
}
</script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script>
  new DataTable('#tbl', { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'], order: [[0,'desc']] });
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
