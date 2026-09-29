<?php
require_once dirname(__DIR__) . '/master/auth.php'; // load auth helpers only

/*
 * FIX PAYROLL INDEX:
 * Jangan panggil require_login() di index payroll.
 * require_login() akan memblokir POST action=create_run melalui RBAC method/action.
 * Login & akses Payroll sudah dijaga di payroll/_inc/bootstrap.php.
 */
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_inc/payroll_sync_helpers.php';

if (!function_exists('payroll_current_dept_code')) {
    function payroll_current_dept_code(): string {
        if (function_exists('auth_dept')) {
            try { return strtoupper(trim((string)auth_dept())); } catch (Throwable $e) {}
        }
        return strtoupper(trim((string)($_SESSION['department'] ?? $_SESSION['dept_code'] ?? '')));
    }
}

if (!function_exists('payroll_current_level_code')) {
    function payroll_current_level_code(): string {
        if (function_exists('auth_level')) {
            try { return strtoupper(trim((string)auth_level())); } catch (Throwable $e) {}
        }
        return strtoupper(trim((string)($_SESSION['level'] ?? $_SESSION['user_level'] ?? $_SESSION['role'] ?? '')));
    }
}

if (!function_exists('payroll_can_create_run')) {
    function payroll_can_create_run(): bool {
        $dept  = payroll_current_dept_code();
        $level = payroll_current_level_code();
        $role  = strtoupper(trim((string)($_SESSION['role'] ?? '')));

        if (in_array($dept, ['SYS'], true) || in_array($level, ['SYS','ADMIN','SUPERADMIN','OWNER'], true) || in_array($role, ['SYS','ADMIN','SUPERADMIN','OWNER'], true)) {
            return true;
        }

        return in_array($dept, ['FIN','HRL'], true) && in_array($level, ['STAFF','MANAGER','MGR'], true);
    }
}

if (!function_exists('payroll_forbidden')) {
    function payroll_forbidden(string $message): void {
        http_response_code(403);
        echo "Forbidden\n\n" . $message;
        exit;
    }
}

if (!function_exists('payroll_first_existing_col')) {
    function payroll_first_existing_col(PDO $pdo, string $table, array $cols): string {
        foreach ($cols as $c) {
            if (function_exists('payroll_column_exists') && payroll_column_exists($pdo, $table, $c)) return $c;
        }
        return '';
    }
}
if (!function_exists('payroll_select_alias')) {
    function payroll_select_alias(PDO $pdo, string $table, string $alias, array $candidates, string $as): string {
        $c = payroll_first_existing_col($pdo, $table, $candidates);
        return $c ? ", {$alias}.`{$c}` AS `{$as}`" : ", NULL AS `{$as}`";
    }
}

if (!function_exists('payroll_infer_level_from_text')) {
    function payroll_infer_level_from_text(string $text): string {
        $t = strtoupper($text);
        if (preg_match('/MANAGER|MGR|SUPERVISOR|SPV|KEPALA|HEAD|LEADER/', $t)) return 'MANAGER';
        return 'STAFF';
    }
}



