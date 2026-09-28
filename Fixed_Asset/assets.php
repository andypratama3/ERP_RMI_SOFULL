<?php
require_once __DIR__ . '/_inc/layout.php';
require_once __DIR__ . '/_inc/fa_helpers.php';

// Explicit auth guard (for static scan + safety)
if (function_exists('require_login')) { require_login(); }

fa_preflight_or_die($pdo);
rbac_require(['FIXED_ASSET.ASSET_CRUD', 'FIXED_ASSET.ASSET_VIEW']);

$groups = fa_group_map();

// Kode kategori aset perusahaan. asset_code = nomor register unik per office,
// sedangkan asset_category_code = klasifikasi aset.
$asset_categories = [
  '0403' => 'Furniture / Kursi',
  '0405' => 'Kendaraan',
  '0413' => 'Peralatan',
  '0708' => 'Peralatan Elektronik',
  '0710' => 'Peralatan Lainnya',
  '0709' => 'Furniture',
  '0503' => 'Bangunan',
];

/** Generate nomor register aset unik per office + kategori, contoh BGR-0708-0001. */
function fa_generate_asset_register_code(PDO $pdo, string $officeCode, string $categoryCode): string {
  $office = strtoupper(preg_replace('/[^A-Z0-9]/', '', $officeCode));
  $cat = preg_replace('/[^0-9A-Z]/i', '', strtoupper($categoryCode));
  if ($office === '' || $cat === '') throw new RuntimeException('Office dan kategori aset wajib diisi.');
  $prefix = $office . '-' . $cat . '-';
  $st = $pdo->prepare("SELECT asset_code FROM fa_assets WHERE asset_code LIKE ? ORDER BY id DESC");
  $st->execute([$prefix . '%']);
  $max = 0;
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
    if (preg_match('/^(?:.*-)(\\d+)$/', (string)$code, $m)) $max = max($max, (int)$m[1]);
  }
  return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}


/** Pastikan kode register sudah mengikuti OFFICE-KATEGORI-NNNN. */
function fa_is_standard_asset_register_code(string $assetCode, string $officeCode, string $categoryCode): bool {
  $office = strtoupper(preg_replace('/[^A-Z0-9]/', '', $officeCode));
  $cat = strtoupper(preg_replace('/[^0-9A-Z]/i', '', $categoryCode));
  if ($office === '' || $cat === '') return false;
  return (bool)preg_match('/^' . preg_quote($office, '/') . '-' . preg_quote($cat, '/') . '-\d{4,}$/', strtoupper(trim($assetCode)));
}

/** Simpan kode lama agar histori register tetap dapat dilacak. */
function fa_ensure_legacy_asset_code_column(PDO $pdo): void {
  $st = $pdo->query("SHOW COLUMNS FROM fa_assets LIKE 'legacy_asset_code'");
  if (!$st || !$st->fetch(PDO::FETCH_ASSOC)) {
    $pdo->exec("ALTER TABLE fa_assets ADD COLUMN legacy_asset_code VARCHAR(100) NULL AFTER asset_code");
  }
}


/** Pastikan kolom foto aset tersedia tanpa merusak database existing. */
function fa_ensure_asset_photo_columns(PDO $pdo): void {
  $cols = [
    'asset_photo_1' => "ALTER TABLE fa_assets ADD COLUMN asset_photo_1 VARCHAR(255) NULL AFTER notes",
    'asset_photo_2' => "ALTER TABLE fa_assets ADD COLUMN asset_photo_2 VARCHAR(255) NULL AFTER asset_photo_1",
  ];
  foreach ($cols as $col => $sql) {
    try {
      $st = $pdo->query("SHOW COLUMNS FROM fa_assets LIKE " . $pdo->quote($col));
      if (!$st || !$st->fetch(PDO::FETCH_ASSOC)) $pdo->exec($sql);
    } catch (Throwable $e) {
      if (function_exists('fa_log')) fa_log($pdo,'ASSET_PHOTO_PREFLIGHT_ERROR','ASSET',0,['column'=>$col,'error'=>$e->getMessage()]);
    }
  }
}

/** Nama file media aset canonical berdasarkan Register Asset. */
function fa_asset_media_folder_name(string $assetCode): string {
  $raw = strtoupper(trim($assetCode));

  // Fixed Asset canonical harus berupa REGISTER aset, bukan SKU produk.
  // Contoh valid: SMG-0710-0010, BGR-0709-0007.
  if (!preg_match('/^[A-Z0-9]+-[A-Z0-9]+-\d{4,}$/', $raw)) {
    return '';
  }

  $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $raw);
  $safe = preg_replace('/_{2,}/', '_', (string)$safe);
  return trim((string)$safe, '_');
}

/**
 * Lokasi canonical FIXED ASSET = flat folder /uploads/assets/.
 * Tidak membuat subfolder berdasarkan register dan tidak pernah membaca uploads/products/.
 */
function fa_asset_media_location(string $assetCode): array {
  $register = fa_asset_media_folder_name($assetCode);
  return [
    'folder'   => '',
    'register' => $register,
    'dir'      => dirname(__DIR__) . '/uploads/assets',
    'url'      => '../uploads/assets/',
  ];
}

/** Ambil ekstensi gambar yang diizinkan dari nama file. */
function fa_asset_allowed_photo_ext(string $fileName): ?string {
  $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
  return in_array($ext, ['jpg','jpeg','png','webp'], true) ? $ext : null;
}

/**
 * Cari/migrasikan foto aset ke format flat berdasarkan Register Asset.
 * Canonical:
 *   Foto 1 = /uploads/assets/BGR-0709-0007.jpg
 *   Foto 2 = /uploads/assets/BGR-0709-0007_2.jpg (opsional, agar fitur 2 foto lama tetap aman)
 *
 * Kompatibilitas lama yang tetap dibaca dan, bila aman, dipindahkan otomatis:
 *   /uploads/assets/{REGISTER}/photo1.jpg
 *   /uploads/assets/{REGISTER}/photo2.jpg
 *   /uploads/assets/{REGISTER}_1.jpg
 *   /uploads/assets/{REGISTER}_2.jpg
 */
function fa_asset_manual_photo(string $assetCode, int $slot): ?string {
  $slot = $slot === 2 ? 2 : 1;
  $loc = fa_asset_media_location($assetCode);
  $register = (string)($loc['register'] ?? '');
  if ($register === '') return null;

  $rootDir = $loc['dir'];
  $urlBase = $loc['url'];
  if (!is_dir($rootDir)) return null;

  $exts = ['jpg','jpeg','png','webp','JPG','JPEG','PNG','WEBP'];
  $baseCanonical = $slot === 1 ? $register : $register . '_2';

  // 1) Canonical flat file.
  foreach ($exts as $ext) {
    $name = $baseCanonical . '.' . $ext;
    if (is_file($rootDir . '/' . $name)) return $urlBase . rawurlencode($name);
  }

  // 2) Legacy flat _1/_2.
  $legacyBase = $register . '_' . $slot;
  foreach ($exts as $ext) {
    $legacyName = $legacyBase . '.' . $ext;
    $legacyPath = $rootDir . '/' . $legacyName;
    if (!is_file($legacyPath)) continue;

    $canonicalName = $baseCanonical . '.' . strtolower($ext === 'JPEG' ? 'jpeg' : $ext);
    $canonicalPath = $rootDir . '/' . $canonicalName;
    if (!file_exists($canonicalPath) && is_writable($rootDir) && @rename($legacyPath, $canonicalPath)) {
      return $urlBase . rawurlencode($canonicalName);
    }
    return $urlBase . rawurlencode($legacyName);
  }

  // 3) Legacy subfolder per register: photo1/photo2.*
  $legacyDir = $rootDir . '/' . $register;
  if (is_dir($legacyDir)) {
    foreach ($exts as $ext) {
      $legacyName = 'photo' . $slot . '.' . $ext;
      $legacyPath = $legacyDir . '/' . $legacyName;
      if (!is_file($legacyPath)) continue;

      $canonicalName = $baseCanonical . '.' . strtolower($ext === 'JPEG' ? 'jpeg' : $ext);
      $canonicalPath = $rootDir . '/' . $canonicalName;
      if (!file_exists($canonicalPath) && is_writable($rootDir) && @rename($legacyPath, $canonicalPath)) {
        $remain = @scandir($legacyDir);
        if (is_array($remain) && count(array_diff($remain, ['.','..'])) === 0) @rmdir($legacyDir);
        return $urlBase . rawurlencode($canonicalName);
      }
      return '../uploads/assets/' . rawurlencode($register) . '/' . rawurlencode($legacyName);
    }
  }

  return null;
}

/** Upload Foto Aset 1/2 ke /uploads/assets/ berdasarkan Register Asset, tanpa subfolder. */
function fa_asset_upload_photo(string $fieldName, string $assetCode): ?string {
  if (empty($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name'])) return null;
  if (!isset($_FILES[$fieldName]['error']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Upload '.str_replace('_',' ', $fieldName).' gagal.');
  }

  $maxBytes = 5 * 1024 * 1024;
  $size = (int)($_FILES[$fieldName]['size'] ?? 0);
  if ($size <= 0 || $size > $maxBytes) {
    throw new RuntimeException('Ukuran setiap foto aset maksimal 5MB.');
  }

  $tmp = (string)($_FILES[$fieldName]['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    throw new RuntimeException('Upload foto aset tidak valid.');
  }

  $allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
  ];

  $mime = '';
  if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
      $mime = (string)finfo_file($fi, $tmp);
      finfo_close($fi);
    }
  }
  if (!isset($allowedMime[$mime])) {
    throw new RuntimeException('Format foto aset harus JPG, PNG, atau WEBP.');
  }

  $loc = fa_asset_media_location($assetCode);
  $register = (string)($loc['register'] ?? '');
  if ($register === '') throw new RuntimeException('Register aset belum valid untuk nama foto.');

  if (!is_dir($loc['dir'])) @mkdir($loc['dir'], 0775, true);
  if (!is_dir($loc['dir']) || !is_writable($loc['dir'])) {
    throw new RuntimeException('Folder upload foto aset belum siap: uploads/assets/.');
  }

  $slot = $fieldName === 'asset_photo_2' ? 2 : 1;
  $ext = $allowedMime[$mime];
  $baseName = $slot === 1 ? $register : $register . '_2';
  $fileName = $baseName . '.' . $ext;
  $target = $loc['dir'] . '/' . $fileName;

  // Simpan ke file sementara dulu agar replacement aman.
  $tmpTarget = $loc['dir'] . '/.' . $baseName . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  if (!move_uploaded_file($tmp, $tmpTarget)) {
    throw new RuntimeException('Foto aset gagal disimpan.');
  }

  // Bersihkan variant lama slot yang sama, termasuk format legacy flat.
  foreach (['jpg','jpeg','png','webp','JPG','JPEG','PNG','WEBP'] as $oldExt) {
    foreach ([$baseName, $register . '_' . $slot] as $oldBase) {
      $old = $loc['dir'] . '/' . $oldBase . '.' . $oldExt;
      if (is_file($old) && $old !== $tmpTarget) @unlink($old);
    }
  }

  if (!@rename($tmpTarget, $target)) {
    @unlink($tmpTarget);
    throw new RuntimeException('Foto aset gagal difinalisasi.');
  }

  return $loc['url'] . rawurlencode($fileName);
}

/** Hapus file foto aset hanya bila path memang berada pada area uploads/assets. */
function fa_asset_delete_photo_file(?string $relativePath): void {
  $relativePath = trim((string)$relativePath);
  if ($relativePath === '') return;

  $root = realpath(dirname(__DIR__) . '/uploads/assets');
  if ($root === false) return;

  $candidate = null;
  if (str_starts_with($relativePath, '../uploads/assets/')) {
    $candidate = dirname(__DIR__) . '/uploads/assets/' . substr($relativePath, strlen('../uploads/assets/'));
  } elseif (str_starts_with($relativePath, 'uploads/assets/')) {
    $candidate = dirname(__DIR__) . '/uploads/assets/' . substr($relativePath, strlen('uploads/assets/'));
  }

  if (!$candidate) return;
  $real = realpath($candidate);
  if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real)) {
    @unlink($real);
  }
}

