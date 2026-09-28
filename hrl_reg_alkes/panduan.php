<?php
declare(strict_types=1);
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['HRL.REG_ALKES_VIEW', 'HRL.VIEW', 'PQP.VIEW']);
}
if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Panduan REG Alkes', [
    'active'     => 'hrl_reg_alkes',
    'breadcrumbs' => [
        ['label' => 'REG Alkes', 'url' => $baseProject . '/hrl_reg_alkes/index.php'],
        'Panduan',
    ],
    'actions' => [
        ['label' => '← Dashboard', 'url' => $baseProject . '/hrl_reg_alkes/index.php', 'class' => 'btn-ghost'],
        ['label' => '🗼 Control Tower', 'url' => $baseProject . '/hrl_reg_alkes/reg_alkes_control_tower.php', 'class' => 'btn-soft'],
    ],
]);
?>

<style>
.pnd-wrap    { max-width:820px; margin:0 auto }
.pnd-section { background:var(--rmi-card,#1a2235);
               border:1px solid var(--rmi-border,rgba(255,255,255,.1));
               border-radius:14px; padding:22px 26px; margin-bottom:18px }
.pnd-section.accent  { border-color:rgba(59,130,246,.3) }
.pnd-section.green   { border-color:rgba(5,150,105,.3) }
.pnd-section.warning { border-color:rgba(245,158,11,.3) }

h3 { font-size:15px; font-weight:700; margin:0 0 16px 0; display:flex; align-items:center; gap:8px }

/* ── Step ── */
.pnd-step { display:flex; gap:14px; align-items:flex-start; margin-bottom:16px }
.pnd-step:last-child { margin-bottom:0 }
.pnd-num { min-width:34px; height:34px; border-radius:50%; color:#fff; font-weight:700; font-size:13px;
           display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px }
.pnd-title { font-weight:600; margin-bottom:4px; font-size:14px; color:var(--rmi-text,#e8ecf4) }
.pnd-desc  { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7 }

/* ── 15-step timeline ── */
.pnd-timeline { display:flex; flex-direction:column; gap:0 }
.pnd-tl-item { display:flex; gap:0; position:relative }
.pnd-tl-line { display:flex; flex-direction:column; align-items:center; width:44px; flex-shrink:0 }
.pnd-tl-dot  { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center;
               font-size:12px; font-weight:700; color:#fff; flex-shrink:0; z-index:1 }
.pnd-tl-vline{ flex:1; width:2px; background:rgba(255,255,255,.08); margin:2px auto }
.pnd-tl-content { padding:4px 0 18px 10px; flex:1 }
.pnd-tl-item:last-child .pnd-tl-vline { display:none }
.pnd-tl-item:last-child .pnd-tl-content { padding-bottom:4px }

.pnd-who { display:inline-flex; align-items:center; gap:4px; padding:2px 9px;
           border-radius:20px; font-size:11px; font-weight:600; margin-left:6px }
.pnd-who-pqp   { background:rgba(59,130,246,.15);  color:#60a5fa }
.pnd-who-hrl   { background:rgba(124,58,237,.15);  color:#a78bfa }
.pnd-who-revisi{ background:rgba(245,158,11,.15);  color:#fbbf24 }
.pnd-who-done  { background:rgba(5,150,105,.15);   color:#34d399 }

/* ── Infobox ── */
.pnd-infobox { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2);
               border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-infobox strong { color:#60a5fa }
.pnd-warnbox { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2);
               border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-warnbox strong { color:#fbbf24 }
.pnd-successbox { background:rgba(5,150,105,.08); border:1px solid rgba(5,150,105,.2);
                  border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-successbox strong { color:#34d399 }

/* ── FAQ ── */
.pnd-faq-q { font-weight:600; font-size:14px; color:var(--rmi-text,#e8ecf4); margin-bottom:5px; margin-top:18px }
.pnd-faq-q:first-child { margin-top:0 }
.pnd-faq-a { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7 }

/* ── Role table ── */
.pnd-role-tbl { width:100%; border-collapse:collapse; font-size:13px }
.pnd-role-tbl th { padding:8px 12px; font-size:11px; font-weight:600; color:var(--rmi-muted,#9ca3af);
    text-transform:uppercase; border-bottom:1px solid rgba(255,255,255,.08); text-align:left }
.pnd-role-tbl td { padding:10px 12px; border-bottom:1px solid rgba(255,255,255,.04) }
.pnd-role-tbl tr:last-child td { border-bottom:none }
</style>

<div class="pnd-wrap">

  <!-- Header -->
  <div style="margin-bottom:22px;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0 0 5px 0">Panduan REG Alkes</h2>
      <div style="color:var(--rmi-muted,#9ca3af);font-size:13px">
        Registrasi Alat Kesehatan (NIE/AKL/AKD) — Proses 15 Tahap · ERP RMI Enterprise
      </div>
    </div>
    <a href="<?= h($baseProject) ?>/hrl_reg_alkes/index.php" class="btn btn-ghost btn-sm">← Kembali ke Dashboard</a>
  </div>

  <!-- Apa itu REG Alkes -->
  <div class="pnd-section">
    <h3>📋 Apa itu Registrasi Alkes (NIE)?</h3>
    <div class="pnd-desc" style="margin-bottom:14px">
      <b>Registrasi Alat Kesehatan (Reg Alkes)</b> adalah proses memperoleh izin edar resmi dari Kemenkes RI
      agar suatu alat kesehatan (alkes) boleh dipasarkan dan digunakan di Indonesia.
      Izin ini berupa <b>Nomor Izin Edar (NIE)</b>, yaitu:
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);border-radius:10px;padding:14px">
        <div style="font-weight:700;font-size:13px;color:#60a5fa;margin-bottom:4px">AKL — Alat Kesehatan Luar Negeri</div>
        <div style="font-size:12px;color:var(--rmi-muted,#9ca3af)">Produk impor dari pabrikan luar negeri (principal). Proses lebih panjang — butuh LOA, legalisasi KBRI, dokumen teknis.</div>
      </div>
      <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);border-radius:10px;padding:14px">
        <div style="font-weight:700;font-size:13px;color:#34d399;margin-bottom:4px">AKD — Alat Kesehatan Dalam Negeri</div>
        <div style="font-size:12px;color:var(--rmi-muted,#9ca3af)">Produk buatan dalam negeri. Proses lebih singkat, tidak perlu LOA atau legalisasi KBRI.</div>
      </div>
    </div>
    <div class="pnd-infobox" style="margin-top:14px">
      <strong>Timeline NIE:</strong> Proses standar memakan waktu <b>2–6 bulan</b> tergantung kelengkapan dokumen dan antrian di sistem Regalkes Kemenkes.
      Revisi dapat memperpanjang waktu hingga <b>+1 bulan</b>.
    </div>
  </div>

  <!-- Siapa mengerjakan apa -->
  <div class="pnd-section">
    <h3>👥 Peran & Tanggung Jawab</h3>
    <table class="pnd-role-tbl">
      <thead>
        <tr>
          <th>Departemen</th>
          <th>Peran</th>
          <th>Tugas Utama</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><span class="pnd-who pnd-who-pqp">PQP</span></td>
          <td>Purchasing & Procurement</td>
          <td style="font-size:12px;color:var(--rmi-muted,#9ca3af)">
            Cari principal, negosiasi, minta berkas registrasi dan katalog produk dari pabrikan, lengkapi revisi.
          </td>
        </tr>
        <tr>
          <td><span class="pnd-who pnd-who-hrl">HRL Legal</span></td>
          <td>Regulatory / Legal</td>
          <td style="font-size:12px;color:var(--rmi-muted,#9ca3af)">
            Cek PKS/LOA, legalisasi KBRI, cek kelengkapan berkas, input ke sistem Regalkes (OSS), submit, review NIE.
          </td>
        </tr>
        <tr>
          <td><span class="pnd-who pnd-who-pqp" style="background:rgba(20,184,166,.15);color:#2dd4bf">ITC/Admin</span></td>
          <td>IT & Admin</td>
          <td style="font-size:12px;color:var(--rmi-muted,#9ca3af)">
            Import SKU ke master_products setelah NIE terbit, kelola dossier digital, backup dokumen.
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- 15 Tahap Proses -->
  <div class="pnd-section">
    <h3>🔄 15 Tahap Proses Registrasi NIE</h3>
    <div style="font-size:12px;color:var(--rmi-muted,#9ca3af);margin-bottom:18px">
      Setiap case di Control Tower mengikuti alur ini. Update stage dilakukan oleh PIC masing-masing tahap.
    </div>

    <div class="pnd-timeline">

      <?php
      $steps = [
        [1,  '#3b82f6', 'PQP', 'pqp',
         'Tahap 1 — PQP Cari Principal & Katalog',
         'PQP mencari <b>principal</b> (pabrikan/distributor luar negeri) dan mendapatkan katalog produk alkes yang akan diregistrasi. Pastikan principal memiliki reputasi baik dan produk memenuhi standar teknis.'],
        [2,  '#3b82f6', 'PQP', 'pqp',
         'Tahap 2 — PQP Quotation & Negosiasi (Deal)',
         'PQP melakukan negosiasi harga, MOQ, dan syarat distribusi. Setelah deal, minta <b>Letter of Authorization (LOA)</b> dan <b>Perjanjian Kerjasama (PKS)</b> resmi.'],
        [3,  '#3b82f6', 'PQP', 'pqp',
         'Tahap 3 — PQP Minta PKS + Draft LOA',
         'PQP meminta draft PKS dan LOA dari principal. LOA harus menyatakan <b>RMI sebagai distributor resmi</b> di Indonesia untuk produk tersebut.'],
        [4,  '#7c3aed', 'HRL', 'hrl',
         'Tahap 4 — Legal Cek PKS/LOA + TTD + Legalisasi KBRI',
         'Legal review isi PKS dan LOA — pastikan cakupan produk, wilayah distribusi, dan masa berlaku sesuai. Setelah PKS/LOA ditandatangani kedua pihak, LOA wajib <b>dilegalisasi oleh KBRI</b> di negara pabrikan.'],
        [5,  '#3b82f6', 'PQP', 'pqp',
         'Tahap 5 — PQP Minta Berkas Registrasi ke Pabrikan',
         'PQP meminta dokumen teknis dari principal: <b>Technical File, IFU (Instruction for Use), Certificate (CE/FDA/ISO), Shelf-life data, Sterilization report</b>, dll. Sesuaikan dengan checklist persyaratan Regalkes.'],
        [6,  '#3b82f6', 'PQP', 'pqp',
         'Tahap 6 — PQP Siapkan Dokumen Pendukung',
         'PQP menyiapkan dokumen pendukung dari RMI: <b>IFU Bahasa Indonesia, daftar aksesori, data OEM (jika ada)</b>. Terjemahan IFU harus akurat dan tersertifikasi jika diperlukan.'],
        [7,  '#7c3aed', 'HRL', 'hrl',
         'Tahap 7 — Legal Cek Kelengkapan Berkas',
         'Legal memverifikasi kelengkapan <b>semua dokumen</b> sebelum submit. Gunakan checklist resmi Regalkes Kemenkes. Dokumen kurang = dikembalikan oleh sistem Kemenkes (waste time).'],
        [8,  '#7c3aed', 'HRL', 'hrl',
         'Tahap 8 — Legal PB-UMKU (OSS RBA) + Permohonan Baru Regalkes',
         'Legal membuka permohonan baru di sistem <b>Regalkes Kemenkes (OSS RBA)</b>. Pastikan PB-UMKU aktif dan data perusahaan (RMI) sudah terverifikasi di OSS sebelum submit produk baru.'],
        [9,  '#7c3aed', 'HRL', 'hrl',
         'Tahap 9 — Legal Siapkan Berkas TTD Manajemen + Dokumen RMI',
         'Legal menyiapkan dokumen internal RMI: <b>CDAKB (Cara Distribusi Alat Kesehatan yang Baik), surat pernyataan manajemen, ttd Direktur</b>. Dokumen ini wajib ada untuk permohonan NIE.'],
        [10, '#7c3aed', 'HRL', 'hrl',
         'Tahap 10 — Legal Isi Data di Regalkes + Submit',
         'Legal mengisi seluruh data di form online Regalkes: nama produk, kategori, jenis, kemasan, nomor seri, komposisi, dll. Setelah semua lengkap dan benar, klik <b>Submit Permohonan</b>. Verifikasi akan memakan waktu.'],
        [11, '#f59e0b', 'Revisi', 'revisi',
         'Tahap 11 — Revisi Diminta (Maks 10 Hari, 1 Kali)',
         'Jika Kemenkes meminta revisi dokumen, tim mendapat notifikasi. <b>Revisi hanya diizinkan 1 kali dengan batas waktu 10 hari kerja.</b> Jika terlewat atau revisi gagal, permohonan ditolak dan harus mulai dari awal.'],
        [12, '#f59e0b', 'PQP', 'revisi',
         'Tahap 12 — PQP Lengkapi Berkas Revisi ke Pabrikan',
         'PQP menghubungi principal untuk melengkapi/memperbaiki dokumen yang diminta. Harus cepat — ingat batas 10 hari. Koordinasi dengan HRL Legal agar revisi tepat sasaran.'],
        [13, '#f59e0b', 'HRL', 'revisi',
         'Tahap 13 — Legal Submit Revisi (Kesempatan Terakhir)',
         'Legal mengupload dokumen revisi ke sistem Regalkes. <b>Ini kesempatan terakhir — jika gagal, permohonan otomatis ditolak.</b> Pastikan semua poin revisi sudah terpenuhi sebelum submit.'],
        [14, '#0d9488', 'HRL', 'hrl',
         'Tahap 14 — Legal Review Draft NIE',
         'Kemenkes mengirimkan draft NIE untuk direview. Legal wajib memverifikasi: <b>nama produk, nomor NIE, pabrikan, kelas alkes, masa berlaku</b>. Jika ada kesalahan, segera laporkan ke Kemenkes sebelum NIE dicetak resmi.'],
        [15, '#059669', 'PQP', 'done',
         'Tahap 15 — NIE Terbit (AKL/AKD) + GO LIVE',
         'NIE resmi terbit! Produk <b>boleh dipasarkan dan dipesan (order)</b>. Langkah selanjutnya: upload NIE ke dossier, import SKU ke master_products via modul "Import SKU", dan informasikan ke tim Sales & SCM.'],
      ];
      foreach ($steps as [$num, $color, $who, $whoCls, $title, $desc]):
      ?>
      <div class="pnd-tl-item">
        <div class="pnd-tl-line">
          <div class="pnd-tl-dot" style="background:<?= h($color) ?>"><?= $num ?></div>
          <div class="pnd-tl-vline"></div>
        </div>
        <div class="pnd-tl-content">
          <div class="pnd-title">
            <?= h($title) ?>
            <span class="pnd-who pnd-who-<?= h($whoCls) ?>"><?= h($who) ?></span>
          </div>
          <div class="pnd-desc"><?= $desc ?></div>
        </div>
      </div>
      <?php endforeach; ?>

    </div>

    <div class="pnd-successbox">
      <strong>Setelah NIE terbit:</strong> Buka modul <b>Import SKU → Master Products</b>, upload file aksesoris CSV/XLSX dari pabrikan,
      preview, lalu import. SKU akan tersedia di master_products dan bisa dipesan oleh Sales.
    </div>
  </div>

  <!-- Cara Pakai Control Tower -->
  <div class="pnd-section">
    <h3>🗼 Cara Pakai Control Tower</h3>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#3b82f6">1</div>
      <div>
        <div class="pnd-title">Buka Control Tower</div>
        <div class="pnd-desc">
          Dashboard → klik <b>Control Tower</b>, atau:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">
            /hrl_reg_alkes/reg_alkes_control_tower.php
          </code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#3b82f6">2</div>
      <div>
        <div class="pnd-title">Buat Case Baru</div>
        <div class="pnd-desc">
          Klik <b>"+ Buat Case Baru"</b> → pilih Manufacture (Principal) → isi nama produk →
          upload File PQP (XLSX/CSV katalog produk) dan Catalog (PDF) → klik Simpan.<br>
          Case otomatis masuk <b>Tahap 1</b>.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#3b82f6">3</div>
      <div>
        <div class="pnd-title">Update Stage</div>
        <div class="pnd-desc">
          Buka case → klik <b>"Advance ke Tahap Berikutnya"</b> setelah tahap saat ini selesai.
          Sistem mencatat waktu masuk dan keluar tiap tahap untuk analitik durasi rata-rata.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#7c3aed">4</div>
      <div>
        <div class="pnd-title">Upload Dossier per Case</div>
        <div class="pnd-desc">
          Setiap case punya folder dossier sendiri. Upload dokumen teknis, LOA, PKS, NIE PDF, dll.
          di tab <b>Dossier</b> di halaman case. Dokumen tersimpan aman dan bisa didownload kapan saja.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#059669">5</div>
      <div>
        <div class="pnd-title">Tutup Case Setelah NIE Terbit</div>
        <div class="pnd-desc">
          Setelah NIE terbit dan SKU sudah diimport ke master_products, klik <b>"Tutup Case"</b>.
          Case tetap bisa dibuka kembali jika ada perpanjangan NIE di masa depan.
        </div>
      </div>
    </div>
  </div>

  <!-- Cara Import SKU -->
  <div class="pnd-section">
    <h3>📦 Cara Import SKU ke Master Products</h3>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#3b82f6">1</div>
      <div>
        <div class="pnd-title">Pastikan NIE sudah terbit dan PDF-nya diupload ke dossier</div>
        <div class="pnd-desc">
          Sistem akan memvalidasi: file PDF AKL/AKD dengan nomor NIE yang sesuai harus ada di folder dossier HRL
          sebelum import diizinkan.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#3b82f6">2</div>
      <div>
        <div class="pnd-title">Buka modul Import SKU → pilih Manufacture → isi Nomor NIE</div>
        <div class="pnd-desc">
          Di dropdown, pilih manufacture/principal → isi nomor AKL/AKD (NIE) → klik <b>Load Dossier</b>.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#3b82f6">3</div>
      <div>
        <div class="pnd-title">Upload file XLSX/CSV dari pabrikan → Preview</div>
        <div class="pnd-desc">
          Upload file katalog produk (aksesori) format XLSX atau CSV dari pabrikan.
          Klik <b>Preview</b> untuk melihat daftar SKU yang akan diimport sebelum dieksekusi.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#059669">4</div>
      <div>
        <div class="pnd-title">Pilih mode & klik Import</div>
        <div class="pnd-desc">
          <b>Skip</b> — lewati SKU yang sudah ada (aman, tidak menimpa data).<br>
          <b>Upsert</b> — update SKU yang sudah ada sekaligus insert yang baru.<br>
          Setelah import, sistem melaporkan: Inserted / Updated / Skipped / Errors.
        </div>
      </div>
    </div>

    <div class="pnd-warnbox">
      <strong>Perhatian:</strong> Import ke master_products hanya bisa dilakukan oleh <b>HRL Legal atau Admin</b>.
      Staff PQP biasa tidak punya akses import (hanya bisa upload dossier dan preview).
    </div>
  </div>

  <!-- FAQ -->
  <div class="pnd-section">
    <h3>❓ Pertanyaan Umum (FAQ)</h3>

    <div class="pnd-faq-q">❓ Berapa lama proses NIE dari awal sampai terbit?</div>
    <div class="pnd-faq-a">
      Rata-rata <b>2–6 bulan</b> untuk AKL (impor). AKD biasanya lebih cepat (<b>1–3 bulan</b>).
      Bergantung kelengkapan dokumen, antrian di Kemenkes, dan apakah ada revisi atau tidak.
    </div>

    <div class="pnd-faq-q">❓ LOA itu apa dan kenapa harus dilegalisasi KBRI?</div>
    <div class="pnd-faq-a">
      LOA (Letter of Authorization) adalah surat kuasa dari pabrikan yang menyatakan RMI sebagai distributor resmi di Indonesia.
      <b>KBRI (Kedutaan Besar RI)</b> di negara pabrikan wajib mengesahkan LOA agar dokumen ini sah secara hukum di Indonesia.
      Tanpa legalisasi KBRI, Kemenkes akan menolak permohonan.
    </div>

    <div class="pnd-faq-q">❓ Apa yang terjadi kalau revisi tidak selesai dalam 10 hari?</div>
    <div class="pnd-faq-a">
      Permohonan <b>otomatis ditolak</b> oleh sistem Regalkes Kemenkes. Tim harus mengulang proses dari awal (Tahap 1).
      Oleh karena itu, saat menerima notifikasi revisi, segera koordinasi dengan PQP dan pabrikan tanpa menunda.
    </div>

    <div class="pnd-faq-q">❓ NIE yang sudah terbit berlaku berapa lama?</div>
    <div class="pnd-faq-a">
      NIE berlaku <b>5 tahun</b> sejak tanggal terbit dan wajib diperpanjang sebelum kadaluarsa.
      Gunakan fitur <b>Expiry Check</b> di modul ini untuk memantau NIE yang akan habis dalam 30/90 hari.
    </div>

    <div class="pnd-faq-q">❓ Kenapa SKU tidak bisa diimport meskipun NIE sudah terbit?</div>
    <div class="pnd-faq-a">
      Sistem mensyaratkan <b>file PDF NIE</b> (dengan nomor yang sesuai) sudah diupload ke folder dossier HRL case tersebut.
      Jika PDF belum ada, upload dulu via Control Tower → buka case → tab Dossier → Upload Output AKL/AKD.
    </div>

    <div class="pnd-faq-q">❓ Siapa yang bisa buat dan update case di Control Tower?</div>
    <div class="pnd-faq-a">
      Semua user dengan permission <b>HRL.REG_ALKES_VIEW</b> atau <b>HRL.VIEW</b> atau <b>PQP.VIEW</b> bisa melihat.
      Update stage dan upload dokumen bisa dilakukan oleh PQP dan HRL Legal.
      Import SKU ke master_products hanya untuk HRL Legal / Admin.
    </div>

    <div class="pnd-faq-q">❓ Apakah data case bisa dihapus?</div>
    <div class="pnd-faq-a">
      Case tidak dihapus — hanya bisa <b>ditutup (CLOSED)</b> atau dibuka kembali (OPEN).
      Ini memastikan audit trail dan history lengkap tersimpan selamanya.
    </div>
  </div>

  <!-- Kontak -->
  <div class="pnd-section accent">
    <h3>🆘 Butuh Bantuan?</h3>
    <div class="pnd-desc" style="line-height:1.9">
      Untuk pertanyaan terkait proses registrasi, hubungi <b>Tim HRL Legal</b>.<br>
      Untuk masalah teknis sistem (import gagal, akses, dll.), hubungi <b>ITC</b>.<br><br>
      Sertakan: <b>kode case (misal: CASE#00012), screenshot error, dan langkah yang sudah dilakukan</b>.
    </div>
  </div>

  <!-- Back -->
  <div style="text-align:center;margin:8px 0 28px">
    <a href="<?= h($baseProject) ?>/hrl_reg_alkes/index.php" class="btn btn-rmi btn-sm">← Kembali ke Dashboard REG Alkes</a>
  </div>

</div>

<?php rmi_footer(); ?>
