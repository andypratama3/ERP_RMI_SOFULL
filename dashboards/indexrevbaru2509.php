<?php
require_once __DIR__ . '/_dashboard_bootstrap.php';
require_once __DIR__ . '/../_shared/rbac_ui.php';

// Static scan marker (guard enforced in _dashboard_bootstrap.php)
require_login();

$dept = rbac_ui_user_dept();
$is_admin = rbac_ui_is_admin();

// Redirect ke landing page masing-masing dept (BRANCH→branch_dashboard, CRM→sales, dll). Admin tetap lihat Dashboard Center.
if (!$is_admin && $dept !== '' && function_exists('auth_landing_path_for_dept')) {
    $landing = auth_landing_path_for_dept($dept);
    if ($landing !== '/dashboards/index.php') {
        $bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
        rmi_redirect($bp . $landing);
    }
}

// --- Helper URL builder ---
function dash_center_url(string $path): string {
    $bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
    $path = ltrim($path, '/');
    return rtrim($bp, '/') . '/' . $path;
}

$role = rbac_ui_user_role();

// Cards shown in Dashboard Center (menu)
// NOTE: RBAC enforcement must still exist in the destination pages.
$cards = [
    [
        'key'   => 'funnels',
        'title' => 'Funnel Overview',
        'desc'  => 'Ringkasan semua funnel: CRM Leads, Sales DO, Reg Alkes, Import/PO.',
        'href'  => dash_center_url('dashboards/funnels.php'),
        'perm'  => 'DASHBOARD.VIEW',
    ],
    [
        'key'   => 'owner_exec',
        'title' => 'Owner Executive Summary',
        'desc'  => 'Ringkasan bisnis untuk Owner.',
        'href'  => dash_center_url('dashboards/owner/exec_summary.php'),
        'depts' => [],
        'roles' => ['ADMIN', 'SUPERADMIN', 'SYS'],
    ],
    [
        'key'   => 'branch',
        'title' => 'Branch Dashboard',
        'desc'  => 'Landing staff cabang — Quick links: DO, Control Tower, Incoming, PR, PO, HRL, Chat.',
        'href'  => dash_center_url('dashboards/branch/branch_dashboard.php'),
        'perm'  => 'DASHBOARD.BRANCH_VIEW',
    ],
    [
        'key'   => 'crm',
        'title' => 'CRM Dashboard',
        'desc'  => 'Flow CRM → WQS → SCM → ACT → FIN + SLA (manager view).',
        'href'  => dash_center_url('sales/sales_dashboard.php'),
        'depts' => ['CRM', 'BRANCH'],
        'roles' => [],
    ],
    [
        'key'   => 'mpr',
        'title' => 'MPR Dashboard',
        'desc'  => 'Medical Representative — Plans, visits, progress, budget.',
        'href'  => dash_center_url('mpr/mpr_dashboard.php'),
        'depts' => ['MPR', 'BRANCH'],
        'roles' => [],
    ],
    [
        'key'   => 'warehouse',
        'title' => 'Warehouse (WQS) Dashboard',
        'desc'  => 'Stock, Incoming, Allocation, Picking + expiry risk.',
        'href'  => dash_center_url('dashboards/warehouse/wqs_dashboard.php'),
        'depts' => ['WQS', 'SCM', 'BRANCH'],
        'roles' => [],
    ],
    [
        'key'   => 'procurement',
        'title' => 'PQP Dashboard',
        'desc'  => 'PR → PO → AP → Incoming (PQP/FIN/SCM/WQS).',
        'href'  => dash_center_url('purchases/purchases_dashboard.php'),
        'depts' => ['PQP', 'SCM', 'FIN', 'ACT', 'WQS', 'BRANCH'],
        'roles' => [],
    ],
    [
        'key'   => 'finance',
        'title' => 'Finance Dashboard',
        'desc'  => 'AR/AP summary + link ke task FIN.',
        'href'  => dash_center_url('dashboards/finance/ar_ap_cash_dashboard.php'),
        'perm'  => 'DASHBOARD.FINANCE_VIEW',
    ],
    [
        'key'   => 'finance_detail',
        'title' => 'Dashboard Detail (Excel Style)',
        'desc'  => 'Target vs Pencapaian + Finance Detail per office (MTD As-Of). Khusus ADMIN & SUPERADMIN.',
        'href'  => dash_center_url('dashboards/finance/dashboard_detail.php'),
        'roles' => ['ADMIN', 'SUPERADMIN', 'SYS'],
    ],
    [
        'key'   => 'regulatory',
        'title' => 'Regulatory & Compliance',
        'desc'  => 'Expiry NIE/AKL/AKD (dari master_products) + link dossier.',
        'href'  => dash_center_url('dashboards/regulatory/license_docs_dashboard.php'),
        'depts' => ['PQP', 'HRL', 'FIN', 'ACT', 'BRANCH'],
        'roles' => [],
    ],
    [
        'key'   => 'quality',
        'title' => 'Quality & Complaint',
        'desc'  => 'Placeholder (nanti: incoming QC, complaint, CAPA, recall readiness).',
        'href'  => dash_center_url('dashboards/quality/qc_complaint_dashboard.php'),
        'depts' => ['PQP', 'WQS', 'SCM', 'ACT', 'BRANCH'],
        'roles' => [],
    ],
    [
        'key'   => 'scm',
        'title' => 'SCM Dashboard',
        'desc'  => 'Supply Chain Management — Import, Procurement, Logistics.',
        'href'  => dash_center_url('dashboards/scm/scm_dashboard.php'),
        'depts' => ['SCM', 'BRANCH'],
        'roles' => [],
    ],
    [
        'key'   => 'hrl',
        'title' => 'HRL Dashboard',
        'desc'  => 'Human Resource & Legal — Docs, Process, Absensi, KPI.',
        'href'  => dash_center_url('dashboards/hrl/hrl_dashboard.php'),
        'depts' => ['HRL', 'BRANCH'],
        'roles' => [],
    ],
    [
        'key'   => 'employee_mutation',
        'title' => 'Employee Mutation',
        'desc'  => 'Mutasi dan histori penempatan karyawan antar departemen/office dengan effective date.',
        'href'  => dash_center_url('hrl_process/employee_mutations.php'),
        'roles' => ['ADMIN', 'SUPERADMIN', 'SYS'],
    ],
    [
        'key'   => 'itc',
        'title' => 'ITC Dashboard',
        'desc'  => 'IT & Cloud — Master Data, RBAC, Tools.',
        'href'  => dash_center_url('dashboards/itc/itc_dashboard.php'),
        'depts' => ['ITC'],
        'roles' => [],
    ],
    [
        'key'   => 'error_log',
        'title' => 'Error Log Center',
        'desc'  => 'Error log per modul di storage/logs — khusus SYS / Admin.',
        'href'  => dash_center_url('tools/view_error_log.php'),
        'roles' => ['ADMIN', 'SUPERADMIN', 'SYS'],
    ],
    [
        'key'   => 'act',
        'title' => 'ACT Dashboard',
        'desc'  => 'Accounting & Tax — Fixed Asset, Finance, AR/AP.',
        'href'  => dash_center_url('dashboards/act/act_dashboard.php'),
        'depts' => ['ACT', 'BRANCH'],
        'roles' => [],
    ],
];

