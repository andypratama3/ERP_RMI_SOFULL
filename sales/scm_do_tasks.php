<?php

require_once __DIR__ . '/../_shared/rmi_icons.php';
// --- upload safety (auto-enforced) ---
require_once dirname(__DIR__, 1) . '/_shared/upload_safety.php';
if (!empty($_FILES)) {
    // enforce safe_filename() for all uploaded names
    rmi_sanitize_uploads($_FILES);
}
// --- /upload safety ---
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../app/Services/SalesTrackingService.php';
require_login();

$__scm_user   = function_exists('auth_user') ? auth_user() : [];
$__scm_role   = strtoupper(trim((string)($__scm_user['role'] ?? '')));
$__scm_level  = strtoupper(trim((string)($__scm_user['level'] ?? '')));
$__scm_dept   = strtoupper(trim((string)($__scm_user['department'] ?? $__scm_user['dept'] ?? '')));
$__scm_office = strtoupper(trim((string)($__scm_user['office_code'] ?? '')));
$__scm_username = strtoupper(trim((string)($__scm_user['username'] ?? $__scm_user['user_name'] ?? '')));

$__is_admin  = in_array($__scm_role, ['SYS','SUPERADMIN','ADMIN'], true);
$__is_scm    = ($__scm_role === 'SCM' || $__scm_dept === 'SCM');
$__is_scm_manager = $__is_scm && (
    in_array($__scm_role, ['MANAGER','MGR','OWNER','SYS','SUPERADMIN','ADMIN'], true)
    || in_array($__scm_level, ['MANAGER','MGR','OWNER'], true)
    || str_starts_with($__scm_username, 'MGRSCM_')
);
$__is_scm_staff = $__is_scm && !$__is_scm_manager && !$__is_admin;
$__is_branch = ($__scm_role === 'BRANCH' || $__scm_dept === 'BRANCH');

// Operational exception yang sangat sempit:
// StaffSCM_BDG adalah staff SCM DELIVERY: seluruh office BDG + khusus UNIT ACC office BGR.
// Hak ini hanya berlaku di halaman SCM dan TIDAK memberi hak WQS/picking/preparation.
$__is_staffscm_bdg = ($__is_scm_staff && $__scm_username === 'STAFFSCM_BDG');

if (function_exists('require_any_permission')) {
    if (!$__is_branch) {
        require_any_permission(['SALES.EDIT', 'SALES.VIEW']);
    }
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','SCM','BRANCH']);
}
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_do_office_scope.php';
require_once __DIR__ . '/_do_task_helpers.php';
require_once __DIR__ . '/../stock/_stock_office_helper.php';
// sales/scm_do_tasks.php
// Build: SCM-TASKS-504-SAFE-20260912
// SCM - Task DO dari WQS (terima barang, kirim, serah terima ke customer, upload bukti foto/video + tanda tangan)
// ERP_RMI_SOFULL flow: CRM -> WQS -> SCM -> ACT -> FIN
// Catatan: auth/role parkir dulu (sementara), fokus UI/UX & flow.
// --- DB (centralized) ---
$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
$trackingSvc = new \App\Services\SalesTrackingService();

// ---------------- helpers ----------------
if (!function_exists('h')) {
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/** Detect UNIT ACC safely from item/master classification, with legacy DO-code fallback. */
function scm_table_columns_safe(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $q = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`','',$table) . "`");
        $cache[$table] = array_map('strtolower', array_column($q->fetchAll(PDO::FETCH_ASSOC), 'Field'));
    } catch (Throwable $e) {
        $cache[$table] = [];
    }
    return $cache[$table];
}

function scm_unit_acc_sql_parts(PDO $pdo, string $iAlias='iua', string $pAlias='pua'): array {
    $ic = scm_table_columns_safe($pdo, 'sales_do_items');
    $pc = scm_table_columns_safe($pdo, 'master_products');
    $conds = [];
    $join = '';
    if (!$ic) return ['join'=>'', 'where'=>'1=0'];
    if (in_array('business_group',$ic,true)) $conds[] = "UPPER(TRIM(COALESCE({$iAlias}.business_group,'')))='UNIT_ACC'";
    if (in_array('category',$ic,true)) $conds[] = "UPPER(TRIM(COALESCE({$iAlias}.category,''))) IN ('UNIT_ACC','ALKES','AKSESORIS')";
    if (in_array('product_id',$ic,true) && $pc && in_array('id',$pc,true)) {
        $join = " LEFT JOIN master_products {$pAlias} ON {$pAlias}.id={$iAlias}.product_id ";
        if (in_array('business_group',$pc,true)) $conds[] = "UPPER(TRIM(COALESCE({$pAlias}.business_group,'')))='UNIT_ACC'";
        if (in_array('category',$pc,true)) $conds[] = "UPPER(TRIM(COALESCE({$pAlias}.category,''))) IN ('UNIT_ACC','ALKES','AKSESORIS')";
    }
    return ['join'=>$join, 'where'=>$conds ? '(' . implode(' OR ', $conds) . ')' : '1=0'];
}

function scm_do_has_unit_acc(PDO $pdo, int $doId, array $row=[]): bool {
    if ($doId <= 0) return false;
    try {
        $x = scm_unit_acc_sql_parts($pdo);
        if ($x['where'] !== '1=0') {
            $st = $pdo->prepare("SELECT 1 FROM sales_do_items iua {$x['join']} WHERE iua.do_id=? AND {$x['where']} LIMIT 1");
            $st->execute([$doId]);
            if ($st->fetchColumn()) return true;
        }
    } catch (Throwable $e) { /* legacy fallback below */ }
    $doCode = strtoupper(trim((string)($row['do_code'] ?? '')));
    $tracking = strtoupper(trim((string)($row['tracking_code'] ?? '')));
    if (str_starts_with($doCode, 'UNITACC-') || str_starts_with($tracking, 'UNITACC-')) return true;
    try {
        $st = $pdo->prepare("SELECT 1 FROM sales_do WHERE id=? AND (UPPER(TRIM(COALESCE(do_code,''))) LIKE 'UNITACC-%' OR UPPER(TRIM(COALESCE(tracking_code,''))) LIKE 'UNITACC-%') LIMIT 1");
        $st->execute([$doId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function scm_can_process_do(array $row): bool {
    global $__is_admin, $__is_scm_manager, $__is_scm_staff, $__is_branch, $__scm_office, $__is_staffscm_bdg, $pdo;

    // Admin dan Manager SCM boleh melihat/memproses semua office.
    if ($__is_admin || $__is_scm_manager) return true;

    // StaffSCM_BDG: seluruh DO office BDG + khusus UNIT ACC office BGR.
    // BMHP-BGR tetap tertutup. Scope ini hanya DELIVERY SCM; tidak memberi hak WQS/preparation.
    if ($__is_staffscm_bdg && $pdo instanceof PDO) {
        $rowOffice = (string)($row['office_code'] ?? '');
        if (rmi_office_same($pdo, $rowOffice, 'BDG')) return true;
        if (rmi_office_same($pdo, $rowOffice, 'BGR')) {
            return scm_do_has_unit_acc($pdo, (int)($row['id'] ?? 0), $row);
        }
        return false;
    }

    // Staff SCM dan Branch lain tetap mengikuti office/cabang miliknya seperti alur lama.
    if (($__is_scm_staff || $__is_branch) && $pdo instanceof PDO && $__scm_office !== '') {
        return rmi_office_same($pdo, (string)($row['office_code'] ?? ''), (string)$__scm_office);
    }

    return false;
}

function scm_actor_label(): string {
    global $__is_branch;
    return $__is_branch ? 'SCM/BRANCH' : 'SCM';
}

function scm_actor_name(): string {
    if (function_exists('auth_user')) {
        $u = auth_user();
        foreach (['username','user_name','name','full_name'] as $key) {
            $v = trim((string)($u[$key] ?? ''));
            if ($v !== '') return $v;
        }
    }
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    foreach (['username','user_name','full_name'] as $key) {
        $v = trim((string)($_SESSION[$key] ?? ''));
        if ($v !== '') return $v;
    }
    // Nested session shape set by master/login.php: $_SESSION['user']['username'].
    // Tanpa ini, sesi bertipe nested jatuh ke label dept ('SCM') dan pelaku tak ketahuan.
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        foreach (['username','user_name','name','full_name'] as $key) {
            $v = trim((string)($_SESSION['user'][$key] ?? ''));
            if ($v !== '') return $v;
        }
    }
    return scm_actor_label();
}

/*
 * PERFORMANCE / SAFETY:
 * Jangan menjalankan ALTER TABLE dari request halaman operasional.
 * Pada versi lama, puluhan SHOW COLUMNS + ALTER/CREATE dapat membuat request
 * menunggu metadata lock dan akhirnya 504 pada NAS/MySQL yang sedang sibuk.
 *
 * Metadata kolom sekarang dibaca sekali per tabel dan dicache selama request.
 * Missing column diperlakukan fail-soft; perubahan schema harus melalui migration.
 */
$GLOBALS['__scm_schema_columns'] = [];

function scm_table_columns(PDO $pdo, string $table): array {
    $key = spl_object_id($pdo) . ':' . $table;
    if (isset($GLOBALS['__scm_schema_columns'][$key])) {
        return $GLOBALS['__scm_schema_columns'][$key];
    }
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $cols = [];
        foreach ($rows as $r) {
            $f = (string)($r['Field'] ?? '');
            if ($f !== '') $cols[$f] = true;
        }
        return $GLOBALS['__scm_schema_columns'][$key] = $cols;
    } catch (Throwable $e) {
        error_log('[SCM_DO_TASKS][SCHEMA] '.$table.' '.$e->getMessage());
        return $GLOBALS['__scm_schema_columns'][$key] = [];
    }
}

function ensure_column(PDO $pdo, string $table, string $column, string $ddl): void {
    // Idempotent compatibility migration.
    // Dibutuhkan agar deployment file SCM tidak berhenti hanya karena kolom evidence
    // belum pernah dibuat pada database production.
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        throw new RuntimeException("Schema SCM tidak valid.");
    }

    $cols = scm_table_columns($pdo, $table);
    if (isset($cols[$column])) return;

    try {
        // $ddl berasal dari source code internal (bukan input user).
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");

        // Refresh cache schema setelah ALTER supaya table_has_column() pada request
        // yang sama langsung melihat kolom baru.
        // NOTE: kunci cache = spl_object_id($pdo).':'.$table (lihat scm_table_columns).
        unset($GLOBALS['__scm_schema_columns'][spl_object_id($pdo) . ':' . $table]);
        $cols = scm_table_columns($pdo, $table);
        if (!isset($cols[$column])) {
            throw new RuntimeException("Kolom {$table}.{$column} belum terbaca setelah migration.");
        }
    } catch (Throwable $e) {
        // Bisa terjadi dua request bersamaan sama-sama mencoba ADD COLUMN.
        // Cek ulang schema; bila kolom sudah ada, anggap sukses.
        unset($GLOBALS['__scm_schema_columns'][spl_object_id($pdo) . ':' . $table]);
        $cols = scm_table_columns($pdo, $table);
        if (isset($cols[$column])) return;

        error_log('[SCM_DO_TASKS][MIGRATION] '.$table.'.'.$column.' '.$e->getMessage());
        throw new RuntimeException(
            "SCM belum dapat menyiapkan kolom {$column}. Pastikan user database ERP memiliki izin ALTER sekali untuk migration, atau jalankan MIGRATION_SCM_EVIDENCE_COLUMNS.sql."
        );
    }
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
    $cols = scm_table_columns($pdo, $table);
    return isset($cols[$column]);
}

function select_col(bool $hasCol, string $col): string {
    return $hasCol ? "`{$col}`" : "NULL AS `{$col}`";
}

function select_col_d(bool $hasCol, string $col): string {
    return $hasCol ? "d.`{$col}`" : "NULL AS `{$col}`";
}

function generate_tracking_token(): string {
    return bin2hex(random_bytes(24));
}

function normalize_tracking_status_from_do(string $status): string {
    $s = strtolower(trim($status));
    if (in_array($s, ['delivered', 'scm_done', 'paid', 'paid_done', 'closed'], true)) return 'DELIVERED';
    if ($s === 'on_delivery') return 'ON_DELIVERY';
    if (in_array($s, ['ready_scm', 'wqs_done'], true)) return 'READY_TO_SHIP';
    return 'PENDING';
}

function sanitize_https_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception("Fallback Live Location URL tidak valid.");
    }
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'https') {
        throw new Exception("Fallback Live Location URL wajib menggunakan https.");
    }
    return $url;
}

function build_tracking_public_url(string $token): string {
    if ($token === '') return '';
    $base = (string)(defined('BASE_PROJECT') ? BASE_PROJECT : (function_exists('auth_base_project') ? auth_base_project() : ''));
    $path = ($base !== '' ? rtrim($base, '/') . '/' : '') . 'sales/tracking_public.php?t=' . rawurlencode($token);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '/' . ltrim($path, '/');
    }
    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function build_wa_share_url(string $doCode, string $trackingUrl): string {
    $msg = "Halo, berikut link tracking pengiriman DO {$doCode}:\n{$trackingUrl}";
    return 'https://wa.me/?text=' . rawurlencode($msg);
}

function scm_uploaded_file_exists(string $storedPath): bool {
    $storedPath = trim($storedPath);
    if ($storedPath === '') return false;

    $base = realpath(__DIR__ . '/..');
    if ($base === false) return false;

    $rel = ltrim(str_replace('\\', '/', $storedPath), '/');
    if ($rel === '' || str_contains($rel, '../')) return false;

    $abs = $base . '/' . $rel;
    return is_file($abs) && is_readable($abs) && filesize($abs) > 0;
}

function upload_file(string $field, string $dirRel, array $allowExt): ?string {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;

    $f = $_FILES[$field];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return null;

    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'ukuran file melebihi upload_max_filesize server',
        UPLOAD_ERR_FORM_SIZE  => 'ukuran file melebihi batas form',
        UPLOAD_ERR_PARTIAL    => 'file hanya ter-upload sebagian',
        UPLOAD_ERR_NO_TMP_DIR => 'folder temporary server tidak tersedia',
        UPLOAD_ERR_CANT_WRITE => 'server gagal menulis file ke disk',
        UPLOAD_ERR_EXTENSION  => 'upload dihentikan extension PHP',
    ];
    if ($err !== UPLOAD_ERR_OK) {
        $why = $uploadErrors[$err] ?? ('kode upload ' . $err);
        throw new RuntimeException("Upload {$field} gagal: {$why}.");
    }

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException("Upload {$field} gagal: temporary upload tidak valid.");
    }

    $name = (string)($f['name'] ?? 'file');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowExt, true)) {
        throw new RuntimeException(
            "Upload {$field} ditolak: ekstensi ." . ($ext ?: '-') .
            " tidak diizinkan. Format: " . implode(', ', $allowExt) . "."
        );
    }

    $base = realpath(__DIR__ . '/..');
    if ($base === false) {
        throw new RuntimeException("Upload {$field} gagal: project root tidak ditemukan.");
    }

    $dirRel = trim(str_replace('\\', '/', $dirRel), '/');
    if ($dirRel === '' || str_contains($dirRel, '../')) {
        throw new RuntimeException("Upload {$field} gagal: folder tujuan tidak valid.");
    }

    $dirAbs = $base . '/' . $dirRel;
    if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        throw new RuntimeException("Upload {$field} gagal: folder tujuan tidak dapat dibuat.");
    }
    if (!is_writable($dirAbs)) {
        throw new RuntimeException("Upload {$field} gagal: folder tujuan tidak writable.");
    }

    $stem = preg_replace('/[^a-zA-Z0-9\-_]/', '_', pathinfo($name, PATHINFO_FILENAME));
    $stem = trim((string)$stem, '_');
    if ($stem === '') $stem = $field;

    $fname = $stem . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destAbs = $dirAbs . '/' . $fname;

    if (!move_uploaded_file($tmp, $destAbs)) {
        throw new RuntimeException("Upload {$field} gagal: file tidak dapat dipindahkan ke penyimpanan SCM.");
    }
    if (!is_file($destAbs) || !is_readable($destAbs) || filesize($destAbs) <= 0) {
        @unlink($destAbs);
        throw new RuntimeException("Upload {$field} gagal: hasil penyimpanan tidak dapat diverifikasi.");
    }

    return '/' . $dirRel . '/' . $fname;
}

