<?php
// hrl_reg_alkes/reg_alkes_control_tower.php
// Control Tower v2: tracking Step 1–15 registrasi, per-case dossier folder, templates, SKU link
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

ob_start();

require_once __DIR__ . '/../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['HRL.REG_ALKES_VIEW', 'HRL.VIEW', 'PQP.VIEW']);
} elseif (file_exists(__DIR__ . '/../_shared/enterprise_guard.php')) {
    require_once __DIR__ . '/../_shared/enterprise_guard.php';
    if (function_exists('eg_can_access_reg_alkes') && !eg_can_access_reg_alkes()) {
        http_response_code(403);
        echo "<h3>Akses ditolak</h3><p>Module REG Alkes hanya untuk dept PQP/HRL/ITC atau SYS.</p>";
        exit;
    }
}

$pdo = db_pdo();
$pdo->exec("SET collation_connection = 'utf8mb4_general_ci'");
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_stage_log_helper.php';

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function now_dt(): string { return date('Y-m-d H:i:s'); }

function project_root(): string {
    $root = realpath(__DIR__ . '/..');
    return $root ?: dirname(__DIR__);
}
function ensure_dir(string $dir): void {
    if ($dir === '') return;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}
if (!function_exists('safe_filename')) {
    function safe_filename(string $name): string {
        $name = preg_replace('/[^\w\-. ]+/u', '_', $name);
        $name = preg_replace('/\s+/', '_', $name);
        return trim($name, '._');
    }
}
if (!function_exists('csrf_check')) {
    function csrf_check(): void {
        $token = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? '');
        if (function_exists('verify_csrf')) {
            verify_csrf($token);
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $expected = (string)($_SESSION['_csrf'] ?? '');
        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            exit('Forbidden (CSRF)');
        }
    }
}

function ini_bytes(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val)-1]);
    $num = (float)$val;
    switch ($last) {
        case 'g': $num *= 1024; // fallthrough
        case 'm': $num *= 1024; // fallthrough
        case 'k': $num *= 1024; break;
        default: break;
    }
    return (int)round($num);
}

function upload_err_text(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE: return 'Ukuran file melebihi upload_max_filesize (php.ini).';
        case UPLOAD_ERR_FORM_SIZE: return 'Ukuran file melebihi limit form (MAX_FILE_SIZE).';
        case UPLOAD_ERR_PARTIAL: return 'File terupload sebagian (partial).';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Folder temp untuk upload tidak ditemukan.';
        case UPLOAD_ERR_CANT_WRITE: return 'Gagal menulis file ke disk.';
        case UPLOAD_ERR_EXTENSION: return 'Upload dihentikan oleh ekstensi PHP.';
        default: return 'Error upload tidak diketahui (code: ' . $code . ').';
    }
}


function derive_product_name_from_filename(string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    // Remove common prefixes like "File Aksesoris - " / "Aksesoris - "
    $base = preg_replace('/^(file[_ -]*)?(aksesori|aksesoris)[_ -]*[-_ ]*/iu', '', $base);
    $base = preg_replace('/^(aksesori|aksesoris)[_ -]*[-_ ]*/iu', '', $base);
    $base = str_replace(['_'], [' '], $base);
    $base = preg_replace('/\s+/', ' ', $base);
    $base = trim($base);
    if ($base === '') $base = 'Produk';
    return $base;
}

function stage_defs(): array {
    // step 1–15 sesuai flow kamu
    return [
        1  => ['code'=>'PQP_FIND_PRINCIPAL', 'label'=>'1. PQP cari principal + katalog', 'next'=>'PQP'],
        2  => ['code'=>'PQP_QUOTATION_DEAL', 'label'=>'2. PQP quotation + negosiasi (deal)', 'next'=>'PQP'],
        3  => ['code'=>'PQP_PKS_LOA_DRAFT', 'label'=>'3. PQP minta PKS + draft LOA', 'next'=>'PQP'],
        4  => ['code'=>'LEGAL_SIGN_LOA_KBRI', 'label'=>'4. Legal cek PKS/LOA + ttd + legalisasi KBRI (LOA)', 'next'=>'HRL'],
        5  => ['code'=>'PQP_REQUEST_DOSSIER', 'label'=>'5. PQP minta berkas registrasi ke pabrikan', 'next'=>'PQP'],
        6  => ['code'=>'PQP_SUPPORT_DOCS', 'label'=>'6. PQP siapkan pendukung (aksesori/OEM/IFU-ID)', 'next'=>'PQP'],
        7  => ['code'=>'LEGAL_CHECK', 'label'=>'7. Legal cek kelengkapan berkas', 'next'=>'HRL'],
        8  => ['code'=>'LEGAL_OSS_REGALKES', 'label'=>'8. Legal PB-UMKU (OSS RBA) + permohonan baru Regalkes', 'next'=>'HRL'],
        9  => ['code'=>'LEGAL_MGMT_DOCS', 'label'=>'9. Legal siapkan berkas ttd manajemen + dokumen RMI (CDAKB dll)', 'next'=>'HRL'],
        10 => ['code'=>'LEGAL_SUBMIT', 'label'=>'10. Legal isi nama/kategori/jenis/kemasan + submit', 'next'=>'HRL'],
        11 => ['code'=>'REVISION_REQUESTED', 'label'=>'11. Revisi diminta (maks 10 hari, 1x)', 'next'=>'PQP'],
        12 => ['code'=>'PQP_REVISION_COMPLETE', 'label'=>'12. PQP lengkapi berkas revisi ke pabrikan', 'next'=>'PQP'],
        13 => ['code'=>'LEGAL_REVISION_SUBMIT', 'label'=>'13. Legal submit revisi (kesempatan 1x)', 'next'=>'HRL'],
        14 => ['code'=>'LEGAL_NIE_REVIEW', 'label'=>'14. Legal review draft NIE', 'next'=>'HRL'],
        15 => ['code'=>'NIE_ISSUED_GO_LIVE', 'label'=>'15. NIE terbit (AKL/AKD) + GO LIVE (boleh order)', 'next'=>'PQP'],
    ];
}

