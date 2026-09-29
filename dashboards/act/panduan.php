<?php
/**
 * dashboards/act/panduan.php — Panduan ACT (Accounting & Tax) Dashboard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../_panduan_helpers.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();
require_once __DIR__ . '/../../_shared/rmi_layout.php';

rmi_header('Panduan ACT Dashboard', [
    'active' => 'dashboard',
    'subtitle' => 'AP, Task DO, pajak, aset tetap',
    'breadcrumbs' => [
        ['label' => 'ACT Dashboard', 'url' => ds_panduan_u('/dashboards/act/act_dashboard.php')],
        'Panduan',
    ],
    'actions' => [
        ['label' => rmi_icon('memo').' ACT Dashboard', 'url' => ds_panduan_u('/dashboards/act/act_dashboard.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);

echo ds_panduan_styles();
?>

<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px"><?=rmi_icon('memo')?></div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Panduan ACT Dashboard</div>
  <div class="pnd-desc" style="color:#94a3b8">
    ACT fokus pada <b>hutang (AP)</b>, tugas lanjutan terkait <b>DO</b>, <b>faktur pajak</b>, <b>fixed asset</b>, dan rekonsiliasi.
    Gunakan filter periode di dashboard untuk menyelaraskan angka dengan bulan buku.
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-amber">
      <h5 style="color:#fcd34d;margin-bottom:12px"><?=rmi_icon('clipboard')?> Prioritas harian</h5>
      <ul class="pnd-list">
        <li>AP overdue &amp; aging — tindak lanjut ke tim PQP/FIN sesuai SOP.</li>
        <li>Antrian <b>task DO</b> yang menunggu tindakan accounting.</li>
        <li>Draft vs terbitnya <b>faktur pajak</b>.</li>
      </ul>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:12px"><?=rmi_icon('users')?> Koordinasi</h5>
      <ul class="pnd-list">
        <li><b>FIN</b>: arus kas &amp; pembayaran pelanggan.</li>
        <li><b>SCM/WQS</b>: bukti penerimaan &amp; dokumen pendukung AP.</li>
        <li><b>Sales</b>: penyesuaian DO &amp; tax invoice.</li>
      </ul>
    </div>
  </div>
  <div class="col-12">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:12px"><?=rmi_icon('doc')?> Pintasan</h5>
      <div class="pnd-quick">
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/act/act_dashboard.php')) ?>"><?=rmi_icon('memo')?> ACT Dashboard</a>
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/finance/panduan.php')) ?>"><?=rmi_icon('money')?> Panduan Finance</a>
        <a href="<?= rmi_h(ds_panduan_u('/sales/fin_do_tasks.php')) ?>"><?=rmi_icon('clipboard')?> FIN DO Tasks</a>
        <a href="<?= rmi_h(ds_panduan_u('/purchases/purchases_invoice_ap.php')) ?>"><?=rmi_icon('receipt')?> AP Invoice</a>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
