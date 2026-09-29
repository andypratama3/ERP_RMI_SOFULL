<?php
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/_do_office_scope.php';
require_once __DIR__ . '/../stock/_stock_office_helper.php';
require_login();

$u = function_exists('auth_user') ? auth_user() : [];
$role = strtoupper(trim((string)($u['role'] ?? '')));
$dept = strtoupper(trim((string)($u['department'] ?? $u['level'] ?? '')));
$office = strtoupper(trim((string)($u['office_code'] ?? '')));
$isAdmin = in_array($role, ['SYS','SUPERADMIN','ADMIN'], true);
$isScm = ($role === 'SCM' || $dept === 'SCM');
$isBranch = ($role === 'BRANCH' || $dept === 'BRANCH');

if (function_exists('require_any_permission')) {
    if (!$isAdmin && !$isScm && !$isBranch) {
        require_any_permission(['SALES.VIEW','SALES.EDIT']);
    }
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','SCM','BRANCH']);
}

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
$GLOBALS['__scm_recap_schema_columns'] = [];

function recap_table_columns(PDO $pdo, string $table): array {
    $key = spl_object_id($pdo) . ':' . $table;
    if (isset($GLOBALS['__scm_recap_schema_columns'][$key])) {
        return $GLOBALS['__scm_recap_schema_columns'][$key];
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return [];

    try {
        // Jangan gunakan SHOW COLUMNS ... LIKE ? karena pada sebagian driver/server
        // metadata statement tersebut tidak menerima placeholder dengan konsisten.
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $cols = [];
        foreach ($rows as $row) {
            $name = (string)($row['Field'] ?? '');
            if ($name !== '') $cols[$name] = true;
        }
        return $GLOBALS['__scm_recap_schema_columns'][$key] = $cols;
    } catch (Throwable $e) {
        error_log('[SCM_DELIVERY_RECAP][SCHEMA] '.$table.' '.$e->getMessage());
        return $GLOBALS['__scm_recap_schema_columns'][$key] = [];
    }
}

function recap_has_col(PDO $pdo, string $table, string $col): bool {
    $cols = recap_table_columns($pdo, $table);
    return isset($cols[$col]);
}
function recap_sel(bool $has, string $col): string {
    return $has ? "d.`$col`" : "NULL AS `$col`";
}
function recap_status_badge(string $s): string {
    $s = strtolower(trim($s));
    $label = strtoupper($s ?: '-');
    $cls = 'gray';
    if (in_array($s, ['delivered','scm_done'], true)) $cls = 'blue';
    elseif (in_array($s, ['act_done','wait_payment','wait_tax_invoice'], true)) $cls = 'yellow';
    elseif (in_array($s, ['paid','paid_done','closed','fin_done'], true)) $cls = 'green';
    return '<span class="badge '.$cls.'">'.h($label).'</span>';
}

$hasDeliveredAt = recap_has_col($pdo, 'sales_do', 'scm_delivered_at');
$hasDeliveredBy = recap_has_col($pdo, 'sales_do', 'scm_delivered_by');
$hasOnDeliveryAt = recap_has_col($pdo, 'sales_do', 'scm_on_delivery_at');
$hasDeliveryMode = recap_has_col($pdo, 'sales_do', 'delivery_mode');
$hasDeliveryVendorId = recap_has_col($pdo, 'sales_do', 'delivery_vendor_id');
$hasReceivePhoto = recap_has_col($pdo, 'sales_do', 'scm_receive_photo');
$hasReceiveVideo = recap_has_col($pdo, 'sales_do', 'scm_receive_video');
$hasDeliveryPhoto = recap_has_col($pdo, 'sales_do', 'scm_delivery_photo');
$hasDeliveryVideo = recap_has_col($pdo, 'sales_do', 'scm_delivery_video');
$hasSignature = recap_has_col($pdo, 'sales_do', 'scm_signature_data');
$hasLiveAt = recap_has_col($pdo, 'sales_do', 'scm_live_at');
$hasLastUpdatedAt = recap_has_col($pdo, 'sales_do', 'last_updated_at');

$q = trim((string)($_GET['q'] ?? ''));
$officeF = trim((string)($_GET['office'] ?? ''));
$modeF = strtoupper(trim((string)($_GET['mode'] ?? '')));
$dateFr = trim((string)($_GET['date_fr'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$podF = trim((string)($_GET['pod'] ?? ''));

$scope = do_scope_where('d');
if ($isBranch && $office !== '') {
    $p = [];
    $scope = ['sql' => ' AND ' . rmi_office_in_sql($pdo, 'd.office_code', $office, $p), 'params' => $p];
}

// Effective SCM delivered event.
// Prefer sales_do.scm_delivered_at. For legacy rows, fall back to SCM_DELIVERED audit.
$deliveredExpr = $hasDeliveredAt
    ? "COALESCE(d.scm_delivered_at, aud.scm_delivered_audit_at)"
    : "aud.scm_delivered_audit_at";

$where = " WHERE (" . $deliveredExpr . " IS NOT NULL OR LOWER(COALESCE(d.status,''))='delivered') ";
$params = $scope['params'];
$where .= $scope['sql'];
if ($q !== '') {
    $like = '%'.$q.'%';
    $where .= ' AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR c.customers_name LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($officeF !== '') { $where .= ' AND d.office_code = ?'; $params[] = $officeF; }
if ($modeF !== '' && in_array($modeF, ['INTERNAL','VENDOR'], true) && $hasDeliveryMode) {
    $where .= ' AND UPPER(COALESCE(d.delivery_mode,\'INTERNAL\')) = ?'; $params[] = $modeF;
}
if ($dateFr !== '') {
    $where .= ' AND DATE(COALESCE(' . $deliveredExpr . ', d.do_date)) >= ?';
    $params[] = $dateFr;
}
if ($dateTo !== '') {
    $where .= ' AND DATE(COALESCE(' . $deliveredExpr . ', d.do_date)) <= ?';
    $params[] = $dateTo;
}
if ($podF === 'complete') {
    $parts = [];
    if ($hasDeliveryPhoto) $parts[] = "COALESCE(d.scm_delivery_photo,'')<>''";
    if ($hasDeliveryVideo) $parts[] = "COALESCE(d.scm_delivery_video,'')<>''";
    $proof = $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
    if ($hasSignature) $where .= " AND $proof AND COALESCE(d.scm_signature_data,'')<>''";
} elseif ($podF === 'incomplete') {
    $proofParts = [];
    if ($hasDeliveryPhoto) $proofParts[] = "COALESCE(d.scm_delivery_photo,'')<>''";
    if ($hasDeliveryVideo) $proofParts[] = "COALESCE(d.scm_delivery_video,'')<>''";
    $proof = $proofParts ? '(' . implode(' OR ', $proofParts) . ')' : '1=0';
    $sig = $hasSignature ? "COALESCE(d.scm_signature_data,'')<>''" : '1=0';
    $where .= " AND NOT ($proof AND $sig)";
}

$deliveredByExpr = $hasDeliveredBy
    ? "COALESCE(d.scm_delivered_by, aud.scm_delivered_audit_by)"
    : "aud.scm_delivered_audit_by";

$sql = "SELECT d.id,d.do_code,d.tracking_code,d.do_date,d.customers_code,c.customers_name,d.office_code,d.grand_total,d.status,
        ".recap_sel($hasOnDeliveryAt,'scm_on_delivery_at').",
        ".$deliveredExpr." AS scm_delivered_at,
        ".$deliveredByExpr." AS scm_delivered_by,
        ".recap_sel($hasDeliveryMode,'delivery_mode').",
        ".recap_sel($hasDeliveryVendorId,'delivery_vendor_id').",
        ".recap_sel($hasReceivePhoto,'scm_receive_photo').",
        ".recap_sel($hasReceiveVideo,'scm_receive_video').",
        ".recap_sel($hasDeliveryPhoto,'scm_delivery_photo').",
        ".recap_sel($hasDeliveryVideo,'scm_delivery_video').",
        ".recap_sel($hasSignature,'scm_signature_data').",
        ".recap_sel($hasLiveAt,'scm_live_at')."
        FROM sales_do d
        LEFT JOIN master_customers c ON c.customers_code=d.customers_code
        LEFT JOIN (
            SELECT record_code,
                   MAX(created_at) AS scm_delivered_audit_at,
                   SUBSTRING_INDEX(GROUP_CONCAT(username ORDER BY created_at DESC SEPARATOR '||'), '||', 1) AS scm_delivered_audit_by
            FROM system_audit_logs
            WHERE module='sales_do' AND action='SCM_DELIVERED'
            GROUP BY record_code
        ) aud ON aud.record_code=d.do_code
        $where
        ORDER BY COALESCE(".$deliveredExpr.", d.do_date) DESC,d.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="scm_delivery_recap_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['DO Code','Tanggal DO','Customer Code','Customer','Office','Grand Total','Mode','On Delivery At','Delivered At','Delivered By','Current Status','Bukti WQS','POD','TTD','Durasi Menit']);
    foreach ($rows as $r) {
        $wqsProof = !empty($r['scm_receive_photo']) || !empty($r['scm_receive_video']);
        $proof = !empty($r['scm_delivery_photo']) || !empty($r['scm_delivery_video']);
        $sig = !empty($r['scm_signature_data']);
        $dur = '';
        if (!empty($r['scm_on_delivery_at']) && !empty($r['scm_delivered_at'])) {
            $a = strtotime((string)$r['scm_on_delivery_at']); $b = strtotime((string)$r['scm_delivered_at']);
            if ($a && $b && $b >= $a) $dur = (string)round(($b-$a)/60);
        }
        fputcsv($out, [$r['do_code'],$r['do_date'],$r['customers_code'],$r['customers_name'],$r['office_code'],$r['grand_total'],$r['delivery_mode'] ?: 'INTERNAL',$r['scm_on_delivery_at'],$r['scm_delivered_at'],$r['scm_delivered_by'],$r['status'],$wqsProof?'OK':'BELUM',$proof?'OK':'BELUM',$sig?'OK':'BELUM',$dur]);
    }
    fclose($out); exit;
}

$total = count($rows);
$totalValue = 0.0; $internal = 0; $vendor = 0; $podComplete = 0; $durationSum = 0; $durationN = 0;
foreach ($rows as $r) {
    $totalValue += (float)($r['grand_total'] ?? 0);
    $mode = strtoupper((string)($r['delivery_mode'] ?? 'INTERNAL'));
    $mode === 'VENDOR' ? $vendor++ : $internal++;
    $proof = !empty($r['scm_delivery_photo']) || !empty($r['scm_delivery_video']);
    $sig = !empty($r['scm_signature_data']);
    if ($proof && $sig) $podComplete++;
    if (!empty($r['scm_on_delivery_at']) && !empty($r['scm_delivered_at'])) {
        $a = strtotime((string)$r['scm_on_delivery_at']); $b = strtotime((string)$r['scm_delivered_at']);
        if ($a && $b && $b >= $a) { $durationSum += ($b-$a)/60; $durationN++; }
    }
}
$avgDuration = $durationN ? round($durationSum/$durationN) : 0;

$offices = [];
try { $offices = $pdo->query("SELECT DISTINCT office_code FROM sales_do WHERE office_code IS NOT NULL AND office_code<>'' ORDER BY office_code")->fetchAll(PDO::FETCH_COLUMN); } catch(Throwable $e) {}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();
rmi_header('SCM - Rekap Delivery', [
    'active'=>'sales',
    'breadcrumbs'=>[['label'=>'Sales (CRM)','url'=>$baseProject.'/sales/sales_dashboard.php'],['label'=>'SCM Task','url'=>$baseProject.'/sales/scm_do_tasks.php'],'Rekap Delivery'],
    'actions'=>[
        ['label'=>'← SCM Task','url'=>$baseProject.'/sales/scm_do_tasks.php','class'=>'btn btn-sm btn-outline-light'],
        ['label'=>rmi_icon('tower') . ' Control Tower','url'=>$baseProject.'/sales/sales_control_tower.php','class'=>'btn btn-sm btn-outline-light'],
    ],
    'extra_head'=>'<style>
      body{background:#080e1a;color:#e2e8f0}.wrap{max-width:1320px;margin:24px auto;padding:0 16px}.card{background:#0f1724;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px;margin-bottom:12px}.filters{display:grid;grid-template-columns:2fr repeat(5,1fr) auto;gap:8px;align-items:end}.field{width:100%;background:#141d2e;color:#e2e8f0;border:1px solid rgba(255,255,255,.1);border-radius:9px;padding:8px 10px}.lbl{font-size:11px;color:#94a3b8;margin-bottom:4px}.cards{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}.metric{background:#141d2e;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:13px}.metric small{color:#94a3b8}.metric strong{display:block;font-size:18px;margin-top:5px}.tblwrap{overflow:auto}.tbl{width:100%;border-collapse:collapse;min-width:1250px}.tbl th,.tbl td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.06);text-align:left;font-size:12px;vertical-align:top}.tbl th{font-size:10px;color:#94a3b8;text-transform:uppercase}.badge{display:inline-block;border:1px solid rgba(255,255,255,.12);border-radius:999px;padding:2px 8px;font-size:10px}.badge.green{color:#bbf7d0;background:rgba(34,197,94,.12)}.badge.blue{color:#bfdbfe;background:rgba(59,130,246,.14)}.badge.yellow{color:#fde68a;background:rgba(245,158,11,.12)}.badge.gray{color:#cbd5e1;background:rgba(255,255,255,.05)}@media(max-width:900px){.filters,.cards{grid-template-columns:1fr 1fr}.wrap{padding:0 10px}} </style>'
]);
?>
<div class="wrap">
  <div class="card">
    <h2 style="margin:0 0 4px">SCM — Rekap Delivery</h2>
    <div style="color:#94a3b8;font-size:12px">Read-only. Berdasarkan event <b>scm_delivered_at</b>, sehingga histori tidak hilang saat workflow lanjut ke ACT/FIN.</div>
  </div>
  <form class="card filters" method="get">
    <div><div class="lbl">Cari</div><input class="field" name="q" value="<?=h($q)?>" placeholder="DO / customer"></div>
    <div><div class="lbl">Tanggal dari</div><input class="field" type="date" name="date_fr" value="<?=h($dateFr)?>"></div>
    <div><div class="lbl">Tanggal s/d</div><input class="field" type="date" name="date_to" value="<?=h($dateTo)?>"></div>
    <div><div class="lbl">Office</div><select class="field" name="office"><option value="">Semua</option><?php foreach($offices as $o):?><option value="<?=h($o)?>" <?=$officeF===$o?'selected':''?>><?=h($o)?></option><?php endforeach;?></select></div>
    <div><div class="lbl">Mode</div><select class="field" name="mode"><option value="">Semua</option><option value="INTERNAL" <?=$modeF==='INTERNAL'?'selected':''?>>INTERNAL</option><option value="VENDOR" <?=$modeF==='VENDOR'?'selected':''?>>VENDOR</option></select></div>
    <div><div class="lbl">POD + TTD</div><select class="field" name="pod"><option value="">Semua</option><option value="complete" <?=$podF==='complete'?'selected':''?>>Lengkap</option><option value="incomplete" <?=$podF==='incomplete'?'selected':''?>>Belum lengkap</option></select></div>
    <div style="display:flex;gap:6px"><button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-outline-light" href="?">Reset</a></div>
  </form>
  <div class="cards">
    <div class="metric"><small>Total Delivered</small><strong><?=number_format($total,0,',','.')?></strong></div>
    <div class="metric"><small>Nilai DO</small><strong>Rp <?=number_format($totalValue,0,',','.')?></strong></div>
    <div class="metric"><small>Internal</small><strong><?=number_format($internal,0,',','.')?></strong></div>
    <div class="metric"><small>Vendor</small><strong><?=number_format($vendor,0,',','.')?></strong></div>
    <div class="metric"><small>POD + TTD Lengkap</small><strong><?=$podComplete?> / <?=$total?></strong></div>
    <div class="metric"><small>Avg Delivery</small><strong><?=$avgDuration?> menit</strong></div>
  </div>
  <div class="card" style="margin-top:12px">
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px"><b>Detail Delivery</b><a class="btn btn-sm btn-outline-light" href="?<?=h(http_build_query(array_merge($_GET,['export'=>'csv'])))?>">CSV</a></div>
    <div class="tblwrap"><table class="tbl"><thead><tr><th>DO</th><th>Customer</th><th>Office</th><th>Nilai</th><th>Mode</th><th>On Delivery</th><th>Delivered</th><th>Durasi</th><th>PIC SCM</th><th>Current Status</th><th>Bukti WQS</th><th>POD Customer</th><th>TTD</th></tr></thead><tbody>
    <?php if(!$rows):?><tr><td colspan="13">Belum ada data.</td></tr><?php endif;?>
    <?php foreach($rows as $r):
      $wqsProof=!empty($r['scm_receive_photo'])||!empty($r['scm_receive_video']); $proof=!empty($r['scm_delivery_photo'])||!empty($r['scm_delivery_video']); $sig=!empty($r['scm_signature_data']); $dur='-';
      if(!empty($r['scm_on_delivery_at'])&&!empty($r['scm_delivered_at'])){ $a=strtotime($r['scm_on_delivery_at']);$b=strtotime($r['scm_delivered_at']);if($a&&$b&&$b>=$a)$dur=number_format(($b-$a)/60,0,',','.').' mnt'; }
    ?><tr>
      <td><b><?=h($r['do_code'])?></b><br><a href="./sales_do_view.php?id=<?=(int)$r['id']?>" target="_blank">Detail ↗</a></td>
      <td><b><?=h($r['customers_name'] ?: $r['customers_code'])?></b><br><?=h($r['customers_code'])?></td>
      <td><?=h($r['office_code'])?></td><td>Rp <?=number_format((float)$r['grand_total'],0,',','.')?></td><td><?=h(strtoupper($r['delivery_mode'] ?: 'INTERNAL'))?></td>
      <td><?=h($r['scm_on_delivery_at'] ?: '-')?></td><td><?=h($r['scm_delivered_at'] ?: '-')?></td><td><?=$dur?></td><td><?=h($r['scm_delivered_by'] ?: '-')?></td><td><?=recap_status_badge((string)$r['status'])?></td>
      <td><span class="badge <?=$wqsProof?'green':'yellow'?>"><?=$wqsProof?'OK':'BELUM'?></span><br>
        <?php if(!empty($r['scm_receive_photo'])):?><a href="<?=h($r['scm_receive_photo'])?>" target="_blank">📷 Foto ↗</a><?php endif;?>
        <?php if(!empty($r['scm_receive_video'])):?><br><a href="<?=h($r['scm_receive_video'])?>" target="_blank">🎥 Video ↗</a><?php endif;?>
      </td>
      <td><span class="badge <?=$proof?'green':'yellow'?>"><?=$proof?'OK':'BELUM'?></span><br>
        <?php if(!empty($r['scm_delivery_photo'])):?><a href="<?=h($r['scm_delivery_photo'])?>" target="_blank">📷 Foto ↗</a><?php endif;?>
        <?php if(!empty($r['scm_delivery_video'])):?><br><a href="<?=h($r['scm_delivery_video'])?>" target="_blank">🎥 Video ↗</a><?php endif;?>
      </td>
      <td><span class="badge <?=$sig?'green':'yellow'?>"><?=$sig?'ADA ' . rmi_icon('tick'):'BELUM'?></span></td>
    </tr><?php endforeach;?>
    </tbody></table></div>
  </div>
</div>
<?php rmi_footer(); ?>
