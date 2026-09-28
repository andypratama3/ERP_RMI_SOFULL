<?php
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.EDIT', 'SALES.VIEW']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','ACT']);
}

// PATCH_3_AUDIT
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/_do_office_scope.php';
require_once __DIR__ . '/_do_task_helpers.php';
require_once __DIR__ . '/_ar_helper.php';
// sales/act_do_tasks.php
// ACT - Task DO setelah DELIVERED (upload faktur pajak + set jatuh tempo & nominal, kirim FIN; bukti tukar faktur bisa menyusul)
// ERP_RMI_SOFULL flow: CRM -> WQS -> SCM -> ACT -> FIN
// Catatan: auth/role parkir dulu (sementara), fokus UI/UX & flow.
// --- DB (centralized) ---
$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();

// ---------------- helpers ----------------
if (!function_exists('h')) {
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Cache metadata sales_do per request. V11 melakukan SHOW COLUMNS puluhan kali
 * setiap halaman dibuka; ini membuat loading berat terutama di NAS/MySQL remote.
 */
function table_columns(PDO $pdo, string $table, bool $refresh=false): array {
    static $cache = [];
    if (!$refresh && isset($cache[$table])) return $cache[$table];
    $cols = [];
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`','',$table) . "`");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $f = (string)($r['Field'] ?? '');
            if ($f !== '') $cols[$f] = true;
        }
    } catch (Throwable $e) { /* fail-soft */ }
    return $cache[$table] = $cols;
}

function ensure_column(PDO $pdo, string $table, string $column, string $ddl): void {
    $cols = table_columns($pdo, $table);
    if (isset($cols[$column])) return;
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        table_columns($pdo, $table, true);
    } catch (Throwable $e) { /* fail-soft */ }
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
    $cols = table_columns($pdo, $table);
    return isset($cols[$column]);
}

function select_col(bool $hasCol, string $col): string {
    return $hasCol ? "`{$col}`" : "NULL AS `{$col}`";
}

function select_col_d(bool $hasCol, string $col): string {
    return $hasCol ? "d.`{$col}`" : "NULL AS `{$col}`";
}

function upload_file(string $field, string $dirRel, array $allowExt): ?string {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $f = $_FILES[$field];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return null;
    if ($err !== UPLOAD_ERR_OK) {
        $msg = [
            UPLOAD_ERR_INI_SIZE => 'ukuran file melebihi upload_max_filesize server',
            UPLOAD_ERR_FORM_SIZE => 'ukuran file melebihi batas form',
            UPLOAD_ERR_PARTIAL => 'file hanya ter-upload sebagian',
            UPLOAD_ERR_NO_TMP_DIR => 'folder temporary upload server tidak tersedia',
            UPLOAD_ERR_CANT_WRITE => 'server gagal menulis file ke disk',
            UPLOAD_ERR_EXTENSION => 'upload dihentikan extension PHP',
        ][$err] ?? ('kode error upload '.$err);
        throw new RuntimeException('Upload '.($field==='act_tax_invoice_file'?'Faktur Pajak':'Tukar Faktur').' gagal: '.$msg.'.');
    }

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Upload gagal: temporary file tidak valid.');
    }

    $name = (string)($f['name'] ?? 'file');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowExt, true)) {
        throw new RuntimeException('Format file tidak diizinkan. Hanya pdf/jpg/jpeg/png.');
    }

    $base = realpath(__DIR__ . '/..'); // project root
    if ($base === false) throw new RuntimeException('Project root tidak ditemukan.');

    $dirAbs = $base . '/' . trim($dirRel, '/');
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0777, true) && !is_dir($dirAbs)) {
        throw new RuntimeException('Folder upload tidak tersedia: '.$dirRel);
    }
    if (!is_writable($dirAbs)) {
        throw new RuntimeException('Folder upload tidak writable: '.$dirRel);
    }

    $clean = safe_filename($name);
    $basePart = pathinfo($clean, PATHINFO_FILENAME);
    $ext2 = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
    if ($basePart === '') $basePart = 'file';
    if ($ext2 === '') $ext2 = $ext;
    $fname = $basePart . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext2;

    $destAbs = $dirAbs . '/' . $fname;
    if (!@move_uploaded_file($tmp, $destAbs)) {
        throw new RuntimeException('Server gagal memindahkan file upload ke '.$dirRel.'.');
    }

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

/**
 * ACT SLA source:
 * - KPI Faktur Pajak  : SCM DELIVERED -> upload Faktur Pajak pertama.
 * - KPI Tukar Faktur  : SCM DELIVERED -> upload Bukti Tukar Faktur pertama.
 *
 * Timestamp dokumen memakai "first valid upload" dan tidak berubah saat file diganti,
 * supaya histori KPI tidak ikut berubah karena re-upload.
 */
