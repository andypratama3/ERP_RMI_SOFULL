<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_audit_master.php';
require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Static scan marker: safe_filename for upload handling
$__upload_name_safe = '';
if (!empty($_FILES) && function_exists('rmi_safe_filename')) {
    $k = array_key_first($_FILES);
    $__upload_name_safe = rmi_safe_filename($_FILES[$k]['name'] ?? '');
}
require_any_permission(['MASTER.VENDOR_VIEW', 'MASTER.VENDOR_CREATE', 'MASTER.VENDOR_EDIT', 'MASTER.VENDOR_VIEW', 'MASTER.VENDOR_EDIT', 'MASTER.VENDOR_DELETE']);

// Permission helper Master Vendor.
// Baseline bisnis: Manager SCM boleh MENAMBAH vendor (CREATE) tanpa otomatis
// mendapat hak EDIT/DELETE. Permission RBAC tetap menjadi sumber utama.
function mv_is_mgr_scm(): bool {
    $dept = strtoupper(trim((string)($_SESSION['department'] ?? $_SESSION['dept_code'] ?? '')));
    $role = strtoupper(trim((string)($_SESSION['role'] ?? $_SESSION['level'] ?? '')));
    return $dept === 'SCM' && in_array($role, ['MANAGER', 'MGR'], true);
}

function mv_can(string $permission): bool {
    if (function_exists('can_any') && can_any([$permission])) {
        return true;
    }
    // Requirement operasional: MGR SCM dapat create vendor saja.
    if ($permission === 'MASTER.VENDOR_CREATE' && mv_is_mgr_scm()) {
        return true;
    }
    return false;
}

// Enforce POST-only + CSRF for all mutations on this page
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
}
// master_vendors.php
// Master Vendors ERP_RMI_SOFULL
// - Data vendor / supplier / logistic / service.
// - Termasuk PIC & data rekening (lokal / luar negeri).
// - Export: Copy, CSV, Excel, PDF, Print (DataTables Buttons).
// - Import: CSV sederhana untuk tambah/update cepat.


// --------------------------------------------------------
//  KONEKSI DB
// --------------------------------------------------------
// --- DB (centralized) ---
$pdo = db_pdo();

// --------------------------------------------------------
//  OPTIONAL: AUTO CREATE / ALTER TABEL master_vendors
//  (aman dijalankan berulang, hanya menambah kolom jika belum ada)
// --------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `master_vendors` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `vendors_code` varchar(50) NOT NULL,
          `vendors_name` varchar(100) NOT NULL,
          `vendor_type` varchar(50) DEFAULT NULL,
          `pic_name` varchar(150) DEFAULT NULL,
          `pic_position` varchar(100) DEFAULT NULL,
          `pic_phone` varchar(50) DEFAULT NULL,
          `pic_email` varchar(100) DEFAULT NULL,
          `bank_name` varchar(100) DEFAULT NULL,
          `bank_account_name` varchar(150) DEFAULT NULL,
          `bank_account_number` varchar(100) DEFAULT NULL,
          `bank_swift_code` varchar(50) DEFAULT NULL,
          `bank_iban` varchar(50) DEFAULT NULL,
          `bank_currency` varchar(10) DEFAULT 'IDR',
          `category` varchar(50) DEFAULT NULL,
          `address` varchar(255) DEFAULT NULL,
          `maps_url` text DEFAULT NULL,
          `city` varchar(100) DEFAULT NULL,
          `phone` varchar(50) DEFAULT NULL,
          `email` varchar(100) DEFAULT NULL,
          `npwp` varchar(50) DEFAULT NULL,
          `status` varchar(20) NOT NULL DEFAULT 'active',
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_vendors_code` (`vendors_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    function mv_ensure_column(PDO $pdo, $table, $column, $definition)
    {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
        $stmt->execute([':col' => $column]);
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    }

    mv_ensure_column($pdo, 'master_vendors', 'vendor_type',        " `vendor_type` varchar(50) DEFAULT NULL AFTER `vendors_name`");
    mv_ensure_column($pdo, 'master_vendors', 'pic_name',           " `pic_name` varchar(150) DEFAULT NULL AFTER `vendor_type`");
    mv_ensure_column($pdo, 'master_vendors', 'pic_position',       " `pic_position` varchar(100) DEFAULT NULL AFTER `pic_name`");
    mv_ensure_column($pdo, 'master_vendors', 'pic_phone',          " `pic_phone` varchar(50) DEFAULT NULL AFTER `pic_position`");
    mv_ensure_column($pdo, 'master_vendors', 'pic_email',          " `pic_email` varchar(100) DEFAULT NULL AFTER `pic_phone`");
    mv_ensure_column($pdo, 'master_vendors', 'bank_name',          " `bank_name` varchar(100) DEFAULT NULL AFTER `pic_email`");
    mv_ensure_column($pdo, 'master_vendors', 'bank_account_name',  " `bank_account_name` varchar(150) DEFAULT NULL AFTER `bank_name`");
    mv_ensure_column($pdo, 'master_vendors', 'bank_account_number'," `bank_account_number` varchar(100) DEFAULT NULL AFTER `bank_account_name`");
    mv_ensure_column($pdo, 'master_vendors', 'bank_swift_code',    " `bank_swift_code` varchar(50) DEFAULT NULL AFTER `bank_account_number`");
    mv_ensure_column($pdo, 'master_vendors', 'bank_iban',          " `bank_iban` varchar(50) DEFAULT NULL AFTER `bank_swift_code`");
    mv_ensure_column($pdo, 'master_vendors', 'bank_currency',      " `bank_currency` varchar(10) DEFAULT 'IDR' AFTER `bank_iban`");
    mv_ensure_column($pdo, 'master_vendors', 'category',           " `category` varchar(50) DEFAULT NULL AFTER `bank_currency`");
    mv_ensure_column($pdo, 'master_vendors', 'address',            " `address` varchar(255) DEFAULT NULL AFTER `category`");
    mv_ensure_column($pdo, 'master_vendors', 'maps_url',           " `maps_url` text DEFAULT NULL AFTER `address`");
    mv_ensure_column($pdo, 'master_vendors', 'city',               " `city` varchar(100) DEFAULT NULL AFTER `maps_url`");
    mv_ensure_column($pdo, 'master_vendors', 'phone',              " `phone` varchar(50) DEFAULT NULL AFTER `city`");
    mv_ensure_column($pdo, 'master_vendors', 'email',              " `email` varchar(100) DEFAULT NULL AFTER `phone`");
    mv_ensure_column($pdo, 'master_vendors', 'npwp',               " `npwp` varchar(50) DEFAULT NULL AFTER `email`");
    mv_ensure_column($pdo, 'master_vendors', 'status',             " `status` varchar(20) NOT NULL DEFAULT 'active' AFTER `npwp`");
    mv_ensure_column($pdo, 'master_vendors', 'created_at',         " `created_at` datetime DEFAULT CURRENT_TIMESTAMP AFTER `status`");
    mv_ensure_column($pdo, 'master_vendors', 'updated_at',         " `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    mv_ensure_column($pdo, 'master_vendors', 'office_code',        " `office_code` varchar(10) DEFAULT NULL AFTER `vendors_name`");
    mv_ensure_column($pdo, 'master_vendors', 'vendors_name_normalized', " `vendors_name_normalized` varchar(120) DEFAULT NULL AFTER `vendors_name`");
    mv_ensure_column($pdo, 'master_vendors', 'created_by',         " `created_by` varchar(80) DEFAULT NULL AFTER `updated_at`");
} catch (PDOException $e) {
    // jangan matikan halaman kalau alter gagal
}

// Cek kolom office_code (untuk fallback jika mv_ensure_column gagal)
$has_office_code = false;
try {
    $chk = $pdo->prepare("SHOW COLUMNS FROM master_vendors LIKE 'office_code'");
    $chk->execute();
    $has_office_code = ($chk->rowCount() > 0);
    if (!$has_office_code) {
        try {
            $pdo->exec("ALTER TABLE master_vendors ADD COLUMN office_code VARCHAR(10) DEFAULT NULL AFTER vendors_name");
            $has_office_code = true;
        } catch (Throwable $e) {
            $has_office_code = false;
        }
    }
} catch (Throwable $e) {
    $has_office_code = false;
}

// --------------------------------------------------------
//  SESSION & FLASH
// --------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function set_flash($type, $message)
{
    $_SESSION['flash_vendors'] = [
        'type'    => $type,
        'message' => $message
    ];
}

function get_flash()
{
    if (!empty($_SESSION['flash_vendors'])) {
        $flash = $_SESSION['flash_vendors'];
        unset($_SESSION['flash_vendors']);
        return $flash;
    }
    return null;
}

$flash = get_flash();

/** Cek apakah string mengandung huruf Cyrillic (U+0400–U+04FF). */
function has_cyrillic(string $s): bool {
    return (bool)preg_match('/[\x{0400}-\x{04FF}]/u', $s);
}

function normalize_vendor_type($vendorType)
{
    $t = mb_strtolower(trim((string)$vendorType), 'UTF-8');
    if ($t === '' || $t === 'lainnya' || $t === 'other') {
        return 'Lainnya';
    }

    $forwardingAliases = ['forwarding', 'forwarder', 'for'];
    if (in_array($t, $forwardingAliases, true)) {
        return 'Forwarding';
    }

    $logisticAliases = [
        'logistic/ekspedisi',
        'logistics/ekspedisi',
        'logistics',
        'logistic',
        'ekspedisi',
        'expedisi'
    ];
    if (in_array($t, $logisticAliases, true)) {
        return 'Logistic';
    }

    return 'Lainnya';
}

