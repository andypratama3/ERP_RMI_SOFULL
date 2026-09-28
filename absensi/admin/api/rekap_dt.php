<?php
declare(strict_types=1);

// DataTables server-side endpoint for Rekap Absensi (paging/search).
// v2 — tambah: employee_name search, office/dept filter, status, durasi, is_late

require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once __DIR__ . '/../../_inc/shift_helper.php';

if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.RECAP');

if (!absensi_is_hr_admin($pdo, $ABS_USER)) {
    rmi_json(['error' => 'Akses ditolak'], 403);
}

function dt_int($v, int $def = 0): int {
    if ($v === null || $v === '') return $def;
    if (is_array($v)) return $def;
    return (int)$v;
}

function dt_table_exists(PDO $pdo, string $t): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute([$t]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function dt_money(float $v): string {
    return 'Rp ' . number_format($v, 0, ',', '.');
}
function dt_late_penalty_rules(PDO $pdo): array {
    $defaults = [
        ['min'=>1,  'max'=>19,   'amount'=>5000],
        ['min'=>20, 'max'=>29,   'amount'=>10000],
        ['min'=>30, 'max'=>9999, 'amount'=>20000],
    ];
    if (!dt_table_exists($pdo, 'absensi_late_penalty_rules')) return $defaults;
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
        // Runtime guard untuk data rule lama 5-19: setelah toleransi, menit ke-1 sudah kena tier pertama.
        if (!empty($rules) && (int)$rules[0]['min'] > 1 && (float)$rules[0]['amount'] > 0) {
            $rules[0]['min'] = 1;
        }
        return $rules;
    } catch (Throwable $e) { return $defaults; }
}
function dt_late_minutes(?string $checkinTime, string $effectiveStart): int {
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
function dt_late_penalty_amount(int $lateMin, array $rules): float {
    if ($lateMin <= 0) return 0.0;
    foreach ($rules as $r) {
        if ($lateMin >= (int)$r['min'] && $lateMin <= (int)$r['max']) return (float)$r['amount'];
    }
    return 0.0;
}


// ── Input params ──────────────────────────────────────────────────────────
$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to   = (string)($_GET['to']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
$fromDT = $from . ' 00:00:00';
$toDT   = $to   . ' 23:59:59';

// Batas efektif global (jam masuk + toleransi). Rekap mengirim std_time yang sudah efektif.
$globalStd = (string)(absensi_setting($pdo, 'checkin_std_time', '07:00') ?? '07:00');
$globalTol = max(0, (int)(absensi_setting($pdo, 'late_tolerance_min', '5') ?? '5'));
$globalTs = strtotime('2000-01-01 ' . $globalStd . ':00');
$globalEffective = ($globalTs !== false && $globalTol > 0) ? date('H:i', $globalTs + ($globalTol * 60)) : $globalStd;
$stdTime = preg_match('/^\d{2}:\d{2}$/', $_GET['std_time'] ?? '') ? (string)$_GET['std_time'] : $globalEffective;
$latePenaltyRules = dt_late_penalty_rules($pdo);

// Filters
$filterOffice = strtoupper(trim((string)($_GET['office'] ?? '')));
$filterDept   = strtoupper(trim((string)($_GET['dept']   ?? '')));
$filterStatus = strtolower(trim((string)($_GET['status'] ?? '')));
// status: '' = semua, 'late' = terlambat, 'no_checkout' = belum pulang, 'on_time' = tepat waktu

$draw   = dt_int($_GET['draw']   ?? 1, 1);
$start  = max(0, dt_int($_GET['start']  ?? 0, 0));
$length = dt_int($_GET['length'] ?? 25, 25);
if ($length < 1)   $length = 25;
if ($length > 200) $length = 200;

$search = '';
if (isset($_GET['search']) && is_array($_GET['search'])) {
    $search = trim((string)($_GET['search']['value'] ?? ''));
}

$orderCol = 0;
$orderDir = 'DESC';
if (isset($_GET['order'][0]) && is_array($_GET['order'][0])) {
    $orderCol = dt_int($_GET['order'][0]['column'] ?? 0, 0);
    $dir = strtolower((string)($_GET['order'][0]['dir'] ?? 'desc'));
    $orderDir = ($dir === 'asc') ? 'ASC' : 'DESC';
}

// cols: 0=tanggal,1=username,2=nama,3=dept_office,4=shift,5=checkin,6=status,7=menit_telat,8=potongan_telat,9=lembur,10=foto_in,11=checkout,12=foto_out
$orderBy = 'tanggal ' . $orderDir . ', username ASC';
if ($orderCol === 1) $orderBy = 'username '   . $orderDir;
if ($orderCol === 2) $orderBy = 'employee_name ' . $orderDir;
if ($orderCol === 5) $orderBy = 'checkin '    . $orderDir;
if ($orderCol === 11) $orderBy = 'checkout '   . $orderDir;

// ── Pre-load employee map: user_id → {name, dept, office} ─────────────────
$empMap = [];
try {
    $st = $pdo->query("
        SELECT m.id,
               COALESCE(e.employee_name, '') AS employee_name,
               COALESCE(m.department, '')    AS department,
               COALESCE(m.office_code, '')   AS office_code
        FROM master_system_login m
        LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code
        WHERE m.deleted_at IS NULL
    ");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $empMap[(int)$row['id']] = [
            'name'   => (string)$row['employee_name'],
            'dept'   => strtoupper((string)$row['department']),
            'office' => strtoupper((string)$row['office_code']),
        ];
    }
} catch (Throwable $e) {}

// ── Build WHERE conditions ────────────────────────────────────────────────
$whereClauses = ["al.deleted_at IS NULL", "al.created_at BETWEEN ? AND ?"];
$paramsBase   = [$fromDT, $toDT];

// Office filter via absensi_logs.office_code
if ($filterOffice !== '') {
    $whereClauses[] = "UPPER(COALESCE(al.office_code,'')) = ?";
    $paramsBase[]   = $filterOffice;
}

// Dept filter: via master_system_login.department (JOIN needed)
// We'll handle dept filter in PHP after fetching (simpler + avoids complex subquery)
// For large datasets this is fine with the GROUP BY approach.

// Search: username OR office_code
$paramsSearch = $paramsBase;
if ($search !== '') {
    $like = '%' . $search . '%';
    $whereClauses[] = "(al.username LIKE ? OR UPPER(COALESCE(al.office_code,'')) LIKE ?)";
    $paramsSearch[] = $like;
    $paramsSearch[] = strtoupper($like);
}

$whereSQL       = implode(' AND ', $whereClauses);
$whereBaseSQL   = implode(' AND ', array_slice($whereClauses, 0, count($paramsBase) === count($paramsSearch) ? count($whereClauses) : count($whereClauses) - 1));

// Recalculate base without search
$paramsBaseOnly = $paramsBase;
$whereBaseOnly  = implode(' AND ', array_filter($whereClauses, fn($c) => !str_contains($c, 'LIKE')));

// ── Count total (no search, no dept filter) ───────────────────────────────
$sqlTotal = "SELECT COUNT(*) FROM (
    SELECT 1 FROM absensi_logs al
    WHERE {$whereBaseOnly}
    GROUP BY DATE(al.created_at), al.user_id
) x";
try {
    $stmt = $pdo->prepare($sqlTotal);
    $stmt->execute($paramsBaseOnly);
    $recordsTotal = (int)$stmt->fetchColumn();
} catch (Throwable $e) { $recordsTotal = 0; }

