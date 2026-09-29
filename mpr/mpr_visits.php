<?php
if (!function_exists('rmi_icon')) { require_once __DIR__ . '/../_shared/rmi_icons.php'; }
// --- Auth guard ---
$__rmi_guard_dir = __DIR__;
for ($__rmi_guard_i = 0; $__rmi_guard_i < 5; $__rmi_guard_i++) {
    $__rmi_guard_auth = $__rmi_guard_dir . '/master/auth.php';
    if (file_exists($__rmi_guard_auth)) { require_once $__rmi_guard_auth; if (function_exists('require_login')) require_login(); break; }
    $__rmi_guard_parent = dirname($__rmi_guard_dir);
    if ($__rmi_guard_parent === $__rmi_guard_dir) break;
    $__rmi_guard_dir = $__rmi_guard_parent;
}
unset($__rmi_guard_dir,$__rmi_guard_i,$__rmi_guard_auth,$__rmi_guard_parent);
// --- /Auth guard ---

require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/_inc/schema.php';
mpr_schema_ensure($pdo);
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_inc/mpr_access.php';
try { mpr_access_schema_ensure($pdo); } catch (Throwable $e) {}
$MPR_ALLOWED_OFFICES = mpr_allowed_offices($pdo, $MPR_USER, $MPR_IS_ADMIN);

// Konstanta
define('MPR_VISIT_TYPES', [
    'NEW_PROSPECT'      => ['label' => rmi_icon('zap').' Prospek Baru',        'color' => '#14b8a6'],
    'FOLLOW_UP'         => ['label' => rmi_icon('refresh').' Follow-Up',           'color' => '#f59e0b'],
    'PRESENTATION'      => ['label' => rmi_icon('chart').' Presentasi/Demo',     'color' => '#3b82f6'],
    'NEGOTIATION'       => ['label' => rmi_icon('users').' Negosiasi',           'color' => '#8b5cf6'],
    'EXISTING_CUSTOMER' => ['label' => rmi_icon('check').' Customer Aktif',      'color' => '#22c55e'],
    'CLOSING'           => ['label' => rmi_icon('target').' Closing/Deal',        'color' => '#ef4444'],
]);
define('MPR_OUTCOMES', [
    'PENDING'         => ['label' => rmi_icon('refresh').' Belum ada hasil', 'color' => '#64748b'],
    'INTERESTED'      => ['label' => rmi_icon('check').' Tertarik',        'color' => '#14b8a6'],
    'NEED_FOLLOWUP'   => ['label' => rmi_icon('refresh').' Perlu Follow-Up', 'color' => '#f59e0b'],
    'PRESENTATION_OK' => ['label' => rmi_icon('chart').' Minta Presentasi','color' => '#3b82f6'],
    'NEGOTIATING'     => ['label' => rmi_icon('users').' Negosiasi Harga', 'color' => '#8b5cf6'],
    'DEAL_WON'        => ['label' => rmi_icon('target').' DEAL – Berhasil', 'color' => '#22c55e'],
    'DEAL_LOST'       => ['label' => rmi_icon('cross').' Tidak Jadi',      'color' => '#ef4444'],
    'NOT_INTERESTED'  => ['label' => rmi_icon('cross').' Tidak Berminat',  'color' => '#94a3b8'],
]);


function mpr_visits_has_column(PDO $pdo, string $column): bool {
    static $cache = [];
    $key = 'mpr_visits.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM mpr_visits LIKE ?");
        $st->execute([$column]);
        $cache[$key] = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function mpr_visits_save_gps_accuracy(PDO $pdo, int $visitId, float $acc): void {
    if ($visitId <= 0) return;
    if (mpr_visits_has_column($pdo, 'gps_accuracy_m')) {
        try {
            $st = $pdo->prepare("UPDATE mpr_visits SET gps_accuracy_m=? WHERE id=?");
            $st->execute([$acc, $visitId]);
        } catch (Throwable $e) {}
    }
}



function mpr_visits_add_column(PDO $pdo, string $column, string $ddl): void {
    if (mpr_visits_has_column($pdo, $column)) return;
    try {
        $pdo->exec("ALTER TABLE mpr_visits ADD COLUMN `$column` $ddl");
    } catch (Throwable $e) {
        $msg = strtolower($e->getMessage());
        if (strpos($msg, 'duplicate column') !== false || strpos($msg, '1060') !== false || strpos($msg, 'already exists') !== false) {
            return;
        }
        throw $e;
    }
}

function mpr_visits_ensure_photo_schema(PDO $pdo): void {
    // Kolom foto kunjungan dibuat aman: jika sudah ada, dilewati.
    // Tidak memakai AFTER agar tidak error di struktur tabel live yang berbeda.
    mpr_visits_add_column($pdo, 'photo_path', "VARCHAR(255) NULL");
    mpr_visits_add_column($pdo, 'photo_original_name', "VARCHAR(255) NULL");
    mpr_visits_add_column($pdo, 'photo_uploaded_at', "DATETIME NULL");
}


function mpr_visits_ensure_customer_pic_schema(PDO $pdo): void {
    // Relasi baru bersifat additive; kolom snapshot lama tetap dipertahankan
    // agar histori/report existing tidak rusak.
    mpr_visits_add_column($pdo, 'customer_id', "INT NULL");
    mpr_visits_add_column($pdo, 'pic_id', "INT NULL");
    try { $pdo->exec("CREATE INDEX idx_mpr_visits_customer_id ON mpr_visits (customer_id)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX idx_mpr_visits_pic_id ON mpr_visits (pic_id)"); } catch (Throwable $e) {}
}
function mpr_visits_customer_by_id(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    try {
        $st=$pdo->prepare("SELECT id,customers_code,customers_name,office_code,status FROM master_customers WHERE id=? LIMIT 1");
        $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    } catch (Throwable $e) { return null; }
}
function mpr_visits_pic_by_id(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    try {
        $st=$pdo->prepare("SELECT id,customer_id,contact_name,role_title,phone,status FROM master_mpr WHERE id=? LIMIT 1");
        $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function mpr_visits_photo_url(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path) || substr($path, 0, 1) === '/') return $path;
    // Halaman ini berada di /mpr/, sedangkan upload disimpan di /uploads/...
    return '../' . ltrim($path, '/');
}


function mpr_visits_php_size_to_bytes($value): int {
    $value = trim((string)$value);
    if ($value === '') return 0;
    $last = strtolower(substr($value, -1));
    $num = (float)$value;
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return (int)round($num);
}

function mpr_visits_upload_max_bytes(): int {
    $upload = mpr_visits_php_size_to_bytes((string)ini_get('upload_max_filesize'));
    $post   = mpr_visits_php_size_to_bytes((string)ini_get('post_max_size'));
    $limits = array_filter([$upload, $post], static fn($v) => $v > 0);
    return $limits ? min($limits) : (8 * 1024 * 1024);
}

function mpr_visits_format_bytes(int $bytes): string {
    if ($bytes >= 1024 * 1024) return rtrim(rtrim(number_format($bytes / 1024 / 1024, 2, ',', '.'), '0'), ',') . ' MB';
    if ($bytes >= 1024) return rtrim(rtrim(number_format($bytes / 1024, 2, ',', '.'), '0'), ',') . ' KB';
    return $bytes . ' byte';
}

function mpr_visits_upload_error_message(int $code, string $field = 'visit_photo'): string {
    $max = mpr_visits_format_bytes(min(8 * 1024 * 1024, mpr_visits_upload_max_bytes()));
    return match ($code) {
        UPLOAD_ERR_OK => 'Upload berhasil.',
        UPLOAD_ERR_INI_SIZE => 'Ukuran foto melebihi batas upload server. Maksimal yang aman saat ini sekitar ' . $max . '. Silakan kompres foto atau ubah setting PHP upload_max_filesize/post_max_size.',
        UPLOAD_ERR_FORM_SIZE => 'Ukuran foto melebihi batas form. Maksimal foto kunjungan adalah ' . $max . '. Silakan kompres foto lalu upload ulang.',
        UPLOAD_ERR_PARTIAL => 'Upload foto terputus / hanya terupload sebagian. Silakan upload ulang dengan koneksi stabil, atau kompres foto dari HP sebelum upload.',
        UPLOAD_ERR_NO_FILE => 'Foto kunjungan wajib diupload.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary upload server tidak tersedia. Cek konfigurasi upload_tmp_dir PHP/NAS.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file upload. Cek permission folder uploads/mpr_visits.',
        UPLOAD_ERR_EXTENSION => 'Upload foto dihentikan oleh extension PHP/server.',
        default => 'Upload foto gagal. Kode error: ' . $code,
    };
}

function mpr_visits_post_exceeds_server_limit(): bool {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = mpr_visits_php_size_to_bytes((string)ini_get('post_max_size'));
    return $contentLength > 0 && $postMax > 0 && $contentLength > $postMax;
}

function mpr_visits_uploaded_original_name(string $field): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $err = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return null;
    return trim((string)($_FILES[$field]['name'] ?? '')) ?: null;
}

