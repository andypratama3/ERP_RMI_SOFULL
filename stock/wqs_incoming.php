<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT', 'PURCHASES.GR_PROCESS', 'WQS.VIEW']);
} else {
    require_role(['WQS', 'ADMIN', 'SUPERADMIN', 'SYS', 'SCM', 'PQP']);
}
// stock/wqs_incoming.php
// WQS - Incoming (Barang Datang) -> update wqs_stock + simpan detail LOT/Serial/EXP + lokasi (Office/Depo)
//
// DB Tables (auto create if not exists):
// - wqs_incoming
// - wqs_incoming_items
//
// Phase 1: create incoming + items + optional upload docs
// Phase 2: list incoming + export
// Phase 3: CSV import (bulk create one incoming) + audit log


if (!function_exists('h')) { function h($v){ return rmi_h($v); } }
function up($v){ return strtoupper(trim((string)($v ?? ''))); }
function normalize_sku_wqs($v){
  $v = up($v);
  // Normalisasi SKU untuk validasi PO vs Incoming: hapus semua spasi/tab/newline.
  // Contoh: 'SKP-IP-00325-20/ 8*60' == 'SKP-IP-00325-20/8*60'.
  $v = preg_replace('/\s+/', '', $v);
  return $v;
}

// --- DB (centralized) ---
$pdo = db_pdo();


/**
 * FIX FIFO MULTI-ROW:
 * Pastikan tabel wqs_stock_by_office punya kolom id unik untuk stok per lot/baris.
 * Aman dijalankan berulang; jika id sudah ada, dilewati.
 */
function wqs_incoming_ensure_wqs_stock_by_office_id(PDO $pdo): void
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

try { wqs_incoming_ensure_wqs_stock_by_office_id($pdo); } catch (Throwable $e) {}

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/_stock_office_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/../_shared/helpers.php'; // safe_filename, csrf

// NOTE: audit_log() core sudah disediakan di PATCH_1_CORE.
// Jangan define ulang di file ini untuk menghindari fatal redeclare.

function has_column(PDO $pdo, string $table, string $column): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
  $stmt->execute([$table, $column]);
  return ((int)$stmt->fetchColumn()) > 0;
}

$schemaErrors = [];
if (!has_column($pdo, 'wqs_incoming', 'po_id')) {
  $schemaErrors[] = 'Kolom wqs_incoming.po_id belum tersedia. Jalankan migration terbaru.';
}
if (!has_column($pdo, 'wqs_incoming_items', 'po_item_id')) {
  $schemaErrors[] = 'Kolom wqs_incoming_items.po_item_id belum tersedia. Jalankan migration terbaru.';
}

function ensure_column(PDO $pdo, string $table, string $column, string $ddl): void {
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() === 0) $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
  } catch (Throwable $e) { /* fail-soft */ }
}
ensure_column($pdo, 'wqs_incoming', 'wqs_stock_before', "wqs_stock_before VARCHAR(255) NULL");
ensure_column($pdo, 'wqs_incoming', 'wqs_stock_after', "wqs_stock_after VARCHAR(255) NULL");
ensure_column($pdo, 'wqs_incoming', 'created_by', "created_by VARCHAR(100) NULL");
ensure_column($pdo, 'wqs_incoming', 'goods_arrival_photos', "goods_arrival_photos LONGTEXT NULL");
ensure_column($pdo, 'wqs_incoming', 'goods_arrival_videos', "goods_arrival_videos LONGTEXT NULL");

