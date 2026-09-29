<?php
/**
 * dashboards/funnels.php
 * Funnel Overview — read-only cross-module operational overview.
 */
declare(strict_types=1);

require_once __DIR__ . '/_dashboard_bootstrap.php';
require_login();

if (function_exists('require_any_permission')) {
    require_any_permission([
        'DASHBOARD.VIEW','DASHBOARD.SALES_VIEW','DASHBOARD.OWNER_VIEW','DASHBOARD.OWNER_SUMMARY',
    ]);
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo && function_exists('kpi_require_pdo')) $pdo = kpi_require_pdo();

function rmi_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
if (!function_exists('h')) { function h($v): string { return rmi_h($v); } }
function funnel_num($v): string { return $v === null ? '—' : number_format((int)$v,0,',','.'); }

require_once __DIR__ . '/_funnels_data.php';

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$date_from = rmi_funnel_valid_date($_GET['date_from'] ?? null, $monthStart);
$date_to = rmi_funnel_valid_date($_GET['date_to'] ?? null, $today);
if ($date_from > $date_to) { [$date_from,$date_to]=[$date_to,$date_from]; }
if ($date_to > $today) $date_to = $today;

$data = $pdo instanceof PDO ? rmi_funnel_read($pdo,$date_from,$date_to) : null;
$warnings = $data['warnings'] ?? ['Koneksi database tidak tersedia.'];
$crm = $data['crm_leads'] ?? [];
$salesDo = $data['sales_do'] ?? [];
$regAlkes = $data['reg_alkes'] ?? [];
$importPo = $data['import'] ?? [];

$bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT,'/') : '');
require_once __DIR__ . '/../_shared/rmi_layout.php';

