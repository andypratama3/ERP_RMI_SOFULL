<?php
declare(strict_types=1);
/**
 * Quick PO checker — menampilkan semua data terkait satu PO code.
 * Jalankan: php tools/check_po.php BMHP-PO-BGR-260416-003
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }

$poCodeArg = $argv[1] ?? '';
if ($poCodeArg === '') { echo "Usage: php tools/check_po.php <PO_CODE>\n"; exit(1); }

$root = dirname(__DIR__);
@session_start();
$_SESSION['user'] = 'SmokeSYS_SYS';
$_SESSION['username'] = 'SmokeSYS_SYS';
$_SESSION['user_id'] = 999999;
$_SESSION['role'] = 'sys';
$_SESSION['level'] = 'SYS';
$_SESSION['department'] = 'SYS';
$_SERVER['REQUEST_METHOD'] = 'GET';

error_reporting(E_ALL & ~E_WARNING);
require_once $root . '/_shared/db.php';
ob_start();
require_once $root . '/purchases/_purchases_lib.php';
ob_end_clean();
error_reporting(E_ALL);

$pdo = function_exists('p_pdo') ? p_pdo() : rmi_db_pdo();

echo "\n══════════════════════════════════════════\n";
echo " CHECK PO: {$poCodeArg}\n";
echo "══════════════════════════════════════════\n\n";

// 1. PO header
$st = $pdo->prepare("SELECT * FROM purchases_po WHERE po_code=? LIMIT 1");
$st->execute([$poCodeArg]);
$po = $st->fetch(PDO::FETCH_ASSOC);
if (!$po) {
    echo "❌ PO tidak ditemukan di database.\n\n";
    // Check if soft-deleted
    $st2 = $pdo->prepare("SELECT id, po_code, status, deleted_at FROM purchases_po WHERE po_code=? AND deleted_at IS NOT NULL LIMIT 1");
    $st2->execute([$poCodeArg]);
    $del = $st2->fetch(PDO::FETCH_ASSOC);
    if ($del) {
        echo "  (PO ditemukan tapi DELETED: deleted_at={$del['deleted_at']})\n";
    }
    exit(0);
}

$poId = (int)$po['id'];
echo "── PO Header ──\n";
foreach (['id','po_code','po_date','pr_id','manufacture_id','office_code','currency','status','total_amount','category','forwarder_vendor_id','forwarder_status','created_by','created_at','deleted_at'] as $k) {
    if (array_key_exists($k, $po)) echo "  {$k}: " . ($po[$k] ?? 'NULL') . "\n";
}

// 2. PR (if linked)
if (!empty($po['pr_id'])) {
    echo "\n── Linked PR ──\n";
    $st = $pdo->prepare("SELECT id, pr_code, status, category, office_code, created_by FROM wqs_pr WHERE id=?");
    $st->execute([(int)$po['pr_id']]);
    $pr = $st->fetch(PDO::FETCH_ASSOC);
    if ($pr) {
        foreach ($pr as $k => $v) echo "  {$k}: " . ($v ?? 'NULL') . "\n";
    } else {
        echo "  PR id={$po['pr_id']} tidak ditemukan.\n";
    }
}

// 3. PO Items
echo "\n── PO Items ──\n";
$st = $pdo->prepare("SELECT id, line_no, sku, products_name, qty, unit_price, subtotal, deleted_at FROM purchases_po_items WHERE po_id=?");
$st->execute([$poId]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$items) { echo "  (tidak ada items)\n"; }
foreach ($items as $i) {
    $del = $i['deleted_at'] ? ' [DELETED]' : '';
    echo "  #{$i['line_no']} SKU={$i['sku']} qty={$i['qty']} price={$i['unit_price']} sub={$i['subtotal']}{$del}\n";
}

// 4. Import Control
echo "\n── Import Control ──\n";
$st = $pdo->prepare("SELECT * FROM purchases_import_control WHERE po_id=? LIMIT 1");
$st->execute([$poId]);
$ic = $st->fetch(PDO::FETCH_ASSOC);
if (!$ic) { echo "  (tidak ada record)\n"; }
else {
    foreach (['production_start_date','production_done_date','pickup_date','etd','eta','arrived_id_date','arrived_warehouse_date','note_prod','note_ship','updated_by'] as $k) {
        if (array_key_exists($k, $ic)) echo "  {$k}: " . ($ic[$k] ?? 'NULL') . "\n";
    }
}

// 5. Forwarder Quotes
echo "\n── Forwarder Quotes ──\n";
$st = $pdo->prepare("SELECT q.id, q.status, q.currency, q.total_cost, q.leadtime_days, v.vendors_name FROM purchases_forwarder_quotes q LEFT JOIN master_vendors v ON v.id=q.vendor_id WHERE q.po_id=? AND q.deleted_at IS NULL ORDER BY q.id");
$st->execute([$poId]);
$quotes = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$quotes) { echo "  (tidak ada quotes)\n"; }
foreach ($quotes as $q) {
    echo "  Q#{$q['id']} {$q['status']} | {$q['vendors_name']} | {$q['currency']} {$q['total_cost']} | {$q['leadtime_days']}d\n";
}

// 6. Forwarding Docs
echo "\n── Forwarding Docs ──\n";
$st = $pdo->prepare("SELECT id, doc_type, doc_number, doc_date, uploaded_by FROM purchases_forwarding_docs WHERE po_id=? AND deleted_at IS NULL ORDER BY id");
$st->execute([$poId]);
$docs = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$docs) { echo "  (tidak ada docs)\n"; }
foreach ($docs as $d) {
    echo "  #{$d['id']} {$d['doc_type']} no={$d['doc_number']} date={$d['doc_date']} by={$d['uploaded_by']}\n";
}

// 7. CEISA/PIB
echo "\n── CEISA/PIB ──\n";
$st = $pdo->prepare("SELECT * FROM purchases_ceisa_pib WHERE po_id=? LIMIT 1");
$st->execute([$poId]);
$pib = $st->fetch(PDO::FETCH_ASSOC);
if (!$pib) { echo "  (tidak ada record)\n"; }
else {
    foreach (['id','ceisa_status','submitted_date','reject_count','bc11_no','noa_no','billing_aju_no','billing_amount','sppb_no','final_pib_no','updated_by'] as $k) {
        if (array_key_exists($k, $pib)) echo "  {$k}: " . ($pib[$k] ?? 'NULL') . "\n";
    }

    // PIB Payments
    echo "\n── PIB Payments ──\n";
    $st = $pdo->prepare("SELECT id, pay_code, pay_date, amount, method, bank_name, reference FROM purchases_ceisa_payment WHERE pib_id=? AND deleted_at IS NULL ORDER BY id");
    $st->execute([(int)$pib['id']]);
    $pays = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$pays) { echo "  (tidak ada payments)\n"; }
    foreach ($pays as $p) {
        echo "  {$p['pay_code']} date={$p['pay_date']} amt={$p['amount']} {$p['method']} bank={$p['bank_name']} ref={$p['reference']}\n";
    }
}

// 8. Forwarder Invoice
echo "\n── Forwarder Invoice ──\n";
$st = $pdo->prepare("SELECT f.id, f.fap_code, f.invoice_type, f.currency, f.subtotal, f.tax_amount, f.total_amount, f.status, v.vendors_name
    FROM purchases_forwarder_invoice f LEFT JOIN master_vendors v ON v.id=f.vendor_id WHERE f.po_id=? AND f.deleted_at IS NULL ORDER BY f.id");
$st->execute([$poId]);
$faps = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$faps) { echo "  (tidak ada invoice)\n"; }
foreach ($faps as $f) {
    echo "  {$f['fap_code']} {$f['invoice_type']} | {$f['vendors_name']} | {$f['currency']} sub={$f['subtotal']} tax={$f['tax_amount']} total={$f['total_amount']} status={$f['status']}\n";

    // Payments for this invoice
    $st2 = $pdo->prepare("SELECT pay_code, pay_date, amount, method FROM purchases_forwarder_payment WHERE fap_id=? AND deleted_at IS NULL ORDER BY id");
    $st2->execute([(int)$f['id']]);
    $fpays = $st2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fpays as $fp) {
        echo "    └ {$fp['pay_code']} date={$fp['pay_date']} amt={$fp['amount']} {$fp['method']}\n";
    }
}

// 9. WQS Incoming
echo "\n── WQS Incoming ──\n";
$st = $pdo->prepare("SELECT id, incoming_code, received_date, office_code, created_by, deleted_at FROM wqs_incoming WHERE po_code=? ORDER BY id");
$st->execute([$poCodeArg]);
$incs = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$incs) { echo "  (tidak ada incoming)\n"; }
foreach ($incs as $inc) {
    $del = $inc['deleted_at'] ? ' [DELETED]' : '';
    echo "  #{$inc['id']} {$inc['incoming_code']} date={$inc['received_date']} office={$inc['office_code']} by={$inc['created_by']}{$del}\n";
}

echo "\n══════════════════════════════════════════\n";
echo " Done.\n";
echo "══════════════════════════════════════════\n\n";
