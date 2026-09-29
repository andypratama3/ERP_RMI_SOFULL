<?php
require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/../_shared/actor_stamp.php';
require_once __DIR__ . '/../_shared/rmi_branch_guard.php';
$__sdv_depo_restricted = function_exists('rmi_is_depo_branch_session') && rmi_is_depo_branch_session();
if (!$__sdv_depo_restricted) {
    require_any_permission(['SALES.VIEW']);
}
// sales_do_view.php
// View / Print Delivery Order (CRM → WQS → SCM)

// TAMPILKAN ERROR SAAT DEV (boleh di-nonaktifkan nanti di production)
// --- DB (centralized) ---
$pdo = db_pdo();

// Schema helpers tanpa information_schema (hosting ERP membatasi akses ke DB sistem).
function sdv_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    try {
        // NOTE: placeholder (?) TIDAK valid di SHOW TABLES LIKE (MySQL 1064).
        // Interpolasi aman karena $table sudah divalidasi regex di atas.
        $rows = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchAll(PDO::FETCH_NUM) ?: [];
        return count($rows) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function sdv_cols(PDO $pdo, string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn($r) => strtolower((string)($r['Field'] ?? '')), $rows ?: []);
    } catch (Throwable $e) {
        return [];
    }
}
function sdv_effective_item_classification(array $item): array
{
    $bg = strtoupper(trim((string)($item['business_group'] ?? '')));
    $cat = strtoupper(trim((string)($item['category'] ?? '')));
    $mbg = strtoupper(trim((string)($item['_master_business_group'] ?? '')));
    $mcat = strtoupper(trim((string)($item['_master_category'] ?? '')));

    if (!in_array($cat, ['BMHP','ALKES','AKSESORIS'], true) && in_array($mcat, ['BMHP','ALKES','AKSESORIS'], true)) {
        $cat = $mcat;
    }
    if (!in_array($bg, ['BMHP','UNIT_ACC'], true) && in_array($mbg, ['BMHP','UNIT_ACC'], true)) {
        $bg = $mbg;
    }
    if (!in_array($bg, ['BMHP','UNIT_ACC'], true)) {
        $bg = (strtoupper(trim((string)($item['category'] ?? ''))) === 'UNIT_ACC' || in_array($cat, ['ALKES','AKSESORIS'], true)) ? 'UNIT_ACC' : 'BMHP';
    }
    if (!in_array($cat, ['BMHP','ALKES','AKSESORIS'], true)) {
        $cat = $bg === 'BMHP' ? 'BMHP' : 'UNIT_ACC'; // legacy: tampil apa adanya, jangan menebak ALKES/AKSESORIS.
    }
    return [$bg, $cat];
}

/**
 * Ubah path bukti order yang tersimpan (uploads/...) menjadi URL project-root.
 * Hanya untuk display; tidak mengubah data/status/workflow DO.
 */
function sdv_project_file_url(string $storedPath, string $baseProject): string
{
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '') return '';
    if (preg_match('~^https?://~i', $storedPath)) return $storedPath;

    // Jangan izinkan traversal dari nilai path database.
    $storedPath = ltrim($storedPath, '/');
    if (str_contains($storedPath, '../') || str_contains($storedPath, '..\\')) return '';

    return rtrim($baseProject, '/') . '/' . $storedPath;
}

// --------------------------------------------------------
// AMBIL PARAMETER DO
// --------------------------------------------------------
$do_id = 0;

if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $do_id = (int)$_GET['id'];
} elseif (isset($_GET['do_code']) && $_GET['do_code'] !== '') {
    // fallback: ambil via do_code kalau suatu saat dipakai
    $stmt = $pdo->prepare("SELECT id FROM sales_do WHERE do_code = :dc");
    $stmt->execute([':dc' => $_GET['do_code']]);
    $do_id = (int)$stmt->fetchColumn();
}

if ($do_id <= 0) {
    die("DO Code tidak ditemukan.");
}

// --------------------------------------------------------
// AMBIL HEADER DO + CUSTOMER + OFFICE
// --------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT
        d.*,
        c.customers_name,
        c.address       AS cust_address,
        c.city          AS cust_city,
        c.phone         AS cust_phone,
        c.email         AS cust_email,
        o.office_name,
        o.address       AS office_address,
        o.city          AS office_city,
        o.phone         AS office_phone
    FROM sales_do d
    LEFT JOIN master_customers c ON c.customers_code = d.customers_code
    LEFT JOIN master_office    o ON o.office_code   = d.office_code
    WHERE d.id = :id
");
$stmt->execute([':id' => $do_id]);
$do = $stmt->fetch();

if (!$do) {
    die("DO Code tidak ditemukan.");
}

// ── Office scope guard (BRANCH hanya bisa view DO kantor sendiri) ─────────
$_sdv_user  = function_exists('auth_user') ? auth_user() : [];
$_sdv_dept  = strtoupper(trim((string)($_sdv_user['department'] ?: $_sdv_user['level'] ?: '')));
$_sdv_role  = strtoupper(trim((string)($_sdv_user['role'] ?? '')));
$_sdv_office = strtoupper(trim((string)($_sdv_user['office_code'] ?? '')));
$_sdv_is_branch = in_array($_sdv_dept, ['BRANCH'], true)
               && !in_array($_sdv_role, ['SYS','ADMIN','SUPERADMIN'], true)
               && $_sdv_office !== '';
if ($_sdv_is_branch && strtoupper((string)($do['office_code'] ?? '')) !== $_sdv_office) {
    http_response_code(403);
    die("<div style='font-family:sans-serif;padding:40px'><b>Akses ditolak.</b> DO ini bukan milik kantor Anda (" . htmlspecialchars($_sdv_office, ENT_QUOTES, 'UTF-8') . ").</div>");
}

// --------------------------------------------------------
// AMBIL INFO DELIVERY (VENDOR) JIKA ADA
// --------------------------------------------------------
$delivery_vendor_name = '';
try {
    $vid = (int)($do['delivery_vendor_id'] ?? 0);
    if ($vid > 0) {
        $stV = $pdo->prepare("SELECT vendors_name, vendors_code FROM master_vendors WHERE id=?");
        $stV->execute([$vid]);
        $vr = $stV->fetch(PDO::FETCH_ASSOC);
        if ($vr) $delivery_vendor_name = trim(($vr['vendors_name'] ?? '') . ' (' . ($vr['vendors_code'] ?? '') . ')');
    }
} catch (Throwable $e) {}

