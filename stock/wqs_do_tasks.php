<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
require_once __DIR__ . '/../_shared/rmi_branch_guard.php';
$__wqs_depo_restricted = function_exists('rmi_is_depo_branch_session') && rmi_is_depo_branch_session();

if (function_exists('require_any_permission') && !$__wqs_depo_restricted) {
    require_any_permission(['SALES.EDIT', 'SALES.VIEW', 'WQS.DO_TASKS']);
} elseif (!function_exists('require_any_permission')) {
    require_role(['SYS','SUPERADMIN','ADMIN','WQS','BRANCH']);
}

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/../_shared/helpers.php'; // ensure safe_filename/csrf helpers
require_once __DIR__ . '/../stock/_stock_office_helper.php';

// sales/wqs_do_tasks.php
// WQS - Task DO dari CRM (cek stok + foto kartu stok sebelum/sesudah + set READY SCM)
// ERP_RMI_SOFULL flow: CRM -> WQS -> SCM -> ACT -> FIN

// --- KONEKSI DB (pakai helper terpusat) ---
function connect_pdo_robust(): PDO {
    $errors = [];

    try {
        if (function_exists('db_pdo')) {
            $p = db_pdo();
            if ($p instanceof PDO) return $p;
        }
    } catch (Throwable $e) {
        $errors[] = 'db_pdo(): ' . $e->getMessage();
    }

    try {
        if (function_exists('rmi_db_pdo')) {
            $p = rmi_db_pdo();
            if ($p instanceof PDO) return $p;
        }
    } catch (Throwable $e) {
        $errors[] = 'rmi_db_pdo(): ' . $e->getMessage();
    }

    $msg = empty($errors) ? 'Unknown DB connection error' : implode(' | ', $errors);
    throw new PDOException($msg);
}

try {
    $pdo = connect_pdo_robust();
} catch (Throwable $e) {
    http_response_code(500);
    die("Koneksi database gagal: " . htmlspecialchars($e->getMessage()));
}

// ---------------- helpers ----------------
if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

// ── Office Scope & Access Mode ───────────────────────────────────────────────
$_wqs_user     = function_exists('auth_user') ? auth_user() : [];
$_wqs_role     = strtoupper(trim((string)($_wqs_user['role'] ?? '')));
$_wqs_level    = strtoupper(trim((string)($_wqs_user['level'] ?? '')));
$_wqs_dept     = strtoupper(trim((string)(($_wqs_user['department'] ?? '') ?: ($_wqs_user['dept'] ?? '') ?: '')));
$_wqs_office   = strtoupper(trim((string)($_wqs_user['office_code'] ?? '')));
$_wqs_username = strtoupper(trim((string)($_wqs_user['username'] ?? $_wqs_user['user_name'] ?? '')));

$_wqs_is_admin = in_array($_wqs_role, ['SYS','ADMIN','SUPERADMIN'], true);
$_wqs_is_wqs   = ($_wqs_role === 'WQS' || $_wqs_dept === 'WQS');
$_wqs_is_wqs_manager = $_wqs_is_wqs && (
    in_array($_wqs_role, ['MANAGER','MGR','OWNER','SYS','SUPERADMIN','ADMIN'], true)
    || in_array($_wqs_level, ['MANAGER','MGR','OWNER'], true)
    || str_starts_with($_wqs_username, 'MGRWQS_')
);
$_wqs_is_wqs_staff = $_wqs_is_wqs && !$_wqs_is_wqs_manager && !$_wqs_is_admin;
$_wqs_is_branch = ($_wqs_role === 'BRANCH' || $_wqs_dept === 'BRANCH') && !$_wqs_is_admin;

/*
 * OFFICE OWNERSHIP — termasuk UNIT ACC.
 * UNIT ACC bukan office tersendiri. DO Unit ACC tetap memakai office asal barang,
 * saat ini BGR, sehingga otomatis ditangani WQS BGR. Jangan membuat exception ACCUNIT.
 *
 * - SYS/Admin           : lintas office
 * - Manager WQS         : lintas office
 * - Staff WQS           : hanya office akun sendiri
 * - Branch              : hanya office akun sendiri
 */
$_wqs_can_process = $_wqs_is_admin || $_wqs_is_wqs_manager || $_wqs_is_wqs_staff || $_wqs_is_branch;

/*
 * UNIT ACC PREPARATION — exception operasional yang sempit.
 * StaffBRANCH_BGR tetap akun BRANCH/BGR, tetapi pada halaman WQS ini hanya
 * diperbolehkan menyiapkan DO yang mengandung item UNIT ACC (ALKES/AKSESORIS).
 * BMHP biasa tetap menjadi tanggung jawab WQS dan tidak ditampilkan ke akun ini.
 */
$_wqs_is_branch_bgr_unit_acc = $_wqs_is_branch
    && $_wqs_username === 'STAFFBRANCH_BGR'
    && $_wqs_office === 'BGR';

function wqs_table_columns_safe(PDO $pdo, string $table): array {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`','', $table) . "`");
        $out = [];
        foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
            $f = (string)($r['Field'] ?? '');
            if ($f !== '') $out[] = $f;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

function wqs_unit_acc_sql_parts(PDO $pdo, string $iAlias='iua', string $pAlias='pua'): array {
    $ic = wqs_table_columns_safe($pdo, 'sales_do_items');
    $pc = wqs_table_columns_safe($pdo, 'master_products');
    if (!$ic) return ['join'=>'', 'where'=>'0=1'];
    $conds = [];
    if (in_array('business_group',$ic,true)) $conds[] = "UPPER(TRIM(COALESCE({$iAlias}.business_group,'')))='UNIT_ACC'";
    if (in_array('category',$ic,true)) $conds[] = "UPPER(TRIM(COALESCE({$iAlias}.category,''))) IN ('UNIT_ACC','ALKES','AKSESORIS')";
    $join = '';
    if ($pc && in_array('product_id',$ic,true) && in_array('id',$pc,true)) {
        $join = " LEFT JOIN master_products {$pAlias} ON {$pAlias}.id={$iAlias}.product_id ";
        if (in_array('business_group',$pc,true)) $conds[] = "UPPER(TRIM(COALESCE({$pAlias}.business_group,'')))='UNIT_ACC'";
        if (in_array('category',$pc,true)) $conds[] = "UPPER(TRIM(COALESCE({$pAlias}.category,''))) IN ('UNIT_ACC','ALKES','AKSESORIS')";
    }
    return ['join'=>$join, 'where'=>$conds ? '(' . implode(' OR ', $conds) . ')' : '0=1'];
}

function wqs_do_has_unit_acc(PDO $pdo, int $doId): bool {
    if ($doId <= 0) return false;
    try {
        // Primary: klasifikasi item/master product.
        $x = wqs_unit_acc_sql_parts($pdo, 'iua', 'pua');
        $st = $pdo->prepare("SELECT 1 FROM sales_do_items iua {$x['join']} WHERE iua.do_id=? AND {$x['where']} LIMIT 1");
        $st->execute([$doId]);
        if ((bool)$st->fetchColumn()) return true;

        // Compatibility fallback: DO Unit ACC produksi memakai prefix UNITACC-.
        // Ini penting untuk data lama/DO yang kolom business_group/category item-nya belum terisi.
        $st2 = $pdo->prepare("SELECT 1 FROM sales_do WHERE id=? AND (UPPER(TRIM(COALESCE(do_code,''))) LIKE 'UNITACC-%' OR UPPER(TRIM(COALESCE(tracking_code,''))) LIKE 'UNITACC-%') LIMIT 1");
        $st2->execute([$doId]);
        return (bool)$st2->fetchColumn();
    } catch (Throwable $e) { return false; }
}

// Pembatalan/arsip DO adalah aksi berisiko tinggi.
// Hanya SYS/Admin/Manager WQS/Staff WQS. BRANCH tidak boleh cancel DO.
$_wqs_can_cancel = $_wqs_is_admin || $_wqs_is_wqs_manager || $_wqs_is_wqs_staff;

$WQS_LEGACY_TRIAL_FROM = '2026-04-21';
$WQS_LEGACY_TRIAL_TO = '2026-06-30';
$WQS_LEGACY_ACTIVE_STATUSES = ['crm_to_wqs','sent_wqs','revision_requested','wqs_processing'];

// Staff WQS dan Branch wajib office-scoped. Manager/Admin tetap lintas office.
$_wqs_scope_required = ($_wqs_is_wqs_staff || $_wqs_is_branch);
$_wqs_office_scope = $_wqs_scope_required ? $_wqs_office : null;

function ensure_column(PDO $pdo, string $table, string $column, string $ddl): void {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $exists = (bool)$stmt->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        }
    } catch (Throwable $e) {
        // fail-soft
    }
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function upload_file(string $field, string $dirRel): ?string {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;

    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

    $tmp = $f['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) return null;

    $name = (string)($f['name'] ?? 'file');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allow = ['jpg','jpeg','png','webp','pdf'];
    if (!in_array($ext, $allow, true)) return null;

    $base = realpath(__DIR__ . '/..'); // project root
    if ($base === false) return null;

    $dirAbs = $base . '/' . trim($dirRel, '/');
    if (!is_dir($dirAbs)) {
        @mkdir($dirAbs, 0777, true);
    }

    $clean = safe_filename($name);
    $basePart = pathinfo($clean, PATHINFO_FILENAME);
    $ext2 = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
    if ($basePart === '') $basePart = 'file';
    if ($ext2 === '') $ext2 = $ext;

    $fname = $basePart . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext2;
    $destAbs = $dirAbs . '/' . $fname;

    if (!@move_uploaded_file($tmp, $destAbs)) return null;

    return '/' . trim($dirRel, '/') . '/' . $fname;
}

/**
 * Upload banyak foto/file untuk bukti kartu stok.
 * Mendukung input:
 * - name="wqs_stock_before[]" multiple
 * - name="wqs_stock_after[]" multiple
 * Tetap aman jika browser mengirim single file.
 */
function upload_files_multi(string $field, string $dirRel): array {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return [];

    $f = $_FILES[$field];
    $names = $f['name'] ?? null;
    if ($names === null) return [];

    // Normalisasi single file menjadi array.
    if (!is_array($names)) {
        $f = [
            'name' => [$f['name'] ?? 'file'],
            'type' => [$f['type'] ?? ''],
            'tmp_name' => [$f['tmp_name'] ?? ''],
            'error' => [$f['error'] ?? UPLOAD_ERR_NO_FILE],
            'size' => [$f['size'] ?? 0],
        ];
    }

    $base = realpath(__DIR__ . '/..'); // project root
    if ($base === false) return [];

    $dirAbs = $base . '/' . trim($dirRel, '/');
    if (!is_dir($dirAbs)) {
        @mkdir($dirAbs, 0777, true);
    }

    $allow = ['jpg','jpeg','png','webp','pdf'];
    $saved = [];

    foreach (($f['name'] ?? []) as $i => $name) {
        $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) continue;

        $tmp = (string)($f['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;

        $name = (string)($name ?: 'file');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allow, true)) continue;

        $clean = safe_filename($name);
        $basePart = pathinfo($clean, PATHINFO_FILENAME);
        $ext2 = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        if ($basePart === '') $basePart = 'file';
        if ($ext2 === '') $ext2 = $ext;

        $fname = $basePart . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext2;
        $destAbs = $dirAbs . '/' . $fname;

        if (@move_uploaded_file($tmp, $destAbs)) {
            $saved[] = [
                'path' => '/' . trim($dirRel, '/') . '/' . $fname,
                'original' => $name,
            ];
        }
    }

    return $saved;
}

