<?php
/**
 * stock_helper.php — Helper terpusat untuk join stok produk.
 *
 * MASALAH YANG DISELESAIKAN:
 *   wqs_stock menyimpan stok PER OFFICE/DEPO, bukan per produk.
 *   LEFT JOIN langsung ke wqs_stock tanpa GROUP BY menghasilkan baris duplikat
 *   jika produk punya stok di lebih dari 1 office → produk tampil berkali-kali.
 *
 * CARA PAKAI (WAJIB gunakan ini, jangan JOIN wqs_stock langsung):
 *
 *   require_once __DIR__ . '/stock_helper.php';
 *
 *   // Option A — pakai VIEW (paling bersih, butuh migration 155):
 *   $sql = "SELECT p.*, COALESCE(s.stock_qty, 0) AS stock_qty
 *           FROM master_products p
 *           " . stock_left_join() . "
 *           WHERE p.status = 'active'";
 *
 *   // Option B — pakai subquery (aman tanpa migration):
 *   $sql = "SELECT p.*, COALESCE(s.stock_qty, 0) AS stock_qty
 *           FROM master_products p
 *           " . stock_left_join_subquery() . "
 *           WHERE p.status = 'active'";
 *
 *   // Option C — cek apakah tabel/view ada lalu pilih sendiri:
 *   $join = stock_left_join_safe($pdo);
 *
 * NAS BASE: /volume4/web/ERP_RMI_SOFULL
 */
declare(strict_types=1);

if (!function_exists('stock_left_join')) {
    /**
     * Kembalikan JOIN clause menggunakan VIEW v_product_stock.
     * Paling efisien — butuh migration 155_v_product_stock_view.sql.
     *
     * @param string $alias Alias tabel stok (default: s)
     * @param string $productAlias Alias tabel produk (default: p)
     */
    function stock_left_join(string $alias = 's', string $productAlias = 'p'): string {
        return "LEFT JOIN v_product_stock {$alias} ON {$alias}.product_id = {$productAlias}.id";
    }
}

if (!function_exists('stock_left_join_subquery')) {
    /**
     * Kembalikan JOIN clause menggunakan subquery agregat.
     * Aman tanpa VIEW — tidak perlu migration.
     *
     * @param string $alias Alias tabel stok (default: s)
     * @param string $productAlias Alias tabel produk (default: p)
     */
    function stock_left_join_subquery(string $alias = 's', string $productAlias = 'p'): string {
        return "LEFT JOIN (
                    SELECT product_id, SUM(stock_qty) AS stock_qty, MAX(updated_at) AS updated_at
                    FROM wqs_stock
                    GROUP BY product_id
                ) {$alias} ON {$alias}.product_id = {$productAlias}.id";
    }
}

if (!function_exists('stock_left_join_office')) {
    /**
     * JOIN stok hanya untuk 1 office tertentu (untuk tampilan per-cabang).
     * Aman dari duplikat karena WHERE office_code menjamin 1 baris per produk per office.
     *
     * @param string $officeCode Kode office (BGR, BDG, dll.)
     * @param string $alias Alias tabel stok (default: s)
     * @param string $productAlias Alias tabel produk (default: p)
     */
    function stock_left_join_office(string $officeCode, string $alias = 's', string $productAlias = 'p'): string {
        $safeOffice = preg_replace('/[^A-Za-z0-9_\-]/', '', $officeCode);
        return "LEFT JOIN wqs_stock {$alias}
                    ON {$alias}.product_id = {$productAlias}.id
                    AND {$alias}.office_code = " . "'" . addslashes($safeOffice) . "'";
    }
}

if (!function_exists('stock_left_join_safe')) {
    /**
     * Pilih JOIN yang tepat berdasarkan ketersediaan VIEW vs tabel.
     * Gunakan ini jika tidak yakin VIEW sudah ada.
     *
     * @param PDO $pdo
     * @param string $alias Alias tabel stok (default: s)
     * @param string $productAlias Alias tabel produk (default: p)
     */
    function stock_left_join_safe(PDO $pdo, string $alias = 's', string $productAlias = 'p'): string {
        static $viewExists = null;
        if ($viewExists === null) {
            try {
                $st = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW' AND Tables_in_" . $pdo->query("SELECT DATABASE()")->fetchColumn() . " = 'v_product_stock'");
                $viewExists = ($st && $st->rowCount() > 0);
            } catch (Throwable $e) {
                $viewExists = false;
            }
        }
        return $viewExists
            ? stock_left_join($alias, $productAlias)
            : stock_left_join_subquery($alias, $productAlias);
    }
}

if (!function_exists('stock_has_wqs_table')) {
    /**
     * Cek apakah tabel wqs_stock tersedia.
     * Cache static agar tidak query berulang per request.
     */
    function stock_has_wqs_table(PDO $pdo): bool {
        static $cache = null;
        if ($cache === null) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wqs_stock'");
                $st->execute();
                $cache = ((int)$st->fetchColumn()) > 0;
            } catch (Throwable $e) {
                $cache = false;
            }
        }
        return $cache;
    }
}
