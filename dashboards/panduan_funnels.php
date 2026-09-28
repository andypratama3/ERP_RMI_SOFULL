<?php
/** dashboards/panduan_funnels.php */
declare(strict_types=1);
require_once __DIR__ . '/_dashboard_bootstrap.php';
require_once __DIR__ . '/_panduan_helpers.php';
require_login();
if(function_exists('require_any_permission'))require_any_permission(['DASHBOARD.VIEW','DASHBOARD.SALES_VIEW','DASHBOARD.OWNER_VIEW','DASHBOARD.OWNER_SUMMARY']);
require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('Panduan Funnel Overview',['active'=>'dashboard','subtitle'=>'CRM · Sales DO · Reg Alkes · Import/PO','breadcrumbs'=>[['label'=>'Dashboard Center','url'=>ds_panduan_u('/dashboards/index.php')],['label'=>'Funnel Overview','url'=>ds_panduan_u('/dashboards/funnels.php')],'Panduan'],'actions'=>[['label'=>'🎯 Buka Funnel','url'=>ds_panduan_u('/dashboards/funnels.php'),'class'=>'btn btn-sm btn-outline-light']]]);
echo ds_panduan_styles();
?>
<div class="pnd-hero"><div style="font-size:28px;margin-bottom:8px">🎯</div><div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Panduan Funnel Overview</div><div class="pnd-desc" style="color:#94a3b8">Ringkasan <b>read-only</b> lintas modul untuk melihat posisi proses dan bottleneck. Halaman ini tidak mengubah transaksi sumber.</div></div>
<div class="row g-3">
<div class="col-12 col-lg-6"><div class="pnd-section accent-blue"><h5 style="color:#93c5fd">📊 Cara membaca</h5><ul class="pnd-list">
<li><b>CRM Leads</b> adalah distribusi status lead yang dibuat pada periode filter; persentase bukan conversion cohort historis.</li>
<li><b>Sales DO</b> menunjukkan posisi antrean saat ini: CRM → WQS → SCM → ACT → FIN. Satu DO hanya masuk satu tahap. CANCELLED dan OTHER tetap dihitung agar total tidak hilang.</li>
<li><b>Paid</b> dibaca dari <code>fin_paid_at</code>; bila kolom tidak ada, digunakan status paid/closed.</li>
<li><b>Reg Alkes</b> menampilkan backlog OPEN per tahap sampai tanggal akhir, durasi tahap selesai, dan umur backlog aktif.</li>
<li><b>Import/PO</b> adalah empat hitungan operasional terpisah (PO, PIB, GR, AP), bukan conversion funnel satu cohort.</li>
</ul></div></div>
<div class="col-12 col-lg-6"><div class="pnd-section accent-amber"><h5 style="color:#fcd34d">⚠️ Kontrol data</h5><ul class="pnd-list">
<li>Filter tanggal diterapkan pada setiap sumber bila kolom tanggal tersedia.</li><li>Peringatan integritas tampil bila tabel atau kolom belum dapat dipetakan.</li><li>Status OTHER/unmapped harus ditinjau dan ditambahkan ke mapping, bukan dihapus dari total.</li><li>Dashboard dan API JSON memakai satu service yang sama agar angka tidak berbeda.</li><li>Laporan resmi tetap berada pada modul sumber.</li>
</ul></div></div>
<div class="col-12"><div class="pnd-section accent-green"><h5 style="color:#86efac">🔗 Lanjutkan ke</h5><div class="pnd-quick"><a href="<?=rmi_h(ds_panduan_u('/dashboards/funnels.php'))?>">🎯 Funnel Overview</a><a href="<?=rmi_h(ds_panduan_u('/sales/panduan.php'))?>">💼 Panduan Sales</a><a href="<?=rmi_h(ds_panduan_u('/purchases/panduan.php'))?>">🛒 Panduan Purchases</a><a href="<?=rmi_h(ds_panduan_u('/dashboards/owner/exec_summary.php'))?>">⭐ Executive Summary</a></div></div></div>
</div>
<?php rmi_footer(); ?>
