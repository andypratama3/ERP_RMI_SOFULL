<?php
require_once __DIR__ . '/_inc/layout.php';
require_once __DIR__ . '/_inc/fa_helpers.php';

// Explicit auth guard (for static scan + safety)
if (function_exists('require_login')) { require_login(); }

fa_preflight_or_die($pdo);
rbac_require('FIXED_ASSET.DEPRECIATION_RUN');

$groups = fa_group_map();

$action = $_POST['_action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST') { fa_csrf_verify(); }
if ($action === 'run_dep') {
  $ym = trim($_POST['period_ym'] ?? date('Y-m'));
  $force = (int)($_POST['force'] ?? 0);

  // validate format
  if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    flash_set('danger','Format period harus YYYY-MM.');
    redirect_to('depreciation.php');
  }

  // if exists and not force
  $exists = $pdo->prepare("SELECT id FROM fa_dep_runs WHERE period_ym=?");
  $exists->execute([$ym]);
  $run_id = (int)($exists->fetchColumn() ?: 0);

  if ($run_id && !$force) {
    flash_set('warning','Periode sudah pernah di-run. Centang "Force re-run" kalau mau hitung ulang.');
    redirect_to('depreciation.php?ym='.urlencode($ym));
  }

  $pdo->beginTransaction();
  try {
    if ($run_id) {
      $pdo->prepare("DELETE FROM fa_dep_runs WHERE id=?")->execute([$run_id]); // cascade delete lines
      fa_log($pdo,'RERUN','DEPRECIATION_RUN',$run_id,['period'=>$ym]);
      $run_id=0;
    }

    $stmt=$pdo->prepare("INSERT INTO fa_dep_runs(period_ym, run_by, total_assets, total_amount) VALUES (?,?,0,0)");
    $stmt->execute([$ym, (int)($_SESSION['user_id'] ?? 0)]);
    $run_id=(int)$pdo->lastInsertId();

    $p_start = fa_month_start($ym);
    $p_end   = fa_month_end($ym);

    // assets eligible: active/inactive (not disposed before period start), acquired <= period end
    $assets = $pdo->prepare("SELECT * FROM fa_assets
      WHERE deleted_at IS NULL
        AND acq_date <= ?
        AND (status <> 'DISPOSED' OR disposed_at IS NULL OR disposed_at >= ?)");
    $assets->execute([$p_end, $p_start]);
    $assets=$assets->fetchAll();

    $total_assets=0; $total_amount=0.0;

    foreach($assets as $a){
      $gid = $a['tax_group_code'];
      $g = $groups[$gid] ?? $groups['G1'];

      // accumulated before this period
      $acc = $pdo->prepare("SELECT COALESCE(SUM(dep_amount),0) FROM fa_dep_lines WHERE asset_id=? AND period_ym < ?");
      $acc->execute([(int)$a['id'], $ym]);
      $accum_before = (float)$acc->fetchColumn();

      $opening_book = max(0.0, (float)$a['acq_cost'] - (float)$a['salvage_value'] - $accum_before);
      $opening_book_full = max(0.0, (float)$a['acq_cost'] - $accum_before); // for DDB base

      $dep = fa_calc_monthly_dep($a, $g, $opening_book_full, $accum_before, $ym);
      if ($dep <= 0.0) continue;

      $accum_after = $accum_before + $dep;
      $closing_book = max((float)$a['salvage_value'], (float)$a['acq_cost'] - $accum_after);

      $ins=$pdo->prepare("INSERT INTO fa_dep_lines(run_id,asset_id,period_ym,opening_book,dep_amount,closing_book,accum_after) VALUES (?,?,?,?,?,?,?)");
      $ins->execute([$run_id,(int)$a['id'],$ym,$opening_book_full,$dep,$closing_book,$accum_after]);

      $total_assets++;
      $total_amount += $dep;
    }

    $pdo->prepare("UPDATE fa_dep_runs SET total_assets=?, total_amount=? WHERE id=?")->execute([$total_assets, $total_amount, $run_id]);
    fa_log($pdo,'RUN','DEPRECIATION_RUN',$run_id,['period'=>$ym,'assets'=>$total_assets,'amount'=>$total_amount]);

    $pdo->commit();
    flash_set('success',"Depresiasi periode $ym berhasil di-run untuk $total_assets aset.");
  } catch(Throwable $e) {
    $pdo->rollBack();
    flash_set('danger','Gagal run depresiasi: '.$e->getMessage());
  }
  redirect_to('depreciation.php?ym='.urlencode($ym));
}

$ym = $_GET['ym'] ?? date('Y-m');

fa_header('Depresiasi Bulanan');
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card bg-white p-3">
      <div class="h6">Run Depresiasi</div>
      <form method="post" class="mt-2">
        <input type="hidden" name="_action" value="run_dep">
        <?= fa_csrf_input() ?>
        <label class="form-label">Periode (YYYY-MM)</label>
        <input class="form-control" name="period_ym" value="<?= h($ym) ?>" required>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="force" value="1" id="force">
          <label class="form-check-label" for="force">Force re-run (hapus hasil periode ini lalu hitung ulang)</label>
        </div>
        <button class="btn btn-primary w-100 mt-3" onclick="return confirm('Run depresiasi periode ini?')">Run</button>
      </form>
      <div class="text-muted small mt-3">
        <b>Catatan:</b> Kelompok 1–4 mendukung SL/DDB. Bangunan otomatis SL.
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card bg-white p-3">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <div class="h6 mb-0">Hasil Periode <?= h($ym) ?></div>
          <div class="text-muted small">Export tersedia via tombol DataTables</div>
        </div>
      </div>
      <hr>
      <?php
        $run = $pdo->prepare("SELECT * FROM fa_dep_runs WHERE period_ym=?");
        $run->execute([$ym]);
        $run = $run->fetch();
      ?>
      <?php if(!$run): ?>
        <div class="text-muted">Belum ada hasil untuk periode ini.</div>
      <?php else: ?>
        <?php
          $lines = $pdo->prepare("SELECT l.*, a.asset_code, a.asset_name FROM fa_dep_lines l JOIN fa_assets a ON a.id=l.asset_id WHERE l.run_id=? ORDER BY a.asset_code");
          $lines->execute([(int)$run['id']]);
          $lines=$lines->fetchAll();
        ?>
        <div class="mb-2 text-muted small">
          Run at: <?= h($run['run_at']) ?> • Total: <?= h($run['total_assets']) ?> aset • Rp <?= number_format((float)$run['total_amount'],0,',','.') ?>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-striped" id="tblDep">
            <thead>
              <tr>
                <th>Asset</th><th>Nama</th><th>Opening Book</th><th>Dep</th><th>Closing</th><th>Accum After</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($lines as $l): ?>
                <tr>
                  <td><b><?= h($l['asset_code']) ?></b></td>
                  <td><?= h($l['asset_name']) ?></td>
                  <td><?= number_format((float)$l['opening_book'],2,',','.') ?></td>
                  <td><?= number_format((float)$l['dep_amount'],2,',','.') ?></td>
                  <td><?= number_format((float)$l['closing_book'],2,',','.') ?></td>
                  <td><?= number_format((float)$l['accum_after'],2,',','.') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <script>initDT('#tblDep');</script>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php fa_footer(); ?>
