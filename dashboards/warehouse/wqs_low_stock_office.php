<?php
require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../../_shared/helpers.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
if (function_exists('require_login')) { @require_login(); }

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
$pdo = $GLOBALS['pdo'] ?? null;
function u($path) { $bp=$GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT')?rtrim((string)BASE_PROJECT,'/'):''); return rtrim($bp,'/').'/'.ltrim($path,'/'); }
function ls_table_exists($pdo,$t){ try{$pdo->query("SELECT 1 FROM `$t` LIMIT 1");return true;}catch(Throwable $e){return false;} }
function ls_cols($pdo,$t){$o=[];try{foreach($pdo->query("SHOW COLUMNS FROM `$t`") as $r){$o[(string)$r['Field']]=1;}}catch(Throwable $e){}return $o;}
function ls_pick($cols,$cands){foreach($cands as $c)if(isset($cols[$c]))return $c;return null;}

$user = function_exists('auth_user') ? (array)auth_user() : [];
$dept = strtoupper(trim((string)($user['department'] ?? $user['dept'] ?? ($_SESSION['department'] ?? $_SESSION['dept'] ?? ''))));
$role = strtoupper(trim((string)($user['role'] ?? ($_SESSION['role'] ?? ''))));
$level= strtoupper(trim((string)($user['level'] ?? ($_SESSION['level'] ?? ''))));
$username=strtoupper(trim((string)($user['username'] ?? ($_SESSION['username'] ?? ''))));
$isMgr=in_array($role,['MANAGER','MGR'],true)||in_array($level,['MANAGER','MGR'],true)||str_starts_with($username,'MGR');
$globalScope=($dept==='WQS' && $isMgr)||strpos($username,'MGRWQS')!==false;
$scope=function_exists('ds_scope_ctx')?ds_scope_ctx():['is_admin'=>true,'office_code'=>''];
if($globalScope){$scope['is_admin']=true;$scope['office_code']='';}
$scopeOffice=strtoupper(trim((string)($scope['office_code']??'')));
$scopeLocked=empty($scope['is_admin']) && $scopeOffice!=='';

$threshold=max(2,min(100,(int)($_GET['threshold']??10)));
$q=trim((string)($_GET['q']??''));
$office=trim((string)($_GET['office']??''));
if($scopeLocked)$office=$scopeOffice;

$rows=[];$offices=[];$summary=['office_sku'=>0,'sku'=>0,'qty'=>0,'critical'=>0];$err='';
if($pdo){
  $table=ls_table_exists($pdo,'wqs_stock_by_office')?'wqs_stock_by_office':(ls_table_exists($pdo,'wqs_stock')?'wqs_stock':null);
  if($table){
    $c=ls_cols($pdo,$table); $qty=ls_pick($c,['qty','stock_qty','on_hand_qty']); $prod=ls_pick($c,['product_id','sku']); $off=ls_pick($c,['office_code','office','branch_code']);
    if($qty && $prod && $off){
      try{
        $st=$pdo->query("SELECT DISTINCT UPPER(TRIM(COALESCE(`$off`,''))) o FROM `$table` WHERE TRIM(COALESCE(`$off`,''))<>'' ORDER BY o");
        $offices=$st->fetchAll(PDO::FETCH_COLUMN);
        $hasMp=ls_table_exists($pdo,'master_products');
        if($hasMp && $prod==='product_id'){$join="LEFT JOIN master_products mp ON mp.id=ws.`$prod`";$sku="COALESCE(mp.sku,'')";$name="COALESCE(NULLIF(mp.products_name,''),mp.sku,CONCAT('ID#',ws.`$prod`))";}
        elseif($hasMp && $prod==='sku'){$join="LEFT JOIN master_products mp ON mp.sku=ws.`$prod`";$sku="ws.`$prod`";$name="COALESCE(NULLIF(mp.products_name,''),ws.`$prod`)";}
        else{$join='';$sku=$prod==='sku'?"ws.`$prod`":"''";$name=$prod==='sku'?"ws.`$prod`":"CONCAT('ID#',ws.`$prod`)";}
        $where=[];$params=[];
        if($office!==''){$where[]="UPPER(TRIM(COALESCE(ws.`$off`,'')))=UPPER(?)";$params[]=$office;}
        if($scopeLocked && $office===''){$where[]="UPPER(TRIM(COALESCE(ws.`$off`,'')))=UPPER(?)";$params[]=$scopeOffice;}
        if($q!==''){$where[]="(CAST(ws.`$prod` AS CHAR) LIKE ? OR $sku LIKE ? OR $name LIKE ?)";$like='%'.$q.'%';$params=array_merge($params,[$like,$like,$like]);}
        $wh=$where?'WHERE '.implode(' AND ',$where):'';
        $sql="SELECT UPPER(TRIM(COALESCE(ws.`$off`,''))) office_code, ws.`$prod` product_key, $sku sku, $name product_name, SUM(COALESCE(ws.`$qty`,0)) stock_qty FROM `$table` ws $join $wh GROUP BY UPPER(TRIM(COALESCE(ws.`$off`,''))),ws.`$prod`,$sku,$name HAVING SUM(COALESCE(ws.`$qty`,0))>0 AND SUM(COALESCE(ws.`$qty`,0))<? ORDER BY office_code,stock_qty,product_name";
        $st=$pdo->prepare($sql);$st->execute(array_merge($params,[$threshold]));$rows=$st->fetchAll(PDO::FETCH_ASSOC);
        $summary['office_sku']=count($rows);$summary['sku']=count(array_unique(array_map(fn($r)=>(string)$r['product_key'],$rows)));$summary['qty']=array_sum(array_map(fn($r)=>(float)$r['stock_qty'],$rows));$summary['critical']=count(array_filter($rows,fn($r)=>(float)$r['stock_qty']<=3));
      }catch(Throwable $e){$err=$e->getMessage();}
    } else $err='Kolom stock/product/office tidak lengkap.';
  } else $err='Tabel stok WQS tidak ditemukan.';
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WQS - Low Stock per Kantor</title>
<style>
body{background:#0b1725;color:#e5edf7;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0}.wrap{max-width:1500px;margin:auto;padding:22px}.head,.panel,.stat{background:#142235;border:1px solid #2b4057;border-radius:16px}.head{padding:20px 22px;display:flex;justify-content:space-between;gap:15px;align-items:center}.head h1{margin:0;font-size:25px}.muted{color:#8fa2b8;font-size:13px}.btn{display:inline-block;padding:9px 13px;border:1px solid #49647f;border-radius:9px;color:#eaf2fb;text-decoration:none;background:#1b2d43}.filters{display:grid;grid-template-columns:1.2fr .7fr .45fr auto;gap:10px;align-items:end}.panel{padding:18px;margin-top:16px}label{font-size:12px;font-weight:700;color:#aab9c9;display:block;margin-bottom:6px}input,select{width:100%;box-sizing:border-box;background:#0f1c2d;color:#eaf2fb;border:1px solid #40556d;border-radius:9px;padding:10px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:16px}.stat{padding:15px}.n{font-size:26px;font-weight:800}.warn{color:#fbbf24}.danger{color:#fb7185}table{width:100%;border-collapse:collapse;margin-top:8px}th{background:#071222;color:#dbeafe;text-align:left;padding:11px;font-size:12px;position:sticky;top:0}td{border-bottom:1px solid #304258;padding:11px;font-size:13px}code{color:#60a5fa}.badge{padding:4px 8px;border-radius:999px;background:#25384e}.qty{font-weight:800;text-align:right}.action{color:#fb923c;text-decoration:none;font-weight:700}.office-row td{background:#1a2b40;font-weight:800;color:#f8fafc}.note{padding:12px;border-radius:10px;background:#101e30;color:#a9b8c9;font-size:12px;margin-top:12px}@media(max-width:800px){.filters,.stats{grid-template-columns:1fr}.head{align-items:flex-start;flex-direction:column}}
</style></head><body><div class="wrap">
<div class="head"><div><h1>📉 WQS — Low Stock per Kantor</h1><div class="muted">Exception stock per Office + SKU. Stok kantor lain tidak menutupi kekurangan kantor yang dipilih.</div></div><div><a class="btn" href="<?=h(u('/dashboards/warehouse/wqs_dashboard.php'))?>">← Dashboard WQS</a></div></div>
<div class="panel"><form class="filters" method="get"><div><label>Cari SKU / Produk</label><input name="q" value="<?=h($q)?>" placeholder="contoh: 030228B"></div><div><label>Kantor</label><select name="office" <?=$scopeLocked?'disabled':''?>><option value="">Semua Kantor</option><?php foreach($offices as $o):?><option value="<?=h($o)?>" <?=strtoupper($office)===strtoupper($o)?'selected':''?>><?=h($o)?></option><?php endforeach?></select><?php if($scopeLocked):?><input type="hidden" name="office" value="<?=h($scopeOffice)?>"><?php endif?></div><div><label>Batas Low Stock</label><input type="number" min="2" max="100" name="threshold" value="<?=h($threshold)?>"></div><div><button class="btn" type="submit">Terapkan</button></div></form><div class="note">Definisi: stock per <b>Office + SKU</b> dijumlahkan dahulu. Nilai <b>1 sampai <?=h($threshold-1)?></b> masuk Low Stock. Stock 0 tidak dicampur karena merupakan Out of Stock.</div></div>
<div class="stats"><div class="stat"><div class="muted">LOW-STOCK OFFICE-SKU</div><div class="n warn"><?=number_format($summary['office_sku'])?></div></div><div class="stat"><div class="muted">SKU UNIK TERDAMPAK</div><div class="n"><?=number_format($summary['sku'])?></div></div><div class="stat"><div class="muted">TOTAL QTY LOW-STOCK</div><div class="n"><?=number_format($summary['qty'],0,',','.')?></div></div><div class="stat"><div class="muted">CRITICAL 1–3</div><div class="n danger"><?=number_format($summary['critical'])?></div></div></div>
<div class="panel"><?php if($err):?><div class="danger"><?=h($err)?></div><?php elseif(!$rows):?><div class="muted">Tidak ada Office-SKU low stock untuk filter ini.</div><?php else:?><div style="overflow:auto;max-height:70vh"><table><thead><tr><th>KANTOR</th><th>SKU</th><th>PRODUK</th><th style="text-align:right">STOK</th><th>STATUS</th><th>AKSI</th></tr></thead><tbody><?php $last=null; foreach($rows as $r): $oc=(string)$r['office_code']; if($last!==$oc): $last=$oc; $cnt=count(array_filter($rows,fn($x)=>(string)$x['office_code']===$oc));?><tr class="office-row"><td colspan="6">🏢 <?=h($oc?:'—')?> · <?=number_format($cnt)?> item low-stock</td></tr><?php endif; $v=(float)$r['stock_qty'];?><tr><td><?=h($oc?:'—')?></td><td><code><?=h($r['sku']?:$r['product_key'])?></code></td><td><?=h($r['product_name'])?></td><td class="qty" style="color:<?=$v<=3?'#fb7185':'#fbbf24'?>"><?=number_format($v,0,',','.')?></td><td><span class="badge"><?=$v<=3?'CRITICAL':'LOW'?></span></td><td><a class="action" href="<?=h(u('/stock/wqs_pr.php'))?>">+ PR</a></td></tr><?php endforeach?></tbody></table></div><?php endif?></div>
</div></body></html>
