<?php
declare(strict_types=1);

require_once __DIR__ . "/../_inc/bootstrap.php";
require_once __DIR__ . "/../_inc/shift_helper.php";

// Static scan: explicit auth/RBAC guard in this file
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.RECAP');

require_once __DIR__ . '/../_layout_top.php';


if (!absensi_is_hr_admin($pdo, $ABS_USER)) {
  echo "Akses ditolak.";
  require_once __DIR__ . '/../_layout_bottom.php';
  exit;
}

// =========================================================
// Payroll Gate (Absensi)
// =========================================================
// Tujuan:
// - Rekap "hari yang boleh dibayar" (allowance/operasional harian)
//   berdasarkan bukti Absensi (check-in) dan/atau request DINAS yang APPROVED.
// - Request IZIN/SAKIT ditampilkan untuk audit, namun default TIDAK dihitung payable.
//
// Payable rule (default):
//   PAYABLE = (PRESENT & within geofence) OR (DINAS approved)
//
// Catatan:
// - Modul ini tidak mengubah payroll. Ini hanya data gate + export CSV.
// - Bisa digunakan FIN untuk hitung allowance by day.

// ---------- helpers
function absensi_csv_row(array $cols): string {
  $out = [];
  foreach ($cols as $v) {
    if ($v === null) { $v = ''; }
    $v = (string)$v;
    // Excel-friendly: keep as text if starts with =,+,-,@
    if ($v !== '' && preg_match('/^[=\+\-@]/', $v)) {
      $v = "'" . $v;
    }
    $v = str_replace('"', '""', $v);
    $out[] = '"' . $v . '"';
  }
  return implode(',', $out) . "\n";
}


function absensi_office_radius_map(PDO $pdo): array {
  // Returns: office_code => radius_m
  $map = [];

  // Preferred: master_office (ERP)
  if (absensi_table_exists($pdo, 'master_office')) {
    try {
      $stmt = $pdo->query("SELECT office_code, office_radius_m FROM master_office");
      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $code = (string)($r['office_code'] ?? '');
        if ($code === '') continue;
        $map[$code] = isset($r['office_radius_m']) ? (int)$r['office_radius_m'] : null;
      }
      if (!empty($map)) return $map;
    } catch (Throwable $e) {
      // ignore
    }
  }

  // Fallback: absensi_offices (legacy)
  if (absensi_table_exists($pdo, 'absensi_offices')) {
    try {
      $stmt = $pdo->query("SELECT office_code, radius_m FROM absensi_offices");
      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $code = (string)($r['office_code'] ?? '');
        if ($code === '') continue;
        $map[$code] = isset($r['radius_m']) ? (int)$r['radius_m'] : null;
      }
    } catch (Throwable $e) {
      // ignore
    }
  }
  return $map;
}