function act_sla_start_at(PDO $pdo, int $doId, string $doCode, ?string $scmDeliveredAt): ?string {
    $v = trim((string)$scmDeliveredAt);
    if ($v !== '') return $v;

    // Fallback data lama: event SCM_DELIVERED dari audit.
    try {
        $st = $pdo->prepare("
            SELECT MAX(created_at)
            FROM system_audit_logs
            WHERE module='sales_do'
              AND action='SCM_DELIVERED'
              AND record_code=?
        ");
        $st->execute([$doCode]);
        $auditAt = trim((string)($st->fetchColumn() ?: ''));
        if ($auditAt !== '') return $auditAt;
    } catch (Throwable $e) {
        // fail-soft
    }

    // Fallback terakhir by do_id pada audit ringan.
    try {
        $st = $pdo->prepare("
            SELECT MAX(created_at)
            FROM sales_do_audit
            WHERE do_id=? AND LOWER(COALESCE(status_to,''))='delivered'
        ");
        $st->execute([$doId]);
        $auditAt = trim((string)($st->fetchColumn() ?: ''));
        if ($auditAt !== '') return $auditAt;
    } catch (Throwable $e) {
        // fail-soft
    }

    return null;
}

function act_sla_seconds(?string $startAt, ?string $endAt = null): ?int {
    $startAt = trim((string)$startAt);
    if ($startAt === '') return null;
    try {
        $start = new DateTimeImmutable($startAt);
        $end = trim((string)$endAt) !== '' ? new DateTimeImmutable((string)$endAt) : new DateTimeImmutable('now');
        return max(0, $end->getTimestamp() - $start->getTimestamp());
    } catch (Throwable $e) {
        return null;
    }
}

function act_duration_text(?int $seconds): string {
    if ($seconds === null) return '-';
    $seconds = max(0, $seconds);
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $secs = $seconds % 60;

    $parts = [];
    if ($days > 0) $parts[] = $days . ' hari';
    if ($hours > 0 || $days > 0) $parts[] = $hours . ' jam';
    if ($minutes > 0 || $hours > 0 || $days > 0) $parts[] = $minutes . ' menit';
    $parts[] = $secs . ' detik';
    return implode(' ', $parts);
}

function act_exchange_duration_text(?int $seconds): string {
    if ($seconds === null) return '-';
    $seconds = max(0, $seconds);
    $days = intdiv($seconds, 86400);
    // Tukar Faktur dinilai per hari; jam/menit/detik tidak ditampilkan.
    return $days . ' hari';
}


/**
 * Recovery timestamp upload historis, READ-ONLY.
 * Prioritas: timestamp yang tertanam pada nama file hasil upload_file(), lalu filemtime.
 * Tidak pernah menulis balik DB dan tidak mengubah file.
 */
function act_historical_file_timestamp(?string $webPath, ?string $startAt = null): ?string {
    $webPath = trim((string)$webPath);
    if ($webPath === '') return null;

    $candidate = null;
    $baseName = basename(parse_url($webPath, PHP_URL_PATH) ?: $webPath);
    if (preg_match('/_(\d{8})_(\d{6})_[A-Za-z0-9]+\.[A-Za-z0-9]+$/', $baseName, $m)) {
        $dt = DateTimeImmutable::createFromFormat('!Ymd His', $m[1] . ' ' . $m[2]);
        if ($dt instanceof DateTimeImmutable) $candidate = $dt->format('Y-m-d H:i:s');
    }

    if ($candidate === null) {
        $root = realpath(__DIR__ . '/..');
        if ($root !== false) {
            $urlPath = parse_url($webPath, PHP_URL_PATH) ?: $webPath;
            $abs = $root . '/' . ltrim((string)$urlPath, '/');
            if (is_file($abs)) {
                $mtime = @filemtime($abs);
                if (is_int($mtime) && $mtime > 0) $candidate = date('Y-m-d H:i:s', $mtime);
            }
        }
    }

    if ($candidate === null) return null;
    try {
        $cand = new DateTimeImmutable($candidate);
        if (trim((string)$startAt) !== '') {
            $start = new DateTimeImmutable((string)$startAt);
            if ($cand < $start) return null;
        }
        if ($cand > (new DateTimeImmutable('now'))->modify('+5 minutes')) return null;
    } catch (Throwable $e) { return null; }
    return $candidate;
}

/** Ambil fallback AR satu DO tanpa JOIN ke query daftar utama. */
function act_ar_fallback_one(PDO $pdo, string $doCode): array {
    $out = ['source_tax_file' => '', 'due_date' => '', 'total_amount' => null];
    $doCode = trim($doCode);
    if ($doCode === '') return $out;
    try {
        $st = $pdo->prepare("SELECT source_tax_file, due_date, total_amount
                               FROM ar_invoices
                              WHERE CONVERT(do_code USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
                              ORDER BY id DESC LIMIT 1");
        $st->execute([$doCode]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if ($a) {
            $out['source_tax_file'] = trim((string)($a['source_tax_file'] ?? ''));
            $out['due_date'] = trim((string)($a['due_date'] ?? ''));
            $out['total_amount'] = ($a['total_amount'] ?? null);
        }
    } catch (Throwable $e) { /* fail-soft: canonical sales_do tetap dipakai */ }
    return $out;
}


/**
 * Source-of-truth dokumen ACT ter-normalisasi. READ-ONLY saat halaman dibuka.
 * IMPORTANT: KPI memakai FIRST VALID UPLOAD. Karena itu bila data historis memiliki
 * lebih dari satu row/re-upload, row PALING AWAL yang dipakai untuk timestamp SLA,
 * bukan row terbaru. File canonical sales_do tetap prioritas pada tampilan.
 */
function act_doc_store_one(PDO $pdo, int $doId): array {
    $out = [
        'tax_file'=>'', 'tax_at'=>'', 'tax_by'=>'',
        'exchange_file'=>'', 'exchange_at'=>'', 'exchange_duration'=>null, 'exchange_by'=>''
    ];
    if ($doId <= 0) return $out;
    try {
        $st = $pdo->prepare("SELECT file_path, created_at, uploaded_by, updated_at
                               FROM sales_do_act_tax_docs
                              WHERE do_id=?
                              ORDER BY created_at ASC, id ASC LIMIT 1");
        $st->execute([$doId]);
        $x = $st->fetch(PDO::FETCH_ASSOC);
        if ($x) {
            $out['tax_file'] = trim((string)($x['file_path'] ?? ''));
            $out['tax_at'] = trim((string)($x['created_at'] ?? ''));
            $out['tax_by'] = trim((string)($x['uploaded_by'] ?? ''));
        }
    } catch (Throwable $e) { /* fail-soft */ }
    try {
        $st = $pdo->prepare("SELECT file_path, uploaded_at, duration_sec, uploaded_by, updated_at
                               FROM sales_do_act_exchange_docs
                              WHERE do_id=?
                              ORDER BY uploaded_at ASC, id ASC LIMIT 1");
        $st->execute([$doId]);
        $x = $st->fetch(PDO::FETCH_ASSOC);
        if ($x) {
            $out['exchange_file'] = trim((string)($x['file_path'] ?? ''));
            $out['exchange_at'] = trim((string)($x['uploaded_at'] ?? ''));
            $out['exchange_duration'] = $x['duration_sec'] ?? null;
            $out['exchange_by'] = trim((string)($x['uploaded_by'] ?? ''));
        }
    } catch (Throwable $e) { /* fail-soft */ }
    return $out;
}

/** Simpan ke store dokumen baru hanya pada aksi upload user; tidak pernah saat GET. */
function act_doc_store_save(PDO $pdo, string $type, int $doId, string $filePath, string $uploadedAt, ?int $durationSec, string $actor): void {
    if ($doId <= 0 || trim($filePath)==='') return;
    try {
        if ($type === 'tax') {
            $u = $pdo->prepare("UPDATE sales_do_act_tax_docs SET file_path=?, uploaded_by=?, updated_at=? WHERE do_id=?");
            $u->execute([$filePath, $actor, $uploadedAt, $doId]);
            if ($u->rowCount() === 0) {
                $i = $pdo->prepare("INSERT INTO sales_do_act_tax_docs (do_id,file_path,uploaded_by,created_at,updated_at) VALUES (?,?,?,?,?)");
                $i->execute([$doId,$filePath,$actor,$uploadedAt,$uploadedAt]);
            }
        } elseif ($type === 'exchange') {
            $u = $pdo->prepare("UPDATE sales_do_act_exchange_docs SET file_path=?, uploaded_at=?, duration_sec=?, uploaded_by=?, updated_at=? WHERE do_id=?");
            $u->execute([$filePath,$uploadedAt,$durationSec,$actor,$uploadedAt,$doId]);
            if ($u->rowCount() === 0) {
                $i = $pdo->prepare("INSERT INTO sales_do_act_exchange_docs (do_id,file_path,uploaded_at,duration_sec,uploaded_by,updated_at) VALUES (?,?,?,?,?,?)");
                $i->execute([$doId,$filePath,$uploadedAt,$durationSec,$actor,$uploadedAt]);
            }
        }
    } catch (Throwable $e) { /* canonical sales_do tetap menjadi fallback */ }
}

/**
 * Recovery legacy Tukar Faktur (Mei-Agustus) tanpa menebak DO dari nama file.
 * DB audit memberi do_id + waktu upload; file upload_file() membawa timestamp yang sama pada nama file.
 * Path hanya dipakai jika pencocokan timestamp menghasilkan tepat SATU file.
 */
function act_legacy_exchange_from_audit(PDO $pdo, int $doId): array {
    static $memo=[];
    static $fileIndex=null;
    if ($doId <= 0) return ['file'=>'','at'=>'','duration'=>null,'source'=>''];
    if (isset($memo[$doId])) return $memo[$doId];
    $out=['file'=>'','at'=>'','duration'=>null,'source'=>''];

    try {
        $st=$pdo->prepare("SELECT created_at FROM system_audit_logs
                            WHERE record_id=? AND action='ACT_SAVE_EXCHANGE_AFTER_FIN'
                            ORDER BY created_at ASC");
        $st->execute([$doId]);
        $times=$st->fetchAll(PDO::FETCH_COLUMN);
        if (!$times) return $memo[$doId]=$out;

        if ($fileIndex===null) {
            $fileIndex=[];
            $root=realpath(__DIR__.'/..');
            $dir=$root ? $root.'/uploads/sales_act' : '';
            if ($dir && is_dir($dir)) {
                foreach (new DirectoryIterator($dir) as $fi) {
                    if (!$fi->isFile()) continue;
                    $name=$fi->getFilename();
                    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
                    if (!in_array($ext,['pdf','jpg','jpeg','png'],true)) continue;
                    // Standard uploader: original_YYYYMMDD_HHMMSS_random.ext
                    if (preg_match('/_(20\\d{6})_(\\d{6})_[A-Za-z0-9]+\\.[A-Za-z0-9]+$/',$name,$m)) {
                        $key=$m[1].'_'.$m[2];
                        $fileIndex[$key][]='/uploads/sales_act/'.$name;
                    }
                }
            }
        }

        foreach ($times as $ts) {
            try { $dt=new DateTimeImmutable((string)$ts); } catch(Throwable $e){ continue; }
            // exact second first; then +/-2 sec only if exactly one candidate overall
            $cands=[];
            foreach ([0,-1,1,-2,2] as $off) {
                $d=$off===0 ? $dt : $dt->modify(($off>0?'+':'').$off.' seconds');
                $key=$d->format('Ymd_His');
                foreach (($fileIndex[$key]??[]) as $p) $cands[$p]=$d->format('Y-m-d H:i:s');
                if ($off===0 && count($cands)===1) break;
            }
            if (count($cands)===1) {
                $path=array_key_first($cands);
                $out['file']=$path;
                $out['at']=(string)$ts; // SLA selesai mengikuti event upload DB, bukan mtime
                $out['source']='audit+filename_timestamp';
                return $memo[$doId]=$out;
            }
        }
    } catch(Throwable $e) { /* fail-soft */ }
    return $memo[$doId]=$out;
}


/**
 * V13 PERFORMANCE: recovery list dilakukan secara batch, bukan query per DO.
 * POST tetap boleh memakai helper one-by-one karena hanya memproses satu DO.
 */
function act_chunks(array $values, int $size = 400): array {
    $values = array_values(array_unique(array_filter($values, static fn($v) => $v !== '' && $v !== null)));
    return array_chunk($values, $size);
}

function act_batch_ar_fallback(PDO $pdo, array $doCodes): array {
    $out = [];
    foreach (act_chunks($doCodes, 300) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $st = $pdo->prepare("
                SELECT id, do_code, source_tax_file, due_date, total_amount
                FROM ar_invoices
                WHERE CONVERT(do_code USING utf8mb4) COLLATE utf8mb4_unicode_ci
                      IN (" . implode(',', array_fill(0, count($chunk), "CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci")) . ")
                ORDER BY id DESC
            ");
            $st->execute($chunk);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $code = trim((string)($r['do_code'] ?? ''));
                if ($code === '' || isset($out[$code])) continue; // id DESC = record terbaru
                $out[$code] = [
                    'source_tax_file' => trim((string)($r['source_tax_file'] ?? '')),
                    'due_date' => trim((string)($r['due_date'] ?? '')),
                    'total_amount' => $r['total_amount'] ?? null,
                ];
            }
        } catch (Throwable $e) { /* fail-soft */ }
    }
    return $out;
}

function act_batch_doc_store(PDO $pdo, array $doIds): array {
    $out = [];
    foreach ($doIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $out[$id] = [
                'tax_file'=>'', 'tax_at'=>'', 'tax_by'=>'',
                'exchange_file'=>'', 'exchange_at'=>'', 'exchange_duration'=>null, 'exchange_by'=>''
            ];
        }
    }
    foreach (act_chunks(array_map('intval', $doIds), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $st = $pdo->prepare("
                SELECT do_id, file_path, created_at, uploaded_by, updated_at
                FROM sales_do_act_tax_docs
                WHERE do_id IN ($ph)
                ORDER BY do_id, created_at ASC, id ASC
            ");
            $st->execute($chunk);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)($r['do_id'] ?? 0);
                if ($id <= 0 || !isset($out[$id]) || $out[$id]['tax_file'] !== '') continue;
                $out[$id]['tax_file'] = trim((string)($r['file_path'] ?? ''));
                $out[$id]['tax_at'] = trim((string)($r['created_at'] ?? ''));
                $out[$id]['tax_by'] = trim((string)($r['uploaded_by'] ?? ''));
            }
        } catch (Throwable $e) { /* fail-soft */ }

        try {
            $st = $pdo->prepare("
                SELECT do_id, file_path, uploaded_at, duration_sec, uploaded_by, updated_at
                FROM sales_do_act_exchange_docs
                WHERE do_id IN ($ph)
                ORDER BY do_id, uploaded_at ASC, id ASC
            ");
            $st->execute($chunk);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)($r['do_id'] ?? 0);
                if ($id <= 0 || !isset($out[$id]) || $out[$id]['exchange_file'] !== '') continue;
                $out[$id]['exchange_file'] = trim((string)($r['file_path'] ?? ''));
                $out[$id]['exchange_at'] = trim((string)($r['uploaded_at'] ?? ''));
                $out[$id]['exchange_duration'] = $r['duration_sec'] ?? null;
                $out[$id]['exchange_by'] = trim((string)($r['uploaded_by'] ?? ''));
            }
        } catch (Throwable $e) { /* fail-soft */ }
    }
    return $out;
}

function act_batch_sla_start(PDO $pdo, array $rows): array {
    $out = [];
    $needIds = [];
    $needCodes = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) continue;
        $v = trim((string)($r['scm_delivered_at'] ?? ''));
        if ($v !== '') {
            $out[$id] = $v;
        } else {
            $needIds[] = $id;
            $code = trim((string)($r['do_code'] ?? ''));
            if ($code !== '') $needCodes[] = $code;
        }
    }
    // Audit utama berdasarkan record_code.
    foreach (act_chunks($needCodes, 300) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $st = $pdo->prepare("
                SELECT record_code, MAX(created_at) AS delivered_at
                FROM system_audit_logs
                WHERE module='sales_do' AND action='SCM_DELIVERED'
                  AND record_code IN ($ph)
                GROUP BY record_code
            ");
            $st->execute($chunk);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $code = trim((string)($r['record_code'] ?? ''));
                $at = trim((string)($r['delivered_at'] ?? ''));
                if ($code !== '' && $at !== '') $byCode[$code] = $at;
            }
        } catch (Throwable $e) { /* fail-soft */ }
    }
    $byCode = $byCode ?? [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0 || isset($out[$id])) continue;
        $code = trim((string)($r['do_code'] ?? ''));
        if ($code !== '' && isset($byCode[$code])) $out[$id] = $byCode[$code];
    }
    // Fallback audit ringan hanya untuk yang masih belum punya start.
    $still = [];
    foreach ($needIds as $id) if (!isset($out[$id])) $still[] = $id;
    foreach (act_chunks($still, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $st = $pdo->prepare("
                SELECT do_id, MAX(created_at) AS delivered_at
                FROM sales_do_audit
                WHERE do_id IN ($ph)
                  AND LOWER(COALESCE(status_to,''))='delivered'
                GROUP BY do_id
            ");
            $st->execute($chunk);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)($r['do_id'] ?? 0);
                $at = trim((string)($r['delivered_at'] ?? ''));
                if ($id > 0 && $at !== '') $out[$id] = $at;
            }
        } catch (Throwable $e) { /* fail-soft */ }
    }
    return $out;
}

/**
 * Batch legacy Tukar Faktur:
 * - 1 query audit untuk seluruh DO yang perlu recovery.
 * - scan folder maksimal 1x dan memakai cache file temp 5 menit.
 * - mapping hanya jika timestamp menghasilkan satu file unik.
 */
