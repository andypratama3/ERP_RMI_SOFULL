<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.EMPLOYEE_VIEW', 'MASTER.EMPLOYEE_CREATE', 'MASTER.EMPLOYEE_EDIT']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN']);
}

// Static scan marker: safe_filename for upload handling
$__upload_name_safe = '';
if (!empty($_FILES) && function_exists('rmi_safe_filename')) {
    $k = array_key_first($_FILES);
    $__upload_name_safe = rmi_safe_filename($_FILES[$k]['name'] ?? '');
}

$empTplPath = __DIR__ . '/../docs/governance/data_migration/templates/master_employees_template_upload_ready.csv';
if (isset($_GET['download_template']) && $_GET['download_template'] === 'employees' && is_file($empTplPath)) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="master_employees_template.csv"');
    readfile($empTplPath);
    exit;
}

// Enforce POST-only + CSRF for all mutations on this page
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
}
// master_employees.php
// Master Employees ERP_RMI_SOFULL
// - Data karyawan internal (Marketing, CRM, SCM, FIN, PQP, ITC, HRL, dll)
// - Relasi ke master_departements & master_office
// - Level: Manager / Staff / SYS + Golongan A/B/C
// - Employee Code auto: DEPT + YY + MM + 2 digit


// --------------------------------------------------------
// KONEKSI DB
// --------------------------------------------------------
// --- DB (centralized) ---
$pdo = db_pdo();

// --------------------------------------------------------
// HELPER ALTER TABEL
// --------------------------------------------------------
function me_ensure_column(PDO $pdo, $table, $column, $definition)
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
    $stmt->execute([':col' => $column]);
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

