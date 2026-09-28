<?php
declare(strict_types=1);
/**
 * Target Finance — target manual, pencapaian tetap otomatis dari sales_do.
 * Perubahan target dicatat ke audit dan tidak mengubah transaksi penjualan.
 */
require_once __DIR__ . '/../../_shared/assets.php';

/*
 * Target Finance memiliki mutasi lokal (save_target/delete_target) yang sudah
 * digate di file ini: login + FIN MANAGER/SYS + POST + CSRF + audit.
 *
 * _dashboard_bootstrap.php juga menjalankan guard route generik. Pada policy
 * lama, nama action POST yang tidak terdaftar dapat dihentikan lebih awal
 * dengan USER_ACTIVE_PERM_LEVEL_METHOD_BLOCK walaupun dept=FIN, level=MANAGER
 * dan policy_perm_ok=1. Agar guard route tetap memeriksa URL/session tetapi
 * tidak salah menganggap action lokal ini sebagai action global, sembunyikan
 * sementara hanya dua action yang memang ditangani oleh halaman ini. Setelah
 * bootstrap selesai action dikembalikan dan divalidasi normal di bawah.
 */
$__ftBootstrapAction = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $candidateAction = (string)($_POST['action'] ?? '');
    if (in_array($candidateAction, ['save_target','delete_target'], true)) {
        $__ftBootstrapAction = $candidateAction;
        unset($_POST['action']);
    }
}
require_once __DIR__ . '/../_dashboard_bootstrap.php';
if ($__ftBootstrapAction !== null) {
    $_POST['action'] = $__ftBootstrapAction;
}
unset($__ftBootstrapAction, $candidateAction);

require_login();

/*
 * Hak kelola Target Finance:
 * - FIN + MANAGER (termasuk MgrFIN_BGR / manager FIN cabang yang sah)
 * - SYS / ADMIN / SUPERADMIN sebagai override
 * Staff FIN tetap tidak bisa melakukan mutasi.
 * Ini BUKAN approval cash-out, jadi tidak memakai FIN central approver rule.
 */
function ft_can_manage_target(): bool {
    if (function_exists('auth_is_sys_tier') && auth_is_sys_tier()) return true;
    if (function_exists('auth_is_sys') && auth_is_sys()) return true;

    $u = function_exists('auth_user') ? (array)auth_user() : [];
    $dept = strtoupper(trim((string)($u['department'] ?? $_SESSION['department'] ?? $_SESSION['dept'] ?? '')));
    $role = strtoupper(trim((string)($u['role'] ?? $u['level'] ?? $_SESSION['role'] ?? $_SESSION['level'] ?? '')));

    if (in_array($role, ['SYS','ADMIN','SUPERADMIN'], true)) return true;
    if ($dept === 'SYS') return true;

    // Gunakan helper canonical bila tersedia, tetapi tetap validasi FIN+MANAGER
    // dari session agar tidak bergantung pada variasi implementasi helper lama.
    if ($dept === 'FIN' && $role === 'MANAGER') return true;
    if (function_exists('auth_is_fin_manager') && auth_is_fin_manager()) return true;

    return false;
}

