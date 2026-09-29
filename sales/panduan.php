<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PANDUAN.SALES_VIEW', 'SALES.VIEW', 'DASHBOARD.SALES_VIEW']);
}
require_once __DIR__ . '/../_shared/rmi_layout.php';

rmi_header('Panduan Sales & DO', 'sales', [
    'breadcrumbs' => [
        ['label' => 'Sales', 'url' => 'sales_dashboard.php'],
        'Panduan',
    ],
]);
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
.pnd-badge.new      { background:rgba(59,130,246,.15); color:#60a5fa }
.pnd-badge.process  { background:rgba(245,158,11,.15); color:#fbbf24 }
.pnd-badge.done     { background:rgba(16,185,129,.15); color:#34d399 }
.pnd-badge.cancel   { background:rgba(239,68,68,.15);  color:#f87171 }
.pnd-faq-q { font-weight:600; font-size:14px; color:var(--rmi-text,#e8ecf4); margin-bottom:5px; margin-top:16px }
.pnd-faq-q:first-child { margin-top:0 }
.pnd-faq-a { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7; margin-bottom:4px }
.pnd-alert { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2); border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-alert strong { color:#fbbf24 }
.pnd-info  { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2); border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-info strong { color:#60a5fa }
.pnd-pipe { display:flex; align-items:center; overflow-x:auto; padding:4px 0 8px; margin:14px 0 }
.pnd-pipe-step { display:flex; flex-direction:column; align-items:center; min-width:80px; text-align:center; flex-shrink:0 }
.pnd-pipe-dot  { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700 }
.pnd-pipe-lbl  { font-size:10px; margin-top:5px; color:var(--rmi-muted,#9ca3af); white-space:nowrap; line-height:1.4 }
.pnd-pipe-line { flex:1; height:2px; background:rgba(255,255,255,.12); min-width:20px }
h3 { font-size:15px; font-weight:700; margin:0 0 14px 0; display:flex; align-items:center; gap:8px }
</style>

<div style="max-width:780px;margin:0 auto">

  <div style="margin-bottom:22px">
    <h2 style="margin:0 0 4px 0">Panduan Sales & Delivery Order</h2>
    <div style="color:var(--rmi-muted,#9ca3af);font-size:13px">
      Manajemen Penjualan, DO, CRM Leads — ERP RMI Enterprise
      &nbsp;·&nbsp;
      <a href="sales_dashboard.php" style="color:#60a5fa">← Kembali ke Sales Dashboard</a>
    </div>
  </div>

  <!-- Pipeline DO -->
  <div class="pnd-section">
    <h3><?= rmi_icon('refresh') ?> Alur Delivery Order (DO) — End to End</h3>
    <div class="pnd-pipe">
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#475569;color:#fff"><?= rmi_icon('memo') ?></div>
        <div class="pnd-pipe-lbl">SO/Order<br><span style="color:#94a3b8">Sales Order</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#2563eb;color:#fff"><?= rmi_icon('box') ?></div>
        <div class="pnd-pipe-lbl">DO Dibuat<br><span style="color:#60a5fa">Sales</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#d97706;color:#fff">🏭</div>
        <div class="pnd-pipe-lbl">Alokasi<br><span style="color:#fbbf24">WQS</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#7c3aed;color:#fff">🚚</div>
        <div class="pnd-pipe-lbl">Picking<br><span style="color:#a78bfa">Gudang</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#0d9488;color:#fff"><?= rmi_icon('receipt') ?></div>
        <div class="pnd-pipe-lbl">Invoice<br><span style="color:#2dd4bf">ACT/FIN</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#16a34a;color:#fff"><?= rmi_icon('tick') ?></div>
        <div class="pnd-pipe-lbl">DONE<br><span style="color:#34d399">Selesai</span></div>
      </div>
    </div>
    <div style="font-size:12px;color:var(--rmi-muted,#9ca3af);line-height:1.8">
      DO melibatkan beberapa dept: <b>Sales</b> (buat DO), <b>WQS</b> (alokasi + picking),
      <b>SCM</b> (koordinasi delivery), <b>ACT/FIN</b> (invoice + pembayaran).
      Setiap dept hanya melihat task-nya masing-masing.
    </div>
  </div>

  <!-- Cara Buat DO -->
  <div class="pnd-section">
    <h3><?= rmi_icon('box') ?> Cara Membuat Delivery Order (DO)</h3>

    <div class="pnd-step">
      <div class="pnd-num blue">1</div>
      <div>
        <div class="pnd-title">Buka Halaman DO</div>
        <div class="pnd-desc">
          Login ERP → <b>Sales → Delivery Order</b>, atau akses langsung:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/sales/sales_do.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">2</div>
      <div>
        <div class="pnd-title">Klik "+ Buat DO Baru"</div>
        <div class="pnd-desc">Pilih customer dari daftar, isi tanggal pengiriman yang diinginkan, dan office asal pengiriman.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">3</div>
      <div>
        <div class="pnd-title">Tambahkan Item Produk</div>
        <div class="pnd-desc">
          Cari produk dari katalog → isi jumlah → isi harga satuan (atau gunakan harga kontrak customer).
          Bisa tambahkan lebih dari 1 item.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">4</div>
      <div>
        <div class="pnd-title">Isi Detail Pengiriman & Catatan</div>
        <div class="pnd-desc">
          Isi alamat pengiriman, nomor PO customer (jika ada), dan catatan khusus untuk tim gudang atau driver.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">5</div>
      <div>
        <div class="pnd-title">Submit DO</div>
        <div class="pnd-desc">
          Klik <b>Submit DO</b>. DO akan otomatis masuk ke antrian WQS untuk alokasi stok.
          Status awal: <span class="pnd-badge new">● NEW</span>.
        </div>
      </div>
    </div>

    <div class="pnd-info">
      <strong>Cetak Surat Jalan:</strong> Setelah DO diproses, buka DO detail → klik <b>Cetak Surat Jalan</b>
      untuk mendapatkan dokumen fisik yang dibawa saat pengiriman.
    </div>
  </div>

  <!-- Control Tower -->
  <div class="pnd-section">
    <h3><?= rmi_icon('tower') ?> Sales Control Tower — Monitoring DO</h3>

    <div class="pnd-step">
      <div class="pnd-num green">1</div>
      <div>
        <div class="pnd-title">Buka Control Tower</div>
        <div class="pnd-desc">
          <b>Sales → Control Tower</b>, atau:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/sales/sales_control_tower.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">2</div>
      <div>
        <div class="pnd-title">Filter & Cari DO</div>
        <div class="pnd-desc">
          Filter berdasarkan: status, office, tanggal, atau nama customer.
          Gunakan kolom pencarian untuk menemukan DO tertentu dengan cepat.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">3</div>
      <div>
        <div class="pnd-title">Pantau Status Real-Time</div>
        <div class="pnd-desc">
          Control Tower menampilkan semua DO beserta stage saat ini:
          NEW → Allocated → Picked → Delivered → Invoiced → DONE.
          Klik baris DO untuk melihat detail lengkap.
        </div>
      </div>
    </div>

    <div class="pnd-info">
      <strong>KPI SLA:</strong> Lihat tab <b>KPI DO SLA</b> untuk memantau rata-rata waktu proses DO
      vs target SLA. Berguna untuk identifikasi bottleneck di alur pengiriman.
    </div>
  </div>

  <!-- CRM Leads -->
  <div class="pnd-section">
    <h3><?= rmi_icon('target') ?> CRM Leads — Manajemen Prospek Customer</h3>

    <div class="pnd-step">
      <div class="pnd-num purple">1</div>
      <div>
        <div class="pnd-title">Buka CRM Leads</div>
        <div class="pnd-desc">
          <b>Sales → CRM Leads</b>, atau:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/sales/crm_leads.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">2</div>
      <div>
        <div class="pnd-title">Tambah Lead Baru</div>
        <div class="pnd-desc">
          Klik <b>+ Tambah Lead</b>. Isi: nama perusahaan/kontak, nomor telepon/email, sumber lead (cold call, referral, dll.),
          dan estimasi nilai potensi bisnis.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">3</div>
      <div>
        <div class="pnd-title">Update Stage Lead</div>
        <div class="pnd-desc">
          Perbarui stage lead sesuai perkembangan: <b>NEW → CONTACTED → PROPOSAL → NEGOTIATION → WON / LOST</b>.
          Tambahkan catatan di setiap update.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">4</div>
      <div>
        <div class="pnd-title">Konversi Lead → Customer</div>
        <div class="pnd-desc">
          Jika deal berhasil (WON), konversi lead menjadi Customer aktif di Master Customer.
          Customer aktif bisa langsung digunakan saat buat DO.
        </div>
      </div>
    </div>
  </div>

  <!-- Sales Order -->
  <div class="pnd-section">
    <h3><?= rmi_icon('clipboard') ?> Sales Order — Manajemen Pesanan</h3>

    <div class="pnd-step">
      <div class="pnd-num teal">1</div>
      <div>
        <div class="pnd-title">Buat Sales Order</div>
        <div class="pnd-desc">
          <b>Sales → Sales Order</b>. SO adalah dokumen konfirmasi pesanan sebelum DO dibuat.
          Bisa dibuat berdasarkan PO dari customer.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num teal">2</div>
      <div>
        <div class="pnd-title">Approve SO</div>
        <div class="pnd-desc">Manager Sales mengapprove SO sebelum tim operasional memproses ke DO.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num teal">3</div>
      <div>
        <div class="pnd-title">Buat DO dari SO</div>
        <div class="pnd-desc">Dari SO yang sudah approved, klik <b>Buat DO</b>. Item SO akan otomatis terbawa ke form DO.</div>
      </div>
    </div>
  </div>

  <!-- Tax Invoice -->
  <div class="pnd-section">
    <h3><?= rmi_icon('receipt') ?> Faktur Pajak (Tax Invoice)</h3>

    <div class="pnd-step">
      <div class="pnd-num yellow">1</div>
      <div>
        <div class="pnd-title">Buka Tax Invoices</div>
        <div class="pnd-desc">
          <b>Sales → Tax Invoices</b>, atau:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/sales/tax_invoices.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">2</div>
      <div>
        <div class="pnd-title">Generate Faktur Pajak</div>
        <div class="pnd-desc">Pilih DO yang sudah delivered → klik <b>Generate Faktur Pajak</b>. Sistem akan membuat draft faktur pajak sesuai data DO.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">3</div>
      <div>
        <div class="pnd-title">Kirim ke Customer</div>
        <div class="pnd-desc">Setelah direview, tandai faktur sebagai <b>ISSUED</b> dan kirimkan ke customer (print atau digital).</div>
      </div>
    </div>
  </div>

  <!-- Rekap & Laporan -->
  <div class="pnd-section">
    <h3><?= rmi_icon('chart') ?> Rekap & Laporan Sales</h3>
    <div class="pnd-desc" style="line-height:1.8">
      Menu <b>Rekap DO</b> (<code>sales_do_rekap.php</code>) menyediakan:
      <ul style="margin-top:8px;padding-left:20px">
        <li>Rekapitulasi DO per periode, per customer, per office</li>
        <li>Total nilai penjualan dan DO yang sudah terselesaikan</li>
        <li>Export ke Excel untuk analisis lebih lanjut</li>
        <li>Filter berdasarkan status, tanggal, dan office</li>
      </ul>
    </div>
  </div>

  <!-- FAQ -->
  <div class="pnd-section">
    <h3><?= rmi_icon('question') ?> Pertanyaan Umum (FAQ)</h3>

    <div class="pnd-faq-q"><?= rmi_icon('question') ?> DO sudah dibuat tapi tidak muncul di WQS untuk di-pick?</div>
    <div class="pnd-faq-a">
      Pastikan DO sudah melewati tahap <b>Alokasi</b> di WQS Allocation.
      Jika belum, tim WQS perlu mengalokasikan stok terlebih dahulu sebelum DO bisa di-pick.
    </div>

    <div class="pnd-faq-q"><?= rmi_icon('question') ?> Customer baru belum ada di sistem — bisa langsung buat DO?</div>
    <div class="pnd-faq-a">
      Tidak. Customer baru harus didaftarkan terlebih dahulu di <b>Master Customer</b> oleh tim yang berwenang.
      Setelah terdaftar dan aktif, bisa langsung dipakai di DO.
    </div>

    <div class="pnd-faq-q"><?= rmi_icon('question') ?> Bagaimana tracking pengiriman untuk customer?</div>
    <div class="pnd-faq-a">
      Gunakan halaman <b>Public Tracking</b> (<code>tracking_public.php</code>) untuk link tracking yang bisa diberikan ke customer.
      Customer bisa memantau status DO tanpa login ke ERP.
    </div>

    <div class="pnd-faq-q"><?= rmi_icon('question') ?> DO salah dibuat — bisa dibatalkan?</div>
    <div class="pnd-faq-a">
      DO yang masih berstatus NEW bisa dibatalkan oleh Sales Manager atau Admin.
      DO yang sudah diproses (allocated/picked) perlu koordinasi dengan WQS dan Manager sebelum dibatalkan.
    </div>

    <div class="pnd-faq-q"><?= rmi_icon('question') ?> Apa beda Sales Order (SO) dengan Delivery Order (DO)?</div>
    <div class="pnd-faq-a">
      <b>SO</b> adalah konfirmasi pesanan dari customer (boleh ada, boleh tidak).
      <b>DO</b> adalah dokumen operasional yang menggerakkan stok dan pengiriman fisik. DO bisa dibuat langsung tanpa SO.
    </div>
  </div>

  <!-- Kontak -->
  <div class="pnd-section accent">
    <h3>🆘 Butuh Bantuan?</h3>
    <div class="pnd-desc" style="line-height:1.9">
      Untuk kendala modul Sales, hubungi <b>Manager Sales</b> atau <b>Admin Sistem</b>:
      <ul style="margin-top:10px;padding-left:22px">
        <li>WhatsApp Group Sales/Operasional</li>
        <li>Langsung ke divisi Sales</li>
      </ul>
      Sertakan: <b>nomor DO, nama customer, tanggal, dan screenshot error</b>.
    </div>
  </div>

  <div style="text-align:center;margin:8px 0 28px">
    <a href="sales_dashboard.php" class="btn btn-rmi btn-sm">← Kembali ke Sales Dashboard</a>
  </div>

</div>

<?php rmi_footer(); ?>
