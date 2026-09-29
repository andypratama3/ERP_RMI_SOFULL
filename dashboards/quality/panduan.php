<?php
/**
 * dashboards/quality/panduan.php — Panduan Quality & Compliance dashboard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
if (function_exists('require_login')) {
    require_login();
}
require_once __DIR__ . '/../_panduan_helpers.php';

require_once __DIR__ . '/../../_shared/rmi_layout.php';

rmi_header('Panduan Quality & Compliance', [
    'active' => 'quality',
    'subtitle' => 'Incoming, stok, kelengkapan data',
    'breadcrumbs' => [
        ['label' => 'Quality Dashboard', 'url' => ds_panduan_u('/dashboards/quality/qc_complaint_dashboard.php')],
        'Panduan',
    ],
    'actions' => [
        ['label' => rmi_icon('check').' Quality Dashboard', 'url' => ds_panduan_u('/dashboards/quality/qc_complaint_dashboard.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);

echo ds_panduan_styles();
?>

<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px"><?=rmi_icon('check')?></div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Panduan Quality &amp; Compliance</div>
  <div class="pnd-desc" style="color:#94a3b8">
    Dashboard ini menyoroti <b>kelengkapan lot/exp/serial</b> pada <b>WQS Incoming</b>, anomali <b>stok</b>, baseline lock, dan
    kelengkapan dasar <b>master produk</b> — tanpa membuat modul CAPA baru di schema.
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:12px"><?=rmi_icon('inbox')?> Incoming</h5>
      <ul class="pnd-list">
        <li>Prioritaskan baris dengan <b>exp / lot / serial</b> kosong sebelum barang dipindahkan jauh dari gudang penerimaan.</li>
        <li>Gunakan daftar “incoming dengan issue” sebagai backlog harian WQS.</li>
      </ul>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-amber">
      <h5 style="color:#fcd34d;margin-bottom:12px"><?=rmi_icon('box')?> Stok &amp; baseline</h5>
      <ul class="pnd-list">
        <li>Stok negatif atau nol perlu dicek: transaksi tertunda, salah input, atau perbedaan opname.</li>
        <li><b>Baseline lock</b> mempengaruhi kebijakan audit — koordinasi dengan tim keuangan/gudang.</li>
      </ul>
    </div>
  </div>
  <div class="col-12">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:12px"><?=rmi_icon('doc')?> Pintasan</h5>
      <div class="pnd-quick">
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/quality/qc_complaint_dashboard.php')) ?>"><?=rmi_icon('check')?> Quality Dashboard</a>
        <a href="<?= rmi_h(ds_panduan_u('/stock/wqs_incoming.php')) ?>"><?=rmi_icon('inbox')?> WQS Incoming</a>
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/warehouse/panduan.php')) ?>"><?=rmi_icon('box')?> Panduan Warehouse</a>
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/regulatory/panduan.php')) ?>"><?=rmi_icon('office')?> Panduan Regulatory</a>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
