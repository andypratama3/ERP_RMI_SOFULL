<?php
// payroll/_inc/bootstrap.php
// Payroll Starter + Loans + Audit + Salary Matrix (Enterprise-ready)

error_reporting(E_ALL);
if (defined('APP_DEBUG') && APP_DEBUG) { ini_set('display_errors', '1'); } else { ini_set('display_errors', '0'); }

require_once dirname(__DIR__, 2) . '/master/auth.php';

/*
 * PAYROLL LOGIN SAFE FIX
 * Jangan panggil require_login() di bootstrap payroll.
 * require_login() menjalankan RBAC method/action global dan memblokir POST action=create_run,
 * padahal Payroll sudah punya guard internal FIN/HRL/SYS di bawah ini.
 *
 * Yang tetap dijalankan:
 * - auth_require_login() agar user wajib login.
 * - verify_csrf() untuk POST/PUT/PATCH/DELETE agar keamanan form tetap aman.
 * - guard departemen/level payroll di bawah tetap aktif.
 */
if (function_exists('auth_require_login')) {
    auth_require_login();
} elseif (function_exists('require_login')) {
    // Fallback lama jika auth_require_login belum tersedia.
    require_login();
}

$__payroll_method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (in_array($__payroll_method, ['POST','PUT','PATCH','DELETE'], true) && function_exists('verify_csrf')) {
    verify_csrf();
}


// Payroll — hanya FIN Manager atau ADMIN/SUPERADMIN/SYS
// Data gaji karyawan adalah informasi paling sensitif di perusahaan
$__role  = strtoupper((string)($_SESSION['role'] ?? ''));
$__level = strtoupper((string)($_SESSION['level'] ?? ''));
$__dept  = strtoupper((string)($_SESSION['department'] ?? ''));
$__user  = strtoupper((string)($_SESSION['username'] ?? ''));

$__admin = in_array($__role, ['ADMIN','SUPERADMIN','SYS'], true)
        || in_array($__level, ['ADMIN','SUPERADMIN','SYS'], true);

$__is_mgr_fin = (
    ($__role === 'MANAGER' && $__dept === 'FIN')
    || ($__level === 'MANAGER' && $__dept === 'FIN')
    || ($__user === 'MGRFIN_BGR' && $__dept === 'FIN')
);

if (function_exists('auth_is_fin_manager')) {
    if (!auth_is_fin_manager() && !$__is_mgr_fin && !$__admin) {
        http_response_code(403);
        echo '<h3>Akses Terbatas</h3><p>Modul Payroll hanya untuk <b>Manager FIN</b> atau <b>ADMIN/SUPERADMIN</b>.</p>';
        exit;
    }
} elseif (function_exists('require_any_permission')) {
    if (!$__is_mgr_fin && !$__admin) {
        require_any_permission([
            'PAYROLL.VIEW',
            'PAYROLL.LOANS_VIEW',
            'PAYROLL.PAYSLIP_VIEW',
            'PAYROLL.SETTINGS',
            'PAYROLL.CREATE',
            'PAYROLL.EDIT',
            'PAYROLL.APPROVE',
            'PAYROLL.AUDIT'
        ]);
    }
}

$pdo = db_pdo();

// BASE url project (mis: /ERP_RMI_SOFULL)
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\'); // .../payroll
$BASE_PROJECT = rtrim(dirname($scriptDir), '/\\');
$BASE_PAYROLL = $BASE_PROJECT . '/payroll';

// Shared UI
require_once __DIR__ . '/../../_shared/rmi_layout.php';

// Shared audit (kalau file belum ada, dibuat stub agar tidak fatal)
if (file_exists(__DIR__ . '/../../_shared/erp_audit.php')) {
    require_once __DIR__ . '/../../_shared/erp_audit.php';
} else {
    function erp_audit_ensure(PDO $pdo): void {}
    function erp_audit(PDO $pdo, string $module, ?string $entityKey, string $action, $payload = null): void {}
    function erp_audit_excerpt(?string $json, int $limit = 120): string { return ''; }
}

// ------------------------- helpers -------------------------
function payroll_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function payroll_table_exists(PDO $pdo, string $table): bool {
    // information_schema lebih aman/portable dibanding SHOW TABLES (yang kadang tidak cocok dengan prepared statement)
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function payroll_column_exists(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    $stmt->execute([$table, $col]);
    return (bool)$stmt->fetchColumn();
}


function payroll_add_col(PDO $pdo, string $table, string $col, string $ddl): void {
    if (!payroll_column_exists($pdo, $table, $col)) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
        } catch (Throwable $e) {
            // ignore
        }
    }
}

