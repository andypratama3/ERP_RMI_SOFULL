<?php
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
$msg='';$err='';
try{
    $cols=$pdo->query("SHOW COLUMNS FROM sales_do_returns")->fetchAll(PDO::FETCH_COLUMN,0)?:[];
    if(!in_array('commercial_effect',$cols,true)) $pdo->exec("ALTER TABLE sales_do_returns ADD COLUMN commercial_effect VARCHAR(30) NULL");
    if(!in_array('commercial_effect_set_by',$cols,true)) $pdo->exec("ALTER TABLE sales_do_returns ADD COLUMN commercial_effect_set_by VARCHAR(100) NULL");
    if(!in_array('commercial_effect_set_at',$cols,true)) $pdo->exec("ALTER TABLE sales_do_returns ADD COLUMN commercial_effect_set_at DATETIME NULL");

    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(function_exists('require_post')) require_post();
        if(function_exists('verify_csrf')) verify_csrf((string)($_POST['csrf_token'] ?? ''));
        if(empty($_POST['confirm'])) throw new Exception('Konfirmasi wajib dicentang.');
        $actor=(string)($u['username']??$u['name']??'SYS');

        $q=$pdo->prepare("
            SELECT r.id return_id,d.id do_id,d.do_code,d.customers_code,
                   COALESCE(SUM(i.subtotal),0) net,r.status,r.commercial_effect
            FROM sales_do d
            JOIN sales_do_returns r ON r.do_id=d.id
            JOIN sales_do_items i ON i.do_id=d.id
            WHERE d.do_code='BMHP-BGR-260902-004'
              AND UPPER(TRIM(d.customers_code))='H018'
            GROUP BY r.id,d.id,d.do_code,d.customers_code,r.status,r.commercial_effect
            LIMIT 1
        ");
        $q->execute();
        $r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r) throw new Exception('DO004/return tidak ditemukan.');
        if(abs((float)$r['net']-604080)>1) throw new Exception('Guard gagal: net bukan Rp604.080.');
        if(strtolower(trim((string)$r['status']))!=='return_scm_completed') throw new Exception('Guard gagal: retur belum FINAL SCM.');

        $st=$pdo->prepare("UPDATE sales_do_returns
                           SET commercial_effect='OPERATIONAL_ONLY',
                               commercial_effect_set_by=?,
                               commercial_effect_set_at=NOW(),
                               updated_at=NOW()
                           WHERE id=?");
        $st->execute([$actor,(int)$r['return_id']]);
        $msg='SUCCESS: DO004 menjadi OPERATIONAL_ONLY. Retur stok/history tetap ada, Rp604.080 tidak lagi mengurangi achievement.';
    }
}catch(Throwable $e){$err=$e->getMessage();}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Fix DO004 Commercial Effect</title>
<style>body{font-family:Arial;background:#0f172a;color:#e2e8f0;padding:28px}.c{max-width:800px;margin:auto;background:#111827;border:1px solid #334155;border-radius:14px;padding:20px}.ok{background:#064e3b;padding:10px}.er{background:#7f1d1d;padding:10px}button{padding:10px 16px;font-weight:bold}</style></head>
<body><div class="c"><h2>One-Time Fix DO004</h2>
<p>Menetapkan <b>BMHP-BGR-260902-004</b> sebagai <b>OPERATIONAL_ONLY</b>. Tidak mengubah stok, qty, item, payment, atau nomor DO.</p>
<?php if($msg):?><div class="ok"><?=hh($msg)?></div><?php endif;?>
<?php if($err):?><div class="er"><?=hh($err)?></div><?php endif;?>
<form method="post" onsubmit="return confirm('Jalankan koreksi DO004?')">
<input type="hidden" name="csrf_token" value="<?=hh(function_exists('csrf_token')?csrf_token():'')?>">
<label><input type="checkbox" name="confirm" value="1" required> Saya konfirmasi retur DO004 hanya operasional dan penjualan tetap diakui.</label><br><br>
<button>Jalankan Fix</button>
</form>
<p>Setelah SUCCESS, hapus file ini dan refresh Executive Summary tanggal 07/08 September.</p>
</div></body></html>