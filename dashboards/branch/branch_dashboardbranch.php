<?php
/**
 * dashboards/branch/branch_dashboard.php
 * Branch Office Dashboard — Landing page untuk staff cabang (BGR, BDG, dll).
 */
declare(strict_types=1);

require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();
// Dept guard: BRANCH Dashboard — BRANCH + SYS only
$bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
$office = function_exists('auth_office_code') ? auth_office_code() : '';

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function u(string $path): string {
    $bp = $GLOBALS['BASE_PROJECT'] ?? '';
    return rtrim($bp, '/') . '/' . ltrim($path, '/');
}

function fmt_money(float $v): string {
    return number_format($v, 0, ',', '.');
}

$periodStart = date('Y-m-01');
$periodEnd   = date('Y-m-d');
$pdo = $GLOBALS['pdo'] ?? null;

$kpi = [
    'do_count'   => 0, 'do_value'    => 0.0,
    'do_open'    => 0, 'picking'     => 0,
    'incoming'   => 0, 'hadir'       => 0,
    'pr_pending' => 0, 'piutang'     => 0.0,
];

require_once __DIR__ . '/../../_shared/rbac.php';
$branchDashShowKpi = false;
if ($pdo instanceof PDO && function_exists('rbac_can')) {
    $branchDashShowKpi = rbac_can($pdo, 'KPI.VIEW');
} elseif (function_exists('rbac_can2')) {
    $branchDashShowKpi = rbac_can2('KPI.VIEW');
}

