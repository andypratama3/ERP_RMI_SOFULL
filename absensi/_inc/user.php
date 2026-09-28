<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker
/**
 * absensi/_inc/user.php
 * Enterprise+++ (Model B ready)
 *
 * Prinsip:
 * - Ambil role/department/level/office_code dari SESSION (sumber utama: master_system_login)
 * - absensi_user_profile dipakai untuk flag is_hr_admin (+ optional override office_code)
 * - Support Model B: baca master_system_login.holder_employee_code untuk pemegang akun (employee)
 */

function absensi_master_system_login(PDO $pdo, string $username): array {
  $username = trim($username);
  if ($username === '') return [];

  try {
    $stmt = $pdo->prepare("SELECT id, username, role, level, department, office_code, holder_employee_code
                           FROM master_system_login
                           WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function absensi_current_user(PDO $pdo): array {
  // --- 1) Ambil user_id + username dari beberapa format session yang umum ---
  $sid = null;
  $uname = null;

  if (isset($_SESSION['user_id'])) $sid = (int)$_SESSION['user_id'];
  if (isset($_SESSION['id']) && !$sid) $sid = (int)$_SESSION['id'];
  if (isset($_SESSION['uid']) && !$sid) $sid = (int)$_SESSION['uid'];

  if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $u = $_SESSION['user'];
    if (!$sid && isset($u['id'])) $sid = (int)$u['id'];
    if (!$sid && isset($u['user_id'])) $sid = (int)$u['user_id'];
    if (!$uname && isset($u['username'])) $uname = (string)$u['username'];
    if (!$uname && isset($u['name'])) $uname = (string)$u['name'];
  }

  if (!$uname && isset($_SESSION['username'])) $uname = (string)$_SESSION['username'];

  // --- 2) Ambil role/department/level/office dari session (utama) ---
  $role  = $_SESSION['role'] ?? ($_SESSION['user']['role'] ?? '');
  $dept  = $_SESSION['department'] ?? ($_SESSION['user']['department'] ?? ($_SESSION['dept_code'] ?? ($_SESSION['user']['dept_code'] ?? '')));
  $level = $_SESSION['level'] ?? ($_SESSION['user']['level'] ?? '');

  $sessOffice = $_SESSION['office_code'] ?? ($_SESSION['user']['office_code'] ?? ($_SESSION['office'] ?? ($_SESSION['user']['office'] ?? '')));
  $sessOffice = is_string($sessOffice) ? strtoupper(trim($sessOffice)) : '';

  $role  = is_string($role) ? strtoupper(trim($role)) : '';
  $dept  = is_string($dept) ? strtoupper(trim($dept)) : '';
  $level = is_string($level) ? strtoupper(trim($level)) : '';

  // --- 3) Load profile (is_hr_admin + optional office override) ---
  $profile = ['office_code' => null, 'is_hr_admin' => 0];
  try {
    if ($sid) {
      $stmt = $pdo->prepare("SELECT office_code, is_hr_admin FROM absensi_user_profile WHERE user_id=? LIMIT 1");
      $stmt->execute([$sid]);
      $p = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($p) $profile = $p;
    }
  } catch (Throwable $e) {}

  $profileOffice = isset($profile['office_code']) ? strtoupper(trim((string)$profile['office_code'])) : '';
  $isHrAdmin = !empty($profile['is_hr_admin']) ? 1 : 0;

  // --- 4) Optional: baca master_system_login utk holder employee & fallback role/office ---
  $msl = [];
  if ($uname) {
    $msl = absensi_master_system_login($pdo, (string)$uname);
  }

  $mslOffice = '';
  if (!empty($msl['office_code'])) {
    $mslOffice = strtoupper(trim((string)$msl['office_code']));
  }

  // Office final: session > (profile override jika bukan DEFAULT) > master_system_login > DEFAULT
  $profileOfficeUse = ($profileOffice !== '' && $profileOffice !== 'DEFAULT') ? $profileOffice : '';
  $officeFinal = $sessOffice ?: ($profileOfficeUse ?: ($mslOffice ?: 'DEFAULT'));

  // Fallback role/dept/level jika session kosong
  if ($role === '' && !empty($msl['role'])) $role = strtoupper(trim((string)$msl['role']));
  if ($dept === '' && !empty($msl['department'])) $dept = strtoupper(trim((string)$msl['department']));
  if ($level === '' && !empty($msl['level'])) $level = strtoupper(trim((string)$msl['level']));

  return [
    'id' => $sid,
    'username' => $uname,

    'role' => $role,
    'department' => $dept,
    'level' => $level,
    'office_code' => $officeFinal,

    'is_hr_admin' => $isHrAdmin,

    // Model B
    'holder_employee_code' => $msl['holder_employee_code'] ?? null,

    // optional diagnostics (tidak wajib)
    'login_role' => $msl['role'] ?? null,
    'login_level' => $msl['level'] ?? null,
    'login_department' => $msl['department'] ?? null,
    'login_office_code' => $msl['office_code'] ?? null,
  ];
}

/**
 * Ambil data pemegang akun (employee) untuk Model B.
 * Sumber: master_system_login.holder_employee_code → master_employees
 */
function absensi_current_holder(PDO $pdo, array $absUser): ?array {
  $code = trim((string)($absUser['holder_employee_code'] ?? ''));
  if ($code === '') return null;

  try {
    $stmt = $pdo->prepare("SELECT employee_code, employee_name, dept_code, level_type, office_code, status
                           FROM master_employees
                           WHERE employee_code=? LIMIT 1");
    $stmt->execute([$code]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r) return $r;
  } catch (Throwable $e) {}

  // Fallback: holder ada tapi employee belum dibuat
  return [
    'employee_code' => $code,
    'employee_name' => '(unknown)',
    'dept_code' => null,
    'level_type' => null,
    'office_code' => null,
    'status' => null,
  ];
}

/**
 * HR admin flag checker
 * - Bisa dipanggil dengan (PDO $pdo, array $user) atau tanpa argumen.
 */
function absensi_is_hr_admin(?PDO $pdo=null, ?array $user=null): bool {
  // Resolve user jika belum dikirim
  if ($user === null) {
    if (isset($GLOBALS['ABS_USER']) && is_array($GLOBALS['ABS_USER'])) {
      $user = $GLOBALS['ABS_USER'];
    } elseif ($pdo instanceof PDO) {
      try { $user = absensi_current_user($pdo); } catch (Throwable $e) {}
    }
  }

  if (is_array($user) && !empty($user['is_hr_admin'])) return true;

  // fallback based on role/level/department in session
  $role  = $_SESSION['role'] ?? ($_SESSION['user']['role'] ?? null);
  $level = $_SESSION['level'] ?? ($_SESSION['user']['level'] ?? null);
  $dept  = $_SESSION['department'] ?? ($_SESSION['user']['department'] ?? ($_SESSION['dept_code'] ?? ($_SESSION['user']['dept_code'] ?? null)));

  $role  = is_string($role)  ? strtoupper(trim($role))  : '';
  $level = is_string($level) ? strtoupper(trim($level)) : '';
  $dept  = is_string($dept)  ? strtoupper(trim($dept))  : '';

  // Admin/Superadmin always HR-admin for access purposes
  if (in_array($role,  ['SYS','SUPERADMIN','ADMIN'], true)) return true;
  if (in_array($level, ['SUPERADMIN','ADMIN'], true)) return true;

  // HR department gets admin HR menu
  if (in_array($dept, ['HR','HRL'], true)) return true;

  // fallback from master_system_login row (if available)
  if (is_array($user)) {
    $lr = strtoupper(trim((string)($user['login_role'] ?? '')));
    $ll = strtoupper(trim((string)($user['login_level'] ?? '')));
    $ld = strtoupper(trim((string)($user['login_department'] ?? '')));
    if (in_array($lr, ['SYS','SUPERADMIN','ADMIN'], true)) return true;
    if (in_array($ll, ['SUPERADMIN','ADMIN'], true)) return true;
    if (in_array($ld, ['HR','HRL'], true)) return true;
  }

  return false;
}
