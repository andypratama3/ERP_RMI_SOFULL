<?php
// payroll/payroll_repair_run_matrix_absensi.php
// Utility perbaikan run lama: isi ulang THP Matrix Ref, Cuti/Izin/Sakit/Terlambat, dan snapshot karyawan.
// Pakai: /payroll/payroll_repair_run_matrix_absensi.php?run_id=9&do=1

declare(strict_types=1);

require_once __DIR__ . '/_inc/bootstrap.php';

if (function_exists('payroll_ensure_schema')) {
    payroll_ensure_schema($pdo);
}

function r_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function r_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name=? AND column_name=? LIMIT 1");
        $st->execute([$table,$col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function r_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name=? LIMIT 1");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function r_first_col(PDO $pdo, string $table, array $cols): string {
    foreach ($cols as $c) if (r_col_exists($pdo, $table, $c)) return $c;
    return '';
}
function r_alias(PDO $pdo, string $alias, string $table, array $cols, string $as): string {
    $c = r_first_col($pdo, $table, $cols);
    return $c ? ", {$alias}.`{$c}` AS {$as}" : ", NULL AS {$as}";
}
function r_infer_level(string $txt): string {
    $t = strtoupper($txt);
    if (preg_match('/MANAGER|MGR|SUPERVISOR|SPV|KEPALA|HEAD|LEADER/', $t)) return 'MANAGER';
    return 'STAFF';
}
function r_pick_matrix(array $matrixMap, string $status, string $level, string $hint): array {
    $status = payroll_norm_status($status);
    $level = payroll_norm_level($level);

    // Repair tidak boleh fallback ke matrix terbesar.
    // Matrix hanya dipakai kalau status + level cocok persis.
    if ($status !== '' && $level !== '') {
        $k = payroll_matrix_key($status, $level);
        if (isset($matrixMap[$k])) {
            return [$matrixMap[$k], payroll_norm_status($matrixMap[$k]['payroll_status'] ?? $status), payroll_norm_level($matrixMap[$k]['payroll_level'] ?? $level)];
        }
    }

    return [null, $status, $level];
}

function r_absensi_summary(PDO $pdo, int $loginUserId, string $start, string $end, ?string $office): array {
    $out = ['present'=>0,'leave'=>0,'izin'=>0,'sick'=>0,'late'=>0,'source_found'=>0];

    if ($loginUserId <= 0) return $out;

    if (function_exists('payroll_get_absensi_summary')) {
        try {
            $s = payroll_get_absensi_summary($pdo, $loginUserId, $start, $end, $office);
            foreach ($out as $k=>$v) $out[$k] = (int)($s[$k] ?? 0);
        } catch (Throwable $e) {}
    }

    // Tambahan: baca tabel rekap jika ada kolom langsung cuti/izin/sakit/late.
    $tables = ['absensi_rekap','attendance_recap','attendance_daily','absensi_daily','absensi'];
    foreach ($tables as $tbl) {
        if (!r_table_exists($pdo, $tbl)) continue;

        $uidCol = r_first_col($pdo, $tbl, ['login_user_id','user_id']);
        if ($uidCol === '') continue;

        $dateCol = r_first_col($pdo, $tbl, ['attendance_date','tanggal','date','work_date','created_at']);
        $periodCol = r_first_col($pdo, $tbl, ['period_ym','periode','month_ym']);
        if ($dateCol === '' && $periodCol === '') continue;

        $presentCol = r_first_col($pdo, $tbl, ['present','hadir','days_present']);
        $leaveCol   = r_first_col($pdo, $tbl, ['leave_days','cuti','cuti_days']);
        $izinCol    = r_first_col($pdo, $tbl, ['izin_days','izin','permission_days']);
        $sickCol    = r_first_col($pdo, $tbl, ['sick_days','sakit','sakit_days']);
        $lateCol    = r_first_col($pdo, $tbl, ['late_count','late_days','terlambat','late','is_late']);

        if (!$presentCol && !$leaveCol && !$izinCol && !$sickCol && !$lateCol) continue;

        $select = [];
        foreach (['present'=>$presentCol,'leave'=>$leaveCol,'izin'=>$izinCol,'sick'=>$sickCol,'late'=>$lateCol] as $as=>$col) {
            $select[] = $col ? "SUM(CASE WHEN `{$col}` REGEXP '^[0-9]+(\\\\.[0-9]+)?$' THEN `{$col}` ELSE IF(`{$col}` IN ('1','Y','YES','TRUE','LATE','TERLAMBAT'),1,0) END) AS {$as}" : "0 AS {$as}";
        }

        $where = "`{$uidCol}` = ?";
        $params = [$loginUserId];
        if ($dateCol) {
            $where .= " AND DATE(`{$dateCol}`) BETWEEN ? AND ?";
            $params[] = $start; $params[] = $end;
        } else {
            $where .= " AND `{$periodCol}` = ?";
            $params[] = substr($start,0,7);
        }

        try {
            $st = $pdo->prepare("SELECT ".implode(',', $select)." FROM `{$tbl}` WHERE {$where}");
            $st->execute($params);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                foreach ($out as $k=>$v) {
                    if ((int)($r[$k] ?? 0) > $out[$k]) {
                        $out[$k] = (int)$r[$k];
                        if ($k !== 'source_found') $out['source_found'] = 1;
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    return $out;
}

$runId = (int)($_GET['run_id'] ?? 0);
$do = isset($_GET['do']) && $_GET['do'] === '1';

if ($runId <= 0) {
    echo '<h3>Payroll Repair</h3><p>Gunakan: payroll_repair_run_matrix_absensi.php?run_id=9&do=1</p>';
    exit;
}

$st = $pdo->prepare("SELECT * FROM payroll_runs WHERE id=? LIMIT 1");
$st->execute([$runId]);
$run = $st->fetch(PDO::FETCH_ASSOC);
if (!$run) { http_response_code(404); echo 'Run tidak ditemukan.'; exit; }

$period = (string)$run['period_ym'];
$office = $run['office_code'] ? (string)$run['office_code'] : null;
[$start,$end] = payroll_parse_month($period);
$workdaysDefault = payroll_count_workdays($start,$end);
$matrixMap = payroll_load_salary_matrix($pdo, (int)substr($period,0,4));

$extra = "";
$extra .= r_alias($pdo,'e','master_employees',['job_title','position','jabatan','jabatan_name','position_name','title','designation'],'m_job_title');
$extra .= r_alias($pdo,'e','master_employees',['join_date','tgl_masuk','tanggal_masuk','start_date','hire_date','date_joined'],'m_join_date');
$extra .= r_alias($pdo,'e','master_employees',['bank_name','bank','nama_bank','bank_code'],'m_bank_name');
$extra .= r_alias($pdo,'e','master_employees',['bank_account','bank_account_no','no_rekening','rekening','rekening_bank','account_number'],'m_bank_account');

$sql = "SELECT i.*, e.employee_code AS m_employee_code, e.employee_name AS m_employee_name, e.dept_code AS m_dept_code, e.office_code AS m_office_code,
               e.payroll_status AS m_payroll_status, e.payroll_level AS m_payroll_level,
               s.login_user_id AS s_login_user_id, s.pay_type AS s_pay_type, s.salary_basic AS s_salary_basic,
               s.op_rate_day AS s_op_rate_day, s.allowance_position AS s_allowance_position, s.allowance_child AS s_allowance_child,
               s.allowance_transport AS s_allowance_transport, s.allowance_quota AS s_allowance_quota,
               s.allowance_fixed AS s_allowance_fixed, s.deduction_fixed AS s_deduction_fixed, s.overtime_rate_per_hour AS s_overtime_rate_per_hour
               {$extra}
        FROM payroll_run_items i
        JOIN master_employees e ON e.id = i.employee_id
        LEFT JOIN payroll_employee_settings s ON s.employee_id = e.id
        WHERE i.run_id = ?
        ORDER BY e.employee_name ASC";
$st = $pdo->prepare($sql);
$st->execute([$runId]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$rows = [];

foreach ($items as $it) {
    $empId = (int)$it['employee_id'];
    $loginUserId = (int)($it['login_user_id'] ?: ($it['s_login_user_id'] ?? 0));
    $empCode = (string)($it['employee_code'] ?: $it['m_employee_code']);
    $empName = (string)($it['employee_name'] ?: $it['m_employee_name']);
    $dept = (string)($it['dept_code'] ?: $it['m_dept_code']);
    $empOffice = (string)($it['office_code'] ?: $it['m_office_code']);
    $job = (string)($it['job_title'] ?: $it['m_job_title']);
    $join = (string)($it['join_date'] ?: $it['m_join_date']);
    $bank = (string)($it['bank_name'] ?: $it['m_bank_name']);
    $bankAcc = (string)($it['bank_account'] ?: $it['m_bank_account']);

    $status = (string)($it['matrix_status'] ?: $it['m_payroll_status']);
    $level = (string)($it['matrix_level'] ?: $it['m_payroll_level']);
    $hint = $job.' '.$empName.' '.$empCode;
    [$mx,$status,$level] = r_pick_matrix($matrixMap, $status, $level, $hint);

    $wd = $mx ? (int)($mx['work_days_default'] ?? 0) : (int)($it['work_days'] ?? 0);
    if ($wd <= 0) $wd = $workdaysDefault;

    $abs = r_absensi_summary($pdo, $loginUserId, $start, $end, $office);
    $present = (int)$abs['present'];
    $leave = (int)$abs['leave'];
    $izin = (int)$abs['izin'];
    $sick = (int)$abs['sick'];
    $late = (int)$abs['late'];

    $sourceFound = (int)($abs['source_found'] ?? 0);

    // Jika tidak ada data absensi/request, jangan otomatis jadikan ALPA.
    if ($loginUserId <= 0 || $sourceFound <= 0) {
        $present = $wd;
        $leave = $izin = $sick = $late = 0;
    }

    if ($present > $wd) $present = $wd;
    if (($leave+$izin+$sick) > ($wd-$present)) {
        $max = max(0,$wd-$present);
        $leave = min($leave,$max); $max -= $leave;
        $izin = min($izin,$max); $max -= $izin;
        $sick = min($sick,$max);
    }
    $absent = max(0, $wd-$present-$leave-$izin-$sick);

    $hasSetting = array_key_exists('s_salary_basic', $it) && $it['s_salary_basic'] !== null;

    // Payroll Settings menjadi sumber utama. Nilai 0 tetap 0, tidak diganti matrix atau nilai lama yang sudah salah.
    $salaryBasic = $hasSetting ? (float)($it['s_salary_basic'] ?? 0) : ($mx ? (float)($mx['basic_salary'] ?? 0) : (float)($it['salary_basic'] ?? 0));
    $opRate      = $hasSetting ? (float)($it['s_op_rate_day'] ?? 0) : ($mx ? (float)($mx['op_rate_day'] ?? 0) : (float)($it['op_rate_day'] ?? 0));
    $allowPos    = $hasSetting ? (float)($it['s_allowance_position'] ?? 0) : ($mx ? (float)($mx['tunj_jabatan'] ?? 0) : (float)($it['allowance_position'] ?? 0));
    $allowChild  = $hasSetting ? (float)($it['s_allowance_child'] ?? 0) : ($mx ? (float)($mx['tunj_anak'] ?? 0) : (float)($it['allowance_child'] ?? 0));
    $allowTrans  = $hasSetting ? (float)($it['s_allowance_transport'] ?? 0) : ($mx ? (float)($mx['transport'] ?? 0) : (float)($it['allowance_transport'] ?? 0));
    $allowQuota  = $hasSetting ? (float)($it['s_allowance_quota'] ?? 0) : ($mx ? (float)($mx['kuota'] ?? 0) : (float)($it['allowance_quota'] ?? 0));

    $calc = payroll_recalc_amounts([
        'pay_type'=>(string)($it['pay_type'] ?: ($it['s_pay_type'] ?? 'MONTHLY')),
        'salary_basic'=>$salaryBasic,
        'op_rate_day'=>$opRate,
        'allowance_position'=>$allowPos,
        'allowance_child'=>$allowChild,
        'allowance_transport'=>$allowTrans,
        'allowance_quota'=>$allowQuota,
        'allowance_fixed'=>$hasSetting ? (float)($it['s_allowance_fixed'] ?? 0) : (float)($it['allowance_fixed'] ?? 0),
        'deduction_fixed'=>$hasSetting ? (float)($it['s_deduction_fixed'] ?? 0) : (float)($it['deduction_fixed'] ?? 0),
        'overtime_rate_per_hour'=>$hasSetting ? (float)($it['s_overtime_rate_per_hour'] ?? 0) : (float)($it['overtime_rate_per_hour'] ?? 0),
        'overtime_hours'=>(float)($it['overtime_hours'] ?? 0),
        'other_allowance'=>(float)($it['other_allowance'] ?? 0),
        'other_deduction'=>(float)($it['other_deduction'] ?? 0),
        'kasbon_deduction'=>(float)($it['kasbon_deduction'] ?? 0),
        'loan_deduction'=>(float)($it['loan_deduction'] ?? 0),
        'tax_pph21'=>(float)($it['tax_pph21'] ?? 0),
        'bpjs_tk'=>(float)($it['bpjs_tk'] ?? 0),
        'bpjs_kes'=>(float)($it['bpjs_kes'] ?? 0),
        'work_days'=>$wd,
        'days_present'=>$present,
        'absent_days'=>$absent,
    ]);

    if ($do) {
        $up = $pdo->prepare("UPDATE payroll_run_items SET
            login_user_id=?, employee_code=?, employee_name=?, dept_code=?, office_code=?, job_title=?, join_date=?, bank_name=?, bank_account=?,
            matrix_year=?, matrix_status=?, matrix_level=?, matrix_take_home=?,
            work_days=?, days_present=?, leave_days=?, izin_days=?, sick_days=?, absent_days=?, late_count=?,
            salary_basic=?, base_amount=?, op_rate_day=?, op_amount=?,
            allowance_position=?, allowance_child=?, allowance_transport=?, allowance_quota=?,
            gross_pay=?, total_deduction=?, net_pay=?, absence_deduction=?, updated_at=NOW()
            WHERE id=? AND run_id=?");
        $up->execute([
            $loginUserId ?: null, $empCode, $empName, $dept, $empOffice, $job ?: null, ($join && $join !== '0000-00-00') ? $join : null, $bank ?: null, $bankAcc ?: null,
            (int)substr($period,0,4), $status ?: null, $level ?: null, $mx ? (float)($mx['take_home_pay'] ?? 0) : 0,
            $wd,$present,$leave,$izin,$sick,$absent,$late,
            $salaryBasic,$calc['base_amount'],$opRate,$calc['op_amount'],
            $allowPos,$allowChild,$allowTrans,$allowQuota,
            $calc['gross_pay'],$calc['total_deduction'],$calc['net_pay'],$calc['absence_deduction'],
            (int)$it['id'],$runId
        ]);
        $updated++;
    }

    $rows[] = [$empCode,$empName,$loginUserId,$status,$level,($mx ? (float)$mx['take_home_pay'] : 0),$present,$leave,$izin,$sick,$absent,$late];
}

?>
<!doctype html>
<meta charset="utf-8">
<title>Repair Payroll Run</title>
<style>
body{font-family:Arial;margin:24px;background:#f5f5f5} table{border-collapse:collapse;background:#fff;width:100%} td,th{border:1px solid #ddd;padding:7px;font-size:13px} th{background:#eee}.btn{display:inline-block;padding:10px 14px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px}
</style>
<h2>Repair Payroll Run #<?= r_h($runId) ?> - <?= r_h($period) ?></h2>
<p>Status: <?= $do ? '<b>SUDAH DIPERBAIKI</b> sebanyak '.$updated.' item' : 'Preview saja' ?></p>
<p>
  <a class="btn" href="?run_id=<?= r_h($runId) ?>&do=1">Jalankan Repair</a>
  <a class="btn" href="payroll_run.php?id=<?= r_h($runId) ?>">Kembali ke Payroll Run</a>
</p>
<table>
<tr><th>Kode</th><th>Nama</th><th>Login User ID</th><th>Status</th><th>Level</th><th>THP Matrix</th><th>Hadir</th><th>Cuti</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>Terlambat</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
<?php foreach ($r as $c): ?><td><?= r_h($c) ?></td><?php endforeach; ?>
</tr>
<?php endforeach; ?>
</table>