function next_incoming_code(PDO $pdo): string {
  $date = date('Ymd');
  $prefix = "INC-$date-";
  $stmt = $pdo->prepare("SELECT incoming_code FROM wqs_incoming WHERE incoming_code LIKE ? ORDER BY id DESC LIMIT 1");
  $stmt->execute([$prefix.'%']);
  $last = $stmt->fetch();
  if (!$last) return $prefix.'0001';
  $code = $last['incoming_code'];
  $n = (int)substr($code, -4);
  $n++;
  return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function get_product(PDO $pdo, string $sku): ?array {
  $stmt = $pdo->prepare("SELECT id, sku, products_name, unit, barcode FROM master_products WHERE UPPER(sku)=UPPER(?) LIMIT 1");
  $stmt->execute([$sku]);
  $r = $stmt->fetch();
  return $r ?: null;
}


// =========================
// HARD VALIDATION (PO -> Incoming)
// Incoming harus:
// - SKU harus ada di PO
// - Total qty per SKU (gabungan beberapa LOT/serial) tidak boleh melebihi outstanding (PO qty - received)
// - Office incoming harus sama dengan Office PO (kalau PO punya office_code)
// =========================

function po_outstanding_map(PDO $pdo, string $po_code): array {
  $po_code = up($po_code);
  if ($po_code === '') return ['ok'=>false,'msg'=>'PO code kosong'];

  // PO header
  $st = $pdo->prepare("SELECT id, po_code, office_code FROM purchases_po WHERE UPPER(po_code)=UPPER(?) LIMIT 1");
  $st->execute([$po_code]);
  $po = $st->fetch();
  if (!$po) return ['ok'=>false,'msg'=>'PO tidak ditemukan: '.$po_code];

  $po_id = (int)$po['id'];

  // PO items aggregated by SKU normalisasi (skip deleted lines)
  // Catatan: SKU master/import sering berbeda hanya spasi, misalnya:
  // SKP-IP-00325-20/ 8*60 vs SKP-IP-00325-20/8*60.
  // Karena itu key map wajib pakai normalize_sku_wqs().
  $sqlItems = "
    SELECT
      MIN(i.id) AS po_item_id,
      COALESCE(NULLIF(UPPER(TRIM(i.sku)),''), UPPER(TRIM(p.sku))) AS sku,
      MIN(COALESCE(i.product_id, p.id, 0)) AS product_id,
      SUM(i.qty) AS po_qty
    FROM purchases_po_items i
    LEFT JOIN master_products p ON p.id = i.product_id
    WHERE i.po_id = ?
      AND (i.deleted_at IS NULL OR i.deleted_at = '0000-00-00 00:00:00')
    GROUP BY REPLACE(REPLACE(REPLACE(COALESCE(NULLIF(UPPER(TRIM(i.sku)),''), UPPER(TRIM(p.sku))), ' ', ''), CHAR(9), ''), CHAR(10), '')
  ";
  $it = $pdo->prepare($sqlItems);
  $it->execute([$po_id]);

  $items = [];
  while ($r = $it->fetch()) {
    $skuRaw = up($r['sku'] ?? '');
    $skuKey = normalize_sku_wqs($skuRaw);
    if ($skuKey === '') continue;
    $items[$skuKey] = [
      'po_item_id'=>(int)($r['po_item_id'] ?? 0),
      'sku'=>$skuRaw,
      'sku_key'=>$skuKey,
      'product_id'=>(int)($r['product_id'] ?? 0),
      'po_qty'=>(float)($r['po_qty'] ?? 0),
      'received_qty'=>0.0,
      'outstanding_qty'=>0.0,
    ];
  }
  if (!$items) return ['ok'=>false,'msg'=>'PO tidak punya item'];

  // Received qty aggregated by PO item / SKU normalisasi for this PO (sum semua incoming sebelumnya)
  $rc = $pdo->prepare("
    SELECT
      COALESCE(i.po_item_id, 0) AS po_item_id,
      UPPER(TRIM(i.sku)) AS sku,
      SUM(i.qty) AS received_qty
    FROM wqs_incoming_items i
    JOIN wqs_incoming inc ON inc.id = i.incoming_id
    WHERE inc.po_id = ? AND inc.deleted_at IS NULL
    GROUP BY COALESCE(i.po_item_id, 0), REPLACE(REPLACE(REPLACE(UPPER(TRIM(i.sku)), ' ', ''), CHAR(9), ''), CHAR(10), '')
  ");
  $rc->execute([$po_id]);
  while ($r = $rc->fetch()) {
    $poItemId = (int)($r['po_item_id'] ?? 0);
    $skuKey = normalize_sku_wqs($r['sku'] ?? '');
    $received = (float)($r['received_qty'] ?? 0);
    if ($poItemId > 0) {
      foreach ($items as $k => $item) {
        if ((int)($item['po_item_id'] ?? 0) === $poItemId) {
          $items[$k]['received_qty'] += $received;
          continue 2;
        }
      }
    }
    if ($skuKey !== '' && isset($items[$skuKey])) {
      $items[$skuKey]['received_qty'] += $received;
    }
  }

  // Compute outstanding
  foreach ($items as $sku => $row) {
    $out = (float)$row['po_qty'] - (float)$row['received_qty'];
    if ($out < 0) $out = 0;
    $items[$sku]['outstanding_qty'] = $out;
  }

  return [
    'ok'=>true,
    'po_id'=>$po_id,
    'po_code'=>up($po['po_code']),
    'office_code'=>up($po['office_code'] ?? ''),
    'items'=>$items,
  ];
}

/** Jika PO ke manufacture internal (kantor), return office_code supplier. Kosong = vendor eksternal. */
function get_po_supplier_office(PDO $pdo, int $po_id): string {
  $st = $pdo->prepare("SELECT UPPER(TRIM(COALESCE(m.internal_office_code,''))) AS oc
    FROM purchases_po p
    LEFT JOIN master_manufactures m ON m.id = p.manufacture_id
    WHERE p.id = ? LIMIT 1");
  $st->execute([$po_id]);
  $r = $st->fetch();
  return $r ? trim((string)($r['oc'] ?? '')) : '';
}

function validate_incoming_vs_po(PDO $pdo, string $po_code, string $office_code, array $cleanItems): array {
  $poInfo = po_outstanding_map($pdo, $po_code);
  if (empty($poInfo['ok'])) return $poInfo;

  $poOffice = up($poInfo['office_code'] ?? '');
  $office_code = up($office_code);

  if ($poOffice !== '' && $office_code !== '' && $poOffice !== $office_code) {
    return ['ok'=>false,'msg'=>"Office Incoming harus sama dengan Office PO ({$poOffice}). Kamu pilih: {$office_code}"];
  }

  // Sum request per SKU (untuk multi-lot/serial)
  $req = [];
  foreach ($cleanItems as $it) {
    $skuRaw = up($it['sku'] ?? '');
    $sku = normalize_sku_wqs($skuRaw);
    $qty = (float)($it['qty'] ?? 0);
    if ($sku === '' || $qty <= 0) continue;
    if (!isset($req[$sku])) $req[$sku] = 0;
    $req[$sku] += $qty;
  }
  if (!$req) return ['ok'=>false,'msg'=>'Tidak ada item valid untuk divalidasi'];

  $errs = [];
  foreach ($req as $sku => $qtyReq) {
    $displaySku = $poInfo['items'][$sku]['sku'] ?? $sku;
    if (!isset($poInfo['items'][$sku])) {
      $errs[] = "SKU {$sku} tidak ada di PO {$poInfo['po_code']}.";
      continue;
    }
    $out = (float)$poInfo['items'][$sku]['outstanding_qty'];
    $poQty = (float)$poInfo['items'][$sku]['po_qty'];
    $rcv = (float)$poInfo['items'][$sku]['received_qty'];

    if ($out <= 0) {
      $errs[] = "SKU {$displaySku} outstanding 0 (PO={$poQty}, received={$rcv}). Tidak bisa receive lagi.";
      continue;
    }
    if ($qtyReq > $out + 1e-9) {
      $errs[] = "SKU {$displaySku}: qty incoming {$qtyReq} melebihi outstanding {$out} (PO={$poQty}, received={$rcv}).";
      continue;
    }
    // ensure product_id exists (untuk insert stok)
    $pid = (int)($poInfo['items'][$sku]['product_id'] ?? 0);
    if ($pid <= 0) {
      $errs[] = "SKU {$displaySku}: product_id kosong di PO. Pastikan PO item sudah pilih SKU dari master_products.";
      continue;
    }
  }

  if ($errs) {
    return ['ok'=>false,'msg'=>"Validasi Incoming vs PO gagal:\n- ".implode("\n- ", $errs)];
  }

  return ['ok'=>true,'po'=>$poInfo];
}


/**
 * Prepare incoming items agar validasi PO aman untuk semua office/depo:
 * - Office incoming wajib sama dengan office PO.
 * - SKU yang tidak ada di PO tetap fatal (agar tidak salah barang).
 * - Qty yang melebihi outstanding tetap fatal (agar tidak over-receive).
 * - SKU dengan outstanding 0 di-skip, bukan menambah stok lagi.
 * - Multi LOT/serial untuk SKU sama dihitung memakai sisa outstanding berjalan.
 */
function prepare_incoming_items_vs_po(PDO $pdo, string $po_code, string $office_code, array $cleanItems): array {
  $poInfo = po_outstanding_map($pdo, $po_code);
  if (empty($poInfo['ok'])) return $poInfo;

  $poOffice = up($poInfo['office_code'] ?? '');
  $office_code = up($office_code);
  if ($poOffice !== '' && $office_code !== '' && $poOffice !== $office_code) {
    return ['ok'=>false,'msg'=>"Office Incoming harus sama dengan Office PO ({$poOffice}). Kamu pilih: {$office_code}"];
  }

  $items = $poInfo['items'] ?? [];
  $remaining = [];
  foreach ($items as $k => $row) {
    $remaining[$k] = (float)($row['outstanding_qty'] ?? 0);
  }

  $eligible = [];
  $skipped = [];
  $errs = [];

  foreach ($cleanItems as $idx => $it) {
    $skuRaw = up($it['sku'] ?? '');
    $skuKey = normalize_sku_wqs($skuRaw);
    $qty = (float)($it['qty'] ?? 0);
    if ($skuKey === '' || $qty <= 0) {
      $skipped[] = "Baris " . ($idx + 1) . " dilewati: SKU/Qty kosong.";
      continue;
    }
    if (!isset($items[$skuKey])) {
      $errs[] = "SKU {$skuRaw} tidak ada di PO {$poInfo['po_code']}.";
      continue;
    }

    $poRow = $items[$skuKey];
    $displaySku = $poRow['sku'] ?? $skuRaw;
    $out = (float)($remaining[$skuKey] ?? 0);
    $poQty = (float)($poRow['po_qty'] ?? 0);
    $rcv = (float)($poRow['received_qty'] ?? 0);

    if ($out <= 0) {
      $skipped[] = "SKU {$displaySku} dilewati: outstanding 0 (PO={$poQty}, received={$rcv}).";
      continue;
    }
    if ($qty > $out + 1e-9) {
      $errs[] = "SKU {$displaySku}: qty incoming {$qty} melebihi outstanding {$out} (PO={$poQty}, received={$rcv}).";
      continue;
    }

    $pid = (int)($poRow['product_id'] ?? 0);
    if ($pid <= 0) {
      $errs[] = "SKU {$displaySku}: product_id kosong di PO. Pastikan PO item sudah pilih SKU dari master_products.";
      continue;
    }

    $eligible[] = [
      'sku' => $displaySku,
      'qty' => $qty,
      'lot' => $it['lot'] ?? null,
      'serial' => $it['serial'] ?? null,
      'exp' => $it['exp'] ?? null,
      'product_id' => $pid,
      'po_item_id' => (int)($poRow['po_item_id'] ?? 0),
      'sku_key' => $skuKey,
    ];
    $remaining[$skuKey] = $out - $qty;
  }

  if ($errs) {
    return ['ok'=>false,'msg'=>"Validasi Incoming vs PO gagal:\n- " . implode("\n- ", $errs), 'skipped'=>$skipped];
  }
  if (!$eligible) {
    $msg = 'Tidak ada item yang bisa diproses. Semua item kosong atau outstanding PO sudah 0.';
    if ($skipped) $msg .= "\n- " . implode("\n- ", $skipped);
    return ['ok'=>false,'msg'=>$msg, 'skipped'=>$skipped];
  }

  return ['ok'=>true, 'po'=>$poInfo, 'eligible_items'=>$eligible, 'skipped'=>$skipped];
}

function add_stock(PDO $pdo, int $product_id, float $addQty, string $office_code = ''): void {
  $addQty = (float)$addQty;
  if ($addQty <= 0) return;

  $office_code = up($office_code);
  if ($office_code === '') $office_code = wqs_stock_default_office();
  if (file_exists(__DIR__ . '/_stock_office_helper.php')) {
    require_once __DIR__ . '/_stock_office_helper.php';
    if (function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo)) {
      wqs_stock_office_add($pdo, $product_id, (float)$addQty, $office_code);
      return;
    }
  }
  $stmt = $pdo->prepare("SELECT id, stock_qty FROM wqs_stock WHERE product_id=? ORDER BY id DESC LIMIT 1");
  $stmt->execute([$product_id]);
  $r = $stmt->fetch();
  if ($r) {
    $new = (float)$r['stock_qty'] + $addQty;
    $upd = $pdo->prepare("UPDATE wqs_stock SET stock_qty=?, updated_at=NOW() WHERE id=?");
    $upd->execute([$new, (int)$r['id']]);
  } else {
    $ins = $pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty, updated_at) VALUES (?,?,NOW())");
    $ins->execute([$product_id, $addQty]);
  }
}

/** Upload foto kartu stok (pola sama wqs_do_tasks) — untuk Stock Snapshot opname (docs/BASELINES.md) */
function upload_stock_card_file(string $field, string $dirRel): ?string {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $tmp = $f['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) return null;
    $name = (string)($f['name'] ?? 'file');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allow = ['jpg','jpeg','png','webp','pdf'];
    if (!in_array($ext, $allow, true)) return null;
    $base = realpath(__DIR__ . '/..');
    if ($base === false) return null;
    $dirAbs = $base . '/' . trim($dirRel, '/');
    if (!is_dir($dirAbs)) @mkdir($dirAbs, 0775, true);
    $clean = function_exists('safe_filename') ? safe_filename($name) : preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    $basePart = pathinfo($clean, PATHINFO_FILENAME) ?: 'file';
    $ext2 = strtolower(pathinfo($clean, PATHINFO_EXTENSION)) ?: $ext;
    $fname = $basePart . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext2;
    $destAbs = $dirAbs . '/' . $fname;
    if (!@move_uploaded_file($tmp, $destAbs)) return null;
    return '/' . trim($dirRel, '/') . '/' . $fname;
}


/** Bukti barang datang: tambahan, tidak mengubah foto kartu stok. */
function upload_incoming_evidence_files(string $field, string $incoming_code, string $kind): array {
  $saved=[]; if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return $saved;
  $names=$_FILES[$field]['name']??[]; $tmps=$_FILES[$field]['tmp_name']??[];
  $errs=$_FILES[$field]['error']??[]; $sizes=$_FILES[$field]['size']??[];
  if (!is_array($names)) { $names=[$names]; $tmps=[$tmps]; $errs=[$errs]; $sizes=[$sizes]; }
  $kind=($kind==='video')?'video':'photo';
  $allow=$kind==='video'?['mp4','mov','webm']:['jpg','jpeg','png','webp'];
  $max=$kind==='video'?80*1024*1024:10*1024*1024;
  $base=realpath(__DIR__.'/..'); if ($base===false) return $saved;
  $dirRel='uploads/wqs_incoming_evidence/'.$incoming_code.'/'.$kind; $dirAbs=$base.'/'.$dirRel;
  if (!is_dir($dirAbs)) @mkdir($dirAbs,0775,true);
  foreach($names as $i=>$origName){
    if (($errs[$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) continue;
    $tmp=$tmps[$i]??''; $size=(int)($sizes[$i]??0);
    if (!is_uploaded_file($tmp)||$size<=0||$size>$max) continue;
    $orig=basename((string)$origName); $ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));
    if(!in_array($ext,$allow,true)) continue;
    $safe=function_exists('safe_filename')?safe_filename($orig):preg_replace('/[^A-Za-z0-9._-]+/','_',$orig);
    $stem=pathinfo($safe,PATHINFO_FILENAME)?:$kind;
    try{$rand=bin2hex(random_bytes(4));}catch(Throwable $e){$rand=(string)mt_rand(100000,999999);}
    $fname=$stem.'_'.date('Ymd_His').'_'.$rand.'.'.$ext;
    if(@move_uploaded_file($tmp,$dirAbs.'/'.$fname)) $saved[]='/'.$dirRel.'/'.$fname;
  } return $saved;
}

function handle_uploads(string $incoming_code): array {
  $saved = [];
  if (!isset($_FILES['docs'])) return $saved;

  $baseDir = __DIR__ . '/../uploads/wqs_incoming/' . $incoming_code;
  if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);

  $names = $_FILES['docs']['name'] ?? [];
  $tmps  = $_FILES['docs']['tmp_name'] ?? [];
  $errs  = $_FILES['docs']['error'] ?? [];
  $sizes = $_FILES['docs']['size'] ?? [];

  $allowedExt = ['pdf','jpg','jpeg','png','doc','docx','xls','xlsx'];
  $max = 50 * 1024 * 1024;

  for ($i=0; $i<count($names); $i++) {
    if ($errs[$i] !== UPLOAD_ERR_OK) continue;
    if ($sizes[$i] > $max) continue;

    $orig = basename($names[$i]);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) continue;

    // gunakan helper global (scan audit juga mendeteksi ini)
    $safeBase = safe_filename($orig);
    $ext2 = strtolower(pathinfo($safeBase, PATHINFO_EXTENSION));
    $base2 = pathinfo($safeBase, PATHINFO_FILENAME);
    // random suffix untuk menghindari overwrite
    try {
      $rand = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
      $rand = (string)mt_rand(100000, 999999);
    }
    $safe = $base2 . '_' . date('YmdHis') . '_' . $rand . ($ext2 !== '' ? '.' . $ext2 : '');
    $target = $baseDir . '/' . $safe;
    if (move_uploaded_file($tmps[$i], $target)) $saved[] = $safe;
  }
  return $saved;
}

