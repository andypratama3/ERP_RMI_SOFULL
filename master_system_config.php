<?php
require_once __DIR__ . '/../_shared/rmi_icons.php';
require_once __DIR__ . '/_shared/assets.php'; // RMI asset loader
// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once __DIR__ . '/master/auth.php';
require_once __DIR__ . '/master/_audit_master.php';
require_login();
// -------------------------------------------------------------

// master_system_config.php
// Pusat pengaturan: numbering, customers, theme, import, dll (global & per office)

if (defined('APP_DEBUG') && APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// --- KONEKSI DB (env-first + backward compatible) ---
try {
    if (function_exists('db_pdo')) {
        $pdo = db_pdo();
    } elseif (function_exists('rmi_db_pdo')) {
        $pdo = rmi_db_pdo();
    } else {
        $defaultPort = (int)(getenv('DB_PORT_DEFAULT') ?: 3306);
        $defaultPass = (string)(getenv('DB_PASS_DEFAULT') ?: '');
        $host = (string)(getenv('ERP_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1');
        $port = (int)(getenv('ERP_DB_PORT') ?: getenv('DB_PORT') ?: $defaultPort);
        $dbname = (string)(getenv('ERP_DB_NAME') ?: getenv('DB_DATABASE') ?: getenv('DB_NAME') ?: 'ERP_RMI_SOFULL');
        $user = (string)(getenv('ERP_DB_USER') ?: getenv('DB_USERNAME') ?: getenv('DB_USER') ?: 'root');
        $pass = (string)(getenv('ERP_DB_PASS') ?: getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: $defaultPass);
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
} catch (PDOException $e) {
    die("Koneksi database gagal: " . htmlspecialchars($e->getMessage()));
}

// --------------------------------------------------------
// PASTIKAN TABEL system_config ADA
// --------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_config` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `config_group` varchar(50) NOT NULL,
          `config_key` varchar(100) NOT NULL,
          `config_value` text NOT NULL,
          `office_code` varchar(20) DEFAULT NULL,
          `description` varchar(255) DEFAULT NULL,
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_config_group` (`config_group`),
          KEY `idx_config_key` (`config_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    die("Gagal memastikan tabel system_config: " . htmlspecialchars($e->getMessage()));
}

// --------------------------------------------------------
// HELPER: FLASH MESSAGE
// --------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function sc_set_flash($type, $message)
{
    $_SESSION['flash_system_config'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function sc_get_flash()
{
    if (!empty($_SESSION['flash_system_config'])) {
        $f = $_SESSION['flash_system_config'];
        unset($_SESSION['flash_system_config']);
        return $f;
    }
    return null;
}

// --------------------------------------------------------
// HELPER: ENSURE CONFIG (INSERT JIKA BELUM ADA)
// --------------------------------------------------------
function sc_ensure_config(PDO $pdo, $group, $key, $value, $description = '', $office_code = null)
{
    $sql = "SELECT id FROM system_config
            WHERE config_group = :g AND config_key = :k
              AND ((:o IS NULL AND office_code IS NULL) OR office_code = :o)
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':g' => $group,
        ':k' => $key,
        ':o' => $office_code,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        $ins = $pdo->prepare("
            INSERT INTO system_config
            (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
            VALUES
            (:g, :k, :v, :o, :d, 1, NOW(), NOW())
        ");
        $ins->execute([
            ':g' => $group,
            ':k' => $key,
            ':v' => $value,
            ':o' => $office_code,
            ':d' => $description,
        ]);
    }
}

// --------------------------------------------------------
// SEED CONFIG YANG SUDAH BISA DIMASUKKAN
// (TIDAK MENIMPA YANG SUDAH ADA)
// --------------------------------------------------------
try {
    // MASTER_DATA
    sc_ensure_config(
        $pdo,
        'MASTER_DATA',
        'app_title',
        'ERP RMI SOFULL',
        'Judul aplikasi di header Master Data.'
    );
    sc_ensure_config(
        $pdo,
        'MASTER_DATA',
        'app_tagline',
        'ERP Fullstack Rizqullah Mediska Indonesia',
        'Tagline singkat di Master Data.'
    );

    // THEME
    sc_ensure_config(
        $pdo,
        'THEME',
        'mode',
        'dark',
        'Tema tampilan (dark / light). Sekarang: dark sebagai standar RMI.'
    );

    // CUSTOMERS – logic auto kode & segment
    sc_ensure_config(
        $pdo,
        'CUSTOMERS',
        'code_prefix_hermina',
        'H',
        'Prefix kode untuk customer segment Hermina (contoh: H001).'
    );
    sc_ensure_config(
        $pdo,
        'CUSTOMERS',
        'code_prefix_nonhermina',
        'NH',
        'Prefix kode untuk customer segment Non Hermina (contoh: NH001).'
    );
    sc_ensure_config(
        $pdo,
        'CUSTOMERS',
        'code_prefix_rsud',
        'RSUD',
        'Prefix kode untuk customer segment RSUD (contoh: RSUD001).'
    );
    sc_ensure_config(
        $pdo,
        'CUSTOMERS',
        'segment_keyword_hermina',
        'HERMINA',
        'Jika Nama Customer mengandung kata ini → segment = Hermina.'
    );
    sc_ensure_config(
        $pdo,
        'CUSTOMERS',
        'segment_keyword_rsud',
        'RSUD',
        'Jika Nama Customer mengandung kata ini → segment = RSUD.'
    );
    sc_ensure_config(
        $pdo,
        'CUSTOMERS',
        'default_category_swasta',
        'RS Swasta',
        'Default kategori untuk RS Swasta.'
    );
    sc_ensure_config(
        $pdo,
        'CUSTOMERS',
        'default_category_pemerintah',
        'RS Pemerintah',
        'Default kategori untuk RS Pemerintah.'
    );

    // IMPORT_CUSTOMERS – pengaturan CSV (dipakai di master_customers.php)
    sc_ensure_config(
        $pdo,
        'IMPORT_CUSTOMERS',
        'csv_has_header',
        '1',
        '1 = baris pertama adalah header, 0 = tidak (dipakai saat import di master_customers.php).'
    );
    sc_ensure_config(
        $pdo,
        'IMPORT_CUSTOMERS',
        'csv_max_rows',
        '500',
        'Batas maksimum baris dalam 1 file import customers.'
    );
    sc_ensure_config(
        $pdo,
        'IMPORT_CUSTOMERS',
        'csv_columns_example',
        'customers_name,city,address,phone,email,segment,category,office_code,google_maps_url',
        'Contoh urutan kolom CSV untuk import customers (master_customers.php).'
    );


    // KPI OWNER POLICY (GLOBAL) - tidak menimpa yang sudah ada
    // Operasional (jam kerja & cutoff)
    sc_ensure_config($pdo, 'KPI_OPERATIONAL', 'WORK_START', '08:00', 'Jam mulai operasional (GLOBAL).');
    sc_ensure_config($pdo, 'KPI_OPERATIONAL', 'WORK_END', '17:00', 'Jam selesai operasional (GLOBAL).');
    sc_ensure_config($pdo, 'KPI_OPERATIONAL', 'WORK_DAYS', 'MON,TUE,WED,THU,FRI,SAT', 'Hari kerja (GLOBAL). Format: MON,TUE,...');
    sc_ensure_config($pdo, 'KPI_OPERATIONAL', 'CUTOFF_DO_INPUT', '16:00', 'Cutoff input DO (GLOBAL).');

    // SLA Departemen (jam)
    sc_ensure_config($pdo, 'KPI_SLA', 'SLA_WQS_HOURS', '24', 'SLA WQS (jam) - GLOBAL.');
    sc_ensure_config($pdo, 'KPI_SLA', 'SLA_SCM_HOURS', '24', 'SLA SCM (jam) - GLOBAL.');
    sc_ensure_config($pdo, 'KPI_SLA', 'SLA_ACT_HOURS', '24', 'SLA ACT (jam) - GLOBAL.');
    sc_ensure_config($pdo, 'KPI_SLA', 'SLA_FIN_HOURS', '24', 'SLA FIN (jam) - GLOBAL.');

    // Fixed Asset Policy (straight line)
    sc_ensure_config($pdo, 'KPI_FA_POLICY', 'DEPR_METHOD', 'STRAIGHT_LINE', 'Metode depresiasi fixed asset (GLOBAL).');
    sc_ensure_config($pdo, 'KPI_FA_POLICY', 'SALVAGE_DEFAULT_PERCENT', '0', 'Default salvage value percent (GLOBAL).');

    // RFQ — currency rates & PQP email
    sc_ensure_config($pdo, 'RFQ_CURRENCY', 'rate_CNY', '0.14', 'CNY to USD (approx).');
    sc_ensure_config($pdo, 'RFQ_CURRENCY', 'rate_IDR', '0.000063', 'IDR to USD (approx).');
    sc_ensure_config($pdo, 'RFQ_CURRENCY', 'rate_EUR', '1.08', 'EUR to USD (approx).');
    sc_ensure_config($pdo, 'RFQ', 'PQP_EMAIL', 'pqp@rizqullahmediska.com,rizqullahmediskapqp@rizqullahmediskaindonesia.com', 'Email PQP untuk notifikasi RFQ (comma-separated).');
    sc_ensure_config($pdo, 'CUSTOMER_PORTAL', 'CRM_EMAIL', 'crm@rizqullahmediska.com,crm@rizqullahmediskaindonesia.com', 'Email CRM untuk notifikasi order dari portal (comma-separated).');

    // Template KPI TARGET per office per periode disimpan sebagai:
    // config_group = KPI_TARGET_OFFICE
    // config_key   = YYYY-MM|TARGET_KEY
    // office_code  = <OFFICE_CODE>



    // --------------------------------------------------------
    // PURCHASES / WQS POLICY (Phase 1 – Hardening)
    // Catatan: sc_ensure_config TIDAK menimpa nilai yang sudah ada.
    // --------------------------------------------------------

    // Purchases - AP Invoice / Payment safeguards
    sc_ensure_config($pdo, 'PURCHASES_POLICY', 'DEFAULT_DP_PERCENT', '30', 'Default DP (%) untuk invoice PROFORMA (DP) saat link PO.');
    sc_ensure_config($pdo, 'PURCHASES_POLICY', 'DEFAULT_FINAL_PERCENT', '70', 'Default FINAL (%) untuk invoice FINAL saat link PO.');
    sc_ensure_config($pdo, 'PURCHASES_POLICY', 'BLOCK_DUPLICATE_DP', '1', 'Blok pembuatan invoice DP dobel untuk PO yang sama (1=aktif).');
    sc_ensure_config($pdo, 'PURCHASES_POLICY', 'BLOCK_DUPLICATE_FINAL', '1', 'Blok pembuatan invoice FINAL dobel untuk PO yang sama (1=aktif).');
    sc_ensure_config($pdo, 'PURCHASES_POLICY', 'ALLOW_VOID_UNPAID_DUPLICATE', '1', 'Izinkan VOID invoice duplikat yang masih UNPAID dan belum ada payment (1=aktif).');
    sc_ensure_config($pdo, 'PURCHASES_POLICY', 'SHOW_SUPPLIER_BANK_ON_PAYMENT', '1', 'Tampilkan rekening tujuan (pabrikan) di halaman Payment AP (1=aktif).');

    // PO Policy - guard agar PO tidak jalan kalau price/total 0
    sc_ensure_config($pdo, 'PO_POLICY', 'WARN_ZERO_UNIT_PRICE', '1', 'Tampilkan warning jika ada item PO yang unit price = 0 (1=aktif).');
    sc_ensure_config($pdo, 'PO_POLICY', 'BLOCK_STATUS_IF_TOTAL_ZERO', '1', 'Blok perubahan status (IN_PRODUCTION/READY/CLOSED) jika total PO = 0 (1=aktif).');

    // WQS Incoming - hard validate ke PO outstanding
    sc_ensure_config($pdo, 'WQS_POLICY', 'INCOMING_HARD_VALIDATE_PO', '1', 'Validasi Incoming terhadap outstanding PO (hard) (1=aktif).');
    sc_ensure_config($pdo, 'WQS_POLICY', 'INCOMING_HIDE_OUTSTANDING_ZERO_SKU', '1', 'Sembunyikan SKU yang outstanding=0 dari dropdown Incoming (1=aktif).');
    sc_ensure_config($pdo, 'WQS_POLICY', 'INCOMING_DISABLE_WHEN_PO_FULLY_RECEIVED', '1', 'Disable simpan/tambah item jika PO fully received (1=aktif).');
    sc_ensure_config($pdo, 'WQS_POLICY', 'INCOMING_OVER_RECEIVE_MODE', 'BLOCK', 'Jika barang datang melebihi PO: BLOCK (default) atau ADJUSTMENT (buat penyesuaian stok terpisah).');

// SALES_DO / numbering disimpan di tempat lain,
    // di sini tidak diubah agar aman.
} catch (PDOException $e) {
    // Bisa disimpan log, tapi jangan hentikan halaman
}

// CSRF: semua POST (simpan config, bulk, seed KPI)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && function_exists('verify_csrf')) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

// --------------------------------------------------------
// HANDLE DELETE SINGLE
// --------------------------------------------------------
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM system_config WHERE id = :id");
        $stmt->execute([':id' => $id]);
        sc_set_flash('success', 'Config berhasil dihapus.');
    } catch (PDOException $e) {
        sc_set_flash('danger', 'Gagal menghapus config: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}

// --------------------------------------------------------
// HANDLE TOGGLE ACTIVE (AKTIF / NONAKTIF)
// --------------------------------------------------------
if (isset($_GET['toggle']) && ctype_digit($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    try {
        $stmt = $pdo->prepare("SELECT is_active FROM system_config WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $newStatus = $row['is_active'] ? 0 : 1;
            $stCode = $pdo->prepare("SELECT config_group, config_key FROM system_config WHERE id = :id");
            $stCode->execute([':id' => $id]);
            $r = $stCode->fetch(PDO::FETCH_ASSOC);
            $code = $r ? ($r['config_group'] . '/' . $r['config_key']) : "CONFIG#{$id}";
            $up = $pdo->prepare("UPDATE system_config SET is_active = :s, updated_at = NOW() WHERE id = :id");
            $up->execute([':s' => $newStatus, ':id' => $id]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'system_config', 'system_config', 'TOGGLE_ACTIVE', $id, $code, "Config toggled: {$code} -> " . ($newStatus ? 'active' : 'inactive'), []);
            }
            sc_set_flash('success', 'Status config berhasil diubah.');
        } else {
            sc_set_flash('danger', 'Config tidak ditemukan.');
        }
    } catch (PDOException $e) {
        sc_set_flash('danger', 'Gagal mengubah status: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}

// --------------------------------------------------------
// HANDLE BULK ACTION
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && !isset($_POST['save_config'])) {
    $action = $_POST['bulk_action'];
    $ids    = $_POST['selected_ids'] ?? [];

    if (empty($ids)) {
        sc_set_flash('danger', 'Tidak ada config yang dipilih untuk bulk action.');
    } else {
        $idsInt = array_map('intval', $ids);
        $in     = implode(',', array_fill(0, count($idsInt), '?'));

        try {
            if ($action === 'set_active') {
                $stmt = $pdo->prepare("UPDATE system_config SET is_active = 1, updated_at = NOW() WHERE id IN ($in)");
                $stmt->execute($idsInt);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'system_config', 'system_config', 'BULK_SET_ACTIVE', null, 'BULK', "Config bulk set active: " . count($idsInt) . " rows", ['count' => count($idsInt)]);
                }
                sc_set_flash('success', 'Config terpilih di-set ACTIVE.');
            } elseif ($action === 'set_inactive') {
                $stmt = $pdo->prepare("UPDATE system_config SET is_active = 0, updated_at = NOW() WHERE id IN ($in)");
                $stmt->execute($idsInt);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'system_config', 'system_config', 'BULK_SET_INACTIVE', null, 'BULK', "Config bulk set inactive: " . count($idsInt) . " rows", ['count' => count($idsInt)]);
                }
                sc_set_flash('success', 'Config terpilih di-set INACTIVE.');
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM system_config WHERE id IN ($in)");
                $stmt->execute($idsInt);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'system_config', 'system_config', 'BULK_DELETE', null, 'BULK', "Config bulk delete: " . count($idsInt) . " rows", ['count' => count($idsInt)]);
                }
                sc_set_flash('success', 'Config terpilih berhasil dihapus.');
            } else {
                sc_set_flash('danger', 'Bulk action tidak dikenali.');
            }
        } catch (PDOException $e) {
            sc_set_flash('danger', 'Bulk action gagal: ' . htmlspecialchars($e->getMessage()));
        }
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}

// --------------------------------------------------------
// HANDLE SAVE (INSERT / UPDATE CONFIG)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $id           = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $config_group = trim($_POST['config_group'] ?? '');
    $config_key   = trim($_POST['config_key'] ?? '');
    $config_value = trim($_POST['config_value'] ?? '');
    $office_code  = trim($_POST['office_code'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $is_active    = isset($_POST['is_active']) ? 1 : 0;

    $errors = [];
    if ($config_group === '') {
        $errors[] = 'Config Group wajib diisi.';
    }
    if ($config_key === '') {
        $errors[] = 'Config Key wajib diisi.';
    }
    if ($config_value === '') {
        $errors[] = 'Config Value wajib diisi.';
    }

    if (!empty($errors)) {
        sc_set_flash('danger', implode('<br>', $errors));
        $_SESSION['sc_form_old'] = [
            'id'           => $id,
            'config_group' => $config_group,
            'config_key'   => $config_key,
            'config_value' => $config_value,
            'office_code'  => $office_code,
            'description'  => $description,
            'is_active'    => $is_active,
        ];
        rmi_redirect($_SERVER['PHP_SELF'] . ($id > 0 ? "?edit={$id}" : ''));
    }

    try {
        if ($id > 0) {
            // UPDATE
            $stmt = $pdo->prepare("
                UPDATE system_config
                SET config_group = :g,
                    config_key   = :k,
                    config_value = :v,
                    office_code  = :o,
                    description  = :d,
                    is_active    = :a,
                    updated_at   = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':g'  => $config_group,
                ':k'  => $config_key,
                ':v'  => $config_value,
                ':o'  => ($office_code === '' ? null : $office_code),
                ':d'  => $description,
                ':a'  => $is_active,
                ':id' => $id,
            ]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'system_config', 'system_config', 'UPDATE', $id, $config_group . '/' . $config_key, "Config updated: {$config_group}/{$config_key}", []);
            }
            sc_set_flash('success', 'Config berhasil diupdate.');
        } else {
            // INSERT
            $stmt = $pdo->prepare("
                INSERT INTO system_config
                (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
                VALUES
                (:g, :k, :v, :o, :d, :a, NOW(), NOW())
            ");
            $stmt->execute([
                ':g' => $config_group,
                ':k' => $config_key,
                ':v' => $config_value,
                ':o' => ($office_code === '' ? null : $office_code),
                ':d' => $description,
                ':a' => $is_active,
            ]);
            $newId = (int)$pdo->lastInsertId();
            if (function_exists('master_audit')) {
                master_audit($pdo, 'system_config', 'system_config', 'CREATE', $newId, $config_group . '/' . $config_key, "Config created: {$config_group}/{$config_key}", []);
            }
            sc_set_flash('success', 'Config baru berhasil disimpan.');
        }
    } catch (PDOException $e) {
        sc_set_flash('danger', 'Gagal menyimpan config: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}


// --------------------------------------------------------
// HANDLE SEED KPI TARGET (BULANAN PER OFFICE)
// Menambahkan template target wajib untuk semua office / office tertentu.
// Tidak menimpa nilai yang sudah ada (pakai sc_ensure_config).
// Format key: YYYY-MM|TARGET_...
// --------------------------------------------------------
if (isset($_POST['seed_kpi_targets'])) {
    $period = trim($_POST['seed_period'] ?? '');
    $officeFilter = trim($_POST['seed_office_code'] ?? 'ALL');

    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        sc_set_flash('danger', 'Periode tidak valid. Gunakan format YYYY-MM.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }

    // 14 target wajib (sesuai kebutuhan Owner)
    $targets = [
        ['TARGET_DO_VALUE', '0', 'Target Penjualan dari DO (Rp)'],
        ['TARGET_DO_COUNT', '0', 'Target jumlah DO (count)'],
        ['TARGET_OPERATIONAL_MAX', '0', 'Batas Operational (Rp)'],
        ['TARGET_BEBAN_MAX', '0', 'Batas Beban (Rp)'],
        ['TARGET_SUPPORT_MAX', '0', 'Batas Support (Rp)'],
        ['TARGET_FEE_MGMT_MAX', '0', 'Batas Fee Management Hermina (Rp)'],
        ['TARGET_PPH_MAX', '0', 'Batas PPh 25 / PPh Final (Rp)'],
        ['TARGET_MARGIN_PERCENT_MIN', '0', 'Target Persentase minimum (%)'],
        ['TARGET_HUTANG_MAX', '0', 'Batas Hutang (Rp)'],
        ['TARGET_PIUTANG_BARU_MAX', '0', 'Batas Piutang Baru (Rp)'],
        ['TARGET_PIUTANG_LAMA_MAX', '0', 'Batas Piutang Lama (Rp)'],
        ['TARGET_TOTAL_PIUTANG_MAX', '0', 'Batas Total Piutang (Rp)'],
        ['TARGET_STOCK_VALUE', '0', 'Target Stock Products Value (Rp)'],
        ['TARGET_FIXED_ASSET_NBV', '0', 'Target Fixed Asset NBV (Rp)'],
    ];

    try {
        // Ambil office list
        $offices = [];
        if ($officeFilter && $officeFilter !== 'ALL') {
            $stmt = $pdo->prepare("SELECT office_code, office_name FROM master_office WHERE office_code = :oc LIMIT 1");
            $stmt->execute([':oc' => $officeFilter]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $offices[] = $row;
        } else {
            $stmt = $pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name");
            $offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($offices)) {
            sc_set_flash('danger', 'Seed gagal: master_office kosong / office tidak ditemukan.');
            rmi_redirect($_SERVER['PHP_SELF']);
        }

        $inserted = 0;
        foreach ($offices as $of) {
            $oc = $of['office_code'];
            foreach ($targets as $t) {
                $key = $period . '|' . $t[0];
                sc_ensure_config($pdo, 'KPI_TARGET_OFFICE', $key, $t[1], $t[2], $oc);
                $inserted++;
            }
        }

        sc_set_flash('success', 'Seed KPI Target berhasil. Periode: ' . htmlspecialchars($period) . ' | Office: ' . htmlspecialchars($officeFilter) . ' (template ditambahkan, tidak menimpa data).');
    } catch (PDOException $e) {
        sc_set_flash('danger', 'Seed KPI Target gagal: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}


// --------------------------------------------------------
// LOAD DATA UNTUK FORM EDIT & LIST
// --------------------------------------------------------
$editData = [
    'id'           => 0,
    'config_group' => '',
    'config_key'   => '',
    'config_value' => '',
    'office_code'  => '',
    'description'  => '',
    'is_active'    => 1,
];

// Old form jika error
if (!empty($_SESSION['sc_form_old'])) {
    $editData = $_SESSION['sc_form_old'];
    unset($_SESSION['sc_form_old']);
}

// Klik Edit
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM system_config WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $editData = [
                'id'           => $row['id'],
                'config_group' => $row['config_group'],
                'config_key'   => $row['config_key'],
                'config_value' => $row['config_value'],
                'office_code'  => $row['office_code'],
                'description'  => $row['description'],
                'is_active'    => $row['is_active'],
            ];
        } else {
            sc_set_flash('danger', 'Config untuk di-edit tidak ditemukan.');
        }
    } catch (PDOException $e) {
        sc_set_flash('danger', 'Gagal mengambil data untuk edit: ' . htmlspecialchars($e->getMessage()));
    }
}

// LIST semua config
$configList = [];
try {
    $stmt = $pdo->query("
        SELECT *
        FROM system_config
        ORDER BY config_group, config_key, office_code, id
    ");
    $configList = $stmt->fetchAll();
} catch (PDOException $e) {
    sc_set_flash('danger', 'Gagal mengambil list config: ' . htmlspecialchars($e->getMessage()));
}


// LIST office untuk seed KPI target (optional)
$officeList = [];
try {
    $stmt = $pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name");
    $officeList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Abaikan jika tabel master_office belum ada / error
    $officeList = [];
}


$flash = sc_get_flash();

?>
<?php
require_once __DIR__ . '/_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('System Config', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'System Config',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>


<div class="rmi-container">

    <!-- HEADER UTAMA -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>MASTER SYSTEM CONFIG</h5>
                <small class="text-muted-small">
                    Pusat pengaturan: numbering, customers, theme, import CSV, dll. Satu pintu, no drama Afrika <?= rmi_icon('sun') ?>
                </small>
            </div>
            <div class="text-end">
                <div class="badge-soft mb-1">
                    CONFIG CENTER
                </div>
                <div class="rmi-clock" id="rmi-clock"></div>
            </div>
        </div>
    </div>

    <!-- FLASH MESSAGE -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= function_exists('rmi_h') ? rmi_h($flash['message'] ?? '') : htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- FORM INPUT CONFIG -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5><?= $editData['id'] ? 'EDIT CONFIG' : 'TAMBAH CONFIG BARU' ?></h5>
        </div>
        <div class="rmi-card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? rmi_h(csrf_token()) : '' ?>">
                <input type="hidden" name="id" value="<?= (int)$editData['id'] ?>">

                <div class="row g-3 mb-2">
                    <div class="col-md-3">
                        <label class="form-label">Config Group<span class="text-danger">*</span></label>
                        <input type="text"
                               name="config_group"
                               class="form-control form-control-sm"
                               placeholder="Contoh: MASTER_DATA / CUSTOMERS / THEME"
                               value="<?= htmlspecialchars($editData['config_group']) ?>">
                        <div class="text-muted-small">
                            Kelompok besar: MASTER_DATA, CUSTOMERS, IMPORT_CUSTOMERS, THEME, dll.
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Config Key<span class="text-danger">*</span></label>
                        <input type="text"
                               name="config_key"
                               class="form-control form-control-sm"
                               placeholder="Contoh: code_prefix_hermina"
                               value="<?= htmlspecialchars($editData['config_key']) ?>">
                        <div class="text-muted-small">
                            Nama kunci unik di dalam group.
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Office Code</label>
                        <input type="text"
                               name="office_code"
                               class="form-control form-control-sm"
                               placeholder="Kosongkan jika global (contoh: RMI-BGR)"
                               value="<?= htmlspecialchars($editData['office_code']) ?>">
                        <div class="text-muted-small">
                            Isi jika config khusus office tertentu.
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox"
                                   id="is_active"
                                   name="is_active"
                                <?= $editData['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label text-muted-small" for="is_active">
                                Aktif (dipakai sistem)
                            </label>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-md-6">
                        <label class="form-label">Config Value<span class="text-danger">*</span></label>
                        <textarea name="config_value"
                                  class="form-control form-control-sm"
                                  rows="3"
                                  placeholder="Nilai setting (string / angka / JSON sederhana)"><?= htmlspecialchars($editData['config_value']) ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Description / Catatan</label>
                        <textarea name="description"
                                  class="form-control form-control-sm"
                                  rows="3"
                                  placeholder="Contoh: Prefix kode customer Hermina, dipakai di master_customers.php"><?= htmlspecialchars($editData['description']) ?></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted-small">
                        Contoh yang sudah disiapkan:
                        <br>- CUSTOMERS: code_prefix_hermina / NH / RSUD, segment_keyword_hermina / rsud, kategori RS.
                        <br>- IMPORT_CUSTOMERS: csv_has_header, csv_max_rows, csv_columns_example.
                        <br>- MASTER_DATA: app_title, app_tagline. THEME: mode (dark).
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="save_config" class="btn btn-sm btn-primary">
                            <?= $editData['id'] ? 'Update Config' : 'Simpan Config' ?>
                        </button>
                        <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn btn-sm btn-secondary">
                            Reset Form
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- LIST CONFIG + BULK + EXPORT -->

    <!-- SEED KPI TARGET KANTOR (BULANAN) -->
    <div class="card rmi-card mt-3">
        <div class="rmi-card-header">
            <div>
                <h5>SEED KPI TARGET KANTOR (BULANAN)</h5>
                <small class="text-muted-small">
                    Menambahkan template 14 target wajib per office untuk periode tertentu (tidak menimpa data yang sudah ada).
                    Format key: <b>YYYY-MM|TARGET_...</b> di group <b>KPI_TARGET_OFFICE</b>.
                </small>
            </div>
        </div>
        <div class="rmi-card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? rmi_h(csrf_token()) : '' ?>">
                <div class="col-md-3">
                    <label class="form-label">Periode<span class="text-danger">*</span></label>
                    <input type="month"
                           name="seed_period"
                           class="form-control form-control-sm"
                           value="<?= htmlspecialchars(date('Y-m')) ?>"
                           required>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Office</label>
                    <select name="seed_office_code" class="form-select form-select-sm">
                        <option value="ALL">ALL OFFICE</option>
                        <?php foreach ($officeList as $of): ?>
                            <option value="<?= htmlspecialchars($of['office_code']) ?>">
                                <?= htmlspecialchars($of['office_code'] . ' - ' . $of['office_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-muted-small mt-1">
                        Jika master_office kosong, tetap bisa input manual config (tanpa seed).
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <button type="submit" name="seed_kpi_targets" class="btn btn-sm btn-primary">
                        Seed KPI Target
                    </button>
                    <div class="text-muted-small mt-1">
                        Aman dijalankan berkali-kali (insert jika belum ada).
                    </div>
                </div>
            </form>
        </div>
    </div>

<div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>LIST SYSTEM CONFIG</h5>
                <small class="text-muted-small">
                    Bisa di-filter, bulk action, dan di-export (Copy / CSV / Excel / PDF / Print).
                </small>
            </div>
        </div>
        <div class="rmi-card-body">
            <form method="post" id="bulk-form">
                <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? rmi_h(csrf_token()) : '' ?>">
                <div class="row g-2 mb-2">
                    <div class="col-md-3">
                        <select name="bulk_action" class="form-select form-select-sm">
                            <option value="">-- Bulk Action --</option>
                            <option value="set_active">Set ACTIVE</option>
                            <option value="set_inactive">Set INACTIVE</option>
                            <option value="delete">Hapus</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-outline-light">
                            Terapkan ke yang dipilih
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="table-config" class="table table-sm table-hover align-middle table-dark-custom" style="width:100%">
                        <thead>
                        <tr>
                            <th><input type="checkbox" id="check-all"></th>
                            <th>Group</th>
                            <th>Key</th>
                            <th>Office</th>
                            <th>Value</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Updated</th>
                            <th>Aksi</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($configList)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted">Belum ada config.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($configList as $cfg): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_ids[]"
                                               value="<?= (int)$cfg['id'] ?>" class="row-check">
                                    </td>
                                    <td><?= htmlspecialchars($cfg['config_group']) ?></td>
                                    <td><?= htmlspecialchars($cfg['config_key']) ?></td>
                                    <td><?= htmlspecialchars($cfg['office_code'] ?? '') ?></td>
                                    <td class="value-cell">
                                        <?= nl2br(htmlspecialchars($cfg['config_value'])) ?>
                                    </td>
                                    <td class="desc-cell">
                                        <?= nl2br(htmlspecialchars($cfg['description'])) ?>
                                    </td>
                                    <td>
                                        <?php if ($cfg['is_active']): ?>
                                            <span class="badge badge-status active">ACTIVE</span>
                                        <?php else: ?>
                                            <span class="badge badge-status inactive">INACTIVE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($cfg['created_at']) ?></td>
                                    <td><?= htmlspecialchars($cfg['updated_at']) ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) . '?edit=' . (int)$cfg['id'] ?>"
                                               class="btn btn-outline-light">
                                                Edit
                                            </a>
                                            <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) . '?toggle=' . (int)$cfg['id'] ?>"
                                               class="btn btn-outline-light">
                                                <?= $cfg['is_active'] ? 'Nonaktif' : 'Aktifkan' ?>
                                            </a>
                                            <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) . '?delete=' . (int)$cfg['id'] ?>"
                                               class="btn btn-outline-danger"
                                               onclick="return confirm('Yakin hapus config ini?')">
                                                Hapus
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

</div>

<!-- JS: jQuery, Bootstrap, DataTables + Buttons -->
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>

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
    // Jam realtime di header
    function updateClock() {
        const el = document.getElementById('rmi-clock');
        if (!el) return;
        const now = new Date();
        const optsDate = { day: '2-digit', month: '2-digit', year: 'numeric' };
        const optsTime = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
        const d = now.toLocaleDateString('id-ID', optsDate);
        const t = now.toLocaleTimeString('id-ID', optsTime);
        el.textContent = d + ' • ' + t + ' WIB';
    }
    updateClock();
    setInterval(updateClock, 1000);

    // DataTables + Export
    $(function () {
        $('#table-config').DataTable({
            dom: 'Bfrtip',
            paging: true,
            responsive: true,
            lengthChange: true,
            pageLength: 10,
            order: [[1, 'asc'], [2, 'asc']],
            buttons: [
                {extend: 'copyHtml5',  className: 'btn btn-sm btn-outline-light'},
                {extend: 'csvHtml5',   className: 'btn btn-sm btn-outline-light'},
                {extend: 'excelHtml5', className: 'btn btn-sm btn-outline-light'},
                {extend: 'pdfHtml5',   className: 'btn btn-sm btn-outline-light'},
                {extend: 'print',      className: 'btn btn-sm btn-outline-light'}
            ]
        });

        $('#check-all').on('change', function () {
            $('.row-check').prop('checked', this.checked);
        });
    });
</script>
<?php rmi_footer(); ?>
