<?php
// --- Auth guard ---
$__rmi_guard_dir = __DIR__;
for ($__rmi_guard_i = 0; $__rmi_guard_i < 5; $__rmi_guard_i++) {
    $__rmi_guard_auth = $__rmi_guard_dir . '/master/auth.php';
    if (file_exists($__rmi_guard_auth)) { require_once $__rmi_guard_auth; if (function_exists('require_login')) require_login(); break; }
    $__rmi_guard_parent = dirname($__rmi_guard_dir);
    if ($__rmi_guard_parent === $__rmi_guard_dir) break;
    $__rmi_guard_dir = $__rmi_guard_parent;
}
unset($__rmi_guard_dir,$__rmi_guard_i,$__rmi_guard_auth,$__rmi_guard_parent);
// --- /Auth guard ---

require_once __DIR__ . '/../dashboards/_manager_scope.php';
require_once __DIR__ . '/_layout_top.php';
require_once __DIR__ . '/../_shared/rmi_branch_guard.php';
$MPR_DEPO_RESTRICTED = function_exists('rmi_is_depo_branch_session') && rmi_is_depo_branch_session();

$scope_office = $MPR_IS_ADMIN ? null : $MPR_USER['office_code'];
$scope_dept   = $MPR_IS_ADMIN ? null : $MPR_USER['department'];

$f_year  = (int)($_GET['year']  ?? date('Y'));
$f_month = (int)($_GET['month'] ?? (int)date('m'));
$f_year  = ($f_year<2020||$f_year>2040) ? (int)date('Y') : $f_year;
$f_month = ($f_month<1||$f_month>12) ? (int)date('m') : $f_month;
$ym      = sprintf('%04d-%02d', $f_year, $f_month);
$today   = date('Y-m-d');

$months  = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$mprBase = rtrim(mpr_guess_base_project(), '/');

function db_scope(array &$p, ?string $office, ?string $dept=null, string $o_col='office_code', string $d_col='dept_code'): string {
    $w='1=1';
    if ($office) { $w.=" AND {$o_col}=?"; $p[]=$office; }
    if ($dept)   { $w.=" AND {$d_col}=?"; $p[]=$dept; }
    return $w;
}

// ============================================================
// DATA FETCH
// ============================================================
$data = [
    'funnel'         => [], // stage => count
    'visits_total'   => 0,
    'visits_new'     => 0,
    'visits_today'   => 0,
    'deals_won'      => 0,
    'conversion'     => 0,
    'pipeline_active'=> 0,
    'pipeline_value' => 0.0,
    'budget_pending' => 0,
    'customers_active'=> 0,
    'customers_new_m' => 0,
    'followup_today'  => [],   // preview kunjungan follow-up due/overdue
    'followup_total'  => 0,    // total sebenarnya, tidak terpotong LIMIT preview
    'leaderboard'     => [],   // per user: visits, new, deals, target
    'tren_months'     => [],   // chart tren
    'alerts'          => [],
];

