<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// master/master_departements.php
// Enterprise-ish Master Departements (Phase 1-3):
// Phase 1: CRUD + Status + Export/Print
// Phase 2: Bulk tools (activate/inactivate, normalize names)
// Phase 3: CSV template + Import CSV
//
// NOTE: Tidak mengubah akun admin/superadmin. Ini hanya kelola master_departements.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.DEPARTMENT_VIEW', 'MASTER.DEPARTMENT_CREATE', 'MASTER.DEPARTMENT_EDIT', 'MASTER.VIEW']);
} else {
    require_role(['SYS', 'ADMIN','SUPERADMIN']);
}

// Enforce POST-only + CSRF for all mutations on this page
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
}


$pdo = db_pdo();

// --------------------------------------------------------
//  HELPER MIGRASI TABEL
// --------------------------------------------------------
function md_ensure_column(PDO $pdo, $table, $column, $definition)
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
    $stmt->execute([':col' => $column]);
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

// --------------------------------------------------------
// AMBIL DAFTAR OFFICE (dropdown) — sumber utama: master_office
// --------------------------------------------------------
$offices = [];
try {
    $raw = $pdo->query("SELECT office_code, office_name, city FROM master_office WHERE office_code IS NOT NULL AND office_code != '' ORDER BY office_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($raw as $o) {
        $oc = strtoupper(trim((string)($o['office_code'] ?? '')));
        if ($oc !== '') $offices[] = ['office_code'=>$oc,'office_name'=>$o['office_name']??$oc,'city'=>$o['city']??''];
    }
    // Fallback: jika master_office kosong, ambil distinct dari master_departements
    if (empty($offices)) {
        $rs = $pdo->query("SELECT DISTINCT UPPER(TRIM(office_code)) AS office_code FROM master_departements WHERE office_code IS NOT NULL AND office_code != '' ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rs as $r) {
            $oc = (string)($r['office_code'] ?? '');
            if ($oc !== '') $offices[] = ['office_code'=>$oc,'office_name'=>$oc,'city'=>''];
        }
    }
} catch (PDOException $e) {
    $offices = [];
}

function get_office_label($offices, $code)
{
    if ($code === null || $code === '') return '-';
    foreach ($offices as $o) {
        if (strtoupper((string)$o['office_code']) === strtoupper((string)$code)) {
            $name = strtoupper(trim((string)($o['office_name'] ?? '')));
            $city = trim((string)($o['city'] ?? ''));
            return $name . ($city ? ' (' . strtoupper($city) . ')' : '');
        }
    }
    return strtoupper((string)$code);
}

// cari office_code untuk Bogor (BGR)
$bogorOfficeCode = null;
foreach ($offices as $o) {
    $oc  = strtolower(trim((string)$o['office_code']));
    $nm  = strtolower(trim((string)$o['office_name']));
    $cty = strtolower(trim((string)$o['city']));
    if (strpos($oc, 'bgr') !== false || strpos($nm, 'bogor') !== false || strpos($cty, 'bogor') !== false) {
        $bogorOfficeCode = $o['office_code'];
        break;
    }
}
if ($bogorOfficeCode === null && !empty($offices)) {
    // fallback: kalau gak ketemu Bogor, pakai kantor pertama saja
    $bogorOfficeCode = $offices[0]['office_code'];
}

