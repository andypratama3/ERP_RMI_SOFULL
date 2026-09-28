<?php
/**
 * docs/user_guide/index.php
 * Pusat Dokumentasi User — ERP RMI SOFULL
 */
require_once __DIR__ . '/../../master/auth.php';
require_login();

if (!function_exists('rmi_h')) {
    function rmi_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$bp = defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '';
$mod = trim((string)($_GET['mod'] ?? 'home'));
$q   = trim((string)($_GET['q']   ?? ''));

$modules = [
    'home'      => ['icon'=>'🏠', 'label'=>'Beranda',          'color'=>'#06b6d4'],
    'absensi'   => ['icon'=>'📅', 'label'=>'Absensi',          'color'=>'#22c55e'],
    'sales'     => ['icon'=>'💼', 'label'=>'Sales & CRM',       'color'=>'#3b82f6'],
    'purchasing'=> ['icon'=>'🛒', 'label'=>'Purchasing & AP',   'color'=>'#f59e0b'],
    'stock'     => ['icon'=>'📦', 'label'=>'Stock & WQS',       'color'=>'#8b5cf6'],
    'finance'   => ['icon'=>'💰', 'label'=>'Finance & GL',      'color'=>'#10b981'],
    'hrl'       => ['icon'=>'👥', 'label'=>'HRL & Reg Alkes',   'color'=>'#ef4444'],
    'payroll'   => ['icon'=>'💳', 'label'=>'Payroll',           'color'=>'#f97316'],
    'kpi'       => ['icon'=>'📊', 'label'=>'KPI & Dashboard',   'color'=>'#06b6d4'],
    'master'    => ['icon'=>'⚙️',  'label'=>'Master Data',       'color'=>'#94a3b8'],
    'manufactures'=> ['icon'=>'🏭','label'=>'Master Manufactures','color'=>'#0ea5e9'],
    'vendors'   => ['icon'=>'🤝', 'label'=>'Master Vendor',       'color'=>'#d946ef'],
    'itc_reset' => ['icon'=>'🔑', 'label'=>'ITC Reset Password',  'color'=>'#f43f5e'],
    'hrl_process'=> ['icon'=>'📋', 'label'=>'HRL Process',        'color'=>'#ec4899'],
    'mpr'       => ['icon'=>'📣', 'label'=>'MPR (Marketing)',     'color'=>'#14b8a6'],
    'asset'     => ['icon'=>'🏢', 'label'=>'Fixed Asset',         'color'=>'#a78bfa'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panduan User — ERP RMI</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0f172a;color:#e2e8f0;font-family:'Segoe UI',system-ui,sans-serif;min-height:100vh;display:flex}
.sidebar{width:220px;min-height:100vh;background:#0b1120;border-right:1px solid rgba(6,182,212,.2);padding:20px 0;flex-shrink:0;position:sticky;top:0;height:100vh;overflow-y:auto}
.sidebar-title{padding:0 16px 16px;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid rgba(255,255,255,.06);margin-bottom:8px}
.sidebar a{display:flex;align-items:center;gap:10px;padding:9px 16px;font-size:13px;color:#94a3b8;text-decoration:none;transition:all .2s;border-left:3px solid transparent}
.sidebar a:hover{color:#e2e8f0;background:rgba(255,255,255,.04)}
.sidebar a.active{color:#06b6d4;background:rgba(6,182,212,.08);border-left-color:#06b6d4;font-weight:600}
.main{flex:1;padding:32px;overflow-y:auto;max-width:900px}
.page-title{font-size:24px;font-weight:800;margin-bottom:4px;color:#f1f5f9}
.page-sub{font-size:13px;color:#64748b;margin-bottom:28px}
.search-bar{display:flex;gap:10px;margin-bottom:24px}
.search-bar input{flex:1;background:rgba(30,41,59,.8);border:1px solid rgba(6,182,212,.3);color:#e2e8f0;padding:10px 14px;border-radius:10px;font-size:14px;outline:none}
.search-bar input:focus{border-color:#06b6d4}
.mod-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:32px}
.mod-card{background:rgba(15,23,42,.8);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;cursor:pointer;text-decoration:none;color:inherit;transition:all .2s;display:block}
.mod-card:hover{border-color:rgba(6,182,212,.4);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3)}
.mod-icon{font-size:28px;margin-bottom:8px}
.mod-label{font-size:14px;font-weight:700;color:#f1f5f9;margin-bottom:4px}
.mod-desc{font-size:12px;color:#64748b}
.section{margin-bottom:32px}
.section h2{font-size:16px;font-weight:700;color:#06b6d4;margin-bottom:16px;padding-bottom:8px;border-bottom:1px solid rgba(6,182,212,.2);display:flex;align-items:center;gap:8px}
.section h3{font-size:14px;font-weight:700;color:#e2e8f0;margin:16px 0 8px}
.section p,.section li{font-size:13px;color:#94a3b8;line-height:1.7}
.section ul,.section ol{padding-left:20px}
.section li{margin-bottom:4px}
.step{display:flex;gap:12px;margin-bottom:14px;align-items:flex-start}
.step-num{min-width:28px;height:28px;border-radius:50%;background:#06b6d4;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.step-body{}
.step-title{font-size:13px;font-weight:700;color:#e2e8f0;margin-bottom:2px}
.step-desc{font-size:12px;color:#94a3b8;line-height:1.6}
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700}
.badge-blue{background:rgba(59,130,246,.2);color:#60a5fa;border:1px solid rgba(59,130,246,.3)}
.badge-green{background:rgba(34,197,94,.2);color:#4ade80;border:1px solid rgba(34,197,94,.3)}
.badge-yellow{background:rgba(251,191,36,.2);color:#fbbf24;border:1px solid rgba(251,191,36,.3)}
.badge-red{background:rgba(239,68,68,.2);color:#f87171;border:1px solid rgba(239,68,68,.3)}
.tip{background:rgba(6,182,212,.08);border:1px solid rgba(6,182,212,.2);border-radius:10px;padding:12px 14px;font-size:12px;color:#a5f3fc;margin:12px 0}
.warn{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:10px;padding:12px 14px;font-size:12px;color:#fde68a;margin:12px 0}
.role-table{width:100%;border-collapse:collapse;font-size:12px;margin:12px 0}
.role-table th{background:rgba(6,182,212,.1);color:#67e8f9;padding:8px 12px;text-align:left;border:1px solid rgba(6,182,212,.15)}
.role-table td{padding:8px 12px;border:1px solid rgba(255,255,255,.06);color:#94a3b8}
.role-table tr:hover td{background:rgba(255,255,255,.03)}
.back-link{display:inline-flex;align-items:center;gap:6px;color:#06b6d4;font-size:13px;text-decoration:none;margin-bottom:20px}
.back-link:hover{color:#67e8f9}
.toc{background:rgba(15,23,42,.6);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:14px 16px;margin-bottom:24px}
.toc-title{font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:8px}
.toc a{display:block;font-size:12px;color:#64748b;text-decoration:none;padding:2px 0;transition:color .2s}
.toc a:hover{color:#06b6d4}
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
  <div class="sidebar-title">📖 Panduan User ERP RMI</div>
  <?php foreach ($modules as $key => $m): ?>
    <a href="?mod=<?= $key ?>" class="<?= $mod===$key?'active':'' ?>">
      <span><?= $m['icon'] ?></span>
      <span><?= rmi_h($m['label']) ?></span>
    </a>
  <?php endforeach; ?>
  <div style="border-top:1px solid rgba(255,255,255,.06);margin:12px 0"></div>
  <a href="<?= rmi_h($bp) ?>/" style="font-size:12px;color:#475569;padding:8px 16px;display:block;text-decoration:none">← Kembali ke ERP</a>
</div>

<!-- Main Content -->
<div class="main">

<?php if ($mod === 'home'): ?>
  <div class="page-title">📖 Pusat Dokumentasi ERP RMI</div>
  <div class="page-sub">Panduan lengkap penggunaan sistem ERP RMI SOFULL per modul</div>

  <div class="search-bar">
    <input type="text" id="searchInput" placeholder="Cari modul atau fitur..." onkeyup="filterMods(this.value)">
  </div>

  <div class="mod-grid" id="modGrid">
    <?php foreach (array_slice($modules,1) as $key => $m): ?>
    <a href="?mod=<?= $key ?>" class="mod-card" data-label="<?= rmi_h($m['label']) ?>">
      <div class="mod-icon"><?= $m['icon'] ?></div>
      <div class="mod-label"><?= rmi_h($m['label']) ?></div>
      <div class="mod-desc">Klik untuk lihat panduan</div>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="section">
    <h2>🚀 Cara Mulai Menggunakan ERP</h2>
    <div class="step">
      <div class="step-num">1</div>
      <div class="step-body">
        <div class="step-title">Login ke ERP</div>
        <div class="step-desc">Buka browser → masukkan URL ERP → login dengan username & password yang diberikan admin.</div>
      </div>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <div class="step-body">
        <div class="step-title">Pilih Modul</div>
        <div class="step-desc">Klik menu di sidebar sesuai tugas kamu — Sales, Purchasing, Stock, dll.</div>
      </div>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <div class="step-body">
        <div class="step-title">Baca Panduan Modul</div>
        <div class="step-desc">Gunakan dokumentasi ini sebagai referensi. Pilih modul di sidebar kiri.</div>
      </div>
    </div>
    <div class="tip">💡 Jika ada kendala, hubungi Tim IT atau lihat panduan modul terkait di halaman ini.</div>
  </div>

  <div class="section">
    <h2>👤 Role & Akses</h2>
    <table class="role-table">
      <thead><tr><th>Role</th><th>Akses</th><th>Contoh</th></tr></thead>
      <tbody>
        <tr><td><span class="badge badge-red">sys</span></td><td>Akses penuh semua modul</td><td>Administrator sistem</td></tr>
        <tr><td><span class="badge badge-blue">manager</span></td><td>Akses modul departemennya + approval</td><td>Manager FIN, Manager SCM</td></tr>
        <tr><td><span class="badge badge-green">staff</span></td><td>Akses operasional modul departemennya</td><td>Staff Sales, Staff WQS</td></tr>
      </tbody>
    </table>
  </div>

<?php elseif ($mod === 'absensi'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">📅 Panduan Modul Absensi</div>
  <div class="page-sub">Absensi by Photo — Check-in/out dengan foto & GPS</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#checkin">1. Cara Check-in</a>
    <a href="#checkout">2. Cara Check-out</a>
    <a href="#izin">3. Pengajuan Izin/Sakit/Dinas</a>
    <a href="#riwayat">4. Melihat Riwayat</a>
    <a href="#admin">5. Admin HR</a>
    <a href="#faq-abs">6. FAQ</a>
  </div>

  <div class="section" id="checkin">
    <h2>📅 1. Cara Check-in</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka Modul Absensi</div><div class="step-desc">Menu → Absensi, atau langsung ke <code>/absensi/</code></div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Klik tombol "Check-in"</div><div class="step-desc">Tersedia di Dashboard Absensi bagian Aksi Cepat. Hanya aktif jika belum check-in hari ini.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Izinkan Kamera & GPS</div><div class="step-desc">Browser meminta izin — klik <strong>Izinkan/Allow</strong> untuk keduanya.</div></div></div>
    <div class="step"><div class="step-num">4</div><div class="step-body"><div class="step-title">Ambil Foto Selfie & Kirim</div><div class="step-desc">Pastikan wajah jelas → klik Ambil Foto → Kirim Check-in. Muncul notifikasi "Check-in berhasil".</div></div></div>
    <div class="warn">⚠️ Jika GPS menolak karena lokasi terlalu jauh, pastikan kamu berada di area kantor dan GPS HP aktif.</div>
  </div>

  <div class="section" id="checkout">
    <h2>🏠 2. Cara Check-out</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Klik tombol "Check-out"</div><div class="step-desc">Aktif setelah check-in. Lakukan saat akan pulang.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Ambil Foto & Kirim</div><div class="step-desc">Sama seperti check-in — foto selfie → Kirim Check-out.</div></div></div>
    <div class="warn">⚠️ Jangan lupa check-out setiap hari — data ini digunakan untuk laporan jam kerja dan payroll.</div>
  </div>

  <div class="section" id="izin">
    <h2>📝 3. Izin / Sakit / Dinas Luar</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Klik menu "Izin / Dinas"</div><div class="step-desc">Dashboard Absensi → Aksi Cepat → Izin / Dinas</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Pilih Jenis & Isi Form</div><div class="step-desc">Pilih: <span class="badge badge-blue">Izin</span> / <span class="badge badge-red">Sakit</span> / <span class="badge badge-yellow">Dinas Luar</span> → isi tanggal & alasan → lampirkan foto dokter jika sakit.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Submit → Tunggu Approval</div><div class="step-desc">Status awal: PENDING. Tim HRL akan mereview dan menyetujui.</div></div></div>
  </div>

  <div class="section" id="riwayat">
    <h2>📋 4. Riwayat Absensi</h2>
    <p>Buka menu <strong>Riwayat</strong> untuk melihat daftar check-in/out kamu, foto selfie, lokasi, dan jarak dari kantor.</p>
  </div>

  <div class="section" id="admin">
    <h2>⚙️ 5. Admin HR (Khusus HR Admin)</h2>
    <table class="role-table">
      <thead><tr><th>Menu</th><th>Fungsi</th></tr></thead>
      <tbody>
        <tr><td>Rekap HR</td><td>Lihat rekap absensi semua karyawan + foto</td></tr>
        <tr><td>User Settings</td><td>Set office & HR admin per user</td></tr>
        <tr><td>Approval</td><td>Setujui/tolak pengajuan izin karyawan</td></tr>
        <tr><td>Office Settings</td><td>Kelola titik koordinat kantor (GeoFence)</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="faq-abs">
    <h2>❓ 6. FAQ Absensi</h2>
    <h3>Kamera tidak mau aktif?</h3>
    <p>Pengaturan Browser → Izin → Kamera → Izinkan untuk domain ERP.</p>
    <h3>Muncul "Lokasi terlalu jauh"?</h3>
    <p>Aktifkan GPS HP, pastikan berada di area kantor. Hubungi HRL jika masih bermasalah.</p>
    <h3>Lupa check-out?</h3>
    <p>Hubungi Tim HRL untuk koreksi data manual.</p>
  </div>

<?php elseif ($mod === 'sales'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">💼 Panduan Modul Sales & CRM</div>
  <div class="page-sub">Pengelolaan DO (Delivery Order), CRM Leads, dan monitoring penjualan</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#do-buat">1. Membuat DO (Delivery Order)</a>
    <a href="#do-list">2. Daftar & Status DO</a>
    <a href="#crm">3. CRM Leads</a>
    <a href="#rekap-sales">4. Rekap Penjualan</a>
    <a href="#control-tower">5. Sales Control Tower</a>
  </div>

  <div class="section" id="do-buat">
    <h2>💼 1. Membuat DO (Delivery Order)</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka modul Sales</div><div class="step-desc">Menu → Sales → Delivery Order</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Klik "Buat DO Baru"</div><div class="step-desc">Isi: Customer, Office, Tanggal DO, Produk & Jumlah, Harga.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Submit DO</div><div class="step-desc">DO tersimpan dengan status <span class="badge badge-yellow">OPEN</span>. Bisa dicetak sebagai dokumen.</div></div></div>
    <div class="step"><div class="step-num">4</div><div class="step-body"><div class="step-title">Update Status Pembayaran</div><div class="step-desc">Setelah customer bayar → update status ke <span class="badge badge-green">PAID</span> atau input partial payment.</div></div></div>
  </div>

  <div class="section" id="do-list">
    <h2>📋 2. Status DO</h2>
    <table class="role-table">
      <thead><tr><th>Status</th><th>Arti</th></tr></thead>
      <tbody>
        <tr><td><span class="badge badge-yellow">OPEN</span></td><td>DO dibuat, belum lunas</td></tr>
        <tr><td><span class="badge badge-blue">SUBMITTED</span></td><td>Diajukan, menunggu approval</td></tr>
        <tr><td><span class="badge badge-yellow">WAIT_PAYMENT</span></td><td>Menunggu pembayaran</td></tr>
        <tr><td><span class="badge badge-green">PAID</span></td><td>Sudah lunas</td></tr>
        <tr><td><span class="badge badge-red">CANCELLED</span></td><td>Dibatalkan</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="crm">
    <h2>🤝 3. CRM Leads</h2>
    <p>Kelola prospek customer dari awal sampai closing:</p>
    <ul>
      <li>Tambah lead baru → isi nama, kontak, produk yang diminati</li>
      <li>Update stage: <span class="badge badge-blue">PROSPEK</span> → <span class="badge badge-yellow">FOLLOW UP</span> → <span class="badge badge-green">CLOSING</span></li>
      <li>Catat aktivitas & notes per lead</li>
    </ul>
  </div>

  <div class="section" id="rekap-sales">
    <h2>📊 4. Rekap Penjualan</h2>
    <p>Menu Sales → Rekap DO: filter per periode, office, customer. Export ke CSV untuk laporan.</p>
  </div>

  <div class="section" id="control-tower">
    <h2>🗼 5. Sales Control Tower</h2>
    <p>Monitoring real-time semua DO aktif, status pembayaran, dan backlog per office. Khusus Manager & SYS.</p>
    <div class="tip">💡 Control Tower adalah satu-satunya tempat untuk melihat semua transaksi sales lintas office dalam satu layar.</div>
  </div>

<?php elseif ($mod === 'purchasing'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">🛒 Panduan Modul Purchasing & AP</div>
  <div class="page-sub">RFQ, Purchase Order, Good Receipt, Accounts Payable</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#rfq">1. RFQ (Request for Quotation)</a>
    <a href="#po">2. Purchase Order (PO)</a>
    <a href="#gr">3. Good Receipt (GR)</a>
    <a href="#ap">4. Accounts Payable (AP)</a>
    <a href="#forwarding">5. Forwarding & Impor</a>
  </div>

  <div class="section" id="rfq">
    <h2>📄 1. RFQ (Request for Quotation)</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buat RFQ Baru</div><div class="step-desc">Menu → Purchasing → RFQ → Buat baru → isi supplier, produk, kuantitas.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Kirim ke Supplier</div><div class="step-desc">Download PDF RFQ → kirim ke supplier via email/WhatsApp.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Input Penawaran Supplier</div><div class="step-desc">Setelah supplier reply → update harga & terms di RFQ → jadikan PO.</div></div></div>
  </div>

  <div class="section" id="po">
    <h2>🛒 2. Purchase Order (PO)</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buat PO</div><div class="step-desc">Dari RFQ yang disetujui → convert ke PO, atau buat PO langsung.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Approval PO</div><div class="step-desc">PO perlu disetujui Manager/SYS sebelum dikirim ke supplier.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Kirim PO ke Supplier</div><div class="step-desc">Download dokumen PO → kirim ke supplier. Tunggu pengiriman barang.</div></div></div>
  </div>

  <div class="section" id="gr">
    <h2>📦 3. Good Receipt (GR)</h2>
    <p>Saat barang datang dari supplier:</p>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka PO yang bersangkutan</div><div class="step-desc">Menu → Purchasing → PO → cari PO → klik "Proses GR".</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Input Kuantitas Diterima</div><div class="step-desc">Isi jumlah barang yang benar-benar diterima (bisa partial).</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Konfirmasi GR</div><div class="step-desc">Stok otomatis bertambah di WQS setelah GR dikonfirmasi.</div></div></div>
  </div>

  <div class="section" id="ap">
    <h2>💳 4. Accounts Payable (AP)</h2>
    <table class="role-table">
      <thead><tr><th>Langkah</th><th>Keterangan</th></tr></thead>
      <tbody>
        <tr><td>Input Invoice AP</td><td>Setelah GR → input invoice dari supplier ke AP</td></tr>
        <tr><td>Approval Invoice</td><td>Manager/FIN approve invoice sebelum dibayar</td></tr>
        <tr><td>Pembayaran</td><td>Input tanggal & jumlah bayar → status jadi PAID/PARTIAL</td></tr>
        <tr><td>Rekap AP</td><td>Lihat outstanding AP per supplier/office</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="forwarding">
    <h2>🚢 5. Forwarding & Impor</h2>
    <p>Untuk produk impor:</p>
    <ul>
      <li>Input <strong>forwarder invoice</strong> — biaya jasa pengiriman internasional</li>
      <li>Input <strong>PIB (CEISA)</strong> — dokumen bea cukai</li>
      <li>Tracking status import di <strong>Import Control Tower</strong></li>
    </ul>
    <div class="tip">💡 Import Control Tower membantu memantau seluruh proses impor dari PO sampai barang masuk gudang.</div>
  </div>

<?php elseif ($mod === 'stock'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">📦 Panduan Modul Stock & WQS</div>
  <div class="page-sub">Warehouse & Quality System — pengelolaan stok, transfer, opname</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#incoming">1. Incoming (Barang Masuk)</a>
    <a href="#picking">2. Picking (Pengambilan)</a>
    <a href="#transfer">3. Transfer Stok Antar Cabang</a>
    <a href="#opname">4. Stock Opname</a>
    <a href="#adjustment">5. Adjustment Stok</a>
    <a href="#pr">6. Purchase Request (PR)</a>
  </div>

  <div class="section" id="incoming">
    <h2>📥 1. Incoming (Barang Masuk)</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka menu Incoming</div><div class="step-desc">Menu → Stock → Incoming. Tampil daftar GR yang menunggu diproses masuk gudang.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Verifikasi Barang</div><div class="step-desc">Cocokkan fisik barang dengan dokumen GR — jumlah, kondisi, batch/expired.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Konfirmasi Incoming</div><div class="step-desc">Klik Konfirmasi → stok otomatis masuk ke sistem.</div></div></div>
  </div>

  <div class="section" id="picking">
    <h2>📤 2. Picking (Pengambilan)</h2>
    <p>Saat ada DO yang perlu dikirim ke customer:</p>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Lihat daftar picking</div><div class="step-desc">Menu → Stock → Picking. Tampil DO yang perlu disiapkan barangnya.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Ambil barang dari gudang</div><div class="step-desc">Ikuti instruksi picking list — produk, lokasi, jumlah.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Konfirmasi picking</div><div class="step-desc">Tandai sebagai selesai → stok berkurang otomatis.</div></div></div>
  </div>

  <div class="section" id="transfer">
    <h2>🔄 3. Transfer Stok Antar Cabang</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buat Transfer Request</div><div class="step-desc">Menu → Stock → Transfer → pilih dari office & ke office → isi produk & jumlah.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Approval</div><div class="step-desc">Manager approve transfer sebelum stok bergerak.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Konfirmasi Penerimaan</div><div class="step-desc">Cabang tujuan konfirmasi penerimaan → stok update di kedua cabang.</div></div></div>
  </div>

  <div class="section" id="opname">
    <h2>🔢 4. Stock Opname</h2>
    <p>Penghitungan stok fisik secara berkala:</p>
    <ul>
      <li>Menu → Stock → Opname → Buat sesi opname baru</li>
      <li>Hitung fisik stok per produk → input ke sistem</li>
      <li>Sistem membandingkan dengan stok buku → tampilkan selisih</li>
      <li>Approve hasil opname → stok buku diupdate</li>
    </ul>
    <div class="warn">⚠️ Lakukan opname secara rutin (minimal bulanan) untuk memastikan akurasi stok.</div>
  </div>

  <div class="section" id="adjustment">
    <h2>✏️ 5. Adjustment Stok</h2>
    <p>Untuk koreksi stok karena rusak, hilang, atau selisih kecil:</p>
    <ul>
      <li>Menu → Stock → Adjustment → pilih produk → isi jumlah koreksi & alasan</li>
      <li>Perlu approval Manager untuk adjustment yang signifikan</li>
    </ul>
  </div>

  <div class="section" id="pr">
    <h2>📋 6. Purchase Request (PR)</h2>
    <p>Permintaan pembelian dari gudang ke tim Purchasing:</p>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buat PR</div><div class="step-desc">Menu → Stock → PR → Buat baru → isi produk, jumlah, urgensi.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Approval PR</div><div class="step-desc">Manager approve → PR diteruskan ke tim Purchasing untuk diproses jadi PO.</div></div></div>
  </div>

<?php elseif ($mod === 'finance'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">💰 Panduan Modul Finance & GL</div>
  <div class="page-sub">General Ledger, Bank Rekonsiliasi, AR/AP Management</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#gl">1. General Ledger (GL)</a>
    <a href="#ar">2. Accounts Receivable (AR / Piutang)</a>
    <a href="#bank">3. Bank Rekonsiliasi</a>
    <a href="#dashboard-fin">4. Dashboard Finance</a>
    <a href="#export-fin">5. Export Laporan</a>
  </div>

  <div class="section" id="gl">
    <h2>📒 1. General Ledger (GL)</h2>
    <p>GL mencatat semua transaksi keuangan per akun dan per office:</p>
    <ul>
      <li><strong>Input jurnal</strong> — debit/kredit per akun GL</li>
      <li><strong>Kategori biaya</strong> — Operasional, Beban Gaji, Support, Fee Management, PPh</li>
      <li><strong>Auto-posting</strong> — beberapa transaksi (AP payment) otomatis posting ke GL</li>
      <li><strong>Reversal</strong> — jurnal bisa di-reverse jika ada kesalahan (perlu approval)</li>
    </ul>
    <div class="tip">💡 GL adalah sumber data untuk laporan keuangan di Dashboard Finance Detail.</div>
  </div>

  <div class="section" id="ar">
    <h2>💵 2. Accounts Receivable (Piutang)</h2>
    <table class="role-table">
      <thead><tr><th>Jenis</th><th>Keterangan</th></tr></thead>
      <tbody>
        <tr><td>Piutang Baru</td><td>DO bulan berjalan yang belum dibayar</td></tr>
        <tr><td>Piutang Lama</td><td>DO bulan sebelumnya yang belum lunas</td></tr>
        <tr><td>Input Pembayaran</td><td>Update fin_paid_amount di DO saat customer bayar</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="bank">
    <h2>🏦 3. Bank Rekonsiliasi</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Import Mutasi Bank</div><div class="step-desc">Menu → Finance → Bank Rekon → Import file mutasi dari bank (CSV).</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Cocokkan dengan Transaksi ERP</div><div class="step-desc">Sistem otomatis mencocokkan mutasi bank dengan pembayaran AP/AR di ERP.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Tandai Selisih</div><div class="step-desc">Transaksi yang tidak cocok perlu di-review dan dicatat alasannya.</div></div></div>
  </div>

  <div class="section" id="dashboard-fin">
    <h2>📊 4. Dashboard Finance</h2>
    <p>Menu → Dashboard → Finance Detail. Tersedia 4 tab:</p>
    <table class="role-table">
      <thead><tr><th>Tab</th><th>Isi</th></tr></thead>
      <tbody>
        <tr><td>Target vs Pencapaian</td><td>Perbandingan target & realisasi per segment/office</td></tr>
        <tr><td>Finance Detail</td><td>P&L per office: penjualan, biaya, piutang, hutang, stok</td></tr>
        <tr><td>Chart View</td><td>Grafik visual + auto-refresh untuk monitoring</td></tr>
        <tr><td>⭐ Executive Summary</td><td>Ringkasan eksekutif — KPI utama + chart</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="export-fin">
    <h2>📥 5. Export Laporan</h2>
    <p>Di Dashboard Finance → tombol <strong>⬇ Target</strong> dan <strong>⬇ Finance</strong> untuk export CSV yang bisa dibuka di Excel.</p>
    <div class="tip">💡 Gunakan filter Bulan, Tahun, dan As-Of Date untuk memilih periode laporan sebelum export.</div>
  </div>

<?php elseif ($mod === 'hrl'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">👥 Panduan Modul HRL & Reg Alkes</div>
  <div class="page-sub">Human Resource & Legal — Dokumen, Proses, Registrasi Alat Kesehatan</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#hrl-docs">1. Manajemen Dokumen HRL</a>
    <a href="#hrl-process">2. HRL Process</a>
    <a href="#reg-alkes">3. Registrasi Alat Kesehatan</a>
    <a href="#hrl-dashboard">4. HRL Dashboard</a>
  </div>

  <div class="section" id="hrl-docs">
    <h2>📁 1. Manajemen Dokumen HRL</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Upload Dokumen</div><div class="step-desc">Menu → HRL → Dokumen → Upload → pilih kategori dokumen (SOP, Perjanjian, Sertifikat, dll).</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Review & Approval</div><div class="step-desc">Dokumen yang disubmit perlu review dan tanda tangan digital dari pejabat berwenang.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Arsip & Akses</div><div class="step-desc">Dokumen yang approved tersimpan di arsip digital — bisa diakses sesuai hak akses.</div></div></div>
    <div class="tip">💡 HRL Tower adalah pusat monitoring semua dokumen — lihat mana yang akan expired, pending approval, dll.</div>
  </div>

  <div class="section" id="hrl-process">
    <h2>⚙️ 2. HRL Process</h2>
    <p>Kelola proses bisnis berbasis dokumen:</p>
    <ul>
      <li>Buat proses baru dengan template yang sudah ada</li>
      <li>Assign ke PIC yang bertanggung jawab</li>
      <li>Track progress tiap tahapan</li>
      <li>Upload bukti penyelesaian tiap step</li>
    </ul>
  </div>

  <div class="section" id="reg-alkes">
    <h2>🏥 3. Registrasi Alat Kesehatan (Reg Alkes)</h2>
    <p>Kelola proses registrasi produk ke Kemenkes/BPOM:</p>
    <table class="role-table">
      <thead><tr><th>Langkah</th><th>Keterangan</th></tr></thead>
      <tbody>
        <tr><td>Buat Case Baru</td><td>Input produk, jenis izin, dokumen persyaratan</td></tr>
        <tr><td>Tracking Status</td><td>Update status pengajuan ke Kemenkes</td></tr>
        <tr><td>Notifikasi Expired</td><td>Sistem reminder sebelum izin edar habis masa berlaku</td></tr>
        <tr><td>Perpanjangan</td><td>Buat case perpanjangan sebelum izin habis</td></tr>
      </tbody>
    </table>
    <div class="warn">⚠️ Pastikan izin edar produk selalu diperbarui sebelum kadaluarsa untuk menghindari masalah regulasi.</div>
  </div>

  <div class="section" id="hrl-dashboard">
    <h2>📊 4. HRL Dashboard</h2>
    <p>Menu → Dashboard → HRL. Menampilkan:</p>
    <ul>
      <li>KPI: dokumen total, karyawan aktif, pending approval, reg alkes open</li>
      <li>Rekap absensi hari ini (check-in/out semua karyawan)</li>
      <li>Quick links ke semua sub-modul HRL</li>
    </ul>
  </div>

<?php elseif ($mod === 'payroll'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">💳 Panduan Modul Payroll</div>
  <div class="page-sub">Penggajian karyawan, salary matrix, dan payslip</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#salary-matrix">1. Salary Matrix</a>
    <a href="#payroll-run">2. Proses Penggajian</a>
    <a href="#payslip">3. Payslip</a>
    <a href="#loans">4. Pinjaman Karyawan</a>
  </div>

  <div class="section" id="salary-matrix">
    <h2>📋 1. Salary Matrix</h2>
    <p>Konfigurasi struktur gaji per level dan golongan:</p>
    <table class="role-table">
      <thead><tr><th>Level</th><th>Golongan</th><th>Komponen</th></tr></thead>
      <tbody>
        <tr><td>Manager</td><td>A / B / C</td><td>Gaji pokok, tunjangan, BPJS</td></tr>
        <tr><td>Staff</td><td>A / B / C</td><td>Gaji pokok, tunjangan, BPJS</td></tr>
        <tr><td>SYS</td><td>—</td><td>Sesuai kontrak</td></tr>
      </tbody>
    </table>
    <div class="tip">💡 Salary matrix diisi oleh HRL/Finance — jadi dasar perhitungan gaji otomatis saat payroll run.</div>
  </div>

  <div class="section" id="payroll-run">
    <h2>▶️ 2. Proses Penggajian (Payroll Run)</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Verifikasi Data Absensi</div><div class="step-desc">Pastikan data absensi bulan berjalan sudah lengkap dan benar sebelum payroll run.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Buka menu Payroll Run</div><div class="step-desc">Menu → Payroll → Payroll Run → pilih periode (bulan/tahun) → pilih office.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Preview & Verifikasi</div><div class="step-desc">Cek total gaji per karyawan, pastikan tidak ada yang anomali sebelum dikonfirmasi.</div></div></div>
    <div class="step"><div class="step-num">4</div><div class="step-body"><div class="step-title">Konfirmasi Payroll</div><div class="step-desc">Klik Konfirmasi → data gaji terkunci, payslip bisa dicetak/dikirim.</div></div></div>
    <div class="warn">⚠️ Payroll yang sudah dikonfirmasi tidak bisa diubah. Pastikan semua data benar sebelum konfirmasi.</div>
  </div>

  <div class="section" id="payslip">
    <h2>🧾 3. Payslip</h2>
    <ul>
      <li>Menu → Payroll → Payslip → pilih karyawan & periode</li>
      <li>Download PDF payslip untuk diberikan ke karyawan</li>
      <li>Karyawan bisa lihat payslip sendiri via portal (jika aktif)</li>
    </ul>
  </div>

  <div class="section" id="loans">
    <h2>💰 4. Pinjaman Karyawan</h2>
    <ul>
      <li>Input pinjaman baru per karyawan — jumlah & cicilan per bulan</li>
      <li>Cicilan otomatis dipotong dari gaji saat payroll run</li>
      <li>Tracking sisa pinjaman per karyawan</li>
    </ul>
  </div>

<?php elseif ($mod === 'kpi'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">📊 Panduan Modul KPI & Dashboard</div>
  <div class="page-sub">Target penjualan, monitoring pencapaian, dan laporan kinerja</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#kpi-target">1. Input Target KPI</a>
    <a href="#kpi-dashboard">2. Dashboard Finance Detail</a>
    <a href="#exec-summary">3. Executive Summary</a>
    <a href="#monitoring">4. Mode Monitoring</a>
  </div>

  <div class="section" id="kpi-target">
    <h2>🎯 1. Input Target KPI</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka KPI Center</div><div class="step-desc">Menu → KPI → KPI Center</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Input Target Bulanan</div><div class="step-desc">Pilih bulan & tahun → input target per segment (NON HERMINA, HERMINA, ACCUNIT) per office.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Simpan Target</div><div class="step-desc">Target tersimpan → otomatis tampil di Dashboard Finance sebagai pembanding pencapaian.</div></div></div>
    <div class="tip">💡 Target harus diinput di awal bulan agar perbandingan vs pencapaian akurat sepanjang bulan.</div>
  </div>

  <div class="section" id="kpi-dashboard">
    <h2>📈 2. Dashboard Finance Detail</h2>
    <p>Buka: <strong>Dashboard → Finance Detail</strong>. Filter: Bulan, Tahun, As-Of Date.</p>
    <table class="role-table">
      <thead><tr><th>Tab</th><th>Fungsi</th></tr></thead>
      <tbody>
        <tr><td>Target vs Pencapaian</td><td>Tabel detail per office/segment vs target</td></tr>
        <tr><td>Finance Detail</td><td>P&L lengkap: biaya, piutang, hutang, stok per office</td></tr>
        <tr><td>Chart View</td><td>Grafik bar & donut, bisa auto-refresh 30s/60s</td></tr>
        <tr><td>⭐ Executive Summary</td><td>6 KPI card + semua chart dalam satu layar</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="exec-summary">
    <h2>⭐ 3. Executive Summary</h2>
    <p>Tab khusus untuk tampilan ringkas yang cocok untuk presentasi dan monitoring:</p>
    <ul>
      <li>Total Penjualan MTD, Target, % Pencapaian</li>
      <li>Total Piutang & Hutang AP</li>
      <li>Karyawan hadir hari ini</li>
      <li>Chart per office & segment</li>
      <li>Top-6 cabang by penjualan</li>
    </ul>
  </div>

  <div class="section" id="monitoring">
    <h2>📺 4. Mode Monitoring</h2>
    <p>Klik tombol <strong>📺 Monitor</strong> di Dashboard untuk membuka mode monitoring:</p>
    <ul>
      <li>Layar penuh — header/menu tersembunyi</li>
      <li>Live clock di pojok kanan</li>
      <li>Summary bar total KPI selalu terlihat</li>
      <li>Auto-refresh 60 detik — data selalu terkini</li>
      <li>Cocok untuk share screen saat meeting</li>
    </ul>
  </div>

<?php elseif ($mod === 'master'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">⚙️ Panduan Master Data</div>
  <div class="page-sub">Data referensi yang digunakan oleh seluruh modul ERP</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#master-user">1. Master System Login (User)</a>
    <a href="#master-employee">2. Master Karyawan</a>
    <a href="#master-customer">3. Master Customer</a>
    <a href="#master-product">4. Master Produk</a>
    <a href="#master-office">5. Master Office/Cabang</a>
    <a href="#master-dept">6. Master Departemen</a>
  </div>

  <div class="section" id="master-user">
    <h2>👤 1. Master System Login (User)</h2>
    <p>Kelola akun login ERP. Menu → Master Data → System Login.</p>
    <table class="role-table">
      <thead><tr><th>Aksi</th><th>Keterangan</th></tr></thead>
      <tbody>
        <tr><td>Tambah User</td><td>Buat akun baru — username, password, role, dept, office</td></tr>
        <tr><td>Edit User</td><td>Ubah role, department, office, status</td></tr>
        <tr><td>Set Holder Employee</td><td>Hubungkan akun login ke data karyawan di master_employees</td></tr>
        <tr><td>Reset Password</td><td>Reset password user lain (ITC/Admin)</td></tr>
        <tr><td>Bulk Inactive</td><td>Nonaktifkan banyak user sekaligus (SYS/Admin terlindungi)</td></tr>
      </tbody>
    </table>
    <div class="warn">⚠️ Akun dengan role SYS/ADMIN/SUPERADMIN tidak bisa dinonaktifkan via bulk update — terlindungi otomatis.</div>
  </div>

  <div class="section" id="master-employee">
    <h2>👥 2. Master Karyawan</h2>
    <p>Data lengkap karyawan. Menu → Master Data → Karyawan.</p>
    <ul>
      <li>Data pribadi: nama, NIK, NPWP, pendidikan, kontak</li>
      <li>Data kerja: departemen, office, level, golongan, tanggal bergabung</li>
      <li>Data payroll: bank, no rekening, BPJS TK & Kesehatan</li>
      <li>Import massal via CSV</li>
    </ul>
    <div class="tip">💡 Employee Code di-generate otomatis: DEPT + YY + MM + nomor urut. Contoh: FIN2601.</div>
  </div>

  <div class="section" id="master-customer">
    <h2>🏢 3. Master Customer</h2>
    <ul>
      <li>Data customer: nama, kode, alamat, kontak, segment</li>
      <li>Segment: NON HERMINA / HERMINA / ACCUNIT — menentukan target KPI</li>
      <li>Import massal via CSV</li>
    </ul>
  </div>

  <div class="section" id="master-product">
    <h2>📦 4. Master Produk</h2>
    <ul>
      <li>Kode produk, nama, satuan, harga jual, harga beli</li>
      <li>Kategori & sub-kategori produk</li>
      <li>Dipakai oleh modul Sales (DO), Purchasing (PO), dan Stock (WQS)</li>
    </ul>
  </div>

  <div class="section" id="master-office">
    <h2>🏢 5. Master Office/Cabang</h2>
    <table class="role-table">
      <thead><tr><th>Kode</th><th>Cabang</th></tr></thead>
      <tbody>
        <tr><td>BGR</td><td>Bogor (Depo Utama)</td></tr>
        <tr><td>BDG</td><td>Bandung</td></tr>
        <tr><td>BKS</td><td>Bekasi</td></tr>
        <tr><td>TGR</td><td>Tangerang</td></tr>
        <tr><td>SMG</td><td>Semarang</td></tr>
        <tr><td>SLO</td><td>Solo</td></tr>
        <tr><td>JGY</td><td>Yogyakarta</td></tr>
        <tr><td>KAL</td><td>Kalimantan</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="master-dept">
    <h2>🏛️ 6. Master Departemen</h2>
    <p>Daftar departemen yang digunakan untuk RBAC dan reporting:</p>
    <ul>
      <li>FIN, ACT, SCM, WQS, PQP, CRM, HRL, ITC, MPR, SYS, dll</li>
      <li>Setiap user harus punya department agar akses modul benar</li>
    </ul>
  </div>

<?php elseif ($mod === 'manufactures'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">🏭 Panduan Master Manufactures</div>
  <div class="page-sub">Data produsen / pabrik alat kesehatan yang menjadi mitra RMI</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#mfr-overview">1. Overview</a>
    <a href="#mfr-tambah">2. Tambah Manufacturer</a>
    <a href="#mfr-data">3. Data yang Dikelola</a>
    <a href="#mfr-docs">4. Dokumen Manufacturer</a>
    <a href="#mfr-portal">5. Portal User Manufacturer</a>
    <a href="#mfr-import">6. Import CSV</a>
  </div>

  <div class="section" id="mfr-overview">
    <h2>🏭 1. Overview</h2>
    <p>Master Manufactures menyimpan data lengkap semua produsen/pabrik alat kesehatan yang produknya didistribusikan oleh RMI. Data ini digunakan sebagai referensi di modul Purchasing, Reg Alkes, dan dokumen impor.</p>
    <div class="tip">💡 Manufacturer berbeda dengan Vendor — Manufacturer adalah produsen/pabrik, Vendor adalah supplier/pemasok yang bisa jadi distributor lokal.</div>
  </div>

  <div class="section" id="mfr-tambah">
    <h2>➕ 2. Tambah Manufacturer</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka Master Manufactures</div><div class="step-desc">Menu → Master Data → Manufactures</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Klik "Tambah Baru"</div><div class="step-desc">Isi form data manufacturer — semua field wajib diisi sebelum status bisa diset Active.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Simpan</div><div class="step-desc">Manufacturer tersimpan. Bisa langsung upload dokumen terkait.</div></div></div>
  </div>

  <div class="section" id="mfr-data">
    <h2>📋 3. Data yang Dikelola</h2>
    <table class="role-table">
      <thead><tr><th>Kategori</th><th>Field</th></tr></thead>
      <tbody>
        <tr><td>Identitas</td><td>Kode, nama, negara asal (origin/country), website</td></tr>
        <tr><td>PIC Pabrik</td><td>Nama kontak, email, telepon PIC di pabrik</td></tr>
        <tr><td>Bank & Swift</td><td>Bank, nomor rekening, SWIFT code (wajib jika Active)</td></tr>
        <tr><td>Status</td><td>ACTIVE / INACTIVE / PENDING</td></tr>
      </tbody>
    </table>
    <div class="warn">⚠️ Data Bank & SWIFT wajib diisi sebelum status dapat diubah ke ACTIVE — digunakan untuk pembayaran impor.</div>
  </div>

  <div class="section" id="mfr-docs">
    <h2>📁 4. Dokumen Manufacturer</h2>
    <p>Setiap manufacturer bisa memiliki dokumen terkait (sertifikat, agreement, katalog):</p>
    <ul>
      <li>Dari tabel manufacturer → klik tombol <strong>Docs</strong></li>
      <li>Upload dokumen → pilih kategori → simpan</li>
      <li>Dokumen bisa di-download kapan saja oleh tim yang berwenang</li>
    </ul>
  </div>

  <div class="section" id="mfr-portal">
    <h2>🌐 5. Portal User Manufacturer</h2>
    <p>Manufacturer tertentu bisa diberikan akses portal untuk melihat data terkait mereka:</p>
    <ul>
      <li>Menu → Master Data → Manufacturer Portal Users</li>
      <li>Buat akun portal per manufacturer</li>
      <li>Akses dibatasi hanya ke data manufacturer tersebut</li>
    </ul>
  </div>

  <div class="section" id="mfr-import">
    <h2>📥 6. Import CSV</h2>
    <p>Untuk input massal data manufacturer:</p>
    <ul>
      <li>Download template CSV dari halaman Manufactures</li>
      <li>Isi data sesuai format kolom template</li>
      <li>Upload CSV → sistem validasi → data masuk</li>
      <li>Duplicate kode → update data existing (tidak duplikat)</li>
    </ul>
    <div class="tip">💡 Re-import aman — sistem mendeteksi kode yang sudah ada dan mengupdate, bukan membuat duplikat.</div>
  </div>

<?php elseif ($mod === 'vendors'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">🤝 Panduan Master Vendor</div>
  <div class="page-sub">Data supplier, vendor, logistik, dan penyedia jasa mitra RMI</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#vnd-overview">1. Overview</a>
    <a href="#vnd-tambah">2. Tambah Vendor</a>
    <a href="#vnd-data">3. Data yang Dikelola</a>
    <a href="#vnd-import">4. Import CSV</a>
    <a href="#vnd-purchasing">5. Hubungan dengan Purchasing</a>
  </div>

  <div class="section" id="vnd-overview">
    <h2>🤝 1. Overview</h2>
    <p>Master Vendor menyimpan data semua vendor/supplier yang bertransaksi dengan RMI. Digunakan sebagai referensi saat membuat PO, AP Invoice, dan pembayaran.</p>
    <table class="role-table">
      <thead><tr><th>Tipe Vendor</th><th>Contoh</th></tr></thead>
      <tbody>
        <tr><td>Supplier produk</td><td>Distributor alat kesehatan lokal</td></tr>
        <tr><td>Forwarder / Logistik</td><td>Perusahaan ekspedisi impor</td></tr>
        <tr><td>Penyedia jasa</td><td>Vendor IT, office supplies, dll</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="vnd-tambah">
    <h2>➕ 2. Tambah Vendor</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka Master Vendors</div><div class="step-desc">Menu → Master Data → Vendors</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Klik "Tambah Vendor"</div><div class="step-desc">Isi: nama, kode, tipe vendor, NPWP, alamat, kontak PIC.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Isi Data Bank</div><div class="step-desc">Bank, nomor rekening, nama pemilik rekening — digunakan saat pembayaran AP.</div></div></div>
    <div class="step"><div class="step-num">4</div><div class="step-body"><div class="step-title">Simpan</div><div class="step-desc">Vendor aktif dan bisa dipilih saat membuat PO atau AP Invoice.</div></div></div>
  </div>

  <div class="section" id="vnd-data">
    <h2>📋 3. Data yang Dikelola</h2>
    <table class="role-table">
      <thead><tr><th>Kategori</th><th>Field</th></tr></thead>
      <tbody>
        <tr><td>Identitas</td><td>Kode vendor, nama, tipe, NPWP, alamat</td></tr>
        <tr><td>PIC</td><td>Nama kontak, email, telepon</td></tr>
        <tr><td>Bank Lokal</td><td>Bank, cabang, nomor rekening, nama rekening</td></tr>
        <tr><td>Bank Luar Negeri</td><td>Bank internasional, SWIFT code, IBAN (untuk vendor impor)</td></tr>
        <tr><td>Status</td><td>ACTIVE / INACTIVE</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="vnd-import">
    <h2>📥 4. Import CSV</h2>
    <ul>
      <li>Download template → isi data → upload</li>
      <li>Sistem update data existing jika kode sudah ada</li>
      <li>Export data vendor ke CSV/Excel via tombol Export di tabel</li>
    </ul>
  </div>

  <div class="section" id="vnd-purchasing">
    <h2>🔗 5. Hubungan dengan Purchasing</h2>
    <p>Data vendor digunakan di modul Purchasing saat:</p>
    <ul>
      <li><strong>RFQ</strong> — pilih vendor penerima quotation</li>
      <li><strong>PO</strong> — pilih vendor supplier di Purchase Order</li>
      <li><strong>AP Invoice</strong> — input tagihan dari vendor</li>
      <li><strong>Pembayaran AP</strong> — data bank vendor otomatis terisi dari master</li>
    </ul>
    <div class="tip">💡 Pastikan data bank vendor selalu up-to-date untuk menghindari kesalahan transfer pembayaran.</div>
  </div>

<?php elseif ($mod === 'itc_reset'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">🔑 Panduan ITC Reset Password</div>
  <div class="page-sub">Tool khusus ITC untuk reset password user tanpa akses ke Master System Login penuh</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#itp-overview">1. Overview</a>
    <a href="#itp-reset">2. Cara Reset Password</a>
    <a href="#itp-proteksi">3. Proteksi & Batasan</a>
    <a href="#itp-audit">4. Audit Trail</a>
    <a href="#itp-referensi">5. Referensi User → Employee</a>
  </div>

  <div class="section" id="itp-overview">
    <h2>🔑 1. Overview</h2>
    <p>Halaman <strong>ITC Reset Password</strong> adalah tool khusus untuk tim ITC agar bisa mereset password user tanpa perlu akses penuh ke Master System Login. Dirancang untuk keamanan — ITC hanya bisa reset password, tidak bisa ubah role/status/data lain.</p>
    <table class="role-table">
      <thead><tr><th>Yang Bisa</th><th>Yang Tidak Bisa</th></tr></thead>
      <tbody>
        <tr><td>Reset password user biasa</td><td>Reset password ADMIN/SUPERADMIN/SYS</td></tr>
        <tr><td>Filter & cari user</td><td>Edit role, department, status user</td></tr>
        <tr><td>Lihat referensi user→employee</td><td>Hapus atau nonaktifkan user</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="itp-reset">
    <h2>🔄 2. Cara Reset Password</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka ITC Reset Password</div><div class="step-desc">Menu → Master Data → ITC Reset Password (khusus dept ITC / ADMIN / SYS)</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Cari User</div><div class="step-desc">Gunakan filter (Dept, Office, Status) atau kolom Cari untuk temukan user yang minta reset.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Klik "Reset" pada baris user</div><div class="step-desc">Panel kiri menampilkan form reset — isi password baru untuk user tersebut.</div></div></div>
    <div class="step"><div class="step-num">4</div><div class="step-body"><div class="step-title">Klik "Reset Password"</div><div class="step-desc">Password berhasil diubah. Beritahu user password barunya secara aman (jangan via chat grup).</div></div></div>
    <div class="tip">💡 Password sebaiknya dikomunikasikan ke user secara langsung atau via pesan pribadi — jangan di grup/broadcast.</div>
  </div>

  <div class="section" id="itp-proteksi">
    <h2>🛡️ 3. Proteksi & Batasan</h2>
    <p>Sistem secara otomatis melindungi akun-akun berikut:</p>
    <table class="role-table">
      <thead><tr><th>Akun</th><th>Status</th></tr></thead>
      <tbody>
        <tr><td>Username: admin / superadmin</td><td><span class="badge badge-red">🔒 PROTECTED</span> — tidak bisa direset ITC</td></tr>
        <tr><td>Role: SYS / ADMIN / SUPERADMIN</td><td><span class="badge badge-red">🔒 PROTECTED</span> — tidak bisa direset ITC</td></tr>
        <tr><td>Manager FIN</td><td><span class="badge badge-yellow">⚠️ PROTECTED</span> — tidak bisa direset ITC</td></tr>
        <tr><td>User biasa (staff/manager dept lain)</td><td><span class="badge badge-green">✓ Bisa direset ITC</span></td></tr>
      </tbody>
    </table>
    <div class="warn">⚠️ Akun yang PROTECTED akan tampil dengan tombol "Locked" (abu-abu) — tidak bisa diklik.</div>
  </div>

  <div class="section" id="itp-audit">
    <h2>📋 4. Audit Trail</h2>
    <p>Setiap reset password dicatat otomatis di log:</p>
    <ul>
      <li>Siapa yang mereset (ITC user)</li>
      <li>User mana yang direset</li>
      <li>Waktu reset</li>
    </ul>
    <p>Log tersimpan di: <code>/uploads/audit_logs/audit_itc_password_reset.log</code></p>
    <div class="tip">💡 Audit log ini bisa diperiksa oleh Admin jika ada reset yang mencurigakan.</div>
  </div>

  <div class="section" id="itp-referensi">
    <h2>👥 5. Referensi User → Employee</h2>
    <p>Di bagian bawah halaman tersedia tabel referensi yang menampilkan:</p>
    <ul>
      <li>User ID, Username</li>
      <li>Employee Code (holder) yang terhubung</li>
      <li>Nama karyawan</li>
      <li>Status: Terhubung ✓ / Belum ✗</li>
    </ul>
    <p>Berguna untuk ITC mengidentifikasi user mana yang milik karyawan mana, tanpa perlu buka Master System Login penuh.</p>
  </div>

<?php elseif ($mod === 'hrl_process'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">📋 Panduan HRL Process</div>
  <div class="page-sub">Pengelolaan proses bisnis berbasis dokumen & PIN approval</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#hrlp-overview">1. Overview HRL Process</a>
    <a href="#hrlp-request">2. Membuat Request Proses</a>
    <a href="#hrlp-approval">3. Approval & PIN</a>
    <a href="#hrlp-tower">4. HRL Process Tower</a>
    <a href="#hrlp-roles">5. Role & Akses</a>
  </div>

  <div class="section" id="hrlp-overview">
    <h2>📋 1. Overview HRL Process</h2>
    <p>HRL Process adalah modul untuk mengelola proses bisnis yang memerlukan alur persetujuan (approval workflow) berbasis dokumen. Berbeda dengan HRL Docs yang fokus pada penyimpanan dokumen, HRL Process fokus pada <strong>proses yang sedang berjalan</strong>.</p>
    <table class="role-table">
      <thead><tr><th>Komponen</th><th>Fungsi</th></tr></thead>
      <tbody>
        <tr><td>Request</td><td>Pengajuan proses baru oleh karyawan/staff</td></tr>
        <tr><td>Review</td><td>Pemeriksaan oleh supervisor/HRL</td></tr>
        <tr><td>Approval PIN</td><td>Persetujuan menggunakan PIN digital</td></tr>
        <tr><td>Tower</td><td>Monitoring semua proses aktif</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="hrlp-request">
    <h2>📝 2. Membuat Request Proses</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka HRL Process</div><div class="step-desc">Menu → HRL Process → klik "Buat Request Baru".</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Pilih Jenis Proses</div><div class="step-desc">Pilih template proses yang tersedia sesuai kebutuhan (contoh: mutasi, kenaikan jabatan, pelatihan, dll).</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Isi Detail & Lampiran</div><div class="step-desc">Lengkapi form — isi data, alasan, dan upload dokumen pendukung jika diperlukan.</div></div></div>
    <div class="step"><div class="step-num">4</div><div class="step-body"><div class="step-title">Submit</div><div class="step-desc">Request masuk ke antrian approval. Status: <span class="badge badge-yellow">PENDING</span>.</div></div></div>
    <div class="tip">💡 Kamu bisa print request untuk arsip fisik via tombol Print di halaman detail request.</div>
  </div>

  <div class="section" id="hrlp-approval">
    <h2>✅ 3. Approval & PIN</h2>
    <p>HRL Process menggunakan sistem <strong>PIN digital</strong> untuk approval — lebih aman dari sekadar klik tombol.</p>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka notifikasi approval</div><div class="step-desc">Approver mendapat notifikasi request masuk → buka HRL Process Tower atau link langsung.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Review detail request</div><div class="step-desc">Baca dokumen, lampiran, dan data pengaju sebelum memutuskan.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Input PIN & Approve/Reject</div><div class="step-desc">Input PIN personal → pilih Setujui atau Tolak dengan catatan alasan jika ditolak.</div></div></div>
    <div class="warn">⚠️ PIN approval adalah tanda tangan digital yang sah. Jangan bagikan PIN ke orang lain.</div>
    <h3>Setup PIN (Pertama Kali)</h3>
    <p>Menu → HRL Process → My PIN → set PIN 6 digit. Simpan PIN dengan aman.</p>
  </div>

  <div class="section" id="hrlp-tower">
    <h2>🗼 4. HRL Process Tower</h2>
    <p>Dashboard monitoring semua proses aktif — khusus HRL Admin & Manager.</p>
    <ul>
      <li>Lihat semua request yang <span class="badge badge-yellow">PENDING</span> approval</li>
      <li>Filter per jenis proses, department, status</li>
      <li>Track berapa lama request belum diproses</li>
      <li>Kirim reminder ke approver yang belum merespons</li>
    </ul>
  </div>

  <div class="section" id="hrlp-roles">
    <h2>👤 5. Role & Akses HRL Process</h2>
    <table class="role-table">
      <thead><tr><th>Role</th><th>Yang Bisa Dilakukan</th></tr></thead>
      <tbody>
        <tr><td><span class="badge badge-green">Staff (semua dept)</span></td><td>Buat request, lihat status request sendiri</td></tr>
        <tr><td><span class="badge badge-blue">Manager</span></td><td>Approve/reject request timnya, lihat request dept sendiri</td></tr>
        <tr><td><span class="badge badge-red">HRL Admin</span></td><td>Kelola semua request, akses Tower, setup template proses</td></tr>
        <tr><td><span class="badge badge-red">SYS</span></td><td>Akses penuh</td></tr>
      </tbody>
    </table>
  </div>

<?php elseif ($mod === 'mpr'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">📣 Panduan Modul MPR (Marketing)</div>
  <div class="page-sub">Marketing Planning & Reporting — Rencana, Budget, dan Realisasi Marketing</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#mpr-overview">1. Overview MPR</a>
    <a href="#mpr-plans">2. Rencana Marketing (Plans)</a>
    <a href="#mpr-budget">3. Budget & Approval FIN</a>
    <a href="#mpr-realisasi">4. Realisasi & Pembayaran</a>
    <a href="#mpr-dashboard">5. MPR Dashboard</a>
    <a href="#mpr-roles">6. Role & Akses</a>
  </div>

  <div class="section" id="mpr-overview">
    <h2>📣 1. Overview MPR</h2>
    <p>MPR (Marketing Planning & Reporting) mengelola seluruh siklus kegiatan marketing:</p>
    <ul>
      <li><strong>Perencanaan</strong> — buat rencana kegiatan + estimasi budget</li>
      <li><strong>Approval</strong> — FIN Manager approve budget sebelum kegiatan jalan</li>
      <li><strong>Realisasi</strong> — catat pengeluaran aktual kegiatan</li>
      <li><strong>Pelaporan</strong> — laporan realisasi vs budget per periode</li>
    </ul>
    <div class="tip">💡 MPR terhubung langsung dengan Finance — setiap pengeluaran marketing tercatat di GL otomatis.</div>
  </div>

  <div class="section" id="mpr-plans">
    <h2>📅 2. Rencana Marketing (Plans)</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buat Rencana Baru</div><div class="step-desc">Menu → MPR → Rencana → Buat Baru → isi nama kegiatan, tanggal, target, estimasi budget.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Detail Kegiatan</div><div class="step-desc">Isi deskripsi, target audience, area/region, dan rincian biaya per item.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Submit untuk Approval</div><div class="step-desc">Submit → masuk ke FIN untuk approval budget. Status: <span class="badge badge-yellow">PENDING</span>.</div></div></div>
    <table class="role-table" style="margin-top:12px">
      <thead><tr><th>Status Plan</th><th>Arti</th></tr></thead>
      <tbody>
        <tr><td><span class="badge badge-yellow">DRAFT</span></td><td>Masih dibuat, belum disubmit</td></tr>
        <tr><td><span class="badge badge-blue">SUBMITTED</span></td><td>Menunggu approval FIN</td></tr>
        <tr><td><span class="badge badge-green">APPROVED</span></td><td>Budget disetujui, bisa jalan</td></tr>
        <tr><td><span class="badge badge-red">REJECTED</span></td><td>Ditolak FIN, perlu revisi</td></tr>
        <tr><td><span class="badge badge-green">DONE</span></td><td>Kegiatan selesai, realisasi dicatat</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="mpr-budget">
    <h2>💰 3. Budget & Approval FIN</h2>
    <p>FIN Manager bertugas mereview dan approve budget marketing:</p>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka MPR Budget (khusus FIN)</div><div class="step-desc">Menu → MPR → Budget Approval. Tampil semua plan yang menunggu approval.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Review Detail Budget</div><div class="step-desc">Cek estimasi biaya, kewajaran, dan ketersediaan budget departemen.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Approve atau Reject</div><div class="step-desc">Approve → tim MPR bisa jalankan kegiatan. Reject → sertakan catatan alasan penolakan.</div></div></div>
  </div>

  <div class="section" id="mpr-realisasi">
    <h2>📊 4. Realisasi & Pembayaran</h2>
    <p>Setelah kegiatan selesai atau saat kegiatan berjalan:</p>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Input Realisasi</div><div class="step-desc">Buka plan → klik Realisasi → input pengeluaran aktual per item + upload bukti (nota/invoice).</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Request Pembayaran</div><div class="step-desc">Jika ada vendor yang perlu dibayar → buat request pembayaran ke FIN.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">FIN Proses Pembayaran</div><div class="step-desc">FIN approve & proses pembayaran → otomatis posting ke GL.</div></div></div>
    <div class="tip">💡 Selalu simpan bukti pengeluaran (nota, kwitansi, invoice) untuk upload ke sistem.</div>
  </div>

  <div class="section" id="mpr-dashboard">
    <h2>📈 5. MPR Dashboard</h2>
    <p>Menu → MPR → Dashboard. Menampilkan:</p>
    <ul>
      <li>Total rencana aktif bulan ini</li>
      <li>Budget terpakai vs budget disetujui</li>
      <li>Kegiatan yang akan berlangsung minggu ini</li>
      <li>Laporan realisasi harian (Ops Daily FIN)</li>
    </ul>
    <h3>Ops Daily FIN</h3>
    <p>Laporan harian pengeluaran marketing per hari — filter per periode, export ke CSV untuk laporan ke manajemen.</p>
  </div>

  <div class="section" id="mpr-roles">
    <h2>👤 6. Role & Akses MPR</h2>
    <table class="role-table">
      <thead><tr><th>Role</th><th>Akses</th></tr></thead>
      <tbody>
        <tr><td><span class="badge badge-blue">MPR Staff/Manager</span></td><td>Buat & kelola rencana, input realisasi, lihat dashboard</td></tr>
        <tr><td><span class="badge badge-blue">FIN Manager</span></td><td>Approval budget, proses pembayaran, lihat laporan realisasi</td></tr>
        <tr><td><span class="badge badge-red">SYS/Admin</span></td><td>Akses penuh semua fitur MPR</td></tr>
      </tbody>
    </table>
  </div>

<?php elseif ($mod === 'asset'): ?>
  <a href="?mod=home" class="back-link">← Kembali</a>
  <div class="page-title">🏢 Panduan Modul Fixed Asset</div>
  <div class="page-sub">Manajemen Aset Tetap — Pencatatan, Depresiasi, Audit, dan Disposal</div>

  <div class="toc">
    <div class="toc-title">Daftar Isi</div>
    <a href="#fa-overview">1. Overview Fixed Asset</a>
    <a href="#fa-register">2. Registrasi Aset Baru</a>
    <a href="#fa-operations">3. Operasional Aset</a>
    <a href="#fa-depreciation">4. Depresiasi</a>
    <a href="#fa-audit">5. Audit Aset</a>
    <a href="#fa-disposal">6. Disposal Aset</a>
    <a href="#fa-tax">7. Laporan Pajak Tahunan</a>
  </div>

  <div class="section" id="fa-overview">
    <h2>🏢 1. Overview Fixed Asset</h2>
    <p>Modul Fixed Asset mengelola seluruh siklus hidup aset perusahaan:</p>
    <table class="role-table">
      <thead><tr><th>Tahap</th><th>Keterangan</th></tr></thead>
      <tbody>
        <tr><td>Akuisisi</td><td>Pencatatan aset baru yang dibeli/diperoleh</td></tr>
        <tr><td>Operasional</td><td>Transfer, pemeliharaan, perubahan data aset</td></tr>
        <tr><td>Depresiasi</td><td>Perhitungan penyusutan nilai aset per periode</td></tr>
        <tr><td>Audit</td><td>Verifikasi fisik aset secara berkala</td></tr>
        <tr><td>Disposal</td><td>Penghapusan aset yang sudah tidak dipakai</td></tr>
      </tbody>
    </table>
    <div class="tip">💡 Dashboard Fixed Asset menampilkan: total aset, aset aktif, aset disposed, dan info depresiasi terakhir.</div>
  </div>

  <div class="section" id="fa-register">
    <h2>➕ 2. Registrasi Aset Baru</h2>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka menu Aset</div><div class="step-desc">Menu → Fixed Asset → Aset → klik "Tambah Aset Baru" atau "Quick Acquisition".</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Isi Data Aset</div><div class="step-desc">
      <ul style="margin:4px 0;padding-left:16px">
        <li>Kode & nama aset</li>
        <li>Office & departemen pemilik</li>
        <li>Tanggal akuisisi & harga perolehan</li>
        <li>Kategori aset & masa manfaat (tahun)</li>
        <li>Metode depresiasi (Garis Lurus/Saldo Menurun)</li>
      </ul>
    </div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Simpan</div><div class="step-desc">Aset tersimpan dengan status <span class="badge badge-green">ACTIVE</span>. Siap didepresiasi.</div></div></div>
    <table class="role-table" style="margin-top:12px">
      <thead><tr><th>Status Aset</th><th>Arti</th></tr></thead>
      <tbody>
        <tr><td><span class="badge badge-green">ACTIVE</span></td><td>Aset aktif digunakan</td></tr>
        <tr><td><span class="badge badge-yellow">MAINTENANCE</span></td><td>Sedang dalam perbaikan</td></tr>
        <tr><td><span class="badge badge-red">DISPOSED</span></td><td>Sudah dijual/dihapus</td></tr>
      </tbody>
    </table>
  </div>

  <div class="section" id="fa-operations">
    <h2>⚙️ 3. Operasional Aset</h2>
    <p>Menu → Fixed Asset → Operasional. Tersedia 4 tab:</p>
    <table class="role-table">
      <thead><tr><th>Tab</th><th>Fungsi</th></tr></thead>
      <tbody>
        <tr><td>Akuisisi</td><td>Tambah aset baru secara cepat</td></tr>
        <tr><td>Transfer</td><td>Pindahkan aset antar office/departemen</td></tr>
        <tr><td>Pemeliharaan</td><td>Catat biaya maintenance/perbaikan aset</td></tr>
        <tr><td>Disposal</td><td>Proses penghapusan aset dari daftar aktif</td></tr>
      </tbody>
    </table>
    <h3>Transfer Aset</h3>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Pilih aset yang akan ditransfer</div><div class="step-desc">Cari kode/nama aset → klik Transfer.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Pilih tujuan</div><div class="step-desc">Pilih office & departemen tujuan → isi tanggal transfer & alasan.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Konfirmasi</div><div class="step-desc">Transfer tercatat di history aset — bisa dilacak ke mana aset pernah berpindah.</div></div></div>
  </div>

  <div class="section" id="fa-depreciation">
    <h2>📉 4. Depresiasi Aset</h2>
    <p>Menu → Fixed Asset → Depresiasi.</p>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Pilih Periode</div><div class="step-desc">Pilih bulan & tahun yang akan didepresiasi (contoh: Maret 2026).</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Preview Depresiasi</div><div class="step-desc">Sistem menghitung nilai depresiasi semua aset aktif untuk periode tersebut — cek sebelum dikonfirmasi.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Run Depresiasi</div><div class="step-desc">Klik "Run Depresiasi" → nilai buku aset terupdate, jurnal GL otomatis posting.</div></div></div>
    <div class="warn">⚠️ Depresiasi yang sudah di-run tidak bisa dibatalkan. Pastikan data aset sudah benar sebelum run.</div>
    <div class="tip">💡 Lakukan depresiasi setiap akhir bulan untuk laporan keuangan yang akurat.</div>
  </div>

  <div class="section" id="fa-audit">
    <h2>🔍 5. Audit Aset</h2>
    <p>Verifikasi fisik aset secara berkala:</p>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buat Sesi Audit</div><div class="step-desc">Menu → Fixed Asset → Audit → Buat audit baru → pilih office/dept yang akan diaudit.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Verifikasi Fisik</div><div class="step-desc">Tim audit cek fisik aset satu per satu → tandai: Ditemukan / Tidak Ditemukan / Kondisi.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Tutup Audit</div><div class="step-desc">Setelah semua aset diverifikasi → tutup sesi audit. Laporan selisih tersimpan.</div></div></div>
    <p>Dashboard menampilkan jumlah audit dengan status <span class="badge badge-yellow">OPEN</span> yang belum selesai.</p>
  </div>

  <div class="section" id="fa-disposal">
    <h2>🗑️ 6. Disposal Aset</h2>
    <p>Untuk aset yang sudah tidak digunakan (dijual, rusak, dihapus):</p>
    <div class="step"><div class="step-num">1</div><div class="step-body"><div class="step-title">Buka tab Disposal</div><div class="step-desc">Menu → Fixed Asset → Operasional → tab Disposal.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-body"><div class="step-title">Pilih Aset & Isi Detail</div><div class="step-desc">Pilih aset → isi tanggal disposal, alasan (dijual/rusak/hilang), nilai jual jika ada.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-body"><div class="step-title">Konfirmasi</div><div class="step-desc">Aset berubah status jadi <span class="badge badge-red">DISPOSED</span> → jurnal keuntungan/kerugian disposal posting ke GL.</div></div></div>
  </div>

  <div class="section" id="fa-tax">
    <h2>📄 7. Laporan Pajak Tahunan</h2>
    <p>Menu → Fixed Asset → Tax Annual.</p>
    <ul>
      <li>Laporan depresiasi fiskal per tahun sesuai ketentuan pajak</li>
      <li>Daftar aset beserta nilai buku fiskal</li>
      <li>Export CSV untuk keperluan SPT Badan</li>
    </ul>
    <div class="tip">💡 Laporan pajak menggunakan tarif depresiasi fiskal sesuai PMK — berbeda dengan depresiasi komersial.</div>
  </div>

<?php endif; ?>

</div><!-- /main -->

<script>
function filterMods(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#modGrid .mod-card').forEach(function(card) {
    var label = (card.dataset.label || '').toLowerCase();
    card.style.display = label.includes(q) ? '' : 'none';
  });
}
</script>

</body>
</html>
