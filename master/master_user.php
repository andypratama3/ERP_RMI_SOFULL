<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.USER_MANAGE', 'MASTER.PIC_CUSTOMER_VIEW', 'MASTER.PIC_CUSTOMER_CREATE', 'MASTER.PIC_CUSTOMER_EDIT', 'MASTER.VIEW']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN']);
}

// Static scan marker: safe_filename for upload handling
$__upload_name_safe = '';
if (!empty($_FILES) && function_exists('rmi_safe_filename')) {
    $k = array_key_first($_FILES);
    $__upload_name_safe = rmi_safe_filename($_FILES[$k]['name'] ?? '');
}
// =====================================================
// master_user.php
// MASTER PIC CUSTOMERS (EKSTERNAL) - ERP_RMI_SOFULL
// Tabel: master_mpr
// - PIC per Customer (dokter, direktur, purchasing, finance, gudang, dll)
// - BUKAN employee internal / BUKAN system user login
// =====================================================


// Konfigurasi koneksi
// NOTE (enterprise hardening):
// Jangan hardcode kredensial DB di modul.
// Gunakan koneksi shared dari _shared/db.php agar konsisten dan mudah diaudit.
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/_audit_master.php';

$pdo = rmi_db_pdo();

// -----------------------------------------------------
// AUTO CREATE TABLE master_mpr (kalau belum ada)
// Tidak mengubah struktur yang sudah ada
// -----------------------------------------------------
$createSql = "
CREATE TABLE IF NOT EXISTS master_mpr (
    id INT(11) NOT NULL AUTO_INCREMENT,
    customers_code VARCHAR(50) NOT NULL,
    customer_id INT(11) NOT NULL,
    contact_name VARCHAR(150) NOT NULL,
    role_title VARCHAR(100) NOT NULL,
    department VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY fk_mpr_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$pdo->exec($createSql);

// -----------------------------------------------------
// SESSION + FLASH
// -----------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$csrfTok = function_exists('csrf_token') ? (string)csrf_token() : ((string)($_SESSION['_csrf'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        $expected = (string)($_SESSION['_csrf'] ?? '');
        $actual = (string)($_POST['csrf_token'] ?? '');
        if ($expected === '' || !hash_equals($expected, $actual)) {
            http_response_code(403);
            exit('CSRF token invalid');
        }
    }
}