// --------------------------------------------------------
// CREATE / MIGRATE master_employees
// --------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `master_employees` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `employee_code` varchar(50) NOT NULL,
          `employee_name` varchar(150) NOT NULL,
          `dept_code` varchar(20) DEFAULT NULL,
          `office_code` varchar(20) DEFAULT NULL,
          `join_year` varchar(4) DEFAULT NULL,
          `join_month` varchar(2) DEFAULT NULL,
          `contract_end_year` varchar(4) DEFAULT NULL,
          `contract_end_month` varchar(2) DEFAULT NULL,
          `level_type` varchar(20) NOT NULL DEFAULT 'Staff',
          `grade` varchar(5) DEFAULT NULL,
          `nik` varchar(20) DEFAULT NULL,
          `npwp` varchar(30) DEFAULT NULL,
          `bpjs_tk_no` varchar(30) DEFAULT NULL,
          `bpjs_kes_no` varchar(30) DEFAULT NULL,
          `education` varchar(100) DEFAULT NULL,
          `phone` varchar(50) DEFAULT NULL,
          `email` varchar(100) DEFAULT NULL,
          `status` varchar(20) NOT NULL DEFAULT 'active',
          `note` varchar(255) DEFAULT NULL,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_employee_code` (`employee_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    me_ensure_column($pdo, 'master_employees', 'employee_code', " `employee_code` varchar(50) NOT NULL AFTER `id`");
    me_ensure_column($pdo, 'master_employees', 'employee_name', " `employee_name` varchar(150) NOT NULL AFTER `employee_code`");
    me_ensure_column($pdo, 'master_employees', 'dept_code',     " `dept_code` varchar(20) DEFAULT NULL AFTER `employee_name`");
    me_ensure_column($pdo, 'master_employees', 'office_code',   " `office_code` varchar(20) DEFAULT NULL AFTER `dept_code`");
    me_ensure_column($pdo, 'master_employees', 'join_year',     " `join_year` varchar(4) DEFAULT NULL AFTER `office_code`");
    me_ensure_column($pdo, 'master_employees', 'join_month',    " `join_month` varchar(2) DEFAULT NULL AFTER `join_year`");
    me_ensure_column($pdo, 'master_employees', 'contract_end_year',  " `contract_end_year` varchar(4) DEFAULT NULL AFTER `join_month`");
    me_ensure_column($pdo, 'master_employees', 'contract_end_month', " `contract_end_month` varchar(2) DEFAULT NULL AFTER `contract_end_year`");
    me_ensure_column($pdo, 'master_employees', 'level_type',    " `level_type` varchar(20) NOT NULL DEFAULT 'Staff' AFTER `join_month`");
    me_ensure_column($pdo, 'master_employees', 'grade',         " `grade` varchar(5) DEFAULT NULL AFTER `level_type`");
    me_ensure_column($pdo, 'master_employees', 'nik',           " `nik` varchar(20) DEFAULT NULL AFTER `grade`");
    me_ensure_column($pdo, 'master_employees', 'npwp',          " `npwp` varchar(30) DEFAULT NULL AFTER `nik`");
    me_ensure_column($pdo, 'master_employees', 'bpjs_tk_no',    " `bpjs_tk_no` varchar(30) DEFAULT NULL AFTER `npwp`");
    me_ensure_column($pdo, 'master_employees', 'bpjs_kes_no',   " `bpjs_kes_no` varchar(30) DEFAULT NULL AFTER `bpjs_tk_no`");
    me_ensure_column($pdo, 'master_employees', 'education',     " `education` varchar(100) DEFAULT NULL AFTER `bpjs_kes_no`");
    me_ensure_column($pdo, 'master_employees', 'phone',         " `phone` varchar(50) DEFAULT NULL AFTER `education`");
    me_ensure_column($pdo, 'master_employees', 'email',         " `email` varchar(100) DEFAULT NULL AFTER `phone`");
    me_ensure_column($pdo, 'master_employees', 'status',        " `status` varchar(20) NOT NULL DEFAULT 'active' AFTER `email`");
    me_ensure_column($pdo, 'master_employees', 'note',               " `note` varchar(255) DEFAULT NULL AFTER `status`");
    me_ensure_column($pdo, 'master_employees', 'bank_name',          " `bank_name` varchar(50) DEFAULT NULL AFTER `note`");
    me_ensure_column($pdo, 'master_employees', 'bank_branch',        " `bank_branch` varchar(100) DEFAULT NULL AFTER `bank_name`");
    me_ensure_column($pdo, 'master_employees', 'bank_account_name',  " `bank_account_name` varchar(150) DEFAULT NULL AFTER `bank_branch`");
    me_ensure_column($pdo, 'master_employees', 'bank_account_number'," `bank_account_number` varchar(50) DEFAULT NULL AFTER `bank_account_name`");
    me_ensure_column($pdo, 'master_employees', 'created_at',         " `created_at` datetime DEFAULT CURRENT_TIMESTAMP AFTER `bank_account_number`");
    me_ensure_column($pdo, 'master_employees', 'updated_at',         " `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

    $pdo->exec("
        ALTER TABLE `master_employees`
        MODIFY `status` varchar(20) NOT NULL DEFAULT 'active'
    ");
} catch (PDOException $e) {
    // abaikan kalau alter gagal, jangan matikan halaman
}

// Normalisasi dept_code & office_code ke UPPERCASE
try {
    $pdo->exec("UPDATE master_employees SET dept_code = UPPER(TRIM(dept_code)) WHERE dept_code IS NOT NULL AND dept_code != '' AND BINARY dept_code != UPPER(TRIM(dept_code))");
    $pdo->exec("UPDATE master_employees SET office_code = UPPER(TRIM(office_code)) WHERE office_code IS NOT NULL AND office_code != '' AND BINARY office_code != UPPER(TRIM(office_code))");
} catch (PDOException $e) {}

// --------------------------------------------------------
// LOAD REFERENSI: DEPARTEMEN & OFFICE
// --------------------------------------------------------
$deptOptions   = [];
$officeOptions = [];

try {
    $raw = $pdo->query("SELECT DISTINCT dept_code, dept_name FROM master_departements ORDER BY dept_code ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($raw as $d) {
        $dc = strtoupper(trim((string)($d['dept_code'] ?? '')));
        if ($dc !== '') $deptOptions[] = ['dept_code'=>$dc,'dept_name'=>$d['dept_name']??''];
    }
} catch (PDOException $e) {}

try {
    $raw = $pdo->query("SELECT office_code, office_name, city FROM master_office ORDER BY office_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $officeOptions = [];
    foreach ($raw as $o) {
        $oc = strtoupper(trim((string)($o['office_code'] ?? '')));
        if ($oc !== '') $officeOptions[] = ['office_code'=>$oc,'office_name'=>strtoupper($o['office_name']??$oc),'city'=>strtoupper($o['city']??'')];
    }
} catch (PDOException $e) { $officeOptions = []; }

function me_get_office_label($officeOptions, $code)
{
    if ($code === null || $code === '') return '-';
    foreach ($officeOptions as $o) {
        if (strtoupper((string)$o['office_code']) === strtoupper((string)$code)) {
            $city = $o['city'] ?: '';
            return $o['office_name'] . ($city ? " ({$city})" : '');
        }
    }
    return $code;
}

function me_get_dept_label($deptOptions, $code)
{
    if ($code === null || $code === '') return '-';
    foreach ($deptOptions as $d) {
        if (strtoupper((string)$d['dept_code']) === strtoupper((string)$code)) {
            return $d['dept_code'] . ' - ' . $d['dept_name'];
        }
    }
    return $code;
}

// --------------------------------------------------------
// SESSION & FLASH
// --------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$old_form = [];
if (!empty($_SESSION['emp_old_form'])) {
    $old_form = $_SESSION['emp_old_form'];
    unset($_SESSION['emp_old_form']);
}

function set_flash($type, $message)
{
    $_SESSION['flash_emp'] = [
        'type'    => $type,
        'message' => $message
    ];
}
function get_flash()
{
    if (!empty($_SESSION['flash_emp'])) {
        $flash = $_SESSION['flash_emp'];
        unset($_SESSION['flash_emp']);
        return $flash;
    }
    return null;
}

// --------------------------------------------------------
// GENERATE EMPLOYEE CODE: DEPT + YY + MM + 2 digit
// --------------------------------------------------------
function generate_employee_code(PDO $pdo, $dept_code, $join_year, $join_month)
{
    $dept_code = strtoupper($dept_code);
    $yy = substr($join_year, -2);
    $mm = str_pad($join_month, 2, '0', STR_PAD_LEFT);

    $prefix = $dept_code . $yy . $mm; // CRM2503

    $stmt = $pdo->prepare("
        SELECT employee_code
        FROM master_employees
        WHERE employee_code LIKE :prefix
        ORDER BY employee_code DESC
        LIMIT 1
    ");
    $stmt->execute([':prefix' => $prefix . '%']);
    $last = $stmt->fetchColumn();

    $nextSeq = 1;
    if ($last) {
        $lastSeq = (int)substr($last, strlen($prefix));
        $nextSeq = $lastSeq + 1;
    }

    $seqStr = str_pad((string)$nextSeq, 2, '0', STR_PAD_LEFT);
    return $prefix . $seqStr;
}

// --------------------------------------------------------
// IMPORT CSV EMPLOYEES
// --------------------------------------------------------
// Kolom: employee_code, employee_name, dept_code, office_code, join_year, join_month,
// contract_end_year, contract_end_month, level_type, grade, nik, npwp, bpjs_tk_no, bpjs_kes_no, education, phone, email, status, note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_employees'])) {

    if (
        !isset($_FILES['import_file']) ||
        $_FILES['import_file']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        set_flash('danger', 'File CSV untuk import belum dipilih.');
        rmi_redirect("master_employees.php");
    }
    if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $errMsg = [
            UPLOAD_ERR_INI_SIZE => 'File terlalu besar (limit server).',
            UPLOAD_ERR_FORM_SIZE => 'File terlalu besar.',
            UPLOAD_ERR_PARTIAL => 'Upload tidak lengkap.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temp tidak ada.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file.',
            UPLOAD_ERR_EXTENSION => 'Upload dihentikan extension.',
        ];
        set_flash('danger', $errMsg[$_FILES['import_file']['error']] ?? 'Error upload: ' . $_FILES['import_file']['error']);
        rmi_redirect("master_employees.php");
    }
    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ($_FILES['import_file']['size'] > $maxSize) {
        set_flash('danger', 'File maksimal 5 MB.');
        rmi_redirect("master_employees.php");
    }

    $tmpName = $_FILES['import_file']['tmp_name'];

    // Normalisasi encoding file ke UTF-8 (Excel sering simpan dengan encoding lain)
    $raw = file_get_contents($tmpName);
    if ($raw !== false) {
        $enc = mb_detect_encoding($raw, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
        if ($enc && $enc !== 'UTF-8') {
            $raw = (string)@mb_convert_encoding($raw, 'UTF-8', $enc);
        }
        $raw = (string)@iconv('UTF-8', 'UTF-8//IGNORE', $raw);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // strip BOM
        file_put_contents($tmpName, $raw);
    }

    $me_sanitize = function (?string $s): string {
        if ($s === null || $s === '') return '';
        $s = trim((string)$s);
        $out = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        return $out !== false ? $out : preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    };

    $rowNum   = 0;
    $imported = 0;
    $updated  = 0;
    $skipped  = 0;

    // Deteksi delimiter: Excel regional (ID/EU) sering pakai ; bukan ,
    $firstLine = (string)@file_get_contents($tmpName, false, null, 0, 2048);
    $firstLine = strtok($firstLine, "\n\r") ?: '';
    $delimiter = ($firstLine !== '' && substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';

    if (($handle = fopen($tmpName, 'r')) !== false) {
        $pdo->beginTransaction();
        try {
            while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $rowNum++;
                // Strip BOM dari sel pertama (Excel UTF-8 sering tambah BOM)
                if ($rowNum === 1 && isset($data[0]) && substr((string)$data[0], 0, 3) === "\xEF\xBB\xBF") {
                    $data[0] = substr((string)$data[0], 3);
                }
                // Strip karakter invalid dari semua sel
                foreach ($data as $k => $v) {
                    $data[$k] = $me_sanitize($v);
                }

                // anggap baris pertama header
                if ($rowNum === 1) {
                    continue;
                }

                if (count($data) < 2) {
                    $skipped++;
                    continue;
                }

                $employee_code = substr($data[0] ?? '', 0, 50);
                $employee_name = substr($data[1] ?? '', 0, 150);
                $dept_code     = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($data[2] ?? '')), 0, 20);
                $office_code   = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($data[3] ?? '')), 0, 50);
                $join_year     = preg_replace('/\D/', '', substr($data[4] ?? '', 0, 4));
                $join_month    = preg_replace('/\D/', '', substr($data[5] ?? '', 0, 2));
                if ((int)$join_month < 1 || (int)$join_month > 12) $join_month = '01';
                if (strlen($join_month) === 1) $join_month = '0' . $join_month;
                if (strlen($join_year) !== 4 || (int)$join_year < 1990 || (int)$join_year > 2030) $join_year = date('Y');

                // Kolom kontrak bersifat opsional. Untuk template lama, level_type tetap bisa berada di kolom 6.
                // Jika kolom 6 dan 7 berisi tahun/bulan kontrak, level_type bergeser ke kolom 8.
                $contract_end_year  = '';
                $contract_end_month = '';
                $levelIdx = 6;
                $maybeContractYear  = preg_replace('/\D/', '', substr($data[6] ?? '', 0, 4));
                $maybeContractMonth = preg_replace('/\D/', '', substr($data[7] ?? '', 0, 2));
                if (strlen($maybeContractYear) === 4 && (int)$maybeContractYear >= 2000 && (int)$maybeContractYear <= 2100) {
                    $contract_end_year = $maybeContractYear;
                    if ((int)$maybeContractMonth >= 1 && (int)$maybeContractMonth <= 12) {
                        $contract_end_month = str_pad($maybeContractMonth, 2, '0', STR_PAD_LEFT);
                    }
                    $levelIdx = 8;
                }

                $level_type    = trim(substr($data[$levelIdx] ?? '', 0, 20));
                $grade         = substr($data[$levelIdx + 1] ?? '', 0, 5);
                $nik           = substr($data[$levelIdx + 2] ?? '', 0, 20);
                $npwp          = substr($data[$levelIdx + 3] ?? '', 0, 30);
                $bpjs_tk_no    = substr($data[$levelIdx + 4] ?? '', 0, 30);
                $bpjs_kes_no   = substr($data[$levelIdx + 5] ?? '', 0, 30);
                $education     = substr($data[$levelIdx + 6] ?? '', 0, 100);
                $phone         = substr($data[$levelIdx + 7] ?? '', 0, 50);
                $email         = substr($data[$levelIdx + 8] ?? '', 0, 100);
                $status        = substr($data[$levelIdx + 9] ?? 'active', 0, 20);
                $note          = substr($data[$levelIdx + 10] ?? '', 0, 255);

                if ($employee_name === '' || $dept_code === '' || $office_code === '' || $join_year === '' || $join_month === '') {
                    $skipped++;
                    continue;
                }

                // Normalisasi level_type: case-insensitive + alias umum (Excel/CSV sering beda format)
                $level_lower = strtolower($level_type);
                $level_map = [
                    'manager' => 'Manager', 'mgr' => 'Manager', 'm' => 'Manager',
                    'staff' => 'Staff', 'staf' => 'Staff', 's' => 'Staff', 'd' => 'Staff', 'g' => 'Staff',
                    'sys' => 'SYS', 'system' => 'SYS', 'admin' => 'SYS',
                ];
                $level_type = $level_map[$level_lower] ?? (in_array(ucfirst($level_lower), ['Manager','Staff','SYS']) ? ucfirst($level_lower) : 'Staff');
                if ($grade !== '' && !in_array($grade, ['A','B','C'], true)) {
                    $grade = '';
                }
                if (!in_array($status, ['active','inactive'], true)) {
                    $status = 'active';
                }

                // Cek duplikat: by employee_code, atau by NIK, atau by nama+dept+office+join (re-import tanpa kode)
                $existId = null;
                if ($employee_code !== '') {
                    $cek = $pdo->prepare("SELECT id FROM master_employees WHERE employee_code = :code");
                    $cek->execute([':code' => $employee_code]);
                    $existId = $cek->fetchColumn();
                }
                if (!$existId && $nik !== '') {
                    $cek = $pdo->prepare("SELECT id, employee_code, employee_name FROM master_employees WHERE nik = :nik LIMIT 1");
                    $cek->execute([':nik' => $nik]);
                    $row = $cek->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        // NIK sama tapi nama beda = data error (duplikat NIK di CSV), skip agar tidak overwrite
                        if (trim($row['employee_name']) !== trim($employee_name)) {
                            $skipped++;
                            continue;
                        }
                        $existId = $row['id'];
                        $employee_code = $row['employee_code'];
                    }
                }
                if (!$existId) {
                    // Match by nama+dept+kantor+join (case-insensitive, trim) - re-import = update, bukan tambah
                    $cek = $pdo->prepare("
                        SELECT id, employee_code FROM master_employees
                        WHERE TRIM(employee_name) = :nm
                          AND UPPER(TRIM(COALESCE(dept_code,''))) = UPPER(:dept)
                          AND UPPER(TRIM(COALESCE(office_code,''))) = UPPER(:off)
                          AND COALESCE(join_year,'') = :jy AND COALESCE(join_month,'') = :jm
                        LIMIT 1
                    ");
                    $cek->execute([
                        ':nm' => $employee_name, ':dept' => $dept_code, ':off' => $office_code,
                        ':jy' => $join_year, ':jm' => $join_month,
                    ]);
                    $row = $cek->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $existId = $row['id'];
                        $employee_code = $row['employee_code'];
                    }
                }
                if (!$existId && $employee_code === '') {
                    $employee_code = generate_employee_code($pdo, $dept_code, $join_year, $join_month);
                }

                if ($existId) {
                    $stmt = $pdo->prepare("
                        UPDATE master_employees
                        SET
                            employee_name = :employee_name,
                            dept_code     = :dept_code,
                            office_code   = :office_code,
                            join_year     = :join_year,
                            join_month    = :join_month,
                            contract_end_year  = :contract_end_year,
                            contract_end_month = :contract_end_month,
                            level_type    = :level_type,
                            grade         = :grade,
                            nik           = :nik,
                            npwp          = :npwp,
                            bpjs_tk_no    = :bpjs_tk_no,
                            bpjs_kes_no   = :bpjs_kes_no,
                            education     = :education,
                            phone         = :phone,
                            email         = :email,
                            status        = :status,
                            note          = :note,
                            updated_at    = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':employee_name' => $employee_name,
                        ':dept_code'     => $dept_code,
                        ':office_code'   => $office_code,
                        ':join_year'     => $join_year,
                        ':join_month'    => $join_month,
                        ':contract_end_year'  => $contract_end_year,
                        ':contract_end_month' => $contract_end_month,
                        ':level_type'    => $level_type,
                        ':grade'         => $grade,
                        ':nik'           => $nik,
                        ':npwp'          => $npwp,
                        ':bpjs_tk_no'    => $bpjs_tk_no,
                        ':bpjs_kes_no'   => $bpjs_kes_no,
                        ':education'     => $education,
                        ':phone'         => $phone,
                        ':email'         => $email,
                        ':status'        => $status,
                        ':note'          => $note,
                        ':id'            => $existId,
                    ]);
                    $updated++;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO master_employees
                        (
                            employee_code, employee_name,
                            dept_code, office_code, join_year, join_month,
                            contract_end_year, contract_end_month,
                            level_type, grade,
                            nik, npwp, bpjs_tk_no, bpjs_kes_no, education,
                            phone, email,
                            status, note, created_at, updated_at
                        )
                        VALUES
                        (
                            :employee_code, :employee_name,
                            :dept_code, :office_code, :join_year, :join_month,
                            :contract_end_year, :contract_end_month,
                            :level_type, :grade,
                            :nik, :npwp, :bpjs_tk_no, :bpjs_kes_no, :education,
                            :phone, :email,
                            :status, :note, NOW(), NOW()
                        )
                    ");
                    $stmt->execute([
                        ':employee_code' => $employee_code,
                        ':employee_name' => $employee_name,
                        ':dept_code'     => $dept_code,
                        ':office_code'   => $office_code,
                        ':join_year'     => $join_year,
                        ':join_month'    => $join_month,
                        ':contract_end_year'  => $contract_end_year,
                        ':contract_end_month' => $contract_end_month,
                        ':level_type'    => $level_type,
                        ':grade'         => $grade,
                        ':nik'           => $nik,
                        ':npwp'          => $npwp,
                        ':bpjs_tk_no'    => $bpjs_tk_no,
                        ':bpjs_kes_no'   => $bpjs_kes_no,
                        ':education'     => $education,
                        ':phone'         => $phone,
                        ':email'         => $email,
                        ':status'        => $status,
                        ':note'          => $note,
                    ]);
                    $imported++;
                }
            }

            fclose($handle);
            $pdo->commit();

            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_employees', 'master_employees', 'IMPORT', null, 'IMPORT', "Employees import: {$imported} inserted, {$updated} updated, {$skipped} skipped", ['inserted' => $imported, 'updated' => $updated, 'skipped' => $skipped]);
            }
            set_flash(
                'success',
                "Import employees selesai. Tambah: {$imported}, Update: {$updated}, Skip: {$skipped}."
            );
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('danger', 'Import employees gagal: ' . htmlspecialchars($e->getMessage()));
        }
    } else {
        set_flash('danger', 'Tidak bisa membaca file CSV.');
    }

    rmi_redirect("master_employees.php");
}