function act_batch_legacy_exchange(PDO $pdo, array $doIds): array {
    $out = [];
    $timesById = [];
    foreach (act_chunks(array_map('intval', $doIds), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $st = $pdo->prepare("
                SELECT record_id, created_at
                FROM system_audit_logs
                WHERE record_id IN ($ph)
                  AND action='ACT_SAVE_EXCHANGE_AFTER_FIN'
                ORDER BY record_id, created_at ASC
            ");
            $st->execute($chunk);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)($r['record_id'] ?? 0);
                $ts = trim((string)($r['created_at'] ?? ''));
                if ($id > 0 && $ts !== '') $timesById[$id][] = $ts;
            }
        } catch (Throwable $e) { /* fail-soft */ }
    }
    if (!$timesById) return $out;

    $neededKeys = [];
    foreach ($timesById as $list) {
        foreach ($list as $ts) {
            try { $dt = new DateTimeImmutable($ts); } catch (Throwable $e) { continue; }
            foreach ([0,-1,1,-2,2] as $off) {
                $d = $off === 0 ? $dt : $dt->modify(($off > 0 ? '+' : '') . $off . ' seconds');
                $neededKeys[$d->format('Ymd_His')] = true;
            }
        }
    }

    $root = realpath(__DIR__ . '/..');
    $dir = $root ? $root . '/uploads/sales_act' : '';
    if ($dir === '' || !is_dir($dir)) return $out;

    $cacheFile = sys_get_temp_dir() . '/rmi_act_exchange_file_index_v13.json';
    $index = null;
    $dirMtime = @filemtime($dir) ?: 0;
    if (is_file($cacheFile)) {
        $cacheMtime = @filemtime($cacheFile) ?: 0;
        if ($cacheMtime >= $dirMtime && (time() - $cacheMtime) <= 300) {
            $json = @file_get_contents($cacheFile);
            $tmp = $json !== false ? json_decode($json, true) : null;
            if (is_array($tmp)) $index = $tmp;
        }
    }

    if (!is_array($index)) {
        $index = [];
        try {
            foreach (new DirectoryIterator($dir) as $fi) {
                if (!$fi->isFile()) continue;
                $name = $fi->getFilename();
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf','jpg','jpeg','png'], true)) continue;
                if (preg_match('/_(20\d{6})_(\d{6})_[A-Za-z0-9]+\.[A-Za-z0-9]+$/', $name, $m)) {
                    $key = $m[1] . '_' . $m[2];
                    $index[$key][] = '/uploads/sales_act/' . $name;
                }
            }
            @file_put_contents($cacheFile, json_encode($index, JSON_UNESCAPED_SLASHES), LOCK_EX);
        } catch (Throwable $e) { return $out; }
    }

    foreach ($timesById as $id => $list) {
        foreach ($list as $ts) {
            try { $dt = new DateTimeImmutable($ts); } catch (Throwable $e) { continue; }
            $cands = [];
            foreach ([0,-1,1,-2,2] as $off) {
                $d = $off === 0 ? $dt : $dt->modify(($off > 0 ? '+' : '') . $off . ' seconds');
                $key = $d->format('Ymd_His');
                foreach (($index[$key] ?? []) as $path) $cands[$path] = true;
                if ($off === 0 && count($cands) === 1) break;
            }
            if (count($cands) === 1) {
                $out[(int)$id] = [
                    'file' => (string)array_key_first($cands),
                    'at' => $ts,
                    'duration' => null,
                    'source' => 'audit+filename_timestamp'
                ];
                break;
            }
        }
    }
    return $out;
}

// -------------- schema guards --------------
ensure_column($pdo, 'sales_do', 'status', "status VARCHAR(50) NOT NULL DEFAULT 'crm_to_wqs'");

// ACT fields
ensure_column($pdo, 'sales_do', 'act_status', "act_status VARCHAR(20) NULL"); // Pending/Open/Done
ensure_column($pdo, 'sales_do', 'status_act', "status_act VARCHAR(20) NOT NULL DEFAULT 'pending'");
ensure_column($pdo, 'sales_do', 'act_note', "act_note TEXT NULL");

ensure_column($pdo, 'sales_do', 'act_tax_invoice_file', "act_tax_invoice_file VARCHAR(255) NULL"); // faktur pajak
ensure_column($pdo, 'sales_do', 'act_exchange_doc_file', "act_exchange_doc_file VARCHAR(255) NULL"); // bukti tukar faktur
ensure_column($pdo, 'sales_do', 'act_due_date', "act_due_date DATE NULL"); // tanggal dibayar
ensure_column($pdo, 'sales_do', 'act_amount', "act_amount DECIMAL(15,2) NULL"); // nominal

ensure_column($pdo, 'sales_do', 'act_ready_fin_at', "act_ready_fin_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'act_invoiced_at', "act_invoiced_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'act_updated_at', "act_updated_at DATETIME NULL");