rmi_header('Funnel Overview', [
    'active'=>'dashboard',
    'breadcrumbs'=>[['label'=>'Dashboard','url'=>$bp.'/dashboards/index.php'],'Funnel Overview'],
    'actions'=>[
        ['label'=>rmi_icon('books').' Panduan','url'=>$bp.'/dashboards/panduan_funnels.php','class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'API Funnels JSON','url'=>$bp.'/api/v1/internal/funnels_summary.php?date_from='.urlencode($date_from).'&date_to='.urlencode($date_to),'class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'MPR Pipeline','url'=>$bp.'/mpr/mpr_pipeline.php','class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'Sales Dashboard','url'=>$bp.'/sales/sales_dashboard.php','class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'Reg Alkes Tower','url'=>$bp.'/hrl_reg_alkes/reg_alkes_control_tower.php','class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'Exec Summary','url'=>$bp.'/dashboards/owner/exec_summary.php','class'=>'btn btn-sm btn-outline-light'],
    ],
]);
?>
<style>
.funnel-page .text-muted,.funnel-page .muted{color:#9fb0c5!important}
.funnel-page .card{border-color:rgba(148,163,184,.22)}
.funnel-page .flowline{font-size:15px;line-height:1.85;word-spacing:2px;color:#e8eef7}
.funnel-page .metric-note{color:#a8b8ca;font-size:12px;line-height:1.55}
.funnel-page .metric-note strong{color:#e8eef7}
.funnel-page .badge-soft{background:rgba(56,189,248,.12);color:#7dd3fc;border:1px solid rgba(56,189,248,.25)}
.funnel-page .badge-good{background:rgba(34,197,94,.13);color:#86efac;border:1px solid rgba(34,197,94,.25)}
.funnel-page .badge-warn{background:rgba(245,158,11,.13);color:#fcd34d;border:1px solid rgba(245,158,11,.25)}
.funnel-page .legend-box{background:rgba(15,23,42,.28);border:1px solid rgba(148,163,184,.18);border-radius:10px;padding:10px 12px}
.funnel-page .source-chip{font-size:10px;color:#94a3b8;font-weight:600}
</style>

<div class="container py-4 funnel-page">
  <div class="card mb-3">
    <div class="card-body py-3">
      <form class="row g-2 align-items-end" method="get" action="funnels.php">
        <div class="col-auto"><label class="form-label small">Date From</label><input class="form-control form-control-sm" type="date" name="date_from" value="<?=h($date_from)?>"></div>
        <div class="col-auto"><label class="form-label small">Date To</label><input class="form-control form-control-sm" type="date" name="date_to" value="<?=h($date_to)?>" max="<?=h($today)?>"></div>
        <div class="col-auto"><button class="btn btn-primary btn-sm">Apply</button></div>
        <div class="col-auto"><a class="btn btn-outline-secondary btn-sm" href="funnels.php">Reset</a></div>
      </form>
      <div class="metric-note mt-2"><strong>Periode aktivitas:</strong> <?=h($date_from)?> s.d. <?=h($date_to)?>. Snapshot backlog memakai posisi terakhir sampai tanggal <?=h($date_to)?>. Halaman read-only.</div>
    </div>
  </div>

  <?php if($warnings): ?>
  <div class="alert alert-warning small"><strong>Catatan integritas data:</strong><ul class="mb-0 mt-1"><?php foreach(array_unique($warnings) as $w):?><li><?=h($w)?></li><?php endforeach;?></ul></div>
  <?php endif; ?>

  <div class="legend-box mb-3 metric-note">
    <strong>Cara membaca:</strong> MPR, Sales DO, dan Reg Alkes adalah <strong>snapshot posisi aktif sampai Date To</strong>. Paid, PIB, GR, dan AP adalah <strong>aktivitas dalam Date From–Date To</strong>. PO menunjukkan <strong>PO aktif sampai Date To</strong>. Karena definisinya berbeda, angka antar-card tidak dikurangkan sebagai conversion cohort.
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2">
          <strong><?=h($crm['label'] ?? 'MPR Sales Pipeline')?></strong>
          <span class="badge badge-soft"><?=funnel_num($crm['active_total'] ?? 0)?> active</span>
          <span class="badge bg-secondary"><?=funnel_num($crm['total'] ?? 0)?> total</span>
          <?php if(($crm['stages']['OTHER']??0)>0):?><span class="badge badge-warn"><?=funnel_num($crm['stages']['OTHER'])?> unmapped</span><?php endif;?>
        </div>
        <div class="card-body py-2 small">
          <div class="flowline">PROSPEK <b><?=funnel_num($crm['stages']['PROSPEK']??0)?></b> → KUNJUNGAN <b><?=funnel_num($crm['stages']['KUNJUNGAN']??0)?></b> → FOLLOW UP <b><?=funnel_num($crm['stages']['FOLLOW_UP']??0)?></b> → PRESENTASI <b><?=funnel_num($crm['stages']['PRESENTASI']??0)?></b> → NEGOSIASI <b><?=funnel_num($crm['stages']['NEGOSIASI']??0)?></b></div>
          <div class="flowline">WON <b><?=funnel_num($crm['stages']['WON']??0)?></b> | LOST <b><?=funnel_num($crm['stages']['LOST']??0)?></b></div>
          <div class="metric-note">Win rate closed: <strong><?=number_format((float)($crm['won_pct']??0),1,',','.')?>%</strong>. Ini distribusi posisi pipeline saat ini, bukan cohort historis.</div>
          <div class="source-chip mt-1">Source: <?=h($crm['source']??'n/a')?><?=!empty($crm['stage_column'])?' · stage='.$crm['stage_column']:''?><?=!empty($crm['date_column'])?' · as-of '.$crm['date_column']:''?></div>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?=h($bp)?>/mpr/mpr_pipeline.php">Open MPR Pipeline</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2">
          <strong>Sales DO Current Queue</strong>
          <span class="badge badge-soft"><?=funnel_num($salesDo['active_total']??0)?> active</span>
          <span class="badge bg-secondary"><?=funnel_num($salesDo['total']??0)?> total as-of</span>
          <span class="badge badge-good"><?=funnel_num($salesDo['paid']??0)?> paid in period</span>
          <?php if(($salesDo['stages']['OTHER']??0)>0):?><span class="badge badge-warn"><?=funnel_num($salesDo['stages']['OTHER'])?> unmapped</span><?php endif;?>
        </div>
        <div class="card-body py-2 small">
          <div class="flowline">CRM <b><?=funnel_num($salesDo['stages']['CRM']??0)?></b> → WQS <b><?=funnel_num($salesDo['stages']['WQS']??0)?></b> → SCM <b><?=funnel_num($salesDo['stages']['SCM']??0)?></b> → ACT <b><?=funnel_num($salesDo['stages']['ACT']??0)?></b> → FIN <b><?=funnel_num($salesDo['stages']['FIN']??0)?></b> → DONE <b><?=funnel_num($salesDo['stages']['DONE']??0)?></b></div>
          <div class="flowline">CANCELLED <b><?=funnel_num($salesDo['stages']['CANCELLED']??0)?></b></div>
          <div class="metric-note">Queue memakai <strong>sales_do.status</strong> sebagai source of truth. Paid-in-period memakai tanggal pembayaran bila tersedia; tidak lagi dibatasi tanggal pembuatan DO.</div>
          <div class="source-chip mt-1">Stage source: <?=h($salesDo['stage_column']??'n/a')?> · DO date: <?=h($salesDo['date_column']??'n/a')?> · Paid date: <?=h($salesDo['paid_date_column']??'fallback status')?></div>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?=h($bp)?>/sales/sales_dashboard.php">Open Sales Dashboard</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2"><strong>Reg Alkes Current Backlog</strong><span class="badge bg-secondary"><?=funnel_num($regAlkes['total']??0)?> OPEN</span><?php if(($regAlkes['final_open']??0)>0):?><span class="badge badge-warn"><?=funnel_num($regAlkes['final_open'])?> S15 belum closed</span><?php endif;?></div>
        <div class="card-body py-2 small">
          <div class="flowline">Stage 1–15: <?=h(implode(' | ',array_map(static fn($n,$c)=>"S{$n}:{$c}",array_keys($regAlkes['stages']??[]),$regAlkes['stages']??[])))?></div>
          <?php if(!empty($regAlkes['avg_days'])):?><div class="metric-note">Rata-rata selesai dalam periode: <?=h(implode(' | ',array_map(static fn($n,$d)=>"S{$n}:{$d} hari",array_keys($regAlkes['avg_days']),$regAlkes['avg_days'])))?></div><?php endif;?>
          <?php if(!empty($regAlkes['active_age_days'])):?><div class="metric-note">Umur backlog aktif per <?=h($date_to)?>: <?=h(implode(' | ',array_map(static fn($n,$d)=>"S{$n}:{$d} hari",array_keys($regAlkes['active_age_days']),$regAlkes['active_age_days'])))?></div><?php endif;?>
          <?php if(($regAlkes['final_open']??0)>0):?><div class="metric-note mt-1"><strong>Perhatian:</strong> case di Stage 15 sudah berada di tahap final tetapi status masih OPEN; perlu closure review.</div><?php endif;?>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?=h($bp)?>/hrl_reg_alkes/reg_alkes_control_tower.php">Open Control Tower</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2"><strong>Procurement / Import Snapshot</strong><?php if(($importPo['value']??null)!==null):?><span class="badge bg-secondary">Rp <?=number_format((float)$importPo['value'],0,',','.')?></span><?php endif;?></div>
        <div class="card-body py-2 small">
          <div class="flowline">PO ACTIVE <b><?=funnel_num($importPo['stages']['PO']??null)?></b> → PIB ACTIVITY <b><?=funnel_num($importPo['stages']['PIB']??null)?></b> → GR ACTIVITY <b><?=funnel_num($importPo['stages']['GR']??null)?></b> → AP ACTIVITY <b><?=funnel_num($importPo['stages']['AP']??null)?></b></div>
          <div class="metric-note"><strong>PO Active</strong> = OPEN / IN_PRODUCTION / READY sampai Date To. <strong>PIB/GR/AP</strong> = aktivitas pada periode filter. PIB hanya berlaku untuk PO impor, sehingga ini snapshot operasional, bukan conversion funnel satu cohort.</div>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?=h($bp)?>/purchases/purchases_import_control_tower.php">Open Import Tower</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
