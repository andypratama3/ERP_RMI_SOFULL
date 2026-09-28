<?php
// absensi/_inc/layout.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker

function absensi_nav_active(string $path, string $cur): string {
  return ($path === $cur) ? 'active' : '';
}

function absensi_flash(): void {
  if (!empty($_SESSION['_flash'])) {
    $f = $_SESSION['_flash']; unset($_SESSION['_flash']);
    $type = htmlspecialchars($f['type'] ?? 'ok', ENT_QUOTES, 'UTF-8');
    $msg  = htmlspecialchars($f['msg'] ?? '', ENT_QUOTES, 'UTF-8');
    echo '<div class="flash '.$type.'">'.$msg.'</div>';
  }
}

function absensi_set_flash(string $type, string $msg): void {
  $_SESSION['_flash'] = ['type'=>$type,'msg'=>$msg];
}

function absensi_header(string $title, string $active): void {
  require_once __DIR__ . '/../../_shared/rmi_layout.php';
  $uname = htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8');
  $role  = htmlspecialchars((string)($_SESSION['role'] ?? ''), ENT_QUOTES, 'UTF-8');
  $dept  = htmlspecialchars((string)($_SESSION['department'] ?? ''), ENT_QUOTES, 'UTF-8');
  $level = htmlspecialchars((string)($_SESSION['level'] ?? ''), ENT_QUOTES, 'UTF-8');
  $badge = trim($role.' / '.$dept.' / '.$level, ' /');
  $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

  rmi_header($title, [
    'active' => 'absensi',
    'subtitle' => 'Absensi Enterprise+++',
    'extra_head' => '<link rel="stylesheet" href="'.$base.'/_assets/app.css?v=1">'
  ]);

  echo '<div class="rmi-card mb-3"><div class="rmi-card-header d-flex justify-content-between align-items-center">';
  echo '<div class="fw-semibold">'.$title.'</div>';
  echo '<span class="badge rmi-badge">'.$uname.($badge?(' • '.$badge):'').'</span></div>';
  echo '<div class="card-body"><div class="d-flex flex-wrap gap-2">';
  echo '<a class="btn btn-sm btn-outline-light '.absensi_nav_active("index.php",$active).'" href="'.$base.'/index.php">Dashboard</a>';
  echo '<a class="btn btn-sm btn-outline-light '.absensi_nav_active("checkin.php",$active).'" href="'.$base.'/checkin.php">Check-in</a>';
  echo '<a class="btn btn-sm btn-outline-light '.absensi_nav_active("checkout.php",$active).'" href="'.$base.'/checkout.php">Check-out</a>';
  echo '<a class="btn btn-sm btn-outline-light '.absensi_nav_active("history.php",$active).'" href="'.$base.'/history.php">Riwayat</a>';
  echo '<a class="btn btn-sm btn-outline-light '.absensi_nav_active("request.php",$active).'" href="'.$base.'/request.php">Izin / Dinas</a>';
  echo '<a class="btn btn-sm btn-outline-light '.absensi_nav_active("admin_rekap.php",$active).'" href="'.$base.'/admin/rekap.php">Admin HR</a>';
  echo '<a class="btn btn-sm btn-outline-light '.absensi_nav_active("admin_approval.php",$active).'" href="'.$base.'/admin/approval.php">Approval</a>';
  echo '<a class="btn btn-sm btn-outline-light '.absensi_nav_active("admin_offices.php",$active).'" href="'.$base.'/admin/offices.php">Office Settings</a>';
  echo '</div></div></div>';
}

function absensi_footer(): void {
  echo '<div class="rmi-muted small mb-3">Tip: aktifkan Location Services agar validasi lokasi akurat. Jika kantor belum diset, Admin > Office Settings.</div>';
  rmi_footer();
}
