<?php
declare(strict_types=1);
require_once __DIR__ . '/_inc/bootstrap.php';

$page_title = 'Panduan HRL Dokumen';
require_once __DIR__ . '/_layout_top.php';
?>

<style>
.pnd-wrap    { max-width:780px; margin:0 auto }
.pnd-section { background:var(--rmi-card,#1a2235); border:1px solid var(--rmi-border,rgba(255,255,255,.1)); border-radius:14px; padding:22px 26px; margin-bottom:18px }
.pnd-section.accent { border-color:rgba(59,130,246,.3) }
.pnd-step  { display:flex; gap:14px; align-items:flex-start; margin-bottom:18px }
.pnd-step:last-child { margin-bottom:0 }
.pnd-num   { min-width:32px; height:32px; border-radius:50%; color:#fff; font-weight:700; font-size:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px }
.pnd-num.blue   { background:#2563eb }
.pnd-num.green  { background:#16a34a }
.pnd-num.yellow { background:#d97706 }
.pnd-num.purple { background:#7c3aed }
.pnd-title { font-weight:600; margin-bottom:5px; font-size:14px; color:var(--rmi-text,#e8ecf4) }
.pnd-desc  { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7 }
.pnd-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600 }
.pnd-badge.ack    { background:rgba(16,185,129,.15); color:#34d399 }
.pnd-badge.pending{ background:rgba(245,158,11,.15); color:#fbbf24 }
.pnd-badge.active { background:rgba(59,130,246,.15); color:#60a5fa }
.pnd-faq-q { font-weight:600; font-size:14px; color:var(--rmi-text,#e8ecf4); margin-bottom:5px; margin-top:16px }
.pnd-faq-q:first-child { margin-top:0 }
.pnd-faq-a { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7; margin-bottom:4px }
.pnd-alert { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2); border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-alert strong { color:#fbbf24 }
.pnd-info  { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2); border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-info strong { color:#60a5fa }
.pnd-doc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; margin:14px 0 }
.pnd-doc-item { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:10px; padding:12px 14px }
.pnd-doc-ico  { font-size:22px; margin-bottom:6px }
.pnd-doc-name { font-weight:600; font-size:13px; color:var(--rmi-text,#e8ecf4); margin-bottom:4px }
.pnd-doc-desc { font-size:11px; color:var(--rmi-muted,#9ca3af); line-height:1.5 }
h3 { font-size:15px; font-weight:700; margin:0 0 14px 0; display:flex; align-items:center; gap:8px }
</style>

<div class="pnd-wrap">

  <div style="margin-bottom:22px;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0 0 5px 0">Panduan HRL Dokumen</h2>
      <div style="color:var(--rmi-muted,#9ca3af);font-size:13px">
        Dokumen Kontrak, Surat & Acknowledgement — ERP RMI Enterprise
      </div>
    </div>
    <a href="hrl_docs.php" style="color:#60a5fa;font-size:13px">← Kembali ke Daftar Dokumen</a>
  </div>

  <!-- Jenis Dokumen -->
  <div class="pnd-section">
    <h3><?=rmi_icon('box')?> Jenis Dokumen HR yang Tersedia</h3>
    <div class="pnd-doc-grid">
      <div class="pnd-doc-item">
        <div class="pnd-doc-ico"><?=rmi_icon('memo')?></div>
        <div class="pnd-doc-name">Kontrak Kerja</div>
        <div class="pnd-doc-desc">PKWT, PKWTT, dan kontrak periode tertentu</div>
      </div>
      <div class="pnd-doc-item">
        <div class="pnd-doc-ico"><?=rmi_icon('doc')?></div>
        <div class="pnd-doc-name">Surat Tugas</div>
        <div class="pnd-doc-desc">Penugasan dinas luar, proyek khusus</div>
      </div>
      <div class="pnd-doc-item">
        <div class="pnd-doc-ico"><?=rmi_icon('clipboard')?></div>
        <div class="pnd-doc-name">Surat Keterangan</div>
        <div class="pnd-doc-desc">Keterangan kerja, penghasilan, dll.</div>
      </div>
      <div class="pnd-doc-item">
        <div class="pnd-doc-ico"><?=rmi_icon('warn')?></div>
        <div class="pnd-doc-name">SP (Surat Peringatan)</div>
        <div class="pnd-doc-desc">SP1, SP2, SP3 — perlu TTD karyawan</div>
      </div>
      <div class="pnd-doc-item">
        <div class="pnd-doc-ico"><?=rmi_icon('books')?></div>
        <div class="pnd-doc-name">Sertifikat</div>
        <div class="pnd-doc-desc">Pelatihan, kompetensi, penghargaan</div>
      </div>
      <div class="pnd-doc-item">
        <div class="pnd-doc-ico"><?=rmi_icon('memo')?></div>
        <div class="pnd-doc-name">Lainnya</div>
        <div class="pnd-doc-desc">Memo internal, formulir HR, dsb.</div>
      </div>
    </div>
    <div class="pnd-info">
      <strong>Hak Akses:</strong> Karyawan hanya bisa melihat dokumen yang ditujukan untuk dirinya sendiri.
      Tim HRL dan Admin/SYS bisa melihat dan mengelola semua dokumen.
    </div>
  </div>

  <!-- Cara Akses Dokumen (Karyawan) -->
  <div class="pnd-section">
    <h3><?=rmi_icon('books')?> Cara Mengakses Dokumen Kamu</h3>

    <div class="pnd-step">
      <div class="pnd-num blue">1</div>
      <div>
        <div class="pnd-title">Buka Modul HRL Dokumen</div>
        <div class="pnd-desc">
          Login ERP → klik menu <b>HRL Docs</b> di sidebar, atau akses langsung:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/hrl/hrl_docs.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">2</div>
      <div>
        <div class="pnd-title">Lihat Daftar Dokumen</div>
        <div class="pnd-desc">
          Sistem menampilkan semua dokumen yang ditujukan untuk kamu secara otomatis.
          Kolom yang ditampilkan: Judul, Jenis, Tanggal, Status, dan Aksi.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">3</div>
      <div>
        <div class="pnd-title">Buka & Baca Dokumen</div>
        <div class="pnd-desc">Klik <b>Lihat</b> atau judul dokumen untuk membaca isi dokumen secara lengkap.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">4</div>
      <div>
        <div class="pnd-title">Download Dokumen</div>
        <div class="pnd-desc">
          Klik tombol <b>Download</b> untuk mengunduh salinan dokumen dalam format PDF atau file asli yang diupload Tim HRL.
        </div>
      </div>
    </div>
  </div>

  <!-- Acknowledgement -->
  <div class="pnd-section">
    <h3><?=rmi_icon('memo')?> Acknowledgement (Tanda Terima Digital)</h3>

    <div class="pnd-step">
      <div class="pnd-num green">1</div>
      <div>
        <div class="pnd-title">Dokumen Perlu Acknowledgement</div>
        <div class="pnd-desc">
          Beberapa dokumen (terutama Kontrak Kerja dan Surat Peringatan) memerlukan konfirmasi tanda terima dari kamu.
          Ditandai dengan badge <span class="pnd-badge pending">● Perlu Tanda Terima</span>.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">2</div>
      <div>
        <div class="pnd-title">Buka Dokumen & Klik "Saya Sudah Membaca"</div>
        <div class="pnd-desc">
          Setelah membaca dokumen, klik tombol <b>"Saya Sudah Membaca & Menerima"</b>.
          Sistem akan mencatat waktu dan IP address sebagai bukti acknowledgement.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">3</div>
      <div>
        <div class="pnd-title">Status Berubah ke Acknowledged</div>
        <div class="pnd-desc">
          Setelah di-acknowledge, status berubah menjadi <span class="pnd-badge ack">● Diterima</span>.
          Tim HRL bisa melihat laporan acknowledgement di halaman <b>ACK Report</b>.
        </div>
      </div>
    </div>

    <div class="pnd-alert">
      <strong>Penting:</strong> Acknowledgement bersifat legal — setara dengan tanda tangan tanda terima dokumen.
      Pastikan kamu benar-benar membaca isi dokumen sebelum menekan tombol.
    </div>
  </div>

  <!-- Untuk Tim HRL -->
  <div class="pnd-section">
    <h3><?=rmi_icon('office')?> Untuk Tim HRL — Upload & Kelola Dokumen</h3>

    <div class="pnd-step">
      <div class="pnd-num purple">1</div>
      <div>
        <div class="pnd-title">Upload Dokumen Baru</div>
        <div class="pnd-desc">
          Di halaman <b>HRL Docs</b>, klik <b>+ Upload Dokumen</b>.
          Isi: judul, jenis dokumen, pilih karyawan penerima, tanggal berlaku, dan lampirkan file (PDF, DOC, XLS, JPG, PNG).
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">2</div>
      <div>
        <div class="pnd-title">Aktifkan Wajib Acknowledgement (Opsional)</div>
        <div class="pnd-desc">
          Centang opsi <b>"Perlu Acknowledgement"</b> untuk dokumen yang memerlukan konfirmasi tanda terima dari karyawan.
          Gunakan untuk kontrak kerja, SP, dan dokumen legal lainnya.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">3</div>
      <div>
        <div class="pnd-title">Pantau Status ACK</div>
        <div class="pnd-desc">
          Buka <b>ACK Report</b> untuk melihat dokumen mana yang sudah dan belum di-acknowledge oleh karyawan.
          Bisa difilter per dokumen, per karyawan, atau per divisi.
        </div>
      </div>
    </div>
    <div class="pnd-step">
      <div class="pnd-num purple">4</div>
      <div>
        <div class="pnd-title">Laporan HR</div>
        <div class="pnd-desc">
          Gunakan <b>HR Report Center</b> untuk rekap dokumen, statistik acknowledgement, dan export ke Excel.
        </div>
      </div>
    </div>
  </div>

  <!-- FAQ -->
  <div class="pnd-section">
    <h3><?=rmi_icon('question')?> Pertanyaan Umum (FAQ)</h3>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Dokumen saya tidak muncul di daftar?</div>
    <div class="pnd-faq-a">
      Hubungi Tim HRL — mungkin dokumen belum diterbitkan atau ditujukan ke akun yang berbeda.
      Pastikan kamu login dengan akun ERP yang benar (sesuai NIP/employee code kamu).
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Tidak bisa download dokumen?</div>
    <div class="pnd-faq-a">
      Pastikan koneksi internet stabil. Jika masih gagal, hubungi Tim HRL — mungkin file belum diupload atau terdapat kendala server.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Sudah klik "Saya Sudah Membaca" tapi status masih Pending?</div>
    <div class="pnd-faq-a">
      Refresh halaman dan cek kembali. Jika masih Pending, hubungi Tim HRL dengan menyertakan
      nama dokumen dan waktu saat kamu menekan tombol.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bisa membatalkan acknowledgement yang sudah dilakukan?</div>
    <div class="pnd-faq-a">
      Tidak bisa dibatalkan sendiri — acknowledgement bersifat final. Hubungi Admin/SYS jika ada kekeliruan.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Siapa yang bisa melihat dokumen saya?</div>
    <div class="pnd-faq-a">
      Hanya kamu sendiri dan Tim HRL yang bisa melihat dokumen yang ditujukan padamu.
      Rekan kerja di divisi lain tidak bisa mengakses dokumen pribadimu.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bagaimana cara mengajukan permintaan dokumen (misal: Surat Keterangan Kerja)?</div>
    <div class="pnd-faq-a">
      Hubungi langsung Tim HRL via WhatsApp Group atau datang ke divisi HRL.
      Tim HRL yang akan membuat dan mengupload dokumen ke sistemmu.
    </div>
  </div>

  <!-- Kontak -->
  <div class="pnd-section accent">
    <h3><?=rmi_icon('warn')?> Butuh Bantuan?</h3>
    <div class="pnd-desc" style="line-height:1.9">
      Jika mengalami kendala yang tidak tercantum di panduan ini, hubungi <b>Tim HRL</b> melalui:
      <ul style="margin-top:10px;padding-left:22px">
        <li>WhatsApp Group Karyawan</li>
        <li>Langsung ke divisi HRL</li>
      </ul>
      Sertakan: <b>nama lengkap, judul dokumen, tanggal, dan screenshot error</b>.
    </div>
  </div>

  <div style="text-align:center;margin:8px 0 28px">
    <a href="hrl_docs.php" class="btn btn-rmi btn-sm">← Kembali ke HRL Dokumen</a>
  </div>

</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
