<?php
declare(strict_types=1);

/**
 * RMI ERP — HRL Process Tower (Pengajuan ke HRL)
 * EnterprisePPP (Fase 1-3): CRUD + Filter/Export + Attachment + GPS+Photo + Workflow + Audit + TTD digital (confirm password/PIN)
 *
 * Catatan: file ini sengaja memulai output-buffer SEPALING AWAL untuk mencegah "headers already sent".
 */

// --- Output buffer START (very early) ---
if (!ob_get_level()) { @ob_start(); }

// --- Base path (auto-detect; safe even if folder accidentally double) ---
$__script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$__dir = rtrim(dirname($__script), '/');
if ($__dir === '/') { $__dir = ''; }
$BASE_HRLP = $__dir; // base folder of this module as accessed by browser

$pos = strpos($__script, '/hrl_process');
if ($pos === false) {
    $BASE_PROJECT = '';
} else {
    // take everything before the FIRST /hrl_process occurrence
    $BASE_PROJECT = rtrim(substr($__script, 0, $pos), '/');
}
if ($BASE_PROJECT === '/') { $BASE_PROJECT = ''; }

// --- Auth / DB ---
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/hrlp_process_rbac.php';
require_login();

// ACCESS FIX:
    // Manager/MGR/Admin/SYS lintas cabang/kantor boleh membuka HRL Process.
    // Gate require_any_permission(hrlp_process_menu_permissions()) dinonaktifkan di bootstrap
    // agar tidak memaksa HRL.REQ_CUTI_VIEW sebelum halaman berjalan.
    // Permission detail tetap diamankan oleh master/auth.php dan fungsi aksi.

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('db_pdo')) {
    // fallback (should not happen in ERP RMI)
    function db_pdo(): PDO { throw new RuntimeException('db_pdo() tidak ditemukan. Pastikan master/auth.php tersedia.'); }
}

$pdo = db_pdo();

// --- Schema ensure ---
require_once __DIR__ . '/schema.php';
hrlp_schema_ensure($pdo);