function payroll_gate_late_penalty_rules(PDO $pdo): array {
  $defaults = [
    ['min'=>1,  'max'=>19,   'amount'=>5000.0],
    ['min'=>20, 'max'=>29,   'amount'=>10000.0],
    ['min'=>30, 'max'=>9999, 'amount'=>20000.0],
  ];
  if (!absensi_table_exists($pdo, 'absensi_late_penalty_rules')) return $defaults;
  try {
    $rows = $pdo->query("SELECT minute_from AS min, minute_to AS max, penalty_amount AS amount
                         FROM absensi_late_penalty_rules WHERE is_active=1 ORDER BY minute_from ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return $defaults;
    $rules = array_map(fn($r) => [
      'min'=>(int)($r['min'] ?? 0), 'max'=>(int)($r['max'] ?? 9999), 'amount'=>(float)($r['amount'] ?? 0)
    ], $rows);
    // Samakan dengan Rekap: setelah toleransi, keterlambatan efektif >=1 menit kena tier pertama.
    if (!empty($rules) && (int)$rules[0]['min'] > 1 && (float)$rules[0]['amount'] > 0) {
      $rules[0]['min'] = 1;
    }
    return $rules;
  } catch (Throwable $e) { return $defaults; }
}

function payroll_gate_late_penalty_amount(int $lateMin, array $rules): float {
  if ($lateMin <= 0) return 0.0;
  foreach ($rules as $r) {
    if ($lateMin >= (int)$r['min'] && $lateMin <= (int)$r['max']) return (float)$r['amount'];
  }
  return 0.0;
}

function payroll_gate_effective_global_start(PDO $pdo): string {
  $std = (string)(absensi_setting($pdo, 'checkin_std_time', '07:00') ?? '07:00');
  $tol = max(0, (int)(absensi_setting($pdo, 'late_tolerance_min', '5') ?? '5'));
  $ts = strtotime('2000-01-01 ' . $std . ':00');
  return ($ts !== false && $tol > 0) ? date('H:i', $ts + ($tol * 60)) : $std;
}

function payroll_gate_late_minutes_fallback(string $cinTime, string $effectiveStart): int {
  if ($cinTime === '' || $effectiveStart === '') return 0;
  $a = strtotime('2000-01-01 ' . (strlen($cinTime) === 5 ? $cinTime . ':00' : $cinTime));
  $b = strtotime('2000-01-01 ' . (strlen($effectiveStart) === 5 ? $effectiveStart . ':00' : $effectiveStart));
  if ($a === false || $b === false || $a <= $b) return 0;
  return (int)ceil(($a - $b) / 60);
}

function absensi_user_meta_map(PDO $pdo): array {
  // username => [dept, role, office_code]
  $meta = [];

  if (absensi_table_exists($pdo, 'master_system_login')) {
    try {
      // Columns may vary; select only if exist.
      $cols = [];
      $colCheck = $pdo->query("SHOW COLUMNS FROM master_system_login")->fetchAll(PDO::FETCH_COLUMN);
      $want = ['username','department','role','office_code','status'];
      foreach ($want as $w) if (in_array($w, $colCheck, true)) $cols[] = $w;
      if (in_array('username', $cols, true)) {
        $sql = 'SELECT ' . implode(',', $cols) . ' FROM master_system_login';
        if (in_array('status', $cols, true)) {
          $sql .= " WHERE status='active' OR status='ACTIVE'";
        }
        $stmt = $pdo->query($sql);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
          $u = (string)($r['username'] ?? '');
          if ($u === '') continue;
          $meta[$u] = [
            'dept' => (string)($r['department'] ?? ''),
            'role' => (string)($r['role'] ?? ''),
            'office_code' => (string)($r['office_code'] ?? ''),
          ];
        }
        if (!empty($meta)) return $meta;
      }
    } catch (Throwable $e) {
      // ignore
    }
  }

  return $meta;
}

// ---------- inputs
$from = isset($_GET['from']) ? (string)$_GET['from'] : date('Y-m-01');
$to   = isset($_GET['to'])   ? (string)$_GET['to']   : date('Y-m-d');

$export = isset($_GET['export']) ? (string)$_GET['export'] : '';

$radiusMap = absensi_office_radius_map($pdo);
$metaMap   = absensi_user_meta_map($pdo);
$latePenaltyRules = payroll_gate_late_penalty_rules($pdo);
$globalEffectiveStart = payroll_gate_effective_global_start($pdo);

// ---------- load logs (grouped per user+date)
$fromDT = $from . ' 00:00:00';
$toDT   = $to   . ' 23:59:59';

$sqlLogs = "
  SELECT
    DATE(created_at) AS tanggal,
    user_id,
    username,
    MAX(CASE WHEN action_type='IN' THEN created_at END)  AS checkin,
    MAX(CASE WHEN action_type='OUT' THEN created_at END) AS checkout,
    MAX(office_code) AS office_code,
    MAX(CASE WHEN action_type='IN' THEN distance_m END)  AS in_distance_m
  FROM absensi_logs
  WHERE deleted_at IS NULL
    AND created_at BETWEEN ? AND ?
  GROUP BY DATE(created_at), user_id, username
  ORDER BY tanggal ASC