if ($pdo) {
    try {
        // DO bulan ini
        $st = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS val FROM sales_do WHERE do_date BETWEEN ? AND ? AND UPPER(TRIM(COALESCE(office_code,'')))=UPPER(?)");
        $st->execute([$periodStart, $periodEnd, $office]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $kpi['do_count'] = (int)($r['cnt'] ?? 0);
        $kpi['do_value'] = (float)($r['val'] ?? 0);

        // DO open / belum lunas
        $st = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE UPPER(TRIM(COALESCE(office_code,'')))=UPPER(?) AND status NOT IN ('PAID','CANCELLED','DONE')");
        $st->execute([$office]); $kpi['do_open'] = (int)$st->fetchColumn();

        // Piutang outstanding
        $st = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(COALESCE(NULLIF(grand_total,0),total_amount,0)-COALESCE(fin_paid_amount,0),0)),0) FROM sales_do WHERE UPPER(TRIM(COALESCE(office_code,'')))=UPPER(?) AND status IN ('OPEN','WAIT_PAYMENT','PARTIAL','SUBMITTED')");
        $st->execute([$office]); $kpi['piutang'] = (float)$st->fetchColumn();

        // Picking pending
        try {
            $st = $pdo->prepare("SELECT COUNT(DISTINCT do_id) FROM sales_do_items di JOIN sales_do d ON d.id=di.do_id WHERE UPPER(TRIM(COALESCE(d.office_code,'')))=UPPER(?) AND d.status='crm_to_wqs'");
            $st->execute([$office]); $kpi['picking'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {}

        // Karyawan hadir hari ini di kantor ini
        try {
            $st = $pdo->prepare("
                SELECT COUNT(DISTINCT l.user_id) FROM absensi_logs l
                JOIN absensi_user_profile p ON p.user_id=l.user_id
                WHERE l.deleted_at IS NULL AND l.action_type='IN'
                  AND DATE(l.created_at)=CURDATE()
                  AND UPPER(TRIM(COALESCE(p.office_code,'')))=UPPER(?)");
            $st->execute([$office]); $kpi['hadir'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {}

        // PR pending
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM wqs_pr WHERE UPPER(TRIM(COALESCE(office_code,'')))=UPPER(?) AND status='PENDING'");
            $st->execute([$office]); $kpi['pr_pending'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

// Office name
$office_name = $office;
try {
    if ($pdo && $office !== '') {
        $stO = $pdo->prepare("SELECT office_name FROM master_office WHERE office_code=? LIMIT 1");
        $stO->execute([$office]);
        $r = $stO->fetch(PDO::FETCH_ASSOC);
        if ($r) $office_name = (string)$r['office_name'];
    }
} catch (Throwable $e) {}

require_once __DIR__ . '/../../_shared/rmi_layout.php';

$extraHead = <<<'HTML'
<style>
body{background:#0b1220;color:#e8ecf4}
.b-wrap{max-width:1100px;margin:0 auto;padding:20px 16px}

/* Greeting */
.b-greeting{background:linear-gradient(135deg,rgba(30,58,138,.8),rgba(17,94,89,.7));border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:22px 26px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;position:relative;overflow:hidden}
.b-greeting::before{content:"";position:absolute;top:-60px;right:-60px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.04)}
.b-greeting h2{margin:0;font-size:20px;font-weight:800;color:#fff}
.b-greeting p{margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.7)}
.b-clock{font-size:26px;font-weight:800;color:#67e8f9;font-variant-numeric:tabular-nums}
.b-date{font-size:11px;color:rgba(255,255,255,.5);text-align:right}

/* KPI grid */
.b-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:18px}
.b-kpi-card{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:14px;transition:all .2s;border-top:3px solid var(--kc)}
.b-kpi-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3)}
.b-kpi-icon{font-size:22px;margin-bottom:6px}
.b-kpi-val{font-size:22px;font-weight:800;color:#fff;line-height:1}
.b-kpi-lbl{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}

/* Quick link groups */
.b-links-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.b-link-group{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px}
.b-link-group-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.b-link{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;text-decoration:none;color:#e2e8f0;font-size:13px;transition:all .2s;margin-bottom:4px}
.b-link:hover{background:rgba(255,255,255,.08);color:#fff;padding-left:14px}
.b-link:last-child{margin-bottom:0}
.b-link .li{font-size:16px;flex-shrink:0}
.b-link .lbadge{margin-left:auto;background:rgba(239,68,68,.2);color:#f87171;font-size:10px;font-weight:700;padding:1px 7px;border-radius:8px}
.b-link .lbadge.green{background:rgba(34,197,94,.2);color:#4ade80}
.b-link .lbadge.yellow{background:rgba(251,191,36,.2);color:#fbbf24}
</style>
HTML;

rmi_header('Branch Dashboard', [
    'active'       => 'dashboard',
    'subtitle'     => h($office_name) . ' — Operasional Harian',
    'extra_head'   => $extraHead,
    'actions'      => [
        ['label' => '📚 Panduan', 'url' => u('/dashboards/branch/panduan.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<div class="b-wrap">

  <!-- Greeting -->
  <div class="b-greeting">
    <div>
      <h2>🏢 <?= h($office_name ?: 'Branch Dashboard') ?></h2>
      <p>ERP RMI &nbsp;·&nbsp; <?= h(date('l, d F Y')) ?> &nbsp;·&nbsp; Kode: <strong><?= h($office) ?></strong></p>
    </div>
    <div style="text-align:right">
      <div class="b-clock" id="brClock">--:--:--</div>
      <div class="b-date">Waktu Server</div>
    </div>
  </div>

  <!-- KPI Cards -->
  <div class="b-kpi">
    <div class="b-kpi-card" style="--kc:#3b82f6">
      <div class="b-kpi-icon">📋</div>
      <div class="b-kpi-val"><?= $kpi['do_count'] ?></div>
      <div class="b-kpi-lbl">DO Bulan Ini</div>
    </div>
    <div class="b-kpi-card" style="--kc:#22c55e">
      <div class="b-kpi-icon">💰</div>
      <div class="b-kpi-val" style="font-size:16px">Rp <?= fmt_money($kpi['do_value']) ?></div>
      <div class="b-kpi-lbl">Nilai Penjualan MTD</div>
    </div>
    <div class="b-kpi-card" style="--kc:#f59e0b">
      <div class="b-kpi-icon">⏳</div>
      <div class="b-kpi-val"><?= $kpi['do_open'] ?></div>
      <div class="b-kpi-lbl">DO Belum Lunas</div>
    </div>
    <div class="b-kpi-card" style="--kc:#ef4444">
      <div class="b-kpi-icon">💳</div>
      <div class="b-kpi-val" style="font-size:16px">Rp <?= fmt_money($kpi['piutang']) ?></div>
      <div class="b-kpi-lbl">Piutang Outstanding</div>
    </div>
    <div class="b-kpi-card" style="--kc:#06b6d4">
      <div class="b-kpi-icon">📦</div>
      <div class="b-kpi-val"><?= $kpi['picking'] ?></div>
      <div class="b-kpi-lbl">Picking Pending</div>
    </div>
    <div class="b-kpi-card" style="--kc:#8b5cf6">
      <div class="b-kpi-icon">📝</div>
      <div class="b-kpi-val"><?= $kpi['pr_pending'] ?></div>
      <div class="b-kpi-lbl">PR Pending</div>
    </div>
    <div class="b-kpi-card" style="--kc:#10b981">
      <div class="b-kpi-icon">✅</div>
      <div class="b-kpi-val"><?= $kpi['hadir'] ?></div>
      <div class="b-kpi-lbl">Hadir Hari Ini</div>
    </div>
  </div>

  <!-- Quick Links -->
  <div class="b-links-grid">

    <!-- Sales -->
    <div class="b-link-group">
      <div class="b-link-group-title">💼 Sales & CRM</div>
      <a class="b-link" href="<?= h(u('/sales/sales_do.php')) ?>"><span class="li">📋</span> Delivery Order (DO)</a>
      <a class="b-link" href="<?= h(u('/sales/sales_control_tower.php')) ?>"><span class="li">🗼</span> Sales Control Tower</a>
      <a class="b-link" href="<?= h(u('/sales/crm_leads.php')) ?>"><span class="li">🎯</span> CRM Leads</a>
      <a class="b-link" href="<?= h(u('/stock/wqs_do_tasks.php')) ?>">
        <span class="li">⚡</span> Task DO dari CRM
        <?php if ($kpi['picking'] > 0): ?><span class="lbadge yellow"><?= $kpi['picking'] ?></span><?php endif; ?>
      </a>
    </div>

    <!-- Stock / WQS -->
    <div class="b-link-group">
      <div class="b-link-group-title">📦 Warehouse & Stock</div>
      <a class="b-link" href="<?= h(u('/stock/wqs_picking.php')) ?>">
        <span class="li">🚚</span> Picking DO
        <?php if ($kpi['picking'] > 0): ?><span class="lbadge yellow"><?= $kpi['picking'] ?></span><?php endif; ?>
      </a>
      <a class="b-link" href="<?= h(u('/stock/wqs_incoming.php')) ?>"><span class="li">📥</span> Incoming Barang</a>
      <a class="b-link" href="<?= h(u('/stock/wqs_stock.php')) ?>"><span class="li">📊</span> Lihat Stok</a>
      <a class="b-link" href="<?= h(u('/stock/wqs_stock_opname.php')) ?>"><span class="li">🔢</span> Stock Opname</a>
      <a class="b-link" href="<?= h(u('/stock/wqs_pr.php')) ?>">
        <span class="li">📝</span> Purchase Request (PR)
        <?php if ($kpi['pr_pending'] > 0): ?><span class="lbadge"><?= $kpi['pr_pending'] ?></span><?php endif; ?>
      </a>
    </div>

    <!-- Purchasing -->
    <div class="b-link-group">
      <div class="b-link-group-title">🛒 Purchasing</div>
      <a class="b-link" href="<?= h(u('/purchases/purchases_po.php')) ?>"><span class="li">📄</span> Purchase Order (PO)</a>
      <a class="b-link" href="<?= h(u('/purchases/purchases_gr.php')) ?>"><span class="li">✅</span> Good Receipt (GR)</a>
      <a class="b-link" href="<?= h(u('/sales/scm_do_tasks.php')) ?>"><span class="li">🔄</span> SCM Task DO</a>
    </div>

    <!-- HR & Lain -->
    <div class="b-link-group">
      <div class="b-link-group-title">👥 HR & Lainnya</div>
      <a class="b-link" href="<?= h(u('/absensi/index.php')) ?>">
        <span class="li">📅</span> Absensi
        <?php if ($kpi['hadir'] > 0): ?><span class="lbadge green"><?= $kpi['hadir'] ?> hadir</span><?php endif; ?>
      </a>
      <a class="b-link" href="<?= h(u('/hrl_process/index.php')) ?>"><span class="li">📋</span> HRL Process</a>
      <a class="b-link" href="<?= h(u('/chat/index.php')) ?>"><span class="li">💬</span> Chat Internal</a>
      <?php if ($branchDashShowKpi): ?>
      <a class="b-link" href="<?= h(u('/kpi/kpi_center.php')) ?>"><span class="li">📊</span> KPI Center</a>
      <?php endif; ?>
      <a class="b-link" href="<?= h(u('/dashboards/index.php')) ?>"><span class="li">🏠</span> Dashboard Center</a>
      <a class="b-link" href="<?= h(u('/dashboards/branch/panduan.php')) ?>" style="border-color:rgba(16,185,129,.4);color:#34d399"><span class="li">📚</span> Panduan BRANCH</a>
    </div>

  </div>

  <div style="margin-top:12px;font-size:11px;color:#334155;text-align:center">
    Periode MTD: <?= h($periodStart) ?> s/d <?= h($periodEnd) ?>
  </div>
</div>

<script>
(function(){
  function pad(n){return n<10?'0'+n:n}
  function tick(){
    var d=new Date(),el=document.getElementById('brClock');
    if(el) el.textContent=pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
  }
  tick(); setInterval(tick,1000);
})();
</script>
<?php rmi_footer(); ?>
