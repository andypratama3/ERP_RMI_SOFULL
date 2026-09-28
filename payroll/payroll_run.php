<?php
require_once dirname(__DIR__) . '/master/auth.php'; // load auth helpers
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_inc/payroll_sync_helpers.php';
// Shift helper dipakai agar potongan telat karyawan shift tidak memakai jam kantor 08:30.
foreach ([__DIR__ . '/../absensi/_inc/shift_helper.php', __DIR__ . '/../../absensi/_inc/shift_helper.php'] as $__rmi_shift_helper) {
    if (is_file($__rmi_shift_helper)) { require_once $__rmi_shift_helper; break; }
}
unset($__rmi_shift_helper);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && function_exists('verify_csrf')) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

$runId = (int)($_GET['id'] ?? 0);
if ($runId <= 0) { http_response_code(400); exit("Run ID tidak valid"); }

$st = $pdo->prepare("SELECT * FROM payroll_runs WHERE id = ? LIMIT 1");
$st->execute([$runId]);
$run = $st->fetch(PDO::FETCH_ASSOC);
if (!$run) { http_response_code(404); exit("Run tidak ditemukan"); }

$period = (string)$run['period_ym'];
if (function_exists('payroll_ensure_schema')) { payroll_ensure_schema($pdo); }
$parsed = function_exists('rmi_payroll_cutoff_period')
    ? rmi_payroll_cutoff_period($period, 25)
    : payroll_parse_month($period);
[$start, $end] = $parsed ?: [null,null];
$workdaysCalendar = $parsed
    ? rmi_payroll_effective_workdays($pdo, $start, $end, (string)($run['office_code'] ?? ''))
    : 0;

// Periode payroll RMI menggunakan cutoff tanggal 26 bulan sebelumnya
// sampai tanggal 25 bulan berjalan. Tanggal 26 dan seterusnya masuk
// ke periode payroll bulan berikutnya.
$todayYmd = date('Y-m-d');
$periodIsOpen = ($parsed && $end !== null && $todayYmd < $end);
$periodNotStarted = ($parsed && $start !== null && $todayYmd < $start);
$attendanceCutoffEnd = $end;
if ($parsed && $periodIsOpen && !$periodNotStarted && $todayYmd >= $start) {
    $attendanceCutoffEnd = $todayYmd;
}
$elapsedWorkdaysCalendar = ($parsed && $attendanceCutoffEnd)
    ? rmi_payroll_effective_workdays($pdo, $start, $attendanceCutoffEnd, (string)($run['office_code'] ?? ''))
    : 0;

// === FINAL RMI OVERTIME 2026-09 ============================================
// Sumber kebenaran lembur payroll:
// 1) Tanggal kerja lembur (overtime_start_at), BUKAN tanggal request dibuat/approved.
// 2) Periode payroll mengikuti cutoff 26 bulan lalu s/d 25 bulan berjalan.
// 3) Durasi bayar mengikuti rekap manual HRL: <4 jam tanpa istirahat, >=4 jam 30 menit, >=8 jam 60 menit.
// 4) Tarif/jam = floor((Gaji Pokok + Tunjangan Jabatan + Transport) / pembagi).
//    Manager = 173, Staff = 200.
// 5) Faktor upah lembur per kejadian: jam pertama 1.5x, jam berikutnya 2x.
// Dengan logika ini contoh MgrWQS_BGR/Mia 22-08-2026: 09:27 - 01:00 = 08:27,
// tarif floor(7.137.211/173)=41.255, faktor 16.4 => Rp 676.582.
function rmi_pr_overtime_cutoff_range(string $periodYm): array {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $periodYm, $m)) return [null, null];
    try {
        $cur = new DateTimeImmutable($periodYm . '-01');
        $prev = $cur->modify('-1 month');
        return [$prev->format('Y-m-26'), $cur->format('Y-m-25')];
    } catch (Throwable $e) {
        return [null, null];
    }
}

function rmi_pr_overtime_role_is_manager(PDO $pdo, array $item, int $loginUserId = 0): bool {
    $tokens = [];
    foreach (['job_title','employee_name','dept_code'] as $k) {
        if (!empty($item[$k])) $tokens[] = strtoupper(trim((string)$item[$k]));
    }
    $employeeId = (int)($item['employee_id'] ?? 0);
    try {
        if ($loginUserId > 0 && function_exists('rmi_pr_table_exists') && rmi_pr_table_exists($pdo, 'master_system_login')) {
            $cols = ['username'];
            foreach (['role','level','position','job_title'] as $c) {
                if (function_exists('rmi_pr_col_exists') && rmi_pr_col_exists($pdo, 'master_system_login', $c)) $cols[] = $c;
            }
            $st = $pdo->prepare('SELECT ' . implode(',', $cols) . ' FROM master_system_login WHERE id=? LIMIT 1');
            $st->execute([$loginUserId]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($u as $v) if ($v !== null && $v !== '') $tokens[] = strtoupper(trim((string)$v));
        }
        if ($employeeId > 0 && function_exists('rmi_pr_table_exists') && rmi_pr_table_exists($pdo, 'master_employees')) {
            $cols = [];
            foreach (['level_type','payroll_level','job_title','jabatan','position_name','position','title','role_title','employee_position'] as $c) {
                if (function_exists('rmi_pr_col_exists') && rmi_pr_col_exists($pdo, 'master_employees', $c)) $cols[] = $c;
            }
            if ($cols) {
                $st = $pdo->prepare('SELECT ' . implode(',', array_map(fn($c)=>'`'.$c.'`', $cols)) . ' FROM master_employees WHERE id=? LIMIT 1');
                $st->execute([$employeeId]);
                $e = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                foreach ($e as $v) if ($v !== null && $v !== '') $tokens[] = strtoupper(trim((string)$v));
            }
        }
    } catch (Throwable $e) {}
    $text = ' ' . implode(' ', $tokens) . ' ';
    return preg_match('/\b(MANAGER|MGR)\b/i', $text) === 1 || strpos($text, 'MGRWQS_') !== false;
}

function rmi_pr_overtime_rate_for_item(PDO $pdo, array $item, int $loginUserId = 0): float {
    $base = max(0.0, (float)($item['salary_basic'] ?? 0))
          + max(0.0, (float)($item['allowance_position'] ?? 0))
          + max(0.0, (float)($item['allowance_transport'] ?? 0));
    if ($base <= 0) return 0.0;
    $divisor = rmi_pr_overtime_role_is_manager($pdo, $item, $loginUserId) ? 173 : 200;
    return (float)floor($base / $divisor);
}

// === FINAL RMI: KEBIJAKAN SAKIT / CUTI DARI HRL PROCESS ====================
// Kebijakan bisnis:
// 1) CUTI approved HRL Process            => GP tidak dipotong, OP tidak dibayar pada hari tidak masuk.
// 2) SAKIT + surat/keterangan approved    => GP tidak dipotong, OP tidak dibayar pada hari tidak masuk.
// 3) SAKIT tanpa surat/keterangan         => GP dipotong + OP tidak dibayar.
// 4) Potongan GP sakit tanpa keterangan:
//      - pola kerja s.d. Sabtu : GP / 21 x hari sakit tanpa keterangan
//      - pola kerja s.d. Jumat : GP / 20 x hari sakit tanpa keterangan
// 5) Sumber status/durasi wajib HRL Process; tanggal created_at bukan tanggal sakit.

function rmi_pr_absence_gp_divisor(PDO $pdo, array $item): int {
    $employeeId = (int)($item['employee_id'] ?? 0);

    // Prioritas: master karyawan bila ada kolom pola kerja yang eksplisit.
    if ($employeeId > 0 && function_exists('rmi_pr_table_exists') && rmi_pr_table_exists($pdo, 'master_employees')) {
        $cols = ['work_schedule','work_pattern','working_pattern','workday_pattern','work_days_pattern','weekly_workdays','work_days_per_week','saturday_work','work_saturday','hari_kerja'];
        foreach ($cols as $c) {
            if (!function_exists('rmi_pr_col_exists') || !rmi_pr_col_exists($pdo, 'master_employees', $c)) continue;
            try {
                $st = $pdo->prepare("SELECT `{$c}` FROM master_employees WHERE id=? LIMIT 1");
                $st->execute([$employeeId]);
                $raw = strtoupper(trim((string)($st->fetchColumn() ?? '')));
                if ($raw === '') continue;
                if (preg_match('/(^|\\b)(6|SABTU|SATURDAY|MON\\s*[-–]\\s*SAT)(\\b|$)/i', $raw)) return 21;
                if (preg_match('/(^|\\b)(5|JUMAT|FRIDAY|MON\\s*[-–]\\s*FRI)(\\b|$)/i', $raw)) return 20;
                if (in_array($raw, ['1','Y','YES','TRUE','YA'], true) && stripos($c, 'satur') !== false) return 21;
                if (in_array($raw, ['0','N','NO','FALSE','TIDAK'], true) && stripos($c, 'satur') !== false) return 20;
            } catch (Throwable $e) {}
        }
    }

    // Fallback aman dari snapshot hari kerja resmi. Pola >21 hari pada cutoff biasanya s.d. Sabtu.
    // Ini hanya fallback ketika master belum punya penanda pola kerja eksplisit.
    $wd = max(0, (int)($item['work_days'] ?? 0));
    return $wd > 21 ? 21 : 20;
}

function rmi_pr_count_schedule_days(string $from, string $to, bool $includeSaturday): int {
    $a = strtotime($from); $b = strtotime($to ?: $from);
    if ($a === false || $b === false || $b < $a) return 0;
    $n = 0;
    for ($t=$a; $t<=$b; $t=strtotime('+1 day', $t)) {
        $dow=(int)date('N',$t); // 1 Senin .. 7 Minggu
        if ($dow <= 5 || ($includeSaturday && $dow === 6)) $n++;
    }
    return $n;
}

function rmi_pr_hrl_identity_usernames(PDO $pdo, int $employeeId, int $loginUserId = 0): array {
    $out=[];
    if ($employeeId <= 0 || !rmi_pr_table_exists($pdo,'master_employees') || !rmi_pr_table_exists($pdo,'master_system_login')) return [];
    try {
        $st=$pdo->prepare("SELECT employee_code FROM master_employees WHERE id=? LIMIT 1");
        $st->execute([$employeeId]);
        $code=trim((string)($st->fetchColumn() ?: ''));
        if ($code==='') return [];
        $add=static function($u) use (&$out){ $u=trim((string)$u); if($u!=='') $out[strtoupper($u)]=$u; };

        if (rmi_pr_col_exists($pdo,'master_system_login','holder_employee_code')) {
            $sql="SELECT username FROM master_system_login WHERE UPPER(TRIM(holder_employee_code))=UPPER(TRIM(?))";
            if (rmi_pr_col_exists($pdo,'master_system_login','deleted_at')) $sql.=" AND deleted_at IS NULL";
            if (rmi_pr_col_exists($pdo,'master_system_login','status')) $sql.=" AND UPPER(TRIM(COALESCE(status,'ACTIVE')))='ACTIVE'";
            $st=$pdo->prepare($sql); $st->execute([$code]); foreach($st->fetchAll(PDO::FETCH_COLUMN) as $u) $add($u);
        }
        $sql="SELECT username FROM master_system_login WHERE UPPER(TRIM(username))=UPPER(TRIM(?))";
        if (rmi_pr_col_exists($pdo,'master_system_login','deleted_at')) $sql.=" AND deleted_at IS NULL";
        $st=$pdo->prepare($sql); $st->execute([$code]); foreach($st->fetchAll(PDO::FETCH_COLUMN) as $u) $add($u);

        if ($loginUserId>0) {
            $st=$pdo->prepare("SELECT username FROM master_system_login WHERE id=? LIMIT 1");
            $st->execute([$loginUserId]); $add($st->fetchColumn());
        }
    } catch(Throwable $e) {}
    return array_values($out);
}

function rmi_pr_hrl_sick_policy_snapshot(PDO $pdo, array $item, string $periodYm, int $loginUserId = 0): array {
    $out=['source_found'=>0,'sick_days'=>0,'sick_with_note_days'=>0,'sick_without_note_days'=>0,'gp_divisor'=>20,'gp_deduction'=>0.0];
    $employeeId=(int)($item['employee_id'] ?? 0);
    $out['gp_divisor']=rmi_pr_absence_gp_divisor($pdo,$item);
    if ($employeeId<=0 || !preg_match('/^\\d{4}-\\d{2}$/',$periodYm) || !rmi_pr_table_exists($pdo,'hrl_requests')) return $out;

    // Rekap resmi Agustus 2026 sudah membedakan sakit dengan surat vs tanpa surat.
    // Yang tercatat "sakit tanpa surat": Moch Iksan, Muhammad Farizal, Yohanes Ratu Pito.
    if ($periodYm === '2026-08') {
        $nm=strtoupper(trim((string)($item['employee_name'] ?? '')));
        $nm=preg_replace('/[^A-Z0-9]+/',' ', $nm) ?? $nm;
        $nm=trim(preg_replace('/\\s+/',' ', $nm) ?? $nm);
        $official=rmi_pr_attendance_recap_override($pdo,$employeeId,(string)($item['employee_code']??''),(string)($item['employee_name']??''),$periodYm);
        if ($official !== null) {
            $sick=max(0,(int)($official['sick']??0));
            $without = in_array($nm,['MOCH IKSAN ARDIANSYAH','MUHAMMAD FARIZAL','YOHANES RATU PITO'],true) ? $sick : 0;
            $out['source_found']=1;
            $out['sick_days']=$sick;
            $out['sick_without_note_days']=$without;
            $out['sick_with_note_days']=max(0,$sick-$without);
            $out['gp_deduction']=round(((float)($item['salary_basic']??0) / max(1,$out['gp_divisor'])) * $without,2);
            return $out;
        }
    }

    $users=rmi_pr_hrl_identity_usernames($pdo,$employeeId,$loginUserId);
    if (!$users) return $out;
    $parsed = function_exists('rmi_payroll_cutoff_period') ? rmi_payroll_cutoff_period($periodYm,25) : null;
    if (!$parsed) return $out;
    [$start,$end]=$parsed;
    $includeSaturday = ((int)$out['gp_divisor'] === 21);

    // Ambil hanya field yang memang ada di schema HRL Process.
    $select=['r.id','r.start_date','r.end_date'];
    $textCols=[]; $fileCols=[];
    foreach(['title','judul','description','deskripsi','notes','note','catatan','keterangan','reason','alasan'] as $c){
        if(rmi_pr_col_exists($pdo,'hrl_requests',$c)){ $select[]="r.`{$c}`"; $textCols[]=$c; }
    }
    foreach(['attachment','attachment_path','file_path','document_path','surat_path','medical_letter','medical_certificate','supporting_document'] as $c){
        if(rmi_pr_col_exists($pdo,'hrl_requests',$c)){ $select[]="r.`{$c}`"; $fileCols[]=$c; }
    }
    $ph=implode(',',array_fill(0,count($users),'?'));
    $sql="SELECT ".implode(',',$select)." FROM hrl_requests r WHERE r.created_by IN ({$ph}) AND UPPER(TRIM(r.req_type))='SAKIT' AND UPPER(TRIM(r.status)) IN ('HRL_APPROVED','FIN_APPROVED','PAID','APPROVED') AND r.start_date IS NOT NULL AND COALESCE(r.end_date,r.start_date)>=? AND r.start_date<=?";
    if(rmi_pr_col_exists($pdo,'hrl_requests','deleted_at')) $sql.=" AND r.deleted_at IS NULL";
    try {
        $st=$pdo->prepare($sql); $st->execute(array_merge($users,[$start,$end]));
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
            $from=max(strtotime((string)$r['start_date']),strtotime($start));
            $to=min(strtotime((string)($r['end_date'] ?: $r['start_date'])),strtotime($end));
            if($from===false||$to===false||$to<$from) continue;
            $days=rmi_pr_count_schedule_days(date('Y-m-d',$from),date('Y-m-d',$to),$includeSaturday);
            if($days<=0) continue;
            $hasEvidence=false;
            foreach($fileCols as $c){ if(trim((string)($r[$c]??''))!==''){ $hasEvidence=true; break; } }
            if(!$hasEvidence){
                $txt=''; foreach($textCols as $c) $txt.=' '.strtoupper((string)($r[$c]??''));
                if(preg_match('/\\b(SURAT|DOKTER|MEDIS|MEDICAL|KETERANGAN|MC|KECELAKAAN)\\b/i',$txt)) $hasEvidence=true;
                if(preg_match('/TANPA\\s+(SURAT|KETERANGAN)|TIDAK\\s+ADA\\s+(SURAT|KETERANGAN)/i',$txt)) $hasEvidence=false;
            }
            $out['source_found']=1; $out['sick_days']+=$days;
            if($hasEvidence) $out['sick_with_note_days']+=$days; else $out['sick_without_note_days']+=$days;
        }
    } catch(Throwable $e) { return $out; }
    $out['gp_deduction']=round(((float)($item['salary_basic']??0) / max(1,$out['gp_divisor'])) * (int)$out['sick_without_note_days'],2);
    return $out;
}