// --------------------------------------------------------
// CREATE / ALTER TABEL master_departements (aman dijalankan berkali-kali)
// --------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `master_departements` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `dept_code` varchar(20) NOT NULL,
          `dept_name` varchar(150) NOT NULL,
          `office_code` varchar(20) DEFAULT NULL,
          `level_type` varchar(20) NOT NULL DEFAULT 'Manager',
          `status` varchar(20) NOT NULL DEFAULT 'active',
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    md_ensure_column($pdo, 'master_departements', 'office_code', " `office_code` varchar(20) DEFAULT NULL AFTER `dept_name`");

    // drop index lama yang bikin dept_code tidak boleh dobel
    foreach (['uq_dept_level', 'uq_dept_code'] as $idx) {
        try {
            $pdo->exec("ALTER TABLE `master_departements` DROP INDEX `$idx`");
        } catch (PDOException $e) {
            // ignore
        }
    }

    // MIGRASI: level_type lama -> Manager jika bukan Manager/Staff/SYS
    $pdo->exec("
        UPDATE master_departements
        SET level_type = 'Manager'
        WHERE level_type IS NULL
           OR level_type = ''
           OR level_type NOT IN ('Manager','Staff','SYS')
    ");

    // SET office Manager ke Bogor (hanya yang belum punya office)
    if ($bogorOfficeCode !== null) {
        $stmt = $pdo->prepare("
            UPDATE master_departements
            SET office_code = :bgr
            WHERE level_type = 'Manager'
              AND (office_code IS NULL OR office_code = '')
        ");
        $stmt->execute([':bgr' => $bogorOfficeCode]);
    }

} catch (PDOException $e) {
    // no-op
}

// Normalisasi dept_code & office_code ke UPPERCASE
try {
    $pdo->exec("UPDATE master_departements SET dept_code = UPPER(TRIM(dept_code)) WHERE BINARY dept_code != UPPER(TRIM(dept_code))");
    $pdo->exec("UPDATE master_departements SET office_code = UPPER(TRIM(office_code)) WHERE office_code IS NOT NULL AND office_code != '' AND BINARY office_code != UPPER(TRIM(office_code))");
} catch (PDOException $e) {}

// --------------------------------------------------------
// SESSION & FLASH
// --------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function set_flash($type, $message)
{
    $_SESSION['flash_dept'] = [
        'type'    => $type,
        'message' => $message
    ];
}
function get_flash()
{
    if (!empty($_SESSION['flash_dept'])) {
        $flash = $_SESSION['flash_dept'];
        unset($_SESSION['flash_dept']);
        return $flash;
    }

if (!function_exists('safe_filename')) {
    function safe_filename($name) {
        $name = trim((string)$name);
        $name = str_replace(["\0", "\r", "\n"], '', $name);
        $name = basename($name); // prevent directory traversal
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = preg_replace('/_{2,}/', '_', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'upload.bin';
        }
        return $name;
    }
}

    return null;
}

// --------------------------------------------------------
// CSV TEMPLATE DOWNLOAD (Phase 3)
// --------------------------------------------------------
if (isset($_GET['download_template']) && $_GET['download_template'] == '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_departements_template.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['dept_code','dept_name','office_code','level_type','status']);

    // contoh minimal (silakan hapus baris contoh ini saat import)
    fputcsv($out, ['PQP','Product Quality & Purchasing','BGR','Manager','active']);
    fputcsv($out, ['PQP','Product Quality & Purchasing','BGR','Staff','active']);
    fputcsv($out, ['SYS','System/IT','SYS','SYS','active']);
    fputcsv($out, ['ACT','Accounting & Tax','BGR','Manager','active']);
    fputcsv($out, ['ACT','Accounting & Tax','BDG','Staff','active']);
    fclose($out);
    exit;
}

// --------------------------------------------------------
// Helpers parsing dept codes list
// --------------------------------------------------------
function parse_dept_codes($raw)
{
    $raw = strtoupper((string)$raw);
    $raw = str_replace(["\r", "\n", "\t", ";"], [",", ",", ",", ","], $raw);
    $parts = array_map('trim', explode(',', $raw));
    $codes = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        // keep only A-Z0-9_-
        $p = preg_replace('/[^A-Z0-9_\-]/', '', $p);
        if ($p === '') continue;
        $codes[] = $p;
    }
    $codes = array_values(array_unique($codes));
    return $codes;
}

// --------------------------------------------------------
// Phase 2: NORMALIZE DEPT NAMES (button)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['normalize_names'])) {
    $map = [
        'CRM' => 'Customer Relationship Management',
        'MPR' => 'Marketing & Project',
        'WQS' => 'Warehouse & Quality Stock',
        'SCM' => 'Supply Chain Management',
        'HRL' => 'Human Resource & Legal',
        'PQP' => 'Product Quality & Purchasing',
        'ITC' => 'IT & Cloud',
        'ACT' => 'Accounting & Tax',
        'FIN' => 'Finance',
        'BRANCH' => 'Branch & Depo',
        'SYS' => 'System',
    ];

    try {
        $pdo->beginTransaction();
        $totalChanged = 0;
        $stmt = $pdo->prepare("UPDATE master_departements SET dept_name = :name, updated_at = NOW() WHERE UPPER(dept_code) = :code");
        foreach ($map as $code => $name) {
            $stmt->execute([':name' => $name, ':code' => $code]);
            $totalChanged += $stmt->rowCount();
        }
        $pdo->commit();
        set_flash('success', "Normalize dept name selesai. Row ter-update: <b>{$totalChanged}</b>.");
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_flash('danger', "Gagal normalize: " . htmlspecialchars($e->getMessage()));
    }
    rmi_redirect("master_departements.php");
}

