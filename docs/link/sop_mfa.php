<?php
/**
 * docs/sop_mfa.php
 * SOP Resmi MFA — Rizqullah Mediska Indonesia
 */
require_once __DIR__ . '/../../master/auth.php';
require_login();

if (!function_exists('rmi_h')) {
    function rmi_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$bp = defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '';
$username = (string)($_SESSION['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SOP MFA — Rizqullah Mediska Indonesia</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
body{background:#f8fafc;color:#1e293b;font-family:'Inter',system-ui,sans-serif;font-size:14px;line-height:1.6}

/* Print styles */
@media print {
  .no-print{display:none!important}
  body{background:#fff;color:#000;font-size:12px}
  .sop-container{max-width:100%;padding:0;box-shadow:none}
  .sop-header{background:#1e3a5f!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .step-num{background:#1e3a5f!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .badge-wajib{background:#dc2626!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .warning-box{border-left:4px solid #dc2626!important}
  .tip-box{border-left:4px solid #2563eb!important}
  a[href]:after{content:" (" attr(href) ")";font-size:10px;color:#666}
}

.sop-container{max-width:860px;margin:0 auto;padding:24px 16px}

/* Header */
.sop-header{background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%);color:#fff;border-radius:16px;padding:32px;margin-bottom:24px;position:relative;overflow:hidden}
.sop-header::before{content:"";position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.08)}
.sop-header::after{content:"";position:absolute;bottom:-40px;left:-40px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.05)}
.sop-logo{font-size:13px;font-weight:700;letter-spacing:1px;color:rgba(255,255,255,.7);margin-bottom:8px;text-transform:uppercase}
.sop-title{font-size:26px;font-weight:800;margin-bottom:6px}
.sop-sub{font-size:14px;color:rgba(255,255,255,.8);margin-bottom:16px}
.sop-meta{display:flex;gap:20px;flex-wrap:wrap;font-size:12px;color:rgba(255,255,255,.7)}
.sop-meta span strong{color:#fff}
.badge-wajib{background:#dc2626;color:#fff;padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700;display:inline-block;margin-top:12px;letter-spacing:.5px}

/* Nav actions */
.sop-actions{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.btn-action{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.btn-primary{background:#2563eb;color:#fff}
.btn-primary:hover{background:#1d4ed8}
.btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db}
.btn-outline:hover{background:#f9fafb}

/* Content sections */
.sop-section{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:16px}
.sop-section-title{font-size:16px;font-weight:700;color:#1e3a5f;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #eff6ff;display:flex;align-items:center;gap:8px}
.sop-section-title .sec-num{background:#2563eb;color:#fff;width:26px;height:26px;border-radius:50%;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}

/* Step */
.step{display:flex;gap:14px;margin-bottom:18px;align-items:flex-start}
.step-num{min-width:32px;height:32px;border-radius:50%;background:#2563eb;color:#fff;font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.step-num.green{background:#16a34a}
.step-num.red{background:#dc2626}
.step-content{}
.step-title{font-weight:700;font-size:14px;color:#1e293b;margin-bottom:3px}
.step-desc{font-size:13px;color:#64748b;line-height:1.6}
code.inline{background:#f1f5f9;padding:2px 8px;border-radius:6px;font-size:12px;font-family:monospace;color:#334155}

/* Boxes */
.warning-box{background:#fef2f2;border-left:4px solid #dc2626;border-radius:0 8px 8px 0;padding:12px 16px;margin:12px 0;font-size:13px;color:#991b1b}
.warning-box strong{color:#dc2626}
.tip-box{background:#eff6ff;border-left:4px solid #2563eb;border-radius:0 8px 8px 0;padding:12px 16px;margin:12px 0;font-size:13px;color:#1e40af}
.tip-box strong{color:#2563eb}
.success-box{background:#f0fdf4;border-left:4px solid #16a34a;border-radius:0 8px 8px 0;padding:12px 16px;margin:12px 0;font-size:13px;color:#166534}

/* Table */
.sop-table{width:100%;border-collapse:collapse;font-size:13px;margin:12px 0}
.sop-table th{background:#f1f5f9;color:#374151;padding:10px 14px;text-align:left;font-weight:700;border:1px solid #e2e8f0}
.sop-table td{padding:10px 14px;border:1px solid #e2e8f0;color:#475569}
.sop-table tr:hover td{background:#f8fafc}

/* App cards */
.app-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:12px 0}
.app-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center}
.app-icon{font-size:32px;margin-bottom:8px}
.app-name{font-weight:700;font-size:14px;color:#1e293b;margin-bottom:4px}
.app-desc{font-size:12px;color:#64748b}
.app-badge{display:inline-block;margin-top:6px;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600}
.badge-rec{background:#dcfce7;color:#166534}
.badge-ok{background:#dbeafe;color:#1e40af}

/* Policy table */
.policy-row{display:flex;align-items:center;gap:12px;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px}
.policy-icon{font-size:20px;flex-shrink:0}
.policy-text{flex:1;font-size:13px;color:#374151}
.policy-status{font-size:12px;font-weight:700;padding:3px 10px;border-radius:12px}
.status-wajib{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.status-opsional{background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd}

/* QR simulation */
.qr-demo{background:#fff;border:2px dashed #94a3b8;border-radius:12px;padding:24px;text-align:center;color:#94a3b8;font-size:13px;margin:12px 0}
.qr-demo .qr-icon{font-size:48px;margin-bottom:8px}

/* Signature */
.signature-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:16px}
.sig-box{border:1px solid #e2e8f0;border-radius:8px;padding:16px;text-align:center}
.sig-line{border-top:1px solid #94a3b8;margin:40px 8px 8px;font-size:12px;color:#64748b}
.sig-label{font-size:12px;font-weight:700;color:#374151}

/* Deadline banner */
.deadline-banner{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px}
.deadline-icon{font-size:28px;flex-shrink:0}
.deadline-text strong{font-size:15px;display:block;margin-bottom:2px}
.deadline-text span{font-size:13px;opacity:.9}
</style>
</head>
<body>

<div class="sop-container">

  <!-- Actions bar -->
  <div class="sop-actions no-print">
    <button class="btn-action btn-primary" onclick="window.print()">🖨️ Cetak / Save PDF</button>
    <a href="<?= rmi_h($bp) ?>/master/mfa_settings.php" class="btn-action btn-primary">🔐 Aktifkan MFA Sekarang</a>
    <a href="<?= rmi_h($bp) ?>/" class="btn-action btn-outline">← Kembali ke ERP</a>
  </div>

  <!-- Header -->
  <div class="sop-header">
    <div class="sop-logo">Rizqullah Mediska Indonesia — Dokumen Resmi</div>
    <div class="sop-title">🔐 SOP Keamanan Akun ERP<br>Multi-Factor Authentication (MFA)</div>
    <div class="sop-sub">Standard Operating Procedure — Wajib Dipatuhi Seluruh Karyawan</div>
    <div class="sop-meta">
      <span>📄 Nomor: <strong>SOP-ITC-001</strong></span>
      <span>📅 Tanggal: <strong><?= date('d F Y') ?></strong></span>
      <span>🔄 Revisi: <strong>01</strong></span>
      <span>👤 Dibuat oleh: <strong>Divisi ITC</strong></span>
      <span>✅ Disetujui: <strong>Manajemen RMI</strong></span>
    </div>
    <div class="badge-wajib">🚨 WAJIB — Berlaku untuk SEMUA Karyawan RMI</div>
  </div>

  <!-- Deadline Banner -->
  <div class="deadline-banner no-print">
    <div class="deadline-icon">⏰</div>
    <div class="deadline-text">
      <strong>BATAS AKTIVASI MFA: 7 Hari Sejak Sosialisasi</strong>
      <span>Akun yang belum mengaktifkan MFA setelah batas waktu akan dinonaktifkan sementara oleh Tim ITC.</span>
    </div>
  </div>

  <!-- Section 1: Tujuan & Ruang Lingkup -->
  <div class="sop-section">
    <div class="sop-section-title"><div class="sec-num">1</div> Tujuan & Ruang Lingkup</div>

    <p style="color:#475569;margin-bottom:16px">
      SOP ini menetapkan kewajiban penggunaan <strong>Multi-Factor Authentication (MFA)</strong> pada seluruh akun ERP Rizqullah Mediska Indonesia untuk melindungi data perusahaan, data karyawan, dan data transaksi bisnis dari akses tidak sah.
    </p>

    <table class="sop-table">
      <thead><tr><th>Aspek</th><th>Keterangan</th></tr></thead>
      <tbody>
        <tr><td><strong>Berlaku untuk</strong></td><td>Seluruh karyawan aktif RMI yang memiliki akun ERP</td></tr>
        <tr><td><strong>Sistem yang dimaksud</strong></td><td>ERP RMI SOFULL (erp.rizqullahmediska.com)</td></tr>
        <tr><td><strong>Metode MFA</strong></td><td>TOTP (Time-based One-Time Password) — 6 digit, berganti tiap 30 detik</td></tr>
        <tr><td><strong>Aplikasi yang digunakan</strong></td><td>Google Authenticator / Microsoft Authenticator / Authy</td></tr>
        <tr><td><strong>Efektif berlaku</strong></td><td>Segera setelah sosialisasi</td></tr>
        <tr><td><strong>Penanggung jawab</strong></td><td>Divisi ITC</td></tr>
      </tbody>
    </table>
  </div>

  <!-- Section 2: Kebijakan -->
  <div class="sop-section">
    <div class="sop-section-title"><div class="sec-num">2</div> Kebijakan MFA</div>

    <div class="policy-row">
      <div class="policy-icon">👤</div>
      <div class="policy-text"><strong>Semua karyawan</strong> dengan akun ERP wajib mengaktifkan MFA — tidak ada pengecualian</div>
      <div class="policy-status status-wajib">WAJIB</div>
    </div>
    <div class="policy-row">
      <div class="policy-icon">📱</div>
      <div class="policy-text">Aplikasi authenticator wajib dipasang di <strong>HP pribadi karyawan</strong> — bukan HP kantor yang bergantian</div>
      <div class="policy-status status-wajib">WAJIB</div>
    </div>
    <div class="policy-row">
      <div class="policy-icon">🔑</div>
      <div class="policy-text"><strong>Backup code</strong> wajib disimpan di tempat aman — cetak atau simpan di password manager</div>
      <div class="policy-status status-wajib">WAJIB</div>
    </div>
    <div class="policy-row">
      <div class="policy-icon">🚫</div>
      <div class="policy-text">Dilarang membagikan kode OTP kepada siapapun — termasuk Tim ITC sekalipun</div>
      <div class="policy-status status-wajib">DILARANG</div>
    </div>
    <div class="policy-row">
      <div class="policy-icon">🔄</div>
      <div class="policy-text">Jika HP hilang/rusak, segera laporkan ke Tim ITC untuk reset MFA dan aktivasi ulang</div>
      <div class="policy-status status-wajib">WAJIB</div>
    </div>
    <div class="policy-row">
      <div class="policy-icon">📋</div>
      <div class="policy-text">Penonaktifan MFA hanya bisa dilakukan oleh Tim ITC dengan persetujuan Manager terkait</div>
      <div class="policy-status status-opsional">HANYA ITC</div>
    </div>

    <div class="warning-box">
      <strong>⚠️ Sanksi:</strong> Karyawan yang tidak mengaktifkan MFA setelah batas waktu yang ditetapkan akan dinonaktifkan aksesnya ke sistem ERP sampai MFA diaktifkan. Pelanggaran berulang akan diproses sesuai peraturan perusahaan.
    </div>
  </div>

  <!-- Section 3: Aplikasi Authenticator -->
  <div class="sop-section">
    <div class="sop-section-title"><div class="sec-num">3</div> Pilihan Aplikasi Authenticator</div>

    <p style="color:#475569;margin-bottom:14px">Pilih salah satu aplikasi berikut. Install di HP pribadi melalui App Store / Play Store:</p>

    <div class="app-grid">
      <div class="app-card">
        <div class="app-icon">🔵</div>
        <div class="app-name">Google Authenticator</div>
        <div class="app-desc">Paling umum digunakan. Tersedia di Android & iOS.</div>
        <span class="app-badge badge-rec">⭐ Direkomendasikan</span>
      </div>
      <div class="app-card">
        <div class="app-icon">🟦</div>
        <div class="app-name">Microsoft Authenticator</div>
        <div class="app-desc">Pilihan bagus jika sudah pakai Microsoft 365.</div>
        <span class="app-badge badge-ok">✓ Disetujui</span>
      </div>
      <div class="app-card">
        <div class="app-icon">🟣</div>
        <div class="app-name">Authy</div>
        <div class="app-desc">Mendukung backup cloud & multi-device.</div>
        <span class="app-badge badge-ok">✓ Disetujui</span>
      </div>
    </div>

    <div class="tip-box">
      <strong>💡 Tips:</strong> Jika kamu menggunakan <strong>Google Authenticator</strong> versi terbaru, aktifkan fitur backup ke Google Account agar kode tidak hilang jika HP diganti.
    </div>
  </div>

  <!-- Section 4: Panduan Aktivasi -->
  <div class="sop-section">
    <div class="sop-section-title"><div class="sec-num">4</div> Panduan Aktivasi MFA — Step by Step</div>

    <div class="tip-box" style="margin-bottom:18px">
      <strong>🕐 Estimasi waktu:</strong> 5–10 menit. Siapkan HP dan pastikan aplikasi authenticator sudah terinstall.
    </div>

    <div class="step">
      <div class="step-num">1</div>
      <div class="step-content">
        <div class="step-title">Login ke ERP RMI</div>
        <div class="step-desc">
          Buka browser → akses: <code class="inline">erp.rizqullahmediska.com</code><br>
          Masukkan username dan password akun ERP kamu.
        </div>
      </div>
    </div>

    <div class="step">
      <div class="step-num">2</div>
      <div class="step-content">
        <div class="step-title">Buka Pengaturan MFA</div>
        <div class="step-desc">
          Setelah login → klik menu <strong>MFA Settings</strong> di navigasi atas.<br>
          Atau akses langsung: <code class="inline">erp.rizqullahmediska.com/ERP_RMI_SOFULL/master/mfa_settings.php</code>
        </div>
      </div>
    </div>

    <div class="step">
      <div class="step-num">3</div>
      <div class="step-content">
        <div class="step-title">Klik "Aktifkan MFA"</div>
        <div class="step-desc">
          Di halaman MFA Settings → klik tombol <strong>"Aktifkan MFA / Start Enrollment"</strong>.<br>
          Sistem akan men-generate QR Code dan secret key untuk akunmu.
        </div>
      </div>
    </div>

    <div class="step">
      <div class="step-num">4</div>
      <div class="step-content">
        <div class="step-title">Scan QR Code dengan Aplikasi Authenticator</div>
        <div class="step-desc">
          Buka aplikasi <strong>Google Authenticator</strong> (atau yang kamu pilih) di HP:<br>
          <ul style="margin:6px 0 0 16px;color:#64748b">
            <li>Google Authenticator: klik <strong>+ → Scan QR code</strong></li>
            <li>Microsoft Authenticator: klik <strong>+ → Akun lain → Scan QR</strong></li>
            <li>Authy: klik <strong>+ → Masukkan kode secara manual</strong> atau scan QR</li>
          </ul>
          Arahkan kamera HP ke QR code yang tampil di layar ERP.
        </div>
      </div>
    </div>

    <div class="qr-demo no-print">
      <div class="qr-icon">📱</div>
      <strong>QR Code akan muncul di halaman MFA Settings</strong><br>
      Scan dengan aplikasi authenticator di HP kamu
    </div>

    <div class="step">
      <div class="step-num">5</div>
      <div class="step-content">
        <div class="step-title">Masukkan Kode OTP untuk Verifikasi</div>
        <div class="step-desc">
          Setelah scan, aplikasi authenticator akan menampilkan kode <strong>6 digit</strong> yang berganti setiap 30 detik.<br>
          Masukkan kode tersebut ke kolom "OTP" di halaman ERP → klik <strong>Verifikasi & Aktifkan</strong>.
        </div>
      </div>
    </div>

    <div class="step">
      <div class="step-num green">6</div>
      <div class="step-content">
        <div class="step-title">Simpan Backup Code — SANGAT PENTING!</div>
        <div class="step-desc">
          Setelah berhasil, sistem akan menampilkan <strong>8 backup code</strong>.<br>
          Backup code digunakan jika HP hilang/rusak dan tidak bisa buka authenticator.
          <div class="warning-box" style="margin-top:8px">
            <strong>⚠️ WAJIB:</strong> Catat atau print backup code. Simpan di tempat aman (bukan di HP yang sama). Backup code hanya tampil sekali — tidak bisa dilihat lagi setelah halaman ditutup.
          </div>
        </div>
      </div>
    </div>

    <div class="success-box">
      ✅ <strong>MFA berhasil diaktifkan!</strong> Mulai sekarang, setiap login ke ERP akan meminta kode 6 digit dari aplikasi authenticator kamu.
    </div>
  </div>

  <!-- Section 5: Cara Login dengan MFA -->
  <div class="sop-section">
    <div class="sop-section-title"><div class="sec-num">5</div> Cara Login ke ERP dengan MFA Aktif</div>

    <div class="step">
      <div class="step-num">1</div>
      <div class="step-content">
        <div class="step-title">Masukkan Username & Password seperti biasa</div>
        <div class="step-desc">Login ke ERP → isi username dan password → klik Login.</div>
      </div>
    </div>

    <div class="step">
      <div class="step-num">2</div>
      <div class="step-content">
        <div class="step-title">Buka Aplikasi Authenticator di HP</div>
        <div class="step-desc">Buka Google Authenticator → cari akun <strong>ERP_RMI_SOFULL</strong> → lihat kode 6 digit yang tampil.</div>
      </div>
    </div>

    <div class="step">
      <div class="step-num">3</div>
      <div class="step-content">
        <div class="step-title">Masukkan Kode OTP</div>
        <div class="step-desc">
          Ketik kode 6 digit di kolom OTP yang muncul di layar ERP → klik <strong>Verifikasi</strong>.<br>
          <span style="color:#ef4444;font-size:12px">⏱️ Kode berlaku hanya 30 detik — jika habis, tunggu kode baru muncul.</span>
        </div>
      </div>
    </div>

    <div class="step">
      <div class="step-num green">4</div>
      <div class="step-content">
        <div class="step-title">Login Berhasil</div>
        <div class="step-desc">Kamu masuk ke ERP. Proses ini hanya butuh 10–15 detik tambahan.</div>
      </div>
    </div>
  </div>

  <!-- Section 6: HP Hilang / Masalah MFA -->
  <div class="sop-section">
    <div class="sop-section-title"><div class="sec-num">6</div> Prosedur Darurat — HP Hilang / MFA Bermasalah</div>

    <table class="sop-table">
      <thead><tr><th>Situasi</th><th>Yang Harus Dilakukan</th><th>Hubungi</th></tr></thead>
      <tbody>
        <tr>
          <td>HP hilang/rusak, tidak bisa buka authenticator</td>
          <td>Gunakan <strong>backup code</strong> untuk login → segera lapor ke ITC untuk reset MFA</td>
          <td>Tim ITC</td>
        </tr>
        <tr>
          <td>Backup code juga hilang</td>
          <td>Hubungi Tim ITC dengan bukti identitas → ITC reset MFA setelah verifikasi manual</td>
          <td>Tim ITC + Manager</td>
        </tr>
        <tr>
          <td>Kode OTP selalu salah / tidak valid</td>
          <td>Pastikan waktu HP sudah sinkron (Settings → Date & Time → Automatic). Coba sync ulang authenticator</td>
          <td>Tim ITC jika masih bermasalah</td>
        </tr>
        <tr>
          <td>Ganti HP baru</td>
          <td>Install authenticator di HP baru → <strong>sebelum</strong> hapus HP lama, transfer akun authenticator dulu</td>
          <td>Minta panduan ke ITC</td>
        </tr>
      </tbody>
    </table>

    <div class="warning-box">
      <strong>⚠️ Penting:</strong> Tim ITC <strong>TIDAK PERNAH</strong> meminta kode OTP kamu melalui chat atau telepon. Jika ada yang meminta, segera tolak dan laporkan ke Manager.
    </div>
  </div>

  <!-- Section 7: Cara Penggunaan Backup Code -->
  <div class="sop-section">
    <div class="sop-section-title"><div class="sec-num">7</div> Cara Menggunakan Backup Code</div>

    <p style="color:#475569;margin-bottom:14px">Backup code digunakan sebagai alternatif OTP jika HP tidak tersedia:</p>

    <div class="step">
      <div class="step-num">1</div>
      <div class="step-content">
        <div class="step-title">Di halaman login ERP → masukkan username & password</div>
        <div class="step-desc">Login seperti biasa dengan kredensial kamu.</div>
      </div>
    </div>

    <div class="step">
      <div class="step-num">2</div>
      <div class="step-content">
        <div class="step-title">Di kolom OTP → klik "Gunakan Backup Code"</div>
        <div class="step-desc">Atau masukkan salah satu backup code langsung di kolom OTP.</div>
      </div>
    </div>

    <div class="step">
      <div class="step-num">3</div>
      <div class="step-content">
        <div class="step-title">Masukkan backup code (format: XXXXXX)</div>
        <div class="step-desc">Gunakan salah satu dari 8 backup code yang disimpan. Setiap backup code hanya bisa dipakai <strong>satu kali</strong>.</div>
      </div>
    </div>

    <div class="step">
      <div class="step-num red">4</div>
      <div class="step-content">
        <div class="step-title">Setelah berhasil login — segera aktifkan ulang MFA!</div>
        <div class="step-desc">Buka MFA Settings → aktifkan ulang dengan perangkat baru. Hubungi ITC jika butuh bantuan.</div>
      </div>
    </div>
  </div>

  <!-- Section 8: Tanggung Jawab -->
  <div class="sop-section">
    <div class="sop-section-title"><div class="sec-num">8</div> Tanggung Jawab</div>

    <table class="sop-table">
      <thead><tr><th>Pihak</th><th>Tanggung Jawab</th></tr></thead>
      <tbody>
        <tr>
          <td><strong>Setiap Karyawan</strong></td>
          <td>
            • Mengaktifkan MFA dalam batas waktu yang ditetapkan<br>
            • Menjaga kerahasiaan kode OTP dan backup code<br>
            • Melaporkan ke ITC jika HP hilang atau MFA bermasalah<br>
            • Tidak meminjamkan akses ERP ke orang lain
          </td>
        </tr>
        <tr>
          <td><strong>Manager / Atasan</strong></td>
          <td>
            • Memastikan seluruh tim mengaktifkan MFA dalam batas waktu<br>
            • Melaporkan karyawan yang belum comply ke Tim ITC<br>
            • Memberikan persetujuan jika ITC perlu reset MFA karyawan
          </td>
        </tr>
        <tr>
          <td><strong>Tim ITC</strong></td>
          <td>
            • Memantau status MFA seluruh akun ERP<br>
            • Memberikan bantuan teknis aktivasi MFA<br>
            • Menonaktifkan akun yang belum comply setelah batas waktu<br>
            • Melakukan reset MFA dengan prosedur verifikasi yang benar
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Section 9: FAQ -->
  <div class="sop-section">
    <div class="sop-section-title"><div class="sec-num">9</div> FAQ — Pertanyaan yang Sering Diajukan</div>

    <div style="margin-bottom:14px">
      <div style="font-weight:700;color:#1e293b;margin-bottom:4px">❓ Apakah MFA berlaku setiap kali login?</div>
      <div style="font-size:13px;color:#64748b">Ya, setiap login ke ERP akan meminta kode OTP dari aplikasi authenticator. Ini demi keamanan akun kamu.</div>
    </div>

    <div style="margin-bottom:14px">
      <div style="font-weight:700;color:#1e293b;margin-bottom:4px">❓ Bagaimana jika baterai HP habis saat mau login?</div>
      <div style="font-size:13px;color:#64748b">Gunakan backup code yang sudah disimpan. Pastikan selalu ada charger atau HP cadangan untuk keadaan darurat.</div>
    </div>

    <div style="margin-bottom:14px">
      <div style="font-weight:700;color:#1e293b;margin-bottom:4px">❓ Apakah kode OTP bisa dipakai berulang?</div>
      <div style="font-size:13px;color:#64748b">Tidak. Kode OTP berubah setiap 30 detik dan hanya bisa dipakai sekali. Backup code juga hanya bisa dipakai sekali.</div>
    </div>

    <div style="margin-bottom:14px">
      <div style="font-weight:700;color:#1e293b;margin-bottom:4px">❓ Bolehkah menggunakan HP kantor untuk authenticator?</div>
      <div style="font-size:13px;color:#64748b">Tidak disarankan jika HP kantor bergantian dipakai. Gunakan HP pribadi agar akses authenticator terjaga oleh kamu sendiri.</div>
    </div>

    <div style="margin-bottom:14px">
      <div style="font-weight:700;color:#1e293b;margin-bottom:4px">❓ Apakah backup code bisa diminta ulang?</div>
      <div style="font-size:13px;color:#64748b">Tidak bisa. Backup code hanya tampil saat pertama aktivasi. Jika semua backup code sudah terpakai, hubungi ITC untuk reset dan aktivasi ulang MFA.</div>
    </div>

    <div>
      <div style="font-weight:700;color:#1e293b;margin-bottom:4px">❓ Siapa yang harus dihubungi jika ada masalah?</div>
      <div style="font-size:13px;color:#64748b">Hubungi <strong>Tim ITC</strong> melalui WhatsApp Group karyawan atau langsung ke divisi ITC. Sertakan: nama lengkap, username ERP, dan deskripsi masalah.</div>
    </div>
  </div>

  <!-- Signature Section -->
  <div class="sop-section">
    <div class="sop-section-title"><div class="sec-num">10</div> Pengesahan Dokumen</div>

    <div class="signature-grid">
      <div class="sig-box">
        <div class="sig-line">Dibuat oleh</div>
        <div class="sig-label">Divisi ITC<br>Rizqullah Mediska Indonesia</div>
      </div>
      <div class="sig-box">
        <div class="sig-line">Diperiksa oleh</div>
        <div class="sig-label">Manager ITC<br>Rizqullah Mediska Indonesia</div>
      </div>
      <div class="sig-box">
        <div class="sig-line">Disetujui oleh</div>
        <div class="sig-label">Direktur / Manajemen<br>Rizqullah Mediska Indonesia</div>
      </div>
    </div>

    <div style="margin-top:20px;padding:14px;background:#f8fafc;border-radius:8px;font-size:12px;color:#64748b;text-align:center">
      Dokumen ini diterbitkan oleh Rizqullah Mediska Indonesia • SOP-ITC-001 • Revisi 01<br>
      <?= date('d F Y') ?> • Berlaku untuk semua karyawan aktif pemegang akun ERP RMI
    </div>
  </div>

  <!-- CTA -->
  <div style="background:linear-gradient(135deg,#1e3a5f,#2563eb);border-radius:14px;padding:28px;text-align:center;color:#fff;margin-top:8px" class="no-print">
    <div style="font-size:28px;margin-bottom:8px">🔐</div>
    <div style="font-size:18px;font-weight:800;margin-bottom:6px">Aktifkan MFA Sekarang</div>
    <div style="font-size:13px;opacity:.85;margin-bottom:16px">Hanya butuh 5 menit. Lindungi akun ERP kamu hari ini.</div>
    <a href="<?= rmi_h($bp) ?>/master/mfa_settings.php"
       style="background:#fff;color:#1e3a5f;padding:10px 28px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;display:inline-block">
      → Buka Pengaturan MFA
    </a>
  </div>

</div><!-- /container -->

</body>
</html>