function mpr_visits_upload_photo(string $field, string $existingPath = ''): string {
    $hasFile = !empty($_FILES[$field]) && is_array($_FILES[$field]) && (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!$hasFile) {
        if (trim($existingPath) !== '') return $existingPath;
        if (mpr_visits_post_exceeds_server_limit()) {
            throw new Exception('Ukuran request upload melebihi batas server post_max_size. Silakan kompres foto lalu upload ulang.');
        }
        throw new Exception(mpr_visits_upload_error_message(UPLOAD_ERR_NO_FILE, $field));
    }

    $file = $_FILES[$field];
    $err = (int)($file['error'] ?? UPLOAD_ERR_OK);
    if ($err !== UPLOAD_ERR_OK) {
        throw new Exception(mpr_visits_upload_error_message($err, $field));
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new Exception('File foto tidak valid.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) throw new Exception('File foto kosong.');
    $maxBytes = min(8 * 1024 * 1024, mpr_visits_upload_max_bytes());
    if ($size > $maxBytes) {
        throw new Exception('Ukuran foto terlalu besar (' . mpr_visits_format_bytes($size) . '). Maksimal yang diperbolehkan ' . mpr_visits_format_bytes($maxBytes) . '. Silakan kompres foto lalu upload ulang.');
    }

    $original = (string)($file['name'] ?? 'foto');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)finfo_file($fi, $tmp);
            finfo_close($fi);
        }
    }
    if ($mime === '') $mime = strtolower((string)($file['type'] ?? ''));

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];
    $allowedExt = ['jpg','jpeg','png','webp','gif','heic','heif'];

    if (!isset($allowed[$mime]) && !in_array($ext, $allowedExt, true)) {
        throw new Exception('Foto wajib berupa gambar: JPG, PNG, WEBP, GIF, HEIC, atau HEIF.');
    }
    if (isset($allowed[$mime])) $ext = $allowed[$mime];
    if ($ext === 'jpeg') $ext = 'jpg';
    if ($ext === '') $ext = 'jpg';

    $ym = date('Ym');
    $root = dirname(__DIR__); // project root ERP_RMI_SOFULL
    $relDir = 'uploads/mpr_visits/' . $ym;
    $absDir = $root . '/' . $relDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0775, true)) {
        throw new Exception('Folder upload foto tidak bisa dibuat: ' . $relDir);
    }
    if (!is_writable($absDir)) {
        throw new Exception('Folder upload foto tidak bisa ditulis: ' . $relDir . '. Cek permission folder uploads/mpr_visits.');
    }

    $name = 'visit_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $absDir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new Exception('Gagal menyimpan foto kunjungan. Cek permission folder uploads/mpr_visits dan kapasitas storage server.');
    }
    @chmod($dest, 0644);

    return $relDir . '/' . $name;
}

// Permissions
// Branch operational: cabang/depo tanpa staff MPR boleh membuat kunjungan,
// tetap wajib GPS+Foto dan tetap dibatasi office/assignment plan.
$isBranchOperational = !empty($MPR_BRANCH_OPERATIONAL);
$isDepoBranch = !empty($MPR_IS_DEPO_BRANCH)
    || (function_exists('mpr_access_is_depo_branch') && mpr_access_is_depo_branch($MPR_USER));
$can_create = $isBranchOperational ? true : (function_exists('can_any') ? can_any(['MPR.VIEW','MPR.PLAN_CREATE']) : true);
// Depo partner boleh mencatat/memperbarui kunjungan sendiri, tetapi tidak menghapus histori.
$can_delete = !$isDepoBranch && ($MPR_IS_ADMIN || (function_exists('can') && can('MPR.PLAN_EDIT')));

// HRL Manager boleh melihat seluruh laporan kunjungan MPR lintas kantor/cabang.
// Contoh akun: MgrHRL_BGR, MgrHRL_TGR, MgrHRL_BKS.
function mpr_visits_is_mgr_hrl_global(array $user): bool {
    $username = strtoupper(trim((string)($user['username'] ?? '')));
    $role     = strtoupper(trim((string)($user['role'] ?? '')));
    $level    = strtoupper(trim((string)($user['level'] ?? '')));
    $dept     = strtoupper(trim((string)($user['department'] ?? $user['dept'] ?? '')));

    $isManager = in_array($level, ['MANAGER','MGR'], true)
              || in_array($role, ['MANAGER','MGR'], true)
              || strpos($username, 'MGR') !== false
              || strpos($username, 'MANAGER') !== false;

    $isHrl = ($dept === 'HRL') || strpos($username, 'HRL') !== false;

    return $isManager && $isHrl;
}

$MPR_VISITS_ALL_OFFICE_VIEW = $MPR_IS_ADMIN || mpr_visits_is_mgr_hrl_global($MPR_USER);

try {
    mpr_visits_ensure_photo_schema($pdo);
    mpr_visits_ensure_customer_pic_schema($pdo);
} catch (Throwable $e) {
    if (function_exists('rmi_log_module_error')) rmi_log_module_error('mpr_visits', $e, ['action' => 'MPR_VISIT_PHOTO_SCHEMA']);
    flash_set('danger', 'Gagal memastikan kolom foto kunjungan: ' . e($e->getMessage()));
}

