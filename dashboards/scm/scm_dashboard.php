<?php
/**
 * dashboards/scm/scm_dashboard.php
 * Supply Chain Management Dashboard — v3
 */
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();

$pdo      = $GLOBALS['pdo'] ?? null;
$bp       = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
$scopeCtx = function_exists('ds_scope_ctx') ? ds_scope_ctx() : ['is_admin' => true, 'office_code' => ''];

// Strict access flag for Manager SCM widgets/links.
// StaffSCM_* must never see/open Manager SCM panel, even if another helper misreads scope.
if (!function_exists('scm_session_value')) {
    function scm_session_value(array $keys): string {
        $sources = [$_SESSION ?? []];
        foreach (['user','auth','login','account'] as $k) {
            if (isset($_SESSION[$k]) && is_array($_SESSION[$k])) $sources[] = $_SESSION[$k];
        }
        foreach ($sources as $src) {
            foreach ($keys as $key) {
                if (isset($src[$key]) && !is_array($src[$key]) && (string)$src[$key] !== '') {
                    return (string)$src[$key];
                }
            }
        }
        return '';
    }
}
$scmUsername = scm_session_value(['username','user_name','login_username']);
$scmRole     = strtolower(scm_session_value(['role','user_role','level_role']));
$scmLevel    = strtoupper(scm_session_value(['level','user_level','level_type']));
$scmDept     = strtoupper(scm_session_value(['department','dept_code','dept','departement']));

$isStaffSCMUser = (bool)preg_match('/^StaffSCM_/i', $scmUsername)
    || ($scmDept === 'SCM' && ($scmRole === 'staff' || $scmLevel === 'STAFF'));

$isAdminUser = in_array($scmRole, ['admin','owner','sys','superadmin'], true)
    || in_array($scmLevel, ['ADMIN','OWNER','SYS','SUPERADMIN'], true);

$isManagerSCMUser = ($scmDept === 'SCM' && ($scmRole === 'manager' || $scmLevel === 'MANAGER'))
    || (bool)preg_match('/^MgrSCM_/i', $scmUsername);

$canOpenManagerSCM = !$isStaffSCMUser && ($isAdminUser || $isManagerSCMUser);

// StaffSCM_BDG is a delivery-only operational account.
// Scope: Bandung DO + Unit ACC DO, only at SCM delivery stages.
$isStaffSCMBDG = (strcasecmp(trim($scmUsername), 'StaffSCM_BDG') === 0);
$isDeliveryOnlySCM = $isStaffSCMBDG;

// Manager SCM dashboard uses global read scope like SYS, while Staff SCM remains office-scoped.
if ($isManagerSCMUser && !$isStaffSCMUser) {
    $scopeCtx['is_admin'] = true;
    $scopeCtx['office_code'] = '';
}

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
function scm_u($path) {
    $bp = $GLOBALS['BASE_PROJECT'] ?? '';
    return rtrim($bp, '/') . '/' . ltrim((string)$path, '/');
}
function scm_num(int $v): string { return number_format($v); }
function scm_delivery_svg(array $trend): string {
    if (!$trend) return '<div style="color:#64748b;padding:24px;text-align:center">Belum ada data delivery 30 hari terakhir.</div>';
    $w=760; $h=210; $padL=34; $padR=10; $padT=16; $padB=38;
    $vals=[]; foreach($trend as $r){ $vals[]=(int)($r['on']??0); $vals[]=(int)($r['del']??0); }
    $max=max(1, ...$vals); $n=count($trend); $plotW=$w-$padL-$padR; $plotH=$h-$padT-$padB; $group=$plotW/max(1,$n); $bar=max(2,min(8,$group*0.34));
    $svg='<svg viewBox="0 0 '.$w.' '.$h.'" width="100%" height="100%" role="img" aria-label="SCM Delivery Trend 30 Hari">';
    for($i=0;$i<=4;$i++){ $y=$padT+$plotH*($i/4); $v=(int)round($max*(1-$i/4)); $svg.='<line x1="'.$padL.'" y1="'.$y.'" x2="'.($w-$padR).'" y2="'.$y.'" stroke="rgba(148,163,184,.16)" stroke-width="1"/><text x="2" y="'.($y+4).'" fill="#64748b" font-size="9">'.$v.'</text>'; }
    foreach($trend as $i=>$r){
        $x=$padL+$group*$i+$group/2; $on=(int)($r['on']??0); $del=(int)($r['del']??0);
        $onH=$plotH*$on/$max; $delH=$plotH*$del/$max;
        $svg.='<rect x="'.($x-$bar-1).'" y="'.($padT+$plotH-$onH).'" width="'.$bar.'" height="'.$onH.'" rx="2" fill="#06b6d4"><title>'.h($r['d']).' ON DELIVERY: '.$on.'</title></rect>';
        $svg.='<rect x="'.($x+1).'" y="'.($padT+$plotH-$delH).'" width="'.$bar.'" height="'.$delH.'" rx="2" fill="#22c55e"><title>'.h($r['d']).' DELIVERED: '.$del.'</title></rect>';
        if($i%5===0 || $i===$n-1){ $lab=date('d/m',strtotime($r['d'])); $svg.='<text x="'.$x.'" y="'.($h-15).'" text-anchor="middle" fill="#64748b" font-size="9">'.$lab.'</text>'; }
    }
    $svg.='<circle cx="'.$padL.'" cy="'.($h-5).'" r="4" fill="#06b6d4"/><text x="'.($padL+8).'" y="'.($h-2).'" fill="#94a3b8" font-size="9">ON DELIVERY</text><circle cx="'.($padL+88).'" cy="'.($h-5).'" r="4" fill="#22c55e"/><text x="'.($padL+96).'" y="'.($h-2).'" fill="#94a3b8" font-size="9">DELIVERED</text></svg>';
    return $svg;
}

// ── Period ────────────────────────────────────────────────────────────────
$period = date('Y-m');
$mStart = $period . '-01';
$mEnd   = date('Y-m-t', strtotime($mStart));
$today  = date('Y-m-d');

function scm_t(PDO $pdo, string $t): bool {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch (Throwable $e) { return false; }
}

