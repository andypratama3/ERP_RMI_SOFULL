<?php
// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once __DIR__ . '/master/auth.php';
require_login();
// -------------------------------------------------------------

/**
 * master_product_resolver.php
 *
 * Tujuan:
 * - Mengurangi error karena beda nama kolom antar versi schema.
 * - Caller mengirim PDO, resolver mengembalikan mapping nama kolom yang aman.
 *
 * Catatan:
 * - File ini tidak membuat koneksi DB sendiri.
 * - Key hasil fetch dari information_schema bisa berubah CASE (UPPER/LOWER) tergantung setting PDO.
 */

if (!function_exists('rmi_warn')) {
    function rmi_warn(string $msg): void
    {
        // Jangan hard-fatal; cukup warning supaya page masih bisa render.
        trigger_error($msg, E_USER_WARNING);
    }
}

if (!function_exists('rmi_row_get_ci')) {
    /**
     * Ambil value dari associative array secara case-insensitive.
     * Berguna jika PDO di-set ATTR_CASE => CASE_UPPER.
     */
    function rmi_row_get_ci(array $row, string $key, $default = null)
    {
        foreach ($row as $k => $v) {
            if (strcasecmp((string)$k, $key) === 0) {
                return $v;
            }
        }
        return $default;
    }
}

if (!function_exists('rmi_table_exists')) {
    function rmi_table_exists(PDO $pdo, string $table): bool
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
}

if (!function_exists('rmi_sql_columns')) {
    /**
     * Return kolom table dari information_schema.
     * Result disimpan dengan key lowercase agar gampang dicek.
     */
    function rmi_sql_columns(PDO $pdo, string $table): array
    {
        try {
            $st = $pdo->prepare(
                "SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default\n"
                . "FROM information_schema.columns\n"
                . "WHERE table_schema = DATABASE() AND table_name = ?"
            );
            $st->execute([$table]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rmi_warn("[master_product_resolver] Cannot read information_schema for table {$table}: " . $e->getMessage());
            return [];
        }

        $cols = [];
        foreach ($rows as $row) {
            $name = (string)rmi_row_get_ci($row, 'column_name', '');
            if ($name === '') {
                continue;
            }
            $cols[strtolower($name)] = [
                'name' => $name,
                'data_type' => (string)rmi_row_get_ci($row, 'data_type', ''),
                'is_nullable' => (string)rmi_row_get_ci($row, 'is_nullable', ''),
                'column_default' => rmi_row_get_ci($row, 'column_default', null),
            ];
        }
        return $cols;
    }
}

if (!function_exists('rmi_pick_col')) {
    /**
     * Pilih kolom pertama yang tersedia dari daftar kandidat.
     * Mengembalikan nama kolom (sesuai original case dari DB).
     */
    function rmi_pick_col(array $cols, array $candidates, ?string $fallback = null): ?string
    {
        foreach ($candidates as $cand) {
            $k = strtolower((string)$cand);
            if (isset($cols[$k])) {
                return (string)$cols[$k]['name'];
            }
        }
        return $fallback;
    }
}

if (!function_exists('master_products_resolve_columns')) {
    /**
     * Mapping kolom untuk master_products.
     * Return format:
     *   ['table'=>'master_products', 'id'=>'id', 'sku'=>'sku', 'name'=>'products_name', ...]
     */
    function master_products_resolve_columns(PDO $pdo): array
    {
        $table = 'master_products';

        if (!rmi_table_exists($pdo, $table)) {
            rmi_warn("[master_product_resolver] Table '{$table}' tidak ditemukan di database aktif");
            return [
                'table' => $table,
                'id' => 'id',
                'sku' => 'sku',
                'name' => 'products_name',
                'uom' => null,
                'category' => null,
                'brand' => null,
                'is_active' => null,
                'manufacture_id' => null,
                'barcode' => null,
                'unit' => null,
                'price' => null,
                'status' => null,
                'product_type' => null,
                'general_name' => null,
                'no_akl' => null,
                'akl_reg_no' => null,
                'stock_qty' => null,
                'current_stock' => null,
            ];
        }

        $cols = rmi_sql_columns($pdo, $table);

        $map = [
            'table' => $table,
            'id' => rmi_pick_col($cols, ['id', 'product_id'], 'id'),
            'sku' => rmi_pick_col($cols, ['sku', 'product_sku', 'kode_barang'], 'sku'),
            'name' => rmi_pick_col($cols, ['products_name', 'product_name', 'name', 'nama_barang'], 'products_name'),
            'uom' => rmi_pick_col($cols, ['uom', 'unit', 'satuan', 'unit_name'], null),
            'category' => rmi_pick_col($cols, ['category', 'kategori', 'product_category'], null),
            'brand' => rmi_pick_col($cols, ['brand', 'merk', 'product_brand'], null),
            'is_active' => rmi_pick_col($cols, ['is_active', 'active', 'is_enabled', 'status_active'], null),

            // Field versi lama / modul HRL
            'manufacture_id' => rmi_pick_col($cols, ['manufacture_id', 'manufacturer_id', 'manufactures_id'], null),
            'barcode' => rmi_pick_col($cols, ['barcode', 'bar_code'], null),
            'unit' => rmi_pick_col($cols, ['unit', 'uom', 'satuan'], null),
            'price' => rmi_pick_col($cols, ['price', 'harga'], null),
            'status' => rmi_pick_col($cols, ['status', 'product_status'], null),
            'product_type' => rmi_pick_col($cols, ['product_type', 'type'], null),
            'general_name' => rmi_pick_col($cols, ['general_name', 'nama_generik'], null),
            'no_akl' => rmi_pick_col($cols, ['no_akl', 'noakl'], null),
            'akl_reg_no' => rmi_pick_col($cols, ['akl_reg_no', 'akl_no', 'reg_no'], null),
            'stock_qty' => rmi_pick_col($cols, ['stock_qty', 'stock', 'qty'], null),
            'current_stock' => rmi_pick_col($cols, ['current_stock', 'stock_current'], null),
        ];

        return $map;
    }
}