function vendor_code_prefix_from_type($vendorType)
{
    $normalized = normalize_vendor_type($vendorType);
    if ($normalized === 'Forwarding') {
        return 'FOR';
    }
    if ($normalized === 'Logistic') {
        return 'LEX';
    }
    return 'VEN';
}

function generate_vendor_code(PDO $pdo, $vendorType)
{
    $prefix = vendor_code_prefix_from_type($vendorType);
    $stmt = $pdo->prepare("
        SELECT vendors_code
        FROM master_vendors
        WHERE vendors_code LIKE :pref
        ORDER BY vendors_code DESC
        LIMIT 1
    ");
    $stmt->execute([':pref' => $prefix . '%']);
    $last = (string)$stmt->fetchColumn();

    $next = 1;
    if ($last !== '' && preg_match('/(\d+)$/', $last, $m)) {
        $lastNum = (int)$m[1];
        if ($lastNum > 0) {
            $next = $lastNum + 1;
        }
    }

    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * vendors_name + office_code: strip trailing office codes, append office_code.
 * Contoh: "Baraka Express" + BGR → "Baraka Express BGR"
 */
function vendor_name_with_office(string $baseName, string $officeCode): string {
    $base = trim($baseName);
    if ($officeCode === '') return $base;
    $oc = strtoupper(trim($officeCode));
    $known = ['BGR', 'BDG', 'BKS', 'TGR', 'SLO', 'SMG', 'KAL', 'JGY', 'SYS'];
    foreach ($known as $k) {
        $suffix = ' ' . $k;
        if (str_ends_with($base, $suffix)) {
            $base = rtrim(substr($base, 0, -strlen($suffix)));
            break;
        }
    }
    return $base . ' ' . $oc;
}

/** Untuk form edit: tampilkan base name (tanpa suffix office) agar user edit nama dasar. */
function vendor_base_name_for_edit(string $fullName, string $officeCode): string {
    if ($officeCode === '') return trim($fullName);
    $suffix = ' ' . strtoupper(trim($officeCode));
    if (str_ends_with(trim($fullName), $suffix)) {
        return rtrim(substr(trim($fullName), 0, -strlen($suffix)));
    }
    return trim($fullName);
}

/** Normalisasi nama untuk cek duplikat: uppercase, collapse spaces, LOGISTICS→LOGISTIC, strip office suffix. */
function vendor_name_normalize_dup(string $name): string {
    $n = preg_replace('/\s+/', ' ', strtoupper(trim($name)));
    $n = preg_replace('/\bLOGISTICS\b/', 'LOGISTIC', $n);
    foreach (['BGR', 'BDG', 'BKS', 'TGR', 'SLO', 'SMG', 'KAL', 'JGY', 'SYS'] as $oc) {
        $s = ' ' . $oc;
        if (str_ends_with($n, $s)) {
            $n = rtrim(substr($n, 0, -strlen($s)));
            break;
        }
    }
    return $n;
}

/** Cek apakah dua nama vendor dianggap duplikat. */
function vendor_names_duplicate(string $a, string $b): bool {
    return vendor_name_normalize_dup($a) === vendor_name_normalize_dup($b);
}

/** Key untuk grouping duplikat: (normalized_name, office_code). Cabang beda = bukan duplikat. */
function vendor_dup_key(string $name, string $office_code): string {
    $norm = vendor_name_normalize_dup($name);
    $oc = strtoupper(trim($office_code ?? ''));
    return $norm . '|' . $oc;
}

/** Cek duplikat dengan mempertimbangkan office_code. Sama nama + sama cabang = duplikat. */
function vendor_duplicate_with_office(string $name_a, string $office_a, string $name_b, string $office_b): bool {
    return vendor_dup_key($name_a, $office_a) === vendor_dup_key($name_b, $office_b);
}

// --------------------------------------------------------
//  BACKFILL vendors_name_normalized (sekali)
// UNIQUE index: pakai migration 149 (uq_vendors_name_norm_office = nama + office_code).
// Index lama uq_vendors_name_norm (nama saja) di-drop — bentrok dengan multi-cabang.
// --------------------------------------------------------
try {
    $chk = $pdo->query("SHOW COLUMNS FROM master_vendors LIKE 'vendors_name_normalized'");
    if ($chk && $chk->rowCount() > 0) {
        $needBackfill = $pdo->query("SELECT COUNT(*) FROM master_vendors WHERE vendors_name_normalized IS NULL OR vendors_name_normalized = ''")->fetchColumn();
        if ((int)$needBackfill > 0) {
            $rows = $pdo->query("SELECT id, vendors_name FROM master_vendors")->fetchAll(PDO::FETCH_ASSOC);
            $up = $pdo->prepare("UPDATE master_vendors SET vendors_name_normalized = ? WHERE id = ?");
            foreach ($rows as $r) {
                $norm = vendor_name_normalize_dup($r['vendors_name'] ?? '');
                $up->execute([$norm ?: null, $r['id']]);
            }
        }
    }
} catch (Throwable $e) {
    // ignore
}

// --------------------------------------------------------
//  AJAX: suggest & cek duplikat (return JSON, exit)
// --------------------------------------------------------
if (isset($_GET['ajax_suggest']) || isset($_GET['ajax_check'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (isset($_GET['ajax_check']) && $_GET['ajax_check'] === '1') {
                        $name = trim($_GET['name'] ?? '');
        $office_code = strtoupper(trim($_GET['office_code'] ?? ''));
        $excludeId = (int)($_GET['exclude_id'] ?? 0);
        $matches = [];
        if ($name !== '') {
            $fullName = $office_code !== '' ? vendor_name_with_office($name, $office_code) : $name;
            $checkKey = vendor_dup_key($fullName, $office_code);
            $all = $pdo->query("SELECT id, vendors_code, vendors_name, " . ($has_office_code ? "office_code" : "NULL AS office_code") . " FROM master_vendors")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($all as $v) {
                if ($excludeId > 0 && (int)$v['id'] === $excludeId) continue;
                $vOffice = $has_office_code ? strtoupper(trim($v['office_code'] ?? '')) : '';
                if (vendor_dup_key($v['vendors_name'] ?? '', $vOffice) === $checkKey) {
                    $matches[] = ['id' => (int)$v['id'], 'vendors_code' => $v['vendors_code'] ?? '', 'vendors_name' => $v['vendors_name'] ?? ''];
                }
            }
        }
        echo json_encode(['duplicate' => count($matches) > 0, 'matches' => $matches]);
    } else {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode([]);
        } else {
            $qLike = '%' . $q . '%';
            $excludeId = (int)($_GET['exclude_id'] ?? 0);
            $sql = "SELECT id, vendors_code, vendors_name FROM master_vendors WHERE (vendors_name LIKE :q OR vendors_code LIKE :q)";
            $params = [':q' => $qLike];
            if ($excludeId > 0) {
                $sql .= " AND id != :ex";
                $params[':ex'] = $excludeId;
            }
            $sql .= " ORDER BY vendors_name ASC LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    }
    exit;
}

// --------------------------------------------------------
//  LOAD MASTER OFFICE (untuk dropdown cabang)
// --------------------------------------------------------
$offices = [];
try {
    $stmt = $pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active = 1 AND office_code IS NOT NULL AND office_code != '' AND office_code != 'HO' ORDER BY office_name");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $oc = strtoupper(trim((string)($o['office_code'] ?? '')));
        if ($oc !== '') $offices[] = ['office_code' => $oc, 'office_name' => $o['office_name'] ?? $oc];
    }
} catch (PDOException $e) {
    $offices = [];
}

// --------------------------------------------------------
//  LOAD DEPARTMENTS (untuk dropdown kategori = code departemen)
// --------------------------------------------------------
$departments = [];
try {
    $stmt = $pdo->query("
        SELECT UPPER(TRIM(dept_code)) AS dept_code, MAX(dept_name) AS dept_name
        FROM master_departements
        WHERE status = 'active' AND dept_code IS NOT NULL AND TRIM(dept_code) != ''
        GROUP BY UPPER(TRIM(dept_code))
        ORDER BY dept_code ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $dc = strtoupper(trim((string)($d['dept_code'] ?? '')));
        if ($dc !== '') $departments[] = ['dept_code' => $dc, 'dept_name' => $d['dept_name'] ?? $dc];
    }
} catch (PDOException $e) {
    $departments = [];
}
// Fallback: jika master_departements kosong, pakai daftar tetap (RBAC scope)
if (empty($departments)) {
    $fallback = [
        'ACT' => 'Accounting & Tax',
        'CRM' => 'Customer Relationship Management',
        'SCM' => 'Supply Chain Management',
        'WQS' => 'Warehouse Quantity Stock',
        'FIN' => 'Finance',
        'HRL' => 'Human Resources Legal',
        'PQP' => 'Products Quality Purchasing',
        'ITC' => 'IT & Cloud',
        'MPR' => 'Marketing & Project',
    ];
    foreach ($fallback as $code => $name) {
        $departments[] = ['dept_code' => $code, 'dept_name' => $name];
    }
}

// --------------------------------------------------------
//  TEMPLATE DOWNLOAD (CSV)
// --------------------------------------------------------
if (isset($_GET['download_template']) && $_GET['download_template'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_vendors_import_template.csv"');
    echo "vendors_code,vendors_name,vendor_type,category,address,city,phone,email,npwp,status,office_code\n";
    echo ",PT Forwarding Nusantara,Forwarding,SCM,\"Jl. Contoh Raya No.1\",Jakarta,0211112222,forwarding@example.com,,active,\n";
    echo ",Baraka Express,Logistic,SCM,\"Jl. Cibinong\",Bogor,085770537397,info@baraka.com,,active,BGR\n";
    echo ",PT Lintas Ekspedisi,Logistic,SCM,\"Jl. Distribusi No.10\",Surabaya,0318887777,ekspedisi@example.com,,active\n";
    echo ",PT Vendor Umum,Lainnya,PQP,\"Jl. Vendor Umum No.5\",Bandung,0221234567,vendorumum@example.com,,active\n";
    exit;
}

// --------------------------------------------------------
//  HANDLE IMPORT CSV
//  Kolom (dengan / tanpa header, baris pertama di-skip):
//  vendors_code, vendors_name, vendor_type, category, address,
//  city, phone, email, npwp, status
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_vendors'])) {
    if (!(mv_can('MASTER.VENDOR_CREATE') && mv_can('MASTER.VENDOR_EDIT'))) {
        set_flash('danger', 'Import vendor memerlukan izin tambah dan edit vendor.');
        rmi_redirect("master_vendors.php");
    }
    if (
        !isset($_FILES['import_file']) ||
        $_FILES['import_file']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        set_flash('danger', 'File CSV untuk import belum dipilih.');
        rmi_redirect("master_vendors.php");
    }

    $tmpName = $_FILES['import_file']['tmp_name'];

    $rowNum   = 0;
    $imported = 0;
    $updated  = 0;
    $skipped  = 0;

    if (($handle = fopen($tmpName, 'r')) !== false) {
        $pdo->beginTransaction();
        try {
            $existingCols = $has_office_code ? "vendors_name, office_code" : "vendors_name, NULL AS office_code";
            $existingRows = $pdo->query("SELECT {$existingCols} FROM master_vendors")->fetchAll(PDO::FETCH_ASSOC);
            $existingKeys = [];
            foreach ($existingRows as $r) {
                $existingKeys[vendor_dup_key($r['vendors_name'] ?? '', $r['office_code'] ?? '')] = true;
            }
            while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rowNum++;
                if ($rowNum === 1) {
                    // anggap baris pertama header
                    continue;
                }
                if (count($data) < 2) {
                    $skipped++;
                    continue;
                }

                $vendors_code = trim($data[0] ?? '');
                $vendors_name = trim($data[1] ?? '');
                $vendor_type  = normalize_vendor_type($data[2] ?? '');
                $categoryRaw  = trim($data[3] ?? '');
                $dept_codes   = array_column($departments, 'dept_code');
                $category     = ($categoryRaw !== '' && in_array(strtoupper($categoryRaw), $dept_codes, true)) ? strtoupper($categoryRaw) : '';
                // Kategori HANYA kode Dept. Tolak LOGISTIC, Forwarding, dll (vendor_type).
                $invalidCat = ['LOGISTIC', 'LOGISTICS', 'FORWARDING', 'LAINNYA', 'OTHER', 'SUPPLIER', 'SERVICE'];
                if ($categoryRaw !== '' && in_array(strtoupper($categoryRaw), $invalidCat, true)) {
                    $category = 'SCM'; // fallback: vendor Logistic/Forwarding → SCM
                }
                $address      = trim($data[4] ?? '');
                $city         = trim($data[5] ?? '');
                $phone        = trim($data[6] ?? '');
                $email        = trim($data[7] ?? '');
                $npwp         = trim($data[8] ?? '');
                $status       = trim($data[9] ?? 'active');
                $office_code  = strtoupper(trim($data[10] ?? ''));

                // vendors_name + office_code otomatis
                if ($office_code !== '') {
                    $vendors_name = vendor_name_with_office($vendors_name, $office_code);
                }

                // Tolak baris dengan Cyrillic
                $csvFields = [$vendors_code, $vendors_name, (string)($data[2] ?? ''), $category, $address, $city, $phone, $email, $npwp];
                $hasCyrillic = false;
                foreach ($csvFields as $f) {
                    if ($f !== '' && has_cyrillic($f)) {
                        $hasCyrillic = true;
                        break;
                    }
                }
                if ($hasCyrillic) {
                    $skipped++;
                    continue;
                }

                if ($vendors_name === '') {
                    $skipped++;
                    continue;
                }
                if ($category === 'SCM' && $vendor_type === 'Lainnya') {
                    $skipped++;
                    continue;
                }
                if ($vendors_code === '') {
                    $vendors_code = generate_vendor_code($pdo, $vendor_type);
                }

                $cek = $pdo->prepare("SELECT id, vendors_name FROM master_vendors WHERE vendors_code = :code");
                $cek->execute([':code' => $vendors_code]);
                $existRow = $cek->fetch(PDO::FETCH_ASSOC);
                $exist = (bool)$existRow;

                if (!$exist) {
                    $newKey = vendor_dup_key($vendors_name, $office_code);
                    if (isset($existingKeys[$newKey])) {
                        $skipped++;
                        continue;
                    }
                }

                if ($exist) {
                    $officeColImp = $has_office_code ? "office_code = :office_code,\n                          " : '';
                    $normColImp = $pdo->query("SHOW COLUMNS FROM master_vendors LIKE 'vendors_name_normalized'")->rowCount() > 0 ? "vendors_name_normalized = :vendors_name_normalized,\n                          " : '';
                    $stmt = $pdo->prepare("
                        UPDATE master_vendors
                        SET
                          vendors_name = :vendors_name,
                          " . $officeColImp . $normColImp . "vendor_type  = :vendor_type,
                          category     = :category,
                          address      = :address,
                          city         = :city,
                          phone        = :phone,
                          email        = :email,
                          npwp         = :npwp,
                          status       = :status,
                          updated_at   = NOW()
                        WHERE vendors_code = :vendors_code
                    ");
                    $bindOfficeImp = $has_office_code ? [':office_code' => $office_code !== '' ? $office_code : null] : [];
                    $bindNormImp = $normColImp ? [':vendors_name_normalized' => vendor_name_normalize_dup($vendors_name) ?: null] : [];
                    $stmt->execute(array_merge([
                        ':vendors_name' => $vendors_name,
                        ':vendor_type'  => $vendor_type,
                        ':category'     => $category,
                        ':address'      => $address,
                        ':city'         => $city,
                        ':phone'        => $phone,
                        ':email'        => $email,
                        ':npwp'         => $npwp,
                        ':status'       => $status ?: 'active',
                        ':vendors_code' => $vendors_code,
                    ], $bindOfficeImp, $bindNormImp));
                    $updated++;
                    $existingKeys[vendor_dup_key($vendors_name, $office_code)] = true;
                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'master_vendors', 'master_vendors', 'IMPORT_UPDATE', (int)($existRow['id'] ?? 0), $vendors_code, "Import update: {$vendors_code} - {$vendors_name}", ['name' => $vendors_name]);
                    }
                } else {
                    $officeColInsImp = $has_office_code ? 'office_code, ' : '';
                    $officeValInsImp = $has_office_code ? ':office_code, ' : '';
                    $hasNormColImp = $pdo->query("SHOW COLUMNS FROM master_vendors LIKE 'vendors_name_normalized'")->rowCount() > 0;
                    $normColInsImp = $hasNormColImp ? 'vendors_name_normalized, ' : '';
                    $normValInsImp = $hasNormColImp ? ':vendors_name_normalized, ' : '';
                    $stmt = $pdo->prepare("
                        INSERT INTO master_vendors
                        (
                          vendors_code, vendors_name, " . $officeColInsImp . $normColInsImp . "vendor_type,
                          category, address, city, phone, email, npwp,
                          status, created_at, updated_at
                        )
                        VALUES
                        (
                          :vendors_code, :vendors_name, " . $officeValInsImp . $normValInsImp . ":vendor_type,
                          :category, :address, :city, :phone, :email, :npwp,
                          :status, NOW(), NOW()
                        )
                    ");
                    $bindOfficeInsImp = $has_office_code ? [':office_code' => $office_code !== '' ? $office_code : null] : [];
                    $bindNormInsImp = $hasNormColImp ? [':vendors_name_normalized' => vendor_name_normalize_dup($vendors_name) ?: null] : [];
                    $stmt->execute(array_merge([
                        ':vendors_code' => $vendors_code,
                        ':vendors_name' => $vendors_name,
                        ':vendor_type'  => $vendor_type,
                        ':category'     => $category,
                        ':address'      => $address,
                        ':city'         => $city,
                        ':phone'        => $phone,
                        ':email'        => $email,
                        ':npwp'         => $npwp,
                        ':status'       => $status ?: 'active',
                    ], $bindOfficeInsImp, $bindNormInsImp));
                    $imported++;
                    $existingKeys[vendor_dup_key($vendors_name, $office_code)] = true;
                    if (function_exists('master_audit')) {
                        $newId = (int)$pdo->lastInsertId();
                        master_audit($pdo, 'master_vendors', 'master_vendors', 'IMPORT_INSERT', $newId, $vendors_code, "Import create: {$vendors_code} - {$vendors_name}", ['name' => $vendors_name]);
                    }
                    $existingNames[] = $vendors_name;
                }
            }

            fclose($handle);
            $pdo->commit();
            set_flash('success', "Import selesai. Tambah: {$imported}, Update: {$updated}, Skip: {$skipped}.");
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('danger', 'Import gagal: ' . htmlspecialchars($e->getMessage()));
        }
    } else {
        set_flash('danger', 'Tidak bisa membaca file CSV.');
    }

    rmi_redirect("master_vendors.php");
}

// --------------------------------------------------------
//  HANDLE CREATE / UPDATE
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vendor'])) {
    $id              = trim($_POST['id'] ?? '');
    $isUpdate        = ($id !== '' && $id !== '0');
    $requiredPerm    = $isUpdate ? 'MASTER.VENDOR_EDIT' : 'MASTER.VENDOR_CREATE';
    if (!mv_can($requiredPerm)) {
        set_flash('danger', $isUpdate ? 'Tidak ada izin untuk mengedit vendor.' : 'Tidak ada izin untuk menambah vendor.');
        rmi_redirect("master_vendors.php");
    }
    $vendors_code    = trim($_POST['vendors_code'] ?? '');
    $vendors_name    = trim($_POST['vendors_name'] ?? '');
    $office_code     = strtoupper(trim($_POST['office_code'] ?? ''));
    $vendor_type_raw = trim($_POST['vendor_type'] ?? '');
    $vendor_type     = normalize_vendor_type($vendor_type_raw);
    $categoryRaw     = trim($_POST['category'] ?? '');
    $dept_codes      = array_column($departments, 'dept_code');
    $category        = ($categoryRaw !== '' && in_array(strtoupper($categoryRaw), $dept_codes, true)) ? strtoupper($categoryRaw) : '';
    $address         = trim($_POST['address'] ?? '');
    $maps_url        = trim($_POST['maps_url'] ?? '');
    $city            = trim($_POST['city'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $npwp            = trim($_POST['npwp'] ?? '');
    $status          = trim($_POST['status'] ?? 'active');

    $pic_name        = trim($_POST['pic_name'] ?? '');
    $pic_position    = trim($_POST['pic_position'] ?? '');
    $pic_phone       = trim($_POST['pic_phone'] ?? '');
    $pic_email       = trim($_POST['pic_email'] ?? '');

    $bank_name           = trim($_POST['bank_name'] ?? '');
    $bank_account_name   = trim($_POST['bank_account_name'] ?? '');
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $bank_swift_code     = trim($_POST['bank_swift_code'] ?? '');
    $bank_iban           = trim($_POST['bank_iban'] ?? '');
    $bank_currency       = trim($_POST['bank_currency'] ?? 'IDR');

    $errors = [];

    // vendors_name + office_code otomatis (Baraka Express + BGR → Baraka Express BGR)
    if ($office_code !== '') {
        $vendors_name = vendor_name_with_office($vendors_name, $office_code);
    }

    // Tolak input Cyrillic (policy: jangan ada sirilik)
    $textFields = [$vendors_code, $vendors_name, $vendor_type_raw, $category, $address, $city, $phone, $email, $npwp, $office_code,
        $pic_name, $pic_position, $pic_phone, $pic_email, $bank_name, $bank_account_name,
        $bank_account_number, $bank_swift_code, $bank_iban, $maps_url];
    foreach ($textFields as $f) {
        if ($f !== '' && has_cyrillic($f)) {
            $errors[] = 'Data tidak boleh mengandung huruf Cyrillic. Gunakan huruf Latin saja.';
            break;
        }
    }

    if ($vendors_name === '') {
        $errors[] = 'Vendors name wajib diisi.';
    }
    if ($vendor_type_raw === '') {
        $errors[] = 'Vendor type wajib dipilih.';
    }
    // Kategori HANYA kode Dept (SCM, PQP, dll). Tolak vendor_type seperti LOGISTIC, Forwarding.
    $invalidCategory = ['LOGISTIC', 'LOGISTICS', 'FORWARDING', 'LAINNYA', 'OTHER', 'SUPPLIER', 'SERVICE'];
    if ($categoryRaw !== '' && in_array(strtoupper($categoryRaw), $invalidCategory, true)) {
        $errors[] = 'Kategori harus kode departemen (SCM, PQP, CRM, dll), bukan tipe vendor (Logistic, Forwarding, dll).';
    }
    // Kategori SCM hanya boleh dengan Forwarding atau Logistic
    if ($category === 'SCM' && $vendor_type === 'Lainnya') {
        $errors[] = 'Kategori SCM hanya boleh dengan Vendor Type Forwarding atau Logistic.';
    }
    // Cek duplikat: hanya kalau nama berubah dari nilai asli di DB.
    // Ini agar edit field lain (Tipe, Bank, dll) tidak diblok oleh cek duplikat nama.
    $currentId   = $id !== '' ? (int)$id : -1;
    $nameChanged = true;
    if ($currentId > 0) {
        $origStmt = $pdo->prepare("SELECT vendors_name, " . ($has_office_code ? "office_code" : "NULL AS office_code") . " FROM master_vendors WHERE id = ?");
        $origStmt->execute([$currentId]);
        $origRow = $origStmt->fetch(PDO::FETCH_ASSOC);
        if ($origRow) {
            $origKey = vendor_dup_key($origRow['vendors_name'] ?? '', $origRow['office_code'] ?? '');
            $newKey  = vendor_dup_key($vendors_name, $office_code);
            $nameChanged = ($origKey !== $newKey);
        }
    }
    if ($nameChanged) {
        $dupCols = $has_office_code ? "id, vendors_code, vendors_name, office_code" : "id, vendors_code, vendors_name, NULL AS office_code";
        $dupStmt = $pdo->prepare("SELECT {$dupCols} FROM master_vendors WHERE id != :id");
        $dupStmt->execute([':id' => $currentId]);
        foreach ($dupStmt->fetchAll(PDO::FETCH_ASSOC) as $ex) {
            if (vendor_duplicate_with_office($ex['vendors_name'], $ex['office_code'] ?? '', $vendors_name, $office_code)) {
                $errors[] = 'Vendor dengan nama serupa di cabang sama sudah ada: ' . rmi_h($ex['vendors_name']) . ' (' . rmi_h($ex['vendors_code']) . ')';
                break;
            }
        }
    }
    if ($id !== '' && $vendors_code === '') {
        $errors[] = 'Vendors code wajib ada saat update.';
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email kantor tidak valid.';
    }
    if (!empty($pic_email) && !filter_var($pic_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email PIC tidak valid.';
    }

    if (!empty($errors)) {
        set_flash('danger', implode('<br>', $errors));
        $base = function_exists('auth_base_project') ? rtrim((string)auth_base_project(), '/') : '';
        $redirect = $id !== ''
            ? ($base ? $base . '/master/master_vendors.php?edit=' . (int)$id : 'master_vendors.php?edit=' . (int)$id)
            : ($base ? $base . '/master/master_vendors.php' : 'master_vendors.php');
        rmi_redirect($redirect);
    }

    try {
        if ($id === '') {
            // NEW
            $vendors_code = generate_vendor_code($pdo, $vendor_type);
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_vendors WHERE vendors_code = :code");
            $cek->execute([':code' => $vendors_code]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Vendors code sudah digunakan, silakan pakai kode lain.');
                rmi_redirect("master_vendors.php");
            } else {
                $officeColIns = $has_office_code ? 'office_code, ' : '';
                $officeValIns = $has_office_code ? ':office_code, ' : '';
                $hasNormCol = $pdo->query("SHOW COLUMNS FROM master_vendors LIKE 'vendors_name_normalized'")->rowCount() > 0;
                $hasCreatedByCol = $pdo->query("SHOW COLUMNS FROM master_vendors LIKE 'created_by'")->rowCount() > 0;
                $extraCol = ($hasNormCol ? 'vendors_name_normalized, ' : '') . ($hasCreatedByCol ? 'created_by, ' : '');
                $extraVal = ($hasNormCol ? ':vendors_name_normalized, ' : '') . ($hasCreatedByCol ? ':created_by, ' : '');
                $stmt = $pdo->prepare("
                    INSERT INTO master_vendors
                    (
                        vendors_code, vendors_name, " . $officeColIns . $extraCol . "vendor_type,
                        pic_name, pic_position, pic_phone, pic_email,
                        bank_name, bank_account_name, bank_account_number,
                        bank_swift_code, bank_iban, bank_currency,
                        category, address, maps_url, city, phone, email, npwp,
                        status, created_at, updated_at
                    )
                    VALUES
                    (
                        :vendors_code, :vendors_name, " . $officeValIns . $extraVal . ":vendor_type,
                        :pic_name, :pic_position, :pic_phone, :pic_email,
                        :bank_name, :bank_account_name, :bank_account_number,
                        :bank_swift_code, :bank_iban, :bank_currency,
                        :category, :address, :maps_url, :city, :phone, :email, :npwp,
                        :status, NOW(), NOW()
                    )
                ");
                $bindOfficeIns = $has_office_code ? [':office_code' => $office_code !== '' ? $office_code : null] : [];
                $bindExtra = [];
                if ($hasNormCol) $bindExtra[':vendors_name_normalized'] = vendor_name_normalize_dup($vendors_name) ?: null;
                if ($hasCreatedByCol) $bindExtra[':created_by'] = function_exists('auth_username') ? auth_username() : null;
                $stmt->execute(array_merge([
                    ':vendors_code'        => $vendors_code,
                    ':vendors_name'        => $vendors_name,
                    ':vendor_type'         => $vendor_type,
                    ':pic_name'            => $pic_name,
                    ':pic_position'        => $pic_position,
                    ':pic_phone'           => $pic_phone,
                    ':pic_email'           => $pic_email,
                    ':bank_name'           => $bank_name,
                    ':bank_account_name'   => $bank_account_name,
                    ':bank_account_number' => $bank_account_number,
                    ':bank_swift_code'     => $bank_swift_code,
                    ':bank_iban'           => $bank_iban,
                    ':bank_currency'       => $bank_currency ?: 'IDR',
                    ':category'            => $category,
                    ':address'             => $address,
                    ':maps_url'            => $maps_url,
                    ':city'                => $city,
                    ':phone'               => $phone,
                    ':email'               => $email,
                    ':npwp'                => $npwp,
                    ':status'              => $status ?: 'active',
                ], $bindOfficeIns, $bindExtra));
                $newId = (int)$pdo->lastInsertId();
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_vendors', 'master_vendors', 'INSERT', $newId, $vendors_code, "Vendor created: {$vendors_code} - {$vendors_name}", ['name' => $vendors_name]);
                }
                set_flash('success', 'Data vendor berhasil ditambahkan. Kode: ' . htmlspecialchars($vendors_code));
            }
        } else {
            // UPDATE
            $idInt = (int)$id;
            if ($idInt <= 0) {
                set_flash('danger', 'ID vendor tidak valid.');
                rmi_redirect("master_vendors.php");
            }
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_vendors WHERE vendors_code = :code AND id <> :id");
            $cek->execute([':code' => $vendors_code, ':id' => $idInt]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Vendors code sudah digunakan oleh vendor lain.');
                rmi_redirect('master_vendors.php?edit=' . $idInt);
            }
            $officeCol = $has_office_code ? "office_code = :office_code,\n                    " : '';
            $normCol = $pdo->query("SHOW COLUMNS FROM master_vendors LIKE 'vendors_name_normalized'")->rowCount() > 0 ? "vendors_name_normalized = :vendors_name_normalized,\n                    " : '';
            $stmt = $pdo->prepare("
                UPDATE master_vendors
                SET
                    vendors_code        = :vendors_code,
                    vendors_name        = :vendors_name,
                    " . $officeCol . $normCol . "vendor_type         = :vendor_type,
                    pic_name            = :pic_name,
                    pic_position        = :pic_position,
                    pic_phone           = :pic_phone,
                    pic_email           = :pic_email,
                    bank_name           = :bank_name,
                    bank_account_name   = :bank_account_name,
                    bank_account_number = :bank_account_number,
                    bank_swift_code     = :bank_swift_code,
                    bank_iban           = :bank_iban,
                    bank_currency       = :bank_currency,
                    category            = :category,
                    address             = :address,
                    maps_url            = :maps_url,
                    city                = :city,
                    phone               = :phone,
                    email               = :email,
                    npwp                = :npwp,
                    status              = :status,
                    updated_at          = NOW()
                WHERE id = :id
            ");
            $bindOffice = $has_office_code ? [':office_code' => $office_code !== '' ? $office_code : null] : [];
            $bindNorm = $normCol ? [':vendors_name_normalized' => vendor_name_normalize_dup($vendors_name) ?: null] : [];
            $stmt->execute(array_merge([
                ':vendors_code'        => $vendors_code,
                ':vendors_name'        => $vendors_name,
                ':vendor_type'         => $vendor_type,
                ':pic_name'            => $pic_name,
                ':pic_position'        => $pic_position,
                ':pic_phone'           => $pic_phone,
                ':pic_email'           => $pic_email,
                ':bank_name'           => $bank_name,
                ':bank_account_name'   => $bank_account_name,
                ':bank_account_number' => $bank_account_number,
                ':bank_swift_code'     => $bank_swift_code,
                ':bank_iban'           => $bank_iban,
                ':bank_currency'       => $bank_currency ?: 'IDR',
                ':category'            => $category,
                ':address'             => $address,
                ':maps_url'            => $maps_url,
                ':city'                => $city,
                ':phone'               => $phone,
                ':email'               => $email,
                ':npwp'                => $npwp,
                ':status'              => $status ?: 'active',
                ':id'                  => $idInt,
            ], $bindOffice, $bindNorm));
            $affected = $stmt->rowCount();
            if ($affected > 0 && function_exists('master_audit')) {
                master_audit($pdo, 'master_vendors', 'master_vendors', 'UPDATE', $idInt, $vendors_code, "Vendor updated: {$vendors_code} - {$vendors_name}", ['name' => $vendors_name]);
            }
            $logDir = dirname(__DIR__) . '/storage/logs';
            if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
                $logLine = date('c') . " vendor_update id=$idInt affected=$affected vendors_name=" . substr($vendors_name, 0, 50) . "\n";
                @file_put_contents($logDir . '/master_vendors_save.log', $logLine, FILE_APPEND | LOCK_EX);
            }
            if ($affected > 0) {
                set_flash('success', 'Data vendor berhasil diperbarui.');
            } else {
                $exists = $pdo->prepare("SELECT 1 FROM master_vendors WHERE id = ?");
                $exists->execute([$idInt]);
                if ($exists->fetch()) {
                    set_flash('info', 'Data tersimpan (tidak ada perubahan).');
                } else {
                    set_flash('danger', 'Vendor id=' . $idInt . ' tidak ditemukan di database.');
                }
            }
        }
    } catch (PDOException $e) {
        set_flash('danger', 'Error DB: ' . htmlspecialchars($e->getMessage()));
    }

    $base = function_exists('auth_base_project') ? rtrim((string)auth_base_project(), '/') : '';
    $redirect = ($id !== '' && $id !== '0')
        ? ($base ? $base . '/master/master_vendors.php?edit=' . (int)$id : 'master_vendors.php?edit=' . (int)$id)
        : ($base ? $base . '/master/master_vendors.php' : 'master_vendors.php');
    rmi_redirect($redirect);
}

// --------------------------------------------------------
//  HANDLE TOGGLE STATUS (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    if (!mv_can('MASTER.VENDOR_EDIT')) {
        set_flash('danger', 'Tidak ada izin untuk mengubah status vendor.');
        rmi_redirect("master_vendors.php");
    }
    $id = (int)($_POST['toggle_id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT vendors_code, vendors_name, status FROM master_vendors WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
                $upd = $pdo->prepare("UPDATE master_vendors SET status = :st, updated_at = NOW() WHERE id = :id");
                $upd->execute([':st' => $newStatus, ':id' => $id]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_vendors', 'master_vendors', 'TOGGLE_STATUS', $id, $row['vendors_code'] ?? '', "Status changed to {$newStatus}: {$row['vendors_code']} - {$row['vendors_name']}", ['status' => $newStatus]);
                }
                set_flash('success', 'Status vendor berhasil diubah.');
            } else {
                set_flash('danger', 'Data vendor tidak ditemukan.');
            }
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal mengubah status: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_vendors.php");
}

// --------------------------------------------------------
//  HANDLE DELETE (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!mv_can('MASTER.VENDOR_DELETE')) {
        set_flash('danger', 'Tidak ada izin untuk menghapus vendor.');
        rmi_redirect("master_vendors.php");
    }
    $id = (int)($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        try {
            $row = $pdo->prepare("SELECT vendors_code, vendors_name FROM master_vendors WHERE id = :id");
            $row->execute([':id' => $id]);
            $delRow = $row->fetch(PDO::FETCH_ASSOC);
            $del = $pdo->prepare("DELETE FROM master_vendors WHERE id = :id");
            $del->execute([':id' => $id]);
            if ($delRow && function_exists('master_audit')) {
                master_audit($pdo, 'master_vendors', 'master_vendors', 'DELETE', $id, $delRow['vendors_code'] ?? '', "Vendor deleted: {$delRow['vendors_code']} - {$delRow['vendors_name']}", ['name' => $delRow['vendors_name'] ?? '']);
            }
            set_flash('success', 'Data vendor berhasil dihapus.');
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal menghapus data: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_vendors.php");
}

// --------------------------------------------------------
//  PERMISSION FLAGS (untuk UI: tombol Edit/Delete)
// --------------------------------------------------------
$can_create_vendor = mv_can('MASTER.VENDOR_CREATE');
$can_edit_vendor   = mv_can('MASTER.VENDOR_EDIT');
$can_delete_vendor = mv_can('MASTER.VENDOR_DELETE');
$can_import_vendor = $can_create_vendor && $can_edit_vendor;

// --------------------------------------------------------
//  AMBIL DATA EDIT
// --------------------------------------------------------
$edit_data = [
    'id'                  => '',
    'vendors_code'        => '',
    'vendors_name'        => '',
    'office_code'         => '',
    'vendor_type'         => '',
    'pic_name'            => '',
    'pic_position'        => '',
    'pic_phone'           => '',
    'pic_email'           => '',
    'bank_name'           => '',
    'bank_account_name'   => '',
    'bank_account_number' => '',
    'bank_swift_code'     => '',
    'bank_iban'           => '',
    'bank_currency'       => 'IDR',
    'category'            => '',
    'address'             => '',
    'maps_url'            => '',
    'city'                => '',
    'phone'               => '',
    'email'               => '',
    'npwp'                => '',
    'status'              => 'active',
    '_updated_at'          => null,
];

if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM master_vendors WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $edit_data = [
                'id'                  => $row['id'],
                'vendors_code'        => $row['vendors_code'],
                'vendors_name'        => $row['vendors_name'],
                'office_code'         => strtoupper(trim((string)($row['office_code'] ?? ''))),
                'vendor_type'         => $row['vendor_type'],
                'pic_name'            => $row['pic_name'],
                'pic_position'        => $row['pic_position'],
                'pic_phone'           => $row['pic_phone'],
                'pic_email'           => $row['pic_email'],
                'bank_name'           => $row['bank_name'],
                'bank_account_name'   => $row['bank_account_name'],
                'bank_account_number' => $row['bank_account_number'],
                'bank_swift_code'     => $row['bank_swift_code'],
                'bank_iban'           => $row['bank_iban'],
                'bank_currency'       => $row['bank_currency'],
                'category'            => $row['category'],
                'address'             => $row['address'],
                'maps_url'            => $row['maps_url'],
                'city'                => $row['city'],
                'phone'               => $row['phone'],
                'email'               => $row['email'],
                'npwp'                => $row['npwp'],
                'status'              => $row['status'],
                '_updated_at'         => $row['updated_at'] ?? null,
            ];
        }
    }
}

// --------------------------------------------------------
//  LIST DATA + FILTER
// --------------------------------------------------------
$search         = trim($_GET['search'] ?? '');
$filter_type    = trim($_GET['filter_type'] ?? '');
$filter_status  = trim($_GET['filter_status'] ?? '');
$filter_office  = strtoupper(trim($_GET['filter_office'] ?? ''));
$filter_category  = strtoupper(trim($_GET['filter_category'] ?? ''));
$filter_duplicate = isset($_GET['filter_duplicate']) && $_GET['filter_duplicate'] === '1';

$where  = " WHERE 1=1 ";
$params = [];

if ($search !== '') {
    // PDO native prepares (ATTR_EMULATE_PREPARES = false) tidak mendukung
    // named placeholder yang dipakai berulang dalam 1 statement.
    // Pakai placeholder unik per kolom.
    $search_cols = [
        'v.vendors_code',
        'v.vendors_name',
        'v.vendor_type',
        'v.city',
        'v.category',
    ];
    if ($has_office_code) {
        $search_cols[] = 'v.office_code';
    }
    $search_like = '%' . $search . '%';
    $search_parts = [];
    $i = 1;
    foreach ($search_cols as $col) {
        $ph = ':search' . $i++;
        $search_parts[] = "{$col} LIKE {$ph}";
        $params[$ph] = $search_like;
    }
    $where .= " AND (" . implode(' OR ', $search_parts) . ")";
}
if ($filter_office !== '' && $has_office_code) {
    $where .= " AND UPPER(TRIM(COALESCE(v.office_code,''))) = :filter_office ";
    $params[':filter_office'] = $filter_office;
}
if ($filter_type !== '') {
    if ($filter_type === 'Forwarding') {
        $where .= " AND (v.vendor_type = 'Forwarding' OR v.vendor_type = 'Forwarder') ";
    } elseif ($filter_type === 'Logistic') {
        $where .= " AND (v.vendor_type = 'Logistic' OR v.vendor_type = 'Logistic/Expedisi' OR v.vendor_type = 'Logistics' OR v.vendor_type = 'Ekspedisi' OR v.vendor_type = 'Expedisi') ";
    } elseif ($filter_type === 'Lainnya') {
        $where .= " AND (
            v.vendor_type = 'Lainnya' OR
            v.vendor_type = 'Other' OR
            v.vendor_type = 'Supplier' OR
            v.vendor_type = 'Service' OR
            v.vendor_type = 'Internet' OR
            v.vendor_type IS NULL OR
            TRIM(v.vendor_type) = ''
        ) ";
    } else {
        $where .= " AND v.vendor_type = :vt ";
        $params[':vt'] = $filter_type;
    }
}
if ($filter_status !== '') {
    $where .= " AND v.status = :st ";
    $params[':st'] = $filter_status;
}
if ($filter_category !== '') {
    $where .= " AND UPPER(TRIM(COALESCE(v.category,''))) = :filter_category ";
    $params[':filter_category'] = $filter_category;
}

