<?php
/**
 * sales/panduan_control_tower.php
 * Panduan Sales Control Tower — CRM, WQS, SCM, ACT, FIN, SYS, BRANCH
 */
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../_shared/bootstrap.php';
require_login();
require_once __DIR__ . '/../_shared/rmi_layout.php';

$bp = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';
if (!function_exists('h')) { function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

rmi_header('Panduan Sales Control Tower', [
    'active'      => 'crm_control_tower',
    'subtitle'    => 'Sales Control Tower — Panduan Alur DO End-to-End',
    'breadcrumbs' => [
        ['label' => 'Sales Control Tower', 'url' => $bp . '/sales/sales_control_tower.php'],
        'Panduan',
    ],
    'actions' => [
        ['label' => rmi_icon('tower') . ' Buka Sales Tower', 'url' => $bp . '/sales/sales_control_tower.php', 'class' => 'btn btn-sm btn-rmi'],
    ],
]);
?>

<style>
.pnd-hero{background:linear-gradient(135deg,rgba(59,130,246,.15),rgba(139,92,246,.1));border:1px solid rgba(59,130,246,.35);border-radius:16px;padding:28px 32px;margin-bottom:24px}
.pnd-section{background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:14px;padding:22px 26px;margin-bottom:18px}
.pnd-section.accent-blue{border-color:rgba(59,130,246,.35)}
.pnd-section.accent-green{border-color:rgba(34,197,94,.35)}
.pnd-section.accent-amber{border-color:rgba(245,158,11,.35)}
.pnd-section.accent-teal{border-color:rgba(20,184,166,.35)}
.pnd-section.accent-red{border-color:rgba(239,68,68,.35)}
.pnd-section.accent-purple{border-color:rgba(139,92,246,.35)}
.pnd-step{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px}
.pnd-num{min-width:34px;height:34px;border-radius:50%;color:#fff;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
.pnd-title{font-weight:700;margin-bottom:5px;font-size:15px;color:#e2e8f0}
.pnd-desc{color:var(--rmi-muted,#9ca3af);font-size:13px;line-height:1.75}
.pnd-desc b{color:#e2e8f0}
.pnd-tip{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#86efac;margin-top:12px}
.pnd-warning{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#f87171;margin-top:12px}
.pnd-info{background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#93c5fd;margin-top:12px}

/* Flow bar */
.do-flow{display:flex;align-items:stretch;flex-wrap:wrap;gap:2px;margin:16px 0}
.do-stage{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;font-size:12px;font-weight:700;text-align:center;min-width:80px;border:1px solid}
.do-arrow{color:#475569;font-size:20px;align-self:center;padding:0 4px}

/* Status table */
.st-table{width:100%;border-collapse:collapse;font-size:13px}
.st-table th{padding:8px 12px;background:rgba(0,0,0,.2);color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.04em;text-align:left;border-bottom:1px solid rgba(255,255,255,.06)}
.st-table td{padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.04);color:#e2e8f0;vertical-align:top}
.st-table tr:last-child td{border-bottom:none}
.st-badge{display:inline-block;padding:2px 9px;border-radius:6px;font-size:11px;font-weight:700}

/* Chip per dept */
.chip-crm{background:rgba(59,130,246,.2);color:#93c5fd;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700}
.chip-wqs{background:rgba(245,158,11,.2);color:#fbbf24;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700}
.chip-scm{background:rgba(139,92,246,.2);color:#c4b5fd;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700}
.chip-act{background:rgba(251,146,60,.2);color:#fed7aa;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700}
.chip-fin{background:rgba(34,197,94,.2);color:#86efac;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700}
.chip-done{background:rgba(100,116,139,.2);color:#94a3b8;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700}

/* Quick links */
.pnd-links{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-top:14px}
.pnd-links a{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#e2e8f0;text-decoration:none;font-size:13px;font-weight:600;transition:.12s}
.pnd-links a:hover{background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.4);color:#93c5fd}
</style>

<!-- Hero -->
<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px"><?= rmi_icon('tower') ?></div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Sales Control Tower</div>
  <div style="color:#94a3b8;font-size:14px;line-height:1.7">
    Pusat kendali penjualan — memantau semua Delivery Order (DO) dari pembuatan hingga pembayaran.
    Satu tampilan untuk melihat status DO, siapa yang bertugas selanjutnya, dan tracking pengiriman.
  </div>
  <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
    <span class="chip-crm">CRM</span>
    <span class="chip-wqs">WQS</span>
    <span class="chip-scm">SCM</span>
    <span class="chip-act">ACT</span>
    <span class="chip-fin">FIN</span>
    <span style="background:rgba(248,113,113,.2);color:#fca5a5;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700">BRANCH</span>
  </div>
</div>

<div class="row g-3">

  <!-- Alur DO -->
  <div class="col-12">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:16px"><?= rmi_icon('clipboard') ?> Alur DO — CRM hingga Lunas</h5>
      <div class="do-flow">
        <div class="do-stage" style="background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.3);color:#93c5fd">
          <?= rmi_icon('clipboard') ?><br>DO Dibuat<br><small style="font-size:10px;opacity:.7">CRM</small>
        </div>
        <span class="do-arrow">→</span>
        <div class="do-stage" style="background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.3);color:#fbbf24">
          <?= rmi_icon('box') ?><br>WQS Proses<br><small style="font-size:10px;opacity:.7">WQS</small>
        </div>
        <span class="do-arrow">→</span>
        <div class="do-stage" style="background:rgba(139,92,246,.12);border-color:rgba(139,92,246,.3);color:#c4b5fd">
          🚚<br>SCM Kirim<br><small style="font-size:10px;opacity:.7">SCM</small>
        </div>
        <span class="do-arrow">→</span>
        <div class="do-stage" style="background:rgba(251,146,60,.12);border-color:rgba(251,146,60,.3);color:#fed7aa">
          <?= rmi_icon('receipt') ?><br>ACT Invoice<br><small style="font-size:10px;opacity:.7">ACT</small>
        </div>
        <span class="do-arrow">→</span>
        <div class="do-stage" style="background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.3);color:#86efac">
          💸<br>FIN Bayar<br><small style="font-size:10px;opacity:.7">FIN</small>
        </div>
        <span class="do-arrow">→</span>
        <div class="do-stage" style="background:rgba(100,116,139,.12);border-color:rgba(100,116,139,.3);color:#94a3b8">
          <?= rmi_icon('check') ?><br>PAID / Done<br><small style="font-size:10px;opacity:.7">Selesai</small>
        </div>
      </div>
      <div class="pnd-info">
        💡 Di Control Tower, kolom <b>"Next"</b> menunjukkan dept siapa yang bertanggung jawab sekarang dan apa yang harus dilakukan. Pantau kolom ini setiap hari!
      </div>
    </div>
  </div>

  <!-- Status DO -->
  <div class="col-12">
    <div class="pnd-section accent-teal">
      <h5 style="color:#2dd4bf;margin-bottom:14px"><?= rmi_icon('refresh') ?> Status DO — Arti & Tanggung Jawab</h5>
      <table class="st-table">
        <thead>
          <tr><th>Status</th><th>Artinya</th><th>Dept Bertugas</th><th>Langkah Selanjutnya</th></tr>
        </thead>
        <tbody>
          <tr>
            <td><span class="st-badge" style="background:rgba(59,130,246,.2);color:#93c5fd">crm_to_wqs</span></td>
            <td>DO baru dibuat oleh CRM, dikirim ke WQS</td>
            <td><span class="chip-wqs">WQS</span></td>
            <td>WQS mulai proses: cek stok, siapkan barang</td>
          </tr>
          <tr>
            <td><span class="st-badge" style="background:rgba(245,158,11,.2);color:#fbbf24">wqs_processing</span></td>
            <td>WQS sedang mempersiapkan barang (picking)</td>
            <td><span class="chip-wqs">WQS</span></td>
            <td>Upload foto stok before/after → set READY SCM</td>
          </tr>
          <tr>
            <td><span class="st-badge" style="background:rgba(139,92,246,.2);color:#c4b5fd">ready_scm / wqs_done</span></td>
            <td>Barang siap dikirim, menunggu SCM</td>
            <td><span class="chip-scm">SCM</span></td>
            <td>Atur pengiriman (driver/ekspedisi) → set ON DELIVERY</td>
          </tr>
          <tr>
            <td><span class="st-badge" style="background:rgba(20,184,166,.2);color:#2dd4bf">on_delivery</span></td>
            <td>Barang sedang dalam perjalanan ke customer</td>
            <td><span class="chip-scm">SCM</span></td>
            <td>Konfirmasi diterima + bukti tanda terima → DELIVERED</td>
          </tr>
          <tr>
            <td><span class="st-badge" style="background:rgba(251,146,60,.2);color:#fed7aa">delivered / scm_done</span></td>
            <td>Barang sudah diterima customer</td>
            <td><span class="chip-act">ACT</span></td>
            <td>Buat/kirim faktur + faktur pajak → WAIT PAYMENT</td>
          </tr>
          <tr>
            <td><span class="st-badge" style="background:rgba(245,158,11,.2);color:#fbbf24">wait_payment / act_done</span></td>
            <td>Menunggu pembayaran dari customer</td>
            <td><span class="chip-fin">FIN</span></td>
            <td>Konfirmasi pembayaran masuk → set PAID / FIN DONE</td>
          </tr>
          <tr>
            <td><span class="st-badge" style="background:rgba(34,197,94,.2);color:#86efac">paid / fin_done / closed</span></td>
            <td>DO selesai, pembayaran diterima</td>
            <td><span class="chip-done">SELESAI</span></td>
            <td>DO closed — tidak ada lagi yang perlu dilakukan</td>
          </tr>
          <tr>
            <td><span class="st-badge" style="background:rgba(239,68,68,.2);color:#fca5a5">cancelled</span></td>
            <td>DO dibatalkan</td>
            <td><span class="chip-done">—</span></td>
            <td>Cek alasan pembatalan, koordinasi dengan CRM</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Tugas per Dept -->
  <div class="col-lg-6">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:16px"><?= rmi_icon('users') ?> Tugas Harian per Dept di Sales Tower</h5>

      <div style="padding:12px 14px;background:rgba(59,130,246,.06);border-radius:10px;border:1px solid rgba(59,130,246,.15);margin-bottom:10px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <span class="chip-crm">CRM</span>
          <span style="font-size:13px;font-weight:700;color:#e2e8f0">Sales / CRM</span>
        </div>
        <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:1.9">
          <li>Buat DO dari Sales Order customer</li>
          <li>Monitor status DO yang sudah dikirim ke WQS</li>
          <li>Cek apakah DO sudah di-picking WQS</li>
          <li>Koordinasi dengan customer jika ada delay</li>
          <li>Update note/catatan pengiriman jika ada permintaan khusus</li>
        </ul>
      </div>

      <div style="padding:12px 14px;background:rgba(245,158,11,.06);border-radius:10px;border:1px solid rgba(245,158,11,.15);margin-bottom:10px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <span class="chip-wqs">WQS</span>
          <span style="font-size:13px;font-weight:700;color:#e2e8f0">Warehouse</span>
        </div>
        <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:1.9">
          <li>Buka <b>WQS DO Tasks</b> → lihat DO yang perlu diproses</li>
          <li>Lakukan picking: upload foto kartu stok <b>sebelum</b> dan <b>sesudah</b></li>
          <li>Set status ke <em>READY SCM</em> jika barang sudah siap</li>
          <li>Pastikan qty sesuai DO — jika tidak ada stok, hubungi CRM</li>
        </ul>
      </div>

      <div style="padding:12px 14px;background:rgba(139,92,246,.06);border-radius:10px;border:1px solid rgba(139,92,246,.15);margin-bottom:10px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <span class="chip-scm">SCM</span>
          <span style="font-size:13px;font-weight:700;color:#e2e8f0">Supply Chain</span>
        </div>
        <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:1.9">
          <li>Buka <b>SCM Task DO</b> → lihat DO yang siap dikirim</li>
          <li>Atur ekspedisi/driver → set <em>ON DELIVERY</em></li>
          <li>Setelah diterima customer → set <em>DELIVERED</em> + upload bukti terima</li>
          <li>Generate link tracking untuk customer (klik icon WhatsApp)</li>
        </ul>
      </div>

      <div style="padding:12px 14px;background:rgba(251,146,60,.06);border-radius:10px;border:1px solid rgba(251,146,60,.15);margin-bottom:10px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <span class="chip-act">ACT</span>
          <span style="font-size:13px;font-weight:700;color:#e2e8f0">Accounting</span>
        </div>
        <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:1.9">
          <li>Buka <b>ACT Task DO</b> → lihat DO yang DELIVERED</li>
          <li>Buat/kirim Faktur Pajak (Tax Invoice) ke customer</li>
          <li>Set status <em>WAIT PAYMENT</em> setelah faktur terkirim</li>
        </ul>
      </div>

      <div style="padding:12px 14px;background:rgba(34,197,94,.06);border-radius:10px;border:1px solid rgba(34,197,94,.15)">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <span class="chip-fin">FIN</span>
          <span style="font-size:13px;font-weight:700;color:#e2e8f0">Finance</span>
        </div>
        <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:1.9">
          <li>Buka <b>FIN Task DO</b> → lihat DO yang WAIT PAYMENT</li>
          <li>Konfirmasi transfer masuk di rekening</li>
          <li>Set status <em>PAID / FIN DONE</em></li>
          <li>Verifikasi AR outstanding di Finance Dashboard</li>
        </ul>
      </div>
    </div>
  </div>

  <!-- Cara Pakai Control Tower -->
  <div class="col-lg-6">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:16px">🖥️ Cara Pakai Sales Control Tower</h5>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">1</div>
        <div>
          <div class="pnd-title">Filter Sesuai Tugasmu</div>
          <div class="pnd-desc">
            Gunakan filter di atas tabel:<br>
            — <b>Dept:</b> pilih deptmu (CRM/WQS/SCM/ACT/FIN)<br>
            — <b>Status:</b> pilih status yang relevan<br>
            — <b>Tanggal:</b> filter periode DO<br>
            — <b>Office:</b> filter per kantor (BRANCH)
          </div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">2</div>
        <div>
          <div class="pnd-title">Kolom "Next" — Fokus di Sini</div>
          <div class="pnd-desc">
            Kolom <b>Next Action</b> menunjukkan:<br>
            — <b style="color:#fbbf24">WQS</b> = belum diproses WQS<br>
            — <b style="color:#c4b5fd">SCM</b> = menunggu SCM kirim<br>
            — <b style="color:#fed7aa">ACT</b> = menunggu faktur ACT<br>
            — <b style="color:#86efac">FIN</b> = menunggu konfirmasi bayar<br>
            — <b style="color:#94a3b8">DONE</b> = selesai
          </div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">3</div>
        <div>
          <div class="pnd-title">Klik DO Code untuk Detail</div>
          <div class="pnd-desc">Klik nomor DO (misal: <em>DO-BGR-001</em>) untuk melihat detail lengkap, timeline status, dan tombol action yang tersedia.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">4</div>
        <div>
          <div class="pnd-title">Tracking Link untuk Customer</div>
          <div class="pnd-desc">
            Klik ikon <b>🔗 Link</b> atau <b>WhatsApp</b> di baris DO → copy link tracking → kirim ke customer. Customer bisa cek status tanpa perlu login ke ERP.
          </div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">5</div>
        <div>
          <div class="pnd-title">Export Data</div>
          <div class="pnd-desc">Klik <b>Export CSV/Excel</b> di bawah tabel untuk laporan DO. Filter dulu sebelum export agar datanya sesuai kebutuhan.</div>
        </div>
      </div>

      <div class="pnd-tip">💡 <b>Shortcut:</b> Dari dashboard masing-masing dept ada tombol langsung ke Task DO sesuai dept (WQS Task DO, SCM Task DO, FIN Task DO, ACT Task DO) — lebih fokus dari Sales Control Tower.</div>
    </div>

    <!-- Fitur Tracking -->
    <div class="pnd-section accent-purple" style="margin-top:0">
      <h5 style="color:#c4b5fd;margin-bottom:14px">🔗 Fitur Tracking Customer</h5>
      <div style="font-size:13px;color:#94a3b8;line-height:1.8">
        Setiap DO punya <b style="color:#e2e8f0">link tracking publik</b> yang bisa dikirim ke customer:<br><br>
        <b style="color:#e2e8f0">Cara kirim:</b>
        <ol style="padding-left:16px;line-height:2">
          <li>Di Sales Control Tower → cari DO</li>
          <li>Klik ikon <b>WhatsApp</b> di baris DO tersebut</li>
          <li>Link otomatis ter-copy ke pesan WhatsApp</li>
          <li>Kirim ke nomor customer</li>
        </ol>
        <b style="color:#e2e8f0">Customer bisa melihat:</b> Status terkini, tanggal perkiraan, nama pengirim.<br>
        <b style="color:#e2e8f0">Customer tidak perlu:</b> Login ke ERP.
      </div>
    </div>
  </div>

  <!-- Tips & SLA -->
  <div class="col-12">
    <div class="pnd-section accent-amber">
      <h5 style="color:#fbbf24;margin-bottom:14px">⏱️ SLA & Target Waktu per Tahap</h5>
      <table class="st-table">
        <thead>
          <tr><th>Tahap</th><th>Target Waktu</th><th>Dept</th><th>Jika Melebihi</th></tr>
        </thead>
        <tbody>
          <tr>
            <td>DO Dibuat → WQS Proses</td>
            <td><b>H+0 (hari yang sama)</b></td>
            <td><span class="chip-wqs">WQS</span></td>
            <td>Hubungi WQS Manager untuk prioritas</td>
          </tr>
          <tr>
            <td>WQS Picking → Ready SCM</td>
            <td><b>H+1 (maks 1 hari kerja)</b></td>
            <td><span class="chip-wqs">WQS</span></td>
            <td>Cek stok — mungkin perlu reorder</td>
          </tr>
          <tr>
            <td>Ready SCM → On Delivery</td>
            <td><b>H+0 sampai H+1</b></td>
            <td><span class="chip-scm">SCM</span></td>
            <td>Cek ketersediaan driver/ekspedisi</td>
          </tr>
          <tr>
            <td>On Delivery → Delivered</td>
            <td><b>Sesuai jarak (1–5 hari)</b></td>
            <td><span class="chip-scm">SCM</span></td>
            <td>Hubungi ekspedisi untuk update status</td>
          </tr>
          <tr>
            <td>Delivered → Wait Payment</td>
            <td><b>H+1 (faktur harus langsung keluar)</b></td>
            <td><span class="chip-act">ACT</span></td>
            <td>Ingatkan ACT agar faktur segera diterbitkan</td>
          </tr>
          <tr>
            <td>Wait Payment → Paid</td>
            <td><b>Sesuai term pembayaran (Net 7/14/30)</b></td>
            <td><span class="chip-fin">FIN</span></td>
            <td>Follow up ke customer — cek AR overdue</td>
          </tr>
        </tbody>
      </table>
      <div class="pnd-warning">
        🔴 <b>DO yang overdue</b> (melebihi SLA) ditandai merah di Control Tower. Ini prioritas utama yang harus diselesaikan setiap hari sebelum membuat DO baru.
      </div>
    </div>
  </div>

  <!-- FAQ -->
  <div class="col-lg-6">
    <div class="pnd-section">
      <h5 style="color:#e2e8f0;margin-bottom:14px"><?= rmi_icon('question') ?> FAQ Sales Control Tower</h5>
      <div style="display:flex;flex-direction:column;gap:12px;font-size:13px">
        <div>
          <div style="color:#93c5fd;font-weight:600">Q: Status DO tidak berubah padahal sudah diproses?</div>
          <div style="color:#94a3b8;margin-top:4px">A: Refresh halaman (F5). Jika masih sama, cek apakah sudah klik <em>Simpan</em> setelah update status di halaman Task DO.</div>
        </div>
        <div>
          <div style="color:#93c5fd;font-weight:600">Q: DO sudah dikirim tapi customer bilum terima link tracking?</div>
          <div style="color:#94a3b8;margin-top:4px">A: Klik icon WhatsApp/Link di kolom tracking Sales Tower → kirim ulang link ke customer. Pastikan nomor HP customer di master data sudah benar.</div>
        </div>
        <div>
          <div style="color:#93c5fd;font-weight:600">Q: Tidak bisa set status DELIVERED karena tombol tidak muncul?</div>
          <div style="color:#94a3b8;margin-top:4px">A: Pastikan upload bukti foto tanda terima (required). Juga cek role — hanya SCM Manager/Staff yang bisa set DELIVERED.</div>
        </div>
        <div>
          <div style="color:#93c5fd;font-weight:600">Q: DO berstatus crm_to_wqs sudah lebih dari 2 hari?</div>
          <div style="color:#94a3b8;margin-top:4px">A: Hubungi WQS — kemungkinan stok tidak cukup atau DO terlewat. Gunakan filter status untuk temukan DO yang macet.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="pnd-section">
      <h5 style="color:#e2e8f0;margin-bottom:14px"><?= rmi_icon('zap') ?> Akses Cepat Sales Tower</h5>
      <div class="pnd-links">
        <a href="<?= h($bp) ?>/sales/sales_control_tower.php"><?= rmi_icon('tower') ?> Sales Control Tower</a>
        <a href="<?= h($bp) ?>/sales/sales_do.php"><?= rmi_icon('clipboard') ?> Buat DO Baru</a>
        <a href="<?= h($bp) ?>/stock/wqs_do_tasks.php"><?= rmi_icon('zap') ?> WQS Task DO</a>
        <a href="<?= h($bp) ?>/sales/scm_do_tasks.php">🚚 SCM Task DO</a>
        <a href="<?= h($bp) ?>/sales/act_do_tasks.php"><?= rmi_icon('receipt') ?> ACT Task DO</a>
        <a href="<?= h($bp) ?>/sales/fin_do_tasks.php">💸 FIN Task DO</a>
        <a href="<?= h($bp) ?>/sales/tax_invoices.php"><?= rmi_icon('receipt') ?> Tax Invoice</a>
        <a href="<?= h($bp) ?>/sales/sales_dashboard.php">💼 Sales Dashboard</a>
        <a href="<?= h($bp) ?>/stock/wqs_picking.php"><?= rmi_icon('refresh') ?> WQS Picking</a>
        <a href="<?= h($bp) ?>/stock/wqs_stock.php"><?= rmi_icon('chart') ?> Lihat Stok</a>
        <a href="<?= h($bp) ?>/purchases/purchases_import_control_tower.php"><?= rmi_icon('tower') ?> Import Tower</a>
        <a href="<?= h($bp) ?>/kpi/kpi_do_sla.php"><?= rmi_icon('trend') ?> KPI SLA DO</a>
      </div>
    </div>
  </div>

</div><!-- /row -->

<?php rmi_footer(); ?>
