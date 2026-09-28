<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
// kpi/kpi_dashboard_daily.php
// Dashboard harian: Target vs Actual dari DO (tanpa sync). Additive.

require_once __DIR__ . '/_kpi_policy.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../dashboards/_manager_scope.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['KPI.VIEW', 'DASHBOARD.KPI_VIEW']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','MANAGER','FIN','ACT','CRM','WQS','PQP','BRANCH','STAFF']);
}

$pdo = kpi_require_pdo();

$date = isset($_GET['d']) ? trim($_GET['d']) : '';
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$office_filter = isset($_GET['office']) ? trim($_GET['office']) : '';
$scopeCtx = ds_scope_ctx();
if (!$scopeCtx['is_admin'] && $scopeCtx['office_code'] !== '') {
    $office_filter = (string)$scopeCtx['office_code'];
}
$period = substr($date, 0, 7);

// Offices
$offices = [];
try {
    $offices = $pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active=1 ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $offices = [];
}

$work_days_csv = (string)kpi_policy_get($pdo, 'KPI_OPS', 'WORK_DAYS', null, 'MON,TUE,WED,THU,FRI,SAT');
$workdays_in_month = kpi_policy_count_workdays_in_period($period, $work_days_csv);
if ($workdays_in_month <= 0) $workdays_in_month = 1;

