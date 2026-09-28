<?php
declare(strict_types=1);

/**
 * RBAC Seeder (ACT) — Purchases Ceisa/PIB permissions
 * Jalankan sebagai ADMIN/SUPERADMIN/SYS via browser:
 *   /tools/rbac_seed_act_purchases.php?run=1
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
$statePath = ts_storage_logs_dir() . '/rbac_seed_act_purchases.state.json';

if ($isCheck) {
  $c1 = (int)$pdo->query("SELECT COUNT(*) FROM rbac_permissions WHERE perm_code='ACT.CEISA.VIEW'")->fetchColumn();
  $c2 = (int)$pdo->query("SELECT COUNT(*) FROM rbac_dept_role_permissions WHERE dept_code='ACT' AND role_code='MANAGER' AND perm_code='ACT.GL.POST'")->fetchColumn();
  $applied = ($c1 > 0 && $c2 > 0);
  ts_write_json($statePath, [
    'script' => 'rbac_seed_act_purchases.php',
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

  // ACT — Ceisa / PIB
  ['ACT.CEISA.VIEW',          'ACT: Ceisa View',            'ACT', 'Melihat dokumen & status PIB/Ceisa'],
  ['ACT.CEISA.CREATE',        'ACT: Ceisa Create',          'ACT', 'Membuat draft PIB'],
  ['ACT.CEISA.SUBMIT',        'ACT: Ceisa Submit',          'ACT', 'Submit PIB ke Ceisa (pertama kali)'],
  ['ACT.CEISA.RESUBMIT',      'ACT: Ceisa Re-Submit',       'ACT', 'Resubmit PIB saat reject (wajib audit note)'],
  ['ACT.CEISA.UPDATE_STATUS', 'ACT: Ceisa Update Status',   'ACT', 'Update status (arrived, accepted, rejected, billing issued, SPPB, final PIB)'],
  ['ACT.CEISA.AUDIT_NOTE',    'ACT: Ceisa Audit Note',      'ACT', 'Wajib isi note saat reject/resubmit untuk audit trail'],

  // ACT — Accounting hard rules
  ['ACT.GL.POST',        'ACT: GL Post',                   'ACT', 'Posting jurnal (immutable; audit wajib)'],
  ['ACT.PERIOD.CLOSE',   'ACT: Period Close',              'ACT', 'Tutup periode akuntansi (audit wajib)'],
  ['ACT.REVERSAL.CREATE','ACT: Reversal Create',           'ACT', 'Buat reversal/cancel untuk transaksi posted'],
];

foreach ($perms as [$code,$name,$group,$desc]) {
  upsert_perm($pdo, $code, $name, $group, $desc);
}

$grantStaff = [
  'WF.TASK.VIEW','WF.OUTBOX.VIEW','DOC.UPLOAD','DOC.DOWNLOAD',
  'ACT.CEISA.VIEW','ACT.CEISA.CREATE','ACT.CEISA.SUBMIT','ACT.CEISA.RESUBMIT','ACT.CEISA.UPDATE_STATUS','ACT.CEISA.AUDIT_NOTE',
];

$grantMgr = array_merge($grantStaff, [
  'ACT.GL.POST','ACT.PERIOD.CLOSE','ACT.REVERSAL.CREATE','SYS.AUDIT.VIEW'
]);

foreach ($grantStaff as $code) { grant($pdo,'ACT','STAFF',$code); }
foreach ($grantMgr as $code)   { grant($pdo,'ACT','MANAGER',$code); }

ts_write_json($statePath, [
  'script' => 'rbac_seed_act_purchases.php',
  'mode' => 'apply',
  'last_run_at' => date(DateTimeInterface::ATOM),
  'last_run_by' => $actor,
  'ok' => true,
  'applied' => true,
  'summary' => 'ACT seed applied',
]);

echo "OK: ACT purchases permissions seeded.\n";
echo "Granted to ACT|STAFF: ".count($grantStaff)." perms\n";
echo "Granted to ACT|MANAGER: ".count($grantMgr)." perms\n";
