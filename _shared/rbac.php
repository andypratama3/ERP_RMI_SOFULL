<?php
// _shared/rbac.php
// RBAC (Role-Based Access Control) untuk ERP_RMI_SOFULL (Dept + Role).
//
// Prinsip:
// - SUPERADMIN/ADMIN selalu ALLOW ALL (hard lock) untuk memastikan tidak mengunci diri sendiri.
// - User lain mengikuti matrix Dept + Role di tabel rbac_dept_role_permissions.
// - Optional override per user di tabel rbac_user_permissions.
// - Permission registry ada di tabel rbac_permissions.
// - Pola permission: MODULE.ACTION (uppercase), contoh: SALES.VIEW, SALES.EDIT, PURCHASES.PO_APPROVE, STOCK.CREATE.
//
// Catatan penting (untuk kasus DB legacy):
// - Di beberapa install lama, tabel RBAC sudah ada tapi tidak memiliki UNIQUE key pada perm_code.
// - Akibatnya, seed "upsert" bisa membuat data duplikat (list permission jadi ribuan dan berulang).
// - File ini mengandung "schema repair" untuk dedup + tambah UNIQUE index agar upsert aman.
//
// Aman dipanggil berulang kali.

declare(strict_types=1);




// RMI_GUARD_DIRECT_ACCESS
if (basename(__FILE__) === basename($_SERVER["SCRIPT_FILENAME"] ?? "")) {
    $auth = __DIR__ . "/../master/auth.php";
    if (is_file($auth)) { require_once $auth; }
    if (function_exists("require_login")) { require_login(); }
    http_response_code(403);
    exit("Forbidden");
}

function rbac_is_privileged_session(): bool {
    $role  = strtoupper((string)($_SESSION['role'] ?? ''));
    $level = strtoupper((string)($_SESSION['level'] ?? ''));
    // SYS = ADMIN = SUPERADMIN (privileged). OWNER deprecated.
    // SYS = satu-satunya privileged. ADMIN/SUPERADMIN dipertahankan sebagai backward-compat shim.
    return in_array($role, ['SYS','ADMIN','SUPERADMIN'], true) || in_array($level, ['SYS','ADMIN','SUPERADMIN'], true);
}

function rbac_norm_code(string $v): string {
    $v = strtoupper(trim($v));
    $v = preg_replace('/\s+/', '_', $v);
    return $v ?? '';
}

/**
 * Canonicalize legacy permission aliases.
 * Example: PURCHASES_READ -> PURCHASES.VIEW
 *
 * @return array<int,string> candidate perm codes (first is original normalized)
 */
function rbac_perm_equivalent_codes(string $normCode): array {
    $c = rbac_norm_code($normCode);
    if ($c === '') {
        return [];
    }
    /** @var list<array{0:string,1:string}> Pasangan setara (dua arah) — kosong; duplikat HRL dihapus migrasi 161 */
    static $pairs = [
    ];
    $add = [];
    foreach ($pairs as [$a, $b]) {
        $a = rbac_norm_code($a);
        $b = rbac_norm_code($b);
        if ($c === $a) {
            $add[] = $b;
        }
        if ($c === $b) {
            $add[] = $a;
        }
    }
    return array_values(array_unique($add));
}

/**
 * Map legacy/mirror perm → kanonik (config/rbac_legacy_merge_map.php).
 *
 * @return array<string,string> normalized OLD => NEW
 */
function rbac_legacy_mirror_forward_map(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $root = defined('RMI_ROOT') ? RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
    $path = $root . '/config/rbac_legacy_merge_map.php';
    $raw = (is_file($path) && is_readable($path)) ? require $path : [];
    if (!is_array($raw)) {
        $cache = [];
        return $cache;
    }
    $out = [];
    foreach ($raw as $old => $new) {
        $o = rbac_norm_code((string)$old);
        $n = rbac_norm_code((string)$new);
        if ($o !== '' && $n !== '') {
            $out[$o] = $n;
        }
    }
    $cache = $out;
    return $cache;
}

/**
 * Untuk setiap kode kanonik di $candidates, tambahkan kode legacy yang masih bisa ada di matrix (pra-migrasi 162).
 *
 * @param array<int,string> $candidates
 * @return array<int,string>
 */
function rbac_legacy_mirror_extra_candidates(array $candidates): array {
    $map = rbac_legacy_mirror_forward_map();
    if ($map === []) {
        return [];
    }
    /** @var array<string,list<string>> $rev */
    $rev = [];
    foreach ($map as $old => $new) {
        $rev[$new][] = $old;
    }
    $extra = [];
    foreach ($candidates as $c) {
        $c = rbac_norm_code((string)$c);
        if ($c === '' || !isset($rev[$c])) {
            continue;
        }
        foreach ($rev[$c] as $legacy) {
            $extra[] = $legacy;
        }
    }
    return $extra;
}

/**
 * Role code untuk join ke rbac_dept_role_permissions (MANAGER | STAFF | SYS).
 * Dipakai diagnostik (tools) dan session runtime.
 */
function rbac_matrix_role_from_role_level_strings(string $roleRaw, string $levelRaw): string {
    $r = rbac_norm_code($roleRaw);
    $l = rbac_norm_code($levelRaw);
    if (in_array($r, ['SYS', 'ADMIN', 'SUPERADMIN'], true) || in_array($l, ['SYS', 'ADMIN', 'SUPERADMIN'], true)) {
        return 'SYS';
    }
    // Prefer MANAGER jika salah satu field manager (data user kadang role=STAFF, level=MANAGER).
    if ($r === 'MANAGER' || $l === 'MANAGER') {
        return 'MANAGER';
    }
    if ($r === 'STAFF' || $l === 'STAFF') {
        return 'STAFF';
    }
    if ($r !== '') {
        return $r;
    }
    if ($l !== '') {
        return $l;
    }

    return 'STAFF';
}

/**
 * Role matrix untuk session saat ini (wrapper auth_user / $_SESSION).
 */
function rbac_matrix_role_for_dept_permissions(): string {
    if (function_exists('auth_user')) {
        $u = auth_user();

        return rbac_matrix_role_from_role_level_strings((string)($u['role'] ?? ''), (string)($u['level'] ?? ''));
    }
    $r = (string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? ($_SESSION['user']['role'] ?? ''));
    $l = (string)($_SESSION['level'] ?? $_SESSION['user_level'] ?? ($_SESSION['user']['level'] ?? ''));

    return rbac_matrix_role_from_role_level_strings($r, $l);
}

function rbac_alias_candidates(string $permCode): array {
    $base = rbac_norm_code($permCode);
    if ($base === '') {
        return [];
    }
    $out = [$base];
    $legacyFwd = rbac_legacy_mirror_forward_map();
    if (isset($legacyFwd[$base])) {
        $out[] = $legacyFwd[$base];
    }
    if (!str_contains($base, '.')) {
        $m = [];
        if (preg_match('/^([A-Z0-9_]+)_(READ|VIEW|CREATE|EDIT|DELETE|APPROVE|EXPORT|IMPORT|PRINT|AUDIT|SETTINGS)$/', $base, $m)) {
            $mod = strtoupper((string)$m[1]);
            $act = strtoupper((string)$m[2]);
            $act = ($act === 'READ') ? 'VIEW' : $act;
            $out[] = $mod . '.' . $act;
        }

        $directMap = [
            'MASTER_READ' => 'MASTER.VIEW',
            'PURCHASES_READ' => 'PURCHASES.VIEW',
            'SALES_READ' => 'SALES.VIEW',
            'STOCK_READ' => 'STOCK.VIEW',
            'TOOLS_READ' => 'TOOLS.VIEW',
            'CHAT_READ' => 'CHAT.VIEW',
            'KPI_READ' => 'KPI.VIEW',
            'HRL_READ' => 'HRL.VIEW',
            'FA_READ' => 'FIXED_ASSET.VIEW',
        ];
        if (isset($directMap[$base])) {
            $out[] = $directMap[$base];
        }
    }

    $out = array_values(array_unique($out));
    $extra = [];
    foreach ($out as $c) {
        foreach (rbac_perm_equivalent_codes($c) as $e) {
            $extra[] = $e;
        }
    }

    $merged = array_values(array_unique(array_merge($out, $extra)));
    foreach (rbac_legacy_mirror_extra_candidates($merged) as $leg) {
        $merged[] = $leg;
    }

    return array_values(array_unique($merged));
}

function rbac_pdo(): ?PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) { return $GLOBALS['pdo']; }
    if (function_exists('rmi_db_pdo')) { try { return rmi_db_pdo(); } catch (Throwable $e) {} }
    if (function_exists('db_pdo')) { try { return db_pdo(); } catch (Throwable $e) {} }
    return null;
}

/**
 * RBAC schema repair (MySQL/MariaDB):
 * - Deduplicate rows (jika dulu tidak ada unique key).
 * - Tambah UNIQUE index yang dibutuhkan oleh upsert.
 *
 * Safe dipanggil berkali-kali.
 */
function rbac_schema_repair(PDO $pdo): void {
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (strtolower($driver) !== 'mysql') {
            return; // hanya MySQL/MariaDB
        }
    } catch (Throwable $e) {
        return;
    }

    // Required UNIQUE keys for upserts:
    // - rbac_permissions.perm_code
    // - rbac_dept_role_permissions(dept_code, role_code, perm_code)
    // - rbac_user_permissions(user_id, perm_code)
    rbac_repair_unique_index($pdo, 'rbac_permissions', ['perm_code'], 'uniq_perm_code');
    rbac_repair_unique_index($pdo, 'rbac_dept_role_permissions', ['dept_code','role_code','perm_code'], 'uniq_dept_role_perm');
    rbac_repair_unique_index($pdo, 'rbac_user_permissions', ['user_id','perm_code'], 'uniq_user_perm');
}

/**
 * @param array<int,string> $cols
 */
