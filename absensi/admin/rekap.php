<?php
require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_inc/bootstrap.php';
require_once __DIR__ . '/../_inc/shift_helper.php';

if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.RECAP');

require_once __DIR__ . '/../_layout_top.php';

if (!absensi_is_hr_admin($pdo, $ABS_USER)) {
    echo '<div class="alert alert-danger">Akses ditolak — hanya HRL / SYS.</div>';
    require_once __DIR__ . '/../_layout_bottom.php';
    exit;
}

// ── Export CSV (diproses sebelum output HTML) ────────────────────────────
if (isset($_GET['export']) && (string)($_GET['export']) === '1') {
    // Auth check
    if (!absensi_is_hr_admin($pdo, $ABS_USER)) {
        http_response_code(403); exit('Akses ditolak');
    }
    absensi_audit($pdo, $ABS_USER, 'EXPORT_CSV', [
        'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
        'office' => $_GET['office'] ?? '', 'dept' => $_GET['dept'] ?? '',
        'module' => 'absensi_rekap_v2',
    ]);

    $expFrom   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
    $expTo     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
    $expOffice = strtoupper(trim((string)($_GET['office'] ?? '')));
    $expDept   = strtoupper(trim((string)($_GET['dept']   ?? '')));
    $expStd    = absensi_setting($pdo, 'checkin_std_time', '07:00') ?? '07:00';
    $expTol    = max(0, (int)(absensi_setting($pdo, 'late_tolerance_min', '5') ?? '5'));
    $expEffective = $expStd;
    $expTs = strtotime('2000-01-01 ' . $expStd . ':00');
    if ($expTs !== false && $expTol > 0) $expEffective = date('H:i', $expTs + ($expTol * 60));

    $fileLabel = 'rekap_absensi_' . str_replace('-', '', $expFrom) . '_' . str_replace('-', '', $expTo)
               . ($expOffice ? '_' . $expOffice : '') . ($expDept ? '_' . $expDept : '');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileLabel . '.csv"');

    // Employee map
    $csvEmpMap = [];
    try {
        $s = $pdo->query("
            SELECT m.id, COALESCE(e.employee_name,'') AS n,
                   UPPER(COALESCE(m.department,''))   AS d,
                   UPPER(COALESCE(m.office_code,''))  AS o
            FROM master_system_login m
            LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code
            WHERE m.deleted_at IS NULL
        ");
        while ($row = $s->fetch(PDO::FETCH_ASSOC)) {
            $csvEmpMap[(int)$row['id']] = $row;
        }
    } catch (Throwable $e) {}

    $csvWhere  = "al.deleted_at IS NULL AND al.created_at BETWEEN ? AND ?";
    $csvParams = [$expFrom . ' 00:00:00', $expTo . ' 23:59:59'];
    if ($expOffice !== '') { $csvWhere .= " AND UPPER(COALESCE(al.office_code,''))=?"; $csvParams[] = $expOffice; }

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM untuk Excel
    fputcsv($out, ['tanggal','user_id','username','nama_karyawan','dept','office_code','jam_masuk','jam_pulang','durasi','status_terlambat','menit_terlambat','potongan_terlambat','belum_checkout','foto_in','foto_out'], ',', '"', '\\');

    try {
        $st = $pdo->prepare("
            SELECT DATE(al.created_at) AS tanggal, al.user_id, al.username,
                   UPPER(COALESCE(MAX(al.office_code),'')) AS office_code,
                   MAX(CASE WHEN al.action_type='IN'  THEN al.created_at END) AS checkin,
                   MAX(CASE WHEN al.action_type='OUT' THEN al.created_at END) AS checkout,
                   MAX(CASE WHEN al.action_type='IN'  THEN al.photo_path END) AS photo_in,
                   MAX(CASE WHEN al.action_type='OUT' THEN al.photo_path END) AS photo_out
            FROM absensi_logs al
            WHERE {$csvWhere}
            GROUP BY DATE(al.created_at), al.user_id, al.username
            ORDER BY tanggal DESC, al.username ASC
            LIMIT 200000
        ");
        $st->execute($csvParams);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $uid  = (int)($r['user_id'] ?? 0);
            $emp  = $csvEmpMap[$uid] ?? ['n'=>'','d'=>'','o'=>''];
            if ($expDept !== '' && $emp['d'] !== $expDept) continue;
            $cin  = $r['checkin']  ?? null;
            $cout = $r['checkout'] ?? null;
            $cinTime  = $cin  ? substr($cin, 11, 5)  : '';
            $coutTime = $cout ? substr($cout, 11, 5) : '';
            $csvRules = abs_rekap_late_penalty_rules($pdo);
            $csvShift = absensi_resolve_shift($pdo, (string)($r['username'] ?? ''), (string)($r['tanggal'] ?? ''));
            $lateMin  = $csvShift
                ? absensi_shift_late_minutes($cinTime, $csvShift)
                : abs_rekap_late_minutes($cinTime, $expEffective);
            $lateAmt  = abs_rekap_late_penalty_amount($lateMin, $csvRules);
            $isLate   = $lateMin > 0 ? 'YA' : 'TIDAK';
            $noOut    = $cin && !$cout ? 'YA' : 'TIDAK';
            $duration = '';
            if ($cin && $cout) {
                $diff = strtotime($cout) - strtotime($cin);
                if ($diff > 0) $duration = intdiv($diff, 3600) . 'j ' . intdiv($diff % 3600, 60) . 'm';
            }
            fputcsv($out, [
                $r['tanggal'],
                $uid,
                $r['username'],
                $emp['n'],
                $emp['d'],
                $emp['o'] ?: $r['office_code'],
                $cinTime,
                $coutTime,
                $duration,
                $isLate,
                $lateMin,
                $lateAmt,
                $noOut,
                $r['photo_in']  ? 'Ada' : '',
                $r['photo_out'] ? 'Ada' : '',
            ], ',', '"', '\\');
        }
    } catch (Throwable $e) {}
    fclose($out);
    exit;
}