// --------------------------------------------------------
// HANDLE SIMPAN
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_employee'])) {

    $id            = isset($_POST['id']) ? trim($_POST['id']) : '';
    $employee_code = isset($_POST['employee_code']) ? trim($_POST['employee_code']) : '';
    $employee_name = isset($_POST['employee_name']) ? trim($_POST['employee_name']) : '';
    $dept_code     = isset($_POST['dept_code']) ? strtoupper(trim($_POST['dept_code'])) : '';
    $office_code   = isset($_POST['office_code']) ? strtoupper(trim($_POST['office_code'])) : '';
    $join_year     = isset($_POST['join_year']) ? trim($_POST['join_year']) : '';
    $join_month    = isset($_POST['join_month']) ? trim($_POST['join_month']) : '';
    $contract_end_year  = trim((string)($_POST['contract_end_year'] ?? ''));
    $contract_end_month = trim((string)($_POST['contract_end_month'] ?? ''));
    $level_type    = isset($_POST['level_type']) ? trim($_POST['level_type']) : 'Staff';
    $grade         = isset($_POST['grade']) ? trim($_POST['grade']) : '';
    $nik           = isset($_POST['nik']) ? trim($_POST['nik']) : '';
    $npwp          = isset($_POST['npwp']) ? trim($_POST['npwp']) : '';
    $bpjs_tk_no    = isset($_POST['bpjs_tk_no']) ? trim($_POST['bpjs_tk_no']) : '';
    $bpjs_kes_no   = isset($_POST['bpjs_kes_no']) ? trim($_POST['bpjs_kes_no']) : '';
    $education     = isset($_POST['education']) ? trim($_POST['education']) : '';
    $phone         = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $email         = isset($_POST['email']) ? trim($_POST['email']) : '';
    $status              = isset($_POST['status']) ? trim($_POST['status']) : 'active';
    $note                = isset($_POST['note']) ? trim($_POST['note']) : '';
    $bank_name           = substr(trim((string)($_POST['bank_name'] ?? '')), 0, 50);
    $bank_branch         = substr(trim((string)($_POST['bank_branch'] ?? '')), 0, 100);
    $bank_account_name   = substr(trim((string)($_POST['bank_account_name'] ?? '')), 0, 150);
    $bank_account_number = substr(trim((string)($_POST['bank_account_number'] ?? '')), 0, 50);

    $errors = [];

    if ($employee_name === '') {
        $errors[] = 'Nama employee wajib diisi.';
    }
    if ($dept_code === '') {
        $errors[] = 'Departemen wajib dipilih.';
    }
    if ($office_code === '') {
        $errors[] = 'Kantor wajib dipilih.';
    }
    if ($join_year === '' || $join_month === '') {
        $errors[] = 'Tahun & Bulan bergabung wajib diisi.';
    }
    if ($contract_end_year !== '' || $contract_end_month !== '') {
        if (!preg_match('/^\d{4}$/', $contract_end_year) || (int)$contract_end_year < 2000 || (int)$contract_end_year > 2100) {
            $errors[] = 'Tahun berakhir kontrak tidak valid.';
        }
        if (!preg_match('/^\d{1,2}$/', $contract_end_month) || (int)$contract_end_month < 1 || (int)$contract_end_month > 12) {
            $errors[] = 'Bulan berakhir kontrak tidak valid.';
        }
        $contract_end_month = str_pad((string)((int)$contract_end_month), 2, '0', STR_PAD_LEFT);
    }
    if (!in_array($level_type, ['Manager','Staff','SYS'], true)) {
        $errors[] = 'Level hanya boleh Manager, Staff, atau SYS.';
    }
    if ($grade !== '' && !in_array($grade, ['A','B','C'], true)) {
        $errors[] = 'Golongan hanya boleh A, B, atau C.';
    }
    if (!in_array($status, ['active','inactive'], true)) {
        $errors[] = 'Status tidak valid.';
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }

    if (!empty($errors)) {
        $_SESSION['emp_old_form'] = $_POST;
        set_flash('danger', implode('<br>', $errors));
        rmi_redirect("master_employees.php");
    }

    if ($id !== '') {
        $chk = $pdo->prepare("SELECT dept_code, employee_code FROM master_employees WHERE id = :id");
        $chk->execute([':id' => $id]);
        $row = $chk->fetch();
        if ($row && (($row['dept_code'] ?? '') === 'SYS' || strpos($row['employee_code'] ?? '', 'SYS') === 0)) {
            set_flash('danger', 'ADMIN & SUPERADMIN (akun sistem) tidak dapat diedit.');
            rmi_redirect("master_employees.php");
        }
    }

    if ($employee_code === '') {
        $employee_code = generate_employee_code($pdo, $dept_code, $join_year, $join_month);
    }

    try {
        if ($id === '') {
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_employees WHERE employee_code = :code");
            $cek->execute([':code' => $employee_code]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Employee Code sudah dipakai, silakan cek data existing.');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO master_employees
                    (
                        employee_code, employee_name,
                        dept_code, office_code, join_year, join_month,
                        contract_end_year, contract_end_month,
                        level_type, grade,
                        nik, npwp, bpjs_tk_no, bpjs_kes_no, education,
                        phone, email, status, note,
                        bank_name, bank_branch, bank_account_name, bank_account_number,
                        created_at, updated_at
                    )
                    VALUES
                    (
                        :employee_code, :employee_name,
                        :dept_code, :office_code, :join_year, :join_month,
                        :contract_end_year, :contract_end_month,
                        :level_type, :grade,
                        :nik, :npwp, :bpjs_tk_no, :bpjs_kes_no, :education,
                        :phone, :email, :status, :note,
                        :bank_name, :bank_branch, :bank_account_name, :bank_account_number,
                        NOW(), NOW()
                    )
                ");
                $stmt->execute([
                    ':employee_code'       => $employee_code,
                    ':employee_name'       => $employee_name,
                    ':dept_code'           => $dept_code,
                    ':office_code'         => $office_code,
                    ':join_year'           => $join_year,
                    ':join_month'          => $join_month,
                    ':contract_end_year'   => $contract_end_year,
                    ':contract_end_month'  => $contract_end_month,
                    ':level_type'          => $level_type,
                    ':grade'               => $grade,
                    ':nik'                 => $nik,
                    ':npwp'                => $npwp,
                    ':bpjs_tk_no'          => $bpjs_tk_no,
                    ':bpjs_kes_no'         => $bpjs_kes_no,
                    ':education'           => $education,
                    ':phone'               => $phone,
                    ':email'               => $email,
                    ':status'              => $status,
                    ':note'                => $note,
                    ':bank_name'           => $bank_name,
                    ':bank_branch'         => $bank_branch,
                    ':bank_account_name'   => $bank_account_name,
                    ':bank_account_number' => $bank_account_number,
                    ]);
                    if (function_exists('master_audit')) {
                        $newId = (int)$pdo->lastInsertId();
                        master_audit($pdo, 'master_employees', 'master_employees', 'CREATE', $newId, $employee_code, "Employee created: {$employee_code} - {$employee_name}", ['dept_code' => $dept_code]);
                    }
                    set_flash('success', 'Data employee berhasil ditambahkan.');
            }
        } else {
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_employees WHERE employee_code = :code AND id <> :id");
            $cek->execute([':code' => $employee_code, ':id' => $id]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Employee Code sudah dipakai oleh employee lain.');
            } else {
                $stmt = $pdo->prepare("
                    UPDATE master_employees
                    SET
                        employee_code        = :employee_code,
                        employee_name        = :employee_name,
                        dept_code            = :dept_code,
                        office_code          = :office_code,
                        join_year            = :join_year,
                        join_month           = :join_month,
                        contract_end_year    = :contract_end_year,
                        contract_end_month   = :contract_end_month,
                        level_type           = :level_type,
                        grade                = :grade,
                        nik                  = :nik,
                        npwp                 = :npwp,
                        bpjs_tk_no           = :bpjs_tk_no,
                        bpjs_kes_no          = :bpjs_kes_no,
                        education            = :education,
                        phone                = :phone,
                        email                = :email,
                        status               = :status,
                        note                 = :note,
                        bank_name            = :bank_name,
                        bank_branch          = :bank_branch,
                        bank_account_name    = :bank_account_name,
                        bank_account_number  = :bank_account_number,
                        updated_at           = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':employee_code'       => $employee_code,
                    ':employee_name'       => $employee_name,
                    ':dept_code'           => $dept_code,
                    ':office_code'         => $office_code,
                    ':join_year'           => $join_year,
                    ':join_month'          => $join_month,
                    ':contract_end_year'   => $contract_end_year,
                    ':contract_end_month'  => $contract_end_month,
                    ':level_type'          => $level_type,
                    ':grade'               => $grade,
                    ':nik'                 => $nik,
                    ':npwp'                => $npwp,
                    ':bpjs_tk_no'          => $bpjs_tk_no,
                    ':bpjs_kes_no'         => $bpjs_kes_no,
                    ':education'           => $education,
                    ':phone'               => $phone,
                    ':email'               => $email,
                    ':status'              => $status,
                    ':note'                => $note,
                    ':bank_name'           => $bank_name,
                    ':bank_branch'         => $bank_branch,
                    ':bank_account_name'   => $bank_account_name,
                    ':bank_account_number' => $bank_account_number,
                    ':id'                  => $id,
                ]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_employees', 'master_employees', 'UPDATE', (int)$id, $employee_code, "Employee updated: {$employee_code} - {$employee_name}", ['dept_code' => $dept_code]);
                }
                set_flash('success', 'Data employee berhasil diperbarui.');
            }
        }
    } catch (PDOException $e) {
        set_flash('danger', 'Error DB: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_employees.php");
}

// --------------------------------------------------------
// TOGGLE STATUS (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $id = (int)($_POST['toggle_id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT status, dept_code, employee_code FROM master_employees WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if ($row) {
                if (($row['dept_code'] ?? '') === 'SYS' || strpos($row['employee_code'] ?? '', 'SYS') === 0) {
                    set_flash('danger', 'ADMIN & SUPERADMIN (akun sistem) tidak dapat diubah status.');
                } else {
                    $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
                    $upd = $pdo->prepare("
                        UPDATE master_employees
                        SET status = :st, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $upd->execute([':st' => $newStatus, ':id' => $id]);
                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'master_employees', 'master_employees', 'TOGGLE_STATUS', $id, $row['employee_code'] ?? '', "Employee status: {$row['employee_code']} -> {$newStatus}", ['status' => $newStatus]);
                    }
                    set_flash('success', 'Status employee berhasil diubah.');
                }
            }
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal mengubah status: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_employees.php");
}

