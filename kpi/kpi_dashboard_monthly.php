<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
// kpi/kpi_dashboard_monthly.php
// Dashboard bulanan per office: Target vs Actual (DO), Stock Value (global), Fixed Asset NBV (per office).

require_once __DIR__ . '/_kpi_policy.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../dashboards/_manager_scope.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['KPI.VIEW', 'DASHBOARD.KPI_VIEW']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','MANAGER','FIN','ACT','CRM','WQS','PQP','STAFF']);
}

$pdo = kpi_require_pdo();

$period = isset($_GET['m']) ? trim($_GET['m']) : '';
if ($period === '' || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}
$office_filter = isset($_GET['office']) ? trim($_GET['office']) : '';
$scopeCtx = ds_scope_ctx();
if (!$scopeCtx['is_admin'] && $scopeCtx['office_code'] !== '') {
    $office_filter = (string)$scopeCtx['office_code'];
}
[$start_date, $end_date] = kpi_policy_month_range($period);

$offices = [];
try {
    $offices = $pdo->query("SELECT office_code, office_name, id FROM master_office WHERE is_active=1 ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $offices = [];
}

$work_days_csv = (string)kpi_policy_get($pdo, 'KPI_OPS', 'WORK_DAYS', null, 'MON,TUE,WED,THU,FRI,SAT');
$workdays_in_month = kpi_policy_count_workdays_in_period($period, $work_days_csv);
if ($workdays_in_month <= 0) $workdays_in_month = 1;

// Actual DO MTD (full month)
$actual_do = [];
try {
    $sql = "SELECT office_code, COUNT(*) do_count, COALESCE(SUM(grand_total),0) do_value
            FROM sales_do
            WHERE do_date BETWEEN :s AND :e";
    $params = [':s'=>$start_date, ':e'=>$end_date];
    if ($office_filter !== '') {
        $sql .= " AND office_code=:o";
        $params[':o'] = $office_filter;
    }
    $sql .= " GROUP BY office_code ORDER BY office_code";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $oc = (string)($r['office_code'] ?? '');
        if ($oc === '') continue;
        $actual_do[$oc] = ['do_count'=>(int)($r['do_count']??0), 'do_value'=>(float)($r['do_value']??0)];
    }
} catch (Throwable $e) {
    // fail-soft
}

// Stock value global (best effort)
$stock_value_global = null;
try {
    if (kpi_policy_table_exists($pdo,'wqs_stock') && kpi_policy_table_exists($pdo,'master_products')) {
        // latest stock per product by updated_at
        $sql = "SELECT COALESCE(SUM(ws.stock_qty * p.price),0) AS stock_value
                FROM (
                    SELECT s1.product_id, s1.stock_qty
                    FROM wqs_stock s1
                    JOIN (
                        SELECT product_id, MAX(updated_at) mu
                        FROM wqs_stock
                        GROUP BY product_id
                    ) t ON t.product_id = s1.product_id AND t.mu = s1.updated_at
                ) ws
                JOIN master_products p ON p.id = ws.product_id";
        $stock_value_global = (float)($pdo->query($sql)->fetchColumn() ?: 0);
    }
} catch (Throwable $e) {
    $stock_value_global = null;
}

// Fixed Asset NBV per office (best effort using Fixed_Asset tables)
$fa_nbv_by_office = [];
try {
    if (kpi_policy_table_exists($pdo,'fa_assets') && kpi_policy_table_exists($pdo,'fa_depreciation_runs') && kpi_policy_table_exists($pdo,'fa_depreciation_lines')) {
        // accumulated depreciation per asset up to period
        $sql = "SELECT a.office_id, o.office_code, o.office_name,
                       SUM(a.cost) AS cost_total,
                       SUM(COALESCE(dep.accum_dep,0)) AS accum_dep,
                       SUM(a.cost - COALESCE(dep.accum_dep,0)) AS nbv
                FROM fa_assets a
                LEFT JOIN master_office o ON o.id = a.office_id
                LEFT JOIN (
                    SELECT dl.asset_id, SUM(dl.amount) accum_dep
                    FROM fa_depreciation_lines dl
                    JOIN fa_depreciation_runs dr ON dr.id = dl.run_id
                    WHERE dr.period <= :p
                    GROUP BY dl.asset_id
                ) dep ON dep.asset_id = a.id
                WHERE a.deleted_at IS NULL
                GROUP BY a.office_id, o.office_code, o.office_name";
        $st = $pdo->prepare($sql);
        $st->execute([':p'=>$period]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $oc = (string)($r['office_code'] ?? '');
            if ($oc === '') continue;
            $fa_nbv_by_office[$oc] = (float)($r['nbv'] ?? 0);
        }
    }
} catch (Throwable $e) {
    // fail-soft
}

// Build report rows
$rows = [];
foreach ($offices as $o) {
    $oc = (string)($o['office_code'] ?? '');
    if ($oc === '') continue;
    if ($office_filter !== '' && $office_filter !== $oc) continue;

    $targets = kpi_policy_get_targets_office($pdo, $period, $oc);

    $t_do_value = (float)($targets['TARGET_DO_VALUE'] ?? 0);
    $t_do_count = (float)($targets['TARGET_DO_COUNT'] ?? 0);
    $t_stock    = (float)($targets['TARGET_STOCK_VALUE'] ?? 0);
    $t_fa_nbv   = (float)($targets['TARGET_FIXED_ASSET_NBV'] ?? 0);

    $a_do_value = (float)($actual_do[$oc]['do_value'] ?? 0);
    $a_do_count = (int)($actual_do[$oc]['do_count'] ?? 0);
    $a_fa_nbv   = (float)($fa_nbv_by_office[$oc] ?? 0);

    $rows[] = [
        'office_code' => $oc,
        'office_name' => (string)($o['office_name'] ?? ''),
        't_do_value'  => $t_do_value,
        'a_do_value'  => $a_do_value,
        't_do_count'  => $t_do_count,
        'a_do_count'  => $a_do_count,
        't_stock'     => $t_stock,
        'stock_global'=> $stock_value_global,
        't_fa_nbv'    => $t_fa_nbv,
        'a_fa_nbv'    => $a_fa_nbv,
    ];
}

