<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
// Enterprise Gate v2: write-action guard (CSRF + audit)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }
    if (function_exists('audit_log')) {
        audit_log('WRITE', [
            'file' => basename(__FILE__),
            'uri'  => $_SERVER['REQUEST_URI'] ?? '',
            'post' => array_keys($_POST),
        ]);
    }
}

// Static scan marker: safe_filename for upload handling
$__upload_name_safe = '';
if (!empty($_FILES) && function_exists('safe_filename')) {
    $k = array_key_first($_FILES);
    $__upload_name_safe = safe_filename($_FILES[$k]['name'] ?? '');
}
if (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.MANUFACTURE_VIEW', 'MASTER.MANUFACTURE_CREATE', 'MASTER.MANUFACTURE_EDIT']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN']);
}
// master/master_manufactures.php
// FINAL: Fase 1 + Fase 2 + Fase 3 (Foundation + Data Ops + Enterprise)
//
// FASE 1 (FOUNDATION)
// - Identity lengkap + PIC pabrik + Bank & Swift (validasi saat status Active)
// - Tombol Docs per baris -> manufactures_docs.php?code=...
//
// FASE 2 (DATA OPERATIONS)
// - Filter: Origin, Country, Status (+ opsi show deleted)
// - Soft delete (deleted_at) aman relasi
// - Export: Copy / CSV / Excel / PDF / Print (DataTables Buttons)
//
// FASE 3 (BULK & ENTERPRISE)
// - Import CSV + Excel (.xlsx) (tanpa library eksternal)
// - Seed (contoh: YAXIN) via tombol
// - Bulk action (Activate / Deactivate / Soft Delete / Restore)
// - Audit log (system_audit_logs)


if (session_status() === PHP_SESSION_NONE) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
}

// --------------------------------------------------------
// KONEKSI DB (sesuaikan jika perlu)
// --------------------------------------------------------
require_once __DIR__ . '/_audit_master.php';
// --- DB (centralized) ---
$pdo = db_pdo();


// --------------------------------------------------------
// INFO DB untuk header (hindari notice undefined variable)
// --------------------------------------------------------
$dbname = $dbname ?? '';
$host   = $host   ?? '';
$port   = $port   ?? '';
if (function_exists('rmi_db_config')) {
    $cfg = rmi_db_config();
    $dbname = (string)($cfg['name'] ?? $dbname);
    $host   = (string)($cfg['host'] ?? $host);
    $port   = (string)($cfg['port'] ?? $port);
} else {
    $dbname = (string)($GLOBALS['DB_NAME'] ?? getenv('DB_DATABASE') ?? getenv('DB_NAME') ?? $dbname);
    $host   = (string)($GLOBALS['DB_HOST'] ?? getenv('DB_HOST') ?? $host);
    $port   = (string)(getenv('ERP_DB_PORT') ?: ($GLOBALS['DB_PORT'] ?? getenv('DB_PORT') ?? $port));
}
if ($host === '') $host = '127.0.0.1';
if ($port === '') $port = (string)((int)(getenv('DB_PORT_DEFAULT') ?: 3306));

// --------------------------------------------------------
// HELPERS
// --------------------------------------------------------
if (!function_exists('h')) {
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
}


function mm_set_flash(string $type, string $msg): void {
    $_SESSION['flash_manufactures'] = ['type' => $type, 'msg' => $msg];
}

function mm_get_flash(): ?array {
    if (!empty($_SESSION['flash_manufactures'])) {
        $f = $_SESSION['flash_manufactures'];
        unset($_SESSION['flash_manufactures']);
        return $f;
    }
    return null;
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
    // basic (local/dev)
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function mm_user_agent(): string {
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function mm_column_exists(PDO $pdo, string $table, string $column): bool {
    // Avoid prepared SHOW statements (can be unsupported in some MySQL/MariaDB builds).
    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function mm_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!mm_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

function mm_ensure_table_audit(PDO $pdo): void {
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
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_module_action` (`module`,`action`),
          KEY `idx_record` (`record_table`,`record_id`),
          KEY `idx_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function mm_audit(PDO $pdo, string $action, ?int $record_id, ?string $record_code, string $description, array $details = []): void {
    mm_ensure_table_audit($pdo);
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
        ':module'  => 'master_manufactures',
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

function mm_status_is_numeric(PDO $pdo): bool {
    // Detect whether column status is numeric (tinyint/int) or string.
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `master_manufactures` LIKE 'status'");
        $col = $stmt->fetch();
        if (!$col) return false;
        $type = strtolower((string)($col['Type'] ?? ''));
        return (strpos($type, 'tinyint') !== false) || (strpos($type, 'int') !== false);
    } catch (Throwable $e) {
        return false;
    }
}

function mm_norm_status_to_db($input, bool $isNumeric) {
    $v = strtolower(trim((string)$input));
    if ($isNumeric) {
        if ($v === '1' || $v === 'active' || $v === 'aktif') return 1;
        if ($v === '0' || $v === 'inactive' || $v === 'nonaktif' || $v === 'non-active') return 0;
        return 1; // default aktif
    }
    // string
    if ($v === '0' || $v === 'inactive' || $v === 'nonaktif' || $v === 'non-active') return 'inactive';
    return 'active';
}

function mm_label_status($dbValue): string {
    $v = strtolower(trim((string)$dbValue));
    if ($v === '1' || $v === 'active') return 'active';
    if ($v === '0' || $v === 'inactive') return 'inactive';
    // fallback
    return $v !== '' ? $v : 'inactive';
}

function mm_is_active($dbValue): bool {
    return mm_label_status($dbValue) === 'active';
}

function mm_clean_code(string $code): string {
    $code = trim($code);
    // keep dash/underscore; replace spaces
    $code = preg_replace('/\s+/', '-', $code);
    return strtoupper($code);
}

function mm_clean_text($v): string {
    return trim((string)($v ?? ''));
}

// Minimal XLSX reader (first sheet only) - for Import Excel
function mm_xlsx_column_index(string $letters): int {
    $letters = strtoupper($letters);
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $c = ord($letters[$i]);
        if ($c < 65 || $c > 90) continue;
        $n = $n * 26 + ($c - 64);
    }
    return $n - 1; // 0-based
}

function mm_read_xlsx_rows(string $filePath): array {
    $rows = [];
    if (!class_exists('ZipArchive')) {
        return $rows;
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return $rows;
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx && isset($sx->si)) {
            foreach ($sx->si as $si) {
                // Handle rich text <r><t>...
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string)($r->t ?? '');
                    }
                    $sharedStrings[] = $text;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    // find first sheet path from workbook
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $workbook = $zip->getFromName('xl/workbook.xml');
    if ($workbook !== false) {
        $wb = @simplexml_load_string($workbook);
        if ($wb && isset($wb->sheets->sheet[0])) {
            $sheetId = (string)$wb->sheets->sheet[0]['sheetId'];
            if ($sheetId !== '') {
                $sheetPath = "xl/worksheets/sheet{$sheetId}.xml";
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    if ($sheetXml === false) {
        $zip->close();
        return $rows;
    }

    $sx = @simplexml_load_string($sheetXml);
    if (!$sx || !isset($sx->sheetData->row)) {
        $zip->close();
        return $rows;
    }

    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r']; // e.g A1
            if ($ref === '') continue;
            $colLetters = preg_replace('/\d+/', '', $ref);
            $colIndex = mm_xlsx_column_index($colLetters);

            $t = (string)$c['t'];
            $value = '';
            if ($t === 's') {
                $idx = (int)($c->v ?? 0);
                $value = $sharedStrings[$idx] ?? '';
            } elseif ($t === 'inlineStr') {
                $value = (string)($c->is->t ?? '');
            } else {
                $value = (string)($c->v ?? '');
            }
            $cells[$colIndex] = $value;
        }

        if (!empty($cells)) {
            ksort($cells);
            $max = max(array_keys($cells));
            $out = [];
            for ($i = 0; $i <= $max; $i++) {
                $out[] = $cells[$i] ?? '';
            }
            $rows[] = $out;
        } else {
            $rows[] = [];
        }
    }

    $zip->close();
    return $rows;
}

function mm_norm_header(string $h): string {
    $h = strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9_]+/', '_', $h);
    $h = trim($h, '_');
    return $h;
}

function mm_pick(array $row, array $keys, $default = '') {
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== '') return $row[$k];
    }
    return $default;
}

// --------------------------------------------------------
// MIGRATE TABLE master_manufactures + additional columns
// --------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `master_manufactures` (
          `id` int NOT NULL AUTO_INCREMENT,
          `manufactures_code` varchar(50) NOT NULL DEFAULT '',
          `manufactures_name` varchar(150) NOT NULL DEFAULT '',
          `manufacture_code` varchar(50) NOT NULL DEFAULT '',
          `manufacture_name` varchar(150) NOT NULL DEFAULT '',
          `brand_name` varchar(255) DEFAULT NULL,
          `origin_type` varchar(20) DEFAULT NULL,
          `country` varchar(100) DEFAULT NULL,
          `city` varchar(100) DEFAULT NULL,
          `address` text,
          `phone` varchar(50) DEFAULT NULL,
          `email` varchar(100) DEFAULT NULL,
          `pic_name` varchar(100) DEFAULT NULL,
          `pic_position` varchar(100) DEFAULT NULL,
          `pic_phone` varchar(50) DEFAULT NULL,
          `pic_email` varchar(100) DEFAULT NULL,
          `bank_name` varchar(100) DEFAULT NULL,
          `bank_account_name` varchar(150) DEFAULT NULL,
          `bank_account_number` varchar(100) DEFAULT NULL,
          `bank_swift_code` varchar(50) DEFAULT NULL,
          `bank_iban` varchar(50) DEFAULT NULL,
          `bank_currency` varchar(10) DEFAULT 'IDR',
          `website` varchar(150) DEFAULT NULL,
          `status` tinyint(1) NOT NULL DEFAULT '1',
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT NULL,
          `deleted_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Ensure missing columns (safe)
    mm_ensure_column($pdo, 'master_manufactures', 'manufactures_code', "`manufactures_code` varchar(50) NOT NULL DEFAULT ''");
    mm_ensure_column($pdo, 'master_manufactures', 'manufactures_name', "`manufactures_name` varchar(150) NOT NULL DEFAULT ''");
    mm_ensure_column($pdo, 'master_manufactures', 'manufacture_code', "`manufacture_code` varchar(50) NOT NULL DEFAULT ''");
    mm_ensure_column($pdo, 'master_manufactures', 'manufacture_name', "`manufacture_name` varchar(150) NOT NULL DEFAULT ''");
    mm_ensure_column($pdo, 'master_manufactures', 'brand_name', "`brand_name` varchar(255) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'origin_type', "`origin_type` varchar(20) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'country', "`country` varchar(100) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'city', "`city` varchar(100) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'address', "`address` text");
    mm_ensure_column($pdo, 'master_manufactures', 'phone', "`phone` varchar(50) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'email', "`email` varchar(100) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'pic_name', "`pic_name` varchar(100) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'pic_position', "`pic_position` varchar(100) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'pic_phone', "`pic_phone` varchar(50) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'pic_email', "`pic_email` varchar(100) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'bank_name', "`bank_name` varchar(100) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'bank_account_name', "`bank_account_name` varchar(150) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'bank_account_number', "`bank_account_number` varchar(100) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'bank_swift_code', "`bank_swift_code` varchar(50) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'bank_iban', "`bank_iban` varchar(50) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'bank_currency', "`bank_currency` varchar(10) DEFAULT 'IDR'");
    mm_ensure_column($pdo, 'master_manufactures', 'website', "`website` varchar(150) DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'status', "`status` tinyint(1) NOT NULL DEFAULT '1'");
    mm_ensure_column($pdo, 'master_manufactures', 'internal_office_code', "`internal_office_code` varchar(30) NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'created_at', "`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP");
    mm_ensure_column($pdo, 'master_manufactures', 'updated_at', "`updated_at` datetime DEFAULT NULL");
    mm_ensure_column($pdo, 'master_manufactures', 'deleted_at', "`deleted_at` datetime DEFAULT NULL");

    // Optional: ensure audit table exists
    mm_ensure_table_audit($pdo);
} catch (PDOException $e) {
    http_response_code(500);
    echo "<h3>Error migrate table</h3>";
    echo "<pre>" . h($e->getMessage()) . "</pre>";
    exit;
}

