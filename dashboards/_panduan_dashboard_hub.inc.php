<?php
declare(strict_types=1);
if (!function_exists('rmi_icon')) { require_once __DIR__ . '/../_shared/rmi_icons.php'; }
/**
 * Konten panduan Dashboard Center — gaya pd-* (sama konsep sales/panduan_do_tasks.php).
 * Set $bp = rmi_layout_base_project() sebelum include.
 */
if (!isset($bp) || !is_string($bp)) {
    throw new LogicException('$bp wajib string (base project URL) sebelum include _panduan_dashboard_hub.inc.php');
}
$bp = rtrim($bp, '/');
?>
<style>
.pd-hero{background:linear-gradient(135deg,rgba(59,130,246,.12),rgba(139,92,246,.08));border:1px solid rgba(59,130,246,.3);border-radius:16px;padding:24px 28px;margin-bottom:22px}
.pd-flow{display:flex;gap:0;align-items:stretch;flex-wrap:wrap;margin-bottom:24px}
.pd-step{flex:1;min-width:120px;padding:14px 12px;text-align:center;border:1px solid rgba(255,255,255,.08);background:var(--rmi-card,#1a2235);position:relative}
.pd-step:first-child{border-radius:12px 0 0 12px}
.pd-step:last-child{border-radius:0 12px 12px 0}
.pd-step::after{content:"→";position:absolute;right:-12px;top:50%;transform:translateY(-50%);color:#6b7280;font-size:16px;z-index:2}
.pd-step:last-child::after{display:none}
.pd-step .ps-icon{font-size:20px;margin-bottom:4px}
.pd-step .ps-dept{font-size:10px;font-weight:700;letter-spacing:.06em;opacity:.7}
.pd-step .ps-label{font-size:11px;font-weight:600;margin-top:2px}
.pd-step.a{border-top:3px solid rgba(59,130,246,.55)}
.pd-step.b{border-top:3px solid rgba(245,158,11,.55)}
.pd-step.c{border-top:3px solid rgba(16,185,129,.55)}
.pd-card{background:var(--rmi-card,#1a2235);border:1px solid rgba(255,255,255,.08);border-radius:14px;margin-bottom:18px;overflow:hidden}
.pd-card-head{padding:14px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.06);flex-wrap:wrap}
.pd-card-head .ph-icon{font-size:20px}
.pd-card-head .ph-title{font-size:15px;font-weight:700}
.pd-card-head .ph-sub{font-size:11px;opacity:.6;margin-top:1px}
.pd-card-body{padding:18px 20px}
.pd-card.do{border-left:4px solid rgba(59,130,246,.5)}
.pd-steps{counter-reset:step;display:flex;flex-direction:column;gap:10px}
.pd-step-item{display:flex;gap:12px;align-items:flex-start}
.pd-step-num{flex-shrink:0;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15)}
.pd-step-content{flex:1;font-size:13px;line-height:1.5;padding-top:3px}
.pd-step-content b{color:#e2e8f0}
.pd-step-content .sc-note{font-size:11px;color:#6b7280;margin-top:3px;line-height:1.4}
.pd-warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:10px 14px;font-size:12px;color:#fcd34d;margin-top:14px;display:flex;gap:8px;align-items:flex-start}
.pd-linkgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
.pd-linkgrid a{display:flex;align-items:center;gap:8px;padding:12px 14px;border-radius:10px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#e2e8f0;text-decoration:none;font-size:13px;font-weight:600;transition:.12s}
.pd-linkgrid a:hover{background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.35);color:#93c5fd}
ul.pd-bullets{margin:0;padding-left:18px;color:#94a3b8;font-size:13px;line-height:1.75}
ul.pd-bullets li{margin-bottom:6px}
</style>

<div class="pd-hero">
  <h4 class="mb-1"><?=rmi_icon('books')?> Panduan Dashboard Center</h4>
  <div style="font-size:13px;opacity:.85">Satu pintu masuk ke ringkasan KPI dan modul. Kartu yang tampil mengikuti <b>hak akses (RBAC)</b> — staff sering diarahkan langsung ke dashboard departemen setelah login.</div>
</div>

<div class="pd-flow">
  <div class="pd-step a">
    <div class="ps-icon"><?=rmi_icon('home')?></div>
    <div class="ps-dept">1</div>
    <div class="ps-label">Buka Dashboard Center</div>
  </div>
  <div class="pd-step b">
    <div class="ps-icon"><?=rmi_icon('target')?></div>
    <div class="ps-dept">2</div>
    <div class="ps-label">Pilih kartu sesuai tugas</div>
  </div>
  <div class="pd-step c">
    <div class="ps-icon"><?=rmi_icon('books')?></div>
    <div class="ps-dept">3</div>
    <div class="ps-label">Lanjut ke modul / panduan area</div>
  </div>
</div>

<div class="pd-card">
  <div class="pd-card-head">
    <div class="ph-icon"><?=rmi_icon('target')?></div>
    <div>
      <div class="ph-title">Yang perlu diketahui</div>
      <div class="ph-sub">Role, dept, dan permission menentukan apa yang Anda lihat</div>
    </div>
  </div>
  <div class="pd-card-body">
    <ul class="pd-bullets">
      <li><b>Admin / SYS</b> biasanya melihat semua kartu; staff hanya area yang diizinkan.</li>
      <li><b>KPI Center</b> mengumpulkan metrik lintas modul untuk monitoring harian.</li>
      <li>Data <b>office / cabang</b> mengikuti scope user (bukan seluruh kantor untuk semua orang).</li>
    </ul>
  </div>
</div>

<div class="pd-card do">
  <div class="pd-card-head">
    <div class="ph-icon"><?=rmi_icon('box')?></div>
    <div>
      <div class="ph-title">Alur Task DO (CRM → WQS → SCM → ACT → FIN)</div>
      <div class="ph-sub">Panduan langkah per departemen — format sama dengan halaman task</div>
    </div>
    <a href="<?= rmi_h($bp) ?>/sales/panduan_do_tasks.php" class="btn btn-sm btn-rmi ms-auto">Buka Panduan Task DO →</a>
  </div>
  <div class="pd-card-body">
    <div class="pd-steps">
      <div class="pd-step-item">
        <div class="pd-step-num">1</div>
        <div class="pd-step-content">
          <b>Mulai dari Control Tower atau task per dept</b>
          <div class="sc-note">CRM membuat DO; WQS siapkan barang; SCM kirim &amp; POD; ACT faktur pajak; FIN terima bayar.</div>
        </div>
      </div>
      <div class="pd-step-item">
        <div class="pd-step-num">2</div>
        <div class="pd-step-content">
          <b>Buka halaman task nyata dari tombol di panduan</b>
          <div class="sc-note">WQS / SCM / ACT / FIN Task punya tombol langsung di <code>panduan_do_tasks.php</code>.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="pd-card">
  <div class="pd-card-head">
    <div class="ph-icon"><?=rmi_icon('search')?></div>
    <div>
      <div class="ph-title">Panduan per dashboard &amp; area</div>
      <div class="ph-sub">Ringkas per folder — detail di masing-masing halaman</div>
    </div>
  </div>
  <div class="pd-card-body">
    <div class="pd-linkgrid">
      <a href="<?= rmi_h($bp) ?>/dashboards/panduan_funnels.php"><?=rmi_icon('target')?> Funnel Overview</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/branch/panduan.php"><?=rmi_icon('office')?> Branch</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/scm/panduan.php"><?=rmi_icon('box')?> SCM</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/finance/panduan.php"><?=rmi_icon('money')?> Finance</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/act/panduan.php"><?=rmi_icon('memo')?> ACT</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/warehouse/panduan.php"><?=rmi_icon('box')?> Warehouse (WQS)</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/quality/panduan.php"><?=rmi_icon('check')?> Quality</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/regulatory/panduan.php"><?=rmi_icon('office')?> Regulatory</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/hrl/panduan.php"><?=rmi_icon('users')?> HRL</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/itc/panduan.php"><?=rmi_icon('gear')?> ITC</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/owner/panduan.php"><?=rmi_icon('target')?> Executive Summary</a>
      <a href="<?= rmi_h($bp) ?>/sales/panduan.php"><?=rmi_icon('money')?> CRM / Sales</a>
      <a href="<?= rmi_h($bp) ?>/purchases/panduan.php"><?=rmi_icon('cart')?> Purchases / PQP</a>
      <a href="<?= rmi_h($bp) ?>/dashboards/procurement/panduan.php"><?=rmi_icon('box')?> Procurement</a>
    </div>
    <div class="pd-warn"><?=rmi_icon('warn')?> Tombol <b>Panduan</b> di header halaman ERP (jika ada) mengarah ke file <code>panduan_*.php</code> di folder yang sama dengan halaman kerja Anda — tidak melewati daftar ini.</div>
  </div>
</div>