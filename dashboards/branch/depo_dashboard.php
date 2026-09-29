<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_login();

$__guard = __DIR__ . '/../../_shared/rmi_branch_guard.php';
if (is_file($__guard)) require_once $__guard;
unset($__guard);

// Pakai helper office yang sama dengan WQS/SCM bila tersedia.
// Tujuannya: KAL/JGY memakai canonical/alias office yang sama dengan task operasional,
// tanpa mengubah workflow atau status apa pun.
$__officeHelper = __DIR__ . '/../../stock/_stock_office_helper.php';
if (is_file($__officeHelper)) require_once $__officeHelper;
unset($__officeHelper);

if (!function_exists('rmi_is_depo_branch_session') || !rmi_is_depo_branch_session()) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = db_pdo();
$user = function_exists('auth_user') ? (array)auth_user() : [];
$office = strtoupper(trim((string)($user['office_code'] ?? ($_SESSION['office_code'] ?? ''))));
$username = (string)($user['username'] ?? ($_SESSION['username'] ?? ''));

if ($office === '') {
    http_response_code(403);
    exit('Office akun Depo belum dikonfigurasi.');
}

if (!function_exists('bd_h')) {
    function bd_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Table check dibuat tanpa SHOW TABLES LIKE ? agar kompatibel di DB produksi.
 * Dashboard harus fail-soft di UI, tetapi tidak boleh membuat semua KPI nol hanya
 * karena driver tidak mendukung placeholder pada SHOW statement.
 */
function bd_table_exists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
            $st->execute([$table]);
            return ((int)$st->fetchColumn()) > 0;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function bd_cols(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $cache[$table] = array_map(static fn($r) => strtolower((string)($r['Field'] ?? '')), $rows);
    } catch (Throwable $e) { return $cache[$table] = []; }
}
function bd_has_col(PDO $pdo, string $table, string $col): bool {
    return in_array(strtolower($col), bd_cols($pdo, $table), true);
}
function bd_scalar(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : $v;
    } catch (Throwable $e) {
        // Jangan rusak halaman dashboard hanya karena satu KPI gagal.
        // Nilai default hanya fallback; query utama dibuat sama dengan scope operasional.
        return $default;
    }
}
function bd_money($v): string {
    $n = (float)$v;
    // Untuk dashboard operasional, tampilkan nominal aktual agar mudah rekonsiliasi dengan DO.
    return 'Rp ' . number_format($n, 0, ',', '.');
}
function bd_status_expr(PDO $pdo): string {
    return bd_has_col($pdo, 'sales_do', 'status') ? "LOWER(TRIM(COALESCE(d.status,'')))" : "''";
}

/**
 * Scope office tunggal yang mengikuti helper WQS/SCM.
 * Jika helper canonical tersedia, alias office (mis. kode/nama depo legacy) tetap terbaca.
 * Jika tidak tersedia, fallback exact office_code session.
 */
function bd_office_where(PDO $pdo, string $expr, string $office, array &$params): string {
    $office = strtoupper(trim($office));
    if (function_exists('rmi_office_in_sql')) {
        try {
            return rmi_office_in_sql($pdo, $expr, $office, $params);
        } catch (Throwable $e) {
            // jatuh ke exact match
        }
    }
    $params[] = $office;
    return "UPPER(TRIM(COALESCE({$expr},''))) = ?";
}

function bd_sales_base_where(PDO $pdo, array $dcols, string $office, array &$params): string {
    $parts = [bd_office_where($pdo, 'd.office_code', $office, $params)];
    if (in_array('deleted_at', $dcols, true)) $parts[] = 'd.deleted_at IS NULL';
    return implode(' AND ', $parts);
}

$monthStart = date('Y-m-01');
$today = date('Y-m-d');
$nextMonth = date('Y-m-01', strtotime('+1 month'));

