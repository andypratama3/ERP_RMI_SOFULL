<?php
/**
 * dashboards/scm/panduan.php
 * Panduan SCM (Supply Chain Management) — untuk Manager & Staff SCM
 */
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';
require_login();

$bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
if (!function_exists('h')) { function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
function scm_u(string $path): string {
    $bp = $GLOBALS['BASE_PROJECT'] ?? '';
    return rtrim($bp, '/') . '/' . ltrim($path, '/');
}

require_once __DIR__ . '/../../_shared/rmi_layout.php';
rmi_header('Panduan SCM', [
    'active' => 'scm_dashboard',
    'subtitle' => 'Supply Chain Management — Panduan Operasional',
    'breadcrumbs' => [
        ['label' => 'SCM Dashboard', 'url' => scm_u('/dashboards/scm/scm_dashboard.php')],
        'Panduan SCM',
    ],
    'actions' => [
        ['label' => '🚢 SCM Dashboard', 'url' => scm_u('/dashboards/scm/scm_dashboard.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<style>
.pnd-hero{background:linear-gradient(135deg,rgba(99,102,241,.15),rgba(168,85,247,.1));border:1px solid rgba(99,102,241,.3);border-radius:16px;padding:28px 32px;margin-bottom:24px}
.pnd-section{background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:14px;padding:22px 26px;margin-bottom:18px}
.pnd-section.accent-blue{border-color:rgba(59,130,246,.35)}
.pnd-section.accent-purple{border-color:rgba(139,92,246,.35)}
.pnd-section.accent-amber{border-color:rgba(245,158,11,.35)}
.pnd-section.accent-green{border-color:rgba(34,197,94,.35)}
.pnd-section.accent-teal{border-color:rgba(20,184,166,.35)}
.pnd-section.accent-red{border-color:rgba(239,68,68,.35)}
.pnd-step{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px}
.pnd-num{min-width:34px;height:34px;border-radius:50%;color:#fff;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
.pnd-title{font-weight:700;margin-bottom:5px;font-size:15px;color:#e2e8f0}
.pnd-desc{color:var(--rmi-muted,#9ca3af);font-size:13px;line-height:1.75}
.pnd-desc b{color:#e2e8f0}
.pnd-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600}
.pnd-badge.blue{background:rgba(59,130,246,.2);color:#93c5fd}
.pnd-badge.purple{background:rgba(139,92,246,.2);color:#c4b5fd}
.pnd-badge.amber{background:rgba(245,158,11,.2);color:#fcd34d}
.pnd-badge.green{background:rgba(34,197,94,.2);color:#86efac}
.pnd-badge.teal{background:rgba(20,184,166,.2);color:#2dd4bf}
.pnd-badge.red{background:rgba(239,68,68,.2);color:#fca5a5}
.pnd-flow{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:10px 0}
.pnd-flow-item{padding:5px 14px;border-radius:20px;font-size:12px;font-weight:700}
.pnd-flow-arrow{color:#475569;font-size:16px;font-weight:700}
.pnd-tip{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#fbbf24;margin-top:12px}
.pnd-warning{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#f87171;margin-top:12px}
.scm-quick{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-top:14px}
.scm-quick a{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#e2e8f0;text-decoration:none;font-size:13px;font-weight:600;transition:.12s}
.scm-quick a:hover{background:rgba(99,102,241,.12);border-color:rgba(99,102,241,.35);color:#a5b4fc}
</style>

<div class="pnd-hero">
  <div style="font-size:28px;margin-bottom:8px">🚢</div>
  <div style="font-size:20px;font-weight:800;color:#e2e8f0;margin-bottom:6px">Panduan SCM — Supply Chain Management</div>
  <div style="color:#94a3b8;font-size:14px;line-height:1.7">
    SCM mengelola seluruh rantai pasok: dari Purchase Order, Import, Forwarding, hingga barang tiba di gudang.
    Halaman ini menjelaskan alur kerja, tanggung jawab, dan langkah operasional harian SCM.
  </div>
</div>

<div class="row g-3">

  <!-- Alur Utama SCM -->
  <div class="col-12">
    <div class="pnd-section accent-blue">
      <h5 style="color:#93c5fd;margin-bottom:16px">📋 Alur Utama SCM</h5>
      <div class="pnd-flow">
        <div class="pnd-flow-item" style="background:rgba(59,130,246,.2);color:#93c5fd">1. Purchase Request (WQS)</div>
        <span class="pnd-flow-arrow">→</span>
        <div class="pnd-flow-item" style="background:rgba(139,92,246,.2);color:#c4b5fd">2. PO dibuat (PQP/SCM)</div>
        <span class="pnd-flow-arrow">→</span>
        <div class="pnd-flow-item" style="background:rgba(245,158,11,.2);color:#fcd34d">3. Forwarding/Import</div>
        <span class="pnd-flow-arrow">→</span>
        <div class="pnd-flow-item" style="background:rgba(20,184,166,.2);color:#2dd4bf">4. Good Receipt (GR)</div>
        <span class="pnd-flow-arrow">→</span>
        <div class="pnd-flow-item" style="background:rgba(34,197,94,.2);color:#86efac">5. WQS Incoming</div>
        <span class="pnd-flow-arrow">→</span>
        <div class="pnd-flow-item" style="background:rgba(99,102,241,.2);color:#a5b4fc">6. Stok Bertambah</div>
      </div>
      <div style="font-size:13px;color:#64748b;margin-top:8px">
        SCM terlibat di semua tahap: memonitor PO, koordinasi forwarding, dan memastikan barang masuk ke sistem.
      </div>
    </div>
  </div>

  <!-- Tugas Harian SCM Staff -->
  <div class="col-lg-6">
    <div class="pnd-section accent-purple">
      <h5 style="color:#c4b5fd;margin-bottom:16px">👷 Tugas Harian — SCM Staff</h5>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#3b82f6">1</div>
        <div>
          <div class="pnd-title">Cek SCM Dashboard</div>
          <div class="pnd-desc">Buka <b>SCM Dashboard</b> setiap pagi. Pantau: PR backlog, PO open, Incoming MTD, dan DO yang perlu dikirim.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#8b5cf6">2</div>
        <div>
          <div class="pnd-title">Monitor Import Control Tower</div>
          <div class="pnd-desc">Buka <b>Import Control Tower</b>. Cek status setiap PO: Production → ETD → ETA → Arrived → CEISA/PIB → GR.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#f59e0b">3</div>
        <div>
          <div class="pnd-title">Update Status Forwarding</div>
          <div class="pnd-desc">Buka <b>Forwarding Tasks</b>. Update progress pengiriman: nomor BL, tanggal ETD/ETA, status Customs.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#14b8a6">4</div>
        <div>
          <div class="pnd-title">Input Good Receipt (GR)</div>
          <div class="pnd-desc">Saat barang tiba, buka <b>Good Receipt</b>. Pilih PO → masukkan qty yang diterima → Save. GR akan trigger WQS Incoming.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">5</div>
        <div>
          <div class="pnd-title">Monitor SCM Task DO</div>
          <div class="pnd-desc">Buka <b>SCM Task DO</b>. Proses DO yang siap dikirim: konfirmasi pengiriman dan update status SCM.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tugas Harian SCM Manager -->
  <div class="col-lg-6">
    <div class="pnd-section accent-amber">
      <h5 style="color:#fcd34d;margin-bottom:16px">🎯 Tugas Harian — SCM Manager</h5>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#f59e0b">1</div>
        <div>
          <div class="pnd-title">Review PR & Approve PO</div>
          <div class="pnd-desc">Cek <b>Purchase Request</b> masuk. Koordinasi dengan PQP untuk approve PO. Pastikan semua PR punya PO.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#8b5cf6">2</div>
        <div>
          <div class="pnd-title">Pantau SLA Pengiriman</div>
          <div class="pnd-desc">Review ETD/ETA di Import Tower. Jika ada delay, koordinasi dengan vendor/forwarder dan update di sistem.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#3b82f6">3</div>
        <div>
          <div class="pnd-title">Approve GR & Verifikasi Kualitas</div>
          <div class="pnd-desc">Manager mereview GR dari staff. Pastikan qty GR sesuai PO. Jika ada selisih, catat di notes GR.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#14b8a6">4</div>
        <div>
          <div class="pnd-title">Laporan Mingguan SCM</div>
          <div class="pnd-desc">Export data dari SCM Dashboard (PO/GR/Incoming). Kirim ke manajemen: realisasi vs target per minggu.</div>
        </div>
      </div>

      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">5</div>
        <div>
          <div class="pnd-title">Koordinasi Lintas Dept</div>
          <div class="pnd-desc"><b>Dengan WQS:</b> Pastikan barang yang di-GR masuk Incoming. <b>Dengan PQP:</b> Update status PO & dokumen. <b>Dengan FIN:</b> Konfirmasi AP Invoice setelah GR.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Cara Pakai Import Control Tower -->
  <div class="col-12">
    <div class="pnd-section accent-teal">
      <h5 style="color:#2dd4bf;margin-bottom:16px">🗼 Import Control Tower — Cara Pakai</h5>
      <div class="row g-3">
        <div class="col-md-4">
          <div style="padding:14px;background:rgba(20,184,166,.06);border-radius:10px;border:1px solid rgba(20,184,246,.15)">
            <div style="color:#2dd4bf;font-weight:700;margin-bottom:8px">📊 Kolom Status</div>
            <ul style="font-size:13px;color:#94a3b8;padding-left:16px;margin:0;line-height:2">
              <li><b style="color:#e2e8f0">Production</b> — barang sedang diproduksi</li>
              <li><b style="color:#e2e8f0">ETD</b> — perkiraan tanggal berangkat</li>
              <li><b style="color:#e2e8f0">ETA</b> — perkiraan tiba Indonesia</li>
              <li><b style="color:#e2e8f0">Arrived</b> — barang sudah di pelabuhan</li>
              <li><b style="color:#e2e8f0">CEISA/PIB</b> — dokumen bea cukai</li>
              <li><b style="color:#e2e8f0">GR</b> — Good Receipt sudah diinput</li>
            </ul>
          </div>
        </div>
        <div class="col-md-4">
          <div style="padding:14px;background:rgba(20,184,166,.06);border-radius:10px;border:1px solid rgba(20,184,246,.15)">
            <div style="color:#2dd4bf;font-weight:700;margin-bottom:8px">✏️ Update Status PO</div>
            <ol style="font-size:13px;color:#94a3b8;padding-left:16px;margin:0;line-height:2">
              <li>Buka Import Control Tower</li>
              <li>Klik nomor PO yang mau diupdate</li>
              <li>Isi kolom yang berubah (ETD/ETA/Arrived)</li>
              <li>Klik <b style="color:#2dd4bf">Simpan</b></li>
              <li>Status otomatis terupdate di semua tampilan</li>
            </ol>
          </div>
        </div>
        <div class="col-md-4">
          <div style="padding:14px;background:rgba(20,184,166,.06);border-radius:10px;border:1px solid rgba(20,184,246,.15)">
            <div style="color:#2dd4bf;font-weight:700;margin-bottom:8px">📋 Filter & Export</div>
            <ul style="font-size:13px;color:#94a3b8;padding-left:16px;margin:0;line-height:2">
              <li>Filter by <b style="color:#e2e8f0">Office</b> (cabang)</li>
              <li>Filter by <b style="color:#e2e8f0">Status</b> (Open/Closed)</li>
              <li>Klik <b style="color:#2dd4bf">Export CSV/Excel</b> di bawah tabel</li>
              <li>Data bisa difilter ke period tertentu</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Good Receipt -->
  <div class="col-lg-6">
    <div class="pnd-section accent-green">
      <h5 style="color:#86efac;margin-bottom:16px">✅ Good Receipt (GR) — Langkah</h5>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">1</div>
        <div>
          <div class="pnd-title">Buka halaman GR</div>
          <div class="pnd-desc">Menu <b>Good Receipt (GR)</b> → klik <b>Tambah GR Baru</b></div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">2</div>
        <div>
          <div class="pnd-title">Pilih PO</div>
          <div class="pnd-desc">Cari nomor PO dari dropdown. Sistem otomatis tampilkan item dan qty yang dipesan.</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">3</div>
        <div>
          <div class="pnd-title">Input Qty Diterima</div>
          <div class="pnd-desc">Masukkan qty aktual yang diterima. Jika ada selisih, catat di kolom <b>Notes</b>.</div>
        </div>
      </div>
      <div class="pnd-step">
        <div class="pnd-num" style="background:#10b981">4</div>
        <div>
          <div class="pnd-title">Save → Trigger Incoming</div>
          <div class="pnd-desc">Klik <b>Simpan</b>. GR akan otomatis membuat WQS Incoming yang perlu diproses tim WQS.</div>
        </div>
      </div>
      <div class="pnd-tip">💡 <b>GR Parsial:</b> Jika barang tiba sebagian, buat GR dengan qty parsial. Bisa buat GR lagi saat sisanya tiba.</div>
    </div>
  </div>

  <!-- Integrasi Lintas Dept -->
  <div class="col-lg-6">
    <div class="pnd-section accent-red">
      <h5 style="color:#fca5a5;margin-bottom:16px">🔗 Integrasi SCM dengan Dept Lain</h5>
      <div style="display:flex;flex-direction:column;gap:12px">
        <div style="padding:12px 14px;background:rgba(239,68,68,.06);border-radius:10px;border:1px solid rgba(239,68,68,.15)">
          <div style="color:#f87171;font-weight:700;margin-bottom:4px">↔ SCM ↔ WQS</div>
          <div style="font-size:13px;color:#94a3b8">Setelah GR → WQS proses Incoming → Stok bertambah. SCM harus koordinasi jika ada barang spesial (serial number, exp date).</div>
        </div>
        <div style="padding:12px 14px;background:rgba(239,68,68,.06);border-radius:10px;border:1px solid rgba(239,68,68,.15)">
          <div style="color:#f87171;font-weight:700;margin-bottom:4px">↔ SCM ↔ PQP</div>
          <div style="font-size:13px;color:#94a3b8">SCM membantu PQP dalam pemilihan vendor dan monitoring PO. PQP yang buat PO, SCM yang monitor progress.</div>
        </div>
        <div style="padding:12px 14px;background:rgba(239,68,68,.06);border-radius:10px;border:1px solid rgba(239,68,68,.15)">
          <div style="color:#f87171;font-weight:700;margin-bottom:4px">↔ SCM ↔ FIN</div>
          <div style="font-size:13px;color:#94a3b8">Setelah GR confirm → FIN input AP Invoice. SCM perlu memastikan dokumen GR lengkap agar FIN bisa bayar vendor.</div>
        </div>
        <div style="padding:12px 14px;background:rgba(239,68,68,.06);border-radius:10px;border:1px solid rgba(239,68,68,.15)">
          <div style="color:#f87171;font-weight:700;margin-bottom:4px">↔ SCM ↔ CRM</div>
          <div style="font-size:13px;color:#94a3b8">SCM Task DO — SCM memproses DO sebelum dikirim ke customer. Konfirmasi dengan CRM untuk jadwal pengiriman.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick Links -->
  <div class="col-12">
    <div class="pnd-section">
      <h5 style="color:#e2e8f0;margin-bottom:14px">⚡ Akses Cepat SCM</h5>
      <div class="scm-quick">
        <a href="<?= h(scm_u('/dashboards/scm/scm_dashboard.php')) ?>">🚢 SCM Dashboard</a>
        <a href="<?= h(scm_u('/purchases/purchases_import_control_tower.php')) ?>">🗼 Import Control Tower</a>
        <a href="<?= h(scm_u('/purchases/purchases_po.php')) ?>">📄 Purchase Order (PO)</a>
        <a href="<?= h(scm_u('/purchases/purchases_gr.php')) ?>">✅ Good Receipt (GR)</a>
        <a href="<?= h(scm_u('/purchases/purchases_forwarding_tasks.php')) ?>">🚢 Forwarding Tasks</a>
        <a href="<?= h(scm_u('/purchases/purchases_ceisa_pib.php')) ?>">📋 PIB / CEISA</a>
        <a href="<?= h(scm_u('/sales/scm_do_tasks.php')) ?>">📦 SCM Task DO</a>
        <a href="<?= h(scm_u('/stock/wqs_pr.php')) ?>">📝 Purchase Request</a>
        <a href="<?= h(scm_u('/stock/wqs_incoming.php')) ?>">📥 WQS Incoming</a>
        <a href="<?= h(scm_u('/master/master_vendors.php')) ?>">🤝 Master Vendor</a>
        <a href="<?= h(scm_u('/kpi/kpi_center.php')) ?>">📈 KPI Center</a>
        <a href="<?= h(scm_u('/absensi/index.php')) ?>">📅 Absensi</a>
      </div>
    </div>
  </div>

</div><!-- /row -->

<?php rmi_footer(); ?>
