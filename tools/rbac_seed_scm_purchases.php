<?php
/**
 * tools/rbac_seed_scm_purchases.php
 *
 * One-time helper untuk menambahkan permission codes SCM purchases (forwarder) + RBAC Default mapping.
 *
 * Jalankan via browser sebagai ADMIN/SUPERADMIN:
 *   APP_URL/tools/rbac_seed_scm_purchases.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/tools_state_lib.php';

if (PHP_SAPI !== 'cli' && function_exists('require_login')) {
  require_login();
}

// --- Admin guard (simple) ---
$role = strtoupper((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? ''));
if (PHP_SAPI !== 'cli' && !in_array($role, ['ADMIN','SUPERADMIN','SYS'], true)) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "403 Forbidden\nAdmin/Superadmin only.";
  exit;
}

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : null;
if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo "DB connection failed";
  exit;
}

$argvList = $_SERVER['argv'] ?? [];
$isCheck = in_array('--check', $argvList, true) || (string)($_GET['check'] ?? '') === '1';
$actor = 'SYSTEM';
foreach ($argvList as $arg) {
  if (str_starts_with($arg, '--actor=')) $actor = trim(substr($arg, 8)) ?: 'SYSTEM';
}
if ($actor === 'SYSTEM') {
  $actor = (string)($_SESSION['username'] ?? (getenv('USER') ?: 'SYSTEM'));
}
$statePath = ts_storage_logs_dir() . '/rbac_seed_scm_purchases.state.json';
if ($isCheck) {
  $c1 = (int)$pdo->query("SELECT COUNT(*) FROM rbac_permissions WHERE perm_code='SCM.FWD.QUOTES.VIEW'")->fetchColumn();
  $c2 = (int)$pdo->query("SELECT COUNT(*) FROM rbac_dept_role_permissions WHERE dept_code='SCM' AND role_code='MANAGER' AND perm_code='SCM.BL.FINALIZE'")->fetchColumn();
  $applied = ($c1 > 0 && $c2 > 0);
  ts_write_json($statePath, [
    'script' => 'rbac_seed_scm_purchases.php',
    'mode' => 'check',
    'checked_at' => date(DateTimeInterface::ATOM),
    'checked_by' => $actor,
    'applied' => $applied,
    'summary' => $applied ? 'Applied' : 'Not Applied',
  ]);
  header('Content-Type: text/plain; charset=utf-8');
  echo $applied ? "Applied\n" : "Not Applied\n";
  exit($applied ? 0 : 1);
}

// Ensure RBAC tables exist
if (function_exists('rbac_ensure_tables')) {
  rbac_ensure_tables($pdo);
} else {
  // fallback: try to load RBAC lib
  $rbacFile = __DIR__ . '/../_shared/rbac.php';
  if (is_file($rbacFile)) {
    require_once $rbacFile;
    if (function_exists('rbac_ensure_tables')) {
      rbac_ensure_tables($pdo);
    }
  }
}

// --- Permission codes to ensure (minimal untuk SCM forwarder) ---
$perms = [
  // Global docs
  ['code' => 'DOC.UPLOAD', 'label' => 'Upload dokumen (global)'],
  ['code' => 'DOC.DOWNLOAD', 'label' => 'Download dokumen (global)'],

  // SCM forwarder quotes
  ['code' => 'SCM.FWD.QUOTES.VIEW', 'label' => 'SCM View Forwarder Quotes'],
  ['code' => 'SCM.FWD.QUOTES.EDIT', 'label' => 'SCM Edit Forwarder Quotes'],
  ['code' => 'SCM.FWD.QUOTES.APPROVE', 'label' => 'SCM Approve/Lock Forwarder Quote Selection'],

  // SCM BL
  ['code' => 'SCM.BL.VIEW', 'label' => 'SCM View Bill of Lading docs'],
  ['code' => 'SCM.BL.UPLOAD_DRAFT', 'label' => 'SCM Upload BL Draft'],
  ['code' => 'SCM.BL.FINALIZE', 'label' => 'SCM Upload/Finalize BL Final'],

  // SCM shipment tracking
  ['code' => 'SCM.SHIPMENT.TRACK.VIEW', 'label' => 'SCM View Shipment Tracking'],
  ['code' => 'SCM.SHIPMENT.TRACK.UPDATE', 'label' => 'SCM Update Shipment Tracking & Forwarder Fields'],

  // SCM area upload (purchases)
  ['code' => 'SCM.PURCHASES.DOC.UPLOAD', 'label' => 'SCM Upload Purchases Forwarder Docs (QUOTES/BL/TRACKING)'],
];

$insPerm = $pdo->prepare(
  'INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) '
  . 'VALUES (:code, :perm_name, :module, :description, 1) '
  . 'ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1'
);

$addedPerm = 0;
foreach ($perms as $p) {
  $code = strtoupper(trim((string)$p['code']));
  $label = (string)$p['label'];
  $module = (string)(explode('.', $code)[0] ?? 'SCM');
  $insPerm->execute([':code' => $code, ':perm_name' => $label, ':module' => $module, ':description' => $label]);
  $addedPerm++;
}

// --- RBAC Default mapping (docs/BASELINES.md) ---
// Dept: SCM
// Role: STAFF -> view/edit/upload draft & tracking
// Role: MANAGER -> semua STAFF + approve/lock + finalize

$staffAllow = [
  'DOC.UPLOAD',
  'DOC.DOWNLOAD',
  'SCM.FWD.QUOTES.VIEW',
  'SCM.FWD.QUOTES.EDIT',
  'SCM.BL.VIEW',
  'SCM.BL.UPLOAD_DRAFT',
  'SCM.SHIPMENT.TRACK.VIEW',
  'SCM.SHIPMENT.TRACK.UPDATE',
  'SCM.PURCHASES.DOC.UPLOAD',
];

$managerAllow = array_values(array_unique(array_merge($staffAllow, [
  'SCM.FWD.QUOTES.APPROVE',
  'SCM.BL.FINALIZE',
])));

$insMap = $pdo->prepare(
  'INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) '
  . 'VALUES (:dept, :role, :perm, :allow) '
  . 'ON DUPLICATE KEY UPDATE allow_flag = VALUES(allow_flag)'
);

$mapCount = 0;
foreach ($staffAllow as $perm) {
  $insMap->execute([
    ':dept' => 'SCM',
    ':role' => 'STAFF',
    ':perm' => strtoupper($perm),
    ':allow' => 1,
  ]);
  $mapCount++;
}
foreach ($managerAllow as $perm) {
  $insMap->execute([
    ':dept' => 'SCM',
    ':role' => 'MANAGER',
    ':perm' => strtoupper($perm),
    ':allow' => 1,
  ]);
  $mapCount++;
}

header('Content-Type: application/json; charset=utf-8');
ts_write_json($statePath, [
  'script' => 'rbac_seed_scm_purchases.php',
  'mode' => 'apply',
  'last_run_at' => date(DateTimeInterface::ATOM),
  'last_run_by' => $actor,
  'ok' => true,
  'applied' => true,
  'summary' => 'SCM seed applied',
]);
echo json_encode([
  'ok' => true,
  'permissions_upserted' => $addedPerm,
  'dept_role_permissions_upserted' => $mapCount,
  'dept' => 'SCM',
  'roles' => ['STAFF','MANAGER'],
  'notes' => [
    'Jika sistem Anda sudah punya RBAC Default yang berbeda, mapping ini hanya default dan boleh diubah lewat RBAC Center.',
    'SCM tidak diberi DOC.DELETE (delete dokumen admin-only).',
  ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