$officeName = $office;
$officeCity = '';
if (bd_table_exists($pdo, 'master_office')) {
    try {
        $cols = bd_cols($pdo, 'master_office');
        $sel = ['office_code'];
        if (in_array('office_name', $cols, true)) $sel[] = 'office_name';
        if (in_array('city', $cols, true)) $sel[] = 'city';
        $st = $pdo->prepare('SELECT ' . implode(',', array_map(fn($c)=>"`{$c}`", $sel)) . ' FROM master_office WHERE UPPER(TRIM(office_code))=? LIMIT 1');
        $st->execute([$office]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $officeName = trim((string)($r['office_name'] ?? '')) ?: $office;
            $officeCity = trim((string)($r['city'] ?? ''));
        }
    } catch (Throwable $e) {}
}
if ($officeName === $office) {
    $fallbackName = ['KAL'=>'Depo Kalimantan', 'JGY'=>'Depo Yogyakarta'];
    $officeName = $fallbackName[$office] ?? ('Depo ' . $office);
}

$kpi = [
    'do_month' => 0,
    'sales_mtd' => 0.0,
    'do_active' => 0,
    'backlog' => 0,
    'exceptions' => 0,
    'wqs_pending' => 0,
    'delivery_active' => 0,
    'delivered_month' => 0,
    'mpr_visits' => 0,
    'mpr_pipeline' => 0,
    'mpr_won' => 0,
    'mpr_plans' => 0,
];

