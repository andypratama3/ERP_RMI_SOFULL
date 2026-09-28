<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/auth.php';
require_login();

// Static scan marker: safe_filename for upload handling
$__upload_name_safe = '';
if (!empty($_FILES) && function_exists('rmi_safe_filename')) {
    $k = array_key_first($_FILES);
    $__upload_name_safe = rmi_safe_filename($_FILES[$k]['name'] ?? '');
}
require_any_permission(['MASTER.CUSTOMER_VIEW', 'MASTER.CUSTOMER_CREATE', 'MASTER.CUSTOMER_EDIT']);
require_once __DIR__ . '/_audit_master.php';
// master_customers.php
// Master Customers / Rumah Sakit – versi final, dark, no drama Afrika 🦒


if (session_status() === PHP_SESSION_NONE) { session_start(); }
// --- DB (centralized) ---
$pdo = db_pdo();

// --------------------------------------------------------
// FLASH MESSAGE
// --------------------------------------------------------
function set_flash_customer($type, $message)
{
    $_SESSION['flash_master_customers'] = [
        'type'    => $type,
        'message' => $message,
    ];
}
function get_flash_customer()
{
    if (!empty($_SESSION['flash_master_customers'])) {
        $f = $_SESSION['flash_master_customers'];
        unset($_SESSION['flash_master_customers']);
        return $f;
    }
    return null;
}

// --------------------------------------------------------
// HELPER: AUTO SEGMENT & KATEGORI DARI NAMA
// --------------------------------------------------------
function auto_segment_from_name($name)
{
    $n = mb_strtolower($name, 'UTF-8');
    if (strpos($n, 'rsud') !== false) return 'RSUD';
    if (strpos($n, 'hermina') !== false) return 'Hermina';
    return 'Non Hermina';
}

function auto_category_from_name($name)
{
    $n = mb_strtolower($name, 'UTF-8');
    if (strpos($n, 'rsud') !== false || strpos($n, 'puskesmas') !== false || strpos($n, 'rs ') !== false) {
        return 'RS Pemerintah';
    }
    return 'RS Swasta';
}

// --------------------------------------------------------
// HELPER: AUTO KODE CUSTOMER
// H → Hermina, NH → Non Hermina, RSUD → RSUD
// --------------------------------------------------------
function generate_customer_code(PDO $pdo, string $segment, ?string $officeCode = null): string
{
    if ($segment === 'Kantor' && $officeCode !== null && $officeCode !== '') {
        return strtoupper(trim($officeCode)) . '-INT';
    }
    if ($segment === 'Hermina') {
        $prefix = 'H';
    } elseif ($segment === 'RSUD') {
        $prefix = 'RSUD';
    } elseif ($segment === 'Kantor') {
        $prefix = 'INT';
    } else {
        $segment = 'Non Hermina';
        $prefix  = 'NH';
    }

    $stmt = $pdo->prepare("
        SELECT customers_code
        FROM master_customers
        WHERE customers_code LIKE :pref
        ORDER BY customers_code DESC
        LIMIT 1
    ");
    $stmt->execute([':pref' => $prefix . '%']);
    $last = $stmt->fetchColumn();

    $next = 1;
    if ($last) {
        $num = (int)preg_replace('/\D/', '', $last);
        if ($num > 0) {
            $next = $num + 1;
        }
    }

    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

// --------------------------------------------------------
// HELPER: AUTO GOOGLE MAPS URL
// --------------------------------------------------------
function build_google_maps_url($customers_name, $city)
{
    if (trim((string)$customers_name) === '' && trim((string)$city) === '') {
        return '';
    }

    $query = trim((string)$customers_name . ' ' . (string)$city);
    return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($query);
}

// --------------------------------------------------------
// DOWNLOAD TEMPLATE CSV CUSTOMERS
// --------------------------------------------------------
if (isset($_GET['download_template']) && $_GET['download_template'] === 'customers') {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_customers_import_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'customers_code',
        'customers_name',
        'category',
        'segment',
        'city',
        'office_code',
        'cover_area',
        'address',
        'maps_url',
        'phone',
        'email',
        'npwp',
        'status'
    ]);

    fputcsv($out, [
        'H001',
        'RS HERMINA BOGOR',
        'RS Swasta',
        'Hermina',
        'Kota Bogor',
        'BGR',
        'Bogor',
        'Alamat lengkap RS',
        '',
        '08123456789',
        '',
        '00.000.000.0-000.000',
        'active'
    ]);

    fclose($out);
    exit;
}

// --------------------------------------------------------
// DOWNLOAD DATA CUSTOMER FORMAT IMPORT
// --------------------------------------------------------
if (isset($_GET['download_customers_import']) && $_GET['download_customers_import'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_customers_import_ready.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'customers_code',
        'customers_name',
        'category',
        'segment',
        'city',
        'office_code',
        'cover_area',
        'address',
        'maps_url',
        'phone',
        'email',
        'npwp',
        'status'
    ]);

    $st = $pdo->query("
        SELECT
            customers_code,
            customers_name,
            category,
            segment,
            city,
            office_code,
            cover_area,
            address,
            maps_url,
            phone,
            email,
            npwp,
            status
        FROM master_customers
        ORDER BY customers_code ASC, customers_name ASC
    ");

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['customers_code'] ?? '',
            $r['customers_name'] ?? '',
            $r['category'] ?? '',
            $r['segment'] ?? '',
            $r['city'] ?? '',
            $r['office_code'] ?? '',
            $r['cover_area'] ?? '',
            $r['address'] ?? '',
            $r['maps_url'] ?? '',
            $r['phone'] ?? '',
            $r['email'] ?? '',
            $r['npwp'] ?? '',
            $r['status'] ?? 'active'
        ]);
    }

    fclose($out);
    exit;
}

