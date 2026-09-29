<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// sales_do.php
// CRM → SALES DO / ORDER
// Flow: Customer → CRM → WQS → SCM → ACT → FIN


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// helper escape (dipakai di beberapa tempat)
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// --- AUTH (Enterprise Guard) ---
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.VIEW', 'SALES.CREATE', 'SALES.EDIT', 'SALES.DELETE', 'SALES.EXPORT']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','MANAGER','STAFF','CRM','WQS','SCM','ACT','FIN','BRANCH']);
}
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/../_shared/helpers.php';       // rmi_sku(), rmi_product_name(), rmi_h()
require_once __DIR__ . '/../_shared/stock_helper.php'; // stock_left_join_subquery() — join aman tanpa duplikat
require_once __DIR__ . '/../stock/_stock_office_helper.php';

function sd_reduce_stock_by_office_for_do(PDO $pdo, int $productId, float $qty, string $officeCode, string $sku = ''): void
{
    // Legacy helper: tetap ada untuk kompatibilitas, tetapi DO baru memakai sd_reduce_stock_by_do_id().
    $officeCode = strtoupper(trim($officeCode));

    if ($productId <= 0 || $qty <= 0 || $officeCode === '') {
        throw new Exception('Data pengurangan stok tidak valid.');
    }

    $st = $pdo->prepare("
        SELECT stock_qty
        FROM wqs_stock_by_office
        WHERE product_id = ?
          AND UPPER(TRIM(office_code)) = ?
        LIMIT 1
        FOR UPDATE
    ");
    $st->execute([$productId, $officeCode]);
    $currentStock = $st->fetchColumn();

    if ($currentStock === false) {
        throw new Exception("Stok SKU {$sku} tidak ditemukan di office {$officeCode}.");
    }

    $currentStock = (float)$currentStock;

    if ($currentStock < $qty) {
        throw new Exception("Stok SKU {$sku} tidak cukup di office {$officeCode}. Tersedia: {$currentStock}, diminta: {$qty}.");
    }

    $up = $pdo->prepare("
        UPDATE wqs_stock_by_office
        SET stock_qty = stock_qty - ?,
            updated_at = NOW()
        WHERE product_id = ?
          AND UPPER(TRIM(office_code)) = ?
          AND stock_qty >= ?
    ");
    $up->execute([$qty, $productId, $officeCode, $qty]);

    if ($up->rowCount() < 1) {
        throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}. Stok kemungkinan sudah berubah.");
    }

    sd_sync_global_stock_after_do($pdo, $productId);
}

function sd_table_columns(PDO $pdo, string $table): array
{
    try {
        return $pdo->query("SHOW COLUMNS FROM `" . str_replace("`", "", $table) . "`")->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Throwable $e) {
        return [];
    }
}

function sd_sync_global_stock_after_do(PDO $pdo, int $productId): void
{
    try {
        $sync = $pdo->prepare("
            INSERT INTO wqs_stock (product_id, stock_qty, updated_at)
            SELECT ?, COALESCE(SUM(stock_qty), 0), NOW()
            FROM wqs_stock_by_office
            WHERE product_id = ?
            ON DUPLICATE KEY UPDATE
                stock_qty = VALUES(stock_qty),
                updated_at = VALUES(updated_at)
        ");
        $sync->execute([$productId, $productId]);
    } catch (Throwable $e) {
        // Jangan gagalkan DO hanya karena sync global gagal.
    }

    // Jika master_products memiliki kolom stok global, ikut sinkronkan dari total semua office.
    try {
        $mpCols = sd_table_columns($pdo, 'master_products');
        $sets = [];
        if (in_array('current_stock', $mpCols, true)) $sets[] = 'current_stock = :total_stock';
        if (in_array('stock_qty', $mpCols, true)) $sets[] = 'stock_qty = :total_stock';
        if (in_array('updated_at', $mpCols, true)) $sets[] = 'updated_at = NOW()';

        if (!empty($sets)) {
            $st = $pdo->prepare("SELECT COALESCE(SUM(stock_qty), 0) FROM wqs_stock_by_office WHERE product_id = ?");
            $st->execute([$productId]);
            $totalStock = (float)($st->fetchColumn() ?: 0);

            $up = $pdo->prepare("UPDATE master_products SET " . implode(', ', $sets) . " WHERE id = :product_id");
            $up->execute([':total_stock' => $totalStock, ':product_id' => $productId]);
        }
    } catch (Throwable $e) {
        // Sync master_products stok bersifat pelengkap.
    }
}


/**
 * Kembalikan stok WQS saat DO CRM yang salah dihapus.
 * Restore hanya dilakukan jika kolom sales_do.stock_reduced_at ada dan bernilai, agar stok tidak dobel naik.
 */
function sd_restore_stock_by_do_id_for_delete(PDO $pdo, int $doId): void
{
    if ($doId <= 0) return;

    $doCols = sd_table_columns($pdo, 'sales_do');
    $hasMarker = in_array('stock_reduced_at', $doCols, true);
    if ($hasMarker) {
        $chk = $pdo->prepare("SELECT stock_reduced_at FROM sales_do WHERE id = ? LIMIT 1");
        $chk->execute([$doId]);
        $reducedAt = $chk->fetchColumn();
        if ($reducedAt === false || $reducedAt === null || trim((string)$reducedAt) === '') {
            return;
        }
    } else {
        return;
    }

    $st = $pdo->prepare("
        SELECT
            UPPER(TRIM(d.office_code)) AS office_code,
            i.product_id,
            MAX(i.sku) AS sku,
            SUM(i.qty) AS total_qty
        FROM sales_do d
        JOIN sales_do_items i ON i.do_id = d.id
        WHERE d.id = ?
        GROUP BY UPPER(TRIM(d.office_code)), i.product_id
    ");
    $st->execute([$doId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $it) {
        $officeCode = strtoupper(trim((string)($it['office_code'] ?? '')));
        $productId  = (int)($it['product_id'] ?? 0);
        $qty        = (float)($it['total_qty'] ?? 0);
        if ($officeCode === '' || $productId <= 0 || $qty <= 0) continue;

        $up = $pdo->prepare("
            UPDATE wqs_stock_by_office
            SET stock_qty = COALESCE(stock_qty, 0) + ?,
                updated_at = NOW()
            WHERE product_id = ?
              AND UPPER(TRIM(office_code)) = ?
        ");
        $up->execute([$qty, $productId, $officeCode]);
        sd_sync_global_stock_after_do($pdo, $productId);
    }
}

/**
 * Kurangi stok WQS berdasarkan DO yang sudah tersimpan.
 * Lebih aman dari $validItems karena office_code diambil langsung dari sales_do.
 * Berlaku untuk semua office: BDG, BGR, BKS, SMG, SLO, TGR, dst.
 */
function sd_reduce_stock_by_do_id(PDO $pdo, int $doId): void
{
    if ($doId <= 0) {
        throw new Exception('DO ID tidak valid untuk pengurangan stok.');
    }

    $salesDoCols = sd_table_columns($pdo, 'sales_do');
    $hasReducedMarker = in_array('stock_reduced_at', $salesDoCols, true);

    if ($hasReducedMarker) {
        $chk = $pdo->prepare("SELECT stock_reduced_at FROM sales_do WHERE id = ? LIMIT 1 FOR UPDATE");
        $chk->execute([$doId]);
        $already = $chk->fetchColumn();
        if ($already !== false && $already !== null && trim((string)$already) !== '') {
            return; // stok DO ini sudah pernah dikurangi, cegah double deduct
        }
    }

    $st = $pdo->prepare("
        SELECT
            UPPER(TRIM(d.office_code)) AS office_code,
            i.product_id,
            MAX(i.sku) AS sku,
            SUM(i.qty) AS total_qty
        FROM sales_do d
        JOIN sales_do_items i ON i.do_id = d.id
        WHERE d.id = ?
        GROUP BY UPPER(TRIM(d.office_code)), i.product_id
    ");
    $st->execute([$doId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        throw new Exception('Item DO tidak ditemukan untuk pengurangan stok.');
    }

    $stockCols = sd_table_columns($pdo, 'wqs_stock_by_office');
    $hasStockId = in_array('id', $stockCols, true);

    foreach ($items as $it) {
        $officeCode = strtoupper(trim((string)($it['office_code'] ?? '')));
        $productId  = (int)($it['product_id'] ?? 0);
        $sku        = (string)($it['sku'] ?? '');
        $qty        = (float)($it['total_qty'] ?? 0);

        if ($officeCode === '' || $productId <= 0 || $qty <= 0) {
            throw new Exception("Data stok tidak valid untuk SKU {$sku}.");
        }

        if ($hasStockId) {
            $lock = $pdo->prepare("
                SELECT id, stock_qty
                FROM wqs_stock_by_office
                WHERE product_id = ?
                  AND UPPER(TRIM(office_code)) = ?
                ORDER BY stock_qty DESC, id ASC
                FOR UPDATE
            ");
            $lock->execute([$productId, $officeCode]);
            $rows = $lock->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                throw new Exception("Stok SKU {$sku} tidak ditemukan di office {$officeCode}.");
            }

            $available = 0.0;
            foreach ($rows as $r) {
                $available += (float)($r['stock_qty'] ?? 0);
            }

            if ($available < $qty) {
                throw new Exception("Stok SKU {$sku} tidak cukup di office {$officeCode}. Tersedia: {$available}, diminta: {$qty}.");
            }

            $remaining = $qty;
            foreach ($rows as $r) {
                if ($remaining <= 0) break;
                $rowId = (int)$r['id'];
                $rowStock = (float)($r['stock_qty'] ?? 0);
                if ($rowId <= 0 || $rowStock <= 0) continue;

                $take = min($rowStock, $remaining);
                $up = $pdo->prepare("
                    UPDATE wqs_stock_by_office
                    SET stock_qty = stock_qty - ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND stock_qty >= ?
                ");
                $up->execute([$take, $rowId, $take]);

                if ($up->rowCount() < 1) {
                    throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}. Stok kemungkinan berubah.");
                }

                $remaining -= $take;
            }

            if ($remaining > 0.00001) {
                throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}. Sisa qty: {$remaining}.");
            }
        } else {
            $lock = $pdo->prepare("
                SELECT stock_qty
                FROM wqs_stock_by_office
                WHERE product_id = ?
                  AND UPPER(TRIM(office_code)) = ?
                LIMIT 1
                FOR UPDATE
            ");
            $lock->execute([$productId, $officeCode]);
            $currentStock = $lock->fetchColumn();

            if ($currentStock === false) {
                throw new Exception("Stok SKU {$sku} tidak ditemukan di office {$officeCode}.");
            }

            $currentStock = (float)$currentStock;

            if ($currentStock < $qty) {
                throw new Exception("Stok SKU {$sku} tidak cukup di office {$officeCode}. Tersedia: {$currentStock}, diminta: {$qty}.");
            }

            $up = $pdo->prepare("
                UPDATE wqs_stock_by_office
                SET stock_qty = stock_qty - ?,
                    updated_at = NOW()
                WHERE product_id = ?
                  AND UPPER(TRIM(office_code)) = ?
                  AND stock_qty >= ?
            ");
            $up->execute([$qty, $productId, $officeCode, $qty]);

            if ($up->rowCount() < 1) {
                throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}. Stok kemungkinan berubah.");
            }
        }

        sd_sync_global_stock_after_do($pdo, $productId);
    }

    if ($hasReducedMarker) {
        $mark = $pdo->prepare("UPDATE sales_do SET stock_reduced_at = NOW() WHERE id = ?");
        $mark->execute([$doId]);
    }
}


/**
 * FINAL FIX: Kurangi stok langsung dari DO tersimpan.
 * Fungsi ini sengaja TIDAK memakai stock_reduced_at untuk DO baru, karena pada beberapa data lama
 * marker bisa terisi tetapi stok belum benar-benar berubah.
 * Dipanggil sekali setelah insert sales_do_items pada blok CREATE DO.
 */
function sd_reduce_stock_by_do_id_force(PDO $pdo, int $doId): void
{
    if ($doId <= 0) {
        throw new Exception('DO ID tidak valid untuk pengurangan stok.');
    }

    if (!sd_has_table($pdo, 'wqs_stock_by_office')) {
        throw new Exception('Table wqs_stock_by_office belum tersedia.');
    }

    $st = $pdo->prepare("
        SELECT
            UPPER(TRIM(d.office_code)) AS office_code,
            i.product_id,
            MAX(i.sku) AS sku,
            SUM(i.qty) AS total_qty
        FROM sales_do d
        JOIN sales_do_items i ON i.do_id = d.id
        WHERE d.id = ?
        GROUP BY UPPER(TRIM(d.office_code)), i.product_id
    ");
    $st->execute([$doId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        throw new Exception('Item DO tidak ditemukan untuk pengurangan stok.');
    }

    $stockCols = sd_table_columns($pdo, 'wqs_stock_by_office');
    $hasStockId = in_array('id', $stockCols, true);
    $hasUpdatedAt = in_array('updated_at', $stockCols, true);
    $setUpdatedAt = $hasUpdatedAt ? ', updated_at = NOW()' : '';

    foreach ($items as $it) {
        $officeCode = strtoupper(trim((string)($it['office_code'] ?? '')));
        $productId  = (int)($it['product_id'] ?? 0);
        $sku        = (string)($it['sku'] ?? '');
        $qty        = (float)($it['total_qty'] ?? 0);

        if ($officeCode === '' || $productId <= 0 || $qty <= 0) {
            throw new Exception("Data stok tidak valid untuk SKU {$sku}.");
        }

        if ($hasStockId) {
            // Jika tabel memiliki ID, kurangi dari baris stok terbesar dahulu.
            // Aman untuk produk yang punya beberapa row stok karena beda lot/serial.
            $lock = $pdo->prepare("
                SELECT id, stock_qty
                FROM wqs_stock_by_office
                WHERE product_id = ?
                  AND UPPER(TRIM(office_code)) = ?
                ORDER BY stock_qty DESC, id ASC
                FOR UPDATE
            ");
            $lock->execute([$productId, $officeCode]);
            $rows = $lock->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                throw new Exception("Stok SKU {$sku} tidak ditemukan di office {$officeCode}.");
            }

            $available = 0.0;
            foreach ($rows as $r) {
                $available += (float)($r['stock_qty'] ?? 0);
            }

            if ($available < $qty) {
                throw new Exception("Stok SKU {$sku} tidak cukup di office {$officeCode}. Tersedia: {$available}, diminta: {$qty}.");
            }

            $remaining = $qty;
            foreach ($rows as $r) {
                if ($remaining <= 0) break;

                $rowId = (int)($r['id'] ?? 0);
                $rowStock = (float)($r['stock_qty'] ?? 0);
                if ($rowId <= 0 || $rowStock <= 0) continue;

                $take = min($rowStock, $remaining);
                $up = $pdo->prepare("
                    UPDATE wqs_stock_by_office
                    SET stock_qty = stock_qty - ?{$setUpdatedAt}
                    WHERE id = ?
                      AND stock_qty >= ?
                ");
                $up->execute([$take, $rowId, $take]);

                if ($up->rowCount() < 1) {
                    throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}.");
                }

                $remaining -= $take;
            }

            if ($remaining > 0.00001) {
                throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}. Sisa qty: {$remaining}.");
            }
        } else {
            // Fallback jika wqs_stock_by_office tidak memiliki kolom id.
            // Cocok untuk struktur unik product_id + office_code.
            $lock = $pdo->prepare("
                SELECT COALESCE(SUM(stock_qty), 0) AS stock_qty
                FROM wqs_stock_by_office
                WHERE product_id = ?
                  AND UPPER(TRIM(office_code)) = ?
                FOR UPDATE
            ");
            $lock->execute([$productId, $officeCode]);
            $available = (float)($lock->fetchColumn() ?: 0);

            if ($available <= 0) {
                throw new Exception("Stok SKU {$sku} tidak ditemukan di office {$officeCode}.");
            }
            if ($available < $qty) {
                throw new Exception("Stok SKU {$sku} tidak cukup di office {$officeCode}. Tersedia: {$available}, diminta: {$qty}.");
            }

            $up = $pdo->prepare("
                UPDATE wqs_stock_by_office
                SET stock_qty = stock_qty - ?{$setUpdatedAt}
                WHERE product_id = ?
                  AND UPPER(TRIM(office_code)) = ?
                  AND stock_qty >= ?
                LIMIT 1
            ");
            $up->execute([$qty, $productId, $officeCode, $qty]);

            if ($up->rowCount() < 1) {
                throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}.");
            }
        }

        sd_sync_global_stock_after_do($pdo, $productId);
    }

    // Marker hanya sebagai informasi, bukan syarat pengurangan.
    if (sd_has_column($pdo, 'sales_do', 'stock_reduced_at')) {
        try {
            $mark = $pdo->prepare("UPDATE sales_do SET stock_reduced_at = NOW() WHERE id = ?");
            $mark->execute([$doId]);
        } catch (Throwable $e) {}
    }
}


// Centralized error logger — konsisten dengan modul lain
$__sd_elg = __DIR__ . '/../_shared/rmi_error_logger.php';
if (is_file($__sd_elg)) require_once $__sd_elg;
unset($__sd_elg);
// --- DB (centralized) ---
$pdo = db_pdo();
// --------------------------------------------------------
// AJAX: LOAD PRODUCTS BY OFFICE STOCK
// --------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'products_by_office') {

    header('Content-Type: application/json; charset=utf-8');

    $office = strtoupper(trim((string)($_GET['office_code'] ?? '')));
    $checkTable = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'wqs_stock_by_office'
");
$checkTable->execute();

if ((int)$checkTable->fetchColumn() === 0) {
    echo json_encode([
        'ok' => false,
        'message' => 'Table wqs_stock_by_office belum tersedia',
        'products' => []
    ]);
    exit;
}

    if ($office === '') {
        echo json_encode([
            'ok' => false,
            'message' => 'office_code kosong',
            'products' => []
        ]);
        exit;
    }

    try {
        $mpCols = $pdo->query("SHOW COLUMNS FROM master_products")->fetchAll(PDO::FETCH_COLUMN, 0);

       $hasBarcode = in_array('barcode', $mpCols, true);
$hasType    = in_array('product_type', $mpCols, true);
$hasPrice   = in_array('price', $mpCols, true);

$sql = "
    SELECT
        p.id,
        p.sku,
        p.products_name,
        p.unit,
        " . ($hasPrice ? "COALESCE(p.price, 0) AS price" : "0 AS price") . ",
        " . ($hasBarcode ? "p.barcode" : "'' AS barcode") . ",
        " . ($hasType ? "p.product_type" : "'' AS product_type") . ",
        COALESCE(s.stock_qty, 0) AS stock_qty,
        s.office_code,
        s.updated_at
    FROM wqs_stock_by_office s
    INNER JOIN master_products p ON p.id = s.product_id
    WHERE UPPER(TRIM(s.office_code)) = UPPER(TRIM(?))
      AND LOWER(TRIM(COALESCE(p.status, 'active'))) = 'active'
      AND COALESCE(s.stock_qty, 0) > 0
    ORDER BY p.products_name ASC
";

        $st = $pdo->prepare($sql);
        $st->execute([$office]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => true,
            'office_code' => $office,
            'products' => $rows
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
            'products' => []
        ]);
        exit;
    }
}

$perm_sales_view = function_exists('can_any') ? can_any(['SALES.VIEW']) : true;
$perm_sales_create = function_exists('can') ? can('SALES.CREATE') : true;
$perm_sales_edit = function_exists('can') ? can('SALES.EDIT') : true;
$perm_sales_delete = function_exists('can') ? can('SALES.DELETE') : true;
$perm_sales_export = function_exists('can') ? can('SALES.EXPORT') : true;

// ── Office Scope (BRANCH staff hanya bisa akses DO office sendiri) ──────────
$_sd_user       = function_exists('auth_user') ? auth_user() : [];

// Gunakan ?: bukan ?? agar empty string '' juga dianggap falsy dan bisa fallback
$_sd_user_dept  = strtoupper(trim((string)($_sd_user['department'] ?: $_sd_user['level'] ?: '')));
$_sd_user_role  = strtoupper(trim((string)($_sd_user['role'] ?? '')));
$_sd_user_office = strtoupper(trim((string)($_sd_user['office_code'] ?? '')));

// BRANCH user → scope ke office sendiri. SYS/ADMIN/dept lain → lihat semua.
// Cek department ATAU level, salah satu BRANCH sudah cukup.
$_sd_is_branch = in_array($_sd_user_dept, ['BRANCH'], true)
              && !in_array($_sd_user_role, ['SYS','ADMIN','SUPERADMIN'], true);

// Pastikan office_code tidak kosong sebelum scope diterapkan
$_sd_office_scope = ($_sd_is_branch && $_sd_user_office !== '') ? $_sd_user_office : null;

/**
 * Cek apakah user boleh akses DO ini.
 * Mengembalikan true jika bukan BRANCH, atau jika office_code DO cocok.
 */
function sd_can_access_do(array $do_row): bool {
    global $_sd_office_scope;
    if ($_sd_office_scope === null) return true;
    return strtoupper((string)($do_row['office_code'] ?? '')) === $_sd_office_scope;
}

$can_bulk_send_wqs = $perm_sales_edit;
$can_bulk_delete = $perm_sales_delete;

$CRM_ALLOWED_EDIT_STATUSES = ['crm_to_wqs','sent_wqs'];
$is_locked = false;
$lock_reason = '';
$LOCK_BLOCK_POST = false;

// detect editing id from URL
$editing_id = 0;
if (isset($_GET['edit'])) $editing_id = (int)$_GET['edit'];
elseif (isset($_GET['id'])) $editing_id = (int)$_GET['id'];

if ($editing_id > 0) {
    try {
        $st = $pdo->prepare("SELECT status FROM sales_do WHERE id=? LIMIT 1");
        $st->execute([$editing_id]);
        $row = $st->fetch();
        $cur_status = (string)($row['status'] ?? '');
        if ($cur_status !== '' && !in_array($cur_status, $CRM_ALLOWED_EDIT_STATUSES, true)) {
            $is_locked = true;
            $lock_reason = "DO sudah diproses departemen berikutnya (status: {$cur_status}). CRM hanya bisa melihat (read-only).";
        }
    } catch (Throwable $e) {
        // fail-soft
    }
}

// Apply lock guard for POST (prevent update on locked DO)
if (!$LOCK_BLOCK_POST && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $post_id = 0;
    if (isset($_POST['id'])) $post_id = (int)$_POST['id'];
    elseif (isset($_POST['do_id'])) $post_id = (int)$_POST['do_id'];
    elseif (isset($_POST['sales_do_id'])) $post_id = (int)$_POST['sales_do_id'];
    elseif (isset($_POST['header_id'])) $post_id = (int)$_POST['header_id'];

    if ($post_id > 0) {
        try {
            $st = $pdo->prepare("SELECT status FROM sales_do WHERE id=? LIMIT 1");
            $st->execute([$post_id]);
            $row = $st->fetch();
            $cur_status = (string)($row['status'] ?? '');
            if ($cur_status !== '' && !in_array($cur_status, $CRM_ALLOWED_EDIT_STATUSES, true)) {
                $LOCK_BLOCK_POST = true;
                $is_locked = true;
                $lock_reason = "DO sudah diproses departemen berikutnya (status: {$cur_status}). Perubahan CRM ditolak (read-only).";
            }
        } catch (Throwable $e) {
            // fail-soft
        }
    }
}

// --------------------------------------------------------
// Schema readiness check (DDL moved to SQL migrations)
// --------------------------------------------------------
function sd_has_table(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$table]);
    return ((int)$st->fetchColumn()) > 0;
}
function sd_has_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$table, $column]);
    return ((int)$st->fetchColumn()) > 0;
}
$sd_schema_errors = [];
if (!sd_has_table($pdo, 'sales_do')) {
    $sd_schema_errors[] = 'Table sales_do belum tersedia. Jalankan migration.';
}
if (!sd_has_table($pdo, 'sales_do_items')) {
    $sd_schema_errors[] = 'Table sales_do_items belum tersedia. Jalankan migration.';
}
foreach (['tracking_code','tax_code','tax_rate_percent','tax_amount','grand_total','is_price_include_tax','crm_start_at','crm_finish_at','crm_duration_sec'] as $col) {
    if (sd_has_table($pdo, 'sales_do') && !sd_has_column($pdo, 'sales_do', $col)) {
        $sd_schema_errors[] = "Kolom sales_do.{$col} belum tersedia. Jalankan migration.";
    }
}
foreach (['barcode','stock_at_crm','show_package_items','exp_date','serial_lot'] as $col) {
    if (sd_has_table($pdo, 'sales_do_items') && !sd_has_column($pdo, 'sales_do_items', $col)) {
        $sd_schema_errors[] = "Kolom sales_do_items.{$col} belum tersedia. Jalankan migration.";
    }
}
// Auto-migrate: tambah kolom category ke sales_do dan sales_do_items jika belum ada
if (sd_has_table($pdo, 'sales_do') && !sd_has_column($pdo, 'sales_do', 'category')) {
    try { $pdo->exec("ALTER TABLE `sales_do` ADD COLUMN `category` VARCHAR(20) NOT NULL DEFAULT 'BMHP'"); }
    catch (Throwable $e) {}
}
if (sd_has_table($pdo, 'sales_do_items') && !sd_has_column($pdo, 'sales_do_items', 'category')) {
    try { $pdo->exec("ALTER TABLE `sales_do_items` ADD COLUMN `category` VARCHAR(20) NOT NULL DEFAULT 'BMHP'"); }
    catch (Throwable $e) {}
}
if (sd_has_table($pdo, 'sales_do') && !sd_has_column($pdo, 'sales_do', 'stock_reduced_at')) {
    try { $pdo->exec("ALTER TABLE `sales_do` ADD COLUMN `stock_reduced_at` DATETIME NULL AFTER updated_at"); }
    catch (Throwable $e) {}
}


// --------------------------------------------------------
// SESSION & FLASH
// --------------------------------------------------------
function set_flash_do($type, $message)
{
    $_SESSION['flash_sales_do'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function get_flash_do()
{
    if (!empty($_SESSION['flash_sales_do'])) {
        $f = $_SESSION['flash_sales_do'];
        unset($_SESSION['flash_sales_do']);
        return $f;
    }
    return null;
}

// --------------------------------------------------------
// LOAD MASTER DATA
// --------------------------------------------------------
$customers    = [];
$offices      = [];
$products     = [];
$productsMap  = [];
$taxProfiles  = [];
$taxMap       = [];
$picUsers     = []; // hook ke master_user (nanti bisa difilter per customer)
$mprContacts  = []; // PIC external customer dari master_mpr
try {
    // Customers
    $stmt = $pdo->query("
        SELECT customers_code, customers_name, office_code, city, address, phone, email
        FROM master_customers
        WHERE status = 'active'
        ORDER BY customers_name
    ");
    $customers = $stmt->fetchAll();

    // Offices — BRANCH user hanya lihat office sendiri, yang lain lihat semua
    if (!empty($GLOBALS['_sd_office_scope'])) {
        $stOff = $pdo->prepare("SELECT office_code, office_name, city, address FROM master_office WHERE is_active=1 AND office_code=? ORDER BY office_name");
        $stOff->execute([$GLOBALS['_sd_office_scope']]);
    } else {
        $stOff = $pdo->query("SELECT office_code, office_name, city, address FROM master_office WHERE is_active=1 ORDER BY office_name");
    }
    $offices = $stOff->fetchAll();

    // Products (fail-soft kolom): barcode, product_type, package_items, manufacture_code
        $mpCols = [];
        try {
            $mpCols = $pdo->query("SHOW COLUMNS FROM master_products")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (Throwable $e) { $mpCols = []; }

        $sel = ['p.id','p.sku','p.products_name','p.unit','p.price'];
        if (in_array('barcode', $mpCols, true))         $sel[] = 'p.barcode';
        if (in_array('product_type', $mpCols, true))    $sel[] = 'p.product_type';
        if (in_array('package_items', $mpCols, true))   $sel[] = 'p.package_items';
        if (in_array('manufacture_code', $mpCols, true)) $sel[] = 'p.manufacture_code';
        if (in_array('manufactures_code', $mpCols, true)) $sel[] = 'p.manufactures_code';

        $hasWqsStock = sd_has_table($pdo, 'wqs_stock');
        if ($hasWqsStock) {
            // Pakai stock_left_join_subquery() dari _shared/stock_helper.php
            // — aman dari duplikat karena sudah diagregasi per product_id
            $productsSql = "SELECT " . implode(',', $sel) . ", COALESCE(s.stock_qty, 0) AS stock_qty
                FROM master_products p
                " . stock_left_join_subquery() . "
                WHERE p.status='active'
                ORDER BY p.products_name";
        } else {
            $productsSql = "SELECT " . implode(',', $sel) . ", 0 AS stock_qty
                FROM master_products p
                WHERE p.status='active'
                ORDER BY p.products_name";
        }
        $stmt = $pdo->query($productsSql);
        $products = $stmt->fetchAll();
        foreach ($products as $p) { $productsMap[$p['id']] = $p; }

    // Tax profile (Transaction level saja)
    $stmt = $pdo->query("
        SELECT tax_code, tax_name, tax_type, rate_percent, level_type, office_scope, status
        FROM master_tax
        WHERE status = 'active'
          AND level_type = 'Transaction'
        ORDER BY tax_type, tax_name
    ");
    $taxProfiles = $stmt->fetchAll();
    foreach ($taxProfiles as $t) {
        $taxMap[$t['tax_code']] = $t;
    }

    // Hook PIC dari master_user (aman)
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'master_user'");
        if ($check->rowCount() > 0) {
            $stmt = $pdo->query("
                SELECT id, user_code, user_name, email, phone
                FROM master_user
                WHERE status = 'active'
                ORDER BY user_name
            ");
            $picUsers = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        $picUsers = [];
    }
    // Hook PIC External Customer dari master_mpr (aman)
    try {
        $checkMpr = $pdo->query("SHOW TABLES LIKE 'master_mpr'");
        if ($checkMpr->rowCount() > 0) {
            $stmt = $pdo->query("
                SELECT id, customers_code, contact_name, role_title, department, phone, email, is_primary
                FROM master_mpr
                WHERE status = 'active'
                ORDER BY customers_code, is_primary DESC, contact_name ASC
            ");
            $mprContacts = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        $mprContacts = [];
    }
} catch (PDOException $e) {
    set_flash_do('danger', 'Gagal mengambil master data: ' . htmlspecialchars($e->getMessage()));
}

// --------------------------------------------------------
// HELPER: GENERATE DO CODE — format diatur di config/doc_numbering.php
// Thread-safe: pakai SELECT MAX + advisory lock untuk mencegah duplikat.
// --------------------------------------------------------
if (!function_exists('doc_prefix_do')) {
    require_once __DIR__ . '/../config/doc_numbering.php';
}
function generate_do_code(PDO $pdo, $office_code, $dateYmd, string $category = 'BMHP'): string
{
    $prefix = doc_prefix_do((string)$office_code, (string)$dateYmd, $category);

    // Gunakan GET_LOCK() untuk serialisasi per prefix (mencegah race condition)
    $lockKey = 'do_code_' . md5($prefix);
    try {
        $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockKey) . ", 5)")->fetchColumn();
    } catch (Throwable $e) {
        // fail-soft: lanjutkan tanpa lock (risiko lebih kecil dari crash)
    }

    try {
        // Ambil nomor urut tertinggi yang sudah ada hari ini
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(CAST(RIGHT(do_code, 3) AS UNSIGNED)), 0)
             FROM sales_do
             WHERE do_code LIKE :pref"
        );
        $stmt->execute([':pref' => $prefix . '%']);
        $maxSeq = (int)$stmt->fetchColumn();
        $nextNo = $maxSeq + 1;
        $code   = $prefix . str_pad((string)$nextNo, 3, '0', STR_PAD_LEFT);
    } finally {
        // Selalu release lock
        try {
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockKey) . ")")->fetchColumn();
        } catch (Throwable $e) {}
    }

    return $code;
}

// helper format durasi
function format_duration($sec)
{
    $sec = (int)$sec;
    if ($sec <= 0) return '-';

    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;

    $parts = [];
    if ($h > 0) $parts[] = $h . ' jam';
    if ($m > 0) $parts[] = $m . ' menit';
    if ($s > 0 || empty($parts)) $parts[] = $s . ' detik';

    return implode(' ', $parts);
}

function flow_step_from_status($status)
{
    $s = (string)$status;
    switch ($s) {
        case 'crm_to_wqs':
        case 'sent_wqs':
        case 'wqs_processing':
            return 'WQS';
        case 'ready_scm':
        case 'on_delivery':
            return 'SCM';
        case 'delivered':
            return 'ACT';
        case 'wait_payment':
            return 'FIN';
        case 'paid':
            return 'PAID';
        default:
            return 'CRM';
    }
}

// --------------------------------------------------------
// SALES_DO_AUDIT helpers (AUTO LOG) - CRM module
// --------------------------------------------------------
function get_actor_name_crm(): string {
    if (function_exists('current_actor_username')) {
        return current_actor_username();
    }
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['username']) && $_SESSION['username'] !== '') {
        return (string)$_SESSION['username'];
    }
    return 'SYSTEM';
}

function log_sales_do_audit(PDO $pdo, int $do_id, string $status_from, string $status_to, string $actor_dept, string $actor_name, string $note): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sales_do_audit
                (do_id, status_from, status_to, actor_dept, actor_name, note, created_at)
            VALUES
                (:do_id, :status_from, :status_to, :actor_dept, :actor_name, :note, NOW())
        ");
        $stmt->execute([
            ':do_id'       => $do_id,
            ':status_from' => $status_from,
            ':status_to'   => $status_to,
            ':actor_dept'  => $actor_dept,
            ':actor_name'  => $actor_name,
            ':note'        => $note,
        ]);
    } catch (Throwable $e) {
        // fail-soft
    }
}