// KPI SLA ACT (raw event values untuk dipakai KPI Center).
// Nilai final tidak bergantung target SLA di halaman ini; target akan dinilai oleh modul KPI.
ensure_column($pdo, 'sales_do', 'act_tax_invoice_at', "act_tax_invoice_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'act_tax_invoice_duration_sec', "act_tax_invoice_duration_sec INT UNSIGNED NULL");
ensure_column($pdo, 'sales_do', 'act_exchange_doc_at', "act_exchange_doc_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'act_exchange_duration_sec', "act_exchange_duration_sec INT UNSIGNED NULL");
ensure_column($pdo, 'sales_do', 'act_tax_invoice_by', "act_tax_invoice_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'act_exchange_doc_by', "act_exchange_doc_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'act_ready_fin_duration_sec', "act_ready_fin_duration_sec INT UNSIGNED NULL");

ensure_column($pdo, 'sales_do', 'last_updated_by', "last_updated_by VARCHAR(50) NULL");
ensure_column($pdo, 'sales_do', 'act_updated_by', "act_updated_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'act_ready_by', "act_ready_by VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'last_updated_at', "last_updated_at DATETIME NULL");

$hasActStatus          = table_has_column($pdo, 'sales_do', 'act_status');
$hasStatusAct          = table_has_column($pdo, 'sales_do', 'status_act');
$hasActNote            = table_has_column($pdo, 'sales_do', 'act_note');
$hasActTaxInvoiceFile  = table_has_column($pdo, 'sales_do', 'act_tax_invoice_file');
$hasActExchangeDocFile = table_has_column($pdo, 'sales_do', 'act_exchange_doc_file');
$hasActDueDate         = table_has_column($pdo, 'sales_do', 'act_due_date');
$hasActAmount          = table_has_column($pdo, 'sales_do', 'act_amount');
$hasActReadyFinAt      = table_has_column($pdo, 'sales_do', 'act_ready_fin_at');
$hasActInvoicedAt      = table_has_column($pdo, 'sales_do', 'act_invoiced_at');
$hasActUpdatedAt       = table_has_column($pdo, 'sales_do', 'act_updated_at');
$hasActTaxInvoiceAt    = table_has_column($pdo, 'sales_do', 'act_tax_invoice_at');
$hasActTaxDuration     = table_has_column($pdo, 'sales_do', 'act_tax_invoice_duration_sec');
$hasActExchangeAt      = table_has_column($pdo, 'sales_do', 'act_exchange_doc_at');
$hasActExchangeDuration = table_has_column($pdo, 'sales_do', 'act_exchange_duration_sec');
$hasActTaxInvoiceBy    = table_has_column($pdo, 'sales_do', 'act_tax_invoice_by');
$hasActExchangeBy      = table_has_column($pdo, 'sales_do', 'act_exchange_doc_by');
$hasActReadyFinDuration = table_has_column($pdo, 'sales_do', 'act_ready_fin_duration_sec');
$hasScmDeliveredAt     = table_has_column($pdo, 'sales_do', 'scm_delivered_at');
$hasLastUpdatedBy      = table_has_column($pdo, 'sales_do', 'last_updated_by');
$hasLastUpdatedAt      = table_has_column($pdo, 'sales_do', 'last_updated_at');
$hasActUpdatedBy      = table_has_column($pdo, 'sales_do', 'act_updated_by');
$hasActReadyBy        = table_has_column($pdo, 'sales_do', 'act_ready_by');

// V12 PERFORMANCE: metadata kolom di-cache 1x/request; alur bisnis tidak diubah.
// Backfill otomatis saat membuka halaman DINONAKTIFKAN. GET harus read-only agar histori tidak berubah.

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
$action = trim((string)($_POST['action'] ?? ''));
    $id     = (int)($_POST['id'] ?? 0);

    try {
        $stmt = $pdo->prepare("
            SELECT id, do_code, status, office_code,
                   " . select_col($hasScmDeliveredAt, 'scm_delivered_at') . ",
                   " . select_col($hasActTaxInvoiceFile, 'act_tax_invoice_file') . ",
                   " . select_col($hasActExchangeDocFile, 'act_exchange_doc_file') . ",
                   " . select_col($hasActDueDate, 'act_due_date') . ",
                   " . select_col($hasActAmount, 'act_amount') . ",
                   " . select_col($hasActTaxInvoiceAt, 'act_tax_invoice_at') . ",
                   " . select_col($hasActTaxDuration, 'act_tax_invoice_duration_sec') . ",
                   " . select_col($hasActExchangeAt, 'act_exchange_doc_at') . ",
                   " . select_col($hasActExchangeDuration, 'act_exchange_duration_sec') . ",
                   " . select_col($hasActTaxInvoiceBy, 'act_tax_invoice_by') . ",
                   " . select_col($hasActExchangeBy, 'act_exchange_doc_by') . ",
                   " . select_col($hasActReadyFinAt, 'act_ready_fin_at') . ",
                   " . select_col($hasActReadyFinDuration, 'act_ready_fin_duration_sec') . "
            FROM sales_do
            WHERE id=?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) throw new Exception("DO tidak ditemukan.");
        do_scope_assert_row($r); // Guard: BRANCH hanya boleh akses DO kantornya sendiri

        // Fallback data historis dari AR hanya bila canonical sales_do kosong.
        // Query terpisah sengaja dipakai agar query/list ACT asli TIDAK berubah.
        $arFallback = act_ar_fallback_one($pdo, (string)($r['do_code'] ?? ''));
        $r['ar_source_tax_file'] = $arFallback['source_tax_file'];
        $r['ar_due_date'] = $arFallback['due_date'];
        $r['ar_total_amount'] = $arFallback['total_amount'];

        $docStore = act_doc_store_one($pdo, $id);
        $r['store_tax_file'] = $docStore['tax_file'];
        $r['store_tax_at'] = $docStore['tax_at'];
        $r['store_exchange_file'] = $docStore['exchange_file'];
        $r['store_exchange_at'] = $docStore['exchange_at'];
        $r['store_exchange_duration'] = $docStore['exchange_duration'];
        if (trim((string)$r['store_exchange_file']) === '') {
            $legacyEx = act_legacy_exchange_from_audit($pdo, $id);
            $r['legacy_exchange_file'] = $legacyEx['file'];
            $r['legacy_exchange_at'] = $legacyEx['at'];
        } else {
            $r['legacy_exchange_file'] = '';
            $r['legacy_exchange_at'] = '';
        }

        // --- SOFT LOCK ACT ---
        $allowed_status = ['delivered'];
        $locked_status  = ['wait_payment', 'paid'];
        $curStatus = (string)($r['status'] ?? '');
        if ($curStatus === 'paid') {
            throw new Exception("DO sudah PAID. Data ACT terkunci (read-only).");
        }
        if ($curStatus === 'wait_payment' && !in_array($action, ['save_exchange','save_tax_recovery'], true)) {
            throw new Exception("DO sudah dikirim ke FIN. Pada WAIT PAYMENT ACT hanya boleh melengkapi evidence Faktur Pajak/Tukar Faktur yang memang masih kosong.");
        }
        // Guard: ACT action hanya boleh sesuai status flow
        if ($action === 'send_fin' && $curStatus !== 'delivered') {
            throw new Exception("ACT: tidak bisa kirim ke FIN. Status harus delivered. Status sekarang: {$curStatus}");
        }
        $note = trim((string)($_POST['act_note'] ?? ''));
        $act_status = (string)($_POST['act_status'] ?? 'Pending');
        if (!in_array($act_status, ['Pending','Open','Done'], true)) $act_status = 'Pending';

        $actorName = audit_actor_name();

        $due_date = trim((string)($_POST['act_due_date'] ?? ''));
        $amount_raw = trim((string)($_POST['act_amount'] ?? ''));

        // normalize amount (allow "1.000.000" or "1000000")
        $amount_norm = str_replace(['.',',',' '], ['', '.', ''], $amount_raw);
        $amount = null;
        if ($amount_norm !== '' && is_numeric($amount_norm)) $amount = (float)$amount_norm;

        // Nilai existing: canonical sales_do selalu prioritas; AR hanya fallback historis.
        $existingTax = trim((string)(($r['act_tax_invoice_file'] ?? '') ?: ($r['store_tax_file'] ?? '') ?: ($r['ar_source_tax_file'] ?? '')));
        $existingExg = trim((string)(($r['act_exchange_doc_file'] ?? '') ?: ($r['store_exchange_file'] ?? '') ?: ($r['legacy_exchange_file'] ?? '')));
        $existingDue = trim((string)(($r['act_due_date'] ?? '') ?: ($r['ar_due_date'] ?? '')));
        $existingAmount = ($r['act_amount'] !== null && $r['act_amount'] !== '')
            ? (float)$r['act_amount']
            : (($r['ar_total_amount'] ?? null) !== null ? (float)$r['ar_total_amount'] : null);

        // uploads: format final hanya pdf/jpg/jpeg/png.
        $docExt = ['pdf','jpg','jpeg','png'];
        // Setelah WAIT PAYMENT hanya Tukar Faktur yang boleh di-upload.
        // Jangan memindahkan file Faktur Pajak dari request save_exchange agar tidak membuat orphan file.
        $tax_up = in_array($action, ['save','send_fin','save_tax_recovery'], true) ? upload_file('act_tax_invoice_file', 'uploads/sales_act', $docExt) : null;
        $exg_up = in_array($action, ['save','send_fin','save_exchange'], true) ? upload_file('act_exchange_doc_file', 'uploads/sales_act', $docExt) : null;

        // Server-side lock: dokumen yang sudah ada tidak boleh tertimpa.
        if ($tax_up && $existingTax !== '') throw new Exception('Faktur Pajak sudah ada. Upload terkunci agar file lama tidak tertimpa.');
        if ($exg_up && $existingExg !== '') throw new Exception('Bukti Tukar Faktur sudah ada. Upload terkunci agar file lama tidak tertimpa.');

        $tax = $tax_up ?: $existingTax;
        $exg = $exg_up ?: $existingExg;

        // Semua ACT SLA dimulai dari event SCM DELIVERED yang sama.
        // Jangan memakai do_date agar KPI tidak mencampur waktu CRM/WQS/SCM.
        $actSlaStartAt = act_sla_start_at(
            $pdo,
            $id,
            (string)($r['do_code'] ?? ''),
            $r['scm_delivered_at'] ?? null
        );

        if ($action === 'save') {
            $sets = [];
            $params = [];
            if ($hasActNote) { $sets[] = "act_note=?"; $params[] = $note; }
            if ($hasActStatus) { $sets[] = "act_status=?"; $params[] = $act_status; }
            if ($hasStatusAct) { $sets[] = "status_act=?"; $params[] = strtolower($act_status); }
            if ($hasActUpdatedBy) { $sets[] = "act_updated_by=?"; $params[] = $actorName; }
            if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=?"; $params[] = $actorName; }
            if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }
            if ($hasActUpdatedAt) { $sets[] = "act_updated_at=NOW()"; }

            if ($hasActTaxInvoiceFile && $tax_up) { $sets[] = "act_tax_invoice_file=?"; $params[] = $tax_up; }
            if ($hasActExchangeDocFile && $exg_up) { $sets[] = "act_exchange_doc_file=?"; $params[] = $exg_up; }

            // Freeze SLA hanya pada upload pertama. Re-upload tidak mengubah timestamp/durasi KPI.
            if ($tax_up && $hasActTaxInvoiceAt && empty($r['act_tax_invoice_at'])) {
                $taxAt = date('Y-m-d H:i:s');
                $taxSec = act_sla_seconds($actSlaStartAt, $taxAt);
                $sets[] = "act_tax_invoice_at=?";
                $params[] = $taxAt;
                if ($hasActTaxInvoiceBy) { $sets[] = "act_tax_invoice_by=COALESCE(act_tax_invoice_by, ?)"; $params[] = $actorName; }
                if ($hasActTaxDuration && $taxSec !== null) {
                    $sets[] = "act_tax_invoice_duration_sec=?";
                    $params[] = $taxSec;
                }
            }
            if ($exg_up && $hasActExchangeAt && empty($r['act_exchange_doc_at'])) {
                $exgAt = date('Y-m-d H:i:s');
                $exgSec = act_sla_seconds($actSlaStartAt, $exgAt);
                $sets[] = "act_exchange_doc_at=?";
                $params[] = $exgAt;
                if ($hasActExchangeBy) { $sets[] = "act_exchange_doc_by=COALESCE(act_exchange_doc_by, ?)"; $params[] = $actorName; }
                if ($hasActExchangeDuration && $exgSec !== null) {
                    $sets[] = "act_exchange_duration_sec=?";
                    $params[] = $exgSec;
                }
            }

            if ($hasActDueDate && $due_date !== '') { $sets[] = "act_due_date=?"; $params[] = $due_date; }
            if ($hasActAmount && $amount !== null) { $sets[] = "act_amount=?"; $params[] = $amount; }

            if (!empty($sets)) {
                $params[] = $id;
                $pdo->prepare("UPDATE sales_do SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
            }

            // Sinkronkan store dokumen ter-normalisasi hanya untuk upload baru.
            if ($tax_up) {
                $taxAtStore = !empty($taxAt) ? $taxAt : date('Y-m-d H:i:s');
                $taxSecStore = act_sla_seconds($actSlaStartAt, $taxAtStore);
                act_doc_store_save($pdo, 'tax', $id, $tax_up, $taxAtStore, $taxSecStore, $actorName);
            }
            if ($exg_up) {
                $exgAtStore = !empty($exgAt) ? $exgAt : date('Y-m-d H:i:s');
                $exgSecStore = act_sla_seconds($actSlaStartAt, $exgAtStore);
                act_doc_store_save($pdo, 'exchange', $id, $exg_up, $exgAtStore, $exgSecStore, $actorName);
            }

            // PATCH_3_AUDIT
            $after = null;
            try {
                $stA = $pdo->prepare("SELECT id, status, act_status, act_note, act_tax_invoice_file, act_exchange_doc_file, act_due_date, act_amount, act_ready_fin_at, last_updated_by, last_updated_at FROM sales_do WHERE id=? LIMIT 1");
                $stA->execute([$id]);
                $after = $stA->fetch();
            } catch (Throwable $e) {
                $after = null;
            }
            rmi_audit_safe('UPDATE', 'SALES.DO', $id, $r, $after, [
                'event' => 'act_save',
            ]);
            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit($pdo, 'sales_do', 'sales_do', 'ACT_SAVE', $id, $code, "DO ACT save: {$code}", []);
            }
            sales_do_audit_append($pdo, $id, $curStatus, $curStatus, 'ACT', $note !== '' ? $note : 'ACT_SAVE');
            $success = "Tersimpan (ACT).";
        }

        if ($action === 'save_tax_recovery') {
            if ($curStatus !== 'wait_payment') {
                throw new Exception("Pelengkapan Faktur Pajak historis hanya untuk DO WAIT PAYMENT yang evidence Faktur Pajaknya masih kosong.");
            }
            if ($existingTax !== '') {
                throw new Exception("Faktur Pajak sudah ada. Upload terkunci agar file lama tidak tertimpa.");
            }
            if (!$tax_up) {
                throw new Exception("Pilih file Faktur Pajak terlebih dahulu (pdf/jpg/jpeg/png).");
            }

            $taxAt = date('Y-m-d H:i:s');
            $taxSec = act_sla_seconds($actSlaStartAt, $taxAt);
            $sets = [];
            $params = [];
            if ($hasActTaxInvoiceFile) { $sets[] = "act_tax_invoice_file=?"; $params[] = $tax_up; }
            if ($hasActTaxInvoiceAt) { $sets[] = "act_tax_invoice_at=?"; $params[] = $taxAt; }
            if ($hasActTaxInvoiceBy) { $sets[] = "act_tax_invoice_by=?"; $params[] = $actorName; }
            if ($hasActTaxDuration && $taxSec !== null) { $sets[] = "act_tax_invoice_duration_sec=?"; $params[] = $taxSec; }
            if ($hasActUpdatedBy) { $sets[] = "act_updated_by=?"; $params[] = $actorName; }
            if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=?"; $params[] = $actorName; }
            if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }
            if ($hasActUpdatedAt) { $sets[] = "act_updated_at=NOW()"; }
            $params[] = $id;
            $pdo->prepare("UPDATE sales_do SET " . implode(', ', $sets) . " WHERE id=? AND status='wait_payment'")->execute($params);

            act_doc_store_save($pdo, 'tax', $id, $tax_up, $taxAt, $taxSec, $actorName);
            sales_do_audit_append($pdo, $id, $curStatus, $curStatus, 'ACT', 'RECOVERY Faktur Pajak historis pada WAIT PAYMENT');
            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit($pdo, 'sales_do', 'sales_do', 'ACT_TAX_RECOVERY', $id, $code, "ACT melengkapi Faktur Pajak historis: {$code}", []);
            }
            $success = "Faktur Pajak tersimpan. SLA Faktur Pajak berhenti pada waktu upload ini; status DO tetap WAIT PAYMENT.";
        }

        if ($action === 'save_exchange') {
            if ($curStatus !== 'wait_payment') {
                throw new Exception("Bukti Tukar Faktur susulan hanya dapat disimpan setelah status WAIT PAYMENT.");
            }

            // FLOW FINAL setelah handoff ke FIN:
            // Faktur Pajak + jatuh tempo + nominal sudah menjadi data handoff dan TIDAK boleh diedit lagi.
            // ACT hanya boleh menambahkan Bukti Tukar Faktur yang masih kosong (+ catatan opsional).
            // WAIT PAYMENT tidak boleh memblokir upload Tukar Faktur susulan.
            // Data handoff historis tetap read-only; aksi ini hanya menambahkan evidence Tukar Faktur.
            if ($existingExg !== '') {
                throw new Exception("Bukti Tukar Faktur sudah ada. Upload terkunci agar file lama tidak tertimpa.");
            }
            if (!$exg_up) {
                throw new Exception("Pilih file Bukti Tukar Faktur terlebih dahulu (pdf/jpg/jpeg/png).");
            }

            $exgAt = date('Y-m-d H:i:s');
            $exgSec = act_sla_seconds($actSlaStartAt, $exgAt);
            $sets = [];
            $params = [];

            if ($hasActExchangeDocFile) {
                $sets[] = "act_exchange_doc_file=?";
                $params[] = $exg_up;
            }
            if ($hasActExchangeAt && empty($r['act_exchange_doc_at'])) {
                $sets[] = "act_exchange_doc_at=?";
                $params[] = $exgAt;
            }
            if ($hasActExchangeBy) {
                $sets[] = "act_exchange_doc_by=COALESCE(act_exchange_doc_by, ?)";
                $params[] = $actorName;
            }
            if ($hasActExchangeDuration && $exgSec !== null && empty($r['act_exchange_duration_sec'])) {
                $sets[] = "act_exchange_duration_sec=?";
                $params[] = $exgSec;
            }
            if ($hasActNote && $note !== '') {
                $sets[] = "act_note=?";
                $params[] = $note;
            }

            // Setelah Tukar Faktur berhasil tersimpan, pekerjaan ACT lengkap.
            if ($hasActStatus) $sets[] = "act_status='Done'";
            if ($hasStatusAct) $sets[] = "status_act='completed'";
            if ($hasActUpdatedBy) { $sets[] = "act_updated_by=?"; $params[] = $actorName; }
            if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=?"; $params[] = $actorName; }
            if ($hasLastUpdatedAt) $sets[] = "last_updated_at=NOW()";
            if ($hasActUpdatedAt) $sets[] = "act_updated_at=NOW()";

            if ($sets) {
                $params[] = $id;
                $pdo->prepare("UPDATE sales_do SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
            }

            // Source-of-truth dokumen baru: simpan juga pada store ter-normalisasi.
            act_doc_store_save($pdo, 'exchange', $id, $exg_up, $exgAt, $exgSec, $actorName);

            try {
                $stA = $pdo->prepare("SELECT id, status, act_status, act_note, act_tax_invoice_file, act_exchange_doc_file, act_due_date, act_amount, act_ready_fin_at, last_updated_by, last_updated_at FROM sales_do WHERE id=? LIMIT 1");
                $stA->execute([$id]);
                $after = $stA->fetch();
                rmi_audit_safe('UPDATE', 'SALES.DO', $id, $r, $after, [
                    'event' => 'act_save_exchange_after_fin',
                    'exchange_file' => $exg_up,
                ]);
            } catch (Throwable $e) { /* fail-soft audit */ }
            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit($pdo, 'sales_do', 'sales_do', 'ACT_SAVE_EXCHANGE_AFTER_FIN', $id, $code, "DO ACT upload Bukti Tukar Faktur susulan: {$code}", []);
            }
            sales_do_audit_append($pdo, $id, $curStatus, $curStatus, 'ACT', $note !== '' ? $note : 'ACT_EXCHANGE_UPLOADED');
            $success = "Bukti Tukar Faktur berhasil disimpan. ACT selesai; status DO tetap WAIT PAYMENT untuk proses FIN.";
        }

        if ($action === 'send_fin') {
            if (!in_array($curStatus, ['delivered'], true)) { throw new Exception('Tidak bisa kirim ke FIN. Status harus DELIVERED.'); }
            if (!$tax) throw new Exception("Wajib upload Faktur Pajak sebelum kirim ke FIN.");
            if (!$exg) throw new Exception("Wajib upload dan simpan Bukti Tukar Faktur sebelum kirim ke FIN.");
            if ($due_date === '' && $existingDue === '') throw new Exception("Wajib isi tanggal jatuh tempo sebelum kirim ke FIN.");
            if ($amount === null && $existingAmount === null) throw new Exception("Wajib isi nominal sebelum kirim ke FIN.");
            if (function_exists('auth_sales_do_require_transition')) {
                auth_sales_do_require_transition($curStatus, 'wait_payment', 'ACT_SEND_FIN');
            }

            $final_due = ($due_date !== '') ? $due_date : ($existingDue !== '' ? $existingDue : null);
            $final_amt = ($amount !== null) ? $amount : $existingAmount;
            if ((float)$final_amt <= 0) throw new Exception('Nominal invoice harus lebih dari Rp 0.');

            // Bentuk/update AR invoice sebelum DO dipindahkan ke WAIT PAYMENT.
            // Bila gagal, status DO tetap DELIVERED sehingga tidak ada piutang setengah jadi.
            rmi_ar_create_or_update_invoice_from_do($pdo, $id, (float)$final_amt, (string)$final_due, (string)$tax);

            $sets = ["status='wait_payment'"];
            $params = [];

            $readyFinAt = date('Y-m-d H:i:s');
            if ($hasActReadyFinAt) {
                $sets[] = "act_ready_fin_at=COALESCE(act_ready_fin_at, ?)";
                $params[] = $readyFinAt;
            }
            if ($hasActInvoicedAt) {
                $sets[] = "act_invoiced_at=COALESCE(act_invoiced_at, ?)";
                $params[] = $readyFinAt;
            }

            // Nilai tambahan handoff ACT->FIN disimpan mentah untuk analisis KPI/lead-time nanti.
            if ($hasActReadyFinDuration && empty($r['act_ready_fin_duration_sec'])) {
                $handoffSec = act_sla_seconds($actSlaStartAt, $readyFinAt);
                if ($handoffSec !== null) {
                    $sets[] = "act_ready_fin_duration_sec=?";
                    $params[] = $handoffSec;
                }
            }

            // Jika user langsung upload Faktur Pajak sekaligus menekan Kirim FIN,
            // freeze KPI Faktur Pajak pada saat upload/submit pertama ini.
            if ($tax_up && $hasActTaxInvoiceAt && empty($r['act_tax_invoice_at'])) {
                $taxAt = $readyFinAt;
                $taxSec = act_sla_seconds($actSlaStartAt, $taxAt);
                $sets[] = "act_tax_invoice_at=?";
                $params[] = $taxAt;
                if ($hasActTaxInvoiceBy) { $sets[] = "act_tax_invoice_by=COALESCE(act_tax_invoice_by, ?)"; $params[] = $actorName; }
                if ($hasActTaxDuration && $taxSec !== null) {
                    $sets[] = "act_tax_invoice_duration_sec=?";
                    $params[] = $taxSec;
                }
            }

            // Bukti Tukar Faktur boleh sudah ikut diupload sebelum WAIT PAYMENT.
            if ($exg_up && $hasActExchangeAt && empty($r['act_exchange_doc_at'])) {
                $exgAt = $readyFinAt;
                $exgSec = act_sla_seconds($actSlaStartAt, $exgAt);
                $sets[] = "act_exchange_doc_at=?";
                $params[] = $exgAt;
                if ($hasActExchangeBy) { $sets[] = "act_exchange_doc_by=COALESCE(act_exchange_doc_by, ?)"; $params[] = $actorName; }
                if ($hasActExchangeDuration && $exgSec !== null) {
                    $sets[] = "act_exchange_duration_sec=?";
                    $params[] = $exgSec;
                }
            }

            if ($hasActUpdatedAt) { $sets[] = "act_updated_at=NOW()"; }
            if ($hasActReadyBy) { $sets[] = "act_ready_by=?"; $params[] = $actorName; }
            if ($hasActUpdatedBy) { $sets[] = "act_updated_by=?"; $params[] = $actorName; }
            if ($hasActNote) { $sets[] = "act_note=?"; $params[] = $note; }
            // Handoff FIN hanya boleh terjadi setelah Faktur Pajak DAN Tukar Faktur selesai.
            // Karena send_fin sudah digate oleh $tax dan $exg, ACT pasti DONE pada handoff ke FIN.
            $actCompleteOnSend = true;
            if ($hasActStatus) { $sets[] = $actCompleteOnSend ? "act_status='Done'" : "act_status='Open'"; }
            if ($hasStatusAct) { $sets[] = $actCompleteOnSend ? "status_act='completed'" : "status_act='open'"; }
            if ($hasActTaxInvoiceFile) { $sets[] = "act_tax_invoice_file=?"; $params[] = $tax; }
            if ($hasActExchangeDocFile) { $sets[] = "act_exchange_doc_file=?"; $params[] = $exg; }
            if ($hasActDueDate) { $sets[] = "act_due_date=?"; $params[] = $final_due; }
            if ($hasActAmount) { $sets[] = "act_amount=?"; $params[] = $final_amt; }
            if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=?"; $params[] = $actorName; }
            if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }
            $params[] = $id;
            $pdo->prepare("UPDATE sales_do SET
                " . implode(",\n                ", $sets) . "
              WHERE id=?")->execute($params);

            if ($tax_up) act_doc_store_save($pdo, 'tax', $id, $tax_up, $readyFinAt, act_sla_seconds($actSlaStartAt, $readyFinAt), $actorName);
            if ($exg_up) act_doc_store_save($pdo, 'exchange', $id, $exg_up, $readyFinAt, act_sla_seconds($actSlaStartAt, $readyFinAt), $actorName);

            // PATCH_3_AUDIT
            $after = null;
            try {
                $stA = $pdo->prepare("SELECT id, status, act_status, act_note, act_tax_invoice_file, act_exchange_doc_file, act_due_date, act_amount, act_ready_fin_at, last_updated_by, last_updated_at FROM sales_do WHERE id=? LIMIT 1");
                $stA->execute([$id]);
                $after = $stA->fetch();
            } catch (Throwable $e) {
                $after = null;
            }
            rmi_audit_safe('APPROVE', 'SALES.DO', $id, array_merge($r, ['status' => $curStatus]), $after, [
                'event' => 'act_send_fin',
                'to_status' => 'wait_payment',
            ]);
            if (function_exists('master_audit')) {
                $code = (string)($r['do_code'] ?? '');
                master_audit($pdo, 'sales_do', 'sales_do', 'ACT_SEND_FIN', $id, $code, "DO ACT send FIN: {$code}", ['to_status' => 'wait_payment']);
            }
            sales_do_audit_append($pdo, $id, $curStatus, 'wait_payment', 'ACT', $note);
            $success = "Status: WAIT PAYMENT ✅ (Trigger FIN)";
        }

    } catch (Throwable $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// ACT backlog: DELIVERED & WAIT_PAYMENT (lihat history singkat)
$_f_status  = trim((string)($_GET['f_status'] ?? ''));
$_f_date_fr = trim((string)($_GET['f_date_fr'] ?? ''));
$_f_date_to = trim((string)($_GET['f_date_to'] ?? ''));
$_f_exchange_date_fr = trim((string)($_GET['f_exchange_date_fr'] ?? ''));
$_f_exchange_date_to = trim((string)($_GET['f_exchange_date_to'] ?? ''));
$_f_q       = trim((string)($_GET['f_q'] ?? ''));

$_act_scope = do_scope_where('d');
$_act_extra_sql    = '';
$_act_extra_params = [];

$_allowed_act_status = ['delivered','wait_payment','paid'];
if ($_f_status !== '' && in_array($_f_status, $_allowed_act_status, true)) {
    $_act_extra_sql    .= " AND d.status = ?";
    $_act_extra_params[] = $_f_status;
}

// History PAID hanya ikut saat user memang membuka periode/history atau memilih PAID.
// Ini menjaga backlog default ACT tetap ringan dan tidak memaksa DO yang belum mencapai ACT.
$_include_paid_history = ($_f_status === 'paid' || $_f_date_fr !== '' || $_f_date_to !== '' || $_f_exchange_date_fr !== '' || $_f_exchange_date_to !== '');
$_act_status_scope_sql = $_include_paid_history
    ? "(d.status IN ('delivered','wait_payment') OR (d.status='paid' AND (NULLIF(d.act_ready_fin_at,'') IS NOT NULL OR NULLIF(d.act_tax_invoice_file,'') IS NOT NULL OR NULLIF(d.act_exchange_doc_file,'') IS NOT NULL)))"
    : "d.status IN ('delivered','wait_payment')";
if ($_f_date_fr !== '') { $_act_extra_sql .= " AND d.do_date >= ?"; $_act_extra_params[] = $_f_date_fr; }
if ($_f_date_to !== '') { $_act_extra_sql .= " AND d.do_date <= ?"; $_act_extra_params[] = $_f_date_to; }
if ($_f_q !== '') {
    $_act_extra_sql    .= " AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR c.customers_name LIKE ?)";
    $_like = '%' . $_f_q . '%';
    $_act_extra_params = array_merge($_act_extra_params, [$_like, $_like, $_like]);
}

$_act_sql = "
    SELECT d.id, d.do_code, d.tracking_code, d.do_date, d.customers_code,
           c.customers_name,
           d.office_code, d.grand_total,
           d.status,
           " . select_col_d($hasActStatus, 'act_status') . ",
           " . select_col_d($hasStatusAct, 'status_act') . ",
           " . select_col_d($hasActNote, 'act_note') . ",
           " . select_col_d($hasActTaxInvoiceFile, 'act_tax_invoice_file') . ",
           " . select_col_d($hasActExchangeDocFile, 'act_exchange_doc_file') . ",
           " . select_col_d($hasActDueDate, 'act_due_date') . ",
           " . select_col_d($hasActAmount, 'act_amount') . ",
           " . select_col_d($hasScmDeliveredAt, 'scm_delivered_at') . ",
           " . select_col_d($hasActTaxInvoiceAt, 'act_tax_invoice_at') . ",
           " . select_col_d($hasActTaxDuration, 'act_tax_invoice_duration_sec') . ",
           " . select_col_d($hasActExchangeAt, 'act_exchange_doc_at') . ",
           " . select_col_d($hasActExchangeDuration, 'act_exchange_duration_sec') . ",
           " . select_col_d($hasActTaxInvoiceBy, 'act_tax_invoice_by') . ",
           " . select_col_d($hasActExchangeBy, 'act_exchange_doc_by') . ",
           " . select_col_d($hasActReadyFinAt, 'act_ready_fin_at') . ",
           " . select_col_d($hasActInvoicedAt, 'act_invoiced_at') . ",
           " . select_col_d($hasActReadyFinDuration, 'act_ready_fin_duration_sec') . "
    FROM sales_do d
    LEFT JOIN master_customers c ON c.customers_code = d.customers_code
    WHERE " . $_act_status_scope_sql . "
    " . $_act_scope['sql'] . $_act_extra_sql . "
    ORDER BY
      FIELD(d.status,'delivered','wait_payment','paid'),
      d.do_date DESC, d.id DESC";
$_act_st = $pdo->prepare($_act_sql);
$_act_st->execute(array_merge($_act_scope['params'], $_act_extra_params));
$rows = $_act_st->fetchAll();

/*
 * V13 PERFORMANCE
 * Sebelumnya 1.000 DO dapat memicu ribuan query (AR + 2 doc store + audit SLA per baris).
 * Sekarang semua recovery dilakukan batch. Query daftar utama tetap sama agar alur/filter existing aman.
 */
$allRows = $rows;
$allIds = array_values(array_unique(array_filter(array_map(static fn($x) => (int)($x['id'] ?? 0), $allRows))));
$allCodes = array_values(array_unique(array_filter(array_map(static fn($x) => trim((string)($x['do_code'] ?? '')), $allRows))));

$needArCodes = [];
foreach ($allRows as $r0) {
    if (trim((string)($r0['act_tax_invoice_file'] ?? '')) === ''
        || trim((string)($r0['act_due_date'] ?? '')) === ''
        || (($r0['act_amount'] ?? null) === null || $r0['act_amount'] === '')) {
        $c0 = trim((string)($r0['do_code'] ?? ''));
        if ($c0 !== '') $needArCodes[] = $c0;
    }
}
$arBatch = act_batch_ar_fallback($pdo, $needArCodes);
$docBatch = act_batch_doc_store($pdo, $allIds);
$slaStartBatch = act_batch_sla_start($pdo, $allRows);

$legacyNeedIds = [];
foreach ($allRows as $r0) {
    $id0 = (int)($r0['id'] ?? 0);
    $store0 = $docBatch[$id0] ?? [];
    if (trim((string)($r0['act_exchange_doc_file'] ?? '')) === ''
        && trim((string)($store0['exchange_file'] ?? '')) === '') {
        $legacyNeedIds[] = $id0;
    }
}
$legacyBatch = act_batch_legacy_exchange($pdo, $legacyNeedIds);

foreach ($allRows as &$rrAr) {
    $id0 = (int)($rrAr['id'] ?? 0);
    $code0 = trim((string)($rrAr['do_code'] ?? ''));
    $af = $arBatch[$code0] ?? ['source_tax_file'=>'','due_date'=>'','total_amount'=>null];
    $rrAr['ar_source_tax_file'] = $af['source_tax_file'];
    $rrAr['ar_due_date'] = $af['due_date'];
    $rrAr['ar_total_amount'] = $af['total_amount'];

    $ds = $docBatch[$id0] ?? [
        'tax_file'=>'','tax_at'=>'','tax_by'=>'',
        'exchange_file'=>'','exchange_at'=>'','exchange_duration'=>null,'exchange_by'=>''
    ];
    $rrAr['store_tax_file'] = $ds['tax_file'];
    $rrAr['store_tax_at'] = $ds['tax_at'];
    $rrAr['store_exchange_file'] = $ds['exchange_file'];
    $rrAr['store_exchange_at'] = $ds['exchange_at'];
    $rrAr['store_exchange_duration'] = $ds['exchange_duration'];

    $lx = $legacyBatch[$id0] ?? ['file'=>'','at'=>''];
    $rrAr['legacy_exchange_file'] = $lx['file'];
    $rrAr['legacy_exchange_at'] = $lx['at'];
    $rrAr['_sla_start_at'] = $slaStartBatch[$id0] ?? '';
}
unset($rrAr);

/* Filter Tukar Faktur: status + TANGGAL UPLOAD (bukan tanggal DO).
 * Dilakukan setelah recovery supaya canonical, document-store, dan legacy dihitung konsisten.
 * Tanggal upload diprioritaskan dari:
 * 1) sales_do.act_exchange_doc_at
 * 2) sales_do_act_exchange_docs.uploaded_at
 * 3) audit legacy yang berhasil dipetakan
 * 4) timestamp nama file / mtime sebagai fallback READ-ONLY.
 */
$_f_exchange = trim((string)($_GET['f_exchange'] ?? ''));
if (!in_array($_f_exchange, ['', 'uploaded', 'missing'], true)) $_f_exchange = '';

$validDate = static function(string $v): bool {
    if ($v === '') return true;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $v;
};
if (!$validDate($_f_exchange_date_fr)) $_f_exchange_date_fr = '';
if (!$validDate($_f_exchange_date_to)) $_f_exchange_date_to = '';

if ($_f_exchange !== '' || $_f_exchange_date_fr !== '' || $_f_exchange_date_to !== '') {
    $allRows = array_values(array_filter($allRows, static function(array $r) use ($_f_exchange, $_f_exchange_date_fr, $_f_exchange_date_to): bool {
        $exg = trim((string)(($r['act_exchange_doc_file'] ?? '')
            ?: ($r['store_exchange_file'] ?? '')
            ?: ($r['legacy_exchange_file'] ?? '')));
        $hasExg = ($exg !== '');

        // Filter status tetap independen dari filter tanggal upload.
        if ($_f_exchange === 'uploaded' && !$hasExg) return false;
        if ($_f_exchange === 'missing' && $hasExg) return false;

        // Jika user memilih rentang tanggal upload, hanya dokumen yang benar-benar punya file
        // dan timestamp upload yang dapat dibuktikan yang boleh lolos.
        if ($_f_exchange_date_fr !== '' || $_f_exchange_date_to !== '') {
            if (!$hasExg) return false;

            $uploadAt = trim((string)(($r['act_exchange_doc_at'] ?? '')
                ?: ($r['store_exchange_at'] ?? '')
                ?: ($r['legacy_exchange_at'] ?? '')));

            if ($uploadAt === '') {
                $uploadAt = trim((string)(act_historical_file_timestamp($exg, null) ?? ''));
            }
            if ($uploadAt === '') return false;

            try {
                $uploadDate = (new DateTimeImmutable($uploadAt))->format('Y-m-d');
            } catch (Throwable $e) {
                return false;
            }

            if ($_f_exchange_date_fr !== '' && $uploadDate < $_f_exchange_date_fr) return false;
            if ($_f_exchange_date_to !== '' && $uploadDate > $_f_exchange_date_to) return false;
        }
        return true;
    }));
}

/* Pagination UI supaya browser tidak merender 1.000+ form/file-input sekaligus.
 * Data tidak dihapus; hanya 100 baris per halaman. Filter tetap mempertahankan seluruh dataset.
 */
$_f_page = max(1, (int)($_GET['f_page'] ?? 1));
$_perPage = 100;
$totalRows = count($allRows);
$totalPages = max(1, (int)ceil($totalRows / $_perPage));
if ($_f_page > $totalPages) $_f_page = $totalPages;
$rows = array_slice($allRows, ($_f_page - 1) * $_perPage, $_perPage);

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
        'delivered'     => ['DELIVERED','tag blue'],
        'wait_payment'  => ['WAIT PAYMENT','tag yellow'],
    ];
    $v = $map[$s] ?? [strtoupper($s), 'tag'];
    return '<span class="'.$v[1].'">'.h($v[0]).'</span>';
}
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('ACT - Task DO', [
  'active' => 'sales',
  'breadcrumbs' => [
    ['label' => 'Sales (CRM)', 'url' => $baseProject . '/sales/sales_dashboard.php'],
    'ACT - Task DO',
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
    .tag.blue{border-color:rgba(96,165,250,.35); background:rgba(96,165,250,.12)}
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
    .sla-box{margin-top:8px;display:flex;flex-direction:column;gap:5px;padding:8px;border:1px solid rgba(255,255,255,.08);border-radius:9px;background:rgba(255,255,255,.025)}
    .sla-row{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
    .sla-name{font-size:10px;color:#cbd5e1;font-weight:600;text-transform:uppercase;letter-spacing:.3px}
    .sla-time{font-size:11px;color:#e5e7eb;text-align:right}
    .sla-running{color:#fbbf24}
    .sla-done{color:#86efac}
    .sla-legacy{color:#94a3b8}
  </style>',
]);
?>

  <div class="wrap">
    <div class="top">
      <div class="title">
        <h1>ACT – TASK DO SETELAH DELIVERED</h1>
        <div class="sub">Upload Faktur Pajak → SLA Faktur stop → upload Tukar Faktur → SLA Tukar Faktur stop → Kirim ke FIN (WAIT PAYMENT).</div>
        <div class="flow">
          <?php echo pill('CRM', false); ?>
          <?php echo pill('WQS', false); ?>
          <?php echo pill('SCM', false); ?>
          <?php echo pill('ACT (Now)', true); ?>
          <?php echo pill('FIN', false); ?>
        </div>
      </div>
      <div class="stack">
        <a class="btn" href="sales_dashboard.php">« Kembali ke Modul Penjualan</a>
        <a class="btn" href="scm_do_tasks.php">SCM Tasks</a>
      </div>
    </div>

    <?php if ($success): ?><div class="alert ok"><?php echo h($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert bad"><?php echo h($error); ?></div><?php endif; ?>

    <div class="card">
      <div class="muted mini">
        List DO untuk ACT. Alur bisnis: <b>DELIVERED</b> → Faktur Pajak + tanggal/nominal → Tukar Faktur → <b>WAIT PAYMENT</b> (trigger FIN).
        KPI ACT dicatat terpisah: <b>SLA Faktur Pajak</b> dan <b>SLA Tukar Faktur</b>, keduanya mulai dari SCM DELIVERED.
        <b>SLA Tukar Faktur maksimal 7 hari</b> dan ditampilkan dalam satuan hari. Timer Faktur Pajak berhenti saat bukti pertama diupload. Timer Tukar Faktur berhenti saat bukti pertama diupload. FIN baru dilanjutkan setelah Tukar Faktur tersedia.
        Filter tanggal <b>Upload Tukar</b> memakai tanggal upload Bukti Tukar Faktur, bukan tanggal DO.
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
            <option value="delivered"    <?= $_f_status==='delivered'    ?'selected':'' ?>>DELIVERED</option>
            <option value="wait_payment" <?= $_f_status==='wait_payment' ?'selected':'' ?>>WAIT PAYMENT</option>
            <option value="paid" <?= $_f_status==='paid' ?'selected':'' ?>>PAID / HISTORY</option>
          </select>
        </div>
        <div>
          <div class="mini muted" style="margin-bottom:4px">Tukar Faktur</div>
          <select class="field select" style="width:150px" name="f_exchange">
            <option value="" <?= $_f_exchange==='' ? 'selected' : '' ?>>Semua</option>
            <option value="uploaded" <?= $_f_exchange==='uploaded' ? 'selected' : '' ?>>Sudah Upload</option>
            <option value="missing" <?= $_f_exchange==='missing' ? 'selected' : '' ?>>Belum Upload</option>
          </select>
        </div>
        <div>
          <div class="mini muted" style="margin-bottom:4px">Upload Tukar dari</div>
          <input class="field" style="width:140px" type="date" name="f_exchange_date_fr" value="<?= h($_f_exchange_date_fr) ?>">
        </div>
        <div>
          <div class="mini muted" style="margin-bottom:4px">Upload Tukar s/d</div>
          <input class="field" style="width:140px" type="date" name="f_exchange_date_to" value="<?= h($_f_exchange_date_to) ?>">
        </div>
        <div>
          <div class="mini muted" style="margin-bottom:4px">Tgl DO dari</div>
          <input class="field" style="width:140px" type="date" name="f_date_fr" value="<?= h($_f_date_fr) ?>">
        </div>
        <div>
          <div class="mini muted" style="margin-bottom:4px">Tgl DO s/d</div>
          <input class="field" style="width:140px" type="date" name="f_date_to" value="<?= h($_f_date_to) ?>">
        </div>
        <div style="display:flex;gap:8px;padding-bottom:1px">
          <button class="btn2 primary" type="submit">Filter</button>
          <a class="btn2" href="?">Reset</a>
        </div>
        <div class="mini muted" style="padding-bottom:4px">
          Menampilkan <?= count($rows) ?> dari <?= (int)$totalRows ?> DO
          <?php if ($totalPages > 1): ?> · Halaman <?= (int)$_f_page ?>/<?= (int)$totalPages ?><?php endif; ?>
        </div>
      </form>
    </div>

    <?php if ($totalPages > 1): ?>
      <?php
        $pagerBase = $_GET;
        unset($pagerBase['f_page']);
        $pagerQs = http_build_query($pagerBase);
        $pagerPrefix = '?' . ($pagerQs !== '' ? $pagerQs . '&' : '');
      ?>
      <div class="card" style="padding:10px 14px">
        <div class="actions" style="justify-content:space-between">
          <div class="mini muted">100 DO per halaman agar loading lebih ringan. Total <?= (int)$totalRows ?> DO.</div>
          <div class="actions">
            <?php if ($_f_page > 1): ?>
              <a class="btn2" href="<?= h($pagerPrefix . 'f_page=' . ($_f_page - 1)) ?>">← Sebelumnya</a>
            <?php endif; ?>
            <span class="tag">Halaman <?= (int)$_f_page ?> / <?= (int)$totalPages ?></span>
            <?php if ($_f_page < $totalPages): ?>
              <a class="btn2" href="<?= h($pagerPrefix . 'f_page=' . ($_f_page + 1)) ?>">Berikutnya →</a>
            <?php endif; ?>
          </div>
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
            <th style="width:230px">ACT / KPI SLA</th>
            <th style="width:260px">Dokumen</th>
            <th style="width:460px">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="muted">Belum ada DO yang sudah mencapai tahap ACT pada filter ini.</td></tr>
          <?php endif; ?>

          <?php foreach ($rows as $r):
            $is_wait_payment = ($r['status'] === 'wait_payment');
            $is_paid = ($r['status'] === 'paid');
            $is_locked = $is_paid;

            // Canonical sales_do tidak pernah diganti; fallback hanya dipakai bila canonical kosong.
            $taxFile = trim((string)(($r['act_tax_invoice_file'] ?? '') ?: ($r['store_tax_file'] ?? '') ?: ($r['ar_source_tax_file'] ?? '')));
            $exgFile = trim((string)(($r['act_exchange_doc_file'] ?? '') ?: ($r['store_exchange_file'] ?? '') ?: ($r['legacy_exchange_file'] ?? '')));
            $dueValue = trim((string)(($r['act_due_date'] ?? '') ?: ($r['ar_due_date'] ?? '')));
            $amountValue = ($r['act_amount'] !== null && $r['act_amount'] !== '') ? $r['act_amount'] : ($r['ar_total_amount'] ?? null);
            $taxHasFile = ($taxFile !== '');
            $exgHasFile = ($exgFile !== '');
            // Evidence dokumen adalah gate selesai KPI. Status WAIT PAYMENT tidak dipakai sebagai gate.
            // Begitu Bukti Tukar Faktur ada, timer WAJIB berhenti dan tidak boleh kembali memakai NOW().
            $taxDone = $taxHasFile;
            $exgDone = $exgHasFile;

            $actStartAt = trim((string)($r['_sla_start_at'] ?? $r['scm_delivered_at'] ?? ''));

            $taxFinishedAt = trim((string)(($r['act_tax_invoice_at'] ?? '') ?: ($r['store_tax_at'] ?? '')));
            $exgFinishedAt = trim((string)(($r['act_exchange_doc_at'] ?? '') ?: ($r['store_exchange_at'] ?? '') ?: ($r['legacy_exchange_at'] ?? '')));
            $taxStoredSec = ($r['act_tax_invoice_duration_sec'] ?? null);
            $exgStoredSec = ($r['act_exchange_duration_sec'] ?? null);
            if ($exgStoredSec === null && ($r['store_exchange_duration'] ?? null) !== null) $exgStoredSec = (int)$r['store_exchange_duration'];

            // File adalah bukti selesai. WAIT PAYMENT sendiri bukan bukti dokumen.
            if (!$taxHasFile) {
                $taxFinishedAt = '';
                $taxStoredSec = null;
            } elseif ($taxFinishedAt === '') {
                if ($taxStoredSec !== null && $actStartAt) {
                    try { $taxFinishedAt = (new DateTimeImmutable($actStartAt))->modify('+' . max(0,(int)$taxStoredSec) . ' seconds')->format('Y-m-d H:i:s'); }
                    catch (Throwable $e) { $taxFinishedAt = ''; }
                }
                if ($taxFinishedAt === '') $taxFinishedAt = (string)(act_historical_file_timestamp($taxFile, $actStartAt) ?? '');
            }

            if (!$exgHasFile) {
                $exgFinishedAt = '';
                $exgStoredSec = null;
            } elseif ($exgFinishedAt === '') {
                if ($exgStoredSec !== null && $actStartAt) {
                    try { $exgFinishedAt = (new DateTimeImmutable($actStartAt))->modify('+' . max(0,(int)$exgStoredSec) . ' seconds')->format('Y-m-d H:i:s'); }
                    catch (Throwable $e) { $exgFinishedAt = ''; }
                }
                if ($exgFinishedAt === '') $exgFinishedAt = (string)(act_historical_file_timestamp($exgFile, $actStartAt) ?? '');
            }

            /* Legacy: bila DO sudah handoff FIN, SLA ACT tidak boleh terus berjalan hanya karena
             * path evidence lama belum termapping. Handoff hanya menjadi STOP fallback SLA;
             * tidak dianggap sebagai file dan tidak mengubah taxDone/exgDone. */
            $legacyHandoffAt = trim((string)($r['act_ready_fin_at'] ?? ''));
            $taxLegacyHandoffStop = false;
            $exgLegacyHandoffStop = false;
            if ($legacyHandoffAt !== '' && $actStartAt !== '' && in_array((string)$r['status'], ['wait_payment','paid'], true)) {
                try {
                    $handoffDt = new DateTimeImmutable($legacyHandoffAt);
                    $startDt = new DateTimeImmutable($actStartAt);
                    if ($handoffDt >= $startDt) {
                        if (!$taxDone && $taxFinishedAt === '') { $taxFinishedAt = $legacyHandoffAt; $taxLegacyHandoffStop = true; }
                        if (!$exgDone && $exgFinishedAt === '') { $exgFinishedAt = $legacyHandoffAt; $exgLegacyHandoffStop = true; }
                    }
                } catch (Throwable $e) { /* invalid legacy timestamp: jangan dipakai sebagai stop */ }
            }

            $taxSec = ($taxDone || $taxLegacyHandoffStop)
                ? ($taxStoredSec !== null ? (int)$taxStoredSec : ($taxFinishedAt !== '' ? act_sla_seconds($actStartAt, $taxFinishedAt) : null))
                : act_sla_seconds($actStartAt);
            $exgSec = ($exgDone || $exgLegacyHandoffStop)
                ? ($exgStoredSec !== null ? (int)$exgStoredSec : ($exgFinishedAt !== '' ? act_sla_seconds($actStartAt, $exgFinishedAt) : null))
                : act_sla_seconds($actStartAt);

            $taxLegacyUnknown = $taxDone && $taxFinishedAt === '' && $taxStoredSec === null;
            $exgLegacyUnknown = $exgDone && $exgFinishedAt === '' && $exgStoredSec === null;
          ?>
            <tr>
              <td>
                <div><b><?php echo h($r['do_code']); ?></b></div>
                <div class="mini muted">Track: <?php echo h($r['tracking_code'] ?? $r['do_code']); ?></div>
                <div class="mini"><a href="sales_do_view.php?id=<?php echo (int)$r['id']; ?>" target="_blank">Detail / Print</a></div>
              </td>
              <td><?php echo h($r['do_date']); ?></td>
              <td>
                <div><b><?php echo h(($r['customers_name'] ?? '') !== '' ? $r['customers_name'] : $r['customers_code']); ?></b></div>
                <div class="mini muted"><?php echo h($r['customers_code'] ?? ''); ?></div>
              </td>
              <td><?php echo h($r['office_code']); ?></td>
              <td><?php echo h(number_format((float)($r['grand_total'] ?? 0), 0, ',', '.')); ?></td>
              <td>
                <div class="actions" style="gap:6px">
                  <?php echo badgeStatus($r['status']); ?>
                  <span class="tag"><?php echo h($exgDone ? (($taxDone || $r['status'] === 'wait_payment') ? 'Done' : ($r['act_status'] ?: 'Pending')) : (($r['status'] === 'wait_payment') ? 'Open' : ($r['act_status'] ?: 'Pending'))); ?></span>
                </div>
                <div class="mini muted" style="margin-top:6px">Catatan: <?php echo h($r['act_note'] ?? ''); ?></div>

                <div class="sla-box">
                  <div class="sla-row">
                    <div>
                      <div class="sla-name">Faktur Pajak</div>
                      <div class="mini muted">Mulai: <?= h($actStartAt ?: '-') ?></div>
                    </div>
                    <div class="sla-time">
                      <?php if ($taxLegacyUnknown): ?>
                        <span class="sla-legacy">Selesai — timestamp historis tidak tersedia</span>
                      <?php elseif ($taxLegacyHandoffStop): ?>
                        <span class="sla-legacy">⏹ <?= h(act_duration_text($taxSec)) ?> <small>(stop Handoff FIN legacy; evidence belum termapping)</small></span>
                      <?php elseif ($taxFinishedAt !== ''): ?>
                        <span class="sla-done">✓ <?= h(act_duration_text($taxSec)) ?></span>
                        <div class="mini muted"><?= h($taxFinishedAt) ?></div>
                      <?php elseif ($actStartAt): ?>
                        <span class="sla-running act-sla-live"
                              data-start="<?= h($actStartAt) ?>"
                              data-end="">⏱ <?= h(act_duration_text($taxSec)) ?></span>
                      <?php else: ?>
                        <span class="sla-legacy">Start DELIVERED belum tersedia</span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="sla-row" style="padding-top:5px;border-top:1px solid rgba(255,255,255,.06)">
                    <div>
                      <div class="sla-name">Tukar Faktur</div>
                      <div class="mini muted">Mulai: <?= h($actStartAt ?: '-') ?></div>
                    </div>
                    <div class="sla-time">
                      <?php if ($exgDone && $exgLegacyUnknown): ?>
                        <span class="sla-done">✓ Selesai — timestamp historis tidak tersedia</span>
                      <?php elseif ($exgDone && $exgFinishedAt !== ''): ?>
                        <span class="sla-done">✓ <?= h(act_exchange_duration_text($exgSec)) ?></span>
                        <div class="mini muted"><?= h($exgFinishedAt) ?></div>
                      <?php elseif ($exgLegacyHandoffStop): ?>
                        <span class="sla-legacy">⏹ <?= h(act_exchange_duration_text($exgSec)) ?> <small>(stop Handoff FIN legacy; evidence belum termapping)</small></span>
                      <?php elseif (!$exgDone && $actStartAt): ?>
                        <span class="sla-running act-sla-live-days"
                              data-start="<?= h($actStartAt) ?>"
                              data-end="">⏱ <?= h(act_exchange_duration_text($exgSec)) ?> (berjalan)</span>
                      <?php else: ?>
                        <span class="sla-legacy">Start DELIVERED belum tersedia</span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <?php if (!empty($r['act_ready_fin_at'])): ?>
                    <div class="mini muted" style="padding-top:5px;border-top:1px solid rgba(255,255,255,.06)">
                      Handoff FIN: <?= h($r['act_ready_fin_at']) ?>
                      <?php if (($r['act_ready_fin_duration_sec'] ?? null) !== null): ?>
                        · <?= h(act_duration_text((int)$r['act_ready_fin_duration_sec'])) ?>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php if (($taxLegacyHandoffStop || $exgLegacyHandoffStop) && (!$taxHasFile || !$exgHasFile)): ?>
                    <div class="mini muted" style="margin-top:4px">Evidence legacy belum termapping; SLA tidak diteruskan melewati Handoff FIN.</div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="photo-links">
                  <div>Faktur Pajak: <?php echo $taxHasFile ? '<a href="'.h($taxFile).'" target="_blank">lihat</a>' : '<span class="muted">-</span>'; ?></div>
                  <div>Tukar Faktur: <?php echo $exgHasFile ? '<a href="'.h($exgFile).'" target="_blank">lihat</a>' : '<span class="muted">-</span>'; ?></div>
                  <?php if ($exgHasFile): ?>
                    <div class="mini muted">Upload Tukar: <?= h($exgFinishedAt !== '' ? $exgFinishedAt : '-') ?></div>
                  <?php endif; ?>
                  <div>Jatuh tempo: <?php echo $dueValue !== '' ? h($dueValue) : '<span class="muted">-</span>'; ?></div>
                  <div>Nominal: <?php echo $amountValue !== null ? h(number_format((float)$amountValue, 0, ',', '.')) : '<span class="muted">-</span>'; ?></div>
                </div>
              </td>
              <td>
                <form method="post" enctype="multipart/form-data" class="stack">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">

                  <div class="grid2">
                    <select class="field select" name="act_status" <?php echo ($is_locked || $is_wait_payment) ? "disabled" : ""; ?>>
                      <?php $cur = $r['act_status'] ?: 'Pending'; ?>
                      <option value="Pending" <?php echo $cur==='Pending'?'selected':''; ?>>Pending</option>
                      <option value="Open" <?php echo $cur==='Open'?'selected':''; ?>>Open</option>
                      <option value="Done" <?php echo $cur==='Done'?'selected':''; ?>>Done</option>
                    </select>
                    <input class="field" name="act_note" placeholder="Catatan ACT..." value="<?php echo h($r['act_note'] ?? ''); ?>" <?php echo $is_locked ? "disabled" : ""; ?>>
                  </div>

                  <div class="gridFiles">
                    <div>
                      <div class="mini muted" style="margin:0 0 6px">Upload Faktur Pajak (pdf/jpg/jpeg/png)</div>
                      <input class="field" type="file" name="act_tax_invoice_file" <?php echo ($is_locked || $taxHasFile) ? "disabled" : ""; ?> accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <div>
                      <div class="mini muted" style="margin:0 0 6px">Upload Bukti Tukar Faktur (pdf/jpg/jpeg/png)</div>
                      <input class="field" type="file" name="act_exchange_doc_file" <?php echo ($is_locked || $exgHasFile) ? "disabled" : ""; ?> accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                  </div>

                  <div class="gridFiles">
                    <div>
                      <div class="mini muted" style="margin:0 0 6px">Tanggal dibayar / jatuh tempo</div>
                      <input class="field" type="date" name="act_due_date" <?php echo ($is_locked || $is_wait_payment) ? "disabled" : ""; ?> value="<?php echo h($dueValue); ?>">
                    </div>
                    <div>
                      <div class="mini muted" style="margin:0 0 6px">Nominal (contoh: 1000000)</div>
                      <input class="field" type="text" name="act_amount" <?php echo ($is_locked || $is_wait_payment) ? "disabled" : ""; ?> value="<?php echo h($amountValue ?? ''); ?>" placeholder="nominal">
                    </div>
                  </div>

                  <div class="actions">
                    <?php if ($is_paid): ?>
                      <span class="tag" style="opacity:.9">🔒 Terkunci (sudah PAID)</span>
                    <?php elseif ($is_wait_payment): ?>
                      <?php if (!$taxHasFile): ?>
                        <span class="tag yellow" style="opacity:.95">WAIT PAYMENT — Faktur Pajak historis masih kosong; boleh dilengkapi tanpa mengubah status</span>
                        <button class="btn2 primary" name="action" value="save_tax_recovery" type="submit">Simpan Faktur Pajak</button>
                      <?php endif; ?>
                      <?php if ($exgHasFile): ?>
                        <span class="tag" style="opacity:.95">🔒 Bukti Tukar Faktur sudah tersimpan</span>
                      <?php else: ?>
                        <span class="tag yellow" style="opacity:.95">WAIT PAYMENT — upload Bukti Tukar Faktur, lalu simpan</span>
                        <button class="btn2 ok" name="action" value="save_exchange" type="submit">Simpan Tukar Faktur</button>
                      <?php endif; ?>
                    <?php else: ?>
                      <button class="btn2 primary" name="action" value="save" type="submit">Simpan Data ACT</button>
                      <?php if (in_array($r['status'], ['delivered'], true)): ?>
                        <button class="btn2 ok" name="action" value="send_fin" type="submit">Kirim ke FIN (WAIT PAYMENT)</button>
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
      <div class="mini muted">Upload tersimpan ke: <code>/uploads/sales_act/</code>. Alur: <b>DELIVERED</b> → Faktur Pajak + jatuh tempo + nominal → Tukar Faktur → <b>Kirim ke FIN (WAIT PAYMENT)</b>. Faktur Pajak dan Tukar Faktur masing-masing menghentikan SLA saat upload pertama. Dokumen yang sudah ada terkunci dan tidak ditimpa.</div>
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

  function parseSqlDateTime(v) {
    if (!v) return null;
    var normalized = String(v).replace(' ', 'T');
    var d = new Date(normalized);
    return isNaN(d.getTime()) ? null : d;
  }

  function durationText(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var day = Math.floor(sec / 86400); sec %= 86400;
    var hour = Math.floor(sec / 3600); sec %= 3600;
    var min = Math.floor(sec / 60);
    var s = sec % 60;
    var parts = [];
    if (day > 0) parts.push(day + ' hari');
    if (hour > 0 || day > 0) parts.push(hour + ' jam');
    if (min > 0 || hour > 0 || day > 0) parts.push(min + ' menit');
    parts.push(s + ' detik');
    return parts.join(' ');
  }

  function exchangeDurationText(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var day = Math.floor(sec / 86400);
    return day + ' hari';
  }

  function refreshActSlaTimers() {
    var now = new Date();
    document.querySelectorAll('.act-sla-live').forEach(function (el) {
      var start = parseSqlDateTime(el.getAttribute('data-start') || '');
      if (!start) return;
      var sec = Math.floor((now.getTime() - start.getTime()) / 1000);
      el.textContent = '⏱ ' + durationText(sec);
    });
    document.querySelectorAll('.act-sla-live-days').forEach(function (el) {
      var start = parseSqlDateTime(el.getAttribute('data-start') || '');
      if (!start) return;
      var sec = Math.floor((now.getTime() - start.getTime()) / 1000);
      el.textContent = '⏱ ' + exchangeDurationText(sec);
    });
  }

  refreshActSlaTimers();
  setInterval(refreshActSlaTimers, 1000);
})();
</script>
<?php rmi_footer(); ?>
