<?php
// payroll/payroll_absensi_manual_fix.php
// FINAL fallback: input/update Cuti/Izin/Sakit/Terlambat langsung ke payroll_run_items
// Pakai bila data absensi tidak terbaca otomatis karena mapping user absensi belum sama.

declare(strict_types=1);

require_once __DIR__ . '/_inc/bootstrap.php';

if (function_exists('payroll_ensure_schema')) {
    payroll_ensure_schema($pdo);
}

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

function ensure_payroll_absensi_columns(PDO $pdo): void {
    $cols = [
        'izin_days' => "ALTER TABLE payroll_run_items ADD COLUMN izin_days INT NOT NULL DEFAULT 0",
        'sick_days' => "ALTER TABLE payroll_run_items ADD COLUMN sick_days INT NOT NULL DEFAULT 0",
        'late_count' => "ALTER TABLE payroll_run_items ADD COLUMN late_count INT NOT NULL DEFAULT 0",
    ];
    foreach ($cols as $c => $sql) {
        if (!col_exists($pdo, 'payroll_run_items', $c)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }
}

ensure_payroll_absensi_columns($pdo);

$runId = (int)($_GET['run_id'] ?? $_POST['run_id'] ?? 0);
if ($runId <= 0) {
    http_response_code(400);
    echo "run_id wajib diisi. Contoh: /payroll/payroll_absensi_manual_fix.php?run_id=9";
    exit;
}

$st = $pdo->prepare("SELECT * FROM payroll_runs WHERE id=? LIMIT 1");
$st->execute([$runId]);
$run = $st->fetch(PDO::FETCH_ASSOC);

if (!$run) {
    http_response_code(404);
    echo "Payroll run tidak ditemukan.";
    exit;
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_absensi') {
    $items = $_POST['items'] ?? [];

    $pdo->beginTransaction();
    try {
        foreach ($items as $itemId => $v) {
            $itemId = (int)$itemId;
            if ($itemId <= 0) continue;

            $workDays = max(0, (int)($v['work_days'] ?? 0));
            $present  = max(0, (int)($v['present'] ?? 0));
            $leave    = max(0, (int)($v['leave'] ?? 0));
            $izin     = max(0, (int)($v['izin'] ?? 0));
            $sick     = max(0, (int)($v['sick'] ?? 0));
            $late     = max(0, (int)($v['late'] ?? 0));

            if ($workDays > 0 && $present > $workDays) $present = $workDays;

            // Cuti/Izin/Sakit tidak boleh lebih dari sisa hari kerja.
            $remaining = max(0, $workDays - $present);
            if (($leave + $izin + $sick) > $remaining) {
                $leave = min($leave, $remaining);
                $remaining -= $leave;
                $izin = min($izin, $remaining);
                $remaining -= $izin;
                $sick = min($sick, $remaining);
            }

            $absent = max(0, $workDays - $present - $leave - $izin - $sick);

            $stItem = $pdo->prepare("SELECT * FROM payroll_run_items WHERE id=? AND run_id=? LIMIT 1");
            $stItem->execute([$itemId, $runId]);
            $row = $stItem->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;

            $calc = null;
            if (function_exists('payroll_recalc_amounts')) {
                $calc = payroll_recalc_amounts([
                    'pay_type' => (string)($row['pay_type'] ?? 'MONTHLY'),
                    'salary_basic' => (float)($row['salary_basic'] ?? 0),
                    'op_rate_day' => (float)($row['op_rate_day'] ?? 0),
                    'allowance_position' => (float)($row['allowance_position'] ?? 0),
                    'allowance_child' => (float)($row['allowance_child'] ?? 0),
                    'allowance_transport' => (float)($row['allowance_transport'] ?? 0),
                    'allowance_quota' => (float)($row['allowance_quota'] ?? 0),
                    'allowance_fixed' => (float)($row['allowance_fixed'] ?? 0),
                    'deduction_fixed' => (float)($row['deduction_fixed'] ?? 0),
                    'overtime_rate_per_hour' => (float)($row['overtime_rate_per_hour'] ?? 0),
                    'overtime_hours' => (float)($row['overtime_hours'] ?? 0),
                    'other_allowance' => (float)($row['other_allowance'] ?? 0),
                    'other_deduction' => (float)($row['other_deduction'] ?? 0),
                    'kasbon_deduction' => (float)($row['kasbon_deduction'] ?? 0),
                    'loan_deduction' => (float)($row['loan_deduction'] ?? 0),
                    'tax_pph21' => (float)($row['tax_pph21'] ?? 0),
                    'bpjs_tk' => (float)($row['bpjs_tk'] ?? 0),
                    'bpjs_kes' => (float)($row['bpjs_kes'] ?? 0),
                    'work_days' => $workDays,
                    'days_present' => $present,
                    'absent_days' => $absent,
                ]);
            }

            if ($calc) {
                $up = $pdo->prepare("UPDATE payroll_run_items
                    SET work_days=?, days_present=?, leave_days=?, izin_days=?, sick_days=?, absent_days=?, late_count=?,
                        base_amount=?, op_amount=?, overtime_amount=?, absence_deduction=?,
                        gross_pay=?, total_deduction=?, net_pay=?, updated_at=NOW()
                    WHERE id=? AND run_id=?");
                $up->execute([
                    $workDays, $present, $leave, $izin, $sick, $absent, $late,
                    $calc['base_amount'], $calc['op_amount'], $calc['overtime_amount'], $calc['absence_deduction'],
                    $calc['gross_pay'], $calc['total_deduction'], $calc['net_pay'],
                    $itemId, $runId
                ]);
            } else {
                $up = $pdo->prepare("UPDATE payroll_run_items
                    SET work_days=?, days_present=?, leave_days=?, izin_days=?, sick_days=?, absent_days=?, late_count=?, updated_at=NOW()
                    WHERE id=? AND run_id=?");
                $up->execute([$workDays, $present, $leave, $izin, $sick, $absent, $late, $itemId, $runId]);
            }
        }

        $pdo->commit();
        $msg = "Data absensi payroll berhasil disimpan dan payslip sudah diperbarui.";
    } catch (Throwable $e) {
        $pdo->rollBack();
        $msg = "Gagal simpan: " . $e->getMessage();
    }
}

$sql = "SELECT i.*,
               COALESCE(NULLIF(i.employee_code,''), e.employee_code) AS employee_code_x,
               COALESCE(NULLIF(i.employee_name,''), e.employee_name) AS employee_name_x,
               COALESCE(NULLIF(i.dept_code,''), e.dept_code) AS dept_code_x,
               COALESCE(NULLIF(i.office_code,''), e.office_code) AS office_code_x
        FROM payroll_run_items i
        LEFT JOIN master_employees e ON e.id = i.employee_id
        WHERE i.run_id=?
        ORDER BY employee_name_x ASC, i.id ASC";
$st = $pdo->prepare($sql);
$st->execute([$runId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fix Absensi Payroll Run #<?= h($runId) ?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:24px}
.wrap{max-width:1200px;margin:auto}
.card{background:#172033;border:1px solid #334155;border-radius:14px;padding:18px;margin-bottom:16px}
h1{margin:0 0 8px;font-size:24px}
a.btn,button{background:#2563eb;color:white;border:0;border-radius:8px;padding:9px 12px;text-decoration:none;cursor:pointer}
button.save{background:#16a34a;font-weight:bold}
table{width:100%;border-collapse:collapse;background:#111827;border-radius:12px;overflow:hidden}
th,td{border-bottom:1px solid #334155;padding:8px;font-size:13px;text-align:left}
th{background:#020617;color:#fff}
input{width:72px;padding:7px;border-radius:8px;border:1px solid #475569;background:#0b1120;color:#fff}
.small{font-size:12px;color:#94a3b8}
.msg{padding:12px;border-radius:10px;background:#064e3b;color:#bbf7d0;margin:12px 0}
.warn{padding:12px;border-radius:10px;background:#451a03;color:#fed7aa;margin:12px 0}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Fix Manual Absensi Payroll Run #<?= h($runId) ?></h1>
        <div class="small">Periode: <b><?= h($run['period_ym'] ?? '-') ?></b> | Status: <?= h($run['status'] ?? '-') ?></div>
        <p>
            <a class="btn" href="payroll_run.php?id=<?= h($runId) ?>">Kembali ke Payroll Run</a>
            <a class="btn" href="index.php">Payroll Dashboard</a>
        </p>
        <?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
        <div class="warn">
            Gunakan halaman ini jika Cuti / Izin / Sakit / Terlambat tidak terbaca otomatis dari absensi.
            Setelah disimpan, nilai akan langsung tampil di payslip.
        </div>
    </div>

    <form method="post">
        <input type="hidden" name="run_id" value="<?= h($runId) ?>">
        <input type="hidden" name="action" value="save_absensi">

        <div class="card" style="overflow:auto">
            <table>
                <thead>
                    <tr>
                        <th>Karyawan</th>
                        <th>Dept</th>
                        <th>Office</th>
                        <th>Hari Kerja</th>
                        <th>Hadir</th>
                        <th>Cuti</th>
                        <th>Izin</th>
                        <th>Sakit</th>
                        <th>Alpa</th>
                        <th>Terlambat</th>
                        <th>Payslip</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): 
                    $id = (int)$r['id'];
                    $wd = (int)($r['work_days'] ?? 0);
                    $present = (int)($r['days_present'] ?? 0);
                    $leave = (int)($r['leave_days'] ?? 0);
                    $izin = (int)($r['izin_days'] ?? 0);
                    $sick = (int)($r['sick_days'] ?? 0);
                    $absent = (int)($r['absent_days'] ?? 0);
                    $late = (int)($r['late_count'] ?? 0);
                ?>
                    <tr>
                        <td>
                            <b><?= h($r['employee_name_x'] ?: ('Karyawan #'.$r['employee_id'])) ?></b><br>
                            <span class="small"><?= h($r['employee_code_x'] ?: '-') ?></span>
                        </td>
                        <td><?= h($r['dept_code_x'] ?: '-') ?></td>
                        <td><?= h($r['office_code_x'] ?: '-') ?></td>
                        <td><input type="number" min="0" name="items[<?= $id ?>][work_days]" value="<?= h($wd) ?>"></td>
                        <td><input type="number" min="0" name="items[<?= $id ?>][present]" value="<?= h($present) ?>"></td>
                        <td><input type="number" min="0" name="items[<?= $id ?>][leave]" value="<?= h($leave) ?>"></td>
                        <td><input type="number" min="0" name="items[<?= $id ?>][izin]" value="<?= h($izin) ?>"></td>
                        <td><input type="number" min="0" name="items[<?= $id ?>][sick]" value="<?= h($sick) ?>"></td>
                        <td><?= h($absent) ?></td>
                        <td><input type="number" min="0" name="items[<?= $id ?>][late]" value="<?= h($late) ?>"></td>
                        <td><a class="btn" href="payslip.php?item_id=<?= $id ?>" target="_blank">Payslip</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <button class="save" type="submit">Simpan Cuti / Izin / Sakit / Terlambat</button>
        </div>
    </form>
</div>
<script>
document.addEventListener('input', function(e){
    if(!e.target.matches('input[type="number"]')) return;
    const tr = e.target.closest('tr');
    if(!tr) return;
    const inputs = tr.querySelectorAll('input[type="number"]');
    if(inputs.length < 6) return;
    const wd = parseInt(inputs[0].value || '0',10);
    const present = parseInt(inputs[1].value || '0',10);
    const leave = parseInt(inputs[2].value || '0',10);
    const izin = parseInt(inputs[3].value || '0',10);
    const sick = parseInt(inputs[4].value || '0',10);
    const absentCell = tr.children[8];
    absentCell.textContent = Math.max(0, wd-present-leave-izin-sick);
});
</script>
</body>
</html>
