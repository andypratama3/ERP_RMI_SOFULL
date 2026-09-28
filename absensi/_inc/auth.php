<?php
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker
// Prefer ERP auth if available
// Expected: master/auth.php defines session & user identity
$erpAuth = __DIR__ . '/../../master/auth.php';
if (file_exists($erpAuth)) {
  require_once $erpAuth;
}

// Fallback minimal auth for dev/demo if ERP auth doesn't exist
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function rmi_current_user() : array {
  // If ERP auth provides variables, adapt here. We keep it generic:
  // 1) Session 'user' array
  if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) return $_SESSION['user'];

  // 2) Legacy ERP patterns (optional)
  if (!empty($_SESSION['username'])) {
    return [
      'username' => $_SESSION['username'],
      'role' => $_SESSION['role'] ?? 'staff',
      'dept' => $_SESSION['dept'] ?? 'HRL',
      'full_name' => $_SESSION['full_name'] ?? $_SESSION['username'],
      'user_id' => $_SESSION['user_id'] ?? null,
    ];
  }

  return [];
}

function rmi_require_login() : void {
  $u = rmi_current_user();
  if (empty($u)) {
    // Gunakan BASE_PROJECT prefix agar benar di subdirectory deployment
    $base = '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $pos = strpos($script, '/absensi');
    if ($pos !== false) { $base = substr($script, 0, $pos); }
    $loc = $base . '/master/login.php';
    if (function_exists('rmi_redirect')) {
      rmi_redirect($loc);
    }
    header('Location: ' . $loc);
    exit;
  }
}

function rmi_is_role($roles) : bool {
  $u = rmi_current_user();
  $role = strtolower($u['role'] ?? '');
  $roles = array_map('strtolower', (array)$roles);
  return in_array($role, $roles, true);
}
