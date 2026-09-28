<?php
// KPI Stock — actual otomatis ERP + adjustment resmi.
require_once __DIR__ . '/_kpi_bootstrap.php';
require_once __DIR__ . '/_kpi_metrics.php';
require_once __DIR__ . '/_kpi_policy.php';
require_once __DIR__ . '/../_shared/rbac.php';

$pdo = $pdo ?? (function_exists('kpi_require_pdo') ? kpi_require_pdo() : null);
if (!$pdo) { http_response_code(500); exit('DB unavailable'); }
if (!function_exists('rbac_require')) { http_response_code(500); exit('RBAC unavailable'); }
rbac_require($pdo, 'KPI.VIEW');
kpi_ensure_compat_schema($pdo);

$table='kpi_office';
if (!kpi_table_exists($pdo,$table)) {
    kpi_header('KPI Stock'); kpi_nav('stock');
    echo "<div class='card'><span class='badge danger'>MISSING</span> Tabel kpi_office belum tersedia.</div>";
    kpi_footer(); exit;
}

$cols=kpi_table_columns($pdo,$table);
$pk=kpi_pick_col($cols,['id','kpi_office_id'])?:'id';
$MONTH_COL=kpi_month_col($pdo,$table);
$OFFICE_COL=kpi_office_col($pdo,$table)?:'office_code';
$STATUS_COL=kpi_status_col($pdo,$table);
$DEL_COL=kpi_deleted_col($pdo,$table);
$JSON_COL=kpi_json_col($pdo,$table)?:'metrics_json';
$CREATED_BY=kpi_pick_col($cols,['created_by']); $CREATED_AT=kpi_pick_col($cols,['created_at']);
$UPDATED_BY=kpi_pick_col($cols,['updated_by']); $UPDATED_AT=kpi_pick_col($cols,['updated_at']);

$offices=kpi_get_master_offices($pdo);
$OFFICE_IS_NUM=kpi_is_numeric_column($pdo,$table,$OFFICE_COL);
$officeByCode=[];$officeById=[];
foreach($offices as $o){$id=trim((string)($o['office_id']??''));$code=trim((string)($o['office_code']??''));$name=trim((string)($o['office_name']??''));if($code==='')continue;$row=['office_id'=>$id,'office_code'=>$code,'office_name'=>$name];$officeByCode[strtoupper($code)]=$row;if($id!=='')$officeById[$id]=$row;}
$officeInputToDb=function(string $v)use($OFFICE_IS_NUM,$officeByCode){$v=trim($v);if($v==='')return '';if(!$OFFICE_IS_NUM)return $v;if(ctype_digit($v))return $v;return $officeByCode[strtoupper($v)]['office_id']??null;};
$officeDbToCode=function($v)use($OFFICE_IS_NUM,$officeById){$v=trim((string)$v);if(!$OFFICE_IS_NUM)return $v;return $officeById[$v]['office_code']??$v;};
$officeDbToLabel=function($v)use($officeDbToCode,$OFFICE_IS_NUM,$officeById){$code=$officeDbToCode($v);if(!$OFFICE_IS_NUM)return $code;$id=trim((string)$v);$name=$officeById[$id]['office_name']??'';return $name!==''?$code.' • '.$name:$code;};

function stock_metrics_decode($json):array{$x=json_decode((string)$json,true);return is_array($x)?$x:[];}
function stock_flash(string $type,string $msg):void{$_SESSION['_kpi_stock_flash'][$type]=$msg;}
function stock_take_flash():array{$f=$_SESSION['_kpi_stock_flash']??[];unset($_SESSION['_kpi_stock_flash']);return is_array($f)?$f:[];}
function stock_num($v):float{return is_numeric($v)?(float)$v:0.0;}
function stock_final_value($actual,$adj){return $actual===null?null:(float)$actual+(float)$adj;}

