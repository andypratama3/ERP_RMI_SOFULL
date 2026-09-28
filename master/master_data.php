<?php
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/hrlp_process_rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.VIEW', 'MASTER.ADMIN_CENTER']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN']);
}

// master_data.php — Dashboard Master Data ERP_RMI_SOFULL
// Semua tombol tetap berfungsi, hanya UI wrapper yang dirapikan.

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Data Center', [
    'active'      => 'master',
    'subtitle'    => 'Rizqullah Mediska Indonesia — Pusat Master Data & Operasional ERP.',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
        'Master Data Center',
    ],
    'actions' => [
        ['label' => 'Dashboard Center', 'url' => '../dashboards/index.php',    'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'RBAC',             'url' => '../rbac/index.php',           'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Help Center',      'url' => '../docs/help_center.php',     'class' => 'btn btn-sm btn-outline-light', 'attrs' => 'target="_blank"'],
    ],
]);

// ── Helper: render satu module card ──
function mod_card(string $icon, string $title, string $desc, string $file, string $href): void {
?>
  <div class="col-md-6 col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="d-flex gap-2 align-items-start mb-2">
        <div style="font-size:24px;line-height:1"><?= $icon ?></div>
            <div>
          <div class="fw-semibold"><?= htmlspecialchars($title) ?></div>
          <div class="rmi-muted small"><?= $desc /* safe: hardcoded strings only */ ?></div>
        </div>
    </div>
      <div class="d-flex justify-content-between align-items-center mt-auto">
        <code class="rmi-muted small"><?= htmlspecialchars($file) ?></code>
        <a class="btn btn-rmi btn-sm" href="<?= htmlspecialchars($href) ?>">Buka Modul</a>
        </div>
    </div>
        </div>
<?php
}
?>

<!-- Clock badge -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="rmi-muted small"><span id="rmi-clock"></span></div>
  <div class="badge rmi-badge">Delegasi per departemen · SUPERADMIN/ADMIN Only</div>
        </div>

<!-- ═══ PQP — Product & Manufactures ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">PQP — Product & Manufactures (Pabrik)</div>
  <div class="rmi-muted mb-3">Owner data: Products & Manufactures. Dipakai lintas departemen (HRL/WQS/CRM/FIN/SCM).</div>
  <div class="row g-3">
    <?php mod_card('📦', 'Master Products', 'Produk single & paket, termasuk foto & video. Referensi utama penawaran, penjualan & stok.', 'master_products.php', 'master_products.php') ?>
    <?php mod_card('🏭', 'Master Manufactures (Pabrik)', 'Data pabrikan / manufacture untuk produk. Dimiliki & dikelola oleh departemen PQP.', 'master_manufactures.php', 'master_manufactures.php') ?>
    <?php mod_card('🔐', 'Manufacturer Portal Users', 'User login portal pabrikan (Reg Alkes). Upload dokumen registrasi mandiri via portal.', 'master_manufacturer_portal_users.php', 'master_manufacturer_portal_users.php') ?>
            </div>
        </div>

<!-- ═══ HRL — People & Struktur ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">HRL — People & Struktur</div>
  <div class="rmi-muted mb-3">Data internal: Employees & Departements (struktur organisasi & PIC).</div>
  <div class="row g-3">
    <?php mod_card('🧑‍💼', 'Master Employees', 'Data karyawan internal. Terhubung ke departemen, kantor, level & golongan.', 'master_employees.php', 'master_employees.php') ?>
    <?php mod_card('🗂️', 'Master Departements', 'Daftar departemen & level (Manager / Staff) per kantor.', 'master_departements.php', 'master_departements.php') ?>
    <?php mod_card('⏱️', 'Absensi Enterprise', 'Check-in/out, request izin/sakit/dinas, approval, rekap & office settings.', 'absensi/index.php', '../absensi/index.php') ?>
    <?php mod_card('💰', 'Payroll (HRL)', 'Proses payroll per periode (run), integrasi absensi, export CSV & payslip.', 'payroll/index.php', '../payroll/index.php') ?>
            </div>
        </div>

