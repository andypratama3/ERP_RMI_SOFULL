<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.USER_MANAGE', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN']);
}
// backfill_sales_do_audit_stages.php (V2 - multi-time detection per stage)
// Backfill audit stage (WQS/SCM/ACT/FIN) berdasarkan berbagai timestamp/status di sales_do.
// Mode:
// - Preview (default): /sales/backfill_sales_do_audit_stages.php
// - Run insert:         /sales/backfill_sales_do_audit_stages.php?run=1
//
// Aman:
// - Hanya insert untuk dept yang BELUM ada di sales_do_audit per DO.
// - Stage dianggap terjadi jika ADA salah satu timestamp stage (dari beberapa kandidat) ATAU status stage bukan pending.
//
// Kenapa V2?
// - Pada beberapa DB, kolom wqs_picked_at ada tapi selalu NULL, sedangkan wqs_started_at terisi.
//   Versi V1 memilih wqs_picked_at (karena ada) sehingga gagal mendeteksi WQS.
//   V2 mengecek SEMUA kolom kandidat (multi-time), jadi aman.

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

$dbname = 'ERP_RMI_SOFULL';

// credential (NAS: dari .env)
$creds = [
  ['user' => 'root', 'pass' => ''],
];

// TCP targets (NAS: 3306)
$tcp_targets = [
  ['host' => '127.0.0.1', 'port' => 3306],
  ['host' => 'localhost', 'port' => 3306],
];

// unix sockets (Linux/NAS)
$socket_targets = [
  '/tmp/mysql.sock',
  '/var/run/mysqld/mysqld.sock',
];

$pdo = null;
$last_err = null;

// try TCP
foreach ($creds as $c) {
  foreach ($tcp_targets as $t) {
// --- DB (centralized) ---
$pdo = db_pdo();

}
}

// try socket
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
function list_existing_cols(array $all, array $cands): array {
  $out = [];
  foreach ($cands as $c) if (in_array($c, $all, true)) $out[] = $c;
  return $out;
}
function first_existing(array $all, array $cands): ?string {
  foreach ($cands as $c) if (in_array($c, $all, true)) return $c;
  return null;
}
function nonempty_time($v): bool {
  if ($v === null) return false;
  $s = trim((string)$v);
  if ($s === '' || $s === '0000-00-00 00:00:00' || $s === '0000-00-00') return false;
  return true;
}
function stage_happened_multi(array $row, array $time_cols, ?string $status_col, array $truthy_statuses = []): bool {
  foreach ($time_cols as $c) {
    if (array_key_exists($c, $row) && nonempty_time($row[$c])) return true;
  }
  if ($status_col && array_key_exists($status_col, $row)) {
    $s = strtolower(trim((string)$row[$status_col]));
    if ($s !== '' && !in_array($s, ['pending','-','null'], true)) {
      if (!empty($truthy_statuses)) return in_array($s, $truthy_statuses, true);
      return true;
    }
  }
  return false;
}

$sales_cols = cols($pdo, 'sales_do');
$audit_cols = cols($pdo, 'sales_do_audit');
if (empty($sales_cols)) { echo "ERROR: tabel sales_do tidak ditemukan.\n"; exit; }
if (empty($audit_cols)) { echo "ERROR: tabel sales_do_audit tidak ditemukan.\n"; exit; }

// multi-time candidates
$wqs_time_cols = list_existing_cols($sales_cols, ['wqs_started_at','wqs_picked_at','wqs_ready_at','wqs_updated_at']);
$scm_time_cols = list_existing_cols($sales_cols, ['scm_delivered_at','scm_updated_at']);
$act_time_cols = list_existing_cols($sales_cols, ['act_invoiced_at','act_updated_at']);
$fin_time_cols = list_existing_cols($sales_cols, ['fin_paid_at','fin_paid_date','fin_updated_at']);

// status cols
$status_wqs = first_existing($sales_cols, ['status_wqs','wqs_status']);
$status_scm = first_existing($sales_cols, ['status_scm','scm_status']);
$status_act = first_existing($sales_cols, ['status_act','act_status']);
$status_fin = first_existing($sales_cols, ['status_fin','fin_status']);

// general cols
$status_col  = first_existing($sales_cols, ['status']);
$created_col = first_existing($sales_cols, ['created_at']);
$updated_col = first_existing($sales_cols, ['updated_at','last_updated_at']);

$run = isset($_GET['run']) ? (int)$_GET['run'] : 0;

// build SELECT: include detected time/status cols
$select = ["sd.id"];
$select[] = $status_col ? "sd.`$status_col` AS cur_status" : "'' AS cur_status";
$select[] = $created_col ? "sd.`$created_col` AS created_at" : "NULL AS created_at";
$select[] = $updated_col ? "sd.`$updated_col` AS updated_at" : "NULL AS updated_at";

foreach ($wqs_time_cols as $c) $select[] = "sd.`$c` AS `$c`";
foreach ($scm_time_cols as $c) $select[] = "sd.`$c` AS `$c`";
foreach ($act_time_cols as $c) $select[] = "sd.`$c` AS `$c`";
foreach ($fin_time_cols as $c) $select[] = "sd.`$c` AS `$c`";

if ($status_wqs) $select[] = "sd.`$status_wqs` AS `$status_wqs`";
if ($status_scm) $select[] = "sd.`$status_scm` AS `$status_scm`";
if ($status_act) $select[] = "sd.`$status_act` AS `$status_act`";
if ($status_fin) $select[] = "sd.`$status_fin` AS `$status_fin`";

