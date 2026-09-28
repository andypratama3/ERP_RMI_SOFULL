<?php
// payroll/payslip.php
// FIX FINAL: baca snapshot payroll_run_items + fallback master_employees untuk jabatan/tgl masuk/bank/rek dan absensi.

declare(strict_types=1);

require_once __DIR__ . '/_inc/bootstrap.php';

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    http_response_code(400);
    echo "Item payslip tidak valid.";
    exit;
}

function ps_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ps_money($n): string { return number_format((float)$n, 0, ',', '.'); }
function ps_val($v, string $empty='Belum diisi di Master Karyawan'): string {
    $s = trim((string)$v);
    return $s !== '' && $s !== '0000-00-00' ? $s : $empty;
}
function ps_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
        $st->execute([$table, $col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function ps_first_col(PDO $pdo, string $table, array $cols): string {
    foreach ($cols as $c) if (ps_col_exists($pdo, $table, $c)) return $c;
    return '';
}
function ps_select_extra(PDO $pdo, string $alias, string $table, array $cols, string $as): string {
    $c = ps_first_col($pdo, $table, $cols);
    return $c ? ", {$alias}.`{$c}` AS {$as}" : ", NULL AS {$as}";
}

$extraEmp = "";
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['job_title','position','jabatan','jabatan_name','position_name','title','designation'], 'm_job_title');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['level_type'], 'm_level_type');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['grade'], 'm_grade');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['join_date','tgl_masuk','tanggal_masuk','start_date','hire_date','date_joined'], 'm_join_date');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['join_year'], 'm_join_year');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['join_month'], 'm_join_month');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['bank_name','bank','nama_bank','bank_code'], 'm_bank_name');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['bank_branch'], 'm_bank_branch');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['bank_account_name','account_name','nama_rekening'], 'm_bank_account_name');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['bank_account_number','bank_account','bank_account_no','no_rekening','rekening','rekening_bank','account_number'], 'm_bank_account');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['nik'], 'm_nik');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['npwp'], 'm_npwp');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['bpjs_tk_no'], 'm_bpjs_tk_no');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['bpjs_kes_no'], 'm_bpjs_kes_no');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['education'], 'm_education');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['phone'], 'm_phone');
$extraEmp .= ps_select_extra($pdo, 'e', 'master_employees', ['email'], 'm_email');

$sql = "SELECT i.*, r.period_ym, r.office_code AS run_office, r.status AS run_status, r.posted_at, r.paid_at, r.payment_reference, r.payment_note,
               COALESCE(NULLIF(i.employee_code,''), e.employee_code) AS x_employee_code,
               COALESCE(NULLIF(i.employee_name,''), e.employee_name) AS x_employee_name,
               COALESCE(NULLIF(i.dept_code,''), e.dept_code) AS x_dept_code,
               COALESCE(NULLIF(i.office_code,''), e.office_code) AS x_office_code,
               COALESCE(NULLIF(i.job_title,''), NULL) AS x_job_title,
               COALESCE(i.join_date, NULL) AS x_join_date,
               COALESCE(NULLIF(i.bank_name,''), NULL) AS x_bank_name,
               COALESCE(NULLIF(i.bank_account,''), NULL) AS x_bank_account
               {$extraEmp}
        FROM payroll_run_items i
        JOIN payroll_runs r ON r.id = i.run_id
        LEFT JOIN master_employees e ON e.id = i.employee_id
        WHERE i.id = ?
        LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute([$itemId]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo "Payslip tidak ditemukan.";
    exit;
}

$runId = (int)($row['run_id'] ?? 0);
$backUrl = $runId > 0 ? "payroll_run.php?id={$runId}" : "index.php";

$empCode = ps_val($row['x_employee_code'] ?? '', '-');
$empName = ps_val($row['x_employee_name'] ?? '', 'Karyawan #' . (int)($row['employee_id'] ?? 0));
$dept    = ps_val($row['x_dept_code'] ?? '', '-');
$office  = ps_val($row['x_office_code'] ?? ($row['run_office'] ?? ''), '-');

$jobTitle = trim((string)($row['x_job_title'] ?? ''));
if ($jobTitle === '') $jobTitle = trim((string)($row['m_job_title'] ?? ''));
if ($jobTitle === '') {
    $level = trim((string)($row['m_level_type'] ?? ''));
    $grade = trim((string)($row['m_grade'] ?? ''));
    $jobTitle = trim($level . ($grade !== '' ? ' ' . $grade : ''));
}