function scm_reload_proofs(PDO $pdo, int $id, array $flags): array {
    $select = ['id', 'status'];
    foreach ([
        'scm_receive_photo' => 'receive_photo',
        'scm_receive_video' => 'receive_video',
        'scm_delivery_photo' => 'delivery_photo',
        'scm_delivery_video' => 'delivery_video',
        'scm_signature_data' => 'signature_data',
    ] as $col => $alias) {
        $select[] = !empty($flags[$col]) ? "`{$col}` AS `{$alias}`" : "NULL AS `{$alias}`";
    }

    $st = $pdo->prepare("SELECT " . implode(', ', $select) . " FROM sales_do WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException("Verifikasi bukti gagal: DO tidak ditemukan setelah penyimpanan.");
    return $row;
}

function scm_assert_wqs_proof(array $proof): void {
    $photo = trim((string)($proof['receive_photo'] ?? ''));
    $video = trim((string)($proof['receive_video'] ?? ''));

    $photoOk = $photo !== '' && scm_uploaded_file_exists($photo);
    $videoOk = $video !== '' && scm_uploaded_file_exists($video);

    if (!$photoOk && !$videoOk) {
        throw new RuntimeException(
            "ON DELIVERY dibatalkan: bukti terima barang dari WQS belum terverifikasi di penyimpanan. Upload foto atau video lalu coba lagi."
        );
    }
}

function scm_assert_pod_proof(array $proof): void {
    $photo = trim((string)($proof['delivery_photo'] ?? ''));
    $video = trim((string)($proof['delivery_video'] ?? ''));
    $sig   = trim((string)($proof['signature_data'] ?? ''));

    $photoOk = $photo !== '' && scm_uploaded_file_exists($photo);
    $videoOk = $video !== '' && scm_uploaded_file_exists($video);
    $sigOk = str_starts_with($sig, 'data:image/png;base64,') && strlen($sig) > 100;

    if (!$photoOk && !$videoOk) {
        throw new RuntimeException(
            "DELIVERED dibatalkan: bukti serah-terima customer (POD) belum terverifikasi di penyimpanan."
        );
    }
    if (!$sigOk) {
        throw new RuntimeException(
            "DELIVERED dibatalkan: tanda tangan digital customer belum tersimpan/terverifikasi."
        );
    }
}

// ---------------- KPI / SLA SCM ----------------
/** Format durasi SCM untuk tampilan KPI/SLA. */
function scm_format_duration(?int $sec): string {
    if ($sec === null) return '-';
    $sec = max(0, $sec);
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    $ss = $sec % 60;
    $parts = [];
    if ($d > 0) $parts[] = $d . ' hari';
    if ($h > 0) $parts[] = $h . ' jam';
    if ($m > 0) $parts[] = $m . ' menit';
    if ($ss > 0 || !$parts) $parts[] = $ss . ' detik';
    return implode(' ', $parts);
}

/**
 * SLA SCM dihitung sejak handoff WQS selesai (READY SCM) sampai DELIVERED.
 * ON DELIVERY tetap bagian dari durasi SCM karena tanggung jawab SCM belum selesai.
 * Untuk data lama yang belum memiliki wqs_ready_at, dipakai timestamp audit WQS_READY bila tersedia.
 */
function scm_effective_duration_sec(array $row, ?int $nowTs = null): ?int {
    $startRaw = trim((string)($row['scm_sla_started_at'] ?? $row['wqs_ready_at'] ?? ''));
    if ($startRaw === '') return null;
    $startTs = strtotime($startRaw);
    if ($startTs === false) return null;

    $stored = $row['scm_duration_sec'] ?? null;
    // Nilai tersimpan hanya authoritative sesudah SCM benar-benar DELIVERED.
    // Ini mencegah durasi siklus lama terbawa jika DO pernah direvisi dan kembali READY SCM.
    if (!empty($row['scm_delivered_at']) && $stored !== null && $stored !== '' && is_numeric($stored)) {
        return max(0, (int)$stored);
    }

    $finishRaw = trim((string)($row['scm_delivered_at'] ?? ''));
    if ($finishRaw !== '') {
        $finishTs = strtotime($finishRaw);
        if ($finishTs !== false && $finishTs >= $startTs) return max(0, $finishTs - $startTs);
    }

    $status = strtolower(trim((string)($row['status'] ?? '')));
    if (in_array($status, ['ready_scm','on_delivery'], true)) {
        $nowTs = $nowTs ?? time();
        return max(0, $nowTs - $startTs);
    }
    return null;
}

/** Baca target SLA SCM dari system_config group KPI_DO_SLA secara fail-soft. */
function scm_sla_target_minutes(PDO $pdo): ?int {
    static $loaded = false, $value = null;
    if ($loaded) return $value;
    $loaded = true;
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'system_config'")->fetchColumn()) return null;
        $cols = $pdo->query("SHOW COLUMNS FROM system_config")->fetchAll(PDO::FETCH_COLUMN, 0);
        $pick = static function(array $candidates) use ($cols): ?string {
            foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
            return null;
        };
        $groupCol = $pick(['config_group','group_name','config_group_name','group_key']);
        $keyCol   = $pick(['config_key','key_name','config_name','name','key']);
        $valueCol = $pick(['config_value','value','config_val','setting_value']);
        if (!$keyCol || !$valueCol) return null;

        if ($groupCol) {
            $keys = ['SCM_MINUTES','SCM_SLA_MINUTES','SLA_SCM_MINUTES'];
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $st = $pdo->prepare("SELECT `{$valueCol}` FROM system_config WHERE UPPER(`{$groupCol}`)=? AND UPPER(`{$keyCol}`) IN ({$ph}) LIMIT 1");
            $st->execute(array_merge(['KPI_DO_SLA'], $keys));
        } else {
            $keys = ['KPI_DO_SLA.SCM_MINUTES','KPI_DO_SLA_SCM_MINUTES','SCM_MINUTES'];
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $st = $pdo->prepare("SELECT `{$valueCol}` FROM system_config WHERE UPPER(`{$keyCol}`) IN ({$ph}) LIMIT 1");
            $st->execute($keys);
        }
        $raw = $st->fetchColumn();
        if ($raw !== false && is_numeric($raw) && (int)$raw > 0) $value = (int)$raw;
    } catch (Throwable $e) {
        $value = null;
    }
    return $value;
}

function scm_sla_meta(?int $durationSec, ?int $targetMinutes): array {
    if ($durationSec === null) return ['label'=>'BELUM MULAI','class'=>'badge gray'];
    if (!$targetMinutes || $targetMinutes <= 0) return ['label'=>'DURASI','class'=>'badge blue'];
    $targetSec = $targetMinutes * 60;
    if ($durationSec > $targetSec) return ['label'=>'LEWAT SLA','class'=>'badge red'];
    if ($durationSec >= (int)floor($targetSec * 0.8)) return ['label'=>'MENDEKATI SLA','class'=>'badge yellow'];
    return ['label'=>'AMAN','class'=>'badge green'];
}

// -------------- schema guards --------------
ensure_column($pdo, 'sales_do', 'status', "status VARCHAR(50) NOT NULL DEFAULT 'crm_to_wqs'");

ensure_column($pdo, 'sales_do', 'scm_status', "scm_status VARCHAR(20) NULL"); // Pending/Open/Done (UI)
ensure_column($pdo, 'sales_do', 'scm_note', "scm_note TEXT NULL");

ensure_column($pdo, 'sales_do', 'scm_receive_photo', "scm_receive_photo VARCHAR(255) NULL");
ensure_column($pdo, 'sales_do', 'scm_receive_video', "scm_receive_video VARCHAR(255) NULL");

ensure_column($pdo, 'sales_do', 'scm_delivery_photo', "scm_delivery_photo VARCHAR(255) NULL");
ensure_column($pdo, 'sales_do', 'scm_delivery_video', "scm_delivery_video VARCHAR(255) NULL");

