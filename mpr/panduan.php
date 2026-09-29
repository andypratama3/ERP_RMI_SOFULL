<?php
if (!function_exists('rmi_icon')) { require_once __DIR__ . '/../_shared/rmi_icons.php'; }
// --- Auth guard ---
$__rmi_guard_dir = __DIR__;
for ($__rmi_guard_i = 0; $__rmi_guard_i < 5; $__rmi_guard_i++) {
    $__rmi_guard_auth = $__rmi_guard_dir . '/master/auth.php';
    if (file_exists($__rmi_guard_auth)) { require_once $__rmi_guard_auth; if (function_exists('require_login')) require_login(); break; }
    $__rmi_guard_parent = dirname($__rmi_guard_dir);
    if ($__rmi_guard_parent === $__rmi_guard_dir) break;
    $__rmi_guard_dir = $__rmi_guard_parent;
}
unset($__rmi_guard_dir,$__rmi_guard_i,$__rmi_guard_auth,$__rmi_guard_parent);
// --- /Auth guard ---

require_once __DIR__ . '/_layout_top.php';
?>

<style>
.pnd-section{background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:14px;padding:20px 24px;margin-bottom:18px}
.pnd-section.accent-teal{border-color:rgba(20,184,166,.3)}
.pnd-section.accent-purple{border-color:rgba(139,92,246,.3)}
.pnd-section.accent-amber{border-color:rgba(245,158,11,.3)}
.pnd-section.accent-green{border-color:rgba(34,197,94,.3)}
.pnd-section.accent-red{border-color:rgba(239,68,68,.3)}
.pnd-step{display:flex;gap:14px;align-items:flex-start;margin-bottom:16px}
.pnd-num{min-width:32px;height:32px;border-radius:50%;color:#fff;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
.pnd-title{font-weight:600;margin-bottom:4px;font-size:15px;color:#e2e8f0}
.pnd-desc{color:var(--rmi-muted,#9ca3af);font-size:13px;line-height:1.7}
.pnd-desc b{color:#e2e8f0}
.pnd-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600}
.pnd-badge.teal{background:rgba(20,184,166,.2);color:#2dd4bf}
.pnd-badge.amber{background:rgba(245,158,11,.2);color:#fbbf24}
.pnd-badge.purple{background:rgba(139,92,246,.2);color:#a78bfa}
.pnd-badge.green{background:rgba(34,197,94,.2);color:#4ade80}
.pnd-badge.red{background:rgba(239,68,68,.2);color:#f87171}
.pnd-badge.blue{background:rgba(59,130,246,.2);color:#60a5fa}
.pnd-faq-q{font-weight:600;margin-bottom:4px;font-size:14px;color:var(--rmi-text,#e8ecf4)}
.pnd-faq-a{color:var(--rmi-muted,#9ca3af);font-size:13px;line-height:1.7;margin-bottom:16px;padding-left:14px;border-left:2px solid rgba(255,255,255,.07)}
.pnd-alert{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:12px 16px;font-size:13px;color:#9ca3af;margin-top:10px}
.pnd-alert.warn{background:rgba(245,158,11,.07);border-color:rgba(245,158,11,.25);color:#fbbf24}
.pnd-alert.info{background:rgba(59,130,246,.07);border-color:rgba(59,130,246,.25);color:#60a5fa}
.pnd-alert strong{color:#e2e8f0}
.pnd-rule-row{display:flex;gap:12px;align-items:flex-start;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
.pnd-rule-row:last-child{border-bottom:none}
.pnd-rule-icon{font-size:18px;flex-shrink:0;width:28px;text-align:center}
.pnd-toc{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px}
.pnd-toc a{display:inline-block;padding:5px 12px;border-radius:8px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#94a3b8;font-size:12px;text-decoration:none;transition:all .15s}
.pnd-toc a:hover{background:rgba(255,255,255,.1);color:#e2e8f0}
.flow-row{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12px;margin:8px 0}
.flow-box{padding:5px 12px;border-radius:6px;font-weight:700;font-size:12px}
.flow-arrow{color:var(--rmi-muted);font-size:14px}
h3{font-size:16px;font-weight:700;margin-bottom:14px;color:#e2e8f0}
code{font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px;color:#a5b4fc}
</style>

<div style="max-width:800px;margin:0 auto">

  <!-- Header -->
  <div style="margin-bottom:20px">
    <h2 style="margin:0 0 4px;font-size:22px"><?=rmi_icon('clipboard')?> Panduan Modul MPR</h2>
    <div style="color:#9ca3af;font-size:13px">
      Marketing &amp; Project · Panduan Lengkap Kunjungan, Pipeline &amp; Plans
      &nbsp;·&nbsp;
      <a href="<?= e(url_mpr('mpr_dashboard.php')) ?>" style="color:#60a5fa">← Kembali ke Dashboard</a>
    </div>
  </div>

  <!-- Daftar Isi -->
  <div class="pnd-toc">
    <a href="#prinsip"><?=rmi_icon('clipboard')?> Prinsip Wajib</a>
    <a href="#alur"><?=rmi_icon('refresh')?> Alur Kerja</a>
    <a href="#kunjungan"><?=rmi_icon('target')?> Catat Kunjungan</a>
    <a href="#pipeline"><?=rmi_icon('target')?> Pipeline Prospek</a>
    <a href="#plans"><?=rmi_icon('clipboard')?> Plans</a>
    <a href="#dashboard"><?=rmi_icon('chart')?> Dashboard</a>
    <a href="#manager"><?=rmi_icon('user')?> Panduan Manager</a>
    <a href="#faq"><?=rmi_icon('question')?> FAQ</a>
  </div>

  <!-- ===== PRINSIP ===== -->
  <div class="pnd-section accent-red" id="prinsip">
    <h3><?=rmi_icon('clipboard')?> Prinsip Wajib — Baca Dulu Sebelum Mulai</h3>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('user')?></div>
      <div>
        <div class="pnd-title">Staff DAN Manager wajib kunjungan</div>
        <div class="pnd-desc">Tidak ada perbedaan kewajiban. Baik Staff maupun Manager <b>sama-sama harus</b> mencatat setiap kunjungan customer. Manager tidak hanya memonitor — Manager juga turun ke lapangan dan catat hasilnya.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('zap')?></div>
      <div>
        <div class="pnd-title">Fokus utama: Customer BARU</div>
        <div class="pnd-desc">Tujuan utama modul MPR adalah <b>menambah customer baru</b> (RS, Klinik, Apotek, dll.) untuk pertumbuhan Rizqullah Mediska Indonesia. Setiap kunjungan ke prospek baru wajib dicatat dengan tipe <span class="pnd-badge teal">Prospek Baru</span>.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('calendar')?></div>
      <div>
        <div class="pnd-title">Catat di hari yang sama</div>
        <div class="pnd-desc">Kunjungan harus dicatat <b>di hari itu juga</b>, bukan keesokan harinya. Sistem akan memperlihatkan siapa yang belum catat kunjungan hari ini.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('refresh')?></div>
      <div>
        <div class="pnd-title">Follow-up wajib ditindaklanjuti</div>
        <div class="pnd-desc">Jika outcome kunjungan adalah <span class="pnd-badge amber">Perlu Follow-Up</span>, wajib diisi tanggal follow-up berikutnya. Dashboard akan menampilkan alert merah jika follow-up sudah jatuh tempo.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('target')?></div>
      <div>
        <div class="pnd-title">Pipeline harus selalu up-to-date</div>
        <div class="pnd-desc">Setiap prospek harus masuk ke <b>mpr_pipeline.php</b> dan stage-nya diupdate setiap kali ada perkembangan. Prospek yang stagnan >14 hari akan muncul di alert.</div>
      </div>
    </div>

    <div class="pnd-alert warn" style="margin-top:12px">
      <strong><?=rmi_icon('warn')?> Target minimum per bulan:</strong> 3 customer baru per cabang. Leaderboard di dashboard memperlihatkan kinerja semua anggota tim secara transparan.
    </div>
  </div>

  <!-- ===== ALUR KERJA ===== -->
  <div class="pnd-section" id="alur">
    <h3><?=rmi_icon('refresh')?> Alur Kerja Harian MPR</h3>

    <div style="margin-bottom:12px">
      <div style="font-size:12px;color:#64748b;margin-bottom:6px;font-weight:600;text-transform:uppercase">Setiap Hari Kerja:</div>
      <div class="flow-row">
        <div class="flow-box" style="background:rgba(20,184,166,.15);color:#2dd4bf;border:1px solid rgba(20,184,166,.3)"><?=rmi_icon('target')?> Kunjungi Customer</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(99,102,241,.15);color:#818cf8;border:1px solid rgba(99,102,241,.3)"><?=rmi_icon('memo')?> Catat Kunjungan</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(139,92,246,.15);color:#a78bfa;border:1px solid rgba(139,92,246,.3)"><?=rmi_icon('target')?> Update Pipeline</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(245,158,11,.15);color:#fbbf24;border:1px solid rgba(245,158,11,.3)"><?=rmi_icon('refresh')?> Jadwalkan Follow-Up</div>
      </div>
    </div>

    <div style="margin-bottom:12px">
      <div style="font-size:12px;color:#64748b;margin-bottom:6px;font-weight:600;text-transform:uppercase">Jika Ada Deal:</div>
      <div class="flow-row">
        <div class="flow-box" style="background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3)"><?=rmi_icon('target')?> Update Stage → WON</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(59,130,246,.15);color:#60a5fa;border:1px solid rgba(59,130,246,.3)"><?=rmi_icon('memo')?> Daftarkan ke Master Customer</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3)"><?=rmi_icon('check')?> Customer Aktif!</div>
      </div>
    </div>

    <div style="margin-bottom:12px">
      <div style="font-size:12px;color:#64748b;margin-bottom:6px;font-weight:600;text-transform:uppercase">Jika Butuh Anggaran:</div>
      <div class="flow-row">
        <div class="flow-box" style="background:rgba(100,116,139,.15);color:#94a3b8;border:1px solid rgba(100,116,139,.3)"><?=rmi_icon('clipboard')?> Buat Plan</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(100,116,139,.15);color:#94a3b8;border:1px solid rgba(100,116,139,.3)"><?=rmi_icon('money')?> Ajukan Budget Request</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)"><?=rmi_icon('check')?> FIN Approve</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3)"><?=rmi_icon('money')?> Dana Cair</div>
      </div>
    </div>
  </div>

  <!-- ===== KUNJUNGAN ===== -->
  <div class="pnd-section accent-teal" id="kunjungan">
    <h3><?=rmi_icon('target')?> Cara Catat Kunjungan <span class="pnd-badge teal">mpr_visits.php</span></h3>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#14b8a6">1</div>
      <div>
        <div class="pnd-title">Buka menu "Kunjungan"</div>
        <div class="pnd-desc">Di navigasi MPR → klik <b><?=rmi_icon('target')?> Kunjungan</b>. Halaman ini menampilkan daftar kunjungan bulan ini dan form catat kunjungan baru di sebelah kiri.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#14b8a6">2</div>
      <div>
        <div class="pnd-title">Isi form kunjungan</div>
        <div class="pnd-desc">
          Field wajib:
          <ul style="margin:6px 0;padding-left:20px">
            <li><b>Tanggal Kunjungan</b> — otomatis hari ini, bisa diubah</li>
            <li><b>Plan yang Dikunjungi</b> — pilih plan yang relevan</li>
            <li><b>Nama Customer / Prospek</b> — nama RS, Klinik, Apotek, dll.</li>
          </ul>
          Field penting lainnya:
          <ul style="margin:6px 0;padding-left:20px">
            <li><b>Tipe Kunjungan</b> — pilih sesuai jenis kunjungan (lihat tabel di bawah)</li>
            <li><b>Nama & Jabatan Kontak</b> — PIC yang ditemui di customer</li>
            <li><b>Kota</b> — kota lokasi kunjungan</li>
            <li><b>Hasil / Kesimpulan</b> — apa yang dibahas dan hasilnya</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#14b8a6">3</div>
      <div>
        <div class="pnd-title">Pilih Tipe Kunjungan yang tepat</div>
        <div class="pnd-desc">
          <table style="width:100%;border-collapse:collapse;margin-top:4px;font-size:12px">
            <tr style="border-bottom:1px solid rgba(255,255,255,.08)">
              <th style="text-align:left;padding:5px 8px;color:#64748b;font-weight:600">Tipe</th>
              <th style="text-align:left;padding:5px 8px;color:#64748b;font-weight:600">Kapan dipakai</th>
            </tr>
            <tr style="border-bottom:1px solid rgba(255,255,255,.05)">
              <td style="padding:5px 8px"><span class="pnd-badge teal"><?=rmi_icon('zap')?> Prospek Baru</span></td>
              <td style="padding:5px 8px;color:#9ca3af">Kunjungan pertama ke RS/Klinik yang belum jadi customer</td>
            </tr>
            <tr style="border-bottom:1px solid rgba(255,255,255,.05)">
              <td style="padding:5px 8px"><span class="pnd-badge amber"><?=rmi_icon('refresh')?> Follow-Up</span></td>
              <td style="padding:5px 8px;color:#9ca3af">Kunjungan lanjutan ke prospek yang sudah pernah dikunjungi</td>
            </tr>
            <tr style="border-bottom:1px solid rgba(255,255,255,.05)">
              <td style="padding:5px 8px"><span class="pnd-badge blue"><?=rmi_icon('chart')?> Presentasi</span></td>
              <td style="padding:5px 8px;color:#9ca3af">Saat melakukan presentasi/demo produk ke prospek</td>
            </tr>
            <tr style="border-bottom:1px solid rgba(255,255,255,.05)">
              <td style="padding:5px 8px"><span class="pnd-badge purple"><?=rmi_icon('users')?> Negosiasi</span></td>
              <td style="padding:5px 8px;color:#9ca3af">Sedang negosiasi harga/kontrak</td>
            </tr>
            <tr style="border-bottom:1px solid rgba(255,255,255,.05)">
              <td style="padding:5px 8px"><span class="pnd-badge green"><?=rmi_icon('check')?> Customer Aktif</span></td>
              <td style="padding:5px 8px;color:#9ca3af">Kunjungan maintenance ke customer yang sudah aktif order</td>
            </tr>
            <tr>
              <td style="padding:5px 8px"><span class="pnd-badge red"><?=rmi_icon('target')?> Closing/Deal</span></td>
              <td style="padding:5px 8px;color:#9ca3af">Kunjungan untuk finalisasi/tanda tangan deal</td>
            </tr>
          </table>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#14b8a6">4</div>
      <div>
        <div class="pnd-title">Pilih Outcome dan jadwalkan Follow-Up</div>
        <div class="pnd-desc">
          Setelah isi hasil kunjungan, pilih outcome:
          <ul style="margin:6px 0;padding-left:20px">
            <li><span class="pnd-badge amber">Perlu Follow-Up</span> → isi tanggal follow-up berikutnya</li>
            <li><span class="pnd-badge green">DEAL WON</span> → segera update pipeline ke stage WON</li>
            <li><span class="pnd-badge teal">Tertarik</span> → jadwalkan presentasi</li>
            <li><span class="pnd-badge red">Tidak Berminat</span> → tidak perlu follow-up, update pipeline ke LOST</li>
          </ul>
          <b>Estimasi nilai deal</b>: isi jika sudah ada gambaran nilai order (contoh: 5000000 untuk Rp 5 juta).
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#14b8a6">5</div>
      <div>
        <div class="pnd-title">Ambil lokasi GPS (opsional tapi disarankan)</div>
        <div class="pnd-desc">Klik tombol <b><?=rmi_icon('target')?> Ambil Lokasi GPS</b> untuk merekam koordinat lokasi kunjungan. Browser akan meminta izin lokasi — klik <b>Izinkan</b>. Koordinat ini menjadi bukti bahwa kunjungan benar-benar dilakukan di lokasi tersebut.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#14b8a6">6</div>
      <div>
        <div class="pnd-title">Klik "Simpan"</div>
        <div class="pnd-desc">Kunjungan tersimpan dan langsung muncul di daftar. Dashboard otomatis terupdate dengan data terbaru.</div>
      </div>
    </div>

    <div class="pnd-alert info">
      <strong><?=rmi_icon('zap')?> Tips:</strong> Kunjungan ke Prospek Baru yang berhasil harus segera juga ditambahkan ke Pipeline (<b>mpr_pipeline.php</b>) agar terlacak progresnya sampai jadi customer aktif.
    </div>
  </div>

  <!-- ===== PIPELINE ===== -->
  <div class="pnd-section accent-purple" id="pipeline">
    <h3><?=rmi_icon('target')?> Cara Kelola Pipeline Prospek <span class="pnd-badge purple">mpr_pipeline.php</span></h3>

    <div class="pnd-desc" style="margin-bottom:14px">
      Pipeline adalah daftar semua RS/Klinik/Apotek yang sedang dalam proses menjadi customer baru. Setiap prospek memiliki <b>stage</b> yang menunjukkan sejauh mana proses akuisisinya.
    </div>

    <div style="margin-bottom:14px">
      <div style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;margin-bottom:6px">Stage Pipeline (dari kiri ke kanan di kanban):</div>
      <div class="flow-row">
        <div class="flow-box" style="background:rgba(100,116,139,.15);color:#94a3b8;font-size:11px"><?=rmi_icon('search')?> Prospek</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(20,184,166,.15);color:#2dd4bf;font-size:11px"><?=rmi_icon('target')?> Kunjungan</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(245,158,11,.15);color:#fbbf24;font-size:11px"><?=rmi_icon('refresh')?> Follow-Up</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(59,130,246,.15);color:#60a5fa;font-size:11px"><?=rmi_icon('chart')?> Presentasi</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(139,92,246,.15);color:#a78bfa;font-size:11px"><?=rmi_icon('users')?> Negosiasi</div>
        <div class="flow-arrow">→</div>
        <div class="flow-box" style="background:rgba(34,197,94,.15);color:#4ade80;font-size:11px"><?=rmi_icon('target')?> WON</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#8b5cf6">1</div>
      <div>
        <div class="pnd-title">Tambah Prospek Baru</div>
        <div class="pnd-desc">Klik <b><?=rmi_icon('target')?> Pipeline</b> di navigasi → isi form di sebelah kiri:
          <ul style="margin:6px 0;padding-left:20px">
            <li><b>Nama RS/Klinik/Customer</b> — nama lengkap</li>
            <li><b>Tipe</b> — RS, Klinik, Apotek, dll.</li>
            <li><b>Kota</b> — lokasi prospek</li>
            <li><b>Stage</b> — mulai dari <span class="pnd-badge purple">Prospek</span></li>
            <li><b>Kontak / PIC</b> — nama dan jabatan orang yang bisa dihubungi</li>
            <li><b>Estimasi nilai deal</b> — perkiraan omset/order per bulan</li>
            <li><b>Target closing</b> — kapan diharapkan bisa closing</li>
            <li><b>Sumber lead</b> — dari mana info prospek ini (Referral, Cold Call, Pameran, dll.)</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#8b5cf6">2</div>
      <div>
        <div class="pnd-title">Update stage setiap ada perkembangan</div>
        <div class="pnd-desc">Klik tombol <b><?=rmi_icon('memo')?></b> pada kartu prospek → ubah stage sesuai kondisi terkini. <b>Wajib diupdate setelah setiap kunjungan atau interaksi</b> dengan prospek tersebut.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#8b5cf6">3</div>
      <div>
        <div class="pnd-title">Jika Deal WON → update ke stage WON</div>
        <div class="pnd-desc">Ubah stage ke <span class="pnd-badge green"><?=rmi_icon('target')?> WON</span>. Setelah itu, segera <b>daftarkan customer ke Master Customers</b> agar bisa mulai order via modul Sales/DO.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#8b5cf6">4</div>
      <div>
        <div class="pnd-title">Jika tidak berhasil → update ke LOST</div>
        <div class="pnd-desc">Ubah stage ke <span class="pnd-badge red"><?=rmi_icon('cross')?> LOST</span> dan isi alasan (misal: harga tidak cocok, sudah pakai kompetitor). Data ini berguna untuk evaluasi strategi ke depan.</div>
      </div>
    </div>

    <div class="pnd-alert warn">
      <strong><?=rmi_icon('target')?> Ingat:</strong> Satu prospek bisa dikunjungi berkali-kali. Setiap kunjungan dicatat di <b>mpr_visits.php</b>, tapi stage pipeline-nya diupdate di <b>mpr_pipeline.php</b>. Keduanya saling melengkapi.
    </div>
  </div>

  <!-- ===== PLANS ===== -->
  <div class="pnd-section" id="plans">
    <h3><?=rmi_icon('clipboard')?> Cara Buat Plan <span class="pnd-badge blue">mpr_plans.php</span></h3>

    <div class="pnd-desc" style="margin-bottom:12px">
      Plan adalah rencana kerja MPR yang berisi tujuan, target, timeline, dan anggaran. Plan wajib disetujui Manager sebelum bisa berjalan.
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#3b82f6">1</div>
      <div>
        <div class="pnd-title">Buat Plan Baru</div>
        <div class="pnd-desc">Klik <b><?=rmi_icon('clipboard')?> Plans</b> → isi form: Judul, Objective, Target, Tanggal Mulai-Selesai, dan Budget (jika ada). Status awal: <span class="pnd-badge blue">DRAFT</span>.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#3b82f6">2</div>
      <div>
        <div class="pnd-title">Submit untuk persetujuan</div>
        <div class="pnd-desc">Klik tombol <b><?=rmi_icon('outbox')?></b> (Submit) → status berubah ke <span class="pnd-badge amber">SUBMITTED</span>. Manager akan mereview dan menyetujui atau menolak.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#3b82f6">3</div>
      <div>
        <div class="pnd-title">Tunggu Approval Manager</div>
        <div class="pnd-desc">
          Manager akan mengubah status ke:
          <ul style="margin:6px 0;padding-left:20px">
            <li><span class="pnd-badge green">APPROVED</span> → Plan disetujui, bisa mulai berjalan</li>
            <li><span class="pnd-badge red">REJECTED</span> → Plan perlu diperbaiki, kembali ke DRAFT</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num" style="background:#3b82f6">4</div>
      <div>
        <div class="pnd-title">Kaitkan kunjungan ke plan yang benar</div>
        <div class="pnd-desc">Saat catat kunjungan, pilih plan yang relevan di dropdown "Plan yang Dikunjungi". Ini menghubungkan data kunjungan dengan plan sehingga progress plan bisa terpantau.</div>
      </div>
    </div>

    <div class="pnd-alert">
      <strong>Export & Import:</strong> Gunakan tombol <b><?=rmi_icon('outbox')?> Export CSV</b> untuk mengunduh daftar plans. Gunakan <b><?=rmi_icon('clipboard')?> Download Template</b> → isi template → <b>Import CSV</b> untuk memasukkan banyak plans sekaligus.
    </div>
  </div>

  <!-- ===== DASHBOARD ===== -->
  <div class="pnd-section accent-green" id="dashboard">
    <h3><?=rmi_icon('chart')?> Membaca Dashboard MPR</h3>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('target')?></div>
      <div>
        <div class="pnd-title">KPI Cards (atas)</div>
        <div class="pnd-desc">Menampilkan ringkasan bulan ini: total kunjungan, kunjungan ke prospek baru, deal won, follow-up overdue, pipeline aktif, dan target customer baru. Gunakan filter Tahun/Bulan untuk melihat periode lain.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('trend')?></div>
      <div>
        <div class="pnd-title">Funnel Pipeline Akuisisi</div>
        <div class="pnd-desc">Menampilkan berapa prospek di setiap stage. Funnel yang sehat: Prospek banyak → sedikit yang WON. Jika conversion rendah, evaluasi presentasi dan harga. Angka ideal: setidaknya 10% prospek menjadi WON.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('target')?></div>
      <div>
        <div class="pnd-title">Leaderboard Staff &amp; Manager</div>
        <div class="pnd-desc">Semua anggota tim ditampilkan dengan data: total kunjungan, prospek baru, deal won, dan kunjungan hari ini. <b>Manager ditandai badge MGR kuning</b> — kewajiban sama dengan staff. Bar progress menunjukkan pencapaian vs target bulanan.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('cross')?></div>
      <div>
        <div class="pnd-title">Follow-Up Overdue (panel merah)</div>
        <div class="pnd-desc">Daftar kunjungan yang jadwal follow-up-nya sudah lewat. Klik <b>Update</b> untuk mengisi hasil follow-up dan jadwal berikutnya. Panel ini harus selalu kosong — jika ada isian, segera tindak lanjut hari itu.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('trend')?></div>
      <div>
        <div class="pnd-title">Tren Chart (6 bulan)</div>
        <div class="pnd-desc">Grafik batang + garis: total kunjungan (biru), prospek baru (ungu), dan deal won (hijau) per bulan. Digunakan Manager untuk melihat tren dan menentukan apakah kinerja tim meningkat atau menurun.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('users')?></div>
      <div>
        <div class="pnd-title">Customer Baru Bulan Ini</div>
        <div class="pnd-desc">Target minimum: <b>3 customer baru per bulan per cabang</b>. Angka ditampilkan besar — hijau jika tercapai, kuning jika belum. Ini adalah KPI utama modul MPR.</div>
      </div>
    </div>
  </div>

  <!-- ===== PANDUAN MANAGER ===== -->
  <div class="pnd-section accent-amber" id="manager">
    <h3><?=rmi_icon('user')?> Panduan Khusus Manager MPR</h3>

    <div class="pnd-desc" style="margin-bottom:14px">
      Manager memiliki semua kewajiban yang sama dengan Staff, <b>ditambah</b> tanggung jawab sebagai berikut:
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('check')?></div>
      <div>
        <div class="pnd-title">Approve Plan Staff</div>
        <div class="pnd-desc">Di halaman Plans → plans dengan status <span class="pnd-badge amber">SUBMITTED</span> menunggu persetujuan. Klik tombol <b><?=rmi_icon('check')?></b> → review catatan → Approve atau Reject. Plans yang di-reject harus diberi catatan alasan yang jelas.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('chart')?></div>
      <div>
        <div class="pnd-title">Monitor Leaderboard setiap hari</div>
        <div class="pnd-desc">Dashboard menampilkan kinerja harian setiap anggota tim. Perhatikan kolom <b>"Hari Ini"</b> — siapa yang belum ada kunjungan hari ini? Berikan coaching langsung.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('target')?></div>
      <div>
        <div class="pnd-title">Set Target Bulanan Tim</div>
        <div class="pnd-desc">Target kunjungan, prospek baru, dan closing per anggota tim bisa diatur via database (tabel <code>mpr_targets</code>). Target ini akan muncul di leaderboard sebagai bar progress. Hubungi IT/SYS untuk mengatur target awal.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('cross')?></div>
      <div>
        <div class="pnd-title">Tindak lanjuti Follow-Up Overdue</div>
        <div class="pnd-desc">Jika ada follow-up overdue milik staff, tanyakan langsung: apakah sudah dikunjungi? Jika sudah, minta diupdate di sistem. Jika belum, jadwalkan segera.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('outbox')?></div>
      <div>
        <div class="pnd-title">Export laporan bulanan</div>
        <div class="pnd-desc">Gunakan tombol <b><?=rmi_icon('outbox')?> Export Kunjungan</b> di Dashboard atau halaman Kunjungan untuk mengunduh data CSV. Data ini bisa dianalisis lebih lanjut di Excel untuk laporan ke manajemen.</div>
      </div>
    </div>

    <div class="pnd-rule-row">
      <div class="pnd-rule-icon"><?=rmi_icon('target')?></div>
      <div>
        <div class="pnd-title">Manager juga wajib kunjungan lapangan</div>
        <div class="pnd-desc">Leaderboard menampilkan data Manager dan Staff dalam satu tabel yang sama. Manager yang tidak mencatat kunjungan akan terlihat di leaderboard dengan angka nol. <b>Manager = role bisnis, bukan hanya role administratif.</b></div>
      </div>
    </div>
  </div>

  <!-- ===== FAQ ===== -->
  <div class="pnd-section" id="faq">
    <h3><?=rmi_icon('question')?> FAQ — Pertanyaan Umum</h3>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Apakah Manager wajib catat kunjungan seperti Staff?</div>
    <div class="pnd-faq-a">Ya, <b>wajib</b>. Leaderboard menampilkan semua anggota tim — Manager dan Staff — dengan metrik yang sama. Manager yang tidak catat kunjungan akan terlihat dengan angka nol di kolom "Kunjungan" dan "Hari Ini".</div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Apa bedanya Kunjungan dan Pipeline?</div>
    <div class="pnd-faq-a">
      <b>Kunjungan</b> = catatan aktivitas harian (siapa dikunjungi, kapan, hasilnya apa). Satu prospek bisa dikunjungi berkali-kali.<br>
      <b>Pipeline</b> = daftar prospek beserta stage prosesnya. Diupdate setiap ada perkembangan dari prospek tersebut.
      <br><br>
      Analogi: Kunjungan = jurnal harian. Pipeline = status deal per customer.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Berapa target kunjungan per hari?</div>
    <div class="pnd-faq-a">Target minimum 3 customer baru per bulan per cabang. Untuk mencapai itu, disarankan setidaknya <b>2 kunjungan per hari kerja</b> per anggota tim, dengan proporsi minimal 50% ke prospek baru. Manajer dapat menyesuaikan target sesuai kapasitas tim dan area coverage.</div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> GPS tidak bisa diambil di HP?</div>
    <div class="pnd-faq-a">Pastikan: (1) GPS HP aktif. (2) Browser (Chrome/Safari) sudah diberi izin lokasi. (3) Buka pengaturan browser → Privacy → Izin Lokasi → aktifkan untuk domain ERP. GPS bersifat opsional tapi sangat disarankan sebagai bukti kunjungan.</div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bagaimana kalau prospek sudah dikunjungi tapi tidak responsif selama 2 minggu?</div>
    <div class="pnd-faq-a">Coba 1-2x follow-up via telepon/WA. Jika tetap tidak ada respons, ubah outcome ke <span class="pnd-badge red">Tidak Berminat</span> dan update stage pipeline ke <b>LOST</b> dengan alasan "Tidak responsif". Fokus ke prospek aktif lainnya.</div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Siapa yang bisa lihat data kunjungan saya?</div>
    <div class="pnd-faq-a">Data kunjungan bisa dilihat oleh: <b>kamu sendiri</b> (semua kunjungan kamu), <b>Manager MPR cabang kamu</b> (semua kunjungan tim), dan <b>Admin/SYS</b> (semua cabang). Leaderboard di dashboard menampilkan ringkasan yang bisa dilihat seluruh tim MPR.</div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bagaimana cara mengubah stage pipeline dari LOST menjadi aktif lagi?</div>
    <div class="pnd-faq-a">Klik tombol <b><?=rmi_icon('memo')?> Update</b> pada kartu prospek → ubah stage ke <b>PROSPEK</b> atau stage yang sesuai → simpan. Misal: prospek yang sebelumnya tidak berminat, kemudian menghubungi kembali — ubah stage-nya ke KUNJUNGAN dan lanjutkan prosesnya.</div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bagaimana cara mengajukan anggaran untuk kegiatan kunjungan?</div>
    <div class="pnd-faq-a">
      1. Buat Plan di <b>mpr_plans.php</b> → submit untuk approval Manager.<br>
      2. Setelah Plan diapprove, kaitkan Budget Request ke plan tersebut.<br>
      3. Budget Request akan dikirim ke FIN Manager untuk disetujui.<br>
      4. Setelah approved, dana bisa dicairkan via modul Ops Daily FIN.
    </div>

    <div class="pnd-faq-q"><?=rmi_icon('question')?> Bisa export data kunjungan untuk laporan?</div>
    <div class="pnd-faq-a">Ya. Di halaman <b>Kunjungan</b> → klik <b><?=rmi_icon('outbox')?> Export CSV</b>. File akan terdownload dengan semua field kunjungan, bisa dibuka di Excel. Filter berdasarkan tipe kunjungan dan bulan sebelum export untuk laporan yang lebih spesifik.</div>
  </div>

  <!-- Kontak -->
  <div class="pnd-section" style="border-color:rgba(59,130,246,.3)">
    <h3><?=rmi_icon('warn')?> Butuh Bantuan?</h3>
    <div class="pnd-desc" style="font-size:13px;line-height:1.8">
      Jika mengalami kendala teknis (error sistem, tidak bisa login, data tidak tersimpan), hubungi:
      <ul style="margin-top:8px;padding-left:20px">
        <li><b>Tim ITC</b> — untuk masalah teknis sistem</li>
        <li><b>Manager MPR</b> — untuk pertanyaan prosedur dan target</li>
      </ul>
      Sertakan: <b>nama, tanggal, dan screenshot error</b> yang muncul.<br><br>
      Untuk pertanyaan tentang aturan/kebijakan kunjungan customer, hubungi <b>manajemen Rizqullah Mediska Indonesia</b>.
    </div>
  </div>

  <div style="text-align:center;margin-top:8px;margin-bottom:24px">
    <a href="<?= e(url_mpr('mpr_dashboard.php')) ?>" class="btn btn-sm btn-outline-light" style="margin-right:8px">← Kembali ke Dashboard MPR</a>
    <a href="<?= e(url_mpr('mpr_visits.php')) ?>" class="btn btn-sm btn-primary"><?=rmi_icon('target')?> Mulai Catat Kunjungan</a>
  </div>

</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
