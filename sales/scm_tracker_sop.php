<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.EDIT', 'MASTER.ADMIN_CENTER']);
} else {
    require_role(['SCM', 'ADMIN', 'SUPERADMIN', 'SYS', 'MANAGER']);
}
require_once __DIR__ . '/../_shared/rmi_branch_guard.php';
rmi_block_branch('SCM Tracker hanya untuk Dept SCM atau Admin.');
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SOP SCM Mobile Tracker</title>
  <style>
    body { margin: 0; font-family: Arial, sans-serif; background: #0b1220; color: #e5e7eb; }
    .wrap { max-width: 860px; margin: 0 auto; padding: 14px; }
    .card { background: #111827; border: 1px solid #334155; border-radius: 12px; padding: 14px; margin-bottom: 12px; }
    .title { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
    .subtitle { font-size: 15px; font-weight: 700; margin: 0 0 8px; }
    .muted { color: #94a3b8; font-size: 12px; }
    .list { margin: 8px 0 0; padding-left: 18px; }
    .list li { margin: 6px 0; color: #cbd5e1; font-size: 14px; }
    .btnrow { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn { border: 1px solid #475569; background: #1f2937; color: #e5e7eb; border-radius: 10px; padding: 10px 12px; text-decoration: none; }
    .ok { color: #34d399; }
    .warn { color: #fbbf24; }
    .bad { color: #f87171; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="title">SOP SCM Mobile Tracker</div>
      <div class="muted">Panduan operasional real-time tracking delivery untuk tim SCM.</div>
      <div class="btnrow" style="margin-top:10px">
        <a class="btn" href="scm_tracker_mobile.php">Kembali ke Tracker</a>
        <a class="btn" href="scm_do_tasks.php">Buka SCM Task</a>
      </div>
    </div>

    <div class="card">
      <div class="subtitle">1) Standar Perangkat SCM</div>
      <ul class="list">
        <li>Android 10+ (disarankan 11+) dengan RAM minimal 4 GB.</li>
        <li>SIM data aktif dengan jaringan stabil 4G/5G.</li>
        <li>Powerbank minimal 10.000 mAh untuk operasional lapangan.</li>
        <li>Gunakan perangkat khusus kerja agar tracking tidak terganggu aplikasi pribadi.</li>
      </ul>
    </div>

    <div class="card">
      <div class="subtitle">2) Setup Awal (sekali)</div>
      <ul class="list">
        <li>Buka <b>SCM Mobile Tracker</b> lalu install ke Home Screen (PWA).</li>
        <li>Izin lokasi set ke <b>Allow all the time</b>.</li>
        <li>Matikan battery optimization untuk browser/PWA tracker.</li>
        <li>Izinkan notifikasi (opsional) dan akses data seluler di background.</li>
      </ul>
    </div>

    <div class="card">
      <div class="subtitle">3) Prosedur Operasional Harian</div>
      <ul class="list">
        <li>Pilih DO aktif pada tracker, lalu klik <b>Start Tracking</b>.</li>
        <li>Pastikan status berubah <span class="ok">Tracking aktif</span> dan koordinat tampil.</li>
        <li>Tekan <b>Keep Screen Awake</b> jika perjalanan panjang.</li>
        <li>Jangan force-close browser/app selama pengiriman berlangsung.</li>
        <li>Setelah sampai, buka SCM Task untuk upload POD + tanda tangan digital sebelum set delivered.</li>
      </ul>
    </div>

    <div class="card">
      <div class="subtitle">4) Validasi Selesai Pengiriman</div>
      <ul class="list">
        <li>Upload bukti foto/video penerimaan barang.</li>
        <li>Isi catatan SCM jika ada kendala di lapangan.</li>
        <li>Pastikan tanda tangan digital customer terisi.</li>
        <li>Baru ubah status ke <b>DELIVERED</b>.</li>
      </ul>
    </div>

    <div class="card">
      <div class="subtitle">5) Troubleshooting Cepat</div>
      <ul class="list">
        <li><span class="bad">GPS error</span>: cek izin lokasi dan restart browser/PWA.</li>
        <li><span class="warn">Ping gagal</span>: cek jaringan, lalu tunggu hingga sinyal stabil.</li>
        <li><span class="warn">Lokasi tidak berubah</span>: aktifkan akurasi lokasi tinggi (high accuracy).</li>
        <li><span class="bad">Baterai cepat habis</span>: gunakan powerbank saat tracking aktif.</li>
      </ul>
    </div>
  </div>
</body>
</html>