$visible_cards = [];
foreach ($cards as $c) {
    if (isset($c['perm']) && $c['perm'] !== '') {
        if ($is_admin || (function_exists('can') && can($c['perm']))) {
            $visible_cards[] = $c;
        }
    } else {
        $opts = [
            'depts' => $c['depts'] ?? [],
            'roles' => $c['roles'] ?? [],
            'allow_admin' => true,
        ];
        if (!empty($c['roles'])) {
            $opts['allow_admin'] = false;
        }
        if (rbac_ui_can($opts)) {
            $visible_cards[] = $c;
        }
    }
}
if ($is_admin) {
    $visible_cards = $cards;
}

$canFinanceQuickLink = $is_admin || (function_exists('can') && can('DASHBOARD.FINANCE_VIEW'));

require_once __DIR__ . '/../_shared/rmi_layout.php';

// Quick stats
$pdo_dash = null;
$stat_hadir = 0; $stat_order = 0; $stat_pending_ap = 0;
try {
    if (function_exists('db_pdo')) $pdo_dash = db_pdo();
    if ($pdo_dash) {
        $stat_hadir    = (int)$pdo_dash->query("SELECT COUNT(DISTINCT user_id) FROM absensi_logs WHERE deleted_at IS NULL AND action_type='IN' AND DATE(created_at)=CURDATE()")->fetchColumn();
        $stat_order    = (int)$pdo_dash->query("SELECT COUNT(*) FROM sales_do WHERE do_date=CURDATE()")->fetchColumn();
        $stat_pending_ap = (int)$pdo_dash->query("SELECT COUNT(*) FROM purchases_invoice_ap WHERE deleted_at IS NULL AND status IN ('UNPAID','PARTIAL')")->fetchColumn();
    }
} catch (Throwable $e) {}

