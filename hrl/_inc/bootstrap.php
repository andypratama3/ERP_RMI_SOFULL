<?php
// hrl/_inc/bootstrap.php
// Bootstrap + Auth untuk Modul HRL Docs (HR & Legal) - EnterprisePPP Fase 1-3
// - Mengikuti session dari master/login.php
// - DB via master/auth.php (db_pdo)

// ---- Output buffering (fix "headers already sent" saat action melakukan redirect) ----
// Beberapa server/dev environment mematikan output buffering default.
// Kita aktifkan buffering seawal mungkin (sebelum include file lain) agar semua header()/redirect aman.
if (ob_get_level() === 0) { @ob_start(); }

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../master/_audit_master.php';

// Load centralized error logger agar rmi_log_module_error() tersedia di semua halaman HRL
$__hrl_elg = __DIR__ . '/../../_shared/rmi_error_logger.php';
if (is_file($__hrl_elg)) require_once $__hrl_elg;
unset($__hrl_elg);

if (defined('APP_DEBUG') && APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

require_login();

/*
|--------------------------------------------------------------------------
| HRL Docs Access - FIXED NO REGISTRY GATE
|--------------------------------------------------------------------------
| Penyebab Forbidden:
| require_any_permission(['HRL.VIEW','HRL.DOC_VIEW']) memanggil registry dan
| langsung menolak akun yang belum punya permission HRL.DOC_VIEW.
|
| Untuk modul HRL Docs, akses halaman dibuka untuk semua user yang sudah login.
| Hak kelola/approve tetap dikontrol oleh $HRL_CAN_MANAGE dan $HRL_CAN_APPROVE.
*/
$pdo = db_pdo();

unset($__session_role, $__session_level, $__session_dept, $__is_admin, $__is_mgr_hrl, $__is_hrl);

// Helper escape
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Current user (session)
$HRL_USER = [
  'id'         => (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0)),
  'username'   => (string)($_SESSION['username'] ?? ($_SESSION['user']['username'] ?? '')),
  'role'       => strtoupper((string)($_SESSION['role'] ?? ($_SESSION['user']['role'] ?? ''))),
  'level'      => strtoupper((string)($_SESSION['level'] ?? ($_SESSION['user']['level'] ?? ''))),
  'department' => strtoupper((string)(
      $_SESSION['department']
      ?? $_SESSION['dept']
      ?? $_SESSION['user']['department']
      ?? $_SESSION['user']['dept']
      ?? ''
  )),
  'office_code'=> strtoupper((string)(
      $_SESSION['office_code']
      ?? $_SESSION['office']
      ?? $_SESSION['user']['office_code']
      ?? $_SESSION['user']['office']
      ?? ''
  )),
];

$HRL_IS_ADMIN = in_array($HRL_USER['role'], ['ADMIN','SUPERADMIN','SYS'], true)
             || in_array($HRL_USER['level'], ['ADMIN','SUPERADMIN','SYS'], true);

$HRL_IS_HRL   = ($HRL_USER['department'] === 'HRL');


$HRL_IS_MGR_HRL = (
    in_array($HRL_USER['level'], ['MANAGER','MGR'], true)
    || in_array($HRL_USER['role'], ['MANAGER','MGR'], true)
) && (
    $HRL_USER['department'] === 'HRL'
    || stripos($HRL_USER['username'], 'HRL') !== false
);
$HRL_CAN_MANAGE  = ($HRL_IS_ADMIN || $HRL_IS_HRL || $HRL_IS_MGR_HRL);

// Manager untuk approval (boleh disesuaikan)
$HRL_IS_MANAGER = in_array($HRL_USER['role'], ['MANAGER'], true)
               || in_array($HRL_USER['level'], ['MANAGER'], true);

$HRL_CAN_APPROVE = ($HRL_IS_ADMIN || ($HRL_IS_HRL && $HRL_IS_MANAGER) || $HRL_IS_MGR_HRL);

