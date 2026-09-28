<?php
/**
 * dashboards/owner/panduan.php — Panduan Executive Summary.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../_panduan_helpers.php';

if (function_exists('require_any_permission')) {
    require_any_permission(['DASHBOARD.OWNER_SUMMARY']);
} elseif (function_exists('require_login')) {
    require_login();
}

require_once __DIR__ . '/../../_shared/rmi_layout.php';

rmi_header('Panduan Executive Summary', [
    'active' => 'exec_summary',
    'subtitle' => 'Ringkasan eksekutif & KPI manual',
    'breadcrumbs' => [
        ['label' => 'Dashboard Center', 'url' => ds_panduan_u('/dashboards/index.php')],
        ['label' => 'Executive Summary', 'url' => ds_panduan_u('/dashboards/owner/exec_summary.php')],
        'Panduan',
    ],
    'actions' => [
        ['label' => '⭐ Executive Summary', 'url' => ds_panduan_u('/dashboards/owner/exec_summary.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);

echo ds_panduan_styles();
?>

<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px">⭐</div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Panduan Executive Summary</div>
  <div class="pnd-desc" style="color:#94a3b8">
    Halaman ini menampilkan <b>KPI live</b> (omzet, AR, inventori proxy, pipeline PO) dan <b>KPI manual</b> yang diisi lewat konfigurasi sistem (kas, burn, OPEX).
    Gunakan filter <b>periode</b> dan <b>office</b> untuk melihat agregat yang relevan.
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:12px">📊 Membaca ringkasan</h5>
      <ul class="pnd-list">
        <li>Angka yang bertanda <b>manual</b> perlu update rutin oleh tim yang ditunjuk.</li>
        <li>Perbandingan antar-office membantu melihat kontribusi cabang.</li>
        <li>Gabungkan dengan <b>Funnel Overview</b> untuk konteks pipeline penjualan &amp; import.</li>
      </ul>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-amber">
      <h5 style="color:#fcd34d;margin-bottom:12px">⚠️ Data &amp; keamanan</h5>
      <ul class="pnd-list">
        <li>Akses dibatasi permission <b>DASHBOARD.OWNER_SUMMARY</b> — tidak untuk semua staff.</li>
        <li>Jika metrik kosong, cek ketersediaan tabel/kolom di environment Anda.</li>
      </ul>
    </div>
  </div>
  <div class="col-12">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:12px">🔗 Pintasan</h5>
      <div class="pnd-quick">
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/owner/exec_summary.php')) ?>">⭐ Executive Summary</a>
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/funnels.php')) ?>">🎯 Funnel Overview</a>
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/panduan_funnels.php')) ?>">📚 Panduan Funnel</a>
        <a href="<?= rmi_h(ds_panduan_u('/kpi/kpi_center.php')) ?>">📊 KPI Center</a>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
