<?php
/**
 * Dashboard Center (Unified Layout)
 *
 * Entry point (root /index.php) redirects here.
 * Keep this page light: no heavy DB aggregation; just module hub.
 */

require_once __DIR__ . '/../master/auth.php';
require_login();

require_once __DIR__ . '/../_shared/rmi_layout.php';

$role = strtoupper((string)($_SESSION['role_code'] ?? $_SESSION['role'] ?? ''));
$level = strtoupper((string)($_SESSION['user_level'] ?? $_SESSION['level'] ?? ''));
$isAdmin = in_array($level, ['SUPERADMIN','ADMIN'], true) || in_array($role, ['SUPERADMIN','ADMIN'], true);

rmi_header('Dashboard Center', 'dashboard');
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h2 class="h4 mb-1">Dashboard Center</h2>
    <div class="text-muted">Pusat navigasi modul ERP (tanpa agregasi data berat).</div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">MASTER (Master Data Center)</div>
      <div class="text-muted small mb-3">Products, Customers, Vendors, Employees, Office/Dept, Config.</div>
      <a class="btn btn-outline-light btn-sm" href="../master/master_data.php">Buka Master Data</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">PURCHASES (P2P)</div>
      <div class="text-muted small mb-3">PO, GR, Invoice AP, Payment AP, Forwarding, Import Control, CEISA PIB.</div>
      <a class="btn btn-outline-light btn-sm" href="../purchases/index.php">Buka Purchases</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">SALES (O2C)</div>
      <div class="text-muted small mb-3">Sales DO + taskboard WQS/SCM/ACT/FIN (jika tersedia).</div>
      <a class="btn btn-outline-light btn-sm" href="../sales/sales_dashboard.php">Buka Sales</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">STOCK (WQS)</div>
      <div class="text-muted small mb-3">PR, Incoming, Allocation, Picking, Adjustment, Audit.</div>
      <a class="btn btn-outline-light btn-sm" href="../stock/wqs_stock.php">Buka Stock</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">KPI</div>
      <div class="text-muted small mb-3">Input manual + snapshot + dashboard KPI.</div>
      <a class="btn btn-outline-light btn-sm" href="../kpi/kpi_center.php">Buka KPI Center</a>
    </div>
  </div>

  <?php if ($isAdmin || (function_exists('can') && can('DASHBOARD.FINANCE_VIEW'))): ?>
  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">FINANCE REPORT</div>
      <div class="text-muted small mb-3">AR/AP cash overview + dashboard detail (excel style).</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-light btn-sm" href="../dashboards/finance/ar_ap_cash_dashboard.php">Finance Dashboard</a>
        <a class="btn btn-outline-light btn-sm" href="../dashboards/finance/dashboard_detail.php">Finance Detail</a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">ABSENSI</div>
      <div class="text-muted small mb-3">Checkin/out, request, approval, history.</div>
      <a class="btn btn-outline-light btn-sm" href="../absensi/index.php">Buka Absensi</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">PAYROLL</div>
      <div class="text-muted small mb-3">Matrix, run, payslip, loans, audit, settings.</div>
      <a class="btn btn-outline-light btn-sm" href="../payroll/index.php">Buka Payroll</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">FIXED ASSET</div>
      <div class="text-muted small mb-3">Asset register, depreciation, tax annual, audit.</div>
      <a class="btn btn-outline-light btn-sm" href="../Fixed_Asset/index.php">Buka Fixed Asset</a>
    </div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">SETTINGS (RBAC)</div>
      <div class="text-muted small mb-3">Role, permission, scope. Admin/Superadmin saja.</div>
      <a class="btn btn-outline-light btn-sm" href="../rbac/index.php">Buka RBAC</a>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php rmi_footer();
