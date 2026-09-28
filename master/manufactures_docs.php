<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// master/manufactures_docs.php
// FINAL: Docs & Catalog untuk Manufacture
// - 2 kategori dokumen: HRL Registrasi (AKL/AKD/NIE) dan Internal Wajib (Umum Kantor + Catalog)
// - Upload + List dari filesystem (lebih tahan lama)
// - Audit log upload/delete (system_audit_logs)
// - Guard: mengikuti master/_guard.php (login/role)

require_once __DIR__ . '/auth.php';
require_login();

// Enterprise role gate: allow PQP/HRL/ITC (atau ADMIN) untuk halaman Manufactures Docs
if (file_exists(__DIR__ . '/../_shared/enterprise_guard.php')) {
    require_once __DIR__ . '/../_shared/enterprise_guard.php';
    if (function_exists('eg_can_access_manufactures_docs') && !eg_can_access_manufactures_docs()) {
        http_response_code(403);
        echo "<h3>Akses ditolak</h3><p>Module Manufactures Docs hanya untuk dept PQP/HRL/LEGAL/ITC atau ADMIN.</p>";
        exit;
    }
}



// --------------------------------------------------------
// DB connection (samakan dengan master modules)
// --------------------------------------------------------
require_once __DIR__ . '/_audit_master.php';
// --- DB (centralized) ---
$pdo = db_pdo();

// Optional: also log to erp_audit_log (if available)
if (file_exists(__DIR__ . '/../_shared/erp_audit.php')) {
    require_once __DIR__ . '/../_shared/erp_audit.php';
    if (function_exists('erp_audit_ensure')) {
        erp_audit_ensure($pdo);
    }
}
function mm_audit2(PDO $pdo, string $action, ?string $entityKey, array $payload = []): void {
    if (function_exists('erp_audit')) {
        erp_audit($pdo, 'MANUFACTURES_DOCS', $entityKey, $action, $payload);
    }
}

// --------------------------------------------------------
// HELPERS
// --------------------------------------------------------
if (!function_exists('h')) {
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
}


function mm_current_user(): array {
    return [
        'user_id'    => $_SESSION['user_id']    ?? null,
        'username'   => $_SESSION['username']   ?? '',
        'full_name'  => $_SESSION['full_name']  ?? '',
        'role'       => $_SESSION['role']       ?? '',
        'level'      => $_SESSION['level']      ?? '',
        'department' => $_SESSION['department'] ?? '',
    ];
}

function mm_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function mm_user_agent(): string {
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function mm_log_pdo_error(PDOException $e, string $sql, array $params): void {
    $namedCount = preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $sql, $m) ?: 0;
    $positionalCount = substr_count($sql, '?');
    $paramCount = count($params);
    $sqlOneLine = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
    $msg = '[manufactures_docs] PDO error'
        . ' code=' . (string)$e->getCode()
        . ' message=' . $e->getMessage()
        . ' placeholders_named=' . $namedCount
        . ' placeholders_positional=' . $positionalCount
        . ' params_count=' . $paramCount
        . ' sql="' . $sqlOneLine . '"';
    @error_log($msg);
}

