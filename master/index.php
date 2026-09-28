<?php
// master/index.php
// Master Data Hub (Unified Layout)

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
require_any_permission(['MASTER.VIEW']);

require_once __DIR__ . '/../_shared/rmi_layout.php';

// Helper: cek apakah user punya salah satu permission (tanpa die)
function _mi_can(array $perms): bool {
    if (!function_exists('can_any')) return false;
    return can_any($perms);
}

$canProducts   = _mi_can(['MASTER.PRODUCT_CRUD','MASTER.PRODUCT_EDIT']);
$canCustomers  = _mi_can(['MASTER.CUSTOMER_CRUD','MASTER.CUSTOMER_EDIT']);
$canImportCust = _mi_can(['MASTER.IMPORT_CUSTOMERS','MASTER.CUSTOMER_CRUD']);
$canVendors    = _mi_can(['MASTER.VENDOR_CRUD','MASTER.VENDOR_EDIT','MASTER.VENDOR_VIEW']);
$canImportVend = _mi_can(['MASTER.IMPORT_VENDORS','MASTER.VENDOR_CRUD']);
$canMfg        = _mi_can(['MASTER.MANUFACTURE_CRUD']);
$canEmployees  = _mi_can(['MASTER.EMPLOYEE_CRUD']);
$canPricelist  = _mi_can(['MASTER.PRICELIST_SELL_CRUD','MASTER.PRICELIST_SELL_EDIT','MPR.VIEW','MASTER.PRICELIST_BUY_CRUD','MASTER.ADMIN_CENTER']);
$canITC        = _mi_can(['SYSTEM.USER_MANAGE']);
$canMfa        = _mi_can(['SYSTEM.MFA_POLICY_MANAGE','SYSTEM.USER_MANAGE','SYSTEM.CONFIG_MANAGE']);
$canMfaBp      = _mi_can(['SYSTEM.MFA_BYPASS_MANAGE','SYSTEM.USER_MANAGE']);
$canJobs       = _mi_can(['SYSTEM.JOBS_MONITOR','SYSTEM.USER_MANAGE','SYSTEM.CONFIG_MANAGE']);
$canRateLimit  = _mi_can(['SYSTEM.RATE_LIMIT_MANAGE','SYSTEM.CONFIG_MANAGE']);
$canAudit      = _mi_can(['SYSTEM.JOBS_MONITOR','SYSTEM.USER_MANAGE','SYSTEM.CONFIG_MANAGE','MASTER.ADMIN_CENTER']);
$canGL         = _mi_can(['PURCHASES.AP_PAYMENT_CRUD','MASTER.ADMIN_CENTER','SYSTEM.CONFIG_MANAGE']);
$canNavMgr     = _mi_can(['SYSTEM.CONFIG_MANAGE','SYSTEM.USER_MANAGE','MASTER.ADMIN_CENTER']);
$canOrgSysEdit = function_exists('auth_is_sys') && auth_is_sys();

$hasAny = $canProducts || $canCustomers || $canVendors || $canMfg || $canEmployees
       || $canPricelist || $canITC || $canMfa || $canMfaBp || $canJobs
       || $canRateLimit || $canAudit || $canGL || $canNavMgr || $canOrgSysEdit
       || (function_exists('auth_is_sys_tier') && auth_is_sys_tier());

$actions = [
  ['label'=>'Open Legacy Master Data', 'url'=>'master_data.php', 'class'=>'btn btn-sm btn-outline-light'],
  ['label'=>'Dashboard Center', 'url'=>'../dashboards/index.php', 'class'=>'btn btn-sm btn-outline-light'],
];

rmi_header('Master Data', [
  'active' => 'master',
  'subtitle' => 'Hub master data & konfigurasi',
  'breadcrumbs' => [
    'Master Data'
  ],
  'actions' => $actions,
]);
?>

<div class="row g-3">