try {
    // --- FUNNEL (pipeline stages) ---
    $pp=[]; $pw=db_scope($pp,$scope_office,null,'office_code');
    $pw.=' AND deleted_at IS NULL';
    $stF=$pdo->prepare("SELECT stage,COUNT(*) c FROM mpr_pipeline WHERE {$pw} GROUP BY stage");
    $stF->execute($pp);
    $stageCounts=[];
    foreach ($stF->fetchAll() as $r) $stageCounts[strtoupper((string)$r['stage'])]=(int)$r['c'];
    $data['funnel'] = $stageCounts;
    $data['pipeline_active'] = array_sum(array_filter($stageCounts, fn($c,$s)=>!in_array($s,['WON','LOST'],true), ARRAY_FILTER_USE_BOTH));

    // Pipeline value
    $pvp=[]; $pvw=db_scope($pvp,$scope_office,null,'office_code');
    $pvw.=" AND deleted_at IS NULL AND stage NOT IN ('WON','LOST')";
    $stPV=$pdo->prepare("SELECT COALESCE(SUM(est_deal_value),0) FROM mpr_pipeline WHERE {$pvw}");
    $stPV->execute($pvp);
    $data['pipeline_value']=(float)$stPV->fetchColumn();

    // --- VISITS this month ---
    $vp=[]; $vw='v.deleted_at IS NULL';
    if ($scope_office) { $vw.=' AND p.office_code=?'; $vp[]=$scope_office; }
    $vp[]=$ym;
    $stVB=$pdo->prepare("SELECT v.visit_type,v.outcome,v.visit_date,v.visitor_username,v.visitor_level,v.next_followup_date,v.customer_name,v.plan_id FROM mpr_visits v JOIN mpr_plans p ON p.id=v.plan_id WHERE {$vw} AND DATE_FORMAT(v.visit_date,'%Y-%m')=?");
    $stVB->execute($vp);
    $allVisits=$stVB->fetchAll();
    $data['visits_total']=count($allVisits);
    $data['visits_new']  =count(array_filter($allVisits,fn($r)=>$r['visit_type']==='NEW_PROSPECT'));
    $data['visits_today']=count(array_filter($allVisits,fn($r)=>(string)$r['visit_date']===$today));
    $data['deals_won']   =count(array_filter($allVisits,fn($r)=>$r['outcome']==='DEAL_WON'));
    $data['conversion']  =$data['visits_total']>0?round($data['deals_won']/$data['visits_total']*100):0;

    // --- FOLLOW-UP OVERDUE / today ---
    // Hitung TOTAL untuk KPI, lalu ambil maksimal 20 baris hanya untuk preview panel.
    $fupBase=[]; $fuw='v.deleted_at IS NULL AND v.next_followup_date IS NOT NULL AND v.outcome NOT IN (\'DEAL_WON\',\'DEAL_LOST\',\'NOT_INTERESTED\')';
    if ($scope_office) { $fuw.=' AND p.office_code=?'; $fupBase[]=$scope_office; }
    $fuw.=' AND v.next_followup_date <= ?'; $fupBase[]=$today;

    $stFUC=$pdo->prepare("SELECT COUNT(*) FROM mpr_visits v JOIN mpr_plans p ON p.id=v.plan_id WHERE {$fuw}");
    $stFUC->execute($fupBase);
    $data['followup_total']=(int)$stFUC->fetchColumn();

    $stFU=$pdo->prepare("SELECT v.id,v.customer_name,v.next_followup_date,v.visitor_username,v.visitor_level,v.outcome FROM mpr_visits v JOIN mpr_plans p ON p.id=v.plan_id WHERE {$fuw} ORDER BY v.next_followup_date ASC LIMIT 20");
    $stFU->execute($fupBase);
    $data['followup_today']=$stFU->fetchAll();

    // --- LEADERBOARD (staff + manager semua diukur sama) ---
    $lp=[]; $lw='v.deleted_at IS NULL';
    if ($scope_office) { $lw.=' AND p.office_code=?'; $lp[]=$scope_office; }
    $lp[]=$ym;
    $stL=$pdo->prepare("
        SELECT v.visitor_username, v.visitor_level,
               COUNT(*) total_visits,
               SUM(CASE WHEN v.visit_type='NEW_PROSPECT' THEN 1 ELSE 0 END) new_prospects,
               SUM(CASE WHEN v.outcome='DEAL_WON' THEN 1 ELSE 0 END) deals_won,
               SUM(CASE WHEN v.visit_date=CURDATE() THEN 1 ELSE 0 END) visits_today
        FROM mpr_visits v JOIN mpr_plans p ON p.id=v.plan_id
        WHERE {$lw} AND DATE_FORMAT(v.visit_date,'%Y-%m')=?
        GROUP BY v.visitor_username,v.visitor_level
        ORDER BY new_prospects DESC, total_visits DESC
        LIMIT 20
    ");
    $stL->execute($lp);
    $lb=$stL->fetchAll();

    // Load targets for this month
    $targetMap=[];
    if (!empty($lb)) {
        $users=array_column($lb,'visitor_username');
        $in=implode(',',array_fill(0,count($users),'?'));
        $tp=array_merge([$ym],$users);
        $stT=$pdo->prepare("SELECT username,target_visits,target_new_prospects,target_closings FROM mpr_targets WHERE `year_month`=? AND username IN ({$in})");
        $stT->execute($tp);
        foreach ($stT->fetchAll() as $t) $targetMap[$t['username']]=$t;
    }
    foreach ($lb as &$row) {
        $t=$targetMap[$row['visitor_username']]??null;
        $row['target_visits']  =$t?(int)$t['target_visits']:0;
        $row['target_new']     =$t?(int)$t['target_new_prospects']:0;
        $row['target_closing'] =$t?(int)$t['target_closings']:0;
    }
    unset($row);
    $data['leaderboard']=$lb;

    // --- TREN: visits + new prospects per month (6 months) ---
    // Periode tren mengikuti bulan filter dashboard, bukan tanggal server.
    $trendEnd = new DateTimeImmutable(sprintf('%04d-%02d-01',$f_year,$f_month));
    $trendStart = $trendEnd->modify('-5 months');
    $trendStartDate = $trendStart->format('Y-m-01');
    $trendEndDate   = $trendEnd->modify('+1 month')->format('Y-m-01');

    $tp2=[]; $tw2='v.deleted_at IS NULL';
    if ($scope_office) { $tw2.=' AND p.office_code=?'; $tp2[]=$scope_office; }
    $tp2[]=$trendStartDate; $tp2[]=$trendEndDate;
    $stTr=$pdo->prepare("
        SELECT DATE_FORMAT(v.visit_date,'%Y-%m') mon,
               COUNT(*) total,
               SUM(CASE WHEN v.visit_type='NEW_PROSPECT' THEN 1 ELSE 0 END) new_p,
               SUM(CASE WHEN v.outcome='DEAL_WON' THEN 1 ELSE 0 END) deals
        FROM mpr_visits v JOIN mpr_plans p ON p.id=v.plan_id
        WHERE {$tw2} AND v.visit_date >= ? AND v.visit_date < ?
        GROUP BY mon ORDER BY mon
    " );
    $stTr->execute($tp2);
    $trenRaw=$stTr->fetchAll();
    $trenMap=[];
    foreach ($trenRaw as $r) $trenMap[$r['mon']]=$r;
    for ($i=5;$i>=0;$i--) {
        $k=$trendEnd->modify("-{$i} months")->format('Y-m');
        $data['tren_months'][$k]=['total'=>(int)($trenMap[$k]['total']??0),'new_p'=>(int)($trenMap[$k]['new_p']??0),'deals'=>(int)($trenMap[$k]['deals']??0)];
    }

    // --- Customers ---
    try {
        /*
         * OFFICE SCOPE CUSTOMER KPI
         * -------------------------
         * master_customers adalah master lintas perusahaan. Untuk user non-admin yang
         * mempunyai office scope (terutama Branch Depo KAL/JGY), KPI Customer Aktif dan
         * Customer Baru tidak boleh memakai agregat company-wide.
         *
         * Prinsip fail-closed khusus Depo:
         * - bila office_code tersedia -> hitung office session saja;
         * - bila schema legacy tidak punya office_code -> tampilkan 0, jangan bocorkan total global;
         * - user internal existing tetap memakai fallback lama bila memang tidak punya office scope.
         */
        $custCols = [];
        try {
            $stCols = $pdo->query("SHOW COLUMNS FROM master_customers");
            foreach (($stCols ? $stCols->fetchAll(PDO::FETCH_ASSOC) : []) as $cr) {
                $cf = strtolower(trim((string)($cr['Field'] ?? '')));
                if ($cf !== '') $custCols[$cf] = true;
            }
        } catch (Throwable $e) { $custCols = []; }

        $custHasOffice   = isset($custCols['office_code']);
        $custHasStatus   = isset($custCols['status']);
        $custHasCreated  = isset($custCols['created_at']);
        $custOfficeScope = strtoupper(trim((string)($scope_office ?? '')));

        if ($custOfficeScope !== '' && $custHasOffice) {
            $whereActive = $custHasStatus
                ? "LOWER(TRIM(COALESCE(status,'')))='active'"
                : "1=1";
            $stC = $pdo->prepare("SELECT COUNT(*) FROM master_customers WHERE {$whereActive} AND UPPER(TRIM(COALESCE(office_code,'')))=?");
            $stC->execute([$custOfficeScope]);
            $data['customers_active'] = (int)$stC->fetchColumn();

            if ($custHasCreated) {
                $stN = $pdo->prepare("SELECT COUNT(*) FROM master_customers WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND UPPER(TRIM(COALESCE(office_code,'')))=?");
                $stN->execute([$ym, $custOfficeScope]);
                $data['customers_new_m'] = (int)$stN->fetchColumn();
            } else {
                $data['customers_new_m'] = 0;
            }
        } elseif ($MPR_DEPO_RESTRICTED) {
            // Fail-closed: Depo tidak boleh melihat KPI customer perusahaan bila schema tidak bisa di-scope.
            $data['customers_active'] = 0;
            $data['customers_new_m']  = 0;
        } else {
            // Pertahankan alur existing untuk user internal yang tidak memiliki office scope yang dapat dipakai.
            $whereActive = $custHasStatus
                ? "LOWER(TRIM(COALESCE(status,'')))='active'"
                : "1=1";
            $data['customers_active'] = (int)$pdo->query("SELECT COUNT(*) FROM master_customers WHERE {$whereActive}")->fetchColumn();
            if ($custHasCreated) {
                $stN = $pdo->prepare("SELECT COUNT(*) FROM master_customers WHERE DATE_FORMAT(created_at,'%Y-%m')=?");
                $stN->execute([$ym]);
                $data['customers_new_m'] = (int)$stN->fetchColumn();
            }
        }
    } catch (Throwable $e) {
        // Dashboard harus tetap hidup. Khusus Depo, error customer KPI tetap fail-closed.
        if ($MPR_DEPO_RESTRICTED) {
            $data['customers_active'] = 0;
            $data['customers_new_m']  = 0;
        }
    }

    // --- Budget pending ---
    $bp2=[]; $bw2="br.deleted_at IS NULL AND br.status='SUBMITTED'";
    if ($scope_office) { $bw2.=' AND br.office_code=?'; $bp2[]=$scope_office; }
    $stBP=$pdo->prepare("SELECT COUNT(*) FROM mpr_budget_requests br WHERE {$bw2}"); $stBP->execute($bp2);
    $data['budget_pending']=(int)$stBP->fetchColumn();
    if ($MPR_DEPO_RESTRICTED) $data['budget_pending']=0;

    // --- Alerts ---
    if ($data['followup_total'] > 0) {
        $data['alerts'][]=['level'=>'danger','msg'=>$data['followup_total'].' follow-up jatuh tempo/overdue. Segera tindak lanjut.'];
    }
    if ($data['visits_today'] === 0 && date('N') <= 5) {
        $data['alerts'][]=['level'=>'warning','msg'=>'Belum ada kunjungan hari ini. Pastikan tim MPR aktif di lapangan.'];
    }
    if ($data['budget_pending'] > 0) {
        $data['alerts'][]=['level'=>'warning','msg'=>$data['budget_pending'].' budget request menunggu FIN Approval.'];
    }

} catch (Throwable $e) {
    flash_set('warning','Dashboard error: '.e($e->getMessage()));
}

// Chart data
$trenLabels = array_keys($data['tren_months']);
$trenTotal  = array_column($data['tren_months'],'total');
$trenNew    = array_column($data['tren_months'],'new_p');
$trenDeals  = array_column($data['tren_months'],'deals');

// Funnel stages order
$funnelStages = ['PROSPEK','KUNJUNGAN','FOLLOW_UP','PRESENTASI','NEGOSIASI','WON'];
$funnelColors = ['#64748b','#14b8a6','#f59e0b','#3b82f6','#8b5cf6','#22c55e'];
$funnelCounts = array_map(fn($s) => $data['funnel'][$s] ?? 0, $funnelStages);
$funnelLabels = ['Prospek','Kunjungan','Follow-Up','Presentasi','Negosiasi','Deal Won'];
?>

<style>
/* ---- Layout ---- */
.d-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.d-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
@media(max-width:768px){.d-grid-2,.d-grid-3{grid-template-columns:1fr}}

/* ---- KPI Cards ---- */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-bottom:12px}
.kpi-card{background:rgba(17,24,39,.9);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:11px 14px;border-top:3px solid var(--kc);transition:transform .15s;cursor:default}
.kpi-card:hover{transform:translateY(-2px)}
.kpi-icon{font-size:16px;margin-bottom:3px}
.kpi-val{font-size:22px;font-weight:800;color:#fff}
.kpi-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;margin-top:1px}
.kpi-sub{font-size:10px;margin-top:3px;font-weight:600}

/* ---- Panels ---- */
.mprd-panel{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;height:100%}
.mprd-panel-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:10px}

