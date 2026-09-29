<?php
/**
 * dashboards/regulatory/panduan.php — Panduan Regulatory & Compliance dashboard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../_panduan_helpers.php';

require_login();
require_once __DIR__ . '/../../_shared/rmi_layout.php';

rmi_header('Panduan Regulatory & Compliance', [
    'active' => 'quality',
    'subtitle' => 'Izin produk, expiry, & dokumen',
    'breadcrumbs' => [
        ['label' => 'Regulatory Dashboard', 'url' => ds_panduan_u('/dashboards/regulatory/license_docs_dashboard.php')],
        'Panduan',
    ],
    'actions' => [
        ['label' => rmi_icon('office').' Regulatory Dashboard', 'url' => ds_panduan_u('/dashboards/regulatory/license_docs_dashboard.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);

echo ds_panduan_styles();
?>

<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px"><?=rmi_icon('office')?></div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Panduan Regulatory &amp; Compliance</div>
  <div class="pnd-desc" style="color:#94a3b8">
    Dashboard ini fokus pada <b>kelengkapan nomor registrasi</b> (AKL/NIE/dll.) dan <b>tanggal kedaluwarsa</b> pada master produk,
    sebagai early warning sebelum operasional atau audit menemukan gap.
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-red">
      <h5 style="color:#fca5a5;margin-bottom:12px"><?=rmi_icon('warn')?> Prioritas</h5>
      <ul class="pnd-list">
        <li>Produk <b>expired</b> atau <b>&lt; 90 hari</b> perlu tindakan: update data, pull stok, atau proses reg ulang.</li>
        <li>SKU tanpa nomor izin — koordinasi dengan <b>PQP</b> dan master data.</li>
      </ul>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:12px"><?=rmi_icon('doc')?> Modul terkait</h5>
      <ul class="pnd-list">
        <li><b>Reg Alkes</b> — alur dokumen registrasi alkes.</li>
        <li><b>Quality &amp; Compliance</b> — kelengkapan incoming &amp; stok.</li>
        <li><b>Master Products</b> — sumber kebenaran field izin &amp; exp_date.</li>
      </ul>
    </div>
  </div>
  <div class="col-12">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:12px"><?=rmi_icon('doc')?> Pintasan</h5>
      <div class="pnd-quick">
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/regulatory/license_docs_dashboard.php')) ?>"><?=rmi_icon('office')?> Regulatory Dashboard</a>
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/quality/panduan.php')) ?>"><?=rmi_icon('check')?> Panduan Quality</a>
        <a href="<?= rmi_h(ds_panduan_u('/hrl_reg_alkes/reg_alkes.php')) ?>"><?=rmi_icon('clipboard')?> Reg Alkes</a>
        <a href="<?= rmi_h(ds_panduan_u('/master/master_products.php')) ?>"><?=rmi_icon('box')?> Master Products</a>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
