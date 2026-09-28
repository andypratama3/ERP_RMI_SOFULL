<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.USER_MANAGE', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN']);
}
// backfill_sales_do_audit_crm.php (V2 - DB super fallback: TCP + unix_socket)
// Backfill audit CRM untuk DO lama yang belum punya jejak di sales_do_audit.
// Mode:
// - Preview (default): /sales/backfill_sales_do_audit_crm.php
// - Run insert:         /sales/backfill_sales_do_audit_crm.php?run=1
//
// Aman:
// - Hanya insert untuk DO yang BELUM punya audit actor_dept='CRM'.
// - Jika tabel/kolom tidak ada, tampilkan error jelas.

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

// -------------------------
// DB connect (SUPER robust)
// -------------------------
$dbname = 'ERP_RMI_SOFULL';

// credential umum (NAS: config dari .env)
$creds = [
  ['user' => 'root', 'pass' => ''],
];

// TCP hosts/ports (NAS: 3306)
$tcp_targets = [
  ['host' => '127.0.0.1', 'port' => 3306],
  ['host' => 'localhost', 'port' => 3306],
];

// unix socket path (Linux/NAS)
$socket_targets = [
  '/tmp/mysql.sock',
  '/var/run/mysqld/mysqld.sock',
];

$pdo = null;
$last_err = null;

// 1) coba TCP dulu
foreach ($creds as $c) {
  foreach ($tcp_targets as $t) {
// --- DB (centralized) ---
$pdo = db_pdo();

}
}

// 2) kalau TCP gagal, coba unix_socket
if (!$pdo) {
  foreach ($creds as $c) {
    foreach ($socket_targets as $sock) {
      if (!file_exists($sock)) continue;
      try {
// --- DB (centralized) ---
$pdo = db_pdo();

break 2;
      } catch (PDOException $e) {
        $last_err = $e->getMessage();
        $pdo = null;
      }
    }
  }
}

if (!$pdo) {
  http_response_code(500);
  echo "Koneksi database gagal.\n";
  echo "Last error: {$last_err}\n";
  echo "\nTips cepat:\n";
  echo "- Pastikan MySQL service nyala (port 3306).\n";
  echo "- Kalau kamu tahu port pasti, edit file ini dan set hanya 1 target.\n";
  exit;
}

function cols(PDO $pdo, string $table): array {
  try {
    $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[] = (string)$r['Field'];
    return $out;
  } catch (Throwable $e) { return []; }
}

$sales_cols = cols($pdo, 'sales_do');
$audit_cols = cols($pdo, 'sales_do_audit');

if (empty($sales_cols)) {
  echo "ERROR: tabel sales_do tidak ditemukan.\n";
  exit;
}
if (empty($audit_cols)) {
  echo "ERROR: tabel sales_do_audit tidak ditemukan.\n";
  exit;
}

$required = ['do_id','status_from','status_to','actor_dept','actor_name','note','created_at'];
$missing_req = [];
foreach ($required as $c) if (!in_array($c, $audit_cols, true)) $missing_req[] = $c;
if (!empty($missing_req)) {
  echo "ERROR: kolom wajib sales_do_audit tidak lengkap. Missing: " . implode(', ', $missing_req) . "\n";
  exit;
}

$status_col = in_array('status', $sales_cols, true) ? 'status' : null;
$created_at_col = in_array('created_at', $sales_cols, true) ? 'created_at' : null;

$run = isset($_GET['run']) ? (int)$_GET['run'] : 0;

// cari DO yang belum ada audit CRM
$sql = "
  SELECT sd.id, " . ($status_col ? "sd.`$status_col` AS status" : "'' AS status") . ",
         " . ($created_at_col ? "sd.`$created_at_col` AS created_at" : "NULL AS created_at") . "
  FROM sales_do sd
  LEFT JOIN sales_do_audit a
    ON a.do_id = sd.id AND UPPER(a.actor_dept) = 'CRM'
  WHERE a.id IS NULL
  ORDER BY sd.id DESC
";

$rows = $pdo->query($sql)->fetchAll();
$total = count($rows);

echo "BACKFILL CRM AUDIT\n";
echo "Total DO tanpa audit CRM: {$total}\n\n";

if ($total === 0) {
  echo "Tidak ada yang perlu di-backfill.\n";
  exit;
}

// preview 10
echo "Preview (max 10):\n";
for ($i=0; $i < min(10, $total); $i++) {
  $r = $rows[$i];
  echo "- do_id={$r['id']} status={$r['status']} created_at={$r['created_at']}\n";
}
echo "\n";

if ($run !== 1) {
  echo "Mode PREVIEW.\n";
  echo "Untuk eksekusi insert, buka:\n";
  echo "  backfill_sales_do_audit_crm.php?run=1\n";
  exit;
}

// run insert
echo "Mode RUN (insert)...\n";
$ins = $pdo->prepare("
  INSERT INTO sales_do_audit
    (do_id, status_from, status_to, actor_dept, actor_name, note, created_at)
  VALUES
    (:do_id, :status_from, :status_to, :actor_dept, :actor_name, :note, :created_at)
");

$inserted = 0;
$pdo->beginTransaction();

try {
  foreach ($rows as $r) {
    $do_id = (int)$r['id'];
    $status_to = (string)($r['status'] ?? '');
    $created_at = $r['created_at'];

    if ($created_at === null || trim((string)$created_at) === '' || $created_at === '0000-00-00 00:00:00' || $created_at === '0000-00-00') {
      $created_at = date('Y-m-d H:i:s');
    }

    $ins->execute([
      ':do_id'        => $do_id,
      ':status_from'  => 'BACKFILL',
      ':status_to'    => $status_to,
      ':actor_dept'   => 'CRM',
      ':actor_name'   => 'SYSTEM_BACKFILL',
      ':note'         => 'BACKFILL_CRM_AUDIT',
      ':created_at'   => $created_at,
    ]);
    $inserted++;
  }

  $pdo->commit();
  echo "SUKSES. Inserted: {$inserted}\n";
  echo "Selanjutnya: export ulang KPI CSV.\n";
} catch (Throwable $e) {
  $pdo->rollBack();
  echo "GAGAL. Error: " . $e->getMessage() . "\n";
  exit;
}
