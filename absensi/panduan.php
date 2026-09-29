<?php
$title = "Panduan Absensi";
require_once __DIR__ . "/_inc/bootstrap.php";
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.VIEW');
require_once __DIR__ . "/_layout_top.php";
?>

<style>
.pnd-section { background:var(--rmi-card,#1a2235); border:1px solid var(--rmi-border,rgba(255,255,255,.1)); border-radius:14px; padding:20px 24px; margin-bottom:18px }
.pnd-step { display:flex; gap:14px; align-items:flex-start; margin-bottom:16px }
.pnd-num { min-width:32px; height:32px; border-radius:50%; background:#2563eb; color:#fff; font-weight:700; font-size:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px }
.pnd-num.green { background:#16a34a }
.pnd-num.yellow { background:#d97706 }
.pnd-num.red { background:#dc2626 }
.pnd-title { font-weight:600; margin-bottom:4px; font-size:15px }
.pnd-desc { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.6 }
.pnd-badge { display:inline-block; padding:2px 10px; border-radius:12px; font-size:12px; font-weight:600 }
.pnd-badge.in { background:#14532d; color:#4ade80 }
.pnd-badge.out { background:#1e3a5f; color:#60a5fa }
.pnd-badge.izin { background:#451a03; color:#fbbf24 }
.pnd-faq-q { font-weight:600; margin-bottom:4px; font-size:14px; color:var(--rmi-text,#e8ecf4) }
.pnd-faq-a { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.6; margin-bottom:16px }
.pnd-alert { background:#1c1917; border:1px solid #44403c; border-radius:10px; padding:12px 16px; font-size:13px; color:#a8a29e; margin-top:10px }
.pnd-alert strong { color:#fbbf24 }
h3 { font-size:16px; font-weight:700; margin-bottom:14px }
</style>

<div style="max-width:760px;margin:0 auto">

  <div style="margin-bottom:20px">
    <h2 style="margin:0 0 4px 0">Panduan Absensi</h2>
    <div style="color:var(--rmi-muted,#9ca3af);font-size:13px">
      Absensi by Photo — ERP RMI · Versi Enterprise+++
      &nbsp;·&nbsp;
      <a href="index.php" style="color:#60a5fa">← Kembali ke Dashboard</a>
    </div>
  </div>

  <!-- Cara Check-in -->
  <div class="pnd-section">
    <h3>Check-in <span class="pnd-badge in">Masuk</span></h3>

    <div class="pnd-step">
      <div class="pnd-num green">1</div>
      <div>
        <div class="pnd-title">Buka Halaman Absensi</div>
        <div class="pnd-desc">Login ke ERP → klik menu <b>Absensi</b>, atau akses langsung:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">
            /ERP_RMI_SOFULL/absensi/
          </code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">2</div>
      <div>
        <div class="pnd-title">Klik tombol "Check-in"</div>
        <div class="pnd-desc">Tersedia di Dashboard Absensi bagian <b>Aksi Cepat</b>. Tombol hanya aktif jika belum check-in hari ini.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">3</div>
      <div>
        <div class="pnd-title">Izinkan Akses Kamera & Lokasi</div>
        <div class="pnd-desc">Browser akan meminta izin kamera dan lokasi GPS. Klik <b>Izinkan / Allow</b> untuk keduanya.<br>
          <span style="color:#f87171;font-size:12px"><?=rmi_icon('warn')?> Jika ditolak, check-in tidak bisa dilakukan.</span>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">4</div>
      <div>
        <div class="pnd-title">Ambil Foto Selfie</div>
        <div class="pnd-desc">Kamera akan aktif otomatis. Pastikan wajah terlihat jelas, cahaya cukup, lalu klik <b>Ambil Foto</b>.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">5</div>
      <div>
        <div class="pnd-title">Klik "Kirim Check-in"</div>
        <div class="pnd-desc">Sistem akan menyimpan foto, waktu, dan lokasi. Muncul pesan <b>"Check-in berhasil"</b> jika sukses.</div>
      </div>
    </div>

    <div class="pnd-alert">
      <strong>Catatan GeoFence:</strong> Jika sistem menolak check-in karena lokasi terlalu jauh dari kantor,
      pastikan GPS aktif dan kamu berada di area kantor. Hubungi Tim HRL jika terjadi kendala.
    </div>
  </div>

  <!-- Cara Check-out -->
  <div class="pnd-section">
    <h3>Check-out <span class="pnd-badge out">Pulang</span></h3>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#2563eb">1</div>
      <div>
        <div class="pnd-title">Klik tombol "Check-out"</div>
        <div class="pnd-desc">Tombol aktif setelah check-in. Lakukan saat akan meninggalkan kantor di akhir jam kerja.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#2563eb">2</div>
      <div>
        <div class="pnd-title">Ambil Foto & Kirim</div>
        <div class="pnd-desc">Sama seperti check-in — ambil foto selfie lalu klik <b>Kirim Check-out</b>.</div>
      </div>
    </div>

    <div class="pnd-alert">
      <strong>Penting:</strong> Jangan lupa check-out setiap hari. Data check-out digunakan untuk laporan jam kerja dan payroll.
    </div>
  </div>

  <!-- Izin / Sakit / Dinas -->
  <div class="pnd-section">
    <h3>Izin / Sakit / Dinas Luar <span class="pnd-badge izin">Request</span></h3>

    <div class="pnd-step">
      <div class="pnd-num yellow">1</div>
      <div>
        <div class="pnd-title">Buka menu "Izin / Dinas"</div>
        <div class="pnd-desc">Dari Dashboard Absensi → klik <b>Izin / Dinas</b> di Aksi Cepat.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">2</div>
      <div>
        <div class="pnd-title">Pilih Jenis & Isi Form</div>
        <div class="pnd-desc">
          Pilih jenis: <b>Izin</b> / <b>Sakit</b> / <b>Dinas Luar</b><br>
          Isi tanggal mulai–selesai dan alasan.<br>
          Untuk sakit, lampirkan foto surat dokter jika ada.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">3</div>
      <div>
        <div class="pnd-title">Submit → Tunggu Persetujuan</div>
        <div class="pnd-desc">Status awal: <b>PENDING</b>. Tim HRL akan mereview dan menyetujui / menolak request kamu.</div>
      </div>
    </div>
  </div>

  <!-- Riwayat -->
  <div class="pnd-section">
    <h3>Cek Riwayat Absensi</h3>
    <div class="pnd-desc" style="font-size:13px;line-height:1.7">
      Buka menu <b>Riwayat</b> dari Dashboard Absensi untuk melihat:
      <ul style="margin-top:8px;padding-left:20px">
        <li>Daftar check-in & check-out kamu per hari</li>
        <li>Foto selfie yang tersimpan</li>
        <li>Lokasi dan jarak dari kantor</li>
      </ul>
    </div>
  </div>

  <!-- FAQ -->
  <div class="pnd-section">
    <h3>FAQ — Pertanyaan Umum</h3>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Kamera tidak mau aktif di browser?</div>
    <div class="pnd-faq-a">
      Pastikan browser sudah diberi izin kamera. Buka <b>Pengaturan Browser → Privacy → Izin Kamera</b> dan aktifkan untuk domain ERP.
      Di HP Android: Pengaturan → Aplikasi → Chrome → Izin → Kamera → Izinkan.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Muncul pesan "Lokasi terlalu jauh dari kantor"?</div>
    <div class="pnd-faq-a">
      Aktifkan GPS di HP dan pastikan kamu berada di area kantor. Jika GPS sudah aktif tapi masih ditolak, hubungi Tim HRL
      — mungkin perlu kalibrasi radius kantor di sistem.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Tombol Check-in/Check-out tidak muncul atau abu-abu?</div>
    <div class="pnd-faq-a">
      Tombol Check-in hanya muncul jika belum check-in hari ini.<br>
      Tombol Check-out hanya muncul setelah check-in dan belum check-out.<br>
      Jika sudah check-in & check-out, keduanya akan nonaktif sampai besok.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Lupa check-out kemarin?</div>
    <div class="pnd-faq-a">
      Hubungi Tim HRL untuk koreksi data. Tim HRL bisa melakukan penyesuaian melalui halaman Admin Rekap.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bagaimana cara mengajukan izin sakit mendadak?</div>
    <div class="pnd-faq-a">
      Buka ERP → Absensi → <b>Izin / Dinas</b> → pilih <b>Sakit</b> → isi tanggal hari ini → submit.
      Lampirkan foto surat dokter jika memungkinkan. Tim HRL akan memproses.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Foto absensi saya bisa dilihat siapa?</div>
    <div class="pnd-faq-a">
      Foto hanya bisa dilihat oleh kamu sendiri (di Riwayat) dan Tim HRL (di Admin Rekap).
      Foto digunakan semata-mata untuk verifikasi kehadiran.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Apakah bisa absensi dari luar kantor?</div>
    <div class="pnd-faq-a">
      Tergantung kebijakan perusahaan. Jika GeoFence aktif, check-in di luar radius kantor akan ditolak otomatis.
      Untuk dinas luar, gunakan fitur <b>Izin / Dinas Luar</b>.
    </div>
  </div>

  <!-- Kontak -->
  <div class="pnd-section" style="border-color:rgba(37,99,235,.3)">
    <h3>Butuh Bantuan?</h3>
    <div class="pnd-desc" style="font-size:13px;line-height:1.8">
      Jika mengalami kendala yang tidak tercantum di atas, hubungi <b>Tim HRL</b> via:
      <ul style="margin-top:8px;padding-left:20px">
        <li>WhatsApp Group karyawan</li>
        <li>Langsung ke divisi HRL</li>
      </ul>
      Sertakan: <b>nama lengkap, tanggal, dan screenshot error</b> yang muncul.
    </div>
  </div>

  <div style="text-align:center;margin-top:8px;margin-bottom:24px">
    <a href="index.php" class="btn btn-rmi btn-sm">← Kembali ke Dashboard Absensi</a>
  </div>

</div>

<?php require_once __DIR__ . "/_layout_bottom.php"; ?>