// ── Count filtered (with search) ─────────────────────────────────────────
$recordsFiltered = $recordsTotal;
if ($search !== '') {
    $sqlFiltered = "SELECT COUNT(*) FROM (
        SELECT 1 FROM absensi_logs al
        WHERE {$whereSQL}
        GROUP BY DATE(al.created_at), al.user_id
    ) x";
    try {
        $stmt = $pdo->prepare($sqlFiltered);
        $stmt->execute($paramsSearch);
        $recordsFiltered = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $recordsFiltered = 0; }
}

// ── Fetch page data ───────────────────────────────────────────────────────
// Fetch extra to allow PHP-side dept filter (over-fetch then slice)
$fetchLimit  = $length + 500; // over-fetch for dept filter
$fetchOffset = $start;
if ($filterDept !== '') {
    $fetchLimit  = 2000; // fetch more for dept filter
    $fetchOffset = 0;
}

$sqlData = "SELECT
    DATE(al.created_at)                              AS tanggal,
    al.user_id,
    al.username,
    MAX(UPPER(COALESCE(al.office_code,'')))          AS office_code,
    MAX(CASE WHEN al.action_type='IN'  THEN al.created_at END)  AS checkin,
    MAX(CASE WHEN al.action_type='OUT' THEN al.created_at END)  AS checkout,
    MAX(CASE WHEN al.action_type='IN'  THEN al.photo_path END)  AS photo_in,
    MAX(CASE WHEN al.action_type='OUT' THEN al.photo_path END)  AS photo_out
FROM absensi_logs al
WHERE {$whereSQL}
GROUP BY DATE(al.created_at), al.user_id, al.username
ORDER BY {$orderBy}
LIMIT {$fetchLimit} OFFSET {$fetchOffset}";