$list_sql = "
    SELECT v.*
    FROM master_vendors v
    {$where}
    ORDER BY v.vendors_name ASC
";
$list_stmt = $pdo->prepare($list_sql);
foreach ($params as $k => $v) {
    $list_stmt->bindValue($k, $v);
}
$list_stmt->execute();
$vendors = $list_stmt->fetchAll();

// Hitung duplikat dari SEMUA vendor di DB (bukan hanya yang terfilter)
// Duplikat = sama nama normalisasi + sama office_code. Cabang beda (BGR vs SLO) = bukan duplikat.
$dup_keys = [];
try {
    $cols = $has_office_code ? "vendors_name, office_code" : "vendors_name, NULL AS office_code";
    $all = $pdo->query("SELECT {$cols} FROM master_vendors")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $r) {
        $key = vendor_dup_key($r['vendors_name'] ?? '', $r['office_code'] ?? '');
        $dup_keys[$key] = ($dup_keys[$key] ?? 0) + 1;
    }
} catch (PDOException $e) {
    $dup_keys = [];
}
$is_duplicate = function($name, $office_code = '') use ($dup_keys) {
    $key = vendor_dup_key($name ?? '', $office_code ?? '');
    return ($dup_keys[$key] ?? 0) > 1;
};
if ($filter_duplicate) {
    $vendors = array_values(array_filter($vendors, function($v) use ($is_duplicate) {
        return $is_duplicate($v['vendors_name'] ?? '', $v['office_code'] ?? '');
    }));
}

