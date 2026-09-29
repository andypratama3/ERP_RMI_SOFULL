<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';
require_once __DIR__ . '/../../app/Dashboard/DashboardDetailService.php';

use App\Dashboard\DashboardDetailService;

require_login();
require_once __DIR__ . '/../../_shared/rmi_branch_guard.php';
$__dd_is_depo_branch = function_exists('rmi_is_depo_branch_session') && rmi_is_depo_branch_session();
$__dd_depo_office = $__dd_is_depo_branch && function_exists('rmi_depo_branch_office') ? rmi_depo_branch_office() : '';

// Finance Dashboard Detail tetap khusus FIN Manager/SYS.
// Exception tunggal: BRANCH Depo KAL/JGY masuk mode PENCAPAIAN RESTRICTED dan keluar
// sebelum blok finance/export/drilldown diproses.
if (!$__dd_is_depo_branch) {
    if (function_exists('auth_is_fin_manager')) {
        if (!auth_is_fin_manager()) {
            http_response_code(403);
            echo '<h3>Akses Terbatas</h3><p>Halaman ini hanya untuk <b>Manager FIN</b> atau <b>ADMIN/SUPERADMIN</b>.</p>';
            exit;
        }
    } elseif (function_exists('require_admin_critical')) {
        require_admin_critical();
    } else {
        require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method Not Allowed');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Database connection is required.');
}

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function f_money($v): string
{
    return number_format((float)$v, 0, ',', '.');
}

function f_pct($v): string
{
    return number_format((float)$v, 1, ',', '.') . '%';
}

function f_delta($v): string
{
    $n = (float)$v;
    $sign = $n >= 0 ? '+' : '';
    return $sign . number_format($n, 1, ',', '.') . '%';
}

function normalize_dashboard_segment($segment): string
{
    $s = strtoupper(trim((string)$segment));
    $s = str_replace([' ', '-', '_'], '', $s);
    return match ($s) {
        'NONHERMINA' => 'NON_HERMINA',
        'HERMINA' => 'HERMINA',
        'ACCUNIT' => 'ACCUNIT',
        default => strtoupper(trim((string)$segment)),
    };
}

$monthNo = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$yearNo = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$asOf = trim((string)($_GET['as_of'] ?? date('Y-m-d')));
$tab = strtolower(trim((string)($_GET['tab'] ?? 'target')));
$export = strtolower(trim((string)($_GET['export'] ?? '')));
$drilldown = strtolower(trim((string)($_GET['drilldown'] ?? '')));
$drillOffice = strtoupper(trim((string)($_GET['office'] ?? '')));
$drillSegment = normalize_dashboard_segment($_GET['segment'] ?? '');
$refreshSec = isset($_GET['refresh']) ? (int)$_GET['refresh'] : 0;
$motionMode = strtolower(trim((string)($_GET['motion'] ?? 'live')));
$strictMode = isset($_GET['strict']) ? (int)$_GET['strict'] : 0;
$kioskMode  = isset($_GET['kiosk']) && (string)$_GET['kiosk'] === '1';
$anomalyThreshold = isset($_GET['anomaly_threshold']) ? (float)$_GET['anomaly_threshold'] : 35.0;

if ($monthNo < 1 || $monthNo > 12) {
    $monthNo = (int)date('m');
}
if ($yearNo < 2000 || $yearNo > 2100) {
    $yearNo = (int)date('Y');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $asOf = date('Y-m-d');
}
if (!in_array($refreshSec, [0, 30, 60], true)) {
    $refreshSec = 0;
}
if (!in_array($motionMode, ['live', 'classic'], true)) {
    $motionMode = 'live';
}
$strictMode = $strictMode === 1 ? 1 : 0;
if (!is_finite($anomalyThreshold) || $anomalyThreshold < 5.0 || $anomalyThreshold > 200.0) {
    $anomalyThreshold = 35.0;
}

$service = new DashboardDetailService($pdo);

$data = $service->build(str_pad((string)$monthNo, 2, '0', STR_PAD_LEFT), $yearNo, $asOf);

/*
 * Defensive normalization for ALL OFFICE display.
 * Tangerang (TGR) must always appear in every office-based target table,
 * even when an older cached/service payload omits the row. This only repairs
 * the presentation payload and recalculates the table total; transaction and
 * source-table flows remain unchanged.
 */
$ensureOfficeRow = static function (array $rows, string $segment) use ($data): array {
    $officeCode = 'TGR';
    $officeName = $officeCode === 'TGR' ? 'Tangerang' : (string)($data['office_meta'][$officeCode]['name'] ?? $officeCode);
    $target = (float)($data['targets']['by_segment_office'][$segment][$officeCode] ?? 0);
    $pencapaian = (float)($data['sales']['by_office_segment'][$officeCode][$segment]['net_sales'] ?? 0);

    $detail = [];
    $hasTgr = false;
    foreach ($rows as $row) {
        if (!empty($row['is_total']) || strtoupper((string)($row['office_code'] ?? '')) === 'TOTAL') {
            continue;
        }
        if (strtoupper((string)($row['office_code'] ?? '')) === $officeCode) {
            $hasTgr = true;
            $row['office'] = $officeName;
            $row['target'] = $target;
            $row['pencapaian'] = $pencapaian;
            $row['persentase'] = $target > 0 ? ($pencapaian / $target) * 100.0 : 0.0;
        }
        $detail[] = $row;
    }

    if (!$hasTgr) {
        $detail[] = [
            'tanggal' => (string)($data['as_of'] ?? date('Y-m-d')),
            'office_code' => $officeCode,
            'office' => $officeName,
            'target' => $target,
            'pencapaian' => $pencapaian,
            'persentase' => $target > 0 ? ($pencapaian / $target) * 100.0 : 0.0,
        ];
    }

    $targetTotal = 0.0;
    $pencapaianTotal = 0.0;
    foreach ($detail as $row) {
        $targetTotal += (float)($row['target'] ?? 0);
        $pencapaianTotal += (float)($row['pencapaian'] ?? 0);
    }
    $detail[] = [
        'tanggal' => (string)($data['as_of'] ?? date('Y-m-d')),
        'office_code' => 'TOTAL',
        'office' => 'TOTAL',
        'target' => $targetTotal,
        'pencapaian' => $pencapaianTotal,
        'persentase' => $targetTotal > 0 ? ($pencapaianTotal / $targetTotal) * 100.0 : 0.0,
        'is_total' => true,
    ];
    return $detail;
};

$data['section1']['non_hermina'] = $ensureOfficeRow((array)($data['section1']['non_hermina'] ?? []), 'NON_HERMINA');
$data['section1']['hermina'] = $ensureOfficeRow((array)($data['section1']['hermina'] ?? []), 'HERMINA');
$data['section1']['accunit'] = $ensureOfficeRow((array)($data['section1']['accunit'] ?? []), 'ACCUNIT');

/*
 * V12 ACTUAL LEDGER + CANONICAL MASTER SEGMENT + RECONCILIATION FIX
 * Target vs Pencapaian memakai ledger aktual yang sama dengan Executive Summary.
 * Tidak ada hard-code nominal/DO/tanggal.
 */
$ddActual = [
    'by_office_segment'=>[], 'by_office'=>[], 'daily'=>[], 'total'=>0.0,
    'diagnostic'=>[
        'rows'=>0,'internal'=>0,'excluded_status'=>0,'return_adjustment'=>0.0,
        'segment_map'=>0,'segment_code'=>0,'segment_master'=>0,'segment_name'=>0,
        'segment_conflict'=>0,'segment_conflict_rows'=>[],
        'segment_fallback'=>0,'segment_fallback_rows'=>[],
        'office_prefix_mismatch'=>0,'office_prefix_mismatch_rows'=>[],
        'office_assignment_source'=>[],
        'office_operational_mismatch'=>0,'office_operational_mismatch_rows'=>[],
        'office_unresolved'=>0,'office_unresolved_rows'=>[]
    ],
];
$ddUnitAcc=['by_office'=>[],'by_office_category'=>[],'category_total'=>['ALKES'=>0.0,'AKSESORIS'=>0.0],'daily'=>[],'total'=>0.0,'count'=>0,'diagnostic'=>[]];
foreach(['BGR','BKS','TGR','SLO','BDG','SMG','JGY','KAL'] as $uoc){
    $ddUnitAcc['by_office'][$uoc]=0.0;
    $ddUnitAcc['by_office_category'][$uoc]=['ALKES'=>0.0,'AKSESORIS'=>0.0];
}

try {
    $ddCols = static function(string $table) use ($pdo): array {
        try {
            $q=$pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?");
            $q->execute([$table]);
            return array_map('strtolower',$q->fetchAll(PDO::FETCH_COLUMN,0) ?: []);
        } catch(Throwable $e) { return []; }
    };
    $salesCols=$ddCols('sales_do');
    $custCols=$ddCols('master_customers');
    $retCols=$ddCols('sales_do_returns');
    $segMapCols=$ddCols('kpi_customer_segment_map');
    $itemCols=$ddCols('sales_do_items');
    $prodCols=$ddCols('master_products');
    $targetCols=$ddCols('kpi_targets');
    $has=static fn(array $cols,string $c): bool => in_array(strtolower($c),$cols,true);
    $first=static function(array $cols,array $cands): ?string {
        foreach($cands as $c) if(in_array(strtolower($c),$cols,true)) return $c;
        return null;
    };

    // Canonical item business group/category with safe master-product fallback.
    $groupNormExpr=static function(string $alias) use ($has,$itemCols,$prodCols): string {
        $raw="'BMHP'";
        $canMaster=$has($itemCols,'product_id') && $has($prodCols,'id') && $has($prodCols,'business_group');
        if($has($itemCols,'business_group') && $canMaster){
            $raw="COALESCE(NULLIF({$alias}.business_group,''),(SELECT mp_bg.business_group FROM master_products mp_bg WHERE mp_bg.id={$alias}.product_id LIMIT 1),'BMHP')";
        } elseif($has($itemCols,'business_group')) {
            $raw="COALESCE(NULLIF({$alias}.business_group,''),'BMHP')";
        } elseif($canMaster) {
            $raw="COALESCE((SELECT mp_bg.business_group FROM master_products mp_bg WHERE mp_bg.id={$alias}.product_id LIMIT 1),'BMHP')";
        }
        return "REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE({$raw},'BMHP'))),' ',''),'-',''),'_','')";
    };
    $categoryNormExpr=static function(string $alias) use ($has,$itemCols,$prodCols): string {
        $raw="''";
        $canMaster=$has($itemCols,'product_id') && $has($prodCols,'id') && $has($prodCols,'category');
        if($has($itemCols,'category') && $canMaster){
            $raw="COALESCE(NULLIF({$alias}.category,''),(SELECT mp_cat.category FROM master_products mp_cat WHERE mp_cat.id={$alias}.product_id LIMIT 1),'')";
        } elseif($has($itemCols,'category')) {
            $raw="COALESCE(NULLIF({$alias}.category,''),'')";
        } elseif($canMaster) {
            $raw="COALESCE((SELECT mp_cat.category FROM master_products mp_cat WHERE mp_cat.id={$alias}.product_id LIMIT 1),'')";
        }
        return "REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE({$raw},''))),' ',''),'-',''),'_','')";
    };

    $dateCol=$has($salesCols,'do_date')?'do_date':($has($salesCols,'date')?'date':null);
    if($dateCol){
        $custNameCol=$first($custCols,['customers_name','customer_name','name']);
        $custTypeCol=$first($custCols,['customer_type','customers_type','type','category','customer_category']);
        $custSegmentCol=$first($custCols,['segment','customer_segment','segment_code','customer_segment_code']);
        $custIntCol=$first($custCols,['is_internal','internal_flag','is_intercompany']);
        // Office pencapaian harus dipisahkan dari office operasional DO.
        // Prioritas: field revenue/achievement eksplisit di sales_do -> office master customer -> office operasional -> prefix DO.
        $custOfficeCol=$first($custCols,['office_achievement','achievement_office','revenue_office','sales_office','office_code','office']);
        $doAchievementCol=$first($salesCols,['office_achievement','achievement_office','revenue_office','sales_office']);
        $doIntCol=$first($salesCols,['is_internal_transfer','is_internal','internal_flag','is_intercompany']);

        $custMatch="1=0";
        if($has($salesCols,'customer_id') && $has($custCols,'id') && $has($custCols,'customers_code')){
            $custMatch="((COALESCE(d.customer_id,0)>0 AND c.id=d.customer_id) OR (COALESCE(d.customer_id,0)<=0 AND c.customers_code=d.customers_code))";
        } elseif($has($custCols,'customers_code')){
            $custMatch="c.customers_code=d.customers_code";
        }

        $custNameExpr=$custNameCol?"(SELECT c.`{$custNameCol}` FROM master_customers c WHERE {$custMatch} LIMIT 1)":"NULL";
        $custTypeExpr=$custTypeCol?"(SELECT c.`{$custTypeCol}` FROM master_customers c WHERE {$custMatch} LIMIT 1)":"NULL";
        $custSegmentExpr=$custSegmentCol?"(SELECT c.`{$custSegmentCol}` FROM master_customers c WHERE {$custMatch} LIMIT 1)":"NULL";
        $custIntExpr=$custIntCol?"(SELECT c.`{$custIntCol}` FROM master_customers c WHERE {$custMatch} LIMIT 1)":"NULL";
        $custOfficeExpr=$custOfficeCol?"(SELECT c.`{$custOfficeCol}` FROM master_customers c WHERE {$custMatch} LIMIT 1)":"NULL";
        $doAchievementExpr=$doAchievementCol?"d.`{$doAchievementCol}`":"NULL";
        $doIntExpr=$doIntCol?"d.`{$doIntCol}`":"NULL";
        $replacementFlagExpr=$has($salesCols,'is_replacement_fulfillment')?"COALESCE(d.is_replacement_fulfillment,0)":"0";

        $sqlActual="SELECT d.id,d.do_code,DATE(d.`{$dateCol}`) do_date,
                           UPPER(TRIM(COALESCE(d.office_code,''))) office_code_db,
                           UPPER(TRIM(COALESCE({$doAchievementExpr},''))) office_achievement_do,
                           UPPER(TRIM(COALESCE({$custOfficeExpr},''))) office_achievement_master,
                           d.customers_code,
                           LOWER(TRIM(COALESCE(d.status,''))) status_now,
                           {$custNameExpr} customer_name,
                           {$custTypeExpr} customer_type,
                           {$custSegmentExpr} customer_segment_master,
                           {$custIntExpr} customer_internal_flag,
                           {$doIntExpr} do_internal_flag,
                           {$replacementFlagExpr} is_replacement_fulfillment,
                           COALESCE((SELECT SUM(COALESCE(i.subtotal,0)) FROM sales_do_items i WHERE i.do_id=d.id),0) net_items
                    FROM sales_do d
                    WHERE DATE(d.`{$dateCol}`) BETWEEN ? AND ?
                      AND UPPER(TRIM(COALESCE(d.do_code,''))) LIKE 'BMHP-%'";
        if($has($salesCols,'deleted_at')) $sqlActual.=" AND d.deleted_at IS NULL";
        $sqlActual.=" ORDER BY DATE(d.`{$dateCol}`),d.id";

        $stActual=$pdo->prepare($sqlActual);
        $stActual->execute([$data['month_start'],$data['as_of']]);
        $actualRows=$stActual->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Mapping segment eksplisit bila tabel tersedia. Schema dibaca dinamis agar aman pada legacy.
        $segmentMapByCode=[];
        if(!empty($segMapCols)){
            try{
                $mapCodeCol=$first($segMapCols,['customers_code','customer_code','code']);
                $mapSegCol=$first($segMapCols,['segment','segment_code','customer_segment','customer_segment_code']);
                $mapActiveCol=$first($segMapCols,['is_active','active']);
                if($mapCodeCol && $mapSegCol){
                    $sqlMap="SELECT UPPER(TRIM(COALESCE(`{$mapCodeCol}`,''))) customer_code,
                                    UPPER(TRIM(COALESCE(`{$mapSegCol}`,''))) segment_value
                             FROM kpi_customer_segment_map
                             WHERE TRIM(COALESCE(`{$mapCodeCol}`,''))<>''";
                    if($mapActiveCol) $sqlMap.=" AND COALESCE(`{$mapActiveCol}`,1)=1";
                    $qMap=$pdo->query($sqlMap);
                    foreach($qMap->fetchAll(PDO::FETCH_ASSOC) ?: [] as $mr){
                        $mc=strtoupper(trim((string)($mr['customer_code']??'')));
                        $ms=normalize_dashboard_segment($mr['segment_value']??'');
                        if($mc!=='' && in_array($ms,['HERMINA','NON_HERMINA'],true)){
                            $segmentMapByCode[$mc]=$ms;
                        }
                    }
                }
            } catch(Throwable $e){}
        }

        $returnByDo=[];
        // Kebijakan achievement existing:
        // REVERSAL mengurangi penjualan. REPLACEMENT juga mengurangi qty/nilai DO asal,
        // lalu DO pengganti masuk sebagai fulfillment NET satu kali. OPERATIONAL_ONLY tidak mengurangi.
        if($has($retCols,'commercial_effect')){
            try{
                $sqlRet="SELECT r.do_id,
                                COALESCE(SUM(COALESCE(ri.qty_return,0)*(COALESCE(di.subtotal,0)/NULLIF(di.qty,0))),0) adj
                         FROM sales_do_returns r
                         JOIN sales_do_return_items ri ON ri.return_id=r.id AND ri.do_id=r.do_id
                         JOIN sales_do_items di ON di.id=ri.do_item_id AND di.do_id=r.do_id
                         WHERE LOWER(TRIM(COALESCE(r.status,'')))='return_scm_completed'
                           AND UPPER(TRIM(COALESCE(r.commercial_effect,''))) IN ('REVERSAL','REPLACEMENT')
                           AND COALESCE(ri.qty_return,0)>0
                           ";
                if($has($retCols,'scm_completed_at')) $sqlRet.=" AND r.scm_completed_at<=?";
                $sqlRet.=" GROUP BY r.do_id";
                $qRet=$pdo->prepare($sqlRet);
                $has($retCols,'scm_completed_at') ? $qRet->execute([$data['as_of'].' 23:59:59']) : $qRet->execute();
                foreach($qRet->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rr) $returnByDo[(int)$rr['do_id']]=(float)$rr['adj'];
            } catch(Throwable $e){}
        }

        $excluded=['draft','cancelled','canceled','cancel','rejected','reject','void','voided','deleted','inactive'];
        $truthy=['1','Y','YES','TRUE','INTERNAL','INTERCOMPANY'];

        foreach($actualRows as $r){
            $ddActual['diagnostic']['rows']++;
            $status=strtolower(trim((string)($r['status_now']??'')));
            if(in_array($status,$excluded,true)){
                $ddActual['diagnostic']['excluded_status']++;
                continue;
            }

            $doCode=strtoupper(trim((string)($r['do_code']??'')));

            /*
             * OFFICE CANONICAL UNTUK PENCAPAIAN CABANG
             * ----------------------------------------
             * sales_do.office_code adalah office OPERASIONAL/creator dan tidak selalu sama
             * dengan pemilik revenue. Contoh valid: transaksi diproses Bogor tetapi pendapatan
             * milik Depo Kalimantan. Karena itu distribusi pencapaian tidak boleh hanya mengikuti
             * office_code atau prefix nomor DO.
             *
             * Prioritas revenue office:
             *   1) field office_achievement/revenue_office eksplisit pada sales_do bila schema punya;
             *   2) office canonical dari master_customers;
             *   3) sales_do.office_code sebagai fallback legacy;
             *   4) prefix BMHP-<OFFICE>-... sebagai fallback paling akhir.
             *
             * Nilai total perusahaan tidak berubah; yang diperbaiki hanya distribusi per cabang.
             */
            $validOffices=['BGR','BKS','TGR','SLO','BDG','SMG','JGY','KAL'];
            $officeDb=strtoupper(trim((string)($r['office_code_db']??'')));
            $officeDoAchievement=strtoupper(trim((string)($r['office_achievement_do']??'')));
            $officeMaster=strtoupper(trim((string)($r['office_achievement_master']??'')));
            $officePrefix='';
            if(preg_match('/^BMHP-([A-Z0-9]+)-/',$doCode,$m)){
                $cand=strtoupper($m[1]);
                if(in_array($cand,$validOffices,true)) $officePrefix=$cand;
            }

            $office='';
            $officeSource='';
            if(in_array($officeDoAchievement,$validOffices,true)){
                $office=$officeDoAchievement;
                $officeSource='SALES_DO_ACHIEVEMENT';
            } elseif(in_array($officeMaster,$validOffices,true)){
                $office=$officeMaster;
                $officeSource='MASTER_CUSTOMER';
            } elseif(in_array($officeDb,$validOffices,true)){
                $office=$officeDb;
                $officeSource='SALES_DO_OPERATIONAL_FALLBACK';
            } elseif(in_array($officePrefix,$validOffices,true)){
                $office=$officePrefix;
                $officeSource='DO_PREFIX_FALLBACK';
            }

            if($office===''){
                $ddActual['diagnostic']['office_unresolved']++;
                if(count($ddActual['diagnostic']['office_unresolved_rows'])<100){
                    $ddActual['diagnostic']['office_unresolved_rows'][]=[
                        'do_code'=>$doCode,'customer_code'=>(string)($r['customers_code']??''),
                        'office_operational'=>$officeDb,'office_master'=>$officeMaster,'office_prefix'=>$officePrefix,
                    ];
                }
                continue;
            }
            $ddActual['diagnostic']['office_assignment_source'][$officeSource]=
                ($ddActual['diagnostic']['office_assignment_source'][$officeSource]??0)+1;

            // Mismatch operasional vs pencapaian adalah informasi governance, BUKAN kesalahan otomatis.
            // Ini justru kasus yang sebelumnya membuat nilai cabang bergeser meski total perusahaan benar.
            if($officeDb!=='' && in_array($officeDb,$validOffices,true) && $officeDb!==$office){
                $ddActual['diagnostic']['office_operational_mismatch']++;
                if(count($ddActual['diagnostic']['office_operational_mismatch_rows'])<100){
                    $ddActual['diagnostic']['office_operational_mismatch_rows'][]=[
                        'do_code'=>$doCode,
                        'customer_code'=>(string)($r['customers_code']??''),
                        'office_operational'=>$officeDb,
                        'office_achievement'=>$office,
                        'source'=>$officeSource,
                    ];
                }
            }

            // Prefix mismatch tetap dicatat sebagai informasi audit, namun tidak menentukan revenue office.
            if($officePrefix!=='' && $officePrefix!==$office){
                $ddActual['diagnostic']['office_prefix_mismatch']++;
                if(count($ddActual['diagnostic']['office_prefix_mismatch_rows'])<100){
                    $ddActual['diagnostic']['office_prefix_mismatch_rows'][]=[
                        'do_code'=>$doCode,
                        'office_code'=>$officeDb,
                        'prefix_office'=>$officePrefix,
                        'used_office'=>$office,
                        'source'=>$officeSource,
                    ];
                }
            }

            $custCode=strtoupper(trim((string)($r['customers_code']??'')));
            $custName=strtoupper(trim((string)($r['customer_name']??'')));
            $custType=strtoupper(trim((string)($r['customer_type']??'')));
            $custSegmentMaster=normalize_dashboard_segment($r['customer_segment_master']??'');
            $custFlag=strtoupper(trim((string)($r['customer_internal_flag']??'')));
            $doFlag=strtoupper(trim((string)($r['do_internal_flag']??'')));

            $isInternal=(bool)preg_match('/(^|[-_])(INT|INTERNAL)($|[-_])/',$custCode)
                || str_starts_with($custName,'KANTOR RIZQULLAH MEDISKA INDONESIA')
                || str_starts_with($custName,'KANTOR DEPO ')
                || in_array($custType,['INTERNAL','INTERCOMPANY'],true)
                || in_array($custFlag,$truthy,true)
                || in_array($doFlag,$truthy,true);
            if($isInternal){
                $ddActual['diagnostic']['internal']++;
                continue;
            }

            $original=max(0.0,(float)($r['net_items']??0));
            $adj=max(0.0,min($original,(float)($returnByDo[(int)$r['id']]??0)));
            if($adj>0) $ddActual['diagnostic']['return_adjustment']+=$adj;
            /*
             * Jangan membulatkan per DO.
             * Executive Summary menjumlahkan ledger efektif terlebih dahulu, baru formatting
             * Rupiah dilakukan pada output. round() per DO menimbulkan cumulative rounding drift
             * (contoh selisih +Rp6 pada 09-Sep-2026 walau data Sales DO sama).
             */
            $effective=max(0.0,$original-$adj);
            // Replacement child tetap fulfillment NET satu kali, tetapi bila child itu sendiri
            // diretur secara komersial maka retur child tetap harus mengurangi nilainya.
            if($effective<=0) continue;

            /*
             * V12 segment classifier — SINGLE SOURCE OF TRUTH:
             * 1. master_customers.segment (canonical, dapat dikoreksi via Master Customer);
             * 2. master customer type/category bila memang eksplisit Hermina/Non Hermina;
             * 3. kpi_customer_segment_map hanya fallback legacy bila master belum punya segment;
             * 4. nama customer mengandung HERMINA;
             * 5. prefix kode H/NH hanya fallback terakhir.
             *
             * Dengan urutan ini, koreksi master (contoh customer yang sebelumnya salah segment)
             * langsung berlaku ke seluruh dashboard dan tidak bisa ditimpa mapping legacy stale.
             * Tidak ada hard-code nominal, DO, customer tertentu, atau tanggal.
             */
            $segment='';
            $segmentSource='';

            if(in_array($custSegmentMaster,['HERMINA','NON_HERMINA'],true)){
                $segment=$custSegmentMaster;
                $segmentSource='MASTER_SEGMENT';
                $ddActual['diagnostic']['segment_master']++;
                if(isset($segmentMapByCode[$custCode]) && $segmentMapByCode[$custCode]!==$custSegmentMaster){
                    $ddActual['diagnostic']['segment_conflict']++;
                    if(count($ddActual['diagnostic']['segment_conflict_rows'])<50){
                        $ddActual['diagnostic']['segment_conflict_rows'][]=[
                            'customers_code'=>$custCode,
                            'customer_name'=>$custName,
                            'master_segment'=>$custSegmentMaster,
                            'legacy_map_segment'=>$segmentMapByCode[$custCode],
                        ];
                    }
                }
            } else {
                $typeNorm=normalize_dashboard_segment($custType);
                if($typeNorm==='NON_HERMINA' || str_contains($custType,'NON HERMINA') || str_contains($custType,'NON_HERMINA')){
                    $segment='NON_HERMINA';
                    $segmentSource='MASTER_TYPE';
                    $ddActual['diagnostic']['segment_master']++;
                } elseif($typeNorm==='HERMINA' || (str_contains($custType,'HERMINA') && !str_contains($custType,'NON'))){
                    $segment='HERMINA';
                    $segmentSource='MASTER_TYPE';
                    $ddActual['diagnostic']['segment_master']++;
                } elseif(isset($segmentMapByCode[$custCode])){
                    $segment=$segmentMapByCode[$custCode];
                    $segmentSource='MAP_FALLBACK';
                    $ddActual['diagnostic']['segment_map']++;
                } elseif(str_contains($custName,'HERMINA')){
                    $segment='HERMINA';
                    $segmentSource='NAME';
                    $ddActual['diagnostic']['segment_name']++;
                } elseif(preg_match('/^NH[0-9A-Z_-]*$/',$custCode)){
                    $segment='NON_HERMINA';
                    $segmentSource='CODE_FALLBACK';
                    $ddActual['diagnostic']['segment_code']++;
                } elseif(preg_match('/^H[0-9A-Z_-]*$/',$custCode)){
                    $segment='HERMINA';
                    $segmentSource='CODE_FALLBACK';
                    $ddActual['diagnostic']['segment_code']++;
                } else {
                    $segment='NON_HERMINA';
                    $segmentSource='FALLBACK_EXTERNAL';
                    $ddActual['diagnostic']['segment_fallback']++;
                    $ddActual['diagnostic']['segment_fallback_rows'][]=[
                        'do_code'=>$doCode,
                        'customers_code'=>$custCode,
                        'customer_name'=>$custName,
                        'office'=>$office,
                        'value'=>$effective,
                    ];
                }
            }

            $ddActual['by_office_segment'][$office][$segment]=($ddActual['by_office_segment'][$office][$segment]??0.0)+$effective;
            $ddActual['by_office'][$office]=($ddActual['by_office'][$office]??0.0)+$effective;
            $ddActual['daily'][(string)$r['do_date']]=($ddActual['daily'][(string)$r['do_date']]??0.0)+$effective;
            $ddActual['total']+=$effective;
        }
    }
} catch(Throwable $e){
    $ddActual['diagnostic']['error']=$e->getMessage();
}