// ── Jam standar masuk + toleransi (dari absensi_settings) ───────────────
$stdTime     = absensi_setting($pdo, 'checkin_std_time',   '08:30') ?? '08:30';
$stdTimeTol  = $stdTime; // effective batas terlambat setelah toleransi
$_tolerance  = max(0, (int)(absensi_setting($pdo, 'late_tolerance_min', '5') ?? '5'));
if ($_tolerance > 0) {
    // Tambahkan toleransi ke jam standar untuk batas efektif terlambat
    $ts = strtotime('2000-01-01 ' . $stdTime . ':00');
    if ($ts !== false) {
        $stdTimeTol = date('H:i', $ts + $_tolerance * 60);
    }
}
// Kirim effective time ke API DataTables (bukan stdTime mentah)
$stdTimeForDt = $stdTimeTol;

// Aturan potongan keterlambatan
$latePenaltyRules = abs_rekap_late_penalty_rules($pdo);

// ── Filter params ────────────────────────────────────────────────────────
$from          = trim((string)($_GET['from']   ?? date('Y-m-01')));
$to            = trim((string)($_GET['to']     ?? date('Y-m-d')));
$filterOffice  = strtoupper(trim((string)($_GET['office'] ?? '')));
$filterDept    = strtoupper(trim((string)($_GET['dept']   ?? '')));
$filterStatus  = strtolower(trim((string)($_GET['status'] ?? '')));
$activeTab     = in_array($_GET['tab'] ?? 'detail', ['detail','summary','absent'], true)
                   ? ($_GET['tab'] ?? 'detail') : 'detail';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$fromDT = $from . ' 00:00:00';
$toDT   = $to   . ' 23:59:59';

// ── Office list ──────────────────────────────────────────────────────────
$officeList = [];
try {
    foreach (['master_office','absensi_offices'] as $tbl) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $chk->execute([$tbl]);
        if ((int)$chk->fetchColumn() > 0) {
            $officeList = $pdo->query("SELECT office_code, office_name FROM {$tbl} WHERE COALESCE(is_active,1)=1 ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
            break;
        }
    }
} catch (Throwable $e) {}

// ── Dept list ────────────────────────────────────────────────────────────
$deptList = ['ACT','CRM','FIN','HRL','ITC','MPR','PQP','SCM','WQS','BRANCH'];
try {
    $rows = $pdo->query("SELECT DISTINCT UPPER(dept_code) AS d FROM master_departements WHERE dept_code IS NOT NULL AND dept_code<>'' ORDER BY dept_code")->fetchAll(PDO::FETCH_COLUMN);
    if ($rows) $deptList = $rows;
} catch (Throwable $e) {}

// ── KPI summary ──────────────────────────────────────────────────────────
$kpi = ['hadir'=>0,'terlambat'=>0,'belum_pulang'=>0,'normal'=>0,'late_penalty'=>0];

$baseWhere  = "al.deleted_at IS NULL AND al.created_at BETWEEN ? AND ?";
$baseParams = [$fromDT, $toDT];
if ($filterOffice !== '') { $baseWhere .= " AND UPPER(COALESCE(al.office_code,''))=?"; $baseParams[] = $filterOffice; }
// Dept filter diterapkan di query yang join master_system_login agar KPI/summary konsisten dengan tabel detail.
$baseWhereWithDept = $baseWhere;
$baseParamsWithDept = $baseParams;
if ($filterDept !== '') { $baseWhereWithDept .= " AND UPPER(COALESCE(msl.department,''))=?"; $baseParamsWithDept[] = $filterDept; }

try {
    $st = $pdo->prepare("
        SELECT DATE(al.created_at) AS tanggal, al.user_id, al.username,
               MAX(CASE WHEN al.action_type='IN'  THEN al.created_at END) AS checkin,
               MAX(CASE WHEN al.action_type='OUT' THEN al.created_at END) AS checkout
        FROM absensi_logs al
        LEFT JOIN master_system_login msl ON msl.id = al.user_id AND msl.deleted_at IS NULL
        WHERE {$baseWhereWithDept}
        GROUP BY DATE(al.created_at), al.user_id, al.username
    ");
    $st->execute($baseParamsWithDept);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $kpi['hadir']++;
        $cinTime  = $r['checkin']  ? substr($r['checkin'],  11, 5) : null;
        $coutTime = $r['checkout'] ? true : false;
        $kpiShift = absensi_resolve_shift($pdo, (string)($r['username'] ?? ''), (string)($r['tanggal'] ?? ''));
        $lateMin = $kpiShift
            ? absensi_shift_late_minutes((string)$cinTime, $kpiShift)
            : abs_rekap_late_minutes((string)$cinTime, $stdTimeTol);
        $late = $lateMin > 0;
        $latePenalty = abs_rekap_late_penalty_amount($lateMin, $latePenaltyRules);
        $noOut = $r['checkin'] && !$r['checkout'];
        if ($late || $noOut) {
            if ($late) {
                $kpi['terlambat']++;
                $kpi['late_penalty'] += $latePenalty;
            }
            if ($noOut) $kpi['belum_pulang']++;
        } else {
            $kpi['normal']++;
        }
    }
} catch (Throwable $e) {}

