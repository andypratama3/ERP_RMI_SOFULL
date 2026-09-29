<?php
$title="Admin HR • User Settings";
require_once __DIR__ . "/../_inc/bootstrap.php";
require_once __DIR__ . "/../../master/_audit_master.php";

// Static scan: explicit auth/RBAC guard in this file
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.ADMIN_USERS');

if (!absensi_is_hr_admin($pdo, $ABS_USER)) { http_response_code(403); die('Akses ditolak'); }

// POST: process & redirect BEFORE any output (layout sends headers/HTML)
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify_or_die();
  $rq = trim((string)($_POST['redirect_query'] ?? ''));

  // Batch save — semua user_ids dikirim sekaligus
  $user_ids = array_map('intval', (array)($_POST['user_ids'] ?? []));
  $saved = 0; $skipped_protected = 0;

  // Cache: ambil role/level semua uid sekaligus untuk cek proteksi
  $protected_uids = [];
  if ($user_ids) {
    $in = implode(',', array_fill(0, count($user_ids), '?'));
    $stProt = $pdo->prepare("SELECT id, role, level, username FROM master_system_login WHERE id IN ($in)");
    $stProt->execute($user_ids);
    foreach ($stProt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $r = strtoupper(trim((string)($row['role'] ?? '')));
      $l = strtoupper(trim((string)($row['level'] ?? '')));
      $u = strtolower(trim((string)($row['username'] ?? '')));
      // Proteksi: ADMIN/SUPERADMIN/SYS & username reserved tidak boleh dicabut HR admin
      if (in_array($r, ['SYS','ADMIN','SUPERADMIN'], true)
       || in_array($l, ['SYS','ADMIN','SUPERADMIN'], true)
       || in_array($u, ['admin','superadmin'], true)) {
        $protected_uids[] = (int)$row['id'];
      }
    }
  }

  foreach ($user_ids as $uid) {
    if ($uid <= 0) continue;
    // Skip akun protected — is_hr_admin mereka tidak boleh diubah
    if (in_array($uid, $protected_uids, true)) {
      $skipped_protected++;
      continue;
    }
    $office = strtoupper(trim((string)($_POST['office_code_' . $uid] ?? '')));
    $office = $office ? absensi_office_code_normalize($office) : '';
    $isHr   = isset($_POST['is_hr_admin_' . $uid]) ? 1 : 0;
    $stmt = $pdo->prepare("INSERT INTO absensi_user_profile (user_id, office_code, is_hr_admin, updated_at)
      VALUES (?,?,?,NOW())
      ON DUPLICATE KEY UPDATE office_code=VALUES(office_code), is_hr_admin=VALUES(is_hr_admin), updated_at=NOW()");
    $stmt->execute([$uid, ($office===''?null:$office), $isHr]);
    absensi_audit($pdo, $ABS_USER, 'USER_PROFILE_SAVE', ['user_id'=>$uid,'office_code'=>$office,'is_hr_admin'=>$isHr]);
    if (function_exists('master_audit')) {
      master_audit($pdo, 'absensi_admin', 'absensi_user_profile', 'USER_PROFILE_SAVE', $uid, 'USER#'.$uid, "Absensi user profile saved: user_id {$uid}", ['office_code'=>$office,'is_hr_admin'=>$isHr]);
    }
    $saved++;
  }
  $msg = "Tersimpan ({$saved} user).";
  if ($skipped_protected > 0) $msg .= " {$skipped_protected} akun SYS/ADMIN dilewati (terlindungi).";
  if ($saved > 0 || $skipped_protected > 0) absensi_flash_set('ok', $msg);
  rmi_redirect('users.php' . ($rq ? '?' . $rq : ''));
}

require_once __DIR__ . "/../_layout_top.php";

// Filter params (GET)
$filter_q = trim((string)($_GET['q'] ?? ''));
$filter_role = trim((string)($_GET['role'] ?? ''));
$filter_dept = trim((string)($_GET['dept'] ?? ''));
$filter_office = trim((string)($_GET['office'] ?? ''));
$filter_hr = $_GET['hr_admin'] ?? '';