// UNIT ACC actual ledger is calculated separately at item level. This is critical for mixed DOs.
try {
    if(isset($itemCols,$dateCol,$groupNormExpr,$categoryNormExpr) && !empty($itemCols) && $dateCol && $has($itemCols,'do_id') && $has($itemCols,'subtotal')){
        $uaGroup=$groupNormExpr('ui');
        $uaCat=$categoryNormExpr('ui');
        $uaSql="SELECT d.id,d.do_code,DATE(d.`{$dateCol}`) do_date,
                       UPPER(TRIM(COALESCE(d.office_code,''))) office_code_db,
                       UPPER(TRIM(COALESCE({$doAchievementExpr},''))) office_achievement_do,
                       UPPER(TRIM(COALESCE({$custOfficeExpr},''))) office_achievement_master,
                       d.customers_code,LOWER(TRIM(COALESCE(d.status,''))) status_now,
                       {$custNameExpr} customer_name,{$custTypeExpr} customer_type,
                       {$custIntExpr} customer_internal_flag,{$doIntExpr} do_internal_flag,
                       COALESCE((SELECT SUM(COALESCE(ui.subtotal,0)) FROM sales_do_items ui WHERE ui.do_id=d.id),0) ua_total,
                       COALESCE((SELECT SUM(COALESCE(ui.subtotal,0)) FROM sales_do_items ui WHERE ui.do_id=d.id AND {$uaCat} IN ('ALKES','ALATKESEHATAN')),0) ua_alkes,
                       COALESCE((SELECT SUM(COALESCE(ui.subtotal,0)) FROM sales_do_items ui WHERE ui.do_id=d.id AND {$uaCat} IN ('AKSESORIS','AKSESORI','ACCESSORY','ACCESSORIES')),0) ua_aks
                FROM sales_do d
                WHERE DATE(d.`{$dateCol}`) BETWEEN ? AND ?
                  AND UPPER(TRIM(COALESCE(d.do_code,''))) LIKE 'UNITACC-%'";
        if($has($salesCols,'deleted_at')) $uaSql.=" AND d.deleted_at IS NULL";
        $qUa=$pdo->prepare($uaSql);$qUa->execute([$data['month_start'],$data['as_of']]);

        $uaReturnByDo=[];
        if($has($retCols,'commercial_effect')){
            try{
                $retUa="SELECT r.do_id,COALESCE(SUM(COALESCE(ri.qty_return,0)*(COALESCE(di.subtotal,0)/NULLIF(di.qty,0))),0) adj
                        FROM sales_do_returns r
                        JOIN sales_do_return_items ri ON ri.return_id=r.id AND ri.do_id=r.do_id
                        JOIN sales_do_items di ON di.id=ri.do_item_id AND di.do_id=r.do_id
                        WHERE LOWER(TRIM(COALESCE(r.status,'')))='return_scm_completed'
                          AND UPPER(TRIM(COALESCE(r.commercial_effect,''))) IN ('REVERSAL','REPLACEMENT')
                          AND COALESCE(ri.qty_return,0)>0";
                if($has($retCols,'scm_completed_at')) $retUa.=" AND r.scm_completed_at<=?";
                $retUa.=" GROUP BY r.do_id";
                $qr=$pdo->prepare($retUa);$has($retCols,'scm_completed_at')?$qr->execute([$data['as_of'].' 23:59:59']):$qr->execute();
                foreach($qr->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rr) $uaReturnByDo[(int)$rr['do_id']]=(float)$rr['adj'];
            }catch(Throwable $e){$ddUnitAcc['diagnostic']['return_error']=$e->getMessage();}
        }

        $excludedUa=['draft','cancelled','canceled','cancel','rejected','reject','void','voided','deleted','inactive'];
        $truthyUa=['1','Y','YES','TRUE','INTERNAL','INTERCOMPANY'];$validUa=['BGR','BKS','TGR','SLO','BDG','SMG','JGY','KAL'];
        foreach($qUa->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ur){
            if(in_array(strtolower(trim((string)($ur['status_now']??''))),$excludedUa,true)) continue;
            $cc=strtoupper(trim((string)($ur['customers_code']??'')));$cn=strtoupper(trim((string)($ur['customer_name']??'')));
            $ct=strtoupper(trim((string)($ur['customer_type']??'')));$cf=strtoupper(trim((string)($ur['customer_internal_flag']??'')));$df=strtoupper(trim((string)($ur['do_internal_flag']??'')));
            $internal=(bool)preg_match('/(^|[-_])(INT|INTERNAL)($|[-_])/',$cc)||str_starts_with($cn,'KANTOR RIZQULLAH MEDISKA INDONESIA')||str_starts_with($cn,'KANTOR DEPO ')||in_array($ct,['INTERNAL','INTERCOMPANY'],true)||in_array($cf,$truthyUa,true)||in_array($df,$truthyUa,true);
            if($internal) continue;
            $od=strtoupper(trim((string)($ur['office_achievement_do']??'')));$om=strtoupper(trim((string)($ur['office_achievement_master']??'')));$odb=strtoupper(trim((string)($ur['office_code_db']??'')));
            $op='';$dc=strtoupper(trim((string)($ur['do_code']??'')));if(preg_match('/^[A-Z0-9]+-([A-Z0-9]+)-/',$dc,$mm)&&in_array(strtoupper($mm[1]),$validUa,true))$op=strtoupper($mm[1]);
            $oc=in_array($op,$validUa,true)?$op:(in_array($od,$validUa,true)?$od:(in_array($om,$validUa,true)?$om:$odb));if(!in_array($oc,$validUa,true))continue;
            $gross=max(0.0,(float)($ur['ua_total']??0));$adj=max(0.0,min($gross,(float)($uaReturnByDo[(int)$ur['id']]??0)));$net=max(0.0,$gross-$adj);if($net<=0)continue;
            $alk=max(0.0,(float)($ur['ua_alkes']??0));$aks=max(0.0,(float)($ur['ua_aks']??0));$known=$alk+$aks;
            if($known<$gross-0.01){$aks+=($gross-$known);$ddUnitAcc['diagnostic']['category_fallback_amount']=($ddUnitAcc['diagnostic']['category_fallback_amount']??0.0)+($gross-$known);}
            if($adj>0&&$gross>0){$ratio=$net/$gross;$alk*=$ratio;$aks*=$ratio;}
            $ddUnitAcc['by_office'][$oc]+=$net;$ddUnitAcc['by_office_category'][$oc]['ALKES']+=$alk;$ddUnitAcc['by_office_category'][$oc]['AKSESORIS']+=$aks;
            $ddUnitAcc['category_total']['ALKES']+=$alk;$ddUnitAcc['category_total']['AKSESORIS']+=$aks;$ddUnitAcc['total']+=$net;$ddUnitAcc['count']++;
            $ud=(string)($ur['do_date']??'');if($ud!=='')$ddUnitAcc['daily'][$ud]=($ddUnitAcc['daily'][$ud]??0.0)+$net;
        }
    }
}catch(Throwable $e){$ddUnitAcc['diagnostic']['error']=$e->getMessage();}

$applyActualRows=static function(array $rows,string $segment,array $actual,string $asOf) use ($data): array {
    // Canonical office order: tabel target selalu lengkap dan stabil walau payload service/cache
    // lama kehilangan satu office. Target tetap berasal dari data target, pencapaian dari actual ledger.
    $officeOrder=['BGR','BKS','SLO','BDG','SMG','JGY','KAL','TGR'];
    $existing=[];
    foreach($rows as $r){
        if(!empty($r['is_total']) || strtoupper((string)($r['office_code']??''))==='TOTAL') continue;
        $oc=strtoupper(trim((string)($r['office_code']??'')));
        if($oc!=='') $existing[$oc]=$r;
    }

    $out=[];
    foreach($officeOrder as $oc){
        $r=$existing[$oc]??[];
        $t=array_key_exists('target',$r)
            ? (float)$r['target']
            : (float)($data['targets']['by_segment_office'][$segment][$oc]??0);
        $p=(float)($actual[$oc][$segment]??0);
        $r['tanggal']=$asOf;
        $r['office_code']=$oc;
        $r['office']=$oc==='TGR' ? 'Tangerang' : (string)($r['office']??($data['office_meta'][$oc]['name']??$oc));
        $r['target']=$t;
        $r['pencapaian']=$p;
        $r['persentase']=$t>0?($p/$t)*100.0:0.0;
        $out[]=$r;
    }

    $tt=0.0;$pp=0.0;
    foreach($out as $r){$tt+=(float)$r['target'];$pp+=(float)$r['pencapaian'];}
    $out[]=['tanggal'=>$asOf,'office_code'=>'TOTAL','office'=>'TOTAL','target'=>$tt,'pencapaian'=>$pp,'persentase'=>$tt>0?($pp/$tt)*100.0:0.0,'is_total'=>true];
    return $out;
};

$data['section1']['non_hermina']=$applyActualRows((array)($data['section1']['non_hermina']??[]),'NON_HERMINA',$ddActual['by_office_segment'],$data['as_of']);
$data['section1']['hermina']=$applyActualRows((array)($data['section1']['hermina']??[]),'HERMINA',$ddActual['by_office_segment'],$data['as_of']);
// UNIT ACC adalah ledger terpisah, bukan office baru.
// Source of truth achievement = family DO UNITACC-*; kategori ALKES/AKSESORIS hanya untuk breakdown item.
$unitAccService = (array)($data['sales']['unit_acc'] ?? []);
$unitAccByOffice = ($ddUnitAcc['total']>0 || empty($unitAccService['by_office'])) ? (array)$ddUnitAcc['by_office'] : (array)$unitAccService['by_office'];
$unitAccByOfficeCategory = ($ddUnitAcc['total']>0 || empty($unitAccService['by_office_category'])) ? (array)$ddUnitAcc['by_office_category'] : (array)$unitAccService['by_office_category'];
$unitAccCategoryTotal = ($ddUnitAcc['total']>0 || empty($unitAccService['category_total'])) ? (array)$ddUnitAcc['category_total'] : (array)$unitAccService['category_total'];
$unitAccTargetFallback=[];
try{
    if(!empty($targetCols) && $has($targetCols,'segment') && $has($targetCols,'target_amount') && $has($targetCols,'month_no') && $has($targetCols,'year_no')){
        $hasOfficeId=$has($targetCols,'office_id') && !empty($ddCols('master_office'));
        $sqlT="SELECT ".($hasOfficeId?"UPPER(COALESCE(o.office_code,'ALL'))":"'ALL'")." office_code,SUM(COALESCE(t.target_amount,0)) target_amount FROM kpi_targets t ".($hasOfficeId?"LEFT JOIN master_office o ON o.id=t.office_id":"")." WHERE t.month_no=? AND t.year_no=? AND REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE(t.segment,''))),' ',''),'-',''),'_','') IN ('ACCUNIT','UNITACC')";
        if($hasOfficeId)$sqlT.=" GROUP BY UPPER(COALESCE(o.office_code,'ALL'))";
        $qt=$pdo->prepare($sqlT);$qt->execute([$monthNo,$yearNo]);foreach($qt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $tr)$unitAccTargetFallback[strtoupper((string)$tr['office_code'])]=(float)$tr['target_amount'];
    }
}catch(Throwable $e){}
$unitAccRows=[];
$unitAccTargetTotal=0.0;
$unitAccAchTotal=0.0;
foreach(['BGR','BKS','TGR','SLO','BDG','SMG','JGY','KAL'] as $oc){
    $t=(float)($data['targets']['by_segment_office']['ACCUNIT'][$oc]??0);
    if($t<=0 && isset($unitAccTargetFallback[$oc]))$t=(float)$unitAccTargetFallback[$oc];
    $a=(float)($unitAccByOffice[$oc]??0);
    $unitAccRows[]=[
        'tanggal'=>$data['as_of'],
        'office_code'=>$oc,
        'office'=>$oc==='TGR' ? 'Tangerang' : (string)($data['office_meta'][$oc]['name']??$oc),
        'target'=>$t,
        'pencapaian'=>$a,
        'alkes'=>(float)($unitAccByOfficeCategory[$oc]['ALKES']??0),
        'aksesoris'=>(float)($unitAccByOfficeCategory[$oc]['AKSESORIS']??0),
        'persentase'=>$t>0?($a/$t)*100.0:0.0,
    ];
    $unitAccTargetTotal+=$t;
    $unitAccAchTotal+=$a;
}
// Bila target ACCUNIT disimpan sebagai target korporat/ALL (seperti rekap manual),
// jangan hilangkan hanya karena tidak dibagi per office.
$unitAccTargetAll=(float)($unitAccTargetFallback['ALL']??0);
if($unitAccTargetAll>$unitAccTargetTotal) $unitAccTargetTotal=$unitAccTargetAll;
$unitAccRows[]=['tanggal'=>$data['as_of'],'office_code'=>'TOTAL','office'=>'TOTAL UNIT ACC','target'=>$unitAccTargetTotal,'pencapaian'=>$unitAccAchTotal,'alkes'=>(float)($unitAccCategoryTotal['ALKES']??0),'aksesoris'=>(float)($unitAccCategoryTotal['AKSESORIS']??0),'persentase'=>$unitAccTargetTotal>0?($unitAccAchTotal/$unitAccTargetTotal)*100.0:0.0,'is_total'=>true];
$data['section1']['accunit']=$unitAccRows;

foreach(['BGR','BKS','TGR','SLO','BDG','SMG','JGY','KAL'] as $oc){
    $data['sales']['office_sales_mtd'][$oc]=(float)($ddActual['by_office'][$oc]??0);
    foreach(['HERMINA','NON_HERMINA'] as $sg){
        if(!isset($data['sales']['by_office_segment'][$oc][$sg]) || !is_array($data['sales']['by_office_segment'][$oc][$sg])) $data['sales']['by_office_segment'][$oc][$sg]=[];
        $data['sales']['by_office_segment'][$oc][$sg]['net_sales']=(float)($ddActual['by_office_segment'][$oc][$sg]??0);
    }
}

$mainOfficeCodes=['BGR','BKS','TGR','SLO','BDG','SMG'];
$nonMain=0.0;$herMain=0.0;
foreach($mainOfficeCodes as $oc){
    $nonMain+=(float)($ddActual['by_office_segment'][$oc]['NON_HERMINA']??0);
    $herMain+=(float)($ddActual['by_office_segment'][$oc]['HERMINA']??0);
}
$depoYoga=(float)($ddActual['by_office']['JGY']??0);
$depoKal=(float)($ddActual['by_office']['KAL']??0);