if (!function_exists('payroll_hrl_overtime_hours')) {
    function payroll_hrl_overtime_hours(PDO $pdo, int $employeeId, string $periodYm): float {
        if ($employeeId <= 0 || !preg_match('/^\d{4}-\d{2}$/',$periodYm)) return 0.0;
        try {
            $st=$pdo->prepare("SELECT employee_code FROM master_employees WHERE id=? LIMIT 1"); $st->execute([$employeeId]);
            $code=trim((string)($st->fetchColumn()?:'')); if($code==='') return 0.0;
            $st=$pdo->prepare("SELECT username FROM master_system_login WHERE holder_employee_code=? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$code]); $username=trim((string)($st->fetchColumn()?:'')); if($username==='') return 0.0;
            $st=$pdo->prepare("SELECT COALESCE(SUM(o.duration_minutes),0)
                FROM hrl_request_overtime o JOIN hrl_requests r ON r.id=o.request_id
                WHERE r.created_by=? AND o.payroll_period=? AND r.deleted_at IS NULL
                  AND UPPER(r.req_type)='LEMBUR' AND UPPER(r.status) IN ('HRL_APPROVED','FIN_APPROVED','PAID')");
            $st->execute([$username,$periodYm]);
            return round(((float)$st->fetchColumn())/60,2);
        } catch(Throwable $e){ return 0.0; }
    }
}

if (!function_exists('payroll_pick_matrix_flexible')) {
    function payroll_pick_matrix_flexible(array $matrixMap, string $status, string $level, string $hintText = '', float $salaryBasic = 0.0): array {
        $status = payroll_norm_status($status);
        $level  = payroll_norm_level($level);
        $job    = $hintText;

        // Gunakan helper baru dari bootstrap jika tersedia: bisa match status+level+job,
        // dan fallback aman dari salary_basic. Ini menghindari Matrix: --- dan OP 0.
        if (function_exists('payroll_find_salary_matrix')) {
            $mx = payroll_find_salary_matrix($matrixMap, $status, $level, $job, $salaryBasic);
            if (is_array($mx)) {
                return [
                    $mx,
                    payroll_norm_status($mx['payroll_status'] ?? $status),
                    payroll_norm_level($mx['payroll_level'] ?? $level)
                ];
            }
        }

        if ($status !== '' && $level !== '') {
            $k = payroll_matrix_key($status, $level);
            if (isset($matrixMap[$k]) && is_array($matrixMap[$k])) {
                return [$matrixMap[$k], payroll_norm_status($matrixMap[$k]['payroll_status'] ?? $status), payroll_norm_level($matrixMap[$k]['payroll_level'] ?? $level)];
            }
        }

        return [null, $status, $level];
    }
}

if (!function_exists('payroll_matrix_or_setting')) {
    function payroll_matrix_or_setting($settingRow, string $col, float $matrixValue): float {
        if (is_array($settingRow) && array_key_exists($col, $settingRow)) {
            $v = (float)($settingRow[$col] ?? 0);
            if ($v > 0) return $v;
        }
        return $matrixValue;
    }
}

$flash = payroll_flash_get();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && function_exists('verify_csrf')) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

// ------------------------- CSV export (items per run) -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['id'])) {
    $runId = (int)$_GET['id'];

    $st = $pdo->prepare("SELECT * FROM payroll_runs WHERE id = ? LIMIT 1");
    $st->execute([$runId]);
    $run = $st->fetch(PDO::FETCH_ASSOC);
    if (!$run) { http_response_code(404); exit("Run tidak ditemukan"); }

    $st = $pdo->prepare("SELECT i.*, e.employee_code, e.employee_name, e.dept_code, e.office_code AS emp_office
                         FROM payroll_run_items i
                         LEFT JOIN master_employees e ON e.id = i.employee_id
                         WHERE i.run_id = ?
                         ORDER BY e.employee_name ASC");
    $st->execute([$runId]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_'.$runId.'_'.$run['period_ym'].'.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'period_ym','office_code','status',
        'employee_code','employee_name','dept_code','emp_office',
        'pay_type','matrix_year','matrix_status','matrix_level','matrix_take_home',
        'work_days','present','leave','izin','sick','absent','late',
        'salary_basic','base_amount',
        'op_rate_day','op_amount',
        'allow_position','allow_child','allow_transport','allow_quota',
        'allowance_fixed','other_allowance',
        'overtime_hours','overtime_rate','overtime_amount',
        'deduction_fixed','other_deduction','late_deduction','kasbon_deduction','loan_deduction','absence_deduction','tax_pph21','bpjs_tk','bpjs_kes',
        'gross_pay','total_deduction','net_pay','note'
    ]);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $run['period_ym'],
            $run['office_code'],
            $run['status'],
            $r['employee_code'],
            $r['employee_name'],
            $r['dept_code'],
            $r['emp_office'],
            $r['pay_type'],
            $r['matrix_year'],
            $r['matrix_status'],
            $r['matrix_level'],
            $r['matrix_take_home'],
            $r['work_days'],
            $r['days_present'],
            $r['leave_days'],
            $r['izin_days'] ?? 0,
            $r['sick_days'] ?? 0,
            $r['absent_days'],
            $r['late_count'] ?? 0,
            $r['salary_basic'],
            $r['base_amount'],
            $r['op_rate_day'],
            $r['op_amount'],
            $r['allowance_position'],
            $r['allowance_child'],
            $r['allowance_transport'],
            $r['allowance_quota'],
            $r['allowance_fixed'],
            $r['other_allowance'],
            $r['overtime_hours'],
            $r['overtime_rate_per_hour'],
            $r['overtime_amount'],
            $r['deduction_fixed'],
            $r['other_deduction'],
            $r['late_deduction'] ?? 0,
            $r['kasbon_deduction'],
            $r['loan_deduction'],
            $r['absence_deduction'],
            $r['tax_pph21'],
            $r['bpjs_tk'],
            $r['bpjs_kes'],
            $r['gross_pay'],
            $r['total_deduction'],
            $r['net_pay'],
            $r['note'],
        ]);
    }
    fclose($out);
    exit;
}