// Period filter untuk rekap (default: bulan ini)
$raw_month = trim((string)($_GET['month'] ?? ''));
$filter_month = preg_match('/^\d{4}-\d{2}$/', $raw_month) ? $raw_month : date('Y-m');
$period_from = $filter_month . '-01 00:00:00';
$period_to   = date('Y-m-t', strtotime($filter_month . '-01')) . ' 23:59:59';

// Filter tambahan: status setup holder
$filter_setup = trim((string)($_GET['setup'] ?? ''));

// Users: prefer master_system_login (with filter)
$users = [];
$params = [];
$where = ["m.deleted_at IS NULL"];
try {
  $sql = "SELECT m.id, m.username, COALESCE(m.full_name,'') AS full_name,
                 COALESCE(m.role,'') AS role, COALESCE(m.department,'') AS department,
                 COALESCE(m.office_code,'') AS office_code,
                 COALESCE(m.holder_employee_code,'') AS holder_employee_code,
                 COALESCE(e.employee_name,'') AS employee_name
          FROM master_system_login m
          LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code
          LEFT JOIN absensi_user_profile p ON p.user_id = m.id";
  if ($filter_q !== '') {
    $where[] = "(m.username LIKE :q OR COALESCE(m.full_name,'') LIKE :q OR COALESCE(e.employee_name,'') LIKE :q)";
    $params[':q'] = '%' . $filter_q . '%';
  }
  if ($filter_role !== '') {
    $where[] = "UPPER(TRIM(COALESCE(m.role,''))) = :role";
    $params[':role'] = strtoupper($filter_role);
  }
  if ($filter_dept !== '') {
    $where[] = "UPPER(TRIM(COALESCE(m.department,''))) = :dept";
    $params[':dept'] = strtoupper($filter_dept);
  }
  if ($filter_office !== '') {
    $where[] = "UPPER(TRIM(COALESCE(m.office_code,''))) = :office";
    $params[':office'] = strtoupper($filter_office);
  }
  if ($filter_hr === '1') {
    $where[] = "p.is_hr_admin = 1";
  } elseif ($filter_hr === '0') {
    $where[] = "(p.is_hr_admin IS NULL OR p.is_hr_admin = 0)";
  }
  if ($filter_setup === 'sudah') {
    $where[] = "(m.holder_employee_code IS NOT NULL AND m.holder_employee_code != '')";
  } elseif ($filter_setup === 'belum') {
    $where[] = "(m.holder_employee_code IS NULL OR m.holder_employee_code = '')";
  }
  $sql .= " WHERE " . implode(" AND ", $where) . " ORDER BY m.username ASC LIMIT 500";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // master_system_login tidak tersedia
}

// Hitung total sudah/belum setup (dari semua user, bukan filtered)
$total_setup_sudah = 0;
$total_setup_belum = 0;
try {
  $total_setup_sudah = (int)$pdo->query("SELECT COUNT(*) FROM master_system_login WHERE deleted_at IS NULL AND holder_employee_code IS NOT NULL AND holder_employee_code != ''")->fetchColumn();
  $total_all = (int)$pdo->query("SELECT COUNT(*) FROM master_system_login WHERE deleted_at IS NULL")->fetchColumn();
  $total_setup_belum = $total_all - $total_setup_sudah;
} catch (Throwable $e) {}

$profiles = $pdo->query("SELECT * FROM absensi_user_profile")->fetchAll(PDO::FETCH_ASSOC);
$map = [];
foreach ($profiles as $p) $map[(int)$p['user_id']] = $p;

