<?php
/**
 * dashboards/itc/itc_dashboard.php
 * IT & Cloud Dashboard
 * Final review: KPI user/access dibuat operasional, tanpa mengubah flow/link yang sudah baik.
 */
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();

$bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('u')) {
    function u($path) {
        $bp = $GLOBALS['BASE_PROJECT'] ?? '';
        return rtrim($bp, '/') . '/' . ltrim($path, '/');
    }
}

$pdo = $GLOBALS['pdo'] ?? null;

if (!function_exists('itc_table_exists')) {
    function itc_table_exists($pdo, $table) {
        if (!$pdo) return false;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
            $st->execute([$table]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('itc_columns')) {
    function itc_columns($pdo, $table) {
        if (!$pdo) return [];
        try {
            $st = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
            $st->execute([$table]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $out[strtolower((string)$c)] = (string)$c;
            return $out;
        } catch (Throwable $e) { return []; }
    }
}
if (!function_exists('itc_first_col')) {
    function itc_first_col($cols, $candidates) {
        foreach ($candidates as $c) {
            $k = strtolower($c);
            if (isset($cols[$k])) return $cols[$k];
        }
        return null;
    }
}
if (!function_exists('itc_scalar')) {
    function itc_scalar($pdo, $sql, $params = [], $default = null) {
        if (!$pdo) return $default;
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $v = $st->fetchColumn();
            return ($v === false || $v === null) ? $default : $v;
        } catch (Throwable $e) { return $default; }
    }
}

// -----------------------------------------------------------------------------
// KPI USER / ACCESS
// Prinsip: MFA backlog hanya untuk USER AKTIF. User inactive tidak dianggap wajib MFA.
// -----------------------------------------------------------------------------
$kpi_itc = [
    'total_users'        => 0,
    'active_users'       => 0,
    'inactive_users'     => 0,
    'mfa_active_users'   => 0,
    'active_without_mfa' => 0,
];

if ($pdo && itc_table_exists($pdo, 'master_system_login')) {
    $cols = itc_columns($pdo, 'master_system_login');
    $hasDeleted = isset($cols['deleted_at']);
    $hasStatus  = isset($cols['status']);
    $hasMfa     = isset($cols['mfa_enabled']);

    $base = $hasDeleted ? "deleted_at IS NULL" : "1=1";
    $activeCond = $hasStatus ? "LOWER(COALESCE(status,''))='active'" : "1=1";

    $kpi_itc['total_users'] = (int)itc_scalar($pdo, "SELECT COUNT(*) FROM master_system_login WHERE $base", [], 0);
    $kpi_itc['active_users'] = (int)itc_scalar($pdo, "SELECT COUNT(*) FROM master_system_login WHERE $base AND $activeCond", [], 0);
    $kpi_itc['inactive_users'] = max(0, $kpi_itc['total_users'] - $kpi_itc['active_users']);

    if ($hasMfa) {
        $kpi_itc['mfa_active_users'] = (int)itc_scalar(
            $pdo,
            "SELECT COUNT(*) FROM master_system_login WHERE $base AND $activeCond AND COALESCE(mfa_enabled,0)=1",
            [],
            0
        );
        $kpi_itc['active_without_mfa'] = max(0, $kpi_itc['active_users'] - $kpi_itc['mfa_active_users']);
    }
}

// -----------------------------------------------------------------------------
// MANAGER CONTROLLING ITC
// Hanya memakai source yang dapat dipastikan dari schema. Jika source operasional
// belum tersedia, tampil N/A, bukan angka 0 palsu.
// -----------------------------------------------------------------------------
$mgr = [
    'scope'              => 'ALL',
    'team_staff'         => null,
    'attendance_today'   => null,
    'security_backlog'   => $kpi_itc['active_without_mfa'],
    'exceptions'         => $kpi_itc['active_without_mfa'],
    'health_issue'       => null,
    'backup_issue'       => null,
    'asset_request_open' => null,
];

// Team staff ITC dari master_employees bila struktur dapat dikenali.
if ($pdo && itc_table_exists($pdo, 'master_employees')) {
    $ec = itc_columns($pdo, 'master_employees');
    $deptCol = itc_first_col($ec, ['department','dept','department_code','dept_code']);
    $statusCol = itc_first_col($ec, ['status','employee_status']);
    $deletedCol = itc_first_col($ec, ['deleted_at']);
    if ($deptCol) {
        $where = ["UPPER(COALESCE(`$deptCol`,''))='ITC'"];
        if ($statusCol) $where[] = "LOWER(COALESCE(`$statusCol`,'')) IN ('active','aktif')";
        if ($deletedCol) $where[] = "`$deletedCol` IS NULL";
        $mgr['team_staff'] = (int)itc_scalar($pdo, "SELECT COUNT(*) FROM master_employees WHERE ".implode(' AND ', $where), [], 0);
    }
}

// Attendance Today ITC hanya dihitung jika ada mapping user yang aman.
if ($pdo && itc_table_exists($pdo, 'absensi_logs') && itc_table_exists($pdo, 'master_system_login')) {
    $ac = itc_columns($pdo, 'absensi_logs');
    $lc = itc_columns($pdo, 'master_system_login');
    $aUser = itc_first_col($ac, ['user_id','login_id','system_login_id']);
    $aAction = itc_first_col($ac, ['action','type']);
    $aCreated = itc_first_col($ac, ['created_at','log_time','event_time']);
    $lId = itc_first_col($lc, ['id']);
    $lDept = itc_first_col($lc, ['department','dept','department_code','dept_code']);
    if ($aUser && $aAction && $aCreated && $lId && $lDept) {
        $sql = "SELECT COUNT(DISTINCT a.`$aUser`) FROM absensi_logs a JOIN master_system_login l ON l.`$lId`=a.`$aUser` WHERE DATE(a.`$aCreated`)=CURDATE() AND UPPER(COALESCE(l.`$lDept`,''))='ITC' AND UPPER(COALESCE(a.`$aAction`,''))='IN'";
        $mgr['attendance_today'] = (int)itc_scalar($pdo, $sql, [], 0);
    }
}

// Source health/backup/asset request sengaja tidak ditebak.
// Bila nanti ada tabel/log resmi, KPI dapat dihubungkan tanpa mengubah flow dashboard lain.

// Tentukan link KPI berdasarkan level user
$_itcRole  = strtoupper(trim((string)($_SESSION['role']  ?? '')));
$_itcLevel = strtoupper(trim((string)($_SESSION['level'] ?? '')));
$_itcDept  = strtoupper(trim((string)($_SESSION['department'] ?? '')));
$_isSuperAdmin = in_array($_itcRole,  ['SYS','ADMIN','SUPERADMIN'], true)
              || in_array($_itcLevel, ['SYS','ADMIN','SUPERADMIN'], true);
$_isITCOnly = ($_itcDept === 'ITC' && !$_isSuperAdmin);
$_kpiBase = $_isITCOnly ? u('/master/itc_reset_password.php') : u('/master/master_system_login.php');

require_once __DIR__ . '/../../_shared/rmi_layout.php';

$extraHead = <<<'STYLE'
<style>
body{background:#0b1220;color:#e8ecf4}
.d-wrap{max-width:1180px;margin:0 auto;padding:20px 16px}
.d-header{background:linear-gradient(135deg,rgba(88,28,135,.7),rgba(109,40,217,.5));border:1px solid rgba(139,92,246,.3);border-radius:18px;padding:22px 26px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.d-header h2{margin:0;font-size:20px;font-weight:800;color:#fff}.d-header p{margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.65)}
.d-actions{display:flex;gap:8px;flex-wrap:wrap}.d-btn{padding:7px 14px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:700;border:1px solid rgba(255,255,255,.15);color:#e2e8f0;background:rgba(255,255,255,.07);display:inline-flex;align-items:center;gap:5px}.d-btn:hover{background:rgba(255,255,255,.14);color:#fff}
.mc{background:rgba(35,52,75,.72);border:1px solid rgba(148,163,184,.22);border-radius:16px;padding:16px;margin-bottom:18px}.mc h3{margin:0 0 12px;font-size:15px}.mc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:9px}.mc-card{border:1px solid rgba(255,255,255,.14);border-radius:12px;padding:12px;background:rgba(15,23,42,.3)}.mc-lbl{font-size:10px;text-transform:uppercase;color:#94a3b8}.mc-val{font-size:22px;font-weight:800;margin-top:5px}.mc-sub{font-size:10px;color:#64748b;margin-top:3px}
.d-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:18px}.d-kpi-card{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:14px;border-top:3px solid var(--kc);transition:all .2s}.d-kpi-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.3)}.d-kpi-icon{font-size:20px;margin-bottom:6px}.d-kpi-val{font-size:24px;font-weight:800;color:#fff}.d-kpi-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-top:3px}.d-kpi-sub{font-size:10px;color:#64748b;margin-top:4px}
.d-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.d-group{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px}.d-group-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;margin-bottom:10px}.d-link{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:9px;text-decoration:none;color:#e2e8f0;font-size:13px;transition:all .2s;margin-bottom:3px}.d-link:hover{background:rgba(255,255,255,.08);color:#fff;padding-left:14px}.d-link:last-child{margin-bottom:0}.d-link .li{font-size:15px;flex-shrink:0}
</style>
STYLE;

rmi_header('ITC Dashboard', [
    'active'     => 'dashboard',
    'subtitle'   => 'IT & Cloud — Reset Password, Health Check, Security.',
    'extra_head' => $extraHead,
]);
?>
<div class="d-wrap">

  <!-- Manager Controlling Staff removed from ITC dashboard display only. -->

  <div class="d-header">
    <div>
      <h2>💻 ITC Dashboard</h2>
      <p>IT & Communication — User Access, MFA, Health Check, Security</p>
    </div>
    <div class="d-actions">
      <a class="d-btn" href="<?= h(u('/dashboards/itc/panduan.php')) ?>">📚 Panduan</a>
      <a class="d-btn" href="<?= h(u('/dashboards/index.php')) ?>">🏠 Dashboard Center</a>
      <a class="d-btn" href="<?= h(u('/tools/health.php')) ?>">🟢 Health Check</a>
    </div>
  </div>

  <div class="d-kpi">
    <a class="d-kpi-card" href="<?= h($_kpiBase) ?>" style="--kc:#3b82f6;text-decoration:none">
      <div class="d-kpi-icon">👤</div><div class="d-kpi-val"><?= $kpi_itc['total_users'] ?></div><div class="d-kpi-lbl">Total User</div><div class="d-kpi-sub">Seluruh akun belum dihapus</div>
    </a>
    <a class="d-kpi-card" href="<?= h($_isITCOnly ? $_kpiBase : u('/master/master_system_login.php?status=active')) ?>" style="--kc:#22c55e;text-decoration:none">
      <div class="d-kpi-icon">✅</div><div class="d-kpi-val"><?= $kpi_itc['active_users'] ?></div><div class="d-kpi-lbl">User Aktif</div><div class="d-kpi-sub">Akun yang masih digunakan</div>
    </a>
    <a class="d-kpi-card" href="<?= h(u('/master/mfa_policy.php')) ?>" style="--kc:#8b5cf6;text-decoration:none">
      <div class="d-kpi-icon">🔐</div><div class="d-kpi-val"><?= $kpi_itc['mfa_active_users'] ?></div><div class="d-kpi-lbl">MFA Aktif (User Aktif)</div><div class="d-kpi-sub">Tidak menghitung akun inactive</div>
    </a>
    <a class="d-kpi-card" href="<?= h(u('/master/mfa_policy.php')) ?>" style="--kc:#ef4444;text-decoration:none">
      <div class="d-kpi-icon">⚠️</div><div class="d-kpi-val"><?= $kpi_itc['active_without_mfa'] ?></div><div class="d-kpi-lbl">User Aktif Belum MFA</div><div class="d-kpi-sub">Backlog security yang perlu tindakan</div>
    </a>
    <a class="d-kpi-card" href="<?= h($_isITCOnly ? $_kpiBase : u('/master/master_system_login.php?status=inactive')) ?>" style="--kc:#64748b;text-decoration:none">
      <div class="d-kpi-icon">🔒</div><div class="d-kpi-val"><?= $kpi_itc['inactive_users'] ?></div><div class="d-kpi-lbl">User Inactive</div><div class="d-kpi-sub">Tidak dimasukkan ke backlog MFA</div>
    </a>
    <a class="d-kpi-card" href="<?= h(u('/tools/health.php')) ?>" style="--kc:#06b6d4;text-decoration:none">
      <div class="d-kpi-icon">🟢</div><div class="d-kpi-val">N/A</div><div class="d-kpi-lbl">System Health</div><div class="d-kpi-sub">Buka Health Check untuk status real-time</div>
    </a>
    <a class="d-kpi-card" href="<?= h(u('/tools/backup_now.php')) ?>" style="--kc:#f59e0b;text-decoration:none">
      <div class="d-kpi-icon">💾</div><div class="d-kpi-val">N/A</div><div class="d-kpi-lbl">Backup Status</div><div class="d-kpi-sub">Belum ada source log dashboard</div>
    </a>
  </div>

  <div class="d-grid">
    <div class="d-group"><div class="d-group-title">👤 User Management</div>
      <a class="d-link" href="<?= h(u('/master/itc_reset_password.php')) ?>"><span class="li">👥</span> Manajemen User (ITC)</a>
      <a class="d-link" href="<?= h(u('/master/mfa_settings.php')) ?>"><span class="li">🔐</span> MFA Settings (Akun Saya)</a>
      <?php if ($_isSuperAdmin): ?><a class="d-link" href="<?= h(u('/master/mfa_policy.php')) ?>"><span class="li">📋</span> MFA Policy</a><a class="d-link" href="<?= h(u('/master/master_system_login.php')) ?>"><span class="li">⚙️</span> Master System Login</a><?php endif; ?>
    </div>
    <div class="d-group"><div class="d-group-title">🔐 Security</div>
      <a class="d-link" href="<?= h(u('/tools/security_audit.php')) ?>"><span class="li">🔒</span> Security Audit</a>
      <a class="d-link" href="<?= h(u('/docs/link/sop_mfa.php')) ?>"><span class="li">📄</span> SOP MFA</a>
    </div>
    <div class="d-group"><div class="d-group-title">🛠️ Tools & System</div>
      <a class="d-link" href="<?= h(u('/tools/index.php')) ?>"><span class="li">🔧</span> Tools Center</a>
      <a class="d-link" href="<?= h(u('/tools/health.php')) ?>"><span class="li">🟢</span> Health Check</a>
      <a class="d-link" href="<?= h(u('/tools/enterprise_audit.php')) ?>"><span class="li">📊</span> Enterprise Audit</a>
      <a class="d-link" href="<?= h(u('/tools/backup_now.php')) ?>"><span class="li">💾</span> Backup Now</a>
    </div>
    <div class="d-group"><div class="d-group-title">⚙️ Master & Config</div>
      <a class="d-link" href="<?= h(u('/master/master_office.php')) ?>"><span class="li">🏢</span> Master Office</a>
      <a class="d-link" href="<?= h(u('/master/master_system_config.php')) ?>"><span class="li">⚙️</span> System Config</a>
      <?php if ($_isSuperAdmin): ?><a class="d-link" href="<?= h(u('/absensi/admin/offices.php')) ?>"><span class="li">📍</span> Absensi Office Settings</a><?php endif; ?>
      <a class="d-link" href="<?= h(u('/hrl_process/index.php')) ?>"><span class="li">📋</span> HRL Process</a>
    </div>
    <div class="d-group"><div class="d-group-title">💻 ITC Asset Request</div>
      <a class="d-link" href="<?= h(u('/Fixed_Asset/assets.php#asset-purchase-flow')) ?>"><span class="li">🖥️</span> Pengajuan Pembelian Aset ITC</a>
      <a class="d-link" href="<?= h(u('/Fixed_Asset/index.php')) ?>"><span class="li">📊</span> Dashboard Fixed Asset</a>
    </div>
  </div>

  <?php
  $auditModules = ['TOOLS_BACKUP', 'RBAC', 'SYSTEM', 'CHAT', 'API_KEY', 'MFA'];
  $auditLimit   = 15;
  require __DIR__ . '/../_audit_log_widget.php';
  ?>
</div>
<?php rmi_footer(); ?>