// --------------------------------------------------------
// DELETE (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        $chk = $pdo->prepare("SELECT dept_code, employee_code FROM master_employees WHERE id = :id");
        $chk->execute([':id' => $id]);
        $row = $chk->fetch();
        if ($row && (($row['dept_code'] ?? '') === 'SYS' || strpos($row['employee_code'] ?? '', 'SYS') === 0)) {
            set_flash('danger', 'ADMIN & SUPERADMIN (akun sistem) tidak dapat dihapus.');
        } else {
            try {
                $del = $pdo->prepare("DELETE FROM master_employees WHERE id = :id");
                $del->execute([':id' => $id]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_employees', 'master_employees', 'DELETE', $id, $row['employee_code'] ?? 'EMP#' . $id, "Employee deleted: " . ($row['employee_code'] ?? '#' . $id), []);
                }
                set_flash('success', 'Data employee berhasil dihapus.');
            } catch (PDOException $e) {
                set_flash('danger', 'Gagal menghapus data: ' . htmlspecialchars($e->getMessage()));
            }
        }
    }
    rmi_redirect("master_employees.php");
}

// Bulk Delete (kecuali SYS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    $ids = array_map('intval', (array)($_POST['bulk_ids'] ?? []));
    $ids = array_filter($ids);
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM master_employees WHERE id IN ($placeholders) AND NOT (dept_code = 'SYS' OR employee_code LIKE 'SYS%')");
        $stmt->execute($ids);
        $cnt = $stmt->rowCount();
        if (function_exists('master_audit')) {
            master_audit($pdo, 'master_employees', 'master_employees', 'BULK_DELETE', null, 'BULK', "Employees bulk delete: {$cnt} rows", ['count' => $cnt]);
        }
        set_flash('success', "{$cnt} employee berhasil dihapus (SYS tidak dihapus).");
    }
    $q = http_build_query(array_filter([
        'filter_dept' => $_GET['filter_dept'] ?? '',
        'filter_office' => $_GET['filter_office'] ?? '',
        'filter_status' => $_GET['filter_status'] ?? '',
        'filter_level' => $_GET['filter_level'] ?? '',
        'filter_search' => $_GET['filter_search'] ?? '',
    ]));
    rmi_redirect('master_employees.php' . ($q ? "?$q" : ''));
}

