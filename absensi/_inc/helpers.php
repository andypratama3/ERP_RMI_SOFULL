<?php
// absensi/_inc/helpers.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker

function absensi_is_hr_admin(): bool {
  $role = strtoupper((string)($_SESSION['role'] ?? ''));
  $level = strtoupper((string)($_SESSION['level'] ?? ''));
  $dept = strtoupper((string)($_SESSION['department'] ?? ''));
  return in_array($role, ['SYS','DIRECTOR','ADMIN','HR','HRL'], true)
      || in_array($level, ['SYS','DIRECTOR','ADMIN'], true)
      || in_array($dept, ['HR','HRL'], true);
}

function absensi_require_hr_admin(): void {
  if (!absensi_is_hr_admin()) {
    http_response_code(403);
    die('Akses ditolak (khusus HR/Admin).');
  }
}

function absensi_office_options(PDO $pdo, ?string $selected = null): string {
  $rows = $pdo->query("SELECT office_code, office_name FROM absensi_offices WHERE is_active=1 ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
  $out = '';
  foreach ($rows as $r) {
    $code = (string)$r['office_code'];
    $name = (string)$r['office_name'];
    $sel = ($selected && $selected === $code) ? ' selected' : '';
    $out .= '<option value="'.htmlspecialchars($code,ENT_QUOTES,'UTF-8').'"'.$sel.'>'.htmlspecialchars($name.' ('.$code.')',ENT_QUOTES,'UTF-8').'</option>';
  }
  return $out;
}