// Username
$username_dash = (string)($_SESSION['username'] ?? ($_SESSION['user']['username'] ?? ''));
$fullname_dash = (string)($_SESSION['full_name'] ?? ($_SESSION['user']['full_name'] ?? $username_dash));

// Icon per card key
$card_meta = [
    'funnels'        => ['icon'=>rmi_icon('search'),'color'=>'#8b5cf6','group'=>'Manajemen'],
    'owner_exec'     => ['icon'=>rmi_icon('target'),'color'=>'#f59e0b','group'=>'Manajemen'],
    'branch'         => ['icon'=>rmi_icon('office'),'color'=>'#06b6d4','group'=>'Operasional'],
    'crm'            => ['icon'=>rmi_icon('money'),'color'=>'#3b82f6','group'=>'Operasional'],
    'mpr'            => ['icon'=>rmi_icon('memo'),'color'=>'#14b8a6','group'=>'Operasional'],
    'warehouse'      => ['icon'=>rmi_icon('box'),'color'=>'#f97316','group'=>'Operasional'],
    'procurement'    => ['icon'=>rmi_icon('cart'),'color'=>'#eab308','group'=>'Operasional'],
    'finance'        => ['icon'=>rmi_icon('money'),'color'=>'#22c55e','group'=>'Keuangan'],
    'finance_detail' => ['icon'=>rmi_icon('chart'),'color'=>'#10b981','group'=>'Keuangan'],
    'regulatory'     => ['icon'=>rmi_icon('office'),'color'=>'#ef4444','group'=>'Compliance'],
    'quality'        => ['icon'=>rmi_icon('check'),'color'=>'#84cc16','group'=>'Compliance'],
    'scm'            => ['icon'=>rmi_icon('box'),'color'=>'#a78bfa','group'=>'Operasional'],
    'hrl'            => ['icon'=>rmi_icon('users'),'color'=>'#ec4899','group'=>'SDM'],
    'employee_mutation'=> ['icon'=>rmi_icon('refresh'),'color'=>'#38bdf8','group'=>'SDM'],
    'itc'            => ['icon'=>rmi_icon('gear'),'color'=>'#64748b','group'=>'Sistem'],
    'act'            => ['icon'=>rmi_icon('memo'),'color'=>'#fb923c','group'=>'Keuangan'],
];

// Group cards
$grouped = [];
foreach ($visible_cards as $c) {
    $m = $card_meta[$c['key']] ?? ['icon'=>rmi_icon('clipboard'),'color'=>'#94a3b8','group'=>'Lainnya'];
    $c['icon']  = $m['icon'];
    $c['color'] = $m['color'];
    $c['group'] = $m['group'];
    $grouped[$m['group']][] = $c;
}
$group_order = ['Operasional','Keuangan','Manajemen','SDM','Compliance','Sistem','Lainnya'];
uksort($grouped, fn($a,$b) => (array_search($a,$group_order)??99) <=> (array_search($b,$group_order)??99));
?>