$joinDate = trim((string)($row['x_join_date'] ?? ''));
if ($joinDate === '' || $joinDate === '0000-00-00') $joinDate = trim((string)($row['m_join_date'] ?? ''));
if ($joinDate === '' || $joinDate === '0000-00-00') {
    $joinYear = preg_replace('/\D/', '', (string)($row['m_join_year'] ?? ''));
    $joinMonth = preg_replace('/\D/', '', (string)($row['m_join_month'] ?? ''));
    if (strlen($joinYear) === 4 && (int)$joinMonth >= 1 && (int)$joinMonth <= 12) {
        $joinDate = $joinYear . '-' . str_pad((string)(int)$joinMonth, 2, '0', STR_PAD_LEFT) . '-01';
    }
}
if ($joinDate !== '' && $joinDate !== '0000-00-00') {
    $tsJoin = strtotime($joinDate);
    if ($tsJoin !== false) $joinDate = date('d-m-Y', $tsJoin);
}

$bankName = trim((string)($row['x_bank_name'] ?? ''));
if ($bankName === '') $bankName = trim((string)($row['m_bank_name'] ?? ''));
$bankBranch = trim((string)($row['m_bank_branch'] ?? ''));
$bankAccountName = trim((string)($row['m_bank_account_name'] ?? ''));

$bankAccount = trim((string)($row['x_bank_account'] ?? ''));
if ($bankAccount === '') $bankAccount = trim((string)($row['m_bank_account'] ?? ''));

$nik = trim((string)($row['m_nik'] ?? ''));
$npwp = trim((string)($row['m_npwp'] ?? ''));
$bpjsTkNo = trim((string)($row['m_bpjs_tk_no'] ?? ''));
$bpjsKesNo = trim((string)($row['m_bpjs_kes_no'] ?? ''));
$education = trim((string)($row['m_education'] ?? ''));
$phone = trim((string)($row['m_phone'] ?? ''));
$email = trim((string)($row['m_email'] ?? ''));

$period = (string)($row['period_ym'] ?? '-');

$workDays = (int)($row['work_days'] ?? 0);
$present = (int)($row['days_present'] ?? 0);
$leave = (int)($row['leave_days'] ?? 0);
$izin = (int)($row['izin_days'] ?? 0);
$sick = (int)($row['sick_days'] ?? 0);
$absent = (int)($row['absent_days'] ?? 0);
$late = (int)($row['late_count'] ?? 0);
$lateDeduction = (float)($row['late_deduction'] ?? 0);

$attendancePercent = $workDays > 0 ? round(($present / $workDays) * 100, 2) : 0;
$absencePercent = $workDays > 0 ? round(($absent / $workDays) * 100, 2) : 0;
$attendanceTotal = $present + $leave + $izin + $sick + $absent;
$attendanceValid = $workDays > 0 && $attendanceTotal === $workDays;

$earnings = [
    'Gaji Pokok / Base' => (float)($row['base_amount'] ?? $row['salary_basic'] ?? 0),
    'OP Harian' => (float)($row['op_amount'] ?? 0),
    'Tunjangan Jabatan' => (float)($row['allowance_position'] ?? 0),
    'Tunjangan Anak' => (float)($row['allowance_child'] ?? 0),
    'Transport' => (float)($row['allowance_transport'] ?? 0),
    'Kuota' => (float)($row['allowance_quota'] ?? 0),
    'Tunjangan Lainnya (Parkir,Pengiriman,Bonus)' => (float)($row['allowance_fixed'] ?? 0),
    'Tunjangan Lain' => (float)($row['other_allowance'] ?? 0),
    'Lembur' => (float)($row['overtime_amount'] ?? 0),
];
$deductions = [
    'Potongan Lainnya (barang kosong, barang minus)' => (float)($row['deduction_fixed'] ?? 0),
    'Potongan Absensi' => (float)($row['absence_deduction'] ?? 0),
    'Potongan Terlambat' => $lateDeduction,
    'Kasbon' => (float)($row['kasbon_deduction'] ?? 0),
    'Pinjaman' => (float)($row['loan_deduction'] ?? 0),
    'PPh 21' => (float)($row['tax_pph21'] ?? 0),
    'BPJS TK' => (float)($row['bpjs_tk'] ?? 0),
    'BPJS Kesehatan' => (float)($row['bpjs_kes'] ?? 0),
    'Potongan Lain' => (float)($row['other_deduction'] ?? 0),
];