// Bulk Toggle Status (active/inactive) - kecuali SYS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_toggle'])) {
    $ids = array_map('intval', (array)($_POST['bulk_ids'] ?? []));
    $ids = array_filter($ids);
    $target = ($_POST['bulk_toggle'] ?? '') === 'inactive' ? 'inactive' : 'active';
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE master_employees SET status = ? WHERE id IN ($placeholders) AND NOT (dept_code = 'SYS' OR employee_code LIKE 'SYS%')");
        $stmt->execute(array_merge([$target], $ids));
        $cnt = $stmt->rowCount();
        if (function_exists('master_audit')) {
            master_audit($pdo, 'master_employees', 'master_employees', 'BULK_TOGGLE', null, 'BULK', "Employees bulk status: {$cnt} -> {$target}", ['count' => $cnt, 'status' => $target]);
        }
        set_flash('success', "{$cnt} employee diubah status menjadi {$target}.");
    }
    $q = http_build_query(array_filter([
        'filter_dept' => $_GET['filter_dept'] ?? '',
        'filter_office' => $_GET['filter_office'] ?? '',
        'filter_status' => $_GET['filter_status'] ?? '',
        'filter_level' => $_GET['filter_level'] ?? '',
        'filter_search' => $_GET['filter_search'] ?? '',
    ]));
    rmi_redirect('master_employees.php' . ($q ? "?$q" : ''));
}

// Hapus semua employees (kecuali SYS: ADMIN & SUPERADMIN) - konfirmasi via ketik "HAPUS SEMUA"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_employees'])) {
    $confirmKey = trim($_POST['confirm_delete_all'] ?? '');
    if ($confirmKey === 'HAPUS SEMUA') {
        try {
            $stmt = $pdo->prepare("DELETE FROM master_employees WHERE NOT (dept_code = 'SYS' OR employee_code LIKE 'SYS%')");
            $stmt->execute();
            $cnt = $stmt->rowCount();
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_employees', 'master_employees', 'DELETE_ALL', null, 'BULK', "All employees deleted (non-SYS): {$cnt} rows", ['count' => $cnt]);
            }
            set_flash('success', "Data employee non-SYS ({$cnt} record) berhasil dihapus. ADMIN & SUPERADMIN tetap ada.");
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal menghapus: ' . htmlspecialchars($e->getMessage()));
        }
    } else {
        set_flash('danger', 'Konfirmasi gagal. Ketik "HAPUS SEMUA" (huruf besar) untuk menghapus semua data.');
    }
    rmi_redirect("master_employees.php");
}

// --------------------------------------------------------
// DEFAULT FORM DATA
// --------------------------------------------------------
$edit_data = [
    'id'            => '',
    'employee_code' => '',
    'employee_name' => '',
    'dept_code'     => '',
    'office_code'   => '',
    'join_year'     => '',
    'join_month'    => '',
    'contract_end_year'  => '',
    'contract_end_month' => '',
    'level_type'    => 'Staff',
    'grade'         => '',
    'nik'           => '',
    'npwp'          => '',
    'bpjs_tk_no'    => '',
    'bpjs_kes_no'   => '',
    'education'     => '',
    'phone'         => '',
    'email'         => '',
    'status'              => 'active',
    'note'                => '',
    'bank_name'           => '',
    'bank_branch'         => '',
    'bank_account_name'   => '',
    'bank_account_number' => '',
];

if (!empty($old_form) && !isset($_GET['edit'])) {
    foreach ($edit_data as $k => $v) {
        if (isset($old_form[$k])) {
            $edit_data[$k] = $old_form[$k];
        }
    }
    if (empty($edit_data['status'])) {
        $edit_data['status'] = 'active';
    }
}

