<?php
/**
 * ONE-TIME SAFE REPAIR
 * Case:
 * - BMHP-BGR-260902-010 = original DO, FULL return, commercial effect = REPLACEMENT
 * - BMHP-BGR-260902-012 = existing fulfillment replacement child
 *
 * Effects:
 * - sales_do_returns.commercial_effect = REPLACEMENT
 * - sales_do_returns.replacement_do_id = DO012
 * - sales_do.is_replacement_fulfillment = 1 on DO012
 * - sales_do.replacement_for_do_id = DO010
 * - sales_do.replacement_return_id = return of DO010
 *
 * Guarded, idempotent, no stock/item/payment/workflow deletion.
 */

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_login();

$u=function_exists('auth_user') ? auth_user() : [];
$role=strtoupper(trim((string)($u['role']??'')));
$dept=strtoupper(trim((string)($u['department']??'')));
$level=strtoupper(trim((string)($u['level']??'')));
if(!in_array($role,['SYS','ADMIN','SUPERADMIN','OWNER'],true) && $dept!=='SYS' && $level!=='SYS'){
    http_response_code(403); exit('FORBIDDEN');
}

$pdo=function_exists('db_pdo') ? db_pdo() : rmi_db_pdo();
function hh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$msg=''; $err=''; $preview=[];