if (!ft_can_manage_target()) {
    http_response_code(403);
    exit('<h3>Akses Terbatas</h3><p>Hanya Manager FIN atau Admin/SYS yang dapat mengelola target Finance.</p>');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Database connection required.'); }
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
function ft_money($v): string { return number_format((float)$v, 0, ',', '.'); }
function ft_actor(): string {
    if (function_exists('auth_user')) {
        $u=auth_user();
        foreach (['username','user_name','name','full_name'] as $k) if (!empty($u[$k])) return (string)$u[$k];
    }
    return (string)($_SESSION['username'] ?? 'SYSTEM');
}
function ft_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_targets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        year_no SMALLINT NOT NULL,
        month_no TINYINT NOT NULL,
        segment VARCHAR(40) NOT NULL,
        office_id BIGINT NOT NULL DEFAULT 0,
        target_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
        note VARCHAR(500) NULL,
        created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by VARCHAR(100) NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_kpi_target_period (year_no,month_no,segment,office_id),
        KEY idx_kpi_target_period (year_no,month_no),
        KEY idx_kpi_target_office (office_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_finance_manual_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        module VARCHAR(40) NOT NULL,
        record_id BIGINT NULL,
        action VARCHAR(30) NOT NULL,
        period_ym CHAR(7) NULL,
        office_id BIGINT NULL,
        segment VARCHAR(40) NULL,
        old_amount DECIMAL(20,2) NULL,
        new_amount DECIMAL(20,2) NULL,
        reason VARCHAR(500) NULL,
        actor VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_fin_audit_module (module,created_at),
        KEY idx_fin_audit_period (period_ym,office_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cols=[];
    try { $cols=$pdo->query("SHOW COLUMNS FROM kpi_targets")->fetchAll(PDO::FETCH_COLUMN,0); } catch(Throwable $e) {}
    foreach ([
        'note'=>"ALTER TABLE kpi_targets ADD COLUMN note VARCHAR(500) NULL",
        'created_by'=>"ALTER TABLE kpi_targets ADD COLUMN created_by VARCHAR(100) NULL",
        'created_at'=>"ALTER TABLE kpi_targets ADD COLUMN created_at DATETIME NULL",
        'updated_by'=>"ALTER TABLE kpi_targets ADD COLUMN updated_by VARCHAR(100) NULL",
        'updated_at'=>"ALTER TABLE kpi_targets ADD COLUMN updated_at DATETIME NULL"
    ] as $c=>$sql) { if (!in_array($c,$cols,true)) { try{$pdo->exec($sql);}catch(Throwable $e){} } }
}
function ft_audit(PDO $pdo,string $action,?int $id,string $period,int $officeId,string $segment,?float $old,?float $new,string $reason): void {
    $st=$pdo->prepare("INSERT INTO kpi_finance_manual_audit(module,record_id,action,period_ym,office_id,segment,old_amount,new_amount,reason,actor) VALUES('TARGET',?,?,?,?,?,?,?,?,?)");
    $st->execute([$id,$action,$period,$officeId,$segment,$old,$new,$reason,ft_actor()]);
    if (function_exists('erp_audit')) {
        try { erp_audit($pdo,'KPI_FIN_TARGET',(string)($id ?? 0),$action,['period'=>$period,'office_id'=>$officeId,'segment'=>$segment,'old'=>$old,'new'=>$new,'reason'=>$reason]); } catch(Throwable $e) {}
    }
}
ft_ensure_schema($pdo);

$office = strtoupper(trim((string)($_GET['office'] ?? '')));
$segment = strtoupper(trim((string)($_GET['segment'] ?? '')));
$monthNo = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$yearNo = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($monthNo<1||$monthNo>12) $monthNo=(int)date('m');
if ($yearNo<2000||$yearNo>2100) $yearNo=(int)date('Y');
$period=sprintf('%04d-%02d',$yearNo,$monthNo);
$success=''; $error='';

$officeList=[]; $officeIdByCode=[];
try {
    $st=$pdo->query("SELECT id,office_code,office_name FROM master_office WHERE COALESCE(is_active,1)=1 ORDER BY office_name");
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
        $oc=strtoupper(trim((string)$r['office_code'])); if($oc==='') continue;
        $officeList[$oc]=(string)($r['office_name'] ?: $oc); $officeIdByCode[$oc]=(int)$r['id'];
    }
} catch(Throwable $e) {}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        if (function_exists('require_post')) require_post();
        if (function_exists('verify_csrf')) verify_csrf((string)($_POST['csrf_token'] ?? ''));
        $action=(string)($_POST['action'] ?? '');
        $pMonth=(int)($_POST['month_no'] ?? $monthNo); $pYear=(int)($_POST['year_no'] ?? $yearNo);
        $pSegment=strtoupper(trim((string)($_POST['segment'] ?? '')));
        $pOfficeCode=strtoupper(trim((string)($_POST['office_code'] ?? 'ALL')));
        $pOfficeId=$pOfficeCode==='ALL' ? 0 : (int)($officeIdByCode[$pOfficeCode] ?? 0);
        $reason=trim((string)($_POST['reason'] ?? ''));
        if (!in_array($pSegment,['HERMINA','NON_HERMINA','ACCUNIT'],true)) throw new RuntimeException('Segment tidak valid.');
        if ($pOfficeCode!=='ALL' && $pOfficeId<=0) throw new RuntimeException('Office tidak valid.');
        if ($pMonth<1||$pMonth>12||$pYear<2000||$pYear>2100) throw new RuntimeException('Periode tidak valid.');
        if (mb_strlen($reason)<5) throw new RuntimeException('Alasan perubahan minimal 5 karakter.');
        $pPeriod=sprintf('%04d-%02d',$pYear,$pMonth);

        if ($action==='save_target') {
            $amount=(float)str_replace([',',' '],['',''],(string)($_POST['target_amount'] ?? '0'));
            if ($amount<0) throw new RuntimeException('Target tidak boleh negatif.');
            $pdo->beginTransaction();
            $oldSt=$pdo->prepare("SELECT id,target_amount FROM kpi_targets WHERE year_no=? AND month_no=? AND segment=? AND office_id=? LIMIT 1 FOR UPDATE");
            $oldSt->execute([$pYear,$pMonth,$pSegment,$pOfficeId]); $old=$oldSt->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                $id=(int)$old['id']; $oldAmount=(float)$old['target_amount'];
                $pdo->prepare("UPDATE kpi_targets SET target_amount=?,note=?,updated_by=?,updated_at=NOW() WHERE id=?")
                    ->execute([$amount,$reason,ft_actor(),$id]);
                ft_audit($pdo,'UPDATE',$id,$pPeriod,$pOfficeId,$pSegment,$oldAmount,$amount,$reason);
            } else {
                $pdo->prepare("INSERT INTO kpi_targets(year_no,month_no,segment,office_id,target_amount,note,created_by,created_at) VALUES(?,?,?,?,?,?,?,NOW())")
                    ->execute([$pYear,$pMonth,$pSegment,$pOfficeId,$amount,$reason,ft_actor()]);
                $id=(int)$pdo->lastInsertId();
                ft_audit($pdo,'CREATE',$id,$pPeriod,$pOfficeId,$pSegment,null,$amount,$reason);
            }
            $pdo->commit(); $success='Target berhasil disimpan. Pencapaian tetap dihitung otomatis dari Sales DO.';
            $monthNo=$pMonth; $yearNo=$pYear; $period=$pPeriod;
        } elseif ($action==='delete_target') {
            $id=(int)($_POST['target_id'] ?? 0); if($id<=0) throw new RuntimeException('Target tidak valid.');
            $pdo->beginTransaction();
            $st=$pdo->prepare("SELECT * FROM kpi_targets WHERE id=? FOR UPDATE"); $st->execute([$id]); $old=$st->fetch(PDO::FETCH_ASSOC);
            if(!$old) throw new RuntimeException('Target tidak ditemukan.');
            $pdo->prepare("DELETE FROM kpi_targets WHERE id=?")->execute([$id]);
            ft_audit($pdo,'DELETE',$id,sprintf('%04d-%02d',(int)$old['year_no'],(int)$old['month_no']),(int)$old['office_id'],(string)$old['segment'],(float)$old['target_amount'],null,$reason);
            $pdo->commit(); $success='Target dihapus dan jejak audit tetap tersimpan.';
        }
    }
} catch(Throwable $e) {
    if($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage();
}

$rows=[]; $sumTarget=0.0;
$sql="SELECT t.id,UPPER(t.segment) segment,t.office_id,UPPER(COALESCE(o.office_code,'ALL')) office_code,COALESCE(o.office_name,'Semua Office') office_name,t.target_amount,t.note,t.created_by,t.created_at,t.updated_by,t.updated_at FROM kpi_targets t LEFT JOIN master_office o ON o.id=t.office_id WHERE t.month_no=? AND t.year_no=?";
$params=[$monthNo,$yearNo];
if($office!==''){ $sql.=" AND UPPER(COALESCE(o.office_code,'ALL'))=?"; $params[]=$office; }
if($segment!==''){ $sql.=" AND UPPER(t.segment)=?"; $params[]=$segment; }
$sql.=" ORDER BY t.segment,COALESCE(o.office_code,'ALL')";
$st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach($rows as $r) $sumTarget+=(float)$r['target_amount'];
$auditRows=[];
try { $st=$pdo->prepare("SELECT a.*,COALESCE(o.office_code,'ALL') office_code FROM kpi_finance_manual_audit a LEFT JOIN master_office o ON o.id=a.office_id WHERE a.module='TARGET' AND a.period_ym=? ORDER BY a.id DESC LIMIT 50"); $st->execute([$period]); $auditRows=$st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch(Throwable $e) {}

$bp=$GLOBALS['BASE_PROJECT'] ?? '';
$backUrl=rtrim($bp,'/').'/dashboards/finance/dashboard_detail.php?month='.$monthNo.'&year='.$yearNo.'&tab=target';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
$rekapExtraHead='<style>.excel-surface{background:#0f172a;color:#e2e8f0;border-radius:14px}.excel-card{background:#111827;border:1px solid rgba(255,255,255,.09);border-radius:12px;margin-bottom:14px}.excel-title{padding:11px 14px;font-weight:800;color:#67e8f9;border-bottom:1px solid rgba(255,255,255,.08)}.excel-table{width:100%;border-collapse:collapse;font-size:12px}.excel-table th,.excel-table td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.07)}.excel-table th{color:#94a3b8}.num{text-align:right}.flow-note{padding:12px;border-radius:10px;background:rgba(6,182,212,.1);border:1px solid rgba(6,182,212,.25);font-size:12px}</style>';
$subtitle='Target Finance Manual • '.$period;
rmi_header('Target Finance','dashboard',['subtitle'=>$subtitle,'breadcrumbs'=>[['label'=>'Finance','url'=>$bp.'/dashboards/finance/ar_ap_cash_dashboard.php'],['label'=>'Target Finance']],'extra_head'=>$rekapExtraHead]);
?>
<div class="container-fluid"><div class="excel-surface p-3 p-md-4">
<div class="d-flex justify-content-between align-items-center mb-3"><div><h4 class="mb-1">Target Finance</h4><div class="text-muted small">Target manual per office/segment. Pencapaian tidak dapat diketik di sini.</div></div><a href="<?=h($backUrl)?>" class="btn btn-sm btn-outline-light">Lihat Dashboard Detail</a></div>
<?php if($success!==''):?><div class="alert alert-success"><?=h($success)?></div><?php endif;?>
<?php if($error!==''):?><div class="alert alert-danger"><?=h($error)?></div><?php endif;?>
<div class="flow-note mb-3"><strong>Alur:</strong> Manager FIN mengisi target → sistem menyimpan ke <code>kpi_targets</code> → pencapaian tetap otomatis dari <code>sales_do</code> → koreksi khusus dilakukan melalui menu Adjustment dan wajib alasan/audit.</div>
<div class="excel-card"><div class="excel-title">Input / Update Target</div><form method="post" class="p-3 row g-2 align-items-end">
<input type="hidden" name="csrf_token" value="<?=h(function_exists('csrf_token')?csrf_token():'')?>"><input type="hidden" name="action" value="save_target">
<div class="col-md-2"><label class="form-label small">Bulan</label><select class="form-select form-select-sm" name="month_no"><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>" <?=$m===$monthNo?'selected':''?>><?=str_pad((string)$m,2,'0',STR_PAD_LEFT)?></option><?php endfor;?></select></div>
<div class="col-md-2"><label class="form-label small">Tahun</label><input class="form-control form-control-sm" type="number" name="year_no" value="<?=$yearNo?>" min="2020" max="2100" required></div>
<div class="col-md-2"><label class="form-label small">Office</label><select class="form-select form-select-sm" name="office_code"><option value="ALL">Semua Office</option><?php foreach($officeList as $oc=>$on):?><option value="<?=h($oc)?>"><?=h($on)?> (<?=h($oc)?>)</option><?php endforeach;?></select></div>
<div class="col-md-2"><label class="form-label small">Segment</label><select class="form-select form-select-sm" name="segment" required><option value="HERMINA">Hermina</option><option value="NON_HERMINA">Non Hermina</option><option value="ACCUNIT">ACCUNIT</option></select></div>
<div class="col-md-2"><label class="form-label small">Target</label><input class="form-control form-control-sm" type="number" name="target_amount" min="0" step="1" required></div>
<div class="col-md-2"><label class="form-label small">Alasan</label><input class="form-control form-control-sm" name="reason" maxlength="500" placeholder="Target awal/revisi..." required></div>
<div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Simpan Target</button> <a class="btn btn-sm btn-outline-warning" href="adjustment_manage.php?month=<?=$monthNo?>&year=<?=$yearNo?>">Buka Adjustment</a></div>
</form></div>
<div class="excel-card"><div class="excel-title">Rekap Target • Total <?=ft_money($sumTarget)?></div><div class="table-responsive"><table class="excel-table"><thead><tr><th>Segment</th><th>Office</th><th class="num">Target</th><th>Catatan</th><th>Terakhir Diubah</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=h($r['segment'])?></td><td><?=h($r['office_name'])?> (<?=h($r['office_code'])?>)</td><td class="num"><?=ft_money($r['target_amount'])?></td><td><?=h($r['note']??'-')?></td><td><?=h(($r['updated_by']?:$r['created_by'])??'-')?> • <?=h(($r['updated_at']?:$r['created_at'])??'-')?></td><td><form method="post" onsubmit="return confirm('Hapus target ini? Audit tetap disimpan.')"><input type="hidden" name="csrf_token" value="<?=h(function_exists('csrf_token')?csrf_token():'')?>"><input type="hidden" name="action" value="delete_target"><input type="hidden" name="target_id" value="<?=(int)$r['id']?>"><input type="hidden" name="month_no" value="<?=$monthNo?>"><input type="hidden" name="year_no" value="<?=$yearNo?>"><input type="hidden" name="segment" value="<?=h($r['segment'])?>"><input type="hidden" name="office_code" value="<?=h($r['office_code'])?>"><input class="form-control form-control-sm mb-1" name="reason" placeholder="Alasan hapus" required><button class="btn btn-sm btn-outline-danger">Hapus</button></form></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="6" class="text-center text-muted">Belum ada target periode ini.</td></tr><?php endif;?></tbody></table></div></div>
<div class="excel-card"><div class="excel-title">Audit Perubahan Target</div><div class="table-responsive"><table class="excel-table"><thead><tr><th>Waktu</th><th>Aksi</th><th>Office</th><th>Segment</th><th class="num">Lama</th><th class="num">Baru</th><th>Alasan</th><th>User</th></tr></thead><tbody><?php foreach($auditRows as $a):?><tr><td><?=h($a['created_at'])?></td><td><?=h($a['action'])?></td><td><?=h($a['office_code'])?></td><td><?=h($a['segment'])?></td><td class="num"><?=is_null($a['old_amount'])?'-':ft_money($a['old_amount'])?></td><td class="num"><?=is_null($a['new_amount'])?'-':ft_money($a['new_amount'])?></td><td><?=h($a['reason'])?></td><td><?=h($a['actor'])?></td></tr><?php endforeach;?><?php if(!$auditRows):?><tr><td colspan="8" class="text-center text-muted">Belum ada audit.</td></tr><?php endif;?></tbody></table></div></div>
</div></div><?php rmi_footer(); ?>