function pct($a, $t): string {
    if ($t <= 0) return '-';
    return number_format(($a/$t)*100, 1, ',', '.') . '%';
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$extraHead = <<<'HTML'
  <style>
    body{background:#020617;color:#e5e7eb}
    .card{background:#0b1220;border:1px solid #1f2937;border-radius:14px}
    .muted{color:#9ca3af;font-size:12px}
    .table thead th{background:#0b1220;color:#e5e7eb;border-color:#1f2937;white-space:nowrap}
    .table td{border-color:#1f2937;color:#e5e7eb;vertical-align:middle}
  </style>
HTML;

rmi_header('KPI Monthly Dashboard', 'kpi', [
  'subtitle' => 'Target vs Actual bulanan per office.',
  'breadcrumbs' => [
    ['label' => 'KPI Center', 'url' => $baseProject . '/kpi/kpi_center.php'],
    'Monthly Dashboard',
  ],
  'actions' => [
    ['label' => 'KPI Center', 'url' => 'kpi_center.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Owner Policy (Config)', 'url' => '../master_system_config.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Daily', 'url' => 'kpi_dashboard_daily.php', 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'extra_head' => $extraHead,
]);
?>
<div class="container" style="max-width:1200px;padding:18px 12px;">
  <?php
  ds_manager_section($pdo, [
    'backlog_table' => 'sales_do',
    'backlog_status_col' => 'status',
    'backlog_open_statuses' => ['DRAFT','SUBMITTED','OPEN','WAIT_PAYMENT'],
    'backlog_office_col' => 'office_code',
    'exceptions_count' => 0,
  ]);
  ?>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div>
      <h4 class="mb-0">KPI Monthly Dashboard</h4>
      <div class="muted">Periode <?= h($period) ?> • Source DO: sales_do • Fixed Asset: Fixed_Asset module</div>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-3">
        <label class="form-label">Periode (YYYY-MM)</label>
        <input class="form-control form-control-sm" name="m" value="<?= h($period) ?>">
      </div>
      <div class="col-md-5">
        <label class="form-label">Office</label>
        <select class="form-select form-select-sm" name="office">
          <option value="">ALL OFFICE</option>
          <?php foreach ($offices as $o): $oc=(string)($o['office_code']??''); ?>
            <option value="<?= h($oc) ?>" <?= ($office_filter===$oc?'selected':'') ?>><?= h($oc) ?> — <?= h($o['office_name']??'') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <button class="btn btn-sm btn-success" type="submit">Tampilkan</button>
        <span class="muted ms-2">Hari kerja: <?= (int)$workdays_in_month ?></span>
      </div>
    </form>
  </div>

  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <div class="fw-semibold">Stock Products Value (Global)</div>
        <div class="muted">Saat ini stok tidak punya dimensi office di DB. Nilai ini global.</div>
      </div>
      <div class="fs-5">Rp <?= $stock_value_global===null ? '-' : kpi_policy_money($stock_value_global) ?></div>
    </div>
  </div>

  <div class="card p-3">
    <div class="table-responsive">
      <table class="table table-sm table-dark" style="--bs-table-bg:#0b1220;">
        <thead>
          <tr>
            <th>Office</th>
            <th class="text-end">Target DO (Rp)</th>
            <th class="text-end">Actual DO (Rp)</th>
            <th class="text-end">% DO Value</th>
            <th class="text-end">Target DO Count</th>
            <th class="text-end">Actual DO Count</th>
            <th class="text-end">% DO Count</th>
            <th class="text-end">Target Stock (Rp)</th>
            <th class="text-end">Target FA NBV (Rp)</th>
            <th class="text-end">Actual FA NBV (Rp)</th>
            <th class="text-end">% FA NBV</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="11" class="muted">Data office tidak ditemukan / belum ada master_office.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><strong><?= h($r['office_code']) ?></strong> <span class="muted"><?= h($r['office_name']) ?></span></td>
            <td class="text-end">Rp <?= kpi_policy_money($r['t_do_value']) ?></td>
            <td class="text-end">Rp <?= kpi_policy_money($r['a_do_value']) ?></td>
            <td class="text-end"><?= pct($r['a_do_value'],$r['t_do_value']) ?></td>
            <td class="text-end"><?= number_format((float)$r['t_do_count'],0,',','.') ?></td>
            <td class="text-end"><?= number_format((int)$r['a_do_count'],0,',','.') ?></td>
            <td class="text-end"><?= pct($r['a_do_count'],$r['t_do_count']) ?></td>
            <td class="text-end">Rp <?= kpi_policy_money($r['t_stock']) ?></td>
            <td class="text-end">Rp <?= kpi_policy_money($r['t_fa_nbv']) ?></td>
            <td class="text-end">Rp <?= kpi_policy_money($r['a_fa_nbv']) ?></td>
            <td class="text-end"><?= pct($r['a_fa_nbv'],$r['t_fa_nbv']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="muted mt-2">
      Catatan: Actual Operational/Beban/Support/Fee/PPh/Hutang/Piutang akan bisa otomatis ketika modul finance ledger final.
      Untuk sekarang, kamu tetap bisa input targetnya di Owner Policy Center.
    </div>
  </div>

</div>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<?php rmi_footer(); ?>
