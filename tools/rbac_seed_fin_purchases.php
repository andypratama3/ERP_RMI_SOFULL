<?php
declare(strict_types=1);

/**
 * RBAC Seeder (FIN) — Purchases Finance permissions
 * - Upsert permission codes into rbac_permissions
 * - Grant to FIN|STAFF and FIN|MANAGER in rbac_dept_role_permissions
 *
 * Jalankan sebagai ADMIN/SUPERADMIN/SYS via browser:
 *   /tools/rbac_seed_fin_purchases.php?run=1
 */

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/tools_state_lib.php';
if (is_file(__DIR__ . '/../_shared/rbac.php')) {
  require_once __DIR__ . '/../_shared/rbac.php';
}

if (PHP_SAPI !== 'cli' && function_exists('require_login')) {
  require_login();
}

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();

if (PHP_SAPI !== 'cli' && (!function_exists('rbac_is_privileged_session') || !rbac_is_privileged_session())) {
  http_response_code(403);
  echo "Forbidden: admin only";
  exit;
}

if (!function_exists('rbac_ensure_tables')) {
  http_response_code(500);
  echo "RBAC not available: missing _shared/rbac.php";
  exit;
}

rbac_ensure_tables($pdo);

$argvList = $_SERVER['argv'] ?? [];
$isCheck = in_array('--check', $argvList, true) || (string)($_GET['check'] ?? '') === '1';
$actor = 'SYSTEM';
foreach ($argvList as $arg) {
  if (str_starts_with($arg, '--actor=')) $actor = trim(substr($arg, 8)) ?: 'SYSTEM';
}
if ($actor === 'SYSTEM') {
  $actor = (string)($_SESSION['username'] ?? (getenv('USER') ?: 'SYSTEM'));
}
$statePath = ts_storage_logs_dir() . '/rbac_seed_fin_purchases.state.json';
if ($isCheck) {
  $c1 = (int)$pdo->query("SELECT COUNT(*) FROM rbac_permissions WHERE perm_code='FIN.AP.INVOICE.VIEW'")->fetchColumn();
  $c2 = (int)$pdo->query("SELECT COUNT(*) FROM rbac_dept_role_permissions WHERE dept_code='FIN' AND role_code='MANAGER' AND perm_code='FIN.AP.PAYMENT.APPROVE'")->fetchColumn();
  $applied = ($c1 > 0 && $c2 > 0);
  ts_write_json($statePath, [
    'script' => 'rbac_seed_fin_purchases.php',
    'mode' => 'check',
    'checked_at' => date(DateTimeInterface::ATOM),
    'checked_by' => $actor,
    'applied' => $applied,
    'summary' => $applied ? 'Applied' : 'Not Applied',
  ]);
  echo $applied ? "Applied\n" : "Not Applied\n";
  exit($applied ? 0 : 1);
}

