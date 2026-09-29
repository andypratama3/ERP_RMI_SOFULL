<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// sales_do.php
// CRM → SALES DO / ORDER
// PATCH ORDER BY 0 FIX - 2026-06-29
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
require_once __DIR__ . '/../_shared/rmi_branch_guard.php';
$__sd_depo_restricted = function_exists('rmi_is_depo_branch_session') && rmi_is_depo_branch_session();
if (function_exists('require_any_permission') && !$__sd_depo_restricted) {
    require_any_permission(['SALES.VIEW', 'SALES.CREATE', 'SALES.EDIT', 'SALES.DELETE', 'SALES.EXPORT']);
} elseif (!function_exists('require_any_permission')) {
    require_role(['SYS','SUPERADMIN','ADMIN','MANAGER','STAFF','CRM','WQS','SCM','ACT','FIN','BRANCH']);
}
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/../_shared/helpers.php';       // rmi_sku(), rmi_product_name(), rmi_h()
require_once __DIR__ . '/../_shared/stock_helper.php'; // stock_left_join_subquery() — join aman tanpa duplikat
require_once __DIR__ . '/../stock/_stock_office_helper.php';
require_once __DIR__ . '/_ar_helper.php';

function sd_reduce_stock_by_office_for_do(PDO $pdo, int $productId, float $qty, string $officeCode, string $sku = ''): void
{
    // Legacy helper: tetap ada untuk kompatibilitas, tetapi DO baru memakai sd_reduce_stock_by_do_id().
    $officeCode = strtoupper(trim($officeCode));

    if ($productId <= 0 || $qty <= 0 || $officeCode === '') {
        throw new Exception('Data pengurangan stok tidak valid.');
    }

    $aliases = sd_office_aliases($pdo, $officeCode);
    $params = [$productId];
    $whereOffice = sd_office_alias_where('office_code', $aliases, $params);

    $st = $pdo->prepare("
        SELECT stock_qty
        FROM wqs_stock_by_office
        WHERE product_id = ?
          AND {$whereOffice}
        LIMIT 1
        FOR UPDATE
    ");
    $st->execute($params);
    $currentStock = $st->fetchColumn();

    if ($currentStock === false) {
        throw new Exception("Stok SKU {$sku} tidak ditemukan di office {$officeCode}.");
    }

    $currentStock = (float)$currentStock;

    if ($currentStock < $qty) {
        throw new Exception("Stok SKU {$sku} tidak cukup di office {$officeCode}. Tersedia: {$currentStock}, diminta: {$qty}.");
    }

    $params = sd_office_alias_execute_params([$qty, $productId], $aliases, [$qty]);
    $dummyParams = [];
    $whereOffice = sd_office_alias_where('office_code', $aliases, $dummyParams);

    $up = $pdo->prepare("
        UPDATE wqs_stock_by_office
        SET stock_qty = stock_qty - ?,
            updated_at = NOW()
        WHERE product_id = ?
          AND {$whereOffice}
          AND stock_qty >= ?
    ");
    $up->execute($params);

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

function sd_norm_sku_value(string $sku): string
{
    // Normalisasi SKU untuk kasus spasi berbeda: "20/ 4*10CM" = "20/4*10CM".
    $sku = strtoupper(trim($sku));
    return preg_replace('/\s+/u', '', $sku) ?: '';
}

function sd_norm_sku_sql(string $expr): string
{
    // MySQL expression: trim + uppercase + hapus spasi/tab/nbsp agar matching SKU tidak gagal karena beda format.
    return "UPPER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE({$expr}, '')), ' ', ''), CHAR(9), ''), CHAR(160), ''))";
}

/**
 * Office alias resolver untuk stok DO.
 * Tujuan: semua cabang/depo memakai satu logika, tidak hardcode satu depo saja.
 * Contoh:
 * - DO office_code = SAMARINDA, stok = SMD/SMR/DEPO SAMARINDA tetap terbaca jika mapping ada di master_office.
 * - DO office_code = MLG, stok = DEPO MALANG/MALANG tetap terbaca jika mapping ada di master_office.
 */
function sd_norm_office_value(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/\s+/u', ' ', $value) ?: '';
    return $value;
}

/**
 * UNIT_ACC tetap merupakan kategori master produk.
 * Ownership stok mengikuti office_code normal (BGR/BDG/dll), bukan office khusus.
 * Pada CRM, UNIT_ACC dipisahkan melalui kategori dokumen AKSESORIS.
 */
function sd_is_unit_acc_category(?string $category): bool
{
    return strtoupper(trim((string)$category)) === 'UNIT_ACC';
}

function sd_office_match_key(string $value): string
{
    $value = sd_norm_office_value($value);
    $value = str_replace(['RIZQULLAH MEDISKA INDONESIA', 'PT ', 'KAB.', 'KOTA ', 'DEPO ', 'CABANG '], '', $value);
    return preg_replace('/[^A-Z0-9]/', '', $value) ?: '';
}

function sd_office_aliases(PDO $pdo, string $officeCode): array
{
    $raw = sd_norm_office_value($officeCode);
    if ($raw === '') return [];

    $aliases = [$raw];

    // Alias umum tetap kecil dan aman. Alias utama tetap diambil dinamis dari master_office.
    $manual = [
        'SAMARINDA' => ['SAMARINDA', 'DEPO SAMARINDA', 'SMD', 'SMR'],
        'SMD'       => ['SMD', 'SMR', 'SAMARINDA', 'DEPO SAMARINDA'],
        'SMR'       => ['SMR', 'SMD', 'SAMARINDA', 'DEPO SAMARINDA'],
        'MALANG'    => ['MALANG', 'DEPO MALANG', 'MLG'],
        'MLG'       => ['MLG', 'MALANG', 'DEPO MALANG'],
    ];

    $rawKey = sd_office_match_key($raw);
    foreach ($manual as $k => $vals) {
        if ($rawKey === sd_office_match_key($k) || in_array($raw, $vals, true)) {
            foreach ($vals as $v) $aliases[] = sd_norm_office_value($v);
        }
    }

    try {
        if (sd_has_table($pdo, 'master_office')) {
            $officeCols = sd_table_columns($pdo, 'master_office');
            $select = ["office_code"];
            foreach (['office_name','city','address'] as $c) {
                if (in_array($c, $officeCols, true)) $select[] = $c;
            }

            $rows = $pdo->query("SELECT " . implode(',', array_map(fn($c) => "`{$c}`", $select)) . " FROM master_office")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $values = [];
                foreach ($select as $c) {
                    $v = sd_norm_office_value((string)($row[$c] ?? ''));
                    if ($v !== '') $values[] = $v;
                }

                $matched = false;
                foreach ($values as $v) {
                    $key = sd_office_match_key($v);
                    if ($key === '' || $rawKey === '') continue;
                    if ($key === $rawKey || str_contains($key, $rawKey) || str_contains($rawKey, $key)) {
                        $matched = true;
                        break;
                    }
                }

                if ($matched) {
                    foreach ($values as $v) {
                        $aliases[] = $v;
                        if (str_starts_with($v, 'DEPO ')) $aliases[] = trim(substr($v, 5));
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Fail-soft: minimal alias raw tetap dipakai.
    }

    $out = [];
    foreach ($aliases as $a) {
        $a = sd_norm_office_value((string)$a);
        if ($a !== '' && !in_array($a, $out, true)) $out[] = $a;
    }
    return $out;
}

function sd_office_alias_where(string $sqlExpr, array $aliases, array &$params): string
{
    $aliases = array_values(array_filter(array_unique(array_map('sd_norm_office_value', $aliases))));
    if (empty($aliases)) {
        $params[] = '';
        return "UPPER(TRIM({$sqlExpr})) = ?";
    }

    $params = array_merge($params, $aliases);
    return "UPPER(TRIM({$sqlExpr})) IN (" . implode(',', array_fill(0, count($aliases), '?')) . ")";
}

function sd_office_alias_execute_params(array $before, array $aliases, array $after = []): array
{
    $aliases = array_values(array_filter(array_unique(array_map('sd_norm_office_value', $aliases))));
    return array_merge($before, $aliases ?: [''], $after);
}

function sd_restore_stock_product_office(PDO $pdo, int $productId, float $qty, string $officeCode): void
{
    if ($productId <= 0 || $qty <= 0) return;

    $aliases = sd_office_aliases($pdo, $officeCode);
    $stockCols = sd_table_columns($pdo, 'wqs_stock_by_office');
    $hasId = in_array('id', $stockCols, true);
    $hasUpdatedAt = in_array('updated_at', $stockCols, true);
    $setUpdatedAt = $hasUpdatedAt ? ', updated_at = NOW()' : '';

    if ($hasId) {
        $params = [$productId];
        $whereOffice = sd_office_alias_where('office_code', $aliases, $params);
        $st = $pdo->prepare("
            SELECT id
            FROM wqs_stock_by_office
            WHERE product_id = ?
              AND {$whereOffice}
            ORDER BY CASE WHEN UPPER(TRIM(office_code)) = ? THEN 0 ELSE 1 END, id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute(array_merge($params, [sd_norm_office_value($officeCode)]));
        $rowId = (int)($st->fetchColumn() ?: 0);

        if ($rowId > 0) {
            $up = $pdo->prepare("
                UPDATE wqs_stock_by_office
                SET stock_qty = COALESCE(stock_qty, 0) + ?{$setUpdatedAt}
                WHERE id = ?
            ");
            $up->execute([$qty, $rowId]);
            sd_sync_global_stock_after_do($pdo, $productId);
        }
        return;
    }

    $params = [$qty, $productId];
    $whereOffice = sd_office_alias_where('office_code', $aliases, $params);
    $up = $pdo->prepare("
        UPDATE wqs_stock_by_office
        SET stock_qty = COALESCE(stock_qty, 0) + ?{$setUpdatedAt}
        WHERE product_id = ?
          AND {$whereOffice}
        LIMIT 1
    ");
    $up->execute($params);
    sd_sync_global_stock_after_do($pdo, $productId);
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



function sd_ensure_stock_allocations(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_stock_allocations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        do_id INT NOT NULL,
        stock_row_id BIGINT UNSIGNED NOT NULL,
        product_id INT NOT NULL,
        office_code VARCHAR(50) NOT NULL,
        sku VARCHAR(120) NULL,
        qty DECIMAL(18,4) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_do_id (do_id),
        KEY idx_stock_row (stock_row_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function sd_record_stock_allocation(PDO $pdo, int $doId, int $rowId, int $productId, string $officeCode, string $sku, float $qty): void
{
    if ($doId <= 0 || $rowId <= 0 || $productId <= 0 || $qty <= 0) return;
    $st = $pdo->prepare("INSERT INTO sales_do_stock_allocations
        (do_id, stock_row_id, product_id, office_code, sku, qty)
        VALUES (?,?,?,?,?,?)");
    $st->execute([$doId, $rowId, $productId, rmi_office_canonical($pdo, $officeCode), $sku, $qty]);
}

/**
 * Kembalikan stok WQS saat DO CRM yang salah dihapus.
 * Restore hanya dilakukan jika kolom sales_do.stock_reduced_at ada dan bernilai, agar stok tidak dobel naik.
 */
function sd_restore_stock_by_do_id_for_delete(PDO $pdo, int $doId): void
{
    if ($doId <= 0) return;

    $doCols = sd_table_columns($pdo, 'sales_do');
    if (!in_array('stock_reduced_at', $doCols, true)) return;

    $chk = $pdo->prepare("SELECT stock_reduced_at FROM sales_do WHERE id = ? LIMIT 1 FOR UPDATE");
    $chk->execute([$doId]);
    $reducedAt = $chk->fetchColumn();
    if ($reducedAt === false || $reducedAt === null || trim((string)$reducedAt) === '') return;

    $al = $pdo->prepare("SELECT id, stock_row_id, product_id, office_code, sku, qty
                         FROM sales_do_stock_allocations
                         WHERE do_id=? ORDER BY id ASC FOR UPDATE");
    $al->execute([$doId]);
    $allocations = $al->fetchAll(PDO::FETCH_ASSOC);

    if ($allocations) {
        $stockCols = sd_table_columns($pdo, 'wqs_stock_by_office');
        if (!in_array('id', $stockCols, true)) {
            throw new Exception('Restore revisi membutuhkan kolom id pada wqs_stock_by_office.');
        }

        $hasUpdatedAt = in_array('updated_at', $stockCols, true);
        $setUpdatedAt = $hasUpdatedAt ? ', updated_at=NOW()' : '';
        $changed = [];

        foreach ($allocations as $a) {
            $originalRowId = (int)($a['stock_row_id'] ?? 0);
            $productId = (int)($a['product_id'] ?? 0);
            $officeCode = trim((string)($a['office_code'] ?? ''));
            $sku = trim((string)($a['sku'] ?? ''));
            $skuNorm = sd_norm_sku_value($sku);
            $qty = (float)($a['qty'] ?? 0);

            if ($qty <= 0) continue;

            // Prioritas pertama: kembalikan tepat ke row FIFO/lot asal.
            $targetRowId = 0;
            $targetProductId = 0;
            if ($originalRowId > 0) {
                $row = $pdo->prepare("SELECT id, product_id FROM wqs_stock_by_office WHERE id=? LIMIT 1 FOR UPDATE");
                $row->execute([$originalRowId]);
                $found = $row->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $targetRowId = (int)$found['id'];
                    $targetProductId = (int)$found['product_id'];
                }
            }

            // Fallback 1: row lama sudah hilang, cari product_id yang sama pada office yang sama.
            $aliases = sd_office_aliases($pdo, $officeCode);
            if ($targetRowId <= 0 && $productId > 0) {
                $params = [$productId];
                $officeWhere = sd_office_alias_where('s.office_code', $aliases, $params);
                $st = $pdo->prepare("SELECT s.id, s.product_id
                                     FROM wqs_stock_by_office s
                                     WHERE s.product_id=? AND {$officeWhere}
                                     ORDER BY CASE WHEN UPPER(TRIM(s.office_code))=? THEN 0 ELSE 1 END, s.id ASC
                                     LIMIT 1 FOR UPDATE");
                $st->execute(array_merge($params, [sd_norm_office_value($officeCode)]));
                $found = $st->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $targetRowId = (int)$found['id'];
                    $targetProductId = (int)$found['product_id'];
                }
            }

            // Fallback 2: data import dapat membuat product_id baru untuk SKU yang sama.
            if ($targetRowId <= 0 && $skuNorm !== '') {
                $params = [];
                $officeWhere = sd_office_alias_where('s.office_code', $aliases, $params);
                $sql = "SELECT s.id, s.product_id
                        FROM wqs_stock_by_office s
                        LEFT JOIN master_products p ON p.id=s.product_id
                        WHERE {$officeWhere}
                          AND " . sd_norm_sku_sql('p.sku') . "=?
                        ORDER BY CASE WHEN s.product_id=? THEN 0 ELSE 1 END,
                                 CASE WHEN UPPER(TRIM(s.office_code))=? THEN 0 ELSE 1 END,
                                 s.id ASC
                        LIMIT 1 FOR UPDATE";
                $params[] = $skuNorm;
                $params[] = $productId;
                $params[] = sd_norm_office_value($officeCode);
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $found = $st->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $targetRowId = (int)$found['id'];
                    $targetProductId = (int)$found['product_id'];
                }
            }

            if ($targetRowId <= 0) {
                throw new Exception("Gagal mengembalikan stok DO {$doId}: row asal {$originalRowId} sudah tidak ada dan row pengganti SKU {$sku} pada office {$officeCode} tidak ditemukan.");
            }

            $up = $pdo->prepare("UPDATE wqs_stock_by_office
                                 SET stock_qty=COALESCE(stock_qty,0)+?{$setUpdatedAt}
                                 WHERE id=?");
            $up->execute([$qty, $targetRowId]);
            if ($up->rowCount() < 1) {
                throw new Exception("Gagal mengembalikan stok DO {$doId} ke row stok pengganti {$targetRowId}.");
            }

            if ($targetProductId > 0) $changed[$targetProductId] = true;
            if ($productId > 0) $changed[$productId] = true;
        }

        $pdo->prepare("DELETE FROM sales_do_stock_allocations WHERE do_id=?")->execute([$doId]);
        foreach (array_keys($changed) as $pid) {
            sd_sync_global_stock_after_do($pdo, (int)$pid);
        }
        return;
    }

    // Fallback untuk DO lama yang dibuat sebelum tabel alokasi tersedia.
    $st = $pdo->prepare("SELECT UPPER(TRIM(d.office_code)) AS office_code, i.product_id, SUM(i.qty) AS total_qty
        FROM sales_do d JOIN sales_do_items i ON i.do_id=d.id WHERE d.id=?
        GROUP BY UPPER(TRIM(d.office_code)), i.product_id");
    $st->execute([$doId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
        sd_restore_stock_product_office($pdo, (int)$it['product_id'], (float)$it['total_qty'], (string)$it['office_code']);
    }
}


/**
 * Reset siklus WQS/SCM ketika revisi pra-kirim selesai diedit CRM.
 * Bukti kartu stok lama diarsipkan agar histori tidak hilang, kemudian data aktif
 * dibersihkan supaya WQS wajib melakukan pemeriksaan dan upload bukti baru.
 */
function sd_reset_revision_workflow(PDO $pdo, int $doId, string $actor = 'CRM'): void
{
    if ($doId <= 0) return;

    // Arsipkan bukti kartu stok lama sebelum menghapus data aktif.
    try {
        if (sd_has_table($pdo, 'sales_do_stock_card_photos')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_stock_card_photos_archive (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                original_photo_id BIGINT UNSIGNED NULL,
                do_id INT NOT NULL,
                photo_type VARCHAR(20) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NULL,
                uploaded_by VARCHAR(100) NULL,
                uploaded_at DATETIME NULL,
                archived_by VARCHAR(100) NULL,
                archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_archive_do_id (do_id),
                KEY idx_archive_photo_type (photo_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $arch = $pdo->prepare("INSERT INTO sales_do_stock_card_photos_archive
                (original_photo_id, do_id, photo_type, file_path, original_name, uploaded_by, uploaded_at, archived_by)
                SELECT id, do_id, CAST(photo_type AS CHAR), file_path, original_name, uploaded_by, uploaded_at, ?
                FROM sales_do_stock_card_photos
                WHERE do_id = ?");
            $arch->execute([$actor, $doId]);

            $pdo->prepare("DELETE FROM sales_do_stock_card_photos WHERE do_id = ?")->execute([$doId]);
        }
    } catch (Throwable $e) {
        throw new Exception('Gagal mengarsipkan bukti WQS lama saat revisi: ' . $e->getMessage(), 0, $e);
    }

    // Reset hanya kolom yang memang tersedia di database live.
    $cols = sd_table_columns($pdo, 'sales_do');
    $sets = [];
    $params = [];

    $nullable = [
        'wqs_note', 'wqs_stock_before', 'wqs_stock_after', 'wqs_started_at', 'wqs_ready_at',
        'scm_note', 'scm_receive_photo', 'scm_receive_video', 'scm_delivery_photo',
        'scm_delivery_video', 'scm_signature_data', 'scm_on_delivery_at', 'scm_delivered_at'
    ];
    foreach ($nullable as $col) {
        if (in_array($col, $cols, true)) $sets[] = "`{$col}` = NULL";
    }
    if (in_array('wqs_status', $cols, true)) $sets[] = "wqs_status = 'Pending'";
    if (in_array('scm_status', $cols, true)) $sets[] = "scm_status = 'Pending'";
    if (in_array('last_updated_by', $cols, true)) {
        $sets[] = 'last_updated_by = ?';
        $params[] = $actor;
    }
    if (in_array('last_updated_at', $cols, true)) $sets[] = 'last_updated_at = NOW()';

    if ($sets) {
        $params[] = $doId;
        $pdo->prepare('UPDATE sales_do SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    // Tandai permintaan revisi sebagai sudah ditangani CRM jika tabel log tersedia.
    try {
        if (sd_has_table($pdo, 'sales_do_revisions')) {
            $pdo->prepare("UPDATE sales_do_revisions
                           SET status = 'COMPLETED'
                           WHERE do_id = ? AND status = 'REQUESTED'")->execute([$doId]);
        }
    } catch (Throwable $e) {
        // Log revisi bersifat pelengkap dan tidak boleh membatalkan transaksi utama.
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
            $aliases = sd_office_aliases($pdo, $officeCode);
            $params = [$productId];
            $whereOffice = sd_office_alias_where('office_code', $aliases, $params);

            $lock = $pdo->prepare("
                SELECT id, product_id, stock_qty
                FROM wqs_stock_by_office
                WHERE product_id = ?
                  AND {$whereOffice}
                ORDER BY stock_qty DESC, id ASC
                FOR UPDATE
            ");
            $lock->execute($params);
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
            $changedProductIds = [];
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
                $changedProductIds[(int)($r['product_id'] ?? $productId)] = true;
            }

            foreach (array_keys($changedProductIds) as $changedPid) {
                if ((int)$changedPid > 0) sd_sync_global_stock_after_do($pdo, (int)$changedPid);
            }

            if ($remaining > 0.00001) {
                throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}. Sisa qty: {$remaining}.");
            }
        } else {
            $aliases = sd_office_aliases($pdo, $officeCode);
            $params = [$productId];
            $whereOffice = sd_office_alias_where('office_code', $aliases, $params);

            $lock = $pdo->prepare("
                SELECT stock_qty
                FROM wqs_stock_by_office
                WHERE product_id = ?
                  AND {$whereOffice}
                LIMIT 1
                FOR UPDATE
            ");
            $lock->execute($params);
            $currentStock = $lock->fetchColumn();

            if ($currentStock === false) {
                throw new Exception("Stok SKU {$sku} tidak ditemukan di office {$officeCode}.");
            }

            $currentStock = (float)$currentStock;

            if ($currentStock < $qty) {
                throw new Exception("Stok SKU {$sku} tidak cukup di office {$officeCode}. Tersedia: {$currentStock}, diminta: {$qty}.");
            }

            $params = sd_office_alias_execute_params([$qty, $productId], $aliases, [$qty]);
            $dummyParams = [];
            $whereOffice = sd_office_alias_where('office_code', $aliases, $dummyParams);

            $up = $pdo->prepare("
                UPDATE wqs_stock_by_office
                SET stock_qty = stock_qty - ?,
                    updated_at = NOW()
                WHERE product_id = ?
                  AND {$whereOffice}
                  AND stock_qty >= ?
            ");
            $up->execute($params);

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
            $aliases = sd_office_aliases($pdo, $officeCode);
            $params = [$productId];
            $whereOffice = sd_office_alias_where('office_code', $aliases, $params);

            $lock = $pdo->prepare("
                SELECT id, stock_qty
                FROM wqs_stock_by_office
                WHERE product_id = ?
                  AND {$whereOffice}
                ORDER BY stock_qty DESC, id ASC
                FOR UPDATE
            ");
            $lock->execute($params);
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
            $aliases = sd_office_aliases($pdo, $officeCode);
            $params = [$productId];
            $whereOffice = sd_office_alias_where('office_code', $aliases, $params);

            $lock = $pdo->prepare("
                SELECT COALESCE(SUM(stock_qty), 0) AS stock_qty
                FROM wqs_stock_by_office
                WHERE product_id = ?
                  AND {$whereOffice}
                FOR UPDATE
            ");
            $lock->execute($params);
            $available = (float)($lock->fetchColumn() ?: 0);

            if ($available <= 0) {
                throw new Exception("Stok SKU {$sku} tidak ditemukan di office {$officeCode}.");
            }
            if ($available < $qty) {
                throw new Exception("Stok SKU {$sku} tidak cukup di office {$officeCode}. Tersedia: {$available}, diminta: {$qty}.");
            }

            $params = sd_office_alias_execute_params([$qty, $productId], $aliases, [$qty]);
            $dummyParams = [];
            $whereOffice = sd_office_alias_where('office_code', $aliases, $dummyParams);

            $up = $pdo->prepare("
                UPDATE wqs_stock_by_office
                SET stock_qty = stock_qty - ?{$setUpdatedAt}
                WHERE product_id = ?
                  AND {$whereOffice}
                  AND stock_qty >= ?
                LIMIT 1
            ");
            $up->execute($params);

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



/**
 * Ambil nama kolom lot/serial yang tersedia pada wqs_stock_by_office.
 */
function sd_wqs_lot_columns(PDO $pdo): array
{
    $cols = sd_table_columns($pdo, 'wqs_stock_by_office');

    // Jangan hardcode lot_number saja. Di database live beberapa tabel memakai
    // serial_lot / lot_no / serial_number / batch_no.
    $candidates = [
        'lot_number',
        'lot_no',
        'serial_lot',
        'serial_number',
        'serial_no',
        'batch_number',
        'batch_no'
    ];

    return array_values(array_filter($candidates, fn($c) => in_array($c, $cols, true)));
}

/**
 * Ambil kolom expired date yang tersedia pada wqs_stock_by_office untuk FIFO.
 */
function sd_wqs_exp_column(PDO $pdo): string
{
    $cols = sd_table_columns($pdo, 'wqs_stock_by_office');

    foreach ([
        'exp_date',
        'expired_products',
        'expired_date',
        'expiry_date',
        'expired_at',
        'exp'
    ] as $c) {
        if (in_array($c, $cols, true)) {
            return $c;
        }
    }

    return '';
}

/**
 * Kurangi stok WQS berdasarkan DO, office, product, dan jika ada berdasarkan Serial/Lot yang dipilih.
 * Ini dipakai untuk CREATE DO agar stok cabang langsung berkurang sesuai baris lot yang dipilih.
 */
function sd_reduce_stock_by_do_id_lot_aware(PDO $pdo, int $doId): void
{
    if ($doId <= 0) {
        throw new Exception('DO ID tidak valid untuk pengurangan stok.');
    }
    if (!sd_has_table($pdo, 'wqs_stock_by_office')) {
        throw new Exception('Table wqs_stock_by_office belum tersedia.');
    }

    $stockCols = sd_table_columns($pdo, 'wqs_stock_by_office');
    $hasStockId = in_array('id', $stockCols, true);
    $hasUpdatedAt = in_array('updated_at', $stockCols, true);
    $setUpdatedAt = $hasUpdatedAt ? ', updated_at = NOW()' : '';
    $lotCols = sd_wqs_lot_columns($pdo);
    $expCol = sd_wqs_exp_column($pdo);

    // FIFO / FEFO: expired terdekat dulu. Jika tidak ada kolom expired, urut berdasarkan id.
    // FIX: MySQL tidak menerima ORDER BY 0 pada beberapa mode/versi dan memunculkan:
    // "Unknown column '0' in 'ORDER BY'". Karena cabang ini hanya dipakai saat kolom id ada,
    // fallback paling aman adalah s.id ASC.
    $fifoOrder = $expCol !== ''
        ? "CASE WHEN s.`{$expCol}` IS NULL OR TRIM(CAST(s.`{$expCol}` AS CHAR)) = '' OR CAST(s.`{$expCol}` AS CHAR) = '0000-00-00' THEN 1 ELSE 0 END ASC, s.`{$expCol}` ASC, s.id ASC"
        : "s.id ASC";

    // Penting: group berdasarkan SKU normalisasi, bukan product_id.
    // Banyak data import memiliki SKU sama dengan product_id berbeda per lot/office.
    $st = $pdo->prepare("
        SELECT
            UPPER(TRIM(d.office_code)) AS office_code,
            MIN(i.product_id) AS product_id,
            COALESCE(i.sku, '') AS sku,
            SUM(i.qty) AS total_qty,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(COALESCE(i.serial_lot, '')), '') SEPARATOR ' + ') AS serial_lot
        FROM sales_do d
        JOIN sales_do_items i ON i.do_id = d.id
        WHERE d.id = ?
        GROUP BY UPPER(TRIM(d.office_code)), " . sd_norm_sku_sql('i.sku') . "
    ");
    $st->execute([$doId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        throw new Exception('Item DO tidak ditemukan untuk pengurangan stok.');
    }

    $pdo->prepare("DELETE FROM sales_do_stock_allocations WHERE do_id=?")->execute([$doId]);

    foreach ($items as $it) {
        $officeCode = strtoupper(trim((string)($it['office_code'] ?? '')));
        $productId  = (int)($it['product_id'] ?? 0);
        $sku        = trim((string)($it['sku'] ?? ''));
        $skuNorm    = sd_norm_sku_value($sku);
        $qty        = (float)($it['total_qty'] ?? 0);

        if ($officeCode === '' || $qty <= 0 || ($productId <= 0 && $skuNorm === '')) {
            throw new Exception("Data stok tidak valid untuk SKU {$sku}.");
        }

        $params = [];
        $aliases = sd_office_aliases($pdo, $officeCode);
        $whereParts = [sd_office_alias_where('s.office_code', $aliases, $params)];

        // Matching utama: SKU yang dinormalisasi agar "20/ 5*15CM" = "20/5*15CM".
        // Tambahkan fallback product_id karena beberapa data lama masih belum rapi.
        if ($skuNorm !== '') {
            $whereParts[] = "(" . sd_norm_sku_sql('p.sku') . " = ? OR s.product_id = ?)";
            $params[] = $skuNorm;
            $params[] = $productId;
        } else {
            $whereParts[] = "s.product_id = ?";
            $params[] = $productId;
        }

        $whereStock = implode(' AND ', $whereParts);

        if ($hasStockId) {
            $selectFields = "s.id, s.product_id, s.stock_qty";
            foreach ($lotCols as $lc) {
                $selectFields .= ", s.`{$lc}` AS `{$lc}`";
            }

            // FIX: beberapa data import menyimpan LOT/SERI di master_products, bukan di wqs_stock_by_office.
            // Ambil juga dari master agar AUTO FIFO yang disimpan ke sales_do_items tidak jatuh ke ROW-id.
            $mpColsForLot = sd_table_columns($pdo, 'master_products');
            $masterLotCols = ['lot_number','lot_no','serial_lot','serial_number','serial_no','batch_number','batch_no'];
            foreach ($masterLotCols as $mlc) {
                if (in_array($mlc, $mpColsForLot, true)) {
                    $selectFields .= ", p.`{$mlc}` AS `mp_{$mlc}`";
                }
            }

            if ($expCol !== '') {
                $selectFields .= ", s.`{$expCol}` AS fifo_exp_date";
            } else {
                $selectFields .= ", NULL AS fifo_exp_date";
            }

            $sqlRows = "
                SELECT {$selectFields}
                FROM wqs_stock_by_office s
                LEFT JOIN master_products p ON p.id = s.product_id
                WHERE {$whereStock}
                  AND COALESCE(s.stock_qty, 0) > 0
                ORDER BY {$fifoOrder}
                FOR UPDATE
            ";

            $lock = $pdo->prepare($sqlRows);
            $lock->execute($params);
            $rows = $lock->fetchAll(PDO::FETCH_ASSOC);

            // Fallback ekstra: jika SKU di master tidak cocok tapi product_id ada, cari by product_id saja.
            if (empty($rows) && $productId > 0) {
                $fbParams = [];
                $fbOfficeWhere = sd_office_alias_where('s.office_code', $aliases, $fbParams);
                $lock = $pdo->prepare("
                    SELECT {$selectFields}
                    FROM wqs_stock_by_office s
                    LEFT JOIN master_products p ON p.id = s.product_id
                    WHERE {$fbOfficeWhere}
                      AND s.product_id = ?
                      AND COALESCE(s.stock_qty, 0) > 0
                    ORDER BY {$fifoOrder}
                    FOR UPDATE
                ");
                $lock->execute(array_merge($fbParams, [$productId]));
                $rows = $lock->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($rows)) {
                throw new Exception("Stok SKU {$sku} tidak ditemukan di office {$officeCode}. Cek apakah stok WQS office tersebut ada dan SKU sama setelah normalisasi.");
            }

            $available = 0.0;
            foreach ($rows as $r) {
                $available += (float)($r['stock_qty'] ?? 0);
            }

            if ($available + 0.00001 < $qty) {
                throw new Exception("Stok SKU {$sku} tidak cukup di office {$officeCode}. Tersedia: {$available}, diminta: {$qty}.");
            }

            $remaining = $qty;
            $allocationParts = [];

            foreach ($rows as $r) {
                if ($remaining <= 0.00001) {
                    break;
                }

                $rowId = (int)($r['id'] ?? 0);
                $rowStock = (float)($r['stock_qty'] ?? 0);
                if ($rowId <= 0 || $rowStock <= 0) {
                    continue;
                }

                $take = min($rowStock, $remaining);

                // Update aman: hanya kurangi jika stok row masih cukup.
                $up = $pdo->prepare("
                    UPDATE wqs_stock_by_office
                    SET stock_qty = COALESCE(stock_qty, 0) - ?{$setUpdatedAt}
                    WHERE id = ?
                      AND COALESCE(stock_qty, 0) >= ?
                ");
                $up->execute([$take, $rowId, $take]);

                if ($up->rowCount() < 1) {
                    // Recheck untuk pesan error lebih jelas, bukan langsung pesan umum.
                    $re = $pdo->prepare("SELECT COALESCE(stock_qty, 0) FROM wqs_stock_by_office WHERE id = ? LIMIT 1");
                    $re->execute([$rowId]);
                    $nowStock = (float)($re->fetchColumn() ?: 0);
                    throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}. Row stok ID {$rowId} berubah. Stok sekarang: {$nowStock}, butuh ambil: {$take}.");
                }

                sd_record_stock_allocation($pdo, $doId, $rowId, (int)($r['product_id'] ?? $productId), $officeCode, $sku, $take);

                $lotName = '';
                foreach ($lotCols as $lc) {
                    $v = trim((string)($r[$lc] ?? ''));
                    if ($v !== '') {
                        $lotName = $v;
                        break;
                    }
                }

                // FIX: fallback ke master_products jika kolom LOT/SERI di stok office kosong/tidak ada.
                if ($lotName === '') {
                    foreach (['lot_number','lot_no','serial_lot','serial_number','serial_no','batch_number','batch_no'] as $mlc) {
                        $v = trim((string)($r['mp_' . $mlc] ?? ''));
                        if ($v !== '') {
                            $lotName = $v;
                            break;
                        }
                    }
                }

                if ($lotName === '') {
                    $lotName = 'ROW-' . $rowId;
                }

                $allocationParts[] = $lotName . '(' . $take . ')';
                sd_sync_global_stock_after_do($pdo, (int)($r['product_id'] ?? $productId));
                $remaining -= $take;
            }

            if ($remaining > 0.00001) {
                throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}. Sisa qty belum terpotong: {$remaining}.");
            }

            // Simpan informasi FIFO ke sales_do_items.serial_lot supaya muncul di sales_do_view.php.
            if (!empty($allocationParts) && sd_has_column($pdo, 'sales_do_items', 'serial_lot')) {
                try {
                    $allocText = 'AUTO FIFO: ' . implode(' + ', $allocationParts);
                    $updItem = $pdo->prepare("
                        UPDATE sales_do_items
                        SET serial_lot = ?
                        WHERE do_id = ?
                          AND " . sd_norm_sku_sql('sku') . " = ?
                    ");
                    $updItem->execute([$allocText, $doId, $skuNorm]);
                } catch (Throwable $e) {
                    // Tidak menggagalkan DO jika hanya gagal update label serial/lot.
                }
            }
        } else {
            // Fallback struktur lama tanpa kolom id.
            // Multi-row FIFO tidak bisa akurat tanpa id, jadi pakai aggregate + update product_id.
            $lock = $pdo->prepare("
                SELECT COALESCE(SUM(s.stock_qty), 0)
                FROM wqs_stock_by_office s
                LEFT JOIN master_products p ON p.id = s.product_id
                WHERE {$whereStock}
                FOR UPDATE
            ");
            $lock->execute($params);
            $available = (float)($lock->fetchColumn() ?: 0);

            if ($available + 0.00001 < $qty) {
                throw new Exception("Stok SKU {$sku} tidak cukup di office {$officeCode}. Tersedia: {$available}, diminta: {$qty}.");
            }

            $upParams = sd_office_alias_execute_params([$qty, $productId], $aliases, [$qty]);
            $dummyParams = [];
            $upOfficeWhere = sd_office_alias_where('office_code', $aliases, $dummyParams);

            $up = $pdo->prepare("
                UPDATE wqs_stock_by_office
                SET stock_qty = stock_qty - ?{$setUpdatedAt}
                WHERE product_id = ?
                  AND {$upOfficeWhere}
                  AND stock_qty >= ?
                LIMIT 1
            ");
            $up->execute($upParams);

            if ($up->rowCount() < 1) {
                throw new Exception("Gagal mengurangi stok SKU {$sku} di office {$officeCode}. Struktur stok tanpa kolom id tidak mendukung FIFO multi-row.");
            }

            sd_sync_global_stock_after_do($pdo, $productId);
        }
    }

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
sd_ensure_stock_allocations($pdo);
// --------------------------------------------------------
// AJAX: LOAD PRODUCTS BY OFFICE STOCK
// --------------------------------------------------------
if ($__sd_depo_restricted && isset($_GET['ajax'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'message'=>'Akun Depo hanya memiliki akses DO read-only.','products'=>[]]);
    exit;
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'products_by_office') {

    header('Content-Type: application/json; charset=utf-8');

    $office = strtoupper(trim((string)($_GET['office_code'] ?? '')));
    // Satu DO hanya satu kelompok bisnis. UNIT ACC boleh berisi item ALKES + AKSESORIS sekaligus.
    $ajaxBusinessGroup = strtoupper(trim((string)($_GET['do_business_group'] ?? $_GET['business_group'] ?? '')));
    // Backward compatibility request lama dari browser/cache.
    if ($ajaxBusinessGroup === '') {
        $legacyCat = strtoupper(trim((string)($_GET['do_category'] ?? '')));
        $ajaxBusinessGroup = in_array($legacyCat, ['ALKES','AKSESORIS','UNIT_ACC'], true) ? 'UNIT_ACC' : 'BMHP';
    }
    if (!in_array($ajaxBusinessGroup, ['BMHP','UNIT_ACC'], true)) $ajaxBusinessGroup = 'BMHP';

    // Jangan pakai information_schema: user DB produksi tidak selalu punya akses.
    $checkTable = $pdo->query("SHOW TABLES LIKE 'wqs_stock_by_office'");
    if (!$checkTable || !$checkTable->fetchColumn()) {
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
$hasCategory = in_array('category', $mpCols, true);
$hasBusinessGroup = in_array('business_group', $mpCols, true);

// Lot / expired date bisa tersimpan di wqs_stock_by_office ATAU di master_products
// tergantung sumber import. Karena itu AJAX produk harus membaca keduanya.
$stockCols = sd_table_columns($pdo, 'wqs_stock_by_office');
$hasLotNumber = in_array('lot_number', $stockCols, true);
$hasLotNo     = in_array('lot_no', $stockCols, true);
$hasSerialLot = in_array('serial_lot', $stockCols, true);
$hasSerialNo  = in_array('serial_no', $stockCols, true);
$hasBatchNo   = in_array('batch_number', $stockCols, true) || in_array('batch_no', $stockCols, true);
$hasExpDate   = in_array('exp_date', $stockCols, true);
$hasExpired   = in_array('expired_products', $stockCols, true) || in_array('expired_date', $stockCols, true) || in_array('expiry_date', $stockCols, true);

if ($hasLotNumber) {
    $stockLotExpr = "s.lot_number";
} elseif ($hasLotNo) {
    $stockLotExpr = "s.lot_no";
} elseif ($hasSerialLot) {
    $stockLotExpr = "s.serial_lot";
} elseif ($hasSerialNo) {
    $stockLotExpr = "s.serial_no";
} elseif (in_array('batch_number', $stockCols, true)) {
    $stockLotExpr = "s.batch_number";
} elseif (in_array('batch_no', $stockCols, true)) {
    $stockLotExpr = "s.batch_no";
} else {
    $stockLotExpr = "''";
}

if ($hasExpDate) {
    $stockExpExpr = "s.exp_date";
} elseif (in_array('expired_products', $stockCols, true)) {
    $stockExpExpr = "s.expired_products";
} elseif (in_array('expired_date', $stockCols, true)) {
    $stockExpExpr = "s.expired_date";
} elseif (in_array('expiry_date', $stockCols, true)) {
    $stockExpExpr = "s.expiry_date";
} else {
    $stockExpExpr = "NULL";
}

$hasMpLotNumber = in_array('lot_number', $mpCols, true);
$hasMpLotNo     = in_array('lot_no', $mpCols, true);
$hasMpSerialLot = in_array('serial_lot', $mpCols, true);
$hasMpBatchNo   = in_array('batch_number', $mpCols, true);
$hasMpExpired   = in_array('expired_products', $mpCols, true);
$hasMpExpDate   = in_array('exp_date', $mpCols, true);

$productLotExpr = $hasMpLotNumber ? "p.lot_number" : ($hasMpLotNo ? "p.lot_no" : ($hasMpSerialLot ? "p.serial_lot" : ($hasMpBatchNo ? "p.batch_number" : "''")));
$productExpExpr = $hasMpExpired ? "p.expired_products" : ($hasMpExpDate ? "p.exp_date" : "NULL");

$lotExpr = "COALESCE(NULLIF(TRIM({$stockLotExpr}), ''), NULLIF(TRIM({$productLotExpr}), ''), '')";
$expExpr = "COALESCE(NULLIF({$stockExpExpr}, ''), NULLIF({$productExpExpr}, ''), NULL)";

$sql = "
    SELECT
        p.id,
        p.sku,
        p.products_name,
        p.unit,
        " . ($hasPrice ? "COALESCE(p.price, 0) AS price" : "0 AS price") . ",
        " . ($hasBarcode ? "p.barcode" : "'' AS barcode") . ",
        " . ($hasType ? "p.product_type" : "'' AS product_type") . ",
        " . ($hasCategory ? "UPPER(TRIM(COALESCE(p.category,''))) AS category" : "'' AS category") . ",
        " . ($hasBusinessGroup ? "UPPER(TRIM(COALESCE(p.business_group,'BMHP'))) AS business_group" : "'BMHP' AS business_group") . ",
        COALESCE(s.stock_qty, 0) AS stock_qty,
        COALESCE({$lotExpr}, '') AS lot_number,
        {$expExpr} AS exp_date,
        s.office_code,
        s.updated_at
    FROM wqs_stock_by_office s
    INNER JOIN master_products p ON p.id = s.product_id
    WHERE {OFFICE_WHERE_AJAX}
      AND LOWER(TRIM(COALESCE(p.status, 'active'))) = 'active'
      AND COALESCE(s.stock_qty, 0) > 0
    ORDER BY p.products_name ASC, lot_number ASC
";

        $officeAliases = sd_office_aliases($pdo, $office);
        $ajaxParams = [];
        $officeWhereAjax = sd_office_alias_where('s.office_code', $officeAliases, $ajaxParams);
        $sql = str_replace('{OFFICE_WHERE_AJAX}', $officeWhereAjax, $sql);

        // Filter CRM berdasarkan KELOMPOK BISNIS, bukan satu kategori header.
        // BMHP: hanya BMHP. UNIT_ACC: boleh ALKES + AKSESORIS dalam DO yang sama.
        $groupClause = '';
        if ($ajaxBusinessGroup === 'BMHP') {
            if ($hasBusinessGroup && $hasCategory) {
                $groupClause = "UPPER(TRIM(COALESCE(p.business_group,'BMHP'))) = 'BMHP' AND UPPER(TRIM(COALESCE(p.category,''))) = 'BMHP'";
            } elseif ($hasCategory) {
                $groupClause = "UPPER(TRIM(COALESCE(p.category,''))) = 'BMHP'";
            }
        } elseif ($ajaxBusinessGroup === 'UNIT_ACC') {
            if ($hasBusinessGroup && $hasCategory) {
                $groupClause = "UPPER(TRIM(COALESCE(p.business_group,''))) = 'UNIT_ACC' AND UPPER(TRIM(COALESCE(p.category,''))) IN ('ALKES','AKSESORIS')";
            } else {
                // Tanpa business_group jangan menebak Unit ACC dari category saja.
                $groupClause = "1=0";
            }
        }
        if ($groupClause !== '') {
            $sql = str_replace(
                "AND LOWER(TRIM(COALESCE(p.status, 'active'))) = 'active'",
                "AND LOWER(TRIM(COALESCE(p.status, 'active'))) = 'active'\n      AND {$groupClause}",
                $sql
            );
        }

        $st = $pdo->prepare($sql);
        $st->execute($ajaxParams);
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
$perm_sales_print_cf = function_exists('can') ? can('SALES.PRINT') : true;
if ($__sd_depo_restricted) {
    // Depo tidak membuat/mengedit/menghapus/export Sales DO. Proses operasional dilakukan
    // dari WQS Task DO dan SCM Task DO pada office sendiri.
    $perm_sales_create = false;
    $perm_sales_edit = false;
    $perm_sales_delete = false;
    $perm_sales_export = false;
}

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
    global $_sd_office_scope, $pdo;
    if ($_sd_office_scope === null) return true;

    $rowOffice = sd_norm_office_value((string)($do_row['office_code'] ?? ''));
    $scopeOffice = sd_norm_office_value((string)$_sd_office_scope);
    if ($rowOffice === $scopeOffice) return true;

    try {
        if ($pdo instanceof PDO) {
            $aliases = sd_office_aliases($pdo, $scopeOffice);
            return in_array($rowOffice, $aliases, true);
        }
    } catch (Throwable $e) {}

    return false;
}


// -------------------------------------------------------------------------
// AJAX: HISTORY HARGA JUAL SEBELUMNYA (READ-ONLY)
// -------------------------------------------------------------------------
// Dipakai oleh CRM saat memilih produk. Sumber = sales_do_items yang memang
// pernah tersimpan pada DO customer yang sama. Tidak mengubah pricelist,
// harga input, status DO, stok, ataupun workflow.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'price_history') {
    header('Content-Type: application/json; charset=utf-8');

    $customerCode = strtoupper(trim((string)($_GET['customers_code'] ?? '')));
    $officeCode   = strtoupper(trim((string)($_GET['office_code'] ?? '')));
    $skuRaw       = trim((string)($_GET['sku'] ?? ''));
    $skuNorm      = sd_norm_sku_value($skuRaw);
    $excludeDoId  = max(0, (int)($_GET['exclude_do_id'] ?? 0));

    if ($customerCode === '' || $skuNorm === '') {
        echo json_encode(['ok'=>true, 'history'=>[]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // BRANCH hanya boleh membaca histori harga dari office sendiri.
    if ($_sd_office_scope !== null) {
        $officeCode = (string)$_sd_office_scope;
    }

    try {
        $where = [
            "UPPER(TRIM(d.customers_code)) = ?",
            sd_norm_sku_sql('i.sku') . " = ?",
            "LOWER(TRIM(COALESCE(d.status,''))) NOT IN ('cancelled','canceled','rejected','reject','void','voided')"
        ];
        $paramsHist = [$customerCode, $skuNorm];

        if ($officeCode !== '') {
            $aliasesHist = sd_office_aliases($pdo, $officeCode);
            $where[] = sd_office_alias_where('d.office_code', $aliasesHist, $paramsHist);
        }
        if ($excludeDoId > 0) {
            $where[] = "d.id <> ?";
            $paramsHist[] = $excludeDoId;
        }
        if (sd_has_column($pdo, 'sales_do', 'is_replacement_fulfillment')) {
            // Fulfillment pengganti retur bukan harga transaksi komersial baru.
            $where[] = "COALESCE(d.is_replacement_fulfillment,0)=0";
        }

        $includeTaxExpr = sd_has_column($pdo, 'sales_do', 'is_price_include_tax')
            ? "COALESCE(d.is_price_include_tax,0)"
            : "0";

        $sqlHist = "
            SELECT
                d.id AS do_id,
                d.do_code,
                d.do_date,
                d.office_code,
                d.status,
                {$includeTaxExpr} AS include_tax,
                i.sku,
                i.products_name,
                i.unit_price,
                i.disc_percent,
                i.qty,
                i.subtotal
            FROM sales_do_items i
            JOIN sales_do d ON d.id=i.do_id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY d.do_date DESC, d.id DESC, i.id DESC
            LIMIT 8
        ";

        $stHist = $pdo->prepare($sqlHist);
        $stHist->execute($paramsHist);
        $history = [];

        foreach ($stHist->fetchAll(PDO::FETCH_ASSOC) ?: [] as $hr) {
            $unitPrice = (float)($hr['unit_price'] ?? 0);
            $disc = (float)($hr['disc_percent'] ?? 0);
            $netAfterDisc = $unitPrice * (100 - max(0, min(100, $disc))) / 100;

            $history[] = [
                'do_id'       => (int)($hr['do_id'] ?? 0),
                'do_code'     => (string)($hr['do_code'] ?? ''),
                'do_date'     => (string)($hr['do_date'] ?? ''),
                'office_code' => (string)($hr['office_code'] ?? ''),
                'status'      => (string)($hr['status'] ?? ''),
                'include_tax' => (int)($hr['include_tax'] ?? 0),
                'sku'         => (string)($hr['sku'] ?? ''),
                'product'     => (string)($hr['products_name'] ?? ''),
                'unit_price'  => $unitPrice,
                'disc_percent'=> $disc,
                'net_after_disc' => $netAfterDisc,
            ];
        }

        echo json_encode([
            'ok' => true,
            'customers_code' => $customerCode,
            'office_code' => $officeCode,
            'sku' => $skuRaw,
            'history' => $history,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'message' => 'Riwayat harga belum dapat dibaca.',
            'history' => [],
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// -------------------------------------------------------------------------
// BRANCH DEPO KAL/JGY — SALES DO READ-ONLY
// -------------------------------------------------------------------------
// Berhenti sebelum blok CREATE/EDIT/DELETE/repair agar akun kerja sama tidak
// mendapat permukaan mutasi CRM. Branch internal tidak masuk kondisi ini.
if ($__sd_depo_restricted) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        exit('Method Not Allowed');
    }
    if ($_sd_office_scope === null || $_sd_office_scope === '') {
        http_response_code(403);
        exit('Office akun Depo belum dikonfigurasi.');
    }

    $qDepo = trim((string)($_GET['q'] ?? ''));
    $paramsDepo = [];
    if (function_exists('rmi_office_in_sql')) {
        $officeWhereDepo = rmi_office_in_sql($pdo, 'd.office_code', $_sd_office_scope, $paramsDepo);
    } else {
        $officeWhereDepo = 'UPPER(TRIM(d.office_code)) = ?';
        $paramsDepo[] = $_sd_office_scope;
    }
    $sqlDepo = "SELECT d.id,d.do_code,d.do_date,d.office_code,d.customers_code,d.status,d.grand_total,d.tracking_code,
                       c.customers_name,o.office_name
                FROM sales_do d
                LEFT JOIN master_customers c ON c.customers_code=d.customers_code
                LEFT JOIN master_office o ON o.office_code=d.office_code
                WHERE {$officeWhereDepo}";
    if ($qDepo !== '') {
        $sqlDepo .= " AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR COALESCE(c.customers_name,'') LIKE ?)";
        $like = '%' . $qDepo . '%';
        array_push($paramsDepo, $like, $like, $like);
    }
    $sqlDepo .= " ORDER BY d.created_at DESC,d.id DESC LIMIT 1000";
    $stDepo = $pdo->prepare($sqlDepo);
    $stDepo->execute($paramsDepo);
    $rowsDepo = $stDepo->fetchAll(PDO::FETCH_ASSOC) ?: [];

    require_once __DIR__ . '/../_shared/rmi_layout.php';
    $bp = rmi_layout_base_project();
    rmi_header('Delivery Order — ' . $_sd_office_scope, [
        'active'=>'depo_do',
        'subtitle'=>'Read-only DO office sendiri. Proses WQS/SCM dilakukan dari Task DO.',
        'skip_panduan_link'=>true,
        'breadcrumbs'=>['Depo','Delivery Order'],
        'actions'=>[
            ['label'=>'Pencapaian','url'=>$bp . '/dashboards/finance/dashboard_detail.php','class'=>'btn btn-sm btn-outline-light'],
            ['label'=>'WQS Task','url'=>$bp . '/stock/wqs_do_tasks.php','class'=>'btn btn-sm btn-outline-light'],
            ['label'=>'SCM Task','url'=>$bp . '/sales/scm_do_tasks.php','class'=>'btn btn-sm btn-outline-light'],
            ['label'=>'MPR','url'=>$bp . '/mpr/mpr_dashboard.php','class'=>'btn btn-sm btn-outline-light'],
        ],
    ]);
    ?>
    <form class="row g-2 mb-3" method="get">
      <div class="col-md-10"><input class="form-control" name="q" value="<?= h($qDepo) ?>" placeholder="Cari nomor DO / customer"></div>
      <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Cari</button></div>
    </form>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead><tr><th>DO</th><th>Tanggal</th><th>Customer</th><th>Office</th><th>Status</th><th class="text-end">Nilai</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (!$rowsDepo): ?>
          <tr><td colspan="7" class="text-center text-secondary py-4">Belum ada DO pada office <?= h($_sd_office_scope) ?>.</td></tr>
        <?php else: foreach ($rowsDepo as $r):
          $st = strtolower(trim((string)($r['status'] ?? '')));
        ?>
          <tr>
            <td><strong><?= h($r['do_code'] ?? '') ?></strong></td>
            <td><?= h($r['do_date'] ?? '') ?></td>
            <td><?= h($r['customers_name'] ?? $r['customers_code'] ?? '') ?></td>
            <td><?= h($r['office_code'] ?? '') ?></td>
            <td><?= h(strtoupper($st)) ?></td>
            <td class="text-end">Rp <?= number_format((float)($r['grand_total'] ?? 0),0,',','.') ?></td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-light" href="sales_do_view.php?id=<?= (int)$r['id'] ?>">View</a>
              <?php if ($perm_sales_print_cf): ?>
                <a class="btn btn-sm btn-outline-info"
                   href="sales_do_print_cf.php?id=<?= (int)$r['id'] ?>"
                   target="_blank"
                   rel="noopener"
                   title="Print Continuous Form / Dot-Matrix 9.5 × 11 inch"><?= rmi_icon('print') ?> Print CF</a>
              <?php endif; ?>
              <?php if (in_array($st,['crm_to_wqs','sent_wqs','revision_requested','wqs_processing'],true)): ?>
                <a class="btn btn-sm btn-outline-warning" href="<?= h($bp) ?>/stock/wqs_do_tasks.php">WQS</a>
              <?php elseif (in_array($st,['ready_scm','on_delivery'],true)): ?>
                <a class="btn btn-sm btn-outline-info" href="<?= h($bp) ?>/sales/scm_do_tasks.php">SCM</a>
              <?php endif; ?>
              <?php if ($st === 'on_delivery'): ?>
                <a class="btn btn-sm btn-outline-success" href="<?= h($bp) ?>/sales/scm_tracker_mobile.php?do_id=<?= (int)$r['id'] ?>">Tracker</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    rmi_footer();
    exit;
}

$can_bulk_send_wqs = $perm_sales_edit;
$can_bulk_delete = $perm_sales_delete;

$CRM_ALLOWED_EDIT_STATUSES = ['crm_to_wqs','sent_wqs','revision_requested'];
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
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;

    // Jangan memakai information_schema dan jangan memakai placeholder pada SHOW TABLES.
    // Pada sebagian MariaDB/MySQL produksi, prepared SHOW ... LIKE ? dapat gagal walaupun
    // tabel sebenarnya ada. Uji langsung SELECT adalah source-of-truth yang paling aman.
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        // Fallback untuk tabel kosong / driver tertentu.
        try {
            $q = $pdo->quote($table);
            $st = $pdo->query("SHOW TABLES LIKE {$q}");
            return (bool)$st->fetchColumn();
        } catch (Throwable $e2) {
            return false;
        }
    }
}
function sd_table_columns_cached(PDO $pdo, string $table): array {
    static $cache=[];
    if(isset($cache[$table])) return $cache[$table];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return $cache[$table]=[];
    try{
        $cache[$table]=$pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN,0) ?: [];
    }catch(Throwable $e){
        $cache[$table]=[];
    }
    return $cache[$table];
}

function sd_has_column(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;

    // Ambil daftar kolom sekali via SHOW COLUMNS tanpa placeholder.
    $cols = sd_table_columns_cached($pdo, $table);
    if ($cols) {
        foreach ($cols as $c) {
            if (strcasecmp((string)$c, $column) === 0) return true;
        }
        return false;
    }

    // Fallback terakhir: compile SELECT kolom tanpa membaca data.
    try {
        $pdo->query("SELECT `{$column}` FROM `{$table}` LIMIT 0");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Return/replacement linkage helpers.
 * Satu DO pengganti wajib terhubung eksplisit ke retur final agar dashboard tidak
 * menebak hubungan berdasarkan tanggal/nominal/customer.
 */
function sd_latest_completed_return_id(PDO $pdo, int $doId): int
{
    if ($doId <= 0 || !sd_has_table($pdo, 'sales_do_returns')) return 0;
    try {
        $st = $pdo->prepare("SELECT id FROM sales_do_returns
                             WHERE do_id=?
                               AND LOWER(COALESCE(status,'')) IN ('return_scm_completed','return_completed','completed','closed')
                             ORDER BY COALESCE(scm_completed_at, updated_at, created_at) DESC, id DESC
                             LIMIT 1");
        $st->execute([$doId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) { return 0; }
}

function sd_replacement_context(PDO $pdo, int $originalDoId, int $returnId): array
{
    if ($originalDoId <= 0 || $returnId <= 0 || !sd_has_table($pdo, 'sales_do_returns')) return [];
    try {
        $hasCommercialEffect = sd_has_column($pdo, 'sales_do_returns', 'commercial_effect');
        $ceExpr = $hasCommercialEffect ? "r.commercial_effect" : "NULL AS commercial_effect";
        $st = $pdo->prepare("SELECT r.id return_id, r.do_id original_do_id, r.return_code, r.status return_status,
                                    r.office_code return_office, r.customer_code return_customer,
                                    {$ceExpr},
                                    d.do_code original_do_code, d.office_code, d.customers_code
                             FROM sales_do_returns r
                             JOIN sales_do d ON d.id=r.do_id
                             WHERE r.id=? AND r.do_id=? LIMIT 1");
        $st->execute([$returnId, $originalDoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$row) return [];
        if (!in_array(strtolower(trim((string)$row['return_status'])), ['return_scm_completed','return_completed','completed','closed'], true)) return [];

        // Kembalikan konteks FINAL apa adanya. Validasi commercial_effect dilakukan di caller
        // supaya UI dapat menjelaskan penyebab secara spesifik, bukan diam-diam menampilkan
        // dropdown produk kosong.
        return $row;
    } catch (Throwable $e) { return []; }
}

function sd_validate_replacement_items(PDO $pdo, int $returnId, array $validItems): void
{
    if ($returnId <= 0) return;
    if (!sd_has_table($pdo, 'sales_do_return_items')) {
        throw new Exception('Data item retur tidak tersedia untuk validasi DO pengganti.');
    }

    /*
     * V35 — FLEXIBLE REPLACEMENT PRODUCT / QTY / VALUE
     * ------------------------------------------------
     * Barang pengganti BOLEH berbeda:
     * - product_id / SKU;
     * - qty;
     * - unit_price;
     * - discount.
     *
     * Yang wajib tetap:
     * - setiap baris replacement harus menunjuk return_item_id yang sah;
     * - replacement tetap bukan NEW SALE dan tidak boleh menambah achievement kedua kali;
     * - stok mengikuti produk/qty pengganti yang benar-benar dipilih;
     * - histori source return tetap tersimpan eksplisit.
     *
     * Perbedaan nilai pada DO pengganti adalah nilai operasional/reference replacement.
     * Bila selisih memang akan ditagihkan/dikreditkan ke customer, proses tersebut harus
     * melalui dokumen komersial/adjustment tersendiri, bukan menjadikan replacement sebagai sales baru.
     */

    // Satu return = satu DO pengganti aktif.
    if (sd_has_column($pdo, 'sales_do', 'replacement_return_id')) {
        $used = $pdo->prepare("SELECT id,do_code
                               FROM sales_do
                               WHERE replacement_return_id=?
                                 AND LOWER(COALESCE(status,'')) NOT IN ('cancelled','canceled','rejected','reject','void','voided')
                               ORDER BY id DESC LIMIT 1");
        $used->execute([$returnId]);
        $existing = $used->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($existing) {
            throw new Exception('Retur ini sudah mempunyai DO pengganti aktif ' . (string)($existing['do_code'] ?? '') . '. Jangan membuat DO pengganti kedua.');
        }
    }

    $st = $pdo->prepare("
        SELECT
            ri.id return_item_id,
            ri.do_id,
            COALESCE(ri.product_id,di.product_id,0) original_product_id,
            COALESCE(NULLIF(ri.sku,''),di.sku,'') original_sku,
            COALESCE(ri.qty_return,0) qty_return,
            COALESCE(di.unit_price,0) original_unit_price,
            COALESCE(di.disc_percent,0) original_disc_percent
        FROM sales_do_return_items ri
        LEFT JOIN sales_do_items di
          ON di.id=ri.do_item_id
         AND di.do_id=ri.do_id
        WHERE ri.return_id=?
          AND COALESCE(ri.qty_return,0)>0
        ORDER BY ri.id
    ");
    $st->execute([$returnId]);

    $source = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $rid = (int)($r['return_item_id'] ?? 0);
        if ($rid > 0) $source[$rid] = $r;
    }
    if (!$source) {
        throw new Exception('Item retur FINAL tidak ditemukan. DO pengganti tidak dapat dibuat.');
    }

    $usedSource = [];
    foreach ($validItems as $it) {
        $srcId = (int)($it['replacement_return_item_id'] ?? 0);

        // Baris prefill dari retur wajib membawa source return_item_id yang sah.
        // Baris tambahan CRM diperbolehkan tanpa source (srcId=0) dan tetap menjadi
        // bagian dari fulfillment DO pengganti yang sama — BUKAN penjualan baru.
        if ($srcId > 0) {
            if (!isset($source[$srcId])) {
                throw new Exception('Sumber item retur pada DO pengganti tidak valid.');
            }
            if (isset($usedSource[$srcId])) {
                throw new Exception('Satu item retur tidak boleh dipakai lebih dari satu kali dalam DO pengganti.');
            }
            $usedSource[$srcId] = true;
        }

        // Produk/qty/harga replacement maupun barang tambahan tetap divalidasi normal.
        $qtyAsked   = (float)($it['qty'] ?? 0);
        $priceAsked = (float)($it['unit_price'] ?? 0);
        $discAsked  = (float)($it['disc_percent'] ?? 0);

        if ($qtyAsked <= 0) {
            throw new Exception('Qty barang pengganti harus lebih dari 0.');
        }
        if ($priceAsked < 0) {
            throw new Exception('Harga barang pengganti tidak boleh negatif.');
        }
        if ($discAsked < 0 || $discAsked > 100) {
            throw new Exception('Diskon barang pengganti harus antara 0% sampai 100%.');
        }
    }

    if (!$usedSource) {
        throw new Exception('DO pengganti wajib tetap memuat minimal satu item yang berasal dari retur FINAL.');
    }
}

/**
 * Internal transfer classifier used ONLY to persist an explicit flag on sales_do.
 * High-confidence rules:
 * - customer code ending -INT
 * - master customer name starts with "KANTOR RIZQULLAH MEDISKA INDONESIA"
 * - master customer name starts with "KANTOR DEPO"
 * - explicit master customer type/flag if available
 *
 * Do not use general "RIZQULLAH MEDISKA" contains matching because that can
 * misclassify normal external customers.
 */
function sd_customer_is_internal_transfer(PDO $pdo, string $customerCode): bool {
    $code=strtoupper(trim($customerCode));
    if($code==='') return false;
    if(preg_match('/-INT$/',$code)) return true;

    try{
        $cols=sd_table_columns_cached($pdo,'master_customers');
        if(!$cols) return false;

        $nameCol=null;
        foreach(['customers_name','customer_name','name'] as $c){
            if(in_array($c,$cols,true)){ $nameCol=$c; break; }
        }
        $typeCol=null;
        foreach(['customer_type','customers_type','type','category','customer_category'] as $c){
            if(in_array($c,$cols,true)){ $typeCol=$c; break; }
        }
        $flagCol=null;
        foreach(['is_internal','is_internal_transfer','internal_flag','is_intercompany'] as $c){
            if(in_array($c,$cols,true)){ $flagCol=$c; break; }
        }
        if(!in_array('customers_code',$cols,true)) return false;

        $sel=["customers_code"];
        if($nameCol) $sel[]="`{$nameCol}` AS customer_name";
        if($typeCol) $sel[]="`{$typeCol}` AS customer_type";
        if($flagCol) $sel[]="`{$flagCol}` AS internal_flag";

        $q=$pdo->prepare("SELECT ".implode(',',$sel)." FROM master_customers WHERE UPPER(TRIM(customers_code))=? ORDER BY id DESC LIMIT 1");
        $q->execute([$code]);
        $r=$q->fetch(PDO::FETCH_ASSOC) ?: [];

        $name=strtoupper(trim((string)($r['customer_name']??'')));
        $type=strtoupper(trim((string)($r['customer_type']??'')));
        $flag=strtoupper(trim((string)($r['internal_flag']??'')));

        if(str_starts_with($name,'KANTOR RIZQULLAH MEDISKA INDONESIA')) return true;
        if(str_starts_with($name,'KANTOR DEPO ')) return true;
        if(in_array($type,['INTERNAL','INTERCOMPANY'],true)) return true;
        if(in_array($flag,['1','Y','YES','TRUE','INTERNAL','INTERCOMPANY'],true)) return true;
    }catch(Throwable $e){}
    return false;
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
if (sd_has_table($pdo, 'sales_do_items') && !sd_has_column($pdo, 'sales_do_items', 'business_group')) {
    try { $pdo->exec("ALTER TABLE `sales_do_items` ADD COLUMN `business_group` VARCHAR(20) NOT NULL DEFAULT 'BMHP' AFTER `category`"); }
    catch (Throwable $e) {}
}
if (sd_has_table($pdo, 'sales_do') && !sd_has_column($pdo, 'sales_do', 'stock_reduced_at')) {
    try { $pdo->exec("ALTER TABLE `sales_do` ADD COLUMN `stock_reduced_at` DATETIME NULL AFTER updated_at"); }
    catch (Throwable $e) {}
}
if (sd_has_table($pdo, 'sales_do') && !sd_has_column($pdo, 'sales_do', 'revision_reason')) {
    try { $pdo->exec("ALTER TABLE `sales_do` ADD COLUMN `revision_reason` TEXT NULL"); }
    catch (Throwable $e) {}
}
if (sd_has_table($pdo, 'sales_do') && !sd_has_column($pdo, 'sales_do', 'revision_requested_by')) {
    try { $pdo->exec("ALTER TABLE `sales_do` ADD COLUMN `revision_requested_by` VARCHAR(100) NULL"); }
    catch (Throwable $e) {}
}
if (sd_has_table($pdo, 'sales_do') && !sd_has_column($pdo, 'sales_do', 'revision_requested_at')) {
    try { $pdo->exec("ALTER TABLE `sales_do` ADD COLUMN `revision_requested_at` DATETIME NULL"); }
    catch (Throwable $e) {}
}

// Explicit snapshot: internal transfer must be persisted on the DO itself.
if (sd_has_table($pdo,'sales_do') && !sd_has_column($pdo,'sales_do','is_internal_transfer')) {
    try { $pdo->exec("ALTER TABLE `sales_do` ADD COLUMN `is_internal_transfer` TINYINT(1) NOT NULL DEFAULT 0"); }
    catch(Throwable $e) {}
}
// Safe legacy backfill: only HIGH-CONFIDENCE internal identities.
try{
    if(sd_has_column($pdo,'sales_do','is_internal_transfer') && sd_has_table($pdo,'master_customers')){
        $pdo->exec("
            UPDATE sales_do d
            LEFT JOIN master_customers c
              ON UPPER(TRIM(c.customers_code))=UPPER(TRIM(d.customers_code))
            SET d.is_internal_transfer=1
            WHERE COALESCE(d.is_internal_transfer,0)=0
              AND (
                   UPPER(TRIM(d.customers_code)) LIKE '%-INT'
                OR UPPER(TRIM(COALESCE(c.customers_name,''))) LIKE 'KANTOR RIZQULLAH MEDISKA INDONESIA%'
                OR UPPER(TRIM(COALESCE(c.customers_name,''))) LIKE 'KANTOR DEPO %'
              )
        ");
    }
}catch(Throwable $e){}

// Explicit linkage retur -> DO pengganti. Ini menjadi source of truth untuk dashboard/rekonsiliasi.
foreach ([
    ['is_replacement_fulfillment', 'TINYINT(1) NOT NULL DEFAULT 0'],
    ['replacement_for_do_id', 'INT NULL'],
    ['replacement_return_id', 'INT NULL'],
    ['replacement_created_at', 'DATETIME NULL'],
    // Legacy repair only: operator membuat DO baru padahal seharusnya edit/revisi DO lama.
    ['revision_of_do_id', 'INT NULL'],
    ['revision_linked_at', 'DATETIME NULL'],
    ['revision_linked_by', 'VARCHAR(100) NULL'],
] as [$col, $ddl]) {
    if (sd_has_table($pdo, 'sales_do') && !sd_has_column($pdo, 'sales_do', $col)) {
        try { $pdo->exec("ALTER TABLE `sales_do` ADD COLUMN `{$col}` {$ddl}"); } catch (Throwable $e) {}
    }
}
try {
    $idx = $pdo->query("SHOW INDEX FROM sales_do WHERE Key_name='idx_sales_do_replacement_return'")->fetchColumn();
    if (!$idx && sd_has_column($pdo,'sales_do','replacement_return_id')) {
        $pdo->exec("ALTER TABLE sales_do ADD INDEX idx_sales_do_replacement_return (replacement_return_id)");
    }
} catch (Throwable $e) {}


/*
 * Schema readiness untuk two-way linkage return -> replacement.
 * Jangan mengasumsikan sales_do_return.php sudah pernah dibuka.
 */
if (sd_has_table($pdo,'sales_do_returns')) {
    foreach ([
        ['replacement_do_id', 'INT NULL'],
        ['replacement_created_at', 'DATETIME NULL'],
    ] as [$col,$ddl]) {
        if (!sd_has_column($pdo,'sales_do_returns',$col)) {
            try { $pdo->exec("ALTER TABLE `sales_do_returns` ADD COLUMN `{$col}` {$ddl}"); }
            catch (Throwable $e) {
                $sd_schema_errors[] = "Kolom sales_do_returns.{$col} belum tersedia dan auto-migration gagal: ".$e->getMessage();
            }
        }
    }
}

if (sd_has_table($pdo, 'sales_do_items') && !sd_has_column($pdo, 'sales_do_items', 'no_po')) {
    try { $pdo->exec("ALTER TABLE `sales_do_items` ADD COLUMN `no_po` VARCHAR(100) NULL AFTER serial_lot"); }
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
    // Mixed Unit ACC memakai prefix sendiri; kategori ALKES/AKSESORIS berada di level item.
    if (strtoupper(trim($category)) === 'UNIT_ACC') {
        $prefix = 'UNITACC-' . strtoupper(trim((string)$office_code)) . '-' . (string)$dateYmd . '-';
    } else {
        $prefix = doc_prefix_do((string)$office_code, (string)$dateYmd, $category);
    }

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

/**
 * Hitung durasi CRM secara fail-safe untuk data baru maupun legacy.
 * Prioritas nilai tersimpan; bila kosong, derive dari jam order/start hingga waktu DO selesai dibuat.
 * Tidak mengubah status/workflow/stok.
 */
function sd_effective_crm_duration_sec(array $row): ?int
{
    // Jangan langsung mempercayai nilai 0 legacy. Pada versi lama, crm_duration_sec
    // dapat tersimpan 0 walaupun Jam Order Customer/RS dan waktu pembuatan DO berbeda.
    $stored = $row['crm_duration_sec'] ?? null;
    $storedIsNumeric = ($stored !== null && $stored !== '' && is_numeric($stored));
    $storedSec = $storedIsNumeric ? max(0, (int)$stored) : null;

    // Nilai tersimpan positif adalah sumber utama.
    if ($storedSec !== null && $storedSec > 0) {
        return $storedSec;
    }

    $startCandidates = [
        $row['crm_order_received_at'] ?? null,
        $row['crm_start_at'] ?? null,
    ];
    $finishCandidates = [
        $row['crm_finish_at'] ?? null,
        $row['crm_created_at'] ?? null,
        $row['created_at'] ?? null,
    ];

    $startTs = false;
    foreach ($startCandidates as $raw) {
        $raw = trim((string)$raw);
        if ($raw === '') continue;
        $ts = strtotime($raw);
        if ($ts !== false) { $startTs = $ts; break; }
    }

    if ($startTs !== false) {
        foreach ($finishCandidates as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') continue;
            $ts = strtotime($raw);
            if ($ts !== false && $ts >= $startTs) {
                $derived = max(0, (int)$ts - (int)$startTs);
                // Jika data waktu menunjukkan durasi positif, gunakan hasil derivasi.
                // Ini memperbaiki crm_duration_sec legacy yang salah tersimpan 0.
                if ($derived > 0) return $derived;
                // Kalau memang start == finish, 0 adalah nilai yang sah.
                if ($derived === 0 && $storedSec === 0) return 0;
            }
        }
    }

    // Jika timestamp pendukung tidak lengkap, baru gunakan nilai stored 0 bila memang ada.
    if ($storedSec !== null) return $storedSec;
    return null;
}

function sd_return_status_meta(string $status): array
{
    $status = strtolower(trim($status));
    return match ($status) {
        'return_requested'      => ['label' => 'RETUR MENUNGGU SCM', 'class' => 'return-requested'],
        'return_received'       => ['label' => 'RETUR DITERIMA SCM', 'class' => 'return-received'],
        'return_stocked'        => ['label' => 'RETUR STOK DIPROSES', 'class' => 'return-stocked'],
        'return_scm_completed'  => ['label' => 'RETUR SELESAI SCM', 'class' => 'return-completed'],
        'wait_act_review'       => ['label' => 'RETUR MENUNGGU ACT', 'class' => 'return-act'],
        'wait_fin_adjustment'   => ['label' => 'RETUR MENUNGGU FIN', 'class' => 'return-fin'],
        'closed'                => ['label' => 'RETUR SELESAI', 'class' => 'return-closed'],
        'cancelled'             => ['label' => 'RETUR DIBATALKAN', 'class' => 'return-cancelled'],
        default                 => ['label' => strtoupper($status), 'class' => 'return-other'],
    };
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
// CRM ORDER TIME helpers (manual jam order RS/customer untuk KPI CRM)
// --------------------------------------------------------
function sd_datetime_local_to_mysql(?string $raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $raw = str_replace('T', ' ', $raw);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt && $dt->format($fmt) === $raw) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function sd_upload_crm_order_proof(string $field, string $prefix = 'ORDERPROOF'): ?string
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $f = $_FILES[$field];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ((int)($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Upload bukti order gagal. Kode error: ' . (int)$f['error']);
    }
    if ((int)($f['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new Exception('Upload bukti order maksimal 5MB.');
    }
    $orig = (string)($f['name'] ?? '');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','pdf'];
    if (!in_array($ext, $allowed, true)) {
        throw new Exception('Format bukti order harus JPG, PNG, WEBP, atau PDF.');
    }

    // Hardening upload: validasi isi file, bukan hanya ekstensi.
    // Tidak mengubah workflow/status DO; hanya menolak file yang menyamar dengan ekstensi yang diizinkan.
    $tmpName = (string)($f['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new Exception('File bukti order tidak valid.');
    }
    $allowedMime = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
    ];
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$fi->file($tmpName));
        if ($mime === '' || !in_array($mime, $allowedMime[$ext] ?? [], true)) {
            throw new Exception('Isi file bukti order tidak sesuai format JPG, PNG, WEBP, atau PDF.');
        }
    }

    $dir = __DIR__ . '/../uploads/sales_do_order_proof';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new Exception('Folder upload bukti order tidak bisa dibuat.');
    }
    $safePrefix = preg_replace('/[^A-Z0-9_-]/i', '_', $prefix) ?: 'ORDERPROOF';
    $name = $safePrefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file((string)$f['tmp_name'], $dest)) {
        throw new Exception('Gagal menyimpan file bukti order.');
    }
    return 'uploads/sales_do_order_proof/' . $name;
}

function sd_update_optional_sales_do_cols(PDO $pdo, int $doId, array $values): void
{
    if ($doId <= 0 || !$values) return;
    $sets = [];
    $params = [];
    foreach ($values as $col => $val) {
        if (!sd_has_column($pdo, 'sales_do', $col)) continue;
        $sets[] = "`{$col}` = ?";
        $params[] = $val;
    }
    if (!$sets) return;
    $params[] = $doId;
    $pdo->prepare('UPDATE sales_do SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
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
// CONTEXT DO PENGGANTI DARI RETUR FINAL
// --------------------------------------------------------
$replacement_for_do_id = (int)($_GET['replacement_for'] ?? $_POST['replacement_for_do_id'] ?? 0);
$replacement_return_id = (int)($_GET['return_id'] ?? $_POST['replacement_return_id'] ?? 0);
$replacement_requested = ($replacement_for_do_id > 0 || $replacement_return_id > 0
    || strtoupper(trim((string)($_POST['creation_mode'] ?? ''))) === 'REPLACEMENT');
$replacement_context = [];
$replacement_context_error = '';
if ($replacement_for_do_id > 0) {
    if ($replacement_return_id <= 0) $replacement_return_id = sd_latest_completed_return_id($pdo, $replacement_for_do_id);
    $replacement_context = sd_replacement_context($pdo, $replacement_for_do_id, $replacement_return_id);
    if (!$replacement_context) {
        // JANGAN nol-kan ID: bila linkage salah, proses harus gagal, bukan berubah menjadi NEW SALE.
        $replacement_context_error = 'Mode DO Pengganti terdeteksi tetapi linkage retur FINAL tidak valid/tidak lengkap.';
    } else {
        $ce = strtoupper(trim((string)($replacement_context['commercial_effect'] ?? '')));
        if ($ce !== 'REPLACEMENT') {
            $replacement_context_error = 'Retur ' . (string)($replacement_context['return_code'] ?? ('ID '.$replacement_return_id)) .
                ' saat ini memiliki Pengaruh Komersial ' . ($ce !== '' ? $ce : 'BELUM DIKLASIFIKASI') .
                '. Untuk membuat DO Pengganti, ubah klasifikasi retur FINAL menjadi REPLACEMENT dari Riwayat Retur/SYS. ' .
                'Stok dan histori retur tidak perlu dibatalkan.';
            // Pertahankan ID untuk audit, tetapi blok mode replacement agar tidak memfilter produk dengan allowed-set kosong.
            $replacement_context = [];
        }
    }
} elseif ($replacement_requested) {
    $replacement_context_error = 'Mode DO Pengganti terdeteksi tetapi DO original belum terhubung.';
}

/*
 * PREFILL DO PENGGANTI DARI RETUR FINAL.
 * Jangan meminta CRM mengisi ulang customer/office/item secara manual karena
 * satu kesalahan/redirect dapat membuat DO pengganti berubah menjadi DO normal.
 * Qty item selalu berasal dari qty_return; harga/PO/lot berasal dari DO asal.
 */
$replacement_prefill_do = [];
$replacement_prefill_items = [];
if ($replacement_context && $replacement_for_do_id > 0 && $replacement_return_id > 0) {
    try {
        $stPrefDo = $pdo->prepare("SELECT * FROM sales_do WHERE id=? LIMIT 1");
        $stPrefDo->execute([$replacement_for_do_id]);
        $replacement_prefill_do = $stPrefDo->fetch(PDO::FETCH_ASSOC) ?: [];

        $stPrefIt = $pdo->prepare("
            SELECT
                di.*,
                ri.qty_return AS replacement_qty,
                ri.id AS return_item_id
            FROM sales_do_return_items ri
            JOIN sales_do_items di
              ON di.id=ri.do_item_id
             AND di.do_id=ri.do_id
            WHERE ri.return_id=?
              AND ri.do_id=?
              AND COALESCE(ri.qty_return,0)>0
            ORDER BY di.line_no ASC, di.id ASC
        ");
        $stPrefIt->execute([$replacement_return_id,$replacement_for_do_id]);
        foreach ($stPrefIt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pi) {
            $pi['qty'] = (float)($pi['replacement_qty'] ?? 0);
            // stock_at_crm akan dihitung ulang oleh UI/backend pada office pengganti.
            $pi['stock_at_crm'] = null;
            $replacement_prefill_items[] = $pi;
        }
    } catch (Throwable $e) {
        $replacement_prefill_do = [];
        $replacement_prefill_items = [];
    }
}

function sd_replacement_form_url(int $originalDoId, int $returnId): string {
    $url = $_SERVER['PHP_SELF'] ?? 'sales_do.php';
    if ($originalDoId > 0 && $returnId > 0) {
        $url .= '?replacement_for=' . $originalDoId . '&return_id=' . $returnId;
    }
    return $url;
}

function sd_same_office_for_repair(PDO $pdo, string $a, string $b): bool {
    $aa=sd_norm_office_value($a); $bb=sd_norm_office_value($b);
    if ($aa===$bb) return true;
    try { return in_array($bb, sd_office_aliases($pdo,$aa), true); }
    catch (Throwable $e) { return false; }
}


/**
 * Repair linkage DO Pengganti legacy.
 *
 * Tidak melakukan pencarian fuzzy. SYS/Admin memasukkan dua kode DO secara eksplisit.
 * Sistem hanya menyimpan linkage jika seluruh validasi berikut lolos:
 * - original dan replacement ada serta berbeda;
 * - customer + office sama;
 * - replacement bukan turunan revisi operator;
 * - replacement belum terhubung ke return/original lain;
 * - terdapat tepat satu return FINAL yang masih belum mempunyai replacement;
 * - item replacement merupakan subset valid dari item yang diretur:
 *   SKU/product harus ada di return FINAL dan qty replacement <= qty_return;
 * - replacement dibuat sesudah return selesai SCM.
 */
function sd_link_legacy_replacement(
    PDO $pdo,
    string $originalCode,
    string $replacementCode,
    string $actor,
    string $reason,
    bool $confirmed
): array {
    $originalCode=strtoupper(trim($originalCode));
    $replacementCode=strtoupper(trim($replacementCode));
    $reason=trim($reason);

    if(!$confirmed) throw new Exception('Konfirmasi repair DO Pengganti wajib dicentang.');
    if(mb_strlen($reason)<8) throw new Exception('Alasan repair wajib diisi minimal 8 karakter.');
    if($originalCode==='' || $replacementCode==='' || $originalCode===$replacementCode){
        throw new Exception('Kode DO original dan DO pengganti wajib berbeda.');
    }

    $q=$pdo->prepare("SELECT id,do_code,customers_code,office_code,created_at,status,
                            replacement_for_do_id,replacement_return_id,revision_of_do_id
                     FROM sales_do
                     WHERE UPPER(TRIM(do_code)) IN (?,?)");
    $q->execute([$originalCode,$replacementCode]);
    $map=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r){
        $map[strtoupper(trim((string)$r['do_code']))]=$r;
    }
    if(empty($map[$originalCode]) || empty($map[$replacementCode])){
        throw new Exception('DO original atau DO pengganti tidak ditemukan.');
    }

    $orig=$map[$originalCode];
    $rep=$map[$replacementCode];
    $origId=(int)$orig['id'];
    $repId=(int)$rep['id'];

    if(!sd_same_office_for_repair($pdo,(string)$orig['office_code'],(string)$rep['office_code'])){
        throw new Exception('Office original dan replacement berbeda.');
    }
    if(strtoupper(trim((string)$orig['customers_code'])) !== strtoupper(trim((string)$rep['customers_code']))){
        throw new Exception('Customer original dan replacement berbeda.');
    }
    if(!empty($rep['revision_of_do_id'])){
        throw new Exception('DO replacement yang dipilih sudah ditandai sebagai turunan revisi operator.');
    }

    $repFor=(int)($rep['replacement_for_do_id']??0);
    $repReturn=(int)($rep['replacement_return_id']??0);
    if($repFor>0 && $repFor!==$origId){
        throw new Exception('DO replacement sudah terhubung ke original lain.');
    }

    // Cari return FINAL original yang belum/masih boleh ditautkan.
    // Schema-safe: kolom linkage legacy mungkin belum ada pada instalasi lama.
    $retHasReplacementDo = sd_has_column($pdo,'sales_do_returns','replacement_do_id');
    $retHasReplacementAt = sd_has_column($pdo,'sales_do_returns','replacement_created_at');
    $retHasUpdatedAt      = sd_has_column($pdo,'sales_do_returns','updated_at');

    $retReplacementDoExpr = $retHasReplacementDo ? "replacement_do_id" : "NULL AS replacement_do_id";
    $retReplacementAtExpr = $retHasReplacementAt ? "replacement_created_at" : "NULL AS replacement_created_at";

    $retQ=$pdo->prepare("SELECT id,do_id,status,scm_completed_at,
                                {$retReplacementDoExpr},
                                {$retReplacementAtExpr}
                         FROM sales_do_returns
                         WHERE do_id=?
                           AND LOWER(TRIM(COALESCE(status,'')))='return_scm_completed'
                         ORDER BY scm_completed_at DESC,id DESC");
    $retQ->execute([$origId]);
    $finalReturns=$retQ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if(!$finalReturns) throw new Exception('Tidak ada retur FINAL SCM pada DO original.');

    $eligible=[];
    foreach($finalReturns as $fr){
        $linked=(int)($fr['replacement_do_id']??0);
        if($linked===0 || $linked===$repId) $eligible[]=$fr;
    }
    if(count($eligible)!==1){
        throw new Exception('Repair diblok: return FINAL yang eligible tidak tepat satu. Pilih/rapikan linkage return terlebih dahulu.');
    }
    $ret=$eligible[0];
    $returnId=(int)$ret['id'];

    if($repReturn>0 && $repReturn!==$returnId){
        throw new Exception('DO replacement sudah terhubung ke return lain.');
    }

    $retCompleted=strtotime((string)($ret['scm_completed_at']??'')) ?: 0;
    $repCreated=strtotime((string)($rep['created_at']??'')) ?: 0;
    if($retCompleted>0 && $repCreated>0 && $repCreated<$retCompleted){
        throw new Exception('DO replacement dibuat sebelum retur selesai SCM. Repair diblok.');
    }

    /*
     * Validasi item replacement:
     * - identitas barang = product_id bila tersedia, fallback SKU normalisasi;
     * - qty WAJIB sama persis dengan qty_return;
     * - nilai NET TIDAK menjadi blocker karena DO Pengganti adalah fulfillment,
     *   bukan order komersial kedua. Harga replacement bisa 0 / berubah akibat
     *   cara entry lama. Untuk KPI nanti nilai fulfillment memakai return_net
     *   dari DO asal, bukan subtotal child.
     */
    $rq=$pdo->prepare("
        SELECT
            COALESCE(di.product_id,0) product_id,
            UPPER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(di.sku,'')),' ',''),CHAR(9),''),CHAR(160),'')) sku_norm,
            ROUND(SUM(ri.qty_return),4) qty_sum,
            ROUND(SUM(ri.qty_return*(COALESCE(di.subtotal,0)/NULLIF(di.qty,0))),2) net_sum
        FROM sales_do_return_items ri
        JOIN sales_do_items di
          ON di.id=ri.do_item_id
         AND di.do_id=ri.do_id
        WHERE ri.return_id=?
          AND ri.do_id=?
          AND COALESCE(ri.qty_return,0)>0
        GROUP BY COALESCE(di.product_id,0),
                 UPPER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(di.sku,'')),' ',''),CHAR(9),''),CHAR(160),''))
        ORDER BY product_id,sku_norm
    ");
    $rq->execute([$returnId,$origId]);
    $retRows=$rq->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if(!$retRows) throw new Exception('Item retur FINAL tidak ditemukan.');

    $pq=$pdo->prepare("
        SELECT
            COALESCE(i.product_id,0) product_id,
            UPPER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(i.sku,'')),' ',''),CHAR(9),''),CHAR(160),'')) sku_norm,
            ROUND(SUM(i.qty),4) qty_sum,
            ROUND(SUM(COALESCE(i.subtotal,0)),2) net_sum
        FROM sales_do_items i
        WHERE i.do_id=?
        GROUP BY COALESCE(i.product_id,0),
                 UPPER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(i.sku,'')),' ',''),CHAR(9),''),CHAR(160),''))
        ORDER BY product_id,sku_norm
    ");
    $pq->execute([$repId]);
    $repRows=$pq->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $keyOf=static function(array $x): string {
        $pid=(int)($x['product_id']??0);
        $sku=(string)($x['sku_norm']??'');
        return $pid>0 ? ('P:'.$pid) : ('S:'.$sku);
    };
    $toMap=static function(array $rows) use ($keyOf): array {
        $m=[];
        foreach($rows as $x){
            $k=$keyOf($x);
            if($k==='S:') continue;
            if(!isset($m[$k])) $m[$k]=['qty'=>0.0,'net'=>0.0,'sku'=>(string)($x['sku_norm']??''),'product_id'=>(int)($x['product_id']??0)];
            $m[$k]['qty']+=(float)($x['qty_sum']??0);
            $m[$k]['net']+=(float)($x['net_sum']??0);
        }
        return $m;
    };
    $retMap=$toMap($retRows);
    $repMap=$toMap($repRows);

    if(!$retMap || !$repMap){
        throw new Exception('Item retur FINAL atau item DO replacement tidak ditemukan.');
    }

    // FULL return boleh memiliki replacement sebagian.
    // Replacement harus SUBSET dari return FINAL: item harus ada dan qty tidak boleh melebihi qty_return.
    foreach($repMap as $k=>$v){
        if(!isset($retMap[$k])){
            throw new Exception("Barang DO replacement tidak ada pada retur FINAL: {$k}.");
        }
        $rq=(float)($retMap[$k]['qty']??0);
        $pq=(float)($v['qty']??0);
        if($pq<=0){
            throw new Exception("Qty replacement tidak valid untuk {$k}.");
        }
        if($pq-$rq>0.0001){
            throw new Exception(
                "Qty replacement melebihi qty retur FINAL untuk {$k}. ".
                "Retur={$rq}, replacement={$pq}."
            );
        }
    }

    // Net hanya audit.
    $returnNetAudit=0.0; foreach($retMap as $v) $returnNetAudit+=(float)$v['net'];
    $replacementNetAudit=0.0; foreach($repMap as $v) $replacementNetAudit+=(float)$v['net'];

    // Two-way linkage in ONE transaction.
    $repCreatedAt=(string)($rep['created_at']??'');
    if($repCreatedAt==='') $repCreatedAt=date('Y-m-d H:i:s');

    if (!sd_has_column($pdo,'sales_do_returns','replacement_do_id')
        || !sd_has_column($pdo,'sales_do_returns','replacement_created_at')) {
        throw new Exception(
            'Schema sales_do_returns belum memiliki kolom linkage replacement. ' .
            'Pastikan migration replacement_do_id dan replacement_created_at berhasil.'
        );
    }

    $pdo->prepare("UPDATE sales_do
                   SET is_replacement_fulfillment=1,
                       replacement_for_do_id=?,
                       replacement_return_id=?,
                       replacement_created_at=COALESCE(replacement_created_at,?)
                   WHERE id=?")
        ->execute([$origId,$returnId,$repCreatedAt,$repId]);

    $retSet = "replacement_do_id=?,
               replacement_created_at=COALESCE(replacement_created_at,?)";
    if (sd_has_column($pdo,'sales_do_returns','updated_at')) {
        $retSet .= ", updated_at=NOW()";
    }
    $pdo->prepare("UPDATE sales_do_returns
                   SET {$retSet}
                   WHERE id=? AND do_id=?")
        ->execute([$repId,$repCreatedAt,$returnId,$origId]);

    // Verify two-way linkage.
    $chk=$pdo->prepare("SELECT
        (SELECT COUNT(*) FROM sales_do
         WHERE id=? AND replacement_for_do_id=? AND replacement_return_id=?) a,
        (SELECT COUNT(*) FROM sales_do_returns
         WHERE id=? AND do_id=? AND replacement_do_id=?) b");
    $chk->execute([$repId,$origId,$returnId,$returnId,$origId,$repId]);
    $ok=$chk->fetch(PDO::FETCH_ASSOC) ?: [];
    if((int)($ok['a']??0)!==1 || (int)($ok['b']??0)!==1){
        throw new Exception('Verifikasi two-way linkage gagal.');
    }

    return [
        'original_id'=>$origId,
        'original_code'=>$originalCode,
        'replacement_id'=>$repId,
        'replacement_code'=>$replacementCode,
        'return_id'=>$returnId,
        'replacement_created_at'=>$repCreatedAt,
        'return_net'=>$returnNetAudit,
        'replacement_net'=>$replacementNetAudit,
        'net_difference'=>$replacementNetAudit-$returnNetAudit,
        'reason'=>$reason,
    ];
}

/**
 * Explicit legacy repair. Tidak ada auto-detect.
 * Menandai DO yang terlanjur dibuat operator sebagai turunan revisi dari DO original.
 * History/workflow tetap ada; hanya lineage komersial yang diperbaiki.
 */
function sd_link_operator_revision_duplicate(PDO $pdo, string $originalCode, string $duplicateCode, string $actor, string $reason, bool $confirmed): array {
    $originalCode=strtoupper(trim($originalCode));
    $duplicateCode=strtoupper(trim($duplicateCode));
    if ($originalCode==='' || $duplicateCode==='' || $originalCode===$duplicateCode) {
        throw new Exception('Kode DO original dan DO salah/duplikat wajib berbeda.');
    }
    if (!$confirmed) {
        throw new Exception('Konfirmasi koreksi operator wajib dicentang.');
    }
    $reason=trim($reason);
    if (mb_strlen($reason) < 8) {
        throw new Exception('Alasan koreksi wajib diisi jelas minimal 8 karakter.');
    }

    // Kedua kode harus masih satu keluarga transaksi/tanggal/office.
    // Contoh valid: BMHP-BGR-260902-010 -> BMHP-BGR-260902-011.
    // Ini bukan auto-detect; hanya safety guard atas dua kode yang dimasukkan SYS.
    $familyOf = static function(string $code): string {
        return preg_replace('/-\d{3,}$/', '', strtoupper(trim($code))) ?: '';
    };
    if ($familyOf($originalCode)==='' || $familyOf($originalCode)!==$familyOf($duplicateCode)) {
        throw new Exception('Koreksi ditolak: kedua DO bukan satu keluarga kode/tanggal/office yang sama.');
    }

    $q=$pdo->prepare("SELECT id,do_code,customers_code,office_code,created_at,status,
                            replacement_for_do_id,replacement_return_id,revision_of_do_id
                     FROM sales_do
                     WHERE UPPER(TRIM(do_code)) IN (?,?)");
    $q->execute([$originalCode,$duplicateCode]);
    $m=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $m[strtoupper(trim((string)$r['do_code']))]=$r;
    }
    if (empty($m[$originalCode]) || empty($m[$duplicateCode])) {
        throw new Exception('DO original atau DO duplikat tidak ditemukan.');
    }
    $o=$m[$originalCode]; $d=$m[$duplicateCode];
    $oid=(int)$o['id']; $did=(int)$d['id'];

    if (!sd_same_office_for_repair($pdo,(string)$o['office_code'],(string)$d['office_code'])) {
        throw new Exception('Office DO original dan DO duplikat berbeda.');
    }
    if (strtoupper(trim((string)$o['customers_code'])) !== strtoupper(trim((string)$d['customers_code']))) {
        throw new Exception('Customer DO original dan DO duplikat berbeda.');
    }
    if (!empty($d['replacement_for_do_id']) || !empty($d['replacement_return_id'])) {
        throw new Exception('DO duplikat sudah merupakan DO Pengganti Retur; jangan ubah menjadi revisi.');
    }
    if (!empty($d['revision_of_do_id']) && (int)$d['revision_of_do_id'] !== $oid) {
        throw new Exception('DO duplikat sudah terhubung ke original lain.');
    }
    $ot=strtotime((string)($o['created_at'] ?? '')) ?: 0;
    $dt=strtotime((string)($d['created_at'] ?? '')) ?: 0;
    if ($ot>0 && $dt>0 && $dt<$ot) throw new Exception('DO duplikat dibuat sebelum DO original.');

    /*
     * Evidence revisi historis hanya DIAGNOSTIK, bukan syarat.
     * Alasan: kasus yang sedang diperbaiki justru terjadi karena operator TIDAK
     * memakai jalur Revisi. Bila evidence diwajibkan, repair tidak pernah bisa
     * memperbaiki kesalahan tersebut.
     *
     * Safety pengganti:
     * - hanya SYS/Admin;
     * - dua kode dimasukkan eksplisit;
     * - kode satu family/tanggal/office;
     * - customer sama;
     * - office sama;
     * - duplicate dibuat setelah original;
     * - duplicate bukan replacement retur;
     * - wajib checkbox + alasan koreksi;
     * - semua dicatat ke audit/kolom linkage.
     */
    $evidence=false;
    if (sd_has_table($pdo,'sales_do_revisions')) {
        try {
            $x=$pdo->prepare("SELECT COUNT(*) FROM sales_do_revisions WHERE do_id=?");
            $x->execute([$oid]); $evidence=((int)$x->fetchColumn())>0;
        } catch (Throwable $e) {}
    }
    if (!$evidence && sd_has_table($pdo,'sales_do_audit')) {
        try {
            $cols=sd_table_columns($pdo,'sales_do_audit');
            $parts=[];
            if (in_array('status_to',$cols,true)) $parts[]="LOWER(COALESCE(status_to,''))='revision_requested'";
            if (in_array('action',$cols,true)) $parts[]="UPPER(COALESCE(action,'')) LIKE '%REVISION%'";
            if (in_array('action_code',$cols,true)) $parts[]="UPPER(COALESCE(action_code,'')) LIKE '%REVISION%'";
            if ($parts) {
                $x=$pdo->prepare("SELECT COUNT(*) FROM sales_do_audit WHERE do_id=? AND (".implode(' OR ',$parts).")");
                $x->execute([$oid]); $evidence=((int)$x->fetchColumn())>0;
            }
        } catch (Throwable $e) {}
    }
    if (!$evidence && strtolower(trim((string)$o['status']))==='revision_requested') $evidence=true;

    $sets=["revision_of_do_id=?","revision_linked_at=NOW()","revision_linked_by=?"];
    $params=[$oid,$actor];
    if (sd_has_column($pdo,'sales_do','revision_reason')) {
        $sets[]="revision_reason=?";
        $params[]='OPERATOR ERROR REPAIR: '.$reason;
    }
    $params[]=$did;
    $pdo->prepare("UPDATE sales_do SET ".implode(',',$sets)." WHERE id=?")->execute($params);

    return [
        'original_id'=>$oid,
        'original_code'=>$originalCode,
        'duplicate_id'=>$did,
        'duplicate_code'=>$duplicateCode,
        'reason'=>$reason,
        'historical_revision_evidence'=>$evidence ? 1 : 0,
    ];
}

// --------------------------------------------------------
// SYS REPAIR: DO Pengganti legacy belum mempunyai two-way linkage
// --------------------------------------------------------
if (!$LOCK_BLOCK_POST && empty($sd_schema_errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repair_legacy_replacement'])) {
    require_post();
    verify_csrf();

    $u=function_exists('auth_user') ? auth_user() : [];
    $role=strtoupper(trim((string)($u['role']??'')));
    $level=strtoupper(trim((string)($u['level']??'')));
    $dept=strtoupper(trim((string)($u['department']??'')));
    $allowed=in_array($role,['SYS','ADMIN','SUPERADMIN'],true) || $level==='SYS' || $dept==='SYS';

    if(!$allowed){
        set_flash_do('danger','Repair linkage DO Pengganti hanya boleh dilakukan SYS/Admin.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }

    try{
        if(!$pdo->inTransaction()) $pdo->beginTransaction();
        $actor=function_exists('get_actor_name_crm') ? get_actor_name_crm() : (string)($u['username']??'SYS');

        $fixed=sd_link_legacy_replacement(
            $pdo,
            (string)($_POST['repair_replacement_original_code']??''),
            (string)($_POST['repair_replacement_child_code']??''),
            $actor,
            (string)($_POST['repair_replacement_reason']??''),
            !empty($_POST['repair_replacement_confirm'])
        );

        if($pdo->inTransaction()) $pdo->commit();

        try{
            if(function_exists('master_audit')){
                master_audit(
                    $pdo,'sales_do','sales_do','LINK_LEGACY_REPLACEMENT',
                    (int)$fixed['replacement_id'],(string)$fixed['replacement_code'],
                    'Legacy replacement repair: '.$fixed['replacement_code'].' -> '.$fixed['original_code'].' / return '.$fixed['return_id'],
                    $fixed
                );
            }
        }catch(Throwable $e){}

        set_flash_do(
            'success',
            'Link DO Pengganti berhasil: '.$fixed['replacement_code'].
            ' terhubung ke '.$fixed['original_code'].
            ' / Return ID '.$fixed['return_id'].
            ' Qty/barang tervalidasi. Nilai retur '.number_format((float)$fixed['return_net'],0,',','.').
            ', nilai child '.number_format((float)$fixed['replacement_net'],0,',','.').
            '. KPI memakai nilai retur original sebagai fulfillment. Refresh Executive Summary.'
        );
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        set_flash_do('danger','Repair DO Pengganti gagal: '.h($e->getMessage()));
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}

// --------------------------------------------------------
// SYS REPAIR: operator membuat DO baru saat seharusnya EDIT/REVISI
// --------------------------------------------------------
if (!$LOCK_BLOCK_POST && empty($sd_schema_errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repair_revision_duplicate'])) {
    require_post();
    verify_csrf();
    $u=function_exists('auth_user') ? auth_user() : [];
    $role=strtoupper(trim((string)($u['role']??'')));
    $level=strtoupper(trim((string)($u['level']??'')));
    $dept=strtoupper(trim((string)($u['department']??'')));
    $allowed=in_array($role,['SYS','ADMIN','SUPERADMIN'],true) || $level==='SYS' || $dept==='SYS';
    if (!$allowed) {
        set_flash_do('danger','Koreksi lineage revisi hanya boleh dilakukan SYS/Admin.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }
    try {
        if (!$pdo->inTransaction()) $pdo->beginTransaction();
        $actor=function_exists('get_actor_name_crm') ? get_actor_name_crm() : (string)($u['username']??'SYS');
        $fixed=sd_link_operator_revision_duplicate(
            $pdo,
            (string)($_POST['repair_original_do_code']??''),
            (string)($_POST['repair_duplicate_do_code']??''),
            $actor,
            (string)($_POST['repair_reason']??''),
            !empty($_POST['repair_confirm'])
        );
        if ($pdo->inTransaction()) $pdo->commit();
        try {
            if (function_exists('master_audit')) {
                master_audit($pdo,'sales_do','sales_do','LINK_REVISION_DUPLICATE',
                    (int)$fixed['duplicate_id'],(string)$fixed['duplicate_code'],
                    'Operator repair: '.$fixed['duplicate_code'].' linked as revision child of '.$fixed['original_code'],
                    [
                        'original_do_id'=>$fixed['original_id'],
                        'original_do_code'=>$fixed['original_code'],
                        'reason'=>$fixed['reason'] ?? '',
                        'historical_revision_evidence'=>$fixed['historical_revision_evidence'] ?? 0,
                    ]);
            }
        } catch (Throwable $e) {}
        set_flash_do('success','Koreksi berhasil: '.$fixed['duplicate_code'].' ditandai sebagai turunan revisi dari '.$fixed['original_code'].'. History tetap ada, nilai child menjadi Rp0 pada achievement. Silakan refresh Executive Summary.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash_do('danger','Koreksi operator gagal: '.h($e->getMessage()));
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
    $creation_mode   = strtoupper(trim((string)($_POST['creation_mode'] ?? ($replacement_requested ? 'REPLACEMENT' : 'NEW_SALE'))));
    if (!in_array($creation_mode,['NEW_SALE','REPLACEMENT'],true)) $creation_mode='NEW_SALE';
    if (!$is_update && !$perm_sales_create) {
        set_flash_do('danger', 'Tidak ada izin CREATE untuk membuat DO.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }
    if ($is_update && !$perm_sales_edit) {
        set_flash_do('danger', 'Tidak ada izin EDIT untuk mengubah DO.');
        rmi_redirect($_SERVER['PHP_SELF'] . '?edit=' . $do_id_post);
    }

    // FINAL GUARD: DO yang sudah masuk SCM/ACT/FIN/PAID tidak boleh diedit langsung.
    // Revisi pra-kirim wajib EDIT DO yang sama. Setelah barang dikirim, gunakan Retur + DO Pengganti ter-link; jangan membuat NEW SALE.
    // Ini menjaga stok, audit, pencapaian, dan piutang tidak berubah diam-diam.
    if ($is_update) {
        try {
            $stLock = $pdo->prepare("SELECT status, do_code, return_status FROM sales_do WHERE id = ? LIMIT 1");
            $stLock->execute([$do_id_post]);
            $lockRow = $stLock->fetch(PDO::FETCH_ASSOC) ?: [];
            $curStatus = strtolower(trim((string)($lockRow['status'] ?? '')));
            $curReturn = strtolower(trim((string)($lockRow['return_status'] ?? '')));
            if ($curStatus !== '' && !in_array($curStatus, $CRM_ALLOWED_EDIT_STATUSES, true)) {
                set_flash_do('danger', 'DO ' . h((string)($lockRow['do_code'] ?? '')) . ' sudah masuk alur ' . strtoupper($curStatus) . '. Jika barang sudah masuk pengiriman/FIN, gunakan Ajukan Retur lalu tombol DO Pengganti. Jangan membuat Sales DO baru.');
                rmi_redirect($_SERVER['PHP_SELF']);
            }
            if ($curReturn !== '' && !in_array($curReturn, ['cancelled'], true)) {
                set_flash_do('danger', 'DO ini memiliki proses retur aktif/selesai. Tidak boleh edit langsung; gunakan riwayat retur dan buat DO pengganti bila barang salah.');
                rmi_redirect($_SERVER['PHP_SELF']);
            }
        } catch (Throwable $e) {
            set_flash_do('danger', 'Gagal memvalidasi status DO sebelum edit: ' . h($e->getMessage()));
            rmi_redirect($_SERVER['PHP_SELF']);
        }
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
    // Simpan selalu dengan office_code canonical dari master_office.
    $office_code = rmi_office_canonical($pdo, $office_code);
    // Satu DO = satu kelompok bisnis, BUKAN satu kategori item.
    // UNIT ACC boleh berisi ALKES + AKSESORIS sekaligus.
    $do_business_group_posted = strtoupper(trim((string)($_POST['do_business_group'] ?? 'BMHP')));
    if (!in_array($do_business_group_posted, ['BMHP','UNIT_ACC'], true)) $do_business_group_posted = 'BMHP';
    $do_business_group = $do_business_group_posted;
    $business_group_lock_error = '';

    // IDENTITAS DOKUMEN DIKUNCI SETELAH DO DIBUAT.
    // Prefix do_code dibuat dari business group; karena itu revisi DO yang sama tidak boleh
    // mengubah BMHP <-> UNIT_ACC. Jika kelompok awal salah, gunakan koreksi/cancel + dokumen baru
    // sesuai prosedur, bukan mengganti identitas dokumen secara diam-diam.
    if ($is_update && $do_id_post > 0) {
        try {
            $colsDoBg = sd_table_columns($pdo, 'sales_do');
            $bgExpr = in_array('business_group', $colsDoBg, true)
                ? "UPPER(TRIM(COALESCE(NULLIF(business_group,''), CASE WHEN UPPER(TRIM(COALESCE(category,'')))='UNIT_ACC' THEN 'UNIT_ACC' ELSE 'BMHP' END)))"
                : "CASE WHEN UPPER(TRIM(COALESCE(category,'')))='UNIT_ACC' THEN 'UNIT_ACC' ELSE 'BMHP' END";
            $stBgLock = $pdo->prepare("SELECT {$bgExpr} AS business_group FROM sales_do WHERE id=? LIMIT 1");
            $stBgLock->execute([$do_id_post]);
            $existingBg = strtoupper(trim((string)($stBgLock->fetchColumn() ?: 'BMHP')));
            if (!in_array($existingBg, ['BMHP','UNIT_ACC'], true)) $existingBg = 'BMHP';
            if ($do_business_group_posted !== $existingBg) {
                $business_group_lock_error = 'Kelompok DO tidak boleh diubah saat revisi (' . $existingBg . ' -> ' . $do_business_group_posted . '). Nomor/prefix DO dan histori harus tetap konsisten.';
            }
            $do_business_group = $existingBg;
        } catch (Throwable $eBgLock) {
            $business_group_lock_error = 'Gagal membaca kelompok bisnis DO lama. Revisi diblokir agar identitas dokumen tidak berubah.';
        }
    }

    // DO Pengganti adalah kelanjutan operasional dari transaksi original, bukan penjualan baru.
    // Kelompok bisnis wajib tetap sama dengan DO original. Di dalam UNIT_ACC, kategori item
    // tetap boleh berubah/campur ALKES <-> AKSESORIS sesuai barang pengganti aktual.
    if (!$is_update && $creation_mode === 'REPLACEMENT' && !empty($replacement_prefill_do)) {
        $replacementBg = strtoupper(trim((string)($replacement_prefill_do['business_group'] ?? '')));
        if (!in_array($replacementBg, ['BMHP','UNIT_ACC'], true)) {
            $replacementLegacyCat = strtoupper(trim((string)($replacement_prefill_do['category'] ?? 'BMHP')));
            $replacementBg = in_array($replacementLegacyCat, ['ALKES','AKSESORIS','UNIT_ACC'], true) ? 'UNIT_ACC' : 'BMHP';
        }
        if ($do_business_group_posted !== $replacementBg) {
            $business_group_lock_error = 'DO Pengganti wajib memakai kelompok bisnis yang sama dengan DO original (' . $replacementBg . ').';
        }
        $do_business_group = $replacementBg;
    }

    // Header category dipakai untuk kompatibilitas dokumen; kategori asli tetap di level item.
    $do_category = ($do_business_group === 'UNIT_ACC') ? 'UNIT_ACC' : 'BMHP';


    $do_date_raw      = trim($_POST['do_date'] ?? '');
    $customer_pic     = trim($_POST['customer_pic'] ?? '');
    $customer_phone   = trim($_POST['customer_phone'] ?? '');
    $shipping_address = trim($_POST['shipping_address'] ?? '');
    $note             = trim($_POST['note'] ?? '');
    $tax_code_post    = trim($_POST['tax_code'] ?? '');
    $include_tax      = isset($_POST['include_tax']) ? 1 : 0;

    // CRM order time manual: jam order asli dari RS/customer sebagai dasar KPI CRM.
    $crm_order_received_at = sd_datetime_local_to_mysql($_POST['crm_order_received_at'] ?? '');
    $crm_order_source = strtoupper(trim((string)($_POST['crm_order_source'] ?? '')));
    $allowedCrmOrderSources = ['WA','EMAIL','TELEPON','MANUAL','LAINNYA'];
    if ($crm_order_source !== '' && !in_array($crm_order_source, $allowedCrmOrderSources, true)) {
        $crm_order_source = 'MANUAL';
    }
    $crm_order_note = trim((string)($_POST['crm_order_note'] ?? ''));
    $existing_crm_order_proof_file = trim((string)($_POST['existing_crm_order_proof_file'] ?? ''));
    $crm_order_proof_file = $existing_crm_order_proof_file !== '' ? $existing_crm_order_proof_file : null;

    // CRM timer
    $crm_start_ts = isset($_POST['crm_start_ts']) ? (int)$_POST['crm_start_ts'] : null;
    $now_ts       = time();

    $errors = [];
    if ($business_group_lock_error !== '') $errors[] = $business_group_lock_error;

    if (!$is_update && $replacement_requested) {
        if ($creation_mode !== 'REPLACEMENT') {
            $errors[]='Alur berasal dari DO Pengganti tetapi mode form berubah menjadi NEW SALE. Proses diblok.';
        }
        if ($replacement_context_error!=='' || !$replacement_context) {
            $errors[]=$replacement_context_error!=='' ? $replacement_context_error : 'Link DO Pengganti tidak valid.';
        }
    }
    if (!$is_update && $creation_mode==='REPLACEMENT' && !$replacement_requested) {
        $errors[]='DO Pengganti wajib dimulai dari Retur Selesai SCM.';
    }

    if ($customers_code === '') $errors[] = 'Customer wajib dipilih.';
    if ($office_code === '')    $errors[] = 'Office penanggung jawab wajib dipilih.';
    if ($do_date_raw === '')    $errors[] = 'Tanggal DO wajib diisi.';

    // Unit ACC tidak memakai office khusus. Office mengikuti lokasi stok normal
    // (BGR/BDG/BKS/TGR/dst). Pemisah transaksi adalah business_group; kategori ALKES/AKSESORIS
    // tersimpan per item dan keduanya boleh berada dalam satu DO UNIT_ACC.
    if (!$is_update && !$crm_order_received_at) $errors[] = 'Jam order customer/RS wajib diisi sebagai dasar KPI SLA CRM.';
    if ($crm_order_received_at && strtotime($crm_order_received_at) > (time() + 300)) {
        $errors[] = 'Jam order customer/RS tidak boleh melebihi waktu sekarang.';
    }

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

    // Untuk REVISI DO: stok lama sudah pernah dikurangi.
    // Saat validasi qty baru, tambahkan kembali qty lama sebagai "reserved stock"
    // jika office lama sama dengan office baru, agar revisi tidak salah dianggap stok kurang.
    $old_do_office_for_stock = '';
    $old_qty_by_sku_for_stock = [];
    if ($is_update && $do_id_post > 0) {
        try {
            $stOldStock = $pdo->prepare("
                SELECT UPPER(TRIM(d.office_code)) AS office_code,
                       " . sd_norm_sku_sql('i.sku') . " AS sku_norm,
                       SUM(i.qty) AS qty
                FROM sales_do d
                JOIN sales_do_items i ON i.do_id = d.id
                WHERE d.id = ?
                GROUP BY UPPER(TRIM(d.office_code)), " . sd_norm_sku_sql('i.sku') . "
            ");
            $stOldStock->execute([$do_id_post]);
            foreach ($stOldStock->fetchAll(PDO::FETCH_ASSOC) as $orow) {
                $old_do_office_for_stock = strtoupper(trim((string)($orow['office_code'] ?? $old_do_office_for_stock)));
                $skn = strtoupper(trim((string)($orow['sku_norm'] ?? '')));
                if ($skn !== '') {
                    $old_qty_by_sku_for_stock[$skn] = ($old_qty_by_sku_for_stock[$skn] ?? 0) + (float)($orow['qty'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $old_do_office_for_stock = '';
            $old_qty_by_sku_for_stock = [];
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
            $no_po_raw  = trim($row['no_po'] ?? '');
            $replacement_return_item_id = (int)($row['replacement_return_item_id'] ?? 0);

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
    // Validasi stok berdasarkan SKU di office agar SKU yang punya beberapa lot/product_id tetap dihitung total.
    $officeAliasesForStock = sd_office_aliases($pdo, $office_code);
    $stockParams = [sd_norm_sku_value($sku)];
    $officeWhereStock = sd_office_alias_where('s.office_code', $officeAliasesForStock, $stockParams);

    $stStock = $pdo->prepare("
        SELECT COALESCE(SUM(s.stock_qty), 0)
        FROM wqs_stock_by_office s
        JOIN master_products mp ON mp.id = s.product_id
        WHERE " . sd_norm_sku_sql('mp.sku') . " = ?
          AND {$officeWhereStock}
    ");
    $stStock->execute($stockParams);
    $officeStock = (float)($stStock->fetchColumn() ?: 0);
} catch (Throwable $e) {
    try {
        $officeAliasesForStock = sd_office_aliases($pdo, $office_code);
        $stockParams = [$product_id];
        $officeWhereStock = sd_office_alias_where('office_code', $officeAliasesForStock, $stockParams);

        $stStock = $pdo->prepare("
            SELECT COALESCE(SUM(stock_qty), 0)
            FROM wqs_stock_by_office
            WHERE product_id = ?
              AND {$officeWhereStock}
        ");
        $stStock->execute($stockParams);
        $officeStock = (float)($stStock->fetchColumn() ?: 0);
    } catch (Throwable $e2) {
        $officeStock = 0;
    }
}

// Jika sedang revisi DO dan office tidak berubah, qty lama dianggap tersedia lagi
// karena nanti sebelum simpan item baru stok lama akan dikembalikan dulu.
if ($is_update && $old_do_office_for_stock !== '' && rmi_office_same($pdo, (string)$office_code, (string)$old_do_office_for_stock)) {
    $oldSkuNorm = sd_norm_sku_value((string)$sku);
    if ($oldSkuNorm !== '' && isset($old_qty_by_sku_for_stock[$oldSkuNorm])) {
        $officeStock += (float)$old_qty_by_sku_for_stock[$oldSkuNorm];
    }
}

// Serial/Lot sekarang otomatis FIFO dari CRM, jadi validasi cukup total stok SKU per office.
// Pengurangan stok final tetap dilakukan FIFO di sd_reduce_stock_by_do_id_lot_aware().
$stock_crm = $officeStock;

if ($qty > $officeStock) {
    $lotMsg = $serial_raw !== '' ? ' lot ' . htmlspecialchars($serial_raw, ENT_QUOTES, 'UTF-8') : '';
    $errors[] = 'Qty item melebihi stok office ' . htmlspecialchars($office_code, ENT_QUOTES, 'UTF-8') .
        ' untuk SKU ' . htmlspecialchars((string)$sku, ENT_QUOTES, 'UTF-8') . $lotMsg .
        '. Stok tersedia: ' . number_format($officeStock, 0, ',', '.');
    continue;
}
            $lineSubtotal = $qty * $netAfterDisc;

            // Denormalisasi category + business_group dari master agar reporting historis stabil.
            $itemCategory = $do_category;
            $itemBusinessGroup = 'BMHP';
            try {
                $stCat = $pdo->prepare("SELECT category, COALESCE(NULLIF(UPPER(TRIM(business_group)),''),'BMHP') AS business_group FROM master_products WHERE id=? LIMIT 1");
                $stCat->execute([$product_id]);
                $catRow = $stCat->fetch(PDO::FETCH_ASSOC) ?: [];
                if (!empty($catRow['category'])) $itemCategory = strtoupper(trim((string)$catRow['category']));
                if (!empty($catRow['business_group'])) $itemBusinessGroup = strtoupper(trim((string)$catRow['business_group']));
            } catch (Throwable $e) {}

            if (!in_array($itemCategory, ['BMHP','ALKES','AKSESORIS'], true)) {
                $errors[] = 'Produk ' . htmlspecialchars((string)$sku, ENT_QUOTES, 'UTF-8') . ' masih memakai category legacy. Reklasifikasi di Master Product menjadi BMHP / ALKES / AKSESORIS terlebih dahulu.';
                continue;
            }
            if (!in_array($itemBusinessGroup, ['BMHP','UNIT_ACC'], true)) $itemBusinessGroup = 'BMHP';
            if ($itemBusinessGroup === 'UNIT_ACC' && !in_array($itemCategory, ['ALKES','AKSESORIS'], true)) {
                $errors[] = 'Produk Unit ACC ' . htmlspecialchars((string)$sku, ENT_QUOTES, 'UTF-8') . ' wajib berkategori ALKES atau AKSESORIS.';
                continue;
            }

            // Guard kelompok bisnis: BMHP tidak boleh bercampur dengan UNIT ACC dalam satu DO.
            // Namun di dalam UNIT ACC, ALKES dan AKSESORIS BOLEH bercampur.
            if ($do_business_group === 'BMHP') {
                if ($itemBusinessGroup !== 'BMHP' || $itemCategory !== 'BMHP') {
                    $errors[] = 'DO BMHP hanya boleh berisi produk business_group BMHP + category BMHP. SKU ' . htmlspecialchars((string)$sku, ENT_QUOTES, 'UTF-8') . ' tidak sesuai.';
                    continue;
                }
            } else {
                if ($itemBusinessGroup !== 'UNIT_ACC' || !in_array($itemCategory, ['ALKES','AKSESORIS'], true)) {
                    $errors[] = 'DO UNIT ACC hanya boleh berisi produk business_group UNIT_ACC dengan kategori ALKES atau AKSESORIS. SKU ' . htmlspecialchars((string)$sku, ENT_QUOTES, 'UTF-8') . ' tidak sesuai.';
                    continue;
                }
            }

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
                'no_po'              => $no_po_raw !== '' ? $no_po_raw : null,
                'category'           => $itemCategory,
                'business_group'     => $itemBusinessGroup,
                'replacement_return_item_id' => $replacement_return_item_id,
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

    // DO pengganti hanya boleh berisi SKU/qty yang memang diretur final.
    if (!$is_update && $creation_mode === 'REPLACEMENT' && $replacement_for_do_id > 0 && $replacement_return_id > 0) {
        if (!$replacement_context) {
            $errors[] = 'Link retur untuk DO pengganti tidak valid atau retur belum selesai SCM.';
        } else {
            try {
                // Linkage return -> replacement bersifat satu-ke-satu.
                // Pengecekan DO pengganti aktif dilakukan terpusat di sd_validate_replacement_items().
                $ctxOffice = (string)($replacement_context['office_code'] ?? $replacement_context['return_office'] ?? '');
                $ctxCustomer = (string)($replacement_context['customers_code'] ?? $replacement_context['return_customer'] ?? '');
                if ($ctxOffice !== '' && !rmi_office_same($pdo, $office_code, $ctxOffice)) {
                    $errors[] = 'Office DO pengganti harus sama dengan DO asal retur.';
                }
                if ($ctxCustomer !== '' && strtoupper(trim($customers_code)) !== strtoupper(trim($ctxCustomer))) {
                    $errors[] = 'Customer DO pengganti harus sama dengan DO asal retur.';
                }
                sd_validate_replacement_items($pdo, $replacement_return_id, $validItems);
            } catch (Throwable $eRep) {
                $errors[] = $eRep->getMessage();
            }
        }
    }

    // Upload bukti order customer/RS bersifat opsional, tetapi jika diupload harus valid.
    try {
        $uploadedProof = sd_upload_crm_order_proof('crm_order_proof_file', 'ORDERPROOF');
        if ($uploadedProof) $crm_order_proof_file = $uploadedProof;
    } catch (Throwable $eUploadProof) {
        $errors[] = $eUploadProof->getMessage();
    }

    // Status DO: sekarang HANYA ke WQS (tidak ada Draft manual)
    $status = 'crm_to_wqs';

    // CRM timer / SLA CRM.
    // CREATE: durasi = jam DO selesai dibuat - jam order asli dari RS/customer.
    // UPDATE: jangan memakai waktu edit sebagai finish karena akan memperbesar KPI secara palsu.
    //         Pertahankan finish lama; bila data legacy belum punya durasi, backfill memakai
    //         crm_finish_at -> crm_created_at -> created_at sebagai anchor waktu penyelesaian DO.
    $crm_start_at     = null;
    $crm_finish_at    = null;
    $crm_duration_sec = null;

    if (!$is_update) {
        if ($crm_order_received_at) {
            $crm_start_at     = $crm_order_received_at;
            $crm_finish_at    = date('Y-m-d H:i:s', $now_ts);
            $crm_duration_sec = max(0, $now_ts - (int)strtotime($crm_order_received_at));
        } elseif ($crm_start_ts) {
            $crm_start_at     = date('Y-m-d H:i:s', $crm_start_ts);
            $crm_finish_at    = date('Y-m-d H:i:s', $now_ts);
            $crm_duration_sec = max(0, $now_ts - $crm_start_ts);
        } else {
            $crm_start_at     = date('Y-m-d H:i:s', $now_ts);
            $crm_finish_at    = $crm_start_at;
            $crm_duration_sec = 0;
        }
    } else {
        // Ambil timer lama sebelum update. Ini hanya membaca data dan tidak mengubah flow/status/stok.
        $oldTimer = [];
        try {
            $stTimer = $pdo->prepare("SELECT crm_order_received_at, crm_start_at, crm_finish_at, crm_duration_sec, crm_created_at, created_at FROM sales_do WHERE id = ? LIMIT 1");
            $stTimer->execute([$do_id_post]);
            $oldTimer = $stTimer->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $eTimer) {
            $oldTimer = [];
        }

        $oldFinish = trim((string)($oldTimer['crm_finish_at'] ?? ''));
        if ($oldFinish === '') $oldFinish = trim((string)($oldTimer['crm_created_at'] ?? ''));
        if ($oldFinish === '') $oldFinish = trim((string)($oldTimer['created_at'] ?? ''));

        // Jika finish lama valid, itulah titik akhir SLA yang benar. Jika benar-benar tidak ada,
        // gunakan waktu sekarang hanya sebagai fallback legacy terakhir.
        $finishTs = $oldFinish !== '' ? strtotime($oldFinish) : false;
        if (!$finishTs) $finishTs = $now_ts;

        if ($crm_order_received_at) {
            $startTs = strtotime($crm_order_received_at);
            $crm_start_at = $crm_order_received_at;
            $crm_finish_at = date('Y-m-d H:i:s', (int)$finishTs);
            $crm_duration_sec = $startTs ? max(0, (int)$finishTs - (int)$startTs) : null;
        } else {
            // Jika user tidak mengubah/mengisi jam order, pertahankan timer lama apa adanya.
            $crm_start_at = trim((string)($oldTimer['crm_start_at'] ?? '')) ?: null;
            $crm_finish_at = trim((string)($oldTimer['crm_finish_at'] ?? '')) ?: null;
            $oldDur = $oldTimer['crm_duration_sec'] ?? null;
            $crm_duration_sec = ($oldDur === null || $oldDur === '') ? null : max(0, (int)$oldDur);
        }
    }

    if (!empty($errors)) {
        set_flash_do('danger', implode('<br>', $errors));
        if ($is_update) {
            rmi_redirect($_SERVER['PHP_SELF'] . '?edit=' . $do_id_post);
        }
        // KRITIS: jangan hilangkan mode replacement setelah validation error.
        // Sebelumnya redirect ke sales_do.php polos membuat hidden linkage hilang pada retry,
        // sehingga DO yang seharusnya replacement dapat tersimpan sebagai DO normal.
        rmi_redirect(sd_replacement_form_url($replacement_for_do_id, $replacement_return_id));
    }

    try {
        // Header DO, item, dan perubahan stok wajib atomik untuk CREATE maupun UPDATE.
        // Audit eksternal tetap dijalankan setelah COMMIT agar tidak mengganggu transaksi utama.
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        if ($is_update) {
            // Ambil status lama untuk audit (sebelum update)
            $oldStatus = '';
            $oldGrandTotalForRevision = 0.0;
            try {
                $stOld = $pdo->prepare("SELECT status, grand_total FROM sales_do WHERE id = :id LIMIT 1");
                $stOld->execute([':id' => $do_id_post]);
                $oldDoRevisionRow = $stOld->fetch(PDO::FETCH_ASSOC) ?: [];
                $oldStatus = (string)($oldDoRevisionRow['status'] ?? '');
                $oldGrandTotalForRevision = (float)($oldDoRevisionRow['grand_total'] ?? 0);
            } catch (Throwable $e) {
                $oldStatus = '';
                $oldGrandTotalForRevision = 0.0;
            }

            $newStatusAfterRevisionEdit = $oldStatus;
            if ($oldStatus === 'revision_requested') {
                // Revisi pra-kirim selalu kembali ke WQS untuk pemeriksaan ulang.
                // Review ACT/FIN tidak boleh menahan alur stok/operasional sebelum modul review khusus tersedia.
                $newStatusAfterRevisionEdit = 'crm_to_wqs';
            }

            // REVISI DO:
            // 1) Kembalikan dulu stok lama ke office lama berdasarkan item lama.
            //    Fungsi ini aman karena hanya restore jika stock_reduced_at sudah terisi.
            // 2) Setelah item baru tersimpan, stok akan dikurangi ulang sesuai qty/office terbaru.
            sd_restore_stock_by_do_id_for_delete($pdo, (int)$do_id_post);

            // Jika ini revisi dari SCM, reset siklus bukti/status WQS dan SCM.
            // Bukti lama tetap disimpan di tabel arsip, tetapi tidak dapat dipakai ulang.
            if ($oldStatus === 'revision_requested') {
                sd_reset_revision_workflow($pdo, (int)$do_id_post, get_actor_name_crm());
            }

            if (sd_has_column($pdo, 'sales_do', 'stock_reduced_at')) {
                try {
                    $clrStock = $pdo->prepare("UPDATE sales_do SET stock_reduced_at = NULL WHERE id = ?");
                    $clrStock->execute([$do_id_post]);
                } catch (Throwable $eClrStock) {}
            }

            // UPDATE HEADER & ITEMS (revisi_requested kembali menjadi crm_to_wqs)
            $stmt = $pdo->prepare("
                UPDATE sales_do
                SET
                    status               = :status,
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
                    is_price_include_tax = :include_tax,
                    category             = :category,
                    is_internal_transfer  = :is_internal_transfer,
                    is_replacement_fulfillment = :is_replacement_fulfillment
                WHERE id = :id
            ");
            $stmt->execute([
                ':status'             => $newStatusAfterRevisionEdit,
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
                ':category'             => $do_category,
                ':is_internal_transfer' => sd_customer_is_internal_transfer($pdo,$customers_code) ? 1 : 0,
                ':is_replacement_fulfillment' => ($creation_mode==='REPLACEMENT' ? 1 : 0),
                ':id'                   => $do_id_post,
            ]);

            // Simpan metadata order RS/customer hanya pada kolom yang tersedia, tanpa mengubah alur stok.
            $crmActor = get_actor_name_crm();
            sd_update_optional_sales_do_cols($pdo, (int)$do_id_post, [
                'crm_order_received_at' => $crm_order_received_at,
                'crm_order_source' => $crm_order_source,
                'crm_order_proof_file' => $crm_order_proof_file,
                'crm_order_note' => $crm_order_note,
                'crm_created_by' => $crmActor,
                'sales_emp_code' => $crmActor,
                'business_group' => $do_business_group,
                // FIX KPI CRM: update/backfill timer saat Jam Order Customer/RS dilengkapi pada DO lama.
                'crm_start_at' => $crm_start_at,
                'crm_finish_at' => $crm_finish_at,
                'crm_duration_sec' => $crm_duration_sec,
            ]);

            // AUTO AUDIT (CRM): EDIT DO (status tidak berubah)
            $actor_name = get_actor_name_crm();
            $stForAudit = $oldStatus !== '' ? $oldStatus : 'UNKNOWN';
            $auditToStatus = ($newStatusAfterRevisionEdit !== '' ? $newStatusAfterRevisionEdit : $stForAudit);
            log_sales_do_audit($pdo, (int)$do_id_post, $stForAudit, $auditToStatus, 'CRM', $actor_name, $oldStatus === 'revision_requested' ? 'EDIT_REVISION_DO' : 'EDIT_DO');
            $stCode = $pdo->prepare("SELECT do_code FROM sales_do WHERE id = ? LIMIT 1");
            $stCode->execute([$do_id_post]);
            $code = (string)($stCode->fetchColumn() ?: '');

            // hapus detail lama, insert ulang
            $del = $pdo->prepare("DELETE FROM sales_do_items WHERE do_id = :id");
            $del->execute([':id' => $do_id_post]);

            $stmtItem = $pdo->prepare("
                INSERT INTO sales_do_items
                (
                    do_id, line_no, product_id,
                    sku, products_name,
                    qty, unit, exp_date, serial_lot, no_po,
                    unit_price, disc_percent, subtotal,
                    barcode, stock_at_crm, show_package_items, category, business_group
                )
                VALUES
                (
                    :do_id, :line_no, :product_id,
                    :sku, :products_name,
                    :qty, :unit, :exp_date, :serial_lot, :no_po,
                    :unit_price, :disc_percent, :subtotal,
                    :barcode, :stock_at_crm, :show_package_items, :category, :business_group
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
                    ':no_po'              => $it['no_po'] ?? null,
                    ':unit_price'         => $it['unit_price'],
                    ':disc_percent'       => $it['disc_percent'],
                    ':subtotal'           => $it['subtotal'],
                    ':barcode'            => $it['barcode'],
                    ':stock_at_crm'       => $it['stock_at_crm'],
                    ':show_package_items' => $it['show_package_items'],
                    ':category'           => $it['category'] ?? $do_category,
                    ':business_group'     => $it['business_group'] ?? 'BMHP',
                ]);
            }

            // Kurangi ulang stok berdasarkan item revisi terbaru.
            // Berlaku untuk semua office/cabang: BDG, BGR, BKS, TGR, SLO, SMG, dst.
            sd_reduce_stock_by_do_id_lot_aware($pdo, (int)$do_id_post);

            if ($pdo->inTransaction()) { $pdo->commit(); }
            try {
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'sales_do', 'sales_do', 'CRM_UPDATE', $do_id_post, $code, "DO updated: {$code}", []);
                }
            } catch (Throwable $auditEx) {}
            $_SESSION['last_do_id'] = $do_id_post;
            if ($oldStatus === 'revision_requested') {
                set_flash_do('success', "DO berhasil direvisi. Stok lama sudah dikembalikan, stok item revisi sudah dikurangi ulang, dan DO dikirim kembali ke WQS untuk pemeriksaan serta bukti baru.");
            } else {
                set_flash_do('success', "DO berhasil diupdate. Stok lama sudah dikembalikan dan stok baru sudah dikurangi sesuai revisi.");
            }

        } else {
            // NEW_SALE dan REPLACEMENT dipisahkan tegas.
            if ($creation_mode==='NEW_SALE' && ($replacement_for_do_id>0 || $replacement_return_id>0)) {
                throw new Exception('NEW SALE tidak boleh membawa linkage replacement.');
            }
            if ($creation_mode==='REPLACEMENT' && (!$replacement_context || $replacement_for_do_id<=0 || $replacement_return_id<=0)) {
                throw new Exception('DO Pengganti wajib terhubung ke original DO + return final.');
            }

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
                    is_price_include_tax, category, is_internal_transfer, is_replacement_fulfillment,
                    crm_created_at, crm_start_at, crm_finish_at, crm_duration_sec,
                    replacement_for_do_id, replacement_return_id, replacement_created_at,
                    created_at, updated_at
                )
                VALUES
                (
                    :do_code, :tracking_code, :do_date,
                    :customers_code, :office_code, :sales_emp_code,
                    :shipping_address, :customer_pic, :customer_phone,
                    :status, :note, :total_amount,
                    :tax_code, :tax_rate_percent, :tax_amount, :grand_total,
                    :include_tax, :category, :is_internal_transfer, :is_replacement_fulfillment,
                    NOW(), :crm_start_at, :crm_finish_at, :crm_duration_sec,
                    :replacement_for_do_id, :replacement_return_id,
                    CASE WHEN :replacement_return_id2 > 0 THEN NOW() ELSE NULL END,
                    NOW(), NOW()
                )
            ");
            // V22: jumlah placeholder harus identik dengan parameter execute().
            // is_replacement_fulfillment sekarang benar-benar ikut INSERT header.
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
                ':is_internal_transfer' => sd_customer_is_internal_transfer($pdo,$customers_code) ? 1 : 0,
                ':is_replacement_fulfillment' => ($creation_mode==='REPLACEMENT' && $replacement_context ? 1 : 0),
                ':crm_start_at'     => $crm_start_at,
                ':crm_finish_at'    => $crm_finish_at,
                ':crm_duration_sec' => $crm_duration_sec,
                ':replacement_for_do_id' => ($creation_mode==='REPLACEMENT' && $replacement_context ? $replacement_for_do_id : null),
                ':replacement_return_id' => ($creation_mode==='REPLACEMENT' && $replacement_context ? $replacement_return_id : null),
                ':replacement_return_id2' => ($creation_mode==='REPLACEMENT' && $replacement_context ? $replacement_return_id : 0),
            ]);

            $do_id = (int)$pdo->lastInsertId();

            // Jika dibuat dari tombol DO Pengganti, linkage sisi sales_do SUDAH tersimpan
            // pada INSERT header. Sekarang tulis backlink retur pada transaksi yang sama.
            if ($creation_mode==='REPLACEMENT' && $replacement_for_do_id > 0 && $replacement_return_id > 0 && $replacement_context) {
                if (sd_has_table($pdo, 'sales_do_returns')) {
                    try {
                        // Jangan ALTER TABLE di tengah transaksi DO: MySQL dapat implicit COMMIT.
                        // Schema sudah dipastikan saat bootstrap sales_do/sales_do_return.
                        if (!sd_has_column($pdo, 'sales_do_returns', 'replacement_do_id')
                            || !sd_has_column($pdo, 'sales_do_returns', 'replacement_created_at')) {
                            throw new Exception('Schema linkage retur belum lengkap. Buka modul Retur Sales DO sekali atau jalankan migration sebelum membuat DO pengganti.');
                        }
                        $pdo->prepare("UPDATE sales_do_returns SET replacement_do_id=?, replacement_created_at=NOW(), updated_at=NOW() WHERE id=? AND do_id=?")
                            ->execute([$do_id, $replacement_return_id, $replacement_for_do_id]);

                        // Integrity lock: replacement wajib tersimpan dua arah. Dashboard dan audit
                        // memakai linkage ini sebagai source of truth, bukan tebakan nominal/tanggal.
                        $chkRep = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE id=? AND replacement_for_do_id=? AND replacement_return_id=?");
                        $chkRep->execute([$do_id,$replacement_for_do_id,$replacement_return_id]);
                        $chkRet = $pdo->prepare("SELECT COUNT(*) FROM sales_do_returns WHERE id=? AND do_id=? AND replacement_do_id=?");
                        $chkRet->execute([$replacement_return_id,$replacement_for_do_id,$do_id]);
                        if ((int)$chkRep->fetchColumn() !== 1 || (int)$chkRet->fetchColumn() !== 1) {
                            throw new Exception('Linkage DO pengganti tidak konsisten. Transaksi dibatalkan agar tidak menimbulkan double-count di dashboard.');
                        }
                    } catch (Throwable $eLink) {
                        throw new Exception('Gagal menyimpan linkage DO pengganti ke retur: ' . $eLink->getMessage(), 0, $eLink);
                    }
                }
            }

            // Simpan metadata order RS/customer pada kolom tambahan bila migration sudah dijalankan.
            $actor_name = get_actor_name_crm();
            sd_update_optional_sales_do_cols($pdo, $do_id, [
                'crm_order_received_at' => $crm_order_received_at,
                'crm_order_source' => $crm_order_source,
                'crm_order_proof_file' => $crm_order_proof_file,
                'crm_order_note' => $crm_order_note,
                'crm_created_by' => $actor_name,
                'sales_emp_code' => $actor_name,
                'business_group' => $do_business_group,
                'crm_duration_sec' => $crm_duration_sec,
                'crm_start_at' => $crm_start_at,
                'crm_finish_at' => $crm_finish_at,
            ]);

            // AUTO AUDIT (CRM): CREATE & SEND TO WQS
            // Catatan penting:
            // master_audit() sengaja TIDAK dipanggil di dalam transaksi utama.
            // Beberapa helper audit dapat melakukan commit/rollback sendiri sehingga
            // menyebabkan error: "There is no active transaction".
            log_sales_do_audit($pdo, $do_id, 'NEW', $status, 'CRM', $actor_name, 'CREATE_DO');

            // NEW DO item insert include exp_date, serial_lot, category
            $stmtItem = $pdo->prepare("
                INSERT INTO sales_do_items
                (
                    do_id, line_no, product_id,
                    sku, products_name,
                    qty, unit, exp_date, serial_lot, no_po,
                    unit_price, disc_percent, subtotal,
                    barcode, stock_at_crm, show_package_items, category, business_group
                )
                VALUES
                (
                    :do_id, :line_no, :product_id,
                    :sku, :products_name,
                    :qty, :unit, :exp_date, :serial_lot, :no_po,
                    :unit_price, :disc_percent, :subtotal,
                    :barcode, :stock_at_crm, :show_package_items, :category, :business_group
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
                    ':no_po'              => $it['no_po'] ?? null,
                    ':unit_price'         => $it['unit_price'],
                    ':disc_percent'       => $it['disc_percent'],
                    ':subtotal'           => $it['subtotal'],
                    ':barcode'            => $it['barcode'],
                    ':stock_at_crm'       => $it['stock_at_crm'],
                    ':show_package_items' => $it['show_package_items'],
                    ':category'           => $it['category'] ?? $do_category,
                    ':business_group'     => $it['business_group'] ?? 'BMHP',
                ]);
            }

            // KURANGI STOK WQS OFFICE setelah item DO tersimpan.
            // Lot-aware: jika Serial/Lot dipilih, stok akan dikurangi dari lot tersebut.
            sd_reduce_stock_by_do_id_lot_aware($pdo, $do_id);

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
        if ($is_update) {
            rmi_redirect($_SERVER['PHP_SELF'] . '?edit=' . $do_id_post);
        }
        rmi_redirect(sd_replacement_form_url($replacement_for_do_id, $replacement_return_id));
    }
}

$flash = get_flash_do();
// Schema error ditampilkan satu kali melalui blok $sd_schema_errors di bawah.
// Jangan menyalinnya ke flash karena flash di-escape oleh layout sehingga <br>
// tampil sebagai teks literal dan menghasilkan pesan ganda.

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
        .rmi-container { max-width: 1540px; width: calc(100% - 28px); margin: 20px auto 30px auto; }
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
        .badge-return { display:inline-block; margin-top:4px; border-radius:999px; padding:3px 8px; font-size:10px; font-weight:700; line-height:1.2; border:1px solid transparent; white-space:nowrap; }
        .badge-return.return-requested { background:rgba(245,158,11,.16); color:#fde68a; border-color:rgba(245,158,11,.40); }
        .badge-return.return-received { background:rgba(14,165,233,.16); color:#bae6fd; border-color:rgba(14,165,233,.40); }
        .badge-return.return-stocked { background:rgba(34,197,94,.16); color:#bbf7d0; border-color:rgba(34,197,94,.40); }
        .badge-return.return-completed, .badge-return.return-closed { background:rgba(16,185,129,.20); color:#d1fae5; border-color:rgba(16,185,129,.45); }
        .badge-return.return-act { background:rgba(234,179,8,.16); color:#fef08a; border-color:rgba(234,179,8,.40); }
        .badge-return.return-fin { background:rgba(168,85,247,.16); color:#e9d5ff; border-color:rgba(168,85,247,.40); }
        .badge-return.return-cancelled { background:rgba(239,68,68,.15); color:#fecaca; border-color:rgba(239,68,68,.40); }
        .badge-return.return-other { background:rgba(100,116,139,.20); color:#e2e8f0; border-color:rgba(100,116,139,.45); }

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

        /* --- RMI patch: Daftar Produk dibuat lebih lebar & rapi saat input --- */
        #table-items {
            min-width: 1880px;
            table-layout: fixed;
        }
        #table-items th,
        #table-items td {
            vertical-align: middle;
            white-space: normal !important;
            word-break: normal !important;
        }
        #table-items .form-control,
        #table-items .form-select {
            width: 100%;
            min-width: 0;
            padding-left: 8px;
            padding-right: 8px;
            font-size: 12px;
        }
        #table-items .product-select { min-width: 320px; }
        #table-items .product-search-input {
            min-width: 320px;
            margin-bottom: 5px;
            border-color: rgba(56,189,248,.35);
        }
        #table-items .product-search-input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px rgba(56,189,248,.30);
        }
        #table-items .price-input { min-width: 155px; text-align: right; }
        .price-history-box {
            margin-top: 5px;
            min-width: 190px;
            max-width: 240px;
            padding: 5px 7px;
            border: 1px solid rgba(148,163,184,.18);
            border-radius: 7px;
            background: rgba(2,6,23,.42);
            font-size: 10px;
            line-height: 1.35;
            color: #94a3b8;
        }
        .price-history-box .ph-latest {
            color: #bae6fd;
            font-weight: 600;
        }
        .price-history-box details { margin-top: 3px; }
        .price-history-box summary {
            cursor: pointer;
            color: #93c5fd;
            user-select: none;
        }
        .price-history-list {
            max-height: 155px;
            overflow: auto;
            margin-top: 5px;
            padding-right: 2px;
        }
        .price-history-item {
            padding: 5px 0;
            border-top: 1px dashed rgba(148,163,184,.18);
        }
        .price-history-item:first-child { border-top: 0; }
        #table-items .sku-input { min-width: 130px; }
        #table-items .barcode-input { min-width: 120px; }
        #table-items .qty-input { min-width: 70px; text-align: center; }
        #table-items .unit-input { min-width: 90px; }
        #table-items .exp-input { min-width: 135px; }
        #table-items .serial-input { min-width: 220px; }
        #table-items .no-po-input { min-width: 160px; }
        #table-items .price-input { min-width: 155px; text-align: right; }
        #table-items .disc-input { min-width: 90px; text-align: right; }
        #table-items .stock-input { min-width: 90px; text-align: right; }
        #table-items .subtotal-label {
            min-width: 135px;
            display: inline-block;
            text-align: right;
            white-space: nowrap;
        }
        .rmi-product-table-wrap {
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 6px;
        }
        .rmi-product-table-wrap::-webkit-scrollbar { height: 10px; }
        .rmi-product-table-wrap::-webkit-scrollbar-thumb {
            background: rgba(148,163,184,.45);
            border-radius: 999px;
        }
        @media (max-width: 768px) {
            .rmi-container { width: calc(100% - 12px); }
            #table-items { min-width: 1820px; }
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

    <?php
      $__u=function_exists('auth_user') ? auth_user() : [];
      $__r=strtoupper(trim((string)($__u['role']??'')));
      $__l=strtoupper(trim((string)($__u['level']??'')));
      $__d=strtoupper(trim((string)($__u['department']??'')));
      $__canRepair=in_array($__r,['SYS','ADMIN','SUPERADMIN'],true) || $__l==='SYS' || $__d==='SYS';
    ?>
    <?php if ($__canRepair): ?>
    <details class="alert alert-info">
      <summary style="cursor:pointer;font-weight:700">Repair Legacy — DO Pengganti sudah dibuat tetapi linkage retur belum tersimpan</summary>
      <div class="text-muted-small mt-2">
        Gunakan hanya untuk data lama yang linkage-nya benar-benar hilang. Untuk transaksi baru gunakan tombol Buat DO Pengganti dari riwayat retur FINAL.
        Sistem tidak menebak: customer + office harus sama, harus ada tepat satu retur FINAL eligible,
        dan setiap baris replacement harus terhubung ke item retur FINAL. Produk/SKU, qty, harga, dan diskon pengganti boleh berbeda. DO Pengganti tetap bukan sales baru dan tidak menambah achievement kedua kali.
      </div>
      <form method="post" class="row g-2 mt-1"
            onsubmit="return confirm('Konfirmasi: DO kedua memang DO PENGGANTI dari retur DO pertama. Sistem akan membuat two-way linkage permanen. Lanjutkan?')">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="repair_legacy_replacement" value="1">
        <div class="col-md-3">
          <input class="form-control form-control-sm"
                 name="repair_replacement_original_code"
                 placeholder="DO asal: ...-010" required>
        </div>
        <div class="col-md-3">
          <input class="form-control form-control-sm"
                 name="repair_replacement_child_code"
                 placeholder="DO pengganti: ...-012" required>
        </div>
        <div class="col-md-4">
          <input class="form-control form-control-sm"
                 name="repair_replacement_reason"
                 placeholder="Alasan: linkage legacy belum tersimpan" minlength="8" required>
        </div>
        <div class="col-md-2">
          <button class="btn btn-sm btn-info w-100">Validasi & Link</button>
        </div>
        <div class="col-12">
          <label class="form-check-label">
            <input class="form-check-input me-1"
                   type="checkbox"
                   name="repair_replacement_confirm"
                   value="1" required>
            Saya konfirmasi DO kedua adalah fulfillment pengganti dari retur FINAL DO pertama, bukan order customer baru.
          </label>
        </div>
      </form>
    </details>

    <details class="alert alert-warning">
      <summary style="cursor:pointer;font-weight:700">Koreksi Kesalahan Operator — DO baru terlanjur dibuat saat seharusnya REVISI</summary>
      <div class="text-muted-small mt-2">
        Untuk insiden operator yang membuat DO baru padahal seharusnya EDIT/REVISI DO lama.
        Tidak menghapus history/stok/workflow. Sistem hanya memberi lineage komersial sehingga DO salah tidak dihitung sebagai sales baru.
      </div>
      <form method="post" class="row g-2 mt-1" onsubmit="return confirm('Konfirmasi FINAL: DO kedua memang murni kesalahan operator, bukan order/customer transaction baru. Lanjutkan koreksi?')">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="repair_revision_duplicate" value="1">
        <div class="col-md-4"><input class="form-control form-control-sm" name="repair_original_do_code" placeholder="DO original: ...-010" required></div>
        <div class="col-md-4"><input class="form-control form-control-sm" name="repair_duplicate_do_code" placeholder="DO salah/duplikat: ...-011" required></div>
        <div class="col-md-4"><input class="form-control form-control-sm" name="repair_reason" placeholder="Alasan, contoh: operator salah klik Buat DO Baru" required minlength="8"></div>
        <div class="col-12">
          <label class="form-check-label">
            <input class="form-check-input me-1" type="checkbox" name="repair_confirm" value="1" required>
            Saya konfirmasi DO kedua bukan transaksi penjualan baru dan memang dibuat akibat kesalahan operator.
          </label>
        </div>
        <div class="col-md-4"><button class="btn btn-sm btn-warning w-100">Validasi & Hubungkan sebagai Revisi</button></div>
      </form>
    </details>
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
    <form method="post" id="do-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="do_id" value="<?= $edit_do ? (int)$edit_do['id'] : '' ?>">
        <input type="hidden" name="crm_start_ts" id="crm_start_ts" value="">
        <input type="hidden" name="existing_crm_order_proof_file" value="<?= htmlspecialchars((string)($edit_do['crm_order_proof_file'] ?? '')) ?>">
        <?php if ($replacement_requested && $replacement_context_error !== ''): ?>
        <div class="alert alert-danger" style="margin-bottom:14px">
            <b>DO Pengganti belum dapat dibuat.</b><br>
            <?= h($replacement_context_error) ?>
            <div style="margin-top:8px">
                <a class="btn btn-sm btn-outline-light" href="sales_do_return.php?do_id=<?= (int)$replacement_for_do_id ?>">Buka Riwayat Retur & Klasifikasi</a>
            </div>
        </div>
        <?php endif; ?>
        <input type="hidden" name="replacement_for_do_id" value="<?= (int)$replacement_for_do_id ?>">
        <input type="hidden" name="replacement_return_id" value="<?= (int)$replacement_return_id ?>">
        <input type="hidden" name="creation_mode" value="<?= $replacement_requested ? 'REPLACEMENT' : 'NEW_SALE' ?>">

        <?php if ($replacement_context): ?>
        <div class="alert alert-success" style="margin-bottom:14px">
            <b>DO PENGGANTI RETUR — BUKAN PENJUALAN BARU</b> — sumber <?= h((string)($replacement_context['original_do_code'] ?? '')) ?>
            / <?= h((string)($replacement_context['return_code'] ?? '')) ?>.
            Sistem menyimpan linkage eksplisit. Produk/SKU, Qty, Harga dan Diskon barang pengganti BOLEH berbeda dari item retur. Nilai replacement bersifat operasional/reference dan tidak menjadi penjualan baru atau menambah achievement kedua kali.
        </div>
        <?php endif; ?>

        <!-- INFORMASI UMUM & CUSTOMER -->
        <div class="card rmi-card">
            <div class="rmi-card-header"><h5>INFORMASI UMUM &amp; CUSTOMER</h5></div>
            <div class="rmi-card-body">

                <!-- KELOMPOK DO — satu DO satu business group; kategori Unit ACC berada di level item -->
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Kelompok Dokumen (DO)<span class="text-danger">*</span></label>
                        <?php
                        $groupSourceDo = !empty($edit_do) ? $edit_do : (!empty($replacement_prefill_do) ? $replacement_prefill_do : []);
                        $curGroup = strtoupper(trim((string)($groupSourceDo['business_group'] ?? '')));
                        if (!in_array($curGroup, ['BMHP','UNIT_ACC'], true)) {
                            $legacyHeaderCat = strtoupper(trim((string)($groupSourceDo['category'] ?? 'BMHP')));
                            $curGroup = in_array($legacyHeaderCat, ['ALKES','AKSESORIS','UNIT_ACC'], true) ? 'UNIT_ACC' : 'BMHP';
                        }
                        $groupLocked = !empty($edit_do) || !empty($replacement_prefill_do);
                        ?>
                        <?php if ($groupLocked): ?>
                            <input type="hidden" name="do_business_group" value="<?= h($curGroup) ?>">
                        <?php endif; ?>
                        <div class="d-flex gap-2 flex-wrap mt-1">
                            <?php foreach ([
                                'BMHP'     => ['label' => '🩺 BMHP',     'sub' => 'Hanya produk BMHP', 'color' => 'rgba(59,130,246,.2)', 'border' => 'rgba(59,130,246,.5)'],
                                'UNIT_ACC' => ['label' => '🧩 UNIT ACC', 'sub' => 'Boleh campur Alat Kesehatan + Aksesoris dalam 1 DO', 'color' => 'rgba(245,158,11,.2)', 'border' => 'rgba(245,158,11,.5)'],
                            ] as $grpVal => $grpInfo): ?>
                            <label style="cursor:pointer;display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:10px;border:2px solid <?= $grpInfo['border'] ?>;background:<?= $grpInfo['color'] ?>;min-width:240px">
                                <input type="radio" name="do_business_group" value="<?= $grpVal ?>" <?= $curGroup===$grpVal?'checked':'' ?> <?= $groupLocked?'disabled':'' ?> style="accent-color:currentColor">
                                <div>
                                    <div style="font-weight:700;font-size:13px"><?= $grpInfo['label'] ?></div>
                                    <div style="font-size:11px;opacity:.7"><?= $grpInfo['sub'] ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-muted-small mt-1">
                            Unit ACC ditentukan oleh <code>business_group=UNIT_ACC</code>. Kategori <b>ALKES</b>/<b>AKSESORIS</b> melekat pada tiap produk, sehingga keduanya dapat berada dalam satu DO. Office tetap mengikuti stok kantor.<?= $groupLocked ? ' <b>Kelompok dokumen dikunci pada revisi/DO pengganti agar nomor dan histori tetap konsisten.</b>' : '' ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Customer<span class="text-danger">*</span></label>
                        <select name="customers_code" id="customers_code" class="form-select form-select-sm">
                            <option value="">-- Pilih Customer --</option>
                            <?php foreach ($customers as $c): ?>
                                <?php $selectedCustomerCode = $edit_do['customers_code'] ?? ($replacement_prefill_do['customers_code'] ?? ''); ?>
                                <option value="<?= htmlspecialchars($c['customers_code']) ?>"
                                    data-office-code="<?= htmlspecialchars(strtoupper(trim($c['office_code'] ?? ''))) ?>"
                                    <?= ((string)$selectedCustomerCode === (string)$c['customers_code']) ? 'selected' : '' ?>>
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
                                <?php $selectedOfficeCode = $edit_do['office_code'] ?? ($replacement_prefill_do['office_code'] ?? ''); ?>
                                <option value="<?= htmlspecialchars(strtoupper($o['office_code'])) ?>"
                                    <?= (strtoupper((string)$selectedOfficeCode) === strtoupper((string)$o['office_code'])) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(strtoupper($o['office_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted-small">Kantor / cabang yang handle transaksi.</div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Tanggal DO<span class="text-danger">*</span></label>
                        <input type="date" name="do_date" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($edit_do ? $edit_do['do_date'] : ($replacement_prefill_do['do_date'] ?? date('Y-m-d'))) ?>">
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

                <div class="row g-3 mt-3">
                    <div class="col-md-3">
                        <label class="form-label">Jam Order Customer/RS<span class="text-danger">*</span></label>
                        <?php
                        $crmOrderValue = '';
                        if ($edit_do && !empty($edit_do['crm_order_received_at'])) {
                            $crmOrderValue = date('Y-m-d\TH:i', strtotime((string)$edit_do['crm_order_received_at']));
                        }
                        ?>
                        <input type="datetime-local" name="crm_order_received_at" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($crmOrderValue) ?>" <?= $edit_do ? '' : 'required' ?>>
                        <div class="text-muted-small">Isi sesuai jam PO/order diterima dari RS/customer. Ini dasar durasi KPI CRM.</div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Sumber Order</label>
                        <?php $src = strtoupper((string)($edit_do['crm_order_source'] ?? 'WA')); ?>
                        <select name="crm_order_source" class="form-select form-select-sm">
                            <?php foreach (['WA'=>'WhatsApp','EMAIL'=>'Email','TELEPON'=>'Telepon','MANUAL'=>'Manual','LAINNYA'=>'Lainnya'] as $sv=>$sl): ?>
                                <option value="<?= $sv ?>" <?= $src===$sv?'selected':'' ?>><?= htmlspecialchars($sl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Bukti Order / Screenshot</label>
                        <input type="file" name="crm_order_proof_file" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp,.pdf">
                        <?php if ($edit_do && !empty($edit_do['crm_order_proof_file'])): ?>
                            <div class="text-muted-small">Bukti tersimpan: <?= htmlspecialchars((string)$edit_do['crm_order_proof_file']) ?></div>
                        <?php else: ?>
                            <div class="text-muted-small">Opsional, format JPG/PNG/WEBP/PDF maksimal 5MB.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Catatan Order Customer</label>
                        <input type="text" name="crm_order_note" class="form-control form-control-sm"
                               value="<?= htmlspecialchars((string)($edit_do['crm_order_note'] ?? '')) ?>"
                               placeholder="Contoh: order via WA, PO menyusul, urgent pagi">
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
                <div class="table-responsive rmi-product-table-wrap mb-2">
                    <table class="table table-sm table-dark-custom align-middle mb-0" id="table-items">
                        <thead>
                        <tr>
                            <th style="width:45px;">#</th>
                            <th style="width:340px;">Produk</th>
                            <th style="width:120px;">Kategori</th>
                            <th style="width:140px;">SKU</th>
                            <th style="width:125px;">Barcode</th>
                            <th style="width:80px;">Qty</th>
                            <th style="width:100px;">Satuan</th>
                            <th style="width:140px;">Exp Date</th>
                            <th style="width:230px;">Serial / Lot</th>
                            <th style="width:170px;">No PO</th>
                            <th style="width:135px;" class="text-end">Harga</th>
                            <th style="width:95px;" class="text-end">Disc (%)</th>
                            <th style="width:95px;" class="text-end">Stok</th>
                            <th style="width:145px;" class="text-end">Subtotal</th>
                            <th style="width:85px;" class="text-center">Paket?</th>
                            <th style="width:55px;" class="text-center">Aksi</th>
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
            <?php
            // Rekap Dashboard: tetap tersedia untuk admin, dan dibuka khusus MGR CRM.
            // Jangan memakai username hardcode; ikuti identitas department + role/level.
            // Audit Log / Error Log tetap admin-only seperti alur sebelumnya.
            $__sd_is_admin = function_exists('auth_is_admin') && auth_is_admin();
            $__sd_user_level = strtoupper(trim((string)($_sd_user['level'] ?? '')));
            $__sd_is_crm_manager = ($_sd_user_dept === 'CRM')
                && (in_array($_sd_user_role, ['MANAGER','MGR'], true)
                    || in_array($__sd_user_level, ['MANAGER','MGR'], true));
            $__sd_can_rekap_dashboard = $__sd_is_admin || $__sd_is_crm_manager;
            ?>
            <?php if ($__sd_can_rekap_dashboard || $__sd_is_admin): ?>
            <div class="d-flex gap-2">
                <?php if ($__sd_can_rekap_dashboard): ?>
                <a href="<?= htmlspecialchars($baseProject . '/dashboards/finance/sales_do_rekap.php?all_period=1') ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" title="Rekap untuk validasi angka Dashboard Finance">
                    Rekap Dashboard
                </a>
                <?php endif; ?>
                <?php if ($__sd_is_admin): ?>
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
                <?php endif; // admin-only Audit Log / Error Log ?>
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

    // Durasi CRM: pakai nilai tersimpan; bila legacy/kosong, derive secara read-only
    // dari crm_order_received_at/crm_start_at hingga crm_finish_at/crm_created_at/created_at.
    $durSecRaw = sd_effective_crm_duration_sec($d);
    $dur       = ($durSecRaw === null) ? '-' : format_duration($durSecRaw);
    $flow      = flow_step_from_status($d['status'] ?? '');
    $flow_badge = $flow === 'PAID' ? 'DONE' : $flow;

    $status    = (string)($d['status'] ?? '');
    $status_class = ($status === 'crm_to_wqs') ? 'crm_to_wqs' : 'other';
    $return_status = strtolower(trim((string)($d['return_status'] ?? '')));
    $return_meta = $return_status !== '' ? sd_return_status_meta($return_status) : null;
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
      <?php if ($return_meta): ?>
        <br><span class="badge-return <?= h($return_meta['class']) ?>" title="Status retur terakhir<?= !empty($d['return_last_at']) ? ' · ' . h((string)$d['return_last_at']) : '' ?>"><?= h($return_meta['label']) ?></span>
      <?php endif; ?>
    </td>

    <td class="nowrap">
      <?php if ($can_edit_row): ?>
      <a class="btn btn-sm btn-outline-primary" href="<?= h($_SERVER['PHP_SELF']) ?>?edit=<?= $id ?>">Edit</a>
      <?php endif; ?>
      <a class="btn btn-sm btn-outline-light" href="sales_do_view.php?id=<?= $id ?>" target="_blank">View</a>
      <?php if ($perm_sales_print_cf): ?>
      <a class="btn btn-sm btn-outline-info"
         href="sales_do_print_cf.php?id=<?= $id ?>"
         target="_blank"
         rel="noopener"
         title="Print Continuous Form / Dot-Matrix 9.5 × 11 inch"><?= rmi_icon('print') ?> Print CF</a>
      <?php endif; ?>
      <?php if ($return_status !== ''): ?>
      <a class="btn btn-sm btn-outline-warning" href="sales_do_return.php?do_id=<?= $id ?>" target="_blank"><?= in_array($return_status, ['return_scm_completed','closed'], true) ? 'Riwayat Retur' : 'Lihat / Lanjutkan Retur' ?></a>
      <?php if (in_array($return_status, ['return_scm_completed','closed'], true) && $perm_sales_create): ?>
      <?php
        $latestReturnId = sd_latest_completed_return_id($pdo, $id);
        $existingReplacementCode = '';
        if ($latestReturnId > 0 && sd_has_column($pdo,'sales_do','replacement_return_id')) {
            try {
                $sr = $pdo->prepare("SELECT do_code FROM sales_do WHERE replacement_return_id=? AND LOWER(COALESCE(status,'')) NOT IN ('cancelled','rejected','void') ORDER BY id DESC LIMIT 1");
                $sr->execute([$latestReturnId]);
                $existingReplacementCode = (string)($sr->fetchColumn() ?: '');
            } catch (Throwable $e) {}
        }
      ?>
      <?php if ($existingReplacementCode !== ''): ?>
      <span class="badge-return return-completed" title="Retur ini sudah memiliki DO pengganti aktif">PENGGANTI: <?= h($existingReplacementCode) ?></span>
      <?php elseif ($latestReturnId > 0): ?>
      <a class="btn btn-sm btn-outline-success" href="<?= h($_SERVER['PHP_SELF']) ?>?replacement_for=<?= $id ?>&return_id=<?= $latestReturnId ?>" title="Buat DO pengganti yang ter-link eksplisit ke retur final.">Buat DO Pengganti</a>
      <?php endif; ?>
      <?php endif; ?>
      <?php elseif (in_array($status, ['on_delivery','delivered','wait_payment','paid'], true)): ?>
      <a class="btn btn-sm btn-outline-warning" href="sales_do_return.php?do_id=<?= $id ?>" target="_blank">Ajukan Retur</a>
      <?php endif; ?>
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
    const replacementItems = <?= json_encode($replacement_prefill_items, JSON_UNESCAPED_UNICODE) ?> || [];
    const isReplacementMode = <?= ($replacement_requested && !$edit_do && !empty($replacement_context)) ? 'true' : 'false' ?>;
    const replacementContextError = <?= json_encode($replacement_context_error, JSON_UNESCAPED_UNICODE) ?>;
    const initialItems = editItems.length > 0 ? editItems : replacementItems;
    const replacementAllowedProductIds = new Set(replacementItems.map(x => String(x.product_id || '')).filter(Boolean));
    const replacementAllowedSkus = new Set(replacementItems.map(x => String(x.sku || '').trim().toUpperCase().replace(/\s+/g, '')).filter(Boolean));
    const tbody = document.querySelector('#table-items tbody');
    const btnAdd = document.getElementById('btn-add-row');
    const grandTotalEl = document.getElementById('grand-total');
    const selCustomer = document.getElementById('customers_code');
    const selOffice = document.querySelector('[name="office_code"]');
    const doBusinessGroupRadios = Array.from(document.querySelectorAll('input[name="do_business_group"]'));
    let pricelistMap = {};

    if (!tbody || !btnAdd) return;

    if (isReplacementMode) {
        // Item retur tetap diprefill dan terikat return_item_id. CRM boleh menambah
        // barang lain pada DO pengganti; baris tambahan tidak membawa return_item_id.
        btnAdd.disabled = false;
        btnAdd.title = 'Tambah barang tambahan ke DO Pengganti. Item retur yang diprefill tetap terhubung ke retur FINAL.';
    }

    if (replacementContextError) {
        btnAdd.disabled = true;
        document.querySelectorAll('#table-items select, #table-items input').forEach(el => { el.disabled = true; });
        document.querySelectorAll('button[type="submit"]').forEach(btn => {
            const txt = String(btn.textContent || '').toUpperCase();
            if (txt.includes('SIMPAN') || txt.includes('BUAT DO')) btn.disabled = true;
        });
    }

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }


    // RMI FIX 2026-07-31:
    // Input diskon harus menerima angka desimal dengan titik/koma, contoh 2.3 atau 5,5.
    // type=number di browser sering menolak koma, jadi parsing dibuat terpusat.
    function parseDecimalInput(value) {
        let raw = String(value ?? '').trim();
        if (raw === '') return 0;
        raw = raw.replace(/%/g, '').replace(/\s+/g, '');

        // Format Indonesia: 1.234,56 -> 1234.56
        if (raw.includes(',') && raw.includes('.')) {
            raw = raw.replace(/\./g, '').replace(',', '.');
        } else {
            // Format sederhana: 5,5 -> 5.5
            raw = raw.replace(',', '.');
        }

        raw = raw.replace(/[^0-9.\-]/g, '');
        const n = parseFloat(raw);
        return Number.isFinite(n) ? n : 0;
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

    function currentDoBusinessGroup() {
        const checked = doBusinessGroupRadios.find(r => r.checked);
        return checked ? String(checked.value || '').trim().toUpperCase() : 'BMHP';
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
            const url = 'sales_do.php?ajax=products_by_office&office_code=' + encodeURIComponent(office) + '&do_business_group=' + encodeURIComponent(currentDoBusinessGroup());
            const res = await fetch(url, { credentials: 'same-origin' });
            const json = await res.json();
            console.log('LOAD PRODUCTS OFFICE:', office, json);

            if (json && json.ok && Array.isArray(json.products)) {
                const grouped = {};
                json.products.forEach(p => {
                    const id = String(p.id || '');
                    if (!id) return;

                    // Penting: produk import sering mempunyai SKU yang sama namun product_id berbeda per lot.
                    // Untuk Sales DO, tampilkan 1 pilihan produk per SKU+nama, lalu lot-nya dijadikan dropdown.
                    const skuKey = String(p.sku || '').trim().toUpperCase().replace(/\s+/g, '');
                    const nameKey = String(p.products_name || '').trim().toUpperCase().replace(/\s+/g, ' ');
                    const unitKey = String(p.unit || '').trim().toUpperCase();
                    const key = skuKey + '||' + nameKey + '||' + unitKey;

                    if (!grouped[key]) {
                        grouped[key] = {
                            id: id, // product_id default; akan diganti ke product_id lot saat lot dipilih
                            sku: String(p.sku || ''),
                            products_name: String(p.products_name || ''),
                            unit: String(p.unit || ''),
                            price: Number(p.price || 0),
                            barcode: String(p.barcode || ''),
                            stock_qty: 0,
                            office_code: String(p.office_code || ''),
                            category: String(p.category || '').toUpperCase(),
                            business_group: String(p.business_group || '').toUpperCase(),
                            product_ids: [],
                            lots: []
                        };
                    }

                    const qty = Number(p.stock_qty || 0);
                    grouped[key].stock_qty += qty;
                    if (!grouped[key].product_ids.includes(id)) grouped[key].product_ids.push(id);

                    const lotNo = String(p.lot_number || '').trim();
                    const expDate = String(p.exp_date || '').trim();

                    // Gabungkan lot berdasarkan lot+exp+product_id agar lot ganda tidak hilang.
                    const lotKey = id + '||' + lotNo + '||' + expDate;
                    let lot = grouped[key].lots.find(x => x._key === lotKey);
                    if (!lot) {
                        lot = {
                            _key: lotKey,
                            product_id: id,
                            lot_number: lotNo,
                            exp_date: expDate,
                            stock_qty: 0
                        };
                        grouped[key].lots.push(lot);
                    }
                    lot.stock_qty += qty;
                });

                products = Object.values(grouped).map(p => {
                    // FIFO: urutkan lot berdasarkan expired date paling dekat, lalu nomor lot.
                    p.lots.sort((a, b) => {
                        const ax = String(a.exp_date || '').trim();
                        const bx = String(b.exp_date || '').trim();
                        const aEmpty = !ax || ax === '0000-00-00';
                        const bEmpty = !bx || bx === '0000-00-00';
                        if (aEmpty !== bEmpty) return aEmpty ? 1 : -1;
                        if (ax !== bx) return ax.localeCompare(bx);
                        return String(a.lot_number || '').localeCompare(String(b.lot_number || ''));
                    });
                    // Default product_id mengikuti lot FIFO tertua. Jika SKU punya beberapa product_id per lot,
                    // CRM tetap menampilkan 1 produk dan backend akan mengurangi stok FIFO berdasarkan SKU.
                    const firstFifo = p.lots.find(l => Number(l.stock_qty || 0) > 0) || p.lots[0];
                    if (firstFifo && firstFifo.product_id) p.id = String(firstFifo.product_id);
                    return p;
                });

                // V34: pada mode DO Pengganti, barang pengganti boleh berbeda SKU/product.
                // Karena itu daftar produk TIDAK difilter ke SKU retur.
                // Guard qty + harga + diskon dilakukan terhadap source return_item_id di backend.
            } else {
                console.error('Produk office gagal dimuat:', json);
            }
        } catch (e) {
            console.error('Gagal load produk office:', e);
        }

        refreshAllProductSelects();
    }

    function productOptionsHtml(selectedId, searchQuery) {
        const sid = String(selectedId || '');
        const q = String(searchQuery || '').trim().toUpperCase();
        let html = '<option value="">-- Pilih Produk --</option>';
        products.forEach(p => {
            const idsForFilter = Array.isArray(p.product_ids) ? p.product_ids.map(String) : [String(p.id)];
            const haystack = [
                p.sku || '',
                p.products_name || '',
                p.category || '',
                p.business_group || '',
                p.barcode || '',
                p.unit || ''
            ].join(' ').toUpperCase();

            // Saat search aktif: tampilkan yang cocok, tetapi produk yang sedang
            // terpilih tetap dipertahankan agar value tidak hilang.
            if (q && !haystack.includes(q) && !idsForFilter.includes(sid) && String(p.id) !== sid) {
                return;
            }
            // SKU dapat mempunyai beberapa product_id karena lot/import. Saat prefill
            // replacement membawa product_id lot yang bukan p.id hasil grouping,
            // tetap pilih produk berdasarkan seluruh product_ids milik grup SKU.
            const ids = Array.isArray(p.product_ids) ? p.product_ids.map(String) : [String(p.id)];
            const selected = (String(p.id) === sid || ids.includes(sid)) ? ' selected' : '';
            let lotLabel = '';
            if (Array.isArray(p.lots) && p.lots.length === 1) {
                const onlyLot = String(p.lots[0].lot_number || '').trim();
                if (onlyLot) lotLabel = ' | Lot: ' + onlyLot;
            } else if (Array.isArray(p.lots) && p.lots.length > 1) {
                lotLabel = ' | ' + p.lots.length + ' Lot';
            }
            const catTag = String(p.category || '').toUpperCase();
            const label = ((catTag ? '[' + catTag + '] ' : '') + (p.sku ? '[' + p.sku.toUpperCase() + '] ' : '') + p.products_name.toUpperCase() + lotLabel + ' | Stok: ' + Number(p.stock_qty || 0).toLocaleString('id-ID'));
            html += '<option value="' + esc(p.id) + '"'
                + ' data-sku="' + esc(p.sku) + '"'
                + ' data-unit="' + esc(p.unit) + '"'
                + ' data-price="' + esc(p.price) + '"'
                + ' data-barcode="' + esc(p.barcode) + '"'
                + ' data-stock="' + esc(p.stock_qty) + '"'
                + ' data-category="' + esc(p.category || '') + '"'
                + ' data-business-group="' + esc(p.business_group || '') + '"'
                + ' data-default-product-id="' + esc(p.id) + '"'
                + selected + '>' + esc(label) + '</option>';
        });
        return html;
    }

    function refreshAllProductSelects() {
        document.querySelectorAll('.product-select').forEach(select => {
            const selectedId = select.value;
            select.innerHTML = productOptionsHtml(selectedId);

            if (selectedId) {
                const exact = Array.from(select.options).find(o => o.value === selectedId);
                if (exact) {
                    select.value = selectedId;
                } else {
                    // productOptionsHtml sudah menandai option terpilih jika selectedId
                    // merupakan salah satu product_id lot dalam SKU yang sama.
                    const selectedOpt = Array.from(select.options).find(o => o.selected && o.value);
                    if (selectedOpt) select.value = selectedOpt.value;
                }
            }

            applySelectedProduct(select, false);
            const tr = select.closest('tr');
            if (tr) {
                syncProductSearchLabel(tr);
                loadPriceHistory(tr);
            }
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


    function selectedProductLabel(select) {
        if (!select || !select.selectedOptions || !select.selectedOptions.length) return '';
        const opt = select.selectedOptions[0];
        if (!opt || !opt.value) return '';
        const sku = String(opt.getAttribute('data-sku') || '').trim();
        const text = String(opt.textContent || '').trim();
        return sku ? (sku.toUpperCase() + ' — ' + text.replace(/^\[[^\]]+\]\s*/,'').replace(/^\[[^\]]+\]\s*/,''))
                   : text;
    }

    function syncProductSearchLabel(tr) {
        if (!tr) return;
        const input = tr.querySelector('.product-search-input');
        const select = tr.querySelector('.product-select');
        if (!input || !select) return;
        const label = selectedProductLabel(select);
        if (label) input.value = label;
    }

    function bindProductSearch(tr) {
        const input = tr.querySelector('.product-search-input');
        const select = tr.querySelector('.product-select');
        if (!input || !select) return;

        input.addEventListener('focus', function () {
            try { input.select(); } catch (e) {}
        });

        input.addEventListener('input', function () {
            const selectedId = select.value;
            select.innerHTML = productOptionsHtml(selectedId, input.value);

            if (selectedId) {
                const exact = Array.from(select.options).find(o => o.value === selectedId);
                if (exact) {
                    select.value = selectedId;
                } else {
                    const selectedOpt = Array.from(select.options).find(o => o.selected && o.value);
                    if (selectedOpt) select.value = selectedOpt.value;
                }
            }
        });

        // Enter pada kolom search: bila hanya ada satu kandidat, pilih langsung.
        input.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            const opts = Array.from(select.options).filter(o => o.value);
            if (opts.length === 1) {
                ev.preventDefault();
                select.value = opts[0].value;
                select.dispatchEvent(new Event('change', {bubbles:true}));
                syncProductSearchLabel(tr);
            }
        });
    }

    function fmtRupiah(value) {
        const n = Number(value || 0);
        return 'Rp ' + (Number.isFinite(n) ? n : 0).toLocaleString('id-ID', {maximumFractionDigits: 2});
    }

    const currentEditDoId = <?= (int)$edit_id ?>;
    const priceHistoryCache = new Map();

    function currentCustomerCode() {
        return selCustomer && selCustomer.value ? String(selCustomer.value).trim() : '';
    }

    async function loadPriceHistory(tr) {
        if (!tr) return;
        const box = tr.querySelector('.price-history-box');
        const select = tr.querySelector('.product-select');
        if (!box || !select) return;

        const opt = select.selectedOptions && select.selectedOptions[0];
        const cust = currentCustomerCode();
        const office = currentOfficeCode();
        const sku = opt ? String(opt.getAttribute('data-sku') || '').trim() : '';

        if (!cust || !sku || !opt || !opt.value) {
            box.innerHTML = '<span>Pilih customer + produk untuk melihat harga sebelumnya.</span>';
            return;
        }

        const key = [cust.toUpperCase(), office.toUpperCase(), sku.toUpperCase(), currentEditDoId].join('|');
        box.innerHTML = '<span>Memuat riwayat harga…</span>';

        let payload = priceHistoryCache.get(key);
        if (!payload) {
            try {
                const url = 'sales_do.php?ajax=price_history'
                    + '&customers_code=' + encodeURIComponent(cust)
                    + '&office_code=' + encodeURIComponent(office)
                    + '&sku=' + encodeURIComponent(sku)
                    + '&exclude_do_id=' + encodeURIComponent(String(currentEditDoId || 0));
                const res = await fetch(url, {credentials:'same-origin'});
                payload = await res.json();
                priceHistoryCache.set(key, payload);
            } catch (e) {
                payload = {ok:false, history:[]};
            }
        }

        if (!payload || !payload.ok) {
            box.innerHTML = '<span>Riwayat harga belum dapat dibaca.</span>';
            return;
        }

        const rows = Array.isArray(payload.history) ? payload.history : [];
        if (!rows.length) {
            box.innerHTML = '<span>Belum ada transaksi harga sebelumnya untuk customer + produk ini.</span>';
            return;
        }

        const latest = rows[0];
        const latestDate = String(latest.do_date || '-');
        let html = '<div class="ph-latest">Terakhir: ' + esc(fmtRupiah(latest.unit_price)) + '</div>'
            + '<div>' + esc(latestDate) + ' · ' + esc(latest.do_code || '-') + '</div>'
            + '<details><summary>Lihat ' + rows.length + ' riwayat harga</summary><div class="price-history-list">';

        rows.forEach(r => {
            const disc = Number(r.disc_percent || 0);
            html += '<div class="price-history-item">'
                + '<b>' + esc(r.do_date || '-') + '</b> · ' + esc(r.do_code || '-') + '<br>'
                + 'Harga: <b>' + esc(fmtRupiah(r.unit_price)) + '</b>'
                + ' · Disc: ' + esc(disc.toLocaleString('id-ID', {maximumFractionDigits:2})) + '%<br>'
                + 'Net setelah disc: ' + esc(fmtRupiah(r.net_after_disc))
                + (Number(r.include_tax || 0) ? ' · include tax' : '')
                + '<br><span style="color:#64748b">' + esc(r.office_code || '') + ' · ' + esc(String(r.status || '').toUpperCase()) + '</span>'
                + '</div>';
        });
        html += '</div></details>';
        box.innerHTML = html;
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


    function findProductById(productId) {
        return products.find(p => String(p.id) === String(productId || '')) || null;
    }

    function lotLabel(l) {
        const lot = String(l && l.lot_number ? l.lot_number : '').trim();
        const exp = String(l && l.exp_date ? l.exp_date : '').trim();
        const stock = Number(l && l.stock_qty ? l.stock_qty : 0);
        return (lot ? lot : '-') + ' | Stok: ' + stock.toLocaleString('id-ID') + (exp ? ' | Exp: ' + exp : ' | Exp: -');
    }

    function fifoAllocations(product, qtyNeeded) {
        const allocations = [];
        if (!product || !Array.isArray(product.lots) || !product.lots.length) return allocations;
        let remaining = Math.max(0, Number(qtyNeeded || 0));
        if (!remaining) remaining = 1;
        product.lots.forEach(l => {
            if (remaining <= 0) return;
            const stock = Number(l.stock_qty || 0);
            if (stock <= 0) return;
            const take = Math.min(stock, remaining);
            allocations.push({ lot: l, take: take });
            remaining -= take;
        });
        return allocations;
    }

    function refreshSerialOptions(tr, product, selectedSerial) {
        const serialHidden = tr.querySelector('.serial-input');
        const serialLabel  = tr.querySelector('.serial-auto-label');
        if (!serialHidden) return;

        const qtyInp = tr.querySelector('.qty-input');
        const qty = qtyInp ? (Number(qtyInp.value || 0) || 1) : 1;
        updateAutoFifoLot(tr, qty);
    }

    function updateAutoFifoLot(tr, qtyValue) {
        const productSelect = tr.querySelector('.product-select');
        const product = findProductById(productSelect ? productSelect.value : '');
        const serialHidden = tr.querySelector('.serial-input');
        const serialLabel  = tr.querySelector('.serial-auto-label');
        const stockInp = tr.querySelector('.stock-input');
        const expInp = tr.querySelector('.exp-input');
        const productIdInp = tr.querySelector('.product-id-input');
        const qtyInp = tr.querySelector('.qty-input');
        if (!serialHidden) return;

        if (!product || !Array.isArray(product.lots) || !product.lots.length) {
            serialHidden.value = '';
            if (serialLabel) serialLabel.value = 'AUTO FIFO - tidak ada lot';
            if (product && stockInp) stockInp.value = product.stock_qty || 0;
            return;
        }

        const qtyNeeded = Number(qtyValue || (qtyInp ? qtyInp.value : 1) || 1) || 1;
        const allocs = fifoAllocations(product, qtyNeeded);
        const totalStock = product.lots.reduce((sum, l) => sum + (Number(l.stock_qty || 0) || 0), 0);
        const first = allocs[0] ? allocs[0].lot : (product.lots.find(l => Number(l.stock_qty || 0) > 0) || product.lots[0]);

        const labels = allocs.map(a => {
            const lot = String(a.lot.lot_number || '').trim() || '-';
            const exp = String(a.lot.exp_date || '').trim();
            return lot + '(' + a.take + ')' + (exp ? ' exp ' + exp : '');
        });

        serialHidden.value = labels.length ? labels.join(' + ') : (String(first.lot_number || '').trim());
        tr.dataset.selectedSerial = serialHidden.value;

        if (serialLabel) {
            serialLabel.value = labels.length
                ? 'AUTO FIFO: ' + labels.join(' + ')
                : 'AUTO FIFO: ' + lotLabel(first);
            serialLabel.title = 'Lot/Serial otomatis berdasarkan expired paling lama/terdekat (FIFO/FEFO). Tidak perlu pilih dropdown.';
        }
        if (productIdInp && first && first.product_id) productIdInp.value = String(first.product_id);
        if (stockInp) stockInp.value = totalStock;
        if (expInp && first && first.exp_date) expInp.value = String(first.exp_date || '');
        if (qtyInp) {
            qtyInp.max = String(totalStock);
            qtyInp.title = 'Max stok total SKU FIFO: ' + totalStock.toLocaleString('id-ID');
            if ((Number(qtyInp.value || 0) || 0) > totalStock) qtyInp.value = String(totalStock);
        }
    }

    function applySelectedLot(serialHidden) {
        const tr = serialHidden.closest('tr');
        if (!tr) return;
        const qtyInp = tr.querySelector('.qty-input');
        updateAutoFifoLot(tr, qtyInp ? qtyInp.value : 1);
        if (qtyInp) qtyInp.dispatchEvent(new Event('input'));
    }

    function applySelectedProduct(select, updatePrice) {
        const tr = select.closest('tr');
        if (!tr) return;
        const opt = select.selectedOptions && select.selectedOptions[0];
        const sku = tr.querySelector('.sku-text');
        const categoryText = tr.querySelector('.category-text');
        const unit = tr.querySelector('.unit-text');
        const barcode = tr.querySelector('.barcode-text');
        const stockInp = tr.querySelector('.stock-input');
        const productIdInp = tr.querySelector('.product-id-input');
        const price = tr.querySelector('.price-input');
        const qty = tr.querySelector('.qty-input');

        if (!opt || !opt.value) {
            if (sku) sku.value = '';
            if (categoryText) categoryText.value = '';
            if (unit) unit.value = '';
            if (barcode) barcode.value = '';
            if (stockInp) stockInp.value = '0';
            if (productIdInp) productIdInp.value = '';
            const serialSelect = tr.querySelector('.serial-input');
            const serialLabel = tr.querySelector('.serial-auto-label');
            if (serialSelect) serialSelect.value = '';
            if (serialLabel) serialLabel.value = 'AUTO FIFO';
            if (updatePrice && price) price.value = '0';
            if (qty) qty.dispatchEvent(new Event('input'));
            const historyBox = tr.querySelector('.price-history-box');
            if (historyBox) historyBox.innerHTML = 'Pilih customer + produk untuk melihat harga sebelumnya.';
            return;
        }

        const skuValue = opt.getAttribute('data-sku') || '';
        const product = findProductById(opt.value);
        if (sku) sku.value = skuValue;
        if (categoryText) categoryText.value = String(opt.getAttribute('data-category') || '').toUpperCase();
        if (productIdInp) productIdInp.value = opt.getAttribute('data-default-product-id') || opt.value || '';
        if (unit) unit.value = opt.getAttribute('data-unit') || '';
        if (barcode) barcode.value = opt.getAttribute('data-barcode') || '';
        if (stockInp) stockInp.value = opt.getAttribute('data-stock') || '0';
        refreshSerialOptions(tr, product, tr.dataset.selectedSerial || '');
        const serialSelect = tr.querySelector('.serial-input');
        if (serialSelect) {
            applySelectedLot(serialSelect);
        }

        if (updatePrice && price) {
            const skuKey = skuValue.toUpperCase().trim();
            const pl = skuKey ? pricelistMap[skuKey] : undefined;
            const basePrice = parseFloat(opt.getAttribute('data-price') || '0') || 0;
            price.value = (pl !== undefined && pl !== null && pl !== '') ? String(pl) : String(basePrice);
        }
        // V35 replacement: harga mengikuti produk pengganti yang dipilih dan tetap dapat diedit.

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
        const noPoVal = data.no_po || '';
        const replacementReturnItemId = data.return_item_id || data.replacement_return_item_id || '';

        tr.innerHTML = `
            <td class="text-center align-middle line-no">${index}</td>
            <td>
                <input type="search"
                       class="form-control form-control-sm product-search-input"
                       placeholder="Cari SKU / nama / barcode…"
                       autocomplete="off"
                       aria-label="Cari produk">
                <select class="form-select form-select-sm product-select" name="items[${index}][product_select_id]">
                    ${productOptionsHtml(productId, '')}
                </select>
                <input type="hidden" class="product-id-input" name="items[${index}][product_id]" value="${esc(productId)}">
                <input type="hidden" name="items[${index}][replacement_return_item_id]" value="${esc(replacementReturnItemId)}">
            </td>
            <td><input type="text" class="form-control form-control-sm category-text" value="${esc(String(data.category || '').toUpperCase())}" readonly placeholder="AUTO"></td>
            <td><input type="text" class="form-control form-control-sm sku-text" value="${esc(skuVal)}" readonly></td>
            <td><input type="text" class="form-control form-control-sm barcode-text" value="${esc(barcodeVal)}" readonly></td>
            <td><input type="number" class="form-control form-control-sm qty-input" min="1" step="1" value="${esc(qtyVal)}" name="items[${index}][qty]"></td>
            <td><input type="text" class="form-control form-control-sm unit-text" value="${esc(unitVal)}" readonly></td>
            <td><input type="date" class="form-control form-control-sm exp-input" name="items[${index}][exp_date]" value="${esc(expVal)}"></td>
            <td>
                <input type="hidden" class="serial-input" name="items[${index}][serial_lot]" value="${esc(serialVal)}">
                <input type="text" class="form-control form-control-sm serial-auto-label" value="${esc(serialVal ? ('AUTO FIFO: ' + serialVal) : 'AUTO FIFO')}" readonly title="Lot/Serial otomatis berdasarkan expired paling lama/terdekat (FIFO/FEFO)">
            </td>
            <td><input type="text" class="form-control form-control-sm no-po-input" name="items[${index}][no_po]" value="${esc(noPoVal)}" placeholder="No PO"></td>
            <td>
                <input type="text" class="form-control form-control-sm text-end price-input" name="items[${index}][unit_price]" value="${esc(priceVal)}">
                <div class="price-history-box">Pilih customer + produk untuk melihat harga sebelumnya.</div>
            </td>
            <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-end disc-input" name="items[${index}][disc_percent]" value="${esc(discVal)}" placeholder="0 / 2,3 / 5.5" title="Diskon persen, boleh pakai koma atau titik. Contoh: 2,3 atau 5.5"></td>
            <td><input type="number" step="0.01" class="form-control form-control-sm text-end stock-input" name="items[${index}][stock_at_crm]" value="${esc(stockVal)}" readonly></td>
            <td class="text-end"><span class="subtotal-text">Rp 0</span><input type="hidden" class="subtotal-input" name="items[${index}][subtotal]" value="0"></td>
            <td class="text-center"><div class="form-check"><input class="form-check-input pkg-checkbox" type="checkbox" name="items[${index}][show_package_items]" value="1" ${showPkg ? 'checked' : ''}></div></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-del-row">&times;</button></td>
        `;
        tr.dataset.selectedSerial = serialVal;
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
        const serialSelect = tr.querySelector('.serial-input');

        bindProductSearch(tr);

        select.addEventListener('change', function () {
            tr.dataset.selectedSerial = '';
            applySelectedProduct(select, true);
            syncProductSearchLabel(tr);
            loadPriceHistory(tr);
        });

        syncProductSearchLabel(tr);
        loadPriceHistory(tr);

        function updateLine() {
            updateAutoFifoLot(tr, qty.value);
            const q = parseFloat(qty.value || '0') || 0;
            const pr = parseDecimalInput(price.value);
            let dc = parseDecimalInput(disc.value);
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
            priceHistoryCache.clear();
            await refreshPricelistMap();
            await loadProductsByOffice();
            tbody.querySelectorAll('tr').forEach(loadPriceHistory);
        });
    }

    if (selOffice) {
        selOffice.addEventListener('change', async function () {
            priceHistoryCache.clear();
            await refreshPricelistMap();
            await loadProductsByOffice();
            tbody.querySelectorAll('tr').forEach(loadPriceHistory);
        });
    }

    doBusinessGroupRadios.forEach(radio => {
        radio.addEventListener('change', async function () {
            const selectedRows = Array.from(document.querySelectorAll('.product-select')).filter(sel => !!sel.value);
            if (selectedRows.length) {
                const ok = window.confirm('Mengganti Kelompok DO akan mengosongkan produk yang sudah dipilih agar BMHP dan UNIT ACC tidak tercampur. Lanjutkan?');
                if (!ok) return;
                selectedRows.forEach(sel => { sel.value = ''; applySelectedProduct(sel, false); });
            }
            await loadProductsByOffice();
        });
    });

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
    if (initialItems.length > 0) {
        initialItems.forEach((row, idx) => tbody.appendChild(createRow(idx + 1, row)));
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