<!-- ═══ CRM / MPR — Customer & Selling Support ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">CRM / MPR — Customer & Selling Support</div>
  <div class="rmi-muted mb-3">Customer master, PIC customer, dan list harga jual (tanpa buy) untuk penawaran.</div>
  <div class="row g-3">
    <?php mod_card('🧑‍⚕️', 'Master Customers', 'Data pelanggan (RS, klinik, dll). Termasuk cover office, NPWP, maps & PIC staff.', 'master_customers.php', 'master_customers.php') ?>
    <?php mod_card('🔐', 'Customer Portal Users', 'User login portal B2B (Hermina, dll). PIC Purchasing order mandiri via portal.', 'master_customer_portal_users.php', 'master_customer_portal_users.php') ?>
    <?php mod_card('🤝', 'Master User / PIC Customers', 'PIC eksternal per customer: Direktur, Purchasing, Finance, Warehouse, dll.', 'master_user.php', 'master_user.php') ?>
    <?php mod_card('🏷️', 'Pricelist Jual (CRM / MPR)', 'View-only daftar harga jual (tanpa buy). Bisa filter Office/Customer + Export/Print.', 'master_pricelist_sell.php', 'master_pricelist_sell.php') ?>
        </div>
    </div>

<!-- ═══ SCM — Vendor ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">SCM — Vendor (Logistik/Jasa) & Forwarding</div>
  <div class="rmi-muted mb-3">Vendor / rekanan jasa (forwarding/logistik) untuk pengiriman & import.</div>
  <div class="row g-3">
    <?php mod_card('🚚', 'Master Vendors (Logistik/Jasa)', 'Data vendor (logistik/jasa) pendukung SCM, kantor, dsb. Termasuk PIC & rekening pembayaran.', 'master_vendors.php', 'master_vendors.php') ?>
            </div>
        </div>

<!-- ═══ FIN — Pricing, Tax, Payment ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">FIN — Pricing, Tax, Payment</div>
  <div class="rmi-muted mb-3">Harga jual final untuk Sales DO, pajak, dan terms pembayaran.</div>
  <div class="row g-3">
    <?php $plDept = strtoupper(trim((string)($_SESSION['department'] ?? ''))); $plRole = strtoupper(trim((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? ''))); $plLevel = strtoupper(trim((string)($_SESSION['level'] ?? ''))); $showPricelistBuy = in_array($plRole, ['ADMIN','SUPERADMIN','SYS'], true) || in_array($plLevel, ['ADMIN','SUPERADMIN','SYS'], true) || in_array($plDept, ['FIN','PQP','SYS'], true); ?>
    <?php if ($showPricelistBuy): ?>
    <?php mod_card('💰', 'Master Pricelist (Buy → Sell Auto)', 'FIN/PQP/Admin set Buy + Markup% → Sell otomatis. Hanya Admin/PQP/FIN.', 'master_pricelist.php', 'master_pricelist.php') ?>
    <?php endif; ?>
    <?php mod_card('⏱️', 'Master Payment Terms', 'Aturan termin pembayaran (CBD, COD, TOP 7/14/30/45/60).', 'master_payment_terms.php', 'master_payment_terms.php') ?>
    <?php mod_card('📑', 'Master Tax Profile', 'Profil pajak (PPN, PPh) per kantor. Dipakai di DO, Invoice & laporan pajak.', 'master_tax.php', 'master_tax.php') ?>
            </div>
        </div>

<!-- ═══ SYSTEM — Office & Config ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">SYSTEM — Office & Config</div>
  <div class="rmi-muted mb-3">Kantor, email perusahaan, dan konfigurasi sistem (nomor dokumen, default config).</div>
  <div class="row g-3">
    <?php mod_card('🏢', 'Master Office', 'Data kantor / depo / cabang. Untuk mapping cover area, delegasi wilayah & pengiriman.', 'master_office.php', 'master_office.php') ?>
    <?php mod_card('✉️', 'Master Email Company', 'Email resmi kantor & departemen dengan domain <b>@rizqullahmediska.com</b>.', 'master_emailcompany.php', 'master_emailcompany.php') ?>
    <?php mod_card('⚙️', 'System Config & Numbering', 'Atur pola penomoran (DO, Invoice, Quotation, Employee) & default tax/payment per kantor.', 'master_system_config.php', 'master_system_config.php') ?>
    <?php mod_card('🔑', 'API Partner Keys', 'Kelola API key untuk partner eksternal. Hanya ADMIN & SUPERADMIN.', 'api_partner_keys.php', 'api_partner_keys.php') ?>
        </div>
    </div>

