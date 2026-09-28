<?php
declare(strict_types=1);

// absensi/_inc/bootstrap.php (Enterprise+++)
// Drop-in for ERP_RMI_SOFULL (requires master/auth.php and session login)

if (session_status() === PHP_SESSION_NONE) session_start();

// Try locate ERP auth
$auth = __DIR__ . '/../../master/auth.php';
if (!file_exists($auth)) $auth = __DIR__ . '/../master/auth.php';
require_once $auth;
require_once __DIR__ . '/../../master/_audit_master.php';
require_once __DIR__ . '/../../_shared/helpers.php';
require_once __DIR__ . '/../../_shared/rbac.php';

// Must be logged in (kecuali halaman publik terbatas, mis. kiosk.php dengan token)
if ((!defined('ABSENSI_BOOTSTRAP_PUBLIC_KIOSK') || !ABSENSI_BOOTSTRAP_PUBLIC_KIOSK) && function_exists('require_login')) {
  require_login();
}

// DB handle (provided by ERP)
$pdo = db_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// App config (safe defaults)
if (!defined('ABSENSI_MAX_MB')) {
  define('ABSENSI_MAX_MB', (int)(getenv('ABSENSI_MAX_MB') ?: 3));
}
if (!defined('ABSENSI_GEOFENCE_ENFORCE_DEFAULT')) {
  define('ABSENSI_GEOFENCE_ENFORCE_DEFAULT', (int)(getenv('ABSENSI_GEOFENCE_ENFORCE') ?: 1)); // 1=reject out-of-radius
}
if (!defined('ABSENSI_DEFAULT_RADIUS_M')) {
  define('ABSENSI_DEFAULT_RADIUS_M', (int)(getenv('ABSENSI_DEFAULT_RADIUS_M') ?: 120));
}

// Model B (akun jabatan + PIN personal) — aman walaupun halaman tidak memakainya
if (!defined('ABSENSI_PIN_ENFORCE')) {
  define('ABSENSI_PIN_ENFORCE', (int)(getenv('ABSENSI_PIN_ENFORCE') ?: 1)); // 1=wajib PIN utk aksi absensi
}
if (!defined('ABSENSI_REQUIRE_HOLDER')) {
  define('ABSENSI_REQUIRE_HOLDER', (int)(getenv('ABSENSI_REQUIRE_HOLDER') ?: 1)); // 1=wajib holder employee
}

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/schema.php';
// PIN functions (fix: absensi_pin_set undefined)
$pinFile = __DIR__ . '/pin.php';
if (file_exists($pinFile)) {
  require_once $pinFile;
}
require_once __DIR__ . '/user.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/flash.php';

// Ensure schema exists/migrated (idempotent)
absensi_schema_ensure($pdo);

// Current user
$ABS_USER = absensi_current_user($pdo);

// Holder (Model B) — optional
$ABS_HOLDER = null;
if (function_exists('absensi_current_holder')) {
  try { $ABS_HOLDER = absensi_current_holder($pdo, $ABS_USER); } catch (Throwable $e) { $ABS_HOLDER = null; }
}