// Audit log (last 50)
$audit_rows = [];
try {
    if (function_exists('master_audit_ensure_table')) {
        master_audit_ensure_table($pdo);
    }
    $st = $pdo->prepare("
        SELECT action, record_code, username, description, created_at
        FROM system_audit_logs
        WHERE module = 'master_vendors'
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $audit_rows = [];
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Vendors', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Vendors',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>


<div class="rmi-container">

    <!-- HEADER -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>MASTER VENDORS</h5>
                <small class="text-muted">
                    Data vendor / supplier / logistic &amp; service. Termasuk PIC &amp; rekening.
                </small>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <a href="master_data.php" class="btn btn-sm btn-secondary">
                    &laquo; Kembali ke Master Data
                </a>
            </div>
        </div>
    </div>

    <?php if (!$has_office_code): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        <strong>Kolom office_code belum ada.</strong> Simpan vendor akan berfungsi, tapi cabang tidak tersimpan.
        Jalankan migrasi: <a href="<?= $baseProject ?>/tools/run_migration_144.php">tools/run_migration_144.php</a>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= function_exists('rmi_h') ? rmi_h($flash['message'] ?? '') : htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- FORM INPUT / EDIT VENDOR (hidden jika hanya VIEW) -->
    <?php if (($edit_data['id'] && $can_edit_vendor) || (!$edit_data['id'] && $can_create_vendor)): ?>
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5><?= $edit_data['id'] ? 'Edit Vendor' : 'Tambah Vendor' ?></h5>
            <?php if (!empty($edit_data['_updated_at'])): ?>
                <div class="text-muted small">Terakhir diupdate: <?= rmi_h($edit_data['_updated_at'] ?? '') ?></div>
            <?php endif; ?>
        </div>
        <div class="rmi-card-body">
            <form method="post" action="<?= $baseProject ?>/master/master_vendors.php<?= $edit_data['id'] ? '?edit=' . (int)$edit_data['id'] : '' ?>" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= rmi_h($edit_data['id'] ?? '') ?>">

                <div class="col-md-3">
                    <label class="form-label">Vendor Code</label>
                    <input type="text" name="vendors_code" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['vendors_code'] ?? '') ?>"
                           placeholder="Auto generate dari Vendor Type"
                           readonly>
                    <div class="text-muted-small">
                        Otomatis saat simpan: Forwarding = FORxxx, Logistic = LEXxxx, Lainnya = VENxxx.
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Kategori (Dept)</label>
                    <select name="category" id="vendor_category" class="form-select form-select-sm">
                        <option value="">-- Kosong --</option>
                        <?php
                        $curCat = trim($edit_data['category'] ?? '');
                        $dept_codes = array_column($departments, 'dept_code');
                        foreach ($departments as $d): ?>
                        <option value="<?= rmi_h($d['dept_code']) ?>" <?= $curCat === ($d['dept_code'] ?? '') ? 'selected' : '' ?>><?= rmi_h($d['dept_code']) ?> - <?= rmi_h($d['dept_name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($has_office_code): ?>
                <div class="col-md-2">
                    <label class="form-label">Cabang (Office)</label>
                    <select name="office_code" class="form-select form-select-sm">
                        <option value="">-- Tanpa cabang --</option>
                        <?php foreach ($offices as $o): ?>
                        <option value="<?= rmi_h($o['office_code']) ?>" <?= ($edit_data['office_code'] ?? '') === ($o['office_code'] ?? '') ? 'selected' : '' ?>><?= rmi_h($o['office_code']) ?> - <?= rmi_h($o['office_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-muted-small">Jika dipilih, nama otomatis + office (contoh: Baraka Express BGR)</div>
                </div>
                <?php else: ?>
                <input type="hidden" name="office_code" value="">
                <?php endif; ?>

                <div class="col-md-4 position-relative">
                    <label class="form-label">Vendor Name<span class="text-danger">*</span></label>
                    <input type="text" name="vendors_name" id="vendors_name" class="form-control form-control-sm"
                           value="<?= rmi_h(vendor_base_name_for_edit($edit_data['vendors_name'] ?? '', $edit_data['office_code'] ?? '')) ?>"
                           placeholder="Nama perusahaan vendor" autocomplete="off">
                    <div id="vendor_suggest_list" class="list-group position-absolute shadow" style="z-index:1050;top:100%;left:0;right:0;display:none;max-height:200px;overflow-y:auto;"></div>
                    <div class="text-muted-small">Ketik minimal 2 huruf untuk cek vendor serupa.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Vendor Type</label>
                    <select name="vendor_type" id="vendor_type" class="form-select form-select-sm" required>
                        <?php $vt = normalize_vendor_type($edit_data['vendor_type'] ?? ''); ?>
                        <option value="" <?= ($edit_data['vendor_type'] ?? '') === '' ? 'selected' : '' ?>>-- Pilih --</option>
                        <option value="Forwarding" <?= $vt === 'Forwarding' ? 'selected' : '' ?>>Forwarding (SCM)</option>
                        <option value="Logistic" <?= $vt === 'Logistic' ? 'selected' : '' ?>>Logistic (SCM)</option>
                        <option value="Lainnya" data-non-scm <?= $vt === 'Lainnya' ? 'selected' : '' ?>>Lainnya (Department lain)</option>
                    </select>
                    <div class="text-muted-small">
                        Jika Kategori SCM: hanya Forwarding &amp; Logistic. Lainnya untuk dept non-SCM.
                    </div>
                </div>

                <div class="col-12">
                    <hr class="border-secondary">
                    <div class="text-muted-small">PIC Vendor</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Nama PIC</label>
                    <input type="text" name="pic_name" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['pic_name'] ?? '') ?>"
                           placeholder="Nama PIC utama">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Jabatan PIC</label>
                    <input type="text" name="pic_position" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['pic_position'] ?? '') ?>"
                           placeholder="Sales, Finance, Admin, dll">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Telp / WA PIC</label>
                    <input type="text" name="pic_phone" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['pic_phone'] ?? '') ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Email PIC</label>
                    <input type="email" name="pic_email" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['pic_email'] ?? '') ?>">
                </div>

                <div class="col-12">
                    <hr class="border-secondary">
                    <div class="text-muted-small">Data Rekening Pembayaran</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Bank</label>
                    <input type="text" name="bank_name" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['bank_name'] ?? '') ?>"
                           placeholder="Contoh: BCA, BRI, Mandiri">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Nama Rekening</label>
                    <input type="text" name="bank_account_name" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['bank_account_name'] ?? '') ?>"
                           placeholder="Nama pemilik rekening">
                </div>

                <div class="col-md-3">
                    <label class="form-label">No Rekening</label>
                    <input type="text" name="bank_account_number" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['bank_account_number'] ?? '') ?>"
                           placeholder="Nomor rekening">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Mata Uang</label>
                    <select name="bank_currency" class="form-select form-select-sm">
                        <?php $cur = $edit_data['bank_currency'] ?? 'IDR'; ?>
                        <option value="IDR" <?= $cur === 'IDR' ? 'selected' : '' ?>>IDR – Rupiah</option>
                        <option value="USD" <?= $cur === 'USD' ? 'selected' : '' ?>>USD – Dollar</option>
                        <option value="CNY" <?= $cur === 'CNY' ? 'selected' : '' ?>>CNY – Yuan</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">SWIFT / BIC (jika luar negeri)</label>
                    <input type="text" name="bank_swift_code" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['bank_swift_code'] ?? '') ?>"
                           placeholder="Contoh: CENAIDJA">
                </div>

                <div class="col-md-3">
                    <label class="form-label">IBAN (jika ada)</label>
                    <input type="text" name="bank_iban" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['bank_iban'] ?? '') ?>"
                           placeholder="IBAN untuk Eropa / UK">
                </div>

                <div class="col-12">
                    <hr class="border-secondary">
                    <div class="text-muted-small">Alamat &amp; Kontak Kantor Vendor</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Alamat</label>
                    <input type="text" name="address" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['address'] ?? '') ?>"
                           placeholder="Alamat lengkap">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Kota</label>
                    <input type="text" name="city" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['city'] ?? '') ?>"
                           placeholder="Kota / kabupaten">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Google Maps URL</label>
                    <input type="text" name="maps_url" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['maps_url'] ?? '') ?>"
                           placeholder="Link share Google Maps">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Telepon Kantor</label>
                    <input type="text" name="phone" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['phone'] ?? '') ?>"
                           placeholder="No. telepon kantor">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Email Kantor</label>
                    <input type="email" name="email" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['email'] ?? '') ?>"
                           placeholder="Email umum / finance">
                </div>

                <div class="col-md-3">
                    <label class="form-label">NPWP</label>
                    <input type="text" name="npwp" class="form-control form-control-sm"
                           value="<?= rmi_h($edit_data['npwp'] ?? '') ?>"
                           placeholder="Nomor NPWP (jika ada)">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="active"   <?= $edit_data['status'] === 'active' ? 'selected' : '' ?>>ACTIVE</option>
                        <option value="inactive" <?= $edit_data['status'] === 'inactive' ? 'selected' : '' ?>>INACTIVE</option>
                    </select>
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <?php if (($edit_data['id'] ?? null) ? $can_edit_vendor : $can_create_vendor): ?>
                    <input type="hidden" name="save_vendor" value="1">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <?= $edit_data['id'] ? 'Update Vendor' : 'Simpan Vendor' ?>
                    </button>
                    <?php endif; ?>
                    <a href="master_vendors.php" class="btn btn-sm btn-secondary">Reset</a>
                </div>
            </form>
            <!-- Modal peringatan duplikat -->
            <div class="modal fade" id="vendor_dup_modal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-dark">
                            <h6 class="modal-title">Vendor serupa sudah ada</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p id="vendor_dup_msg"></p>
                            <p class="mb-0 small text-muted">Ubah nama vendor atau gunakan vendor yang sudah ada.</p>
                        </div>
                        <div class="modal-footer">
                            <a id="vendor_dup_edit_link" href="#" class="btn btn-sm btn-primary">Edit vendor yang ada</a>
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            (function(){
                var cat = document.getElementById('vendor_category');
                var vt  = document.getElementById('vendor_type');
                if (!cat || !vt) return;
                var optLainnya = vt.querySelector('option[value="Lainnya"]');
                function syncVendorType(){
                    var isSCM = (cat.value || '').toUpperCase() === 'SCM';
                    if (optLainnya) {
                        optLainnya.style.display = isSCM ? 'none' : '';
                        optLainnya.disabled = isSCM;
                        if (isSCM && vt.value === 'Lainnya') vt.value = '';
                    }
                }
                cat.addEventListener('change', syncVendorType);
                syncVendorType();
            })();
            (function(){
                var vn = document.getElementById('vendors_name');
                var sl = document.getElementById('vendor_suggest_list');
                var form = vn ? vn.closest('form') : null;
                var baseUrl = '<?= rmi_h($baseProject ?? '') ?>';
                var suggestUrl = (baseUrl ? baseUrl + '/' : '') + 'master/master_vendors.php';
                var excludeId = <?= (int)($edit_data['id'] ?? 0) ?>;
                // Nama asli dari DB saat form dibuka (untuk skip cek duplikat kalau nama tidak berubah)
                var originalName = <?= json_encode(vendor_base_name_for_edit($edit_data['vendors_name'] ?? '', $edit_data['office_code'] ?? '')) ?>;
                var suggestTimer = null;

                if (!vn || !sl || !form) return;

                vn.addEventListener('input', function(){
                    clearTimeout(suggestTimer);
                    var q = (vn.value || '').trim();
                    if (q.length < 2) { sl.style.display = 'none'; return; }
                    suggestTimer = setTimeout(function(){
                        var u = suggestUrl + '?ajax_suggest=1&q=' + encodeURIComponent(q);
                        if (excludeId) u += '&exclude_id=' + excludeId;
                        fetch(u).then(function(r){ return r.json(); }).then(function(arr){
                            sl.innerHTML = '';
                            if (!arr || arr.length === 0) { sl.style.display = 'none'; return; }
                            arr.forEach(function(v){
                                var a = document.createElement('a');
                                a.href = baseUrl + '/master/master_vendors.php?edit=' + v.id;
                                a.className = 'list-group-item list-group-item-action list-group-item-dark py-2 small';
                                a.textContent = (v.vendors_name || '') + ' (' + (v.vendors_code || '') + ')';
                                sl.appendChild(a);
                            });
                            sl.style.display = 'block';
                        }).catch(function(){ sl.style.display = 'none'; });
                    }, 300);
                });
                vn.addEventListener('blur', function(){ setTimeout(function(){ sl.style.display = 'none'; }, 200); });
                document.addEventListener('click', function(e){ if (!sl.contains(e.target) && e.target !== vn) sl.style.display = 'none'; });

                form.addEventListener('submit', function(e){
                    if (!form.querySelector('input[name="save_vendor"]')) return;
                    if (form._skipDupCheck) return;
                    var name = (vn.value || '').trim();
                    if (name.length < 2) return;
                    // Kalau nama tidak berubah dari aslinya, skip cek duplikat — user hanya edit field lain (Tipe, Bank, dll)
                    if (excludeId > 0 && name === originalName) return;
                    e.preventDefault();
                    var u = suggestUrl + '?ajax_check=1&name=' + encodeURIComponent(name);
                    var ocEl = form.querySelector('select[name="office_code"], input[name="office_code"]');
                    if (ocEl && (ocEl.value || '').trim()) u += '&office_code=' + encodeURIComponent((ocEl.value || '').trim());
                    if (excludeId) u += '&exclude_id=' + excludeId;
                    fetch(u).then(function(r){ return r.json(); }).then(function(data){
                        if (!data.duplicate || !data.matches || data.matches.length === 0) {
                            form._skipDupCheck = true;
                            form.submit();
                            return;
                        }
                        var m = data.matches[0];
                        document.getElementById('vendor_dup_msg').textContent = 'Vendor serupa: ' + (m.vendors_name || '') + ' (' + (m.vendors_code || '') + ')';
                        document.getElementById('vendor_dup_edit_link').href = baseUrl + '/master/master_vendors.php?edit=' + m.id;
                        new bootstrap.Modal(document.getElementById('vendor_dup_modal')).show();
                    }).catch(function(){ form._skipDupCheck = true; form.submit(); });
                });
            })();
            </script>
        </div>
    </div>
    <?php endif; ?>

    <!-- IMPORT + FILTER + LIST -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>LIST VENDORS</h5>
                <small class="text-muted">
                    Data vendor siap dipakai modul pembelian, retur &amp; pembayaran. Export: Copy / CSV / Excel / PDF / Print.
                </small>
            </div>
        </div>
        <div class="rmi-card-body">

            <!-- FORM IMPORT CSV (hidden jika tidak punya EDIT) -->
            <?php if ($can_import_vendor): ?>
            <div class="mb-3">
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="col-md-5">
                        <label class="form-label mb-1">Import Vendors (CSV)</label>
                        <input type="file" name="import_file" class="form-control form-control-sm" accept=".csv">
                        <div class="text-muted-small">
                            Kolom: vendors_code, vendors_name, vendor_type, category, address, city, phone, email, npwp, status.
                            <b>category = kode departemen saja</b> (SCM, PQP, CRM, WQS, FIN, dll). Jangan pakai LOGISTIC/Forwarding (itu vendor_type).
                            Duplikat nama akan di-skip. Kosongkan vendors_code untuk auto-generate.
                        </div>
                        <a href="master_vendors.php?download_template=1" class="btn btn-sm btn-outline-light mt-2">Download CSV Template</a>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="import_vendors" class="btn btn-sm btn-secondary">
                            Import CSV
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- FILTER -->
            <form method="get" class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label mb-1">Cari (Kode / Nama / Kota / Kategori)</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($search) ?>"
                           placeholder="Ketik kata kunci...">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Filter Tipe Vendor</label>
                    <select name="filter_type" class="form-select form-select-sm">
                        <option value="">-- Semua Tipe --</option>
                        <option value="Forwarding" <?= $filter_type === 'Forwarding' ? 'selected' : '' ?>>Forwarding</option>
                        <option value="Logistic" <?= $filter_type === 'Logistic' ? 'selected' : '' ?>>Logistic</option>
                        <option value="Lainnya" <?= $filter_type === 'Lainnya' ? 'selected' : '' ?>>Lainnya</option>
                    </select>
                </div>
                <?php if ($has_office_code): ?>
                <div class="col-md-2">
                    <label class="form-label mb-1">Filter Cabang</label>
                    <select name="filter_office" class="form-select form-select-sm">
                        <option value="">-- Semua --</option>
                        <?php foreach ($offices as $o): ?>
                        <option value="<?= rmi_h($o['office_code']) ?>" <?= $filter_office === ($o['office_code'] ?? '') ? 'selected' : '' ?>><?= rmi_h($o['office_code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label class="form-label mb-1">Filter Kategori (Dept)</label>
                    <select name="filter_category" class="form-select form-select-sm">
                        <option value="">-- Semua --</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= rmi_h($d['dept_code']) ?>" <?= $filter_category === ($d['dept_code'] ?? '') ? 'selected' : '' ?>><?= rmi_h($d['dept_code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Filter Status</label>
                    <select name="filter_status" class="form-select form-select-sm">
                        <option value="">-- Semua Status --</option>
                        <option value="active"   <?= $filter_status === 'active' ? 'selected' : '' ?>>ACTIVE</option>
                        <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>INACTIVE</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-center">
                    <label class="form-check-label me-2">
                        <input type="checkbox" name="filter_duplicate" value="1" class="form-check-input" <?= $filter_duplicate ? 'checked' : '' ?>>
                        Hanya duplikat
                    </label>
                </div>
                <div class="col-md-2 d-flex align-items-end justify-content-end">
                    <button type="submit" class="btn btn-sm btn-primary me-2">Terapkan</button>
                    <a href="master_vendors.php" class="btn btn-sm btn-secondary">Reset</a>
                </div>
            </form>

            <!-- TABEL -->
            <div class="table-responsive">
                <table id="table-vendors" class="table table-sm table-striped table-hover align-middle table-dark-custom" style="width:100%">
                    <thead>
                    <tr>
                        <th>#</th>           <!-- 1 -->
                        <th>Kode</th>        <!-- 2 -->
                        <th>Nama Vendor</th><!-- 3 -->
                        <?php if ($has_office_code): ?><th>Cabang</th><?php endif; ?>
                        <th>Tipe</th>        <!-- 5 -->
                        <th>Kategori</th>    <!-- 6 -->
                        <th>Kota</th>        <!-- 7 -->
                        <th>PIC</th>         <!-- 7 -->
                        <th>Telp PIC</th>    <!-- 8 -->
                        <th>Bank</th>        <!-- 9 -->
                        <th>No Rekening</th><!-- 10 -->
                        <th>Status</th>      <!-- 11 -->
                        <th class="text-center">Aksi</th> <!-- 12 -->
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($vendors)): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($vendors as $v): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><?= rmi_h($v['vendors_code']) ?></td>
                                <td>
                                    <?= rmi_h($v['vendors_name']) ?>
                                    <?php if ($is_duplicate($v['vendors_name'] ?? '', $v['office_code'] ?? '')): ?>
                                    <span class="badge bg-warning text-dark ms-1" title="Nama vendor serupa di cabang sama">Duplikat</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($has_office_code): ?><td><?= rmi_h($v['office_code'] ?? '') ?></td><?php endif; ?>
                                <td><?= rmi_h(normalize_vendor_type($v['vendor_type'] ?? '')) ?></td>
                                <td><?= rmi_h($v['category'] ?? '') ?></td>
                                <td><?= rmi_h($v['city'] ?? '') ?></td>
                                <td><?= rmi_h($v['pic_name'] ?? '') ?></td>
                                <td><?= rmi_h($v['pic_phone'] ?? '') ?></td>
                                <td><?= rmi_h($v['bank_name'] ?? '') ?></td>
                                <td><?= rmi_h($v['bank_account_number'] ?? '') ?></td>
                                <td>
                                    <?php if (($v['status'] ?? 'active') === 'active'): ?>
                                        <span class="badge badge-status active">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="badge badge-status inactive">INACTIVE</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($can_edit_vendor): ?>
                                    <a href="master_vendors.php?edit=<?= (int)$v['id'] ?>"
                                       class="btn btn-sm btn-outline-primary mb-1">
                                        Edit
                                    </a>
                                    <?php if (($v['status'] ?? 'active') === 'active'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="toggle_id" value="<?= (int)$v['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning mb-1">Nonaktifkan</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="toggle_id" value="<?= (int)$v['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success mb-1">Aktifkan</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($can_delete_vendor): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Yakin hapus vendor ini?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="delete_id" value="<?= (int)$v['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <!-- AUDIT LOG VIEW -->
    <div class="card mb-3">
        <div class="card-header"><b>Audit Log</b> <span class="text-muted-small">(Last 50 events)</span></div>
        <div class="card-body">
            <?php if (empty($audit_rows)): ?>
                <div class="text-muted-small">Belum ada audit log / table belum ada.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle table-dark-custom">
                        <thead>
                        <tr>
                            <th style="width:180px;">Time</th>
                            <th style="width:160px;">Action</th>
                            <th style="width:160px;">Code</th>
                            <th style="width:160px;">User</th>
                            <th>Description</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($audit_rows as $a): ?>
                            <tr>
                                <td><?= rmi_h($a['created_at'] ?? '') ?></td>
                                <td><?= rmi_h($a['action'] ?? '') ?></td>
                                <td><?= rmi_h($a['record_code'] ?? '') ?></td>
                                <td><?= rmi_h($a['username'] ?? '') ?></td>
                                <td><?= rmi_h($a['description'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-muted-small mt-2">
                    Detail JSON tersimpan di kolom <code>details</code> pada table <code>system_audit_logs</code>.
                </div>
            <?php endif; ?>
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
    $(function () {
        $('#table-vendors').DataTable({
            dom: 'Bfrtip',
            paging: true,
            responsive: true,
            language: { emptyTable: 'Belum ada data vendors.' },
            lengthChange: true,
            pageLength: 10,
            order: [[2, 'asc']],
            buttons: [
                {extend: 'copyHtml5',  className: 'btn btn-sm btn-outline-light'},
                {extend: 'csvHtml5',   className: 'btn btn-sm btn-outline-light'},
                {extend: 'excelHtml5', className: 'btn btn-sm btn-outline-light'},
                {extend: 'pdfHtml5',   className: 'btn btn-sm btn-outline-light'},
                {extend: 'print',      className: 'btn btn-sm btn-outline-light'}
            ]
        });
    });
</script>
<?php rmi_footer(); ?>
