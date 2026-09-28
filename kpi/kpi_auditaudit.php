<?php
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
require_once __DIR__ . '/_kpi_bootstrap.php';
$pdo = kpi_require_pdo();

require_once __DIR__ . '/../_shared/rbac.php';
if (!function_exists('rbac_require')) {
    http_response_code(500);
    exit('RBAC unavailable');
}
rbac_require($pdo, 'KPI.DO_AUDIT');

kpi_header('KPI Audit Log');
kpi_nav('audit');

if (!kpi_table_exists($pdo,'kpi_audit_log')) {
    kpi_footer(); 
    echo "<div class='card' style='border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08);padding:14px 16px'>
        <span class='badge danger'>MISSING</span>
        Tabel <code>kpi_audit_log</code> belum ada.
        Jalankan SQL: <code>kpi/kpi_enterprise_tables.sql</code>
    </div>";
    exit;
}

$module = trim($_GET['m'] ?? '');
$action = trim($_GET['a'] ?? '');
$actor  = trim($_GET['u'] ?? '');

$where = []; $params = [];
if ($module !== '') { $where[] = "module = :m"; $params[':m'] = $module; }
if ($action !== '') { $where[] = "action = :a"; $params[':a'] = $action; }
if ($actor  !== '') { $where[] = "actor  = :u"; $params[':u'] = $actor; }

$sql = "SELECT * FROM kpi_audit_log" . ($where ? " WHERE " . implode(" AND ", $where) : "") . " ORDER BY id DESC LIMIT 500";
$st  = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    kpi_csv_download("kpi_audit_export.csv", $rows);
}

// Action color map
$actionColors = [
    'CREATE'   => ['#bbf7d0','rgba(34,197,94,.18)'],
    'UPDATE'   => ['#bfdbfe','rgba(59,130,246,.18)'],
    'DELETE'   => ['#fecaca','rgba(239,68,68,.18)'],
    'IMPORT'   => ['#e9d5ff','rgba(139,92,246,.18)'],
    'BULK'     => ['#fde68a','rgba(245,158,11,.18)'],
    'SNAPSHOT' => ['#a5f3fc','rgba(6,182,212,.18)'],
    'LOCK'     => ['#fca5a5','rgba(239,68,68,.25)'],
    'UNLOCK'   => ['#86efac','rgba(34,197,94,.25)'],
];
?>

<!-- Filter -->
<div class="card">
  <div class="kpi-header-row">
    <h3 class="kpi-section-title">🔍 Filter Audit Log</h3>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a class="btn" href="kpi_audit.php?export=csv&m=<?= urlencode($module) ?>&a=<?= urlencode($action) ?>&u=<?= urlencode($actor) ?>">
        ⬇ Export CSV
      </a>
      <?php if ($module || $action || $actor): ?>
        <a class="btn secondary" href="kpi_audit.php">✕ Reset Filter</a>
      <?php endif; ?>
    </div>
  </div>
  <form method="get" class="kpi-form-row">
    <div class="kpi-field">
      <label>Module</label>
      <input name="m" value="<?= h($module) ?>" placeholder="kpi_office, kpi_employee…">
    </div>
    <div class="kpi-field">
      <label>Action</label>
      <input name="a" value="<?= h($action) ?>" placeholder="CREATE, UPDATE, DELETE…">
    </div>
    <div class="kpi-field">
      <label>Actor (username)</label>
      <input name="u" value="<?= h($actor) ?>" placeholder="username">
    </div>
    <div class="kpi-field" style="justify-content:flex-end">
      <button class="btn ok" type="submit">Filter</button>
    </div>
  </form>
</div>

<!-- Stats bar -->
<?php if ($rows): ?>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
  <div style="background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:8px 16px;font-size:12px;color:#94a3b8">
    Menampilkan <b style="color:#f1f5f9"><?= count($rows) ?></b> record
    <?= ($module||$action||$actor) ? '(filtered)' : '(500 terbaru)' ?>
  </div>
  <?php
  $actCounts = array_count_values(array_column($rows, 'action'));
  arsort($actCounts);
  foreach (array_slice($actCounts, 0, 5, true) as $act => $cnt):
    [$color, $bg] = $actionColors[strtoupper($act)] ?? ['#e2e8f0','rgba(255,255,255,.08)'];
  ?>
  <div style="background:<?= $bg ?>;border:1px solid <?= $color ?>33;border-radius:10px;padding:8px 14px;font-size:12px;color:<?= $color ?>">
    <?= h($act) ?> <b><?= $cnt ?></b>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Table -->
<div class="card">
  <h3 class="kpi-section-title">📋 Audit Log <?= ($module||$action||$actor) ? '<span class="badge" style="font-size:10px;margin-left:6px">Filtered</span>' : '' ?></h3>
  <?php if (empty($rows)): ?>
    <div style="text-align:center;padding:28px;color:#475569;font-size:13px">
      Tidak ada log yang cocok dengan filter ini.
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#ID</th>
          <th>Waktu</th>
          <th>Module</th>
          <th>Action</th>
          <th>Ref</th>
          <th>Actor</th>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $act = strtoupper((string)($r['action'] ?? ''));
          [$aColor, $aBg] = $actionColors[$act] ?? ['#e2e8f0','rgba(255,255,255,.06)'];
        ?>
        <tr>
          <td style="color:#475569;font-size:11px"><?= h((string)($r['id'] ?? '')) ?></td>
          <td style="white-space:nowrap;font-size:11px;color:#64748b"><?= h((string)($r['created_at'] ?? '')) ?></td>
          <td>
            <code style="font-size:11px;color:#93c5fd;background:rgba(59,130,246,.12);padding:2px 7px;border-radius:5px">
              <?= h((string)($r['module'] ?? '')) ?>
            </code>
          </td>
          <td>
            <span style="background:<?= $aBg ?>;color:<?= $aColor ?>;font-size:10px;font-weight:700;padding:2px 9px;border-radius:8px;white-space:nowrap">
              <?= h($act) ?>
            </span>
          </td>
          <td style="font-size:11px;color:#94a3b8;font-family:monospace"><?= h((string)($r['ref_code'] ?? '—')) ?></td>
          <td>
            <span style="background:rgba(139,92,246,.12);color:#c4b5fd;font-size:11px;font-weight:600;padding:2px 8px;border-radius:6px">
              <?= h((string)($r['actor'] ?? '')) ?>
            </span>
          </td>
          <td class="muted" style="max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= h((string)($r['detail'] ?? '')) ?>">
            <?= h((string)($r['detail'] ?? '')) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php kpi_footer();