// --------------------------- SALES / DO ---------------------------
// Semua kartu DO/WQS/SCM memakai sales_do.office_code operasional,
// sama dengan halaman WQS/SCM. Tidak memakai office achievement/revenue.
if (bd_table_exists($pdo, 'sales_do')) {
    $dcols = bd_cols($pdo, 'sales_do');
    $dateCol = in_array('do_date', $dcols, true) ? 'do_date' : (in_array('created_at', $dcols, true) ? 'created_at' : null);
    $statusExpr = bd_status_expr($pdo);

    // Status terminal/arsip. Draft tidak dihitung sebagai DO operasional aktif.
    $terminal = ['delivered','wait_payment','paid','cancelled','canceled','cancel','rejected','reject','void','voided','inactive','deleted','archived'];
    $terminalSql = implode(',', array_fill(0, count($terminal), '?'));

    // DO BULAN INI = DO operasional office ini pada bulan berjalan, kecuali draft/batal/arsip.
    if ($dateCol !== null) {
        $params = [];
        $where = bd_sales_base_where($pdo, $dcols, $office, $params);
        $sql = "SELECT COUNT(*) FROM sales_do d WHERE {$where} AND DATE(d.`{$dateCol}`) >= ? AND DATE(d.`{$dateCol}`) < ? AND {$statusExpr} NOT IN ('draft','cancelled','canceled','cancel','rejected','reject','void','voided','inactive','deleted','archived')";
        $params[] = $monthStart; $params[] = $nextMonth;
        $kpi['do_month'] = (int)bd_scalar($pdo, $sql, $params, 0);
    }

    // DO AKTIF/BACKLOG = alur operasional yang belum terminal.
    $params = [];
    $where = bd_sales_base_where($pdo, $dcols, $office, $params);
    $sql = "SELECT COUNT(*) FROM sales_do d WHERE {$where} AND {$statusExpr} <> 'draft' AND {$statusExpr} NOT IN ({$terminalSql})";
    $params = array_merge($params, $terminal);
    $kpi['do_active'] = (int)bd_scalar($pdo, $sql, $params, 0);
    $kpi['backlog'] = $kpi['do_active'];

    // WQS PENDING mengikuti antrean WQS yang benar-benar masih membutuhkan tindakan WQS.
    // READY_SCM sudah handoff ke SCM sehingga tidak dihitung sebagai pending WQS.
    $params = [];
    $where = bd_sales_base_where($pdo, $dcols, $office, $params);
    $wqsActive = ['crm_to_wqs','sent_wqs','revision_requested','wqs_processing'];
    $ph = implode(',', array_fill(0, count($wqsActive), '?'));
    $sql = "SELECT COUNT(*) FROM sales_do d WHERE {$where} AND {$statusExpr} IN ({$ph})";
    $params = array_merge($params, $wqsActive);
    $kpi['wqs_pending'] = (int)bd_scalar($pdo, $sql, $params, 0);

    // DELIVERY ACTIVE mengikuti halaman SCM: READY SCM / ON DELIVERY.
    $params = [];
    $where = bd_sales_base_where($pdo, $dcols, $office, $params);
    $deliveryStatuses = ['ready_scm','on_delivery'];
    $ph = implode(',', array_fill(0, count($deliveryStatuses), '?'));
    $sql = "SELECT COUNT(*) FROM sales_do d WHERE {$where} AND {$statusExpr} IN ({$ph})";
    $params = array_merge($params, $deliveryStatuses);
    $kpi['delivery_active'] = (int)bd_scalar($pdo, $sql, $params, 0);

    // DELIVERED BULAN INI.
    $deliveredDateCol = in_array('scm_delivered_at', $dcols, true) ? 'scm_delivered_at' : $dateCol;
    if ($deliveredDateCol !== null) {
        $params = [];
        $where = bd_sales_base_where($pdo, $dcols, $office, $params);
        $sql = "SELECT COUNT(*) FROM sales_do d WHERE {$where} AND {$statusExpr} IN ('delivered','wait_payment','paid') AND DATE(d.`{$deliveredDateCol}`) >= ? AND DATE(d.`{$deliveredDateCol}`) < ?";
        $params[] = $monthStart; $params[] = $nextMonth;
        $kpi['delivered_month'] = (int)bd_scalar($pdo, $sql, $params, 0);
    }

    // NILAI DO MTD = nilai DO operasional bulan berjalan office tersebut.
    // Ini bukan revenue achievement; pencapaian tetap halaman terpisah.
    $amountCol = in_array('grand_total', $dcols, true) ? 'grand_total' : (in_array('total_amount', $dcols, true) ? 'total_amount' : null);
    if ($amountCol !== null && $dateCol !== null) {
        $params = [];
        $where = bd_sales_base_where($pdo, $dcols, $office, $params);
        $sql = "SELECT COALESCE(SUM(COALESCE(d.`{$amountCol}`,0)),0) FROM sales_do d WHERE {$where} AND DATE(d.`{$dateCol}`) >= ? AND DATE(d.`{$dateCol}`) < ? AND {$statusExpr} NOT IN ('draft','cancelled','canceled','cancel','rejected','reject','void','voided','inactive','deleted','archived')";
        $params[] = $monthStart; $params[] = $nextMonth;
        $kpi['sales_mtd'] = (float)bd_scalar($pdo, $sql, $params, 0.0);
    }

    // EXCEPTION > 3 HARI = DO aktif yang belum terminal dan terakhir berubah > 3 hari.
    $ageCol = null;
    foreach (['last_updated_at','updated_at','created_at','do_date'] as $c) {
        if (in_array($c, $dcols, true)) { $ageCol = $c; break; }
    }
    if ($ageCol !== null) {
        $params = [];
        $where = bd_sales_base_where($pdo, $dcols, $office, $params);
        $sql = "SELECT COUNT(*) FROM sales_do d WHERE {$where} AND {$statusExpr} <> 'draft' AND {$statusExpr} NOT IN ({$terminalSql}) AND d.`{$ageCol}` < DATE_SUB(NOW(), INTERVAL 3 DAY)";
        $params = array_merge($params, $terminal);
        $kpi['exceptions'] = (int)bd_scalar($pdo, $sql, $params, 0);
    }
}

// --------------------------- MPR ---------------------------
// MPR tetap scope office akun. KAL tidak pernah menjumlah JGY dan sebaliknya.
if (bd_table_exists($pdo, 'mpr_plans')) {
    $pcols = bd_cols($pdo, 'mpr_plans');
    $params = [];
    $where = bd_office_where($pdo, 'p.office_code', $office, $params);
    if (in_array('deleted_at', $pcols, true)) $where .= ' AND p.deleted_at IS NULL';
    if (in_array('status', $pcols, true)) $where .= " AND LOWER(TRIM(COALESCE(p.status,''))) NOT IN ('cancelled','canceled','rejected','closed','archived')";
    $kpi['mpr_plans'] = (int)bd_scalar($pdo, "SELECT COUNT(*) FROM mpr_plans p WHERE {$where}", $params, 0);
}

