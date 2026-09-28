<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.EDIT', 'SALES.VIEW']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','FIN']);
}

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/_do_office_scope.php';
require_once __DIR__ . '/_do_task_helpers.php';
// sales/fin_do_tasks.php
// FIN - Task DO status WAIT PAYMENT (upload bukti bayar, set PAID/CLOSED)
// Alur lama dipertahankan: tidak memakai pembayaran parsial pada halaman ini.
// ERP_RMI_SOFULL flow: CRM -> WQS -> SCM -> ACT -> FIN
// Catatan: auth/role parkir dulu (sementara), fokus UI/UX & flow.
// --- DB (centralized) ---
$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();


/*
 * STAFF FIN ACCESS FIX (localized)
 * --------------------------------
 * Untuk aksi FIN, department authoritative dibaca dari master_system_login
 * berdasarkan username login. Ini mencegah session department lama/salah
 * (mis. ACT) memblokir Staff FIN yang di database memang FIN.
 *
 * Tidak mengubah auth.php, login.php, matrix transition, atau akses dept lain.
 */
function fin_authoritative_department(PDO $pdo): string {
    $username = '';
    if (function_exists('auth_username')) {
        $username = trim((string)auth_username());
    }
    if ($username === '') {
        $username = trim((string)($_SESSION['username'] ?? ($_SESSION['user']['username'] ?? '')));
    }

    if ($username !== '') {
        try {
            $st = $pdo->prepare("
                SELECT department
                FROM master_system_login
                WHERE username = ?
                  AND (deleted_at IS NULL)
                LIMIT 1
            ");
            $st->execute([$username]);
            $dept = strtoupper(trim((string)$st->fetchColumn()));
            if ($dept !== '') return $dept;
        } catch (Throwable $e) {
            // Fail-soft: kembali ke resolver auth existing.
        }
    }

    return function_exists('auth_dept')
        ? strtoupper(trim((string)auth_dept()))
        : strtoupper(trim((string)($_SESSION['department'] ?? '')));
}

function fin_require_paid_transition(PDO $pdo, string $from): void {
    $dept = fin_authoritative_department($pdo);

    // Hanya akun yang authoritative department-nya FIN yang mendapat rule FIN.
    // Department lain tetap melewati guard existing tanpa bypass.
    if ($dept === 'FIN') {
        if (function_exists('auth_sales_do_transition_allowed')
            && auth_sales_do_transition_allowed($from, 'paid', 'FIN')) {
            return;
        }
    }

    if (function_exists('auth_sales_do_require_transition')) {
        auth_sales_do_require_transition($from, 'paid', 'FIN_PAID');
    }
}

function tax_invoice_required(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $st = $pdo->prepare("SELECT config_value FROM system_config WHERE config_group='TAX_INVOICE' AND config_key='REQUIRE_ISSUED_BEFORE_FIN_PAID' AND is_active=1 LIMIT 1");
        $st->execute();
        $v = strtolower(trim((string)$st->fetchColumn()));
        if ($v === '') return $cached = true;
        return $cached = in_array($v, ['1','true','yes','on'], true);
    } catch (Throwable $e) {
        return $cached = true;
    }
}

// ---------------- helpers ----------------
if (!function_exists('h')) {
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function ensure_column(PDO $pdo, string $table, string $column, string $ddl): void {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $exists = (bool)$stmt->fetch();
        if (!$exists) $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
    } catch (Throwable $e) {
        // fail-soft
    }
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}


function fin_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return $cache[$table] = false;
    try {
        // NOTE: placeholder (?) TIDAK valid di SHOW TABLES LIKE (MySQL 1064).
        // Interpolasi aman karena $table sudah divalidasi regex di atas.
        $rows = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchAll(PDO::FETCH_NUM) ?: [];
        return $cache[$table] = count($rows) > 0;
    } catch (Throwable $e) { return $cache[$table] = false; }
}

function fin_upload_timestamp(?string $path): ?string {
    $path = trim((string)$path);
    if ($path === '') return null;
    $name = basename($path);
    // Format upload ERP umumnya menyimpan YYYYMMDD_HHMMSS pada nama file.
    if (preg_match('/(?:^|_)(20\\d{2})(\\d{2})(\\d{2})_(\\d{2})(\\d{2})(\\d{2})(?:_|\\.|$)/', $name, $m)) {
        $v = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        if (strtotime($v) !== false) return $v;
    }
    return null;
}

function fin_exchange_evidence_complete(?string $exchangeFile, ?string $exchangeAt): bool {
    // Evidence Tukar Faktur tetap wajib memiliki FILE nyata.
    // Timestamp utama = act_exchange_doc_at / document store uploaded_at.
    // Compatibility aman: bila timestamp DB kosong tetapi file upload ERP ada,
    // timestamp boleh dibaca dari nama file yang memang dibuat saat upload.
    // WAIT PAYMENT / act_ready_fin_at tetap TIDAK pernah menjadi pengganti evidence.
    $file = trim((string)$exchangeFile);
    $at   = trim((string)$exchangeAt);
    if ($file === '') return false;
    if ($at !== '' && strtotime($at) !== false) return true;
    return fin_upload_timestamp($file) !== null;
}

function fin_normalize_exchange_evidence(?string $exchangeFile, ?string $exchangeAt): array {
    $file = trim((string)$exchangeFile);
    $at   = trim((string)$exchangeAt);
    if ($file !== '' && ($at === '' || strtotime($at) === false)) {
        $fromName = fin_upload_timestamp($file);
        if ($fromName !== null) $at = $fromName;
    }
    return ['file' => $file, 'at' => $at];
}

function fin_resolve_sla_start(PDO $pdo, int $doId, string $doCode, ?string $exchangeAt = null, ?string $exchangeFile = null, ?string $readyFinAt = null, ?string $legacyFinStart = null, ?string $actStatus = null, ?string $statusAct = null, ?string $actUpdatedAt = null): ?string {
    // Sumber utama SLA FIN = evidence Tukar Faktur ACT yang lengkap (file + timestamp).
    // Jangan infer dari WAIT PAYMENT, act_ready_fin_at, nama file, atau audit log.
    // Normalisasi lebih dulu. Pada data historis, file Tukar Faktur dapat valid sementara
    // act_exchange_doc_at kosong. Jika timestamp dapat dibaca dari nama file upload ERP,
    // resolver harus mengembalikan timestamp hasil normalisasi, bukan exchangeAt mentah.
    $normalized = fin_normalize_exchange_evidence($exchangeFile, $exchangeAt);
    if (fin_exchange_evidence_complete($normalized['file'], $normalized['at'])) {
        return trim((string)$normalized['at']);
    }

    // Compatibility penting untuk upload Tukar Faktur SUSULAN setelah WAIT PAYMENT.
    // Pada flow ACT resmi, save_exchange mengubah act_status='Done'/status_act='completed'
    // dan act_updated_at=NOW() HANYA setelah file Tukar Faktur berhasil disimpan.
    // Jadi kombinasi status selesai + act_updated_at adalah bukti workflow yang lebih kuat
    // daripada act_ready_fin_at (karena handoff FIN memang boleh terjadi sebelum Tukar Faktur).
    // PENTING: status ACT Done/completed boleh menjadi compatibility evidence bahwa
    // Tukar Faktur historis sudah selesai, tetapi act_updated_at BUKAN timestamp upload
    // Tukar Faktur yang authoritative. Jangan gunakan act_updated_at sebagai SLA start.
    // Ini mencegah DO lama mendapat tanggal mulai FIN palsu hanya karena ACT disentuh ulang.

    // act_ready_fin_at TIDAK dipakai sebagai bukti Tukar Faktur, karena ACT memang boleh
    // handoff ke WAIT PAYMENT sebelum Tukar Faktur diupload.

    // Snapshot historis FIN tetap dipertahankan sebagai fallback terakhir.
    $legacy = trim((string)$legacyFinStart);
    if ($legacy !== '' && strtotime($legacy) !== false) return $legacy;

    return null;
}