$ringTargets=['NON_HERMINA'=>0.0,'HERMINA'=>0.0,'JGY'=>0.0,'KAL'=>0.0];
foreach((array)($data['section1']['ringkasan']??[]) as $rr){
    $lab=strtoupper(trim((string)($rr['label']??'')));
    if(str_contains($lab,'NON') && str_contains($lab,'HERMINA')) $ringTargets['NON_HERMINA']=(float)($rr['target']??0);
    elseif($lab==='HERMINA') $ringTargets['HERMINA']=(float)($rr['target']??0);
    elseif(str_contains($lab,'YOG')) $ringTargets['JGY']=(float)($rr['target']??0);
    elseif(str_contains($lab,'SAMAR')) $ringTargets['KAL']=(float)($rr['target']??0);
}

// RINGKASAN hanya 2 bucket utama. Tangerang digabung ke bucket Hermina/Non Hermina;
// Depo tidak ditampilkan sebagai baris tersendiri pada ringkasan.
$tgrTargetH=0.0;$tgrTargetN=0.0;
foreach((array)($data['section1']['tangerang']??[]) as $rr){
    $lab=strtoupper((string)($rr['label']??''));
    if(str_contains($lab,'NON')) $tgrTargetN=(float)($rr['target']??0);
    elseif(str_contains($lab,'HERMINA')) $tgrTargetH=(float)($rr['target']??0);
}
$tgrH=(float)($ddActual['by_office_segment']['TGR']['HERMINA']??0);
$tgrN=(float)($ddActual['by_office_segment']['TGR']['NON_HERMINA']??0);

// Target ringkasan dari DashboardDetailService SUDAH memasukkan TGR pada mainCore.
// Jangan tambahkan TGR lagi di sini karena akan double count target Tangerang.
$data['section1']['ringkasan']=[
    ['label'=>'Non Hermina','segment'=>'NON_HERMINA','target'=>$ringTargets['NON_HERMINA'],'pencapaian'=>$nonMain,'persentase'=>$ringTargets['NON_HERMINA']>0?($nonMain/$ringTargets['NON_HERMINA'])*100:0],
    ['label'=>'Hermina','segment'=>'HERMINA','target'=>$ringTargets['HERMINA'],'pencapaian'=>$herMain,'persentase'=>$ringTargets['HERMINA']>0?($herMain/$ringTargets['HERMINA'])*100:0],
];
$ringT=$ringTargets['NON_HERMINA']+$ringTargets['HERMINA'];
$ringP=$nonMain+$herMain;

// Data Tangerang tetap dipertahankan internal untuk kompatibilitas/alur lama,
// tetapi blok tampilannya di tab Target & Pencapaian dihilangkan.
$data['section1']['tangerang']=[
    ['label'=>'Hermina','target'=>$tgrTargetH,'pencapaian'=>$tgrH,'persentase'=>$tgrTargetH>0?($tgrH/$tgrTargetH)*100:0],
    ['label'=>'Non Hermina','target'=>$tgrTargetN,'pencapaian'=>$tgrN,'persentase'=>$tgrTargetN>0?($tgrN/$tgrTargetN)*100:0],
    ['label'=>'TOTAL','target'=>$tgrTargetH+$tgrTargetN,'pencapaian'=>$tgrH+$tgrN,'persentase'=>($tgrTargetH+$tgrTargetN)>0?(($tgrH+$tgrN)/($tgrTargetH+$tgrTargetN))*100:0,'is_total'=>true],
];

// Label UI canonical: TGR selalu ditampilkan sebagai 'Tangerang' (bukan 'RMI Tangerang').
// Hanya label yang dinormalisasi; office_code TGR dan seluruh scope/query tetap tidak berubah.
if (isset($data['office_meta']['TGR']) && is_array($data['office_meta']['TGR'])) {
    $data['office_meta']['TGR']['name'] = 'Tangerang';
}
foreach (['main','tangerang','accunit'] as $ddBlockKey) {
    if (!empty($data['section2'][$ddBlockKey]['rows']) && is_array($data['section2'][$ddBlockKey]['rows'])) {
        foreach ($data['section2'][$ddBlockKey]['rows'] as &$ddOfficeRow) {
            if (strtoupper((string)($ddOfficeRow['office_code'] ?? '')) === 'TGR') {
                $ddOfficeRow['office'] = 'Tangerang';
            }
        }
        unset($ddOfficeRow);
    }
}

// ALL CABANG BMHP = main office (termasuk TGR) + target Depo Yogya + Depo Samarinda.
// ringT sudah mencakup TGR satu kali; JGY/KAL sebelumnya memang dipisah dari ringkasan utama.
$allTarget=$ringT+$ringTargets['JGY']+$ringTargets['KAL'];
$consolidatedTarget=$allTarget+$unitAccTargetTotal;
$consolidatedAchievement=(float)$ddActual['total']+$unitAccAchTotal;
$data['section1']['all_cabang']=[
    ['tanggal'=>$data['as_of'],'keterangan'=>'RMI BMHP Recognized / Comparable','target'=>$allTarget,'pencapaian'=>$ddActual['total'],'persentase'=>$allTarget>0?($ddActual['total']/$allTarget)*100:0,'drill_segment'=>'BMHP_ALL','drill_office'=>null],
    ['tanggal'=>$data['as_of'],'keterangan'=>'Unit ACC (DO family UNITACC-*; ALKES + AKSESORIS)','target'=>$unitAccTargetTotal,'pencapaian'=>$unitAccAchTotal,'persentase'=>$unitAccTargetTotal>0?($unitAccAchTotal/$unitAccTargetTotal)*100:0,'drill_segment'=>'ACCUNIT','drill_office'=>null],
    ['tanggal'=>$data['as_of'],'keterangan'=>'CONSOLIDATED ACHIEVEMENT','target'=>$consolidatedTarget,'pencapaian'=>$consolidatedAchievement,'persentase'=>$consolidatedTarget>0?($consolidatedAchievement/$consolidatedTarget)*100:0,'is_total'=>true,'drill_segment'=>null,'drill_office'=>null],
];

if(isset($data['section1']['comparisons']) && is_array($data['section1']['comparisons'])) $data['section1']['comparisons']['current_total']=$ddActual['total'];

ksort($ddActual['daily']);
ksort($ddUnitAcc['daily']);
$labels=[];$dailyVals=[];$cumVals=[];$running=0.0;
$cursor=new DateTime($data['month_start']);$end=new DateTime($data['as_of']);
while($cursor<=$end){
    $d=$cursor->format('Y-m-d');
    // Daily canonical = RMI BMHP + Unit ACC, sama dengan ALL CABANG consolidated.
    $v=(float)($ddActual['daily'][$d]??0)+(float)($ddUnitAcc['daily'][$d]??0);
    $running+=$v;
    $labels[]=$d;$dailyVals[]=$v;$cumVals[]=$running;$cursor->modify('+1 day');
}
$data['daily_series']=['labels'=>$labels,'daily_total'=>$dailyVals,'cumulative_total'=>$cumVals];

// Canonical ALL OFFICE totals: always use the TOTAL row of section1.all_cabang.
// Total canonical = RMI BMHP + Unit ACC. Unit ACC dipisahkan berdasarkan family DO UNITACC-*, bukan office ACCUNIT.
$allOfficeTarget = 0.0;
$allOfficePencapaian = 0.0;
$allCabangCanonicalRows = $data['section1']['all_cabang'] ?? [];
foreach ($allCabangCanonicalRows as $allOfficeRow) {
    if (!empty($allOfficeRow['is_total'])) {
        $allOfficeTarget = (float)($allOfficeRow['target'] ?? 0);
        $allOfficePencapaian = (float)($allOfficeRow['pencapaian'] ?? 0);
        break;
    }
}
if (($allOfficeTarget == 0.0 && $allOfficePencapaian == 0.0) && count($allCabangCanonicalRows) > 0) {
    $lastAllOfficeRow = $allCabangCanonicalRows[count($allCabangCanonicalRows) - 1];
    $allOfficeTarget = (float)($lastAllOfficeRow['target'] ?? 0);
    $allOfficePencapaian = (float)($lastAllOfficeRow['pencapaian'] ?? 0);
}
$allOfficePct = $allOfficeTarget > 0 ? (($allOfficePencapaian / $allOfficeTarget) * 100.0) : 0.0;

// -------------------------------------------------------------------------
// DEPO RESTRICTED OUTPUT — berhenti di sini sebelum data Finance/AR/AP/GL.
// -------------------------------------------------------------------------
if ($__dd_is_depo_branch) {
    $officeCode = strtoupper(trim((string)$__dd_depo_office));
    if (!in_array($officeCode, ['KAL','JGY'], true)) {
        http_response_code(403);
        exit('Forbidden');
    }

    $findOfficeRow = static function(array $rows, string $office): array {
        foreach ($rows as $row) {
            if (!empty($row['is_total'])) continue;
            if (strtoupper(trim((string)($row['office_code'] ?? ''))) === $office) return $row;
        }
        return ['target'=>0.0,'pencapaian'=>0.0,'persentase'=>0.0];
    };

    $rowNon = $findOfficeRow((array)($data['section1']['non_hermina'] ?? []), $officeCode);
    $rowHer = $findOfficeRow((array)($data['section1']['hermina'] ?? []), $officeCode);
    $rowAcc = $findOfficeRow((array)($data['section1']['accunit'] ?? []), $officeCode);

    $targetBmhp = (float)($rowNon['target'] ?? 0) + (float)($rowHer['target'] ?? 0);
    $achBmhp = (float)($rowNon['pencapaian'] ?? 0) + (float)($rowHer['pencapaian'] ?? 0);
    $targetAcc = (float)($rowAcc['target'] ?? 0);
    $achAcc = (float)($rowAcc['pencapaian'] ?? 0);
    $targetTotal = $targetBmhp + $targetAcc;
    $achTotal = $achBmhp + $achAcc;
    $pctTotal = $targetTotal > 0 ? ($achTotal / $targetTotal) * 100.0 : 0.0;
    $officeName = (string)($data['office_meta'][$officeCode]['name'] ?? $officeCode);

    require_once __DIR__ . '/../../_shared/rmi_layout.php';
    $bp = rmi_layout_base_project();
    rmi_header('Pencapaian Depo ' . $officeCode, [
        'active' => 'depo_achievement',
        'subtitle' => 'Target vs Pencapaian — hanya office ' . $officeCode,
        'skip_panduan_link' => true,
        'breadcrumbs' => ['Depo', 'Pencapaian'],
        'actions' => [
            ['label'=>'Delivery Order','url'=>$bp . '/sales/sales_do.php','class'=>'btn btn-sm btn-outline-light'],
            ['label'=>'MPR','url'=>$bp . '/mpr/mpr_dashboard.php','class'=>'btn btn-sm btn-outline-light'],
        ],
        'extra_head' => '<style>.depo-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.depo-kpi{border:1px solid rgba(148,163,184,.25);border-radius:12px;padding:14px;background:rgba(15,23,42,.55)}.depo-kpi .lbl{font-size:12px;color:#94a3b8}.depo-kpi .val{font-size:22px;font-weight:700}.depo-table td,.depo-table th{vertical-align:middle}</style>',
    ]);
    ?>
    <div class="mb-3">
      <div class="fw-semibold"><?= h($officeName) ?> (<?= h($officeCode) ?>)</div>
      <div class="small text-secondary">Periode <?= h(sprintf('%02d/%04d', $monthNo, $yearNo)) ?> · As of <?= h($asOf) ?>. Data lain di Finance Dashboard tidak dibuka untuk akun Depo.</div>
    </div>
    <div class="depo-kpis mb-3">
      <div class="depo-kpi"><div class="lbl">Target Total</div><div class="val">Rp <?= h(f_money($targetTotal)) ?></div></div>
      <div class="depo-kpi"><div class="lbl">Pencapaian Total</div><div class="val">Rp <?= h(f_money($achTotal)) ?></div></div>
      <div class="depo-kpi"><div class="lbl">Persentase</div><div class="val"><?= h(f_pct($pctTotal)) ?></div></div>
    </div>
    <div class="card bg-dark-subtle border-secondary-subtle">
      <div class="card-body">
        <form class="row g-2 align-items-end mb-3" method="get">
          <div class="col-sm-3"><label class="form-label">Bulan</label><input class="form-control" type="number" min="1" max="12" name="month" value="<?= (int)$monthNo ?>"></div>
          <div class="col-sm-3"><label class="form-label">Tahun</label><input class="form-control" type="number" min="2000" max="2100" name="year" value="<?= (int)$yearNo ?>"></div>
          <div class="col-sm-3"><label class="form-label">As of</label><input class="form-control" type="date" name="as_of" value="<?= h($asOf) ?>"></div>
          <div class="col-sm-3"><button class="btn btn-primary w-100" type="submit">Tampilkan</button></div>
        </form>
        <div class="table-responsive">
          <table class="table table-sm depo-table mb-0">
            <thead><tr><th>Kelompok</th><th class="text-end">Target</th><th class="text-end">Pencapaian</th><th class="text-end">%</th></tr></thead>
            <tbody>
              <tr><td>BMHP — Non Hermina</td><td class="text-end">Rp <?= h(f_money($rowNon['target'] ?? 0)) ?></td><td class="text-end">Rp <?= h(f_money($rowNon['pencapaian'] ?? 0)) ?></td><td class="text-end"><?= h(f_pct($rowNon['persentase'] ?? 0)) ?></td></tr>
              <tr><td>BMHP — Hermina</td><td class="text-end">Rp <?= h(f_money($rowHer['target'] ?? 0)) ?></td><td class="text-end">Rp <?= h(f_money($rowHer['pencapaian'] ?? 0)) ?></td><td class="text-end"><?= h(f_pct($rowHer['persentase'] ?? 0)) ?></td></tr>
              <tr><td>Unit ACC</td><td class="text-end">Rp <?= h(f_money($targetAcc)) ?></td><td class="text-end">Rp <?= h(f_money($achAcc)) ?></td><td class="text-end"><?= h(f_pct($rowAcc['persentase'] ?? 0)) ?></td></tr>
              <tr class="fw-bold"><td>TOTAL <?= h($officeCode) ?></td><td class="text-end">Rp <?= h(f_money($targetTotal)) ?></td><td class="text-end">Rp <?= h(f_money($achTotal)) ?></td><td class="text-end"><?= h(f_pct($pctTotal)) ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
    rmi_footer();
    exit;
}

$tableExists = static function (string $t) use ($pdo): bool {
    if (function_exists('ds_table_exists')) {
        return ds_table_exists($pdo, $t);
    }
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
};
$columnExistsLocal = static function (string $t, string $c) use ($pdo): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $st->execute([$t, $c]);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
};

// Finance-specific exceptions: AR WAIT_PAYMENT overdue + AP overdue.
// Fallback AR memakai umur DO >30 hari bila kolom due_date belum tersedia.
$financeExceptionCount = 0;
try {
    if ($tableExists('sales_do')) {
        $dueExpr = $columnExistsLocal('sales_do', 'due_date')
            ? "(d.due_date IS NOT NULL AND d.due_date < ?)"
            : "d.do_date < DATE_SUB(?, INTERVAL 30 DAY)";
        $sqlExAr = "SELECT COUNT(*) FROM sales_do d
                    WHERE LOWER(COALESCE(d.status,''))='wait_payment'
                      AND {$dueExpr}
                      AND GREATEST(COALESCE(NULLIF(d.grand_total,0),d.total_amount,0)-COALESCE(d.fin_paid_amount,0),0) > 0";
        $stExAr = $pdo->prepare($sqlExAr);
        $stExAr->execute([$data['as_of']]);
        $financeExceptionCount += (int)$stExAr->fetchColumn();
    }
    if ($tableExists('purchases_invoice_ap') && $columnExistsLocal('purchases_invoice_ap', 'due_date')) {
        $sqlExAp = "SELECT COUNT(*) FROM purchases_invoice_ap ap
                    WHERE ap.deleted_at IS NULL
                      AND ap.status IN ('UNPAID','PARTIAL')
                      AND ap.due_date IS NOT NULL AND ap.due_date < ?";
        $stExAp = $pdo->prepare($sqlExAp);
        $stExAp->execute([$data['as_of']]);
        $financeExceptionCount += (int)$stExAp->fetchColumn();
    }
    if ($tableExists('fin_ap_opening') && $columnExistsLocal('fin_ap_opening', 'due_date')) {
        $sqlExOpening = "SELECT COUNT(*) FROM fin_ap_opening o
                         WHERE UPPER(COALESCE(o.status,'UNPAID')) IN ('UNPAID','PARTIAL')
                           AND o.due_date IS NOT NULL AND o.due_date < ?
                           AND COALESCE(o.outstanding_amount, GREATEST(COALESCE(o.original_amount,0)-COALESCE(o.paid_amount,0),0)) > 0";
        $stExOpening = $pdo->prepare($sqlExOpening);
        $stExOpening->execute([$data['as_of']]);
        $financeExceptionCount += (int)$stExOpening->fetchColumn();
    }
} catch (Throwable $e) {
    // Keep dashboard resilient; exception count remains the successfully calculated portion.
}

$viewSourceBase = '../../tools/view_source.php';
$rekapBaseForIcon = rtrim($GLOBALS['BASE_PROJECT'] ?? '', '/') . '/dashboards/finance/';
$dataUrlBase = ['sales' => $rekapBaseForIcon . 'sales_do_rekap.php', 'ap' => $rekapBaseForIcon . 'ap_rekap.php'];
$columnSourceMap = [
    'target' => ['table' => 'kpi_targets', 'purpose' => 'Target monthly per segment/office', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 155],
    'pencapaian' => ['table' => 'sales_do + sales_do_items + sales_do_returns', 'purpose' => 'Actual ledger BMHP: net item, internal excluded, commercial return applied, segment governed', 'file' => 'dashboards/finance/dashboard_detail.php', 'line' => 170, 'data_url' => 'sales'],
    'persentase' => ['table' => 'calculated', 'purpose' => 'Pencapaian / Target × 100', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 440],
    'penjualan' => ['table' => 'sales_do + sales_do_items + sales_do_returns', 'purpose' => 'Penjualan aktual MTD per office', 'file' => 'dashboards/finance/dashboard_detail.php', 'line' => 170, 'data_url' => 'sales'],
    'operasional' => ['table' => 'gl_journal_headers', 'purpose' => 'Biaya operasional dari GL', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 235],
    'beban_gaji' => ['table' => 'gl_journal_headers', 'purpose' => 'Beban gaji/pendapatan dari GL', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 235],
    'support' => ['table' => 'gl_journal_headers', 'purpose' => 'Support dari GL', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 235],
    'fee_management' => ['table' => 'gl_journal_headers', 'purpose' => 'Fee Management Hermina dari GL', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 235],
    'pph' => ['table' => 'gl_journal_headers', 'purpose' => 'PPh 25 / PPh Final dari GL', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 235],
    'corporate' => ['table' => 'kpi_corporate_rates', 'purpose' => 'Corporate rate × penjualan', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 318],
    'hutang' => ['table' => 'purchases_invoice_ap + fin_ap_opening', 'purpose' => 'Outstanding AP ERP + hutang lama/import', 'file' => 'dashboards/finance/dashboard_detail.php', 'line' => 436, 'data_url' => 'ap'],
    'piutang_baru' => ['table' => 'sales_do', 'purpose' => 'AR baru (DO bulan berjalan)', 'file' => 'dashboards/finance/dashboard_detail.php', 'line' => 422, 'data_url' => 'sales'],
    'piutang_lama' => ['table' => 'sales_do', 'purpose' => 'AR lama (DO sebelum bulan berjalan)', 'file' => 'dashboards/finance/dashboard_detail.php', 'line' => 422, 'data_url' => 'sales'],
    'total_piutang' => ['table' => 'sales_do', 'purpose' => 'Piutang Baru + Piutang Lama', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 113, 'data_url' => 'sales'],
    'stock_by_office' => ['table' => 'kpi_daily_snapshots', 'purpose' => 'Nilai stok', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 268],
    'jumlah' => ['table' => 'calculated', 'purpose' => 'Profit + WorkingCapital + Adjustment', 'file' => 'app/Dashboard/DashboardDetailService.php', 'line' => 540],
];
$cellSourceAttr = static function (string $key) use ($columnSourceMap): string {
    $m = $columnSourceMap[$key] ?? null;
    if (!$m) return '';
    return ' data-source-table="' . h($m['table']) . '" data-source-purpose="' . h($m['purpose']) . '"';
};
$cellSourceIcon = static function (string $key, ?string $drillUrl = null) use ($columnSourceMap, $viewSourceBase, $dataUrlBase): string {
    $m = $columnSourceMap[$key] ?? null;
    if (!$m) return '';
    $dataUrlKey = $m['data_url'] ?? '';
    $file = $m['file'] ?? '';
    $line = isset($m['line']) ? (int)$m['line'] : 0;
    if ($drillUrl !== null && $drillUrl !== '') {
        return '';
    }
    if ($dataUrlKey !== '' && isset($dataUrlBase[$dataUrlKey])) {
        $url = $dataUrlBase[$dataUrlKey];
        $title = 'Klik untuk validasi data real (buka halaman rekap)';
        return ' <a href="' . h($url) . '" class="src-info-icon" title="' . h($title) . '">ⓘ</a>';
    }
    if ($file !== '') {
        $url = $viewSourceBase . '?file=' . rawurlencode($file);
        if ($line > 0) $url .= '&line=' . $line;
        return ' <a href="' . h($url) . '" class="src-info-icon" target="_blank" rel="noopener" title="Klik untuk buka file sumber">ⓘ</a>';
    }
    return ' <span class="src-info-icon" data-source-table="' . h($m['table']) . '" data-source-purpose="' . h($m['purpose']) . '" role="button" tabindex="0" title="Lihat sumber data">ⓘ</span>';
};
$dataSources = [
    ['table' => 'sales_do', 'purpose' => 'Header DO, business date, status, office, customer', 'required' => true],
    ['table' => 'sales_do_items', 'purpose' => 'Net sales aktual dari subtotal item', 'required' => true],
    ['table' => 'sales_do_returns', 'purpose' => 'Commercial effect retur FINAL', 'required' => false],
    ['table' => 'sales_do_return_items', 'purpose' => 'Qty retur per item DO', 'required' => false],
    ['table' => 'master_customers', 'purpose' => 'Identitas, segment/type dan internal classifier customer', 'required' => true],
    ['table' => 'kpi_customer_segment_map', 'purpose' => 'Fallback segment legacy; master_customers.segment tetap canonical', 'required' => false],
    ['table' => 'kpi_targets', 'purpose' => 'Target monthly per segment/office', 'required' => true],
    ['table' => 'purchases_invoice_ap', 'purpose' => 'Outstanding AP', 'required' => true],
    ['table' => 'purchases_payment_ap', 'purpose' => 'Pembayaran AP terposting', 'required' => true],
    ['table' => 'fin_ap_opening', 'purpose' => 'Hutang lama/import yang digabung ke AP outstanding', 'required' => false],
    ['table' => 'fin_ap_opening_payments', 'purpose' => 'Pembayaran hutang lama/import', 'required' => false],
    ['table' => 'gl_journal_headers', 'purpose' => 'Biaya operasional/finance by office', 'required' => true],
    ['table' => 'gl_journal_lines', 'purpose' => 'Detail line GL untuk category map', 'required' => true],
    ['table' => 'kpi_gl_category_map', 'purpose' => 'Map akun GL -> category dashboard', 'required' => true],
    ['table' => 'kpi_daily_snapshots', 'purpose' => 'Snapshot stock value FINANCE_DETAIL (preferred)', 'required' => false],
    ['table' => 'wqs_stock_by_office', 'purpose' => 'Fallback stok per office', 'required' => false],
    ['table' => 'wqs_stock', 'purpose' => 'Legacy fallback stok global', 'required' => false],
    ['table' => 'master_products', 'purpose' => 'Master produk untuk relasi stok', 'required' => false],
    ['table' => 'master_pricelist', 'purpose' => 'Harga beli/jual per SKU untuk valuasi stok bila tersedia', 'required' => false],
    ['table' => 'kpi_corporate_rates', 'purpose' => 'Rate corporate per blok', 'required' => false],
    ['table' => 'kpi_adjustments', 'purpose' => 'Adjustment manual per office', 'required' => false],
];
foreach ($dataSources as &$ds) {
    $ds['exists'] = $tableExists((string)$ds['table']);
}
unset($ds);
$sourceWarnCount = 0;
foreach ($dataSources as $ds) {
    if (!empty($ds['required']) && empty($ds['exists'])) {
        $sourceWarnCount++;
    }
}
// Stock chain dianggap READY bila snapshot tersedia, atau ada fallback stok + master produk.
$sourceExists = [];
foreach ($dataSources as $ds) $sourceExists[(string)$ds['table']] = !empty($ds['exists']);
$stockSourceReady = !empty($sourceExists['kpi_daily_snapshots'])
                 || (!empty($sourceExists['wqs_stock_by_office']) && !empty($sourceExists['master_products']))
                 || (!empty($sourceExists['wqs_stock']) && !empty($sourceExists['master_products']));