$flash = ['type'=>'', 'msg'=>''];

// Download template CSV (Phase 3)
if (isset($_GET['download']) && $_GET['download']==='template') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="wqs_incoming_import_template.csv"');
  echo "received_date,po_code,office_code,depo_name,ref_note,sku,qty,lot_number,serial_number,exp_date\n";
  $defOffice = wqs_stock_default_office();
  echo date('Y-m-d').",PO-CONTOH-001,{$defOffice},DEPO-UTAMA,Contoh Incoming,01070108,100,LOT-001,,2027-12-31\n";
  exit;
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
  if ($schemaErrors) {
    $flash = ['type' => 'danger', 'msg' => implode(' ', $schemaErrors)];
  }
  $action = $_POST['action'] ?? '';

  if (!$schemaErrors && $action === 'create_incoming') {
    $received_date = $_POST['received_date'] ?? date('Y-m-d');
    $po_code = up($_POST['po_code'] ?? '');
    $office_code = up($_POST['office_code'] ?? '');
    $userDept = function_exists('auth_dept') ? strtoupper(auth_dept()) : '';
    $userOffice = function_exists('auth_office_code') ? strtoupper(trim(auth_office_code())) : '';
    if ($userDept === 'BRANCH' && $userOffice !== '' && $office_code !== $userOffice) {
      $flash = ['type'=>'danger','msg'=>'Staff BRANCH hanya boleh input incoming untuk kantor ' . $userOffice . '.'];
      $office_code = '';
    }
    $depo_name = trim($_POST['depo_name'] ?? '');
    $ref_note = trim($_POST['ref_note'] ?? '');

    $items = $_POST['items'] ?? [];
    $clean = [];
    foreach ($items as $it) {
      $sku = up($it['sku'] ?? '');
      $qty = (float)($it['qty'] ?? 0);
      $lot = trim($it['lot_number'] ?? '');
      $ser = trim($it['serial_number'] ?? '');
      $exp = trim($it['exp_date'] ?? '');

      if ($sku === '' || $qty <= 0) continue;
      $clean[] = [
        'sku'=>$sku,
        'qty'=>$qty,
        'lot'=>$lot !== '' ? $lot : null,
        'serial'=>$ser !== '' ? $ser : null,
        'exp'=>$exp !== '' ? $exp : null,
      ];
    }

    if ($po_code === '' || $office_code === '' || $depo_name === '') {
      $flash=['type'=>'danger','msg'=>'PO Code, Office, dan Depo wajib diisi.'];
    } elseif (!$clean) {
      $flash=['type'=>'danger','msg'=>'Minimal 1 item dengan SKU & qty > 0'];
    } else {
      // HARD validation: Incoming harus match PO & tidak boleh melebihi outstanding qty.
      $v = prepare_incoming_items_vs_po($pdo, $po_code, $office_code, $clean);
      if (empty($v['ok'])) {
        $flash=['type'=>'danger','msg'=>nl2br(h($v['msg'] ?? 'Validasi gagal'))];
      } else {
        $eligibleItems = $v['eligible_items'] ?? [];
        $skipMessages = $v['skipped'] ?? [];
        // Wajib foto kartu stok sebelum & sesudah (Stock Snapshot opname, pola wqs_do_tasks)
        $stockBeforeUp = upload_stock_card_file('wqs_stock_before', 'uploads/wqs_incoming_stock');
        $stockAfterUp  = upload_stock_card_file('wqs_stock_after',  'uploads/wqs_incoming_stock');
        if (!$stockBeforeUp || !$stockAfterUp) {
          $flash=['type'=>'danger','msg'=>'Wajib upload foto kartu stok SEBELUM & SESUDAH sebelum simpan Incoming (untuk Stock Snapshot opname).'];
        } else {
        $incoming_code = next_incoming_code($pdo);
        $arrivalPhotos = upload_incoming_evidence_files('goods_arrival_photos', $incoming_code, 'photo');
        $arrivalVideos = upload_incoming_evidence_files('goods_arrival_videos', $incoming_code, 'video');

        $pdo->beginTransaction();
      try {
        $incCreatedBy = function_exists('p_username') ? p_username() : ($_SESSION['username'] ?? $_SESSION['user']['username'] ?? '');
        $ins = $pdo->prepare("INSERT INTO wqs_incoming (incoming_code, received_date, po_id, po_code, office_code, depo_name, ref_note, wqs_stock_before, wqs_stock_after, goods_arrival_photos, goods_arrival_videos, created_by)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([$incoming_code, $received_date, (int)($v['po']['po_id'] ?? 0), $po_code ?: null, $office_code ?: null, $depo_name ?: null, $ref_note ?: null, $stockBeforeUp, $stockAfterUp, json_encode($arrivalPhotos, JSON_UNESCAPED_SLASHES), json_encode($arrivalVideos, JSON_UNESCAPED_SLASHES), $incCreatedBy]);
        $incoming_id = (int)$pdo->lastInsertId();

        $poMap = $v['po']['items'] ?? [];
        // Pembelian antar kantor: stock supplier berkurang via Sales DO + Picking (BKS), bukan di Incoming.
        // Incoming hanya add ke kantor penerima (BGR). Lihat docs/PEMBELIAN_ANTAR_KANTOR.md.

        $insItem = $pdo->prepare("INSERT INTO wqs_incoming_items
          (incoming_id, po_item_id, product_id, sku, lot_number, serial_number, exp_date, qty)
          VALUES (?,?,?,?,?,?,?,?)");

        $ok=0; $notfound=0;
        // PATCH_3_AUDIT: capture stock before/after (per product)
        $stockBefore = [];
        $affectedPids = [];
        foreach ($eligibleItems as $it) {
          $pid = (int)($it['product_id'] ?? 0);
          if ($pid <= 0) {
            throw new Exception("Product ID kosong pada item incoming.");
          }
          $poItemId = (int)($it['po_item_id'] ?? 0);

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

          $insItem->execute([$incoming_id, $poItemId > 0 ? $poItemId : null, $pid, ($it['sku'] ?? ''), $it['lot'], $it['serial'], $it['exp'], $it['qty']]);
          add_stock($pdo, $pid, (float)$it['qty'], $office_code);
          $ok++;
        }
        $notfound = 0;

$files = handle_uploads($incoming_code);

        if ($pdo->inTransaction()) { $pdo->commit(); }

        // PATCH_3_AUDIT: after snapshot
        $incomingRow = null;
        $itemsRows = [];
        $stockAfter = [];
        try {
          $stInc = $pdo->prepare("SELECT * FROM wqs_incoming WHERE id=? LIMIT 1");
          $stInc->execute([$incoming_id]);
          $incomingRow = $stInc->fetch();

          $stItems = $pdo->prepare("SELECT * FROM wqs_incoming_items WHERE incoming_id=? ORDER BY id");
          $stItems->execute([$incoming_id]);
          $itemsRows = $stItems->fetchAll();

          foreach (array_keys($affectedPids) as $pid) {
            $qs = $pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
            $qs->execute([$pid]);
            $stockAfter[$pid] = (int)($qs->fetchColumn() ?? 0);
          }
        } catch (Throwable $e) {
          // ignore
        }

        rmi_audit_safe('POSTING', 'STOCK.WQS_INCOMING', $incoming_id, [
          'incoming_code' => $incoming_code,
          'received_date' => $received_date,
          'po_code' => $po_code,
          'office_code' => $office_code,
          'depo_name' => $depo_name,
          'ref_note' => $ref_note,
          'items' => $clean,
          'stock_before' => $stockBefore,
        ], [
          'incoming' => $incomingRow,
          'items' => $itemsRows,
          'files' => $files,
          'stock_after' => $stockAfter,
        ], [
          'event' => 'create_incoming',
          'items_ok' => $ok,
        ]);
        if (function_exists('master_audit')) {
          master_audit($pdo, 'wqs_incoming', 'wqs_incoming', 'CREATE', $incoming_id, $incoming_code, "Incoming created: {$incoming_code}", ['po_code' => $po_code, 'office_code' => $office_code, 'items_ok' => $ok]);
        }

        $msg = "Incoming tersimpan: {$incoming_code}. Items OK={$ok}";
        if (!empty($skipMessages)) $msg .= ". Skip=" . count($skipMessages) . " item (outstanding 0/kosong)";
        if ($notfound>0) $msg .= " (SKU not found={$notfound})";
        $flash=['type'=>'success','msg'=>$msg.' — <a class="link-light" href="wqs_incoming_view.php?code='.urlencode($incoming_code).'">Lihat detail</a>'];
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $flash=['type'=>'danger','msg'=>'Gagal simpan incoming: '.h($e->getMessage())];
      }
      }
    }
    }
  }

  if (!$schemaErrors && $action === 'import_csv') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
      $flash=['type'=>'danger','msg'=>'Upload CSV gagal'];
    } else {
      $tmp = $_FILES['csv_file']['tmp_name'];
      $fh = fopen($tmp, 'r');
      if (!$fh) {
        $flash=['type'=>'danger','msg'=>'CSV tidak bisa dibaca'];
      } else {
        $header = fgetcsv($fh);
        $map = [];
        if ($header) foreach ($header as $i=>$col) $map[strtolower(trim($col))] = $i;

        $need = ['received_date','po_code','office_code','depo_name','ref_note','sku','qty','lot_number','serial_number','exp_date'];
        $missing = array_diff($need, array_keys($map));
        if ($missing) {
          $flash=['type'=>'danger','msg'=>'Header CSV tidak sesuai template'];
        } else {
          // Create one incoming_code for this import
          $incoming_code = next_incoming_code($pdo);

          $pdo->beginTransaction();
          try {
            // Read first row for header fields
            $rows = [];
            while (($row = fgetcsv($fh)) !== false) { $rows[] = $row; }
            if (!$rows) throw new Exception('CSV kosong');

            $first = $rows[0];
            $received_date = $first[$map['received_date']] ?: date('Y-m-d');
            $po_code = up($first[$map['po_code']] ?? '');
            $office_code = up($first[$map['office_code']] ?? '');
            $depo_name = trim($first[$map['depo_name']] ?? '');
            $ref_note = trim($first[$map['ref_note']] ?? '');

            // Guard CSV: sama seperti form manual — BRANCH tidak bisa import untuk kantor lain
            $csvUserDept = function_exists('auth_dept') ? strtoupper(auth_dept()) : '';
            $csvUserOffice = function_exists('auth_office_code') ? strtoupper(trim(auth_office_code())) : '';
            if ($csvUserDept === 'BRANCH' && $csvUserOffice !== '' && $office_code !== $csvUserOffice) {
              throw new Exception("Akses ditolak: CSV berisi office_code '{$office_code}' bukan kantor Anda ({$csvUserOffice}).");
            }

            // HARD validation vs PO (SKU harus ada di PO dan qty tidak boleh melebihi outstanding)
            $cleanVal = [];
            foreach ($rows as $r) {
              $skuRaw = up($r[$map['sku']] ?? '');
              $sku = normalize_sku_wqs($skuRaw);
              $qty = (float)($r[$map['qty']] ?? 0);
              if ($sku === '' || $qty <= 0) continue;
              $cleanVal[] = ['sku'=>$sku,'qty'=>$qty];
            }
            $v = prepare_incoming_items_vs_po($pdo, $po_code, $office_code, $cleanVal);
            if (empty($v['ok'])) throw new Exception($v['msg'] ?? 'Validasi Incoming vs PO gagal');
            $eligibleItems = $v['eligible_items'] ?? [];
            $skipMessages = $v['skipped'] ?? [];


            $csvCreatedBy = function_exists('p_username') ? p_username() : ($_SESSION['username'] ?? $_SESSION['user']['username'] ?? '');
            $ins = $pdo->prepare("INSERT INTO wqs_incoming (incoming_code, received_date, po_id, po_code, office_code, depo_name, ref_note, created_by)
                              VALUES (?,?,?,?,?,?,?,?)");
            $ins->execute([$incoming_code, $received_date, (int)($v['po']['po_id'] ?? 0), $po_code ?: null, $office_code ?: null, $depo_name ?: null, $ref_note ?: null, $csvCreatedBy]);
            $incoming_id = (int)$pdo->lastInsertId();

            $poMap = $v['po']['items'] ?? [];
            // Pembelian antar kantor: stock supplier berkurang via Sales DO + Picking, bukan di Incoming.

            $insItem = $pdo->prepare("INSERT INTO wqs_incoming_items
              (incoming_id, po_item_id, product_id, sku, lot_number, serial_number, exp_date, qty)
              VALUES (?,?,?,?,?,?,?,?)");

            $ok=0; $notfound=0; $skip=0;
            // PATCH_3_AUDIT: capture stock before/after (per product)
            $stockBefore = [];
            $affectedPids = [];
            foreach ($eligibleItems as $it) {
              $pid = (int)($it['product_id'] ?? 0);
              if ($pid <= 0) {
                throw new Exception("Product ID kosong pada item incoming.");
              }
              $poItemId = (int)($it['po_item_id'] ?? 0);

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

              $insItem->execute([$incoming_id, $poItemId > 0 ? $poItemId : null, $pid, ($it['sku'] ?? ''), $it['lot'], $it['serial'], $it['exp'], $it['qty']]);
              add_stock($pdo, $pid, (float)$it['qty'], $office_code);
              $ok++;
            }
            $skip += count($skipMessages);
            $notfound = 0;

if ($pdo->inTransaction()) { $pdo->commit(); }

            // PATCH_3_AUDIT: after snapshot
            $incomingRow = null;
            $itemsRows = [];
            $stockAfter = [];
            try {
              $stInc = $pdo->prepare("SELECT * FROM wqs_incoming WHERE id=? LIMIT 1");
              $stInc->execute([$incoming_id]);
              $incomingRow = $stInc->fetch();

              $stItems = $pdo->prepare("SELECT * FROM wqs_incoming_items WHERE incoming_id=? ORDER BY id");
              $stItems->execute([$incoming_id]);
              $itemsRows = $stItems->fetchAll();

              foreach (array_keys($affectedPids) as $pid) {
                $qs = $pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
                $qs->execute([$pid]);
                $stockAfter[$pid] = (int)($qs->fetchColumn() ?? 0);
              }
            } catch (Throwable $e) {
              // ignore
            }

            rmi_audit_safe('POSTING', 'STOCK.WQS_INCOMING', $incoming_id, [
              'event' => 'import_csv',
              'file' => (string)($_FILES['csv_file']['name'] ?? ''),
              'incoming_code' => $incoming_code,
              'received_date' => $received_date,
              'po_code' => $po_code,
              'office_code' => $office_code,
              'depo_name' => $depo_name,
              'ref_note' => $ref_note,
              'rows_total' => count($rows),
              'stock_before' => $stockBefore,
            ], [
              'incoming' => $incomingRow,
              'items' => $itemsRows,
              'stock_after' => $stockAfter,
            ], [
              'items_ok' => $ok,
              'skip' => $skip,
            ]);
            if (function_exists('master_audit')) {
              master_audit($pdo, 'wqs_incoming', 'wqs_incoming', 'IMPORT_CSV', $incoming_id, $incoming_code, "Incoming import CSV: {$incoming_code}", ['po_code' => $po_code, 'items_ok' => $ok, 'skip' => $skip]);
            }
            $flash=['type'=>'success','msg'=>"Import OK: {$incoming_code}. Items OK={$ok}, NotFound={$notfound}, Skip={$skip} — <a class=\"link-light\" href=\"wqs_incoming_view.php?code=".urlencode($incoming_code)."\">Lihat detail</a>"];
          } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $flash=['type'=>'danger','msg'=>'Import gagal: '.nl2br(h($e->getMessage()))];
          }
        }
        fclose($fh);
      }
    }
  }
}