/** Cek path foto DB; Fixed Asset hanya boleh memakai area assets atau legacy asset_receive. */
function fa_asset_photo_path_exists(string $relativePath): bool {
  $relativePath = trim(str_replace('\\', '/', $relativePath));
  if ($relativePath === '') return false;

  // Tegas: media produk tidak boleh menjadi foto fixed asset.
  if (str_contains($relativePath, 'uploads/products/')) return false;

  if (str_starts_with($relativePath, '../uploads/assets/')) {
    return is_file(dirname(__DIR__) . '/uploads/assets/' . substr($relativePath, strlen('../uploads/assets/')));
  }

  if (str_starts_with($relativePath, 'uploads/assets/')) {
    // Root ERP canonical.
    if (is_file(dirname(__DIR__) . '/' . $relativePath)) return true;
    // Legacy lama di Fixed_Asset/uploads/assets.
    if (is_file(__DIR__ . '/' . $relativePath)) return true;
  }

  // Legacy penerimaan ITC sebelum dicopy ke canonical folder asset.
  if (str_starts_with($relativePath, 'uploads/asset_receive/')) {
    return is_file(__DIR__ . '/' . $relativePath);
  }

  return false;
}

/** Ambil foto DB bila file masih ada; jika tidak, fallback ke file berdasarkan Register Asset. */
function fa_asset_resolved_photo(array $asset, int $slot): ?string {
  $key = $slot === 2 ? 'asset_photo_2' : 'asset_photo_1';
  $dbPath = trim((string)($asset[$key] ?? ''));

  if ($dbPath !== '' && fa_asset_photo_path_exists($dbPath)) return $dbPath;
  return fa_asset_manual_photo((string)($asset['asset_code'] ?? ''), $slot);
}

/**
 * Salin foto penerimaan ITC ke folder aset canonical saat ACT melakukan register.
 * Jika sumber tidak tersedia/copy gagal, path lama dipertahankan sebagai fallback.
 */
function fa_asset_copy_receive_photo_to_asset_folder(?string $receivePath, string $assetCode): ?string {
  $receivePath = trim((string)$receivePath);
  if ($receivePath === '') return null;

  $source = null;
  if (str_starts_with($receivePath, 'uploads/asset_receive/')) {
    $source = __DIR__ . '/' . $receivePath;
  }
  if (!$source || !is_file($source)) return $receivePath;

  $mime = '';
  if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
      $mime = (string)finfo_file($fi, $source);
      finfo_close($fi);
    }
  }
  $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
  if (!isset($extMap[$mime])) return $receivePath;

  $loc = fa_asset_media_location($assetCode);
  $register = (string)($loc['register'] ?? '');
  if ($register === '') return $receivePath;
  if (!is_dir($loc['dir'])) @mkdir($loc['dir'], 0775, true);
  if (!is_dir($loc['dir']) || !is_writable($loc['dir'])) return $receivePath;

  // Foto penerimaan menjadi foto utama dengan nama persis Register Asset.
  $targetName = $register . '.' . $extMap[$mime];
  $target = $loc['dir'] . '/' . $targetName;
  if (@copy($source, $target)) {
    return $loc['url'] . rawurlencode($targetName);
  }
  return $receivePath;
}

/**
 * Normalisasi aman kode aset lama.
 * Hanya mengubah asset_code untuk record legacy yang belum mengikuti format
 * OFFICE-KATEGORI-NNNN. Nilai aset, quantity, office, dept, depresiasi, status,
 * dan histori lain tidak disentuh. Kode lama disimpan di legacy_asset_code.
 */
function fa_normalize_legacy_asset_codes(PDO $pdo, array $asset_categories): array {
  fa_ensure_legacy_asset_code_column($pdo);

  $st = $pdo->query("SELECT id,asset_code,asset_category_code,category,office_code FROM fa_assets WHERE deleted_at IS NULL ORDER BY id ASC");
  $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

  // Cari nomor maksimum yang SUDAH dipakai per office+kategori agar kode baru tidak bentrok.
  $maxByPrefix = [];
  foreach ($rows as $r) {
    $office = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($r['office_code'] ?? '')));
    $cat = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($r['asset_category_code'] ?? '')));
    $code = strtoupper(trim((string)($r['asset_code'] ?? '')));
    if ($office === '' || $cat === '' || !isset($asset_categories[$cat])) continue;
    $prefix = $office . '-' . $cat . '-';
    if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{4,})$/', $code, $m)) {
      $maxByPrefix[$prefix] = max($maxByPrefix[$prefix] ?? 0, (int)$m[1]);
    }
  }

  $upd = $pdo->prepare("UPDATE fa_assets
    SET legacy_asset_code=COALESCE(NULLIF(legacy_asset_code,''),?),
        asset_code=?, asset_category_code=?, category=?, updated_at=NOW()
    WHERE id=?");

  $updated = 0; $skipped = 0; $errors = [];
  foreach ($rows as $r) {
    $id = (int)($r['id'] ?? 0);
    $oldCode = trim((string)($r['asset_code'] ?? ''));
    $office = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($r['office_code'] ?? '')));
    $cat = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($r['asset_category_code'] ?? '')));

    // Legacy: category code dahulu tersimpan langsung pada asset_code (0708/0710/0413/dll).
    if ((!isset($asset_categories[$cat]) || $cat === '') && preg_match('/^\d{4}$/', $oldCode) && isset($asset_categories[$oldCode])) {
      $cat = $oldCode;
    }

    if ($id <= 0 || $office === '' || !isset($asset_categories[$cat])) {
      $skipped++;
      continue;
    }

    $prefix = $office . '-' . $cat . '-';
    if (preg_match('/^' . preg_quote($prefix, '/') . '\d{4,}$/i', $oldCode)) {
      continue; // sudah format baru
    }

    $next = ($maxByPrefix[$prefix] ?? 0) + 1;
    $maxByPrefix[$prefix] = $next;
    $newCode = $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

    try {
      $upd->execute([$oldCode !== '' ? $oldCode : null, $newCode, $cat, $asset_categories[$cat], $id]);
      $updated++;
      if (function_exists('fa_log')) {
        fa_log($pdo, 'NORMALIZE_ASSET_CODE', 'ASSET', $id, [
          'old_asset_code' => $oldCode,
          'new_asset_code' => $newCode,
          'office_code' => $office,
          'asset_category_code' => $cat,
        ]);
      }
    } catch (Throwable $e) {
      $errors[] = 'ID '.$id.': '.$e->getMessage();
    }
  }

  return ['updated'=>$updated,'skipped'=>$skipped,'errors'=>$errors];
}


fa_ensure_asset_photo_columns($pdo);