// --- Overtime detail schema (non-destructive; khusus tipe LEMBUR) ---
if (!function_exists('hrlp_overtime_schema_ensure')) {
    function hrlp_overtime_schema_ensure(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_request_overtime (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            overtime_start_at DATETIME NOT NULL,
            overtime_end_at DATETIME NOT NULL,
            break_minutes INT NOT NULL DEFAULT 0,
            duration_minutes INT NOT NULL DEFAULT 0,
            work_description TEXT NULL,
            payroll_period CHAR(7) NULL,
            payroll_processed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_overtime_request (request_id),
            KEY idx_overtime_period (payroll_period),
            KEY idx_overtime_start (overtime_start_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
if (!function_exists('hrlp_overtime_calculate')) {
    function hrlp_overtime_calculate(string $date, string $startTime, string $endTime, int $breakMinutes=0): array {
        $date = trim($date); $startTime = trim($startTime); $endTime = trim($endTime);
        if ($date === '' || $startTime === '' || $endTime === '') {
            throw new RuntimeException('Tanggal, jam mulai, dan jam selesai lembur wajib diisi.');
        }
        $start = new DateTime($date . ' ' . $startTime);
        $end = new DateTime($date . ' ' . $endTime);
        if ($end <= $start) $end->modify('+1 day');
        $breakMinutes = max(0, min(720, $breakMinutes));
        $gross = (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60);
        $net = $gross - $breakMinutes;
        if ($net <= 0) throw new RuntimeException('Durasi lembur bersih harus lebih dari 0 menit.');
        if ($net > 1440) throw new RuntimeException('Durasi lembur maksimum 24 jam per pengajuan.');
        return [
            'start_at'=>$start->format('Y-m-d H:i:s'),
            'end_at'=>$end->format('Y-m-d H:i:s'),
            'break_minutes'=>$breakMinutes,
            'duration_minutes'=>$net,
            'payroll_period'=>$start->format('Y-m'),
        ];
    }
}
if (!function_exists('hrlp_overtime_upsert')) {
    function hrlp_overtime_upsert(PDO $pdo, int $requestId, array $data, string $workDescription=''): void {
        hrlp_overtime_schema_ensure($pdo);
        $st=$pdo->prepare("INSERT INTO hrl_request_overtime
            (request_id,overtime_start_at,overtime_end_at,break_minutes,duration_minutes,work_description,payroll_period,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE overtime_start_at=VALUES(overtime_start_at), overtime_end_at=VALUES(overtime_end_at),
            break_minutes=VALUES(break_minutes), duration_minutes=VALUES(duration_minutes), work_description=VALUES(work_description),
            payroll_period=VALUES(payroll_period), updated_at=NOW()");
        $st->execute([$requestId,$data['start_at'],$data['end_at'],$data['break_minutes'],$data['duration_minutes'],$workDescription,$data['payroll_period']]);
    }
}
if (!function_exists('hrlp_overtime_get')) {
    function hrlp_overtime_get(PDO $pdo, int $requestId): array {
        hrlp_overtime_schema_ensure($pdo);
        $st=$pdo->prepare("SELECT * FROM hrl_request_overtime WHERE request_id=? LIMIT 1");
        $st->execute([$requestId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
hrlp_overtime_schema_ensure($pdo);

// --- Staffing request detail schema (non-destructive; khusus PERMINTAAN_KARYAWAN) ---
if (!function_exists('hrlp_staffing_schema_ensure')) {
    function hrlp_staffing_schema_ensure(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_request_staffing (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            position_name VARCHAR(150) NOT NULL,
            division_name VARCHAR(100) NOT NULL,
            work_location VARCHAR(150) NOT NULL,
            headcount INT NOT NULL DEFAULT 1,
            expected_start_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_staffing_request (request_id),
            KEY idx_staffing_start (expected_start_date),
            KEY idx_staffing_division (division_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
if (!function_exists('hrlp_staffing_validate')) {
    function hrlp_staffing_validate(string $position, string $division, string $location, int $headcount, string $startDate): array {
        $position = trim($position);
        $division = strtoupper(trim($division));
        $location = trim($location);
        $headcount = max(1, $headcount);
        $startDate = trim($startDate);
        if ($position === '') throw new RuntimeException('Jabatan yang dibutuhkan wajib diisi.');
        if ($division === '') throw new RuntimeException('Divisi/departemen kebutuhan wajib diisi.');
        if ($location === '') throw new RuntimeException('Lokasi kerja wajib diisi.');
        if ($headcount > 999) throw new RuntimeException('Jumlah kebutuhan maksimum 999 orang.');
        $dt = DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$dt || $dt->format('Y-m-d') !== $startDate) throw new RuntimeException('Tanggal mulai kerja wajib diisi dengan format tanggal yang valid.');
        return [
            'position_name'=>$position,
            'division_name'=>$division,
            'work_location'=>$location,
            'headcount'=>$headcount,
            'expected_start_date'=>$startDate,
        ];
    }
}
if (!function_exists('hrlp_staffing_upsert')) {
    function hrlp_staffing_upsert(PDO $pdo, int $requestId, array $data): void {
        hrlp_staffing_schema_ensure($pdo);

        $st=$pdo->prepare("INSERT INTO hrl_request_staffing
            (request_id,position_name,division_name,work_location,headcount,expected_start_date,created_at,updated_at)
            VALUES (?,?,?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE position_name=VALUES(position_name), division_name=VALUES(division_name),
            work_location=VALUES(work_location), headcount=VALUES(headcount), expected_start_date=VALUES(expected_start_date), updated_at=NOW()");
        $st->execute([$requestId,$data['position_name'],$data['division_name'],$data['work_location'],$data['headcount'],$data['expected_start_date']]);
    }
}
if (!function_exists('hrlp_staffing_get')) {
    function hrlp_staffing_get(PDO $pdo, int $requestId): array {
        hrlp_staffing_schema_ensure($pdo);

        $st=$pdo->prepare("SELECT * FROM hrl_request_staffing WHERE request_id=? LIMIT 1");
        $st->execute([$requestId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
hrlp_staffing_schema_ensure($pdo);



// --- Sickness detail schema (non-destructive; khusus tipe SAKIT) ---
if (!function_exists('hrlp_sickness_schema_ensure')) {
    function hrlp_sickness_schema_ensure(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_request_sickness (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            day_type VARCHAR(20) NOT NULL DEFAULT 'FULL_DAY',
            general_condition VARCHAR(500) NULL,
            expected_return_date DATE NULL,
            examination_location VARCHAR(150) NULL,
            handover_work TEXT NULL,
            handover_to VARCHAR(150) NULL,
            contact_during_leave VARCHAR(50) NULL,
            emergency_flag TINYINT(1) NOT NULL DEFAULT 0,
            inpatient_flag TINYINT(1) NOT NULL DEFAULT 0,
            doctor_letter_required TINYINT(1) NOT NULL DEFAULT 0,
            final_payroll_status VARCHAR(40) NULL,
            validated_by VARCHAR(100) NULL,
            validated_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_sickness_request (request_id),
            KEY idx_sickness_return (expected_return_date),
            KEY idx_sickness_status (final_payroll_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
if (!function_exists('hrlp_sickness_validate')) {
    function hrlp_sickness_validate(array $src, ?string $startDate, ?string $endDate): array {
        $dayType = strtoupper(trim((string)($src['sick_day_type'] ?? 'FULL_DAY')));
        if (!in_array($dayType, ['FULL_DAY','HALF_DAY'], true)) $dayType = 'FULL_DAY';
        $condition = trim((string)($src['sick_general_condition'] ?? ''));
        $expected = trim((string)($src['sick_expected_return_date'] ?? ''));
        $location = trim((string)($src['sick_examination_location'] ?? ''));
        $handover = trim((string)($src['sick_handover_work'] ?? ''));
        $handoverTo = trim((string)($src['sick_handover_to'] ?? ''));
        $contact = trim((string)($src['sick_contact'] ?? ''));
        $emergency = !empty($src['sick_emergency']) ? 1 : 0;
        $inpatient = !empty($src['sick_inpatient']) ? 1 : 0;
        if (!$startDate) throw new RuntimeException('Tanggal mulai sakit wajib diisi.');
        if (!$endDate) $endDate = $startDate;
        if (strtotime($endDate) < strtotime($startDate)) throw new RuntimeException('Tanggal selesai sakit tidak boleh sebelum tanggal mulai.');
        if ($condition === '') throw new RuntimeException('Kondisi umum/keluhan singkat wajib diisi.');
        if ($expected === '') throw new RuntimeException('Perkiraan kembali bekerja wajib diisi.');
        if ($location === '') throw new RuntimeException('Lokasi pemeriksaan/perawatan wajib diisi.');
        if ($contact === '') throw new RuntimeException('Nomor kontak selama sakit wajib diisi.');
        $workDays = 0;
        $cur = new DateTime($startDate); $end = new DateTime($endDate);
        while ($cur <= $end) { if ((int)$cur->format('N') <= 5) $workDays++; $cur->modify('+1 day'); }
        $doctorRequired = ($workDays >= 2 || $inpatient === 1) ? 1 : 0;
        return compact('dayType','condition','expected','location','handover','handoverTo','contact','emergency','inpatient','doctorRequired');
    }
}
if (!function_exists('hrlp_sickness_upsert')) {
    function hrlp_sickness_upsert(PDO $pdo, int $requestId, array $d): void {
        hrlp_sickness_schema_ensure($pdo);
        $st=$pdo->prepare("INSERT INTO hrl_request_sickness
            (request_id,day_type,general_condition,expected_return_date,examination_location,handover_work,handover_to,contact_during_leave,emergency_flag,inpatient_flag,doctor_letter_required,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE day_type=VALUES(day_type),general_condition=VALUES(general_condition),expected_return_date=VALUES(expected_return_date),examination_location=VALUES(examination_location),handover_work=VALUES(handover_work),handover_to=VALUES(handover_to),contact_during_leave=VALUES(contact_during_leave),emergency_flag=VALUES(emergency_flag),inpatient_flag=VALUES(inpatient_flag),doctor_letter_required=VALUES(doctor_letter_required),updated_at=NOW()");
        $st->execute([$requestId,$d['dayType'],$d['condition'],$d['expected'],$d['location'],$d['handover'],$d['handoverTo'],$d['contact'],$d['emergency'],$d['inpatient'],$d['doctorRequired']]);
    }
}
if (!function_exists('hrlp_sickness_get')) {
    function hrlp_sickness_get(PDO $pdo, int $requestId): array {
        hrlp_sickness_schema_ensure($pdo);
        $st=$pdo->prepare("SELECT * FROM hrl_request_sickness WHERE request_id=? LIMIT 1");
        $st->execute([$requestId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
if (!function_exists('hrlp_sickness_finalize')) {
    function hrlp_sickness_finalize(PDO $pdo, int $requestId, string $username): void {
        hrlp_sickness_schema_ensure($pdo);
        $pdo->prepare("UPDATE hrl_request_sickness SET final_payroll_status='SAKIT_DIBAYAR', validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE request_id=?")
            ->execute([$username,$requestId]);
    }
}
hrlp_sickness_schema_ensure($pdo);

// --- Helpers: user context ---
function me_username(): string { return (string)($_SESSION['username'] ?? ''); }
function me_role(): string {
    $r = $_SESSION['role'] ?? (function_exists('current_user_role') ? current_user_role() : '');
    return strtoupper((string)$r);
}
function me_level(): string {
    $l = $_SESSION['level'] ?? (function_exists('current_user_level') ? current_user_level() : '');
    return strtoupper((string)$l);
}
function me_dept(): string {
    $d = $_SESSION['department'] ?? ($_SESSION['dept'] ?? '');
    return strtoupper((string)$d);
}
function me_office(): string {
    $o = $_SESSION['office_code'] ?? ($_SESSION['office'] ?? '');
    return strtoupper((string)$o);
}
function is_admin_owner(): bool {
    return in_array(me_role(), ['ADMIN','SYS'], true) || in_array(me_level(), ['ADMIN','SYS'], true);
}
function is_manager_like(): bool {
    return is_admin_owner() || in_array(me_role(), ['MANAGER'], true) || in_array(me_level(), ['MANAGER'], true);
}
function is_dept(string $dept): bool {
    return strtoupper($dept) === me_dept();
}

function hrlp_is_manager_or_admin_any_session(): bool {
    if (function_exists('auth_is_manager_or_sys_any') && auth_is_manager_or_sys_any()) {
        return true;
    }
    $vals = [];
    foreach (['username','role','level','department','dept','office_code'] as $k) {
        if (isset($_SESSION[$k])) $vals[] = strtoupper((string)$_SESSION[$k]);
    }
    $joined = implode('|', array_filter($vals));
    return str_contains($joined, 'MANAGER')
        || str_contains($joined, 'MGR')
        || str_contains($joined, 'ADMIN')
        || preg_match('/(^|[|_\\-])SYS([|_\\-]|$)/', $joined) === 1;
}


// --- Flash ---
function flash_set(string $msg, string $type='success'): void {
    $_SESSION['_flash'] = ['msg'=>$msg,'type'=>$type];
}
function flash_get(): array {
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return is_array($f) ? $f : [];
}

// --- CSRF ---
function csrf_check(): void {
    $tok = (string)($_POST['_csrf'] ?? $_POST['csrf_token'] ?? '');
    if (function_exists('verify_csrf')) {
        verify_csrf($tok);
        return;
    }
    if (!$tok || !hash_equals((string)($_SESSION['_csrf'] ?? ''), $tok)) {
        http_response_code(400);
        echo "<h3>CSRF token tidak valid</h3>";
        exit;
    }
}

// --- Redirect (header-safe + JS fallback) ---
function rmi_redirect(string $url): void {
    // bersihkan buffer biar header aman
    while (ob_get_level()) { @ob_end_clean(); }
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    // fallback kalau headers sudah terkirim
    $u = h($url);
    echo "<!doctype html><meta charset='utf-8'><meta http-equiv='refresh' content='0;url={$u}'>";
    echo "<script>location.href=" . json_encode($url) . ";</script>";
    echo "<a href='{$u}'>Lanjut</a>";
    exit;
}

// --- URL helpers ---
function base_project(): string {
    global $BASE_PROJECT;
    return (string)$BASE_PROJECT;
}
function base_hrlp(): string {
    global $BASE_HRLP;
    return (string)$BASE_HRLP;
}
/**
 * URL helper untuk modul ini (HRL Process).
 * Aman untuk kasus folder dobel (hrl_process/hrl_process) karena base diambil dari SCRIPT_NAME.
 * Contoh: um('tower.php'), um('my_pin.php')
 */
function um(string $path): string {
    $b = base_hrlp();
    $p = '/' . ltrim($path, '/');
    return ($b === '') ? $p : $b . $p;
}

function u(string $path): string {
    return base_project() . $path;
}

// --- Upload helpers ---
function hrlp_upload_root(): string {
    $root = realpath(__DIR__ . '/../../uploads');
    if (!$root) { $root = __DIR__ . '/../../uploads'; }
    if (!is_dir($root)) { @mkdir($root, 0775, true); }
    $p = $root . '/hrl_process';
    if (!is_dir($p)) { @mkdir($p, 0775, true); }
    return $p;
}
function hrlp_req_dir(int $reqId): string {
    $dir = hrlp_upload_root() . '/req_' . $reqId;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir;
}
function hrlp_audit_log_path(): string {
    $dir = hrlp_upload_root() . '/audit_logs';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir . '/audit_hrl_process.log';
}
function hrlp_audit(string $action, array $meta=[]): void {
    $row = [
        'ts' => date('c'),
        'by' => me_username(),
        'role' => me_role(),
        'level' => me_level(),
        'dept' => me_dept(),
        'office' => me_office(),
        'action' => $action,
        'meta' => $meta,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ];
    @file_put_contents(hrlp_audit_log_path(), json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

// --- Security: access to a request ---
function hrlp_can_view(array $req): bool {
    // HRL Process open-access:
    // Semua user login, semua level, semua cabang/kantor boleh melihat pengajuan HRL Process.
    return true;
}

// --- Verify password for TTD digital ---
function hrlp_verify_password(PDO $pdo, string $username, string $plain): bool {
    $stmt = $pdo->prepare("SELECT password_hash FROM master_system_login WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $hash = (string)($stmt->fetchColumn() ?: '');
    if (!$hash) return false;
    return password_verify($plain, $hash);
}

// --- Verify PIN (optional) ---
function hrlp_pin_is_set(PDO $pdo, string $username): bool {
    $stmt = $pdo->prepare("SELECT pin_hash FROM hrl_user_pins WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    return (bool)$stmt->fetchColumn();
}
function hrlp_verify_pin(PDO $pdo, string $username, string $pin): bool {
    $stmt = $pdo->prepare("SELECT pin_hash FROM hrl_user_pins WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $hash = (string)($stmt->fetchColumn() ?: '');
    if (!$hash) return false;
    return password_verify($pin, $hash);
}

// --- Determine if FIN is required ---
function hrlp_need_fin(array $req): bool {
    $type = strtoupper((string)($req['req_type'] ?? ''));
    // SAKIT tidak pernah melalui FIN. Status final ditetapkan HRL dan dibaca payroll.
    if ($type === 'SAKIT') return false;
    $amount = (float)($req['amount'] ?? 0);
    if ($amount > 0) return true;
    return in_array($type, ['LEMBUR','KENAIKAN_GAJI','REKRUTMEN','PERMINTAAN_KARYAWAN','KASBON'], true);
}

// --- Required GPS+Photo? (Perjadin is form-only) ---
function hrlp_requires_gps_photo(string $type): bool {
    $t = strtoupper($type);
    return !in_array($t, ['PERJADIN','SAKIT'], true);
}

// --- Allowed upload types ---
function hrlp_allowed_ext(string $name): bool {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ok = ['pdf','doc','docx','xls','xlsx','csv','jpg','jpeg','png','webp','heic'];
    return in_array($ext, $ok, true);
}
function hrlp_save_upload(array $file, string $destDir, string $prefix='file'): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload gagal: ' . (string)($file['error'] ?? 'unknown'));
    }
    $orig = (string)($file['name'] ?? '');
    if (!$orig) throw new RuntimeException('Nama file kosong.');
    if (!hrlp_allowed_ext($orig)) {
        throw new RuntimeException('Tipe file tidak diizinkan: ' . h($orig));
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!$tmp || !is_uploaded_file($tmp)) throw new RuntimeException('File upload tidak valid.');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $base = pathinfo($orig, PATHINFO_FILENAME);
    // Sanitasi nama file untuk keamanan + static scan marker
    $safeBase = rmi_safe_filename((string)$base);
    $ext = preg_replace('/[^a-z0-9]+/i', '', (string)$ext);
    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . ($ext ? '.' . $ext : '');
    $path = rtrim($destDir, '/') . '/' . $name;
    if (!@move_uploaded_file($tmp, $path)) {
        throw new RuntimeException('Gagal menyimpan file.');
    }
    return [
        'orig' => $orig,
        'path' => $path,
        'size' => (int)($file['size'] ?? 0),
        'mime' => (string)($file['type'] ?? ''),
        'name' => $name,
    ];
}