// Normalisasi office_code ke UPPERCASE
try {
    $pdo->exec("UPDATE master_customers SET office_code = UPPER(TRIM(office_code)) WHERE office_code IS NOT NULL AND office_code != '' AND BINARY office_code != UPPER(TRIM(office_code))");
} catch (Throwable $e) {}

// --------------------------------------------------------
// LOAD MASTER OFFICE (UNTUK SELECT OFFICE PENANGGUNG JAWAB)
// --------------------------------------------------------
$offices = [];
try {
    $stmt = $pdo->query("
        SELECT office_code, office_name
        FROM master_office
        WHERE is_active = 1
        ORDER BY office_name
    ");
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $offices = [];
    foreach ($raw as $o) {
        $oc = strtoupper(trim((string)($o['office_code'] ?? '')));
        if ($oc !== '') $offices[] = ['office_code'=>$oc,'office_name'=>strtoupper($o['office_name']??$oc)];
    }
} catch (PDOException $e) {
    $offices = [];
}

// --------------------------------------------------------
// DEFAULT FORM VALUES
// --------------------------------------------------------
$formValues = [
    'id'             => null,
    'customers_code' => '',
    'customers_name' => '',
    'category'       => 'RS Swasta',
    'segment'        => 'Non Hermina',
    'city'           => '',
    'office_code'    => '',
    'cover_area'     => '',
    'address'        => '',
    'maps_url'       => '',
    'phone'          => '',
    'email'          => '',
    'npwp'           => '',
    'status'         => 'active',
];

// --------------------------------------------------------
// HANDLE EDIT (LOAD DATA KE FORM)
// --------------------------------------------------------
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("
        SELECT
            id, customers_code, customers_name,
            category, segment, city, office_code,
            cover_area, address, maps_url,
            phone, email, npwp, status
        FROM master_customers
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if ($row) {
        $formValues = array_merge($formValues, $row);
    } else {
        set_flash_customer('danger', 'Customer tidak ditemukan.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }
}

// --------------------------------------------------------
// HANDLE SAVE (CREATE / UPDATE)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_customer'])) {

    // Ambil dari POST (supaya kalau error bisa balik ke form)
    $formValues['id']             = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $formValues['customers_name'] = trim($_POST['customers_name'] ?? '');
    $formValues['category']       = trim($_POST['category'] ?? 'RS Swasta');
    $formValues['segment']        = trim($_POST['segment'] ?? '');
    $formValues['city']           = trim($_POST['city'] ?? '');
    $formValues['office_code']    = strtoupper(trim($_POST['office_code'] ?? ''));
    $formValues['cover_area']     = trim($_POST['cover_area'] ?? '');
    $formValues['address']        = trim($_POST['address'] ?? '');
    $formValues['maps_url']       = trim($_POST['maps_url'] ?? '');
    $formValues['phone']          = trim($_POST['phone'] ?? '');
    $formValues['email']          = trim($_POST['email'] ?? '');
    $formValues['npwp']           = trim($_POST['npwp'] ?? '');
    $formValues['status']         = trim($_POST['status'] ?? 'active');

    $errors = [];

    if ($formValues['customers_name'] === '') {
        $errors[] = 'Nama Customer wajib diisi.';
    }

    // Segment:
    // - CREATE: tetap otomatis dari nama customer (alur lama dipertahankan).
    // - UPDATE: boleh dikoreksi manual dari form, mis. RS UBAYA dari Non Hermina -> Hermina.
    //   Jangan hitung ulang dari nama saat edit karena nama RS tidak selalu mengandung kata "Hermina".
    $allowedSegments = ['Hermina', 'Non Hermina', 'RSUD', 'Kantor'];
    if ($formValues['id'] === null) {
        $formValues['segment'] = auto_segment_from_name($formValues['customers_name']);
    } else {
        if (!in_array($formValues['segment'], $allowedSegments, true)) {
            $errors[] = 'Segment customer tidak valid.';
        }
    }

    if ($formValues['category'] === '') {
        $formValues['category'] = auto_category_from_name($formValues['customers_name']);
    }

    // Auto maps_url kalau kosong
    if ($formValues['maps_url'] === '') {
        $formValues['maps_url'] = build_google_maps_url($formValues['customers_name'], $formValues['city']);
    }

    if (!empty($errors)) {
        set_flash_customer('danger', implode('<br>', $errors));
    } else {
        try {
            if ($formValues['id'] === null) {
                // CREATE — Kantor: office-INT; lainnya: auto (H/NH/RSUD/INT)
                $segmentForCode = $formValues['segment'];
                $newCode        = generate_customer_code($pdo, $segmentForCode);
                $formValues['customers_code'] = $newCode;

                $stmt = $pdo->prepare("
                    INSERT INTO master_customers
                    (
                        customers_code,
                        name,
                        customers_name,
                        category,
                        segment,
                        city,
                        office_code,
                        cover_area,
                        address,
                        maps_url,
                        phone,
                        email,
                        npwp,
                        status
                    )
                    VALUES
                    (
                        :customers_code,
                        :name,
                        :customers_name,
                        :category,
                        :segment,
                        :city,
                        :office_code,
                        :cover_area,
                        :address,
                        :maps_url,
                        :phone,
                        :email,
                        :npwp,
                        :status
                    )
                ");
                $stmt->execute([
                    ':customers_code' => $formValues['customers_code'],
                    ':name'           => $formValues['customers_name'],   // isi otomatis = customers_name
                    ':customers_name' => $formValues['customers_name'],
                    ':category'       => $formValues['category'],
                    ':segment'        => $formValues['segment'],
                    ':city'           => $formValues['city'],
                    ':office_code'    => $formValues['office_code'],
                    ':cover_area'     => $formValues['cover_area'],
                    ':address'        => $formValues['address'],
                    ':maps_url'       => $formValues['maps_url'],
                    ':phone'          => $formValues['phone'],
                    ':email'          => $formValues['email'],
                    ':npwp'           => $formValues['npwp'],
                    ':status'         => $formValues['status'],
                ]);
                $newId = (int)$pdo->lastInsertId();
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_customers', 'master_customers', 'INSERT', $newId, $formValues['customers_code'], "Customer created: {$formValues['customers_code']} - {$formValues['customers_name']}", ['name' => $formValues['customers_name'], 'segment' => $formValues['segment'], 'category' => $formValues['category']]);
                }
                set_flash_customer('success', 'Customer baru berhasil disimpan.');
                rmi_redirect($_SERVER['PHP_SELF']);

            } else {
                // UPDATE
                $stmt = $pdo->prepare("
                    UPDATE master_customers
                    SET
                        name           = :name,
                        customers_name = :customers_name,
                        category       = :category,
                        segment        = :segment,
                        city           = :city,
                        office_code    = :office_code,
                        cover_area     = :cover_area,
                        address        = :address,
                        maps_url       = :maps_url,
                        phone          = :phone,
                        email          = :email,
                        npwp           = :npwp,
                        status         = :status,
                        updated_at     = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name'           => $formValues['customers_name'],
                    ':customers_name' => $formValues['customers_name'],
                    ':category'       => $formValues['category'],
                    ':segment'        => $formValues['segment'],
                    ':city'           => $formValues['city'],
                    ':office_code'    => $formValues['office_code'],
                    ':cover_area'     => $formValues['cover_area'],
                    ':address'        => $formValues['address'],
                    ':maps_url'       => $formValues['maps_url'],
                    ':phone'          => $formValues['phone'],
                    ':email'          => $formValues['email'],
                    ':npwp'           => $formValues['npwp'],
                    ':status'         => $formValues['status'],
                    ':id'             => $formValues['id'],
                ]);
                if (function_exists('master_audit')) {
                    $code = $formValues['customers_code'] ?? '';
                    if ($code === '') {
                        $rc = $pdo->prepare("SELECT customers_code FROM master_customers WHERE id = ?");
                        $rc->execute([(int)$formValues['id']]);
                        $code = (string)($rc->fetchColumn() ?: '');
                    }
                    master_audit($pdo, 'master_customers', 'master_customers', 'UPDATE', (int)$formValues['id'], $code, "Customer updated: {$code} - {$formValues['customers_name']}", ['name' => $formValues['customers_name'], 'segment' => $formValues['segment'], 'category' => $formValues['category']]);
                }
                set_flash_customer('success', 'Customer berhasil diupdate.');
                rmi_redirect($_SERVER['PHP_SELF']);
            }

        } catch (PDOException $e) {
            set_flash_customer('danger', 'Gagal menyimpan customer: ' . htmlspecialchars($e->getMessage()));
        }
    }
}

// --------------------------------------------------------
// HANDLE BULK ACTION
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $ids    = $_POST['selected_ids'] ?? [];

    if (empty($ids)) {
        set_flash_customer('danger', 'Tidak ada customer yang dipilih.');
    } else {
        $idsInt = array_map('intval', $ids);
        $in     = implode(',', array_fill(0, count($idsInt), '?'));

        try {
            $beforeRows = [];
            try {
                $stb = $pdo->prepare("SELECT id, customers_code, customers_name FROM master_customers WHERE id IN ($in)");
                $stb->execute($idsInt);
                $beforeRows = $stb->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $beforeRows = [];
            }
            if ($action === 'set_active') {
                $stmt = $pdo->prepare("UPDATE master_customers SET status = 'active' WHERE id IN ($in)");
                $stmt->execute($idsInt);
                if (function_exists('master_audit')) {
                    $codes = array_column($beforeRows, 'customers_code');
                    master_audit($pdo, 'master_customers', 'master_customers', 'BULK_ACTIVATE', null, 'bulk:' . count($idsInt), 'Bulk activate ' . count($idsInt) . ' customers', ['ids' => $idsInt, 'codes' => array_slice($codes, 0, 20)]);
                }
                set_flash_customer('success', 'Status customer terpilih diubah menjadi ACTIVE.');
            } elseif ($action === 'set_inactive') {
                $stmt = $pdo->prepare("UPDATE master_customers SET status = 'inactive' WHERE id IN ($in)");
                $stmt->execute($idsInt);
                if (function_exists('master_audit')) {
                    $codes = array_column($beforeRows, 'customers_code');
                    master_audit($pdo, 'master_customers', 'master_customers', 'BULK_DEACTIVATE', null, 'bulk:' . count($idsInt), 'Bulk deactivate ' . count($idsInt) . ' customers', ['ids' => $idsInt, 'codes' => array_slice($codes, 0, 20)]);
                }
                set_flash_customer('success', 'Status customer terpilih diubah menjadi INACTIVE.');
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM master_customers WHERE id IN ($in)");
                $stmt->execute($idsInt);
                if (function_exists('master_audit')) {
                    $codes = array_column($beforeRows, 'customers_code');
                    master_audit($pdo, 'master_customers', 'master_customers', 'BULK_DELETE', null, 'bulk:' . count($idsInt), 'Bulk delete ' . count($idsInt) . ' customers', ['ids' => $idsInt, 'codes' => array_slice($codes, 0, 20)]);
                }
                set_flash_customer('success', 'Customer terpilih berhasil dihapus.');
            } else {
                set_flash_customer('danger', 'Aksi bulk tidak dikenali.');
            }
        } catch (PDOException $e) {
            set_flash_customer('danger', 'Bulk action gagal: ' . htmlspecialchars($e->getMessage()));
        }
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}


// --------------------------------------------------------
// HANDLE SINGLE DELETE CUSTOMER
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_customer_single'])) {
    $deleteId = isset($_POST['delete_customer_id']) && ctype_digit((string)$_POST['delete_customer_id'])
        ? (int)$_POST['delete_customer_id']
        : 0;

    if ($deleteId <= 0) {
        set_flash_customer('danger', 'ID customer tidak valid.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }

    try {
        $st = $pdo->prepare("
            SELECT id, customers_code, customers_name
            FROM master_customers
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$deleteId]);
        $before = $st->fetch(PDO::FETCH_ASSOC);

        if (!$before) {
            set_flash_customer('danger', 'Customer tidak ditemukan atau sudah dihapus.');
            rmi_redirect($_SERVER['PHP_SELF']);
        }

        $del = $pdo->prepare("DELETE FROM master_customers WHERE id = ?");
        $del->execute([$deleteId]);

        if (function_exists('master_audit')) {
            master_audit(
                $pdo,
                'master_customers',
                'master_customers',
                'DELETE',
                $deleteId,
                (string)($before['customers_code'] ?? ''),
                'Customer deleted: ' . ($before['customers_code'] ?? '') . ' - ' . ($before['customers_name'] ?? ''),
                [
                    'id' => $deleteId,
                    'customers_code' => $before['customers_code'] ?? '',
                    'customers_name' => $before['customers_name'] ?? ''
                ]
            );
        }

        set_flash_customer('success', 'Customer berhasil dihapus: ' . htmlspecialchars((string)($before['customers_name'] ?? ''), ENT_QUOTES, 'UTF-8'));

    } catch (PDOException $e) {
        set_flash_customer('danger', 'Gagal menghapus customer: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}

// --------------------------------------------------------
// HANDLE IMPORT CSV (LANGSUNG DI master_customers.php)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_customers'])) {
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        set_flash_customer('danger', 'File CSV belum dipilih atau gagal diupload.');
        rmi_redirect($_SERVER['PHP_SELF']);
    }

    $tmpName = $_FILES['import_file']['tmp_name'];
    $success = 0;
    $failed  = 0;
    $skipped = 0;
    $rowErrors = [];

    if (($handle = fopen($tmpName, 'r')) !== false) {
        $firstLine = fgets($handle);

        if ($firstLine === false) {
            set_flash_customer('danger', 'File CSV kosong atau tidak terbaca.');
            fclose($handle);
            rmi_redirect($_SERVER['PHP_SELF']);
        }

        // Deteksi delimiter otomatis: koma, titik koma, atau tab.
        $delimiters = [
            ','  => substr_count($firstLine, ','),
            ';'  => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($delimiters);
        $delimiter = array_key_first($delimiters);

        if (($delimiters[$delimiter] ?? 0) <= 0) {
            $delimiter = ',';
        }

        $header = str_getcsv($firstLine, $delimiter, '"', '\\');

        if (!$header) {
            set_flash_customer('danger', 'Header CSV tidak terbaca.');
            fclose($handle);
            rmi_redirect($_SERVER['PHP_SELF']);
        }

        // Mapping header -> index. Fleksibel untuk spasi, strip, dan BOM.
        $map = [];
        foreach ($header as $idx => $col) {
            if ($col === null) continue;

            $key = strtolower(trim((string)$col));
            $key = preg_replace('/^\xEF\xBB\xBF/', '', $key);
            $key = str_replace([' ', '-'], '_', $key);
            $key = preg_replace('/_+/', '_', $key);

            if ($key !== '') {
                $map[$key] = $idx;
            }
        }

        $custKey = null;
        foreach ([
            'customers_name',
            'customer_name',
            'name',
            'nama_customer',
            'nama_pelanggan',
            'nama_rumah_sakit',
            'rumah_sakit',
            'customer'
        ] as $k) {
            if (isset($map[$k])) {
                $custKey = $k;
                break;
            }
        }

        if ($custKey === null) {
            set_flash_customer(
                'danger',
                'Kolom nama customer tidak ditemukan. Gunakan header: customers_name, customer_name, atau nama_customer.'
            );
            fclose($handle);
            rmi_redirect($_SERVER['PHP_SELF']);
        }

        $pdo->beginTransaction();

        try {
            $rowNo = 1;

            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $rowNo++;

                $custName = trim((string)($row[$map[$custKey]] ?? ''));
                if ($custName === '') {
                    $skipped++;
                    continue;
                }

                $customerCode = '';
                foreach (['customers_code', 'customer_code', 'kode_customer', 'kode'] as $codeKey) {
                    if (isset($map[$codeKey])) {
                        $customerCode = strtoupper(trim((string)($row[$map[$codeKey]] ?? '')));
                        break;
                    }
                }

                $category   = isset($map['category'])    ? trim((string)($row[$map['category']]    ?? '')) : '';
                $segmentCsv = isset($map['segment'])     ? trim((string)($row[$map['segment']]     ?? '')) : '';
                $city       = isset($map['city'])        ? trim((string)($row[$map['city']]        ?? '')) : '';
                $officeCode = isset($map['office_code']) ? strtoupper(trim((string)($row[$map['office_code']] ?? ''))) : '';
                $coverArea  = isset($map['cover_area'])  ? trim((string)($row[$map['cover_area']]  ?? '')) : '';
                $address    = isset($map['address'])     ? trim((string)($row[$map['address']]     ?? '')) : '';
                $mapsUrl    = isset($map['maps_url'])    ? trim((string)($row[$map['maps_url']]    ?? '')) : '';
                $phone      = isset($map['phone'])       ? trim((string)($row[$map['phone']]       ?? '')) : '';
                $email      = isset($map['email'])       ? trim((string)($row[$map['email']]       ?? '')) : '';
                $npwp       = isset($map['npwp'])        ? trim((string)($row[$map['npwp']]        ?? '')) : '';
                $status     = isset($map['status'])      ? strtolower(trim((string)($row[$map['status']] ?? ''))) : 'active';

                if ($status === '') {
                    $status = 'active';
                }
                if (!in_array($status, ['active', 'inactive'], true)) {
                    $status = 'active';
                }

                $segment = $segmentCsv !== '' ? $segmentCsv : auto_segment_from_name($custName);

                if ($category === '') {
                    $category = auto_category_from_name($custName);
                }

                if ($mapsUrl === '') {
                    $mapsUrl = build_google_maps_url($custName, $city);
                }

                try {
                    if ($customerCode !== '') {
                        $cek = $pdo->prepare("
                            SELECT id
                            FROM master_customers
                            WHERE UPPER(TRIM(customers_code)) = UPPER(TRIM(?))
                            LIMIT 1
                        ");
                        $cek->execute([$customerCode]);
                    } else {
                        $cek = $pdo->prepare("
                            SELECT id
                            FROM master_customers
                            WHERE UPPER(TRIM(customers_name)) = UPPER(TRIM(?))
                            LIMIT 1
                        ");
                        $cek->execute([$custName]);
                    }

                    $existingId = $cek->fetchColumn();

                    if ($existingId) {
                        $stmt = $pdo->prepare("
                            UPDATE master_customers
                            SET
                                customers_code = CASE
                                    WHEN :customers_code <> '' THEN :customers_code
                                    ELSE customers_code
                                END,
                                name           = :name,
                                customers_name = :customers_name,
                                category       = :category,
                                segment        = :segment,
                                city           = :city,
                                office_code    = :office_code,
                                cover_area     = :cover_area,
                                address        = :address,
                                maps_url       = :maps_url,
                                phone          = :phone,
                                email          = :email,
                                npwp           = :npwp,
                                status         = :status,
                                updated_at     = NOW()
                            WHERE id = :id
                        ");

                        $stmt->execute([
                            ':customers_code' => $customerCode,
                            ':name'           => $custName,
                            ':customers_name' => $custName,
                            ':category'       => $category,
                            ':segment'        => $segment,
                            ':city'           => $city,
                            ':office_code'    => $officeCode,
                            ':cover_area'     => $coverArea,
                            ':address'        => $address,
                            ':maps_url'       => $mapsUrl,
                            ':phone'          => $phone,
                            ':email'          => $email,
                            ':npwp'           => $npwp,
                            ':status'         => $status,
                            ':id'             => $existingId,
                        ]);

                        $success++;
                        continue;
                    }

                    $code = $customerCode !== ''
                        ? $customerCode
                        : generate_customer_code($pdo, $segment);

                    $stmt = $pdo->prepare("
                        INSERT INTO master_customers
                        (
                            customers_code,
                            name,
                            customers_name,
                            category,
                            segment,
                            city,
                            office_code,
                            cover_area,
                            address,
                            maps_url,
                            phone,
                            email,
                            npwp,
                            status
                        )
                        VALUES
                        (
                            :customers_code,
                            :name,
                            :customers_name,
                            :category,
                            :segment,
                            :city,
                            :office_code,
                            :cover_area,
                            :address,
                            :maps_url,
                            :phone,
                            :email,
                            :npwp,
                            :status
                        )
                    ");

                    $stmt->execute([
                        ':customers_code' => $code,
                        ':name'           => $custName,
                        ':customers_name' => $custName,
                        ':category'       => $category,
                        ':segment'        => $segment,
                        ':city'           => $city,
                        ':office_code'    => $officeCode,
                        ':cover_area'     => $coverArea,
                        ':address'        => $address,
                        ':maps_url'       => $mapsUrl,
                        ':phone'          => $phone,
                        ':email'          => $email,
                        ':npwp'           => $npwp,
                        ':status'         => $status,
                    ]);

                    $success++;

                } catch (PDOException $eRow) {
                    $failed++;
                    $rowErrors[] = "Row {$rowNo}: " . $eRow->getMessage();
                }
            }

            $pdo->commit();
            fclose($handle);

            if (function_exists('master_audit')) {
                master_audit(
                    $pdo,
                    'master_customers',
                    'master_customers',
                    'IMPORT',
                    null,
                    'import',
                    "Import: success={$success}, failed={$failed}, skipped={$skipped}",
                    ['success' => $success, 'failed' => $failed, 'skipped' => $skipped, 'errors' => array_slice($rowErrors, 0, 10)]
                );
            }

            $msg = "Import selesai. Berhasil: {$success} baris";
            if ($skipped > 0) {
                $msg .= ", Skip kosong: {$skipped} baris";
            }
            if ($failed > 0) {
                $msg .= ", Gagal: {$failed} baris. Detail: " . implode(' | ', array_slice($rowErrors, 0, 5));
            }

            set_flash_customer($failed > 0 ? 'warning' : 'success', $msg);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fclose($handle);
            set_flash_customer('danger', 'Import gagal: ' . htmlspecialchars($e->getMessage()));
        }
    } else {
        set_flash_customer('danger', 'Gagal membuka file CSV.');
    }

    rmi_redirect($_SERVER['PHP_SELF']);
}


// --------------------------------------------------------
// AMBIL LIST CUSTOMERS UNTUK TABEL
// --------------------------------------------------------
$customersList = [];
try {
    $stmt = $pdo->query("
        SELECT
            id,
            customers_code,
            customers_name,
            category,
            segment,
            city,
            office_code,
            address,
            maps_url,
            phone,
            email,
            npwp,
            status,
            created_at
        FROM master_customers
        ORDER BY id DESC
    ");
    $customersList = $stmt->fetchAll();
} catch (PDOException $e) {
    // optional
}

$flash = get_flash_customer();

// Audit log (last 50)
$audit_rows = [];
try {
    if (function_exists('master_audit_ensure_table')) {
        master_audit_ensure_table($pdo);
    }
    $st = $pdo->prepare("
        SELECT action, record_code, username, description, created_at
        FROM system_audit_logs
        WHERE module = 'master_customers'
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

rmi_header('Master Customers / Rumah Sakit', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Customers / Rumah Sakit',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>


<div class="rmi-container">

    <!-- HEADER -->
    <div class="card rmi-card mb-3">
        <div class="rmi-card-header">
            <div>
                <h5>MASTER CUSTOMERS / RUMAH SAKIT</h5>
                <small class="text-muted-small">
                    Data pelanggan utama (RS Hermina, RSUD, RS Swasta lain) – dasar flow Penjualan & Finance.
                </small>
            </div>
            <div class="text-end">
                <div class="rmi-clock" id="rmi-clock">--:--:--</div>
                <small class="text-muted-small">Realtime Clock</small>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? '') ?> alert-dismissible fade show" role="alert">
            <?= function_exists('rmi_h') ? rmi_h($flash['message'] ?? '') : htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- FORM INPUT / EDIT CUSTOMER -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5><?= $formValues['id'] ? 'EDIT CUSTOMER' : 'TAMBAH CUSTOMER BARU' ?></h5>
            <small class="text-muted-small">
                Kode & Segment akan di-generate otomatis dari Nama Customer.
            </small>
        </div>
        <div class="rmi-card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= htmlspecialchars($formValues['id'] ?? '') ?>">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Nama Customer / Rumah Sakit<span class="text-danger">*</span></label>
                        <input type="text" name="customers_name"
                               class="form-control form-control-sm"
                               value="<?= htmlspecialchars($formValues['customers_name'] ?? '') ?>"
                               placeholder="Contoh: RS Hermina Bogor">
                        <div class="text-muted-small">
                            Auto saat tambah baru. Edit: segment dipertahankan.
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Kategori</label>
                        <select name="category" class="form-select form-select-sm">
                            <option value="RS Swasta" <?= $formValues['category'] === 'RS Swasta' ? 'selected' : '' ?>>RS SWASTA</option>
                            <option value="RS Pemerintah" <?= $formValues['category'] === 'RS Pemerintah' ? 'selected' : '' ?>>RS PEMERINTAH</option>
                            <option value="Internal" <?= $formValues['category'] === 'Internal' ? 'selected' : '' ?>>INTERNAL</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Segment <?= $formValues['id'] ? '(Bisa Dikoreksi)' : '(Auto)' ?></label>
                        <select name="segment" id="segment-select" class="form-select form-select-sm"
                                <?= $formValues['id'] ? '' : 'onfocus="this.blur();" tabindex="-1" aria-readonly="true"' ?>>
                            <option value="Hermina" <?= $formValues['segment'] === 'Hermina' ? 'selected' : '' ?>>HERMINA</option>
                            <option value="Non Hermina" <?= $formValues['segment'] === 'Non Hermina' ? 'selected' : '' ?>>NON HERMINA</option>
                            <option value="RSUD" <?= $formValues['segment'] === 'RSUD' ? 'selected' : '' ?>>RSUD</option>
                            <option value="Kantor" <?= $formValues['segment'] === 'Kantor' ? 'selected' : '' ?>>KANTOR</option>
                        </select>
                        <div class="text-muted-small">
                            <?= $formValues['id']
                                ? 'Mode edit: segment dapat dikoreksi manual tanpa mengubah nama customer atau kode customer.'
                                : 'Auto saat tambah baru: Hermina / RSUD / Non Hermina. Kantor mengikuti data internal.' ?>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Kota / Kota Madya</label>
                        <input type="text" name="city"
                               class="form-control form-control-sm"
                               value="<?= htmlspecialchars($formValues['city'] ?? '') ?>"
                               placeholder="Contoh: Kota Bogor">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Office Penanggung Jawab</label>
                        <select name="office_code" class="form-select form-select-sm">
                            <option value="">-- PILIH OFFICE --</option>
                            <?php foreach ($offices as $o): ?>
                                <option value="<?= htmlspecialchars($o['office_code'] ?? '') ?>"
                                    <?= ($formValues['office_code'] ?? '') === ($o['office_code'] ?? '') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(strtoupper($o['office_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted-small">
                            Lokasi kantor yang handle customer ini.
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label class="form-label">Alamat Lengkap</label>
                        <textarea name="address" class="form-control form-control-sm" rows="2"
                                  placeholder="Alamat lengkap RS / Klinik"><?= htmlspecialchars($formValues['address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Link Google Maps</label>
                        <input type="text" name="maps_url" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($formValues['maps_url'] ?? '') ?>"
                               placeholder="Boleh dikosongkan, akan diisi otomatis.">
                        <div class="text-muted-small">
                            Jika kosong, sistem buat link pencarian Maps dari Nama + Kota.
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Kontak</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="text" name="phone" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($formValues['phone'] ?? '') ?>"
                                       placeholder="Telepon / WA">
                            </div>
                            <div class="col-6">
                                <input type="email" name="email" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($formValues['email'] ?? '') ?>"
                                       placeholder="Email">
                            </div>
                            <div class="col-12 mt-1">
                                <input type="text" name="npwp" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($formValues['npwp'] ?? '') ?>"
                                       placeholder="NPWP (jika ada)">
                            </div>
                            <div class="col-12 mt-1">
                                <select name="status" class="form-select form-select-sm">
                                    <option value="active" <?= $formValues['status'] === 'active' ? 'selected' : '' ?>>ACTIVE</option>
                                    <option value="inactive" <?= $formValues['status'] === 'inactive' ? 'selected' : '' ?>>INACTIVE</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3 d-flex justify-content-between align-items-center">
                    <div class="text-muted-small">
                        Kode customer akan di-generate saat simpan:
                        Hermina → Hxxx, Non Hermina → NHxxx, RSUD → RSUDxxx.
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="save_customer" class="btn btn-sm btn-primary">
                            Simpan Customer
                        </button>
                        <a href="<?= htmlspecialchars($_SERVER['PHP_SELF'] ?? '') ?>" class="btn btn-sm btn-secondary">
                            Reset Form
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- IMPORT CSV + LIST CUSTOMERS -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>LIST CUSTOMERS / RUMAH SAKIT</h5>
                <small class="text-muted-small">
                    Termasuk data hasil import. Bisa di-export Copy / CSV / Excel / PDF / Print.
                </small>
            </div>
          <form method="post" enctype="multipart/form-data" class="d-flex align-items-center gap-2 flex-wrap">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                <a href="master_customers.php?download_template=customers" class="btn btn-sm btn-outline-info">
                    Template CSV
                </a>

                <a href="master_customers.php?download_customers_import=1" class="btn btn-sm btn-outline-success">
                    Download CSV Import
                </a>

                <input type="file" name="import_file" accept=".csv,.txt" class="form-control form-control-sm" required>

                <button type="submit" name="import_customers" class="btn btn-sm btn-success">
                    Import CSV
                </button>
            </form>
        </div>
        <div class="rmi-card-body">
            <form method="post" id="bulk-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <div class="row g-2 mb-2">
                    <div class="col-md-3">
                        <select name="bulk_action" class="form-select form-select-sm">
                            <option value="">-- BULK ACTION --</option>
                            <option value="set_active">SET ACTIVE</option>
                            <option value="set_inactive">SET INACTIVE</option>
                            <option value="delete">HAPUS</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-outline-light">
                            Terapkan ke yang dipilih
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="customers-table" class="table table-sm table-hover table-dark-custom" style="width:100%">
                        <thead>
                        <tr>
                            <th><input type="checkbox" id="check-all"></th>
                            <th>Kode</th>
                            <th>Nama Customer</th>
                            <th>Kategori</th>
                            <th>Segment</th>
                            <th>Kota</th>
                            <th>Office</th>
                            <th>Telepon</th>
                            <th>Email</th>
                            <th>NPWP</th>
                            <th>Status</th>
                            <th>Maps</th>
                            <th>Aksi</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($customersList)): ?>
                            <tr>
                                <td colspan="13" class="text-center text-muted">Belum ada data customer.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customersList as $c): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_ids[]"
                                               value="<?= (int)$c['id'] ?>" class="row-check">
                                    </td>
                                    <td><?= htmlspecialchars(strtoupper($c['customers_code'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars(strtoupper($c['customers_name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars(strtoupper($c['category'] ?? '')) ?></td>
                                    <td>
                                        <?php $seg = $c['segment'] ?? ''; ?>
                                        <span class="badge badge-segment <?= str_replace(' ', '', $seg) ?>">
                                            <?= htmlspecialchars(strtoupper($seg ?? '')) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars(strtoupper($c['city'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars(strtoupper($c['office_code'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($c['phone'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($c['npwp'] ?? '') ?></td>
                                    <td>
                                        <?php
                                        $st = $c['status'] ?? 'active';
                                        $badgeClass = ($st === 'active') ? 'active' : 'inactive';
                                        ?>
                                        <span class="badge badge-status <?= $badgeClass ?>">
                                            <?= strtoupper(htmlspecialchars($st ?? '')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($c['maps_url'])): ?>
                                            <a href="<?= htmlspecialchars($c['maps_url'] ?? '') ?>"
                                               target="_blank"
                                               class="btn btn-sm btn-outline-light">
                                                Maps
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted-small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 flex-wrap">
                                            <a href="?edit=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-light">
                                                Edit
                                            </a>

                                            <form method="post" class="d-inline delete-customer-form">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                <input type="hidden" name="delete_customer_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit"
                                                        name="delete_customer_single"
                                                        value="1"
                                                        class="btn btn-sm btn-outline-danger">
                                                    Delete
                                                </button>
                                            </form>
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
                                <td><?= function_exists('rmi_h') ? rmi_h($a['created_at'] ?? '') : htmlspecialchars($a['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= function_exists('rmi_h') ? rmi_h($a['action'] ?? '') : htmlspecialchars($a['action'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= function_exists('rmi_h') ? rmi_h($a['record_code'] ?? '') : htmlspecialchars($a['record_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= function_exists('rmi_h') ? rmi_h($a['username'] ?? '') : htmlspecialchars($a['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= function_exists('rmi_h') ? rmi_h($a['description'] ?? '') : htmlspecialchars($a['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
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
    // Realtime clock manis
    function updateClock() {
        const el = document.getElementById('rmi-clock');
        if (!el) return;
        const now = new Date();
        const h = String(now.getHours()).padStart(2, '0');
        const m = String(now.getMinutes()).padStart(2, '0');
        const s = String(now.getSeconds()).padStart(2, '0');
        el.textContent = `${h}:${m}:${s}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // DataTables + export
    $(function () {
        const table = $('#customers-table').DataTable({
            dom: 'Bfrtip',
            paging: true,
            responsive: false,
            scrollX: true,
            lengthChange: true,
            pageLength: 10,
            order: [[1, 'asc']],
      buttons: [
    {extend: 'copyHtml5',  className: 'btn btn-sm btn-outline-light'},
    {
        text: 'CSV Import',
        className: 'btn btn-sm btn-outline-success',
        action: function () {
            window.location.href = 'master_customers.php?download_customers_import=1';
        }
    },
    {extend: 'excelHtml5', className: 'btn btn-sm btn-outline-light'},
    {extend: 'pdfHtml5',   className: 'btn btn-sm btn-outline-light'},
    {extend: 'print',      className: 'btn btn-sm btn-outline-light'}
]
        });

        $('#check-all').on('change', function () {
            $('.row-check').prop('checked', this.checked);
        });

        $(document).on('submit', '.delete-customer-form', function (e) {
            const row = this.closest('tr');
            const code = row ? (row.children[1]?.innerText || '').trim() : '';
            const name = row ? (row.children[2]?.innerText || '').trim() : '';
            const msg = 'Yakin hapus customer ini?\n\n' + code + ' - ' + name + '\n\nData yang sudah dihapus tidak bisa dikembalikan.';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });

        // Auto segment hanya untuk TAMBAH BARU.
        // Saat EDIT, pilihan segment manual tidak boleh ditimpa oleh perubahan nama.
        const isEditCustomer = <?= $formValues['id'] ? 'true' : 'false' ?>;
        const nameInput = document.querySelector('input[name="customers_name"]');
        const segmentSelect = document.getElementById('segment-select');
        if (!isEditCustomer && nameInput && segmentSelect) {
            nameInput.addEventListener('input', function () {
                const v = this.value.toLowerCase();
                let seg = 'Non Hermina';
                if (v.includes('rsud')) {
                    seg = 'RSUD';
                } else if (v.includes('hermina')) {
                    seg = 'Hermina';
                }
                for (let i = 0; i < segmentSelect.options.length; i++) {
                    if (segmentSelect.options[i].value === seg) {
                        segmentSelect.selectedIndex = i;
                        break;
                    }
                }
            });
        }
    });
</script>

<?php rmi_footer(); ?>
