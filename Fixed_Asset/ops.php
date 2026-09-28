<?php
require_once __DIR__ . '/_inc/layout.php';
require_once __DIR__ . '/_inc/fa_helpers.php';

// Explicit auth guard (for static scan + safety)
if (function_exists('require_login')) { require_login(); }

fa_preflight_or_die($pdo);
rbac_require(['FIXED_ASSET.OPERATIONS', 'FIXED_ASSET.OPS_VIEW']);

$tab = $_GET['tab'] ?? 'acq';

$master_offices = fa_master_offices($pdo);
$master_depts   = fa_master_departements($pdo);
$tab = in_array($tab, ['acq','transfer','maint','disposal']) ? $tab : 'acq';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  fa_csrf_verify();
  $act = $_POST['_action'] ?? '';

  // acquisition: create asset quickly
  if ($act === 'quick_acq') {
    $code=trim($_POST['asset_code'] ?? '');

    $name=trim($_POST['asset_name'] ?? '');
    $office=trim($_POST['office_code'] ?? '');
    $dept=trim($_POST['dept_code'] ?? '');
    $acq_date=$_POST['acq_date'] ?? date('Y-m-d');
    $cost=(float)($_POST['acq_cost'] ?? 0);
    $group=$_POST['tax_group_code'] ?? 'G1';
    $method=$_POST['dep_method'] ?? 'SL';
    $vendor=trim($_POST['vendor_name'] ?? '');
    $purchase_ref=trim($_POST['purchase_ref'] ?? '');
    $invoice=trim($_POST['invoice_no'] ?? '');

    if ($code==='' || $name==='') { flash_set('danger','Asset code & name wajib.'); redirect_to('ops.php?tab=acq'); }

    $pdo->prepare("INSERT INTO fa_assets(asset_code,asset_name,office_code,dept_code,acq_date,acq_cost,tax_group_code,dep_method,vendor_name,purchase_ref,invoice_no,status) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'ACTIVE')")
      ->execute([$code,$name,$office,$dept,$acq_date,$cost,$group,$method,$vendor,$purchase_ref,$invoice]);

    $id=(int)$pdo->lastInsertId();
    fa_log($pdo,'CREATE','ASSET',$id,['from'=>'ACQ']);
    flash_set('success','Acquisition masuk register & asset dibuat.');
    redirect_to('assets.php');
  }

  if ($act === 'transfer') {
    $asset_id=(int)($_POST['asset_id'] ?? 0);
    $date=$_POST['transfer_date'] ?? date('Y-m-d');
    $to_office=trim($_POST['to_office'] ?? '');
    $to_dept=trim($_POST['to_dept'] ?? '');
    $to_custodian = ($_POST['to_custodian'] ?? '')!=='' ? (int)$_POST['to_custodian'] : null;
    $notes=trim($_POST['notes'] ?? '');

    $a=$pdo->prepare("SELECT * FROM fa_assets WHERE id=? AND deleted_at IS NULL");
    $a->execute([$asset_id]);
    $a=$a->fetch();
    if(!$a){ flash_set('danger','Asset tidak ditemukan.'); redirect_to('ops.php?tab=transfer'); }

    $pdo->prepare("INSERT INTO fa_transfers(asset_id,transfer_date,from_office,to_office,from_dept,to_dept,from_custodian,to_custodian,notes) VALUES (?,?,?,?,?,?,?,?,?)")
      ->execute([$asset_id,$date,$a['office_code'],$to_office,$a['dept_code'],$to_dept,$a['custodian_emp_id'],$to_custodian,$notes]);

    $pdo->prepare("UPDATE fa_assets SET office_code=?, dept_code=?, custodian_emp_id=?, updated_at=NOW() WHERE id=?")
      ->execute([$to_office,$to_dept,$to_custodian,$asset_id]);

    fa_log($pdo,'TRANSFER','ASSET',$asset_id,['to_office'=>$to_office,'to_dept'=>$to_dept,'to_custodian'=>$to_custodian]);
    flash_set('success','Mutasi berhasil disimpan & asset di-update.');
    redirect_to('ops.php?tab=transfer');
  }

  if ($act === 'maint') {
    $asset_id=(int)($_POST['asset_id'] ?? 0);
    $date=$_POST['maint_date'] ?? date('Y-m-d');
    $vendor=trim($_POST['vendor'] ?? '');
    $cost=(float)($_POST['cost'] ?? 0);
    $down=(float)($_POST['downtime_hours'] ?? 0);
    $desc=trim($_POST['description'] ?? '');

    $pdo->prepare("INSERT INTO fa_maintenance(asset_id,maint_date,vendor,cost,downtime_hours,description) VALUES (?,?,?,?,?,?)")
      ->execute([$asset_id,$date,$vendor,$cost,$down,$desc]);
    fa_log($pdo,'MAINTENANCE','ASSET',$asset_id,['cost'=>$cost,'vendor'=>$vendor]);
    flash_set('success','Maintenance tercatat.');
    redirect_to('ops.php?tab=maint');
  }

  if ($act === 'dispose') {
    $asset_id=(int)($_POST['asset_id'] ?? 0);
    $date=$_POST['disposal_date'] ?? date('Y-m-d');
    $type=$_POST['disposal_type'] ?? 'SOLD';
    $proc=(float)($_POST['proceeds'] ?? 0);
    $doc=trim($_POST['doc_ref'] ?? '');
    $notes=trim($_POST['notes'] ?? '');

    $pdo->beginTransaction();
    try{
      $pdo->prepare("INSERT INTO fa_disposals(asset_id,disposal_date,disposal_type,proceeds,doc_ref,notes) VALUES (?,?,?,?,?,?)")
        ->execute([$asset_id,$date,$type,$proc,$doc,$notes]);
      $pdo->prepare("UPDATE fa_assets SET status='DISPOSED', disposed_at=?, updated_at=NOW() WHERE id=?")->execute([$date,$asset_id]);
      fa_log($pdo,'DISPOSE','ASSET',$asset_id,['type'=>$type,'proceeds'=>$proc,'doc'=>$doc]);
      $pdo->commit();
      flash_set('success','Disposal tersimpan & asset menjadi DISPOSED.');
    }catch(Throwable $e){
      $pdo->rollBack();
      flash_set('danger','Gagal disposal: '.$e->getMessage());
    }
    redirect_to('ops.php?tab=disposal');
  }
}

