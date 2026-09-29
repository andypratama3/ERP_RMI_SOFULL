<?php
declare(strict_types=1);
require_once __DIR__ . '/_wqs_bootstrap.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

$pdo = wqs_pdo();
rmi_header('Panduan WQS / Warehouse', 'WQS', [
    'breadcrumbs' => [
        ['label' => 'WQS Stock', 'url' => 'wqs_stock.php'],
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
.pnd-badge.pending  { background:rgba(245,158,11,.15); color:#fbbf24 }
.pnd-badge.approved { background:rgba(16,185,129,.15); color:#34d399 }
.pnd-badge.done     { background:rgba(20,184,166,.15); color:#2dd4bf }
.pnd-badge.draft    { background:rgba(100,116,139,.2); color:#94a3b8 }
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
.pnd-menu-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px; margin:14px 0 }
.pnd-menu-item { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:10px; padding:12px 14px; text-decoration:none }
.pnd-menu-ico  { font-size:22px; margin-bottom:6px }
.pnd-menu-name { font-weight:600; font-size:13px; color:var(--rmi-text,#e8ecf4); margin-bottom:4px }
.pnd-menu-desc { font-size:11px; color:var(--rmi-muted,#9ca3af); line-height:1.5 }
h3 { font-size:15px; font-weight:700; margin:0 0 14px 0; display:flex; align-items:center; gap:8px }
</style>

<div style="max-width:780px;margin:0 auto">

  <div style="margin-bottom:22px">
    <h2 style="margin:0 0 4px 0">Panduan WQS — Warehouse & Stock</h2>
    <div style="color:var(--rmi-muted,#9ca3af);font-size:13px">
      Operasional Gudang Harian — ERP RMI Enterprise
      &nbsp;·&nbsp;
      <a href="wqs_stock.php" style="color:#60a5fa">← Kembali ke WQS Stock</a>
    </div>
  </div>

  <!-- Menu Utama WQS -->
  <div class="pnd-section">
    <h3><?= rmi_icon('target') ?> Menu Utama Modul WQS</h3>
    <div class="pnd-menu-grid">
      <div class="pnd-menu-item">
        <div class="pnd-menu-ico"><?= rmi_icon('box') ?></div>
        <div class="pnd-menu-name">WQS Stock</div>
        <div class="pnd-menu-desc">Lihat posisi stok saat ini per produk & office</div>
      </div>
      <div class="pnd-menu-item">
        <div class="pnd-menu-ico"><?= rmi_icon('inbox') ?></div>
        <div class="pnd-menu-name">Incoming</div>
        <div class="pnd-menu-desc">Penerimaan barang dari supplier (GR)</div>
      </div>
      <div class="pnd-menu-item">
        <div class="pnd-menu-ico"><?= rmi_icon('outbox') ?></div>
        <div class="pnd-menu-name">Picking</div>
        <div class="pnd-menu-desc">Pengambilan barang untuk DO</div>
      </div>
      <div class="pnd-menu-item">
        <div class="pnd-menu-ico"><?= rmi_icon('clipboard') ?></div>
        <div class="pnd-menu-name">Allocation</div>
        <div class="pnd-menu-desc">Alokasi stok ke Delivery Order</div>
      </div>
      <div class="pnd-menu-item">
        <div class="pnd-menu-ico"><?= rmi_icon('clipboard') ?></div>
        <div class="pnd-menu-name">Purchase Request</div>
        <div class="pnd-menu-desc">PR ke Procurement saat stok menipis</div>
      </div>
      <div class="pnd-menu-item">
        <div class="pnd-menu-ico"><?= rmi_icon('chart') ?></div>
        <div class="pnd-menu-name">Stock Opname</div>
        <div class="pnd-menu-desc">Perhitungan stok fisik berkala</div>
      </div>
      <div class="pnd-menu-item">
        <div class="pnd-menu-ico"><?= rmi_icon('refresh') ?></div>
        <div class="pnd-menu-name">Transfer Stok</div>
        <div class="pnd-menu-desc">Pindah stok antar cabang/office</div>
      </div>
      <div class="pnd-menu-item">
        <div class="pnd-menu-ico"><?= rmi_icon('gear') ?></div>
        <div class="pnd-menu-name">Adjustment</div>
        <div class="pnd-menu-desc">Koreksi stok (manager only)</div>
      </div>
    </div>
  </div>

  <!-- Alur Barang Masuk -->
  <div class="pnd-section">
    <h3><?= rmi_icon('inbox') ?> Alur Barang Masuk (Incoming / Goods Receipt)</h3>
    <div class="pnd-pipe">
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#2563eb;color:#fff"><?= rmi_icon('cart') ?></div>
        <div class="pnd-pipe-lbl">Barang Tiba<br><span style="color:#60a5fa">Supplier</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#d97706;color:#fff"><?= rmi_icon('clipboard') ?></div>
        <div class="pnd-pipe-lbl">Buat GR<br><span style="color:#fbbf24">Incoming</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#7c3aed;color:#fff"><?= rmi_icon('tick') ?></div>
        <div class="pnd-pipe-lbl">Verifikasi<br><span style="color:#a78bfa">Manager</span></div>
      </div>
      <div class="pnd-pipe-line"></div>
      <div class="pnd-pipe-step">
        <div class="pnd-pipe-dot" style="background:#16a34a;color:#fff"><?= rmi_icon('box') ?></div>
        <div class="pnd-pipe-lbl">Stok Masuk<br><span style="color:#34d399">Update</span></div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">1</div>
      <div>
        <div class="pnd-title">Buka Menu Incoming</div>
        <div class="pnd-desc">
          Dari topbar WQS, klik <b>Incoming</b>, atau akses:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/stock/wqs_incoming.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">2</div>
      <div>
        <div class="pnd-title">Klik "+ Buat Penerimaan Baru"</div>
        <div class="pnd-desc">
          Pilih <b>Purchase Order (PO)</b> yang terkait (dari Procurement). Sistem akan memuat item-item PO secara otomatis.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">3</div>
      <div>
        <div class="pnd-title">Isi Jumlah yang Diterima</div>
        <div class="pnd-desc">
          Masukkan jumlah aktual barang yang diterima per item. Bisa berbeda dengan jumlah PO (partial delivery).
          Isi juga nomor surat jalan / delivery order supplier.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">4</div>
      <div>
        <div class="pnd-title">Submit → Approve</div>
        <div class="pnd-desc">
          Klik <b>Submit</b>. Manager WQS akan memverifikasi dan klik <b>Approve</b>.
          Setelah approved, stok otomatis bertambah sesuai item yang diterima.
        </div>
      </div>
    </div>

    <div class="pnd-alert">
      <strong>Catatan:</strong> Pastikan nama barang, satuan, dan jumlah sesuai dengan fisik barang dan surat jalan supplier sebelum submit.
    </div>
  </div>

  <!-- Alur Barang Keluar (Picking) -->
  <div class="pnd-section">
    <h3><?= rmi_icon('outbox') ?> Proses Picking Barang untuk DO</h3>

    <div class="pnd-step">
      <div class="pnd-num green">1</div>
      <div>
        <div class="pnd-title">Buka Menu Picking</div>
        <div class="pnd-desc">
          Klik <b>Picking</b> dari topbar, atau:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/stock/wqs_picking.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">2</div>
      <div>
        <div class="pnd-title">Pilih DO yang Perlu Dipicking</div>
        <div class="pnd-desc">
          Daftar DO yang sudah dialokasikan akan muncul. Pilih DO yang akan diproses hari ini.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">3</div>
      <div>
        <div class="pnd-title">Konfirmasi Barang yang Dipicking</div>
        <div class="pnd-desc">
          Periksa fisik barang sesuai daftar item DO. Isi jumlah yang benar-benar dipicking.
          Klik <b>Konfirmasi Picking</b> setelah selesai.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num green">4</div>
      <div>
        <div class="pnd-title">Stok Berkurang Otomatis</div>
        <div class="pnd-desc">Setelah picking dikonfirmasi, sistem akan otomatis mengurangi stok sesuai item yang dipicking.</div>
      </div>
    </div>
  </div>

  <!-- Purchase Request -->
  <div class="pnd-section">
    <h3><?= rmi_icon('clipboard') ?> Purchase Request (PR) — Saat Stok Menipis</h3>

    <div class="pnd-step">
      <div class="pnd-num yellow">1</div>
      <div>
        <div class="pnd-title">Cek Posisi Stok</div>
        <div class="pnd-desc">
          Buka <b>WQS Stock</b> → lihat kolom Stok. Jika mendekati safety stock, buat Purchase Request.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">2</div>
      <div>
        <div class="pnd-title">Buka Menu PR</div>
        <div class="pnd-desc">
          Klik menu <b>PR</b> di sidebar atau akses:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/stock/wqs_pr.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">3</div>
      <div>
        <div class="pnd-title">Buat PR Baru</div>
        <div class="pnd-desc">
          Klik <b>+ Buat PR</b>. Isi daftar produk yang dibutuhkan beserta jumlah, satuan, dan keterangan urgensi.
          Klik <b>Submit PR</b>.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num yellow">4</div>
      <div>
        <div class="pnd-title">PR Diteruskan ke Procurement (Purchases)</div>
        <div class="pnd-desc">Tim Procurement akan menerima PR dan memproses ke RFQ → PO. Pantau status PR dari menu PR.</div>
      </div>
    </div>
  </div>

  <!-- Stock Opname -->
  <div class="pnd-section">
    <h3><?= rmi_icon('chart') ?> Stock Opname — Perhitungan Fisik Berkala</h3>

    <div class="pnd-step">
      <div class="pnd-num teal">1</div>
      <div>
        <div class="pnd-title">Buka Stock Opname</div>
        <div class="pnd-desc">
          Klik <b>Stock Opname</b> dari topbar, atau:<br>
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/stock/wqs_stock_opname.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num teal">2</div>
      <div>
        <div class="pnd-title">Mulai Sesi Opname</div>
        <div class="pnd-desc">Klik <b>Mulai Opname</b> — sistem akan membekukan (snapshot) data stok saat ini sebagai referensi.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num teal">3</div>
      <div>
        <div class="pnd-title">Isi Stok Fisik Aktual</div>
        <div class="pnd-desc">Hitung fisik barang di gudang. Isi kolom "Stok Fisik" untuk setiap item produk.</div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num teal">4</div>
      <div>
        <div class="pnd-title">Lihat Selisih & Submit</div>
        <div class="pnd-desc">
          Sistem menampilkan selisih (surplus/defisit) otomatis. Setelah semua item selesai, klik <b>Finalisasi Opname</b>.
          Hasilnya bisa dilihat di <b>Opname Report</b>.
        </div>
      </div>
    </div>

    <div class="pnd-info">
      <strong>Adjustment:</strong> Jika ada selisih yang perlu dikoreksi, gunakan menu <b>Stock Adjustment</b>
      (hanya untuk Manager/Admin). Setiap adjustment dicatat di Audit Log.
    </div>
  </div>

  <!-- Transfer Stok -->
  <div class="pnd-section">
    <h3><?= rmi_icon('refresh') ?> Transfer Stok Antar Cabang</h3>

    <div class="pnd-step">
      <div class="pnd-num blue">1</div>
      <div>
        <div class="pnd-title">Buka Stock Transfer</div>
        <div class="pnd-desc">
          <code style="font-size:12px;background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px">/ERP_RMI_SOFULL/stock/wqs_stock_transfer.php</code>
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">2</div>
      <div>
        <div class="pnd-title">Isi Form Transfer</div>
        <div class="pnd-desc">
          Pilih Office Asal → Office Tujuan → produk yang ditransfer → jumlah.
          Isi nomor referensi dan keterangan.
        </div>
      </div>
    </div>

    <div class="pnd-step">
      <div class="pnd-num blue">3</div>
      <div>
        <div class="pnd-title">Submit → Konfirmasi Penerima</div>
        <div class="pnd-desc">
          Setelah submit, Office Tujuan perlu mengkonfirmasi penerimaan barang.
          Stok Office Asal berkurang dan stok Office Tujuan bertambah setelah konfirmasi.
        </div>
      </div>
    </div>
  </div>

  <!-- FAQ -->
  <div class="pnd-section">
    <h3><?= rmi_icon('question') ?> Pertanyaan Umum (FAQ)</h3>

    <div class="pnd-faq-q"><?= rmi_icon('question') ?> Stok berkurang padahal tidak ada picking?</div>
    <div class="pnd-faq-a">
      Cek <b>Stock Audit</b> untuk melihat riwayat perubahan stok lengkap beserta waktu, user, dan alasan.
      Jika ada kejanggalan, hubungi Manager WQS atau Admin.
    </div>

    <div class="pnd-faq-q"><?= rmi_icon('question') ?> Barang diterima tapi Incoming tidak bisa dibuat?</div>
    <div class="pnd-faq-a">
      Pastikan PO sudah dibuat oleh tim Procurement dan statusnya belum CLOSED.
      Jika PO belum ada, hubungi Procurement untuk membuatnya terlebih dahulu.
    </div>

    <div class="pnd-faq-q"><?= rmi_icon('question') ?> DO tidak muncul di daftar Picking?</div>
    <div class="pnd-faq-a">
      DO hanya muncul di Picking setelah melewati proses Allocation. Hubungi tim Sales/SCM jika DO belum dialokasikan.
    </div>

    <div class="pnd-faq-q"><?= rmi_icon('question') ?> Bisa tidak Picking sebagian dulu?</div>
    <div class="pnd-faq-a">
      Ya — isi jumlah aktual yang dipicking. Sisa bisa dipicking di sesi berikutnya sampai DO selesai.
    </div>

    <div class="pnd-faq-q"><?= rmi_icon('question') ?> Salah input stok di Opname — bisa dikoreksi?</div>
    <div class="pnd-faq-a">
      Selama sesi Opname belum difinalisasi, data masih bisa diedit. Setelah finalisasi, gunakan
      <b>Stock Adjustment</b> (Manager only) untuk koreksi. Setiap perubahan tercatat di audit log.
    </div>
  </div>

  <!-- Kontak -->
  <div class="pnd-section accent">
    <h3><?= rmi_icon('question') ?> Butuh Bantuan?</h3>
    <div class="pnd-desc" style="line-height:1.9">
      Untuk kendala operasional WQS, hubungi <b>Manager WQS</b> atau <b>Admin Sistem</b>:
      <ul style="margin-top:10px;padding-left:22px">
        <li>WhatsApp Group Operasional Gudang</li>
        <li>Langsung ke Supervisor/Manager WQS di gudang</li>
      </ul>
      Sertakan: <b>nama, tanggal kejadian, DO/PR/GR nomor terkait, dan screenshot</b>.
    </div>
  </div>

  <div style="text-align:center;margin:8px 0 28px">
    <a href="wqs_stock.php" class="btn btn-rmi btn-sm">← Kembali ke WQS Stock</a>
  </div>

</div>

<?php rmi_footer(); ?>