if (bd_table_exists($pdo, 'mpr_visits') && bd_table_exists($pdo, 'mpr_plans')) {
    $vcols = bd_cols($pdo, 'mpr_visits');
    $pcols = bd_cols($pdo, 'mpr_plans');
    $visitDateCol = in_array('visit_date', $vcols, true) ? 'visit_date' : (in_array('created_at', $vcols, true) ? 'created_at' : null);
    if ($visitDateCol !== null) {
        $params = [];
        $where = bd_office_where($pdo, 'p.office_code', $office, $params);
        $where .= " AND DATE(v.`{$visitDateCol}`) >= ? AND DATE(v.`{$visitDateCol}`) < ?";
        $params[] = $monthStart; $params[] = $nextMonth;
        if (in_array('deleted_at', $vcols, true)) $where .= ' AND v.deleted_at IS NULL';
        if (in_array('deleted_at', $pcols, true)) $where .= ' AND p.deleted_at IS NULL';
        $kpi['mpr_visits'] = (int)bd_scalar($pdo,
            "SELECT COUNT(*) FROM mpr_visits v JOIN mpr_plans p ON p.id=v.plan_id WHERE {$where}",
            $params, 0);
    }
}

if (bd_table_exists($pdo, 'mpr_pipeline')) {
    $pcols = bd_cols($pdo, 'mpr_pipeline');
    $paramsBase = [];
    $base = bd_office_where($pdo, 'p.office_code', $office, $paramsBase);
    if (in_array('deleted_at', $pcols, true)) $base .= ' AND p.deleted_at IS NULL';
    if (in_array('stage', $pcols, true)) {
        $kpi['mpr_pipeline'] = (int)bd_scalar($pdo,
            "SELECT COUNT(*) FROM mpr_pipeline p WHERE {$base} AND UPPER(TRIM(COALESCE(p.stage,''))) NOT IN ('WON','LOST')",
            $paramsBase, 0);

        $wonDateCol = in_array('converted_at', $pcols, true) ? 'converted_at' : (in_array('updated_at', $pcols, true) ? 'updated_at' : (in_array('created_at', $pcols, true) ? 'created_at' : null));
        if ($wonDateCol !== null) {
            $paramsWon = [];
            $wonBase = bd_office_where($pdo, 'p.office_code', $office, $paramsWon);
            if (in_array('deleted_at', $pcols, true)) $wonBase .= ' AND p.deleted_at IS NULL';
            $sql = "SELECT COUNT(*) FROM mpr_pipeline p WHERE {$wonBase} AND UPPER(TRIM(COALESCE(p.stage,'')))='WON' AND DATE(p.`{$wonDateCol}`) >= ? AND DATE(p.`{$wonDateCol}`) < ?";
            $paramsWon[] = $monthStart; $paramsWon[] = $nextMonth;
            $kpi['mpr_won'] = (int)bd_scalar($pdo, $sql, $paramsWon, 0);
        }
    }
}

require_once __DIR__ . '/../../_shared/rmi_layout.php';
$bp = rmi_layout_base_project();

