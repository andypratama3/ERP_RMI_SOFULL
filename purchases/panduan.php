<?php
declare(strict_types=1);
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PANDUAN.PURCHASES_VIEW', 'DASHBOARD.PROCUREMENT_VIEW']);
}
require_once __DIR__ . '/../_shared/rmi_layout.php';

rmi_header('Panduan Purchases / Procurement', 'purchases', [
    'breadcrumbs' => [
        ['label' => 'Purchases Dashboard', 'url' => 'purchases_dashboard.php'],
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
.pnd-num.teal   { background:#0d9488 }
.pnd-title { font-weight:600; margin-bottom:5px; font-size:14px; color:var(--rmi-text,#e8ecf4) }
.pnd-desc  { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7 }
.pnd-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600 }
.pnd-badge.draft    { background:rgba(100,116,139,.2); color:#94a3b8 }
.pnd-badge.open     { background:rgba(59,130,246,.15); color:#60a5fa }
.pnd-badge.approved { background:rgba(16,185,129,.15); color:#34d399 }
.pnd-badge.closed   { background:rgba(20,184,166,.15); color:#2dd4bf }
.pnd-badge.paid     { background:rgba(139,92,246,.15); color:#a78bfa }
.pnd-faq-q { font-weight:600; font-size:14px; color:var(--rmi-text,#e8ecf4); margin-bottom:5px; margin-top:16px }
.pnd-faq-q:first-child { margin-top:0 }
.pnd-faq-a { color:var(--rmi-muted,#9ca3af); font-size:13px; line-height:1.7; margin-bottom:4px }
.pnd-alert { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2); border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-alert strong { color:#fbbf24 }
.pnd-info  { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2); border-radius:10px; padding:12px 16px; font-size:12px; color:#a8a29e; margin-top:14px }
.pnd-info strong { color:#60a5fa }
.pnd-pipe { display:flex; align-items:center; overflow-x:auto; padding:4px 0 8px; margin:14px 0 }
.pnd-pipe-step { display:flex; flex-direction:column; align-items:center; min-width:80px; text-align:center; flex-shrink:0 }
.pnd-pipe-dot  { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700 }
.pnd-pipe-lbl  { font-size:10px; margin-top:5px; color:var(--rmi-muted,#9ca3af); white-space:nowrap; line-height:1.4 }
.pnd-pipe-line { flex:1; height:2px; background:rgba(255,255,255,.12); min-width:20px }
h3 { font-size:15px; font-weight:700; margin:0 0 14px 0; display:flex; align-items:center; gap:8px }
</style>

<div style="max-width:780px;margin:0 auto">

  <div style="margin-bottom:22px">
    <h2 style="margin:0 0 4px 0">Panduan Purchases & Procurement</h2>
    <div style="color:var(--rmi-muted,#9ca3af);font-size:13px">
      Pengadaan Barang, Import, AP & Pembayaran — ERP RMI Enterprise
      &nbsp;·&nbsp;
      <a href="purchases_dashboard.php" style="color:#60a5fa">← Kembali ke Dashboard</a>
    </div>
  </div>

  <!-- Pipeline Procurement -->
  <div class="pnd-section">
    <h3>🔄 Alur Procurement End to End</h3>
    <div class="pnd-pipe">
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#475569;color:#fff">📋</div>
        <div class="pnd-pipe-lbl">PR<br><span style="color:#94a3b8">Dari WQS</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#2563eb;color:#fff">📨</div>
        <div class="pnd-pipe-lbl">RFQ<br><span style="color:#60a5fa">Penawaran</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#d97706;color:#fff">📑</div>
        <div class="pnd-pipe-lbl">PO<br><span style="color:#fbbf24">Order</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#7c3aed;color:#fff">🚢</div>
        <div class="pnd-pipe-lbl">GR / Import<br><span style="color:#a78bfa">Penerimaan</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#0d9488;color:#fff">🧾</div>
        <div class="pnd-pipe-lbl">Invoice AP<br><span style="color:#2dd4bf">Vendor Bill</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#16a34a;color:#fff">💳</div>
        <div class="pnd-pipe-lbl">Payment<br><span style="color:#34d399">PAID</span></div>
      </div>
    </div>
    <div style="font-size:12px;color:var(--rmi-muted,#9ca3af);line-height:1.8">
      Dimulai dari <b>PR (Purchase Request)</b> dari WQS, berlanjut ke RFQ → PO → GR → Invoice AP → Payment.
      Untuk barang impor: <b>PIB/CEISA</b> utama di <b>ACT</b> (bea cukai/kepatuhan); Forwarder & Import Control Tower koordinasi <b>SCM/PQP</b>.
    </div>
  </div>

  <!-- RFQ -->
  <div class="pnd-section">
    <h3>📨 Request for Quotation (RFQ)</h3>

    <div class="pnd-step">
      <div class="pnd-num blue">1</div>
      <div>
        <div class="pnd-title">Buka Menu RFQ</div>
        <div class="pnd-desc">
          <b>Purchases → RFQ</b>, atau:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/purchases/pqp_rfq.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">2</div>
      <div>
        <div class="pnd-title">Buat RFQ Baru</div>
        <div class="pnd-desc">
          Isi: vendor/supplier, daftar item yang dimintakan harga, tanggal deadline penawaran, dan catatan ke supplier.
          Bisa dikaitkan dengan PR dari WQS.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">3</div>
      <div>
        <div class="pnd-title">Download & Kirim ke Supplier</div>
        <div class="pnd-desc">
          Klik <b>Download RFQ</b> atau <b>Export</b> untuk mendapatkan dokumen RFQ yang dikirimkan ke supplier via email/WhatsApp.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">4</div>
      <div>
        <div class="pnd-title">Input Penawaran Masuk</div>
        <div class="pnd-desc">Setelah supplier membalas, input harga penawaran ke sistem untuk perbandingan (comparison matrix).</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">5</div>
      <div>
        <div class="pnd-title">Konversi ke PO</div>
        <div class="pnd-desc">Pilih penawaran terbaik → klik <b>Buat PO dari RFQ ini</b>. Data akan terbawa otomatis ke form PO.</div>
      </div>
    </div>
  </div>

  <!-- Purchase Order -->
  <div class="pnd-section">
    <h3>📑 Purchase Order (PO)</h3>

    <div class="pnd-step">
      <div class="pnd-num yellow">1</div>
      <div>
        <div class="pnd-title">Buka Daftar PO</div>
        <div class="pnd-desc">
          <b>Purchases → Purchase Order</b>:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/purchases/purchases_po.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">2</div>
      <div>
        <div class="pnd-title">Buat atau Edit PO</div>
        <div class="pnd-desc">
          Isi vendor, daftar item, harga, jumlah, terms pembayaran, dan tanggal delivery.
          Status awal: <span class="pnd-badge draft">● DRAFT</span>.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">3</div>
      <div>
        <div class="pnd-title">Approve PO</div>
        <div class="pnd-desc">Manager Procurement approve PO. Status berubah ke <span class="pnd-badge approved">● APPROVED</span>. PO siap dikirim ke supplier.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">4</div>
      <div>
        <div class="pnd-title">Cetak & Kirim ke Supplier</div>
        <div class="pnd-desc">Klik <b>Cetak PO</b> untuk mendapatkan dokumen PO resmi ber-nomor yang dikirim ke supplier.</div>
      </div>
    </div>

    <div class="pnd-info">
      <strong>Status PO:</strong> DRAFT → APPROVED → PARTIAL GR (sebagian diterima) → CLOSED (semua diterima).
      PO CLOSED berarti semua barang sudah diterima dan tidak bisa di-GR lagi.
    </div>
  </div>

  <!-- Goods Receipt -->
  <div class="pnd-section">
    <h3>📦 Goods Receipt (GR) — Penerimaan Barang</h3>

    <div class="pnd-step">
      <div class="pnd-num green">1</div>
      <div>
        <div class="pnd-title">Buka Menu GR</div>
        <div class="pnd-desc">
          <b>Purchases → Goods Receipt</b>:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/purchases/purchases_gr.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">2</div>
      <div>
        <div class="pnd-title">Buat GR dari PO</div>
        <div class="pnd-desc">Pilih PO → sistem load item PO otomatis → isi jumlah aktual yang diterima per item.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">3</div>
      <div>
        <div class="pnd-title">Approve GR → Stok Bertambah</div>
        <div class="pnd-desc">
          Setelah GR diapprove, stok di WQS otomatis bertambah.
          Sistem juga mencatat landed cost untuk keperluan COGS.
        </div>
      </div>
    </div>

    <div class="pnd-alert">
      <strong>Partial Delivery:</strong> Bisa buat GR sebagian dari total PO. PO status berubah ke PARTIAL.
      Buat GR lagi saat sisa barang datang.
    </div>
  </div>

  <!-- AP Invoice & Payment -->
  <div class="pnd-section">
    <h3>🧾 Invoice AP & Pembayaran ke Vendor</h3>

    <div class="pnd-step">
      <div class="pnd-num teal">1</div>
      <div>
        <div class="pnd-title">Input Invoice AP dari Vendor</div>
        <div class="pnd-desc">
          <b>Purchases → Invoice AP</b> → klik <b>+ Tambah Invoice</b>.
          Input: nomor invoice vendor, tanggal, nominal, dan kaitkan ke PO/GR terkait.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num teal">2</div>
      <div>
        <div class="pnd-title">3-Way Matching</div>
        <div class="pnd-desc">
          Sistem otomatis memverifikasi kesesuaian Invoice AP vs PO vs GR (harga, jumlah, vendor).
          Invoice yang tidak sesuai akan diberi flag peringatan.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num teal">3</div>
      <div>
        <div class="pnd-title">Approve Invoice → Buat Payment</div>
        <div class="pnd-desc">
          Invoice AP diapprove oleh Manager FIN → buat <b>Payment</b> setelah transfer bank dilakukan.
          Akses: <b>Purchases → AP Payment</b>.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num teal">4</div>
      <div>
        <div class="pnd-title">Rekonsiliasi Bank</div>
        <div class="pnd-desc">
          Import mutasi bank dari <b>Bank Statement Import</b> → cocokkan dengan payment yang sudah dibuat di sistem.
          Lihat status rekonsiliasi di <b>Bank Recon</b>.
        </div>
      </div>
    </div>
  </div>

  <!-- Import Control Tower -->
  <div class="pnd-section">
    <h3>🚢 Import Control Tower (Barang Impor)</h3>

    <div class="pnd-step">
      <div class="pnd-num purple">1</div>
      <div>
        <div class="pnd-title">Buka Import Control Tower</div>
        <div class="pnd-desc">
          <b>Purchases → Import Control Tower</b>:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/purchases/purchases_import_control_tower.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">2</div>
      <div>
        <div class="pnd-title">Pantau Status Shipment</div>
        <div class="pnd-desc">
          Setiap PO impor ditampilkan beserta stage: PO → PIB → Forwarder → Shipping → Arrival → Customs → GR.
          Update stage secara manual sesuai perkembangan di lapangan.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">3</div>
      <div>
        <div class="pnd-title">Input Data PIB (CEISA)</div>
        <div class="pnd-desc">
          Setelah PIB (Pemberitahuan Impor Barang) keluar dari Bea Cukai, input nomor PIB dan detail di menu <b>CEISA / PIB</b>.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num purple">4</div>
      <div>
        <div class="pnd-title">Invoice & Pembayaran Forwarder</div>
        <div class="pnd-desc">
          Kelola biaya forwarder (freight, customs) di menu <b>Forwarder Invoice</b> → <b>Forwarder Payment</b>.
        </div>
      </div>
    </div>
  </div>

  <!-- FAQ -->
  <div class="pnd-section">
    <h3>❓ Pertanyaan Umum (FAQ)</h3>

    <div class="pnd-faq-q">❓ PO sudah dibuat tapi supplier belum terima — perlu dikirim ulang?</div>
    <div class="pnd-faq-a">
      Buka detail PO → klik <b>Cetak PO</b> → kirim ulang ke supplier via email atau WhatsApp.
      PO memiliki nomor unik yang bisa dijadikan referensi.
    </div>

    <div class="pnd-faq-q">❓ Barang datang sebagian — bagaimana cara GR?</div>
    <div class="pnd-faq-a">
      Buat GR dengan jumlah yang benar-benar diterima (partial). PO status berubah ke PARTIAL GR.
      Saat sisa barang datang, buat GR lagi dari PO yang sama.
    </div>

    <div class="pnd-faq-q">❓ Invoice AP dari vendor berbeda dengan PO — apa yang harus dilakukan?</div>
    <div class="pnd-faq-a">
      Sistem akan memberi flag mismatch. Hubungi supplier untuk klarifikasi sebelum approve invoice.
      Jika ada debit/kredit note, input sebagai adjustment di invoice AP.
    </div>

    <div class="pnd-faq-q">❓ Bagaimana cara cek sisa hutang ke vendor (AP aging)?</div>
    <div class="pnd-faq-a">
      Buka <b>Purchases → Reports</b> → pilih laporan <b>AP Aging</b> untuk melihat daftar invoice yang belum dibayar
      beserta umur hutang (30/60/90 hari).
    </div>

    <div class="pnd-faq-q">❓ PIB belum keluar — apakah bisa GR dulu?</div>
    <div class="pnd-faq-a">
      Tergantung kebijakan. Biasanya GR dilakukan setelah barang secara fisik sudah di gudang dan dokumen PIB sudah ada.
      Koordinasikan dengan Manager Procurement dan Tim Logistik.
    </div>
  </div>

  <!-- Kontak -->
  <div class="pnd-section accent">
    <h3>🆘 Butuh Bantuan?</h3>
    <div class="pnd-desc" style="line-height:1.9">
      Untuk kendala modul Purchases, hubungi <b>Manager Procurement / FIN</b> atau <b>Admin Sistem</b>:
      <ul style="margin-top:10px;padding-left:22px">
        <li>WhatsApp Group Procurement / Finance</li>
        <li>Langsung ke divisi Procurement atau Finance</li>
      </ul>
      Sertakan: <b>nomor PO/GR/Invoice, nama vendor, tanggal, dan screenshot error</b>.
    </div>
  </div>

  <div style="text-align:center;margin:8px 0 28px">
    <a href="purchases_dashboard.php" class="btn btn-rmi btn-sm">← Kembali ke Purchases Dashboard</a>
  </div>

</div>

<?php rmi_footer(); ?>