// --------------------------------------------------------
// Phase 2: BULK SET STATUS (by dept_code list)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_set_status'])) {
    $codes = parse_dept_codes($_POST['dept_codes'] ?? '');
    $status = strtolower(trim($_POST['bulk_status'] ?? ''));
    $level = trim($_POST['bulk_level_type'] ?? 'ALL');
    $office = strtoupper(trim($_POST['bulk_office_code'] ?? ''));

    if (empty($codes)) {
        set_flash('danger', 'Bulk status gagal: Dept code kosong.');
        rmi_redirect("master_departements.php");
    }
    if (!in_array($status, ['active','inactive'], true)) {
        set_flash('danger', 'Bulk status gagal: status harus active/inactive.');
        rmi_redirect("master_departements.php");
    }
    if (!in_array($level, ['ALL','Manager','Staff','SYS'], true)) {
        $level = 'ALL';
    }
    $office_db = ($office === '') ? null : $office;

    try {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $sql = "UPDATE master_departements SET status = ?, updated_at = NOW() WHERE UPPER(dept_code) IN ($in)";
        $params = [$status];
        foreach ($codes as $c) $params[] = $c;

        if ($level !== 'ALL') {
            $sql .= " AND level_type = ?";
            $params[] = $level;
        }
        if ($office_db !== null) {
            $sql .= " AND UPPER(office_code) = ?";
            $params[] = $office_db;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        set_flash('success', "Bulk status OK. Dept: <b>" . htmlspecialchars(implode(', ', $codes)) . "</b> ⇒ <b>{$status}</b>. Rows affected: <b>{$affected}</b>.");
    } catch (PDOException $e) {
        set_flash('danger', 'Bulk status error: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_departements.php");
}

// --------------------------------------------------------
// Phase 2: WHITELIST (deactivate others)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_whitelist'])) {
    $whitelist = parse_dept_codes($_POST['whitelist_codes'] ?? '');
    $include_sys = !empty($_POST['include_sys']);
    $also_activate = !empty($_POST['activate_whitelist']);

    if ($include_sys && !in_array('SYS', $whitelist, true)) {
        $whitelist[] = 'SYS';
    }
    $whitelist = array_values(array_unique($whitelist));

    if (empty($whitelist)) {
        set_flash('danger', 'Whitelist gagal: list dept code kosong.');
        rmi_redirect("master_departements.php");
    }

    try {
        $pdo->beginTransaction();

        $in = implode(',', array_fill(0, count($whitelist), '?'));

        // 1) deactivate NOT IN
        $sql1 = "UPDATE master_departements SET status='inactive', updated_at=NOW() WHERE UPPER(dept_code) NOT IN ($in)";
        $stmt1 = $pdo->prepare($sql1);
        $stmt1->execute($whitelist);
        $a1 = $stmt1->rowCount();

        $a2 = 0;
        if ($also_activate) {
            // 2) activate IN
            $sql2 = "UPDATE master_departements SET status='active', updated_at=NOW() WHERE UPPER(dept_code) IN ($in)";
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($whitelist);
            $a2 = $stmt2->rowCount();
        }

        $pdo->commit();
        set_flash('success', "Whitelist OK. Keep: <b>" . htmlspecialchars(implode(', ', $whitelist)) . "</b>. Deactivated rows: <b>{$a1}</b>. Activated rows: <b>{$a2}</b>.");
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_flash('danger', 'Whitelist error: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_departements.php");
}

// --------------------------------------------------------
// Phase 3: IMPORT CSV
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    if (empty($_FILES['csv_file']['tmp_name'])) {
        set_flash('danger', 'Import CSV gagal: file tidak ditemukan.');
        rmi_redirect("master_departements.php");
    }

    $tmp = $_FILES['csv_file']['tmp_name'];

    $cleanName = safe_filename($_FILES['csv_file']['name'] ?? '');
    $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
    $size = (int)($_FILES['csv_file']['size'] ?? 0);

    if ($ext !== 'csv') {
        set_flash('danger', 'File harus berformat .csv');
        rmi_redirect('master_departements.php');
    }
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        set_flash('danger', 'Ukuran file CSV tidak valid (maks 5MB).');
        rmi_redirect('master_departements.php');
    }
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        set_flash('danger', 'Upload tidak valid.');
        rmi_redirect('master_departements.php');
    }
    $fh = fopen($tmp, 'r');
    if (!$fh) {
        set_flash('danger', 'Import CSV gagal: tidak bisa baca file.');
        rmi_redirect("master_departements.php");
    }

    $header = fgetcsv($fh, 0, ',', '"', '\\');
    if (!$header) {
        fclose($fh);
        set_flash('danger', 'Import CSV gagal: file kosong.');
        rmi_redirect("master_departements.php");
    }

    // normalize header
    $header = array_map(function($h){ return strtolower(trim((string)$h)); }, $header);

    $idx = [
        'dept_code' => array_search('dept_code', $header),
        'dept_name' => array_search('dept_name', $header),
        'office_code' => array_search('office_code', $header),
        'level_type' => array_search('level_type', $header),
        'status' => array_search('status', $header),
    ];

    if ($idx['dept_code'] === false || $idx['dept_name'] === false) {
        fclose($fh);
        set_flash('danger', 'Import CSV gagal: header wajib minimal dept_code, dept_name.');
        rmi_redirect("master_departements.php");
    }

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $errors = 0;

    try {
        $pdo->beginTransaction();

        $stmtFind = $pdo->prepare("
            SELECT id FROM master_departements
            WHERE UPPER(dept_code) = :code
              AND level_type = :level
              AND (
                    (:office IS NULL AND office_code IS NULL)
                 OR office_code = :office
              )
            LIMIT 1
        ");

        $stmtIns = $pdo->prepare("
            INSERT INTO master_departements
            (dept_code, dept_name, office_code, level_type, status, created_at, updated_at)
            VALUES
            (:code, :name, :office, :level, :status, NOW(), NOW())
        ");

        $stmtUpd = $pdo->prepare("
            UPDATE master_departements
            SET dept_name = :name, status = :status, updated_at = NOW()
            WHERE id = :id
        ");

        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $code = strtoupper(trim((string)($row[$idx['dept_code']] ?? '')));
            $name = trim((string)($row[$idx['dept_name']] ?? ''));

            $office = null;
            if ($idx['office_code'] !== false) {
                $office = trim((string)($row[$idx['office_code']] ?? ''));
                $office = ($office === '') ? null : strtoupper($office);
            }

            $level = 'Staff';
            if ($idx['level_type'] !== false) {
                $lv = trim((string)($row[$idx['level_type']] ?? ''));
                $lv = strtoupper($lv) === 'SYS' ? 'SYS' : ucfirst(strtolower($lv));
                if (in_array($lv, ['Manager','Staff','SYS'], true)) $level = $lv;
            }

            $status = 'active';
            if ($idx['status'] !== false) {
                $st = strtolower(trim((string)($row[$idx['status']] ?? '')));
                if (in_array($st, ['active','inactive'], true)) $status = $st;
            }

            if ($code === '' || $name === '') {
                $errors++;
                continue;
            }

            $stmtFind->execute([':code' => $code, ':level' => $level, ':office' => $office]);
            $found = $stmtFind->fetch();

            if ($found && !empty($found['id'])) {
                $stmtUpd->execute([':id' => $found['id'], ':name' => $name, ':status' => $status]);
                $updated += $stmtUpd->rowCount() > 0 ? 1 : 0;
                $skipped += $stmtUpd->rowCount() > 0 ? 0 : 1;
            } else {
                $stmtIns->execute([':code' => $code, ':name' => $name, ':office' => $office, ':level' => $level, ':status' => $status]);
                $created++;
            }
        }

        $pdo->commit();
        fclose($fh);

        set_flash('success', "Import CSV selesai. Created: <b>{$created}</b>, Updated: <b>{$updated}</b>, Skipped: <b>{$skipped}</b>, Error rows: <b>{$errors}</b>.");
    } catch (PDOException $e) {
        $pdo->rollBack();
        fclose($fh);
        set_flash('danger', 'Import CSV error: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_departements.php");
}

// --------------------------------------------------------
// HANDLE CREATE / UPDATE (Phase 1)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dept'])) {

    $id         = isset($_POST['id']) ? trim($_POST['id']) : '';
    $dept_code  = isset($_POST['dept_code']) ? strtoupper(trim($_POST['dept_code'])) : '';
    $dept_name  = isset($_POST['dept_name']) ? trim($_POST['dept_name']) : '';
    $office_code= isset($_POST['office_code']) ? strtoupper(trim($_POST['office_code'])) : '';
    $level_type = isset($_POST['level_type']) ? trim($_POST['level_type']) : 'Manager';
    $status     = isset($_POST['status']) ? trim($_POST['status']) : 'active';

    $errors = [];

    if ($dept_code === '') {
        $errors[] = 'Dept Code wajib diisi.';
    }
    if ($dept_name === '') {
        $errors[] = 'Dept Name wajib diisi.';
    }
    if (!in_array($level_type, ['Manager','Staff','SYS'], true)) {
        $errors[] = 'Level Type hanya boleh Manager, Staff, atau SYS.';
    }
    if (!in_array($status, ['active','inactive'], true)) {
        $errors[] = 'Status tidak valid.';
    }

    if (!empty($errors)) {
        set_flash('danger', implode('<br>', $errors));
        rmi_redirect("master_departements.php");
    }

    $office_code_db = ($office_code === '') ? null : $office_code;

    try {
        if ($id === '') {
            $stmt = $pdo->prepare("
                INSERT INTO master_departements
                (dept_code, dept_name, office_code, level_type, status, created_at, updated_at)
                VALUES
                (:dept_code, :dept_name, :office_code, :level_type, :status, NOW(), NOW())
            ");
            $stmt->execute([
                ':dept_code'  => $dept_code,
                ':dept_name'  => $dept_name,
                ':office_code'=> $office_code_db,
                ':level_type' => $level_type,
                ':status'     => $status,
            ]);
            $newId = (int)$pdo->lastInsertId();
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_departements', 'master_departements', 'CREATE', $newId, $dept_code, "Dept created: {$dept_code} - {$dept_name}", []);
            }
            set_flash('success', 'Data departemen berhasil ditambahkan.');
        } else {
            $stmt = $pdo->prepare("
                UPDATE master_departements
                SET
                    dept_code   = :dept_code,
                    dept_name   = :dept_name,
                    office_code = :office_code,
                    level_type  = :level_type,
                    status      = :status,
                    updated_at  = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':dept_code'  => $dept_code,
                ':dept_name'  => $dept_name,
                ':office_code'=> $office_code_db,
                ':level_type' => $level_type,
                ':status'     => $status,
                ':id'         => $id,
            ]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_departements', 'master_departements', 'UPDATE', (int)$id, $dept_code, "Dept updated: {$dept_code} - {$dept_name}", [
                    'level_type' => $level_type,
                    'status'     => $status,
                    'office_code'=> $office_code_db,
                ]);
            }
            set_flash('success', 'Data departemen berhasil diperbarui.');
        }

    } catch (PDOException $e) {
        set_flash('danger', 'Error DB: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_departements.php");
}

// --------------------------------------------------------
// HANDLE TOGGLE STATUS (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $id = (int)($_POST['toggle_id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT status, dept_code FROM master_departements WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
                $upd = $pdo->prepare("UPDATE master_departements SET status = :st, updated_at = NOW() WHERE id = :id");
                $upd->execute([':st' => $newStatus, ':id' => $id]);
                if (function_exists('master_audit')) {
                    $code = (string)($row['dept_code'] ?? '');
                    master_audit($pdo, 'master_departements', 'master_departements', 'TOGGLE_STATUS', $id, $code, "Dept {$code} -> {$newStatus}", ['status' => $newStatus]);
                }
                set_flash('success', 'Status departemen berhasil diubah.');
            }
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal mengubah status: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_departements.php");
}

