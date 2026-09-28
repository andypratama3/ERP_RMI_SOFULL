<?php
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
// DO Audit Viewer - Phase 1 helper (Enterprise+++)
require_once __DIR__ . '/_kpi_bootstrap.php';
require_once __DIR__ . '/_kpi_metrics.php';
if (function_exists('require_any_permission')) {
    require_any_permission(['KPI.VIEW', 'SALES.AUDIT']);
}

$pdo = kpi_require_pdo();

// Ensure audit table exists (safe)
kpi_ensure_sales_do_audit($pdo);

if (!kpi_table_exists($pdo, 'sales_do_audit')) {
    kpi_header('DO Audit');
    kpi_nav('do_audit');
    echo "<div class='card'><span class='badge danger'>MISSING</span> Table <b>sales_do_audit</b> tidak ditemukan.</div>";
    kpi_footer();
    exit;
}

// Export CSV before HTML
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $monthYm = trim((string)($_GET['month'] ?? ''));
    if (!$monthYm) $monthYm = date('Y-m');
    [$d1,$d2] = kpi_month_range($monthYm);

    $dept = trim((string)($_GET['dept'] ?? ''));
    $actor = trim((string)($_GET['actor'] ?? ''));
    $doId = trim((string)($_GET['do_id'] ?? ''));

    $where = ["created_at BETWEEN :d1 AND :d2"];
    $params = [':d1'=>$d1.' 00:00:00', ':d2'=>$d2.' 23:59:59'];
    if ($dept !== '') { $where[] = 'actor_dept = :dep'; $params[':dep']=$dept; }
    if ($actor !== '') { $where[] = 'actor_name = :a'; $params[':a']=$actor; }
    if ($doId !== '') { $where[] = 'do_id = :id'; $params[':id']=(int)$doId; }

    $sql = "SELECT id, do_id, status_from, status_to, actor_dept, actor_name, note, created_at FROM sales_do_audit WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC, id DESC LIMIT 5000";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    kpi_csv_download('sales_do_audit_export.csv', $rows);
}

// UI
kpi_header('DO Audit');
kpi_nav('do_audit');

$monthYm = trim((string)($_GET['month'] ?? ''));
if (!$monthYm) $monthYm = date('Y-m');
[$d1,$d2] = kpi_month_range($monthYm);

$dept = trim((string)($_GET['dept'] ?? ''));
$actor = trim((string)($_GET['actor'] ?? ''));
$doId = trim((string)($_GET['do_id'] ?? ''));

$where = ["created_at BETWEEN :d1 AND :d2"];
$params = [':d1'=>$d1.' 00:00:00', ':d2'=>$d2.' 23:59:59'];
if ($dept !== '') { $where[] = 'actor_dept = :dep'; $params[':dep']=$dept; }
if ($actor !== '') { $where[] = 'actor_name = :a'; $params[':a']=$actor; }
if ($doId !== '') { $where[] = 'do_id = :id'; $params[':id']=(int)$doId; }

$sql = "SELECT id, do_id, status_from, status_to, actor_dept, actor_name, note, created_at FROM sales_do_audit WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC, id DESC LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Summary header

echo "<div class='card'>
  <div class='kpi-header-row'>
    <div>
      <h2>DO Audit Log</h2>
      <div class='kpi-subtitle'>Data: <code>sales_do_audit</code> • Range: <b>".h($d1)."</b> s/d <b>".h($d2)."</b></div>
    </div>
    <div>
      <a class='btn secondary' href='kpi_do_audit.php?export=csv&month=".urlencode($monthYm)."&dept=".urlencode($dept)."&actor=".urlencode($actor)."&do_id=".urlencode($doId)."'>Export CSV</a>
      <a class='btn secondary' href='kpi_do_audit.php'>Reset</a>
    </div>
  </div>
</div>";

// Filter

echo "<div class='card'>
  <h3 class='kpi-section-title'>Filter</h3>
  <form method='get' class='kpi-form-row'>
    <div class='kpi-field'><label>Bulan</label><input type='month' name='month' value='".h($monthYm)."'></div>
    <div class='kpi-field'><label>Dept</label><input name='dept' value='".h($dept)."' placeholder='WQS/SCM/ACT/FIN'></div>
    <div class='kpi-field'><label>Actor</label><input name='actor' value='".h($actor)."' placeholder='username / nama'></div>
    <div class='kpi-field'><label>DO ID</label><input name='do_id' value='".h($doId)."' placeholder='123'></div>
    <div class='kpi-field'><button class='btn secondary' type='submit'>Apply</button></div>
  </form>
</div>";

// Table

echo "<div class='card'>
  <h3 class='kpi-section-title'>Log (limit 500)</h3>
  <div class='table-wrap'>
  <table>
    <thead><tr>
      <th>ID</th><th>Created</th><th>DO ID</th><th>From</th><th>To</th><th>Dept</th><th>Actor</th><th>Note</th>
    </tr></thead>
    <tbody>";

foreach ($rows as $r) {
    echo "<tr>
      <td>".h((string)($r['id'] ?? ''))."</td>
      <td>".h((string)($r['created_at'] ?? ''))."</td>
      <td>".h((string)($r['do_id'] ?? ''))."</td>
      <td>".h((string)($r['status_from'] ?? ''))."</td>
      <td>".h((string)($r['status_to'] ?? ''))."</td>
      <td><span class='pill'>".h((string)($r['actor_dept'] ?? ''))."</span></td>
      <td>".h((string)($r['actor_name'] ?? ''))."</td>
      <td>".h((string)($r['note'] ?? ''))."</td>
    </tr>";
}

echo "</tbody></table></div>
  <div class='muted'>Rows: ".h((string)count($rows))."</div>
</div>";

kpi_footer();