$pdo->exec("SET SESSION group_concat_max_len = 1024000");
$sql = "
  SELECT
    " . implode(",\n    ", $select) . ",
    GROUP_CONCAT(DISTINCT UPPER(a.actor_dept) SEPARATOR ',') AS dept_list
  FROM sales_do sd
  LEFT JOIN sales_do_audit a ON a.do_id = sd.id
  GROUP BY sd.id
  ORDER BY sd.id DESC
";
$rows = $pdo->query($sql)->fetchAll();

$to_insert = [];

foreach ($rows as $r) {
  $dept_list = (string)($r['dept_list'] ?? '');
  $have = [];
  if ($dept_list !== '') {
    foreach (explode(',', $dept_list) as $d) {
      $d = strtoupper(trim($d));
      if ($d !== '') $have[$d] = true;
    }
  }

  $wqs_done = stage_happened_multi($r, $wqs_time_cols, $status_wqs, []);
  $scm_done = stage_happened_multi($r, $scm_time_cols, $status_scm, []);
  $act_done = stage_happened_multi($r, $act_time_cols, $status_act, []);
  $fin_done = stage_happened_multi($r, $fin_time_cols, $status_fin, ['paid','done','closed','finish','finished','completed','complete','ok']);

  $base_time = $r['updated_at'] ?? $r['created_at'] ?? date('Y-m-d H:i:s');

  // choose time per stage (first non-empty in list)
  $pick_time = function(array $cols) use ($r, $base_time) {
    foreach ($cols as $c) if (array_key_exists($c, $r) && nonempty_time($r[$c])) return (string)$r[$c];
    return (string)$base_time;
  };

  if ($wqs_done && !isset($have['WQS'])) {
    $to_insert[] = ['do_id'=>$r['id'],'dept'=>'WQS','time'=>$pick_time($wqs_time_cols),'note'=>'BACKFILL_WQS_STAGE','status_to'=>$r['cur_status']];
  }
  if ($scm_done && !isset($have['SCM'])) {
    $to_insert[] = ['do_id'=>$r['id'],'dept'=>'SCM','time'=>$pick_time($scm_time_cols),'note'=>'BACKFILL_SCM_STAGE','status_to'=>$r['cur_status']];
  }
  if ($act_done && !isset($have['ACT'])) {
    $to_insert[] = ['do_id'=>$r['id'],'dept'=>'ACT','time'=>$pick_time($act_time_cols),'note'=>'BACKFILL_ACT_STAGE','status_to'=>$r['cur_status']];
  }
  if ($fin_done && !isset($have['FIN'])) {
    $to_insert[] = ['do_id'=>$r['id'],'dept'=>'FIN','time'=>$pick_time($fin_time_cols),'note'=>'BACKFILL_FIN_STAGE','status_to'=>$r['cur_status']];
  }
}

echo "BACKFILL STAGES V2 (WQS/SCM/ACT/FIN)\n";
echo "Detected columns:\n";
echo "- wqs_time_cols: " . (empty($wqs_time_cols) ? '-' : implode(', ', $wqs_time_cols)) . "\n";
echo "- scm_time_cols: " . (empty($scm_time_cols) ? '-' : implode(', ', $scm_time_cols)) . "\n";
echo "- act_time_cols: " . (empty($act_time_cols) ? '-' : implode(', ', $act_time_cols)) . "\n";
echo "- fin_time_cols: " . (empty($fin_time_cols) ? '-' : implode(', ', $fin_time_cols)) . "\n";
echo "- status_wqs: " . ($status_wqs ?: '-') . "\n";
echo "- status_scm: " . ($status_scm ?: '-') . "\n";
echo "- status_act: " . ($status_act ?: '-') . "\n";
echo "- status_fin: " . ($status_fin ?: '-') . "\n\n";

echo "Total audit rows yang akan di-insert: " . count($to_insert) . "\n";

if (count($to_insert) > 0) {
  echo "\nPreview (max 20):\n";
  for ($i=0; $i < min(20, count($to_insert)); $i++) {
    $x = $to_insert[$i];
    echo "- do_id={$x['do_id']} dept={$x['dept']} time={$x['time']} note={$x['note']}\n";
  }
  echo "\n";
}

if ($run !== 1) {
  echo "Mode PREVIEW.\n";
  echo "Untuk eksekusi insert, buka:\n";
  echo "  backfill_sales_do_audit_stages.php?run=1\n";
  exit;
}

if (count($to_insert) === 0) {
  echo "Tidak ada yang perlu di-insert.\n";
  exit;
}

echo "Mode RUN (insert)...\n";
$ins = $pdo->prepare("
  INSERT INTO sales_do_audit
    (do_id, status_from, status_to, actor_dept, actor_name, note, created_at)
  VALUES
    (:do_id, :status_from, :status_to, :actor_dept, :actor_name, :note, :created_at)
");

$pdo->beginTransaction();
$inserted = 0;

try {
  foreach ($to_insert as $x) {
    $t = trim((string)$x['time']);
    if ($t === '' || $t === '0000-00-00 00:00:00' || $t === '0000-00-00') $t = date('Y-m-d H:i:s');

    $ins->execute([
      ':do_id'       => (int)$x['do_id'],
      ':status_from' => 'BACKFILL',
      ':status_to'   => (string)$x['status_to'],
      ':actor_dept'  => (string)$x['dept'],
      ':actor_name'  => 'SYSTEM_BACKFILL',
      ':note'        => (string)$x['note'],
      ':created_at'  => $t,
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