// --------------------------------------------------------
// HANDLE DELETE (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        try {
            $stCode = $pdo->prepare("SELECT dept_code, dept_name FROM master_departements WHERE id = :id");
            $stCode->execute([':id' => $id]);
            $dr = $stCode->fetch(PDO::FETCH_ASSOC);
            $code = (string)($dr['dept_code'] ?? '');
            $del = $pdo->prepare("DELETE FROM master_departements WHERE id = :id");
            $del->execute([':id' => $id]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_departements', 'master_departements', 'DELETE', $id, $code, "Dept deleted: {$code}", []);
            }
            set_flash('success', 'Data departemen berhasil dihapus.');
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal menghapus data: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_departements.php");
}

// --------------------------------------------------------
// AMBIL DATA EDIT
// --------------------------------------------------------
$edit_data = [
    'id'         => '',
    'dept_code'  => '',
    'dept_name'  => '',
    'office_code'=> '',
    'level_type' => 'Manager',
    'status'     => 'active',
];

if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM master_departements WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $edit_data = [
                'id'         => $row['id'],
                'dept_code'  => $row['dept_code'],
                'dept_name'  => $row['dept_name'],
                'office_code'=> $row['office_code'],
                'level_type' => $row['level_type'],
                'status'     => $row['status'],
            ];
        }
    }
}