function fin_calendar_days(?string $startAt, ?string $endDate): ?int {
    $startAt = trim((string)$startAt);
    $endDate = trim((string)$endDate);
    if ($startAt === '' || $endDate === '') return null;

    try {
        $start = new DateTimeImmutable(date('Y-m-d', strtotime($startAt)));
        $end   = new DateTimeImmutable(date('Y-m-d', strtotime($endDate)));
        return (int)$start->diff($end)->format('%r%a');
    } catch (Throwable $e) {
        return null;
    }
}

function fin_duration_label(?int $days, bool $running = false): string {
    if ($days === null) return 'Belum tersedia';
    if ($days < 0) return 'Tanggal tidak valid';
    if ($days === 0) return $running ? '0 hari (hari ini)' : '0 hari (hari yang sama)';
    return $days . ' hari' . ($running ? ' berjalan' : '');
}

function upload_file(string $field, string $dirRel, array $allowExt): ?string {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

    $tmp = $f['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) return null;

    $name = (string)($f['name'] ?? 'file');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowExt, true)) return null;

    $base = realpath(__DIR__ . '/..'); // project root
    if ($base === false) return null;

    $dirAbs = $base . '/' . trim($dirRel, '/');
    if (!is_dir($dirAbs)) @mkdir($dirAbs, 0777, true);

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
    } catch (Throwable $e) { /* fail-soft */ }
}
function audit_actor_name(): string {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    $u = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? '';
    $u = trim((string)$u);
    return $u !== '' ? $u : 'SYSTEM';
}
function sales_do_audit_append(PDO $pdo, int $do_id, ?string $from, ?string $to, string $dept, string $note=''): void {
    try {
        ensure_audit_table($pdo);
        $actor = audit_actor_name();
        $st = $pdo->prepare("INSERT INTO sales_do_audit (do_id, status_from, status_to, actor_dept, actor_name, note)
                             VALUES (?,?,?,?,?,?)");
        $st->execute([$do_id, $from, $to, $dept, $actor, $note]);
    } catch (Throwable $e) { /* fail-soft */ }
}

// -------------- schema guards --------------
ensure_column($pdo, 'sales_do', 'status', "status VARCHAR(50) NOT NULL DEFAULT 'crm_to_wqs'");

// FIN fields
ensure_column($pdo, 'sales_do', 'fin_status', "fin_status VARCHAR(20) NULL"); // Pending/Open/Done
ensure_column($pdo, 'sales_do', 'fin_note', "fin_note TEXT NULL");

ensure_column($pdo, 'sales_do', 'fin_payment_file', "fin_payment_file VARCHAR(255) NULL"); // bukti bayar
ensure_column($pdo, 'sales_do', 'fin_paid_date', "fin_paid_date DATE NULL"); // tanggal masuk rekening
ensure_column($pdo, 'sales_do', 'fin_paid_at', "fin_paid_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'fin_paid_by', "fin_paid_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'fin_updated_by', "fin_updated_by VARCHAR(100) NULL");

// KPI SLA FIN: satuan hari kalender, start setelah Tukar Faktur ACT selesai/upload, freeze saat PAID.
// FIN SLA is derived from ACT exchange evidence. Do not ALTER production schema here.
// Existing snapshot columns are used only when already present.

ensure_column($pdo, 'sales_do', 'last_updated_by', "last_updated_by VARCHAR(50) NULL");
ensure_column($pdo, 'sales_do', 'revision_reason', "revision_reason TEXT NULL");
ensure_column($pdo, 'sales_do', 'revision_requested_by', "revision_requested_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'revision_requested_at', "revision_requested_at DATETIME NULL");

$hasActReadyFinAt   = table_has_column($pdo, 'sales_do', 'act_ready_fin_at');
$hasActExchangeDocAt = table_has_column($pdo, 'sales_do', 'act_exchange_doc_at');
$hasActExchangeDocFile = table_has_column($pdo, 'sales_do', 'act_exchange_doc_file');
$hasActStatusCol      = table_has_column($pdo, 'sales_do', 'act_status');
$hasStatusActCol      = table_has_column($pdo, 'sales_do', 'status_act');
$hasActUpdatedAtCol   = table_has_column($pdo, 'sales_do', 'act_updated_at');
$hasFinSlaStartAt   = table_has_column($pdo, 'sales_do', 'fin_sla_start_at');
$hasFinDurationDays = table_has_column($pdo, 'sales_do', 'fin_duration_days');
$hasFinPaidAt       = table_has_column($pdo, 'sales_do', 'fin_paid_at');
$hasExchangeStore    = fin_table_exists($pdo, 'sales_do_act_exchange_docs');

/**
 * Resolve Tukar Faktur ACT tanpa mengubah data.
 * Prioritas: canonical sales_do -> normalized document store terbaru -> legacy audit recovery.
 * WAIT PAYMENT / act_ready_fin_at tidak pernah dianggap sebagai evidence.
 */
function fin_exchange_store_one(PDO $pdo, int $doId): array {
    $out = ['file'=>'', 'at'=>''];
    if ($doId <= 0 || !fin_table_exists($pdo, 'sales_do_act_exchange_docs')) return $out;
    try {
        $st = $pdo->prepare("SELECT file_path, uploaded_at
                               FROM sales_do_act_exchange_docs
                              WHERE do_id=? AND COALESCE(file_path,'')<>''
                              ORDER BY COALESCE(updated_at, uploaded_at) DESC
                              LIMIT 1");
        $st->execute([$doId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['file'] = trim((string)($r['file_path'] ?? ''));
            $out['at']   = trim((string)($r['uploaded_at'] ?? ''));
        }
    } catch (Throwable $e) { /* fail-soft */ }
    return $out;
}

function fin_legacy_exchange_from_audit(PDO $pdo, int $doId): array {
    static $memo = [];
    static $fileIndex = null;
    if ($doId <= 0) return ['file'=>'','at'=>''];
    if (isset($memo[$doId])) return $memo[$doId];
    $out = ['file'=>'','at'=>''];
    try {
        $st = $pdo->prepare("SELECT created_at FROM system_audit_logs
                              WHERE record_id=? AND action='ACT_SAVE_EXCHANGE_AFTER_FIN'
                              ORDER BY created_at ASC");
        $st->execute([$doId]);
        $times = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$times) return $memo[$doId] = $out;

        if ($fileIndex === null) {
            $fileIndex = [];
            $root = realpath(__DIR__ . '/..');
            $dir = $root ? $root . '/uploads/sales_act' : '';
            if ($dir && is_dir($dir)) {
                foreach (new DirectoryIterator($dir) as $fi) {
                    if (!$fi->isFile()) continue;
                    $name = $fi->getFilename();
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['pdf','jpg','jpeg','png'], true)) continue;
                    if (preg_match('/_(20\\d{6})_(\\d{6})_[A-Za-z0-9]+\\.[A-Za-z0-9]+$/', $name, $m)) {
                        $fileIndex[$m[1].'_'.$m[2]][] = '/uploads/sales_act/'.$name;
                    }
                }
            }
        }
        foreach ($times as $ts) {
            try { $dt = new DateTimeImmutable((string)$ts); } catch (Throwable $e) { continue; }
            $cands = [];
            foreach ([0,-1,1,-2,2] as $off) {
                $d = $off === 0 ? $dt : $dt->modify(($off > 0 ? '+' : '').$off.' seconds');
                foreach (($fileIndex[$d->format('Ymd_His')] ?? []) as $p) $cands[$p] = true;
                if ($off === 0 && count($cands) === 1) break;
            }
            if (count($cands) === 1) {
                $out['file'] = (string)array_key_first($cands);
                $out['at'] = (string)$ts;
                return $memo[$doId] = $out;
            }
        }
    } catch (Throwable $e) { /* fail-soft */ }
    return $memo[$doId] = $out;
}

function fin_hydrate_exchange_evidence(PDO $pdo, array $row): array {
    $file = trim((string)($row['act_exchange_doc_file'] ?? ''));
    $at   = trim((string)($row['act_exchange_doc_at'] ?? ''));

    $storeFile = trim((string)($row['store_exchange_file'] ?? ''));
    $storeAt   = trim((string)($row['store_exchange_at'] ?? ''));
    if (($storeFile === '' || $storeAt === '') && (int)($row['id'] ?? 0) > 0) {
        $st = fin_exchange_store_one($pdo, (int)$row['id']);
        if ($storeFile === '') $storeFile = $st['file'];
        if ($storeAt === '') $storeAt = $st['at'];
    }
    if ($file === '') $file = $storeFile;
    if ($at === '') $at = $storeAt;

    // Historical recovery hanya jika canonical + store belum lengkap.
    if (!fin_exchange_evidence_complete($file, $at)) {
        $lx = fin_legacy_exchange_from_audit($pdo, (int)($row['id'] ?? 0));
        if ($file === '') $file = trim((string)$lx['file']);
        if ($at === '') $at = trim((string)$lx['at']);
    }

    // Jika file ACT nyata ada tetapi timestamp DB historis kosong, gunakan timestamp
    // yang tertanam pada nama file upload ERP. Ini bukan timestamp WAIT PAYMENT/handoff.
    $norm = fin_normalize_exchange_evidence($file, $at);
    $row['act_exchange_doc_file'] = $norm['file'];
    $row['act_exchange_doc_at'] = $norm['at'];
    return $row;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
verify_csrf((string)($_POST['csrf_token'] ?? ''));
$action = trim((string)($_POST['action'] ?? ''));
    $id     = (int)($_POST['id'] ?? 0);

    try {
        $stmt = $pdo->prepare("
            SELECT id, do_code, status, office_code, fin_payment_file, fin_paid_date, customers_code,
                   " . ($hasActExchangeDocAt ? "act_exchange_doc_at" : "NULL AS act_exchange_doc_at") . ",
                   " . ($hasActExchangeDocFile ? "act_exchange_doc_file" : "NULL AS act_exchange_doc_file") . ",
                   " . ($hasActReadyFinAt ? "act_ready_fin_at" : "NULL AS act_ready_fin_at") . ",
                   " . ($hasActStatusCol ? "act_status" : "NULL AS act_status") . ",
                   " . ($hasStatusActCol ? "status_act" : "NULL AS status_act") . ",
                   " . ($hasActUpdatedAtCol ? "act_updated_at" : "NULL AS act_updated_at") . ",
                   " . ($hasFinSlaStartAt ? "fin_sla_start_at" : "NULL AS fin_sla_start_at") . ",
                   " . ($hasFinDurationDays ? "fin_duration_days" : "NULL AS fin_duration_days") . ",
                   " . ($hasFinPaidAt ? "fin_paid_at" : "NULL AS fin_paid_at") . ",
                   " . ($hasExchangeStore ? "(SELECT x.file_path FROM sales_do_act_exchange_docs x WHERE x.do_id=sales_do.id AND COALESCE(x.file_path,'')<>'' ORDER BY COALESCE(x.updated_at,x.uploaded_at) DESC LIMIT 1) AS store_exchange_file, (SELECT x.uploaded_at FROM sales_do_act_exchange_docs x WHERE x.do_id=sales_do.id AND COALESCE(x.file_path,'')<>'' ORDER BY COALESCE(x.updated_at,x.uploaded_at) DESC LIMIT 1) AS store_exchange_at" : "NULL AS store_exchange_file, NULL AS store_exchange_at") . "
            FROM sales_do
            WHERE id=?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) throw new Exception("DO tidak ditemukan.");
        // ACT menyimpan evidence ke canonical sales_do dan document store. FIN membaca keduanya
        // agar upload ACT langsung dikenali tanpa bergantung pada sinkronisasi field lama.
        $r = fin_hydrate_exchange_evidence($pdo, $r);
        do_scope_assert_row($r); // Guard: BRANCH hanya boleh PAID DO kantornya sendiri

        // --- SOFT LOCK FIN ---
        $locked_status  = ['paid','fin_done','closed'];
        $curStatus = strtolower(trim((string)($r['status'] ?? '')));
        if (in_array($curStatus, $locked_status, true)) {
            throw new Exception("DO sudah lanjut/selesai. Data FIN terkunci (read-only).");
        }
        // Guard: FIN action hanya boleh sesuai status flow
        if ($action === 'paid' && $curStatus !== 'wait_payment') {
            throw new Exception("FIN: tidak bisa set PAID. Status harus wait_payment. Status sekarang: {$curStatus}");
        }
        $note = trim((string)($_POST['fin_note'] ?? ''));
        $fin_status = (string)($_POST['fin_status'] ?? 'Pending');
        if (!in_array($fin_status, ['Pending','Open','Done'], true)) $fin_status = 'Pending';

        $paid_date = trim((string)($_POST['fin_paid_date'] ?? ''));

        $docExt = ['pdf','jpg','jpeg','png','webp'];
        $pay_up = upload_file('fin_payment_file', 'uploads/sales_fin', $docExt);
        $pay = $pay_up ?: ($r['fin_payment_file'] ?? '');
        // KPI SLA FIN wajib menyimpan PIC aktual, bukan label generik 'FIN'.
        // Dipakai oleh /kpi/kpi_do_sla.php untuk membaca performa per akun staff FIN.
        $finActor = audit_actor_name();

        $finSlaStart = fin_resolve_sla_start(
            $pdo,
            $id,
            (string)($r['do_code'] ?? ''),
            (string)($r['act_exchange_doc_at'] ?? ''),
            (string)($r['act_exchange_doc_file'] ?? ''),
            (string)($r['act_ready_fin_at'] ?? ''),
            (string)($r['fin_sla_start_at'] ?? ''),
        (string)($r['act_status'] ?? ''),
        (string)($r['status_act'] ?? ''),
        (string)($r['act_updated_at'] ?? '')
        );

        if ($action === 'save') {
            $sets = "fin_note=?, fin_status=?, fin_updated_by=?, last_updated_by=?, last_updated_at=NOW()";
            $params = [$note, $fin_status, $finActor, $finActor];

            if ($pay_up) { $sets .= ", fin_payment_file=?"; $params[] = $pay_up; }
            if ($paid_date !== '') { $sets .= ", fin_paid_date=?"; $params[] = $paid_date; }
            if ($hasFinSlaStartAt && $finSlaStart !== null) {
                $sets .= ", fin_sla_start_at=?";
                $params[] = $finSlaStart;
            }

            $params[] = $id;
            $pdo->prepare("UPDATE sales_do SET {$sets} WHERE id=?")->execute($params);

            // PATCH_3_AUDIT
            $afterRow = null;
            try {
                $stA = $pdo->prepare("SELECT id, status, fin_status, fin_note, fin_payment_file, fin_paid_date, fin_paid_at, fin_paid_by, fin_updated_by, last_updated_by, last_updated_at FROM sales_do WHERE id=? LIMIT 1");
                $stA->execute([$id]);
                $afterRow = $stA->fetch();
            } catch (Throwable $e) {
                $afterRow = null;
            }
            rmi_audit_safe('UPDATE', 'SALES.DO', $id, $r, $afterRow, [
                'event' => 'fin_save',
            ]);
            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit($pdo, 'sales_do', 'sales_do', 'FIN_SAVE', $id, $code, "DO FIN save: {$code}", []);
            }
            $success = "Tersimpan (FIN).";
        }

        if ($action === 'approve_revision') {
            if ($curStatus !== 'revision_fin_review') {
                throw new Exception("FIN: approval revisi hanya untuk status revision_fin_review. Status sekarang: {$curStatus}");
            }

            $pdo->prepare("UPDATE sales_do SET
                status='crm_to_wqs',
                fin_note=?,
                fin_status='Done',
                fin_updated_by=?,
                last_updated_by=?,
                " . ($hasFinSlaStartAt ? "fin_sla_start_at=NULL," : "") . "
                " . ($hasFinDurationDays ? "fin_duration_days=NULL," : "") . "
                last_updated_at=NOW()
              WHERE id=?")->execute([$note, $finActor, $finActor, $id]);

            $afterRow = null;
            try {
                $stA = $pdo->prepare("SELECT id, status, fin_status, fin_note, grand_total, fin_updated_by, last_updated_by, last_updated_at FROM sales_do WHERE id=? LIMIT 1");
                $stA->execute([$id]);
                $afterRow = $stA->fetch();
            } catch (Throwable $e) {
                $afterRow = null;
            }

            rmi_audit_safe('APPROVE', 'SALES.DO', $id, array_merge($r, ['status' => $curStatus]), $afterRow, [
                'event' => 'fin_approve_revision',
                'to_status' => 'crm_to_wqs',
            ]);
            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit($pdo, 'sales_do', 'sales_do', 'FIN_APPROVE_REVISION', $id, $code, "DO FIN approve revision: {$code}", ['to_status' => 'crm_to_wqs']);
            }
            sales_do_audit_append($pdo, $id, $curStatus, 'crm_to_wqs', 'FIN', $note);
            $success = "Revisi nilai disetujui FIN. DO dikembalikan ke WQS untuk re-check.";
        }

        if ($action === 'paid') {
            if ($curStatus !== 'wait_payment') {
                throw new Exception('Pembayaran hanya dapat diselesaikan untuk status WAIT PAYMENT.');
            }
            if (!$pay) {
                throw new Exception('Wajib upload bukti pembayaran sebelum Set PAID.');
            }

            // Gate FIN: utamakan evidence Tukar Faktur lengkap. Untuk data historis yang canonical
            // evidence-nya tidak lengkap, handoff ACT->FIN yang valid tetap membuktikan ACT sudah
            // menyelesaikan Tukar Faktur. Ini mencegah DO sah terjebak di 'menunggu Tukar Faktur ACT'.
            $hasExchangeEvidence = fin_exchange_evidence_complete(
                (string)($r['act_exchange_doc_file'] ?? ''),
                (string)($r['act_exchange_doc_at'] ?? '')
            );
            $actDoneEvidence = strtolower(trim((string)($r['act_status'] ?? ''))) === 'done'
                || strtolower(trim((string)($r['status_act'] ?? ''))) === 'completed';
            $actUpdatedEvidence = trim((string)($r['act_updated_at'] ?? ''));
            $hasActCompletedExchange = $actDoneEvidence
                && $actUpdatedEvidence !== ''
                && strtotime($actUpdatedEvidence) !== false;
            if (!$hasExchangeEvidence && !$hasActCompletedExchange) {
                throw new Exception('Tidak bisa Set PAID: Bukti Tukar Faktur ACT belum tersedia.');
            }

            if (tax_invoice_required($pdo)) {
                $salesRef = (string)($r['do_code'] ?? '');
                $hasIssuedTax = false;
                try {
                    if (class_exists(\App\Accounting\TaxInvoiceService::class)) {
                        $taxSvc = new \App\Accounting\TaxInvoiceService();
                        $hasIssuedTax = $taxSvc->isIssuedForRef($pdo, $salesRef);
                    }
                } catch (Throwable $e) {
                    $hasIssuedTax = false;
                }
                if (!$hasIssuedTax) {
                    throw new Exception('Tidak bisa Set PAID: Tax Invoice ISSUED belum tersedia untuk ref '.$salesRef.'.');
                }
            }

            // STAFF FIN: validasi transition memakai department authoritative dari master_system_login.
            // ACT/CRM/WQS/SCM tetap tidak dapat melakukan wait_payment -> paid.
            fin_require_paid_transition($pdo, $curStatus);

            $final_paid_date = ($paid_date !== '') ? $paid_date : date('Y-m-d');

            // Timestamp SLA hanya boleh berasal dari evidence upload yang authoritative.
            // Untuk data historis ACT Done/completed tanpa timestamp upload yang dapat
            // dibuktikan, pembayaran tetap boleh diselesaikan, tetapi KPI FIN tidak boleh
            // dibuat dari act_updated_at/WAIT PAYMENT karena akan menghasilkan tanggal palsu.
            $finDurationDays = null;
            if ($finSlaStart !== null) {
                $finDurationDays = fin_calendar_days($finSlaStart, $final_paid_date);
                if ($finDurationDays === null) {
                    throw new Exception('Tidak bisa menghitung durasi KPI FIN.');
                }
                // EARLY PAYMENT:
                // Tanggal pembayaran adalah fakta transaksi dan boleh lebih awal
                // dari tanggal Tukar Faktur ACT. Jangan ubah tanggal aktual dan
                // jangan hasilkan SLA negatif. Untuk KPI FIN, early payment = 0 hari.
                if ($finDurationDays < 0) {
                    $finDurationDays = 0;
                }
            } elseif (!$hasActCompletedExchange) {
                throw new Exception('Tidak bisa Set PAID: Bukti Tukar Faktur ACT belum tersedia.');
            }

            $pdo->beginTransaction();
            $paidSql = "UPDATE sales_do SET
                    status='paid',
                    fin_status='Done',
                    fin_note=?,
                    fin_payment_file=?,
                    fin_paid_date=?,
                    fin_paid_at=NOW(),
                    fin_paid_by=?,
                    fin_updated_by=?,
                    last_updated_by=?,
                    " . ($hasFinSlaStartAt ? "fin_sla_start_at=?," : "") . "
                    " . ($hasFinDurationDays ? "fin_duration_days=?," : "") . "
                    last_updated_at=NOW()
                WHERE id=? AND status='wait_payment'";
            $paidParams = [$note, $pay, $final_paid_date, $finActor, $finActor, $finActor];
            if ($hasFinSlaStartAt) $paidParams[] = $finSlaStart;
            if ($hasFinDurationDays) $paidParams[] = $finDurationDays;
            $paidParams[] = $id;
            $pdo->prepare($paidSql)->execute($paidParams);
            $pdo->commit();

            $afterRow = null;
            try {
                $stA = $pdo->prepare("SELECT id,status,fin_status,fin_note,fin_payment_file,fin_paid_date,fin_paid_at,fin_paid_by,fin_updated_by,last_updated_by,last_updated_at FROM sales_do WHERE id=? LIMIT 1");
                $stA->execute([$id]);
                $afterRow = $stA->fetch();
            } catch (Throwable $e) {
                $afterRow = null;
            }
            rmi_audit_safe('POSTING', 'SALES.DO', $id, array_merge($r, ['status'=>$curStatus]), $afterRow, [
                'event' => 'fin_paid',
                'to_status' => 'paid',
            ]);
            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit($pdo, 'sales_do', 'sales_do', 'FIN_PAID', $id, $code, "DO FIN paid: {$code}", []);
            }
            sales_do_audit_append($pdo, $id, $curStatus, 'paid', 'FIN', $note);
            $success = 'Pembayaran tersimpan. Status DO menjadi PAID / CLOSED.';
        }


    } catch (Throwable $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// FIN backlog: WAIT PAYMENT & PAID
$_f_status  = trim((string)($_GET['f_status'] ?? ''));
$_f_date_fr = trim((string)($_GET['f_date_fr'] ?? ''));
$_f_date_to = trim((string)($_GET['f_date_to'] ?? ''));
$_f_q       = trim((string)($_GET['f_q'] ?? ''));

$_fin_scope = do_scope_where('d');
$_fin_extra_sql    = '';
$_fin_extra_params = [];

$_allowed_fin_status = ['revision_fin_review','wait_payment','paid'];
if ($_f_status !== '' && in_array($_f_status, $_allowed_fin_status, true)) {
    if ($_f_status === 'paid') {
        // UI "PAID / CLOSED" harus mencakup seluruh status terminal O2C yang sah.
        $_fin_extra_sql .= " AND LOWER(TRIM(COALESCE(d.status,''))) IN ('paid','fin_done','closed')";
    } else {
        $_fin_extra_sql    .= " AND LOWER(TRIM(COALESCE(d.status,''))) = ?";
        $_fin_extra_params[] = $_f_status;
    }
}

// Filter tanggal mengikuti makna status:
// - PAID / CLOSED => WAJIB memakai tanggal pembayaran bisnis yang disimpan di fin_paid_date.
//   Jangan fallback ke fin_paid_at, karena fin_paid_at adalah timestamp saat tombol Set PAID diproses
//   dan bukan selalu tanggal uang masuk yang ditampilkan sebagai "Tgl bayar".
//   Konsekuensi yang benar: record PAID lama dengan fin_paid_date NULL tidak ikut hasil
//   ketika pengguna memasang filter tanggal pembayaran.
// - status lainnya => tanggal DO (alur lama tetap).
$_filterDateExpr = ($_f_status === 'paid')
    ? "d.fin_paid_date"
    : "d.do_date";
if ($_f_date_fr !== '') { $_fin_extra_sql .= " AND {$_filterDateExpr} >= ?"; $_fin_extra_params[] = $_f_date_fr; }
if ($_f_date_to !== '') { $_fin_extra_sql .= " AND {$_filterDateExpr} <= ?"; $_fin_extra_params[] = $_f_date_to; }
if ($_f_q !== '') {
    $_fin_extra_sql    .= " AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR c.customers_name LIKE ?)";
    $_like = '%' . $_f_q . '%';
    $_fin_extra_params = array_merge($_fin_extra_params, [$_like, $_like, $_like]);
}

// PERFORMANCE: halaman FIN tidak boleh mengambil seluruh histori sekaligus.
// 100 DO/page menjaga flow lama tetapi mencegah 504 ketika tabel membesar.
$_per_page = 100;
$_page = max(1, (int)($_GET['page'] ?? 1));

$_count_sql = "SELECT COUNT(*)
    FROM sales_do d
    LEFT JOIN master_customers c ON c.customers_code = d.customers_code
    WHERE LOWER(TRIM(COALESCE(d.status,''))) IN ('revision_fin_review','wait_payment','paid','fin_done','closed')
    " . $_fin_scope['sql'] . $_fin_extra_sql;
$_count_st = $pdo->prepare($_count_sql);
$_count_st->execute(array_merge($_fin_scope['params'], $_fin_extra_params));
$_total_rows = (int)$_count_st->fetchColumn();
$_total_pages = max(1, (int)ceil($_total_rows / $_per_page));
if ($_page > $_total_pages) $_page = $_total_pages;
$_offset = ($_page - 1) * $_per_page;

$_storeSelect = $hasExchangeStore
    ? "(SELECT x.file_path FROM sales_do_act_exchange_docs x WHERE x.do_id=d.id AND COALESCE(x.file_path,'')<>'' ORDER BY COALESCE(x.updated_at,x.uploaded_at) DESC LIMIT 1) AS store_exchange_file,
       (SELECT x.uploaded_at FROM sales_do_act_exchange_docs x WHERE x.do_id=d.id AND COALESCE(x.file_path,'')<>'' ORDER BY COALESCE(x.updated_at,x.uploaded_at) DESC LIMIT 1) AS store_exchange_at"
    : "NULL AS store_exchange_file, NULL AS store_exchange_at";

$_fin_sql = "
    SELECT d.id, d.do_code, d.tracking_code, d.do_date, d.customers_code,
           c.customers_name,
           d.office_code, d.grand_total,
           d.status, d.fin_status, d.fin_note, d.fin_payment_file, d.fin_paid_date,
           " . ($hasActExchangeDocAt ? "d.act_exchange_doc_at" : "NULL AS act_exchange_doc_at") . ",
           " . ($hasActExchangeDocFile ? "d.act_exchange_doc_file" : "NULL AS act_exchange_doc_file") . ",
           " . ($hasActReadyFinAt ? "d.act_ready_fin_at" : "NULL AS act_ready_fin_at") . ",
           " . ($hasActStatusCol ? "d.act_status" : "NULL AS act_status") . ",
           " . ($hasStatusActCol ? "d.status_act" : "NULL AS status_act") . ",
           " . ($hasActUpdatedAtCol ? "d.act_updated_at" : "NULL AS act_updated_at") . ",
           " . ($hasFinSlaStartAt ? "d.fin_sla_start_at" : "NULL AS fin_sla_start_at") . ",
           " . ($hasFinDurationDays ? "d.fin_duration_days" : "NULL AS fin_duration_days") . ",
           " . ($hasFinPaidAt ? "d.fin_paid_at" : "NULL AS fin_paid_at") . ",
           {$_storeSelect}
    FROM sales_do d
    LEFT JOIN master_customers c ON c.customers_code = d.customers_code
    WHERE LOWER(TRIM(COALESCE(d.status,''))) IN ('revision_fin_review','wait_payment','paid','fin_done','closed')
    " . $_fin_scope['sql'] . $_fin_extra_sql . "
    ORDER BY
      FIELD(LOWER(TRIM(COALESCE(d.status,''))),'revision_fin_review','wait_payment','paid','fin_done','closed'),
      " . ($_f_status === 'paid' ? "d.fin_paid_date DESC, " : "") . "
      d.do_date DESC, d.id DESC
    LIMIT {$_per_page} OFFSET {$_offset}";
$_fin_st = $pdo->prepare($_fin_sql);
$_fin_st->execute(array_merge($_fin_scope['params'], $_fin_extra_params));
$rows = $_fin_st->fetchAll(PDO::FETCH_ASSOC);

// Canonical + normalized store sudah dibawa oleh query di atas.
// Legacy filesystem/audit recovery sengaja TIDAK dijalankan massal saat membuka list,
// karena itulah sumber I/O + N-query yang membuat FIN berat/504.
// Recovery legacy tetap tersedia pada POST terhadap satu DO melalui fin_hydrate_exchange_evidence().
foreach ($rows as &$__finRow) {
    $file = trim((string)($__finRow['act_exchange_doc_file'] ?? ''));
    $at   = trim((string)($__finRow['act_exchange_doc_at'] ?? ''));
    if ($file === '') $file = trim((string)($__finRow['store_exchange_file'] ?? ''));
    if ($at === '')   $at   = trim((string)($__finRow['store_exchange_at'] ?? ''));
    $norm = fin_normalize_exchange_evidence($file, $at);
    $__finRow['act_exchange_doc_file'] = $norm['file'];
    $__finRow['act_exchange_doc_at'] = $norm['at'];
}
unset($__finRow);

// PERFORMANCE + COMPATIBILITY:
// Sebagian upload Tukar Faktur historis hanya dapat direkonstruksi dari audit + nama file.
// Jangan lakukan recovery per-row (N query + N filesystem scan). Recovery dilakukan BATCH
// hanya untuk maksimal 100 DO pada halaman aktif: 1 query audit + 1 scan folder.
$_missingExchangeIds = [];
foreach ($rows as $__r) {
    if (!fin_exchange_evidence_complete(
        (string)($__r['act_exchange_doc_file'] ?? ''),
        (string)($__r['act_exchange_doc_at'] ?? '')
    )) {
        $_missingExchangeIds[] = (int)($__r['id'] ?? 0);
    }
}
$_missingExchangeIds = array_values(array_filter(array_unique($_missingExchangeIds)));

if ($_missingExchangeIds) {
    try {
        $_ph = implode(',', array_fill(0, count($_missingExchangeIds), '?'));
        $_stLegacy = $pdo->prepare("SELECT record_id, created_at
            FROM system_audit_logs
            WHERE record_id IN ($_ph)
              AND action='ACT_SAVE_EXCHANGE_AFTER_FIN'
            ORDER BY record_id ASC, created_at ASC");
        $_stLegacy->execute($_missingExchangeIds);
        $_auditByDo = [];
        while ($_a = $_stLegacy->fetch(PDO::FETCH_ASSOC)) {
            $_rid = (int)($_a['record_id'] ?? 0);
            if ($_rid > 0) $_auditByDo[$_rid][] = (string)($_a['created_at'] ?? '');
        }

        // Index file satu kali untuk seluruh page. Nama upload ERP membawa timestamp YYYYMMDD_HHMMSS.
        $_fileByTs = [];
        $_root = realpath(__DIR__ . '/..');
        $_dir = $_root ? $_root . '/uploads/sales_act' : '';
        if ($_dir && is_dir($_dir)) {
            foreach (new DirectoryIterator($_dir) as $_fi) {
                if (!$_fi->isFile()) continue;
                $_name = $_fi->getFilename();
                $_ext = strtolower(pathinfo($_name, PATHINFO_EXTENSION));
                if (!in_array($_ext, ['pdf','jpg','jpeg','png'], true)) continue;
                if (preg_match('/_(20\\d{6})_(\\d{6})_[A-Za-z0-9]+\\.[A-Za-z0-9]+$/', $_name, $_m)) {
                    $_fileByTs[$_m[1].'_'.$_m[2]][] = '/uploads/sales_act/'.$_name;
                }
            }
        }

        foreach ($rows as &$__finRow) {
            if (fin_exchange_evidence_complete(
                (string)($__finRow['act_exchange_doc_file'] ?? ''),
                (string)($__finRow['act_exchange_doc_at'] ?? '')
            )) continue;

            $_rid = (int)($__finRow['id'] ?? 0);
            foreach (($_auditByDo[$_rid] ?? []) as $_ts) {
                try { $_dt = new DateTimeImmutable($_ts); } catch (Throwable $_e) { continue; }
                $_cands = [];
                foreach ([0,-1,1,-2,2] as $_off) {
                    $_d = $_off === 0 ? $_dt : $_dt->modify(($_off > 0 ? '+' : '').$_off.' seconds');
                    foreach (($_fileByTs[$_d->format('Ymd_His')] ?? []) as $_p) $_cands[$_p] = true;
                    if ($_off === 0 && count($_cands) === 1) break;
                }
                if (count($_cands) === 1) {
                    if (trim((string)($__finRow['act_exchange_doc_file'] ?? '')) === '') {
                        $__finRow['act_exchange_doc_file'] = (string)array_key_first($_cands);
                    }
                    if (trim((string)($__finRow['act_exchange_doc_at'] ?? '')) === '') {
                        $__finRow['act_exchange_doc_at'] = $_ts;
                    }
                    break;
                }
            }
        }
        unset($__finRow);
    } catch (Throwable $_e) {
        // fail-soft: halaman FIN tetap dapat dibuka walaupun recovery historis gagal.
    }
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
    return '<span class="'.$cls.'">'.h($label).'</span>';
}

function badgeStatus($s): string {
    $s = strtolower((string)$s);
    $map = [
        'revision_fin_review' => ['REVISI NILAI','tag yellow'],
        'wait_payment' => ['WAIT PAYMENT','tag yellow'],
        'paid'         => ['PAID / CLOSED','tag green'],
        'fin_done'     => ['PAID / CLOSED','tag green'],
        'closed'       => ['PAID / CLOSED','tag green'],
    ];
    $v = $map[$s] ?? [strtoupper($s), 'tag'];
    return '<span class="'.$v[1].'">'.h($v[0]).'</span>';
}
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('FIN - Task DO', [
  'active' => 'sales',
  'breadcrumbs' => [
    ['label' => 'Sales (CRM)', 'url' => $baseProject . '/sales/sales_dashboard.php'],
    'FIN - Task DO',
  ],
  'actions' => [
    ['label' => '📚 Panduan Task', 'url' => $baseProject . '/sales/panduan_do_tasks.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => '🗼 Control Tower', 'url' => $baseProject . '/sales/sales_control_tower.php', 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'extra_head' => '<style>
    :root{
      --bg:#0b1220; --card2:#111827; --line:rgba(255,255,255,.08);
      --text:#e5e7eb; --muted:#94a3b8;
    }
    *{box-sizing:border-box}
    body{margin:0;background:radial-gradient(1200px 600px at 50% -10%, rgba(96,165,250,.15), transparent), var(--bg); color:var(--text); font-family: ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;}
    a{color:#93c5fd;text-decoration:none}
    a:hover{text-decoration:underline}
    .wrap{max-width:1180px;margin:22px auto;padding:0 14px}
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
    .table-wrap{overflow:auto;border-radius:14px}
    table{width:100%;border-collapse:separate;border-spacing:0;min-width:1120px}
    thead th{position:sticky;top:0;background:rgba(15,23,42,.95); color:#cbd5e1; font-weight:600; font-size:12px; text-align:left; padding:10px 10px; border-bottom:1px solid var(--line)}
    tbody td{padding:10px 10px; border-bottom:1px solid var(--line); vertical-align:top; font-size:12px}
    tbody tr:hover{background:rgba(255,255,255,.03)}
    .tag{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-size:11px;border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.04)}
    .tag.yellow{border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.12); color:#fff7ed}
    .tag.green{border-color:rgba(34,197,94,.35); background:rgba(34,197,94,.12)}
    .field{width:100%; background:#0b1220; border:1px solid rgba(255,255,255,.14); color:var(--text); border-radius:10px; padding:8px 10px; outline:none; font-size:12px}
    .field:focus{border-color:rgba(96,165,250,.6)}
    .select{appearance:none}
    .grid2{display:grid;grid-template-columns: 180px 1fr; gap:10px; align-items:start}
    .gridFiles{display:grid;grid-template-columns: 1fr 1fr; gap:10px}
    .stack{display:flex;flex-direction:column;gap:8px}
    .actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .btn2{border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.06); color:var(--text); padding:8px 10px;border-radius:10px;font-size:12px; cursor:pointer}
    .btn2:hover{background:rgba(255,255,255,.08)}
    .btn2.primary{background:rgba(59,130,246,.20); border-color:rgba(59,130,246,.40)}
    .btn2.ok{background:rgba(34,197,94,.18); border-color:rgba(34,197,94,.35)}
    .muted{color:var(--muted)}
    .mini{font-size:11px}
    .photo-links{display:flex;flex-direction:column;gap:4px}
  </style>',
]);
?>

  <div class="wrap">
    <div class="top">
      <div class="title">
        <h1>FIN – PENAGIHAN & PEMBAYARAN</h1>
        <div class="sub">FIN menerima DO status WAIT PAYMENT, menyimpan bukti pelunasan, lalu menutup DO menjadi PAID / CLOSED.</div>
        <div class="flow">
          <?php echo pill('CRM', false); ?>
          <?php echo pill('WQS', false); ?>
          <?php echo pill('SCM', false); ?>
          <?php echo pill('ACT', false); ?>
          <?php echo pill('FIN (Now)', true); ?>
        </div>
      </div>
      <div class="stack">
        <a class="btn" href="sales_dashboard.php">« Kembali ke Modul Penjualan</a>
        <a class="btn" href="act_do_tasks.php">ACT Tasks</a>
        <a class="btn" href="tax_invoices.php">Tax Invoice Workflow</a>
        <a class="btn" href="fin_ar_recap.php">Rekap Piutang</a>
        <a class="btn" href="fin_ar_import.php">Import Piutang Lama</a>
      </div>
    </div>

    <?php if ($success): ?><div class="alert ok"><?php echo h($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert bad"><?php echo h($error); ?></div><?php endif; ?>

    <div class="card">
      <div class="muted mini">
        List DO untuk FIN. Alur tetap: <b>WAIT PAYMENT</b> → upload bukti pelunasan → <b>PAID / CLOSED</b>.
        KPI FIN dihitung dalam <b>hari kalender</b> mulai setelah <b>Tukar Faktur ACT selesai/upload</b> sampai tanggal pembayaran, lalu nilainya dibekukan. WAIT PAYMENT saja tidak memulai SLA FIN.
      </div>
    </div>

    <div class="card" style="padding:12px 14px">
      <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div>
          <div class="mini muted" style="margin-bottom:4px">Cari (kode/customer)</div>
          <input class="field" style="width:200px" name="f_q" placeholder="DO code, customer…" value="<?= h($_f_q) ?>">
        </div>
        <div>
          <div class="mini muted" style="margin-bottom:4px">Status</div>
          <select class="field select" style="width:150px" name="f_status">
            <option value="">Semua</option>
            <option value="wait_payment" <?= $_f_status==='wait_payment' ?'selected':'' ?>>WAIT PAYMENT</option>
            <option value="paid"         <?= $_f_status==='paid'         ?'selected':'' ?>>PAID / CLOSED</option>
          </select>
        </div>
        <div>
          <div class="mini muted" style="margin-bottom:4px"><?= $_f_status==='paid' ? 'Tgl Bayar dari' : 'Tgl DO dari' ?></div>
          <input class="field" style="width:140px" type="date" name="f_date_fr" value="<?= h($_f_date_fr) ?>">
        </div>
        <div>
          <div class="mini muted" style="margin-bottom:4px"><?= $_f_status==='paid' ? 'Tgl Bayar s/d' : 'Tgl DO s/d' ?></div>
          <input class="field" style="width:140px" type="date" name="f_date_to" value="<?= h($_f_date_to) ?>">
        </div>
        <div style="display:flex;gap:8px;padding-bottom:1px">
          <button class="btn2 primary" type="submit">Filter</button>
          <a class="btn2" href="?">Reset</a>
        </div>
        <div class="mini muted" style="padding-bottom:4px">Menampilkan <?= count($rows) ?> dari <?= (int)$_total_rows ?> DO · Halaman <?= (int)$_page ?>/<?= (int)$_total_pages ?></div>
        <div class="mini muted" style="flex-basis:100%;padding-top:2px">Filter tanggal: <?= $_f_status==='paid' ? 'PAID / CLOSED memakai Tgl bayar (fin_paid_date) saja; data tanpa Tgl bayar tidak ikut filter tanggal' : 'status aktif memakai tanggal DO' ?>.</div>
      </form>
    </div>

    <?php if ($_total_pages > 1): ?>
    <div class="card" style="padding:10px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px">
      <div class="mini muted">100 DO per halaman agar FIN tetap ringan.</div>
      <div class="actions">
        <?php
          $_qs = $_GET;
          if ($_page > 1) { $_qs['page'] = $_page - 1; echo '<a class="btn2" href="?'.h(http_build_query($_qs)).'">← Sebelumnya</a>'; }
          echo '<span class="tag">Halaman '.(int)$_page.' / '.(int)$_total_pages.'</span>';
          if ($_page < $_total_pages) { $_qs['page'] = $_page + 1; echo '<a class="btn2" href="?'.h(http_build_query($_qs)).'">Berikutnya →</a>'; }
        ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:170px">DO Code</th>
            <th style="width:110px">Tanggal</th>
            <th style="width:120px">Customer</th>
            <th style="width:90px">Office</th>
            <th style="width:110px">Grand Total</th>
            <th style="width:140px">FIN</th>
            <th style="width:260px">Pembayaran</th>
            <th style="width:460px">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="muted">Belum ada DO untuk FIN.</td></tr>
          <?php endif; ?>

          <?php foreach ($rows as $r):
            $rowStatus = strtolower(trim((string)($r['status'] ?? '')));
            $is_locked = in_array($rowStatus, ['paid','fin_done','closed'], true);
            $has_exchange_act = fin_exchange_evidence_complete(
                (string)($r['act_exchange_doc_file'] ?? ''),
                (string)($r['act_exchange_doc_at'] ?? '')
            );
            // ACT save_exchange resmi menandai Done/completed setelah file sukses tersimpan.
            // Gunakan ini hanya sebagai compatibility fallback bila canonical/store lama tidak terbaca FIN.
            if (!$has_exchange_act) {
                $actDoneCompat = strtolower(trim((string)($r['act_status'] ?? ''))) === 'done'
                    || strtolower(trim((string)($r['status_act'] ?? ''))) === 'completed';
                $actUpdCompat = trim((string)($r['act_updated_at'] ?? ''));
                if ($actDoneCompat && $actUpdCompat !== '' && strtotime($actUpdCompat) !== false) {
                    $has_exchange_act = true;
                }
            }
            $needs_tax_issued = false;
            if (!$is_locked && $rowStatus === 'wait_payment' && tax_invoice_required($pdo)) {
              $hasIssued = false;
              try {
                if (class_exists(\App\Accounting\TaxInvoiceService::class)) {
                  $hasIssued = (new \App\Accounting\TaxInvoiceService())->isIssuedForRef($pdo, (string)($r['do_code'] ?? ''));
                }
              } catch (Throwable $e) {}
              $needs_tax_issued = !$hasIssued;
            }
          ?>
            <tr>
              <td>
                <div><b><?php echo h($r['do_code']); ?></b></div>
                <div class="mini muted">Track: <?php echo h($r['tracking_code'] ?? $r['do_code']); ?></div>
                <div class="mini"><a href="sales_do_view.php?id=<?php echo (int)$r['id']; ?>" target="_blank">Detail / Print</a></div>
              </td>
              <td><?php echo h($r['do_date']); ?></td>
              <td>
                <div><b><?php echo h($r['customers_name'] ?: $r['customers_code']); ?></b></div>
                <div class="mini muted"><?php echo h($r['customers_code']); ?></div>
              </td>
              <td><?php echo h($r['office_code']); ?></td>
              <td><?php echo h(number_format((float)($r['grand_total'] ?? 0), 0, ',', '.')); ?></td>
              <td>
                <div class="actions" style="gap:6px">
                  <?php echo badgeStatus($r['status']); ?>
                  <span class="tag"><?php echo h($r['fin_status'] ?: 'Pending'); ?></span>
                </div>
                <div class="mini muted" style="margin-top:6px">Catatan: <?php echo h($r['fin_note'] ?? ''); ?></div>
                <?php
                  $finStartUi = fin_resolve_sla_start(
                      $pdo,
                      (int)$r['id'],
                      (string)($r['do_code'] ?? ''),
                      (string)($r['act_exchange_doc_at'] ?? ''),
                      (string)($r['act_exchange_doc_file'] ?? ''),
                      (string)($r['act_ready_fin_at'] ?? ''),
                      (string)($r['fin_sla_start_at'] ?? ''),
                      (string)($r['act_status'] ?? ''),
                      (string)($r['status_act'] ?? ''),
                      (string)($r['act_updated_at'] ?? '')
                  );
                  $finTerminal = in_array($rowStatus, ['paid','fin_done','closed'], true);
                  $finRunning = ($rowStatus === 'wait_payment' && $finStartUi !== null);
                  $finDaysUi = null;
                  if ($finTerminal && $r['fin_duration_days'] !== null) {
                      $finDaysUi = (int)$r['fin_duration_days'];
                  } elseif ($finStartUi !== null) {
                      $endUi = $finTerminal
                          ? ((string)($r['fin_paid_date'] ?? '') ?: (string)($r['fin_paid_at'] ?? ''))
                          : date('Y-m-d');
                      $finDaysUi = fin_calendar_days($finStartUi, $endUi);
                  }
                ?>
                <div class="mini" style="margin-top:8px">
                  <b>Durasi FIN:</b> <?php
                    if ($finStartUi !== null) {
                        echo h(fin_duration_label($finDaysUi, $finRunning));
                    } elseif ($has_exchange_act) {
                        echo 'Tukar Faktur ACT terkonfirmasi — timestamp upload historis tidak tersedia';
                    } else {
                        echo $finTerminal ? 'Belum tersedia — timestamp Tukar Faktur historis tidak ditemukan' : 'Belum mulai — menunggu Tukar Faktur ACT';
                    }
                  ?>
                </div>
                <div class="mini muted">
                  Mulai: <?php echo $finStartUi ? h(date('d-m-Y', strtotime($finStartUi))) : ($has_exchange_act ? 'historis / tidak diketahui' : '-'); ?>
                  <?php if ($finTerminal): ?>
                    · Selesai: <?php echo h($r['fin_paid_date'] ?: (($r['fin_paid_at'] ?? '') ? date('d-m-Y', strtotime($r['fin_paid_at'])) : '-')); ?>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="photo-links">
                  <div>Bukti bayar: <?php echo $r['fin_payment_file'] ? '<a href="'.h($r['fin_payment_file']).'" target="_blank">lihat</a>' : '<span class="muted">-</span>'; ?></div>
                  <div>Tgl bayar: <?php echo $r['fin_paid_date'] ? h($r['fin_paid_date']) : '<span class="muted">-</span>'; ?></div>
                </div>
              </td>
              <td>
                <form method="post" enctype="multipart/form-data" class="stack">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">

                  <div class="grid2">
                    <select class="field select" name="fin_status" <?php echo $is_locked ? "disabled" : ""; ?>>
                      <?php $cur = $r['fin_status'] ?: 'Pending'; ?>
                      <option value="Pending" <?php echo $cur==='Pending'?'selected':''; ?>>Pending</option>
                      <option value="Open" <?php echo $cur==='Open'?'selected':''; ?>>Open</option>
                      <option value="Done" <?php echo $cur==='Done'?'selected':''; ?>>Done</option>
                    </select>
                    <input class="field" name="fin_note" placeholder="Catatan FIN..." value="<?php echo h($r['fin_note'] ?? ''); ?>" <?php echo $is_locked ? "disabled" : ""; ?>>
                  </div>

                  <div class="gridFiles">
                    <div>
                      <div class="mini muted" style="margin:0 0 6px">Upload Bukti Pembayaran (pdf/jpg/png)</div>
                      <input class="field" type="file" name="fin_payment_file" <?php echo $is_locked ? "disabled" : ""; ?> accept=".pdf,.jpg,.jpeg,.png,.webp">
                    </div>
                    <div>
                      <div class="mini muted" style="margin:0 0 6px">Tanggal pembayaran (opsional)</div>
                      <input class="field" type="date" name="fin_paid_date" <?php echo $is_locked ? "disabled" : ""; ?> value="<?php echo h($r['fin_paid_date'] ?? ''); ?>">
                    </div>
                  </div>

                  <?php if ($needs_tax_issued): ?>
                    <div class="alert bad" style="margin:8px 0; padding:8px 10px; font-size:11px">
                      ⚠️ Tax Invoice status <b>ISSUED</b> belum tersedia untuk DO ini. Buat & set ISSUED di <a href="tax_invoices.php" target="_blank" rel="noopener">tax_invoices.php</a> sebelum Set PAID.
                    </div>
                  <?php endif; ?>
                  <div class="actions">
                    <?php if ($is_locked): ?>
                      <span class="tag" style="opacity:.9">🔒 Terkunci (PAID / CLOSED)</span>
                    <?php else: ?>
                      <button class="btn2 primary" name="action" value="save" type="submit">Simpan</button>
                      <?php if ($rowStatus === 'revision_fin_review'): ?>
                        <button class="btn2 ok" name="action" value="approve_revision" type="submit" onclick="return confirm('Setujui revisi nilai dan kembalikan DO ke WQS?')">Approve Revisi Nilai</button>
                      <?php endif; ?>
                      <?php if ($rowStatus === 'wait_payment'): ?>
                        <button class="btn2 ok" name="action" value="paid" type="submit" <?php echo $needs_tax_issued ? 'disabled title="Tax Invoice ISSUED wajib dulu"' : ''; ?>>Set PAID / CLOSED</button>
                      <?php endif; ?>
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
      <div class="mini muted">Upload tersimpan ke: <code>/uploads/sales_fin/</code>. Bukti pembayaran menjadi dasar penyelesaian. Status <b>PAID / CLOSED</b> mengunci transaksi FIN.</div>
    </div>

    <div class="card" style="margin-top:12px">
      <div class="fw-semibold mb-2">Audit Log <span class="muted">(Last 50 events)</span></div>
      <?php if (empty($audit_rows)): ?>
        <div class="muted mini">Belum ada audit log.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th style="width:180px">Time</th><th style="width:140px">Action</th><th style="width:140px">Code</th><th style="width:120px">User</th><th>Description</th></tr></thead>
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
<?php rmi_footer(); ?>