// --------------------------------------------------------
// HANDLE BULK ACTION (SEND WQS / DELETE)
// --------------------------------------------------------
if (!$LOCK_BLOCK_POST && empty($sd_schema_errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['bulk_action']) || isset($_POST['bulk_delete_selected']))) {
    $action = strtolower(trim((string)($_POST['bulk_action'] ?? '')));
    if (isset($_POST['bulk_delete_selected'])) {
        $action = 'delete';
    }
    if (in_array($action, ['hapus', 'bulk_delete', 'delete_selected'], true)) {
        $action = 'delete';
    }
    if (in_array($action, ['wqs', 'set_wqs', 'send_to_wqs'], true)) {
        $action = 'send_wqs';
    }

    $ids = $_POST['selected_ids'] ?? [];
    $idsInt = array_map('intval', (array)$ids);
    $idsInt = array_values(array_filter($idsInt, fn($v) => $v > 0));

    if (empty($idsInt)) {
        set_flash_do('danger', 'Tidak ada DO yang dipilih. Centang DO yang ingin diproses.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }

    if (!in_array($action, ['send_wqs', 'delete'], true)) {
        set_flash_do('danger', 'Aksi bulk belum dipilih. Pilih "Hapus" atau klik tombol "Hapus DO Terpilih".');
        rmi_redirect($_SERVER['PHP_SELF']);
    }

    if ($action === 'send_wqs' && !$can_bulk_send_wqs) {
        set_flash_do('danger', 'Tidak ada izin EDIT untuk bulk Set ke WQS.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }
    if ($action === 'delete' && !$can_bulk_delete) {
        set_flash_do('danger', 'Tidak ada izin DELETE untuk bulk hapus DO.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }

    try {
        $in = implode(',', array_fill(0, count($idsInt), '?'));

        $stChk = $pdo->prepare("SELECT id, status, office_code, do_code FROM sales_do WHERE id IN ($in)");
        $stChk->execute($idsInt);
        $rows = $stChk->fetchAll(PDO::FETCH_ASSOC);
        $foundIds = array_map(fn($r) => (int)$r['id'], $rows);

        if (empty($rows)) {
            throw new Exception('DO yang dipilih tidak ditemukan.');
        }

        $bad = [];
        $codesBefore = [];
        foreach ($rows as $rr) {
            $did = (int)$rr['id'];
            $codesBefore[$did] = (string)($rr['do_code'] ?? '');
            if (!sd_can_access_do($rr)) {
                $bad[] = 'id=' . $did . ' akses kantor lain';
                continue;
            }
            $st = (string)($rr['status'] ?? '');
            if ($st !== '' && !in_array($st, $CRM_ALLOWED_EDIT_STATUSES, true)) {
                $bad[] = 'id=' . $did . ' status=' . $st;
            }
        }
        if (!empty($bad)) {
            throw new Exception('Bulk action ditolak. Ada DO yang sudah terkunci / tidak boleh diproses: ' . implode('; ', $bad));
        }

        if ($action === 'delete') {
            $pdo->beginTransaction();

            foreach ($foundIds as $did) {
                sd_restore_stock_by_do_id_for_delete($pdo, (int)$did);
            }

            $inFound = implode(',', array_fill(0, count($foundIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM sales_do_items WHERE do_id IN ($inFound)");
            $stmt->execute($foundIds);

            $stmt = $pdo->prepare("DELETE FROM sales_do WHERE id IN ($inFound)");
            $stmt->execute($foundIds);

            if ($pdo->inTransaction()) { $pdo->commit(); }

            try {
                if (function_exists('master_audit')) {
                    foreach ($foundIds as $did) {
                        $code = $codesBefore[$did] ?? '';
                        master_audit($pdo, 'sales_do', 'sales_do', 'CRM_BULK_DELETE', $did, $code, "DO bulk deleted: {$code}", []);
                    }
                }
            } catch (Throwable $auditEx) {}

            set_flash_do('success', 'Data DO terpilih berhasil dihapus. Jika stok DO sudah pernah dikurangi, stok sudah dikembalikan.');
        } elseif ($action === 'send_wqs') {
            $oldMap = [];
            foreach ($rows as $rr) {
                $oldMap[(int)$rr['id']] = (string)($rr['status'] ?? '');
            }

            foreach ($oldMap as $__fromStatus) {
                if (function_exists('auth_sales_do_require_transition')) {
                    auth_sales_do_require_transition((string)$__fromStatus, 'crm_to_wqs', 'CRM_BULK_SEND_WQS');
                }
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE sales_do SET status = 'crm_to_wqs' WHERE id IN ($in)");
            $stmt->execute($idsInt);

            $actor_name = get_actor_name_crm();
            foreach ($idsInt as $did) {
                $from = $oldMap[(int)$did] ?? '';
                log_sales_do_audit($pdo, (int)$did, $from, 'crm_to_wqs', 'CRM', $actor_name, 'BULK_SEND_WQS');
            }

            if ($pdo->inTransaction()) { $pdo->commit(); }

            try {
                if (function_exists('master_audit')) {
                    foreach ($idsInt as $did) {
                        $code = $codesBefore[$did] ?? '';
                        master_audit($pdo, 'sales_do', 'sales_do', 'CRM_BULK_SEND_WQS', $did, $code, "DO bulk send WQS: {$code}", []);
                    }
                }
            } catch (Throwable $auditEx) {}

            set_flash_do('success', 'Data DO terpilih berhasil di-set ke WQS.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash_do('danger', 'Bulk action gagal: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}

// --------------------------------------------------------
// HANDLE DELETE SINGLE (POST-only)
// --------------------------------------------------------
if (!$LOCK_BLOCK_POST && empty($sd_schema_errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_single'])) {
    if (!$perm_sales_delete) {
        set_flash_do('danger', 'Tidak ada izin DELETE untuk hapus DO.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }
    $id = (int)($_POST['delete_single'] ?? 0);

    try {
        $st = $pdo->prepare("SELECT id, do_code, status, office_code FROM sales_do WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $delRow = $st->fetch(PDO::FETCH_ASSOC);
        if (!$delRow) {
            throw new Exception('DO tidak ditemukan.');
        }

        $do_code = (string)($delRow['do_code'] ?? '');
        $cur = (string)($delRow['status'] ?? '');

        if (!sd_can_access_do($delRow)) {
            throw new Exception('Akses ditolak: tidak bisa menghapus DO kantor lain.');
        }

        if ($cur !== '' && !in_array($cur, $CRM_ALLOWED_EDIT_STATUSES, true)) {
            throw new Exception('Tidak bisa hapus. DO sudah diproses departemen berikutnya (status: ' . $cur . ').');
        }

        $pdo->beginTransaction();

        sd_restore_stock_by_do_id_for_delete($pdo, $id);

        $stmt = $pdo->prepare("DELETE FROM sales_do_items WHERE do_id = :id");
        $stmt->execute([':id' => $id]);

        $stmt = $pdo->prepare("DELETE FROM sales_do WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($pdo->inTransaction()) { $pdo->commit(); }

        try {
            if (function_exists('master_audit')) {
                master_audit($pdo, 'sales_do', 'sales_do', 'CRM_DELETE', $id, $do_code, "DO deleted: {$do_code}", []);
            }
        } catch (Throwable $auditEx) {}

        set_flash_do('success', 'Data DO berhasil dihapus. Jika stok DO sudah pernah dikurangi, stok sudah dikembalikan.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash_do('danger', 'Gagal menghapus DO: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}

// --------------------------------------------------------
// HANDLE SUBMIT FORM (CREATE / UPDATE)
// --------------------------------------------------------
$flash         = null;
$last_do_id    = isset($_SESSION['last_do_id']) ? (int)$_SESSION['last_do_id'] : null;
if ($last_do_id) {
    unset($_SESSION['last_do_id']);
}

if (!$LOCK_BLOCK_POST && empty($sd_schema_errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_do'])) {

    $do_id_post      = isset($_POST['do_id']) ? (int)$_POST['do_id'] : 0;
    $is_update       = $do_id_post > 0;
    if (!$is_update && !$perm_sales_create) {
        set_flash_do('danger', 'Tidak ada izin CREATE untuk membuat DO.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }
    if ($is_update && !$perm_sales_edit) {
        set_flash_do('danger', 'Tidak ada izin EDIT untuk mengubah DO.');
        rmi_redirect($_SERVER['PHP_SELF'] . '?edit=' . $do_id_post);
    }

    // Guard BRANCH: cek kepemilikan DO sebelum edit/update
    if ($is_update && $_sd_office_scope !== null) {
        try {
            $stOwn = $pdo->prepare("SELECT office_code FROM sales_do WHERE id=? LIMIT 1");
            $stOwn->execute([$do_id_post]);
            $ownRow = $stOwn->fetch(PDO::FETCH_ASSOC);
            if ($ownRow && !sd_can_access_do($ownRow)) {
                set_flash_do('danger', 'Akses ditolak: tidak bisa mengubah DO kantor lain.');
                rmi_redirect($_SERVER['PHP_SELF']);
            }
        } catch (Throwable $e) {}
    }

    $customers_code   = trim($_POST['customers_code'] ?? '');
    $office_code      = trim($_POST['office_code'] ?? '');

    // Guard BRANCH: paksa office_code ke office sendiri, tolak jika coba set ke kantor lain
    if ($_sd_office_scope !== null) {
        if ($office_code === '' || strtoupper($office_code) !== $_sd_office_scope) {
            $office_code = $_sd_office_scope;
        }
    }
    // Kategori dokumen (Opsi A: 1 DO = 1 kategori)
    $do_category = strtoupper(trim($_POST['do_category'] ?? 'BMHP'));
    if (!in_array($do_category, ['BMHP','ALKES','AKSESORIS'], true)) $do_category = 'BMHP';

    $do_date_raw      = trim($_POST['do_date'] ?? '');
    $customer_pic     = trim($_POST['customer_pic'] ?? '');
    $customer_phone   = trim($_POST['customer_phone'] ?? '');
    $shipping_address = trim($_POST['shipping_address'] ?? '');
    $note             = trim($_POST['note'] ?? '');
    $tax_code_post    = trim($_POST['tax_code'] ?? '');
    $include_tax      = isset($_POST['include_tax']) ? 1 : 0;

    // CRM timer
    $crm_start_ts = isset($_POST['crm_start_ts']) ? (int)$_POST['crm_start_ts'] : null;
    $now_ts       = time();

    $errors = [];

    if ($customers_code === '') $errors[] = 'Customer wajib dipilih.';
    if ($office_code === '')    $errors[] = 'Office penanggung jawab wajib dipilih.';
    if ($do_date_raw === '')    $errors[] = 'Tanggal DO wajib diisi.';

    // Normalisasi tanggal
    $do_date = null;
    if ($do_date_raw !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $do_date_raw);
        if ($d && $d->format('Y-m-d') === $do_date_raw) {
            $do_date = $do_date_raw;
        } else {
            $errors[] = 'Format tanggal DO tidak valid.';
        }
    }

    // Ambil tax rate dari master_tax (kalau ada)
    $tax_rate_percent = 0;
    if ($tax_code_post !== '' && isset($taxMap[$tax_code_post])) {
        $tax_rate_percent = (float)$taxMap[$tax_code_post]['rate_percent'];
    }

    // Ambil item produk
    $items       = $_POST['items'] ?? [];
    $validItems  = [];
    $totalAmount = 0;

    if (empty($items)) {
        $errors[] = 'Minimal 1 produk harus diisi.';
    } else {
        if (!sd_has_table($pdo, 'wqs_stock_by_office')) {
    $errors[] = 'Table wqs_stock_by_office belum tersedia. Stok CRM tidak bisa divalidasi per office.';
}
        $lineNo = 0;
        foreach ($items as $row) {
            $product_id = (int)($row['product_id'] ?? 0);
            $qty        = (int)($row['qty'] ?? 0);
            $price_raw  = trim($row['unit_price'] ?? '0');
            $disc_raw   = trim($row['disc_percent'] ?? '0');
            $show_pkg   = isset($row['show_package_items']) ? 1 : 0;
            $stock_crm  = isset($row['stock_at_crm']) ? (int)$row['stock_at_crm'] : null;

            $exp_raw    = trim($row['exp_date'] ?? '');
            $serial_raw = trim($row['serial_lot'] ?? '');

            if ($product_id <= 0 || $qty <= 0) continue;
            if ($qty > 100000) {
                $errors[] = 'Qty item terlalu besar (maks 100000 per baris).';
                continue;
            }
            $lineNo++;

            $price = (float)str_replace(',', '.', preg_replace('/[^0-9,\.]/', '', $price_raw));
            if ($price < 0) {
                $errors[] = 'Harga item tidak boleh negatif.';
                continue;
            }
            $disc  = (float)str_replace(',', '.', preg_replace('/[^0-9,\.]/', '', $disc_raw));
            if ($disc < 0) $disc = 0;
            if ($disc > 100) $disc = 100;

            $product = $productsMap[$product_id] ?? null;
            if (!$product) continue;

            $sku      = rmi_sku($product['sku']);
            $pname    = rmi_product_name($product['products_name']);
            $unit     = $product['unit'] ?? '';
            $barcode  = $product['barcode'] ?? '';

            if ($include_tax && $tax_rate_percent > 0) {
                $netPrice = $price / (1 + ($tax_rate_percent / 100));
            } else {
                $netPrice = $price;
            }

            $netAfterDisc = $netPrice * (100 - $disc) / 100;
          $officeStock = 0;
try {
    $stStock = $pdo->prepare("
        SELECT COALESCE(SUM(stock_qty), 0)
        FROM wqs_stock_by_office
        WHERE product_id = ?
          AND UPPER(TRIM(office_code)) = UPPER(TRIM(?))
    ");
    $stStock->execute([$product_id, $office_code]);
    $officeStock = (float)($stStock->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $officeStock = 0;
}

$stock_crm = $officeStock;

if ($qty > $officeStock) {
    $errors[] = 'Qty item melebihi stok office ' . htmlspecialchars($office_code, ENT_QUOTES, 'UTF-8') .
        ' untuk SKU ' . htmlspecialchars((string)$sku, ENT_QUOTES, 'UTF-8') .
        '. Stok tersedia: ' . number_format($officeStock, 0, ',', '.');
    continue;
}
            $lineSubtotal = $qty * $netAfterDisc;

            // Ambil category produk dari master untuk denormalisasi ke items
            $itemCategory = $do_category; // fallback ke kategori DO
            try {
                $stCat = $pdo->prepare("SELECT category FROM master_products WHERE id=? LIMIT 1");
                $stCat->execute([$product_id]);
                $catRow = $stCat->fetchColumn();
                if ($catRow !== false && $catRow !== '') {
                    $itemCategory = strtoupper(trim((string)$catRow));
                    if (!in_array($itemCategory, ['BMHP','ALKES','AKSESORIS'], true)) $itemCategory = $do_category;
                }
            } catch (Throwable $e) {}

            $validItems[] = [
                'line_no'            => $lineNo,
                'product_id'         => $product_id,
                'sku'                => $sku,
                'products_name'      => $pname,
                'qty'                => $qty,
                'unit'               => $unit,
                'unit_price'         => $price,
                'disc_percent'       => $disc,
                'subtotal'           => $lineSubtotal,
                'barcode'            => $barcode,
                'stock_at_crm'       => $stock_crm,
                'show_package_items' => $show_pkg,
                'exp_date'           => $exp_raw !== '' ? $exp_raw : null,
                'serial_lot'         => $serial_raw !== '' ? $serial_raw : null,
                'category'           => $itemCategory,
            ];

            $totalAmount += $lineSubtotal;
        }

        if (empty($validItems)) {
            $errors[] = 'Minimal 1 produk valid (qty > 0) wajib diisi.';
        }
    }

    // Hitung PPN / TAX dari totalAmount net
    $tax_code   = $tax_code_post;
    $tax_amount = 0;
    $grand_total = $totalAmount;

    if ($tax_rate_percent > 0 && $totalAmount > 0) {
        $tax_amount  = $totalAmount * $tax_rate_percent / 100;
        $grand_total = $totalAmount + $tax_amount;
    }

    // Status DO: sekarang HANYA ke WQS (tidak ada Draft manual)
    $status = 'crm_to_wqs';

    // CRM timer: kalau DO baru, hitung durasi. Kalau edit, biarkan nilai lama.
    $crm_start_at    = null;
    $crm_finish_at   = null;
    $crm_duration_sec = null;

    if (!$is_update) {
        if ($crm_start_ts) {
            $crm_start_at     = date('Y-m-d H:i:s', $crm_start_ts);
            $crm_finish_at    = date('Y-m-d H:i:s', $now_ts);
            $crm_duration_sec = max(0, $now_ts - $crm_start_ts);
        } else {
            $crm_start_at     = date('Y-m-d H:i:s', $now_ts);
            $crm_finish_at    = $crm_start_at;
            $crm_duration_sec = 0;
        }
    }

    if (!empty($errors)) {
        set_flash_do('danger', implode('<br>', $errors));
        rmi_redirect($_SERVER['PHP_SELF'] . ($is_update ? ('?edit=' . $do_id_post) : ''));
    }

    try {
        // CREATE DO sengaja tidak memakai PDO transaction karena pada server ini sering muncul
        // error "There is no active transaction" setelah helper/audit/DDL runtime.
        // UPDATE tetap memakai transaction karena tidak mengurangi stok otomatis.
        if ($is_update && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        if ($is_update) {
            // Ambil status lama untuk audit (sebelum update)
            $oldStatus = '';
            try {
                $stOld = $pdo->prepare("SELECT status FROM sales_do WHERE id = :id LIMIT 1");
                $stOld->execute([':id' => $do_id_post]);
                $oldStatus = (string)$stOld->fetchColumn();
            } catch (Throwable $e) {
                $oldStatus = '';
            }

            // UPDATE HEADER & ITEMS (status TIDAK diubah saat edit)
            $stmt = $pdo->prepare("
                UPDATE sales_do
                SET
                    customers_code       = :customers_code,
                    office_code          = :office_code,
                    do_date              = :do_date,
                    shipping_address     = :shipping_address,
                    customer_pic         = :customer_pic,
                    customer_phone       = :customer_phone,
                    note                 = :note,
                    total_amount         = :total_amount,
                    tax_code             = :tax_code,
                    tax_rate_percent     = :tax_rate_percent,
                    tax_amount           = :tax_amount,
                    grand_total          = :grand_total,
                    is_price_include_tax = :include_tax
                WHERE id = :id
            ");
            $stmt->execute([
                ':customers_code'       => $customers_code,
                ':office_code'          => $office_code,
                ':do_date'              => $do_date,
                ':shipping_address'     => $shipping_address,
                ':customer_pic'         => $customer_pic,
                ':customer_phone'       => $customer_phone,
                ':note'                 => $note,
                ':total_amount'         => $totalAmount,
                ':tax_code'             => $tax_code,
                ':tax_rate_percent'     => $tax_rate_percent,
                ':tax_amount'           => $tax_amount,
                ':grand_total'          => $grand_total,
                ':include_tax'          => $include_tax,
                ':id'                   => $do_id_post,
            ]);

            // AUTO AUDIT (CRM): EDIT DO (status tidak berubah)
            $actor_name = get_actor_name_crm();
            $stForAudit = $oldStatus !== '' ? $oldStatus : 'UNKNOWN';
            log_sales_do_audit($pdo, (int)$do_id_post, $stForAudit, $stForAudit, 'CRM', $actor_name, 'EDIT_DO');
            if (function_exists('master_audit')) {
                $stCode = $pdo->prepare("SELECT do_code FROM sales_do WHERE id = ? LIMIT 1");
                $stCode->execute([$do_id_post]);
                $code = (string)($stCode->fetchColumn() ?: '');
                master_audit($pdo, 'sales_do', 'sales_do', 'CRM_UPDATE', $do_id_post, $code, "DO updated: {$code}", []);
            }

            // hapus detail lama, insert ulang
            $del = $pdo->prepare("DELETE FROM sales_do_items WHERE do_id = :id");
            $del->execute([':id' => $do_id_post]);

            $stmtItem = $pdo->prepare("
                INSERT INTO sales_do_items
                (
                    do_id, line_no, product_id,
                    sku, products_name,
                    qty, unit, exp_date, serial_lot,
                    unit_price, disc_percent, subtotal,
                    barcode, stock_at_crm, show_package_items
                )
                VALUES
                (
                    :do_id, :line_no, :product_id,
                    :sku, :products_name,
                    :qty, :unit, :exp_date, :serial_lot,
                    :unit_price, :disc_percent, :subtotal,
                    :barcode, :stock_at_crm, :show_package_items
                )
            ");

            foreach ($validItems as $it) {
                $stmtItem->execute([
                    ':do_id'              => $do_id_post,
                    ':line_no'            => $it['line_no'],
                    ':product_id'         => $it['product_id'],
                    ':sku'                => $it['sku'],
                    ':products_name'      => $it['products_name'],
                    ':qty'                => $it['qty'],
                    ':unit'               => $it['unit'],
                    ':exp_date'           => $it['exp_date'],
                    ':serial_lot'         => $it['serial_lot'],
                    ':unit_price'         => $it['unit_price'],
                    ':disc_percent'       => $it['disc_percent'],
                    ':subtotal'           => $it['subtotal'],
                    ':barcode'            => $it['barcode'],
                    ':stock_at_crm'       => $it['stock_at_crm'],
                    ':show_package_items' => $it['show_package_items'],
                ]);
            }

            if ($pdo->inTransaction()) { $pdo->commit(); }
            $_SESSION['last_do_id'] = $do_id_post;
            set_flash_do('success', "DO berhasil diupdate.");

        } else {
            // DO BARU
            $dateYmd6  = date('ymd', strtotime($do_date)); // YYMMDD
            $do_code   = generate_do_code($pdo, $office_code, $dateYmd6, $do_category);
            $trackCode = $do_code;

            $stmt = $pdo->prepare("
                INSERT INTO sales_do
                (
                    do_code, tracking_code, do_date,
                    customers_code, office_code, sales_emp_code,
                    shipping_address, customer_pic, customer_phone,
                    status, note, total_amount,
                    tax_code, tax_rate_percent, tax_amount, grand_total,
                    is_price_include_tax, category,
                    crm_created_at, crm_start_at, crm_finish_at, crm_duration_sec,
                    created_at, updated_at
                )
                VALUES
                (
                    :do_code, :tracking_code, :do_date,
                    :customers_code, :office_code, :sales_emp_code,
                    :shipping_address, :customer_pic, :customer_phone,
                    :status, :note, :total_amount,
                    :tax_code, :tax_rate_percent, :tax_amount, :grand_total,
                    :include_tax, :category,
                    NOW(), :crm_start_at, :crm_finish_at, :crm_duration_sec,
                    NOW(), NOW()
                )
            ");
            $stmt->execute([
                ':do_code'          => $do_code,
                ':tracking_code'    => $trackCode,
                ':do_date'          => $do_date,
                ':customers_code'   => $customers_code,
                ':office_code'      => $office_code,
                ':sales_emp_code'   => null,
                ':shipping_address' => $shipping_address,
                ':customer_pic'     => $customer_pic,
                ':customer_phone'   => $customer_phone,
                ':status'           => $status,
                ':note'             => $note,
                ':total_amount'     => $totalAmount,
                ':tax_code'         => $tax_code,
                ':tax_rate_percent' => $tax_rate_percent,
                ':tax_amount'       => $tax_amount,
                ':grand_total'      => $grand_total,
                ':include_tax'      => $include_tax,
                ':category'         => $do_category,
                ':crm_start_at'     => $crm_start_at,
                ':crm_finish_at'    => $crm_finish_at,
                ':crm_duration_sec' => $crm_duration_sec,
            ]);

            $do_id = (int)$pdo->lastInsertId();

            // AUTO AUDIT (CRM): CREATE & SEND TO WQS
            // Catatan penting:
            // master_audit() sengaja TIDAK dipanggil di dalam transaksi utama.
            // Beberapa helper audit dapat melakukan commit/rollback sendiri sehingga
            // menyebabkan error: "There is no active transaction".
            $actor_name = get_actor_name_crm();
            log_sales_do_audit($pdo, $do_id, 'NEW', $status, 'CRM', $actor_name, 'CREATE_DO');

            // NEW DO item insert include exp_date, serial_lot, category
            $stmtItem = $pdo->prepare("
                INSERT INTO sales_do_items
                (
                    do_id, line_no, product_id,
                    sku, products_name,
                    qty, unit, exp_date, serial_lot,
                    unit_price, disc_percent, subtotal,
                    barcode, stock_at_crm, show_package_items, category
                )
                VALUES
                (
                    :do_id, :line_no, :product_id,
                    :sku, :products_name,
                    :qty, :unit, :exp_date, :serial_lot,
                    :unit_price, :disc_percent, :subtotal,
                    :barcode, :stock_at_crm, :show_package_items, :category
                )
            ");

            foreach ($validItems as $it) {
                $stmtItem->execute([
                    ':do_id'              => $do_id,
                    ':line_no'            => $it['line_no'],
                    ':product_id'         => $it['product_id'],
                    ':sku'                => $it['sku'],
                    ':products_name'      => $it['products_name'],
                    ':qty'                => $it['qty'],
                    ':unit'               => $it['unit'],
                    ':exp_date'           => $it['exp_date'],
                    ':serial_lot'         => $it['serial_lot'],
                    ':unit_price'         => $it['unit_price'],
                    ':disc_percent'       => $it['disc_percent'],
                    ':subtotal'           => $it['subtotal'],
                    ':barcode'            => $it['barcode'],
                    ':stock_at_crm'       => $it['stock_at_crm'],
                    ':show_package_items' => $it['show_package_items'],
                    ':category'           => $it['category'] ?? $do_category,
                ]);
            }

            // KURANGI STOK WQS OFFICE setelah item DO tersimpan.
            // Direct update ini sengaja dibuat tanpa bergantung pada status transaction,
            // agar stok tetap berkurang di semua office meskipun server mematikan transaksi PDO.
            $stockBeforeReduce = [];
            $stStockRows = $pdo->prepare("
                SELECT
                    i.product_id,
                    MAX(i.sku) AS sku,
                    SUM(i.qty) AS total_qty,
                    COALESCE(SUM(w.stock_qty), 0) AS stock_available
                FROM sales_do_items i
                LEFT JOIN wqs_stock_by_office w
                       ON w.product_id = i.product_id
                      AND UPPER(TRIM(w.office_code)) = UPPER(TRIM(?))
                WHERE i.do_id = ?
                GROUP BY i.product_id
            " );
            $stStockRows->execute([$office_code, $do_id]);
            $stockRows = $stStockRows->fetchAll(PDO::FETCH_ASSOC);

            foreach ($stockRows as $sr) {
                $pid = (int)($sr['product_id'] ?? 0);
                $skuCheck = (string)($sr['sku'] ?? '');
                $needQty = (float)($sr['total_qty'] ?? 0);
                $availableQty = (float)($sr['stock_available'] ?? 0);
                if ($pid <= 0 || $needQty <= 0) {
                    throw new Exception("Data stok tidak valid untuk SKU {$skuCheck}.");
                }
                if ($availableQty < $needQty) {
                    throw new Exception("Stok SKU {$skuCheck} tidak cukup di office {$office_code}. Tersedia: {$availableQty}, diminta: {$needQty}.");
                }
                $stockBeforeReduce[$pid] = $availableQty;
            }

            // Kurangi stok per office. Untuk struktur normal product_id+office_code satu baris, ini langsung berhasil.
            $reduceStock = $pdo->prepare("
                UPDATE wqs_stock_by_office w
                JOIN sales_do_items i ON i.product_id = w.product_id
                SET
                    w.stock_qty = w.stock_qty - i.qty,
                    w.updated_at = NOW()
                WHERE i.do_id = ?
                  AND UPPER(TRIM(w.office_code)) = UPPER(TRIM(?))
                  AND w.stock_qty >= i.qty
            " );
            $reduceStock->execute([$do_id, $office_code]);

            if ($reduceStock->rowCount() < count($stockRows)) {
                throw new Exception('Stok WQS gagal dikurangi. Cek product_id, office_code, atau stok tidak cukup.');
            }

            // Sync stok global dari total semua office, tanpa kolom source.
            foreach ($stockRows as $sr) {
                $pid = (int)($sr['product_id'] ?? 0);
                if ($pid > 0) {
                    sd_sync_global_stock_after_do($pdo, $pid);
                }
            }

            // Catat marker jika kolom tersedia, hanya informasi.
            if (sd_has_column($pdo, 'sales_do', 'stock_reduced_at')) {
                try {
                    $markStock = $pdo->prepare("UPDATE sales_do SET stock_reduced_at = NOW() WHERE id = ?");
                    $markStock->execute([$do_id]);
                } catch (Throwable $eMarkStock) {}
            }

            // COMMIT hanya jika benar-benar ada transaction aktif.
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            // Audit dilakukan SETELAH commit agar audit tidak merusak transaksi DO/stok.
            try {
                if (function_exists('master_audit')) {
                    master_audit(
                        $pdo,
                        'sales_do',
                        'sales_do',
                        'CRM_CREATE',
                        $do_id,
                        $do_code,
                        "DO created: {$do_code}",
                        ['status' => $status]
                    );

                    master_audit(
                        $pdo,
                        'sales_do',
                        'wqs_stock_by_office',
                        'STOCK_REDUCE_BY_DO',
                        $do_id,
                        $do_code,
                        "Stock reduced by Sales DO: {$do_code}",
                        [
                            'office_code' => $office_code,
                            'items' => array_map(function ($x) {
                                return [
                                    'product_id' => $x['product_id'],
                                    'sku' => $x['sku'],
                                    'qty' => $x['qty'],
                                ];
                            }, $validItems)
                        ]
                    );
                }
            } catch (Throwable $auditEx) {
                // Audit gagal tidak boleh menggagalkan DO dan pengurangan stok.
            }

            $_SESSION['last_do_id'] = $do_id;
            set_flash_do('success', "DO <strong>{$do_code}</strong> berhasil dibuat & dikirim ke WQS.");
        }

        rmi_redirect($_SERVER['PHP_SELF']);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        $errAction = $is_update ? 'DO_SAVE_FAILED_UPDATE' : 'DO_SAVE_FAILED_CREATE';
        $errCtx = [
            // Konteks bisnis
            'action'         => $errAction,
            'do_id'          => $is_update ? ($do_id_post ?? null) : null,
            'customers_code' => $customers_code ?? null,
            'office_code'    => $office_code ?? null,
            'do_date'        => $do_date ?? null,
            // Konteks teknis (redundan tapi memudahkan baca log di DB audit)
            'error_message'  => $e->getMessage(),
            'error_class'    => get_class($e),
            'error_file'     => $e->getFile(),
            'error_line'     => $e->getLine(),
            'ip'             => $_SERVER['REMOTE_ADDR'] ?? '',
            'actor'          => $_SESSION['username'] ?? ($_SESSION['user_name'] ?? ''),
        ];

        // Gunakan centralized logger (konsisten dengan modul lain)
        if (function_exists('rmi_log_module_error')) {
            rmi_log_module_error('sales_do', $e, $errCtx);
        } else {
            // Fallback minimal jika logger belum di-load
            try {
                $logDir = __DIR__ . '/../storage/logs';
                if (!is_dir($logDir)) @mkdir($logDir, 0770, true);
                @file_put_contents(
                    $logDir . '/sales_errors.log',
                    date('[Y-m-d H:i:s]') . ' ERROR ' . $errAction . ' | user=' . ($_SESSION['username'] ?? '?')
                        . ' | ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine() . "\n",
                    FILE_APPEND | LOCK_EX
                );
            } catch (Throwable $fileEx) {}
        }

        // Juga catat ke DB audit (fail-soft)
        try {
            master_audit($pdo, 'SALES_DO', 'sales_do', $errAction, 0, '', $e->getMessage(), $errCtx);
        } catch (Throwable $logEx) {}

        set_flash_do('danger', 'Gagal menyimpan DO: ' . htmlspecialchars($e->getMessage()));
        rmi_redirect($_SERVER['PHP_SELF'] . ($is_update ? ('?edit=' . $do_id_post) : ''));
    }
}

$flash = get_flash_do();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($sd_schema_errors) && $flash === null) {
    $flash = [
        'type' => 'danger',
        'message' => implode('<br>', array_map('h', $sd_schema_errors)),
    ];
}

// --------------------------------------------------------
// AMBIL DATA EDIT (JIKA ADA ?edit=ID)
// --------------------------------------------------------
$edit_id    = 0;
$edit_do    = null;
$edit_items = [];

if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM sales_do WHERE id = :id");
        $stmt->execute([':id' => $edit_id]);
        $edit_do = $stmt->fetch();

        // Guard: BRANCH hanya boleh edit DO kantor sendiri
        if ($edit_do && !sd_can_access_do($edit_do)) {
            set_flash_do('danger', 'Akses ditolak: DO ini bukan milik kantor Anda (' . h($_sd_office_scope) . ').');
            $edit_do = null;
            $edit_id = 0;
        }

        if ($edit_do) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM sales_do_items
                WHERE do_id = :id
                ORDER BY line_no ASC, id ASC
            ");
            $stmt->execute([':id' => $edit_id]);
            $edit_items = $stmt->fetchAll();
        }
    }
}

$can_save_form = $edit_do ? $perm_sales_edit : $perm_sales_create;

// --------------------------------------------------------
// LIST DATA DO UNTUK TABEL
// --------------------------------------------------------
$source_filter = trim((string)($_GET['source'] ?? ''));
$do_list = [];
try {
    $sql = "
        SELECT
            d.*,
            c.customers_name,
            o.office_name
        FROM sales_do d
        LEFT JOIN master_customers c ON c.customers_code = d.customers_code
        LEFT JOIN master_office     o ON o.office_code = d.office_code
    ";
    $params = [];
    $whereClauses = [];

    // Scope BRANCH: hanya DO kantor sendiri
    if ($_sd_office_scope !== null) {
        $whereClauses[] = "d.office_code = :scope_office";
        $params[':scope_office'] = $_sd_office_scope;
    }

    if (sd_has_column($pdo, 'sales_do', 'source') && $source_filter !== '') {
        $whereClauses[] = "d.source = :src";
        $params[':src'] = $source_filter;
    }

    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(' AND ', $whereClauses);
    }

    $sql .= " ORDER BY d.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $do_list = $stmt->fetchAll();
} catch (PDOException $e) {
    if (function_exists('rmi_log_module_error')) {
        rmi_log_module_error('sales_do', $e, [
            'action'         => 'DO_LIST_QUERY_FAILED',
            'source_filter'  => $source_filter ?? '',
            'office_scope'   => $_sd_office_scope ?? null,
            'error_message'  => $e->getMessage(),
            'error_class'    => get_class($e),
            'error_file'     => $e->getFile(),
            'error_line'     => $e->getLine(),
        ]);
    } else {
        try {
            $logDir = __DIR__ . '/../storage/logs';
            if (!is_dir($logDir)) @mkdir($logDir, 0770, true);
            @file_put_contents(
                $logDir . '/sales_errors.log',
                date('[Y-m-d H:i:s]') . ' ERROR DO_LIST_QUERY_FAILED | ' . $e->getMessage() . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable $logEx) {}
    }
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$extraHead = '<link rel="stylesheet" href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209">' .
  '<link rel="stylesheet" href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209">' .
  '<style>
        table.dataTable.table-dark-custom tbody tr.odd td,
        table.dataTable.table-dark-custom tbody tr.even td {
            color: #e5e7eb;
        }

        table.dataTable.table-dark-custom tbody tr.even td {
            background-color: #020617 !important;
        }

        body {
            background:
                radial-gradient(circle at 0% -20%, #0f172a 0, transparent 50%),
                radial-gradient(circle at 100% 120%, #111827 0, transparent 55%),
                radial-gradient(circle at 50% 0%, #0b1120 0, transparent 55%),
                #020617;
            color: #e5e7eb;
            font-size: 14px;
            min-height: 100vh;
        }
        .rmi-container { max-width: 1200px; margin: 20px auto 30px auto; }
        .rmi-card {
            border-radius: 14px;
            border: 1px solid #1f2937;
            background: rgba(15, 23, 42, 0.96);
            box-shadow:
                0 18px 45px rgba(0, 0, 0, 0.7),
                0 0 0 1px rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            margin-bottom: 20px;
        }
        .rmi-card-header {
            padding: 14px 18px;
            border-bottom: 1px solid #1f2937;
            background: linear-gradient(135deg, #020617 0%, #020617 40%, #0f172a 100%);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #e5e7eb;
        }
        .rmi-card-header h5 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .rmi-card-body { padding: 16px 18px 18px 18px; }
        .badge-step { border-radius: 999px; padding: 6px 14px; font-size: 11px; margin-right: 6px; }
        .badge-step.active { background: #2563eb; }
        .badge-step.muted { background: #111827; color: #9ca3af; border: 1px solid #1f2937; }
        .form-control, .form-select {
            background-color: #020617;
            border: 1px solid #374151;
            color: #e5e7eb;
            font-size: 13px;
        }
        .form-control::placeholder { color: #6b7280; }
        .form-control:focus, .form-select:focus {
            background-color: #020617;
            color: #e5e7eb;
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.4);
        }
        .form-label { color: #e5e7eb; }
        .text-muted-small { font-size: 11px; color: #9ca3af; }
        .btn-primary { background: linear-gradient(135deg, #0ea5e9, #2563eb); border: none; }
        .btn-primary:hover { background: linear-gradient(135deg, #38bdf8, #1d4ed8); }
        .btn-secondary { background-color: #374151; border-color: #4b5563; }
        .btn-outline-light { border-color: #4b5563; color: #e5e7eb; }
        .btn-outline-light:hover { background-color: #111827; color: #e5e7eb; }
        .btn-outline-danger { border-color: #f97373; color: #fecaca; }
        .btn-outline-danger:hover { background-color: #ef4444; color: #0f172a; }
        .btn-outline-primary { border-color: #60a5fa; color: #bfdbfe; }
        .btn-outline-primary:hover { background-color: #3b82f6; color: #0b1120; }
        .table-dark-custom {
            --bs-table-bg: #020617;
            --bs-table-striped-bg: #020617;
            --bs-table-striped-color: #e5e7eb;
            --bs-table-border-color: #1f2937;
            --bs-table-hover-bg: #0f172a;
            color: #e5e7eb;
            border-color: #1f2937;
            font-size: 12px;
        }
        .table thead th { background-color: #020617; font-size: 11px; color: #e5e7eb; white-space: nowrap; }
        .table tbody td { vertical-align: middle; font-size: 12px; }
        .grand-total { font-size: 18px; font-weight: 600; color: #38bdf8; }
        .badge-status { border-radius: 999px; padding: 2px 8px; font-size: 11px; }
        .badge-status.crm_to_wqs { background-color:#22c55e; color:#022c22;}
        .badge-status.other { background-color:#a855f7; color:#1e1b4b;}

        .badge-flow { border-radius: 999px; padding: 2px 8px; font-size: 10px; }
        .badge-flow-CRM  { background-color:#3b82f6; color:#dbeafe;}
        .badge-flow-WQS  { background-color:#22c55e; color:#dcfce7;}
        .badge-flow-SCM  { background-color:#f97316; color:#fff7ed;}
        .badge-flow-ACT  { background-color:#eab308; color:#fefce8;}
        .badge-flow-FIN  { background-color:#a855f7; color:#f5f3ff;}
        .badge-flow-DONE { background-color:#10b981; color:#ecfdf5;}

        .clock-badge {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(15,23,42,0.9);
            border: 1px solid #1f2937;
            color: #f9a8d4;
        }

        .table-dark-custom{
            --bs-table-color: #e5e7eb;
            --bs-table-bg: transparent;
            --bs-table-striped-color: #e5e7eb;
            --bs-table-striped-bg: rgba(255,255,255,.02);
            --bs-table-hover-color: #f9fafb;
            --bs-table-hover-bg: rgba(255,255,255,.06);
            --bs-table-active-color: #f9fafb;
            --bs-table-active-bg: rgba(255,255,255,.10);
            color: var(--bs-table-color) !important;
        }
        .table-dark-custom th,
        .table-dark-custom td{ color: var(--bs-table-color) !important; }
        .table-dark-custom a,
        .table-dark-custom a:visited{ color: inherit !important; }
        .table-dark-custom .text-muted,
        .table-dark-custom .text-secondary{ color: rgba(229,231,235,.78) !important; }
        table.dataTable.table-dark-custom th,
        table.dataTable.table-dark-custom td,
        .table-dark-custom .nowrap,
        .table-dark-custom td.nowrap,
        .table-dark-custom th.nowrap{ white-space: normal !important; }
        table.dataTable.table-dark-custom td{
            overflow: visible !important;
            text-overflow: clip !important;
            word-break: break-word;
        }
    </style>';

rmi_header('CRM – Sales DO / Order', 'sales', [
    'subtitle' => 'CRM → WQS → SCM → ACT → FIN.',
    'breadcrumbs' => [
        ['label' => 'Sales (CRM)', 'url' => $baseProject . '/sales/sales_dashboard.php'],
        'Sales DO / Order',
    ],
    'actions' => [
        ['label' => 'CRM Dashboard', 'url' => 'sales_dashboard.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Open Chat (DO)', 'url' => '../chat/index.php?context=DO:LIST', 'class' => 'btn btn-sm btn-outline-light'],
    ],
    'extra_head' => $extraHead,
]);
?>

<?php if (!empty($is_locked)): ?>
  <div style="max-width:1100px;margin:14px auto 0;padding:0 14px;">
    <div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.25);color:#fff7ed;padding:10px 12px;border-radius:12px;font-size:12px;">
      🔒 <b>READ-ONLY</b> — <?php echo h($lock_reason); ?>
    </div>
  </div>
<?php endif; ?>

<div class="rmi-container">

    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>CRM – SALES DO / ORDER</h5>
                <small class="text-muted-small">
                    Step: Customer &amp; produk. Setelah disimpan, tugas langsung diteruskan ke WQS.
                </small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge-step active">CRM (Now)</span>
                <span class="badge-step muted">WQS</span>
                <span class="badge-step muted">SCM</span>
                <span class="badge-step muted">ACT</span>
                <span class="badge-step muted">FIN</span>

                <span class="clock-badge ms-2" id="clock-display">--</span>

                <a href="../sales/sales_dashboard.php" class="btn btn-sm btn-secondary ms-3">
                    &laquo; Kembali ke Modul Penjualan
                </a>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= function_exists('rmi_h') ? rmi_h($flash['message']) : htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($sd_schema_errors)): ?>
        <div class="alert alert-danger" role="alert">
            <?= implode('<br>', array_map('h', $sd_schema_errors)) ?>
        </div>
    <?php endif; ?>

    <?php if ($last_do_id): ?>
        <div class="alert alert-info py-2 px-3 mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted-small">
                    DO terakhir berhasil disimpan. Kamu bisa preview / print langsung di sini.
                </div>
                <div class="d-flex gap-2">
                    <a href="sales_do_view.php?id=<?= (int)$last_do_id ?>" target="_blank" class="btn btn-sm btn-outline-light">
                        Preview DO Terakhir
                    </a>
                    <a href="sales_do_view.php?id=<?= (int)$last_do_id ?>&mode=print" target="_blank" class="btn btn-sm btn-outline-light">
                        Print DO Terakhir
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- FORM BUAT / EDIT DO -->
    <form method="post" id="do-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="do_id" value="<?= $edit_do ? (int)$edit_do['id'] : '' ?>">
        <input type="hidden" name="crm_start_ts" id="crm_start_ts" value="">

        <!-- INFORMASI UMUM & CUSTOMER -->
        <div class="card rmi-card">
            <div class="rmi-card-header"><h5>INFORMASI UMUM &amp; CUSTOMER</h5></div>
            <div class="rmi-card-body">

                <!-- KATEGORI DO — harus dipilih dulu, menentukan prefix nomor -->
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Kategori Dokumen (DO)<span class="text-danger">*</span></label>
                        <?php $curCat = strtoupper(trim((string)($edit_do['category'] ?? 'BMHP'))); if (!in_array($curCat,['BMHP','ALKES','AKSESORIS'],true)) $curCat='BMHP'; ?>
                        <div class="d-flex gap-2 flex-wrap mt-1">
                            <?php foreach ([
                                'BMHP'      => ['label' => '🩺 BMHP',      'sub' => 'Bahan Medis Habis Pakai', 'color' => 'rgba(59,130,246,.2)',  'border' => 'rgba(59,130,246,.5)'],
                                'ALKES'     => ['label' => '⚕️ ALKES',      'sub' => 'Alat Kesehatan Durable', 'color' => 'rgba(16,185,129,.2)',  'border' => 'rgba(16,185,129,.5)'],
                                'AKSESORIS' => ['label' => '🔌 AKSESORIS',  'sub' => 'Aksesori Alat Kesehatan','color' => 'rgba(245,158,11,.2)', 'border' => 'rgba(245,158,11,.5)'],
                            ] as $catVal => $catInfo): ?>
                            <label style="cursor:pointer;display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:10px;border:2px solid <?= $catInfo['border'] ?>;background:<?= $catInfo['color'] ?>;min-width:180px">
                                <input type="radio" name="do_category" value="<?= $catVal ?>" <?= $curCat===$catVal?'checked':'' ?> style="accent-color:currentColor">
                                <div>
                                    <div style="font-weight:700;font-size:13px"><?= $catInfo['label'] ?></div>
                                    <div style="font-size:11px;opacity:.7"><?= $catInfo['sub'] ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-muted-small mt-1">Kategori menentukan prefix nomor DO. Contoh: <strong>BMHP-BGR-260318-001</strong></div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Customer<span class="text-danger">*</span></label>
                        <select name="customers_code" id="customers_code" class="form-select form-select-sm">
                            <option value="">-- Pilih Customer --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= htmlspecialchars($c['customers_code']) ?>"
                                    data-office-code="<?= htmlspecialchars(strtoupper(trim($c['office_code'] ?? ''))) ?>"
                                    <?= ($edit_do && $edit_do['customers_code'] === $c['customers_code']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['customers_code'] . ' - ' . $c['customers_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted-small">Rumah sakit / klinik / pelanggan.</div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Office Penanggung Jawab<span class="text-danger">*</span></label>
                        <select name="office_code" class="form-select form-select-sm">
                            <option value="">-- Pilih Office --</option>
                            <?php foreach ($offices as $o): ?>
                                <option value="<?= htmlspecialchars(strtoupper($o['office_code'])) ?>"
                                    <?= ($edit_do && strtoupper($edit_do['office_code']) === strtoupper($o['office_code'])) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(strtoupper($o['office_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted-small">Kantor / cabang yang handle transaksi.</div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Tanggal DO<span class="text-danger">*</span></label>
                        <input type="date" name="do_date" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($edit_do ? $edit_do['do_date'] : date('Y-m-d')) ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Profil Pajak / PPN</label>
                        <select name="tax_code" class="form-select form-select-sm" id="tax-code-select">
                            <option value="">-- Pilih Profil Pajak --</option>
                            <?php $selected_tax_code = $edit_do['tax_code'] ?? ''; ?>
                            <?php foreach ($taxProfiles as $t): ?>
                                <?php
                                $code = $t['tax_code'];
                                $name = $t['tax_name'];
                                $rate = (float)$t['rate_percent'];
                                $label = $name;
                                if ($rate > 0) $label .= " ({$rate}%)";
                                ?>
                                <option value="<?= htmlspecialchars($code) ?>"
                                        data-rate="<?= htmlspecialchars($rate) ?>"
                                    <?= ($selected_tax_code === $code) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted-small">Diambil dari Master Tax Profile (level Transaction).</div>
                    </div>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-md-6">
                        <label class="form-label">Alamat Kirim</label>
                        <textarea name="shipping_address" id="shipping_address" class="form-control form-control-sm" rows="2"
                                  placeholder="Alamat kirim, bisa auto dari master_customers"><?= htmlspecialchars($edit_do['shipping_address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">PIC External (Purchasing Customer)</label>
                        <div class="mb-2">
  <label class="form-label">PIC External (Purchasing) - Dropdown (Master PIC Customer)</label>
  <select id="ext_pic_select" class="form-select form-select-sm" <?= !empty($is_locked) ? 'disabled' : '' ?>>
    <option value="">-- pilih PIC Purchasing (opsional) --</option>
  </select>
  <div class="text-muted" style="font-size:12px; margin-top:4px;">
    Sumber: master_mpr (diisi lewat Master PIC Customers / master_user.php). Pilih dropdown untuk mengisi Nama PIC + WA/HP.
  </div>
</div>
                        <input type="text" name="customer_pic" id="customer_pic" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($edit_do['customer_pic'] ?? '') ?>"
                               placeholder="Nama PIC di RS / klinik">
                        <div class="text-muted-small">(Nanti bisa dihubungkan ke master_user untuk pilihan otomatis.)</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">WA/HP Purchasing</label>
                        <input type="text" name="customer_phone" id="customer_phone" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($edit_do['customer_phone'] ?? '') ?>"
                               placeholder="No HP / WA PIC">
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label class="form-label mb-1">Harga termasuk PPN?</label>
                        <?php $include_tax_checked = ($edit_do && !empty($edit_do['is_price_include_tax'])) ? 'checked' : ''; ?>
                        <div class="form-check text-muted-small">
                            <input class="form-check-input" type="checkbox" name="include_tax" id="include_tax"
                                   value="1" <?= $include_tax_checked ?>>
                            <label class="form-check-label" for="include_tax">
                                Centang jika harga produk yang diinput sudah termasuk PPN (include tax).
                            </label>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- DAFTAR PRODUK -->
        <div class="card rmi-card">
            <div class="rmi-card-header"><h5>DAFTAR PRODUK</h5></div>
            <div class="rmi-card-body">
                <div class="table-responsive mb-2">
                    <table class="table table-sm table-dark-custom align-middle mb-0" id="table-items">
                        <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th style="width:260px;">Produk</th>
                            <th style="width:120px;">SKU</th>
                            <th style="width:90px;">Barcode</th>
                            <th style="width:70px;">Qty</th>
                            <th style="width:90px;">Satuan</th>
                            <th style="width:120px;">Exp Date</th>
                            <th style="width:140px;">Serial / Lot</th>
                            <th style="width:120px;" class="text-end">Harga</th>
                            <th style="width:80px;" class="text-end">Disc (%)</th>
                            <th style="width:80px;" class="text-end">Stok</th>
                            <th style="width:130px;" class="text-end">Subtotal</th>
                            <th style="width:80px;" class="text-center">Paket?</th>
                            <th style="width:40px;" class="text-center">Aksi</th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-2">
                    <button type="button" class="btn btn-sm btn-outline-light" id="btn-add-row">+ Tambah Baris</button>
                    <div class="text-end">
                        <div class="text-muted-small">Subtotal (sebelum PPN)</div>
                        <div class="fw-semibold" id="label-subtotal">Rp 0</div>

                        <div class="text-muted-small mt-1">PPN / Tax</div>
                        <div class="fw-semibold" id="label-tax">Rp 0</div>

                        <div class="text-muted-small mt-1">Grand Total (estimasi)</div>
                        <div class="grand-total" id="grand-total">Rp 0</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CATATAN & AKSI SIMPAN -->
        <div class="card rmi-card">
            <div class="rmi-card-header"><h5>CATATAN &amp; AKSI SIMPAN</h5></div>
            <div class="rmi-card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Catatan untuk WQS / SCM / ACT / FIN</label>
                        <textarea name="note" class="form-control form-control-sm" rows="2"
                                  placeholder="Contoh: mohon kirim pagi, butuh bantuan instalasi, dll."><?= htmlspecialchars($edit_do['note'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Aksi</label>
                        <div class="text-muted-small mb-2">
                            DO akan langsung berstatus <strong>CRM → WQS</strong> setelah disimpan.
                        </div>

                        <div class="d-flex flex-column gap-2 mt-1">
                            <button type="submit" name="save_do" class="btn btn-sm btn-primary" <?= $can_save_form ? '' : 'disabled' ?>>
                                <?= $edit_do ? 'Update & Kirim ke WQS' : 'Simpan & Kirim ke WQS' ?>
                            </button>
                            <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn btn-sm btn-secondary">Reset Form</a>
                            <?php if ($edit_do): ?>
                                <a href="sales_do_view.php?id=<?= (int)$edit_do['id'] ?>" target="_blank" class="btn btn-sm btn-outline-light">
                                    Preview DO Ini
                                </a>
                            <?php endif; ?>
                            <?php if (!$can_save_form): ?>
                                <small class="text-warning">Anda tidak memiliki izin <?= $edit_do ? 'EDIT' : 'CREATE' ?> DO.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </form>

    <!-- LIST DO + BULK ACTION -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>LIST DO (CRM)</h5>
                <small class="text-muted-small">DO yang dibuat dari CRM. Bisa di-export Copy / CSV / Excel / PDF / Print.</small>
            </div>
            <?php if (function_exists('auth_is_admin') && auth_is_admin()): ?>
            <div class="d-flex gap-2">
                <a href="<?= htmlspecialchars($baseProject . '/dashboards/finance/sales_do_rekap.php?all_period=1') ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" title="Rekap untuk validasi angka Dashboard Finance">
                    Rekap Dashboard
                </a>
                <a href="<?= htmlspecialchars($baseProject . '/master/audit_logs.php?module=SALES_DO') ?>" class="btn btn-sm btn-outline-warning" target="_blank" rel="noopener" title="Lihat audit log semua aktivitas DO">
                    <?= rmi_icon('clipboard') ?> Audit Log
                </a>
                <?php
                // sales_errors.log adalah file yang dipakai centralized logger untuk modul sales/*
                $errLogFile = __DIR__ . '/../storage/logs/sales_errors.log';
                if (file_exists($errLogFile) && filesize($errLogFile) > 0):
                ?>
                <a href="<?= htmlspecialchars($baseProject . '/tools/view_error_log.php?file=sales_errors') ?>" class="btn btn-sm btn-outline-danger" target="_blank" rel="noopener" title="Ada error log Sales DO — klik untuk lihat">
                    <?= rmi_icon('warn') ?> Error Log
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="rmi-card-body">

            <form method="post" id="bulk-form">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="row g-2 mb-2 align-items-center">
                    <div class="col-auto">
                        <span class="text-muted-small">Filter:</span>
                        <a href="?source=" class="btn btn-sm btn-outline-<?= $source_filter === '' ? 'primary' : 'secondary' ?>">Semua</a>
                        <?php if (sd_has_column($pdo, 'sales_do', 'source')): ?>
                        <a href="?source=h2h" class="btn btn-sm btn-outline-<?= $source_filter === 'h2h' ? 'success' : 'secondary' ?>">H2H</a>
                        <a href="?source=portal" class="btn btn-sm btn-outline-<?= $source_filter === 'portal' ? 'info' : 'secondary' ?>">Portal</a>
                        <a href="?source=crm" class="btn btn-sm btn-outline-<?= $source_filter === 'crm' ? 'warning' : 'secondary' ?>">CRM</a>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <select name="bulk_action" class="form-select form-select-sm">
                            <option value="">-- Bulk Action --</option>
                            <?php if ($can_bulk_send_wqs): ?><option value="send_wqs">Set ke WQS</option><?php endif; ?>
                            <?php if ($can_bulk_delete): ?><option value="delete">Hapus</option><?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-outline-light" <?= ($can_bulk_send_wqs || $can_bulk_delete) ? '' : 'disabled' ?>>Terapkan ke yang dipilih</button>
                        <?php if ($can_bulk_delete): ?>
                        <button type="submit" name="bulk_delete_selected" value="1" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus DO yang dicentang? Jika stok sudah pernah berkurang, stok akan dikembalikan.');">Hapus DO Terpilih</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="table-do" class="table table-sm table-hover align-middle table-dark-custom" style="width:100%">
                        <thead>
                        <tr>
                            <th><input type="checkbox" id="check-all"></th>
                            <th>DO Code</th>
                            <th>Tracking</th>
                            <th>Dibuat</th>
                            <th>Customer</th>
                            <th>Office</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">PPN</th>
                            <th class="text-end">Grand Total</th>
                            <th>Durasi CRM</th>
                            <th>Flow</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                        </thead>
                        <tbody>
<?php foreach ($do_list as $d):
    $id        = (int)($d['id'] ?? 0);
    $do_code   = (string)($d['do_code'] ?? '');
    $tracking  = (string)($d['tracking_code'] ?? '');
    $tgl_raw        = (string)($d['do_date'] ?? '');
    $tgl_show       = $tgl_raw !== '' ? date('d-m-Y', strtotime($tgl_raw)) : '';
    $created_raw    = (string)($d['created_at'] ?? '');
    $created_show   = $created_raw !== '' ? date('d-m-Y H:i', strtotime($created_raw)) : $tgl_show;
    $created_time   = $created_raw !== '' ? date('H:i', strtotime($created_raw)) : '';
    // ISO datetime string untuk sorting DataTables yang benar
    $sort_ts        = $created_raw !== '' ? date('Y-m-d H:i:s', strtotime($created_raw)) : $tgl_raw;

    $cust_name = (string)($d['customers_name'] ?? '');
    $cust_code = (string)($d['customers_code'] ?? '');
    $cust_show = $cust_name !== '' ? $cust_name : $cust_code;

    $off_name  = (string)($d['office_name'] ?? '');
    $off_code  = (string)($d['office_code'] ?? '');
    $off_show  = $off_name !== '' ? $off_name : $off_code;

    $total     = (float)($d['total_amount'] ?? 0);
    $ppn       = (float)($d['tax_amount'] ?? 0);
    $grand     = (float)($d['grand_total'] ?? 0);

    $dur       = format_duration($d['crm_duration_sec'] ?? 0);
    $flow      = flow_step_from_status($d['status'] ?? '');
    $flow_badge = $flow === 'PAID' ? 'DONE' : $flow;

    $status    = (string)($d['status'] ?? '');
    $status_class = ($status === 'crm_to_wqs') ? 'crm_to_wqs' : 'other';
    $can_edit_row = $perm_sales_edit && in_array($status, $CRM_ALLOWED_EDIT_STATUSES, true);
    $can_delete_row = $perm_sales_delete && in_array($status, $CRM_ALLOWED_EDIT_STATUSES, true);
?>
  <tr>
    <td><input type="checkbox" class="row-check" name="selected_ids[]" value="<?= $id ?>" <?= ($can_bulk_send_wqs || $can_bulk_delete) ? '' : 'disabled' ?>></td>

    <td>
      <div class="fw-semibold"><?= h(strtoupper($do_code)) ?><?php if (($d['source'] ?? '') === 'portal'): ?> <span class="badge bg-info" title="Order dari Customer Portal">Portal</span><?php endif; ?><?php if (($d['source'] ?? '') === 'h2h'): ?> <span class="badge bg-success" title="Order dari H2H/API Partner">H2H</span><?php endif; ?></div>
      <div class="text-muted-small">
        <a href="sales_do_view.php?id=<?= $id ?>" target="_blank">Detail</a>
        <span class="text-muted">•</span>
        <a href="sales_do_view.php?id=<?= $id ?>&mode=print" target="_blank">Print</a>
      </div>
    </td>

    <td><?= h(strtoupper($tracking)) ?></td>
    <td data-order="<?= h($sort_ts) ?>">
      <div style="font-weight:600;white-space:nowrap"><?= h($tgl_show) ?></div>
      <?php if ($created_time): ?>
      <div style="font-size:11px;color:#64748b;white-space:nowrap">
        ⏰ <?= h($created_time) ?>
        <?php if ($tgl_raw !== '' && $created_raw !== '' && substr($created_raw,0,10) !== $tgl_raw): ?>
          <span title="Tanggal DO berbeda dari waktu pembuatan" style="color:#f59e0b">•</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </td>

    <td>
      <div><b><?= h($cust_show) ?></b></div>
      <div class="text-muted-small"><?= h($cust_code) ?></div>
    </td>

    <td>
      <div><b><?= h(strtoupper($off_show)) ?></b></div>
      <div class="text-muted-small"><?= h(strtoupper($off_code)) ?></div>
    </td>

    <td class="text-end"><?='Rp ' . number_format($total, 0, ',', '.')?></td>
    <td class="text-end"><?='Rp ' . number_format($ppn, 0, ',', '.')?></td>
    <td class="text-end"><?='Rp ' . number_format($grand, 0, ',', '.')?></td>

    <td><?= h($dur) ?></td>

    <td>
      <span class="badge-flow badge-flow-<?= h($flow_badge) ?>"><?= h(strtoupper($flow_badge)) ?></span>
    </td>

    <td>
      <span class="badge-status <?= h($status_class) ?>"><?= h(strtoupper($status)) ?></span>
    </td>

    <td class="nowrap">
      <?php if ($can_edit_row): ?>
      <a class="btn btn-sm btn-outline-primary" href="<?= h($_SERVER['PHP_SELF']) ?>?edit=<?= $id ?>">Edit</a>
      <?php endif; ?>
      <a class="btn btn-sm btn-outline-light" href="sales_do_view.php?id=<?= $id ?>" target="_blank">View</a>
      <?php if ($can_delete_row): ?>
      <button type="submit" class="btn btn-sm btn-outline-danger" name="delete_single" value="<?= $id ?>" onclick="return confirm('Hapus DO ini?');">Hapus</button>
      <?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
</tbody>
                    </table>
                </div>

            </form>

        </div>
    </div>

</div>

<!-- JS: jQuery, Bootstrap, DataTables + Buttons -->
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209"></script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>

<script>
function updateClock() {
    const el = document.getElementById('clock-display');
    if (!el) return;
    const now = new Date();
    const options = { weekday:'short', year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit' };
    el.textContent = now.toLocaleString('id-ID', options);
}
setInterval(updateClock, 1000);
updateClock();

(function () {
    const input = document.getElementById('crm_start_ts');
    if (input && !input.value) input.value = Math.floor(Date.now() / 1000);
})();

$(function () {
    const CAN_EXPORT = <?php echo $perm_sales_export ? 'true' : 'false'; ?>;
    const dtButtons = [
        {extend: 'copyHtml5', className: 'btn btn-sm btn-outline-light'}
    ];
    if (CAN_EXPORT) {
        dtButtons.push(
            {extend: 'csvHtml5', className: 'btn btn-sm btn-outline-light'},
            {extend: 'excelHtml5', className: 'btn btn-sm btn-outline-light'},
            {extend: 'pdfHtml5', className: 'btn btn-sm btn-outline-light'},
            {extend: 'print', className: 'btn btn-sm btn-outline-light'}
        );
    }

    if ($.fn.DataTable && document.getElementById('table-do')) {
        $('#table-do').DataTable({
            dom: 'Bfrtip',
            paging: true,
            responsive: true,
            lengthChange: true,
            pageLength: 25,
            order: [[3, 'desc']],
            columnDefs: [
                { type: 'date', targets: 3 },
                { orderable: false, targets: [0, 12] }
            ],
            buttons: dtButtons
        });
    }

    $('#check-all').on('change', function () {
        $('.row-check').prop('checked', this.checked);
    });
});
</script>

<script>
(function () {
    let products = [];
    const editItems = <?= json_encode($edit_items, JSON_UNESCAPED_UNICODE) ?> || [];
    const tbody = document.querySelector('#table-items tbody');
    const btnAdd = document.getElementById('btn-add-row');
    const grandTotalEl = document.getElementById('grand-total');
    const selCustomer = document.getElementById('customers_code');
    const selOffice = document.querySelector('[name="office_code"]');
    let pricelistMap = {};

    if (!tbody || !btnAdd) return;

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getSelectedCustomerOffice() {
        if (!selCustomer || !selCustomer.selectedOptions || !selCustomer.selectedOptions.length) return '';
        return String(selCustomer.selectedOptions[0].getAttribute('data-office-code') || '').trim().toUpperCase();
    }

    function syncOfficeFromCustomer() {
        const officeCode = getSelectedCustomerOffice();
        if (officeCode && selOffice) {
            selOffice.value = officeCode;
        }
        return officeCode;
    }

    function currentOfficeCode() {
        if (selOffice && selOffice.value) return String(selOffice.value).trim().toUpperCase();
        return getSelectedCustomerOffice();
    }

    async function loadProductsByOffice() {
        const office = currentOfficeCode();
        products = [];

        if (!office) {
            refreshAllProductSelects();
            console.warn('office_code kosong: pilih customer/office terlebih dahulu');
            return;
        }

        try {
            const url = 'sales_do.php?ajax=products_by_office&office_code=' + encodeURIComponent(office);
            const res = await fetch(url, { credentials: 'same-origin' });
            const json = await res.json();
            console.log('LOAD PRODUCTS OFFICE:', office, json);

            if (json && json.ok && Array.isArray(json.products)) {
                products = json.products.map(p => ({
                    id: String(p.id || ''),
                    sku: String(p.sku || ''),
                    products_name: String(p.products_name || ''),
                    unit: String(p.unit || ''),
                    price: Number(p.price || 0),
                    barcode: String(p.barcode || ''),
                    stock_qty: Number(p.stock_qty || 0),
                    office_code: String(p.office_code || '')
                }));
            } else {
                console.error('Produk office gagal dimuat:', json);
            }
        } catch (e) {
            console.error('Gagal load produk office:', e);
        }

        refreshAllProductSelects();
    }

    function productOptionsHtml(selectedId) {
        const sid = String(selectedId || '');
        let html = '<option value="">-- Pilih Produk --</option>';
        products.forEach(p => {
            const selected = String(p.id) === sid ? ' selected' : '';
            const label = ((p.sku ? '[' + p.sku.toUpperCase() + '] ' : '') + p.products_name.toUpperCase());
            html += '<option value="' + esc(p.id) + '"'
                + ' data-sku="' + esc(p.sku) + '"'
                + ' data-unit="' + esc(p.unit) + '"'
                + ' data-price="' + esc(p.price) + '"'
                + ' data-barcode="' + esc(p.barcode) + '"'
                + ' data-stock="' + esc(p.stock_qty) + '"'
                + selected + '>' + esc(label) + '</option>';
        });
        return html;
    }

    function refreshAllProductSelects() {
        document.querySelectorAll('.product-select').forEach(select => {
            const selectedId = select.value;
            select.innerHTML = productOptionsHtml(selectedId);

            if (selectedId && Array.from(select.options).some(o => o.value === selectedId)) {
                select.value = selectedId;
            }

            applySelectedProduct(select, false);
        });
    }

    async function refreshPricelistMap() {
        try {
            const office = currentOfficeCode();
            const cust = selCustomer && selCustomer.value ? selCustomer.value : '';
            const url = '../master/master_pricelist.php?ajax=price_map'
                + '&office_code=' + encodeURIComponent(office)
                + '&customers_code=' + encodeURIComponent(cust);
            const res = await fetch(url, { credentials: 'same-origin' });
            const j = await res.json();
            pricelistMap = (j && j.ok && j.map) ? j.map : {};
        } catch (e) {
            pricelistMap = {};
        }
    }

    function currentTaxRate() {
        const sel = document.getElementById('tax-code-select');
        if (!sel || !sel.selectedOptions.length) return 0;
        const rate = parseFloat(sel.selectedOptions[0].getAttribute('data-rate') || '0');
        return isNaN(rate) ? 0 : rate;
    }

    function isIncludeTax() {
        const cb = document.getElementById('include_tax');
        return !!(cb && cb.checked);
    }

    function applySelectedProduct(select, updatePrice) {
        const tr = select.closest('tr');
        if (!tr) return;
        const opt = select.selectedOptions && select.selectedOptions[0];
        const sku = tr.querySelector('.sku-text');
        const unit = tr.querySelector('.unit-text');
        const barcode = tr.querySelector('.barcode-text');
        const stockInp = tr.querySelector('.stock-input');
        const price = tr.querySelector('.price-input');
        const qty = tr.querySelector('.qty-input');

        if (!opt || !opt.value) {
            if (sku) sku.value = '';
            if (unit) unit.value = '';
            if (barcode) barcode.value = '';
            if (stockInp) stockInp.value = '0';
            if (updatePrice && price) price.value = '0';
            if (qty) qty.dispatchEvent(new Event('input'));
            return;
        }

        const skuValue = opt.getAttribute('data-sku') || '';
        if (sku) sku.value = skuValue;
        if (unit) unit.value = opt.getAttribute('data-unit') || '';
        if (barcode) barcode.value = opt.getAttribute('data-barcode') || '';
        if (stockInp) stockInp.value = opt.getAttribute('data-stock') || '0';

        if (updatePrice && price) {
            const skuKey = skuValue.toUpperCase().trim();
            const pl = skuKey ? pricelistMap[skuKey] : undefined;
            const basePrice = parseFloat(opt.getAttribute('data-price') || '0') || 0;
            price.value = (pl !== undefined && pl !== null && pl !== '') ? String(pl) : String(basePrice);
        }

        if (qty) qty.dispatchEvent(new Event('input'));
    }

    function createRow(index, data) {
        data = data || {};
        const tr = document.createElement('tr');
        const productId = data.product_id || '';
        const qtyVal = data.qty || 1;
        const priceVal = data.unit_price || 0;
        const discVal = data.disc_percent || 0;
        const skuVal = data.sku || '';
        const unitVal = data.unit || '';
        const barcodeVal = data.barcode || '';
        const stockVal = (data.stock_at_crm !== null && data.stock_at_crm !== undefined) ? data.stock_at_crm : 0;
        const showPkg = data.show_package_items ? 1 : 0;
        const expVal = data.exp_date || '';
        const serialVal = data.serial_lot || '';

        tr.innerHTML = `
            <td class="text-center align-middle line-no">${index}</td>
            <td>
                <select class="form-select form-select-sm product-select" name="items[${index}][product_id]">
                    ${productOptionsHtml(productId)}
                </select>
            </td>
            <td><input type="text" class="form-control form-control-sm sku-text" value="${esc(skuVal)}" readonly></td>
            <td><input type="text" class="form-control form-control-sm barcode-text" value="${esc(barcodeVal)}" readonly></td>
            <td><input type="number" class="form-control form-control-sm qty-input" min="1" value="${esc(qtyVal)}" name="items[${index}][qty]"></td>
            <td><input type="text" class="form-control form-control-sm unit-text" value="${esc(unitVal)}" readonly></td>
            <td><input type="date" class="form-control form-control-sm exp-input" name="items[${index}][exp_date]" value="${esc(expVal)}"></td>
            <td><input type="text" class="form-control form-control-sm serial-input" name="items[${index}][serial_lot]" value="${esc(serialVal)}" placeholder="Serial / Lot"></td>
            <td><input type="text" class="form-control form-control-sm text-end price-input" name="items[${index}][unit_price]" value="${esc(priceVal)}"></td>
            <td><input type="number" class="form-control form-control-sm text-end disc-input" name="items[${index}][disc_percent]" value="${esc(discVal)}" min="0" max="100"></td>
            <td><input type="number" step="0.01" class="form-control form-control-sm text-end stock-input" name="items[${index}][stock_at_crm]" value="${esc(stockVal)}" readonly></td>
            <td class="text-end"><span class="subtotal-text">Rp 0</span><input type="hidden" class="subtotal-input" name="items[${index}][subtotal]" value="0"></td>
            <td class="text-center"><div class="form-check"><input class="form-check-input pkg-checkbox" type="checkbox" name="items[${index}][show_package_items]" value="1" ${showPkg ? 'checked' : ''}></div></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-del-row">&times;</button></td>
        `;
        bindRow(tr);
        return tr;
    }

    function bindRow(tr) {
        const select = tr.querySelector('.product-select');
        const price = tr.querySelector('.price-input');
        const qty = tr.querySelector('.qty-input');
        const disc = tr.querySelector('.disc-input');
        const subTxt = tr.querySelector('.subtotal-text');
        const subInp = tr.querySelector('.subtotal-input');
        const btnDel = tr.querySelector('.btn-del-row');

        select.addEventListener('change', function () {
            applySelectedProduct(select, true);
        });

        function updateLine() {
            const q = parseFloat(qty.value || '0') || 0;
            const pr = parseFloat(String(price.value || '0').replace(/[^0-9,\.]/g, '').replace(',', '.')) || 0;
            let dc = parseFloat(disc.value || '0') || 0;
            if (dc < 0) dc = 0;
            if (dc > 100) dc = 100;

            const rate = currentTaxRate();
            const netPrice = (isIncludeTax() && rate > 0) ? pr / (1 + (rate / 100)) : pr;
            let sub = q * netPrice * (100 - dc) / 100;
            if (!isFinite(sub)) sub = 0;

            subInp.value = sub.toFixed(2);
            subTxt.innerText = 'Rp ' + sub.toLocaleString('id-ID');
            updateGrandTotal();
        }

        qty.addEventListener('input', updateLine);
        price.addEventListener('input', updateLine);
        disc.addEventListener('input', updateLine);
        btnDel.addEventListener('click', function () {
            tr.remove();
            renumberRows();
            updateGrandTotal();
        });
        updateLine();
    }

    function addEmptyRow() {
        const index = tbody.querySelectorAll('tr').length + 1;
        const tr = createRow(index, {});
        tbody.appendChild(tr);
        return tr;
    }

    function renumberRows() {
        let i = 1;
        tbody.querySelectorAll('tr').forEach(tr => {
            const no = tr.querySelector('.line-no');
            if (no) no.innerText = i;
            tr.querySelectorAll('select, input').forEach(el => {
                if (el.name) el.name = el.name.replace(/items\[\d+\]/, 'items[' + i + ']');
            });
            i++;
        });
    }

    function updateGrandTotal() {
        let subtotal = 0;
        tbody.querySelectorAll('.subtotal-input').forEach(inp => {
            subtotal += parseFloat(inp.value || '0') || 0;
        });
        const rate = currentTaxRate();
        const taxAmount = subtotal * rate / 100;
        const grand = subtotal + taxAmount;
        const subEl = document.getElementById('label-subtotal');
        const taxEl = document.getElementById('label-tax');
        if (subEl) subEl.innerText = 'Rp ' + subtotal.toLocaleString('id-ID');
        if (taxEl) taxEl.innerText = 'Rp ' + taxAmount.toLocaleString('id-ID');
        if (grandTotalEl) grandTotalEl.innerText = 'Rp ' + grand.toLocaleString('id-ID');
    }

    btnAdd.addEventListener('click', async function () {
        addEmptyRow();
        await refreshPricelistMap();
        await loadProductsByOffice();
    });

    if (selCustomer) {
        selCustomer.addEventListener('change', async function () {
            syncOfficeFromCustomer();
            await refreshPricelistMap();
            await loadProductsByOffice();
        });
    }

    if (selOffice) {
        selOffice.addEventListener('change', async function () {
            await refreshPricelistMap();
            await loadProductsByOffice();
        });
    }

    const taxSelect = document.getElementById('tax-code-select');
    if (taxSelect) taxSelect.addEventListener('change', function () {
        tbody.querySelectorAll('.qty-input').forEach(q => q.dispatchEvent(new Event('input')));
        updateGrandTotal();
    });

    const includeTaxCb = document.getElementById('include_tax');
    if (includeTaxCb) includeTaxCb.addEventListener('change', function () {
        tbody.querySelectorAll('.qty-input').forEach(q => q.dispatchEvent(new Event('input')));
        updateGrandTotal();
    });

    syncOfficeFromCustomer();

    if (editItems.length > 0) {
        editItems.forEach((row, idx) => tbody.appendChild(createRow(idx + 1, row)));
    } else {
        addEmptyRow();
    }

    refreshPricelistMap().then(loadProductsByOffice).then(updateGrandTotal);
})();
</script>

<script>
(function(){
  const LOCKED = <?php echo !empty($is_locked) ? 'true' : 'false'; ?>;
  if (LOCKED) return;

  const customers = <?php echo json_encode($customers, JSON_UNESCAPED_UNICODE); ?>;

  const selCustomer = document.getElementById('customers_code');
  const taShip      = document.getElementById('shipping_address');
  const inPhone     = document.getElementById('customer_phone');
  const inPic       = document.getElementById('customer_pic');
  const selExtPic   = document.getElementById('ext_pic_select');
  const mprContacts = <?php echo json_encode($mprContacts, JSON_UNESCAPED_UNICODE); ?>;

  if (!selCustomer || !taShip || !inPhone || !inPic || !selExtPic) return;
    function normalize(s){ return String(s || '').toLowerCase(); }

  function getContactsByCustomer(code){
    const needle = String(code || '').toUpperCase();
    return (mprContacts || []).filter(m => String(m.customers_code || '').toUpperCase() === needle);
  }

  function filterPurchasing(list){
    const keys = ['purch', 'purchasing', 'procurement', 'pengadaan'];
    return list.filter(x => keys.some(k => normalize(x.role_title).includes(k) || normalize(x.department).includes(k)));
  }

  function buildExtPicOptions(){
    const code = selCustomer.value || '';
    selExtPic.innerHTML = '<option value="">-- pilih PIC Purchasing (opsional) --</option>';
    if (!code) return;

    const all = getContactsByCustomer(code);
    const purch = filterPurchasing(all);
    const list = purch.length ? purch : all;

    list.forEach(m => {
      const opt = document.createElement('option');
      opt.value = String(m.id || '');
      opt.textContent = String(m.contact_name || '-') + (m.role_title ? ' - ' + m.role_title : '');
      opt.dataset.name = String(m.contact_name || '');
      opt.dataset.phone = String(m.phone || '');
      opt.dataset.primary = String(m.is_primary || 0);
      selExtPic.appendChild(opt);
    });

    // auto pilih primary kalau field PIC + phone masih kosong
    const prim = Array.from(selExtPic.options).find(o => o.dataset.primary === '1');
    if (prim && !inPic.value.trim() && !inPhone.value.trim()) {
      selExtPic.value = prim.value;
      inPic.value = prim.dataset.name || inPic.value;
      inPhone.value = prim.dataset.phone || inPhone.value;
    }
  }
  function findCustomer(code){
    const needle = String(code || '').toUpperCase();
    return customers.find(c => String(c.customers_code || '').toUpperCase() === needle);
  }

  function autofill(){
    const code = selCustomer.value || '';

    // selalu rebuild dropdown berdasarkan customer terpilih
    buildExtPicOptions();

    // jika customer belum dipilih, berhenti setelah clear/build dropdown
    if (!code) return;

    const c = findCustomer(code);
    if (!c) return;

    // isi hanya jika masih kosong (AMAN, tidak menimpa input manual)
    if (!taShip.value.trim() && (c.address || c.city)) {
      let addr = String(c.address || '');
      if (c.city) addr += (addr ? "\n" : "") + String(c.city);
      taShip.value = addr;
    }

    if (!inPhone.value.trim() && c.phone) {
      inPhone.value = String(c.phone || '');
    }

    // PIC: fallback aman dari master customer email (jika PIC masih kosong)
    if (!inPic.value.trim() && c.email) {
      inPic.value = String(c.email || '');
    }
  }

  selCustomer.addEventListener('change', autofill);
  selExtPic.addEventListener('change', function(){
    const opt = selExtPic.options[selExtPic.selectedIndex];
    if (!opt || !opt.value) return;
    // user memilih dropdown -> overwrite boleh
    if (opt.dataset.name) inPic.value = opt.dataset.name;
    if (opt.dataset.phone) inPhone.value = opt.dataset.phone;
    });
  // jika halaman dibuka dalam mode edit dan customer sudah terpilih
  setTimeout(autofill, 50);
})();
</script>

<script>
(function(){
  var locked = <?php echo !empty($is_locked) ? 'true' : 'false'; ?>;
  if(!locked) return;

  var form = document.getElementById('do-form');
  if(!form) return;

  form.querySelectorAll('input, select, textarea, button').forEach(function(el){
    if(el.tagName === 'INPUT' && el.type === 'hidden') return;
    el.disabled = true;
    el.style.opacity = 0.75;
    el.style.cursor = 'not-allowed';
  });

  form.addEventListener('submit', function(e){
    e.preventDefault();
    alert('DO sudah terkunci (read-only). CRM tidak bisa mengubah karena sudah diproses departemen berikutnya.');
    return false;
  });
})();
</script>


<?php rmi_footer(); ?>