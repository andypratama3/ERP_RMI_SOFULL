<?php
/**
 * dashboards/finance/panduan.php — Panduan Finance Dashboard (AR · AP · Cash · Payroll).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../_panduan_helpers.php';

require_login();
require_once __DIR__ . '/../../_shared/rmi_layout.php';

rmi_header('Panduan Finance Dashboard', [
    'active' => 'dashboard',
    'subtitle' => 'AR · AP · Cash · Payroll',
    'breadcrumbs' => [
        ['label' => 'Dashboard Center', 'url' => ds_panduan_u('/dashboards/index.php')],
        ['label' => 'Finance Dashboard', 'url' => ds_panduan_u('/dashboards/finance/ar_ap_cash_dashboard.php')],
        'Panduan',
    ],
    'actions' => [
        ['label' => '💰 Finance Dashboard', 'url' => ds_panduan_u('/dashboards/finance/ar_ap_cash_dashboard.php'), 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => '📊 Dashboard Detail', 'url' => ds_panduan_u('/dashboards/finance/dashboard_detail.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);

echo ds_panduan_styles();
?>

<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px">💰</div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Panduan Finance Dashboard</div>
  <div class="pnd-desc" style="color:#94a3b8">
    Dashboard ini merangkum posisi <b>piutang (AR)</b>, <b>hutang (AP)</b>, arus kas, dan payroll per periode.
    Gunakan tombol periode di header kartu untuk bulan berbeda.
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:12px">✅ Alur kerja singkat</h5>
      <div class="pnd-step">
        <div class="pnd-num">1</div>
        <div>
          <div class="pnd-title">Cek KPI atas</div>
          <div class="pnd-desc">Outstanding AR/AP, overdue, dan payroll run terakhir.</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num">2</div>
        <div>
          <div class="pnd-title">Drill ke modul</div>
          <div class="pnd-desc">Dari tombol cepat: <b>FIN Tasks</b>, <b>AP Invoice/Payment</b>, <b>Tax Invoice</b>, <b>Payroll</b>.</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num">3</div>
        <div>
          <div class="pnd-title">Dashboard Detail</div>
          <div class="pnd-desc">Untuk analisis lebih dalam &amp; tampilan eksekutif — sesuai hak akses Manager FIN / SYS.</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="pnd-section accent-amber">
      <h5 style="color:#fcd34d;margin-bottom:12px">⚠️ Scope &amp; data</h5>
      <ul class="pnd-list">
        <li>User cabang mungkin hanya melihat <b>office</b> mereka — cek label di header dashboard.</li>
        <li>Angka mengikuti status dokumen di database; sinkronkan dengan tim ACT untuk rekonsiliasi.</li>
      </ul>
    </div>
  </div>
  <div class="col-12">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:12px">🔗 Pintasan</h5>
      <div class="pnd-quick">
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/finance/ar_ap_cash_dashboard.php')) ?>">💰 Finance Dashboard</a>
        <a href="<?= rmi_h(ds_panduan_u('/dashboards/act/panduan.php')) ?>">📝 Panduan ACT</a>
        <a href="<?= rmi_h(ds_panduan_u('/purchases/panduan.php')) ?>">🛒 Panduan Purchases</a>
        <a href="<?= rmi_h(ds_panduan_u('/kpi/kpi_center.php')) ?>">📊 KPI Center</a>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
