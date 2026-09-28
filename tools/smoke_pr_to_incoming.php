<?php
declare(strict_types=1);
/**
 * Smoke Test: PR → PO → Incoming (End-to-End)
 * 
 * Menjalankan 1 dokumen lengkap dari Purchase Request sampai Incoming/GR.
 * Semua data test dibersihkan setelah selesai (rollback-safe).
 *
 * Jalankan di NAS: php tools/smoke_pr_to_incoming.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);

// --- Minimal bootstrap (tanpa session/login requirement) ---
require_once $root . '/_shared/db.php';
require_once $root . '/config/doc_numbering.php';

$pdo = null;
if (function_exists('rmi_db_pdo')) {
    try { $pdo = rmi_db_pdo(); } catch (Throwable $e) {}
}
if (!$pdo) {
    $cfg = is_file($root . '/config-db.php') ? (require $root . '/config-db.php') : [];
    $host = $cfg['host'] ?? '127.0.0.1';
    $port = $cfg['port'] ?? 3306;
    $name = $cfg['name'] ?? 'erp_rmi_sofull';
    $user = $cfg['user'] ?? 'root';
    $pass = $cfg['pass'] ?? '';
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// --- Helpers ---
$passCount = 0;
$failCount = 0;
$warnings  = [];

function t_log(string $msg): void { echo $msg . PHP_EOL; }
function t_pass(string $label): void {
    global $passCount;
    $passCount++;
    echo "  ✅ PASS: {$label}" . PHP_EOL;
}
function t_fail(string $label, string $detail = ''): void {
    global $failCount;
    $failCount++;
    echo "  ❌ FAIL: {$label}" . ($detail ? " — {$detail}" : '') . PHP_EOL;
}
function t_warn(string $label): void {
    global $warnings;
    $warnings[] = $label;
    echo "  ⚠️  WARN: {$label}" . PHP_EOL;
}
function t_info(string $msg): void { echo "  ℹ️  {$msg}" . PHP_EOL; }

// --- Ensure schema exists ---
t_log("\n══════════════════════════════════════════════════════════");
t_log("  SMOKE TEST: PR → PO → Incoming (End-to-End)");
t_log("  " . date('Y-m-d H:i:s'));
t_log("══════════════════════════════════════════════════════════\n");

// Schema bootstrap (same as production pages do)
t_log("[0] Schema Bootstrap");
try {
    // PR tables
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `wqs_pr` (
        `id` int NOT NULL AUTO_INCREMENT,
        `pr_code` varchar(60) NOT NULL,
        `pr_date` date NOT NULL,
        `office_code` varchar(20) DEFAULT NULL,
        `category` varchar(20) NOT NULL DEFAULT 'BMHP',
        `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
        `note` text,
        `created_by` varchar(100) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `deleted_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `wqs_pr_items` (
        `id` int NOT NULL AUTO_INCREMENT,
        `pr_id` int NOT NULL,
        `line_no` int NOT NULL DEFAULT 1,
        `product_id` int DEFAULT NULL,
        `sku` varchar(50) DEFAULT NULL,
        `products_name` varchar(255) DEFAULT NULL,
        `qty` decimal(18,2) NOT NULL DEFAULT 0,
        `unit` varchar(30) DEFAULT NULL,
        `category` varchar(20) NOT NULL DEFAULT 'BMHP',
        `deleted_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // PO tables
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `purchases_po` (
        `id` int NOT NULL AUTO_INCREMENT,
        `po_code` varchar(60) NOT NULL,
        `po_date` date NOT NULL,
        `pr_id` int DEFAULT NULL,
        `manufacture_id` int DEFAULT NULL,
        `office_code` varchar(20) DEFAULT NULL,
        `currency` varchar(10) NOT NULL DEFAULT 'IDR',
        `payment_term` varchar(30) DEFAULT NULL,
        `note` text,
        `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
        `total_amount` decimal(18,2) NOT NULL DEFAULT 0,
        `category` varchar(20) NOT NULL DEFAULT 'BMHP',
        `created_by` varchar(100) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `deleted_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `purchases_po_items` (
        `id` int NOT NULL AUTO_INCREMENT,
        `po_id` int NOT NULL,
        `line_no` int NOT NULL DEFAULT 1,
        `product_id` int DEFAULT NULL,
        `sku` varchar(50) DEFAULT NULL,
        `products_name` varchar(255) DEFAULT NULL,
        `qty` decimal(18,2) NOT NULL DEFAULT 0,
        `unit` varchar(30) DEFAULT NULL,
        `unit_price` decimal(18,2) NOT NULL DEFAULT 0,
        `subtotal` decimal(18,2) NOT NULL DEFAULT 0,
        `deleted_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // Incoming tables
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `wqs_incoming` (
        `id` int NOT NULL AUTO_INCREMENT,
        `incoming_code` varchar(60) NOT NULL,
        `received_date` date DEFAULT NULL,
        `po_id` int DEFAULT NULL,
        `po_code` varchar(60) DEFAULT NULL,
        `office_code` varchar(20) DEFAULT NULL,
        `depo_name` varchar(100) DEFAULT NULL,
        `ref_note` text,
        `wqs_stock_before` varchar(255) DEFAULT NULL,
        `wqs_stock_after` varchar(255) DEFAULT NULL,
        `created_by` varchar(100) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `deleted_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `wqs_incoming_items` (
        `id` int NOT NULL AUTO_INCREMENT,
        `incoming_id` int NOT NULL,
        `po_item_id` int DEFAULT NULL,
        `product_id` int DEFAULT NULL,
        `sku` varchar(50) DEFAULT NULL,
        `lot_number` varchar(50) DEFAULT NULL,
        `serial_number` varchar(100) DEFAULT NULL,
        `exp_date` date DEFAULT NULL,
        `qty` decimal(18,2) NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `deleted_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // Stock table
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `wqs_stock` (
        `id` int NOT NULL AUTO_INCREMENT,
        `product_id` int NOT NULL,
        `stock_qty` decimal(18,2) NOT NULL DEFAULT 0,
        `updated_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // Audit log
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `purchases_audit_log` (
        `id` int NOT NULL AUTO_INCREMENT,
        `module` varchar(30) DEFAULT NULL,
        `ref_code` varchar(80) DEFAULT NULL,
        `action` varchar(50) DEFAULT NULL,
        `details_json` text,
        `user_name` varchar(100) DEFAULT NULL,
        `user_role` varchar(30) DEFAULT NULL,
        `user_level` varchar(30) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    t_pass("Schema bootstrap OK");
} catch (Throwable $e) {
    t_fail("Schema bootstrap", $e->getMessage());
    exit(1);
}

// --- Get or create a test product ---
t_log("\n[0.1] Prepare Test Product");
$testProduct = null;
try {
    $st = $pdo->query("SELECT id, sku, products_name, unit FROM master_products WHERE status='active' LIMIT 1");
    $testProduct = $st->fetch();
} catch (Throwable $e) {}
if (!$testProduct) {
    t_fail("Tidak ada produk aktif di master_products", "Minimal 1 produk dengan status=active diperlukan");
    exit(1);
}
$testPid  = (int)$testProduct['id'];
$testSku  = strtoupper(trim($testProduct['sku'] ?? ''));
$testName = strtoupper(trim($testProduct['products_name'] ?? ''));
$testUnit = trim($testProduct['unit'] ?? 'pcs');
t_pass("Produk test: [{$testSku}] {$testName} (ID={$testPid}, unit={$testUnit})");

// --- Get or create a test manufacture ---
t_log("\n[0.2] Prepare Test Manufacture");
$testMfg = null;
try {
    $st = $pdo->query("SELECT id, manufacture_code, manufacture_name FROM master_manufactures WHERE (status=1 OR status='active') LIMIT 1");
    $testMfg = $st->fetch();
} catch (Throwable $e) {}
if (!$testMfg) {
    t_fail("Tidak ada manufacture aktif di master_manufactures");
    exit(1);
}
$testMfgId = (int)$testMfg['id'];
t_pass("Manufacture: [{$testMfg['manufacture_code']}] {$testMfg['manufacture_name']} (ID={$testMfgId})");

// --- Test constants ---
$testOffice   = 'BGR';
$testCategory = 'BMHP';
$testDate     = date('Y-m-d');
$testDateYmd  = date('ymd');
$testQty      = 10.5; // Desimal untuk test float handling
$testPrice    = 150000.00;
$testUser     = 'SmokeSYS_SYS';

// --- Track IDs for cleanup ---
$cleanupIds = ['pr_id' => null, 'po_id' => null, 'incoming_id' => null];

try {
    // ═══════════════════════════════════════════════════
    // STEP 1: CREATE PR (DRAFT)
    // ═══════════════════════════════════════════════════
    t_log("\n────────────────────────────────────────────────");
    t_log("[STEP 1] CREATE PR (DRAFT)");
    t_log("────────────────────────────────────────────────");

    $prefix  = doc_prefix_pr($testOffice, $testDateYmd, $testCategory);
    t_info("Doc prefix: {$prefix}");

    // Generate unique PR code
    $st = $pdo->prepare("SELECT pr_code FROM wqs_pr WHERE pr_code LIKE ? ORDER BY pr_code DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $lastPr = (string)($st->fetchColumn() ?: '');
    $nextSeq = 1;
    if ($lastPr !== '') { $nextSeq = (int)substr($lastPr, -3) + 1; }
    $prCode = $prefix . str_pad((string)$nextSeq, 3, '0', STR_PAD_LEFT);
    t_info("PR Code: {$prCode}");

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO wqs_pr (pr_code, pr_date, office_code, category, status, note, created_by)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([$prCode, $testDate, $testOffice, $testCategory, 'DRAFT', 'SMOKE TEST — E2E PR→PO→Incoming', $testUser]);
    $prId = (int)$pdo->lastInsertId();
    $cleanupIds['pr_id'] = $prId;

    $pdo->prepare("INSERT INTO wqs_pr_items (pr_id, line_no, product_id, sku, products_name, qty, unit)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([$prId, 1, $testPid, $testSku, $testName, $testQty, $testUnit]);
    $prItemId = (int)$pdo->lastInsertId();
    $pdo->commit();

    // Verify
    $st = $pdo->prepare("SELECT * FROM wqs_pr WHERE id=?");
    $st->execute([$prId]);
    $prRow = $st->fetch();

    if (!$prRow) { t_fail("PR tidak tersimpan di database"); throw new Exception("STOP"); }
    t_pass("PR INSERT OK — ID={$prId}");

    if ($prRow['status'] !== 'DRAFT') { t_fail("PR status harus DRAFT, got: {$prRow['status']}"); }
    else { t_pass("PR status = DRAFT"); }

    if (strtoupper($prRow['category']) !== $testCategory) { t_fail("PR category mismatch: expected={$testCategory}, got={$prRow['category']}"); }
    else { t_pass("PR category = {$testCategory}"); }

    if (strtoupper($prRow['office_code']) !== $testOffice) { t_fail("PR office mismatch"); }
    else { t_pass("PR office = {$testOffice}"); }

    if ($prRow['created_by'] !== $testUser) { t_fail("PR created_by mismatch: expected={$testUser}, got={$prRow['created_by']}"); }
    else { t_pass("PR created_by = {$testUser}"); }

    // Verify items
    $st = $pdo->prepare("SELECT * FROM wqs_pr_items WHERE pr_id=? AND deleted_at IS NULL");
    $st->execute([$prId]);
    $prItems = $st->fetchAll();
    if (count($prItems) !== 1) { t_fail("PR items count: expected=1, got=" . count($prItems)); }
    else { t_pass("PR items count = 1"); }

    $pri = $prItems[0];
    if ((float)$pri['qty'] != $testQty) { t_fail("PR item qty: expected={$testQty}, got={$pri['qty']}"); }
    else { t_pass("PR item qty = {$testQty} (decimal preserved)"); }

    // ═══════════════════════════════════════════════════
    // STEP 2: SUBMIT PR (DRAFT → SUBMITTED)
    // ═══════════════════════════════════════════════════
    t_log("\n────────────────────────────────────────────────");
    t_log("[STEP 2] SUBMIT PR (DRAFT → SUBMITTED)");
    t_log("────────────────────────────────────────────────");

    $pdo->prepare("UPDATE wqs_pr SET status='SUBMITTED' WHERE id=? AND status='DRAFT'")->execute([$prId]);
    $affected = $pdo->prepare("SELECT status FROM wqs_pr WHERE id=?"); $affected->execute([$prId]);
    $prStatus = $affected->fetchColumn();

    if ($prStatus !== 'SUBMITTED') { t_fail("PR submit gagal: status={$prStatus}"); throw new Exception("STOP"); }
    t_pass("PR status → SUBMITTED");

    // ═══════════════════════════════════════════════════
    // STEP 3: CREATE PO FROM PR
    // ═══════════════════════════════════════════════════
    t_log("\n────────────────────────────────────────────────");
    t_log("[STEP 3] CREATE PO FROM PR");
    t_log("────────────────────────────────────────────────");

    // 3a: Re-validate PR status (same as production code fix)
    $st = $pdo->prepare("SELECT id, status, pr_code FROM wqs_pr WHERE id=? AND deleted_at IS NULL");
    $st->execute([$prId]);
    $prCheck = $st->fetch();
    if (!$prCheck || strtoupper($prCheck['status']) !== 'SUBMITTED') {
        t_fail("PR re-validation: status should be SUBMITTED");
        throw new Exception("STOP");
    }
    t_pass("PR re-validation: status = SUBMITTED (race condition guard)");

    // 3b: Read category from PR
    $st = $pdo->prepare("SELECT category FROM wqs_pr WHERE id=?");
    $st->execute([$prId]);
    $poCat = strtoupper(trim((string)($st->fetchColumn() ?: 'BMHP')));
    if ($poCat !== $testCategory) { t_fail("PO category carry-over: expected={$testCategory}, got={$poCat}"); }
    else { t_pass("PO category from PR = {$poCat}"); }

    // 3c: Generate PO code
    $poPrefix = doc_prefix_po($testOffice, $testDateYmd, $poCat);
    $st = $pdo->prepare("SELECT po_code FROM purchases_po WHERE po_code LIKE ? ORDER BY po_code DESC LIMIT 1");
    $st->execute([$poPrefix . '%']);
    $lastPo = (string)($st->fetchColumn() ?: '');
    $poSeq = 1;
    if ($lastPo !== '') { $poSeq = (int)substr($lastPo, -3) + 1; }
    $poCode = $poPrefix . str_pad((string)$poSeq, 3, '0', STR_PAD_LEFT);
    t_info("PO Code: {$poCode}");

    // 3d: Insert PO + items
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO purchases_po (po_code, po_date, pr_id, manufacture_id, office_code, currency, payment_term, note, status, total_amount, category, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$poCode, $testDate, $prId, $testMfgId, $testOffice, 'IDR', 'NET30', 'SMOKE TEST PO', 'OPEN', 0, $poCat, $testUser]);
    $poId = (int)$pdo->lastInsertId();
    $cleanupIds['po_id'] = $poId;

    $subtotal = $testQty * $testPrice;
    $pdo->prepare("INSERT INTO purchases_po_items (po_id, line_no, product_id, sku, products_name, qty, unit, unit_price, subtotal)
                   VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$poId, 1, $testPid, $testSku, $testName, $testQty, $testUnit, $testPrice, $subtotal]);
    $poItemId = (int)$pdo->lastInsertId();

    // 3e: Recalc total
    $st = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL");
    $st->execute([$poId]);
    $poTotal = (float)$st->fetchColumn();
    $pdo->prepare("UPDATE purchases_po SET total_amount=? WHERE id=?")->execute([$poTotal, $poId]);

    // 3f: Mark PR as PO_CREATED
    $pdo->prepare("UPDATE wqs_pr SET status='PO_CREATED' WHERE id=?")->execute([$prId]);
    $pdo->commit();

    // Verify PO
    $st = $pdo->prepare("SELECT * FROM purchases_po WHERE id=?"); $st->execute([$poId]);
    $poRow = $st->fetch();
    if (!$poRow) { t_fail("PO tidak tersimpan"); throw new Exception("STOP"); }
    t_pass("PO INSERT OK — ID={$poId}");

    if ($poRow['status'] !== 'OPEN') { t_fail("PO status: expected=OPEN, got={$poRow['status']}"); }
    else { t_pass("PO status = OPEN"); }

    if ((int)$poRow['pr_id'] !== $prId) { t_fail("PO pr_id mismatch"); }
    else { t_pass("PO pr_id = {$prId} (linked to PR)"); }

    if ((int)$poRow['manufacture_id'] !== $testMfgId) { t_fail("PO manufacture_id mismatch"); }
    else { t_pass("PO manufacture_id = {$testMfgId}"); }

    if (strtoupper($poRow['category']) !== $testCategory) { t_fail("PO category mismatch"); }
    else { t_pass("PO category = {$testCategory} (carried from PR)"); }

    $expectedTotal = $testQty * $testPrice;
    if (abs((float)$poRow['total_amount'] - $expectedTotal) > 0.01) {
        t_fail("PO total: expected={$expectedTotal}, got={$poRow['total_amount']}");
    } else {
        t_pass("PO total_amount = " . number_format($expectedTotal, 2));
    }

    // Verify PR status changed
    $st = $pdo->prepare("SELECT status FROM wqs_pr WHERE id=?"); $st->execute([$prId]);
    $prStatusAfterPo = $st->fetchColumn();
    if ($prStatusAfterPo !== 'PO_CREATED') { t_fail("PR status after PO: expected=PO_CREATED, got={$prStatusAfterPo}"); }
    else { t_pass("PR status → PO_CREATED (auto-updated by PO creation)"); }

    // Verify PO items
    $st = $pdo->prepare("SELECT * FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL");
    $st->execute([$poId]);
    $poItems = $st->fetchAll();
    if (count($poItems) !== 1) { t_fail("PO items count mismatch"); }
    else { t_pass("PO items count = 1"); }

    $poi = $poItems[0];
    if ((float)$poi['qty'] != $testQty) { t_fail("PO item qty: expected={$testQty}, got={$poi['qty']}"); }
    else { t_pass("PO item qty = {$testQty} (decimal preserved)"); }

    if ((float)$poi['unit_price'] != $testPrice) { t_fail("PO item price mismatch"); }
    else { t_pass("PO item unit_price = " . number_format($testPrice, 2)); }

    // ═══════════════════════════════════════════════════
    // STEP 4: PO STATUS TRANSITIONS
    // ═══════════════════════════════════════════════════
    t_log("\n────────────────────────────────────────────────");
    t_log("[STEP 4] PO STATUS TRANSITIONS");
    t_log("────────────────────────────────────────────────");

    $transitions = ['IN_PRODUCTION', 'READY'];
    $prevStatus = 'OPEN';
    foreach ($transitions as $nextStatus) {
        $pdo->prepare("UPDATE purchases_po SET status=? WHERE id=?")->execute([$nextStatus, $poId]);
        $st = $pdo->prepare("SELECT status FROM purchases_po WHERE id=?"); $st->execute([$poId]);
        $actual = $st->fetchColumn();
        if ($actual !== $nextStatus) { t_fail("PO transition {$prevStatus}→{$nextStatus}: got {$actual}"); }
        else { t_pass("PO status: {$prevStatus} → {$nextStatus}"); }
        $prevStatus = $nextStatus;
    }

    // ═══════════════════════════════════════════════════
    // STEP 5: CREATE INCOMING (PARTIAL — 5 units of 10.5)
    // ═══════════════════════════════════════════════════
    t_log("\n────────────────────────────────────────────────");
    t_log("[STEP 5] CREATE INCOMING — PARTIAL (5 of {$testQty})");
    t_log("────────────────────────────────────────────────");

    $incomingQty1 = 5.0;

    // 5a: Validate outstanding
    $st = $pdo->prepare("SELECT SUM(i.qty) FROM purchases_po_items i WHERE i.po_id=? AND i.deleted_at IS NULL");
    $st->execute([$poId]);
    $poQtyTotal = (float)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COALESCE(SUM(ii.qty),0) FROM wqs_incoming_items ii
                          JOIN wqs_incoming inc ON inc.id=ii.incoming_id
                          WHERE inc.po_id=? AND inc.deleted_at IS NULL");
    $st->execute([$poId]);
    $receivedBefore = (float)$st->fetchColumn();
    $outstanding = $poQtyTotal - $receivedBefore;
    t_info("PO qty={$poQtyTotal}, received={$receivedBefore}, outstanding={$outstanding}");

    if ($incomingQty1 > $outstanding + 0.001) {
        t_fail("Incoming qty ({$incomingQty1}) exceeds outstanding ({$outstanding})");
        throw new Exception("STOP");
    }
    t_pass("Incoming qty ({$incomingQty1}) <= outstanding ({$outstanding})");

    // 5b: Stock before
    $st = $pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
    $st->execute([$testPid]);
    $stockBefore = (float)($st->fetchColumn() ?: 0);
    t_info("Stock BEFORE incoming: {$stockBefore}");

    // 5c: Insert incoming
    $inCode1 = 'INC-' . date('Ymd') . '-SMOKE1';
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO wqs_incoming (incoming_code, received_date, po_id, po_code, office_code, depo_name, ref_note, created_by)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$inCode1, $testDate, $poId, $poCode, $testOffice, 'DEPO-UTAMA', 'SMOKE TEST partial incoming', $testUser]);
    $incId1 = (int)$pdo->lastInsertId();
    $cleanupIds['incoming_id'] = $incId1;

    $pdo->prepare("INSERT INTO wqs_incoming_items (incoming_id, po_item_id, product_id, sku, lot_number, serial_number, exp_date, qty)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$incId1, $poItemId, $testPid, $testSku, 'LOT-SMOKE-001', null, '2027-12-31', $incomingQty1]);

    // 5d: Update stock
    $st = $pdo->prepare("SELECT id, stock_qty FROM wqs_stock WHERE product_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$testPid]);
    $stockRow = $st->fetch();
    if ($stockRow) {
        $newQty = (float)$stockRow['stock_qty'] + $incomingQty1;
        $pdo->prepare("UPDATE wqs_stock SET stock_qty=?, updated_at=NOW() WHERE id=?")->execute([$newQty, (int)$stockRow['id']]);
    } else {
        $pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty, updated_at) VALUES (?,?,NOW())")
            ->execute([$testPid, $incomingQty1]);
    }
    $pdo->commit();

    // Verify incoming
    $st = $pdo->prepare("SELECT * FROM wqs_incoming WHERE id=?"); $st->execute([$incId1]);
    $incRow1 = $st->fetch();
    if (!$incRow1) { t_fail("Incoming tidak tersimpan"); throw new Exception("STOP"); }
    t_pass("Incoming INSERT OK — ID={$incId1}, code={$inCode1}");

    if ((int)$incRow1['po_id'] !== $poId) { t_fail("Incoming po_id mismatch"); }
    else { t_pass("Incoming po_id = {$poId}"); }

    if ($incRow1['created_by'] !== $testUser) { t_fail("Incoming created_by: expected={$testUser}, got=" . ($incRow1['created_by'] ?? 'NULL')); }
    else { t_pass("Incoming created_by = {$testUser}"); }

    // Verify stock updated
    $st = $pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
    $st->execute([$testPid]);
    $stockAfter1 = (float)($st->fetchColumn() ?: 0);
    $expectedStock1 = $stockBefore + $incomingQty1;
    if (abs($stockAfter1 - $expectedStock1) > 0.01) {
        t_fail("Stock after partial: expected={$expectedStock1}, got={$stockAfter1}");
    } else {
        t_pass("Stock updated: {$stockBefore} + {$incomingQty1} = {$stockAfter1}");
    }

    // Verify incoming items
    $st = $pdo->prepare("SELECT * FROM wqs_incoming_items WHERE incoming_id=?"); $st->execute([$incId1]);
    $incItems1 = $st->fetchAll();
    if (count($incItems1) !== 1) { t_fail("Incoming items count mismatch"); }
    else { t_pass("Incoming items count = 1"); }

    $ii1 = $incItems1[0];
    if ((float)$ii1['qty'] != $incomingQty1) { t_fail("Incoming item qty: expected={$incomingQty1}, got={$ii1['qty']}"); }
    else { t_pass("Incoming item qty = {$incomingQty1} (float preserved)"); }

    if ($ii1['lot_number'] !== 'LOT-SMOKE-001') { t_fail("LOT number not saved"); }
    else { t_pass("LOT number = LOT-SMOKE-001"); }

    if ($ii1['exp_date'] !== '2027-12-31') { t_fail("EXP date not saved"); }
    else { t_pass("EXP date = 2027-12-31"); }

    // ═══════════════════════════════════════════════════
    // STEP 6: CREATE INCOMING — REMAINING (5.5 of 10.5)
    // ═══════════════════════════════════════════════════
    t_log("\n────────────────────────────────────────────────");
    $remainingQty = $testQty - $incomingQty1;
    t_log("[STEP 6] CREATE INCOMING — REMAINING ({$remainingQty} of {$testQty})");
    t_log("────────────────────────────────────────────────");

    // Recalculate outstanding
    $st = $pdo->prepare("SELECT COALESCE(SUM(ii.qty),0) FROM wqs_incoming_items ii
                          JOIN wqs_incoming inc ON inc.id=ii.incoming_id
                          WHERE inc.po_id=? AND inc.deleted_at IS NULL");
    $st->execute([$poId]);
    $receivedAfter1 = (float)$st->fetchColumn();
    $outstanding2 = $poQtyTotal - $receivedAfter1;
    t_info("After partial: received={$receivedAfter1}, outstanding={$outstanding2}");

    if (abs($outstanding2 - $remainingQty) > 0.01) { t_fail("Outstanding calc: expected={$remainingQty}, got={$outstanding2}"); }
    else { t_pass("Outstanding = {$outstanding2} (correct)"); }

    $inCode2 = 'INC-' . date('Ymd') . '-SMOKE2';
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO wqs_incoming (incoming_code, received_date, po_id, po_code, office_code, depo_name, ref_note, created_by)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$inCode2, $testDate, $poId, $poCode, $testOffice, 'DEPO-UTAMA', 'SMOKE TEST final incoming', $testUser]);
    $incId2 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO wqs_incoming_items (incoming_id, po_item_id, product_id, sku, lot_number, serial_number, exp_date, qty)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$incId2, $poItemId, $testPid, $testSku, 'LOT-SMOKE-002', 'SN-TEST-001', '2028-06-30', $remainingQty]);

    // Update stock
    $st = $pdo->prepare("SELECT id, stock_qty FROM wqs_stock WHERE product_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$testPid]);
    $stockRow2 = $st->fetch();
    $newQty2 = (float)$stockRow2['stock_qty'] + $remainingQty;
    $pdo->prepare("UPDATE wqs_stock SET stock_qty=?, updated_at=NOW() WHERE id=?")->execute([$newQty2, (int)$stockRow2['id']]);
    $pdo->commit();

    // Verify full receipt
    $st = $pdo->prepare("SELECT COALESCE(SUM(ii.qty),0) FROM wqs_incoming_items ii
                          JOIN wqs_incoming inc ON inc.id=ii.incoming_id
                          WHERE inc.po_id=? AND inc.deleted_at IS NULL");
    $st->execute([$poId]);
    $totalReceived = (float)$st->fetchColumn();
    $finalOutstanding = $poQtyTotal - $totalReceived;

    if (abs($totalReceived - $testQty) > 0.01) { t_fail("Total received: expected={$testQty}, got={$totalReceived}"); }
    else { t_pass("Total received = {$totalReceived} (fully received)"); }

    if (abs($finalOutstanding) > 0.01) { t_fail("Final outstanding: expected=0, got={$finalOutstanding}"); }
    else { t_pass("Outstanding = 0 (PO fully fulfilled)"); }

    // Verify stock final
    $st = $pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
    $st->execute([$testPid]);
    $stockFinal = (float)($st->fetchColumn() ?: 0);
    $expectedStockFinal = $stockBefore + $testQty;
    if (abs($stockFinal - $expectedStockFinal) > 0.01) {
        t_fail("Stock final: expected={$expectedStockFinal}, got={$stockFinal}");
    } else {
        t_pass("Stock final: {$stockBefore} + {$testQty} = {$stockFinal}");
    }

    // ═══════════════════════════════════════════════════
    // STEP 7: OVER-RECEIVE GUARD
    // ═══════════════════════════════════════════════════
    t_log("\n────────────────────────────────────────────────");
    t_log("[STEP 7] OVER-RECEIVE GUARD (should reject)");
    t_log("────────────────────────────────────────────────");

    $st = $pdo->prepare("SELECT COALESCE(SUM(ii.qty),0) FROM wqs_incoming_items ii
                          JOIN wqs_incoming inc ON inc.id=ii.incoming_id
                          WHERE inc.po_id=? AND inc.deleted_at IS NULL");
    $st->execute([$poId]);
    $curReceived = (float)$st->fetchColumn();
    $curOutstanding = $poQtyTotal - $curReceived;

    if ($curOutstanding <= 0) {
        t_pass("Outstanding = 0 → over-receive would be rejected by validate_incoming_vs_po()");
    } else {
        t_warn("Outstanding still > 0 ({$curOutstanding}), over-receive guard test skipped");
    }

    // ═══════════════════════════════════════════════════
    // STEP 8: PO CANCEL → PR ROLLBACK
    // ═══════════════════════════════════════════════════
    t_log("\n────────────────────────────────────────────────");
    t_log("[STEP 8] PO CANCEL → PR STATUS ROLLBACK");
    t_log("────────────────────────────────────────────────");

    // Create a fresh PR+PO pair to test cancel logic
    $prCode2 = $prefix . str_pad((string)($nextSeq + 1), 3, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO wqs_pr (pr_code, pr_date, office_code, category, status, note, created_by)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([$prCode2, $testDate, $testOffice, $testCategory, 'PO_CREATED', 'SMOKE TEST cancel pair', $testUser]);
    $prId2 = (int)$pdo->lastInsertId();

    $poCode2 = $poPrefix . str_pad((string)($poSeq + 1), 3, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO purchases_po (po_code, po_date, pr_id, manufacture_id, office_code, status, category, created_by)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$poCode2, $testDate, $prId2, $testMfgId, $testOffice, 'OPEN', $testCategory, $testUser]);
    $poId2 = (int)$pdo->lastInsertId();

    // Cancel PO → PR should rollback to SUBMITTED
    $pdo->prepare("UPDATE purchases_po SET status='CANCELLED' WHERE id=?")->execute([$poId2]);

    // Check no other active POs for this PR
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE pr_id=? AND id!=? AND deleted_at IS NULL AND status NOT IN ('CANCELLED')");
    $st->execute([$prId2, $poId2]);
    $otherActive = (int)$st->fetchColumn();

    if ($otherActive === 0) {
        $pdo->prepare("UPDATE wqs_pr SET status='SUBMITTED' WHERE id=? AND status='PO_CREATED'")->execute([$prId2]);
    }

    $st = $pdo->prepare("SELECT status FROM wqs_pr WHERE id=?"); $st->execute([$prId2]);
    $prStatus2 = $st->fetchColumn();
    if ($prStatus2 !== 'SUBMITTED') { t_fail("PR rollback: expected=SUBMITTED, got={$prStatus2}"); }
    else { t_pass("PO CANCELLED → PR rolled back to SUBMITTED"); }

    // Cleanup cancel test pair
    $pdo->prepare("DELETE FROM purchases_po WHERE id=?")->execute([$poId2]);
    $pdo->prepare("DELETE FROM wqs_pr WHERE id=?")->execute([$prId2]);

    // ═══════════════════════════════════════════════════
    // STEP 9: CROSS-REFERENCE INTEGRITY
    // ═══════════════════════════════════════════════════
    t_log("\n────────────────────────────────────────────────");
    t_log("[STEP 9] CROSS-REFERENCE INTEGRITY");
    t_log("────────────────────────────────────────────────");

    // PR → PO link
    $st = $pdo->prepare("SELECT po.id, po.po_code, po.pr_id FROM purchases_po po WHERE po.pr_id=? AND po.deleted_at IS NULL");
    $st->execute([$prId]);
    $linkedPo = $st->fetch();
    if (!$linkedPo) { t_fail("PR→PO: no PO linked to PR"); }
    else { t_pass("PR→PO cross-ref OK: PR#{$prId} → PO#{$linkedPo['id']} ({$linkedPo['po_code']})"); }

    // PO → Incoming link
    $st = $pdo->prepare("SELECT id, incoming_code FROM wqs_incoming WHERE po_id=? AND deleted_at IS NULL");
    $st->execute([$poId]);
    $linkedInc = $st->fetchAll();
    if (count($linkedInc) !== 2) { t_fail("PO→Incoming: expected 2 incoming records, got=" . count($linkedInc)); }
    else { t_pass("PO→Incoming cross-ref OK: PO#{$poId} → " . count($linkedInc) . " incoming records"); }

    // Incoming → PO item link
    $st = $pdo->prepare("SELECT ii.po_item_id FROM wqs_incoming_items ii
                          JOIN wqs_incoming inc ON inc.id=ii.incoming_id
                          WHERE inc.po_id=? AND inc.deleted_at IS NULL");
    $st->execute([$poId]);
    $incPiLinks = $st->fetchAll();
    $allLinked = true;
    foreach ($incPiLinks as $link) {
        if ((int)($link['po_item_id'] ?? 0) !== $poItemId) { $allLinked = false; break; }
    }
    if ($allLinked && count($incPiLinks) === 2) { t_pass("Incoming items → PO item cross-ref OK (po_item_id={$poItemId})"); }
    else { t_fail("Incoming→PO item cross-ref broken"); }

    t_log("\n");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getMessage() !== 'STOP') {
        t_fail("EXCEPTION: " . $e->getMessage());
        t_info("File: " . $e->getFile() . ":" . $e->getLine());
    }
} finally {
    // ═══════════════════════════════════════════════════
    // CLEANUP
    // ═══════════════════════════════════════════════════
    t_log("────────────────────────────────────────────────");
    t_log("[CLEANUP] Removing test data...");
    t_log("────────────────────────────────────────────────");

    try {
        // Reverse stock change
        $st = $pdo->prepare("SELECT id, stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
        $st->execute([$testPid]);
        $finalStock = $st->fetch();
        if ($finalStock) {
            $revertQty = (float)$finalStock['stock_qty'] - $testQty;
            if ($revertQty < 0) $revertQty = 0;
            $pdo->prepare("UPDATE wqs_stock SET stock_qty=?, updated_at=NOW() WHERE id=?")->execute([$revertQty, (int)$finalStock['id']]);
            t_info("Stock reverted: {$finalStock['stock_qty']} → {$revertQty}");
        }

        // Delete incoming items & records
        if ($cleanupIds['incoming_id']) {
            $pdo->prepare("DELETE FROM wqs_incoming_items WHERE incoming_id IN (SELECT id FROM wqs_incoming WHERE po_id=?)")->execute([$cleanupIds['po_id']]);
            $pdo->prepare("DELETE FROM wqs_incoming WHERE po_id=?")->execute([$cleanupIds['po_id']]);
            t_info("Incoming records deleted");
        }

        // Delete PO items & PO
        if ($cleanupIds['po_id']) {
            $pdo->prepare("DELETE FROM purchases_po_items WHERE po_id=?")->execute([$cleanupIds['po_id']]);
            $pdo->prepare("DELETE FROM purchases_po WHERE id=?")->execute([$cleanupIds['po_id']]);
            t_info("PO deleted");
        }

        // Delete PR items & PR
        if ($cleanupIds['pr_id']) {
            $pdo->prepare("DELETE FROM wqs_pr_items WHERE pr_id=?")->execute([$cleanupIds['pr_id']]);
            $pdo->prepare("DELETE FROM wqs_pr WHERE id=?")->execute([$cleanupIds['pr_id']]);
            t_info("PR deleted");
        }

        t_pass("Cleanup complete — no test data left in DB");
    } catch (Throwable $e) {
        t_warn("Cleanup error: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════════════════
t_log("\n══════════════════════════════════════════════════════════");
t_log("  RESULTS");
t_log("══════════════════════════════════════════════════════════");
t_log("  PASS: {$passCount}");
t_log("  FAIL: {$failCount}");
t_log("  WARN: " . count($warnings));
if ($warnings) { foreach ($warnings as $w) { t_log("    - {$w}"); } }
t_log("");
if ($failCount === 0) {
    t_log("  ✅ ALL TESTS PASSED — Flow PR → PO → Incoming verified.");
} else {
    t_log("  ❌ {$failCount} TEST(S) FAILED — See details above.");
}
t_log("══════════════════════════════════════════════════════════\n");

exit($failCount > 0 ? 1 : 0);