// Load products for dropdown (limit) - SKU dari master_products, konsisten UPPER
$products = [];
try {
  $skuCol = 'sku';
  try { $pdo->query("SELECT sku FROM master_products LIMIT 1"); } catch (Throwable $e) { $skuCol = 'products_code'; }
  $products = $pdo->query("SELECT {$skuCol} AS sku, products_name FROM master_products WHERE status='active' ORDER BY UPPER({$skuCol}), products_name LIMIT 5000")->fetchAll();
} catch (Throwable $e) {}

// Offices (BRANCH user: hanya office sendiri)
$offices=[];
try {
  $offices=$pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active=1 ORDER BY office_name")->fetchAll();
  $userDept = function_exists('auth_dept') ? strtoupper(auth_dept()) : '';
  $userOffice = function_exists('auth_office_code') ? strtoupper(trim(auth_office_code())) : '';
  if ($userDept === 'BRANCH' && $userOffice !== '') {
    $offices = array_filter($offices, fn($o) => strtoupper(trim($o['office_code'] ?? '')) === $userOffice);
  }
} catch(Throwable $e){}


// Purchases PO list (for autofill)
$po_list=[];
try {
  $po_list = $pdo->query("SELECT id, po_code, po_date, office_code, status FROM purchases_po WHERE status IN ('OPEN','IN_PRODUCTION','READY') AND deleted_at IS NULL ORDER BY id DESC LIMIT 2000")->fetchAll();
} catch (Throwable $e) {}
// Incoming list (dengan nama office; BRANCH hanya lihat office sendiri)
$list = [];
$listUserDept = function_exists('auth_dept') ? strtoupper(auth_dept()) : '';
$listUserOffice = function_exists('auth_office_code') ? strtoupper(trim(auth_office_code())) : '';
$filterOffice = trim($_GET['filter_office'] ?? '');

