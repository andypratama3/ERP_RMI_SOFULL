<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.JOBS_MONITOR', 'SYSTEM.USER_MANAGE', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_admin_critical();
}

$pdo = db_pdo();
erp_audit_ensure($pdo);
require_once __DIR__ . '/_audit_master.php';

function jm_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
$flash = ['type' => '', 'msg' => ''];
$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));
if (!in_array($statusFilter, ['PENDING','RUNNING','DONE','FAILED'], true)) $statusFilter = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if (in_array($action, ['bulk_retry','bulk_cancel'], true)) {
            $idsRaw = $_POST['job_ids'] ?? [];
            if (!is_array($idsRaw) || !$idsRaw) throw new Exception('Pilih minimal 1 job.');
            $ids = [];
            foreach ($idsRaw as $v) {
                $n = (int)$v;
                if ($n > 0) $ids[$n] = true;
            }
            $ids = array_keys($ids);
            if (!$ids) throw new Exception('Job selection invalid.');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'bulk_retry') {
                $pdo->prepare("UPDATE jobs SET status='PENDING', run_at=NOW(), updated_at=NOW(), last_error=NULL WHERE id IN ($ph)")->execute($ids);
                erp_audit($pdo, 'JOBS', 'BULK', 'BULK_RETRY', ['ids'=>$ids]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'jobs_monitor', 'jobs', 'BULK_RETRY', null, 'BULK', 'Bulk retry: ' . count($ids) . ' jobs', ['count' => count($ids)]);
                }
                $flash = ['type'=>'success','msg'=>'Bulk retry executed for '.count($ids).' jobs.'];
            } else {
                $pdo->prepare("UPDATE jobs SET status='FAILED', last_error='Cancelled by admin', updated_at=NOW() WHERE id IN ($ph)")->execute($ids);
                erp_audit($pdo, 'JOBS', 'BULK', 'BULK_CANCEL', ['ids'=>$ids]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'jobs_monitor', 'jobs', 'BULK_CANCEL', null, 'BULK', 'Bulk cancel: ' . count($ids) . ' jobs', ['count' => count($ids)]);
                }
                $flash = ['type'=>'success','msg'=>'Bulk cancel executed for '.count($ids).' jobs.'];
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid job id.');
            if ($action === 'retry') {
                $pdo->prepare("UPDATE jobs SET status='PENDING', run_at=NOW(), updated_at=NOW(), last_error=NULL WHERE id=?")->execute([$id]);
                erp_audit($pdo, 'JOBS', 'JOB#'.$id, 'RETRY', []);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'jobs_monitor', 'jobs', 'RETRY', $id, 'JOB#'.$id, "Job retry: #{$id}", []);
                }
                $flash = ['type'=>'success','msg'=>'Job moved to PENDING.'];
            } elseif ($action === 'run_now') {
                $pdo->prepare("UPDATE jobs SET run_at=NOW(), updated_at=NOW() WHERE id=? AND status IN ('PENDING','FAILED')")->execute([$id]);
                erp_audit($pdo, 'JOBS', 'JOB#'.$id, 'RUN_NOW', []);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'jobs_monitor', 'jobs', 'RUN_NOW', $id, 'JOB#'.$id, "Job run now: #{$id}", []);
                }
                $flash = ['type'=>'success','msg'=>'Job scheduled to run now.'];
            } elseif ($action === 'cancel') {
                $pdo->prepare("UPDATE jobs SET status='FAILED', last_error='Cancelled by admin', updated_at=NOW() WHERE id=? AND status IN ('PENDING','FAILED')")->execute([$id]);
                erp_audit($pdo, 'JOBS', 'JOB#'.$id, 'CANCEL', []);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'jobs_monitor', 'jobs', 'CANCEL', $id, 'JOB#'.$id, "Job cancelled: #{$id}", []);
                }
                $flash = ['type'=>'success','msg'=>'Job cancelled (marked FAILED).'];
            }
        }
    } catch (Throwable $e) {
        $flash = ['type'=>'danger','msg'=>$e->getMessage()];
    }
}