$cardsTop = [
    ['label'=>'SCOPE','value'=>$office,'sub'=>$officeName,'accent'=>'#38bdf8','url'=>''],
    ['label'=>'BACKLOG OPEN','value'=>(string)$kpi['backlog'],'sub'=>'DO operasional belum selesai','accent'=>'#f59e0b','url'=>$bp.'/sales/sales_do.php'],
    ['label'=>'EXCEPTIONS','value'=>(string)$kpi['exceptions'],'sub'=>'Task aktif > 3 hari','accent'=>'#ef4444','url'=>$bp.'/sales/sales_do.php'],
    ['label'=>'DELIVERY ACTIVE','value'=>(string)$kpi['delivery_active'],'sub'=>'READY SCM / ON DELIVERY','accent'=>'#22d3ee','url'=>$bp.'/sales/scm_do_tasks.php'],
    ['label'=>'WQS PENDING','value'=>(string)$kpi['wqs_pending'],'sub'=>'Task WQS perlu tindakan','accent'=>'#a78bfa','url'=>$bp.'/stock/wqs_do_tasks.php'],
    ['label'=>'MPR PIPELINE','value'=>(string)$kpi['mpr_pipeline'],'sub'=>'Prospek aktif office ini','accent'=>'#8b5cf6','url'=>$bp.'/mpr/mpr_pipeline.php'],
];

$cardsMain = [
    ['icon'=>rmi_icon('clipboard'),'value'=>(string)$kpi['do_month'],'label'=>'DO BULAN INI','accent'=>'#3b82f6','url'=>$bp.'/sales/sales_do.php'],
    ['icon'=>rmi_icon('money'),'value'=>bd_money($kpi['sales_mtd']),'label'=>'NILAI DO MTD','accent'=>'#22c55e','url'=>$bp.'/dashboards/finance/dashboard_detail.php'],
    ['icon'=>rmi_icon('refresh'),'value'=>(string)$kpi['do_active'],'label'=>'DO AKTIF / BELUM SELESAI','accent'=>'#f59e0b','url'=>$bp.'/sales/sales_do.php'],
    ['icon'=>rmi_icon('check'),'value'=>(string)$kpi['delivered_month'],'label'=>'DELIVERED BULAN INI','accent'=>'#10b981','url'=>$bp.'/sales/scm_do_tasks.php'],
    ['icon'=>rmi_icon('target'),'value'=>(string)$kpi['mpr_visits'],'label'=>'KUNJUNGAN MPR BULAN INI','accent'=>'#06b6d4','url'=>$bp.'/mpr/mpr_visits.php'],
    ['icon'=>rmi_icon('target'),'value'=>(string)$kpi['mpr_pipeline'],'label'=>'PIPELINE AKTIF','accent'=>'#8b5cf6','url'=>$bp.'/mpr/mpr_pipeline.php'],
    ['icon'=>rmi_icon('target'),'value'=>(string)$kpi['mpr_won'],'label'=>'DEAL WON BULAN INI','accent'=>'#22c55e','url'=>$bp.'/mpr/mpr_pipeline.php'],
    ['icon'=>rmi_icon('search'),'value'=>(string)$kpi['mpr_plans'],'label'=>'PLAN MPR AKTIF','accent'=>'#6366f1','url'=>$bp.'/mpr/mpr_plans.php'],
];

$actions = [
    ['label'=>'Pencapaian','url'=>$bp.'/dashboards/finance/dashboard_detail.php','class'=>'btn btn-sm btn-outline-light'],
    ['label'=>'Delivery Order','url'=>$bp.'/sales/sales_do.php','class'=>'btn btn-sm btn-outline-light'],
    ['label'=>'MPR','url'=>$bp.'/mpr/mpr_dashboard.php','class'=>'btn btn-sm btn-outline-light'],
];

