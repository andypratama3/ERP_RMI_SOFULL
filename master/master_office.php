<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

if (!function_exists('safe_filename')) {
    function safe_filename($name) {
        $name = trim((string)$name);
                $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = basename($name); // prevent directory traversal
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = preg_replace('/_{2,}/', '_', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'upload.bin';
        }
        return $name;
    }
}


// =====================================================
// master_office.php
// Master Office ERP_RMI_SOFULL
// - Office Code, Name, City, Address, Phone
// - Status (is_active), Created_by, Created_at
// - CRUD + Bulk + Import CSV + Export (Copy/CSV/Excel/PDF/Print)
// =====================================================

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_audit_master.php';
require_login();
require_any_permission(['MASTER.OFFICE_VIEW', 'MASTER.OFFICE_CREATE', 'MASTER.OFFICE_EDIT']);

// Enforce POST-only + CSRF for all mutations on this page
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
}
// --- DB (centralized) ---
$pdo = db_pdo();

// --------------------------------------------------------
//  AUTO CREATE / MIGRATE TABEL master_office
// --------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `master_office` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `office_code` varchar(10) NOT NULL,
          `office_name` varchar(100) NOT NULL,
          `city` varchar(100) DEFAULT NULL,
          `address` varchar(255) DEFAULT NULL,
          `phone` varchar(50) DEFAULT NULL,
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `created_by` varchar(100) NOT NULL DEFAULT 'SYSTEM',
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // helper cek kolom
    function mo_ensure_column(PDO $pdo, $table, $column, $definition) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
        $stmt->execute([':col' => $column]);
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    }

    // jaga-jaga kalau dari awal belum ada kolom2 ini
    mo_ensure_column($pdo, 'master_office', 'address',
        " `address` varchar(255) DEFAULT NULL AFTER `city`");
    mo_ensure_column($pdo, 'master_office', 'phone',
        " `phone` varchar(50) DEFAULT NULL AFTER `address`");

    // GeoFence (titik kantor dari Google Maps) - tambahan, tidak mengubah flow lama
    mo_ensure_column($pdo, 'master_office', 'office_lat',
        " `office_lat` decimal(15,12) DEFAULT NULL AFTER `phone`");
    mo_ensure_column($pdo, 'master_office', 'office_lng',
        " `office_lng` decimal(15,12) DEFAULT NULL AFTER `office_lat`");
    mo_ensure_column($pdo, 'master_office', 'office_radius_m',
        " `office_radius_m` int DEFAULT 120 AFTER `office_lng`");
    mo_ensure_column($pdo, 'master_office', 'is_active',
        " `is_active` tinyint(1) NOT NULL DEFAULT 1 AFTER `phone`");
    mo_ensure_column($pdo, 'master_office', 'created_by',
        " `created_by` varchar(100) NOT NULL DEFAULT 'SYSTEM' AFTER `is_active`");
    mo_ensure_column($pdo, 'master_office', 'created_at',
        " `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_by`");
    mo_ensure_column($pdo, 'master_office', 'updated_at',
        " `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

    // unique office_code
    try {
        $pdo->exec("ALTER TABLE `master_office` ADD UNIQUE KEY `uq_office_code` (`office_code`)");
    } catch (PDOException $e) {
        // sudah ada
    }

    try {
        $pdo->exec("ALTER TABLE `master_office` MODIFY `office_lat` decimal(15,12) NULL, MODIFY `office_lng` decimal(15,12) NULL");
    } catch (PDOException $e) {
        // ignore if already correct or cannot modify
    }

} catch (PDOException $e) {
    // jangan hentikan halaman
}

// Normalisasi office_code ke UPPERCASE (data lama mungkin lowercase)
try {
    $pdo->exec("UPDATE master_office SET office_code = UPPER(TRIM(office_code)) WHERE BINARY office_code != UPPER(TRIM(office_code))");
} catch (PDOException $e) {
    // ignore
}