$rows = [];
try {
    $stmt = $pdo->prepare($sqlData);
    $stmt->execute($paramsSearch);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rows = []; }

// ── Photo HTML helper ─────────────────────────────────────────────────────
function rekap_photo_html(string $path): string {
    if ($path === '') return '<span style="color:#4b5563;font-size:11px">—</span>';
    $url = htmlspecialchars('../photo.php?f=' . urlencode($path), ENT_QUOTES, 'UTF-8');
    return '<a href="' . $url . '" class="abs-photo-thumb" data-url="' . $url . '" target="_blank">'
         . '<img src="' . $url . '" alt="foto" '
         . 'style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid rgba(255,255,255,.12);cursor:zoom-in" '
         . 'loading="lazy" '
         . 'onerror="this.closest(\'a\').innerHTML=\'<span style=\\\'color:#4b5563;font-size:11px\\\'>—</span>\'">'
         . '</a>';
}

// ── Build response rows ───────────────────────────────────────────────────
$data = [];
$sliceStart  = ($filterDept !== '') ? $start : 0;
$sliceCount  = 0;
$filteredOut = 0;

foreach ($rows as $r) {
    $uid     = (int)($r['user_id'] ?? 0);
    $emp     = $empMap[$uid] ?? ['name'=>'','dept'=>'','office'=>''];
    $empName = $emp['name'];
    $empDept = $emp['dept'] ?: strtoupper((string)($_SESSION['department'] ?? ''));
    $offCode = $emp['office'] ?: strtoupper(trim((string)($r['office_code'] ?? '')));

    // PHP-side dept filter
    if ($filterDept !== '' && $empDept !== $filterDept) {
        $filteredOut++;
        continue;
    }

    // Pagination when dept filter active
    if ($filterDept !== '') {
        if ($sliceCount < $sliceStart) { $sliceCount++; continue; }
        if (count($data) >= $length) break;
    }

    $uname   = (string)($r['username'] ?? '');
    $tanggal = (string)($r['tanggal'] ?? '');
    $cin     = $r['checkin']  ?? null;
    $cout    = $r['checkout'] ?? null;
    $photoIn = trim((string)($r['photo_in']  ?? ''));
    $photoOut= trim((string)($r['photo_out'] ?? ''));

    // Shift resolution
    $userShift  = absensi_resolve_shift($pdo, $uname, $tanggal);
    $shiftLabel = $userShift ? ($userShift['shift_name'] ?? '—') : '—';
    $shiftTimes = '';
    if ($userShift) {
        $shiftTimes = absensi_shift_fmt($userShift['checkin_time'] ?? '') . '–' . absensi_shift_fmt($userShift['checkout_time'] ?? '');
    }

    // Late check (use shift if available)
    $cinTime    = $cin ? substr($cin, 11, 5) : '';
    $coutTime   = $cout ? substr($cout, 11, 5) : '';
    $isLate     = false;
    $isMissCout = false;
    $duration   = '';
    if ($cinTime !== '') {
        $isLate = $userShift
            ? (absensi_shift_checkin_status($cinTime, $userShift) === 'late')
            : ($cinTime > $stdTime);
    }

    // Hitung menit telat SETELAH toleransi. Status dan jumlah menit wajib memakai threshold yang sama.
    $lateMin = $userShift
        ? absensi_shift_late_minutes($cinTime, $userShift)
        : dt_late_minutes($cinTime, $stdTime);
    $lateAmt = dt_late_penalty_amount($lateMin, $latePenaltyRules);
    if ($cin && !$cout) {
        $isMissCout = true;
    }
    if ($cin && $cout) {
        $diff = strtotime($cout) - strtotime($cin);
        if ($diff > 0) {
            $hours = intdiv($diff, 3600);
            $mins  = intdiv($diff % 3600, 60);
            $duration = $hours . 'j ' . $mins . 'm';
        }
    }

    // Overtime
    $overtimeMin = ($userShift && $coutTime) ? absensi_shift_overtime_min($coutTime, $userShift) : 0;
    $overtimeStr = absensi_fmt_overtime($overtimeMin);

    // Status filter
    if ($filterStatus === 'late'       && !$isLate)     continue;
    if ($filterStatus === 'no_checkout' && !$isMissCout) continue;
    if ($filterStatus === 'on_time'    && ($isLate || $isMissCout)) continue;

    // Status badge HTML
    if ($isLate && $isMissCout) {
        $statusHtml = '<span style="background:rgba(239,68,68,.2);color:#f87171;border:1px solid rgba(239,68,68,.3);padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap">⏰ Telat + Belum Pulang</span>';
    } elseif ($isLate) {
        $statusHtml = '<span style="background:rgba(251,146,60,.15);color:#fb923c;border:1px solid rgba(251,146,60,.3);padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">⏰ Terlambat</span>';
    } elseif ($isMissCout) {
        $statusHtml = '<span style="background:rgba(251,191,36,.15);color:#fbbf24;border:1px solid rgba(251,191,36,.3);padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">⚠️ Belum Pulang</span>';
    } else {
        $statusHtml = '<span style="background:rgba(34,197,94,.12);color:#4ade80;border:1px solid rgba(34,197,94,.2);padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">✓ Normal</span>';
    }

    // Checkin display with time + late marker
    $cinDisplay = '-';
    if ($cin) {
        $cinTime    = substr($cin, 11, 5);
        $cinColor   = $isLate ? '#fb923c' : '#4ade80';
        $cinDisplay = '<span style="font-weight:600;color:' . $cinColor . '">' . htmlspecialchars($cinTime, ENT_QUOTES) . '</span>'
                    . '<div style="font-size:10px;color:#4b5563">' . htmlspecialchars(substr($cin, 0, 10), ENT_QUOTES) . '</div>';
    }
    $coutDisplay = '-';
    if ($cout) {
        $coutDisplay = '<span style="font-weight:600;color:#60a5fa">' . htmlspecialchars($coutTime, ENT_QUOTES) . '</span>';
        if ($duration) $coutDisplay .= '<div style="font-size:10px;color:#4b5563">' . htmlspecialchars($duration, ENT_QUOTES) . '</div>';
    }

    // Shift display
    $shiftDisplay = '<span style="font-weight:600;color:#94a3b8">' . htmlspecialchars($shiftLabel, ENT_QUOTES) . '</span>';
    if ($shiftTimes) {
        $shiftDisplay .= '<div style="color:#64748b;font-size:10px">' . htmlspecialchars($shiftTimes, ENT_QUOTES) . '</div>';
    }

    // Lembur display
    $lemburDisplay = $overtimeMin > 0
        ? '<span style="background:rgba(168,85,247,.15);color:#c084fc;border:1px solid rgba(168,85,247,.3);padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700">+'
          . htmlspecialchars($overtimeStr, ENT_QUOTES) . '</span>'
        : '<span style="color:#475569;font-size:11px">—</span>';

    $lateMinDisplay = $lateMin > 0
        ? '<span style="color:#fb923c;font-weight:700">' . htmlspecialchars((string)$lateMin, ENT_QUOTES) . ' menit</span>'
        : '<span style="color:#475569;font-size:11px">—</span>';

    $lateAmtDisplay = $lateAmt > 0
        ? '<span style="color:#f87171;font-weight:700">' . htmlspecialchars(dt_money($lateAmt), ENT_QUOTES) . '</span>'
        : '<span style="color:#475569;font-size:11px">—</span>';

    // Dept+Office badge
    $doBadge = '';
    if ($offCode) $doBadge .= '<span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:5px;background:rgba(99,102,241,.15);color:#c7d2fe;border:1px solid rgba(99,102,241,.2)">' . htmlspecialchars($offCode, ENT_QUOTES) . '</span>';
    if ($empDept) $doBadge .= ' <span style="font-size:10px;color:#64748b">' . htmlspecialchars($empDept, ENT_QUOTES) . '</span>';

    $data[] = [
        htmlspecialchars($tanggal, ENT_QUOTES),
        '<span style="font-weight:600">' . htmlspecialchars($uname, ENT_QUOTES) . '</span>'
            . '<div style="font-size:10px;color:#4b5563">#' . $uid . '</div>',
        $empName !== '' ? '<span style="font-weight:600">' . htmlspecialchars($empName, ENT_QUOTES) . '</span>' : '<span style="color:#374151;font-size:11px">—</span>',
        $doBadge,
        $shiftDisplay,
        $cinDisplay,
        $statusHtml,
        $lateMinDisplay,
        $lateAmtDisplay,
        $lemburDisplay,
        rekap_photo_html($photoIn),
        $coutDisplay,
        rekap_photo_html($photoOut),
    ];
}

// Adjust recordsFiltered for dept filter
if ($filterDept !== '') {
    $recordsFiltered = count($data) + ($filteredOut > 0 ? 0 : 0);
    // More accurate: total matching rows minus filtered-out (approx)
    $recordsFiltered = max(count($data), $recordsTotal - $filteredOut);
}

rmi_json([
    'draw'            => $draw,
    'recordsTotal'    => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data'            => $data,
    'meta'            => [
        'std_time'   => $stdTime,
        'filter_office' => $filterOffice,
        'filter_dept'   => $filterDept,
        'late_penalty_rules' => $latePenaltyRules,
    ],
], 200);
