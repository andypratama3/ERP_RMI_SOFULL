<?php
/**
 * sales/panduan_do_tasks.php
 * Panduan lengkap 4 Task DO: WQS → SCM → ACT → FIN
 */
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../_shared/bootstrap.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.VIEW', 'SALES.EDIT']);
}
require_once __DIR__ . '/../_shared/rmi_layout.php';

$bp = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';

rmi_header('Panduan Task DO', [
    'active'      => 'sales',
    'subtitle'    => 'WQS → SCM → ACT → FIN — Panduan Langkah per Dept',
    'breadcrumbs' => [
        ['label' => 'Sales (CRM)', 'url' => $bp . '/sales/sales_dashboard.php'],
        'Panduan Task DO',
    ],
    'actions' => [
        ['label' => rmi_icon('tower') . ' Control Tower', 'url' => $bp . '/sales/sales_control_tower.php', 'class' => 'btn btn-sm btn-rmi'],
        ['label' => rmi_icon('box') . ' WQS Task',      'url' => $bp . '/stock/wqs_do_tasks.php',        'class' => 'btn btn-sm btn-outline-light'],
        ['label' => '🚚 SCM Task',      'url' => $bp . '/sales/scm_do_tasks.php',        'class' => 'btn btn-sm btn-outline-light'],
        ['label' => rmi_icon('memo') . ' ACT Task',      'url' => $bp . '/sales/act_do_tasks.php',        'class' => 'btn btn-sm btn-outline-light'],
        ['label' => rmi_icon('money') . ' FIN Task',      'url' => $bp . '/sales/fin_do_tasks.php',        'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>
<style>
.pd-hero{background:linear-gradient(135deg,rgba(59,130,246,.12),rgba(139,92,246,.08));border:1px solid rgba(59,130,246,.3);border-radius:16px;padding:24px 28px;margin-bottom:22px}
.pd-flow{display:flex;gap:0;align-items:stretch;flex-wrap:wrap;margin-bottom:24px}
.pd-step{flex:1;min-width:140px;padding:16px 14px;text-align:center;border:1px solid rgba(255,255,255,.08);background:var(--rmi-card,#1a2235);position:relative}
.pd-step:first-child{border-radius:12px 0 0 12px}
.pd-step:last-child{border-radius:0 12px 12px 0}
.pd-step::after{content:"→";position:absolute;right:-14px;top:50%;transform:translateY(-50%);color:#6b7280;font-size:18px;z-index:2}
.pd-step:last-child::after{display:none}
.pd-step .ps-icon{font-size:22px;margin-bottom:4px}
.pd-step .ps-dept{font-size:11px;font-weight:700;letter-spacing:.06em;opacity:.7}
.pd-step .ps-label{font-size:12px;font-weight:600;margin-top:2px}
.pd-step.crm{border-top:3px solid rgba(59,130,246,.6)}
.pd-step.wqs{border-top:3px solid rgba(239,68,68,.6)}
.pd-step.scm{border-top:3px solid rgba(245,158,11,.6)}
.pd-step.act{border-top:3px solid rgba(16,185,129,.6)}
.pd-step.fin{border-top:3px solid rgba(168,85,247,.6)}
.pd-step.done{border-top:3px solid rgba(100,116,139,.5)}
.pd-card{background:var(--rmi-card,#1a2235);border:1px solid rgba(255,255,255,.08);border-radius:14px;margin-bottom:18px;overflow:hidden}
.pd-card-head{padding:14px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.06)}
.pd-card-head .ph-icon{font-size:20px}
.pd-card-head .ph-title{font-size:15px;font-weight:700}
.pd-card-head .ph-sub{font-size:11px;opacity:.6;margin-top:1px}
.pd-card-body{padding:18px 20px}
.pd-card.wqs .pd-card-head{background:rgba(239,68,68,.06);border-left:4px solid rgba(239,68,68,.5)}
.pd-card.scm .pd-card-head{background:rgba(245,158,11,.06);border-left:4px solid rgba(245,158,11,.5)}
.pd-card.act .pd-card-head{background:rgba(16,185,129,.06);border-left:4px solid rgba(16,185,129,.5)}
.pd-card.fin .pd-card-head{background:rgba(168,85,247,.06);border-left:4px solid rgba(168,85,247,.5)}
.pd-steps{counter-reset:step;display:flex;flex-direction:column;gap:10px}
.pd-step-item{display:flex;gap:12px;align-items:flex-start}
.pd-step-num{flex-shrink:0;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15)}
.pd-step-item.done .pd-step-num{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.35);color:#4ade80}
.pd-step-content{flex:1;font-size:13px;line-height:1.5;padding-top:3px}
.pd-step-content b{color:#e2e8f0}
.pd-step-content .sc-note{font-size:11px;color:#6b7280;margin-top:3px;line-height:1.4}
.pd-warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:10px 14px;font-size:12px;color:#fcd34d;margin-top:14px;display:flex;gap:8px;align-items:flex-start}
.pd-ok{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:10px;padding:10px 14px;font-size:12px;color:#86efac;margin-top:14px;display:flex;gap:8px;align-items:flex-start}
.pd-status{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:700}
.st-wqs{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.2)}
.st-scm{background:rgba(245,158,11,.15);color:#fcd34d;border:1px solid rgba(245,158,11,.2)}
.st-act{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.2)}
.st-fin{background:rgba(168,85,247,.15);color:#d8b4fe;border:1px solid rgba(168,85,247,.2)}
.st-done{background:rgba(34,197,94,.1);color:#34d399}
.pd-faq{display:flex;flex-direction:column;gap:8px;margin-top:8px}
.pd-faq-item{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:12px 14px}
.pd-faq-q{font-size:12px;font-weight:700;color:#e2e8f0;margin-bottom:4px}
.pd-faq-a{font-size:12px;color:#9ca3af;line-height:1.5}
</style>

<!-- Hero -->
<div class="pd-hero">
  <h4 class="mb-1"><?= rmi_icon('clipboard') ?> Panduan Task DO — WQS → SCM → ACT → FIN</h4>
  <div style="font-size:13px;opacity:.8">Setiap Delivery Order melewati 4 departemen setelah CRM membuat DO. Panduan ini menjelaskan tugas, syarat, dan tombol yang dipakai oleh masing-masing dept.</div>
</div>

<!-- Alur Visual -->
<div class="pd-flow">
  <div class="pd-step crm">
    <div class="ps-icon">💼</div>
    <div class="ps-dept">CRM</div>
    <div class="ps-label">Buat DO</div>
  </div>
  <div class="pd-step wqs">
    <div class="ps-icon"><?= rmi_icon('box') ?></div>
    <div class="ps-dept">WQS</div>
    <div class="ps-label">Siapkan Barang</div>
  </div>
  <div class="pd-step scm">
    <div class="ps-icon">🚚</div>
    <div class="ps-dept">SCM</div>
    <div class="ps-label">Kirim & POD</div>
  </div>
  <div class="pd-step act">
    <div class="ps-icon"><?= rmi_icon('memo') ?></div>
    <div class="ps-dept">ACT</div>
    <div class="ps-label">Faktur Pajak</div>
  </div>
  <div class="pd-step fin">
    <div class="ps-icon"><?= rmi_icon('money') ?></div>
    <div class="ps-dept">FIN</div>
    <div class="ps-label">Terima Bayar</div>
  </div>
  <div class="pd-step done">
    <div class="ps-icon"><?= rmi_icon('check') ?></div>
    <div class="ps-dept">DONE</div>
    <div class="ps-label">PAID / Closed</div>
  </div>
</div>

<!-- WQS -->
<div class="pd-card wqs">
  <div class="pd-card-head">
    <div class="ph-icon"><?= rmi_icon('box') ?></div>
    <div>
      <div class="ph-title">WQS — Task DO dari CRM</div>
      <div class="ph-sub">Status: <span class="pd-status st-wqs">crm_to_wqs → wqs_processing → ready_scm</span></div>
    </div>
    <a href="<?= $bp ?>/stock/wqs_do_tasks.php" class="btn btn-sm btn-outline-light ms-auto">Buka WQS Task →</a>
  </div>
  <div class="pd-card-body">
    <div class="pd-steps">
      <div class="pd-step-item">
        <div class="pd-step-num">1</div>
        <div class="pd-step-content">
          <b>Cek DO yang masuk dari CRM</b>
          <div class="sc-note">DO dengan status <code>crm_to_wqs</code> atau <code>sent_wqs</code> muncul di daftar. DO baru masuk otomatis saat CRM klik "Kirim ke WQS".</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">2</div>
        <div class="pd-step-content">
          <b>Klik "Mulai Proses" → status jadi <code>wqs_processing</code></b>
          <div class="sc-note">Tombol ini mengunci DO agar tidak diedit CRM saat WQS sedang menyiapkan barang.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">3</div>
        <div class="pd-step-content">
          <b>Upload foto kartu stok sebelum pengambilan</b>
          <div class="sc-note">Foto stok sebelum (wajib) dan setelah (opsional). Digunakan sebagai bukti pengurangan stok.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">4</div>
        <div class="pd-step-content">
          <b>Klik "Set READY SCM" → status jadi <code>ready_scm</code></b>
          <div class="sc-note">Syarat: sudah Mulai Proses (status <code>wqs_processing</code>). DO akan muncul di halaman SCM Task.</div>
        </div>
      </div>
    </div>
    <div class="pd-warn"><?= rmi_icon('warn') ?> Tidak bisa Set READY SCM sebelum klik Mulai Proses. Urutan wajib: <b>Mulai Proses → Ready SCM</b>.</div>
  </div>
</div>

<!-- SCM -->
<div class="pd-card scm">
  <div class="pd-card-head">
    <div class="ph-icon">🚚</div>
    <div>
      <div class="ph-title">SCM — Task DO (Pengiriman & POD)</div>
      <div class="ph-sub">Status: <span class="pd-status st-scm">ready_scm → on_delivery → delivered</span></div>
    </div>
    <a href="<?= $bp ?>/sales/scm_do_tasks.php" class="btn btn-sm btn-outline-light ms-auto">Buka SCM Task →</a>
  </div>
  <div class="pd-card-body">
    <div class="pd-steps">
      <div class="pd-step-item">
        <div class="pd-step-num">1</div>
        <div class="pd-step-content">
          <b>Pilih Metode Pengiriman: INTERNAL atau VENDOR</b>
          <div class="sc-note">INTERNAL = team SCM sendiri. VENDOR = jasa logistik (pilih vendor dari dropdown). Kalau VENDOR, wajib pilih vendor sebelum set ON DELIVERY.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">2</div>
        <div class="pd-step-content">
          <b>Upload bukti terima barang dari WQS (foto atau video)</b>
          <div class="sc-note">Wajib sebelum klik Set ON DELIVERY. Foto/video ini menjadi bukti serah terima WQS → SCM.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">3</div>
        <div class="pd-step-content">
          <b>Klik "Set ON DELIVERY" → status jadi <code>on_delivery</code></b>
          <div class="sc-note">CRM bisa langsung notifikasi ke customer bahwa barang sedang dalam pengiriman.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">4</div>
        <div class="pd-step-content">
          <b>Upload bukti serah terima ke customer (foto/video POD)</b>
          <div class="sc-note">Proof of Delivery (POD). Wajib sebelum klik Set DELIVERED.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">5</div>
        <div class="pd-step-content">
          <b>Minta tanda tangan digital customer di kanvas</b>
          <div class="sc-note">Klik area kanvas untuk menandatangani → klik "Ambil TTD". Wajib sebelum DELIVERED.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">6</div>
        <div class="pd-step-content">
          <b>Klik "Set DELIVERED" → status jadi <code>delivered</code></b>
          <div class="sc-note">Syarat: ada bukti pengiriman + tanda tangan. DO akan muncul di ACT Task.</div>
        </div>
      </div>
    </div>
    <div class="pd-ok"><?= rmi_icon('check') ?> Fitur GPS otomatis (Start/Stop Auto GPS) tersedia untuk melacak posisi kurir secara live. Tidak wajib, tapi membantu transparansi pengiriman.</div>
    <div class="pd-warn"><?= rmi_icon('warn') ?> Tanpa foto terima dari WQS → tidak bisa ON DELIVERY. Tanpa foto + TTD → tidak bisa DELIVERED.</div>
  </div>
</div>

<!-- ACT -->
<div class="pd-card act">
  <div class="pd-card-head">
    <div class="ph-icon"><?= rmi_icon('memo') ?></div>
    <div>
      <div class="ph-title">ACT — Task DO (Penagihan & Faktur Pajak)</div>
      <div class="ph-sub">Status: <span class="pd-status st-act">delivered → wait_payment</span></div>
    </div>
    <a href="<?= $bp ?>/sales/act_do_tasks.php" class="btn btn-sm btn-outline-light ms-auto">Buka ACT Task →</a>
  </div>
  <div class="pd-card-body">
    <div class="pd-steps">
      <div class="pd-step-item">
        <div class="pd-step-num">1</div>
        <div class="pd-step-content">
          <b>Cek DO dengan status <code>delivered</code></b>
          <div class="sc-note">DO baru muncul di ACT setelah SCM set DELIVERED. DO yang sudah <code>wait_payment</code> masih terlihat tapi terkunci (read-only).</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">2</div>
        <div class="pd-step-content">
          <b>Upload Faktur Pajak (PDF/JPG)</b>
          <div class="sc-note">File faktur pajak yang sudah diterima dari customer atau sudah diterbitkan. Ini syarat utama sebelum kirim ke FIN.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">3</div>
        <div class="pd-step-content">
          <b>Upload Bukti Serah Terima Dokumen (opsional)</b>
          <div class="sc-note">Dokumen fisik (kwitansi, BA serah terima) jika ada.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">4</div>
        <div class="pd-step-content">
          <b>Isi tanggal jatuh tempo pembayaran</b>
          <div class="sc-note">Tanggal due date sesuai terms pembayaran customer. FIN akan memonitor berdasarkan tanggal ini.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">5</div>
        <div class="pd-step-content">
          <b>Klik "Kirim ke FIN (WAIT PAYMENT)" → status jadi <code>wait_payment</code></b>
          <div class="sc-note">Syarat: status harus <code>delivered</code>. DO akan muncul di FIN Task untuk proses pembayaran.</div>
        </div>
      </div>
    </div>
    <div class="pd-warn"><?= rmi_icon('warn') ?> Jika konfigurasi sistem mengharuskan faktur pajak sebelum PAID, upload Faktur Pajak wajib dilakukan di tahap ini. Cek konfigurasi di <code>system_config</code> → <code>TAX_INVOICE.REQUIRE_ISSUED_BEFORE_FIN_PAID</code>.</div>
  </div>
</div>

<!-- FIN -->
<div class="pd-card fin">
  <div class="pd-card-head">
    <div class="ph-icon"><?= rmi_icon('money') ?></div>
    <div>
      <div class="ph-title">FIN — Task DO (Penerimaan Pembayaran)</div>
      <div class="ph-sub">Status: <span class="pd-status st-fin">wait_payment → paid</span></div>
    </div>
    <a href="<?= $bp ?>/sales/fin_do_tasks.php" class="btn btn-sm btn-outline-light ms-auto">Buka FIN Task →</a>
  </div>
  <div class="pd-card-body">
    <div class="pd-steps">
      <div class="pd-step-item">
        <div class="pd-step-num">1</div>
        <div class="pd-step-content">
          <b>Cek DO dengan status <code>wait_payment</code></b>
          <div class="sc-note">DO muncul setelah ACT kirim ke FIN. DO yang sudah <code>paid</code> masih terlihat tapi terkunci.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">2</div>
        <div class="pd-step-content">
          <b>Upload bukti transfer / pembayaran</b>
          <div class="sc-note">Bukti transfer masuk ke rekening perusahaan. Format: PDF, JPG, PNG, WebP.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">3</div>
        <div class="pd-step-content">
          <b>Isi tanggal masuk rekening (fin_paid_date)</b>
          <div class="sc-note">Tanggal uang benar-benar masuk ke rekening, bukan tanggal transfer customer.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">4</div>
        <div class="pd-step-content">
          <b>Klik "Set PAID" → status jadi <code>paid</code></b>
          <div class="sc-note">Syarat: status harus <code>wait_payment</code>. DO selesai, alur CRM→WQS→SCM→ACT→FIN complete.</div>
        </div>
      </div>
    </div>
    <div class="pd-ok"><?= rmi_icon('check') ?> Setelah PAID, DO muncul di Control Tower dengan milestone semua hijau. Data bisa diexport untuk laporan keuangan.</div>
    <div class="pd-warn"><?= rmi_icon('warn') ?> Tidak bisa set PAID jika status bukan <code>wait_payment</code>. Pastikan ACT sudah kirim ke FIN sebelumnya.</div>
  </div>
</div>

<!-- Status Transition Reference -->
<div class="pd-card" style="margin-bottom:18px">
  <div class="pd-card-head">
    <div class="ph-icon"><?= rmi_icon('refresh') ?></div>
    <div>
      <div class="ph-title">Referensi Status Alur DO</div>
      <div class="ph-sub">Urutan wajib — tidak bisa dilewati</div>
    </div>
  </div>
  <div class="pd-card-body">
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead>
        <tr style="border-bottom:1px solid rgba(255,255,255,.1)">
          <th style="padding:8px 12px;text-align:left;color:#9ca3af;font-size:10px;font-weight:700;text-transform:uppercase">Status DB</th>
          <th style="padding:8px 12px;text-align:left;color:#9ca3af;font-size:10px;font-weight:700;text-transform:uppercase">Dept PIC</th>
          <th style="padding:8px 12px;text-align:left;color:#9ca3af;font-size:10px;font-weight:700;text-transform:uppercase">Aksi</th>
          <th style="padding:8px 12px;text-align:left;color:#9ca3af;font-size:10px;font-weight:700;text-transform:uppercase">Status Berikutnya</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ([
          ['crm_to_wqs / sent_wqs', 'WQS', 'Mulai Proses', 'wqs_processing'],
          ['wqs_processing',        'WQS', 'Set READY SCM', 'ready_scm'],
          ['ready_scm',             'SCM', 'Set ON DELIVERY', 'on_delivery'],
          ['on_delivery',           'SCM', 'Set DELIVERED (upload POD + TTD)', 'delivered'],
          ['delivered',             'ACT', 'Kirim ke FIN', 'wait_payment'],
          ['wait_payment',          'FIN', 'Set PAID (upload bukti bayar)', 'paid ' . rmi_icon('check')],
        ] as [$st, $dept, $aksi, $next]): ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
          <td style="padding:8px 12px"><code style="font-size:11px;color:#c7d2fe"><?= $st ?></code></td>
          <td style="padding:8px 12px;font-weight:700;font-size:11px"><?= $dept ?></td>
          <td style="padding:8px 12px;font-size:12px"><?= $aksi ?></td>
          <td style="padding:8px 12px"><code style="font-size:11px;color:#86efac"><?= $next ?></code></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- FAQ -->
<div class="pd-card" style="margin-bottom:24px">
  <div class="pd-card-head">
    <div class="ph-icon"><?= rmi_icon('question') ?></div>
    <div>
      <div class="ph-title">FAQ — Pertanyaan Umum</div>
    </div>
  </div>
  <div class="pd-card-body">
    <div class="pd-faq">
      <div class="pd-faq-item">
        <div class="pd-faq-q">DO tidak muncul di WQS Task padahal CRM sudah kirim?</div>
        <div class="pd-faq-a">Cek status DO di Control Tower. Harus <code>crm_to_wqs</code> atau <code>sent_wqs</code>. Jika status lain, DO sudah diproses atau ada kesalahan alur. Cek juga Office Code — BRANCH hanya lihat DO kantornya sendiri.</div>
      </div>
      <div class="pd-faq-item">
        <div class="pd-faq-q">Tombol "Set READY SCM" tidak bisa diklik / error?</div>
        <div class="pd-faq-a">Pastikan sudah klik <strong>Mulai Proses</strong> terlebih dahulu. Status DO harus <code>wqs_processing</code> sebelum bisa set READY SCM.</div>
      </div>
      <div class="pd-faq-item">
        <div class="pd-faq-q">SCM: Error "Wajib upload bukti terima barang dari WQS"?</div>
        <div class="pd-faq-a">Upload minimal satu file (foto atau video) di bagian "Bukti terima dari WQS" sebelum klik Set ON DELIVERY.</div>
      </div>
      <div class="pd-faq-item">
        <div class="pd-faq-q">SCM: Error "Wajib tanda tangan digital"?</div>
        <div class="pd-faq-a">Buka kanvas tanda tangan → minta customer tanda tangan → klik "Ambil TTD" → baru klik Set DELIVERED.</div>
      </div>
      <div class="pd-faq-item">
        <div class="pd-faq-q">FIN: Error "Status harus wait_payment"?</div>
        <div class="pd-faq-a">ACT belum kirim DO ke FIN. Hubungi ACT untuk membuka ACT Task dan klik "Kirim ke FIN".</div>
      </div>
      <div class="pd-faq-item">
        <div class="pd-faq-q">DO muncul dengan badge <?= rmi_icon('warn') ?> "X hari" di Control Tower?</div>
        <div class="pd-faq-a">DO belum diupdate lebih dari 3 hari. Cek dept PIC yang bertanggung jawab dan tindak lanjuti segera.</div>
      </div>
      <div class="pd-faq-item">
        <div class="pd-faq-q">Bagaimana cara share link tracking ke customer?</div>
        <div class="pd-faq-a">Di SCM Task, setiap DO punya tombol "Share WA" 💬 yang otomatis membuka WhatsApp dengan pesan berisi link tracking publik. Link ini bisa dibuka customer tanpa login.</div>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
