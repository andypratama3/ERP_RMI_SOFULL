<?php
require_once __DIR__ . '/_inc/layout.php';
require_once __DIR__ . '/_inc/fa_helpers.php';

// Explicit auth guard (for static scan + safety)
if (function_exists('require_login')) { require_login(); }

fa_preflight_or_die($pdo);
rbac_require(['FIXED_ASSET.AUDIT_VIEW', 'FIXED_ASSET.AUDIT']);


$master_offices = fa_master_offices($pdo);
$a = $_GET['a'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// create new audit
if ($_SERVER['REQUEST_METHOD']==='POST') {
  fa_csrf_verify();
  $act = $_POST['_action'] ?? '';

  if ($act === 'create_audit') {
    $office = trim($_POST['office_code'] ?? '');
    $date = $_POST['audit_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    $code = 'AUD-FA-'.date('ymd').'-'.substr(strtoupper(bin2hex(random_bytes(2))),0,4);

    $pdo->beginTransaction();
    try{
      $pdo->prepare("INSERT INTO fa_audits(audit_code,office_code,audit_date,status,created_by,notes) VALUES (?,?,?,?,?,?)")
        ->execute([$code,$office,$date,'OPEN',(int)($_SESSION['user_id'] ?? 0),$notes]);
      $audit_id=(int)$pdo->lastInsertId();

      // seed lines: assets in office (and not deleted)
      $stmt=$pdo->prepare("SELECT id FROM fa_assets WHERE deleted_at IS NULL AND (office_code=? OR ?='')");
      $stmt->execute([$office,$office]);
      $asset_ids=$stmt->fetchAll();

      $ins=$pdo->prepare("INSERT INTO fa_audit_lines(audit_id,asset_id,physical_status) VALUES (?,?, 'NOT_FOUND')");
      foreach($asset_ids as $r){
        $ins->execute([$audit_id,(int)$r['id']]);
      }

      fa_log($pdo,'CREATE','AUDIT',$audit_id,['code'=>$code,'office'=>$office,'lines'=>count($asset_ids)]);
      $pdo->commit();
      flash_set('success','Audit dibuat: '.$code);
      redirect_to('audit.php?a=entry&id='.$audit_id);
    }catch(Throwable $e){
      $pdo->rollBack();
      flash_set('danger','Gagal membuat audit: '.$e->getMessage());
      redirect_to('audit.php');
    }
  }

  if ($act === 'save_lines' && $id>0) {
    // save statuses
    $lines = $_POST['line'] ?? [];
    $pdo->beginTransaction();
    try{
      $upd=$pdo->prepare("UPDATE fa_audit_lines SET physical_status=?, note=?, updated_at=NOW() WHERE id=?");
      foreach($lines as $line_id=>$data){
        $st = $data['st'] ?? 'NOT_FOUND';
        $note = $data['note'] ?? '';
        $upd->execute([$st,$note,(int)$line_id]);
      }
      fa_log($pdo,'UPDATE','AUDIT_LINES',$id,['count'=>count($lines)]);
      $pdo->commit();
      flash_set('success','Hasil audit tersimpan.');
    }catch(Throwable $e){
      $pdo->rollBack();
      flash_set('danger','Gagal simpan: '.$e->getMessage());
    }
    redirect_to('audit.php?a=entry&id='.$id);
  }

  if ($act === 'close_audit' && $id>0) {
    $pdo->prepare("UPDATE fa_audits SET status='CLOSED', closed_by=?, closed_at=NOW() WHERE id=?")->execute([(int)($_SESSION['user_id'] ?? 0),$id]);
    fa_log($pdo,'CLOSE','AUDIT',$id);
    flash_set('success','Audit ditutup.');
    redirect_to('audit.php?a=report&id='.$id);
  }
}

fa_header('Audit / Stock Opname');

if ($a==='new') {
?>
<div class="card bg-white p-4">
  <div class="h6">Buat Audit Baru</div>
  <form method="post" class="mt-2">
    <input type="hidden" name="_action" value="create_audit">
    <?= fa_csrf_input() ?>
    <div class="row g-2">
      <div class="col-md-4">
        <label class="form-label">Office</label>
        <?php if(!empty($master_offices)): ?>
          <select class="form-select" name="office_code">
            <option value="">ALL / Semua office</option>
            <?= fa_build_options($master_offices, 'office_code', 'office_name', ($_POST['office_code'] ?? '')) ?>
          </select>
        <?php else: ?>
          <input class="form-control" name="office_code" value="<?= h($_POST['office_code'] ?? '') ?>" placeholder="BOGOR (kosong = semua)">
        <?php endif; ?>
      </div>
      <div class="col-md-4">
        <label class="form-label">Tanggal Audit</label>
        <input type="date" class="form-control" name="audit_date" value="<?= h(date('Y-m-d')) ?>">
      </div>
      <div class="col-md-12">
        <label class="form-label">Notes</label>
        <input class="form-control" name="notes">
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Buat Audit</button>
        <a class="btn btn-outline-secondary" href="audit.php">Batal</a>
      </div>
    </div>
  </form>
</div>
<?php fa_footer(); exit; }

if ($a==='entry' && $id>0) {
  $audit=$pdo->prepare("SELECT * FROM fa_audits WHERE id=?");
  $audit->execute([$id]);
  $audit=$audit->fetch();
  if(!$audit){ flash_set('danger','Audit tidak ditemukan.'); redirect_to('audit.php'); }

  $lines=$pdo->prepare("SELECT l.id line_id, l.physical_status, l.note, a.asset_code, a.asset_name, a.office_code, a.dept_code
      FROM fa_audit_lines l JOIN fa_assets a ON a.id=l.asset_id WHERE l.audit_id=? ORDER BY a.asset_code");
  $lines->execute([$id]);
  $lines=$lines->fetchAll();
?>
<div class="card bg-white p-3">
  <div class="d-flex justify-content-between align-items-center">
    <div>
      <div class="h6 mb-0">Entry Audit: <?= h($audit['audit_code']) ?></div>
      <div class="text-muted small">Office: <?= h($audit['office_code']) ?> • Date: <?= h($audit['audit_date']) ?> • Status: <?= h($audit['status']) ?></div>
    </div>
    <div class="d-flex gap-2">
      <?php if($audit['status']==='OPEN'): ?>
        <form method="post" onsubmit="return confirm('Tutup audit? Setelah ditutup, entry tidak ideal untuk diubah.')">
          <input type="hidden" name="_action" value="close_audit">
          <?= fa_csrf_input() ?>
          <button class="btn btn-danger">Close Audit</button>
        </form>
      <?php endif; ?>
      <a class="btn btn-outline-secondary" href="audit.php">Kembali</a>
    </div>
  </div>
  <hr>
  <form method="post">
    <input type="hidden" name="_action" value="save_lines">
    <?= fa_csrf_input() ?>
    <div class="table-responsive">
      <table class="table table-sm table-striped" id="tblAudit">
        <thead>
          <tr><th>Asset</th><th>Nama</th><th>Office</th><th>Dept</th><th>Status Fisik</th><th>Catatan</th></tr>
        </thead>
        <tbody>
          <?php foreach($lines as $l): ?>
            <tr>
              <td><b><?= h($l['asset_code']) ?></b></td>
              <td><?= h($l['asset_name']) ?></td>
              <td><?= h($l['office_code']) ?></td>
              <td><?= h($l['dept_code']) ?></td>
              <td style="min-width:170px">
                <select class="form-select form-select-sm" name="line[<?= (int)$l['line_id'] ?>][st]" <?= ($audit['status']!=='OPEN'?'disabled':'') ?>>
                  <?php foreach(['OK','MISSING','DAMAGED','NOT_FOUND'] as $st): ?>
                    <option value="<?= h($st) ?>" <?= ($l['physical_status']===$st?'selected':'') ?>><?= h($st) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <input class="form-control form-control-sm" name="line[<?= (int)$l['line_id'] ?>][note]" value="<?= h($l['note']) ?>" <?= ($audit['status']!=='OPEN'?'disabled':'') ?>>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>initDT('#tblAudit');</script>

    <?php if($audit['status']==='OPEN'): ?>
      <button class="btn btn-primary mt-2">Simpan</button>
    <?php else: ?>
      <div class="text-muted small mt-2">Audit sudah CLOSED.</div>
    <?php endif; ?>
  </form>
</div>
<?php fa_footer(); exit; }

if ($a==='report' && $id>0) {
  $audit=$pdo->prepare("SELECT * FROM fa_audits WHERE id=?");
  $audit->execute([$id]);
  $audit=$audit->fetch();
  if(!$audit){ flash_set('danger','Audit tidak ditemukan.'); redirect_to('audit.php'); }

  $sum=$pdo->prepare("SELECT physical_status, COUNT(*) c FROM fa_audit_lines WHERE audit_id=? GROUP BY physical_status");
  $sum->execute([$id]);
  $sum=$sum->fetchAll();
  $smap=[]; foreach($sum as $r){ $smap[$r['physical_status']] = (int)$r['c']; }

  $lines=$pdo->prepare("SELECT l.physical_status, l.note, a.asset_code, a.asset_name, a.office_code, a.dept_code
      FROM fa_audit_lines l JOIN fa_assets a ON a.id=l.asset_id WHERE l.audit_id=? ORDER BY l.physical_status, a.asset_code");
  $lines->execute([$id]);
  $lines=$lines->fetchAll();
?>
<div class="card bg-white p-3">
  <div class="d-flex justify-content-between align-items-center">
    <div>
      <div class="h6 mb-0">Report Audit: <?= h($audit['audit_code']) ?></div>
      <div class="text-muted small">Office: <?= h($audit['office_code']) ?> • Date: <?= h($audit['audit_date']) ?> • Status: <?= h($audit['status']) ?></div>
    </div>
    <a class="btn btn-outline-secondary" href="audit.php">Kembali</a>
  </div>
  <hr>
  <div class="row g-2">
    <?php foreach(['OK','MISSING','DAMAGED','NOT_FOUND'] as $k): ?>
      <div class="col-md-3">
        <div class="card bg-dark text-white p-3">
          <div class="small"><?= h($k) ?></div>
          <div class="h3 mb-0"><?= h($smap[$k] ?? 0) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <hr>
  <div class="table-responsive">
    <table class="table table-sm table-striped" id="tblAuditReport">
      <thead><tr><th>Status</th><th>Asset</th><th>Nama</th><th>Office</th><th>Dept</th><th>Note</th></tr></thead>
      <tbody>
        <?php foreach($lines as $l): ?>
          <tr>
            <td><?= h($l['physical_status']) ?></td>
            <td><b><?= h($l['asset_code']) ?></b></td>
            <td><?= h($l['asset_name']) ?></td>
            <td><?= h($l['office_code']) ?></td>
            <td><?= h($l['dept_code']) ?></td>
            <td><?= h($l['note']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <script>initDT('#tblAuditReport');</script>
</div>
<?php fa_footer(); exit; }

// default list
$audits=$pdo->query("SELECT * FROM fa_audits ORDER BY id DESC LIMIT 200")->fetchAll();
?>
<div class="card bg-white p-3">
  <div class="d-flex justify-content-between align-items-center">
    <div>
      <div class="h6 mb-0">Daftar Audit</div>
      <div class="text-muted small">Buat audit per office / depo lalu entry status fisik.</div>
    </div>
    <a class="btn btn-primary" href="audit.php?a=new">+ Audit Baru</a>
  </div>
  <hr>
  <div class="table-responsive">
    <table class="table table-sm table-striped" id="tblAudits">
      <thead><tr><th>Code</th><th>Office</th><th>Date</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php foreach($audits as $au): ?>
          <tr>
            <td><b><?= h($au['audit_code']) ?></b></td>
            <td><?= h($au['office_code']) ?></td>
            <td><?= h($au['audit_date']) ?></td>
            <td><?= h($au['status']) ?></td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="audit.php?a=entry&id=<?= (int)$au['id'] ?>">Entry</a>
              <a class="btn btn-sm btn-outline-secondary" href="audit.php?a=report&id=<?= (int)$au['id'] ?>">Report</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <script>initDT('#tblAudits');</script>
</div>

<?php fa_footer(); ?>