// --------------------------------------------------------
//  SESSION & FLASH
// --------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
function set_flash($type, $message)
{
    $_SESSION['flash_office'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function get_flash()
{
    if (!empty($_SESSION['flash_office'])) {
        $f = $_SESSION['flash_office'];
        unset($_SESSION['flash_office']);
        return $f;
    }
    return null;
}

function e($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}


/**
 * Parse koordinat dari Google Maps URL / string "lat,lng".
 * Contoh yang bisa dipaste:
 *  - https://www.google.com/maps/@-6.123456,106.123456,17z
 *  - https://www.google.com/maps?q=-6.123456,106.123456
 *  - -6.123456,106.123456
 */
function mo_parse_coords($text) {
    $text = trim((string)$text);
    if ($text === '') return [null, null];

    // 1) Pola "!3dLAT!4dLNG" (sering muncul pada URL place/detail Google Maps) – diprioritaskan
    if (preg_match('/!3d\s*(-?\d{1,3}\.\d+)\s*!4d\s*(-?\d{1,3}\.\d+)/', $text, $m)) {
        return [(float)$m[1], (float)$m[2]];
    }

    // 2) Pola "@lat,lng" (URL Google Maps umum)
    if (preg_match('/@\s*(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/', $text, $m)) {
        return [(float)$m[1], (float)$m[2]];
    }

    // 3) Pola "?q=lat,lng" atau "?query=lat,lng" atau "?ll=lat,lng"
    if (preg_match('/[?&](?:q|query|ll)=\s*(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/', $text, $m)) {
        return [(float)$m[1], (float)$m[2]];
    }

    // 4) Fallback: teks biasa "lat,lng"
    if (preg_match('/(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/', $text, $m)) {
        return [(float)$m[1], (float)$m[2]];
    }

    return [null, null];
}

/**
 * Default GeoFence (fallback/demo) kalau master_office masih kosong.
 * Catatan: ini hanya titik perkiraan (pusat kota) – untuk produksi tetap
 * disarankan isi titik kantor yang akurat dari Google Maps.
 */
function mo_guess_geofence($office_code, $office_name = '', $city = '') {
    $code = strtolower(trim((string)$office_code));
    $name = strtolower(trim((string)$office_name));
    $cty  = strtolower(trim((string)$city));
    $hay  = trim($code . ' ' . $name . ' ' . $cty);

    // Depo / warehouse sering tidak perlu geofence (sesuai request)
    if (strpos($hay, 'depo') !== false || in_array($code, ['jgy','kal'], true)) {
        return [null, null, null];
    }

    $map = [
        'bgr' => [-6.597147, 106.806039, 150], // Bogor
        'bks' => [-6.238270, 106.975570, 150], // Bekasi
        'tgr' => [-6.178306, 106.631889, 150], // Tangerang
        'bdg' => [-6.917464, 107.619123, 150], // Bandung
        'smg' => [-6.966667, 110.416667, 150], // Semarang
        'slo' => [-7.566667, 110.816667, 150], // Solo / Surakarta
        'jkt' => [-6.208763, 106.845599, 150], // Jakarta
        'dpk' => [-6.402484, 106.794243, 150], // Depok (fallback)
    ];

    if (isset($map[$code])) return $map[$code];

    // keyword based (kalau office_code bukan singkatan kota)
    $keywords = [
        'bogor' => $map['bgr'],
        'bekasi' => $map['bks'],
        'tangerang' => $map['tgr'],
        'bandung' => $map['bdg'],
        'semarang' => $map['smg'],
        'solo' => $map['slo'],
        'surakarta' => $map['slo'],
        'jakarta' => $map['jkt'],
        'depok' => $map['dpk'],
    ];
    foreach ($keywords as $kw => $geo) {
        if (strpos($hay, $kw) !== false) return $geo;
    }

    return [null, null, null];
}


// --------------------------------------------------------
//  SYNC GEOFENCE DARI ABSENSI (absensi_offices -> master_office)
//  Ini berguna kalau titik GPS sudah diset di modul Absensi.
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_from_absensi'])) {
    try {
        $tbl = $pdo->query("SHOW TABLES LIKE 'absensi_offices'")->fetchColumn();
        if (!$tbl) {
            set_flash('danger', 'Tabel absensi_offices tidak ditemukan. Pastikan modul Absensi sudah ter-install (jalankan absensi/_sql/absensi_install.sql atau buka modul absensi sekali).');
            rmi_redirect("master_office.php");
        }

        $rows = $pdo->query("SELECT office_code, office_name, lat, lng, radius_m, is_active FROM absensi_offices")->fetchAll();

        $cekStmt = $pdo->prepare("SELECT id FROM master_office WHERE UPPER(office_code) = :code LIMIT 1");
        $insStmt = $pdo->prepare("
            INSERT INTO master_office
            (office_code, office_name, city, address, phone, office_lat, office_lng, office_radius_m, is_active, created_by, created_at, updated_at)
            VALUES
            (:office_code, :office_name, '', '', '', :office_lat, :office_lng, :office_radius_m, :is_active, 'SYSTEM', NOW(), NOW())
        ");
        $updStmt = $pdo->prepare("
            UPDATE master_office
            SET office_name = :office_name,
                office_lat = :office_lat,
                office_lng = :office_lng,
                office_radius_m = :office_radius_m,
                is_active = :is_active,
                updated_at = NOW()
            WHERE id = :id
        ");

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        $pdo->beginTransaction();

        foreach ($rows as $r) {
            $rawCode = trim((string)($r['office_code'] ?? ''));
            if ($rawCode === '' || strcasecmp($rawCode, 'DEFAULT') === 0) { $skipped++; continue; }

            $code = strtoupper($rawCode);
            $name = (string)($r['office_name'] ?? $code);

            $lat  = $r['lat'];
            $lng  = $r['lng'];
            $rad  = isset($r['radius_m']) && $r['radius_m'] !== null ? (int)$r['radius_m'] : 120;
            $act  = isset($r['is_active']) ? (int)$r['is_active'] : 1;

            if ($lat === null || $lng === null) { $skipped++; continue; }

            $cekStmt->execute([':code' => $code]);
            $id = $cekStmt->fetchColumn();

            if ($id) {
                $updStmt->execute([
                    ':office_name' => $name,
                    ':office_lat' => $lat,
                    ':office_lng' => $lng,
                    ':office_radius_m' => $rad,
                    ':is_active' => $act,
                    ':id' => $id,
                ]);
                $updated++;
            } else {
                $insStmt->execute([
                    ':office_code' => $code,
                    ':office_name' => $name,
                    ':office_lat' => $lat,
                    ':office_lng' => $lng,
                    ':office_radius_m' => $rad,
                    ':is_active' => $act,
                ]);
                $inserted++;
            }
        }

        $pdo->commit();
        if (function_exists('master_audit')) {
            master_audit($pdo, 'master_office', 'master_office', 'SYNC_ABSENSI', null, 'SYNC', "Sync from Absensi: {$inserted} inserted, {$updated} updated", ['inserted' => $inserted, 'updated' => $updated]);
        }
        set_flash('success', "Sync dari Absensi selesai. Insert: {$inserted}, Update: {$updated}, Skip: {$skipped}.");
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('danger', 'Sync gagal: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_office.php");
}

// --------------------------------------------------------
//  AUTO FILL GEOFENCE (fallback/demo) berbasis office_code/city
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autofill_geofence'])) {
    try {
        $rows = $pdo->query("SELECT id, office_code, office_name, city, office_lat, office_lng, office_radius_m FROM master_office")->fetchAll();
        $updStmt = $pdo->prepare("
            UPDATE master_office
            SET office_lat = :lat,
                office_lng = :lng,
                office_radius_m = :rad,
                updated_at = NOW()
            WHERE id = :id
        ");

        $updated = 0;
        $skipped = 0;

        foreach ($rows as $r) {
            $latNow = $r['office_lat'];
            $lngNow = $r['office_lng'];

            // kalau sudah ada, skip
            if ($latNow !== null && $lngNow !== null && $latNow !== '' && $lngNow !== '') {
                $skipped++;
                continue;
            }

            [$lat, $lng, $rad] = mo_guess_geofence($r['office_code'], $r['office_name'] ?? '', $r['city'] ?? '');
            if ($lat === null || $lng === null) { $skipped++; continue; }

            $rad = $rad ?? (int)($r['office_radius_m'] ?? 120);

            $updStmt->execute([
                ':lat' => $lat,
                ':lng' => $lng,
                ':rad' => $rad,
                ':id'  => $r['id'],
            ]);
            $updated++;
        }

        set_flash('success', "Auto Fill GeoFence selesai. Update: {$updated}, Skip: {$skipped}.");
    } catch (PDOException $e) {
        set_flash('danger', 'Auto Fill gagal: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_office.php");
}


// --------------------------------------------------------
//  HANDLE IMPORT CSV
// --------------------------------------------------------
// Format CSV (header boleh ada, baris pertama di-skip):
//  1) format lama (minimal):
//     office_code, office_name, city, address, phone, is_active
//  2) format lengkap (recommended):
//     office_code, office_name, city, address, phone, office_lat, office_lng, office_radius_m, is_active
//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_office'])) {
    if (
        !isset($_FILES['csv_file']) ||
        $_FILES['csv_file']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        set_flash('danger', 'File CSV belum dipilih.');
        rmi_redirect("master_office.php");
    }

    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        set_flash('danger', 'Upload gagal. Error code: ' . (int)$_FILES['csv_file']['error']);
        rmi_redirect("master_office.php");
    }

    $tmpName = $_FILES['csv_file']['tmp_name'];

    $cleanName = safe_filename($_FILES['csv_file']['name'] ?? '');
    $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
    $size = (int)($_FILES['csv_file']['size'] ?? 0);

    if ($ext !== 'csv') {
        set_flash('danger', 'File harus berformat .csv');
        rmi_redirect("master_office.php");
    }
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        set_flash('danger', 'Ukuran file CSV tidak valid (maks 5MB).');
        rmi_redirect("master_office.php");
    }
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        set_flash('danger', 'Upload tidak valid.');
        rmi_redirect("master_office.php");
    }

    try {
        $pdo->beginTransaction();

        $handle = fopen($tmpName, 'r');
        if (!$handle) {
            throw new Exception("Tidak bisa membaca file CSV.");
        }

        $rownum = 0;
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        while (($data = fgetcsv($handle, 0, ",", '"', '\\')) !== false) {
            $rownum++;
            // skip header (baris pertama)
            if ($rownum === 1) continue;

            if (count($data) < 2) { $skipped++; continue; }

            $office_code = strtoupper(trim($data[0] ?? ''));
            $office_name = trim($data[1] ?? '');

            if ($office_code === '' || $office_name === '') { $skipped++; continue; }

            $city    = trim($data[2] ?? '');
            $address = trim($data[3] ?? '');
            $phone   = trim($data[4] ?? '');

            // default
            $office_lat = null;
            $office_lng = null;
            $office_radius_m = null;
            $is_active_raw = null;

            // Deteksi format lengkap
            if (count($data) >= 9) {
                $office_lat = trim($data[5] ?? '');
                $office_lng = trim($data[6] ?? '');
                $office_radius_m = trim($data[7] ?? '');
                $is_active_raw = trim($data[8] ?? '1');
            } else {
                // format lama: is_active ada di kolom ke-6
                $is_active_raw = trim($data[5] ?? '1');
            }

            $is_active = ($is_active_raw === '' || $is_active_raw === '1' || strcasecmp($is_active_raw, 'active') === 0) ? 1 : 0;

            // normalize lat/lng/radius
            $office_lat = ($office_lat === null || $office_lat === '') ? null : (float)$office_lat;
            $office_lng = ($office_lng === null || $office_lng === '') ? null : (float)$office_lng;
            $office_radius_m = ($office_radius_m === null || $office_radius_m === '') ? null : (int)$office_radius_m;

            // cek existing
            $cek = $pdo->prepare("SELECT id FROM master_office WHERE UPPER(office_code) = :code");
            $cek->execute([':code' => $office_code]);
            $existId = $cek->fetchColumn();

            if ($existId) {
                $stmt = $pdo->prepare("
                    UPDATE master_office
                    SET office_name = :office_name,
                        city        = :city,
                        address     = :address,
                        phone       = :phone,
                        office_lat  = COALESCE(:office_lat, office_lat),
                        office_lng  = COALESCE(:office_lng, office_lng),
                        office_radius_m = COALESCE(:office_radius_m, office_radius_m),
                        is_active   = :is_active,
                        updated_at  = NOW()
                    WHERE UPPER(office_code) = :office_code
                ");
                $stmt->execute([
                    ':office_name' => $office_name,
                    ':city'        => $city,
                    ':address'     => $address,
                    ':phone'       => $phone,
                    ':office_lat'  => $office_lat,
                    ':office_lng'  => $office_lng,
                    ':office_radius_m' => $office_radius_m,
                    ':is_active'   => $is_active,
                    ':office_code' => $office_code,
                ]);
                $updated++;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO master_office
                    (office_code, office_name, city, address, phone, office_lat, office_lng, office_radius_m, is_active, created_by, created_at, updated_at)
                    VALUES
                    (:office_code, :office_name, :city, :address, :phone, :office_lat, :office_lng, :office_radius_m, :is_active, 'SYSTEM', NOW(), NOW())
                ");
                $stmt->execute([
                    ':office_code' => $office_code,
                    ':office_name' => $office_name,
                    ':city'        => $city,
                    ':address'     => $address,
                    ':phone'       => $phone,
                    ':office_lat'  => $office_lat,
                    ':office_lng'  => $office_lng,
                    ':office_radius_m' => $office_radius_m ?? 120,
                    ':is_active'   => $is_active,
                ]);
                $inserted++;
            }
        }

        fclose($handle);
        $pdo->commit();

        if (function_exists('master_audit')) {
            master_audit($pdo, 'master_office', 'master_office', 'IMPORT', null, 'IMPORT', "Office import: {$inserted} inserted, {$updated} updated", ['inserted' => $inserted, 'updated' => $updated]);
        }
        set_flash('success', "Import CSV selesai. Insert: {$inserted}, Update: {$updated}, Skip: {$skipped}.");
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('danger', 'Import gagal: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_office.php");
}

// --------------------------------------------------------
//  HANDLE SAVE (CREATE / UPDATE)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_office'])) {

    $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $office_code = strtoupper(trim($_POST['office_code'] ?? ''));
    $office_name = trim($_POST['office_name'] ?? '');
    $city        = trim($_POST['city'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $office_lat_raw = trim($_POST['office_lat'] ?? '');
    $office_lng_raw = trim($_POST['office_lng'] ?? '');
    $office_radius_raw = trim($_POST['office_radius_m'] ?? '');
    $gmaps_url = trim($_POST['gmaps_url'] ?? '');

    // Parsing dari Google Maps URL dinonaktifkan (user akan isi manual)
    // if ($gmaps_url !== '') {
    //     [$pLat, $pLng] = mo_parse_coords($gmaps_url);
    //     if ($pLat !== null && $pLng !== null) {
    //         $office_lat_raw = (string)$pLat;
    //         $office_lng_raw = (string)$pLng;
    //     }
    // }

    // Normalisasi desimal: ubah koma menjadi titik jika ada
    if ($office_lat_raw !== '') {
        $office_lat_raw = str_replace(',', '.', $office_lat_raw);
    }
    if ($office_lng_raw !== '') {
        $office_lng_raw = str_replace(',', '.', $office_lng_raw);
    }


    $office_lat = ($office_lat_raw === '') ? null : (float)$office_lat_raw;
    $office_lng = ($office_lng_raw === '') ? null : (float)$office_lng_raw;
    $office_radius_m = ($office_radius_raw === '') ? 120 : (int)$office_radius_raw;

    $is_active   = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

    $errors = [];

    // Validasi GeoFence (optional)
    if ($office_lat !== null && ($office_lat < -90 || $office_lat > 90)) {
        $errors[] = 'Office Latitude tidak valid (range -90 s/d 90).';
    }
    if ($office_lng !== null && ($office_lng < -180 || $office_lng > 180)) {
        $errors[] = 'Office Longitude tidak valid (range -180 s/d 180).';
    }
    if ($office_radius_m < 30 || $office_radius_m > 5000) {
        $errors[] = 'Office Radius (meter) tidak valid (30 s/d 5000).';
    }


    if ($office_code === '') {
        $errors[] = 'Office Code wajib diisi.';
    }
    if ($office_name === '') {
        $errors[] = 'Office Name wajib diisi.';
    }

    $is_active = ($is_active === 1) ? 1 : 0;

    if (!empty($errors)) {
        set_flash('danger', implode('<br>', $errors));
        rmi_redirect("master_office.php");
    }

    try {
        if ($id === 0) {
            // cek duplikasi code
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_office WHERE UPPER(office_code) = :code");
            $cek->execute([':code' => $office_code]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Office Code sudah digunakan, silakan pakai kode lain.');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO master_office
                    (office_code, office_name, city, address, phone, office_lat, office_lng, office_radius_m, is_active, created_by, created_at, updated_at)
                    VALUES (:office_code, :office_name, :city, :address, :phone, :office_lat, :office_lng, :office_radius_m, :is_active, 'SYSTEM', NOW(), NOW())
                ");
                $stmt->execute([
                    ':office_code' => $office_code,
                    ':office_name' => $office_name,
                    ':city'        => $city,
                    ':address'     => $address,
                    ':phone'       => $phone,
                    ':office_lat'  => $office_lat,
                    ':office_lng'  => $office_lng,
                    ':office_radius_m' => $office_radius_m,
                    ':is_active'   => $is_active,
                ]);
                if (function_exists('master_audit')) {
                    $newId = (int)$pdo->lastInsertId();
                    master_audit($pdo, 'master_office', 'master_office', 'INSERT', $newId, $office_code, "Office created: {$office_code} - {$office_name}", [
                        'city'       => $city,
                        'is_active'  => $is_active,
                    ]);
                }
                set_flash('success', 'Data office baru berhasil ditambahkan.');
            }
        } else {
            // cek duplikasi code untuk id lain
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_office WHERE UPPER(office_code) = :code AND id <> :id");
            $cek->execute([':code' => $office_code, ':id' => $id]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Office Code sudah digunakan oleh office lain.');
            } else {
                $stmt = $pdo->prepare("
                    UPDATE master_office
                    SET office_code = :office_code,
                        office_name = :office_name,
                        city        = :city,
                        address     = :address,
                        phone       = :phone,
                        office_lat  = :office_lat,
                        office_lng  = :office_lng,
                        office_radius_m = :office_radius_m,
                        is_active   = :is_active,
                        updated_at  = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':office_code' => $office_code,
                    ':office_name' => $office_name,
                    ':city'        => $city,
                    ':address'     => $address,
                    ':phone'       => $phone,
                    ':office_lat'  => $office_lat,
                    ':office_lng'  => $office_lng,
                    ':office_radius_m' => $office_radius_m,
                    ':is_active'   => $is_active,
                    ':id'          => $id,
                ]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_office', 'master_office', 'UPDATE', $id, $office_code, "Office updated: {$office_code} - {$office_name}", []);
                }
                set_flash('success', 'Data office berhasil diperbarui.');
            }
        }
    } catch (PDOException $e) {
        set_flash('danger', 'Error DB: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_office.php");
}

// --------------------------------------------------------
//  HANDLE SINGLE DELETE (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        try {
            $stCode = $pdo->prepare("SELECT office_code, office_name FROM master_office WHERE id = :id");
            $stCode->execute([':id' => $id]);
            $row = $stCode->fetch(PDO::FETCH_ASSOC);
            $code = (string)($row['office_code'] ?? '');
            $stmt = $pdo->prepare("DELETE FROM master_office WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_office', 'master_office', 'DELETE', $id, $code, "Office deleted: {$code}", []);
            }
            set_flash('success', 'Data office berhasil dihapus.');
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal menghapus data: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_office.php");
}

// --------------------------------------------------------
//  HANDLE TOGGLE STATUS (AKTIF / NONAKTIF) (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_office'])) {
    $raw = (string)($_POST['toggle_office'] ?? '');
    $parts = explode(':', $raw, 2);
    $id = (int)($parts[0] ?? 0);
    $value = (string)($parts[1] ?? '') === '1' ? 1 : 0;

    if ($id > 0) {
        try {
            $stCode = $pdo->prepare("SELECT office_code FROM master_office WHERE id = :id");
            $stCode->execute([':id' => $id]);
            $code = (string)($stCode->fetchColumn() ?: '');
            $stmt = $pdo->prepare("UPDATE master_office SET is_active = :val, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':val' => $value, ':id' => $id]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_office', 'master_office', 'TOGGLE_ACTIVE', $id, $code, "Office {$code} -> " . ($value ? 'active' : 'inactive'), ['to' => $value]);
            }
            set_flash('success', 'Status office berhasil diubah.');
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal mengubah status: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_office.php");
}

// --------------------------------------------------------
//  HANDLE BULK ACTION
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_bulk']) && isset($_POST['bulk_action']) && $_POST['bulk_action'] !== '' && !empty($_POST['ids'])) {
    $ids = array_map('intval', (array)$_POST['ids']);
    if (!empty($ids)) {
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $act = $_POST['bulk_action'];

        try {
            if ($act === 'activate') {
                $stmt = $pdo->prepare("UPDATE master_office SET is_active = 1, updated_at = NOW() WHERE id IN ($in)");
                $stmt->execute($ids);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_office', 'master_office', 'BULK_ACTIVATE', null, 'BULK', 'Office bulk activate: ' . count($ids) . ' rows', ['count' => count($ids)]);
                }
                set_flash('success', 'Office terpilih berhasil diaktifkan.');
            } elseif ($act === 'deactivate') {
                $stmt = $pdo->prepare("UPDATE master_office SET is_active = 0, updated_at = NOW() WHERE id IN ($in)");
                $stmt->execute($ids);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_office', 'master_office', 'BULK_DEACTIVATE', null, 'BULK', 'Office bulk deactivate: ' . count($ids) . ' rows', ['count' => count($ids)]);
                }
                set_flash('success', 'Office terpilih berhasil dinonaktifkan.');
            } elseif ($act === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM master_office WHERE id IN ($in)");
                $stmt->execute($ids);
                $cnt = $stmt->rowCount();
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_office', 'master_office', 'BULK_DELETE', null, 'BULK', "Office bulk delete: {$cnt} rows", ['count' => $cnt]);
                }
                set_flash('success', 'Office terpilih berhasil dihapus.');
            }
        } catch (PDOException $e) {
            set_flash('danger', 'Bulk action gagal: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_office.php");
}

// --------------------------------------------------------
//  AMBIL DATA EDIT
// --------------------------------------------------------
$edit_data = [
    'id'          => 0,
    'office_code' => '',
    'office_name' => '',
    'city'        => '',
    'address'     => '',
    'phone'       => '',
    'office_lat'  => '',
    'office_lng'  => '',
    'office_radius_m' => 120,
    'is_active'   => 1,
];

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM master_office WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $edit_data = [
                'id'          => $row['id'],
                'office_code' => $row['office_code'],
                'office_name' => $row['office_name'],
                'city'        => $row['city'],
                'address'     => $row['address'],
                'phone'       => $row['phone'],
                'office_lat'  => $row['office_lat'] ?? '',
                'office_lng'  => $row['office_lng'] ?? '',
                'office_radius_m' => (int)($row['office_radius_m'] ?? 120),
                'is_active'   => (int)$row['is_active'],
            ];
        }
    }
}

// --------------------------------------------------------
//  LIST DATA OFFICE
// --------------------------------------------------------
$list_sql = "
    SELECT *
    FROM master_office
    ORDER BY FIELD(office_code,
        'BGR',  -- Bogor
        'BKS',  -- Bekasi
        'TGR',  -- Tangerang
        'BDG',  -- Bandung
        'SLO',  -- Solo
        'SMG',  -- Semarang
        'JGY',  -- Depo Jogyakarta
        'KAL'   -- Depo Kalimantan
    ), office_name ASC
";
$list_stmt = $pdo->query($list_sql);
$offices   = $list_stmt->fetchAll();

$flash = get_flash();

$audit_rows = [];
if (function_exists('master_audit_ensure_table')) {
    master_audit_ensure_table($pdo);
    try {
        $stA = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='master_office' ORDER BY created_at DESC LIMIT 50");
        $stA->execute();
        $audit_rows = $stA->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// --------------------------------------------------------
//  FRONTEND DARK THEME
// --------------------------------------------------------
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Office', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Office',
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
                <h5>MASTER OFFICE</h5>
                <small class="text-muted">
                    Data kantor cabang / depo RMI. Dipakai di modul Departemen, Employees, DO, Invoice, dsb.
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

    <!-- FORM INPUT / EDIT OFFICE -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5><?= $edit_data['id'] ? 'Edit Office' : 'Tambah Office' ?></h5>
        </div>
        <div class="rmi-card-body">
            <form id="save-office-form" method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int)$edit_data['id'] ?>">

                <div class="col-md-3">
                    <label class="form-label">Office Code<span class="text-danger">*</span></label>
                    <input type="text" name="office_code" class="form-control form-control-sm"
                           value="<?= e(strtoupper(trim((string)($edit_data['office_code'] ?? '')))) ?>"
                           placeholder="Contoh: BGR, BKS, TGR" oninput="this.value = this.value.toUpperCase();">
                    <div class="text-muted-small">
                        Kode singkat kantor, unik (dipakai referensi di modul lain).
                    </div>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Office Name<span class="text-danger">*</span></label>
                    <input type="text" name="office_name" class="form-control form-control-sm"
                           value="<?= e($edit_data['office_name']) ?>"
                           placeholder="Nama kantor lengkap">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Kota / Area</label>
                    <input type="text" name="city" class="form-control form-control-sm"
                           value="<?= e($edit_data['city']) ?>"
                           placeholder="Contoh: Kab. Bogor, Kota Bekasi">
                </div>

                <div class="col-md-8">
                    <label class="form-label">Alamat Lengkap</label>
                    <textarea name="address" rows="2" class="form-control form-control-sm"
                              placeholder="Alamat sesuai Google Maps"><?= e($edit_data['address']) ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Telepon / WA Kantor</label>
                    <input type="text" name="phone" class="form-control form-control-sm"
                           value="<?= e($edit_data['phone']) ?>"
                           placeholder="Contoh: 0852-8336-4900">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Google Maps URL / Koordinat</label>
                    <input type="text" name="gmaps_url" class="form-control form-control-sm"
                           value="<?= e($_POST['gmaps_url'] ?? '') ?>"
                           placeholder="Paste link Maps (@lat,lng) atau 'lat,lng'">
                    <div class="text-muted-small">Opsional (referensi saja). Tidak otomatis mengisi. Silakan isi Lat/Lng manual di bawah.</div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Office Lat</label>
                    <input type="text" name="office_lat" class="form-control form-control-sm"
                           value="<?= e((string)$edit_data['office_lat']) ?>"
                           placeholder="Contoh: -6.468256500000">
                    <div class="text-muted-small">Ambil dari Google Maps (Latitude).</div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Office Lng</label>
                    <input type="text" name="office_lng" class="form-control form-control-sm"
                           value="<?= e((string)$edit_data['office_lng']) ?>"
                           placeholder="Contoh: 106.824085700000">
                    <div class="text-muted-small">Ambil dari Google Maps (Longitude).</div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Radius (m)</label>
                    <input type="number" name="office_radius_m" min="30" max="5000" class="form-control form-control-sm"
                           value="<?= (int)$edit_data['office_radius_m'] ?>"
                           placeholder="120">
                    <div class="text-muted-small">Rekomendasi indoor 120–150m.</div>
                </div>


                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-select form-select-sm">
                        <option value="1" <?= $edit_data['is_active'] == 1 ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= $edit_data['is_active'] == 0 ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <button type="submit" name="save_office" class="btn btn-sm btn-primary">
                        <?= $edit_data['id'] ? 'Update Office' : 'Simpan Office' ?>
                    </button>
                    <a href="master_office.php" class="btn btn-sm btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- IMPORT + LIST -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>LIST OFFICE</h5>
                <small class="text-muted">
                    Data kantor untuk integrasi ke Departemen, Employees, DO, Invoice, dsb.
                    Export: Copy / CSV / Excel / PDF / Print.
                </small>
            </div>
        </div>
        <div class="rmi-card-body">

            <!-- FORM IMPORT CSV -->
            <div class="mb-3">
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit" name="sync_from_absensi" class="btn btn-sm btn-outline-info"
                                onclick="return confirm('Sync GeoFence dari Absensi ke Master Office?')">
                            Sync GeoFence dari Absensi
                        </button>
                    </form>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit" name="autofill_geofence" class="btn btn-sm btn-outline-secondary"
                                onclick="return confirm('Auto Fill GeoFence (fallback/demo) untuk office yang belum punya lat/lng?')">
                            Auto Fill GeoFence (Demo)
                        </button>
                    </form>
                    <div class="text-muted-small align-self-center">
                        Jika kamu sudah set GPS di modul Absensi, pakai tombol <b>Sync</b>. Kalau belum ada, pakai <b>Auto Fill</b> dulu (bisa diedit lagi).
                    </div>
                </div>

                <div class="mb-2">
                    <a id="download-template" class="btn btn-sm btn-outline-light" download="office_template.csv"
                       href="data:text/csv;charset=utf-8,office_code,office_name,city,address,phone,office_lat,office_lng,office_radius_m,is_active%0A
BGR,Office Bogor,BOGOR,Alamat 1,08123456789,-6.597147,106.806039,150,1%0A
BDG,Office Bandung,BANDUNG,Alamat 2,08129876543,,,120,0">
                        Download Template CSV
                    </a>
                </div>

                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="col-md-5">
                        <label class="form-label mb-1">Import Office (CSV)</label>
                        <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv">
                        <div class="text-muted-small">
                            Format lengkap (disarankan): office_code, office_name, city, address, phone, office_lat, office_lng, office_radius_m, is_active.<br>
                            Format lama (minimal): office_code, office_name, city, address, phone, is_active.
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="import_office" class="btn btn-sm btn-secondary">
                            Import CSV
                        </button>
                    </div>
                </form>
            </div>

            <!-- BULK ACTION -->
            <form method="post" id="bulk-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="row g-2 mb-2">
                    <div class="col-md-3">
                        <select name="bulk_action" class="form-select form-select-sm">
                            <option value="">-- Aksi Bulk --</option>
                            <option value="activate">Aktifkan Terpilih</option>
                            <option value="deactivate">Nonaktifkan Terpilih</option>
                            <option value="delete">Hapus Terpilih</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary" name="do_bulk" value="1">
                            Terapkan
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="table-office" class="table table-sm table-hover align-middle table-dark-custom" style="width:100%">
                        <thead>
                        <tr>
                            <th><input type="checkbox" id="check-all"></th>
                            <th>#</th>
                            <th>Office Code</th>
                            <th>Office Name</th>
                            <th>Kota / Area</th>
                            <th>Alamat</th>
                            <th>Telepon</th>
                            <th>GeoFence</th>
                            <th>Status</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($offices)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">Belum ada data office.</td>
                            </tr>
                        <?php else: ?>
                            <?php $no = 1; ?>
                            <?php foreach ($offices as $o): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="ids[]" value="<?= (int)$o['id'] ?>">
                                    </td>
                                    <td><?= $no++ ?></td>
                                    <td><?= e(strtoupper($o['office_code'])) ?></td>
                                    <td><?= e(strtoupper($o['office_name'] ?? '')) ?></td>
                                    <td><?= e($o['city']) ?></td>
                                    <td><?= e($o['address']) ?></td>
                                    <td><?= e($o['phone']) ?></td>
                                    <td>
                                        <?php
                                            $lat = $o['office_lat'] ?? null;
                                            $lng = $o['office_lng'] ?? null;
                                            $rad = $o['office_radius_m'] ?? 120;
                                        ?>
                                        <?php if ($lat !== null && $lng !== null && $lat !== '' && $lng !== ''): ?>
                                            <div class="text-muted-small">Lat: <?= e($lat) ?></div>
                                            <div class="text-muted-small">Lng: <?= e($lng) ?></div>
                                            <div class="text-muted-small">R: <?= (int)$rad ?> m</div>
                                            <?php
                                                $mapUrl = 'https://www.google.com/maps/@' . $lat . ',' . $lng . ',18z';
                                            ?>
                                            <div class="mt-1">
                                                <a href="<?= e($mapUrl) ?>" target="_blank" class="btn btn-xs btn-outline-info">Lihat di Maps</a>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted-small">Belum diset</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$o['is_active'] === 1): ?>
                                            <span class="badge-status active">Active</span>
                                        <?php else: ?>
                                            <span class="badge-status inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="master_office.php?edit=<?= (int)$o['id'] ?>"
                                           class="btn btn-sm btn-outline-light mb-1">
                                            Edit
                                        </a>
                                        <?php if ((int)$o['is_active'] === 1): ?>
                                            <button type="submit" name="toggle_office" value="<?= (int)$o['id'] ?>:0"
                                                class="btn btn-sm btn-outline-warning mb-1"
                                                onclick="return confirm('Nonaktifkan office ini?')">
                                                Nonaktifkan
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="toggle_office" value="<?= (int)$o['id'] ?>:1"
                                                class="btn btn-sm btn-outline-success mb-1"
                                                onclick="return confirm('Aktifkan kembali office ini?')">
                                                Aktifkan
                                            </button>
                                        <?php endif; ?>
                                        <button type="submit" name="delete_id" value="<?= (int)$o['id'] ?>"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Yakin hapus office ini? Data terkait di modul lain bisa terpengaruh.')">
                                            Hapus
                                        </button>
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

    <?php if (!empty($audit_rows)): ?>
    <div class="card rmi-card mt-3">
        <div class="rmi-card-header"><b>Audit Log</b> <span class="text-muted">(Last 50 events)</span></div>
        <div class="table-responsive">
            <table class="table table-sm table-dark table-hover mb-0">
                <thead><tr><th style="width:160px">Time</th><th style="width:100px">Action</th><th style="width:120px">Code</th><th style="width:100px">User</th><th>Description</th></tr></thead>
                <tbody>
                <?php foreach ($audit_rows as $a): ?>
                    <tr><td><?= rmi_h($a['created_at'] ?? '') ?></td><td><?= rmi_h($a['action'] ?? '') ?></td><td><?= rmi_h($a['record_code'] ?? '') ?></td><td><?= rmi_h($a['username'] ?? '') ?></td><td><?= rmi_h($a['description'] ?? '') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

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
        // DataTables
        $('#table-office').DataTable({
            dom: 'Bfrtip',
            paging: true,
            responsive: true,
            lengthChange: true,
            pageLength: 20,

             // Pakai kolom "#" (index 1) supaya urutannya ikut hasil SQL
              order: [[1, 'asc']],

             // (Opsional) matikan sort di checkbox & kolom Aksi biar nggak aneh
             columnDefs: [
                { orderable: false, targets: [0, 9] }
            ],
            buttons: [
                {extend: 'copyHtml5',  className: 'btn btn-sm btn-outline-light'},
                {extend: 'csvHtml5',   className: 'btn btn-sm btn-outline-light'},
                {extend: 'excelHtml5', className: 'btn btn-sm btn-outline-light'},
                {extend: 'pdfHtml5',   className: 'btn btn-sm btn-outline-light'},
                {extend: 'print',      className: 'btn btn-sm btn-outline-light'}
            ]
        });

        // Check all
        $('#check-all').on('change', function () {
            const checked = $(this).is(':checked');
            $('#table-office tbody input[type="checkbox"]').prop('checked', checked);
        });

        // Client-side validation for save_office form
        const saveForm = document.getElementById('save-office-form');
        if (saveForm) {
            saveForm.addEventListener('submit', function (e) {
                const latEl = saveForm.querySelector('input[name="office_lat"]');
                const lngEl = saveForm.querySelector('input[name="office_lng"]');
                const radEl = saveForm.querySelector('input[name="office_radius_m"]');
                let lat = latEl && latEl.value.trim() !== '' ? Number(latEl.value) : null;
                let lng = lngEl && lngEl.value.trim() !== '' ? Number(lngEl.value) : null;
                let rad = radEl && radEl.value.trim() !== '' ? Number(radEl.value) : 120;
                const errs = [];
                if (lat !== null && (isNaN(lat) || lat < -90 || lat > 90)) {
                    errs.push('Office Latitude tidak valid (range -90 s/d 90).');
                }
                if (lng !== null && (isNaN(lng) || lng < -180 || lng > 180)) {
                    errs.push('Office Longitude tidak valid (range -180 s/d 180).');
                }
                if (isNaN(rad) || rad < 30 || rad > 5000) {
                    errs.push('Office Radius (meter) tidak valid (30 s/d 5000).');
                }
                if (errs.length > 0) {
                    e.preventDefault();
                    alert(errs.join('\n'));
                }
            });
        }

    });
</script>
<?php rmi_footer(); ?>
