<?php
declare(strict_types=1);
require_once __DIR__ . '/_inc/bootstrap.php';
require_login();

$page_title = 'Panduan HRL Process';
require_once __DIR__ . '/_layout_top.php';
?>

<style>
.pnd-wrap    { max-width:780px; margin:0 auto }
.pnd-section { background:var(--rmi-card,#1a2235);
               border:1px solid var(--rmi-border,rgba(255,255,255,.1));
               border-radius:14px; padding:22px 26px; margin-bottom:18px }
.pnd-section.accent { border-color:rgba(59,130,246,.3) }

.pnd-step  { display:flex; gap:14px; align-items:flex-start; margin-bottom:18px }
.pnd-step:last-child { margin-bottom:0 }
.pnd-num   { min-width:32px; height:32px; border-radius:50%; color:#fff; font-weight:700; font-size:14px;
             display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px }
.pnd-num.blue   { background:#2563eb }
.pnd-num.green  { background:#16a34a }
.pnd-num.yellow { background:#d97706 }
.pnd-num.purple { background:#7c3aed }
.pnd-num.teal   { background:#0d9488 }

.pnd-title { font-weight:600; margin-bottom:5px; font-size:14px; color:var(--rmi-text,#e8ecf4) }
.pnd-desc  { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7 }

.pnd-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 10px; border-radius:20px;
             font-size:11px; font-weight:600 }
.pnd-badge.draft    { background:rgba(100,116,139,.2); color:#94a3b8 }
.pnd-badge.submit   { background:rgba(245,158,11,.15); color:#fbbf24 }
.pnd-badge.approved { background:rgba(16,185,129,.15); color:#34d399 }
.pnd-badge.rejected { background:rgba(239,68,68,.15);  color:#f87171 }
.pnd-badge.paid     { background:rgba(20,184,166,.15); color:#2dd4bf }

.pnd-faq-q { font-weight:600; font-size:14px; color:var(--rmi-text,#e8ecf4); margin-bottom:5px; margin-top:16px }
.pnd-faq-q:first-child { margin-top:0 }
.pnd-faq-a { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7; margin-bottom:4px }

.pnd-alert { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2);
             border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-alert strong { color:#fbbf24 }

.pnd-info  { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2);
             border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-info strong { color:#60a5fa }

/* Pipeline panduan */
.pnd-pipe { display:flex; align-items:center; gap:0; overflow-x:auto; padding:4px 0 8px; margin:14px 0 }
.pnd-pipe-step { display:flex; flex-direction:column; align-items:center; min-width:80px; text-align:center; flex-shrink:0 }
.pnd-pipe-dot  { width:36px; height:36px; border-radius:50%; display:flex; align-items:center;
                 justify-content:center; font-size:14px; font-weight:700; flex-shrink:0 }
.pnd-pipe-lbl  { font-size:10px; margin-top:5px; color:var(--rmi-muted,#9ca3af); white-space:nowrap; line-height:1.4 }
.pnd-pipe-line { flex:1; height:2px; background:rgba(255,255,255,.12); min-width:20px }

/* Type card mini */
.pnd-type-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:10px; margin:14px 0 }
.pnd-type-item { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08);
                 border-radius:10px; padding:12px 14px }
.pnd-type-ico  { font-size:22px; margin-bottom:6px }
.pnd-type-name { font-weight:600; font-size:13px; color:var(--rmi-text,#e8ecf4); margin-bottom:4px }
.pnd-type-desc { font-size:11px; color:var(--rmi-muted,#9ca3af); line-height:1.5 }

h3 { font-size:15px; font-weight:700; margin:0 0 14px 0; display:flex; align-items:center; gap:8px }
</style>

<div class="pnd-wrap">

  <!-- Header -->
  <div style="margin-bottom:22px;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0 0 5px 0">Panduan HRL Process</h2>
      <div style="color:var(--rmi-muted,#9ca3af);font-size:13px">
        Pengajuan Cuti / Izin / Lembur / Permintaan Karyawan · ERP RMI Enterprise
      </div>
    </div>
    <a href="<?= h(um('tower.php')) ?>" class="btn btn-ghost btn-sm">← Kembali ke Tower</a>
  </div>

  <!-- Tipe Pengajuan -->
  <div class="pnd-section">
    <h3>📋 Tipe Pengajuan yang Tersedia</h3>
    <div class="pnd-type-grid">
      <div class="pnd-type-item">
        <div class="pnd-type-ico">🌴</div>
        <div class="pnd-type-name">Cuti</div>
        <div class="pnd-type-desc">Cuti tahunan, besar, atau sakit. GPS + foto wajib.</div>
      </div>
      <div class="pnd-type-item">
        <div class="pnd-type-ico">📋</div>
        <div class="pnd-type-name">Izin</div>
        <div class="pnd-type-desc">Izin tidak masuk atau keluar lebih awal. GPS + foto wajib.</div>
      </div>
      <div class="pnd-type-item">
        <div class="pnd-type-ico">⏰</div>
        <div class="pnd-type-name">Lembur</div>
        <div class="pnd-type-desc">Lembur kerja — nominal otomatis ke FIN. GPS + foto wajib.</div>
      </div>
      <div class="pnd-type-item">
        <div class="pnd-type-ico">✈️</div>
        <div class="pnd-type-name">Perjadin</div>
        <div class="pnd-type-desc">Form perjalanan dinas saja. Foto & GPS <b>tidak wajib</b>, cukup attachment.</div>
      </div>
      <div class="pnd-type-item">
        <div class="pnd-type-ico">📦</div>
        <div class="pnd-type-name">Permintaan Karyawan</div>
        <div class="pnd-type-desc">Kebutuhan operasional (ATK, peralatan). Otomatis ke FIN.</div>
      </div>
      <div class="pnd-type-item">
        <div class="pnd-type-ico">💰</div>
        <div class="pnd-type-name">Kenaikan Gaji</div>
        <div class="pnd-type-desc">Pengajuan kenaikan gaji. Review HRL + FIN.</div>
      </div>
      <div class="pnd-type-item">
        <div class="pnd-type-ico">👥</div>
        <div class="pnd-type-name">Rekrutmen</div>
        <div class="pnd-type-desc">Permintaan rekrutmen karyawan baru. Otomatis ke FIN.</div>
      </div>
    </div>
    <div class="pnd-info">
      <strong>Kapan butuh FIN?</strong>
      Lembur, Permintaan Karyawan, Kenaikan Gaji, dan Rekrutmen otomatis masuk ke approval FIN setelah HRL approve.
      Selain itu, <b>jika nominal &gt; 0</b> pada tipe apapun, juga otomatis ke FIN.
    </div>
  </div>

  <!-- Alur Persetujuan -->
  <div class="pnd-section">
    <h3>🔄 Alur Persetujuan (Pipeline)</h3>
    <div class="pnd-pipe">
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#475569;color:#fff">📝</div>
        <div class="pnd-pipe-lbl">Draft<br><span style="color:#94a3b8">Dibuat</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#d97706;color:#fff">🚀</div>
        <div class="pnd-pipe-lbl">Submitted<br><span style="color:#fbbf24">Pemohon</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#2563eb;color:#fff">👔</div>
        <div class="pnd-pipe-lbl">Mgr ✓<br><span style="color:#60a5fa">Manager Dept</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#7c3aed;color:#fff">🏢</div>
        <div class="pnd-pipe-lbl">HRL ✓<br><span style="color:#a78bfa">Tim HRL</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#0d9488;color:#fff">💳</div>
        <div class="pnd-pipe-lbl">FIN ✓<br><span style="color:#2dd4bf">Jika perlu</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#059669;color:#fff">✓</div>
        <div class="pnd-pipe-lbl">Selesai<br><span style="color:#34d399">PAID/Done</span></div>
      </div>
    </div>
    <div style="font-size:12px;color:var(--rmi-muted,#9ca3af);line-height:1.8">
      Setiap approval menggunakan <b>TTD Digital</b> (konfirmasi password atau PIN).
      Pemohon bisa memantau status real-time dari Tower.
    </div>
  </div>

  <!-- Cara Buat Pengajuan -->
  <div class="pnd-section">
    <h3>📝 Cara Membuat Pengajuan</h3>

    <div class="pnd-step">
      <div class="pnd-num blue">1</div>
      <div>
        <div class="pnd-title">Buka HRL Process Tower</div>
        <div class="pnd-desc">
          Login ERP → klik menu <b>HRL Process</b> di sidebar, atau akses langsung:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">
            /ERP_RMI_SOFULL/hrl_process/tower.php
          </code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">2</div>
      <div>
        <div class="pnd-title">Pilih Tipe Pengajuan</div>
        <div class="pnd-desc">
          Klik salah satu ikon tipe: 🌴 Cuti / 📋 Izin / ⏰ Lembur / ✈️ Perjadin, dst.
          Tipe yang dipilih menentukan kewajiban GPS, foto, dan alur approval.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">3</div>
      <div>
        <div class="pnd-title">Isi Judul, Periode, dan Keterangan</div>
        <div class="pnd-desc">
          Judul/alasan wajib diisi. Periode (tanggal mulai–selesai) disarankan untuk Cuti dan Izin.
          Isi nominal jika ada nilai uang (otomatis ke gate FIN).
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">4</div>
      <div>
        <div class="pnd-title">Upload Attachment dan Foto Bukti</div>
        <div class="pnd-desc">
          Attachment: PDF, DOC, XLS, JPG, PNG — boleh lebih dari 1 file.<br>
          Foto: selfie atau foto bukti. <b>Perjadin</b> tidak wajib foto, cukup attachment form.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">5</div>
      <div>
        <div class="pnd-title">Ambil GPS (Lokasi)</div>
        <div class="pnd-desc">
          Klik <b>"📍 Ambil GPS"</b> dan izinkan akses lokasi di browser. Koordinat akan terisi otomatis.<br>
          Pastikan GPS HP aktif dan sinyal kuat untuk akurasi terbaik.
          <span style="color:#f87171;font-size:12px;display:block;margin-top:4px">
            ⚠ GPS tidak wajib untuk tipe <b>Perjadin</b>.
          </span>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">6</div>
      <div>
        <div class="pnd-title">Submit atau Simpan Draft</div>
        <div class="pnd-desc">
          <b>Simpan Draft</b> — tersimpan, belum dikirim ke Manager. Bisa dilengkapi dan submit nanti.<br>
          <b>Submit Pengajuan</b> — dikirim langsung ke Manager Dept untuk review. Tidak bisa dibatalkan kecuali ditolak.
        </div>
      </div>
    </div>

    <div class="pnd-alert">
      <strong>Catatan:</strong> Saat submit, sistem memvalidasi minimal 1 file (foto atau attachment),
      GPS, dan foto (kecuali Perjadin). Pastikan semua terisi sebelum klik Submit.
    </div>
  </div>

  <!-- Cara Approve (Manager/HRL/FIN) -->
  <div class="pnd-section">
    <h3>✅ Cara Menyetujui Pengajuan (Manager / HRL / FIN)</h3>

    <div class="pnd-step">
      <div class="pnd-num yellow">1</div>
      <div>
        <div class="pnd-title">Buka Tower → Klik Buka pada pengajuan yang perlu di-review</div>
        <div class="pnd-desc">
          Pengajuan yang perlu review akan muncul di kolom <b>Next PIC</b> dengan nama dept/peran kamu.
          Klik <b>Buka</b> untuk melihat detail.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">2</div>
      <div>
        <div class="pnd-title">Review Detail: Foto, Attachment, GPS, Keterangan</div>
        <div class="pnd-desc">
          Cek foto bukti, lampiran, tanggal, dan alasan pemohon. Pastikan semua sesuai.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">3</div>
      <div>
        <div class="pnd-title">TTD Digital — Konfirmasi Password atau PIN</div>
        <div class="pnd-desc">
          Untuk approve, kamu wajib memasukkan <b>Password akun</b> atau <b>PIN TTD</b>
          (jika sudah diatur di menu PIN TTD). Ini menggantikan tanda tangan fisik.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">4</div>
      <div>
        <div class="pnd-title">Klik Approve atau Tolak</div>
        <div class="pnd-desc">
          <b>Approve</b> → pengajuan lanjut ke tahap berikutnya (HRL atau FIN).<br>
          <b>Tolak</b> → status menjadi <span class="pnd-badge rejected">● Ditolak</span> dan pemohon bisa revisi ulang.
        </div>
      </div>
    </div>

    <div class="pnd-info">
      <strong>PIN TTD:</strong> Kamu bisa mengatur PIN 6 digit via menu <b>PIN TTD</b> di tower.
      PIN memudahkan approval tanpa perlu mengetik password panjang.
    </div>
  </div>

  <!-- Status & Pemantauan -->
  <div class="pnd-section">
    <h3>📊 Status Pengajuan</h3>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
      <span class="pnd-badge draft">● Draft</span>
      <span class="pnd-badge submit">● Submitted</span>
      <span class="pnd-badge" style="background:rgba(59,130,246,.15);color:#60a5fa">● Mgr ✓</span>
      <span class="pnd-badge" style="background:rgba(139,92,246,.15);color:#a78bfa">● HRL ✓</span>
      <span class="pnd-badge" style="background:rgba(20,184,166,.15);color:#2dd4bf">● FIN ✓</span>
      <span class="pnd-badge paid">● Selesai</span>
      <span class="pnd-badge rejected">● Ditolak</span>
    </div>
    <div class="pnd-desc">
      Pantau status real-time di kolom <b>Status</b> dan <b>Next PIC</b> pada tabel Tower.
      Klik <b>Buka</b> untuk melihat detail dan riwayat approval setiap tahap.
    </div>
  </div>

  <!-- FAQ -->
  <div class="pnd-section">
    <h3>❓ Pertanyaan Umum (FAQ)</h3>

    <div class="pnd-faq-q">❓ Tombol Submit tidak bisa diklik atau selalu gagal?</div>
    <div class="pnd-faq-a">
      Pastikan: (1) Judul sudah diisi, (2) minimal 1 file attachment/foto sudah dipilih,
      (3) GPS sudah diambil. Untuk Perjadin, attachment form wajib meski foto/GPS tidak wajib.
    </div>

    <div class="pnd-faq-q">❓ GPS tidak bisa diambil di browser?</div>
    <div class="pnd-faq-a">
      Pastikan browser diberi izin lokasi: <b>Pengaturan Browser → Privasi → Izin Lokasi → Izinkan</b> untuk domain ERP.
      Di HP Android: Pengaturan → Aplikasi → Chrome → Izin → Lokasi → Izinkan.
      Pastikan sinyal GPS kuat (buka area terbuka sebentar).
    </div>

    <div class="pnd-faq-q">❓ Sudah submit tapi lupa lampirkan dokumen?</div>
    <div class="pnd-faq-a">
      Jika pengajuan masih <span class="pnd-badge submit">● Submitted</span> (belum di-approve Manager),
      hubungi Tim HRL untuk membatalkan dan submit ulang.
      Jika sudah di-approve, hubungi HRL untuk prosedur koreksi.
    </div>

    <div class="pnd-faq-q">❓ Pengajuan ditolak — apa yang harus dilakukan?</div>
    <div class="pnd-faq-a">
      Buka detail pengajuan → baca catatan penolakan → perbaiki isi/lampiran →
      klik <b>Submit Ulang</b> (atau buat pengajuan baru jika diperlukan).
    </div>

    <div class="pnd-faq-q">❓ Apa itu PIN TTD dan kenapa harus diset?</div>
    <div class="pnd-faq-a">
      PIN TTD adalah PIN 6 digit sebagai pengganti tanda tangan saat approval.
      Sangat disarankan untuk Manager, HRL, dan FIN agar proses approval lebih cepat.
      Set PIN melalui menu <b>PIN TTD</b> di halaman Tower.
    </div>

    <div class="pnd-faq-q">❓ Pengajuan Lembur otomatis ke FIN — kenapa?</div>
    <div class="pnd-faq-a">
      Tipe Lembur, Permintaan Karyawan, Kenaikan Gaji, dan Rekrutmen selalu membutuhkan
      persetujuan FIN karena berkaitan dengan pengeluaran keuangan perusahaan.
      Selain itu, jika nominal di pengajuan apapun &gt; 0, otomatis juga ke FIN.
    </div>

    <div class="pnd-faq-q">❓ Siapa yang bisa melihat pengajuan saya?</div>
    <div class="pnd-faq-a">
      <b>Kamu sendiri</b> bisa melihat pengajuanmu.<br>
      <b>Manager Dept-mu</b> bisa melihat semua pengajuan di dept yang sama.<br>
      <b>Tim HRL dan FIN</b> bisa melihat semua pengajuan dari semua dept.<br>
      <b>Admin/SYS</b> memiliki akses penuh.
    </div>
  </div>

  <!-- Kontak -->
  <div class="pnd-section accent">
    <h3>🆘 Butuh Bantuan?</h3>
    <div class="pnd-desc" style="line-height:1.9">
      Jika menemui kendala yang tidak tercantum di panduan ini, hubungi <b>Tim HRL</b> melalui:
      <ul style="margin-top:10px;padding-left:22px">
        <li>WhatsApp Group Karyawan</li>
        <li>Langsung ke divisi HRL</li>
      </ul>
      Sertakan: <b>nama lengkap, kode pengajuan (misal: HRL-20260313-00012), tanggal, dan screenshot error</b>.
    </div>
  </div>

  <!-- Back button -->
  <div style="text-align:center;margin:8px 0 28px">
    <a href="<?= h(um('tower.php')) ?>" class="btn btn-rmi btn-sm">← Kembali ke HRL Process Tower</a>
  </div>

</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
