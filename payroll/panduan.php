<?php
declare(strict_types=1);
require_once __DIR__ . '/_inc/bootstrap.php';

rmi_header('Panduan Payroll', 'payroll', [
    'base_project' => $BASE_PROJECT,
    'breadcrumbs'  => [
        ['label' => 'Payroll', 'url' => $BASE_PAYROLL . '/index.php'],
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
.pnd-title { font-weight:600; margin-bottom:5px; font-size:14px; color:var(--rmi-text,#e8ecf4) }
.pnd-desc  { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7 }
.pnd-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600 }
.pnd-badge.draft    { background:rgba(100,116,139,.2); color:#94a3b8 }
.pnd-badge.posted   { background:rgba(16,185,129,.15); color:#34d399 }
.pnd-badge.paid     { background:rgba(20,184,166,.15); color:#2dd4bf }
.pnd-badge.cancelled{ background:rgba(239,68,68,.15);  color:#f87171 }
.pnd-faq-q { font-weight:600; font-size:14px; color:var(--rmi-text,#e8ecf4); margin-bottom:5px; margin-top:16px }
.pnd-faq-q:first-child { margin-top:0 }
.pnd-faq-a { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7; margin-bottom:4px }
.pnd-alert { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2); border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-alert strong { color:#fbbf24 }
.pnd-info  { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2); border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-info strong { color:#60a5fa }
.pnd-pipe { display:flex; align-items:center; overflow-x:auto; padding:4px 0 8px; margin:14px 0 }
.pnd-pipe-step { display:flex; flex-direction:column; align-items:center; min-width:80px; text-align:center; flex-shrink:0 }
.pnd-pipe-dot  { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; flex-shrink:0 }
.pnd-pipe-lbl  { font-size:10px; margin-top:5px; color:var(--rmi-muted,#9ca3af); white-space:nowrap; line-height:1.4 }
.pnd-pipe-line { flex:1; height:2px; background:rgba(255,255,255,.12); min-width:20px }
.pnd-comp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:10px; margin:14px 0 }
.pnd-comp-item { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:10px; padding:12px 14px }
.pnd-comp-name { font-weight:600; font-size:13px; color:var(--rmi-text,#e8ecf4); margin-bottom:4px }
.pnd-comp-desc { font-size:11px; color:var(--rmi-muted,#9ca3af); line-height:1.5 }
h3 { font-size:15px; font-weight:700; margin:0 0 14px 0; display:flex; align-items:center; gap:8px }
</style>

<div style="max-width:780px;margin:0 auto">

  <div style="margin-bottom:22px">
    <h2 style="margin:0 0 4px 0">Panduan Payroll</h2>
    <div style="color:var(--rmi-muted,#9ca3af);font-size:13px">
      Penggajian Karyawan — ERP RMI Enterprise
      &nbsp;·&nbsp;
      <a href="index.php" style="color:#60a5fa">← Kembali ke Dashboard Payroll</a>
    </div>
  </div>

  <!-- Alur Payroll -->
  <div class="pnd-section">
    <h3><?=rmi_icon('refresh')?> Alur Proses Payroll Bulanan</h3>
    <div class="pnd-pipe">
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#475569;color:#fff"><?=rmi_icon('clipboard')?></div>
        <div class="pnd-pipe-lbl">Setup<br><span style="color:#94a3b8">Settings + Matrix</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#2563eb;color:#fff"><?=rmi_icon('zap')?></div>
        <div class="pnd-pipe-lbl">Generate Run<br><span style="color:#60a5fa">DRAFT</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#d97706;color:#fff"><?=rmi_icon('memo')?></div>
        <div class="pnd-pipe-lbl">Review & Edit<br><span style="color:#fbbf24">Koreksi</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#16a34a;color:#fff"><?=rmi_icon('tick')?></div>
        <div class="pnd-pipe-lbl">Post<br><span style="color:#34d399">POSTED</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#0d9488;color:#fff"><?=rmi_icon('money')?></div>
        <div class="pnd-pipe-lbl">Bayar<br><span style="color:#2dd4bf">PAID</span></div>
      </div>
    </div>
    <div style="font-size:12px;color:var(--rmi-muted,#9ca3af);line-height:1.8">
      Payroll hanya bisa diakses oleh <b>FIN Manager</b> atau <b>Admin/SYS</b>.
      Data gaji bersifat rahasia — tidak terlihat oleh dept lain.
    </div>
  </div>

  <!-- Langkah 1: Setup -->
  <div class="pnd-section">
    <h3><?=rmi_icon('gear')?> Langkah 1 — Setup Sebelum Run Payroll</h3>

    <div class="pnd-step">
      <div class="pnd-num purple">A</div>
      <div>
        <div class="pnd-title">Master Golongan Gaji (Salary Matrix)</div>
        <div class="pnd-desc">
          Buka <b>Payroll → Master Golongan Gaji</b> untuk mengatur struktur gaji per tahun, status (TETAP/KONTRAK/PROBATION), dan level (S1, S2, dst.).<br>
          Kolom yang tersedia: Gaji Pokok, Tunj. Jabatan, Tunj. Anak, Transport, Kuota, OP Rate Harian, Work Days Default.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">B</div>
      <div>
        <div class="pnd-title">Payroll Settings per Karyawan</div>
        <div class="pnd-desc">
          Buka <b>Payroll → Payroll Settings</b>. Pastikan setiap karyawan aktif sudah terhubung ke akun login ERP (untuk sinkronisasi absensi).<br>
          Isi override jika komponen gaji karyawan berbeda dari matrix (misal: tunjangan jabatan khusus).
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">C</div>
      <div>
        <div class="pnd-title">Kalender Kerja Payroll</div>
        <div class="pnd-desc">
          Buka <b>Payroll → Kalender Kerja</b> atau <a href="payroll_calendar.php" style="color:#60a5fa">payroll_calendar.php</a>.
          Isi libur nasional, cuti bersama, libur perusahaan, atau libur khusus office. Tanggal aktif otomatis mengurangi hari kerja efektif dan tidak dihitung sebagai OP reguler.
        </div>
      </div>
    </div>

    <div class="pnd-alert">
      <strong>Penting:</strong> Karyawan yang belum terhubung ke akun login ERP tidak boleh dianggap hadir penuh maupun ALPA penuh. Item akan ditandai belum konsisten dan payroll tidak dapat di-Post sampai mapping diperbaiki.
      Pastikan mapping employee ↔ login sudah benar sebelum generate run.
    </div>
  </div>

  <!-- Langkah 2: Generate Run -->
  <div class="pnd-section">
    <h3><?=rmi_icon('zap')?> Langkah 2 — Generate Payroll Run</h3>

    <div class="pnd-info"><strong>Cutoff Payroll RMI:</strong> setiap periode berjalan dari tanggal <b>26 bulan sebelumnya</b> sampai tanggal <b>25 bulan berjalan</b>. Contoh periode Juli 2026 membaca absensi 26 Juni–25 Juli. Tanggal 26–31 Juli otomatis masuk periode Agustus 2026.</div>

    <div class="pnd-step">
      <div class="pnd-num blue">1</div>
      <div>
        <div class="pnd-title">Buka Payroll Dashboard</div>
        <div class="pnd-desc">
          Login ERP → klik menu <b>Payroll</b> di sidebar, atau akses:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/payroll/</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">2</div>
      <div>
        <div class="pnd-title">Isi Form "Buat Payroll Run"</div>
        <div class="pnd-desc">
          <b>Periode:</b> Isi bulan payroll, format <code>YYYY-MM</code>. Label bulan mengikuti periode cutoff 26–25; contoh <code>2026-07</code> mencakup 26 Juni–25 Juli 2026.<br>
          <b>Office Code:</b> Isi untuk filter cabang tertentu (misal: BGR, BDG), kosongkan untuk semua office.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">3</div>
      <div>
        <div class="pnd-title">Klik "Generate Run"</div>
        <div class="pnd-desc">
          Sistem akan membuat payroll run dengan status <span class="pnd-badge draft">● DRAFT</span> secara otomatis —
          mengambil data absensi, menghitung gaji pokok, tunjangan, lembur, dan potongan (kasbon/pinjaman/absent).
        </div>
      </div>
    </div>

    <div class="pnd-info">
      <strong>Sumber Data Otomatis:</strong> Absensi dari modul Absensi, cicilan kasbon/pinjaman dari modul Loans,
      komponen gaji dari Salary Matrix + override Payroll Settings.
    </div>
  </div>

  <!-- Langkah 3: Review & Edit -->
  <div class="pnd-section">
    <h3><?=rmi_icon('memo')?> Langkah 3 — Review & Koreksi</h3>

    <div class="pnd-step">
      <div class="pnd-num yellow">1</div>
      <div>
        <div class="pnd-title">Buka Detail Run</div>
        <div class="pnd-desc">Di tabel "History Payroll Runs", klik <b>Open</b> pada run yang baru dibuat.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">2</div>
      <div>
        <div class="pnd-title">Cek Data Per Karyawan</div>
        <div class="pnd-desc">
          Verifikasi: hari kerja, hari hadir, absen, lembur, tunjangan, dan potongan.
          Klik <b>Edit</b> pada baris karyawan jika ada yang perlu dikoreksi (lembur tambahan, tunjangan khusus, dll.).
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">3</div>
      <div>
        <div class="pnd-title">Export CSV (Opsional)</div>
        <div class="pnd-desc">Klik <b>CSV</b> di tabel run untuk mengunduh data payroll lengkap ke Excel untuk verifikasi offline.</div>
      </div>
    </div>

    <div class="pnd-alert">
      <strong>Catatan:</strong> Selama status masih DRAFT, semua data masih bisa diubah.
      Setelah POST, perubahan tidak bisa dilakukan (hanya Admin/SYS yang bisa buka kunci).
    </div>
  </div>

  <!-- Langkah 4: Post & Paid -->
  <div class="pnd-section">
    <h3><?=rmi_icon('check')?> Langkah 4 — Post & Tandai PAID</h3>

    <div class="pnd-step">
      <div class="pnd-num green">1</div>
      <div>
        <div class="pnd-title">Klik "Post Payroll"</div>
        <div class="pnd-desc">
          Setelah semua data sudah benar dan tanggal terakhir periode payroll sudah selesai, klik <b>Post</b> di halaman detail run.
          Payroll bulan berjalan tidak dapat di-Post lebih awal agar tanggal kerja masa depan tidak dianggap kekurangan absensi.
          Status berubah menjadi <span class="pnd-badge posted">● POSTED</span>.
          Data terkunci — tidak bisa diedit lagi.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">2</div>
      <div>
        <div class="pnd-title">Catat Proses Pembayaran</div>
        <div class="pnd-desc">Setelah transfer dilakukan sesuai Net Pay dan rekening snapshot, isi <b>Tanggal Pembayaran</b>, <b>Metode</b>, dan <b>Nomor Referensi Bank</b>, lalu klik <b>Catat Pembayaran</b>. Sistem membuat rincian pembayaran per karyawan dan memeriksa totalnya.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">3</div>
      <div>
        <div class="pnd-title">Klik "Tandai PAID"</div>
        <div class="pnd-desc">
          Setelah catatan pembayaran lengkap dan total pembayaran sama dengan Total Net, klik <b>Tandai PAID</b>. Status menjadi <span class="pnd-badge paid">● PAID</span>.
          Karyawan dapat mencetak slip gaji melalui menu <b>Slip Gaji</b>.
        </div>
      </div>
    </div>
  </div>

  <!-- Slip Gaji -->
  <div class="pnd-section">
    <h3><?=rmi_icon('receipt')?> Cara Melihat & Cetak Slip Gaji</h3>

    <div class="pnd-step">
      <div class="pnd-num blue">1</div>
      <div>
        <div class="pnd-title">Buka Menu Slip Gaji</div>
        <div class="pnd-desc">
          Login ERP → <b>Payroll → Slip Gaji</b>, atau akses langsung:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/payroll/payslip.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">2</div>
      <div>
        <div class="pnd-title">Pilih Periode</div>
        <div class="pnd-desc">Pilih bulan yang ingin dilihat. Slip gaji tersedia setelah run berstatus <span class="pnd-badge paid">● PAID</span>.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">3</div>
      <div>
        <div class="pnd-title">Cetak atau Download</div>
        <div class="pnd-desc">Klik <b>Cetak</b> untuk membuka versi print-friendly, lalu gunakan <b>Ctrl+P</b> (atau <?=rmi_icon('gear')?>+P di Mac) untuk mencetak atau save PDF.</div>
      </div>
    </div>

    <div class="pnd-info">
      <strong>Komponen Slip Gaji:</strong> Gaji Pokok, Tunjangan (Jabatan, Anak, Transport, Kuota), Lembur,
      Potongan (Absen, Kasbon, Pinjaman, BPJS, PPh21), dan Net Pay.
    </div>
  </div>

  <!-- Komponen Gaji -->
  <div class="pnd-section">
    <h3><?=rmi_icon('money')?> Komponen Gaji yang Dihitung Otomatis</h3>
    <div class="pnd-comp-grid">
      <div class="pnd-comp-item">
        <div class="pnd-comp-name">Gaji Pokok</div>
        <div class="pnd-comp-desc">Dari Salary Matrix sesuai status & level karyawan</div>
      </div>
      <div class="pnd-comp-item">
        <div class="pnd-comp-name">OP Harian</div>
        <div class="pnd-comp-desc">Rate per hari × jumlah hari hadir</div>
      </div>
      <div class="pnd-comp-item">
        <div class="pnd-comp-name">Tunj. Jabatan</div>
        <div class="pnd-comp-desc">Dari matrix atau override settings</div>
      </div>
      <div class="pnd-comp-item">
        <div class="pnd-comp-name">Tunj. Anak</div>
        <div class="pnd-comp-desc">Dari matrix atau override settings</div>
      </div>
      <div class="pnd-comp-item">
        <div class="pnd-comp-name">Transport & Kuota</div>
        <div class="pnd-comp-desc">Dari matrix atau override settings</div>
      </div>
      <div class="pnd-comp-item">
        <div class="pnd-comp-name">Lembur</div>
        <div class="pnd-comp-desc">Rate/jam × jam lembur approved dari HRL; koreksi hanya saat DRAFT</div>
      </div>
      <div class="pnd-comp-item">
        <div class="pnd-comp-name">Pot. Absen</div>
        <div class="pnd-comp-desc">Gaji pokok / hari kerja × hari tidak hadir</div>
      </div>
      <div class="pnd-comp-item">
        <div class="pnd-comp-name">Kasbon & Pinjaman</div>
        <div class="pnd-comp-desc">Cicilan bulan ini dari modul Loans</div>
      </div>
      <div class="pnd-comp-item">
        <div class="pnd-comp-name">BPJS & PPh21</div>
        <div class="pnd-comp-desc">Dihitung dari Payroll Settings; nominal override tetap dapat digunakan</div>
      </div>
    </div>
  </div>

  <!-- Pinjaman / Kasbon -->
  <div class="pnd-section">
    <h3><?=rmi_icon('money')?> Manajemen Pinjaman & Kasbon</h3>

    <div class="pnd-step">
      <div class="pnd-num blue">1</div>
      <div>
        <div class="pnd-title">Buka Menu Pinjaman / Kasbon</div>
        <div class="pnd-desc">
          <b>Payroll → Pinjaman / Kasbon</b>:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/payroll/loans.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">2</div>
      <div>
        <div class="pnd-title">Tambah Pinjaman / Kasbon</div>
        <div class="pnd-desc">
          Isi: karyawan, jenis (KASBON/LOAN), jumlah, tenor (bulan), cicilan per bulan, dan periode mulai.<br>
          Cicilan otomatis dipotong dari payroll setiap bulan selama tenor berlaku.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">3</div>
      <div>
        <div class="pnd-title">Status: ACTIVE → LUNAS</div>
        <div class="pnd-desc">Tandai LUNAS setelah karyawan selesai membayar semua cicilan. Potongan berhenti otomatis saat status berubah ke LUNAS.</div>
      </div>
    </div>
  </div>

  <!-- FAQ -->
  <div class="pnd-section">
    <h3><?=rmi_icon('question')?> Pertanyaan Umum (FAQ)</h3>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Payroll run sudah dibuat tapi data absensi tidak terbaca?</div>
    <div class="pnd-faq-a">
      Pastikan karyawan sudah di-mapping ke akun login ERP di <b>Payroll Settings</b> (kolom "Login User").
      Jika belum, item ditandai belum konsisten. Sistem tidak membuat hadir penuh maupun ALPA otomatis.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Kenapa karyawan tidak muncul di payroll run?</div>
    <div class="pnd-faq-a">
      Cek: (1) Status karyawan di <b>Master Karyawan</b> harus <b>active</b>. (2) Jika run dibuat dengan filter Office Code,
      pastikan office karyawan sesuai.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bagaimana cara mengganti komponen gaji karyawan tertentu?</div>
    <div class="pnd-faq-a">
      Gunakan <b>Payroll Settings</b> untuk override komponen individual (gaji pokok, tunjangan, rate lembur).
      Override di settings lebih diprioritaskan daripada Salary Matrix.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Run sudah POSTED, tapi ada kesalahan — bisa dibatalkan?</div>
    <div class="pnd-faq-a">
      Status POSTED mengunci data. Untuk membatalkan, hubungi <b>Admin/SYS</b> untuk melakukan reversal.
      Buat run baru untuk periode yang sama hanya jika run lama dibatalkan.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Slip gaji tidak muncul untuk karyawan tertentu?</div>
    <div class="pnd-faq-a">
      Slip gaji hanya tersedia jika run sudah berstatus <span class="pnd-badge paid">● PAID</span>
      dan karyawan tersebut ada di run (tidak difilter oleh Office Code).
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bagaimana cara melihat audit log payroll?</div>
    <div class="pnd-faq-a">
      Buka <b>Payroll → Audit Log</b>. Tercatat semua aksi: buat run, edit item, post, paid, dll. beserta waktu dan user yang melakukan.
    </div>
  </div>

  <!-- Kontak -->
  <div class="pnd-section accent">
    <h3><?=rmi_icon('warn')?> Butuh Bantuan?</h3>
    <div class="pnd-desc" style="line-height:1.9">
      Untuk kendala modul Payroll, hubungi <b>Tim FIN / Admin Sistem</b> melalui:
      <ul style="margin-top:10px;padding-left:22px">
        <li>WhatsApp Group Operasional</li>
        <li>Langsung ke divisi Finance / HRL</li>
      </ul>
      Sertakan: <b>nama lengkap, ID run payroll, periode, dan screenshot error</b>.
    </div>
  </div>

  <div style="text-align:center;margin:8px 0 28px">
    <a href="index.php" class="btn btn-rmi btn-sm">← Kembali ke Dashboard Payroll</a>
  </div>

</div>

<?php rmi_footer(); ?>
