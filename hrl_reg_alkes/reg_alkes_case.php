<?php
// hrl_reg_alkes/reg_alkes_case.php
// Control Tower v2 - case detail: stage update, docs, templates, SKU link
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

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
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_stage_log_helper.php';
$baseProject = rmi_layout_base_project();

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

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
function stage_defs(): array {
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

function manu_doc_path_matches_case(array $doc, array $case): bool {
    $code = trim((string)($case['manufacture_code'] ?? ''));
    if ($code === '') return false;

    $rel = str_replace('\\', '/', ltrim((string)($doc['file_rel'] ?? ''), '/'));
    if ($rel === '') return false;

    $codeQ = preg_quote($code, '#');
    return (bool)preg_match(
        '#^(?:master/)?uploads/manufactures/' . $codeQ . '/hrl/manufactures_docs/#i',
        $rel
    );
}

function ensure_case_column(PDO $pdo, array &$cols, string $col, string $ddl): void {
    if (has_col($cols, $col)) return;
    $pdo->exec("ALTER TABLE hrl_reg_alkes_cases ADD COLUMN {$col} {$ddl}");
    $cols[] = $col;
}

function migrate_case_table(PDO $pdo, array $STAGES): array {
    // Create tables if missing, then *upgrade* v1 schema to v2 schema (best effort).
    $ok = true;
    $err = '';
    try {
        $pdo->exec("SET collation_connection = 'utf8mb4_general_ci'");
        $tablesExist = false;
        try {
            $pdo->query("SELECT 1 FROM hrl_reg_alkes_cases LIMIT 1");
            $tablesExist = true;
        } catch (Throwable $e) {
            $tablesExist = false;
        }
        if (!$tablesExist) {
        $pdo->exec("CREATE TABLE hrl_reg_alkes_cases (
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
            nie_expiry_date DATE DEFAULT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

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
        } else {
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

        $cols = table_columns($pdo, 'hrl_reg_alkes_cases');
        if (!$cols) {
            return [false, 'Tidak bisa membaca struktur tabel hrl_reg_alkes_cases (permission?).', []];
        }

        // Columns introduced in v2 (safe to add to v1)
        ensure_case_column($pdo, $cols, 'status', "VARCHAR(20) NOT NULL DEFAULT 'OPEN'");
        ensure_case_column($pdo, $cols, 'manufacture_name', "VARCHAR(200) NULL");
        ensure_case_column($pdo, $cols, 'is_oem', "TINYINT(1) NOT NULL DEFAULT 0");
        ensure_case_column($pdo, $cols, 'stage_code', "VARCHAR(40) NULL");
        ensure_case_column($pdo, $cols, 'oss_pb_umku', "VARCHAR(120) NULL");
        ensure_case_column($pdo, $cols, 'regalkes_ref', "VARCHAR(120) NULL");
        ensure_case_column($pdo, $cols, 'nie_issue_date', "DATE NULL");
        ensure_case_column($pdo, $cols, 'nie_expiry_date', "DATE NULL");
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

        // Legacy compatibility:
        // older UI used nie_issue_date to store the NIE validity/expiry date.
        // If that value is still in the future and the canonical expiry field is empty,
        // copy it to nie_expiry_date. The original value is preserved for safety.
        if (has_col($cols, 'nie_expiry_date') && has_col($cols, 'nie_issue_date')) {
            $pdo->exec("UPDATE hrl_reg_alkes_cases
                      SET nie_expiry_date = nie_issue_date
                      WHERE (nie_expiry_date IS NULL OR nie_expiry_date='0000-00-00')
                        AND nie_issue_date IS NOT NULL
                        AND nie_issue_date >= CURDATE()");
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

function ensure_tables(PDO $pdo): void {
    // create if not exists (best effort)
    $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_reg_alkes_cases (
        id INT NOT NULL AUTO_INCREMENT,
        case_code VARCHAR(40) NOT NULL,
        manufacture_id INT NOT NULL,
        manufacture_code VARCHAR(60) NOT NULL,
        manufacture_name VARCHAR(200) NOT NULL,
        product_name VARCHAR(200) NOT NULL,
        is_oem TINYINT(1) NOT NULL DEFAULT 0,
        stage_no INT NOT NULL DEFAULT 1,
        stage_code VARCHAR(40) NOT NULL DEFAULT 'PQP_FIND_PRINCIPAL',
        next_pic_dept VARCHAR(10) NOT NULL DEFAULT 'PQP',
        revision_count TINYINT NOT NULL DEFAULT 0,
        revision_deadline DATE DEFAULT NULL,
        oss_pb_umku VARCHAR(120) DEFAULT NULL,
        regalkes_ref VARCHAR(120) DEFAULT NULL,
        nie_type VARCHAR(10) DEFAULT NULL,
        nie_no VARCHAR(150) DEFAULT NULL,
        nie_issue_date DATE DEFAULT NULL,
        nie_expiry_date DATE DEFAULT NULL,
        nie_file_rel VARCHAR(255) DEFAULT NULL,
        sku_imported_count INT NOT NULL DEFAULT 0,
        sku_last_import_at DATETIME DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
        note TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(50) DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by VARCHAR(50) DEFAULT NULL,
        closed_at DATETIME DEFAULT NULL,
        closed_by VARCHAR(50) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_case_code (case_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_reg_alkes_case_docs (
        id INT NOT NULL AUTO_INCREMENT,
        case_id INT NOT NULL,
        doc_type VARCHAR(40) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_rel VARCHAR(255) NOT NULL,
        note VARCHAR(255) DEFAULT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        uploaded_by VARCHAR(50) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_case (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_payment_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_reg_alkes_payments (
        id INT NOT NULL AUTO_INCREMENT,
        case_id INT NOT NULL,
        case_code VARCHAR(40) NOT NULL,
        payment_type VARCHAR(50) NOT NULL DEFAULT 'REGISTRATION',
        billing_no VARCHAR(100) DEFAULT NULL,
        invoice_no VARCHAR(100) DEFAULT NULL,
        vendor_name VARCHAR(200) DEFAULT NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        payment_status VARCHAR(30) NOT NULL DEFAULT 'UNPAID',
        invoice_date DATE DEFAULT NULL,
        due_date DATE DEFAULT NULL,
        paid_date DATE DEFAULT NULL,
        proof_file_name VARCHAR(255) DEFAULT NULL,
        proof_file_rel VARCHAR(255) DEFAULT NULL,
        note TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(64) DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL,
        updated_by VARCHAR(64) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_case_id (case_id),
        KEY idx_case_code (case_code),
        KEY idx_payment_status (payment_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$STAGES = stage_defs();
[$tables_ok, $tables_err, $CASE_COLS] = migrate_case_table($pdo, $STAGES);
try {
    ensure_payment_table($pdo);
} catch (Throwable $e) {
    // Payment table is optional at render time; errors are shown only when saving payment.
}

$HAS_STATUS = has_col($CASE_COLS, 'status');
$HAS_IS_CLOSED = has_col($CASE_COLS, 'is_closed');
$HAS_CLOSED_AT = has_col($CASE_COLS, 'closed_at');
$HAS_CLOSED_BY = has_col($CASE_COLS, 'closed_by');
$HAS_UPDATED_BY = has_col($CASE_COLS, 'updated_by');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  rmi_header('Registrasi Alkes - Case', [
  'active' => 'hrl_reg_alkes',
  'breadcrumbs' => [
    ['label' => 'HRL Reg Alkes', 'url' => $baseProject . '/hrl_reg_alkes/index.php'],
    'Registrasi Alkes - Case',
  ],
  'extra_head' => '<style>body{font-family:Arial,Helvetica,sans-serif;padding:24px} .card{max-width:720px;margin:0 auto;border:1px solid #ddd;border-radius:10px;padding:18px}</style>',
]);
?>

    <div class="card">
      <h2>Case ID belum dipilih</h2>
      <p>Halaman ini membutuhkan parameter <code>?id=</code> (case ID).</p>
      <p><a href="reg_alkes_control_tower.php">Kembali ke Control Tower</a> · <a href="reg_alkes.php">Kembali ke Registrasi</a> · <a href="../master/logout.php">Logout</a></p>
    </div>
  <?php
  rmi_footer();
  exit;
}

if (!$tables_ok) {
    http_response_code(500);
    exit('Control Tower tables not ready: ' . $tables_err);
}

$case = null;
$stmt = $pdo->prepare("SELECT * FROM hrl_reg_alkes_cases WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$case = $stmt->fetch();
if (!$case) { http_response_code(404); exit('Case not found'); }

// Normalize status across schema versions
$statusNorm = 'OPEN';
if ($HAS_STATUS) {
    $statusNorm = strtoupper((string)($case['status'] ?? 'OPEN'));
} elseif ($HAS_IS_CLOSED) {
    $statusNorm = ((int)($case['is_closed'] ?? 0) === 1) ? 'CLOSED' : 'OPEN';
}

$uploads_manu = project_root() . '/master/uploads/manufactures';
$case_dir = $uploads_manu . '/' . $case['manufacture_code'] . '/hrl/cases/' . $case['case_code'];
ensure_dir($case_dir);

function download_file(string $fullPath, string $downloadName): void {
    if (!is_file($fullPath)) { http_response_code(404); exit('File not found'); }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($fullPath));
    header('Content-Disposition: attachment; filename="' . str_replace('"','', $downloadName) . '"');
    readfile($fullPath);
    exit;
}

// Download doc (case docs)
if (isset($_GET['dl'])) {
    $doc_id = (int)($_GET['dl'] ?? 0);
    if ($doc_id > 0) {
        $d = $pdo->prepare("SELECT * FROM hrl_reg_alkes_case_docs WHERE id=? AND case_id=? LIMIT 1");
        $d->execute([$doc_id, $id]);
        $doc = $d->fetch();
        if ($doc) {
            $full = project_root() . '/' . ltrim((string)$doc['file_rel'], '/');
            download_file($full, (string)$doc['file_name']);
        }
    }
    http_response_code(404); exit('File not found');
}

// Download manufacture doc (master_manufactures_docs)
if (isset($_GET['dl_manu'])) {
    $manu_doc_id = (int)($_GET['dl_manu'] ?? 0);
    if ($manu_doc_id > 0 && !empty($case['manufacture_id'])) {
        $d = $pdo->prepare("SELECT * FROM master_manufactures_docs WHERE id=? AND manufacture_id=? LIMIT 1");
        $d->execute([$manu_doc_id, (int)$case['manufacture_id']]);
        $doc = $d->fetch();
        if ($doc && manu_doc_path_matches_case($doc, $case)) {
            $full = project_root() . '/' . ltrim((string)($doc['file_rel'] ?? ''), '/');
            if (is_file($full)) {
                download_file($full, (string)($doc['file_name'] ?? basename($full)));
            }
        }
    }
    http_response_code(404); exit('File not found');
}

$message = '';
$errors = [];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_check')) csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $user = (string)($_SESSION['username'] ?? '');

    if ($action === 'update_case') {
        $stage_no = (int)($_POST['stage_no'] ?? (int)$case['stage_no']);
        if ($stage_no < 1) $stage_no = 1;
        if ($stage_no > 15) $stage_no = 15;

        $next_pic = strtoupper(trim((string)($_POST['next_pic_dept'] ?? $case['next_pic_dept'])));
        if ($next_pic === '') $next_pic = $STAGES[$stage_no]['next'] ?? 'PQP';

        $oss = trim((string)($_POST['oss_pb_umku'] ?? ''));
        $reg_ref = trim((string)($_POST['regalkes_ref'] ?? ''));
        $nie_type = strtoupper(trim((string)($_POST['nie_type'] ?? '')));
        if ($nie_type === '') $nie_type = null;
        if ($nie_type !== null && !in_array($nie_type, ['AKL','AKD'], true)) $nie_type = null;

        $nie_no = trim((string)($_POST['nie_no'] ?? ''));
        if ($nie_no === '') $nie_no = null;

        $nie_date = trim((string)($_POST['nie_issue_date'] ?? ''));
        if ($nie_date === '') $nie_date = null;

        $nie_expiry_date = trim((string)($_POST['nie_expiry_date'] ?? ''));
        if ($nie_expiry_date === '') $nie_expiry_date = null;

        $note = trim((string)($_POST['note'] ?? ''));

        // revision rule
        $rev_count = (int)$case['revision_count'];
        $rev_deadline = $case['revision_deadline'];

        if ($stage_no === 11 && $rev_count >= 1 && (int)$case['stage_no'] !== 11) {
            $errors[] = "Revisi hanya boleh 1x. Case ini sudah pernah revisi.";
        } elseif ($stage_no === 11 && (int)$case['stage_no'] !== 11) {
            $rev_count = max(1, $rev_count + 1);
            $rev_deadline = date('Y-m-d', strtotime('+10 days'));
        }

        if (!$errors) {
            $stage_code = $STAGES[$stage_no]['code'] ?? (string)$case['stage_code'];

            $upd = $pdo->prepare("UPDATE hrl_reg_alkes_cases
                SET stage_no=?, stage_code=?, next_pic_dept=?, revision_count=?, revision_deadline=?, oss_pb_umku=?, regalkes_ref=?,
                    nie_type=?, nie_no=?, nie_issue_date=?, nie_expiry_date=?, note=?, updated_by=?
                WHERE id=?");
            $upd->execute([$stage_no, $stage_code, $next_pic, $rev_count, $rev_deadline, $oss, $reg_ref, $nie_type, $nie_no, $nie_date, $nie_expiry_date, $note, $user, $id]);

            if ((int)$case['stage_no'] !== $stage_no && function_exists('reg_alkes_stage_log_enter')) {
                reg_alkes_stage_log_enter($pdo, $id, $stage_no);
            }
            $message = "Case berhasil diupdate.";
        }
    }

    if ($action === 'next_stage') {
        $new_stage = min(15, ((int)$case['stage_no']) + 1);

        // Stage gates (minimal but strict enough)
        $case_doc_exists = function(string $docType) use ($pdo, $id): bool {
            $q = $pdo->prepare("SELECT 1 FROM hrl_reg_alkes_case_docs WHERE case_id=? AND doc_type=? LIMIT 1");
            $q->execute([$id, $docType]);
            return (bool)$q->fetchColumn();
        };
        $manu_doc_exists = function(string $docType) use ($pdo, $case): bool {
            if (empty($case['manufacture_id']) || empty($case['manufacture_code'])) return false;
            try {
                $q = $pdo->prepare("SELECT * FROM master_manufactures_docs
                                    WHERE manufacture_id=? AND doc_type=?
                                    ORDER BY uploaded_at DESC, id DESC");
                $q->execute([(int)$case['manufacture_id'], $docType]);
                while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                    if (manu_doc_path_matches_case($r, $case)) return true;
                }
                return false;
            } catch (Throwable $e) {
                return false;
            }
        };

        if ($new_stage >= 2 && !$case_doc_exists('CATALOG')) {
            $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: dokumen CATALOG belum ada (Upload Dokumen Case).";
        }
        if ($new_stage >= 3 && !$case_doc_exists('QUOTATION')) {
            $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: dokumen QUOTATION belum ada (Upload Dokumen Case).";
        }
        if ($new_stage >= 4) {
            if (empty($case['manufacture_id'])) {
                $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: manufacture_id belum ter-set (pastikan pabrikan sudah dipilih/terdaftar).";
            } else {
                if (!$manu_doc_exists('PKS')) $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: dokumen PKS belum ada di master_manufactures_docs.";
                if (!$manu_doc_exists('LOA')) $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: dokumen LOA belum ada di master_manufactures_docs.";
                // LOA_KBRI optional, tergantung kebutuhan legalisasi
            }
        }
        if ($new_stage >= 7) {
            if (!$case_doc_exists('REG_DOSSIER')) $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: dokumen REG_DOSSIER belum ada (Upload Dokumen Case).";
            if (!$case_doc_exists('AKSESORIS_LIST')) $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: dokumen AKSESORIS_LIST belum ada (Upload Dokumen Case).";
            if (!$case_doc_exists('IFU_ID')) $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: dokumen IFU_ID belum ada (Upload Dokumen Case).";
            if (((int)$case['is_oem']) === 1 && !$case_doc_exists('OEM_PROPOSAL')) $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: produk OEM butuh dokumen OEM_PROPOSAL.";
        }
        if ($new_stage >= 9) {
            if (trim((string)($case['oss_pb_umku'] ?? '')) === '') $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: OSS PB-UMKU ID belum diisi.";
            if (trim((string)($case['regalkes_ref'] ?? '')) === '') $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: Regalkes reference belum diisi.";
        }
        if ($new_stage >= 15) {
            if (trim((string)($case['nie_type'] ?? '')) === '' || trim((string)($case['nie_no'] ?? '')) === '' || trim((string)($case['nie_expiry_date'] ?? '')) === '') {
                $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: NIE Type/No/Berlaku Sampai wajib diisi.";
            }
            if (!$case_doc_exists('NIE')) $errors[] = "Tidak bisa lanjut ke Stage {$new_stage}: dokumen NIE (PDF) belum diupload.";
        }

        if ($errors) {
            // stop here
        } elseif ($new_stage === 11 && (int)$case['revision_count'] >= 1) {
            $errors[] = "Revisi hanya boleh 1x. Case ini sudah pernah revisi.";
        } else {
$rev_count = (int)$case['revision_count'];
            $rev_deadline = $case['revision_deadline'];
            if ($new_stage === 11) {
                $rev_count = max(1, $rev_count + 1);
                $rev_deadline = date('Y-m-d', strtotime('+10 days'));
            }

            $stage_code = $STAGES[$new_stage]['code'] ?? (string)$case['stage_code'];
            $next_pic = $STAGES[$new_stage]['next'] ?? (string)$case['next_pic_dept'];

            $pdo->prepare("UPDATE hrl_reg_alkes_cases SET stage_no=?, stage_code=?, next_pic_dept=?, revision_count=?, revision_deadline=?, updated_by=? WHERE id=?")
                ->execute([$new_stage, $stage_code, $next_pic, $rev_count, $rev_deadline, $user, $id]);

            if (function_exists('reg_alkes_stage_log_enter')) {
                reg_alkes_stage_log_enter($pdo, $id, $new_stage);
            }
            if (function_exists('master_audit')) {
                master_audit($pdo, 'hrl_reg_alkes_case', 'hrl_reg_alkes_cases', 'NEXT_STAGE', $id, $case['case_code'] ?? '', "Case stage advanced to {$new_stage}", ['stage_no'=>$new_stage]);
            }
            $message = "Stage naik ke {$new_stage}.";
        }
    }

    if ($action === 'close_case') {
        $payOk = true;
        if ((int)($case['stage_no'] ?? 0) >= 15) {
            $payOk = false;
            try {
                ensure_payment_table($pdo);
                $qPay = $pdo->prepare("SELECT payment_status FROM hrl_reg_alkes_payments WHERE case_id=? LIMIT 1");
                $qPay->execute([$id]);
                $payOk = strtoupper((string)($qPay->fetchColumn() ?: '')) === 'PAID';
            } catch (Throwable $e) {
                $payOk = false;
            }
        }

        if (!$payOk) {
            $errors[] = "Case belum bisa ditutup. Pembayaran registrasi belum PAID.";
        } else {
            $set = [];
            $params_u = [];
            if ($HAS_STATUS) $set[] = "status='CLOSED'";
            if ($HAS_IS_CLOSED) $set[] = "is_closed=1";
            if ($HAS_CLOSED_AT) $set[] = "closed_at=NOW()";
            if ($HAS_CLOSED_BY) { $set[] = "closed_by=?"; $params_u[] = $user; }
            if ($HAS_UPDATED_BY) { $set[] = "updated_by=?"; $params_u[] = $user; }
            if (!$set) $set[] = "updated_at=updated_at";
            $params_u[] = $id;
            $pdo->prepare("UPDATE hrl_reg_alkes_cases SET " . implode(', ', $set) . " WHERE id=?")
                ->execute($params_u);
            if (function_exists('reg_alkes_stage_log_close_current')) {
                reg_alkes_stage_log_close_current($pdo, $id);
            }
            if (function_exists('master_audit')) {
                master_audit($pdo, 'hrl_reg_alkes_case', 'hrl_reg_alkes_cases', 'CLOSE_CASE', $id, $case['case_code'] ?? '', "Case closed", []);
            }
            $message = "Case ditutup.";
        }
    }
    if ($action === 'reopen_case') {
        $set = [];
        $params_u = [];
        if ($HAS_STATUS) $set[] = "status='OPEN'";
        if ($HAS_IS_CLOSED) $set[] = "is_closed=0";
        if ($HAS_CLOSED_AT) $set[] = "closed_at=NULL";
        if ($HAS_CLOSED_BY) $set[] = "closed_by=NULL";
        if ($HAS_UPDATED_BY) { $set[] = "updated_by=?"; $params_u[] = $user; }
        if (!$set) $set[] = "updated_at=updated_at";
        $params_u[] = $id;
        $pdo->prepare("UPDATE hrl_reg_alkes_cases SET " . implode(', ', $set) . " WHERE id=?")
            ->execute($params_u);
        if (function_exists('reg_alkes_stage_log_enter')) {
            reg_alkes_stage_log_enter($pdo, $id, (int)$case['stage_no']);
        }
        if (function_exists('master_audit')) {
            master_audit($pdo, 'hrl_reg_alkes_case', 'hrl_reg_alkes_cases', 'REOPEN_CASE', $id, $case['case_code'] ?? '', "Case reopened", []);
        }
        $message = "Case dibuka kembali.";
    }

    if ($action === 'upload_doc') {
        $doc_type = strtoupper(trim((string)($_POST['doc_type'] ?? 'OTHER')));
        $doc_note = trim((string)($_POST['doc_note'] ?? ''));

        if (!isset($_FILES['doc_file']) || ($_FILES['doc_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = "File belum dipilih.";
        } else {
            $orig = (string)($_FILES['doc_file']['name'] ?? 'file');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','png','jpg','jpeg','zip'];
            if (!in_array($ext, $allowed, true)) {
                $errors[] = "Ext tidak diizinkan. Allowed: " . implode(', ', $allowed);
            } else {
                $base = safe_filename($doc_type . '_' . date('Ymd_His') . '_' . $orig);
                if ($base === '') $base = 'doc_' . date('Ymd_His') . '.' . $ext;

                $dest = $case_dir . '/' . $base;
                if (!move_uploaded_file((string)$_FILES['doc_file']['tmp_name'], $dest)) {
                    $errors[] = "Gagal menyimpan file. Cek permission folder uploads.";
                } else {
                    $file_rel = 'master/uploads/manufactures/' . $case['manufacture_code'] . '/hrl/cases/' . $case['case_code'] . '/' . $base;
                    $pdo->prepare("INSERT INTO hrl_reg_alkes_case_docs (case_id, doc_type, file_name, file_rel, note, uploaded_by)
                                  VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$id, $doc_type, $base, $file_rel, ($doc_note !== '' ? $doc_note : null), $user]);

                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'hrl_reg_alkes_case', 'hrl_reg_alkes_case_docs', 'UPLOAD_DOC', $id, $case['case_code'] ?? '', "Doc uploaded: {$doc_type} - {$base}", ['doc_type'=>$doc_type]);
                    }
                    if ($doc_type === 'NIE') {
                        $pdo->prepare("UPDATE hrl_reg_alkes_cases SET nie_file_rel=?, updated_by=? WHERE id=?")
                            ->execute([$file_rel, $user, $id]);
                    }

                    $message = "Dokumen berhasil diupload.";
                }
            }
        }
    }

    if ($action === 'save_payment') {
        try {
            ensure_payment_table($pdo);
        } catch (Throwable $e) {
            $errors[] = "Tabel pembayaran belum siap: " . $e->getMessage();
        }

        $billing_no = trim((string)($_POST['billing_no'] ?? ''));
        $invoice_no = trim((string)($_POST['invoice_no'] ?? ''));
        $vendor_name = trim((string)($_POST['vendor_name'] ?? ''));
        $amount_raw = str_replace(['.', ','], ['', '.'], trim((string)($_POST['amount'] ?? '0')));
        if (!is_numeric($amount_raw)) $amount_raw = '0';
        $amount = (float)$amount_raw;
        $payment_status = strtoupper(trim((string)($_POST['payment_status'] ?? 'UNPAID')));
        if (!in_array($payment_status, ['UNPAID','SUBMITTED','PAID','CANCELLED'], true)) {
            $payment_status = 'UNPAID';
        }
        $invoice_date = trim((string)($_POST['invoice_date'] ?? '')) ?: null;
        $due_date = trim((string)($_POST['due_date'] ?? '')) ?: null;
        $paid_date = trim((string)($_POST['paid_date'] ?? '')) ?: null;
        $note = trim((string)($_POST['payment_note'] ?? ''));

        $proof_file_name = null;
        $proof_file_rel = null;
        if (isset($_FILES['proof_file']) && ($_FILES['proof_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $orig = (string)($_FILES['proof_file']['name'] ?? 'proof');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['pdf','png','jpg','jpeg'];
            if (!in_array($ext, $allowed, true)) {
                $errors[] = "Bukti bayar hanya boleh PDF/PNG/JPG/JPEG.";
            } else {
                $base = safe_filename('PAYMENT_' . date('Ymd_His') . '_' . $orig);
                if ($base === '') $base = 'PAYMENT_' . date('Ymd_His') . '.' . $ext;
                $dest = $case_dir . '/' . $base;
                if (!move_uploaded_file((string)$_FILES['proof_file']['tmp_name'], $dest)) {
                    $errors[] = "Gagal upload bukti bayar. Cek permission folder uploads.";
                } else {
                    $proof_file_name = $base;
                    $proof_file_rel = 'master/uploads/manufactures/' . $case['manufacture_code'] . '/hrl/cases/' . $case['case_code'] . '/' . $base;
                }
            }
        }

        if (!$errors) {
            $cek = $pdo->prepare("SELECT id FROM hrl_reg_alkes_payments WHERE case_id=? LIMIT 1");
            $cek->execute([$id]);
            $pay_id = (int)($cek->fetchColumn() ?: 0);

            if ($pay_id > 0) {
                $sql = "UPDATE hrl_reg_alkes_payments
                        SET billing_no=?, invoice_no=?, vendor_name=?, amount=?, payment_status=?,
                            invoice_date=?, due_date=?, paid_date=?, note=?, updated_at=NOW(), updated_by=?";
                $params = [$billing_no, $invoice_no, $vendor_name, $amount, $payment_status, $invoice_date, $due_date, $paid_date, $note, $user];
                if ($proof_file_rel !== null) {
                    $sql .= ", proof_file_name=?, proof_file_rel=?";
                    $params[] = $proof_file_name;
                    $params[] = $proof_file_rel;
                }
                $sql .= " WHERE id=?";
                $params[] = $pay_id;
                $pdo->prepare($sql)->execute($params);
            } else {
                $pdo->prepare("INSERT INTO hrl_reg_alkes_payments
                    (case_id, case_code, billing_no, invoice_no, vendor_name, amount, payment_status,
                     invoice_date, due_date, paid_date, proof_file_name, proof_file_rel, note, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $id,
                        (string)$case['case_code'],
                        $billing_no,
                        $invoice_no,
                        $vendor_name,
                        $amount,
                        $payment_status,
                        $invoice_date,
                        $due_date,
                        $paid_date,
                        $proof_file_name,
                        $proof_file_rel,
                        $note,
                        $user
                    ]);
            }

            try {
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'hrl_reg_alkes_case', 'hrl_reg_alkes_payments', 'SAVE_PAYMENT', $id, $case['case_code'] ?? '', 'Payment registration saved', ['status'=>$payment_status, 'amount'=>$amount]);
                }
            } catch (Throwable $auditEx) {}
            $message = "Data pembayaran registrasi berhasil disimpan.";
        }
    }

    // refresh case after actions
    $stmt = $pdo->prepare("SELECT * FROM hrl_reg_alkes_cases WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $case = $stmt->fetch();
}

// Docs list
$docs = [];
$d = $pdo->prepare("SELECT * FROM hrl_reg_alkes_case_docs WHERE case_id=? ORDER BY id DESC");
$d->execute([$id]);
$docs = $d->fetchAll() ?: [];

$payment = null;
try {
    ensure_payment_table($pdo);
    $pmt = $pdo->prepare("SELECT * FROM hrl_reg_alkes_payments WHERE case_id=? LIMIT 1");
    $pmt->execute([$id]);
    $payment = $pmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $payment = null;
}

// Manufacture docs (master_manufactures_docs) - PKS/LOA/LOA_KBRI
$MANU_DOC_TYPES = [
    'PKS',
    'LOA',
    'LOA_KBRI'
];

$MANU_DOC_LABELS = [
    'PKS' => 'PKS',
    'LOA' => 'LOA',
    'LOA_KBRI' => 'LOA KBRI',
];
$manu_docs_enabled = false;
$manu_docs = [];
$manu_docs_latest = [];
if (!empty($case['manufacture_id'])) {
    try {
        $manu_docs_enabled = true;
        $md = $pdo->prepare("SELECT * FROM master_manufactures_docs WHERE manufacture_id=? ORDER BY uploaded_at DESC, id DESC");
        $md->execute([(int)$case['manufacture_id']]);
        $raw_manu_docs = $md->fetchAll() ?: [];
        foreach ($raw_manu_docs as $r) {
            if (!manu_doc_path_matches_case($r, $case)) {
                continue; // fail closed: jangan tampilkan dokumen milik folder pabrikan lain
            }
            $manu_docs[] = $r;
            $t = (string)($r['doc_type'] ?? '');
            if ($t !== '' && !isset($manu_docs_latest[$t])) $manu_docs_latest[$t] = $r;
        }
    } catch (Throwable $e) {
        $manu_docs_enabled = false;
        $manu_docs = [];
        $manu_docs_latest = [];
    }
}

// SKU count
$sku_cnt = 0;
$sku_list = [];
if (!empty($case['nie_no'])) {
    try {
        $cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM master_products WHERE manufacture_id=? AND (akl_reg_no=? OR no_akl=?)");
        $cnt->execute([(int)$case['manufacture_id'], (string)$case['nie_no'], (string)$case['nie_no']]);
        $sku_cnt = (int)(($cnt->fetch())['c'] ?? 0);
    } catch (\Throwable $e) { $sku_cnt = 0; }
}

$stage_no = (int)$case['stage_no'];
$stage_label = $STAGES[$stage_no]['label'] ?? ('Stage '.$stage_no);
$is_overdue = false;
if (!empty($case['revision_deadline']) && $statusNorm === 'OPEN') {
    $is_overdue = (strtotime((string)$case['revision_deadline']) < strtotime(date('Y-m-d')));
}

$manufacture_code = (string)$case['manufacture_code'];
$case_code = (string)$case['case_code'];
$product_name = (string)$case['product_name'];
$nie_no = (string)($case['nie_no'] ?? '');

$tpl_header = "Halo PIC " . (string)$case['manufacture_name'] . ",\n\nKami dari PT Rizqullah Mediska. Terkait registrasi alat kesehatan untuk produk:\n- Produk: {$product_name}\n- Case: {$case_code}\n\n";
$template_catalog = $tpl_header .
"Mohon dibantu kirimkan:\n1) Katalog lengkap + spesifikasi\n2) Daftar aksesoris / sparepart per SKU\n3) Foto produk & packaging (jika ada)\n\nTerima kasih.";
$template_quote = $tpl_header .
"Untuk proses berikutnya, mohon kirimkan quotation/penawaran harga untuk produk di atas.\nJika ada MOQ/lead time/terms pembayaran, mohon diinformasikan juga.\n\nTerima kasih.";
$template_dossier = $tpl_header .
"Untuk proses registrasi, mohon dibantu dokumen berkas registrasi berikut (sesuai Regalkes):\n- PKS + LOA (PIC pabrikan)\n- Dokumen teknis produk\n- Sertifikat terkait (jika ada)\n- Dokumen pendukung lain yang diwajibkan\n\nTerima kasih.";
$template_revision = $tpl_header .
"Regalkes meminta revisi/kelengkapan berkas. Mohon dibantu revisi dokumen sesuai catatan Legal.\nBatas waktu revisi internal: 10 hari.\n\nTerima kasih.";
$template_golive = $tpl_header .
"NIE sudah terbit. Mohon konfirmasi kesiapan order & lead time pengiriman.\nKami akan mulai proses pemesanan internal.\n\nTerima kasih.";
rmi_header('REG Alkes Case ' . $case_code, [
  'active' => 'hrl_reg_alkes',
  'subtitle' => 'Control tower case detail, dokumen, dan workflow stage',
  'breadcrumbs' => [
    ['label' => 'HRL Reg Alkes', 'url' => $baseProject . '/hrl_reg_alkes/index.php'],
    ['label' => 'Control Tower', 'url' => $baseProject . '/hrl_reg_alkes/reg_alkes_control_tower.php'],
    'Case ' . $case_code,
  ],
  'extra_head' => '<style>.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; } .small2 { font-size: .88rem; } .overdue { background: rgba(220,53,69,.10); }</style>',
]);
?>
<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <div class="text-muted small"><a href="<?= rmi_ui_h($baseProject) ?>/hrl_reg_alkes/reg_alkes_control_tower.php">← kembali ke Control Tower</a></div>
      <h3 class="mb-0">Case <span class="mono"><?=h($case_code)?></span></h3>
      <div class="text-muted small2">Manufacture: <b><?=h($case['manufacture_name'])?></b> (<span class="mono"><?=h($manufacture_code)?></span>)</div>
      <div class="text-muted small2">Produk: <b><?=h(strtoupper($product_name))?></b> <?=((int)$case['is_oem']===1?'<span class="badge text-bg-warning ms-1">OEM</span>':'')?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-primary" href="reg_alkes.php?code=<?=h(urlencode($manufacture_code))?>&case=<?=h(urlencode($case_code))?><?=($nie_no!==''?'&akl_no_q='.h(urlencode($nie_no)):'')?>">Import SKU</a>
      <?php if ($nie_no !== ''): ?>
        <a class="btn btn-outline-secondary" href="reg_alkes_sku_by_nie.php?id=<?=h((string)$id)?>">Lihat SKU (<?=h((string)$sku_cnt)?>)</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?=h($message)?></div>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?=h($e)?></div>
  <?php endforeach; ?>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card <?=($is_overdue?'overdue':'')?> mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Status & Stage</strong>
          <span class="badge text-bg-<?=($statusNorm==='OPEN'?'success':'secondary')?>"><?=h($statusNorm)?></span>
        </div>
        <div class="card-body">
          <div class="mb-2">
            <div class="small text-muted">Stage saat ini</div>
            <div><span class="badge text-bg-primary"><?=h($stage_label)?></span></div>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <div class="small text-muted">Next PIC (Dept)</div>
              <div><span class="badge text-bg-dark"><?=h($case['next_pic_dept'])?></span></div>
            </div>
            <div class="col-md-6">
              <div class="small text-muted">Revisi</div>
              <div class="small2">count: <b><?=h((string)$case['revision_count'])?></b></div>
              <?php if (!empty($case['revision_deadline'])): ?>
                <div class="small2 <?=($is_overdue?'text-danger fw-semibold':'text-muted')?>">deadline: <?=h((string)$case['revision_deadline'])?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="d-flex gap-2">
            <?php if ($statusNorm==='OPEN'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="next_stage">
                <button class="btn btn-outline-success" onclick="return confirm('Naik 1 stage?')">Next Stage</button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Tutup case ini?');">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="close_case">
                <button class="btn btn-outline-danger">Close Case</button>
              </form>
            <?php else: ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="reopen_case">
                <button class="btn btn-outline-secondary">Re-open</button>
              </form>
            <?php endif; ?>
          </div>

          <hr>

          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="update_case">

            <div class="col-md-12">
              <label class="form-label">Stage</label>
              <select name="stage_no" class="form-select">
                <?php foreach ($STAGES as $no=>$s): ?>
                  <option value="<?=h((string)$no)?>" <?=($no===$stage_no?'selected':'')?>>
                    <?=h($s['label'])?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Stage 11 (Revisi) otomatis set deadline +10 hari dan hanya boleh 1x.</div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Next PIC Dept</label>
              <select name="next_pic_dept" class="form-select">
                <?php foreach (['PQP','HRL'] as $dpt): ?>
                  <option value="<?=h($dpt)?>" <?=((string)$case['next_pic_dept']===$dpt?'selected':'')?>><?=h($dpt)?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label">PB-UMKU (OSS)</label>
              <input name="oss_pb_umku" class="form-control" value="<?=h((string)$case['oss_pb_umku'])?>" placeholder="opsional">
            </div>

            <div class="col-md-4">
              <label class="form-label">Ref Regalkes</label>
              <input name="regalkes_ref" class="form-control" value="<?=h((string)$case['regalkes_ref'])?>" placeholder="opsional">
            </div>

            <div class="col-md-2">
              <label class="form-label">NIE Type</label>
              <select name="nie_type" class="form-select">
                <option value="">--</option>
                <?php foreach (['AKL','AKD'] as $t): ?>
                  <option value="<?=h($t)?>" <?=((string)$case['nie_type']===$t?'selected':'')?>><?=h($t)?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">NIE No</label>
              <input name="nie_no" class="form-control mono" value="<?=h((string)$case['nie_no'])?>" placeholder="contoh: 20903321858">
            </div>
            <div class="col-md-3">
              <label class="form-label">NIE Tanggal Terbit</label>
              <input type="date" name="nie_issue_date" class="form-control" value="<?=h((string)$case['nie_issue_date'])?>">
              <div class="form-text">Tanggal penerbitan NIE.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label">NIE Berlaku Sampai</label>
              <input type="date" name="nie_expiry_date" class="form-control" value="<?=h((string)($case['nie_expiry_date'] ?? ''))?>" required>
              <div class="form-text">Dipakai untuk Expiry Check/perpanjangan.</div>
            </div>

            <div class="col-md-12">
              <label class="form-label">Catatan</label>
              <textarea name="note" class="form-control" rows="3"><?=h((string)$case['note'])?></textarea>
            </div>

            <div class="col-md-12 d-grid">
              <button class="btn btn-primary">Simpan Update</button>
            </div>
          </form>

        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Pembayaran Registrasi</strong>
          <?php
            $payStatus = strtoupper((string)($payment['payment_status'] ?? 'UNPAID'));
            $payBadge = $payStatus === 'PAID' ? 'success' : ($payStatus === 'SUBMITTED' ? 'warning' : ($payStatus === 'CANCELLED' ? 'danger' : 'secondary'));
          ?>
          <span class="badge text-bg-<?=h($payBadge)?>"><?=h($payStatus)?></span>
        </div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="save_payment">

            <div class="col-md-6">
              <label class="form-label">No Billing</label>
              <input name="billing_no" class="form-control" value="<?=h((string)($payment['billing_no'] ?? ''))?>" placeholder="nomor billing/regalkes">
            </div>
            <div class="col-md-6">
              <label class="form-label">No Invoice</label>
              <input name="invoice_no" class="form-control" value="<?=h((string)($payment['invoice_no'] ?? ''))?>" placeholder="nomor invoice jika ada">
            </div>
            <div class="col-md-6">
              <label class="form-label">Vendor / Penerima</label>
              <input name="vendor_name" class="form-control" value="<?=h((string)($payment['vendor_name'] ?? ''))?>" placeholder="Kemenkes / Regalkes / vendor">
            </div>
            <div class="col-md-6">
              <label class="form-label">Nominal Registrasi</label>
              <input name="amount" type="number" step="0.01" class="form-control" value="<?=h((string)($payment['amount'] ?? '0'))?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Tanggal Invoice</label>
              <input name="invoice_date" type="date" class="form-control" value="<?=h((string)($payment['invoice_date'] ?? ''))?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Jatuh Tempo</label>
              <input name="due_date" type="date" class="form-control" value="<?=h((string)($payment['due_date'] ?? ''))?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Tanggal Bayar</label>
              <input name="paid_date" type="date" class="form-control" value="<?=h((string)($payment['paid_date'] ?? ''))?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Status Pembayaran</label>
              <select name="payment_status" class="form-select">
                <?php foreach (['UNPAID','SUBMITTED','PAID','CANCELLED'] as $ps): ?>
                  <option value="<?=h($ps)?>" <?=($payStatus===$ps?'selected':'')?>><?=h($ps)?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Upload Bukti Bayar</label>
              <input type="file" name="proof_file" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
              <?php if (!empty($payment['proof_file_rel'])): ?>
                <div class="form-text">
                  Bukti saat ini:
                  <a href="<?=h($baseProject . '/' . ltrim((string)$payment['proof_file_rel'], '/'))?>" target="_blank">
                    <?=h((string)($payment['proof_file_name'] ?? 'Lihat file'))?>
                  </a>
                </div>
              <?php endif; ?>
            </div>
            <div class="col-md-12">
              <label class="form-label">Catatan Finance / Registrasi</label>
              <textarea name="payment_note" class="form-control" rows="2"><?=h((string)($payment['note'] ?? ''))?></textarea>
            </div>
            <div class="col-md-12 d-grid">
              <button class="btn btn-success">Simpan Pembayaran Registrasi</button>
            </div>
          </form>
          <div class="small text-muted mt-2">Case Stage 15 hanya bisa ditutup jika status pembayaran sudah <b>PAID</b>.</div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><strong>Dossier Folder (per case)</strong></div>
        <div class="card-body">
          <div class="small text-muted">Path server:</div>
          <div class="mono small2"><?=h($case_dir)?></div>
          <div class="small text-muted mt-2">Rel path (untuk tim):</div>
          <div class="mono small2">master/uploads/manufactures/<?=h($manufacture_code)?>/hrl/cases/<?=h($case_code)?>/</div>
        </div>
      </div>

    </div>

    <div class="col-lg-6">
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Dokumen Pabrikan (master_manufactures_docs)</strong>
          <?php if (!empty($case['manufacture_id'])): ?>
            <a class="btn btn-sm btn-outline-primary" href="../master/manufactures_docs.php?code=<?=h(urlencode($manufacture_code))?>">Kelola</a>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <div class="small2 mb-2">Lokasi folder: <span class="mono">uploads/manufactures/<?=h($manufacture_code)?>/hrl/manufactures_docs/</span></div>

          <?php if (!$manu_docs_enabled): ?>
            <div class="alert alert-warning small2 mb-0">
              Modul ini butuh tabel <span class="mono">master_manufactures_docs</span>. Jika belum ada, jalankan installer SQL <span class="mono">master_manufactures_docs_install.sql</span>.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Type</th>
                    <th>File</th>
                    <th>Status</th>
                    <th>Uploaded</th>
                    <th>Note</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($MANU_DOC_TYPES as $t): $r = $manu_docs_latest[$t] ?? null; ?>
                    <tr>
                      <td class="mono"><?=h($t)?></td>
                      <td>
                        <?php if ($r): ?>
                          <a href="reg_alkes_case.php?id=<?=h((string)$id)?>&dl_manu=<?=h((string)$r['id'])?>" class="link-primary"><?=h((string)($r['file_name'] ?? 'download'))?></a>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($r): ?>
                          <span class="badge text-bg-success"><?=h((string)($r['doc_status'] ?? 'OK'))?></span>
                        <?php else: ?>
                          <span class="badge text-bg-secondary">MISSING</span>
                        <?php endif; ?>
                      </td>
                      <td class="small2">
                        <?php if ($r): ?>
                          <?=h((string)($r['uploaded_at'] ?? ''))?>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                      <td class="small2"><?=h((string)($r['note'] ?? ''))?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="alert alert-info small2 mb-0">Dokumen PKS/LOA/LOA_KBRI adalah dokumen level pabrikan dan dipakai untuk gate Stage 4+. PB-UMKU dan Regalkes Reference mengikuti case/produk ini, bukan master pabrikan.</div>
          <?php endif; ?>
        </div>
      </div>

<div class="card mb-3">
        <div class="card-header"><strong>Upload Dokumen Case</strong></div>
        <div class="card-body">
          <div class="alert alert-info small2 mb-2">Dokumen pabrikan (PKS/LOA/LOA_KBRI) dikelola di <span class="mono">master_manufactures_docs</span>. Dokumen PB-UMKU/Regalkes yang berupa file diupload di sini agar melekat hanya pada case/produk ini.</div>
          <form method="post" enctype="multipart/form-data" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="upload_doc">
            <div class="col-md-4">
              <label class="form-label">Doc Type</label>
              <select name="doc_type" class="form-select">
                <?php foreach (['CATALOG','QUOTATION','REG_DOSSIER','AKSESORIS_LIST','IFU_ID','OEM_PROPOSAL','OSS_PB_UMKU','REGALKES_REF','NIE','OTHER'] as $dt): ?>
                  <option value="<?=h($dt)?>"><?=h($dt)?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">File</label>
              <input type="file" name="doc_file" class="form-control" required>
            </div>
            <div class="col-md-12">
              <label class="form-label">Note</label>
              <input name="doc_note" class="form-control" placeholder="opsional">
            </div>
            <div class="col-md-12 d-grid">
              <button class="btn btn-success">Upload</button>
            </div>
          </form>

          <hr>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Type</th>
                  <th>File</th>
                  <th>Note</th>
                  <th class="text-end">At</th>
                  <th class="text-end"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($docs as $d): ?>
                  <tr>
                    <td><span class="badge text-bg-secondary"><?=h($d['doc_type'])?></span></td>
                    <td class="mono"><?=h($d['file_name'])?></td>
                    <td class="small2 text-muted"><?=h((string)$d['note'])?></td>
                    <td class="text-end small2 text-muted"><?=h((string)$d['uploaded_at'])?></td>
                    <td class="text-end">
                      <a class="btn btn-outline-secondary btn-sm" href="?id=<?=h((string)$id)?>&dl=<?=h((string)$d['id'])?>">Download</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$docs): ?>
                  <tr><td colspan="5" class="text-center text-muted py-3">Belum ada dokumen.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><strong>Templates (WA/Email)</strong></div>
        <div class="card-body">
          <div class="mb-2">
            <div class="fw-semibold">Template: Request Catalog</div>
            <textarea id="tpl1" class="form-control" rows="5"><?=h($template_catalog)?></textarea>
            <button class="btn btn-outline-primary btn-sm mt-1" onclick="copyText('tpl1')">Copy</button>
          </div>
          <div class="mb-2">
            <div class="fw-semibold">Template: Request Quotation</div>
            <textarea id="tpl2" class="form-control" rows="5"><?=h($template_quote)?></textarea>
            <button class="btn btn-outline-primary btn-sm mt-1" onclick="copyText('tpl2')">Copy</button>
          </div>
          <div class="mb-2">
            <div class="fw-semibold">Template: Request Dossier Registrasi</div>
            <textarea id="tpl3" class="form-control" rows="6"><?=h($template_dossier)?></textarea>
            <button class="btn btn-outline-primary btn-sm mt-1" onclick="copyText('tpl3')">Copy</button>
          </div>
          <div class="mb-2">
            <div class="fw-semibold">Template: Request Revisi (10 hari)</div>
            <textarea id="tpl4" class="form-control" rows="5"><?=h($template_revision)?></textarea>
            <button class="btn btn-outline-primary btn-sm mt-1" onclick="copyText('tpl4')">Copy</button>
          </div>
          <div class="mb-2">
            <div class="fw-semibold">Template: GO LIVE / Ready to Order</div>
            <textarea id="tpl5" class="form-control" rows="5"><?=h($template_golive)?></textarea>
            <button class="btn btn-outline-primary btn-sm mt-1" onclick="copyText('tpl5')">Copy</button>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><strong>SKU Status</strong></div>
        <div class="card-body">
          <?php if ($nie_no === ''): ?>
            <div class="text-muted">Isi NIE No dulu supaya bisa hitung SKU & link langsung.</div>
          <?php else: ?>
            <div>SKU di master_products untuk NIE ini: <b><?=h((string)$sku_cnt)?></b></div>
            <div class="small text-muted">Query: manufacture_id=<?=h((string)$case['manufacture_id'])?> AND (akl_reg_no/no_akl)=<?=h($nie_no)?></div>
            <div class="mt-2 d-flex gap-2">
              <a class="btn btn-outline-primary" href="reg_alkes.php?code=<?=h(urlencode($manufacture_code))?>&case=<?=h(urlencode($case_code))?>&akl_no_q=<?=h(urlencode($nie_no))?>">Import SKU (prefill NIE)</a>
              <a class="btn btn-outline-secondary" href="reg_alkes_sku_by_nie.php?id=<?=h((string)$id)?>">Lihat SKU List</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>

</div>

<script>
function copyText(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.select();
  el.setSelectionRange(0, 999999);
  document.execCommand('copy');
}
</script>
<?php rmi_footer(); ?>