function payroll_ensure_schema(PDO $pdo): void {
    // settings per employee
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_employee_settings (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        login_user_id INT NULL,
        pay_type VARCHAR(20) NOT NULL DEFAULT 'MONTHLY',
        salary_basic DECIMAL(18,2) NOT NULL DEFAULT 0,

        -- optional components (override dari salary matrix kalau perlu)
        op_rate_day DECIMAL(18,2) NOT NULL DEFAULT 0,
        allowance_position DECIMAL(18,2) NOT NULL DEFAULT 0,
        allowance_child DECIMAL(18,2) NOT NULL DEFAULT 0,
        allowance_transport DECIMAL(18,2) NOT NULL DEFAULT 0,
        allowance_quota DECIMAL(18,2) NOT NULL DEFAULT 0,

        allowance_fixed DECIMAL(18,2) NOT NULL DEFAULT 0,
        deduction_fixed DECIMAL(18,2) NOT NULL DEFAULT 0,
        overtime_rate_per_hour DECIMAL(18,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_employee (employee_id),
        KEY idx_login_user_id (login_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // run header
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_runs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        period_ym VARCHAR(7) NOT NULL,
        office_code VARCHAR(50) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
        note VARCHAR(255) NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        posted_by INT NULL,
        posted_at DATETIME NULL,
        paid_by INT NULL,
        paid_at DATETIME NULL,
        KEY idx_period (period_ym),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Kalender hari libur payroll. Global bila office_code NULL/kosong; dapat dibuat khusus per office.
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_holidays (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL,
        holiday_name VARCHAR(150) NOT NULL,
        office_code VARCHAR(50) NULL,
        holiday_type VARCHAR(20) NOT NULL DEFAULT 'NATIONAL',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_payroll_holiday (holiday_date, office_code),
        KEY idx_payroll_holiday_date (holiday_date),
        KEY idx_payroll_holiday_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Seed aman untuk libur nasional Juni 2026. INSERT IGNORE tidak menimpa data yang sudah ada.
    try {
        $pdo->exec("INSERT IGNORE INTO payroll_holidays
            (holiday_date, holiday_name, office_code, holiday_type, is_active) VALUES
            ('2026-06-01', 'Hari Lahir Pancasila', NULL, 'NATIONAL', 1),
            ('2026-06-16', 'Tahun Baru Islam 1448 H', NULL, 'NATIONAL', 1)");
    } catch (Throwable $e) {
        // Jangan menggagalkan modul payroll bila user database belum mengizinkan seed.
    }

    // run items
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_run_items (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT NOT NULL,
        employee_id INT NOT NULL,
        login_user_id INT NULL,
        pay_type VARCHAR(20) NOT NULL DEFAULT 'MONTHLY',

        -- snapshot matrix (biar run tidak berubah kalau employee berubah setelahnya)
        matrix_year INT NULL,
        matrix_status VARCHAR(20) NULL,
        matrix_level VARCHAR(5) NULL,
        matrix_take_home DECIMAL(18,2) NOT NULL DEFAULT 0,

        work_days INT NOT NULL DEFAULT 0,
        days_present INT NOT NULL DEFAULT 0,
        leave_days INT NOT NULL DEFAULT 0,
        absent_days INT NOT NULL DEFAULT 0,

        salary_basic DECIMAL(18,2) NOT NULL DEFAULT 0,
        base_amount DECIMAL(18,2) NOT NULL DEFAULT 0,

        op_rate_day DECIMAL(18,2) NOT NULL DEFAULT 0,
        op_amount DECIMAL(18,2) NOT NULL DEFAULT 0,

        allowance_position DECIMAL(18,2) NOT NULL DEFAULT 0,
        allowance_child DECIMAL(18,2) NOT NULL DEFAULT 0,
        allowance_transport DECIMAL(18,2) NOT NULL DEFAULT 0,
        allowance_quota DECIMAL(18,2) NOT NULL DEFAULT 0,

        allowance_fixed DECIMAL(18,2) NOT NULL DEFAULT 0,
        deduction_fixed DECIMAL(18,2) NOT NULL DEFAULT 0,

        overtime_rate_per_hour DECIMAL(18,2) NOT NULL DEFAULT 0,
        overtime_hours DECIMAL(18,2) NOT NULL DEFAULT 0,
        overtime_amount DECIMAL(18,2) NOT NULL DEFAULT 0,

        other_allowance DECIMAL(18,2) NOT NULL DEFAULT 0,
        other_deduction DECIMAL(18,2) NOT NULL DEFAULT 0,
        absence_deduction DECIMAL(18,2) NOT NULL DEFAULT 0,

        kasbon_deduction DECIMAL(18,2) NOT NULL DEFAULT 0,
        loan_deduction DECIMAL(18,2) NOT NULL DEFAULT 0,
        late_deduction DECIMAL(18,2) NOT NULL DEFAULT 0,

        tax_pph21 DECIMAL(18,2) NOT NULL DEFAULT 0,
        bpjs_tk DECIMAL(18,2) NOT NULL DEFAULT 0,
        bpjs_kes DECIMAL(18,2) NOT NULL DEFAULT 0,

        gross_pay DECIMAL(18,2) NOT NULL DEFAULT 0,
        total_deduction DECIMAL(18,2) NOT NULL DEFAULT 0,
        net_pay DECIMAL(18,2) NOT NULL DEFAULT 0,

        note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,

        UNIQUE KEY uniq_run_employee (run_id, employee_id),
        KEY idx_run (run_id),
        KEY idx_employee (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Salary Matrix (Master Golongan Gaji)
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_salary_matrix (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        matrix_year INT NOT NULL,
        payroll_status VARCHAR(20) NOT NULL,
        payroll_level VARCHAR(5) NOT NULL,
        job_title VARCHAR(50) NULL,

        take_home_pay DECIMAL(18,2) NOT NULL DEFAULT 0,
        basic_salary DECIMAL(18,2) NOT NULL DEFAULT 0,
        op_rate_day DECIMAL(18,2) NOT NULL DEFAULT 0,
        work_days_default INT NOT NULL DEFAULT 21,

        tunj_jabatan DECIMAL(18,2) NOT NULL DEFAULT 0,
        tunj_anak DECIMAL(18,2) NOT NULL DEFAULT 0,
        transport DECIMAL(18,2) NOT NULL DEFAULT 0,
        kuota DECIMAL(18,2) NOT NULL DEFAULT 0,

        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_matrix (matrix_year, payroll_status, payroll_level),
        KEY idx_year (matrix_year),
        KEY idx_status (payroll_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Pastikan Salary Matrix mendukung beberapa job pada status+level yang sama.
    // Jika index lama masih (matrix_year,payroll_status,payroll_level), upsert job STAFF/MGR bisa saling menimpa.
    try {
        $idx = $pdo->query("SHOW INDEX FROM payroll_salary_matrix WHERE Key_name='uniq_matrix'")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_map(fn($r) => $r['Column_name'] ?? '', $idx);
        if ($idx && !in_array('job_title', $cols, true)) {
            $pdo->exec("ALTER TABLE payroll_salary_matrix DROP INDEX uniq_matrix");
            $pdo->exec("ALTER TABLE payroll_salary_matrix ADD UNIQUE KEY uniq_matrix (matrix_year, payroll_status, payroll_level, job_title)");
        } elseif (!$idx) {
            $pdo->exec("ALTER TABLE payroll_salary_matrix ADD UNIQUE KEY uniq_matrix (matrix_year, payroll_status, payroll_level, job_title)");
        }
    } catch (Throwable $e) {
        // Abaikan agar alur payroll lain tidak gagal jika DB tidak mengizinkan alter index.
    }

    // Loans table (Kasbon/Pinjaman)
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_loans (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        loan_type VARCHAR(20) NOT NULL DEFAULT 'LOAN',
        principal DECIMAL(18,2) NOT NULL DEFAULT 0,
        tenor_months INT NOT NULL DEFAULT 1,
        installment_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        start_period_ym VARCHAR(7) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        note VARCHAR(255) NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        KEY idx_employee (employee_id),
        KEY idx_status (status),
        KEY idx_start (start_period_ym),
        KEY idx_type (loan_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // ---------------- Backward compatible / auto-migrate columns ----------------
    payroll_add_col($pdo, 'payroll_runs', 'posted_by', "posted_by INT NULL AFTER created_at");
    payroll_add_col($pdo, 'payroll_runs', 'posted_at', "posted_at DATETIME NULL AFTER posted_by");
    payroll_add_col($pdo, 'payroll_runs', 'paid_by', "paid_by INT NULL AFTER posted_at");
    payroll_add_col($pdo, 'payroll_runs', 'paid_at', "paid_at DATETIME NULL AFTER paid_by");
    payroll_add_col($pdo, 'payroll_runs', 'payment_reference', "payment_reference VARCHAR(120) NULL AFTER paid_at");
    payroll_add_col($pdo, 'payroll_runs', 'payment_note', "payment_note VARCHAR(255) NULL AFTER payment_reference");
    payroll_add_col($pdo, 'payroll_holidays', 'note', "note VARCHAR(255) NULL AFTER is_active");
    payroll_add_col($pdo, 'payroll_holidays', 'updated_by', "updated_by INT NULL AFTER updated_at");

    // Pencatatan pembayaran payroll. Tidak mengubah kalkulasi payroll yang sudah baik.
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_payments (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT NOT NULL,
        payment_date DATE NOT NULL,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'BANK_TRANSFER',
        reference_no VARCHAR(120) NOT NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'RECORDED',
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_run_reference (run_id, reference_no),
        KEY idx_run (run_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_payment_items (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        payment_id BIGINT NOT NULL,
        run_item_id BIGINT NOT NULL,
        employee_id INT NOT NULL,
        bank_name VARCHAR(100) NULL,
        bank_account VARCHAR(100) NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        payment_status VARCHAR(20) NOT NULL DEFAULT 'PAID',
        failure_reason VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_payment_item (payment_id, run_item_id),
        KEY idx_payment (payment_id),
        KEY idx_run_item (run_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // payroll_employee_settings: add missing component cols (older installs)
    payroll_add_col($pdo, 'payroll_employee_settings', 'op_rate_day', "`op_rate_day` DECIMAL(18,2) NOT NULL DEFAULT 0");
    payroll_add_col($pdo, 'payroll_employee_settings', 'allowance_position', "`allowance_position` DECIMAL(18,2) NOT NULL DEFAULT 0");
    payroll_add_col($pdo, 'payroll_employee_settings', 'allowance_child', "`allowance_child` DECIMAL(18,2) NOT NULL DEFAULT 0");
    payroll_add_col($pdo, 'payroll_employee_settings', 'allowance_transport', "`allowance_transport` DECIMAL(18,2) NOT NULL DEFAULT 0");
    payroll_add_col($pdo, 'payroll_employee_settings', 'allowance_quota', "`allowance_quota` DECIMAL(18,2) NOT NULL DEFAULT 0");

    // payroll_run_items: add missing cols
    payroll_add_col($pdo, 'payroll_run_items', 'matrix_year', "`matrix_year` INT NULL");
    payroll_add_col($pdo, 'payroll_run_items', 'matrix_status', "`matrix_status` VARCHAR(20) NULL");
    payroll_add_col($pdo, 'payroll_run_items', 'matrix_level', "`matrix_level` VARCHAR(5) NULL");
    payroll_add_col($pdo, 'payroll_run_items', 'matrix_take_home', "`matrix_take_home` DECIMAL(18,2) NOT NULL DEFAULT 0");

    payroll_add_col($pdo, 'payroll_run_items', 'op_rate_day', "`op_rate_day` DECIMAL(18,2) NOT NULL DEFAULT 0");
    payroll_add_col($pdo, 'payroll_run_items', 'op_amount', "`op_amount` DECIMAL(18,2) NOT NULL DEFAULT 0");

    payroll_add_col($pdo, 'payroll_run_items', 'allowance_position', "`allowance_position` DECIMAL(18,2) NOT NULL DEFAULT 0");
    payroll_add_col($pdo, 'payroll_run_items', 'allowance_child', "`allowance_child` DECIMAL(18,2) NOT NULL DEFAULT 0");
    payroll_add_col($pdo, 'payroll_run_items', 'allowance_transport', "`allowance_transport` DECIMAL(18,2) NOT NULL DEFAULT 0");
    payroll_add_col($pdo, 'payroll_run_items', 'allowance_quota', "`allowance_quota` DECIMAL(18,2) NOT NULL DEFAULT 0");

    payroll_add_col($pdo, 'payroll_run_items', 'kasbon_deduction', "`kasbon_deduction` DECIMAL(18,2) NOT NULL DEFAULT 0");
    payroll_add_col($pdo, 'payroll_run_items', 'loan_deduction', "`loan_deduction` DECIMAL(18,2) NOT NULL DEFAULT 0");
    payroll_add_col($pdo, 'payroll_run_items', 'late_deduction', "`late_deduction` DECIMAL(18,2) NOT NULL DEFAULT 0");

    // master_employees: tambahkan kolom payroll_status & payroll_level (untuk mapping matrix)
    if (payroll_table_exists($pdo, 'master_employees')) {
        payroll_add_col($pdo, 'master_employees', 'payroll_status', "`payroll_status` VARCHAR(20) NULL");
        payroll_add_col($pdo, 'master_employees', 'payroll_level', "`payroll_level` VARCHAR(5) NULL");
    }

    // Ensure audit table exists
    erp_audit_ensure($pdo);
}

function payroll_flash_set(string $type, string $msg): void {
    $_SESSION['_payroll_flash'] = ['type'=>$type,'msg'=>$msg];
}
function payroll_flash_get(): ?array {
    $v = $_SESSION['_payroll_flash'] ?? null;
    unset($_SESSION['_payroll_flash']);
    return $v;
}

function payroll_badge_status(string $status): array {
    $s = strtoupper(trim($status));
    if ($s === 'POSTED') return ['success', 'POSTED'];
    if ($s === 'PAID') return ['info', 'PAID'];
    if ($s === 'CANCELLED') return ['danger', 'CANCELLED'];
    return ['warning', $s ?: 'DRAFT'];
}

function payroll_parse_month(string $ym): ?array {
    $ym = trim($ym);
    if (!preg_match('/^\d{4}\-\d{2}$/', $ym)) return null;
    [$y,$m] = array_map('intval', explode('-', $ym));
    if ($m < 1 || $m > 12) return null;
    $start = sprintf('%04d-%02d-01', $y, $m);
    $end = date('Y-m-t', strtotime($start));
    return [$start, $end];
}

function payroll_count_workdays(string $start, string $end): int {
    $dt = new DateTime($start);
    $endDt = new DateTime($end);
    $endDt->setTime(0,0,0);
    $count = 0;
    while ($dt <= $endDt) {
        $dow = (int)$dt->format('N'); // 1..7
        if ($dow <= 5) $count++;
        $dt->modify('+1 day');
    }
    return $count;
}

function payroll_dates_weekdays(string $start, string $end): array {
    $dt = new DateTime($start);
    $endDt = new DateTime($end);
    $endDt->setTime(0,0,0);
    $dates = [];
    while ($dt <= $endDt) {
        $dow = (int)$dt->format('N');
        if ($dow <= 5) $dates[] = $dt->format('Y-m-d');
        $dt->modify('+1 day');
    }
    return $dates;
}

function payroll_get_absensi_summary(PDO $pdo, int $login_user_id, string $start, string $end, ?string $office_code = null): array {
    $presentDates = [];
    $leaveDates = [];
    $izinDates = [];
    $sickDates = [];
    $sourceFound = 0;

    // Present from absensi_logs
    if (payroll_table_exists($pdo, 'absensi_logs')) {
        $sql = "SELECT DATE(created_at) d
                FROM absensi_logs
                WHERE user_id = ? AND action_type = 'IN'
                  AND created_at >= ? AND created_at <= ?";
        $params = [$login_user_id, $start . ' 00:00:00', $end . ' 23:59:59'];
        if ($office_code) {
            $sql .= " AND (office_code = ? OR office_code IS NULL OR office_code = '')";
            $params[] = $office_code;
        }
        $sql .= " GROUP BY DATE(created_at)";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $presentDates[$r['d']] = true;
            }
        } catch (Throwable $e) {}
    }

    // Leave / izin / sakit from absensi_requests (APPROVED)
    if (payroll_table_exists($pdo, 'absensi_requests')) {
        $sql = "SELECT start_date, end_date";
        $typeCol = '';
        foreach (['request_type','type','jenis','kategori','leave_type'] as $c) {
            if (payroll_column_exists($pdo, 'absensi_requests', $c)) { $typeCol = $c; break; }
        }
        if ($typeCol) $sql .= ", `{$typeCol}` AS req_type"; else $sql .= ", 'CUTI' AS req_type";
        $sql .= " FROM absensi_requests
                  WHERE user_id = ? AND status = 'APPROVED'
                    AND end_date >= ? AND start_date <= ?";
        $params = [$login_user_id, $start, $end];
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $s = max($start, $r['start_date']);
                $e = min($end, $r['end_date']);
                $typ = strtoupper((string)($r['req_type'] ?? 'CUTI'));
                foreach (payroll_dates_weekdays($s, $e) as $d) {
                    if (strpos($typ, 'SAKIT') !== false || strpos($typ, 'SICK') !== false) {
                        $sickDates[$d] = true;
                    } elseif (strpos($typ, 'IZIN') !== false || strpos($typ, 'PERMISSION') !== false) {
                        $izinDates[$d] = true;
                    } else {
                        $leaveDates[$d] = true;
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    // Remove approved day types that are already present (hadir menang)
    foreach ($presentDates as $d => $_) {
        unset($leaveDates[$d], $izinDates[$d], $sickDates[$d]);
    }

    $late = 0;
    if (function_exists('payroll_calc_late_deduction')) {
        $lateCalc = payroll_calc_late_deduction($pdo, $login_user_id, $start, $end, $office_code);
        $late = (int)($lateCalc['late_count'] ?? 0);
    }

    if (count($presentDates) + count($leaveDates) + count($izinDates) + count($sickDates) + $late > 0) {
        $sourceFound = 1;
    }

    return [
        'present' => count($presentDates),
        'leave'   => count($leaveDates),
        'izin'    => count($izinDates),
        'sick'    => count($sickDates),
        'late'    => $late,
        'source_found' => $sourceFound,
    ];
}

function payroll_late_penalty_rules(PDO $pdo): array {
    $defaults = [
        ['min'=>5,  'max'=>19,   'amount'=>5000.0],
        ['min'=>20, 'max'=>29,   'amount'=>10000.0],
        ['min'=>30, 'max'=>9999, 'amount'=>20000.0],
    ];
    if (!payroll_table_exists($pdo, 'absensi_late_penalty_rules')) return $defaults;
    try {
        $st = $pdo->query("SELECT minute_from AS min, minute_to AS max, penalty_amount AS amount
                           FROM absensi_late_penalty_rules
                           WHERE is_active=1
                           ORDER BY minute_from ASC");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!$rows) return $defaults;
        return array_map(function($r){
            return [
                'min' => (int)($r['min'] ?? 0),
                'max' => (int)($r['max'] ?? 9999),
                'amount' => (float)($r['amount'] ?? 0),
            ];
        }, $rows);
    } catch (Throwable $e) { return $defaults; }
}

function payroll_late_minutes(?string $checkinTime, string $effectiveStart): int {
    $checkinTime = trim((string)$checkinTime);
    $effectiveStart = trim((string)$effectiveStart);
    if ($checkinTime === '' || $effectiveStart === '') return 0;
    if (strlen($checkinTime) === 5) $checkinTime .= ':00';
    if (strlen($effectiveStart) === 5) $effectiveStart .= ':00';
    $a = strtotime('2000-01-01 ' . $checkinTime);
    $b = strtotime('2000-01-01 ' . $effectiveStart);
    if ($a === false || $b === false || $a <= $b) return 0;
    return (int)ceil(($a - $b) / 60);
}

function payroll_late_penalty_amount(int $lateMin, array $rules): float {
    foreach ($rules as $r) {
        if ($lateMin >= (int)$r['min'] && $lateMin <= (int)$r['max']) return (float)$r['amount'];
    }
    return 0.0;
}

function payroll_absensi_effective_late_time(PDO $pdo): string {
    // Default mengikuti rekap absensi saat ini: jam masuk 07:00 + toleransi 5 menit = 07:05.
    $std = '07:00';
    $tol = 5;
    if (payroll_table_exists($pdo, 'absensi_settings')) {
        try {
            // Format umum: setting_key/setting_value atau key/value.
            $keyCol = payroll_column_exists($pdo, 'absensi_settings', 'setting_key') ? 'setting_key' : (payroll_column_exists($pdo, 'absensi_settings', 'key') ? 'key' : '');
            $valCol = payroll_column_exists($pdo, 'absensi_settings', 'setting_value') ? 'setting_value' : (payroll_column_exists($pdo, 'absensi_settings', 'value') ? 'value' : '');
            if ($keyCol && $valCol) {
                $st = $pdo->query("SELECT `{$keyCol}` k, `{$valCol}` v FROM absensi_settings");
                foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                    $k = strtolower((string)$r['k']);
                    $v = trim((string)$r['v']);
                    if (in_array($k, ['checkin_std_time','std_time','jam_masuk','start_time'], true) && preg_match('/^\d{2}:\d{2}/', $v)) $std = substr($v, 0, 5);
                    if (in_array($k, ['late_tolerance_min','tolerance_min','toleransi_telat'], true)) $tol = max(0, (int)$v);
                }
            }
        } catch (Throwable $e) {}
    }
    $ts = strtotime('2000-01-01 ' . $std) + ($tol * 60);
    return date('H:i', $ts);
}

function payroll_calc_late_deduction(PDO $pdo, int $login_user_id, string $start, string $end, ?string $office_code = null): array {
    $out = ['late_count'=>0, 'late_deduction'=>0.0, 'late_minutes_total'=>0];
    if ($login_user_id <= 0 || !payroll_table_exists($pdo, 'absensi_logs')) return $out;

    $rules = payroll_late_penalty_rules($pdo);
    $effectiveTime = payroll_absensi_effective_late_time($pdo);

    $sql = "SELECT DATE(created_at) d, MIN(TIME(created_at)) t
            FROM absensi_logs
            WHERE user_id = ? AND action_type = 'IN'
              AND created_at >= ? AND created_at <= ?";
    $params = [$login_user_id, $start . ' 00:00:00', $end . ' 23:59:59'];
    if ($office_code) {
        $sql .= " AND (office_code = ? OR office_code IS NULL OR office_code = '')";
        $params[] = $office_code;
    }
    $sql .= " GROUP BY DATE(created_at)";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $min = payroll_late_minutes((string)($r['t'] ?? ''), $effectiveTime);
            if ($min <= 0) continue;
            $amt = payroll_late_penalty_amount($min, $rules);
            if ($amt <= 0) continue;
            $out['late_count']++;
            $out['late_minutes_total'] += $min;
            $out['late_deduction'] += $amt;
        }
    } catch (Throwable $e) {}

    $out['late_deduction'] = round((float)$out['late_deduction'], 2);
    return $out;
}

// ---- Salary Matrix helpers ----
function payroll_norm_status(?string $s): string {
    $s = strtoupper(trim((string)$s));
    if ($s === '') return '';
    // alias
    if ($s === 'CONTRACT') $s = 'KONTRAK';
    if ($s === 'KONTRAK' || $s === 'PROBATION') return $s;
    return $s;
}
function payroll_norm_level(?string $s): string {
    return strtoupper(trim((string)$s));
}

function payroll_norm_job(?string $s): string {
    $s = strtoupper(trim((string)$s));
    if ($s === '') return '';
    $s = preg_replace('/[^A-Z0-9]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = trim($s);
    if (preg_match('/\b(MANAGER|MGR|KEPALA|HEAD|LEADER|SUPERVISOR|SPV)\b/', $s)) return 'MGR';
    if (preg_match('/\b(STAFF|STAF|HELPER|ADMIN|DRIVER|OB|SECURITY|SALES)\b/', $s)) return 'STAFF';
    return $s;
}

function payroll_matrix_key(string $status, string $level): string {
    return payroll_norm_status($status) . '|' . payroll_norm_level($level);
}

function payroll_matrix_key_job(string $status, string $level, string $job): string {
    return payroll_matrix_key($status, $level) . '|' . payroll_norm_job($job);
}

function payroll_load_salary_matrix(PDO $pdo, int $year): array {
    $out = ['__rows' => []];
    if (!payroll_table_exists($pdo, 'payroll_salary_matrix')) return $out;

    // Ambil semua baris matrix. Jangan hanya simpan key status+level karena
    // satu level bisa punya beberapa job (contoh STAFF dan MGR).
    $st = $pdo->prepare("SELECT * FROM payroll_salary_matrix WHERE matrix_year = ? ORDER BY payroll_status, payroll_level, job_title, id");
    $st->execute([$year]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $out['__rows'][] = $r;

        $status = (string)($r['payroll_status'] ?? '');
        $level  = (string)($r['payroll_level'] ?? '');
        $job    = (string)($r['job_title'] ?? '');

        $kJob = payroll_matrix_key_job($status, $level, $job);
        if ($job !== '' && !isset($out[$kJob])) $out[$kJob] = $r;

        // Backward compatible untuk kode lama: key status+level tetap ada.
        // Pilih baris dengan OP/day > 0 terlebih dulu, jangan overwrite sembarangan.
        $k = payroll_matrix_key($status, $level);
        if (!isset($out[$k]) || ((float)($out[$k]['op_rate_day'] ?? 0) <= 0 && (float)($r['op_rate_day'] ?? 0) > 0)) {
            $out[$k] = $r;
        }
    }
    return $out;
}

function payroll_matrix_rows(array $matrixMap): array {
    if (isset($matrixMap['__rows']) && is_array($matrixMap['__rows'])) {
        return array_values(array_filter($matrixMap['__rows'], 'is_array'));
    }
    $rows = [];
    foreach ($matrixMap as $k => $r) {
        if ($k === '__rows' || !is_array($r)) continue;
        $rows[] = $r;
    }
    return $rows;
}

function payroll_matrix_job_score(string $empJob, string $matrixJob): int {
    $a = payroll_norm_job($empJob);
    $b = payroll_norm_job($matrixJob);
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 60;
    if (strpos($a, $b) !== false || strpos($b, $a) !== false) return 30;
    return 0;
}

function payroll_find_salary_matrix(array $matrixMap, string $status, string $level, string $jobTitle = '', float $salaryBasic = 0.0): ?array {
    $statusN = payroll_norm_status($status);
    $levelN  = payroll_norm_level($level);
    $jobN    = payroll_norm_job($jobTitle);

    // 1) Exact status + level + job.
    if ($statusN !== '' && $levelN !== '' && $jobN !== '') {
        $kJob = payroll_matrix_key_job($statusN, $levelN, $jobN);
        if (isset($matrixMap[$kJob]) && is_array($matrixMap[$kJob])) return $matrixMap[$kJob];
    }

    // 2) Scoring semua row supaya bisa fallback dari salary_basic jika status/level master employee kosong.
    $best = null;
    $bestScore = -999999;
    foreach (payroll_matrix_rows($matrixMap) as $r) {
        $rowStatus = payroll_norm_status($r['payroll_status'] ?? '');
        $rowLevel  = payroll_norm_level($r['payroll_level'] ?? '');
        $rowJob    = (string)($r['job_title'] ?? '');
        $rowBasic  = (float)($r['basic_salary'] ?? 0);

        $score = 0;
        if ($statusN !== '') $score += ($rowStatus === $statusN ? 80 : -40);
        if ($levelN  !== '') $score += ($rowLevel === $levelN ? 80 : -40);
        $score += payroll_matrix_job_score($jobN, $rowJob);

        if ($salaryBasic > 0 && $rowBasic > 0) {
            $diff = abs($rowBasic - $salaryBasic);
            if ($diff <= 1) $score += 160;
            elseif ($diff <= 1000) $score += 80;
            else $score -= 60;
        }

        if ((float)($r['op_rate_day'] ?? 0) > 0) $score += 10;
        if ((float)($r['tunj_jabatan'] ?? 0) > 0) $score += 3;

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }

    // Ambil hanya jika cukup yakin: ada status+level match, atau ada salary_basic match.
    if ($best && $bestScore >= 80) return $best;

    // 3) Backward compatible exact status+level terakhir.
    if ($statusN !== '' && $levelN !== '') {
        $k = payroll_matrix_key($statusN, $levelN);
        if (isset($matrixMap[$k]) && is_array($matrixMap[$k])) return $matrixMap[$k];
    }

    return null;
}

// ---- Loans helpers ----
function payroll_ym_to_index(string $ym): int {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return 0;
    $y = (int)$m[1];
    $mo = (int)$m[2];
    return $y * 12 + $mo;
}

function payroll_ym_add_months(string $ym, int $delta): string {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return $ym;
    $y = (int)$m[1];
    $mo = (int)$m[2];
    $idx = $y * 12 + $mo + $delta;
    $newY = intdiv($idx - 1, 12);
    $newM = ($idx - 1) % 12 + 1;
    return sprintf('%04d-%02d', $newY, $newM);
}

function payroll_calc_loan_deductions(PDO $pdo, int $employeeId, string $periodYm): array {
    $cur = payroll_ym_to_index($periodYm);
    $kasbon = 0.0;
    $loan = 0.0;

    if (!payroll_table_exists($pdo, 'payroll_loans')) {
        return ['kasbon'=>0.0, 'loan'=>0.0];
    }

    $st = $pdo->prepare("SELECT loan_type, principal, tenor_months, installment_amount, start_period_ym, status
                         FROM payroll_loans
                         WHERE employee_id = ? AND status = 'ACTIVE'");
    $st->execute([$employeeId]);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $startYm = (string)$r['start_period_ym'];
        $start = payroll_ym_to_index($startYm);
        $tenor = max(1, (int)$r['tenor_months']);
        $end = $start + $tenor - 1;
        if ($cur < $start || $cur > $end) continue;

        $install = (float)$r['installment_amount'];
        if ($install <= 0) {
            $install = ((float)$r['principal']) / $tenor;
        }

        $type = strtoupper(trim((string)$r['loan_type']));
        if (in_array($type, ['KASBON','KASB0N','ADVANCE'], true)) {
            $kasbon += $install;
        } else {
            $loan += $install;
        }
    }

    return ['kasbon'=>round($kasbon,2), 'loan'=>round($loan,2)];
}

function payroll_recalc_amounts(array $row): array {
    $payType = strtoupper($row['pay_type'] ?? 'MONTHLY');

    $salaryBasic = (float)($row['salary_basic'] ?? 0);

    $opRateDay = (float)($row['op_rate_day'] ?? 0);

    $allowPos   = (float)($row['allowance_position'] ?? 0);
    $allowChild = (float)($row['allowance_child'] ?? 0);
    $allowTrans = (float)($row['allowance_transport'] ?? 0);
    $allowQuota = (float)($row['allowance_quota'] ?? 0);

    $allowFixed  = (float)($row['allowance_fixed'] ?? 0);
    $dedFixed    = (float)($row['deduction_fixed'] ?? 0);

    $workDays  = (int)($row['work_days'] ?? 0);
    $present   = (int)($row['days_present'] ?? 0);
    $absent    = (int)($row['absent_days'] ?? 0);

    $otRate    = (float)($row['overtime_rate_per_hour'] ?? 0);
    $otHours   = (float)($row['overtime_hours'] ?? 0);

    $otherAllow= (float)($row['other_allowance'] ?? 0);
    $otherDed  = (float)($row['other_deduction'] ?? 0);

    $kasbonDed = (float)($row['kasbon_deduction'] ?? 0);
    $loanDed   = (float)($row['loan_deduction'] ?? 0);
    $lateDed   = (float)($row['late_deduction'] ?? 0);

    $tax       = (float)($row['tax_pph21'] ?? 0);
    $bpjsTk    = (float)($row['bpjs_tk'] ?? 0);
    $bpjsKes   = (float)($row['bpjs_kes'] ?? 0);

    // cap present to workDays if set
    if ($workDays > 0 && $present > $workDays) $present = $workDays;

    $baseAmount = 0.0;
    $absenceDed = 0.0;

    if ($payType === 'DAILY') {
        $baseAmount = $salaryBasic * max(0, $present);
        $absenceDed = 0.0;
    } else { // MONTHLY
        $baseAmount = $salaryBasic;
        if ($workDays > 0) {
            $absenceDed = ($salaryBasic / $workDays) * max(0, $absent);
        }
    }

    // OP (harian) -> dibayar per hari hadir
    $opAmount = $opRateDay * max(0, $present);

    $otAmount = $otRate * max(0, $otHours);

    $gross = $baseAmount
      + $opAmount
      + $allowPos + $allowChild + $allowTrans + $allowQuota
      + $allowFixed
      + $otherAllow
      + $otAmount;

    $totalDed = $dedFixed + $otherDed + $kasbonDed + $loanDed + $lateDed + $absenceDed + $tax + $bpjsTk + $bpjsKes;
    $net = $gross - $totalDed;

    $round = fn($n) => round((float)$n, 2);

    return [
        'base_amount'       => $round($baseAmount),
        'op_amount'         => $round($opAmount),
        'absence_deduction' => $round($absenceDed),
        'overtime_amount'   => $round($otAmount),
        'gross_pay'         => $round($gross),
        'total_deduction'   => $round($totalDed),
        'net_pay'           => $round($net),
    ];
}

payroll_ensure_schema($pdo);
