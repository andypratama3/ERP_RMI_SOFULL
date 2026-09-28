<?php
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
$kpi = ['pr_submitted'=>0,'po_open'=>0,'po_value_month'=>0,'ap_outstanding'=>0,'ap_unpaid'=>0];
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

  // AP outstanding (purchases_payment_ap)
  if ($scopeOfficeSql !== '') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_payment_ap WHERE deleted_at IS NULL AND status IN ('OPEN','PARTIAL'){$scopeOfficeSql}");
    $st->execute($scopeParams);
    $kpi['ap_outstanding'] = (int)$st->fetchColumn();
  } else {
    $kpi['ap_outstanding'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases_payment_ap WHERE deleted_at IS NULL AND status IN ('OPEN','PARTIAL')")->fetchColumn();
  }

  // unpaid amount
  if ($canPrice) {
    if ($scopeOfficeSql !== '') {
      $st = $pdo->prepare("SELECT COALESCE(SUM(balance_amount),0) FROM purchases_payment_ap WHERE deleted_at IS NULL AND status IN ('OPEN','PARTIAL'){$scopeOfficeSql}");
      $st->execute($scopeParams);
      $kpi['ap_unpaid'] = (float)$st->fetchColumn();
    } else {
      $st = $pdo->query("SELECT COALESCE(SUM(balance_amount),0) FROM purchases_payment_ap WHERE deleted_at IS NULL AND status IN ('OPEN','PARTIAL')");
      $kpi['ap_unpaid'] = (float)$st->fetchColumn();
    }
  }
} catch(Exception $e) {
  // silent - dashboard tetap render
}

require_once __DIR__ . '/../_shared/rmi_layout.php';

$actions = [
  ['label'=>'HRL Process', 'url'=>'../hrl_process/index.php', 'class'=>'btn btn-sm btn-outline-light'],
  ['label'=>'Master Manufactures', 'url'=>'../master/master_manufactures.php', 'class'=>'btn btn-sm btn-outline-light'],
  ['label'=>'Stock (WQS)', 'url'=>'../stock/index.php', 'class'=>'btn btn-sm btn-outline-light'],
  ['label'=>'Absensi', 'url'=>'../absensi/index.php', 'class'=>'btn btn-sm btn-outline-light'],
];

if (file_exists(__DIR__ . '/purchases_import_control_tower.php')) {
  $actions[] = ['label'=>'Import Control Tower', 'url'=>'purchases_import_control_tower.php', 'class'=>'btn btn-sm btn-outline-light'];
}

rmi_header('Purchases Dashboard', [
  'active' => 'purchases',
  'subtitle' => 'Flow: WQS PR → PQP PO → FIN AP/Payment → WQS Incoming',
  'breadcrumbs' => [
    ['label'=>'Purchases', 'url'=>'index.php'],
    'Dashboard',
  ],
  'actions' => $actions,
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

<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <div class="fw-semibold">KPI Snapshot</div>
          <div class="rmi-muted">Ringkasan cepat status PR/PO/AP (nilai nominal hanya tampil untuk role yang berhak).</div>
        </div>
        <div class="rmi-muted">
          Buy price visibility: <?= p_can_view_buy_price() ? '<span class="badge text-bg-success">ON</span>' : '<span class="badge text-bg-secondary">OFF</span>' ?>
        </div>
      </div>

      <div class="row g-3 mt-1">
        <div class="col-md-3">
          <div class="rmi-card p-3 h-100">
            <div class="rmi-muted">PR Submitted</div>
            <div class="fs-4 fw-bold"><?= (int)$kpi['pr_submitted'] ?></div>
            <div class="rmi-muted">Menunggu dibuat PO</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="rmi-card p-3 h-100">
            <div class="rmi-muted">PO Open</div>
            <div class="fs-4 fw-bold"><?= (int)$kpi['po_open'] ?></div>
            <div class="rmi-muted">OPEN / IN_PRODUCTION / READY</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="rmi-card p-3 h-100">
            <div class="rmi-muted">PO Value (This Month)</div>
            <div class="fs-4 fw-bold"><?= p_can_view_buy_price() ? number_format((float)$kpi['po_value_month'], 0, ',', '.') : '—' ?></div>
            <div class="rmi-muted"><?= p_can_view_buy_price() ? 'Total amount PO bulan ini' : 'Restricted' ?></div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="rmi-card p-3 h-100">
            <div class="rmi-muted">AP Outstanding</div>
            <div class="fs-4 fw-bold"><?= (int)$kpi['ap_outstanding'] ?></div>
            <div class="rmi-muted"><?= p_can_view_buy_price() ? ('Unpaid: ' . number_format((float)$kpi['ap_unpaid'], 0, ',', '.')) : 'Balance restricted' ?></div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">1) WQS Purchase Request (PR)</div>
      <div class="rmi-muted mb-3">Input kebutuhan barang → submit untuk diproses Purchases.</div>
      <a class="btn btn-outline-light btn-sm" href="../stock/wqs_pr.php">Open PR</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">2) Purchases PO</div>
      <div class="rmi-muted mb-3">Buat PO dari PR, update status produksi, cetak PO.</div>
      <a class="btn btn-rmi btn-sm" href="purchases_po.php">Open PO</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">3) FIN Accounts Payable</div>
      <div class="rmi-muted mb-3">Catat invoice / AP dari PO, pembayaran, dan status.</div>
      <a class="btn btn-outline-light btn-sm" href="purchases_payment_ap.php">Open AP</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">4) WQS Incoming</div>
      <div class="rmi-muted mb-3">Terima barang berdasarkan PO/DO, masuk stok & audit trail.</div>
      <a class="btn btn-outline-light btn-sm" href="../stock/wqs_incoming.php">Open Incoming</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Forwarding Tasks</div>
      <div class="rmi-muted mb-3">Daftar tugas forwarding (shipment/clearance) terkait PO.</div>
      <a class="btn btn-outline-light btn-sm" href="purchases_forwarding_tasks.php">Open Tasks</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Bank Reconciliation</div>
      <div class="rmi-muted mb-3">Import statement CSV, auto/manual match transaksi ERP, lock period.</div>
      <a class="btn btn-outline-light btn-sm" href="bank_recon.php">Open Bank Recon</a>
    </div>
  </div>

  <?php if (file_exists(__DIR__ . '/purchases_import_control_tower.php')): ?>
    <div class="col-md-6 col-lg-4">
      <div class="rmi-card p-3 h-100">
        <div class="fw-semibold mb-1">Import Control Tower</div>
        <div class="rmi-muted mb-3">Monitor & kontrol proses import (tracking, status, SLA).</div>
        <a class="btn btn-outline-light btn-sm" href="purchases_import_control_tower.php">Open Control Tower</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-1">Catatan UI vFinal</div>
      <div class="rmi-muted">
        Dashboard ini sudah menggunakan unified layout (<code>_shared/rmi_layout.php</code>).
        Modul Purchases lainnya akan mengikuti pola yang sama (header/breadcrumb/actions/cards/tables).
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