/* ---- Alerts ---- */
.alert-row{margin-bottom:10px}
.mprd-alert{padding:8px 12px;border-radius:8px;font-size:12px;margin-bottom:5px;display:flex;align-items:center;gap:8px}
.mprd-alert.danger{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#f87171}
.mprd-alert.warning{background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.25);color:#fbbf24}

/* ---- Filter Bar ---- */
.filter-bar{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:9px 14px;margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12px}

/* ---- Leaderboard ---- */
.lb-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.lb-row:last-child{border-bottom:none}
.lb-rank{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0}
.lb-name{flex:1;font-size:12px;color:#e2e8f0}
.lb-name-level{font-size:10px;margin-top:1px}
.lb-bar-wrap{width:80px;height:6px;background:rgba(255,255,255,.07);border-radius:3px;overflow:hidden}
.lb-bar-fill{height:100%;border-radius:3px;background:var(--bc)}
.lb-stat{text-align:center;min-width:36px}
.lb-stat-val{font-size:13px;font-weight:700;color:#fff}
.lb-stat-lbl{font-size:9px;color:#64748b;text-transform:uppercase}

/* ---- Funnel ---- */
.funnel-wrap{display:flex;flex-direction:column;gap:6px}
.funnel-row{display:flex;align-items:center;gap:10px;font-size:12px}
.funnel-bar-out{flex:1;background:rgba(255,255,255,.05);border-radius:4px;height:20px;overflow:hidden}
.funnel-bar-in{height:100%;border-radius:4px;display:flex;align-items:center;padding:0 8px;font-size:11px;font-weight:700;color:#fff;transition:width .5s}
.funnel-label{width:80px;color:#94a3b8;font-size:11px;text-align:right}
.funnel-count{width:28px;font-weight:700;color:#fff;text-align:right}

/* ---- Follow-up list ---- */
.fu-item{display:flex;gap:8px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:12px}
.fu-item:last-child{border-bottom:none}
.fu-dot{width:8px;height:8px;border-radius:50%;background:#ef4444;flex-shrink:0;margin-top:4px}
.fu-dot.today{background:#f59e0b}

/* ---- Quick Links ---- */
.ql-link{display:flex;align-items:center;gap:7px;padding:7px 10px;border-radius:8px;text-decoration:none;color:#e2e8f0;font-size:12px;transition:all .18s;margin-bottom:3px}
.ql-link:hover{background:rgba(255,255,255,.07);color:#fff;padding-left:14px}
.ql-badge{margin-left:auto;background:rgba(239,68,68,.2);color:#f87171;font-size:10px;font-weight:700;padding:1px 6px;border-radius:6px}
.ql-badge.y{background:rgba(251,191,36,.2);color:#fbbf24}
.ql-badge.g{background:rgba(34,197,94,.2);color:#4ade80}
</style>

<?php // ---- Alerts ---- ?>
<?php if (!empty($data['alerts'])): ?>
<div class="alert-row">
  <?php foreach ($data['alerts'] as $al): ?>
    <div class="mprd-alert <?= e($al['level']) ?>"><?= $al['level']==='danger'?'🔴':'⚠️' ?> <?= e($al['msg']) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php // ---- Filter Periode ---- ?>
<form class="filter-bar" method="get">
  <span style="color:#64748b;font-size:10px;text-transform:uppercase">Periode</span>
  <select name="year" class="form-select form-select-sm" style="max-width:90px">
    <?php for ($y=(int)date('Y');$y>=2023;$y--): ?>
      <option value="<?= $y ?>" <?= $y===$f_year?'selected':'' ?>><?= $y ?></option>
    <?php endfor; ?>
  </select>
  <select name="month" class="form-select form-select-sm" style="max-width:110px">
    <?php for ($m=1;$m<=12;$m++): ?>
      <option value="<?= $m ?>" <?= $m===$f_month?'selected':'' ?>><?= $months[$m] ?></option>
    <?php endfor; ?>
  </select>
  <button class="btn btn-sm btn-outline-light">Tampilkan</button>
  <a class="btn btn-sm btn-outline-light" href="<?= e(url_mpr('mpr_dashboard.php')) ?>">Reset</a>
  <span style="color:#475569;margin-left:auto;font-size:10px">Scope: <?= e($scope_office?:'Semua Cabang') ?></span>
</form>

<?php // ---- KPI Cards ---- ?>
<div class="kpi-grid">
  <div class="kpi-card" style="--kc:#14b8a6">
    <div class="kpi-icon">📍</div>
    <div class="kpi-val"><?= $data['visits_total'] ?></div>
    <div class="kpi-lbl">Total Kunjungan <?= $months[$f_month] ?></div>
    <div class="kpi-sub" style="color:#64748b">Hari ini: <?= $data['visits_today'] ?></div>
  </div>
  <div class="kpi-card" style="--kc:#6366f1">
    <div class="kpi-icon">🆕</div>
    <div class="kpi-val" style="color:#818cf8"><?= $data['visits_new'] ?></div>
    <div class="kpi-lbl">Kunjungan Prospek Baru</div>
    <?php $newPct=$data['visits_total']>0?round($data['visits_new']/$data['visits_total']*100):0; ?>
    <div class="kpi-sub" style="color:#818cf8"><?= $newPct ?>% dari total</div>
  </div>
  <div class="kpi-card" style="--kc:#22c55e">
    <div class="kpi-icon">🏆</div>
    <div class="kpi-val" style="color:#4ade80"><?= $data['deals_won'] ?></div>
    <div class="kpi-lbl">Deal Won <?= $months[$f_month] ?></div>
    <div class="kpi-sub" style="color:#4ade80">Konversi: <?= $data['conversion'] ?>%</div>
  </div>
  <div class="kpi-card" style="--kc:#f59e0b">
    <div class="kpi-icon">⚠️</div>
    <div class="kpi-val" style="color:<?= $data['followup_total']>0?'#f87171':'#fbbf24' ?>"><?= $data['followup_total'] ?></div>
    <div class="kpi-lbl">Follow-Up Overdue</div>
    <div class="kpi-sub" style="color:#fbbf24">Segera tindak lanjut</div>
  </div>
  <div class="kpi-card" style="--kc:#8b5cf6">
    <div class="kpi-icon">🎯</div>
    <div class="kpi-val"><?= $data['pipeline_active'] ?></div>
    <div class="kpi-lbl">Pipeline Aktif</div>
    <div class="kpi-sub" style="color:#94a3b8"><?= $data['pipeline_value']>0?'Rp '.number_format($data['pipeline_value']/1e6,1).'jt':'-' ?></div>
  </div>
  <div class="kpi-card" style="--kc:#3b82f6">
    <div class="kpi-icon">👥</div>
    <div class="kpi-val"><?= $data['customers_active'] ?></div>
    <div class="kpi-lbl">Customer Aktif</div>
    <?php $cHit=$data['customers_new_m']>=3; ?>
    <div class="kpi-sub" style="color:<?= $cHit?'#4ade80':'#fbbf24' ?>"><?= $cHit?'✅':'' ?> +<?= $data['customers_new_m'] ?> bln ini</div>
  </div>
  <?php if ($MPR_IS_ADMIN||($MPR_IS_FIN&&in_array($MPR_USER['level'],['MANAGER'],true))): ?>
  <div class="kpi-card" style="--kc:#ef4444">
    <div class="kpi-icon">💰</div>
    <div class="kpi-val" style="color:<?= $data['budget_pending']>0?'#f87171':'#94a3b8' ?>"><?= $data['budget_pending'] ?></div>
    <div class="kpi-lbl">Budget Pending FIN</div>
  </div>
  <?php endif; ?>
</div>

<?php // ---- Main Grid ---- ?>
<div class="row g-3">

  <?php // ---- FUNNEL ---- ?>
  <div class="col-lg-4">
    <div class="mprd-panel">
      <div class="mprd-panel-title">🔽 Funnel Pipeline Akuisisi</div>
      <?php
      $fMax = max(1, max($funnelCounts));
      foreach ($funnelStages as $i => $sk):
        $cnt = $funnelCounts[$i];
        $pct = round($cnt / $fMax * 100);
        $col = $funnelColors[$i];
      ?>
      <div class="funnel-row">
        <div class="funnel-label"><?= e($funnelLabels[$i]) ?></div>
        <div class="funnel-bar-out">
          <div class="funnel-bar-in" style="width:<?= $pct ?>%;background:<?= e($col) ?>"><?= $cnt > 0 ? $cnt : '' ?></div>
        </div>
        <div class="funnel-count" style="color:<?= e($col) ?>"><?= $cnt ?></div>
      </div>
      <?php endforeach; ?>
      <?php $lost = $data['funnel']['LOST'] ?? 0; if ($lost>0): ?>
        <div class="funnel-row" style="margin-top:6px;opacity:.6">
          <div class="funnel-label" style="color:#ef4444">Lost</div>
          <div class="funnel-bar-out"><div class="funnel-bar-in" style="width:<?= round($lost/$fMax*100) ?>%;background:#ef4444"><?= $lost > 0 ? $lost : '' ?></div></div>
          <div class="funnel-count" style="color:#ef4444"><?= $lost ?></div>
        </div>
      <?php endif; ?>
      <div style="margin-top:10px;font-size:10px;color:#475569">
        <a href="<?= e(url_mpr('mpr_pipeline.php')) ?>" style="color:#3b82f6">→ Kelola Pipeline</a>
      </div>
    </div>
  </div>

  <?php // ---- LEADERBOARD ---- ?>
  <div class="col-lg-8">
    <div class="mprd-panel">
      <div class="mprd-panel-title">🏆 Leaderboard <?= $months[$f_month] ?> <?= $f_year ?> — Staff &amp; Manager (semua wajib kunjungan)</div>
      <?php if (empty($data['leaderboard'])): ?>
        <div style="color:#475569;font-size:12px;text-align:center;padding:20px">Belum ada data kunjungan periode ini.</div>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:auto 1fr auto auto auto auto;gap:0;align-items:center;margin-bottom:4px;padding:0 0 4px;border-bottom:1px solid rgba(255,255,255,.06)">
          <div style="font-size:9px;color:#475569;width:28px">#</div>
          <div style="font-size:9px;color:#475569">Nama</div>
          <div style="font-size:9px;color:#475569;width:50px;text-align:center">Kunjungan</div>
          <div style="font-size:9px;color:#475569;width:50px;text-align:center">Prospek Baru</div>
          <div style="font-size:9px;color:#475569;width:50px;text-align:center">Deal Won</div>
          <div style="font-size:9px;color:#475569;width:50px;text-align:center">Hari Ini</div>
        </div>
        <?php
        $maxVisits = max(1, max(array_column($data['leaderboard'],'total_visits')));
        foreach ($data['leaderboard'] as $i => $lb):
          $rank = $i+1;
          $rankColor = $rank===1?'#f59e0b':($rank===2?'#94a3b8':($rank===3?'#cd7c2e':'#334155'));
          $rankBg    = $rank===1?'rgba(245,158,11,.2)':($rank===2?'rgba(148,163,184,.1)':($rank===3?'rgba(205,124,46,.1)':'rgba(51,65,85,.2)'));
          $isManager = in_array(strtoupper((string)$lb['visitor_level']),['MANAGER'],true);
          $visitPct  = round((int)$lb['total_visits']/$maxVisits*100);
          $tNew      = (int)$lb['target_new'];
          $tVis      = (int)$lb['target_visits'];
          $tClo      = (int)$lb['target_closing'];
          $newPct    = $tNew>0 ? min(100,round((int)$lb['new_prospects']/$tNew*100)) : -1;
          $visPct    = $tVis>0 ? min(100,round((int)$lb['total_visits']/$tVis*100)) : -1;
        ?>
        <div class="lb-row">
          <div class="lb-rank" style="background:<?= $rankBg ?>;color:<?= $rankColor ?>">
            <?= $rank<=3 ? ['🥇','🥈','🥉'][$rank-1] : $rank ?>
          </div>
          <div class="lb-name" style="flex:1">
            <div style="display:flex;align-items:center;gap:5px">
              <?= e($lb['visitor_username']) ?>
              <?php if ($isManager): ?>
                <span style="font-size:9px;background:rgba(251,191,36,.15);color:#fbbf24;padding:1px 5px;border-radius:3px;font-weight:700">MGR</span>
              <?php else: ?>
                <span style="font-size:9px;background:rgba(100,116,139,.15);color:#94a3b8;padding:1px 5px;border-radius:3px">STAFF</span>
              <?php endif; ?>
            </div>
            <div style="margin-top:3px">
              <div class="lb-bar-wrap" style="width:100%">
                <div class="lb-bar-fill" style="width:<?= $visitPct ?>%;--bc:<?= $isManager?'#fbbf24':'#3b82f6' ?>"></div>
              </div>
            </div>
            <?php if ($tVis>0||$tNew>0): ?>
            <div style="font-size:9px;color:#475569;margin-top:2px">
              Target: <?= $tVis ?>kunjungan/<?= $tNew ?>prospek/<?= $tClo ?>deal
              <?php if ($visPct>=0): ?>
                <span style="color:<?= $visPct>=100?'#4ade80':'#f59e0b' ?>">(<?= $visPct ?>%)</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="lb-stat">
            <div class="lb-stat-val"><?= (int)$lb['total_visits'] ?></div>
            <div class="lb-stat-lbl">Kunjungan</div>
          </div>
          <div class="lb-stat">
            <div class="lb-stat-val" style="color:#818cf8"><?= (int)$lb['new_prospects'] ?></div>
            <div class="lb-stat-lbl">Baru</div>
          </div>
          <div class="lb-stat">
            <div class="lb-stat-val" style="color:#4ade80"><?= (int)$lb['deals_won'] ?></div>
            <div class="lb-stat-lbl">Deal</div>
          </div>
          <div class="lb-stat">
            <div class="lb-stat-val" style="color:<?= (int)$lb['visits_today']>0?'#fbbf24':'#334155' ?>"><?= (int)$lb['visits_today'] ?></div>
            <div class="lb-stat-lbl">Hari Ini</div>
          </div>
        </div>
        <?php endforeach; ?>
        <div style="font-size:10px;color:#334155;margin-top:8px">Manager dan Staff wajib kunjungan &amp; update pipeline setiap hari.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php // ---- TREN CHART ---- ?>
  <div class="col-lg-8">
    <div class="mprd-panel">
      <div class="mprd-panel-title">📈 Tren 6 Bulan s/d <?= $months[$f_month] ?> <?= $f_year ?> — Total vs Prospek Baru vs Deal Won</div>
      <canvas id="chartTren" height="110"></canvas>
    </div>
  </div>

  <?php // ---- FOLLOW-UP OVERDUE ---- ?>
  <div class="col-lg-4">
    <div class="mprd-panel" style="border:<?= !empty($data['followup_today'])?'1px solid rgba(239,68,68,.3)':'' ?>">
      <div class="mprd-panel-title" style="color:<?= !empty($data['followup_today'])?'#f87171':'#64748b' ?>">
        🔴 Follow-Up Jatuh Tempo (<?= $data['followup_total'] ?>)
      </div>
      <?php if (empty($data['followup_today'])): ?>
        <div style="color:#475569;font-size:12px">✅ Tidak ada follow-up overdue. Tim MPR on track!</div>
      <?php else: ?>
        <?php foreach ($data['followup_today'] as $fu):
          $isOverdue = (string)$fu['next_followup_date'] < $today;
          $isManager = in_array(strtoupper((string)$fu['visitor_level']),['MANAGER'],true);
        ?>
        <div class="fu-item">
          <div class="fu-dot <?= $isOverdue?'':'today' ?>"></div>
          <div style="flex:1">
            <div style="font-weight:700;color:#e2e8f0;font-size:12px"><?= e($fu['customer_name']) ?></div>
            <div style="font-size:11px;color:#64748b">
              Jadwal: <?= e($fu['next_followup_date']) ?><?= $isOverdue?' <span style="color:#f87171">⚠ Overdue!</span>':'' ?>
            </div>
            <div style="font-size:11px;color:#94a3b8">
              PIC: <?= e($fu['visitor_username']) ?>
              <?php if ($isManager): ?><span style="color:#fbbf24;font-size:10px">(Manager)</span><?php endif; ?>
            </div>
          </div>
          <a class="btn btn-xs btn-outline-light" href="<?= e(url_mpr('mpr_visits.php?edit='.(int)$fu['id'])) ?>">Update</a>
        </div>
        <?php endforeach; ?>
        <div style="font-size:10px;color:#475569;margin-top:8px">
          <a href="<?= e(url_mpr('mpr_visits.php')) ?>" style="color:#3b82f6">→ Semua Kunjungan</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php // ---- QUICK LINKS ---- ?>
  <div class="col-lg-4">
    <div class="mprd-panel">
      <div class="mprd-panel-title">⚡ Aksi Cepat</div>
      <?php if ($MPR_DEPO_RESTRICTED): ?>
        <a class="ql-link" href="<?= e(url_mpr('mpr_visits.php')) ?>"><span>📍</span> Catat / Lihat Kunjungan Office</a>
        <a class="ql-link" href="<?= e(url_mpr('mpr_pipeline.php')) ?>"><span>🎯</span> Pipeline Office</a>
        <a class="ql-link" href="<?= e(url_mpr('mpr_plans.php')) ?>"><span>📋</span> MPR Plans</a>
        <a class="ql-link" href="<?= e($mprBase . '/sales/sales_do.php') ?>"><span>📦</span> Delivery Order Office</a>
        <a class="ql-link" href="<?= e($mprBase . '/dashboards/finance/dashboard_detail.php') ?>"><span>🎯</span> Pencapaian Office</a>
      <?php else: ?>
      <a class="ql-link" href="<?= e(url_mpr('mpr_visits.php')) ?>">
        <span>📍</span> Catat Kunjungan Hari Ini
        <?php if ($data['visits_today']>0): ?><span class="ql-badge g"><?= $data['visits_today'] ?> hari ini</span><?php endif; ?>
      </a>
      <a class="ql-link" href="<?= e(url_mpr('mpr_pipeline.php')) ?>">
        <span>🎯</span> Kelola Pipeline Prospek
        <?php if ($data['pipeline_active']>0): ?><span class="ql-badge y"><?= $data['pipeline_active'] ?> aktif</span><?php endif; ?>
      </a>
      <a class="ql-link" href="<?= e(url_mpr('mpr_plans.php')) ?>">
        <span>📋</span> Plans
      </a>
      <a class="ql-link" href="<?= e(url_mpr('mpr_visits.php?export=1&month='.urlencode($ym))) ?>">
        <span>📤</span> Export Kunjungan Bulan Ini
      </a>
      <a class="ql-link" href="<?= e(url_mpr('mpr_pipeline.php?export=1')) ?>">
        <span>📤</span> Export Pipeline
      </a>
      <hr style="border-color:rgba(255,255,255,.06);margin:6px 0">
      <a class="ql-link" href="<?= e($mprBase . '/absensi/index.php') ?>"><span>📅</span> Absensi</a>
      <a class="ql-link" href="<?= e($mprBase . '/master/master_pricelist_sell.php') ?>"><span>💰</span> Pricelist Jual</a>
      <a class="ql-link" href="<?= e($mprBase . '/sales/sales_control_tower.php') ?>"><span>🗼</span> Sales Control Tower</a>
      <?php if ($MPR_IS_ADMIN||$MPR_IS_FIN): ?>
      <a class="ql-link" href="<?= e(url_mpr('mpr_budget_fin.php')) ?>">
        <span>💰</span> FIN Approval
        <?php if ($data['budget_pending']>0): ?><span class="ql-badge"><?= $data['budget_pending'] ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php // ---- Customer Baru Highlight ---- ?>
  <div class="col-lg-4">
    <div class="mprd-panel" style="border-top:3px solid #22c55e">
      <div class="mprd-panel-title">🤝 Customer Baru <?= $months[$f_month] ?> <?= $f_year ?></div>
      <?php $cHit=$data['customers_new_m']>=3; ?>
      <div style="font-size:36px;font-weight:900;color:<?= $cHit?'#4ade80':'#fbbf24' ?>">
        <?= $data['customers_new_m'] ?>
        <span style="font-size:14px;color:#64748b">/ target min. 3</span>
      </div>
      <div style="font-size:12px;color:<?= $cHit?'#4ade80':'#fbbf24' ?>;font-weight:700;margin-top:4px">
        <?= $cHit ? '✅ Target tercapai! Pertahankan.' : '⚠️ Belum tercapai. Tingkatkan prospek baru!' ?>
      </div>
      <div style="margin-top:10px;font-size:11px;color:#64748b">
        Total Customer Aktif: <strong style="color:#e2e8f0"><?= $data['customers_active'] ?></strong>
      </div>
      <div style="font-size:11px;color:#475569;margin-top:6px">
        Setiap deal won harus dikonversi ke master_customers segera.
      </div>
    </div>
  </div>

  <?php if (!$MPR_DEPO_RESTRICTED): ?>
  <div class="col-12">
    <?php
    // Audit widget existing belum memiliki kontrak office-scope di dashboard ini.
    // Jangan tampilkan ke akun Depo agar event office lain tidak ikut terbaca.
    $auditModules = ['MPR', 'MPR_PLAN', 'MPR_VISIT', 'MPR_PIPELINE', 'MPR_BUDGET'];
    $auditLimit   = 10;
    require __DIR__ . '/../dashboards/_audit_log_widget.php';
    ?>
  </div>
  <?php endif; ?>

</div>

<script>
(function(){
  function initChart(){
    var labels  = <?= json_encode(array_values($trenLabels)) ?>;
    var total   = <?= json_encode(array_values($trenTotal)) ?>;
    var newP    = <?= json_encode(array_values($trenNew)) ?>;
    var deals   = <?= json_encode(array_values($trenDeals)) ?>;
    var ctx = document.getElementById('chartTren');
    if (!ctx) return;
    new Chart(ctx,{
      type:'bar',
      data:{
        labels:labels,
        datasets:[
          {type:'bar',  label:'Total Kunjungan', data:total, backgroundColor:'rgba(59,130,246,.5)',yAxisID:'y'},
          {type:'line', label:'Prospek Baru',    data:newP,  borderColor:'#818cf8',backgroundColor:'rgba(129,140,248,.15)',tension:.4,pointRadius:4,yAxisID:'y'},
          {type:'line', label:'Deal Won',         data:deals, borderColor:'#4ade80',backgroundColor:'rgba(74,222,128,.15)',tension:.4,pointRadius:5,pointStyle:'star',yAxisID:'y'},
        ]
      },
      options:{
        responsive:true,maintainAspectRatio:true,
        plugins:{legend:{labels:{color:'#94a3b8',font:{size:11}}}},
        scales:{
          y:{ticks:{color:'#64748b',precision:0},grid:{color:'rgba(255,255,255,.05)'}},
          x:{ticks:{color:'#64748b'},grid:{color:'rgba(255,255,255,.04)'}}
        }
      }
    });
  }
  if (typeof Chart === 'undefined') {
    var s=document.createElement('script');
    s.src='https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js';
    s.onload=initChart; document.head.appendChild(s);
  } else { initChart(); }
})();
</script>

<style>.btn-xs{padding:2px 7px;font-size:11px;border-radius:5px}</style>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