// Offices: from master_office (preferred)
$offices = absensi_master_office_list($pdo, true);
if (!$offices) {
  try{
    $offices = $pdo->query("SELECT office_code, office_name, lat, lng, radius_m, is_active FROM absensi_offices WHERE is_active=1 ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $offices = []; }
}

// Absensi stats per user untuk periode terpilih
$abs_stats = [];
try {
  $sql_abs = "
    SELECT
      user_id,
      COUNT(DISTINCT CASE WHEN action_type='IN' THEN DATE(created_at) END) AS hari_hadir,
      MAX(CASE WHEN action_type='IN' THEN created_at END) AS last_checkin,
      MAX(CASE WHEN action_type='OUT' THEN created_at END) AS last_checkout,
      MAX(CASE WHEN action_type='IN' AND DATE(created_at)=CURDATE() THEN created_at END) AS today_in,
      MAX(CASE WHEN action_type='OUT' AND DATE(created_at)=CURDATE() THEN created_at END) AS today_out
    FROM absensi_logs
    WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?
    GROUP BY user_id
  ";
  $st = $pdo->prepare($sql_abs);
  $st->execute([$period_from, $period_to]);
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $abs_stats[(int)$r['user_id']] = $r;
  }
} catch (Throwable $e) { $abs_stats = []; }

// Izin/Sakit/Dinas per user di periode terpilih
$izin_stats = [];
try {
  $sql_izin = "
    SELECT user_id,
      COUNT(CASE WHEN status='PENDING'  THEN 1 END) AS pending,
      COUNT(CASE WHEN status='APPROVED' THEN 1 END) AS approved
    FROM absensi_requests
    WHERE deleted_at IS NULL AND start_date <= ? AND end_date >= ?
    GROUP BY user_id
  ";
  $si = $pdo->prepare($sql_izin);
  $si->execute([
    date('Y-m-t', strtotime($filter_month . '-01')),
    $filter_month . '-01'
  ]);
  while ($r = $si->fetch(PDO::FETCH_ASSOC)) {
    $izin_stats[(int)$r['user_id']] = $r;
  }
} catch (Throwable $e) { $izin_stats = []; }

// Options untuk filter (role, dept, office)
$filter_roles = [];
$filter_depts = [];
$filter_offices = [];
try {
  $filter_roles = $pdo->query("SELECT DISTINCT UPPER(TRIM(COALESCE(role,''))) AS v FROM master_system_login WHERE role IS NOT NULL AND TRIM(role)!='' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
  $filter_depts = $pdo->query("SELECT DISTINCT UPPER(TRIM(COALESCE(department,''))) AS v FROM master_system_login WHERE department IS NOT NULL AND TRIM(department)!='' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
  $filter_offices = $pdo->query("SELECT DISTINCT UPPER(TRIM(COALESCE(office_code,''))) AS v FROM master_system_login WHERE office_code IS NOT NULL AND TRIM(office_code)!='' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { /* ignore */ }

$redirect_query = htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES, 'UTF-8');
$base_url_msl = '../../master/master_system_login.php';
?>
<div class="grid">
  <div class="card col-12">
    <div class="h1">User Settings</div>

    <!-- Summary counters -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 14px 0">
      <a href="users.php?setup=sudah" style="text-decoration:none">
        <span style="background:#16a34a;color:#fff;padding:4px 14px;border-radius:16px;font-size:13px;font-weight:600;cursor:pointer">
          <?=rmi_icon('tick')?> Sudah Setup: <?= $total_setup_sudah ?>
        </span>
      </a>
      <a href="users.php?setup=belum" style="text-decoration:none">
        <span style="background:#dc2626;color:#fff;padding:4px 14px;border-radius:16px;font-size:13px;font-weight:600;cursor:pointer">
          <?=rmi_icon('x')?> Belum Setup: <?= $total_setup_belum ?>
        </span>
      </a>
      <span style="color:#888;font-size:12px;align-self:center">
        Klik badge untuk filter · <a href="<?= htmlspecialchars($base_url_msl, ENT_QUOTES, 'UTF-8') ?>" style="color:#60a5fa">Set Holder Employee →</a>
      </span>
    </div>

    <!-- Filter form -->
    <form method="get" style="margin-bottom:14px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
      <div>
        <label class="small muted" style="display:block;margin-bottom:3px">Cari</label>
        <input type="text" name="q" placeholder="Username / Nama / Employee" value="<?= htmlspecialchars($filter_q, ENT_QUOTES, 'UTF-8') ?>" style="min-width:160px;padding:5px 9px;font-size:13px">
      </div>
      <div>
        <label class="small muted" style="display:block;margin-bottom:3px">Role</label>
        <select name="role" style="padding:5px 9px;font-size:13px">
          <option value="">Semua</option>
          <?php foreach ($filter_roles as $r): ?>
            <option value="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_role === $r ? 'selected' : '' ?>><?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="small muted" style="display:block;margin-bottom:3px">Dept</label>
        <select name="dept" style="padding:5px 9px;font-size:13px">
          <option value="">Semua</option>
          <?php foreach ($filter_depts as $d): ?>
            <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_dept === $d ? 'selected' : '' ?>><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="small muted" style="display:block;margin-bottom:3px">Office</label>
        <select name="office" style="padding:5px 9px;font-size:13px">
          <option value="">Semua</option>
          <?php foreach ($filter_offices as $o): ?>
            <option value="<?= htmlspecialchars($o, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_office === $o ? 'selected' : '' ?>><?= htmlspecialchars($o, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="small muted" style="display:block;margin-bottom:3px">HR Admin</label>
        <select name="hr_admin" style="padding:5px 9px;font-size:13px">
          <option value="" <?= $filter_hr==='' ? 'selected':'' ?>>Semua</option>
          <option value="1" <?= $filter_hr==='1' ? 'selected':'' ?>>Ya</option>
          <option value="0" <?= $filter_hr==='0' ? 'selected':'' ?>>Tidak</option>
        </select>
      </div>
      <div>
        <label class="small muted" style="display:block;margin-bottom:3px">Status Setup</label>
        <select name="setup" style="padding:5px 9px;font-size:13px">
          <option value="" <?= $filter_setup==='' ? 'selected':'' ?>>Semua</option>
          <option value="sudah" <?= $filter_setup==='sudah' ? 'selected':'' ?>><?=rmi_icon('tick')?> Sudah Setup</option>
          <option value="belum" <?= $filter_setup==='belum' ? 'selected':'' ?>><?=rmi_icon('x')?> Belum Setup</option>
        </select>
      </div>
      <div>
        <label class="small muted" style="display:block;margin-bottom:3px">Bulan Rekap</label>
        <input type="month" name="month" value="<?= htmlspecialchars($filter_month, ENT_QUOTES, 'UTF-8') ?>" style="padding:5px 9px;font-size:13px">
      </div>
      <div style="display:flex;gap:6px;align-items:flex-end">
        <button type="submit" class="btn ok" style="padding:6px 14px;font-size:13px">Filter</button>
        <a href="users.php" class="btn" style="padding:6px 14px;font-size:13px">Reset</a>
      </div>
    </form>

    <?php if ($users): ?>
    <!-- Batch save form -->
    <form method="post" id="batchForm">
      <?= csrf_field() ?>
      <input type="hidden" name="redirect_query" value="<?= $redirect_query ?>">
      <?php foreach ($users as $u): ?>
        <input type="hidden" name="user_ids[]" value="<?= (int)$u['id'] ?>">
      <?php endforeach; ?>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div class="muted small">Menampilkan <b><?= count($users) ?></b> user</div>
        <button type="submit" class="btn ok" style="padding:6px 18px;font-size:13px;font-weight:600">
          <?=rmi_icon('doc')?> Simpan Semua (<?= count($users) ?>)
        </button>
      </div>

      <div style="overflow-x:auto">
      <table class="table" style="font-size:13px">
        <thead>
          <tr>
            <th>Username</th>
            <th>Nama / Employee</th>
            <th>Dept</th>
            <th>Office ERP</th>
            <th>Office Override</th>
            <th style="text-align:center">HR Admin</th>
            <th style="text-align:center">Setup</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
          $uid     = (int)$u['id'];
          $p       = $map[$uid] ?? ['office_code'=>null,'is_hr_admin'=>0];
          $linked  = !empty($u['holder_employee_code']);
          $rowStyle = $linked ? '' : 'background:rgba(220,38,38,.08)';
        ?>
          <tr style="<?= $rowStyle ?>">
            <td style="font-weight:600">
              <?= htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') ?>
              <div class="muted" style="font-size:11px">#<?= $uid ?></div>
            </td>
            <td>
              <?php if ($linked): ?>
                <div style="font-weight:500"><?= htmlspecialchars((string)$u['employee_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="muted" style="font-size:11px;font-family:monospace"><?= htmlspecialchars((string)$u['holder_employee_code'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php elseif (!empty($u['full_name'])): ?>
                <div><?= htmlspecialchars((string)$u['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div style="color:#ef4444;font-size:11px">Belum di-set holder</div>
              <?php else: ?>
                <span style="color:#ef4444;font-size:12px">— Belum di-set holder —</span>
              <?php endif; ?>
            </td>
            <td class="muted"><?= htmlspecialchars((string)($u['department'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="muted"><?= htmlspecialchars((string)($u['office_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <select name="office_code_<?= $uid ?>" style="font-size:12px;padding:3px 6px;min-width:130px">
                <option value="">(pakai dari ERP)</option>
                <?php foreach ($offices as $o): ?>
                  <option value="<?= htmlspecialchars($o['office_code'], ENT_QUOTES, 'UTF-8') ?>"
                    <?= ($p['office_code']===$o['office_code']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($o['office_code'].' - '.$o['office_name'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <?php
              $uRole = strtoupper(trim((string)($u['role'] ?? '')));
              $uLevel = strtoupper(trim((string)($u['role'] ?? '')));
              $isProtected = in_array($uRole, ['SYS','ADMIN','SUPERADMIN'], true)
                          || in_array($uLevel, ['SYS','ADMIN','SUPERADMIN'], true);
            ?>
            <td style="text-align:center">
              <?php if ($isProtected): ?>
                <input type="checkbox" checked disabled title="Akun SYS/ADMIN selalu HR Admin — tidak bisa dicabut">
                <span style="font-size:10px;color:#f59e0b;display:block"><?=rmi_icon('warn')?> Protected</span>
              <?php else: ?>
                <input type="checkbox" name="is_hr_admin_<?= $uid ?>" value="1"
                  <?= ((int)$p['is_hr_admin']===1) ? 'checked' : '' ?>>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php if ($linked): ?>
                <span style="background:#14532d;color:#4ade80;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600"><?=rmi_icon('tick')?></span>
              <?php else: ?>
                <a href="<?= htmlspecialchars($base_url_msl . '?edit=' . $uid, ENT_QUOTES, 'UTF-8') ?>"
                   style="background:#450a0a;color:#f87171;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;text-decoration:none"
                   title="Set Holder Employee"><?=rmi_icon('x')?> Set</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div style="text-align:right;margin-top:8px">
        <button type="submit" class="btn ok" style="padding:7px 22px;font-size:13px;font-weight:600">
          <?=rmi_icon('doc')?> Simpan Semua (<?= count($users) ?>)
        </button>
      </div>
    </form>
    <?php else: ?>
      <div class="muted small" style="margin-top:10px">
        <?php if ($filter_q || $filter_role || $filter_dept || $filter_office || $filter_hr !== '' || $filter_setup !== ''): ?>
          Tidak ada user yang cocok dengan filter. <a href="users.php">Reset filter</a>
        <?php else: ?>
          Tidak menemukan tabel user (<b>master_system_login</b>).
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php
  // Hitung summary hari ini dari filtered users
  $total_today_in  = 0;
  $total_today_out = 0;
  $total_belum     = 0;
  foreach ($users as $u) {
    $uid  = (int)$u['id'];
    $abs  = $abs_stats[$uid] ?? null;
    if ($abs && $abs['today_in'])  $total_today_in++;
    if ($abs && $abs['today_out']) $total_today_out++;
    if (!$abs || !$abs['today_in']) $total_belum++;
  }
  $label_month = date('F Y', strtotime($filter_month . '-01'));
  ?>

  <div class="card col-12" style="margin-top:16px">
    <div class="h1">Rekap Absensi — <?= htmlspecialchars($label_month, ENT_QUOTES, 'UTF-8') ?></div>

    <!-- Summary badges -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin:10px 0 16px 0">
      <span style="background:#22c55e;color:#fff;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:600">
        <?=rmi_icon('check')?> Hadir Hari Ini: <?= $total_today_in ?>
      </span>
      <span style="background:#3b82f6;color:#fff;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:600">
        <?=rmi_icon('refresh')?> Sudah Checkout: <?= $total_today_out ?>
      </span>
      <span style="background:#ef4444;color:#fff;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:600">
        <?=rmi_icon('cross')?> Belum Hadir: <?= $total_belum ?>
      </span>
    </div>

    <table class="table" style="margin-top:4px;font-size:13px">
      <thead>
        <tr>
          <th>Username</th>
          <th>Dept / Office</th>
          <th style="text-align:center">Hari Hadir<br><span class="muted" style="font-weight:400"><?= htmlspecialchars($label_month, ENT_QUOTES, 'UTF-8') ?></span></th>
          <th style="text-align:center">Status Hari Ini</th>
          <th>Check-in Terakhir</th>
          <th style="text-align:center">Izin/Sakit<br><span class="muted" style="font-weight:400"><?= htmlspecialchars($label_month, ENT_QUOTES, 'UTF-8') ?></span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u):
        $uid  = (int)$u['id'];
        $abs  = $abs_stats[$uid] ?? null;
        $izin = $izin_stats[$uid] ?? null;
        $hari_hadir = $abs ? (int)$abs['hari_hadir'] : 0;

        // Status hari ini
        if ($abs && $abs['today_in'] && $abs['today_out']) {
          $status_badge = '<span style="background:#3b82f6;color:#fff;padding:2px 10px;border-radius:12px;font-size:12px">Checkout ' . rmi_icon("tick") . '</span>';
        } elseif ($abs && $abs['today_in']) {
          $status_badge = '<span style="background:#22c55e;color:#fff;padding:2px 10px;border-radius:12px;font-size:12px">Hadir ' . rmi_icon("tick") . '</span>';
        } else {
          $status_badge = '<span style="background:#e5e7eb;color:#666;padding:2px 10px;border-radius:12px;font-size:12px">Belum</span>';
        }

        // Last check-in format
        $last_in = $abs['last_checkin'] ?? null;
        $last_in_fmt = $last_in ? date('d/m H:i', strtotime($last_in)) : '-';

        // Hari hadir badge color
        $hadir_color = $hari_hadir >= 20 ? '#22c55e' : ($hari_hadir >= 10 ? '#f59e0b' : ($hari_hadir > 0 ? '#3b82f6' : '#e5e7eb'));
        $hadir_text  = $hari_hadir > 0 ? '#fff' : '#999';

        // Izin display
        $izin_html = '-';
        if ($izin) {
          $parts = [];
          if ((int)$izin['approved'] > 0) $parts[] = '<span style="color:#22c55e;font-weight:600">' . (int)$izin['approved'] . ' disetujui</span>';
          if ((int)$izin['pending']  > 0) $parts[] = '<span style="color:#f59e0b;font-weight:600">' . (int)$izin['pending']  . ' pending</span>';
          $izin_html = implode(' / ', $parts) ?: '-';
        }

        $row_bg = (!$abs || !$abs['today_in']) ? '' : '';
      ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="muted">
            <?= htmlspecialchars((string)($u['department'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            <?php if (!empty($u['office_code'])): ?>
              <br><small><?= htmlspecialchars((string)$u['office_code'], ENT_QUOTES, 'UTF-8') ?></small>
            <?php endif; ?>
          </td>
          <td style="text-align:center">
            <span style="background:<?= $hadir_color ?>;color:<?= $hadir_text ?>;padding:2px 12px;border-radius:12px;font-size:13px;font-weight:600">
              <?= $hari_hadir ?>
            </span>
          </td>
          <td style="text-align:center"><?= $status_badge ?></td>
          <td class="muted" style="font-size:12px"><?= htmlspecialchars($last_in_fmt, ENT_QUOTES, 'UTF-8') ?></td>
          <td style="text-align:center;font-size:12px"><?= $izin_html ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (!$users): ?>
      <div class="muted small" style="margin-top:10px">Tidak ada user.</div>
    <?php endif; ?>

    <div style="margin-top:12px;font-size:12px;color:#888">
      Data absensi bulan <b><?= htmlspecialchars($label_month, ENT_QUOTES, 'UTF-8') ?></b>
      · <a href="rekap.php?from=<?= urlencode($filter_month . '-01') ?>&to=<?= urlencode(date('Y-m-t', strtotime($filter_month . '-01'))) ?>">Lihat Rekap Detail →</a>
    </div>
  </div>
</div>
<?php require_once __DIR__ . "/../_layout_bottom.php"; ?>