$extraHead = <<<'CSS'
<style>
.depo-home{max-width:1240px;margin:0 auto}
.depo-hero{background:linear-gradient(110deg,#263f8f,#165b67);border:1px solid rgba(255,255,255,.08);border-radius:0 0 22px 22px;padding:18px 28px;margin:-16px -16px 20px;color:#fff;display:flex;justify-content:space-between;gap:20px;align-items:center;box-shadow:0 18px 40px rgba(0,0,0,.16)}
.depo-hero-title{font-size:26px;font-weight:900;letter-spacing:.2px}.depo-hero-sub{opacity:.8;font-size:13px;margin-top:3px}.depo-clock{text-align:right;min-width:150px}.depo-clock .time{font-size:30px;font-weight:900;color:#67e8f9}.depo-clock .lbl{font-size:12px;opacity:.8}
.depo-panel{background:linear-gradient(135deg,rgba(30,41,59,.82),rgba(17,24,39,.96));border:1px solid rgba(148,163,184,.16);border-radius:18px;padding:16px;margin-bottom:18px;box-shadow:0 18px 44px rgba(0,0,0,.12)}
.depo-panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}.depo-office-title{font-weight:800;font-size:18px}.depo-badge{background:rgba(14,165,233,.13);color:#67e8f9;border:1px solid rgba(103,232,249,.15);font-weight:800;border-radius:999px;padding:5px 11px;font-size:12px}
.depo-top-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}.depo-main-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-top:18px}
.depo-kpi,.depo-main-card{position:relative;background:#111827;border:1px solid rgba(148,163,184,.16);border-top:3px solid var(--accent);border-radius:14px;padding:15px;min-height:132px;overflow:hidden}.depo-kpi{min-height:150px}.depo-kpi:hover,.depo-main-card:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(0,0,0,.16)}
.depo-kpi .label,.depo-main-card .label{font-size:11px;letter-spacing:.5px;color:#7890b7;text-transform:uppercase}.depo-kpi .value{font-size:26px;font-weight:900;color:#f8fafc;margin-top:6px;line-height:1.05}.depo-kpi .sub{font-size:11px;color:#7f93b5;margin-top:10px;line-height:1.35}.depo-main-card .icon{font-size:26px}.depo-main-card .value{font-size:24px;font-weight:900;color:#fff;margin-top:6px;line-height:1.05}.depo-main-card .label{margin-top:7px}.depo-link{color:inherit;text-decoration:none;display:block;height:100%}
.depo-quick{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.depo-q{display:flex;align-items:center;gap:10px;padding:13px 14px;border-radius:12px;background:#111827;border:1px solid rgba(148,163,184,.14);color:#dbeafe;text-decoration:none}.depo-q:hover{border-color:rgba(96,165,250,.45);color:#fff}.depo-q span{font-size:22px}
@media(max-width:1200px){.depo-top-grid,.depo-main-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.depo-quick{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:720px){.depo-hero{margin:-12px -12px 14px;padding:16px;border-radius:0 0 16px 16px}.depo-hero-title{font-size:20px}.depo-clock .time{font-size:22px}.depo-top-grid,.depo-main-grid,.depo-quick{grid-template-columns:1fr 1fr}.depo-kpi,.depo-main-card{min-height:120px}}
@media(max-width:480px){.depo-top-grid,.depo-main-grid,.depo-quick{grid-template-columns:1fr}.depo-hero{align-items:flex-start}.depo-clock{min-width:auto}.depo-kpi{min-height:108px}}
</style>
CSS;

rmi_header('Depo Dashboard', 'depo_home', [
    'subtitle' => 'Operasional harian — hanya data office ' . $office,
    'breadcrumbs' => ['Depo', 'Dashboard'],
    'actions' => $actions,
    'extra_head' => $extraHead,
]);
?>
<div class="depo-home">
  <div class="depo-hero">
    <div>
      <div class="depo-hero-title"><?=rmi_icon('office')?> <?= bd_h($officeName) ?></div>
      <div class="depo-hero-sub">ERP RMI &nbsp;•&nbsp; <?= bd_h(date('l, d F Y')) ?> &nbsp;•&nbsp; Kode: <strong><?= bd_h($office) ?></strong><?php if($officeCity!==''): ?> &nbsp;•&nbsp; <?= bd_h($officeCity) ?><?php endif; ?></div>
    </div>
    <div class="depo-clock"><div class="time" id="depoClock"><?= bd_h(date('H:i:s')) ?></div><div class="lbl">Waktu Server</div></div>
  </div>

  <div class="depo-panel">
    <div class="depo-panel-head">
      <div><div class="depo-office-title"><?=rmi_icon('office')?> <?= bd_h($officeName) ?></div><div class="small text-secondary">Scope ketat office <?= bd_h($office) ?> • login <?= bd_h($username) ?></div></div>
      <div class="depo-badge"><?= bd_h($office) ?></div>
    </div>

    <div class="depo-top-grid">
      <?php foreach ($cardsTop as $c): ?>
        <div class="depo-kpi" style="--accent:<?= bd_h($c['accent']) ?>">
          <?php if($c['url']!==''): ?><a class="depo-link" href="<?= bd_h($c['url']) ?>"><?php endif; ?>
          <div class="label"><?= bd_h($c['label']) ?></div>
          <div class="value"><?= bd_h($c['value']) ?></div>
          <div class="sub"><?= bd_h($c['sub']) ?></div>
          <?php if($c['url']!==''): ?></a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="depo-main-grid">
      <?php foreach ($cardsMain as $c): ?>
        <div class="depo-main-card" style="--accent:<?= bd_h($c['accent']) ?>">
          <a class="depo-link" href="<?= bd_h($c['url']) ?>">
            <div class="icon"><?= bd_h($c['icon']) ?></div>
            <div class="value"><?= bd_h($c['value']) ?></div>
            <div class="label"><?= bd_h($c['label']) ?></div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="depo-panel">
    <div class="depo-panel-head"><div class="depo-office-title">Akses Cepat</div><div class="small text-secondary">Semua link tetap mengikuti guard dan scope office</div></div>
    <div class="depo-quick">
      <a class="depo-q" href="<?= bd_h($bp) ?>/dashboards/finance/dashboard_detail.php"><span><?=rmi_icon('target')?></span><div><strong>Pencapaian</strong><div class="small text-secondary">Target vs realisasi <?= bd_h($office) ?></div></div></a>
      <a class="depo-q" href="<?= bd_h($bp) ?>/sales/sales_do.php"><span><?=rmi_icon('clipboard')?></span><div><strong>Delivery Order</strong><div class="small text-secondary">DO office <?= bd_h($office) ?></div></div></a>
      <a class="depo-q" href="<?= bd_h($bp) ?>/stock/wqs_do_tasks.php"><span><?=rmi_icon('zap')?></span><div><strong>Task WQS</strong><div class="small text-secondary">Proses DO office sendiri</div></div></a>
      <a class="depo-q" href="<?= bd_h($bp) ?>/sales/scm_do_tasks.php"><span><?=rmi_icon('box')?></span><div><strong>Task SCM</strong><div class="small text-secondary">Delivery office sendiri</div></div></a>
      <a class="depo-q" href="<?= bd_h($bp) ?>/mpr/mpr_dashboard.php"><span><?=rmi_icon('gear')?></span><div><strong>MPR Dashboard</strong><div class="small text-secondary">Ringkasan aktivitas MPR</div></div></a>
      <a class="depo-q" href="<?= bd_h($bp) ?>/mpr/mpr_plans.php"><span><?=rmi_icon('search')?></span><div><strong>MPR Plans</strong><div class="small text-secondary">Plan office <?= bd_h($office) ?></div></div></a>
      <a class="depo-q" href="<?= bd_h($bp) ?>/mpr/mpr_visits.php"><span><?=rmi_icon('target')?></span><div><strong>Kunjungan</strong><div class="small text-secondary">GPS + foto kunjungan</div></div></a>
      <a class="depo-q" href="<?= bd_h($bp) ?>/mpr/mpr_pipeline.php"><span><?=rmi_icon('target')?></span><div><strong>Pipeline</strong><div class="small text-secondary">Prospek & deal office</div></div></a>
    </div>
  </div>
</div>
<script>
(function(){
  var el=document.getElementById('depoClock');
  if(!el) return;
  var parts=el.textContent.split(':').map(Number); if(parts.length!==3) return;
  var s=parts[0]*3600+parts[1]*60+parts[2];
  setInterval(function(){s=(s+1)%86400;var h=Math.floor(s/3600),m=Math.floor((s%3600)/60),ss=s%60;el.textContent=[h,m,ss].map(function(v){return String(v).padStart(2,'0')}).join(':')},1000);
})();
</script>
<?php rmi_footer(); ?>