$master_offices = fa_master_offices($pdo);
$master_depts = fa_master_departements($pdo);
$a = $_GET['a'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$csrf = urlencode(rmi_csrf_token());


// -----------------------------------------------------------------------------
// ALUR PEMBELIAN ASET (tidak mengubah alur Asset Register lama)
// Mgr ITC -> PQP -> FIN -> ITC Receive -> ACT Register -> fa_assets
// -----------------------------------------------------------------------------
function fa_asset_request_preflight(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS fa_purchase_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(50) NOT NULL UNIQUE,
    request_date DATE NOT NULL,
    requested_by VARCHAR(100) NOT NULL,
    request_dept VARCHAR(20) NOT NULL DEFAULT 'ITC',
    office_code VARCHAR(50) NULL,
    asset_name VARCHAR(255) NOT NULL,
    asset_category_code VARCHAR(20) NULL,
    asset_category VARCHAR(100) NULL,
    qty INT NOT NULL DEFAULT 1,
    estimated_price DECIMAL(18,2) DEFAULT 0,
    approved_price DECIMAL(18,2) DEFAULT 0,
    selected_vendor VARCHAR(255) NULL,
    invoice_no VARCHAR(100) NULL,
    purchase_ref VARCHAR(100) NULL,
    received_at DATETIME NULL,
    received_by VARCHAR(100) NULL,
    itc_receive_photo VARCHAR(255) NULL,
    itc_receive_photo_note TEXT NULL,
    itc_receive_match_status VARCHAR(20) NULL,
    registered_asset_id INT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'DRAFT',
    reason TEXT NULL,
    pqp_note TEXT NULL,
    fin_note TEXT NULL,
    receive_note TEXT NULL,
    act_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    INDEX idx_fa_pr_status(status),
    INDEX idx_fa_pr_office(office_code),
    INDEX idx_fa_pr_registered(registered_asset_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Kolom penerimaan ITC untuk database existing. Dibuat aman agar tidak mengubah alur lama.
  $itcCols = [
    'itc_receive_photo' => "ALTER TABLE fa_purchase_requests ADD COLUMN itc_receive_photo VARCHAR(255) NULL AFTER received_by",
    'itc_receive_photo_note' => "ALTER TABLE fa_purchase_requests ADD COLUMN itc_receive_photo_note TEXT NULL AFTER itc_receive_photo",
    'itc_receive_match_status' => "ALTER TABLE fa_purchase_requests ADD COLUMN itc_receive_match_status VARCHAR(20) NULL AFTER itc_receive_photo_note",
  ];
  foreach ($itcCols as $col => $sql) {
    try {
      $chk = $pdo->query("SHOW COLUMNS FROM fa_purchase_requests LIKE " . $pdo->quote($col));
      if (!$chk || !$chk->fetch(PDO::FETCH_ASSOC)) { $pdo->exec($sql); }
    } catch (Throwable $e) { /* jangan hentikan halaman jika ALTER gagal; tampilkan melalui proses normal */ }
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS fa_purchase_quotations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    vendor_name VARCHAR(255) NOT NULL,
    quotation_no VARCHAR(100) NULL,
    quotation_amount DECIMAL(18,2) DEFAULT 0,
    quality_note TEXT NULL,
    file_path VARCHAR(255) NULL,
    is_selected TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fa_pq_request(request_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS fa_purchase_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    status_from VARCHAR(50) NULL,
    status_to VARCHAR(50) NOT NULL,
    actor_dept VARCHAR(50) NULL,
    actor_name VARCHAR(100) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fa_pa_request(request_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function fa_asset_current_user(): array {
  $username = trim((string)($_SESSION['username'] ?? $_SESSION['user'] ?? $_SESSION['login'] ?? $_SESSION['name'] ?? 'SYSTEM'));
  $level = strtoupper(trim((string)($_SESSION['level'] ?? $_SESSION['role'] ?? '')));
  $dept = strtoupper(trim((string)($_SESSION['dept_code'] ?? $_SESSION['department'] ?? $_SESSION['departement'] ?? '')));
  return ['username'=>$username ?: 'SYSTEM', 'level'=>$level, 'dept'=>$dept];
}

function fa_asset_user_is(array $u, string $dept): bool {
  $d = strtoupper($dept);
  return $u['dept'] === $d || str_contains(strtoupper($u['username']), $d) || str_contains(strtoupper($u['level']), $d);
}

function fa_asset_is_privileged(): bool {
  $lvl = strtolower((string)($_SESSION['level'] ?? $_SESSION['role'] ?? ''));
  return (function_exists('rbac_is_privileged_session') && rbac_is_privileged_session()) || in_array($lvl, ['sys','admin','superadmin','owner'], true);
}

function fa_asset_can_itc_request(array $u): bool { return fa_asset_is_privileged() || fa_asset_user_is($u,'ITC') || str_starts_with(strtoupper($u['username']), 'MGRITC'); }
function fa_asset_can_pqp(array $u): bool { return fa_asset_is_privileged() || fa_asset_user_is($u,'PQP'); }
function fa_asset_can_fin(array $u): bool { return fa_asset_is_privileged() || fa_asset_user_is($u,'FIN'); }
function fa_asset_can_act(array $u): bool { return fa_asset_is_privileged() || fa_asset_user_is($u,'ACT'); }

function fa_asset_next_request_code(PDO $pdo): string {
  $ymd = date('ymd');
  $prefix = 'FAPR-'.$ymd.'-';
  $st = $pdo->prepare("SELECT request_code FROM fa_purchase_requests WHERE request_code LIKE ? ORDER BY id DESC LIMIT 1");
  $st->execute([$prefix.'%']);
  $last = (string)($st->fetchColumn() ?: '');
  $n = 1;
  if (preg_match('/-(\d+)$/', $last, $m)) $n = (int)$m[1] + 1;
  return $prefix . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

function fa_asset_purchase_log(PDO $pdo, int $requestId, ?string $from, string $to, string $note=''): void {
  $u = fa_asset_current_user();
  $st = $pdo->prepare("INSERT INTO fa_purchase_audit(request_id,status_from,status_to,actor_dept,actor_name,note) VALUES (?,?,?,?,?,?)");
  $st->execute([$requestId,$from,$to,$u['dept'],$u['username'],$note]);
}

function fa_asset_get_purchase_request(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare("SELECT * FROM fa_purchase_requests WHERE id=?");
  $st->execute([$id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

function fa_asset_upload_itc_receive_photo(string $fieldName, string $requestCode): string {
  if (empty($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name'])) {
    throw new RuntimeException('Foto bukti penerimaan ITC wajib diupload.');
  }
  if (!isset($_FILES[$fieldName]['error']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Upload foto penerimaan ITC gagal.');
  }

  $allowedExt = ['jpg','jpeg','png','webp'];
  $orig = (string)$_FILES[$fieldName]['name'];
  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) {
    throw new RuntimeException('Format foto penerimaan harus JPG, JPEG, PNG, atau WEBP.');
  }
  if ((int)($_FILES[$fieldName]['size'] ?? 0) > 5 * 1024 * 1024) {
    throw new RuntimeException('Ukuran foto penerimaan maksimal 5MB.');
  }

  $baseDir = __DIR__ . '/uploads/asset_receive';
  if (!is_dir($baseDir)) { @mkdir($baseDir, 0775, true); }
  if (!is_dir($baseDir) || !is_writable($baseDir)) {
    throw new RuntimeException('Folder upload foto ITC belum siap: Fixed_Asset/uploads/asset_receive.');
  }

  $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', $requestCode);
  $fileName = $safeCode . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $target = $baseDir . '/' . $fileName;
  if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target)) {
    throw new RuntimeException('Foto penerimaan ITC gagal disimpan.');
  }
  return 'uploads/asset_receive/' . $fileName;
}

function fa_asset_purchase_status_label(string $status): string {
  $map = [
    'DRAFT'=>'Draft', 'SUBMITTED_TO_PQP'=>'Menunggu PQP', 'PQP_REVIEW'=>'Review PQP',
    'PQP_QUOTATION'=>'Penawaran PQP', 'WAIT_FIN_APPROVAL'=>'Menunggu FIN',
    'FIN_APPROVED'=>'Disetujui FIN', 'FIN_REJECTED'=>'Ditolak FIN', 'WAIT_PURCHASE'=>'Menunggu Pembelian',
    'PURCHASED'=>'Dibeli PQP', 'RECEIVED_BY_ITC'=>'Diterima ITC - Sesuai',
    'RECEIVED_MISMATCH_ITC'=>'Diterima ITC - Tidak Sesuai', 'WAIT_ACT_REGISTER'=>'Menunggu ACT Register',
    'REGISTERED_AS_FIXED_ASSET'=>'Masuk Fixed Asset', 'CANCELLED'=>'Dibatalkan'
  ];
  return $map[$status] ?? $status;
}

fa_asset_request_preflight($pdo);
$fa_purchase_user = fa_asset_current_user();


// Verify CSRF for POST requests
if ($_SERVER['REQUEST_METHOD']==='POST') {
  fa_csrf_verify();
}

// --- ALUR PEMBELIAN ASET: POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $flow_action = $_POST['_action'] ?? '';

  if ($flow_action === 'asset_request_submit') {
    if (!fa_asset_can_itc_request($fa_purchase_user)) { flash_set('danger','Hanya Mgr/Staff ITC yang dapat membuat pengajuan pembelian aset.'); redirect_to('assets.php'); }
    $asset_name = trim($_POST['asset_name'] ?? '');
    $office_code = strtoupper(trim($_POST['office_code'] ?? ''));
    $asset_category_code = trim($_POST['asset_category_code'] ?? '');
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    $estimated_price = max(0, (float)($_POST['estimated_price'] ?? 0));
    $reason = trim($_POST['reason'] ?? '');
    if ($asset_name==='' || $office_code==='') { flash_set('danger','Nama aset dan office wajib diisi.'); redirect_to('assets.php'); }
    $request_code = fa_asset_next_request_code($pdo);
    $catName = $asset_categories[$asset_category_code] ?? null;
    $st = $pdo->prepare("INSERT INTO fa_purchase_requests(request_code,request_date,requested_by,request_dept,office_code,asset_name,asset_category_code,asset_category,qty,estimated_price,reason,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
    $st->execute([$request_code,date('Y-m-d'),$fa_purchase_user['username'],'ITC',$office_code,$asset_name,$asset_category_code,$catName,$qty,$estimated_price,$reason,'SUBMITTED_TO_PQP']);
    $rid = (int)$pdo->lastInsertId();
    fa_asset_purchase_log($pdo,$rid,'DRAFT','SUBMITTED_TO_PQP','Pengajuan pembelian aset dibuat oleh ITC.');
    flash_set('success','Pengajuan pembelian aset berhasil dibuat: '.$request_code);
    redirect_to('assets.php#asset-purchase-flow');
  }

  if ($flow_action === 'asset_pqp_save') {
    if (!fa_asset_can_pqp($fa_purchase_user)) { flash_set('danger','Hanya PQP yang dapat memproses penawaran/pembelian aset.'); redirect_to('assets.php#asset-purchase-flow'); }
    $rid = (int)($_POST['request_id'] ?? 0);
    $req = fa_asset_get_purchase_request($pdo,$rid);
    if (!$req) { flash_set('danger','Request tidak ditemukan.'); redirect_to('assets.php#asset-purchase-flow'); }
    $old = $req['status'];
    $vendor = trim($_POST['selected_vendor'] ?? '');
    $price = max(0, (float)($_POST['approved_price'] ?? $_POST['quotation_amount'] ?? 0));
    $purchase_ref = trim($_POST['purchase_ref'] ?? '');
    $pqp_note = trim($_POST['pqp_note'] ?? '');
    $nextStatus = ($_POST['send_to_fin'] ?? '') === '1' ? 'WAIT_FIN_APPROVAL' : 'PQP_QUOTATION';
    if ($vendor==='') { flash_set('danger','Vendor rekomendasi wajib diisi oleh PQP.'); redirect_to('assets.php#asset-purchase-flow'); }
    $pdo->prepare("UPDATE fa_purchase_requests SET selected_vendor=?, approved_price=?, purchase_ref=?, pqp_note=?, status=?, updated_at=NOW() WHERE id=?")
        ->execute([$vendor,$price,$purchase_ref,$pqp_note,$nextStatus,$rid]);
    if ($price > 0) {
      $pdo->prepare("INSERT INTO fa_purchase_quotations(request_id,vendor_name,quotation_amount,quality_note,is_selected) VALUES (?,?,?,?,1)")
          ->execute([$rid,$vendor,$price,$pqp_note]);
    }
    fa_asset_purchase_log($pdo,$rid,$old,$nextStatus,'PQP input vendor/rekomendasi.');
    flash_set('success','Data PQP tersimpan.');
    redirect_to('assets.php#asset-purchase-flow');
  }

  if ($flow_action === 'asset_fin_decision') {
    if (!fa_asset_can_fin($fa_purchase_user)) { flash_set('danger','Hanya FIN yang dapat approve/reject anggaran pembelian aset.'); redirect_to('assets.php#asset-purchase-flow'); }
    $rid = (int)($_POST['request_id'] ?? 0);
    $req = fa_asset_get_purchase_request($pdo,$rid);
    if (!$req) { flash_set('danger','Request tidak ditemukan.'); redirect_to('assets.php#asset-purchase-flow'); }
    $old = $req['status'];
    $decision = $_POST['decision'] ?? 'reject';
    $fin_note = trim($_POST['fin_note'] ?? '');
    $nextStatus = $decision === 'approve' ? 'FIN_APPROVED' : 'FIN_REJECTED';
    $pdo->prepare("UPDATE fa_purchase_requests SET status=?, fin_note=?, updated_at=NOW() WHERE id=?")->execute([$nextStatus,$fin_note,$rid]);
    fa_asset_purchase_log($pdo,$rid,$old,$nextStatus,$fin_note);
    flash_set('success','Keputusan FIN tersimpan.');
    redirect_to('assets.php#asset-purchase-flow');
  }

  if ($flow_action === 'asset_pqp_purchased') {
    if (!fa_asset_can_pqp($fa_purchase_user)) { flash_set('danger','Hanya PQP yang dapat menandai aset sudah dibeli.'); redirect_to('assets.php#asset-purchase-flow'); }
    $rid = (int)($_POST['request_id'] ?? 0);
    $req = fa_asset_get_purchase_request($pdo,$rid);
    if (!$req) { flash_set('danger','Request tidak ditemukan.'); redirect_to('assets.php#asset-purchase-flow'); }
    if ($req['status'] !== 'FIN_APPROVED') { flash_set('danger','Pembelian hanya dapat diproses setelah FIN_APPROVED.'); redirect_to('assets.php#asset-purchase-flow'); }
    $invoice_no = trim($_POST['invoice_no'] ?? '');
    $purchase_ref = trim($_POST['purchase_ref'] ?? ($req['purchase_ref'] ?? ''));
    $pdo->prepare("UPDATE fa_purchase_requests SET invoice_no=?, purchase_ref=?, status='PURCHASED', updated_at=NOW() WHERE id=?")->execute([$invoice_no,$purchase_ref,$rid]);
    fa_asset_purchase_log($pdo,$rid,'FIN_APPROVED','PURCHASED','PQP menandai aset sudah dibeli.');
    flash_set('success','Status pembelian aset diperbarui.');
    redirect_to('assets.php#asset-purchase-flow');
  }

  if ($flow_action === 'asset_itc_receive') {
    if (!fa_asset_can_itc_request($fa_purchase_user)) { flash_set('danger','Hanya ITC yang dapat konfirmasi penerimaan aset.'); redirect_to('assets.php#asset-purchase-flow'); }
    $rid = (int)($_POST['request_id'] ?? 0);
    $req = fa_asset_get_purchase_request($pdo,$rid);
    if (!$req) { flash_set('danger','Request tidak ditemukan.'); redirect_to('assets.php#asset-purchase-flow'); }
    if (!in_array($req['status'], ['PURCHASED','FIN_APPROVED'], true)) { flash_set('danger','Aset hanya dapat diterima setelah dibeli/diapprove FIN.'); redirect_to('assets.php#asset-purchase-flow'); }

    $receive_note = trim($_POST['receive_note'] ?? '');
    $match_status = strtoupper(trim((string)($_POST['itc_receive_match_status'] ?? 'MATCH')));
    if (!in_array($match_status, ['MATCH','NOT_MATCH'], true)) { $match_status = 'MATCH'; }

    try {
      $photo_path = fa_asset_upload_itc_receive_photo('itc_receive_photo', (string)$req['request_code']);
      $new_status = ($match_status === 'MATCH') ? 'RECEIVED_BY_ITC' : 'RECEIVED_MISMATCH_ITC';
      $pdo->prepare("UPDATE fa_purchase_requests
        SET received_at=NOW(), received_by=?, receive_note=?,
            itc_receive_photo=?, itc_receive_photo_note=?, itc_receive_match_status=?,
            status=?, updated_at=NOW()
        WHERE id=?")
        ->execute([$fa_purchase_user['username'],$receive_note,$photo_path,$receive_note,$match_status,$new_status,$rid]);
      fa_asset_purchase_log($pdo,$rid,$req['status'],$new_status,'ITC menerima aset. Kesesuaian: '.$match_status.'. '.$receive_note);
      if ($new_status === 'RECEIVED_BY_ITC') {
        flash_set('success','Penerimaan aset oleh ITC tersimpan dengan foto. Status barang sesuai dan siap ACT register.');
      } else {
        flash_set('warning','Penerimaan ITC tersimpan, tetapi barang tidak sesuai. ACT belum boleh register asset.');
      }
    } catch (Throwable $e) {
      flash_set('danger',$e->getMessage());
    }
    redirect_to('assets.php#asset-purchase-flow');
  }

  if ($flow_action === 'asset_act_register') {
    if (!fa_asset_can_act($fa_purchase_user)) { flash_set('danger','Hanya ACT yang dapat finalisasi aset ke Fixed Asset.'); redirect_to('assets.php#asset-purchase-flow'); }
    $rid = (int)($_POST['request_id'] ?? 0);
    $req = fa_asset_get_purchase_request($pdo,$rid);
    if (!$req) { flash_set('danger','Request tidak ditemukan.'); redirect_to('assets.php#asset-purchase-flow'); }
    if ($req['status'] !== 'RECEIVED_BY_ITC') { flash_set('danger','ACT hanya bisa register setelah status RECEIVED_BY_ITC.'); redirect_to('assets.php#asset-purchase-flow'); }
    if ((int)($req['registered_asset_id'] ?? 0) > 0) { flash_set('warning','Request ini sudah masuk Fixed Asset.'); redirect_to('assets.php#asset-purchase-flow'); }

    $asset_category_code = trim((string)($req['asset_category_code'] ?? ''));
    if ($asset_category_code === '' || !isset($asset_categories[$asset_category_code])) $asset_category_code = '0708';
    $office_code = strtoupper(trim((string)($req['office_code'] ?? '')));
    if ($office_code === '') $office_code = 'BGR';
    $asset_code = fa_generate_asset_register_code($pdo,$office_code,$asset_category_code);
    $qty = max(1, (int)($req['qty'] ?? 1));
    $total = (float)($req['approved_price'] ?? 0);
    if ($total <= 0) $total = (float)($req['estimated_price'] ?? 0);
    $unit = $qty > 0 ? ($total / $qty) : $total;
    $category = $asset_categories[$asset_category_code] ?? ($req['asset_category'] ?? 'Peralatan');
    $notes = 'Auto dari pengajuan pembelian aset '.$req['request_code'].'. '.trim((string)($_POST['act_note'] ?? ''));

    // Foto penerimaan ITC otomatis menjadi Foto 1 aset agar bukti barang ikut ke register.
    $photo1FromReceive = fa_asset_copy_receive_photo_to_asset_folder($req['itc_receive_photo'] ?? null, $asset_code);
    $stmt=$pdo->prepare("INSERT INTO fa_assets(asset_code,asset_name,asset_category_code,quantity,unit_cost,category,office_code,dept_code,custodian_emp_id,vendor_name,purchase_ref,invoice_no,acq_date,acq_cost,salvage_value,tax_group_code,dep_method,status,notes,asset_photo_1,asset_photo_2,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
    $stmt->execute([$asset_code,$req['asset_name'],$asset_category_code,$qty,$unit,$category,$office_code,'ITC',null,$req['selected_vendor'],$req['purchase_ref'],$req['invoice_no'],date('Y-m-d'),$total,0,'G1','SL','ACTIVE',$notes,$photo1FromReceive,null]);
    $assetId=(int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE fa_purchase_requests SET registered_asset_id=?, act_note=?, status='REGISTERED_AS_FIXED_ASSET', updated_at=NOW() WHERE id=?")
        ->execute([$assetId, trim($_POST['act_note'] ?? ''), $rid]);
    fa_asset_purchase_log($pdo,$rid,'RECEIVED_BY_ITC','REGISTERED_AS_FIXED_ASSET','ACT register ke fa_assets ID '.$assetId);
    fa_log($pdo,'CREATE_FROM_PURCHASE_REQUEST','ASSET',$assetId,['request_id'=>$rid,'request_code'=>$req['request_code']]);
    flash_set('success','Aset berhasil masuk Fixed Asset Register: '.$asset_code);
    redirect_to('assets.php#asset-purchase-flow');
  }
}

// --- FASE 3: template / seed / import / bulk ---

// Seragamkan kode aset lama menjadi OFFICE-KATEGORI-NNNN tanpa mengubah nilai/depresiasi aset.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action'] ?? '') === 'normalize_legacy_codes') {
  $result = fa_normalize_legacy_asset_codes($pdo, $asset_categories);
  $msg = "Normalisasi kode aset selesai. Diubah: {$result['updated']}; dilewati: {$result['skipped']}.";
  if (!empty($result['errors'])) $msg .= ' Error: '.implode(' | ', array_slice($result['errors'],0,5));
  flash_set(empty($result['errors']) ? 'success' : 'warning', $msg);
  redirect_to('assets.php');
}

// AUTO-NORMALIZE legacy code saat daftar Asset Register dibuka.
// Aman dijalankan berulang karena record yang sudah OFFICE-KATEGORI-NNNN akan dilewati.
if ($_SERVER['REQUEST_METHOD']==='GET' && $a === 'list') {
  try {
    $autoNorm = fa_normalize_legacy_asset_codes($pdo, $asset_categories);
    if (($autoNorm['updated'] ?? 0) > 0) {
      flash_set('success', 'Kode aset lama otomatis diseragamkan: '.(int)$autoNorm['updated'].' aset.');
      redirect_to('assets.php?normalized=1');
    }
  } catch (Throwable $e) {
    // Jangan memblokir Asset Register jika normalisasi gagal; alur existing tetap berjalan.
    if (function_exists('fa_log')) fa_log($pdo,'NORMALIZE_ASSET_CODE_ERROR','ASSET',0,['error'=>$e->getMessage()]);
  }
}

if ($a === 'template') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="fa_assets_import_template.csv"');
  $out = fopen('php://output','w');
  fputcsv($out, ['asset_code','asset_category_code','asset_name','quantity','unit_cost','office_code','dept_code','custodian_employee_id','acq_date','salvage_value','tax_group_code','dep_method','status','notes']);
  fputcsv($out, ['', '0708','Laptop Dell Latitude','5','8000000','BGR','ITC','12','2025-12-29','0','G1','SL','ACTIVE','contoh: asset_code boleh kosong agar dibuat otomatis']);
  fclose($out);
  exit;
}

if ($a === 'seed') {
  // ADMIN/SUPERADMIN saja
  if (!(function_exists('rbac_is_privileged_session') && rbac_is_privileged_session())) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
  fa_csrf_verify((string)($_GET['_csrf'] ?? ''));

  // seed sample assets (aman diulang, pakai unique asset_code)
  $samples = [
    ['LAP-001','Laptop Operasional','BOGOR','ITC',0,'2025-12-01',15000000,0,'IT','G1','SL','ACTIVE','seed'],
    ['PRN-001','Printer Kantor','BEKASI','FIN',0,'2025-11-15',3500000,0,'Office','G1','SL','ACTIVE','seed'],
    ['CAR-001','Mobil Operasional','BOGOR','MP',0,'2025-01-10',220000000,0,'Kendaraan','G2','DDB','ACTIVE','seed'],
    ['BLD-001','Bangunan Depo','SEMARANG','WQS',0,'2024-06-01',1500000000,0,'Bangunan','B-PERM','SL','ACTIVE','seed'],
  ];
  $stmt = $pdo->prepare("INSERT IGNORE INTO fa_assets(asset_code,asset_name,office_code,dept_code,custodian_employee_id,acq_date,acq_cost,salvage_value,category,tax_group_code,dep_method,status,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
  $n=0;
  foreach($samples as $s){
    $stmt->execute($s);
    $n += (int)$stmt->rowCount();
  }
  fa_log($pdo,'SEED','ASSET',0,['inserted'=>$n]);
  flash_set('success',"Seed selesai. Inserted: {$n}");
  redirect_to('assets.php');
}

// Import CSV
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action'] ?? '') === 'import_assets') {
  if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    flash_set('danger','File CSV tidak valid.');
    redirect_to('assets.php');
  }
  $orig_name = (string)($_FILES['csv_file']['name'] ?? '');
  $safe_name = safe_filename($orig_name);
  $size = (int)($_FILES['csv_file']['size'] ?? 0);
  if ($safe_name === '' || !preg_match('/\.csv$/i', $safe_name)) {
    flash_set('danger','File harus CSV (.csv).');
    redirect_to('assets.php');
  }
  if ($size <= 0 || $size > 5*1024*1024) {
    flash_set('danger','Ukuran file CSV tidak valid (maks 5MB).');
    redirect_to('assets.php');
  }
  $tmp = $_FILES['csv_file']['tmp_name'] ?? '';
  if ($tmp==='' || !is_uploaded_file($tmp)) {
    flash_set('danger','Upload tidak valid.');
    redirect_to('assets.php');
  }
  $fh = fopen($tmp,'r');
  $header = fgetcsv($fh);
  if (!$header) { flash_set('danger','CSV kosong.'); redirect_to('assets.php'); }
  $map = [];
  foreach($header as $i=>$h){ $map[strtolower(trim($h))]=$i; }
  $req = ['asset_name','office_code','asset_category_code'];
  foreach($req as $r){ if(!isset($map[$r])) { flash_set('danger',"CSV harus punya kolom: {$r}"); redirect_to('assets.php'); } }

  $sel = $pdo->prepare("SELECT id FROM fa_assets WHERE asset_code=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
  $upd = $pdo->prepare("UPDATE fa_assets SET asset_name=?, asset_category_code=?, quantity=?, unit_cost=?, office_code=?, dept_code=?, custodian_emp_id=?, acq_date=?, acq_cost=?, salvage_value=?, category=?, tax_group_code=?, dep_method=?, status=?, notes=?, updated_at=NOW() WHERE id=?");
  $ins = $pdo->prepare("INSERT INTO fa_assets(asset_code,asset_name,asset_category_code,quantity,unit_cost,office_code,dept_code,custodian_emp_id,acq_date,acq_cost,salvage_value,category,tax_group_code,dep_method,status,notes,created_at,updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
  $count=0; $skipped=0;
  while(($row=fgetcsv($fh))!==false){
    $code = trim($row[$map['asset_code']] ?? '');
    $name = trim($row[$map['asset_name']] ?? '');
    $office = strtoupper(trim($row[$map['office_code']] ?? ''));
    $catCode = trim($row[$map['asset_category_code']] ?? '');
    if ($name==='' || $office==='' || $catCode==='' || !isset($asset_categories[$catCode])) { $skipped++; continue; }
    $qty = max(1, (int)trim($row[$map['quantity']] ?? '1'));
    $unitCost = (float)preg_replace('/[^0-9.\-]/', '', str_replace(',', '', trim($row[$map['unit_cost']] ?? '0')));
    if ($unitCost < 0) $unitCost = 0;
    $totalCost = $qty * $unitCost;
    $dept   = strtoupper(trim($row[$map['dept_code']] ?? ''));
    $cust   = (int)trim($row[$map['custodian_employee_id']] ?? '0');
    $date   = trim($row[$map['acq_date']] ?? date('Y-m-d'));
    $salv   = (float)preg_replace('/[^0-9.\-]/', '', str_replace(',', '', trim($row[$map['salvage_value']] ?? '0')));
    $tg     = trim($row[$map['tax_group_code']] ?? 'G1');
    $meth   = strtoupper(trim($row[$map['dep_method']] ?? 'SL'));
    $stat   = strtoupper(trim($row[$map['status']] ?? 'ACTIVE'));
    $notes  = trim($row[$map['notes']] ?? '');
    $categoryName = $asset_categories[$catCode];
    if ($code==='') $code = fa_generate_asset_register_code($pdo,$office,$catCode);
    $sel->execute([$code]);
    $exist_id = (int)($sel->fetchColumn() ?: 0);
    if ($exist_id>0) {
      $upd->execute([$name,$catCode,$qty,$unitCost,$office,$dept,$cust ?: null,$date,$totalCost,$salv,$categoryName,$tg,$meth,$stat,$notes,$exist_id]);
    } else {
      $ins->execute([$code,$name,$catCode,$qty,$unitCost,$office,$dept,$cust ?: null,$date,$totalCost,$salv,$categoryName,$tg,$meth,$stat,$notes]);
    }
    $count++;
  }
  fclose($fh);
  fa_log($pdo,'IMPORT_CSV','ASSET',0,['rows'=>$count]);
  flash_set('success',"Import CSV selesai. Berhasil: {$count}; dilewati: {$skipped}.");
  redirect_to('assets.php');
}

// Bulk actions (checkbox di list)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action'] ?? '') === 'bulk_assets') {
  $ids = $_POST['ids'] ?? [];
  if (!is_array($ids) || count($ids)===0) { flash_set('danger','Pilih minimal 1 asset.'); redirect_to('assets.php'); }
  $ids = array_values(array_filter(array_map('intval',$ids), fn($x)=>$x>0));
  if (!$ids) { flash_set('danger','Pilih minimal 1 asset.'); redirect_to('assets.php'); }

  $bulk_action = $_POST['bulk_action'] ?? '';
  $placeholders = implode(',', array_fill(0,count($ids),'?'));

  if ($bulk_action === 'soft_delete') {
    $pdo->prepare("UPDATE fa_assets SET deleted_at=NOW(), updated_at=NOW() WHERE id IN ($placeholders)")->execute($ids);
    fa_log($pdo,'BULK_SOFT_DELETE','ASSET',0,['ids'=>$ids]);
    flash_set('success','Bulk soft delete berhasil.');
    redirect_to('assets.php');
  }
  if ($bulk_action === 'set_status_active' || $bulk_action === 'set_status_disposed') {
    $new = $bulk_action==='set_status_active' ? 'ACTIVE' : 'DISPOSED';
    $pdo->prepare("UPDATE fa_assets SET status=?, updated_at=NOW() WHERE id IN ($placeholders)")->execute(array_merge([$new], $ids));
    fa_log($pdo,'BULK_SET_STATUS','ASSET',0,['status'=>$new,'ids'=>$ids]);
    flash_set('success',"Bulk status set: {$new}");
    redirect_to('assets.php');
  }
  if ($bulk_action === 'set_office') {
    $office = trim($_POST['bulk_office'] ?? '');
    if ($office==='') { flash_set('danger','Office wajib diisi.'); redirect_to('assets.php'); }
    $pdo->prepare("UPDATE fa_assets SET office_code=?, updated_at=NOW() WHERE id IN ($placeholders)")->execute(array_merge([$office], $ids));
    fa_log($pdo,'BULK_SET_OFFICE','ASSET',0,['office_code'=>$office,'ids'=>$ids]);
    flash_set('success',"Bulk office set: {$office}");
    redirect_to('assets.php');
  }
  if ($bulk_action === 'set_dept') {
    $dept = trim($_POST['bulk_dept'] ?? '');
    if ($dept==='') { flash_set('danger','Dept wajib diisi.'); redirect_to('assets.php'); }
    $pdo->prepare("UPDATE fa_assets SET dept_code=?, updated_at=NOW() WHERE id IN ($placeholders)")->execute(array_merge([$dept], $ids));
    fa_log($pdo,'BULK_SET_DEPT','ASSET',0,['dept_code'=>$dept,'ids'=>$ids]);
    flash_set('success',"Bulk dept set: {$dept}");
    redirect_to('assets.php');
  }

  flash_set('danger','Bulk action tidak dikenali.');
  redirect_to('assets.php');
}

// soft delete
if ($a === 'delete' && $id>0) {
  // hanya MANAGER/ADMIN
  $lvl = strtolower((string)($_SESSION['level'] ?? ''));
  if (!(function_exists('rbac_is_privileged_session') && rbac_is_privileged_session()) && $lvl !== 'manager') {
    flash_set('danger','Tidak punya izin untuk delete asset (butuh MANAGER/ADMIN).');
    redirect_to('assets.php');
  }
  fa_csrf_verify((string)($_GET['_csrf'] ?? ''));
  $pdo->prepare("UPDATE fa_assets SET deleted_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$id]);
  fa_log($pdo,'SOFT_DELETE','ASSET',$id);
  flash_set('success','Asset berhasil dihapus (soft delete).');
  redirect_to('assets.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $form_action = $_POST['_action'] ?? '';
  if ($form_action === 'save_asset') {
    $asset_code = trim($_POST['asset_code'] ?? '');
    $asset_name = trim($_POST['asset_name'] ?? '');
    $asset_category_code = trim($_POST['asset_category_code'] ?? '');
    $office_code = strtoupper(trim($_POST['office_code'] ?? ''));
    $category = $asset_categories[$asset_category_code] ?? '';
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $unit_cost = max(0, (float)($_POST['unit_cost'] ?? 0));
    $dept_code = trim($_POST['dept_code'] ?? '');
    $custodian_emp_id = ($_POST['custodian_emp_id'] ?? '') !== '' ? (int)$_POST['custodian_emp_id'] : null;
    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $purchase_ref = trim($_POST['purchase_ref'] ?? '');
    $invoice_no = trim($_POST['invoice_no'] ?? '');
    $acq_date = $_POST['acq_date'] ?? date('Y-m-d');
    $acq_cost = $quantity * $unit_cost;
    $salvage = (float)($_POST['salvage_value'] ?? 0);
    $tax_group = $_POST['tax_group_code'] ?? 'G1';
    $method = $_POST['dep_method'] ?? 'SL';
    $status = $_POST['status'] ?? 'ACTIVE';
    $notes = trim($_POST['notes'] ?? '');

    if ($asset_name==='' || $office_code==='' || $asset_category_code==='' || !isset($asset_categories[$asset_category_code])) {
      flash_set('danger','Office, Kode Kategori Aset, dan Asset Name wajib diisi.');
      redirect_to('assets.php?a='.(($id>0)?'edit&id='.$id:'new'));
    }
    if ($id<=0 && $asset_code==='') {
      $asset_code = fa_generate_asset_register_code($pdo,$office_code,$asset_category_code);
    }
    if (!isset($groups[$tax_group])) $tax_group='G1';
    if ($method==='DDB' && (int)$groups[$tax_group]['allow_ddb']===0) $method='SL';

    // Foto bersifat opsional saat simpan. Pada edit, file lama tetap dipakai jika tidak upload pengganti.
    $oldPhoto1 = null; $oldPhoto2 = null;
    if ($id > 0) {
      $ps = $pdo->prepare("SELECT asset_photo_1,asset_photo_2 FROM fa_assets WHERE id=?");
      $ps->execute([$id]);
      $pr = $ps->fetch(PDO::FETCH_ASSOC) ?: [];
      $oldPhoto1 = trim((string)($pr['asset_photo_1'] ?? '')) ?: null;
      $oldPhoto2 = trim((string)($pr['asset_photo_2'] ?? '')) ?: null;
    }

    try {
      $newPhoto1 = fa_asset_upload_photo('asset_photo_1', $asset_code);
      $newPhoto2 = fa_asset_upload_photo('asset_photo_2', $asset_code);
    } catch (Throwable $e) {
      flash_set('danger',$e->getMessage());
      redirect_to('assets.php?a='.(($id>0)?'edit&id='.$id:'new'));
    }
    $photo1 = $newPhoto1 ?: $oldPhoto1;
    $photo2 = $newPhoto2 ?: $oldPhoto2;

    if ($id>0) {
      $stmt=$pdo->prepare("UPDATE fa_assets SET asset_code=?,asset_name=?,asset_category_code=?,quantity=?,unit_cost=?,category=?,office_code=?,dept_code=?,custodian_emp_id=?,vendor_name=?,purchase_ref=?,invoice_no=?,acq_date=?,acq_cost=?,salvage_value=?,tax_group_code=?,dep_method=?,status=?,notes=?,asset_photo_1=?,asset_photo_2=?,updated_at=NOW() WHERE id=?");
      $stmt->execute([$asset_code,$asset_name,$asset_category_code,$quantity,$unit_cost,$category,$office_code,$dept_code,$custodian_emp_id,$vendor_name,$purchase_ref,$invoice_no,$acq_date,$acq_cost,$salvage,$tax_group,$method,$status,$notes,$photo1,$photo2,$id]);
      if ($newPhoto1 && $oldPhoto1 && $newPhoto1 !== $oldPhoto1) fa_asset_delete_photo_file($oldPhoto1);
      if ($newPhoto2 && $oldPhoto2 && $newPhoto2 !== $oldPhoto2) fa_asset_delete_photo_file($oldPhoto2);
      fa_log($pdo,'UPDATE','ASSET',$id,['asset_photo_1'=>(bool)$photo1,'asset_photo_2'=>(bool)$photo2]);
      flash_set('success','Asset berhasil diupdate.');
    } else {
      $stmt=$pdo->prepare("INSERT INTO fa_assets(asset_code,asset_name,asset_category_code,quantity,unit_cost,category,office_code,dept_code,custodian_emp_id,vendor_name,purchase_ref,invoice_no,acq_date,acq_cost,salvage_value,tax_group_code,dep_method,status,notes,asset_photo_1,asset_photo_2) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([$asset_code,$asset_name,$asset_category_code,$quantity,$unit_cost,$category,$office_code,$dept_code,$custodian_emp_id,$vendor_name,$purchase_ref,$invoice_no,$acq_date,$acq_cost,$salvage,$tax_group,$method,$status,$notes,$photo1,$photo2]);
      $new_id=(int)$pdo->lastInsertId();
      fa_log($pdo,'CREATE','ASSET',$new_id,['asset_photo_1'=>(bool)$photo1,'asset_photo_2'=>(bool)$photo2]);
      flash_set('success','Asset berhasil ditambahkan.');
    }
    redirect_to('assets.php');
  }
}

if ($a === 'edit' && $id>0) {
  $row=$pdo->prepare("SELECT * FROM fa_assets WHERE id=?")->execute([$id]) or null;
}

fa_header('Asset Register');

if ($a==='new' || ($a==='edit' && $id>0)) {
  $asset = null;
  if ($a==='edit') {
    $stmt=$pdo->prepare("SELECT * FROM fa_assets WHERE id=?");
    $stmt->execute([$id]);
    $asset=$stmt->fetch();
    if(!$asset){ flash_set('danger','Asset tidak ditemukan.'); redirect_to('assets.php');}
  }
  ?>
  <div class="card bg-white p-4">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_action" value="save_asset">
      <?= fa_csrf_input() ?>
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Asset Register Code</label>
          <input class="form-control" name="asset_code" value="<?= h($asset['asset_code'] ?? '') ?>" placeholder="Otomatis: BGR-0708-0001" readonly>
          <div class="form-text">Dibuat otomatis dari Office + Kode Kategori + nomor urut.</div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Kode Kategori Aset *</label>
          <?php $acc = $asset['asset_category_code'] ?? ''; ?>
          <select class="form-select" name="asset_category_code" id="asset_category_code" required>
            <option value="">-- pilih kategori --</option>
            <?php foreach($asset_categories as $code=>$label): ?>
              <option value="<?= h($code) ?>" <?= ($acc===$code?'selected':'') ?>><?= h($code.' - '.$label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Asset Name *</label>
          <input class="form-control" name="asset_name" value="<?= h($asset['asset_name'] ?? '') ?>" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <?php $st=$asset['status'] ?? 'ACTIVE'; ?>
            <?php foreach(['ACTIVE','INACTIVE','DISPOSED'] as $opt): ?>
              <option value="<?= h($opt) ?>" <?= ($st===$opt?'selected':'') ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Office</label>
          <?php if(!empty($master_offices)): ?>
            <select class="form-select" name="office_code" required>
              <option value="">-- pilih office --</option>
              <?= fa_build_options($master_offices, 'office_code', 'office_name', ($asset['office_code'] ?? '')) ?>
            </select>
            <div class="form-text">Sumber: master_office</div>
          <?php else: ?>
            <input class="form-control" name="office_code" value="<?= h($asset['office_code'] ?? '') ?>" placeholder="contoh: BOGOR / BEKASI">
            <div class="form-text text-warning">master_office belum ada / kosong — isi manual.</div>
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Departement</label>
          <?php if(!empty($master_depts)): ?>
            <select class="form-select" name="dept_code" required>
              <option value="">-- pilih departement --</option>
              <?= fa_build_options($master_depts, 'dept_code', 'dept_name', ($asset['dept_code'] ?? '')) ?>
            </select>
            <div class="form-text">Sumber: master_departements</div>
          <?php else: ?>
            <input class="form-control" name="dept_code" value="<?= h($asset['dept_code'] ?? '') ?>" placeholder="contoh: FIN / WQS">
            <div class="form-text text-warning">master_departements belum ada / kosong — isi manual.</div>
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Custodian (employee_id)</label>
          <input class="form-control" name="custodian_emp_id" value="<?= h($asset['custodian_emp_id'] ?? '') ?>" placeholder="ID employee">
        </div>

        <div class="col-md-3">
          <label class="form-label">Tanggal Perolehan</label>
          <input type="date" class="form-control" name="acq_date" value="<?= h($asset['acq_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Quantity *</label>
          <input type="number" min="1" step="1" class="form-control" id="quantity" name="quantity" value="<?= h($asset['quantity'] ?? 1) ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Harga / Unit *</label>
          <input type="number" min="0" step="0.01" class="form-control" id="unit_cost" name="unit_cost" value="<?= h($asset['unit_cost'] ?? ($asset['acq_cost'] ?? 0)) ?>" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Total Nilai</label>
          <input type="number" step="0.01" class="form-control" id="total_acq_cost" value="<?= h($asset['acq_cost'] ?? 0) ?>" readonly>
          <div class="form-text">Qty × Harga/Unit</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Nilai Residu</label>
          <input type="number" step="0.01" class="form-control" name="salvage_value" value="<?= h($asset['salvage_value'] ?? 0) ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Kelompok Fiskal</label>
          <select class="form-select" name="tax_group_code">
            <?php $tg=$asset['tax_group_code'] ?? 'G1'; ?>
            <?php foreach($groups as $code=>$g): ?>
              <option value="<?= h($code) ?>" <?= ($tg===$code?'selected':'') ?>><?= h($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted small mt-1">Kelompok 1–4: SL/DDB, Bangunan: SL.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Metode</label>
          <?php $m=$asset['dep_method'] ?? 'SL'; ?>
          <select class="form-select" name="dep_method">
            <option value="SL" <?= ($m==='SL'?'selected':'') ?>>Garis Lurus (SL)</option>
            <option value="DDB" <?= ($m==='DDB'?'selected':'') ?>>Saldo Menurun (DDB)</option>
          </select>
          <div class="text-muted small mt-1">Jika kelompok bangunan dipilih, sistem otomatis pakai SL.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Foto Aset 1</label>
          <input type="file" class="form-control" name="asset_photo_1" accept="image/jpeg,image/png,image/webp">
          <div class="form-text">JPG/PNG/WEBP, maksimal 5MB. Disimpan langsung ke uploads/assets/ dengan nama Register Asset, contoh BGR-0709-0007.jpg. Saat edit, kosongkan jika tidak ingin mengganti.</div>
          <?php $photo1Preview = fa_asset_resolved_photo($asset ?? [], 1); if ($photo1Preview): ?>
            <a href="<?= h($photo1Preview) ?>" target="_blank" class="d-inline-block mt-2">
              <img src="<?= h($photo1Preview) ?>" alt="Foto aset 1" style="max-width:180px;max-height:130px;object-fit:cover" class="img-thumbnail">
            </a>
          <?php endif; ?>
        </div>
        <div class="col-md-6">
          <label class="form-label">Foto Aset 2</label>
          <input type="file" class="form-control" name="asset_photo_2" accept="image/jpeg,image/png,image/webp">
          <div class="form-text">Foto kedua opsional. Agar dua file tidak bentrok, disimpan sebagai {REGISTER}_2.ext. Foto utama tetap persis {REGISTER}.ext.</div>
          <?php $photo2Preview = fa_asset_resolved_photo($asset ?? [], 2); if ($photo2Preview): ?>
            <a href="<?= h($photo2Preview) ?>" target="_blank" class="d-inline-block mt-2">
              <img src="<?= h($photo2Preview) ?>" alt="Foto aset 2" style="max-width:180px;max-height:130px;object-fit:cover" class="img-thumbnail">
            </a>
          <?php endif; ?>
        </div>

        <div class="col-12">
          <label class="form-label">Catatan</label>
          <textarea class="form-control" rows="3" name="notes"><?= h($asset['notes'] ?? '') ?></textarea>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary">Simpan</button>
          <a class="btn btn-outline-secondary" href="assets.php">Batal</a>
        </div>
      </div>
    </form>
  </div>
  <script>
  (function(){
    const q=document.getElementById('quantity'), u=document.getElementById('unit_cost'), t=document.getElementById('total_acq_cost');
    function calc(){ if(t) t.value=((parseFloat(q?.value||1)||1)*(parseFloat(u?.value||0)||0)).toFixed(2); }
    q?.addEventListener('input',calc); u?.addEventListener('input',calc); calc();
  })();
  </script>
  <?php
  fa_footer(); exit;
}

// LIST
$f_office = strtoupper(trim($_GET['office'] ?? ''));
$f_dept = strtoupper(trim($_GET['dept'] ?? ''));
$f_cat = trim($_GET['cat'] ?? '');
$f_status = strtoupper(trim($_GET['status'] ?? ''));
$f_q = trim($_GET['q'] ?? '');
$where = ['deleted_at IS NULL']; $params=[];
if ($f_office!=='') { $where[]='office_code=?'; $params[]=$f_office; }
if ($f_dept!=='') { $where[]='dept_code=?'; $params[]=$f_dept; }
if ($f_cat!=='') { $where[]='asset_category_code=?'; $params[]=$f_cat; }
if ($f_status!=='') { $where[]='status=?'; $params[]=$f_status; }
if ($f_q!=='') { $where[]='(asset_code LIKE ? OR asset_name LIKE ?)'; $params[]='%'.$f_q.'%'; $params[]='%'.$f_q.'%'; }
$stmt=$pdo->prepare('SELECT * FROM fa_assets WHERE '.implode(' AND ',$where).' ORDER BY id DESC');
$stmt->execute($params); $rows=$stmt->fetchAll();

$sumStmt=$pdo->prepare('SELECT COUNT(*) records, COALESCE(SUM(quantity),0) qty, COALESCE(SUM(acq_cost),0) value_total FROM fa_assets WHERE '.implode(' AND ',$where));
$sumStmt->execute($params); $summary=$sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['records'=>0,'qty'=>0,'value_total'=>0];
$officeSummary=$pdo->query("SELECT office_code,COUNT(*) records,COALESCE(SUM(quantity),0) qty,COALESCE(SUM(acq_cost),0) value_total FROM fa_assets WHERE deleted_at IS NULL AND status<>'DISPOSED' GROUP BY office_code ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
?>

<?php
// Data ringkas alur pembelian aset. Ditampilkan di halaman Asset Register agar ACT/PQP/FIN/ITC dapat kontrol satu alur.
$purchaseRows = [];
try {
  $purchaseRows = $pdo->query("SELECT * FROM fa_purchase_requests ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $purchaseRows = [];
}
?>
<div class="card bg-white p-3 mb-3" id="asset-purchase-flow">
  <div class="d-flex justify-content-between align-items-center">
    <div>
      <div class="h6 mb-0">Alur Pembelian Aset</div>
      <div class="text-muted small">Mgr ITC mengajukan aset → PQP membeli → FIN approve → ITC terima barang → ACT register ke Fixed Asset. Alur Asset Register lama tetap aman.</div>
    </div>
    <?php if (fa_asset_can_itc_request($fa_purchase_user)): ?>
      <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#frmAssetRequest">+ Pengajuan Aset ITC</button>
    <?php endif; ?>
  </div>

  <?php if (fa_asset_can_itc_request($fa_purchase_user)): ?>
  <div class="collapse mt-3" id="frmAssetRequest">
    <form method="post" class="border rounded p-3 bg-light">
      <input type="hidden" name="_action" value="asset_request_submit">
      <?= fa_csrf_input() ?>
      <div class="row g-2">
        <div class="col-md-3"><label class="form-label small">Nama Aset *</label><input class="form-control" name="asset_name" required></div>
        <div class="col-md-2"><label class="form-label small">Office *</label><select class="form-select" name="office_code" required><option value="">-- pilih --</option><?= fa_build_options($master_offices,'office_code','office_name','') ?></select></div>
        <div class="col-md-2"><label class="form-label small">Kategori</label><select class="form-select" name="asset_category_code"><option value="">-- pilih --</option><?php foreach($asset_categories as $c=>$l): ?><option value="<?=h($c)?>"><?=h($c.' - '.$l)?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label small">Qty</label><input type="number" min="1" class="form-control" name="qty" value="1"></div>
        <div class="col-md-2"><label class="form-label small">Estimasi Harga</label><input type="number" min="0" step="0.01" class="form-control" name="estimated_price"></div>
        <div class="col-md-2 d-grid align-items-end"><button class="btn btn-success mt-4">Kirim ke PQP</button></div>
        <div class="col-12"><label class="form-label small">Alasan Kebutuhan</label><textarea class="form-control" name="reason" rows="2" placeholder="Contoh: laptop untuk staff baru ITC / penggantian aset rusak"></textarea></div>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="table-responsive mt-3">
    <table class="table table-sm table-striped align-middle" id="tblAssetPurchaseFlow">
      <thead><tr><th>Kode</th><th>Aset</th><th>Office</th><th>Qty</th><th>Vendor/PQP</th><th>Nilai</th><th>Status</th><th>Aksi sesuai PIC</th></tr></thead>
      <tbody>
      <?php foreach($purchaseRows as $pr): $st=(string)$pr['status']; ?>
        <tr>
          <td><b><?=h($pr['request_code'])?></b><br><span class="text-muted small"><?=h($pr['request_date'])?></span></td>
          <td><?=h($pr['asset_name'])?><br><span class="text-muted small"><?=h($pr['reason'] ?? '')?></span></td>
          <td><?=h($pr['office_code'])?></td>
          <td><?=number_format((int)$pr['qty'])?></td>
          <td><?=h($pr['selected_vendor'] ?: '-')?><br><span class="text-muted small"><?=h($pr['purchase_ref'] ?: '')?></span></td>
          <td>Rp <?=number_format((float)($pr['approved_price'] ?: $pr['estimated_price']),0,',','.')?></td>
          <td><span class="badge bg-secondary"><?=h(fa_asset_purchase_status_label($st))?></span><?php if(!empty($pr['itc_receive_photo'])): ?><br><a class="small" target="_blank" href="<?=h($pr['itc_receive_photo'])?>">Lihat Foto ITC</a><?php endif; ?><?php if(!empty($pr['itc_receive_match_status'])): ?><br><span class="small <?= $pr['itc_receive_match_status']==='MATCH' ? 'text-success' : 'text-danger' ?>"><?= $pr['itc_receive_match_status']==='MATCH' ? 'Barang sesuai' : 'Barang tidak sesuai' ?></span><?php endif; ?><?php if((int)($pr['registered_asset_id'] ?? 0)>0): ?><br><span class="text-success small">Asset ID: <?=number_format((int)$pr['registered_asset_id'])?></span><?php endif; ?></td>
          <td style="min-width:320px">
            <?php if (fa_asset_can_pqp($fa_purchase_user) && in_array($st, ['SUBMITTED_TO_PQP','PQP_REVIEW','PQP_QUOTATION'], true)): ?>
              <form method="post" class="row g-1 mb-1">
                <input type="hidden" name="_action" value="asset_pqp_save"><input type="hidden" name="request_id" value="<?= (int)$pr['id'] ?>"><?= fa_csrf_input() ?>
                <div class="col-4"><input class="form-control form-control-sm" name="selected_vendor" placeholder="vendor" value="<?=h($pr['selected_vendor'])?>"></div>
                <div class="col-3"><input class="form-control form-control-sm" name="approved_price" type="number" step="0.01" placeholder="nilai" value="<?=h($pr['approved_price'])?>"></div>
                <div class="col-3"><input class="form-control form-control-sm" name="purchase_ref" placeholder="ref PO" value="<?=h($pr['purchase_ref'])?>"></div>
                <div class="col-2"><button name="send_to_fin" value="1" class="btn btn-sm btn-primary">Ke FIN</button></div>
                <div class="col-12"><input class="form-control form-control-sm" name="pqp_note" placeholder="catatan kualitas / minimal 3 pilihan vendor"></div>
              </form>
            <?php endif; ?>
            <?php if (fa_asset_can_fin($fa_purchase_user) && $st === 'WAIT_FIN_APPROVAL'): ?>
              <form method="post" class="d-flex gap-1 mb-1">
                <input type="hidden" name="_action" value="asset_fin_decision"><input type="hidden" name="request_id" value="<?= (int)$pr['id'] ?>"><?= fa_csrf_input() ?>
                <input class="form-control form-control-sm" name="fin_note" placeholder="catatan FIN">
                <button name="decision" value="approve" class="btn btn-sm btn-success">Approve</button>
                <button name="decision" value="reject" class="btn btn-sm btn-danger">Reject</button>
              </form>
            <?php endif; ?>
            <?php if (fa_asset_can_pqp($fa_purchase_user) && $st === 'FIN_APPROVED'): ?>
              <form method="post" class="d-flex gap-1 mb-1">
                <input type="hidden" name="_action" value="asset_pqp_purchased"><input type="hidden" name="request_id" value="<?= (int)$pr['id'] ?>"><?= fa_csrf_input() ?>
                <input class="form-control form-control-sm" name="invoice_no" placeholder="invoice no">
                <input class="form-control form-control-sm" name="purchase_ref" placeholder="ref pembelian" value="<?=h($pr['purchase_ref'])?>">
                <button class="btn btn-sm btn-primary">Sudah Dibeli</button>
              </form>
            <?php endif; ?>
            <?php if (fa_asset_can_itc_request($fa_purchase_user) && in_array($st, ['PURCHASED','FIN_APPROVED'], true)): ?>
              <form method="post" enctype="multipart/form-data" class="row g-1 mb-1 align-items-center">
                <input type="hidden" name="_action" value="asset_itc_receive"><input type="hidden" name="request_id" value="<?= (int)$pr['id'] ?>"><?= fa_csrf_input() ?>
                <div class="col-md-3"><select class="form-select form-select-sm" name="itc_receive_match_status" required><option value="MATCH">Barang sesuai</option><option value="NOT_MATCH">Barang tidak sesuai</option></select></div>
                <div class="col-md-4"><input class="form-control form-control-sm" type="file" name="itc_receive_photo" accept="image/jpeg,image/png,image/webp" required></div>
                <div class="col-md-3"><input class="form-control form-control-sm" name="receive_note" placeholder="serial/lokasi/kondisi diterima"></div>
                <div class="col-md-2 d-grid"><button class="btn btn-sm btn-warning">Terima ITC</button></div>
              </form>
            <?php endif; ?>
            <?php if (fa_asset_can_act($fa_purchase_user) && $st === 'RECEIVED_BY_ITC'): ?>
              <form method="post" class="d-flex gap-1 mb-1" onsubmit="return confirm('Finalisasi aset ini ke Fixed Asset Register?')">
                <input type="hidden" name="_action" value="asset_act_register"><input type="hidden" name="request_id" value="<?= (int)$pr['id'] ?>"><?= fa_csrf_input() ?>
                <input class="form-control form-control-sm" name="act_note" placeholder="catatan ACT">
                <button class="btn btn-sm btn-success">Register Asset</button>
              </form>
            <?php endif; ?>
            <?php if (!in_array($st, ['SUBMITTED_TO_PQP','PQP_REVIEW','PQP_QUOTATION','WAIT_FIN_APPROVAL','FIN_APPROVED','PURCHASED','RECEIVED_BY_ITC','RECEIVED_MISMATCH_ITC'], true)): ?>
              <span class="text-muted small">Tidak ada aksi. Status final/menunggu tahap lain.</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(empty($purchaseRows)): ?><tr><td colspan="8" class="text-muted text-center">Belum ada pengajuan pembelian aset.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>initDT('#tblAssetPurchaseFlow');</script>

<div class="card bg-white p-3">
  <div class="d-flex justify-content-between align-items-center">
    <div>
      <div class="h6 mb-0">Daftar Asset</div>
      <div class="text-muted small">Klik Edit untuk update. Foto Fixed Asset hanya dari uploads/assets/ dan dikaitkan berdasarkan Register Asset. Foto utama bernama persis REGISTER.ext (contoh BGR-0709-0007.jpg), tanpa subfolder dan tanpa _front/_back. Foto kedua opsional memakai REGISTER_2.ext. Tidak membaca uploads/products/. Delete = soft delete.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary" href="assets.php?a=new">+ Asset Baru</a>
      <a class="btn btn-outline-primary" href="assets.php#asset-purchase-flow">Alur Pembelian Aset</a>
      <?php if (function_exists('rbac_is_privileged_session') && rbac_is_privileged_session()): ?>
      <form method="post" class="d-inline" onsubmit="return confirm('Seragamkan semua kode aset lama menjadi OFFICE-KATEGORI-NNNN? Kode lama akan disimpan sebagai legacy_asset_code dan nilai aset tidak berubah.');">
        <input type="hidden" name="_action" value="normalize_legacy_codes">
        <?= fa_csrf_input() ?>
        <button class="btn btn-outline-warning" type="submit">Seragamkan Kode Lama</button>
      </form>
      <?php endif; ?>
      <a class="btn btn-outline-secondary" href="assets.php?a=template">Template CSV</a>
      <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#modalImport">Import CSV</button>
      <a class="btn btn-outline-secondary" href="assets.php?a=seed&_csrf=<?= h($csrf) ?>" onclick="return confirm(\'Seed sample assets? (aman diulang)\')">Seed</a>
    </div>
  </div>
  <hr>
  <form method="get" class="row g-2 mb-3 align-items-end">
    <div class="col-md-2"><label class="form-label small">Office</label><select class="form-select" name="office"><option value="">Semua Office</option><?= fa_build_options($master_offices,'office_code','office_name',$f_office) ?></select></div>
    <div class="col-md-2"><label class="form-label small">Departemen</label><select class="form-select" name="dept"><option value="">Semua Dept</option><?= fa_build_options($master_depts,'dept_code','dept_name',$f_dept) ?></select></div>
    <div class="col-md-2"><label class="form-label small">Kategori</label><select class="form-select" name="cat"><option value="">Semua Kategori</option><?php foreach($asset_categories as $c=>$l): ?><option value="<?=h($c)?>" <?=($f_cat===$c?'selected':'')?>><?=h($c.' - '.$l)?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small">Status</label><select class="form-select" name="status"><option value="">Semua Status</option><?php foreach(['ACTIVE','INACTIVE','DISPOSED'] as $x): ?><option value="<?=$x?>" <?=($f_status===$x?'selected':'')?>><?=$x?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small">Cari</label><input class="form-control" name="q" value="<?=h($f_q)?>" placeholder="kode / nama aset"></div>
    <div class="col-md-1 d-grid"><button class="btn btn-outline-primary">Filter</button></div>
  </form>
  <div class="row g-2 mb-3">
    <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Record Aset</div><b><?=number_format((int)$summary['records'])?></b></div></div>
    <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Total Unit</div><b><?=number_format((int)$summary['qty'])?></b></div></div>
    <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Total Nilai Perolehan</div><b>Rp <?=number_format((float)$summary['value_total'],0,',','.')?></b></div></div>
  </div>
  <form method="post">
<input type="hidden" name="_action" value="bulk_assets">
<?= fa_csrf_input() ?>
<div class="table-responsive">
    <table class="table table-sm table-striped" id="tblAssets">
      <thead>
        <tr>
          <th style="width:30px"><input type="checkbox" id="chkAll"></th><th>ID</th><th>Register</th><th>Foto</th><th>Kategori</th><th>Name</th><th>Office</th><th>Dept</th><th>Qty</th><th>Harga/Unit</th><th>Total Nilai</th><th>Group</th><th>Method</th><th>Acq Date</th><th>Status</th><th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td><td><?= h($r['id']) ?></td>
            <td><b><?= h($r['asset_code']) ?></b></td>
            <td class="text-nowrap">
              <?php $p1 = fa_asset_resolved_photo($r,1); $p2 = fa_asset_resolved_photo($r,2); ?>
              <?php foreach ([$p1,$p2] as $photoPath): if ($photoPath): ?>
                <a href="<?= h($photoPath) ?>" target="_blank" class="me-1" title="Lihat foto aset">
                  <img src="<?= h($photoPath) ?>" alt="Foto aset" style="width:42px;height:42px;object-fit:cover" class="rounded border">
                </a>
              <?php endif; endforeach; ?>
              <?php if (!$p1 && !$p2): ?><span class="text-muted small">-</span><?php endif; ?>
            </td>
            <td><?= h(($r['asset_category_code'] ?? '').' '.($asset_categories[$r['asset_category_code'] ?? ''] ?? '')) ?></td>
            <td><?= h($r['asset_name']) ?></td>
            <td><?= h($r['office_code']) ?></td>
            <td><?= h($r['dept_code']) ?></td>
            <td><?= number_format((int)($r['quantity'] ?? 1)) ?></td>
            <td>Rp <?= number_format((float)($r['unit_cost'] ?? $r['acq_cost']),0,',','.') ?></td>
            <td>Rp <?= number_format((float)$r['acq_cost'],0,',','.') ?></td>
            <td><?= h($r['tax_group_code']) ?></td>
            <td><?= h($r['dep_method']) ?></td>
            <td><?= h($r['acq_date']) ?></td>
            <td><?= h($r['status']) ?></td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="assets.php?a=edit&id=<?= (int)$r['id'] ?>">Edit</a>
              <a class="btn btn-sm btn-outline-danger" onclick="return confirm('Soft delete asset ini?')" href="assets.php?a=delete&id=<?= (int)$r['id'] ?>&_csrf=<?= h($csrf) ?>">Delete</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="row g-2 mt-2 align-items-end">
  <div class="col-md-4">
    <label class="form-label small text-muted">Bulk Action</label>
    <select class="form-select" name="bulk_action" required>
      <option value="">-- pilih --</option>
      <option value="set_status_active">Set status ACTIVE</option>
      <option value="set_status_disposed">Set status DISPOSED</option>
      <option value="set_office">Set office_code</option>
      <option value="set_dept">Set dept_code</option>
      <option value="soft_delete">Soft delete</option>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label small text-muted">office_code (opsional)</label>
    <input class="form-control" name="bulk_office" placeholder="BOGOR">
  </div>
  <div class="col-md-3">
    <label class="form-label small text-muted">dept_code (opsional)</label>
    <input class="form-control" name="bulk_dept" placeholder="FIN / ITC">
  </div>
  <div class="col-md-2 d-grid">
    <button class="btn btn-outline-primary" type="submit" onclick="return confirm('Jalankan bulk action?')">Apply</button>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    const all = document.getElementById('chkAll');
    if(all){
      all.addEventListener('change', function(){
        document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = all.checked);
      });
    }
  });
</script>
</form>
</div>
<div class="card fa-dark-card p-3 mt-3">
  <div class="h6 mb-3">Ringkasan Nilai Aset per Office (non-DISPOSED)</div>
  <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Office</th><th>Record</th><th>Total Unit</th><th>Total Nilai Perolehan</th></tr></thead><tbody>
  <?php foreach($officeSummary as $os): ?><tr><td><b><?=h($os['office_code'])?></b></td><td><?=number_format((int)$os['records'])?></td><td><?=number_format((int)$os['qty'])?></td><td>Rp <?=number_format((float)$os['value_total'],0,',','.')?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<script>initDT('#tblAssets');</script>
<!-- Import CSV Modal -->
<div class="modal fade" id="modalImport" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_action" value="import_assets">
        <?= fa_csrf_input() ?>
        <div class="modal-header">
          <h5 class="modal-title">Import Asset (CSV)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2 text-muted small">
            Gunakan <a href="assets.php?a=template">template CSV</a>. Kolom wajib: <b>asset_name</b>, <b>office_code</b>, <b>asset_category_code</b>. Asset code boleh kosong agar dibuat otomatis.
          </div>
          <input class="form-control" type="file" name="csv_file" accept=".csv" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button class="btn btn-primary" type="submit">Import</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php fa_footer(); ?>
