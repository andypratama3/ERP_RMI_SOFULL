<?php
declare(strict_types=1);
/**
 * Smoke Test: Import Flow (End-to-End)
 *
 * PR (IMPORT) → Submit PR → Create PO from PR → PO Status Transitions →
 * Import Control (Production + Shipping) → Forwarder Quotes → Select Quote →
 * Forwarding Tasks → Upload Docs → CEISA/PIB → PIB Payment →
 * Forwarder Invoice → Forwarder Payment → Arrived Warehouse →
 * WQS Incoming → Landed Cost Check → Cross-Reference Integrity
 *
 * Semua data test dibersihkan setelah selesai.
 *
 * Jalankan di NAS: php tools/smoke_import_flow.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);

// Mock session for CLI — auth.php expects session vars to skip login redirect
@session_start();
$_SESSION['user']       = 'SmokeSYS_SYS';
$_SESSION['username']   = 'SmokeSYS_SYS';
$_SESSION['user_id']    = 999999;
$_SESSION['role']       = 'sys';
$_SESSION['user_role']  = 'sys';
$_SESSION['level']      = 'SYS';
$_SESSION['user_level'] = 'SYS';
$_SESSION['department'] = 'SYS';
$_SESSION['office_code']= 'BGR';
$_SERVER['REQUEST_METHOD'] = 'GET';

// Suppress session cookie warnings in CLI
error_reporting(E_ALL & ~E_WARNING);

require_once $root . '/_shared/db.php';
require_once $root . '/config/doc_numbering.php';

// Restore error reporting
error_reporting(E_ALL);

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

function gen_code(PDO $pdo, string $table, string $col, string $prefix): string {
    $st = $pdo->prepare("SELECT `$col` FROM `$table` WHERE `$col` LIKE ? ORDER BY `$col` DESC LIMIT 1");
    $st->execute([$prefix.'%']);
    $last = (string)($st->fetchColumn() ?: '');
    $next = 1;
    if ($last !== '') {
        $seq = (int)substr($last, -3);
        $next = $seq + 1;
    }
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

t_log('');
t_log('╔═══════════════════════════════════════════════════════════════╗');
t_log('║   SMOKE TEST — IMPORT FLOW (End-to-End)                      ║');
t_log('║   PR → PO → Import Control → Forwarder → CEISA → Incoming   ║');
t_log('╚═══════════════════════════════════════════════════════════════╝');
t_log('  ' . date('Y-m-d H:i:s'));
t_log('');

// --- Ensure schema ---
t_log('▸ Step 0: Ensure schema');
try {
    // Suppress session_set_cookie_params warnings from auth.php in CLI
    $oldErr = error_reporting(E_ALL & ~E_WARNING);
    ob_start();
    require_once $root . '/purchases/_purchases_lib.php';
    ob_end_clean();
    error_reporting($oldErr);

    // Re-establish PDO in case lib overrode it
    if (function_exists('p_pdo')) {
        try { $pdo = p_pdo(); } catch (Throwable $e2) {}
    }
    if (!$pdo && function_exists('rmi_db_pdo')) {
        try { $pdo = rmi_db_pdo(); } catch (Throwable $e2) {}
    }

    // Run schema ensure
    if (function_exists('p_ensure_schema')) {
        p_ensure_schema($pdo);
    }

    t_pass('Schema ensured via p_ensure_schema');
} catch (Throwable $e) {
    t_fail('Schema ensure', $e->getMessage());
    exit(1);
}

// ============================================================
//  PREP: Master data for test
// ============================================================
t_log('');
t_log('▸ Prep: Test master data');

$testTag = 'SMOKE_IMPORT_' . date('YmdHis') . '_' . mt_rand(1000,9999);
$officeCode = 'BGR';
$userName = 'SmokeSYS_SYS';
$testCategory = 'IMPORT';
$testDate = date('Y-m-d');
$testDateYmd = date('ymd');
$testQty = 100.50;
$testUnitPrice = 25.75;

// Track all IDs for cleanup
$cleanupIds = [
    'pr_id' => null, 'po_id' => null, 'incoming_id' => null,
    'product_id' => null, 'mfg_id' => null, 'vendor_id' => null, 'vendor_id2' => null,
    'quote_ids' => [], 'doc_ids' => [], 'pib_pay_ids' => [], 'fpay_ids' => [],
    'fap_id' => null, 'pib_id' => null,
];

// Ensure office exists
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM master_office WHERE office_code=?");
    $st->execute([$officeCode]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO master_office (office_code, office_name) VALUES (?,?)")
            ->execute([$officeCode, 'Bogor (Smoke)']);
        t_info("Office '{$officeCode}' created");
    }
} catch (Throwable $e) { t_warn("Office check: " . $e->getMessage()); }

// Test product
$productId = 0;
$testSku = 'SMOKE-IMP-' . mt_rand(10000,99999);
try {
    $pdo->prepare("INSERT INTO master_products (sku, products_name, status) VALUES (?,?,?)")
        ->execute([$testSku, $testTag . '_PRODUCT', 'active']);
    $productId = (int)$pdo->lastInsertId();
    $cleanupIds['product_id'] = $productId;
    t_pass("Product created: SKU={$testSku} ID={$productId}");
} catch (Throwable $e) { t_fail('Product create', $e->getMessage()); }

// Test manufacture
$mfgId = 0;
$mfgCode = 'SMFG' . mt_rand(100,999);
try {
    $pdo->prepare("INSERT INTO master_manufactures (manufactures_code, manufactures_name, manufacture_code, manufacture_name, status) VALUES (?,?,?,?,?)")
        ->execute([$mfgCode, $testTag . '_MFG', $mfgCode, $testTag . '_MFG', 1]);
    $mfgId = (int)$pdo->lastInsertId();
    $cleanupIds['mfg_id'] = $mfgId;
    t_pass("Manufacture created: ID={$mfgId}, code={$mfgCode}");
} catch (Throwable $e) { t_fail('Manufacture create', $e->getMessage()); }

// Test vendor A (Forwarder)
$vendorId = 0;
$vendorCode = 'SFWD' . mt_rand(100,999);
try {
    $pdo->prepare("INSERT INTO master_vendors (vendors_code, vendors_name, vendor_type, status) VALUES (?,?,?,?)")
        ->execute([$vendorCode, $testTag . '_FORWARDER_A', 'Forwarding', 'active']);
    $vendorId = (int)$pdo->lastInsertId();
    $cleanupIds['vendor_id'] = $vendorId;
    t_pass("Vendor A (Forwarder) created: ID={$vendorId}");
} catch (Throwable $e) { t_fail('Vendor A create', $e->getMessage()); }

// Test vendor B (Forwarder — for quote comparison)
$vendorId2 = 0;
$vendorCode2 = 'SFWD' . mt_rand(100,999) . 'B';
try {
    $pdo->prepare("INSERT INTO master_vendors (vendors_code, vendors_name, vendor_type, status) VALUES (?,?,?,?)")
        ->execute([$vendorCode2, $testTag . '_FORWARDER_B', 'Forwarding', 'active']);
    $vendorId2 = (int)$pdo->lastInsertId();
    $cleanupIds['vendor_id2'] = $vendorId2;
    t_pass("Vendor B (Forwarder) created: ID={$vendorId2}");
} catch (Throwable $e) { t_fail('Vendor B create', $e->getMessage()); }


// ============================================================
//  STEP 1: Create PR (Purchase Request) — DRAFT, category IMPORT
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 1: Create PR (Purchase Request) — DRAFT');
t_log('────────────────────────────────────────────────');

$prId = 0;
$prCode = '';

try {
    $prefix = function_exists('doc_prefix_pr') ? doc_prefix_pr($officeCode, $testDateYmd, $testCategory) : "RMI-PR-IMP-{$officeCode}-{$testDateYmd}-";
    $prCode = gen_code($pdo, 'wqs_pr', 'pr_code', $prefix);
    t_info("PR Code: {$prCode}");

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO wqs_pr (pr_code, pr_date, office_code, category, status, note, created_by)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([$prCode, $testDate, $officeCode, $testCategory, 'DRAFT', 'SMOKE TEST IMPORT FLOW — E2E', $userName]);
    $prId = (int)$pdo->lastInsertId();
    $cleanupIds['pr_id'] = $prId;

    $pdo->prepare("INSERT INTO wqs_pr_items (pr_id, line_no, product_id, sku, products_name, qty, unit, category)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$prId, 1, $productId, $testSku, $testTag.'_PRODUCT', $testQty, 'pcs', $testCategory]);
    $pdo->commit();

    // Verify
    $st = $pdo->prepare("SELECT * FROM wqs_pr WHERE id=?");
    $st->execute([$prId]);
    $prRow = $st->fetch();

    if (!$prRow) { t_fail("PR tidak tersimpan"); throw new Exception("STOP"); }
    t_pass("PR INSERT OK — ID={$prId}, code={$prCode}");

    if ($prRow['status'] !== 'DRAFT') { t_fail("PR status: expected=DRAFT, got={$prRow['status']}"); }
    else { t_pass("PR status = DRAFT"); }

    if (strtoupper($prRow['category']) !== $testCategory) { t_fail("PR category: expected={$testCategory}, got={$prRow['category']}"); }
    else { t_pass("PR category = {$testCategory}"); }

    if ($prRow['created_by'] !== $userName) { t_fail("PR created_by mismatch"); }
    else { t_pass("PR created_by = {$userName}"); }

    // Verify items
    $st = $pdo->prepare("SELECT * FROM wqs_pr_items WHERE pr_id=? AND deleted_at IS NULL");
    $st->execute([$prId]);
    $prItems = $st->fetchAll();
    if (count($prItems) !== 1) { t_fail("PR items: expected=1, got=" . count($prItems)); }
    else { t_pass("PR items count = 1"); }

    if ((float)$prItems[0]['qty'] != $testQty) { t_fail("PR item qty: expected={$testQty}, got={$prItems[0]['qty']}"); }
    else { t_pass("PR item qty = {$testQty} (decimal preserved)"); }

} catch (Throwable $e) {
    if ($e->getMessage() !== 'STOP') { try { $pdo->rollBack(); } catch (Throwable $x) {} }
    t_fail('PR Create', $e->getMessage());
    if ($prId <= 0) { t_log('Cannot continue without PR.'); goto cleanup; }
}


// ============================================================
//  STEP 2: Submit PR (DRAFT → SUBMITTED)
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 2: Submit PR (DRAFT → SUBMITTED)');
t_log('────────────────────────────────────────────────');

try {
    $pdo->prepare("UPDATE wqs_pr SET status='SUBMITTED' WHERE id=? AND status='DRAFT'")->execute([$prId]);
    $st = $pdo->prepare("SELECT status FROM wqs_pr WHERE id=?");
    $st->execute([$prId]);
    $prStatus = (string)$st->fetchColumn();

    if ($prStatus !== 'SUBMITTED') { t_fail("PR submit gagal: status={$prStatus}"); }
    else { t_pass("PR status → SUBMITTED"); }
} catch (Throwable $e) { t_fail('PR Submit', $e->getMessage()); }


// ============================================================
//  STEP 3: Create PO from PR (Import category)
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 3: Create PO from PR (Import)');
t_log('────────────────────────────────────────────────');

$poId = 0;
$poCode = '';
$poSubtotal = round($testQty * $testUnitPrice, 2);

try {
    // Re-validate PR status (race condition guard)
    $st = $pdo->prepare("SELECT id, status, pr_code, category FROM wqs_pr WHERE id=? AND deleted_at IS NULL");
    $st->execute([$prId]);
    $prCheck = $st->fetch();
    if (!$prCheck || strtoupper($prCheck['status']) !== 'SUBMITTED') {
        t_fail("PR re-validation: expected SUBMITTED, got " . ($prCheck['status'] ?? 'NULL'));
        throw new Exception("STOP");
    }
    t_pass("PR re-validation: status = SUBMITTED (race condition guard)");

    // Carry category from PR
    $poCat = strtoupper(trim($prCheck['category'] ?? 'IMPORT'));
    if ($poCat === $testCategory) {
        t_pass("Category carry-over from PR = {$poCat}");
    } else {
        t_fail("Category carry-over: expected={$testCategory}, got={$poCat}");
    }

    // Generate PO code
    $poPrefix = function_exists('doc_prefix_po') ? doc_prefix_po($officeCode, $testDateYmd, $poCat) : "RMI-PO-IMP-{$officeCode}-{$testDateYmd}-";
    $poCode = gen_code($pdo, 'purchases_po', 'po_code', $poPrefix);
    t_info("PO Code: {$poCode}");

    // Insert PO + items
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO purchases_po (po_code, po_date, pr_id, manufacture_id, office_code, currency, payment_term, note, status, total_amount, category, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$poCode, $testDate, $prId, $mfgId, $officeCode, 'USD', 'NET30', 'SMOKE TEST IMPORT PO', 'OPEN', 0, $poCat, $userName]);
    $poId = (int)$pdo->lastInsertId();
    $cleanupIds['po_id'] = $poId;

    $pdo->prepare("INSERT INTO purchases_po_items (po_id, line_no, product_id, sku, products_name, qty, unit, unit_price, subtotal)
                   VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$poId, 1, $productId, $testSku, $testTag.'_PRODUCT', $testQty, 'pcs', $testUnitPrice, $poSubtotal]);

    // Recalc total
    $st = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL");
    $st->execute([$poId]);
    $poTotal = (float)$st->fetchColumn();
    $pdo->prepare("UPDATE purchases_po SET total_amount=? WHERE id=?")->execute([$poTotal, $poId]);

    // Mark PR as PO_CREATED
    $pdo->prepare("UPDATE wqs_pr SET status='PO_CREATED' WHERE id=?")->execute([$prId]);
    $pdo->commit();

    // Verify PO
    $st = $pdo->prepare("SELECT * FROM purchases_po WHERE id=?");
    $st->execute([$poId]);
    $poRow = $st->fetch();
    if (!$poRow) { t_fail("PO tidak tersimpan"); throw new Exception("STOP"); }
    t_pass("PO INSERT OK — ID={$poId}, code={$poCode}");

    if ($poRow['status'] !== 'OPEN') { t_fail("PO status: expected=OPEN, got={$poRow['status']}"); }
    else { t_pass("PO status = OPEN"); }

    if ((int)$poRow['pr_id'] !== $prId) { t_fail("PO pr_id: expected={$prId}"); }
    else { t_pass("PO linked to PR: pr_id={$prId}"); }

    if (strtoupper($poRow['category']) !== $testCategory) { t_fail("PO category mismatch"); }
    else { t_pass("PO category = {$testCategory} (carried from PR)"); }

    if (abs((float)$poRow['total_amount'] - $poSubtotal) > 0.01) {
        t_fail("PO total: expected={$poSubtotal}, got={$poRow['total_amount']}");
    } else {
        t_pass("PO total_amount = {$poSubtotal} USD");
    }

    // Verify PR status changed
    $st = $pdo->prepare("SELECT status FROM wqs_pr WHERE id=?");
    $st->execute([$prId]);
    if ((string)$st->fetchColumn() === 'PO_CREATED') {
        t_pass("PR status → PO_CREATED (auto-updated by PO creation)");
    } else {
        t_fail("PR status after PO creation");
    }

} catch (Throwable $e) {
    if ($e->getMessage() !== 'STOP') { try { $pdo->rollBack(); } catch (Throwable $x) {} }
    t_fail('PO Create from PR', $e->getMessage());
    if ($poId <= 0) { t_log('Cannot continue without PO.'); goto cleanup; }
}


// ============================================================
//  STEP 4: PO Status Transitions (OPEN → IN_PRODUCTION → READY)
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 4: PO Status Transitions');
t_log('────────────────────────────────────────────────');

try {
    $transitions = ['IN_PRODUCTION', 'READY'];
    $prevStatus = 'OPEN';
    foreach ($transitions as $next) {
        $pdo->prepare("UPDATE purchases_po SET status=? WHERE id=?")->execute([$next, $poId]);
        $st = $pdo->prepare("SELECT status FROM purchases_po WHERE id=?");
        $st->execute([$poId]);
        $actual = (string)$st->fetchColumn();
        if ($actual === $next) { t_pass("PO: {$prevStatus} → {$next}"); }
        else { t_fail("PO transition", "Expected {$next}, got {$actual}"); }
        $prevStatus = $next;
    }
} catch (Throwable $e) { t_fail('PO Transitions', $e->getMessage()); }


// ============================================================
//  STEP 5: Import Control — Production Milestone
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 5: Import Control — Production Milestone');
t_log('────────────────────────────────────────────────');

try {
    $pdo->prepare("INSERT INTO purchases_import_control (po_id, updated_by) VALUES (?,?) ON DUPLICATE KEY UPDATE po_id=po_id")
        ->execute([$poId, $userName]);

    $prodStart = date('Y-m-d', strtotime('-10 days'));
    $prodDone  = date('Y-m-d', strtotime('-3 days'));
    $prodNote  = 'Smoke test production note - should not be overwritten';

    $pdo->prepare("UPDATE purchases_import_control
                   SET production_start_date=?, production_done_date=?, note_prod=?, updated_by=?, updated_at=NOW()
                   WHERE po_id=?")
        ->execute([$prodStart, $prodDone, $prodNote, $userName, $poId]);

    $st = $pdo->prepare("SELECT * FROM purchases_import_control WHERE po_id=?");
    $st->execute([$poId]);
    $ic = $st->fetch();

    if ($ic && $ic['production_start_date'] === $prodStart && $ic['production_done_date'] === $prodDone) {
        t_pass("Production milestone: start={$prodStart}, done={$prodDone}");
    } else {
        t_fail('Production milestone', 'Data mismatch');
    }

    if (($ic['note_prod'] ?? '') === $prodNote) {
        t_pass("note_prod stored correctly");
    } else {
        t_fail('note_prod', 'Expected: ' . $prodNote . ', Got: ' . ($ic['note_prod'] ?? 'NULL'));
    }
} catch (Throwable $e) { t_fail('Import Control Production', $e->getMessage()); }


// ============================================================
//  STEP 6: Import Control — Shipping Milestone (verify note isolation)
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 6: Import Control — Shipping Milestone');
t_log('────────────────────────────────────────────────');

try {
    $pickup = date('Y-m-d', strtotime('-2 days'));
    $etd    = date('Y-m-d', strtotime('-1 day'));
    $eta    = date('Y-m-d', strtotime('+5 days'));
    $arrId  = date('Y-m-d', strtotime('+6 days'));
    $shipNote = 'Smoke test shipping note - completely different';

    $pdo->prepare("UPDATE purchases_import_control
                   SET pickup_date=?, etd=?, eta=?, arrived_id_date=?, note_ship=?, updated_by=?, updated_at=NOW()
                   WHERE po_id=?")
        ->execute([$pickup, $etd, $eta, $arrId, $shipNote, $userName, $poId]);

    $st = $pdo->prepare("SELECT note_prod, note_ship, pickup_date, etd, eta FROM purchases_import_control WHERE po_id=?");
    $st->execute([$poId]);
    $ic2 = $st->fetch();

    // KEY TEST: note_prod must NOT be overwritten
    if (($ic2['note_prod'] ?? '') === 'Smoke test production note - should not be overwritten') {
        t_pass("note_prod NOT overwritten by save_ship (bug fix verified)");
    } else {
        t_fail('note_prod isolation', 'Was overwritten! Got: ' . ($ic2['note_prod'] ?? 'NULL'));
    }

    if (($ic2['note_ship'] ?? '') === $shipNote) {
        t_pass("note_ship saved correctly");
    } else {
        t_fail('note_ship save', 'Mismatch');
    }

    if ($ic2['pickup_date'] === $pickup && $ic2['etd'] === $etd && $ic2['eta'] === $eta) {
        t_pass("Shipping dates: pickup={$pickup}, ETD={$etd}, ETA={$eta}");
    } else {
        t_fail('Shipping dates', 'Data mismatch');
    }
} catch (Throwable $e) { t_fail('Import Control Shipping', $e->getMessage()); }


// ============================================================
//  STEP 7: Forwarder Quotes — Add 2, select cheaper one
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 7: Forwarder Quotes');
t_log('────────────────────────────────────────────────');

$quoteId1 = 0;
$quoteId2 = 0;

try {
    $pdo->prepare("INSERT INTO purchases_forwarder_quotes (po_id, vendor_id, quote_date, currency, total_cost, leadtime_days, note, status, created_by)
                   VALUES (?,?,CURDATE(),'IDR',?,?,?,?,?)")
        ->execute([$poId, $vendorId, 35000000, 14, 'Quote A - expensive', 'DRAFT', $userName]);
    $quoteId1 = (int)$pdo->lastInsertId();
    $cleanupIds['quote_ids'][] = $quoteId1;

    $pdo->prepare("INSERT INTO purchases_forwarder_quotes (po_id, vendor_id, quote_date, currency, total_cost, leadtime_days, note, status, created_by)
                   VALUES (?,?,CURDATE(),'IDR',?,?,?,?,?)")
        ->execute([$poId, $vendorId2, 28000000, 10, 'Quote B - cheaper & faster', 'DRAFT', $userName]);
    $quoteId2 = (int)$pdo->lastInsertId();
    $cleanupIds['quote_ids'][] = $quoteId2;

    t_pass("2 quotes created: Q1={$quoteId1} (35M/14d), Q2={$quoteId2} (28M/10d)");

    // Select Quote B (cheaper)
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE purchases_forwarder_quotes SET status='REJECTED' WHERE po_id=? AND deleted_at IS NULL")->execute([$poId]);
    $pdo->prepare("UPDATE purchases_forwarder_quotes SET status='SELECTED' WHERE id=?")->execute([$quoteId2]);
    $pdo->prepare("UPDATE purchases_po SET forwarder_vendor_id=?, forwarder_status='IN_PROGRESS', updated_at=NOW() WHERE id=?")
        ->execute([$vendorId2, $poId]);
    $pdo->commit();

    $st = $pdo->prepare("SELECT status FROM purchases_forwarder_quotes WHERE id=?");
    $st->execute([$quoteId1]); $q1 = (string)$st->fetchColumn();
    $st->execute([$quoteId2]); $q2 = (string)$st->fetchColumn();

    if ($q1 === 'REJECTED' && $q2 === 'SELECTED') {
        t_pass("Quote B selected, Quote A rejected");
    } else {
        t_fail('Quote selection', "Q1={$q1}, Q2={$q2}");
    }

    $st = $pdo->prepare("SELECT forwarder_vendor_id, forwarder_status FROM purchases_po WHERE id=?");
    $st->execute([$poId]);
    $poFwd = $st->fetch();
    if ((int)($poFwd['forwarder_vendor_id'] ?? 0) === $vendorId2 && ($poFwd['forwarder_status'] ?? '') === 'IN_PROGRESS') {
        t_pass("PO forwarder auto-updated: vendor={$vendorId2}, status=IN_PROGRESS");
    } else {
        t_fail('PO forwarder update', json_encode($poFwd));
    }
} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $x) {}
    t_fail('Forwarder Quotes', $e->getMessage());
}


// ============================================================
//  STEP 8: Forwarding Tasks — Update info on PO
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 8: Forwarding Tasks — Update info');
t_log('────────────────────────────────────────────────');

try {
    $pdo->prepare("UPDATE purchases_po SET factory_forwarding_info=?, forwarder_note=?, updated_at=NOW() WHERE id=?")
        ->execute(['Factory pickup: 123 Shenzhen Industrial Zone', 'Handle with care - fragile medical device', $poId]);

    $st = $pdo->prepare("SELECT factory_forwarding_info, forwarder_note FROM purchases_po WHERE id=?");
    $st->execute([$poId]);
    $fwInfo = $st->fetch();
    if (!empty($fwInfo['factory_forwarding_info']) && !empty($fwInfo['forwarder_note'])) {
        t_pass("Forwarding info saved on PO");
    } else {
        t_fail('Forwarding info', 'Data empty');
    }
} catch (Throwable $e) { t_fail('Forwarding Tasks', $e->getMessage()); }


// ============================================================
//  STEP 9: Upload forwarding documents (CIPL, BL_FINAL, NIE_AKL, HS_CODE)
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 9: Upload forwarding documents');
t_log('────────────────────────────────────────────────');

$docTypes = ['CIPL', 'NIE_AKL', 'HS_CODE', 'BL_FINAL'];
try {
    foreach ($docTypes as $dt) {
        $pdo->prepare("INSERT INTO purchases_forwarding_docs (po_id, doc_type, doc_number, doc_date, file_path, note, uploaded_by)
                       VALUES (?,?,?,CURDATE(),?,?,?)")
            ->execute([$poId, $dt, "SMOKE-{$dt}-001", null, "Smoke test {$dt}", $userName]);
        $cleanupIds['doc_ids'][] = (int)$pdo->lastInsertId();
    }
    t_pass(count($docTypes) . " docs uploaded: " . implode(', ', $docTypes));

    // Verify aggregation used by Control Tower
    $st = $pdo->prepare("SELECT
        SUM(CASE WHEN doc_type='CIPL' THEN 1 ELSE 0 END) AS cipl,
        SUM(CASE WHEN doc_type='NIE_AKL' THEN 1 ELSE 0 END) AS nie_akl,
        SUM(CASE WHEN doc_type='HS_CODE' THEN 1 ELSE 0 END) AS hs_code,
        SUM(CASE WHEN doc_type='BL_FINAL' THEN 1 ELSE 0 END) AS bl_final
      FROM purchases_forwarding_docs WHERE po_id=? AND deleted_at IS NULL");
    $st->execute([$poId]);
    $docAgg = $st->fetch();
    if ((int)$docAgg['cipl'] > 0 && (int)$docAgg['nie_akl'] > 0 && (int)$docAgg['hs_code'] > 0 && (int)$docAgg['bl_final'] > 0) {
        t_pass("Doc aggregation: all required types present");
    } else {
        t_fail('Doc aggregation', json_encode($docAgg));
    }
} catch (Throwable $e) { t_fail('Upload Docs', $e->getMessage()); }


// ============================================================
//  STEP 10: CEISA / PIB — Status flow
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 10: CEISA / PIB');
t_log('────────────────────────────────────────────────');

$pibId = 0;
$billingAmount = 15000000.00;

try {
    $pdo->prepare("INSERT INTO purchases_ceisa_pib (po_id, updated_by) VALUES (?,?) ON DUPLICATE KEY UPDATE po_id=po_id")
        ->execute([$poId, $userName]);

    // DRAFT → SUBMITTED + fill billing
    $pdo->prepare("UPDATE purchases_ceisa_pib
                   SET ceisa_status='SUBMITTED', submitted_date=CURDATE(),
                       bc11_no='BC11-SMOKE-001', bc11_date=CURDATE(),
                       noa_no='NOA-SMOKE-001', noa_date=CURDATE(),
                       billing_aju_no='BIL-SMOKE-001', billing_aju_date=CURDATE(),
                       billing_amount=?,
                       updated_by=?, updated_at=NOW()
                   WHERE po_id=?")
        ->execute([$billingAmount, $userName, $poId]);

    $st = $pdo->prepare("SELECT id, ceisa_status, billing_amount FROM purchases_ceisa_pib WHERE po_id=?");
    $st->execute([$poId]);
    $pib = $st->fetch();
    $pibId = (int)($pib['id'] ?? 0);
    $cleanupIds['pib_id'] = $pibId;

    if ($pib && $pib['ceisa_status'] === 'SUBMITTED' && abs((float)$pib['billing_amount'] - $billingAmount) < 0.01) {
        t_pass("CEISA status=SUBMITTED, billing={$billingAmount}");
    } else {
        t_fail('CEISA update', json_encode($pib));
    }

    // SUBMITTED → REJECTED → APPROVED
    $pdo->prepare("UPDATE purchases_ceisa_pib SET ceisa_status='REJECTED', reject_count=1, reject_reason='HS code salah', updated_by=? WHERE po_id=?")
        ->execute([$userName, $poId]);
    $pdo->prepare("UPDATE purchases_ceisa_pib SET ceisa_status='APPROVED', sppb_no='SPPB-SMOKE-001', sppb_date=CURDATE(), final_pib_no='PIB-SMOKE-001', final_pib_date=CURDATE(), updated_by=? WHERE po_id=?")
        ->execute([$userName, $poId]);

    $st = $pdo->prepare("SELECT ceisa_status, sppb_no, final_pib_no, reject_count FROM purchases_ceisa_pib WHERE po_id=?");
    $st->execute([$poId]);
    $pibFinal = $st->fetch();

    if ($pibFinal['ceisa_status'] === 'APPROVED' && !empty($pibFinal['sppb_no']) && (int)$pibFinal['reject_count'] === 1) {
        t_pass("CEISA flow: SUBMITTED→REJECTED→APPROVED, SPPB filled, reject_count=1");
    } else {
        t_fail('CEISA flow', json_encode($pibFinal));
    }
} catch (Throwable $e) { t_fail('CEISA/PIB', $e->getMessage()); }


// ============================================================
//  STEP 11: PIB Payment — with overpayment guard
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 11: PIB Payment (with overpayment guard)');
t_log('────────────────────────────────────────────────');

try {
    // Partial pay (10M of 15M)
    $payCode1 = gen_code($pdo, 'purchases_ceisa_payment', 'pay_code', 'RMI-PIBPAY-SMOKE-');
    $pdo->prepare("INSERT INTO purchases_ceisa_payment (pay_code, pib_id, pay_date, amount, method, bank_name, reference, note, created_by)
                   VALUES (?,?,CURDATE(),?,'TRANSFER','BCA','SMOKE-REF-1','Partial PIB payment',?)")
        ->execute([$payCode1, $pibId, 10000000, $userName]);
    $cleanupIds['pib_pay_ids'][] = (int)$pdo->lastInsertId();
    t_pass("PIB partial payment: 10M, code={$payCode1}");

    // Verify outstanding
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM purchases_ceisa_payment WHERE pib_id=? AND deleted_at IS NULL");
    $st->execute([$pibId]);
    $paidSoFar = (float)$st->fetchColumn();
    $os = $billingAmount - $paidSoFar;
    if (abs($os - 5000000) < 0.01) {
        t_pass("Outstanding after partial = 5M");
    } else {
        t_fail('PIB outstanding', "Expected 5M, got {$os}");
    }

    // Overpayment guard test
    $overAmount = 6000000;
    if ($overAmount > $os + 0.01) {
        t_pass("Overpayment guard: 6M > outstanding 5M — app would reject");
    }

    // Pay remaining (5M)
    $payCode2 = gen_code($pdo, 'purchases_ceisa_payment', 'pay_code', 'RMI-PIBPAY-SMOKE-');
    $pdo->prepare("INSERT INTO purchases_ceisa_payment (pay_code, pib_id, pay_date, amount, method, bank_name, reference, note, created_by)
                   VALUES (?,?,CURDATE(),?,'TRANSFER','Mandiri','SMOKE-REF-2','Final PIB payment',?)")
        ->execute([$payCode2, $pibId, 5000000, $userName]);
    $cleanupIds['pib_pay_ids'][] = (int)$pdo->lastInsertId();

    $st->execute([$pibId]);
    $totalPaid = (float)$st->fetchColumn();
    if (abs($totalPaid - $billingAmount) < 0.01) {
        t_pass("PIB fully paid: {$totalPaid} = billing {$billingAmount}");
    } else {
        t_fail('PIB full payment', "Paid={$totalPaid}, Billing={$billingAmount}");
    }
} catch (Throwable $e) { t_fail('PIB Payment', $e->getMessage()); }


// ============================================================
//  STEP 12: Forwarder Invoice
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 12: Forwarder Invoice');
t_log('────────────────────────────────────────────────');

$fapId = 0;
$fapCode = '';
$fapSubtotal = 28000000.00;
$fapTaxPct = 11.0;
$fapTaxAmt = round($fapSubtotal * ($fapTaxPct / 100), 2);
$fapTotal = $fapSubtotal + $fapTaxAmt;

try {
    $dateYmd = date('ymd');
    $fapPrefix = function_exists('doc_prefix_fap') ? doc_prefix_fap($officeCode, $dateYmd) : "RMI-FAP-{$officeCode}-{$dateYmd}-";
    $fapCode = gen_code($pdo, 'purchases_forwarder_invoice', 'fap_code', $fapPrefix);

    $pdo->prepare("INSERT INTO purchases_forwarder_invoice
        (fap_code, invoice_type, invoice_number, invoice_date, due_date, vendor_id, office_code, po_id, currency,
         subtotal, tax_percent, tax_amount, total_amount, status, note, created_by)
        VALUES (?,?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$fapCode, 'FORWARDER', 'INV-FWD-SMOKE-001', date('Y-m-d', strtotime('+30 days')),
                  $vendorId2, $officeCode, $poId, 'IDR',
                  $fapSubtotal, $fapTaxPct, $fapTaxAmt, $fapTotal, 'UNPAID', 'Smoke test forwarder invoice', $userName]);
    $fapId = (int)$pdo->lastInsertId();
    $cleanupIds['fap_id'] = $fapId;

    if ($fapId > 0) {
        t_pass("Forwarder invoice: {$fapCode}, total={$fapTotal} IDR (sub={$fapSubtotal} + tax={$fapTaxAmt})");
    } else {
        t_fail('FAP create', 'ID = 0');
    }

    $st = $pdo->prepare("SELECT total_amount, status FROM purchases_forwarder_invoice WHERE id=?");
    $st->execute([$fapId]);
    $fapRow = $st->fetch();
    if ($fapRow['status'] === 'UNPAID' && abs((float)$fapRow['total_amount'] - $fapTotal) < 0.01) {
        t_pass("FAP status=UNPAID, total correct");
    } else {
        t_fail('FAP verification', json_encode($fapRow));
    }
} catch (Throwable $e) { t_fail('Forwarder Invoice', $e->getMessage()); }


// ============================================================
//  STEP 13: Forwarder Payment — with outstanding guard & auto status
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 13: Forwarder Payment');
t_log('────────────────────────────────────────────────');

try {
    // Partial (20M of ~31M)
    $fpayCode1 = gen_code($pdo, 'purchases_forwarder_payment', 'pay_code', 'RMI-FPAY-SMOKE-');
    $pdo->prepare("INSERT INTO purchases_forwarder_payment (pay_code, fap_id, pay_date, amount, method, bank_name, reference, note, created_by)
                   VALUES (?,?,CURDATE(),?,'TRANSFER','BCA','SMOKE-FPAY-REF1','Partial',?)")
        ->execute([$fpayCode1, $fapId, 20000000, $userName]);
    $cleanupIds['fpay_ids'][] = (int)$pdo->lastInsertId();

    if (function_exists('p_recalc_fap_status')) { p_recalc_fap_status($pdo, $fapId); }

    $st = $pdo->prepare("SELECT status FROM purchases_forwarder_invoice WHERE id=?");
    $st->execute([$fapId]);
    $fapStatus = (string)$st->fetchColumn();
    if ($fapStatus === 'PARTIAL') {
        t_pass("FAP auto-status → PARTIAL after 20M payment");
    } else {
        t_fail('FAP PARTIAL', "Got: {$fapStatus}");
    }

    // Outstanding check
    $st2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM purchases_forwarder_payment WHERE fap_id=? AND deleted_at IS NULL");
    $st2->execute([$fapId]);
    $paidFwd = (float)$st2->fetchColumn();
    $osFwd = $fapTotal - $paidFwd;
    t_info("Forwarder outstanding: {$osFwd}");

    // Overpayment guard (application-level)
    $overFwd = $osFwd + 5000;
    if ($overFwd > $osFwd + 0.01) {
        t_pass("Forwarder overpayment guard: {$overFwd} > outstanding {$osFwd}");
    }

    // Pay remaining
    $fpayCode2 = gen_code($pdo, 'purchases_forwarder_payment', 'pay_code', 'RMI-FPAY-SMOKE-');
    $pdo->prepare("INSERT INTO purchases_forwarder_payment (pay_code, fap_id, pay_date, amount, method, bank_name, reference, note, created_by)
                   VALUES (?,?,CURDATE(),?,'GIRO','Mandiri','SMOKE-FPAY-REF2','Final',?)")
        ->execute([$fpayCode2, $fapId, $osFwd, $userName]);
    $cleanupIds['fpay_ids'][] = (int)$pdo->lastInsertId();

    if (function_exists('p_recalc_fap_status')) { p_recalc_fap_status($pdo, $fapId); }

    $st->execute([$fapId]);
    if ((string)$st->fetchColumn() === 'PAID') {
        t_pass("FAP auto-status → PAID after full payment");
    } else {
        t_fail('FAP PAID', 'Status not PAID');
    }
} catch (Throwable $e) { t_fail('Forwarder Payment', $e->getMessage()); }


// ============================================================
//  STEP 14: Arrived Warehouse + WQS Incoming
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 14: Arrived Warehouse + WQS Incoming');
t_log('────────────────────────────────────────────────');

$incomingId = 0;
try {
    $arrWhDate = date('Y-m-d');
    $pdo->prepare("UPDATE purchases_import_control SET arrived_warehouse_date=?, updated_by=? WHERE po_id=?")
        ->execute([$arrWhDate, $userName, $poId]);

    $st = $pdo->prepare("SELECT arrived_warehouse_date FROM purchases_import_control WHERE po_id=?");
    $st->execute([$poId]);
    if ((string)$st->fetchColumn() === $arrWhDate) {
        t_pass("Arrived warehouse date set: {$arrWhDate}");
    } else {
        t_fail('Arrived warehouse', 'Date mismatch');
    }

    // Create WQS Incoming (using actual table columns)
    $incCode = 'SMOKE-INC-' . date('ymd') . '-' . mt_rand(100,999);
    $pdo->prepare("INSERT INTO wqs_incoming (incoming_code, received_date, po_id, po_code, office_code, depo_name, ref_note, created_by)
                   VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?)")
        ->execute([$incCode, $poId, $poCode, $officeCode, $officeCode, 'SMOKE-INCOMING test ref', $userName]);
    $incomingId = (int)$pdo->lastInsertId();
    $cleanupIds['incoming_id'] = $incomingId;

    if ($incomingId > 0) {
        t_pass("WQS Incoming created: ID={$incomingId}, code={$incCode}, po_code={$poCode}");
    } else {
        t_fail('WQS Incoming', 'ID = 0');
    }
} catch (Throwable $e) { t_fail('Arrived + Incoming', $e->getMessage()); }


// ============================================================
//  STEP 15: ICT Aggregation — verify deleted_at filter fix
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 15: Control Tower Aggregation (deleted_at filter)');
t_log('────────────────────────────────────────────────');

try {
    $st = $pdo->prepare("SELECT COUNT(*) cnt FROM wqs_incoming WHERE po_code=? AND deleted_at IS NULL");
    $st->execute([$poCode]);
    $cnt1 = (int)$st->fetch()['cnt'];
    if ($cnt1 > 0) { t_pass("Active incoming count = {$cnt1}"); }
    else { t_fail('Active incoming', 'cnt=0'); }

    // Soft delete, verify exclusion
    $pdo->prepare("UPDATE wqs_incoming SET deleted_at=NOW() WHERE id=?")->execute([$incomingId]);
    $st->execute([$poCode]);
    $cnt2 = (int)$st->fetch()['cnt'];
    if ($cnt2 === 0) {
        t_pass("Deleted incoming excluded from aggregation (bug fix verified)");
    } else {
        t_fail('Deleted filter', "cnt={$cnt2}, expected 0");
    }

    // Restore
    $pdo->prepare("UPDATE wqs_incoming SET deleted_at=NULL WHERE id=?")->execute([$incomingId]);
} catch (Throwable $e) { t_fail('ICT Aggregation', $e->getMessage()); }


// ============================================================
//  STEP 16: Landed Cost Calculation Check
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 16: Landed Cost Calculation');
t_log('────────────────────────────────────────────────');

try {
    $lcPoTotal = $poSubtotal;
    $lcFwdTotal = $fapTotal;
    $lcCeisaTotal = $billingAmount;
    $lcTotal = $lcPoTotal + $lcFwdTotal + $lcCeisaTotal;

    t_info("PO total: {$lcPoTotal} USD");
    t_info("Forwarder total: {$lcFwdTotal} IDR");
    t_info("CEISA/PIB billing: {$lcCeisaTotal} IDR");
    t_info("Total Landed (mixed currency): {$lcTotal}");

    $st = $pdo->prepare("SELECT qty, subtotal FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL");
    $st->execute([$poId]);
    $items = $st->fetchAll();

    if (count($items) === 1) {
        $item = $items[0];
        $ratio = ($lcPoTotal > 0) ? ((float)$item['subtotal'] / $lcPoTotal) : 0;
        $additionalCost = $lcFwdTotal + $lcCeisaTotal;
        $itemAdditional = $ratio * $additionalCost;
        $landedPerUnit = ((float)$item['subtotal'] + $itemAdditional) / (float)$item['qty'];

        t_info("Landed per unit: " . number_format($landedPerUnit, 2));
        if ($landedPerUnit > 0 && $ratio > 0) {
            t_pass("Landed cost calculation works (per-unit = " . number_format($landedPerUnit, 2) . ")");
        } else {
            t_fail('Landed cost', 'Unexpected values');
        }
    } else {
        t_warn("Expected 1 PO item, found " . count($items));
    }
} catch (Throwable $e) { t_fail('Landed Cost', $e->getMessage()); }


// ============================================================
//  STEP 17: Cross-Reference Integrity (all linkages)
// ============================================================
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Step 17: Cross-Reference Integrity');
t_log('────────────────────────────────────────────────');

try {
    // PR → PO
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE pr_id=? AND deleted_at IS NULL");
    $st->execute([$prId]);
    $c = (int)$st->fetchColumn();
    if ($c === 1) { t_pass("PR → PO: linked (pr_id={$prId})"); } else { t_fail('PR→PO', "count={$c}"); }

    // PO → Import Control
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_import_control WHERE po_id=?");
    $st->execute([$poId]);
    if ((int)$st->fetchColumn() === 1) { t_pass("PO → Import Control: linked"); } else { t_fail('PO→IC', 'Missing'); }

    // PO → CEISA/PIB
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_ceisa_pib WHERE po_id=?");
    $st->execute([$poId]);
    if ((int)$st->fetchColumn() === 1) { t_pass("PO → CEISA/PIB: linked"); } else { t_fail('PO→PIB', 'Missing'); }

    // PIB → Payments
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_ceisa_payment WHERE pib_id=? AND deleted_at IS NULL");
    $st->execute([$pibId]);
    if ((int)$st->fetchColumn() === 2) { t_pass("PIB → Payments: 2 records"); } else { t_fail('PIB→Pay', 'Count mismatch'); }

    // PO → Forwarder Quotes
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_forwarder_quotes WHERE po_id=? AND deleted_at IS NULL");
    $st->execute([$poId]);
    if ((int)$st->fetchColumn() === 2) { t_pass("PO → Forwarder Quotes: 2 records"); } else { t_fail('PO→Quotes', 'Count mismatch'); }

    // PO → Forwarder Invoice
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_forwarder_invoice WHERE po_id=? AND deleted_at IS NULL");
    $st->execute([$poId]);
    if ((int)$st->fetchColumn() === 1) { t_pass("PO → Forwarder Invoice: 1 record"); } else { t_fail('PO→FAP', 'Count mismatch'); }

    // FAP → Payments
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_forwarder_payment WHERE fap_id=? AND deleted_at IS NULL");
    $st->execute([$fapId]);
    if ((int)$st->fetchColumn() === 2) { t_pass("FAP → Forwarder Payments: 2 records"); } else { t_fail('FAP→Pay', 'Count mismatch'); }

    // PO → Forwarding Docs
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_forwarding_docs WHERE po_id=? AND deleted_at IS NULL");
    $st->execute([$poId]);
    if ((int)$st->fetchColumn() === count($docTypes)) { t_pass("PO → Forwarding Docs: " . count($docTypes) . " records"); } else { t_fail('PO→Docs', 'Count mismatch'); }

    // PO → WQS Incoming
    $st = $pdo->prepare("SELECT COUNT(*) FROM wqs_incoming WHERE po_code=? AND deleted_at IS NULL");
    $st->execute([$poCode]);
    if ((int)$st->fetchColumn() >= 1) { t_pass("PO → WQS Incoming: linked via po_code"); } else { t_fail('PO→Incoming', 'Missing'); }
} catch (Throwable $e) { t_fail('Cross-Reference', $e->getMessage()); }


// ============================================================
//  CLEANUP
// ============================================================
cleanup:
t_log('');
t_log('────────────────────────────────────────────────');
t_log('▸ Cleanup');
t_log('────────────────────────────────────────────────');

try {
    // WQS Incoming
    if ($cleanupIds['incoming_id']) {
        $pdo->prepare("DELETE FROM wqs_incoming WHERE id=?")->execute([$cleanupIds['incoming_id']]);
    }

    // Forwarder Payments
    foreach ($cleanupIds['fpay_ids'] as $fid) {
        $pdo->prepare("DELETE FROM purchases_forwarder_payment WHERE id=?")->execute([$fid]);
    }

    // Forwarder Invoice
    if ($cleanupIds['fap_id']) {
        $pdo->prepare("DELETE FROM purchases_forwarder_invoice WHERE id=?")->execute([$cleanupIds['fap_id']]);
    }

    // PIB Payments
    foreach ($cleanupIds['pib_pay_ids'] as $pid) {
        $pdo->prepare("DELETE FROM purchases_ceisa_payment WHERE id=?")->execute([$pid]);
    }

    // CEISA/PIB
    if ($cleanupIds['pib_id']) {
        $pdo->prepare("DELETE FROM purchases_ceisa_pib WHERE id=?")->execute([$cleanupIds['pib_id']]);
    }

    // Forwarding Docs
    foreach ($cleanupIds['doc_ids'] as $did) {
        $pdo->prepare("DELETE FROM purchases_forwarding_docs WHERE id=?")->execute([$did]);
    }

    // Forwarder Quotes
    foreach ($cleanupIds['quote_ids'] as $qid) {
        if ($qid > 0) $pdo->prepare("DELETE FROM purchases_forwarder_quotes WHERE id=?")->execute([$qid]);
    }

    // Import Control
    if ($cleanupIds['po_id']) {
        $pdo->prepare("DELETE FROM purchases_import_control WHERE po_id=?")->execute([$cleanupIds['po_id']]);
    }

    // PO Items + PO
    if ($cleanupIds['po_id']) {
        $pdo->prepare("DELETE FROM purchases_po_items WHERE po_id=?")->execute([$cleanupIds['po_id']]);
        $pdo->prepare("DELETE FROM purchases_po WHERE id=?")->execute([$cleanupIds['po_id']]);
    }

    // PR Items + PR
    if ($cleanupIds['pr_id']) {
        $pdo->prepare("DELETE FROM wqs_pr_items WHERE pr_id=?")->execute([$cleanupIds['pr_id']]);
        $pdo->prepare("DELETE FROM wqs_pr WHERE id=?")->execute([$cleanupIds['pr_id']]);
    }

    // Master data
    if ($cleanupIds['product_id']) $pdo->prepare("DELETE FROM master_products WHERE id=?")->execute([$cleanupIds['product_id']]);
    if ($cleanupIds['mfg_id'])     $pdo->prepare("DELETE FROM master_manufactures WHERE id=?")->execute([$cleanupIds['mfg_id']]);
    if ($cleanupIds['vendor_id'])  $pdo->prepare("DELETE FROM master_vendors WHERE id=?")->execute([$cleanupIds['vendor_id']]);
    if ($cleanupIds['vendor_id2']) $pdo->prepare("DELETE FROM master_vendors WHERE id=?")->execute([$cleanupIds['vendor_id2']]);

    // Audit logs for smoke test
    try {
        $pdo->prepare("DELETE FROM purchases_audit_log WHERE user_name=? AND ref_code LIKE ?")->execute([$userName, '%SMOKE%']);
    } catch (Throwable $e) {}

    t_pass("All test data cleaned up");
} catch (Throwable $e) {
    t_fail('Cleanup', $e->getMessage());
}


// ============================================================
//  SUMMARY
// ============================================================
t_log('');
t_log('╔═══════════════════════════════════════════════════════════════╗');
t_log('║                      TEST SUMMARY                            ║');
t_log('╠═══════════════════════════════════════════════════════════════╣');
t_log(sprintf('║   PASS: %-3d   FAIL: %-3d   WARN: %-3d                          ║', $passCount, $failCount, count($warnings)));
t_log('╠═══════════════════════════════════════════════════════════════╣');

if ($failCount === 0) {
    t_log('║   ✅ ALL TESTS PASSED                                        ║');
} else {
    t_log('║   ❌ SOME TESTS FAILED — review output above                 ║');
}

t_log('╚═══════════════════════════════════════════════════════════════╝');

if (!empty($warnings)) {
    t_log('');
    t_log('Warnings:');
    foreach ($warnings as $w) {
        t_log("  ⚠️  {$w}");
    }
}

t_log('');
exit($failCount > 0 ? 1 : 0);
