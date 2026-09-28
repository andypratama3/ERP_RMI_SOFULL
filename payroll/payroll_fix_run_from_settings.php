<?php
// payroll/payroll_fix_run_from_settings.php
// FIX gaji payroll run yang kebesaran karena matrix fallback salah.
// Fungsi: restore komponen gaji dari payroll_employee_settings, bukan dari salary_matrix terbesar.
// Pakai: /payroll/payroll_fix_run_from_settings.php?run_id=14&do=1

declare(strict_types=1);

require_once __DIR__ . '/_inc/bootstrap.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
        $st->execute([$table, $col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function add_col_if_missing(PDO $pdo, string $table, string $col, string $ddl): void {
    if (!col_exists($pdo, $table, $col)) {
        try { $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$ddl}"); } catch (Throwable $e) {}
    }
}

function ensure_cols(PDO $pdo): void {
    add_col_if_missing($pdo, 'payroll_run_items', 'izin_days', 'izin_days INT NOT NULL DEFAULT 0');
    add_col_if_missing($pdo, 'payroll_run_items', 'sick_days', 'sick_days INT NOT NULL DEFAULT 0');
    add_col_if_missing($pdo, 'payroll_run_items', 'late_count', 'late_count INT NOT NULL DEFAULT 0');
    add_col_if_missing($pdo, 'payroll_run_items', 'employee_code', 'employee_code VARCHAR(50) NULL');
    add_col_if_missing($pdo, 'payroll_run_items', 'employee_name', 'employee_name VARCHAR(150) NULL');
    add_col_if_missing($pdo, 'payroll_run_items', 'dept_code', 'dept_code VARCHAR(50) NULL');
    add_col_if_missing($pdo, 'payroll_run_items', 'office_code', 'office_code VARCHAR(50) NULL');
}

function n($v): float { return (float)($v ?? 0); }

function calc_amounts_safe(array $r): array {
    if (function_exists('payroll_recalc_amounts')) {
        return payroll_recalc_amounts($r);
    }

    $wd = max(1, (int)($r['work_days'] ?? 22));
    $present = max(0, (int)($r['days_present'] ?? $wd));
    $absent = max(0, (int)($r['absent_days'] ?? 0));

    $base = n($r['salary_basic']);
    $op = n($r['op_rate_day']) * $present;
    $ot = n($r['overtime_rate_per_hour']) * n($r['overtime_hours']);

    $gross = $base + $op + n($r['allowance_position']) + n($r['allowance_child']) +
             n($r['allowance_transport']) + n($r['allowance_quota']) +
             n($r['allowance_fixed']) + $ot + n($r['other_allowance']);

    $absenceDed = ($base / $wd) * $absent;

    $ded = n($r['deduction_fixed']) + $absenceDed + n($r['kasbon_deduction']) +
           n($r['loan_deduction']) + n($r['tax_pph21']) + n($r['bpjs_tk']) +
           n($r['bpjs_kes']) + n($r['other_deduction']);

    return [
        'base_amount' => $base,
        'op_amount' => $op,
        'overtime_amount' => $ot,
        'absence_deduction' => $absenceDed,
        'gross_pay' => $gross,
        'total_deduction' => $ded,
        'net_pay' => $gross - $ded,
    ];
}

function norm_status($v): string {
    $v = strtoupper(trim((string)$v));
    return $v;
}
function norm_level($v): string {
    $v = strtoupper(trim((string)$v));
    if ($v === 'MGR') return 'MANAGER';
    return $v;
}

function exact_matrix(PDO $pdo, int $year, string $status, string $level): ?array {
    $status = norm_status($status);
    $level = norm_level($level);
    if ($status === '' || $level === '') return null;

    try {
        $st = $pdo->prepare("SELECT * FROM salary_matrix
                             WHERE matrix_year=?
                               AND UPPER(TRIM(payroll_status))=?
                               AND UPPER(TRIM(payroll_level))=?
                             LIMIT 1");
        $st->execute([$year, $status, $level]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

$runId = (int)($_GET['run_id'] ?? 0);
$do = (($_GET['do'] ?? '') === '1');

if ($runId <= 0) {
    echo "<h3>Fix Payroll Run from Settings</h3>";
    echo "<p>Gunakan: <code>/payroll/payroll_fix_run_from_settings.php?run_id=14&do=1</code></p>";
    exit;
}

ensure_cols($pdo);

$st = $pdo->prepare("SELECT * FROM payroll_runs WHERE id=? LIMIT 1");
$st->execute([$runId]);
$run = $st->fetch(PDO::FETCH_ASSOC);
if (!$run) {
    http_response_code(404);
    echo "Payroll run tidak ditemukan.";
    exit;
}

$period = (string)($run['period_ym'] ?? '');
$year = (int)substr($period, 0, 4);

$sql = "SELECT
            i.*,
            e.employee_code AS e_code,
            e.employee_name AS e_name,
            e.dept_code AS e_dept,
            e.office_code AS e_office,
            e.payroll_status AS e_status,
            e.payroll_level AS e_level,
            s.login_user_id AS s_login_user_id,
            s.pay_type AS s_pay_type,
            s.salary_basic AS s_salary_basic,
            s.op_rate_day AS s_op_rate_day,
            s.allowance_position AS s_allowance_position,
            s.allowance_child AS s_allowance_child,
            s.allowance_transport AS s_allowance_transport,
            s.allowance_quota AS s_allowance_quota,
            s.allowance_fixed AS s_allowance_fixed,
            s.deduction_fixed AS s_deduction_fixed,
            s.overtime_rate_per_hour AS s_overtime_rate_per_hour
        FROM payroll_run_items i
        JOIN master_employees e ON e.id = i.employee_id
        LEFT JOIN payroll_employee_settings s ON s.employee_id = e.id
        WHERE i.run_id=?
        ORDER BY e.employee_name ASC";
$st = $pdo->prepare($sql);
$st->execute([$runId]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

$preview = [];
$updated = 0;

if ($do) {
    $pdo->beginTransaction();
}

try {
    foreach ($items as $it) {
        $itemId = (int)$it['id'];

        // PRIORITAS UTAMA: payroll_employee_settings
        // Matrix hanya referensi, jangan menimpa gaji setting karyawan.
        $payType = trim((string)($it['s_pay_type'] ?? ''));
        if ($payType === '') $payType = trim((string)($it['pay_type'] ?? 'MONTHLY'));
        if ($payType === '') $payType = 'MONTHLY';

        $salaryBasic = n($it['s_salary_basic']);
        if ($salaryBasic <= 0) $salaryBasic = n($it['salary_basic']);

        $opRate = n($it['s_op_rate_day']);
        if ($opRate <= 0) $opRate = n($it['op_rate_day']);

        $allowPosition = n($it['s_allowance_position']);
        if ($allowPosition <= 0) $allowPosition = n($it['allowance_position']);

        $allowChild = n($it['s_allowance_child']);
        if ($allowChild <= 0) $allowChild = n($it['allowance_child']);

        $allowTransport = n($it['s_allowance_transport']);
        if ($allowTransport <= 0) $allowTransport = n($it['allowance_transport']);

        $allowQuota = n($it['s_allowance_quota']);
        if ($allowQuota <= 0) $allowQuota = n($it['allowance_quota']);

        $allowFixed = n($it['s_allowance_fixed']);
        if ($allowFixed <= 0) $allowFixed = n($it['allowance_fixed']);

        $dedFixed = n($it['s_deduction_fixed']);
        if ($dedFixed <= 0) $dedFixed = n($it['deduction_fixed']);

        $otRate = n($it['s_overtime_rate_per_hour']);
        if ($otRate <= 0) $otRate = n($it['overtime_rate_per_hour']);

        $wd = max(1, (int)($it['work_days'] ?? 22));
        $present = max(0, (int)($it['days_present'] ?? $wd));
        $leave = max(0, (int)($it['leave_days'] ?? 0));
        $izin = max(0, (int)($it['izin_days'] ?? 0));
        $sick = max(0, (int)($it['sick_days'] ?? 0));
        $absent = max(0, (int)($it['absent_days'] ?? max(0, $wd - $present - $leave - $izin - $sick)));
        $late = max(0, (int)($it['late_count'] ?? 0));

        $status = norm_status($it['e_status'] ?? $it['matrix_status'] ?? '');
        $level = norm_level($it['e_level'] ?? $it['matrix_level'] ?? '');

        $mx = exact_matrix($pdo, $year, $status, $level);
        $mxTakeHome = $mx ? n($mx['take_home_pay'] ?? 0) : 0;

        $calc = calc_amounts_safe([
            'pay_type' => $payType,
            'salary_basic' => $salaryBasic,
            'op_rate_day' => $opRate,
            'allowance_position' => $allowPosition,
            'allowance_child' => $allowChild,
            'allowance_transport' => $allowTransport,
            'allowance_quota' => $allowQuota,
            'allowance_fixed' => $allowFixed,
            'deduction_fixed' => $dedFixed,
            'overtime_rate_per_hour' => $otRate,
            'overtime_hours' => n($it['overtime_hours']),
            'other_allowance' => n($it['other_allowance']),
            'other_deduction' => n($it['other_deduction']),
            'kasbon_deduction' => n($it['kasbon_deduction']),
            'loan_deduction' => n($it['loan_deduction']),
            'tax_pph21' => n($it['tax_pph21']),
            'bpjs_tk' => n($it['bpjs_tk']),
            'bpjs_kes' => n($it['bpjs_kes']),
            'work_days' => $wd,
            'days_present' => $present,
            'absent_days' => $absent,
        ]);

        $preview[] = [
            'code' => $it['e_code'],
            'name' => $it['e_name'],
            'old_net' => n($it['net_pay']),
            'new_net' => $calc['net_pay'],
            'salary' => $salaryBasic,
            'op_rate' => $opRate,
            'allow_pos' => $allowPosition,
            'matrix_status' => $status,
            'matrix_level' => $level,
            'matrix_thp' => $mxTakeHome,
            'note' => $mx ? 'Matrix exact ditemukan' : 'Matrix tidak exact, gaji pakai Payroll Settings',
        ];

        if ($do) {
            $up = $pdo->prepare("UPDATE payroll_run_items SET
                    login_user_id=?,
                    employee_code=?,
                    employee_name=?,
                    dept_code=?,
                    office_code=?,
                    pay_type=?,
                    matrix_year=?,
                    matrix_status=?,
                    matrix_level=?,
                    matrix_take_home=?,
                    work_days=?,
                    days_present=?,
                    leave_days=?,
                    izin_days=?,
                    sick_days=?,
                    absent_days=?,
                    late_count=?,
                    salary_basic=?,
                    base_amount=?,
                    op_rate_day=?,
                    op_amount=?,
                    allowance_position=?,
                    allowance_child=?,
                    allowance_transport=?,
                    allowance_quota=?,
                    allowance_fixed=?,
                    deduction_fixed=?,
                    overtime_rate_per_hour=?,
                    overtime_amount=?,
                    absence_deduction=?,
                    gross_pay=?,
                    total_deduction=?,
                    net_pay=?,
                    updated_at=NOW()
                WHERE id=? AND run_id=?");
            $up->execute([
                ($it['s_login_user_id'] ?: $it['login_user_id']) ?: null,
                $it['e_code'],
                $it['e_name'],
                $it['e_dept'],
                $it['e_office'],
                $payType,
                $year,
                $status ?: null,
                $level ?: null,
                $mxTakeHome,
                $wd,
                $present,
                $leave,
                $izin,
                $sick,
                $absent,
                $late,
                $salaryBasic,
                $calc['base_amount'],
                $opRate,
                $calc['op_amount'],
                $allowPosition,
                $allowChild,
                $allowTransport,
                $allowQuota,
                $allowFixed,
                $dedFixed,
                $otRate,
                $calc['overtime_amount'],
                $calc['absence_deduction'],
                $calc['gross_pay'],
                $calc['total_deduction'],
                $calc['net_pay'],
                $itemId,
                $runId
            ]);
            $updated++;
        }
    }

    if ($do) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($do && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Gagal repair: " . h($e->getMessage());
    exit;
}

?>
<!doctype html>
<meta charset="utf-8">
<title>Fix Payroll Run from Settings</title>
<style>
body{font-family:Arial;margin:24px;background:#f3f4f6;color:#111827}
.card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:16px;margin-bottom:14px}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border:1px solid #ddd;padding:7px;font-size:13px}
th{background:#eef2ff}
.btn{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:10px 14px;border-radius:8px}
.ok{background:#ecfdf5;border:1px solid #86efac;padding:10px;border-radius:8px}
.warn{background:#fff7ed;border:1px solid #fdba74;padding:10px;border-radius:8px}
.num{text-align:right}
</style>

<div class="card">
    <h2>Fix Payroll Run #<?= h($runId) ?> dari Payroll Settings</h2>
    <p>Periode: <b><?= h($period) ?></b></p>
    <?php if ($do): ?>
        <div class="ok">Selesai update <?= h($updated) ?> item payroll. Buka kembali payroll run untuk cek nilai net pay.</div>
    <?php else: ?>
        <div class="warn">Ini masih preview. Klik Jalankan Fix untuk mengembalikan gaji dari Payroll Settings.</div>
    <?php endif; ?>
    <p>
        <a class="btn" href="?run_id=<?= h($runId) ?>&do=1">Jalankan Fix</a>
        <a class="btn" href="payroll_run.php?id=<?= h($runId) ?>">Kembali ke Payroll Run</a>
    </p>
</div>

<div class="card">
<table>
    <tr>
        <th>Kode</th>
        <th>Nama</th>
        <th>Gaji Setting</th>
        <th>OP Rate</th>
        <th>Tunj. Jabatan</th>
        <th>Matrix</th>
        <th>THP Matrix</th>
        <th>Net Lama</th>
        <th>Net Baru</th>
        <th>Catatan</th>
    </tr>
    <?php foreach ($preview as $p): ?>
    <tr>
        <td><?= h($p['code']) ?></td>
        <td><?= h($p['name']) ?></td>
        <td class="num"><?= number_format($p['salary'], 2) ?></td>
        <td class="num"><?= number_format($p['op_rate'], 2) ?></td>
        <td class="num"><?= number_format($p['allow_pos'], 2) ?></td>
        <td><?= h(($p['matrix_status'] ?: '-') . ' - ' . ($p['matrix_level'] ?: '-')) ?></td>
        <td class="num"><?= number_format($p['matrix_thp'], 2) ?></td>
        <td class="num"><?= number_format($p['old_net'], 2) ?></td>
        <td class="num"><b><?= number_format($p['new_net'], 2) ?></b></td>
        <td><?= h($p['note']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
</div>