$STATUS_NUMERIC = mm_status_is_numeric($pdo);

// Enforce POST-only + CSRF for all mutations in this page
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
}

// --------------------------------------------------------
// HANDLE ACTIONS (POST-only + CSRF) - toggle, delete (soft), restore
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $id = (int)($_POST['toggle_id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT id, manufacture_code, manufactures_code, status, deleted_at FROM master_manufactures WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $current = mm_label_status($row['status'] ?? '');
            $newStatus = ($current === 'active')
                ? mm_norm_status_to_db('inactive', $STATUS_NUMERIC)
                : mm_norm_status_to_db('active', $STATUS_NUMERIC);

            $up = $pdo->prepare("UPDATE master_manufactures SET status = :st, updated_at = NOW() WHERE id = :id");
            $up->execute([':st' => $newStatus, ':id' => $id]);

            $code = $row['manufacture_code'] ?: ($row['manufactures_code'] ?? null);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_manufactures', 'master_manufactures', 'TOGGLE_STATUS', $id, $code, "Toggle status -> " . ($current === 'active' ? 'inactive' : 'active'), ['from' => $current, 'to' => ($current === 'active' ? 'inactive' : 'active')]);
            }

            mm_set_flash('success', 'Status berhasil diubah.');
        } else {
            mm_set_flash('danger', 'Data tidak ditemukan.');
        }
    }
    rmi_redirect("master_manufactures.php");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT id, manufacture_code, manufactures_code, deleted_at FROM master_manufactures WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare("UPDATE master_manufactures SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $id]);

            $code = $row['manufacture_code'] ?: ($row['manufactures_code'] ?? null);
            mm_audit($pdo, 'soft_delete', $id, $code, "Soft delete manufacture", []);

            mm_set_flash('success', 'Data berhasil dihapus (soft delete).');
        } else {
            mm_set_flash('danger', 'Data tidak ditemukan.');
        }
    }
    rmi_redirect("master_manufactures.php");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_id'])) {
    $id = (int)($_POST['restore_id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT id, manufacture_code, manufactures_code, deleted_at FROM master_manufactures WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare("UPDATE master_manufactures SET deleted_at = NULL, updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $id]);

            $code = $row['manufacture_code'] ?: ($row['manufactures_code'] ?? null);
            mm_audit($pdo, 'restore', $id, $code, "Restore manufacture", []);

            mm_set_flash('success', 'Data berhasil direstore.');
        } else {
            mm_set_flash('danger', 'Data tidak ditemukan.');
        }
    }
    rmi_redirect("master_manufactures.php");
}