function ensure_audit_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_audit_logs` (
          `id` int NOT NULL AUTO_INCREMENT,
          `module` varchar(100) NOT NULL,
          `action` varchar(50) NOT NULL,
          `record_table` varchar(100) DEFAULT NULL,
          `record_id` int DEFAULT NULL,
          `record_code` varchar(100) DEFAULT NULL,
          `description` text DEFAULT NULL,
          `details` longtext DEFAULT NULL,
          `user_id` int DEFAULT NULL,
          `username` varchar(100) DEFAULT NULL,
          `role` varchar(50) DEFAULT NULL,
          `level` varchar(50) DEFAULT NULL,
          `ip` varchar(45) DEFAULT NULL,
          `user_agent` varchar(255) DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_module_action` (`module`,`action`),
          KEY `idx_record` (`record_table`,`record_id`),
          KEY `idx_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function audit(PDO $pdo, string $action, ?int $record_id, ?string $record_code, string $description, array $details = []): void {
    ensure_audit_table($pdo);
    $u = mm_current_user();
    $stmt = $pdo->prepare("
        INSERT INTO system_audit_logs
        (module, action, record_table, record_id, record_code, description, details,
         user_id, username, role, level, ip, user_agent, created_at)
        VALUES
        (:module, :action, :rt, :rid, :rcode, :descr, :details,
         :uid, :uname, :role, :level, :ip, :ua, NOW())
    ");
    $stmt->execute([
        ':module'  => 'manufactures_docs',
        ':action'  => $action,
        ':rt'      => 'master_manufactures',
        ':rid'     => $record_id,
        ':rcode'   => $record_code,
        ':descr'   => $description,
        ':details' => !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ':uid'     => $u['user_id'],
        ':uname'   => $u['username'],
        ':role'    => $u['role'],
        ':level'   => $u['level'],
        ':ip'      => mm_client_ip(),
        ':ua'      => substr(mm_user_agent(), 0, 250),
    ]);
}

function safe_code(string $code): string {
    $code = trim($code);
    $code = preg_replace('/\s+/', '-', $code);
    $code = preg_replace('/[^A-Za-z0-9\-_]/', '', $code);
    return strtoupper($code);
}


// Dokumen pabrikan harus konsisten: manufacture_id + manufacture_code pada file_rel.
function mm_manu_doc_matches(array $doc, string $canonicalCode): bool {
    $code = safe_code($canonicalCode);
    $rel = str_replace('\\', '/', ltrim((string)($doc['file_rel'] ?? ''), '/'));
    if ($code === '' || $rel === '') return false;
    $prefix = 'master/uploads/manufactures/' . $code . '/hrl/manufactures_docs/';
    return stripos($rel, $prefix) === 0;
}

if (!function_exists('safe_filename')) {
    function safe_filename(string $name): string {
        $name = preg_replace('/[^\w\-. ]+/u', '_', $name);
        $name = preg_replace('/\s+/', '_', $name);
        $name = trim($name, '._');
        if ($name === '') $name = 'file';
        return $name;
    }
}

function list_files(string $dir, string $relBase): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $items = scandir($dir);
    if (!$items) return $out;
    foreach ($items as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($path)) continue;
        $out[] = [
            'file' => $f,
            'size' => filesize($path),
            'mtime' => filemtime($path),
            'ext' => strtolower(pathinfo($f, PATHINFO_EXTENSION)),
            'url' => $relBase . '/' . rawurlencode($f),
        ];
    }
    usort($out, fn($a,$b) => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));
    return $out;
}



// --------------------------------------------------------
// ZIP helpers (auto extract & auto-distribute by filename)
// --------------------------------------------------------
function mm_norm_for_match(string $s): string {
    $s = strtolower($s);
    $s = str_replace(['\\', '/', '-', '_'], ' ', $s);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function mm_classify_doc_bucket(string $nameOrPath, string $defaultBucket): string {
    $defaultBucket = ($defaultBucket === 'hrl') ? 'hrl' : 'internal';
    $raw = strtolower((string)$nameOrPath);

    // 1) hint by folder path in ZIP (or prefix)
    // e.g. "hrl/PKS_....pdf", "internal/catalog.pdf"
    if (preg_match('#(^|/)(hrl|reg|registrasi)(/|_)#', $raw)) return 'hrl';
    if (preg_match('#(^|/)(internal|int|umum|office)(/|_)#', $raw)) return 'internal';

    // 2) hint by keywords in filename
    $norm = mm_norm_for_match(basename($raw));

    $hrlKeys = [
        'pks',
        'loa',
        'kbri',
        'legalisasi',
        'notaris',
        'notary',
        'akl',
        'akd',
        'nie',
        'regalkes',
        'registrasi',
        'registration',
        'ce',
        'cfs',
        'coa',
        'msds',
        'iso',
        'ifu',
        'instructions for use',
        'instruction for use',
        'instruction',
        'user manual',
        'manual',
        'label',
        'labelling',
        'labeling',
        'kemasan',
        'packaging',
        'clinical',
        'klinis',
        'bukti klinis',
        'analisa resiko',
        'analisa risiko',
        'analisis resiko',
        'analisis risiko',
        'risk analysis',
        'spesifikasi',
        'specification',
        'spec',
        'kinerja',
        'performance',
        'fungsi',
        'functional',
        'steril',
        'validasi',
        'validation',
        'aksesoris',
        'aksesori',
        'accessory',
        'accessories',
        'dossier',
        'dosier'
    ];
    $intKeys = [
        'cdakb',
        'cda kb',
        'cda',
        'izin penyalur',
        'perizinan berusaha',
        'perizinan',
        'izin',
        'berbasis risiko',
        'berbasis resiko',
        'risk based',
        'rba',
        'pb umku',
        'umku',
        'oss',
        'sertifikat merk',
        'sertifikat merek',
        'sertifikat_merk',
        'sertifikat_merek',
        'merk',
        'merek',
        'surat kuasa',
        'kuasa',
        'power of attorney',
        'penanganan komplain',
        'komplain',
        'complain',
        'complaint',
        'oem',
        'proposal oem',
        'catalog',
        'katalog',
        'brosur',
        'brochure',
        'company profile',
        'profile',
        'pricelist',
        'price list',
        'marketing',
        'internal',
        'umum',
        'office'
    ];

    $isHrl = false;
    foreach ($hrlKeys as $k) {
        if ($k !== '' && strpos($norm, $k) !== false) { $isHrl = true; break; }
    }
    $isInt = false;
    foreach ($intKeys as $k) {
        if ($k !== '' && strpos($norm, $k) !== false) { $isInt = true; break; }
    }

    if ($isHrl && !$isInt) return 'hrl';
    if ($isInt && !$isHrl) return 'internal';

    // if both, prioritize HRL for critical keywords
    if ($isHrl && $isInt) {
        if (strpos($norm, 'pks') !== false || strpos($norm, 'loa') !== false || strpos($norm, 'akl') !== false || strpos($norm, 'akd') !== false || strpos($norm, 'nie') !== false) {
            return 'hrl';
        }
    }

    return $defaultBucket;
}

function mm_import_zip_docs(PDO $pdo, string $tmpZip, string $origZipName, string $defaultBucket, string $hrlDir, string $intDir, int $recordId, string $recordCode, array $allowedEntryExt, int $maxBytes): array {
    $res = ['ok'=>0,'hrl'=>0,'internal'=>0,'ignored'=>0,'errors'=>[]];

    if (!class_exists('ZipArchive')) {
        $res['errors'][] = "ZipArchive tidak tersedia di PHP server ini.";
        return $res;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        $res['errors'][] = "Tidak bisa membuka ZIP.";
        return $res;
    }

    $maxFiles = 200;
    $maxTotalBytes = 200 * 1024 * 1024; // 200MB total extracted (anti zip-bomb sederhana)
    $totalBytes = 0;

    $num = (int)($zip->numFiles ?? 0);
    for ($i=0; $i<$num; $i++) {
        if ($i >= $maxFiles) { $res['ignored'] += max(0, $num - $maxFiles); break; }

        $stat = $zip->statIndex($i);
        $name = (string)($stat['name'] ?? '');
        if ($name === '') { $res['ignored']++; continue; }

        // skip folder
        if (substr($name, -1) === '/') { $res['ignored']++; continue; }

        // prevent zip-slip (../) or absolute path
        if (strpos($name, "\0") !== false) { $res['ignored']++; continue; }
        if (preg_match('#(^|/)\.\.(/|$)#', $name)) { $res['ignored']++; continue; }
        if (strpos($name, ':') !== false) { $res['ignored']++; continue; } // prevent windows drive tricks
        if (strpos($name, '//') !== false) { /* ok, but ignore */ }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedEntryExt, true)) { $res['ignored']++; continue; }

        $size = (int)($stat['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) { $res['ignored']++; continue; }

        $totalBytes += $size;
        if ($totalBytes > $maxTotalBytes) {
            $res['errors'][] = "ZIP terlalu besar (total extracted > 200MB).";
            break;
        }

        $bucket = mm_classify_doc_bucket($name, $defaultBucket);
        // Role gate: jika user tidak punya izin upload ke bucket HRL, paksa simpan ke INTERNAL
        if ($bucket === 'hrl' && function_exists('eg_can_upload_manufacture_bucket') && !eg_can_upload_manufacture_bucket('hrl')) {
            $bucket = 'internal';
        }
        $dir = ($bucket === 'hrl') ? $hrlDir : $intDir;

        $safeBase = safe_filename(pathinfo(basename($name), PATHINFO_FILENAME));
        if ($safeBase === '') $safeBase = 'file';

        // Preserve filename from ZIP (lebih mudah dicari), tapi tetap aman & tidak overwrite.
        $newName = $safeBase . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $newName;

        $dup = 1;
        while (is_file($dest)) {
            $newName = $safeBase . '_' . $dup . '.' . $ext;
            $dest = $dir . DIRECTORY_SEPARATOR . $newName;
            $dup++;
            if ($dup > 999) {
                // fallback: timestamp+hash untuk kasus ekstrem
                $newName = date('Ymd_His') . '_' . substr(md5($name . microtime(true)), 0, 8) . '_' . $safeBase . '.' . $ext;
                $dest = $dir . DIRECTORY_SEPARATOR . $newName;
                break;
            }
        }

        $stream = $zip->getStream($name);
        if (!$stream) { $res['ignored']++; continue; }
        $out = @fopen($dest, 'wb');
        if (!$out) { @fclose($stream); $res['ignored']++; continue; }

        @stream_copy_to_stream($stream, $out);
        @fclose($stream);
        @fclose($out);

        if (!is_file($dest) || (int)filesize($dest) <= 0) {
            @unlink($dest);
            $res['ignored']++;
            continue;
        }

        $res['ok']++;
        if ($bucket === 'hrl') $res['hrl']++; else $res['internal']++;

        if (function_exists('master_audit')) {
            master_audit($pdo, 'manufactures_docs', 'master_manufactures', 'UPLOAD_DOC_ZIP', $recordId, $recordCode, "Upload doc (ZIP → {$bucket})", ['zip_original' => $origZipName, 'zip_entry' => $name, 'stored' => $newName, 'size' => (int)filesize($dest), 'ext' => $ext, 'type' => $bucket]);
        }
    }

    $zip->close();

    if ($res['ok'] > 0 && function_exists('master_audit')) {
        master_audit($pdo, 'manufactures_docs', 'master_manufactures', 'UPLOAD_ZIP', $recordId, $recordCode, "Import ZIP", ['zip_original' => $origZipName, 'imported_total' => $res['ok'], 'imported_hrl' => $res['hrl'], 'imported_internal' => $res['internal'], 'ignored' => $res['ignored'], 'errors' => $res['errors']]);
    }

    return $res;
}

// --------------------------------------------------------
// Load manufacture by code
// --------------------------------------------------------
$code = safe_code((string)($_GET['code'] ?? ''));
if ($code === '') {
    http_response_code(400);
    echo "<h3>Parameter code wajib</h3>";
    exit;
}

$row = null;
$sqlLoadManufacture = "SELECT * FROM master_manufactures WHERE manufacture_code = :c1 OR manufactures_code = :c2 LIMIT 1";
$loadParams = [':c1' => $code, ':c2' => $code];
try {
    $stmt = $pdo->prepare($sqlLoadManufacture);
    $stmt->execute($loadParams);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    mm_log_pdo_error($e, $sqlLoadManufacture, $loadParams);
    http_response_code(500);
    echo "<h3>Terjadi gangguan saat memuat data manufacture. Silakan coba lagi.</h3>";
    exit;
}

if (!$row) {
    http_response_code(404);
    echo "<h3>Manufacture dengan code " . h($code) . " tidak ditemukan.</h3>";
    exit;
}

$recordId = (int)($row['id'] ?? 0);
$canonicalCode = safe_code((string)($row['manufacture_code'] ?? $row['manufactures_code'] ?? $code));
$displayName = (string)($row['manufacture_name'] ?? $row['manufactures_name'] ?? $canonicalCode);

// --------------------------------------------------------
// Filesystem folders
// --------------------------------------------------------
$baseDir = __DIR__ . '/uploads/manufactures/' . $canonicalCode;
$hrlDir = $baseDir . '/hrl';
$intDir = $baseDir . '/internal';

// create folders if not exist
if (!is_dir($hrlDir)) @mkdir($hrlDir, 0775, true);
if (!is_dir($intDir)) @mkdir($intDir, 0775, true);

$baseUrl = 'uploads/manufactures/' . rawurlencode($canonicalCode);
$hrlUrl = $baseUrl . '/hrl';
$intUrl = $baseUrl . '/internal';

// --------------------------------------------------------
// Handle delete file
// --------------------------------------------------------
if (isset($_GET['del']) && isset($_GET['type'])) {

// Role gate: delete dokumen hanya HRL/ITC Manager (atau ADMIN)
if (function_exists('eg_can_delete_manufacture_doc') && !eg_can_delete_manufacture_doc()) {
    http_response_code(403);
    if (function_exists('master_audit')) {
        master_audit($pdo, 'manufactures_docs', 'master_manufactures', 'DENY_DELETE_DOC', $recordId, $canonicalCode, "DENY delete doc", ['dept' => function_exists('eg_dept') ? eg_dept() : null]);
    }
    mm_audit2($pdo, 'deny_delete_doc', 'MNF#' . $canonicalCode, [
        'dept' => function_exists('eg_dept') ? eg_dept() : null
    ]);
    echo "<h3>Akses ditolak</h3><p>Delete dokumen hanya untuk HRL/Legal Manager atau Admin.</p>";
    exit;
}
    $type = (string)$_GET['type'];
    $file = (string)$_GET['del'];
    $type = ($type === 'hrl') ? 'hrl' : 'internal';

    $dir = ($type === 'hrl') ? $hrlDir : $intDir;
    $target = realpath($dir . DIRECTORY_SEPARATOR . $file);
    $dirReal = realpath($dir);

    if ($dirReal && $target && strpos($target, $dirReal) === 0 && is_file($target)) {
        @unlink($target);
        if (function_exists('master_audit')) {
            master_audit($pdo, 'manufactures_docs', 'master_manufactures', 'DELETE_DOC', $recordId, $canonicalCode, "Delete doc ({$type})", ['file' => $file]);
        }
        rmi_redirect('manufactures_docs.php?code=' . urlencode($canonicalCode));
    }
}

// --------------------------------------------------------
// Handle download from master_manufactures_docs (PKS/LOA/LOA_KBRI)
// --------------------------------------------------------
if (isset($_GET['dl_md'])) {
    $mdId = (int)($_GET['dl_md'] ?? 0);
    if ($mdId > 0) {
        try {
            $st = $pdo->prepare("SELECT * FROM master_manufactures_docs WHERE id=? AND manufacture_id=? LIMIT 1");
            $st->execute([$mdId, $recordId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['file_rel']) && mm_manu_doc_matches($row, $canonicalCode)) {
                $full = realpath(__DIR__ . '/../' . ltrim((string)$row['file_rel'], '/'));
                if ($full && is_file($full)) {
                    $fname = (string)($row['file_name'] ?? basename($full));
                    header('Content-Type: application/octet-stream');
                    header('Content-Length: ' . filesize($full));
                    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fname) . '"');
                    header('X-Content-Type-Options: nosniff');
                    readfile($full);
                    exit;
                }
            }
        } catch (Throwable $e) {
            /* fall through to 404 */
        }
    }
    http_response_code(404);
    echo 'File not found.';
    exit;
}

// --------------------------------------------------------
// Hapus relasi Dokumen Pabrikan (file fisik dipertahankan untuk recovery/audit)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pabrikan_doc'])) {
    require_post();
    verify_csrf();
    if (function_exists('eg_can_delete_manufacture_doc') && !eg_can_delete_manufacture_doc()) {
        http_response_code(403);
        echo '<h3>Akses ditolak</h3><p>Hapus dokumen pabrikan hanya untuk HRL/Legal Manager atau Admin.</p>';
        exit;
    }
    $mdId = (int)($_POST['md_id'] ?? 0);
    if ($mdId > 0) {
        $st = $pdo->prepare("SELECT * FROM master_manufactures_docs WHERE id=? AND manufacture_id=? LIMIT 1");
        $st->execute([$mdId, $recordId]);
        $md = $st->fetch(PDO::FETCH_ASSOC);
        if ($md && mm_manu_doc_matches($md, $canonicalCode)) {
            $pdo->prepare("DELETE FROM master_manufactures_docs WHERE id=? AND manufacture_id=?")->execute([$mdId, $recordId]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'manufactures_docs', 'master_manufactures', 'UNLINK_PABRIKAN_DOC', $recordId, $canonicalCode,
                    'Hapus relasi dokumen pabrikan (file fisik dipertahankan)',
                    ['doc_id'=>$mdId,'doc_type'=>$md['doc_type'] ?? '', 'file_name'=>$md['file_name'] ?? '', 'file_rel'=>$md['file_rel'] ?? '']);
            }
        }
    }
    rmi_redirect('manufactures_docs.php?code=' . urlencode($canonicalCode) . '&unlinked=1');
}

// --------------------------------------------------------
// Handle upload (support ZIP auto-extract & auto-distribute)
// --------------------------------------------------------
// --------------------------------------------------------
// Handle upload Dokumen Pabrikan: PKS / LOA / LOA_KBRI
// --------------------------------------------------------
$uploadErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_pabrikan'])) {
    require_post();
    verify_csrf();

    $docType = strtoupper(trim((string)($_POST['pabrikan_doc_type'] ?? '')));
    $allowedTypes = [
    'PKS',
    'LOA',
    'LOA_KBRI',
    'OSS_PB_UMKU',
    'REGALKES_REF'
];

    if (!in_array($docType, $allowedTypes, true)) {
        $uploadErr = 'Tipe dokumen pabrikan tidak valid.';
    } elseif (!isset($_FILES['pabrikan_file']) || ($_FILES['pabrikan_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $uploadErr = 'File PKS/LOA belum dipilih.';
    } else {
        $origName = (string)$_FILES['pabrikan_file']['name'];
        $tmpName  = (string)$_FILES['pabrikan_file']['tmp_name'];
        $size     = (int)$_FILES['pabrikan_file']['size'];

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowedExt = ['pdf','jpg','jpeg','png','webp','doc','docx'];

        if (!in_array($ext, $allowedExt, true)) {
            $uploadErr = 'Format file tidak valid. Gunakan PDF/JPG/PNG/DOC/DOCX.';
        } elseif ($size <= 0 || $size > 20 * 1024 * 1024) {
            $uploadErr = 'Ukuran file maksimal 20MB.';
        } else {
            $docDir = __DIR__ . '/uploads/manufactures/' . $canonicalCode . '/hrl/manufactures_docs';
            if (!is_dir($docDir)) {
                @mkdir($docDir, 0775, true);
            }

            $safeBase = safe_filename(pathinfo($origName, PATHINFO_FILENAME));
            $newName = date('Ymd_His') . '_' . strtolower($docType) . '_' . substr(md5($origName . microtime(true)), 0, 8) . '_' . $safeBase . '.' . $ext;

            $destFull = $docDir . DIRECTORY_SEPARATOR . $newName;

            // untuk download via dl_md, path harus relatif dari root project
            $fileRel = 'master/uploads/manufactures/' . $canonicalCode . '/hrl/manufactures_docs/' . $newName;

            if (function_exists('eg_can_upload_manufacture_bucket') && !eg_can_upload_manufacture_bucket('hrl')) {
                $uploadErr = 'Akses ditolak: tidak boleh upload Dokumen Pabrikan.';
            } elseif (@move_uploaded_file($tmpName, $destFull)) {
                $u = mm_current_user();
                try {
                    $pdo->beginTransaction();
                    // Satu dokumen aktif per tipe per manufacturer. Record lama dilepas setelah file baru aman tersimpan.
                    $oldSt = $pdo->prepare("SELECT id,file_name,file_rel FROM master_manufactures_docs WHERE manufacture_id=? AND doc_type=? ORDER BY uploaded_at DESC,id DESC");
                    $oldSt->execute([$recordId, $docType]);
                    $oldDocs = $oldSt->fetchAll(PDO::FETCH_ASSOC);

                    $st = $pdo->prepare("
                        INSERT INTO master_manufactures_docs
                        (manufacture_id, doc_type, file_name, file_rel, uploaded_at, uploaded_by)
                        VALUES
                        (:manufacture_id, :doc_type, :file_name, :file_rel, NOW(), :uploaded_by)
                    ");
                    $st->execute([
                        ':manufacture_id' => $recordId,
                        ':doc_type'       => $docType,
                        ':file_name'      => $origName,
                        ':file_rel'       => $fileRel,
                        ':uploaded_by'    => $u['username'] ?? '',
                    ]);
                    $newDocId = (int)$pdo->lastInsertId();
                    if (!empty($oldDocs)) {
                        $ids = array_values(array_filter(array_map(fn($x)=>(int)($x['id']??0), $oldDocs)));
                        if ($ids) {
                            $ph = implode(',', array_fill(0, count($ids), '?'));
                            $del = $pdo->prepare("DELETE FROM master_manufactures_docs WHERE manufacture_id=? AND id IN ($ph)");
                            $del->execute(array_merge([$recordId], $ids));
                        }
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    @unlink($destFull);
                    throw $e;
                }

                if (function_exists('master_audit')) {
                    master_audit($pdo, 'manufactures_docs', 'master_manufactures', 'UPLOAD_PABRIKAN_DOC', $recordId, $canonicalCode, "Upload {$docType}", [
                        'doc_type' => $docType,
                        'file' => $origName,
                        'stored' => $newName,
                        'replaced_doc_ids' => array_map(fn($x)=>(int)($x['id']??0), $oldDocs ?? [])
                    ]);
                }

                rmi_redirect('manufactures_docs.php?code=' . urlencode($canonicalCode) . '&ok=1');
            } else {
                $uploadErr = 'Gagal menyimpan file upload.';
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_docs'])) {
    require_post();
    verify_csrf();
    $type = (string)($_POST['doc_type'] ?? '');
    $type = ($type === 'hrl') ? 'hrl' : 'internal';


// Role gate: upload ke bucket HRL hanya HRL/ITC (atau ADMIN)
if (function_exists('eg_can_upload_manufacture_bucket') && !eg_can_upload_manufacture_bucket($type)) {
    $uploadErr = "Akses ditolak: kamu tidak boleh upload ke folder {$type}.";
    if (function_exists('master_audit')) {
        master_audit($pdo, 'manufactures_docs', 'master_manufactures', 'DENY_UPLOAD_DOC', $recordId, $canonicalCode, "DENY upload ({$type})", ['dept' => function_exists('eg_dept') ? eg_dept() : null, 'type' => $type]);
    }
    mm_audit2($pdo, 'deny_upload_doc', 'MNF#' . $canonicalCode, [
        'dept' => function_exists('eg_dept') ? eg_dept() : null,
        'type' => $type
    ]);
}

    if ($uploadErr !== '') {


        // stop (role gate)


    } elseif (!isset($_FILES['docs_files'])) {
        $uploadErr = 'File belum dipilih.';
    } else {
        $files = $_FILES['docs_files'];

        // Allowed ext for normal upload + ZIP upload (ZIP will be extracted)
        $allowedExt = ['pdf','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','webp','mp4','mov','zip','rar'];
        // Allowed file ext inside ZIP (we do NOT extract nested zip/rar)
        $allowedZipEntryExt = ['pdf','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','webp','mp4','mov'];

        $maxBytes = 50 * 1024 * 1024; // 50 MB / file (single upload OR per ZIP entry)

        $countOk = 0;
        $countHrl = 0;
        $countInt = 0;
        $countIgnored = 0;
        $zipErrors = [];

        $totalFiles = is_array($files['name'] ?? null) ? count($files['name']) : 0;

        for ($i=0; $i < $totalFiles; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if (($files['error'][$i] ?? 0) !== UPLOAD_ERR_OK) { $countIgnored++; continue; }

            $origName = (string)($files['name'][$i] ?? '');
            $tmpName  = (string)($files['tmp_name'][$i] ?? '');
            $size     = (int)($files['size'][$i] ?? 0);

            if ($origName === '' || $tmpName === '') { $countIgnored++; continue; }
            if ($size <= 0) { $countIgnored++; continue; }

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) { $countIgnored++; continue; }

            // ZIP: extract & auto distribute by name
            if ($ext === 'zip') {
                $res = mm_import_zip_docs($pdo, $tmpName, $origName, $type, $hrlDir, $intDir, $recordId, $canonicalCode, $allowedZipEntryExt, $maxBytes);
                $countOk += (int)($res['ok'] ?? 0);
                $countHrl += (int)($res['hrl'] ?? 0);
                $countInt += (int)($res['internal'] ?? 0);
                $countIgnored += (int)($res['ignored'] ?? 0);
                if (!empty($res['errors'])) $zipErrors = array_merge($zipErrors, (array)$res['errors']);
                continue;
            }

            // Normal file upload (RAR will be stored as file as-is)
            if ($size > $maxBytes) { $countIgnored++; continue; }

            // Auto route based on filename keywords (fallback: selected tab)
            $bucket = mm_classify_doc_bucket($origName, $type);
            $dir = ($bucket === 'hrl') ? $hrlDir : $intDir;

            $safe = safe_filename(pathinfo($origName, PATHINFO_FILENAME));
            $newName = date('Ymd_His') . '_' . substr(md5($origName . microtime(true)), 0, 8) . '_' . $safe . '.' . $ext;

            $dest = $dir . DIRECTORY_SEPARATOR . $newName;
            if (@move_uploaded_file($tmpName, $dest)) {
                $countOk++;
                if ($bucket === 'hrl') $countHrl++; else $countInt++;

                if (function_exists('master_audit')) {
                    master_audit($pdo, 'manufactures_docs', 'master_manufactures', 'UPLOAD_DOC', $recordId, $canonicalCode, "Upload doc ({$bucket})", ['original' => $origName, 'stored' => $newName, 'size' => $size, 'ext' => $ext, 'type' => $bucket]);
                }
            } else {
                $countIgnored++;
            }
        }

        if ($countOk === 0) {
            if (!empty($zipErrors)) {
                $uploadErr = implode(' | ', array_slice($zipErrors, 0, 3));
            } else {
                $uploadErr = 'Tidak ada file yang berhasil diupload. Cek ekstensi/ukuran.';
            }
        } else {
            // Success message via query params
            rmi_redirect('manufactures_docs.php?code=' . urlencode($canonicalCode)
                . "&ok=1&up={$countOk}&hrl={$countHrl}&int={$countInt}&ign={$countIgnored}");
        }
    }
}

// list docs
$docsHrl = list_files($hrlDir, $hrlUrl);
$docsInt = list_files($intDir, $intUrl);

// master_manufactures_docs (PKS/LOA/LOA_KBRI dari Manufacturer Portal)
$manuDocsTable = [];
try {
    $st = $pdo->prepare("SELECT * FROM master_manufactures_docs WHERE manufacture_id=? ORDER BY doc_type, uploaded_at DESC");
    $st->execute([$recordId]);
    $rawManuDocs = $st->fetchAll(PDO::FETCH_ASSOC);
    $seenTypes = [];
    foreach ($rawManuDocs as $md) {
        if (!mm_manu_doc_matches($md, $canonicalCode)) continue;
        $t = strtoupper(trim((string)($md['doc_type'] ?? '')));
        if ($t === '' || isset($seenTypes[$t])) continue;
        $seenTypes[$t] = true;
        $manuDocsTable[] = $md;
    }
} catch (Throwable $e) {
    $manuDocsTable = [];
}

// audit log for this manufacture docs
$auditRows = [];
try {
    $st = $pdo->prepare("SELECT created_at, action, username, description
                         FROM system_audit_logs
                         WHERE module = 'manufactures_docs' AND record_code = :code
                         ORDER BY id DESC
                         LIMIT 30");
    $st->execute([':code' => $canonicalCode]);
    $auditRows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $auditRows = [];
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Manufactures Docs', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Manufactures Docs',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{
      background:
        radial-gradient(circle at 0% -20%, #0f172a 0, transparent 50%),
        radial-gradient(circle at 100% 120%, #111827 0, transparent 55%),
        radial-gradient(circle at 50% 0%, #0b1120 0, transparent 55%),
        #020617;
      color:#e5e7eb;
      min-height:100vh;
      font-size:14px;
    }
    .wrap{max-width:1100px;margin:0 auto;padding:24px 16px;}
    .card{background-color: rgba(17, 24, 39, 0.85); border: 1px solid rgba(148, 163, 184, 0.15);}
    .text-muted-small{color: rgba(229, 231, 235, 0.6); font-size: 12px;}
    a{color:#93c5fd;}
    a:hover{color:#bfdbfe;}
    .table-dark-custom{ --bs-table-bg: rgba(2, 6, 23, 0.6); --bs-table-striped-bg: rgba(15, 23, 42, 0.6); --bs-table-hover-bg: rgba(30, 41, 59, 0.6); color: #e5e7eb;}
.form-control,.form-select{background-color: rgba(2, 6, 23, 0.75); border: 1px solid rgba(148, 163, 184, 0.2); color: #e5e7eb;}
.form-control:focus,.form-select:focus{border-color: rgba(59, 130, 246, 0.55); box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.15);}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;}</style>',
]);
?>

<div class="wrap">

  <div class="mb-3">
    <a href="master_manufactures.php" class="text-decoration-none">← Kembali</a>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <h4 class="mb-1">Documents: <?= h($displayName) ?></h4>
      <div class="text-muted-small">Code: <b><?= h($canonicalCode) ?></b></div>
      <div class="text-muted-small mt-1">Folder: <code><?= h($baseUrl) ?></code></div>
    </div>
  </div>

  <?php if ($uploadErr !== ''): ?>
    <div class="alert alert-danger"><?= h($uploadErr) ?></div>
  <?php endif; ?>

  <?php if (isset($_GET['ok']) && (string)($_GET['ok'] ?? '') === '1'): ?>
    <div class="alert alert-success">
      Berhasil upload: <b><?= h((string)($_GET['up'] ?? '0')) ?></b> file
      (HRL <?= h((string)($_GET['hrl'] ?? '0')) ?>, Internal <?= h((string)($_GET['int'] ?? '0')) ?>).
      <?php if ((int)($_GET['ign'] ?? 0) > 0): ?>
        <span class="ms-2"><?= h((string)($_GET['ign'] ?? '0')) ?> file di-skip.</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Upload HRL -->
  <div class="card mb-3">
    <div class="card-header"><b>1) HRL Registrasi (AKL/AKD/NIE)</b></div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-2">
        <input type="hidden" name="upload_docs" value="1">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="doc_type" value="hrl">
        <div class="col-md-9">
          <input type="file" name="docs_files[]" class="form-control form-control-sm" multiple required>
          <div class="text-muted-small mt-1">Saran: upload PDF/Doc/XLSX (multi file) atau 1 file ZIP. Jika ZIP, file akan otomatis di-extract & disebar (berdasarkan nama file/folder). Max 50MB per file.</div>
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-sm btn-primary w-100">Upload HRL</button>
        </div>
      </form>

      <div class="table-responsive mt-3">
        <table class="table table-sm table-striped table-hover align-middle table-dark-custom">
          <thead>
            <tr>
              <th style="width:50px;">No</th>
              <th>Nama File</th>
              <th style="width:110px;">Ext</th>
              <th style="width:140px;">Size</th>
              <th style="width:180px;">Modified</th>
              <th style="width:160px;" class="text-center">Aksi</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($docsHrl)): ?>
            <tr><td colspan="6" class="text-center text-muted-small">Tidak ada dokumen.</td></tr>
          <?php else: ?>
            <?php $i=1; foreach ($docsHrl as $d): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= h($d['file']) ?></td>
                <td><?= h(strtoupper($d['ext'])) ?></td>
                <td><?= number_format((int)$d['size']/1024, 1) ?> KB</td>
                <td><?= h(date('Y-m-d H:i', (int)$d['mtime'])) ?></td>
                <td class="text-center">
                  <a class="btn btn-sm btn-outline-info" target="_blank" href="<?= h($d['url']) ?>">View</a>
                  <a class="btn btn-sm btn-outline-danger" href="manufactures_docs.php?code=<?= urlencode($canonicalCode) ?>&type=hrl&del=<?= urlencode($d['file']) ?>"
                     onclick="return confirm('Hapus file ini?')">Delete</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Upload Internal -->
  <div class="card mb-3">
    <div class="card-header"><b>2) Internal Wajib (Umum Kantor + Catalog)</b></div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-2">
        <input type="hidden" name="upload_docs" value="1">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="doc_type" value="internal">
        <div class="col-md-9">
          <input type="file" name="docs_files[]" class="form-control form-control-sm" multiple required>
          <div class="text-muted-small mt-1">Saran: upload Catalog PDF, Surat, SOP, Kontrak, dll (multi file) atau 1 file ZIP. Jika ZIP, file akan otomatis di-extract & disebar (berdasarkan nama file/folder). Max 50MB per file.</div>
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-sm btn-primary w-100">Upload Internal</button>
        </div>
      </form>

      <div class="table-responsive mt-3">
        <table class="table table-sm table-striped table-hover align-middle table-dark-custom">
          <thead>
            <tr>
              <th style="width:50px;">No</th>
              <th>Nama File</th>
              <th style="width:110px;">Ext</th>
              <th style="width:140px;">Size</th>
              <th style="width:180px;">Modified</th>
              <th style="width:160px;" class="text-center">Aksi</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($docsInt)): ?>
            <tr><td colspan="6" class="text-center text-muted-small">Tidak ada dokumen.</td></tr>
          <?php else: ?>
            <?php $i=1; foreach ($docsInt as $d): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= h($d['file']) ?></td>
                <td><?= h(strtoupper($d['ext'])) ?></td>
                <td><?= number_format((int)$d['size']/1024, 1) ?> KB</td>
                <td><?= h(date('Y-m-d H:i', (int)$d['mtime'])) ?></td>
                <td class="text-center">
                  <a class="btn btn-sm btn-outline-info" target="_blank" href="<?= h($d['url']) ?>">View</a>
                  <a class="btn btn-sm btn-outline-danger" href="manufactures_docs.php?code=<?= urlencode($canonicalCode) ?>&type=internal&del=<?= urlencode($d['file']) ?>"
                     onclick="return confirm('Hapus file ini?')">Delete</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Dokumen Pabrikan (PKS/LOA/LOA_KBRI) dari Manufacturer Portal -->

  <h3>Upload Dokumen Pabrikan</h3>
  <div class="text-muted-small mb-2">Manufacturer aktif: <b><?= h($displayName) ?></b> (<?= h($canonicalCode) ?>). Upload tipe yang sama akan menjadi <b>Replace</b>; relasi lama dilepas, file fisik lama tetap disimpan untuk recovery/audit.</div>

<form method="POST" enctype="multipart/form-data" class="row g-2 mb-3">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <div class="col-md-3">
        <select name="pabrikan_doc_type" class="form-select form-select-sm" required>
    <option value="">-- Pilih Type --</option>
    <option value="PKS">PKS</option>
    <option value="LOA">LOA</option>
    <option value="LOA_KBRI">LOA KBRI</option>
    <option value="OSS_PB_UMKU">OSS PB-UMKU ID</option>
    <option value="REGALKES_REF">Regalkes Reference</option>
</select>
    </div>

    <div class="col-md-6">
        <input type="file" name="pabrikan_file" class="form-control form-control-sm" required>
    </div>

    <div class="col-md-3">
        <button type="submit" name="upload_pabrikan" class="btn btn-sm btn-primary w-100">
            Upload Dokumen Pabrikan
        </button>
    </div>
</form>
  <div class="card mb-3">
    <div class="card-header"><b>3) Dokumen Pabrikan (PKS/LOA/LOA_KBRI)</b> <span class="text-muted-small">— dari Manufacturer Portal / master_manufactures_docs</span></div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped table-hover align-middle table-dark-custom">
          <thead>
            <tr>
              <th style="width:100px;">Tipe</th>
              <th>File</th>
              <th style="width:180px;">Uploaded</th>
              <th style="width:120px;">By</th>
              <th style="width:100px;" class="text-center">Aksi</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($manuDocsTable)): ?>
            <tr><td colspan="5" class="text-center text-muted-small">Belum ada dokumen PKS/LOA/LOA_KBRI. Upload via Manufacturer Portal atau Reg Alkes Case.</td></tr>
          <?php else: ?>
            <?php foreach ($manuDocsTable as $md): ?>
              <tr>
                <td class="mono"><?= h($md['doc_type'] ?? '') ?></td>
                <td><?= h($md['file_name'] ?? '') ?></td>
                <td><?= h($md['uploaded_at'] ?? '') ?></td>
                <td><?= h($md['uploaded_by'] ?? '') ?></td>
                <td class="text-center">
                  <a class="btn btn-sm btn-outline-info mb-1" href="manufactures_docs.php?code=<?= urlencode($canonicalCode) ?>&dl_md=<?= (int)($md['id'] ?? 0) ?>">Download</a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Hapus relasi dokumen ini dari <?= h($displayName) ?>? File fisik tetap disimpan untuk recovery.');">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="delete_pabrikan_doc" value="1">
                    <input type="hidden" name="md_id" value="<?= (int)($md['id'] ?? 0) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger mb-1">Hapus Relasi</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><b>Audit Log</b> <span class="text-muted-small">(Last 30 for this manufacture)</span></div>
    <div class="card-body">
      <?php if (empty($auditRows)): ?>
        <div class="text-muted-small">Belum ada audit log upload/delete untuk manufacture ini.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped table-hover align-middle table-dark-custom">
            <thead>
              <tr>
                <th style="width:180px;">Time</th>
                <th style="width:160px;">Action</th>
                <th style="width:160px;">User</th>
                <th>Description</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($auditRows as $a): ?>
                <tr>
                  <td><?= h($a['created_at'] ?? '') ?></td>
                  <td><?= h($a['action'] ?? '') ?></td>
                  <td><?= h($a['username'] ?? '') ?></td>
                  <td><?= h($a['description'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="text-muted-small">
    Catatan: Dokumen HRL/Internal disimpan di filesystem. PKS/LOA/LOA_KBRI dari Manufacturer Portal disimpan di <code>master_manufactures_docs</code>. Semua upload/delete tercatat di <code>system_audit_logs</code>.
  </div>

</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<?php rmi_footer(); ?>