// EXPORT
if ((string)($_GET['export'] ?? '') === '1' && $can_create) {
    $ep = []; $ew = 'v.deleted_at IS NULL';
    if (!$MPR_VISITS_ALL_OFFICE_VIEW) { $ew .= ' AND ' . mpr_apply_allowed_office_filter($pdo, $MPR_USER, false, 'p.office_code', $ep); }
    $fs = strtoupper(trim((string)($_GET['type'] ?? '')));
    if ($fs && $fs !== 'ALL') { $ew .= ' AND v.visit_type=?'; $ep[] = $fs; }
    $fmo = (string)($_GET['month'] ?? '');
    if ($fmo) { $ew .= ' AND DATE_FORMAT(v.visit_date,"%Y-%m")=?'; $ep[] = $fmo; }
    $fwho = trim((string)($_GET['who'] ?? ''));
    if ($fwho !== '') { $ew .= ' AND v.visitor_username=?'; $ep[] = $fwho; }
    $stE = $pdo->prepare("SELECT v.*, p.title plan_title, p.plan_code FROM mpr_visits v JOIN mpr_plans p ON p.id=v.plan_id WHERE {$ew} ORDER BY v.visit_date DESC");
    $stE->execute($ep);
    $rows_e = $stE->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mpr_visits_' . date('Ymd') . '.csv"');
    $fh = fopen('php://output','w'); fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($fh,['visit_date','visitor','level','visit_type','outcome','customer_name','contact_name','location','city','result','next_followup','est_deal_value','plan_code','plan_title','gps_lat','gps_lng','photo_path']);
    foreach ($rows_e as $r) fputcsv($fh,[$r['visit_date'],$r['visitor_username'],$r['visitor_level'],$r['visit_type'],$r['outcome'],$r['customer_name'],$r['contact_name'],$r['location'],$r['visit_city'],$r['result'],$r['next_followup_date'],$r['est_deal_value'],$r['plan_code'],$r['plan_title'],$r['gps_lat'],$r['gps_lng'],$r['photo_path'] ?? '']);
    fclose($fh); exit;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && mpr_visits_post_exceeds_server_limit()) {
        flash_set('danger', 'Upload gagal karena ukuran foto/request melebihi batas server post_max_size. Silakan kompres foto lalu upload ulang.');
        rmi_redirect(url_mpr('mpr_visits.php'));
    }
    csrf_check_or_die();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create' || $action === 'update') {
            if (!$can_create) throw new Exception("Tidak ada izin.");
            $id           = (int)($_POST['id'] ?? 0);
            $plan_id      = (int)($_POST['plan_id'] ?? 0);
            $visit_date   = (string)($_POST['visit_date'] ?? date('Y-m-d'));
            $visit_type   = strtoupper(trim((string)($_POST['visit_type'] ?? 'NEW_PROSPECT')));
            $outcome      = strtoupper(trim((string)($_POST['outcome'] ?? 'PENDING')));
            $customer_id  = (int)($_POST['customer_id'] ?? 0);
            $pic_id       = (int)($_POST['pic_id'] ?? 0);
            $customer_name= '';
            $contact_name = '';
            $contact_role = '';
            $location     = trim((string)($_POST['location'] ?? ''));
            $visit_city   = trim((string)($_POST['visit_city'] ?? ''));
            $result       = trim((string)($_POST['result'] ?? ''));
            $notes        = trim((string)($_POST['notes'] ?? ''));
            $next_fu      = (string)($_POST['next_followup_date'] ?? '');
            $est_deal     = (string)($_POST['est_deal_value'] ?? '');
            $gps_lat      = trim((string)($_POST['gps_lat'] ?? ''));
            $gps_lng      = trim((string)($_POST['gps_lng'] ?? ''));
            $gps_acc      = trim((string)($_POST['gps_accuracy_m'] ?? ''));
            $photo_original_name = mpr_visits_uploaded_original_name('visit_photo');

            if ($plan_id === 0) throw new Exception("Plan wajib dipilih.");

            // Ambil Plan sebagai sumber relasi utama Customer -> PIC.
            $stPlanScope = $pdo->prepare("
                SELECT id, dept_code, office_code, customer_id, customer_name, pic_id, pic_name, pic_role
                FROM mpr_plans
                WHERE id=? AND deleted_at IS NULL
                LIMIT 1
            ");
            $stPlanScope->execute([$plan_id]);
            $planScope = $stPlanScope->fetch(PDO::FETCH_ASSOC);
            if (!$planScope) throw new Exception("Plan tidak ditemukan.");

            // Validasi scope existing tetap dipertahankan.
            if (!$MPR_VISITS_ALL_OFFICE_VIEW) {
                if (strtoupper((string)$planScope['dept_code']) !== strtoupper((string)$MPR_USER['department'])
                    || !mpr_office_is_allowed($pdo, $MPR_USER, false, (string)$planScope['office_code'])) {
                    throw new Exception("Plan ini bukan untuk area tugas akun kamu. Untuk akun BRANCH, pastikan plan dibuat oleh BRANCH pada office akun/assignment yang benar.");
                }
            }

            $planCustomerId = (int)($planScope['customer_id'] ?? 0);
            $planPicId = (int)($planScope['pic_id'] ?? 0);

            // Customer pada Visit harus mengikuti Customer Plan.
            if ($planCustomerId > 0) {
                if ($customer_id > 0 && $customer_id !== $planCustomerId) {
                    throw new Exception("Customer kunjungan harus sama dengan Customer pada Plan.");
                }
                $customer_id = $planCustomerId;
            }
            if ($customer_id <= 0) throw new Exception("Plan belum mempunyai Customer. Lengkapi Customer pada MPR Plan terlebih dahulu.");

            $custSel = mpr_visits_customer_by_id($pdo, $customer_id);
            if (!$custSel) throw new Exception("Master Customer tidak ditemukan.");
            $customer_name = trim((string)($custSel['customers_name'] ?? ''));
            if ($customer_name === '') throw new Exception("Nama Customer pada master kosong.");

            // PIC boleh mengikuti PIC Plan atau diganti ke PIC lain, TETAPI wajib masih
            // berada pada Customer yang sama. Ini mendukung ATEM/JANGMED/Farmasi dalam 1 RS.
            if ($pic_id <= 0 && $planPicId > 0) $pic_id = $planPicId;
            if ($pic_id > 0) {
                $picSel = mpr_visits_pic_by_id($pdo, $pic_id);
                if (!$picSel) throw new Exception("PIC Customer tidak ditemukan.");
                if ((int)($picSel['customer_id'] ?? 0) !== $customer_id) {
                    throw new Exception("PIC kunjungan bukan milik Customer pada Plan.");
                }
                $contact_name = trim((string)($picSel['contact_name'] ?? ''));
                $contact_role = trim((string)($picSel['role_title'] ?? ''));
            }

            if ($gps_lat === '' || $gps_lng === '') {
                throw new Exception("GPS realtime wajib diambil. Klik Buka GPS Realtime Khusus, ambil lokasi, lalu klik Kirim GPS ke Form.");
            }
            if (!is_numeric($gps_lat) || !is_numeric($gps_lng)) {
                throw new Exception("Format GPS tidak valid.");
            }
            $gps_lat_f = (float)$gps_lat;
            $gps_lng_f = (float)$gps_lng;
            $gps_acc_f = is_numeric($gps_acc) ? (float)$gps_acc : 999999;
            if ($gps_lat_f < -90 || $gps_lat_f > 90 || $gps_lng_f < -180 || $gps_lng_f > 180) {
                throw new Exception("Koordinat GPS tidak valid.");
            }
            if ($gps_acc_f <= 0 || $gps_acc_f > 250) {
                throw new Exception("Akurasi GPS terlalu rendah: " . round($gps_acc_f) . " m. Maksimal 250 m. Ambil GPS realtime ulang.");
            }

            // Visitor = user yang login (staff atau manager)
            $visitor_username = $MPR_USER['username'];
            $visitor_level    = $MPR_USER['level'] ?: $MPR_USER['role'];

            if ($action === 'create') {
                $photo_path = mpr_visits_upload_photo('visit_photo');
                $pdo->prepare("
                    INSERT INTO mpr_visits
                      (plan_id,customer_id,pic_id,visit_date,visit_type,outcome,customer_name,contact_name,contact_role_title,
                       location,visit_city,result,notes,next_followup_date,est_deal_value,
                       gps_lat,gps_lng,photo_path,photo_original_name,photo_uploaded_at,visitor_username,visitor_level,created_by,created_at)
                    VALUES
                      (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,NOW())
                ")->execute([
                    $plan_id, $customer_id, ($pic_id > 0 ? $pic_id : null), $visit_date, $visit_type, $outcome, $customer_name, $contact_name, $contact_role,
                    $location, $visit_city, $result, $notes,
                    $next_fu ?: null, ($est_deal !== '' ? (float)$est_deal : null),
                    ($gps_lat !== '' ? (float)$gps_lat : null), ($gps_lng !== '' ? (float)$gps_lng : null),
                    $photo_path, $photo_original_name,
                    $visitor_username, $visitor_level, $visitor_username,
                ]);
                $new_id = (int)$pdo->lastInsertId();
                mpr_visits_save_gps_accuracy($pdo, $new_id, $gps_acc_f);
                mpr_audit($pdo,$MPR_USER,'VISIT_CREATE','mpr_visits',$new_id,$customer_name,'Catat kunjungan',['type'=>$visit_type,'outcome'=>$outcome,'visitor'=>$visitor_username,'level'=>$visitor_level,'gps_acc_m'=>$gps_acc_f,'photo_path'=>$photo_path]);
                flash_set('success', "Kunjungan ke <b>".e($customer_name)."</b> berhasil dicatat.");
            } else {
                $stV = $pdo->prepare("SELECT v.*, p.office_code AS plan_office_code, p.dept_code AS plan_dept_code
                                      FROM mpr_visits v
                                      JOIN mpr_plans p ON p.id=v.plan_id
                                      WHERE v.id=? AND v.deleted_at IS NULL LIMIT 1");
                $stV->execute([$id]);
                $vis = $stV->fetch(PDO::FETCH_ASSOC);
                if (!$vis) throw new Exception("Kunjungan tidak ditemukan.");
                if (!$MPR_VISITS_ALL_OFFICE_VIEW) {
                    if (strtoupper(trim((string)($vis['plan_dept_code'] ?? ''))) !== strtoupper((string)$MPR_USER['department'])
                        || !mpr_office_is_allowed($pdo, $MPR_USER, false, (string)($vis['plan_office_code'] ?? ''))) {
                        throw new Exception("Kunjungan ini bukan milik office/area tugas akun Anda.");
                    }
                }
                if (!$MPR_IS_ADMIN && (string)$vis['visitor_username'] !== $MPR_USER['username']) throw new Exception("Hanya pencatat atau Admin yang boleh edit.");
                $photo_path = mpr_visits_upload_photo('visit_photo', (string)($vis['photo_path'] ?? ''));
                $photo_uploaded_at_sql = ($photo_original_name !== null) ? ', photo_uploaded_at=NOW()' : '';
                $pdo->prepare("
                    UPDATE mpr_visits SET
                      plan_id=?,customer_id=?,pic_id=?,visit_date=?,visit_type=?,outcome=?,customer_name=?,contact_name=?,contact_role_title=?,
                      location=?,visit_city=?,result=?,notes=?,next_followup_date=?,est_deal_value=?,gps_lat=?,gps_lng=?,
                      photo_path=?,photo_original_name=COALESCE(?, photo_original_name) {$photo_uploaded_at_sql}
                    WHERE id=?
                ")->execute([
                    $plan_id,$customer_id,($pic_id > 0 ? $pic_id : null),$visit_date,$visit_type,$outcome,$customer_name,$contact_name,$contact_role,
                    $location,$visit_city,$result,$notes,
                    $next_fu ?: null, ($est_deal !== '' ? (float)$est_deal : null),
                    ($gps_lat !== '' ? (float)$gps_lat : null), ($gps_lng !== '' ? (float)$gps_lng : null),
                    $photo_path, $photo_original_name,
                    $id,
                ]);
                mpr_visits_save_gps_accuracy($pdo, $id, $gps_acc_f);
                mpr_audit($pdo,$MPR_USER,'VISIT_UPDATE','mpr_visits',$id,$customer_name,'Update kunjungan',['type'=>$visit_type,'outcome'=>$outcome,'gps_acc_m'=>$gps_acc_f,'photo_path'=>$photo_path]);
                flash_set('success', "Kunjungan diupdate.");
            }
            rmi_redirect(url_mpr('mpr_visits.php'));
        }

        if ($action === 'delete') {
            if (!$can_delete) throw new Exception("Tidak ada izin hapus kunjungan.");
            $id = (int)($_POST['id'] ?? 0);
            $stDel = $pdo->prepare("SELECT v.id, v.visitor_username, p.office_code AS plan_office_code, p.dept_code AS plan_dept_code
                                    FROM mpr_visits v
                                    JOIN mpr_plans p ON p.id=v.plan_id
                                    WHERE v.id=? AND v.deleted_at IS NULL LIMIT 1");
            $stDel->execute([$id]);
            $delRow = $stDel->fetch(PDO::FETCH_ASSOC);
            if (!$delRow) throw new Exception("Kunjungan tidak ditemukan.");
            if (!$MPR_VISITS_ALL_OFFICE_VIEW) {
                if (strtoupper(trim((string)($delRow['plan_dept_code'] ?? ''))) !== strtoupper((string)$MPR_USER['department'])
                    || !mpr_office_is_allowed($pdo, $MPR_USER, false, (string)($delRow['plan_office_code'] ?? ''))) {
                    throw new Exception("Kunjungan ini bukan milik office/area tugas akun Anda.");
                }
            }
            if (!$MPR_IS_ADMIN && (string)($delRow['visitor_username'] ?? '') !== (string)$MPR_USER['username']) {
                throw new Exception("Hanya pencatat atau Admin yang boleh menghapus kunjungan.");
            }
            $pdo->prepare("UPDATE mpr_visits SET deleted_at=NOW() WHERE id=?")->execute([$id]);
            flash_set('warning', "Kunjungan dihapus.");
            rmi_redirect(url_mpr('mpr_visits.php'));
        }

    } catch (Throwable $e) {
        if (function_exists('rmi_log_module_error')) rmi_log_module_error('mpr_visits', $e, ['action' => 'MPR_VISIT_SAVE']);
        flash_set('danger', "Error: " . e($e->getMessage()));
        rmi_redirect(url_mpr('mpr_visits.php'));
    }
}