function rbac_repair_unique_index(PDO $pdo, string $table, array $cols, string $indexName): void {
    try {
        if (rbac_has_unique_index($pdo, $table, $cols)) {
            return;
        }
    } catch (Throwable $e) {
        return; // table missing / no privilege
    }

    // Deduplicate BEFORE adding unique index.
    try { rbac_dedup_by_cols($pdo, $table, $cols); } catch (Throwable $e) {}

    try {
        $colsSql = implode(', ', array_map(fn($c) => '`' . str_replace('`','', (string)$c) . '`', $cols));
        $idx = preg_replace('/[^A-Za-z0-9_]/', '_', $indexName) ?: $indexName;
        $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$idx}` ({$colsSql})");
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Check if table already has a UNIQUE (or PRIMARY) index with the exact column list.
 *
 * @param array<int,string> $cols
 */
function rbac_has_unique_index(PDO $pdo, string $table, array $cols): bool {
    $want = array_map(fn($c) => strtolower(trim((string)$c)), $cols);

    $st = $pdo->query("SHOW INDEX FROM `{$table}`");
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    if (!$rows) return false;

    /** @var array<string,array{unique:bool, cols:array<int,string>}> $idx */
    $idx = [];
    foreach ($rows as $r) {
        $name = (string)($r['Key_name'] ?? '');
        if ($name === '') continue;
        $nonUnique = (int)($r['Non_unique'] ?? 1);
        $seq = (int)($r['Seq_in_index'] ?? 0);
        $col = strtolower(trim((string)($r['Column_name'] ?? '')));
        if ($seq <= 0 || $col === '') continue;
        if (!isset($idx[$name])) {
            $idx[$name] = ['unique' => ($nonUnique === 0), 'cols' => []];
        }
        $idx[$name]['unique'] = $idx[$name]['unique'] && ($nonUnique === 0);
        $idx[$name]['cols'][$seq] = $col;
    }

    foreach ($idx as $info) {
        if (!$info['unique']) continue;
        $colsInOrder = $info['cols'];
        ksort($colsInOrder);
        $have = array_values($colsInOrder);
        if ($have === $want) {
            return true;
        }
    }
    return false;
}

/**
 * Deduplicate rows by keeping 1 row per unique key group.
 * Uses DELETE ... LIMIT (works even when table has no primary key).
 *
 * @param array<int,string> $cols
 * @return int rows deleted (best effort)
 */
function rbac_dedup_by_cols(PDO $pdo, string $table, array $cols): int {
    $deleted = 0;

    $colsSql = implode(', ', array_map(fn($c) => '`' . str_replace('`','', (string)$c) . '`', $cols));
    $dupSql = "SELECT {$colsSql}, COUNT(*) AS cnt
               FROM `{$table}`
               GROUP BY {$colsSql}
               HAVING cnt > 1";
    $rows = [];
    try {
        $st = $pdo->query($dupSql);
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return 0;
    }

    foreach ($rows as $r) {
        $cnt = (int)($r['cnt'] ?? 0);
        $toDel = $cnt - 1;
        if ($toDel <= 0) continue;

        $whereParts = [];
        $params = [];
        foreach ($cols as $c) {
            $cKey = (string)$c;
            $whereParts[] = '`' . str_replace('`','', $cKey) . '` = ?';
            $params[] = $r[$cKey] ?? null;
        }
        $where = implode(' AND ', $whereParts);

        $limit = (int)$toDel;
        if ($limit <= 0) continue;

        $delSql = "DELETE FROM `{$table}` WHERE {$where} LIMIT {$limit}";
        try {
            $stDel = $pdo->prepare($delSql);
            $stDel->execute($params);
            $deleted += (int)$stDel->rowCount();
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $deleted;
}

/**
 * Create RBAC tables if missing + seed permission registry + RBAC Default (safe). Lihat docs/BASELINES.md.
 */
function rbac_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rbac_permissions (
        perm_code   VARCHAR(80) PRIMARY KEY,
        perm_name   VARCHAR(120) NOT NULL,
        module      VARCHAR(40) NOT NULL,
        description VARCHAR(255) NULL,
        is_active   TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rbac_dept_role_permissions (
        dept_code VARCHAR(40) NOT NULL,
        role_code VARCHAR(40) NOT NULL,
        perm_code VARCHAR(80) NOT NULL,
        allow_flag TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (dept_code, role_code, perm_code),
        CONSTRAINT fk_rbac_perm FOREIGN KEY (perm_code) REFERENCES rbac_permissions(perm_code)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rbac_user_permissions (
        user_id BIGINT NOT NULL,
        perm_code VARCHAR(80) NOT NULL,
        allow_flag TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, perm_code),
        CONSTRAINT fk_rbac_perm2 FOREIGN KEY (perm_code) REFERENCES rbac_permissions(perm_code)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Repair legacy schema (dedup + add unique keys)
    rbac_schema_repair($pdo);

    // Seed permissions (upsert)
    $cnt = 0;
    try { $cnt = (int)$pdo->query("SELECT COUNT(*) FROM rbac_permissions")->fetchColumn(); } catch (Throwable $e) { $cnt = 0; }
    if ($cnt === 0) {
        rbac_seed_permissions($pdo, false);
    } else {
        rbac_seed_permissions($pdo, true);
    }

    // Apply RBAC Default only if empty
    $cntRules = 0;
    try { $cntRules = (int)$pdo->query("SELECT COUNT(*) FROM rbac_dept_role_permissions")->fetchColumn(); } catch (Throwable $e) { $cntRules = 0; }
    if ($cntRules === 0) {
        rbac_apply_baseline($pdo);
    }
}

/**
 * Seed permission registry.
 * Jika $upsert=true, akan UPDATE (tanpa menggandakan) berdasarkan perm_code.
 */
function rbac_seed_permissions(PDO $pdo, bool $upsert = false): void {
    $root = defined('RMI_ROOT') ? RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
    $configPath = $root . '/config/rbac_permissions.php';
    // Fallback: path relatif ke file ini (untuk NAS / volume4 vs Mac mount)
    if (!is_file($configPath)) {
        $altPath = dirname(__DIR__) . '/config/rbac_permissions.php';
        if (is_file($altPath)) {
            $configPath = $altPath;
        }
    }
    $fromConfig = is_file($configPath);
    $perms = $fromConfig ? (require $configPath) : [
        // ── SYSTEM ────────────────────────────────────────────────────────────────
        ['SYSTEM.USER_MANAGE',        'System - Kelola User',       'SYSTEM', 'Buat/edit/hapus user login, dept/role/office, reset password, lock/unlock.'],
        ['SYSTEM.RBAC_MANAGE',        'System - Kelola RBAC',       'SYSTEM', 'Kelola registry permission & matrix Dept+Role (RBAC Center).'],
        ['SYSTEM.RBAC_VIEW',          'System - View RBAC',         'SYSTEM', 'Akses view RBAC Center (read-only).'],
        ['SYSTEM.AUDIT_LOG_VIEW',     'System - Lihat Audit Log',   'SYSTEM', 'Lihat Audit Log semua aktivitas sistem. Bisa dikonfigurasi per Dept+Role di RBAC Center.'],
        ['SYSTEM.CONFIG_MANAGE',      'System - Konfigurasi',       'SYSTEM', 'Konfigurasi global (seed KPI, office code, feature toggle).'],
        ['SYSTEM.SECURITY_VIEW',      'System - Lihat Keamanan',    'SYSTEM', 'Lihat info security/session (read-only).'],
        ['SYSTEM.MFA_POLICY_MANAGE',  'System - MFA Policy',        'SYSTEM', 'Kelola policy MFA per role/dept.'],
        ['SYSTEM.MFA_BYPASS_MANAGE',  'System - MFA Bypass',        'SYSTEM', 'Kelola MFA bypass tickets.'],
        ['SYSTEM.JOBS_MONITOR',       'System - Jobs Monitor',      'SYSTEM', 'Monitoring worker queue, retry/run-now/cancel.'],
        ['SYSTEM.RATE_LIMIT_MANAGE',  'System - Rate Limit',        'SYSTEM', 'Konfigurasi threshold API write per scope.'],
        ['SYSTEM.ACCOUNT_READINESS',  'System - Account Readiness', 'SYSTEM', 'Cek kesiapan akun (MFA, password, dll).'],
        ['SYSTEM.API_PARTNER_KEYS',   'System - API Partner Keys',  'SYSTEM', 'Kelola API key untuk partner eksternal.'],
        // ── MASTER ────────────────────────────────────────────────────────────────
        ['MASTER.VIEW',                  'Master - Dashboard',              'MASTER', 'Akses dashboard Master Data Center.'],
        ['MASTER.CUSTOMER_VIEW',         'Customer - Lihat',                'MASTER', 'Lihat data pelanggan/RS/klinik.'],
        ['MASTER.CUSTOMER_CREATE',       'Customer - Tambah',               'MASTER', 'Tambah pelanggan baru.'],
        ['MASTER.CUSTOMER_EDIT',         'Customer - Edit',                 'MASTER', 'Edit data pelanggan.'],
        ['MASTER.CUSTOMER_DELETE',       'Customer - Hapus',                'MASTER', 'Hapus data pelanggan (high risk).'],
        ['MASTER.CUSTOMER_EXPORT',       'Customer - Export',               'MASTER', 'Export daftar customer (CSV/Excel).'],
        ['MASTER.PIC_CUSTOMER_VIEW',     'PIC Customer - Lihat',            'MASTER', 'Lihat mapping PIC customer.'],
        ['MASTER.PIC_CUSTOMER_CREATE',   'PIC Customer - Tambah',           'MASTER', 'Tambah mapping PIC customer.'],
        ['MASTER.PIC_CUSTOMER_EDIT',     'PIC Customer - Edit',             'MASTER', 'Edit mapping PIC customer.'],
        ['MASTER.PIC_CUSTOMER_DELETE',   'PIC Customer - Hapus',            'MASTER', 'Hapus mapping PIC customer.'],
        ['MASTER.PRODUCT_VIEW',          'Produk - Lihat',                  'MASTER', 'Lihat daftar & detail produk.'],
        ['MASTER.PRODUCT_CREATE',        'Produk - Tambah',                 'MASTER', 'Tambah produk baru.'],
        ['MASTER.PRODUCT_EDIT',          'Produk - Edit',                   'MASTER', 'Edit data produk.'],
        ['MASTER.PRODUCT_DELETE',        'Produk - Hapus',                  'MASTER', 'Hapus data produk (high risk).'],
        ['MASTER.PRODUCT_MEDIA_UPLOAD',  'Produk - Upload Media',           'MASTER', 'Upload foto/video produk.'],
        ['MASTER.PRODUCT_PACKAGE_VIEW',  'Paket Produk - Lihat',            'MASTER', 'Lihat paket produk.'],
        ['MASTER.PRODUCT_PACKAGE_CREATE','Paket Produk - Tambah',           'MASTER', 'Tambah paket produk baru.'],
        ['MASTER.PRODUCT_PACKAGE_EDIT',  'Paket Produk - Edit',             'MASTER', 'Edit paket produk.'],
        ['MASTER.PRODUCT_PACKAGE_DELETE','Paket Produk - Hapus',            'MASTER', 'Hapus paket produk.'],
        ['MASTER.MANUFACTURE_VIEW',      'Manufacture - Lihat',             'MASTER', 'Lihat data pabrik/manufacturer.'],
        ['MASTER.MANUFACTURE_CREATE',    'Manufacture - Tambah',            'MASTER', 'Tambah data pabrik baru.'],
        ['MASTER.MANUFACTURE_EDIT',      'Manufacture - Edit',              'MASTER', 'Edit data pabrik.'],
        ['MASTER.MANUFACTURE_DELETE',    'Manufacture - Hapus',             'MASTER', 'Hapus data pabrik.'],
        ['MASTER.VENDOR_VIEW',           'Vendor - Lihat',                  'MASTER', 'Lihat data vendor.'],
        ['MASTER.VENDOR_CREATE',         'Vendor - Tambah',                 'MASTER', 'Tambah vendor baru.'],
        ['MASTER.VENDOR_EDIT',           'Vendor - Edit',                   'MASTER', 'Edit data vendor.'],
        ['MASTER.VENDOR_DELETE',         'Vendor - Hapus',                  'MASTER', 'Hapus data vendor.'],
        ['MASTER.PRICELIST_SELL_VIEW',   'Pricelist Jual - Lihat',          'MASTER', 'Lihat harga jual.'],
        ['MASTER.PRICELIST_SELL_CREATE', 'Pricelist Jual - Tambah',         'MASTER', 'Tambah harga jual baru.'],
        ['MASTER.PRICELIST_SELL_EDIT',   'Pricelist Jual - Edit',           'MASTER', 'Edit harga jual.'],
        ['MASTER.PRICELIST_SELL_DELETE', 'Pricelist Jual - Hapus',          'MASTER', 'Hapus harga jual.'],
        ['MASTER.PRICELIST_BUY_VIEW',    'Pricelist Beli - Lihat',          'MASTER', 'Lihat harga beli.'],
        ['MASTER.PRICELIST_BUY_CREATE',  'Pricelist Beli - Tambah',         'MASTER', 'Tambah harga beli baru.'],
        ['MASTER.PRICELIST_BUY_EDIT',    'Pricelist Beli - Edit',           'MASTER', 'Edit harga beli.'],
        ['MASTER.PRICELIST_BUY_DELETE',  'Pricelist Beli - Hapus',          'MASTER', 'Hapus harga beli.'],
        ['MASTER.OFFICE_VIEW',           'Office - Lihat',                  'MASTER', 'Lihat data kantor/office code.'],
        ['MASTER.OFFICE_CREATE',         'Office - Tambah',                 'MASTER', 'Tambah kantor baru.'],
        ['MASTER.OFFICE_EDIT',           'Office - Edit',                   'MASTER', 'Edit data kantor.'],
        ['MASTER.OFFICE_DELETE',         'Office - Hapus',                  'MASTER', 'Hapus data kantor.'],
        ['MASTER.TAX_VIEW',              'Pajak - Lihat',                   'MASTER', 'Lihat data pajak/PPN/withholding.'],
        ['MASTER.TAX_CREATE',            'Pajak - Tambah',                  'MASTER', 'Tambah pajak baru.'],
        ['MASTER.TAX_EDIT',              'Pajak - Edit',                    'MASTER', 'Edit data pajak.'],
        ['MASTER.TAX_DELETE',            'Pajak - Hapus',                   'MASTER', 'Hapus data pajak.'],
        ['MASTER.PAYMENT_TERMS_VIEW',    'Payment Terms - Lihat',           'MASTER', 'Lihat termin pembayaran.'],
        ['MASTER.PAYMENT_TERMS_CREATE',  'Payment Terms - Tambah',          'MASTER', 'Tambah termin pembayaran baru.'],
        ['MASTER.PAYMENT_TERMS_EDIT',    'Payment Terms - Edit',            'MASTER', 'Edit termin pembayaran.'],
        ['MASTER.PAYMENT_TERMS_DELETE',  'Payment Terms - Hapus',           'MASTER', 'Hapus termin pembayaran.'],
        ['MASTER.EMAIL_COMPANY_VIEW',    'Email Perusahaan - Lihat',        'MASTER', 'Lihat konfigurasi email perusahaan.'],
        ['MASTER.EMAIL_COMPANY_CREATE',  'Email Perusahaan - Tambah',       'MASTER', 'Tambah konfigurasi email baru.'],
        ['MASTER.EMAIL_COMPANY_EDIT',    'Email Perusahaan - Edit',         'MASTER', 'Edit konfigurasi email.'],
        ['MASTER.EMAIL_COMPANY_DELETE',  'Email Perusahaan - Hapus',        'MASTER', 'Hapus konfigurasi email.'],
        ['MASTER.EMPLOYEE_VIEW',         'Karyawan - Lihat',                'MASTER', 'Lihat data karyawan.'],
        ['MASTER.EMPLOYEE_CREATE',       'Karyawan - Tambah',               'MASTER', 'Tambah karyawan baru.'],
        ['MASTER.EMPLOYEE_EDIT',         'Karyawan - Edit',                 'MASTER', 'Edit data karyawan.'],
        ['MASTER.EMPLOYEE_DELETE',       'Karyawan - Hapus',                'MASTER', 'Hapus data karyawan.'],
        ['MASTER.DEPARTMENT_VIEW',       'Departemen - Lihat',              'MASTER', 'Lihat data departemen.'],
        ['MASTER.DEPARTMENT_CREATE',     'Departemen - Tambah',             'MASTER', 'Tambah departemen baru.'],
        ['MASTER.DEPARTMENT_EDIT',       'Departemen - Edit',               'MASTER', 'Edit data departemen.'],
        ['MASTER.DEPARTMENT_DELETE',     'Departemen - Hapus',              'MASTER', 'Hapus data departemen.'],
        ['MASTER.COMPANY_BANK_VIEW',     'Rekening Perusahaan - Lihat',     'MASTER', 'Lihat rekening perusahaan.'],
        ['MASTER.COMPANY_BANK_CREATE',   'Rekening Perusahaan - Tambah',    'MASTER', 'Tambah rekening perusahaan.'],
        ['MASTER.COMPANY_BANK_EDIT',     'Rekening Perusahaan - Edit',      'MASTER', 'Edit rekening perusahaan.'],
        ['MASTER.COMPANY_BANK_DELETE',   'Rekening Perusahaan - Hapus',     'MASTER', 'Hapus rekening perusahaan.'],
        ['MASTER.IMPORT_PRODUCTS',       'Produk - Import',                 'MASTER', 'Import data produk dari CSV.'],
        ['MASTER.IMPORT_CUSTOMERS',      'Customer - Import',               'MASTER', 'Import data customer dari CSV.'],
        ['MASTER.IMPORT_VENDORS',        'Vendor - Import',                 'MASTER', 'Import data vendor dari CSV.'],
        ['MASTER.ADMIN_CENTER',          'Master - Admin Center',           'MASTER', 'Akses admin-level Master Data Center.'],
        // ── SALES ─────────────────────────────────────────────────────────────────
        ['SALES.VIEW',   'Sales - Lihat',        'SALES', 'Lihat dashboard, control tower, daftar & detail DO.'],
        ['SALES.CREATE', 'Sales - Buat',         'SALES', 'Membuat DO / transaksi sales baru.'],
        ['SALES.EDIT',   'Sales - Edit & Proses','SALES', 'Edit DO & proses semua tahap workflow DO.'],
        ['SALES.DELETE', 'Sales - Hapus',        'SALES', 'Hapus/batalkan DO (high risk).'],
        ['SALES.PRINT',  'Sales - Print',        'SALES', 'Print CF/DO.'],
        ['SALES.EXPORT', 'Sales - Export',       'SALES', 'Export data sales CSV.'],
        ['SALES.AUDIT',  'Sales - Audit & KPI',  'SALES', 'Lihat audit log DO, SLA, dan KPI penjualan.'],
        // ── PURCHASES ─────────────────────────────────────────────────────────────
        ['PURCHASES.VIEW',              'Purchases - Dashboard',         'PURCHASES', 'Lihat dashboard & daftar transaksi purchases.'],
        ['PURCHASES.PO_VIEW',           'PO - Lihat',                    'PURCHASES', 'Lihat daftar & detail PO.'],
        ['PURCHASES.PO_CREATE',         'PO - Buat',                     'PURCHASES', 'Buat PO baru.'],
        ['PURCHASES.PO_EDIT',           'PO - Edit',                     'PURCHASES', 'Edit PO & detail item.'],
        ['PURCHASES.PO_DELETE',         'PO - Hapus',                    'PURCHASES', 'Hapus/batalkan PO (high risk).'],
        ['PURCHASES.PO_APPROVE',        'PO - Approve',                  'PURCHASES', 'Approve/lock PO.'],
        ['PURCHASES.PO_PRINT',          'PO - Print',                    'PURCHASES', 'Print PO.'],
        ['PURCHASES.GR_VIEW',           'GR - Lihat',                    'PURCHASES', 'Lihat Goods Receipt.'],
        ['PURCHASES.GR_PROCESS',        'GR - Proses/Terima',            'PURCHASES', 'Input/terima barang dari PO.'],
        ['PURCHASES.GR_EDIT',           'GR - Edit',                     'PURCHASES', 'Edit Goods Receipt.'],
        ['PURCHASES.GR_DELETE',         'GR - Hapus',                    'PURCHASES', 'Hapus/batalkan GR.'],
        ['PURCHASES.AP_INVOICE_VIEW',   'Invoice AP - Lihat',            'PURCHASES', 'Lihat Invoice AP.'],
        ['PURCHASES.AP_INVOICE_CREATE', 'Invoice AP - Buat',             'PURCHASES', 'Input Invoice AP baru.'],
        ['PURCHASES.AP_INVOICE_EDIT',   'Invoice AP - Edit',             'PURCHASES', 'Edit Invoice AP.'],
        ['PURCHASES.AP_INVOICE_DELETE', 'Invoice AP - Hapus',            'PURCHASES', 'Hapus Invoice AP.'],
        ['PURCHASES.AP_PAYMENT_VIEW',   'Payment AP - Lihat',            'PURCHASES', 'Lihat pembayaran AP.'],
        ['PURCHASES.AP_PAYMENT_CREATE', 'Payment AP - Buat',             'PURCHASES', 'Input pembayaran AP baru.'],
        ['PURCHASES.AP_PAYMENT_EDIT',   'Payment AP - Edit',             'PURCHASES', 'Edit pembayaran AP.'],
        ['PURCHASES.AP_PAYMENT_DELETE', 'Payment AP - Hapus',            'PURCHASES', 'Hapus pembayaran AP.'],
        ['PURCHASES.FORWARDING_VIEW',   'Forwarding - Lihat',            'PURCHASES', 'Lihat data forwarding/logistik.'],
        ['PURCHASES.FORWARDING_CREATE', 'Forwarding - Buat',             'PURCHASES', 'Buat data forwarder.'],
        ['PURCHASES.FORWARDING_EDIT',   'Forwarding - Edit',             'PURCHASES', 'Edit data forwarder.'],
        ['PURCHASES.FORWARDING_DELETE', 'Forwarding - Hapus',            'PURCHASES', 'Hapus data forwarder.'],
        ['PURCHASES.IMPORT_CONTROL',    'Import - Control Tower',        'PURCHASES', 'Monitoring import & compliance.'],
        ['PURCHASES.CEISA_PIB',         'CEISA PIB',                     'PURCHASES', 'Entry/view dokumen PIB/CEISA.'],
        ['PURCHASES.REPORTS_VIEW',      'Purchases - Laporan',           'PURCHASES', 'Lihat laporan purchases.'],
        ['PURCHASES.EXPORT',            'Purchases - Export',            'PURCHASES', 'Export laporan/data purchases.'],
        ['PURCHASES.ADMIN_GL_AUTO',          'Purchases - GL Auto',           'PURCHASES', 'Generate jurnal otomatis (high risk).'],
        ['PURCHASES.ADMIN_STOCK_UPDATE',     'Purchases - Stock Update GR',   'PURCHASES', 'Update stock dari GR (high risk).'],
        ['PURCHASES.API_PR',                 'Purchases - API PR',            'PURCHASES', 'Endpoint API PR (internal).'],
        // ── FIN CENTRAL — Critical cash-out approvals (ONLY MgrFIN_BGR + SYS) ─────
        ['PURCHASES.AP_PAYMENT_APPROVE_POST','Payment AP - Approve/Post',     'PURCHASES', 'Approve dan post pembayaran AP (HANYA MgrFIN_BGR + SYS). Cash outflow final.'],
        ['PURCHASES.GL_REVERSAL_APPROVE',    'GL Reversal - Approve',         'PURCHASES', 'Approve GL Reversal Request (HANYA MgrFIN_BGR + SYS). High-risk dual-control.'],
        ['FIN.PAYMENT_APPROVE',              'FIN - Approve Pembayaran',      'FIN',       'Approval final pengeluaran kas (HANYA MgrFIN_BGR + SYS). Segregation of duty.'],
        // ── STOCK ─────────────────────────────────────────────────────────────────
        ['STOCK.VIEW',   'Stock - Lihat',        'STOCK', 'Lihat stok per office (list/summary).'],
        ['STOCK.CREATE', 'Stock - Input Opname', 'STOCK', 'Input penyesuaian/opname stok baru.'],
        ['STOCK.EDIT',   'Stock - Edit',         'STOCK', 'Edit data penyesuaian stok.'],
        ['STOCK.DELETE', 'Stock - Hapus',        'STOCK', 'Hapus penyesuaian stok (high risk).'],
        ['STOCK.AUDIT',  'Stock - Audit Log',    'STOCK', 'Lihat audit stok & movement log.'],
        // ── WQS (Warehouse) ───────────────────────────────────────────────────────
        ['WQS.VIEW',              'WQS - Dashboard',     'WQS', 'Lihat dashboard & menu WQS.'],
        ['WQS.INCOMING_VIEW',     'Incoming - Lihat',    'WQS', 'Lihat daftar penerimaan barang.'],
        ['WQS.INCOMING_CREATE',   'Incoming - Buat',     'WQS', 'Input penerimaan barang baru.'],
        ['WQS.INCOMING_EDIT',     'Incoming - Edit',     'WQS', 'Edit data incoming.'],
        ['WQS.INCOMING_DELETE',   'Incoming - Hapus',    'WQS', 'Hapus data incoming.'],
        ['WQS.PICKING_VIEW',      'Picking - Lihat',     'WQS', 'Lihat daftar picking.'],
        ['WQS.PICKING_CREATE',    'Picking - Buat',      'WQS', 'Buat picking baru untuk DO/Order.'],
        ['WQS.PICKING_EDIT',      'Picking - Edit',      'WQS', 'Edit data picking.'],
        ['WQS.PICKING_DELETE',    'Picking - Hapus',     'WQS', 'Hapus data picking.'],
        ['WQS.ALLOCATION_VIEW',   'Allocation - Lihat',  'WQS', 'Lihat alokasi stok.'],
        ['WQS.ALLOCATION_CREATE', 'Allocation - Buat',   'WQS', 'Buat alokasi stok untuk order/DO.'],
        ['WQS.ALLOCATION_EDIT',   'Allocation - Edit',   'WQS', 'Edit alokasi.'],
        ['WQS.ALLOCATION_DELETE', 'Allocation - Hapus',  'WQS', 'Hapus alokasi.'],
        ['WQS.PR_VIEW',           'PR - Lihat',          'WQS', 'Lihat daftar Purchase Request.'],
        ['WQS.PR_CREATE',         'PR - Buat',           'WQS', 'Buat Purchase Request baru.'],
        ['WQS.PR_EDIT',           'PR - Edit',           'WQS', 'Edit Purchase Request.'],
        ['WQS.PR_DELETE',         'PR - Hapus',          'WQS', 'Hapus Purchase Request.'],
        ['WQS.PR_PRINT',          'PR - Print',          'WQS', 'Print PR.'],
        ['WQS.TRANSFER_VIEW',     'Transfer - Lihat',    'WQS', 'Lihat list mutasi stok antar kantor.'],
        ['WQS.TRANSFER_CREATE',   'Transfer - Buat',     'WQS', 'Buat transfer stok antar kantor (SYS only).'],
        ['WQS.API_INCOMING_PO',   'WQS - API Incoming',  'WQS', 'API untuk load item PO ke incoming.'],
        // ── HRL ───────────────────────────────────────────────────────────────────
        ['HRL.VIEW',               'HRL - Dashboard',              'HRL', 'Akses modul HRL (dokumen, reg alkes, compliance).'],
        ['HRL.REG_ALKES_VIEW',     'Reg Alkes - Lihat',            'HRL', 'Lihat data registrasi alat kesehatan.'],
        ['HRL.REG_ALKES_CREATE',   'Reg Alkes - Tambah',           'HRL', 'Input NIE/registrasi alkes baru.'],
        ['HRL.REG_ALKES_EDIT',     'Reg Alkes - Edit',             'HRL', 'Edit data registrasi alkes.'],
        ['HRL.REG_ALKES_DELETE',   'Reg Alkes - Hapus',            'HRL', 'Hapus data registrasi alkes (high risk).'],
        ['HRL.REG_ALKES_EXPORT',   'Reg Alkes - Export',           'HRL', 'Export data reg alkes ke CSV/Excel.'],
        ['HRL.DOC_VIEW',           'Dokumen HRL - Lihat',          'HRL', 'Lihat dokumen HRL (legal, kontrak, ijin).'],
        ['HRL.DOC_CREATE',         'Dokumen HRL - Tambah',         'HRL', 'Upload/tambah dokumen HRL baru.'],
        ['HRL.DOC_EDIT',           'Dokumen HRL - Edit',           'HRL', 'Edit metadata/dokumen HRL.'],
        ['HRL.DOC_DELETE',         'Dokumen HRL - Hapus',          'HRL', 'Hapus dokumen HRL (high risk).'],
        ['HRL.COMPLIANCE_EXPORT',  'Compliance - Export',          'HRL', 'Export laporan compliance reg alkes.'],
        ['HRL.IMPORT_REKENING',    'Rekening - Import',            'HRL', 'Import rekening bank karyawan.'],
        // Module HRL_PROCESS: blok terpisah di RBAC Center (selaras menu sidebar / Dashboard HRL).
        ['HRL.PROCESS_VIEW',       'HRL Process - Lihat',          'HRL_PROCESS', 'Lihat list & detail request cuti/izin/dinas.'],
        ['HRL.PROCESS_CREATE',     'HRL Process - Buat',           'HRL_PROCESS', 'Buat/submit request cuti/izin/dinas.'],
        ['HRL.PROCESS_EDIT',       'HRL Process - Edit & Approve', 'HRL_PROCESS', 'Edit request & approve/reject (manager).'],
        ['HRL.PROCESS_DELETE',     'HRL Process - Hapus',          'HRL_PROCESS', 'Hapus/batalkan request (soft delete).'],
        // HRL Process — per tipe pengajuan (CUTI, IZIN, …): VIEW/CREATE/EDIT/DELETE terpisah di RBAC Center.
        ['HRL.REQ_CUTI_VIEW',                'Cuti — Lihat',                 'HRL_PROCESS', 'Lihat daftar/detail & alur approval pengajuan Cuti.'],
        ['HRL.REQ_CUTI_CREATE',              'Cuti — Buat',                  'HRL_PROCESS', 'Buat/draft/submit pengajuan Cuti.'],
        ['HRL.REQ_CUTI_EDIT',                'Cuti — Edit & approve',        'HRL_PROCESS', 'Edit draft/reject + approve/reject alur (mgr/HRL/FIN).'],
        ['HRL.REQ_CUTI_DELETE',              'Cuti — Hapus',                 'HRL_PROCESS', 'Soft delete pengajuan Cuti (sesuai aturan role).'],
        ['HRL.REQ_IZIN_VIEW',                'Izin — Lihat',                 'HRL_PROCESS', 'Lihat pengajuan Izin.'],
        ['HRL.REQ_IZIN_CREATE',              'Izin — Buat',                  'HRL_PROCESS', 'Buat pengajuan Izin.'],
        ['HRL.REQ_IZIN_EDIT',                'Izin — Edit & approve',        'HRL_PROCESS', 'Edit & approve alur Izin.'],
        ['HRL.REQ_IZIN_DELETE',              'Izin — Hapus',                 'HRL_PROCESS', 'Hapus pengajuan Izin.'],
        ['HRL.REQ_LEMBUR_VIEW',              'Lembur — Lihat',               'HRL_PROCESS', 'Lihat pengajuan Lembur.'],
        ['HRL.REQ_LEMBUR_CREATE',            'Lembur — Buat',                'HRL_PROCESS', 'Buat pengajuan Lembur.'],
        ['HRL.REQ_LEMBUR_EDIT',              'Lembur — Edit & approve',      'HRL_PROCESS', 'Edit & approve Lembur.'],
        ['HRL.REQ_LEMBUR_DELETE',            'Lembur — Hapus',               'HRL_PROCESS', 'Hapus pengajuan Lembur.'],
        ['HRL.REQ_PERJADIN_VIEW',            'Perjadin — Lihat',             'HRL_PROCESS', 'Lihat pengajuan Perjadin (form).'],
        ['HRL.REQ_PERJADIN_CREATE',          'Perjadin — Buat',              'HRL_PROCESS', 'Buat pengajuan Perjadin.'],
        ['HRL.REQ_PERJADIN_EDIT',            'Perjadin — Edit & approve',    'HRL_PROCESS', 'Edit & approve Perjadin.'],
        ['HRL.REQ_PERJADIN_DELETE',          'Perjadin — Hapus',             'HRL_PROCESS', 'Hapus pengajuan Perjadin.'],
        ['HRL.REQ_PERMINTAAN_KARYAWAN_VIEW', 'Permintaan Karyawan — Lihat',  'HRL_PROCESS', 'Lihat pengajuan permintaan karyawan/ATK.'],
        ['HRL.REQ_PERMINTAAN_KARYAWAN_CREATE','Permintaan Karyawan — Buat',  'HRL_PROCESS', 'Buat pengajuan permintaan karyawan.'],
        ['HRL.REQ_PERMINTAAN_KARYAWAN_EDIT', 'Permintaan Karyawan — Edit',   'HRL_PROCESS', 'Edit & approve permintaan karyawan.'],
        ['HRL.REQ_PERMINTAAN_KARYAWAN_DELETE','Permintaan Karyawan — Hapus',  'HRL_PROCESS', 'Hapus pengajuan permintaan karyawan.'],
        ['HRL.REQ_KENAIKAN_GAJI_VIEW',       'Kenaikan Gaji — Lihat',        'HRL_PROCESS', 'Lihat pengajuan kenaikan gaji.'],
        ['HRL.REQ_KENAIKAN_GAJI_CREATE',     'Kenaikan Gaji — Buat',         'HRL_PROCESS', 'Buat pengajuan kenaikan gaji.'],
        ['HRL.REQ_KENAIKAN_GAJI_EDIT',       'Kenaikan Gaji — Edit & approve', 'HRL_PROCESS', 'Edit & approve kenaikan gaji.'],
        ['HRL.REQ_KENAIKAN_GAJI_DELETE',     'Kenaikan Gaji — Hapus',        'HRL_PROCESS', 'Hapus pengajuan kenaikan gaji.'],
        ['HRL.REQ_REKRUTMEN_VIEW',           'Rekrutmen — Lihat',            'HRL_PROCESS', 'Lihat pengajuan rekrutmen.'],
        ['HRL.REQ_REKRUTMEN_CREATE',         'Rekrutmen — Buat',             'HRL_PROCESS', 'Buat pengajuan rekrutmen.'],
        ['HRL.REQ_REKRUTMEN_EDIT',           'Rekrutmen — Edit & approve',    'HRL_PROCESS', 'Edit & approve rekrutmen.'],
        ['HRL.REQ_REKRUTMEN_DELETE',         'Rekrutmen — Hapus',            'HRL_PROCESS', 'Hapus pengajuan rekrutmen.'],
        // ── PAYROLL ───────────────────────────────────────────────────────────────
        ['PAYROLL.VIEW',          'Payroll - Dashboard',    'PAYROLL', 'Lihat dashboard payroll & history runs.'],
        ['PAYROLL.CREATE',        'Payroll - Generate Run', 'PAYROLL', 'Generate payroll run per periode.'],
        ['PAYROLL.EDIT',          'Payroll - Edit Run',     'PAYROLL', 'Edit item run (tunjangan/potongan/lembur).'],
        ['PAYROLL.DELETE',        'Payroll - Hapus Run',    'PAYROLL', 'Hapus payroll run (high risk).'],
        ['PAYROLL.APPROVE',       'Payroll - Post/Lock',    'PAYROLL', 'Lock/post & finalisasi payroll run.'],
        ['PAYROLL.EXPORT',        'Payroll - Export Bank',  'PAYROLL', 'Export file pembayaran bank.'],
        ['PAYROLL.SETTINGS',      'Payroll - Pengaturan',   'PAYROLL', 'Konfigurasi payroll, matrix golongan gaji.'],
        ['PAYROLL.LOANS_VIEW',    'Kasbon - Lihat',         'PAYROLL', 'Lihat data pinjaman/kasbon.'],
        ['PAYROLL.LOANS_CREATE',  'Kasbon - Tambah',        'PAYROLL', 'Input pinjaman/kasbon baru.'],
        ['PAYROLL.LOANS_EDIT',    'Kasbon - Edit',          'PAYROLL', 'Edit data pinjaman/kasbon.'],
        ['PAYROLL.LOANS_DELETE',  'Kasbon - Hapus',         'PAYROLL', 'Hapus data pinjaman/kasbon.'],
        ['PAYROLL.PAYSLIP_VIEW',  'Payslip - Lihat',        'PAYROLL', 'Lihat/print payslip.'],
        ['PAYROLL.AUDIT',         'Payroll - Audit Log',    'PAYROLL', 'Lihat audit payroll.'],
        // ── ABSENSI ───────────────────────────────────────────────────────────────
        ['ABSENSI.VIEW',           'Absensi - Dashboard',    'ABSENSI', 'Lihat dashboard absensi.'],
        ['ABSENSI.CHECKIN',        'Absensi - Check-in/out', 'ABSENSI', 'Check-in/out by photo.'],
        ['ABSENSI.REQUEST',        'Absensi - Buat Request', 'ABSENSI', 'Buat pengajuan izin/sakit/dinas.'],
        ['ABSENSI.REQUEST_EDIT',   'Absensi - Edit Request', 'ABSENSI', 'Edit pengajuan absensi.'],
        ['ABSENSI.REQUEST_DELETE', 'Absensi - Hapus Request','ABSENSI', 'Hapus pengajuan absensi.'],
        ['ABSENSI.APPROVE',        'Absensi - Approve',      'ABSENSI', 'Approve request absensi.'],
        ['ABSENSI.RECAP',          'Absensi - Rekap',        'ABSENSI', 'Rekap & laporan absensi.'],
        ['ABSENSI.OFFICE_SETTINGS','Absensi - Office',       'ABSENSI', 'GeoFence/Office settings.'],
        ['ABSENSI.ADMIN_USERS',    'Absensi - Kelola User',  'ABSENSI', 'Kelola user absensi (mapping pin).'],
        ['ABSENSI.ADMIN_PINS',     'Absensi - Kelola PIN',   'ABSENSI', 'Kelola PIN absensi.'],
        // ── FIXED ASSET ───────────────────────────────────────────────────────────
        ['FIXED_ASSET.VIEW',             'Fixed Asset - Dashboard',   'FIXED_ASSET', 'Lihat dashboard fixed asset.'],
        ['FIXED_ASSET.ASSET_VIEW',       'Aset - Lihat',              'FIXED_ASSET', 'Lihat daftar & detail aset.'],
        ['FIXED_ASSET.ASSET_CREATE',     'Aset - Tambah',             'FIXED_ASSET', 'Input aset baru (acquisition).'],
        ['FIXED_ASSET.ASSET_EDIT',       'Aset - Edit',               'FIXED_ASSET', 'Edit data aset.'],
        ['FIXED_ASSET.ASSET_DELETE',     'Aset - Hapus',              'FIXED_ASSET', 'Hapus data aset (high risk).'],
        ['FIXED_ASSET.OPERATIONS',       'Aset - Operasional',        'FIXED_ASSET', 'Operasional aset (move, repair, dispose).'],
        ['FIXED_ASSET.DEPRECIATION_RUN', 'Aset - Depresiasi',         'FIXED_ASSET', 'Hitung depresiasi periodik.'],
        ['FIXED_ASSET.TAX_ANNUAL',       'Aset - Pajak Tahunan',      'FIXED_ASSET', 'Perhitungan pajak tahunan aset.'],
        ['FIXED_ASSET.AUDIT',            'Aset - Audit Log',          'FIXED_ASSET', 'Lihat audit log aset.'],
        // ── PQP ───────────────────────────────────────────────────────────────────
        ['PQP.VIEW',          'PQP - Dashboard',  'PQP', 'Akses modul PQP.'],
        ['PQP.CREATE',        'PQP - Tambah',     'PQP', 'Buat data PQP baru.'],
        ['PQP.EDIT',          'PQP - Edit',       'PQP', 'Edit data PQP.'],
        ['PQP.DELETE',        'PQP - Hapus',      'PQP', 'Hapus data PQP.'],
        ['PQP.QUALITY_VIEW',  'Quality - Lihat',  'PQP', 'Lihat data quality assurance.'],
        ['PQP.QUALITY_CREATE','Quality - Tambah', 'PQP', 'Input data QA baru.'],
        ['PQP.QUALITY_EDIT',  'Quality - Edit',   'PQP', 'Edit data QA.'],
        ['PQP.QUALITY_DELETE','Quality - Hapus',  'PQP', 'Hapus data QA (high risk).'],
        // ── MPR ───────────────────────────────────────────────────────────────────
        ['MPR.VIEW',        'MPR - Dashboard', 'MPR', 'Akses modul MPR (Marketing & Project).'],
        ['MPR.PLAN_VIEW',   'Plan - Lihat',    'MPR', 'Lihat plan MPR.'],
        ['MPR.PLAN_CREATE', 'Plan - Buat',     'MPR', 'Buat plan MPR baru.'],
        ['MPR.PLAN_EDIT',   'Plan - Edit',     'MPR', 'Edit plan MPR.'],
        ['MPR.PLAN_DELETE', 'Plan - Hapus',    'MPR', 'Hapus/restore plan MPR.'],
        ['MPR.PLAN_APPROVE','Plan - Approve',  'MPR', 'Approve/reject plan MPR.'],
        ['MPR.PLAN_IMPORT', 'Plan - Import',   'MPR', 'Import plan MPR dari CSV.'],
        ['MPR.PLAN_EXPORT', 'Plan - Export',   'MPR', 'Export report/rekap plan MPR.'],
        // ── KPI ───────────────────────────────────────────────────────────────────
        ['KPI.VIEW',   'KPI - Dashboard', 'KPI', 'Akses KPI Center & laporan KPI (lapisan RBAC: buka halaman).'],
        ['KPI.CREATE', 'KPI - Tambah',    'KPI', 'Legacy/menu. Mutasi data KPI efektif hanya level SYS di kode (bukan gate utama).'],
        ['KPI.EDIT',   'KPI - Edit',      'KPI', 'Legacy/menu. Jangan pakai sebagai satu-satunya gate mutasi — gunakan level SYS (_shared/rmi_sys_gate.php).'],
        ['KPI.DELETE', 'KPI - Hapus',     'KPI', 'Legacy/menu. Hapus KPI di app hanya SYS (kpi_can_manage).'],
        // ── DASHBOARD ─────────────────────────────────────────────────────────────
        ['DASHBOARD.VIEW',             'Dashboard - Semua',             'DASHBOARD', 'Akses semua dashboard.'],
        ['DASHBOARD.SCM_VIEW',         'Dashboard SCM',                 'DASHBOARD', 'Akses SCM Dashboard.'],
        ['DASHBOARD.SALES_VIEW',       'Dashboard Sales',               'DASHBOARD', 'Akses Sales Dashboard.'],
        ['DASHBOARD.BRANCH_VIEW',      'Dashboard Branch',              'DASHBOARD', 'Akses Branch Dashboard.'],
        ['DASHBOARD.WAREHOUSE_VIEW',   'Dashboard Warehouse',           'DASHBOARD', 'Akses Warehouse/WQS Dashboard.'],
        ['DASHBOARD.FINANCE_VIEW',     'Dashboard Finance',             'DASHBOARD', 'Akses Finance Dashboard.'],
        ['DASHBOARD.PROCUREMENT_VIEW', 'Dashboard Procurement',         'DASHBOARD', 'Akses Procurement/Purchases Dashboard.'],
        ['DASHBOARD.REGULATORY_VIEW',  'Dashboard Regulatory',          'DASHBOARD', 'Akses Regulatory Dashboard.'],
        ['DASHBOARD.QUALITY_VIEW',     'Dashboard Quality',             'DASHBOARD', 'Akses Quality Dashboard.'],
        ['DASHBOARD.HRL_VIEW',         'Dashboard HRL',                 'DASHBOARD', 'Akses HRL Dashboard.'],
        ['DASHBOARD.ITC_VIEW',         'Dashboard ITC',                 'DASHBOARD', 'Akses ITC Dashboard.'],
        ['DASHBOARD.ACT_VIEW',         'Dashboard ACT',                 'DASHBOARD', 'Akses ACT Dashboard.'],
        ['DASHBOARD.OWNER_VIEW',       'Dashboard Executive',           'DASHBOARD', 'Akses Executive Dashboard.'],
        ['DASHBOARD.FINANCE_DETAIL',   'Dashboard Finance Detail',      'DASHBOARD', 'Dashboard Detail target vs pencapaian per office.'],
        ['DASHBOARD.OWNER_SUMMARY',    'Dashboard Executive Summary',   'DASHBOARD', 'Ringkasan bisnis untuk SYS/Manager.'],
        // ── TOOLS ─────────────────────────────────────────────────────────────────
        ['TOOLS.VIEW',                    'Tools - Dashboard',         'TOOLS', 'Akses menu Tools/Diagnostics.'],
        ['TOOLS.ENTERPRISE_AUDIT_VIEW',   'Tools - Audit View',        'TOOLS', 'Lihat hasil static scan audit.'],
        ['TOOLS.ENTERPRISE_AUDIT_EXPORT', 'Tools - Audit Export',      'TOOLS', 'Export audit report (CSV/JSON).'],
        ['TOOLS.BACKUP_MANAGE',           'Tools - Backup',            'TOOLS', 'Backup, restore, schedule, retention.'],
        ['TOOLS.READINESS_AUDIT',         'Tools - Readiness Audit',   'TOOLS', 'Audit kesiapan deploy & cutover.'],
        ['TOOLS.SECURITY_AUDIT',          'Tools - Security Audit',    'TOOLS', 'Static scan keamanan & konsistensi.'],
        ['TOOLS.REVIEW_KIT',              'Tools - Review Kit',        'TOOLS', 'Review kit workspace & signoff.'],
        ['TOOLS.PURCHASES_M2_APPLY',      'Tools - M2 Patch',          'TOOLS', 'Jalankan patch/repair purchases (high risk).'],
        ['TOOLS.ITC_RESET_PASSWORD',      'Tools - Reset Password',    'TOOLS', 'Reset password user (ITC support).'],
        // ── CHAT ──────────────────────────────────────────────────────────────────
        ['CHAT.VIEW',          'Chat - Lihat',    'CHAT', 'Akses Internal Chat (baca & kirim pesan).'],
        ['CHAT.ADMIN_SETTINGS','Chat - Settings', 'CHAT', 'Kelola pengaturan chat (retention, ACL, audit).'],
    ];
    $GLOBALS['_rbac_seed_source'] = $fromConfig ? 'config' : 'fallback';
    $GLOBALS['_rbac_seed_count'] = count($perms);

    if ($upsert) {
        $ins = $pdo->prepare("INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
                              VALUES (?,?,?,?,1)
                              ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1");
    } else {
        $ins = $pdo->prepare("INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
                              VALUES (?,?,?,?,1)");
    }

    foreach ($perms as $p) {
        try {
            $ins->execute([rbac_norm_code((string)$p[0]), (string)$p[1], rbac_norm_code((string)$p[2]), (string)$p[3]]);
        } catch (Throwable $e) {
            // ignore single row
        }
    }
}

/**
 * Path absolut ke config/rbac_permissions.php jika ada; sama dengan resolusi di rbac_seed_permissions().
 */
function rbac_rbac_permissions_config_path(): ?string {
    $root = defined('RMI_ROOT') ? RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
    $configPath = $root . '/config/rbac_permissions.php';
    if (!is_file($configPath)) {
        $altPath = dirname(__DIR__) . '/config/rbac_permissions.php';
        if (is_file($altPath)) {
            $configPath = $altPath;
        }
    }
    return is_file($configPath) ? $configPath : null;
}

/**
 * Set kode permission yang dianggap sah menurut config (normalisasi = seed).
 *
 * @return array<string,true>
 */
function rbac_config_allowed_perm_codes(): array {
    $path = rbac_rbac_permissions_config_path();
    if ($path === null) {
        return [];
    }
    $perms = require $path;
    if (!is_array($perms)) {
        return [];
    }
    $out = [];
    foreach ($perms as $p) {
        $c = rbac_norm_code((string)($p[0] ?? ''));
        if ($c !== '') {
            $out[$c] = true;
        }
    }
    return $out;
}

/**
 * Hapus baris rbac_permissions yang perm_code-nya tidak ada di config/rbac_permissions.php.
 * Baris rbac_dept_role_permissions / rbac_user_permissions yang mereferensi ikut terhapus jika FK ON DELETE CASCADE aktif.
 *
 * @return array{deleted:int, codes:list<string>, dry_run:bool}
 *
 * @throws RuntimeException jika file config hilang, kosong, atau katalog terlalu kecil (anti mis-delete)
 */
function rbac_prune_permissions_not_in_config(PDO $pdo, bool $dryRun = false): array {
    $allowed = rbac_config_allowed_perm_codes();
    if ($allowed === []) {
        throw new RuntimeException('config/rbac_permissions.php tidak ditemukan atau tidak valid; prune dibatalkan.');
    }
    $minCatalog = 100;
    if (count($allowed) < $minCatalog) {
        throw new RuntimeException(
            'Katalog config terlalu kecil (' . count($allowed) . ' < ' . $minCatalog . '); prune dibatalkan demi keamanan.'
        );
    }
    $codes = array_keys($allowed);
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $st = $pdo->prepare("SELECT perm_code FROM rbac_permissions WHERE perm_code NOT IN ($ph) ORDER BY perm_code");
    $st->execute($codes);
    /** @var list<string> $raw */
    $raw = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $orphans = [];
    foreach ($raw as $x) {
        $c = rbac_norm_code((string)$x);
        if ($c !== '') {
            $orphans[] = $c;
        }
    }
    $orphans = array_values(array_unique($orphans, SORT_STRING));
    sort($orphans, SORT_STRING);
    if ($orphans === [] || $dryRun) {
        return ['deleted' => 0, 'codes' => $orphans, 'dry_run' => $dryRun];
    }
    $pdo->beginTransaction();
    try {
        $in = implode(',', array_fill(0, count($orphans), '?'));
        $del = $pdo->prepare("DELETE FROM rbac_permissions WHERE perm_code IN ($in)");
        $del->execute($orphans);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['deleted' => count($orphans), 'codes' => $orphans, 'dry_run' => false];
}

/**
 * RBAC_DEFAULT policy (rekomendasi awal). Lihat docs/BASELINES.md.
 * - Semua dept + STAFF/MANAGER: absensi basic (VIEW/CHECKIN/REQUEST).
 * - Tambahan izin per Dept+Role mengikuti kebutuhan operasional.
 *
 * Admin/Superadmin tetap allow all secara hardcode.
 */
function rbac_apply_baseline(PDO $pdo): void {
    $roles = ['MANAGER','STAFF'];

    // Load dept list (prefer master_departements / fallback master_system_login.department)
    $depts = [];
    try {
        $st = $pdo->query("SELECT DISTINCT dept_code FROM master_departements
                           WHERE dept_code IS NOT NULL AND dept_code<>'' ORDER BY dept_code");
        $depts = array_map(fn($x) => strtoupper((string)$x['dept_code']), $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $depts = [];
    }
    if (!$depts) {
        try {
            $st = $pdo->query("SELECT DISTINCT department AS dept_code FROM master_system_login
                               WHERE department IS NOT NULL AND department<>'' ORDER BY department");
            $depts = array_map(fn($x) => strtoupper((string)$x['dept_code']), $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $depts = [];
        }
    }
    if (!$depts) {
        $depts = ['HRL','FIN','ITC','CRM','MPR','SCM','WQS','PQP','ACT','SYS','BRANCH'];
    }

    // Load perm registry to avoid FK error (skip unknown perm_code)
    $permSet = [];
    try {
        $st = $pdo->query("SELECT perm_code FROM rbac_permissions");
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $r) {
            $pc = strtoupper((string)($r['perm_code'] ?? ''));
            if ($pc !== '') $permSet[$pc] = true;
        }
    } catch (Throwable $e) {
        $permSet = [];
    }

    $basePermsAll = ['ABSENSI.VIEW','ABSENSI.CHECKIN','ABSENSI.REQUEST','DASHBOARD.VIEW','CHAT.VIEW','KPI.VIEW'];

    // Dept|Role — permission granular per aksi (VIEW / CREATE / EDIT / DELETE / APPROVE)
    // STAFF  = VIEW + CREATE + EDIT (tidak bisa DELETE/APPROVE)
    // MANAGER = VIEW + CREATE + EDIT + DELETE + APPROVE
    $extraByDeptRole = [
        // ACT — Accounting & Tax
        'ACT|MANAGER' => ['MASTER.VIEW', 'SALES.VIEW', 'SALES.EDIT', 'SALES.AUDIT', 'SALES.EXPORT', 'KPI.VIEW', 'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'HRL.REQ_KENAIKAN_GAJI_CREATE', 'HRL.REQ_REKRUTMEN_CREATE', 'DASHBOARD.ACT_VIEW', 'DASHBOARD.FINANCE_VIEW', 'DASHBOARD.OWNER_VIEW', 'DASHBOARD.OWNER_SUMMARY'],
        'ACT|STAFF'   => ['MASTER.VIEW', 'SALES.VIEW', 'SALES.EDIT', 'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'DASHBOARD.ACT_VIEW', 'DASHBOARD.FINANCE_VIEW'],

        // CRM — Customer Relationship Management
        'CRM|MANAGER' => [
            'MASTER.VIEW',
            'MASTER.CUSTOMER_VIEW', 'MASTER.CUSTOMER_CREATE', 'MASTER.CUSTOMER_EDIT', 'MASTER.CUSTOMER_DELETE', 'MASTER.CUSTOMER_EXPORT',
            'MASTER.PIC_CUSTOMER_VIEW', 'MASTER.PIC_CUSTOMER_CREATE', 'MASTER.PIC_CUSTOMER_EDIT', 'MASTER.PIC_CUSTOMER_DELETE',
            'MASTER.PRICELIST_SELL_VIEW', 'MASTER.PRICELIST_SELL_CREATE', 'MASTER.PRICELIST_SELL_EDIT', 'MASTER.PRICELIST_SELL_DELETE',
            'MASTER.IMPORT_CUSTOMERS',
            'SALES.VIEW', 'SALES.CREATE', 'SALES.EDIT', 'SALES.DELETE', 'SALES.PRINT', 'SALES.EXPORT', 'SALES.AUDIT', 'KPI.VIEW',
            'STOCK.VIEW', 'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'HRL.REQ_KENAIKAN_GAJI_CREATE', 'HRL.REQ_REKRUTMEN_CREATE', 'DASHBOARD.SALES_VIEW',
        ],
        'CRM|STAFF' => [
            'MASTER.VIEW',
            'MASTER.CUSTOMER_VIEW', 'MASTER.CUSTOMER_CREATE', 'MASTER.CUSTOMER_EDIT', 'MASTER.CUSTOMER_EXPORT',
            'MASTER.PIC_CUSTOMER_VIEW', 'MASTER.PIC_CUSTOMER_CREATE', 'MASTER.PIC_CUSTOMER_EDIT',
            'MASTER.PRICELIST_SELL_VIEW', 'MASTER.PRICELIST_SELL_CREATE', 'MASTER.PRICELIST_SELL_EDIT',
            'SALES.VIEW', 'SALES.CREATE', 'SALES.EDIT', 'SALES.PRINT',
            'STOCK.VIEW', 'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'DASHBOARD.SALES_VIEW',
        ],

        // FIN — Finance
        // NOTE: PURCHASES.AP_PAYMENT_APPROVE_POST and PURCHASES.GL_REVERSAL_APPROVE are NOT in FIN|MANAGER.
        // These critical cash-out approvals are assigned ONLY to MgrFIN_BGR user via rbac_user_permissions.
        'FIN|MANAGER' => [
            'MASTER.VIEW',
            'MASTER.COMPANY_BANK_VIEW', 'MASTER.COMPANY_BANK_CREATE', 'MASTER.COMPANY_BANK_EDIT', 'MASTER.COMPANY_BANK_DELETE',
            'MASTER.TAX_VIEW', 'MASTER.TAX_CREATE', 'MASTER.TAX_EDIT', 'MASTER.TAX_DELETE',
            'MASTER.PAYMENT_TERMS_VIEW', 'MASTER.PAYMENT_TERMS_CREATE', 'MASTER.PAYMENT_TERMS_EDIT', 'MASTER.PAYMENT_TERMS_DELETE',
            'MASTER.PRICELIST_BUY_VIEW', 'MASTER.PRICELIST_BUY_CREATE', 'MASTER.PRICELIST_BUY_EDIT', 'MASTER.PRICELIST_BUY_DELETE',
            'PURCHASES.VIEW',
            'PURCHASES.AP_INVOICE_VIEW', 'PURCHASES.AP_INVOICE_CREATE', 'PURCHASES.AP_INVOICE_EDIT', 'PURCHASES.AP_INVOICE_DELETE',
            'PURCHASES.AP_PAYMENT_VIEW', 'PURCHASES.AP_PAYMENT_CREATE', 'PURCHASES.AP_PAYMENT_EDIT', 'PURCHASES.AP_PAYMENT_DELETE',
            'PURCHASES.REPORTS_VIEW', 'PURCHASES.EXPORT', 'PURCHASES.ADMIN_GL_AUTO',
            'PAYROLL.VIEW', 'PAYROLL.APPROVE', 'PAYROLL.EXPORT', 'PAYROLL.AUDIT',
            'FIXED_ASSET.VIEW', 'FIXED_ASSET.ASSET_VIEW', 'FIXED_ASSET.ASSET_CREATE', 'FIXED_ASSET.ASSET_EDIT', 'FIXED_ASSET.ASSET_DELETE',
            'FIXED_ASSET.OPERATIONS', 'FIXED_ASSET.DEPRECIATION_RUN', 'FIXED_ASSET.TAX_ANNUAL', 'FIXED_ASSET.AUDIT',
            'SALES.VIEW', 'SALES.EDIT', 'STOCK.VIEW',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'HRL.REQ_KENAIKAN_GAJI_CREATE', 'HRL.REQ_REKRUTMEN_CREATE', 'HRL.PROCESS_DELETE',
            'DASHBOARD.FINANCE_VIEW', 'DASHBOARD.OWNER_VIEW', 'DASHBOARD.OWNER_SUMMARY',
        ],
        'FIN|STAFF' => [
            'MASTER.VIEW',
            'MASTER.PRICELIST_BUY_VIEW', 'MASTER.PRICELIST_BUY_CREATE', 'MASTER.PRICELIST_BUY_EDIT',
            'PURCHASES.VIEW',
            'PURCHASES.AP_INVOICE_VIEW', 'PURCHASES.AP_INVOICE_CREATE', 'PURCHASES.AP_INVOICE_EDIT',
            'PURCHASES.AP_PAYMENT_VIEW', 'PURCHASES.AP_PAYMENT_CREATE', 'PURCHASES.AP_PAYMENT_EDIT',
            'PURCHASES.REPORTS_VIEW',
            'FIXED_ASSET.VIEW', 'FIXED_ASSET.ASSET_VIEW', 'FIXED_ASSET.ASSET_CREATE', 'FIXED_ASSET.ASSET_EDIT',
            'SALES.VIEW', 'SALES.EDIT', 'STOCK.VIEW',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'DASHBOARD.FINANCE_VIEW',
        ],

        // HRL — Human Resource & Legal
        'HRL|MANAGER' => [
            'MASTER.VIEW',
            'MASTER.EMPLOYEE_VIEW', 'MASTER.EMPLOYEE_CREATE', 'MASTER.EMPLOYEE_EDIT', 'MASTER.EMPLOYEE_DELETE',
            'MASTER.DEPARTMENT_VIEW', 'MASTER.DEPARTMENT_CREATE', 'MASTER.DEPARTMENT_EDIT', 'MASTER.DEPARTMENT_DELETE',
            'MASTER.COMPANY_BANK_VIEW', 'MASTER.COMPANY_BANK_CREATE', 'MASTER.COMPANY_BANK_EDIT',
            'PAYROLL.VIEW', 'PAYROLL.SETTINGS', 'PAYROLL.CREATE', 'PAYROLL.EDIT', 'PAYROLL.APPROVE', 'PAYROLL.LOANS_VIEW', 'PAYROLL.PAYSLIP_VIEW', 'PAYROLL.AUDIT',
            'ABSENSI.APPROVE', 'ABSENSI.RECAP', 'ABSENSI.ADMIN_USERS', 'ABSENSI.ADMIN_PINS',
            'HRL.VIEW', 'HRL.REG_ALKES_VIEW', 'HRL.REG_ALKES_EDIT', 'HRL.REG_ALKES_DELETE', 'HRL.COMPLIANCE_EXPORT', 'HRL.IMPORT_REKENING',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_EDIT', 'HRL.PROCESS_DELETE',
            'DASHBOARD.HRL_VIEW',
        ],
        'HRL|STAFF' => [
            'MASTER.VIEW',
            'MASTER.EMPLOYEE_VIEW', 'MASTER.EMPLOYEE_CREATE', 'MASTER.EMPLOYEE_EDIT',
            'PAYROLL.VIEW', 'PAYROLL.LOANS_VIEW', 'PAYROLL.PAYSLIP_VIEW',
            'ABSENSI.RECAP',
            'HRL.VIEW', 'HRL.REG_ALKES_VIEW',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_EDIT',
            'DASHBOARD.HRL_VIEW',
        ],

        // ITC — IT Support (hanya reset password user)
        'ITC|MANAGER' => [
            'MASTER.VIEW',
            'TOOLS.ITC_RESET_PASSWORD',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'HRL.REQ_KENAIKAN_GAJI_CREATE', 'HRL.REQ_REKRUTMEN_CREATE', 'DASHBOARD.ITC_VIEW',
        ],
        'ITC|STAFF' => [
            'MASTER.VIEW',
            'TOOLS.ITC_RESET_PASSWORD',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'DASHBOARD.ITC_VIEW',
        ],

        // MPR — Marketing & Project
        'MPR|MANAGER' => ['MASTER.VIEW', 'SALES.VIEW', 'SALES.PRINT', 'SALES.EXPORT', 'SALES.AUDIT', 'MASTER.CUSTOMER_EXPORT', 'MPR.VIEW', 'MPR.PLAN_VIEW', 'MPR.PLAN_CREATE', 'MPR.PLAN_EDIT', 'MPR.PLAN_DELETE', 'MPR.PLAN_APPROVE', 'MPR.PLAN_IMPORT', 'MPR.PLAN_EXPORT', 'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'HRL.REQ_KENAIKAN_GAJI_CREATE', 'HRL.REQ_REKRUTMEN_CREATE', 'DASHBOARD.SALES_VIEW'],
        'MPR|STAFF'   => ['MASTER.VIEW', 'SALES.VIEW', 'SALES.PRINT', 'MASTER.CUSTOMER_EXPORT', 'MPR.VIEW', 'MPR.PLAN_VIEW', 'MPR.PLAN_CREATE', 'MPR.PLAN_EDIT', 'MPR.PLAN_IMPORT', 'MPR.PLAN_EXPORT', 'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'DASHBOARD.SALES_VIEW'],

        // PQP — Product Quality & Purchasing
        'PQP|MANAGER' => [
            'MASTER.VIEW',
            'MASTER.PRODUCT_VIEW', 'MASTER.PRODUCT_CREATE', 'MASTER.PRODUCT_EDIT', 'MASTER.PRODUCT_DELETE', 'MASTER.PRODUCT_MEDIA_UPLOAD',
            'MASTER.PRODUCT_PACKAGE_VIEW', 'MASTER.PRODUCT_PACKAGE_CREATE', 'MASTER.PRODUCT_PACKAGE_EDIT', 'MASTER.PRODUCT_PACKAGE_DELETE',
            'MASTER.MANUFACTURE_VIEW', 'MASTER.MANUFACTURE_CREATE', 'MASTER.MANUFACTURE_EDIT', 'MASTER.MANUFACTURE_DELETE',
            'MASTER.PRICELIST_BUY_VIEW', 'MASTER.PRICELIST_BUY_CREATE', 'MASTER.PRICELIST_BUY_EDIT', 'MASTER.PRICELIST_BUY_DELETE',
            'MASTER.IMPORT_PRODUCTS',
            'PURCHASES.VIEW', 'PURCHASES.PO_VIEW', 'PURCHASES.PO_CREATE', 'PURCHASES.PO_EDIT', 'PURCHASES.PO_DELETE', 'PURCHASES.PO_APPROVE', 'PURCHASES.PO_PRINT',
            'PURCHASES.GR_VIEW', 'PURCHASES.GR_PROCESS', 'PURCHASES.GR_EDIT', 'PURCHASES.GR_DELETE',
            'PURCHASES.REPORTS_VIEW', 'PURCHASES.EXPORT',
            'WQS.PR_VIEW',
            'STOCK.VIEW', 'PQP.VIEW', 'PQP.EDIT', 'PQP.DELETE',
            'PQP.QUALITY_VIEW', 'PQP.QUALITY_CREATE', 'PQP.QUALITY_EDIT', 'PQP.QUALITY_DELETE',
            'HRL.REG_ALKES_VIEW', 'HRL.COMPLIANCE_EXPORT', 'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'HRL.REQ_KENAIKAN_GAJI_CREATE', 'HRL.REQ_REKRUTMEN_CREATE',
            'DASHBOARD.SCM_VIEW', 'DASHBOARD.PROCUREMENT_VIEW', 'DASHBOARD.REGULATORY_VIEW', 'DASHBOARD.QUALITY_VIEW',
        ],
        'PQP|STAFF' => [
            'MASTER.VIEW',
            'MASTER.PRODUCT_VIEW', 'MASTER.PRODUCT_CREATE', 'MASTER.PRODUCT_EDIT', 'MASTER.PRODUCT_MEDIA_UPLOAD',
            'MASTER.PRODUCT_PACKAGE_VIEW', 'MASTER.PRODUCT_PACKAGE_CREATE', 'MASTER.PRODUCT_PACKAGE_EDIT',
            'MASTER.MANUFACTURE_VIEW', 'MASTER.MANUFACTURE_CREATE', 'MASTER.MANUFACTURE_EDIT',
            'MASTER.PRICELIST_BUY_VIEW', 'MASTER.PRICELIST_BUY_CREATE', 'MASTER.PRICELIST_BUY_EDIT',
            'PURCHASES.VIEW', 'PURCHASES.PO_VIEW', 'PURCHASES.PO_CREATE', 'PURCHASES.PO_EDIT', 'PURCHASES.PO_PRINT',
            'PURCHASES.GR_VIEW', 'PURCHASES.GR_PROCESS',
            'PURCHASES.REPORTS_VIEW',
            'WQS.PR_VIEW',
            'STOCK.VIEW', 'PQP.VIEW',
            'PQP.QUALITY_VIEW', 'PQP.QUALITY_CREATE', 'PQP.QUALITY_EDIT',
            'HRL.REG_ALKES_VIEW', 'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT',
            'DASHBOARD.SCM_VIEW', 'DASHBOARD.PROCUREMENT_VIEW', 'DASHBOARD.REGULATORY_VIEW', 'DASHBOARD.QUALITY_VIEW',
        ],

        // SCM — Supply Chain Management (logistik, forwarding, delivery — BUKAN pembuat PO)
        'SCM|MANAGER' => [
            'MASTER.VIEW',
            'MASTER.VENDOR_VIEW', 'MASTER.VENDOR_CREATE', 'MASTER.VENDOR_EDIT', 'MASTER.VENDOR_DELETE', 'MASTER.IMPORT_VENDORS',
            'PURCHASES.VIEW', 'PURCHASES.PO_VIEW', 'PURCHASES.PO_APPROVE',
            'PURCHASES.GR_VIEW', 'PURCHASES.GR_PROCESS',
            'PURCHASES.FORWARDING_VIEW', 'PURCHASES.FORWARDING_CREATE', 'PURCHASES.FORWARDING_EDIT', 'PURCHASES.FORWARDING_DELETE',
            'PURCHASES.IMPORT_CONTROL', 'PURCHASES.CEISA_PIB', 'PURCHASES.REPORTS_VIEW', 'PURCHASES.EXPORT',
            'SALES.VIEW', 'SALES.EDIT', 'STOCK.VIEW',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'HRL.REQ_KENAIKAN_GAJI_CREATE', 'HRL.REQ_REKRUTMEN_CREATE',
            'DASHBOARD.SCM_VIEW', 'DASHBOARD.PROCUREMENT_VIEW',
        ],
        'SCM|STAFF' => [
            'MASTER.VIEW',
            'MASTER.VENDOR_VIEW', 'MASTER.VENDOR_CREATE', 'MASTER.VENDOR_EDIT',
            'PURCHASES.VIEW', 'PURCHASES.PO_VIEW',
            'PURCHASES.GR_VIEW', 'PURCHASES.GR_PROCESS',
            'PURCHASES.FORWARDING_VIEW', 'PURCHASES.FORWARDING_CREATE', 'PURCHASES.FORWARDING_EDIT',
            'PURCHASES.IMPORT_CONTROL', 'PURCHASES.CEISA_PIB', 'PURCHASES.REPORTS_VIEW',
            'SALES.VIEW', 'SALES.EDIT', 'STOCK.VIEW',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT',
            'DASHBOARD.SCM_VIEW', 'DASHBOARD.PROCUREMENT_VIEW',
        ],

        // SYS — bypass penuh via rbac_is_privileged_session()
        'SYS|MANAGER' => null,
        'SYS|STAFF'   => null,
        'SYS|SYS'     => null,

        // WQS — Warehouse & Quality Stock (pembuat PR, bukan PO)
        'WQS|MANAGER' => [
            'MASTER.VIEW', 'STOCK.VIEW', 'STOCK.CREATE', 'STOCK.AUDIT',
            'WQS.VIEW',
            'WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT', 'WQS.INCOMING_DELETE',
            'WQS.PICKING_VIEW', 'WQS.PICKING_CREATE', 'WQS.PICKING_EDIT', 'WQS.PICKING_DELETE',
            'WQS.ALLOCATION_VIEW', 'WQS.ALLOCATION_CREATE', 'WQS.ALLOCATION_EDIT', 'WQS.ALLOCATION_DELETE',
            'WQS.PR_VIEW', 'WQS.PR_CREATE', 'WQS.PR_EDIT', 'WQS.PR_DELETE', 'WQS.PR_PRINT',
            'SALES.EDIT',
            'PURCHASES.GR_VIEW', 'PURCHASES.GR_PROCESS',
            'SALES.VIEW',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT', 'HRL.REQ_KENAIKAN_GAJI_CREATE', 'HRL.REQ_REKRUTMEN_CREATE',
            'DASHBOARD.WAREHOUSE_VIEW', 'DASHBOARD.SCM_VIEW', 'DASHBOARD.QUALITY_VIEW',
        ],
        'WQS|STAFF' => [
            'MASTER.VIEW', 'STOCK.VIEW',
            'WQS.VIEW',
            'WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT',
            'WQS.PICKING_VIEW', 'WQS.PICKING_CREATE', 'WQS.PICKING_EDIT',
            'WQS.ALLOCATION_VIEW', 'WQS.ALLOCATION_CREATE',
            'WQS.PR_VIEW', 'WQS.PR_CREATE', 'WQS.PR_EDIT', 'WQS.PR_PRINT',
            'SALES.EDIT',
            'PURCHASES.GR_VIEW', 'PURCHASES.GR_PROCESS',
            'SALES.VIEW',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT',
            'DASHBOARD.WAREHOUSE_VIEW', 'DASHBOARD.SCM_VIEW', 'DASHBOARD.QUALITY_VIEW',
        ],

        // BRANCH — Branch & Depo (akses lintas modul, terbatas CREATE+EDIT)
        'BRANCH|STAFF' => [
            'MASTER.VIEW',
            'MASTER.CUSTOMER_VIEW', 'MASTER.CUSTOMER_CREATE', 'MASTER.CUSTOMER_EDIT',
            'MASTER.PIC_CUSTOMER_VIEW', 'MASTER.PIC_CUSTOMER_CREATE', 'MASTER.PIC_CUSTOMER_EDIT',
            'MASTER.VENDOR_VIEW', 'MASTER.VENDOR_CREATE', 'MASTER.VENDOR_EDIT',
            'SALES.VIEW', 'SALES.CREATE', 'SALES.EDIT', 'SALES.PRINT',
            'STOCK.VIEW',
            'WQS.VIEW',
            'WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT',
            'WQS.PICKING_VIEW', 'WQS.PICKING_CREATE', 'WQS.PICKING_EDIT',
            'WQS.PR_VIEW', 'WQS.PR_CREATE', 'WQS.PR_EDIT', 'WQS.PR_PRINT',
            'PURCHASES.VIEW', 'PURCHASES.PO_VIEW', 'PURCHASES.PO_CREATE', 'PURCHASES.PO_EDIT', 'PURCHASES.PO_PRINT',
            'PURCHASES.GR_VIEW', 'PURCHASES.GR_PROCESS',
            'PURCHASES.REPORTS_VIEW',
            'HRL.PROCESS_VIEW', 'HRL.PROCESS_CREATE', 'HRL.PROCESS_EDIT',
            'DASHBOARD.BRANCH_VIEW', 'DASHBOARD.SALES_VIEW', 'DASHBOARD.SCM_VIEW', 'DASHBOARD.WAREHOUSE_VIEW', 'DASHBOARD.FINANCE_VIEW',
        ],
    ];

    $ins = $pdo->prepare("INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag)
                          VALUES (?,?,?,1)
                          ON DUPLICATE KEY UPDATE allow_flag=1");

    foreach ($depts as $dRaw) {
        $d = rbac_norm_code((string)$dRaw);
        if ($d === '') continue;

        foreach ($roles as $rRaw) {
            $r = rbac_norm_code((string)$rRaw);
            if ($r === '') continue;

            // base perms
            foreach ($basePermsAll as $pcRaw) {
                $pc = rbac_norm_code((string)$pcRaw);
                if ($pc === '' || !isset($permSet[$pc])) continue;
                try { $ins->execute([$d, $r, $pc]); } catch (Throwable $e) {}
            }

            $key = $d . '|' . $r;
            if ($d === 'SYS' && ($r === 'SYS' || $r === 'MANAGER' || $r === 'STAFF')) {
                // SYS = admin: dapatkan SEMUA permission dari registry
                foreach (array_keys($permSet) as $pc) {
                    if ($pc === '') continue;
                    try { $ins->execute([$d, $r, $pc]); } catch (Throwable $e) {}
                }
            } elseif (!empty($extraByDeptRole[$key]) && is_array($extraByDeptRole[$key])) {
                foreach ($extraByDeptRole[$key] as $pcRaw) {
                    $pc = rbac_norm_code((string)$pcRaw);
                    if ($pc === '' || !isset($permSet[$pc])) continue;
                    try { $ins->execute([$d, $r, $pc]); } catch (Throwable $e) {}
                }
            }
        }
    }
}

/**
 * Matrix / user override tidak boleh mengizinkan kode yang tidak ada di registry atau is_active=0.
 */
function rbac_registry_perm_active(PDO $pdo, array $candidates): bool {
    if ($candidates === []) {
        return false;
    }
    try {
        $in = implode(',', array_fill(0, count($candidates), '?'));
        $st = $pdo->prepare("SELECT 1 FROM rbac_permissions WHERE perm_code IN ($in) AND is_active = 1 LIMIT 1");
        $st->execute($candidates);

        return $st->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Isi session dept/role/level dari master_system_login bila kosong (session lama, path login ringkas, atau bug migrasi).
 * Tanpa department yang valid, matrix rbac_dept_role_permissions tidak pernah match → seluruh ERP terasa "RBAC mati".
 */
function rbac_hydrate_session_profile_from_login(PDO $pdo): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    $uid = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
    if ($uid <= 0) {
        return;
    }
    $curDept = rbac_norm_code((string)($_SESSION['department'] ?? ($_SESSION['user']['department'] ?? '')));
    $curRole = rbac_norm_code((string)($_SESSION['role'] ?? ($_SESSION['user']['role'] ?? '')));
    $curLevel = rbac_norm_code((string)($_SESSION['level'] ?? ($_SESSION['user']['level'] ?? '')));
    if ($curDept !== '' && $curRole !== '' && $curLevel !== '') {
        return;
    }
    try {
        $st = $pdo->prepare('SELECT role, level, department, office_code FROM master_system_login WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }
        if ($curDept === '') {
            $d = rbac_norm_code((string)($row['department'] ?? ''));
            if ($d !== '') {
                $_SESSION['department'] = $d;
                if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                    $_SESSION['user']['department'] = $d;
                }
            }
        }
        if ($curRole === '') {
            $r = rbac_norm_code((string)($row['role'] ?? ''));
            if ($r !== '') {
                $_SESSION['role'] = $r;
                if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                    $_SESSION['user']['role'] = $r;
                }
            }
        }
        if ($curLevel === '') {
            $l = rbac_norm_code((string)($row['level'] ?? ''));
            if ($l !== '') {
                $_SESSION['level'] = $l;
                if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                    $_SESSION['user']['level'] = $l;
                }
            }
        }
        $oc = rbac_norm_code((string)($row['office_code'] ?? ''));
        if ($oc !== '' && empty($_SESSION['office_code'])) {
            $_SESSION['office_code'] = $oc;
            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                $_SESSION['user']['office_code'] = $oc;
            }
        }
    } catch (Throwable $e) {
        // fail-soft
    }
}

/**
 * Check permission for current session.
 */
function rbac_can(PDO $pdo, string $permCode): bool {
    rbac_hydrate_session_profile_from_login($pdo);

    $candidates = rbac_alias_candidates($permCode);
    if (!$candidates) return false;
    $permCode = $candidates[0];

    // SUPERADMIN/ADMIN always allowed
    if (rbac_is_privileged_session()) return true;

    if (function_exists('auth_user')) {
        $au = auth_user();
        $userId = (int)($au['user_id'] ?? 0);
        $dept = rbac_norm_code((string)($au['department'] ?? ''));
    } else {
        $userId = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
        $dept = rbac_norm_code((string)($_SESSION['department'] ?? $_SESSION['dept'] ?? $_SESSION['user_dept'] ?? ($_SESSION['user']['department'] ?? '')));
    }
    $role = rbac_matrix_role_for_dept_permissions();

    // User override
    if ($userId > 0) {
        $in = implode(',', array_fill(0, count($candidates), '?'));
        $st = $pdo->prepare("SELECT allow_flag FROM rbac_user_permissions WHERE user_id=? AND perm_code IN ($in) ORDER BY allow_flag DESC LIMIT 1");
        $st->execute(array_merge([$userId], $candidates));
        $v = $st->fetchColumn();
        if ($v !== false) {
            if ((int)$v !== 1) {
                return false;
            }

            return rbac_registry_perm_active($pdo, $candidates);
        }
    }

    if ($dept === '' || $role === '') return false;

    $in = implode(',', array_fill(0, count($candidates), '?'));
    $st = $pdo->prepare("SELECT allow_flag FROM rbac_dept_role_permissions
                         WHERE dept_code=? AND role_code=? AND perm_code IN ($in)
                         ORDER BY allow_flag DESC LIMIT 1");
    $st->execute(array_merge([$dept, $role], $candidates));
    $v = $st->fetchColumn();
    if ($v === false) return false;
    if ((int)$v !== 1) return false;

    return rbac_registry_perm_active($pdo, $candidates);
}

function rbac_can2(string $permCode): bool {
    $pdo = rbac_pdo();
    if (!$pdo) { return rbac_is_privileged_session(); }
    return rbac_can($pdo, $permCode);
}

/**
 * Assign FIN Central Approver permissions to MgrFIN_BGR user ONLY.
 * Cash-outflow final approval permissions (AP Payment Approve/Post, GL Reversal Approve)
 * are restricted to exactly one user: MgrFIN_BGR (FIN Pusat).
 *
 * Safe to call repeatedly (upsert idempotent).
 */
function rbac_seed_fin_central_approver(PDO $pdo): int {
    $centralPerms = [
        'PURCHASES.AP_PAYMENT_APPROVE_POST',
        'PURCHASES.GL_REVERSAL_APPROVE',
        'FIN.PAYMENT_APPROVE',
    ];
    $centralUsername = 'MgrFIN_BGR';

    // Lookup user_id
    $userId = null;
    try {
        $st = $pdo->prepare("SELECT id FROM master_system_login WHERE username = ? LIMIT 1");
        $st->execute([$centralUsername]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) $userId = (int)$row['id'];
    } catch (Throwable $e) {
        return 0;
    }

    if (!$userId) return 0;

    $count = 0;
    $ins = $pdo->prepare("INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag)
                          VALUES (?,?,1)
                          ON DUPLICATE KEY UPDATE allow_flag=1");
    foreach ($centralPerms as $pc) {
        $pc = rbac_norm_code($pc);
        try {
            $ins->execute([$userId, $pc]);
            $count++;
        } catch (Throwable $e) {}
    }

    return $count;
}

/**
 * Existing rbac_require renamed to accept PDO explicitly.
 */
function rbac_require_with_pdo(PDO $pdo, string $permCode): void {
    if (!rbac_can($pdo, $permCode)) {
        http_response_code(403);
        echo "<h3>Akses ditolak</h3><p>Permission dibutuhkan: <b>" . htmlspecialchars($permCode) . "</b></p>";
        exit;
    }
}

/**
 * rbac_require — flexible signature:
 *   rbac_require(PDO $pdo, string|array $perm)   — explicit PDO
 *   rbac_require(string $perm)                   — single permission
 *   rbac_require(array  $perms)                  — any-of (OR) — avoids "Array to string conversion"
 */
function rbac_require($permOrPdo, $maybePerm = null): void {
    if ($permOrPdo instanceof PDO) {
        // Called as rbac_require(PDO, string|array)
        $perms = is_array($maybePerm) ? $maybePerm : [(string)$maybePerm];
        $pdo   = $permOrPdo;
        $allowed = rbac_is_privileged_session();
        if (!$allowed) {
            foreach ($perms as $pc) {
                if (rbac_can($pdo, (string)$pc)) { $allowed = true; break; }
            }
        }
        if (!$allowed) {
            http_response_code(403);
            $label = htmlspecialchars(implode(' | ', $perms));
            echo "<h3>Akses ditolak</h3><p>Permission dibutuhkan: <b>{$label}</b></p>";
            exit;
        }
        return;
    }

    // Called as rbac_require(string|array)
    $perms = is_array($permOrPdo) ? $permOrPdo : [(string)$permOrPdo];
    $pdo   = rbac_pdo();
    $allowed = rbac_is_privileged_session();
    if (!$allowed) {
        if ($pdo) {
            foreach ($perms as $pc) {
                if (rbac_can($pdo, (string)$pc)) { $allowed = true; break; }
            }
        }
        // fallback: privileged session already checked above
    }
    if (!$allowed) {
        http_response_code(403);
        $label = htmlspecialchars(implode(' | ', array_map('strval', $perms)));
        echo "<h3>Akses ditolak</h3><p>Permission dibutuhkan: <b>{$label}</b></p>";
        exit;
    }
}