";

$stmt = $pdo->prepare($sqlLogs);
$stmt->execute([$fromDT, $toDT]);

$logsByUserDate = []; // userKey => [date => row]
$userInfo = [];       // userKey => ['user_id'=>..,'username'=>..]
$officeFreq = [];     // userKey => [office_code => count]

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $uid = $r['user_id'] ?? null;
  $uname = (string)($r['username'] ?? '');
  $userKey = ($uid !== null && $uid !== '') ? 'id:' . (string)$uid : 'u:' . $uname;
  $tgl = (string)$r['tanggal'];
  if ($tgl === '') continue;

  $office = (string)($r['office_code'] ?? '');
  if ($office === '') $office = 'DEFAULT';

  $dist = $r['in_distance_m'];
  $dist = ($dist === null || $dist === '') ? null : (int)$dist;
  $radius = array_key_exists($office, $radiusMap) ? $radiusMap[$office] : null;
  $radius = ($radius === null || $radius === '') ? null : (int)$radius;
  $within = true;
  if ($radius !== null && $dist !== null) {
    $within = ($dist <= $radius);
  }

  $cinTime = !empty($r['checkin']) ? substr((string)$r['checkin'], 11, 5) : '';
  $resolvedShift = absensi_resolve_shift($pdo, $uname, $tgl);
  $lateMin = $resolvedShift
    ? absensi_shift_late_minutes($cinTime, $resolvedShift)
    : payroll_gate_late_minutes_fallback($cinTime, $globalEffectiveStart);
  $latePenalty = payroll_gate_late_penalty_amount($lateMin, $latePenaltyRules);

  $logsByUserDate[$userKey][$tgl] = [
    'tanggal' => $tgl,
    'user_id' => ($uid === null || $uid === '') ? null : (int)$uid,
    'username' => $uname,
    'office_code' => $office,
    'checkin' => $r['checkin'],
    'checkout' => $r['checkout'],
    'in_distance_m' => $dist,
    'radius_m' => $radius,
    'within_geofence' => $within,
    'late_minutes' => $lateMin,
    'late_penalty' => $latePenalty,
    'shift_name' => $resolvedShift['shift_name'] ?? '',
    'effective_checkin' => $resolvedShift ? absensi_shift_effective_checkin_time($resolvedShift) : $globalEffectiveStart,
  ];

  $userInfo[$userKey] = [
    'user_id' => ($uid === null || $uid === '') ? null : (int)$uid,
    'username' => $uname,
  ];

  if (!isset($officeFreq[$userKey])) $officeFreq[$userKey] = [];
  if (!isset($officeFreq[$userKey][$office])) $officeFreq[$userKey][$office] = 0;
  $officeFreq[$userKey][$office]++;
}

// ---------- load approved requests (IZIN/SAKIT/DINAS)
$requestsByUserDate = []; // userKey => [date => request]