// ── "Tidak Hadir" — karyawan aktif tanpa checkin (hanya untuk 1 hari) ───
$absentList = [];
$isOneDay   = ($from === $to);
if ($isOneDay || $activeTab === 'absent') {
    $targetDate = $isOneDay ? $from : $to;
    try {
        // Semua karyawan aktif yang PUNYA akun login
        $stAktif = $pdo->query("
            SELECT m.id, m.username,
                   COALESCE(e.employee_name,'') AS employee_name,
                   UPPER(COALESCE(m.department,''))  AS dept,
                   UPPER(COALESCE(m.office_code,'')) AS office_code
            FROM master_system_login m
            LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code
            WHERE m.deleted_at IS NULL
              AND LOWER(COALESCE(m.status,''))='active'
              AND UPPER(COALESCE(m.department,'')) NOT IN ('SYS','BRANCH')
              AND NOT (
                    UPPER(COALESCE(m.department,''))='MPR'
                    AND LOWER(COALESCE(e.status,'')) <> 'active'
              )
            ORDER BY m.username
        ");
        $allActive = $stAktif->fetchAll(PDO::FETCH_ASSOC);

        // Yang sudah checkin pada tanggal target
        $stCheck = $pdo->prepare("
            SELECT DISTINCT user_id FROM absensi_logs
            WHERE deleted_at IS NULL AND action_type='IN'
              AND DATE(created_at)=?
        ");
        $stCheck->execute([$targetDate]);
        $hadirIds = array_flip($stCheck->fetchAll(PDO::FETCH_COLUMN));

        foreach ($allActive as $u) {
            if (isset($hadirIds[(int)$u['id']])) continue;
            if ($filterOffice !== '' && $u['office_code'] !== $filterOffice) continue;
            if ($filterDept   !== '' && $u['dept']        !== $filterDept)   continue;
            $absentList[] = $u;
        }
    } catch (Throwable $e) {}
}

// ── Summary per karyawan (untuk tab Summary) ─────────────────────────────
$summaryRows = [];
if ($activeTab === 'summary') {
    try {
        // Ambil per user+tanggal agar aturan shift+toleransi yang sama dengan Detail dapat dipakai.
        $st = $pdo->prepare("
            SELECT DATE(al.created_at) AS tanggal, al.user_id, al.username,
                   MAX(CASE WHEN al.action_type='IN' THEN al.created_at END) AS checkin
            FROM absensi_logs al
            LEFT JOIN master_system_login msl ON msl.id = al.user_id AND msl.deleted_at IS NULL
            WHERE {$baseWhereWithDept}
            GROUP BY DATE(al.created_at), al.user_id, al.username
            ORDER BY al.username, tanggal
        ");
        $st->execute($baseParamsWithDept);
        $daily = $st->fetchAll(PDO::FETCH_ASSOC);

        $empInfo = [];
        try {
            $si = $pdo->query("
                SELECT m.id, COALESCE(e.employee_name,'') AS n,
                       UPPER(COALESCE(m.department,''))   AS d,
                       UPPER(COALESCE(m.office_code,''))  AS o
                FROM master_system_login m
                LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code
                WHERE m.deleted_at IS NULL
            ");
            while ($row = $si->fetch(PDO::FETCH_ASSOC)) $empInfo[(int)$row['id']] = $row;
        } catch (Throwable $e) {}

        $acc = [];
        foreach ($daily as $r) {
            $uid = (int)($r['user_id'] ?? 0);
            $info = $empInfo[$uid] ?? ['n'=>'','d'=>'','o'=>''];
            if ($filterOffice !== '' && $info['o'] !== $filterOffice) continue;
            $key = $uid . '|' . (string)($r['username'] ?? '');
            if (!isset($acc[$key])) {
                $acc[$key] = [
                    'user_id'=>$uid, 'username'=>(string)($r['username'] ?? ''),
                    'hari_hadir'=>0, 'hari_terlambat'=>0,
                    'employee_name'=>$info['n'], 'dept'=>$info['d'], 'office_code'=>$info['o'],
                ];
            }
            $acc[$key]['hari_hadir']++;
            $cinTime = !empty($r['checkin']) ? substr((string)$r['checkin'], 11, 5) : '';
            $sumShift = absensi_resolve_shift($pdo, (string)($r['username'] ?? ''), (string)($r['tanggal'] ?? ''));
            $sumLateMin = $sumShift
                ? absensi_shift_late_minutes($cinTime, $sumShift)
                : abs_rekap_late_minutes($cinTime, $stdTimeTol);
            if ($sumLateMin > 0) $acc[$key]['hari_terlambat']++;
        }
        $summaryRows = array_values($acc);
        usort($summaryRows, fn($a,$b) => strcasecmp((string)$a['username'], (string)$b['username']));
    } catch (Throwable $e) {}
}

// ── Fallback rows (untuk saat DataTables offline, max 500) ───────────────
$fallbackRows = [];
if ($activeTab === 'detail') {
    try {
        $fbWhere  = $baseWhere;
        $fbParams = $baseParams;
        $st = $pdo->prepare("
            SELECT DATE(al.created_at) AS tanggal, al.user_id, al.username,
                   UPPER(COALESCE(MAX(al.office_code),'')) AS office_code,
                   MAX(CASE WHEN al.action_type='IN'  THEN al.created_at END) AS checkin,
                   MAX(CASE WHEN al.action_type='OUT' THEN al.created_at END) AS checkout,
                   MAX(CASE WHEN al.action_type='IN'  THEN al.photo_path END) AS photo_in,
                   MAX(CASE WHEN al.action_type='OUT' THEN al.photo_path END) AS photo_out
            FROM absensi_logs al
            WHERE {$fbWhere}
            GROUP BY DATE(al.created_at), al.user_id, al.username
            ORDER BY tanggal DESC, al.username ASC
            LIMIT 500
        ");
        $st->execute($fbParams);
        $fallbackRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// ── Employee name map for fallback ────────────────────────────────────────
$empNameMap = [];
try {
    $st = $pdo->query("
        SELECT m.id,
               COALESCE(e.employee_name,'') AS n,
               UPPER(COALESCE(m.department,''))  AS d,
               UPPER(COALESCE(m.office_code,'')) AS o
        FROM master_system_login m
        LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code
        WHERE m.deleted_at IS NULL
    ");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $empNameMap[(int)$r['id']] = $r;
    }
} catch (Throwable $e) {}

// ── Ref users (user→employee link status) ────────────────────────────────
$ref_users = [];
try {
    $ref_users = $pdo->query("
        SELECT m.id AS user_id, m.username,
               COALESCE(m.holder_employee_code,'-') AS employee_code,
               COALESCE(e.employee_name,'-')        AS employee_name,
               COALESCE(m.office_code,'-')          AS office_code
        FROM master_system_login m
        LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code
        WHERE m.deleted_at IS NULL
        ORDER BY (m.holder_employee_code IS NULL OR m.holder_employee_code='') ASC, m.username
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── URL builder helper ───────────────────────────────────────────────────
function rekap_url(array $merge = []): string {
    global $from, $to, $filterOffice, $filterDept, $filterStatus, $activeTab;
    $p = array_merge([
        'from'   => $from,
        'to'     => $to,
        'office' => $filterOffice,
        'dept'   => $filterDept,
        'status' => $filterStatus,
        'tab'    => $activeTab,
    ], $merge);
    $p = array_filter($p, fn($v) => $v !== '');
    return 'rekap.php?' . http_build_query($p);
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function abs_rekap_table_exists(PDO $pdo, string $t): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute([$t]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function abs_rekap_money(float $v): string {
    return 'Rp ' . number_format($v, 0, ',', '.');
}
function abs_rekap_late_penalty_rules(PDO $pdo): array {
    $defaults = [
        ['min'=>1,  'max'=>19,   'amount'=>5000],
        ['min'=>20, 'max'=>29,   'amount'=>10000],
        ['min'=>30, 'max'=>9999, 'amount'=>20000],
    ];
    if (!abs_rekap_table_exists($pdo, 'absensi_late_penalty_rules')) return $defaults;
    try {
        $rows = $pdo->query("SELECT minute_from AS min, minute_to AS max, penalty_amount AS amount
                             FROM absensi_late_penalty_rules
                             WHERE is_active=1
                             ORDER BY minute_from ASC")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return $defaults;
        $rules = array_map(function($r){
            return [
                'min'=>(int)($r['min'] ?? 0),
                'max'=>(int)($r['max'] ?? 9999),
                'amount'=>(float)($r['amount'] ?? 0),
            ];
        }, $rows);
        // Policy perusahaan: toleransi dipotong dulu. Begitu terlambat efektif >=1 menit,
        // tier pertama langsung berlaku. Guard ini menjaga runtime benar walau DB lama masih 5-19.
        if (!empty($rules) && (int)$rules[0]['min'] > 1 && (float)$rules[0]['amount'] > 0) {
            $rules[0]['min'] = 1;
        }
        return $rules;
    } catch (Throwable $e) {
        return $defaults;
    }
}
function abs_rekap_late_minutes(?string $checkinTime, string $shiftStart): int {
    $checkinTime = trim((string)$checkinTime);
    $shiftStart = trim((string)$shiftStart);
    if ($checkinTime === '' || $shiftStart === '') return 0;
    if (strlen($checkinTime) === 5) $checkinTime .= ':00';
    if (strlen($shiftStart) === 5) $shiftStart .= ':00';
    $a = strtotime('2000-01-01 ' . $checkinTime);
    $b = strtotime('2000-01-01 ' . $shiftStart);
    if ($a === false || $b === false || $a <= $b) return 0;
    return (int)ceil(($a - $b) / 60);
}
function abs_rekap_late_penalty_amount(int $lateMin, array $rules): float {
    if ($lateMin <= 0) return 0.0;
    foreach ($rules as $r) {
        if ($lateMin >= (int)$r['min'] && $lateMin <= (int)$r['max']) return (float)$r['amount'];
    }
    return 0.0;
}


?>
<style>
/* ── Rekap Admin v2 ──────────────────────────────────────────────────── */
:root{--r-card:#131d2e;--r-border:rgba(255,255,255,.09);--r-muted:#64748b}
.rk-filter-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;padding:14px 16px;
    background:var(--r-card);border:1px solid var(--r-border);border-radius:12px;margin-bottom:14px}
.rk-filter-bar label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
    color:var(--r-muted);display:block;margin-bottom:3px}
.rk-filter-bar .form-control,.rk-filter-bar .form-select{
    background:rgba(255,255,255,.05)!important;border-color:rgba(255,255,255,.1)!important;
    color:#e2e8f0!important;font-size:12px}
.rk-quick-btn{padding:4px 11px;border-radius:6px;border:1px solid rgba(255,255,255,.12);
    background:rgba(255,255,255,.05);color:#94a3b8;font-size:11px;font-weight:600;cursor:pointer;transition:.12s}
.rk-quick-btn:hover{background:rgba(255,255,255,.1);color:#e2e8f0}
/* ── KPI tiles ── */
.rk-kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:14px}
.rk-tile{border-radius:12px;padding:12px 14px;border:1px solid var(--r-border);background:rgba(255,255,255,.04)}
.rk-tile .tl-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--r-muted);margin-bottom:5px}
.rk-tile .tl-val{font-size:26px;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
.rk-tile .tl-sub{font-size:11px;color:var(--r-muted);margin-top:4px}
/* ── Tabs ── */
.rk-tabs{display:flex;gap:4px;margin-bottom:12px;padding:4px;background:rgba(255,255,255,.04);border-radius:10px;border:1px solid var(--r-border);width:fit-content}
.rk-tab{padding:6px 16px;border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;color:#64748b;transition:.12s;border:1px solid transparent}
.rk-tab:hover{color:#e2e8f0;background:rgba(255,255,255,.06)}
.rk-tab.active{background:#1e3a5f;color:#60a5fa;border-color:rgba(96,165,250,.3)}
/* ── Table ── */
.rk-table{width:100%;border-collapse:collapse;font-size:13px}
.rk-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--r-muted);padding:9px 12px;border-bottom:1px solid var(--r-border);text-align:left;white-space:nowrap}
.rk-table td{padding:8px 12px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.rk-table tr:hover td{background:rgba(255,255,255,.02)}
/* ── Quick links ── */
.rk-actions{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
/* ── Absent list ── */
.rk-absent-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px}
.rk-absent-card{border:1px solid rgba(239,68,68,.2);background:rgba(239,68,68,.06);border-radius:10px;padding:10px 12px;font-size:13px}
/* ── Lightbox ── */
#absPhotoModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;align-items:center;justify-content:center;cursor:zoom-out}
#absPhotoModal img{max-width:90vw;max-height:90vh;border-radius:10px;box-shadow:0 8px 48px #000}
</style>

<!-- ── Quick Links ──────────────────────────────────────────────────────── -->
<div class="rk-actions">
  <a class="btn btn-sm btn-ghost" href="approval.php">📋 Approval</a>
  <a class="btn btn-sm btn-ghost" href="offices.php">🏢 Office</a>
  <a class="btn btn-sm btn-ghost" href="users.php">👥 Users</a>
  <a class="btn btn-sm btn-ghost" href="payroll_gate.php">💰 Payroll Gate</a>
  <a class="btn btn-sm btn-ghost" href="settings.php">⚙️ Jam Kerja</a>
  <a class="btn btn-sm btn-ghost" href="late_penalty_settings.php">💸 Potongan Telat</a>
  <a class="btn btn-sm btn-ghost" href="shifts.php">🔄 Shift</a>
  <a class="btn btn-sm btn-ghost" href="broadcast.php" style="color:#25d366;border-color:rgba(37,211,102,.3)">📣 Broadcast WA</a>
  <a class="btn btn-sm btn-rmi" href="<?= h(rekap_url(['export'=>'1'])) ?>">📥 Export CSV</a>
</div>

<!-- ── Filter Bar ───────────────────────────────────────────────────────── -->
<form method="get" class="rk-filter-bar" id="frmFilter">
  <input type="hidden" name="tab" value="<?= h($activeTab) ?>">

  <!-- Quick period buttons -->
  <div>
    <label>Periode Cepat</label>
    <div style="display:flex;gap:4px;flex-wrap:wrap">
      <?php
      $periods = [
        'Hari Ini'     => [date('Y-m-d'), date('Y-m-d')],
        'Kemarin'      => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
        'Minggu Ini'   => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
        'Bulan Ini'    => [date('Y-m-01'), date('Y-m-d')],
        'Bulan Lalu'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
      ];
      foreach ($periods as $label => [$pf, $pt]):
        $active = ($from === $pf && $to === $pt) ? 'border-color:#60a5fa;color:#60a5fa;background:rgba(96,165,250,.1)' : '';
      ?>
      <a class="rk-quick-btn" style="<?= $active ?>"
         href="<?= h(rekap_url(['from'=>$pf,'to'=>$pt])) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Date range -->
  <div>
    <label>Dari</label>
    <input type="date" name="from" class="form-control form-control-sm" value="<?= h($from) ?>" style="width:130px">
  </div>
  <div>
    <label>Sampai</label>
    <input type="date" name="to" class="form-control form-control-sm" value="<?= h($to) ?>" style="width:130px">
  </div>

  <!-- Office filter -->
  <div>
    <label>Office</label>
    <select name="office" class="form-select form-select-sm" style="width:110px">
      <option value="">Semua</option>
      <?php foreach ($officeList as $o): ?>
        <option value="<?= h(strtoupper($o['office_code'])) ?>"
                <?= $filterOffice === strtoupper($o['office_code']) ? 'selected' : '' ?>>
          <?= h($o['office_code']) ?> <?= $o['office_name'] ? '— '.h($o['office_name']) : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Dept filter -->
  <div>
    <label>Dept</label>
    <select name="dept" class="form-select form-select-sm" style="width:100px">
      <option value="">Semua</option>
      <?php foreach ($deptList as $d): ?>
        <option value="<?= h($d) ?>" <?= $filterDept === $d ? 'selected' : '' ?>><?= h($d) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Status filter (detail tab only) -->
  <?php if ($activeTab === 'detail'): ?>
  <div>
    <label>Status</label>
    <select name="status" class="form-select form-select-sm" style="width:130px">
      <option value=""           <?= $filterStatus === '' ? 'selected' : '' ?>>Semua</option>
      <option value="on_time"    <?= $filterStatus === 'on_time'    ? 'selected' : '' ?>>✓ Normal</option>
      <option value="late"       <?= $filterStatus === 'late'       ? 'selected' : '' ?>>⏰ Terlambat</option>
      <option value="no_checkout"<?= $filterStatus === 'no_checkout'? 'selected' : '' ?>>⚠️ Belum Pulang</option>
    </select>
  </div>
  <?php endif; ?>

  <div style="display:flex;gap:6px;align-items:flex-end">
    <button type="submit" class="btn btn-sm btn-rmi">Tampilkan</button>
    <a class="btn btn-sm btn-ghost" href="rekap.php">Reset</a>
  </div>
</form>

<!-- ── KPI Summary ──────────────────────────────────────────────────────── -->
<div class="rk-kpi-row">
  <div class="rk-tile" style="border-color:rgba(34,197,94,.3)">
    <div class="tl-lbl">✅ Total Hadir</div>
    <div class="tl-val" style="color:#4ade80"><?= number_format($kpi['hadir']) ?></div>
    <div class="tl-sub">record absensi</div>
  </div>
  <div class="rk-tile" style="border-color:rgba(34,197,94,.15)">
    <div class="tl-lbl">✓ Normal</div>
    <div class="tl-val" style="color:#86efac"><?= number_format($kpi['normal']) ?></div>
    <div class="tl-sub">tepat waktu + checkout</div>
  </div>
  <div class="rk-tile" style="border-color:rgba(251,146,60,.3)">
    <div class="tl-lbl">⏰ Terlambat</div>
    <div class="tl-val" style="color:#fb923c"><?= number_format($kpi['terlambat']) ?></div>
    <div class="tl-sub">masuk &gt; <?= h($stdTimeTol) ?><?= $_tolerance > 0 ? " <span style='opacity:.6;font-size:10px'>(tol. {$_tolerance}m)</span>" : '' ?></div>
  </div>
  <div class="rk-tile" style="border-color:rgba(239,68,68,.28)">
    <div class="tl-lbl">💸 Potongan Terlambat</div>
    <div class="tl-val" style="color:#f87171;font-size:20px"><?= abs_rekap_money((float)$kpi['late_penalty']) ?></div>
    <div class="tl-sub">berdasarkan aturan menit</div>
  </div>
  <div class="rk-tile" style="border-color:rgba(251,191,36,.3)">
    <div class="tl-lbl">⚠️ Belum Pulang</div>
    <div class="tl-val" style="color:#fbbf24"><?= number_format($kpi['belum_pulang']) ?></div>
    <div class="tl-sub">checkin, belum checkout</div>
  </div>
  <?php if ($isOneDay): ?>
  <div class="rk-tile" style="border-color:rgba(239,68,68,.3)">
    <div class="tl-lbl">❌ Tidak Hadir</div>
    <div class="tl-val" style="color:#f87171"><?= number_format(count($absentList)) ?></div>
    <div class="tl-sub">aktif tapi tidak checkin</div>
  </div>
  <?php endif; ?>
  <div class="rk-tile">
    <div class="tl-lbl">📅 Periode</div>
    <div class="tl-val" style="font-size:13px;line-height:1.4;color:#e2e8f0"><?= h(date('d/m', strtotime($from))) ?> — <?= h(date('d/m/Y', strtotime($to))) ?></div>
    <div class="tl-sub"><?= h($filterOffice ?: 'Semua Office') ?> <?= $filterDept ? '· '.h($filterDept) : '' ?></div>
  </div>
</div>

<!-- ── Tab nav ──────────────────────────────────────────────────────────── -->
<div class="rk-tabs">
  <a class="rk-tab <?= $activeTab==='detail'  ? 'active' : '' ?>"
     href="<?= h(rekap_url(['tab'=>'detail'])) ?>">📋 Detail</a>
  <a class="rk-tab <?= $activeTab==='summary' ? 'active' : '' ?>"
     href="<?= h(rekap_url(['tab'=>'summary'])) ?>">📊 Summary Karyawan</a>
  <a class="rk-tab <?= $activeTab==='absent'  ? 'active' : '' ?>"
     href="<?= h(rekap_url(['tab'=>'absent'])) ?>">
    ❌ Tidak Hadir <?= count($absentList) > 0 ? '<span style="background:#ef4444;color:#fff;border-radius:10px;font-size:10px;padding:1px 7px;margin-left:4px">'.count($absentList).'</span>' : '' ?>
  </a>
</div>

<?php if ($activeTab === 'detail'): ?>
<!-- ══ TAB: DETAIL ════════════════════════════════════════════════════════ -->

<div style="font-size:12px;color:var(--r-muted);margin-bottom:8px">
  DataTables aktif jika CDN tersedia. Jam standar masuk: <strong><?= h($stdTime) ?></strong><?= $_tolerance > 0 ? " + toleransi <strong>{$_tolerance} menit</strong> → batas terlambat: <strong>{$stdTimeTol}</strong>" : '' ?>.
  Fallback tampil maks 500 baris.
</div>

<div style="overflow-x:auto">
  <table id="rekapTable" class="display rk-table" style="width:100%">
    <thead>
      <tr>
        <th>Tanggal</th>
        <th>Username</th>
        <th>Nama</th>
        <th>Dept/Office</th>
        <th>Shift</th>
        <th>Check-in</th>
        <th style="text-align:center">Status</th>
        <th>Menit Telat</th>
        <th>Potongan</th>
        <th>Lembur</th>
        <th style="text-align:center">Foto In</th>
        <th>Check-out</th>
        <th style="text-align:center">Foto Out</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($fallbackRows as $r):
      $uid      = (int)($r['user_id'] ?? 0);
      $emp      = $empNameMap[$uid] ?? ['n'=>'','d'=>'','o'=>''];
      $empName  = $emp['n'];
      $dept     = $emp['d'];
      $office   = $emp['o'] ?: strtoupper((string)($r['office_code'] ?? ''));

      $photoIn  = ltrim((string)($r['photo_in']  ?? ''), '/');
      $photoOut = ltrim((string)($r['photo_out'] ?? ''), '/');
      $urlIn    = $photoIn  ? '../photo.php?f=' . urlencode($photoIn)  : '';
      $urlOut   = $photoOut ? '../photo.php?f=' . urlencode($photoOut) : '';

      $cin  = $r['checkin']  ?? null;
      $cout = $r['checkout'] ?? null;
      $cinTime  = $cin  ? substr($cin, 11, 5) : '';
      $coutTime = $cout ? substr($cout, 11, 5) : '';
      $tanggal  = $r['tanggal'] ?? substr((string)$cin, 0, 10);

      // Resolusi shift untuk user + tanggal ini
      $userShift   = absensi_resolve_shift($pdo, (string)($r['username'] ?? ''), $tanggal);
      $shiftLabel  = $userShift ? $userShift['shift_name'] : '—';
      $shiftTimes  = $userShift ? (absensi_shift_fmt($userShift['checkin_time']).'–'.absensi_shift_fmt($userShift['checkout_time'])) : '';

      // Gunakan shift untuk menentukan terlambat (menggantikan stdTimeTol global)
      $isLate = $userShift
        ? (absensi_shift_checkin_status($cinTime, $userShift) === 'late')
        : ($cinTime !== '' && $cinTime > $stdTimeTol);
      $noOut = $cin && !$cout;
      $lateMin = $userShift
        ? absensi_shift_late_minutes($cinTime, $userShift)
        : abs_rekap_late_minutes($cinTime, $stdTimeTol);
      $lateAmt = abs_rekap_late_penalty_amount($lateMin, $latePenaltyRules);

      // Lembur
      $overtimeMin  = ($userShift && $coutTime) ? absensi_shift_overtime_min($coutTime, $userShift) : 0;
      $overtimeStr  = absensi_fmt_overtime($overtimeMin);

      // Duration
      $duration = '';
      if ($cin && $cout) {
        $diff = strtotime($cout) - strtotime($cin);
        if ($diff > 0) {
          $duration = intdiv($diff, 3600) . 'j ' . intdiv($diff % 3600, 60) . 'm';
        }
      }

      // Status badge
      if ($isLate && $noOut) {
        $badge = '<span style="background:rgba(239,68,68,.2);color:#f87171;border:1px solid rgba(239,68,68,.3);padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">⏰ Telat+Belum Pulang</span>';
      } elseif ($isLate) {
        $badge = '<span style="background:rgba(251,146,60,.15);color:#fb923c;border:1px solid rgba(251,146,60,.3);padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">⏰ Terlambat</span>';
      } elseif ($noOut) {
        $badge = '<span style="background:rgba(251,191,36,.15);color:#fbbf24;border:1px solid rgba(251,191,36,.3);padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">⚠️ Belum Pulang</span>';
      } else {
        $badge = '<span style="background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.2);padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">✓ Normal</span>';
      }
    ?>
      <tr>
        <td><?= h($r['tanggal'] ?? '') ?></td>
        <td>
          <div style="font-weight:600"><?= h($r['username'] ?? '') ?></div>
          <div style="font-size:10px;color:var(--r-muted)">#<?= (int)$uid ?></div>
        </td>
        <td>
          <?= $empName ? '<span style="font-weight:600">'.h($empName).'</span>' : '<span style="color:var(--r-muted);font-size:11px">—</span>' ?>
        </td>
        <td>
          <?php if ($office): ?><span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:5px;background:rgba(99,102,241,.15);color:#c7d2fe;border:1px solid rgba(99,102,241,.2)"><?= h($office) ?></span><?php endif; ?>
          <?php if ($dept):   ?><span style="font-size:10px;color:var(--r-muted)"><?= h($dept) ?></span><?php endif; ?>
        </td>
        <td style="white-space:nowrap;font-size:11px">
          <span style="font-weight:600;color:#94a3b8"><?= h($shiftLabel) ?></span>
          <?php if ($shiftTimes): ?>
            <div style="color:#64748b;font-size:10px"><?= h($shiftTimes) ?></div>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($cinTime): ?>
            <span style="font-weight:700;color:<?= $isLate ? '#fb923c' : '#4ade80' ?>"><?= h($cinTime) ?></span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="text-align:center"><?= $badge ?></td>
        <td style="white-space:nowrap;text-align:center">
          <?= $lateMin > 0 ? '<span style="color:#fb923c;font-weight:700">'.h($lateMin).' menit</span>' : '<span style="color:#475569;font-size:11px">—</span>' ?>
        </td>
        <td style="white-space:nowrap;text-align:center">
          <?= $lateAmt > 0 ? '<span style="color:#f87171;font-weight:700">'.h(abs_rekap_money($lateAmt)).'</span>' : '<span style="color:#475569;font-size:11px">—</span>' ?>
        </td>
        <td style="white-space:nowrap;text-align:center">
          <?php if ($overtimeMin > 0): ?>
            <span style="background:rgba(168,85,247,.15);color:#c084fc;border:1px solid rgba(168,85,247,.3);padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700">
              +<?= h($overtimeStr) ?>
            </span>
          <?php else: ?>
            <span style="color:#475569;font-size:11px">—</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;padding:4px 8px">
          <?php if ($urlIn): ?>
            <a href="<?= h($urlIn) ?>" class="abs-photo-thumb" data-url="<?= h($urlIn) ?>" target="_blank">
              <img src="<?= h($urlIn) ?>" alt="in" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid rgba(255,255,255,.1);cursor:zoom-in" loading="lazy"
                   onerror="this.closest('a').innerHTML='<span style=\'color:var(--r-muted);font-size:10px\'>—</span>'">
            </a>
          <?php else: ?><span style="color:var(--r-muted);font-size:11px">—</span><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($cout): ?>
            <span style="font-weight:700;color:#60a5fa"><?= h(substr($cout, 11, 5)) ?></span>
            <?php if ($duration): ?><div style="font-size:10px;color:var(--r-muted)"><?= h($duration) ?></div><?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="text-align:center;padding:4px 8px">
          <?php if ($urlOut): ?>
            <a href="<?= h($urlOut) ?>" class="abs-photo-thumb" data-url="<?= h($urlOut) ?>" target="_blank">
              <img src="<?= h($urlOut) ?>" alt="out" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid rgba(255,255,255,.1);cursor:zoom-in" loading="lazy"
                   onerror="this.closest('a').innerHTML='<span style=\'color:var(--r-muted);font-size:10px\'>—</span>'">
            </a>
          <?php else: ?><span style="color:var(--r-muted);font-size:11px">—</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php elseif ($activeTab === 'summary'): ?>
<!-- ══ TAB: SUMMARY PER KARYAWAN ═════════════════════════════════════════ -->
<div class="rmi-card p-3">
  <div style="font-size:12px;color:var(--r-muted);margin-bottom:10px">
    Rekap per karyawan untuk periode <?= h($from) ?> s.d. <?= h($to) ?>.
    Total karyawan tampil: <strong><?= count($summaryRows) ?></strong>
  </div>
  <div style="overflow-x:auto">
  <table class="rk-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Karyawan</th>
        <th>Dept/Office</th>
        <th style="text-align:right">Hari Hadir</th>
        <th style="text-align:right">Hari Terlambat</th>
        <th>Attendance Rate</th>
      </tr>
    </thead>
    <tbody>
    <?php
    // Hitung total hari kerja dalam periode
    $workdays = 0;
    $d = new DateTime($from);
    $dEnd = new DateTime($to);
    while ($d <= $dEnd) {
        if ((int)$d->format('N') <= 5) $workdays++;
        $d->modify('+1 day');
    }

    foreach ($summaryRows as $i => $r):
      $hadir    = (int)$r['hari_hadir'];
      $telat    = (int)$r['hari_terlambat'];
      $rate     = $workdays > 0 ? round($hadir / $workdays * 100) : 0;
      $rateColor= $rate >= 90 ? '#4ade80' : ($rate >= 70 ? '#fbbf24' : '#f87171');
    ?>
      <tr>
        <td style="color:var(--r-muted);font-size:11px"><?= $i+1 ?></td>
        <td>
          <div style="font-weight:600"><?= $r['employee_name'] ? h($r['employee_name']) : h($r['username']) ?></div>
          <div style="font-size:10px;color:var(--r-muted)"><?= h($r['username']) ?></div>
        </td>
        <td>
          <?php if ($r['office_code']): ?><span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:5px;background:rgba(99,102,241,.15);color:#c7d2fe;border:1px solid rgba(99,102,241,.2)"><?= h($r['office_code']) ?></span><?php endif; ?>
          <?php if ($r['dept']): ?><span style="font-size:10px;color:var(--r-muted)"><?= h($r['dept']) ?></span><?php endif; ?>
        </td>
        <td style="text-align:right;font-weight:700;color:#4ade80"><?= $hadir ?></td>
        <td style="text-align:right;font-weight:700;color:<?= $telat > 0 ? '#fb923c' : 'var(--r-muted)' ?>"><?= $telat ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="flex:1;height:6px;background:rgba(255,255,255,.08);border-radius:3px;overflow:hidden">
              <div style="height:6px;width:<?= min(100, $rate) ?>%;background:<?= $rateColor ?>;border-radius:3px;transition:.3s"></div>
            </div>
            <span style="font-size:11px;font-weight:700;color:<?= $rateColor ?>;min-width:30px"><?= $rate ?>%</span>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($summaryRows)): ?>
      <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--r-muted)">Tidak ada data untuk filter ini.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php elseif ($activeTab === 'absent'): ?>
<!-- ══ TAB: TIDAK HADIR ═══════════════════════════════════════════════════ -->
<div class="rmi-card p-3">
  <?php if (!$isOneDay): ?>
  <div style="padding:12px;background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:10px;margin-bottom:12px;font-size:12px;color:#fbbf24">
    ⚠️ Fitur "Tidak Hadir" akurat untuk <strong>1 hari spesifik</strong>.
    Untuk multi-hari, akan menampilkan karyawan yang tidak hadir pada tanggal <strong><?= h($to) ?></strong> (hari terakhir range).
    Gunakan filter "Hari Ini" atau "Kemarin" untuk akurasi penuh.
  </div>
  <?php endif; ?>

  <div style="font-size:12px;color:var(--r-muted);margin-bottom:12px">
    Karyawan aktif yang <strong>tidak tercatat checkin</strong> pada
    <?= h($isOneDay ? $from : $to) ?> — <?= h($filterOffice ?: 'Semua Office') ?>.
    Total: <strong style="color:#f87171"><?= count($absentList) ?> orang</strong>
  </div>

  <?php if (empty($absentList)): ?>
    <div style="text-align:center;padding:30px;color:#4ade80">
      🎉 Semua karyawan hadir pada tanggal ini!
    </div>
  <?php else: ?>
  <div class="rk-absent-grid">
    <?php foreach ($absentList as $u): ?>
      <div class="rk-absent-card">
        <div style="font-weight:700;color:#f1f5f9"><?= $u['employee_name'] ?: h($u['username']) ?></div>
        <div style="font-size:11px;color:var(--r-muted)"><?= h($u['username']) ?></div>
        <div style="margin-top:5px;display:flex;gap:5px;flex-wrap:wrap">
          <?php if ($u['office_code']): ?>
            <span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:5px;background:rgba(99,102,241,.15);color:#c7d2fe;border:1px solid rgba(99,102,241,.2)"><?= h($u['office_code']) ?></span>
          <?php endif; ?>
          <?php if ($u['dept']): ?>
            <span style="font-size:10px;color:var(--r-muted)"><?= h($u['dept']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<!-- ── User-Employee Ref (collapsible) ─────────────────────────────────── -->
<?php if ($ref_users): $sudah = array_filter($ref_users, fn($r)=>$r['employee_code']!=='-'); $belum = array_filter($ref_users, fn($r)=>$r['employee_code']==='-'); ?>
<details style="margin-top:14px">
  <summary style="cursor:pointer;font-weight:600;font-size:13px;padding:9px 14px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:10px;display:flex;justify-content:space-between;align-items:center;list-style:none">
    <span>Referensi User → Karyawan
      <span style="background:rgba(34,197,94,.2);color:#4ade80;padding:1px 8px;border-radius:10px;font-size:11px;margin-left:6px"><?= count($sudah) ?> terhubung</span>
      <?php if (count($belum)): ?><span style="background:rgba(239,68,68,.2);color:#f87171;padding:1px 8px;border-radius:10px;font-size:11px;margin-left:4px"><?= count($belum) ?> belum</span><?php endif; ?>
    </span>
    <span style="font-size:11px;color:var(--r-muted)">▼</span>
  </summary>
  <div style="border:1px solid rgba(255,255,255,.1);border-top:none;border-radius:0 0 10px 10px;overflow-x:auto">
    <table class="rk-table">
      <thead><tr><th>Username</th><th>Kode Karyawan</th><th>Nama</th><th>Office</th><th style="text-align:center">Status</th></tr></thead>
      <tbody>
      <?php foreach ($ref_users as $r): $linked = $r['employee_code'] !== '-'; ?>
        <tr style="<?= !$linked ? 'opacity:.45' : '' ?>">
          <td style="font-weight:600"><?= h($r['username']) ?></td>
          <td style="font-family:monospace;color:#60a5fa"><?= h($r['employee_code']) ?></td>
          <td><?= h($r['employee_name']) ?></td>
          <td style="color:var(--r-muted)"><?= h($r['office_code']) ?></td>
          <td style="text-align:center">
            <?php if ($linked): ?>
              <span style="background:rgba(34,197,94,.15);color:#4ade80;padding:2px 10px;border-radius:12px;font-size:11px">✓</span>
            <?php else: ?>
              <span style="background:rgba(239,68,68,.12);color:#f87171;padding:2px 10px;border-radius:12px;font-size:11px">✗ Belum</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<!-- Lightbox -->
<div id="absPhotoModal" onclick="this.style.display='none'">
  <img id="absPhotoModalImg" src="" alt="Foto Absensi">
</div>

<!-- DataTables (detail tab only) -->
<?php if ($activeTab === 'detail'): ?>
<link rel="stylesheet" href="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/css/jquery.dataTables.min.css?v=20260209">
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script>
(function(){
  // Lightbox
  document.addEventListener('click', function(e){
    var a = e.target.closest('.abs-photo-thumb');
    if (!a) return;
    e.preventDefault();
    document.getElementById('absPhotoModalImg').src = a.dataset.url;
    document.getElementById('absPhotoModal').style.display = 'flex';
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') document.getElementById('absPhotoModal').style.display = 'none';
  });

  if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) return;

  $('#rekapTable').DataTable({
    processing: true,
    serverSide: true,
    searching: true,
    paging: true,
    pageLength: 25,
    lengthMenu: [10, 25, 50, 100],
    order: [[0, 'desc']],
    columnDefs: [{ orderable: false, targets: [6, 8, 9, 10, 12] }],
    ajax: {
      url: 'api/rekap_dt.php',
      data: function(d){
        d.from     = '<?= h($from) ?>';
        d.to       = '<?= h($to) ?>';
        d.office   = '<?= h($filterOffice) ?>';
        d.dept     = '<?= h($filterDept) ?>';
        d.status   = '<?= h($filterStatus) ?>';
        d.std_time = '<?= h($stdTimeTol) ?>'; // effective: jam masuk + toleransi
      }
    },
    createdRow: function(row){
      $(row).find('.abs-photo-thumb').off('click').on('click', function(e){
        e.preventDefault();
        document.getElementById('absPhotoModalImg').src = this.dataset.url;
        document.getElementById('absPhotoModal').style.display = 'flex';
      });
    },
    language: {
      search: 'Cari username/office:',
      emptyTable: 'Tidak ada data',
      processing: 'Memuat…',
      paginate: { previous: '←', next: '→' }
    }
  });
})();
</script>
<?php else: ?>
<script>
document.addEventListener('click', function(e){
  var a = e.target.closest('.abs-photo-thumb');
  if (!a) return;
  e.preventDefault();
  document.getElementById('absPhotoModalImg').src = a.dataset.url;
  document.getElementById('absPhotoModal').style.display = 'flex';
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') document.getElementById('absPhotoModal').style.display = 'none';
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../_layout_bottom.php'; ?>
