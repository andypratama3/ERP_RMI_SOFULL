<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['KPI.VIEW', 'SALES.AUDIT', 'SALES.VIEW']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','MANAGER','CRM']);
}
// sales/kpi_do_audit.php
require_once __DIR__ . '/_do_office_scope.php';
$pdo = db_pdo();

if (!function_exists('h')) {
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// ensure audit table exists (fail-soft)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    do_id INT NOT NULL,
    status_from VARCHAR(50) NULL,
    status_to VARCHAR(50) NULL,
    actor_dept VARCHAR(50) NULL,
    actor_name VARCHAR(100) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_do_id (do_id),
    KEY idx_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {}

// ---- Filters ----
$q        = trim((string)($_GET['q'] ?? '')); // DO/Tracking
$dept     = trim((string)($_GET['dept'] ?? '')); // WQS/SCM/ACT/FIN
$st_from  = trim((string)($_GET['from'] ?? ''));
$st_to    = trim((string)($_GET['to'] ?? ''));
$d1       = trim((string)($_GET['d1'] ?? ''));
$d2       = trim((string)($_GET['d2'] ?? ''));

if ($d1 === '') $d1 = date('Y-m-01');
if ($d2 === '') $d2 = date('Y-m-d');

$where = [];
$params = [];

// date range on created_at
$where[] = "a.created_at BETWEEN ? AND ?";
$params[] = $d1 . " 00:00:00";
$params[] = $d2 . " 23:59:59";

if ($dept !== '') { $where[] = "a.actor_dept = ?"; $params[] = $dept; }
if ($st_from !== '') { $where[] = "a.status_from = ?"; $params[] = $st_from; }
if ($st_to !== '') { $where[] = "a.status_to = ?"; $params[] = $st_to; }
// Scope: BRANCH hanya lihat audit DO kantornya sendiri
if ($DO_SCOPE_OFFICE !== null) { $where[] = "d.office_code = ?"; $params[] = $DO_SCOPE_OFFICE; }

// Search by do_code or tracking_code
if ($q !== '') {
  $where[] = "(d.do_code LIKE ? OR d.tracking_code LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// ---- Data (Audit rows + DO header join) ----
$sql = "
SELECT
  a.id,
  a.created_at,
  a.do_id,
  d.do_code,
  COALESCE(d.tracking_code, d.do_code) AS tracking_code,
  d.do_date,
  d.customers_code,
  d.office_code,
  d.grand_total,
  a.status_from,
  a.status_to,
  a.actor_dept,
  a.actor_name,
  a.note
FROM sales_do_audit a
LEFT JOIN sales_do d ON d.id = a.do_id
{$whereSql}
ORDER BY a.created_at DESC, a.id DESC
LIMIT 5000
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---- Summary (avg duration per dept) ----
// Compute durations between status changes per do_id using audit rows ordered asc
$sum = ['WQS'=>['n'=>0,'sec'=>0],'SCM'=>['n'=>0,'sec'=>0],'ACT'=>['n'=>0,'sec'=>0],'FIN'=>['n'=>0,'sec'=>0]];
$byDo = [];
foreach (array_reverse($rows) as $r) { // reverse to ASC within limited set
  $did = (int)$r['do_id'];
  if (!$did) continue;
  $byDo[$did][] = $r;
}
foreach ($byDo as $did => $logs) {
  // logs in ASC order by created_at due to reverse of desc set (best-effort)
  // measure key durations:
  // WQS: wqs_processing -> ready_scm
  // SCM: on_delivery -> delivered
  // ACT: delivered -> wait_payment
  // FIN: wait_payment -> paid
  $t = [];
  foreach ($logs as $l) {
    $to = (string)$l['status_to'];
    $t[$to][] = strtotime((string)$l['created_at']);
  }
  // helper: pick first timestamp for a status_to
  $first = function($key) use ($t) {
    return isset($t[$key]) ? (int)$t[$key][0] : 0;
  };
  $wqs_start = $first('wqs_processing');
  $wqs_end   = $first('ready_scm');
  if ($wqs_start && $wqs_end && $wqs_end >= $wqs_start) { $sum['WQS']['n']++; $sum['WQS']['sec'] += ($wqs_end-$wqs_start); }

  $scm_start = $first('on_delivery');
  $scm_end   = $first('delivered');
  if ($scm_start && $scm_end && $scm_end >= $scm_start) { $sum['SCM']['n']++; $sum['SCM']['sec'] += ($scm_end-$scm_start); }

  $act_start = $first('delivered');
  $act_end   = $first('wait_payment');
  if ($act_start && $act_end && $act_end >= $act_start) { $sum['ACT']['n']++; $sum['ACT']['sec'] += ($act_end-$act_start); }

  $fin_start = $first('wait_payment');
  $fin_end   = $first('paid');
  if ($fin_start && $fin_end && $fin_end >= $fin_start) { $sum['FIN']['n']++; $sum['FIN']['sec'] += ($fin_end-$fin_start); }
}

function fmtDur($sec) {
  $sec = (int)$sec;
  if ($sec <= 0) return "-";
  $d = intdiv($sec, 86400); $sec%=86400;
  $h = intdiv($sec, 3600);  $sec%=3600;
  $m = intdiv($sec, 60);
  if ($d>0) return "{$d}d {$h}h {$m}m";
  if ($h>0) return "{$h}h {$m}m";
  return "{$m}m";
}
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('KPI DO - Audit Viewer', [
  'active' => 'sales',
  'breadcrumbs' => [
    ['label' => 'Sales (CRM)', 'url' => $baseProject . '/sales/sales_dashboard.php'],
    'KPI DO - Audit Viewer',
  ],
  'extra_head' => '<style>
:root{--bg:#0b1220;--card:#111827;--line:rgba(255,255,255,.08);--text:#e5e7eb;--muted:#94a3b8}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(1200px 600px at 50% -10%, rgba(96,165,250,.15), transparent), var(--bg);color:var(--text);font-family:system-ui}
.wrap{max-width:1180px;margin:22px auto;padding:0 14px}
.top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}
h1{margin:0 0 6px;font-size:20px}
.sub{color:var(--muted);font-size:12px}
.card{background:linear-gradient(180deg, rgba(255,255,255,.04), transparent), var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
.grid{display:grid;grid-template-columns:1fr;gap:12px}
.filters{display:grid;grid-template-columns:1.2fr .8fr .8fr .9fr .9fr auto;gap:10px;align-items:end}
.field{width:100%;background:#0b1220;border:1px solid rgba(255,255,255,.14);color:var(--text);border-radius:10px;padding:8px 10px;font-size:12px}
.btn{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:var(--text);padding:9px 12px;border-radius:10px;font-size:12px;text-decoration:none;cursor:pointer}
.btn:hover{background:rgba(255,255,255,.08)}
.table-wrap{overflow:auto;border-radius:14px}
table{width:100%;border-collapse:separate;border-spacing:0;min-width:1120px}
thead th{position:sticky;top:0;background:rgba(15,23,42,.95);color:#cbd5e1;font-weight:600;font-size:12px;text-align:left;padding:10px;border-bottom:1px solid var(--line)}
tbody td{padding:10px;border-bottom:1px solid var(--line);font-size:12px;vertical-align:top}
tbody tr:hover{background:rgba(255,255,255,.03)}
.tag{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-size:11px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04)}
.muted{color:var(--muted)}
.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.kpi .box{border:1px solid var(--line);border-radius:12px;padding:12px;background:rgba(255,255,255,.03)}
.kpi .box b{display:block;margin-bottom:6px}
.small{font-size:11px}
</style>',
]);
?>

<div class="wrap">
  <div class="top">
    <div>
      <h1>KPI DO – Audit Viewer</h1>
      <div class="sub">Read-only. Sumber data: <code>sales_do_audit</code> (max 5000 logs dalam filter).</div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn" href="sales_dashboard.php">« Sales Dashboard</a>
      <a class="btn" href="../stock/wqs_do_tasks.php">WQS</a>
      <a class="btn" href="scm_do_tasks.php">SCM</a>
      <a class="btn" href="act_do_tasks.php">ACT</a>
      <a class="btn" href="fin_do_tasks.php">FIN</a>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <form method="get" class="filters">
        <div>
          <label class="small muted">Cari DO/Tracking</label>
          <input class="field" name="q" value="<?php echo h($q); ?>" placeholder="contoh: DO-2025-0001 / TRK-...">
        </div>
        <div>
          <label class="small muted">Dept</label>
          <select class="field" name="dept">
            <option value="">All</option>
            <?php foreach (['WQS','SCM','ACT','FIN'] as $d): ?>
              <option value="<?php echo h($d); ?>" <?php echo $dept===$d?'selected':''; ?>><?php echo h($d); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="small muted">Status From</label>
          <input class="field" name="from" value="<?php echo h($st_from); ?>" placeholder="mis: delivered">
        </div>
        <div>
          <label class="small muted">Status To</label>
          <input class="field" name="to" value="<?php echo h($st_to); ?>" placeholder="mis: wait_payment">
        </div>
        <div>
          <label class="small muted">Tanggal</label>
          <div style="display:flex;gap:8px">
            <input class="field" type="date" name="d1" value="<?php echo h($d1); ?>">
            <input class="field" type="date" name="d2" value="<?php echo h($d2); ?>">
          </div>
        </div>
        <div>
          <button class="btn" type="submit">Filter</button>
          <a class="btn" href="kpi_do_audit.php" style="margin-left:8px">Reset</a>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="kpi">
        <?php foreach (['WQS','SCM','ACT','FIN'] as $d):
          $n = $sum[$d]['n']; $sec = $sum[$d]['sec']; $avg = $n ? (int)round($sec/$n) : 0; ?>
          <div class="box">
            <b><?php echo h($d); ?> Avg Duration</b>
            <div style="font-size:18px"><?php echo h(fmtDur($avg)); ?></div>
            <div class="muted small">sample: <?php echo (int)$n; ?> DO (dari hasil filter)</div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="muted small" style="margin-top:10px">
        Durasi dihitung best-effort dari audit logs dalam filter: WQS (wqs_processing→ready_scm), SCM (on_delivery→delivered), ACT (delivered→wait_payment), FIN (wait_payment→paid).
      </div>
    </div>

    <div class="card table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:170px">Waktu</th>
            <th style="width:140px">DO / Tracking</th>
            <th style="width:120px">Customer</th>
            <th style="width:90px">Office</th>
            <th style="width:110px">Dept</th>
            <th style="width:240px">Status</th>
            <th style="width:140px">Actor</th>
            <th>Note</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="muted">Tidak ada data audit untuk filter ini.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo h($r['created_at']); ?></td>
              <td>
                <div><b><?php echo h($r['do_code'] ?? ('ID#'.$r['do_id'])); ?></b></div>
                <div class="muted small"><?php echo h($r['tracking_code'] ?? ''); ?></div>
                <?php if (!empty($r['do_id'])): ?>
                  <div class="small"><a href="sales_do_view.php?id=<?php echo (int)$r['do_id']; ?>" target="_blank">Detail</a></div>
                <?php endif; ?>
              </td>
              <td><?php echo h($r['customers_code'] ?? ''); ?></td>
              <td><?php echo h($r['office_code'] ?? ''); ?></td>
              <td><span class="tag"><?php echo h($r['actor_dept'] ?? ''); ?></span></td>
              <td>
                <span class="tag"><?php echo h($r['status_from'] ?? '-'); ?></span>
                <span class="muted">→</span>
                <span class="tag"><?php echo h($r['status_to'] ?? '-'); ?></span>
              </td>
              <td><?php echo h($r['actor_name'] ?? ''); ?></td>
              <td class="muted"><?php echo h($r['note'] ?? ''); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <div class="muted small">
        Tip: untuk KPI mingguan, set range tanggal 7 hari terakhir, lalu filter per dept.
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