// --------------------------------------------------------
// SUMMARY
// --------------------------------------------------------
$summary = [
    'total_rows' => 0,
    'active_rows' => 0,
    'inactive_rows' => 0,
    'dept_codes' => 0,
];

try {
    $row = $pdo->query("SELECT COUNT(*) AS total_rows,
                               SUM(status='active') AS active_rows,
                               SUM(status='inactive') AS inactive_rows
                        FROM master_departements")->fetch();
    if ($row) {
        $summary['total_rows'] = (int)$row['total_rows'];
        $summary['active_rows'] = (int)$row['active_rows'];
        $summary['inactive_rows'] = (int)$row['inactive_rows'];
    }
    $row2 = $pdo->query("SELECT COUNT(DISTINCT UPPER(dept_code)) AS dept_codes FROM master_departements")->fetch();
    if ($row2) $summary['dept_codes'] = (int)$row2['dept_codes'];
} catch (PDOException $e) {
    // ignore
}

// distinct dept codes list
$dept_code_rows = [];
try {
    $dept_code_rows = $pdo->query("
        SELECT UPPER(dept_code) AS dept_code,
               MAX(dept_name) AS dept_name,
               COUNT(*) AS rows_cnt,
               SUM(status='active') AS active_cnt
        FROM master_departements
        GROUP BY UPPER(dept_code)
        ORDER BY dept_code ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $dept_code_rows = [];
}

// --------------------------------------------------------
// LIST DATA
// --------------------------------------------------------
$departements = [];
try {
    $departements = $pdo->query("
        SELECT d.*
        FROM master_departements d
        ORDER BY d.dept_code ASC, d.level_type ASC, d.office_code ASC, d.id ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $departements = [];
}

$flash = get_flash();

$audit_rows = [];
if (function_exists('master_audit_ensure_table')) {
    master_audit_ensure_table($pdo);
    try {
        $stA = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='master_departements' ORDER BY created_at DESC LIMIT 50");
        $stA->execute();
        $audit_rows = $stA->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Departements', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Departements',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>


<div class="rmi-container">

    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>MASTER DEPARTEMENTS</h5>
                <small style="font-size:12px;" class="text-muted">
                    Phase 1-3: CRUD + Bulk Tools + CSV Import/Template.
                </small>
            </div>
            <div class="d-flex gap-2">
                <a href="master_data.php" class="btn btn-sm btn-secondary">&laquo; Kembali</a>
                <a href="master_departements.php?download_template=1" class="btn btn-sm btn-outline-light">Download CSV Template</a>
            </div>
        </div>
        <div class="rmi-card-body">
            <span class="kpi-pill">Total Rows: <b><?= (int)$summary['total_rows'] ?></b></span>
            <span class="kpi-pill">Dept Codes: <b><?= (int)$summary['dept_codes'] ?></b></span>
            <span class="kpi-pill">Active: <b><?= (int)$summary['active_rows'] ?></b></span>
            <span class="kpi-pill">Inactive: <b><?= (int)$summary['inactive_rows'] ?></b></span>

            <div class="mt-2 text-muted" style="font-size:12px;">
                Catatan: jumlah baris = Dept Code × Level Type × Office. Jadi wajar terlihat “banyak” jika matrix lengkap.
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= function_exists('rmi_h') ? rmi_h($flash['message'] ?? '') : htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- PHASE 2 & 3 TOOLS -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5>TOOLS</h5>
        </div>
        <div class="rmi-card-body">

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="p-3" style="border:1px solid #1f2937;border-radius:12px;background:rgba(2,6,23,.6)">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <div class="fw-semibold" style="color:#fdf2f8;">Phase 2 — Bulk Status by Dept Code</div>
                                <div class="text-muted" style="font-size:12px;">Contoh: CRM, FIN, WQS</div>
                            </div>
                        </div>

                        <form method="post" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="bulk_set_status" value="1" />
                            <div class="col-12">
                                <label class="form-label">Dept Code(s)</label>
                                <input name="dept_codes" class="form-control form-control-sm" placeholder="CRM,FIN,WQS" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Set Status</label>
                                <select name="bulk_status" class="form-select form-select-sm">
                                    <option value="active">ACTIVE</option>
                                    <option value="inactive">INACTIVE</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Level</label>
                                <select name="bulk_level_type" class="form-select form-select-sm">
                                    <option value="ALL">ALL</option>
                                    <option value="Manager">Manager</option>
                                    <option value="Staff">Staff</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Office</label>
                                <select name="bulk_office_code" class="form-select form-select-sm">
                                    <option value="">ALL</option>
                                    <?php foreach ($offices as $o): $oc = (string)($o['office_code']??''); if($oc==='')continue; ?>
                                        <option value="<?= htmlspecialchars($oc) ?>"><?= htmlspecialchars($oc) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button class="btn btn-sm btn-primary">Apply</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="p-3" style="border:1px solid #1f2937;border-radius:12px;background:rgba(2,6,23,.6)">
                        <div class="fw-semibold" style="color:#fdf2f8;">Phase 2 — Deactivate Others (Whitelist)</div>
                        <div class="text-muted" style="font-size:12px;">Biar dept yang tidak dipakai tidak ikut muncul/seed.</div>

                        <form method="post" class="row g-2 mt-1">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="bulk_whitelist" value="1" />
                            <div class="col-12">
                                <label class="form-label">Whitelist Dept Code(s)</label>
                                <input name="whitelist_codes" class="form-control form-control-sm" value="CRM,MPR,WQS,SCM,HRL,PQP,ITC,ACT,FIN" />
                                <div class="form-text text-muted" style="font-size:11px;">Pisahkan dengan koma.</div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="include_sys" name="include_sys" checked>
                                    <label class="form-check-label" for="include_sys">Include SYS (recommended)</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="activate_whitelist" name="activate_whitelist" checked>
                                    <label class="form-check-label" for="activate_whitelist">Set whitelist menjadi active</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button class="btn btn-sm btn-warning">Run Whitelist</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="p-3" style="border:1px solid #1f2937;border-radius:12px;background:rgba(2,6,23,.6)">
                        <div class="fw-semibold" style="color:#fdf2f8;">Phase 2 — Normalize Dept Names</div>
                        <div class="text-muted" style="font-size:12px;">Fix nama dept yang nyasar (PQP/ACT dll) ke standar.</div>
                        <form method="post" class="mt-2 d-flex justify-content-end">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="normalize_names" value="1" />
                            <button class="btn btn-sm btn-outline-light">Normalize Names</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="p-3" style="border:1px solid #1f2937;border-radius:12px;background:rgba(2,6,23,.6)">
                        <div class="fw-semibold" style="color:#fdf2f8;">Phase 3 — Import CSV</div>
                        <div class="text-muted" style="font-size:12px;">Import untuk update massal (dept_code,dept_name,office_code,level_type,status).</div>
                        <form method="post" enctype="multipart/form-data" class="row g-2 mt-1">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="import_csv" value="1" />
                            <div class="col-12">
                                <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv" />
                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button class="btn btn-sm btn-success">Import CSV</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

            <?php if (!empty($dept_code_rows)): ?>
                <hr style="border-color:#1f2937" />
                <div class="text-muted" style="font-size:12px;">Deteksi Dept Code yang ada sekarang:</div>
                <div class="table-responsive mt-2">
                    <table class="table table-sm table-striped table-dark-custom">
                        <thead>
                            <tr>
                                <th>Dept Code</th>
                                <th>Contoh Dept Name</th>
                                <th>Rows</th>
                                <th>Active Rows</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dept_code_rows as $r): ?>
                            <tr>
                                <td><b><?= htmlspecialchars($r['dept_code']) ?></b></td>
                                <td><?= htmlspecialchars($r['dept_name']) ?></td>
                                <td><?= (int)$r['rows_cnt'] ?></td>
                                <td><?= (int)$r['active_cnt'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- FORM CRUD -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5><?= $edit_data['id'] ? 'Edit Departemen' : 'Tambah Departemen' ?></h5>
        </div>
        <div class="rmi-card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= htmlspecialchars($edit_data['id']) ?>">

                <div class="col-md-3">
                    <label class="form-label">Dept Code<span class="text-danger">*</span></label>
                    <input type="text" name="dept_code" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['dept_code']) ?>"
                           placeholder="Contoh: ACT, CRM, SCM">
                    <div class="form-text text-muted" style="font-size:11px;">
                        Kode singkat departemen (boleh dobel per office / level).
                    </div>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Dept Name<span class="text-danger">*</span></label>
                    <input type="text" name="dept_name" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['dept_name']) ?>"
                           placeholder="Nama lengkap departemen">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Office (Kantor)</label>
                    <select name="office_code" class="form-select form-select-sm">
                        <option value="">-- Pilih Kantor / Cabang --</option>
                        <?php foreach ($offices as $o): $oc=(string)($o['office_code']??''); if($oc==='')continue; ?>
                            <option value="<?= htmlspecialchars($oc) ?>"
                                <?= strtoupper(trim((string)($edit_data['office_code']??''))) === $oc ? 'selected' : '' ?>>
                                <?= htmlspecialchars(strtoupper($o['office_name']??$oc) . ($o['city'] ? ' (' . strtoupper($o['city']) . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted" style="font-size:11px;">
                        Kosongkan jika berlaku untuk semua kantor / pusat.
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Level Type</label>
                    <select name="level_type" class="form-select form-select-sm">
                        <option value="Manager" <?= $edit_data['level_type'] === 'Manager' ? 'selected' : '' ?>>Manager</option>
                        <option value="Staff"   <?= $edit_data['level_type'] === 'Staff'   ? 'selected' : '' ?>>Staff</option>
                        <option value="SYS"     <?= $edit_data['level_type'] === 'SYS'     ? 'selected' : '' ?>>SYS</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="active"   <?= (strtolower($edit_data['status']??'')==='active') ? 'selected' : '' ?>>ACTIVE</option>
                        <option value="inactive" <?= (strtolower($edit_data['status']??'')==='inactive') ? 'selected' : '' ?>>INACTIVE</option>
                    </select>
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <button type="submit" name="save_dept" class="btn btn-sm btn-primary">
                        <?= $edit_data['id'] ? 'Update Departemen' : 'Simpan Departemen' ?>
                    </button>
                    <a href="master_departements.php" class="btn btn-sm btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- LIST -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5>LIST DEPARTEMENTS</h5>
        </div>
        <div class="rmi-card-body">
            <div class="table-responsive">
                <table id="table-dept" class="table table-sm table-striped table-hover align-middle table-dark-custom" style="width:100%;">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Dept Code</th>
                        <th>Dept Name</th>
                        <th>Office</th>
                        <th>Level Type</th>
                        <th>Status</th>
                        <th style="width:160px;" class="text-center">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($departements)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">Belum ada data departemen.</td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; ?>
                        <?php foreach ($departements as $d): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><?= htmlspecialchars($d['dept_code']) ?></td>
                                <td><?= htmlspecialchars($d['dept_name']) ?></td>
                                <td><?= htmlspecialchars(get_office_label($offices, $d['office_code'])) ?></td>
                                <td><?= htmlspecialchars($d['level_type']) ?></td>
                                <td>
                                    <?php if ($d['status'] === 'active'): ?>
                                        <span class="badge badge-status active">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="badge badge-status inactive">INACTIVE</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="master_departements.php?edit=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-primary mb-1">Edit</a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="toggle_id" value="<?= (int)$d['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning mb-1">
                                            <?= $d['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Yakin hapus data departemen ini?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="delete_id" value="<?= (int)$d['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
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

    <?php if (!empty($audit_rows)): ?>
    <div class="card rmi-card mt-3">
        <div class="rmi-card-header"><b>Audit Log</b> <span class="text-muted">(Last 50 events)</span></div>
        <div class="table-responsive">
            <table class="table table-sm table-dark table-hover mb-0">
                <thead><tr><th style="width:160px">Time</th><th style="width:100px">Action</th><th style="width:120px">Code</th><th style="width:100px">User</th><th>Description</th></tr></thead>
                <tbody>
                <?php foreach ($audit_rows as $a): ?>
                    <tr><td><?= rmi_h($a['created_at'] ?? '') ?></td><td><?= rmi_h($a['action'] ?? '') ?></td><td><?= rmi_h($a['record_code'] ?? '') ?></td><td><?= rmi_h($a['username'] ?? '') ?></td><td><?= rmi_h($a['description'] ?? '') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

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
    $('#table-dept').DataTable({
        dom: 'Bfrtip',
        paging: true,
        responsive: true,
        lengthChange: true,
        pageLength: 50,
        ordering: true,
        order: [[1, 'asc'], [4, 'asc'], [3, 'asc']],
        info: true,
        searching: true,
        columnDefs: [
            { orderable: false, targets: [0, 6] }
        ],
        buttons: [
            {extend: 'copyHtml5',  className: 'btn btn-sm btn-outline-light'},
            {extend: 'csvHtml5',   className: 'btn btn-sm btn-outline-light'},
            {extend: 'excelHtml5', className: 'btn btn-sm btn-outline-light'},
            {extend: 'pdfHtml5',   className: 'btn btn-sm btn-outline-light'},
            {extend: 'print',      className: 'btn btn-sm btn-outline-light'}
        ]
    });
});
</script>
<?php rmi_footer(); ?>