// CSRF
if (empty($_SESSION['csrf_token']) && function_exists('csrf_token')) {
  // Prefer token generator from global auth helper if available.
  $_SESSION['csrf_token'] = (string)csrf_token();
}
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Keep legacy/global key in sync to avoid cross-module mismatch.
if (empty($_SESSION['_csrf']) || !hash_equals((string)$_SESSION['_csrf'], (string)$_SESSION['csrf_token'])) {
  $_SESSION['_csrf'] = (string)$_SESSION['csrf_token'];
}
$CSRF_TOKEN = (string)$_SESSION['csrf_token'];

function csrf_check_or_die(): void {
  $token = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? $_POST['csrf'] ?? '');
  $expected = (string)($_SESSION['csrf_token'] ?? '');
  $expectedLegacy = (string)($_SESSION['_csrf'] ?? '');
  $ok = (
    $token !== '' &&
    (
      ($expected !== '' && hash_equals($expected, $token)) ||
      ($expectedLegacy !== '' && hash_equals($expectedLegacy, $token))
    )
  );
  if (!$ok) {
    http_response_code(400);
    echo "<h3>Bad Request</h3><p>CSRF token tidak valid.</p>";
    exit;
  }
}

// Flash helpers
function flash_set(string $type, string $msg): void {
  $_SESSION['hrl_flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get(): ?array {
  $f = $_SESSION['hrl_flash'] ?? null;
  unset($_SESSION['hrl_flash']);
  return $f ?: null;
}

// Redirect helper (bersihkan output buffer agar tidak muncul warning/halaman setengah jadi)
function hrl_redirect(string $url): void {
  // Redirect aman: bersihkan buffer sebelum header(). Jika headers sudah terlanjur terkirim,
  // fallback pakai JS/meta refresh (supaya tidak blank putih / error semua tombol).
  if (!headers_sent()) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Location: ' . $url);
    exit;
  }
  $safe = htmlspecialchars($url, ENT_QUOTES);
  echo '<script>location.href=' . json_encode($url) . ';</script>';
  echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safe . '"></noscript>';
  exit;
}


// Base project path helper (agar link tidak Not Found saat project ada di subfolder)
// Base project path helper (agar link tidak Not Found saat project ada di subfolder)
function base_project(): string {
  // Jika ada global BASE_PROJECT, pakai tapi normalkan (hindari kasus salah set ke "/hrl" dsb).
  $g = (string)($GLOBALS['BASE_PROJECT'] ?? '');
  if ($g !== '') {
    $g = rtrim($g, '/');
    // normalisasi jika salah set ke folder modul
    $g = preg_replace('~/(hrl|master|absensi|mpr|crm|pqp|fin|scm|api)$~', '', $g);
    $g = rtrim($g, '/');
    return $g;
  }

  $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  foreach (['/master/','/absensi/','/mpr/','/hrl/','/crm/','/pqp/','/fin/','/scm/','/api/'] as $marker) {
    $pos = strpos($sn, $marker);
    if ($pos !== false) return substr($sn, 0, $pos);
  }
  $d = rtrim(dirname($sn), '/');
  return $d === '/' ? '' : $d;
}

// URL helper untuk halaman HRL: pakai folder tempat script berjalan (lebih aman dari BASE_PROJECT)
function url_hrl(string $path): string {
  $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  $dir = rtrim(dirname($sn), '/');
  if ($dir === '/' || $dir === '\\') $dir = '';
  return $dir . '/' . ltrim($path, '/');
}

function url_master(string $path): string {
  $base = rtrim(base_project(), '/');
  return $base . '/master/' . ltrim($path, '/');
}

function url_logout(): string {
  return url_master('logout.php');
}
function url_home(): string {
  $base = rtrim(base_project(), '/');
  return $base . '/';
}

