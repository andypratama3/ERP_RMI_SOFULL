<?php
/**
 * dashboards/warehouse/panduan.php — Panduan Warehouse (WQS) Dashboard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../_panduan_helpers.php';

if (function_exists('require_login')) {
    require_login();
}
require_once __DIR__ . '/../../_shared/rmi_layout.php';

rmi_header('Panduan Warehouse (WQS)', [
    'active' => 'stock',
    'subtitle' => 'Stok, incoming, picking, expiry',
    'breadcrumbs' => [
        ['label' => 'Warehouse Dashboard', 'url' => ds_panduan_u('/dashboards/warehouse/wqs_dashboard.php')],
        'Panduan',
    ],
    'actions' => [
        ['label' => '📦 Warehouse Dashboard', 'url' => ds_panduan_u('/dashboards/warehouse/wqs_dashboard.php'), 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => '📚 Panduan Modul Stok', 'url' => ds_panduan_u('/stock/panduan.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);

echo ds_panduan_styles();
?>

<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px">📦</div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Panduan Warehouse (WQS)</div>
  <div class="pnd-desc" style="color:#94a3b8">
    Dashboard gudang merangkum <b>stok per office</b>, <b>incoming</b>, <b>picking/alokasi</b>, barang mendekati/melewati <b>expiry</b>, dan antrian <b>DO</b>.
    Detail operasional harian ada di modul <b>Stock/WQS</b> — lihat juga <b>Panduan Modul Stok</b>.
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-teal">
      <h5 style="color:#2dd4bf;margin-bottom:12px">✅ Fokus operasional</h5>
      <ul class="pnd-list">
        <li>Selesaikan <b>picking</b> dan konfirmasi pengiriman sesuai prioritas DO.</li>
        <li>Rekam <b>incoming</b> dengan lengkap (lot, exp, serial) untuk produk regulasi.</li>
        <li>Pantau kartu <b>expired / near expiry</b> untuk rotasi stok.</li>
      </ul>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-amber">
      <h5 style="color:#fcd34d;margin-bottom:12px">🏢 Scope cabang</h5>
      <ul class="pnd-list">
        <li>Staff cabang biasanya terbatas pada <b>office</b> mereka — jangan asumsi angka global tanpa cek filter.</li>
      </ul>
      <div class="pnd-tip">Untuk langkah detail (PR, picking, incoming), buka halaman <b>stock/panduan.php</b>.</div>
    </div>
  </div>
  <div class="col-12">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:12px">🔗 Pintasan</h5>
      <div class="pnd-quick">
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/warehouse/wqs_dashboard.php')) ?>">📦 Warehouse Dashboard</a>
        <a href="<?= rmi_h(ds_panduan_u('/stock/panduan.php')) ?>">📚 Panduan Stok (lengkap)</a>
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/quality/panduan.php')) ?>">✅ Panduan Quality</a>
        <a href="<?= rmi_h(ds_panduan_u('/stock/wqs_incoming.php')) ?>">📥 WQS Incoming</a>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