if (absensi_table_exists($pdo, 'absensi_requests')) {
  $sqlReq = "
    SELECT id, user_id, username, req_type, start_date, end_date, reason, photo_path, status
    FROM absensi_requests
    WHERE deleted_at IS NULL
      AND status='APPROVED'
      AND NOT (end_date < ? OR start_date > ?)
    ORDER BY start_date ASC
  ";
  $st = $pdo->prepare($sqlReq);
  $st->execute([$from, $to]);

  $prio = ['IZIN' => 1, 'SAKIT' => 2, 'DINAS' => 3];

  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $uid = $r['user_id'] ?? null;
    $uname = (string)($r['username'] ?? '');
    $userKey = ($uid !== null && $uid !== '') ? 'id:' . (string)$uid : 'u:' . $uname;

    $t = strtoupper((string)($r['req_type'] ?? ''));
    if (!isset($prio[$t])) continue;

    $start = (string)($r['start_date'] ?? '');
    $end   = (string)($r['end_date'] ?? '');
    if ($start === '' || $end === '') continue;

    // Clamp to selected range
    $s = ($start < $from) ? $from : $start;
    $e = ($end > $to) ? $to : $end;

    try {
      $d0 = new DateTimeImmutable($s);
      $d1 = new DateTimeImmutable($e);
    } catch (Throwable $e) {
      continue;
    }

    for ($d = $d0; $d <= $d1; $d = $d->modify('+1 day')) {
      $tgl = $d->format('Y-m-d');
      $new = [
        'req_id' => (int)$r['id'],
        'user_id' => ($uid === null || $uid === '') ? null : (int)$uid,
        'username' => $uname,
        'req_type' => $t,
        'reason' => (string)($r['reason'] ?? ''),
        'photo_path' => (string)($r['photo_path'] ?? ''),
      ];

      if (!isset($requestsByUserDate[$userKey][$tgl])) {
        $requestsByUserDate[$userKey][$tgl] = $new;
      } else {
        $oldType = (string)($requestsByUserDate[$userKey][$tgl]['req_type'] ?? '');
        if (($prio[$t] ?? 0) >= ($prio[$oldType] ?? 0)) {
          $requestsByUserDate[$userKey][$tgl] = $new;
        }
      }
    }

    $userInfo[$userKey] = [
      'user_id' => ($uid === null || $uid === '') ? null : (int)$uid,
      'username' => $uname,
    ];
  }
}

// ---------- build summary
$allKeys = array_unique(array_merge(array_keys($logsByUserDate), array_keys($requestsByUserDate), array_keys($userInfo)));
sort($allKeys);

$summary = []; // rows

foreach ($allKeys as $uk) {
  $uname = (string)($userInfo[$uk]['username'] ?? '');
  $uid   = $userInfo[$uk]['user_id'] ?? null;

  $meta = $uname !== '' && isset($metaMap[$uname]) ? $metaMap[$uname] : ['dept'=>'','role'=>'','office_code'=>''];

  $presentDates = [];
  $presentPayableDates = [];
  $lateDays = 0;
  $lateMinutesTotal = 0;
  $latePenaltyTotal = 0.0;
  if (isset($logsByUserDate[$uk])) {
    foreach ($logsByUserDate[$uk] as $tgl => $row) {
      $presentDates[$tgl] = true;
      $lm = (int)($row['late_minutes'] ?? 0);
      if ($lm > 0) {
        $lateDays++;
        $lateMinutesTotal += $lm;
        $latePenaltyTotal += (float)($row['late_penalty'] ?? 0);
      }
      if (!empty($row['within_geofence'])) {
        $presentPayableDates[$tgl] = true;
      }
    }
  }

  $dinasDates = [];
  $leaveDates = [];
  if (isset($requestsByUserDate[$uk])) {
    foreach ($requestsByUserDate[$uk] as $tgl => $req) {
      $t = (string)($req['req_type'] ?? '');
      if ($t === 'DINAS') {
        $dinasDates[$tgl] = true;
      } elseif ($t === 'IZIN' || $t === 'SAKIT') {
        $leaveDates[$tgl] = true;
      }
    }
  }

  // Payable = presentPayable OR dinasApproved
  $payableDates = $presentPayableDates;
  foreach ($dinasDates as $tgl => $_) {
    $payableDates[$tgl] = true;
  }

  // Office display: prefer ERP meta, else most frequent in logs
  $officeDisp = trim((string)($meta['office_code'] ?? ''));
  if ($officeDisp === '' && isset($officeFreq[$uk]) && !empty($officeFreq[$uk])) {
    arsort($officeFreq[$uk]);
    $officeDisp = (string)array_key_first($officeFreq[$uk]);
  }
  if ($officeDisp === '') $officeDisp = 'DEFAULT';

  $summary[] = [
    'user_id' => $uid,
    'username' => $uname,
    'dept' => (string)($meta['dept'] ?? ''),
    'role' => (string)($meta['role'] ?? ''),
    'office_code' => $officeDisp,
    'present_days' => count($presentDates),
    'dinas_days' => count($dinasDates),
    'leave_days' => count($leaveDates),
    'payable_days' => count($payableDates),
    'late_days' => $lateDays,
    'late_minutes' => $lateMinutesTotal,
    'late_penalty' => $latePenaltyTotal,
  ];
}