// Filter tanggal khusus Phase 2 — List Incoming.
// Memakai received_date (tanggal barang diterima), bukan created_at.
// Filter ini hanya mempengaruhi rekap/list; proses create/import/stock tidak diubah.
$filterDateFrom = trim((string)($_GET['date_from'] ?? ''));
$filterDateTo   = trim((string)($_GET['date_to'] ?? ''));

$validDate = static function (string $v): bool {
  if ($v === '') return true;
  $d = DateTime::createFromFormat('Y-m-d', $v);
  return $d && $d->format('Y-m-d') === $v;
};
if (!$validDate($filterDateFrom)) $filterDateFrom = '';
if (!$validDate($filterDateTo)) $filterDateTo = '';
if ($filterDateFrom !== '' && $filterDateTo !== '' && $filterDateFrom > $filterDateTo) {
  [$filterDateFrom, $filterDateTo] = [$filterDateTo, $filterDateFrom];
}
try {
  // Rekap harus tetap tampil pada instalasi lama yang belum memiliki kolom deleted_at.
  // Bukti kartu stok + barang datang ikut dibaca untuk audit.
  // Gunakan helper lokal yang memang didefinisikan di file ini.
  // Sebelumnya memakai column_exists(); bila helper global itu tidak tersedia/berbeda
  // signature, exception tertelan oleh catch dan $list menjadi [] (rekap 0 entries).
  $hasIncomingDeletedAt = has_column($pdo, 'wqs_incoming', 'deleted_at');
  // Rekap dibuat independen dari collation master_office.
  // Jangan JOIN office_code lintas tabel di sini: pada DB lama collation kedua kolom berbeda
  // (utf8mb4_unicode_ci vs utf8mb4_general_ci) dan membuat seluruh rekap gagal dimuat.
  // Nama office dipetakan di PHP dari data master_office yang sudah dibaca di atas.
  $sqlList = "SELECT wi.id, wi.incoming_code, wi.po_code, wi.received_date, wi.office_code, wi.depo_name, wi.ref_note,
              wi.wqs_stock_before, wi.wqs_stock_after, wi.goods_arrival_photos, wi.goods_arrival_videos,
              wi.created_at, wi.created_by
              FROM wqs_incoming wi
              WHERE 1=1";
  if ($hasIncomingDeletedAt) {
    $sqlList .= " AND wi.deleted_at IS NULL";
  }
  $paramsList = [];
  if ($listUserDept === 'BRANCH' && $listUserOffice !== '') {
    $sqlList .= " AND UPPER(TRIM(wi.office_code)) = UPPER(TRIM(?))";
    $paramsList[] = $listUserOffice;
  }
  if ($filterOffice !== '' && $listUserDept !== 'BRANCH') {
    $sqlList .= " AND UPPER(TRIM(wi.office_code)) = UPPER(TRIM(?))";
    $paramsList[] = strtoupper($filterOffice);
  }

  // Rekap tanggal berdasarkan tanggal barang diterima.
  if ($filterDateFrom !== '') {
    $sqlList .= " AND DATE(wi.received_date) >= ?";
    $paramsList[] = $filterDateFrom;
  }
  if ($filterDateTo !== '') {
    $sqlList .= " AND DATE(wi.received_date) <= ?";
    $paramsList[] = $filterDateTo;
  }

  $sqlList .= " ORDER BY wi.id DESC LIMIT 2000";
  $stList = $pdo->prepare($sqlList);
  $stList->execute($paramsList);
  $list = $stList->fetchAll();

  // Enrich nama office tanpa SQL JOIN sehingga tidak terpengaruh perbedaan collation DB lama.
  $officeNameMap = [];
  foreach ($offices as $o) {
    $oc = up($o['office_code'] ?? '');
    if ($oc !== '') $officeNameMap[$oc] = (string)($o['office_name'] ?? $oc);
  }
  foreach ($list as &$lr) {
    $oc = up($lr['office_code'] ?? '');
    $lr['office_name'] = $officeNameMap[$oc] ?? ($lr['office_code'] ?? '');
  }
  unset($lr);
} catch (Throwable $e) {
  // Rekap tidak boleh gagal diam-diam. Tampilkan error agar masalah DB/schema langsung terlihat.
  $flash = ['type'=>'danger', 'msg'=>'Gagal memuat Rekap WQS Incoming: '.h($e->getMessage())];
  $list = [];
}

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE module = 'wqs_incoming' ORDER BY created_at DESC LIMIT 50");
  $st->execute();
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('WQS Incoming', [
  'active' => 'stock',
  'subtitle' => 'Incoming barang datang (WQS) dengan validasi PO outstanding',
  'breadcrumbs' => [
    ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
    'WQS Incoming',
  ],
  'actions' => [
    ['label' => 'Stock Summary', 'url' => $baseProject . '/stock/wqs_stock.php'],
    ['label' => 'Allocation', 'url' => $baseProject . '/stock/wqs_allocation.php'],
    ['label' => 'WQS DO Tasks', 'url' => $baseProject . '/stock/wqs_do_tasks.php'],
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted mb-1">WQS</div>
    <h3 class="mb-0">Incoming (Barang Datang)</h3>
    <div class="muted">Di sini EXP/LOT/Serial dicatat saat barang datang. Stok total otomatis update ke <code>wqs_stock</code>.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light btn-sm" href="<?= rmi_ui_h($baseProject) ?>/stock/wqs_stock.php">Stock Summary</a>
    <a class="btn btn-outline-light btn-sm" href="<?= rmi_ui_h($baseProject) ?>/stock/wqs_allocation.php">Allocation</a>
    <a class="btn btn-outline-light btn-sm" href="<?= rmi_ui_h($baseProject) ?>/stock/wqs_do_tasks.php">WQS DO Tasks</a>
    <a class="btn btn-outline-light btn-sm" href="<?= rmi_ui_h($baseProject) ?>/master/master_data.php">Master Data</a>
  </div>
</div>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= h($flash['type']) ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>
<?php if ($schemaErrors): ?>
<div class="alert alert-danger">
  <?= h(implode(' ', $schemaErrors)) ?>
  <div class="mt-2">
    <strong>Solusi:</strong>
    <ul class="mb-0 mt-1">
      <li><a href="<?= h(($baseProject ?? '') . '/tools/run_wqs_incoming_migration_102.php') ?>" class="alert-link">→ Jalankan Migration 102 (Web)</a> — Login Admin, klik tombol "Jalankan Migrasi"</li>
      <li><strong>CLI:</strong> <code>php tools/run_wqs_incoming_migration_102_cli.php</code> — Dari root project.</li>
    </ul>
  </div>
</div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-7">
        <div class="fw-semibold mb-1">Phase 1 — Buat Incoming</div>
        <div class="muted">Isi item-item yang benar-benar datang. Lot/Serial/Exp opsional (kalau ada).</div>
      </div>
      <div class="col-md-5">
        <div class="fw-semibold mb-1">Phase 3 — Import CSV</div>
        <div class="muted">Sekali import = 1 Incoming Code. Download template dulu.</div>
        <div class="d-flex gap-2 mb-2">
          <a class="btn btn-outline-light btn-sm" href="?download=template">Template CSV</a>
        </div>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="import_csv">
          <input class="form-control form-control-sm" type="file" name="csv_file" accept=".csv" required>
          <button class="btn btn-primary btn-sm">Import</button>
        </form>
      </div>
    </div>

    <hr class="border-light opacity-25">

    <form method="post" enctype="multipart/form-data" id="incomingForm">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_incoming">

      <div class="row g-2">
        <div class="col-md-3">
          <label class="form-label small">Received Date</label>
          <input class="form-control form-control-sm" type="date" name="received_date" value="<?=h(date('Y-m-d'))?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small">PO Code <span class="text-danger">*</span></label>
          <select class="form-select form-select-sm" name="po_code" id="po_code" required>
            <option value="">-- pilih PO --</option>
            <?php foreach($po_list as $po): ?>
              <option value="<?=h(up($po['po_code']))?>" data-office="<?=h(up($po['office_code'] ?? ''))?>">
                <?=h(up($po['po_code']))?> — <?=h($po['po_date'] ?? '')?> — <?=h(up($po['office_code'] ?? ''))?> — <?=h($po['status'] ?? '')?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text small text-muted">Pilih PO untuk auto-pull SKU & Qty. Kalau PO belum ada, buat dulu di Purchases.</div>
        </div>
        <div class="col-md-3">
          <label class="form-label small">Office <span class="text-danger">*</span></label>
          <select class="form-select form-select-sm" name="office_code" required>
            <option value="">-- pilih --</option>
            <?php foreach($offices as $o): $oc=up($o['office_code']); ?>
              <option value="<?=h($oc)?>"><?=h($oc)?> - <?=h(strtoupper($o['office_name'] ?? ''))?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small">Depo / Lokasi <span class="text-danger">*</span></label>
          <input class="form-control form-control-sm" name="depo_name" placeholder="contoh: DEPO-UTAMA" required>
        </div>
        <div class="col-md-12">
          <label class="form-label small mt-2">Ref Note</label>
          <input class="form-control form-control-sm" name="ref_note" placeholder="contoh: barang datang dari forwarding">
        </div>
      </div>

      <div class="mt-3">
        <div class="fw-semibold mb-2">Items</div>
        <div id="poOutstandingAlert" class="alert alert-warning py-2 small" style="display:none"></div>

        <div class="table-responsive">
          <table class="table table-dark table-sm align-middle" id="itemsTable">
            <thead>
              <tr>
                <th style="width:28%">SKU</th>
                <th style="width:10%">Qty</th>
                <th style="width:16%">LOT</th>
                <th style="width:16%">Serial</th>
                <th style="width:14%">EXP</th>
                <th style="width:10%">Aksi</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <button id="btnAddItem" type="button" class="btn btn-outline-light btn-sm" onclick="addRow()">+ Tambah Item</button>

        <div class="row g-2 mt-3">
          <div class="col-md-6">
            <label class="form-label small">Foto kartu stok <b>SEBELUM</b> <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" type="file" name="wqs_stock_before" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
          <div class="col-md-6">
            <label class="form-label small">Foto kartu stok <b>SESUDAH</b> <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" type="file" name="wqs_stock_after" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
          <div class="col-12">
            <div class="muted small">Wajib upload foto kartu stok sebelum & sesudah (Stock Snapshot opname, pola sama WQS DO Tasks).</div>
          </div>
        </div>
        <div class="row g-2 mt-2">
          <div class="col-12"><div class="fw-semibold">Bukti Barang Datang <span class="muted">(tambahan, tidak menggantikan kartu stok)</span></div></div>
          <div class="col-md-6">
            <label class="form-label small">Foto bukti barang datang (bisa pilih banyak)</label>
            <input class="form-control form-control-sm" type="file" name="goods_arrival_photos[]" multiple accept=".jpg,.jpeg,.png,.webp">
            <div class="muted mt-1">Maks. 10 MB/file.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label small">Video bukti barang datang (bisa pilih banyak)</label>
            <input class="form-control form-control-sm" type="file" name="goods_arrival_videos[]" multiple accept=".mp4,.mov,.webm">
            <div class="muted mt-1">Maks. 80 MB/file • MP4/MOV/WEBM.</div>
          </div>
        </div>
        <div class="row g-2 mt-2">
          <div class="col-md-6">
            <label class="form-label small">Upload Dokumen (opsional)</label>
            <input class="form-control form-control-sm" type="file" name="docs[]" multiple
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
            <div class="muted mt-1">Disimpan di <code>/uploads/wqs_incoming/INC-xxx/</code> (max 50MB/file).</div>
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button id="btnSaveIncoming" class="btn btn-primary btn-sm w-100">Simpan Incoming</button>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <div>
        <div class="fw-semibold">Phase 2 — List Incoming</div>
        <div class="muted small">Filter tanggal memakai <b>Received Date</b> (tanggal barang diterima).</div>
      </div>

      <form method="get" class="d-flex gap-2 align-items-end flex-wrap">
        <div>
          <label class="form-label mb-1 small">Tanggal dari</label>
          <input type="date" class="form-control form-control-sm" name="date_from"
                 value="<?=h($filterDateFrom)?>">
        </div>
        <div>
          <label class="form-label mb-1 small">Tanggal sampai</label>
          <input type="date" class="form-control form-control-sm" name="date_to"
                 value="<?=h($filterDateTo)?>">
        </div>

        <?php if ($listUserDept !== 'BRANCH' && !empty($offices)): ?>
        <div>
          <label class="form-label mb-1 small">Filter Branch</label>
          <select class="form-select form-select-sm" name="filter_office" style="min-width:260px">
            <option value="">— Semua —</option>
            <?php foreach ($offices as $o): $oc = up($o['office_code'] ?? ''); ?>
              <option value="<?=h($oc)?>" <?= (up($filterOffice) === $oc) ? 'selected' : '' ?>><?=h($oc)?> — <?=h($o['office_name'] ?? '')?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php elseif ($filterOffice !== ''): ?>
          <input type="hidden" name="filter_office" value="<?=h($filterOffice)?>">
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-sm">Tampilkan</button>
        <a class="btn btn-outline-light btn-sm" href="wqs_incoming.php">Reset</a>
      </form>
    </div>

    <?php if ($filterDateFrom !== '' || $filterDateTo !== '' || $filterOffice !== ''): ?>
      <div class="muted small mb-2">
        Rekap ditemukan: <b><?=count($list)?></b> incoming
        <?php if ($filterDateFrom !== '' || $filterDateTo !== ''): ?>
          • Periode:
          <b><?=h($filterDateFrom !== '' ? $filterDateFrom : 'awal')?></b>
          s/d
          <b><?=h($filterDateTo !== '' ? $filterDateTo : 'akhir')?></b>
        <?php endif; ?>
        <?php if ($filterOffice !== ''): ?> • Branch: <b><?=h(up($filterOffice))?></b><?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="table-wrap">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <th class="text-nowrap">Incoming Code</th>
            <th class="text-nowrap">PO Code</th>
            <th class="text-nowrap">Received</th>
            <th class="text-nowrap">Office / Branch</th>
            <th class="text-nowrap">Depo</th>
            <th>Ref Note</th>
            <th class="text-nowrap">Kartu Stok</th>
            <th class="text-nowrap">Bukti Barang Datang</th>
            <th class="text-nowrap">Created By</th>
            <th class="text-nowrap">Created</th>
            <th class="text-nowrap">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($list as $r): ?>
          <tr>
            <td class="text-nowrap"><b><?=h(up($r['incoming_code'] ?? ''))?></b></td>
            <td class="text-nowrap"><?=h(up($r['po_code'] ?? ''))?></td>
            <td class="text-nowrap"><?=h($r['received_date'])?></td>
            <td class="text-nowrap"><span title="<?=h($r['office_name'] ?? '')?>"><?=h(up($r['office_code'] ?? ''))?></span><?php if (!empty($r['office_name']) && $r['office_name'] !== ($r['office_code'] ?? '')): ?> <small class="text-muted">— <?=h($r['office_name'])?></small><?php endif; ?></td>
            <td class="text-nowrap"><?=h($r['depo_name'])?></td>
            <td><?=h($r['ref_note'])?></td>
            <td class="text-nowrap">
              <?php if (!empty($r['wqs_stock_before'])): ?><a href="<?=h($r['wqs_stock_before'])?>" target="_blank">Before</a><?php else: ?>-<?php endif; ?>
              /
              <?php if (!empty($r['wqs_stock_after'])): ?><a href="<?=h($r['wqs_stock_after'])?>" target="_blank">After</a><?php else: ?>-<?php endif; ?>
            </td>
            <td class="text-nowrap">
              <?php
                $arrPhotos=json_decode((string)($r['goods_arrival_photos'] ?? '[]'),true); if(!is_array($arrPhotos))$arrPhotos=[];
                $arrVideos=json_decode((string)($r['goods_arrival_videos'] ?? '[]'),true); if(!is_array($arrVideos))$arrVideos=[];
              ?>
              <a href="wqs_incoming_view.php?code=<?=urlencode($r['incoming_code'])?>"><?=count($arrPhotos)?> foto / <?=count($arrVideos)?> video</a>
            </td>
            <td class="text-nowrap"><?=h($r['created_by'] ?? '')?></td>
            <td class="text-nowrap"><span class="muted"><?=h($r['created_at'])?></span></td>
            <td class="text-nowrap"><a class="btn btn-outline-light btn-sm" href="wqs_incoming_view.php?code=<?=urlencode($r['incoming_code'])?>">View</a> <a class="btn btn-outline-light btn-sm" href="<?= rmi_ui_h($baseProject) ?>/stock/wqs_allocation.php?code=<?=urlencode($r['incoming_code'])?>">Allocate</a></td>
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
const products = <?= json_encode(array_map(fn($p)=>['sku'=>strtoupper(trim($p['sku']??'')), 'name'=>strtoupper(trim($p['products_name']??''))], $products), JSON_UNESCAPED_UNICODE) ?>;


let poSkuOptions = null; // null = belum load PO; Array = SKU outstanding dari PO
let poSkuMap = {};
function normalizeSkuClient(v){ return String(v||'').toUpperCase().replace(/\s+/g,'').trim(); }

function setPoSkuOptions(items){
  if (items === null){
    poSkuOptions = null;
    poSkuMap = {};
    return;
  }
  poSkuOptions = Array.isArray(items) ? items.map(it => ({
    sku: String(it.sku||'').toUpperCase().trim(),
    name: String(it.name || it.product_name || it.product || it.sku || '').toUpperCase().trim(),
    max: (it.outstanding_qty ?? it.qty ?? it.po_qty ?? 0),
  })) : [];
  poSkuMap = {};
  for (const it of poSkuOptions){
    if (it.sku) poSkuMap[normalizeSkuClient(it.sku)] = it;
  }
}

function setOutstandingAlert(msg){
  const box = document.getElementById('poOutstandingAlert');
  if (!box) return;
  if (!msg){
    box.style.display = 'none';
    box.textContent = '';
  } else {
    box.style.display = '';
    box.textContent = msg;
  }
}

function setFormEnabled(enabled){
  const btnAdd = document.getElementById('btnAddItem');
  if (btnAdd) btnAdd.disabled = !enabled;
  const btnSave = document.getElementById('btnSaveIncoming');
  if (btnSave) btnSave.disabled = !enabled;
}
function makeSkuSelect(name){
  const sel = document.createElement('select');
  sel.className = 'form-select form-select-sm';
  sel.name = name;
  const opt0 = document.createElement('option');
  opt0.value = '';
  opt0.textContent = '-- pilih SKU --';
  sel.appendChild(opt0);

  const source = Array.isArray(poSkuOptions) ? poSkuOptions : products;

  for (const p of source){
    const o = document.createElement('option');
    const sku = String(p.sku||'').toUpperCase().trim();
    const name = String(p.name||'').toUpperCase().trim();
    o.value = sku;
    o.textContent = `${sku} — ${name}`;
    sel.appendChild(o);
  }

  // Jika PO sudah dipilih tapi outstanding habis, disable selector
  if (Array.isArray(poSkuOptions) && poSkuOptions.length === 0){
    sel.disabled = true;
    opt0.textContent = '-- tidak ada outstanding SKU --';
  }
  return sel;
}

function addRow(skuVal='', qtyVal='', maxVal=null){
  const tbody = document.querySelector('#itemsTable tbody');
  const idx = tbody.children.length;

  const tr = document.createElement('tr');

  const tdSku = document.createElement('td');
  const sel = makeSkuSelect(`items[${idx}][sku]`);
  if (skuVal) sel.value = skuVal;
  tdSku.appendChild(sel);

  const tdQty = document.createElement('td');
  tdQty.innerHTML = `<input class="form-control form-control-sm" type="number" min="1" step="1" name="items[${idx}][qty]" required>`;
  const qinp = tdQty.querySelector('input');
  if (qinp) qinp.value = (qtyVal!=='' && qtyVal!==null && qtyVal!==undefined) ? qtyVal : 1;
  if (qinp && maxVal!==null && maxVal!==undefined && maxVal!=='') {
    try { qinp.max = String(parseInt(maxVal,10)); } catch(e) {}
    qinp.title = 'Max outstanding: ' + maxVal;
  }

  // Update max ketika SKU berubah (berdasarkan outstanding PO)
  sel.addEventListener('change', () => {
    const s = normalizeSkuClient(sel.value);
    if (!qinp) return;
    if (poSkuMap && poSkuMap[s] && poSkuMap[s].max !== undefined){
      const m = parseInt(poSkuMap[s].max,10);
      if (!isNaN(m) && m>0){
        qinp.max = String(m);
        qinp.title = 'Max outstanding: ' + m;
      } else {
        qinp.removeAttribute('max');
        qinp.title = '';
      }
    } else {
      qinp.removeAttribute('max');
      qinp.title = '';
    }
  });

  const tdLot = document.createElement('td');
  tdLot.innerHTML = `<input class="form-control form-control-sm" name="items[${idx}][lot_number]" placeholder="optional">`;

  const tdSer = document.createElement('td');
  tdSer.innerHTML = `<input class="form-control form-control-sm" name="items[${idx}][serial_number]" placeholder="optional">`;

  const tdExp = document.createElement('td');
  tdExp.innerHTML = `<input class="form-control form-control-sm" type="date" name="items[${idx}][exp_date]">`;

  const tdAct = document.createElement('td');
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-outline-danger btn-sm';
  btn.textContent = 'Remove';
  btn.onclick = () => tr.remove();
  tdAct.appendChild(btn);

  tr.appendChild(tdSku);
  tr.appendChild(tdQty);
  tr.appendChild(tdLot);
  tr.appendChild(tdSer);
  tr.appendChild(tdExp);
  tr.appendChild(tdAct);

  tbody.appendChild(tr);
}


async function loadPoAutofill(){
  const poSel = document.getElementById('po_code');
  if (!poSel) return;
  const po = poSel.value || '';
  if (!po){
    setPoSkuOptions(null);
    setOutstandingAlert('');
    setFormEnabled(true);
    // reset items table
    const tbody = document.querySelector('#itemsTable tbody');
    if (tbody){ tbody.innerHTML=''; }
    addRow();
    return;
  }

  try{
    const res = await fetch('wqs_incoming_po_api.php?po_code=' + encodeURIComponent(po));
    const j = await res.json();
    if (!j || !j.ok){
      alert((j && j.msg) ? j.msg : 'Gagal load PO');
      return;
    }

    // Autofill office (only if empty)
    const officeSel = document.querySelector('select[name="office_code"]');
    if (officeSel && j.office_code && (officeSel.value === '' || officeSel.value === null)){
      officeSel.value = j.office_code;
    }

    // Autofill ref note (only if empty)
    const ref = document.querySelector('input[name="ref_note"]');
    if (ref && j.note && ref.value.trim() === ''){
      ref.value = 'PO: ' + po + (j.vendor_name ? ' | Vendor: ' + j.vendor_name : '') + (j.note ? (' | ' + j.note) : '');
    }

    // Set SKU options berdasarkan outstanding item PO
    setPoSkuOptions(Array.isArray(j.items) ? j.items : []);
    const hasOutstanding = Array.isArray(j.items) && j.items.length > 0;
    if (!hasOutstanding){
      setOutstandingAlert('PO ini sudah fully received (outstanding 0). Tidak ada SKU yang bisa diterima lagi.');
      setFormEnabled(false);
      const tbody = document.querySelector('#itemsTable tbody');
      if (tbody){ tbody.innerHTML = ''; }
      addRow();
      return;
    } else {
      setOutstandingAlert('');
      setFormEnabled(true);
    }
    // Replace items table
    if (Array.isArray(j.items) && j.items.length){
      const tbody = document.querySelector('#itemsTable tbody');
      if (tbody){
        tbody.innerHTML = '';
        for (const it of j.items){
          addRow((it.sku||''), (it.qty||1), (it.outstanding_qty||it.qty||null));
        }
      }
    }
  }catch(e){
    console.error(e);
    alert('Gagal load PO (network/server).');
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const poSel = document.getElementById('po_code');
  if (poSel){
    poSel.addEventListener('change', loadPoAutofill);
  }
});

// default 1 row
addRow();
</script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script>
  new DataTable('#tbl', { pageLength: 25, dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'], order: [[2,'desc'],[0,'desc']] });
</script>
<?php rmi_footer(); ?>
