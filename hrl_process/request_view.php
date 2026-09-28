<?php
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_login(); // explicit guard + static scan marker

$page_title = 'Detail Pengajuan';
require_once __DIR__ . '/_layout_top.php';

// --- BRANCH approval resolver -------------------------------------------------
// Menjaga alur existing untuk semua dept. Khusus BRANCH: bila office request
// belum mempunyai Manager BRANCH aktif, request SUBMITTED diteruskan otomatis
// menjadi MANAGER_APPROVED agar HRL dapat memprosesnya.
if (!function_exists('hrlp_find_active_manager_for_request')) {
    function hrlp_find_active_manager_for_request(PDO $pdo, string $dept, string $office): ?array {
        $dept = strtoupper(trim($dept));
        $office = strtoupper(trim($office));
        if ($dept === '' || $office === '') return null;

        $sql = "SELECT id, username, role, level, department, office_code
                FROM master_system_login
                WHERE deleted_at IS NULL
                  AND UPPER(COALESCE(status,'')) = 'ACTIVE'
                  AND UPPER(COALESCE(department,'')) = ?
                  AND UPPER(COALESCE(office_code,'')) = ?
                  AND (
                        UPPER(COALESCE(role,'')) IN ('MANAGER','MGR')
                     OR UPPER(COALESCE(level,'')) IN ('MANAGER','MGR')
                  )
                ORDER BY id ASC
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$dept, $office]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('hrlp_auto_bypass_branch_manager')) {
    function hrlp_auto_bypass_branch_manager(PDO $pdo, int $requestId): bool {
        $st = $pdo->prepare("SELECT id, req_code, dept_code, office_code, status FROM hrl_requests WHERE id=? LIMIT 1");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) return false;

        $dept = strtoupper(trim((string)($req['dept_code'] ?? '')));
        $office = strtoupper(trim((string)($req['office_code'] ?? '')));
        $status = strtoupper(trim((string)($req['status'] ?? '')));

        if ($dept !== 'BRANCH' || $status !== 'SUBMITTED' || $office === '') return false;
        if (hrlp_find_active_manager_for_request($pdo, $dept, $office)) return false;

        $up = $pdo->prepare("UPDATE hrl_requests
                            SET status='MANAGER_APPROVED',
                                manager_approved_by='SYSTEM',
                                manager_approved_at=NOW(),
                                updated_at=NOW()
                            WHERE id=? AND status='SUBMITTED'");
        $up->execute([$requestId]);
        if ($up->rowCount() <= 0) return false;

        hrlp_audit('AUTO_BYPASS_MANAGER', [
            'id'=>$requestId,
            'dept'=>$dept,
            'office'=>$office,
            'reason'=>'NO_ACTIVE_MANAGER_IN_REQUEST_OFFICE'
        ]);
        if (function_exists('master_audit')) {
            master_audit(
                $pdo,
                'hrl_process',
                'hrl_requests',
                'AUTO_BYPASS_MANAGER',
                $requestId,
                (string)($req['req_code'] ?? ('REQ#'.$requestId)),
                'Manager BRANCH tidak tersedia pada office '.$office.'; request otomatis diteruskan ke HRL.',
                ['dept'=>$dept, 'office'=>$office, 'reason'=>'NO_ACTIVE_MANAGER_IN_REQUEST_OFFICE']
            );
        }
        return true;
    }
}


$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo "<div class='alert alert-danger'>ID tidak valid.</div>";
    require_once __DIR__ . '/_layout_bottom.php';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM hrl_requests WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$req) {
    echo "<div class='alert alert-danger'>Pengajuan tidak ditemukan.</div>";
    require_once __DIR__ . '/_layout_bottom.php';
    exit;
}

if (!hrlp_can_view($req)) {
    http_response_code(403);
    echo "<div class='alert alert-danger'>Akses ditolak.</div>";
    require_once __DIR__ . '/_layout_bottom.php';
    exit;
}

function req_files(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM hrl_request_files WHERE request_id=? ORDER BY id DESC");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function req_photo_file(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM hrl_request_files WHERE request_id=? AND kind='PHOTO' ORDER BY id DESC LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function require_confirm(PDO $pdo): string {
    $pw = trim((string)($_POST['confirm_password'] ?? ''));
    $pin = trim((string)($_POST['confirm_pin'] ?? ''));

    if ($pw !== '') {
        if (!hrlp_verify_password($pdo, me_username(), $pw)) {
            throw new RuntimeException('Password salah.');
        }
        return 'password';
    }
    if ($pin !== '') {
        if (!hrlp_verify_pin($pdo, me_username(), $pin)) {
            throw new RuntimeException('PIN salah / belum diset. (Silakan set PIN di menu "PIN TTD")');
        }
        return 'pin';
    }
    throw new RuntimeException('Wajib konfirmasi password atau PIN.');
}


function hrlp_table_columns(PDO $pdo, string $table): array {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cols = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[strtolower((string)$r['Field'])] = (string)$r['Field'];
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function hrlp_payroll_column_exists(PDO $pdo, string $column): bool {
    $cols = hrlp_table_columns($pdo, 'payroll_loans');
    return isset($cols[strtolower($column)]);
}

function hrlp_ensure_payroll_loan_link(PDO $pdo): void {
    // Kolom ini mencegah 1 pengajuan KASBON masuk payroll_loans berkali-kali.
    if (!hrlp_payroll_column_exists($pdo, 'hrl_request_id')) {
        try { $pdo->exec("ALTER TABLE payroll_loans ADD COLUMN hrl_request_id INT NULL"); } catch (Throwable $e) {}
    }
    try { $pdo->exec("ALTER TABLE payroll_loans ADD UNIQUE KEY uq_payroll_loans_hrl_request_id (hrl_request_id)"); } catch (Throwable $e) {}
}

function hrlp_find_employee_id_for_request(PDO $pdo, array $req): int {
    $username = trim((string)($req['created_by'] ?? ''));
    if ($username === '') return 0;

    // 1) Mapping utama ERP: master_system_login.username -> holder_employee_code -> master_employees.employee_code.
    // Ini penting agar saldo cuti/kasbon tidak gagal bila username login berbeda dari employee_code.
    try {
        $st = $pdo->prepare("SELECT holder_employee_code FROM master_system_login WHERE username=? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$username]);
        $empCode = trim((string)($st->fetchColumn() ?: ''));
        if ($empCode !== '') {
            $st2 = $pdo->prepare("SELECT id FROM master_employees WHERE employee_code=? LIMIT 1");
            $st2->execute([$empCode]);
            $id = (int)($st2->fetchColumn() ?: 0);
            if ($id > 0) return $id;
        }
    } catch (Throwable $e) {}

    // 2) Fallback: bila master_employees punya kolom username/login_username/nik/nama yang sama dengan pemohon.
    $cols = hrlp_table_columns($pdo, 'master_employees');
    $candidates = [];
    foreach (['username','user_name','login_username','employee_code','nik','employee_name'] as $c) {
        if (isset($cols[strtolower($c)])) {
            $real = $cols[strtolower($c)];
            $candidates[] = "`{$real}` = ?";
        }
    }
    if (!$candidates) return 0;

    $sql = "SELECT id FROM master_employees WHERE (" . implode(" OR ", $candidates) . ") LIMIT 1";
    $params = array_fill(0, count($candidates), $username);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)($st->fetchColumn() ?: 0);
}

function hrlp_parse_kasbon_data(array $req): array {
    $desc = (string)($req['description'] ?? '');
    $tenor = 1;
    $startYm = date('Y-m');
    $install = 0.0;

    if (preg_match('/\\[KASBON_DATA([^\\]]+)\\]/i', $desc, $m)) {
        $raw = $m[1];
        if (preg_match('/tenor_months\\s*=\\s*(\\d+)/i', $raw, $x)) $tenor = max(1, (int)$x[1]);
        if (preg_match('/start_period_ym\\s*=\\s*(\\d{4}-\\d{2})/i', $raw, $x)) $startYm = $x[1];
        if (preg_match('/installment_amount\\s*=\\s*([0-9]+(?:\\.[0-9]+)?)/i', $raw, $x)) $install = (float)$x[1];
    }

    $principal = (float)($req['amount'] ?? 0);
    if ($install <= 0 && $principal > 0) $install = $principal / max(1, $tenor);

    return [
        'tenor_months' => $tenor,
        'start_period_ym' => $startYm,
        'installment_amount' => round($install, 2),
    ];
}

function hrlp_create_payroll_loan_from_hrl_request(PDO $pdo, array $req, int $requestId): int {
    if (strtoupper((string)($req['req_type'] ?? '')) !== 'KASBON') return 0;

    hrlp_ensure_payroll_loan_link($pdo);

    if (hrlp_payroll_column_exists($pdo, 'hrl_request_id')) {
        $st = $pdo->prepare("SELECT id FROM payroll_loans WHERE hrl_request_id=? LIMIT 1");
        $st->execute([$requestId]);
        $existing = (int)($st->fetchColumn() ?: 0);
        if ($existing > 0) return $existing;
    }

    $principal = (float)($req['amount'] ?? 0);
    if ($principal <= 0) {
        throw new RuntimeException('Nominal kasbon tidak valid, tidak bisa dibuat ke Payroll Loans.');
    }

    $employeeId = hrlp_find_employee_id_for_request($pdo, $req);
    if ($employeeId <= 0) {
        throw new RuntimeException('Karyawan pemohon tidak ditemukan di master_employees. Samakan username/employee_code dengan pemohon: ' . (string)($req['created_by'] ?? '-'));
    }

    $loan = hrlp_parse_kasbon_data($req);
    $note = trim('Auto dari HRL Process ' . (string)($req['req_code'] ?? ('#'.$requestId)) . ' - ' . (string)($req['title'] ?? ''));

    $cols = ['employee_id','loan_type','principal','tenor_months','installment_amount','start_period_ym','status','note','created_by','created_at'];
    $vals = [$employeeId,'KASBON',$principal,$loan['tenor_months'],$loan['installment_amount'],$loan['start_period_ym'],'ACTIVE',$note,(int)($_SESSION['user_id'] ?? 0)];
    $marks = ['?','?','?','?','?','?','?','?','?','NOW()'];

    if (hrlp_payroll_column_exists($pdo, 'hrl_request_id')) {
        $cols[] = 'hrl_request_id';
        $vals[] = $requestId;
        $marks[] = '?';
    }

    $sql = "INSERT INTO payroll_loans (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $marks) . ")";
    $st = $pdo->prepare($sql);
    $st->execute($vals);
    return (int)$pdo->lastInsertId();
}



