<?php
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker
require_once __DIR__ . '/_inc/bootstrap.php';

$title = $title ?? 'Absensi';
$flash = absensi_flash_get();

/**
 * Base URL untuk modul /absensi
 * - Aman dipakai dari halaman root (/absensi/*.php) maupun subfolder (/absensi/admin/*.php)
 * - Support jika project berada di subfolder (contoh: /ERP_RMI_SOFULL/absensi/...)
 */
$script = $_SERVER['SCRIPT_NAME'] ?? '';

// Base prefix project (contoh: "/ERP_RMI_SOFULL" jika deploy di subfolder)
$BASE_PREFIX = '';
$posAbs = strpos($script, '/absensi');
if ($posAbs !== false) {
  $BASE_PREFIX = substr($script, 0, $posAbs);
}

$ABS_BASE = rtrim(dirname($script), '/');
if (substr($ABS_BASE, -6) === '/admin') {
  $ABS_BASE = substr($ABS_BASE, 0, -6);
}
if ($ABS_BASE === '' || $ABS_BASE === '.') {
  // fallback paling aman
  $ABS_BASE = ($BASE_PREFIX ?: '') . '/absensi';
}
$ABS_BASE_H = htmlspecialchars($ABS_BASE, ENT_QUOTES, 'UTF-8');

// URL logout ERP
$LOGOUT_URL = ($BASE_PREFIX ?: '') . '/master/logout.php';
$LOGOUT_URL_H = htmlspecialchars($LOGOUT_URL, ENT_QUOTES, 'UTF-8');

// Label user (biar jelas siapa login apa)
$u_username = (string)($ABS_USER['username'] ?? ($_SESSION['username'] ?? ''));
$u_role     = (string)($_SESSION['role'] ?? ($ABS_USER['role'] ?? ''));
$u_dept     = (string)($_SESSION['department'] ?? ($ABS_USER['department'] ?? ''));
$u_office   = (string)($_SESSION['office_code'] ?? ($ABS_USER['office_code'] ?? ''));
$user_badge = trim($u_username . ' • ' . strtoupper($u_role ?: 'USER') . ($u_dept ? (' • ' . strtoupper($u_dept)) : '') . ($u_office ? (' • ' . strtoupper($u_office)) : ''));

require_once __DIR__ . '/../_shared/rmi_layout.php';

$extraHead = '<link rel="icon" href="' . $ABS_BASE_H . '/assets/rmi_logo.svg">' .
  '<link rel="stylesheet" href="' . $ABS_BASE_H . '/assets/absensi.css?v=1">';

rmi_header($title, [
  'active' => 'absensi',
  'subtitle' => 'Absensi by Photo • Enterprise+++ • GeoFence (Google Maps)',
  'extra_head' => $extraHead,
]);
?>

<div class="rmi-card mb-3">
  <div class="rmi-card-header d-flex flex-wrap gap-3 justify-content-between align-items-start">
    <div>
      <div class="fw-semibold">Absensi by Photo</div>
      <div class="rmi-muted small">Enterprise+++ • GeoFence (Google Maps)</div>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
      <?php if ($user_badge): ?>
        <span class="badge rmi-badge"><?= htmlspecialchars($user_badge, ENT_QUOTES,'UTF-8') ?></span>
      <?php endif; ?>
      <a class="btn btn-outline-light btn-sm" href="<?= $ABS_BASE_H ?>/index.php">Dashboard</a>
      <a class="btn btn-outline-light btn-sm" href="<?= $ABS_BASE_H ?>/history.php">Riwayat</a>
      <a class="btn btn-outline-light btn-sm" href="<?= $ABS_BASE_H ?>/request.php">Izin/Dinas</a>
      <?php if (function_exists('absensi_is_hr_admin') && absensi_is_hr_admin($pdo ?? null, $ABS_USER ?? null)): ?>
        <a class="btn btn-outline-light btn-sm" href="<?= $ABS_BASE_H ?>/admin/rekap.php">Admin HR</a>
      <?php endif; ?>
      <a class="btn btn-outline-light btn-sm" href="<?= $LOGOUT_URL_H ?>">Logout</a>
    </div>
  </div>
</div>

<?php
// Cek apakah user adalah SYS/ADMIN — mereka tidak perlu holder employee
$_abs_role  = strtoupper(trim((string)($ABS_USER['role']  ?? '')));
$_abs_level = strtoupper(trim((string)($ABS_USER['level'] ?? '')));
$_abs_is_sys = in_array($_abs_role,  ['SYS','ADMIN','SUPERADMIN'], true)
            || in_array($_abs_level, ['SYS','ADMIN','SUPERADMIN'], true);
?>
<?php if (defined('ABSENSI_REQUIRE_HOLDER') && ABSENSI_REQUIRE_HOLDER===1 && empty($ABS_HOLDER) && !$_abs_is_sys): ?>
  <div class="alert alert-warning">
    Pemegang akun (employee) belum diset. Buka <b>Master System Login</b> → set <b>Holder Employee</b> untuk username ini.
  </div>
<?php endif; ?>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type']==='ok'?'success':'danger' ?>">
    <?= htmlspecialchars($flash['msg'], ENT_QUOTES,'UTF-8') ?>
  </div>
<?php endif; ?>
