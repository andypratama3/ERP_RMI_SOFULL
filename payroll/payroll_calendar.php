<?php
declare(strict_types=1);
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';

payroll_ensure_schema($pdo);
$month = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
[$start,$end] = payroll_parse_month($month) ?: [date('Y-m-01'),date('Y-m-t')];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        $id=(int)($_POST['id']??0);
        $date=trim((string)($_POST['holiday_date']??''));
        $name=trim((string)($_POST['holiday_name']??''));
        $office=strtoupper(trim((string)($_POST['office_code']??'')));
        $type=strtoupper(trim((string)($_POST['holiday_type']??'NATIONAL')));
        $note=trim((string)($_POST['note']??''));
        $active=isset($_POST['is_active'])?1:0;
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || $name==='') {
            payroll_flash_set('danger','Tanggal dan nama kalender kerja wajib diisi.');
        } elseif(!in_array($type,['NATIONAL','COMPANY','OFFICE','CUTI_BERSAMA'],true)) {
            payroll_flash_set('danger','Jenis kalender kerja tidak valid.');
        } else {
            $officeDb=$office!==''?$office:null;
            $dup=$pdo->prepare("SELECT id FROM payroll_holidays WHERE holiday_date=? AND COALESCE(office_code,'')=COALESCE(?,'') AND id<>? LIMIT 1");
            $dup->execute([$date,$officeDb,$id]);
            if($dup->fetchColumn()) {
                payroll_flash_set('danger','Tanggal dan scope office tersebut sudah terdaftar. Edit data yang sudah ada.');
            } else {
                if($id>0){
                    $st=$pdo->prepare("UPDATE payroll_holidays SET holiday_date=?,holiday_name=?,office_code=?,holiday_type=?,is_active=?,note=?,updated_at=NOW(),updated_by=? WHERE id=?");
                    $st->execute([$date,$name,$officeDb,$type,$active,$note?:null,(int)($_SESSION['user_id']??0),$id]);
                    $auditAction='UPDATE_CALENDAR';
                } else {
                    $st=$pdo->prepare("INSERT INTO payroll_holidays(holiday_date,holiday_name,office_code,holiday_type,is_active,note,created_by,created_at) VALUES(?,?,?,?,?,?,?,NOW())");
                    $st->execute([$date,$name,$officeDb,$type,$active,$note?:null,(int)($_SESSION['user_id']??0)]);
                    $id=(int)$pdo->lastInsertId(); $auditAction='CREATE_CALENDAR';
                }
                erp_audit($pdo,'PAYROLL_CALENDAR','HOLIDAY#'.$id,strtolower($auditAction),['date'=>$date,'office'=>$officeDb,'type'=>$type,'active'=>$active]);
                payroll_flash_set('success','Kalender kerja berhasil disimpan. Jalankan Sync Komponen Payroll pada run DRAFT.');
            }
        }
        rmi_redirect($BASE_PAYROLL.'/payroll_calendar.php?month='.substr($date?:$month,0,7));
    }
    if ($action === 'toggle') {
        $id=(int)($_POST['id']??0);
        $pdo->prepare("UPDATE payroll_holidays SET is_active=IF(is_active=1,0,1),updated_at=NOW(),updated_by=? WHERE id=?")->execute([(int)($_SESSION['user_id']??0),$id]);
        erp_audit($pdo,'PAYROLL_CALENDAR','HOLIDAY#'.$id,'toggle',null);
        payroll_flash_set('success','Status kalender kerja diperbarui.');
        rmi_redirect($BASE_PAYROLL.'/payroll_calendar.php?month='.$month);
    }
}