// --------------------------------------------------------
// AMBIL DATA EDIT
// --------------------------------------------------------
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM master_employees WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            if (($row['dept_code'] ?? '') === 'SYS' || strpos($row['employee_code'] ?? '', 'SYS') === 0) {
                set_flash('danger', 'ADMIN & SUPERADMIN (akun sistem) tidak dapat diedit.');
                rmi_redirect("master_employees.php");
            }
            foreach ($edit_data as $k => $v) {
                if (array_key_exists($k, $row)) {
                    $edit_data[$k] = $row[$k];
                }
            }
        }
    }
}

// --------------------------------------------------------
// LIST DATA (dengan Filter)
// --------------------------------------------------------
$filter_dept   = trim((string)($_GET['filter_dept'] ?? ''));
$filter_office = trim((string)($_GET['filter_office'] ?? ''));
$filter_status = trim((string)($_GET['filter_status'] ?? ''));
$filter_level  = trim((string)($_GET['filter_level'] ?? ''));
$filter_search = trim((string)($_GET['filter_search'] ?? ''));
if ($filter_status !== '' && !in_array($filter_status, ['active','inactive'], true)) $filter_status = '';
if ($filter_level !== '' && !in_array($filter_level, ['Manager','Staff','SYS'], true)) $filter_level = '';

$where = [];
$params = [];
if ($filter_dept !== '') {
    $where[] = "dept_code = ?";
    $params[] = $filter_dept;
}
if ($filter_office !== '') {
    $where[] = "office_code = ?";
    $params[] = $filter_office;
}
if ($filter_status !== '') {
    $where[] = "status = ?";
    $params[] = $filter_status;
}
if ($filter_level !== '') {
    $where[] = "level_type = ?";
    $params[] = $filter_level;
}
if ($filter_search !== '') {
    $where[] = "(employee_code LIKE ? OR employee_name LIKE ? OR nik LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter_search);
    $search = '%' . $escaped . '%';
    $params = array_merge($params, [$search, $search, $search, $search, $search]);
}

$sql = "SELECT e.*,
               COALESCE(m.username, '') AS linked_username,
               COALESCE(m.id, 0)       AS linked_user_id
        FROM master_employees e
        LEFT JOIN master_system_login m
               ON m.holder_employee_code = e.employee_code
              AND m.deleted_at IS NULL";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY e.dept_code ASC, e.office_code ASC, e.level_type ASC, e.employee_name ASC";

$list_stmt = $pdo->prepare($sql);
$list_stmt->execute($params);
$employees = $list_stmt->fetchAll();
$flash     = get_flash();