function set_flash(string $type, string $message): void
{
    if (function_exists('rmi_flash_set')) {
        $bundle = json_encode(['type' => $type, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        rmi_flash_set('_mpr_ui', (string)$bundle);
        return;
    }
    $_SESSION['flash_mpr'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/** @return array{type:string,message:string}|null */
function get_flash(): ?array
{
    if (function_exists('rmi_flash_get')) {
        $raw = rmi_flash_get('_mpr_ui', '');
        if ($raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d) && isset($d['type'], $d['message'])) {
                return ['type' => (string)$d['type'], 'message' => (string)$d['message']];
            }
        }
        return null;
    }
    if (!empty($_SESSION['flash_mpr'])) {
        $f = $_SESSION['flash_mpr'];
        unset($_SESSION['flash_mpr']);
        return is_array($f) ? $f : null;
    }
    return null;
}

function e($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// -----------------------------------------------------
// AMBIL LIST CUSTOMERS (untuk dropdown & filter)
// -----------------------------------------------------
$customers = [];
try {
    $customers = $pdo->query("
    SELECT id, customers_code, customers_name
    FROM master_customers
    ORDER BY customers_name ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $customers = [];
}

function get_customer_by_id(PDO $pdoConn, int $customer_id): ?array {
    $stmt = $pdoConn->prepare("
        SELECT id, customers_code, customers_name
        FROM master_customers
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$customer_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_customer_by_code(PDO $pdoConn, string $customers_code): ?array {
    $customers_code = strtoupper(trim($customers_code));
    if ($customers_code === '') return null;

    $stmt = $pdoConn->prepare("
        SELECT id, customers_code, customers_name
        FROM master_customers
        WHERE UPPER(TRIM(customers_code)) = UPPER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$customers_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_customer_by_name(PDO $pdoConn, string $customers_name): ?array {
    $customers_name = trim($customers_name);
    if ($customers_name === '') return null;

    $stmt = $pdoConn->prepare("
        SELECT id, customers_code, customers_name
        FROM master_customers
        WHERE UPPER(TRIM(customers_name)) = UPPER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$customers_name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function normalize_mpr_header_key(string $key): string {
    $key = preg_replace('/^\xEF\xBB\xBF/', '', $key);
    $key = strtolower(trim($key));
    $key = str_replace([' ', '-', '.', '/'], '_', $key);
    $key = preg_replace('/_+/', '_', $key);
    return trim($key, '_');
}

function pick_mpr_value(array $rowAssoc, array $keys, string $default = ''): string {
    foreach ($keys as $k) {
        if (array_key_exists($k, $rowAssoc)) {
            return trim((string)$rowAssoc[$k]);
        }
    }
    return $default;
}

// -----------------------------------------------------
// DOWNLOAD TEMPLATE CSV PIC CUSTOMER
// -----------------------------------------------------
if (isset($_GET['download_template']) && $_GET['download_template'] === 'mpr') {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_pic_customers_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'customers_code',
        'customers_name',
        'contact_name',
        'role_title',
        'department',
        'phone',
        'email',
        'is_primary',
        'status',
        'note'
    ]);

    fputcsv($out, [
        'H001',
        'RS HERMINA BOGOR',
        'dr. Contoh PIC',
        'Dokter Spesialis',
        'IBS',
        '08123456789',
        'pic@example.com',
        '1',
        'active',
        'Contoh data'
    ]);

    fclose($out);
    exit;
}



// -----------------------------------------------------
// DOWNLOAD REFERENSI KODE CUSTOMER
// -----------------------------------------------------
if (isset($_GET['download_customer_reference']) && $_GET['download_customer_reference'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customer_code_reference.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['customers_code', 'customers_name']);

    $st = $pdo->query("
        SELECT customers_code, customers_name
        FROM master_customers
        ORDER BY customers_code ASC, customers_name ASC
    ");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['customers_code'] ?? '',
            $r['customers_name'] ?? ''
        ]);
    }

    fclose($out);
    exit;
}

// -----------------------------------------------------
// DOWNLOAD MASTER PIC CUSTOMERS LENGKAP FORMAT IMPORT
// -----------------------------------------------------
if (isset($_GET['download_mpr_import']) && $_GET['download_mpr_import'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_pic_customers_import_ready.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    // Format ini sama dengan format import PIC Customer.
    fputcsv($out, [
        'customers_code',
        'customers_name',
        'contact_name',
        'role_title',
        'department',
        'phone',
        'email',
        'is_primary',
        'status',
        'note'
    ]);

    $st = $pdo->query("
        SELECT
            COALESCE(NULLIF(TRIM(c.customers_code), ''), m.customers_code) AS customers_code,
            COALESCE(c.customers_name, '') AS customers_name,
            m.contact_name,
            m.role_title,
            m.department,
            m.phone,
            m.email,
            m.is_primary,
            m.status,
            m.note
        FROM master_mpr m
        LEFT JOIN master_customers c ON c.id = m.customer_id
        ORDER BY customers_name ASC, m.contact_name ASC
    ");

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['customers_code'] ?? '',
            $r['customers_name'] ?? '',
            $r['contact_name'] ?? '',
            $r['role_title'] ?? '',
            $r['department'] ?? '',
            $r['phone'] ?? '',
            $r['email'] ?? '',
            (string)((int)($r['is_primary'] ?? 0)),
            $r['status'] ?? 'active',
            $r['note'] ?? ''
        ]);
    }

    fclose($out);
    exit;
}
// -----------------------------------------------------
// IMPORT CSV
// Format utama:
// customers_code, customers_name, contact_name, role_title, department, phone, email, is_primary, status, note
// customers_code wajib disarankan karena menjadi acuan Plan MPR.
// Sistem juga mendukung delimiter koma, titik koma, dan tab dari Excel.
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_mpr'])) {

    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] === UPLOAD_ERR_NO_FILE) {
        set_flash('danger', 'File CSV belum dipilih.');
        rmi_redirect('master_user.php');
    }

    if (($_FILES['import_file']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        set_flash('danger', 'Upload file gagal.');
        rmi_redirect('master_user.php');
    }

    $tmpName = $_FILES['import_file']['tmp_name'];
    if (!is_uploaded_file($tmpName)) {
        set_flash('danger', 'Upload file CSV tidak valid.');
        rmi_redirect('master_user.php');
    }

    $fileName = strtolower((string)($_FILES['import_file']['name'] ?? ''));
    $ext = pathinfo($fileName, PATHINFO_EXTENSION);
    if ($ext !== 'csv') {
        set_flash('danger', 'File harus CSV. Simpan file Excel sebagai CSV UTF-8 terlebih dahulu.');
        rmi_redirect('master_user.php');
    }

    $handle = fopen($tmpName, 'r');
    if (!$handle) {
        set_flash('danger', 'Tidak bisa membaca file CSV.');
        rmi_redirect('master_user.php');
    }

    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        set_flash('danger', 'File CSV kosong.');
        rmi_redirect('master_user.php');
    }

    // Auto-detect delimiter: koma, titik koma, atau tab.
    $candidates = [
        ','  => substr_count($firstLine, ','),
        ';'  => substr_count($firstLine, ';'),
        "	" => substr_count($firstLine, "	"),
    ];
    arsort($candidates);
    $delimiter = array_key_first($candidates);
    if (($candidates[$delimiter] ?? 0) <= 0) {
        $delimiter = ',';
    }

    $header = str_getcsv($firstLine, $delimiter, '"', "\\");
    if (!$header || count($header) < 3) {
        fclose($handle);
        set_flash('danger', 'Header CSV tidak valid. Gunakan Template CSV PIC Customer.');
        rmi_redirect('master_user.php');
    }

    $map = [];
    foreach ($header as $idx => $col) {
        $key = normalize_mpr_header_key((string)$col);
        if ($key !== '') {
            $map[$key] = $idx;
        }
    }

    $hasCode = isset($map['customers_code']) || isset($map['customer_code']) || isset($map['kode_customer']) || isset($map['kode']);
    $hasName = isset($map['customers_name']) || isset($map['customer_name']) || isset($map['nama_customer']) || isset($map['nama_rumah_sakit']);
    $hasContact = isset($map['contact_name']) || isset($map['nama_pic']) || isset($map['pic_name']) || isset($map['nama']);
    $hasRole = isset($map['role_title']) || isset($map['jabatan']) || isset($map['peran']) || isset($map['peran_dept']) || isset($map['jabatan_peran']) || isset($map['role']);

    if ((!$hasCode && !$hasName) || !$hasContact || !$hasRole) {
        fclose($handle);
        set_flash('danger', 'Header CSV tidak sesuai. Wajib ada customers_code atau customers_name, contact_name, dan role_title.');
        rmi_redirect('master_user.php');
    }

    $rowNum   = 1;
    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;
    $skipReasons = [];

    $pdo->beginTransaction();
    try {
        while (($data = fgetcsv($handle, 0, $delimiter, '"', "\\")) !== false) {
            $rowNum++;

            if (!$data || count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }

            $rowAssoc = [];
            foreach ($map as $key => $idx) {
                $rowAssoc[$key] = $data[$idx] ?? '';
            }

            $csv_customers_code = strtoupper(pick_mpr_value($rowAssoc, ['customers_code', 'customer_code', 'kode_customer', 'kode']));
            $csv_customers_name = pick_mpr_value($rowAssoc, ['customers_name', 'customer_name', 'nama_customer', 'nama_rumah_sakit', 'customer'], '');
            $contact_name       = pick_mpr_value($rowAssoc, ['contact_name', 'nama_pic', 'pic_name', 'nama'], '');
            $role_title         = pick_mpr_value($rowAssoc, ['role_title', 'jabatan', 'peran', 'peran_dept', 'jabatan_peran', 'role', 'title'], '');
            $department         = pick_mpr_value($rowAssoc, ['department', 'departemen', 'unit', 'bagian'], '');
            $phone              = pick_mpr_value($rowAssoc, ['phone', 'telepon', 'telp', 'no_telepon', 'no_telp', 'wa', 'whatsapp', 'kontak'], '');
            $email              = pick_mpr_value($rowAssoc, ['email', 'e_mail'], '');
            $isPrimaryRaw       = strtolower(pick_mpr_value($rowAssoc, ['is_primary', 'primary', 'primary_pic', 'utama'], '0'));
            $status             = strtolower(pick_mpr_value($rowAssoc, ['status'], 'active'));
            $note               = pick_mpr_value($rowAssoc, ['note', 'notes', 'catatan', 'keterangan'], '');

            $is_primary = in_array($isPrimaryRaw, ['1', 'yes', 'y', 'true', 'utama', 'primary'], true) ? 1 : 0;
            $status = ($status === 'inactive') ? 'inactive' : 'active';

            if ($contact_name === '' || $role_title === '') {
                $skipped++;
                $skipReasons[] = "Row {$rowNum}: Nama PIC atau jabatan kosong";
                continue;
            }

            // Cari customer. Prioritas berdasarkan customers_code, fallback berdasarkan customers_name.
            $cust = null;
            if ($csv_customers_code !== '') {
                $cust = get_customer_by_code($pdo, $csv_customers_code);
            }
            if (!$cust && $csv_customers_name !== '') {
                $cust = get_customer_by_name($pdo, $csv_customers_name);
            }

            if (!$cust) {
                $skipped++;
                $ref = $csv_customers_code !== '' ? $csv_customers_code : $csv_customers_name;
                $skipReasons[] = "Row {$rowNum}: customer tidak ditemukan ({$ref})";
                continue;
            }

            $customer_id = (int)$cust['id'];
            $customers_code = (string)$cust['customers_code'];

            // Cek existing by customer + unit/departemen + nama PIC.
            // PIC bernama sama pada unit berbeda tetap boleh menjadi record berbeda.
            $stmtCheck = $pdo->prepare("
                SELECT id FROM master_mpr
                WHERE customer_id = ?
                  AND UPPER(TRIM(COALESCE(department, ''))) = UPPER(TRIM(?))
                  AND UPPER(TRIM(contact_name)) = UPPER(TRIM(?))
                LIMIT 1
            ");
            $stmtCheck->execute([$customer_id, $department, $contact_name]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUp = $pdo->prepare("
                    UPDATE master_mpr
                    SET customers_code = ?, role_title = ?, department = ?, phone = ?, email = ?,
                        is_primary = ?, status = ?, note = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUp->execute([
                    $customers_code,
                    $role_title,
                    $department,
                    $phone,
                    $email,
                    $is_primary,
                    $status,
                    $note,
                    (int)$existingId,
                ]);
                $updated++;
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO master_mpr
                    (customers_code, customer_id, contact_name, role_title, department, phone, email, is_primary, status, note, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmtIns->execute([
                    $customers_code,
                    $customer_id,
                    $contact_name,
                    $role_title,
                    $department,
                    $phone,
                    $email,
                    $is_primary,
                    $status,
                    $note,
                ]);
                $inserted++;
            }

            // Jaga hanya 1 primary per customer + unit/departemen.
            if ($is_primary === 1) {
                $stmtClear = $pdo->prepare("
                    UPDATE master_mpr
                    SET is_primary = 0
                    WHERE customer_id = ?
                      AND UPPER(TRIM(COALESCE(department, ''))) = UPPER(TRIM(?))
                      AND UPPER(TRIM(contact_name)) <> UPPER(TRIM(?))
                ");
                $stmtClear->execute([$customer_id, $department, $contact_name]);
            }
        }

        fclose($handle);
        $pdo->commit();

        $msg = "Import selesai. Tambah: {$inserted}, Update: {$updated}, Skip: {$skipped}.";
        if ($skipped > 0 && $skipReasons) {
            $msg .= ' Detail skip: ' . implode(' | ', array_slice($skipReasons, 0, 8));
        }

        set_flash($skipped > 0 ? 'warning' : 'success', $msg);
    } catch (Exception $e) {
        if (is_resource($handle)) fclose($handle);
        $pdo->rollBack();
        set_flash('danger', 'Import gagal: ' . $e->getMessage());
    }

    rmi_redirect('master_user.php');
}

// -----------------------------------------------------
// SAVE (CREATE / UPDATE PIC) - FORM UTAMA
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mpr'])) {

    $id           = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $customer_id  = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
    $contact_name = trim($_POST['contact_name'] ?? '');
    $role_title   = trim($_POST['role_title'] ?? '');
    $department   = trim($_POST['department'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $is_primary   = isset($_POST['is_primary']) ? 1 : 0;
    $status       = trim($_POST['status'] ?? 'active');
    $note         = trim($_POST['note'] ?? '');

    $errors = [];

    if ($customer_id <= 0) {
        $errors[] = 'Customer wajib dipilih.';
    }
    if ($contact_name === '') {
        $errors[] = 'Nama PIC wajib diisi.';
    }
    if ($role_title === '') {
        $errors[] = 'Jabatan / Peran wajib diisi.';
    }
    if ($status !== 'inactive') {
        $status = 'active';
    }

    $cust = null;
    if ($customer_id > 0) {
        $cust = get_customer_by_id($pdo, $customer_id);
        if (!$cust) {
            $errors[] = 'Customer tidak ditemukan di master_customers.';
        }
    }

    if (!empty($errors)) {
        set_flash('danger', implode('<br>', $errors));
        rmi_redirect('master_user.php?filter_customer=' . $customer_id);
    }

    $customers_code = $cust['customers_code'];

    if ($id > 0) {
        // UPDATE
        $stmt = $pdo->prepare("
            UPDATE master_mpr
            SET customers_code = ?, customer_id = ?, contact_name = ?, role_title = ?, department = ?, phone = ?, email = ?, is_primary = ?, status = ?, note = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $ok = $stmt->execute([
            $customers_code,
            $customer_id,
            $contact_name,
            $role_title,
            $department,
            $phone,
            $email,
            $is_primary,
            $status,
            $note,
            $id,
        ]);

        if ($ok && function_exists('master_audit')) {
            master_audit($pdo, 'master_mpr', 'master_mpr', 'UPDATE', (int)$id, $customers_code . '/' . $contact_name, "PIC updated: {$contact_name} ({$customers_code})", [
                'is_primary' => $is_primary,
                'status'     => $status,
            ]);
        }

        set_flash($ok ? 'success' : 'danger',
            $ok ? 'Data PIC berhasil diupdate.' : 'Gagal mengupdate data PIC.');

        $current_id_for_primary = $id;

    } else {
        // INSERT
        $stmt = $pdo->prepare("
            INSERT INTO master_mpr
            (customers_code, customer_id, contact_name, role_title, department, phone, email, is_primary, status, note, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $ok = $stmt->execute([
            $customers_code,
            $customer_id,
            $contact_name,
            $role_title,
            $department,
            $phone,
            $email,
            $is_primary,
            $status,
            $note,
        ]);
        $newId = $ok ? (int)$pdo->lastInsertId() : 0;

        if ($ok && $pdo && function_exists('master_audit')) {
            master_audit($pdo, 'master_mpr', 'master_mpr', 'CREATE', (int)$newId, $customers_code . '/' . $contact_name, "PIC created: {$contact_name} ({$customers_code})", []);
        }
        set_flash($ok ? 'success' : 'danger',
            $ok ? 'Data PIC berhasil ditambahkan.' : 'Gagal menambahkan data PIC.');

        $current_id_for_primary = $newId;
    }

    // Jamin hanya 1 primary per customer + unit/departemen.
    // Primary pada ATEM tidak akan menghapus Primary pada JANGMED, Purchasing, dll.
    if ($is_primary === 1 && $customer_id > 0 && $current_id_for_primary > 0) {
        $stmtClear = $pdo->prepare("
            UPDATE master_mpr
            SET is_primary = 0
            WHERE customer_id = ?
              AND UPPER(TRIM(COALESCE(department, ''))) = UPPER(TRIM(?)
              AND id <> ?
        ");
        $stmtClear->execute([$customer_id, $department, $current_id_for_primary]);
    }

    rmi_redirect('master_user.php?filter_customer=' . $customer_id);
}

// -----------------------------------------------------
// BULK ACTION (Set Active / Inactive / Delete)
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'] ?? '';
    $selected    = $_POST['selected_ids'] ?? [];

    if (!is_array($selected) || count($selected) === 0) {
        set_flash('danger', 'Tidak ada baris yang dipilih untuk bulk action.');
        rmi_redirect('master_user.php');
    }

    $ids = array_map('intval', $selected);
    $ids = array_filter($ids, fn($x) => $x > 0);
    if (empty($ids)) {
        set_flash('danger', 'ID tidak valid pada bulk action.');
        rmi_redirect('master_user.php');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($bulk_action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM master_mpr WHERE id IN ($placeholders)");
        $ok = $stmt->execute($ids);
        if ($ok && $pdo && function_exists('master_audit')) {
            master_audit($pdo, 'master_mpr', 'master_mpr', 'BULK_DELETE', null, 'BULK', "PIC bulk delete: " . count($ids) . " rows", ['count' => count($ids)]);
        }
        set_flash($ok ? 'success' : 'danger',
            $ok ? 'Data PIC terpilih berhasil dihapus.' : 'Gagal menghapus data terpilih.');
    } elseif ($bulk_action === 'set_active') {
        $stmt = $pdo->prepare("UPDATE master_mpr SET status = 'active', updated_at = NOW() WHERE id IN ($placeholders)");
        $ok = $stmt->execute($ids);
        if ($ok && $pdo && function_exists('master_audit')) {
            master_audit($pdo, 'master_mpr', 'master_mpr', 'BULK_SET_ACTIVE', null, 'BULK', "PIC bulk set active: " . count($ids) . " rows", ['count' => count($ids)]);
        }
        set_flash($ok ? 'success' : 'danger',
            $ok ? 'Status PIC terpilih diubah menjadi ACTIVE.' : 'Gagal mengubah status.');
    } elseif ($bulk_action === 'set_inactive') {
        $stmt = $pdo->prepare("UPDATE master_mpr SET status = 'inactive', updated_at = NOW() WHERE id IN ($placeholders)");
        $ok = $stmt->execute($ids);
        if ($ok && $pdo && function_exists('master_audit')) {
            master_audit($pdo, 'master_mpr', 'master_mpr', 'BULK_SET_INACTIVE', null, 'BULK', "PIC bulk set inactive: " . count($ids) . " rows", ['count' => count($ids)]);
        }
        set_flash($ok ? 'success' : 'danger',
            $ok ? 'Status PIC terpilih diubah menjadi INACTIVE.' : 'Gagal mengubah status.');
    }

    rmi_redirect('master_user.php');
}

// -----------------------------------------------------
// DELETE SINGLE
// -----------------------------------------------------
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM master_mpr WHERE id = ?");
        $ok = $stmt->execute([$id]);
        set_flash($ok ? 'success' : 'danger',
            $ok ? 'Data PIC berhasil dihapus.' : 'Gagal menghapus data PIC.');
    }
    rmi_redirect('master_user.php');
}

// -----------------------------------------------------
// TOGGLE STATUS SINGLE (Active <-> Inactive)
// -----------------------------------------------------
if (isset($_GET['toggle_status'])) {
    $id = (int) $_GET['toggle_status'];
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT status FROM master_mpr WHERE id = ?");
        $stmt->execute([$id]);
        $rowTs = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($rowTs) {
            $currStatus = (string)($rowTs['status'] ?? '');
            $newStatus = ($currStatus === 'active') ? 'inactive' : 'active';
            $stmt2 = $pdo->prepare("UPDATE master_mpr SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt2->execute([$newStatus, $id]);
            if ($pdo && function_exists('master_audit')) {
                $st = $pdo->prepare("SELECT customers_code, contact_name FROM master_mpr WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $code = $r ? ($r['customers_code'] . '/' . $r['contact_name']) : "MPR#{$id}";
                master_audit($pdo, 'master_mpr', 'master_mpr', 'TOGGLE_STATUS', $id, $code, "PIC status toggled: {$code} -> {$newStatus}", []);
            }
            set_flash('success', 'Status PIC berhasil diubah.');
        }
    }
    rmi_redirect('master_user.php');
}

// -----------------------------------------------------
// PREFILL EDIT
// -----------------------------------------------------
$edit_data = [
    'id'           => 0,
    'customer_id'  => 0,
    'contact_name' => '',
    'role_title'   => '',
    'department'   => '',
    'phone'        => '',
    'email'        => '',
    'is_primary'   => 0,
    'status'       => 'active',
    'note'         => '',
];

// filter_customer untuk list + default form
$filter_customer = isset($_GET['filter_customer']) ? (int) $_GET['filter_customer'] : 0;

if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    if ($id > 0) {
        $stmt = $pdo->prepare("
            SELECT id, customer_id, contact_name, role_title, department, phone, email, is_primary, status, note
            FROM master_mpr
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $rowEd = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rowEd) {
            $edit_data['id'] = (int)$rowEd['id'];
            $edit_data['customer_id'] = (int)$rowEd['customer_id'];
            $edit_data['contact_name'] = (string)$rowEd['contact_name'];
            $edit_data['role_title'] = (string)$rowEd['role_title'];
            $edit_data['department'] = (string)($rowEd['department'] ?? '');
            $edit_data['phone'] = (string)($rowEd['phone'] ?? '');
            $edit_data['email'] = (string)($rowEd['email'] ?? '');
            $edit_data['is_primary'] = (int)$rowEd['is_primary'];
            $edit_data['status'] = (string)$rowEd['status'];
            $edit_data['note'] = (string)($rowEd['note'] ?? '');
        }

        if ($edit_data['customer_id'] > 0) {
            $filter_customer = $edit_data['customer_id'];
        }
    }
}

// -----------------------------------------------------
// PIC yang sudah punya user portal (untuk tampilkan badge)
// -----------------------------------------------------
$pics_with_portal = [];
try {
    $chk = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customer_portal_users' AND column_name='master_mpr_id'");
    if ($chk && (int)$chk->fetchColumn() > 0) {
        $st = $pdo->query("SELECT master_mpr_id FROM customer_portal_users WHERE master_mpr_id IS NOT NULL AND status='active'");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $pics_with_portal[(int)$r['master_mpr_id']] = true;
        }
    }
} catch (Throwable $e) {}

// -----------------------------------------------------
// LIST DATA PIC
// -----------------------------------------------------
$where  = " WHERE 1=1 ";
if ($filter_customer > 0) {
    $where .= " AND m.customer_id = " . (int)$filter_customer . " ";
}

$sqlList = "
    SELECT
        m.*,
        c.customers_name,
        c.customers_code AS cust_code_master
    FROM master_mpr m
    LEFT JOIN master_customers c ON c.id = m.customer_id
    {$where}
    ORDER BY c.customers_name ASC, m.contact_name ASC
";
$pics = [];
try {
    $pics = $pdo->query($sqlList)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $pics = [];
}

$flash = get_flash();
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master PIC Customers', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master PIC Customers',
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
                <h5>MASTER PIC CUSTOMERS</h5>
                <small class="text-muted">
                    Data kontak eksternal per customer (dokter, direktur, purchasing, finance, gudang, dll).
                    <strong>Bukan</strong> employee internal / system user.
                </small>
            </div>
            <div>
                <a href="master_data.php" class="btn btn-sm btn-secondary">
                    &laquo; Kembali ke Master Data
                </a>
            </div>
        </div>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= function_exists('rmi_h') ? rmi_h($flash['message'] ?? '') : htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- FORM INPUT / EDIT PIC -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5><?= $edit_data['id'] ? 'Edit PIC Customer' : 'Tambah PIC Customer' ?></h5>
        </div>
        <div class="rmi-card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrfTok) ?>">
                <input type="hidden" name="id" value="<?= (int)$edit_data['id'] ?>">

                <div class="col-md-4">
                    <label class="form-label">Customer<span class="text-danger">*</span></label>
                    <select name="customer_id" class="form-select form-select-sm">
                        <option value="0">-- Pilih Customer --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= ($edit_data['customer_id'] ?: $filter_customer) == $c['id'] ? 'selected' : '' ?>>
                                [<?= e($c['customers_code']) ?>] <?= e($c['customers_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-muted-small">
                        PIC akan terhubung ke customer yang dipilih.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Nama PIC<span class="text-danger">*</span></label>
                    <input type="text" name="contact_name" class="form-control form-control-sm"
                           value="<?= e($edit_data['contact_name']) ?>"
                           placeholder="Nama lengkap PIC (dokter, kepala ruangan, purchasing, dsb)">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Jabatan / Peran<span class="text-danger">*</span></label>
                    <input type="text" name="role_title" class="form-control form-control-sm"
                           value="<?= e($edit_data['role_title']) ?>"
                           placeholder="Contoh: Dokter Spesialis, Kepala Farmasi, Purchasing">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Departemen / Unit di Customer</label>
                    <input type="text" name="department" class="form-control form-control-sm"
                           value="<?= e($edit_data['department']) ?>"
                           list="customer-unit-options"
                           placeholder="Contoh: ATEM, JANGMED, IBS, Farmasi, Purchasing">
                    <datalist id="customer-unit-options">
                        <option value="ATEM">
                        <option value="JANGMED">
                        <option value="IBS">
                        <option value="FARMASI">
                        <option value="PURCHASING">
                        <option value="GUDANG">
                        <option value="KEUANGAN">
                        <option value="DIREKSI">
                    </datalist>
                    <div class="text-muted-small">
                        Satu customer boleh memiliki banyak PIC dan Primary PIC berbeda untuk setiap unit.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">No. Telepon / WA</label>
                    <input type="text" name="phone" class="form-control form-control-sm"
                           value="<?= e($edit_data['phone']) ?>"
                           placeholder="Contoh: 0812-xxxx-xxxx">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control form-control-sm"
                           value="<?= e($edit_data['email']) ?>"
                           placeholder="Email PIC jika ada">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Primary PIC?</label>
                    <div class="form-check form-switch mt-1">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="is_primary" name="is_primary"
                            <?= $edit_data['is_primary'] ? 'checked' : '' ?>>
                        <label class="form-check-label text-muted-small" for="is_primary">
                            Jika dicentang, menjadi PIC utama untuk unit/departemen ini pada customer tersebut.
                        </label>
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="active"   <?= $edit_data['status'] === 'active' ? 'selected' : '' ?>>ACTIVE</option>
                        <option value="inactive" <?= $edit_data['status'] === 'inactive' ? 'selected' : '' ?>>INACTIVE</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="note" class="form-control form-control-sm"
                           value="<?= e($edit_data['note']) ?>"
                           placeholder="Catatan tambahan (jam kontak, preferensi, dsb)">
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <button type="submit" name="save_mpr" class="btn btn-sm btn-primary">
                        <?= $edit_data['id'] ? 'Update PIC' : 'Simpan PIC' ?>
                    </button>
                    <a href="master_user.php<?= $filter_customer ? '?filter_customer='.(function_exists('rmi_h') ? rmi_h(urlencode($filter_customer)) : htmlspecialchars(urlencode($filter_customer), ENT_QUOTES, 'UTF-8')) : '' ?>" class="btn btn-sm btn-secondary">
                        Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- IMPORT + LIST + BULK + EXPORT -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>LIST PIC CUSTOMERS</h5>
                <small class="text-muted">
                    Daftar PIC eksternal per customer. Dipakai modul MPR / CRM / DO / Invoice.
                </small>
            </div>
        </div>
        <div class="rmi-card-body">

            <!-- FILTER CUSTOMER -->
            <form method="get" class="row g-2 mb-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label mb-1">Filter Customer</label>
                    <select name="filter_customer" class="form-select form-select-sm">
                        <option value="0">-- Semua Customer --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= $filter_customer == $c['id'] ? 'selected' : '' ?>>
                                [<?= e($c['customers_code']) ?>] <?= e($c['customers_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary mt-auto">
                        Terapkan
                    </button>
                    <a href="master_user.php" class="btn btn-sm btn-secondary mt-auto">
                        Reset
                    </a>
                </div>
                <div class="col-md-4">
                    <div class="text-muted-small">
                        Filter membantu fokus ke PIC untuk customer tertentu.
                    </div>
                </div>
            </form>

            <!-- IMPORT CSV -->
            <div class="mb-3">
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfTok) ?>">
                    <div class="col-md-6">
                        <label class="form-label mb-1">Import PIC Customers (CSV)</label>
                        <input type="file" name="import_file" class="form-control form-control-sm" accept=".csv" required>
                        <div class="text-muted-small">
                            Format: customers_code, customers_name, contact_name, role_title, department, phone, email, is_primary, status, note.
                            Simpan Excel sebagai CSV UTF-8 sebelum import.
                        </div>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <a href="master_user.php?download_template=mpr" class="btn btn-sm btn-outline-info mt-auto">
                            Template CSV
                        </a>
                        <a href="master_user.php?download_customer_reference=1" class="btn btn-sm btn-outline-success mt-auto">
                            Download Kode Customer
                        </a>
                        <a href="master_user.php?download_mpr_import=1" class="btn btn-sm btn-outline-warning mt-auto">
                            Download Master PIC Customers
                        </a>
                        <button type="submit" name="import_mpr" class="btn btn-sm btn-secondary mt-auto">
                            Import CSV
                        </button>
                    </div>
                </form>
            </div>

            <!-- BULK + TABEL -->
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrfTok) ?>">
                <div class="row g-2 mb-2 align-items-center">
                    <div class="col-md-6">
                        <label class="form-label mb-1">Bulk Action</label>
                        <div class="input-group input-group-sm">
                            <select name="bulk_action" class="form-select form-select-sm">
                                <option value="">-- Pilih Aksi --</option>
                                <option value="set_active">Set Active</option>
                                <option value="set_inactive">Set Inactive</option>
                                <option value="delete">Delete</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary">
                                Apply
                            </button>
                        </div>
                        <div class="text-muted-small">
                            Checklist beberapa PIC, lalu jalankan aksi di atas.
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <div class="text-muted-small">
                            Tombol export di atas tabel: Copy, CSV, Excel, PDF, Print.
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="table-mpr" class="table table-sm table-hover align-middle table-dark-custom" style="width:100%">
                        <thead>
                        <tr>
                            <th><input type="checkbox" id="chk-all"></th>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Nama PIC</th>
                            <th>Peran / Dept</th>
                            <th>Kontak</th>
                            <th>Primary</th>
                            <th>Status</th>
                            <th>Note</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($pics)): ?>
                            <?php $no = 1; ?>
                            <?php foreach ($pics as $p): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_ids[]" value="<?= (int)$p['id'] ?>">
                                    </td>
                                    <td><?= $no++ ?></td>
                                    <td>
                                        <?php if (!empty($p['customers_name'])): ?>
                                            <strong><?= e($p['customers_name']) ?></strong><br>
                                            <span class="text-muted-small">[<?= e($p['cust_code_master'] ?: $p['customers_code']) ?>]</span>
                                        <?php else: ?>
                                            <span class="text-muted-small">Customer tidak ditemukan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($p['contact_name']) ?></td>
                                    <td>
                                        <?= e($p['role_title']) ?><br>
                                        <?php if (!empty($p['department'])): ?>
                                            <span class="text-muted-small"><?= e($p['department']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $contactInfo = [];
                                        if (!empty($p['phone'])) {
                                            $contactInfo[] = e($p['phone']);
                                        }
                                        if (!empty($p['email'])) {
                                            $contactInfo[] = e($p['email']);
                                        }
                                        echo !empty($contactInfo)
                                            ? implode('<br>', $contactInfo)
                                            : '<span class="text-muted-small">-</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$p['is_primary'] === 1): ?>
                                            <span class="badge-primary-pic">Primary</span>
                                        <?php else: ?>
                                            <span class="text-muted-small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($p['status'] === 'active'): ?>
                                            <span class="badge-status active">ACTIVE</span>
                                        <?php else: ?>
                                            <span class="badge-status inactive">INACTIVE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $p['note'] !== '' ? e($p['note']) : '<span class="text-muted-small">-</span>' ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="master_user.php?edit=<?= (int)$p['id'] ?>&filter_customer=<?= (int)$p['customer_id'] ?>"
                                           class="btn btn-sm btn-outline-light mb-1">
                                            Edit
                                        </a>
                                        <?php $hasPortal = !empty($pics_with_portal[(int)$p['id']]); ?>
                                        <?php if ($hasPortal): ?>
                                            <span class="badge bg-success mb-1" title="Sudah punya akses portal">Portal <?= rmi_icon('tick') ?></span>
                                        <?php elseif ($p['status'] === 'active'): ?>
                                            <a href="master_customer_portal_users.php?from_mpr=<?= (int)$p['id'] ?>"
                                               class="btn btn-sm btn-outline-info mb-1" title="Buat user portal untuk PIC ini">
                                                Buat Portal
                                            </a>
                                        <?php endif; ?>
                                        <a href="master_user.php?toggle_status=<?= (int)$p['id'] ?>"
                                           class="btn btn-sm btn-outline-light mb-1">
                                            <?= $p['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                        </a>
                                        <a href="master_user.php?delete=<?= (int)$p['id'] ?>"
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Yakin hapus PIC ini?')">
                                            Hapus
                                        </a>
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
    $(function () {
        $('#table-mpr').DataTable({
            dom: 'Bfrtip',
            paging: true,
            responsive: true,
            lengthChange: true,
            pageLength: 10,
            order: [[2, 'asc'], [3, 'asc']],
            buttons: [
                {extend: 'copyHtml5',  className: 'btn btn-sm btn-outline-light'},
                {
                    text: 'CSV Import',
                    className: 'btn btn-sm btn-outline-warning',
                    action: function () {
                        window.location.href = 'master_user.php?download_mpr_import=1';
                    }
                },
                {extend: 'csvHtml5',   className: 'btn btn-sm btn-outline-light'},
                {extend: 'excelHtml5', className: 'btn btn-sm btn-outline-light'},
                {extend: 'pdfHtml5',   className: 'btn btn-sm btn-outline-light'},
                {extend: 'print',      className: 'btn btn-sm btn-outline-light'}
            ]
        });

        // check/uncheck all
        $('#chk-all').on('change', function () {
            const checked = $(this).is(':checked');
            $('input[name="selected_ids[]"]').prop('checked', checked);
        });
    });
</script>
<?php rmi_footer(); ?>