try{
    // Schema guards
    $doCols=$pdo->query("SHOW COLUMNS FROM sales_do")->fetchAll(PDO::FETCH_COLUMN,0)?:[];
    foreach([
        'is_replacement_fulfillment'=>"TINYINT(1) NOT NULL DEFAULT 0",
        'replacement_for_do_id'=>"INT NULL",
        'replacement_return_id'=>"INT NULL",
        'replacement_created_at'=>"DATETIME NULL"
    ] as $c=>$ddl){
        if(!in_array($c,$doCols,true)){
            $pdo->exec("ALTER TABLE sales_do ADD COLUMN `$c` $ddl");
        }
    }

    $retCols=$pdo->query("SHOW COLUMNS FROM sales_do_returns")->fetchAll(PDO::FETCH_COLUMN,0)?:[];
    foreach([
        'commercial_effect'=>"VARCHAR(30) NULL",
        'commercial_effect_set_by'=>"VARCHAR(100) NULL",
        'commercial_effect_set_at'=>"DATETIME NULL",
        'replacement_do_id'=>"INT NULL",
        'replacement_created_at'=>"DATETIME NULL"
    ] as $c=>$ddl){
        if(!in_array($c,$retCols,true)){
            $pdo->exec("ALTER TABLE sales_do_returns ADD COLUMN `$c` $ddl");
        }
    }

    // Exact original + child
    $q=$pdo->prepare("
        SELECT id,do_code,office_code,customers_code,status,
               COALESCE((SELECT SUM(subtotal) FROM sales_do_items i WHERE i.do_id=d.id),0) net
        FROM sales_do d
        WHERE do_code IN ('BMHP-BGR-260902-010','BMHP-BGR-260902-012')
        ORDER BY do_code
    ");
    $q->execute();
    $dos=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){
        $dos[$r['do_code']]=$r;
    }
    if(empty($dos['BMHP-BGR-260902-010']) || empty($dos['BMHP-BGR-260902-012'])){
        throw new Exception('Guard gagal: DO010 atau DO012 tidak ditemukan.');
    }

    $orig=$dos['BMHP-BGR-260902-010'];
    $child=$dos['BMHP-BGR-260902-012'];

    if((int)$orig['id']!==1518) throw new Exception('Guard gagal: ID DO010 bukan 1518.');
    if((int)$child['id']!==1581) throw new Exception('Guard gagal: ID DO012 bukan 1581.');
    if(strtoupper(trim((string)$orig['office_code']))!=='BGR' || strtoupper(trim((string)$child['office_code']))!=='BGR'){
        throw new Exception('Guard gagal: office bukan BGR.');
    }
    if(strtoupper(trim((string)$orig['customers_code']))!=='H007' || strtoupper(trim((string)$child['customers_code']))!=='H007'){
        throw new Exception('Guard gagal: customer bukan H007 pada kedua DO.');
    }
    if(abs((float)$orig['net']-296000)>1) throw new Exception('Guard gagal: net DO010 bukan Rp296.000.');
    if(abs((float)$child['net']-148000)>1) throw new Exception('Guard gagal: net DO012 bukan Rp148.000.');

    // Exact final return for original
    $qr=$pdo->prepare("
        SELECT id,do_id,do_code,status,commercial_effect,replacement_do_id,scm_completed_at
        FROM sales_do_returns
        WHERE do_id=? AND LOWER(TRIM(COALESCE(status,'')))='return_scm_completed'
        ORDER BY id DESC LIMIT 1
    ");
    $qr->execute([(int)$orig['id']]);
    $ret=$qr->fetch(PDO::FETCH_ASSOC);
    if(!$ret) throw new Exception('Guard gagal: return FINAL DO010 tidak ditemukan.');
    $returnId=(int)$ret['id'];
    if($returnId!==13) throw new Exception('Guard gagal: Return ID DO010 bukan 13.');

    // Subset validator: every child item must exist in return FINAL and qty <= returned qty.
    $retMap=[];
    $st=$pdo->prepare("
        SELECT COALESCE(NULLIF(UPPER(TRIM(di.sku)),''),CONCAT('P:',di.product_id)) item_key,
               SUM(ri.qty_return) qty
        FROM sales_do_return_items ri
        JOIN sales_do_items di ON di.id=ri.do_item_id AND di.do_id=ri.do_id
        WHERE ri.return_id=? AND ri.do_id=?
        GROUP BY item_key
    ");
    $st->execute([$returnId,(int)$orig['id']]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){
        $retMap[(string)$r['item_key']] = (float)$r['qty'];
    }

    $childMap=[];
    $st=$pdo->prepare("
        SELECT COALESCE(NULLIF(UPPER(TRIM(sku)),''),CONCAT('P:',product_id)) item_key,
               SUM(qty) qty
        FROM sales_do_items
        WHERE do_id=?
        GROUP BY item_key
    ");
    $st->execute([(int)$child['id']]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){
        $childMap[(string)$r['item_key']] = (float)$r['qty'];
    }

    if(!$retMap || !$childMap) throw new Exception('Guard gagal: item return atau child kosong.');

    foreach($childMap as $k=>$qchild){
        if(!isset($retMap[$k])) throw new Exception("Guard gagal: item child {$k} tidak ada di retur FINAL.");
        if($qchild<=0 || $qchild-$retMap[$k]>0.0001){
            throw new Exception("Guard gagal: qty child {$k} melebihi qty return.");
        }
    }

    $preview=[
        'DO010'=>$orig,
        'RETURN'=>$ret,
        'DO012'=>$child,
        'return_items'=>$retMap,
        'replacement_items'=>$childMap,
    ];

    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(function_exists('require_post')) require_post();
        if(function_exists('verify_csrf')) verify_csrf((string)($_POST['csrf_token'] ?? ''));
        if(empty($_POST['confirm'])) throw new Exception('Konfirmasi wajib dicentang.');

        $actor=(string)($u['username']??$u['name']??'SYS');

        $pdo->beginTransaction();
        try{
            $st=$pdo->prepare("
                UPDATE sales_do_returns
                SET commercial_effect='REPLACEMENT',
                    commercial_effect_set_by=?,
                    commercial_effect_set_at=NOW(),
                    replacement_do_id=?,
                    replacement_created_at=COALESCE(replacement_created_at,NOW()),
                    updated_at=NOW()
                WHERE id=? AND do_id=?
            ");
            $st->execute([$actor,(int)$child['id'],$returnId,(int)$orig['id']]);

            $st=$pdo->prepare("
                UPDATE sales_do
                SET is_replacement_fulfillment=1,
                    replacement_for_do_id=?,
                    replacement_return_id=?,
                    replacement_created_at=COALESCE(replacement_created_at,NOW()),
                    updated_at=NOW()
                WHERE id=?
            ");
            $st->execute([(int)$orig['id'],$returnId,(int)$child['id']]);

            $pdo->commit();
            $msg='SUCCESS: DO010 ditetapkan REPLACEMENT dan DO012 berhasil di-link sebagai fulfillment pengganti. Tidak ada stok/item/payment/workflow yang dihapus.';
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}catch(Throwable $e){
    $err=$e->getMessage();
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Fix DO010 → DO012 Replacement</title>
<style>
body{font-family:Arial;background:#0f172a;color:#e2e8f0;padding:28px}.c{max-width:900px;margin:auto;background:#111827;border:1px solid #334155;border-radius:14px;padding:20px}
.ok{background:#064e3b;padding:12px;border-radius:8px}.er{background:#7f1d1d;padding:12px;border-radius:8px}
pre{white-space:pre-wrap;background:#020617;padding:12px;border-radius:8px}button{padding:10px 16px;font-weight:bold}
</style></head>
<body><div class="c">
<h2>One-Time Fix DO010 → DO012</h2>
<p>Menetapkan DO010 sebagai <b>REPLACEMENT</b> dan menghubungkan DO012 sebagai fulfillment replacement existing.</p>
<?php if($msg):?><div class="ok"><?=hh($msg)?></div><?php endif;?>
<?php if($err):?><div class="er"><?=hh($err)?></div><?php endif;?>
<?php if($preview):?>
<pre><?=hh(json_encode($preview,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre>
<form method="post" onsubmit="return confirm('Jalankan repair DO010 → DO012?')">
<input type="hidden" name="csrf_token" value="<?=hh(function_exists('csrf_token')?csrf_token():'')?>">
<label><input type="checkbox" name="confirm" value="1" required> Saya konfirmasi DO012 adalah fulfillment pengganti dari retur FINAL DO010.</label><br><br>
<button>Jalankan Fix DO010 → DO012</button>
</form>
<?php endif;?>
<p>Setelah SUCCESS: hapus file ini, lalu refresh Executive Summary 08-09-2026.</p>
</div></body></html>