<?php
$extraHead = <<<'HTML'
  <style>
      :root{
  --glass-bg:rgba(255,255,255,0.07);
  --glass-brd:rgba(255,255,255,0.14);
  --text-primary:#eaf2ff;
  --text-muted:#8fa3c0;
  --radius-xl:20px;
}
      *{box-sizing:border-box}
      body{
  font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;color:var(--text-primary);
        background:
    radial-gradient(1000px 600px at 10% 10%,rgba(107,44,245,.4) 0%,transparent 60%),
    radial-gradient(800px 600px at 90% 15%,rgba(0,210,255,.4) 0%,transparent 60%),
    radial-gradient(900px 600px at 50% 95%,rgba(255,77,141,.35) 0%,transparent 60%),
    linear-gradient(135deg,#0f1528 0%,#080b18 100%);
        overflow-x:hidden;
      }

.dc-wrap{max-width:1060px;margin:0 auto;padding:24px 18px}

/* ── Greeting bar ─────────────────────── */
.dc-greeting{
  background:var(--glass-bg);border:1px solid var(--glass-brd);border-radius:18px;
  padding:20px 24px;margin-bottom:18px;
  backdrop-filter:blur(16px);
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px
}
.dc-greeting .g-left h1{margin:0;font-size:22px;font-weight:800;
  background:linear-gradient(90deg,#e8f1ff,#a0cfff);-webkit-background-clip:text;background-clip:text;color:transparent}
.dc-greeting .g-left p{margin:4px 0 0;font-size:12px;color:var(--text-muted)}
.dc-greeting .g-right{display:flex;flex-direction:column;align-items:flex-end;gap:4px}
.dc-clock{font-size:24px;font-weight:800;color:#67e8f9;font-variant-numeric:tabular-nums;letter-spacing:1px}
.dc-date{font-size:11px;color:var(--text-muted)}

/* ── Quick stats ─────────────────────── */
.dc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:18px}
.dc-stat{
  background:var(--glass-bg);border:1px solid var(--glass-brd);border-radius:14px;
  padding:14px 16px;backdrop-filter:blur(14px);text-decoration:none;color:inherit;
  transition:all .2s;display:flex;align-items:center;gap:12px
}
.dc-stat:hover{border-color:rgba(6,182,212,.4);transform:translateY(-2px);background:rgba(255,255,255,.1)}
.dc-stat .s-icon{font-size:24px;flex-shrink:0}
.dc-stat .s-val{font-size:20px;font-weight:800;line-height:1;color:#fff}
.dc-stat .s-lbl{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-top:2px}

/* ── Action bar ─────────────────────── */
.dc-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.dc-btn{
  padding:8px 16px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:700;
  border:1px solid var(--glass-brd);color:#e2e8f0;background:rgba(255,255,255,.07);
  transition:all .2s;display:inline-flex;align-items:center;gap:5px
}
.dc-btn:hover{background:rgba(255,255,255,.14);color:#fff;border-color:rgba(255,255,255,.3)}
.dc-btn.primary{
  background:linear-gradient(135deg,#6b2cf5,#00d2ff);border-color:transparent;
  box-shadow:0 6px 20px rgba(0,210,255,.3)
}
.dc-btn.primary:hover{filter:brightness(1.1);transform:translateY(-1px)}

/* ── Section label ─────────────────── */
.dc-section{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;
  color:var(--text-muted);margin:18px 0 10px;display:flex;align-items:center;gap:8px}
.dc-section::after{content:"";flex:1;height:1px;background:rgba(255,255,255,.08)}

/* ── Dashboard cards ─────────────────── */
.dc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:8px}
.dc-card{
  background:var(--glass-bg);border:1px solid var(--glass-brd);border-radius:16px;
  padding:18px;backdrop-filter:blur(16px);
  transition:all .25s;cursor:pointer;text-decoration:none;color:inherit;
  display:flex;flex-direction:column;gap:8px;
  position:relative;overflow:hidden
}
.dc-card::before{
  content:"";position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--card-color);border-radius:16px 16px 0 0;
  opacity:.8
}
.dc-card:hover{
  transform:translateY(-4px);
  border-color:var(--card-color);
  box-shadow:0 12px 32px rgba(0,0,0,.4),0 0 0 1px var(--card-color);
  background:rgba(255,255,255,.1)
}
.dc-card .c-icon{
  width:40px;height:40px;border-radius:10px;
  background:rgba(255,255,255,.08);
  display:flex;align-items:center;justify-content:center;font-size:20px
}
.dc-card .c-title{font-size:14px;font-weight:700;color:#f1f5f9;line-height:1.3}
.dc-card .c-desc{font-size:11px;color:var(--text-muted);line-height:1.5;flex:1}
.dc-card .c-open{
  display:inline-flex;align-items:center;gap:4px;
  font-size:11px;font-weight:700;color:var(--card-color);margin-top:4px
}

/* ── Empty state ─────────────────────── */
.dc-empty{
  grid-column:1/-1;background:var(--glass-bg);border:1px dashed var(--glass-brd);
  border-radius:16px;padding:32px;text-align:center;color:var(--text-muted)
}

/* ── Note ────────────────────────────── */
.dc-note{font-size:11px;color:var(--text-muted);margin-top:12px;padding:10px 14px;
  background:rgba(255,255,255,.04);border-radius:10px;border:1px solid var(--glass-brd)}

@media(max-width:600px){
  .dc-greeting{flex-direction:column}
  .dc-greeting .g-right{align-items:flex-start}
  .dc-grid{grid-template-columns:1fr 1fr}
      }
    </style>
HTML;

rmi_header('Dashboard Center', [
  'active'     => 'dashboard',
  'subtitle'   => 'Pusat navigasi dashboard ERP.',
  'extra_head' => $extraHead,
  'actions'    => [
    ['label' => rmi_icon('books').' Panduan', 'url' => dash_center_url('dashboards/panduan.php'), 'class' => 'btn btn-sm btn-outline-light'],
  ],
]);
?>

<div class="dc-wrap">

  <!-- Greeting -->
  <div class="dc-greeting">
    <div class="g-left">
      <h1><?=rmi_icon('users')?> Halo, <?= htmlspecialchars($fullname_dash ?: $username_dash ?: 'User') ?>!</h1>
      <p>
        <span style="color:#67e8f9;font-weight:600"><?= htmlspecialchars($role ?: '-') ?></span>
        &nbsp;·&nbsp; Dept: <span style="color:#a78bfa;font-weight:600"><?= htmlspecialchars($dept ?: '-') ?></span>
        &nbsp;·&nbsp; <?= htmlspecialchars(date('d F Y')) ?>
      </p>
    </div>
    <div class="g-right">
      <div class="dc-clock" id="dcClock">--:--:--</div>
      <div class="dc-date">Dashboard Center</div>
    </div>
  </div>

  <!-- Quick Stats -->
  <div class="dc-stats">
    <div class="dc-stat">
      <span class="s-icon"><?=rmi_icon('check')?></span>
      <div>
        <div class="s-val"><?= $stat_hadir ?></div>
        <div class="s-lbl">Hadir Hari Ini</div>
      </div>
    </div>
    <div class="dc-stat">
      <span class="s-icon"><?=rmi_icon('clipboard')?></span>
      <div>
        <div class="s-val"><?= $stat_order ?></div>
        <div class="s-lbl">Order Hari Ini</div>
      </div>
    </div>
    <div class="dc-stat">
      <span class="s-icon"><?=rmi_icon('money')?></span>
      <div>
        <div class="s-val"><?= $stat_pending_ap ?></div>
        <div class="s-lbl">AP Outstanding</div>
      </div>
    </div>
    <div class="dc-stat" style="cursor:pointer" onclick="location.href='<?= dash_center_url('absensi/admin/rekap.php') ?>'">
      <span class="s-icon"><?=rmi_icon('calendar')?></span>
      <div>
        <div class="s-val" style="font-size:14px;margin-top:2px">Rekap</div>
        <div class="s-lbl">Absensi HR</div>
      </div>
    </div>
    <div class="dc-stat" style="cursor:pointer" onclick="location.href='<?= dash_center_url('dashboards/finance/dashboard_detail.php?tab=exec') ?>'">
      <span class="s-icon"><?=rmi_icon('target')?></span>
      <div>
        <div class="s-val" style="font-size:14px;margin-top:2px">Exec</div>
        <div class="s-lbl">Summary</div>
      </div>
    </div>
  </div>

  <!-- Action Bar -->
  <div class="dc-actions">
    <a class="dc-btn" href="<?= dash_center_url('dashboards/panduan.php') ?>"><?=rmi_icon('books')?> Panduan</a>
    <?php if ($is_admin): ?>
      <a class="dc-btn primary" href="<?= erp_kpi_center_url() ?>"><?=rmi_icon('chart')?> KPI Center</a>
      <a class="dc-btn" href="<?= dash_center_url('dashboards/finance/dashboard_detail.php?tab=exec&refresh=60&kiosk=1') ?>"><?=rmi_icon('chart')?> Monitor Mode</a>
      <a class="dc-btn" href="<?= dash_center_url('master/master_system_login.php') ?>"><?=rmi_icon('user')?> Users</a>
      <a class="dc-btn" href="<?= dash_center_url('tools/rbac_center.php') ?>"><?=rmi_icon('gear')?> RBAC</a>
      <a class="dc-btn" href="<?= dash_center_url('master/master_system_config.php') ?>"><?=rmi_icon('gear')?> Config</a>
      <a class="dc-btn" href="<?= dash_center_url('tools/view_error_log.php') ?>"><?=rmi_icon('doc')?> Error Log</a>
      <a class="dc-btn" href="<?= dash_center_url('docs/link/officepack_portal.php') ?>"><?=rmi_icon('doc')?> Dokumen</a>
    <?php endif; ?>
    <?php if ($canFinanceQuickLink): ?>
      <a class="dc-btn" href="<?= dash_center_url('dashboards/finance/dashboard_detail.php') ?>"><?=rmi_icon('money')?> Finance Detail</a>
    <?php endif; ?>
  </div>

  <!-- Dashboard Cards by Group -->
    <?php if (empty($visible_cards)): ?>
    <div class="dc-grid">
      <div class="dc-empty">
        <div style="font-size:40px;margin-bottom:8px"><?=rmi_icon('gear')?></div>
        <h3 style="margin:0 0 6px;color:#f1f5f9">Belum ada akses dashboard</h3>
        <p style="margin:0">Dept <strong><?= htmlspecialchars($dept ?: '-') ?></strong> belum dipetakan. Hubungi Admin.</p>
      </div>
      </div>
    <?php else: ?>
    <?php foreach ($grouped as $grpName => $grpCards): ?>
      <div class="dc-section"><?= htmlspecialchars($grpName) ?></div>
      <div class="dc-grid">
        <?php foreach ($grpCards as $c): ?>
          <a class="dc-card" href="<?= htmlspecialchars($c['href']) ?>"
             style="--card-color:<?= htmlspecialchars($c['color']) ?>">
            <div class="c-icon" style="background:<?= htmlspecialchars($c['color']) ?>22">
              <?= $c['icon'] ?>
            </div>
            <div class="c-title"><?= htmlspecialchars($c['title']) ?></div>
            <div class="c-desc"><?= htmlspecialchars($c['desc']) ?></div>
            <div class="c-open">Buka →</div>
          </a>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  <div class="dc-note">
    <?=rmi_icon('zap')?> Akses tiap dashboard tetap di-enforce oleh RBAC. Menu ini hanya navigasi.
  </div>

</div>

<script>
(function(){
  function pad(n){return n<10?'0'+n:n}
  function tick(){
    var d=new Date();
    var el=document.getElementById('dcClock');
    if(el) el.textContent=pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
  }
  tick(); setInterval(tick,1000);
})();
</script>

<?php rmi_footer(); ?>