// Audit log (JSON lines) — dual-write ke file + system_audit_logs
function hrl_audit_append(string $action, string $entity, array $meta = [], ?PDO $pdo = null): void {
  // IP asli: utamakan X-Forwarded-For (di balik proxy/Cloudflare)
  $rawIp   = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
  $ipParts = explode(',', $rawIp);
  $clientIp = trim((string)($ipParts[0] ?? ''));
  $ua       = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $username = (string)($_SESSION['username'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');
  $level    = strtoupper((string)($_SESSION['level'] ?? ''));
  $userId   = (int)($_SESSION['user_id'] ?? 0);

  // 1) Tulis ke file (legacy — tetap dipertahankan)
  $dir = __DIR__ . '/../../uploads/audit_logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $row = [
    'ts'       => date('c'),
    'action'   => $action,
    'entity'   => $entity,
    'username' => $username,
    'role'     => $role,
    'dept'     => (string)($_SESSION['department'] ?? $_SESSION['dept'] ?? $_SESSION['user']['department'] ?? $_SESSION['user']['dept'] ?? ''),
    'office'   => (string)($_SESSION['office_code'] ?? $_SESSION['office'] ?? $_SESSION['user']['office_code'] ?? $_SESSION['user']['office'] ?? ''),
    'ip'       => $clientIp,
    'ua'       => $ua,
    'meta'     => $meta,
  ];
  $file = $dir . '/audit_hrl_docs.log';
  @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

  // 2) Dual-write ke system_audit_logs agar tampil di Audit Log UI
  if ($pdo !== null) {
    try {
      if (function_exists('master_audit_ensure_table')) master_audit_ensure_table($pdo);
      $descr = "HRL — {$action}: {$entity}";
      $pdo->prepare("
        INSERT INTO system_audit_logs
          (module, action, record_table, record_code, description, details,
           user_id, username, role, level, ip, user_agent, created_at)
        VALUES ('hrl',?,?,?,?,?,?,?,?,?,?,?,NOW())
      ")->execute([
        strtoupper($action),
        'hrl_docs',
        $entity,
        $descr,
        json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $userId,
        $username,
        strtoupper($role),
        $level,
        substr($clientIp, 0, 45),
        substr($ua, 0, 255),
      ]);
    } catch (Throwable $e) { /* fail-soft */ }
  }
}

// DB helpers
function db_col_exists(PDO $pdo, string $table, string $col): bool {
  $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c";
  $st = $pdo->prepare($sql);
  $st->execute([':t'=>$table, ':c'=>$col]);
  return (int)$st->fetchColumn() > 0;
}

function hrl_current_employee_code(PDO $pdo): ?string {
  static $cached = null;
  static $checked = false;

  if ($cached !== null) return $cached;
  if ($checked) return null;
  $checked = true;

  // Ambil dari master_system_login.holder_employee_code kalau ada
  if (!db_col_exists($pdo, 'master_system_login', 'holder_employee_code')) return null;

  $u = (string)($_SESSION['username'] ?? '');
  if ($u === '') return null;

  $st = $pdo->prepare("SELECT holder_employee_code FROM master_system_login WHERE username = ? LIMIT 1");
  $st->execute([$u]);
  $v = (string)($st->fetchColumn() ?? '');
  $v = trim($v);
  if ($v === '') return null;

  $cached = $v;
  return $cached;
}

function hrl_require_manage(): void {
  if (!(bool)($GLOBALS['HRL_CAN_MANAGE'] ?? false)) {
    http_response_code(403);
    echo "<h3>Akses ditolak</h3><p>Hanya ADMIN/SYS, Departemen HRL, atau Manager HRL yang boleh mengelola dokumen.</p>";
    exit;
  }
}
function hrl_require_approve(): void {
  if (!(bool)($GLOBALS['HRL_CAN_APPROVE'] ?? false)) {
    http_response_code(403);
    echo "<h3>Akses ditolak</h3><p>Hanya MANAGER HRL atau ADMIN/SYS yang boleh approve/reject.</p>";
    exit;
  }
}