<?php if ($canProducts): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Products</div>
      <div class="rmi-muted mb-3">Master SKU, kategori, unit, status, media/dokumen.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="master_products.php">Open</a>
        <?php if (_mi_can(['MASTER.IMPORT_PRODUCTS','MASTER.PRODUCT_CRUD'])): ?>
        <a class="btn btn-outline-light btn-sm" href="master_import_products.php">Import</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canCustomers): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Customers</div>
      <div class="rmi-muted mb-3">Data customer, alamat, NPWP, kontak.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="master_customers.php">Open</a>
        <?php if ($canImportCust): ?>
        <a class="btn btn-outline-light btn-sm" href="master_import_customers.php">Import</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canVendors): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Vendors</div>
      <div class="rmi-muted mb-3">Master vendor/supplier, rekening, kontak.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="master_vendors.php">Open</a>
        <?php if ($canImportVend): ?>
        <a class="btn btn-outline-light btn-sm" href="master_import_vendors.php">Import</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canMfg): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Manufactures / Pabrikan</div>
      <div class="rmi-muted mb-3">Master pabrikan (dipakai Purchases PO, HRL Reg Alkes, dll).</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="master_manufactures.php">Open</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canEmployees): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Employees</div>
      <div class="rmi-muted mb-3">Master karyawan, dept, level, office, kontak.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="master_employees.php">Open</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canPricelist): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Pricelist Jual</div>
      <div class="rmi-muted mb-3">Harga jual per produk/customer/segment.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="master_pricelist_sell.php">Open</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canITC): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">ITC Reset Password</div>
      <div class="rmi-muted mb-3">Reset password akun (ITC only). Akun ADMIN & SUPERADMIN tidak dapat diubah.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="itc_reset_password.php">Open</a>
        <?php if (_mi_can(['SYSTEM.ACCOUNT_READINESS','SYSTEM.USER_MANAGE'])): ?>
        <a class="btn btn-outline-light btn-sm" href="account_readiness.php">Readiness</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canMfa): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">MFA Policy</div>
      <div class="rmi-muted mb-3">Security policy: wajib MFA per role/department.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="mfa_policy.php">Open</a>
        <?php if (function_exists('auth_is_sys_tier') && auth_is_sys_tier()): ?>
        <a class="btn btn-outline-warning btn-sm" href="mfa_admin_reset.php" title="Hanya SYS / Admin">Reset MFA user</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canMfaBp): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">MFA Bypass Tickets</div>
      <div class="rmi-muted mb-3">Bypass sementara MFA dengan expiry dan audit log.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="mfa_bypass.php">Open</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canJobs): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Jobs Monitor</div>
      <div class="rmi-muted mb-3">Monitoring worker queue + retry/run-now/cancel job.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="jobs_monitor.php">Open</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canRateLimit): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Rate Limit Policies</div>
      <div class="rmi-muted mb-3">Konfigurasi threshold API write per scope.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="rate_limit_policies.php">Open</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canOrgSysEdit): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Struktur Organisasi (dokumen)</div>
      <div class="rmi-muted mb-3">Edit JSON dokumen resmi (Office Pack). Hanya SYS. Backup + audit otomatis.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="org_structure_edit.php">Editor</a>
        <a class="btn btn-outline-light btn-sm" href="../docs/link/struktur_organisasi.php">Lihat halaman</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canAudit): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Audit Logs</div>
      <div class="rmi-muted mb-3">Central view audit log semua modul (auth, RBAC, import, dll).</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="audit_logs.php">Open</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canNavMgr): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">Nav Manager</div>
      <div class="rmi-muted mb-3">Atur menu & roles sidebar tanpa edit kode.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="nav_manager.php">Open</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canGL): ?>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-1">GL Reversal Approvals</div>
      <div class="rmi-muted mb-3">Dual-control approval untuk reversal jurnal manual.</div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-rmi btn-sm" href="../purchases/gl_reversal_approvals.php">Open</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (!$hasAny): ?>
  <div class="col-12">
    <div class="rmi-card p-3 text-center">
      <div class="fw-semibold mb-1">Tidak ada modul yang dapat diakses</div>
      <div class="rmi-muted">Hubungi ITC/Admin untuk pengaturan hak akses.</div>
    </div>
  </div>
<?php endif; ?>

</div>

<?php rmi_footer(); ?>
