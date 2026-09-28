<?php
require_once __DIR__ . '/_inc/layout.php';
require_once __DIR__ . '/_inc/fa_helpers.php';

// Explicit auth guard (for static scan + safety)
if (function_exists('require_login')) { require_login(); }

fa_preflight_or_die($pdo);
rbac_require('FIXED_ASSET.VIEW');

fa_header('Dashboard Fixed Asset');

$counts = [
  'assets' => (int)$pdo->query("SELECT COUNT(*) FROM fa_assets WHERE deleted_at IS NULL")->fetchColumn(),
  'active' => (int)$pdo->query("SELECT COUNT(*) FROM fa_assets WHERE status='ACTIVE' AND deleted_at IS NULL")->fetchColumn(),
  'disposed' => (int)$pdo->query("SELECT COUNT(*) FROM fa_assets WHERE status='DISPOSED' AND deleted_at IS NULL")->fetchColumn(),
];

$last_dep = $pdo->query("SELECT period_ym, run_at, total_assets, total_amount FROM fa_dep_runs ORDER BY run_at DESC LIMIT 1")->fetch();
$open_audits = $pdo->query("SELECT COUNT(*) FROM fa_audits WHERE status='OPEN'")->fetchColumn();

?>
<div class="row g-3">
  <div class="col-md-4">
    <div class="card bg-dark text-white p-3">
      <div class="h6 mb-1">Total Assets</div>
      <div class="display-6"><?= h($counts['assets']) ?></div>
      <div class="subtle">Active: <?= h($counts['active']) ?> • Disposed: <?= h($counts['disposed']) ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card bg-dark text-white p-3">
      <div class="h6 mb-1">Depresiasi terakhir</div>
      <?php if ($last_dep): ?>
        <div class="display-6"><?= h($last_dep['period_ym']) ?></div>
        <div class="subtle"><?= h($last_dep['total_assets']) ?> aset • Rp <?= number_format((float)$last_dep['total_amount'],0,',','.') ?></div>
      <?php else: ?>
        <div class="subtle">Belum pernah run</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card bg-dark text-white p-3">
      <div class="h6 mb-1">Audit</div>
      <div class="display-6"><?= h((int)$open_audits) ?></div>
      <div class="subtle">Audit status OPEN</div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-7">
    <div class="card bg-white p-3">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <div class="h6 mb-0">Quick Actions</div>
          <div class="text-muted small">Mulai dari yang paling sering dipakai</div>
        </div>
      </div>
      <div class="mt-3 d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="assets.php?a=new">+ Asset Baru</a>
        <a class="btn btn-outline-primary" href="ops.php?tab=acq">+ Acquisition</a>
        <a class="btn btn-outline-primary" href="depreciation.php">Run Depresiasi</a>
        <a class="btn btn-outline-primary" href="audit.php?a=new">Buat Audit</a>
      </div>
      <hr>
      <div class="text-muted small">
        <b>Skema fiskal Indonesia:</b> Kelompok 1–4 (SL/DDB) & Bangunan (SL). Sistem buat depresiasi bulanan otomatis.
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card bg-white p-3">
      <div class="h6">Recent Activity</div>
      <?php
        $logs = $pdo->query("SELECT created_at, action, entity, entity_id, meta_json FROM fa_audit_log ORDER BY id DESC LIMIT 10")->fetchAll();
      ?>
      <?php if(!$logs): ?>
  <div class="text-muted small">Belum ada activity.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-striped" id="tblLog">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Action</th>
          <th>Entity</th>
          <th>ID</th>
          <th>Meta</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($logs as $l): ?>
          <tr>
            <td class="text-muted small"><?= h($l['created_at']) ?></td>
            <td><b><?= h($l['action']) ?></b></td>
            <td><?= h($l['entity']) ?></td>
            <td><?= h($l['entity_id']) ?></td>
            <td class="text-muted small"><?= h($l['meta_json'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <script>document.addEventListener('DOMContentLoaded', ()=>initDT('#tblLog'));</script>
<?php endif; ?>
    </div>
  </div>
</div>

<?php fa_footer(); ?>