if (!$stockSourceReady) $sourceWarnCount++;

$integrityChecks = [];
$pushCheck = static function (string $name, float $left, float $right, float $tol = 0.01) use (&$integrityChecks): void {
    $delta = abs($left - $right);
    $integrityChecks[] = [
        'name' => $name,
        'left' => $left,
        'right' => $right,
        'delta' => $delta,
        'ok' => $delta <= $tol,
    ];
};

// Check-1: All Cabang total row == sum detail rows.
$allCabang = $data['section1']['all_cabang'] ?? [];
if (count($allCabang) > 1) {
    $sumDet = 0.0;
    for ($i = 0; $i < count($allCabang) - 1; $i++) {
        $sumDet += (float)($allCabang[$i]['pencapaian'] ?? 0);
    }
    $totalRow = (float)($allCabang[count($allCabang) - 1]['pencapaian'] ?? 0);
    $pushCheck('Section1 ALL CABANG total vs detail', $totalRow, $sumDet);
}

// Check-1b: hasil klasifikasi segment actual harus sama persis dengan total actual ledger.
$segmentActualTotal=0.0;
foreach(($ddActual['by_office_segment'] ?? []) as $segOffice){
    $segmentActualTotal += (float)($segOffice['HERMINA'] ?? 0);
    $segmentActualTotal += (float)($segOffice['NON_HERMINA'] ?? 0);
}
$pushCheck('Actual ledger total vs Hermina+Non Hermina', (float)($ddActual['total'] ?? 0), $segmentActualTotal);

// Check-1c: Ringkasan yang dilihat user wajib sama dengan actual ledger canonical.
$ringSummaryTotal=0.0;
foreach(($data['section1']['ringkasan'] ?? []) as $rr){
    if(!empty($rr['is_total'])){$ringSummaryTotal=(float)($rr['pencapaian']??0);break;}
}
$pushCheck('Ringkasan total vs actual ledger', (float)($ddActual['total'] ?? 0), $ringSummaryTotal);

// Check-1d: mapping legacy yang bertentangan dengan master tidak boleh diam-diam lolos.
// Master tetap menang, tetapi STRICT MODE memberi WARN agar mapping lama dibersihkan.
$segmentConflictCount=(float)($ddActual['diagnostic']['segment_conflict'] ?? 0);
$pushCheck('Legacy segment map conflict vs master', 0.0, $segmentConflictCount, 0.0);

// Check-1e: yang benar-benar fatal hanya DO yang office pencapaiannya tidak dapat ditentukan.
// Perbedaan office operasional/prefix terhadap office pencapaian adalah kondisi bisnis yang sah
// dan ditampilkan pada diagnostic, bukan dianggap integrity failure.
$officeUnresolvedCount=(float)($ddActual['diagnostic']['office_unresolved'] ?? 0);
$pushCheck('Sales DO office pencapaian unresolved', 0.0, $officeUnresolvedCount, 0.0);

// Check-2: Main block total penjualan == sum main rows.
$mainRows = $data['section2']['main']['rows'] ?? [];
$mainTotalPenjualan = (float)($data['section2']['main']['total']['penjualan'] ?? 0);
$sumMainRowsPenjualan = 0.0;
foreach ($mainRows as $r) {
    $sumMainRowsPenjualan += (float)($r['penjualan'] ?? 0);
}
$pushCheck('Section2 MAIN total penjualan vs rows', $mainTotalPenjualan, $sumMainRowsPenjualan);

// Check-3: Daily cumulative last value == sum daily.
$daily = $data['daily_series']['daily_total'] ?? [];
$cum = $data['daily_series']['cumulative_total'] ?? [];
if (count($daily) > 0 && count($cum) > 0) {
    $sumDaily = 0.0;
    foreach ($daily as $v) $sumDaily += (float)$v;
    $lastCum = (float)$cum[count($cum) - 1];
    $pushCheck('Daily cumulative tail vs sum daily', $lastCum, $sumDaily);
}

// Check-4: Target tab memakai ACTUAL LEDGER. Finance Detail dapat memakai basis finance-recognized.
// Perbedaan basis tidak dianggap integrity error pada Target vs Pencapaian.
$financeCanonicalSales = (float)($data['section2']['main']['total']['penjualan'] ?? 0)
                       + (float)($data['section2']['accunit']['total']['penjualan'] ?? 0)
                       + (float)($data['section2']['tangerang']['total']['penjualan'] ?? 0);

// Check-5: canonical total == daily cumulative tail.
if (count($cum) > 0) {
    $pushCheck('Cross-tab canonical sales: Target vs Daily', $allOfficePencapaian, (float)$cum[count($cum)-1]);
}

// Check-6/7: transaksi finance yang belum punya mapping office harus terlihat sebagai WARN, bukan hilang diam-diam.
$unmappedGl = 0.0;
foreach (($data['finance']['expense_by_office']['ALL'] ?? []) as $v) $unmappedGl += abs((float)$v);
if ($unmappedGl > 0) {
    $pushCheck('GL belum teralokasi ke office', 0.0, $unmappedGl);
}
$unmappedAp = abs((float)($data['finance']['ap_outstanding']['ALL'] ?? 0));
if ($unmappedAp > 0) {
    $pushCheck('AP belum teralokasi ke office', 0.0, $unmappedAp);
}

$integrityPass = 0;
foreach ($integrityChecks as $c) {
    if (!empty($c['ok'])) $integrityPass++;
}
$integrityFail = count($integrityChecks) - $integrityPass;

$cmpPack = $data['section1']['comparisons'] ?? [];
$anomalyChecks = [];
$cmpMap = [
    'vs_3_bulan' => 'VS 3 Bulan',
    'vs_tahun' => 'VS Tahun Lalu',
    'vs_tertinggi' => 'VS Pencapaian Tertinggi',
    'vs_hari_kerja_sama' => 'VS Hari Kerja Sama',
];
foreach ($cmpMap as $k => $label) {
    $available = !array_key_exists('available', (array)($cmpPack[$k] ?? [])) || !empty($cmpPack[$k]['available']);
    $deltaRaw = $cmpPack[$k]['delta_pct'] ?? null;
    $deltaPct = ($available && $deltaRaw !== null) ? (float)$deltaRaw : null;
    $absDelta = $deltaPct === null ? null : abs($deltaPct);
    $anomalyChecks[] = [
        'label' => $label,
        'delta_pct' => $deltaPct,
        'abs_delta' => $absDelta,
        'available' => $available && $deltaPct !== null,
        'warn' => $available && $deltaPct !== null && $absDelta > $anomalyThreshold,
    ];
}
$anomalyWarnCount = 0;
foreach ($anomalyChecks as $a) {
    if (!empty($a['warn'])) $anomalyWarnCount++;
}

$approvalScopeKey = sprintf(
    '%04d-%02d-%s-th%.1f',
    $yearNo,
    $monthNo,
    (string)$data['as_of'],
    $anomalyThreshold
);
$approvals = $_SESSION['dashboard_detail_approval'] ?? [];
$approvalData = is_array($approvals[$approvalScopeKey] ?? null) ? $approvals[$approvalScopeKey] : null;
$approvalGranted = is_array($approvalData) && !empty($approvalData['approved']) && !empty($approvalData['reason']);
$approvalFlash = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'approve_anomaly') {
        $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
        $allowed = in_array($level, ['MANAGER', 'ADMIN', 'SUPERADMIN', 'SYS'], true);
        $reason = trim((string)($_POST['approval_reason'] ?? ''));
        if (!$allowed) {
            $approvalFlash = 'Hanya MANAGER/ADMIN/SUPERADMIN/SYS yang boleh approve anomaly.';
        } elseif (mb_strlen($reason) < 10) {
            $approvalFlash = 'Alasan approval minimal 10 karakter.';
        } else {
            if (!isset($_SESSION['dashboard_detail_approval']) || !is_array($_SESSION['dashboard_detail_approval'])) {
                $_SESSION['dashboard_detail_approval'] = [];
            }
            $_SESSION['dashboard_detail_approval'][$approvalScopeKey] = [
                'approved' => 1,
                'reason' => mb_substr($reason, 0, 500),
                'approved_by' => (string)($_SESSION['username'] ?? ''),
                'approved_level' => $level,
                'approved_at' => date('c'),
            ];
            $approvalData = $_SESSION['dashboard_detail_approval'][$approvalScopeKey];
            $approvalGranted = true;
            try {
                erp_audit_ensure($pdo);
                erp_audit($pdo, 'DASHBOARD', 'FIN_DETAIL:' . $approvalScopeKey, 'ANOMALY_EXPORT_APPROVED', [
                    'strict_mode' => $strictMode,
                    'anomaly_threshold' => $anomalyThreshold,
                    'warn_count' => $anomalyWarnCount,
                    'reason' => mb_substr($reason, 0, 200),
                ]);
            } catch (Throwable $e) {
                // Keep UI resilient even if audit fails.
            }
        }
    }
}

$strictWarnCount = $integrityFail + $sourceWarnCount + $anomalyWarnCount;
$strictLockExport = ($strictMode === 1 && $strictWarnCount > 0 && !$approvalGranted);
$exportDeniedMsg = '';
if ($export !== '' && $strictLockExport) {
    $exportDeniedMsg = 'Export diblokir oleh STRICT MODE. Selesaikan WARN atau lakukan approval dengan alasan.';
    $export = '';
}
$scopeCtx = function_exists('ds_scope_ctx') ? ds_scope_ctx() : ['is_admin' => true, 'office_code' => ''];
$scopeOffice = strtoupper((string)($scopeCtx['office_code'] ?? ''));
if (!$scopeCtx['is_admin'] && $scopeOffice !== '') {
    $drillOffice = $scopeOffice;
}

$officeChartRows = [];
$officeCodesChart = ['BGR', 'BKS', 'SLO', 'BDG', 'SMG', 'JGY', 'KAL', 'TGR'];
if (!$scopeCtx['is_admin'] && $scopeOffice !== '') {
    $officeCodesChart = [$scopeOffice];
}
$chartMax = 0.0;
foreach ($officeCodesChart as $oc) {
    $label = (string)($data['office_meta'][$oc]['name'] ?? $oc);
    $target = (float)($data['targets']['office_total'][$oc] ?? 0);
    // Fallback dari tabel section1 jika targetAggregates belum membaca office_total.
    if ($target <= 0) {
        foreach (['hermina','non_hermina','accunit'] as $bucket) {
            foreach (($data['section1'][$bucket] ?? []) as $rr) {
                if (($rr['office_code'] ?? '') === $oc) {
                    $target += (float)($rr['target'] ?? 0);
                }
            }
        }
    }
    $ach = (float)($data['sales']['office_sales_mtd'][$oc] ?? 0);
    $chartMax = max($chartMax, $target, $ach);
    $officeChartRows[] = [
        'office_code' => $oc,
        'label' => $label,
        'target' => $target,
        'pencapaian' => $ach,
        'pct' => $target > 0 ? (($ach / $target) * 100.0) : 0.0,
    ];
}

$segmentRowsChart = [];
// Komposisi harus memakai bucket yang sama persis dengan RINGKASAN, bukan ALL CABANG
// (ALL CABANG hanya satu baris total sehingga sebelumnya donut selalu 100% satu warna).
foreach (($data['section1']['ringkasan'] ?? []) as $r) {
    if (!empty($r['is_total'])) continue;
    $segmentRowsChart[] = [
        'label' => (string)($r['label'] ?? ''),
        'value' => round((float)($r['pencapaian'] ?? 0),0),
    ];
}
$segmentTotalChart = 0.0;
foreach ($segmentRowsChart as $r) {
    $segmentTotalChart += (float)$r['value'];
}

$financeRowsChart = array_merge(
    $data['section2']['main']['rows'] ?? [],
    $data['section2']['tangerang']['rows'] ?? []
);
$financeTopJumlah = $financeRowsChart;
usort($financeTopJumlah, static function (array $a, array $b): int {
    return ((float)$b['jumlah']) <=> ((float)$a['jumlah']);
});
$financeTopJumlah = array_slice($financeTopJumlah, 0, 8);
$financeMaxJumlah = 0.0;
foreach ($financeTopJumlah as $r) {
    $financeMaxJumlah = max($financeMaxJumlah, (float)$r['jumlah']);
}

$dailySeries = $data['daily_series'] ?? ['labels' => [], 'daily_total' => [], 'cumulative_total' => []];
$dailyLabels = $dailySeries['labels'] ?? [];
$dailyValues = $dailySeries['daily_total'] ?? [];
$cumValues = $dailySeries['cumulative_total'] ?? [];
$dailyMax = 0.0;
foreach ($dailyValues as $v) {
    $dailyMax = max($dailyMax, (float)$v);
}
$cumMax = 0.0;
foreach ($cumValues as $v) {
    $cumMax = max($cumMax, (float)$v);
}

if ($export === 'target_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dashboard_target_vs_pencapaian_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Section', 'Tanggal', 'Office/Keterangan', 'Target', 'Pencapaian', 'Persentase']);
    $sections = [
        'NON HERMINA' => $data['section1']['non_hermina'],
        'HERMINA' => $data['section1']['hermina'],
        'UNIT ACC' => $data['section1']['accunit'],
    ];
    foreach ($sections as $name => $rows) {
        foreach ($rows as $r) {
            fputcsv($out, [$name, $r['tanggal'] ?? $data['as_of'], $r['office'] ?? ($r['label'] ?? ''), (float)($r['target'] ?? 0), (float)($r['pencapaian'] ?? 0), (float)($r['persentase'] ?? 0)]);
        }
    }
    foreach ($data['section1']['ringkasan'] as $r) {
        fputcsv($out, ['RINGKASAN', $data['as_of'], $r['label'] ?? '', (float)($r['target'] ?? 0), (float)($r['pencapaian'] ?? 0), (float)($r['persentase'] ?? 0)]);
    }
    foreach ($data['section1']['all_cabang'] as $r) {
        fputcsv($out, ['ALL CABANG', $r['tanggal'] ?? $data['as_of'], $r['keterangan'] ?? '', (float)($r['target'] ?? 0), (float)($r['pencapaian'] ?? 0), (float)($r['persentase'] ?? 0)]);
    }
    fclose($out);
    exit;
}

if ($export === 'finance_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dashboard_finance_detail_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Blok', 'No', 'Office', 'Penjualan', 'Operasional', 'Beban Gaji/Pendapatan', 'Support', 'Fee Management Hermina', 'PPh 25 / PPh Final', 'Corporate', 'Persentase', 'Hutang', 'Piutang Baru', 'Piutang Lama', 'Total Piutang', 'Stok By Office', 'Jumlah']);
    $blocks = [
        'MAIN' => array_merge($data['section2']['main']['rows'], $data['section2']['tangerang']['rows']),
        'ACCUNIT' => $data['section2']['accunit']['rows'],
    ];
    foreach ($blocks as $blockName => $rows) {
        foreach ($rows as $r) {
            fputcsv($out, [
                $blockName,
                $r['no'] ?? '',
                $r['office'] ?? '',
                (float)($r['penjualan'] ?? 0),
                (float)($r['operasional'] ?? 0),
                (float)($r['beban_gaji'] ?? 0),
                (float)($r['support'] ?? 0),
                (float)($r['fee_management'] ?? 0),
                (float)($r['pph'] ?? 0),
                (float)($r['corporate'] ?? 0),
                (float)($r['persentase'] ?? 0),
                (float)($r['hutang'] ?? 0),
                (float)($r['piutang_baru'] ?? 0),
                (float)($r['piutang_lama'] ?? 0),
                (float)($r['total_piutang'] ?? 0),
                (float)($r['stock_by_office'] ?? 0),
                (float)($r['jumlah'] ?? 0),
            ]);
        }
    }
    fclose($out);
    exit;
}

/**
 * Drilldown sources
 */