fa_header('Operasional Asset');

$assets = $pdo->query("SELECT id,asset_code,asset_name,office_code,dept_code,status FROM fa_assets WHERE deleted_at IS NULL ORDER BY asset_code")->fetchAll();
$groups = fa_group_map();

function asset_options($assets){
  foreach($assets as $a){
    echo '<option value="'.(int)$a['id'].'">'.h($a['asset_code']).' - '.h($a['asset_name']).' ('.h($a['office_code']).')</option>';
  }
}
?>
<div class="card bg-white p-3">
  <ul class="nav nav-pills gap-2">
    <li class="nav-item"><a class="nav-link <?= $tab==='acq'?'active':'' ?>" href="ops.php?tab=acq">Acquisition</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='transfer'?'active':'' ?>" href="ops.php?tab=transfer">Mutasi</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='maint'?'active':'' ?>" href="ops.php?tab=maint">Maintenance</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='disposal'?'active':'' ?>" href="ops.php?tab=disposal">Disposal</a></li>
  </ul>
  <hr>

  <?php if($tab==='acq'): ?>
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="h6">Quick Acquisition → langsung buat asset</div>
        <form method="post" class="mt-2">
          <input type="hidden" name="_action" value="quick_acq">
      <?= fa_csrf_input() ?>
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Asset Code*</label>
              <input class="form-control" name="asset_code" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Asset Name*</label>
              <input class="form-control" name="asset_name" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Acq Date</label>
              <input type="date" class="form-control" name="acq_date" value="<?= h(date('Y-m-d')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Cost</label>
              <input type="number" step="0.01" class="form-control" name="acq_cost" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">Office</label>
              <?php if(!empty($master_offices)): ?>
                <select class="form-select" name="office_code" required>
                  <option value="">-- pilih office --</option>
                  <?= fa_build_options($master_offices, 'office_code', 'office_name', ($_POST['office_code'] ?? '')) ?>
                </select>
              <?php else: ?>
                <input class="form-control" name="office_code" value="<?= h($_POST['office_code'] ?? '') ?>" placeholder="BOGOR">
              <?php endif; ?>
            </div>
            <div class="col-md-4">
              <label class="form-label">Dept</label>
              <?php if(!empty($master_depts)): ?>
                <select class="form-select" name="dept_code" required>
                  <option value="">-- pilih dept --</option>
                  <?= fa_build_options($master_depts, 'dept_code', 'dept_name', ($_POST['dept_code'] ?? '')) ?>
                </select>
              <?php else: ?>
                <input class="form-control" name="dept_code" value="<?= h($_POST['dept_code'] ?? '') ?>" placeholder="FIN">
              <?php endif; ?>
            </div>
            <div class="col-md-4">
              <label class="form-label">Tax Group</label>
              <select class="form-select" name="tax_group_code">
                <?php foreach($groups as $code=>$g): ?>
                  <option value="<?= h($code) ?>"><?= h($g['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Method</label>
              <select class="form-select" name="dep_method">
                <option value="SL">SL</option>
                <option value="DDB">DDB</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Vendor</label>
              <input class="form-control" name="vendor_name">
            </div>
            <div class="col-md-3">
              <label class="form-label">PO/Ref</label>
              <input class="form-control" name="purchase_ref">
            </div>
            <div class="col-md-3">
              <label class="form-label">Invoice</label>
              <input class="form-control" name="invoice_no">
            </div>

            <div class="col-12">
              <button class="btn btn-primary" onclick="return confirm('Buat asset dari acquisition ini?')">Simpan</button>
              <a class="btn btn-outline-secondary" href="assets.php?a=new">Form lengkap (Register)</a>
            </div>
          </div>
        </form>
      </div>

      <div class="col-lg-6">
        <div class="h6">Tips</div>
        <div class="text-muted small">
          Untuk enterprise, acquisition idealnya link dari Purchases/Finance. Di versi Lite ini dibuat “quick create” dulu agar operasional jalan.
        </div>
      </div>
    </div>

  <?php elseif($tab==='transfer'): ?>
    <div class="h6">Mutasi / Assignment (pindah office/dept/PIC)</div>
    <form method="post" class="mt-2">
      <input type="hidden" name="_action" value="transfer">
      <?= fa_csrf_input() ?>
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label">Asset</label>
          <select class="form-select" name="asset_id" required>
            <?php asset_options($assets); ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Tanggal</label>
          <input type="date" class="form-control" name="transfer_date" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Custodian (employee_id)</label>
          <input class="form-control" name="to_custodian" placeholder="ID employee">
        </div>
        <div class="col-md-4">
          <label class="form-label">To Office</label>
          <?php if(!empty($master_offices)): ?>
            <select class="form-select" name="to_office" required>
              <option value="">-- pilih office --</option>
              <?= fa_build_options($master_offices, 'office_code', 'office_name', ($_POST['to_office'] ?? '')) ?>
            </select>
          <?php else: ?>
            <input class="form-control" name="to_office" value="<?= h($_POST['to_office'] ?? '') ?>" placeholder="BEKASI">
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">To Dept</label>
          <?php if(!empty($master_depts)): ?>
            <select class="form-select" name="to_dept" required>
              <option value="">-- pilih dept --</option>
              <?= fa_build_options($master_depts, 'dept_code', 'dept_name', ($_POST['to_dept'] ?? '')) ?>
            </select>
          <?php else: ?>
            <input class="form-control" name="to_dept" value="<?= h($_POST['to_dept'] ?? '') ?>" placeholder="FIN">
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Notes</label>
          <input class="form-control" name="notes">
        </div>
        <div class="col-12">
          <button class="btn btn-primary" onclick="return confirm('Simpan mutasi ini?')">Simpan Mutasi</button>
        </div>
      </div>
    </form>

    <hr>
    <?php
      $trs = $pdo->query("SELECT t.*, a.asset_code, a.asset_name FROM fa_transfers t JOIN fa_assets a ON a.id=t.asset_id ORDER BY t.id DESC LIMIT 200")->fetchAll();
    ?>
    <div class="table-responsive">
      <table class="table table-sm table-striped" id="tblTr">
        <thead><tr><th>Tgl</th><th>Asset</th><th>From Office</th><th>To Office</th><th>From Dept</th><th>To Dept</th><th>Notes</th></tr></thead>
        <tbody>
          <?php foreach($trs as $t): ?>
            <tr>
              <td><?= h($t['transfer_date']) ?></td>
              <td><b><?= h($t['asset_code']) ?></b> - <?= h($t['asset_name']) ?></td>
              <td><?= h($t['from_office']) ?></td>
              <td><?= h($t['to_office']) ?></td>
              <td><?= h($t['from_dept']) ?></td>
              <td><?= h($t['to_dept']) ?></td>
              <td><?= h($t['notes']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>initDT('#tblTr');</script>

  <?php elseif($tab==='maint'): ?>
    <div class="h6">Maintenance</div>
    <form method="post" class="mt-2">
      <input type="hidden" name="_action" value="maint">
      <?= fa_csrf_input() ?>
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label">Asset</label>
          <select class="form-select" name="asset_id" required>
            <?php asset_options($assets); ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Tanggal</label>
          <input type="date" class="form-control" name="maint_date" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Vendor</label>
          <input class="form-control" name="vendor">
        </div>
        <div class="col-md-3">
          <label class="form-label">Biaya</label>
          <input type="number" step="0.01" class="form-control" name="cost" value="0">
        </div>
        <div class="col-md-3">
          <label class="form-label">Downtime (jam)</label>
          <input type="number" step="0.01" class="form-control" name="downtime_hours" value="0">
        </div>
        <div class="col-md-6">
          <label class="form-label">Deskripsi</label>
          <input class="form-control" name="description">
        </div>
        <div class="col-12">
          <button class="btn btn-primary">Simpan</button>
        </div>
      </div>
    </form>

    <hr>
    <?php
      $mts = $pdo->query("SELECT m.*, a.asset_code, a.asset_name FROM fa_maintenance m JOIN fa_assets a ON a.id=m.asset_id ORDER BY m.id DESC LIMIT 200")->fetchAll();
    ?>
    <div class="table-responsive">
      <table class="table table-sm table-striped" id="tblMt">
        <thead><tr><th>Tgl</th><th>Asset</th><th>Vendor</th><th>Biaya</th><th>Downtime</th><th>Desc</th></tr></thead>
        <tbody>
          <?php foreach($mts as $m): ?>
            <tr>
              <td><?= h($m['maint_date']) ?></td>
              <td><b><?= h($m['asset_code']) ?></b> - <?= h($m['asset_name']) ?></td>
              <td><?= h($m['vendor']) ?></td>
              <td><?= number_format((float)$m['cost'],0,',','.') ?></td>
              <td><?= number_format((float)$m['downtime_hours'],2,',','.') ?></td>
              <td><?= h($m['description']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>initDT('#tblMt');</script>

  <?php else: ?>
    <div class="h6">Disposal (jual/rusak/hilang) + berita acara</div>
    <form method="post" class="mt-2">
      <input type="hidden" name="_action" value="dispose">
      <?= fa_csrf_input() ?>
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label">Asset</label>
          <select class="form-select" name="asset_id" required>
            <?php asset_options($assets); ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Tanggal</label>
          <input type="date" class="form-control" name="disposal_date" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Type</label>
          <select class="form-select" name="disposal_type">
            <option value="SOLD">SOLD</option>
            <option value="DAMAGED">DAMAGED</option>
            <option value="LOST">LOST</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Proceeds</label>
          <input type="number" step="0.01" class="form-control" name="proceeds" value="0">
        </div>
        <div class="col-md-3">
          <label class="form-label">Doc Ref</label>
          <input class="form-control" name="doc_ref" placeholder="BA-FA-xxxx">
        </div>
        <div class="col-md-6">
          <label class="form-label">Notes</label>
          <input class="form-control" name="notes">
        </div>
        <div class="col-12">
          <button class="btn btn-danger" onclick="return confirm('Disposal akan mengubah status asset menjadi DISPOSED. Lanjut?')">Simpan Disposal</button>
        </div>
      </div>
    </form>

    <hr>
    <?php
      $ds = $pdo->query("SELECT d.*, a.asset_code, a.asset_name FROM fa_disposals d JOIN fa_assets a ON a.id=d.asset_id ORDER BY d.id DESC LIMIT 200")->fetchAll();
    ?>
    <div class="table-responsive">
      <table class="table table-sm table-striped" id="tblDs">
        <thead><tr><th>Tgl</th><th>Asset</th><th>Type</th><th>Proceeds</th><th>Doc</th><th>Notes</th></tr></thead>
        <tbody>
          <?php foreach($ds as $d): ?>
            <tr>
              <td><?= h($d['disposal_date']) ?></td>
              <td><b><?= h($d['asset_code']) ?></b> - <?= h($d['asset_name']) ?></td>
              <td><?= h($d['disposal_type']) ?></td>
              <td><?= number_format((float)$d['proceeds'],0,',','.') ?></td>
              <td><?= h($d['doc_ref']) ?></td>
              <td><?= h($d['notes']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>initDT('#tblDs');</script>

  <?php endif; ?>
</div>

<?php fa_footer(); ?>