function table_columns(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        $cols = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = (string)($r['Field'] ?? '');
        }
        return $cols;
    } catch (\Throwable $e) {
        return [];
    }
}

function has_col(array $cols, string $name): bool {
    return in_array($name, $cols, true);
}

function ensure_case_column(PDO $pdo, array &$cols, string $col, string $ddl): void {
    if (has_col($cols, $col)) return;
    try {
        $pdo->exec("ALTER TABLE hrl_reg_alkes_cases ADD COLUMN {$col} {$ddl}");
        $cols[] = $col;
    } catch (\Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate column') !== false) {
            $cols[] = $col;
        } else {
            throw $e;
        }
    }
}

function migrate_case_table(PDO $pdo, array $STAGES): array {
    // Create tables if missing, then *upgrade* v1 schema to v2 schema (best effort).
    $ok = true;
    $err = '';
    try {
        $pdo->exec("SET collation_connection = 'utf8mb4_general_ci'");
        // Cek tabel ada/tidak via SELECT (hindari CREATE IF NOT EXISTS yang trigger collation error)
        $tablesExist = false;
        try {
            $pdo->query("SELECT 1 FROM hrl_reg_alkes_cases LIMIT 1");
            $tablesExist = true;
        } catch (Throwable $e) {
            $tablesExist = false;
        }
        if (!$tablesExist) {
            $sqlCases = "CREATE TABLE hrl_reg_alkes_cases (
            id INT NOT NULL AUTO_INCREMENT,
            case_code VARCHAR(40) NOT NULL,
            manufacture_id INT NULL,
            manufacture_code VARCHAR(60) NOT NULL,
            manufacture_name VARCHAR(200) NULL,
            product_name VARCHAR(200) NOT NULL,
            is_oem TINYINT(1) NOT NULL DEFAULT 0,
            stage_no INT NOT NULL DEFAULT 1,
            stage_code VARCHAR(40) NULL,
            next_pic_dept VARCHAR(10) NULL,
            revision_count TINYINT NOT NULL DEFAULT 0,
            revision_deadline DATE DEFAULT NULL,
            oss_pb_umku VARCHAR(120) DEFAULT NULL,
            regalkes_ref VARCHAR(120) DEFAULT NULL,
            nie_type VARCHAR(10) DEFAULT NULL,
            nie_no VARCHAR(150) DEFAULT NULL,
            nie_issue_date DATE DEFAULT NULL,
            nie_file_rel VARCHAR(255) DEFAULT NULL,
            sku_imported_count INT NOT NULL DEFAULT 0,
            sku_last_import_at DATETIME DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
            note TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(64) DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by VARCHAR(64) DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            closed_by VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_case_code (case_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        $sqlDocs  = "CREATE TABLE hrl_reg_alkes_case_docs (
            id INT NOT NULL AUTO_INCREMENT,
            case_id INT NOT NULL,
            doc_type VARCHAR(40) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_rel VARCHAR(255) NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            uploaded_by VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_case (case_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        // Buat tabel dengan utf8mb4 (jika tersedia). Kalau server tidak support / typo charset, fallback ke default charset.
        try {
            $pdo->exec($sqlCases);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Unknown character set') !== false || stripos($msg, 'collation') !== false) {
                $sqlFallback = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*[a-z0-9_]+/i', '', $sqlCases);
                $pdo->exec($sqlFallback);
            } else {
                throw $e;
            }
        }

        try {
            $pdo->exec($sqlDocs);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Unknown character set') !== false || stripos($msg, 'collation') !== false) {
                $sqlFallback = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*[a-z0-9_]+/i', '', $sqlDocs);
                $pdo->exec($sqlFallback);
            } else {
                throw $e;
            }
        }
        } else {
            // cases ada, cek case_docs (mungkin upgrade dari v1)
            try {
                $pdo->query("SELECT 1 FROM hrl_reg_alkes_case_docs LIMIT 1");
            } catch (Throwable $e) {
                $pdo->exec("CREATE TABLE hrl_reg_alkes_case_docs (
                    id INT NOT NULL AUTO_INCREMENT,
                    case_id INT NOT NULL,
                    doc_type VARCHAR(40) NOT NULL,
                    file_name VARCHAR(255) NOT NULL,
                    file_rel VARCHAR(255) NOT NULL,
                    note VARCHAR(255) DEFAULT NULL,
                    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    uploaded_by VARCHAR(64) DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_case (case_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            }
        }

        $colsV2 = ['id','case_code','manufacture_id','manufacture_code','manufacture_name','product_name','is_oem','stage_no','stage_code','next_pic_dept','revision_count','revision_deadline','oss_pb_umku','regalkes_ref','nie_type','nie_no','nie_issue_date','nie_file_rel','sku_imported_count','sku_last_import_at','status','note','created_at','created_by','updated_at','updated_by','closed_at','closed_by'];
        $cols = [];
        if (!$tablesExist) {
            $cols = $colsV2;
        } else {
            $cols = table_columns($pdo, 'hrl_reg_alkes_cases');
            if (!$cols) {
                $cols = $colsV2;
            }
        }

        // Columns introduced in v2 (safe to add to v1)
        ensure_case_column($pdo, $cols, 'status', "VARCHAR(20) NOT NULL DEFAULT 'OPEN'");
        ensure_case_column($pdo, $cols, 'manufacture_name', "VARCHAR(200) NULL");
        ensure_case_column($pdo, $cols, 'is_oem', "TINYINT(1) NOT NULL DEFAULT 0");
        ensure_case_column($pdo, $cols, 'stage_code', "VARCHAR(40) NULL");
        ensure_case_column($pdo, $cols, 'oss_pb_umku', "VARCHAR(120) NULL");
        ensure_case_column($pdo, $cols, 'regalkes_ref', "VARCHAR(120) NULL");
        ensure_case_column($pdo, $cols, 'nie_issue_date', "DATE NULL");
        ensure_case_column($pdo, $cols, 'nie_file_rel', "VARCHAR(255) NULL");
        ensure_case_column($pdo, $cols, 'sku_imported_count', "INT NOT NULL DEFAULT 0");
        ensure_case_column($pdo, $cols, 'sku_last_import_at', "DATETIME NULL");
        ensure_case_column($pdo, $cols, 'closed_at', "DATETIME NULL");
        ensure_case_column($pdo, $cols, 'closed_by', "VARCHAR(64) NULL");

        // --- Data migration (best-effort) ---
        // v1: is_closed -> status
        if (has_col($cols, 'is_closed')) {
            $pdo->exec("UPDATE hrl_reg_alkes_cases
                      SET status = IF(is_closed=1,'CLOSED','OPEN')
                      WHERE status IS NULL OR status='' OR status NOT IN ('OPEN','CLOSED')");
        }

        // v1: nie_issued_date -> nie_issue_date
        if (has_col($cols, 'nie_issued_date')) {
            $pdo->exec("UPDATE hrl_reg_alkes_cases
                      SET nie_issue_date = nie_issued_date
                      WHERE (nie_issue_date IS NULL OR nie_issue_date='0000-00-00')
                        AND nie_issued_date IS NOT NULL");
        }

        // Fill manufacture_id + manufacture_name from master_manufactures (match by manufacture_code)
        if (has_col($cols, 'manufacture_id')) {
            $pdo->exec("UPDATE hrl_reg_alkes_cases c
                      INNER JOIN master_manufactures m
                        ON CONVERT(m.manufacture_code USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(c.manufacture_code USING utf8mb4) COLLATE utf8mb4_general_ci
                      SET c.manufacture_id = m.id
                      WHERE c.manufacture_code IS NOT NULL
                        AND c.manufacture_code <> ''
                        AND (c.manufacture_id IS NULL OR c.manufacture_id=0 OR c.manufacture_id<>m.id)");
        }
        if (has_col($cols, 'manufacture_name')) {
            $pdo->exec("UPDATE hrl_reg_alkes_cases c
                      INNER JOIN master_manufactures m
                        ON CONVERT(m.manufacture_code USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(c.manufacture_code USING utf8mb4) COLLATE utf8mb4_general_ci
                      SET c.manufacture_name = m.manufacture_name
                      WHERE c.manufacture_code IS NOT NULL
                        AND c.manufacture_code <> ''
                        AND (c.manufacture_name IS NULL OR c.manufacture_name='' OR
                             CONVERT(c.manufacture_name USING utf8mb4) COLLATE utf8mb4_general_ci <>
                             CONVERT(m.manufacture_name USING utf8mb4) COLLATE utf8mb4_general_ci)");
        }

        // Fill stage_code + next_pic_dept based on stage_no
        if (has_col($cols, 'stage_code')) {
            $cases = [];
            foreach ($STAGES as $no => $s) {
                $no_i = (int)$no;
                $code = addslashes((string)($s['code'] ?? ''));
                if ($code !== '') $cases[] = "WHEN {$no_i} THEN '{$code}'";
            }
            if ($cases) {
                $caseSql = 'CASE stage_no ' . implode(' ', $cases) . " ELSE stage_code END";
                $pdo->exec("UPDATE hrl_reg_alkes_cases
                          SET stage_code = {$caseSql}
                          WHERE stage_code IS NULL OR stage_code=''");
            }
        }
        if (has_col($cols, 'next_pic_dept')) {
            $cases = [];
            foreach ($STAGES as $no => $s) {
                $no_i = (int)$no;
                $next = addslashes((string)($s['next'] ?? ''));
                if ($next !== '') $cases[] = "WHEN {$no_i} THEN '{$next}'";
            }
            if ($cases) {
                $caseSql = 'CASE stage_no ' . implode(' ', $cases) . " ELSE next_pic_dept END";
                $pdo->exec("UPDATE hrl_reg_alkes_cases
                          SET next_pic_dept = {$caseSql}
                          WHERE next_pic_dept IS NULL OR next_pic_dept=''");
            }
        }

        return [$ok, $err, $cols];
    } catch (\Throwable $e) {
        $ok = false;
        $err = $e->getMessage();
        try {
            $cols = table_columns($pdo, 'hrl_reg_alkes_cases');
        } catch (\Throwable $e2) {
            $cols = ['id','case_code','manufacture_id','manufacture_code','manufacture_name','product_name','status','stage_no','stage_code'];
        }
        return [$ok, $err, $cols ?: []];
    }
}

$STAGES = stage_defs();
[$tables_ok, $tables_err, $CASE_COLS] = migrate_case_table($pdo, $STAGES);

$HAS_STATUS = has_col($CASE_COLS, 'status');
$HAS_IS_CLOSED = has_col($CASE_COLS, 'is_closed');
$HAS_CLOSED_AT = has_col($CASE_COLS, 'closed_at');
$HAS_CLOSED_BY = has_col($CASE_COLS, 'closed_by');
$HAS_UPDATED_BY = has_col($CASE_COLS, 'updated_by');

// Manufactures list
$manufactures = [];
try {
    $stmt = $pdo->query("SELECT id, manufacture_code, manufacture_name, status FROM master_manufactures ORDER BY manufacture_name ASC");
    $manufactures = $stmt->fetchAll() ?: [];
} catch (\Throwable $e) {
    // ignore
}

// Handle actions
$message = '';
$errors = [];

// create_case feedback (PRG)
$created_case_id = (int)($_GET['created_id'] ?? 0);
$created_case_code = (string)($_GET['created'] ?? '');

// Guard: jika POST body terlalu besar, PHP bisa mengosongkan $_POST/$_FILES tanpa error.
// Ini bikin user merasa "klik Buat Case tapi tidak terjadi apa-apa".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    $cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $pm = (string)ini_get('post_max_size');
    $um = (string)ini_get('upload_max_filesize');
    if ($cl > 0) {
        $errors[] = "Form POST kosong (data tidak terbaca). Biasanya karena file upload terlalu besar sehingga melewati limit PHP. "
            . "Content-Length={$cl} bytes. Limit: post_max_size={$pm}, upload_max_filesize={$um}. "
            . "Solusi: buat case tanpa upload file dulu (upload dokumen di halaman Case), atau naikkan limit php.ini lalu restart server.";
    } else {
        $errors[] = "Form POST kosong. Coba refresh dan submit ulang.";
    }
}


$uploads_manu = project_root() . '/master/uploads/manufactures';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action !== '') csrf_check();

    if ($action === 'create_case') {
        $manu_id = (int)($_POST['manufacture_id'] ?? 0);
        $product_name = trim((string)($_POST['product_name'] ?? ''));
        $is_oem = isset($_POST['is_oem']) ? 1 : 0;
        $note = trim((string)($_POST['note'] ?? ''));

        $pqp_err = (isset($_FILES['pqp_file']) && is_array($_FILES['pqp_file']))
            ? (int)($_FILES['pqp_file']['error'] ?? UPLOAD_ERR_NO_FILE)
            : UPLOAD_ERR_NO_FILE;
        $catalog_err = (isset($_FILES['catalog_file']) && is_array($_FILES['catalog_file']))
            ? (int)($_FILES['catalog_file']['error'] ?? UPLOAD_ERR_NO_FILE)
            : UPLOAD_ERR_NO_FILE;

        if ($pqp_err !== UPLOAD_ERR_NO_FILE && $pqp_err !== UPLOAD_ERR_OK) {
            $errors[] = "Upload File PQP gagal: " . upload_err_text($pqp_err)
                . " (cek limit upload_max_filesize/post_max_size).";
        }
        if ($catalog_err !== UPLOAD_ERR_NO_FILE && $catalog_err !== UPLOAD_ERR_OK) {
            $errors[] = "Upload Catalog gagal: " . upload_err_text($catalog_err)
                . " (cek limit upload_max_filesize/post_max_size).";
        }

        $pqp_ok = ($pqp_err === UPLOAD_ERR_OK);
        $catalog_ok = ($catalog_err === UPLOAD_ERR_OK);

        // Jika nama produk kosong, auto-isi dari nama file PQP (misal: "File Aksesoris - Produk XYZ.xlsx")
        if ($product_name === '' && $pqp_ok) {
            $product_name = derive_product_name_from_filename((string)($_FILES['pqp_file']['name'] ?? ''));
        }

        if ($manu_id <= 0) $errors[] = "Manufacture wajib dipilih.";
        if ($product_name === '') $errors[] = "Nama produk/case wajib diisi (atau upload File PQP).";

        if ($pqp_ok) {
            $ext = strtolower(pathinfo((string)($_FILES['pqp_file']['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx','csv'], true)) $errors[] = "File PQP harus .xlsx atau .csv.";
        }
        if ($catalog_ok) {
            $ext = strtolower(pathinfo((string)($_FILES['catalog_file']['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf','zip'], true)) $errors[] = "File Catalog sebaiknya PDF (atau ZIP).";
        }

        if (!$errors) {
            $m = $pdo->prepare("SELECT id, manufacture_code, manufacture_name FROM master_manufactures WHERE id=? LIMIT 1");
            $m->execute([$manu_id]);
            $mr = $m->fetch();
            if (!$mr) $errors[] = "Manufacture tidak ditemukan.";
            else {
                $stage_no = 1;
                $stage_code = $STAGES[$stage_no]['code'] ?? 'PQP_FIND_PRINCIPAL';
                $next = $STAGES[$stage_no]['next'] ?? 'PQP';
                $tmp_code = 'TMP-' . bin2hex(random_bytes(4));

                // Build insert dynamically to support older schema (v1) and upgraded schema (v2)
                $user = (string)($_SESSION['username'] ?? '');
                $cols = table_columns($pdo, 'hrl_reg_alkes_cases');
                $fields = [];
                $holders = [];
                $vals = [];

                $add = function(string $f, $v) use (&$fields,&$holders,&$vals,$cols) {
                    if (!in_array($f, $cols, true)) return;
                    $fields[] = $f;
                    $holders[] = '?';
                    $vals[] = $v;
                };

                $add('case_code', $tmp_code);
                $add('manufacture_id', $manu_id);
                $add('manufacture_code', (string)$mr['manufacture_code']);
                $add('manufacture_name', (string)$mr['manufacture_name']);
                $add('product_name', $product_name);
                $add('is_oem', $is_oem);
                $add('stage_no', $stage_no);
                $add('stage_code', $stage_code);
                $add('next_pic_dept', $next);
                $add('status', 'OPEN');
                $add('is_closed', 0);
                $add('note', $note);
                $add('created_by', $user);
                $add('updated_by', $user);

                $sqlIns = "INSERT INTO hrl_reg_alkes_cases (" . implode(',', $fields) . ") VALUES (" . implode(',', $holders) . ")";
                $ins = $pdo->prepare($sqlIns);
                $ins->execute($vals);
                $id = (int)$pdo->lastInsertId();

                $case_code = 'RAK-' . date('ymd') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("UPDATE hrl_reg_alkes_cases SET case_code=? WHERE id=?")->execute([$case_code, $id]);

                if (function_exists('reg_alkes_stage_log_enter')) {
                    reg_alkes_stage_log_enter($pdo, $id, 1);
                }

                // Create dossier folder per case
                $case_dir = $uploads_manu . '/' . $mr['manufacture_code'] . '/hrl/cases/' . $case_code;
                ensure_dir($case_dir);

                // Optional: upload File PQP + Catalog langsung saat buat case
                $docIns = null;
                try {
                    $docIns = $pdo->prepare("INSERT INTO hrl_reg_alkes_case_docs (case_id, doc_type, file_name, file_rel, note, uploaded_by)
                        VALUES (?,?,?,?,?,?)");
                } catch (Throwable $e) { $docIns = null; }

                $base_rel_dir = 'master/uploads/manufactures/' . $mr['manufacture_code'] . '/hrl/cases/' . $case_code;

                if ($pqp_ok) {
                    $orig = (string)($_FILES['pqp_file']['name'] ?? '');
                    $base = safe_filename('AKSESORIS_' . date('Ymd_His') . '_' . $orig);
                    if ($base === '') $base = 'AKSESORIS_' . date('Ymd_His') . '.xlsx';
                    $dest = $case_dir . '/' . $base;
                    if (@move_uploaded_file((string)($_FILES['pqp_file']['tmp_name'] ?? ''), $dest)) {
                        if ($docIns) {
                            $file_rel = $base_rel_dir . '/' . $base;
                            $docIns->execute([$id, 'AKSESORIS_LIST', $base, $file_rel, 'PQP aksesoris list', $user]);
                        }
                    }
                }

                if ($catalog_ok) {
                    $orig = (string)($_FILES['catalog_file']['name'] ?? '');
                    $base = safe_filename('CATALOG_' . date('Ymd_His') . '_' . $orig);
                    if ($base === '') $base = 'CATALOG_' . date('Ymd_His') . '.pdf';
                    $dest = $case_dir . '/' . $base;
                    if (@move_uploaded_file((string)($_FILES['catalog_file']['tmp_name'] ?? ''), $dest)) {
                        if ($docIns) {
                            $file_rel = $base_rel_dir . '/' . $base;
                            $docIns->execute([$id, 'CATALOG', $base, $file_rel, 'Catalog principal', $user]);
                        }
                    }
                }

                // Redirect back to tower so the new case is visible in list (avoid double submit on refresh)
                $redirect = "reg_alkes_control_tower.php?status=OPEN"
                    . "&manu=" . urlencode((string)$manu_id)
                    . "&q=" . urlencode($case_code)
                    . "&created_id=" . urlencode((string)$id)
                    . "&created=" . urlencode($case_code);

                if (!headers_sent()) {
                    rmi_redirect($redirect);
                }
                // Fallback: if header already sent, show feedback on same page
                $created_case_id = $id;
                $created_case_code = $case_code;
                $message = "Case berhasil dibuat: {$case_code}";
            }
        }
    }

    if ($action === 'quick_next') {
        // IMPORTANT: progression is gated in reg_alkes_case.php.
        // Do not allow Control Tower to bypass CATALOG/QUOTATION/PKS/LOA/
        // dossier/OSS-Regalkes/NIE validation. Redirect to the case detail.
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT id FROM hrl_reg_alkes_cases WHERE id=? LIMIT 1");
            $row->execute([$id]);
            if ($row->fetchColumn()) {
                rmi_redirect('reg_alkes_case.php?id=' . urlencode((string)$id));
            }
        }
        $errors[] = "Case tidak ditemukan.";
    }

    if ($action === 'quick_close') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $user = (string)($_SESSION['username'] ?? '');
            $set = [];
            $params_u = [];
            if ($HAS_STATUS) $set[] = "status='CLOSED'";
            if ($HAS_IS_CLOSED) $set[] = "is_closed=1";
            if ($HAS_CLOSED_AT) $set[] = "closed_at=NOW()";
            if ($HAS_CLOSED_BY) { $set[] = "closed_by=?"; $params_u[] = $user; }
            if ($HAS_UPDATED_BY) { $set[] = "updated_by=?"; $params_u[] = $user; }
            if (!$set) $set[] = "updated_at=updated_at"; // noop
            $params_u[] = $id;
            $row = $pdo->prepare("SELECT case_code FROM hrl_reg_alkes_cases WHERE id=?");
            $row->execute([$id]);
            $cr = $row->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("UPDATE hrl_reg_alkes_cases SET " . implode(', ', $set) . " WHERE id=?")
                ->execute($params_u);
            if (function_exists('reg_alkes_stage_log_close_current')) {
                reg_alkes_stage_log_close_current($pdo, $id);
            }
            if (function_exists('master_audit') && $cr) {
                master_audit($pdo, 'hrl_reg_alkes_control_tower', 'hrl_reg_alkes_cases', 'QUICK_CLOSE', $id, $cr['case_code'] ?? '', "Case closed: {$cr['case_code']}", []);
            }
            $message = "Case ditutup.";
        }
    }

    if ($action === 'quick_reopen') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $user = (string)($_SESSION['username'] ?? '');
            $set = [];
            $params_u = [];
            if ($HAS_STATUS) $set[] = "status='OPEN'";
            if ($HAS_IS_CLOSED) $set[] = "is_closed=0";
            if ($HAS_CLOSED_AT) $set[] = "closed_at=NULL";
            if ($HAS_CLOSED_BY) $set[] = "closed_by=NULL";
            if ($HAS_UPDATED_BY) { $set[] = "updated_by=?"; $params_u[] = $user; }
            if (!$set) $set[] = "updated_at=updated_at"; // noop
            $params_u[] = $id;
            $row = $pdo->prepare("SELECT case_code, stage_no FROM hrl_reg_alkes_cases WHERE id=?");
            $row->execute([$id]);
            $cr = $row->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("UPDATE hrl_reg_alkes_cases SET " . implode(', ', $set) . " WHERE id=?")
                ->execute($params_u);
            if (function_exists('reg_alkes_stage_log_enter') && $cr) {
                reg_alkes_stage_log_enter($pdo, $id, (int)($cr['stage_no'] ?? 1));
            }
            if (function_exists('master_audit') && $cr) {
                master_audit($pdo, 'hrl_reg_alkes_control_tower', 'hrl_reg_alkes_cases', 'QUICK_REOPEN', $id, $cr['case_code'] ?? '', "Case reopened: {$cr['case_code']}", []);
            }
            $message = "Case dibuka kembali.";
        }
    }
}

// Filters (must be before funnel query)
$f_status = strtoupper(trim((string)($_GET['status'] ?? 'OPEN')));

// Funnel: count per stage (sesuai filter status)
$funnelStageCount = [];
for ($i = 1; $i <= 15; $i++) {
    $funnelStageCount[$i] = 0;
}
if ($tables_ok) {
    try {
        $funnelWhere = "WHERE 1=1";
        $funnelParams = [];
        if ($f_status !== '' && $f_status !== 'ALL') {
            if ($HAS_STATUS) {
                $funnelWhere .= " AND UPPER(COALESCE(status,'OPEN')) = ?";
                $funnelParams[] = $f_status;
            } elseif ($HAS_IS_CLOSED) {
                $funnelWhere .= " AND is_closed = ?";
                $funnelParams[] = ($f_status === 'CLOSED') ? 1 : 0;
            }
        }
        $stF = $pdo->prepare("SELECT stage_no, COUNT(*) AS cnt FROM hrl_reg_alkes_cases {$funnelWhere} GROUP BY stage_no");
        $stF->execute($funnelParams);
        while ($r = $stF->fetch(PDO::FETCH_ASSOC)) {
            $sn = (int)($r['stage_no'] ?? 0);
            if ($sn >= 1 && $sn <= 15) {
                $funnelStageCount[$sn] = (int)($r['cnt'] ?? 0);
            }
        }
    } catch (Throwable $e) { /* fail-soft */ }
}
$funnelMax = max(1, max($funnelStageCount));

// Avg days per stage (dari stage_log, jika tabel ada)
$funnelAvgDays = [];
for ($i = 1; $i <= 15; $i++) $funnelAvgDays[$i] = null;
try {
    $chkLog = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_case_stage_log'");
    if ($chkLog && $chkLog->fetch()) {
        $stAvg = $pdo->query("SELECT stage_no, AVG(DATEDIFF(COALESCE(exited_at, NOW()), entered_at)) AS avg_days
            FROM hrl_reg_alkes_case_stage_log
            WHERE exited_at IS NOT NULL
            GROUP BY stage_no");
        while ($r = $stAvg->fetch(PDO::FETCH_ASSOC)) {
            $sn = (int)($r['stage_no'] ?? 0);
            if ($sn >= 1 && $sn <= 15 && $r['avg_days'] !== null) {
                $funnelAvgDays[$sn] = round((float)$r['avg_days'], 1);
            }
        }
    }
} catch (Throwable $e) { /* fail-soft */ }
$f_stage = (int)($_GET['stage'] ?? 0);
$f_manu = (int)($_GET['manu'] ?? 0);
$f_q = trim((string)($_GET['q'] ?? ''));

$where = "WHERE 1=1";
$params = [];

if ($f_status !== '' && $f_status !== 'ALL') {
    if ($HAS_STATUS) {
        $where .= " AND UPPER(COALESCE(c.status,'OPEN')) = ?";
        $params[] = $f_status;
    } elseif ($HAS_IS_CLOSED) {
        $where .= " AND c.is_closed = ?";
        $params[] = ($f_status === 'CLOSED') ? 1 : 0;
    }
}
if ($f_stage > 0) {
    $where .= " AND c.stage_no = ?";
    $params[] = $f_stage;
}
if ($f_manu > 0) {
    $where .= " AND c.manufacture_id = ?";
    $params[] = $f_manu;
}
if ($f_q !== '') {
    $like = '%' . $f_q . '%';
    $where .= " AND (c.case_code LIKE ? OR c.product_name LIKE ? OR c.manufacture_name LIKE ? OR c.nie_no LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}

// Case IDs yang punya dokumen dari Manufacturer Portal
$portalCaseIds = [];
try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($db !== '' && $db !== false) {
        $chk = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=? AND table_name='hrl_reg_alkes_case_docs' AND column_name='uploaded_via'");
        $chk->execute([$db]);
        if ($chk->fetch()) {
            $st = $pdo->query("SELECT DISTINCT case_id FROM hrl_reg_alkes_case_docs WHERE uploaded_via='portal'");
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $portalCaseIds[(int)$r['case_id']] = true;
            }
        }
    }
} catch (Throwable $e) {}

// Cases list
$cases = [];
if ($tables_ok) {
    $statusExpr = $HAS_STATUS
        ? "UPPER(COALESCE(c.status,'OPEN'))"
        : ($HAS_IS_CLOSED ? "IF(c.is_closed=1,'CLOSED','OPEN')" : "'OPEN'");
    $sql = "SELECT c.*,
        {$statusExpr} AS status_norm,
        COALESCE(pc.cnt, 0) AS sku_cnt
        FROM hrl_reg_alkes_cases c
        LEFT JOIN (
            SELECT manufacture_id, akl_reg_no AS nie_no, COUNT(*) AS cnt
            FROM master_products
            WHERE akl_reg_no IS NOT NULL AND akl_reg_no <> ''
            GROUP BY manufacture_id, akl_reg_no
        ) pc ON pc.manufacture_id = c.manufacture_id AND pc.nie_no COLLATE utf8mb4_general_ci = c.nie_no
        $where
        ORDER BY c.updated_at DESC, c.id DESC
        LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cases = $stmt->fetchAll() ?: [];
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('REG Alkes Control Tower (v2)', [
  'active' => 'hrl_reg_alkes',
  'breadcrumbs' => [
    ['label' => 'HRL Reg Alkes', 'url' => $baseProject . '/hrl_reg_alkes/index.php'],
    'REG Alkes Control Tower (v2)',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .small2 { font-size: .88rem; }
    .badge-soft { background: rgba(13,110,253,.10); color: #0d6efd; }
    .overdue { background: rgba(220,53,69,.10); }</style>',
]);
?>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">REG Alkes Control Tower <span class="text-muted small">(v2)</span></h3>
      <div class="text-muted small">Tracking Step 1–15 + per-case dossier folder + tombol menuju Import SKU → master_products</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="../master/master_dashboard.php">Master</a>
      <a class="btn btn-outline-primary" href="reg_alkes.php">HRL Import SKU</a>
      <a class="btn btn-outline-info" href="<?= rmi_h($baseProject) ?>/manufacturer_portal/login.php" target="_blank">Manufacturer Portal</a>
    </div>
  </div>

  <?php if (!$tables_ok): ?>
    <div class="alert alert-danger">
      <div class="fw-semibold">Tabel Control Tower belum bisa dibuat otomatis.</div>
      <div class="small">Error: <?=h($tables_err)?></div>
      <div class="small mt-2">Solusi: jalankan installer SQL di folder <span class="mono">hrl_reg_alkes/_sql</span> (ada di patch ini).</div>
    </div>
  <?php endif; ?>

  <?php if ($created_case_id > 0): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center">
      <div>
        <div class="fw-semibold">Case berhasil dibuat</div>
        <div class="mono"><?=h($created_case_code ?: ('ID #' . $created_case_id))?></div>
      </div>
      <a class="btn btn-sm btn-outline-primary" href="reg_alkes_case.php?id=<?=h((string)$created_case_id)?>">Open</a>
    </div>
  <?php endif; ?>

  <?php if ($message): ?>
    <div class="alert alert-success"><?=h($message)?></div>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?=h($e)?></div>
  <?php endforeach; ?>

  <?php if (array_sum($funnelStageCount) > 0): ?>
  <div class="card mb-3">
    <div class="card-body py-2">
      <div class="small text-muted mb-2">Funnel (<?= h($f_status === 'ALL' ? 'Semua' : $f_status) ?>) — <?= array_sum($funnelStageCount) ?> case</div>
      <div class="row g-1">
        <?php for ($i = 1; $i <= 15; $i++):
          $cnt = (int)($funnelStageCount[$i] ?? 0);
          $pct = $funnelMax > 0 ? min(100, ($cnt / $funnelMax) * 100) : 0;
          $label = $STAGES[$i]['label'] ?? ("Stage {$i}");
        ?>
        <div class="col-12 col-md-6 col-lg-4">
          <div class="d-flex align-items-center gap-2 small">
            <span class="text-nowrap" style="width: 28px;"><?= $i ?>.</span>
            <div class="progress flex-grow-1" style="height: 10px;">
              <div class="progress-bar bg-primary" role="progressbar" style="width: <?= (int)$pct ?>%;" title="<?= h($label) ?>"></div>
            </div>
            <span class="fw-semibold" style="min-width: 20px;"><?= $cnt ?></span>
            <?php if ($funnelAvgDays[$i] !== null): ?>
            <span class="text-muted" style="font-size: 0.75rem;"><?= $funnelAvgDays[$i] ?>d</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (['OPEN'=>'OPEN','CLOSED'=>'CLOSED','ALL'=>'ALL'] as $k=>$v): ?>
              <option value="<?=h($k)?>" <?=($f_status===$k?'selected':'')?>><?=h($v)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Stage</label>
          <select name="stage" class="form-select">
            <option value="0">-- semua --</option>
            <?php foreach ($STAGES as $no=>$s): ?>
              <option value="<?=h((string)$no)?>" <?=($f_stage===$no?'selected':'')?>><?=h($s['label'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Manufacture</label>
          <select name="manu" class="form-select">
            <option value="0">-- semua --</option>
            <?php foreach ($manufactures as $m): ?>
              <option value="<?=h((string)$m['id'])?>" <?=($f_manu===(int)$m['id']?'selected':'')?>>
                <?=h($m['manufacture_name'])?> (<?=h($m['manufacture_code'])?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Cari</label>
          <input name="q" class="form-control" value="<?=h($f_q)?>" placeholder="case / product / NIE / manufacture">
        </div>
        <div class="col-md-1 d-grid">
          <button class="btn btn-primary">Filter</button>
        </div>
      </form>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="text-muted small">Menampilkan: <b><?=count($cases)?></b> case (max 500)</div>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAdd">+ Buat Case</button>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Case</th>
            <th>Manufacture</th>
            <th>Produk</th>
            <th>Stage</th>
            <th>Next</th>
            <th>Revisi</th>
            <th>NIE</th>
            <th class="text-end">SKU</th>
            <th class="text-end">Updated</th>
            <th class="text-end">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cases as $c): ?>
            <?php
              $stage_no = (int)$c['stage_no'];
              $stage_label = $STAGES[$stage_no]['label'] ?? ('Stage '.$stage_no);
              $is_overdue = false;
              $statusNorm = strtoupper((string)($c['status_norm'] ?? $c['status'] ?? 'OPEN'));
              if (!empty($c['revision_deadline']) && $statusNorm==='OPEN') {
                  $is_overdue = (strtotime((string)$c['revision_deadline']) < strtotime(date('Y-m-d')));
              }
            ?>
            <tr class="<?=($is_overdue?'overdue':'')?>">
              <td>
                <div class="mono fw-semibold"><?=h($c['case_code'])?><?php if (!empty($portalCaseIds[(int)$c['id']])): ?> <span class="badge bg-info" title="Ada dokumen dari Manufacturer Portal">Portal</span><?php endif; ?></div>
                <div class="text-muted small2">#<?=h((string)$c['id'])?> • <?=h($statusNorm)?></div>
              </td>
              <td>
                <div><?=h($c['manufacture_name'])?></div>
                <div class="text-muted small2 mono"><?=h($c['manufacture_code'])?></div>
              </td>
              <td>
                <div><?=h(strtoupper($c['product_name'] ?? ''))?></div>
                <?php if ((int)$c['is_oem']===1): ?>
                  <span class="badge text-bg-warning">OEM</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge badge-soft"><?=h($stage_label)?></span>
              </td>
              <td><span class="badge text-bg-secondary"><?=h($c['next_pic_dept'])?></span></td>
              <td>
                <div class="small2">count: <b><?=h((string)$c['revision_count'])?></b></div>
                <?php if (!empty($c['revision_deadline'])): ?>
                  <div class="small2 <?=($is_overdue?'text-danger fw-semibold':'text-muted')?>">dl: <?=h((string)$c['revision_deadline'])?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($c['nie_no'])): ?>
                  <div class="mono fw-semibold"><?=h($c['nie_no'])?></div>
                  <div class="text-muted small2"><?=h((string)$c['nie_type'])?> <?=h((string)$c['nie_issue_date'])?></div>
                <?php else: ?>
                  <span class="text-muted small2">-</span>
                <?php endif; ?>
              </td>
              <td class="text-end"><span class="mono"><?=h((string)$c['sku_cnt'])?></span></td>
              <td class="text-end small2 text-muted"><?=h((string)$c['updated_at'])?></td>
              <td class="text-end">
                <div class="d-flex gap-1 justify-content-end">
                  <a class="btn btn-outline-primary btn-sm" href="reg_alkes_case.php?id=<?=h((string)$c['id'])?>">Open</a>
                  <?php if ($statusNorm==='OPEN'): ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="quick_next">
                      <input type="hidden" name="id" value="<?=h((string)$c['id'])?>">
                      <button class="btn btn-outline-success btn-sm" title="Buka case untuk validasi gate sebelum naik stage">Next</button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Tutup case ini?');">
                      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="quick_close">
                      <input type="hidden" name="id" value="<?=h((string)$c['id'])?>">
                      <button class="btn btn-outline-danger btn-sm">Close</button>
                    </form>
                  <?php else: ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="quick_reopen">
                      <input type="hidden" name="id" value="<?=h((string)$c['id'])?>">
                      <button class="btn btn-outline-secondary btn-sm">Re-open</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$cases): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Belum ada data case.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="text-muted small mt-3">
    Catatan: Stage <b>11</b> (Revisi) otomatis set deadline <b>+10 hari</b> dan hanya boleh <b>1x</b>.
    Setelah NIE terbit, klik <b>Open</b> → tombol <b>Import SKU</b> akan mengarah ke <span class="mono">hrl_reg_alkes/reg_alkes.php</span>.
  </div>
</div>

<!-- Modal Add Case -->
<div class="modal fade" id="modalAdd" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="post" enctype="multipart/form-data" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="create_case">
      <div class="modal-header">
        <h5 class="modal-title">Buat Case Registrasi</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Manufacture (Principal)</label>
            <select name="manufacture_id" class="form-select" required>
              <option value="">-- pilih --</option>
              <?php foreach ($manufactures as $m): ?>
                <option value="<?=h((string)$m['id'])?>"><?=h($m['manufacture_name'])?> (<?=h($m['manufacture_code'])?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Nama Produk / Judul Case <span class="text-muted small">(boleh kosong jika upload File PQP)</span></label>
            <input name="product_name" class="form-control" placeholder="contoh: Ventilator XYZ / Gloves ABC (atau kosongkan agar auto dari file)">
          </div>
                    <div class="col-md-6">
            <label class="form-label">File PQP (Aksesoris SKU) <span class="text-muted small">.xlsx/.csv</span></label>
            <input type="file" name="pqp_file" class="form-control" accept=".xlsx,.csv">
            <div class="form-text">Jika nama produk kosong, sistem akan ambil judul dari nama file ini.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Catalog <span class="text-muted small">(PDF/ZIP, opsional)</span></label>
            <input type="file" name="catalog_file" class="form-control" accept=".pdf,.zip">
            <div class="form-text">Bisa upload di sini atau nanti di halaman Case.</div>
          </div>

<div class="col-md-6">
            <div class="form-check mt-4">
              <input class="form-check-input" type="checkbox" name="is_oem" id="is_oem">
              <label class="form-check-label" for="is_oem">Produk OEM</label>
            </div>
          </div>
          <div class="col-md-12">
            <label class="form-label">Catatan</label>
            <textarea name="note" class="form-control" rows="2" placeholder="opsional"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success">Buat Case</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<?php rmi_footer(); ?>