// ---------- export handlers
if ($export === 'summary_csv' || $export === 'detail_csv') {
  $fname = 'absensi_payroll_gate_' . $export . '_' . $from . '_to_' . $to . '.csv';

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=' . $fname);

  if ($export === 'summary_csv') {
    echo absensi_csv_row(['username','dept','role','office_code','present_days','dinas_days','leave_days','payable_days','late_days','late_minutes','late_penalty','range_from','range_to']);
    foreach ($summary as $row) {
      echo absensi_csv_row([
        $row['username'],
        $row['dept'],
        $row['role'],
        $row['office_code'],
        (string)$row['present_days'],
        (string)$row['dinas_days'],
        (string)$row['leave_days'],
        (string)$row['payable_days'],
        (string)$row['late_days'],
        (string)$row['late_minutes'],
        (string)$row['late_penalty'],
        $from,
        $to,
      ]);
    }
    absensi_audit($pdo, $ABS_USER, 'ABSENSI_PAYROLL_GATE_EXPORT_SUMMARY', ['from'=>$from,'to'=>$to,'rows'=>count($summary)]);
    exit;
  }

  // detail_csv
  echo absensi_csv_row([
    'date','username','dept','role','office_code','checkin','checkout','shift','effective_checkin','late_minutes','late_penalty','req_type','status','within_geofence','distance_m','radius_m','payable_allowance','note'
  ]);

  foreach ($allKeys as $uk) {
    $uname = (string)($userInfo[$uk]['username'] ?? '');
    if ($uname === '') continue;
    $meta = isset($metaMap[$uname]) ? $metaMap[$uname] : ['dept'=>'','role'=>'','office_code'=>''];

    $dates = [];
    if (isset($logsByUserDate[$uk])) {
      foreach ($logsByUserDate[$uk] as $tgl => $_) $dates[$tgl] = true;
    }
    if (isset($requestsByUserDate[$uk])) {
      foreach ($requestsByUserDate[$uk] as $tgl => $_) $dates[$tgl] = true;
    }
    ksort($dates);

    foreach (array_keys($dates) as $tgl) {
      $log = $logsByUserDate[$uk][$tgl] ?? null;
      $req = $requestsByUserDate[$uk][$tgl] ?? null;

      $office = $log['office_code'] ?? (string)($meta['office_code'] ?? '');
      $office = $office ?: 'DEFAULT';

      $within = $log ? (bool)$log['within_geofence'] : null;
      $dist   = $log['in_distance_m'] ?? null;
      $radius = $log['radius_m'] ?? (array_key_exists($office, $radiusMap) ? $radiusMap[$office] : null);

      $reqType = $req['req_type'] ?? '';

      $status = 'NO_PROOF';
      $payable = 0;
      $note = '';

      if ($log) {
        if ($within === false) {
          $status = 'OUTSIDE_GEOFENCE';
          $payable = 0;
        } else {
          $status = 'PRESENT';
          $payable = 1;
        }
      }

      if (!$log && $reqType === 'DINAS') {
        $status = 'DINAS_APPROVED';
        $payable = 1;
      } elseif (!$log && ($reqType === 'IZIN' || $reqType === 'SAKIT')) {
        $status = $reqType . '_APPROVED';
        $payable = 0;
      }

      if ($req) {
        $note = trim((string)($req['reason'] ?? ''));
      }

      echo absensi_csv_row([
        $tgl,
        $uname,
        (string)($meta['dept'] ?? ''),
        (string)($meta['role'] ?? ''),
        $office,
        $log['checkin'] ?? '',
        $log['checkout'] ?? '',
        $log['shift_name'] ?? '',
        $log['effective_checkin'] ?? '',
        (string)($log['late_minutes'] ?? 0),
        (string)($log['late_penalty'] ?? 0),
        $reqType,
        $status,
        ($within === null ? '' : ($within ? '1' : '0')),
        ($dist === null ? '' : (string)$dist),
        ($radius === null ? '' : (string)$radius),
        (string)$payable,
        $note,
      ]);
    }
  }

  absensi_audit($pdo, $ABS_USER, 'ABSENSI_PAYROLL_GATE_EXPORT_DETAIL', ['from'=>$from,'to'=>$to,'users'=>count($allKeys)]);
  exit;
}