// --------------------------------------------------------
// AMBIL DETAIL ITEM
// --------------------------------------------------------
$itemCols = sdv_cols($pdo, 'sales_do_items');
$masterProductCols = sdv_cols($pdo, 'master_products');
$extraItemSelect = [];
if (in_array('category', $masterProductCols, true)) $extraItemSelect[] = "p.category AS _master_category";
if (in_array('business_group', $masterProductCols, true)) $extraItemSelect[] = "p.business_group AS _master_business_group";
$extraItemSql = $extraItemSelect ? (",\n        " . implode(",\n        ", $extraItemSelect)) : '';
$stmt = $pdo->prepare("
    SELECT i.*{$extraItemSql}
    FROM sales_do_items i
    LEFT JOIN master_products p ON p.id = i.product_id
    WHERE i.do_id = :id
    ORDER BY i.line_no ASC, i.id ASC
");
$stmt->execute([':id' => $do_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$itemById = [];
foreach ($items as &$itClass) {
    [$itClass['_business_group'], $itClass['_category']] = sdv_effective_item_classification($itClass);
    $itemById[(int)($itClass['id'] ?? 0)] = $itClass;
}
unset($itClass);

$doBusinessGroup = strtoupper(trim((string)($do['business_group'] ?? '')));
if (!in_array($doBusinessGroup, ['BMHP','UNIT_ACC'], true)) {
    $doBusinessGroup = strtoupper(trim((string)($do['category'] ?? ''))) === 'UNIT_ACC' ? 'UNIT_ACC' : 'BMHP';
}
// Legacy guard: bila header lama default BMHP tetapi item sudah snapshot UNIT_ACC, tampilkan identitas item yang benar.
foreach ($items as $itClass) {
    if (($itClass['_business_group'] ?? '') === 'UNIT_ACC') { $doBusinessGroup = 'UNIT_ACC'; break; }
}
// --------------------------------------------------------
// AMBIL NO PO DARI ITEM DO
// --------------------------------------------------------
$hasItemNoPo = false;
try {
    $chkNoPo = $pdo->prepare("SHOW COLUMNS FROM sales_do_items LIKE 'no_po'");
    $chkNoPo->execute();
    $hasItemNoPo = (bool)$chkNoPo->fetch();
} catch (Throwable $e) {
    $hasItemNoPo = false;
}

// Kolom Exp Date pada tabel item DO untuk ditampilkan di baris produk.
// No PO tetap diringkas di header DO agar tidak dobel pada tabel item.
$hasItemExpDate = false;
try {
    $chkExpDate = $pdo->prepare("SHOW COLUMNS FROM sales_do_items LIKE 'exp_date'");
    $chkExpDate->execute();
    $hasItemExpDate = (bool)$chkExpDate->fetch();
} catch (Throwable $e) {
    $hasItemExpDate = false;
}

$noPoList = [];
if ($hasItemNoPo) {
    foreach ($items as $itNoPo) {
        $np = trim((string)($itNoPo['no_po'] ?? ''));
        if ($np !== '') {
            $noPoList[$np] = true;
        }
    }
}
$no_po_print = implode(', ', array_keys($noPoList));
// Helper format rupiah
function sdv_return_status_label(string $status): string
{
    return match (strtolower(trim($status))) {
        'return_requested'     => 'Menunggu SCM',
        'return_received'      => 'Diterima SCM',
        'return_stocked'       => 'Stok Diproses',
        'return_scm_completed' => 'Selesai SCM',
        'wait_act_review'      => 'Menunggu ACT',
        'wait_fin_adjustment'  => 'Menunggu FIN',
        'closed'               => 'Retur Selesai',
        'cancelled'            => 'Dibatalkan',
        default                => strtoupper(trim($status)),
    };
}

function rupiah($angka)
{
    return 'Rp ' . number_format((float)$angka, 2, ',', '.');
}

/**
 * True bila nilai adalah kode dept/role (data lama), bukan username orang.
 */
function sdv_is_dept_code(string $v): bool
{
    static $codes = ['WQS','SCM','CRM','FIN','ACT','PQP','MPR','HRL','ITC','SYS','ADMIN','SUPERADMIN','MANAGER','STAFF','BRANCH','SYSTEM'];
    return in_array(strtoupper(trim($v)), $codes, true);
}
// Label & relasi pelaku:helpers pusat _shared/actor_stamp.php (sudah include via bootstrap).
// sdv_is_dept_code() di atas tetap dipakai sebagai pagar data lama.

function sdv_format_exp_date($value): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00' || strtoupper($value) === 'NULL') {
        return '-';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    return date('d-m-Y', $ts);
}

function do_item_lot_seri_display(PDO $pdo, array $it, array $do): string
{
    /*
     * Prinsip tampilan DO:
     * - Yang dicetak ke customer adalah nomor LOT / SERI asli.
     * - Jangan tampilkan ROW-xx karena itu hanya ID internal database stok.
     * - Jika sales_do_items.serial_lot berisi "AUTO FIFO: ROW-84(5)", sistem akan mencoba
     *   mencari kembali LOT/SERI dari wqs_stock_by_office.id = 84.
     * - Jika di master stok memang belum ada nomor lot/seri, tampilkan "-" agar tidak
     *   mencetak data internal yang membingungkan customer.
     */

    $candidateCols = [
        'serial_lot',
        'lot_number',
        'lot_no',
        'serial_number',
        'serial_no',
        'batch_no',
        'batch_number',
        'fifo_lot',
        'fifo_serial_lot',
        'allocation_lot',
        'stock_lot',
    ];

    $raw = '';
    foreach ($candidateCols as $col) {
        if (array_key_exists($col, $it)) {
            $v = trim((string)($it[$col] ?? ''));
            if ($v !== '' && $v !== '-' && strtoupper($v) !== 'NULL') {
                $raw = $v;
                break;
            }
        }
    }

    $lookupStockRowLot = function(int $rowId) use ($pdo): string {
        if ($rowId <= 0) return '';
        try {
            if (!sdv_table_exists($pdo, 'wqs_stock_by_office')) return '';
            $stockCols = sdv_cols($pdo, 'wqs_stock_by_office');
            if (!in_array('id', $stockCols, true)) return '';
            $mpCols = sdv_cols($pdo, 'master_products');

            // FIX: lot/serial bisa berada di wqs_stock_by_office ATAU master_products.
            // Jika yang tersimpan di sales_do_items hanya AUTO FIFO: ROW-xx,
            // tampilkan nomor lot/seri asli, bukan ROW internal.
            $lotCandidates = ['lot_number','lot_no','serial_lot','serial_number','serial_no','batch_number','batch_no'];
            $selectParts = [];
            foreach ($lotCandidates as $c) {
                if (in_array($c, $stockCols, true)) {
                    $selectParts[] = "NULLIF(TRIM(CAST(s.`{$c}` AS CHAR)), '')";
                }
                if (in_array($c, $mpCols, true)) {
                    $selectParts[] = "NULLIF(TRIM(CAST(p.`{$c}` AS CHAR)), '')";
                }
            }
            if (!$selectParts) return '';

            $lotExpr = "COALESCE(" . implode(",", $selectParts) . ")";
            $st = $pdo->prepare("
                SELECT {$lotExpr} AS lot_value
                FROM wqs_stock_by_office s
                LEFT JOIN master_products p ON p.id = s.product_id
                WHERE s.id = ?
                LIMIT 1
            ");
            $st->execute([$rowId]);
            return trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            return '';
        }
    };

    $cleanAutoFifo = function(string $value) use ($lookupStockRowLot): string {
        $value = trim($value);
        if ($value === '') return '';

        $value = preg_replace('/^\s*AUTO\s*FIFO\s*:\s*/i', '', $value);
        $chunks = preg_split('/\s*\+\s*/', $value);
        $out = [];

        foreach ($chunks as $chunk) {
            $chunk = trim((string)$chunk);
            if ($chunk === '') continue;

            $qty = '';
            if (preg_match('/\(([-+]?[0-9]*\.?[0-9]+)\)\s*$/', $chunk, $qm)) {
                $qty = $qm[1];
                $chunk = trim(substr($chunk, 0, -strlen($qm[0])));
            }

            if (preg_match('/^ROW[-\s]*([0-9]+)$/i', $chunk, $rm)) {
                $lot = $lookupStockRowLot((int)$rm[1]);
                if ($lot === '') continue;
                $chunk = $lot;
            }

            if ($chunk === '' || $chunk === '-' || strtoupper($chunk) === 'NULL') continue;
            $out[] = $qty !== '' ? ($chunk . ' (' . (float)$qty . ')') : $chunk;
        }

        return implode(' + ', $out);
    };

    if ($raw !== '') {
        $clean = $cleanAutoFifo($raw);
        if ($clean !== '') return $clean;
    }

    // Fallback: cari tabel alokasi DO FIFO jika ada.
    $doId = (int)($do['id'] ?? 0);
    $itemId = (int)($it['id'] ?? 0);
    $productId = (int)($it['product_id'] ?? 0);
    $sku = trim((string)($it['sku'] ?? ''));

    $allocationTables = [
        'sales_do_item_lots',
        'sales_do_stock_allocations',
        'sales_do_fifo_allocations',
        'sales_do_lot_allocations',
        'sales_do_item_allocations',
    ];
    $lotCols = ['serial_lot','lot_number','lot_no','serial_number','serial_no','batch_no','batch_number'];
    $qtyCols = ['qty','alloc_qty','allocated_qty','qty_out','stock_qty'];

    foreach ($allocationTables as $tbl) {
        try {
            if (!sdv_table_exists($pdo, $tbl)) continue;

            $cols = [];
            foreach (sdv_cols($pdo, $tbl) as $c) $cols[strtolower($c)] = $c;

            $where = [];
            $params = [];
            if (isset($cols['do_item_id']) && $itemId > 0) { $where[] = "`{$cols['do_item_id']}` = ?"; $params[] = $itemId; }
            elseif (isset($cols['item_id']) && $itemId > 0) { $where[] = "`{$cols['item_id']}` = ?"; $params[] = $itemId; }
            elseif (isset($cols['sales_do_item_id']) && $itemId > 0) { $where[] = "`{$cols['sales_do_item_id']}` = ?"; $params[] = $itemId; }

            if (isset($cols['do_id']) && $doId > 0) { $where[] = "`{$cols['do_id']}` = ?"; $params[] = $doId; }
            elseif (isset($cols['sales_do_id']) && $doId > 0) { $where[] = "`{$cols['sales_do_id']}` = ?"; $params[] = $doId; }
            if (!$where) continue;

            if (isset($cols['product_id']) && $productId > 0) { $where[] = "`{$cols['product_id']}` = ?"; $params[] = $productId; }
            elseif (isset($cols['sku']) && $sku !== '') { $where[] = "REPLACE(REPLACE(UPPER(TRIM(`{$cols['sku']}`)), ' ', ''), CHAR(9), '') = REPLACE(REPLACE(UPPER(TRIM(?)), ' ', ''), CHAR(9), '')"; $params[] = $sku; }

            $lotExprParts = [];
            foreach ($lotCols as $lc) if (isset($cols[$lc])) $lotExprParts[] = "`{$cols[$lc]}`";
            if (!$lotExprParts) continue;

            $qtyExpr = "''";
            foreach ($qtyCols as $qc) if (isset($cols[$qc])) { $qtyExpr = "`{$cols[$qc]}`"; break; }

            $lotExpr = "COALESCE(" . implode(",", $lotExprParts) . ")";
            $sql = "SELECT {$lotExpr} AS lot_value, {$qtyExpr} AS qty_value FROM `{$tbl}` WHERE " . implode(" AND ", $where) . " ORDER BY id ASC";
            $st = $pdo->prepare($sql);
            $st->execute($params);

            $parts = [];
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $lot = trim((string)($r['lot_value'] ?? ''));
                if ($lot === '' || $lot === '-' || strtoupper($lot) === 'NULL') continue;
                $qty = trim((string)($r['qty_value'] ?? ''));
                $parts[] = ($qty !== '' && is_numeric($qty)) ? ($lot . ' (' . (float)$qty . ')') : $lot;
            }
            if ($parts) return implode(' + ', $parts);
        } catch (Throwable $e) {
            // Abaikan fallback table yang tidak cocok dengan schema.
        }
    }

    return '-';
}


// Dokumen pendukung dari Customer Portal
$portalDocs = [];
try {
    if (sdv_table_exists($pdo, 'sales_do_portal_docs')) {
        $st = $pdo->prepare("SELECT id, doc_type, file_name, uploaded_by, created_at FROM sales_do_portal_docs WHERE do_id = ? ORDER BY id DESC");
        $st->execute([$do_id]);
        $portalDocs = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

// Riwayat Retur DO
$returnRows = [];
$returnItemsByReturn = [];
try {
    if (sdv_table_exists($pdo, 'sales_do_returns')) {
        $st = $pdo->prepare("SELECT * FROM sales_do_returns WHERE do_id = ? ORDER BY id DESC");
        $st->execute([$do_id]);
        $returnRows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($returnRows) {
            $ids = array_map(fn($r)=>(int)$r['id'], $returnRows);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stI = $pdo->prepare("SELECT * FROM sales_do_return_items WHERE return_id IN ($ph) ORDER BY return_id ASC, id ASC");
            $stI->execute($ids);
            while ($ri = $stI->fetch(PDO::FETCH_ASSOC)) {
                $origItem = $itemById[(int)($ri['do_item_id'] ?? 0)] ?? [];
                $riClass = array_merge($origItem, $ri);
                // Kolom snapshot return menang jika valid; jika legacy kosong gunakan item DO asal/master.
                if (empty($ri['business_group']) && isset($origItem['_business_group'])) $riClass['business_group'] = $origItem['_business_group'];
                if (empty($ri['category']) && isset($origItem['_category'])) $riClass['category'] = $origItem['_category'];
                [$ri['_business_group'], $ri['_category']] = sdv_effective_item_classification($riClass);
                $returnItemsByReturn[(int)$ri['return_id']][] = $ri;
            }
        }
    }
} catch (Throwable $e) {}

// Lineage Revisi / DO Pengganti — read-only, tidak mengubah workflow.
$lineage = ['replacement_parent'=>null,'replacement_return'=>null,'replacement_children'=>[],'revision_parent'=>null];
try {
    if (!empty($do['replacement_for_do_id'])) {
        $stL=$pdo->prepare("SELECT id,do_code,status FROM sales_do WHERE id=? LIMIT 1");
        $stL->execute([(int)$do['replacement_for_do_id']]);
        $lineage['replacement_parent']=$stL->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!empty($do['replacement_return_id']) && sdv_table_exists($pdo,'sales_do_returns')) {
        $stL=$pdo->prepare("SELECT id,return_code,status,commercial_effect FROM sales_do_returns WHERE id=? LIMIT 1");
        $stL->execute([(int)$do['replacement_return_id']]);
        $lineage['replacement_return']=$stL->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (sdv_table_exists($pdo,'sales_do') && in_array('replacement_for_do_id', sdv_cols($pdo,'sales_do'), true)) {
        $stL=$pdo->prepare("SELECT id,do_code,status FROM sales_do WHERE replacement_for_do_id=? AND LOWER(COALESCE(status,'')) NOT IN ('cancelled','canceled','rejected','reject','void','voided') ORDER BY id DESC");
        $stL->execute([$do_id]);
        $lineage['replacement_children']=$stL->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if (!empty($do['revision_of_do_id'])) {
        $stL=$pdo->prepare("SELECT id,do_code,status FROM sales_do WHERE id=? LIMIT 1");
        $stL->execute([(int)$do['revision_of_do_id']]);
        $lineage['revision_parent']=$stL->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {}

// Hitung total kalau mau double-check
$total_amount = (float)($do['total_amount'] ?? 0);
$tax_amount   = (float)($do['tax_amount'] ?? 0);
$grand_total  = (float)($do['grand_total'] ?? ($total_amount + $tax_amount));

$do_code        = $do['do_code'] ?? '';
$tracking_code  = $do['tracking_code'] ?? $do_code;

$delivery_mode  = strtoupper((string)($do['delivery_mode'] ?? 'INTERNAL'));
if (!in_array($delivery_mode, ['INTERNAL','VENDOR'], true)) $delivery_mode='INTERNAL';
$do_date        = $do['do_date'] ?? date('Y-m-d');
$do_date_print  = date('d-m-Y', strtotime($do_date));

$office_name    = $do['office_name'] ?? $do['office_code'];
$office_city    = $do['office_city'] ?? '';
$office_address = $do['office_address'] ?? '';
$office_phone   = $do['office_phone'] ?? '';

$cust_name      = $do['customers_name'] ?? $do['customers_code'];
$cust_address   = $do['cust_address'] ?? '';
$cust_city      = $do['cust_city'] ?? '';
$cust_phone     = $do['cust_phone'] ?? '';
$cust_email     = $do['cust_email'] ?? '';

$ship_address   = $do['shipping_address'] ?? $cust_address;
$customer_pic   = $do['customer_pic'] ?? '';
$customer_phone = $do['customer_phone'] ?? '';

$note           = $do['note'] ?? '';
$tax_code       = $do['tax_code'] ?? '';
$tax_rate       = (float)($do['tax_rate_percent'] ?? 0);

$printed_at     = date('d-m-Y H:i:s');

// --------------------------------------------------------
// BUKTI SERAH TERIMA SCM / TTD DIGITAL (READ-ONLY)
// --------------------------------------------------------
// TTD disimpan oleh scm_do_tasks.php sebagai data:image/png;base64,...
// Di halaman Print DO kita hanya merender bukti yang valid; tidak mengubah workflow/status.
$scm_signature_raw = trim((string)($do['scm_signature_data'] ?? ''));
$scm_signature_src = '';
if ($scm_signature_raw !== '' && preg_match('~^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$~', $scm_signature_raw, $m)) {
    $decodedSig = base64_decode(preg_replace('/\s+/', '', (string)$m[1]), true);
    if ($decodedSig !== false && strlen($decodedSig) > 64) {
        $imgInfo = @getimagesizefromstring($decodedSig);
        if (is_array($imgInfo) && (($imgInfo['mime'] ?? '') === 'image/png')) {
            $scm_signature_src = 'data:image/png;base64,' . base64_encode($decodedSig);
        }
    }
}
$scm_delivered_at_print = trim((string)($do['scm_delivered_at'] ?? ''));
$scm_delivered_by_print = trim((string)($do['scm_delivered_by'] ?? ''));
$scm_delivery_photo_url = '';
$scm_delivery_video_url = '';

// --------------------------------------------------------
// AKUN PELAKSANA WQS / SCM UNTUK PRINT DO (READ-ONLY)
// --------------------------------------------------------
// Prioritas WQS: field khusus bila tersedia, lalu audit WQS_READY sebagai sumber historis.
// Prioritas SCM kirim: akun yang mengubah ke ON DELIVERY, fallback akun yang menyelesaikan DELIVERED.
$wqs_prepared_by_print = '';
foreach (['wqs_ready_by','wqs_completed_by','wqs_updated_by','wqs_by'] as $k) {
    $v = trim((string)($do[$k] ?? ''));
    if ($v !== '' && !sdv_is_dept_code($v)) { $wqs_prepared_by_print = $v; break; }
}
$wqs_prepared_at_print = trim((string)($do['wqs_ready_at'] ?? ''));

if ($wqs_prepared_by_print === '' && sdv_table_exists($pdo, 'system_audit_logs')) {
    try {
        // Fallback berlapis: READY dulu, lalu START/SAVE — DO yang sedang
        // diproses WQS (belum READY) tetap ketahuan pelakunya.
        $stWqsActor = $pdo->prepare("
            SELECT username, created_at
            FROM system_audit_logs
            WHERE module='sales_do'
              AND action IN ('WQS_READY','WQS_START','WQS_SAVE')
              AND record_code=?
            ORDER BY FIELD(action,'WQS_READY','WQS_START','WQS_SAVE'), created_at DESC, id DESC
            LIMIT 1
        ");
        $stWqsActor->execute([(string)($do['do_code'] ?? '')]);
        $wqsAuditActor = $stWqsActor->fetch(PDO::FETCH_ASSOC) ?: [];
        $wqs_prepared_by_print = trim((string)($wqsAuditActor['username'] ?? ''));
        if ($wqs_prepared_at_print === '') {
            $wqs_prepared_at_print = trim((string)($wqsAuditActor['created_at'] ?? ''));
        }
    } catch (Throwable $e) {
        // fail-soft: print DO tetap jalan bila audit legacy tidak tersedia.
    }
}

$scm_sent_by_print = '';
foreach (['scm_on_delivery_by','scm_delivered_by','scm_updated_by','last_updated_by'] as $k) {
    $v = trim((string)($do[$k] ?? ''));
    if ($v !== '' && !sdv_is_dept_code($v)) { $scm_sent_by_print = $v; break; }
}
$scm_sent_at_print = trim((string)($do['scm_on_delivery_at'] ?? ''));
if ($scm_sent_at_print === '') {
    $scm_sent_at_print = trim((string)($do['scm_delivered_at'] ?? ''));
}

// Lapisan terakhir: sales_do_audit (actor_name = username asli per transisi).
if (($wqs_prepared_by_print === '' || $scm_sent_by_print === '') && sdv_table_exists($pdo, 'sales_do_audit')) {
    try {
        $stAud = $pdo->prepare("SELECT actor_dept, actor_name, created_at FROM sales_do_audit WHERE do_id=? ORDER BY id DESC LIMIT 20");
        $stAud->execute([(int)$do_id]);
        foreach ($stAud->fetchAll(PDO::FETCH_ASSOC) as $ar) {
            $dept = strtoupper(trim((string)($ar['actor_dept'] ?? '')));
            $nm = trim((string)($ar['actor_name'] ?? ''));
            if ($nm === '') continue;
            if ($wqs_prepared_by_print === '' && $dept === 'WQS') {
                $wqs_prepared_by_print = $nm;
                if ($wqs_prepared_at_print === '') $wqs_prepared_at_print = trim((string)($ar['created_at'] ?? ''));
            }
            if ($scm_sent_by_print === '' && $dept === 'SCM') {
                $scm_sent_by_print = $nm;
                if ($scm_sent_at_print === '') $scm_sent_at_print = trim((string)($ar['created_at'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        // fail-soft
    }
}

// Pembuat DO (CRM): aksi paling awal, agar tidak miss.
$crm_created_by_print = '';
$crm_created_at_print = '';
if (sdv_table_exists($pdo, 'sales_do_audit')) {
    try {
        $stC = $pdo->prepare("SELECT actor_name, created_at FROM sales_do_audit WHERE do_id=? ORDER BY id ASC LIMIT 1");
        $stC->execute([(int)$do_id]);
        $cRow = $stC->fetch(PDO::FETCH_ASSOC) ?: [];
        $crm_created_by_print = rmi_actor_label($pdo, trim((string)($cRow['actor_name'] ?? '')));
        $crm_created_at_print = trim((string)($cRow['created_at'] ?? ''));
    } catch (Throwable $e) { /* fail-soft */ }
}

// Hubungkan pelaku ke kode employee (username -> holder -> master_employees)
// sekaligus nama akunnya, untuk bukti relasi di blok tanda tangan.
// *_raw menyimpan username sebelum dilabeli; dipakai rmi_actor_stamp()
// supaya lookup relasi tetap memakai akun asli.
$wqs_prepared_by_raw = $wqs_prepared_by_print;
$scm_sent_by_raw     = $scm_sent_by_print;
$scm_delivered_by_raw = $scm_delivered_by_print;
$wqs_info_print   = rmi_actor_info($pdo, $wqs_prepared_by_raw);
$scm_sent_info    = rmi_actor_info($pdo, $scm_sent_by_raw);
$scm_deliv_info   = rmi_actor_info($pdo, $scm_delivered_by_raw);
$wqs_prepared_by_print     = $wqs_info_print['label'];
$scm_sent_by_print         = $scm_sent_info['label'];
$scm_delivered_by_print    = $scm_deliv_info['label'];


require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();
// mode=print: otomatis buka dialog cetak saat halaman selesai dimuat.
$isPrintMode = strtolower(trim((string)($_GET['mode'] ?? ''))) === 'print';
$scm_delivery_photo_url = sdv_project_file_url((string)($do['scm_delivery_photo'] ?? ''), $baseProject);
$scm_delivery_video_url = sdv_project_file_url((string)($do['scm_delivery_video'] ?? ''), $baseProject);
$returnStatus = strtolower(trim((string)($do['return_status'] ?? '')));
$returnLastAt = trim((string)($do['return_last_at'] ?? ''));
$headerActions = [
    ['label' => 'Back to DO', 'url' => 'sales_do.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Print', 'url' => 'javascript:window.print()', 'class' => 'btn btn-sm btn-outline-light'],
];
if (!$__sdv_depo_restricted) {
    $headerActions[] = ['label' => 'Open Chat', 'url' => $baseProject . '/chat/index.php?context=DO:' . (int)$do_id, 'class' => 'btn btn-sm btn-outline-light'];
    if ($returnStatus !== '') {
        $headerActions[] = ['label' => 'Lihat Retur', 'url' => 'sales_do_return.php?do_id=' . (int)$do_id, 'class' => 'btn btn-sm btn-outline-warning'];
    } elseif (in_array(strtolower((string)($do['status'] ?? '')), ['on_delivery','delivered','wait_payment','paid'], true)) {
        $headerActions[] = ['label' => 'Ajukan Retur', 'url' => 'sales_do_return.php?do_id=' . (int)$do_id, 'class' => 'btn btn-sm btn-outline-warning'];
    }
    if (strtolower((string)($do['status'] ?? '')) === 'revision_requested') {
        $headerActions[] = ['label' => 'Revisi DO', 'url' => 'sales_do.php?edit=' . (int)$do_id, 'class' => 'btn btn-sm btn-warning'];
    }
} else {
    $stDepoView = strtolower(trim((string)($do['status'] ?? '')));
    if (in_array($stDepoView, ['crm_to_wqs','sent_wqs','revision_requested','wqs_processing'], true)) {
        $headerActions[] = ['label'=>'WQS Task','url'=>$baseProject . '/stock/wqs_do_tasks.php','class'=>'btn btn-sm btn-outline-warning'];
    }
    if (in_array($stDepoView, ['ready_scm','on_delivery'], true)) {
        $headerActions[] = ['label'=>'SCM Task','url'=>$baseProject . '/sales/scm_do_tasks.php','class'=>'btn btn-sm btn-outline-info'];
    }
    if ($stDepoView === 'on_delivery') {
        $headerActions[] = ['label'=>'Tracker','url'=>$baseProject . '/sales/scm_tracker_mobile.php?do_id=' . (int)$do_id,'class'=>'btn btn-sm btn-outline-success'];
    }
}

$extraHead = '<style>
        /* Layout utama: putih, friendly untuk print & dot-matrix */
        body {
            margin: 0;
            padding: 0;
            background: #111827;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: #111827;
        }
        .page {
            width: 900px;                /* cukup untuk continuous form 9.5" */
            margin: 20px auto;
            background: #ffffff;
            padding: 16px 24px 24px 24px;
            border-radius: 8px;
            box-shadow: 0 12px 25px rgba(0,0,0,0.35);
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            border-bottom: 2px solid #111827;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .company-block {
            max-width: 55%;
            font-size: 12px;
        }
        .company-name {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .company-sub {
            font-size: 11px;
        }

        .do-block {
            text-align: right;
            max-width: 40%;
        }
        .do-title {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.12em;
        }
        .do-code {
            font-size: 14px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            display: inline-block;
            background: #f9fafb;
        }
        .tracking {
            margin-top: 4px;
            font-size: 11px;
            color: #6b7280;
        }
        .tracking span {
            font-family: "SF Mono", ui-monospace, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            margin-bottom: 8px;
        }
        .info-box {
            width: 48%;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 8px;
            background: #f9fafb;
        }
        .info-title {
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 4px;
            text-transform: uppercase;
        }
        .info-line {
            margin: 0;
            line-height: 1.35;
        }

        /* Prefix `body.rmi-body .rmi-content .page` WAJIB ada di selector
           tabel print ini.

           Konten halaman diinjeksi DI DALAM .rmi-content, dan tabel ini
           hanya punya class "items" — jadi selector legacy shared ini tetap
           cocok dan specificitiy-nya lebih tinggi:

             _shared/rmi.css
               body.rmi-body .rmi-content table:not(.table):not(.dataTable)
                 th,td          -> (0,4,3)   padding:8px 10px
                 thead th       -> (0,4,4)   font-size:12px, color:var(--rmi-muted),
                                                   border-bottom-width:2px,
                                                   white-space:nowrap,
                                                   position:sticky, top:0,
                                                   background:var(--rmi-bg)
               table           -> (0,4,2)   font-size:13px

             sales_do_view.php (tanpa prefix)
               table.items          -> (0,1,1)
               table.items thead th -> (0,1,3)

           Resultnya SEMUA aturan layar di bawah kalah, sehingga tabel print
           ini tidak pernah memakai padding 3px 4px / font 11px / garis bawah
           tipis yang dimaksud. Yang tampil: padding 8px 10px, font 13px, dan
           header sticky berwarna latar halaman (hitam di dark mode) di atas
           kertas putih.

           Dengan prefix: (0,4,4) — seri dengan shared, dan <style> halaman
           dimuat setelah rmi.css, jadi halaman menang. Presedensinya:
             shared (baris 963) < blok 9f (akhir rmi.css) < <style> halaman

           JANGAN dipangkas prefix ini. */
        body.rmi-body .rmi-content .page table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 11px;
        }
        body.rmi-body .rmi-content .page table.items thead th {
            border-bottom: 1px solid #111827;
            padding: 4px;
            text-align: left;
            text-transform: uppercase;
            font-size: 11px;
            /* Batalkan efek shared: header print bukan kolom lengket, dan
               latar darkestya memenuhi kertas, bukan bg halaman. */
            position: static;
            background: transparent;
            color: #111827;
            letter-spacing: normal;
        }
        body.rmi-body .rmi-content .page table.items tbody td {
            border-bottom: 1px solid #e5e7eb;
            padding: 3px 4px;
            vertical-align: middle;
        }
        body.rmi-body .rmi-content .page table.items tfoot td {
            padding: 3px 4px;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }

        .summary-block {
            margin-top: 4px;
            display: flex;
            justify-content: flex-end;
            font-size: 11px;
        }
        body.rmi-body .rmi-content .page .summary-table {
            border-collapse: collapse;
            font-size: 11px;
        }
        body.rmi-body .rmi-content .page .summary-table td {
            padding: 2px 4px;
        }
        body.rmi-body .rmi-content .page .summary-table tr td:first-child {
            text-align: right;
        }

        .note-block {
            margin-top: 8px;
            font-size: 11px;
        }
        .note-title {
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        .return-summary {
            margin: 8px 0 10px;
            padding: 8px 10px;
            border: 1px solid #f59e0b;
            border-radius: 6px;
            background: #fffbeb;
            color: #78350f;
            font-size: 11px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
        }
        .return-summary a { color:#92400e; font-weight:700; text-decoration:none; }
        .return-summary a:hover { text-decoration:underline; }
        .classification-summary,.lineage-summary {
            margin: 8px 0 10px;
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #f8fafc;
            color: #334155;
            font-size: 11px;
        }
        .class-pill{display:inline-block;padding:2px 7px;border-radius:999px;border:1px solid #cbd5e1;background:#fff;font-weight:700;margin-left:4px}
        .note-body {
            min-height: 28px;
            border: 1px dashed #d1d5db;
            border-radius: 4px;
            padding: 4px 6px;
            background: #f9fafb;
        }

        .sign-row {
            margin-top: 16px;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
        }
        .sign-box {
            width: 30%;
            text-align: center;
        }
        .sign-title {
            font-weight: 600;
            margin-bottom: 36px;
        }
        .sign-name {
            border-top: 1px solid #111827;
            padding-top: 2px;
            margin-top: 26px;
            font-size: 10px;
        }

        .scm-proof-block {
            margin-top: 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #f8fafc;
            padding: 8px 10px;
            font-size: 11px;
        }
        .scm-proof-title {
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .scm-proof-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
        }
        .scm-proof-item {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #fff;
            padding: 7px;
            min-height: 68px;
        }
        .scm-proof-item b {
            display: block;
            margin-bottom: 4px;
        }
        .scm-signature-img {
            display: block;
            width: 100%;
            max-width: 180px;
            height: 72px;
            margin: 4px auto 2px;
            object-fit: contain;
            background: #fff;
        }
        .sign-box.customer-sign .sign-title {
            margin-bottom: 36px;
        }
        .sign-box.customer-sign.has-sign .sign-title {
            margin-bottom: 6px;
        }
        .sign-box.customer-sign .sign-name {
            margin-top: 4px;
        }
        .signature-meta {
            font-size: 9px;
            line-height: 1.35;
            color: #4b5563;
            margin-top: 3px;
        }

        .erp-actor-stamp {
            min-height: 62px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            padding: 4px 3px 0;
        }
        .erp-actor-name {
            font-size: 10px;
            font-weight: 700;
            color: #111827;
            word-break: break-word;
            line-height: 1.25;
        }
        .erp-actor-meta {
            margin-top: 3px;
            font-size: 8.5px;
            color: #6b7280;
            line-height: 1.25;
        }
        .erp-actor-line {
            width: 100%;
            border-top: 1px solid #111827;
            margin-top: 5px;
            padding-top: 3px;
            font-size: 8px;
            color: #6b7280;        }
        .erp-actor-account {
            font-size: 8px;
            line-height: 1.25;
            color: #6b7280;
            margin-top: 2px;
            word-break: break-all;
        }
        @media (max-width: 760px) {
            .scm-proof-grid { grid-template-columns: 1fr; }
        }

        .footer-info {
            margin-top: 8px;
            font-size: 10px;
            color: #6b7280;
            display: flex;
            justify-content: space-between;
        }

        .btn-bar {
            text-align: right;
            margin-bottom: 8px;
        }
        .btn-print,
        .btn-back {
            display: inline-block;
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            margin-left: 4px;
        }
        .btn-print {
            background: #111827;
            color: #f9fafb;
        }
        .btn-back {
            background: #e5e7eb;
            color: #111827;
        }

        /* ---- Kertas putih harus tetap gelap, termasuk di mode gelap ------
           Halaman ini menyimulasikan kertas (900px, cursor:pointer) di atas
           body gelap. Aturan shared ini menang specificity-nya:
             html[data-theme="dark"] body.rmi-body { color: var(--rmi-text) }
           --rmi-text dark = #e5e7eb. Akibatnya seluruh isi .page memakai
           teks terang di atas .page { background:#ffffff } = 1.24:1,
           jadi praktis hilang, termasuk blok warning .return-summary.

           Kertas bukan bagian dari tema, jadi warnanya dikunci di sini.
           Selector dibuat setara/lewat shared rule agar override ini
           menang tanpa !important. */
        html[data-theme="dark"] body.rmi-body .page,
        html[data-rmi-theme="dark"] body.rmi-body .page,
        body.rmi-body .page {
            color: #111827;
        }

        /* .btn-print sudah punya latar gelap sendiri (#111827) dengan teks
           #f9fafb = 16.5:1, aman di kedua mode. .btn-back: #e5e7eb di atas
           #111827 = 14.7:1, juga aman. Keduanya tidak perlu disentuh. */

        @media print {
            /*
             * PRINT CLEAN MODE
             * Cetak hanya isi Delivery Order. Header/navigation/footer ERP dari
             * rmi_layout tidak boleh ikut ke hasil print/PDF.
             *
             * AKAR MASALAH WARNA: tema gelap mendefinisikan
             * --rmi-text:#e5e7eb (putih) dan body memakai
             * color:var(--rmi-text), sehingga hitam jadi putih di kertas.
             * Timpa variabel khusus cetak agar seluruh var() ikut gelap.
             */
            html[data-theme="dark"],
            html[data-rmi-theme="dark"],
            html {
                --rmi-text: #111827 !important;
                --rmi-muted: #374151 !important;
                --text: #111827 !important;
                --muted: #374151 !important;
            }
            @page {
                size: 9in 11in;
                margin: 4mm;
            }

            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            /* Robust terhadap perubahan class pada layout ERP:
               sembunyikan seluruh layout, lalu hidupkan kembali dokumen DO saja. */
            body * {
                visibility: hidden !important;
            }
            .page,
            .page * {
                visibility: visible !important;
            }

            /* Hilangkan elemen navigasi/layout bila browser masih menghitungnya. */
            header,
            nav,
            footer,
            .navbar,
            .topbar,
            .app-header,
            .app-footer,
            .layout-header,
            .layout-footer,
            .rmi-header,
            .rmi-footer,
            .rmi-topbar,
            .sidebar,
            .breadcrumb,
            .breadcrumbs,
            .btn-bar {
                display: none !important;
            }

            .page {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 2mm !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #ffffff !important;
                overflow-x: hidden !important;
            }

            /* Tidak boleh ada elemen yang meluber keluar kertas. */
            .page img,
            .page svg,
            .page canvas,
            .page video {
                max-width: 100% !important;
                height: auto !important;
            }
            .page pre,
            .page code {
                white-space: pre-wrap !important;
                word-break: break-word !important;
            }

            /* Hindari blok bukti/signature pecah di tengah halaman jika memungkinkan. */
            .scm-proof-block,
            .sign-row,
            .info-row,
            .summary-block {
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }

            a {
                color: #111827 !important;
                text-decoration: none !important;
            }

            /* Warna teks: paksa gelap agar terbaca di kertas putih.
               Tema gelap layout menimpa warna body sehingga seluruh
               isi ikut pudar saat cetak. Kecualikan header tabel. */
            .page,
            .page div,
            .page span,
            .page p,
            .page td,
            .page li,
            .page h1, .page h2, .page h3, .page h4 {
                color: #111827 !important;
            }
            .page thead th {
                color: #ffffff !important;
                background: #111827 !important;
            }

            /* Tabel item harus muat di lebar kertas: paksa wrap + susut padding/font. */
            table.items {
                table-layout: fixed !important;
                width: 100% !important;
            }
            table.items thead th {
                white-space: nowrap !important;
                font-size: 9px !important;
                letter-spacing: 0.02em !important;
            }
            table.items tbody td,
            table.items tfoot td {
                white-space: normal !important;
                word-break: break-word !important;
                overflow-wrap: anywhere !important;
                padding: 2px 3px !important;
                font-size: 10px !important;
            }
            .page, .page * {
                print-color-adjust: exact !important;
                -webkit-print-color-adjust: exact !important;
            }
        }

        /* --- RMI patch: ensure long text is visible (no truncation) --- */
        .table td, .table th {
            white-space: normal !important;
            word-break: break-word !important;
            overflow: visible !important;
            text-overflow: clip !important;
        }
        .text-truncate, .truncate, .nowrap {
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
        }

    </style>';

rmi_header('Print DO - ' . htmlspecialchars($do_code), 'sales', [
    'layout' => 'topnav',
    'subtitle' => 'View / Print Delivery Order',
    'breadcrumbs' => [
        ['label' => 'Sales (CRM)', 'url' => $baseProject . '/sales/sales_dashboard.php'],
        ['label' => 'Sales DO', 'url' => $baseProject . '/sales/sales_do.php'],
        'Print DO',
    ],
    'actions' => $headerActions,
    'extra_head' => $extraHead,
]);
?>

<div class="page">

    <div class="btn-bar">
        <button class="btn-back" type="button" onclick="window.close();">
            &laquo; Tutup
        </button>
        <button class="btn-print" type="button" onclick="window.print();">
            <?= rmi_icon('print') ?> Cetak DO
        </button>
    </div>

    <div class="page-header">
        <div class="company-block">
            <div class="company-name">
                RIZQULLAH MEDISKA INDONESIA
            </div>
            <div class="company-sub">
                <?= htmlspecialchars($office_name) ?><br>
                <?= htmlspecialchars($office_address) ?><br>
                <?= htmlspecialchars($office_city) ?><br>
                Telp: <?= htmlspecialchars($office_phone) ?>
            </div>
        </div>
        <div class="do-block">
            <div class="do-title">
                DELIVERY ORDER
            </div>
            <div class="do-code">
                <?= htmlspecialchars($do_code) ?>
            </div>
            <div class="tracking">
                Tracking: <span><?= htmlspecialchars($tracking_code) ?></span><br>
                Tanggal DO: <?= htmlspecialchars($do_date_print) ?><br>
                Kelompok: <strong><?= htmlspecialchars($doBusinessGroup === 'UNIT_ACC' ? 'UNIT ACC' : 'BMHP') ?></strong>
<?php if ($no_po_print !== ''): ?><br>
No PO: <span><?= htmlspecialchars($no_po_print) ?></span>
<?php endif; ?>
            </div>
        </div>
    </div>

    <div class="classification-summary">
        <strong>Kelompok DO:</strong>
        <span class="class-pill"><?= htmlspecialchars($doBusinessGroup === 'UNIT_ACC' ? 'UNIT ACC' : 'BMHP') ?></span>
        <?php if ($doBusinessGroup === 'UNIT_ACC'): ?>
            <span style="margin-left:8px">Item Unit ACC dapat terdiri dari <b>ALKES</b> dan <b>AKSESORIS</b> dalam satu DO; kategori tetap melekat per item.</span>
        <?php endif; ?>
    </div>

    <?php if ($lineage['replacement_parent'] || $lineage['replacement_return'] || $lineage['replacement_children'] || $lineage['revision_parent'] || strtolower((string)($do['status'] ?? ''))==='revision_requested'): ?>
    <div class="lineage-summary">
        <strong>Riwayat Dokumen:</strong>
        <?php if (strtolower((string)($do['status'] ?? ''))==='revision_requested'): ?>
            <div>• DO sedang <b>REVISION REQUESTED</b>; revisi tetap memakai DO/kelompok bisnis yang sama dan setelah disimpan kembali ke WQS.</div>
            <?php if (!empty($do['revision_reason'])): ?><div>• Alasan: <?= htmlspecialchars((string)$do['revision_reason']) ?></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($lineage['revision_parent']): ?><div>• Koreksi/duplikat ini terhubung ke DO asal <a href="sales_do_view.php?id=<?= (int)$lineage['revision_parent']['id'] ?>"><?= htmlspecialchars((string)$lineage['revision_parent']['do_code']) ?></a>.</div><?php endif; ?>
        <?php if ($lineage['replacement_parent']): ?><div>• <b>DO PENGGANTI</b> dari <a href="sales_do_view.php?id=<?= (int)$lineage['replacement_parent']['id'] ?>"><?= htmlspecialchars((string)$lineage['replacement_parent']['do_code']) ?></a><?php if ($lineage['replacement_return']): ?> melalui retur <?= htmlspecialchars((string)$lineage['replacement_return']['return_code']) ?> (<?= htmlspecialchars((string)$lineage['replacement_return']['commercial_effect']) ?>)<?php endif; ?>. Bukan sales baru.</div><?php endif; ?>
        <?php foreach ($lineage['replacement_children'] as $lc): ?><div>• DO Pengganti aktif: <a href="sales_do_view.php?id=<?= (int)$lc['id'] ?>"><?= htmlspecialchars((string)$lc['do_code']) ?></a> · <?= htmlspecialchars((string)$lc['status']) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($returnStatus !== '' && !$__sdv_depo_restricted): ?>
    <div class="return-summary">
        <div><strong>Status Retur:</strong> <?= htmlspecialchars(sdv_return_status_label($returnStatus)) ?><?php if ($returnLastAt !== ''): ?> · Update <?= htmlspecialchars($returnLastAt) ?><?php endif; ?><br><span style="font-size:10px;color:#92400e;">Jika salah barang, DO lama tidak diedit langsung. Stok dikoreksi melalui retur, lalu buat DO baru/pengganti.</span></div>
        <a href="sales_do_return.php?do_id=<?= (int)$do_id ?>" target="_blank">Lihat / lanjutkan proses retur →</a>
    </div>
    <?php endif; ?>

    <div class="info-row">
        <div class="info-box">
            <div class="info-title">Customer</div>
            <p class="info-line"><strong><?= htmlspecialchars($cust_name) ?></strong></p>
            <?php if ($cust_address !== ''): ?>
                <p class="info-line"><?= htmlspecialchars($cust_address) ?></p>
            <?php endif; ?>
            <?php if ($cust_city !== ''): ?>
                <p class="info-line"><?= htmlspecialchars($cust_city) ?></p>
            <?php endif; ?>
            <?php if ($cust_phone !== '' || $cust_email !== ''): ?>
                <p class="info-line">
                    <?php if ($cust_phone !== ''): ?>
                        Telp: <?= htmlspecialchars($cust_phone) ?>
                    <?php endif; ?>
                    <?php if ($cust_phone !== '' && $cust_email !== ''): ?> | <?php endif; ?>
                    <?php if ($cust_email !== ''): ?>
                        Email: <?= htmlspecialchars($cust_email) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="info-box">
            <div class="info-title">Kirim Ke / PIC</div>
            <?php if (trim($ship_address) !== ''): ?>
                <p class="info-line"><?= nl2br(htmlspecialchars($ship_address)) ?></p>
            <?php endif; ?>
            <p class="info-line">
                PIC: <?= $customer_pic !== '' ? htmlspecialchars($customer_pic) : '-' ?>
            </p>
            <p class="info-line">
                Telp PIC: <?= $customer_phone !== '' ? htmlspecialchars($customer_phone) : '-' ?>
            </p>
        </div>
    </div>

    <table class="items">
        <thead>
        <tr>
            <th style="width:28px;">No</th>
            <th style="width:75px;">SKU</th>
<?php if ($hasItemExpDate): ?><th style="width:65px;">Exp Date</th><?php endif; ?>
            <th style="width:70px;">Lot / Seri</th>
            <th>Produk</th>
            <th style="width:62px;">Kategori</th>
            <th style="width:38px;" class="text-right">Qty</th>
            <th style="width:42px;">Satuan</th>
            <th style="width:85px;" class="text-right">Harga</th>
            <th style="width:38px;" class="text-right">Disc%</th>
            <th style="width:90px;" class="text-right">Subtotal</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr>
                <td colspan="<?= $hasItemExpDate ? 11 : 10 ?>" class="text-center">Tidak ada item.</td>
            </tr>
        <?php else: ?>
            <?php $no = 1; ?>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><?= htmlspecialchars(strtoupper($it['sku'] ?? '')) ?></td>
                    <?php if ($hasItemExpDate): ?><td><?= htmlspecialchars(sdv_format_exp_date($it['exp_date'] ?? '')) ?></td><?php endif; ?>
                    <td><?= htmlspecialchars(do_item_lot_seri_display($pdo, $it, $do)) ?></td>
                    <td><?= htmlspecialchars(strtoupper($it['products_name'] ?? '')) ?></td>
                    <td><?= htmlspecialchars(($it['_business_group'] ?? '') === 'UNIT_ACC' ? ('UNIT ACC / ' . ($it['_category'] ?? '')) : ($it['_category'] ?? 'BMHP')) ?></td>
                    <td class="text-right"><?= (int)($it['qty'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($it['unit'] ?? '') ?></td>
                    <td class="text-right"><?= rupiah($it['unit_price'] ?? 0) ?></td>
                    <td class="text-right"><?= number_format((float)($it['disc_percent'] ?? 0), 2, ',', '.') ?></td>
                    <td class="text-right"><?= rupiah($it['subtotal'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="summary-block">
        <table class="summary-table">
            <tr>
                <td>Subtotal</td>
                <td class="text-right"><?= rupiah($total_amount) ?></td>
            </tr>
            <tr>
                <td>
                    PPN / Tax
                    <?php if ($tax_code !== ''): ?>
                        (<?= htmlspecialchars($tax_code) ?><?= $tax_rate > 0 ? ' - ' . $tax_rate . '%' : '' ?>)
                    <?php endif; ?>
                </td>
                <td class="text-right"><?= rupiah($tax_amount) ?></td>
            </tr>
            <tr>
                <td><strong>Grand Total</strong></td>
                <td class="text-right"><strong><?= rupiah($grand_total) ?></strong></td>
            </tr>
        </table>
    </div>

    <?php
    $crmOrderReceivedAt = trim((string)($do['crm_order_received_at'] ?? ''));
    $crmOrderSource = trim((string)($do['crm_order_source'] ?? ''));
    $crmOrderNote = trim((string)($do['crm_order_note'] ?? ''));
    $crmOrderProofStored = trim((string)($do['crm_order_proof_file'] ?? ''));
    $crmOrderProofUrl = sdv_project_file_url($crmOrderProofStored, $baseProject);
    $crmOrderProofExt = strtolower(pathinfo(parse_url($crmOrderProofStored, PHP_URL_PATH) ?: $crmOrderProofStored, PATHINFO_EXTENSION));
    $crmOrderProofIsImage = in_array($crmOrderProofExt, ['jpg','jpeg','png','webp'], true);
    ?>
    <?php if (($crmOrderReceivedAt !== '' || $crmOrderSource !== '' || $crmOrderNote !== '' || $crmOrderProofUrl !== '') && !$__sdv_depo_restricted): ?>
    <div class="note-block" style="margin-top:12px;">
        <div class="note-title">Informasi Order Customer</div>
        <div class="note-body">
            <table style="width:100%;border-collapse:collapse;font-size:11px;">
                <tr>
                    <td style="width:150px;padding:3px 0;color:#6b7280;">Jam Order Customer/RS</td>
                    <td style="padding:3px 0;"><?= $crmOrderReceivedAt !== '' ? htmlspecialchars(date('d-m-Y H:i', strtotime($crmOrderReceivedAt))) : '-' ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 0;color:#6b7280;">Sumber Order</td>
                    <td style="padding:3px 0;"><?= $crmOrderSource !== '' ? htmlspecialchars($crmOrderSource) : '-' ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 0;color:#6b7280;vertical-align:top;">Catatan Order</td>
                    <td style="padding:3px 0;"><?= $crmOrderNote !== '' ? nl2br(htmlspecialchars($crmOrderNote)) : '-' ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 0;color:#6b7280;vertical-align:top;">Bukti Order / Screenshot</td>
                    <td style="padding:3px 0;">
                        <?php if ($crmOrderProofUrl !== ''): ?>
                            <?php if ($crmOrderProofIsImage): ?>
                                <a href="<?= htmlspecialchars($crmOrderProofUrl) ?>" target="_blank" rel="noopener noreferrer" title="Klik untuk melihat ukuran penuh">
                                    <img src="<?= htmlspecialchars($crmOrderProofUrl) ?>" alt="Bukti Order" style="display:block;max-width:260px;max-height:180px;border:1px solid #d1d5db;border-radius:6px;padding:3px;background:#fff;object-fit:contain;">
                                </a>
                                <div style="margin-top:4px;font-size:10px;color:#6b7280;">Klik gambar untuk melihat ukuran penuh.</div>
                            <?php elseif ($crmOrderProofExt === 'pdf'): ?>
                                <a href="<?= htmlspecialchars($crmOrderProofUrl) ?>" target="_blank" rel="noopener noreferrer"><?= rmi_icon('doc') ?> Lihat PDF Bukti Order</a>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($crmOrderProofUrl) ?>" target="_blank" rel="noopener noreferrer">Lihat Bukti Order</a>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="note-block">
        <div class="note-title">Catatan</div>
        <div class="note-body">
            <?= $note !== '' ? nl2br(htmlspecialchars($note)) : '<span style="color:#9ca3af;">(Tidak ada catatan)</span>' ?>
        </div>
    </div>

    <?php if (!empty($returnRows) && !$__sdv_depo_restricted): ?>
    <div class="note-block" style="margin-top:12px;">
        <div class="note-title">Riwayat Retur DO</div>
        <div class="note-body">
            <?php foreach ($returnRows as $rr): ?>
                <div style="margin-bottom:8px;">
                    <strong><?= htmlspecialchars($rr['return_code'] ?? '') ?></strong>
                    <span style="color:#6b7280;font-size:10px;">Status: <?= htmlspecialchars(sdv_return_status_label((string)($rr['status'] ?? ''))) ?> · Tipe: <?= htmlspecialchars($rr['return_type'] ?? '') ?> · <?= htmlspecialchars($rr['created_at'] ?? '') ?></span>
                    <a href="sales_do_return.php?do_id=<?= (int)$do_id ?>&return_id=<?= (int)$rr['id'] ?>" target="_blank" style="font-size:10px;margin-left:6px;">Detail Retur</a><br>
                    <span>Alasan: <?= htmlspecialchars($rr['reason'] ?? '') ?></span>
                    <?php $ris = $returnItemsByReturn[(int)$rr['id']] ?? []; ?>
                    <?php if ($ris): ?>
                    <ul style="margin:4px 0 0 18px;padding:0;">
                        <?php foreach ($ris as $ri): ?>
                            <li><?= htmlspecialchars($ri['product_name'] ?? '') ?> — <b><?= htmlspecialchars(($ri['_business_group'] ?? '') === 'UNIT_ACC' ? ('UNIT ACC / ' . ($ri['_category'] ?? '')) : ($ri['_category'] ?? 'BMHP')) ?></b> — Qty Retur: <?= htmlspecialchars($ri['qty_return'] ?? '') ?> <?= htmlspecialchars($ri['unit'] ?? '') ?>, Kondisi: <?= htmlspecialchars($ri['condition_status'] ?? '') ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($portalDocs) && !$__sdv_depo_restricted): ?>
    <div class="note-block" style="margin-top:12px;">
        <div class="note-title">Dokumen Pendukung (dari Portal)</div>
        <div class="note-body">
            <ul style="margin:0;padding-left:18px;">
            <?php foreach ($portalDocs as $pd): ?>
                <li><a href="sales_do_doc_download.php?id=<?= (int)$pd['id'] ?>" target="_blank"><?= htmlspecialchars($pd['file_name']) ?></a> <span style="color:#6b7280;font-size:10px;">(<?= htmlspecialchars($pd['doc_type']) ?>, <?= htmlspecialchars($pd['created_at']) ?>)</span></li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>


    <?php if ($scm_signature_src !== '' || $scm_delivery_photo_url !== '' || $scm_delivery_video_url !== '' || $scm_delivered_at_print !== ''): ?>
    <div class="scm-proof-block" id="scm-pod-proof">
        <div class="scm-proof-title">Bukti Serah Terima SCM / POD</div>
        <div class="scm-proof-grid">
            <div class="scm-proof-item">
                <b>Foto POD</b>
                <?php if ($scm_delivery_photo_url !== ''): ?>
                    <a href="<?= htmlspecialchars($scm_delivery_photo_url) ?>" target="_blank" rel="noopener noreferrer">📷 Lihat Foto POD ↗</a>
                <?php else: ?>
                    <span style="color:#6b7280">-</span>
                <?php endif; ?>
            </div>
            <div class="scm-proof-item">
                <b>Video POD</b>
                <?php if ($scm_delivery_video_url !== ''): ?>
                    <a href="<?= htmlspecialchars($scm_delivery_video_url) ?>" target="_blank" rel="noopener noreferrer">🎥 Lihat Video POD ↗</a>
                <?php else: ?>
                    <span style="color:#6b7280">-</span>
                <?php endif; ?>
            </div>
            <div class="scm-proof-item">
                <b>Tanda Tangan Digital Customer</b>
                <?php if ($scm_signature_src !== ''): ?>
                    <img class="scm-signature-img" src="<?= htmlspecialchars($scm_signature_src, ENT_QUOTES, 'UTF-8') ?>" alt="Tanda Tangan Digital Customer">
                    <span style="color:#15803d;font-weight:700">Tersimpan <?= rmi_icon('tick') ?></span>
                <?php else: ?>
                    <span style="color:#6b7280">-</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($scm_delivered_at_print !== '' || $scm_delivered_by_print !== ''): ?>
        <div style="margin-top:7px;color:#4b5563">
            <?php if ($scm_delivered_at_print !== ''): ?>
                Delivered: <b><?= htmlspecialchars(date('d-m-Y H:i:s', strtotime($scm_delivered_at_print))) ?></b>
            <?php endif; ?>
            <?php if ($scm_delivered_by_print !== ''): ?>
                <?= $scm_delivered_at_print !== '' ? ' · ' : '' ?>PIC SCM: <b><?= htmlspecialchars($scm_delivered_by_print) ?></b>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="sign-row">
        <?php /* Flow internal: CRM -> WQS -> SCM -> Customer -> ACT -> FIN.
                 Label tetap baku (Disiapkan WQS / Dikirim SCM / Diterima Customer);
                 identitas akun + nama employee tampil di baris "Akun:" & nama. */ ?>
        <?= rmi_actor_stamp($pdo, $wqs_prepared_by_raw, $wqs_prepared_at_print, ['title' => 'Disiapkan WQS']) ?>
        <?= rmi_actor_stamp($pdo, $scm_sent_by_raw,     $scm_sent_at_print,     ['title' => 'Dikirim SCM']) ?>
<div class="sign-box customer-sign<?= $scm_signature_src !== '' ? ' has-sign' : '' ?>">
            <div class="sign-title">Diterima Customer</div>
            <?php if ($scm_signature_src !== ''): ?>
                <img class="scm-signature-img" src="<?= htmlspecialchars($scm_signature_src, ENT_QUOTES, 'UTF-8') ?>" alt="TTD Customer">
                <div class="signature-meta">
                    TTD Digital Customer
                    <?php if ($scm_delivered_at_print !== ''): ?><br><?= htmlspecialchars(date('d-m-Y H:i:s', strtotime($scm_delivered_at_print))) ?><?php endif; ?>
                </div>
                <div class="sign-name"><?= $customer_pic !== '' ? htmlspecialchars($customer_pic) : '&nbsp;' ?></div>
            <?php else: ?>
                <div class="erp-actor-stamp">
                    <div class="erp-actor-name">&nbsp;</div>
                    <div class="erp-actor-line">&nbsp;</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer-info">
        <div>
            Dicetak: <?= htmlspecialchars($printed_at) ?>
            <?php if ($crm_created_by_print !== ''): ?>
            <br>Dibuat: <?= htmlspecialchars($crm_created_by_print) ?><?= $crm_created_at_print !== '' ? ' • ' . htmlspecialchars(date('d-m-Y H:i', strtotime($crm_created_at_print))) : '' ?>
            <?php endif; ?>
        </div>
        <div>
            Sistem ERP_RMI_SOFULL • DO: <?= htmlspecialchars($do_code) ?>
        </div>
    </div>

</div>

<?php if ($isPrintMode): ?>
<script>
window.addEventListener('load', function () { window.print(); });
</script>
<?php endif; ?>

<?php rmi_footer(); ?>