$drillRows = [];
if ($drilldown !== '' && $drillOffice !== '') {
    if ($drilldown === 'sales') {
        $sql = "SELECT do_code, do_date, customers_code, status, COALESCE(NULLIF(total_amount,0),grand_total,0) AS net_amount
                FROM sales_do
                WHERE UPPER(COALESCE(office_code,''))=?
                  AND do_date BETWEEN ? AND ?
                ORDER BY do_date DESC, id DESC";
        $st = $pdo->prepare($sql);
        $st->execute([$drillOffice, $data['month_start'], $data['as_of']]);
        $drillRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($drilldown === 'piutang_baru' || $drilldown === 'piutang_lama') {
        $cmp = $drilldown === 'piutang_baru' ? "d.do_date >= ?" : "d.do_date < ?";
        $sql = "SELECT d.do_code, d.do_date, d.customers_code, d.status,
                       GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0), 0) AS outstanding
                FROM sales_do d
                WHERE UPPER(COALESCE(d.office_code,''))=?
                  AND d.do_date <= ?
                  AND {$cmp}
                  AND GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0), 0) > 0
                ORDER BY d.do_date DESC, d.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute([$drillOffice, $data['as_of'], $data['month_start']]);
        $drillRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($drilldown === 'target' && $tableExists('kpi_targets')) {
        $sql = "SELECT CASE
                       WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(t.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')='NONHERMINA' THEN 'NON_HERMINA'
                       WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(t.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')='HERMINA' THEN 'HERMINA'
                       WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(t.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')='ACCUNIT' THEN 'ACCUNIT'
                       ELSE UPPER(COALESCE(t.segment,''))
                       END AS segment, UPPER(COALESCE(o.office_code,'ALL')) AS office_code, o.office_name, t.target_amount
                FROM kpi_targets t
                LEFT JOIN master_office o ON o.id = t.office_id
                WHERE t.month_no = ? AND t.year_no = ?
                  AND UPPER(COALESCE(o.office_code,'ALL')) = ?
                ORDER BY t.segment, o.office_code";
        $st = $pdo->prepare($sql);
        $st->execute([$monthNo, $yearNo, $drillOffice]);
        $drillRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($drilldown === 'hutang') {
        $sql = "SELECT ap.ap_code, ap.invoice_date, ap.invoice_number, ap.status,
                       (ap.total_amount - COALESCE(paid.paid_amount,0)) AS outstanding
                FROM purchases_invoice_ap ap
                LEFT JOIN (
                  SELECT ap_id, SUM(amount) AS paid_amount
                  FROM purchases_payment_ap
                  WHERE deleted_at IS NULL
                  GROUP BY ap_id
                ) paid ON paid.ap_id = ap.id
                WHERE UPPER(COALESCE(ap.office_code,''))=?
                  AND ap.deleted_at IS NULL
                  AND ap.invoice_date <= ?
                  AND ap.status IN ('UNPAID','PARTIAL')
                ORDER BY ap.invoice_date DESC, ap.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute([$drillOffice, $data['as_of']]);
        $drillRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

require_once __DIR__ . '/../../_shared/rmi_layout.php';

$monthName = strtoupper(date('F', strtotime(sprintf('%04d-%02d-01', $yearNo, $monthNo))));
$subtitle = "PERIODE {$monthName} {$yearNo} • AS OF DATE " . date('d-M-y', strtotime($data['as_of']));
$extraHead = <<<'HTML'
<style>
  .excel-surface{background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,#0f172a 100%);border:1px solid rgba(6,182,212,.25);border-radius:16px;color:#e2e8f0;position:relative;overflow:hidden;isolation:isolate;box-shadow:0 4px 24px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.03)}
  .excel-surface::before,.excel-surface::after{
    content:"";position:absolute;width:400px;height:400px;border-radius:50%;filter:blur(80px);opacity:.12;z-index:-1;pointer-events:none;animation:drift 18s ease-in-out infinite;
  }
  .excel-surface::before{background:linear-gradient(135deg,#06b6d4,#3b82f6);top:-180px;right:-100px}
  .excel-surface::after{background:linear-gradient(135deg,#8b5cf6,#06b6d4);bottom:-180px;left:-100px;animation-delay:3s}
  .excel-surface .card{background:rgba(15,23,42,.85)!important;border:1px solid rgba(6,182,212,.2)!important;backdrop-filter:blur(12px);border-radius:12px}
  .excel-surface .card .form-label{color:#94a3b8;font-weight:600}
  .excel-surface .card .form-control,.excel-surface .card .form-select{background:rgba(30,41,59,.8);color:#e2e8f0;border:1px solid rgba(6,182,212,.3)}
  .excel-surface .card .form-control:focus,.excel-surface .card .form-select:focus{border-color:#06b6d4;box-shadow:0 0 0 3px rgba(6,182,212,.2)}
  .excel-card{background:rgba(15,23,42,.7);border:1px solid rgba(6,182,212,.2);border-radius:12px;backdrop-filter:blur(10px);box-shadow:0 2px 12px rgba(0,0,0,.2);transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease}
  .excel-card:hover,.chart-card:hover,.vs-card:hover{transform:translateY(-3px);box-shadow:0 12px 32px rgba(0,0,0,.35),0 0 0 1px rgba(6,182,212,.15);border-color:rgba(6,182,212,.35)}
  .excel-title{background:linear-gradient(90deg,rgba(6,182,212,.25) 0%,rgba(59,130,246,.15) 100%);color:#06b6d4;font-weight:700;padding:12px 16px;border-bottom:1px solid rgba(6,182,212,.2);font-family:'Segoe UI',system-ui,sans-serif;letter-spacing:.5px;text-transform:uppercase;font-size:12px}
  .excel-title{position:relative;overflow:hidden}
  .excel-title::after{content:"";position:absolute;top:0;left:-120%;width:60%;height:100%;background:linear-gradient(110deg,transparent 0%,rgba(6,182,212,.2) 40%,transparent 80%);animation:titleShine 5s ease-in-out infinite}
  .excel-table{width:100%;border-collapse:collapse;font-size:13px;font-family:'Segoe UI',system-ui,sans-serif;color:#e2e8f0}
  .excel-table th,.excel-table td{border:1px solid rgba(6,182,212,.15);padding:8px 12px;line-height:1.35}
  .excel-table th{background:rgba(6,182,212,.12);text-align:center;white-space:nowrap;font-weight:700;color:#67e8f9}
  .excel-table td{background:rgba(30,41,59,.4);color:#cbd5e1}
  .excel-table td.num{text-align:right;font-variant-numeric:tabular-nums}
  .excel-table tr.total-row td{font-weight:700;background:rgba(6,182,212,.08);color:#67e8f9}
  .excel-table td.pct-high{background:rgba(34,197,94,.2);color:#4ade80;font-weight:700}
  .excel-table td.pct-low{background:rgba(239,68,68,.15);color:#f87171;font-weight:700}
  .subnote{font-size:12px;color:#94a3b8}
  .drill-link{color:#22d3ee;text-decoration:none;font-weight:600;transition:color .2s}
  .drill-link:hover{color:#67e8f9;text-decoration:underline}
  .tab-pane{padding-top:16px}
  .vs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
  .vs-card{background:rgba(15,23,42,.6);border:1px solid rgba(6,182,212,.2);border-radius:12px;padding:14px;color:#e2e8f0;backdrop-filter:blur(8px);transition:all .25s ease}
  .vs-card:hover{border-color:rgba(6,182,212,.4);box-shadow:0 0 20px rgba(6,182,212,.1)}
  .vs-title{font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:.4px;margin-bottom:4px}
  .vs-main{font-size:18px;font-weight:700;color:#67e8f9;line-height:1.2;font-variant-numeric:tabular-nums}
  .vs-sub{font-size:11px;color:#94a3b8;margin-top:2px}
  .vs-up{color:#4ade80}
  .vs-down{color:#f87171}
  .chart-card{background:rgba(15,23,42,.6);border:1px solid rgba(6,182,212,.2);border-radius:12px;padding:16px;color:#e2e8f0;backdrop-filter:blur(8px);transition:all .25s ease}
  .chart-title{font-size:13px;font-weight:700;color:#06b6d4;margin-bottom:12px;text-transform:uppercase;letter-spacing:.3px}
  .bar-row{display:grid;grid-template-columns:140px 1fr auto;gap:10px;align-items:center;margin:8px 0}
  .bar-wrap{position:relative;background:rgba(30,41,59,.8);border:1px solid rgba(6,182,212,.2);height:22px;border-radius:6px;overflow:hidden}
  .bar-target{position:absolute;left:0;top:0;bottom:0;background:linear-gradient(90deg,#eab308,#f59e0b);opacity:.9}
  .bar-ach{position:absolute;left:0;top:0;bottom:0;background:linear-gradient(90deg,#22c55e,#10b981);opacity:.95}
  .bar-target,.bar-ach{transform-origin:left center;transform:scaleX(0);animation:barGrow 1s cubic-bezier(.2,.8,.2,1) forwards}
  .bar-target{animation-delay:.05s}
  .bar-ach{animation-delay:.15s}
  .bar-value{font-size:12px;color:#94a3b8;min-width:80px;text-align:right;font-variant-numeric:tabular-nums}
  .donut{width:220px;height:220px;border-radius:50%;margin:12px auto;border:2px solid rgba(6,182,212,.2);box-shadow:0 0 30px rgba(6,182,212,.1)}
  .legend{font-size:12px}
  .legend div{display:flex;align-items:center;gap:8px;margin:6px 0;color:#cbd5e1}
  .sw{width:12px;height:12px;border-radius:4px;display:inline-block;box-shadow:0 0 8px currentColor}
  .svg-wrap{width:100%;overflow-x:auto;border-radius:8px;border:1px solid rgba(6,182,212,.15)}
  .svg-wrap svg{background:rgba(15,23,42,.5)!important}
  .line-caption{font-size:12px;color:#94a3b8}
  .mini-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
  .source-table{font-size:12px}
  .source-table td,.source-table th{padding:6px 10px;color:#cbd5e1}
  .source-table th{color:#67e8f9}
  .ok-badge{display:inline-block;padding:3px 10px;border-radius:6px;background:rgba(34,197,94,.2);color:#4ade80;font-weight:700;font-size:11px;border:1px solid rgba(34,197,94,.3)}
  .warn-badge{display:inline-block;padding:3px 10px;border-radius:6px;background:rgba(239,68,68,.2);color:#f87171;font-weight:700;font-size:11px;border:1px solid rgba(239,68,68,.3)}
  .strict-banner{background:rgba(127,29,29,.4);color:#fecaca;border:1px solid rgba(248,113,113,.3);padding:12px 16px;border-radius:10px;font-size:13px;backdrop-filter:blur(8px)}
  .info-banner{background:rgba(6,182,212,.15);color:#a5f3fc;border:1px solid rgba(6,182,212,.3);padding:10px 16px;border-radius:10px;font-size:12px}
  .btn-export-disabled{pointer-events:none;opacity:.5;filter:grayscale(.3)}
  .nav-tabs{border-bottom:2px solid rgba(6,182,212,.2);gap:4px;padding-bottom:0}
  .nav-tabs .nav-link{color:#94a3b8;font-weight:600;border:none;border-radius:8px 8px 0 0;padding:10px 18px;margin-bottom:-2px;transition:all .25s ease;position:relative}
  .nav-tabs .nav-link:hover{color:#e2e8f0;background:rgba(6,182,212,.1)}
  .nav-tabs .nav-link.active{color:#06b6d4;background:rgba(6,182,212,.15);border-bottom:2px solid #06b6d4}
  .nav-tabs .nav-link::after{content:"";position:absolute;left:50%;bottom:0;width:0;height:2px;background:#06b6d4;transition:width .25s ease,left .25s ease}
  .nav-tabs .nav-link.active::after{width:100%;left:0}
  .kpi-count{font-variant-numeric:tabular-nums}
  .reveal{opacity:0;transform:translateY(12px);transition:opacity .5s ease,transform .5s ease}
  .reveal.in{opacity:1;transform:translateY(0)}
  body.theme-contrast .excel-surface{background:#0a0a0a;border-color:#06b6d4}
  body.theme-contrast .excel-surface .card,body.theme-contrast .excel-card,body.theme-contrast .chart-card,body.theme-contrast .vs-card{background:#111!important;border-color:#06b6d4!important}
  body.theme-contrast .excel-title{background:rgba(6,182,212,.2);color:#67e8f9}
  body.theme-contrast .excel-table th,body.theme-contrast .excel-table td{background:#0a0a0a;color:#e2e8f0;border-color:rgba(6,182,212,.3)}
  body.theme-contrast .excel-table tr.total-row td{background:#1a1a1a}
  body.theme-contrast .excel-table td.pct-high{background:rgba(34,197,94,.2);color:#4ade80}
  body.theme-contrast .excel-table td.pct-low{background:rgba(239,68,68,.2);color:#f87171}
  body.theme-contrast .subnote,body.theme-contrast .line-caption,body.theme-contrast .vs-sub{color:#94a3b8}
  body.theme-contrast .drill-link{color:#22d3ee}
  body.theme-contrast .nav-tabs .nav-link{color:#94a3b8}
  body.theme-contrast .nav-tabs .nav-link.active{color:#06b6d4;background:rgba(6,182,212,.15)}
  body.motion-classic .excel-surface::before,body.motion-classic .excel-surface::after,body.motion-classic .excel-title::after,body.motion-classic .bar-target,body.motion-classic .bar-ach{animation:none!important;transform:none!important}
  body.motion-classic .excel-card,body.motion-classic .chart-card,body.motion-classic .vs-card,body.motion-classic .nav-tabs .nav-link{transition:none!important}
  body.motion-classic .excel-card:hover,body.motion-classic .chart-card:hover,body.motion-classic .vs-card:hover{transform:none!important}
  body.motion-classic .reveal{opacity:1!important;transform:none!important}
  @keyframes drift{0%,100%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(20px,-15px,0) scale(1.05)}}
  @keyframes titleShine{0%,70%{left:-120%}100%{left:140%}}
  @keyframes barGrow{from{transform:scaleX(0)}to{transform:scaleX(1)}}
  @media (prefers-reduced-motion:reduce){.excel-surface::before,.excel-surface::after,.excel-title::after,.bar-target,.bar-ach{animation:none!important}.reveal{opacity:1;transform:none;transition:none}}
  body.finance-dashboard{background:#0f172a!important;color:#e2e8f0}
  body.finance-dashboard .container-fluid{background:transparent}

  /* === KIOSK / MONITORING MODE === */
  body.kiosk-mode .rmi-topbar,
  body.kiosk-mode .rmi-sidebar,
  body.kiosk-mode .rmi-breadcrumb,
  body.kiosk-mode nav,
  body.kiosk-mode header { display:none!important }
  body.kiosk-mode .container-fluid { padding:0!important }
  body.kiosk-mode .excel-surface { border-radius:0!important; margin:0!important }

  /* Live clock */
  #kiosk-clock {
    position:fixed; top:12px; right:16px; z-index:9999;
    background:rgba(6,182,212,.15); border:1px solid rgba(6,182,212,.35);
    color:#67e8f9; font-size:22px; font-weight:700; font-variant-numeric:tabular-nums;
    padding:6px 18px; border-radius:12px; backdrop-filter:blur(8px);
    letter-spacing:1px; font-family:'Segoe UI',monospace;
    box-shadow:0 0 20px rgba(6,182,212,.2);
  }

  /* Kiosk summary bar */
  #kiosk-summary {
    display:flex; gap:16px; flex-wrap:wrap; align-items:center;
    background:rgba(6,182,212,.08); border-bottom:1px solid rgba(6,182,212,.2);
    padding:10px 20px; font-size:14px;
  }
  #kiosk-summary .ks-item { display:flex; flex-direction:column; gap:2px }
  #kiosk-summary .ks-label { font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:.4px }
  #kiosk-summary .ks-val { font-size:18px; font-weight:700; color:#67e8f9; font-variant-numeric:tabular-nums }
  #kiosk-summary .ks-pct-ok { color:#4ade80 }
  #kiosk-summary .ks-pct-low { color:#f87171 }
  #kiosk-summary .ks-sep { width:1px; background:rgba(6,182,212,.2); align-self:stretch }

  /* Chart cards bigger in kiosk */
  body.kiosk-mode .chart-card { padding:20px 24px }
  body.kiosk-mode .chart-title { font-size:16px; margin-bottom:16px }
  body.kiosk-mode .bar-row { margin:12px 0 }
  body.kiosk-mode .bar-wrap { height:32px; border-radius:8px }
  body.kiosk-mode .bar-value { font-size:15px; min-width:100px }
  body.kiosk-mode .bar-row > div:first-child { font-size:14px; font-weight:600 }
  body.kiosk-mode .vs-main { font-size:24px }
  body.kiosk-mode .vs-title { font-size:12px }
  body.kiosk-mode .donut { width:280px; height:280px }
  body.kiosk-mode .legend { font-size:14px }
  body.kiosk-mode .subnote { font-size:13px }
  .src-info-icon{display:inline-block;margin-left:6px;padding:2px 4px;cursor:pointer;opacity:.9;font-size:13px;font-weight:700;color:#06b6d4;transition:opacity .2s;text-decoration:none;vertical-align:middle;position:relative;z-index:5}
  .src-info-icon:hover{opacity:1;color:#67e8f9}
  a.src-info-icon:hover{text-decoration:none}
  .src-info-icon:focus{outline:1px solid #06b6d4;border-radius:2px}
  .source-popover{font-size:12px;color:#e2e8f0;min-width:220px}
  .source-popover code{background:rgba(6,182,212,.2);padding:2px 6px;border-radius:4px;color:#67e8f9}
  .popover.source-popover-popover .popover-body{background:rgba(15,23,42,.95);border:1px solid rgba(6,182,212,.3);color:#e2e8f0}
  .popover.source-popover-popover .popover-header{background:rgba(6,182,212,.15);color:#06b6d4;border-bottom-color:rgba(6,182,212,.2)}
</style>
HTML;

rmi_header('Dashboard Detail ERP', 'dashboard', [
    'subtitle' => $subtitle,
    'breadcrumbs' => [
        ['label' => 'Dashboard Center', 'url' => '../index.php'],
        ['label' => 'Finance', 'url' => './ar_ap_cash_dashboard.php'],
        ['label' => 'Dashboard Detail'],
    ],
    'extra_head' => $extraHead,
    'actions' => [
        ['label' => rmi_icon('books').' Panduan Finance', 'url' => rtrim((string) ($GLOBALS['BASE_PROJECT'] ?? ''), '/') . '/dashboards/finance/panduan.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.body.classList.add('motion-<?= h($motionMode) ?>', 'finance-dashboard');
  <?php if ($kioskMode): ?>
  document.body.classList.add('kiosk-mode');
  <?php endif; ?>
  var drill = document.getElementById('drilldown-section');
  if (drill) {
    drill.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
});
</script>

<?php if ($kioskMode): ?>
<!-- Live Clock -->
<div id="kiosk-clock">--:--:--</div>
<script>
(function(){
  function pad(n){return n<10?'0'+n:n}
  function tick(){
    var d=new Date();
    document.getElementById('kiosk-clock').textContent=
      pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
  }
  tick(); setInterval(tick,1000);
})();
</script>

<!-- Kiosk Summary Bar -->
<?php
$cmp = $data['section1']['comparisons'] ?? [];
$totalTarget = $allOfficeTarget;
$totalPencapaian = $allOfficePencapaian;
$totalPct = $allOfficePct;
$pctClass = $totalPct >= 100 ? 'ks-pct-ok' : 'ks-pct-low';
?>
<div id="kiosk-summary">
  <div class="ks-item">
    <span class="ks-label">Periode</span>
    <span class="ks-val" style="font-size:14px"><?= h($monthName) ?> <?= h((string)$yearNo) ?></span>
  </div>
  <div class="ks-sep"></div>
  <div class="ks-item">
    <span class="ks-label">Total Target</span>
    <span class="ks-val"><?= h(f_money($totalTarget)) ?></span>
  </div>
  <div class="ks-sep"></div>
  <div class="ks-item">
    <span class="ks-label">Total Pencapaian</span>
    <span class="ks-val"><?= h(f_money($totalPencapaian)) ?></span>
  </div>
  <div class="ks-sep"></div>
  <div class="ks-item">
    <span class="ks-label">Persentase</span>
    <span class="ks-val <?= $pctClass ?>"><?= h(f_pct($totalPct)) ?></span>
  </div>
  <div class="ks-sep"></div>
  <div class="ks-item">
    <span class="ks-label">As Of</span>
    <span class="ks-val" style="font-size:14px"><?= h(date('d-M-Y', strtotime($data['as_of']))) ?></span>
  </div>
  <div style="margin-left:auto">
    <a href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=chart&refresh=60&motion=live&kiosk=0"
       style="background:rgba(239,68,68,.2);color:#f87171;border:1px solid rgba(239,68,68,.3);padding:6px 14px;border-radius:8px;font-size:12px;text-decoration:none;font-weight:600">
      <?=rmi_icon('x')?> Keluar Monitoring
    </a>
  </div>
</div>
<?php endif; ?>
<div class="container-fluid">
  <div class="excel-surface p-3 p-md-4 mb-3">
  <?php
    // Manager Controlling Staff sengaja tidak ditampilkan pada Dashboard Detail ERP.
    // Hanya presentation block yang dihapus; perhitungan/data/workflow Finance lain tetap utuh.
  ?>
  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <input type="hidden" name="refresh" value="<?= (int)$refreshSec ?>">
        <input type="hidden" name="motion" value="<?= h($motionMode) ?>">
        <input type="hidden" name="strict" value="<?= (int)$strictMode ?>">
        <input type="hidden" name="anomaly_threshold" value="<?= h(number_format($anomalyThreshold, 1, '.', '')) ?>">
        <div class="col-md-2">
          <label class="form-label">Month</label>
          <select class="form-select form-select-sm" name="month">
            <?php for($m=1;$m<=12;$m++): ?>
              <option value="<?= $m ?>" <?= $m===$monthNo?'selected':'' ?>><?= str_pad((string)$m,2,'0',STR_PAD_LEFT) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Year</label>
          <input type="number" class="form-control form-control-sm" name="year" value="<?= (int)$yearNo ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">As-Of Date</label>
          <input type="date" class="form-control form-control-sm" name="as_of" value="<?= h($data['as_of']) ?>">
        </div>
        <div class="col-12 mt-2" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
          <!-- Grup 1: Apply -->
          <button class="btn btn-sm btn-primary" type="submit" style="min-width:64px">Apply</button>

          <div style="width:1px;background:rgba(255,255,255,.15);height:28px;margin:0 2px"></div>

          <!-- Grup 2: Motion -->
          <div class="btn-group btn-group-sm" role="group" title="Motion Mode">
            <a class="btn <?= $motionMode==='live' ? 'btn-warning' : 'btn-outline-warning' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=<?= h($tab) ?>&refresh=<?= (int)$refreshSec ?>&motion=live">Live</a>
            <a class="btn <?= $motionMode==='classic' ? 'btn-warning' : 'btn-outline-warning' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=<?= h($tab) ?>&refresh=<?= (int)$refreshSec ?>&motion=classic">Classic</a>
          </div>

          <div style="width:1px;background:rgba(255,255,255,.15);height:28px;margin:0 2px"></div>

          <!-- Grup 3: Strict -->
          <div class="btn-group btn-group-sm" role="group" title="Strict Mode">
            <a class="btn <?= $strictMode===1 ? 'btn-danger' : 'btn-outline-danger' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=<?= h($tab) ?>&refresh=<?= (int)$refreshSec ?>&motion=<?= h($motionMode) ?>&strict=1">Strict ON</a>
            <a class="btn <?= $strictMode===0 ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=<?= h($tab) ?>&refresh=<?= (int)$refreshSec ?>&motion=<?= h($motionMode) ?>&strict=0">OFF</a>
          </div>

          <div style="width:1px;background:rgba(255,255,255,.15);height:28px;margin:0 2px"></div>

          <!-- Grup 4: Export -->
          <div class="btn-group btn-group-sm" role="group">
            <a class="btn btn-outline-success <?= $strictLockExport ? 'btn-export-disabled' : '' ?>"
               href="<?= $strictLockExport ? '#' : ('?month='.(int)$monthNo.'&year='.(int)$yearNo.'&as_of='.h($data['as_of']).'&tab=target&export=target_csv&motion='.h($motionMode).'&strict='.(int)$strictMode) ?>"
               title="Export Target vs Pencapaian"><?=rmi_icon('inbox')?> Target</a>
            <a class="btn btn-outline-success <?= $strictLockExport ? 'btn-export-disabled' : '' ?>"
               href="<?= $strictLockExport ? '#' : ('?month='.(int)$monthNo.'&year='.(int)$yearNo.'&as_of='.h($data['as_of']).'&tab=finance&export=finance_csv&motion='.h($motionMode).'&strict='.(int)$strictMode) ?>"
               title="Export Finance Detail"><?=rmi_icon('inbox')?> Finance</a>
          </div>

          <div style="width:1px;background:rgba(255,255,255,.15);height:28px;margin:0 2px"></div>

          <!-- Grup 5: Monitoring -->
          <a class="btn btn-sm btn-warning" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=exec&refresh=60&motion=live&kiosk=1" title="Buka Executive Summary + Monitoring Mode">
            <?=rmi_icon('chart')?> Monitor
          </a>
        </div>
      </form>
      <?php if ($exportDeniedMsg !== ''): ?>
      <div class="strict-banner mt-2"><?= h($exportDeniedMsg) ?></div>
      <?php endif; ?>
      <?php if ($strictMode === 1 && $strictWarnCount > 0): ?>
      <div class="strict-banner mt-2">
        STRICT MODE AKTIF: ditemukan <?= (int)$strictWarnCount ?> WARN (integrity + source).
        Export dikunci sampai status kembali sinkron (tanpa WARN).
      </div>
      <?php endif; ?>
      <?php if ($approvalGranted): ?>
      <div class="info-banner mt-2">
        Approval aktif oleh <strong><?= h((string)($approvalData['approved_by'] ?? '-')) ?></strong> (<?= h((string)($approvalData['approved_level'] ?? '-')) ?>)
        pada <?= h((string)($approvalData['approved_at'] ?? '-')) ?>.
        Reason: <?= h((string)($approvalData['reason'] ?? '-')) ?>
      </div>
      <?php endif; ?>
      <?php if ($approvalFlash !== ''): ?>
      <div class="strict-banner mt-2"><?= h($approvalFlash) ?></div>
      <?php endif; ?>
      <div class="subnote mt-2">
        Catatan: dashboard membaca snapshot `kpi_daily_snapshots` bila tersedia; jika tidak, fallback ke query live.
      </div>
    </div>
  </div>

  <?php if ($tab !== 'exec'): ?>
  <div class="excel-card mb-3">
    <div class="excel-title">DATA SOURCE & INTEGRITY CHECK</div>
    <div class="p-2">
      <div class="mini-grid mb-2">
        <div class="subnote">As-Of: <strong><?= h($data['as_of']) ?></strong></div>
        <div class="subnote">Integrity: <strong><?= (int)$integrityPass ?></strong> OK / <strong><?= (int)$integrityFail ?></strong> WARN</div>
          <div class="subnote">Source Required: <strong><?= (int)(count(array_filter($dataSources, static fn($x) => !empty($x['required']) && !empty($x['exists']))) + ($stockSourceReady ? 1 : 0)) ?></strong> READY / <strong><?= (int)$sourceWarnCount ?></strong> WARN</div>
          <div class="subnote">Anomaly: <strong><?= (int)(count($anomalyChecks) - $anomalyWarnCount) ?></strong> OK / <strong><?= (int)$anomalyWarnCount ?></strong> WARN (threshold <?= h(number_format($anomalyThreshold,1,',','.')) ?>%)</div>
        <div class="subnote">Motion Mode: <strong><?= h(strtoupper($motionMode)) ?></strong></div>
          <div class="subnote">Strict Mode: <strong><?= $strictMode === 1 ? 'ON' : 'OFF' ?></strong></div>
      </div>
      <div class="table-responsive mb-2">
        <table class="excel-table source-table">
          <thead><tr><th>Anomaly Check</th><th>Delta %</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($anomalyChecks as $a): ?>
            <tr>
              <td><?= h($a['label']) ?></td>
              <td class="num"><?= !empty($a['available']) ? h(f_delta((float)$a['delta_pct'])) : 'N/A' ?></td>
              <td><?= empty($a['available']) ? '<span class="ok-badge" style="background:rgba(100,116,139,.18);color:#94a3b8;border-color:rgba(100,116,139,.3)">N/A</span>' : (!empty($a['warn']) ? '<span class="warn-badge">WARN</span>' : '<span class="ok-badge">OK</span>') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <form method="get" class="row g-2 align-items-end mb-2">
        <input type="hidden" name="month" value="<?= (int)$monthNo ?>">
        <input type="hidden" name="year" value="<?= (int)$yearNo ?>">
        <input type="hidden" name="as_of" value="<?= h($data['as_of']) ?>">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <input type="hidden" name="refresh" value="<?= (int)$refreshSec ?>">
        <input type="hidden" name="motion" value="<?= h($motionMode) ?>">
        <input type="hidden" name="strict" value="<?= (int)$strictMode ?>">
        <div class="col-md-3">
          <label class="form-label">Anomaly threshold (%)</label>
          <input class="form-control form-control-sm" type="number" min="5" max="200" step="0.1" name="anomaly_threshold" value="<?= h(number_format($anomalyThreshold, 1, '.', '')) ?>">
        </div>
        <div class="col-md-2">
          <button class="btn btn-sm btn-outline-primary" type="submit">Apply Threshold</button>
        </div>
      </form>
      <?php if ($strictMode === 1 && $anomalyWarnCount > 0 && !$approvalGranted): ?>
      <form method="post" class="row g-2 align-items-end mb-2">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="approve_anomaly">
        <div class="col-md-8">
          <label class="form-label">Approval reason (required, min 10 chars)</label>
          <input class="form-control form-control-sm" type="text" name="approval_reason" maxlength="500" required>
        </div>
        <div class="col-md-4">
          <button class="btn btn-sm btn-danger" type="submit">Approve Export Despite WARN</button>
        </div>
      </form>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="excel-table source-table mb-2">
          <thead><tr><th>Check</th><th>Status</th><th class="text-end">Delta</th></tr></thead>
          <tbody>
          <?php foreach ($integrityChecks as $c): ?>
            <tr>
              <td><?= h($c['name']) ?></td>
              <td><?= !empty($c['ok']) ? '<span class="ok-badge">OK</span>' : '<span class="warn-badge">WARN</span>' ?></td>
              <td class="num"><?= h(f_money($c['delta'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="table-responsive">
        <table class="excel-table source-table">
          <thead><tr><th>Source Table</th><th>Purpose</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($dataSources as $s): ?>
            <tr>
              <td><code><?= h($s['table']) ?></code></td>
              <td><?= h($s['purpose']) ?></td>
              <td><?= !empty($s['exists']) ? '<span class="ok-badge">READY</span>' : (!empty($s['required']) ? '<span class="warn-badge">MISSING</span>' : '<span class="ok-badge" style="background:rgba(100,116,139,.18);color:#94a3b8;border-color:rgba(100,116,139,.3)">OPTIONAL/FALLBACK</span>') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; // end: hide data source on exec tab ?>

  <ul class="nav nav-tabs" role="tablist">
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= $tab === 'target' ? 'active' : '' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=target&refresh=<?= (int)$refreshSec ?>&motion=<?= h($motionMode) ?>&strict=<?= (int)$strictMode ?>&anomaly_threshold=<?= h(number_format($anomalyThreshold,1,'.','')) ?>">TARGET vs PENCAPAIAN</a>
    </li>
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= $tab === 'finance' ? 'active' : '' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=finance&refresh=<?= (int)$refreshSec ?>&motion=<?= h($motionMode) ?>&strict=<?= (int)$strictMode ?>&anomaly_threshold=<?= h(number_format($anomalyThreshold,1,'.','')) ?>">FINANCE DETAIL</a>
    </li>
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= $tab === 'chart' ? 'active' : '' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=chart&refresh=<?= (int)$refreshSec ?>&motion=<?= h($motionMode) ?>&strict=<?= (int)$strictMode ?>&anomaly_threshold=<?= h(number_format($anomalyThreshold,1,'.','')) ?>">CHART VIEW</a>
    </li>
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= $tab === 'exec' ? 'active' : '' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=exec&refresh=<?= (int)$refreshSec ?>&motion=<?= h($motionMode) ?>&strict=<?= (int)$strictMode ?>&anomaly_threshold=<?= h(number_format($anomalyThreshold,1,'.','')) ?>" style="color:#fbbf24">rmi_icon('target') EXECUTIVE SUMMARY</a>
    </li>
  </ul>

  <?php if ($drilldown !== '' && $drillOffice !== ''): ?>
    <div id="drilldown-section" class="excel-card mt-3 mb-3" style="border:2px solid #06b6d4;box-shadow:0 0 20px rgba(6,182,212,.3)">
      <div class="excel-title">DRILLDOWN: <?= h(strtoupper($drilldown)) ?> - OFFICE <?= h($drillOffice) ?></div>
      <div class="table-responsive">
        <table class="excel-table">
          <thead>
            <tr>
              <?php if ($drilldown === 'target'): ?>
                <th>Segment</th><th>Office</th><th>Target Amount</th>
              <?php elseif ($drilldown === 'sales' || $drilldown === 'piutang_baru' || $drilldown === 'piutang_lama'): ?>
                <th>Kode</th><th>Tanggal</th><th>Customer</th><th>Status</th><th>Amount</th>
              <?php else: ?>
                <th>Kode AP</th><th>Tanggal</th><th>Invoice</th><th>Status</th><th>Outstanding</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php if (count($drillRows) === 0): ?>
            <tr><td colspan="<?= $drilldown === 'target' ? 3 : 5 ?>">Tidak ada data.</td></tr>
          <?php else: ?>
            <?php foreach ($drillRows as $r): ?>
              <tr>
                <?php if ($drilldown === 'target'): ?>
                  <td><?= h($r['segment'] ?? '') ?></td><td><?= h($r['office_name'] ?? $r['office_code'] ?? '') ?></td><td class="num"><?= h(f_money($r['target_amount'] ?? 0)) ?></td>
                <?php elseif ($drilldown === 'sales'): ?>
                  <td><?= h($r['do_code'] ?? '') ?></td><td><?= h($r['do_date'] ?? '') ?></td><td><?= h($r['customers_code'] ?? '') ?></td><td><?= h($r['status'] ?? '') ?></td><td class="num"><?= h(f_money($r['net_amount'] ?? 0)) ?></td>
                <?php elseif ($drilldown === 'piutang_baru' || $drilldown === 'piutang_lama'): ?>
                  <td><?= h($r['do_code'] ?? '') ?></td><td><?= h($r['do_date'] ?? '') ?></td><td><?= h($r['customers_code'] ?? '') ?></td><td><?= h($r['status'] ?? '') ?></td><td class="num"><?= h(f_money($r['outstanding'] ?? 0)) ?></td>
                <?php else: ?>
                  <td><?= h($r['ap_code'] ?? '') ?></td><td><?= h($r['invoice_date'] ?? '') ?></td><td><?= h($r['invoice_number'] ?? '') ?></td><td><?= h($r['status'] ?? '') ?></td><td class="num"><?= h(f_money($r['outstanding'] ?? 0)) ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php $cmp = $data['section1']['comparisons'] ?? []; ?>
  <?php if (!empty($cmp)): ?>
  <div class="excel-card mt-3 mb-2">
    <div class="excel-title">PERBANDINGAN PENCAPAIAN (VS)</div>
    <div class="p-2">
      <div class="vs-grid">
        <div class="vs-card">
          <div class="vs-title">Current Pencapaian</div>
          <div class="vs-main kpi-count" data-format="money" data-target="<?= h((string)round((float)($cmp['current_total'] ?? 0))) ?>"><?= h(f_money($cmp['current_total'] ?? 0)) ?></div>
          <div class="vs-sub">Basis: RMI BMHP terpisah dari Unit ACC; consolidated = RMI + Unit ACC (ALKES + AKSESORIS)</div>
        </div>
        <div class="vs-card">
          <div class="vs-title">VS 3 Bulan</div>
          <?php $c3 = $cmp['vs_3_bulan'] ?? []; $c3ok = !array_key_exists('available',$c3) || !empty($c3['available']); $d = $c3['delta_pct'] ?? null; ?>
          <div class="vs-main<?= $c3ok ? ' kpi-count' : '' ?>"<?= $c3ok ? ' data-format="money" data-target="'.h((string)round((float)($c3['base'] ?? 0))).'"' : '' ?>><?= $c3ok ? h(f_money($c3['base'] ?? 0)) : 'N/A' ?></div>
          <div class="vs-sub <?= ($c3ok && $d !== null) ? (((float)$d >= 0) ? 'vs-up' : 'vs-down') : '' ?>"><?= ($c3ok && $d !== null) ? h(f_delta((float)$d)) . ' • ' : '' ?><?= h($c3['note'] ?? '') ?></div>
        </div>
        <div class="vs-card">
          <div class="vs-title">VS Tahun Lalu</div>
          <?php $cy = $cmp['vs_tahun'] ?? []; $cyok = !array_key_exists('available',$cy) || !empty($cy['available']); $d = $cy['delta_pct'] ?? null; ?>
          <div class="vs-main<?= $cyok ? ' kpi-count' : '' ?>"<?= $cyok ? ' data-format="money" data-target="'.h((string)round((float)($cy['base'] ?? 0))).'"' : '' ?>><?= $cyok ? h(f_money($cy['base'] ?? 0)) : 'N/A' ?></div>
          <div class="vs-sub <?= ($cyok && $d !== null) ? (((float)$d >= 0) ? 'vs-up' : 'vs-down') : '' ?>"><?= ($cyok && $d !== null) ? h(f_delta((float)$d)) . ' • ' : '' ?><?= h($cy['note'] ?? '') ?></div>
        </div>
        <div class="vs-card">
          <div class="vs-title">VS Pencapaian Tertinggi</div>
          <?php $cp = $cmp['vs_tertinggi'] ?? []; $cpok = !array_key_exists('available',$cp) || !empty($cp['available']); $d = $cp['delta_pct'] ?? null; ?>
          <div class="vs-main<?= $cpok ? ' kpi-count' : '' ?>"<?= $cpok ? ' data-format="money" data-target="'.h((string)round((float)($cp['base'] ?? 0))).'"' : '' ?>><?= $cpok ? h(f_money($cp['base'] ?? 0)) : 'N/A' ?></div>
          <div class="vs-sub <?= ($cpok && $d !== null) ? (((float)$d >= 0) ? 'vs-up' : 'vs-down') : '' ?>"><?= ($cpok && $d !== null) ? h(f_delta((float)$d)) . ' • ' : '' ?><?= h($cp['note'] ?? '') ?></div>
        </div>
        <div class="vs-card">
          <div class="vs-title">VS Hari Kerja Sama</div>
          <?php $cw = $cmp['vs_hari_kerja_sama'] ?? []; $cwok = !array_key_exists('available',$cw) || !empty($cw['available']); $d = $cw['delta_pct'] ?? null; ?>
          <div class="vs-main<?= $cwok ? ' kpi-count' : '' ?>"<?= $cwok ? ' data-format="money" data-target="'.h((string)round((float)($cw['base'] ?? 0))).'"' : '' ?>><?= $cwok ? h(f_money($cw['base'] ?? 0)) : 'N/A' ?></div>
          <div class="vs-sub <?= ($cwok && $d !== null) ? (((float)$d >= 0) ? 'vs-up' : 'vs-down') : '' ?>"><?= ($cwok && $d !== null) ? h(f_delta((float)$d)) . ' • ' : '' ?><?= h($cw['note'] ?? '') ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'target'): ?>
    <div class="tab-pane active">
      <?php
      $rekapBaseTarget = rtrim($GLOBALS['BASE_PROJECT'] ?? '', '/') . '/dashboards/finance/';
      $targetRekapBase = $rekapBaseTarget . 'target_rekap.php?month=' . (int)$monthNo . '&year=' . (int)$yearNo;
      $salesRekapBase = $rekapBaseTarget . 'sales_do_rekap.php?month=' . (int)$monthNo . '&year=' . (int)$yearNo . '&as_of=' . rawurlencode($data['as_of']) . '&type=sales&revenue_only=1';
      $renderOfficeTable = function(string $title, array $rows, ?string $forceOffice = null, ?string $segment = null) use ($cellSourceAttr, $cellSourceIcon, $targetRekapBase, $salesRekapBase) {
          echo '<div class="excel-card mb-3"><div class="excel-title">' . h($title) . '</div><div class="table-responsive">';
          echo '<table class="excel-table"><thead><tr><th>Tanggal</th><th>Office</th><th>Target</th><th>Pencapaian</th><th>Persentase</th></tr></thead><tbody>';
          foreach ($rows as $r) {
              $cls = ((float)$r['persentase'] >= 100.0) ? 'pct-high' : 'pct-low';
              $trClass = !empty($r['is_total']) ? 'total-row' : '';
              $oc = $forceOffice ?? ($r['office_code'] ?? '');
              $targetUrl = $oc ? ($targetRekapBase . '&office=' . rawurlencode($oc) . ($segment ? '&segment=' . rawurlencode($segment) : '')) : null;
              $pencUrl = $oc ? ($salesRekapBase . '&office=' . rawurlencode($oc) . ($segment ? '&segment=' . rawurlencode($segment) : '')) : null;
              echo '<tr class="' . $trClass . '">';
              echo '<td>' . h($r['tanggal']) . '</td>';
              echo '<td>' . h($r['office']) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('target') . '><a class="drill-link" href="' . h($targetUrl ?? '#') . '">' . h(f_money($r['target'])) . '</a>' . $cellSourceIcon('target', $targetUrl) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('pencapaian') . '><a class="drill-link" href="' . h($pencUrl ?? '#') . '">' . h(f_money($r['pencapaian'])) . '</a>' . $cellSourceIcon('pencapaian', $pencUrl) . '</td>';
              echo '<td class="num ' . $cls . '"' . $cellSourceAttr('persentase') . '>' . h(f_pct($r['persentase'])) . $cellSourceIcon('persentase') . '</td>';
              echo '</tr>';
          }
          echo '</tbody></table></div></div>';
      };
      $renderOfficeTable('NON HERMINA - ALL OFFICE TERMASUK TANGERANG', $data['section1']['non_hermina'], null, 'NON_HERMINA');
      $renderOfficeTable('HERMINA - ALL OFFICE TERMASUK TANGERANG', $data['section1']['hermina'], null, 'HERMINA');
      ?>

      <div class="excel-card mb-3">
        <div class="excel-title">RINGKASAN</div>
        <div class="table-responsive">
          <table class="excel-table">
            <thead><tr><th>Keterangan</th><th>Target</th><th>Pencapaian</th><th>Persentase</th></tr></thead>
            <tbody>
              <?php foreach ($data['section1']['ringkasan'] as $r):
                $seg = $r['segment'] ?? null;
                $isTotal = !empty($r['is_total']);
                $ringTargetUrl = $seg && !$isTotal ? ($targetRekapBase . '&segment=' . rawurlencode($seg)) : null;
                $ringPencUrl = $seg && !$isTotal ? ($salesRekapBase . '&segment=' . rawurlencode($seg)) : null;
              ?>
                <tr class="<?= $isTotal ? 'total-row' : '' ?>">
                  <td><?= h($r['label']) ?></td>
                  <td class="num"<?= $cellSourceAttr('target') ?>><?= $isTotal ? h(f_money($r['target'])) : '<a class="drill-link" href="' . h($ringTargetUrl ?? '#') . '">' . h(f_money($r['target'])) . '</a>' ?><?= $cellSourceIcon('target', $ringTargetUrl) ?></td>
                  <td class="num"<?= $cellSourceAttr('pencapaian') ?>><?= $isTotal ? h(f_money($r['pencapaian'])) : '<a class="drill-link" href="' . h($ringPencUrl ?? '#') . '">' . h(f_money($r['pencapaian'])) . '</a>' ?><?= $cellSourceIcon('pencapaian', $ringPencUrl) ?></td>
                  <td class="num <?= ((float)$r['persentase']>=100.0)?'pct-high':'pct-low' ?>"<?= $cellSourceAttr('persentase') ?>><?= h(f_pct($r['persentase'])) ?><?= $cellSourceIcon('persentase') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>


      <div class="excel-card mb-3">
        <div class="excel-title">UNIT ACC — PENCAPAIAN PER OFFICE OPERASIONAL</div>
        <div class="mini muted" style="padding:8px 10px 0">Pemisahan berdasarkan <b>sales_do.do_code = UNITACC-*</b>. Jenis Unit ACC = <b>ALKES + AKSESORIS</b>; office mengikuti prefix DO (BGR/BKS/TGR/dll.).</div>
        <div class="table-responsive">
          <table class="excel-table">
            <thead><tr><th>Tanggal</th><th>Office</th><th>Target Unit ACC</th><th>Alat Kesehatan</th><th>Aksesoris</th><th>Pencapaian Unit ACC</th><th>Persentase</th></tr></thead>
            <tbody>
            <?php foreach ($data['section1']['accunit'] as $r):
              $isTotal = !empty($r['is_total']);
              $uaOffice = strtoupper((string)($r['office_code'] ?? ''));
              $uaTargetUrl = $isTotal ? null : ($targetRekapBase . '&office=' . rawurlencode($uaOffice) . '&segment=ACCUNIT');
              $uaPencUrl = $isTotal ? null : ($salesRekapBase . '&office=' . rawurlencode($uaOffice) . '&segment=ACCUNIT');
            ?>
              <tr class="<?= $isTotal ? 'total-row' : '' ?>">
                <td><?= h($r['tanggal'] ?? $data['as_of']) ?></td>
                <td><?= h($r['office'] ?? $uaOffice) ?></td>
                <td class="num"><?= $uaTargetUrl ? '<a class="drill-link" href="'.h($uaTargetUrl).'">'.h(f_money($r['target'])).'</a>' : h(f_money($r['target'])) ?></td>
                <td class="num"><?= h(f_money($r['alkes'] ?? 0)) ?></td>
                <td class="num"><?= h(f_money($r['aksesoris'] ?? 0)) ?></td>
                <td class="num"><?= $uaPencUrl ? '<a class="drill-link" href="'.h($uaPencUrl).'">'.h(f_money($r['pencapaian'])).'</a>' : h(f_money($r['pencapaian'])) ?></td>
                <td class="num <?= ((float)$r['persentase']>=100.0)?'pct-high':'pct-low' ?>"><?= h(f_pct($r['persentase'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="excel-card mb-3">
        <div class="excel-title">ALL CABANG</div>
        <div class="table-responsive">
          <table class="excel-table">
            <thead><tr><th>Tanggal</th><th>Keterangan</th><th>Target</th><th>Pencapaian</th><th>Persentase</th></tr></thead>
            <tbody>
            <?php foreach ($data['section1']['all_cabang'] as $r):
              $isTotal = !empty($r['is_total']);
              $acSeg = $r['drill_segment'] ?? null;
              $acOffice = $r['drill_office'] ?? null;
              $acTargetUrl = null;
              $acPencUrl = null;
              if (!$isTotal && ($acSeg || $acOffice)) {
                $acTargetUrl = $targetRekapBase . ($acOffice ? '&office=' . rawurlencode($acOffice) : '') . ($acSeg ? '&segment=' . rawurlencode($acSeg) : '');
                $acPencUrl = $salesRekapBase . ($acOffice ? '&office=' . rawurlencode($acOffice) : '') . ($acSeg ? '&segment=' . rawurlencode($acSeg) : '');
              } elseif ($isTotal) {
                // TOTAL = BMHP + ACCUNIT. Drill ke rekap keseluruhan periode yang sama.
                $acTargetUrl = $targetRekapBase;
                $acPencUrl = $salesRekapBase;
              }
            ?>
              <tr class="<?= $isTotal ? 'total-row' : '' ?>">
                <td><?= h($r['tanggal']) ?></td>
                <td><?= h($r['keterangan']) ?></td>
                <td class="num"<?= $cellSourceAttr('target') ?>><?= $acTargetUrl ? '<a class="drill-link" href="' . h($acTargetUrl) . '">' . h(f_money($r['target'])) . '</a>' : h(f_money($r['target'])) ?><?= $cellSourceIcon('target', $acTargetUrl) ?></td>
                <td class="num"<?= $cellSourceAttr('pencapaian') ?>><?= $acPencUrl ? '<a class="drill-link" href="' . h($acPencUrl) . '">' . h(f_money($r['pencapaian'])) . '</a>' : h(f_money($r['pencapaian'])) ?><?= $cellSourceIcon('pencapaian', $acPencUrl) ?></td>
                <td class="num <?= ((float)$r['persentase']>=100.0)?'pct-high':'pct-low' ?>"<?= $cellSourceAttr('persentase') ?>><?= h(f_pct($r['persentase'])) ?><?= $cellSourceIcon('persentase') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php elseif ($tab === 'finance'): ?>
    <div class="tab-pane active">
      <?php
      $rekapBase = rtrim($GLOBALS['BASE_PROJECT'] ?? '', '/') . '/dashboards/finance/';
      $rekapParams = 'month=' . (int)$monthNo . '&year=' . (int)$yearNo . '&as_of=' . rawurlencode($data['as_of']) . '&revenue_only=1';
      $renderFinanceBlock = function(string $title, array $rows, array $footerRows = []) use ($monthNo, $yearNo, $data, $cellSourceAttr, $cellSourceIcon, $rekapParams, $rekapBase) {
          echo '<div class="excel-card mb-3"><div class="excel-title">' . h($title) . '</div><div class="table-responsive">';
          echo '<table class="excel-table"><thead><tr>';
          echo '<th>No</th><th>Office</th><th>Penjualan</th><th>Operasional</th><th>Beban Gaji/Pendapatan</th><th>Support</th><th>Fee Management Hermina</th><th>PPh 25 / PPh Final</th><th>Corporate (rate)</th><th>Persentase</th><th>Hutang</th><th>Piutang Baru</th><th>Piutang Lama</th><th>Total Piutang</th><th>Stok By Office</th><th>Jumlah</th>';
          echo '</tr></thead><tbody>';
          foreach ($rows as $r) {
              $office = (string)$r['office_code'];
              $rp = $rekapParams . '&office=' . rawurlencode($office);
              $salesRekap = $rekapBase . 'sales_do_rekap.php?' . $rp . '&type=sales';
              $piutangBaruRekap = $rekapBase . 'sales_do_rekap.php?' . $rp . '&type=piutang_baru';
              $piutangLamaRekap = $rekapBase . 'sales_do_rekap.php?' . $rp . '&type=piutang_lama';
              $apRekap = $rekapBase . 'ap_rekap.php?' . $rp;
              $glRekap = static fn(string $cat) => $rekapBase . 'gl_rekap.php?' . $rp . '&category=' . rawurlencode($cat);
              echo '<tr>';
              echo '<td class="num">' . h((string)$r['no']) . '</td>';
              echo '<td>' . h($r['office']) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('penjualan') . '><a class="drill-link" href="' . h($salesRekap) . '">' . h(f_money($r['penjualan'])) . '</a>' . $cellSourceIcon('penjualan', $salesRekap) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('operasional') . '><a class="drill-link" href="' . h($glRekap('OPERASIONAL')) . '">' . h(f_money($r['operasional'])) . '</a>' . $cellSourceIcon('operasional', $glRekap('OPERASIONAL')) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('beban_gaji') . '><a class="drill-link" href="' . h($glRekap('BEBAN_GAJI')) . '">' . h(f_money($r['beban_gaji'])) . '</a>' . $cellSourceIcon('beban_gaji', $glRekap('BEBAN_GAJI')) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('support') . '><a class="drill-link" href="' . h($glRekap('SUPPORT')) . '">' . h(f_money($r['support'])) . '</a>' . $cellSourceIcon('support', $glRekap('SUPPORT')) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('fee_management') . '><a class="drill-link" href="' . h($glRekap('FEE_MGMT')) . '">' . h(f_money($r['fee_management'])) . '</a>' . $cellSourceIcon('fee_management', $glRekap('FEE_MGMT')) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('pph') . '><a class="drill-link" href="' . h($glRekap('PPH')) . '">' . h(f_money($r['pph'])) . '</a>' . $cellSourceIcon('pph', $glRekap('PPH')) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('corporate') . '>' . h(f_money($r['corporate'])) . ' (' . h(number_format((float)$r['corporate_rate'], 1, ',', '.')) . '%)' . $cellSourceIcon('corporate') . '</td>';
              echo '<td class="num ' . (((float)$r['persentase'] <= 60.0) ? 'pct-high' : 'pct-low') . '"' . $cellSourceAttr('persentase') . '>' . h(f_pct($r['persentase'])) . $cellSourceIcon('persentase') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('hutang') . '><a class="drill-link" href="' . h($apRekap) . '">' . h(f_money($r['hutang'])) . '</a>' . $cellSourceIcon('hutang', $apRekap) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('piutang_baru') . '><a class="drill-link" href="' . h($piutangBaruRekap) . '">' . h(f_money($r['piutang_baru'])) . '</a>' . $cellSourceIcon('piutang_baru', $piutangBaruRekap) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('piutang_lama') . '><a class="drill-link" href="' . h($piutangLamaRekap) . '">' . h(f_money($r['piutang_lama'])) . '</a>' . $cellSourceIcon('piutang_lama', $piutangLamaRekap) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('total_piutang') . '><a class="drill-link" href="' . h($piutangBaruRekap) . '" title="Total = Baru + Lama">' . h(f_money($r['total_piutang'])) . '</a>' . $cellSourceIcon('total_piutang', $piutangBaruRekap) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('stock_by_office') . '>' . h(f_money($r['stock_by_office'])) . $cellSourceIcon('stock_by_office') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('jumlah') . '><strong>' . h(f_money($r['jumlah'])) . '</strong>' . $cellSourceIcon('jumlah') . '</td>';
              echo '</tr>';
          }
          foreach ($footerRows as $fr) {
              echo '<tr class="total-row"><td colspan="2">' . h($fr['label']) . '</td>';
              echo '<td class="num"' . $cellSourceAttr('penjualan') . '>' . h(isset($fr['penjualan']) ? f_money($fr['penjualan']) : '-') . $cellSourceIcon('penjualan') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('operasional') . '>' . h(isset($fr['operasional']) ? f_money($fr['operasional']) : '-') . $cellSourceIcon('operasional') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('beban_gaji') . '>' . h(isset($fr['beban_gaji']) ? f_money($fr['beban_gaji']) : '-') . $cellSourceIcon('beban_gaji') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('support') . '>' . h(isset($fr['support']) ? f_money($fr['support']) : '-') . $cellSourceIcon('support') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('fee_management') . '>' . h(isset($fr['fee_management']) ? f_money($fr['fee_management']) : '-') . $cellSourceIcon('fee_management') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('pph') . '>' . h(isset($fr['pph']) ? f_money($fr['pph']) : '-') . $cellSourceIcon('pph') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('corporate') . '>' . h(isset($fr['corporate']) ? f_money($fr['corporate']) : '-') . $cellSourceIcon('corporate') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('persentase') . '>' . h(isset($fr['persentase']) ? f_pct($fr['persentase']) : '-') . $cellSourceIcon('persentase') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('hutang') . '>' . h(isset($fr['hutang']) ? f_money($fr['hutang']) : '-') . $cellSourceIcon('hutang') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('piutang_baru') . '>' . h(isset($fr['piutang_baru']) ? f_money($fr['piutang_baru']) : '-') . $cellSourceIcon('piutang_baru') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('piutang_lama') . '>' . h(isset($fr['piutang_lama']) ? f_money($fr['piutang_lama']) : '-') . $cellSourceIcon('piutang_lama') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('total_piutang') . '>' . h(isset($fr['total_piutang']) ? f_money($fr['total_piutang']) : '-') . $cellSourceIcon('total_piutang') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('stock_by_office') . '>' . h(isset($fr['stock_by_office']) ? f_money($fr['stock_by_office']) : '-') . $cellSourceIcon('stock_by_office') . '</td>';
              echo '<td class="num"' . $cellSourceAttr('jumlah') . '><strong>' . h(isset($fr['jumlah']) ? f_money($fr['jumlah']) : '-') . '</strong>' . $cellSourceIcon('jumlah') . '</td></tr>';
          }
          echo '</tbody></table></div></div>';
      };

      $main = $data['section2']['main'];
      $acc = $data['section2']['accunit'];
      $tgr = $data['section2']['tangerang'];

      // Tangerang bukan blok terpisah lagi: tampilkan sebagai office biasa di BLOK UTAMA.
      // Data sumber section2.tangerang tetap dipertahankan agar query, audit/integrity check,
      // executive KPI, dan kompatibilitas alur lama tidak berubah.
      $mainDisplayRows = array_merge($main['rows'] ?? [], $tgr['rows'] ?? []);
      foreach ($mainDisplayRows as $i => &$mainDisplayRow) {
          $mainDisplayRow['no'] = $i + 1;
          if (strtoupper((string)($mainDisplayRow['office_code'] ?? '')) === 'TGR') {
              $mainDisplayRow['office'] = 'Tangerang';
          }
      }
      unset($mainDisplayRow);

      $mainDisplayTotal = $main['total'] ?? [];
      $tgrTotal = $tgr['total'] ?? [];
      foreach (['penjualan','operasional','beban_gaji','support','fee_management','pph','corporate','hutang','piutang_baru','piutang_lama','total_piutang','stock_by_office','jumlah'] as $sumKey) {
          $mainDisplayTotal[$sumKey] = (float)($mainDisplayTotal[$sumKey] ?? 0) + (float)($tgrTotal[$sumKey] ?? 0);
      }
      $mainDisplaySales = (float)($mainDisplayTotal['penjualan'] ?? 0);
      $mainDisplayExpense = (float)($mainDisplayTotal['operasional'] ?? 0)
                          + (float)($mainDisplayTotal['beban_gaji'] ?? 0)
                          + (float)($mainDisplayTotal['support'] ?? 0)
                          + (float)($mainDisplayTotal['fee_management'] ?? 0)
                          + (float)($mainDisplayTotal['pph'] ?? 0)
                          + (float)($mainDisplayTotal['corporate'] ?? 0);
      $mainDisplayTotal['persentase'] = $mainDisplaySales > 0 ? ($mainDisplayExpense / $mainDisplaySales) * 100.0 : 0.0;
      $mainDisplayTarget = (float)($main['target_penjualan'] ?? 0) + (float)($tgr['target_penjualan'] ?? 0);
      $mainDisplayAchievementPct = $mainDisplayTarget > 0 ? ($mainDisplaySales / $mainDisplayTarget) * 100.0 : 0.0;

      $renderFinanceBlock('BLOK UTAMA', $mainDisplayRows, [
          array_merge(['label' => 'TOTAL'], $mainDisplayTotal),
          ['label' => 'Target', 'penjualan' => $mainDisplayTarget],
          ['label' => 'Pencapaian %', 'persentase' => $mainDisplayAchievementPct],
          ['label' => $main['saldo_label'], 'jumlah' => $main['saldo_value'] ?? 0],
      ]);

      $renderFinanceBlock('BLOK ACCUNIT (Corporate rate 5%)', $acc['rows'], [
          array_merge(['label' => 'TOTAL ACCUNIT'], $acc['total']),
          ['label' => 'Target', 'penjualan' => $acc['target_penjualan']],
          ['label' => 'Pencapaian %', 'persentase' => $acc['pencapaian_pct']],
          ['label' => $acc['saldo_label'], 'jumlah' => $acc['saldo_value'] ?? 0],
      ]);
      ?>

      <?php
      $financeAllOfficeSales = (float)($main['total']['penjualan'] ?? 0)
                           + (float)($acc['total']['penjualan'] ?? 0)
                           + (float)($tgr['total']['penjualan'] ?? 0);
      ?>
      <div class="excel-card mb-3">
        <div class="excel-title">RINGKASAN BAWAH</div>
        <table class="excel-table">
          <tbody>
            <tr class="total-row">
              <td style="width:60%">Total Penjualan ALL OFFICE BMHP (termasuk Tangerang)</td>
              <td class="num"><strong><?= h(f_money($financeAllOfficeSales)) ?></strong></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="subnote">
        Rumus: Persentase = (Operasional + Beban + Support + Fee + PPh + Corporate) / Penjualan x 100.
        Jumlah = Profit + WorkingCapital + Adjustment.
      </div>
    </div>
  <?php elseif ($tab === 'chart'): ?>
    <div class="tab-pane active">
      <div class="excel-card mb-3">
        <div class="excel-title">Realtime Controls</div>
        <div class="p-2 d-flex flex-wrap gap-2 align-items-center">
          <span class="subnote">Auto-refresh chart:</span>
          <a class="btn btn-sm <?= $refreshSec === 0 ? 'btn-primary' : 'btn-outline-primary' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=chart&refresh=0&motion=<?= h($motionMode) ?>&strict=<?= (int)$strictMode ?>&anomaly_threshold=<?= h(number_format($anomalyThreshold,1,'.','')) ?>">Off</a>
          <a class="btn btn-sm <?= $refreshSec === 30 ? 'btn-primary' : 'btn-outline-primary' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=chart&refresh=30&motion=<?= h($motionMode) ?>&strict=<?= (int)$strictMode ?>&anomaly_threshold=<?= h(number_format($anomalyThreshold,1,'.','')) ?>">30s</a>
          <a class="btn btn-sm <?= $refreshSec === 60 ? 'btn-primary' : 'btn-outline-primary' ?>" href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=chart&refresh=60&motion=<?= h($motionMode) ?>&strict=<?= (int)$strictMode ?>&anomaly_threshold=<?= h(number_format($anomalyThreshold,1,'.','')) ?>">60s</a>
          <span id="rmiRefreshCountdown" class="subnote ms-2"></span>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="chart-card">
            <div class="chart-title">Target vs Pencapaian per Office</div>
            <?php if ($chartMax <= 0): ?>
              <div class="subnote">Belum ada data untuk divisualisasikan.</div>
            <?php else: ?>
              <?php foreach ($officeChartRows as $r): ?>
                <?php
                  $wTarget = ((float)$r['target'] / $chartMax) * 100.0;
                  $wAch = ((float)$r['pencapaian'] / $chartMax) * 100.0;
                ?>
                <div class="bar-row">
                  <div><?= h($r['label']) ?></div>
                  <div class="bar-wrap">
                    <div class="bar-target" style="width: <?= h(number_format($wTarget, 2, '.', '')) ?>%"></div>
                    <div class="bar-ach" style="width: <?= h(number_format($wAch, 2, '.', '')) ?>%"></div>
                  </div>
                  <div class="bar-value"><?= h(f_pct($r['pct'])) ?></div>
                </div>
              <?php endforeach; ?>
              <div class="subnote mt-2">
                Kuning = Target, Hijau = Pencapaian.
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="chart-card">
            <div class="chart-title">Komposisi Pencapaian</div>
            <?php
              $colors = ['#22c55e','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f97316'];
              $startDeg = 0.0;
              $parts = [];
              foreach ($segmentRowsChart as $i => $r) {
                  $pct = $segmentTotalChart > 0 ? (((float)$r['value'] / $segmentTotalChart) * 100.0) : 0.0;
                  $deg = $pct * 3.6;
                  $parts[] = [
                    'label' => $r['label'],
                    'value' => $r['value'],
                    'pct' => $pct,
                    'color' => $colors[$i % count($colors)],
                    'start' => $startDeg,
                    'end' => $startDeg + $deg,
                  ];
                  $startDeg += $deg;
              }
              $grad = [];
              foreach ($parts as $p) {
                  $grad[] = $p['color'] . ' ' . number_format($p['start'], 2, '.', '') . 'deg ' . number_format($p['end'], 2, '.', '') . 'deg';
              }
              $bg = count($grad) > 0 ? ('conic-gradient(' . implode(', ', $grad) . ')') : 'conic-gradient(#e5e7eb 0 360deg)';
            ?>
            <div class="donut" style="background: <?= h($bg) ?>"></div>
            <div class="legend">
              <?php foreach ($parts as $p): ?>
                <div>
                  <span class="sw" style="background: <?= h($p['color']) ?>"></span>
                  <span><?= h($p['label']) ?>: <?= h(f_money($p['value'])) ?> (<?= h(number_format((float)$p['pct'], 1, ',', '.')) ?>%)</span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="chart-card">
            <div class="chart-title">Realtime By Day (MTD sampai As-Of)</div>
            <?php if (count($dailyLabels) === 0): ?>
              <div class="subnote">Belum ada data harian.</div>
            <?php else: ?>
              <?php
                $n = count($dailyLabels);
                $w = max(700, $n * 34);
                $h = 250;
                $padL = 40;
                $padR = 16;
                $padT = 16;
                $padB = 34;
                $plotW = $w - $padL - $padR;
                $plotH = $h - $padT - $padB;
                $stepX = $n > 1 ? ($plotW / ($n - 1)) : $plotW;
                $dailyLinePoints = [];
                $cumLinePoints = [];
                for ($i = 0; $i < $n; $i++) {
                    $x = $padL + ($i * $stepX);
                    $dv = (float)$dailyValues[$i];
                    $cv = (float)$cumValues[$i];
                    $yDaily = $padT + ($plotH - (($dailyMax > 0 ? ($dv / $dailyMax) : 0.0) * $plotH));
                    $yCum = $padT + ($plotH - (($cumMax > 0 ? ($cv / $cumMax) : 0.0) * $plotH));
                    $dailyLinePoints[] = number_format($x, 2, '.', '') . ',' . number_format($yDaily, 2, '.', '');
                    $cumLinePoints[] = number_format($x, 2, '.', '') . ',' . number_format($yCum, 2, '.', '');
                }
              ?>
              <div class="svg-wrap">
                <svg viewBox="0 0 <?= h((string)$w) ?> <?= h((string)$h) ?>" style="width:100%;min-width:<?= h((string)$w) ?>px;height:auto;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">
                  <line x1="<?= h((string)$padL) ?>" y1="<?= h((string)($h - $padB)) ?>" x2="<?= h((string)($w - $padR)) ?>" y2="<?= h((string)($h - $padB)) ?>" stroke="#cbd5e1"/>
                  <line x1="<?= h((string)$padL) ?>" y1="<?= h((string)$padT) ?>" x2="<?= h((string)$padL) ?>" y2="<?= h((string)($h - $padB)) ?>" stroke="#cbd5e1"/>
                  <polyline points="<?= h(implode(' ', $dailyLinePoints)) ?>" fill="none" stroke="#22c55e" stroke-width="2.2"/>
                  <polyline points="<?= h(implode(' ', $cumLinePoints)) ?>" fill="none" stroke="#2563eb" stroke-width="2.2"/>
                  <?php for ($i = 0; $i < $n; $i++): ?>
                    <?php $x = $padL + ($i * $stepX); ?>
                    <text x="<?= h(number_format($x, 2, '.', '')) ?>" y="<?= h((string)($h - 10)) ?>" text-anchor="middle" font-size="10" fill="#6b7280">
                      <?= h(date('d', strtotime((string)$dailyLabels[$i]))) ?>
                    </text>
                  <?php endfor; ?>
                </svg>
              </div>
              <div class="line-caption mt-2">
                Garis hijau = penjualan harian, garis biru = kumulatif MTD.
              </div>
              <div class="mini-grid mt-2">
                <div class="subnote">Hari terakhir: <strong><?= h(date('d-M-y', strtotime((string)end($dailyLabels)))) ?></strong></div>
                <div class="subnote">Penjualan harian terakhir: <strong><?= h(f_money((float)end($dailyValues))) ?></strong></div>
                <div class="subnote">Kumulatif s.d. as-of: <strong><?= h(f_money((float)end($cumValues))) ?></strong></div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-12">
          <div class="chart-card">
            <div class="chart-title">Top Office berdasarkan Jumlah (Finance Detail)</div>
            <?php if ($financeMaxJumlah <= 0): ?>
              <div class="subnote">Belum ada nilai Jumlah untuk ditampilkan.</div>
            <?php else: ?>
              <?php foreach ($financeTopJumlah as $r): ?>
                <?php $w = ((float)$r['jumlah'] / $financeMaxJumlah) * 100.0; ?>
                <div class="bar-row">
                  <div><?= h($r['office']) ?></div>
                  <div class="bar-wrap">
                    <div class="bar-ach" style="width: <?= h(number_format($w, 2, '.', '')) ?>%;background:#0ea5e9"></div>
                  </div>
                  <div class="bar-value"><?= h(f_money($r['jumlah'])) ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php elseif ($tab === 'exec'):
    // === EXECUTIVE SUMMARY TAB ===
    // Hitung variabel yang biasanya dihitung di chart tab
    $n = count($dailyLabels);
    $execColors = ['#22c55e','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f97316'];
    $execStartDeg = 0.0; $parts = []; $grad = [];
    foreach (is_array($segmentRowsChart) ? $segmentRowsChart : [] as $i => $r) {
      $pct2 = $segmentTotalChart > 0 ? (((float)$r['value'] / $segmentTotalChart) * 100.0) : 0.0;
      $deg2 = $pct2 * 3.6;
      $parts[] = ['label'=>$r['label'],'value'=>$r['value'],'pct'=>$pct2,'color'=>$execColors[$i%7],'start'=>$execStartDeg,'end'=>$execStartDeg+$deg2];
      $grad[] = $execColors[$i%7].' '.number_format($execStartDeg,2,'.','').'deg '.number_format($execStartDeg+$deg2,2,'.','').'deg';
      $execStartDeg += $deg2;
    }
    $bg = count($grad) > 0 ? ('conic-gradient('.implode(', ',$grad).')') : 'conic-gradient(#e5e7eb 0 360deg)';

    // Hitung KPI utama
    // Executive tab uses ALL OFFICE including Tangerang/TGR.
    $execTarget = $allOfficeTarget;
    $execPencapaian = $allOfficePencapaian;
    $execPct = $allOfficePct;

    $main2 = $data['section2']['main']     ?? ['total'=>[],'rows'=>[]];
    $acc2  = $data['section2']['accunit']  ?? ['total'=>[],'rows'=>[]];
    $tgr2  = $data['section2']['tangerang']?? ['total'=>[],'rows'=>[]];
    $execPiutang = (float)($main2['total']['total_piutang'] ?? 0)
                 + (float)($acc2['total']['total_piutang']  ?? 0)
                 + (float)($tgr2['total']['total_piutang']  ?? 0);
    $execHutang  = (float)($main2['total']['hutang'] ?? 0)
                 + (float)($acc2['total']['hutang']  ?? 0)
                 + (float)($tgr2['total']['hutang']  ?? 0);
    // Stok adalah nilai fisik per office, jangan dijumlahkan ulang dari ACCUNIT
    // karena ACCUNIT bukan gudang stok terpisah. Hitung main office + TGR satu kali.
    $execStok    = (float)($main2['total']['stock_by_office'] ?? 0)
                 + (float)($tgr2['total']['stock_by_office']  ?? 0);
    if ($execStok <= 0 && isset($data['finance']['stock_by_office']['ALL'])) {
        $execStok = (float)$data['finance']['stock_by_office']['ALL'];
    }

    // Karyawan hadir hari ini dari absensi
    $execHadir = 0;
    try {
      $stH = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM absensi_logs WHERE deleted_at IS NULL AND action_type='IN' AND DATE(created_at)=CURDATE()");
      $execHadir = (int)$stH->fetchColumn();
    } catch (Throwable $e) {}

    // Top-6 office memakai actual ledger canonical yang sama dengan Target vs Pencapaian.
    // Jangan mengambil section2 finance-recognized karena basisnya dapat berbeda dan membuat
    // Executive Summary menampilkan angka cabang yang tidak sama dengan tab Target.
    $execOffices = [];
    foreach (['BGR','BKS','SLO','BDG','SMG','JGY','KAL','TGR'] as $oc) {
      $v = round((float)($ddActual['by_office'][$oc] ?? 0),0);
      if ($v <= 0) continue;
      $execOffices[] = [
        'office'=>(string)($data['office_meta'][$oc]['name'] ?? $oc),
        'penjualan'=>$v,
      ];
    }
    usort($execOffices, fn($a,$b) => $b['penjualan'] <=> $a['penjualan']);
    $top6 = array_slice(array_values($execOffices), 0, 6);
    $top6Max = $top6 ? max(array_column($top6,'penjualan')) : 1;
  ?>
  <div class="tab-pane active" style="padding-top:16px">
    <style>
      .exec-kpi{background:rgba(15,23,42,.8);border:1px solid rgba(6,182,212,.25);border-radius:14px;padding:18px 22px;display:flex;flex-direction:column;gap:4px;transition:all .25s}
      .exec-kpi:hover{border-color:rgba(6,182,212,.5);box-shadow:0 0 24px rgba(6,182,212,.15)}
      .exec-kpi-label{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
      .exec-kpi-val{font-size:28px;font-weight:800;color:#67e8f9;font-variant-numeric:tabular-nums;line-height:1.1}
      .exec-kpi-sub{font-size:12px;color:#94a3b8;margin-top:2px}
      .exec-kpi-val.green{color:#4ade80}
      .exec-kpi-val.red{color:#f87171}
      .exec-kpi-val.yellow{color:#fbbf24}
      .exec-kpi-val.blue{color:#60a5fa}
      .exec-section-title{font-size:12px;font-weight:700;color:#06b6d4;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}
      .exec-bar-label{font-size:13px;font-weight:600;color:#e2e8f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .exec-bar-val{font-size:13px;color:#94a3b8;font-variant-numeric:tabular-nums;min-width:90px;text-align:right}
      .exec-bar-wrap{background:rgba(30,41,59,.9);border-radius:6px;height:24px;overflow:hidden;position:relative;flex:1}
      .exec-bar-fill{height:100%;border-radius:6px;transition:width 1s cubic-bezier(.2,.8,.2,1)}
    </style>

    <!-- Row 1: 6 KPI Cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
      <div class="exec-kpi">
        <div class="exec-kpi-label">Total Penjualan MTD</div>
        <div class="exec-kpi-val green">Rp <?= h(number_format($execPencapaian/1e6,2,',','.')) ?>M</div>
        <div class="exec-kpi-sub">Month to date</div>
      </div>
      <div class="exec-kpi">
        <div class="exec-kpi-label">Target MTD</div>
        <div class="exec-kpi-val blue">Rp <?= h(number_format($execTarget/1e6,2,',','.')) ?>M</div>
        <div class="exec-kpi-sub">Bulan <?= h($monthName) ?> <?= h((string)$yearNo) ?></div>
      </div>
      <div class="exec-kpi" style="border-color:<?= $execPct>=100?'rgba(34,197,94,.4)':'rgba(239,68,68,.3)' ?>">
        <div class="exec-kpi-label">% Pencapaian</div>
        <div class="exec-kpi-val <?= $execPct>=100?'green':'red' ?>"><?= h(f_pct($execPct)) ?></div>
        <div class="exec-kpi-sub"><?= $execPct>=100?rmi_icon('check').' Target tercapai':rmi_icon('warn').' Belum mencapai target' ?></div>
      </div>
      <div class="exec-kpi">
        <div class="exec-kpi-label">Total Piutang</div>
        <div class="exec-kpi-val yellow">Rp <?= h(number_format($execPiutang/1e6,2,',','.')) ?>M</div>
        <div class="exec-kpi-sub">AR outstanding</div>
      </div>
      <div class="exec-kpi">
        <div class="exec-kpi-label">Total Hutang (AP)</div>
        <div class="exec-kpi-val red">Rp <?= h(number_format($execHutang/1e6,2,',','.')) ?>M</div>
        <div class="exec-kpi-sub">AP outstanding</div>
      </div>
      <div class="exec-kpi">
        <div class="exec-kpi-label">Karyawan Hadir</div>
        <div class="exec-kpi-val"><?= h((string)$execHadir) ?></div>
        <div class="exec-kpi-sub">Check-in hari ini</div>
      </div>
    </div>

    <!-- Row 2: Target vs Pencapaian per Office + Segment Donut -->
    <div style="display:grid;grid-template-columns:1fr 360px;gap:12px;margin-bottom:12px">
      <div class="chart-card">
        <div class="exec-section-title">Target vs Pencapaian per Office</div>
        <?php if ($chartMax > 0): foreach ($officeChartRows as $r):
          $wT = $chartMax>0 ? ((float)$r['target']/$chartMax*100):0;
          $wA = $chartMax>0 ? ((float)$r['pencapaian']/$chartMax*100):0;
          $pct = (float)$r['pct'];
        ?>
          <div style="display:grid;grid-template-columns:90px 1fr 70px;gap:8px;align-items:center;margin:8px 0">
            <div class="exec-bar-label" title="<?= h($r['label']) ?>"><?= h($r['label']) ?></div>
            <div style="display:flex;flex-direction:column;gap:3px">
              <div class="exec-bar-wrap">
                <div class="exec-bar-fill" style="width:<?= h(number_format($wT,2,'.','')) ?>%;background:linear-gradient(90deg,#eab308,#f59e0b)"></div>
              </div>
              <div class="exec-bar-wrap">
                <div class="exec-bar-fill" style="width:<?= h(number_format($wA,2,'.','')) ?>%;background:linear-gradient(90deg,<?= $pct>=100?'#22c55e,#10b981':'#3b82f6,#06b6d4' ?>)"></div>
              </div>
            </div>
            <div class="exec-bar-val <?= $pct>=100?'green':'red' ?>"><?= h(f_pct($pct)) ?></div>
          </div>
        <?php endforeach; else: ?>
          <div class="subnote">Belum ada data.</div>
        <?php endif; ?>
        <div class="subnote mt-2" style="font-size:11px">■ <span style="color:#f59e0b">Target</span> &nbsp; ■ <span style="color:#06b6d4">Pencapaian</span></div>
      </div>

      <div class="chart-card">
        <div class="exec-section-title">Komposisi Pencapaian</div>
        <div class="donut" style="width:160px;height:160px;background:<?= h($bg) ?>;margin:8px auto"></div>
        <div class="legend" style="margin-top:12px">
          <?php foreach ($parts as $p): ?>
            <div style="display:flex;align-items:center;gap:8px;margin:6px 0;font-size:13px;color:#cbd5e1">
              <span style="width:12px;height:12px;border-radius:4px;display:inline-block;background:<?= h($p['color']) ?>"></span>
              <span><?= h($p['label']) ?>: <strong><?= h(f_money($p['value'])) ?></strong> <span style="color:#64748b">(<?= h(number_format((float)$p['pct'],1,',','.')) ?>%)</span></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Row 3: Penjualan Harian + Top-6 Cabang -->
    <div style="display:grid;grid-template-columns:1fr 360px;gap:12px;margin-bottom:12px">
      <div class="chart-card">
        <div class="exec-section-title">Penjualan Harian MTD (as of <?= h(date('d-M-Y', strtotime($data['as_of']))) ?>)</div>
        <?php if (count($dailyLabels) > 0):
          $n2=$n; $w2=max(500,$n2*30); $h2=200; $pL=40;$pR=12;$pT=12;$pB=28;
          $pW=$w2-$pL-$pR; $pH=$h2-$pT-$pB;
          $sX=$n2>1?($pW/($n2-1)):$pW;
          $dPts=[]; $cPts=[];
          for($i=0;$i<$n2;$i++){
            $x=$pL+($i*$sX);
            $yD=$pT+($pH-(($dailyMax>0?(float)$dailyValues[$i]/$dailyMax:0)*$pH));
            $yC=$pT+($pH-(($cumMax>0?(float)$cumValues[$i]/$cumMax:0)*$pH));
            $dPts[]=number_format($x,2,'.','').','.number_format($yD,2,'.','');
            $cPts[]=number_format($x,2,'.','').','.number_format($yC,2,'.','');
          }
        ?>
        <div style="overflow-x:auto">
          <svg viewBox="0 0 <?= h((string)$w2) ?> <?= h((string)$h2) ?>" style="width:100%;min-width:<?= h((string)$w2) ?>px;height:auto">
            <rect width="<?= $w2 ?>" height="<?= $h2 ?>" fill="rgba(15,23,42,.5)" rx="8"/>
            <line x1="<?= $pL ?>" y1="<?= $h2-$pB ?>" x2="<?= $w2-$pR ?>" y2="<?= $h2-$pB ?>" stroke="rgba(6,182,212,.3)" stroke-width="1"/>
            <line x1="<?= $pL ?>" y1="<?= $pT ?>" x2="<?= $pL ?>" y2="<?= $h2-$pB ?>" stroke="rgba(6,182,212,.3)" stroke-width="1"/>
            <polyline points="<?= h(implode(' ',$dPts)) ?>" fill="none" stroke="#4ade80" stroke-width="2.5" stroke-linejoin="round"/>
            <polyline points="<?= h(implode(' ',$cPts)) ?>" fill="none" stroke="#60a5fa" stroke-width="2.5" stroke-linejoin="round" stroke-dasharray="6,3"/>
            <?php for($i=0;$i<$n2;$i+=max(1,intval($n2/8))):
              $x=$pL+($i*$sX);?>
              <text x="<?= h(number_format($x,2,'.','')) ?>" y="<?= $h2-8 ?>" text-anchor="middle" font-size="10" fill="#64748b"><?= h(date('d',strtotime((string)$dailyLabels[$i]))) ?></text>
            <?php endfor; ?>
          </svg>
        </div>
        <div class="subnote mt-1" style="font-size:11px">
          — <span style="color:#4ade80">Harian</span> &nbsp; - - <span style="color:#60a5fa">Kumulatif</span>
          &nbsp;|&nbsp; Terakhir: <strong><?= h(f_money((float)end($cumValues))) ?></strong>
        </div>
        <?php else: ?><div class="subnote">Belum ada data harian.</div><?php endif; ?>
      </div>

      <div class="chart-card">
        <div class="exec-section-title">Top-6 Cabang by Penjualan</div>
        <?php foreach ($top6 as $i => $r):
          $colors2=['#22d3ee','#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444'];
          $w3=$top6Max>0?((float)$r['penjualan']/$top6Max*100):0;
        ?>
          <div style="margin:10px 0">
            <div style="display:flex;justify-content:space-between;margin-bottom:3px">
              <span style="font-size:13px;font-weight:600;color:#e2e8f0"><?= h($r['office']) ?></span>
              <span style="font-size:12px;color:#94a3b8"><?= h(f_money($r['penjualan'])) ?></span>
            </div>
            <div class="exec-bar-wrap">
              <div class="exec-bar-fill" style="width:<?= h(number_format($w3,2,'.','')) ?>%;background:<?= $colors2[$i%6] ?>"></div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$top6): ?><div class="subnote">Belum ada data.</div><?php endif; ?>
      </div>
    </div>

    <!-- Row 4: Bottom info -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
      <div class="exec-kpi">
        <div class="exec-kpi-label">Total Stok (Nilai)</div>
        <div class="exec-kpi-val" style="font-size:22px">Rp <?= h(number_format($execStok/1e6,2,',','.')) ?>M</div>
        <div class="exec-kpi-sub">Semua office</div>
      </div>
      <div class="exec-kpi">
        <div class="exec-kpi-label">Penjualan Harian Terakhir</div>
        <div class="exec-kpi-val green" style="font-size:22px"><?= count($dailyValues)?h(f_money((float)end($dailyValues))):'-' ?></div>
        <div class="exec-kpi-sub"><?= count($dailyLabels)?h(date('d M Y',strtotime((string)end($dailyLabels)))):'-' ?></div>
      </div>
      <div class="exec-kpi">
        <div class="exec-kpi-label">Kumulatif s.d. As-Of</div>
        <div class="exec-kpi-val blue" style="font-size:22px"><?= count($cumValues)?h(f_money((float)end($cumValues))):'-' ?></div>
        <div class="exec-kpi-sub">As of <?= h(date('d-M-Y',strtotime($data['as_of']))) ?></div>
      </div>
      <div class="exec-kpi" style="text-align:center;align-items:center">
        <div class="exec-kpi-label">Monitoring Mode</div>
        <a href="?month=<?= (int)$monthNo ?>&year=<?= (int)$yearNo ?>&as_of=<?= h($data['as_of']) ?>&tab=exec&refresh=60&motion=live&kiosk=1"
           style="background:rgba(251,191,36,.2);color:#fbbf24;border:1px solid rgba(251,191,36,.4);padding:8px 18px;border-radius:10px;font-size:13px;text-decoration:none;font-weight:700;margin-top:4px;display:inline-block">
          <?=rmi_icon('chart')?> Buka Full Screen
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
</div>
<?php if ($refreshSec > 0): ?>
<script>
(function () {
  var refreshSec = <?= (int)$refreshSec ?>;
  if (!refreshSec || refreshSec < 1) return;
  var left = refreshSec;
  var countdownEl = document.getElementById('rmiRefreshCountdown');
  var tick = function () {
    if (countdownEl) {
      countdownEl.textContent = 'Reload in ' + left + 's';
    }
    if (left <= 0) {
      window.location.reload();
      return;
    }
    left -= 1;
    window.setTimeout(tick, 1000);
  };
  tick();
})();
</script>
<?php endif; ?>
<script>
(function () {
  var motionMode = <?= json_encode($motionMode, JSON_UNESCAPED_SLASHES) ?>;
  if (motionMode === 'classic') return;
  var nodes = document.querySelectorAll('.excel-card, .chart-card, .vs-card');
  if (!nodes.length) return;
  nodes.forEach(function (el) { el.classList.add('reveal'); });
  if (!('IntersectionObserver' in window)) {
    nodes.forEach(function (el) { el.classList.add('in'); });
    return;
  }
  var idx = 0;
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      var el = entry.target;
      var delay = Math.min(360, idx * 40);
      window.setTimeout(function () { el.classList.add('in'); }, delay);
      idx += 1;
      io.unobserve(el);
    });
  }, { threshold: 0.12 });
  nodes.forEach(function (el) { io.observe(el); });
})();
</script>
<script>
(function () {
  var motionMode = <?= json_encode($motionMode, JSON_UNESCAPED_SLASHES) ?>;
  if (motionMode === 'classic') return;
  var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (prefersReduced) return;
  var counters = document.querySelectorAll('.kpi-count[data-target]');
  if (!counters.length) return;

  var fmtMoney = function (n) {
    try { return Number(n).toLocaleString('id-ID'); } catch (e) { return String(Math.round(n)); }
  };

  var animateCounter = function (el) {
    if (el.dataset.animated === '1') return;
    var end = Number(el.dataset.target || '0');
    var format = String(el.dataset.format || 'number');
    var startTs = null;
    var duration = 900;
    var from = 0;
    el.dataset.animated = '1';

    var step = function (ts) {
      if (!startTs) startTs = ts;
      var p = Math.min(1, (ts - startTs) / duration);
      var eased = 1 - Math.pow(1 - p, 3);
      var val = from + (end - from) * eased;
      if (format === 'money') {
        el.textContent = fmtMoney(val);
      } else {
        el.textContent = String(Math.round(val));
      }
      if (p < 1) window.requestAnimationFrame(step);
    };
    window.requestAnimationFrame(step);
  };

  if (!('IntersectionObserver' in window)) {
    counters.forEach(animateCounter);
    return;
  }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      animateCounter(entry.target);
      io.unobserve(entry.target);
    });
  }, { threshold: 0.35 });
  counters.forEach(function (el) { io.observe(el); });
})();
</script>
<script>
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var icons = document.querySelectorAll('.src-info-icon');
    var lastPopover = null;
    icons.forEach(function (icon) {
      icon.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var table = icon.getAttribute('data-source-table') || '';
        var purpose = icon.getAttribute('data-source-purpose') || '';
        if (!table && !purpose) return;
        if (lastPopover && lastPopover.tip) {
          try { lastPopover.dispose(); } catch (x) {}
        }
        if (window.bootstrap && window.bootstrap.Popover) {
          var content = '<div class="source-popover"><strong>Tabel:</strong> <code>' + (table || '-') + '</code><br><strong>Keterangan:</strong> ' + (purpose || '-') + '</div>';
          lastPopover = new bootstrap.Popover(icon, {
            title: 'Sumber Data',
            content: content,
            html: true,
            trigger: 'manual',
            placement: 'top'
          });
          lastPopover.show();
          setTimeout(function () {
            document.addEventListener('click', function closePopover() {
              document.removeEventListener('click', closePopover);
              if (lastPopover && lastPopover.tip) {
                try { lastPopover.hide(); } catch (x) {}
              }
            });
          }, 10);
        } else {
          alert('Sumber data:\nTabel: ' + table + '\nKeterangan: ' + purpose);
        }
      });
    });
  });
})();
</script>
<?php rmi_footer(); ?>

