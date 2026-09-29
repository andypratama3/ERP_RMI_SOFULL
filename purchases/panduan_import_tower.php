<?php
/**
 * purchases/panduan_import_tower.php
 * Panduan Import Control Tower — PQP, SCM, FIN, WQS, SYS
 */
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();
require_once __DIR__ . '/../_shared/rmi_layout.php';

$bp = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';
if (!function_exists('h')) { function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

rmi_header('Panduan Import Control Tower', [
    'active'      => 'pqp_import_ct',
    'subtitle'    => 'Import Control Tower — Panduan Lengkap Alur Impor',
    'breadcrumbs' => [
        ['label' => 'Import Control Tower', 'url' => $bp . '/purchases/purchases_import_control_tower.php'],
        'Panduan',
    ],
    'actions' => [
        ['label' => rmi_icon('tower').' Buka Import Tower', 'url' => $bp . '/purchases/purchases_import_control_tower.php', 'class' => 'btn btn-sm btn-rmi'],
    ],
]);
?>

<style>
.pnd-hero{background:linear-gradient(135deg,rgba(245,158,11,.15),rgba(234,88,12,.1));border:1px solid rgba(245,158,11,.35);border-radius:16px;padding:28px 32px;margin-bottom:24px}
.pnd-section{background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:14px;padding:22px 26px;margin-bottom:18px}
.pnd-section.accent-amber{border-color:rgba(245,158,11,.35)}
.pnd-section.accent-blue{border-color:rgba(59,130,246,.35)}
.pnd-section.accent-green{border-color:rgba(34,197,94,.35)}
.pnd-section.accent-red{border-color:rgba(239,68,68,.35)}
.pnd-section.accent-purple{border-color:rgba(139,92,246,.35)}
.pnd-section.accent-teal{border-color:rgba(20,184,166,.35)}
.pnd-step{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px}
.pnd-num{min-width:34px;height:34px;border-radius:50%;color:#fff;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
.pnd-title{font-weight:700;margin-bottom:5px;font-size:15px;color:var(--rmi-text,#e2e8f0)}
.pnd-desc{color:var(--rmi-muted,#9ca3af);font-size:13px;line-height:1.75}
.pnd-desc b{color:var(--rmi-text,#e2e8f0)}
.pnd-tip{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#86efac;margin-top:12px}
.pnd-warning{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#f87171;margin-top:12px}
.pnd-info{background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#93c5fd;margin-top:12px}

/* Kolom status visual */
.it-flow{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0}
.it-stage{display:flex;flex-direction:column;align-items:center;gap:4px;min-width:80px}
.it-stage-box{padding:8px 12px;border-radius:10px;font-size:11px;font-weight:700;text-align:center;border:1px solid}
.it-arrow{color:#475569;font-size:18px;align-self:center;margin:0 2px}

/* Tabel indikator */
.it-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px}
.it-table th{padding:8px 12px;background:rgba(0,0,0,.2);color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.04em;text-align:left;border-bottom:1px solid rgba(255,255,255,.06)}
.it-table td{padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.04);color:var(--rmi-text,#e2e8f0)}
.it-table tr:last-child td{border-bottom:none}
.it-badge{display:inline-block;padding:2px 9px;border-radius:6px;font-size:11px;font-weight:700}

/* Quick links */
.pnd-links{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-top:14px}
.pnd-links a{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#e2e8f0;text-decoration:none;font-size:13px;font-weight:600;transition:.12s}
.pnd-links a:hover{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.4);color:#fbbf24}
</style>

<!-- Hero -->
<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px"><?= rmi_icon('tower') ?></div>
  <div style="font-size:20px;font-weight:800;color:var(--rmi-text,#e2e8f0);margin-bottom:6px">Import Control Tower</div>
  <div style="color:#94a3b8;font-size:14px;line-height:1.7">
    Pusat kendali impor — memantau semua Purchase Order dari tahap produksi hingga barang masuk gudang.
    Satu halaman untuk melihat status PO, forwarding, CEISA/PIB, dan pembayaran.
  </div>
  <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
    <span class="it-badge" style="background:rgba(245,158,11,.2);color:#fbbf24;border:1px solid rgba(245,158,11,.3)">PQP</span>
    <span class="it-badge" style="background:rgba(139,92,246,.2);color:#c4b5fd;border:1px solid rgba(139,92,246,.3)">SCM</span>
    <span class="it-badge" style="background:rgba(34,197,94,.2);color:#86efac;border:1px solid rgba(34,197,94,.3)">FIN</span>
    <span class="it-badge" style="background:rgba(59,130,246,.2);color:#93c5fd;border:1px solid rgba(59,130,246,.3)">WQS</span>
    <span class="it-badge" style="background:rgba(248,113,113,.2);color:#fca5a5;border:1px solid rgba(248,113,113,.3)">BRANCH</span>
  </div>
</div>

<div class="row g-3">

  <!-- Alur Impor -->
  <div class="col-12">
    <div class="pnd-section accent-amber">
      <h5 style="color:#fbbf24;margin-bottom:16px"><?= rmi_icon('clipboard') ?> Alur Impor End-to-End</h5>
      <div class="it-flow">
        <div class="it-stage">
          <div class="it-stage-box" style="background:rgba(59,130,246,.15);border-color:rgba(59,130,246,.3);color:#93c5fd"><?= rmi_icon('memo') ?><br>PR Dibuat</div>
          <div style="font-size:10px;color:#475569">WQS</div>
        </div>
        <span class="it-arrow">→</span>
        <div class="it-stage">
          <div class="it-stage-box" style="background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.3);color:#fbbf24"><?= rmi_icon('cart') ?><br>PO Dibuat</div>
          <div style="font-size:10px;color:#475569">PQP</div>
        </div>
        <span class="it-arrow">→</span>
        <div class="it-stage">
          <div class="it-stage-box" style="background:rgba(139,92,246,.15);border-color:rgba(139,92,246,.3);color:#c4b5fd"><?= rmi_icon('office') ?><br>Produksi</div>
          <div style="font-size:10px;color:#475569">Vendor</div>
        </div>
        <span class="it-arrow">→</span>
        <div class="it-stage">
          <div class="it-stage-box" style="background:rgba(234,88,12,.15);border-color:rgba(234,88,12,.3);color:#fb923c"><?= rmi_icon('outbox') ?><br>ETD/ETA</div>
          <div style="font-size:10px;color:#475569">SCM/FWD</div>
        </div>
        <span class="it-arrow">→</span>
        <div class="it-stage">
          <div class="it-stage-box" style="background:rgba(20,184,166,.15);border-color:rgba(20,184,166,.3);color:#2dd4bf"><?= rmi_icon('office') ?><br>Bea Cukai</div>
          <div style="font-size:10px;color:#475569">CEISA/PIB</div>
        </div>
        <span class="it-arrow">→</span>
        <div class="it-stage">
          <div class="it-stage-box" style="background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.3);color:#86efac"><?= rmi_icon('check') ?><br>GR + WQS</div>
          <div style="font-size:10px;color:#475569">SCM+WQS</div>
        </div>
        <span class="it-arrow">→</span>
        <div class="it-stage">
          <div class="it-stage-box" style="background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.3);color:#fca5a5"><?= rmi_icon('money') ?><br>Bayar AP</div>
          <div style="font-size:10px;color:#475569">FIN</div>
        </div>
      </div>
      <div class="pnd-info">
        <?= rmi_icon('zap') ?> <b>Import Control Tower</b> memantau semua tahap ini dalam satu tabel. Tiap kolom menunjukkan status terkini dari setiap PO.
      </div>
    </div>
  </div>

  <!-- Cara Membaca Kolom -->
  <div class="col-12">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:14px"><?= rmi_icon('chart') ?> Cara Membaca Kolom Import Tower</h5>
      <table class="it-table">
        <thead>
          <tr><th>Kolom</th><th>Artinya</th><th>Siapa yang Update</th><th>Indikator</th></tr>
        </thead>
        <tbody>
          <tr>
            <td><b>PO Code</b></td>
            <td>Nomor Purchase Order</td>
            <td>PQP (otomatis)</td>
            <td>Klik untuk lihat detail PO</td>
          </tr>
          <tr>
            <td><b>Vendor / Manufacture</b></td>
            <td>Nama supplier/pabrik</td>
            <td>Master Manufactures</td>
            <td>—</td>
          </tr>
          <tr>
            <td><b>Status PO</b></td>
            <td>DRAFT / OPEN / IN_PRODUCTION / READY / CLOSED / CANCELLED</td>
            <td>PQP / SCM Manager</td>
            <td>Badge warna</td>
          </tr>
          <tr>
            <td><b>Production Done</b></td>
            <td>Tanggal produksi selesai di pabrik</td>
            <td>SCM / PQP update manual</td>
            <td><?= rmi_icon('check') ?> ada / <?= rmi_icon('cross') ?> kosong</td>
          </tr>
          <tr>
            <td><b>ETD</b></td>
            <td>Estimated Time of Departure — perkiraan kapal berangkat</td>
            <td>SCM / Forwarding</td>
            <td><?= rmi_icon('check') ?> ada / <?= rmi_icon('cross') ?> kosong</td>
          </tr>
          <tr>
            <td><b>ETA</b></td>
            <td>Estimated Time of Arrival — perkiraan tiba di Indonesia</td>
            <td>SCM / Forwarding</td>
            <td><?= rmi_icon('check') ?> ada / <?= rmi_icon('cross') ?> kosong</td>
          </tr>
          <tr>
            <td><b>Arrived</b></td>
            <td>Tanggal barang benar-benar tiba di pelabuhan</td>
            <td>SCM konfirmasi</td>
            <td><?= rmi_icon('check') ?> ada / <?= rmi_icon('cross') ?> kosong</td>
          </tr>
          <tr>
            <td><b>Forwarder</b></td>
            <td>Nama perusahaan freight/forwarding</td>
            <td>PQP / SCM assign</td>
            <td>—</td>
          </tr>
          <tr>
            <td><b>CEISA / PIB</b></td>
            <td>Status bea cukai: nomor PIB, SPPB, billing customs</td>
            <td>SCM / FIN input</td>
            <td><?= rmi_icon('check') ?> lunas / <?= rmi_icon('cross') ?> belum</td>
          </tr>
          <tr>
            <td><b>AP DP / Final</b></td>
            <td>Pembayaran Down Payment dan Pelunasan ke vendor</td>
            <td>FIN input invoice + payment</td>
            <td><?= rmi_icon('check') ?> lunas / <?= rmi_icon('cross') ?> belum</td>
          </tr>
          <tr>
            <td><b>GR / Incoming</b></td>
            <td>Sudah ada Good Receipt + WQS Incoming diproses</td>
            <td>SCM GR + WQS Incoming</td>
            <td><?= rmi_icon('check') ?> ada / <?= rmi_icon('cross') ?> belum</td>
          </tr>
          <tr>
            <td><b>Dokumen</b></td>
            <td>CIPL, Packing List, COA, COO, dll.</td>
            <td>PQP / SCM upload</td>
            <td><?= rmi_icon('check') ?> lengkap / <?= rmi_icon('warn') ?> kurang</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Cara Update Status -->
  <div class="col-lg-6">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:16px"><?= rmi_icon('memo') ?> Cara Update Status Impor (Harian)</h5>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">1</div>
        <div>
          <div class="pnd-title">Buka Import Control Tower</div>
          <div class="pnd-desc">Menu <b>Import Control Tower</b> atau klik langsung dari sidebar SCM/PQP.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">2</div>
        <div>
          <div class="pnd-title">Filter PO yang Aktif</div>
          <div class="pnd-desc">Gunakan filter <b>Office</b> (kantor) dan <b>Status</b> (OPEN/IN_PRODUCTION). Ketik di kolom pencarian untuk cari PO tertentu.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">3</div>
        <div>
          <div class="pnd-title">Klik Nomor PO</div>
          <div class="pnd-desc">Klik <b>PO Code</b> untuk membuka halaman detail PO. Di sana bisa update: ETD, ETA, Arrived, Forwarding, dokumen.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">4</div>
        <div>
          <div class="pnd-title">Update Kolom yang Berubah</div>
          <div class="pnd-desc">
            — <b>Produksi selesai?</b> → isi <em>Production Done Date</em><br>
            — <b>Kapal berangkat?</b> → isi <em>ETD</em><br>
            — <b>Kapal tiba?</b> → isi <em>ETA</em> → lalu <em>Arrived</em><br>
            — <b>PIB keluar?</b> → isi di menu <em>PIB/CEISA</em>
          </div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">5</div>
        <div>
          <div class="pnd-title">Simpan & Refresh Tower</div>
          <div class="pnd-desc">Klik <b>Simpan</b>. Kembali ke Import Tower — status akan terupdate otomatis.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tugas per Dept -->
  <div class="col-lg-6">
    <div class="pnd-section accent-purple">
      <h5 style="color:#c4b5fd;margin-bottom:16px"><?= rmi_icon('target') ?> Tugas per Dept di Import Tower</h5>
      <div style="display:flex;flex-direction:column;gap:12px">
        <div style="padding:12px 14px;background:rgba(245,158,11,.06);border-radius:10px;border:1px solid rgba(245,158,11,.15)">
          <div style="color:#fbbf24;font-weight:700;margin-bottom:4px"><?= rmi_icon('cart') ?> PQP — Purchasing</div>
          <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:1.8">
            <li>Buat PO dari PR WQS</li>
            <li>Assign Forwarder ke PO</li>
            <li>Upload dokumen: CIPL, COA, COO, Packing List</li>
            <li>Monitor status AP Invoice (DP & Final)</li>
          </ul>
        </div>
        <div style="padding:12px 14px;background:rgba(139,92,246,.06);border-radius:10px;border:1px solid rgba(139,92,246,.15)">
          <div style="color:#c4b5fd;font-weight:700;margin-bottom:4px"><?= rmi_icon('outbox') ?> SCM — Supply Chain</div>
          <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:1.8">
            <li>Update ETD / ETA / Arrived dari forwarder</li>
            <li>Input Forwarding Tasks (nomor BL, kontainer)</li>
            <li>Input Good Receipt (GR) saat barang tiba</li>
            <li>Koordinasi WQS untuk proses Incoming</li>
          </ul>
        </div>
        <div style="padding:12px 14px;background:rgba(34,197,94,.06);border-radius:10px;border:1px solid rgba(34,197,94,.15)">
          <div style="color:#86efac;font-weight:700;margin-bottom:4px"><?= rmi_icon('money') ?> FIN — Finance</div>
          <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:1.8">
            <li>Input AP Invoice (Proforma/DP & Final)</li>
            <li>Proses AP Payment ke vendor</li>
            <li>Input PIB/CEISA billing dan payment bea cukai</li>
            <li>Monitor status pembayaran di kolom AP</li>
          </ul>
        </div>
        <div style="padding:12px 14px;background:rgba(59,130,246,.06);border-radius:10px;border:1px solid rgba(59,130,246,.15)">
          <div style="color:#93c5fd;font-weight:700;margin-bottom:4px"><?= rmi_icon('box') ?> WQS — Warehouse</div>
          <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:1.8">
            <li>Proses Incoming dari GR yang dibuat SCM</li>
            <li>Input Lot/Serial/Exp Date saat barang masuk</li>
            <li>Update stok setelah Incoming diposting</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Status PO -->
  <div class="col-12">
    <div class="pnd-section accent-teal">
      <h5 style="color:#2dd4bf;margin-bottom:14px"><?= rmi_icon('refresh') ?> Status PO — Arti & Urutan</h5>
      <table class="it-table">
        <thead>
          <tr><th>Status</th><th>Artinya</th><th>Yang Harus Dilakukan</th></tr>
        </thead>
        <tbody>
          <tr>
            <td><span class="it-badge" style="background:rgba(100,116,139,.2);color:#94a3b8">DRAFT</span></td>
            <td>PO baru dibuat, belum dikonfirmasi</td>
            <td>PQP: review dan ubah ke OPEN</td>
          </tr>
          <tr>
            <td><span class="it-badge" style="background:rgba(59,130,246,.2);color:#93c5fd">OPEN</span></td>
            <td>PO sudah dikirim ke vendor, menunggu produksi</td>
            <td>SCM: monitor jadwal produksi dari vendor</td>
          </tr>
          <tr>
            <td><span class="it-badge" style="background:rgba(245,158,11,.2);color:#fbbf24">IN_PRODUCTION</span></td>
            <td>Vendor sedang memproduksi barang</td>
            <td>SCM: update ETD saat jadwal pengiriman konfirmasi</td>
          </tr>
          <tr>
            <td><span class="it-badge" style="background:rgba(139,92,246,.2);color:#c4b5fd">READY</span></td>
            <td>Barang sudah di pelabuhan asal, siap dikirim</td>
            <td>SCM: update ETA, assign forwarder jika belum</td>
          </tr>
          <tr>
            <td><span class="it-badge" style="background:rgba(34,197,94,.2);color:#86efac">CLOSED</span></td>
            <td>Barang sudah diterima + GR + semua dokumen lengkap</td>
            <td>Tidak ada — PO selesai</td>
          </tr>
          <tr>
            <td><span class="it-badge" style="background:rgba(239,68,68,.2);color:#fca5a5">CANCELLED</span></td>
            <td>PO dibatalkan</td>
            <td>Pastikan AP Invoice dibatalkan juga (jika ada)</td>
          </tr>
        </tbody>
      </table>
      <div class="pnd-warning">
        <?= rmi_icon('cross') ?> <b>PO tidak boleh ditutup (CLOSED) sebelum:</b> GR diinput + WQS Incoming selesai + AP Final sudah dibayar atau ada konfirmasi dari FIN.
      </div>
    </div>
  </div>

  <!-- Tips & FAQ -->
  <div class="col-lg-6">
    <div class="pnd-section">
      <h5 style="color:var(--rmi-text,#e2e8f0);margin-bottom:14px"><?= rmi_icon('zap') ?> Tips Penting Import Tower</h5>
      <div style="display:flex;flex-direction:column;gap:10px">
        <div class="pnd-tip"><?= rmi_icon('check') ?> <b>Filter per Office</b> — Jika kamu di cabang, selalu filter office-mu agar tidak terkecoh dengan PO kantor lain.</div>
        <div class="pnd-tip"><?= rmi_icon('check') ?> <b>Cari PO cepat</b> — Ketik nomor PO, nama vendor, atau nama kantor di kolom pencarian untuk menemukan dengan cepat.</div>
        <div class="pnd-tip"><?= rmi_icon('check') ?> <b>Export ke Excel</b> — Klik tombol <em>Excel/CSV</em> di bawah tabel untuk ekspor laporan bulanan ke FIN/manajemen.</div>
        <div class="pnd-tip"><?= rmi_icon('check') ?> <b>Indikator warna</b> — <?= rmi_icon('check') ?> = data sudah ada. <?= rmi_icon('cross') ?>/kosong = perlu dilengkapi. Lihat kolom yang kosong dan segera update.</div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="pnd-section">
      <h5 style="color:var(--rmi-text,#e2e8f0);margin-bottom:14px"><?= rmi_icon('question') ?> FAQ Import Tower</h5>
      <div style="display:flex;flex-direction:column;gap:12px;font-size:13px">
        <div>
          <div style="color:#fbbf24;font-weight:600">Q: Kenapa kolom ETD/ETA masih kosong padahal barang sudah dikirim?</div>
          <div style="color:#94a3b8;margin-top:4px">A: SCM atau PQP belum update. Minta tim SCM untuk mengisi tanggal ETD/ETA di halaman detail PO.</div>
        </div>
        <div>
          <div style="color:#fbbf24;font-weight:600">Q: AP Status merah padahal sudah bayar?</div>
          <div style="color:#94a3b8;margin-top:4px">A: FIN belum menginput payment di AP Payment. Minta FIN untuk cek dan input transaksi pembayaran.</div>
        </div>
        <div>
          <div style="color:#fbbf24;font-weight:600">Q: GR sudah diinput tapi WQS Incoming masih kosong?</div>
          <div style="color:#94a3b8;margin-top:4px">A: Tim WQS perlu memproses Incoming dari menu WQS Incoming. GR hanya membuat draft — WQS yang mempostingnya ke stok.</div>
        </div>
        <div>
          <div style="color:#fbbf24;font-weight:600">Q: Tidak bisa mengubah status PO?</div>
          <div style="color:#94a3b8;margin-top:4px">A: Perubahan status PO memerlukan akses Manager (PQP/SCM Manager). Staff hanya bisa lihat dan update kolom detail.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick Links -->
  <div class="col-12">
    <div class="pnd-section">
      <h5 style="color:var(--rmi-text,#e2e8f0);margin-bottom:14px"><?= rmi_icon('zap') ?> Akses Cepat terkait Import</h5>
      <div class="pnd-links">
        <a href="<?= h($bp) ?>/purchases/purchases_import_control_tower.php"><?= rmi_icon('tower') ?> Import Control Tower</a>
        <a href="<?= h($bp) ?>/purchases/purchases_po.php"><?= rmi_icon('doc') ?> Purchase Order (PO)</a>
        <a href="<?= h($bp) ?>/purchases/purchases_forwarding_tasks.php"><?= rmi_icon('outbox') ?> Forwarding Tasks</a>
        <a href="<?= h($bp) ?>/purchases/purchases_gr.php"><?= rmi_icon('check') ?> Good Receipt (GR)</a>
        <a href="<?= h($bp) ?>/purchases/purchases_ceisa_pib.php"><?= rmi_icon('clipboard') ?> PIB / CEISA</a>
        <a href="<?= h($bp) ?>/purchases/purchases_invoice_ap.php"><?= rmi_icon('receipt') ?> AP Invoice</a>
        <a href="<?= h($bp) ?>/purchases/purchases_payment_ap.php"><?= rmi_icon('money') ?> AP Payment</a>
        <a href="<?= h($bp) ?>/stock/wqs_incoming.php"><?= rmi_icon('inbox') ?> WQS Incoming</a>
        <a href="<?= h($bp) ?>/purchases/purchases_dashboard.php"><?= rmi_icon('cart') ?> PQP Dashboard</a>
        <a href="<?= h($bp) ?>/dashboards/scm/scm_dashboard.php"><?= rmi_icon('outbox') ?> SCM Dashboard</a>
      </div>
    </div>
  </div>

</div><!-- /row -->

<?php rmi_footer(); ?>
