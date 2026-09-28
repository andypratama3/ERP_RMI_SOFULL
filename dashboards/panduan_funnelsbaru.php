<?php
/**
 * dashboards/panduan_funnels.php — Panduan Funnel Overview.
 */
declare(strict_types=1);

require_once __DIR__ . '/_dashboard_bootstrap.php';
require_once __DIR__ . '/_panduan_helpers.php';

require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['DASHBOARD.VIEW', 'DASHBOARD.SALES_VIEW', 'DASHBOARD.OWNER_VIEW', 'DASHBOARD.OWNER_SUMMARY']);
}

require_once __DIR__ . '/../_shared/rmi_layout.php';

rmi_header('Panduan Funnel Overview', [
    'active' => 'dashboard',
    'subtitle' => 'CRM · Sales DO · Reg Alkes · Import/PO',
    'breadcrumbs' => [
        ['label' => 'Dashboard Center', 'url' => ds_panduan_u('/dashboards/index.php')],
        ['label' => 'Funnel Overview', 'url' => ds_panduan_u('/dashboards/funnels.php')],
        'Panduan',
    ],
    'actions' => [
        ['label' => '🎯 Buka Funnel', 'url' => ds_panduan_u('/dashboards/funnels.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);

echo ds_panduan_styles();
?>

<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px">🎯</div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Panduan Funnel Overview</div>
  <div class="pnd-desc" style="color:#94a3b8">
    Ringkasan read-only dari beberapa alur utama: <b>CRM Leads</b>, tahap <b>Sales DO</b>, pipeline <b>Reg Alkes</b>, dan <b>Import/PO</b>.
    Cocok untuk melihat bottleneck tanpa membuka tiap modul.
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:12px">📊 Membaca halaman</h5>
      <ul class="pnd-list">
        <li>Atur <b>Date From / To</b> untuk membatasi periode CRM &amp; DO.</li>
        <li>Persentase konversi CRM bersifat indikatif; detail ada di modul CRM.</li>
        <li>Bagian Import/PO menggambarkan posisi PO aktif, PIB, GR, dan AP (jika tabel tersedia).</li>
      </ul>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-amber">
      <h5 style="color:#fcd34d;margin-bottom:12px">⚠️ Batasan</h5>
      <ul class="pnd-list">
        <li>Halaman ini <b>tidak mengganti</b> laporan resmi di modul sumber.</li>
        <li>Jika angka kosong, kemungkinan tabel belum ada data atau kolom berbeda versi DB.</li>
      </ul>
      <div class="pnd-info">API JSON internal tersedia dari tombol di header Funnel untuk integrasi/monitoring.</div>
    </div>
  </div>
  <div class="col-12">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:12px">🔗 Lanjutkan ke</h5>
      <div class="pnd-quick">
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/funnels.php')) ?>">🎯 Funnel Overview</a>
        <a href="<?= rmi_h(ds_panduan_u('/sales/panduan.php')) ?>">💼 Panduan Sales</a>
        <a href="<?= rmi_h(ds_panduan_u('/purchases/panduan.php')) ?>">🛒 Panduan Purchases</a>
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/owner/exec_summary.php')) ?>">⭐ Executive Summary</a>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