<!-- ═══ OPERASIONAL — SALES (O2C) ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">OPERASIONAL — SALES (O2C)</div>
  <div class="rmi-muted mb-3">Order-to-Cash: <b>CRM → WQS → SCM → ACT → FIN</b>. Shortcut task per departemen + monitoring end-to-end.</div>
  <div class="row g-3">
    <?php mod_card('🧾', 'Sales Dashboard (O2C Overview)', 'Ringkasan flow & beban kerja per stage (read-only). Termasuk KPI/Audit/SLA.', 'sales/sales_dashboard.php', '../sales/sales_dashboard.php') ?>
    <?php mod_card('🛰️', 'Sales Control Tower', 'Monitoring status DO per tahap + quick drilldown (CRM/WQS/SCM/ACT/FIN).', 'sales/sales_control_tower.php', '../sales/sales_control_tower.php') ?>
    <?php mod_card('🧑‍💼', 'CRM — Sales DO (Create & Manage)', 'Buat Delivery Order / Order, pilih customer & item, kirim ke WQS.', 'sales/sales_do.php', '../sales/sales_do.php') ?>
    <?php mod_card('📦', 'WQS — DO Tasks (Stock Check)', 'Cek stok, foto kartu stok before/after, set <b>READY SCM</b>.', 'stock/wqs_do_tasks.php', '../stock/wqs_do_tasks.php') ?>
    <?php mod_card('🚚', 'SCM — DO Tasks (Delivery)', 'Atur pengiriman, assign vendor/logistik, update status <b>ON DELIVERY</b>.', 'sales/scm_do_tasks.php', '../sales/scm_do_tasks.php') ?>
    <?php mod_card('🧮', 'ACT — DO Tasks (Delivered)', 'Konfirmasi delivered + kelengkapan dokumen (handover ke FIN).', 'sales/act_do_tasks.php', '../sales/act_do_tasks.php') ?>
    <?php mod_card('💳', 'FIN — DO Tasks (AR / Payment)', 'Kelola status <b>WAIT PAYMENT</b> → <b>PAID</b> + monitoring piutang.', 'sales/fin_do_tasks.php', '../sales/fin_do_tasks.php') ?>
    <?php mod_card('📜', 'KPI — DO Audit', 'Audit perubahan status DO (siapa, kapan, dari mana ke mana).', 'sales/kpi_do_audit.php', '../sales/kpi_do_audit.php') ?>
    <?php mod_card('⏱️', 'KPI — DO SLA', 'Monitoring SLA per stage (WQS/SCM/ACT/FIN) untuk deteksi overdue.', 'sales/kpi_do_sla.php', '../sales/kpi_do_sla.php') ?>
            </div>
        </div>