$ADJ_KEYS=[
 'stock_value_available'=>'adjustment_stock_value',
 'stock_qty_available'=>'adjustment_stock_qty',
 'unallocated_qty'=>'adjustment_unallocated_qty',
 'allocation_sla_pct'=>'adjustment_allocation_sla_pct',
 'expiring_90_value'=>'adjustment_expiring_90_value',
 'expired_value'=>'adjustment_expired_value',
 'adjustment_count_mtd'=>'adjustment_count',
 'adjustment_abs_qty_mtd'=>'adjustment_abs_qty',
];

// Export adjustment records; actual tetap dihitung ulang dari ERP.
if(($_GET['export']??'')==='csv'){
 $where=[];$params=[];$fm=trim((string)($_GET['month']??''));$fo=trim((string)($_GET['office']??''));$fs=trim((string)($_GET['status']??''));
 if($DEL_COL)$where[]="{$DEL_COL} IS NULL";if($fm!==''){$where[]="{$MONTH_COL}=:m";$params[':m']=$fm;}if($fo!==''){$db=$officeInputToDb($fo);if($db===null)$where[]='1=0';else{$where[]="{$OFFICE_COL}=:o";$params[':o']=$db;}}if($fs!==''){$where[]="{$STATUS_COL}=:s";$params[':s']=$fs;}
 $st=$pdo->prepare("SELECT * FROM {$table}".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY {$MONTH_COL} DESC,{$OFFICE_COL}");$st->execute($params);$out=[];
 foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){$j=stock_metrics_decode($r[$JSON_COL]??'');$out[]=['month_ym'=>$r[$MONTH_COL]??'','office_code'=>$officeDbToCode($r[$OFFICE_COL]??''),'status'=>$r[$STATUS_COL]??'','adjustment_stock_value'=>$j['stock_adjustment']['stock_value_available']??0,'adjustment_stock_qty'=>$j['stock_adjustment']['stock_qty_available']??0,'adjustment_unallocated_qty'=>$j['stock_adjustment']['unallocated_qty']??0,'adjustment_allocation_sla_pct'=>$j['stock_adjustment']['allocation_sla_pct']??0,'adjustment_expiring_90_value'=>$j['stock_adjustment']['expiring_90_value']??0,'adjustment_expired_value'=>$j['stock_adjustment']['expired_value']??0,'adjustment_count'=>$j['stock_adjustment']['adjustment_count_mtd']??0,'adjustment_abs_qty'=>$j['stock_adjustment']['adjustment_abs_qty_mtd']??0,'reason'=>$j['stock_adjustment_reason']??'','checker_username'=>$j['stock_checker_username']??''];}
 kpi_csv_download('kpi_stock_adjustment_export.csv',$out);
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['_action'])){
 if(function_exists('verify_csrf'))verify_csrf();
 if(!kpi_can_manage()){stock_flash('err','Akses ditolak. Hanya pengelola KPI yang dapat membuat adjustment.');rmi_redirect('kpi_stock.php');}
 $action=(string)$_POST['_action'];
 if($action==='delete'){
   if(!kpi_can_manage('SYS')){stock_flash('err','Hapus hanya untuk SYS.');rmi_redirect('kpi_stock.php');}
   $id=(int)($_POST['id']??0);if($id>0){if($DEL_COL)$pdo->prepare("UPDATE {$table} SET {$DEL_COL}=NOW() WHERE {$pk}=:id AND {$STATUS_COL}<>'LOCKED'")->execute([':id'=>$id]);else$pdo->prepare("DELETE FROM {$table} WHERE {$pk}=:id AND {$STATUS_COL}<>'LOCKED'")->execute([':id'=>$id]);kpi_audit($pdo,'kpi_stock','delete',(string)$id,'adjustment deleted');}
   stock_flash('ok','Adjustment dihapus.');rmi_redirect('kpi_stock.php');
 }
 if($action==='save'){
   $id=(int)($_POST['id']??0);$month=trim((string)($_POST['month_ym']??''));$office=trim((string)($_POST['office_code']??''));$officeDb=$officeInputToDb($office);$status=strtoupper(trim((string)($_POST['status']??'DRAFT')));$reason=trim((string)($_POST['reason']??''));$checker=trim((string)($_POST['checker_username']??''));
   if(!preg_match('/^\d{4}-\d{2}$/',$month)||$office===''||$officeDb===null){stock_flash('err','Bulan dan office tidak valid.');rmi_redirect('kpi_stock.php');}
   if(!in_array($status,['DRAFT','FINAL'],true))$status='DRAFT';
   if(strlen($reason)<10){stock_flash('err','Alasan adjustment minimal 10 karakter.');rmi_redirect('kpi_stock.php');}
   $actor=(string)($_SESSION['username']??$_SESSION['user_name']??'system');
   if($status==='FINAL'){
      if($checker===''||!function_exists('kpi_user_exists_active')||!kpi_user_exists_active($pdo,$checker)){stock_flash('err','Checker aktif wajib untuk status FINAL.');rmi_redirect('kpi_stock.php');}
      if(strcasecmp($checker,$actor)===0){stock_flash('err','Checker harus berbeda dari maker.');rmi_redirect('kpi_stock.php');}
   }
   $w=$DEL_COL?" AND {$DEL_COL} IS NULL":'';$existing=null;
   if($id>0){$st=$pdo->prepare("SELECT * FROM {$table} WHERE {$pk}=:id{$w} LIMIT 1");$st->execute([':id'=>$id]);$existing=$st->fetch(PDO::FETCH_ASSOC)?:null;}
   if(!$existing){$st=$pdo->prepare("SELECT * FROM {$table} WHERE {$MONTH_COL}=:m AND {$OFFICE_COL}=:o{$w} LIMIT 1");$st->execute([':m'=>$month,':o'=>$officeDb]);$existing=$st->fetch(PDO::FETCH_ASSOC)?:null;}
   if($existing&&strtoupper((string)($existing[$STATUS_COL]??''))==='LOCKED'){stock_flash('err','Data LOCKED dan tidak dapat diubah.');rmi_redirect('kpi_stock.php');}
   $policy=kpi_stock_policy_load($pdo,$office);$actual=kpi_calc_stock_metrics($pdo,$month,$office,$policy);
   $adjustment=[];$final=[];
   foreach($ADJ_KEYS as $metric=>$post){$adjustment[$metric]=stock_num($_POST[$post]??0);$final[$metric]=stock_final_value($actual[$metric]??null,$adjustment[$metric]);}
   $coverageKeys=['stock_qty_available','unallocated_qty','allocation_sla_pct','expiring_90_value','expired_value','adjustment_count_mtd','adjustment_abs_qty_mtd'];$avail=$actual['metric_available']??[];$nAvail=0;foreach($coverageKeys as $k)if(!empty($avail[$k]))$nAvail++;$metricCoverage=count($coverageKeys)?($nAvail/count($coverageKeys)*100):0;
   $payload=$existing?stock_metrics_decode($existing[$JSON_COL]??''):[];
   $payload['stock_actual']=$actual;$payload['stock_adjustment']=$adjustment;$payload['stock_final']=$final;$payload['stock_adjustment_reason']=$reason;$payload['stock_checker_username']=$checker;$payload['stock_maker_username']=$actor;$payload['stock_metric_coverage_pct']=$metricCoverage;$payload['stock_source']='auto_erp_plus_adjustment';$payload['stock_updated_at']=date('c');
   // Legacy keys tetap diisi dari final agar modul lama tidak rusak.
   foreach($final as $k=>$v)$payload[$k]=$v;$payload['source_stock']='auto_erp_plus_adjustment';$payload['stock_note']=$reason;
   $json=json_encode($payload,JSON_UNESCAPED_UNICODE);
   if($existing){$id=(int)$existing[$pk];$sets=["{$MONTH_COL}=:m","{$OFFICE_COL}=:o","{$STATUS_COL}=:s","{$JSON_COL}=:j"];$p=[':m'=>$month,':o'=>$officeDb,':s'=>$status,':j'=>$json,':id'=>$id];if($UPDATED_BY){$sets[]="{$UPDATED_BY}=:ub";$p[':ub']=$actor;}if($UPDATED_AT)$sets[]="{$UPDATED_AT}=NOW()";$pdo->prepare("UPDATE {$table} SET ".implode(',',$sets)." WHERE {$pk}=:id")->execute($p);$act='update';}
   else{$ci=[$MONTH_COL,$OFFICE_COL,$STATUS_COL,$JSON_COL];$vi=[':m',':o',':s',':j'];$p=[':m'=>$month,':o'=>$officeDb,':s'=>$status,':j'=>$json];if($CREATED_BY){$ci[]=$CREATED_BY;$vi[]=':cb';$p[':cb']=$actor;}if($CREATED_AT){$ci[]=$CREATED_AT;$vi[]='NOW()';}if($UPDATED_BY){$ci[]=$UPDATED_BY;$vi[]=':ub';$p[':ub']=$actor;}if($UPDATED_AT){$ci[]=$UPDATED_AT;$vi[]='NOW()';}$pdo->prepare("INSERT INTO {$table}(".implode(',',$ci).") VALUES(".implode(',',$vi).")")->execute($p);$id=(int)$pdo->lastInsertId();$act='create';}
   kpi_audit($pdo,'kpi_stock',$act,(string)$id,"month={$month};office={$office};status={$status};source=auto_erp_plus_adjustment");stock_flash('ok','KPI Stock tersimpan. Actual tetap berasal dari ERP; perubahan manual tercatat sebagai adjustment.');rmi_redirect('kpi_stock.php?edit='.$id);
 }
}

