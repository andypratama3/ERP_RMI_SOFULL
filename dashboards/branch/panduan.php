<?php
/**
 * dashboards/branch/panduan.php
 * Panduan BRANCH — untuk semua staff & manager kantor cabang
 */
declare(strict_types=1);

require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();
$bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
$office = function_exists('auth_office_code') ? strtoupper(trim((string)(auth_office_code()))) : '';

if (!function_exists('h')) { function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
function br_u(string $path): string {
    $bp = $GLOBALS['BASE_PROJECT'] ?? '';
    return rtrim($bp, '/') . '/' . ltrim($path, '/');
}

require_once __DIR__ . '/../../_shared/rmi_layout.php';
rmi_header('Panduan BRANCH', [
    'active'      => 'branch_dashboard',
    'subtitle'    => 'Panduan Operasional untuk Kantor Cabang',
    'breadcrumbs' => [
        ['label' => 'Branch Dashboard', 'url' => br_u('/dashboards/branch/branch_dashboard.php')],
        'Panduan BRANCH',
    ],
    'actions' => [
        ['label' => rmi_icon('office').' Branch Dashboard', 'url' => br_u('/dashboards/branch/branch_dashboard.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<style>
.pnd-hero{background:linear-gradient(135deg,rgba(16,185,129,.15),rgba(20,184,166,.1));border:1px solid rgba(16,185,129,.35);border-radius:16px;padding:28px 32px;margin-bottom:24px}
.pnd-section{background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:14px;padding:22px 26px;margin-bottom:18px}
.pnd-section.accent-green{border-color:rgba(34,197,94,.35)}
.pnd-section.accent-blue{border-color:rgba(59,130,246,.35)}
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
.pnd-aturan{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#fbbf24;margin-top:12px}
.br-modul{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-top:12px}
.br-modul-item{padding:14px 16px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);text-align:center;transition:.12s}
.br-modul-item:hover{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.3)}
.br-modul-item .br-icon{font-size:22px;margin-bottom:6px}
.br-modul-item .br-label{font-size:12px;font-weight:600;color:#e2e8f0}
.br-modul-item .br-desc{font-size:11px;color:#64748b;margin-top:3px}
.pnd-links{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-top:14px}
.pnd-links a{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#e2e8f0;text-decoration:none;font-size:13px;font-weight:600;transition:.12s}
.pnd-links a:hover{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.4);color:#34d399}
.scope-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;background:rgba(16,185,129,.15);color:#34d399;border:1px solid rgba(16,185,129,.3)}
</style>

<!-- Hero -->
<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px"><?=rmi_icon('office')?></div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">
    Panduan BRANCH — Kantor Cabang
    <?php if ($office): ?>
      <span class="scope-badge" style="margin-left:10px;font-size:14px"><?=rmi_icon('office')?> <?= h($office) ?></span>
    <?php endif; ?>
  </div>
  <div style="color:#94a3b8;font-size:14px;line-height:1.7">
    Panduan lengkap untuk staff dan manager kantor cabang dalam menjalankan operasional harian menggunakan ERP RMI.
    Dari pembuatan DO, pengelolaan stok, absensi, hingga koordinasi dengan pusat.
  </div>
</div>

<div class="row g-3">

  <!-- Aturan Penting BRANCH -->
  <div class="col-12">
    <div class="pnd-section accent-amber">
      <h5 style="color:#fbbf24;margin-bottom:14px"><?=rmi_icon('warn')?> Aturan Penting Kantor Cabang</h5>
      <div class="row g-3">
        <div class="col-md-4">
          <div style="padding:14px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:10px;height:100%">
            <div style="color:#f87171;font-weight:700;font-size:14px;margin-bottom:8px"><?=rmi_icon('gear')?> Office Scope</div>
            <div style="font-size:13px;color:#94a3b8;line-height:1.8">
              Kamu <b style="color:#fca5a5">hanya bisa melihat dan mengelola data kantormu sendiri</b>.<br><br>
              Contoh: Staff BGR hanya lihat DO BGR, stok BGR, PR BGR.<br>
              <b>Tidak bisa</b> lihat data BDG, SMG, atau kantor lain.
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div style="padding:14px;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.2);border-radius:10px;height:100%">
            <div style="color:#fbbf24;font-weight:700;font-size:14px;margin-bottom:8px"><?=rmi_icon('clipboard')?> Tugas Utama Cabang</div>
            <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:2">
              <li>Buat & proses <b style="color:#e2e8f0">Delivery Order (DO)</b></li>
              <li>Proses <b style="color:#e2e8f0">Picking & Incoming</b> barang</li>
              <li>Input <b style="color:#e2e8f0">Purchase Request (PR)</b></li>
              <li>Absensi harian</li>
              <li>Laporan KPI kantor</li>
            </ul>
          </div>
        </div>
        <div class="col-md-4">
          <div style="padding:14px;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.2);border-radius:10px;height:100%">
            <div style="color:#93c5fd;font-weight:700;font-size:14px;margin-bottom:8px"><?=rmi_icon('user')?> Hubungi Pusat Jika</div>
            <ul style="font-size:13px;color:#94a3b8;margin:0;padding-left:16px;line-height:2">
              <li>Stok kosong, perlu restock</li>
              <li>DO tidak bisa diproses</li>
              <li>Masalah teknis sistem</li>
              <li>Butuh akses tambahan</li>
              <li>PO / GR perlu dikonfirmasi</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Rutinitas Harian -->
  <div class="col-lg-6">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:16px"><?=rmi_icon('calendar')?> Rutinitas Harian BRANCH Staff</h5>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">1</div>
        <div>
          <div class="pnd-title">Login & Buka Branch Dashboard</div>
          <div class="pnd-desc">Setelah login, halaman utama langsung menampilkan <b>Branch Dashboard</b> dengan ringkasan DO, stok, dan absensi hari ini.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">2</div>
        <div>
          <div class="pnd-title">Absensi — Wajib Setiap Hari</div>
          <div class="pnd-desc">Buka menu <b>Absensi</b> → klik <em>Clock In</em>. Pastikan di jam masuk kerja. Jangan lupa <em>Clock Out</em> saat pulang.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">3</div>
        <div>
          <div class="pnd-title">Cek DO Masuk (dari CRM Pusat)</div>
          <div class="pnd-desc">Buka <b>WQS Task DO</b> atau <b>Sales Control Tower</b>. Lihat DO berstatus <em>crm_to_wqs</em> — ini DO yang perlu segera diproses WQS cabang.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">4</div>
        <div>
          <div class="pnd-title">Proses Picking DO</div>
          <div class="pnd-desc">Buka <b>Picking DO</b> → pilih DO → ambil barang → upload foto stok <b>sebelum</b> dan <b>sesudah</b> → set READY SCM.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">5</div>
        <div>
          <div class="pnd-title">Proses Incoming Barang</div>
          <div class="pnd-desc">Jika ada barang masuk (dari PO pusat), buka <b>Incoming Barang</b> → input qty diterima → isi Lot/Serial/Exp Date jika ada → Posting.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">6</div>
        <div>
          <div class="pnd-title">Cek Stok Kantor</div>
          <div class="pnd-desc">Buka <b>Lihat Stok</b> untuk monitor stok kantor. Jika ada produk hampir habis, segera buat <b>Purchase Request (PR)</b>.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Rutinitas Manager -->
  <div class="col-lg-6">
    <div class="pnd-section accent-teal">
      <h5 style="color:#2dd4bf;margin-bottom:16px"><?=rmi_icon('target')?> Rutinitas Harian BRANCH Manager</h5>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#14b8a6">1</div>
        <div>
          <div class="pnd-title">Review Branch Dashboard</div>
          <div class="pnd-desc">Cek ringkasan: berapa DO pending, stok kritis, absensi tim hari ini. Fokus pada badge merah yang butuh perhatian.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#14b8a6">2</div>
        <div>
          <div class="pnd-title">Approve Prioritas DO</div>
          <div class="pnd-desc">Review DO yang masuk — pastikan DO urgent diproses lebih dulu. Koordinasi dengan tim WQS untuk jadwal picking.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#14b8a6">3</div>
        <div>
          <div class="pnd-title">Monitor SCM Task DO</div>
          <div class="pnd-desc">Buka <b>SCM Task DO</b> → pastikan pengiriman DO berjalan. Set status <em>ON DELIVERY</em> saat barang dikirim, <em>DELIVERED</em> saat diterima.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#14b8a6">4</div>
        <div>
          <div class="pnd-title">Review & Approve PR</div>
          <div class="pnd-desc">Cek <b>Purchase Request</b> dari staff. PR perlu disetujui manager sebelum dikirim ke pusat untuk dijadikan PO.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#14b8a6">5</div>
        <div>
          <div class="pnd-title">Monitor Absensi & KPI Tim</div>
          <div class="pnd-desc">Cek <b>KPI Center</b> untuk performa tim. Review absensi mingguan, DO selesai vs target, dan stok movement.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#14b8a6">6</div>
        <div>
          <div class="pnd-title">Koordinasi dengan Pusat</div>
          <div class="pnd-desc">Via <b>Chat Internal</b> → hubungi PQP/SCM/CRM pusat untuk update status PO, konfirmasi pengiriman, atau masalah stok.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modul yang bisa diakses BRANCH -->
  <div class="col-12">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:16px"><?=rmi_icon('gear')?> Modul yang Bisa Diakses Kantor Cabang</h5>
      <div class="br-modul">
        <a href="<?= h(br_u('/sales/sales_do.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('clipboard')?></div>
            <div class="br-label">Delivery Order</div>
            <div class="br-desc">Buat & kelola DO</div>
          </div>
        </a>
        <a href="<?= h(br_u('/sales/sales_control_tower.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('tower')?></div>
            <div class="br-label">Sales Control Tower</div>
            <div class="br-desc">Monitor status semua DO</div>
          </div>
        </a>
        <a href="<?= h(br_u('/stock/wqs_do_tasks.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('zap')?></div>
            <div class="br-label">WQS Task DO</div>
            <div class="br-desc">DO masuk dari CRM</div>
          </div>
        </a>
        <a href="<?= h(br_u('/sales/scm_do_tasks.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('box')?></div>
            <div class="br-label">SCM Task DO</div>
            <div class="br-desc">Atur pengiriman DO</div>
          </div>
        </a>
        <a href="<?= h(br_u('/stock/wqs_picking.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('refresh')?></div>
            <div class="br-label">Picking DO</div>
            <div class="br-desc">Ambil & siapkan barang</div>
          </div>
        </a>
        <a href="<?= h(br_u('/stock/wqs_incoming.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('inbox')?></div>
            <div class="br-label">Incoming Barang</div>
            <div class="br-desc">Terima barang dari PO</div>
          </div>
        </a>
        <a href="<?= h(br_u('/stock/wqs_stock.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('chart')?></div>
            <div class="br-label">Lihat Stok</div>
            <div class="br-desc">Stok kantor kamu</div>
          </div>
        </a>
        <a href="<?= h(br_u('/stock/wqs_pr.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('memo')?></div>
            <div class="br-label">Purchase Request</div>
            <div class="br-desc">Minta restock ke pusat</div>
          </div>
        </a>
        <a href="<?= h(br_u('/purchases/purchases_po.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('doc')?></div>
            <div class="br-label">Purchase Order</div>
            <div class="br-desc">Lihat PO kantor</div>
          </div>
        </a>
        <a href="<?= h(br_u('/purchases/purchases_gr.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('check')?></div>
            <div class="br-label">Good Receipt</div>
            <div class="br-desc">Konfirmasi barang tiba</div>
          </div>
        </a>
        <a href="<?= h(br_u('/absensi/index.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('calendar')?></div>
            <div class="br-label">Absensi</div>
            <div class="br-desc">Clock in/out harian</div>
          </div>
        </a>
        <a href="<?= h(br_u('/hrl_process/index.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('refresh')?></div>
            <div class="br-label">HRL Process</div>
            <div class="br-desc">Dokumen & proses HR</div>
          </div>
        </a>
        <a href="<?= h(br_u('/kpi/kpi_center.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('trend')?></div>
            <div class="br-label">KPI Center</div>
            <div class="br-desc">Performa tim</div>
          </div>
        </a>
        <a href="<?= h(br_u('/chat/index.php')) ?>" style="text-decoration:none">
          <div class="br-modul-item">
            <div class="br-icon"><?=rmi_icon('memo')?></div>
            <div class="br-label">Chat Internal</div>
            <div class="br-desc">Komunikasi dengan pusat</div>
          </div>
        </a>
      </div>
    </div>
  </div>

  <!-- Cara Buat DO -->
  <div class="col-lg-6">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:16px"><?=rmi_icon('clipboard')?> Cara Buat Delivery Order (DO)</h5>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">1</div>
        <div>
          <div class="pnd-title">Buka Sales DO</div>
          <div class="pnd-desc">Menu <b>Delivery Order</b> → klik tombol <em>Buat DO Baru</em></div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">2</div>
        <div>
          <div class="pnd-title">Pilih Customer & Kantor</div>
          <div class="pnd-desc">Pilih <b>Customer</b> dari dropdown. <b>Office Code</b> otomatis terisi kantormu — <b style="color:#fca5a5">jangan diubah</b>.</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">3</div>
        <div>
          <div class="pnd-title">Tambah Produk</div>
          <div class="pnd-desc">Klik <em>+ Tambah Item</em> → pilih produk dari dropdown → isi qty → harga otomatis terisi dari pricelist. Cek stok sebelum input qty.</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">4</div>
        <div>
          <div class="pnd-title">Isi Alamat Pengiriman</div>
          <div class="pnd-desc">Isi kolom <b>Shipping Address</b> dengan alamat lengkap customer. Tambahkan <b>nomor HP PIC</b> untuk koordinasi pengiriman.</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">5</div>
        <div>
          <div class="pnd-title">Simpan → DO Dikirim ke WQS</div>
          <div class="pnd-desc">Klik <b>Simpan & Kirim ke WQS</b>. DO otomatis masuk ke antrian WQS untuk diproses picking.</div>
        </div>
      </div>
      <div class="pnd-tip"><?=rmi_icon('zap')?> <b>Stok tidak cukup?</b> Buat PR (Purchase Request) ke pusat terlebih dahulu. Jangan buat DO jika stok tidak tersedia — sistem akan menolak picking.</div>
    </div>
  </div>

  <!-- Cara Buat PR -->
  <div class="col-lg-6">
    <div class="pnd-section accent-purple">
      <h5 style="color:#c4b5fd;margin-bottom:16px"><?=rmi_icon('memo')?> Cara Buat Purchase Request (PR)</h5>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#8b5cf6">1</div>
        <div>
          <div class="pnd-title">Cek Stok Dulu</div>
          <div class="pnd-desc">Buka <b>Lihat Stok</b> → cari produk yang mau di-restock → cek qty yang tersisa. PR dibuat jika stok sudah mendekati minimum.</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#8b5cf6">2</div>
        <div>
          <div class="pnd-title">Buka Purchase Request</div>
          <div class="pnd-desc">Menu <b>Purchase Request (PR)</b> → klik <em>Buat PR Baru</em></div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#8b5cf6">3</div>
        <div>
          <div class="pnd-title">Pilih Produk & Qty</div>
          <div class="pnd-desc">Pilih produk → isi qty yang dibutuhkan → tambahkan <b>catatan/alasan</b> jika perlu (misal: stok habis, demand tinggi).</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#8b5cf6">4</div>
        <div>
          <div class="pnd-title">Submit PR</div>
          <div class="pnd-desc">Klik <b>Submit</b>. PR dikirim ke PQP pusat untuk diproses menjadi PO. Status PR bisa dipantau di halaman PR.</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#8b5cf6">5</div>
        <div>
          <div class="pnd-title">Tunggu PO & GR</div>
          <div class="pnd-desc">Pusat akan buat PO → barang dikirim → kamu terima via <b>GR (Good Receipt)</b> → lalu proses di <b>WQS Incoming</b> → stok bertambah.</div>
        </div>
      </div>
      <div class="pnd-tip"><?=rmi_icon('zap')?> <b>PR urgent?</b> Hubungi PQP pusat via Chat Internal dan beri tahu PR-nya urgent agar diprioritaskan.</div>
    </div>
  </div>

  <!-- FAQ -->
  <div class="col-12">
    <div class="pnd-section accent-red">
      <h5 style="color:#fca5a5;margin-bottom:14px"><?=rmi_icon('question')?> FAQ Kantor Cabang</h5>
      <div class="row g-3" style="font-size:13px">
        <div class="col-md-6">
          <div style="display:flex;flex-direction:column;gap:14px">
            <div>
              <div style="color:#f87171;font-weight:600">Q: Tidak bisa lihat data kantor lain?</div>
              <div style="color:#94a3b8;margin-top:4px">A: Benar — sistem membatasi data sesuai office_code kamu. Ini untuk keamanan dan keakuratan data. Jika perlu lihat data lain, hubungi SYS.</div>
            </div>
            <div>
              <div style="color:#f87171;font-weight:600">Q: Produk tidak muncul di dropdown saat buat DO?</div>
              <div style="color:#94a3b8;margin-top:4px">A: Produk hanya muncul jika ada di Master Produk dan stoknya ada. Jika produk tidak ada, minta pusat (PQP) untuk menambahkan ke master.</div>
            </div>
            <div>
              <div style="color:#f87171;font-weight:600">Q: DO sudah dibuat tapi tidak muncul di WQS Task?</div>
              <div style="color:#94a3b8;margin-top:4px">A: Refresh halaman. Jika masih tidak muncul, cek status DO di Sales DO — pastikan statusnya <em>crm_to_wqs</em> bukan <em>DRAFT</em>.</div>
            </div>
            <div>
              <div style="color:#f87171;font-weight:600">Q: Tidak bisa upload foto picking?</div>
              <div style="color:#94a3b8;margin-top:4px">A: Pastikan ukuran foto tidak terlalu besar (maks 5MB). Format yang didukung: JPG, PNG. Gunakan HP dengan koneksi internet stabil.</div>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div style="display:flex;flex-direction:column;gap:14px">
            <div>
              <div style="color:#f87171;font-weight:600">Q: Stok negatif di sistem, bagaimana?</div>
              <div style="color:#94a3b8;margin-top:4px">A: Jangan proses DO jika stok negatif. Hubungi WQS pusat untuk stock adjustment. Pastikan semua Incoming sudah di-posting.</div>
            </div>
            <div>
              <div style="color:#f87171;font-weight:600">Q: Incoming sudah diinput tapi stok tidak bertambah?</div>
              <div style="color:#94a3b8;margin-top:4px">A: Pastikan klik tombol <em>Posting</em> setelah input Incoming. Jika sudah di-posting tapi stok belum bertambah, hubungi SYS.</div>
            </div>
            <div>
              <div style="color:#f87171;font-weight:600">Q: Lupa absensi Clock In / Clock Out?</div>
              <div style="color:#94a3b8;margin-top:4px">A: Hubungi HRL Manager atau Admin untuk koreksi absensi. Koreksi harus dilakukan oleh HRL — staff tidak bisa ubah sendiri.</div>
            </div>
            <div>
              <div style="color:#f87171;font-weight:600">Q: Tidak bisa akses menu tertentu?</div>
              <div style="color:#94a3b8;margin-top:4px">A: Akses dibatasi sesuai role dan dept. Jika membutuhkan akses tambahan, minta manager untuk koordinasi dengan ITC/SYS.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick Links -->
  <div class="col-12">
    <div class="pnd-section">
      <h5 style="color:#e2e8f0;margin-bottom:14px"><?=rmi_icon('zap')?> Akses Cepat BRANCH</h5>
      <div class="pnd-links">
        <a href="<?= h(br_u('/dashboards/branch/branch_dashboard.php')) ?>"><?=rmi_icon('office')?> Branch Dashboard</a>
        <a href="<?= h(br_u('/sales/sales_do.php')) ?>"><?=rmi_icon('clipboard')?> Delivery Order</a>
        <a href="<?= h(br_u('/sales/sales_control_tower.php')) ?>"><?=rmi_icon('tower')?> Sales Control Tower</a>
        <a href="<?= h(br_u('/stock/wqs_do_tasks.php')) ?>"><?=rmi_icon('zap')?> WQS Task DO</a>
        <a href="<?= h(br_u('/sales/scm_do_tasks.php')) ?>"><?=rmi_icon('box')?> SCM Task DO</a>
        <a href="<?= h(br_u('/stock/wqs_picking.php')) ?>"><?=rmi_icon('refresh')?> Picking DO</a>
        <a href="<?= h(br_u('/stock/wqs_incoming.php')) ?>"><?=rmi_icon('inbox')?> Incoming Barang</a>
        <a href="<?= h(br_u('/stock/wqs_stock.php')) ?>"><?=rmi_icon('chart')?> Lihat Stok</a>
        <a href="<?= h(br_u('/stock/wqs_pr.php')) ?>"><?=rmi_icon('memo')?> Purchase Request</a>
        <a href="<?= h(br_u('/purchases/purchases_po.php')) ?>"><?=rmi_icon('doc')?> Purchase Order</a>
        <a href="<?= h(br_u('/purchases/purchases_gr.php')) ?>"><?=rmi_icon('check')?> Good Receipt</a>
        <a href="<?= h(br_u('/absensi/index.php')) ?>"><?=rmi_icon('calendar')?> Absensi</a>
        <a href="<?= h(br_u('/hrl_process/index.php')) ?>"><?=rmi_icon('refresh')?> HRL Process</a>
        <a href="<?= h(br_u('/kpi/kpi_center.php')) ?>"><?=rmi_icon('trend')?> KPI Center</a>
        <a href="<?= h(br_u('/chat/index.php')) ?>"><?=rmi_icon('memo')?> Chat Internal</a>
        <a href="<?= h(br_u('/sales/panduan_control_tower.php')) ?>"><?=rmi_icon('books')?> Panduan Sales Tower</a>
      </div>
    </div>
  </div>

</div><!-- /row -->

<?php rmi_footer(); ?>