<!-- ═══ OPERASIONAL — PROCUREMENT / IMPORT (P2P) ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">OPERASIONAL — PROCUREMENT / IMPORT (P2P)</div>
  <div class="rmi-muted mb-3">Procure-to-Pay: <b>PR → PO → GR → AP → Payment</b>. Melibatkan PQP/SCM/WQS/FIN/ACT.</div>
  <div class="row g-3">
    <?php mod_card('🛒', 'PQP Dashboard', 'Ringkasan PR/PO/AP + shortcut proses pembelian & import.', 'purchases/purchases_dashboard.php', '../purchases/purchases_dashboard.php') ?>
    <?php mod_card('🧭', 'Import Control Tower', 'Monitor pipeline import (PO → PIB/CEISA → GR → AP) & task forwarding.', 'purchases/purchases_import_control_tower.php', '../purchases/purchases_import_control_tower.php') ?>
    <?php mod_card('📝', 'WQS — PR (Purchase Request)', 'Buat PR kebutuhan gudang/operasional untuk diproses menjadi PO.', 'stock/wqs_pr.php', '../stock/wqs_pr.php') ?>
    <?php mod_card('🧾', 'PQP/SCM — Purchase Order (PO)', 'Buat & kelola PO, supplier/manufacture, pricing, dokumen import.', 'purchases/purchases_po.php', '../purchases/purchases_po.php') ?>
    <?php mod_card('📥', 'WQS — Goods Receipt (GR)', 'Terima barang, input batch/exp, update stok & sinkron ke PO.', 'purchases/purchases_gr.php', '../purchases/purchases_gr.php') ?>
    <?php mod_card('🧾', 'FIN — AP Invoice', 'Catat invoice supplier/forwarder (AP) + status UNPAID/PARTIAL/PAID.', 'purchases/purchases_invoice_ap.php', '../purchases/purchases_invoice_ap.php') ?>
    <?php mod_card('💸', 'FIN/ACT — AP Payment', 'Input pembayaran AP, cicilan/partial, bukti transfer, rekonsiliasi.', 'purchases/purchases_payment_ap.php', '../purchases/purchases_payment_ap.php') ?>
    <?php mod_card('🚢', 'SCM — Forwarding Tasks', 'Task forwarding: quotes, invoice forwarder, pembayaran & tracking.', 'purchases/purchases_forwarding_tasks.php', '../purchases/purchases_forwarding_tasks.php') ?>
    <?php mod_card('🛃', 'Import — CEISA/PIB', 'Data PIB/CEISA & clearance — utama ACT; PQP/SCM koordinasi.', 'purchases/purchases_ceisa_pib.php', '../purchases/purchases_ceisa_pib.php') ?>
    <?php mod_card('📊', 'Purchases Reports', 'Laporan PR/PO/GR/AP + export untuk kontrol biaya & vendor.', 'purchases/purchases_reports.php', '../purchases/purchases_reports.php') ?>
        </div>
    </div>

<!-- ═══ OPERASIONAL — WAREHOUSE (WQS) ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">OPERASIONAL — WAREHOUSE (WQS)</div>
  <div class="rmi-muted mb-3">Operasional gudang: stock, incoming, allocation, picking, adjustment, audit.</div>
  <div class="row g-3">
    <?php mod_card('📦', 'WQS Stock Center', 'Stok per SKU/batch/exp, monitoring qty, filtering, stock insight.', 'stock/wqs_stock.php', '../stock/wqs_stock.php') ?>
    <?php mod_card('📥', 'WQS Incoming', 'Proses incoming barang (PO/GR), batch/exp & penempatan awal.', 'stock/wqs_incoming.php', '../stock/wqs_incoming.php') ?>
    <?php $trRole = function_exists('auth_role') ? auth_role() : ($_SESSION['role'] ?? ''); if (in_array(strtoupper(trim((string)$trRole)), ['ADMIN','SUPERADMIN','SYS'], true)): ?>
    <?php mod_card('🔄', 'WQS Transfer', 'Transfer antar kantor (Admin only). Kantor gunakan Pembelian (PO + Sales DO).', 'stock/wqs_stock_transfer.php', '../stock/wqs_stock_transfer.php') ?>
    <?php endif; ?>
    <?php mod_card('🧩', 'WQS Allocation', 'Alokasi stok untuk DO/permintaan, menjaga FIFO/FEFO & ketersediaan.', 'stock/wqs_allocation.php', '../stock/wqs_allocation.php') ?>
    <?php mod_card('🧺', 'WQS Picking', 'Picking list + konfirmasi pengambilan stok untuk DO / outbound.', 'stock/wqs_picking.php', '../stock/wqs_picking.php') ?>
    <?php mod_card('🛠️', 'Stock Adjustment', 'Penyesuaian stok (koreksi) dengan audit trail & alasan.', 'stock/wqs_stock_adjustment.php', '../stock/wqs_stock_adjustment.php') ?>
    <?php mod_card('🔍', 'Stock Audit', 'Audit stok & perubahan (history/trace) untuk kontrol internal.', 'stock/wqs_stock_audit.php', '../stock/wqs_stock_audit.php') ?>
    <?php if (in_array(strtoupper(trim((string)$trRole)), ['ADMIN','SUPERADMIN','SYS'], true)): ?>
    <?php mod_card('🔄', 'Setup Pembelian Antar Kantor', 'Seed Customer Internal & Manufacture Kantor (BGR-INT, KANTOR-BKS, dll).', 'tools/setup/seed_pembelian_antar_kantor.php', '../tools/setup/seed_pembelian_antar_kantor.php') ?>
    <?php endif; ?>
            </div>
        </div>

