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


// master_products.php
// ERP_RMI_SOFULL - Master Products (tanpa harga; harga dikelola di master_pricelist)
//
// Flow manusiawi (sesuai keputusan):
// - HRL input massal via hrl_reg_alkes/reg_alkes.php setelah AKL/AKD keluar
// - master_products fokus: list + search + status + link docs manufacture + link media
// - EXP barang (LOT/Serial/EXP/QTY) dikelola WQS saat inventory datang (bukan di sini)


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';
require_login();
require_any_permission(['MASTER.PRODUCT_VIEW', 'MASTER.PRODUCT_CREATE', 'MASTER.PRODUCT_EDIT']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        die('Upload terlalu besar. Naikkan upload_max_filesize dan post_max_size di PHP 8.2.');
    }

    verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/_audit_master.php';

// --------------------------------------------------------
// KONEKSI DB (terpusat; baca .env via _shared/db.php)
// --------------------------------------------------------
$pdo = null;
try {
    if (function_exists('db_pdo')) {
        $pdo = db_pdo(); // prefer helper dari master/auth.php
    } elseif (function_exists('rmi_db_pdo')) {
        $pdo = rmi_db_pdo(); // fallback
    } else {
        throw new Exception('DB helper not loaded (db_pdo/rmi_db_pdo)');
    }
} catch (Throwable $e) {
    // Jangan bocorkan detail di production
    $msg = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Internal Server Error';
    die("DB connection failed: " . $msg);
}