require_once __DIR__ . '/_layout_top.php';

// Filters
$f_type  = strtoupper(trim((string)($_GET['type']  ?? '')));
$f_month = (string)($_GET['month'] ?? date('Y-m'));
$f_q     = trim((string)($_GET['q'] ?? ''));
$f_who   = trim((string)($_GET['who'] ?? ''));

$where  = 'v.deleted_at IS NULL';
$params = [];
if (!$MPR_VISITS_ALL_OFFICE_VIEW) { $where .= ' AND ' . mpr_apply_allowed_office_filter($pdo, $MPR_USER, false, 'p.office_code', $params); }
if ($f_type && $f_type !== 'ALL') { $where .= ' AND v.visit_type=?'; $params[] = $f_type; }
if ($f_month) { $where .= ' AND DATE_FORMAT(v.visit_date,"%Y-%m")=?'; $params[] = $f_month; }
if ($f_q !== '') { $where .= ' AND (v.customer_name LIKE ? OR v.contact_name LIKE ? OR v.location LIKE ?)'; $lk="%$f_q%"; array_push($params,$lk,$lk,$lk); }
if ($f_who !== '') { $where .= ' AND v.visitor_username=?'; $params[] = $f_who; }

$stV = $pdo->prepare("
    SELECT v.*, p.plan_code, p.title plan_title
    FROM mpr_visits v JOIN mpr_plans p ON p.id=v.plan_id
    WHERE {$where}
    ORDER BY v.visit_date DESC, v.id DESC
    LIMIT 200
");
$stV->execute($params);
$visits = $stV->fetchAll();

// Plans for dropdown
$pPlans=[]; $wPlans='deleted_at IS NULL';
if (!$MPR_VISITS_ALL_OFFICE_VIEW) {
    $wPlans .= ' AND dept_code=? AND ';
    $pPlans[] = $MPR_USER['department'];
    $wPlans .= mpr_apply_allowed_office_filter($pdo, $MPR_USER, false, 'office_code', $pPlans);
}
$stPl = $pdo->prepare("SELECT id, plan_code, title FROM mpr_plans WHERE {$wPlans} ORDER BY created_at DESC LIMIT 100");
$stPl->execute($pPlans); $plans = $stPl->fetchAll();

// Customers for dropdown - sumber master_customers.
// User MPR internal tetap memakai perilaku lama (semua master customer).
// Khusus akun Depo KAL/JGY: fail-closed ke customer office sendiri agar data
// customer cabang/depo lain tidak ikut terbuka.
$customers = [];
try {
    $custWhere = "(LOWER(TRIM(COALESCE(status,''))) = 'active' OR TRIM(COALESCE(status,'')) = '')
"
               . "          AND TRIM(COALESCE(customers_name,'')) <> ''";
    $custParams = [];
    if ($isDepoBranch) {
        $custWhere .= "
          AND UPPER(TRIM(COALESCE(office_code,''))) = ?";
        $custParams[] = strtoupper(trim((string)$MPR_USER['office_code']));
    }
    $stCust = $pdo->prepare("
        SELECT id, customers_code, customers_name, office_code, status
        FROM master_customers
        WHERE {$custWhere}
        ORDER BY customers_name ASC
        LIMIT 5000
    ");
    $stCust->execute($custParams);
    $customers = $stCust->fetchAll();

    // Fallback format status legacy tetap mempertahankan scope Depo.
    if (!$customers) {
        $fallbackWhere = "TRIM(COALESCE(customers_name,'')) <> ''";
        $fallbackParams = [];
        if ($isDepoBranch) {
            $fallbackWhere .= " AND UPPER(TRIM(COALESCE(office_code,''))) = ?";
            $fallbackParams[] = strtoupper(trim((string)$MPR_USER['office_code']));
        }
        $stCust = $pdo->prepare("
            SELECT id, customers_code, customers_name, office_code, status
            FROM master_customers
            WHERE {$fallbackWhere}
            ORDER BY customers_name ASC
            LIMIT 5000
        ");
        $stCust->execute($fallbackParams);
        $customers = $stCust->fetchAll();
    }
} catch (Throwable $e) {
    $customers = [];
}

// Edit load — scope office/dept wajib sama dengan list utama.
$edit_id = (int)($_GET['edit'] ?? 0); 
$visitPics = [];
try {
    $stPic = $pdo->query("
        SELECT m.id,m.customer_id,m.contact_name,m.role_title,m.phone,m.status,
               c.customers_name,c.customers_code,c.office_code
        FROM master_mpr m
        JOIN master_customers c ON c.id=m.customer_id
        WHERE TRIM(COALESCE(m.contact_name,'')) <> ''
          AND (LOWER(TRIM(COALESCE(m.status,'')))='active' OR TRIM(COALESCE(m.status,''))='')
        ORDER BY c.customers_name,m.contact_name
        LIMIT 8000
    ");
    $visitPics = $stPic->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $visitPics = []; }

$edit = null;
if ($edit_id) {
    $editParams = [$edit_id];
    $editWhere = 'v.id=? AND v.deleted_at IS NULL';
    if (!$MPR_VISITS_ALL_OFFICE_VIEW) {
        $editWhere .= " AND UPPER(TRIM(COALESCE(p.dept_code,'')))=?";
        $editParams[] = strtoupper((string)$MPR_USER['department']);
        $editWhere .= ' AND ' . mpr_apply_allowed_office_filter($pdo, $MPR_USER, false, 'p.office_code', $editParams);
    }
    $st2 = $pdo->prepare("SELECT v.*,p.plan_code,p.title plan_title,p.office_code plan_office_code,p.dept_code plan_dept_code
                          FROM mpr_visits v JOIN mpr_plans p ON p.id=v.plan_id
                          WHERE {$editWhere} LIMIT 1");
    $st2->execute($editParams);
    $edit = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$edit) {
        http_response_code(403);
        exit('Forbidden: kunjungan bukan milik office/area tugas akun ini.');
    }
}

// Summary counts
$cnt_new=0; $cnt_fu=0; $cnt_deal=0; $cnt_today=0;
foreach ($visits as $v) {
    $t = (string)$v['visit_type']; $o = (string)$v['outcome'];
    if ($t === 'NEW_PROSPECT') $cnt_new++;
    if ($t === 'FOLLOW_UP' || $t === 'PRESENTATION' || $t === 'NEGOTIATION') $cnt_fu++;
    if ($o === 'DEAL_WON') $cnt_deal++;
    if ((string)$v['visit_date'] === date('Y-m-d')) $cnt_today++;
}
$total = count($visits);
$rate  = $total > 0 ? round($cnt_deal / $total * 100) : 0;
?>

<style>
.vt-badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:700;text-transform:uppercase}
.visit-card{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:12px;margin-bottom:8px;transition:box-shadow .15s}
.visit-card:hover{box-shadow:0 2px 12px rgba(0,0,0,.3)}
.visit-card-head{display:flex;gap:10px;align-items:flex-start}
.visit-card-meta{font-size:11px;color:#64748b;margin-top:3px}
.summary-strip{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.strip-box{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:8px 14px;text-align:center;min-width:90px}
.strip-val{font-size:20px;font-weight:800;color:#fff}
.strip-lbl{font-size:10px;color:#64748b;text-transform:uppercase}
.gps-btn{font-size:11px;padding:3px 8px;cursor:pointer}
.visit-photo-thumb{margin-top:7px;display:inline-block;border:1px solid rgba(255,255,255,.14);border-radius:8px;overflow:hidden;background:#0f172a}
.visit-photo-thumb img{display:block;width:140px;max-height:105px;object-fit:cover}
</style>

<div class="rmi-card">
  <div class="rmi-card-header d-flex flex-wrap gap-2 justify-content-between align-items-start">
    <div>
      <h5><?=rmi_icon('target')?> Kunjungan Customer</h5>
      <div class="sub">Catat setiap kunjungan — Staff &amp; Manager wajib mengisi</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-sm btn-outline-light" href="<?= e(url_mpr('mpr_visits.php?export=1&type='.urlencode($f_type).'&month='.urlencode($f_month).'&who='.urlencode($f_who))) ?>"><?=rmi_icon('outbox')?> Export CSV</a>
    </div>
  </div>
  <div class="rmi-card-body">

    <!-- Summary Strip -->
    <div class="summary-strip">
      <div class="strip-box"><div class="strip-val"><?= $total ?></div><div class="strip-lbl">Total <?= $f_month ?></div></div>
      <div class="strip-box" style="border-top:3px solid #14b8a6"><div class="strip-val" style="color:#14b8a6"><?= $cnt_new ?></div><div class="strip-lbl">Prospek Baru</div></div>
      <div class="strip-box" style="border-top:3px solid #f59e0b"><div class="strip-val" style="color:#f59e0b"><?= $cnt_fu ?></div><div class="strip-lbl">Follow-up</div></div>
      <div class="strip-box" style="border-top:3px solid #22c55e"><div class="strip-val" style="color:#22c55e"><?= $cnt_deal ?></div><div class="strip-lbl">Deal Won</div></div>
      <div class="strip-box" style="border-top:3px solid #ef4444"><div class="strip-val" style="color:#ef4444"><?= $rate ?>%</div><div class="strip-lbl">Conversion</div></div>
      <div class="strip-box" style="border-top:3px solid #6366f1"><div class="strip-val" style="color:#6366f1"><?= $cnt_today ?></div><div class="strip-lbl">Hari Ini</div></div>
    </div>

    <!-- Filter -->
    <form class="row g-2 mb-3" method="get">
      <div class="col-md-2">
        <label class="mini">Tipe</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">Semua</option>
          <?php foreach (MPR_VISIT_TYPES as $k => $vt): ?>
            <option value="<?= e($k) ?>" <?= $f_type===$k?'selected':'' ?>><?= e($vt['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="mini">Bulan</label>
        <input name="month" type="month" class="form-control form-control-sm" value="<?= e($f_month) ?>">
      </div>
      <div class="col-md-3">
        <label class="mini">Cari Customer / Lokasi</label>
        <input name="q" class="form-control form-control-sm" value="<?= e($f_q) ?>" placeholder="nama customer / lokasi">
      </div>
      <?php if ($MPR_VISITS_ALL_OFFICE_VIEW): ?>
      <div class="col-md-2">
        <label class="mini">Visitor</label>
        <input name="who" class="form-control form-control-sm" value="<?= e($f_who) ?>" placeholder="username">
      </div>
      <?php endif; ?>
      <div class="col-md-2 d-flex align-items-end gap-2">
        <button class="btn btn-sm btn-primary">Filter</button>
        <a class="btn btn-sm btn-outline-light" href="<?= e(url_mpr('mpr_visits.php')) ?>">Reset</a>
      </div>
    </form>

    <div class="row g-3">
      <!-- Form Catat Kunjungan -->
      <div class="col-lg-4">
        <div class="p-3 rounded-3" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1)">
          <div class="mini mb-1 fw-semibold"><?= $edit ? rmi_icon('memo').' Edit Kunjungan' : rmi_icon('memo').' Catat Kunjungan Baru' ?></div>
          <div class="mini mb-3" style="color:#64748b">Wajib diisi oleh Staff dan Manager.</div>

          <?php if ($can_create): ?>
          <form method="post" id="formVisit" enctype="multipart/form-data">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)min(8 * 1024 * 1024, mpr_visits_upload_max_bytes()) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
            <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

            <!-- GPS hidden -->
            <input type="hidden" name="gps_lat" id="gpsLat" value="<?= e($edit['gps_lat'] ?? '') ?>">
            <input type="hidden" name="gps_lng" id="gpsLng" value="<?= e($edit['gps_lng'] ?? '') ?>">
            <input type="hidden" name="gps_accuracy_m" id="gpsAcc" value="<?= e($edit['gps_accuracy_m'] ?? '') ?>">

            <div class="mb-2">
              <label class="mini">Tanggal Kunjungan <span style="color:#ef4444">*</span></label>
              <input class="form-control form-control-sm" type="date" name="visit_date" value="<?= e($edit['visit_date'] ?? date('Y-m-d')) ?>" required>
            </div>

            <div class="mb-2">
              <label class="mini">Plan yang Dikunjungi <span style="color:#ef4444">*</span></label>
              <select class="form-select form-select-sm" name="plan_id" id="visitPlanSelect" required>
                <option value="">-- Pilih Plan --</option>
                <?php foreach ($plans as $pl): ?>
                  <option value="<?= (int)$pl['id'] ?>"
                          data-customer-id="<?= (int)($pl['customer_id'] ?? 0) ?>"
                          data-pic-id="<?= (int)($pl['pic_id'] ?? 0) ?>"
                          <?= (int)($edit['plan_id']??0)===(int)$pl['id']?'selected':'' ?>>
                    <?= e($pl['plan_code']) ?> — <?= e(mb_strimwidth((string)$pl['title'],0,35,'…')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="mini">Customer / Rumah Sakit <span style="color:#ef4444">*</span></label>
              <select class="form-select form-select-sm" name="customer_id" id="visitCustomerSelect" required>
                <option value="0">-- Pilih Plan terlebih dahulu --</option>
                <?php foreach ($customers as $cust):
                  $cid=(int)($cust['id']??0); $cName=trim((string)($cust['customers_name']??''));
                  if ($cid<=0 || $cName==='') continue;
                  $cCode=trim((string)($cust['customers_code']??'')); $cOffice=trim((string)($cust['office_code']??''));
                  $label=trim(($cCode!==''?$cCode.' — ':'').$cName.($cOffice!==''?' — '.$cOffice:''));
                ?>
                  <option value="<?= $cid ?>" <?= (int)($edit['customer_id']??0)===$cid?'selected':'' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="mini mt-1" style="color:#64748b">Customer mengikuti Plan dan tidak boleh berpindah ke customer lain.</div>
            </div>

            <div class="mb-2">
              <label class="mini">Tipe Kunjungan</label>
              <select class="form-select form-select-sm" name="visit_type" id="visitTypeSelect">
                <?php foreach (MPR_VISIT_TYPES as $k => $vt): ?>
                  <option value="<?= e($k) ?>" <?= strtoupper((string)($edit['visit_type']??'NEW_PROSPECT'))===$k?'selected':'' ?>>
                    <?= e($vt['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="mini">PIC Customer yang Dikunjungi</label>
              <select class="form-select form-select-sm" name="pic_id" id="visitPicSelect">
                <option value="0" data-customer-id="0">-- Belum ditentukan --</option>
                <?php foreach ($visitPics as $pic):
                  $pid=(int)($pic['id']??0); $pcid=(int)($pic['customer_id']??0);
                  $pn=trim((string)($pic['contact_name']??'')); if($pid<=0||$pn==='') continue;
                  $pr=trim((string)($pic['role_title']??''));
                  $label=$pn.($pr!==''?' — '.$pr:'');
                ?>
                  <option value="<?= $pid ?>" data-customer-id="<?= $pcid ?>" <?= (int)($edit['pic_id']??0)===$pid?'selected':'' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="mini mt-1" style="color:#64748b">Boleh mengganti PIC Plan ke PIC lain hanya jika masih dalam Customer/RS yang sama (mis. ATEM ↔ JANGMED).</div>
            </div>

            <div class="row g-1 mb-2">
              <div class="col-7">
                <label class="mini">Lokasi / Alamat</label>
                <input class="form-control form-control-sm" name="location" value="<?= e($edit['location'] ?? '') ?>" placeholder="Alamat / nama gedung">
              </div>
              <div class="col-5">
                <label class="mini">Kota</label>
                <input class="form-control form-control-sm" name="visit_city" value="<?= e($edit['visit_city'] ?? '') ?>" placeholder="Bogor, Bandung...">
              </div>
            </div>

            <div class="mb-2">
              <label class="mini">Foto Kunjungan <span style="color:#ef4444">*</span></label>
              <?php if ($edit && !empty($edit['photo_path'])): ?>
                <div class="mb-1">
                  <a href="<?= e(mpr_visits_photo_url((string)$edit['photo_path'])) ?>" target="_blank" style="color:#38bdf8"><?=rmi_icon('doc')?> Lihat foto tersimpan</a>
                </div>
              <?php endif; ?>
              <input class="form-control form-control-sm" type="file" name="visit_photo" id="visitPhoto" accept="image/*" <?= $edit && !empty($edit['photo_path']) ? '' : 'required' ?>>
              <div class="mini mt-1" style="color:#94a3b8">
                Foto wajib sebagai bukti kunjungan. Format JPG/PNG/WEBP/HEIC, maksimal <?= e(mpr_visits_format_bytes(min(8 * 1024 * 1024, mpr_visits_upload_max_bytes()))) ?>.
                <?= $edit && !empty($edit['photo_path']) ? 'Kosongkan jika tidak ingin mengganti foto.' : '' ?>
              </div>
            </div>

            <!-- GPS Capture: gunakan halaman khusus saja -->
            <div class="mb-2">
              <a class="btn btn-sm btn-outline-info gps-btn w-100" target="_blank" rel="noopener" href="mpr_gps_capture.php?return=mpr_visits.php&target=mpr_visits">
                Buka GPS Realtime Khusus
              </a>
              <div id="gpsStatus" class="mini mt-1" style="color:#64748b">
                Buka GPS Realtime Khusus, ambil lokasi, lalu klik Kirim GPS ke Form.
                GPS wajib dan akurasi harus <= 250 m. Link Maps laporan akan memakai nama customer, bukan hanya angka koordinat.
              </div>
            </div>

            <div class="mb-2">
              <label class="mini">Hasil / Kesimpulan Kunjungan</label>
              <textarea class="form-control form-control-sm" name="result" rows="2" placeholder="Apa yang dibahas? Apa hasilnya?"><?= e($edit['result'] ?? '') ?></textarea>
            </div>

            <div class="mb-2">
              <label class="mini">Outcome / Status Tindak Lanjut</label>
              <select class="form-select form-select-sm" name="outcome">
                <?php foreach (MPR_OUTCOMES as $k => $oc): ?>
                  <option value="<?= e($k) ?>" <?= strtoupper((string)($edit['outcome']??'PENDING'))===$k?'selected':'' ?>>
                    <?= e($oc['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row g-1 mb-2" id="followupRow">
              <div class="col-6">
                <label class="mini">Jadwal Follow-Up Berikutnya</label>
                <input class="form-control form-control-sm" type="date" name="next_followup_date" value="<?= e($edit['next_followup_date'] ?? '') ?>">
              </div>
              <div class="col-6">
                <label class="mini">Estimasi Nilai Deal (Rp)</label>
                <input class="form-control form-control-sm" name="est_deal_value" id="estDealInput" value="<?= e($edit['est_deal_value'] ?? '') ?>" placeholder="contoh: 5000000">
                <div class="mini mt-1" id="estDealPreview" style="color:#22c55e"></div>
              </div>
            </div>

            <div class="mb-2">
              <label class="mini">Catatan Tambahan</label>
              <textarea class="form-control form-control-sm" name="notes" rows="2"><?= e($edit['notes'] ?? '') ?></textarea>
            </div>

            <div class="d-flex gap-2 mt-2">
              <button class="btn btn-primary btn-sm flex-fill"><?=rmi_icon('doc')?> Simpan</button>
              <?php if ($edit): ?>
                <a class="btn btn-outline-light btn-sm" href="<?= e(url_mpr('mpr_visits.php')) ?>">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
          <?php else: ?>
            <div class="mini" style="color:#f59e0b">Tidak ada izin catat kunjungan.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Daftar Kunjungan -->
      <div class="col-lg-8">
        <?php if (empty($visits)): ?>
          <div style="text-align:center;padding:40px;color:var(--rmi-muted)">
            <div style="font-size:40px"><?=rmi_icon('target')?></div>
            <div class="mini mt-2">Belum ada kunjungan tercatat untuk periode ini.</div>
            <div class="mini">Isi form di sebelah kiri untuk mencatat kunjungan pertama.</div>
          </div>
        <?php else: ?>
          <?php foreach ($visits as $v):
            $vt = MPR_VISIT_TYPES[$v['visit_type']] ?? ['label'=>$v['visit_type'],'color'=>'#64748b'];
            $oc = MPR_OUTCOMES[$v['outcome']] ?? ['label'=>$v['outcome'],'color'=>'#64748b'];
            $isToday = (string)$v['visit_date'] === date('Y-m-d');
            $isDeal  = $v['outcome'] === 'DEAL_WON';
            $isNew   = $v['visit_type'] === 'NEW_PROSPECT';
            $hasFollowup = !empty($v['next_followup_date']);
            $fuOverdue = $hasFollowup && $v['next_followup_date'] < date('Y-m-d') && !$isDeal;
          ?>
          <div class="visit-card" style="<?= $isDeal ? 'border-left:3px solid #22c55e' : ($fuOverdue ? 'border-left:3px solid #ef4444' : ($isNew ? 'border-left:3px solid #14b8a6' : '')) ?>">
            <div class="visit-card-head">
              <div style="flex:1">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                  <span class="vt-badge" style="background:<?= e($vt['color']) ?>22;color:<?= e($vt['color']) ?>;border:1px solid <?= e($vt['color']) ?>44"><?= e($vt['label']) ?></span>
                  <span class="vt-badge" style="background:<?= e($oc['color']) ?>22;color:<?= e($oc['color']) ?>;border:1px solid <?= e($oc['color']) ?>44"><?= e($oc['label']) ?></span>
                  <?php if ($isToday): ?><span class="vt-badge" style="background:#6366f122;color:#818cf8">HARI INI</span><?php endif; ?>
                  <?php if ($fuOverdue): ?><span class="vt-badge" style="background:#ef444422;color:#f87171"><?=rmi_icon('warn')?> FOLLOW-UP OVERDUE</span><?php endif; ?>
                </div>
                <div style="font-weight:700;color:#fff;margin-top:5px;font-size:14px">
                  <?= e($v['customer_name']) ?>
                  <?php if (!empty($v['visit_city'])): ?><span style="font-size:11px;color:#64748b"> • <?= e($v['visit_city']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($v['contact_name'])): ?>
                  <div class="visit-card-meta">Kontak: <?= e($v['contact_name']) ?><?= $v['contact_role_title'] ? ' (' . e($v['contact_role_title']) . ')' : '' ?></div>
                <?php endif; ?>
                <div class="visit-card-meta">
                  <?=rmi_icon('calendar')?> <?= e($v['visit_date']) ?>
                  • <?=rmi_icon('user')?> <strong><?= e($v['visitor_username'] ?: $v['created_by']) ?></strong>
                  <?php if (!empty($v['visitor_level'])): ?>
                    <span style="text-transform:uppercase;font-size:10px;color:#f59e0b">(<?= e($v['visitor_level']) ?>)</span>
                  <?php endif; ?>
                  • <?=rmi_icon('clipboard')?> <?= e($v['plan_code']) ?>
                </div>
                <?php if (!empty($v['result'])): ?>
                  <div class="mini mt-1" style="color:#94a3b8"><?=rmi_icon('memo')?> <?= e(mb_strimwidth((string)$v['result'],0,120,'…')) ?></div>
                <?php endif; ?>
                <?php if (!empty($v['photo_path'])): ?>
                  <div class="visit-photo-thumb">
                    <a href="<?= e(mpr_visits_photo_url((string)$v['photo_path'])) ?>" target="_blank" title="Lihat foto kunjungan">
                      <img src="<?= e(mpr_visits_photo_url((string)$v['photo_path'])) ?>" alt="Foto kunjungan <?= e($v['customer_name']) ?>">
                    </a>
                  </div>
                <?php else: ?>
                  <div class="mini mt-1" style="color:#f59e0b"><?=rmi_icon('doc')?> Foto kunjungan belum ada.</div>
                <?php endif; ?>
                <?php if ($hasFollowup): ?>
                  <div class="mini mt-1" style="color:<?= $fuOverdue ? '#f87171' : '#fbbf24' ?>">
                    <?=rmi_icon('refresh')?> Follow-up: <?= e($v['next_followup_date']) ?><?= $fuOverdue ? ' '.rmi_icon('warn').' Overdue!' : '' ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($v['est_deal_value'])): ?>
                  <div class="mini mt-1" style="color:#22c55e"><?=rmi_icon('money')?> Est. Deal: Rp <?= number_format((float)$v['est_deal_value'],0,',','.') ?></div>
                <?php endif; ?>
                <?php if (!empty($v['gps_lat']) && !empty($v['gps_lng'])): ?>
                  <div class="mini mt-1">
                    <?php
                    $mapLabel = trim((string)($v['customer_name'] ?? ''));
                    $mapCity  = trim((string)($v['visit_city'] ?? ''));
                    $mapLoc   = trim((string)($v['location'] ?? ''));
                    $mapText  = $mapLabel !== '' ? $mapLabel : 'Lokasi Kunjungan';
                    $mapQueryParts = [];
                    if ($mapLabel !== '') $mapQueryParts[] = $mapLabel;
                    if ($mapCity !== '')  $mapQueryParts[] = $mapCity;
                    if ($mapLoc !== '')   $mapQueryParts[] = $mapLoc;
                    $mapQueryParts[] = trim((string)$v['gps_lat']) . ',' . trim((string)$v['gps_lng']);
                    $mapQuery = implode(' ', array_filter($mapQueryParts));
                  ?>
                    <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= e(rawurlencode($mapQuery)) ?>" target="_blank" style="color:#3b82f6"><?=rmi_icon('target')?> Lihat di Maps — <?= e($mapText) ?></a>
                  </div>
                <?php endif; ?>
              </div>
              <div class="d-flex flex-column gap-1">
                <?php if ($MPR_IS_ADMIN || (string)$v['visitor_username'] === $MPR_USER['username']): ?>
                  <a class="btn btn-xs btn-outline-light" href="<?= e(url_mpr('mpr_visits.php?edit='.(int)$v['id'])) ?>"><?=rmi_icon('memo')?></a>
                  <?php if ($can_delete): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Hapus kunjungan ini?')">
                    <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                    <button class="btn btn-xs btn-outline-danger"><?=rmi_icon('x')?></button>
                  </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<style>.btn-xs{padding:2px 7px;font-size:11px;border-radius:5px}</style>
<script>
(function(){
  var gpsFreshAt = 0;

  function storageGet(key) {
    var v = '';
    try { v = localStorage.getItem(key) || ''; } catch(e) {}
    if (!v) {
      try { v = sessionStorage.getItem(key) || ''; } catch(e) {}
    }
    return String(v || '').trim();
  }

  function cookieGet(key) {
    try {
      var m = document.cookie.match(new RegExp('(?:^|; )' + key.replace(/[.$?*|{}()\[\]\\/+^]/g, '\\$&') + '=([^;]*)'));
      return m ? decodeURIComponent(m[1]) : '';
    } catch(e) {
      return '';
    }
  }

  function firstValue(keys) {
    for (var i = 0; i < keys.length; i++) {
      var v = storageGet(keys[i]);
      if (v !== '') return v;
      v = cookieGet(keys[i]);
      if (v !== '') return v;
    }
    return '';
  }

  function parseJsonPayload(raw) {
    if (!raw) return {};
    try {
      var obj = JSON.parse(raw);
      if (obj && typeof obj === 'object') return obj;
    } catch(e) {}
    return {};
  }

  function normalizeGpsPayload(obj) {
    if (!obj || typeof obj !== 'object') return {lat:'', lng:'', acc:''};

    var lat = obj.lat ?? obj.latitude ?? obj.gps_lat ?? obj.gpsLat ?? obj.mpr_gps_lat ?? '';
    var lng = obj.lng ?? obj.lon ?? obj.longitude ?? obj.gps_lng ?? obj.gpsLng ?? obj.mpr_gps_lng ?? '';
    var acc = obj.acc ?? obj.accuracy ?? obj.gps_acc ?? obj.gpsAcc ?? obj.gps_accuracy_m ?? obj.mpr_gps_acc ?? '';

    return {
      lat: String(lat || '').trim(),
      lng: String(lng || '').trim(),
      acc: String(acc || '').trim()
    };
  }

  function readGpsFromStorage() {
    var lat = firstValue([
      'mpr_gps_lat','mprGpsLat','gps_lat','gpsLat',
      'mpr_visit_gps_lat','mprVisitGpsLat','last_gps_lat',
      'mprLat','visit_lat','visitGpsLat','gpsLatitude'
    ]);
    var lng = firstValue([
      'mpr_gps_lng','mprGpsLng','gps_lng','gpsLng',
      'mpr_visit_gps_lng','mprVisitGpsLng','last_gps_lng',
      'mpr_gps_lon','gps_lon','gpsLon',
      'mprLng','mprLon','visit_lng','visit_lon','visitGpsLng','gpsLongitude'
    ]);
    var acc = firstValue([
      'mpr_gps_acc','mprGpsAcc','gps_acc','gpsAcc',
      'mpr_gps_accuracy_m','gps_accuracy_m','mpr_visit_gps_acc',
      'accuracy','last_gps_acc',
      'mprAcc','visit_acc','visitGpsAcc','gpsAccuracy'
    ]);

    var payloadKeys = [
      'mpr_gps_last','mprGpsLast','mpr_gps_data','mprGpsData',
      'gps_data','gpsData','mpr_realtime_gps','mprGpsRealtime',
      'mpr_visit_gps','mprVisitGps'
    ];

    for (var i = 0; i < payloadKeys.length; i++) {
      var payload = normalizeGpsPayload(parseJsonPayload(storageGet(payloadKeys[i])));
      if (!lat && payload.lat) lat = payload.lat;
      if (!lng && payload.lng) lng = payload.lng;
      if (!acc && payload.acc) acc = payload.acc;
    }

    return {lat: lat, lng: lng, acc: acc};
  }

  function readGpsFromUrl() {
    var q = new URLSearchParams(window.location.search);
    var h = new URLSearchParams((window.location.hash || '').replace(/^#/, ''));

    function getAny(names) {
      for (var i = 0; i < names.length; i++) {
        var v = q.get(names[i]) || h.get(names[i]) || '';
        if (String(v).trim() !== '') return String(v).trim();
      }
      return '';
    }

    return {
      lat: getAny(['gps_lat','lat','mpr_gps_lat']),
      lng: getAny(['gps_lng','lng','lon','longitude','mpr_gps_lng']),
      acc: getAny(['gps_acc','acc','accuracy','gps_accuracy_m','mpr_gps_acc'])
    };
  }

  function setGpsStatus(message, type) {
    var gpsStatus = document.getElementById('gpsStatus');
    if (!gpsStatus) return;
    gpsStatus.textContent = message;
    if (type === 'ok') gpsStatus.style.color = '#22c55e';
    else if (type === 'danger') gpsStatus.style.color = '#f87171';
    else if (type === 'warning') gpsStatus.style.color = '#f59e0b';
    else gpsStatus.style.color = '#38bdf8';
  }

  function applyGps(gps, source) {
    if (!gps || !gps.lat || !gps.lng) return false;

    var gpsLat = document.getElementById('gpsLat');
    var gpsLng = document.getElementById('gpsLng');
    var gpsAcc = document.getElementById('gpsAcc');

    if (gpsLat) gpsLat.value = gps.lat;
    if (gpsLng) gpsLng.value = gps.lng;
    if (gpsAcc && gps.acc) gpsAcc.value = gps.acc;

    var msg = 'GPS realtime khusus sudah masuk';
    if (source) msg += ' dari ' + source;
    msg += ': ' + gps.lat + ', ' + gps.lng;
    if (gps.acc) msg += ' (akurasi ' + gps.acc + ' m)';
    msg += '.';

    gpsFreshAt = Date.now();
    setGpsStatus(msg, 'ok');
    return true;
  }

  function loadGpsFromSpecialPage() {
    var fromUrl = readGpsFromUrl();
    if (applyGps(fromUrl, 'URL')) return true;

    var fromStorage = readGpsFromStorage();
    if (applyGps(fromStorage, 'halaman khusus')) return true;

    setGpsStatus('GPS belum masuk. Klik Buka GPS Realtime Khusus, ambil lokasi, lalu klik Kirim GPS ke Form.', 'warning');
    return false;
  }

  // Terima data GPS jika halaman khusus memakai window.opener.postMessage().
  window.addEventListener('message', function(ev){
    if (!ev || !ev.data) return;

    var data = ev.data;
    if (typeof data === 'string') {
      try { data = JSON.parse(data); } catch(e) {}
    }

    if (data && typeof data === 'object') {
      var type = String(data.type || data.event || '').toLowerCase();
      if (type.indexOf('gps') !== -1 || data.lat || data.latitude || data.gps_lat) {
        applyGps(normalizeGpsPayload(data), 'popup GPS');
      }
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    // Hapus tombol lama bila masih tertinggal dari cache/HTML lama.
    var oldBtnGps = document.getElementById('btnGps');
    var oldBtnStop = document.getElementById('btnGpsStop');
    if (oldBtnGps) oldBtnGps.style.display = 'none';
    if (oldBtnStop) oldBtnStop.style.display = 'none';

    loadGpsFromSpecialPage();

    window.addEventListener('focus', loadGpsFromSpecialPage);
    window.addEventListener('storage', loadGpsFromSpecialPage);
    document.addEventListener('visibilitychange', function(){
      if (!document.hidden) loadGpsFromSpecialPage();
    });
    setInterval(loadGpsFromSpecialPage, 1500);

    var formVisit = document.getElementById('formVisit');
    if (formVisit) {
      formVisit.addEventListener('submit', function(e) {
        loadGpsFromSpecialPage();

        var gpsLat = document.getElementById('gpsLat');
        var gpsLng = document.getElementById('gpsLng');
        var gpsAcc = document.getElementById('gpsAcc');
        var accVal = gpsAcc && gpsAcc.value !== '' ? parseFloat(gpsAcc.value) : 999999;

        var photo = document.getElementById('visitPhoto');
        if (photo && photo.hasAttribute('required') && (!photo.files || photo.files.length === 0)) {
          e.preventDefault();
          alert('Foto kunjungan wajib diupload.');
          return false;
        }

        if (photo && photo.files && photo.files.length > 0) {
          var maxPhotoSize = <?= (int)min(8 * 1024 * 1024, mpr_visits_upload_max_bytes()) ?>;
          if (photo.files[0].size > maxPhotoSize) {
            e.preventDefault();
            alert('Ukuran foto terlalu besar. Maksimal <?= e(mpr_visits_format_bytes(min(8 * 1024 * 1024, mpr_visits_upload_max_bytes()))) ?>. Silakan kompres foto lalu upload ulang.');
            return false;
          }
        }

        if (!gpsLat || !gpsLng || gpsLat.value === '' || gpsLng.value === '') {
          e.preventDefault();
          alert('GPS realtime wajib diambil. Klik Buka GPS Realtime Khusus, ambil lokasi, lalu klik Kirim GPS ke Form.');
          return false;
        }

        if (accVal > 250) {
          e.preventDefault();
          alert('Akurasi GPS masih ' + Math.round(accVal) + ' m. Ambil GPS realtime khusus ulang sampai <= 250 m.');
          return false;
        }
      });
    }

    var estInput = document.getElementById('estDealInput');
    var estPrev  = document.getElementById('estDealPreview');
    if (estInput && estPrev) {
      estInput.addEventListener('input', function () {
        var cleaned = this.value.replace(/[^0-9.]/g, '');
        var n = parseFloat(cleaned);
        estPrev.textContent = isNaN(n) ? '' : '= Rp ' + n.toLocaleString('id-ID');
      });
    }
  });
})();
</script>


<script>
// Sinkronisasi Plan -> Customer -> PIC.
// Customer dikunci mengikuti Plan. PIC hanya menampilkan PIC milik customer yang sama.
(function(){
  var plan = document.getElementById('visitPlanSelect');
  var customer = document.getElementById('visitCustomerSelect');
  var pic = document.getElementById('visitPicSelect');
  if (!plan || !customer || !pic) return;

  function filterPics(customerId, preferredPicId) {
    Array.prototype.forEach.call(pic.options, function(opt){
      var ocid = opt.getAttribute('data-customer-id') || '0';
      var show = opt.value === '0' || (customerId !== '0' && ocid === customerId);
      opt.hidden = !show;
      opt.disabled = !show;
    });
    if (preferredPicId && preferredPicId !== '0') {
      var target = Array.prototype.find.call(pic.options, function(o){ return o.value === preferredPicId && !o.disabled; });
      if (target) pic.value = preferredPicId;
    }
    if (pic.options[pic.selectedIndex] && pic.options[pic.selectedIndex].disabled) pic.value = '0';
  }

  function syncFromPlan(forcePic) {
    var opt = plan.options[plan.selectedIndex];
    if (!opt || !opt.value) {
      customer.value = '0';
      customer.disabled = true;
      filterPics('0','0');
      return;
    }
    var cid = opt.getAttribute('data-customer-id') || '0';
    var pid = opt.getAttribute('data-pic-id') || '0';
    customer.value = cid;
    customer.disabled = true;
    filterPics(cid, forcePic ? pid : (pic.value || pid));
  }

  // disabled select tidak ikut POST; mirror customer_id ke hidden input.
  var hiddenCustomer = document.createElement('input');
  hiddenCustomer.type = 'hidden';
  hiddenCustomer.name = 'customer_id';
  hiddenCustomer.id = 'visitCustomerHidden';
  customer.removeAttribute('name');
  customer.parentNode.appendChild(hiddenCustomer);

  function syncHidden(){
    hiddenCustomer.value = customer.value || '0';
  }

  plan.addEventListener('change', function(){ syncFromPlan(true); syncHidden(); });
  pic.addEventListener('change', syncHidden);
  syncFromPlan(false);
  syncHidden();
})();
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