$gross = (float)($row['gross_pay'] ?? array_sum($earnings));
$calcDed = array_sum($deductions);
$totalDed = array_key_exists('total_deduction', $row) ? (float)$row['total_deduction'] : (float)$calcDed;
$net = array_key_exists('net_pay', $row) ? (float)$row['net_pay'] : ($gross - $totalDed);

// Payslip adalah pembaca snapshot payroll, bukan kalkulator kedua.
// Bila snapshot tidak konsisten, tampilkan peringatan dan perbaiki melalui Payroll Run.
$deductionMismatch = abs($totalDed - $calcDed) > 0.01;
$netMismatch = abs($net - ($gross - $totalDed)) > 0.01;
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payslip <?= ps_h($period) ?> - <?= ps_h($empName) ?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;color:#111827;margin:0;padding:24px}
.sheet{max-width:980px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,.08)}
.top{display:flex;justify-content:space-between;gap:20px;border-bottom:3px solid #1d4ed8;padding-bottom:16px;margin-bottom:20px}
h1{font-size:26px;margin:0 0 6px}.muted{color:#6b7280;font-size:13px}
.badge{display:inline-block;padding:5px 10px;border-radius:999px;background:#dbeafe;color:#1e40af;font-size:12px;font-weight:bold}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.grid3{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
table{width:100%;border-collapse:collapse;margin-top:10px}th,td{padding:9px 10px;border-bottom:1px solid #e5e7eb;font-size:14px;vertical-align:top}
th{text-align:left;background:#f9fafb}td.money{text-align:right;font-variant-numeric:tabular-nums}
.summary{margin-top:18px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}.box{border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#f9fafb}
.box b{display:block;font-size:20px;margin-top:4px}.net{background:#ecfdf5;border-color:#86efac}
.actions{position:sticky;top:0;z-index:5;text-align:right;margin:-8px 0 14px;padding:10px 0;background:#f3f4f6}
button,a.btn{border:0;border-radius:8px;padding:10px 14px;background:#2563eb;color:#fff;text-decoration:none;cursor:pointer;display:inline-block}.small{font-size:12px;color:#6b7280}
@media(max-width:900px){.grid,.grid3,.summary{grid-template-columns:1fr}.top{display:block}}@media print{body{background:#fff;padding:0}.sheet{box-shadow:none;border:0;border-radius:0}.actions{display:none}}
</style>
</head>
<body>
<div class="actions">
    <button type="button" onclick="window.print()">Print Payslip</button>
    <a class="btn" href="<?= ps_h($backUrl) ?>">Kembali</a>
</div>
<div class="sheet">
    <div class="top">
        <div><h1>PAYSLIP / SLIP GAJI</h1><div class="muted">Rizqullah Mediska Indonesia</div></div>
        <div style="text-align:right"><span class="badge"><?= ps_h(strtoupper((string)($row['run_status'] ?? 'DRAFT'))) ?></span><div class="muted" style="margin-top:8px">Periode: <b><?= ps_h($period) ?></b></div><div class="muted">Run ID: <?= $runId ?> / Item ID: <?= (int)$itemId ?></div><?php if (strtoupper((string)($row['run_status'] ?? '')) === 'PAID'): ?><div class="muted">Dibayar: <b><?= ps_h($row['paid_at'] ?? '-') ?></b> · Ref: <b><?= ps_h($row['payment_reference'] ?? '-') ?></b></div><?php endif; ?></div>
    </div>

    <?php if (strtoupper((string)($row['run_status'] ?? 'DRAFT')) === 'DRAFT'): ?><div style="padding:10px 12px;border:1px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:8px;margin-bottom:14px">Preview DRAFT — belum menjadi slip final dan masih dapat berubah.</div><?php elseif (strtoupper((string)($row['run_status'] ?? '')) === 'POSTED'): ?><div style="padding:10px 12px;border:1px solid #3b82f6;background:#eff6ff;color:#1e40af;border-radius:8px;margin-bottom:14px">Payroll sudah POSTED dan terkunci, tetapi pembayaran belum ditandai PAID.</div><?php else: ?><div style="padding:10px 12px;border:1px solid #22c55e;background:#ecfdf5;color:#166534;border-radius:8px;margin-bottom:14px">Slip final — payroll telah dibayar.</div><?php endif; ?>

    <div class="grid">
        <div>
            <table>
                <tr><th colspan="2">Data Karyawan</th></tr>
                <tr><td>Kode Karyawan</td><td><?= ps_h($empCode) ?></td></tr>
                <tr><td>Nama Karyawan</td><td><b><?= ps_h($empName) ?></b></td></tr>
                <tr><td>Departemen</td><td><?= ps_h($dept) ?></td></tr>
                <tr><td>Office / Cabang</td><td><?= ps_h($office) ?></td></tr>
                <tr><td>Jabatan</td><td><?= ps_h(ps_val($jobTitle)) ?></td></tr>
                <tr><td>Tanggal Masuk</td><td><?= ps_h(ps_val($joinDate)) ?></td></tr>
                <tr><td>Bank / Rekening</td><td>
                    <?= ps_h(ps_val($bankName)) ?><?= $bankBranch !== '' ? ' - ' . ps_h($bankBranch) : '' ?> / <?= ps_h(ps_val($bankAccount)) ?>
                    <?= $bankAccountName !== '' ? '<div class="small">a.n. ' . ps_h($bankAccountName) . '</div>' : '' ?>
                </td></tr>
                <tr><td>NIK / NPWP</td><td><?= ps_h(ps_val($nik, '-')) ?> / <?= ps_h(ps_val($npwp, '-')) ?></td></tr>
                <tr><td>No. BPJS</td><td>TK: <?= ps_h(ps_val($bpjsTkNo, '-')) ?> / Kes: <?= ps_h(ps_val($bpjsKesNo, '-')) ?></td></tr>
                <tr><td>Pendidikan</td><td><?= ps_h(ps_val($education, '-')) ?></td></tr>
                <tr><td>Kontak</td><td><?= ps_h(ps_val($phone, '-')) ?><?= $email !== '' ? '<br>' . ps_h($email) : '' ?></td></tr>
            </table>
        </div>
        <div>
            <table>
                <tr><th colspan="2">Absensi & Payroll</th></tr>
                <tr><td>Tipe Gaji</td><td><?= ps_h((string)($row['pay_type'] ?? 'MONTHLY')) ?></td></tr>
                <tr><td>Periode Payroll</td><td><?= ps_h($period) ?></td></tr>
                <tr><td>Hari Kerja</td><td><?= $workDays ?></td></tr>
                <tr><td>Hadir</td><td><?= $present ?> hari (<?= ps_h((string)$attendancePercent) ?>%)</td></tr>
                <tr><td>Cuti</td><td><?= $leave ?> hari</td></tr>
                <tr><td>Izin</td><td><?= $izin ?> hari</td></tr>
                <tr><td>Sakit</td><td><?= $sick ?> hari</td></tr>
                <tr><td>Alpa</td><td><?= $absent ?> hari (<?= ps_h((string)$absencePercent) ?>%)</td></tr>
                <tr><td>Terlambat</td><td><?= $late ?> kali<?= $lateDeduction > 0 ? " / Potongan Rp " . ps_money($lateDeduction) : "" ?></td></tr>
                <tr><td>Matrix Payroll</td><td><?= ps_h((string)($row['matrix_status'] ?? '-')) ?> - <?= ps_h((string)($row['matrix_level'] ?? '-')) ?></td></tr>
                <tr><td>THP Matrix Ref.</td><td>Rp <?= ps_money((float)($row['matrix_take_home'] ?? 0)) ?></td></tr>
                <tr><td>Catatan</td><td><?= ps_h((string)($row['note'] ?? '-')) ?></td></tr>
            </table>
        </div>
    </div>

    <div class="grid3" style="margin-top:18px">
        <div class="box"><div class="small">OP Harian</div><b>Rp <?= ps_money((float)($row['op_rate_day'] ?? 0)) ?> x <?= $present ?></b></div>
        <div class="box"><div class="small">Lembur</div><b><?= ps_money((float)($row['overtime_hours'] ?? 0)) ?> jam x Rp <?= ps_money((float)($row['overtime_rate_per_hour'] ?? 0)) ?></b><div class="small">Total: Rp <?= ps_money((float)($row['overtime_amount'] ?? 0)) ?></div></div>
        <div class="box"><div class="small">Potongan Absensi</div><b>Rp <?= ps_money((float)($row['absence_deduction'] ?? 0)) ?></b></div>
        <div class="box"><div class="small">Potongan Terlambat</div><b>Rp <?= ps_money($lateDeduction) ?></b></div>
    </div>

    <div class="grid" style="margin-top:18px">
        <div><table><tr><th>Pendapatan</th><th style="text-align:right">Nominal</th></tr>
            <?php foreach ($earnings as $label => $amount): if (abs((float)$amount) > 0.00001): ?>
            <tr><td><?= ps_h($label) ?></td><td class="money">Rp <?= ps_money($amount) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr><th>Gross Pay</th><th style="text-align:right">Rp <?= ps_money($gross) ?></th></tr>
        </table></div>
        <div><table><tr><th>Potongan</th><th style="text-align:right">Nominal</th></tr>
            <?php foreach ($deductions as $label => $amount): if (abs((float)$amount) > 0.00001): ?>
            <tr><td><?= ps_h($label) ?></td><td class="money">Rp <?= ps_money($amount) ?></td></tr>
            <?php endif; endforeach; ?>
            <tr><th>Total Potongan</th><th style="text-align:right">Rp <?= ps_money($totalDed) ?></th></tr>
        </table></div>
    </div>


    <?php if (!$attendanceValid): ?>
    <div style="margin-top:14px;padding:10px 12px;border:1px solid #ef4444;background:#fef2f2;color:#991b1b;border-radius:10px;font-size:13px">
        Data absensi belum konsisten: Hari Kerja <?= $workDays ?>, sedangkan Hadir+Cuti+Izin+Sakit+Alpa = <?= $attendanceTotal ?> hari.
        Payslip belum boleh difinalkan sebelum menjalankan <b>Sync Komponen Payroll</b> dan memperbaiki mapping absensi karyawan.
    </div>
    <?php endif; ?>

    <?php if ((float)($row['overtime_hours'] ?? 0) > 0 && (float)($row['overtime_amount'] ?? 0) <= 0): ?>
    <div style="margin-top:14px;padding:10px 12px;border:1px solid #fbbf24;background:#fffbeb;color:#92400e;border-radius:10px;font-size:13px">
        Lembur tercatat <?= ps_money((float)($row['overtime_hours'] ?? 0)) ?> jam tetapi nominal lembur masih Rp 0. Periksa tarif lembur/master payroll.
    </div>
    <?php endif; ?>

    <?php if ($deductionMismatch || $netMismatch): ?>
    <div style="margin-top:14px;padding:10px 12px;border:1px solid #ef4444;background:#fef2f2;color:#991b1b;border-radius:10px;font-size:13px">
        Snapshot payroll belum konsisten.
        <?= $deductionMismatch ? 'Total potongan tersimpan berbeda dari jumlah rincian potongan. ' : '' ?>
        <?= $netMismatch ? 'Net pay tersimpan berbeda dari Gross Pay dikurangi Total Potongan.' : '' ?>
        Jalankan <b>Sync Komponen Payroll</b> atau simpan ulang item pada Payroll Run sebelum finalisasi.
    </div>
    <?php endif; ?>

    <?php if ((float)($row['tax_pph21'] ?? 0) <= 0 || ((float)($row['bpjs_tk'] ?? 0) <= 0 && (float)($row['bpjs_kes'] ?? 0) <= 0)): ?>
    <div style="margin-top:14px;padding:10px 12px;border:1px solid #fbbf24;background:#fffbeb;color:#92400e;border-radius:10px;font-size:13px">
        Nilai PPh 21/BPJS pada snapshot masih Rp 0. Nilai nol dapat sah, tetapi harus dipastikan melalui konfigurasi master atau kalkulator resmi sebelum payroll difinalkan.
    </div>
    <?php endif; ?>

    <?php if ($late > 0 && $lateDeduction <= 0): ?>
    <div style="margin-top:14px;padding:10px 12px;border:1px solid #fbbf24;background:#fffbeb;color:#92400e;border-radius:10px;font-size:13px">
        Catatan: karyawan tercatat terlambat <?= $late ?> kali, tetapi potongan terlambat masih Rp 0.
        Jalankan tombol <b>Sync Potongan Telat</b> di Payroll Run bila potongan telat memang akan diterapkan.
    </div>
    <?php endif; ?>

    <div class="summary">
        <div class="box">Gross Pay<b>Rp <?= ps_money($gross) ?></b></div>
        <div class="box">Total Potongan<b>Rp <?= ps_money($totalDed) ?></b></div>
        <div class="box net">Take Home Pay<b>Rp <?= ps_money($net) ?></b></div>
    </div>
    <div class="muted" style="margin-top:28px;text-align:center">Slip ini dihasilkan otomatis dari sistem Payroll ERP RMI.</div>
</div>
</body>
</html>