// ── Runtime schema helpers (read-only; no workflow mutation) ───────────────
function scm_cols(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $out = [];
    if (!scm_t($pdo, $table)) return $cache[$table] = $out;
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`") as $r) {
            $f = (string)($r['Field'] ?? '');
            if ($f !== '') $out[$f] = true;
        }
    } catch (Throwable $e) {}
    return $cache[$table] = $out;
}
function scm_pick(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) if (isset($cols[$c])) return $c;
    return null;
}
function scm_scalar(PDO $pdo, string $sql, array $params=[]): int {
    try { $st=$pdo->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
function scm_office_filter(array $cols, array $scopeCtx, string $alias=''): array {
    if (!empty($scopeCtx['is_admin'])) return ['', []];
    $office = strtoupper(trim((string)($scopeCtx['office_code'] ?? '')));
    if ($office === '') return ['', []];
    $col = scm_pick($cols, ['office_code','office','branch_code']);
    if (!$col) return ['', []];
    $pfx = $alias !== '' ? $alias . '.' : '';
    return [" AND UPPER(COALESCE({$pfx}`{$col}`,''))=?", [$office]];
}

// ── KPI ───────────────────────────────────────────────────────────────────
$kpi = [
    'do_waiting_scm'   => 0, // current SCM work = READY_SCM + ON_DELIVERY (+ legacy WQS_DONE)
    'do_on_delivery'   => 0,
    'scm_sla_overdue'  => 0,
    'tracking_offline' => 0,
    'return_active'    => 0,
    'pr_submitted'     => 0,
    'po_open'          => 0,
    'pib_count'        => 0,
    'forwarding_open'  => 0,
    'vendors_total'    => 0,
    'vendors_forward'  => 0,
    'vendors_logistic' => 0,
    'incoming_mtd'     => 0,
    'avg_sla_hours'     => 0.0,
    'avg_sla_count'     => 0,
];

// Procurement/import snapshot. These are operational stage totals, not a mathematical funnel.
$funnel = ['po' => 0, 'pib' => 0, 'gr' => 0, 'ap' => 0];
$recentSCMTasks = [];
$deliveryTrend = [];
$deliveryTrendSource = 'sales_do';

if ($pdo) {
    try {
        $salesCols = scm_cols($pdo, 'sales_do');
        [$salesOfficeSql, $salesOfficeParams] = scm_office_filter($salesCols, $scopeCtx);

        // StaffSCM_BDG = delivery office Bandung saja.
        // BMHP dan UNIT ACC sama-sama boleh bila DO milik BDG; jenis produk tidak membuka office lain.
        // Literal BDG juga mencegah salah konfigurasi office akun (mis. BGR) membocorkan task office lain.
        if ($isStaffSCMBDG) {
            $salesOfficeSql = " AND UPPER(COALESCE(sales_do.office_code,''))='BDG'";
            $salesOfficeParams = [];
        }

        // SINGLE SOURCE OF TRUTH dengan sales/scm_do_tasks.php:
        // ACTIVE SCM hanya READY_SCM + ON_DELIVERY. WQS_DONE adalah status legacy dan
        // tidak boleh menambah angka dashboard bila tidak tampil sebagai task aktif.
        $scmStatuses = ['ready_scm','on_delivery'];
        $inPh = implode(',', array_fill(0, count($scmStatuses), '?'));

        // Samakan juga pengecualian FULL RETURN yang sudah selesai SCM dengan scm_do_tasks.php.
        // PARTIAL return tetap aktif; hanya return kumulatif >= qty DO yang dikeluarkan.
        $scmReturnExcludeSql = '';
        try {
            $hasRetTbl     = scm_t($pdo, 'sales_do_returns');
            $hasRetItemTbl = scm_t($pdo, 'sales_do_return_items');
            $hasDoItemTbl  = scm_t($pdo, 'sales_do_items');
            if ($hasRetTbl && $hasRetItemTbl && $hasDoItemTbl) {
                $scmReturnExcludeSql = " AND NOT (
                    COALESCE((
                        SELECT SUM(ri.qty_return)
                        FROM sales_do_return_items ri
                        JOIN sales_do_returns rr ON rr.id=ri.return_id
                        WHERE rr.do_id=sales_do.id
                          AND LOWER(COALESCE(rr.status,'')) IN ('return_scm_completed','return_completed','completed','closed')
                    ),0) >= COALESCE((SELECT SUM(di.qty) FROM sales_do_items di WHERE di.do_id=sales_do.id),0)
                    AND COALESCE((SELECT SUM(di2.qty) FROM sales_do_items di2 WHERE di2.do_id=sales_do.id),0) > 0
                )";
            }
        } catch (Throwable $e) {
            $scmReturnExcludeSql = '';
        }

        $kpi['do_waiting_scm'] = scm_scalar(
            $pdo,
            "SELECT COUNT(*) FROM sales_do WHERE LOWER(COALESCE(status,'')) IN ({$inPh}){$scmReturnExcludeSql}{$salesOfficeSql}",
            array_merge($scmStatuses, $salesOfficeParams)
        );
        $kpi['do_on_delivery'] = scm_scalar(
            $pdo,
            "SELECT COUNT(*) FROM sales_do WHERE LOWER(COALESCE(status,''))='on_delivery'{$scmReturnExcludeSql}{$salesOfficeSql}",
            $salesOfficeParams
        );

        // SLA overdue: only active SCM stages. Prefer stage timestamps already stored on sales_do.
        // Default operational threshold is 1 day and does not change workflow/status.
        $slaHours = 24;
        $stageTimeCol = scm_pick($salesCols, ['scm_ready_at','ready_scm_at','wqs_done_at','updated_at','created_at']);
        if ($stageTimeCol) {
            $kpi['scm_sla_overdue'] = scm_scalar(
                $pdo,
                "SELECT COUNT(*) FROM sales_do WHERE LOWER(COALESCE(status,'')) IN ({$inPh}) " .
                "AND `{$stageTimeCol}` IS NOT NULL AND TIMESTAMPDIFF(HOUR, `{$stageTimeCol}`, NOW()) > ?{$scmReturnExcludeSql}{$salesOfficeSql}",
                array_merge($scmStatuses, [$slaHours], $salesOfficeParams)
            );
        }

        // Avg durasi SLA SCM: dari handoff WQS->SCM sampai delivered/scm_done.
        // Hanya task yang sudah selesai SCM yang dihitung; tidak terus berjalan ke ACT/FIN.
        $scmStartCol = scm_pick($salesCols, ['scm_ready_at','ready_scm_at','wqs_done_at','wqs_ready_at','updated_at','created_at']);
        $scmDoneCol  = scm_pick($salesCols, ['scm_delivered_at','delivered_at']);
        if ($scmStartCol && $scmDoneCol) {
            try {
                $sqlAvg = "SELECT AVG(TIMESTAMPDIFF(SECOND, `{$scmStartCol}`, `{$scmDoneCol}`))/3600 AS avg_h, COUNT(*) AS cnt " .
                          "FROM sales_do WHERE `{$scmStartCol}` IS NOT NULL AND `{$scmDoneCol}` IS NOT NULL " .
                          "AND `{$scmDoneCol}` >= `{$scmStartCol}`{$salesOfficeSql}";
                $stAvg = $pdo->prepare($sqlAvg);
                $stAvg->execute($salesOfficeParams);
                $avgRow = $stAvg->fetch(PDO::FETCH_ASSOC) ?: [];
                $kpi['avg_sla_hours'] = (float)($avgRow['avg_h'] ?? 0);
                $kpi['avg_sla_count'] = (int)($avgRow['cnt'] ?? 0);
            } catch (Throwable $e) {}
        }

        // Tracking offline/delay: only ON_DELIVERY and only when live GPS columns exist.
        if (isset($salesCols['scm_live_at'])) {
            $kpi['tracking_offline'] = scm_scalar(
                $pdo,
                "SELECT COUNT(*) FROM sales_do WHERE LOWER(COALESCE(status,''))='on_delivery' " .
                "AND (`scm_live_at` IS NULL OR TIMESTAMPDIFF(MINUTE,`scm_live_at`,NOW())>5){$scmReturnExcludeSql}{$salesOfficeSql}",
                $salesOfficeParams
            );
        }

        // Return queue, if the installed return table exposes a recognisable status/office.
        foreach (['sales_do_returns','sales_do_return','sales_returns'] as $rt) {
            if (!scm_t($pdo, $rt)) continue;
            $rc = scm_cols($pdo, $rt);
            $rs = scm_pick($rc, ['status','return_status','scm_status']);
            if (!$rs) continue;
            [$roSql,$roParams] = scm_office_filter($rc, $scopeCtx);
            $kpi['return_active'] = scm_scalar(
                $pdo,
                "SELECT COUNT(*) FROM `{$rt}` WHERE UPPER(COALESCE(`{$rs}`,'')) NOT IN ('DONE','CLOSED','CANCELLED','REJECTED','COMPLETED'){$roSql}",
                $roParams
            );
            break;
        }

        // Recent active SCM tasks.
        try {
            $st = $pdo->prepare("SELECT id, do_code, customers_code, status, office_code, " .
                "COALESCE(grand_total,total_amount,0) AS amount, do_date FROM sales_do " .
                "WHERE LOWER(COALESCE(status,'')) IN ({$inPh}){$scmReturnExcludeSql}{$salesOfficeSql} ORDER BY id DESC LIMIT 8");
            $st->execute(array_merge($scmStatuses, $salesOfficeParams));
            $recentSCMTasks = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        // PR Submitted = PR aktif yang sudah SUBMITTED dan belum soft-delete.
        // Selaras dengan aturan sumber wqs_pr pada Dashboard WQS:
        // record deleted_at IS NOT NULL adalah PR trial/salah yang sudah dihapus dan tidak boleh dihitung.
        if (scm_t($pdo, 'wqs_pr')) {
            $c=scm_cols($pdo,'wqs_pr'); [$oSql,$oParams]=scm_office_filter($c,$scopeCtx);
            $prNotDeleted = isset($c['deleted_at']) ? " AND deleted_at IS NULL" : "";
            $kpi['pr_submitted'] = scm_scalar(
                $pdo,
                "SELECT COUNT(*)
                 FROM wqs_pr
                 WHERE UPPER(TRIM(COALESCE(status,'')))='SUBMITTED'
                   {$prNotDeleted}{$oSql}",
                $oParams
            );
        }

        // PO Open = current procurement backlog.
        if (scm_t($pdo, 'purchases_po')) {
            $c=scm_cols($pdo,'purchases_po'); [$oSql,$oParams]=scm_office_filter($c,$scopeCtx);
            $deleted = isset($c['deleted_at']) ? " AND deleted_at IS NULL" : '';
            $kpi['po_open'] = scm_scalar($pdo,"SELECT COUNT(*) FROM purchases_po WHERE UPPER(COALESCE(status,'')) IN ('OPEN','IN_PRODUCTION','READY'){$deleted}{$oSql}",$oParams);
            $funnel['po'] = $kpi['po_open'];
        }

        // PIB snapshot. This remains all stored PIB unless the installed table has an explicit active status.
        if (scm_t($pdo, 'purchases_ceisa_pib')) {
            $c=scm_cols($pdo,'purchases_ceisa_pib'); [$oSql,$oParams]=scm_office_filter($c,$scopeCtx);
            $status=scm_pick($c,['status','pib_status','ceisa_status']);
            if ($status) {
                $kpi['pib_count']=scm_scalar($pdo,"SELECT COUNT(*) FROM purchases_ceisa_pib WHERE UPPER(COALESCE(`{$status}`,'')) NOT IN ('DONE','CLOSED','CANCELLED','COMPLETED'){$oSql}",$oParams);
            } else {
                $kpi['pib_count']=scm_scalar($pdo,"SELECT COUNT(*) FROM purchases_ceisa_pib WHERE 1=1{$oSql}",$oParams);
            }
            $funnel['pib']=$kpi['pib_count'];
        }

        // IMPORTANT: jangan memakai wqs_incoming sebagai Goods Receipt (GR).
        // WQS Incoming adalah proses penerimaan gudang, sedangkan GR adalah dokumen/proses Purchases.
        // Sampai sumber GR yang authoritative tersedia di dashboard ini, KPI/funnel GR sengaja tidak dihitung
        // agar dashboard tidak menampilkan angka yang salah atau menyesatkan.

        // AP snapshot.
        if (scm_t($pdo, 'purchases_invoice_ap')) {
            $c=scm_cols($pdo,'purchases_invoice_ap'); [$oSql,$oParams]=scm_office_filter($c,$scopeCtx);
            $deleted=isset($c['deleted_at'])?" AND deleted_at IS NULL":'';
            $funnel['ap']=scm_scalar($pdo,"SELECT COUNT(*) FROM purchases_invoice_ap WHERE 1=1{$deleted}{$oSql}",$oParams);
        }

        // Forwarding aktif: hitung PO yang benar-benar masih aktif dan sudah masuk proses forwarding.
        // Source utama purchases_po; quote SELECTED / forwarding docs hanya bukti bahwa proses forwarding sudah dimulai.
        if (scm_t($pdo,'purchases_po')) {
            $pc = scm_cols($pdo,'purchases_po');
            $fwdStatus = scm_pick($pc,['forwarder_status','forwarding_status']);
            $fwdVendor = scm_pick($pc,['forwarder_vendor_id','forwarding_vendor_id']);
            $poStatus  = scm_pick($pc,['status','po_status']);
            [$poOfficeSql,$poOfficeParams] = scm_office_filter($pc,$scopeCtx);
            $deletedSql = isset($pc['deleted_at']) ? " AND p.deleted_at IS NULL" : '';
            $poTerminalSql = $poStatus ? " AND UPPER(COALESCE(p.`{$poStatus}`,'')) NOT IN ('DONE','CLOSED','CANCELLED','COMPLETED','DELIVERED','PAID','VOID')" : '';
            $fwdTerminalSql = $fwdStatus ? " AND UPPER(COALESCE(p.`{$fwdStatus}`,'')) NOT IN ('DONE','CLOSED','CANCELLED','COMPLETED','DELIVERED')" : '';
            $evidence=[];
            if ($fwdVendor) $evidence[] = "p.`{$fwdVendor}` IS NOT NULL";
            if (scm_t($pdo,'purchases_forwarder_quotes')) {
                $qc=scm_cols($pdo,'purchases_forwarder_quotes');
                if(isset($qc['po_id']) && isset($qc['status'])){
                    $qDel=isset($qc['deleted_at'])?" AND q.deleted_at IS NULL":'';
                    $evidence[]="EXISTS (SELECT 1 FROM purchases_forwarder_quotes q WHERE q.po_id=p.id AND UPPER(COALESCE(q.status,''))='SELECTED'{$qDel})";
                }
            }
            if (scm_t($pdo,'purchases_forwarding_docs')) {
                $dc=scm_cols($pdo,'purchases_forwarding_docs');
                if(isset($dc['po_id'])){
                    $dDel=isset($dc['deleted_at'])?" AND fd.deleted_at IS NULL":'';
                    $evidence[]="EXISTS (SELECT 1 FROM purchases_forwarding_docs fd WHERE fd.po_id=p.id{$dDel})";
                }
            }
            if($evidence){
                $poOfficeSqlAliased = str_replace('`office_code`','p.`office_code`', $poOfficeSql);
                $poOfficeSqlAliased = str_replace('`office`','p.`office`', $poOfficeSqlAliased);
                $poOfficeSqlAliased = str_replace('`branch_code`','p.`branch_code`', $poOfficeSqlAliased);
                $kpi['forwarding_open']=scm_scalar($pdo,
                    "SELECT COUNT(DISTINCT p.id) FROM purchases_po p WHERE (".implode(' OR ',$evidence)."){$deletedSql}{$poTerminalSql}{$fwdTerminalSql}{$poOfficeSqlAliased}",
                    $poOfficeParams
                );
            }
        }

        // Fallback lama hanya dipakai bila kolom forwarder_status pada PO belum tersedia.
        if ($kpi['forwarding_open'] === 0 && (!scm_t($pdo,'purchases_po') || !isset(scm_cols($pdo,'purchases_po')['forwarder_status']))) {
            foreach (['purchases_forwarder_tasks','purchases_forwarding_tasks'] as $tbl) {
                if (!scm_t($pdo,$tbl)) continue;
                $c=scm_cols($pdo,$tbl); $status=scm_pick($c,['status','task_status','forwarding_status']);
                if (!$status) continue;
                [$oSql,$oParams]=scm_office_filter($c,$scopeCtx);
                $kpi['forwarding_open']=scm_scalar($pdo,"SELECT COUNT(*) FROM `{$tbl}` WHERE UPPER(COALESCE(`{$status}`,'')) NOT IN ('DONE','CLOSED','CANCELLED','COMPLETED'){$oSql}",$oParams);
                break;
            }
        }

        // Vendor master.
        if (scm_t($pdo,'master_vendors')) {
            $c=scm_cols($pdo,'master_vendors');
            $type=scm_pick($c,['vendor_type','type']);
            $kpi['vendors_total']=scm_scalar($pdo,'SELECT COUNT(*) FROM master_vendors');
            if ($type) {
                $kpi['vendors_forward']=scm_scalar($pdo,"SELECT COUNT(*) FROM master_vendors WHERE UPPER(COALESCE(`{$type}`,''))='FORWARDING'");
                $kpi['vendors_logistic']=scm_scalar($pdo,"SELECT COUNT(*) FROM master_vendors WHERE UPPER(COALESCE(`{$type}`,''))='LOGISTIC'");
            }
        }

        // Delivery Trend (30 hari): source valid adalah sales_do_audit.status_to + created_at.
        // Audit tidak mempunyai kolom action; office diambil lewat JOIN sales_do.do_id agar scope branch tetap benar.
        $dtFrom = date('Y-m-d', strtotime('-29 days'));
        $onMap=[]; $delMap=[];
        if (scm_t($pdo,'sales_do_audit')) {
            $ac=scm_cols($pdo,'sales_do_audit');
            $eventCol=scm_pick($ac,['status_to','to_status','new_status','status']);
            $dateCol=scm_pick($ac,['created_at','event_at','logged_at','updated_at']);
            $doIdCol=scm_pick($ac,['do_id','sales_do_id']);
            if ($eventCol && $dateCol && $doIdCol) {
                [$aoSql,$aoParams]=scm_office_filter($salesCols,$scopeCtx,'d');
                try {
                    $st=$pdo->prepare("SELECT DATE(a.`{$dateCol}`) d, LOWER(COALESCE(a.`{$eventCol}`,'')) ev, COUNT(*) cnt " .
                        "FROM sales_do_audit a JOIN sales_do d ON d.id=a.`{$doIdCol}` " .
                        "WHERE a.`{$dateCol}`>=? AND LOWER(COALESCE(a.`{$eventCol}`,'')) IN ('on_delivery','delivered'){$aoSql} " .
                        "GROUP BY DATE(a.`{$dateCol}`), LOWER(COALESCE(a.`{$eventCol}`,''))");
                    $st->execute(array_merge([$dtFrom.' 00:00:00'],$aoParams));
                    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
                        $d=(string)$r['d']; $ev=(string)$r['ev']; $cnt=(int)$r['cnt'];
                        if ($ev==='on_delivery') $onMap[$d]=($onMap[$d]??0)+$cnt;
                        elseif ($ev==='delivered') $delMap[$d]=($delMap[$d]??0)+$cnt;
                    }
                    if ($onMap || $delMap) $deliveryTrendSource='sales_do_audit.status_to';
                } catch(Throwable $e) {}
            }
        }
        if (!$onMap && !$delMap) {
            $onCol=scm_pick($salesCols,['scm_on_delivery_at','on_delivery_at']);
            $delCol=scm_pick($salesCols,['scm_delivered_at','delivered_at']);
            if ($onCol) {
                try {$st=$pdo->prepare("SELECT DATE(`{$onCol}`) d,COUNT(*) cnt FROM sales_do WHERE `{$onCol}`>=?{$salesOfficeSql} GROUP BY DATE(`{$onCol}`)");$st->execute(array_merge([$dtFrom.' 00:00:00'],$salesOfficeParams));foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$onMap[$r['d']]=(int)$r['cnt'];} catch(Throwable $e){}
            }
            if ($delCol) {
                try {$st=$pdo->prepare("SELECT DATE(`{$delCol}`) d,COUNT(*) cnt FROM sales_do WHERE `{$delCol}`>=?{$salesOfficeSql} GROUP BY DATE(`{$delCol}`)");$st->execute(array_merge([$dtFrom.' 00:00:00'],$salesOfficeParams));foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$delMap[$r['d']]=(int)$r['cnt'];} catch(Throwable $e){}
            }
        }
        for($ts=strtotime($dtFrom);$ts<=strtotime($today);$ts+=86400){$d=date('Y-m-d',$ts);$deliveryTrend[]=['d'=>$d,'on'=>(int)($onMap[$d]??0),'del'=>(int)($delMap[$d]??0)];}
    } catch (Throwable $e) {}
}

// Exceptions = operational problems requiring SCM attention.
$scmExceptions = (int)$kpi['scm_sla_overdue'] + (int)$kpi['tracking_offline'] + (int)$kpi['return_active'];

// ── Chart data ────────────────────────────────────────────────────────────
$chartLabels    = json_encode(array_column($deliveryTrend,'d'));
$chartOn        = json_encode(array_column($deliveryTrend,'on'));
$chartDelivered = json_encode(array_column($deliveryTrend,'del'));
$base = function_exists('rmi_assets_base') ? rmi_assets_base() : '';
$chartJs = function_exists('rmi_assets_foot') ? rmi_assets_foot(['chartjs'=>true,'jquery'=>false,'bootstrap'=>true,'datatables'=>false]) : '';

// ── Layout ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../_shared/rmi_layout.php';

rmi_header('SCM Dashboard', [
    'active'     => 'dashboard',
    'subtitle'   => 'Supply Chain — Delivery, Import, Forwarding, Procurement',
    'extra_head' => '<style>
body{background:#0b1220;color:#e8ecf4}
.scm-wrap{max-width:1160px;margin:0 auto}
.scm-hdr{background:linear-gradient(135deg,rgba(7,89,133,.75),rgba(2,132,199,.55));border:1px solid rgba(6,182,212,.3);border-radius:16px;padding:18px 22px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.scm-hdr h2{margin:0;font-size:19px;font-weight:800;color:#fff}
.scm-hdr p{margin:3px 0 0;font-size:12px;color:rgba(255,255,255,.65)}
.scm-kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:10px;margin-bottom:14px}
.scm-k{padding:13px;border-radius:12px;background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.09);border-top:3px solid var(--kc,#64748b);text-decoration:none;color:inherit;display:block;transition:.2s}
.scm-k:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.3)}
.scm-k-icon{font-size:18px;margin-bottom:5px}
.scm-k-val{font-size:22px;font-weight:800;color:#fff;line-height:1;font-variant-numeric:tabular-nums}
.scm-k-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-top:3px}
.scm-k-sub{font-size:10px;color:var(--rmi-muted);margin-top:2px}
.scm-sh{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--rmi-muted);margin:14px 0 8px;display:flex;align-items:center;gap:8px}
.scm-sh::after{content:"";flex:1;height:1px;background:rgba(255,255,255,.08)}
.scm-tbl{width:100%;border-collapse:collapse;font-size:12px}
.scm-tbl th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--rmi-muted);padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;white-space:nowrap}
.scm-tbl td{padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle}
.scm-tbl tr:hover td{background:rgba(255,255,255,.02)}
.scm-tbl tr:last-child td{border-bottom:none}
.scm-links{display:flex;flex-wrap:wrap;gap:6px}
.scm-link{padding:7px 13px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid rgba(255,255,255,.12);color:#e2e8f0;background:rgba(255,255,255,.05);transition:.15s;display:inline-flex;align-items:center;gap:5px}
.scm-link:hover{background:rgba(255,255,255,.12);color:#fff}
.scm-link.primary{background:linear-gradient(135deg,#0891b2,#0e7490);border-color:transparent;color:#fff}
.funnel-step{flex:1;text-align:center;padding:10px 6px;background:rgba(255,255,255,.04);border-radius:10px;border:1px solid rgba(255,255,255,.08)}
.funnel-arrow{color:rgba(6,182,212,.3);font-size:18px;flex-shrink:0;align-self:center}
/* Fix click layer: keep global header/menu above dashboard cards */
.rmi-topbar,.rmi-navbar,.topbar,.navbar,.rmi-header,.app-header,.layout-header{position:relative;z-index:99999;pointer-events:auto}
.scm-wrap,.scm-hdr,.scm-k,.rmi-card{position:relative;z-index:1}
</style>',
    'actions' => [
        ['label' => rmi_icon('books').' Panduan', 'url' => scm_u('/dashboards/scm/panduan.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>
<div class="scm-wrap">

  <!-- Header -->
  <div class="scm-hdr">
    <div>
      <h2><?=rmi_icon('box')?> <?= $isDeliveryOnlySCM ? 'SCM Delivery Dashboard' : 'SCM Dashboard' ?></h2>
      <p><?= $isDeliveryOnlySCM ? 'Pengiriman Bandung — BMHP + Unit ACC — Delivery Only' : 'Supply Chain — Delivery, Import, Forwarding, Procurement' ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="scm-link" href="<?= h(scm_u('/dashboards/index.php')) ?>"><?=rmi_icon('home')?> Home</a>
      <?php if (!$isDeliveryOnlySCM): ?><a class="scm-link primary" href="<?= h(scm_u('/purchases/purchases_import_control_tower.php')) ?>"><?=rmi_icon('tower')?> Import Tower</a><?php endif; ?>
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($kpi['do_waiting_scm'] > 0): ?>
  <div style="background:rgba(6,182,212,.1);border:1px solid rgba(6,182,212,.3);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:13px">
    <span style="font-size:18px"><?=rmi_icon('box')?></span>
    <span><strong style="color:#67e8f9"><?= $kpi['do_waiting_scm'] ?> DO menunggu tindakan SCM</strong>
    <?php if ($kpi['do_on_delivery'] > 0): ?>
      <span style="color:#94a3b8"> — <?= $kpi['do_on_delivery'] ?> sedang on-delivery</span>
    <?php endif; ?>
    </span>
    <a href="<?= h(scm_u('/sales/scm_do_tasks.php')) ?>" style="margin-left:auto;color:#67e8f9;font-size:11px;text-decoration:none">Buka Tasks →</a>
  </div>
  <?php endif; ?>

  <!-- KPI Tiles -->
  <div class="scm-kpi">

    <!-- KRITIS: DO Waiting SCM -->
    <a class="scm-k" href="<?= h(scm_u('/sales/scm_do_tasks.php')) ?>" style="--kc:<?= $kpi['do_waiting_scm']>0?'#06b6d4':'#64748b' ?>">
      <div class="scm-k-icon"><?=rmi_icon('box')?></div>
      <div class="scm-k-val" style="color:<?= $kpi['do_waiting_scm']>0?'#67e8f9':'#fff' ?>"><?= scm_num($kpi['do_waiting_scm']) ?></div>
      <div class="scm-k-lbl">DO Waiting SCM</div>
      <div class="scm-k-sub"><?= $kpi['do_waiting_scm']>0?rmi_icon('zap').' Perlu tindakan':rmi_icon('tick').' Clear' ?></div>
    </a>

    <!-- On Delivery -->
    <a class="scm-k" href="<?= h(scm_u('/sales/scm_do_tasks.php')) ?>" style="--kc:#22c55e">
      <div class="scm-k-icon"><?=rmi_icon('box')?></div>
      <div class="scm-k-val" style="color:<?= $kpi['do_on_delivery']>0?'#4ade80':'#fff' ?>"><?= scm_num($kpi['do_on_delivery']) ?></div>
      <div class="scm-k-lbl">On Delivery</div>
      <div class="scm-k-sub">Sedang dalam pengiriman</div>
    </a>

    <?php if (!$isDeliveryOnlySCM): ?>
<!-- Forwarding -->
    <a class="scm-k" href="<?= h(scm_u('/purchases/purchases_forwarding_tasks.php')) ?>" style="--kc:<?= $kpi['forwarding_open']>0?'#f97316':'#64748b' ?>">
      <div class="scm-k-icon"><?=rmi_icon('box')?></div>
      <div class="scm-k-val" style="color:<?= $kpi['forwarding_open']>0?'#fb923c':'#fff' ?>"><?= scm_num($kpi['forwarding_open']) ?></div>
      <div class="scm-k-lbl">Forwarding Aktif</div>
      <div class="scm-k-sub"><?= $kpi['forwarding_open']>0?'Dalam proses':'—' ?></div>
    </a>
<?php endif; ?>

    <!-- Avg SLA SCM -->
    <div class="scm-k" style="--kc:#a855f7">
      <div class="scm-k-icon"><?=rmi_icon('calendar')?></div>
      <div class="scm-k-val" style="color:#c084fc"><?= number_format((float)$kpi['avg_sla_hours'],1,',','.') ?> jam</div>
      <div class="scm-k-lbl">Avg Durasi SLA SCM</div>
      <div class="scm-k-sub"><?= scm_num((int)$kpi['avg_sla_count']) ?> DO selesai SCM</div>
    </div>

    <?php if (!$isDeliveryOnlySCM): ?>
    <!-- Vendors -->
    <a class="scm-k" href="<?= h(scm_u('/master/master_vendors.php')) ?>" style="--kc:#06b6d4">
      <div class="scm-k-icon"><?=rmi_icon('users')?></div>
      <div class="scm-k-val"><?= scm_num($kpi['vendors_total']) ?></div>
      <div class="scm-k-lbl">Total Vendor</div>
      <div class="scm-k-sub">Fwd: <?= $kpi['vendors_forward'] ?> · Log: <?= $kpi['vendors_logistic'] ?></div>
    </a>
    <?php endif; ?>
    </div>

  <!-- Import Pipeline Funnel + Delivery Trend -->
  <div class="row g-3 mb-3">

    <!-- Import Pipeline Funnel -->
    <?php if (!$isDeliveryOnlySCM): ?>
    <div class="col-lg-5">
      <div class="rmi-card p-3">
        <div class="scm-sh" style="margin-top:0"><?=rmi_icon('box')?> Procurement / Import Snapshot</div>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
          <?php
          $funnelItems = [
              ['icon'=>rmi_icon('cart'),'label'=>'PO','count'=>$funnel['po'],'color'=>'#3b82f6'],
              ['icon'=>rmi_icon('clipboard'),'label'=>'PIB','count'=>$funnel['pib'],'color'=>'#8b5cf6'],
              ['icon'=>rmi_icon('money'),'label'=>'AP','count'=>$funnel['ap'],'color'=>'#f59e0b'],
          ];
          $maxFunnel = max(1, ...array_column($funnelItems, 'count'));
          foreach ($funnelItems as $i => $fi):
          ?>
            <?php if ($i > 0): ?><div class="funnel-arrow">→</div><?php endif; ?>
            <div class="funnel-step">
              <div style="font-size:16px;margin-bottom:4px"><?= $fi['icon'] ?></div>
              <div style="font-size:20px;font-weight:800;color:<?= $fi['color'] ?>"><?= scm_num($fi['count']) ?></div>
              <div style="font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.04em"><?= $fi['label'] ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="font-size:11px;color:var(--rmi-muted);line-height:1.6">
          Alur: PO → PIB (jika impor) → GR → AP. Angka di atas adalah snapshot tiap tahap yang tersedia dan tidak dikurangkan satu sama lain kecuali relasi PO sudah tervalidasi.

        </div>

        <!-- Vendor breakdown -->
        <?php if ($kpi['vendors_total'] > 0): ?>
        <div style="margin-top:12px;border-top:1px solid rgba(255,255,255,.08);padding-top:10px">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--rmi-muted);margin-bottom:8px">Tipe Vendor</div>
          <?php
          $vendorData = [
              ['label'=>'Forwarding', 'cnt'=>$kpi['vendors_forward'],  'color'=>'#06b6d4'],
              ['label'=>'Logistic',   'cnt'=>$kpi['vendors_logistic'], 'color'=>'#3b82f6'],
              ['label'=>'Lainnya',    'cnt'=>max(0,$kpi['vendors_total']-$kpi['vendors_forward']-$kpi['vendors_logistic']), 'color'=>'#64748b'],
          ];
          foreach ($vendorData as $v):
            if ($v['cnt'] <= 0) continue;
            $pct = $kpi['vendors_total'] > 0 ? round($v['cnt']/$kpi['vendors_total']*100) : 0;
          ?>
            <div style="margin-bottom:7px">
              <div style="display:flex;justify-content:space-between;margin-bottom:2px">
                <span style="font-size:11px;color:#e2e8f0"><?= $v['label'] ?></span>
                <span style="font-size:11px;color:<?= $v['color'] ?>;font-weight:700"><?= $v['cnt'] ?> (<?= $pct ?>%)</span>
              </div>
              <div style="height:4px;background:rgba(255,255,255,.07);border-radius:2px;overflow:hidden">
                <div style="height:4px;width:<?= $pct ?>%;background:<?= $v['color'] ?>;border-radius:2px"></div>
        </div>
      </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php endif; ?>

    <!-- Delivery Trend Chart -->
    <div class="<?= $isDeliveryOnlySCM ? 'col-12' : 'col-lg-7' ?>">
      <div class="rmi-card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="scm-sh" style="margin:0"><?=rmi_icon('trend')?> SCM Delivery Trend (30 Hari)</div>
          <span style="font-size:11px;color:var(--rmi-muted)"><?= h(date('Y-m-d', strtotime('-29 days'))) ?> → <?= h(date('Y-m-d')) ?></span>
        </div>
        <div style="height:220px;overflow:hidden"><?= scm_delivery_svg($deliveryTrend) ?></div>
      </div>
    </div>
  </div>

  <!-- Recent SCM DO Tasks -->
  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="rmi-card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="scm-sh" style="margin:0"><?=rmi_icon('box')?> Task DO SCM Aktif</div>
          <a href="<?= h(scm_u('/sales/scm_do_tasks.php')) ?>" style="font-size:11px;color:#67e8f9;text-decoration:none">Buka semua →</a>
        </div>
        <?php if (empty($recentSCMTasks)): ?>
          <div style="color:var(--rmi-muted);font-size:13px;padding:16px 0;text-align:center"><?=rmi_icon('check')?> Tidak ada DO menunggu tindakan SCM.</div>
        <?php else: ?>
          <div class="table-responsive">
          <table class="scm-tbl">
            <thead>
              <tr><th>DO Code</th><th>Customer</th><th>Status</th><th>Office</th><th>DO Date</th><th style="text-align:right">Amount</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentSCMTasks as $r):
              $statusColor = [
                  'ready_scm'  => '#22c55e',
                  'wqs_done'   => '#4ade80',
                  'on_delivery'=> '#06b6d4',
              ][$r['status']??''] ?? '#94a3b8';
            ?>
              <tr>
                <td><a href="<?= h(scm_u('/sales/sales_do_view.php?id='.(int)$r['id'])) ?>" style="color:#60a5fa;font-weight:600;font-size:12px"><?= h($r['do_code']??'—') ?></a></td>
                <td style="color:#94a3b8;font-size:11px"><?= h(mb_strimwidth((string)($r['customers_code']??''),0,14,'…')) ?></td>
                <td><span style="font-size:10px;font-weight:700;color:<?= $statusColor ?>;background:rgba(255,255,255,.06);padding:2px 7px;border-radius:6px;white-space:nowrap"><?= h($r['status']??'') ?></span></td>
                <td><span style="background:rgba(6,182,212,.15);color:#67e8f9;padding:1px 6px;border-radius:5px;font-size:11px;font-weight:700"><?= h(strtoupper($r['office_code']??'')) ?></span></td>
                <td style="font-size:11px;color:#64748b;white-space:nowrap"><?= h($r['do_date']??'') ?></td>
                <td style="text-align:right;font-size:11px;font-weight:600;color:#67e8f9">
                  <?php $amt = (float)($r['amount']??0); echo $amt>0 ? 'Rp '.number_format($amt/1e6,1,',','.').'jt' : '—'; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Quick Links -->
  <div class="rmi-card p-3">
    <div class="scm-sh" style="margin-top:0"><?=rmi_icon('zap')?> Quick Links</div>
    <div class="scm-links">
      <a class="scm-link primary" href="<?= h(scm_u('/sales/scm_do_tasks.php')) ?>"><?=rmi_icon('box')?> SCM Task DO</a>
      <?php if (!$isDeliveryOnlySCM): ?>
      <a class="scm-link primary" href="<?= h(scm_u('/purchases/purchases_import_control_tower.php')) ?>"><?=rmi_icon('tower')?> Import Tower</a>
      <a class="scm-link primary" href="<?= h(scm_u('/purchases/purchases_forwarding_tasks.php')) ?>"><?=rmi_icon('box')?> Forwarding Tasks</a>
      <a class="scm-link" href="<?= h(scm_u('/purchases/purchases_po.php')) ?>"><?=rmi_icon('cart')?> Purchase Order</a>
      <a class="scm-link" href="<?= h(scm_u('/purchases/purchases_ceisa_pib.php')) ?>"><?=rmi_icon('clipboard')?> PIB / CEISA</a>
      <a class="scm-link" href="<?= h(scm_u('/purchases/purchases_gr.php')) ?>"><?=rmi_icon('check')?> Good Receipt</a>
      <a class="scm-link" href="<?= h(scm_u('/stock/wqs_pr.php')) ?>"><?=rmi_icon('memo')?> Purchase Request</a>
      <a class="scm-link" href="<?= h(scm_u('/stock/wqs_incoming.php')) ?>"><?=rmi_icon('inbox')?> WQS Incoming</a>
      <?php if ($canOpenManagerSCM): ?><a class="scm-link" href="<?= h(scm_u('/sales/sales_control_tower.php')) ?>"><?=rmi_icon('tower')?> Sales Control Tower</a><?php endif; ?>
      <a class="scm-link" href="<?= h(scm_u('/master/master_vendors.php')) ?>"><?=rmi_icon('users')?> Master Vendor</a>
      <a class="scm-link" href="<?= h(scm_u('/master/master_manufactures.php')) ?>"><?=rmi_icon('office')?> Master Manufacturer</a>
      <?php if ($canOpenManagerSCM): ?><a class="scm-link" href="<?= h(scm_u('/kpi/kpi_center.php')) ?>"><?=rmi_icon('trend')?> KPI Center</a><?php endif; ?>
      <?php endif; ?>
      <a class="scm-link" href="<?= h(scm_u('/absensi/index.php')) ?>"><?=rmi_icon('calendar')?> Absensi</a>
      <a class="scm-link primary" href="<?= h(scm_u('/dashboards/scm/panduan.php')) ?>" style="border-color:rgba(139,92,246,.5);color:#c4b5fd"><?=rmi_icon('books')?> Panduan SCM</a>
    </div>
  </div>

  <?php
  /*
   * Audit Log SCM — READ ONLY.
   * purchases_audit_log adalah sumber audit procurement/import yang benar
   * (schema: module, ref_code, action, details_json, user_name, user_role,
   * user_level, created_at). Widget generik sebelumnya tidak membaca sumber/
   * nama kolom ini dengan tepat sehingga dashboard terlihat kosong.
   *
   * Untuk SCM dashboard, tampilkan event supply-chain yang relevan:
   * PO/PR, forwarding/import/CEISA/PIB, GR/AP, ditambah SCM bila memang
   * sudah ada writer SCM ke purchases_audit_log.
   */
  $scmAuditRows = [];
  try {
      if ($pdo && scm_t($pdo, 'purchases_audit_log')) {
          $auditModuleRx = $isDeliveryOnlySCM
              ? '^(SCM)$'
              : '^(SCM|PURCHASE|PURCHASES|PR|PR_WQS|RFQ|PO|FORWARDING|FWD_QUOTE|GR|AP|PAY|FAP|FAP_PAY|IMPORT_DOC|IMPORT_CTRL|CEISA|CEISA_DOC|PIB_PAY)$';

          $stAudit = $pdo->prepare(
              "SELECT id, module, ref_code, action, details_json,
                      user_name, user_role, user_level, created_at
               FROM purchases_audit_log
               WHERE UPPER(TRIM(COALESCE(module,''))) REGEXP ?
               ORDER BY created_at DESC, id DESC
               LIMIT 10"
          );
          $stAudit->execute([$auditModuleRx]);
          $scmAuditRows = $stAudit->fetchAll(PDO::FETCH_ASSOC) ?: [];
      }
  } catch (Throwable $e) {
      $scmAuditRows = [];
  }

  if (!function_exists('scm_audit_detail')) {
      function scm_audit_detail($raw): string {
          $raw = trim((string)$raw);
          if ($raw === '') return '';
          $j = json_decode($raw, true);
          if (!is_array($j)) return $raw;
          foreach (['description','detail','message','note','notes'] as $k) {
              if (isset($j[$k]) && !is_array($j[$k]) && trim((string)$j[$k]) !== '') {
                  return trim((string)$j[$k]);
              }
          }
          $parts = [];
          foreach ($j as $k => $v) {
              if (is_scalar($v) && $v !== '' && $v !== null) $parts[] = $k.'='.$v;
              if (count($parts) >= 4) break;
          }
          return implode(' · ', $parts);
      }
  }
  ?>

  <section class="rmi-card p-3 mb-3" style="margin-top:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px">
      <div class="fw-semibold">
        <?=rmi_icon('clipboard')?> Audit Log Terbaru
        <span style="font-size:12px;font-weight:400;opacity:.55;margin-left:8px">
          <?= $isDeliveryOnlySCM ? 'SCM' : 'SCM, PURCHASES, PO, PR, FORWARDING, IMPORT, CEISA, GR, AP' ?>
        </span>
      </div>
      <a href="<?= h(scm_u('/master/audit_logs.php')) ?>" style="font-size:11px;color:#60a5fa;text-decoration:none">Lihat semua →</a>
    </div>

    <?php if ($scmAuditRows): ?>
      <div style="display:grid;gap:8px">
        <?php foreach ($scmAuditRows as $log):
          $action = strtoupper(trim((string)($log['action'] ?? 'LOG')));
          $module = trim((string)($log['module'] ?? ''));
          $ref    = trim((string)($log['ref_code'] ?? ''));
          $user   = trim((string)($log['user_name'] ?? ''));
          $role   = trim((string)($log['user_role'] ?? ''));
          $level  = trim((string)($log['user_level'] ?? ''));
          $time   = trim((string)($log['created_at'] ?? ''));
          $detail = scm_audit_detail($log['details_json'] ?? '');
        ?>
          <div style="border:1px solid rgba(148,163,184,.16);background:rgba(255,255,255,.035);border-radius:10px;padding:11px 13px;display:flex;justify-content:space-between;gap:16px;align-items:flex-start">
            <div style="min-width:0">
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <span style="font-weight:800;color:#34d399"><?= h($action ?: 'LOG') ?></span>
                <?php if ($module !== ''): ?><span style="opacity:.58;font-size:12px"><?= h($module) ?></span><?php endif; ?>
                <?php if ($ref !== ''): ?><span style="font-family:monospace"><?= h($ref) ?></span><?php endif; ?>
              </div>
              <?php if ($detail !== ''): ?><div style="margin-top:6px;opacity:.82;font-size:12px"><?= h($detail) ?></div><?php endif; ?>
            </div>
            <div style="text-align:right;white-space:nowrap;font-size:11px;opacity:.7">
              <?php if ($user !== ''): ?><div><?= h($user) ?></div><?php endif; ?>
              <?php if ($role !== '' || $level !== ''): ?>
                <div style="opacity:.75"><?= h(trim($role . ($role !== '' && $level !== '' ? ' · ' : '') . $level)) ?></div>
              <?php endif; ?>
              <?php if ($time !== ''): ?><div><?= h($time) ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div style="padding:16px;border:1px dashed rgba(148,163,184,.25);border-radius:10px;opacity:.65">
        Belum ada Audit Log SCM yang sesuai pada purchases_audit_log.
      </div>
    <?php endif; ?>
  </section>

</div>

<?php echo $chartJs; ?>
<script>
(function(){
  function txt(el){ return (el && (el.textContent || el.innerText) || '').replace(/\s+/g,' ').trim().toLowerCase(); }
  function isMenuBtn(el){
    var t = txt(el);
    return t === 'menu' || t.indexOf('☰ menu') !== -1 || t.indexOf('≡ menu') !== -1 || (el.getAttribute && /menu/i.test(el.getAttribute('aria-label') || ''));
  }
  function visible(el){ return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length)); }
  function toggleNearestMenu(btn){
    var root = btn.closest('header,.rmi-header,.rmi-topbar,.topbar,.navbar,body') || document.body;
    var candidates = [].slice.call(root.querySelectorAll('.dropdown-menu,.menu-dropdown,.rmi-menu-panel,.sidebar,.offcanvas,.app-menu,.nav-menu'));
    candidates = candidates.filter(function(x){ return x !== btn && !btn.contains(x); });
    if (candidates.length) {
      var m = candidates[0];
      m.classList.toggle('show');
      m.style.display = m.classList.contains('show') ? 'block' : '';
      m.style.visibility = 'visible';
      m.style.zIndex = 100000;
      return true;
    }
    return false;
  }
  document.addEventListener('DOMContentLoaded', function(){
    var headerEls = document.querySelectorAll('.rmi-topbar,.rmi-navbar,.topbar,.navbar,.rmi-header,.app-header,.layout-header');
    headerEls.forEach(function(el){ el.style.zIndex='99999'; el.style.position=el.style.position || 'relative'; el.style.pointerEvents='auto'; });

    var menuBtns = [].slice.call(document.querySelectorAll('button,a,.btn,[role="button"]')).filter(isMenuBtn).filter(visible);
    menuBtns.forEach(function(btn){
      btn.style.position = btn.style.position || 'relative';
      btn.style.zIndex = 100000;
      btn.style.pointerEvents = 'auto';
      btn.addEventListener('click', function(ev){
        setTimeout(function(){
          var opened = document.querySelector('.dropdown-menu.show,.offcanvas.show,.sidebar.show,.menu-dropdown.show,.rmi-menu-panel.show');
          if (!opened) toggleNearestMenu(btn);
        }, 60);
      }, true);
    });
  });
})();
</script>
<?php if (function_exists('rmi_footer')) { rmi_footer(); } ?>
