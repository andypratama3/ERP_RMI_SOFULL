<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PURCHASES.VIEW', 'DASHBOARD.PROCUREMENT_VIEW']);
} else {
    require_role(['ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
}
require_once __DIR__ . '/../dashboards/_manager_scope.php';

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

// KPI summary (best-effort)
$kpi = ['pr_submitted'=>0,'po_open'=>0,'po_value_month'=>0,'ap_outstanding'=>0,'ap_unpaid'=>0,'rfq_open'=>0,'rfq_pending'=>0,'manufactures_active'=>0,'manufactures_total'=>0,'manufactures_new_month'=>0,'gr_mtd'=>0,'ap_3wm'=>0,'po_overdue'=>0,'forwarder_open'=>0];
$scopeCtx = ds_scope_ctx();
$scopeOffice = (string)($scopeCtx['office_code'] ?? '');
$scopeOfficeSql = (!$scopeCtx['is_admin'] && $scopeOffice !== '') ? " AND UPPER(COALESCE(office_code,'')) = UPPER(:scope_office)" : '';
$scopeParams = (!$scopeCtx['is_admin'] && $scopeOffice !== '') ? [':scope_office' => $scopeOffice] : [];

try {
  // PR submitted waiting PO
  if ($scopeOfficeSql !== '') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM wqs_pr WHERE status='SUBMITTED'{$scopeOfficeSql}");
    $st->execute($scopeParams);
    $kpi['pr_submitted'] = (int)$st->fetchColumn();
  } else {
    $kpi['pr_submitted'] = (int)$pdo->query("SELECT COUNT(*) FROM wqs_pr WHERE status='SUBMITTED'")->fetchColumn();
  }

  // PO open
  if ($scopeOfficeSql !== '') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE deleted_at IS NULL AND status IN ('OPEN','IN_PRODUCTION','READY'){$scopeOfficeSql}");
    $st->execute($scopeParams);
    $kpi['po_open'] = (int)$st->fetchColumn();
  } else {
    $kpi['po_open'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases_po WHERE deleted_at IS NULL AND status IN ('OPEN','IN_PRODUCTION','READY')")->fetchColumn();
  }

  // PO value this month (only if can view buy price)
  $canPrice = p_can_view_buy_price();
  if ($canPrice) {
    $ym = date('Y-m');
    $sql = "SELECT COALESCE(SUM(total_amount),0) FROM purchases_po WHERE deleted_at IS NULL AND DATE_FORMAT(po_date,'%Y-%m')=:ym";
    if ($scopeOfficeSql !== '') {
      $sql .= $scopeOfficeSql;
    }
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([':ym'=>$ym], $scopeParams));
    $kpi['po_value_month'] = (float)$st->fetchColumn();
  }

  // AP outstanding (purchases_invoice_ap: UNPAID/PARTIAL, outstanding = total - paid)
  $apWhere = "ap.deleted_at IS NULL AND ap.status IN ('UNPAID','PARTIAL')";
  $apOfficeSql = ($scopeOfficeSql !== '' && strpos($scopeOfficeSql, 'office_code') !== false)
    ? str_replace('office_code', 'ap.office_code', $scopeOfficeSql) : '';
  if ($apOfficeSql !== '') {
    $st = $pdo->prepare("
      SELECT COUNT(*) FROM purchases_invoice_ap ap
      WHERE {$apWhere}{$apOfficeSql}
    ");
    $st->execute($scopeParams);
    $kpi['ap_outstanding'] = (int)$st->fetchColumn();
  } else {
    $kpi['ap_outstanding'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases_invoice_ap ap WHERE {$apWhere}")->fetchColumn();
  }

  // unpaid amount (total - paid)
  if ($canPrice) {
    $apSumSql = "
      SELECT COALESCE(SUM(ap.total_amount - COALESCE(paid.paid_amount,0)),0)
      FROM purchases_invoice_ap ap
      LEFT JOIN (SELECT ap_id, SUM(amount) paid_amount FROM purchases_payment_ap WHERE deleted_at IS NULL GROUP BY ap_id) paid ON paid.ap_id = ap.id
      WHERE {$apWhere}
    ";
    if ($apOfficeSql !== '') {
      $st = $pdo->prepare($apSumSql . $apOfficeSql);
      $st->execute($scopeParams);
      $kpi['ap_unpaid'] = (float)$st->fetchColumn();
    } else {
      $kpi['ap_unpaid'] = (float)$pdo->query($apSumSql)->fetchColumn();
    }
  }

  // Manufactures count — exclude internal "Kantor/Depo" entries
  try {
    $kpi['manufactures_active']    = (int)$pdo->query("SELECT COUNT(*) FROM master_manufactures WHERE status=1 AND manufacture_name NOT LIKE 'Kantor%'")->fetchColumn();
    $kpi['manufactures_total']     = (int)$pdo->query("SELECT COUNT(*) FROM master_manufactures WHERE manufacture_name NOT LIKE 'Kantor%'")->fetchColumn();
    $kpi['manufactures_new_month'] = (int)$pdo->query("SELECT COUNT(*) FROM master_manufactures WHERE manufacture_name NOT LIKE 'Kantor%' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')")->fetchColumn();
  } catch (Exception $e) { /* silent */ }

  // RFQ KPI (best-effort)
  try {
    $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pqp_rfq'");
    if ($chk && $chk->fetch()) {
      $kpi['rfq_open'] = (int)$pdo->query("SELECT COUNT(*) FROM pqp_rfq WHERE status='open'")->fetchColumn();
      $kpi['rfq_pending'] = (int)$pdo->query("
        SELECT COUNT(*) FROM pqp_rfq r
        WHERE r.status='open' AND NOT EXISTS (
          SELECT 1 FROM pqp_rfq_quotations q WHERE q.rfq_id=r.id AND q.status='submitted'
        )
      ")->fetchColumn();
    }
  } catch (Exception $e) {}

  // NEW: GR this month
  try {
    $ym = date('Y-m');
    $st = $pdo->prepare("SELECT COUNT(*) FROM wqs_incoming WHERE DATE_FORMAT(COALESCE(received_date,created_at),'%Y-%m')=?");
    $st->execute([$ym]);
    $kpi['gr_mtd'] = (int)$st->fetchColumn();
  } catch (Throwable $e) {}

  // NEW: AP HOLD_3WM
  try {
    $kpi['ap_3wm'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases_invoice_ap WHERE deleted_at IS NULL AND status='HOLD_3WM'")->fetchColumn();
  } catch (Throwable $e) {}

  // NEW: PO Overdue (open > 60 days)
  try {
    $kpi['po_overdue'] = (int)$pdo->query("
      SELECT COUNT(*) FROM purchases_po
      WHERE deleted_at IS NULL AND status IN ('OPEN','IN_PRODUCTION')
        AND po_date <= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
    ")->fetchColumn();
  } catch (Throwable $e) {}

  // NEW: Forwarder tasks open
  try {
    $chkFwd = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='purchases_forwarder_tasks'");
    if ($chkFwd && $chkFwd->fetch()) {
      $kpi['forwarder_open'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases_forwarder_tasks WHERE status NOT IN ('DONE','CLOSED','CANCELLED')")->fetchColumn();
    }
  } catch (Throwable $e) {}

} catch(Exception $e) {
  // silent - dashboard tetap render
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';

$actions = [
  ['label'=>'RFQ (Request Quotation)', 'url'=>'pqp_rfq.php',   'class'=>'btn btn-sm btn-rmi'],
  ['label'=>'PO',                      'url'=>'purchases_po.php','class'=>'btn btn-sm btn-rmi'],
  ['label'=>'📖 Panduan',              'url'=>'panduan.php',    'class'=>'btn btn-sm btn-outline-light'],
];

$extraHeadPQP = <<<'STYLE'
<style>
.pqp-header{background:linear-gradient(135deg,rgba(76,29,149,.8),rgba(109,40,217,.6));border:1px solid rgba(139,92,246,.3);border-radius:18px;padding:20px 24px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.pqp-header h2{margin:0;font-size:20px;font-weight:800;color:#fff}
.pqp-header p{margin:4px 0 0;font-size:12px;color:rgba(255,255,255,.65)}
.pqp-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px}
.pqp-k{padding:14px;border-radius:14px;background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-top:3px solid var(--kc)}
.pqp-k-icon{font-size:20px;margin-bottom:6px}
.pqp-k-n{font-size:24px;font-weight:800;color:#fff}
.pqp-k-n.sm{font-size:15px}
.pqp-k-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-top:3px}
.pqp-k-sub{font-size:10px;color:#334155;margin-top:2px}
.pqp-links{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}
.pqp-link{padding:7px 13px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid rgba(255,255,255,.12);color:#e2e8f0;background:rgba(255,255,255,.06);transition:all .2s;display:inline-flex;align-items:center;gap:5px}
.pqp-link:hover{background:rgba(255,255,255,.14);color:#fff}
.pqp-link.primary{background:linear-gradient(135deg,#7c3aed,#8b5cf6);border-color:transparent;color:#fff}
</style>
STYLE;

rmi_header('PQP Dashboard', [
  'active'     => 'purchases',
  'subtitle'   => 'Flow: WQS PR → PQP PO → FIN AP/Payment → WQS Incoming',
  'breadcrumbs'=> [['label'=>'PQP', 'url'=>'index.php'], 'Dashboard'],
  'actions'    => $actions,
  'extra_head' => $extraHeadPQP,
]);
?>

<?php
ds_manager_section($pdo, [
  'backlog_table' => 'purchases_po',
  'backlog_status_col' => 'status',
  'backlog_open_statuses' => ['OPEN','IN_PRODUCTION','READY','SUBMITTED'],
  'backlog_office_col' => 'office_code',
  'exceptions_count' => (int)$kpi['ap_outstanding'],
]);
?>

<!-- PQP Header -->
<div class="pqp-header">
  <div>
    <h2>🛒 PQP Dashboard</h2>
    <p>Flow: WQS PR → PQP RFQ/PO → FIN AP/Payment → WQS Incoming
      &nbsp;·&nbsp; Buy price: <?= p_can_view_buy_price() ? '<span style="color:#4ade80;font-weight:700">Visible</span>' : '<span style="color:#94a3b8">Restricted</span>' ?>
    </p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="pqp-link" style="background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.15);color:#e2e8f0" href="<?= rmi_h($baseProject . '/dashboards/index.php') ?>">🏠 Home</a>
  </div>
</div>

<!-- KPI Cards -->
<div class="pqp-kpi">
  <div class="pqp-k" style="--kc:#f59e0b">
    <div class="pqp-k-icon">📋</div>
    <div class="pqp-k-n"><?= (int)$kpi['rfq_open'] ?></div>
    <div class="pqp-k-lbl">RFQ Open</div>
    <div class="pqp-k-sub">Menunggu quotation</div>
  </div>
  <div class="pqp-k" style="--kc:#94a3b8">
    <div class="pqp-k-icon">📭</div>
    <div class="pqp-k-n"><?= (int)$kpi['rfq_pending'] ?></div>
    <div class="pqp-k-lbl">RFQ Pending</div>
    <div class="pqp-k-sub">Belum ada quotation</div>
  </div>
  <div class="pqp-k" style="--kc:#f97316">
    <div class="pqp-k-icon">📝</div>
    <div class="pqp-k-n"><?= (int)$kpi['pr_submitted'] ?></div>
    <div class="pqp-k-lbl">PR Submitted</div>
    <div class="pqp-k-sub">Menunggu dibuat PO</div>
  </div>
  <div class="pqp-k" style="--kc:#3b82f6">
    <div class="pqp-k-icon">🛒</div>
    <div class="pqp-k-n"><?= (int)$kpi['po_open'] ?></div>
    <div class="pqp-k-lbl">PO Open</div>
    <div class="pqp-k-sub">On progress</div>
  </div>
  <div class="pqp-k" style="--kc:#22c55e">
    <div class="pqp-k-icon">💰</div>
    <div class="pqp-k-n sm"><?= p_can_view_buy_price() ? 'Rp '.number_format((float)$kpi['po_value_month']/1e6,1,',','.').'jt' : '—' ?></div>
    <div class="pqp-k-lbl">PO Value MTD</div>
    <div class="pqp-k-sub">Bulan ini</div>
  </div>
  <div class="pqp-k" style="--kc:#ef4444">
    <div class="pqp-k-icon">💳</div>
    <div class="pqp-k-n"><?= (int)$kpi['ap_outstanding'] ?></div>
    <div class="pqp-k-lbl">AP Outstanding</div>
    <div class="pqp-k-sub"><?= p_can_view_buy_price() ? 'Rp '.number_format((float)$kpi['ap_unpaid']/1e6,1,',','.').'jt' : 'Restricted' ?></div>
  </div>
  <div class="pqp-k" style="--kc:#8b5cf6">
    <div class="pqp-k-icon">🏭</div>
    <div class="pqp-k-n"><?= (int)$kpi['manufactures_active'] ?></div>
    <div class="pqp-k-lbl">Manufactures Aktif</div>
    <div class="pqp-k-sub">Total: <?= (int)$kpi['manufactures_total'] ?> · Baru bulan ini: <b><?= (int)$kpi['manufactures_new_month'] ?></b></div>
  </div>

  <!-- NEW: GR MTD -->
  <div class="pqp-k" style="--kc:#06b6d4">
    <div class="pqp-k-icon">✅</div>
    <div class="pqp-k-n"><?= (int)$kpi['gr_mtd'] ?></div>
    <div class="pqp-k-lbl">GR Bulan Ini</div>
    <div class="pqp-k-sub">Barang diterima MTD</div>
  </div>

  <!-- NEW: PO Overdue -->
  <div class="pqp-k" style="--kc:<?= $kpi['po_overdue']>0?'#f97316':'#64748b' ?>">
    <div class="pqp-k-icon">⏰</div>
    <div class="pqp-k-n" style="color:<?= $kpi['po_overdue']>0?'#fb923c':'#fff' ?>"><?= (int)$kpi['po_overdue'] ?></div>
    <div class="pqp-k-lbl">PO Overdue 60d+</div>
    <div class="pqp-k-sub"><?= $kpi['po_overdue']>0?'⚠ Sudah >60 hari OPEN':'✓ Semua on track' ?></div>
  </div>

  <!-- Forwarding Tasks Open -->
  <?php if ($kpi['forwarder_open'] > 0): ?>
  <div class="pqp-k" style="--kc:#8b5cf6">
    <div class="pqp-k-icon">🚢</div>
    <div class="pqp-k-n" style="color:#a78bfa"><?= (int)$kpi['forwarder_open'] ?></div>
    <div class="pqp-k-lbl">Forwarding Open</div>
    <div class="pqp-k-sub">Tugas forwarder aktif</div>
  </div>
  <?php endif; ?>

  <!-- NEW: AP HOLD_3WM -->
  <?php if ($kpi['ap_3wm'] > 0): ?>
  <div class="pqp-k" style="--kc:#f59e0b">
    <div class="pqp-k-icon">🔍</div>
    <div class="pqp-k-n" style="color:#fbbf24"><?= (int)$kpi['ap_3wm'] ?></div>
    <div class="pqp-k-lbl">HOLD 3WM</div>
    <div class="pqp-k-sub">3-Way Match pending</div>
  </div>
  <?php endif; ?>
</div>

<!-- Alerts -->
<?php if ($kpi['po_overdue'] > 0): ?>
<div style="background:rgba(249,115,22,.1);border:1px solid rgba(249,115,22,.3);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:13px">
  <span style="font-size:18px">⏰</span>
  <span><strong style="color:#fb923c"><?= $kpi['po_overdue'] ?> PO sudah lebih dari 60 hari OPEN</strong>
  <span style="color:#94a3b8"> — perlu follow-up ke supplier</span></span>
  <a href="purchases_po.php" style="margin-left:auto;color:#fb923c;font-size:11px;text-decoration:none">Lihat PO →</a>
</div>
<?php endif; ?>
<?php if ($kpi['ap_3wm'] > 0): ?>
<div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:13px">
  <span style="font-size:18px">🔍</span>
  <span><strong style="color:#fbbf24"><?= $kpi['ap_3wm'] ?> Invoice HOLD 3-Way Match</strong>
  <span style="color:#94a3b8"> — perlu validasi PO/GR/Invoice</span></span>
  <a href="purchases_invoice_ap.php" style="margin-left:auto;color:#fbbf24;font-size:11px;text-decoration:none">Review →</a>
</div>
<?php endif; ?>

<!-- Quick Links -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-3">⚡ Quick Links — PQP</div>
  <div class="pqp-links">
    <a class="pqp-link primary" href="pqp_rfq.php">📋 RFQ</a>
    <a class="pqp-link primary" href="purchases_po.php">🛒 Purchase Order</a>
    <a class="pqp-link primary" href="purchases_gr.php">✅ Good Receipt (GR)</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/master/master_products.php') ?>">📦 Master Products</a>
    <a class="pqp-link" href="purchases_invoice_ap.php">💳 AP Invoice</a>
    <a class="pqp-link" href="purchases_payment_ap.php">💸 AP Payment</a>
    <a class="pqp-link" href="purchases_import_control_tower.php">🗼 Import Control Tower</a>
    <a class="pqp-link" href="purchases_forwarding_tasks.php">🚢 Forwarding Tasks</a>
    <a class="pqp-link" href="purchases_reports.php">📊 Purchases Reports</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/stock/wqs_pr.php') ?>">📝 PR (WQS)</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/hrl_process/index.php') ?>">📋 HRL Process</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/absensi/index.php') ?>">📅 Absensi</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/kpi/kpi_center.php') ?>">📈 KPI Center</a>
  </div>
</div>


<?php rmi_footer(); ?>