// ---------- UI
?>

<h2>Payroll Gate (Absensi)</h2>

<div class="muted" style="margin-bottom:8px;">
  <div><b>Rule (default):</b> Payable allowance = <b>PRESENT (check-in)</b> + within geofence, atau <b>DINAS</b> (APPROVED).</div>
  <div>IZIN/SAKIT (APPROVED) tetap ditampilkan untuk audit, namun <b>tidak</b> dihitung payable allowance by day.</div>
  <div>Kolom telat memakai <b>shift + toleransi</b> yang sama dengan Rekap. Nilai ini adalah sumber audit potongan telat untuk payroll, bukan posting payroll otomatis.</div>
</div>

<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
  <a class="btn" href="rekap.php">← Rekap</a>
  <a class="btn" href="approval.php">Approval</a>
  <a class="btn" href="offices.php">Office</a>
  <a class="btn" href="users.php">Users</a>
</div>

<form method="get" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:12px;">
  <div>
    <div class="muted">Dari</div>
    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" required>
  </div>
  <div>
    <div class="muted">Sampai</div>
    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" required>
  </div>
  <button class="btn" type="submit">Filter</button>

  <a class="btn" href="payroll_gate.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&export=summary_csv">Export Summary CSV</a>
  <a class="btn" href="payroll_gate.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&export=detail_csv">Export Detail CSV</a>
</form>

<div style="max-width:1100px;">
  <table id="tblPayroll" class="table">
    <thead>
      <tr>
        <th>Username</th>
        <th>Dept</th>
        <th>Role</th>
        <th>Office</th>
        <th>Present</th>
        <th>Dinas</th>
        <th>Leave</th>
        <th>Payable</th>
        <th>Hari Telat</th>
        <th>Menit Telat</th>
        <th>Potongan Telat</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($summary as $row): ?>
        <tr>
          <td><?= htmlspecialchars((string)$row['username']) ?></td>
          <td><?= htmlspecialchars((string)$row['dept']) ?></td>
          <td><?= htmlspecialchars((string)$row['role']) ?></td>
          <td><?= htmlspecialchars((string)$row['office_code']) ?></td>
          <td><?= (int)$row['present_days'] ?></td>
          <td><?= (int)$row['dinas_days'] ?></td>
          <td><?= (int)$row['leave_days'] ?></td>
          <td><b><?= (int)$row['payable_days'] ?></b></td>
          <td><?= (int)$row['late_days'] ?></td>
          <td><?= (int)$row['late_minutes'] ?></td>
          <td><b>Rp <?= number_format((float)$row['late_penalty'], 0, ',', '.') ?></b></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
// Optional DataTables (client-side) for summary table
(() => {
  const $ = window.jQuery;
  if (!$ || !$.fn || !$.fn.DataTable) return;
  $('#tblPayroll').DataTable({
    pageLength: 25,
    order: [[7,'desc']],
  });
})();
</script>

<?php require_once __DIR__ . '/../_layout_bottom.php'; ?>