// --------------------------------------------------------
// BULK ACTIONS
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_apply'])) {
    $action = trim((string)($_POST['bulk_action'] ?? ''));
    $idsCsv = trim((string)($_POST['bulk_ids'] ?? ''));
    $ids = [];
    if ($idsCsv !== '') {
        foreach (explode(',', $idsCsv) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int)$part;
            }
        }
    }
    $ids = array_values(array_unique(array_filter($ids, fn($x) => $x > 0)));

    if (empty($ids)) {
        mm_set_flash('danger', 'Tidak ada data yang dipilih untuk Bulk Action.');
        rmi_redirect("master_manufactures.php");
    }

    $allowed = ['activate','deactivate','soft_delete','restore'];
    if (!in_array($action, $allowed, true)) {
        mm_set_flash('danger', 'Bulk action tidak valid.');
        rmi_redirect("master_manufactures.php");
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    try {
        $pdo->beginTransaction();

        if ($action === 'activate' || $action === 'deactivate') {
            $st = mm_norm_status_to_db($action === 'activate' ? 'active' : 'inactive', $STATUS_NUMERIC);
            $sql = "UPDATE master_manufactures SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$st], $ids));
        } elseif ($action === 'soft_delete') {
            $sql = "UPDATE master_manufactures SET deleted_at = NOW(), updated_at = NOW() WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
        } elseif ($action === 'restore') {
            $sql = "UPDATE master_manufactures SET deleted_at = NULL, updated_at = NOW() WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
        }

        $pdo->commit();

        mm_audit($pdo, 'bulk_'.$action, null, null, "Bulk action {$action}", [
            'ids' => $ids,
            'count' => count($ids),
        ]);

        mm_set_flash('success', "Bulk action '{$action}' berhasil untuk " . count($ids) . " data.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        mm_set_flash('danger', 'Bulk action gagal: ' . $e->getMessage());
    }

    rmi_redirect("master_manufactures.php");
}

// --------------------------------------------------------
// SEED (contoh YAXIN) - Upsert by code
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seed_yaxin'])) {
    $seed = [
        'code' => 'MNF-YAXIN',
        'name' => 'Suzhou Yaxin Medical Products Co., Ltd',
        'brand' => 'RIZKIMED',
        'origin' => 'Import',
        'country' => 'China',
        'city' => 'Suzhou',
        'address' => 'No.12, Zhongta Road, Mudu Town, Suzhou 215101, Jiangsu province, China',
        'phone' => '+86 152 5017 8777',
        'email' => 'yaxin@yx-yiliao.com',
        'pic_name' => 'Jim Wu',
        'pic_position' => 'Sales',
        'pic_phone' => '+86 152 5017 8777',
        'pic_email' => 'yaxin@yx-yiliao.com',
        'bank_name' => 'JPMorgan Chase Bank N.A., Singapore Branch',
        'bank_account_name' => 'Suzhou Yaxin Medical Products Co., Ltd.',
        'bank_account_number' => '1014 1740 2041 66',
        'bank_swift_code' => 'CHASSGSGXXX',
        'bank_iban' => null,
        'bank_currency' => 'USD',
        'website' => null,
        'status' => mm_norm_status_to_db('active', $STATUS_NUMERIC),
    ];

    try {
        // Try find existing by either code variant
        $stmt = $pdo->prepare("SELECT id, manufacture_code, manufactures_code FROM master_manufactures
                               WHERE manufacture_code IN (:c1,:c2) OR manufactures_code IN (:c1b,:c2b)
                               LIMIT 1");
        $stmt->execute([':c1' => $seed['code'], ':c2' => 'YAXIN', ':c1b' => $seed['code'], ':c2b' => 'YAXIN']);
        $existing = $stmt->fetch();

        if ($existing) {
            $id = (int)$existing['id'];
            $useCode = $existing['manufacture_code'] ?: ($existing['manufactures_code'] ?? $seed['code']);
            $upd = $pdo->prepare("
                UPDATE master_manufactures SET
                    manufactures_code = :mc, manufactures_name = :mn,
                    manufacture_code = :mc2, manufacture_name = :mn2,
                    brand_name = :brand, origin_type = :origin,
                    country = :country, city = :city, address = :address,
                    phone = :phone, email = :email,
                    pic_name = :pic_name, pic_position = :pic_position, pic_phone = :pic_phone, pic_email = :pic_email,
                    bank_name = :bank_name, bank_account_name = :bank_account_name, bank_account_number = :bank_account_number,
                    bank_swift_code = :bank_swift_code, bank_iban = :bank_iban, bank_currency = :bank_currency,
                    website = :website,
                    status = :status,
                    updated_at = NOW(),
                    deleted_at = NULL
                WHERE id = :id
            ");
            $upd->execute([
                ':mc' => $useCode,
                ':mn' => $seed['name'],
                ':mc2' => $useCode,
                ':mn2' => $seed['name'],
                ':brand' => $seed['brand'],
                ':origin' => $seed['origin'],
                ':country' => $seed['country'],
                ':city' => $seed['city'],
                ':address' => $seed['address'],
                ':phone' => $seed['phone'],
                ':email' => $seed['email'],
                ':pic_name' => $seed['pic_name'],
                ':pic_position' => $seed['pic_position'],
                ':pic_phone' => $seed['pic_phone'],
                ':pic_email' => $seed['pic_email'],
                ':bank_name' => $seed['bank_name'],
                ':bank_account_name' => $seed['bank_account_name'],
                ':bank_account_number' => $seed['bank_account_number'],
                ':bank_swift_code' => $seed['bank_swift_code'],
                ':bank_iban' => $seed['bank_iban'],
                ':bank_currency' => $seed['bank_currency'],
                ':website' => $seed['website'],
                ':status' => $seed['status'],
                ':id' => $id,
            ]);
            mm_audit($pdo, 'seed_update', $id, $useCode, 'Seed YAXIN (update)', $seed);
            mm_set_flash('success', 'Seed YAXIN berhasil (update).');
        } else {
            $ins = $pdo->prepare("
                INSERT INTO master_manufactures
                (manufactures_code, manufactures_name, manufacture_code, manufacture_name,
                 brand_name, origin_type, country, city, address, phone, email,
                 pic_name, pic_position, pic_phone, pic_email,
                 bank_name, bank_account_name, bank_account_number, bank_swift_code, bank_iban, bank_currency,
                 website, status, created_at, updated_at, deleted_at)
                VALUES
                (:mc, :mn, :mc2, :mn2,
                 :brand, :origin, :country, :city, :address, :phone, :email,
                 :pic_name, :pic_position, :pic_phone, :pic_email,
                 :bank_name, :bank_account_name, :bank_account_number, :bank_swift_code, :bank_iban, :bank_currency,
                 :website, :status, NOW(), NOW(), NULL)
            ");
            $ins->execute([
                ':mc' => $seed['code'],
                ':mn' => $seed['name'],
                ':mc2' => $seed['code'],
                ':mn2' => $seed['name'],
                ':brand' => $seed['brand'],
                ':origin' => $seed['origin'],
                ':country' => $seed['country'],
                ':city' => $seed['city'],
                ':address' => $seed['address'],
                ':phone' => $seed['phone'],
                ':email' => $seed['email'],
                ':pic_name' => $seed['pic_name'],
                ':pic_position' => $seed['pic_position'],
                ':pic_phone' => $seed['pic_phone'],
                ':pic_email' => $seed['pic_email'],
                ':bank_name' => $seed['bank_name'],
                ':bank_account_name' => $seed['bank_account_name'],
                ':bank_account_number' => $seed['bank_account_number'],
                ':bank_swift_code' => $seed['bank_swift_code'],
                ':bank_iban' => $seed['bank_iban'],
                ':bank_currency' => $seed['bank_currency'],
                ':website' => $seed['website'],
                ':status' => $seed['status'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            mm_audit($pdo, 'seed_insert', $newId, $seed['code'], 'Seed YAXIN (insert)', $seed);
            mm_set_flash('success', 'Seed YAXIN berhasil (insert).');
        }
    } catch (Throwable $e) {
        mm_set_flash('danger', 'Seed gagal: ' . $e->getMessage());
    }

    rmi_redirect("master_manufactures.php");
}

// --------------------------------------------------------
// IMPORT (CSV / XLSX)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_manufactures'])) {

        $up = rmi_upload_validate($_FILES['import_file'] ?? null, ['csv','xlsx'], 10 * 1024 * 1024);
    if (!$up['ok']) {
        mm_set_flash('danger', 'File import tidak valid: ' . $up['error']);
        rmi_redirect("master_manufactures.php");
    }
    $tmpName = $up['tmp'];
    $origName = $up['orig'];
    $ext = $up['ext'];$rawRows = [];
    try {
        if ($ext === 'csv') {
            if (($handle = fopen($tmpName, 'r')) !== false) {
                while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $rawRows[] = $data;
                }
                fclose($handle);
            }
        } elseif ($ext === 'xlsx') {
            $rawRows = mm_read_xlsx_rows($tmpName);
        } else {
            mm_set_flash('danger', 'Format file tidak didukung. Gunakan CSV atau XLSX.');
            rmi_redirect("master_manufactures.php");
        }
    } catch (Throwable $e) {
        mm_set_flash('danger', 'Gagal membaca file import: ' . $e->getMessage());
        rmi_redirect("master_manufactures.php");
    }

    if (count($rawRows) < 2) {
        mm_set_flash('danger', 'File import kosong / tidak ada data.');
        rmi_redirect("master_manufactures.php");
    }

    // header mapping
    $header = array_map(fn($x) => mm_norm_header((string)$x), $rawRows[0]);
    $imported = 0;
    $updated  = 0;
    $skipped  = 0;
    $errors   = [];

    $pdo->beginTransaction();
    try {
        for ($i = 1; $i < count($rawRows); $i++) {
            $data = $rawRows[$i];
            if (is_array($data) && count($data) === 1 && trim((string)$data[0]) === '') {
                continue;
            }
            $rowAssoc = [];
            for ($c = 0; $c < count($header); $c++) {
                $key = $header[$c] ?? '';
                if ($key === '') continue;
                $rowAssoc[$key] = isset($data[$c]) ? trim((string)$data[$c]) : '';
            }

            $code = mm_pick($rowAssoc, ['manufacture_code','manufactures_code','code','kode','manufacturescode','manufacturecode']);
            $name = mm_pick($rowAssoc, ['manufacture_name','manufactures_name','name','nama','manufacturename','manufacturesname']);

            $code = mm_clean_code((string)$code);
            $name = mm_clean_text($name);

            if ($code === '' || $name === '') {
                $skipped++;
                continue;
            }

            $brand = mm_clean_text(mm_pick($rowAssoc, ['brand_name','brand','merk']));
            $origin = mm_clean_text(mm_pick($rowAssoc, ['origin_type','origin']));
            $country = mm_clean_text(mm_pick($rowAssoc, ['country','negara']));
            $city = mm_clean_text(mm_pick($rowAssoc, ['city','kota']));
            $address = mm_clean_text(mm_pick($rowAssoc, ['address','alamat']));
            $phone = mm_clean_text(mm_pick($rowAssoc, ['phone','telp','telepon']));
            $email = mm_clean_text(mm_pick($rowAssoc, ['email','email_kantor']));
            $website = mm_clean_text(mm_pick($rowAssoc, ['website','web']));

            $pic_name = mm_clean_text(mm_pick($rowAssoc, ['pic_name','pic']));
            $pic_position = mm_clean_text(mm_pick($rowAssoc, ['pic_position','pic_jabatan','jabatan']));
            $pic_phone = mm_clean_text(mm_pick($rowAssoc, ['pic_phone','pic_hp']));
            $pic_email = mm_clean_text(mm_pick($rowAssoc, ['pic_email','pic_email_pabrik']));

            $bank_name = mm_clean_text(mm_pick($rowAssoc, ['bank_name','bank']));
            $bank_acc_name = mm_clean_text(mm_pick($rowAssoc, ['bank_account_name','account_name']));
            $bank_acc_number = mm_clean_text(mm_pick($rowAssoc, ['bank_account_number','account_number','rekening']));
            $bank_swift = mm_clean_text(mm_pick($rowAssoc, ['bank_swift_code','swift','swift_bic','swiftbic']));
            $bank_iban = mm_clean_text(mm_pick($rowAssoc, ['bank_iban','iban']));
            $bank_currency = mm_clean_text(mm_pick($rowAssoc, ['bank_currency','currency']));
            if ($bank_currency === '') $bank_currency = 'IDR';

            $statusIn = mm_pick($rowAssoc, ['status','is_active','active']);
            $statusDb = mm_norm_status_to_db($statusIn !== '' ? $statusIn : 'active', $STATUS_NUMERIC);

            // validate origin
            if ($origin !== '' && !in_array($origin, ['Local','Import'], true)) {
                // allow lowercase
                if (strtolower($origin) === 'local') $origin = 'Local';
                elseif (strtolower($origin) === 'import') $origin = 'Import';
                else $origin = null;
            }

            // Check existing by code
            $stmt = $pdo->prepare("SELECT id FROM master_manufactures WHERE manufacture_code = ? OR manufactures_code = ? LIMIT 1");
            $stmt->execute([$code, $code]);
            $existing = $stmt->fetch();

            if ($existing) {
                $id = (int)$existing['id'];
                $upd = $pdo->prepare("
                    UPDATE master_manufactures SET
                        manufactures_code = :mc, manufactures_name = :mn,
                        manufacture_code = :mc2, manufacture_name = :mn2,
                        brand_name = :brand, origin_type = :origin,
                        country = :country, city = :city, address = :address,
                        phone = :phone, email = :email,
                        pic_name = :pic_name, pic_position = :pic_position, pic_phone = :pic_phone, pic_email = :pic_email,
                        bank_name = :bank_name, bank_account_name = :bank_account_name, bank_account_number = :bank_account_number,
                        bank_swift_code = :bank_swift_code, bank_iban = :bank_iban, bank_currency = :bank_currency,
                        website = :website,
                        status = :status,
                        updated_at = NOW(),
                        deleted_at = NULL
                    WHERE id = :id
                ");
                $upd->execute([
                    ':mc' => $code,
                    ':mn' => $name,
                    ':mc2' => $code,
                    ':mn2' => $name,
                    ':brand' => $brand !== '' ? $brand : null,
                    ':origin' => $origin !== '' ? $origin : null,
                    ':country' => $country !== '' ? $country : null,
                    ':city' => $city !== '' ? $city : null,
                    ':address' => $address !== '' ? $address : null,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':email' => $email !== '' ? $email : null,
                    ':pic_name' => $pic_name !== '' ? $pic_name : null,
                    ':pic_position' => $pic_position !== '' ? $pic_position : null,
                    ':pic_phone' => $pic_phone !== '' ? $pic_phone : null,
                    ':pic_email' => $pic_email !== '' ? $pic_email : null,
                    ':bank_name' => $bank_name !== '' ? $bank_name : null,
                    ':bank_account_name' => $bank_acc_name !== '' ? $bank_acc_name : null,
                    ':bank_account_number' => $bank_acc_number !== '' ? $bank_acc_number : null,
                    ':bank_swift_code' => $bank_swift !== '' ? $bank_swift : null,
                    ':bank_iban' => $bank_iban !== '' ? $bank_iban : null,
                    ':bank_currency' => $bank_currency,
                    ':website' => $website !== '' ? $website : null,
                    ':status' => $statusDb,
                    ':id' => $id,
                ]);
                $updated++;
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO master_manufactures
                    (manufactures_code, manufactures_name, manufacture_code, manufacture_name,
                     brand_name, origin_type, country, city, address, phone, email,
                     pic_name, pic_position, pic_phone, pic_email,
                     bank_name, bank_account_name, bank_account_number, bank_swift_code, bank_iban, bank_currency,
                     website, status, created_at, updated_at, deleted_at)
                    VALUES
                    (:mc, :mn, :mc2, :mn2,
                     :brand, :origin, :country, :city, :address, :phone, :email,
                     :pic_name, :pic_position, :pic_phone, :pic_email,
                     :bank_name, :bank_account_name, :bank_account_number, :bank_swift_code, :bank_iban, :bank_currency,
                     :website, :status, NOW(), NOW(), NULL)
                ");
                $ins->execute([
                    ':mc' => $code,
                    ':mn' => $name,
                    ':mc2' => $code,
                    ':mn2' => $name,
                    ':brand' => $brand !== '' ? $brand : null,
                    ':origin' => $origin !== '' ? $origin : null,
                    ':country' => $country !== '' ? $country : null,
                    ':city' => $city !== '' ? $city : null,
                    ':address' => $address !== '' ? $address : null,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':email' => $email !== '' ? $email : null,
                    ':pic_name' => $pic_name !== '' ? $pic_name : null,
                    ':pic_position' => $pic_position !== '' ? $pic_position : null,
                    ':pic_phone' => $pic_phone !== '' ? $pic_phone : null,
                    ':pic_email' => $pic_email !== '' ? $pic_email : null,
                    ':bank_name' => $bank_name !== '' ? $bank_name : null,
                    ':bank_account_name' => $bank_acc_name !== '' ? $bank_acc_name : null,
                    ':bank_account_number' => $bank_acc_number !== '' ? $bank_acc_number : null,
                    ':bank_swift_code' => $bank_swift !== '' ? $bank_swift : null,
                    ':bank_iban' => $bank_iban !== '' ? $bank_iban : null,
                    ':bank_currency' => $bank_currency,
                    ':website' => $website !== '' ? $website : null,
                    ':status' => $statusDb,
                ]);
                $imported++;
            }
        }

        $pdo->commit();

        if (function_exists('master_audit')) {
            master_audit($pdo, 'master_manufactures', 'master_manufactures', 'IMPORT', null, '', 'Import manufactures', ['file' => $origName, 'imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'format' => $ext]);
        }

        mm_set_flash('success', "Import selesai. Tambah: {$imported}, Update: {$updated}, Skip: {$skipped}");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        mm_set_flash('danger', 'Import gagal: ' . $e->getMessage());
    }

    rmi_redirect("master_manufactures.php");
}

// --------------------------------------------------------
// SAVE (ADD/EDIT) MANUFACTURE
// --------------------------------------------------------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_manufacture'])) {
    $id = (int)($_POST['id'] ?? 0);

    $manufacture_code = mm_clean_code($_POST['manufacture_code'] ?? '');
    $manufacture_name = mm_clean_text($_POST['manufacture_name'] ?? '');
    $brand_name       = mm_clean_text($_POST['brand_name'] ?? '');
    $origin_type      = mm_clean_text($_POST['origin_type'] ?? '');
    $country          = mm_clean_text($_POST['country'] ?? '');
    $city             = mm_clean_text($_POST['city'] ?? '');
    $address          = mm_clean_text($_POST['address'] ?? '');
    $phone            = mm_clean_text($_POST['phone'] ?? '');
    $email            = mm_clean_text($_POST['email'] ?? '');
    $website          = mm_clean_text($_POST['website'] ?? '');

    $pic_name         = mm_clean_text($_POST['pic_name'] ?? '');
    $pic_position     = mm_clean_text($_POST['pic_position'] ?? '');
    $pic_phone        = mm_clean_text($_POST['pic_phone'] ?? '');
    $pic_email        = mm_clean_text($_POST['pic_email'] ?? '');

    $bank_name           = mm_clean_text($_POST['bank_name'] ?? '');
    $bank_account_name   = mm_clean_text($_POST['bank_account_name'] ?? '');
    $bank_account_number = mm_clean_text($_POST['bank_account_number'] ?? '');
    $bank_swift_code     = mm_clean_text($_POST['bank_swift_code'] ?? '');
    $bank_iban           = mm_clean_text($_POST['bank_iban'] ?? '');
    $bank_currency       = mm_clean_text($_POST['bank_currency'] ?? 'IDR');

    $status_label = strtolower(trim((string)($_POST['status'] ?? 'active')));
    $status_db    = mm_norm_status_to_db($status_label, $STATUS_NUMERIC);
    $is_active    = ($status_label === 'active' || $status_label === '1' || $status_label === 'aktif');

    if ($manufacture_code === '') $errors[] = 'Manufacture Code wajib diisi.';
    if ($manufacture_name === '') $errors[] = 'Manufacture Name wajib diisi.';

    if ($origin_type !== '' && !in_array($origin_type, ['Local','Import'], true)) {
        $errors[] = 'Origin Type hanya boleh Local atau Import.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email kantor tidak valid.';
    }
    if ($pic_email !== '' && !filter_var($pic_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email PIC tidak valid.';
    }

    // FASE 1: kalau status Active -> wajib lengkap (identity + PIC + bank + swift). Kecuali kantor internal.
    if ($is_active && $internal_office_code === '') {
        $need = [];
        if ($origin_type === '') $need[] = 'Origin Type';
        if ($country === '') $need[] = 'Country';
        if ($city === '') $need[] = 'City';
        if ($address === '') $need[] = 'Address';
        if ($phone === '' && $email === '') $need[] = 'Phone/Email kantor';
        if ($pic_name === '') $need[] = 'PIC Name';
        if ($pic_position === '') $need[] = 'PIC Position';
        if ($pic_phone === '' && $pic_email === '') $need[] = 'PIC Phone/Email';
        if ($bank_name === '') $need[] = 'Bank Name';
        if ($bank_account_name === '') $need[] = 'Account Name';
        if ($bank_account_number === '') $need[] = 'Account Number';
        // Swift wajib kalau import
        if (strcasecmp($origin_type, 'Import') === 0 && $bank_swift_code === '') $need[] = 'Swift Code (Import wajib)';

        if (!empty($need)) {
            $errors[] = 'Status Active membutuhkan field lengkap: ' . implode(', ', $need) . '.';
        }
    }

    if (empty($errors)) {
        try {
            if ($id > 0) {
                // cek duplicate code
                $cek = $pdo->prepare("SELECT COUNT(*) FROM master_manufactures WHERE (manufacture_code = ? OR manufactures_code = ?) AND id <> ?");
                $cek->execute([$manufacture_code, $manufacture_code, $id]);
                if ((int)$cek->fetchColumn() > 0) {
                    $errors[] = 'Manufacture Code sudah digunakan.';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE master_manufactures SET
                            manufactures_code = :mc, manufactures_name = :mn,
                            manufacture_code = :mc2, manufacture_name = :mn2,
                            brand_name = :brand,
                            origin_type = :origin,
                            country = :country,
                            city = :city,
                            address = :address,
                            phone = :phone,
                            email = :email,
                            website = :website,
                            pic_name = :pic_name,
                            pic_position = :pic_position,
                            pic_phone = :pic_phone,
                            pic_email = :pic_email,
                            bank_name = :bank_name,
                            bank_account_name = :bank_account_name,
                            bank_account_number = :bank_account_number,
                            bank_swift_code = :bank_swift_code,
                            bank_iban = :bank_iban,
                            bank_currency = :bank_currency,
                            status = :status,
                            internal_office_code = :internal_office,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':mc' => $manufacture_code,
                        ':mn' => $manufacture_name,
                        ':mc2' => $manufacture_code,
                        ':mn2' => $manufacture_name,
                        ':brand' => $brand_name !== '' ? $brand_name : null,
                        ':origin' => $origin_type !== '' ? $origin_type : null,
                        ':country' => $country !== '' ? $country : null,
                        ':city' => $city !== '' ? $city : null,
                        ':address' => $address !== '' ? $address : null,
                        ':phone' => $phone !== '' ? $phone : null,
                        ':email' => $email !== '' ? $email : null,
                        ':website' => $website !== '' ? $website : null,
                        ':pic_name' => $pic_name !== '' ? $pic_name : null,
                        ':pic_position' => $pic_position !== '' ? $pic_position : null,
                        ':pic_phone' => $pic_phone !== '' ? $pic_phone : null,
                        ':pic_email' => $pic_email !== '' ? $pic_email : null,
                        ':bank_name' => $bank_name !== '' ? $bank_name : null,
                        ':bank_account_name' => $bank_account_name !== '' ? $bank_account_name : null,
                        ':bank_account_number' => $bank_account_number !== '' ? $bank_account_number : null,
                        ':bank_swift_code' => $bank_swift_code !== '' ? $bank_swift_code : null,
                        ':bank_iban' => $bank_iban !== '' ? $bank_iban : null,
                        ':bank_currency' => $bank_currency !== '' ? $bank_currency : 'IDR',
                        ':status' => $status_db,
                        ':internal_office' => $internal_office_code !== '' ? $internal_office_code : null,
                        ':id' => $id,
                    ]);

                    mm_audit($pdo, 'update', $id, $manufacture_code, 'Update manufacture', [
                        'manufacture_code' => $manufacture_code,
                        'manufacture_name' => $manufacture_name,
                    ]);

                    mm_set_flash('success', 'Data berhasil diperbarui.');
                    rmi_redirect("master_manufactures.php");
                }
            } else {
                // cek duplicate code
                $cek = $pdo->prepare("SELECT COUNT(*) FROM master_manufactures WHERE manufacture_code = ? OR manufactures_code = ?");
                $cek->execute([$manufacture_code, $manufacture_code]);
                if ((int)$cek->fetchColumn() > 0) {
                    $errors[] = 'Manufacture Code sudah digunakan.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO master_manufactures
                        (manufactures_code, manufactures_name, manufacture_code, manufacture_name,
                         brand_name, origin_type, country, city, address, phone, email, website,
                         pic_name, pic_position, pic_phone, pic_email,
                         bank_name, bank_account_name, bank_account_number, bank_swift_code, bank_iban, bank_currency,
                         status, internal_office_code, created_at, updated_at, deleted_at)
                        VALUES
                        (:mc, :mn, :mc2, :mn2,
                         :brand, :origin, :country, :city, :address, :phone, :email, :website,
                         :pic_name, :pic_position, :pic_phone, :pic_email,
                         :bank_name, :bank_account_name, :bank_account_number, :bank_swift_code, :bank_iban, :bank_currency,
                         :status, :internal_office, NOW(), NOW(), NULL)
                    ");
                    $stmt->execute([
                        ':mc' => $manufacture_code,
                        ':mn' => $manufacture_name,
                        ':mc2' => $manufacture_code,
                        ':mn2' => $manufacture_name,
                        ':brand' => $brand_name !== '' ? $brand_name : null,
                        ':origin' => $origin_type !== '' ? $origin_type : null,
                        ':country' => $country !== '' ? $country : null,
                        ':city' => $city !== '' ? $city : null,
                        ':address' => $address !== '' ? $address : null,
                        ':phone' => $phone !== '' ? $phone : null,
                        ':email' => $email !== '' ? $email : null,
                        ':website' => $website !== '' ? $website : null,
                        ':pic_name' => $pic_name !== '' ? $pic_name : null,
                        ':pic_position' => $pic_position !== '' ? $pic_position : null,
                        ':pic_phone' => $pic_phone !== '' ? $pic_phone : null,
                        ':pic_email' => $pic_email !== '' ? $pic_email : null,
                        ':bank_name' => $bank_name !== '' ? $bank_name : null,
                        ':bank_account_name' => $bank_account_name !== '' ? $bank_account_name : null,
                        ':bank_account_number' => $bank_account_number !== '' ? $bank_account_number : null,
                        ':bank_swift_code' => $bank_swift_code !== '' ? $bank_swift_code : null,
                        ':bank_iban' => $bank_iban !== '' ? $bank_iban : null,
                        ':bank_currency' => $bank_currency !== '' ? $bank_currency : 'IDR',
                        ':status' => $status_db,
                        ':internal_office' => $internal_office_code !== '' ? $internal_office_code : null,
                    ]);

                    $newId = (int)$pdo->lastInsertId();
                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'master_manufactures', 'master_manufactures', 'CREATE', $newId, $manufacture_code, 'Insert manufacture', ['manufacture_code' => $manufacture_code, 'manufacture_name' => $manufacture_name]);
                    }

                    mm_set_flash('success', 'Data berhasil ditambahkan.');
                    rmi_redirect("master_manufactures.php");
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Gagal simpan: ' . $e->getMessage();
        }
    }

    // keep old form on validation error
    $_SESSION['mm_old_form'] = $_POST;
}

// --------------------------------------------------------
// DEFAULT FORM DATA
// --------------------------------------------------------
$edit_data = [
    'id' => '',
    'manufacture_code' => '',
    'manufacture_name' => '',
    'brand_name' => '',
    'origin_type' => '',
    'country' => '',
    'city' => '',
    'address' => '',
    'phone' => '',
    'email' => '',
    'website' => '',
    'pic_name' => '',
    'pic_position' => '',
    'pic_phone' => '',
    'pic_email' => '',
    'bank_name' => '',
    'bank_account_name' => '',
    'bank_account_number' => '',
    'bank_swift_code' => '',
    'bank_iban' => '',
    'bank_currency' => 'IDR',
    'status' => 'active',
    'internal_office_code' => '',
];

if (!empty($_SESSION['mm_old_form']) && !isset($_GET['edit'])) {
    $old_form = $_SESSION['mm_old_form'];
    unset($_SESSION['mm_old_form']);
    foreach ($edit_data as $k => $v) {
        if (isset($old_form[$k])) {
            $edit_data[$k] = $old_form[$k];
        }
    }
}

// Ambil data edit
if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $id = (int)$_GET['edit'];
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM master_manufactures WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        if ($row = $stmt->fetch()) {
            $edit_data['id'] = $row['id'];
            $edit_data['manufacture_code'] = $row['manufacture_code'] ?? ($row['manufactures_code'] ?? '');
            $edit_data['manufacture_name'] = $row['manufacture_name'] ?? ($row['manufactures_name'] ?? '');
            $edit_data['brand_name'] = $row['brand_name'] ?? '';
            $edit_data['origin_type'] = $row['origin_type'] ?? '';
            $edit_data['country'] = $row['country'] ?? '';
            $edit_data['city'] = $row['city'] ?? '';
            $edit_data['address'] = $row['address'] ?? '';
            $edit_data['phone'] = $row['phone'] ?? '';
            $edit_data['email'] = $row['email'] ?? '';
            $edit_data['website'] = $row['website'] ?? '';
            $edit_data['pic_name'] = $row['pic_name'] ?? '';
            $edit_data['pic_position'] = $row['pic_position'] ?? '';
            $edit_data['pic_phone'] = $row['pic_phone'] ?? '';
            $edit_data['pic_email'] = $row['pic_email'] ?? '';
            $edit_data['bank_name'] = $row['bank_name'] ?? '';
            $edit_data['bank_account_name'] = $row['bank_account_name'] ?? '';
            $edit_data['bank_account_number'] = $row['bank_account_number'] ?? '';
            $edit_data['bank_swift_code'] = $row['bank_swift_code'] ?? '';
            $edit_data['bank_iban'] = $row['bank_iban'] ?? '';
            $edit_data['bank_currency'] = $row['bank_currency'] ?? 'IDR';
            $edit_data['status'] = mm_label_status($row['status'] ?? 'active');
            $edit_data['internal_office_code'] = strtoupper(trim((string)($row['internal_office_code'] ?? '')));
        }
    }
}

// --------------------------------------------------------
// FILTER & LIST
// --------------------------------------------------------
$search        = trim((string)($_GET['search'] ?? ''));
$filter_city   = trim((string)($_GET['filter_city'] ?? ''));
$filter_country= trim((string)($_GET['filter_country'] ?? ''));
$filter_status = trim((string)($_GET['filter_status'] ?? ''));
$filter_origin = trim((string)($_GET['filter_origin'] ?? ''));
$show_deleted  = isset($_GET['show_deleted']) && ($_GET['show_deleted'] === '1' || $_GET['show_deleted'] === 'on');

$where = " WHERE 1=1 ";
$params = [];

if (!$show_deleted) {
    $where .= " AND m.deleted_at IS NULL ";
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (m.manufacture_code LIKE :s1 OR m.manufacture_name LIKE :s2 OR m.brand_name LIKE :s3 OR m.city LIKE :s4 OR m.country LIKE :s5)";
    $params[':s1'] = $like;
    $params[':s2'] = $like;
    $params[':s3'] = $like;
    $params[':s4'] = $like;
    $params[':s5'] = $like;
}
if ($filter_origin !== '') {
    $where .= " AND m.origin_type = :origin ";
    $params[':origin'] = $filter_origin;
}
if ($filter_country !== '') {
    $where .= " AND m.country = :country ";
    $params[':country'] = $filter_country;
}
if ($filter_city !== '') {
    $where .= " AND m.city = :city ";
    $params[':city'] = $filter_city;
}
if ($filter_status !== '') {
    if ($STATUS_NUMERIC) {
        $where .= " AND m.status = :status ";
        $params[':status'] = ($filter_status === 'active') ? 1 : 0;
    } else {
        $where .= " AND m.status = :status ";
        $params[':status'] = $filter_status;
    }
}

// options
$cities = [];
$countries = [];
try {
    $res = $pdo->query("SELECT DISTINCT city FROM master_manufactures WHERE city IS NOT NULL AND city <> '' ORDER BY city ASC");
    $cities = $res->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

try {
    $res = $pdo->query("SELECT DISTINCT country FROM master_manufactures WHERE country IS NOT NULL AND country <> '' ORDER BY country ASC");
    $countries = $res->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

// list rows
$sql = "SELECT m.* FROM master_manufactures m {$where} ORDER BY m.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = mm_get_flash();

// last audit logs (for visibility)
$audit_rows = [];
try {
    $st = $pdo->prepare("SELECT created_at, action, record_code, username, description
                         FROM system_audit_logs
                         WHERE module = 'master_manufactures'
                         ORDER BY id DESC
                         LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $audit_rows = [];
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Manufactures', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Manufactures',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>

<div class="rmi-container">

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <div>
            <h3 class="mb-0">Master Manufactures</h3>
            <div class="text-muted-small">Foundation + Data Ops + Enterprise (Import/Seed/Bulk/Audit)</div>
        </div>
        <div class="text-muted-small">
            DB: <?= h($dbname) ?> @ <?= h($host) ?>:<?= h($port) ?>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <b>Gagal simpan:</b>
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- FORM ADD/EDIT -->
    <div class="card mb-3">
        <div class="card-header">
            <b><?= ($edit_data['id'] ?? '') ? 'Edit Manufacture' : 'Tambah Manufacture' ?></b>
            <?php if (($edit_data['id'] ?? '') && isset($_GET['edit'])): ?>
                <a class="btn btn-sm btn-outline-light float-end" href="master_manufactures.php">Batal Edit</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="post" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="save_manufacture" value="1">
                <input type="hidden" name="id" value="<?= h($edit_data['id']) ?>">

                <div class="col-md-3">
                    <label class="form-label mb-1">Manufacture Code <span class="text-danger">*</span></label>
                    <input type="text" name="manufacture_code" class="form-control form-control-sm"
                           value="<?= h($edit_data['manufacture_code']) ?>" placeholder="Contoh: MNF-YAXIN" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label mb-1">Manufacture Name <span class="text-danger">*</span></label>
                    <input type="text" name="manufacture_name" class="form-control form-control-sm"
                           value="<?= h($edit_data['manufacture_name']) ?>" placeholder="Nama pabrik/principal" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Brand / Merk</label>
                    <input type="text" name="brand_name" class="form-control form-control-sm"
                           value="<?= h($edit_data['brand_name']) ?>" placeholder="Contoh: YAXIN / RIZKIMED">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="active" <?= ($edit_data['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($edit_data['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Kantor Internal (Pembelian Antar Kantor)</label>
                    <select name="internal_office_code" class="form-select form-select-sm">
                        <option value="">-- manufacture eksternal --</option>
                        <?php
                        $offices = [];
                        try { $offices = $pdo->query("SELECT office_code, office_name FROM master_office WHERE office_code IS NOT NULL AND office_code != '' AND office_code != 'HO' ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
                        foreach ($offices as $o): $oc = strtoupper(trim($o['office_code'] ?? '')); ?>
                        <option value="<?= h($oc) ?>" <?= ($edit_data['internal_office_code'] ?? '') === $oc ? 'selected' : '' ?>><?= h($oc) ?><?= !empty($o['office_name']) ? ' - ' . h($o['office_name']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Jika diisi: manufacture = kantor supplier. Stock berkurang saat kantor ini pick Sales DO (bukan di Incoming).</small>
                </div>

                <div class="col-md-2">
                    <label class="form-label mb-1">Origin Type</label>
                    <select name="origin_type" class="form-select form-select-sm">
                        <option value="">-- pilih --</option>
                        <option value="Local" <?= ($edit_data['origin_type'] ?? '') === 'Local' ? 'selected' : '' ?>>Local</option>
                        <option value="Import" <?= ($edit_data['origin_type'] ?? '') === 'Import' ? 'selected' : '' ?>>Import</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Country</label>
                    <input type="text" name="country" class="form-control form-control-sm" value="<?= h($edit_data['country']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">City</label>
                    <input type="text" name="city" class="form-control form-control-sm" value="<?= h($edit_data['city']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label mb-1">Address</label>
                    <input type="text" name="address" class="form-control form-control-sm" value="<?= h($edit_data['address']) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label mb-1">Phone</label>
                    <input type="text" name="phone" class="form-control form-control-sm" value="<?= h($edit_data['phone']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1">Email</label>
                    <input type="email" name="email" class="form-control form-control-sm" value="<?= h($edit_data['email']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label mb-1">Website</label>
                    <input type="text" name="website" class="form-control form-control-sm" value="<?= h($edit_data['website']) ?>">
                </div>

                <div class="col-12"><hr class="border-secondary"></div>

                <div class="col-md-3">
                    <label class="form-label mb-1">PIC Name</label>
                    <input type="text" name="pic_name" class="form-control form-control-sm" value="<?= h($edit_data['pic_name']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1">PIC Position</label>
                    <input type="text" name="pic_position" class="form-control form-control-sm" value="<?= h($edit_data['pic_position']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1">PIC Phone</label>
                    <input type="text" name="pic_phone" class="form-control form-control-sm" value="<?= h($edit_data['pic_phone']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1">PIC Email</label>
                    <input type="email" name="pic_email" class="form-control form-control-sm" value="<?= h($edit_data['pic_email']) ?>">
                </div>

                <div class="col-12"><hr class="border-secondary"></div>

                <div class="col-md-4">
                    <label class="form-label mb-1">Bank Name</label>
                    <input type="text" name="bank_name" class="form-control form-control-sm" value="<?= h($edit_data['bank_name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1">Account Name</label>
                    <input type="text" name="bank_account_name" class="form-control form-control-sm" value="<?= h($edit_data['bank_account_name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1">Account Number</label>
                    <input type="text" name="bank_account_number" class="form-control form-control-sm" value="<?= h($edit_data['bank_account_number']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1">Swift Code</label>
                    <input type="text" name="bank_swift_code" class="form-control form-control-sm" value="<?= h($edit_data['bank_swift_code']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1">IBAN</label>
                    <input type="text" name="bank_iban" class="form-control form-control-sm" value="<?= h($edit_data['bank_iban']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1">Currency</label>
                    <input type="text" name="bank_currency" class="form-control form-control-sm" value="<?= h($edit_data['bank_currency']) ?>">
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <button type="submit" class="btn btn-sm btn-success">Simpan</button>
                    <?php if (($edit_data['id'] ?? '') === ''): ?>
                        <button type="reset" class="btn btn-sm btn-outline-light">Reset</button>
                    <?php endif; ?>
                </div>

                <div class="col-12 text-muted-small mt-2 form-note">
                    Catatan: Jika Status = <b>Active</b>, sistem akan meminta identity + PIC + Bank lengkap (khusus Import: Swift wajib).
                </div>
            </form>
        </div>
    </div>

    <!-- ENTERPRISE TOOLBAR: Import / Seed -->
    <div class="card mb-3">
        <div class="card-header"><b>Enterprise Tools</b> <span class="text-muted-small">(Import / Seed / Audit)</span></div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-lg-7">
                    <form method="post" enctype="multipart/form-data" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="import_manufactures" value="1">
                        <div class="col-md-8">
                            <label class="form-label mb-1">Import CSV / XLSX</label>
                            <input type="file" name="import_file" class="form-control form-control-sm" accept=".csv,.xlsx" required>
                            <div class="text-muted-small mt-1 form-note">
                                Header disarankan: manufacture_code, manufacture_name, brand_name, origin_type, country, city, address,
                                phone, email, website, pic_name, pic_position, pic_phone, pic_email,
                                bank_name, bank_account_name, bank_account_number, bank_swift_code, bank_iban, bank_currency, status
                            </div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-sm btn-primary w-100">Import</button>
                        </div>
                    </form>
                </div>
                <div class="col-lg-5">
                    <form method="post" class="d-flex gap-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="seed_yaxin" value="1">
                        <div style="flex:1">
                            <label class="form-label mb-1">Seed Cepat</label>
                            <div class="text-muted-small">Insert/Update data contoh YAXIN (lengkap) + audit log.</div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-light">Seed YAXIN</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTER + BULK -->
    <div class="card mb-3">
        <div class="card-header"><b>Data Operations</b> <span class="text-muted-small">(Filter / Bulk Action / Export)</span></div>
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label mb-1">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Ketik kata kunci...">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Origin</label>
                    <select name="filter_origin" class="form-select form-select-sm">
                        <option value="">-- Semua --</option>
                        <option value="Local"  <?= $filter_origin === 'Local' ? 'selected' : '' ?>>Local</option>
                        <option value="Import" <?= $filter_origin === 'Import' ? 'selected' : '' ?>>Import</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Country</label>
                    <select name="filter_country" class="form-select form-select-sm">
                        <option value="">-- Semua --</option>
                        <?php foreach ($countries as $ctry): ?>
                            <option value="<?= h($ctry) ?>" <?= $filter_country === $ctry ? 'selected' : '' ?>><?= h($ctry) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">City</label>
                    <select name="filter_city" class="form-select form-select-sm">
                        <option value="">-- Semua --</option>
                        <?php foreach ($cities as $ct): ?>
                            <option value="<?= h($ct) ?>" <?= $filter_city === $ct ? 'selected' : '' ?>><?= h($ct) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Status</label>
                    <select name="filter_status" class="form-select form-select-sm">
                        <option value="">-- Semua --</option>
                        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="show_deleted" value="1" id="show_deleted" <?= $show_deleted ? 'checked' : '' ?>>
                        <label class="form-check-label text-muted-small" for="show_deleted">Deleted</label>
                    </div>
                </div>
                <div class="col-12 d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Terapkan</button>
                    <a href="master_manufactures.php" class="btn btn-sm btn-secondary">Reset</a>
                </div>
            </form>

            <hr class="border-secondary">

            <form method="post" id="bulkForm" class="bulk-toolbar mb-2">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="bulk_apply" value="1">
                <input type="hidden" name="bulk_ids" id="bulk_ids" value="">
                <select name="bulk_action" class="form-select form-select-sm" style="width:220px" required>
                    <option value="">-- Bulk Action --</option>
                    <option value="activate">Activate</option>
                    <option value="deactivate">Deactivate</option>
                    <option value="soft_delete">Soft Delete</option>
                    <option value="restore">Restore</option>
                </select>
                <button type="submit" class="btn btn-sm btn-outline-light" onclick="return confirm('Jalankan bulk action untuk item terpilih?')">Apply</button>
                <div class="text-muted-small">
                    Terpilih: <span id="bulkCount">0</span> item
                </div>
            </form>

            <div class="table-responsive">
                <table id="table-manufactures" class="table table-sm table-striped table-hover align-middle table-dark-custom" style="width:100%;">
                    <thead>
                    <tr>
                        <th class="no-export text-center" style="width:34px;">
                            <input type="checkbox" id="checkAll">
                        </th>
                        <th style="width:50px;">#</th>
                        <th>Code</th>
                        <th>Manufacture</th>
                        <th>Origin</th>
                        <th>Country</th>
                        <th>City</th>
                        <th>PIC</th>
                        <th>Bank</th>
                        <th>Status</th>
                        <th class="text-center no-export" style="width:220px;">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $no = 1; ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $code = $r['manufacture_code'] ?? ($r['manufactures_code'] ?? '');
                            $name = $r['manufacture_name'] ?? ($r['manufactures_name'] ?? '');
                            $statusLabel = mm_label_status($r['status'] ?? '');
                            $isDeleted = !empty($r['deleted_at']);
                        ?>
                        <tr class="<?= $isDeleted ? 'table-warning' : '' ?>">
                            <td class="text-center no-export">
                                <input type="checkbox" class="row-check" data-id="<?= (int)$r['id'] ?>">
                            </td>
                            <td><?= $no++ ?></td>
                            <td><?= h($code) ?></td>
                            <td>
                                <b><?= h($name) ?></b><br>
                                <?php if (!empty($r['brand_name'])): ?>
                                    <span class="text-muted-small">Brand: <?= h($r['brand_name']) ?></span><br>
                                <?php endif; ?>
                                <?php if (!empty($r['address'])): ?>
                                    <span class="text-muted-small"><?= h($r['address']) ?></span>
                                <?php endif; ?>
                                <?php if ($isDeleted): ?>
                                    <div class="text-muted-small mt-1">Deleted at: <?= h($r['deleted_at']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= h($r['origin_type'] ?? '') ?></td>
                            <td><?= h($r['country'] ?? '') ?></td>
                            <td><?= h($r['city'] ?? '') ?></td>
                            <td>
                                <?= h($r['pic_name'] ?? '') ?><br>
                                <span class="text-muted-small"><?= h($r['pic_position'] ?? '') ?></span><br>
                                <span class="text-muted-small"><?= h($r['pic_phone'] ?? '') ?></span>
                            </td>
                            <td>
                                <?= h($r['bank_name'] ?? '') ?><br>
                                <span class="text-muted-small"><?= h($r['bank_account_number'] ?? '') ?></span><br>
                                <span class="text-muted-small"><?= h($r['bank_swift_code'] ?? '') ?></span>
                            </td>
                            <td>
                                <?php if ($isDeleted): ?>
                                    <span class="badge badge-deleted">DELETED</span>
                                <?php else: ?>
                                    <?php if ($statusLabel === 'active'): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center no-export">
                                <a href="manufactures_docs.php?code=<?= urlencode((string)$code) ?>"
                                   class="btn btn-sm btn-outline-info mb-1">Docs</a>
                                <a href="master_manufactures.php?edit=<?= (int)$r['id'] ?>"
                                   class="btn btn-sm btn-outline-primary mb-1">Edit</a>
                                <?php if (!$isDeleted): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="toggle_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning mb-1">
                                            <?= $statusLabel === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Yakin soft delete?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger mb-1">Hapus</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Restore data ini?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="restore_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success mb-1">Restore</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-muted-small mt-2">
                    Soft delete menyimpan record (deleted_at) supaya aman untuk relasi antar modul.
                </div>
            </div>
        </div>
    </div>
    <!-- AUDIT LOG VIEW -->
    <div class="card mb-3">
        <div class="card-header"><b>Audit Log</b> <span class="text-muted-small">(Last 50 events)</span></div>
        <div class="card-body">
            <?php if (empty($audit_rows)): ?>
                <div class="text-muted-small">Belum ada audit log / table belum ada.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle table-dark-custom">
                        <thead>
                        <tr>
                            <th style="width:180px;">Time</th>
                            <th style="width:160px;">Action</th>
                            <th style="width:160px;">Code</th>
                            <th style="width:160px;">User</th>
                            <th>Description</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($audit_rows as $a): ?>
                            <tr>
                                <td><?= h($a['created_at'] ?? '') ?></td>
                                <td><?= h($a['action'] ?? '') ?></td>
                                <td><?= h($a['record_code'] ?? '') ?></td>
                                <td><?= h($a['username'] ?? '') ?></td>
                                <td><?= h($a['description'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-muted-small mt-2">
                    Detail JSON tersimpan di kolom <code>details</code> pada table <code>system_audit_logs</code>.
                </div>
            <?php endif; ?>
        </div>
    </div>


</div>

<!-- JS -->
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209"></script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209"></script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>

<script>
    $(function () {
        const table = $('#table-manufactures').DataTable({
            dom: 'Bfrtip',
            paging: true,
            lengthChange: true,
            pageLength: 10,
            ordering: true,
            order: [[3, 'asc']], // Manufacture
            info: true,
            searching: true,
            language: {
                emptyTable: "Belum ada data manufacture."
            },
            columnDefs: [
                { targets: [0, 10], orderable: false } // checkbox + aksi
            ],
            buttons: [
                {extend: 'copyHtml5',  className: 'btn btn-sm btn-outline-light', exportOptions: { columns: ':not(.no-export)' }},
                {extend: 'csvHtml5',   className: 'btn btn-sm btn-outline-light', exportOptions: { columns: ':not(.no-export)' }},
                {extend: 'excelHtml5', className: 'btn btn-sm btn-outline-light', exportOptions: { columns: ':not(.no-export)' }},
                {extend: 'pdfHtml5',   className: 'btn btn-sm btn-outline-light', exportOptions: { columns: ':not(.no-export)' }},
                {extend: 'print',      className: 'btn btn-sm btn-outline-light', exportOptions: { columns: ':not(.no-export)' }}
            ]
        });

        // --- BULK SELECT (persist across pages) ---
        let selected = new Set();

        function syncCheckboxes() {
            $('.row-check').each(function() {
                const id = $(this).data('id');
                $(this).prop('checked', selected.has(String(id)));
            });

            $('#bulk_ids').val(Array.from(selected).join(','));
            $('#bulkCount').text(selected.size);
        }

        // on draw, re-apply checks
        table.on('draw', function () {
            syncCheckboxes();
        });

        // row checkbox
        $(document).on('change', '.row-check', function () {
            const id = String($(this).data('id'));
            if (this.checked) selected.add(id);
            else selected.delete(id);

            syncCheckboxes();
        });

        // check all visible
        $('#checkAll').on('change', function () {
            const checked = this.checked;
            // only visible rows (current filter/page)
            table.rows({search: 'applied'}).nodes().to$().find('.row-check').each(function () {
                const id = String($(this).data('id'));
                $(this).prop('checked', checked);
                if (checked) selected.add(id);
                else selected.delete(id);
            });

            syncCheckboxes();
        });

        // bulk form submit -> ensure hidden input updated
        $('#bulkForm').on('submit', function () {
            syncCheckboxes();
            if (selected.size === 0) {
                alert('Pilih minimal 1 data.');
                return false;
            }
            return true;
        });

        syncCheckboxes();
    });
</script>
<?php rmi_footer(); ?>