<!-- ═══ ANALYTICS — Dashboard & KPI ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">ANALYTICS — Dashboard & KPI</div>
  <div class="rmi-muted mb-3">Dashboard lintas departemen + KPI Center (daily/monthly snapshot).</div>
  <div class="row g-3">
    <?php mod_card('🪟', 'Dashboard Center', 'Pusat dashboard per departemen (Sales/WQS/Procurement/Finance/Regulatory).', 'dashboards/index.php', '../dashboards/index.php') ?>
    <?php mod_card('📚', 'Panduan per modul ERP', 'Satu ringkasan per modul + tabel halaman utama; deep-dive hanya untuk layar kritis.', 'docs/modules_hub.php', '../docs/modules_hub.php') ?>
    <?php mod_card('📈', 'KPI Center', 'KPI Center: pilih metrik, export, snapshot & KPI per modul.', 'kpi/kpi_center.php', '../kpi/kpi_center.php') ?>
    <?php mod_card('🗓️', 'KPI Dashboard (Daily)', 'Ringkasan harian KPI: sales, purchases, stock, employee.', 'kpi/kpi_dashboard_daily.php', '../kpi/kpi_dashboard_daily.php') ?>
    <?php mod_card('📅', 'KPI Dashboard (Monthly)', 'Ringkasan bulanan KPI untuk monitoring target & performa.', 'kpi/kpi_dashboard_monthly.php', '../kpi/kpi_dashboard_monthly.php') ?>
            </div>
        </div>

<!-- ═══ SUPPORT — MPR / HR / Fixed Asset ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">SUPPORT — MPR / HR / Fixed Asset</div>
  <div class="rmi-muted mb-3">Modul pendukung operasional (MPR, HR docs/process, Fixed Asset, Regulatory).</div>
  <div class="row g-3">
    <?php mod_card('📣', 'MPR Dashboard', 'Planning & realisasi MPR, daily ops, budget FIN, dan tracking aktivitas.', 'mpr/mpr_dashboard.php', '../mpr/mpr_dashboard.php') ?>
    <?php mod_card('🗺️', 'MPR Plans', 'Buat & kelola plan MPR + view detail per plan.', 'mpr/mpr_plans.php', '../mpr/mpr_plans.php') ?>
    <?php mod_card('🗂️', 'HRL Docs', 'Dokumen HRL + acknowledgement report + download/view.', 'hrl/hrl_docs.php', '../hrl/hrl_docs.php') ?>
    <?php if (hrlp_can_enter_module()): ?>
    <?php mod_card('🏢', 'HRL Process Tower', 'Request/approval process HRL (tower + print + download).', 'hrl_process/tower.php', '../hrl_process/tower.php') ?>
    <?php endif; ?>
    <?php mod_card('🏷️', 'Fixed Asset Center', 'Aset tetap: data aset, depresiasi, audit & laporan pajak tahunan.', 'Fixed_Asset/index.php', '../Fixed_Asset/index.php') ?>
    <?php mod_card('📑', 'Regulatory Alkes — Control Tower', 'Control tower dokumen/regulasi alkes (case, NIE/SKU, monitoring).', 'hrl_reg_alkes/reg_alkes_control_tower.php', '../hrl_reg_alkes/reg_alkes_control_tower.php') ?>
        </div>
    </div>

