<?php
if (!function_exists('rmi_icon')) { require_once __DIR__ . '/../_shared/rmi_icons.php'; }
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
require_once __DIR__ . '/_kpi_bootstrap.php';
$pdo = kpi_require_pdo();

require_once __DIR__ . '/../_shared/rbac.php';
if (!function_exists('rbac_require')) {
    http_response_code(500);
    exit('RBAC unavailable');
}
rbac_require($pdo, 'KPI.VIEW');

kpi_header('Panduan KPI Center');
?>

<style>
.pnd-section { background:var(--rmi-card,#1a2235); border:1px solid var(--rmi-border,rgba(255,255,255,.1)); border-radius:14px; padding:22px 26px; margin-bottom:18px }
.pnd-section.accent { border-color:rgba(59,130,246,.3) }
.pnd-step  { display:flex; gap:14px; align-items:flex-start; margin-bottom:18px }
.pnd-step:last-child { margin-bottom:0 }
.pnd-num   { min-width:32px; height:32px; border-radius:50%; color:#fff; font-weight:700; font-size:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px }
.pnd-num.blue   { background:#2563eb }
.pnd-num.green  { background:#16a34a }
.pnd-num.yellow { background:#d97706 }
.pnd-num.purple { background:#7c3aed }
.pnd-num.teal   { background:#0d9488 }
.pnd-title { font-weight:600; margin-bottom:5px; font-size:14px; color:var(--rmi-text,#e8ecf4) }
.pnd-desc  { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7 }
.pnd-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600 }
.pnd-badge.green  { background:rgba(16,185,129,.15); color:#34d399 }
.pnd-badge.yellow { background:rgba(245,158,11,.15); color:#fbbf24 }
.pnd-badge.red    { background:rgba(239,68,68,.15);  color:#f87171 }
.pnd-badge.blue   { background:rgba(59,130,246,.15); color:#60a5fa }
.pnd-faq-q { font-weight:600; font-size:14px; color:var(--rmi-text,#e8ecf4); margin-bottom:5px; margin-top:16px }
.pnd-faq-q:first-child { margin-top:0 }
.pnd-faq-a { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7; margin-bottom:4px }
.pnd-alert { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2); border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-alert strong { color:#fbbf24 }
.pnd-info  { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2); border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-info strong { color:#60a5fa }
.pnd-kpi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:10px; margin:14px 0 }
.pnd-kpi-item { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:10px; padding:12px 14px }
.pnd-kpi-ico  { font-size:20px; margin-bottom:6px }
.pnd-kpi-name { font-weight:600; font-size:13px; color:var(--rmi-text,#e8ecf4); margin-bottom:4px }
.pnd-kpi-desc { font-size:11px; color:var(--rmi-muted,#9ca3af); line-height:1.5 }
h3 { font-size:15px; font-weight:700; margin:0 0 14px 0; display:flex; align-items:center; gap:8px }
</style>

<div style="max-width:780px;margin:0 auto">

  <div style="margin-bottom:22px">
    <h2 style="margin:0 0 4px 0">Panduan KPI Center</h2>
    <div style="color:var(--rmi-muted,#9ca3af);font-size:13px">
      Key Performance Indicators — ERP RMI Enterprise
      &nbsp;·&nbsp;
      <a href="kpi_center.php" style="color:#60a5fa">← Kembali ke KPI Center</a>
    </div>
  </div>

  <!-- Apa itu KPI Center -->
  <div class="pnd-section">
    <h3><?=rmi_icon('chart')?> Apa itu KPI Center?</h3>
    <div class="pnd-desc" style="line-height:1.8;margin-bottom:14px">
      KPI Center adalah pusat dashboard performa seluruh operasional perusahaan — dari penjualan, pengiriman,
      inventory, procurement, hingga kinerja karyawan. Semua KPI dihitung otomatis dari data transaksi real-time di ERP.
    </div>
    <div class="pnd-kpi-grid">
      <div class="pnd-kpi-item">
        <div class="pnd-kpi-ico"><?=rmi_icon('calendar')?></div>
        <div class="pnd-kpi-name">KPI DO SLA</div>
        <div class="pnd-kpi-desc">Rata-rata waktu proses Delivery Order vs target SLA</div>
      </div>
      <div class="pnd-kpi-item">
        <div class="pnd-kpi-ico"><?=rmi_icon('search')?></div>
        <div class="pnd-kpi-name">DO Audit</div>
        <div class="pnd-kpi-desc">Audit trail setiap stage DO — siapa, kapan, berapa lama</div>
      </div>
      <div class="pnd-kpi-item">
        <div class="pnd-kpi-ico"><?=rmi_icon('cart')?></div>
        <div class="pnd-kpi-name">KPI Purchases</div>
        <div class="pnd-kpi-desc">Lead time PO, tepat waktu supplier, AP aging</div>
      </div>
      <div class="pnd-kpi-item">
        <div class="pnd-kpi-ico"><?=rmi_icon('box')?></div>
        <div class="pnd-kpi-name">KPI Stock</div>
        <div class="pnd-kpi-desc">Inventory turnover, stok slow-moving, accuracy opname</div>
      </div>
      <div class="pnd-kpi-item">
        <div class="pnd-kpi-ico"><?=rmi_icon('user')?></div>
        <div class="pnd-kpi-name">KPI Employee</div>
        <div class="pnd-kpi-desc">Kehadiran, productivity, dan target per karyawan</div>
      </div>
      <div class="pnd-kpi-item">
        <div class="pnd-kpi-ico"><?=rmi_icon('office')?></div>
        <div class="pnd-kpi-name">KPI Office</div>
        <div class="pnd-kpi-desc">Performa per cabang — revenue, DO, dan efisiensi</div>
      </div>
      <div class="pnd-kpi-item">
        <div class="pnd-kpi-ico"><?=rmi_icon('calendar')?></div>
        <div class="pnd-kpi-name">Harian</div>
        <div class="pnd-kpi-desc">Ringkasan KPI hari ini — DO, penerimaan, pembayaran</div>
      </div>
      <div class="pnd-kpi-item">
        <div class="pnd-kpi-ico"><?=rmi_icon('trend')?></div>
        <div class="pnd-kpi-name">Bulanan</div>
        <div class="pnd-kpi-desc">Trend dan perbandingan KPI vs bulan sebelumnya</div>
      </div>
    </div>
  </div>

  <!-- Cara Baca KPI Center -->
  <div class="pnd-section">
    <h3><?=rmi_icon('home')?> Cara Menggunakan KPI Center</h3>

    <div class="pnd-step">
      <div class="pnd-num blue">1</div>
      <div>
        <div class="pnd-title">Buka KPI Center</div>
        <div class="pnd-desc">
          Login ERP → klik menu <b>KPI</b> di sidebar, atau akses langsung:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/kpi/kpi_center.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">2</div>
      <div>
        <div class="pnd-title">Pilih Periode & Filter</div>
        <div class="pnd-desc">
          Gunakan filter di bagian atas untuk memilih rentang tanggal, office/cabang, atau departemen.
          KPI akan di-refresh otomatis sesuai filter.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">3</div>
      <div>
        <div class="pnd-title">Baca Status Traffic Light</div>
        <div class="pnd-desc">
          Setiap KPI ditampilkan dengan warna status:
          <span class="pnd-badge green">● Baik</span> — di atas target &nbsp;
          <span class="pnd-badge yellow">● Waspada</span> — mendekati batas &nbsp;
          <span class="pnd-badge red">● Buruk</span> — di bawah target<br>
          <span style="color:var(--rmi-muted,#9ca3af);font-size:12px">Threshold dapat dikonfigurasi oleh Admin di KPI Policy.</span>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">4</div>
      <div>
        <div class="pnd-title">Drill-Down ke Detail</div>
        <div class="pnd-desc">Klik angka atau tile KPI untuk masuk ke halaman detail yang menampilkan data breakdown per item/karyawan/DO.</div>
      </div>
    </div>
  </div>

  <!-- KPI DO SLA -->
  <div class="pnd-section">
    <h3><?=rmi_icon('calendar')?> KPI Delivery Order SLA</h3>

    <div class="pnd-step">
      <div class="pnd-num green">1</div>
      <div>
        <div class="pnd-title">Buka DO SLA</div>
        <div class="pnd-desc">
          Dari KPI Center → klik tab <b>DO SLA</b>, atau:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/kpi/kpi_do_sla.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">2</div>
      <div>
        <div class="pnd-title">Metrik yang Diukur</div>
        <div class="pnd-desc">
          <ul style="margin:6px 0 0;padding-left:18px">
            <li><b>Order to Ship:</b> Waktu dari DO dibuat hingga barang dipicking</li>
            <li><b>Ship to Deliver:</b> Waktu dari picking hingga konfirmasi pengiriman</li>
            <li><b>Total Cycle Time:</b> Waktu total dari DO dibuat hingga selesai</li>
            <li><b>On-Time Rate:</b> Persentase DO yang selesai sesuai target SLA</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">3</div>
      <div>
        <div class="pnd-title">Identifikasi Bottleneck</div>
        <div class="pnd-desc">
          Lihat kolom <b>Stage Terlama</b> untuk tahu di tahap mana DO paling sering terlambat
          (misal: Allocation, Picking, atau Invoicing).
        </div>
      </div>
    </div>
  </div>

  <!-- KPI DO Audit -->
  <div class="pnd-section">
    <h3><?=rmi_icon('search')?> DO Audit — Rekam Jejak Proses DO</h3>

    <div class="pnd-step">
      <div class="pnd-num yellow">1</div>
      <div>
        <div class="pnd-title">Buka DO Audit</div>
        <div class="pnd-desc">
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/kpi/kpi_do_audit.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">2</div>
      <div>
        <div class="pnd-title">Cari DO Tertentu</div>
        <div class="pnd-desc">Gunakan filter nomor DO, customer, atau tanggal untuk menemukan DO yang ingin diaudit.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">3</div>
      <div>
        <div class="pnd-title">Baca Timeline Audit</div>
        <div class="pnd-desc">
          Setiap perubahan stage DO tercatat: siapa yang melakukan, kapan (timestamp), dan durasi di setiap stage.
          Berguna untuk investigasi keterlambatan atau dispute.
        </div>
      </div>
    </div>
  </div>

  <!-- KPI Employee -->
  <div class="pnd-section">
    <h3><?=rmi_icon('user')?> KPI Karyawan</h3>

    <div class="pnd-step">
      <div class="pnd-num purple">1</div>
      <div>
        <div class="pnd-title">Buka KPI Employee</div>
        <div class="pnd-desc">
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/kpi/kpi_employee.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">2</div>
      <div>
        <div class="pnd-title">Metrik per Karyawan</div>
        <div class="pnd-desc">
          <ul style="margin:6px 0 0;padding-left:18px">
            <li><b>Kehadiran:</b> Persentase hadir vs hari kerja bulan ini</li>
            <li><b>Absen & Izin:</b> Jumlah hari absen dan izin yang disetujui</li>
            <li><b>DO Processed:</b> Jumlah DO yang dikerjakan (untuk WQS/SCM)</li>
            <li><b>Productivity Score:</b> Skor gabungan dari beberapa metrik</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">3</div>
      <div>
        <div class="pnd-title">Filter per Dept & Office</div>
        <div class="pnd-desc">Gunakan filter departemen dan office untuk melihat KPI spesifik tim tertentu. Manager hanya melihat dept-nya sendiri.</div>
      </div>
    </div>
  </div>

  <!-- Snapshot & Sync -->
  <div class="pnd-section">
    <h3><?=rmi_icon('doc')?> Snapshot & Sinkronisasi KPI</h3>

    <div class="pnd-step">
      <div class="pnd-num teal">1</div>
      <div>
        <div class="pnd-title">KPI Sync (Hitung Ulang)</div>
        <div class="pnd-desc">
          Buka <b>KPI → KPI Sync</b>:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/kpi/kpi_sync.php</code><br>
          Klik <b>Sync Sekarang</b> untuk memaksa sistem menghitung ulang semua KPI dari data terbaru.
          Berguna jika ada data yang baru diinput dan KPI belum terupdate.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num teal">2</div>
      <div>
        <div class="pnd-title">KPI Snapshot</div>
        <div class="pnd-desc">
          Buka <b>KPI → Snapshot</b> untuk merekam kondisi KPI pada titik waktu tertentu.
          Berguna untuk laporan bulanan/kuartalan — data tidak berubah meski transaksi terus berjalan.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num teal">3</div>
      <div>
        <div class="pnd-title">KPI Harian vs Bulanan</div>
        <div class="pnd-desc">
          <b>Dashboard Harian</b> (<code>kpi_dashboard_daily.php</code>): Fokus operasional hari ini.<br>
          <b>Dashboard Bulanan</b> (<code>kpi_dashboard_monthly.php</code>): Trend dan comparison bulan vs bulan sebelumnya.
        </div>
      </div>
    </div>

    <div class="pnd-info">
      <strong>Kapan perlu Sync?</strong> KPI otomatis diperbarui setiap ada transaksi baru. Manual Sync diperlukan
      jika ada data import massal atau koreksi historis yang baru saja dilakukan.
    </div>
  </div>

  <!-- Hak Akses KPI -->
  <div class="pnd-section">
    <h3><?=rmi_icon('gear')?> Hak Akses KPI</h3>
    <div class="pnd-desc" style="line-height:1.8">
      KPI memiliki visibilitas berbeda per peran (sesuai RBAC, mis. <code>KPI.VIEW</code>):
      <ul style="margin-top:8px;padding-left:20px">
        <li><b>Staff:</b> KPI diri sendiri dan dept-nya saja (kehadiran, DO yang dikerjakan)</li>
        <li><b>Manager:</b> KPI seluruh anggota dept-nya, termasuk DO SLA dan KPI operasional (baca)</li>
        <li><b>SYS:</b> Satu-satunya peran yang mengubah <b>kebijakan</b> KPI (threshold, SLA DO menit, import/sync/snapshot, input master KPI)</li>
      </ul>
      Akses halaman untuk user lain diatur SYS lewat <b>RBAC</b>. Konfigurasi angka/threshold tambahan bisa melalui menu <b>KPI Policy</b> / form SYS di modul KPI.
    </div>
  </div>

  <!-- FAQ -->
  <div class="pnd-section">
    <h3><?=rmi_icon('question')?> Pertanyaan Umum (FAQ)</h3>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> KPI saya tidak sesuai dengan kondisi aktual — kenapa?</div>
    <div class="pnd-faq-a">
      Kemungkinan data sumber belum tersinkronisasi. Coba buka <b>KPI Sync</b> dan klik <b>Sync Sekarang</b>.
      Jika masih tidak sesuai, hubungi Admin/SYS dengan detail perbedaan yang ditemukan.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bagaimana cara mengubah target/threshold KPI?</div>
    <div class="pnd-faq-a">
      Hanya <b>SYS</b> yang mengubah kebijakan (threshold, SLA DO per menit, dll.). Untuk SLA DO: buka <b>KPI DO (SLA)</b> → form <b>Kebijakan SLA DO (SYS)</b>.
      User lain: ajukan ke SYS; akses lihat tetap lewat izin RBAC.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> KPI DO SLA menunjukkan angka tinggi — apa penyebabnya?</div>
    <div class="pnd-faq-a">
      Lihat kolom <b>Stage Terlama</b> di DO Audit. Penyebab umum: stok tidak tersedia saat DO dibuat (delay di Allocation),
      tim WQS tidak melakukan picking tepat waktu, atau invoice terlambat dibuat oleh ACT.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bisa export data KPI ke Excel?</div>
    <div class="pnd-faq-a">
      Ya — di halaman KPI DO, klik <b>Export CSV</b> (<code>export_kpi_do_csv.php</code>) untuk mengunduh data mentah.
      Untuk KPI lainnya, gunakan tombol export yang tersedia di masing-masing halaman.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Apa bedanya KPI Snapshot dengan data KPI live?</div>
    <div class="pnd-faq-a">
      Data KPI <b>live</b> terus berubah seiring transaksi berjalan.
      <b>Snapshot</b> adalah foto kondisi KPI pada satu titik waktu tertentu — data terkunci dan tidak berubah.
      Gunakan Snapshot untuk laporan formal yang perlu konsisten.
    </div>
  </div>

  <!-- Kontak -->
  <div class="pnd-section accent">
    <h3><?=rmi_icon('warn')?> Butuh Bantuan?</h3>
    <div class="pnd-desc" style="line-height:1.9">
      Untuk pertanyaan tentang KPI atau konfigurasi threshold, hubungi <b>Admin Sistem / Manager</b>:
      <ul style="margin-top:10px;padding-left:22px">
        <li>WhatsApp Group Operasional / Management</li>
        <li>Langsung ke Admin Sistem ERP</li>
      </ul>
      Sertakan: <b>nama, KPI yang dimaksud, periode, dan screenshot perbedaan</b>.
    </div>
  </div>

  <div style="text-align:center;margin:8px 0 28px">
    <a href="kpi_center.php" class="btn btn-rmi btn-sm">← Kembali ke KPI Center</a>
  </div>

</div>

<?php kpi_footer(); ?>