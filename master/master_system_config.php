<?php
// master/master_system_config.php
// System Config (read-only) – GO LIVE safe page
// - Wajib login
// - Privileged only (ADMIN/SUPERADMIN/SYS) atau permission RBAC: MASTER_SYSTEM.CONFIG_VIEW

declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/auth.php';

require_login();

$dbError = '';
try {
    $pdo = db_pdo();
} catch (Throwable $e) {
    // Jangan hentikan halaman; fallback read-only tanpa RBAC DB
    $pdo = null;
    $dbError = 'DB connection failed: ' . $e->getMessage();
}

if ($pdo instanceof PDO) {
    try { rbac_ensure_tables($pdo); } catch (Throwable $e) {}
}

// RBAC: izinkan via permission; fallback: privileged; terakhir: logged-in (read-only page)
$rbacAvailable = ($pdo instanceof PDO) && (function_exists('rbac_has') || function_exists('rbac_require'));
$hasPermission = false;

if ($rbacAvailable) {
    try {
        if (function_exists('rbac_has')) {
            $hasPermission = (bool) rbac_has($pdo, 'MASTER_SYSTEM.CONFIG_VIEW');
        } else {
            rbac_require($pdo, 'MASTER_SYSTEM.CONFIG_VIEW');
            $hasPermission = true;
        }
    } catch (Throwable $e) {
        $hasPermission = false;
    }
}

$isPrivileged = false;
if (function_exists('rbac_is_privileged_session') && rbac_is_privileged_session()) {
    $isPrivileged = true;
} else {
    $role  = function_exists('auth_role')  ? strtoupper((string) auth_role())  : '';
    $dept  = function_exists('auth_dept')  ? strtoupper((string) auth_dept())  : '';
    $level = function_exists('auth_level') ? strtoupper((string) auth_level()) : '';
    $isPrivileged = in_array($role,  ['ADMIN','SUPERADMIN','SYS'], true)
                 || in_array($level, ['SYS'], true)
                 || $dept === 'SYS';
}

// Hard gate — SYS atau punya permission MASTER_SYSTEM.CONFIG_VIEW.
// Ini berlaku MESKIPUN require_rbac() di require_login() gagal (defense-in-depth).
if (!$isPrivileged && !$hasPermission) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $actor = htmlspecialchars((string)($_SESSION['username'] ?? '-'), ENT_QUOTES, 'UTF-8');
    echo <<<HTML
    <!doctype html><html lang="id"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Akses Ditolak</title>
    <style>body{font-family:system-ui,Arial;background:#0b1220;color:#e8eefc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
    .box{background:#111a2e;border:1px solid rgba(239,68,68,.35);border-radius:12px;padding:28px 32px;max-width:420px;text-align:center}
    h2{color:#f87171;margin:0 0 10px}code{background:rgba(255,255,255,.1);padding:2px 6px;border-radius:4px}</style>
    </head><body><div class="box">
    <h2>Akses Ditolak</h2>
    <p>Halaman ini hanya untuk <b>SYS</b> atau user dengan permission<br><code>MASTER_SYSTEM.CONFIG_VIEW</code>.</p>
    <p style="opacity:.6;font-size:13px">User: <b>{$actor}</b></p>
    </div></body></html>
    HTML;
    exit;
}

// HTML response headers
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$esc = function($v){
    if (function_exists('rmi_h')) return rmi_h($v);
    if (function_exists('h')) return h($v);
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$baseProject = defined('BASE_PROJECT') ? (string)BASE_PROJECT : (string)($GLOBALS['BASE_PROJECT'] ?? '');
$env = defined('RMI_ENV') ? (string)RMI_ENV : (getenv('RMI_ENV') ?: '');
$appDebug = (getenv('APP_DEBUG') ?: '') === '1' ? '1' : '0';

// DB config (mask password)
$dbHost = defined('DB_HOST') ? (string)DB_HOST : (string)($GLOBALS['DB_HOST'] ?? '');
$dbPort = defined('DB_PORT') ? (string)DB_PORT : (string)($GLOBALS['DB_PORT'] ?? '');
$dbName = defined('DB_NAME') ? (string)DB_NAME : (string)($GLOBALS['DB_NAME'] ?? '');
$dbUser = defined('DB_USER') ? (string)DB_USER : (string)($GLOBALS['DB_USER'] ?? '');

$rows = [
    ['BASE_PROJECT', $baseProject],
    ['RMI_ENV', $env],
    ['APP_DEBUG', $appDebug],
    ['PHP_VERSION', PHP_VERSION],
    ['DB_HOST', $dbHost],
    ['DB_PORT', $dbPort],
    ['DB_NAME', $dbName],
    ['DB_USER', $dbUser],
];

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('System Config', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'System Config',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">System Config</h3>
      <div class="text-muted small">Read-only • Privileged</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="<?= $esc(function_exists('rmi_app_url') ? rmi_app_url('index.php') : (($baseProject ?: '') . '/index.php')) ?>">Dashboard Center</a>
      <a class="btn btn-outline-secondary" href="<?= $esc(function_exists('rmi_app_url') ? rmi_app_url('master/master_data.php') : (($baseProject ?: '') . '/master/master_data.php')) ?>">Master Data</a>
    </div>
  </div>

  <?php if (!empty($dbError)): ?>
    <div class="alert alert-warning">
      <?= $esc($dbError) ?> — Halaman tetap ditampilkan (read-only) tanpa verifikasi RBAC dari DB.
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
          <thead>
            <tr>
              <th style="width:30%">Key</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><code><?= $esc($r[0]) ?></code></td>
              <td><?= $esc($r[1]) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="mt-3">
        <div class="alert alert-warning mb-0">
          Password DB tidak ditampilkan. Perubahan konfigurasi dilakukan via <code>config.php</code> / ENV.
        </div>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