// ------------------------- Create run -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_run') {
    if (!payroll_can_create_run()) {
        payroll_forbidden(
            'PAYROLL_CREATE_RUN_BLOCK method=POST action=create_run level=' . payroll_current_level_code()
            . ' session_dept=' . payroll_current_dept_code()
            . ' allowed_depts=FIN,HRL,SYS allowed_levels=STAFF,MANAGER,SYS'
        );
    }

    $period = trim($_POST['period_ym'] ?? '');
    $office = strtoupper(trim((string)($_POST['office_code'] ?? '')));
    $office = $office !== '' ? $office : null;

    $parsed = function_exists('rmi_payroll_cutoff_period') ? rmi_payroll_cutoff_period($period, 25) : payroll_parse_month($period);
    if (!$parsed) {
        payroll_flash_set('danger', 'Periode wajib format YYYY-MM (contoh 2025-12).');
        rmi_redirect("{$BASE_PAYROLL}/index.php");
    }

    if ($office !== null) {
        try {
            $offSt = $pdo->prepare("SELECT COUNT(*) FROM master_office WHERE UPPER(TRIM(office_code)) = ? AND COALESCE(is_active,1)=1");
            $offSt->execute([$office]);
            if ((int)$offSt->fetchColumn() <= 0) {
                payroll_flash_set('danger', 'Office code tidak ditemukan/ tidak aktif: ' . $office);
                rmi_redirect("{$BASE_PAYROLL}/index.php");
            }
        } catch (Throwable $e) {
            // Jika struktur master_office berbeda, validasi office dilewati.
        }
    }

    $year = (int)substr($period, 0, 4);
    $matrixMap = payroll_load_salary_matrix($pdo, $year);

    if (function_exists('payroll_ensure_schema')) {
        payroll_ensure_schema($pdo);
    }

    try {
        // Create run
        $st = $pdo->prepare("INSERT INTO payroll_runs (period_ym, office_code, status, created_by, created_at)
                             VALUES (?, ?, 'DRAFT', ?, NOW())");
        $st->execute([$period, $office, (int)($_SESSION['user_id'] ?? 0)]);
        $runId = (int)$pdo->lastInsertId();

        // Generate items
        [$start, $end] = $parsed;
        $workdays = function_exists('rmi_payroll_effective_workdays') ? rmi_payroll_effective_workdays($pdo, $start, $end, $office) : payroll_count_workdays($start, $end);

        $sqlEmp = "SELECT id, employee_code, employee_name, dept_code, office_code,
                          payroll_status, payroll_level"
                   . payroll_select_alias($pdo, 'master_employees', 'master_employees', ['job_title','position','jabatan','jabatan_name','position_name','title','designation'], 'job_title')
                   . payroll_select_alias($pdo, 'master_employees', 'master_employees', ['join_date','tgl_masuk','tanggal_masuk','start_date','hire_date','date_joined'], 'join_date')
                   . payroll_select_alias($pdo, 'master_employees', 'master_employees', ['bank_name','bank','nama_bank','bank_code'], 'bank_name')
                   . payroll_select_alias($pdo, 'master_employees', 'master_employees', ['bank_account','bank_account_no','no_rekening','rekening','rekening_bank','account_number'], 'bank_account')
                   . "
                   FROM master_employees
                   WHERE status = 'active'";
        $params = [];
        if ($office) {
            $sqlEmp .= " AND office_code = ?";
            $params[] = $office;
        }
        $sqlEmp .= " ORDER BY employee_name ASC";
        $empSt = $pdo->prepare($sqlEmp);
        $empSt->execute($params);

        // preload settings
        $setSt = $pdo->query("SELECT * FROM payroll_employee_settings");
        $settingsByEmp = [];
        while ($s = $setSt->fetch(PDO::FETCH_ASSOC)) {
            $settingsByEmp[(int)$s['employee_id']] = $s;
        }

        $ins = $pdo->prepare("INSERT INTO payroll_run_items
            (run_id, employee_id, login_user_id,
             employee_code, employee_name, dept_code, office_code, job_title, join_date, bank_name, bank_account,
             pay_type,
             matrix_year, matrix_status, matrix_level, matrix_take_home,
             work_days, days_present, leave_days, izin_days, sick_days, absent_days, late_count, late_deduction,
             salary_basic, base_amount,
             op_rate_day, op_amount,
             allowance_position, allowance_child, allowance_transport, allowance_quota,
             allowance_fixed, deduction_fixed, overtime_rate_per_hour,
             overtime_hours, overtime_amount,
             other_allowance, other_deduction,
             absence_deduction, kasbon_deduction, loan_deduction, tax_pph21, bpjs_tk, bpjs_kes,
             gross_pay, total_deduction, net_pay,
             created_at)
            VALUES
            (?, ?, ?,
             ?, ?, ?, ?, ?, ?, ?, ?,
             ?,
             ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?, ?, ?,
             ?, ?,
             ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?,
             ?, ?,
             0, 0,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?,
             NOW())");

        $count = 0;
        while ($e = $empSt->fetch(PDO::FETCH_ASSOC)) {
            $empId = (int)$e['id'];
            $s = $settingsByEmp[$empId] ?? null;

            $loginUserId = $s ? (int)($s['login_user_id'] ?? 0) : 0;
            $loginUserId = $loginUserId > 0 ? $loginUserId : null;

            $payType = strtoupper($s['pay_type'] ?? 'MONTHLY');
            if (!in_array($payType, ['MONTHLY','DAILY'], true)) $payType = 'MONTHLY';

            // ---- salary matrix lookup fleksibel ----
            // Ambil dari master_employees. Jika kosong, sistem infer dari jabatan/nama.
            $empStatus = payroll_norm_status($e['payroll_status'] ?? '');
            $empLevel  = payroll_norm_level($e['payroll_level'] ?? '');
            $hintText  = trim((string)($e['job_title'] ?? '') . ' ' . (string)($e['employee_name'] ?? '') . ' ' . (string)($e['employee_code'] ?? ''));

            $hasSetting = is_array($s);
            $settingBasicForMatrix = $hasSetting ? (float)($s['salary_basic'] ?? 0) : 0.0;
            [$mx, $empStatus, $empLevel] = payroll_pick_matrix_flexible($matrixMap, $empStatus, $empLevel, $hintText, $settingBasicForMatrix);

            $mxTakeHome = $mx ? (float)($mx['take_home_pay'] ?? 0) : 0.0;
            $mxBasic    = $mx ? (float)($mx['basic_salary'] ?? 0) : 0.0;
            $mxOpRate   = $mx ? (float)($mx['op_rate_day'] ?? 0) : 0.0;
            $mxWd       = $mx ? (int)($mx['work_days_default'] ?? 0) : 0;

            $mxJabatan  = $mx ? (float)($mx['tunj_jabatan'] ?? 0) : 0.0;
            $mxAnak     = $mx ? (float)($mx['tunj_anak'] ?? 0) : 0.0;
            $mxTrans    = $mx ? (float)($mx['transport'] ?? 0) : 0.0;
            $mxKuota    = $mx ? (float)($mx['kuota'] ?? 0) : 0.0;

            // ---- Payroll Settings tetap boleh override, tetapi nilai 0 tidak boleh
            // menimpa Salary Matrix. Inilah penyebab OP/day menjadi 0.
            $salaryBasic = $hasSetting ? (float)($s['salary_basic'] ?? 0) : $mxBasic;
            if ($salaryBasic <= 0 && $mxBasic > 0) $salaryBasic = $mxBasic;

            $opRateDay   = payroll_matrix_or_setting($s, 'op_rate_day', $mxOpRate);
            $allowPos    = payroll_matrix_or_setting($s, 'allowance_position', $mxJabatan);
            $allowChild  = payroll_matrix_or_setting($s, 'allowance_child', $mxAnak);
            $allowTrans  = payroll_matrix_or_setting($s, 'allowance_transport', $mxTrans);
            $allowQuota  = payroll_matrix_or_setting($s, 'allowance_quota', $mxKuota);

            $allowFixed  = $hasSetting ? (float)($s['allowance_fixed'] ?? 0) : 0.0;
            $dedFixed    = $hasSetting ? (float)($s['deduction_fixed'] ?? 0) : 0.0;
            $otRate      = $hasSetting ? (float)($s['overtime_rate_per_hour'] ?? 0) : 0.0;

            // attendance: ambil snapshot resmi. Bila sumber/mapping belum tersedia,
            // jangan membuat hadir penuh atau alpa penuh secara fiktif.
            $wd = (!$hasSetting && $mxWd > 0) ? $mxWd : $workdays;
            $att = rmi_payroll_attendance_snapshot($pdo, (int)($loginUserId ?? 0), $empId, $start, $end, $wd, $office);
            if ((int)$att['source_found'] > 0) {
                $present=(int)$att['present']; $leave=(int)$att['leave']; $izin=(int)$att['izin'];
                $sick=(int)$att['sick']; $absent=(int)$att['absent']; $late=(int)$att['late'];
            } else {
                $present=0; $leave=0; $izin=0; $sick=0; $absent=0; $late=0;
            }
            $lateCalc = function_exists('payroll_calc_late_deduction') && $loginUserId
                ? payroll_calc_late_deduction($pdo,(int)$loginUserId,$start,$end,$office)
                : ['late_count'=>$late,'late_deduction'=>0];
            $late=max($late,(int)($lateCalc['late_count']??0));
            $lateDed=(float)($lateCalc['late_deduction']??0);

            // loans/kasbon installment for this period
            $loanCalc = payroll_calc_loan_deductions($pdo, $empId, $period);
            $kasbonDed = (float)($loanCalc['kasbon'] ?? 0);
            $loanDed   = (float)($loanCalc['loan'] ?? 0);

            $approvedOtHours = rmi_payroll_overtime_hours($pdo, $empId, $period);
            $statutory = rmi_payroll_setting_amounts($pdo, $empId, [
                'salary_basic'=>$salaryBasic,'allowance_fixed'=>$allowFixed,'period_ym'=>$period
            ]);
            if ($otRate <= 0) $otRate = (float)$statutory['overtime_rate_per_hour'];
            $taxPph21=(float)$statutory['tax_pph21'];
            $bpjsTk=(float)$statutory['bpjs_tk'];
            $bpjsKes=(float)$statutory['bpjs_kes'];
            if ($dedFixed <= 0 && (float)$statutory['deduction_fixed'] > 0) $dedFixed=(float)$statutory['deduction_fixed'];

            $calc = payroll_recalc_amounts([
                'pay_type'=>$payType,
                'salary_basic'=>$salaryBasic,

                'op_rate_day'=>$opRateDay,
                'allowance_position'=>$allowPos,
                'allowance_child'=>$allowChild,
                'allowance_transport'=>$allowTrans,
                'allowance_quota'=>$allowQuota,

                'allowance_fixed'=>$allowFixed,
                'deduction_fixed'=>$dedFixed,

                'overtime_rate_per_hour'=>$otRate,
                'overtime_hours'=>$approvedOtHours,
                'other_allowance'=>0,
                'other_deduction'=>0,
                'kasbon_deduction'=>$kasbonDed,
                'loan_deduction'=>$loanDed,
                'late_deduction'=>$lateDed,
                'tax_pph21'=>$taxPph21,'bpjs_tk'=>$bpjsTk,'bpjs_kes'=>$bpjsKes,
                'work_days'=>$wd,
                'days_present'=>$present,
                'absent_days'=>$absent
            ]);

            $ins->execute([
                $runId,
                $empId,
                $loginUserId,
                (string)($e['employee_code'] ?? ''),
                (string)($e['employee_name'] ?? ''),
                (string)($e['dept_code'] ?? ''),
                (string)($e['office_code'] ?? ''),
                (string)($e['job_title'] ?? ''),
                !empty($e['join_date']) ? $e['join_date'] : null,
                (string)($e['bank_name'] ?? ''),
                (string)($e['bank_account'] ?? ''),
                $payType,

                $year,
                $empStatus ?: null,
                $empLevel ?: null,
                $mxTakeHome,

                $wd,
                $present,
                $leave,
                $izin,
                $sick,
                $absent,
                $late,
                $lateDed,

                $salaryBasic,
                $calc['base_amount'],

                $opRateDay,
                $calc['op_amount'],

                $allowPos,
                $allowChild,
                $allowTrans,
                $allowQuota,

                $allowFixed,
                $dedFixed,
                $otRate,
                $approvedOtHours,
                $calc['overtime_amount'],

                $calc['absence_deduction'],
                $kasbonDed,
                $loanDed,
                $taxPph21,
                $bpjsTk,
                $bpjsKes,
                $calc['gross_pay'],
                $calc['total_deduction'],
                $calc['net_pay'],
            ]);

            $count++;
        }

        // audit
        erp_audit($pdo, 'PAYROLL', 'RUN#'.$runId, 'create_run', [
            'period'=>$period,
            'office_code'=>$office,
            'items'=>$count,
            'matrix_year'=>$year
        ]);
        if (function_exists('master_audit')) {
            master_audit($pdo, 'payroll', 'payroll_runs', 'CREATE', $runId, "RUN#{$runId}", "Payroll run created: {$period} ({$office}) {$count} items", ['period' => $period, 'office_code' => $office, 'items' => $count]);
        }

        payroll_flash_set('success', "Payroll Run berhasil dibuat (ID: {$runId}) dengan {$count} karyawan.");
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");

    } catch (Throwable $e) {
        if (function_exists('rmi_log_module_error')) {
            rmi_log_module_error('payroll', $e, ['action' => 'PAYROLL_RUN_CREATE', 'period' => $period ?? '', 'office' => $office ?? '']);
        }
        payroll_flash_set('danger', 'Gagal membuat payroll run: ' . $e->getMessage());
        rmi_redirect("{$BASE_PAYROLL}/index.php");
    }
}

// ------------------------- List runs -------------------------
$st = $pdo->query("SELECT r.*,
    (SELECT COUNT(*) FROM payroll_run_items i WHERE i.run_id = r.id) AS items_count,
    (SELECT COALESCE(SUM(i.net_pay),0) FROM payroll_run_items i WHERE i.run_id = r.id) AS total_net
  FROM payroll_runs r
  ORDER BY r.id DESC
  LIMIT 50");
$runs = $st->fetchAll(PDO::FETCH_ASSOC);

rmi_header('Payroll Dashboard', 'payroll', [
    'base_project' => $BASE_PROJECT,
    'actions'      => [
        ['label' => '' . rmi_icon("books") . ' Panduan', 'url' => $BASE_PAYROLL . '/panduan.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<?php if ($flash): ?>
  <div class="alert alert-<?= payroll_h($flash['type']) ?>"><?= payroll_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card rmi-card">
      <div class="card-header">
        <div class="fw-bold">Buat Payroll Run</div>
        <div class="rmi-muted" style="font-size:12px;">Generate payroll per periode (YYYY-MM)</div>
      </div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
          <input type="hidden" name="action" value="create_run">
          <div class="col-12">
            <label class="form-label">Periode</label>
            <input type="month" name="period_ym" class="form-control" required>
            <div class="rmi-muted mt-1" style="font-size:12px;">Cutoff periode 26–25. Contoh periode 2026-07 membaca absensi 26-06-2026 s.d. 25-07-2026.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Office Code (opsional)</label>
            <input type="text" name="office_code" class="form-control" placeholder="Contoh: BGR / BKS / TGR / MLG">
          </div>
          <div class="col-12 d-grid mt-2">
            <button class="btn btn-rmi" type="submit">Generate Run</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card rmi-card mt-3">
      <div class="card-body">
        <div class="fw-bold">Menu Payroll</div>
        <div class="rmi-muted" style="font-size:12px;">Settings, master golongan gaji, pinjaman/kasbon, dan audit log.</div>
        <div class="mt-2 d-grid gap-2">
          <a class="btn btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/payroll_settings.php">Buka Payroll Settings</a>
          <a class="btn btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/salary_matrix.php">Master Golongan Gaji</a>
          <a class="btn btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/loans.php">Pengajuan Pinjaman / Kasbon</a>
          <a class="btn btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/audit.php">Audit Log</a>
          <a class="btn btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/panduan.php"><?=rmi_icon('books')?> Panduan Payroll</a>
        </div>
      </div>
    </div>

  </div>

  <div class="col-12 col-lg-7">
    <div class="card rmi-card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <div class="fw-bold">History Payroll Runs</div>
        <div class="rmi-muted" style="font-size:12px;"><?= count($runs) ?> terakhir</div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm rmi-table mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Periode</th>
                <th>Office</th>
                <th>Status</th>
                <th class="text-end">Items</th>
                <th class="text-end">Total Net</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$runs): ?>
                <tr><td colspan="7" class="text-center rmi-muted py-4">Belum ada payroll run.</td></tr>
              <?php else: foreach ($runs as $r):
                [$badgeClass,$badgeText] = payroll_badge_status((string)$r['status']);
              ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= payroll_h($r['period_ym']) ?></td>
                  <td><?= payroll_h($r['office_code'] ?? '-') ?></td>
                  <td><span class="badge rmi-badge <?= payroll_h($badgeClass) ?>"><?= payroll_h($badgeText) ?></span></td>
                  <td class="text-end"><?= (int)$r['items_count'] ?></td>
                  <td class="text-end"><?= number_format((float)$r['total_net'], 2) ?></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/payroll_run.php?id=<?= (int)$r['id'] ?>">Open</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/index.php?export=csv&id=<?= (int)$r['id'] ?>">CSV</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