$edit=null;
if((int)($_GET['edit']??0)>0){$st=$pdo->prepare('SELECT * FROM payroll_holidays WHERE id=?');$st->execute([(int)$_GET['edit']]);$edit=$st->fetch(PDO::FETCH_ASSOC)?:null;}
$st=$pdo->prepare("SELECT * FROM payroll_holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date,COALESCE(office_code,''),id");
$st->execute([$start,$end]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
$flash=payroll_flash_get();
rmi_header('Kalender Kerja Payroll','payroll',['base_project'=>$BASE_PROJECT,'breadcrumbs'=>[['label'=>'Payroll','url'=>$BASE_PAYROLL.'/index.php'],'Kalender Kerja']]);
?>
<?php if($flash): ?><div class="alert alert-<?= payroll_h($flash['type']) ?>"><?= payroll_h($flash['msg']) ?></div><?php endif; ?>
<div class="row g-3">
 <div class="col-lg-4"><div class="card rmi-card"><div class="card-body">
  <h5><?= $edit?'Edit':'Tambah' ?> Kalender Kerja</h5>
  <form method="post">
   <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token')?payroll_h(csrf_token()):'' ?>">
   <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>">
   <label class="form-label">Tanggal</label><input class="form-control mb-2" type="date" name="holiday_date" required value="<?= payroll_h($edit['holiday_date']??($month.'-01')) ?>">
   <label class="form-label">Nama</label><input class="form-control mb-2" name="holiday_name" required value="<?= payroll_h($edit['holiday_name']??'') ?>">
   <label class="form-label">Jenis</label><select class="form-select mb-2" name="holiday_type"><?php foreach(['NATIONAL'=>'Libur Nasional','CUTI_BERSAMA'=>'Cuti Bersama','COMPANY'=>'Libur Perusahaan','OFFICE'=>'Libur Khusus Office'] as $k=>$v): ?><option value="<?= $k ?>" <?= (($edit['holiday_type']??'NATIONAL')===$k)?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select>
   <label class="form-label">Office</label><input class="form-control mb-2" name="office_code" placeholder="Kosong = semua office" value="<?= payroll_h($edit['office_code']??'') ?>">
   <label class="form-label">Catatan</label><textarea class="form-control mb-2" name="note"><?= payroll_h($edit['note']??'') ?></textarea>
   <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" <?= !isset($edit['is_active'])||(int)$edit['is_active']===1?'checked':'' ?>> <span class="form-check-label">Aktif</span></label>
   <button class="btn btn-primary" type="submit">Simpan</button> <a class="btn btn-outline-secondary" href="?month=<?= payroll_h($month) ?>">Reset</a>
  </form>
 </div></div></div>
 <div class="col-lg-8"><div class="card rmi-card"><div class="card-body">
  <div class="d-flex justify-content-between align-items-end mb-3"><div><h5 class="mb-1">Daftar Kalender Kerja</h5><small class="rmi-muted">Tanggal aktif mengurangi hari kerja dan tidak dihitung sebagai OP reguler.</small></div><form method="get"><input class="form-control" type="month" name="month" value="<?= payroll_h($month) ?>" onchange="this.form.submit()"></form></div>
  <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Tanggal</th><th>Nama</th><th>Jenis</th><th>Office</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
  <?php if(!$rows): ?><tr><td colspan="6" class="rmi-muted">Belum ada kalender pada bulan ini. Hari kerja mengikuti Senin–Jumat.</td></tr><?php endif; ?>
  <?php foreach($rows as $r): ?><tr><td><?= payroll_h(date('d-m-Y',strtotime($r['holiday_date']))) ?></td><td><?= payroll_h($r['holiday_name']) ?></td><td><?= payroll_h($r['holiday_type']) ?></td><td><?= payroll_h($r['office_code']?:'SEMUA') ?></td><td><span class="badge bg-<?= (int)$r['is_active']===1?'success':'secondary' ?>"><?= (int)$r['is_active']===1?'AKTIF':'NONAKTIF' ?></span></td><td><a class="btn btn-sm btn-outline-info" href="?month=<?= payroll_h($month) ?>&edit=<?= (int)$r['id'] ?>">Edit</a> <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token')?payroll_h(csrf_token()):'' ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-warning">Aktif/Nonaktif</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="alert alert-info mb-0"><b>Alur:</b> isi kalender → buka Payroll Run DRAFT → Sync Komponen Payroll → periksa hari belum terklasifikasi → koreksi HRL → Sync ulang → Post Payroll.</div>
 </div></div></div>
</div>
<?php rmi_footer(); ?>