function ensure_wqs_stock_card_photos_table(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_stock_card_photos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            do_id INT NOT NULL,
            photo_type ENUM('BEFORE','AFTER') NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            uploaded_by VARCHAR(100) NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_do_id (do_id),
            KEY idx_photo_type (photo_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {
        // fail-soft, nanti validasi akan menangkap jika tabel tidak tersedia.
    }
}

function save_wqs_stock_card_photos(PDO $pdo, int $do_id, string $type, array $files): void {
    if (!$files) return;
    ensure_wqs_stock_card_photos_table($pdo);
ensure_wqs_handover_media_table($pdo);

    $type = strtoupper($type) === 'AFTER' ? 'AFTER' : 'BEFORE';
    $actor = audit_actor_name();

    $st = $pdo->prepare("
        INSERT INTO sales_do_stock_card_photos
            (do_id, photo_type, file_path, original_name, uploaded_by)
        VALUES (?,?,?,?,?)
    ");

    foreach ($files as $f) {
        $path = (string)($f['path'] ?? '');
        if ($path === '') continue;
        $st->execute([
            $do_id,
            $type,
            $path,
            (string)($f['original'] ?? ''),
            $actor,
        ]);
    }
}

function wqs_stock_card_counts(PDO $pdo, int $do_id): array {
    ensure_wqs_stock_card_photos_table($pdo);
    try {
        $st = $pdo->prepare("
            SELECT
              SUM(CASE WHEN photo_type='BEFORE' THEN 1 ELSE 0 END) AS total_before,
              SUM(CASE WHEN photo_type='AFTER' THEN 1 ELSE 0 END) AS total_after
            FROM sales_do_stock_card_photos
            WHERE do_id=?
        ");
        $st->execute([$do_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'before' => (int)($r['total_before'] ?? 0),
            'after' => (int)($r['total_after'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['before' => 0, 'after' => 0];
    }
}

function wqs_stock_card_first(PDO $pdo, int $do_id, string $type): string {
    ensure_wqs_stock_card_photos_table($pdo);
    try {
        $st = $pdo->prepare("
            SELECT file_path
            FROM sales_do_stock_card_photos
            WHERE do_id=? AND photo_type=?
            ORDER BY id ASC
            LIMIT 1
        ");
        $st->execute([$do_id, strtoupper($type) === 'AFTER' ? 'AFTER' : 'BEFORE']);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function fetch_wqs_stock_card_photos(PDO $pdo, array $doIds): array {
    ensure_wqs_stock_card_photos_table($pdo);
    $doIds = array_values(array_unique(array_filter(array_map('intval', $doIds))));
    if (!$doIds) return [];

    $ph = implode(',', array_fill(0, count($doIds), '?'));
    $map = [];
    try {
        $st = $pdo->prepare("
            SELECT do_id, photo_type, file_path, original_name, uploaded_at
            FROM sales_do_stock_card_photos
            WHERE do_id IN ($ph)
            ORDER BY do_id ASC, photo_type ASC, id ASC
        ");
        $st->execute($doIds);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$row['do_id'];
            $type = strtoupper((string)$row['photo_type']);
            if (!isset($map[$id])) $map[$id] = ['BEFORE' => [], 'AFTER' => []];
            $map[$id][$type][] = $row;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $map;
}

// ---------------- BUKTI SERAH TERIMA WQS -> SCM (TAMBAHAN) ----------------
// Terpisah total dari Foto Kartu Stok BEFORE/AFTER. Tidak mengubah validasi/lock alur lama.
function ensure_wqs_handover_media_table(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_wqs_handover_media (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            do_id INT NOT NULL,
            media_type ENUM('PHOTO','VIDEO') NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            uploaded_by VARCHAR(100) NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_do_id (do_id), KEY idx_media_type (media_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) { /* fail-soft */ }
}

function upload_wqs_handover_multi(string $field, string $dirRel, string $kind): array {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return [];
    $f=$_FILES[$field]; $names=$f['name'] ?? null; if ($names===null) return [];
    if (!is_array($names)) $f=['name'=>[$f['name']??'file'],'tmp_name'=>[$f['tmp_name']??''],'error'=>[$f['error']??UPLOAD_ERR_NO_FILE],'size'=>[$f['size']??0]];
    $base=realpath(__DIR__.'/..'); if ($base===false) return [];
    $dirAbs=$base.'/'.trim($dirRel,'/'); if (!is_dir($dirAbs)) @mkdir($dirAbs,0777,true);
    $kind=strtoupper($kind)==='VIDEO'?'VIDEO':'PHOTO';
    $allow=$kind==='VIDEO'?['mp4','mov','webm']:['jpg','jpeg','png','webp'];
    $max=$kind==='VIDEO'?80*1024*1024:10*1024*1024; $saved=[];
    foreach (($f['name']??[]) as $i=>$name) {
        if ((int)($f['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) continue;
        $tmp=(string)($f['tmp_name'][$i]??''); $size=(int)($f['size'][$i]??0);
        if ($tmp==='' || !is_uploaded_file($tmp) || $size<=0 || $size>$max) continue;
        $name=(string)($name?:'file'); $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION)); if (!in_array($ext,$allow,true)) continue;
        $clean=safe_filename($name); $bp=pathinfo($clean,PATHINFO_FILENAME) ?: 'file';
        $fname=$bp.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.'.$ext;
        if (@move_uploaded_file($tmp,$dirAbs.'/'.$fname)) $saved[]=['path'=>'/'.trim($dirRel,'/').'/'.$fname,'original'=>$name];
    }
    return $saved;
}

function save_wqs_handover_media(PDO $pdo, int $doId, string $type, array $files): void {
    if (!$files) return; ensure_wqs_handover_media_table($pdo); $actor=audit_actor_name();
    $type=strtoupper($type)==='VIDEO'?'VIDEO':'PHOTO';
    $st=$pdo->prepare("INSERT INTO sales_do_wqs_handover_media (do_id,media_type,file_path,original_name,uploaded_by) VALUES (?,?,?,?,?)");
    foreach ($files as $f) if (!empty($f['path'])) $st->execute([$doId,$type,(string)$f['path'],(string)($f['original']??''),$actor]);
}

function fetch_wqs_handover_media(PDO $pdo, array $doIds): array {
    ensure_wqs_handover_media_table($pdo); $ids=array_values(array_unique(array_filter(array_map('intval',$doIds)))); if (!$ids) return [];
    $ph=implode(',',array_fill(0,count($ids),'?')); $map=[];
    try { $st=$pdo->prepare("SELECT do_id,media_type,file_path,original_name,uploaded_at FROM sales_do_wqs_handover_media WHERE do_id IN ($ph) ORDER BY do_id,id"); $st->execute($ids);
        while($r=$st->fetch(PDO::FETCH_ASSOC)){ $id=(int)$r['do_id']; if(!isset($map[$id]))$map[$id]=['PHOTO'=>[],'VIDEO'=>[]]; $map[$id][strtoupper((string)$r['media_type'])][]=$r; }
    } catch(Throwable $e){ return []; } return $map;
}

// ---------------- AUDIT TRAIL (ringan) ----------------
function ensure_audit_table(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            do_id INT NOT NULL,
            status_from VARCHAR(50) NULL,
            status_to VARCHAR(50) NULL,
            actor_dept VARCHAR(50) NULL,
            actor_name VARCHAR(100) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_do_id (do_id),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {
        // fail-soft
    }
}

function audit_actor_name(): string {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $u = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? '';
    $u = trim((string)$u);
    return $u !== '' ? $u : 'SYSTEM';
}

function sales_do_audit_append(PDO $pdo, int $do_id, ?string $from, ?string $to, string $dept, string $note=''): void {
    try {
        ensure_audit_table($pdo);
        $actor = audit_actor_name();
        $st = $pdo->prepare("
            INSERT INTO sales_do_audit
                (do_id, status_from, status_to, actor_dept, actor_name, note)
            VALUES (?,?,?,?,?,?)
        ");
        $st->execute([$do_id, $from, $to, $dept, $actor, $note]);
    } catch (Throwable $e) {
        // fail-soft
    }
}


// ---------------- KPI / SLA WQS ----------------
/** Format durasi WQS dengan pola yang mudah dibaca untuk KPI. */
function wqs_format_duration(?int $sec): string {
    if ($sec === null) return '-';
    $sec = max(0, $sec);
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    $parts = [];
    if ($d > 0) $parts[] = $d . ' hari';
    if ($h > 0) $parts[] = $h . ' jam';
    if ($m > 0) $parts[] = $m . ' menit';
    if ($s > 0 || !$parts) $parts[] = $s . ' detik';
    return implode(' ', $parts);
}

/**
 * SLA WQS dimulai saat DO selesai di CRM / masuk antrean WQS:
 * wqs_received_at -> wqs_ready_at (READY SCM).
 * wqs_started_at tetap dipakai sebagai timestamp operasional tombol Mulai Proses,
 * tetapi TIDAK lagi menjadi titik awal SLA.
 */
function wqs_effective_duration_sec(array $row, ?int $nowTs = null): ?int {
    $status = strtolower(trim((string)($row['status'] ?? '')));

    // DO yang dibatalkan/void/diarsipkan bukan penyelesaian operasional WQS,
    // sehingga tidak boleh menghasilkan durasi KPI/SLA dan timer harus berhenti.
    if (in_array($status, ['cancelled','canceled','void','archived'], true)) {
        return null;
    }

    // SLA dimulai dari timestamp DO dibuat/selesai oleh CRM.
    // Jangan gunakan wqs_started_at karena itu hanya waktu operator WQS menekan Mulai Proses.
    $startRaw = trim((string)($row['wqs_crm_done_at'] ?? ''));
    if ($startRaw === '') $startRaw = trim((string)($row['wqs_received_at'] ?? ''));
    // Fallback terakhir hanya untuk data legacy yang tidak memiliki timestamp CRM/received.
    if ($startRaw === '') $startRaw = trim((string)($row['wqs_started_at'] ?? ''));
    if ($startRaw === '') return null;
    $startTs = strtotime($startRaw);
    if ($startTs === false) return null;

    // Jangan gunakan wqs_duration_sec legacy di tampilan SLA.
    // Nilai lama disimpan dari wqs_started_at -> wqs_ready_at sehingga dapat tampil 9/13 detik.
    // SLA selalu dihitung ulang dari handoff CRM -> WQS sampai READY SCM.

    $finishRaw = trim((string)($row['wqs_ready_at'] ?? ''));
    if ($finishRaw !== '') {
        $finishTs = strtotime($finishRaw);
        if ($finishTs !== false && $finishTs >= $startTs) {
            return max(0, (int)$finishTs - (int)$startTs);
        }
    }

    // Selama DO sudah berada di antrean WQS, SLA harus terus berjalan meskipun
    // operator belum menekan tombol Mulai Proses.
    if (in_array($status, ['crm_to_wqs','sent_wqs','revision_requested','wqs_processing'], true)) {
        $nowTs = $nowTs ?? time();
        return max(0, $nowTs - (int)$startTs);
    }
    return null;
}

/**
 * Baca target SLA WQS dari system_config group KPI_DO_SLA secara fail-soft.
 * Jika schema/key belum tersedia, durasi tetap bekerja dan badge target disembunyikan.
 */
function wqs_sla_target_minutes(PDO $pdo): ?int {
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
            $keys = ['WQS_MINUTES','WQS_SLA_MINUTES','SLA_WQS_MINUTES'];
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $st = $pdo->prepare("SELECT `{$valueCol}` FROM system_config WHERE UPPER(`{$groupCol}`)=? AND UPPER(`{$keyCol}`) IN ({$ph}) LIMIT 1");
            $st->execute(array_merge(['KPI_DO_SLA'], $keys));
        } else {
            $keys = ['KPI_DO_SLA.WQS_MINUTES','KPI_DO_SLA_WQS_MINUTES','WQS_MINUTES'];
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

function wqs_sla_meta(?int $durationSec, ?int $targetMinutes): array {
    if ($durationSec === null) return ['label'=>'BELUM MULAI','class'=>'tag'];
    if (!$targetMinutes || $targetMinutes <= 0) return ['label'=>'DURASI','class'=>'tag blue'];
    $targetSec = $targetMinutes * 60;
    if ($durationSec > $targetSec) return ['label'=>'LEWAT SLA','class'=>'tag red'];
    if ($durationSec >= (int)floor($targetSec * 0.8)) return ['label'=>'MENDEKATI SLA','class'=>'tag yellow'];
    return ['label'=>'AMAN','class'=>'tag green'];
}

// -------------- schema guards --------------
ensure_column($pdo, 'sales_do', 'status', "status VARCHAR(50) NOT NULL DEFAULT 'crm_to_wqs'");
ensure_column($pdo, 'sales_do', 'wqs_status', "wqs_status VARCHAR(20) NULL");
ensure_column($pdo, 'sales_do', 'wqs_note', "wqs_note TEXT NULL");
ensure_column($pdo, 'sales_do', 'wqs_stock_before', "wqs_stock_before VARCHAR(255) NULL");
ensure_column($pdo, 'sales_do', 'wqs_stock_after', "wqs_stock_after VARCHAR(255) NULL");
ensure_column($pdo, 'sales_do', 'wqs_received_at', "wqs_received_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'wqs_started_at', "wqs_started_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'wqs_ready_at', "wqs_ready_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'wqs_duration_sec', "wqs_duration_sec BIGINT UNSIGNED NULL");
ensure_column($pdo, 'sales_do', 'last_updated_by', "last_updated_by VARCHAR(50) NULL");
// Actor columns are optional for compatibility with older databases. The page
// only writes them when the columns really exist, so START/READY never fails
// merely because a production DB has not yet been migrated.
ensure_column($pdo, 'sales_do', 'wqs_updated_by', "wqs_updated_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'wqs_started_by', "wqs_started_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'wqs_ready_by', "wqs_ready_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'wqs_cancelled_at', "wqs_cancelled_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'wqs_cancelled_by', "wqs_cancelled_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'wqs_cancel_reason', "wqs_cancel_reason TEXT NULL");
ensure_column($pdo, 'sales_do', 'revision_reason', "revision_reason TEXT NULL");
ensure_column($pdo, 'sales_do', 'revision_requested_by', "revision_requested_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'revision_requested_at', "revision_requested_at DATETIME NULL");
ensure_wqs_stock_card_photos_table($pdo);
$hasWqsReceivedAtCol = table_has_column($pdo, 'sales_do', 'wqs_received_at');
$hasCreatedAtCol = table_has_column($pdo, 'sales_do', 'created_at');
$hasLastUpdatedAtCol = table_has_column($pdo, 'sales_do', 'last_updated_at');
// Production DB may not allow ALTER TABLE. Never hard-reference wqs_received_at unless it really exists.
// If absent, CRM-created timestamp is used as the stable SLA start; last_updated_at is only the final fallback.
$wqsSlaStartExpr = $hasCreatedAtCol
    ? "COALESCE(created_at, " . ($hasWqsReceivedAtCol ? "wqs_received_at, " : "") . ($hasLastUpdatedAtCol ? "last_updated_at, " : "") . "NOW())"
    : ($hasWqsReceivedAtCol ? "COALESCE(wqs_received_at, NOW())" : ($hasLastUpdatedAtCol ? "COALESCE(last_updated_at, NOW())" : "NOW()"));
$wqsReceivedSelect = $hasWqsReceivedAtCol ? 'wqs_received_at' : ($hasCreatedAtCol ? 'created_at AS wqs_received_at' : ($hasLastUpdatedAtCol ? 'last_updated_at AS wqs_received_at' : 'NULL AS wqs_received_at'));
$wqsReceivedSelectD = $hasWqsReceivedAtCol ? 'd.wqs_received_at' : ($hasCreatedAtCol ? 'd.created_at AS wqs_received_at' : ($hasLastUpdatedAtCol ? 'd.last_updated_at AS wqs_received_at' : 'NULL AS wqs_received_at'));
// Titik awal SLA harus timestamp HANDOFF CRM -> WQS, bukan saat operator menekan Mulai Proses.
// Ambil event paling awal ketika DO masuk status antrean WQS dari audit trail.
// Fallback hanya untuk data lama yang belum memiliki event audit.
ensure_audit_table($pdo);
$wqsAuditHandoffExpr = "(SELECT MIN(a.created_at) FROM sales_do_audit a WHERE a.do_id=d.id AND LOWER(COALESCE(a.status_to,'')) IN ('crm_to_wqs','sent_wqs'))";
if ($hasWqsReceivedAtCol && $hasCreatedAtCol) {
    $wqsCrmDoneSelectD = "COALESCE({$wqsAuditHandoffExpr}, d.wqs_received_at, d.created_at) AS wqs_crm_done_at";
} elseif ($hasWqsReceivedAtCol) {
    $wqsCrmDoneSelectD = "COALESCE({$wqsAuditHandoffExpr}, d.wqs_received_at) AS wqs_crm_done_at";
} elseif ($hasCreatedAtCol) {
    $wqsCrmDoneSelectD = "COALESCE({$wqsAuditHandoffExpr}, d.created_at) AS wqs_crm_done_at";
} elseif ($hasLastUpdatedAtCol) {
    $wqsCrmDoneSelectD = "COALESCE({$wqsAuditHandoffExpr}, d.last_updated_at) AS wqs_crm_done_at";
} else {
    $wqsCrmDoneSelectD = "{$wqsAuditHandoffExpr} AS wqs_crm_done_at";
}
$hasWqsDurationCol = table_has_column($pdo, 'sales_do', 'wqs_duration_sec');
$hasWqsCancelledAtCol = table_has_column($pdo, 'sales_do', 'wqs_cancelled_at');
$hasWqsCancelledByCol = table_has_column($pdo, 'sales_do', 'wqs_cancelled_by');
$hasWqsCancelReasonCol = table_has_column($pdo, 'sales_do', 'wqs_cancel_reason');
$wqsSlaTargetMinutes = wqs_sla_target_minutes($pdo);

// SLA WQS mulai saat CRM selesai membuat/mengirim DO ke WQS, bukan saat WQS
// menekan tombol Mulai Proses. Untuk DO yang sudah ada sebelum patch ini,
// ambil timestamp terakhir milik CRM sebelum WQS menyentuh record. Pada data
// normal crm_to_wqs/sent_wqs, last_updated_at adalah waktu handoff CRM -> WQS.
// created_at dipakai sebagai fallback aman untuk schema/data lama.
try {
    if (!$hasWqsReceivedAtCol) throw new RuntimeException('wqs_received_at unavailable; using schema-safe fallback');
    $hasCreatedAt = table_has_column($pdo, 'sales_do', 'created_at');
    $hasLastUpdatedAt = table_has_column($pdo, 'sales_do', 'last_updated_at');
    // Jangan backfill dari last_updated_at: field itu berubah saat WQS bekerja dan dapat
    // membuat SLA palsu 9/13 detik. Prioritaskan audit handoff CRM -> WQS.
    ensure_audit_table($pdo);
    $receivedExpr = "COALESCE((SELECT MIN(a.created_at) FROM sales_do_audit a WHERE a.do_id=sales_do.id AND LOWER(COALESCE(a.status_to,'')) IN ('crm_to_wqs','sent_wqs')), "
        . ($hasCreatedAt ? "created_at, " : "") . "NOW())";
    $pdo->exec("UPDATE sales_do
               SET wqs_received_at={$receivedExpr}
               WHERE wqs_received_at IS NULL
                 AND LOWER(status) IN ('crm_to_wqs','sent_wqs')");
} catch (Throwable $e) {
    // fail-soft: halaman tetap dapat dipakai; data lama fallback di helper durasi.
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));

    $action = trim((string)($_POST['action'] ?? ''));
    $id     = (int)($_POST['id'] ?? 0);

    // START hanya boleh dari CRM -> WQS
    $allowedStart = ['crm_to_wqs','sent_wqs'];

    // Finalisasi batch percobaan lama tetap POST + CSRF + audit.
    if ($action === 'archive_legacy_trials') {
        try {
            if (!$_wqs_can_cancel) {
                throw new Exception("Arsip batch percobaan hanya boleh dilakukan oleh SYS/Admin atau dept WQS.");
            }

            $legacyStatuses = $WQS_LEGACY_ACTIVE_STATUSES;
            $ph = implode(',', array_fill(0, count($legacyStatuses), '?'));
            $sel = $pdo->prepare("SELECT id, do_code, status, do_date FROM sales_do WHERE DATE(do_date) BETWEEN ? AND ? AND LOWER(status) IN ($ph) ORDER BY do_date, id");
            $sel->execute(array_merge([$WQS_LEGACY_TRIAL_FROM, $WQS_LEGACY_TRIAL_TO], $legacyStatuses));
            $legacyRows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!$legacyRows) {
                $success = "Tidak ada lagi DO percobaan lama yang perlu diarsipkan.";
            } else {
                $actorName = audit_actor_name();
                $reason = "Data percobaan lama WQS 21-04-2026 s/d 30-06-2026; tidak dilanjutkan ke SCM/ACT/FIN.";
                $pdo->beginTransaction();
                try {
                    foreach ($legacyRows as $legacyRow) {
                        $legacyId = (int)$legacyRow['id'];
                        $legacyFrom = (string)$legacyRow['status'];
                        $sets = ["status='cancelled'", "wqs_status='Cancelled'", "wqs_note=?", "last_updated_by=?", "last_updated_at=NOW()"];
                        $params = [$reason, $actorName];
                        if ($hasWqsCancelReasonCol) { $sets[] = "wqs_cancel_reason=?"; $params[] = $reason; }
                        if ($hasWqsCancelledAtCol) { $sets[] = "wqs_cancelled_at=NOW()"; }
                        if ($hasWqsDurationCol) { $sets[] = "wqs_duration_sec=NULL"; }
                        if ($hasWqsCancelledByCol) { $sets[] = "wqs_cancelled_by=?"; $params[] = $actorName; }
                        if (table_has_column($pdo, 'sales_do', 'wqs_updated_by')) { $sets[] = "wqs_updated_by=?"; $params[] = $actorName; }
                        $params[] = $legacyId;
                        $params[] = $legacyFrom;
                        $upd = $pdo->prepare("UPDATE sales_do SET " . implode(', ', $sets) . " WHERE id=? AND status=?");
                        $upd->execute($params);
                        if ($upd->rowCount() > 0) {
                            if (function_exists('sales_do_audit_append')) {
                                sales_do_audit_append($pdo, $legacyId, $legacyFrom, 'cancelled', 'WQS', $reason);
                            } else {
                                error_log('[WQS_AUDIT_GAP] sales_do_audit_append missing for archive_legacy_trials do_id=' . $legacyId);
                            }
                            if (function_exists('master_audit')) {
                                $code = (string)($legacyRow['do_code'] ?? '');
                                master_audit($pdo, 'sales_do', 'sales_do', 'WQS_CANCEL_LEGACY_TRIAL', $legacyId, $code, "DO percobaan lama WQS diarsipkan: {$code}", ['from_status'=>$legacyFrom,'to_status'=>'cancelled','reason'=>$reason]);
                            } else {
                                error_log('[WQS_AUDIT_GAP] master_audit missing for archive_legacy_trials do_id=' . $legacyId);
                            }
                        }
                    }
                    $pdo->commit();
                    $success = count($legacyRows) . " DO percobaan lama sudah diarsipkan.";
                } catch (Throwable $txe) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $txe;
                }
            }
        } catch (Throwable $e) {
            $error = "Error: " . $e->getMessage();
        }
    } else try {
        $wqsDurationSelect = $hasWqsDurationCol ? 'wqs_duration_sec' : 'NULL AS wqs_duration_sec';
        $wqsCancelledAtSelect = $hasWqsCancelledAtCol ? 'wqs_cancelled_at' : 'NULL AS wqs_cancelled_at';
        $wqsCancelledBySelect = $hasWqsCancelledByCol ? 'wqs_cancelled_by' : 'NULL AS wqs_cancelled_by';
        $wqsCancelReasonSelect = $hasWqsCancelReasonCol ? 'wqs_cancel_reason' : 'NULL AS wqs_cancel_reason';
        $stmt = $pdo->prepare("
            SELECT id, do_code, status, office_code, wqs_stock_before, wqs_stock_after,
                   {$wqsReceivedSelect}, wqs_started_at, wqs_ready_at,
                   {$wqsCancelledAtSelect}, {$wqsCancelledBySelect}, {$wqsCancelReasonSelect},
                   {$wqsDurationSelect}
            FROM sales_do
            WHERE id=?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch();

        if (!$r) {
            throw new Exception("DO tidak ditemukan.");
        }

        // Guard server-side office scope untuk Staff WQS/BRANCH.
        // Ini juga melindungi POST langsung: DO BGR (termasuk UNIT_ACC) hanya dapat
        // diproses staff WQS yang office akunnya BGR. Manager/Admin tetap lintas office.
        if ($_wqs_scope_required && $_wqs_office === '') {
            throw new Exception("Akses ditolak: akun WQS/BRANCH belum memiliki office_code.");
        }
        if ($_wqs_office_scope !== null && $_wqs_office_scope !== ''
            && !rmi_office_same($pdo, (string)($r['office_code'] ?? ''), (string)$_wqs_office_scope)) {
            throw new Exception("Akses ditolak: Anda hanya boleh memproses DO milik office sendiri.");
        }

        $doHasUnitAcc = wqs_do_has_unit_acc($pdo, (int)$id);
        if ($_wqs_is_branch_bgr_unit_acc && !$doHasUnitAcc && in_array($action, ['save','start','ready','handover_save'], true)) {
            throw new Exception("Akses ditolak: StaffBRANCH_BGR pada WQS hanya boleh menyiapkan DO UNIT ACC office BGR. BMHP tetap diproses WQS.");
        }

        $curStatus = (string)($r['status'] ?? '');

        // Lock status lanjutan/terminal.
        // DO yang sudah masuk SCM/ACT/FIN atau sudah dibatalkan tidak boleh dimutasi dari WQS.
        $locked_status = ['ready_scm', 'on_delivery', 'delivered', 'wait_payment', 'paid', 'cancelled', 'canceled', 'void', 'archived'];
        if (in_array($curStatus, $locked_status, true) && in_array($action, ['save','start','ready','cancel','handover_save'], true)) {
            throw new Exception("DO sudah lanjut/selesai/dibatalkan. Data WQS terkunci (read-only).");
        }

        $note = trim((string)($_POST['wqs_note'] ?? ''));
        $wqs_status = (string)($_POST['wqs_status'] ?? 'Pending');
        if (!in_array($wqs_status, ['Pending','Open','Done'], true)) {
            $wqs_status = 'Pending';
        }

        $actorName = audit_actor_name();

        // Multi-upload bukti kartu stok di level DO.
        // Tidak dibuat per item agar tidak memberatkan WQS jika item DO banyak.
        $before_uploads = upload_files_multi('wqs_stock_before', 'uploads/sales_wqs');
        $after_uploads  = upload_files_multi('wqs_stock_after', 'uploads/sales_wqs');

        if ($before_uploads) {
            save_wqs_stock_card_photos($pdo, $id, 'BEFORE', $before_uploads);
        }
        if ($after_uploads) {
            save_wqs_stock_card_photos($pdo, $id, 'AFTER', $after_uploads);
        }

        // Backward compatibility: kolom lama tetap diisi foto pertama,
        // agar halaman lama/detail yang masih membaca wqs_stock_before/after tetap jalan.
        $before_up = $before_uploads[0]['path'] ?? null;
        $after_up  = $after_uploads[0]['path'] ?? null;

        $before = $before_up ?: (string)($r['wqs_stock_before'] ?? '') ?: wqs_stock_card_first($pdo, $id, 'BEFORE');
        $after  = $after_up  ?: (string)($r['wqs_stock_after'] ?? '') ?: wqs_stock_card_first($pdo, $id, 'AFTER');

        if ($action === 'save') {
            if (!$_wqs_can_process) {
                throw new Exception("Aksi Simpan hanya boleh dilakukan oleh dept WQS/Admin. User BRANCH hanya dapat melihat data.");
            }

            if (!in_array($curStatus, ['crm_to_wqs','sent_wqs','wqs_processing','revision_requested'], true)) {
                throw new Exception("Tidak bisa Simpan. Status sekarang: " . $curStatus);
            }

            $sets = "wqs_note=?, wqs_status=?, last_updated_by=?, last_updated_at=NOW()";
            $params = [$note, $wqs_status, $actorName];
            if (table_has_column($pdo, 'sales_do', 'wqs_updated_by')) {
                $sets .= ", wqs_updated_by=?";
                $params[] = $actorName;
            }

            if ($before_up) {
                $sets .= ", wqs_stock_before=?";
                $params[] = $before_up;
            }
            if ($after_up) {
                $sets .= ", wqs_stock_after=?";
                $params[] = $after_up;
            }

            $params[] = $id;
            $pdo->prepare("UPDATE sales_do SET {$sets} WHERE id=?")->execute($params);

            $afterRow = null;
            try {
                $stA = $pdo->prepare("
                    SELECT id, status, wqs_status, wqs_note, wqs_stock_before, wqs_stock_after,
                           wqs_started_at, wqs_ready_at, last_updated_by, last_updated_at
                    FROM sales_do WHERE id=? LIMIT 1
                ");
                $stA->execute([$id]);
                $afterRow = $stA->fetch();
            } catch (Throwable $e) {
                $afterRow = null;
            }

            rmi_audit_safe('UPDATE', 'SALES.DO', $id, $r, $afterRow, [
                'event' => 'wqs_save',
            ]);

            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit($pdo, 'sales_do', 'sales_do', 'WQS_SAVE', $id, $code, "DO WQS save: {$code}", []);
            } else {
                error_log('[WQS_AUDIT_GAP] master_audit missing for WQS_SAVE do_id=' . $id);
            }

            if (function_exists('sales_do_audit_append')) {
                sales_do_audit_append($pdo, $id, $curStatus, $curStatus, 'WQS', $note);
            } else {
                error_log('[WQS_AUDIT_GAP] sales_do_audit_append missing for WQS_SAVE do_id=' . $id);
            }

            $success = "Tersimpan (WQS).";
        }

        if ($action === 'start') {
            if (!$_wqs_can_process) {
                throw new Exception("Aksi Mulai Proses hanya boleh dilakukan oleh dept WQS/Admin. User BRANCH hanya dapat melihat data.");
            }

            if (!in_array($curStatus, ['crm_to_wqs','sent_wqs','revision_requested'], true)) {
                throw new Exception("Tidak bisa Mulai Proses. Status sekarang: " . $curStatus);
            }

            if ($curStatus === 'wqs_processing') {
                throw new Exception("DO ini sudah masuk proses WQS.");
            }

            if (function_exists('auth_sales_do_require_transition') && $curStatus !== 'revision_requested' && !$_wqs_is_branch_bgr_unit_acc) {
                auth_sales_do_require_transition($curStatus, 'wqs_processing', 'WQS_START');
            }

            $startSets = [
                "status='wqs_processing'",
                "wqs_started_at=COALESCE(wqs_started_at, NOW())",
                "wqs_status='Open'",
                "revision_reason=NULL",
                "revision_requested_by=NULL",
                "revision_requested_at=NULL",
                "last_updated_by=?",
                "last_updated_at=NOW()",
            ];
            if ($hasWqsReceivedAtCol) {
                $startSets[] = "wqs_received_at=COALESCE(wqs_received_at, " . ($hasCreatedAtCol ? "created_at, " : "") . ($hasLastUpdatedAtCol ? "last_updated_at, " : "") . "NOW())";
            }
            $startParams = [$actorName];
            if (table_has_column($pdo, 'sales_do', 'wqs_started_by')) {
                $startSets[] = "wqs_started_by=?";
                $startParams[] = $actorName;
            }
            if (table_has_column($pdo, 'sales_do', 'wqs_updated_by')) {
                $startSets[] = "wqs_updated_by=?";
                $startParams[] = $actorName;
            }
            // Tombol Mulai Proses hanya menandai pekerjaan operasional dimulai.
            // Titik awal SLA tetap wqs_received_at (handoff CRM -> WQS).
            if ($hasWqsDurationCol) $startSets[] = "wqs_duration_sec=NULL";
            $startParams[] = $id;
            $pdo->prepare("UPDATE sales_do SET " . implode(', ', $startSets) . " WHERE id=?")
                ->execute($startParams);

            $afterRow = null;
            try {
                $stA = $pdo->prepare("
                    SELECT id, status, wqs_status, wqs_note, wqs_stock_before, wqs_stock_after,
                           wqs_started_at, wqs_ready_at, last_updated_by, last_updated_at
                    FROM sales_do WHERE id=? LIMIT 1
                ");
                $stA->execute([$id]);
                $afterRow = $stA->fetch();
            } catch (Throwable $e) {
                $afterRow = null;
            }

            rmi_audit_safe('UPDATE', 'SALES.DO', $id, array_merge($r, ['status' => $curStatus]), $afterRow, [
                'event' => 'wqs_start',
                'to_status' => 'wqs_processing',
            ]);

            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit($pdo, 'sales_do', 'sales_do', 'WQS_START', $id, $code, "DO WQS start: {$code} -> wqs_processing", ['to_status' => 'wqs_processing']);
            } else {
                error_log('[WQS_AUDIT_GAP] master_audit missing for WQS_START do_id=' . $id);
            }

            if (function_exists('sales_do_audit_append')) {
                sales_do_audit_append($pdo, $id, $curStatus, 'wqs_processing', 'WQS', $note);
            } else {
                error_log('[WQS_AUDIT_GAP] sales_do_audit_append missing for WQS_START do_id=' . $id);
            }
            $success = "WQS: DO masuk proses.";
        }


        if ($action === 'handover_save') {
            if (!$_wqs_can_process) throw new Exception("Upload bukti serah terima hanya boleh dilakukan oleh WQS/Admin.");
            if (!in_array($curStatus, ['crm_to_wqs','sent_wqs','revision_requested','wqs_processing'], true)) throw new Exception("Bukti serah terima tidak dapat diubah karena DO sudah lanjut/terkunci.");
            $handoverPhotos = upload_wqs_handover_multi('wqs_handover_photo', 'uploads/sales_wqs/handover', 'PHOTO');
            $handoverVideos = upload_wqs_handover_multi('wqs_handover_video', 'uploads/sales_wqs/handover', 'VIDEO');
            if (!$handoverPhotos && !$handoverVideos) throw new Exception("Pilih minimal satu foto atau video serah terima yang valid.");
            save_wqs_handover_media($pdo, $id, 'PHOTO', $handoverPhotos);
            save_wqs_handover_media($pdo, $id, 'VIDEO', $handoverVideos);
            if (function_exists('master_audit')) {
                $code=(string)($r['do_code']??'');
                master_audit($pdo,'sales_do','sales_do','WQS_HANDOVER_UPLOAD',$id,$code,"Upload bukti serah terima WQS -> SCM: {$code}",['photos'=>count($handoverPhotos),'videos'=>count($handoverVideos)]);
            } else {
                error_log('[WQS_AUDIT_GAP] master_audit missing for WQS_HANDOVER_UPLOAD do_id=' . $id);
            }
            if (function_exists('sales_do_audit_append')) {
                sales_do_audit_append($pdo, $id, $curStatus, $curStatus, 'WQS', 'handover media: '.count($handoverPhotos).' photo / '.count($handoverVideos).' video');
            } else {
                error_log('[WQS_AUDIT_GAP] sales_do_audit_append missing for WQS_HANDOVER_UPLOAD do_id=' . $id);
            }
            $success = "Bukti serah terima WQS → SCM tersimpan.";
        }

        if ($action === 'cancel') {
            if (!$_wqs_can_cancel) {
                throw new Exception("Aksi Batalkan/Arsip hanya boleh dilakukan oleh SYS/Admin atau dept WQS.");
            }

            if (!in_array($curStatus, ['crm_to_wqs','sent_wqs','revision_requested','wqs_processing'], true)) {
                throw new Exception("DO tidak dapat dibatalkan dari WQS. Status sekarang: " . $curStatus);
            }

            $cancelReason = trim((string)($_POST['wqs_note'] ?? ''));
            if ($cancelReason === '') {
                throw new Exception("Catatan/alasan pembatalan wajib diisi sebelum DO dibatalkan.");
            }

            // CANCELLED = terminal state antrean WQS.
            // Record tetap ada untuk audit, tidak diteruskan ke SCM/ACT/FIN,
            // dan tidak dihitung sebagai penyelesaian SLA WQS.
            $cancelSets = [
                "status='cancelled'",
                "wqs_status='Cancelled'",
                "wqs_note=?",
                "last_updated_by=?",
                "last_updated_at=NOW()",
            ];
            $cancelParams = [$cancelReason, $actorName];

            if ($hasWqsCancelReasonCol) {
                $cancelSets[] = "wqs_cancel_reason=?";
                $cancelParams[] = $cancelReason;
            }
            if ($hasWqsCancelledAtCol) {
                $cancelSets[] = "wqs_cancelled_at=NOW()";
            }
            if ($hasWqsDurationCol) {
                $cancelSets[] = "wqs_duration_sec=NULL";
            }
            if ($hasWqsCancelledByCol) {
                $cancelSets[] = "wqs_cancelled_by=?";
                $cancelParams[] = $actorName;
            }
            if (table_has_column($pdo, 'sales_do', 'wqs_updated_by')) {
                $cancelSets[] = "wqs_updated_by=?";
                $cancelParams[] = $actorName;
            }

            $cancelParams[] = $id;
            $pdo->prepare("UPDATE sales_do SET " . implode(', ', $cancelSets) . " WHERE id=?")
                ->execute($cancelParams);

            $afterRow = null;
            try {
                $stA = $pdo->prepare("SELECT * FROM sales_do WHERE id=? LIMIT 1");
                $stA->execute([$id]);
                $afterRow = $stA->fetch();
            } catch (Throwable $e) {
                $afterRow = null;
            }

            rmi_audit_safe('UPDATE', 'SALES.DO', $id, array_merge($r, ['status' => $curStatus]), $afterRow, [
                'event' => 'wqs_cancel',
                'to_status' => 'cancelled',
                'reason' => $cancelReason,
            ]);

            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit(
                    $pdo,
                    'sales_do',
                    'sales_do',
                    'WQS_CANCEL',
                    $id,
                    $code,
                    "DO WQS dibatalkan/diarsipkan: {$code}",
                    ['from_status' => $curStatus, 'to_status' => 'cancelled', 'reason' => $cancelReason]
                );
            } else {
                error_log('[WQS_AUDIT_GAP] master_audit missing for WQS_CANCEL do_id=' . $id);
            }

            if (function_exists('sales_do_audit_append')) {
                sales_do_audit_append($pdo, $id, $curStatus, 'cancelled', 'WQS', $cancelReason);
            } else {
                error_log('[WQS_AUDIT_GAP] sales_do_audit_append missing for WQS_CANCEL do_id=' . $id);
            }
            $success = "DO dibatalkan/diarsipkan. Tidak diteruskan ke SCM/ACT/FIN dan tidak lagi menjadi antrean aktif WQS.";
        }

        if ($action === 'ready') {
            if (!$_wqs_can_process) {
                throw new Exception("Aksi Set READY SCM hanya boleh dilakukan oleh dept WQS/Admin.");
            }

            if ($curStatus !== 'wqs_processing') {
                throw new Exception("Tidak bisa set READY SCM sebelum Mulai Proses (status harus wqs_processing).");
            }

            $photoCounts = wqs_stock_card_counts($pdo, $id);
            if (($photoCounts['before'] ?? 0) <= 0 || ($photoCounts['after'] ?? 0) <= 0) {
                throw new Exception("Wajib upload minimal 1 foto kartu stok SEBELUM dan minimal 1 foto kartu stok SESUDAH sebelum set READY SCM.");
            }

            if (function_exists('auth_sales_do_require_transition') && !$_wqs_is_branch_bgr_unit_acc) {
                auth_sales_do_require_transition($curStatus, 'ready_scm', 'WQS_READY');
            }

            $readySets = [
                "status='ready_scm'",
                "wqs_ready_at=NOW()",
                "wqs_note=?",
                "wqs_status='Done'",
                "wqs_stock_before=?",
                "wqs_stock_after=?",
                "last_updated_by=?",
                "last_updated_at=NOW()",
            ];
            if ($hasWqsDurationCol) {
                // Freeze durasi tepat saat WQS menyerahkan DO ke SCM.
                $readySets[] = "wqs_duration_sec=GREATEST(0, TIMESTAMPDIFF(SECOND, {$wqsSlaStartExpr}, NOW()))";
            }
            $readyParams = [$note, $before, $after, $actorName];
            if (table_has_column($pdo, 'sales_do', 'wqs_ready_by')) {
                $readySets[] = "wqs_ready_by=?";
                $readyParams[] = $actorName;
            }
            if (table_has_column($pdo, 'sales_do', 'wqs_updated_by')) {
                $readySets[] = "wqs_updated_by=?";
                $readyParams[] = $actorName;
            }
            $readyParams[] = $id;
            $pdo->prepare("UPDATE sales_do SET " . implode(', ', $readySets) . " WHERE id=?")
                ->execute($readyParams);

            $afterRow = null;
            try {
                $stA = $pdo->prepare("
                    SELECT id, status, wqs_status, wqs_note, wqs_stock_before, wqs_stock_after,
                           wqs_started_at, wqs_ready_at, last_updated_by, last_updated_at
                    FROM sales_do WHERE id=? LIMIT 1
                ");
                $stA->execute([$id]);
                $afterRow = $stA->fetch();
            } catch (Throwable $e) {
                $afterRow = null;
            }

            rmi_audit_safe('APPROVE', 'SALES.DO', $id, array_merge($r, ['status' => $curStatus]), $afterRow, [
                'event' => 'wqs_ready',
                'to_status' => 'ready_scm',
            ]);

            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit($pdo, 'sales_do', 'sales_do', 'WQS_READY', $id, $code, "DO READY SCM: {$code}", ['to_status' => 'ready_scm']);
            } else {
                error_log('[WQS_AUDIT_GAP] master_audit missing for WQS_READY do_id=' . $id);
            }

            if (function_exists('sales_do_audit_append')) {
                sales_do_audit_append($pdo, $id, $curStatus, 'ready_scm', 'WQS', $note);
            } else {
                error_log('[WQS_AUDIT_GAP] sales_do_audit_append missing for WQS_READY do_id=' . $id);
            }
            $success = "Status: READY SCM ✅";
        }

    } catch (Throwable $e) {
        $error = "Error: " . $e->getMessage();
    }
}

$_wqs_duration_select = $hasWqsDurationCol ? 'd.wqs_duration_sec' : 'NULL AS wqs_duration_sec';
$_wqs_cancelled_at_select = $hasWqsCancelledAtCol ? 'd.wqs_cancelled_at' : 'NULL AS wqs_cancelled_at';
$_wqs_cancelled_by_select = $hasWqsCancelledByCol ? 'd.wqs_cancelled_by' : 'NULL AS wqs_cancelled_by';
$_wqs_cancel_reason_select = $hasWqsCancelReasonCol ? 'd.wqs_cancel_reason' : 'NULL AS wqs_cancel_reason';
$showCancelled = isset($_GET['show_cancelled']) && (string)$_GET['show_cancelled'] === '1';

// FILTER TANGGAL DO — hanya memfilter daftar, tidak mengubah status/alur WQS.
$filterDateFrom = trim((string)($_GET['date_from'] ?? ''));
$filterDateTo   = trim((string)($_GET['date_to'] ?? ''));
$validYmd = static function (string $v): bool {
    if ($v === '') return true;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
};
if (!$validYmd($filterDateFrom)) $filterDateFrom = '';
if (!$validYmd($filterDateTo)) $filterDateTo = '';
if ($filterDateFrom !== '' && $filterDateTo !== '' && $filterDateFrom > $filterDateTo) {
    [$filterDateFrom, $filterDateTo] = [$filterDateTo, $filterDateFrom];
}

// Saat filter tanggal dipakai, halaman berfungsi juga sebagai REKAP WQS:
// DO lama yang sudah lanjut ke SCM/ACT/FIN tetap boleh dilihat, tetapi tetap terkunci
// dan tidak dapat diproses ulang. Tanpa filter, perilaku antrean aktif tetap seperti semula.
$wqsDateRecapMode = ($filterDateFrom !== '' || $filterDateTo !== '');

$_wqs_status_list = "'crm_to_wqs','sent_wqs','revision_requested','wqs_processing','ready_scm'";
if ($wqsDateRecapMode) {
    $_wqs_status_list .= ",'on_delivery','delivered','wait_payment','paid'";
}
if ($showCancelled) {
    $_wqs_status_list .= ",'cancelled','canceled','void','archived'";
}

$_wqs_ua = wqs_unit_acc_sql_parts($pdo, 'iua_list', 'pua_list');
$_wqs_has_unit_acc_expr = "CASE WHEN (
    EXISTS (SELECT 1 FROM sales_do_items iua_list {$_wqs_ua['join']} WHERE iua_list.do_id=d.id AND {$_wqs_ua['where']})
    OR UPPER(TRIM(COALESCE(d.do_code,''))) LIKE 'UNITACC-%'
    OR UPPER(TRIM(COALESCE(d.tracking_code,''))) LIKE 'UNITACC-%'
) THEN 1 ELSE 0 END";

$_wqs_sql = "
    SELECT d.id, d.do_code, d.tracking_code, d.do_date, d.customers_code, d.office_code, d.grand_total,
           d.status, d.wqs_status, d.wqs_note, d.wqs_stock_before, d.wqs_stock_after,
           {$wqsReceivedSelectD}, {$wqsCrmDoneSelectD}, d.wqs_started_at, d.wqs_ready_at,
           {$_wqs_cancelled_at_select}, {$_wqs_cancelled_by_select}, {$_wqs_cancel_reason_select},
           {$_wqs_duration_select},
           {$_wqs_has_unit_acc_expr} AS has_unit_acc,
           c.customers_name, o.office_name
    FROM sales_do d
    LEFT JOIN master_customers c ON c.customers_code = d.customers_code
    LEFT JOIN master_office o ON o.office_code = d.office_code
    WHERE d.status IN ({$_wqs_status_list})
";
$_wqs_params = [];

// DO percobaan 21-04-2026 s/d 30-06-2026 tidak lagi ditampilkan sebagai task aktif.
if (!$showCancelled && !$wqsDateRecapMode) {
    $legacyStatusPh = implode(',', array_fill(0, count($WQS_LEGACY_ACTIVE_STATUSES), '?'));
    $_wqs_sql .= " AND NOT (DATE(d.do_date) BETWEEN ? AND ? AND LOWER(d.status) IN ($legacyStatusPh))";
    $_wqs_params = array_merge($_wqs_params, [$WQS_LEGACY_TRIAL_FROM, $WQS_LEGACY_TRIAL_TO], $WQS_LEGACY_ACTIVE_STATUSES);
}

if ($_wqs_scope_required && $_wqs_office === '') {
    // Fail closed: staff WQS/BRANCH tanpa office tidak boleh melihat antrean lintas kantor.
    $_wqs_sql .= " AND 1=0";
} elseif ($_wqs_office_scope !== null && $_wqs_office_scope !== '') {
    $_wqs_sql .= " AND " . rmi_office_in_sql($pdo, 'd.office_code', $_wqs_office_scope, $_wqs_params);
}

// StaffBRANCH_BGR: antrean WQS khusus UNIT ACC BGR. BMHP tidak ditampilkan.
if ($_wqs_is_branch_bgr_unit_acc) {
    $_wqs_sql .= " AND ({$_wqs_has_unit_acc_expr}) = 1";
}

// Filter berdasarkan tanggal DO (sales_do.do_date), bukan waktu mulai/selesai WQS.
if ($filterDateFrom !== '') {
    $_wqs_sql .= " AND DATE(d.do_date) >= ?";
    $_wqs_params[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $_wqs_sql .= " AND DATE(d.do_date) <= ?";
    $_wqs_params[] = $filterDateTo;
}

$_wqs_sql .= "
    ORDER BY
      FIELD(d.status,'revision_requested','crm_to_wqs','sent_wqs','wqs_processing','ready_scm','on_delivery','delivered','wait_payment','paid','cancelled','canceled','void','archived'),
      d.do_date DESC, d.id DESC
";

$_st = $pdo->prepare($_wqs_sql);
$_st->execute($_wqs_params);
$rows = $_st->fetchAll();
$wqsStockPhotos = fetch_wqs_stock_card_photos($pdo, array_column($rows, 'id'));
$wqsHandoverMedia = fetch_wqs_handover_media($pdo, array_column($rows, 'id'));

$legacyTrialCount = 0;
try {
    $legacyStatusPh = implode(',', array_fill(0, count($WQS_LEGACY_ACTIVE_STATUSES), '?'));
    $stLegacyCount = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE DATE(do_date) BETWEEN ? AND ? AND LOWER(status) IN ($legacyStatusPh)");
    $stLegacyCount->execute(array_merge([$WQS_LEGACY_TRIAL_FROM, $WQS_LEGACY_TRIAL_TO], $WQS_LEGACY_ACTIVE_STATUSES));
    $legacyTrialCount = (int)$stLegacyCount->fetchColumn();
} catch (Throwable $e) {
    $legacyTrialCount = 0;
}

$lastOpnameByOffice = [];
try {
    $st = $pdo->query("
        SELECT office_code, opname_code, opname_date, status
        FROM wqs_stock_opname
        WHERE status='APPLIED' AND office_code IS NOT NULL AND office_code != ''
        ORDER BY opname_date DESC
    ");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $oc = strtoupper(trim((string)($row['office_code'] ?? '')));
        if ($oc !== '' && !isset($lastOpnameByOffice[$oc])) {
            $lastOpnameByOffice[$oc] = $row;
        }
    }
} catch (Throwable $e) {
    // ignore
}

// Audit log (last 50) - sales_do
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
    return '<span class="' . $cls . '">' . h($label) . '</span>';
}

function badgeStatus($s): string {
    $s = strtolower((string)$s);
    $map = [
        'crm_to_wqs'     => ['Ke WQS','tag blue'],
        'sent_wqs'       => ['Ke WQS','tag blue'],
        'revision_requested' => ['Menunggu CRM Revisi','tag yellow'],
        'wqs_processing' => ['WQS Proses','tag yellow'],
        'ready_scm'      => ['READY SCM','tag green'],
        'cancelled'      => ['DIBATALKAN','tag red'],
        'canceled'       => ['DIBATALKAN','tag red'],
        'void'           => ['VOID','tag red'],
        'archived'       => ['DIARSIPKAN','tag red'],
    ];
    $v = $map[$s] ?? [strtoupper($s), 'tag'];
    return '<span class="' . $v[1] . '">' . h($v[0]) . '</span>';
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();
$salesU = rtrim($baseProject, '/') . '/sales';

rmi_header('WQS - Task DO dari CRM', [
  'active' => 'sales',
  'breadcrumbs' => [
    ['label' => 'Sales (CRM)', 'url' => $baseProject . '/sales/sales_dashboard.php'],
    'WQS - Task DO dari CRM',
  ],
  'actions' => [
    ['label' => '⚠ Retur / Karantina', 'url' => $baseProject . '/sales/sales_do_return.php?queue=wqs', 'class' => 'btn btn-sm btn-outline-warning'],
    ['label' => '📚 Panduan Task', 'url' => $baseProject . '/sales/panduan_do_tasks.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => '🗼 Control Tower', 'url' => $baseProject . '/sales/sales_control_tower.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => $showCancelled ? '📋 Antrean Aktif' : '🗃 Riwayat Batal', 'url' => $baseProject . '/stock/wqs_do_tasks.php' . ($showCancelled ? '' : '?show_cancelled=1'), 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'extra_head' => '<style>
    :root{
      --bg:#0b1220; --card:#0f172a; --card2:#111827; --line:rgba(255,255,255,.08);
      --text:#e5e7eb; --muted:#94a3b8; --blue:#60a5fa; --green:#22c55e; --yellow:#f59e0b;
    }
    *{box-sizing:border-box}
    body{margin:0;background:radial-gradient(1200px 600px at 50% -10%, rgba(96,165,250,.15), transparent), var(--bg); color:var(--text); font-family: ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;}
    a{color:#93c5fd;text-decoration:none}
    a:hover{text-decoration:underline}
    .wrap{width:calc(100% - 24px);max-width:none;margin:18px auto;padding:0 6px}
    .top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}
    .title h1{font-size:20px;margin:0 0 4px}
    .title .sub{font-size:12px;color:var(--muted)}
    .btn{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.04); color:var(--text);
         padding:8px 10px;border-radius:10px;font-size:12px}
    .btn:hover{background:rgba(255,255,255,.06)}
    .flow{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
    .pill{padding:7px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.12); color:var(--muted); font-size:12px; background:rgba(255,255,255,.03)}
    .pill.active{border-color:rgba(34,197,94,.55); color:#d1fae5; background:rgba(34,197,94,.12)}
    .card{background:linear-gradient(180deg, rgba(255,255,255,.04), transparent), var(--card2); border:1px solid var(--line); border-radius:14px; padding:14px; box-shadow: 0 10px 30px rgba(0,0,0,.25);}
    .card + .card{margin-top:12px}
    .alert{padding:10px 12px;border-radius:10px;margin:10px 0;border:1px solid var(--line); font-size:12px}
    .alert.ok{background:rgba(34,197,94,.12); border-color:rgba(34,197,94,.25)}
    .alert.bad{background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.25)}
    .table-wrap{overflow-x:auto;overflow-y:visible;border-radius:14px;width:100%;max-width:100%}
    table{width:100%;border-collapse:separate;border-spacing:0;min-width:0;table-layout:fixed}
    thead th{position:sticky;top:0;background:rgba(15,23,42,.95); color:#cbd5e1; font-weight:600; font-size:11px; text-align:left; padding:8px 7px; border-bottom:1px solid var(--line);overflow-wrap:anywhere}
    tbody td{padding:8px 7px; border-bottom:1px solid var(--line); vertical-align:top; font-size:11px;overflow-wrap:anywhere}
    tbody tr:hover{background:rgba(255,255,255,.03)}
    .tag{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-size:11px;border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.04)}
    .tag.blue{border-color:rgba(96,165,250,.35); background:rgba(96,165,250,.12)}
    .tag.green{border-color:rgba(34,197,94,.35); background:rgba(34,197,94,.12)}
    .tag.yellow{border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.12); color:#fff7ed}
    .tag.red{border-color:rgba(239,68,68,.42); background:rgba(239,68,68,.14); color:#fee2e2}
    .sla-time{font-weight:700;white-space:nowrap}
    .sla-meta{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:5px}
    .field{width:100%;min-width:0;max-width:100%; background:#0b1220; border:1px solid rgba(255,255,255,.14); color:var(--text); border-radius:10px; padding:8px 10px; outline:none; font-size:12px}
    .field:focus{border-color:rgba(96,165,250,.6)}
    .select{appearance:none}
    .grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px;align-items:start;min-width:0}
    .stack{display:flex;flex-direction:column;gap:8px}
    .actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .btn2{border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.06); color:var(--text); padding:8px 10px;border-radius:10px;font-size:12px; cursor:pointer}
    .btn2:hover{background:rgba(255,255,255,.08)}
    .btn2.primary{background:rgba(59,130,246,.20); border-color:rgba(59,130,246,.40)}
    .btn2.warn{background:rgba(245,158,11,.18); border-color:rgba(245,158,11,.35)}
    .btn2.ok{background:rgba(34,197,94,.18); border-color:rgba(34,197,94,.35)}
    .btn2.danger{background:rgba(239,68,68,.16); border-color:rgba(239,68,68,.38); color:#fee2e2}
    .muted{color:var(--muted)}
    .mini{font-size:11px}
    .file{display:block;width:100%;min-width:0;max-width:100%;font-size:10px;padding:6px}
    .photo-links{display:flex;flex-direction:column;gap:4px}
    .date-filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:12px}
    .date-filter .filter-group{min-width:160px}
    .date-filter label{display:block;font-size:11px;color:var(--muted);margin-bottom:5px}
    .card.table-wrap{padding:10px}
    td form.stack{min-width:0;width:100%}
    td .actions{min-width:0}
    @media(max-width:1100px){table{min-width:980px;table-layout:fixed}.wrap{width:100%;padding:0 8px}.card.table-wrap{overflow-x:auto}}
  </style>',
]);
?>

<div class="wrap">
  <div class="top">
    <div class="title">
      <h1>WQS – TASK DO DARI CRM</h1>
      <div class="sub">WQS memproses DO yang dikirim CRM: cek stok, siapkan barang, upload bukti kartu stok, update status.</div>
      <div class="flow">
        <?php echo pill('CRM', false); ?>
        <?php echo pill('WQS (Now)', true); ?>
        <?php echo pill('SCM', false); ?>
        <?php echo pill('ACT', false); ?>
        <?php echo pill('FIN', false); ?>
      </div>
    </div>
    <div class="stack">
      <a class="btn" href="sales_dashboard.php">« Kembali ke Modul Penjualan</a>
      <a class="btn" href="sales_do.php">Sales DO (CRM)</a>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="alert ok"><?php echo h($success); ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert bad"><?php echo h($error); ?></div>
  <?php endif; ?>

  <?php if (!$showCancelled && $legacyTrialCount > 0): ?>
    <div class="alert bad" style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
      <div>
        <b><?php echo (int)$legacyTrialCount; ?> DO percobaan lama</b> periode <b>21-04-2026 s/d 30-06-2026</b> masih aktif di database.
        DO tersebut sudah <b>tidak ditampilkan di antrean aktif WQS</b>. Klik arsipkan untuk menghentikan status/timer secara permanen dan mengeluarkannya dari alur SCM/ACT/FIN.
      </div>
      <?php if ($_wqs_can_cancel): ?>
        <form method="post" onsubmit="return confirm('Arsipkan semua DO percobaan 21-04-2026 s/d 30-06-2026 yang masih di tahap WQS? DO yang sudah READY SCM/lanjut tidak disentuh.');">
          <input type="hidden" name="csrf_token" value="<?php echo h((string)csrf_token()); ?>">
          <input type="hidden" name="id" value="0">
          <button class="btn2 warn" type="submit" name="action" value="archive_legacy_trials">Arsipkan <?php echo (int)$legacyTrialCount; ?> DO Percobaan</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="muted mini">
      List DO untuk WQS. Update status per baris:
      <b>Pending → Open → Done</b>.
      Untuk lanjut ke SCM, wajib upload
      <b>minimal 1 foto kartu stok sebelum & minimal 1 foto sesudah</b> lalu klik
      <b>Set READY SCM</b>.
      <br><b>SLA WQS</b> dihitung otomatis sejak <b>CRM selesai membuat/mengirim DO → READY SCM</b>. Tombol <b>Mulai Proses</b> hanya menandai aktivitas operasional WQS dan tidak mereset SLA; setelah READY SCM durasi otomatis berhenti/freeze.
      DO percobaan/batal gunakan <b>Batalkan / Arsipkan</b>: record tetap tersimpan untuk audit, tetapi keluar dari antrean aktif,
      tidak diteruskan ke SCM/ACT/FIN, timer berhenti, dan status pembatalan tidak diperlakukan sebagai completion SLA WQS.
      <?php if ($wqsSlaTargetMinutes): ?> Target KPI: <b><?php echo (int)$wqsSlaTargetMinutes; ?> menit</b>.
      <?php else: ?> Target KPI belum terbaca dari <code>KPI_DO_SLA</code>; durasi tetap dicatat.
      <?php endif; ?>
      <?php if (!$_wqs_can_process): ?>
  <br><br><b>Mode akses Anda saat ini: VIEW ONLY.</b>
<?php elseif ($_wqs_is_wqs_staff): ?>
  <br><br><b>Mode akses Anda saat ini: WQS STAFF — <?= h($_wqs_office ?: 'OFFICE BELUM DISET') ?>.</b> Anda hanya dapat melihat dan memproses DO office sendiri. UNIT ACC Bogor tetap masuk ke WQS BGR karena DO menggunakan office BGR.
<?php elseif ($_wqs_is_branch): ?>
  <br><br><b>Mode akses Anda saat ini: <?= $_wqs_is_branch_bgr_unit_acc ? 'BRANCH BGR — UNIT ACC PREPARATION' : 'BRANCH PROCESS' ?>.</b> <?= $_wqs_is_branch_bgr_unit_acc ? 'Akun StaffBRANCH_BGR hanya menyiapkan barang UNIT ACC office BGR sampai READY SCM; BMHP tetap ditangani WQS.' : 'Anda hanya dapat memproses DO office sendiri.' ?>
<?php elseif ($_wqs_is_wqs_manager || $_wqs_is_admin): ?>
  <br><br><b>Mode akses Anda saat ini: CROSS-OFFICE MONITORING/PROCESS.</b>
<?php endif; ?>
    </div>

    <?php if (!empty($lastOpnameByOffice)): ?>
      <div class="muted mini" style="margin-top:8px; padding-top:8px; border-top:1px solid var(--line)">
        <b>Opname terakhir (Stock Snapshot):</b>
        <?php
        $parts = [];
        foreach ($lastOpnameByOffice as $oc => $op) {
            $parts[] = h($oc) . ' ' . h($op['opname_date'] ?? '') . ' (' . h($op['opname_code'] ?? '') . ')';
        }
        echo implode(' · ', $parts);
        ?>
        <a href="<?php echo h($baseProject ?? ''); ?>/stock/wqs_stock_opname_report.php" target="_blank" class="mini">→ Report Opname</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <form method="get" class="date-filter">
      <?php if ($showCancelled): ?>
        <input type="hidden" name="show_cancelled" value="1">
      <?php endif; ?>
      <div class="filter-group">
        <label for="date_from">Tanggal DO dari</label>
        <input class="field" type="date" id="date_from" name="date_from" value="<?php echo h($filterDateFrom); ?>">
      </div>
      <div class="filter-group">
        <label for="date_to">Tanggal DO sampai</label>
        <input class="field" type="date" id="date_to" name="date_to" value="<?php echo h($filterDateTo); ?>">
      </div>
      <button class="btn2 primary" type="submit">Tampilkan</button>
      <a class="btn2" href="<?php echo h($baseProject); ?>/stock/wqs_do_tasks.php<?php echo $showCancelled ? '?show_cancelled=1' : ''; ?>">Reset</a>
      <span class="mini muted">Filter memakai tanggal DO. <?php if ($wqsDateRecapMode): ?><b>Mode Rekap:</b> DO lama yang sudah lanjut SCM/ACT/FIN ikut ditampilkan dalam kondisi terkunci. <?php endif; ?>Ditemukan: <b><?php echo count($rows); ?></b> DO.</span>
    </form>
  </div>

  <div class="card table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:11%">DO Code</th>
          <th style="width:7%">Tanggal</th>
          <th style="width:10%">Customer</th>
          <th style="width:9%">Office</th>
          <th style="width:8%">Grand Total</th>
          <th style="width:9%">WQS</th>
          <th style="width:11%">Durasi WQS / SLA</th>
          <th style="width:12%">Bukti Kartu Stok</th>
          <th style="width:23%">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="9" class="muted">Belum ada DO untuk WQS.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($rows as $r): ?>
          <?php
            $is_cancelled = in_array(strtolower((string)($r['status'] ?? '')), ['cancelled','canceled','void','archived'], true);
            $is_locked = in_array(strtolower((string)($r['status'] ?? '')), ['ready_scm','on_delivery','delivered','wait_payment','paid','cancelled','canceled','void','archived'], true);
          ?>
          <tr>
            <td>
              <div><b><?php echo h($r['do_code']); ?></b></div>
              <div class="mini muted">Track: <?php echo h($r['tracking_code'] ?? $r['do_code']); ?></div>
              <div class="mini">
                <a href="<?= h($salesU) ?>/sales_do_view.php?id=<?php echo (int)$r['id']; ?>" target="_blank">Detail</a>
                <span class="muted">•</span>
                <a href="<?= h($salesU) ?>/sales_do_view.php?id=<?php echo (int)$r['id']; ?>&mode=print" target="_blank">Print</a>
                <span class="muted">•</span>
                <?php
                  // Print CF tetap menuju endpoint resmi Sales.
                  // Akses endpoint harus diberikan melalui permission khusus SALES.DO_PRINT_CF
                  // pada Page Registry/RBAC; jangan membuka SALES.PRINT global hanya untuk WQS.
                ?>
                <a href="<?= h($salesU) ?>/sales_do_print_cf.php?id=<?php echo (int)$r['id']; ?>" target="_blank" rel="noopener" title="Print Continuous Form">Print CF</a>
                <span class="muted">•</span>
                <span class="muted">(barcode/exp/lot ada di detail)</span>
              </div>
            </td>
            <td><?php echo h(date('d-m-Y', strtotime((string)$r['do_date']))); ?></td>
            <td>
              <div><b><?php echo h($r['customers_name'] ?: $r['customers_code']); ?></b></div>
              <div class="mini muted"><?php echo h($r['customers_code']); ?></div>
            </td>
            <td>
              <div><b><?php echo h($r['office_name'] ?: $r['office_code']); ?></b></div>
              <div class="mini muted"><?php echo h($r['office_code']); ?><?php if (!empty($r['has_unit_acc'])): ?> · <b>UNIT ACC</b><?php endif; ?></div>
            </td>
            <td><?php echo h(number_format((float)($r['grand_total'] ?? 0), 0, ',', '.')); ?></td>
            <td>
              <div class="actions" style="gap:6px">
                <?php echo badgeStatus($r['status']); ?>
                <span class="tag"><?php echo h($r['wqs_status'] ?: 'Pending'); ?></span>
              </div>
              <div class="mini muted" style="margin-top:6px">
                Catatan: <?php echo h($r['wqs_note'] ?? ''); ?>
              </div>
              <?php if ($is_cancelled): ?>
                <div class="mini" style="margin-top:5px">
                  <b>Alasan batal:</b> <?php echo h($r['wqs_cancel_reason'] ?? $r['wqs_note'] ?? '-'); ?>
                </div>
                <?php if (!empty($r['wqs_cancelled_at'])): ?>
                  <div class="mini muted">Dibatalkan: <?php echo h(date('d-m-Y H:i:s', strtotime((string)$r['wqs_cancelled_at']))); ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php
                $wqsDurSec = wqs_effective_duration_sec($r);
                $wqsSla = $is_cancelled
                    ? ['label'=>'DIBATALKAN','class'=>'tag red']
                    : wqs_sla_meta($wqsDurSec, $wqsSlaTargetMinutes);
                $wqsRunning = !$is_cancelled
                    && in_array(strtolower((string)($r['status'] ?? '')), ['crm_to_wqs','sent_wqs','revision_requested','wqs_processing'], true)
                    && !empty($r['wqs_crm_done_at']);
              ?>
              <div class="sla-time<?php echo $wqsRunning ? ' js-wqs-timer' : ''; ?>"
                   <?php if ($wqsRunning): ?>data-start="<?php echo h((string)$r['wqs_crm_done_at']); ?>"<?php endif; ?>>
                <?php echo h(wqs_format_duration($wqsDurSec)); ?>
              </div>
              <div class="sla-meta">
                <span class="<?php echo h($wqsSla['class']); ?> js-wqs-sla-badge"
                      <?php if ($wqsRunning && $wqsSlaTargetMinutes): ?>data-target-sec="<?php echo (int)$wqsSlaTargetMinutes * 60; ?>"<?php endif; ?>>
                  <?php echo h($wqsSla['label']); ?>
                </span>
              </div>
              <?php if (!empty($r['wqs_crm_done_at'])): ?>
                <div class="mini muted" style="margin-top:5px">SLA mulai (CRM → WQS): <?php echo h(date('d-m-Y H:i:s', strtotime((string)$r['wqs_crm_done_at']))); ?></div>
              <?php endif; ?>
              <?php if (!empty($r['wqs_started_at'])): ?>
                <div class="mini muted">Proses WQS mulai: <?php echo h(date('d-m-Y H:i:s', strtotime((string)$r['wqs_started_at']))); ?></div>
              <?php endif; ?>
              <?php if (!empty($r['wqs_started_at']) && !empty($r['wqs_ready_at'])):
                $procStartTs = strtotime((string)$r['wqs_started_at']);
                $procDoneTs  = strtotime((string)$r['wqs_ready_at']);
                $procSec = ($procStartTs !== false && $procDoneTs !== false && $procDoneTs >= $procStartTs) ? ($procDoneTs - $procStartTs) : null;
              ?>
                <div class="mini muted">Durasi proses operator: <?php echo h(wqs_format_duration($procSec)); ?> <span style="opacity:.75">(bukan SLA)</span></div>
              <?php endif; ?>
              <?php if (!empty($r['wqs_ready_at'])): ?>
                <div class="mini muted">Selesai: <?php echo h(date('d-m-Y H:i:s', strtotime((string)$r['wqs_ready_at']))); ?></div>
              <?php elseif ($wqsRunning): ?>
                <div class="mini muted">Timer berjalan</div>
              <?php endif; ?>
            </td>
            <td>
              <?php
                $photoList = $wqsStockPhotos[(int)$r['id']] ?? ['BEFORE' => [], 'AFTER' => []];
                $beforeList = $photoList['BEFORE'] ?? [];
                $afterList  = $photoList['AFTER'] ?? [];
              ?>
              <div class="photo-links">
                <div>
                  <b>Before:</b>
                  <?php if ($beforeList): ?>
                    <?php foreach ($beforeList as $idx => $ph): ?>
                      <a href="<?php echo h($ph['file_path']); ?>" target="_blank">foto <?php echo (int)$idx + 1; ?></a><?php echo ($idx < count($beforeList)-1) ? ', ' : ''; ?>
                    <?php endforeach; ?>
                  <?php elseif ($r['wqs_stock_before']): ?>
                    <a href="<?php echo h($r['wqs_stock_before']); ?>" target="_blank">lihat</a>
                  <?php else: ?>
                    <span class="muted">-</span>
                  <?php endif; ?>
                </div>
                <div>
                  <b>After:</b>
                  <?php if ($afterList): ?>
                    <?php foreach ($afterList as $idx => $ph): ?>
                      <a href="<?php echo h($ph['file_path']); ?>" target="_blank">foto <?php echo (int)$idx + 1; ?></a><?php echo ($idx < count($afterList)-1) ? ', ' : ''; ?>
                    <?php endforeach; ?>
                  <?php elseif ($r['wqs_stock_after']): ?>
                    <a href="<?php echo h($r['wqs_stock_after']); ?>" target="_blank">lihat</a>
                  <?php else: ?>
                    <span class="muted">-</span>
                  <?php endif; ?>
                </div>
                <div class="mini muted">
                  Total: <?php echo count($beforeList); ?> before / <?php echo count($afterList); ?> after
                </div>
              </div>
            </td>
            <td>
              <form method="post" enctype="multipart/form-data" class="stack">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">

                <div class="grid">
                  <select class="field select" name="wqs_status" <?php echo (!$_wqs_can_process || $is_locked) ? "disabled" : ""; ?>>
                    <?php $cur = $r['wqs_status'] ?: 'Pending'; ?>
                    <option value="Pending" <?php echo $cur==='Pending'?'selected':''; ?>>Pending</option>
                    <option value="Open" <?php echo $cur==='Open'?'selected':''; ?>>Open</option>
                    <option value="Done" <?php echo $cur==='Done'?'selected':''; ?>>Done</option>
                  </select>

                  <input
                    class="field"
                    name="wqs_note"
                    placeholder="Catatan WQS / alasan batal..."
                    value="<?php echo h($r['wqs_note'] ?? ''); ?>"
                    <?php echo (!$_wqs_can_process || $is_locked) ? "disabled" : ""; ?>
                  >
                </div>

                <div class="grid">
                  <div>
                    <div class="mini muted" style="margin:0 0 6px">Foto kartu stok <b>SEBELUM</b> <span class="mini muted">(bisa pilih banyak)</span></div>
                    <input class="field file" type="file" name="wqs_stock_before[]" multiple <?php echo (!$_wqs_can_process || $is_locked) ? "disabled" : ""; ?> accept=".jpg,.jpeg,.png,.webp,.pdf">
                  </div>
                  <div>
                    <div class="mini muted" style="margin:0 0 6px">Foto kartu stok <b>SESUDAH</b> <span class="mini muted">(bisa pilih banyak)</span></div>
                    <input class="field file" type="file" name="wqs_stock_after[]" multiple <?php echo (!$_wqs_can_process || $is_locked) ? "disabled" : ""; ?> accept=".jpg,.jpeg,.png,.webp,.pdf">
                  </div>
                </div>


                <?php
                  $handover = $wqsHandoverMedia[(int)$r['id']] ?? ['PHOTO'=>[],'VIDEO'=>[]];
                  $handoverPhotos = $handover['PHOTO'] ?? [];
                  $handoverVideos = $handover['VIDEO'] ?? [];
                ?>
                <div style="padding:10px;border:1px solid rgba(255,255,255,.12);border-radius:10px">
                  <div class="mini" style="margin-bottom:8px"><b>Bukti Serah Terima WQS → SCM</b> <span class="muted">(tambahan; tidak mengubah Foto Kartu Stok)</span></div>
                  <?php if ($handoverPhotos || $handoverVideos): ?>
                    <div class="mini" style="margin-bottom:8px">
                      <?php foreach ($handoverPhotos as $i=>$m): ?><a href="<?php echo h($m['file_path']); ?>" target="_blank">foto <?php echo $i+1; ?></a><?php echo $i<count($handoverPhotos)-1?', ':''; ?><?php endforeach; ?>
                      <?php if ($handoverPhotos && $handoverVideos): ?> · <?php endif; ?>
                      <?php foreach ($handoverVideos as $i=>$m): ?><a href="<?php echo h($m['file_path']); ?>" target="_blank">video <?php echo $i+1; ?></a><?php echo $i<count($handoverVideos)-1?', ':''; ?><?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  <div class="grid">
                    <div><div class="mini muted" style="margin:0 0 6px">Foto serah terima</div><input class="field file" type="file" name="wqs_handover_photo[]" multiple <?php echo (!$_wqs_can_process || $is_locked) ? "disabled" : ""; ?> accept=".jpg,.jpeg,.png,.webp"></div>
                    <div><div class="mini muted" style="margin:0 0 6px">Video serah terima</div><input class="field file" type="file" name="wqs_handover_video[]" multiple <?php echo (!$_wqs_can_process || $is_locked) ? "disabled" : ""; ?> accept=".mp4,.mov,.webm,video/mp4,video/quicktime,video/webm"></div>
                  </div>
                  <?php if ($_wqs_can_process && !$is_locked): ?><div style="margin-top:8px"><button class="btn2" name="action" value="handover_save" type="submit">Upload Bukti Serah Terima</button></div><?php endif; ?>
                </div>

                <div class="actions">
                  <?php if ($_wqs_can_process && in_array($r['status'], ['crm_to_wqs','sent_wqs'], true)): ?>
                    <button class="btn2 warn" name="action" value="start" type="submit">Mulai Proses</button>
                  <?php endif; ?>

                  <?php if ($_wqs_can_process && !$is_locked): ?>
                    <button class="btn2 primary" name="action" value="save" type="submit">Simpan</button>
                  <?php endif; ?>

                  <?php if ($_wqs_can_process && !$is_locked && in_array((string)($r['status'] ?? ''), ['wqs_processing'], true)): ?>
                    <button class="btn2 ok" name="action" value="ready" type="submit">Set READY SCM</button>
                  <?php endif; ?>

                  <?php if ($_wqs_can_cancel && !$is_locked && in_array((string)($r['status'] ?? ''), ['crm_to_wqs','sent_wqs','revision_requested','wqs_processing'], true)): ?>
                    <button class="btn2 danger js-cancel-wqs" name="action" value="cancel" type="submit"
                            title="Tidak menghapus data. Menghentikan alur dan mengarsipkan DO sebagai cancelled.">
                      Batalkan / Arsipkan
                    </button>
                  <?php endif; ?>

                  <?php if (!$_wqs_can_process): ?>
                    <span class="mini muted">View only — proses WQS hanya untuk dept WQS/Admin.</span>
                  <?php elseif (!$_wqs_can_cancel && $_wqs_is_branch): ?>
                    <span class="mini muted"><?= $_wqs_is_branch_bgr_unit_acc ? 'StaffBRANCH_BGR: proses khusus UNIT ACC BGR sampai READY SCM; pembatalan tetap hanya WQS/SYS.' : 'BRANCH dapat proses office sendiri, tetapi pembatalan DO hanya WQS/SYS.' ?></span>
                  <?php endif; ?>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card" style="margin-top:12px">
    <div class="mini muted">
      Upload tersimpan ke:
      <code>/uploads/sales_wqs/</code>.
      Status <b>READY SCM</b> akan menjadi trigger halaman SCM.
    </div>
  </div>

  <div class="card" style="margin-top:12px">
    <div class="fw-semibold mb-2">Audit Log <span class="muted">(Last 50 events)</span></div>
    <?php if (empty($audit_rows)): ?>
      <div class="muted mini">Belum ada audit log.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:180px">Time</th>
              <th style="width:120px">Action</th>
              <th style="width:140px">Code</th>
              <th style="width:120px">User</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($audit_rows as $a): ?>
              <tr>
                <td><?php echo h($a['created_at'] ?? ''); ?></td>
                <td><?php echo h($a['action'] ?? ''); ?></td>
                <td><?php echo h($a['record_code'] ?? ''); ?></td>
                <td><?php echo h($a['username'] ?? ''); ?></td>
                <td><?php echo h($a['description'] ?? ''); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var token = <?= json_encode((string)csrf_token()) ?>;
  document.querySelectorAll('form[method="post"]').forEach(function (f) {
    if (!f.querySelector('input[name="csrf_token"]')) {
      var i = document.createElement('input');
      i.type = 'hidden';
      i.name = 'csrf_token';
      i.value = token;
      f.appendChild(i);
    }
  });
})();
</script>

<script>
(function () {
  document.querySelectorAll('.js-cancel-wqs').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      var form = btn.closest('form');
      var note = form ? form.querySelector('input[name="wqs_note"]') : null;
      var reason = note ? (note.value || '').trim() : '';
      if (!reason) {
        ev.preventDefault();
        alert('Isi Catatan WQS sebagai alasan pembatalan terlebih dahulu.');
        if (note) note.focus();
        return;
      }
      if (!window.confirm('Batalkan/arsipkan DO ini?\n\nData TIDAK dihapus. DO akan keluar dari antrean aktif WQS dan tidak diteruskan ke SCM/ACT/FIN.')) {
        ev.preventDefault();
      }
    });
  });
})();
</script>

<script>
(function () {
  function fmt(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60), ss = sec % 60, p = [];
    if (d > 0) p.push(d + ' hari');
    if (h > 0) p.push(h + ' jam');
    if (m > 0) p.push(m + ' menit');
    if (ss > 0 || p.length === 0) p.push(ss + ' detik');
    return p.join(' ');
  }
  function refresh() {
    var now = Date.now();
    document.querySelectorAll('.js-wqs-timer[data-start]').forEach(function (el) {
      var start = Date.parse((el.getAttribute('data-start') || '').replace(' ', 'T'));
      if (!Number.isFinite(start)) return;
      var sec = Math.max(0, Math.floor((now - start) / 1000));
      el.textContent = fmt(sec);
      var parent = el.parentElement;
      var badge = parent ? parent.querySelector('.js-wqs-sla-badge[data-target-sec]') : null;
      if (!badge) return;
      var target = parseInt(badge.getAttribute('data-target-sec') || '0', 10);
      if (!target) return;
      badge.classList.remove('blue','green','yellow','red');
      if (sec > target) { badge.textContent='LEWAT SLA'; badge.classList.add('red'); }
      else if (sec >= Math.floor(target * 0.8)) { badge.textContent='MENDEKATI SLA'; badge.classList.add('yellow'); }
      else { badge.textContent='AMAN'; badge.classList.add('green'); }
    });
  }
  refresh();
  window.setInterval(refresh, 1000);
})();
</script>

<?php rmi_footer(); ?>