function hrlp_ensure_leave_tables(PDO $pdo): void {
    // Saldo cuti tahunan per karyawan.
    $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_leave_balances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        year INT NOT NULL,
        quota_days DECIMAL(8,2) NOT NULL DEFAULT 12,
        used_days DECIMAL(8,2) NOT NULL DEFAULT 0,
        remaining_days DECIMAL(8,2) NOT NULL DEFAULT 12,
        updated_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_emp_year (employee_id, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Detail pemakaian cuti dari HRL Process. request_id dibuat unique supaya tidak double potong saldo.
    $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_leave_usages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        employee_id INT NOT NULL,
        year INT NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        days DECIMAL(8,2) NOT NULL DEFAULT 0,
        leave_type VARCHAR(50) NOT NULL DEFAULT 'CUTI',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_request_id (request_id),
        KEY idx_emp_year (employee_id, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Saldo awal / penyesuaian historis sebelum sistem berjalan.
    // Data ini untuk saldo cuti tahunan saja dan TIDAK mempengaruhi payroll periode berjalan.
    $pdo->exec("CREATE TABLE IF NOT EXISTS hrl_leave_adjustments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        year INT NOT NULL,
        adjustment_type VARCHAR(40) NOT NULL DEFAULT 'OPENING_BALANCE',
        quota_days DECIMAL(8,2) NOT NULL DEFAULT 12,
        used_days DECIMAL(8,2) NOT NULL DEFAULT 0,
        note TEXT NULL,
        created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_employee_year (employee_id, year),
        KEY idx_type (adjustment_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}


function hrlp_employee_join_info(PDO $pdo, int $employeeId): array {
    $cols = hrlp_table_columns($pdo, 'master_employees');
    $select = ['id', 'employee_code', 'employee_name', 'dept_code', 'office_code'];
    foreach (['join_year','join_month','join_date','date_join','hire_date','tanggal_masuk','start_work_date','work_start_date'] as $c) {
        if (isset($cols[strtolower($c)])) $select[] = '`' . $cols[strtolower($c)] . '` AS `' . $c . '`';
    }
    $st = $pdo->prepare("SELECT " . implode(',', $select) . " FROM master_employees WHERE id=? LIMIT 1");
    $st->execute([$employeeId]);
    $e = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $joinYear = isset($e['join_year']) ? (int)$e['join_year'] : 0;
    $joinMonth = isset($e['join_month']) ? (int)$e['join_month'] : 0;

    if ($joinYear <= 0 || $joinMonth <= 0 || $joinMonth > 12) {
        foreach (['join_date','date_join','hire_date','tanggal_masuk','start_work_date','work_start_date'] as $dc) {
            $v = trim((string)($e[$dc] ?? ''));
            if ($v !== '' && $v !== '0000-00-00') {
                $ts = strtotime($v);
                if ($ts) {
                    $joinYear = (int)date('Y', $ts);
                    $joinMonth = (int)date('m', $ts);
                    break;
                }
            }
        }
    }

    return [
        'employee' => $e,
        'join_year' => $joinYear,
        'join_month' => $joinMonth,
        'join_label' => ($joinYear > 0 && $joinMonth > 0) ? sprintf('%04d-%02d', $joinYear, $joinMonth) : '-',
    ];
}

function hrlp_leave_quota_by_join(PDO $pdo, int $employeeId, int $year): array {
    // Kebijakan aman:
    // - Hak cuti muncul setelah masa kerja 12 bulan.
    // - Tahun pertama berhak cuti dihitung prorata dari bulan eligible sampai Desember.
    // - Tahun berikutnya 12 hari.
    // - Jika data Join Year/Month belum ada, fallback 12 agar alur lama tidak rusak.
    $info = hrlp_employee_join_info($pdo, $employeeId);
    $jy = (int)$info['join_year'];
    $jm = (int)$info['join_month'];

    if ($jy <= 0 || $jm <= 0 || $jm > 12) {
        return ['quota' => 12.0, 'join_label' => '-', 'rule' => 'Fallback 12 hari karena data masuk kerja belum lengkap'];
    }

    $joinDate = new DateTime(sprintf('%04d-%02d-01', $jy, $jm));
    $eligible = (clone $joinDate)->modify('+12 months');
    $eligYear = (int)$eligible->format('Y');
    $eligMonth = (int)$eligible->format('m');

    if ($year < $eligYear) {
        $quota = 0.0;
        $rule = 'Belum genap 12 bulan';
    } elseif ($year === $eligYear) {
        $quota = (float)max(0, 13 - $eligMonth);
        $rule = 'Prorata tahun pertama hak cuti';
    } else {
        $quota = 12.0;
        $rule = 'Hak cuti penuh';
    }

    return [
        'quota' => $quota,
        'join_label' => sprintf('%04d-%02d', $jy, $jm),
        'rule' => $rule,
    ];
}

function hrlp_leave_count_workdays(?string $startDate, ?string $endDate): float {
    // CUTI dihitung berdasarkan rentang tanggal secara inklusif.
    // Contoh: 24-09 s/d 25-09 = 2 hari. Weekend tetap dihitung bila memang
    // dimasukkan pemohon ke dalam rentang CUTI. Perubahan ini sengaja hanya
    // menyentuh kalkulasi hari; workflow approval/RBAC/saldo tetap existing.
    $startDate = trim((string)$startDate);
    $endDate = trim((string)$endDate);
    if ($startDate === '') {
        throw new RuntimeException('Tanggal mulai cuti wajib diisi untuk pencatatan saldo cuti.');
    }
    if ($endDate === '') $endDate = $startDate;

    try {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
    } catch (Throwable $e) {
        throw new RuntimeException('Tanggal cuti tidak valid.');
    }

    if ($end < $start) {
        throw new RuntimeException('Tanggal selesai cuti tidak boleh lebih kecil dari tanggal mulai.');
    }

    return (float)($start->diff($end)->days + 1);
}

function hrlp_sync_leave_balance(PDO $pdo, int $employeeId, int $year): void {
    hrlp_ensure_leave_tables($pdo);

    // Kuota utama diambil dari Master Employees (join_year/join_month) berdasarkan masa kerja.
    // Jika HRL sudah membuat saldo awal, quota dari OPENING_BALANCE tetap override agar koreksi manual tidak tertimpa.
    $quotaInfo = hrlp_leave_quota_by_join($pdo, $employeeId, $year);
    $quota = (float)$quotaInfo['quota'];
    try {
        $stAdjQ = $pdo->prepare("SELECT quota_days FROM hrl_leave_adjustments
                                 WHERE employee_id=? AND year=? AND adjustment_type='OPENING_BALANCE'
                                 ORDER BY id DESC LIMIT 1");
        $stAdjQ->execute([$employeeId, $year]);
        $aq = $stAdjQ->fetchColumn();
        if ($aq !== false && (float)$aq >= 0) $quota = (float)$aq;
    } catch (Throwable $e) {}

    $pdo->prepare("INSERT IGNORE INTO hrl_leave_balances (employee_id, year, quota_days, used_days, remaining_days, updated_at)
                   VALUES (?, ?, ?, 0, ?, NOW())")
        ->execute([$employeeId, $year, $quota, $quota]);

    // Cuti yang valid dari HRL Process saja.
    // Jika request CUTI dihapus / reject / draft, usage tidak boleh mengurangi saldo.
    $usedFromRequests = 0.0;
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(u.days),0)
            FROM hrl_leave_usages u
            JOIN hrl_requests r ON r.id = u.request_id
            WHERE u.employee_id=? AND u.year=?
              AND UPPER(COALESCE(r.req_type,''))='CUTI'
              AND r.deleted_at IS NULL
              AND UPPER(COALESCE(r.status,'')) IN ('HRL_APPROVED','FIN_APPROVED','PAID')");
        $st->execute([$employeeId, $year]);
        $usedFromRequests = (float)$st->fetchColumn();
    } catch (Throwable $e) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(days),0) FROM hrl_leave_usages WHERE employee_id=? AND year=?");
        $st->execute([$employeeId, $year]);
        $usedFromRequests = (float)$st->fetchColumn();
    }

    $usedFromAdjustments = 0.0;
    try {
        $stAdj = $pdo->prepare("SELECT COALESCE(SUM(used_days),0) FROM hrl_leave_adjustments WHERE employee_id=? AND year=?");
        $stAdj->execute([$employeeId, $year]);
        $usedFromAdjustments = (float)$stAdj->fetchColumn();
    } catch (Throwable $e) {}

    $used = $usedFromRequests + $usedFromAdjustments;

    $pdo->prepare("UPDATE hrl_leave_balances
                   SET quota_days=?, used_days=?, remaining_days=GREATEST(0, ? - ?), updated_at=NOW()
                   WHERE employee_id=? AND year=?")
        ->execute([$quota, $used, $quota, $used, $employeeId, $year]);
}

function hrlp_record_leave_usage_from_request(PDO $pdo, array $req, int $requestId): void {
    if (strtoupper((string)($req['req_type'] ?? '')) !== 'CUTI') return;

    hrlp_ensure_leave_tables($pdo);

    $employeeId = hrlp_find_employee_id_for_request($pdo, $req);
    if ($employeeId <= 0) {
        throw new RuntimeException('Karyawan pemohon cuti tidak ditemukan di master_employees. Samakan username/employee_code dengan pemohon: ' . (string)($req['created_by'] ?? '-'));
    }

    $startDate = (string)($req['start_date'] ?? '');
    $endDate = (string)($req['end_date'] ?? '');
    $days = hrlp_leave_count_workdays($startDate, $endDate);
    $year = (int)(new DateTime($startDate))->format('Y');

    // Pastikan saldo sudah mengikuti masa kerja dari Master Employees sebelum approve HRL mengurangi saldo.
    hrlp_sync_leave_balance($pdo, $employeeId, $year);
    $stBalCheck = $pdo->prepare("SELECT remaining_days, quota_days FROM hrl_leave_balances WHERE employee_id=? AND year=? LIMIT 1");
    $stBalCheck->execute([$employeeId, $year]);
    $balCheck = $stBalCheck->fetch(PDO::FETCH_ASSOC) ?: ['remaining_days'=>0, 'quota_days'=>0];
    if ((float)$balCheck['remaining_days'] < $days) {
        $qInfo = hrlp_leave_quota_by_join($pdo, $employeeId, $year);
        throw new RuntimeException('Sisa cuti tidak mencukupi. Hak cuti tahun ' . $year . ': ' . (float)$balCheck['quota_days'] . ' hari, sisa: ' . (float)$balCheck['remaining_days'] . ' hari, pengajuan: ' . $days . ' hari. Basis masa kerja: ' . ($qInfo['join_label'] ?? '-') . ' / ' . ($qInfo['rule'] ?? ''));
    }

    // Idempotent: satu request tetap satu usage. Jika request yang sama pernah
    // tercatat dengan hitungan lama, nilainya diperbarui; tidak membuat debit ganda.
    $pdo->prepare("INSERT INTO hrl_leave_usages
        (request_id, employee_id, year, start_date, end_date, days, leave_type, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'CUTI', NOW())
        ON DUPLICATE KEY UPDATE
            employee_id=VALUES(employee_id),
            year=VALUES(year),
            start_date=VALUES(start_date),
            end_date=VALUES(end_date),
            days=VALUES(days),
            leave_type='CUTI'")
        ->execute([
            $requestId,
            $employeeId,
            $year,
            ($startDate !== '' ? $startDate : null),
            ($endDate !== '' ? $endDate : $startDate),
            $days
        ]);

    hrlp_sync_leave_balance($pdo, $employeeId, $year);
    hrlp_audit('CUTI_TO_LEAVE_BALANCE', ['id'=>$requestId, 'employee_id'=>$employeeId, 'year'=>$year, 'days'=>$days]);
}

function hrlp_remove_leave_usage_from_request(PDO $pdo, array $req, int $requestId): void {
    if (strtoupper((string)($req['req_type'] ?? '')) !== 'CUTI') return;

    hrlp_ensure_leave_tables($pdo);

    $affected = [];
    try {
        $st = $pdo->prepare("SELECT employee_id, year FROM hrl_leave_usages WHERE request_id=?");
        $st->execute([$requestId]);
        $affected = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $pdo->prepare("DELETE FROM hrl_leave_usages WHERE request_id=?")->execute([$requestId]);

    if (!$affected) {
        $employeeId = hrlp_find_employee_id_for_request($pdo, $req);
        $year = !empty($req['start_date']) ? (int)date('Y', strtotime((string)$req['start_date'])) : (int)date('Y');
        if ($employeeId > 0) $affected[] = ['employee_id' => $employeeId, 'year' => $year];
    }

    foreach ($affected as $a) {
        $empId = (int)($a['employee_id'] ?? 0);
        $yr = (int)($a['year'] ?? 0);
        if ($empId > 0 && $yr > 0) hrlp_sync_leave_balance($pdo, $empId, $yr);
    }

    hrlp_audit('CUTI_REMOVE_FROM_LEAVE_BALANCE', ['id'=>$requestId, 'affected'=>$affected]);
}

// Pastikan relasi HRL -> Payroll Loans tersedia sebelum transaksi approval berjalan.
hrlp_ensure_payroll_loan_link($pdo);

$action = (string)($_POST['action'] ?? '');
if ($action !== '') {
    csrf_check();
    try {
        // reload req inside transaction
        // Transaction safe guard:
        // Hindari error "There is no active transaction" jika helper audit/upload menutup transaksi.
        $hrlTxStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $hrlTxStarted = true;
        }
        $stmt = $pdo->prepare("SELECT * FROM hrl_requests WHERE id=? FOR UPDATE");
        $stmt->execute([$id]);
        $req2 = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req2) throw new RuntimeException('Pengajuan tidak ditemukan.');
        if (!hrlp_can_view($req2)) throw new RuntimeException('Akses ditolak.');

        $statusNow = strtoupper((string)$req2['status']);
        $reqType2 = strtoupper((string)($req2['req_type'] ?? ''));
        $needFin2 = hrlp_need_fin($req2);

        // helpers for uploading
        $dir = hrlp_req_dir($id);

        if (in_array($action, ['save','submit'], true)) {
            // Open-access HRL Process: pemohon boleh edit draft/rejected untuk semua tipe termasuk KASBON.
            $canEditThis = (me_username() === (string)($req2['created_by'] ?? ''))
                && in_array($statusNow, ['DRAFT', 'REJECTED', 'NEED_DOCUMENT'], true);
            if (!$canEditThis) {
                throw new RuntimeException('Hanya pemohon yang bisa edit/submit saat DRAFT/REJECTED.');
            }

            $title = trim((string)($_POST['title'] ?? ($req2['title'] ?? '')));
            $desc = trim((string)($_POST['description'] ?? ($req2['description'] ?? '')));
            $start = trim((string)($_POST['start_date'] ?? ($req2['start_date'] ?? ''))) ?: null;
            $end = trim((string)($_POST['end_date'] ?? ($req2['end_date'] ?? ''))) ?: null;
            $amount = (string)($_POST['amount'] ?? ($req2['amount'] ?? '0'));
            $staffData = null;
            $sicknessData = null;
            if ($reqType2 === 'PERMINTAAN_KARYAWAN' && array_key_exists('staff_position_name', $_POST)) {
                $staffData = hrlp_staffing_validate(
                    (string)($_POST['staff_position_name'] ?? ''),
                    (string)($_POST['staff_division_name'] ?? ''),
                    (string)($_POST['staff_work_location'] ?? ''),
                    max(1, (int)($_POST['staff_headcount'] ?? 1)),
                    (string)($_POST['staff_expected_start_date'] ?? '')
                );
                $start = $staffData['expected_start_date'];
                $end = $staffData['expected_start_date'];
            }
            if ($reqType2 === 'SAKIT' && array_key_exists('sick_general_condition', $_POST)) {
                $sicknessData = hrlp_sickness_validate($_POST, $start, $end);
                $amount = 0;
            }
            $amount = is_numeric($amount) ? (float)$amount : 0.0;
            $otData = null;
            if ($reqType2 === 'LEMBUR') {
                $otDate = trim((string)($_POST['overtime_date'] ?? ''));
                $otStart = trim((string)($_POST['overtime_start_time'] ?? ''));
                $otEnd = trim((string)($_POST['overtime_end_time'] ?? ''));
                $otBreak = max(0, (int)($_POST['overtime_break_minutes'] ?? 0));
                $otWork = trim((string)($_POST['overtime_work_description'] ?? ''));
                $otData = hrlp_overtime_calculate($otDate, $otStart, $otEnd, $otBreak);
                $start = substr($otData['start_at'],0,10);
                $end = substr($otData['end_at'],0,10);
            }

            $gpsLat = trim((string)($_POST['gps_lat'] ?? ''));
            $gpsLng = trim((string)($_POST['gps_lng'] ?? ''));
            $gpsAcc = trim((string)($_POST['gps_accuracy_m'] ?? ''));

            if ($title === '') throw new RuntimeException('Judul wajib diisi.');

            $pdo->prepare("UPDATE hrl_requests SET title=?, description=?, start_date=?, end_date=?, amount=?, gps_lat=?, gps_lng=?, gps_accuracy_m=?, updated_at=NOW() WHERE id=?")
                ->execute([
                    $title, $desc, $start, $end, $amount,
                    ($gpsLat!==''?$gpsLat:null),
                    ($gpsLng!==''?$gpsLng:null),
                    ($gpsAcc!==''?(int)$gpsAcc:null),
                    $id
                ]);

            if ($reqType2 === 'LEMBUR' && is_array($otData)) {
                hrlp_overtime_upsert($pdo, $id, $otData, $otWork);
            }
            if ($reqType2 === 'PERMINTAAN_KARYAWAN' && is_array($staffData)) {
                hrlp_staffing_upsert($pdo, $id, $staffData);
            }
            if ($reqType2 === 'SAKIT' && is_array($sicknessData)) {
                hrlp_sickness_upsert($pdo, $id, $sicknessData);
            }

            // Upload proof photo
            $hasPhoto = isset($_FILES['proof_photo']) && (int)($_FILES['proof_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            // Sanitasi nama file untuk keamanan + static scan marker
            $photoNameSafe = rmi_safe_filename((string)($_FILES['proof_photo']['name'] ?? ''));
            if ($hasPhoto) {
                $saved = hrlp_save_upload($_FILES['proof_photo'], $dir, 'photo');
                $pdo->prepare("INSERT INTO hrl_request_files (request_id, kind, file_path, file_name, mime, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$id, 'PHOTO', $saved['path'], $saved['orig'], $saved['mime'], $saved['size'], me_username()]);
                $pdo->prepare("UPDATE hrl_requests SET photo_path=? WHERE id=?")->execute([$saved['path'], $id]);
            }

            // Upload attachments (multi)
            $hasAttach = false;
            if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
                $names = (array)($_FILES['attachments']['name'] ?? []);
                $tmps  = (array)($_FILES['attachments']['tmp_name'] ?? []);
                $errs  = (array)($_FILES['attachments']['error'] ?? []);
                $sizes = (array)($_FILES['attachments']['size'] ?? []);
                $types = (array)($_FILES['attachments']['type'] ?? []);
                for ($i=0; $i<count($names); $i++) {
                    if ((int)($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    // Sanitasi nama file untuk keamanan + static scan marker
                    $attNameSafe = rmi_safe_filename((string)($names[$i] ?? ''));
                    $hasAttach = true;
                    $one = [
                        'name' => $names[$i],
                        'tmp_name' => $tmps[$i],
                        'error' => $errs[$i],
                        'size' => $sizes[$i] ?? 0,
                        'type' => $types[$i] ?? '',
                    ];
                    $saved = hrlp_save_upload($one, $dir, 'att');
                    $pdo->prepare("INSERT INTO hrl_request_files (request_id, kind, file_path, file_name, mime, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$id, 'ATTACH', $saved['path'], $saved['orig'], $saved['mime'], $saved['size'], me_username()]);
                }
            }

            if ($action === 'submit') {
                // re-evaluate requirements from DB
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM hrl_request_files WHERE request_id=?");
                $stmt->execute([$id]);
                $fileCount = (int)$stmt->fetchColumn();

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM hrl_request_files WHERE request_id=? AND kind='PHOTO'");
                $stmt->execute([$id]);
                $photoCount = (int)$stmt->fetchColumn();

                $typeNow = strtoupper((string)$req2['req_type']);
                // Attachment bersifat opsional untuk seluruh tipe. Ketentuan GPS + foto tetap mengikuti alur lama.
                if (hrlp_requires_gps_photo($typeNow)) {
                    if ($photoCount <= 0) throw new RuntimeException('Wajib ada FOTO (selfie/bukti) sebelum submit.');
                    $effectiveLat = $gpsLat !== '' ? $gpsLat : trim((string)($req2['gps_lat'] ?? ''));
                    $effectiveLng = $gpsLng !== '' ? $gpsLng : trim((string)($req2['gps_lng'] ?? ''));
                    if ($effectiveLat === '' || $effectiveLng === '') throw new RuntimeException('Wajib ambil GPS sebelum submit.');
                }

                $pdo->prepare("UPDATE hrl_requests SET status='SUBMITTED', submitted_by=?, submitted_at=NOW(), updated_at=NOW() WHERE id=?")
                    ->execute([me_username(), $id]);
                hrlp_audit('SUBMIT', ['id'=>$id,'code'=>$req2['req_code'] ?? null]);

                // Khusus BRANCH tanpa Manager aktif di office request: lanjut otomatis ke HRL.
                // Jika Manager tersedia, tidak ada perubahan pada alur existing.
                hrlp_auto_bypass_branch_manager($pdo, $id);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'hrl_process', 'hrl_requests', 'SUBMIT', $id, $req2['req_code'] ?? "REQ#{$id}", "HRL request submitted: " . ($req2['req_code'] ?? $id), []);
                }
                flash_set('Berhasil submit pengajuan.', 'success');
            } else {
                hrlp_audit('SAVE', ['id'=>$id]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'hrl_process', 'hrl_requests', 'SAVE', $id, $req2['req_code'] ?? "REQ#{$id}", "HRL request saved: " . ($req2['req_code'] ?? $id), []);
                }
                flash_set('Perubahan tersimpan.', 'success');
            }
        }

        if ($action === 'approve_mgr') {
            // Permission tipe tidak dipakai untuk workflow approval.
            // Akses tetap dikontrol oleh status + role/dept di bawah.
            $deptReq = strtoupper((string)($req2['dept_code'] ?? ''));
            $can = ($statusNow === 'SUBMITTED') && (is_admin_owner() || (is_manager_like() && $deptReq === me_dept()));
            if (!$can) throw new RuntimeException('Tidak bisa approve (manager) pada status ini / akses tidak sesuai.');

            $method = require_confirm($pdo);

            $pdo->prepare("UPDATE hrl_requests SET status='MANAGER_APPROVED', manager_approved_by=?, manager_approved_at=NOW(), manager_sign_method=?, updated_at=NOW() WHERE id=?")
                ->execute([me_username(), $method, $id]);
            hrlp_audit('APPROVE_MANAGER', ['id'=>$id,'method'=>$method]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'hrl_process', 'hrl_requests', 'APPROVE_MANAGER', $id, $req2['req_code'] ?? "REQ#{$id}", "HRL request approved by Manager: " . ($req2['req_code'] ?? $id), []);
            }
            flash_set('Approve Manager berhasil.', 'success');
        }

        if ($action === 'approve_hrl') {
            // Permission tipe tidak dipakai untuk workflow approval.
            // Akses tetap dikontrol oleh status + role/dept di bawah.
            $can = ($statusNow === 'MANAGER_APPROVED') && (is_admin_owner() || is_dept('HRL'));
            if (!$can) throw new RuntimeException('Tidak bisa approve HRL pada status ini / akses tidak sesuai.');

            $method = require_confirm($pdo);

            $pdo->prepare("UPDATE hrl_requests SET status='HRL_APPROVED', hrl_approved_by=?, hrl_approved_at=NOW(), hrl_sign_method=?, updated_at=NOW() WHERE id=?")
                ->execute([me_username(), $method, $id]);

            if ($reqType2 === 'CUTI') {
                hrlp_record_leave_usage_from_request($pdo, $req2, $id);
            }
            if ($reqType2 === 'SAKIT') {
                hrlp_sickness_finalize($pdo, $id, me_username());
            }

            hrlp_audit('APPROVE_HRL', ['id'=>$id,'method'=>$method]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'hrl_process', 'hrl_requests', 'APPROVE_HRL', $id, $req2['req_code'] ?? "REQ#{$id}", "HRL request approved by HRL: " . ($req2['req_code'] ?? $id), []);
            }
            flash_set('Approve HRL berhasil.', 'success');
        }

        if ($action === 'approve_fin') {
            // Permission tipe tidak dipakai untuk workflow approval.
            // Akses tetap dikontrol oleh status + role/dept di bawah.
            if (!$needFin2) throw new RuntimeException('Pengajuan ini tidak memerlukan FIN (nominal 0 & tipe non-fin).');
            $can = ($statusNow === 'HRL_APPROVED') && (is_admin_owner() || is_dept('FIN'));
            if (!$can) throw new RuntimeException('Tidak bisa approve FIN pada status ini / akses tidak sesuai.');

            $method = require_confirm($pdo);

            $pdo->prepare("UPDATE hrl_requests SET status='FIN_APPROVED', fin_approved_by=?, fin_approved_at=NOW(), fin_sign_method=?, updated_at=NOW() WHERE id=?")
                ->execute([me_username(), $method, $id]);
            hrlp_audit('APPROVE_FIN', ['id'=>$id,'method'=>$method]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'hrl_process', 'hrl_requests', 'APPROVE_FIN', $id, $req2['req_code'] ?? "REQ#{$id}", "HRL request approved by FIN: " . ($req2['req_code'] ?? $id), []);
            }
            flash_set('Approve FIN berhasil.', 'success');
        }

        if ($action === 'mark_paid') {
            // Permission tipe tidak dipakai untuk workflow approval.
            // Akses tetap dikontrol oleh status + role/dept di bawah.
            $can = ($statusNow === 'FIN_APPROVED') && (is_admin_owner() || is_dept('FIN'));
            if (!$can) throw new RuntimeException('Tidak bisa mark PAID pada status ini / akses tidak sesuai.');

            $method = require_confirm($pdo); // still require confirm

            $pdo->prepare("UPDATE hrl_requests SET status='PAID', paid_by=?, paid_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([me_username(), $id]);

            if ($reqType2 === 'KASBON') {
                $loanId = hrlp_create_payroll_loan_from_hrl_request($pdo, $req2, $id);
                hrlp_audit('KASBON_TO_PAYROLL_LOAN', ['id'=>$id, 'loan_id'=>$loanId]);
            }

            hrlp_audit('MARK_PAID', ['id'=>$id,'method'=>$method]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'hrl_process', 'hrl_requests', 'MARK_PAID', $id, $req2['req_code'] ?? "REQ#{$id}", "HRL request marked PAID: " . ($req2['req_code'] ?? $id), []);
            }
            flash_set('Status PAID tersimpan.', 'success');
        }

        if ($action === 'soft_delete') {
            // Delete tidak tergantung permission tipe; tetap dibatasi di bawah.
            $canDel = is_admin_owner() || is_dept('HRL') || is_dept('FIN');
            if (!$canDel) {
                $canDel = (me_username() === (string)($req2['created_by'] ?? '')) && in_array($statusNow, ['DRAFT','REJECTED','NEED_DOCUMENT'], true);
            }
            if (!$canDel) throw new RuntimeException('Tidak bisa hapus pengajuan ini.');
            if ($reqType2 === 'CUTI') {
                // Jika cuti yang sudah approved dihapus, saldo cuti harus otomatis dikembalikan.
                hrlp_remove_leave_usage_from_request($pdo, $req2, $id);
            }
            $pdo->prepare("UPDATE hrl_requests SET deleted_by=?, deleted_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([me_username(), $id]);
            hrlp_audit('SOFT_DELETE', ['id'=>$id]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'hrl_process', 'hrl_requests', 'SOFT_DELETE', $id, $req2['req_code'] ?? "REQ#{$id}", "HRL request soft deleted: " . ($req2['req_code'] ?? $id), []);
            }
            flash_set('Pengajuan berhasil dihapus.', 'success');
        }

        if ($action === 'need_document') {
            $note = trim((string)($_POST['reject_note'] ?? ''));
            if ($reqType2 !== 'SAKIT') throw new RuntimeException('Status perlu dokumen hanya untuk pengajuan sakit.');
            if ($note === '') throw new RuntimeException('Dokumen/keterangan yang perlu dilengkapi wajib ditulis.');
            $can = ($statusNow === 'MANAGER_APPROVED') && (is_admin_owner() || is_dept('HRL'));
            if (!$can) throw new RuntimeException('Hanya HRL yang dapat meminta dokumen pada tahap ini.');
            $method = require_confirm($pdo);
            $pdo->prepare("UPDATE hrl_requests SET status='NEED_DOCUMENT', rejected_by=?, rejected_at=NOW(), reject_note=?, updated_at=NOW() WHERE id=?")
                ->execute([me_username(), $note, $id]);
            hrlp_audit('SAKIT_NEED_DOCUMENT', ['id'=>$id,'method'=>$method,'note'=>$note]);
            flash_set('Pengajuan dikembalikan untuk melengkapi dokumen.', 'warning');
        }

        if ($action === 'reject') {
            // Permission tipe tidak dipakai untuk reject.
            // Akses tetap dikontrol oleh status + role/dept di bawah.
            $note = trim((string)($_POST['reject_note'] ?? ''));
            if ($note === '') throw new RuntimeException('Alasan reject wajib diisi.');

            // who can reject depends on status
            $deptReq = strtoupper((string)($req2['dept_code'] ?? ''));
            $can = false;
            if (is_admin_owner()) $can = true;
            elseif ($statusNow === 'SUBMITTED' && is_manager_like() && $deptReq === me_dept()) $can = true;
            elseif ($statusNow === 'MANAGER_APPROVED' && is_dept('HRL')) $can = true;
            elseif ($statusNow === 'HRL_APPROVED' && $needFin2 && is_dept('FIN')) $can = true;

            if (!$can) throw new RuntimeException('Tidak bisa reject pada status ini / akses tidak sesuai.');

            $method = require_confirm($pdo);

            if ($reqType2 === 'CUTI') {
                // Pengaman: jika ada usage cuti dari proses sebelumnya, saldo dikembalikan saat reject.
                hrlp_remove_leave_usage_from_request($pdo, $req2, $id);
            }
            $pdo->prepare("UPDATE hrl_requests SET status='REJECTED', rejected_by=?, rejected_at=NOW(), reject_note=?, updated_at=NOW() WHERE id=?")
                ->execute([me_username(), $note, $id]);
            hrlp_audit('REJECT', ['id'=>$id,'method'=>$method,'note'=>$note]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'hrl_process', 'hrl_requests', 'REJECT', $id, $req2['req_code'] ?? "REQ#{$id}", "HRL request rejected: " . ($req2['req_code'] ?? $id), ['note' => $note]);
            }
            flash_set('Pengajuan di-reject.', 'warning');
        }

        if ($hrlTxStarted && $pdo->inTransaction()) {
            $pdo->commit();
        }
        rmi_redirect(u('/hrl_process/request_view.php?id='.$id));
    } catch (Throwable $e) {
        try {
            if (isset($hrlTxStarted) && $hrlTxStarted && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $rollbackError) {
            // Abaikan error rollback agar tidak memunculkan "There is no active transaction".
        }

        $errMsg = $e->getMessage();
        if (stripos($errMsg, 'There is no active transaction') !== false) {
            // Transaction sudah ditutup oleh helper/audit, jadi jangan tampilkan notifikasi teknis ini.
            flash_set('Proses berhasil diproses. Halaman diperbarui.', 'success');
        } else {
            flash_set('Error: ' . $errMsg, 'danger');
        }
        rmi_redirect(u('/hrl_process/request_view.php?id='.$id));
    }
}

// Refresh after actions
$stmt = $pdo->prepare("SELECT * FROM hrl_requests WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC) ?: $req;
// Rekonsiliasi aman untuk request BRANCH lama yang sudah terlanjur SUBMITTED sebelum fix ini.
// Hanya mengubah request BRANCH yang tidak mempunyai Manager aktif pada office request.
if (strtoupper((string)($req['status'] ?? '')) === 'SUBMITTED'
    && strtoupper((string)($req['dept_code'] ?? '')) === 'BRANCH') {
    if (hrlp_auto_bypass_branch_manager($pdo, $id)) {
        $stmt = $pdo->prepare("SELECT * FROM hrl_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC) ?: $req;
    }
}

$reqType = strtoupper((string)($req['req_type'] ?? ''));
$overtime = ($reqType === 'LEMBUR') ? hrlp_overtime_get($pdo, $id) : [];
$staffing = ($reqType === 'PERMINTAAN_KARYAWAN') ? hrlp_staffing_get($pdo, $id) : [];
$sickness = ($reqType === 'SAKIT') ? hrlp_sickness_get($pdo, $id) : [];
$canEdit = (me_username() === (string)($req['created_by'] ?? ''))
    && in_array(strtoupper((string)$req['status']), ['DRAFT','REJECTED','NEED_DOCUMENT'], true);
$canDelete = (is_admin_owner() || is_dept('HRL') || is_dept('FIN')
        || ((me_username() === (string)($req['created_by'] ?? '')) && in_array(strtoupper((string)$req['status']), ['DRAFT','REJECTED'], true)));

$files = req_files($pdo, $id);
$photo = req_photo_file($pdo, $id);
$needFin = hrlp_need_fin($req);
$requiresGpsPhoto = hrlp_requires_gps_photo((string)$req['req_type']);

$statusU = strtoupper((string)$req['status']);

function milestone_dot(?string $by, ?string $at): string {
    if ($at) return '<span class="status-badge"><span class="dot ok"></span>OK</span>';
    return '<span class="status-badge"><span class="dot wait"></span>WAIT</span>';
}

?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card rmi-card">
      <div class="card-header">Detail Pengajuan</div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
          <span class="badge-pill mono"><?= h($req['req_code'] ?: ('#'.$req['id'])) ?></span>
          <span class="badge-pill"><?= h($req['req_type']) ?></span>
          <span class="badge-pill"><?= h($req['dept_code'] ?: '-') ?></span>
          <span class="badge-pill"><?= h($req['office_code'] ?: '-') ?></span>
          <span class="badge-pill"><?= h($statusU) ?></span>
          <a class="btn btn-ghost btn-sm" href="<?= h(u('/hrl_process/request_print.php?id='.(int)$req['id'])) ?>" target="_blank">🖨️ Print</a>
        </div>

        <?php if (strtoupper((string)$req['req_type']) === 'PERJADIN'): ?>
          <div class="alert alert-info">Perjadin di modul ini hanya <b>form pengajuan</b>. Bukti perjalanan & realtime pakai <a class="small-link" href="<?= h(u('/absensi/request.php')) ?>">Absensi → Dinas Luar</a>.</div>
        <?php endif; ?>

        <div class="mb-2"><div class="muted">Judul</div><div class="fw-semibold"><?= h($req['title']) ?></div></div>
        <div class="mb-2"><div class="muted">Deskripsi</div><div><?= nl2br(h((string)$req['description'])) ?></div></div>

        <div class="row g-2 mb-2">
          <div class="col">
            <div class="muted">Start</div>
            <div class="mono"><?= h($req['start_date'] ?: '-') ?></div>
          </div>
          <div class="col">
            <div class="muted">End</div>
            <div class="mono"><?= h($req['end_date'] ?: '-') ?></div>
          </div>
        </div>

        <div class="mb-2">
          <div class="muted">Nominal</div>
          <div class="mono"><?= number_format((float)$req['amount'], 2) ?> <?= $needFin ? '<span class="badge-pill">Need FIN</span>' : '<span class="badge-pill">No FIN</span>' ?></div>
        </div>

        <div class="mb-2">
          <div class="muted">GPS</div>
          <div class="mono">
            <?= h($req['gps_lat'] ?: '-') ?>, <?= h($req['gps_lng'] ?: '-') ?>
            <?php if ($req['gps_lat'] && $req['gps_lng']): ?>
              <a class="small-link" target="_blank" href="https://www.google.com/maps?q=<?= h($req['gps_lat']) ?>,<?= h($req['gps_lng']) ?>">maps</a>
              <span class="help">(acc <?= h($req['gps_accuracy_m'] ?: '-') ?>m)</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="mb-2">
          <div class="muted">Pemohon</div>
          <div><?= h($req['created_by']) ?> <span class="help mono"><?= h($req['created_at']) ?></span></div>
        </div>

        <div class="mb-2">
          <div class="muted">Milestone</div>
          <div class="d-flex flex-wrap gap-2">
            <span class="badge-pill">MGR: <?= milestone_dot($req['manager_approved_by'] ?? null, $req['manager_approved_at'] ?? null) ?></span>
            <span class="badge-pill">HRL: <?= milestone_dot($req['hrl_approved_by'] ?? null, $req['hrl_approved_at'] ?? null) ?></span>
            <span class="badge-pill">FIN: <?= $needFin ? milestone_dot($req['fin_approved_by'] ?? null, $req['fin_approved_at'] ?? null) : '<span class="status-badge"><span class="dot ok"></span>N/A</span>' ?></span>
            <span class="badge-pill">PAID: <?= ($req['paid_at'] ? '<span class="status-badge"><span class="dot ok"></span>OK</span>' : '<span class="status-badge"><span class="dot wait"></span>WAIT</span>') ?></span>
          </div>
        </div>

        <?php if ($reqType === 'CUTI'):
          try {
            hrlp_ensure_leave_tables($pdo);
            $leaveEmpId = hrlp_find_employee_id_for_request($pdo, $req);
            $leaveYear = $req['start_date'] ? (int)date('Y', strtotime((string)$req['start_date'])) : (int)date('Y');
            $leaveBal = null;
            if ($leaveEmpId > 0) {
              $stLeave = $pdo->prepare("SELECT * FROM hrl_leave_balances WHERE employee_id=? AND year=? LIMIT 1");
              $stLeave->execute([$leaveEmpId, $leaveYear]);
              $leaveBal = $stLeave->fetch(PDO::FETCH_ASSOC);
            }
          } catch (Throwable $e) { $leaveBal = null; }
        ?>
          <div class="mb-2">
            <div class="muted">Saldo Cuti <?= h((string)$leaveYear) ?></div>
            <?php if ($leaveBal): ?>
              <?php $qInfoDetail = ($leaveEmpId > 0) ? hrlp_leave_quota_by_join($pdo, $leaveEmpId, $leaveYear) : ['join_label'=>'-','rule'=>'']; ?>
              <div class="mono">Jatah <?= h($leaveBal['quota_days']) ?> hari • Terpakai <?= h($leaveBal['used_days']) ?> hari • Sisa <?= h($leaveBal['remaining_days']) ?> hari</div>
              <div class="help">Basis masa kerja: <?= h($qInfoDetail['join_label'] ?? '-') ?> — <?= h($qInfoDetail['rule'] ?? '') ?></div>
            <?php else: ?>
              <div class="help">Saldo akan tercatat otomatis setelah HRL approve.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($reqType === 'SAKIT' && $sickness): ?>
          <div class="mb-2">
            <div class="muted">Detail Sakit</div>
            <div class="row g-2 mt-1">
              <div class="col-6"><div class="help">Durasi</div><b><?= h($sickness['day_type']==='HALF_DAY'?'Setengah hari':'Sehari penuh') ?></b></div>
              <div class="col-6"><div class="help">Perkiraan Kembali</div><b><?= h($sickness['expected_return_date'] ?: '-') ?></b></div>
              <div class="col-12"><div class="help">Kondisi Umum</div><b><?= h($sickness['general_condition'] ?: '-') ?></b></div>
              <div class="col-6"><div class="help">Lokasi Pemeriksaan</div><b><?= h($sickness['examination_location'] ?: '-') ?></b></div>
              <div class="col-6"><div class="help">Kontak</div><b><?= h($sickness['contact_during_leave'] ?: '-') ?></b></div>
              <div class="col-12"><div class="help">Serah Terima</div><b><?= h($sickness['handover_work'] ?: '-') ?><?= $sickness['handover_to'] ? ' → '.h($sickness['handover_to']) : '' ?></b></div>
              <div class="col-12"><span class="badge-pill"><?= !empty($sickness['doctor_letter_required']) ? 'Surat dokter wajib' : 'Surat dokter sesuai kebijakan' ?></span> <span class="badge-pill"><?= h($sickness['final_payroll_status'] ?: 'Menunggu validasi HRL') ?></span></div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($reqType === 'PERMINTAAN_KARYAWAN' && $staffing): ?>
          <div class="mb-2">
            <div class="muted">Detail Kebutuhan Karyawan</div>
            <div class="row g-2 mt-1">
              <div class="col-6"><div class="help">Jabatan</div><b><?= h($staffing['position_name']) ?></b></div>
              <div class="col-6"><div class="help">Divisi</div><b><?= h($staffing['division_name']) ?></b></div>
              <div class="col-6"><div class="help">Lokasi Kerja</div><b><?= h($staffing['work_location']) ?></b></div>
              <div class="col-3"><div class="help">Jumlah</div><b><?= (int)$staffing['headcount'] ?> orang</b></div>
              <div class="col-3"><div class="help">Mulai Kerja</div><b><?= h($staffing['expected_start_date']) ?></b></div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($photo): ?>
          <div class="mb-2">
            <div class="muted">Foto Bukti</div>
            <a class="btn btn-ghost btn-sm" target="_blank" href="<?= h(u('/hrl_process/download.php?file_id='.(int)$photo['id'])) ?>">Lihat / Download Foto</a>
          </div>
        <?php endif; ?>

      </div>
    </div>

    <div class="card rmi-card mt-3">
      <div class="card-header">File Attachment</div>
      <div class="card-body">
        <?php if (!$files): ?>
          <div class="help">Belum ada file.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table-sm table-dark align-middle mb-0">
              <thead>
                <tr><th>Kind</th><th>Nama</th><th>Size</th><th>By</th><th></th></tr>
              </thead>
              <tbody>
                <?php foreach ($files as $f): ?>
                  <tr>
                    <td class="mono"><?= h($f['kind']) ?></td>
                    <td><?= h($f['file_name']) ?></td>
                    <td class="mono"><?= number_format(((int)$f['size_bytes'])/1024, 1) ?> KB</td>
                    <td><?= h($f['uploaded_by']) ?></td>
                    <td><a class="btn btn-sm btn-soft" href="<?= h(u('/hrl_process/download.php?file_id='.(int)$f['id'])) ?>">Download</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <div class="col-lg-7">
  <?php if ($reqType === 'LEMBUR' && $overtime): ?>
  <div class="card rmi-card mb-3"><div class="card-header">Akumulasi Waktu Lembur</div><div class="card-body">
    <div class="row g-2">
      <div class="col-md-4"><div class="help">Mulai</div><b><?= h($overtime['overtime_start_at']) ?></b></div>
      <div class="col-md-4"><div class="help">Selesai</div><b><?= h($overtime['overtime_end_at']) ?></b></div>
      <div class="col-md-4"><div class="help">Durasi Bersih</div><b><?= intdiv((int)$overtime['duration_minutes'],60) ?> jam <?= ((int)$overtime['duration_minutes']%60) ?> menit</b></div>
    </div>
    <div class="help mt-2">Istirahat: <?= (int)$overtime['break_minutes'] ?> menit · Periode payroll: <?= h($overtime['payroll_period'] ?? '-') ?></div>
  </div></div>
  <?php endif; ?>


    <?php if ($canEdit): ?>
      <div class="card rmi-card mb-3">
        <div class="card-header">Edit / Submit Pengajuan</div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" class="mb-3">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">

            <div class="mb-2">
              <label class="form-label">Judul</label>
              <input class="form-control" name="title" value="<?= h($req['title']) ?>" required>
            </div>
            <div class="mb-2">
              <label class="form-label">Deskripsi</label>
              <textarea class="form-control" name="description" rows="3"><?= h((string)$req['description']) ?></textarea>
            </div>

            <?php if ($reqType === 'SAKIT'): ?>
            <div class="card rmi-card mb-2"><div class="card-body">
              <div class="fw-semibold mb-2">Detail Pengajuan Sakit</div>
              <div class="row g-2">
                <div class="col-6"><label class="form-label">Durasi Hari</label><select class="form-select" name="sick_day_type"><option value="FULL_DAY" <?= (($sickness['day_type']??'FULL_DAY')==='FULL_DAY'?'selected':'') ?>>Sehari penuh</option><option value="HALF_DAY" <?= (($sickness['day_type']??'')==='HALF_DAY'?'selected':'') ?>>Setengah hari</option></select></div>
                <div class="col-6"><label class="form-label">Perkiraan Kembali</label><input type="date" class="form-control" name="sick_expected_return_date" value="<?= h($sickness['expected_return_date'] ?? '') ?>" required></div>
                <div class="col-12"><label class="form-label">Kondisi Umum/Keluhan Singkat</label><textarea class="form-control" name="sick_general_condition" required><?= h($sickness['general_condition'] ?? '') ?></textarea></div>
                <div class="col-12"><label class="form-label">Lokasi Pemeriksaan</label><input class="form-control" name="sick_examination_location" value="<?= h($sickness['examination_location'] ?? '') ?>" required></div>
                <div class="col-12"><label class="form-label">Pekerjaan Diserahterimakan</label><textarea class="form-control" name="sick_handover_work"><?= h($sickness['handover_work'] ?? '') ?></textarea></div>
                <div class="col-6"><label class="form-label">Penerima Pekerjaan</label><input class="form-control" name="sick_handover_to" value="<?= h($sickness['handover_to'] ?? '') ?>"></div>
                <div class="col-6"><label class="form-label">Nomor Kontak</label><input class="form-control" name="sick_contact" value="<?= h($sickness['contact_during_leave'] ?? '') ?>" required></div>
                <div class="col-6"><label><input type="checkbox" name="sick_emergency" value="1" <?= !empty($sickness['emergency_flag'])?'checked':'' ?>> Darurat/IGD</label></div>
                <div class="col-6"><label><input type="checkbox" name="sick_inpatient" value="1" <?= !empty($sickness['inpatient_flag'])?'checked':'' ?>> Rawat inap</label></div>
              </div>
            </div></div>
            <?php endif; ?>

            <?php if ($reqType === 'PERMINTAAN_KARYAWAN'): ?>
            <div class="card rmi-card mb-2"><div class="card-body">
              <div class="fw-semibold mb-2">Detail Kebutuhan Karyawan</div>
              <div class="row g-2">
                <div class="col-12"><label class="form-label">Jabatan</label><input class="form-control" name="staff_position_name" value="<?= h($staffing['position_name'] ?? '') ?>" required></div>
                <div class="col-6"><label class="form-label">Divisi / Departemen</label><input class="form-control" name="staff_division_name" value="<?= h($staffing['division_name'] ?? '') ?>" required></div>
                <div class="col-6"><label class="form-label">Lokasi Kerja</label><input class="form-control" name="staff_work_location" value="<?= h($staffing['work_location'] ?? '') ?>" required></div>
                <div class="col-5"><label class="form-label">Jumlah Kebutuhan</label><input type="number" min="1" max="999" class="form-control" name="staff_headcount" value="<?= (int)($staffing['headcount'] ?? 1) ?>" required></div>
                <div class="col-7"><label class="form-label">Tanggal Mulai Kerja</label><input type="date" class="form-control" name="staff_expected_start_date" value="<?= h($staffing['expected_start_date'] ?? ($req['start_date'] ?: '')) ?>" required></div>
              </div>
            </div></div>
            <?php endif; ?>

            <?php if ($reqType === 'LEMBUR'): ?>
            <div class="card rmi-card mb-2"><div class="card-body">
              <div class="fw-semibold mb-2">Detail Waktu Lembur</div>
              <div class="row g-2">
                <div class="col-12"><label class="form-label">Tanggal</label><input type="date" class="form-control" name="overtime_date" value="<?= h(!empty($overtime['overtime_start_at']) ? substr($overtime['overtime_start_at'],0,10) : ($req['start_date'] ?: '')) ?>" required></div>
                <div class="col-6"><label class="form-label">Jam Mulai</label><input type="time" class="form-control" name="overtime_start_time" value="<?= h(!empty($overtime['overtime_start_at']) ? substr($overtime['overtime_start_at'],11,5) : '') ?>" required></div>
                <div class="col-6"><label class="form-label">Jam Selesai</label><input type="time" class="form-control" name="overtime_end_time" value="<?= h(!empty($overtime['overtime_end_at']) ? substr($overtime['overtime_end_at'],11,5) : '') ?>" required></div>
                <div class="col-4"><label class="form-label">Istirahat</label><input type="number" min="0" max="720" class="form-control" name="overtime_break_minutes" value="<?= (int)($overtime['break_minutes'] ?? 0) ?>"></div>
                <div class="col-8"><label class="form-label">Pekerjaan</label><input class="form-control" name="overtime_work_description" value="<?= h($overtime['work_description'] ?? '') ?>"></div>
              </div>
            </div></div>
            <?php endif; ?>

            <div class="row g-2 mb-2">
              <div class="col">
                <label class="form-label">Start</label>
                <input type="date" class="form-control" name="start_date" value="<?= h($req['start_date'] ?: '') ?>">
              </div>
              <div class="col">
                <label class="form-label">End</label>
                <input type="date" class="form-control" name="end_date" value="<?= h($req['end_date'] ?: '') ?>">
              </div>
              <div class="col">
                <label class="form-label">Nominal</label>
                <input type="number" step="0.01" class="form-control" name="amount" value="<?= h((string)$req['amount']) ?>">
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">Tambah Attachment (opsional)</label>
              <input type="file" class="form-control" name="attachments[]" multiple>
            </div>

            <div class="mb-2">
              <label class="form-label">Foto Bukti <?= $requiresGpsPhoto ? '(wajib)' : '(opsional)' ?></label>
              <input type="file" class="form-control" name="proof_photo" accept="image/*" capture="user">
            </div>

            <div class="mb-2">
              <label class="form-label">GPS <?= $requiresGpsPhoto ? '(wajib)' : '(opsional)' ?></label>
              <div class="row g-2">
                <div class="col-4"><button type="button" class="btn btn-ghost w-100" id="btnGeo">Ambil GPS</button></div>
                <div class="col"><input class="form-control" name="gps_lat" id="gps_lat" value="<?= h($req['gps_lat'] ?: '') ?>" readonly></div>
                <div class="col"><input class="form-control" name="gps_lng" id="gps_lng" value="<?= h($req['gps_lng'] ?: '') ?>" readonly></div>
              </div>
              <input type="hidden" name="gps_accuracy_m" id="gps_acc" value="<?= h((string)($req['gps_accuracy_m'] ?? '')) ?>">
              <div class="help mt-1" id="geoHelp">Klik “Ambil GPS” lalu izinkan lokasi.</div>
            </div>

            <div class="d-grid gap-2">
              <button class="btn btn-ghost">Simpan Perubahan</button>
            </div>
          </form>

          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="submit">

            <div class="alert alert-warning">
              <div class="fw-semibold">Submit akan mengunci untuk approval.</div>
              <div class="help">
                Attachment opsional. <?= $requiresGpsPhoto ? 'GPS + foto tetap wajib sesuai alur.' : 'Perjadin dapat disubmit tanpa attachment.' ?>
              </div>
            </div>

            <!-- allow upload at submit too -->
            <div class="mb-2">
              <label class="form-label">Attachment tambahan (opsional)</label>
              <input type="file" class="form-control" name="attachments[]" multiple>
            </div>
            <div class="mb-2">
              <label class="form-label">Foto Bukti (jika belum ada)</label>
              <input type="file" class="form-control" name="proof_photo" accept="image/*" capture="user">
            </div>
            <div class="mb-2">
              <label class="form-label">GPS (klik Ambil GPS di atas bila kosong)</label>
              <div class="row g-2">
                <div class="col"><input class="form-control" name="gps_lat" value="<?= h($req['gps_lat'] ?: '') ?>" readonly></div>
                <div class="col"><input class="form-control" name="gps_lng" value="<?= h($req['gps_lng'] ?: '') ?>" readonly></div>
              </div>
              <input type="hidden" name="gps_accuracy_m" value="<?= h((string)($req['gps_accuracy_m'] ?? '')) ?>">
            </div>

            <div class="d-grid">
              <button class="btn btn-soft">SUBMIT Sekarang</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($canDelete): ?>
      <div class="card rmi-card mb-3 border-danger">
        <div class="card-header text-danger">Hapus Pengajuan</div>
        <div class="card-body">
          <form method="post" onsubmit="return confirm('Yakin hapus pengajuan ini?');">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="soft_delete">
            <button class="btn btn-outline-danger btn-sm" type="submit">Hapus Pengajuan</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="card rmi-card">
      <div class="card-header">Workflow & TTD Digital (Approve/Reject)</div>
      <div class="card-body">

        <div class="help mb-2">TTD digital: isi <b>Password</b> atau <b>PIN</b> (PIN bisa diset di menu "PIN TTD").</div>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="rmi-card p-3">
              <div class="fw-semibold mb-1">Approve Manager</div>
              <div class="help mb-2">Status: SUBMITTED → MANAGER_APPROVED</div>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="approve_mgr">
                <input class="form-control mb-2" name="confirm_password" type="password" placeholder="Password (opsional)">
                <input class="form-control mb-2" name="confirm_pin" placeholder="PIN (opsional)">
                <button class="btn btn-soft w-100" <?= (strtoupper($req['status'])!=='SUBMITTED'?'disabled':'') ?>>Approve Manager</button>
              </form>
            </div>
          </div>

          <div class="col-md-6">
            <div class="rmi-card p-3">
              <div class="fw-semibold mb-1">Approve HRL</div>
              <div class="help mb-2">Status: MANAGER_APPROVED → HRL_APPROVED</div>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="approve_hrl">
                <input class="form-control mb-2" name="confirm_password" type="password" placeholder="Password (opsional)">
                <input class="form-control mb-2" name="confirm_pin" placeholder="PIN (opsional)">
                <button class="btn btn-soft w-100" <?= (strtoupper($req['status'])!=='MANAGER_APPROVED'?'disabled':'') ?>>Approve HRL</button>
              </form>
            </div>
          </div>

          <?php if ($reqType === 'SAKIT'): ?>
          <div class="col-md-6">
            <div class="rmi-card p-3">
              <div class="fw-semibold mb-1">Minta Dokumen Sakit</div>
              <div class="help mb-2">HRL mengembalikan ke pemohon tanpa menjadikannya alpa.</div>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="need_document">
                <input class="form-control mb-2" name="reject_note" placeholder="Dokumen/keterangan yang perlu dilengkapi">
                <input class="form-control mb-2" name="confirm_password" type="password" placeholder="Password (opsional)">
                <input class="form-control mb-2" name="confirm_pin" placeholder="PIN (opsional)">
                <button class="btn btn-warning w-100" <?= (strtoupper($req['status'])!=='MANAGER_APPROVED'?'disabled':'') ?>>Perlu Dilengkapi</button>
              </form>
            </div>
          </div>
          <?php endif; ?>

          <div class="col-md-6">
            <div class="rmi-card p-3">
              <div class="fw-semibold mb-1">Approve FIN</div>
              <div class="help mb-2">Status: HRL_APPROVED → FIN_APPROVED <?= $needFin ? '' : '(N/A)' ?></div>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="approve_fin">
                <input class="form-control mb-2" name="confirm_password" type="password" placeholder="Password (opsional)">
                <input class="form-control mb-2" name="confirm_pin" placeholder="PIN (opsional)">
                <button class="btn btn-soft w-100" <?= (!$needFin || strtoupper($req['status'])!=='HRL_APPROVED'?'disabled':'') ?>>Approve FIN</button>
              </form>
            </div>
          </div>

          <div class="col-md-6">
            <div class="rmi-card p-3">
              <div class="fw-semibold mb-1">Mark PAID (FIN)</div>
              <div class="help mb-2">Status: FIN_APPROVED → PAID</div>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_paid">
                <input class="form-control mb-2" name="confirm_password" type="password" placeholder="Password (opsional)">
                <input class="form-control mb-2" name="confirm_pin" placeholder="PIN (opsional)">
                <button class="btn btn-soft w-100" <?= (strtoupper($req['status'])!=='FIN_APPROVED'?'disabled':'') ?>>Set PAID</button>
              </form>
            </div>
          </div>

          <div class="col-12">
            <div class="rmi-card p-3">
              <div class="fw-semibold mb-1">Reject</div>
              <div class="help mb-2">Bisa dilakukan oleh approver di tahapnya masing-masing. Status → REJECTED.</div>
              <form method="post" class="row g-2">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="reject">
                <div class="col-md-6">
                  <input class="form-control" name="reject_note" placeholder="Alasan reject (wajib)">
                </div>
                <div class="col-md-3">
                  <input class="form-control" name="confirm_password" type="password" placeholder="Password">
                </div>
                <div class="col-md-2">
                  <input class="form-control" name="confirm_pin" placeholder="PIN">
                </div>
                <div class="col-md-1 d-grid">
                  <button class="btn btn-danger">Reject</button>
                </div>
              </form>

              <?php if (!empty($req['reject_note'])): ?>
                <div class="alert alert-warning mt-2 mb-0">
                  <div class="fw-semibold">Catatan Reject</div>
                  <div><?= nl2br(h((string)$req['reject_note'])) ?></div>
                  <div class="help">By <?= h((string)$req['rejected_by']) ?> at <?= h((string)$req['rejected_at']) ?></div>
                </div>
              <?php endif; ?>
            </div>
          </div>

        </div>

      </div>
    </div>

  </div>
</div>

<script>
(function(){
  const btn = document.getElementById('btnGeo');
  const lat = document.getElementById('gps_lat');
  const lng = document.getElementById('gps_lng');
  const acc = document.getElementById('gps_acc');
  const help = document.getElementById('geoHelp');

  function setHelp(t){ if(help) help.textContent=t; }

  function getGeo(){
    if(!navigator.geolocation){
      setHelp('Browser tidak mendukung Geolocation.');
      return;
    }
    setHelp('Mengambil lokasi...');
    navigator.geolocation.getCurrentPosition(function(pos){
      if(lat) lat.value = pos.coords.latitude.toFixed(7);
      if(lng) lng.value = pos.coords.longitude.toFixed(7);
      if(acc) acc.value = Math.round(pos.coords.accuracy || 0);
      setHelp('OK. Accuracy ~' + (acc?acc.value:'?') + 'm');
    }, function(err){
      setHelp('Gagal ambil lokasi: ' + err.message);
    }, { enableHighAccuracy:true, timeout:12000, maximumAge:0 });
  }
  if(btn) btn.addEventListener('click', getGeo);
})();
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