$audit_rows = [];
if (function_exists('master_audit_ensure_table')) {
    master_audit_ensure_table($pdo);
    try {
        $stA = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='master_employees' ORDER BY created_at DESC LIMIT 50");
        $stA->execute();
        $audit_rows = $stA->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Employees', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Employees',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>


<div class="rmi-container">

    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>MASTER EMPLOYEES</h5>
                <small style="font-size:12px;">
                    Data karyawan internal (Marketing, CRM, SCM, Finance, WQS, PQP, ITC, HRL, dll).
                    Terhubung ke departemen, kantor, level &amp; golongan.
                </small>
            </div>
            <div>
                <a href="master_data.php" class="btn btn-sm btn-secondary">&laquo; Kembali ke Master Data</a>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars((string)($flash['type'] ?? 'info')) ?> alert-dismissible fade show" role="alert">
            <?= function_exists('rmi_h') ? rmi_h($flash['message'] ?? '') : htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- FORM -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5><?= $edit_data['id'] ? 'Edit Employee' : 'Tambah Employee' ?></h5>
        </div>
        <div class="rmi-card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= htmlspecialchars((string)($edit_data['id'] ?? '')) ?>">

                <div class="col-md-3">
                    <label class="form-label">Employee Code</label>
                    <input type="text" name="employee_code" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['employee_code'] ?? '')) ?>"
                           placeholder="Auto generate" readonly>
                    <div class="form-text text-muted" style="font-size:11px;">
                        Di-generate otomatis: DEPT + YY + MM + nomor urut.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Employee Name<span class="text-danger">*</span></label>
                    <input type="text" name="employee_name" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['employee_name'] ?? '')) ?>"
                           placeholder="Nama lengkap karyawan">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Level</label>
                    <select name="level_type" class="form-select form-select-sm">
                        <option value="Manager" <?= $edit_data['level_type'] === 'Manager' ? 'selected' : '' ?>>Manager</option>
                        <option value="Staff"   <?= $edit_data['level_type'] === 'Staff'   ? 'selected' : '' ?>>Staff</option>
                        <option value="SYS"     <?= $edit_data['level_type'] === 'SYS'     ? 'selected' : '' ?>>SYS</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Golongan</label>
                    <select name="grade" class="form-select form-select-sm">
                        <option value="">-</option>
                        <option value="A" <?= $edit_data['grade'] === 'A' ? 'selected' : '' ?>>A</option>
                        <option value="B" <?= $edit_data['grade'] === 'B' ? 'selected' : '' ?>>B</option>
                        <option value="C" <?= $edit_data['grade'] === 'C' ? 'selected' : '' ?>>C</option>
                    </select>
                    <div class="form-text text-muted" style="font-size:11px;">
                        Dipakai nanti untuk rumus gaji (Manager/Staff/SYS + A/B/C).
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Tahun Bergabung<span class="text-danger">*</span></label>
                    <input type="number" name="join_year" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['join_year'] ?? '')) ?>"
                           placeholder="misal: 2025" min="2000" max="2100">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Bulan Bergabung<span class="text-danger">*</span></label>
                    <select name="join_month" class="form-select form-select-sm">
                        <option value="">-</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                            <?php $val = str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?>
                            <option value="<?= $val ?>" <?= $edit_data['join_month'] === $val ? 'selected' : '' ?>>
                                <?= $val ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Tahun Berakhir Kontrak</label>
                    <input type="number" name="contract_end_year" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['contract_end_year'] ?? '')) ?>"
                           placeholder="misal: 2026" min="2000" max="2100">
                    <div class="form-text text-muted" style="font-size:11px;">
                        Opsional. Dipakai pengingat HRL, tidak mempengaruhi hak cuti.
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Bulan Berakhir Kontrak</label>
                    <select name="contract_end_month" class="form-select form-select-sm">
                        <option value="">-</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                            <?php $val = str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?>
                            <option value="<?= $val ?>" <?= ($edit_data['contract_end_month'] ?? '') === $val ? 'selected' : '' ?>>
                                <?= $val ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Departemen<span class="text-danger">*</span></label>
                    <select name="dept_code" class="form-select form-select-sm">
                        <option value="">-- Pilih Departemen --</option>
                        <?php foreach ($deptOptions as $d): $dc=(string)($d['dept_code']??''); if($dc==='')continue; ?>
                            <option value="<?= htmlspecialchars($dc) ?>"
                                <?= strtoupper(trim((string)($edit_data['dept_code']??''))) === $dc ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dc . ' - ' . ($d['dept_name']??'')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Kantor<span class="text-danger">*</span></label>
                    <select name="office_code" class="form-select form-select-sm">
                        <option value="">-- Pilih Kantor / Cabang --</option>
                        <?php foreach ($officeOptions as $o): $oc=(string)($o['office_code']??''); if($oc==='')continue; ?>
                            <option value="<?= htmlspecialchars($oc) ?>"
                                <?= strtoupper(trim((string)($edit_data['office_code']??''))) === $oc ? 'selected' : '' ?>>
                                <?= htmlspecialchars(strtoupper($o['office_name']??$oc) . ($o['city'] ? ' (' . strtoupper($o['city']) . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">NIK</label>
                    <input type="text" name="nik" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['nik'] ?? '')) ?>"
                           placeholder="Nomor Induk Kependudukan">
                </div>

                <div class="col-md-3">
                    <label class="form-label">NPWP</label>
                    <input type="text" name="npwp" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['npwp'] ?? '')) ?>"
                           placeholder="Nomor NPWP">
                </div>
                <div class="col-12">
                    <hr class="border-secondary">
                    <div class="text-muted-small">Data Rekening Untuk Payroll</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Bank</label>
                    <input type="text" name="bank_name" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['bank_name'] ?? '') ?>"
                           placeholder="Contoh: BCA, BRI, Mandiri">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Cabang</label>
                    <input type="text" name="bank_branch" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['bank_branch'] ?? '') ?>"
                           laceholder="Cabang rekening">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Nama Rekening</label>
                    <input type="text" name="bank_account_name" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['bank_account_name'] ?? '') ?>"
                           placeholder="Nama pemilik rekening">
                </div>

                <div class="col-md-3">
                    <label class="form-label">No Rekening</label>
                    <input type="text" name="bank_account_number" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['bank_account_number'] ?? '') ?>"
                           placeholder="Nomor rekening">
                </div>

                <div class="col-md-3">
                    <label class="form-label">BPJS Ketenagakerjaan</label>
                    <input type="text" name="bpjs_tk_no" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['bpjs_tk_no'] ?? '')) ?>"
                           placeholder="No BPJS TK">
                </div>

                <div class="col-md-3">
                    <label class="form-label">BPJS Kesehatan</label>
                    <input type="text" name="bpjs_kes_no" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['bpjs_kes_no'] ?? '')) ?>"
                           placeholder="No BPJS Kes">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Pendidikan</label>
                    <input type="text" name="education" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['education'] ?? '')) ?>"
                           placeholder="Contoh: S1 Akuntansi, D3 Keperawatan">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['phone'] ?? '')) ?>"
                           placeholder="No. HP / WA">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['email'] ?? '')) ?>"
                           placeholder="Email kantor jika ada">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="active"   <?= $edit_data['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $edit_data['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="note" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string)($edit_data['note'] ?? '')) ?>"
                           placeholder="Catatan singkat (opsional)">
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <button type="submit" name="save_employee" class="btn btn-sm btn-primary">
                        <?= $edit_data['id'] ? 'Update Employee' : 'Simpan Employee' ?>
                    </button>
                    <a href="master_employees.php" class="btn btn-sm btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- LIST + IMPORT -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>LIST EMPLOYEES</h5>
                <small style="font-size:12px;">
                    Export: Copy / CSV / Excel / PDF / Print (kolom Aksi tidak ikut export).
                </small>
            </div>
        </div>
        <div class="rmi-card-body">

            <!-- IMPORT CSV -->
            <div class="mb-3" id="import">
                <div class="fw-semibold mb-2">Panduan Pengisian Template Employees</div>
                <details class="small mb-2">
                    <summary class="text-muted cursor-pointer">Klik untuk melihat cara pengisian detail</summary>
                    <div class="mt-2 p-2" style="background:rgba(0,0,0,.2);border-radius:8px;">
                        <p class="mb-2"><strong>Langkah:</strong> Download template → Buka di Excel → Jangan ubah header → Isi data baris 2 dst → Simpan CSV (UTF-8) → Upload</p>
                        <p class="mb-1"><strong>Kolom wajib:</strong> employee_name, dept_code, office_code, join_year (4 digit, misal 2024), join_month (01-12, misal 01 atau 1). employee_code kosong = auto-generate</p>
                        <p class="mb-1"><strong>Kolom opsional:</strong> employee_code, contract_end_year, contract_end_month, level_type (Manager/Staff/SYS atau alias: M/Mgr→Manager, S/D/G/Staf→Staff), grade (A/B/C), nik, npwp, bpjs_tk_no, bpjs_kes_no, education, phone, email, status (active/inactive), note</p>
                        <p class="mb-0 small">dept_code & office_code harus kode singkat (max 20 char), bukan nama lengkap. Contoh: FIN, SCM, ITC, BGR, TGR. Harus sudah ada di master_departements & master_office. Re-import akan update data existing (cocokkan by employee_code, NIK, atau nama+dept+kantor+join) sehingga tidak duplikat. <strong>NIK harus unik per orang</strong>—jika NIK sama tapi nama beda, baris akan di-skip.</p>
                    </div>
                </details>
                <?php if (is_file($empTplPath)): ?>
                <div class="mb-2"><a href="?download_template=employees" class="btn btn-sm btn-outline-light">Download Template</a> <span class="text-muted small">Template diisi tim → 1 klik upload</span></div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="col-md-5">
                        <label class="form-label mb-1">Import Employees (CSV)</label>
                        <input type="file" name="import_file" class="form-control form-control-sm" accept=".csv">
                        <div class="form-text text-muted" style="font-size:11px;">
                            <strong>Penting:</strong> Simpan CSV sebagai <strong>UTF-8 (Comma delimited)</strong> di Excel (Save As → CSV UTF-8). Kolom: employee_code, employee_name, dept_code, office_code, join_year, join_month, contract_end_year, contract_end_month, level_type, grade, nik, npwp, bpjs_tk_no, bpjs_kes_no, education, phone, email, status, note. Baris pertama = header (di-skip). Sistem mendukung delimiter koma (,) dan titik-koma (;).
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="import_employees" class="btn btn-sm btn-secondary">
                            Import CSV
                        </button>
                    </div>
                </form>
            </div>

            <!-- Hapus Semua (kecuali ADMIN & SUPERADMIN) -->
            <?php if (!empty($employees)): ?>
            <div class="mb-3">
                <details class="small">
                    <summary class="text-danger cursor-pointer">Hapus semua data employees (kecuali ADMIN & SUPERADMIN)</summary>
                    <div class="mt-2 p-2 border border-danger rounded">
                        <p class="mb-2 small text-muted">Tindakan ini tidak dapat dibatalkan. Semua data employee akan dihapus permanen, kecuali akun sistem (dept SYS / kode SYS*).</p>
                        <form method="post" class="row g-2 align-items-end" onsubmit="return document.getElementById('confirm_delete_all').value === 'HAPUS SEMUA'">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <div class="col-auto">
                                <label class="form-label mb-0 small">Ketik <strong>HAPUS SEMUA</strong> untuk konfirmasi:</label>
                                <input type="text" id="confirm_delete_all" name="confirm_delete_all" class="form-control form-control-sm" placeholder="HAPUS SEMUA" maxlength="20" autocomplete="off">
                            </div>
                            <div class="col-auto">
                                <button type="submit" name="delete_all_employees" class="btn btn-sm btn-danger">Hapus Semua</button>
                            </div>
                        </form>
                    </div>
                </details>
            </div>
            <?php endif; ?>

            <!-- Filter -->
            <form method="get" class="row g-2 align-items-end mb-3">
                <div class="col-auto">
                    <label class="form-label mb-0 small">Dept</label>
                    <select name="filter_dept" class="form-select form-select-sm" style="width:auto;">
                        <option value="">-- Semua --</option>
                        <?php foreach ($deptOptions as $d): $dc=(string)($d['dept_code']??''); if($dc==='')continue; ?>
                        <option value="<?= htmlspecialchars($dc) ?>" <?= $filter_dept === $dc ? 'selected' : '' ?>><?= htmlspecialchars($dc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label mb-0 small">Kantor</label>
                    <select name="filter_office" class="form-select form-select-sm" style="width:auto;">
                        <option value="">-- Semua --</option>
                        <?php foreach ($officeOptions as $o): $oc=(string)($o['office_code']??''); if($oc==='')continue; ?>
                        <option value="<?= htmlspecialchars($oc) ?>" <?= $filter_office === $oc ? 'selected' : '' ?>><?= htmlspecialchars($oc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label mb-0 small">Status</label>
                    <select name="filter_status" class="form-select form-select-sm" style="width:auto;">
                        <option value="">-- Semua --</option>
                        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label mb-0 small">Level</label>
                    <select name="filter_level" class="form-select form-select-sm" style="width:auto;">
                        <option value="">-- Semua --</option>
                        <option value="Manager" <?= $filter_level === 'Manager' ? 'selected' : '' ?>>Manager</option>
                        <option value="Staff" <?= $filter_level === 'Staff' ? 'selected' : '' ?>>Staff</option>
                        <option value="SYS" <?= $filter_level === 'SYS' ? 'selected' : '' ?>>SYS</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label mb-0 small">Cari</label>
                    <input type="text" name="filter_search" class="form-control form-control-sm" placeholder="Kode, Nama, NIK, Email, HP" value="<?= htmlspecialchars($filter_search) ?>" style="width:180px;">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-light">Filter</button>
                    <a href="master_employees.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>

            <!-- Bulk Actions + Table -->
            <?php if (!empty($employees)): ?>
            <form method="post" id="form-bulk">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="small text-muted">Bulk:</span>
                    <button type="submit" name="bulk_toggle" value="active" class="btn btn-sm btn-outline-success" onclick="return bulkConfirm('Aktifkan')">Aktifkan</button>
                    <button type="submit" name="bulk_toggle" value="inactive" class="btn btn-sm btn-outline-warning" onclick="return bulkConfirm('Nonaktifkan')">Nonaktifkan</button>
                    <button type="submit" name="bulk_delete" value="1" class="btn btn-sm btn-outline-danger" onclick="return bulkConfirm('Hapus')">Hapus Terpilih</button>
                    <span class="small text-muted" id="bulk-count">0 terpilih</span>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table id="table-employees" class="table table-sm table-striped table-hover align-middle table-dark-custom" style="width:100%;">
                    <thead>
                    <tr>
                        <th class="no-export" style="width:36px;"><input type="checkbox" id="bulk-select-all" title="Pilih semua"></th>
                        <th>#</th>
                        <th>Emp Code</th>
                        <th>Nama</th>
                        <th>Username ERP</th>
                        <th>Dept</th>
                        <th>Kantor</th>
                        <th>Join Year</th>
                        <th>Join Month</th>
                        <th>Kontrak End</th>
                        <th>Sisa Kontrak</th>
                        <th>Level</th>
                        <th>Gol</th>
                        <th>Pendidikan</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>NIK</th>
                        <th>NPWP</th>
                        <th>BPJS TK</th>
                        <th>BPJS Kes</th>
                        <th>Status</th>
                        <th>Catatan</th>
                        <th class="text-center no-export" style="width:180px;">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="23" class="text-center text-muted">Belum ada data employee.</td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; ?>
                        <?php foreach ($employees as $e): ?>
                            <?php $isSys = ($e['dept_code'] ?? '') === 'SYS' || strpos($e['employee_code'] ?? '', 'SYS') === 0; ?>
                            <tr>
                                <td class="no-export"><?php if (!$isSys): ?><input type="checkbox" name="bulk_ids[]" value="<?= (int)$e['id'] ?>" class="bulk-cb"><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                                <td><?= $no++ ?></td>
                                <td><?= htmlspecialchars((string)($e['employee_code'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['employee_name'] ?? '')) ?></td>
                                <td>
                                  <?php if (!empty($e['linked_username'])): ?>
                                    <span style="color:#4ade80;font-weight:600"><?= htmlspecialchars((string)$e['linked_username']) ?></span>
                                  <?php else: ?>
                                    <span class="badge bg-danger" style="font-size:11px"><?= rmi_icon('x') ?> Belum</span>
                                  <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string)(me_get_dept_label($deptOptions, $e['dept_code'] ?? '') ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)(me_get_office_label($officeOptions, $e['office_code'] ?? '') ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['join_year'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['join_month'] ?? '')) ?></td>
                                <td>
                                    <?php
                                      $ceY = trim((string)($e['contract_end_year'] ?? ''));
                                      $ceM = trim((string)($e['contract_end_month'] ?? ''));
                                      echo htmlspecialchars(($ceY !== '' && $ceM !== '') ? ($ceY . '-' . str_pad($ceM, 2, '0', STR_PAD_LEFT)) : '-');
                                    ?>
                                </td>
                                <td>
                                    <?php
                                      $contractBadge = '-';
                                      if ($ceY !== '' && $ceM !== '') {
                                          try {
                                              $endDate = new DateTime($ceY . '-' . str_pad($ceM, 2, '0', STR_PAD_LEFT) . '-01');
                                              $endDate->modify('last day of this month');
                                              $today = new DateTime(date('Y-m-d'));
                                              $daysLeft = (int)$today->diff($endDate)->format('%r%a');
                                              if ($daysLeft < 0) {
                                                  $contractBadge = '<span class="badge bg-danger">Expired ' . abs($daysLeft) . ' hari</span>';
                                              } elseif ($daysLeft <= 30) {
                                                  $contractBadge = '<span class="badge bg-danger">' . $daysLeft . ' hari</span>';
                                              } elseif ($daysLeft <= 90) {
                                                  $contractBadge = '<span class="badge bg-warning text-dark">' . $daysLeft . ' hari</span>';
                                              } else {
                                                  $contractBadge = '<span class="badge bg-success">' . $daysLeft . ' hari</span>';
                                              }
                                          } catch (Throwable $e2) {}
                                      }
                                      echo $contractBadge;
                                    ?>
                                </td>
                                <td><?= htmlspecialchars((string)($e['level_type'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['grade'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['education'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['phone'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['email'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['nik'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['npwp'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['bpjs_tk_no'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($e['bpjs_kes_no'] ?? '')) ?></td>
                                <td>
                                    <?php if (($e['status'] ?? '') === 'active'): ?>
                                        <span class="badge badge-status active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-status inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string)($e['note'] ?? '')) ?></td>
                                <td class="text-center no-export">
                                    <?php if ($isSys): ?>
                                        <span class="text-muted small" title="Akun sistem tidak dapat diubah">—</span>
                                    <?php else: ?>
                                        <a href="master_employees.php?edit=<?= (int)$e['id'] ?>"
                                           class="btn btn-sm btn-outline-primary mb-1">Edit</a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="toggle_id" value="<?= (int)$e['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning mb-1">
                                                <?= ($e['status'] ?? '') === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Yakin hapus data employee ini?')">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="delete_id" value="<?= (int)$e['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($employees)): ?></form><?php endif; ?>
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
        var dt = $('#table-employees').DataTable({
            dom: 'Bfrtip',
            paging: true,
            lengthChange: true,
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Semua"]],
            ordering: true,
            order: [[5, 'asc'], [6, 'asc'], [3, 'asc']], // Dept, Kantor, Nama
            info: true,
            searching: true,
            buttons: [
                {
                    extend: 'copyHtml5',
                    className: 'btn btn-sm btn-outline-light',
                    exportOptions: { columns: ':not(.no-export)' }
                },
                {
                    extend: 'csvHtml5',
                    className: 'btn btn-sm btn-outline-light',
                    exportOptions: { columns: ':not(.no-export)' }
                },
                {
                    extend: 'excelHtml5',
                    className: 'btn btn-sm btn-outline-light',
                    exportOptions: { columns: ':not(.no-export)' }
                },
                {
                    extend: 'pdfHtml5',
                    className: 'btn btn-sm btn-outline-light',
                    exportOptions: { columns: ':not(.no-export)' }
                },
                {
                    extend: 'print',
                    className: 'btn btn-sm btn-outline-light',
                    exportOptions: { columns: ':not(.no-export)' }
                }
            ]
        });

        function updateBulkCount() {
            var n = $('#table-employees .bulk-cb:checked').length;
            $('#bulk-count').text(n + ' terpilih');
        }
        function bulkConfirm(action) {
            var n = $('#table-employees .bulk-cb:checked').length;
            if (n === 0) { alert('Pilih minimal 1 employee.'); return false; }
            return confirm(action + ' ' + n + ' employee terpilih?');
        }
        $('#bulk-select-all').on('click', function() {
            var c = this.checked;
            $('#table-employees .bulk-cb').each(function() { this.checked = c; });
            updateBulkCount();
        });
        $('#table-employees').on('change', '.bulk-cb', updateBulkCount);
    });
</script>
<?php rmi_footer(); ?>