$rows = [];
$deadRows = [];
try {
    $sql = "SELECT id, job_type, status, attempts, run_at, last_error, created_at, updated_at FROM jobs";
    $params = [];
    if ($statusFilter !== '') {
        $sql .= " WHERE status=?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY id DESC LIMIT 300";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $deadRows = $pdo->query(
      "SELECT id, job_type, status, attempts, run_at, last_error, created_at, updated_at
       FROM jobs
       WHERE status='FAILED'
       ORDER BY updated_at DESC, id DESC
       LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $flash = ['type'=>'danger','msg'=>'Load jobs failed: ' . $e->getMessage()];
}

$audit_rows = [];
if (function_exists('master_audit_ensure_table')) {
    master_audit_ensure_table($pdo);
}
try {
    $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='jobs_monitor' ORDER BY created_at DESC LIMIT 50");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $audit_rows = [];
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$base = rmi_layout_base_project();
rmi_header('Jobs Monitor', [
    'active' => 'master',
    'subtitle' => 'Queue monitoring and controls (retry/run/cancel)',
    'breadcrumbs' => [
      ['label' => 'Master', 'url' => $base . '/master/index.php'],
      'Jobs Monitor'
    ]
]);
?>

<?php if ($flash['msg'] !== ''): ?>
  <div class="alert alert-<?= jm_h($flash['type'] !== '' ? $flash['type'] : 'info') ?>"><?= jm_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="rmi-card p-3 mb-3">
  <form method="get" class="d-flex gap-2 align-items-center">
    <label class="form-label mb-0">Status</label>
    <select class="form-select form-select-sm" style="width:180px" name="status">
      <option value="">ALL</option>
      <?php foreach (['PENDING','RUNNING','DONE','FAILED'] as $s): ?>
        <option value="<?= jm_h($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= jm_h($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-outline-light">Filter</button>
  </form>
</div>

<div class="rmi-card p-3">
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover">
      <thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Attempts</th><th>Run At</th><th>Last Error</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No jobs.</td></tr><?php endif; ?>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= jm_h((string)$r['job_type']) ?></td>
          <td><span class="badge text-bg-secondary"><?= jm_h((string)$r['status']) ?></span></td>
          <td><?= (int)$r['attempts'] ?></td>
          <td><?= jm_h((string)$r['run_at']) ?></td>
          <td><?= jm_h((string)($r['last_error'] ?? '')) ?></td>
          <td class="d-flex gap-1">
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="action" value="run_now">
              <button class="btn btn-sm btn-outline-light">Run Now</button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="action" value="retry">
              <button class="btn btn-sm btn-outline-warning">Retry</button>
            </form>
            <form method="post" onsubmit="return confirm('Cancel job?')">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="action" value="cancel">
              <button class="btn btn-sm btn-outline-danger">Cancel</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="rmi-card p-3 mt-3">
  <h6>Dead-Letter Queue (FAILED jobs)</h6>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div class="d-flex gap-2 mb-2">
      <button class="btn btn-sm btn-outline-warning" name="action" value="bulk_retry" onclick="return confirm('Retry selected failed jobs?')">Bulk Retry Selected</button>
      <button class="btn btn-sm btn-outline-danger" name="action" value="bulk_cancel" onclick="return confirm('Cancel selected failed jobs?')">Bulk Cancel Selected</button>
    </div>
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover">
      <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.dead-check').forEach(cb=>cb.checked=this.checked)"></th><th>ID</th><th>Type</th><th>Attempts</th><th>Updated</th><th>Error</th></tr></thead>
      <tbody>
      <?php if (!$deadRows): ?><tr><td colspan="6" class="text-muted">No dead-letter jobs.</td></tr><?php endif; ?>
      <?php foreach($deadRows as $r): ?>
        <tr>
          <td><input class="form-check-input dead-check" type="checkbox" name="job_ids[]" value="<?= (int)$r['id'] ?>"></td>
          <td><?= (int)$r['id'] ?></td>
          <td><?= jm_h((string)$r['job_type']) ?></td>
          <td><?= (int)$r['attempts'] ?></td>
          <td><?= jm_h((string)$r['updated_at']) ?></td>
          <td><?= jm_h((string)($r['last_error'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </form>
</div>

<?php if (!empty($audit_rows)): ?>
<div class="rmi-card p-3 mt-3">
  <h6>Audit Log (Last 50 events)</h6>
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover">
      <thead><tr><th>Time</th><th>Action</th><th>Code</th><th>User</th><th>Description</th></tr></thead>
      <tbody>
        <?php foreach ($audit_rows as $a): ?>
          <tr>
            <td><?= jm_h($a['created_at'] ?? '') ?></td>
            <td><?= jm_h($a['action'] ?? '') ?></td>
            <td><?= jm_h($a['record_code'] ?? '') ?></td>
            <td><?= jm_h($a['username'] ?? '') ?></td>
            <td><?= jm_h($a['description'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>
