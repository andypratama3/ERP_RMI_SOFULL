<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    $allowed = function_exists('can_any') && can_any(['PURCHASES.PO_VIEW', 'PURCHASES.PO_CREATE', 'PURCHASES.PO_EDIT', 'PURCHASES.VIEW']);
    if (!$allowed && function_exists('require_role')) {
        require_role(['ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
    } elseif (!$allowed) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
} else {
    require_role(['ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
}

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_purchases_lib.php';

// ── Office Scope: BRANCH hanya lihat/buat PO kantornya sendiri ───────────────
$_ppo_user   = function_exists('auth_user') ? auth_user() : [];
$_ppo_dept   = strtoupper(trim((string)($_ppo_user['department'] ?: $_ppo_user['level'] ?: '')));
$_ppo_role   = strtoupper(trim((string)($_ppo_user['role'] ?? '')));
$_ppo_office = strtoupper(trim((string)($_ppo_user['office_code'] ?? '')));
$_ppo_is_branch = in_array($_ppo_dept, ['BRANCH'], true)
               && !in_array($_ppo_role, ['SYS','ADMIN','SUPERADMIN'], true)
               && $_ppo_office !== '';
$_ppo_scope = $_ppo_is_branch ? $_ppo_office : null;
$pdo = p_pdo();
p_ensure_schema($pdo);
p_ensure_col($pdo, 'purchases_po', 'business_group', "`business_group` VARCHAR(20) NULL AFTER `category`");
p_ensure_col($pdo, 'purchases_po_items', 'business_group', "`business_group` VARCHAR(20) NULL AFTER `products_name`");
p_ensure_col($pdo, 'purchases_po_items', 'category', "`category` VARCHAR(20) NULL AFTER `business_group`");
p_ensure_col($pdo, 'wqs_pr', 'submitted_at', "`submitted_at` DATETIME NULL AFTER `status`");
function po_expected_business_group(string $category): string {
  $category = strtoupper(trim($category));
  if ($category === 'BMHP') return 'BMHP';
  if (in_array($category, ['UNIT_ACC','ALKES','AKSESORIS'], true)) return 'UNIT_ACC';
  return '';
}
function po_document_group(array $row): string {
  $bg = strtoupper(trim((string)($row['business_group'] ?? '')));
  if (in_array($bg, ['BMHP','UNIT_ACC'], true)) return $bg;
  $derived = po_expected_business_group((string)($row['category'] ?? ''));
  return $derived !== '' ? $derived : '';
}


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

$preselect_pr_id = (int)($_GET['pr_id'] ?? 0);
try {
  // PR available for PO (SUBMITTED only)
  $pr_list = $pdo->query("SELECT id, pr_code, pr_date, office_code, category, business_group FROM wqs_pr WHERE deleted_at IS NULL AND status='SUBMITTED' ORDER BY id DESC LIMIT 500")->fetchAll();
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


// Return SUBMITTED PR to WQS for correction (before PO is created)
if ($action === 'return_pr_revision') {
  if (!p_can_create_po()) {
    p_flash_set('danger','Hanya PQP/Admin yang boleh mengembalikan PR ke WQS.');
    rmi_redirect('purchases_po.php');
  }
  $pr_id = (int)($_POST['pr_id'] ?? 0);
  $reason = trim((string)($_POST['revision_reason'] ?? ''));
  if ($pr_id <= 0 || $reason === '') {
    p_flash_set('danger','Pilih PR dan isi alasan revisi.');
    rmi_redirect('purchases_po.php');
  }
  try {
    $st=$pdo->prepare("SELECT * FROM wqs_pr WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$pr_id]); $pr=$st->fetch(PDO::FETCH_ASSOC);
    if (!$pr) throw new RuntimeException('PR tidak ditemukan.');
    if (strtoupper((string)($pr['status'] ?? '')) !== 'SUBMITTED') throw new RuntimeException('Hanya PR SUBMITTED yang dapat dikembalikan ke WQS.');
    $stPo=$pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE pr_id=? AND deleted_at IS NULL AND status NOT IN ('CANCELLED')");
    $stPo->execute([$pr_id]);
    if ((int)$stPo->fetchColumn() > 0) throw new RuntimeException('PR sudah mempunyai PO aktif. Gunakan pembatalan/amend PO, bukan return PR langsung.');
    $pdo->prepare("UPDATE wqs_pr SET status='REVISION_WQS', note=CONCAT(COALESCE(note,''), CASE WHEN COALESCE(note,'')='' THEN '' ELSE '\n' END, '[REVISI PQP] ', ?) WHERE id=?")->execute([$reason,$pr_id]);
    p_audit($pdo,'PR',(string)$pr['pr_code'],'RETURN_TO_WQS',['id'=>$pr_id,'reason'=>$reason]);
    if (function_exists('master_audit')) master_audit($pdo,'wqs_pr','wqs_pr','RETURN_TO_WQS',$pr_id,(string)$pr['pr_code'],'PR returned to WQS for revision',['reason'=>$reason]);
    p_flash_set('success','PR '.h((string)$pr['pr_code']).' dikembalikan ke WQS untuk revisi.');
  } catch (Throwable $e) {
    p_flash_set('danger','Gagal mengembalikan PR: '.h($e->getMessage()));
  }
  rmi_redirect('purchases_po.php');
}


// ── One-time controlled cleanup: cancel PO trial yang sudah ditetapkan ────────
// Tidak hard-delete. Histori PO tetap ada, status menjadi CANCELLED.
// Hanya exact PO code di whitelist ini yang dapat diproses dari tombol cleanup.
if ($action === 'cancel_trial_po') {
  if (!p_is_admin_plus()) {
    p_flash_set('danger','Hanya Admin/SYS yang boleh menjalankan cleanup PO trial.');
    rmi_redirect('purchases_po.php');
  }

  $trialPoCodes = [
    'RMI-PO-BGR-260101-001',
    'BMHP-PO-BGR-260328-001',
    'BMHP-PO-BGR-260407-001',
    'BMHP-PO-BGR-260408-001',
    'BMHP-PO-BGR-260413-001',
    'BMHP-PO-BGR-260414-001',
    'BMHP-PO-BGR-260416-001',
    'BMHP-PO-BGR-260416-002',
    'BMHP-PO-BGR-260416-003',
    'BMHP-PO-BGR-260420-001',
    'BMHP-PO-BGR-260421-001',
    'BMHP-PO-TGR-260421-001',
    'BMHP-PO-BKS-260422-001',
    'BMHP-PO-BGR-260427-001',
    'BMHP-PO-BGR-260428-001',
    'BMHP-PO-BGR-260430-001',
    'BMHP-PO-BKS-260504-001',
    'BMHP-PO-BGR-260505-001',
    'BMHP-PO-BDG-260507-001',
    'BMHP-PO-BGR-260507-001',
    'BMHP-PO-SLO-260508-001',
    'BMHP-PO-TGR-260512-001',
    'BMHP-PO-BGR-260512-001',
  ];

  $cancelled = [];
  $already = [];
  $blocked = [];
  $missing = [];

  try {
    $pdo->beginTransaction();

    foreach ($trialPoCodes as $trialCode) {
      $st = $pdo->prepare("SELECT * FROM purchases_po WHERE UPPER(TRIM(po_code))=? LIMIT 1 FOR UPDATE");
      $st->execute([$trialCode]);
      $poTrial = $st->fetch(PDO::FETCH_ASSOC);

      if (!$poTrial) {
        $missing[] = $trialCode;
        continue;
      }

      $poId = (int)$poTrial['id'];
      $poStatus = strtoupper(trim((string)($poTrial['status'] ?? '')));

      if ($poStatus === 'CANCELLED') {
        if (empty($poTrial['deleted_at'])) {
          $pdo->prepare("UPDATE purchases_po SET deleted_at=NOW() WHERE id=?")->execute([$poId]);
        }
        $already[] = $trialCode;
        continue;
      }

      // Jangan membatalkan trial yang ternyata sudah punya transaksi downstream riil.
      $deps = [];
      try {
        $x=$pdo->prepare("SELECT COUNT(*) FROM wqs_incoming WHERE po_id=? AND deleted_at IS NULL");
        $x->execute([$poId]); if ((int)$x->fetchColumn()>0) $deps[]='WQS Incoming';
      } catch (Throwable $e) {}
      try {
        $x=$pdo->prepare("SELECT COUNT(*) FROM purchases_invoice_ap WHERE po_id=? AND deleted_at IS NULL");
        $x->execute([$poId]); if ((int)$x->fetchColumn()>0) $deps[]='AP Invoice';
      } catch (Throwable $e) {}
      try {
        $x=$pdo->prepare("SELECT COUNT(*) FROM purchases_ceisa_pib WHERE po_id=?");
        $x->execute([$poId]); if ((int)$x->fetchColumn()>0) $deps[]='CEISA/PIB';
      } catch (Throwable $e) {}
      try {
        $x=$pdo->prepare("SELECT COUNT(*) FROM purchases_forwarding_docs WHERE po_id=? AND deleted_at IS NULL");
        $x->execute([$poId]); if ((int)$x->fetchColumn()>0) $deps[]='Forwarding';
      } catch (Throwable $e) {}
      try {
        $x=$pdo->prepare("SELECT COUNT(*) FROM purchases_import_control WHERE po_id=?");
        $x->execute([$poId]); if ((int)$x->fetchColumn()>0) $deps[]='Import Control';
      } catch (Throwable $e) {}

      if ($deps) {
        $blocked[] = $trialCode.' ('.implode(', ', $deps).')';
        continue;
      }

      $reason = '[TRIAL CLEANUP 2026-09-25] PO trial dibatalkan; histori dipertahankan.';
      $pdo->prepare("UPDATE purchases_po
                        SET status='CANCELLED',
                            deleted_at=COALESCE(deleted_at, NOW()),
                            note=CONCAT(COALESCE(note,''), CASE WHEN COALESCE(note,'')='' THEN '' ELSE '\n' END, ?)
                      WHERE id=?")
          ->execute([$reason, $poId]);

      $stAfter = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1");
      $stAfter->execute([$poId]);
      $after = $stAfter->fetch(PDO::FETCH_ASSOC) ?: $poTrial;

      rmi_audit_safe('UPDATE', 'PURCHASES.PO', $poId, $poTrial, $after, [
        'event'=>'cancel_trial_po',
        'po_code'=>$trialCode,
        'reason'=>'TRIAL CLEANUP 2026-09-25',
      ]);
      if (function_exists('master_audit')) {
        master_audit($pdo,'purchases_po','purchases_po','CANCEL_TRIAL',$poId,$trialCode,
          'PO trial cancelled; history retained.',['reason'=>'TRIAL CLEANUP 2026-09-25']);
      }
      p_audit($pdo,'PO',$trialCode,'CANCEL_TRIAL',[
        'id'=>$poId,
        'reason'=>'TRIAL CLEANUP 2026-09-25',
      ]);

      $cancelled[] = $trialCode;
    }

    $pdo->commit();

    $parts = [];
    $parts[] = count($cancelled).' PO trial berhasil CANCELLED';
    if ($already) $parts[] = count($already).' sudah CANCELLED';
    if ($blocked) $parts[] = count($blocked).' diblokir karena ada downstream: '.implode('; ', $blocked);
    if ($missing) $parts[] = count($missing).' tidak ditemukan: '.implode(', ', $missing);

    p_flash_set($blocked ? 'warning' : 'success', implode(' | ', $parts));
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (function_exists('rmi_log_module_error')) {
      rmi_log_module_error('purchases_po', $e, ['action'=>'CANCEL_TRIAL_PO_BATCH']);
    }
    p_flash_set('danger','Cleanup PO trial gagal dan seluruh perubahan transaksi di-rollback: '.$e->getMessage());
  }

  rmi_redirect('purchases_po.php');
}

if ($action === 'create_po') {
  if (!p_can_create_po()) {
    p_flash_set('danger','Hanya PQP/Admin yang boleh membuat PO.');
    rmi_redirect("purchases_po.php");
  }

  $po_date = $_POST['po_date'] ?? date('Y-m-d');
  $pr_id = (int)($_POST['pr_id'] ?? 0);
  $manufacture_id = (int)($_POST['manufacture_id'] ?? 0);
  $posted_office_code = up($_POST['office_code'] ?? '');
  $office_code = '';
  $currency = up($_POST['currency'] ?? 'IDR');
  $payment_term = up($_POST['payment_term'] ?? '');
  // Alur procurement ditentukan PQP saat PO dibuat. Marker di note menjaga kompatibilitas
  // dengan schema lama; tidak ada ALTER TABLE atau perubahan data existing.
  $procurement_flow = up($_POST['procurement_flow'] ?? 'LOCAL');
  if (!in_array($procurement_flow, ['LOCAL','IMPORT'], true)) $procurement_flow = 'LOCAL';
  $noteUser = trim((string)($_POST['note'] ?? ''));
  $note = '[FLOW:' . $procurement_flow . ']' . ($noteUser !== '' ? "\n" . $noteUser : '');

  if ($pr_id<=0) { p_flash_set('danger','PR wajib dipilih.'); rmi_redirect("purchases_po.php"); }
  // Re-validate PR status to prevent race condition (two users creating PO from same PR)
  $stPrCheck = $pdo->prepare("SELECT id, status, pr_code, office_code, category, business_group FROM wqs_pr WHERE id=? AND deleted_at IS NULL LIMIT 1");
  $stPrCheck->execute([$pr_id]);
  $prCheck = $stPrCheck->fetch(PDO::FETCH_ASSOC);
  if (!$prCheck) { p_flash_set('danger','PR tidak ditemukan atau sudah dihapus.'); rmi_redirect("purchases_po.php"); }
  if (strtoupper($prCheck['status'] ?? '') !== 'SUBMITTED') {
    p_flash_set('danger','PR '.$prCheck['pr_code'].' sudah berstatus '.strtoupper($prCheck['status'] ?? '').'. PO tidak bisa dibuat dari PR yang bukan SUBMITTED.');
    rmi_redirect("purchases_po.php");
  }
  if ($manufacture_id<=0) { p_flash_set('danger','Manufacture/Pabrikan wajib dipilih.'); rmi_redirect("purchases_po.php"); }
  $office_code = up((string)($prCheck['office_code'] ?? ''));
  $po_business_group = po_document_group($prCheck);
  if (!in_array($po_business_group, ['BMHP','UNIT_ACC'], true)) {
    p_flash_set('danger','Kelompok PR tidak valid untuk dibuat PO.');
    rmi_redirect("purchases_po.php");
  }
  // Header PO mengikuti kelompok dokumen. Kategori teknis ALKES/AKSESORIS tetap disimpan per item.
  $po_category = $po_business_group;
  if ($office_code==='') { p_flash_set('danger','Office pada PR kosong. PO tidak dapat dibuat.'); rmi_redirect("purchases_po.php"); }
  if ($posted_office_code !== '' && $posted_office_code !== $office_code) { p_flash_set('danger','Office PO dikunci dari PR ('.$office_code.').'); rmi_redirect("purchases_po.php"); }
  if ($_ppo_scope !== null && $office_code !== $_ppo_scope) { p_flash_set('danger','Akses ditolak: tidak bisa membuat PO untuk kantor lain ('.$office_code.'). Kantor Anda: '.$_ppo_scope); rmi_redirect("purchases_po.php"); }

  $dateYmd = date('ymd', strtotime($po_date));
  if (!function_exists('doc_prefix_po')) require_once __DIR__ . '/../config/doc_numbering.php';
  try {
    $prefix = doc_prefix_po($office_code, $dateYmd, $po_category);
  } catch (Throwable $e) {
    $prefix = '';
  }
  if (trim((string)$prefix) === '') {
    $prefix = ($po_business_group === 'UNIT_ACC')
      ? ('UNITACC-' . strtoupper($office_code) . '-' . $dateYmd . '-')
      : ('BMHP-' . strtoupper($office_code) . '-' . $dateYmd . '-');
  }
  $po_code = p_generate_code($pdo, 'purchases_po', 'po_code', $prefix);

  $pdo->beginTransaction();
  try {
    $pdo->prepare("INSERT INTO purchases_po (po_code, po_date, pr_id, manufacture_id, office_code, currency, payment_term, note, status, total_amount, category, business_group, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$po_code,$po_date,$pr_id,$manufacture_id,$office_code,$currency,$payment_term,$note,'OPEN',0,$po_category,$po_business_group,p_username()]);
    $po_id = (int)$pdo->lastInsertId();

    // Item kebutuhan adalah single source of truth dari PR WQS.
    // PQP hanya mengisi data komersial (harga, diskon, PPN), bukan mengubah produk/qty/unit.
    $prices = $_POST['unit_price'] ?? [];
    $discounts = $_POST['discount_percent'] ?? [];
    $ppns = $_POST['ppn_percent'] ?? [];
    $stPrItems = $pdo->prepare("SELECT i.product_id,i.sku,i.products_name,i.qty,i.unit, COALESCE(NULLIF(UPPER(TRIM(i.business_group)),''),NULLIF(UPPER(TRIM(mp.business_group)),'')) AS business_group, COALESCE(NULLIF(UPPER(TRIM(i.category)),''),NULLIF(UPPER(TRIM(mp.category)),'')) AS category FROM wqs_pr_items i LEFT JOIN master_products mp ON mp.id=i.product_id WHERE i.pr_id=? AND i.deleted_at IS NULL ORDER BY i.line_no,i.id");
    $stPrItems->execute([$pr_id]);
    $prItems = $stPrItems->fetchAll(PDO::FETCH_ASSOC);

    $line=1;
    foreach ($prItems as $i => $prIt){
      $pid=(int)($prIt['product_id'] ?? 0);
      $qty=(float)($prIt['qty'] ?? 0);
      $unit=trim((string)($prIt['unit'] ?? 'pcs'));
      $price=(float)($prices[$i] ?? 0);

      if ($pid<=0 || $qty<=0) continue;
      if (!is_finite($price) || $price < 0) $price = 0;
      if (!p_can_view_buy_price()) $price = 0;

      $sku=rmi_sku((string)($prIt['sku'] ?? ''));
      $pname=rmi_product_name((string)($prIt['products_name'] ?? ''));
      $itemCategory=up((string)($prIt['category'] ?? ''));
      $itemBusinessGroup=up((string)($prIt['business_group'] ?? ''));
      if ($itemBusinessGroup==='') $itemBusinessGroup=po_expected_business_group($itemCategory);
      if ($po_business_group === 'BMHP') {
        if ($itemBusinessGroup !== 'BMHP' || $itemCategory !== 'BMHP') {
          throw new RuntimeException('Item PR '.$sku.' tidak konsisten: PO BMHP hanya boleh berisi item BMHP.');
        }
      } else {
        if ($itemBusinessGroup !== 'UNIT_ACC' || !in_array($itemCategory, ['ALKES','AKSESORIS'], true)) {
          throw new RuntimeException('Item PR '.$sku.' tidak konsisten: PO UNIT ACC hanya boleh berisi item UNIT_ACC kategori ALKES/AKSESORIS.');
        }
      }
      $discount_percent = (float)($discounts[$i] ?? 0);
      $ppn_percent = (float)($ppns[$i] ?? 11);

$gross = $qty * $price;
$discount_amount = $gross * ($discount_percent / 100);
$subtotal = $gross - $discount_amount;
$ppn_amount = $subtotal * ($ppn_percent / 100);
$total_after_tax = $subtotal + $ppn_amount;

      $pdo->prepare("INSERT INTO purchases_po_items 
(po_id,line_no,product_id,sku,products_name,business_group,category,qty,unit,unit_price,discount_percent,discount_amount,ppn_percent,ppn_amount,subtotal,total_after_tax)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([
  $po_id,$line,$pid,$sku,$pname,$itemBusinessGroup,$itemCategory,$qty,$unit,$price,
  $discount_percent,$discount_amount,$ppn_percent,$ppn_amount,
  $subtotal,$total_after_tax
]);
      $line++;
    }

    if ($line <= 1) {
      $pdo->rollBack();
      p_flash_set('danger', 'PO harus memiliki minimal 1 item produk yang valid (qty > 0).');
      rmi_redirect("purchases_po.php");
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
      'category' => $po_category,
      'business_group' => $po_business_group,
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
    if (function_exists('master_audit')) {
      master_audit($pdo, 'purchases_po', 'purchases_po', 'CREATE', $po_id, $po_code, "PO created: {$po_code}", ['pr_id' => $pr_id, 'manufacture_id' => $manufacture_id, 'procurement_flow' => $procurement_flow]);
    }
    p_audit($pdo,'PO',$po_code,'CREATE',['po_id'=>$po_id,'pr_id'=>$pr_id,'manufacture_id'=>$manufacture_id,'procurement_flow'=>$procurement_flow]);
    p_flash_set('success',"PO dibuat: {$po_code} (dari PR)");
    rmi_redirect('purchases_po_view.php?id=' . $po_id);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (function_exists('rmi_log_module_error')) rmi_log_module_error('purchases_po', $e, ['action' => 'CREATE_PO', 'pr_id' => $pr_id ?? null]);
    p_flash_set('danger','Gagal membuat PO: '.$e->getMessage());
    rmi_redirect("purchases_po.php");
  }
}

// status change (manager+)
if (isset($_GET['set_status']) && isset($_GET['id']) && p_is_manager_plus()) {
  verify_csrf((string)($_GET['csrf_token'] ?? ''));
  $id=(int)$_GET['id']; $stt=up($_GET['set_status']);
  if (!in_array($stt,['DRAFT','OPEN','IN_PRODUCTION','READY','CLOSED','CANCELLED'],true)) $stt='OPEN';
  if ($stt === 'CANCELLED') {
    p_flash_set('danger','Pembatalan PO tidak boleh dari quick status. Buka View PO dan pilih flow yang benar: Revisi Kebutuhan WQS atau Batalkan Kebutuhan. Impact Preview wajib bersih; jika ada downstream gunakan reversal/operational cancellation.');
    rmi_redirect('purchases_po_view.php?id='.$id);
  }
  try {
    $before = null;
    $after = null;
    $st=$pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $st->execute([$id]); $before=$st->fetch(PDO::FETCH_ASSOC);
    if (!$before) throw new RuntimeException('PO tidak ditemukan.');
    $code=(string)($before['po_code'] ?? '');
    $current=up((string)($before['status'] ?? 'OPEN'));
    $allowed=[
      'DRAFT'=>['OPEN'],
      'OPEN'=>['IN_PRODUCTION'],
      'IN_PRODUCTION'=>['READY'],
      'READY'=>['CLOSED'],
      'CLOSED'=>[],
      'CANCELLED'=>[],
    ];
    if ($stt !== $current && !in_array($stt,$allowed[$current] ?? [],true)) {
      throw new RuntimeException('Transisi status tidak valid: '.$current.' -> '.$stt.'. Gunakan urutan DRAFT → OPEN → IN_PRODUCTION → READY → CLOSED.');
    }
    if (in_array($stt,['IN_PRODUCTION','READY','CLOSED'],true) && (float)($before['total_amount'] ?? 0) <= 0) {
      throw new RuntimeException('Tidak bisa set '.$stt.' karena total PO masih 0. Isi harga beli terlebih dahulu.');
    }
    $pdo->prepare("UPDATE purchases_po SET status=? WHERE id=?")->execute([$stt,$id]);
    $st2=$pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $st2->execute([$id]); $after=$st2->fetch();

    // Business cancellation is handled in purchases_po_view.php with mandatory reason and downstream guards.
    // PATCH_3_AUDIT
    rmi_audit_safe('UPDATE', 'PURCHASES.PO', $id, $before, $after, [
      'event' => 'set_status',
      'to_status' => $stt,
      'po_code' => $code,
    ]);
    if (function_exists('master_audit')) {
      master_audit($pdo, 'purchases_po', 'purchases_po', 'SET_STATUS', $id, $code, "PO status -> {$stt}", ['status' => $stt]);
    }
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
  } catch (Throwable $e) {
    if (function_exists('rmi_log_module_error')) rmi_log_module_error('purchases_po', $e, ['action' => 'SET_STATUS_PO', 'id' => $id ?? null]);
    p_flash_set('danger',$e->getMessage());
  }
  rmi_redirect("purchases_po.php");
}

// soft delete/restore admin+
if (isset($_GET['delete']) && p_is_admin_plus()) {
  verify_csrf((string)($_GET['csrf_token'] ?? ''));
  $id=(int)$_GET['delete'];
  try {
    $before = null; $after = null;
    $stB = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $stB->execute([$id]); $before = $stB->fetch(PDO::FETCH_ASSOC);
    if (!$before) throw new RuntimeException('PO tidak ditemukan.');
    $code=(string)($before['po_code'] ?? '');
    $statusBefore=strtoupper(trim((string)($before['status'] ?? '')));
    if ($statusBefore !== 'DRAFT') throw new RuntimeException('Soft delete hanya untuk PO DRAFT yang salah dibuat. PO OPEN/IN_PRODUCTION/READY/CLOSED/CANCELLED harus dipertahankan sebagai histori dan memakai flow cancellation/reversal.');
    // Minimal dependency guard before destructive hide.
    $dep=0;
    try { $x=$pdo->prepare("SELECT COUNT(*) FROM wqs_incoming WHERE po_code=?"); $x->execute([$code]); $dep+=(int)$x->fetchColumn(); } catch (Throwable $e) {}
    try { $x=$pdo->prepare("SELECT COUNT(*) FROM purchases_invoice_ap WHERE po_id=? AND deleted_at IS NULL"); $x->execute([$id]); $dep+=(int)$x->fetchColumn(); } catch (Throwable $e) {}
    try { $x=$pdo->prepare("SELECT COUNT(*) FROM purchases_ceisa_pib WHERE po_id=?"); $x->execute([$id]); $dep+=(int)$x->fetchColumn(); } catch (Throwable $e) {}
    try { $x=$pdo->prepare("SELECT COUNT(*) FROM purchases_forwarding_docs WHERE po_id=? AND deleted_at IS NULL"); $x->execute([$id]); $dep+=(int)$x->fetchColumn(); } catch (Throwable $e) {}
    if ($dep>0) throw new RuntimeException('PO DRAFT memiliki dependency downstream; soft delete diblokir. Review melalui View PO.');
    $pdo->prepare("UPDATE purchases_po SET deleted_at=NOW() WHERE id=? AND status='DRAFT'")->execute([$id]);
    try { $stA = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $stA->execute([$id]); $after = $stA->fetch(); } catch (Throwable $e) { $after = null; }

    // Rollback PR status when PO is deleted
    if (!empty($before['pr_id'])) {
      $prIdRb = (int)$before['pr_id'];
      $stOther = $pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE pr_id=? AND id!=? AND deleted_at IS NULL AND status NOT IN ('CANCELLED')");
      $stOther->execute([$prIdRb, $id]);
      if ((int)$stOther->fetchColumn() === 0) {
        $pdo->prepare("UPDATE wqs_pr SET status='SUBMITTED' WHERE id=? AND status='PO_CREATED'")->execute([$prIdRb]);
      }
    }

    // PATCH_3_AUDIT
    rmi_audit_safe('DELETE', 'PURCHASES.PO', $id, $before, $after, [
      'event' => 'soft_delete',
      'po_code' => $code,
    ]);
    if (function_exists('master_audit')) {
      master_audit($pdo, 'purchases_po', 'purchases_po', 'SOFT_DELETE', $id, $code, "PO soft deleted: {$code}", []);
    }
    p_audit($pdo,'PO',$code,'SOFT_DELETE',['id'=>$id]);
    p_flash_set('success','PO soft deleted.');
  } catch (Throwable $e) {
    if (function_exists('rmi_log_module_error')) rmi_log_module_error('purchases_po', $e, ['action' => 'SOFT_DELETE_PO', 'id' => $id ?? null]);
    p_flash_set('danger',$e->getMessage());
  }
  rmi_redirect("purchases_po.php");
}
if (isset($_GET['restore']) && p_is_admin_plus()) {
  verify_csrf((string)($_GET['csrf_token'] ?? ''));
  $id=(int)$_GET['restore'];
  try { $st=$pdo->prepare("SELECT po_code FROM purchases_po WHERE id=?"); $st->execute([$id]); $code=(string)$st->fetchColumn();
    $before = null;
    $after = null;
    try { $stB = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $stB->execute([$id]); $before = $stB->fetch(); } catch (Throwable $e) { $before = null; }
    $pdo->prepare("UPDATE purchases_po SET deleted_at=NULL WHERE id=?")->execute([$id]);
    try { $stA = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1"); $stA->execute([$id]); $after = $stA->fetch(); } catch (Throwable $e) { $after = null; }

    // Re-mark PR as PO_CREATED when PO is restored
    if (!empty($before['pr_id'])) {
      $prIdRestore = (int)$before['pr_id'];
      $poStatus = strtoupper(trim((string)($after['status'] ?? $before['status'] ?? '')));
      if ($poStatus !== '' && $poStatus !== 'CANCELLED') {
        $pdo->prepare("UPDATE wqs_pr SET status='PO_CREATED' WHERE id=? AND status='SUBMITTED'")->execute([$prIdRestore]);
      }
    }

    // PATCH_3_AUDIT
    rmi_audit_safe('UPDATE', 'PURCHASES.PO', $id, $before, $after, [
      'event' => 'restore',
      'po_code' => $code,
    ]);
    if (function_exists('master_audit')) {
      master_audit($pdo, 'purchases_po', 'purchases_po', 'RESTORE', $id, $code, "PO restored: {$code}", []);
    }
    p_audit($pdo,'PO',$code,'RESTORE',['id'=>$id]);
    p_flash_set('success','PO restored.');
  } catch (Throwable $e) {
    if (function_exists('rmi_log_module_error')) rmi_log_module_error('purchases_po', $e, ['action' => 'RESTORE_PO', 'id' => $id ?? null]);
    p_flash_set('danger',$e->getMessage());
  }
  rmi_redirect("purchases_po.php");
}

// List
$where=["1=1"]; $params=[];
$show_deleted = ($_GET['show_deleted'] ?? '')==='1';
if (!$show_deleted) $where[]="po.deleted_at IS NULL";
$status_f=up($_GET['status'] ?? '');
if ($status_f!=='' && $status_f!=='ALL'){ $where[]="po.status=?"; $params[]=$status_f; }
// Scope: BRANCH dipaksa ke office sendiri; user lain bisa filter optional
$office_f=up($_GET['office_code'] ?? '');
if ($_ppo_scope !== null) {
    $office_f = $_ppo_scope;
    $where[]="po.office_code=?"; $params[]=$_ppo_scope;
} else {
    if ($office_f!=='' && $office_f!=='ALL'){ $where[]="po.office_code=?"; $params[]=$office_f; }
}

// Filter flow menggunakan klasifikasi canonical:
// explicit marker menang; PO legacy hanya diinfer IMPORT bila ada evidence import.
$flow_f = up($_GET['flow'] ?? 'ALL');
if (!in_array($flow_f, ['ALL','LOCAL','IMPORT'], true)) $flow_f = 'ALL';

$importEvidenceSql = "(
  EXISTS (SELECT 1 FROM purchases_import_control icf WHERE icf.po_id=po.id)
  OR po.forwarder_vendor_id IS NOT NULL
  OR EXISTS (SELECT 1 FROM purchases_ceisa_pib cpf WHERE cpf.po_id=po.id)
  OR EXISTS (
      SELECT 1 FROM purchases_forwarding_docs pfdf
      WHERE pfdf.po_id=po.id AND pfdf.deleted_at IS NULL
  )
)";

if ($flow_f === 'IMPORT') {
    $where[] = "(
      po.note LIKE '%[FLOW:IMPORT]%'
      OR (
        COALESCE(po.note,'') NOT LIKE '%[FLOW:LOCAL]%'
        AND COALESCE(po.note,'') NOT LIKE '%[FLOW:IMPORT]%'
        AND {$importEvidenceSql}
      )
    )";
} elseif ($flow_f === 'LOCAL') {
    $where[] = "(
      po.note LIKE '%[FLOW:LOCAL]%'
      OR (
        COALESCE(po.note,'') NOT LIKE '%[FLOW:LOCAL]%'
        AND COALESCE(po.note,'') NOT LIKE '%[FLOW:IMPORT]%'
        AND NOT {$importEvidenceSql}
      )
    )";
}

$from=$_GET['from'] ?? ''; $to=$_GET['to'] ?? '';
if ($from!==''){ $where[]="po.po_date>=?"; $params[]=$from; }
if ($to!==''){ $where[]="po.po_date<=?"; $params[]=$to; }

// Filter PO Overdue harus identik dengan purchases_dashboard.php.
// Aktif hanya saat ?overdue=1, sehingga list normal dan workflow PO tidak berubah.
$overdue_f = (($_GET['overdue'] ?? '') === '1');
if ($overdue_f) {
  $where[] = "UPPER(COALESCE(po.status,'')) IN ('OPEN','IN_PRODUCTION','READY')";

  // Hanya PO yang qty-nya masih outstanding.
  $where[] = "COALESCE((
      SELECT SUM(pio.qty)
      FROM purchases_po_items pio
      WHERE pio.po_id=po.id
        AND (pio.deleted_at IS NULL OR pio.deleted_at='0000-00-00 00:00:00')
    ),0) > COALESCE((
      SELECT SUM(wiio.qty)
      FROM wqs_incoming_items wiio
      JOIN wqs_incoming wio ON wio.id=wiio.incoming_id
      WHERE wio.po_id=po.id
        AND wio.deleted_at IS NULL
    ),0)";

  // Prioritas due date sama dengan dashboard:
  // ETA Import Control -> selected forwarder quote lead time -> fallback 60 hari.
  $where[] = "(
    EXISTS (
      SELECT 1
      FROM purchases_import_control ico
      WHERE ico.po_id=po.id
        AND ico.eta IS NOT NULL
        AND DATE(ico.eta)<CURDATE()
        AND ico.arrived_warehouse_date IS NULL
    )
    OR EXISTS (
      SELECT 1
      FROM purchases_forwarder_quotes qo
      WHERE qo.po_id=po.id
        AND UPPER(COALESCE(qo.status,''))='SELECTED'
        AND COALESCE(qo.leadtime_days,0)>0
        AND DATE_ADD(DATE(qo.quote_date), INTERVAL qo.leadtime_days DAY)<CURDATE()
        AND qo.deleted_at IS NULL
    )
    OR (
      NOT EXISTS (
        SELECT 1 FROM purchases_import_control icot
        WHERE icot.po_id=po.id AND icot.eta IS NOT NULL
      )
      AND NOT EXISTS (
        SELECT 1 FROM purchases_forwarder_quotes qot
        WHERE qot.po_id=po.id
          AND UPPER(COALESCE(qot.status,''))='SELECTED'
          AND COALESCE(qot.leadtime_days,0)>0
          AND qot.deleted_at IS NULL
      )
      AND DATE(po.po_date)<=DATE_SUB(CURDATE(),INTERVAL 60 DAY)
    )
  )";
}

$sql="SELECT po.*, o.office_name, m.manufacture_name, pr.pr_code,
             pr.submitted_at AS pr_submitted_at,
             po.created_at AS po_created_at,
             (SELECT MIN(wi.created_at)
                FROM wqs_incoming wi
               WHERE wi.po_id=po.id) AS first_incoming_at,
             (SELECT MAX(wi.created_at)
                FROM wqs_incoming wi
               WHERE wi.po_id=po.id) AS last_incoming_at,
             (SELECT COALESCE(SUM(poi2.qty),0)
                FROM purchases_po_items poi2
               WHERE poi2.po_id=po.id AND poi2.deleted_at IS NULL) AS po_qty_total,
             (SELECT COALESCE(SUM(wii2.qty),0)
                FROM wqs_incoming wi2
                JOIN wqs_incoming_items wii2 ON wii2.incoming_id=wi2.id
               WHERE wi2.po_id=po.id) AS received_qty_total,
             (SELECT icx.id FROM purchases_import_control icx WHERE icx.po_id=po.id LIMIT 1) AS import_control_id,
             (SELECT cpx.id FROM purchases_ceisa_pib cpx WHERE cpx.po_id=po.id LIMIT 1) AS ceisa_pib_id,
             (SELECT COUNT(*) FROM purchases_forwarding_docs pfdx WHERE pfdx.po_id=po.id AND pfdx.deleted_at IS NULL) AS forwarding_docs_count
      FROM purchases_po po
      LEFT JOIN master_office o ON o.office_code=po.office_code
      LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
      LEFT JOIN wqs_pr pr ON pr.id=po.pr_id
      WHERE ".implode(" AND ",$where)."
      ORDER BY po.id DESC";
$rows=[];
try {
  $st=$pdo->prepare($sql);
  $st->execute($params);
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  if (function_exists('rmi_log_module_error')) rmi_log_module_error('purchases_po', $e, ['action'=>'LIST_PO']);
  $rows=[];
}

$canPrice = p_can_view_buy_price();

// Audit log (last 50) - purchases_po
$audit_rows = [];
try {
    if (function_exists('master_audit_ensure_table')) {
        master_audit_ensure_table($pdo);
    }
    $st = $pdo->prepare("
        SELECT action, record_code, username, description, created_at
        FROM system_audit_logs
        WHERE module = 'purchases_po'
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $audit_rows = [];
}


// PQP SLA display only. Business workflow remains unchanged.
// Historical audit recovery MUST NOT be embedded as hard-coded subqueries in the main PO SELECT:
// a missing/legacy audit table would make the entire PO list query fail.
function pqp_table_exists(PDO $pdo, string $table): bool {
  try {
    $st=$pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch(Throwable $e){ return false; }
}
function pqp_column_exists(PDO $pdo, string $table, string $column): bool {
  try {
    $st=$pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
    $st->execute([$table,$column]);
    return (bool)$st->fetchColumn();
  } catch(Throwable $e){ return false; }
}
function pqp_recover_submit_from_audit(PDO $pdo, string $prCode, string $poCreatedAt): ?string {
  $prCode=trim($prCode);
  if($prCode==='' || trim($poCreatedAt)==='') return null;

  // Current master audit schema used elsewhere in this file.
  if (pqp_table_exists($pdo,'system_audit_logs')
      && pqp_column_exists($pdo,'system_audit_logs','module')
      && pqp_column_exists($pdo,'system_audit_logs','record_code')
      && pqp_column_exists($pdo,'system_audit_logs','action')
      && pqp_column_exists($pdo,'system_audit_logs','created_at')) {
    try {
      $st=$pdo->prepare("SELECT MAX(created_at) FROM system_audit_logs
        WHERE module='wqs_pr' AND record_code=?
          AND UPPER(COALESCE(action,'')) IN ('SUBMIT_TO_PQP','SUBMIT')
          AND created_at<=?");
      $st->execute([$prCode,$poCreatedAt]);
      $v=$st->fetchColumn();
      if($v) return (string)$v;
    } catch(Throwable $e){}
  }

  // Optional purchases audit: use only when the exact required columns really exist.
  if (pqp_table_exists($pdo,'purchases_audit')
      && pqp_column_exists($pdo,'purchases_audit','doc_type')
      && pqp_column_exists($pdo,'purchases_audit','doc_code')
      && pqp_column_exists($pdo,'purchases_audit','action')
      && pqp_column_exists($pdo,'purchases_audit','created_at')) {
    try {
      $st=$pdo->prepare("SELECT MAX(created_at) FROM purchases_audit
        WHERE doc_type='PR' AND doc_code=?
          AND UPPER(COALESCE(action,'')) IN ('SUBMIT_TO_PQP','SUBMIT')
          AND created_at<=?");
      $st->execute([$prCode,$poCreatedAt]);
      $v=$st->fetchColumn();
      if($v) return (string)$v;
    } catch(Throwable $e){}
  }
  return null;
}
function pqp_sla_target_minutes(PDO $pdo, ?string $officeCode=null): int {
  $fallback=1440;
  try {
    $st=$pdo->prepare("SELECT config_value FROM system_config
      WHERE config_group='KPI_PURCHASES' AND config_key='PQP_PR_TO_PO_SLA_MINUTES'
        AND is_active=1 AND (office_code=:o OR office_code IS NULL OR office_code='')
      ORDER BY CASE WHEN office_code=:o2 THEN 0 ELSE 1 END, id DESC LIMIT 1");
    $o=strtoupper(trim((string)$officeCode));
    $st->execute([':o'=>$o,':o2'=>$o]);
    $v=$st->fetchColumn();
    if($v!==false && is_numeric($v) && (int)$v>0) return (int)$v;
  } catch(Throwable $e){}
  return $fallback;
}
function pqp_sla_duration_minutes(?string $start, ?string $stop): ?int {
  if(!$start||!$stop)return null;
  $s=strtotime($start);$e=strtotime($stop);
  if(!$s||!$e||$e<$s)return null;
  return (int)floor(($e-$s)/60);
}
function pqp_sla_duration_label(?int $minutes): string {
  if($minutes===null)return 'N/A';
  $d=intdiv($minutes,1440);$r=$minutes%1440;$h=intdiv($r,60);$m=$r%60;$a=[];
  if($d>0)$a[]=$d.'h';
  if($h>0||$d>0)$a[]=$h.'j';
  $a[]=$m.'m';
  return implode(' ',$a);
}

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

rmi_header('Purchase Order (PO) - PQP', 'purchases_po', [
  'subtitle' => 'PO dibuat dari PR (WQS) + pabrikan (master_manufactures).',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Purchase Order',
  ],
  'actions' => [
    ['label' => 'Local Tower', 'url' => 'purchases_control_tower.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Import Tower', 'url' => 'purchases_import_control_tower.php', 'class' => 'btn btn-sm btn-outline-light'],
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
    <h3 class="mb-0">Purchase Order (PO) - PQP <span class="pill">FINAL-PO-TRIAL-CLEANUP-V3-2026-09-25</span></h3>
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
            <?php $prCatOpt=up((string)($pr['category']??'BMHP')); $prBgOpt=po_document_group($pr); if($prBgOpt==='')$prBgOpt='BMHP'; $prDocCat=($prBgOpt==='UNIT_ACC'?'UNIT_ACC':'BMHP'); ?>
            <option value="<?=h($pr['id'])?>" data-office="<?=h(up((string)$pr['office_code']))?>" data-category="<?=h($prDocCat)?>" data-business-group="<?=h($prBgOpt)?>" <?= $preselect_pr_id === (int)$pr['id'] ? 'selected' : '' ?>><?=h($pr['pr_code'].' | '.$pr['office_code'].' | '.$prBgOpt.' | '.$pr['pr_date'])?></option>
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
        <select class="form-select form-select-sm" id="office_code" disabled>
          <option value="">-- auto dari PR --</option>
          <?php foreach($offices as $o): ?><option value="<?=h(strtoupper($o['office_code']??''))?>"><?=h(strtoupper($o['office_name']??''))?></option><?php endforeach; ?>
        </select>
        <div class="muted">Dikunci dari PR; PQP tidak dapat mengubah office.</div>
      </div>

      <div class="col-md-2">
        <label class="form-label">Alur Pembelian <span class="text-danger">*</span></label>
        <select class="form-select form-select-sm" name="procurement_flow" required <?= p_can_create_po()?'':'disabled' ?>>
          <option value="LOCAL">LOCAL</option>
          <option value="IMPORT">IMPORT</option>
        </select>
        <div class="muted">Ditentukan PQP saat PO dibuat. LOCAL tidak masuk Import Control Tower.</div>
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
        <div class="muted mb-2">Pilih PR → produk, qty, unit terkunci dari WQS. PQP/FIN hanya mengisi harga beli, diskon, PPN, currency, supplier, dan payment term.</div>
        <div class="table-responsive">
          <table class="table table-sm table-dark align-middle" id="itemsTable">
            <thead>
              <tr>
    <th>SKU</th>
    <th>Product</th>
    <th style="width:110px">Kelompok</th>
    <th style="width:110px">Kategori</th>
    <th style="width:120px" class="num">Qty</th>
    <th style="width:100px">Unit</th>
    <th style="width:150px" class="num">Unit Price</th>
    <th style="width:110px" class="num">Diskon %</th>
    <th style="width:110px" class="num">PPN %</th>
    <th style="width:160px" class="num">Total</th>
    <th style="width:70px">Aksi</th>
</tr>
            </thead>
            <tbody>
              <tr><td colspan="11" class="muted">Pilih PR dulu.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2">
          <span class="muted">Item kebutuhan mengikuti PR WQS dan tidak dapat ditambah/dihapus dari PO.</span>
          <div class="muted">Total + PPN (preview): <b id="totalPreview">0</b></div>
        </div>

      </div>

      <div class="col-12 mt-2">
        <button class="btn btn-primary btn-sm" <?= p_can_create_po()?'':'disabled' ?>>Save PO</button>
      </div>
    </form>

    <?php if (p_can_create_po()): ?>
    <hr style="border-color:rgba(255,255,255,.12)">
    <form method="post" class="row g-2 align-items-end" onsubmit="return confirm('Kembalikan PR ke WQS untuk diperbaiki?');">
      <input type="hidden" name="action" value="return_pr_revision">
      <div class="col-md-4">
        <label class="form-label">PR yang dikembalikan ke WQS</label>
        <select class="form-select form-select-sm" name="pr_id" required>
          <option value="">-- pilih PR SUBMITTED --</option>
          <?php foreach($pr_list as $pr): ?><option value="<?=h($pr['id'])?>"><?=h($pr['pr_code'].' | '.$pr['office_code'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Alasan revisi <span class="text-danger">*</span></label>
        <input class="form-control form-control-sm" name="revision_reason" required placeholder="Contoh: qty salah, produk salah, unit salah, kebutuhan berubah">
      </div>
      <div class="col-md-2">
        <button class="btn btn-warning btn-sm w-100">↩ Kembalikan ke WQS</button>
      </div>
      <div class="col-12 muted">Gunakan hanya untuk salah produk/qty/unit/kebutuhan. Kesalahan harga, supplier, diskon, PPN, currency, dan payment term diperbaiki di PQP tanpa mengembalikan PR.</div>
    </form>
    <?php endif; ?>

  </div>
</div>

<div class="card">
  <div class="card-body">
    <?php if (p_is_admin_plus()): ?>
      <form method="post" class="mb-3"
            onsubmit="return confirm('Batalkan 23 PO TRIAL yang sudah ditetapkan? Sistem akan mengubah PO trial menjadi CANCELLED dan menyembunyikannya dari daftar aktif. PO yang memiliki transaksi downstream akan otomatis DIBLOKIR dan tidak disentuh.');">
        <input type="hidden" name="action" value="cancel_trial_po">
        <button type="submit" class="btn btn-danger btn-sm">Cancel 23 PO Trial</button>
        <span class="muted ms-2">Controlled cleanup • CANCELLED + hidden dari list aktif • histori & audit tetap dipertahankan • downstream guard aktif</span>
      </form>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="fw-semibold">
        PO List
        <?php if ($overdue_f): ?><span class="pill" style="border-color:#f97316;color:#fb923c">OVERDUE ONLY</span><?php endif; ?>
      </div>
      <form class="d-flex gap-2 flex-wrap" method="get">
        <?php if ($overdue_f): ?><input type="hidden" name="overdue" value="1"><?php endif; ?>
        <input type="date" class="form-control form-control-sm" name="from" value="<?=h($from)?>">
        <input type="date" class="form-control form-control-sm" name="to" value="<?=h($to)?>">
        <select class="form-select form-select-sm" name="status">
          <option value="ALL">All Status</option>
          <?php foreach(['DRAFT','OPEN','IN_PRODUCTION','READY','CLOSED','CANCELLED'] as $s): ?>
            <option value="<?=h($s)?>" <?= $status_f===$s?'selected':'' ?>><?=h($s)?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" name="office_code" <?= $_ppo_scope !== null ? 'disabled' : '' ?>>
          <option value="ALL">All Office</option>
          <?php foreach($offices as $o): ?>
            <option value="<?=h(strtoupper($o['office_code']??''))?>" <?= $office_f===up($o['office_code']??'')?'selected':'' ?>><?=h(strtoupper($o['office_name']??''))?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($_ppo_scope !== null): ?>
          <input type="hidden" name="office_code" value="<?=h($_ppo_scope)?>">
        <?php endif; ?>
        <select class="form-select form-select-sm" name="flow">
          <option value="ALL" <?= $flow_f==='ALL'?'selected':'' ?>>All Flow</option>
          <option value="LOCAL" <?= $flow_f==='LOCAL'?'selected':'' ?>>LOCAL</option>
          <option value="IMPORT" <?= $flow_f==='IMPORT'?'selected':'' ?>>IMPORT</option>
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
            <th style="display:none">Sort ID</th>
            <th>PO Code</th>
            <th>Date</th>
            <th>PR</th>
            <th>Manufacture</th>
            <th>Office</th>
            <th>Kelompok</th>
            <th>Kategori</th>
            <th>Alur</th>
            <th>Status</th>
            <th class="num">Total</th>
            <th>Durasi SLA PQP</th>
            <th>Lead Time Barang</th>
            <th>Created By</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td style="display:none"><?= (int)($r['id'] ?? 0) ?></td>
              <td><b><?=h(strtoupper($r['po_code']??''))?></b><?= $r['deleted_at'] ? " <span class='pill'>DELETED</span>" : "" ?></td>
              <td><?=h($r['po_date'])?></td>
              <td><?=h(strtoupper($r['pr_code'] ?? ''))?></td>
              <td><?=h(strtoupper($r['manufacture_name'] ?? ''))?></td>
              <td><?=h(strtoupper($r['office_name'] ?? $r['office_code'] ?? ''))?></td>
              <?php $poCatRow=up((string)($r['category']??'BMHP')); $poBgRow=po_document_group($r); if($poBgRow==='')$poBgRow='BMHP'; ?>
              <td><span class="pill"><?=h($poBgRow)?></span></td>
              <td><span class="pill"><?=h($poCatRow)?></span></td>
              <?php
                $flowLabel = p_po_flow($r);
                $flowSource = p_po_flow_source($r);
              ?>
              <td>
                <span class="pill"><?=h($flowLabel)?></span>
                <?php if ($flowSource !== 'EXPLICIT'): ?>
                  <div class="muted"><?=h($flowSource)?></div>
                <?php endif; ?>
              </td>
              <td><?=h(strtoupper($r['status'] ?? ''))?></td>
              <td class="num"><?=h($canPrice ? p_money($r['total_amount'], $r['currency'] ?? 'IDR') : '0')?></td>
              <td>
                <?php
                  $slaStart=(string)($r['pr_submitted_at']??'');
                  $slaStartSource='SUBMITTED_AT';
                  if($slaStart===''){
                    $slaStart=(string)(pqp_recover_submit_from_audit(
                      $pdo,
                      (string)($r['pr_code']??''),
                      (string)($r['po_created_at']??'')
                    ) ?? '');
                    if($slaStart!=='') $slaStartSource='AUDIT';
                  }
                  $slaMin=pqp_sla_duration_minutes(
                    $slaStart,
                    (string)($r['po_created_at']??'')
                  );
                  $slaTarget=pqp_sla_target_minutes($pdo,(string)($r['office_code']??''));
                ?>
                <?php if($slaMin===null): ?>
                  <strong>N/A</strong>
                  <div class="muted" style="font-size:11px">Evidence Submit PR tidak tersedia</div>
                <?php else: ?>
                  <strong><?=h(pqp_sla_duration_label($slaMin))?></strong>
                  <?php if($slaMin<=$slaTarget): ?>
                    <div style="font-size:12px;color:#86efac;font-weight:700">ON SLA</div>
                  <?php else: ?>
                    <div style="font-size:12px;color:#fca5a5;font-weight:700">OVER SLA</div>
                  <?php endif; ?>
                  <div class="muted" style="font-size:11px">
                    Submit <?=h(date('d-m-Y H:i',strtotime($slaStart)))?>
                    <?php if($slaStartSource==='AUDIT'): ?><span title="Recovered dari audit trail">†</span><?php endif; ?><br>
                    PO <?=h(date('d-m-Y H:i',strtotime((string)$r['po_created_at'])))?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <?php
                  $poStatus=strtoupper(trim((string)($r['status']??'')));
                  $poCancelled=in_array($poStatus,['CANCELLED','CANCELED','VOID'],true);
                  $poStart=(string)($r['po_created_at']??'');
                  $firstIncoming=(string)($r['first_incoming_at']??'');
                  $lastIncoming=(string)($r['last_incoming_at']??'');
                  $poQty=(float)($r['po_qty_total']??0);
                  $receivedQty=(float)($r['received_qty_total']??0);
                  $isFull=($poQty>0 && $receivedQty+0.000001 >= $poQty);
                  $firstMin=$poCancelled ? null : pqp_sla_duration_minutes($poStart,$firstIncoming);
                  $fullMin=(!$poCancelled && $isFull) ? pqp_sla_duration_minutes($poStart,$lastIncoming) : null;
                ?>
                <?php if($poCancelled): ?>
                  <strong>CANCELLED</strong>
                  <div class="muted" style="font-size:11px">Tidak dihitung</div>
                <?php elseif($firstMin===null): ?>
                  <strong>Belum datang</strong>
                  <div class="muted" style="font-size:11px">PO → WQS Incoming</div>
                <?php else: ?>
                  <div><strong>Barang Pertama: <?=h(pqp_sla_duration_label($firstMin))?></strong></div>
                  <?php if($isFull && $fullMin!==null): ?>
                    <div style="font-size:12px;color:#86efac;font-weight:700">Barang Lengkap: <?=h(pqp_sla_duration_label($fullMin))?></div>
                  <?php else: ?>
                    <div style="font-size:12px;color:#fde68a;font-weight:700">
                      PARTIAL <?=h(rtrim(rtrim(number_format($receivedQty,2,'.',''),'0'),'.'))?> /
                      <?=h(rtrim(rtrim(number_format($poQty,2,'.',''),'0'),'.'))?>
                    </div>
                  <?php endif; ?>
                  <div class="muted" style="font-size:11px">
                    Pertama <?=h(date('d-m-Y H:i',strtotime($firstIncoming)))?>
                    <?php if($isFull && $lastIncoming): ?><br>Lengkap <?=h(date('d-m-Y H:i',strtotime($lastIncoming)))?><?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td><?=h($r['created_by'] ?? '')?></td>
              <td>
                <a class="btn btn-soft btn-sm" href="purchases_po_view.php?id=<?=h($r['id'])?>">View</a>
                <a class="btn btn-soft btn-sm" href="purchases_po_print.php?id=<?=h($r['id'])?>" target="_blank">Print</a>
                <?php if ($flowLabel === 'IMPORT'): ?>
                  <a class="btn btn-soft btn-sm" href="purchases_import_control_tower.php?q=<?=urlencode((string)($r['po_code'] ?? ''))?>">Import Tower</a>
                <?php else: ?>
                  <a class="btn btn-soft btn-sm" href="purchases_control_tower.php?q=<?=urlencode((string)($r['po_code'] ?? ''))?>">Local Tower</a>
                <?php endif; ?>
                <?php if (p_can_create_po() && in_array(strtoupper((string)($r['status'] ?? '')),['DRAFT','OPEN'],true) && !$r['deleted_at']): ?>
                  <a class="btn btn-warning btn-sm" href="purchases_po_view.php?id=<?=h($r['id'])?>">Review / Cancel</a>
                <?php endif; ?>
                <?php if (p_is_manager_plus() && !$r['deleted_at']): ?>
                  <div class="btn-group">
                    <button type="button" class="btn btn-soft btn-sm dropdown-toggle" data-bs-toggle="dropdown">Status</button>
                    <ul class="dropdown-menu dropdown-menu-dark">
                      <li><a class="dropdown-item" href="?set_status=OPEN&id=<?=h($r['id'])?>&csrf_token=<?=csrf_token()?>">OPEN</a></li>
                      <li><a class="dropdown-item" href="?set_status=IN_PRODUCTION&id=<?=h($r['id'])?>&csrf_token=<?=csrf_token()?>">IN_PRODUCTION</a></li>
                      <li><a class="dropdown-item" href="?set_status=READY&id=<?=h($r['id'])?>&csrf_token=<?=csrf_token()?>">READY</a></li>
                      <li><a class="dropdown-item" href="?set_status=CLOSED&id=<?=h($r['id'])?>&csrf_token=<?=csrf_token()?>">CLOSED</a></li>
                    </ul>
                  </div>
                <?php endif; ?>
                <?php if (p_is_admin_plus()): ?>
                  <?php if ($r['deleted_at']): ?>
                    <a class="btn btn-success btn-sm" href="?restore=<?=h($r['id'])?>&csrf_token=<?=csrf_token()?>" onclick="return confirm('Restore PO DRAFT?')">Restore</a>
                  <?php elseif (strtoupper((string)($r['status'] ?? ''))==='DRAFT'): ?>
                    <a class="btn btn-danger btn-sm" href="?delete=<?=h($r['id'])?>&csrf_token=<?=csrf_token()?>" onclick="return confirm('Soft delete hanya PO DRAFT yang salah dibuat. Lanjutkan?')">Delete Draft</a>
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

<!-- AUDIT LOG -->
<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-2">Audit Log <span class="muted">(Last 50 events)</span></div>
    <?php if (empty($audit_rows)): ?>
      <div class="muted">Belum ada audit log.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-dark align-middle">
          <thead><tr><th style="width:180px">Time</th><th style="width:120px">Action</th><th style="width:160px">Code</th><th style="width:120px">User</th><th>Description</th></tr></thead>
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
    <?php endif; ?>
  </div>
</div>

<script>
const PRODUCTS = <?php echo json_encode(array_map(fn($p)=>['id'=>$p['id'],'sku'=>rmi_sku($p['sku']),'products_name'=>rmi_product_name($p['products_name']),'unit'=>$p['unit']??'pcs'], $products), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const CAN_PRICE = <?php echo p_can_view_buy_price() ? 'true' : 'false'; ?>;
const CAN_CREATE = <?php echo p_can_create_po() ? 'true' : 'false'; ?>;

function rowHtml(item){
  const pid = item.product_id || '';
  const sku = item.sku || '';
  const name = item.name || item.products_name || '';
  const qty = item.qty ?? '';
  const unit = item.unit || 'pcs';
  const category=(item.category||'').toUpperCase();
  const businessGroup=(item.business_group||'').toUpperCase();
  const price = CAN_PRICE ? (item.unit_price ?? 0) : 0;
  const discount = item.discount_percent ?? 0;
  const ppn = item.ppn_percent ?? 11;

  const gross = parseFloat(qty || 0) * parseFloat(price || 0);
  const net = gross - (gross * parseFloat(discount || 0) / 100);
  const total = net + (net * parseFloat(ppn || 0) / 100);

  return `
    <tr>
      <td>
        <input type="hidden" name="product_id[]" value="${pid}">
        <b>${(sku || '').toUpperCase()}</b>
      </td>

      <td>${(name || '').toUpperCase()}</td>
      <td><span class="pill">${businessGroup||'-'}</span></td>
      <td><span class="pill">${category||'-'}</span></td>

      <td class="num">
        <input class="form-control form-control-sm text-end calc"
               type="number"
               step="0.01"
               name="qty_display[]"
               value="${qty}"
               readonly>
         <input type="hidden" name="qty[]" value="${qty}">
      </td>

      <td>
        <input class="form-control form-control-sm"
               name="unit_display[]"
               value="${unit}"
               readonly>
         <input type="hidden" name="unit[]" value="${unit}">
      </td>

      <td class="num">
        ${CAN_PRICE
          ? `<input class="form-control form-control-sm text-end calc price"
                    type="number"
                    step="0.000001"
                    min="0"
                    inputmode="decimal"
                    name="unit_price[]"
                    value="${price}"
                    ${CAN_CREATE ? '' : 'disabled'}>`
          : `<span class="muted">Restricted</span>
             <input type="hidden" name="unit_price[]" value="0">`
        }
      </td>

      <td class="num">
        <input class="form-control form-control-sm text-end calc"
               type="number"
               step="0.01"
               name="discount_percent[]"
               value="${discount}"
               ${CAN_CREATE ? '' : 'disabled'}>
      </td>

      <td class="num">
        <input class="form-control form-control-sm text-end calc"
               type="number"
               step="0.01"
               name="ppn_percent[]"
               value="${ppn}"
               ${CAN_CREATE ? '' : 'disabled'}>
      </td>

      <td class="num subtotal">
        ${total.toLocaleString('id-ID', {maximumFractionDigits:2})}
      </td>

      <td><span class="muted">LOCK</span></td>
    </tr>
  `;
}
function recalcTotal(){
  let total = 0;

  document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
    const q = parseFloat(tr.querySelector("input[name='qty[]']")?.value || '0');
    const p = CAN_PRICE ? parseFloat(tr.querySelector("input[name='unit_price[]']")?.value || '0') : 0;
    const d = parseFloat(tr.querySelector("input[name='discount_percent[]']")?.value || '0');
    const ppn = parseFloat(tr.querySelector("input[name='ppn_percent[]']")?.value || '11');

    const gross = q * p;
    const net = gross - (gross * d / 100);
    const rowTotal = net + (net * ppn / 100);

    const sub = tr.querySelector('.subtotal');
    if (sub) {
      sub.textContent = rowTotal.toLocaleString('id-ID', {maximumFractionDigits:2});
    }

    total += rowTotal;
  });

  document.getElementById('totalPreview').textContent =
    total.toLocaleString('id-ID', {maximumFractionDigits:2});
}

function bindPriceInputs(){
  document.querySelectorAll('#itemsTable tbody .calc').forEach(el => {
    el.addEventListener('input', recalcTotal);
  });
}

async function loadPR(pr_id){
  const tbody = document.querySelector('#itemsTable tbody');
  tbody.innerHTML = `<tr><td colspan="11" class="muted">Loading...</td></tr>`;
  try{
    const res = await fetch(`purchases_pr_api.php?pr_id=${encodeURIComponent(pr_id)}`);
    const data = await res.json();
    if (!data.ok){
      tbody.innerHTML = `<tr><td colspan="11" class="muted">${data.message||'Gagal load PR'}</td></tr>`;
      return;
    }
    const selectedOption=document.querySelector('#pr_id option:checked');
    const headerOffice=(data.header&&data.header.office_code)?data.header.office_code:(selectedOption?.dataset?.office||'');
    const headerCategory=((data.header&&data.header.category)?data.header.category:(selectedOption?.dataset?.category||'')).toUpperCase();
    const headerBusinessGroup=((data.header&&data.header.business_group)?data.header.business_group:(selectedOption?.dataset?.businessGroup||'')).toUpperCase();
    if(headerOffice)document.getElementById('office_code').value=headerOffice;
    tbody.innerHTML='';
    if(!data.items||data.items.length===0){tbody.innerHTML=`<tr><td colspan="11" class="muted">PR tidak ada item.</td></tr>`;return;}
    data.items.forEach(it=>{it.category=(it.category||((headerBusinessGroup==='BMHP')?'BMHP':'')).toUpperCase(); it.business_group=(it.business_group||headerBusinessGroup||(it.category==='BMHP'?'BMHP':'UNIT_ACC')).toUpperCase(); tbody.insertAdjacentHTML('beforeend',rowHtml(it));});
    bindPriceInputs();
    recalcTotal();
  }catch(e){
    tbody.innerHTML = `<tr><td colspan="11" class="muted">Error: ${e}</td></tr>`;
  }
}

document.getElementById('pr_id').addEventListener('change', (e)=>{
  const pr_id = e.target.value;
  if (!pr_id) return;
  loadPR(pr_id);
});

// Auto-load PR items when pre-selected via URL (?pr_id=)
(function(){
  const sel = document.getElementById('pr_id');
  if (sel && sel.value) loadPR(sel.value);
})();

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
  new DataTable('#poTable', {
    pageLength: 25,
    dom: 'Bfrtip',
    order: [[0, 'desc']],
    columnDefs: [
      { targets: 0, visible: false, searchable: false }
    ],
    buttons: ['copy','csv','excel','pdf','print']
  });
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