function upsert_perm(PDO $pdo, string $code, string $name, string $module, string $desc=''): void {
  $st = $pdo->prepare("
    INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
    VALUES (:c,:n,:m,:d,1)
    ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1
  ");
  $st->execute([':c'=>$code, ':n'=>$name, ':m'=>$module, ':d'=>$desc]);
}

function grant(PDO $pdo, string $dept, string $role, string $code): void {
  $st = $pdo->prepare("
    INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code)
    VALUES (:d,:r,:p)
    ON DUPLICATE KEY UPDATE perm_code=perm_code
  ");
  $st->execute([':d'=>$dept, ':r'=>$role, ':p'=>$code]);
}

$perms = [
  // Global minimal (safe to upsert)
  ['WF.TASK.VIEW',        'Workflow: View Tasks',           'WF',  'Melihat inbox task workflow'],
  ['WF.OUTBOX.VIEW',      'Workflow: View Outbox',          'WF',  'Melihat antrian notifikasi/outbox'],
  ['DOC.UPLOAD',          'Documents: Upload',              'DOC', 'Upload dokumen (sesuai guard folder)'],
  ['DOC.DOWNLOAD',        'Documents: Download',            'DOC', 'Download dokumen'],
  ['SYS.AUDIT.VIEW',      'System: Audit View',             'SYS', 'Melihat audit log (read-only)'],

  // FIN — AP Invoice
  ['FIN.AP.INVOICE.VIEW',   'FIN: AP Invoice View',         'FIN', 'Melihat daftar invoice AP (supplier/proforma/final/PIB)'],
  ['FIN.AP.INVOICE.CREATE', 'FIN: AP Invoice Create',       'FIN', 'Membuat invoice AP (draft)'],
  ['FIN.AP.INVOICE.EDIT',   'FIN: AP Invoice Edit',         'FIN', 'Edit invoice AP (sebelum posted/locked)'],

  // FIN — AP Payment
  ['FIN.AP.PAYMENT.VIEW',   'FIN: AP Payment View',         'FIN', 'Melihat daftar pembayaran AP'],
  ['FIN.AP.PAYMENT.DRAFT',  'FIN: AP Payment Draft',        'FIN', 'Membuat draft pembayaran AP (termasuk upload bukti bayar)'],
  ['FIN.AP.PAYMENT.APPROVE','FIN: AP Payment Approve',      'FIN', 'Approve/finalize pembayaran AP (opsional bila maker-checker)'],

  // FIN — PIB Payment (khusus invoice_type=PIB)
  ['FIN.PIB.PAY',           'FIN: PIB Pay',                 'FIN', 'Membayar Billing Aju PIB + upload bukti bayar'],

  // FIN — Forwarder invoice/payment
  ['FIN.FORWARDER.INVOICE.CREATE', 'FIN: Forwarder Invoice Create', 'FIN', 'Membuat invoice forwarder (jasa) + upload invoice'],
  ['FIN.FORWARDER.PAYMENT.DRAFT',  'FIN: Forwarder Payment Draft',  'FIN', 'Membuat draft pembayaran forwarder + upload bukti bayar'],
  ['FIN.FORWARDER.PAYMENT.APPROVE','FIN: Forwarder Payment Approve','FIN', 'Approve/finalize pembayaran forwarder (opsional)'],
];

foreach ($perms as [$code,$name,$group,$desc]) {
  upsert_perm($pdo, $code, $name, $group, $desc);
}

$grantStaff = [
  'WF.TASK.VIEW','WF.OUTBOX.VIEW','DOC.UPLOAD','DOC.DOWNLOAD',
  'FIN.AP.INVOICE.VIEW','FIN.AP.INVOICE.CREATE','FIN.AP.INVOICE.EDIT',
  'FIN.AP.PAYMENT.VIEW','FIN.AP.PAYMENT.DRAFT',
  'FIN.PIB.PAY',
  'FIN.FORWARDER.INVOICE.CREATE','FIN.FORWARDER.PAYMENT.DRAFT',
];

$grantMgr = array_merge($grantStaff, [
  'FIN.AP.PAYMENT.APPROVE',
  'FIN.FORWARDER.PAYMENT.APPROVE',
  'SYS.AUDIT.VIEW',
]);

foreach ($grantStaff as $code) { grant($pdo,'FIN','STAFF',$code); }
foreach ($grantMgr as $code)   { grant($pdo,'FIN','MANAGER',$code); }

ts_write_json($statePath, [
  'script' => 'rbac_seed_fin_purchases.php',
  'mode' => 'apply',
  'last_run_at' => date(DateTimeInterface::ATOM),
  'last_run_by' => $actor,
  'ok' => true,
  'applied' => true,
  'summary' => 'FIN seed applied',
]);

echo "OK: FIN purchases permissions seeded.\n";
echo "Granted to FIN|STAFF: ".count($grantStaff)." perms\n";
echo "Granted to FIN|MANAGER: ".count($grantMgr)." perms\n";