ensure_column($pdo, 'sales_do', 'scm_signature_data', "scm_signature_data LONGTEXT NULL"); // base64 png (simple)
ensure_column($pdo, 'sales_do', 'scm_delivered_at', "scm_delivered_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'wqs_ready_at', "wqs_ready_at DATETIME NULL"); // start SLA SCM = handoff READY SCM
ensure_column($pdo, 'sales_do', 'scm_duration_sec', "scm_duration_sec BIGINT UNSIGNED NULL"); // freeze saat DELIVERED
ensure_column($pdo, 'sales_do', 'scm_on_delivery_at', "scm_on_delivery_at DATETIME NULL");

ensure_column($pdo, 'sales_do', 'last_updated_by', "last_updated_by VARCHAR(50) NULL");
ensure_column($pdo, 'sales_do', 'scm_updated_by', "scm_updated_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'scm_on_delivery_by', "scm_on_delivery_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'scm_delivered_by', "scm_delivered_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'last_updated_at', "last_updated_at DATETIME NULL");

// Delivery method
// Revision flow: DO sudah masuk SCM tetapi belum dikirim.
// Alur aman: SCM request revisi -> WQS/CRM proses ulang -> balik ke SCM.
ensure_column($pdo, 'sales_do', 'revision_reason', "revision_reason TEXT NULL");
ensure_column($pdo, 'sales_do', 'revision_requested_by', "revision_requested_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'revision_requested_at', "revision_requested_at DATETIME NULL");

function scm_ensure_revision_table(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_revisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            do_id INT NOT NULL,
            do_code VARCHAR(100) NULL,
            revision_type VARCHAR(30) NOT NULL DEFAULT 'OTHER',
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            reason TEXT NULL,
            requested_by VARCHAR(100) NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(30) NOT NULL DEFAULT 'REQUESTED',
            KEY idx_do_id (do_id),
            KEY idx_status (status),
            KEY idx_requested_at (requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {
        // fail-soft
    }
}
function scm_revision_log(PDO $pdo, int $doId, string $doCode, string $from, string $to, string $type, string $reason): void {
    try {
        scm_ensure_revision_table($pdo);
        $actor = function_exists('auth_user') ? (auth_user()['username'] ?? auth_user()['name'] ?? '') : '';
        if (trim((string)$actor) === '') {
            $actor = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'SCM';
        }
        $st = $pdo->prepare("INSERT INTO sales_do_revisions
            (do_id, do_code, revision_type, old_status, new_status, reason, requested_by, status)
            VALUES (?,?,?,?,?,?,?, 'REQUESTED')");
        $st->execute([$doId, $doCode, $type, $from, $to, $reason, (string)$actor]);
    } catch (Throwable $e) {
        // fail-soft
    }
}
// sales_do_revisions dibuat secara lazy hanya saat request revisi disubmit.

// Delivery method (Sales DO delivery: internal team vs vendor jasa logistik)
ensure_column($pdo, 'sales_do', 'delivery_mode', "delivery_mode VARCHAR(20) NULL");
ensure_column($pdo, 'sales_do', 'delivery_vendor_id', "delivery_vendor_id INT NULL");
ensure_column($pdo, 'sales_do', 'carrier_provider', "carrier_provider VARCHAR(30) NULL");
ensure_column($pdo, 'sales_do', 'carrier_tracking_no', "carrier_tracking_no VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'carrier_courier_code', "carrier_courier_code VARCHAR(50) NULL");
ensure_column($pdo, 'sales_do', 'tracking_public_token', "tracking_public_token VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'tracking_last_sync_at', "tracking_last_sync_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'tracking_last_status', "tracking_last_status VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'tracking_last_payload_json', "tracking_last_payload_json LONGTEXT NULL");
ensure_column($pdo, 'sales_do', 'fallback_live_location_url', "fallback_live_location_url VARCHAR(500) NULL");
ensure_column($pdo, 'sales_do', 'scm_live_lat', "scm_live_lat DECIMAL(10,7) NULL");
ensure_column($pdo, 'sales_do', 'scm_live_lng', "scm_live_lng DECIMAL(10,7) NULL");
ensure_column($pdo, 'sales_do', 'scm_live_accuracy_m', "scm_live_accuracy_m DECIMAL(8,2) NULL");
ensure_column($pdo, 'sales_do', 'scm_live_at', "scm_live_at DATETIME NULL");

$hasScmStatus         = table_has_column($pdo, 'sales_do', 'scm_status');
$hasScmNote           = table_has_column($pdo, 'sales_do', 'scm_note');
$hasScmReceivePhoto   = table_has_column($pdo, 'sales_do', 'scm_receive_photo');
$hasScmReceiveVideo   = table_has_column($pdo, 'sales_do', 'scm_receive_video');
$hasScmDeliveryPhoto  = table_has_column($pdo, 'sales_do', 'scm_delivery_photo');
$hasScmDeliveryVideo  = table_has_column($pdo, 'sales_do', 'scm_delivery_video');
$hasScmSignatureData  = table_has_column($pdo, 'sales_do', 'scm_signature_data');
$hasScmDeliveredAt    = table_has_column($pdo, 'sales_do', 'scm_delivered_at');
$hasWqsReadyAt         = table_has_column($pdo, 'sales_do', 'wqs_ready_at');
$hasScmDurationSec     = table_has_column($pdo, 'sales_do', 'scm_duration_sec');
$hasScmDeliveredBy    = table_has_column($pdo, 'sales_do', 'scm_delivered_by');
$hasScmOnDeliveryAt   = table_has_column($pdo, 'sales_do', 'scm_on_delivery_at');
$hasLastUpdatedBy     = table_has_column($pdo, 'sales_do', 'last_updated_by');
$hasLastUpdatedAt     = table_has_column($pdo, 'sales_do', 'last_updated_at');
$hasReturnStatus     = table_has_column($pdo, 'sales_do', 'return_status');
$hasRevisionReason    = table_has_column($pdo, 'sales_do', 'revision_reason');
$hasRevisionBy        = table_has_column($pdo, 'sales_do', 'revision_requested_by');
$hasRevisionAt        = table_has_column($pdo, 'sales_do', 'revision_requested_at');
$hasDeliveryMode      = table_has_column($pdo, 'sales_do', 'delivery_mode');
$hasDeliveryVendorId  = table_has_column($pdo, 'sales_do', 'delivery_vendor_id');
$hasCarrierProvider   = table_has_column($pdo, 'sales_do', 'carrier_provider');
$hasCarrierTrackingNo = table_has_column($pdo, 'sales_do', 'carrier_tracking_no');
$hasCarrierCourierCode = table_has_column($pdo, 'sales_do', 'carrier_courier_code');
$hasTrackingPublicToken = table_has_column($pdo, 'sales_do', 'tracking_public_token');
$hasTrackingLastSyncAt = table_has_column($pdo, 'sales_do', 'tracking_last_sync_at');
$hasTrackingLastStatus = table_has_column($pdo, 'sales_do', 'tracking_last_status');
$hasTrackingLastPayload = table_has_column($pdo, 'sales_do', 'tracking_last_payload_json');
$hasFallbackLiveLocationUrl = table_has_column($pdo, 'sales_do', 'fallback_live_location_url');
$hasScmLiveLat = table_has_column($pdo, 'sales_do', 'scm_live_lat');
$hasScmLiveLng = table_has_column($pdo, 'sales_do', 'scm_live_lng');
$hasScmLiveAccuracy = table_has_column($pdo, 'sales_do', 'scm_live_accuracy_m');
$hasScmLiveAt = table_has_column($pdo, 'sales_do', 'scm_live_at');


$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    try {
        $stmt = $pdo->prepare("SELECT id, do_code, status, office_code, tracking_code, "
                                      . select_col($hasDeliveryMode, 'delivery_mode') . ", "
                                      . select_col($hasDeliveryVendorId, 'delivery_vendor_id') . ", "
                                      . select_col($hasCarrierProvider, 'carrier_provider') . ", "
                                      . select_col($hasCarrierTrackingNo, 'carrier_tracking_no') . ", "
                                      . select_col($hasCarrierCourierCode, 'carrier_courier_code') . ", "
                                      . select_col($hasTrackingPublicToken, 'tracking_public_token') . ", "
                                      . select_col($hasTrackingLastStatus, 'tracking_last_status') . ", "
                                      . select_col($hasScmReceivePhoto, 'scm_receive_photo') . ", "
                                      . select_col($hasScmReceiveVideo, 'scm_receive_video') . ", "
                                      . select_col($hasScmDeliveryPhoto, 'scm_delivery_photo') . ", "
                                      . select_col($hasScmDeliveryVideo, 'scm_delivery_video') . ", "
                                      . select_col($hasScmSignatureData, 'scm_signature_data') . "
                               FROM sales_do WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) throw new Exception("DO tidak ditemukan.");
        // Akun normal tetap memakai guard office canonical lama.
        // StaffSCM_BDG memakai guard khusus BDG + UNIT ACC BGR di scm_can_process_do(); salah scope
        // pada master employee/session tidak membuatnya melihat BGR/TGR/BKS.
        if (!$__is_branch && !$__is_staffscm_bdg && function_exists('do_scope_assert_row')) {
            do_scope_assert_row($r);
        }

        if (!scm_can_process_do($r)) {
            throw new Exception($__is_staffscm_bdg
                ? "Akses ditolak: StaffSCM_BDG hanya boleh memproses DO office BDG atau DO UNIT ACC office BGR."
                : "Akses ditolak: Anda hanya boleh memproses DO milik office sendiri.");
        }

        $curStatus = (string)($r['status'] ?? ''); // status sekarang untuk validasi transisi
        $code = (string)($r['do_code'] ?? '');

        $note = trim((string)($_POST['scm_note'] ?? ''));
        $scm_status = (string)($_POST['scm_status'] ?? 'Pending');
        if (!in_array($scm_status, ['Pending','Open','Done'], true)) $scm_status = 'Pending';

        // delivery method (SCM)
        $delivery_mode = strtoupper(trim((string)($_POST['delivery_mode'] ?? ($r['delivery_mode'] ?? ''))));
        if (!in_array($delivery_mode, ['INTERNAL','VENDOR'], true)) $delivery_mode = 'INTERNAL';
        $delivery_vendor_id = (int)($_POST['delivery_vendor_id'] ?? ($r['delivery_vendor_id'] ?? 0));
        if ($delivery_mode !== 'VENDOR') $delivery_vendor_id = 0;
        $carrier_provider = strtoupper(trim((string)($_POST['carrier_provider'] ?? ($r['carrier_provider'] ?? 'BITESHIP'))));
        if (!in_array($carrier_provider, ['BITESHIP'], true)) $carrier_provider = 'BITESHIP';
        $carrier_tracking_no = trim((string)($_POST['carrier_tracking_no'] ?? ($r['carrier_tracking_no'] ?? '')));
        $carrier_courier_code = trim((string)($_POST['carrier_courier_code'] ?? ($r['carrier_courier_code'] ?? '')));
        $fallback_live_location_url = sanitize_https_url((string)($_POST['fallback_live_location_url'] ?? ''));
        $tracking_public_token = trim((string)($r['tracking_public_token'] ?? ''));
        if ($tracking_public_token === '') $tracking_public_token = generate_tracking_token();


        // uploads
        $imgExt = ['jpg','jpeg','png','webp','pdf'];
        $vidExt = ['mp4','mov','m4v','webm'];

        $recv_photo_up = upload_file('scm_receive_photo', 'uploads/sales_scm', $imgExt);
        $recv_video_up = upload_file('scm_receive_video', 'uploads/sales_scm', $vidExt);

        $del_photo_up  = upload_file('scm_delivery_photo', 'uploads/sales_scm', $imgExt);
        $del_video_up  = upload_file('scm_delivery_video', 'uploads/sales_scm', $vidExt);

        $recv_photo = $recv_photo_up ?: ($r['scm_receive_photo'] ?? '');
        $recv_video = $recv_video_up ?: ($r['scm_receive_video'] ?? '');
        $del_photo  = $del_photo_up  ?: ($r['scm_delivery_photo'] ?? '');
        $del_video  = $del_video_up  ?: ($r['scm_delivery_video'] ?? '');

        // signature (base64 png from canvas)
        $sig = trim((string)($_POST['scm_signature_data'] ?? ''));
        if ($sig && str_starts_with($sig, 'data:image/png;base64,')) {
            // keep
        } else {
            $sig = '';
        }
        $sig_final = $sig ?: ($r['scm_signature_data'] ?? '');

        if ($action === 'save') {
            $sets = [];
            $params = [];
            if ($hasScmNote) { $sets[] = "scm_note=?"; $params[] = $note; }
            if ($hasScmStatus) { $sets[] = "scm_status=?"; $params[] = $scm_status; }
            if ($hasDeliveryMode) { $sets[] = "delivery_mode=?"; $params[] = $delivery_mode; }
            if ($hasDeliveryVendorId) { $sets[] = "delivery_vendor_id=?"; $params[] = ($delivery_vendor_id?:null); }
            if ($hasCarrierProvider) { $sets[] = "carrier_provider=?"; $params[] = $carrier_provider; }
            if ($hasCarrierTrackingNo) { $sets[] = "carrier_tracking_no=?"; $params[] = $carrier_tracking_no !== '' ? $carrier_tracking_no : null; }
            if ($hasCarrierCourierCode) { $sets[] = "carrier_courier_code=?"; $params[] = $carrier_courier_code !== '' ? $carrier_courier_code : null; }
            if ($hasFallbackLiveLocationUrl) { $sets[] = "fallback_live_location_url=?"; $params[] = $fallback_live_location_url !== '' ? $fallback_live_location_url : null; }
            if ($hasTrackingPublicToken) { $sets[] = "tracking_public_token=?"; $params[] = $tracking_public_token; }
            if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=" . $pdo->quote(scm_actor_name()); }
            if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }

            if ($hasScmReceivePhoto && $recv_photo_up) { $sets[] = "scm_receive_photo=?"; $params[] = $recv_photo_up; }
            if ($hasScmReceiveVideo && $recv_video_up) { $sets[] = "scm_receive_video=?"; $params[] = $recv_video_up; }
            if ($hasScmDeliveryPhoto && $del_photo_up) { $sets[] = "scm_delivery_photo=?"; $params[] = $del_photo_up; }
            if ($hasScmDeliveryVideo && $del_video_up) { $sets[] = "scm_delivery_video=?"; $params[] = $del_video_up; }
            if ($hasScmSignatureData && $sig) { $sets[] = "scm_signature_data=?"; $params[] = $sig; }

            if (!empty($sets)) {
                $params[] = $id;
                $pdo->prepare("UPDATE sales_do SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
            }
            $code = (string)($r['do_code'] ?? '');
            rmi_audit_safe('UPDATE', 'SALES.DO', $id, null, null, ['event' => 'scm_save', 'do_code' => $code]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'sales_do', 'sales_do', 'SCM_SAVE', $id, $code, "DO SCM save: {$code}", []);
            } else {
                error_log('[SCM_AUDIT_GAP] master_audit() missing on SCM_SAVE do_id=' . $id . ' do_code=' . $code);
            }
            if (function_exists('sales_do_audit_append')) {
                sales_do_audit_append($pdo, $id, $curStatus, $curStatus, 'SCM', $note);
            } else {
                error_log('[SCM_AUDIT_GAP] sales_do_audit_append() missing on SCM_SAVE do_id=' . $id . ' do_code=' . $code);
            }
            $success = "Tersimpan (SCM).";
        }

        if ($action === 'request_revision') {
            // Hanya untuk DO sudah sampai SCM tetapi BELUM dikirim.
            // Jika sudah ON_DELIVERY/DELIVERED gunakan return/retur, bukan revisi pra-kirim.
            if (!in_array($curStatus, ['ready_scm'], true)) {
                throw new Exception("Request revisi hanya boleh dari status READY SCM dan barang belum dikirim. Status sekarang: {$curStatus}.");
            }

            $revisionType = strtoupper(trim((string)($_POST['revision_type'] ?? 'OTHER')));
            if (!in_array($revisionType, ['ITEM','QTY','PRICE','ADDRESS','OTHER'], true)) {
                $revisionType = 'OTHER';
            }

            $reason = trim((string)($_POST['revision_reason'] ?? ''));
            if ($reason === '') {
                $reason = $note;
            }
            if ($reason === '') {
                throw new Exception("Alasan revisi wajib diisi sebelum kembalikan DO ke WQS/CRM.");
            }

            $pdo->beginTransaction();

            $sets = ["status='revision_requested'"];
            $params = [];
            if ($hasScmStatus) { $sets[] = "scm_status='Pending'"; }
            if ($hasScmDurationSec) { $sets[] = "scm_duration_sec=NULL"; }
            if ($hasScmNote) { $sets[] = "scm_note=?"; $params[] = '[REQUEST REVISI] ' . $reason; }
            if ($hasRevisionReason) { $sets[] = "revision_reason=?"; $params[] = $reason; }
            if ($hasRevisionBy) { $sets[] = "revision_requested_by=?"; $params[] = scm_actor_name(); }
            if ($hasRevisionAt) { $sets[] = "revision_requested_at=NOW()"; }
            if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=" . $pdo->quote(scm_actor_name()); }
            if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }

            $params[] = $id;
            $pdo->prepare("UPDATE sales_do SET " . implode(",\n                ", $sets) . " WHERE id=?")->execute($params);

            $code = (string)($r['do_code'] ?? '');
            scm_revision_log($pdo, $id, $code, $curStatus, 'revision_requested', $revisionType, $reason);

            if (function_exists('sales_do_audit_append')) {
                sales_do_audit_append($pdo, $id, $curStatus, 'revision_requested', 'SCM', $reason);
            } else {
                error_log('[SCM_AUDIT_GAP] sales_do_audit_append() missing on SCM_REQUEST_REVISION do_id=' . $id . ' do_code=' . $code);
            }
            rmi_audit_safe('UPDATE', 'SALES.DO', $id, null, null, [
                'event' => 'scm_request_revision',
                'do_code' => $code,
                'from_status' => $curStatus,
                'to_status' => 'revision_requested',
                'revision_type' => $revisionType,
                'reason' => $reason,
            ]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'sales_do', 'sales_do', 'SCM_REQUEST_REVISION', $id, $code, "DO SCM request revision: {$code}", [
                    'from_status' => $curStatus,
                    'to_status' => 'revision_requested',
                    'revision_type' => $revisionType,
                ]);
            } else {
                error_log('[SCM_AUDIT_GAP] master_audit() missing on SCM_REQUEST_REVISION do_id=' . $id . ' do_code=' . $code);
            }

            if ($pdo->inTransaction()) { $pdo->commit(); }
            $success = "DO dikembalikan untuk revisi sebelum pengiriman. Tidak masuk proses retur.";
        }

        if ($action === 'refresh_tracking') {
            $sets = [];
            $params = [];
            if ($hasCarrierProvider) { $sets[] = "carrier_provider=?"; $params[] = $carrier_provider; }
            if ($hasCarrierTrackingNo) { $sets[] = "carrier_tracking_no=?"; $params[] = $carrier_tracking_no !== '' ? $carrier_tracking_no : null; }
            if ($hasCarrierCourierCode) { $sets[] = "carrier_courier_code=?"; $params[] = $carrier_courier_code !== '' ? $carrier_courier_code : null; }
            if ($hasFallbackLiveLocationUrl) { $sets[] = "fallback_live_location_url=?"; $params[] = $fallback_live_location_url !== '' ? $fallback_live_location_url : null; }
            if ($hasTrackingPublicToken) { $sets[] = "tracking_public_token=?"; $params[] = $tracking_public_token; }
            if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=" . $pdo->quote(scm_actor_name()); }
            if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }
            if (!empty($sets)) {
                $params[] = $id;
                $pdo->prepare("UPDATE sales_do SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
            }
            $code = (string)($r['do_code'] ?? '');
            rmi_audit_safe('UPDATE', 'SALES.DO', $id, null, null, ['event' => 'scm_refresh_tracking', 'do_code' => $code]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'sales_do', 'sales_do', 'SCM_REFRESH_TRACKING', $id, $code, "DO SCM refresh tracking: {$code}", []);
            } else {
                error_log('[SCM_AUDIT_GAP] master_audit() missing on SCM_REFRESH_TRACKING do_id=' . $id . ' do_code=' . $code);
            }
            if (function_exists('sales_do_audit_append')) {
                sales_do_audit_append($pdo, $id, $curStatus, $curStatus, 'SCM', $note);
            } else {
                error_log('[SCM_AUDIT_GAP] sales_do_audit_append() missing on SCM_REFRESH_TRACKING do_id=' . $id . ' do_code=' . $code);
            }
            $sync = $trackingSvc->syncByDoId($pdo, $id, true);
            if (empty($sync['ok'])) {
                throw new Exception((string)($sync['error'] ?? 'Sinkronisasi gagal'));
            }
            $success = "Tracking di-refresh dari provider: " . h((string)($sync['provider_status'] ?? 'UNKNOWN'));
        }

        if ($action === 'on_delivery') {
            if (!$hasScmReceivePhoto && !$hasScmReceiveVideo) {
                throw new Exception("ON DELIVERY diblokir: kolom bukti WQS belum tersedia setelah pemeriksaan schema.");
            }
            if ($delivery_mode === 'VENDOR' && $delivery_vendor_id <= 0) {
                throw new Exception("Pilih Vendor/Logistik jika Metode Pengiriman = VENDOR.");
            }

            if (!$recv_photo && !$recv_video) {
                throw new Exception("Pilih Bukti Terima dari WQS (foto atau video), lalu tekan Set ON DELIVERY. Bukti akan disimpan otomatis.");
            }

            if ($curStatus === 'on_delivery') {
                $success = "DO sudah berada di status ON DELIVERY.";
            } else {
                if (!$__is_branch && function_exists('auth_sales_do_require_transition')) {
                    auth_sales_do_require_transition($curStatus, 'on_delivery', 'SCM_ON_DELIVERY');
                }

                if (($__is_branch || $__is_scm_staff) && $curStatus !== 'ready_scm') {
                    throw new Exception("Transisi staff tidak valid. Status harus READY SCM sebelum ON DELIVERY.");
                }

                $pdo->beginTransaction();

                // PROOF LOCK: simpan bukti terlebih dahulu, lalu verifikasi ulang dari DB + filesystem.
                // Status baru boleh berubah setelah bukti WQS benar-benar terbukti tersimpan.
                $proofSets = [];
                $proofParams = [];
                if ($hasScmReceivePhoto && $recv_photo_up) { $proofSets[] = "scm_receive_photo=?"; $proofParams[] = $recv_photo_up; }
                if ($hasScmReceiveVideo && $recv_video_up) { $proofSets[] = "scm_receive_video=?"; $proofParams[] = $recv_video_up; }
                if (!empty($proofSets)) {
                    $proofParams[] = $id;
                    $pdo->prepare("UPDATE sales_do SET " . implode(', ', $proofSets) . " WHERE id=?")->execute($proofParams);
                }

                $proofCheck = scm_reload_proofs($pdo, $id, [
                    'scm_receive_photo' => $hasScmReceivePhoto,
                    'scm_receive_video' => $hasScmReceiveVideo,
                    'scm_delivery_photo' => $hasScmDeliveryPhoto,
                    'scm_delivery_video' => $hasScmDeliveryVideo,
                    'scm_signature_data' => $hasScmSignatureData,
                ]);
                scm_assert_wqs_proof($proofCheck);

                $sets = ["status='on_delivery'"];
                $params = [];
                if ($hasScmOnDeliveryAt) { $sets[] = "scm_on_delivery_at=NOW()"; }
                if ($hasScmDurationSec) { $sets[] = "scm_duration_sec=NULL"; }
                if (table_has_column($pdo, 'sales_do', 'scm_on_delivery_by')) { $sets[] = "scm_on_delivery_by=" . $pdo->quote(scm_actor_name()); }
                if (table_has_column($pdo, 'sales_do', 'scm_updated_by')) { $sets[] = "scm_updated_by=" . $pdo->quote(scm_actor_name()); }
                if ($hasScmNote) { $sets[] = "scm_note=?"; $params[] = $note; }
                if ($hasScmStatus) { $sets[] = "scm_status=COALESCE(scm_status,'Open')"; }
                // scm_receive_* sudah disimpan dan diverifikasi pada PROOF LOCK di atas.
                if ($hasDeliveryMode) { $sets[] = "delivery_mode=?"; $params[] = $delivery_mode; }
                if ($hasDeliveryVendorId) { $sets[] = "delivery_vendor_id=?"; $params[] = ($delivery_vendor_id?:null); }
                if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=" . $pdo->quote(scm_actor_name()); }
                if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }
                $params[] = $id;
                $pdo->prepare("UPDATE sales_do SET " . implode(",\n                ", $sets) . "
                  WHERE id=?")->execute($params);

                // ON DELIVERY hanya mengubah workflow. Session GPS dibuat saat tracker benar-benar START.
                // Ini mencegah ghost ACTIVE session dengan 0 GPS point bila tracker tidak pernah berjalan.

                rmi_audit_safe('UPDATE', 'SALES.DO', $id, null, null, ['event' => 'scm_on_delivery', 'do_code' => $code, 'to_status' => 'on_delivery']);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'sales_do', 'sales_do', 'SCM_ON_DELIVERY', $id, $code, "DO SCM on delivery: {$code}", ['to_status' => 'on_delivery']);
                } else {
                    error_log('[SCM_AUDIT_GAP] master_audit() missing on SCM_ON_DELIVERY do_id=' . $id . ' do_code=' . $code);
                }
                if (function_exists('sales_do_audit_append')) {
                    sales_do_audit_append($pdo, $id, $curStatus, 'on_delivery', 'SCM', $note);
                } else {
                    error_log('[SCM_AUDIT_GAP] sales_do_audit_append() missing on SCM_ON_DELIVERY do_id=' . $id . ' do_code=' . $code);
                }
                if ($pdo->inTransaction()) { $pdo->commit(); }
                $success = "Status: ON DELIVERY. Bukti WQS terverifikasi. Membuka Live Tracker… " . rmi_icon('check');

                // FINAL UX: setelah READY SCM -> ON DELIVERY, langsung masuk ke tracker
                // untuk DO yang sama. Di APK URL ini menjadi trigger native foreground GPS;
                // di browser/PWA tracker akan auto-start dan tombol Start tetap fallback.
                if (!headers_sent()) {
                    header('Location: ./scm_tracker_mobile.php?do_id=' . (int)$id . '&autostart=1');
                    exit;
                }
            }
        }

        if ($action === 'delivered') {
            if ((!$hasScmDeliveryPhoto && !$hasScmDeliveryVideo) || !$hasScmSignatureData) {
                throw new Exception("DELIVERED diblokir: kolom POD/TTD belum lengkap setelah pemeriksaan schema.");
            }
            if ($delivery_mode === 'VENDOR' && $delivery_vendor_id <= 0) {
                throw new Exception("Pilih Vendor/Logistik jika Metode Pengiriman = VENDOR.");
            }

            if (!$del_photo && !$del_video) {
                throw new Exception("Pilih Bukti Serah ke Customer/POD (foto atau video), lalu tekan Set DELIVERED. Bukti akan disimpan otomatis.");
            }
            if (!$sig_final) {
                throw new Exception("Ambil TTD digital customer terlebih dahulu, lalu tekan Set DELIVERED.");
            }

            if ($curStatus === 'delivered') {
                $success = "DO sudah berada di status DELIVERED.";
            } else {
                if (!$__is_branch && function_exists('auth_sales_do_require_transition')) {
                    auth_sales_do_require_transition($curStatus, 'delivered', 'SCM_DELIVERED');
                }

                if (($__is_branch || $__is_scm_staff) && $curStatus !== 'on_delivery') {
                    throw new Exception("Transisi staff tidak valid. Status harus ON DELIVERY sebelum DELIVERED.");
                }

                $pdo->beginTransaction();

                // PROOF LOCK POD: persist foto/video/TTD lebih dulu.
                // DELIVERED tidak boleh tercatat sebelum DB dan filesystem sama-sama membuktikan evidence ada.
                $proofSets = [];
                $proofParams = [];
                if ($hasScmDeliveryPhoto && $del_photo_up) { $proofSets[] = "scm_delivery_photo=?"; $proofParams[] = $del_photo_up; }
                if ($hasScmDeliveryVideo && $del_video_up) { $proofSets[] = "scm_delivery_video=?"; $proofParams[] = $del_video_up; }
                if ($hasScmSignatureData && $sig) { $proofSets[] = "scm_signature_data=?"; $proofParams[] = $sig; }
                if (!empty($proofSets)) {
                    $proofParams[] = $id;
                    $pdo->prepare("UPDATE sales_do SET " . implode(', ', $proofSets) . " WHERE id=?")->execute($proofParams);
                }

                $proofCheck = scm_reload_proofs($pdo, $id, [
                    'scm_receive_photo' => $hasScmReceivePhoto,
                    'scm_receive_video' => $hasScmReceiveVideo,
                    'scm_delivery_photo' => $hasScmDeliveryPhoto,
                    'scm_delivery_video' => $hasScmDeliveryVideo,
                    'scm_signature_data' => $hasScmSignatureData,
                ]);
                scm_assert_pod_proof($proofCheck);

                $sets = ["status='delivered'"];
                $params = [];
                if ($hasScmDeliveredAt) { $sets[] = "scm_delivered_at=NOW()"; }
                if ($hasScmDurationSec) {
                    // Freeze SLA SCM pada saat DELIVERED. Start = READY SCM dari WQS.
                    // Fallback audit dipakai untuk DO legacy yang belum memiliki wqs_ready_at.
                    $sets[] = "scm_duration_sec=CASE WHEN COALESCE(wqs_ready_at,(SELECT MAX(a_sla.created_at) FROM system_audit_logs a_sla WHERE a_sla.module='sales_do' AND a_sla.action='WQS_READY' AND a_sla.record_code=sales_do.do_code)) IS NULL THEN NULL ELSE GREATEST(0,TIMESTAMPDIFF(SECOND,COALESCE(wqs_ready_at,(SELECT MAX(a_sla2.created_at) FROM system_audit_logs a_sla2 WHERE a_sla2.module='sales_do' AND a_sla2.action='WQS_READY' AND a_sla2.record_code=sales_do.do_code)),NOW())) END";
                }
                if (table_has_column($pdo, 'sales_do', 'scm_delivered_by')) { $sets[] = "scm_delivered_by=" . $pdo->quote(scm_actor_name()); }
                if (table_has_column($pdo, 'sales_do', 'scm_updated_by')) { $sets[] = "scm_updated_by=" . $pdo->quote(scm_actor_name()); }
                if ($hasScmNote) { $sets[] = "scm_note=?"; $params[] = $note; }
                if ($hasScmStatus) { $sets[] = "scm_status='Done'"; }
                // scm_delivery_* dan signature sudah disimpan + diverifikasi pada PROOF LOCK POD.
                if ($hasDeliveryMode) { $sets[] = "delivery_mode=?"; $params[] = $delivery_mode; }
                if ($hasDeliveryVendorId) { $sets[] = "delivery_vendor_id=?"; $params[] = ($delivery_vendor_id?:null); }
                if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=" . $pdo->quote(scm_actor_name()); }
                if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }
                $params[] = $id;
                $pdo->prepare("UPDATE sales_do SET " . implode(",\n                ", $sets) . "
                  WHERE id=?")->execute($params);

                // DELIVERED = akhir session tracking DO.
                // Semua session ACTIVE untuk DO ini ditutup agar History tidak lagi
                // menampilkan ACTIVE setelah workflow lanjut ke ACT/FIN.
                try {
                    $pdo->prepare("UPDATE sales_shipment_tracking_sessions
                                   SET status='STOPPED',
                                       stopped_at=COALESCE(stopped_at,NOW()),
                                       stop_reason='DELIVERED',
                                       updated_at=NOW()
                                   WHERE do_id=? AND status='ACTIVE'")
                        ->execute([$id]);
                } catch (Throwable $e) {
                    // fail-soft: jangan menggagalkan DELIVERED bila tabel history belum tersedia.
                }

                $code = (string)($r['do_code'] ?? '');
                rmi_audit_safe('UPDATE', 'SALES.DO', $id, null, null, ['event' => 'scm_delivered', 'do_code' => $code, 'to_status' => 'delivered']);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'sales_do', 'sales_do', 'SCM_DELIVERED', $id, $code, "DO SCM delivered: {$code}", ['to_status' => 'delivered']);
                } else {
                    error_log('[SCM_AUDIT_GAP] master_audit() missing on SCM_DELIVERED do_id=' . $id . ' do_code=' . $code);
                }
                if (function_exists('sales_do_audit_append')) {
                    sales_do_audit_append($pdo, $id, $curStatus, 'delivered', 'SCM', $note);
                } else {
                    error_log('[SCM_AUDIT_GAP] sales_do_audit_append() missing on SCM_DELIVERED do_id=' . $id . ' do_code=' . $code);
                }

                // Verifikasi final setelah perubahan status; bila gagal seluruh transaksi rollback.
                $finalProof = scm_reload_proofs($pdo, $id, [
                    'scm_receive_photo' => $hasScmReceivePhoto,
                    'scm_receive_video' => $hasScmReceiveVideo,
                    'scm_delivery_photo' => $hasScmDeliveryPhoto,
                    'scm_delivery_video' => $hasScmDeliveryVideo,
                    'scm_signature_data' => $hasScmSignatureData,
                ]);
                scm_assert_pod_proof($finalProof);

                if ($pdo->inTransaction()) { $pdo->commit(); }
                $success = "Status: DELIVERED " . rmi_icon('check') . " — POD + TTD terverifikasi (Trigger ACT)";
            }
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// --- Filter GET ---
$_f_status  = trim((string)($_GET['f_status'] ?? ''));
$_f_date_fr = trim((string)($_GET['f_date_fr'] ?? ''));
$_f_date_to = trim((string)($_GET['f_date_to'] ?? ''));
$_f_q       = trim((string)($_GET['f_q'] ?? ''));

$_scm_scope = do_scope_where('d');

// Scope utama SCM Task DO:
// - Admin / Manager SCM: boleh lintas office.
// - Staff SCM / Branch: wajib hanya office sendiri, agar DO BKS/TGR/SMG/BDG tidak bercampur.
if ($__is_staffscm_bdg) {
    // StaffSCM_BDG = seluruh pengiriman BDG + UNIT ACC BGR yang sudah READY SCM/ON DELIVERY.
    // Penting: BMHP-BGR tidak ikut terbuka. Klasifikasi utama memakai item/master product,
    // dengan fallback prefix UNITACC- untuk data legacy.
    $_scm_params = [];
    $_bdg_sql = rmi_office_in_sql($pdo, 'd.office_code', 'BDG', $_scm_params);
    $_bgr_sql = rmi_office_in_sql($pdo, 'd.office_code', 'BGR', $_scm_params);
    $_ua = scm_unit_acc_sql_parts($pdo, 'iua_scope', 'pua_scope');
    $_ua_exists = ($_ua['where'] === '1=0') ? '0=1' : "EXISTS (SELECT 1 FROM sales_do_items iua_scope {$_ua['join']} WHERE iua_scope.do_id=d.id AND {$_ua['where']})";
    $_ua_legacy = "(UPPER(TRIM(COALESCE(d.do_code,''))) LIKE 'UNITACC-%' OR UPPER(TRIM(COALESCE(d.tracking_code,''))) LIKE 'UNITACC-%')";
    $_scm_scope = [
        'sql' => " AND (({$_bdg_sql}) OR (({$_bgr_sql}) AND (({$_ua_exists}) OR {$_ua_legacy})))",
        'params' => $_scm_params,
    ];
} elseif (($__is_scm_staff || $__is_branch) && $__scm_office !== '') {
    $_scm_params = [];
    $_scm_scope = [
        'sql' => ' AND ' . rmi_office_in_sql($pdo, 'd.office_code', $__scm_office, $_scm_params),
        'params' => $_scm_params,
    ];
} elseif (($__is_scm_staff || $__is_branch) && $__scm_office === '') {
    $_scm_scope = [
        'sql' => ' AND 1=0',
        'params' => [],
    ];
}

// Retur FULL yang sudah selesai SCM bukan lagi tugas pengiriman aktif.
// PENTING: jangan mengecualikan hanya dari sales_do.return_status karena PARTIAL return
// juga memakai return_scm_completed. Penentuan FULL dilakukan dari qty kumulatif.
$_scm_return_exclude_sql = '';
try {
    $hasRetTbl = (bool)$pdo->query("SHOW TABLES LIKE 'sales_do_returns'")->fetchColumn();
    $hasRetItemTbl = (bool)$pdo->query("SHOW TABLES LIKE 'sales_do_return_items'")->fetchColumn();
    $hasDoItemTbl = (bool)$pdo->query("SHOW TABLES LIKE 'sales_do_items'")->fetchColumn();
    if ($hasRetTbl && $hasRetItemTbl && $hasDoItemTbl) {
        $_scm_return_exclude_sql .= <<<'SQL'

        AND NOT (
            COALESCE((
                SELECT SUM(ri.qty_return)
                FROM sales_do_return_items ri
                JOIN sales_do_returns rr ON rr.id=ri.return_id
                WHERE rr.do_id=d.id
                  AND LOWER(COALESCE(rr.status,'')) IN ('return_scm_completed','return_completed','completed','closed')
            ),0) >= COALESCE((SELECT SUM(di.qty) FROM sales_do_items di WHERE di.do_id=d.id),0)
            AND COALESCE((SELECT SUM(di2.qty) FROM sales_do_items di2 WHERE di2.do_id=d.id),0) > 0
        )
SQL;
    }
} catch (Throwable $e) {
    // fail-soft: bila tabel retur belum ada, flow SCM lama tetap berjalan.
}

// Shared search filter. Untuk tugas aktif tanggal mengikuti do_date.
$_active_extra_sql = '';
$_active_extra_params = [];
if ($_f_q !== '') {
    $_active_extra_sql .= " AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR c.customers_name LIKE ?)";
    $_like = '%' . $_f_q . '%';
    array_push($_active_extra_params, $_like, $_like, $_like);
}
if ($_f_date_fr !== '') { $_active_extra_sql .= " AND d.do_date >= ?"; $_active_extra_params[] = $_f_date_fr; }
if ($_f_date_to !== '') { $_active_extra_sql .= " AND d.do_date <= ?"; $_active_extra_params[] = $_f_date_to; }

// ACTIVE: hanya READY SCM / ON DELIVERY. Status DELIVERED tidak pernah kembali ke kartu aktif.
$_active_status_sql = " AND d.status IN ('ready_scm','on_delivery')";
if ($_f_status === 'ready_scm' || $_f_status === 'on_delivery') {
    $_active_status_sql = " AND d.status = ?";
    array_unshift($_active_extra_params, $_f_status);
} elseif ($_f_status === 'delivered') {
    $_active_status_sql = " AND 1=0";
}

$_active_sql = "
    SELECT d.id, d.do_code, d.tracking_code, d.do_date, d.customers_code,
           c.customers_name,
           d.office_code, d.grand_total,
           " . select_col_d($hasDeliveryMode, 'delivery_mode') . ",
           " . select_col_d($hasDeliveryVendorId, 'delivery_vendor_id') . ",
           " . select_col_d($hasCarrierProvider, 'carrier_provider') . ",
           " . select_col_d($hasCarrierTrackingNo, 'carrier_tracking_no') . ",
           " . select_col_d($hasCarrierCourierCode, 'carrier_courier_code') . ",
           " . select_col_d($hasTrackingPublicToken, 'tracking_public_token') . ",
           " . select_col_d($hasTrackingLastSyncAt, 'tracking_last_sync_at') . ",
           " . select_col_d($hasTrackingLastStatus, 'tracking_last_status') . ",
           " . select_col_d($hasFallbackLiveLocationUrl, 'fallback_live_location_url') . ",
           " . select_col_d($hasScmLiveLat, 'scm_live_lat') . ",
           " . select_col_d($hasScmLiveLng, 'scm_live_lng') . ",
           " . select_col_d($hasScmLiveAccuracy, 'scm_live_accuracy_m') . ",
           " . select_col_d($hasScmLiveAt, 'scm_live_at') . ",
           d.status,
           " . ($hasWqsReadyAt ? "COALESCE(d.wqs_ready_at,(SELECT MAX(a_sla.created_at) FROM system_audit_logs a_sla WHERE a_sla.module='sales_do' AND a_sla.action='WQS_READY' AND a_sla.record_code=d.do_code)) AS scm_sla_started_at" : "(SELECT MAX(a_sla.created_at) FROM system_audit_logs a_sla WHERE a_sla.module='sales_do' AND a_sla.action='WQS_READY' AND a_sla.record_code=d.do_code) AS scm_sla_started_at") . ",
           " . select_col_d($hasWqsReadyAt, 'wqs_ready_at') . ",
           " . select_col_d($hasScmDurationSec, 'scm_duration_sec') . ",
           " . select_col_d($hasScmOnDeliveryAt, 'scm_on_delivery_at') . ",
           " . select_col_d($hasScmStatus, 'scm_status') . ",
           " . select_col_d($hasScmNote, 'scm_note') . ",
           " . select_col_d($hasScmReceivePhoto, 'scm_receive_photo') . ",
           " . select_col_d($hasScmReceiveVideo, 'scm_receive_video') . ",
           " . select_col_d($hasScmDeliveryPhoto, 'scm_delivery_photo') . ",
           " . select_col_d($hasScmDeliveryVideo, 'scm_delivery_video') . ",
           " . select_col_d($hasScmSignatureData, 'scm_signature_data') . ",
           " . select_col_d($hasScmDeliveredAt, 'scm_delivered_at') . ",
           " . select_col_d($hasScmDeliveredBy, 'scm_delivered_by') . "
    FROM sales_do d
    LEFT JOIN master_customers c ON c.customers_code = d.customers_code
    WHERE 1=1
    " . $_active_status_sql . "
    " . $_scm_return_exclude_sql . "
    " . $_scm_scope['sql'] . $_active_extra_sql . "
    ORDER BY "
    . ($hasLastUpdatedAt ? "COALESCE(d.last_updated_at, d.do_date) DESC, " : "")
    . "d.do_date DESC, d.id DESC";
$_active_st = $pdo->prepare($_active_sql);
$_active_st->execute(array_merge($_scm_scope['params'], $_active_extra_params));
$rows_active = $_active_st->fetchAll();

// HISTORY SCM: berdasarkan EVENT selesai SCM.
// Prioritas: scm_delivered_at. Fallback untuk data lama: audit SCM_DELIVERED.
// Current status boleh sudah lanjut ACT/FIN; history SCM tetap tersimpan.
$rows_history = [];
if ($_f_status !== 'ready_scm' && $_f_status !== 'on_delivery') {
    $_hist_extra_sql = '';
    $_hist_extra_params = [];
    if ($_f_q !== '') {
        $_hist_extra_sql .= " AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR c.customers_name LIKE ?)";
        $_like_h = '%' . $_f_q . '%';
        array_push($_hist_extra_params, $_like_h, $_like_h, $_like_h);
    }

    $_hist_delivered_expr = $hasScmDeliveredAt
        ? "COALESCE(d.scm_delivered_at, aud.scm_delivered_audit_at)"
        : "aud.scm_delivered_audit_at";

    if ($_f_date_fr !== '') { $_hist_extra_sql .= " AND DATE(COALESCE(" . $_hist_delivered_expr . ", d.do_date)) >= ?"; $_hist_extra_params[] = $_f_date_fr; }
    if ($_f_date_to !== '') { $_hist_extra_sql .= " AND DATE(COALESCE(" . $_hist_delivered_expr . ", d.do_date)) <= ?"; $_hist_extra_params[] = $_f_date_to; }

    $_hist_by_expr = $hasScmDeliveredBy
        ? "COALESCE(d.scm_delivered_by, aud.scm_delivered_audit_by)"
        : "aud.scm_delivered_audit_by";

    $_hist_sql = "
        SELECT d.id, d.do_code, d.tracking_code, d.do_date, d.customers_code,
               c.customers_name,
               d.office_code, d.grand_total, d.status,
               " . ($hasWqsReadyAt ? "COALESCE(d.wqs_ready_at,(SELECT MAX(a_sla.created_at) FROM system_audit_logs a_sla WHERE a_sla.module='sales_do' AND a_sla.action='WQS_READY' AND a_sla.record_code=d.do_code)) AS scm_sla_started_at" : "(SELECT MAX(a_sla.created_at) FROM system_audit_logs a_sla WHERE a_sla.module='sales_do' AND a_sla.action='WQS_READY' AND a_sla.record_code=d.do_code) AS scm_sla_started_at") . ",
               " . select_col_d($hasWqsReadyAt, 'wqs_ready_at') . ",
               " . select_col_d($hasScmDurationSec, 'scm_duration_sec') . ",
               " . select_col_d($hasScmOnDeliveryAt, 'scm_on_delivery_at') . ",
               " . select_col_d($hasDeliveryMode, 'delivery_mode') . ",
               " . select_col_d($hasDeliveryVendorId, 'delivery_vendor_id') . ",
               " . select_col_d($hasTrackingLastSyncAt, 'tracking_last_sync_at') . ",
               " . select_col_d($hasTrackingLastStatus, 'tracking_last_status') . ",
               " . select_col_d($hasScmReceivePhoto, 'scm_receive_photo') . ",
               " . select_col_d($hasScmReceiveVideo, 'scm_receive_video') . ",
               " . select_col_d($hasScmDeliveryPhoto, 'scm_delivery_photo') . ",
               " . select_col_d($hasScmDeliveryVideo, 'scm_delivery_video') . ",
               " . select_col_d($hasScmSignatureData, 'scm_signature_data') . ",
               " . $_hist_delivered_expr . " AS scm_delivered_at,
               " . $_hist_by_expr . " AS scm_delivered_by
        FROM sales_do d
        LEFT JOIN master_customers c ON c.customers_code = d.customers_code
        LEFT JOIN (
            SELECT record_code,
                   MAX(created_at) AS scm_delivered_audit_at,
                   SUBSTRING_INDEX(GROUP_CONCAT(username ORDER BY created_at DESC SEPARATOR '||'), '||', 1) AS scm_delivered_audit_by
            FROM system_audit_logs
            WHERE module='sales_do' AND action='SCM_DELIVERED'
            GROUP BY record_code
        ) aud ON aud.record_code = d.do_code
        WHERE (" . $_hist_delivered_expr . " IS NOT NULL OR LOWER(COALESCE(d.status,''))='delivered')
        " . $_scm_scope['sql'] . $_hist_extra_sql . "
        ORDER BY COALESCE(" . $_hist_delivered_expr . ", d.do_date) DESC, d.id DESC
        LIMIT 50";
    $_hist_st = $pdo->prepare($_hist_sql);
    $_hist_st->execute(array_merge($_scm_scope['params'], $_hist_extra_params));
    $rows_history = $_hist_st->fetchAll();
}

// Alias untuk kompatibilitas UI/result count yang sudah ada: total yang sedang ditampilkan.
$rows = array_merge($rows_active, $rows_history);
$scmSlaTargetMinutes = scm_sla_target_minutes($pdo);

if ($hasTrackingPublicToken && !empty($rows_active)) {
    foreach ($rows_active as &$rowRef) {
        $token = trim((string)($rowRef['tracking_public_token'] ?? ''));
        if ($token === '') {
            $token = generate_tracking_token();
            try {
                $pdo->prepare("UPDATE sales_do SET tracking_public_token=? WHERE id=?")->execute([$token, (int)$rowRef['id']]);
                $rowRef['tracking_public_token'] = $token;
            } catch (Throwable $e) {
                // fail-soft
            }
        }
    }
    unset($rowRef);
}

// vendor list (jasa logistik / pengiriman)
$vendorList = [];
try {
    $vendorList = $pdo->query("
    SELECT id, vendors_code, vendors_name FROM master_vendors
    WHERE status='active'
    AND (vendor_type = 'Forwarding' OR vendor_type = 'Forwarder'
         OR vendor_type IN ('Logistic/Expedisi','Logistics','Logistic','Ekspedisi','Expedisi'))
    ORDER BY vendors_name ASC
")->fetchAll();
} catch (Throwable $e) { $vendorList = []; }

// Audit log (last 50) - sales_do (SCM)
$audit_rows = [];
try {
    if (function_exists('master_audit_ensure_table')) {
        master_audit_ensure_table($pdo);
    }
    $st = $pdo->prepare("
        SELECT action, record_code, username, description, created_at
        FROM system_audit_logs
        WHERE module = 'sales_do'
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $audit_rows = [];
}

function pill($label, $active=false) {
    $cls = $active ? 'pill active' : 'pill';
    return '<span class="'.$cls.'">'.h($label).'</span>';
}

function badgeStatus($s): string {
    $s = strtolower((string)$s);
    $map = [
        'ready_scm'   => ['READY SCM','tag green'],
        'on_delivery' => ['ON DELIVERY','tag yellow'],
        'delivered'   => ['DELIVERED','tag blue'],
    ];
    $v = $map[$s] ?? [strtoupper($s), 'tag'];
    return '<span class="'.$v[1].'">'.h($v[0]).'</span>';
}
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();


$__scm_header_actions = [
  ['label' => '↩ Antrean Retur DO', 'url' => $baseProject . '/sales/sales_do_return.php?queue=scm', 'class' => 'btn btn-sm btn-outline-warning'],
  ['label' => rmi_icon('books') . ' Panduan Task', 'url' => $baseProject . '/sales/panduan_do_tasks.php', 'class' => 'btn btn-sm btn-outline-light'],
];
if ($__is_admin || $__is_scm_manager) {
  $__scm_header_actions[] = ['label' => rmi_icon('tower') . ' Control Tower', 'url' => $baseProject . '/sales/sales_control_tower.php', 'class' => 'btn btn-sm btn-outline-light'];
}
$__scm_header_actions[] = ['label' => rmi_icon('chart') . ' Rekap Delivery', 'url' => $baseProject . '/sales/scm_delivery_recap.php', 'class' => 'btn btn-sm btn-outline-light'];
$__scm_header_actions[] = ['label' => '📍 History Tracking', 'url' => $baseProject . '/sales/scm_tracking_history.php', 'class' => 'btn btn-sm btn-outline-light'];

rmi_header('SCM - Task DO', [
  'active' => 'sales',
  'breadcrumbs' => [
    ['label' => 'Sales (CRM)', 'url' => $baseProject . '/sales/sales_dashboard.php'],
    'SCM - Task DO',
  ],
  'actions' => $__scm_header_actions,
  'extra_head' => '<style>
    :root{
      --bg:#080e1a; --surface:#0f1724; --surface2:#141d2e; --border:rgba(255,255,255,.07);
      --border-hover:rgba(255,255,255,.13); --text:#e2e8f0; --muted:#64748b; --muted2:#94a3b8;
      --blue:#3b82f6; --blue-s:rgba(59,130,246,.15); --blue-b:rgba(59,130,246,.35);
      --green:#22c55e; --green-s:rgba(34,197,94,.12); --green-b:rgba(34,197,94,.3);
      --yellow:#f59e0b; --yellow-s:rgba(245,158,11,.12); --yellow-b:rgba(245,158,11,.3);
      --red:#ef4444; --red-s:rgba(239,68,68,.12); --red-b:rgba(239,68,68,.3);
      --radius:14px; --radius-sm:9px;
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{background:var(--bg);color:var(--text);font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}
    a{color:#60a5fa;text-decoration:none}
    a:hover{text-decoration:underline}
    code{font-family:monospace;font-size:11px;background:rgba(255,255,255,.06);padding:2px 6px;border-radius:4px}

    /* Layout */
    .scm-wrap{max-width:1060px;margin:24px auto;padding:0 16px}

    /* Page header */
    .page-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px;flex-wrap:wrap}
    .page-hd-left h1{font-size:19px;font-weight:700;letter-spacing:-.3px;margin-bottom:3px}
    .page-hd-left .sub{font-size:12px;color:var(--muted2);margin-bottom:10px}
    .page-hd-right{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start}

    /* Flow stepper */
    .stepper{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
    .step{display:flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:4px 10px;border-radius:999px;
          border:1px solid var(--border);color:var(--muted);background:transparent;letter-spacing:.4px;text-transform:uppercase}
    .step.active{border-color:var(--green-b);color:#bbf7d0;background:var(--green-s)}
    .step-arrow{color:var(--muted);font-size:10px}

    /* Buttons */
    .btn-nav{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);background:rgba(255,255,255,.04);
             color:var(--muted2);padding:7px 12px;border-radius:var(--radius-sm);font-size:12px;cursor:pointer;white-space:nowrap;text-decoration:none}
    .btn-nav:hover{background:rgba(255,255,255,.07);color:var(--text);text-decoration:none}
    .btn{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);background:rgba(255,255,255,.05);
         color:var(--text);padding:8px 14px;border-radius:var(--radius-sm);font-size:12px;cursor:pointer;font-weight:500;white-space:nowrap}
    .btn:hover{background:rgba(255,255,255,.08)}
    .btn.primary{background:var(--blue-s);border-color:var(--blue-b);color:#bfdbfe}
    .btn.primary:hover{background:rgba(59,130,246,.22)}
    .btn.warn{background:var(--yellow-s);border-color:var(--yellow-b);color:#fde68a}
    .btn.warn:hover{background:rgba(245,158,11,.2)}
    .btn.success{background:var(--green-s);border-color:var(--green-b);color:#bbf7d0}
    .btn.success:hover{background:rgba(34,197,94,.18)}
    .btn.danger{background:var(--red-s);border-color:var(--red-b);color:#fca5a5}
    .btn.sm{padding:5px 10px;font-size:11px;border-radius:7px}

    /* Alerts */
    .alert{padding:11px 14px;border-radius:var(--radius-sm);margin:12px 0;font-size:12px;border:1px solid}
    .alert.ok{background:var(--green-s);border-color:var(--green-b);color:#bbf7d0}
    .alert.bad{background:var(--red-s);border-color:var(--red-b);color:#fca5a5}

    /* Badges */
    .badge{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:3px 9px;font-size:11px;font-weight:600;border:1px solid;letter-spacing:.3px;text-transform:uppercase}
    .badge.green{background:var(--green-s);border-color:var(--green-b);color:#bbf7d0}
    .badge.yellow{background:var(--yellow-s);border-color:var(--yellow-b);color:#fde68a}
    .badge.blue{background:var(--blue-s);border-color:var(--blue-b);color:#bfdbfe}
    .badge.gray{background:rgba(255,255,255,.05);border-color:var(--border);color:var(--muted2)}
    .badge.red{background:var(--red-s);border-color:var(--red-b);color:#fca5a5}
    .dot{width:6px;height:6px;border-radius:999px;display:inline-block;flex-shrink:0}
    .dot.green{background:var(--green)}
    .dot.yellow{background:var(--yellow)}
    .dot.blue{background:var(--blue)}
    .sla-time{font-size:13px;font-weight:700;color:var(--text);margin-top:7px}
    .sla-caption{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:7px}
    .sla-dates{font-size:10px;color:var(--muted);line-height:1.45;margin-top:4px}

    /* Card */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px}
    .card+.card{margin-top:10px}

    /* Filter bar */
    .filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
    .filter-group{display:flex;flex-direction:column;gap:5px}
    .filter-group label{font-size:11px;color:var(--muted2);font-weight:500;letter-spacing:.3px;text-transform:uppercase}
    .field{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--text);
           border-radius:var(--radius-sm);padding:7px 10px;outline:none;font-size:12px;transition:border .15s}
    .field:focus{border-color:rgba(59,130,246,.5);background:rgba(59,130,246,.04)}
    .select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27%2364748b%27 stroke-width=%272%27%3E%3Cpath d=%27M6 9l6 6 6-6%27/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
    .filter-actions{display:flex;gap:6px;padding-bottom:1px}
    .result-count{font-size:11px;color:var(--muted);padding:7px 0;align-self:flex-end}

    /* Section header */
    .section-hd{display:flex;align-items:center;gap:10px;margin-bottom:12px}
    .section-hd h2{font-size:13px;font-weight:600;color:var(--text)}
    .section-hd .count{background:rgba(255,255,255,.08);border:1px solid var(--border);border-radius:999px;
                       padding:1px 8px;font-size:11px;color:var(--muted2);font-weight:600}
    .section-hd .desc{font-size:11px;color:var(--muted);margin-left:4px}
    .section-divider{height:1px;background:var(--border);margin:16px 0}

    /* DO Card (active) */
    .do-card{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:border .15s}
    .do-card:hover{border-color:var(--border-hover)}
    .do-card+.do-card{margin-top:10px}
    .do-card.status-ready{border-left:3px solid var(--green)}
    .do-card.status-delivery{border-left:3px solid var(--yellow)}

    .do-card-top{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:16px;padding:14px 16px;align-items:start;border-bottom:1px solid var(--border)}
    .do-meta-label{font-size:10px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);margin-bottom:3px}
    .do-meta-val{font-size:13px;font-weight:600;color:var(--text)}
    .do-meta-sub{font-size:11px;color:var(--muted2);margin-top:1px}
    /* Token, bukan hex: #60a5fa hardcoded hanya 2.31:1 di kartu putih
       (light mode) sehingga link ini nyaris tak terlihat. --rmi-accent-ink
       = 6.40:1 dark / 6.66:1 light. */
    .do-meta-link{font-size:11px;color:var(--rmi-accent-ink, #60a5fa);margin-top:3px;display:block}
    /* Terkunci: tombol hanya aktif setelah status DELIVERED. Bukan <a>,
       jadi tidak bisa diklik/tab-in, dan_reason dilewati ke title+aria. */
    .do-meta-link.is-locked{
      color:var(--rmi-muted,#475569);
      cursor:not-allowed;
      opacity:.85;
      text-decoration:none;
    }
    .do-meta-link.is-locked:hover{text-decoration:none}
    .do-status-col{display:flex;flex-direction:column;gap:6px;align-items:flex-start}
    .do-amount{font-size:15px;font-weight:700;letter-spacing:-.3px}

    /* DO card body (form) */
    .do-card-body{padding:14px 16px;display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media(max-width:800px){.do-card-body,.do-card-top{grid-template-columns:1fr}}
    .form-section{display:flex;flex-direction:column;gap:10px}
    .form-section-title{font-size:11px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);padding-bottom:6px;border-bottom:1px solid var(--border);margin-bottom:4px}

    /* Form fields */
    .field-row{display:flex;flex-direction:column;gap:4px}
    .field-row label{font-size:11px;color:var(--muted2);font-weight:500}
    .field-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    textarea.field{resize:vertical;min-height:54px}

    /* Track box */
    .track-box{background:rgba(2,6,23,.4);border:1px dashed rgba(100,116,139,.35);border-radius:var(--radius-sm);padding:10px 12px;display:flex;flex-direction:column;gap:8px}
    .track-info-row{display:flex;align-items:baseline;gap:6px;font-size:11px}
    .track-info-row .lbl{color:var(--muted);width:90px;flex-shrink:0}
    .track-info-row .val{color:var(--muted2)}
    .track-info-row a{font-size:11px}

    /* Upload / proof area */
    .proof-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .proof-item{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px}
    .proof-item .lbl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:5px}
    .proof-link{font-size:11px;color:#60a5fa}
    .proof-section-hd{font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--muted2);margin:6px 0 4px;display:flex;align-items:center;gap:6px}

    /* Signature */
    .sig-wrap{background:#06101e;border:1px solid rgba(255,255,255,.1);border-radius:var(--radius-sm);overflow:hidden}
    .sig-wrap .sig-label{font-size:10px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);padding:8px 10px 4px;display:flex;align-items:center;gap:6px}
    canvas.sig{display:block;width:100%;height:110px;touch-action:none}
    .sig-bar{display:flex;gap:6px;justify-content:flex-end;padding:6px 8px;border-top:1px solid rgba(255,255,255,.06)}

    /* Action bar */
    .action-bar{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:12px 16px;background:rgba(255,255,255,.015);border-top:1px solid var(--border)}
    .action-bar .sep{flex:1}

    /* Delivery toggle */
    .delivery-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .delivery-row .field{flex:1;min-width:120px}

    /* History table */
    .hist-table-wrap{overflow-x:auto;border-radius:var(--radius-sm)}
    .hist-table{width:100%;border-collapse:collapse;min-width:1120px;font-size:12px}
    .hist-table thead th{padding:8px 12px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap;background:var(--surface)}
    .hist-table tbody td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top;color:var(--muted2)}
    .hist-table tbody tr:hover td{background:rgba(255,255,255,.02)}
    .hist-table tbody tr:last-child td{border-bottom:none}
    .hist-table .bold{font-weight:600;color:var(--text)}

    /* Audit log */
    .audit-table{width:100%;border-collapse:collapse;font-size:11px}
    .audit-table thead th{padding:7px 10px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)}
    .audit-table tbody td{padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.04);color:var(--muted2)}
    .audit-table tbody tr:last-child td{border-bottom:none}

    /* Misc */
    .empty-state{text-align:center;padding:32px 16px;color:var(--muted);font-size:12px}
    .empty-state svg{display:block;margin:0 auto 10px;opacity:.3}
    .file-exists{display:inline-flex;align-items:center;gap:4px;font-size:11px}
    .chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;border-radius:6px;padding:3px 8px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--muted2)}

    @media(max-width:768px){
      .scm-wrap{padding:0 10px}
      .page-hd,.page-hd-right,.do-card-top,.do-card-body,.field-grid-2,.proof-grid,.delivery-row,.action-bar,.filter-bar{display:grid !important;grid-template-columns:1fr !important;gap:10px !important}
      .btn,.btn-nav,button,input,select,textarea{width:100%;min-height:44px;font-size:16px !important;line-height:1.3;pointer-events:auto !important;touch-action:manipulation;position:relative;z-index:5}
      .field{font-size:16px !important;min-height:44px;padding:10px 12px;pointer-events:auto !important;-webkit-appearance:none;appearance:none;position:relative;z-index:5}
      textarea.field{min-height:88px}
      .sig-wrap,.track-box,.proof-item,.form-section,.do-card,.do-card-body{position:relative;z-index:1;overflow:visible}
      canvas.sig{width:100% !important;height:140px !important;display:block;touch-action:none;position:relative;z-index:2}
      .sig-bar{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      .hist-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
      .hist-table,.audit-table{min-width:720px}
    }
  </style>',
]);
?>

<div class="scm-wrap">

  <!-- Page header -->
  <div class="page-hd">
    <div class="page-hd-left">
      <h1>SCM — Task DO dari WQS</h1>
      <div class="sub">Terima barang, update pengiriman, upload bukti serah-terima, tanda tangan digital.</div>
      <div class="stepper">
        <span class="step">CRM</span><span class="step-arrow">›</span>
        <span class="step">WQS</span><span class="step-arrow">›</span>
        <span class="step active"><span class="dot green"></span>SCM</span><span class="step-arrow">›</span>
        <span class="step">ACT</span><span class="step-arrow">›</span>
        <span class="step">FIN</span>
      </div>
    </div>
    <div class="page-hd-right">
      <a class="btn-nav" href="./sales_dashboard.php">← Sales</a>
      <a class="btn-nav" href="../stock/wqs_do_tasks.php">WQS Tasks</a>
      <a class="btn-nav" href="./scm_tracker_mobile.php" target="_blank" rel="noopener">📍 Tracker</a>
    </div>
  </div>

  <?php if ($success): ?><div class="alert ok"><?= rmi_icon('tick') ?> <?php echo h($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert bad"><?= rmi_icon('warn') ?> <?php echo h($error); ?></div><?php endif; ?>
  <?php if ($__is_staffscm_bdg): ?>
    <div class="alert ok">ℹ StaffSCM_BDG: pengiriman office BDG + khusus UNIT ACC office BGR yang sudah READY SCM/ON DELIVERY. BMHP-BGR tetap tertutup; tidak memiliki akses persiapan WQS.</div>
  <?php elseif ($__is_branch || $__is_scm_staff): ?>
    <div class="alert ok">ℹ Scope office aktif. Akun staff hanya dapat melihat dan memproses DO milik office sendiri.</div>
  <?php endif; ?>
  <div style="font-size:11px;color:var(--muted2);margin:8px 0 12px">
    <b>SLA SCM:</b> dihitung dari <b>READY SCM (handoff WQS) → DELIVERED</b>. Status ON DELIVERY tetap dihitung sebagai waktu SCM.
    <?php if ($scmSlaTargetMinutes): ?> Target: <b><?= (int)$scmSlaTargetMinutes ?> menit</b>.<?php else: ?> Target KPI belum terbaca dari <code>KPI_DO_SLA</code>; durasi tetap dicatat.<?php endif; ?>
  </div>

  <!-- Filter -->
  <div class="card" style="margin-bottom:12px">
    <form method="get" class="filter-bar">
      <div class="filter-group">
        <label>Cari</label>
        <input class="field" style="width:200px" name="f_q" placeholder="DO code, customer…" value="<?= h($_f_q) ?>">
      </div>
      <div class="filter-group">
        <label>Status</label>
        <select class="field select" style="width:150px" name="f_status">
          <option value="">Semua</option>
          <option value="ready_scm"   <?= $_f_status==='ready_scm'   ?'selected':'' ?>>READY SCM</option>
          <option value="on_delivery" <?= $_f_status==='on_delivery' ?'selected':'' ?>>ON DELIVERY</option>
          <option value="delivered"   <?= $_f_status==='delivered'   ?'selected':'' ?>>HISTORY DELIVERED</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Tanggal dari</label>
        <input class="field" style="width:138px" type="date" name="f_date_fr" value="<?= h($_f_date_fr) ?>">
      </div>
      <div class="filter-group">
        <label>Tanggal s/d</label>
        <input class="field" style="width:138px" type="date" name="f_date_to" value="<?= h($_f_date_to) ?>">
      </div>
      <div class="filter-actions">
        <button class="btn primary" type="submit">Filter</button>
        <a class="btn" href="?">Reset</a>
      </div>
      <div class="result-count"><?= count($rows_active) ?> aktif · <?= count($rows_history) ?> history</div>
    </form>
  </div>


  <!-- Active section header -->
  <div class="section-hd">
    <h2>Aktif</h2>
    <span class="count"><?= count($rows_active) ?></span>
    <span class="desc">READY SCM &amp; ON DELIVERY — perlu tindakan SCM</span>
  </div>

  <?php if (!$rows_active): ?>
    <div class="card">
      <div class="empty-state">
        <?= rmi_icon('doc', 'is-xl', ['stroke-width' => 1.5]) ?>
        Tidak ada DO aktif untuk SCM
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($rows_active as $r):
    $mode  = strtoupper((string)($r['delivery_mode'] ?? 'INTERNAL'));
    if ($mode === '') $mode = 'INTERNAL';
    $vid   = (int)($r['delivery_vendor_id'] ?? 0);
    $vname = '';
    if ($vid > 0 && $vendorList) {
      foreach ($vendorList as $vv) { if ((int)$vv['id'] === $vid) { $vname = ($vv['vendors_name'] ?? $vv['vendors_code']); break; } }
    }
    $token = trim((string)($r['tracking_public_token'] ?? ''));
    $trackingPublicUrl = build_tracking_public_url($token);
    $waShareUrl = build_wa_share_url((string)$r['do_code'], $trackingPublicUrl);
    $statusClass = $r['status'] === 'on_delivery' ? 'status-delivery' : 'status-ready';
    // Detail/Print hanya terbuka setelah DELIVERED (meminta pada kartu DO SCM).
    // Status final di flow ini = 'delivered' (lihat badgeStatus()).
    $isDelivered = strtolower(trim((string)($r['status'] ?? ''))) === 'delivered';
    $cur_scm = $r['scm_status'] ?: 'Pending';
    $cp = strtoupper((string)($r['carrier_provider'] ?? 'BITESHIP'));
    if (!in_array($cp, ['BITESHIP'], true)) $cp = 'BITESHIP';
    $dm = strtoupper((string)($r['delivery_mode'] ?? 'INTERNAL'));
    if (!in_array($dm, ['INTERNAL','VENDOR'], true)) $dm = 'INTERNAL';
    $selVid = (int)($r['delivery_vendor_id'] ?? 0);
    $custName = ($r['customers_name'] ?? '') !== '' ? $r['customers_name'] : $r['customers_code'];
    $scmDurSec = scm_effective_duration_sec($r);
    $scmSla = scm_sla_meta($scmDurSec, $scmSlaTargetMinutes);
    $scmRunning = in_array(strtolower((string)($r['status'] ?? '')), ['ready_scm','on_delivery'], true) && !empty($r['scm_sla_started_at']);
  ?>
  <div class="do-card <?= $statusClass ?>">

    <!-- Card top: summary info -->
    <div class="do-card-top">
      <div>
        <div class="do-meta-label">DO Code</div>
        <div class="do-meta-val"><?= h($r['do_code']) ?></div>
        <div class="do-meta-sub"><?= h($r['do_date']) ?> &nbsp;·&nbsp; <?= h($r['office_code']) ?></div>
        <?php if ($isDelivered): ?>
        <a class="do-meta-link" href="./sales_do_view.php?id=<?= (int)$r['id'] ?>" target="_blank">Detail / Print ↗</a>
        <?php else: ?>
        <span class="do-meta-link is-locked" role="link" aria-disabled="true"
              title="Detail &amp; Print dibuka setelah status DELIVERED. Status saat ini: <?= h(strtoupper((string)($r['status'] ?? ''))) ?>.">Detail / Print 🔒</span>
        <?php endif; ?>
      </div>
      <div>
        <div class="do-meta-label">Customer</div>
        <div class="do-meta-val"><?= h($custName) ?></div>
        <div class="do-meta-sub"><?= h($r['customers_code'] ?? '') ?></div>
      </div>
      <div>
        <div class="do-meta-label">Grand Total</div>
        <div class="do-amount">Rp <?= h(number_format((float)($r['grand_total'] ?? 0), 0, ',', '.')) ?></div>
        <div class="do-meta-sub" style="margin-top:6px">
          <?php if ($mode === 'VENDOR'): ?>
            <span class="chip">🚚 <?= h($vname ?: 'Vendor #'.$vid) ?></span>
          <?php else: ?>
            <span class="chip"><?= rmi_icon('home') ?> Internal SCM</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="do-status-col">
        <?= badgeStatus($r['status']) ?>
        <span class="badge gray"><?= h($cur_scm) ?></span>
        <div class="sla-caption">Durasi SCM</div>
        <div class="sla-time<?= $scmRunning ? ' js-scm-timer' : '' ?>"<?= $scmRunning ? ' data-start="'.h((string)$r['scm_sla_started_at']).'"' : '' ?>><?= h(scm_format_duration($scmDurSec)) ?></div>
        <span class="<?= h($scmSla['class']) ?> js-scm-sla-badge"<?= ($scmRunning && $scmSlaTargetMinutes) ? ' data-target-sec="'.((int)$scmSlaTargetMinutes * 60).'"' : '' ?>><?= h($scmSla['label']) ?></span>
        <div class="sla-dates">
          <?php if (!empty($r['scm_sla_started_at'])): ?>Mulai: <?= h(date('d-m-Y H:i:s', strtotime((string)$r['scm_sla_started_at']))) ?><br><?php endif; ?>
          <?php if (!empty($r['scm_on_delivery_at'])): ?>Kirim: <?= h(date('d-m-Y H:i:s', strtotime((string)$r['scm_on_delivery_at']))) ?><br><?php endif; ?>
          <?php if (!empty($r['scm_delivered_at'])): ?>Selesai: <?= h(date('d-m-Y H:i:s', strtotime((string)$r['scm_delivered_at']))) ?><?php elseif ($scmRunning): ?>Timer berjalan<?php endif; ?>
        </div>
        <?php if ($r['scm_note'] ?? ''): ?>
          <span style="font-size:11px;color:var(--muted2);"><?= h($r['scm_note']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Card body: two columns -->
    <form method="post" enctype="multipart/form-data" class="scm-form">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="scm_signature_data" class="sig-data" value="">

      <div class="do-card-body">

        <!-- Left column: status/note + tracking -->
        <div class="form-section">
          <div class="form-section-title">Status &amp; Catatan</div>
          <div class="field-grid-2">
            <div class="field-row">
              <label>Status SCM</label>
              <select class="field select" name="scm_status">
                <option value="Pending" <?= $cur_scm==='Pending'?'selected':'' ?>>Pending</option>
                <option value="Open"    <?= $cur_scm==='Open'   ?'selected':'' ?>>Open</option>
                <option value="Done"    <?= $cur_scm==='Done'   ?'selected':'' ?>>Done</option>
              </select>
            </div>
            <div class="field-row">
              <label>Catatan</label>
              <input class="field" name="scm_note" placeholder="Catatan…" value="<?= h($r['scm_note'] ?? '') ?>">
            </div>
          </div>

          <div class="form-section-title" style="margin-top:6px">Metode Pengiriman</div>
          <div class="delivery-row">
            <select class="field select delivery-mode" name="delivery_mode" style="flex:0 0 160px">
              <option value="INTERNAL" <?= $dm==='INTERNAL'?'selected':'' ?>>INTERNAL (Team SCM)</option>
              <option value="VENDOR"   <?= $dm==='VENDOR'  ?'selected':'' ?>>VENDOR (Jasa Logistik)</option>
            </select>
            <select class="field select delivery-vendor" name="delivery_vendor_id" style="flex:1">
              <option value="0">— Pilih Vendor —</option>
              <?php foreach ($vendorList as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= $selVid===(int)$v['id']?'selected':'' ?>>
                  <?= h(($v['vendors_name'] ?? '') . ' (' . ($v['vendors_code'] ?? '') . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-section-title" style="margin-top:6px">Tracking</div>
          <div class="track-box">
            <div class="field-grid-2">
              <div class="field-row">
                <label>Provider</label>
                <select class="field select" name="carrier_provider">
                  <option value="BITESHIP" <?= $cp==='BITESHIP'?'selected':'' ?>>BITESHIP</option>
                </select>
              </div>
              <div class="field-row">
                <label>No Resi / AWB</label>
                <input class="field" name="carrier_tracking_no" placeholder="No resi…" value="<?= h($r['carrier_tracking_no'] ?? '') ?>">
              </div>
            </div>
            <div class="field-grid-2">
              <div class="field-row">
                <label>Kode Kurir</label>
                <input class="field" name="carrier_courier_code" placeholder="jne, jnt, sicepat…" value="<?= h($r['carrier_courier_code'] ?? '') ?>">
              </div>
              <div class="field-row">
                <label>Fallback Live URL</label>
                <input class="field" name="fallback_live_location_url" placeholder="https://…" value="<?= h($r['fallback_live_location_url'] ?? '') ?>">
              </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:3px;padding-top:2px">
              <div class="track-info-row"><span class="lbl">Status terakhir</span><span class="val"><?= h($r['tracking_last_status'] ?? '-') ?></span></div>
              <div class="track-info-row"><span class="lbl">Last sync</span><span class="val"><?= h($r['tracking_last_sync_at'] ?? '-') ?></span></div>
              <div class="track-info-row"><span class="lbl">Live GPS</span><span class="val"><?= h(($r['scm_live_lat'] && $r['scm_live_lng']) ? ($r['scm_live_lat'].','.$r['scm_live_lng'].' @ '.($r['scm_live_at']??'-')) : '-') ?></span></div>
              <?php if ($trackingPublicUrl !== ''): ?>
              <div class="track-info-row"><span class="lbl">Public link</span><a href="<?= h($trackingPublicUrl) ?>" target="_blank">Buka ↗</a></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Right column: uploads + signature -->
        <div class="form-section">
          <div class="form-section-title">Bukti Terima dari WQS <span class="badge gray" style="font-size:9px">WAJIB SEBELUM ON DELIVERY</span></div>
          <div style="font-size:11px;color:var(--muted2);margin:-2px 0 8px">
            Pilih foto atau video. Tidak perlu klik Simpan terlebih dahulu — tombol <b>Set ON DELIVERY</b> akan menyimpan dan memverifikasi bukti sekaligus.
          </div>
          <div class="proof-grid">
            <div class="proof-item">
              <div class="lbl">📷 Foto saat ini</div>
              <?php if ($r['scm_receive_photo']): ?>
                <a class="proof-link" href="<?= h($r['scm_receive_photo']) ?>" target="_blank">Lihat ↗</a>
              <?php else: ?><span style="font-size:11px;color:var(--muted)">Belum ada</span><?php endif; ?>
            </div>
            <div class="proof-item">
              <div class="lbl">🎥 Video saat ini</div>
              <?php if ($r['scm_receive_video']): ?>
                <a class="proof-link" href="<?= h($r['scm_receive_video']) ?>" target="_blank">Lihat ↗</a>
              <?php else: ?><span style="font-size:11px;color:var(--muted)">Belum ada</span><?php endif; ?>
            </div>
          </div>
          <div class="field-grid-2">
            <div class="field-row">
              <label>Upload foto baru</label>
              <input class="field" type="file" name="scm_receive_photo" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="field-row">
              <label>Upload video baru</label>
              <input class="field" type="file" name="scm_receive_video" accept=".mp4,.mov,.m4v,.webm">
            </div>
          </div>

          <div class="form-section-title" style="margin-top:8px">Bukti Serah ke Customer (POD) <span class="badge gray" style="font-size:9px">WAJIB SEBELUM DELIVERED</span></div>
          <div style="font-size:11px;color:var(--muted2);margin:-2px 0 8px">
            Setelah sampai customer, pilih foto/video POD dan ambil TTD. Tombol <b>Set DELIVERED</b> akan menyimpan, memverifikasi, lalu menutup tracking.
          </div>
          <div class="proof-grid">
            <div class="proof-item">
              <div class="lbl">📷 Foto saat ini</div>
              <?php if ($r['scm_delivery_photo']): ?>
                <a class="proof-link" href="<?= h($r['scm_delivery_photo']) ?>" target="_blank">Lihat ↗</a>
              <?php else: ?><span style="font-size:11px;color:var(--muted)">Belum ada</span><?php endif; ?>
            </div>
            <div class="proof-item">
              <div class="lbl">🎥 Video saat ini</div>
              <?php if ($r['scm_delivery_video']): ?>
                <a class="proof-link" href="<?= h($r['scm_delivery_video']) ?>" target="_blank">Lihat ↗</a>
              <?php else: ?><span style="font-size:11px;color:var(--muted)">Belum ada</span><?php endif; ?>
            </div>
          </div>
          <div class="field-grid-2">
            <div class="field-row">
              <label>Upload foto baru</label>
              <input class="field" type="file" name="scm_delivery_photo" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="field-row">
              <label>Upload video baru</label>
              <input class="field" type="file" name="scm_delivery_video" accept=".mp4,.mov,.m4v,.webm">
            </div>
          </div>

          <div class="form-section-title" style="margin-top:8px">Tanda Tangan Digital Customer</div>
          <div class="sig-wrap">
            <div class="sig-label">
              <?php if ($r['scm_signature_data']): ?><span class="badge green" style="font-size:10px">Ada <?= rmi_icon('tick') ?></span>
              <?php else: ?><span class="badge gray" style="font-size:10px">Belum ada — wajib untuk DELIVERED</span><?php endif; ?>
            </div>
            <canvas class="sig" width="900" height="200"></canvas>
            <div class="sig-bar">
              <button type="button" class="btn sm" data-sig-clear>Bersihkan</button>
              <button type="button" class="btn sm primary" data-sig-save>Ambil TTD <?= rmi_icon('tick') ?></button>
            </div>
          </div>
        </div>

      </div><!-- /do-card-body -->

      <?php if (($r['status'] ?? '') === 'ready_scm'): ?>
      <div class="form-section" style="margin-top:10px;border-color:rgba(251,191,36,.25)">
        <div class="form-section-title">Request Revisi Sebelum Pengiriman</div>
        <div class="field-grid-2">
          <div class="field-row">
            <label>Jenis Revisi</label>
            <select class="field select" name="revision_type">
              <option value="ITEM">Salah Barang / Item</option>
              <option value="QTY">Salah Qty</option>
              <option value="PRICE">Salah Harga / Diskon / PPN</option>
              <option value="ADDRESS">Alamat / PIC</option>
              <option value="OTHER">Lainnya</option>
            </select>
          </div>
          <div class="field-row">
            <label>Alasan Revisi</label>
            <input class="field" name="revision_reason" placeholder="Contoh: salah harga / salah item, barang belum dikirim">
          </div>
        </div>
        <div style="font-size:11px;color:var(--muted2);margin-top:6px">
          Gunakan tombol ini hanya jika barang belum dikirim. Jika barang sudah sampai customer, gunakan alur retur.
        </div>
      </div>
      <?php endif; ?>

      <!-- Action bar -->
      <div class="action-bar">
        <button class="btn primary" name="action" value="save" type="submit" title="Opsional untuk menyimpan catatan/evidence sebagai draft tanpa mengubah status">💾 Simpan Draft</button>
        <button class="btn" name="action" value="refresh_tracking" type="submit">↺ Refresh Tracking</button>
        <a class="btn" href="./scm_tracker_mobile.php?do_id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">📍 Buka Tracker</a>
        <span class="sep"></span>
        <?php if (($r['status'] ?? '') === 'ready_scm'): ?>
          <button class="btn" name="action" value="request_revision" type="submit" onclick="return confirm('Kembalikan DO ini untuk revisi sebelum pengiriman?')">↩ Revisi / Kembalikan ke WQS</button>
          <button class="btn warn" name="action" value="on_delivery" type="submit">🚚 Simpan Bukti WQS + Set ON DELIVERY + Mulai Tracker</button>
        <?php endif; ?>
        <?php if (($r['status'] ?? '') === 'on_delivery'): ?>
          <button class="btn success" name="action" value="delivered" type="submit"><?= rmi_icon('check') ?> Simpan POD + TTD + Set DELIVERED</button>
        <?php endif; ?>
        <?php if (in_array((string)($r['status'] ?? ''), ['on_delivery','delivered','wait_payment','paid'], true)): ?>
        <a class="btn" href="sales_do_return.php?do_id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">↩ Ajukan Retur</a>
        <?php endif; ?>
        <a class="btn" href="./scm_tracking_history.php?do_id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">📍 History GPS</a>
        <a class="btn" href="<?= h($waShareUrl) ?>" target="_blank" rel="noopener">💬 Share WA</a>
      </div>

    </form>
  </div><!-- /do-card -->
  <?php endforeach; ?>

  <!-- History section -->
  <div class="section-divider" style="margin-top:20px"></div>
  <div class="section-hd">
    <h2>History</h2>
    <span class="count"><?= count($rows_history) ?></span>
    <span class="desc badge blue" style="font-size:10px">SCM SELESAI</span>
    <span class="desc">50 terbaru · tetap tersimpan walau status sudah lanjut ke ACT/FIN</span>
    <a class="btn sm" href="./scm_delivery_recap.php">Rekap Delivery →</a>
  </div>

  <div class="card">
    <?php if (!$rows_history): ?>
      <div class="empty-state">Belum ada histori penyelesaian SCM pada filter ini.</div>
    <?php else: ?>
    <div class="hist-table-wrap">
      <table class="hist-table">
        <thead>
          <tr>
            <th>DO Code</th>
            <th>Tanggal</th>
            <th>Customer</th>
            <th>Office</th>
            <th>Grand Total</th>
            <th>Delivery</th>
            <th>Delivered At</th>
            <th>Durasi SCM / SLA</th>
            <th>PIC SCM</th>
            <th>Status Saat Ini</th>
            <th>Bukti</th>
            <th>Tracking</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows_history as $r):
            $mode2  = strtoupper((string)($r['delivery_mode'] ?? 'INTERNAL'));
            $vid2   = (int)($r['delivery_vendor_id'] ?? 0);
            $vname2 = '';
            if ($vid2 > 0 && $vendorList) {
              foreach ($vendorList as $vv) { if ((int)$vv['id'] === $vid2) { $vname2 = ($vv['vendors_name'] ?? $vv['vendors_code']); break; } }
            }
          ?>
          <tr>
            <td>
              <div class="bold"><?= h($r['do_code']) ?></div>
              <div style="font-size:11px;color:var(--muted);margin-top:1px"><?= h($r['tracking_code'] ?? '') ?></div>
              <a style="font-size:11px" href="./sales_do_view.php?id=<?= (int)$r['id'] ?>" target="_blank">Detail ↗</a>
            </td>
            <td><?= h($r['do_date']) ?></td>
            <td>
              <div class="bold"><?= h(($r['customers_name'] ?? '') !== '' ? $r['customers_name'] : $r['customers_code']) ?></div>
              <div style="font-size:11px"><?= h($r['customers_code'] ?? '') ?></div>
            </td>
            <td><?= h($r['office_code']) ?></td>
            <td class="bold">Rp <?= h(number_format((float)($r['grand_total'] ?? 0), 0, ',', '.')) ?></td>
            <td>
              <span class="badge <?= $mode2==='VENDOR' ? 'yellow':'green' ?>"><?= h($mode2) ?></span>
              <?php if ($mode2 === 'VENDOR'): ?><div style="font-size:11px;margin-top:3px"><?= h($vname2 ?: '#'.$vid2) ?></div><?php endif; ?>
            </td>
            <td><?= h($r['scm_delivered_at'] ?? '-') ?></td>
            <?php $histScmDur = scm_effective_duration_sec($r); $histScmSla = scm_sla_meta($histScmDur, $scmSlaTargetMinutes); ?>
            <td>
              <div class="bold"><?= h(scm_format_duration($histScmDur)) ?></div>
              <span class="<?= h($histScmSla['class']) ?>" style="margin-top:4px"><?= h($histScmSla['label']) ?></span>
            </td>
            <td><?= h($r['scm_delivered_by'] ?? '-') ?></td>
            <td><?= badgeStatus($r['status'] ?? '') ?></td>
            <td>
              <div style="display:flex;flex-direction:column;gap:3px">
                <span><b>WQS:</b>
                  <?= $r['scm_receive_photo'] ? '<a class="proof-link" href="'.h($r['scm_receive_photo']).'" target="_blank">📷 Foto ↗</a>' : '<span style="color:var(--muted)">📷 -</span>' ?>
                  <?= $r['scm_receive_video'] ? ' <a class="proof-link" href="'.h($r['scm_receive_video']).'" target="_blank">🎥 Video ↗</a>' : ' <span style="color:var(--muted)">🎥 -</span>' ?>
                </span>
                <span><b>POD:</b>
                  <?= $r['scm_delivery_photo'] ? '<a class="proof-link" href="'.h($r['scm_delivery_photo']).'" target="_blank">📷 Foto ↗</a>' : '<span style="color:var(--muted)">📷 -</span>' ?>
                  <?= $r['scm_delivery_video'] ? ' <a class="proof-link" href="'.h($r['scm_delivery_video']).'" target="_blank">🎥 Video ↗</a>' : ' <span style="color:var(--muted)">🎥 -</span>' ?>
                </span>
                <span><b>TTD:</b> <?= $r['scm_signature_data'] ? '<span class="badge green" style="font-size:10px">Ada ' . rmi_icon('tick') . '</span>' : '<span style="color:var(--muted)">-</span>' ?></span>
              </div>
            </td>
            <td>
              <div style="font-size:11px;color:var(--muted2)"><?= h($r['tracking_last_status'] ?: '-') ?></div>
              <div style="font-size:10px;color:var(--muted)">sync: <?= h($r['tracking_last_sync_at'] ?: '-') ?></div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Audit log -->
  <div class="section-divider" style="margin-top:20px"></div>
  <div class="section-hd">
    <h2>Audit Log</h2>
    <span class="count"><?= count($audit_rows) ?></span>
    <span class="desc">50 event terbaru</span>
  </div>
  <div class="card">
    <?php if (empty($audit_rows)): ?>
      <div class="empty-state">Belum ada audit log.</div>
    <?php else: ?>
    <div class="hist-table-wrap">
      <table class="audit-table">
        <thead><tr><th>Waktu</th><th>Action</th><th>DO Code</th><th>User</th><th>Keterangan</th></tr></thead>
        <tbody>
        <?php foreach ($audit_rows as $a): ?>
          <tr>
            <td><?= h($a['created_at'] ?? '') ?></td>
            <td><code><?= h($a['action'] ?? '') ?></code></td>
            <td><?= h($a['record_code'] ?? '') ?></td>
            <td><?= h($a['username'] ?? '') ?></td>
            <td><?= h($a['description'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div style="font-size:11px;color:var(--muted);margin:16px 0 8px;padding:0 2px">
    Upload tersimpan ke <code>/uploads/sales_scm/</code> &nbsp;·&nbsp; Status <strong>DELIVERED</strong> memicu halaman ACT.
  </div>

</div><!-- /scm-wrap -->

<script>
(function(){
  var GPS_API = "<?php echo h(rtrim($baseProject, '/')); ?>/api/v1/internal/sales_scm_geo_ping.php";
  var CSRF_TOKEN = "<?php echo h(csrf_token()); ?>";
  var gpsWatchId = null;
  var gpsActiveDoId = 0;

  function sendGps(doId, lat, lng, acc){
    var fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('do_id', String(doId));
    fd.append('lat', String(lat));
    fd.append('lng', String(lng));
    fd.append('accuracy_m', String(acc || ''));
    return fetch(GPS_API, {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); });
  }

  function stopGps(){
    if (gpsWatchId !== null && navigator.geolocation) {
      navigator.geolocation.clearWatch(gpsWatchId);
    }
    gpsWatchId = null;
    gpsActiveDoId = 0;
  }

  function startGps(doId){
    if (!navigator.geolocation) {
      alert('Browser tidak support geolocation.');
      return;
    }
    stopGps();
    gpsActiveDoId = doId;
    gpsWatchId = navigator.geolocation.watchPosition(function(pos){
      if (!gpsActiveDoId) return;
      sendGps(gpsActiveDoId, pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy)
        .then(function(){ /* silent */ })
        .catch(function(){ /* silent */ });
    }, function(err){
      alert('Gagal akses GPS: ' + (err && err.message ? err.message : 'unknown'));
      stopGps();
    }, {
      enableHighAccuracy: true,
      timeout: 15000,
      maximumAge: 5000
    });
  }

  function setupCanvas(form){
    var canvas = form.querySelector('canvas.sig');
    var out = form.querySelector('input.sig-data');
    var btnSave = form.querySelector('[data-sig-save]');
    var btnClear = form.querySelector('[data-sig-clear]');
    if (!canvas || !out || !btnSave || !btnClear) return;

    var ctx = canvas.getContext('2d');
    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#e5e7eb';

    var drawing = false;

    function getPosFromEvent(e){
      var rect = canvas.getBoundingClientRect();
      var clientX, clientY;

      if (e.touches && e.touches[0]) {
        clientX = e.touches[0].clientX;
        clientY = e.touches[0].clientY;
      } else if (e.changedTouches && e.changedTouches[0]) {
        clientX = e.changedTouches[0].clientX;
        clientY = e.changedTouches[0].clientY;
      } else {
        clientX = e.clientX;
        clientY = e.clientY;
      }

      return {
        x: (clientX - rect.left) * (canvas.width / rect.width),
        y: (clientY - rect.top) * (canvas.height / rect.height)
      };
    }

    function start(e){
      drawing = true;
      var p = getPosFromEvent(e);
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      if (e.cancelable) e.preventDefault();
    }

    function move(e){
      if (!drawing) return;
      var p = getPosFromEvent(e);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      if (e.cancelable) e.preventDefault();
    }

    function end(e){
      if (!drawing) return;
      drawing = false;
      if (e && e.cancelable) e.preventDefault();
    }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    canvas.addEventListener('mouseup', end);
    canvas.addEventListener('mouseleave', end);

    canvas.addEventListener('touchstart', start, {passive:false});
    canvas.addEventListener('touchmove', move, {passive:false});
    canvas.addEventListener('touchend', end, {passive:false});
    canvas.addEventListener('touchcancel', end, {passive:false});

    btnClear.addEventListener('click', function(){
      ctx.clearRect(0,0,canvas.width,canvas.height);
      out.value = '';
    });

    btnSave.addEventListener('click', function(){
      out.value = canvas.toDataURL('image/png');
      btnSave.textContent = 'TTD tersimpan <?= rmi_icon('check') ?>';
      setTimeout(function(){ btnSave.textContent='Ambil TTD <?= rmi_icon('tick') ?>'; }, 1200);
    });
  }

  // Delivery mode toggle (internal vs vendor)
  function setupDeliveryToggle(form){
    var modeSel = form.querySelector('select.delivery-mode');
    var vendorSel = form.querySelector('select.delivery-vendor');
    if(!modeSel || !vendorSel) return;
    function apply(){
      var v = (modeSel.value||'INTERNAL').toUpperCase();
      if(v !== 'VENDOR'){
        vendorSel.value = '0';
        vendorSel.setAttribute('disabled','disabled');
        vendorSel.style.opacity = '0.6';
      } else {
        vendorSel.removeAttribute('disabled');
        vendorSel.style.opacity = '1';
      }
    }
    modeSel.addEventListener('change', apply);
    apply();
  }

  document.querySelectorAll('form.scm-form').forEach(function(f){ setupCanvas(f); setupDeliveryToggle(f); });

  /* GPS dipusatkan di scm_tracker_mobile.php agar tidak ada dua watcher bersamaan.
  document.querySelectorAll('.gps-start-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var doId = parseInt(btn.getAttribute('data-do-id') || '0', 10);
      if (!doId) return;
      startGps(doId);
      btn.textContent = 'Auto GPS ON';
      setTimeout(function(){ btn.textContent='Start Auto GPS'; }, 1800);
    });
  });
  document.querySelectorAll('.gps-stop-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      stopGps();
      btn.textContent = 'Auto GPS OFF';
      setTimeout(function(){ btn.textContent='Stop Auto GPS'; }, 1800);
    });
  });
  window.addEventListener('beforeunload', function(){ stopGps(); });
  */
})();
</script>

<script>
(function(){
  function fmt(sec){
    sec=Math.max(0,Math.floor(sec||0));
    var d=Math.floor(sec/86400), h=Math.floor((sec%86400)/3600), m=Math.floor((sec%3600)/60), ss=sec%60, p=[];
    if(d>0)p.push(d+' hari'); if(h>0)p.push(h+' jam'); if(m>0)p.push(m+' menit'); if(ss>0||p.length===0)p.push(ss+' detik');
    return p.join(' ');
  }
  function refresh(){
    var now=Date.now();
    document.querySelectorAll('.js-scm-timer[data-start]').forEach(function(el){
      var start=Date.parse((el.getAttribute('data-start')||'').replace(' ','T'));
      if(!Number.isFinite(start))return;
      var sec=Math.max(0,Math.floor((now-start)/1000));
      el.textContent=fmt(sec);
      var parent=el.parentElement;
      var badge=parent?parent.querySelector('.js-scm-sla-badge[data-target-sec]'):null;
      if(!badge)return;
      var target=parseInt(badge.getAttribute('data-target-sec')||'0',10); if(!target)return;
      badge.classList.remove('blue','green','yellow','red','gray');
      if(sec>target){badge.textContent='LEWAT SLA';badge.classList.add('red');}
      else if(sec>=Math.floor(target*.8)){badge.textContent='MENDEKATI SLA';badge.classList.add('yellow');}
      else{badge.textContent='AMAN';badge.classList.add('green');}
    });
  }
  refresh(); window.setInterval(refresh,1000);
})();
</script>

<script>
(function(){
  function mobileFormFix(){
    if(window.innerWidth > 768) return;
    document.querySelectorAll('input, textarea, select, button').forEach(function(el){
      el.style.pointerEvents = 'auto';
      el.style.touchAction = 'manipulation';
    });
    document.querySelectorAll('input, textarea, select').forEach(function(el){
      el.addEventListener('touchend', function(){ try{ el.focus(); }catch(e){} }, {passive:true});
    });
  }
  document.addEventListener('DOMContentLoaded', mobileFormFix);
  window.addEventListener('resize', mobileFormFix);
})();
</script>

<?php rmi_footer(); ?>