// Helper: update calc for 1 item (now includes OP + overtime multiplier per event)
function payroll_update_item_calc(PDO $pdo, int $itemId): void {
    $st = $pdo->prepare("SELECT * FROM payroll_run_items WHERE id=? LIMIT 1");
    $st->execute([$itemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    // payroll_recalc_amounts() lama mengalikan jam x rate secara lurus. Untuk lembur resmi
    // kita kirim weighted hours agar overtime_amount, gross_pay dan net_pay memakai 1.5x/2x.
    $calcRow = $row;
    try {
        $runPeriod = '';
        if (!empty($row['run_id'])) {
            $stRun = $pdo->prepare("SELECT period_ym FROM payroll_runs WHERE id=? LIMIT 1");
            $stRun->execute([(int)$row['run_id']]);
            $runPeriod = substr(trim((string)($stRun->fetchColumn() ?: '')), 0, 7);
        }
        if ($runPeriod !== '' && function_exists('rmi_pr_hrl_overtime_snapshot_strict')) {
            $resolvedLogin = function_exists('rmi_pr_resolve_login_for_employee')
                ? rmi_pr_resolve_login_for_employee($pdo, (int)($row['employee_id'] ?? 0), (int)($row['login_user_id'] ?? 0), (string)($row['office_code'] ?? ''))
                : (int)($row['login_user_id'] ?? 0);
            $ot = rmi_pr_hrl_overtime_snapshot_strict($pdo, (int)($row['employee_id'] ?? 0), $runPeriod, $resolvedLogin);
            if ((int)($ot['source_count'] ?? 0) > 0) {
                $rate = rmi_pr_overtime_rate_for_item($pdo, $row, $resolvedLogin);
                $calcRow['overtime_hours'] = (float)($ot['weighted_hours'] ?? 0);
                if ($rate > 0) $calcRow['overtime_rate_per_hour'] = $rate;
            }
        }
    } catch (Throwable $e) {}

    $calc = payroll_recalc_amounts($calcRow);

    // Tambahkan potongan GP khusus SAKIT TANPA surat/keterangan dari HRL Process.
    // Sakit dengan surat/keterangan dan CUTI tidak memotong GP; OP sudah otomatis hanya
    // dibayar berdasarkan days_present sehingga hari CUTI/SAKIT tidak menghasilkan OP.
    try {
        if ($runPeriod !== '') {
            $sickPolicy = rmi_pr_hrl_sick_policy_snapshot($pdo, $row, $runPeriod, (int)($row['login_user_id'] ?? 0));
            $gpSickDeduction = max(0.0, (float)($sickPolicy['gp_deduction'] ?? 0));
            if ($gpSickDeduction > 0) {
                $calc['absence_deduction'] = round((float)($calc['absence_deduction'] ?? 0) + $gpSickDeduction, 2);
                $calc['total_deduction'] = round((float)($calc['total_deduction'] ?? 0) + $gpSickDeduction, 2);
                $calc['net_pay'] = round((float)($calc['net_pay'] ?? 0) - $gpSickDeduction, 2);
            }
        }
    } catch (Throwable $e) {}

    $upd = $pdo->prepare("UPDATE payroll_run_items SET
        base_amount       = ?,
        op_amount         = ?,
        overtime_amount   = ?,
        absence_deduction = ?,
        gross_pay         = ?,
        total_deduction   = ?,
        net_pay           = ?,
        updated_at        = NOW()
      WHERE id = ?");
    $upd->execute([
        $calc['base_amount'],
        $calc['op_amount'],
        $calc['overtime_amount'],
        $calc['absence_deduction'],
        $calc['gross_pay'],
        $calc['total_deduction'],
        $calc['net_pay'],
        $itemId
    ]);
}


// === FIX OP MATRIX: helpers untuk membaca komponen payroll secara aman ======
// Catatan:
// 1) Salary Matrix adalah sumber utama komponen OP/day, tunjangan jabatan,
//    transport, anak, dan kuota.
// 2) Payroll Employee Settings tetap boleh menjadi override, tetapi hanya jika
//    nilainya diisi lebih dari 0. Jika settings masih 0, jangan menimpa nilai
//    dari Salary Matrix menjadi 0.
function rmi_pr_num_or_matrix($settingRow, string $settingCol, float $matrixValue): float {
    if (is_array($settingRow) && array_key_exists($settingCol, $settingRow)) {
        $v = (float)($settingRow[$settingCol] ?? 0);
        if ($v > 0) return $v;
    }
    return (float)$matrixValue;
}
function rmi_pr_first_existing_col(PDO $pdo, string $table, array $cols): string {
    foreach ($cols as $c) {
        if (function_exists('payroll_column_exists') && payroll_column_exists($pdo, $table, $c)) {
            return $c;
        }
    }
    return '';
}
function rmi_pr_employee_job_select(PDO $pdo): string {
    $col = rmi_pr_first_existing_col($pdo, 'master_employees', [
        'job_title','jabatan','position_name','position','title','role_title','employee_position'
    ]);
    if ($col === '') return "'' AS employee_job_title";
    return "e.`{$col}` AS employee_job_title";
}


/**
 * Resolve login user untuk payroll secara aman.
 *
 * Problem yang diperbaiki:
 * - Satu employee_code dapat pernah ditempel ke lebih dari satu akun jabatan
 *   (contoh StaffBRANCH_BDG dan StaffMPR_BDG). Payroll/absensi tidak boleh
 *   memilih akun pertama secara acak karena bisa membuat hari hadir, telat,
 *   dan lembur menjadi 0 atau masuk ke akun yang salah.
 * - Prioritas pilihan akun: holder_employee_code sama, akun aktif, tidak soft
 *   deleted, office sama, department sama, lalu username yang sesuai dept/office.
 */
function rmi_pr_resolve_login_for_employee(PDO $pdo, int $employeeId, int $currentLoginId = 0, ?string $runOffice = null): int {
    if ($employeeId <= 0 || !rmi_pr_table_exists($pdo, 'master_employees') || !rmi_pr_table_exists($pdo, 'master_system_login')) {
        return max(0, $currentLoginId);
    }
    try {
        $st = $pdo->prepare("SELECT employee_code, employee_name, dept_code, office_code FROM master_employees WHERE id=? LIMIT 1");
        $st->execute([$employeeId]);
        $emp = $st->fetch(PDO::FETCH_ASSOC);
        if (!$emp) return max(0, $currentLoginId);

        $empCode = strtoupper(trim((string)($emp['employee_code'] ?? '')));
        $empDept = strtoupper(trim((string)($emp['dept_code'] ?? '')));
        $empOffice = strtoupper(trim((string)($emp['office_code'] ?? ($runOffice ?? ''))));
        if ($empOffice === '' && $runOffice !== null) $empOffice = strtoupper(trim((string)$runOffice));
        if ($empCode === '') return max(0, $currentLoginId);

        $cols = "id, username, department, office_code, holder_employee_code, status";
        $sql = "SELECT {$cols} FROM master_system_login WHERE (UPPER(TRIM(holder_employee_code))=UPPER(TRIM(?)) OR UPPER(TRIM(username))=UPPER(TRIM(?)))";
        $params = [$empCode, $empCode];
        if (rmi_pr_col_exists($pdo, 'master_system_login', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        if (rmi_pr_col_exists($pdo, 'master_system_login', 'status')) $sql .= " AND UPPER(TRIM(COALESCE(status,'ACTIVE')))='ACTIVE'";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return max(0, $currentLoginId);

        $bestId = 0;
        $bestScore = -9999;
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) continue;
            $u = strtoupper(trim((string)($r['username'] ?? '')));
            $dept = strtoupper(trim((string)($r['department'] ?? '')));
            $off = strtoupper(trim((string)($r['office_code'] ?? '')));
            $holder = strtoupper(trim((string)($r['holder_employee_code'] ?? '')));

            $score = 0;
            if ($holder === $empCode) $score += 100;
            if ($off !== '' && $empOffice !== '' && $off === $empOffice) $score += 40;
            if ($dept !== '' && $empDept !== '' && $dept === $empDept) $score += 60;
            if ($currentLoginId > 0 && $id === $currentLoginId) $score += 15;
            if ($empDept !== '' && strpos($u, $empDept) !== false) $score += 20;
            if ($empOffice !== '' && strpos($u, $empOffice) !== false) $score += 10;
            if ($u === $empCode) $score += 5;

            // Hindari akun dept lain mengambil absensi karyawan jika employee_code pernah ditempel ganda.
            if ($empDept !== '' && $dept !== '' && $dept !== $empDept) $score -= 50;
            if ($empOffice !== '' && $off !== '' && $off !== $empOffice) $score -= 20;

            if ($score > $bestScore || ($score === $bestScore && ($bestId === 0 || $id < $bestId))) {
                $bestScore = $score;
                $bestId = $id;
            }
        }
        return $bestId > 0 ? $bestId : max(0, $currentLoginId);
    } catch (Throwable $e) {
        return max(0, $currentLoginId);
    }
}

function rmi_pr_normalize_attendance_counts(array $att, int $wd): array {
    // FINAL RMI:
    // - HADIR/CUTI/IZIN/SAKIT/ALPA harus berasal dari status nyata, bukan dari selisih kalender.
    // - Jangan pernah mengubah hari yang belum terklasifikasi menjadi ALPA secara otomatis.
    //   ALPA hanya sah jika sumber absensi/HRL memang menyatakan ALPA/tidak hadir.
    // - work_days boleh berbeda per karyawan (shift/cabang/masa kerja/rekap resmi).
    // - Jika realisasi > work_days, jangan dipotong; jika realisasi < work_days, validasi final
    //   akan menahan POST sampai hari yang kurang benar-benar diklasifikasikan.
    $wd      = max(0, (int)$wd);
    $present = max(0, (int)($att['present'] ?? 0));
    $leave   = max(0, (int)($att['leave'] ?? 0));
    $izin    = max(0, (int)($att['izin'] ?? 0));
    $sick    = max(0, (int)($att['sick'] ?? 0));
    $absent  = max(0, (int)($att['absent'] ?? 0));
    return [$wd, $present, $leave, $izin, $sick, $absent];
}

// Rekap absensi resmi HRL/FIN periode 26 Juli - 25 Agustus 2026.
// Dipakai sebagai snapshot final untuk payroll Agustus agar Recalc/Sync tidak mengubah
// CUTI/IZIN/SAKIT menjadi ALPA akibat perbedaan kalender, shift, atau mapping login.
// Mapping kolom payroll: CUTI => leave_days, IZIN => izin_days, SAKIT => sick_days,
// ALPA => absent_days. Catatan "sakit tanpa surat" tetap SAKIT sesuai rekap resmi;
// Widia "belum dapat cuti" tercatat IZIN, bukan CUTI.
function rmi_pr_attendance_recap_override(PDO $pdo, int $employeeId, string $employeeCode, string $employeeName, string $periodYm): ?array {
    if (substr(trim($periodYm), 0, 7) !== '2026-08') return null;

    $code = strtoupper(trim($employeeCode));
    $name = strtoupper(trim($employeeName));
    if (($name === '' || $code === '') && $employeeId > 0 && rmi_pr_table_exists($pdo, 'master_employees')) {
        try {
            $st = $pdo->prepare("SELECT employee_code, employee_name FROM master_employees WHERE id=? LIMIT 1");
            $st->execute([$employeeId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($code === '') $code = strtoupper(trim((string)($r['employee_code'] ?? '')));
            if ($name === '') $name = strtoupper(trim((string)($r['employee_name'] ?? '')));
        } catch (Throwable $e) {}
    }

    $norm = static function(string $v): string {
        $v = strtoupper(trim($v));
        $v = preg_replace('/[^A-Z0-9]+/', ' ', $v) ?? $v;
        return trim(preg_replace('/\s+/', ' ', $v) ?? $v);
    };
    $key = $norm($name);

    // [work_days, hadir, late, sakit, izin, cuti, alpa]
    $rows = [
        'A DHIMAS SETYA P'       => [20,19,0,0,0,1,0],
        'A DHIMAS SETYA PAMBUDI' => [20,19,0,0,0,1,0],
        'ABDUL MUHYI'             => [23,23,1,0,0,0,0],
        'AHMAD SYAHRUL AHDZAR'    => [24,24,2,0,0,0,0],
        'AI FATIMAH'              => [20,19,0,1,0,0,0],
        'CITA NURHANIPAH'         => [24,24,4,0,0,0,0],
        'DIDI FERRIANSYAH M'      => [21,19,0,1,0,1,0],
        'DIDI FERRIANSYAH MAULANA'=> [21,19,0,1,0,1,0],
        'ELVIA ZON FITRI'         => [20,11,0,1,0,8,0],
        'HARUN'                   => [24,24,0,0,0,0,0],
        'IMAM SETIYADI'           => [24,24,0,0,0,0,0],
        'KUKUH AJI PRASTIO'       => [22,21,7,1,0,0,0],
        'LILIS WULANDARI'         => [20,19,0,0,0,1,0],
        'M ADHAZ JANURUL H'       => [22,22,1,0,0,0,0],
        'M ADHAZ JANURUL HIDAYAT' => [22,22,1,0,0,0,0],
        'MAIZURA HAFIDZA'         => [20,20,0,0,0,0,0],
        'MIA ASTIA'               => [20,19,4,0,0,1,0],
        'MOCH IKSAN ARDIANSYAH'   => [22,21,12,1,0,0,0],
        'MUALIM'                  => [24,23,10,1,0,0,0],
        'MUHAMMAD ADAM HIDAYAT'   => [20,20,2,0,0,0,0],
        'MUHAMMAD FARIZAL'        => [22,20,12,1,0,1,0],
        'MUHAMMAD IQBAL PERDANA'  => [24,23,6,1,0,0,0],
        'PELIPUS ETDING'          => [21,21,0,0,0,0,0],
        'RISNAWATI'               => [24,24,0,0,0,0,0],
        'ROMLAH'                  => [20,19,14,1,0,0,0],
        'SAHRONI'                 => [31,31,0,0,0,0,0],
        'TASA CAHYANING FITRI'    => [24,24,0,0,0,0,0],
        'TYTA SUKMAWARDANI'       => [20,19,3,1,0,0,0],
        'VIAN SETIAWAN'           => [20,20,8,0,0,0,0],
        'WIDIA AULIA RAHMAH'      => [20,19,0,0,1,0,0],
        'WULANDARI'               => [20,20,0,0,0,0,0],
        'YOHANES RATU PITO'       => [20,19,5,1,0,0,0],
        'ZULIANA'                 => [20,20,0,0,0,0,0],
        'NAVISA ZAHRA FITRIA'     => [24,24,0,0,0,0,0],
        'NUROCHMAN'               => [20,19,2,0,0,1,0],
        'IMAM MAULANA'            => [22,22,2,0,0,0,0],
        'M IMAM MAULANA'          => [22,22,2,0,0,0,0],
        'TRIYANTORO'              => [24,24,2,0,0,0,0],
        'M FAUZAL MUTTAQIN'       => [24,23,6,1,0,0,0],
        'MUHAMMAD FAUZAL MUTTAQIN'=> [24,23,6,1,0,0,0],
        'ANIS TRI RAHMAWATI'      => [20,20,0,0,0,0,0],
        'GALUH HENDRY PRIZKY'     => [5,5,0,0,0,0,0],
    ];

    if (!isset($rows[$key])) return null;
    [$wd,$present,$late,$sick,$izin,$leave,$absent] = $rows[$key];
    return [
        'source_found'=>1,
        'official_recap'=>1,
        'work_days'=>$wd,
        'present'=>$present,
        'late'=>$late,
        'sick'=>$sick,
        'izin'=>$izin,
        'leave'=>$leave,
        'absent'=>$absent,
        'unclassified_days'=>0,
        'unclassified_dates'=>[],
    ];
}

function rmi_pr_sync_employee_profile(PDO $pdo, int $runId, ?int $onlyItemId = null): int {
    if ($runId <= 0 || !rmi_pr_table_exists($pdo, 'master_employees') || !rmi_pr_table_exists($pdo, 'payroll_run_items')) return 0;

    $itemCols = [];
    foreach (['login_user_id','employee_code','employee_name','dept_code','office_code','job_title','join_date','bank_name','bank_account'] as $c) {
        if (rmi_pr_col_exists($pdo, 'payroll_run_items', $c)) $itemCols[$c] = true;
    }
    if (!$itemCols) return 0;

    $jobCol = rmi_pr_first_existing_col($pdo, 'master_employees', ['job_title','jabatan','position_name','position','title','role_title','employee_position']);
    $bankNoCol = rmi_pr_first_existing_col($pdo, 'master_employees', ['bank_account_number','bank_account','bank_account_no','no_rekening','rekening','rekening_bank','account_number']);

    $sql = "SELECT e.*, i.login_user_id AS item_login_user_id";
    if ($jobCol !== '') $sql .= ", e.`{$jobCol}` AS profile_job_title";
    if ($bankNoCol !== '') $sql .= ", e.`{$bankNoCol}` AS profile_bank_account";
    if (rmi_pr_table_exists($pdo, 'master_system_login') && rmi_pr_col_exists($pdo, 'master_system_login', 'holder_employee_code')) {
        $sql .= ", (SELECT msl.id FROM master_system_login msl
                    WHERE UPPER(TRIM(msl.holder_employee_code))=UPPER(TRIM(e.employee_code))";
        if (rmi_pr_col_exists($pdo, 'master_system_login', 'status')) {
            $sql .= " AND UPPER(TRIM(COALESCE(msl.status,'ACTIVE')))='ACTIVE'";
        }
        if (rmi_pr_col_exists($pdo, 'master_system_login', 'deleted_at')) {
            $sql .= " AND msl.deleted_at IS NULL";
        }
        $sql .= " ORDER BY CASE WHEN msl.id=i.login_user_id THEN 0 ELSE 1 END";
        if (rmi_pr_col_exists($pdo, 'master_system_login', 'payroll_primary')) {
            $sql .= ", COALESCE(msl.payroll_primary,0) DESC";
        }
        $sql .= ", msl.id ASC LIMIT 1) AS profile_login_user_id";
    }
    $sql .= " FROM payroll_run_items i JOIN master_employees e ON e.id=i.employee_id WHERE i.run_id=?";
    $params = [$runId];
    if ($onlyItemId !== null && $onlyItemId > 0) { $sql .= " AND i.id=?"; $params[] = $onlyItemId; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    foreach ($rows as $e) {
        $set = []; $vals = [];
        $put = static function(string $col, $value) use (&$set, &$vals, $itemCols): void {
            if (!isset($itemCols[$col])) return;
            $set[] = "`{$col}`=?"; $vals[] = $value;
        };
        $mappedLoginId = rmi_pr_resolve_login_for_employee($pdo, (int)($e['id'] ?? 0), (int)($e['item_login_user_id'] ?? 0), (string)($e['office_code'] ?? ''));
        if ($mappedLoginId > 0) $put('login_user_id', $mappedLoginId);
        $put('employee_code', trim((string)($e['employee_code'] ?? '')));
        $put('employee_name', trim((string)($e['employee_name'] ?? '')));
        $put('dept_code', strtoupper(trim((string)($e['dept_code'] ?? ''))));
        $put('office_code', strtoupper(trim((string)($e['office_code'] ?? ''))));

        $job = trim((string)($e['profile_job_title'] ?? ''));
        if ($job === '') {
            $job = trim((string)($e['level_type'] ?? '') . ((string)($e['grade'] ?? '') !== '' ? ' ' . trim((string)$e['grade']) : ''));
        }
        $put('job_title', $job !== '' ? $job : null);

        $joinDate = null;
        $jy = preg_replace('/\D/', '', (string)($e['join_year'] ?? ''));
        $jm = preg_replace('/\D/', '', (string)($e['join_month'] ?? ''));
        if (strlen($jy) === 4 && (int)$jm >= 1 && (int)$jm <= 12) $joinDate = $jy . '-' . str_pad((string)(int)$jm, 2, '0', STR_PAD_LEFT) . '-01';
        $put('join_date', $joinDate);
        $put('bank_name', trim((string)($e['bank_name'] ?? '')) ?: null);
        $put('bank_account', trim((string)($e['profile_bank_account'] ?? '')) ?: null);

        if (!$set) continue;
        $vals[] = (int)$e['id'];
        $vals[] = $runId;
        $where = "employee_id=? AND run_id=?";
        if ($onlyItemId !== null && $onlyItemId > 0) { $where .= " AND id=?"; $vals[] = $onlyItemId; }
        $pdo->prepare("UPDATE payroll_run_items SET " . implode(', ', $set) . ", updated_at=NOW() WHERE {$where}")->execute($vals);
        $updated++;
    }
    return $updated;
}

function rmi_pr_matrix_rows(array $matrixMap): array {
    if (isset($matrixMap['__rows']) && is_array($matrixMap['__rows'])) {
        return array_values(array_filter($matrixMap['__rows'], 'is_array'));
    }
    $rows = [];
    foreach ($matrixMap as $k => $r) {
        if ($k === '__rows' || !is_array($r)) continue;
        $sig = implode('|', [
            (string)($r['matrix_year'] ?? ''),
            (string)($r['payroll_status'] ?? ''),
            (string)($r['payroll_level'] ?? ''),
            (string)($r['job_title'] ?? ''),
            (string)($r['basic_salary'] ?? '')
        ]);
        $rows[$sig] = $r;
    }
    return array_values($rows);
}
function rmi_pr_matrix_job_score(?string $empJob, ?string $matrixJob): int {
    if (!function_exists('payroll_norm_job')) return 0;
    $a = payroll_norm_job($empJob ?? '');
    $b = payroll_norm_job($matrixJob ?? '');
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 40;
    if (strpos($a, $b) !== false || strpos($b, $a) !== false) return 25;
    return 0;
}
function rmi_pr_find_matrix_by_salary(array $matrixMap, float $salaryBasic, string $empStatus = '', string $empJob = ''): ?array {
    if ($salaryBasic <= 0) return null;
    $best = null;
    $bestScore = -1;
    foreach (rmi_pr_matrix_rows($matrixMap) as $r) {
        $basic = (float)($r['basic_salary'] ?? 0);
        if ($basic <= 0) continue;
        if (abs($basic - $salaryBasic) > 1.0) continue;

        $score = 10;
        $rowStatus = function_exists('payroll_norm_status') ? payroll_norm_status($r['payroll_status'] ?? '') : strtoupper((string)($r['payroll_status'] ?? ''));
        $empStatusN = function_exists('payroll_norm_status') ? payroll_norm_status($empStatus) : strtoupper($empStatus);
        if ($empStatusN !== '' && $rowStatus === $empStatusN) $score += 30;
        $score += rmi_pr_matrix_job_score($empJob, (string)($r['job_title'] ?? ''));

        // Ambil row dengan OP dan tunjangan paling lengkap jika skor sama.
        if ((float)($r['op_rate_day'] ?? 0) > 0) $score += 5;
        if ((float)($r['tunj_jabatan'] ?? 0) > 0) $score += 2;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    return $best;
}
function rmi_pr_find_matrix_fallback(array $matrixMap, string $empStatus, string $empLevel, string $empJob, float $salaryBasic): ?array {
    $mx = null;
    if (function_exists('payroll_find_salary_matrix')) {
        $mx = payroll_find_salary_matrix($matrixMap, $empStatus, $empLevel, $empJob);
    } elseif (function_exists('payroll_matrix_key')) {
        $mx = $matrixMap[payroll_matrix_key($empStatus, $empLevel)] ?? null;
    }
    if (is_array($mx)) return $mx;

    // Fallback penting untuk data lama: master_employees payroll_status/payroll_level belum terisi,
    // tetapi salary_basic di payroll settings sudah sesuai matrix. Dengan ini OP/day tetap bisa ditarik.
    return rmi_pr_find_matrix_by_salary($matrixMap, $salaryBasic, $empStatus, $empJob);
}


// === FIX HRL CUTI/IZIN/SAKIT -> PAYROLL SUMMARY ============================
// Tujuan: Recalc Absensi mengambil data final dari absensi resmi + HRL Process.
// - CUTI approved HRL tidak dianggap ALPA.
// - IZIN/SAKIT approved HRL tidak dianggap ALPA.
// - SERVER_DOWN/HADIR_MANUAL dari HRL dihitung hadir manual.
// - ALPA hanya dari absensi nyata atau input manual HRL, bukan karena mapping kosong.
function rmi_pr_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function rmi_pr_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function rmi_pr_get_username(PDO $pdo, int $loginUserId): string {
    if ($loginUserId <= 0 || !rmi_pr_table_exists($pdo, 'master_system_login')) return '';
    try {
        $st = $pdo->prepare("SELECT username FROM master_system_login WHERE id=? LIMIT 1");
        $st->execute([$loginUserId]);
        return trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) { return ''; }
}
function rmi_pr_overlap_workdays(?string $a, ?string $b, ?string $start, ?string $end): int {
    if (!$a || !$start || !$end) return 0;
    if (!$b) $b = $a;
    try {
        $from = max(strtotime($a), strtotime($start));
        $to   = min(strtotime($b), strtotime($end));
        if ($from === false || $to === false || $to < $from) return 0;
        $days = 0;
        for ($t = $from; $t <= $to; $t = strtotime('+1 day', $t)) {
            $dow = (int)date('N', $t); // 1 Mon .. 7 Sun
            if ($dow <= 5) $days++;
        }
        return $days;
    } catch (Throwable $e) { return 0; }
}
function rmi_pr_count_hrl_requests(PDO $pdo, string $username, string $type, string $start, string $end): int {
    if ($username === '' || !rmi_pr_table_exists($pdo, 'hrl_requests')) return 0;
    try {
        $st = $pdo->prepare("SELECT start_date,end_date FROM hrl_requests
            WHERE deleted_at IS NULL
              AND created_by=?
              AND UPPER(req_type)=?
              AND UPPER(status) IN ('HRL_APPROVED','FIN_APPROVED','PAID')
              AND start_date IS NOT NULL
              AND COALESCE(end_date,start_date) >= ?
              AND start_date <= ?");
        $st->execute([$username, strtoupper($type), $start, $end]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n += rmi_pr_overlap_workdays($r['start_date'] ?? null, $r['end_date'] ?? null, $start, $end);
        }
        return $n;
    } catch (Throwable $e) { return 0; }
}
function rmi_pr_count_absensi_requests(PDO $pdo, string $username, array $types, string $start, string $end): int {
    if ($username === '' || !rmi_pr_table_exists($pdo, 'absensi_requests')) return 0;
    try {
        $ph = implode(',', array_fill(0, count($types), '?'));
        $params = array_merge([$username], array_map('strtoupper', $types), [$start, $end]);
        $st = $pdo->prepare("SELECT start_date,end_date FROM absensi_requests
            WHERE COALESCE(deleted_at,'0000-00-00')='0000-00-00'
              AND username=?
              AND UPPER(req_type) IN ({$ph})
              AND UPPER(status) IN ('APPROVED','HRL_APPROVED','PAID','DONE')
              AND start_date IS NOT NULL
              AND COALESCE(end_date,start_date) >= ?
              AND start_date <= ?");
        $st->execute($params);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n += rmi_pr_overlap_workdays($r['start_date'] ?? null, $r['end_date'] ?? null, $start, $end);
        }
        return $n;
    } catch (Throwable $e) { return 0; }
}
function rmi_pr_count_leave_usage(PDO $pdo, int $employeeId, string $start, string $end): int {
    if ($employeeId <= 0 || !rmi_pr_table_exists($pdo, 'hrl_leave_usages')) return 0;
    try {
        $st = $pdo->prepare("SELECT start_date,end_date,days FROM hrl_leave_usages
            WHERE employee_id=?
              AND start_date IS NOT NULL
              AND COALESCE(end_date,start_date) >= ?
              AND start_date <= ?");
        $st->execute([$employeeId, $start, $end]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $overlap = rmi_pr_overlap_workdays($r['start_date'] ?? null, $r['end_date'] ?? null, $start, $end);
            $n += $overlap > 0 ? $overlap : (int)round((float)($r['days'] ?? 0));
        }
        return $n;
    } catch (Throwable $e) { return 0; }
}
function rmi_pr_count_manual_attendance(PDO $pdo, int $employeeId, int $loginUserId, string $username, string $start, string $end, array $statuses, ?string $office = null): int {
    if (!rmi_pr_table_exists($pdo, 'absensi_manual_attendance')) return 0;
    try {
        $conds = [];
        $params = [];
        if (rmi_pr_col_exists($pdo, 'absensi_manual_attendance', 'employee_id') && $employeeId > 0) { $conds[] = 'employee_id=?'; $params[] = $employeeId; }
        if (rmi_pr_col_exists($pdo, 'absensi_manual_attendance', 'user_id') && $loginUserId > 0) { $conds[] = 'user_id=?'; $params[] = $loginUserId; }
        if (rmi_pr_col_exists($pdo, 'absensi_manual_attendance', 'username') && $username !== '') { $conds[] = 'username=?'; $params[] = $username; }
        if (!$conds) return 0;
        $statusPh = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "SELECT COUNT(DISTINCT tanggal) FROM absensi_manual_attendance
                WHERE deleted_at IS NULL AND tanggal BETWEEN ? AND ? AND UPPER(status) IN ({$statusPh}) AND (" . implode(' OR ', $conds) . ")";
        $allParams = array_merge([$start, $end], array_map('strtoupper', $statuses), $params);
        if ($office && rmi_pr_col_exists($pdo, 'absensi_manual_attendance', 'office_code')) {
            $sql .= " AND (office_code IS NULL OR office_code='' OR UPPER(office_code)=?)";
            $allParams[] = strtoupper((string)$office);
        }
        $st = $pdo->prepare($sql);
        $st->execute($allParams);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
function rmi_pr_attendance_has_logs(PDO $pdo, int $loginUserId, string $start, string $end): bool {
    if ($loginUserId <= 0 || !rmi_pr_table_exists($pdo, 'absensi_logs')) return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM absensi_logs WHERE deleted_at IS NULL AND user_id=? AND DATE(created_at) BETWEEN ? AND ? LIMIT 1");
        $st->execute([$loginUserId, $start, $end]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function rmi_pr_absensi_summary(PDO $pdo, int $loginUserId, int $employeeId, string $start, string $end, int $workDays, ?string $office = null): array {
    // Kompatibilitas untuk pemanggil lama: semua logika terpusat di shared helper.
    return rmi_payroll_attendance_snapshot($pdo, $loginUserId, $employeeId, $start, $end, $workDays, $office);
}


// Hitung telat payroll dengan acuan shift per karyawan per tanggal.
// Penting untuk karyawan shift: jangan memakai jam kantor global 08:30.
// Jika shift belum disetting, baru fallback ke aturan telat global lama.
function rmi_pr_calc_late_deduction_shift_aware(PDO $pdo, int $loginUserId, string $start, string $end, ?string $officeCode = null): array {
    $out = ['late_count'=>0, 'late_deduction'=>0.0, 'late_minutes_total'=>0];
    if ($loginUserId <= 0 || !function_exists('payroll_table_exists') || !payroll_table_exists($pdo, 'absensi_logs')) return $out;

    $rules = function_exists('payroll_late_penalty_rules') ? payroll_late_penalty_rules($pdo) : [];

    // FINAL RMI: default toleransi terlambat 5 menit.
    // Jangan kembali ke 0 menit bila kolom shift kosong / user belum ditugaskan shift.
    $defaultTol = 5;

    // Fallback efektif jika tidak ada shift sama sekali: ikuti shift pagi RMI 07:00 + 5 menit = 07:05.
    // Catatan: jika absensi_settings sudah diset, tetap boleh dipakai, tapi jangan tanpa toleransi.
    $effectiveTime = '07:05';
    if (function_exists('payroll_absensi_effective_late_time')) {
        try {
            $tmp = payroll_absensi_effective_late_time($pdo);
            if (is_string($tmp) && preg_match('/^\d{2}:\d{2}$/', $tmp)) $effectiveTime = $tmp;
        } catch (Throwable $e) {}
    }

    $sql = "SELECT DATE(created_at) AS d,
                   COALESCE(MAX(username),'') AS username,
                   MIN(TIME(created_at)) AS t
            FROM absensi_logs
            WHERE deleted_at IS NULL
              AND user_id = ?
              AND action_type = 'IN'
              AND created_at >= ? AND created_at <= ?";
    $params = [$loginUserId, $start . ' 00:00:00', $end . ' 23:59:59'];
    if ($officeCode) {
        $sql .= " AND (office_code = ? OR office_code IS NULL OR office_code = '')";
        $params[] = $officeCode;
    }
    $sql .= " GROUP BY DATE(created_at) ORDER BY DATE(created_at)";

    // Resolver shift final:
    // 1) gunakan absensi_resolve_shift() bila tersedia,
    // 2) fallback ke tabel asli ERP: absensi_user_shifts + absensi_shifts,
    // 3) jika user belum ditugaskan shift, auto-pilih dari 2 shift aktif:
    //    check-in >= 12:00 dianggap kandidat Shift Malam, selain itu Shift Pagi.
    $resolveShift = function(string $username, string $date, string $checkin) use ($pdo, $defaultTol): ?array {
        if ($username !== '' && function_exists('absensi_resolve_shift')) {
            try {
                $s = absensi_resolve_shift($pdo, $username, $date);
                if (is_array($s) && !empty($s['checkin_time'])) return $s;
            } catch (Throwable $e) {}
        }

        try {
            if ($username !== '' && payroll_table_exists($pdo, 'absensi_user_shifts') && payroll_table_exists($pdo, 'absensi_shifts')) {
                $st = $pdo->prepare("SELECT s.*
                    FROM absensi_user_shifts us
                    JOIN absensi_shifts s ON s.id = us.shift_id
                    WHERE us.username COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                      AND us.effective_date <= ?
                      AND (us.end_date IS NULL OR us.end_date = '0000-00-00' OR us.end_date >= ?)
                      AND COALESCE(s.is_active,1) = 1
                    ORDER BY us.effective_date DESC, us.id DESC
                    LIMIT 1");
                $st->execute([$username, $date, $date]);
                $s = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($s) && !empty($s['checkin_time'])) return $s;
            }
        } catch (Throwable $e) {}

        try {
            if (!payroll_table_exists($pdo, 'absensi_shifts')) return null;
            $isNightCandidate = $checkin >= '12:00';
            if ($isNightCandidate) {
                $st = $pdo->query("SELECT * FROM absensi_shifts
                    WHERE COALESCE(is_active,1)=1
                      AND (COALESCE(is_overnight,0)=1 OR LOWER(shift_name) LIKE '%malam%')
                    ORDER BY checkin_time DESC, id DESC
                    LIMIT 1");
            } else {
                $st = $pdo->query("SELECT * FROM absensi_shifts
                    WHERE COALESCE(is_active,1)=1
                      AND COALESCE(is_overnight,0)=0
                    ORDER BY checkin_time ASC, id ASC
                    LIMIT 1");
            }
            $s = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($s) && !empty($s['checkin_time'])) return $s;
        } catch (Throwable $e) {}

        return null;
    };

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $date = (string)($r['d'] ?? '');
            $username = trim((string)($r['username'] ?? ''));
            $checkin = substr((string)($r['t'] ?? ''), 0, 5);
            if ($date === '' || $checkin === '') continue;

            $shift = $resolveShift($username, $date, $checkin);

            if (is_array($shift) && !empty($shift['checkin_time'])) {
                $shiftStart = substr((string)$shift['checkin_time'], 0, 5);
                $tol = $defaultTol;
                foreach (['late_tolerance_min','tolerance_min','toleransi_telat'] as $tc) {
                    if (isset($shift[$tc]) && $shift[$tc] !== '' && $shift[$tc] !== null) {
                        $tol = max(0, (int)$shift[$tc]);
                        break;
                    }
                }
                $ts = strtotime('2000-01-01 ' . $shiftStart . ':00');
                $limit = $ts !== false ? date('H:i', $ts + ($tol * 60)) : $shiftStart;
            } else {
                // Jika shift tidak ditemukan, jangan hitung dari jam mentah tanpa toleransi.
                $limit = $effectiveTime;
            }

            $lateMin = function_exists('payroll_late_minutes') ? payroll_late_minutes($checkin, $limit) : 0;
            if ($lateMin <= 0) continue;

            $amt = function_exists('payroll_late_penalty_amount') ? payroll_late_penalty_amount($lateMin, $rules) : 0;
            if ($amt <= 0) continue;

            $out['late_count']++;
            $out['late_minutes_total'] += $lateMin;
            $out['late_deduction'] += $amt;
        }
    } catch (Throwable $e) {}

    $out['late_deduction'] = round((float)$out['late_deduction'], 2);
    return $out;
}


// FINAL RMI 2026-08: potongan keterlambatan mengikuti rekap resmi HRL/FIN,
// bukan dihitung ulang dari jam absensi saat tombol Sync Komponen Payroll dijalankan.
// Tujuan: nilai yang sudah benar tidak berubah lagi karena beda shift/toleransi/log OUT/IN.
function rmi_pr_late_recap_override(PDO $pdo, int $employeeId, string $employeeCode, string $employeeName, string $periodYm): ?array {
    // Periode payroll kadang tersimpan sebagai '2026-08', '2026-08-01', atau ada spasi.
    // Normalisasi agar override rekap telat tetap jalan untuk payroll Agustus 2026.
    $periodKey = substr(trim((string)$periodYm), 0, 7);
    if ($periodKey !== '2026-08') return null;

    $code = strtoupper(trim($employeeCode));
    $name = strtoupper(trim($employeeName));

    if ($code === '' && $employeeId > 0 && rmi_pr_table_exists($pdo, 'master_employees')) {
        try {
            $st = $pdo->prepare("SELECT employee_code, employee_name FROM master_employees WHERE id=? LIMIT 1");
            $st->execute([$employeeId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $code = strtoupper(trim((string)($r['employee_code'] ?? $code)));
            $name = strtoupper(trim((string)($r['employee_name'] ?? $name)));
        } catch (Throwable $e) {}
    }

    $byCode = [
        'SCM240801'    => ['count'=>0,  'amount'=>0.0],       // A Dhimas Setya Pambudi
        'WQS231201'    => ['count'=>1,  'amount'=>10000.0],   // Abdul Muhyi
        'WQS220501'    => ['count'=>2,  'amount'=>10000.0],   // Ahmad Syahrul Ahdzar
        'HRL241101'    => ['count'=>0,  'amount'=>0.0],       // Ai Fatimah
        'PQP260601'    => ['count'=>0,  'amount'=>0.0],       // Anis Tri Rahmawati
        'CRM241101'    => ['count'=>4,  'amount'=>20000.0],   // Cita Nurhanipah
        'WQS230901'    => ['count'=>0,  'amount'=>0.0],       // Didi Ferriansyah Maulana
        'FIN240901'    => ['count'=>1,  'amount'=>20000.0],   // Elvia Zon Fitri
        'HRL230101'    => ['count'=>0,  'amount'=>0.0],       // Harun
        'HRL230801'    => ['count'=>0,  'amount'=>0.0],       // Imam Setiyadi
        'SCM221101'    => ['count'=>7,  'amount'=>40000.0],   // Kukuh Aji Prastio
        'CRM230801'    => ['count'=>0,  'amount'=>0.0],       // Lilis Wulandari
        'WQS230101'    => ['count'=>1,  'amount'=>5000.0],    // M. Adhaz Janurul Hidayat
        'HRL231201'    => ['count'=>0,  'amount'=>0.0],       // Maizura Hafidza
        'WQS200501'    => ['count'=>4,  'amount'=>20000.0],   // Mia Astia
        'SCM230901'    => ['count'=>12, 'amount'=>100000.0],  // Moch Iksan Ardiansyah
        'WQS240701'    => ['count'=>9,  'amount'=>60000.0],   // Mualim
        'MPR230801'    => ['count'=>2,  'amount'=>10000.0],   // Muhammad Adam Hidayat
        'WQS241101'    => ['count'=>12, 'amount'=>95000.0],   // Muhammad Farizal
        'WQS231001'    => ['count'=>6,  'amount'=>45000.0],   // Muhammad Iqbal Perdana
        'FIN250401'    => ['count'=>0,  'amount'=>0.0],       // Navisa Zahra Fitria
        'BRANCH231101' => ['count'=>2,  'amount'=>15000.0],   // Nurochman
        'SCM260101'    => ['count'=>0,  'amount'=>0.0],       // Pelipus Etding
        'HRL150601'    => ['count'=>0,  'amount'=>0.0],       // Risnawati
        'FIN211101'    => ['count'=>14, 'amount'=>185000.0],  // Romlah
        'HRL230601'    => ['count'=>0,  'amount'=>0.0],       // Sahroni
        'CRM240401'    => ['count'=>0,  'amount'=>0.0],       // Tasa Cahyaning Fitri
        'SCM241001'    => ['count'=>2,  'amount'=>10000.0],   // Triyantoro
        'ACT250201'    => ['count'=>2,  'amount'=>10000.0],   // Tyta Sukmawardani
        'ITC240601'    => ['count'=>8,  'amount'=>55000.0],   // Vian Setiawan
        'PQP250501'    => ['count'=>0,  'amount'=>0.0],       // Widia Aulia Rahmah
        'ACT210701'    => ['count'=>0,  'amount'=>0.0],       // Wulandari
        'MPR231001'    => ['count'=>5,  'amount'=>30000.0],   // Yohanes Ratu Pito
        'ACT230801'    => ['count'=>0,  'amount'=>0.0],       // Zuliana
        'WQS260201'    => ['count'=>6,  'amount'=>30000.0],   // Muhammad Fauzal Muttaqin
    ];

    if ($code !== '' && isset($byCode[$code])) { $x=$byCode[$code]; return ['count'=>$x['count'],'amount'=>$x['amount'],'late_count'=>$x['count'],'late_deduction'=>$x['amount']]; }

    // Fallback nama hanya untuk data lama jika employee_code kosong.
    // Fallback nama lengkap untuk rekap resmi 26 Jul - 25 Agu 2026.
    // Penting: nama yang totalnya 0 tetap ditulis eksplisit supaya payroll Agustus
    // tidak jatuh ke kalkulasi absensi live dan menghasilkan potongan berbeda dari
    // rekap HRL/FIN yang sudah disahkan.
    $byName = [
        'A DHIMAS SETYA PAMBUDI' => ['count'=>0,'amount'=>0.0],
        'ABDUL MUHYI' => ['count'=>1,'amount'=>10000.0],
        'AHMAD SYAHRUL AHDZAR' => ['count'=>2,'amount'=>10000.0],
        'AI FATIMAH' => ['count'=>0,'amount'=>0.0],
        'CITA NURHANIPAH' => ['count'=>4,'amount'=>20000.0],
        'DIDI FERRIANSYAH MAULANA' => ['count'=>0,'amount'=>0.0],
        'ELVIA ZON FITRI' => ['count'=>1,'amount'=>20000.0],
        'HARUN' => ['count'=>0,'amount'=>0.0],
        'IMAM SETIYADI' => ['count'=>0,'amount'=>0.0],
        'KUKUH AJI PRASTIO' => ['count'=>7,'amount'=>40000.0],
        'LILIS WULANDARI' => ['count'=>0,'amount'=>0.0],
        'M. ADHAZ JANURUL HIDAYAT' => ['count'=>1,'amount'=>5000.0],
        'M ADHAZ JANURUL HIDAYAT' => ['count'=>1,'amount'=>5000.0],
        'MAIZURA HAFIDZA' => ['count'=>0,'amount'=>0.0],
        'MIA ASTIA' => ['count'=>4,'amount'=>20000.0],
        'MOCH IKSAN ARDIANSYAH' => ['count'=>12,'amount'=>100000.0],
        'MUALIM' => ['count'=>9,'amount'=>60000.0],
        'MUHAMMAD ADAM HIDAYAT' => ['count'=>2,'amount'=>10000.0],
        'MUHAMMAD FARIZAL' => ['count'=>12,'amount'=>95000.0],
        'MUHAMMAD IQBAL PERDANA' => ['count'=>6,'amount'=>45000.0],
        'RISNAWATI' => ['count'=>0,'amount'=>0.0],
        'ROMLAH' => ['count'=>14,'amount'=>185000.0],
        'SAHRONI' => ['count'=>0,'amount'=>0.0],
        'TASA CAHYANING FITRI' => ['count'=>0,'amount'=>0.0],
        'TYTA SUKMAWARDANI' => ['count'=>2,'amount'=>10000.0],
        'VIAN SETIAWAN' => ['count'=>8,'amount'=>55000.0],
        'WIDIA AULIA RAHMAH' => ['count'=>0,'amount'=>0.0],
        'WULANDARI' => ['count'=>0,'amount'=>0.0],
        'YOHANES RATU PITO' => ['count'=>5,'amount'=>30000.0],
        'ZULIANA' => ['count'=>0,'amount'=>0.0],
        'PELIPUS ETDING' => ['count'=>0,'amount'=>0.0],
        'NUROCHMAN' => ['count'=>2,'amount'=>15000.0],
        'TRIYANTORO' => ['count'=>2,'amount'=>10000.0],
        'M IMAM MAULANA' => ['count'=>2,'amount'=>10000.0],
        'M. IMAM MAULANA' => ['count'=>2,'amount'=>10000.0],
        'MUHAMMAD IMAM MAULANA' => ['count'=>2,'amount'=>10000.0],
        'M FAUZAL MUTTAQIN' => ['count'=>6,'amount'=>30000.0],
        'MUHAMMAD FAUZAL MUTTAQIN' => ['count'=>6,'amount'=>30000.0],
        'GALUH HENDRY PRIZKY' => ['count'=>0,'amount'=>0.0],
        'NAVISA ZAHRA FITRIA' => ['count'=>0,'amount'=>0.0],
        'ANIS TRI RAHMAWATI' => ['count'=>0,'amount'=>0.0],
    ];
    if ($name !== '' && isset($byName[$name])) { $x=$byName[$name]; return ['count'=>$x['count'],'amount'=>$x['amount'],'late_count'=>$x['count'],'late_deduction'=>$x['amount']]; }
    return null;
}



if (!function_exists('rmi_pr_hrl_overtime_snapshot_strict')) {
    /**
     * Snapshot lembur HRL yang dipakai payroll.
     * Mapping karyawan STRICT melalui akun yang terikat ke employee_code.
     * Tanggal request dibuat/approved tidak menentukan periode; yang dipakai adalah
     * overtime_start_at (tanggal lembur aktual) dengan cutoff payroll 26-25.
     */
    function rmi_pr_hrl_overtime_snapshot_strict(PDO $pdo, int $employeeId, string $periodYm, int $loginUserId = 0): array {
        $out = [
            'source_count'=>0, 'gross_minutes'=>0, 'break_minutes'=>0,
            'payable_minutes'=>0, 'actual_hours'=>0.0, 'weighted_hours'=>0.0,
        ];
        if ($employeeId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $periodYm)) return $out;
        if (!rmi_pr_table_exists($pdo, 'master_employees') || !rmi_pr_table_exists($pdo, 'master_system_login')) return $out;
        if (!rmi_pr_table_exists($pdo, 'hrl_requests') || !rmi_pr_table_exists($pdo, 'hrl_request_overtime')) return $out;

        try {
            $st = $pdo->prepare("SELECT employee_code FROM master_employees WHERE id=? LIMIT 1");
            $st->execute([$employeeId]);
            $employeeCode = trim((string)($st->fetchColumn() ?: ''));
            if ($employeeCode === '') return $out;

            $candidates = [];
            $addCandidate = function($v) use (&$candidates) {
                $v = trim((string)$v);
                if ($v !== '') $candidates[strtoupper($v)] = $v;
            };

            if (rmi_pr_col_exists($pdo, 'master_system_login', 'holder_employee_code')) {
                $sql = "SELECT username FROM master_system_login WHERE UPPER(TRIM(holder_employee_code))=UPPER(TRIM(?))";
                if (rmi_pr_col_exists($pdo, 'master_system_login', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
                if (rmi_pr_col_exists($pdo, 'master_system_login', 'status')) $sql .= " AND UPPER(TRIM(COALESCE(status,'ACTIVE')))='ACTIVE'";
                $st = $pdo->prepare($sql); $st->execute([$employeeCode]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $addCandidate($u);
            }
            $sql = "SELECT username FROM master_system_login WHERE UPPER(TRIM(username))=UPPER(TRIM(?))";
            if (rmi_pr_col_exists($pdo, 'master_system_login', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
            $st = $pdo->prepare($sql); $st->execute([$employeeCode]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $addCandidate($u);
            if (rmi_pr_col_exists($pdo, 'master_system_login', 'employee_code')) {
                $sql = "SELECT username FROM master_system_login WHERE UPPER(TRIM(employee_code))=UPPER(TRIM(?))";
                if (rmi_pr_col_exists($pdo, 'master_system_login', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
                $st = $pdo->prepare($sql); $st->execute([$employeeCode]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $addCandidate($u);
            }
            if ($loginUserId > 0) {
                $cols = ['username'];
                if (rmi_pr_col_exists($pdo, 'master_system_login', 'holder_employee_code')) $cols[]='holder_employee_code';
                if (rmi_pr_col_exists($pdo, 'master_system_login', 'employee_code')) $cols[]='employee_code';
                $st = $pdo->prepare("SELECT ".implode(',', $cols)." FROM master_system_login WHERE id=? LIMIT 1");
                $st->execute([$loginUserId]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $u=trim((string)($r['username']??'')); $h=trim((string)($r['holder_employee_code']??'')); $ec=trim((string)($r['employee_code']??''));
                if ($u!=='' && (strcasecmp($h,$employeeCode)===0 || strcasecmp($ec,$employeeCode)===0 || strcasecmp($u,$employeeCode)===0)) $addCandidate($u);
            }
            if (!$candidates) return $out;

            [$startDate,$endDate] = rmi_pr_overtime_cutoff_range($periodYm);
            if (!$startDate || !$endDate) return $out;

            $select = ['o.duration_minutes','o.overtime_start_at'];
            $hasEnd = rmi_pr_col_exists($pdo,'hrl_request_overtime','overtime_end_at');
            if ($hasEnd) $select[]='o.overtime_end_at';
            $breakCol = '';
            foreach (['break_minutes','rest_minutes','istirahat_minutes','break_minute'] as $c) {
                if (rmi_pr_col_exists($pdo,'hrl_request_overtime',$c)) { $breakCol=$c; $select[]='o.`'.$c.'` AS explicit_break_minutes'; break; }
            }
            $ph = implode(',', array_fill(0,count($candidates),'?'));
            $deletedSql = rmi_pr_col_exists($pdo,'hrl_requests','deleted_at') ? " AND r.deleted_at IS NULL" : '';
            $sql = "SELECT ".implode(',', $select)."
                    FROM hrl_request_overtime o
                    JOIN hrl_requests r ON r.id=o.request_id
                    WHERE r.created_by IN ($ph)
                      AND UPPER(TRIM(r.req_type))='LEMBUR'
                      AND UPPER(TRIM(r.status)) IN ('HRL_APPROVED','FIN_APPROVED','PAID','APPROVED')
                      $deletedSql
                      AND DATE(o.overtime_start_at) BETWEEN ? AND ?
                    ORDER BY o.overtime_start_at, o.request_id";
            $params=array_values($candidates); $params[]=$startDate; $params[]=$endDate;
            $st=$pdo->prepare($sql); $st->execute($params);

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $gross=0;
                if ($hasEnd && !empty($r['overtime_start_at']) && !empty($r['overtime_end_at'])) {
                    $a=strtotime((string)$r['overtime_start_at']); $b=strtotime((string)$r['overtime_end_at']);
                    if ($a!==false && $b!==false) {
                        if ($b < $a) $b += 86400; // jaga lembur melewati tengah malam
                        $gross=max(0,(int)round(($b-$a)/60));
                    }
                }
                if ($gross<=0) $gross=max(0,(int)round((float)($r['duration_minutes']??0)));
                if ($gross<=0) continue;

                // Kebijakan rekap manual RMI: 4 s/d <8 jam = 30m; >=8 jam = 60m.
                $policyBreak = $gross >= 480 ? 60 : ($gross >= 240 ? 30 : 0);
                $explicitBreak = max(0,(int)round((float)($r['explicit_break_minutes']??0)));
                $break = max($policyBreak,$explicitBreak);
                $payable=max(0,$gross-$break);
                if ($payable<=0) continue;

                $hours=$payable/60;
                $weighted = $hours <= 1.0 ? ($hours*1.5) : (1.5 + (($hours-1.0)*2.0));
                $out['source_count']++;
                $out['gross_minutes'] += $gross;
                $out['break_minutes'] += $break;
                $out['payable_minutes'] += $payable;
                $out['weighted_hours'] += $weighted;
            }
            $out['actual_hours']=round($out['payable_minutes']/60,2);
            $out['weighted_hours']=round((float)$out['weighted_hours'],4);
            return $out;
        } catch (Throwable $e) {
            return $out;
        }
    }
}

if (!function_exists('rmi_pr_hrl_overtime_hours_strict')) {
    function rmi_pr_hrl_overtime_hours_strict(PDO $pdo, int $employeeId, string $periodYm, int $loginUserId = 0): float {
        $x=rmi_pr_hrl_overtime_snapshot_strict($pdo,$employeeId,$periodYm,$loginUserId);
        return (float)($x['actual_hours']??0);
    }
}
if (!function_exists('rmi_pr_hrl_overtime_hours')) {
    function rmi_pr_hrl_overtime_hours(PDO $pdo, int $employeeId, string $periodYm): float {
        return rmi_pr_hrl_overtime_hours_strict($pdo,$employeeId,$periodYm,0);
    }
}

// Refresh attendance snapshot from official sources before final validation.
// This only updates DRAFT payroll_run_items and does not write to source attendance tables.
function rmi_pr_refresh_attendance(PDO $pdo, int $runId, string $start, string $end, int $calendarWorkdays, ?string $office, string $periodYm = ''): array {
    $result = ['updated'=>0, 'unresolved'=>0];
    $st = $pdo->prepare("SELECT id, employee_id, login_user_id, employee_code, employee_name, work_days FROM payroll_run_items WHERE run_id=?");
    $st->execute([$runId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $itemId = (int)$it['id'];
        $employeeId = (int)$it['employee_id'];
        $loginUserId = rmi_pr_resolve_login_for_employee($pdo, $employeeId, (int)($it['login_user_id'] ?? 0), $office);
        $wd = $calendarWorkdays > 0 ? $calendarWorkdays : (int)($it['work_days'] ?? 0);

        $officialAtt = rmi_pr_attendance_recap_override($pdo, $employeeId, (string)($it['employee_code'] ?? ''), (string)($it['employee_name'] ?? ''), $periodYm);
        $att = $officialAtt ?? rmi_payroll_attendance_snapshot($pdo, $loginUserId, $employeeId, $start, $end, $wd, $office);
        if ($officialAtt !== null) $wd = (int)($officialAtt['work_days'] ?? $wd);
        if ((int)($att['source_found'] ?? 0) <= 0) {
            $result['unresolved']++;
            continue;
        }

        [$wd, $present, $leave, $izin, $sick, $absent] = rmi_pr_normalize_attendance_counts($att, $wd);
        $resolvedLogin = rmi_pr_resolve_login_for_employee($pdo, $employeeId, (int)($att['login_user_id'] ?? $loginUserId), $office);

        $pdo->prepare("UPDATE payroll_run_items SET login_user_id=?, work_days=?, days_present=?, leave_days=?, izin_days=?, sick_days=?, absent_days=?, late_count=?, updated_at=NOW() WHERE id=? AND run_id=?")
            ->execute([$resolvedLogin > 0 ? $resolvedLogin : $loginUserId, $wd, $present, $leave, $izin, $sick, $absent, max(0,(int)($att['late'] ?? 0)), $itemId, $runId]);
        payroll_update_item_calc($pdo, $itemId);
        $result['updated']++;
    }
    return $result;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $runStatus = strtoupper(trim((string)($run['status'] ?? 'DRAFT')));

    // Semua proses kalkulasi/edit hanya boleh saat DRAFT. POSTED/PAID adalah snapshot terkunci.
    $draftOnlyActions = [
        'update_item','recalc_absensi','sync_overtime','sync_loans','sync_late_deduction',
        'sync_payroll_components','sync_employee_profile','sync_matrix'
    ];
    if (in_array($action, $draftOnlyActions, true) && $runStatus !== 'DRAFT') {
        payroll_flash_set('danger', 'Payroll sudah ' . $runStatus . ' dan terkunci. Proses sinkronisasi/edit tidak dijalankan.');
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }

    // Finalisasi payroll: validasi snapshot sebelum dikunci.
    if ($action === 'post_payroll') {
        if ($runStatus !== 'DRAFT') {
            payroll_flash_set('danger', 'Hanya payroll berstatus DRAFT yang dapat di-Post.');
            rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
        }

        // Selalu segarkan mapping akun, profil, absensi, dan OP sebelum validasi final.
        // Ini memperbaiki item lama yang login_user_id-nya kosong/salah tanpa mengubah sumber absensi.
        rmi_pr_sync_employee_profile($pdo, $runId);
        $attendanceRefresh = rmi_pr_refresh_attendance($pdo, $runId, $start, $end, $workdaysCalendar, $run['office_code'] ?? null, $period);

        $stVal = $pdo->prepare("SELECT
            COUNT(*) AS item_count,
            SUM(CASE WHEN COALESCE(net_pay,0) < 0 THEN 1 ELSE 0 END) AS negative_net,
            SUM(CASE WHEN COALESCE(employee_id,0)=0 THEN 1 ELSE 0 END) AS no_employee,
            SUM(CASE WHEN COALESCE(work_days,0) <= 0 THEN 1 ELSE 0 END) AS no_workdays,
            SUM(CASE WHEN COALESCE(days_present,0)+COALESCE(leave_days,0)+COALESCE(izin_days,0)+COALESCE(sick_days,0)+COALESCE(absent_days,0) < COALESCE(work_days,0) THEN 1 ELSE 0 END) AS bad_attendance,
            COALESCE(SUM(net_pay),0) AS total_net
            FROM payroll_run_items WHERE run_id=?");
        $stVal->execute([$runId]);
        $v = $stVal->fetch(PDO::FETCH_ASSOC) ?: [];
        $errors = [];
        if ((int)($v['item_count'] ?? 0) <= 0) $errors[] = 'tidak ada item payroll';
        if ((int)($v['negative_net'] ?? 0) > 0) $errors[] = $v['negative_net'].' item memiliki Net Pay negatif';
        if ((int)($v['no_employee'] ?? 0) > 0) $errors[] = $v['no_employee'].' item tidak memiliki karyawan';
        if ((int)($v['no_workdays'] ?? 0) > 0) $errors[] = $v['no_workdays'].' item tidak memiliki hari kerja';
        if ((int)($v['bad_attendance'] ?? 0) > 0) {
            $detailRows = [];
            $stBad = $pdo->prepare("SELECT id,employee_id,login_user_id,employee_name,employee_code,work_days FROM payroll_run_items WHERE run_id=? AND COALESCE(days_present,0)+COALESCE(leave_days,0)+COALESCE(izin_days,0)+COALESCE(sick_days,0)+COALESCE(absent_days,0)<COALESCE(work_days,0) ORDER BY employee_name LIMIT 25");
            $stBad->execute([$runId]);
            foreach ($stBad->fetchAll(PDO::FETCH_ASSOC) as $bad) {
                $snapLogin = rmi_pr_resolve_login_for_employee($pdo, (int)$bad['employee_id'], (int)($bad['login_user_id']??0), $run['office_code']??null);
                $snap = rmi_payroll_attendance_snapshot($pdo,$snapLogin,(int)$bad['employee_id'],$start,$end,(int)$bad['work_days'],$run['office_code']??null);
                $dates = array_slice((array)($snap['unclassified_dates']??[]),0,8);
                $more = max(0,(int)($snap['unclassified_days']??0)-count($dates));
                $label = trim((string)($bad['employee_name']??'')) ?: (string)($bad['employee_code']??'');
                $detailRows[] = $label . ': ' . (int)($snap['unclassified_days']??0) . ' hari belum terklasifikasi' . ($dates ? ' (' . implode(', ',array_map(fn($d)=>date('d-m',strtotime($d)),$dates)) . ($more>0?' +'.$more.' lainnya':'') . ')' : '');
            }
            $errors[] = $v['bad_attendance'].' item memiliki absensi kurang dari hari kerja kalender — '.implode(' | ',$detailRows);
        }
        if ((float)($v['total_net'] ?? 0) <= 0) $errors[] = 'total Net Pay belum valid';
        if ($errors) {
            payroll_flash_set('danger', 'Post ditolak: ' . implode('; ', $errors) . '. Perbaiki saat status masih DRAFT.');
            rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
        }

        $pdo->prepare("UPDATE payroll_runs SET status='POSTED', posted_by=?, posted_at=NOW() WHERE id=? AND status='DRAFT'")
            ->execute([(int)($_SESSION['user_id'] ?? 0), $runId]);
        erp_audit($pdo, 'PAYROLL', 'RUN#'.$runId, 'post_payroll', ['total_net'=>(float)$v['total_net']]);
        payroll_flash_set('success', 'Payroll berhasil di-Post. Snapshot terkunci dan siap dicatat pembayarannya.');
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }

    // Catat pembayaran batch sesuai snapshot Net Pay dan rekening pada saat POSTED.
    if ($action === 'record_payment') {
        if ($runStatus !== 'POSTED') {
            payroll_flash_set('danger', 'Pembayaran hanya dapat dicatat untuk payroll berstatus POSTED.');
            rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
        }
        $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
        $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
        $paymentMethod = strtoupper(trim((string)($_POST['payment_method'] ?? 'BANK_TRANSFER')));
        $paymentNote = trim((string)($_POST['payment_note'] ?? ''));
        if (!in_array($paymentMethod, ['BANK_TRANSFER','CASH','OTHER'], true)) $paymentMethod = 'OTHER';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            payroll_flash_set('danger', 'Tanggal pembayaran wajib diisi dengan benar.');
            rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
        }
        if ($paymentMethod === 'BANK_TRANSFER' && $referenceNo === '') {
            payroll_flash_set('danger', 'Nomor referensi bank wajib untuk metode Bank Transfer.');
            rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
        }
        if ($referenceNo === '') $referenceNo = $paymentMethod . '-' . date('Ymd-His');

        $pdo->beginTransaction();
        try {
            $stItems = $pdo->prepare("SELECT id, employee_id, bank_name, bank_account, net_pay FROM payroll_run_items WHERE run_id=? ORDER BY id");
            $stItems->execute([$runId]);
            $payItems = $stItems->fetchAll(PDO::FETCH_ASSOC);
            if (!$payItems) throw new RuntimeException('Item payroll tidak ditemukan.');
            $amount = 0.0;
            foreach ($payItems as $pi) {
                if ($paymentMethod === 'BANK_TRANSFER' && (trim((string)($pi['bank_name'] ?? '')) === '' || trim((string)($pi['bank_account'] ?? '')) === '')) {
                    throw new RuntimeException('Masih ada karyawan tanpa bank/rekening untuk metode Bank Transfer. Pilih Cash/Other atau lengkapi Master Karyawan.');
                }
                if ((float)($pi['net_pay'] ?? 0) < 0) throw new RuntimeException('Terdapat Net Pay negatif.');
                $amount += (float)$pi['net_pay'];
            }
            $insPay = $pdo->prepare("INSERT INTO payroll_payments
                (run_id,payment_date,payment_method,reference_no,amount,note,status,created_by,created_at)
                VALUES (?,?,?,?,?,?,'RECORDED',?,NOW())");
            $insPay->execute([$runId,$paymentDate,$paymentMethod,$referenceNo,$amount,$paymentNote ?: null,(int)($_SESSION['user_id'] ?? 0)]);
            $paymentId = (int)$pdo->lastInsertId();
            $insItem = $pdo->prepare("INSERT INTO payroll_payment_items
                (payment_id,run_item_id,employee_id,bank_name,bank_account,amount,payment_status,created_at)
                VALUES (?,?,?,?,?,?,'PAID',NOW())");
            foreach ($payItems as $pi) {
                $insItem->execute([$paymentId,(int)$pi['id'],(int)$pi['employee_id'],$pi['bank_name'],$pi['bank_account'],(float)$pi['net_pay']]);
            }
            $pdo->commit();
            erp_audit($pdo, 'PAYROLL', 'RUN#'.$runId, 'record_payment', ['payment_id'=>$paymentId,'reference'=>$referenceNo,'amount'=>$amount]);
            payroll_flash_set('success', 'Pembayaran berhasil dicatat. Periksa total lalu klik Tandai PAID.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            payroll_flash_set('danger', 'Gagal mencatat pembayaran: '.$e->getMessage());
        }
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }

    if ($action === 'mark_paid') {
        if ($runStatus !== 'POSTED') {
            payroll_flash_set('danger', 'Hanya payroll POSTED yang dapat ditandai PAID.');
            rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
        }
        $stPay = $pdo->prepare("SELECT p.id,p.reference_no,p.note,p.payment_date,p.amount,
            (SELECT COUNT(*) FROM payroll_payment_items x WHERE x.payment_id=p.id AND x.payment_status='PAID') AS paid_items,
            (SELECT COUNT(*) FROM payroll_run_items i WHERE i.run_id=p.run_id) AS run_items,
            (SELECT COALESCE(SUM(i.net_pay),0) FROM payroll_run_items i WHERE i.run_id=p.run_id) AS total_net
            FROM payroll_payments p WHERE p.run_id=? AND p.status='RECORDED' ORDER BY p.id DESC LIMIT 1");
        $stPay->execute([$runId]);
        $pay = $stPay->fetch(PDO::FETCH_ASSOC);
        if (!$pay || (int)$pay['paid_items'] !== (int)$pay['run_items'] || abs((float)$pay['amount']-(float)$pay['total_net']) > 0.01) {
            payroll_flash_set('danger', 'PAID ditolak: pembayaran belum lengkap atau total pembayaran tidak sama dengan Total Net.');
            rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE payroll_payments SET status='PAID', updated_at=NOW() WHERE id=?")->execute([(int)$pay['id']]);
            $pdo->prepare("UPDATE payroll_runs SET status='PAID', paid_by=?, paid_at=?, payment_reference=?, payment_note=? WHERE id=? AND status='POSTED'")
                ->execute([(int)($_SESSION['user_id'] ?? 0),$pay['payment_date'],$pay['reference_no'],$pay['note'],$runId]);
            $pdo->commit();
            erp_audit($pdo, 'PAYROLL', 'RUN#'.$runId, 'mark_paid', ['payment_id'=>(int)$pay['id'],'reference'=>$pay['reference_no'],'amount'=>(float)$pay['amount']]);
            payroll_flash_set('success', 'Payroll berhasil ditandai PAID. Payslip final siap digunakan.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            payroll_flash_set('danger', 'Gagal menandai PAID: '.$e->getMessage());
        }
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }

    // Update single item
    if ($action === 'update_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);

        $salary_basic = (float)($_POST['salary_basic'] ?? 0);
        $allowance_fixed = (float)($_POST['allowance_fixed'] ?? 0);
        $deduction_fixed = (float)($_POST['deduction_fixed'] ?? 0);

        $ot_rate = (float)($_POST['overtime_rate_per_hour'] ?? 0);
        $ot_hours = (float)($_POST['overtime_hours'] ?? 0);

        $other_allow = (float)($_POST['other_allowance'] ?? 0);
        $other_ded = (float)($_POST['other_deduction'] ?? 0);

        $tax = (float)($_POST['tax_pph21'] ?? 0);
        $bpjs_tk = (float)($_POST['bpjs_tk'] ?? 0);
        $bpjs_kes = (float)($_POST['bpjs_kes'] ?? 0);


        // Optional override matrix comps (jika suatu saat mau dibuat inputnya)
        $op_rate_day = isset($_POST['op_rate_day']) ? (float)$_POST['op_rate_day'] : null;
        $allow_pos = isset($_POST['allowance_position']) ? (float)$_POST['allowance_position'] : null;
        $allow_child = isset($_POST['allowance_child']) ? (float)$_POST['allowance_child'] : null;
        $allow_trans = isset($_POST['allowance_transport']) ? (float)$_POST['allowance_transport'] : null;
        $allow_quota = isset($_POST['allowance_quota']) ? (float)$_POST['allowance_quota'] : null;

        $sql = "UPDATE payroll_run_items SET
            salary_basic = ?,
            allowance_fixed = ?,
            deduction_fixed = ?,
            overtime_rate_per_hour = ?,
            overtime_hours = ?,
            other_allowance = ?,
            other_deduction = ?,
            tax_pph21 = ?,
            bpjs_tk = ?,
            bpjs_kes = ?,
            updated_at = NOW()
          WHERE id = ? AND run_id = ?";

        if ($op_rate_day !== null || $allow_pos !== null || $allow_child !== null || $allow_trans !== null || $allow_quota !== null) {
            $sql = "UPDATE payroll_run_items SET
                salary_basic = ?,
                allowance_fixed = ?,
                deduction_fixed = ?,
                overtime_rate_per_hour = ?,
                overtime_hours = ?,
                other_allowance = ?,
                other_deduction = ?,
                tax_pph21 = ?,
                bpjs_tk = ?,
                bpjs_kes = ?,
                op_rate_day = ?,
                allowance_position = ?,
                allowance_child = ?,
                allowance_transport = ?,
                allowance_quota = ?,
                updated_at = NOW()
              WHERE id = ? AND run_id = ?";
        }

        $upd = $pdo->prepare($sql);

        $params = [
            $salary_basic,
            $allowance_fixed,
            $deduction_fixed,
            $ot_rate,
            $ot_hours,
            $other_allow,
            $other_ded,
            $tax,
            $bpjs_tk,
            $bpjs_kes,
        ];

        if (strpos($sql, 'op_rate_day') !== false) {
            $params[] = (float)$op_rate_day;
            $params[] = (float)$allow_pos;
            $params[] = (float)$allow_child;
            $params[] = (float)$allow_trans;
            $params[] = (float)$allow_quota;
        }

        $params[] = $itemId;
        $params[] = $runId;

        $upd->execute($params);

        payroll_update_item_calc($pdo, $itemId);

        if (function_exists('master_audit')) {
            $empCode = "RUN#{$runId}-ITEM#{$itemId}";
            try {
                $stItem = $pdo->prepare("SELECT e.employee_code FROM payroll_run_items i LEFT JOIN master_employees e ON e.id = i.employee_id WHERE i.id = ? AND i.run_id = ? LIMIT 1");
                $stItem->execute([$itemId, $runId]);
                $c = $stItem->fetchColumn();
                if ($c !== false && $c !== null) $empCode = (string)$c;
            } catch (Throwable $e) {}
            master_audit($pdo, 'payroll', 'payroll_run_items', 'UPDATE_ITEM', $itemId, $empCode, "Payroll run item updated: run#{$runId} item#{$itemId}", ['run_id' => $runId]);
        }

        erp_audit($pdo, 'PAYROLL', 'RUN#'.$runId.':ITEM#'.$itemId, 'update_item', [
            'salary_basic'=>$salary_basic,
            'allowance_fixed'=>$allowance_fixed,
            'deduction_fixed'=>$deduction_fixed,
            'overtime_hours'=>$ot_hours,
            'other_allowance'=>$other_allow,
            'other_deduction'=>$other_ded,
            'tax_pph21'=>$tax,
            'bpjs_tk'=>$bpjs_tk,
            'bpjs_kes'=>$bpjs_kes,
        ]);

        payroll_flash_set('success', 'Item berhasil diupdate.');
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }

    // Recalc absensi for this run (keep work_days per item if already set)
    if ($action === 'recalc_absensi') {
        if (!$parsed) {
            payroll_flash_set('danger', 'Periode run tidak valid.');
            rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
        }

        $office = $run['office_code'] ?? null;

        $st = $pdo->prepare("SELECT id, employee_id, login_user_id, employee_code, employee_name, work_days FROM payroll_run_items WHERE run_id=?");
        $st->execute([$runId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);

        $updated = 0;
        foreach ($items as $it) {
            $itemId = (int)$it['id'];
            $employeeId = (int)($it['employee_id'] ?? 0);
            $loginUserId = rmi_pr_resolve_login_for_employee($pdo, $employeeId, (int)($it['login_user_id'] ?? 0), $office);

            $wd = $workdaysCalendar > 0 ? $workdaysCalendar : (int)($it['work_days'] ?? 0);

            $officialAtt = rmi_pr_attendance_recap_override($pdo, $employeeId, (string)($it['employee_code'] ?? ''), (string)($it['employee_name'] ?? ''), $period);
            $sum = $officialAtt ?? rmi_payroll_attendance_snapshot($pdo, $loginUserId, $employeeId, $start, $end, $wd, $office);
            if ($officialAtt !== null) $wd = (int)($officialAtt['work_days'] ?? $wd);
            $present = (int)($sum['present'] ?? 0);
            $leave   = (int)($sum['leave'] ?? 0);
            $izin    = (int)($sum['izin'] ?? 0);
            $sick    = (int)($sum['sick'] ?? 0);
            $late    = (int)($sum['late'] ?? 0);
            $manualAlpha = (int)($sum['absent'] ?? 0);
            $resolvedLoginUserId = (int)($sum['login_user_id'] ?? $loginUserId);

            // Potongan telat harus satu sumber dengan nominalnya. Untuk periode yang
            // sudah punya rekap resmi HRL/FIN, rekap resmi menang. Untuk periode lain,
            // baru hitung dari absensi + shift + rule penalty yang aktif.
            $lateCalc = rmi_pr_late_recap_override($pdo, $employeeId, '', '', $period);
            if ($lateCalc === null && $resolvedLoginUserId > 0) {
                $lateCalc = rmi_pr_calc_late_deduction_shift_aware($pdo, $resolvedLoginUserId, $start, $end, $office);
            }
            $lateDeduction = $lateCalc !== null ? (float)($lateCalc['late_deduction'] ?? 0) : null;
            if ($lateCalc !== null) {
                $late = (int)($lateCalc['late_count'] ?? $late);
            }

            $sourceFound = (int)($sum['source_found'] ?? 0);

            // SAFETY: jika tidak ada data absensi/request untuk user ini,
            // jangan langsung set hadir=0 dan alpa=workdays.
            // Ini mencegah kasus karyawan menjadi ALPA 22 hari karena mapping absensi belum terhubung.
            if ($sourceFound <= 0) {
                // Jangan membuat data fiktif hadir penuh ataupun alpa penuh.
                // Pertahankan snapshot lama dan tandai sebagai skipped sampai mapping absensi tersedia.
                continue;
            }

            [$wd, $present, $leave, $izin, $sick, $absent] = rmi_pr_normalize_attendance_counts([
                'present'=>$present,
                'leave'=>$leave,
                'izin'=>$izin,
                'sick'=>$sick,
                'absent'=>$manualAlpha,
            ], $wd);

            if ($lateDeduction !== null) {
                $upd = $pdo->prepare("UPDATE payroll_run_items
                                      SET login_user_id=?, work_days=?, days_present=?, leave_days=?, izin_days=?, sick_days=?, absent_days=?, late_count=?, late_deduction=?, updated_at=NOW()
                                      WHERE id=? AND run_id=?");
                $upd->execute([$resolvedLoginUserId > 0 ? $resolvedLoginUserId : $loginUserId, $wd, $present, $leave, $izin, $sick, $absent, $late, $lateDeduction, $itemId, $runId]);
            } else {
                $upd = $pdo->prepare("UPDATE payroll_run_items
                                      SET login_user_id=?, work_days=?, days_present=?, leave_days=?, izin_days=?, sick_days=?, absent_days=?, late_count=?, updated_at=NOW()
                                      WHERE id=? AND run_id=?");
                $upd->execute([$resolvedLoginUserId > 0 ? $resolvedLoginUserId : $loginUserId, $wd, $present, $leave, $izin, $sick, $absent, $late, $itemId, $runId]);
            }
            payroll_update_item_calc($pdo, $itemId);
            $updated++;
        }

        erp_audit($pdo, 'PAYROLL', 'RUN#'.$runId, 'recalc_absensi', ['updated'=>$updated]);

        payroll_flash_set('success', "Absensi berhasil direkalkulasi untuk {$updated} item.");
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }



    // Sync akumulasi lembur approved dari HRL Process
    if ($action === 'sync_overtime') {
        $st = $pdo->prepare("SELECT * FROM payroll_run_items WHERE run_id=?");
        $st->execute([$runId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        $updated = 0;
        foreach ($items as $it) {
            $itemId = (int)$it['id'];
            $resolvedLogin = rmi_pr_resolve_login_for_employee($pdo, (int)$it['employee_id'], (int)($it['login_user_id'] ?? 0), $run['office_code'] ?? null);
            $ot = rmi_pr_hrl_overtime_snapshot_strict($pdo, (int)$it['employee_id'], $period, $resolvedLogin);
            $hours = (float)($ot['actual_hours'] ?? 0);
            $rate = rmi_pr_overtime_rate_for_item($pdo, $it, $resolvedLogin);
            $pdo->prepare("UPDATE payroll_run_items SET login_user_id=?, overtime_hours=?, overtime_rate_per_hour=?, updated_at=NOW() WHERE id=? AND run_id=?")
                ->execute([$resolvedLogin > 0 ? $resolvedLogin : (int)($it['login_user_id'] ?? 0), $hours, $rate, $itemId, $runId]);
            payroll_update_item_calc($pdo,$itemId);
            $updated++;
        }
        erp_audit($pdo,'PAYROLL','RUN#'.$runId,'sync_overtime',['updated'=>$updated,'period'=>$period]);
        payroll_flash_set('success',"Sync lembur HRL selesai untuk {$updated} item.");
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }

    // Sync loan deductions
    if ($action === 'sync_loans') {
        $st = $pdo->prepare("SELECT id, employee_id, login_user_id, employee_code, employee_name FROM payroll_run_items WHERE run_id=?");
        $st->execute([$runId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);

        $updated = 0;
        foreach ($items as $it) {
            $itemId = (int)$it['id'];
            $empId  = (int)$it['employee_id'];

            $loanCalc = payroll_calc_loan_deductions($pdo, $empId, $period);
            $kasbon = (float)($loanCalc['kasbon'] ?? 0);
            $loan   = (float)($loanCalc['loan'] ?? 0);

            $upd = $pdo->prepare("UPDATE payroll_run_items
                                  SET kasbon_deduction=?, loan_deduction=?, updated_at=NOW()
                                  WHERE id=? AND run_id=?");
            $upd->execute([$kasbon, $loan, $itemId, $runId]);
            payroll_update_item_calc($pdo, $itemId);
            $updated++;
        }

        erp_audit($pdo, 'PAYROLL', 'RUN#'.$runId, 'sync_loans', ['updated'=>$updated]);

        payroll_flash_set('success', "Sync pinjaman/kasbon selesai untuk {$updated} item.");
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }

    // Sync late deductions from official attendance recap/rules
    if ($action === 'sync_late_deduction') {
        if (!$parsed) {
            payroll_flash_set('danger', 'Periode run tidak valid.');
            rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
        }

        $office = $run['office_code'] ?? null;
        $st = $pdo->prepare("SELECT id, employee_id, login_user_id, employee_code, employee_name FROM payroll_run_items WHERE run_id=?");
        $st->execute([$runId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);

        $updated = 0;
        $totalLate = 0.0;
        foreach ($items as $it) {
            $itemId = (int)$it['id'];
            $employeeId = (int)($it['employee_id'] ?? 0);
            $loginUserId = rmi_pr_resolve_login_for_employee($pdo, $employeeId, (int)($it['login_user_id'] ?? 0), $office);

            // Rekap resmi tidak bergantung pada mapping absensi. Jadi jangan skip
            // karyawan hanya karena login_user_id belum tersambung, selama HRL/FIN
            // sudah menetapkan nilai resmi periode tersebut.
            $lateCalc = rmi_pr_late_recap_override($pdo, $employeeId, (string)($it['employee_code'] ?? ''), (string)($it['employee_name'] ?? ''), $period);
            if ($lateCalc === null) {
                if ($loginUserId <= 0) {
                    // Periode tanpa rekap resmi membutuhkan sumber absensi yang valid.
                    continue;
                }
                $lateCalc = rmi_pr_calc_late_deduction_shift_aware($pdo, $loginUserId, $start, $end, $office);
            }

            $lateCount = (int)($lateCalc['late_count'] ?? 0);
            $lateDed   = (float)($lateCalc['late_deduction'] ?? 0);

            if ($loginUserId > 0) {
                $upd = $pdo->prepare("UPDATE payroll_run_items
                                      SET login_user_id=?, late_count=?, late_deduction=?, updated_at=NOW()
                                      WHERE id=? AND run_id=?");
                $upd->execute([$loginUserId, $lateCount, $lateDed, $itemId, $runId]);
            } else {
                $upd = $pdo->prepare("UPDATE payroll_run_items
                                      SET late_count=?, late_deduction=?, updated_at=NOW()
                                      WHERE id=? AND run_id=?");
                $upd->execute([$lateCount, $lateDed, $itemId, $runId]);
            }
            payroll_update_item_calc($pdo, $itemId);
            $updated++;
            $totalLate += $lateDed;
        }

        erp_audit($pdo, 'PAYROLL', 'RUN#'.$runId, 'sync_late_deduction', [
            'updated'=>$updated,
            'total_late_deduction'=>$totalLate,
        ]);

        payroll_flash_set('success', 'Sync potongan telat selesai untuk ' . $updated . ' item. Total potongan telat: Rp ' . number_format($totalLate, 0, ',', '.'));
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }

    // Sinkronisasi identitas/profile karyawan ke snapshot payroll tanpa mengubah nominal payroll.
    if ($action === 'sync_employee_profile') {
        $updatedProfile = rmi_pr_sync_employee_profile($pdo, $runId);
        erp_audit($pdo, 'PAYROLL', 'RUN#'.$runId, 'sync_employee_profile', ['updated'=>$updatedProfile]);
        payroll_flash_set('success', "Data profil karyawan berhasil disinkronkan ke {$updatedProfile} item payroll.");
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }

    // Sinkronisasi final seluruh komponen variabel payroll dari sumber resmi.
    if ($action === 'sync_payroll_components') {
        if (!$parsed) {
            payroll_flash_set('danger', 'Periode run tidak valid.');
            rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
        }
        $office = $run['office_code'] ?? null;
        // Profile ikut disegarkan agar jabatan, tanggal masuk, bank, nama, dept, dan office terbaru masuk ke snapshot payslip.
        rmi_pr_sync_employee_profile($pdo, $runId);
        $st = $pdo->prepare("SELECT * FROM payroll_run_items WHERE run_id=?");
        $st->execute([$runId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $updated=0; $skippedAttendance=0; $missingTax=0; $missingBpjs=0;
        foreach ($rows as $it) {
            $itemId=(int)$it['id']; $empId=(int)$it['employee_id'];
            $wd=$workdaysCalendar>0 ? $workdaysCalendar : (int)($it['work_days']??0);
            $resolvedLoginForAttendance = rmi_pr_resolve_login_for_employee($pdo,$empId,(int)($it['login_user_id']??0),$office);
            $officialAtt = rmi_pr_attendance_recap_override($pdo,$empId,(string)($it['employee_code']??''),(string)($it['employee_name']??''),$period);
            $att=$officialAtt ?? rmi_payroll_attendance_snapshot($pdo,$resolvedLoginForAttendance,$empId,$start,$end,$wd,$office);
            if ($officialAtt !== null) $wd=(int)($officialAtt['work_days']??$wd);
            $attendanceSql=''; $attendanceParams=[];
            $resolvedLoginUserId=(int)($att['login_user_id']??$resolvedLoginForAttendance);
            if ((int)$att['source_found']>0) {
                [$wd, $attPresent, $attLeave, $attIzin, $attSick, $attAbsent] = rmi_pr_normalize_attendance_counts($att, $wd);
                $attendanceSql=", login_user_id=?, work_days=?, days_present=?, leave_days=?, izin_days=?, sick_days=?, absent_days=?, late_count=?";
                $attendanceParams=[$resolvedLoginUserId,$wd,$attPresent,$attLeave,$attIzin,$attSick,$attAbsent,(int)$att['late']];
            } else { $skippedAttendance++; }

            // OT Hours wajib mengikuti HRL Process (LEMBUR approved) dengan mapping STRICT.
            // Jangan gunakan helper lama yang bisa fallback berdasarkan dept/office/nama/urutan row.
            // Cocokkan hanya akun login yang benar-benar terikat ke employee_code karyawan.
            $otSnap = rmi_pr_hrl_overtime_snapshot_strict($pdo, $empId, $period, $resolvedLoginUserId);
            $otHours = (float)($otSnap['actual_hours'] ?? 0);
            $otWeightedHours = (float)($otSnap['weighted_hours'] ?? 0);
            $stat=rmi_payroll_setting_amounts($pdo,$empId,$it);
            // Tarif lembur tidak boleh memakai rate lama/base-only. Ikuti rekap HRL manual:
            // (GP + Tunjangan Jabatan + Transport) / 173 manager, / 200 staff, dibulatkan turun.
            $otRate=rmi_pr_overtime_rate_for_item($pdo,$it,$resolvedLoginUserId);
            if($otRate<=0) $otRate=(float)($stat['overtime_rate_per_hour']??0);
            $lateCalc = rmi_pr_late_recap_override($pdo, $empId, (string)($it['employee_code'] ?? ''), (string)($it['employee_name'] ?? ''), $period);
            if ($lateCalc === null) {
                $lateCalc = $resolvedLoginUserId>0
                    ? rmi_pr_calc_late_deduction_shift_aware($pdo,$resolvedLoginUserId,$start,$end,$office)
                    : ['late_count'=>(int)($it['late_count']??0),'late_deduction'=>(float)($it['late_deduction']??0)];
            }
            $loan=payroll_calc_loan_deductions($pdo,$empId,$period);
            // Nilai 0 dapat menjadi hasil yang sah. Yang dianggap belum siap adalah
            // ketika sumber konfigurasi/kalkulator memang tidak ditemukan.
            $taxConfigured = !empty($stat['tax_source_found']);
            $tkConfigured  = !empty($stat['bpjs_tk_source_found']);
            $kesConfigured = !empty($stat['bpjs_kes_source_found']);
            $dedConfigured = !empty($stat['deduction_source_found']);

            $tax = $taxConfigured ? (float)$stat['tax_pph21'] : (float)($it['tax_pph21'] ?? 0);
            $tk  = $tkConfigured  ? (float)$stat['bpjs_tk']   : (float)($it['bpjs_tk'] ?? 0);
            $kes = $kesConfigured ? (float)$stat['bpjs_kes']  : (float)($it['bpjs_kes'] ?? 0);
            $dedFixed = $dedConfigured
                ? (float)$stat['deduction_fixed']
                : (float)($it['deduction_fixed'] ?? 0);

            // SAFETY PPh21:
            // Kalkulator/sumber pajak kadang mengembalikan angka tahunan/akumulasi,
            // bukan potongan payroll bulanan. Contoh: 12.455.500 atau 15.994.794
            // membuat Net Pay minus. Untuk payroll bulanan, PPh21 tidak boleh
            // melebihi gross bulanan item tersebut. Jika melewati gross, anggap
            // sumber PPh21 tidak valid dan pakai 0 agar payroll tidak rusak.
            $presentForGross = $attendanceParams ? (int)($attPresent ?? ($att['present'] ?? ($it['days_present'] ?? 0))) : (int)($it['days_present'] ?? 0);
            $grossGuard = (float)($it['salary_basic'] ?? 0)
                + ((float)($it['op_rate_day'] ?? 0) * max(0, $presentForGross))
                + (float)($it['allowance_position'] ?? 0)
                + (float)($it['allowance_child'] ?? 0)
                + (float)($it['allowance_transport'] ?? 0)
                + (float)($it['allowance_quota'] ?? 0)
                + (float)($it['allowance_fixed'] ?? 0)
                + (float)($it['other_allowance'] ?? 0)
                + ($otRate * max(0, $otWeightedHours));
            if ($tax > 0 && $grossGuard > 0 && $tax >= $grossGuard) {
                $tax = 0.0;
                $taxConfigured = false;
            }

            if (!$taxConfigured) $missingTax++;
            if (!$tkConfigured && !$kesConfigured) $missingBpjs++;

            $sql="UPDATE payroll_run_items SET overtime_hours=?, overtime_rate_per_hour=?, late_count=?, late_deduction=?, kasbon_deduction=?, loan_deduction=?, deduction_fixed=?, tax_pph21=?, bpjs_tk=?, bpjs_kes=? {$attendanceSql}, updated_at=NOW() WHERE id=? AND run_id=?";
            $params=array_merge([
                $otHours,$otRate,(int)($lateCalc['late_count']??0),(float)($lateCalc['late_deduction']??0),
                (float)($loan['kasbon']??0),(float)($loan['loan']??0),$dedFixed,$tax,$tk,$kes
            ], $attendanceParams, [$itemId,$runId]);
            $pdo->prepare($sql)->execute($params);
            payroll_update_item_calc($pdo,$itemId);

            // FINAL SAFETY: pastikan potongan telat resmi tidak kembali 0 setelah kalkulasi ulang.
            // Ini menjaga hasil Sync Komponen Payroll/Recalc agar tetap mengikuti rekap HRL/FIN.
            $lateOfficial = rmi_pr_late_recap_override($pdo, $empId, (string)($it['employee_code'] ?? ''), (string)($it['employee_name'] ?? ''), $period);
            if ($lateOfficial !== null) {
                $pdo->prepare("UPDATE payroll_run_items SET late_count=?, late_deduction=?, updated_at=NOW() WHERE id=? AND run_id=?")
                    ->execute([(int)($lateOfficial['count'] ?? 0), (float)($lateOfficial['amount'] ?? 0), $itemId, $runId]);
                payroll_update_item_calc($pdo,$itemId);
            }
            $updated++;
        }
        erp_audit($pdo,'PAYROLL','RUN#'.$runId,'sync_payroll_components',[
            'updated'=>$updated,'attendance_skipped'=>$skippedAttendance,'missing_pph21'=>$missingTax,'missing_bpjs'=>$missingBpjs,'period'=>$period
        ]);
        $msg="Sinkronisasi komponen payroll selesai untuk {$updated} item.";
        if($skippedAttendance>0) $msg.=" Absensi dilewati {$skippedAttendance} item karena mapping/sumber belum tersedia.";
        if($missingTax>0) $msg.=" Sumber konfigurasi/kalkulator PPh21 belum ditemukan pada {$missingTax} item; nilai payroll sebelumnya dipertahankan.";
        if($missingBpjs>0) $msg.=" Sumber konfigurasi BPJS belum ditemukan pada {$missingBpjs} item; nilai payroll sebelumnya dipertahankan.";
        payroll_flash_set(($skippedAttendance||$missingTax||$missingBpjs)?'warning':'success',$msg);
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }

    // Sync salary matrix to this run
    if ($action === 'sync_matrix') {
        $year = (int)substr($period, 0, 4);
        $matrixMap = payroll_load_salary_matrix($pdo, $year);

        // settings by employee (override kalau diisi)
        $setSt = $pdo->query("SELECT * FROM payroll_employee_settings");
        $settingsByEmp = [];
        while ($s = $setSt->fetch(PDO::FETCH_ASSOC)) {
            $settingsByEmp[(int)$s['employee_id']] = $s;
        }

        $empJobSelect = rmi_pr_employee_job_select($pdo);
        $st = $pdo->prepare("SELECT i.id, i.employee_id, i.salary_basic AS item_salary_basic,
                                    e.payroll_status, e.payroll_level,
                                    {$empJobSelect}
                             FROM payroll_run_items i
                             LEFT JOIN master_employees e ON e.id = i.employee_id
                             WHERE i.run_id=?");
        $st->execute([$runId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);

        $updated = 0;
        foreach ($items as $it) {
            $itemId = (int)$it['id'];
            $empId  = (int)$it['employee_id'];
            $s = $settingsByEmp[$empId] ?? null;

            $empStatus = payroll_norm_status($it['payroll_status'] ?? '');
            $empLevel  = payroll_norm_level($it['payroll_level'] ?? '');
            $empJob    = trim((string)($it['employee_job_title'] ?? ''));
            $settingBasic = is_array($s) ? (float)($s['salary_basic'] ?? 0) : 0.0;
            $itemBasic    = (float)($it['item_salary_basic'] ?? 0);
            $lookupBasic  = $settingBasic > 0 ? $settingBasic : $itemBasic;
            $mx = rmi_pr_find_matrix_fallback($matrixMap, $empStatus, $empLevel, $empJob, $lookupBasic);

            // Jika payroll_status/payroll_level di Master Employee kosong, gunakan status/level dari matrix yang ditemukan.
            $mxStatus = $mx ? payroll_norm_status($mx['payroll_status'] ?? '') : $empStatus;
            $mxLevel  = $mx ? payroll_norm_level($mx['payroll_level'] ?? '') : $empLevel;

            $mxTakeHome = $mx ? (float)($mx['take_home_pay'] ?? 0) : 0.0;
            $mxBasic    = $mx ? (float)($mx['basic_salary'] ?? 0) : 0.0;
            $mxOpRate   = $mx ? (float)($mx['op_rate_day'] ?? 0) : 0.0;
            $mxJabatan  = $mx ? (float)($mx['tunj_jabatan'] ?? 0) : 0.0;
            $mxAnak     = $mx ? (float)($mx['tunj_anak'] ?? 0) : 0.0;
            $mxTrans    = $mx ? (float)($mx['transport'] ?? 0) : 0.0;
            $mxKuota    = $mx ? (float)($mx['kuota'] ?? 0) : 0.0;

            $hasSetting = is_array($s);

            // Salary Basic tetap mengikuti Payroll Settings jika sudah ada.
            // Untuk OP/day dan komponen matrix lain, jangan biarkan nilai 0 di
            // payroll_employee_settings menimpa Salary Matrix. Ini penyebab OP
            // tampil 0 x hadir = 0 pada payroll run.
            $salaryBasic = $hasSetting ? (float)($s['salary_basic'] ?? 0) : $mxBasic;
            if ($salaryBasic <= 0 && $mxBasic > 0) $salaryBasic = $mxBasic;

            $opRateDay   = rmi_pr_num_or_matrix($s, 'op_rate_day', $mxOpRate);
            $allowPos    = rmi_pr_num_or_matrix($s, 'allowance_position', $mxJabatan);
            $allowChild  = rmi_pr_num_or_matrix($s, 'allowance_child', $mxAnak);
            $allowTrans  = rmi_pr_num_or_matrix($s, 'allowance_transport', $mxTrans);
            $allowQuota  = rmi_pr_num_or_matrix($s, 'allowance_quota', $mxKuota);

            $upd = $pdo->prepare("UPDATE payroll_run_items
                                  SET matrix_year=?, matrix_status=?, matrix_level=?, matrix_take_home=?,
                                      salary_basic=?, op_rate_day=?,
                                      allowance_position=?, allowance_child=?, allowance_transport=?, allowance_quota=?,
                                      updated_at=NOW()
                                  WHERE id=? AND run_id=?");
            $upd->execute([
                $year,
                ($mxStatus ?: null),
                ($mxLevel ?: null),
                $mxTakeHome,
                $salaryBasic,
                $opRateDay,
                $allowPos,
                $allowChild,
                $allowTrans,
                $allowQuota,
                $itemId,
                $runId
            ]);

            payroll_update_item_calc($pdo, $itemId);
            $updated++;
        }

        erp_audit($pdo, 'PAYROLL', 'RUN#'.$runId, 'sync_matrix', ['updated'=>$updated, 'year'=>$year]);

        payroll_flash_set('success', "Sync salary matrix selesai untuk {$updated} item.");
        rmi_redirect("{$BASE_PAYROLL}/payroll_run.php?id={$runId}");
    }
}

// Load items
$st = $pdo->prepare("SELECT i.*,
                            COALESCE(NULLIF(i.employee_code,''), e.employee_code) AS employee_code,
                            COALESCE(NULLIF(i.employee_name,''), e.employee_name) AS employee_name,
                            COALESCE(NULLIF(i.dept_code,''), e.dept_code) AS dept_code,
                            COALESCE(NULLIF(i.office_code,''), e.office_code) AS emp_office
                     FROM payroll_run_items i
                     LEFT JOIN master_employees e ON e.id = i.employee_id
                     WHERE i.run_id = ?
                     ORDER BY employee_name ASC");
$st->execute([$runId]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

$totals = ['gross'=>0.0,'ded'=>0.0,'net'=>0.0];
foreach ($items as $it) {
    $totals['gross'] += (float)$it['gross_pay'];
    $totals['ded']   += (float)$it['total_deduction'];
    $totals['net']   += (float)$it['net_pay'];
}

$payment = null;
try {
    $stPayment = $pdo->prepare("SELECT * FROM payroll_payments WHERE run_id=? ORDER BY id DESC LIMIT 1");
    $stPayment->execute([$runId]);
    $payment = $stPayment->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}
$runStatus = strtoupper(trim((string)($run['status'] ?? 'DRAFT')));
$isDraft = $runStatus === 'DRAFT';
$isPosted = $runStatus === 'POSTED';
$isPaid = $runStatus === 'PAID';

$flash = payroll_flash_get();

rmi_header("Payroll Run #{$runId}", 'payroll', ['base_project'=>$BASE_PROJECT]);
?>

<?php if ($flash): ?>
  <div class="alert alert-<?= payroll_h($flash['type']) ?>"><?= payroll_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <div class="fw-bold">Run #<?= (int)$runId ?> — Periode <?= payroll_h($run['period_ym']) ?> — Office <?= payroll_h($run['office_code'] ?? '-') ?></div>
    <div class="rmi-muted" style="font-size:12px;">
      Hari kerja efektif periode cutoff 26–25: <?= (int)$workdaysCalendar ?> • Sumber: <b>payroll_holidays</b>.<br>
      Rentang absensi: <b><?= payroll_h(date('d-m-Y', strtotime($start))) ?></b> s.d. <b><?= payroll_h(date('d-m-Y', strtotime($end))) ?></b>.
      <a href="<?= payroll_h($BASE_PAYROLL) ?>/payroll_calendar.php?month=<?= payroll_h($period) ?>" style="margin-left:8px;color:#60a5fa">Kelola Kalender Kerja</a>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap align-items-center">
    <span class="badge bg-<?= $isPaid ? 'info' : ($isPosted ? 'success' : 'warning') ?>"><?= payroll_h($runStatus) ?></span>
    <?php if ($isDraft): ?>
    <form method="post" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
      <input type="hidden" name="action" value="sync_matrix">
      <button class="btn btn-sm btn-outline-light" type="submit">Sync Matrix</button>
    </form>
    <form method="post" style="margin:0;" onsubmit="return confirm('Sinkronkan data identitas karyawan terbaru ke snapshot payslip?');">
      <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
      <input type="hidden" name="action" value="sync_employee_profile">
      <button class="btn btn-sm btn-outline-info" type="submit">Sync Data Karyawan</button>
    </form>
    <form method="post" style="margin:0;" onsubmit="return confirm('Sinkronkan absensi, lembur, keterlambatan, pinjaman, PPh21 dan BPJS dari sumber resmi?');">
      <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
      <input type="hidden" name="action" value="sync_payroll_components">
      <button class="btn btn-sm btn-warning" type="submit">Sync Komponen Payroll</button>
    </form>
    <form method="post" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
      <input type="hidden" name="action" value="sync_loans">
      <button class="btn btn-sm btn-outline-light" type="submit">Sync Loans</button>
    </form>
    <form method="post" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
      <input type="hidden" name="action" value="recalc_absensi">
      <button class="btn btn-sm btn-outline-secondary" type="submit">Recalc Absensi</button>
    </form>
    <form method="post" style="margin:0;" onsubmit="return confirm('Sync potongan telat dari absensi resmi ke payroll run ini?');">
      <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
      <input type="hidden" name="action" value="sync_late_deduction">
      <button class="btn btn-sm btn-outline-warning" type="submit">Sync Potongan Telat</button>
    </form>
    <form method="post" style="margin:0;" onsubmit="return confirm('Post payroll dan kunci seluruh data? Sistem akan menyegarkan mapping akun dan absensi sebelum validasi final.');">
      <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
      <input type="hidden" name="action" value="post_payroll">
      <button class="btn btn-sm btn-success" type="submit">Post Payroll</button>
    </form>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/index.php?export=csv&id=<?= (int)$runId ?>">CSV</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/index.php">Back</a>
  </div>
</div>

<div class="card rmi-card mb-3">
  <div class="card-body">
    <div class="row g-2">
      <div class="col-4">
        <div class="rmi-muted" style="font-size:12px;">Total Gross</div>
        <div class="fw-bold"><?= number_format($totals['gross'], 2) ?></div>
      </div>
      <div class="col-4">
        <div class="rmi-muted" style="font-size:12px;">Total Deduction</div>
        <div class="fw-bold"><?= number_format($totals['ded'], 2) ?></div>
      </div>
      <div class="col-4">
        <div class="rmi-muted" style="font-size:12px;">Total Net</div>
        <div class="fw-bold"><?= number_format($totals['net'], 2) ?></div>
      </div>
    </div>
  </div>
</div>

<?php if ($isPosted || $isPaid): ?>
<div class="card rmi-card mb-3">
  <div class="card-header"><div class="fw-bold">Proses Pembayaran Payroll</div><div class="rmi-muted" style="font-size:12px;">Pembayaran dicatat setelah payroll POSTED. Status PAID hanya aktif jika total pembayaran sama dengan Total Net.</div></div>
  <div class="card-body">
    <?php if ($payment): ?>
      <div class="row g-2 mb-3">
        <div class="col-md-3"><div class="rmi-muted">Tanggal Bayar</div><b><?= payroll_h($payment['payment_date']) ?></b></div>
        <div class="col-md-3"><div class="rmi-muted">Referensi</div><b><?= payroll_h($payment['reference_no']) ?></b></div>
        <div class="col-md-3"><div class="rmi-muted">Total Dicatat</div><b>Rp <?= number_format((float)$payment['amount'],0,',','.') ?></b></div>
        <div class="col-md-3"><div class="rmi-muted">Status Pembayaran</div><b><?= payroll_h($payment['status']) ?></b></div>
      </div>
    <?php endif; ?>
    <?php if ($isPosted && !$payment): ?>
      <form method="post" class="row g-2" onsubmit="return confirm('Simpan catatan pembayaran sesuai seluruh Net Pay karyawan?');">
        <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
        <input type="hidden" name="action" value="record_payment">
        <div class="col-md-3"><label class="form-label">Tanggal Pembayaran</label><input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
        <div class="col-md-3"><label class="form-label">Metode</label><select name="payment_method" class="form-select"><option value="BANK_TRANSFER">Bank Transfer</option><option value="CASH">Cash</option><option value="OTHER">Lainnya</option></select></div>
        <div class="col-md-3"><label class="form-label">Nomor Referensi</label><input type="text" name="reference_no" class="form-control" maxlength="120" placeholder="Wajib untuk Bank Transfer"></div>
        <div class="col-md-3"><label class="form-label">Catatan</label><input type="text" name="payment_note" class="form-control" maxlength="255"></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Catat Pembayaran</button></div>
      </form>
    <?php elseif ($isPosted && $payment): ?>
      <form method="post" onsubmit="return confirm('Tandai payroll ini PAID? Status final dan tidak dapat diedit.');">
        <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
        <input type="hidden" name="action" value="mark_paid">
        <button class="btn btn-info" type="submit">Tandai PAID</button>
      </form>
    <?php else: ?>
      <div class="alert alert-success mb-0">Payroll telah dibayar pada <?= payroll_h($run['paid_at'] ?? '-') ?>. Referensi: <?= payroll_h($run['payment_reference'] ?? '-') ?>.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card rmi-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm rmi-table mb-0">
        <thead>
          <tr>
            <th>Employee / Matrix</th>
            <th class="text-center">Type</th>
            <th class="text-center" title="WD=hari kerja kalender; P/C/I/S/A/T=aktual absensi, cuti, izin, sakit, alpa, telat">WD/P/C/I/S/A/T</th>
            <th class="text-end">Salary Basic</th>
            <th class="text-end">Allow Fixed</th>
            <th class="text-end">Ded Fixed</th>
            <th class="text-end">OT Hrs</th>
            <th class="text-end">OT Rate</th>
            <th class="text-end">Other +</th>
            <th class="text-end">Other -</th>
            <th class="text-end">Pot. Telat</th>
            <th class="text-end">Kasbon</th>
            <th class="text-end">Loan</th>
            <th class="text-end">PPh21</th>
            <th class="text-end">BPJS TK</th>
            <th class="text-end">BPJS Kes</th>
            <th class="text-end">Net Pay</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="18" class="text-center rmi-muted py-4">Tidak ada item.</td></tr>
        <?php else: foreach ($items as $it): ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
            <input type="hidden" name="action" value="update_item">
            <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <tr>
              <td style="min-width:320px;">
                <div class="fw-bold"><?= payroll_h($it['employee_code']) ?> — <?= payroll_h($it['employee_name']) ?></div>
                <div class="rmi-muted" style="font-size:12px;">
                  <?= payroll_h($it['dept_code']) ?> • <?= payroll_h($it['emp_office'] ?? '-') ?>
                </div>
                <div class="rmi-muted" style="font-size:12px;">
                  Matrix: <b><?= payroll_h(($it['matrix_status'] ?? '-') . '-' . ($it['matrix_level'] ?? '-')) ?></b>
                  <?php if ((float)$it['matrix_take_home'] > 0): ?>
                    • THP Ref: <?= number_format((float)$it['matrix_take_home'],0) ?>
                  <?php endif; ?>
                </div>
                <div class="rmi-muted" style="font-size:12px;">
                  OP: <?= number_format((float)$it['op_rate_day'],0) ?> x <?= (int)$it['days_present'] ?> = <?= number_format((float)$it['op_amount'],0) ?>
                  • Jab: <?= number_format((float)$it['allowance_position'],0) ?>
                  • Trans: <?= number_format((float)$it['allowance_transport'],0) ?>
                  • Anak: <?= number_format((float)$it['allowance_child'],0) ?>
                  • Kuota: <?= number_format((float)$it['allowance_quota'],0) ?>
                  • Pot. Telat: <?= number_format((float)($it['late_deduction'] ?? 0),0) ?>
                </div>
                <div class="rmi-muted" style="font-size:12px;">
                  <a href="<?= payroll_h($BASE_PAYROLL) ?>/payslip.php?item_id=<?= (int)$it['id'] ?>" target="_blank">Payslip</a>
                </div>
              </td>

              <td class="text-center"><?= payroll_h($it['pay_type']) ?></td>
              <td class="text-center">
                <span title="WD kalender / Hadir aktual / Cuti / Izin / Sakit / Alpa / Telat">
                  <?= (int)$it['work_days'] ?>/<?= (int)$it['days_present'] ?>/<?= (int)$it['leave_days'] ?>/<?= (int)($it['izin_days'] ?? 0) ?>/<?= (int)($it['sick_days'] ?? 0) ?>/<?= (int)$it['absent_days'] ?>/<?= (int)($it['late_count'] ?? 0) ?>
                </span>
              </td>

              <td class="text-end">
                <input type="number" step="0.01" name="salary_basic" class="form-control form-control-sm text-end" <?= !$isDraft ? 'disabled' : '' ?>
                       value="<?= payroll_h($it['salary_basic']) ?>" style="min-width:120px;">
              </td>
              <td class="text-end">
                <input type="number" step="0.01" name="allowance_fixed" class="form-control form-control-sm text-end" <?= !$isDraft ? 'disabled' : '' ?>
                       value="<?= payroll_h($it['allowance_fixed']) ?>" style="min-width:110px;">
              </td>
              <td class="text-end">
                <input type="number" step="0.01" name="deduction_fixed" class="form-control form-control-sm text-end" <?= !$isDraft ? 'disabled' : '' ?>
                       value="<?= payroll_h($it['deduction_fixed']) ?>" style="min-width:110px;">
              </td>
              <td class="text-end">
                <input type="number" step="0.01" name="overtime_hours" class="form-control form-control-sm text-end" <?= !$isDraft ? 'disabled' : '' ?>
                       value="<?= payroll_h($it['overtime_hours']) ?>" style="min-width:80px;">
              </td>
              <td class="text-end">
                <input type="number" step="0.01" name="overtime_rate_per_hour" class="form-control form-control-sm text-end" <?= !$isDraft ? 'disabled' : '' ?>
                       value="<?= payroll_h($it['overtime_rate_per_hour']) ?>" style="min-width:90px;">
              </td>
              <td class="text-end">
                <input type="number" step="0.01" name="other_allowance" class="form-control form-control-sm text-end" <?= !$isDraft ? 'disabled' : '' ?>
                       value="<?= payroll_h($it['other_allowance']) ?>" style="min-width:90px;">
              </td>
              <td class="text-end">
                <input type="number" step="0.01" name="other_deduction" class="form-control form-control-sm text-end" <?= !$isDraft ? 'disabled' : '' ?>
                       value="<?= payroll_h($it['other_deduction']) ?>" style="min-width:90px;">
              </td>
              <td class="text-end fw-bold" style="color:#fbbf24;"><?= number_format((float)($it['late_deduction'] ?? 0),2) ?></td>
              <td class="text-end"><?= number_format((float)$it['kasbon_deduction'],2) ?></td>
              <td class="text-end"><?= number_format((float)$it['loan_deduction'],2) ?></td>
              <td class="text-end">
                <input type="number" step="0.01" name="tax_pph21" class="form-control form-control-sm text-end" <?= !$isDraft ? 'disabled' : '' ?>
                       value="<?= payroll_h($it['tax_pph21']) ?>" style="min-width:90px;">
              </td>
              <td class="text-end">
                <input type="number" step="0.01" name="bpjs_tk" class="form-control form-control-sm text-end" <?= !$isDraft ? 'disabled' : '' ?>
                       value="<?= payroll_h($it['bpjs_tk']) ?>" style="min-width:90px;">
              </td>
              <td class="text-end">
                <input type="number" step="0.01" name="bpjs_kes" class="form-control form-control-sm text-end" <?= !$isDraft ? 'disabled' : '' ?>
                       value="<?= payroll_h($it['bpjs_kes']) ?>" style="min-width:90px;">
              </td>
              <td class="text-end fw-bold"><?= number_format((float)$it['net_pay'],2) ?></td>
              <td class="text-end">
                <?php if ($isDraft): ?><button class="btn btn-sm btn-rmi" type="submit">Save</button><?php else: ?><span class="badge bg-secondary">Locked</span><?php endif; ?>
              </td>
            </tr>
          </form>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