// --------------------------------------------------------
// Helpers
// --------------------------------------------------------
function clean_utf8($str) {
    $str = (string)$str;

    // remove BOM
    $str = preg_replace('/^\xEF\xBB\xBF/', '', $str);

    // convert ke UTF-8
    $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');

    // remove karakter aneh non printable
    $str = preg_replace('/[^\x20-\x7E]/u', '', $str);

    return trim($str);
}
if (!function_exists('h')) {
    function h($v) {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function normalize_code(string $s): string {
    // Uppercase + trim; keep dash/underscore; remove extra spaces
    $s = strtoupper(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

/** Folder media canonical. Sama dengan uploader/view/scanner. */
function product_media_folder_name(string $sku): string {
    $sku = trim($sku);
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $sku);
    $safe = preg_replace('/_{2,}/', '_', (string)$safe);
    return trim((string)$safe, '_');
}


/**
 * Resolver lokasi media produk.
 * UNIT_ACC (baik category ALKES maupun AKSESORIS) wajib di:
 *   uploads/products/UNIT ACC/{SKU}/
 * Produk non-UNIT_ACC tetap di lokasi existing uploads/products/{SKU}/ agar alur lama tidak rusak.
 */
function product_media_location(string $sku, string $businessGroup): array {
    $skuSafe = product_media_folder_name($sku);
    $isUnitAcc = strtoupper(trim($businessGroup)) === 'UNIT_ACC';
    $relativeDir = $isUnitAcc ? ('uploads/products/UNIT ACC/' . $skuSafe) : ('uploads/products/' . $skuSafe);
    $canonicalDir = dirname(__DIR__) . '/' . $relativeDir;

    if ($isUnitAcc) {
        $productsRoot = dirname(__DIR__) . '/uploads/products';
        foreach (['unit acc','Unit Acc','UNIT_ACC','unit_acc'] as $groupName) {
            $legacySkuDir = $productsRoot . '/' . $groupName . '/' . $skuSafe;
            if (!is_dir($legacySkuDir) || is_dir($canonicalDir)) continue;
            if (!is_dir(dirname($canonicalDir))) @mkdir(dirname($canonicalDir), 0775, true);
            @rename($legacySkuDir, $canonicalDir); // fail-safe: jika gagal, sumber tetap ada
            if (is_dir($canonicalDir)) break;
        }
    }

    return [
        'sku_safe'=>$skuSafe,
        'is_unit_acc'=>$isUnitAcc,
        'relative_dir'=>$relativeDir,
        'dir'=>$canonicalDir,
    ];
}

/** Kategori fisik produk. UNIT_ACC bukan kategori; ia adalah kelompok bisnis. */
const VALID_PRODUCT_CATEGORIES = ['BMHP', 'ALKES', 'AKSESORIS'];
const VALID_BUSINESS_GROUPS = ['BMHP', 'UNIT_ACC'];

function normalize_category(string $s): string {
    $raw = trim($s);
    if ($raw === '') return 'BMHP';

    // ── UNIT ACC: unit/barang Unit ACC harus tetap terpisah ─────────
    // Diletakkan paling awal agar "UNIT ACC" tidak tertangkap sebagai ALKES.
    if (preg_match('/^(unit[\s_-]*acc|unitacc)$/i', $raw)
        || preg_match('/\bunit[\s_-]*acc\b/i', $raw)) {
        return 'UNIT_ACC';
    }

    // ── ALKES: Alat Kesehatan durable ──────────────────────────────
    if (preg_match('/\b(alkes|alat\s*kes|equipment|device|'
        . 'tensimeter|stetoskop|monitor|respirator|ventilator|infusion|pump|'
        . 'elektr|electr|kendaraan|vehicle|mobil|motor|bangunan|building)\b/i', $raw)) {
        return 'ALKES';
    }

    // ── AKSESORIS: Aksesori alat kesehatan ─────────────────────────
    if (preg_match('/\b(aksesoris|aksesori|accessories?|'
        . 'kabel|cable|sensor|elektroda|electrode|selang|tubing|connector|adaptor|'
        . 'spare\s*part|sparepart|suku\s*cadang)\b/i', $raw)) {
        return 'AKSESORIS';
    }

    // ── BMHP: Bahan Medis Habis Pakai (default) ────────────────────
    return 'BMHP';
}

function require_akl_when_active(string $status, string $no_akl, string $akl_reg_no, string $licence_number, string $category = ''): bool {
    if (strtolower($status) !== 'active') return true;

        $no_akl = trim($no_akl);
    $akl_reg_no = trim($akl_reg_no);
    $licence_number = trim($licence_number);
    return ($no_akl !== '' || $akl_reg_no !== '' || $licence_number !== '');
}
function now(): string { return date('Y-m-d H:i:s'); }

function flash_set(string $type, string $msg): void {
    $_SESSION['flash_products'] = ['type'=>$type,'msg'=>$msg];
}
function flash_get(): ?array {
    $f = $_SESSION['flash_products'] ?? null;
    unset($_SESSION['flash_products']);
    return $f;
}

/**
 * Sinkronkan stock dari Master Products ke tabel WQS.
 * WQS Stock List membaca dari wqs_stock_by_office, jadi update harus masuk ke tabel itu juga.
 */
function sync_product_stock_to_wqs(PDO $pdo, int $product_id, float $qty, string $office_code = 'BGR'): void {
    if ($product_id <= 0) return;

    $office_code = strtoupper(trim($office_code));
    if ($office_code === '') $office_code = 'BGR';

    $now = date('Y-m-d H:i:s');

    try {
        $st = $pdo->prepare("
            INSERT INTO wqs_stock (product_id, stock_qty, updated_at, source)
            VALUES (?, ?, ?, 'MASTER_PRODUCTS')
            ON DUPLICATE KEY UPDATE
                stock_qty = VALUES(stock_qty),
                updated_at = VALUES(updated_at),
                source = VALUES(source)
        ");
        $st->execute([$product_id, $qty, $now]);
    } catch (Throwable $e) {}

    try {
        $up = $pdo->prepare("
            UPDATE wqs_stock_by_office
            SET stock_qty = ?, updated_at = ?
            WHERE office_code = ? AND product_id = ?
        ");
        $up->execute([$qty, $now, $office_code, $product_id]);

        if ($up->rowCount() === 0) {
            $ins = $pdo->prepare("
                INSERT INTO wqs_stock_by_office
                (office_code, product_id, stock_qty, updated_at)
                VALUES (?, ?, ?, ?)
            ");
            $ins->execute([$office_code, $product_id, $qty, $now]);
        }
    } catch (Throwable $e) {}
}

function mp_master_office_exists(PDO $pdo, string $office_code): bool {
    $office_code = strtoupper(trim($office_code));
    if ($office_code === '') return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM master_office WHERE UPPER(TRIM(office_code)) = ? LIMIT 1");
        $st->execute([$office_code]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function mp_load_offices(PDO $pdo): array {
    try {
        $hasActive = false;
        try {
            $c = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='master_office' AND COLUMN_NAME='is_active'");
            $c->execute();
            $hasActive = ((int)$c->fetchColumn() > 0);
        } catch (Throwable $e) { $hasActive = false; }

        $whereActive = $hasActive ? " AND COALESCE(is_active,1)=1" : "";
        $rows = $pdo->query("SELECT
                                UPPER(TRIM(office_code)) AS office_code,
                                COALESCE(NULLIF(TRIM(office_name), ''), UPPER(TRIM(office_code))) AS office_name
                             FROM master_office
                             WHERE office_code IS NOT NULL
                               AND TRIM(office_code) <> ''
                               {$whereActive}
                             ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, fn($r) => trim((string)($r['office_code'] ?? '')) !== ''));
    } catch (Throwable $e) {
        return [];
    }
}

function is_allowed_price_role(): bool {
    // role pending: aman default -> tidak tampil harga
    $role = strtoupper((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? $_SESSION['level'] ?? ''));
    // FIN = canonical, FINANCE deprecated. SYS = privileged.
    return in_array($role, ['FIN','PQP','SYS','ADMIN','SUPERADMIN'], true);
}
function badge_status($s): string {
    $s = strtolower((string)$s);
    if ($s === 'active') return '<span class="badge text-bg-success">ACTIVE</span>';
    if ($s === 'draft') return '<span class="badge text-bg-warning">DRAFT</span>';
    if ($s === 'pending_reg') return '<span class="badge text-bg-info">PENDING_REG</span>';
    return '<span class="badge text-bg-secondary">INACTIVE</span>';
}
function badge_type($t): string {
    $t = strtoupper((string)$t);
    if ($t === 'PAKET') return '<span class="badge text-bg-warning">PAKET</span>';
    return '<span class="badge text-bg-info">SINGLE</span>';
}

// NOTE: audit_log() core sudah disediakan di PATCH_1_CORE.
// Jangan define ulang di file ini untuk menghindari fatal redeclare.

// --------------------------------------------------------
// Template download (CSV)
// --------------------------------------------------------
if (isset($_GET['download_template']) && $_GET['download_template'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_products_import_template.csv"');
    echo "sku,products_name,business_group,category,product_group,unit,product_type,manufacture,status,stock_qty,current_stock,lot_number,akl_reg_no,expired_products,general_name,licence_number,listing_level,office_code\n";
    echo "01070106,SUCTION CATHETER 6FR,BMHP,BMHP,SUCTION_CATHETER,pcs,SINGLE,YAXIN MEDICAL CO LTD,active,100,100,LOT-001,AKL-123,2027-12-31,Nama Umum,LIC-001,1,BGR\n";
    exit;
}
// Template khusus Unit ACC — business_group dikunci UNIT_ACC; category wajib ALKES atau AKSESORIS; office mengikuti kantor barang.
if (isset($_GET['download_template']) && $_GET['download_template'] === 'unit_acc') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_unit_acc.csv"');
    echo "sku,products_name,business_group,category,product_group,unit,product_type,manufacture,status,stock_qty,current_stock,lot_number,akl_reg_no,expired_products,general_name,licence_number,listing_level,office_code\n";
    echo "UACC-ALKES-0001,NAMA ALAT KESEHATAN UNIT ACC,UNIT_ACC,ALKES,UNIT_ACC,unit,SINGLE,,active,0,0,,,,,,,BGR\n";
    echo "UACC-AKS-0001,NAMA AKSESORIS UNIT ACC,UNIT_ACC,AKSESORIS,UNIT_ACC,unit,SINGLE,,active,0,0,,,,,,,BGR\n";
    echo "UACC-ALKES-BDG-0001,CONTOH UNIT ACC BANDUNG,UNIT_ACC,ALKES,UNIT_ACC,unit,SINGLE,,active,0,0,,,,,,,BDG\n";
    exit;
}
// Template format media (kompatibel template_master_products_media.xlsx)
if (isset($_GET['download_template']) && $_GET['download_template'] === 'media') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_master_products_media.csv"');
    echo "sku,name,unit,stock_qty,price,general_name,licence_number,min_price,max_price,listing_level,photo_front,photo_back,photo_box,photo_unpacked,video_front,video_back,video_box,video_unpacked,notes\n";
    echo "01070106,SUCTION CATHETER 6FR,pcs,0,0,Nama Umum,AKL-123,0,0,,front.jpg,back.jpg,,,front.mp4,,,,\n";
    exit;
}

// Normalisasi kategori final. Jangan menebak kategori legacy UNIT_ACC.
try {
    $pdo->exec("UPDATE master_products SET category='BMHP' WHERE category IS NULL OR TRIM(category)=''");
    $pdo->exec("UPDATE master_products SET category='ALKES' WHERE LOWER(TRIM(category))='alkes'");
    $pdo->exec("UPDATE master_products SET category='AKSESORIS' WHERE LOWER(TRIM(category)) IN ('aksesoris','aksesori','accessory','accessories')");
    // category=UNIT_ACC dibiarkan sementara sebagai LEGACY agar direklasifikasi via template, bukan ditebak otomatis.
} catch (Throwable $e) { /* fail-soft */ }

// --------------------------------------------------------
// Dropdowns
// --------------------------------------------------------
$manufactures = [];
try {
    $manufactures = $pdo->query("
        SELECT
          id,
          COALESCE(NULLIF(manufacture_code,''), manufactures_code) AS code,
          COALESCE(NULLIF(manufacture_name,''), manufactures_name) AS name
        FROM master_manufactures
        WHERE (status='active' OR status=1)
        ORDER BY name
    ")->fetchAll();
} catch (Throwable $e) { $manufactures = []; }

$categories = VALID_PRODUCT_CATEGORIES;

// Business group memisahkan pencapaian BMHP vs UNIT ACC tanpa mengubah office/category.
try {
    $stBg = $pdo->prepare("SHOW COLUMNS FROM master_products LIKE 'business_group'");
    $stBg->execute();
    if (!$stBg->fetch()) {
        $pdo->exec("ALTER TABLE master_products ADD COLUMN business_group VARCHAR(20) NOT NULL DEFAULT 'BMHP' AFTER category");
    }
    // Legacy UNIT_ACC dipertahankan sebagai penanda untuk reklasifikasi, jangan menebak ALKES/AKSESORIS.
    $pdo->exec("UPDATE master_products SET business_group='UNIT_ACC' WHERE UPPER(TRIM(COALESCE(category,'')))='UNIT_ACC'");
    $pdo->exec("UPDATE master_products SET business_group='BMHP' WHERE business_group IS NULL OR TRIM(business_group)='' OR UPPER(TRIM(business_group)) NOT IN ('BMHP','UNIT_ACC')");
} catch (Throwable $e) { /* fail-soft */ }
// --------------------------------------------------------
// Actions: bulk / save / toggle status / delete
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['upload_product_media'])) {
    require_any_permission(['MASTER.PRODUCT_MEDIA_UPLOAD', 'MASTER.PRODUCT_EDIT']);
    $product_id = (int)($_POST['product_id'] ?? 0);
    $slot = $_POST['slot'] ?? 'front';

    $allowedSlots = ['front','back','box','unpacked'];
    if (!in_array($slot, $allowedSlots, true)) {
        flash_set('danger', 'Slot media tidak valid.');
        rmi_redirect('master_products.php');
    }

    $st = $pdo->prepare("SELECT id, sku, business_group, category FROM master_products WHERE id=? LIMIT 1");
    $st->execute([$product_id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        flash_set('danger', 'Produk tidak ditemukan.');
        rmi_redirect('master_products.php');
    }

    if (empty($_FILES['media_file']['name'])) {
        flash_set('danger', 'File media belum dipilih.');
        rmi_redirect('master_products.php?upload_media=' . $product_id);
    }

    $maxMediaSize = 200 * 1024 * 1024; // 200MB
    $mediaSize = (int)($_FILES['media_file']['size'] ?? 0);
    if ($mediaSize <= 0 || $mediaSize > $maxMediaSize) {
        flash_set('danger', 'Ukuran file media tidak valid. Maksimal upload media 200MB.');
        rmi_redirect('master_products.php?upload_media=' . $product_id);
    }
    if (($_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Upload media gagal. Kode error: ' . (int)($_FILES['media_file']['error'] ?? -1));
        rmi_redirect('master_products.php?upload_media=' . $product_id);
    }

    $tmpMedia = (string)($_FILES['media_file']['tmp_name'] ?? '');
    if ($tmpMedia === '' || !is_uploaded_file($tmpMedia)) {
        flash_set('danger', 'Upload media tidak valid.');
        rmi_redirect('master_products.php?upload_media=' . $product_id);
    }

    $origExt = strtolower(pathinfo((string)$_FILES['media_file']['name'], PATHINFO_EXTENSION));
    $mediaLoc = product_media_location((string)$p['sku'], (string)($p['business_group'] ?? ''));
    $isUnitAcc = (bool)$mediaLoc['is_unit_acc'];

    // UNIT ACC memakai format baku lengkap: 4 JPG + 2 MP4.
    if ($isUnitAcc) {
        $imageSlots = ['front','back','box','unpacked'];
        $videoSlots = ['front','unpacked'];
        $isJpeg = in_array($origExt, ['jpg','jpeg'], true);
        $isMp4 = ($origExt === 'mp4');

        if ($isJpeg && in_array($slot, $imageSlots, true)) {
            $ext = 'jpg';
            $fileType = 'image';
        } elseif ($isMp4 && in_array($slot, $videoSlots, true)) {
            $ext = 'mp4';
            $fileType = 'video';
        } else {
            flash_set('danger', 'Media UNIT ACC wajib: front.jpg, back.jpg, box.jpg, unpacked.jpg, front.mp4, atau unpacked.mp4. Video hanya untuk Front/Unpacked.');
            rmi_redirect('master_products.php?upload_media=' . $product_id);
        }
    } else {
        $allowedExt = ['jpg','jpeg','png','webp','mp4','mov'];
        if (!in_array($origExt, $allowedExt, true)) {
            flash_set('danger', 'Format hanya jpg, jpeg, png, webp, mp4, mov.');
            rmi_redirect('master_products.php?upload_media=' . $product_id);
        }
        $ext = $origExt;
        $fileType = in_array($ext, ['mp4','mov'], true) ? 'video' : 'image';
    }

    $dir = $mediaLoc['dir'];
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        flash_set('danger', 'Folder media tidak dapat dibuat. Periksa permission ' . $mediaLoc['relative_dir'] . '.');
        rmi_redirect('master_products.php?upload_media=' . $product_id);
    }

    $filename = $slot . '.' . $ext;
    $target = $dir . '/' . $filename;
    $tmpTarget = $dir . '/.' . $slot . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($tmpMedia, $tmpTarget)) {
        flash_set('danger', 'Gagal menyimpan file media ke folder produk.');
        rmi_redirect('master_products.php?upload_media=' . $product_id);
    }

    // Replacement aman: file baru sudah tersimpan sebelum file lama slot/type dibersihkan.
    $oldExts = $fileType === 'video' ? ['mp4','mov','mkv','avi','webm'] : ['jpg','jpeg','png','webp'];
    foreach ($oldExts as $oldExt) {
        $old = $dir . '/' . $slot . '.' . $oldExt;
        if (is_file($old)) @unlink($old);
        // bersihkan double extension canonical lama jika ada
        $double = $dir . '/' . $slot . '.' . $oldExt . '.' . $oldExt;
        if (is_file($double)) @unlink($double);
    }

    if (!@rename($tmpTarget, $target)) {
        @unlink($tmpTarget);
        flash_set('danger', 'Media produk gagal difinalisasi.');
        rmi_redirect('master_products.php?upload_media=' . $product_id);
    }

    $filePath = '/' . $mediaLoc['relative_dir'] . '/' . $filename;

    // Hapus record DB untuk slot + jenis yang sama supaya tidak ada stale path.
    $delMedia = $pdo->prepare("DELETE FROM master_product_media WHERE sku=? AND file_type=? AND LOWER(file_name) LIKE ?");
    $delMedia->execute([$p['sku'], $fileType, strtolower($slot) . '.%']);
    $pdo->prepare("\n        INSERT INTO master_product_media\n        (sku, file_name, file_type, file_path, uploaded_by)\n        VALUES (?, ?, ?, ?, ?)\n    ")->execute([
        $p['sku'],
        $filename,
        $fileType,
        $filePath,
        $_SESSION['user_id'] ?? null
    ]);

    flash_set('success', 'Media produk berhasil diupload.');
    rmi_redirect('master_products.php');
}

    // Bulk actions (FASE 3)
    if (isset($_POST['bulk_action']) && is_array($_POST['ids'] ?? null)) {
        $action = (string)$_POST['bulk_action'];
        $ids = array_values(array_filter(array_map('intval', (array)$_POST['ids'])));
        if (!$ids) {
            flash_set('warning', 'Tidak ada data yang dipilih.');
            rmi_redirect('master_products.php');
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->beginTransaction();
        try {
            // --- PATCH_3_AUDIT: capture before snapshot ---
            $beforeRows = [];
            try {
                $stb = $pdo->prepare("SELECT * FROM master_products WHERE id IN ($in)");
                $stb->execute($ids);
                $beforeRows = $stb->fetchAll();
            } catch (Throwable $e) {
                $beforeRows = [];
            }

            if ($action === 'activate') {
                $st = $pdo->prepare("UPDATE master_products SET status='active', updated_at=NOW() WHERE id IN ($in)");
                $st->execute($ids);
                $afterRows = [];
                try {
                    $sta = $pdo->prepare("SELECT * FROM master_products WHERE id IN ($in)");
                    $sta->execute($ids);
                    $afterRows = $sta->fetchAll();
                } catch (Throwable $e) {
                    $afterRows = [];
                }
                rmi_audit_safe('UPDATE', 'MASTER.PRODUCTS', $ids, $beforeRows, $afterRows, [
                    'event' => 'bulk_activate',
                    'count' => count($ids),
                ]);
                if (function_exists('master_audit')) {
                    $codes = array_column($beforeRows, 'sku');
                    master_audit($pdo, 'master_products', 'master_products', 'BULK_ACTIVATE', null, 'bulk:' . count($ids), 'Bulk activate ' . count($ids) . ' products', ['ids' => $ids, 'skus' => array_slice($codes, 0, 20)]);
                }
                flash_set('success', 'Berhasil mengaktifkan ' . count($ids) . ' produk.');
            } elseif ($action === 'deactivate') {
                $st = $pdo->prepare("UPDATE master_products SET status='inactive', updated_at=NOW() WHERE id IN ($in)");
                $st->execute($ids);
                $afterRows = [];
                try {
                    $sta = $pdo->prepare("SELECT * FROM master_products WHERE id IN ($in)");
                    $sta->execute($ids);
                    $afterRows = $sta->fetchAll();
                } catch (Throwable $e) {
                    $afterRows = [];
                }
                rmi_audit_safe('UPDATE', 'MASTER.PRODUCTS', $ids, $beforeRows, $afterRows, [
                    'event' => 'bulk_deactivate',
                    'count' => count($ids),
                ]);
                if (function_exists('master_audit')) {
                    $codes = array_column($beforeRows, 'sku');
                    master_audit($pdo, 'master_products', 'master_products', 'BULK_DEACTIVATE', null, 'bulk:' . count($ids), 'Bulk deactivate ' . count($ids) . ' products', ['ids' => $ids, 'skus' => array_slice($codes, 0, 20)]);
                }
                flash_set('success', 'Berhasil menonaktifkan ' . count($ids) . ' produk.');
            } elseif ($action === 'delete') {
                // Hard delete (gunakan hati-hati). Karena tidak ada deleted_at di schema.
                $st = $pdo->prepare("DELETE FROM master_products WHERE id IN ($in)");
                $st->execute($ids);
                rmi_audit_safe('DELETE', 'MASTER.PRODUCTS', $ids, $beforeRows, null, [
                    'event' => 'bulk_delete',
                    'count' => count($ids),
                ]);
                if (function_exists('master_audit')) {
                    $codes = array_column($beforeRows, 'sku');
                    master_audit($pdo, 'master_products', 'master_products', 'BULK_DELETE', null, 'bulk:' . count($ids), 'Bulk delete ' . count($ids) . ' products', ['ids' => $ids, 'skus' => array_slice($codes, 0, 20)]);
                }
                flash_set('success', 'Berhasil menghapus ' . count($ids) . ' produk.');
            } else {
                flash_set('danger', 'Bulk action tidak dikenal.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('danger', 'Bulk gagal: ' . $e->getMessage());
        }
        rmi_redirect('master_products.php');
    }

    // Import CSV/XLSX (FASE 3) - tanpa harga
    if (isset($_POST['do_import']) && isset($_FILES['import_file'])) {
        // Import adalah mutasi master: wajib permission khusus, bukan sekadar VIEW halaman.
        require_any_permission(['MASTER.IMPORT_PRODUCTS']);
        $mode = (string)($_POST['import_mode'] ?? 'skip'); // skip|upsert
        $file = $_FILES['import_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash_set('danger', 'Upload gagal.');
            rmi_redirect('master_products.php');
        }
        $cleanName = safe_filename($file['name'] ?? '');
        $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
        $size = (int)($file['size'] ?? 0);
        $tmp = $file['tmp_name'] ?? '';

        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            flash_set('danger', 'File harus CSV atau XLSX.');
            rmi_redirect('master_products.php');
        }
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            flash_set('danger', 'Ukuran file tidak valid (maks 10MB).');
            rmi_redirect('master_products.php');
        }
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            flash_set('danger', 'Upload tidak valid.');
            rmi_redirect('master_products.php');
        }

        // helper read rows
        $rows = [];
        $errors = [];

        if ($ext === 'csv') {
            $fh = fopen($tmp, 'r');
            if ($fh) {
                $header = null;
                while (($data = fgetcsv($fh)) !== false) {
                    if (!$header) { $header = array_map('trim', $data); continue; }
                    $row = [];
                    foreach ($header as $i=>$k) $row[$k] = $data[$i] ?? '';
                    $rows[] = $row;
                }
                fclose($fh);
            }
        } elseif ($ext === 'xlsx') {
            // minimal xlsx reader: uses ZipArchive + sharedStrings.xml
            if (!class_exists('ZipArchive')) {
                $errors[] = 'ZipArchive tidak aktif; pakai CSV saja.';
            } else {
                $zip = new ZipArchive();
                if ($zip->open($tmp) === true) {
                    $shared = [];
                    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
                    if ($sharedXml) {
                        preg_match_all('/<(?:[A-Za-z0-9_]+:)?t[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?t>/s', $sharedXml, $m);
                        foreach ($m[1] as $s) $shared[] = html_entity_decode(strip_tags($s));
                    }
                    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
                    if ($sheetXml) {
                        $grid = [];
                        preg_match_all('/<(?:[A-Za-z0-9_]+:)?row[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?row>/s', $sheetXml, $rm);
                        foreach ($rm[1] as $rowXml) {
                            // Toleran terhadap XLSX dengan namespace prefix (mis. <x:c>)
                            // dan posisi atribut r/t yang berbeda antar generator Excel.
                            preg_match_all('/<(?:[A-Za-z0-9_]+:)?c\b([^>]*)>(.*?)<\/(?:[A-Za-z0-9_]+:)?c>/s', $rowXml, $cm, PREG_SET_ORDER);
                            $rowArr = [];
                            foreach ($cm as $c) {
                                $attrs = $c[1] ?? '';
                                $vXml = $c[2] ?? '';
                                if (!preg_match('/\br="([A-Z]+)(\d+)"/', $attrs, $refm)) continue;
                                $col = $refm[1];

                                $t = '';
                                if (preg_match('/\bt="([^"]+)"/', $attrs, $tm)) {
                                    $t = strtolower((string)$tm[1]);
                                }

                                $v = '';
                                if ($t === 'inlinestr') {
                                    if (preg_match('/<(?:[A-Za-z0-9_]+:)?t[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?t>/s', $vXml, $vm)) {
                                        $v = html_entity_decode(strip_tags($vm[1] ?? ''));
                                    }
                                } elseif (preg_match('/<(?:[A-Za-z0-9_]+:)?v>(.*?)<\/(?:[A-Za-z0-9_]+:)?v>/s', $vXml, $vm)) {
                                    $v = html_entity_decode((string)($vm[1] ?? ''));
                                    if ($t === 's') $v = $shared[(int)$v] ?? '';
                                }

                                $rowArr[$col] = $v;
                            }
                            $grid[] = $rowArr;
                        }
                        // map A,B,C.. to header, then rows
                        $cols = range('A','Z');
                        $headerRow = $grid[0] ?? [];
                       $headerIndex = 0;
foreach ($grid as $idx => $gr) {
    $vals = array_map('strtoupper', array_map('trim', array_values($gr)));
    if (in_array('SKU', $vals, true)) {
        $headerIndex = $idx;
        break;
    }
}

$headerRow = $grid[$headerIndex] ?? [];

// ambil header kolom (A,B,C...)
$header = [];
foreach ($headerRow as $col => $val) {
    $header[] = strtolower(trim($val));
}

// loop data
for ($i = $headerIndex + 1; $i < count($grid); $i++) {
    $r = $grid[$i];
    if (!$r) continue;

    $row = [];
    foreach ($header as $j => $k) {
        $col = $cols[$j];
        $row[$k] = $r[$col] ?? '';
    }
    $rows[] = $row;
}
                    } else {
                        $errors[] = 'Sheet1 tidak ditemukan.';
                    }
                    $zip->close();
                } else {
                    $errors[] = 'File XLSX tidak bisa dibuka.';
                }
            }
        } else {
            $errors[] = 'Format tidak didukung. Pakai CSV/XLSX.';
        }

        if ($errors) {
            flash_set('danger', implode(' ', $errors));
            rmi_redirect('master_products.php');
        }

        // Normalize & insert
        $countInsert=0; $countUpdate=0; $countSkip=0; $countError=0;
        $pdo->beginTransaction();
        try {
            foreach ($rows as $i=>$r) {
                $r = array_change_key_case($r, CASE_LOWER);
                if (isset($r['general name'])) $r['general_name'] = $r['general name'];
if (isset($r['licence number'])) $r['licence_number'] = $r['licence number'];
if (isset($r['license number'])) $r['licence_number'] = $r['license number'];

if (isset($r['nama'])) $r['products_name'] = $r['nama'];
if (isset($r['tipe'])) $r['product_type'] = $r['tipe'];
if (isset($r['akl/akd'])) $r['no_akl'] = $r['akl/akd'];
if (isset($r['manufacture'])) {
    $r['manufacture_name'] = trim($r['manufacture']);
}
foreach ($r as $k => $v) {
    $r[$k] = clean_utf8($v);
}
                $sku = normalize_code(clean_utf8($r['sku'] ?? ''));
$name = clean_utf8($r['products_name'] ?? '');

if ($sku === '' || $name === '') {
    $countError++;
    $errors[] = "Row " . ($i + 2) . ": SKU / products_name kosong.";
    continue;
}
$unit = clean_utf8($r['unit'] ?? '');
                $name = trim((string)($r['products_name'] ?? $r['Nama'] ?? $r['name'] ?? ''));
                $category = trim((string)($r['category'] ?? ''));
                $category = normalize_category($category);
                $business_group = strtoupper(trim((string)($r['business_group'] ?? 'BMHP')));
                if (!in_array($business_group, VALID_BUSINESS_GROUPS, true)) $business_group = 'BMHP';
                $product_group = trim((string)($r['product_group'] ?? ''));
                $unit = trim((string)($r['unit'] ?? 'unit'));
                $lot_number = trim((string)(
    $r['lot_number']
    ?? $r['lot number']
    ?? $r['lot']
    ?? $r['no_lot']
    ?? $r['nomor_lot']
    ?? ''
));


                $ptype = strtoupper(trim((string)($r['product_type'] ?? 'SINGLE')));
                if (!in_array($ptype, ['SINGLE','PAKET'], true)) $ptype='SINGLE';
                $mcode = trim((string)($r['manufacture_code'] ?? $r['manufactures_code'] ?? ''));
                $status = strtolower(trim((string)($r['status'] ?? 'active')));

                // Final classification: UNIT_ACC adalah business group, bukan category.
                if ($category === 'UNIT_ACC' || !in_array($category, VALID_PRODUCT_CATEGORIES, true)) {
                    $countError++;
                    $errors[] = "Row " . ($i+2) . ": category wajib BMHP / ALKES / AKSESORIS. Untuk produk Unit ACC isi business_group=UNIT_ACC.";
                    continue;
                }
                if ($business_group === 'UNIT_ACC' && !in_array($category, ['ALKES','AKSESORIS'], true)) {
                    $countError++;
                    $errors[] = "Row " . ($i+2) . ": business_group UNIT_ACC wajib category ALKES atau AKSESORIS.";
                    continue;
                }
                if ($business_group === 'BMHP' && $category === 'BMHP') { /* normal BMHP */ }

                // Policy: jika ACTIVE wajib punya nomor AKL/NIE
 $no_akl_row = trim((string)($r['no_akl'] ?? $r['akl_reg_no'] ?? $r['licence_number'] ?? ''));
$akl_reg_no_row = trim((string)($r['akl_reg_no'] ?? ''));
$licence_number_row = trim((string)($r['licence_number'] ?? ''));

$no_akl = $no_akl_row;
$akl_reg_no = $akl_reg_no_row ?: $no_akl_row;

if (!require_akl_when_active($status, $no_akl_row, $akl_reg_no_row, $licence_number_row, $category)) {
    $countError++;
    $errors[] = "Row " . ($i+2) . ": status ACTIVE wajib punya No AKL/NIE sesuai policy produk aktif.";
    continue;
}

$stock_qty = (int)($r['stock_qty'] ?? 0);

$current_stock = isset($r['current_stock']) && $r['current_stock'] !== ''
    ? (int)$r['current_stock']
    : $stock_qty;
$office_code = strtoupper(trim((string)($r['office_code'] ?? 'BGR')));

if ($office_code === '') {
    $office_code = 'BGR';
}

if (!mp_master_office_exists($pdo, $office_code)) {
    $countError++;
    $errors[] = "Row " . ($i + 2) . ": office_code tidak valid: " . $office_code . " (tambahkan dulu di master_office).";
    continue;
}
$expiredRaw = trim((string)($r['expired_products'] ?? ''));

$exp_date = null;
if ($expiredRaw !== '' && $expiredRaw !== '-' && strtolower($expiredRaw) !== 'null') {
    $ts = strtotime($expiredRaw);
    if ($ts !== false) {
        $exp_date = date('Y-m-d', $ts);
    }
}
    $general_name = trim((string)($r['general_name'] ?? ''));

$licence_number = trim((string)(
    $r['licence_number'] 
    ?? $r['licence number'] 
    ?? $r['licence_no'] 
    ?? $r['license_number'] 
    ?? ''
));
$min_price = isset($r['min_price']) && trim((string)$r['min_price']) !== ''
    ? (int)$r['min_price']
    : 0;

$max_price = isset($r['max_price']) && trim((string)$r['max_price']) !== ''
    ? (int)$r['max_price']
    : 0;

$listing_level_raw = trim((string)($r['listing_level'] ?? ''));
$listing_level = ($listing_level_raw === '') ? null : (int)$listing_level_raw;
                $price = (float)($r['price'] ?? 0);
                $photo_front = trim((string)($r['photo_front'] ?? ''));
                $photo_back = trim((string)($r['photo_back'] ?? ''));
                $photo_box = trim((string)($r['photo_box'] ?? ''));
                $photo_unpacked = trim((string)($r['photo_unpacked'] ?? ''));
                $video_front = trim((string)($r['video_front'] ?? ''));
                $video_back = trim((string)($r['video_back'] ?? ''));
                $video_box = trim((string)($r['video_box'] ?? ''));
                $video_unpacked = trim((string)($r['video_unpacked'] ?? ''));

     $mid = null;
$mname = trim((string)($r['manufacture_name'] ?? $r['manufacture'] ?? ''));

if ($mcode !== '' || $mname !== '') {
    $st = $pdo->prepare("
        SELECT id FROM master_manufactures
        WHERE manufacture_code = ?
           OR manufactures_code = ?
           OR manufacture_name = ?
           OR manufactures_name = ?
           OR LOWER(TRIM(manufacture_name)) = LOWER(TRIM(?))
           OR LOWER(TRIM(manufactures_name)) = LOWER(TRIM(?))
        LIMIT 1
    ");
    $st->execute([$mcode, $mcode, $mname, $mname, $mname, $mname]);
    $mid = $st->fetchColumn() ?: null;
}

                $st = $pdo->prepare("
    SELECT p.id
    FROM master_products p
    WHERE UPPER(TRIM(p.sku)) = UPPER(TRIM(?))
      AND UPPER(TRIM(p.products_name)) = UPPER(TRIM(?))
      AND COALESCE(UPPER(TRIM(p.lot_number)), '') = COALESCE(UPPER(TRIM(?)), '')
    LIMIT 1
");
$st->execute([
    $sku,
    $name,
    $lot_number
]);
$existingId = $st->fetchColumn();

                if ($existingId) {
                    if ($mode === 'upsert') {
                        $up = $pdo->prepare("UPDATE master_products SET
                            lot_number=:lot_number,
current_stock=:current_stock,
akl_reg_no=:akl_reg_no,
exp_date=:exp_date,
    products_name=:name,
    category=:category,
    business_group=:business_group,
    product_group=:pg,
    no_akl=:no_akl,
    unit=:unit,
    product_type=:ptype,
    manufacture_id=:mid,
    status=:status,
                            general_name=:general_name, licence_number=:licence_number, min_price=:min_price, max_price=:max_price, listing_level=:listing_level,
                            stock_qty=:stock_qty, price=:price,
                            photo_front=:photo_front, photo_back=:photo_back, photo_box=:photo_box, photo_unpacked=:photo_unpacked,
                            video_front=:video_front, video_back=:video_back, video_box=:video_box, video_unpacked=:video_unpacked,
                            updated_at=NOW() WHERE id=:id");
                        $up->execute([
                            ':lot_number'=>($lot_number !== '' ? $lot_number : null),

                            ':name'=>$name, ':category'=>$category, ':business_group'=>$business_group, ':pg'=>$product_group, ':unit'=>$unit, ':ptype'=>$ptype,
                            ':mid'=>$mid, ':status'=>$status, ':no_akl' => $no_akl_row ?: null, ':id'=>$existingId,
                            ':general_name'=>$general_name ?: null, ':licence_number'=>$licence_number ?: null,
                            ':min_price'=>$min_price, ':max_price'=>$max_price, ':listing_level'=>$listing_level,
                            ':stock_qty'=>$stock_qty, ':price'=>$price,
                            ':photo_front'=>$photo_front ?: null, ':photo_back'=>$photo_back ?: null, ':photo_box'=>$photo_box ?: null, ':photo_unpacked'=>$photo_unpacked ?: null,
                            ':video_front'=>$video_front ?: null, ':video_back'=>$video_back ?: null, ':video_box'=>$video_box ?: null, ':video_unpacked'=>$video_unpacked ?: null,
':current_stock'=>$current_stock,
':akl_reg_no'=>$akl_reg_no ?: null,
':exp_date'=>$exp_date,
                        ]);
                        sync_product_stock_to_wqs($pdo, (int)$existingId, (float)$current_stock, $office_code);
                        $countUpdate++;
                    } else {
                        $countSkip++;
                    }
                } else {
                    $ins = $pdo->prepare("INSERT INTO master_products
(
    sku, products_name, category, business_group, product_group, no_akl, manufacture_id, vendor_id,
    stock_qty, price, unit, status,
    general_name, licence_number,
    min_price, max_price, listing_level,
    barcode, lot_number, akl_reg_no, exp_date,
    current_stock, product_type,
    photo_front, photo_back, photo_box, photo_unpacked,
    video_front, video_back, video_box, video_unpacked
)
VALUES
(
    :sku,:name,:category,:business_group,:pg,:no_akl,:mid,NULL,
    :stock_qty,:price,:unit,:status,
    :general_name,:licence_number,
    :min_price,:max_price,:listing_level,
    NULL,:lot_number,:akl_reg_no,:exp_date,
:current_stock,:ptype,
    :photo_front,:photo_back,:photo_box,:photo_unpacked,
    :video_front,:video_back,:video_box,:video_unpacked

)");
                    $ins->execute([
     ':no_akl' => $no_akl_row ?: null,                   
    ':sku'=>$sku,
    ':name'=>$name,
    ':category'=>$category,
    ':business_group'=>$business_group,
    ':pg'=>$product_group,
    ':mid'=>$mid,
    ':unit'=>$unit,
    ':status'=>$status,
    ':ptype'=>$ptype,
    ':stock_qty'=>$stock_qty,
    ':current_stock'=>$current_stock,
    ':price'=>$price,
    ':general_name'=>($general_name !== '' ? $general_name : null),
':licence_number'=>($licence_number !== '' ? $licence_number : null),
    ':min_price'=>$min_price,
    ':max_price'=>$max_price,
    ':listing_level'=>$listing_level,
    ':photo_front'=>$photo_front ?: null,
    ':photo_back'=>$photo_back ?: null,
    ':photo_box'=>$photo_box ?: null,
    ':photo_unpacked'=>$photo_unpacked ?: null,
    ':video_front'=>$video_front ?: null,
    ':video_back'=>$video_back ?: null,
    ':video_box'=>$video_box ?: null,
    ':video_unpacked'=>$video_unpacked ?: null,
    ':akl_reg_no'=>$akl_reg_no ?: null,
':exp_date'=>$exp_date,
':lot_number'=>($lot_number !== '' ? $lot_number : null),
]);
                    $newImportId = (int)$pdo->lastInsertId();
                    sync_product_stock_to_wqs($pdo, $newImportId, (float)$current_stock, $office_code);
                    $countInsert++;
                }
            }
            $pdo->commit();

            // PATCH_3_AUDIT (batch import)
            rmi_audit_safe('POSTING', 'MASTER.PRODUCTS', $cleanName, [
                'event' => 'import_master_products',
                'mode' => $mode,
                'file' => ($file['name'] ?? ''),
                'rows_total' => count($rows),
            ], [
                'insert' => $countInsert,
                'update' => $countUpdate,
                'skip' => $countSkip,
                'error' => $countError,
                'errors_sample' => array_slice($errors, 0, 20),
            ]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_products', 'master_products', 'IMPORT', null, 'import:' . $cleanName, "Import: insert={$countInsert}, update={$countUpdate}, skip={$countSkip}, error={$countError}", ['insert' => $countInsert, 'update' => $countUpdate, 'skip' => $countSkip, 'error' => $countError]);
            }
            $msg = "Import selesai. Insert=$countInsert, Update=$countUpdate, Skip=$countSkip, Error=$countError";
if ($countError > 0) {
    $msg .= " | Error detail: " . implode(" | ", array_slice($errors, 0, 10));
}
flash_set($countError > 0 ? 'warning' : 'success', $msg);
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('danger', 'Import gagal: ' . $e->getMessage());
        }

        rmi_redirect('master_products.php');
    }

    // Save add/edit (tanpa harga)
    if (isset($_POST['save_product'])) {
        $id = (int)($_POST['id'] ?? 0);

        $sku = trim((string)($_POST['sku'] ?? ''));
        $products_name = trim((string)($_POST['products_name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $category = normalize_category($category);
        $business_group = strtoupper(trim((string)($_POST['business_group'] ?? 'BMHP')));
        if (!in_array($business_group, VALID_BUSINESS_GROUPS, true)) $business_group='BMHP';
        $product_group = trim((string)($_POST['product_group'] ?? ''));
        $no_akl = trim((string)($_POST['no_akl'] ?? ''));
        $manufacture_id = (int)($_POST['manufacture_id'] ?? 0);
        $stock_qty = (int)($_POST['stock_qty'] ?? 0);
        $office_code = strtoupper(trim((string)($_POST['office_code'] ?? 'BGR')));
if ($office_code === '') $office_code = 'BGR';
        if (!mp_master_office_exists($pdo, $office_code)) {
            flash_set('danger', 'Office / Cabang tidak valid: ' . $office_code . '. Tambahkan dulu di master_office.');
            rmi_redirect('master_products.php' . ($id ? ('?edit_id='.$id) : ''));
        }
        $unit = trim((string)($_POST['unit'] ?? 'unit'));
        $status = strtolower(trim((string)($_POST['status'] ?? 'inactive')));
        $general_name = trim((string)($_POST['general_name'] ?? ''));
        $licence_number = trim((string)($_POST['licence_number'] ?? ''));
        $listing_level_raw = trim((string)($_POST['listing_level'] ?? ''));
        $listing_level = ($listing_level_raw === '') ? null : (int)$listing_level_raw;
        $barcode = trim((string)($_POST['barcode'] ?? ''));
        $lot_number = trim((string)($_POST['lot_number'] ?? ''));
        $akl_reg_no = trim((string)($_POST['akl_reg_no'] ?? ''));
        $exp_date = trim((string)($_POST['exp_date'] ?? '')); // masa berlaku izin/registrasi (opsional), bukan expiry batch
        $current_stock = (int)($_POST['current_stock'] ?? 0);
        $product_type = strtoupper(trim((string)($_POST['product_type'] ?? 'SINGLE')));
        if (!in_array($product_type, ['SINGLE','PAKET'], true)) $product_type='SINGLE';

        if ($sku === '' || $products_name === '') {
            flash_set('danger', 'SKU dan Nama wajib diisi.');
            rmi_redirect('master_products.php' . ($id ? ('?edit_id='.$id) : ''));
        }

     // cek duplikat Lot Number / Serial Number



        // Validasi wajib (biar master rapi & anti typo) - konsisten UPPER
        $sku = normalize_code($sku);
        $products_name = function_exists('rmi_product_name') ? rmi_product_name($products_name) : strtoupper(trim($products_name));
        $unit = strtoupper(trim($unit));
        $status = ($status === 'active') ? 'active' : 'inactive';
        $product_type = strtoupper(trim($product_type));
        if (!in_array($product_type, ['SINGLE','PAKET'], true)) $product_type = 'SINGLE';

        if ($sku === '' || $products_name === '') {

            flash_set('danger', 'SKU dan Nama Produk wajib diisi.');
            rmi_redirect('master_products.php' . ($id ? ('?edit_id='.$id) : ''));
        }

        if ($category === 'UNIT_ACC' || !in_array($category, VALID_PRODUCT_CATEGORIES, true)) {
            flash_set('danger', 'Category final wajib BMHP / ALKES / AKSESORIS. UNIT ACC dipilih pada Kelompok Bisnis.');
            rmi_redirect('master_products.php' . ($id ? ('?edit_id='.$id) : ''));
        }
        if ($business_group === 'UNIT_ACC' && !in_array($category, ['ALKES','AKSESORIS'], true)) {
            flash_set('danger', 'Kelompok Bisnis UNIT ACC hanya boleh berkategori ALKES atau AKSESORIS.');
            rmi_redirect('master_products.php' . ($id ? ('?edit_id='.$id) : ''));
        }

        // Policy: jika status ACTIVE maka wajib ada nomor AKL/NIE (minimal salah satu)
        if (!require_akl_when_active($status, $no_akl, $akl_reg_no, $licence_number, $category)) {
            flash_set('danger', 'Status ACTIVE wajib memiliki No AKL/NIE sesuai policy produk aktif. Isi salah satu: No AKL, AKL Reg No, atau Licence Number.');
            rmi_redirect('master_products.php' . ($id ? ('?edit_id='.$id) : ''));
        }
        try {
            if ($id) {
                // PATCH_3_AUDIT: before snapshot
                $before = null;
                try {
                    $stb = $pdo->prepare("SELECT * FROM master_products WHERE id=? LIMIT 1");
                    $stb->execute([$id]);
                    $before = $stb->fetch();
                } catch (Throwable $e) {
                    $before = null;
                }

                // jangan update price/min/max
$st = $pdo->prepare("UPDATE master_products SET
    sku=:sku,
    products_name=:products_name,
    category=:category,
    business_group=:business_group,
    product_group=:product_group,
    no_akl=:no_akl,
    manufacture_id=:manufacture_id,
    stock_qty=:stock_qty,
    unit=:unit,
    status=:status,
    general_name=:general_name,
    licence_number=:licence_number,
    listing_level=:listing_level,
    barcode=:barcode,
lot_number=:lot_number,
akl_reg_no=:akl_reg_no,
    exp_date=:exp_date,
    current_stock=:current_stock,
    product_type=:product_type,
    updated_at=NOW()
WHERE id=:id");
                $st->execute([
                    
                    ':sku'=>$sku,
                    ':products_name'=>$products_name,
                    ':category'=>$category,
                    ':business_group'=>$business_group,
                    ':product_group'=>$product_group,
                    ':no_akl'=>$no_akl,
                    ':manufacture_id'=>($manufacture_id ?: null),
                    ':stock_qty'=>$stock_qty,
                    ':unit'=>$unit,
                    ':status'=>$status,
                    ':general_name'=>($general_name!==''?$general_name:null),
                    ':licence_number'=>($licence_number!==''?$licence_number:null),
                    ':listing_level'=>$listing_level,
                    ':barcode'=>($barcode!==''?$barcode:null),
                    ':lot_number'=>($lot_number!==''?$lot_number:null),
                    ':akl_reg_no'=>($akl_reg_no!==''?$akl_reg_no:null),
                    ':exp_date'=>($exp_date!==''?$exp_date:null),
                    ':current_stock'=>$current_stock,
':akl_reg_no'=>$akl_reg_no ?: null,
':exp_date'=>$exp_date,
                    ':product_type'=>$product_type,
                    ':id'=>$id,
                ]);
                sync_product_stock_to_wqs($pdo, $id, $current_stock, $office_code);
                // PATCH_3_AUDIT: after snapshot
                $after = null;
                try {
                    $sta = $pdo->prepare("SELECT * FROM master_products WHERE id=? LIMIT 1");
                    $sta->execute([$id]);
                    $after = $sta->fetch();
                } catch (Throwable $e) {
                    $after = null;
                }
                rmi_audit_safe('UPDATE', 'MASTER.PRODUCTS', $id, $before, $after, [
                    'event' => 'update_product',
                    'sku' => $sku,
                ]);
                flash_set('success', 'Produk berhasil diupdate.');
            } else {
                $st = $pdo->prepare("INSERT INTO master_products
                    (sku, products_name, category, business_group, product_group, no_akl, manufacture_id, vendor_id,
                     stock_qty, price, unit, status, general_name, licence_number,
                     min_price, max_price, listing_level, barcode, lot_number, akl_reg_no, exp_date, current_stock, product_type)
                    VALUES
                    (:sku, :products_name, :category, :business_group, :product_group, :no_akl, :manufacture_id, NULL,
                     :stock_qty, 0, :unit, :status, :general_name, :licence_number,
                     0, 0, :listing_level, :barcode, :lot_number, :akl_reg_no, :exp_date, :current_stock, :product_type)");
               $st->execute([
    ':sku'=>$sku,
    ':products_name'=>$products_name,
    ':category'=>$category,
    ':business_group'=>$business_group,
    ':product_group'=>$product_group,
    ':no_akl'=>$no_akl,
    ':manufacture_id'=>($manufacture_id ?: null),
    ':stock_qty'=>$stock_qty,
    ':unit'=>$unit,
    ':status'=>$status,
    ':general_name'=>($general_name!==''?$general_name:null),
    ':licence_number'=>($licence_number!==''?$licence_number:null),
    ':listing_level'=>$listing_level,
    ':barcode'=>($barcode!==''?$barcode:null),
    ':lot_number'=>($lot_number!==''?$lot_number:null),
   ':current_stock'=>$current_stock,
':akl_reg_no'=>$akl_reg_no ?: null,
':exp_date'=>$exp_date,    ':product_type'=>$product_type,
    
]);
                $newId = (int)$pdo->lastInsertId();
                sync_product_stock_to_wqs($pdo, $newId, $current_stock, $office_code);
                // PATCH_3_AUDIT: after snapshot
                $after = null;
                try {
                    $sta = $pdo->prepare("SELECT * FROM master_products WHERE id=? LIMIT 1");
                    $sta->execute([$newId]);
                    $after = $sta->fetch();
                } catch (Throwable $e) {
                    $after = null;
                }
                rmi_audit_safe('CREATE', 'MASTER.PRODUCTS', $newId, null, $after, [
                    'event' => 'create_product',
                    'sku' => $sku,
                ]);
                flash_set('success', 'Produk berhasil ditambahkan.');
            }
        } catch (Throwable $e) {
            flash_set('danger', 'Gagal simpan: '.$e->getMessage());
        }

        rmi_redirect('master_products.php');
    }
}

// Nonaktifkan/aktifkan quick action
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $mode = (string)$_GET['toggle']; // active|inactive
    $mode = strtolower($mode) === 'active' ? 'active' : 'inactive';
    try {
        // PATCH_3_AUDIT: before snapshot
        $before = null;
        try {
            $stb = $pdo->prepare("SELECT * FROM master_products WHERE id=? LIMIT 1");
            $stb->execute([$id]);
            $before = $stb->fetch();
        } catch (Throwable $e) {
            $before = null;
        }

        $st = $pdo->prepare("UPDATE master_products SET status=?, updated_at=NOW() WHERE id=?");
        $st->execute([$mode,$id]);

        // PATCH_3_AUDIT: after snapshot
        $after = null;
        try {
            $sta = $pdo->prepare("SELECT * FROM master_products WHERE id=? LIMIT 1");
            $sta->execute([$id]);
            $after = $sta->fetch();
        } catch (Throwable $e) {
            $after = null;
        }
        rmi_audit_safe('UPDATE', 'MASTER.PRODUCTS', $id, $before, $after, [
            'event' => 'toggle_status',
            'status' => $mode,
        ]);
        if ($before && function_exists('master_audit')) {
            $sku = $before['sku'] ?? '';
            $name = $before['products_name'] ?? '';
            master_audit($pdo, 'master_products', 'master_products', 'TOGGLE_STATUS', $id, $sku, "Status changed to {$mode}: {$sku} - {$name}", ['status' => $mode]);
        }
        flash_set('success', "Status produk diubah: $mode");
    } catch (Throwable $e) {
        flash_set('danger', 'Gagal ubah status: '.$e->getMessage());
    }
    rmi_redirect('master_products.php');
}
// Hapus produk dari office/cabang saja, bukan hapus master product
if (isset($_GET['delete_office_stock']) && isset($_GET['id']) && isset($_GET['office_code'])) {
    $id = (int)$_GET['id'];
    $office_code = strtoupper(trim((string)$_GET['office_code']));

    try {
        $st = $pdo->prepare("
            DELETE FROM wqs_stock_by_office
            WHERE product_id = ?
              AND office_code = ?
        ");
        $st->execute([$id, $office_code]);

        flash_set('success', 'Produk berhasil dihapus dari office ' . $office_code . '. Master produk tetap aman.');
    } catch (Throwable $e) {
        flash_set('danger', 'Gagal hapus office stock: ' . $e->getMessage());
    }

    rmi_redirect('master_products.php');
}
// Hapus MASTER produk + semua relasi
if (isset($_GET['delete_master']) && isset($_GET['id'])) {

    $id = (int)$_GET['id'];

    try {

        $pdo->beginTransaction();

        // Ambil SKU dulu
        $q = $pdo->prepare("
            SELECT sku
            FROM master_products
            WHERE id=?
        ");

        $q->execute([$id]);

        $prod = $q->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            throw new Exception('Produk tidak ditemukan');
        }

        $sku = trim((string)$prod['sku']);

        // =========================
        // Hapus stock semua office
        // =========================

        $st = $pdo->prepare("
            DELETE FROM wqs_stock_by_office
            WHERE product_id=?
        ");

        $st->execute([$id]);

        // =========================
        // Hapus media database
        // =========================

        $st = $pdo->prepare("
            DELETE FROM master_product_media
            WHERE sku=?
        ");

        $st->execute([$sku]);

        // =========================
        // Hapus master produk
        // =========================

        $st = $pdo->prepare("
            DELETE FROM master_products
            WHERE id=?
        ");

        $st->execute([$id]);

        $pdo->commit();

        flash_set('success', 'MASTER produk berhasil dihapus total.');

    } catch(Throwable $e) {

        $pdo->rollBack();

        flash_set('danger', $e->getMessage());
    }

    rmi_redirect('master_products.php');
}
// --------------------------------------------------------
// LIST DATA (FASE 2: filter)
// --------------------------------------------------------
$filterType = strtoupper(trim((string)($_GET['type'] ?? 'ALL')));
if (!in_array($filterType, ['ALL', 'SINGLE', 'PAKET'], true)) $filterType = 'ALL';

$filterStatus = strtolower(trim((string)($_GET['status'] ?? 'ALL')));
$allowedStatus = ['all','active','inactive','draft','pending_reg'];
if (!in_array($filterStatus, $allowedStatus, true)) $filterStatus = 'all';

$filterMid = (int)($_GET['mid'] ?? 0);
$filterCat = strtoupper(trim((string)($_GET['cat'] ?? '')));
if (!in_array($filterCat, ['BMHP','ALKES','AKSESORIS'], true)) $filterCat = '';
$filterBg = strtoupper(trim((string)($_GET['business_group'] ?? '')));
if (!in_array($filterBg, ['BMHP','UNIT_ACC'], true)) $filterBg = '';

$q = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($filterType !== 'ALL') {
    $where[] = 'p.product_type = :ptype';
    $params[':ptype'] = $filterType;
}
if ($filterStatus !== 'all') {
    $where[] = 'LOWER(p.status) = :status';
    $params[':status'] = $filterStatus;
}
if ($filterMid > 0) {
    $where[] = 'p.manufacture_id = :mid';
    $params[':mid'] = $filterMid;
}
if ($filterCat !== '') {
    $where[] = 'p.category = :cat';
    $params[':cat'] = $filterCat;
}
if ($filterBg !== '') {
    $where[] = "UPPER(TRIM(COALESCE(p.business_group,'BMHP'))) = :bg";
    $params[':bg'] = $filterBg;
}
if ($q !== '') {
    $where[] = '(p.sku LIKE :q OR p.products_name LIKE :q OR p.barcode LIKE :q OR p.no_akl LIKE :q OR p.akl_reg_no LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT
        p.*,
        COALESCE(wso.office_code, 'BGR') AS office_code,
COALESCE(wso.stock_qty, p.current_stock, 0) AS office_stock_qty,
        COALESCE(NULLIF(m.manufacture_name,''), m.manufactures_name) AS manufacture_name,
        COALESCE(NULLIF(m.manufacture_code,''), m.manufactures_code) AS manufacture_code
    FROM master_products p
    LEFT JOIN master_manufactures m ON m.id = p.manufacture_id
    LEFT JOIN wqs_stock_by_office wso ON wso.product_id = p.id
    $whereSql
    ORDER BY p.updated_at DESC, p.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$flash = flash_get();

// load edit row
$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit = null;
if ($edit_id > 0) {
    $st = $pdo->prepare("SELECT * FROM master_products WHERE id=? LIMIT 1");
    $st->execute([$edit_id]);
    $edit = $st->fetch() ?: null;
}
$offices = mp_load_offices($pdo);
if (!$offices) {
    $offices = [['office_code' => 'BGR', 'office_name' => 'Bogor']];
}
$form = [
    'lot_number' => $edit['lot_number'] ?? '',
    'id' => $edit['id'] ?? 0,
    'sku' => $edit['sku'] ?? '',
    'products_name' => $edit['products_name'] ?? '',
    'category' => $edit['category'] ?? '',
    'business_group' => $edit['business_group'] ?? 'BMHP',
    'product_group' => $edit['product_group'] ?? '',
    'no_akl' => $edit['no_akl'] ?? '',
    'manufacture_id' => $edit['manufacture_id'] ?? '',
    'stock_qty' => $edit['stock_qty'] ?? 0,
    'unit' => $edit['unit'] ?? '',
    'status' => $edit['status'] ?? 'inactive',
    'general_name' => $edit['general_name'] ?? '',
    'licence_number' => $edit['licence_number'] ?? '',
    'listing_level' => $edit['listing_level'] ?? '',
    'barcode' => $edit['barcode'] ?? '',
    'akl_reg_no' => $edit['akl_reg_no'] ?? '',
    'exp_date' => $edit['exp_date'] ?? '',
    'current_stock' => $edit['current_stock'] ?? 0,
    'product_type' => $edit['product_type'] ?? 'SINGLE',
    'office_code' => strtoupper(trim((string)($_GET['office_code'] ?? $_POST['office_code'] ?? 'BGR'))),
];

// Audit log (last 50)
$audit_rows = [];
try {
    if (function_exists('master_audit_ensure_table')) {
        master_audit_ensure_table($pdo);
    }
    $st = $pdo->prepare("
        SELECT action, record_code, username, description, created_at
        FROM system_audit_logs
        WHERE module = 'master_products'
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $audit_rows = [];
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$print_qs = http_build_query([
    'manufacture_id' => (int)($_GET['mid'] ?? 0),
    'status' => (string)($_GET['status'] ?? ''),
    'q' => (string)($_GET['q'] ?? ''),
    'product_type' => (string)($_GET['type'] ?? ''),
]);

$extraHead = '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
  . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
  . '<style>'
  . '#tbl.dataTable thead th{color:#d6e2f5 !important; font-weight:600;}'
  . '#tbl.dataTable tbody td{color:#e6edf7 !important;}'
  . '#tbl.dataTable tbody tr.odd td{background:rgba(16,24,39,.72) !important;}'
  . '#tbl.dataTable tbody tr.even td{background:rgba(24,34,52,.72) !important;}'
  . '#tbl.dataTable tbody tr:hover td{background:rgba(37,99,235,.18) !important;}'
  . '#tbl .text-muted{color:#b7c7e2 !important;}'
  . '#tbl code{color:#dbe7fb; background:rgba(148,163,184,.18); border:1px solid rgba(148,163,184,.28);}'
  . 'div.dataTables_wrapper div.dataTables_filter label,'
  . 'div.dataTables_wrapper div.dataTables_info,'
  . 'div.dataTables_wrapper div.dataTables_length label{color:#c6d5ee !important;}'
  . '</style>';

rmi_header('Master Products', [
  'active' => 'master',
  'subtitle' => 'Harga dikelola di master_pricelist. Dokumen registrasi dari manufactures_docs.',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Products',
  ],
  'actions' => [
    ['label' => 'Refresh', 'url' => 'master_products.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Print Tanpa Harga', 'url' => 'master_products_print.php?' . h($print_qs), 'class' => 'btn btn-sm btn-outline-light', 'attrs' => 'target="_blank"'],
    ['label' => 'Kelola Paket', 'url' => 'master_products_package.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Template CSV', 'url' => '?download_template=1', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Template Unit ACC', 'url' => '?download_template=unit_acc', 'class' => 'btn btn-sm btn-warning'],
    ['label' => 'Template Media (XLSX/CSV)', 'url' => '?download_template=media', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Scan Media Folder', 'url' => 'scan_product_media_folder.php', 'class' => 'btn btn-sm btn-warning'],
  ],
  'extra_head' => $extraHead,
]);
?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- FASE 3: Import -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Import (CSV/XLSX)</h5>
                <div class="muted">Mode: SKIP/UPSERT • Unit ACC = business_group • category = BMHP / ALKES / AKSESORIS • office mengikuti kantor stok • CSV/XLSX</div>
            </div>
            <form class="row g-2 mt-2" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="col-md-5">
                    <input class="form-control" type="file" name="import_file" accept=".csv,.xlsx" required>
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="import_mode">
                    <option value="skip">Skip jika SKU + Nama + Lot sudah ada</option>
<option value="upsert">Upsert jika SKU + Nama + Lot sudah ada</option>
                    </select>
                </div>
                <div class="col-md-4 d-grid">
                    <button class="btn btn-primary" name="do_import" value="1">Import</button>
                </div>
            </form>
            <div class="muted mt-2">
                Tips: Template Unit ACC otomatis memakai <b>business_group=UNIT_ACC</b>. Pilih category <b>ALKES</b> atau <b>AKSESORIS</b> dan isi office_code sesuai kantor stok (BGR/BDG/BKS/dll).
            </div>
        </div>
    </div>

    <!-- Filter (FASE 2) -->
    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get">
                <div class="col-md-3">
                    <label class="form-label">Manufacture</label>
                    <select name="mid" class="form-select">
                        <option value="0">All</option>
                        <?php foreach ($manufactures as $m): ?>
                            <option value="<?= (int)$m['id'] ?>" <?= ($filterMid==(int)$m['id']?'selected':'') ?>>
                                <?= h($m['code']) ?> — <?= h($m['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['ALL','active','inactive','draft','pending_reg'] as $s): ?>
                            <option value="<?= strtolower($s) ?>" <?= (strtolower($s)===$filterStatus?'selected':'') ?>><?= h(strtoupper($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select">
                        <?php foreach (['ALL','SINGLE','PAKET'] as $t): ?>
                            <option value="<?= h($t) ?>" <?= ($t===$filterType?'selected':'') ?>><?= h($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Kelompok Bisnis</label>
                    <select name="business_group" class="form-select">
                        <option value="">All</option>
                        <option value="BMHP" <?= $filterBg==='BMHP'?'selected':'' ?>>BMHP</option>
                        <option value="UNIT_ACC" <?= $filterBg==='UNIT_ACC'?'selected':'' ?>>UNIT ACC</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Category</label>
                    <select name="cat" class="form-select">
                        <option value="">All</option>
                        <?php foreach ([
                            'BMHP'      => rmi_icon('box') . ' BMHP',
                            'ALKES'     => rmi_icon('cross') . ' ALKES',
                            'AKSESORIS' => rmi_icon('zap') . ' AKSESORIS',
                        ] as $cv => $cl): ?>
                            <option value="<?= h($cv) ?>" <?= ($filterCat===$cv?'selected':'') ?>><?= h($cl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input name="q" class="form-control" value="<?= h($q) ?>" placeholder="SKU / nama / barcode / AKL">
                </div>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-success">Apply</button>
                    <a class="btn btn-outline-secondary" href="master_products.php">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Form add/edit (tanpa harga) -->
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-3"><?= $form['id'] ? 'Edit Product' : 'Tambah Product' ?></h5>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

                <div class="col-md-3">
                    <label class="form-label">SKU</label>
                    <input name="sku" class="form-control" value="<?= h($form['sku']) ?>" required>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Nama Produk</label>
                    <input name="products_name" class="form-control" value="<?= h($form['products_name']) ?>" required>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select name="product_type" class="form-select">
                        <option value="SINGLE" <?= (strtoupper($form['product_type'])==='SINGLE'?'selected':'') ?>>SINGLE</option>
                        <option value="PAKET" <?= (strtoupper($form['product_type'])==='PAKET'?'selected':'') ?>>PAKET</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['active','inactive','draft','pending_reg'] as $s): ?>
                            <option value="<?= h($s) ?>" <?= (strtolower((string)$form['status'])===$s?'selected':'') ?>><?= h(strtoupper($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <?php $catVal = normalize_category((string)$form['category']); ?>
                    <select name="category" class="form-select" required>
                        <option value="BMHP"      <?= $catVal==='BMHP'?'selected':''      ?>><?= rmi_icon('box') ?> BMHP — Bahan Medis Habis Pakai</option>
                        <option value="ALKES"     <?= $catVal==='ALKES'?'selected':''     ?>><?= rmi_icon('cross') ?> ALKES — Alat Kesehatan Durable</option>
                        <option value="AKSESORIS" <?= $catVal==='AKSESORIS'?'selected':'' ?>><?= rmi_icon('zap') ?> AKSESORIS — Aksesori Alkes</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Kelompok Bisnis</label>
                    <?php $bgVal = strtoupper(trim((string)($form['business_group'] ?? 'BMHP'))); ?>
                    <select name="business_group" class="form-select" required>
                        <option value="BMHP" <?= $bgVal==='BMHP'?'selected':'' ?>>BMHP</option>
                        <option value="UNIT_ACC" <?= $bgVal==='UNIT_ACC'?'selected':'' ?>>UNIT ACC</option>
                    </select>
                    <div class="muted mt-1">UNIT ACC wajib Category ALKES atau AKSESORIS.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Product Group</label>
                    <input name="product_group" class="form-control" value="<?= h($form['product_group']) ?>" placeholder="SUCTION_CATHETER">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Manufacture</label>
                    <select name="manufacture_id" class="form-select">

                        <option value="0">— pilih —</option>
                        <?php foreach ($manufactures as $m): ?>
                            <option value="<?= (int)$m['id'] ?>" <?= ((int)$form['manufacture_id']===(int)$m['id']?'selected':'') ?>>
                                <?= h(strtoupper($m['code'] ?? '')) ?> — <?= h(strtoupper($m['name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
<div class="col-md-3">
    <label class="form-label">Office / Cabang</label>
    <select name="office_code" class="form-select">
        <?php foreach ($offices as $o): ?>
            <?php $oc = strtoupper(trim((string)($o['office_code'] ?? ''))); ?>
            <option value="<?= h($oc) ?>" <?= ($oc === strtoupper((string)$form['office_code']) ? 'selected' : '') ?>>
                <?= h($oc) ?> - <?= h($o['office_name'] ?? $oc) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div><div class="col-md-3">
                    <label class="form-label">Unit</label>
                    <input name="unit" class="form-control" value="<?= h($form['unit']) ?>" placeholder="pcs / box">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Stock Qty</label>
                    <input name="stock_qty" type="number" class="form-control" value="<?= h($form['stock_qty']) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Current Stock</label>
                    <input name="current_stock" type="number" class="form-control" value="<?= h($form['current_stock']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Barcode</label>
                    <input name="barcode" class="form-control" value="<?= h($form['barcode']) ?>">
                </div>
<div class="col-md-4">
    <label class="form-label">Lot Number</label>
    <input name="lot_number" class="form-control" value="<?= h($form['lot_number'] ?? '') ?>" placeholder="LOT-XXXX">
</div>
                <div class="col-md-4">
                    <label class="form-label">No AKD/AKL (opsional)</label>
                    <input name="no_akl" class="form-control" value="<?= h($form['no_akl']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">AKL Reg No (mirror, opsional)</label>
                    <input name="akl_reg_no" class="form-control" value="<?= h($form['akl_reg_no']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Expired Products (opsional)</label>
<input name="exp_date" type="date" class="form-control" value="<?= h($form['exp_date']) ?>"><div class="form-text">Masa berlaku izin/registrasi. Expiry batch/lot diinput melalui WQS Incoming.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">General Name</label>
                    <input name="general_name" class="form-control" value="<?= h($form['general_name']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Licence Number</label>
                    <input name="licence_number" class="form-control" value="<?= h($form['licence_number']) ?>">
                </div>

                <div class="col-md-12">
                    <label class="form-label">Listing Level</label>
                    <input name="listing_level" class="form-control" value="<?= h($form['listing_level']) ?>" placeholder="opsional">
                </div>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" name="save_product" value="1">Simpan</button>
                    <?php if ($form['id']): ?>
                        <a class="btn btn-outline-secondary" href="master_products.php">Batal</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="muted mt-2">
                Catatan: Harga tidak di-edit di sini. Gunakan <b>master_pricelist</b> untuk harga jual/beli sesuai kebijakan.
            </div>
        </div>
    </div>
<?php if (isset($_GET['upload_media'])): ?>
<?php
$uploadId = (int)$_GET['upload_media'];
$st = $pdo->prepare("SELECT id, sku, products_name, business_group, category FROM master_products WHERE id=? LIMIT 1");
$st->execute([$uploadId]);
$uploadProduct = $st->fetch(PDO::FETCH_ASSOC);
?>
<?php if ($uploadProduct): ?>
<div class="card mb-3">
    <div class="card-body">
        <h5>Upload Media Produk</h5>
        <div class="muted mb-2">
            SKU: <b><?= h($uploadProduct['sku']) ?></b><br>
            Produk: <?= h($uploadProduct['products_name']) ?><br>
            <?php if (strtoupper(trim((string)($uploadProduct['business_group'] ?? ''))) === 'UNIT_ACC'): ?>
                <span class="text-success">Folder: uploads/products/UNIT ACC/<?= h(product_media_folder_name((string)$uploadProduct['sku'])) ?>/</span><br>
                <span class="text-muted">Format wajib: front.jpg, back.jpg, box.jpg, unpacked.jpg, front.mp4, unpacked.mp4.</span>
            <?php else: ?>
                <span class="text-muted">Folder: uploads/products/<?= h(product_media_folder_name((string)$uploadProduct['sku'])) ?>/</span>
            <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="product_id" value="<?= (int)$uploadProduct['id'] ?>">

            <div class="col-md-4">
                <label class="form-label">Jenis Media</label>
                <select name="slot" class="form-select" required>
                    <option value="front">Foto / Video Front</option>
                    <option value="back">Foto / Video Back</option>
                    <option value="box">Foto / Video Box</option>
                    <option value="unpacked">Foto / Video Unpacked</option>
                </select>
            </div>

            <div class="col-md-5">
                <label class="form-label">File</label>
                <input type="file" name="media_file" class="form-control"
                       accept=".jpg,.jpeg,.png,.webp,.mp4,.mov" required>
            </div>

            <div class="col-md-3 d-grid align-items-end">
                <button class="btn btn-primary" name="upload_product_media" value="1">
                    Upload Media
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
    <!-- List -->
    <div class="card">
        <div class="card-body">
            <form method="post" id="bulkForm">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">List Products (<?= count($rows) ?>)</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <select name="bulk_action" class="form-select form-select-sm" style="width: 220px;">
                            <option value="">Bulk Action...</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="delete">Delete (HARD)</option>
                        </select>
                        <button class="btn btn-sm btn-outline-primary" onclick="return bulkConfirm()">Apply</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="tbl" class="table table-striped table-hover align-middle rmi-table" style="width:100%">
        <thead>
<tr>
    <th style="width:34px;"><input type="checkbox" id="chkAll"></th>
    <th>sku</th>
    <th>products_name</th>
    <th>business_group</th>
    <th>category</th>
    <th>product_group</th>
    <th>unit</th>
    <th>product_type</th>
    <th>manufacture</th>
    <th>status</th>
    <th>stock_qty</th>
    <th>current_stock</th>
    <th>lot_number</th>
    <th>akl_reg_no</th>
    <th>expired_products</th>
    <th>general_name</th>
    <th>licence_number</th>
    <th>listing_level</th>
    <th>office_code</th>
    <th>Aksi</th>
</tr>
</thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $mcode = trim((string)($r['manufacture_code'] ?? ''));
                                $docsUrl = $mcode !== '' ? ('manufactures_docs.php?code=' . urlencode($mcode)) : null;
                                $mediaUrl = 'products_media_view.php?products_code=' . urlencode((string)$r['sku']);
                                $editUrl = 'master_products.php?edit_id=' . (int)$r['id'];
                            ?>
                            <tr>
    <td><input type="checkbox" class="chkRow" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
    <td><?= h($r['sku'] ?? '') ?></td>
    <td><?= h($r['products_name'] ?? '') ?></td>
    <td><?= h(strtoupper($r['business_group'] ?? 'BMHP')) ?></td>
    <td><?= h($r['category'] ?? '') ?></td>
    <td><?= h($r['product_group'] ?? '') ?></td>
    <td><?= h($r['unit'] ?? '') ?></td>
    <td><?= h($r['product_type'] ?? '') ?></td>
    <td><?= h($r['manufacture_name'] ?? '') ?></td>
    <td><?= h($r['status'] ?? '') ?></td>
    <td><?= h($r['stock_qty'] ?? 0) ?></td>
    <td><?= h($r['office_stock_qty'] ?? $r['current_stock'] ?? 0) ?></td>
   <td><?= h($r['lot_number'] ?? '') ?></td>
<td><?= h($r['akl_reg_no'] ?: ($r['no_akl'] ?? '')) ?></td>
        <td><?= h($r['exp_date'] ?? '') ?></td>
    <td><?= h($r['general_name'] ?? '') ?></td>
    <td><?= h($r['licence_number'] ?? '') ?></td>
    <td><?= h($r['listing_level'] ?? '') ?></td>
    <td><?= h($r['office_code'] ?? 'BGR') ?></td>

    <td>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($docsUrl): ?>
                <a class="btn btn-sm btn-outline-dark" href="<?= h($docsUrl) ?>" target="_blank">Docs ↗</a>
            <?php endif; ?>

            <a class="btn btn-sm btn-outline-success" href="<?= h($mediaUrl) ?>" target="_blank">Media ↗</a>

            <a class="btn btn-sm btn-outline-info" href="master_products.php?upload_media=<?= (int)$r['id'] ?>">
                Upload
            </a>

            <a class="btn btn-sm btn-outline-primary" href="<?= h($editUrl) ?>">Edit</a>

            <?php if (strtoupper((string)$r['product_type']) === 'PAKET'): ?>
                <a class="btn btn-sm btn-outline-warning" href="master_products_package.php?edit_id=<?= (int)$r['id'] ?>">
                    Paket Items
                </a>
            <?php endif; ?>

            <?php if (strtolower((string)$r['status']) !== 'active'): ?>
                <a class="btn btn-sm btn-outline-success"
                   href="master_products.php?toggle=active&id=<?= (int)$r['id'] ?>"
                   onclick="return confirm('Aktifkan produk ini?')">Aktifkan</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-secondary"
                   href="master_products.php?toggle=inactive&id=<?= (int)$r['id'] ?>"
                   onclick="return confirm('Nonaktifkan produk ini?')">Nonaktifkan</a>
            <?php endif; ?>

          <a class="btn btn-sm btn-outline-danger"
   href="master_products.php?delete_office_stock=1&id=<?= (int)$r['id'] ?>&office_code=<?= urlencode((string)($r['office_code'] ?? 'BGR')) ?>"
   onclick="return confirm('Hapus produk ini hanya dari office <?= h($r['office_code'] ?? 'BGR') ?>? Master produk tidak akan dihapus.')">
   Hapus Office
</a>
<a class="btn btn-sm btn-danger"
   href="master_products.php?delete_master=1&id=<?= (int)$r['id'] ?>"
   onclick="return confirm('HAPUS MASTER PRODUK ini? Semua office dan media ikut terhapus!')">
   Hapus Master
</a>
        </div>
    </td>
</tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="muted mt-2">
                    FASE 2: filter + export • FASE 3: import + bulk action + audit log.
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
                                <td><?= h($a['created_at'] ?? '') ?></td>
                                <td><?= h($a['action'] ?? '') ?></td>
                                <td><?= h($a['record_code'] ?? '') ?></td>
                                <td><?= h($a['username'] ?? '') ?></td>
                                <td><?= h($a['description'] ?? '') ?></td>
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

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209"></script>

<!-- Buttons -->
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>

<!-- deps -->
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>

<script>
function bulkConfirm(){
    const act = document.querySelector('select[name="bulk_action"]').value;
    if(!act){ alert('Pilih bulk action.'); return false; }
    const checked = document.querySelectorAll('.chkRow:checked').length;
    if(!checked){ alert('Pilih minimal 1 data.'); return false; }
    if(act==='delete') return confirm('HARD DELETE ' + checked + ' produk?');
    return confirm('Jalankan "' + act + '" untuk ' + checked + ' produk?');
}

$(function(){
    const dt = $('#tbl').DataTable({
        pageLength: 25,
        order: [[1,'desc']],
        dom: 'Bfrtip',
        buttons: [
    {
        extend: 'copy',
        className: 'btn btn-sm btn-outline-secondary',
        exportOptions: { columns: ':not(:first-child):not(:last-child)' }
    },
    {
        extend: 'csv',
        className: 'btn btn-sm btn-outline-secondary',
        exportOptions: { columns: ':not(:first-child):not(:last-child)' }
    },
    {
        extend: 'excel',
        className: 'btn btn-sm btn-outline-secondary',
        exportOptions: { columns: ':not(:first-child):not(:last-child)' }
    },
    {
        extend: 'pdf',
        className: 'btn btn-sm btn-outline-secondary',
        exportOptions: { columns: ':not(:first-child):not(:last-child)' }
    },
    {
        extend: 'print',
        className: 'btn btn-sm btn-outline-secondary',
        exportOptions: { columns: ':not(:first-child):not(:last-child)' }
    }
]
    });

    $('#chkAll').on('change', function(){
        const checked = this.checked;
        document.querySelectorAll('.chkRow').forEach(ch => ch.checked = checked);
    });
});
</script>

<?php rmi_footer(); ?>