<!-- ═══ SYSTEM — Security & Tools ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">SYSTEM — Security & Tools</div>
  <div class="rmi-muted mb-3">RBAC, audit, dan tools internal untuk admin/superadmin.</div>
  <div class="row g-3">
    <?php mod_card('📡', 'Monitoring & Control Center', 'Ringkasan audit DB, file log di storage, dan pintasan Sales/Import/KPI/Tools.', 'monitoring_center.php', 'monitoring_center.php') ?>
    <?php mod_card('🔐', 'RBAC Center', 'Kelola role/permission + mapping akses modul.', 'rbac/index.php', '../rbac/index.php') ?>
    <?php mod_card('🧾', 'Enterprise Audit Viewer', 'Viewer audit terpusat (aksi penting, perubahan data, tracking).', 'tools/enterprise_audit.php', '../tools/enterprise_audit.php') ?>
    <?php mod_card('🔑', 'Reset Password (User)', 'Reset password user internal — hanya akun dengan `SYSTEM.USER_MANAGE` (SYS). Audit: PASSWORD_CHANGED.', 'itc_reset_password.php', 'itc_reset_password.php') ?>
    <?php mod_card('🛡️', 'Security & MFA Policy', 'Kebijakan MFA per role/dept (bukan include helper security.php).', 'mfa_policy.php', 'mfa_policy.php') ?>
    <?php mod_card('🔐', 'MFA Settings', 'Pengaturan MFA per user/perangkat (halaman settings).', 'mfa_settings.php', 'mfa_settings.php') ?>
    <?php if (function_exists('auth_is_sys_tier') && auth_is_sys_tier()): ?>
    <?php mod_card('⚠️', 'MFA Reset User (SYS)', 'Nonaktifkan MFA untuk akun lain — hanya privileged; audit + konfirmasi username.', 'mfa_admin_reset.php', 'mfa_admin_reset.php') ?>
    <?php endif; ?>
    <?php mod_card('📬', 'Import Rekening Final', 'Import rekening final untuk kebutuhan pembayaran/finance.', 'import_rekening_final.php', 'import_rekening_final.php') ?>
                </div>
            </div>

<!-- ═══ MASTER EXTENDED — Import/Export/Docs Utilities ═══ -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-1">MASTER EXTENDED — Import/Export/Docs Utilities</div>
  <div class="rmi-muted mb-3">Shortcut halaman master tambahan (legacy/utility) agar audit & trial end-to-end lebih lengkap dari satu tempat.</div>
  <div class="row g-3">
    <?php mod_card('🗃️', 'Master Departments (Alias)', 'Alias/legacy endpoint dari master departements untuk kompatibilitas lama.', 'master_departments.php', 'master_departments.php') ?>
    <?php mod_card('📤', 'Export Customers', 'Export data customer ke format file untuk audit/migrasi/checkpoint.', 'master_export_customers.php', 'master_export_customers.php') ?>
    <?php mod_card('🏭', 'Manufactures Docs', 'Kelola/lihat dokumen pabrikan (support regulatory & procurement).', 'manufactures_docs.php', 'manufactures_docs.php') ?>

    <?php mod_card('🧩', 'Products Doc (Master)', 'Kelola dokumen produk dari sisi master.', 'master_products_doc.php', 'master_products_doc.php') ?>
    <?php mod_card('📦', 'Products Package (Master)', 'Kelola paket/bundling produk dari sisi master.', 'master_products_package.php', 'master_products_package.php') ?>
    <?php mod_card('🖨️', 'Products Print (Master)', 'Template/preview print produk dari sisi master.', 'master_products_print.php', 'master_products_print.php') ?>

    <?php mod_card('🖼️', 'Products Media View', 'Viewer media produk (gambar/video/file) untuk verifikasi cepat.', 'products_media_view.php', 'products_media_view.php') ?>
    </div>
</div>

<script>
    function updateClock() {
    var el = document.getElementById('rmi-clock');
        if (!el) return;
    var now = new Date();
    el.textContent = now.toLocaleDateString('id-ID', {weekday:'short',year:'numeric',month:'short',day:'numeric'})
      + ' · ' + now.toLocaleTimeString('id-ID', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
    }
    updateClock();
    setInterval(updateClock, 1000);
</script>

<?php rmi_footer(); ?>