$flash=stock_take_flash();$editId=(int)($_GET['edit']??0);$editRow=null;if($editId>0){$w=$DEL_COL?" AND {$DEL_COL} IS NULL":'';$st=$pdo->prepare("SELECT * FROM {$table} WHERE {$pk}=:id{$w} LIMIT 1");$st->execute([':id'=>$editId]);$editRow=$st->fetch(PDO::FETCH_ASSOC)?:null;}
$fMonth=trim((string)($_GET['month']??''));$fOffice=trim((string)($_GET['office']??''));$fStatus=trim((string)($_GET['status']??''));$where=[];$params=[];if($DEL_COL)$where[]="{$DEL_COL} IS NULL";if($fMonth!==''){$where[]="{$MONTH_COL}=:m";$params[':m']=$fMonth;}if($fOffice!==''){$db=$officeInputToDb($fOffice);if($db===null)$where[]='1=0';else{$where[]="{$OFFICE_COL}=:o";$params[':o']=$db;}}if($fStatus!==''){$where[]="{$STATUS_COL}=:s";$params[':s']=$fStatus;}$st=$pdo->prepare("SELECT * FROM {$table}".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY {$MONTH_COL} DESC,{$OFFICE_COL},{$pk} DESC LIMIT 500");$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC);

kpi_header('KPI Stock');kpi_nav('stock');
if(!empty($flash['err']))echo "<div class='card kpi-flash-err'><b>Error:</b> ".h($flash['err'])."</div>";if(!empty($flash['ok']))echo "<div class='card kpi-flash-ok'><b>OK:</b> ".h($flash['ok'])."</div>";
echo "<div class='card'><div class='kpi-header-row'><div><h2>KPI Stock</h2><div class='kpi-subtitle'>Actual dihitung otomatis dari transaksi ERP. Input manual hanya adjustment resmi dan tercatat audit.</div><div class='muted'>Alur: transaksi stok → actual otomatis → adjustment beralasan → FINAL → Snapshot & Lock.</div></div><div><a class='btn secondary' href='kpi_stock.php?export=csv&month=".urlencode($fMonth)."&office=".urlencode($fOffice)."&status=".urlencode($fStatus)."'>Export Adjustment CSV</a> <a class='btn secondary' href='kpi_stock.php'>Reset</a></div></div></div>";

$valMonth=(string)($editRow[$MONTH_COL]??date('Y-m'));$valOffice=$editRow?$officeDbToCode($editRow[$OFFICE_COL]??''):'';$valStatus=(string)($editRow[$STATUS_COL]??'DRAFT');$saved=$editRow?stock_metrics_decode($editRow[$JSON_COL]??''):[];$adj=$saved['stock_adjustment']??[];$reason=(string)($saved['stock_adjustment_reason']??'');$checker=(string)($saved['stock_checker_username']??'');$locked=$valStatus==='LOCKED';
$actual=$valOffice!==''?kpi_calc_stock_metrics($pdo,$valMonth,$valOffice,kpi_stock_policy_load($pdo,$valOffice)):null;
$opts="<option value=''>-- pilih --</option>";foreach($offices as $o){$c=(string)($o['office_code']??'');if($c==='')continue;$n=(string)($o['office_name']??$c);$opts.="<option value='".h($c)."'".($c===$valOffice?' selected':'').">".h($c.' • '.$n)."</option>";}
if(kpi_can_manage()){
 echo "<div class='card'><h3>".($editId?'Review / Adjustment KPI Stock':'Buat Adjustment KPI Stock')."</h3><form method='post'>".(function_exists('csrf_field')?csrf_field():'')."<input type='hidden' name='_action' value='save'><input type='hidden' name='id' value='".h((string)$editId)."'><div class='kpi-form-row'><div class='kpi-field'><label>Bulan</label><input type='month' name='month_ym' value='".h($valMonth)."' required></div><div class='kpi-field'><label>Office</label><select name='office_code' required>{$opts}</select></div><div class='kpi-field'><label>Status</label><select name='status'><option".($valStatus==='DRAFT'?' selected':'').">DRAFT</option><option".($valStatus==='FINAL'?' selected':'').">FINAL</option></select></div><div class='kpi-field'><button class='btn ok'".($locked?' disabled':'').">Simpan</button></div></div>";
 if($actual){$av=$actual['metric_available']??[];$fmt=function($v,$money=false){if($v===null)return 'N/A';return $money?'Rp '.number_format((float)$v,0,',','.'):number_format((float)$v,2,',','.');};echo "<div class='kpi-input-group'><div class='kpi-input-group-title'>Actual Otomatis ERP — read only</div><div class='muted'>Source: ".h(implode(', ',$actual['sources']??[]))." • Cost coverage: ".h(number_format((float)($actual['cost_coverage_pct']??0),1,',','.'))."%</div><div class='kpi-form-grid'>";foreach([['stock_value_available','Stock Value',1],['stock_qty_available','Stock Qty',0],['unallocated_qty','Unallocated Qty',0],['allocation_sla_pct','Allocation SLA %',0],['expiring_90_value','Expiring ≤90 Hari',1],['expired_value','Expired Value',1],['adjustment_count_mtd','Adjustment Count MTD',0],['adjustment_abs_qty_mtd','Adjustment Abs Qty MTD',0]] as [$k,$l,$m])echo "<div class='kpi-field'><label>".h($l)."</label><input readonly value='".h($fmt(!empty($av[$k])?$actual[$k]:null,(bool)$m))."'></div>";echo "</div></div>";if(!empty($actual['warnings']))echo "<div class='muted'>Peringatan: ".h(implode(' | ',$actual['warnings']))."</div>";}
 echo "<div class='kpi-input-group'><div class='kpi-input-group-title'>Adjustment Resmi</div><div class='muted'>Isi hanya selisih koreksi (+/-). Jangan menyalin ulang actual ERP.</div><div class='kpi-form-grid'>";foreach([['adjustment_stock_value','Stock Value'],['adjustment_stock_qty','Stock Qty'],['adjustment_unallocated_qty','Unallocated Qty'],['adjustment_allocation_sla_pct','Allocation SLA %'],['adjustment_expiring_90_value','Expiring Value'],['adjustment_expired_value','Expired Value'],['adjustment_count','Adjustment Count'],['adjustment_abs_qty','Adjustment Abs Qty']] as [$post,$label]){$metric=array_search($post,$ADJ_KEYS,true);$v=$metric!==false?($adj[$metric]??0):0;echo "<div class='kpi-field'><label>".h($label)." (+/-)</label><input type='number' step='0.01' name='".h($post)."' value='".h((string)$v)."'></div>";}echo "</div><div class='kpi-form-grid'><div class='kpi-field'><label>Alasan Adjustment</label><textarea name='reason' required minlength='10'>".h($reason)."</textarea></div><div class='kpi-field'><label>Checker Username (wajib saat FINAL)</label><input name='checker_username' value='".h($checker)."'></div></div></div></form></div>";
}

$filterOpts="<option value=''>-- Semua Office --</option>";foreach($offices as $o){$c=(string)($o['office_code']??'');if($c==='')continue;$n=(string)($o['office_name']??$c);$filterOpts.="<option value='".h($c)."'".($c===$fOffice?' selected':'').">".h($c.' • '.$n)."</option>";}
echo "<div class='card'><h3>Filter</h3><form method='get' class='kpi-form-row'><div class='kpi-field'><label>Bulan</label><input type='month' name='month' value='".h($fMonth)."'></div><div class='kpi-field'><label>Office</label><select name='office'>{$filterOpts}</select></div><div class='kpi-field'><label>Status</label><select name='status'><option value=''>Semua</option><option".($fStatus==='DRAFT'?' selected':'').">DRAFT</option><option".($fStatus==='FINAL'?' selected':'').">FINAL</option><option".($fStatus==='LOCKED'?' selected':'').">LOCKED</option></select></div><div class='kpi-field'><button class='btn secondary'>Apply</button></div></form></div>";

echo "<div class='card'><h3>Data KPI Stock</h3><div class='table-wrap'><table><thead><tr><th>ID</th><th>Bulan</th><th>Office</th><th>Status</th><th>Source</th><th>Coverage</th><th>Value Final</th><th>Qty Final</th><th>Expired Final</th><th>Aksi</th></tr></thead><tbody>";
foreach($rows as $r){$id=(int)($r[$pk]??0);$j=stock_metrics_decode($r[$JSON_COL]??'');$f=$j['stock_final']??$j;$status=(string)($r[$STATUS_COL]??'');echo "<tr><td>".h((string)$id)."</td><td>".h((string)($r[$MONTH_COL]??''))."</td><td>".h($officeDbToLabel($r[$OFFICE_COL]??''))."</td><td><span class='pill'>".h($status)."</span></td><td>".h((string)($j['stock_source']??$j['source_stock']??'legacy'))."</td><td>".h(number_format((float)($j['stock_metric_coverage_pct']??0),1,',','.'))."%</td><td style='text-align:right'>".h($f['stock_value_available']===null?'N/A':number_format((float)($f['stock_value_available']??0),0,',','.'))."</td><td style='text-align:right'>".h($f['stock_qty_available']===null?'N/A':number_format((float)($f['stock_qty_available']??0),2,',','.'))."</td><td style='text-align:right'>".h($f['expired_value']===null?'N/A':number_format((float)($f['expired_value']??0),0,',','.'))."</td><td><a class='btn tiny' href='?edit={$id}'>".(kpi_can_manage()?'Review':'Lihat')."</a>";if(kpi_can_manage('SYS')&&$status!=='LOCKED')echo " <form method='post' style='display:inline' onsubmit=\"return confirm('Hapus adjustment ini?');\">".(function_exists('csrf_field')?csrf_field():'')."<input type='hidden' name='_action' value='delete'><input type='hidden' name='id' value='{$id}'><button class='btn tiny danger'>Del</button></form>";echo "</td></tr>";}
echo "</tbody></table></div><div class='muted'>Total rows: ".h((string)count($rows))."</div></div>";
kpi_footer();