// Actual per office (DO)
$actual_office = [];
try {
    $sql = "SELECT office_code, COUNT(*) do_count, COALESCE(SUM(grand_total),0) do_value
            FROM sales_do
            WHERE do_date = :d";
    $params = [':d'=>$date];
    if ($office_filter !== '') {
        $sql .= " AND office_code = :o";
        $params[':o'] = $office_filter;
    }
    $sql .= " GROUP BY office_code ORDER BY office_code";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $oc = (string)($r['office_code'] ?? '');
        if ($oc === '') continue;
        $actual_office[$oc] = [
            'do_count' => (int)($r['do_count'] ?? 0),
            'do_value' => (float)($r['do_value'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    // fail-soft
}

// Actual per employee (DO)
$actual_employee = [];
try {
    $sql = "SELECT office_code, sales_emp_code, COUNT(*) do_count, COALESCE(SUM(grand_total),0) do_value
            FROM sales_do
            WHERE do_date = :d";
    $params = [':d'=>$date];
    if ($office_filter !== '') {
        $sql .= " AND office_code = :o";
        $params[':o'] = $office_filter;
    }
    $sql .= " GROUP BY office_code, sales_emp_code
              ORDER BY do_value DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $actual_employee = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $actual_employee = [];
}

// Map office_name
$office_name = [];
foreach ($offices as $o) {
    $office_name[(string)$o['office_code']] = (string)($o['office_name'] ?? '');
}

// Targets per office
$targets_office = [];
$office_list_for_targets = $offices;
if ($office_filter !== '') {
    $office_list_for_targets = [['office_code'=>$office_filter, 'office_name'=>$office_name[$office_filter] ?? $office_filter]];
}
foreach ($office_list_for_targets as $o) {
    $oc = (string)($o['office_code'] ?? '');
    if ($oc === '') continue;
    $t = kpi_policy_get_targets_office($pdo, $period, $oc);
    $targets_office[$oc] = $t;
}

function pct($a, $b): float {
    if ($b <= 0) return 0.0;
    return round(($a / $b) * 100, 2);
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$extraHead = <<<'HTML'
  <style>
    body{background:#020617;color:#e5e7eb}
    .card{background:#0b1220;border:1px solid #1f2937;border-radius:14px}
    .muted{color:#94a3b8;font-size:12px}
    .badge-soft{border:1px solid #1f2937;background:#111827;color:#e5e7eb;border-radius:999px;padding:3px 10px;font-size:11px}
    .table{color:#e5e7eb}
    .table thead th{color:#e5e7eb;border-color:#1f2937}
    .table td{border-color:#1f2937}
  </style>
HTML;

rmi_header('KPI Daily Dashboard (DO)', 'kpi', [
  'subtitle' => 'Target vs Actual harian berdasarkan DO.',
  'breadcrumbs' => [
    ['label' => 'KPI Center', 'url' => $baseProject . '/kpi/kpi_center.php'],
    'Daily Dashboard',
  ],
  'actions' => [
    ['label' => 'KPI Center', 'url' => 'kpi_center.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Owner Policy Center', 'url' => '../master_system_config.php', 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'body_class' => 'py-4',
  'extra_head' => $extraHead,
]);
?>
<div class="container" style="max-width:1200px">
  <?php
  ds_manager_section($pdo, [
    'backlog_table' => 'sales_do',
    'backlog_status_col' => 'status',
    'backlog_open_statuses' => ['DRAFT','SUBMITTED','OPEN','WAIT_PAYMENT'],
    'backlog_office_col' => 'office_code',
    'exceptions_count' => 0,
  ]);
  ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-1">KPI Daily Dashboard (berdasarkan DO)</h4>
      <div class="muted">Tanggal: <span class="badge-soft"><?= h($date) ?></span> • Periode target: <span class="badge-soft"><?= h($period) ?></span> • Hari kerja bulan ini: <span class="badge-soft"><?= (int)$workdays_in_month ?></span></div>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-3">
        <label class="form-label">Tanggal</label>
        <input type="date" class="form-control form-control-sm" name="d" value="<?= h($date) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Office</label>
        <select class="form-select form-select-sm" name="office">
          <option value="">ALL OFFICE</option>
          <?php foreach ($offices as $o): $oc=(string)$o['office_code']; ?>
            <option value="<?= h($oc) ?>" <?= $office_filter===$oc?'selected':'' ?>><?= h($oc) ?> — <?= h($o['office_name']??'') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5 d-flex gap-2">
        <button class="btn btn-sm btn-primary" type="submit">Tampilkan</button>
        <a class="btn btn-sm btn-secondary" href="kpi_dashboard_daily.php">Reset</a>
      </div>
    </form>
  </div>

  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <strong>Ringkasan per Office</strong>
      <span class="badge-soft">Target harian = target bulanan ÷ hari kerja</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead>
          <tr>
            <th>Office</th>
            <th class="text-end">DO Count</th>
            <th class="text-end">Target Count (Daily)</th>
            <th class="text-end">Achv %</th>
            <th class="text-end">DO Value</th>
            <th class="text-end">Target Value (Daily)</th>
            <th class="text-end">Achv %</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $rows = $office_list_for_targets;
            if (empty($rows)) $rows = [['office_code'=>'(NO OFFICE)','office_name'=>'']];
            foreach ($rows as $o):
              $oc = (string)($o['office_code'] ?? '');
              if ($oc === '(NO OFFICE)') continue;
              $actC = $actual_office[$oc]['do_count'] ?? 0;
              $actV = $actual_office[$oc]['do_value'] ?? 0;
              $tMonthlyC = (float)($targets_office[$oc]['TARGET_DO_COUNT'] ?? 0);
              $tMonthlyV = (float)($targets_office[$oc]['TARGET_DO_VALUE'] ?? 0);
              $tDailyC = $tMonthlyC / $workdays_in_month;
              $tDailyV = $tMonthlyV / $workdays_in_month;
          ?>
          <tr>
            <td>
              <div><strong><?= h($oc) ?></strong></div>
              <div class="muted"><?= h($office_name[$oc] ?? '') ?></div>
            </td>
            <td class="text-end"><?= number_format((float)$actC,0,',','.') ?></td>
            <td class="text-end"><?= number_format((float)$tDailyC,0,',','.') ?></td>
            <td class="text-end"><?= pct((float)$actC, (float)$tDailyC) ?>%</td>
            <td class="text-end">Rp <?= kpi_policy_money($actV) ?></td>
            <td class="text-end">Rp <?= kpi_policy_money($tDailyV) ?></td>
            <td class="text-end"><?= pct((float)$actV, (float)$tDailyV) ?>%</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="muted">Kalau target masih 0: buka Owner Policy Center, seed target bulan <strong><?= h($period) ?></strong>, lalu isi nilainya.</div>
  </div>

  <div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <strong>Kontribusi Employee (berdasarkan DO)</strong>
      <span class="badge-soft">Top by DO Value</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead>
          <tr>
            <th>Office</th>
            <th>Employee Code</th>
            <th class="text-end">DO Count</th>
            <th class="text-end">DO Value</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($actual_employee)): ?>
          <tr><td colspan="4" class="muted">Belum ada DO di tanggal ini.</td></tr>
        <?php else: foreach ($actual_employee as $r): ?>
          <tr>
            <td><?= h($r['office_code'] ?? '') ?></td>
            <td><strong><?= h($r['sales_emp_code'] ?? '-') ?></strong></td>
            <td class="text-end"><?= number_format((float)($r['do_count'] ?? 0),0,',','.') ?></td>
            <td class="text-end">Rp <?= kpi_policy_money($r['do_value'] ?? 0) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<?php rmi_footer(); ?>
