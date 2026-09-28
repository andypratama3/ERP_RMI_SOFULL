<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.RATE_LIMIT_MANAGE', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_admin_critical();
}

$pdo = db_pdo();
erp_audit_ensure($pdo);
require_once __DIR__ . '/_audit_master.php';

function rp_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
$flash = ['type'=>'','msg'=>''];
$editId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $scope = strtoupper(trim((string)($_POST['scope_key'] ?? '')));
            $window = max(1, (int)($_POST['window_seconds'] ?? 60));
            $maxHits = max(1, (int)($_POST['max_hits'] ?? 20));
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($scope === '') throw new Exception('Scope key wajib diisi.');
            if ($id > 0) {
                $pdo->prepare("UPDATE api_rate_limit_policies SET scope_key=?, window_seconds=?, max_hits=?, is_active=?, updated_at=NOW() WHERE id=?")
                    ->execute([$scope, $window, $maxHits, $active, $id]);
                erp_audit($pdo, 'RATE_LIMIT_POLICY', 'POL#'.$id, 'UPDATE', ['scope'=>$scope,'window'=>$window,'max'=>$maxHits,'active'=>$active]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'rate_limit_policies', 'api_rate_limit_policies', 'UPDATE', $id, 'POL#'.$id, "Rate limit policy updated: {$scope}", ['scope' => $scope, 'window' => $window, 'max' => $maxHits]);
                }
                $flash = ['type'=>'success','msg'=>'Policy updated.'];
            } else {
                $pdo->prepare("INSERT INTO api_rate_limit_policies (scope_key, window_seconds, max_hits, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())")
                    ->execute([$scope, $window, $maxHits, $active]);
                $newId = (int)$pdo->lastInsertId();
                erp_audit($pdo, 'RATE_LIMIT_POLICY', 'POL#'.$newId, 'CREATE', ['scope'=>$scope,'window'=>$window,'max'=>$maxHits,'active'=>$active]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'rate_limit_policies', 'api_rate_limit_policies', 'CREATE', $newId, 'POL#'.$newId, "Rate limit policy created: {$scope}", ['scope' => $scope, 'window' => $window, 'max' => $maxHits]);
                }
                $flash = ['type'=>'success','msg'=>'Policy created.'];
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid policy id.');
            $scope = '';
            $st = $pdo->prepare("SELECT scope_key FROM api_rate_limit_policies WHERE id=? LIMIT 1");
            $st->execute([$id]);
            $scope = (string)($st->fetchColumn() ?: '');
            $pdo->prepare("DELETE FROM api_rate_limit_policies WHERE id=?")->execute([$id]);
            erp_audit($pdo, 'RATE_LIMIT_POLICY', 'POL#'.$id, 'DELETE', []);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'rate_limit_policies', 'api_rate_limit_policies', 'DELETE', $id, 'POL#'.$id, "Rate limit policy deleted: {$scope}", []);
            }
            $flash = ['type'=>'success','msg'=>'Policy deleted.'];
        }
    } catch (Throwable $e) {
        $flash = ['type'=>'danger','msg'=>$e->getMessage()];
    }
}

$audit_rows = [];
if (function_exists('master_audit')) {
    try {
        $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='rate_limit_policies' ORDER BY created_at DESC LIMIT 50");
        $st->execute();
        $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$rows = [];
$edit = null;
try {
    $rows = $pdo->query("SELECT * FROM api_rate_limit_policies ORDER BY scope_key ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if ($editId > 0) {
        $st = $pdo->prepare("SELECT * FROM api_rate_limit_policies WHERE id=? LIMIT 1");
        $st->execute([$editId]);
        $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    $flash = ['type'=>'danger','msg'=>'Load failed: '.$e->getMessage()];
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$base = rmi_layout_base_project();
rmi_header('Rate Limit Policies', [
  'active' => 'master',
  'subtitle' => 'Tune API write limits by scope_key',
  'breadcrumbs' => [
    ['label' => 'Master', 'url' => $base . '/master/index.php'],
    'Rate Limit Policies'
  ]
]);
?>
<?php if ($flash['msg'] !== ''): ?>
  <div class="alert alert-<?= rp_h($flash['type'] !== '' ? $flash['type'] : 'info') ?>"><?= rp_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="rmi-card p-3">
      <h6><?= $edit ? 'Edit Policy' : 'Create Policy' ?></h6>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="col-12"><label class="form-label">Scope Key</label><input class="form-control form-control-sm" name="scope_key" value="<?= rp_h((string)($edit['scope_key'] ?? 'GL_ENQUEUE_POSTING')) ?>" required></div>
        <div class="col-6"><label class="form-label">Window (sec)</label><input type="number" min="1" class="form-control form-control-sm" name="window_seconds" value="<?= (int)($edit['window_seconds'] ?? 60) ?>"></div>
        <div class="col-6"><label class="form-label">Max Hits</label><input type="number" min="1" class="form-control form-control-sm" name="max_hits" value="<?= (int)($edit['max_hits'] ?? 20) ?>"></div>
        <div class="col-12 form-check"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= ((int)($edit['is_active'] ?? 1)===1)?'checked':'' ?>><label class="form-check-label" for="is_active">Active</label></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary">Save</button><?php if ($edit): ?><a class="btn btn-sm btn-outline-light" href="rate_limit_policies.php">Cancel</a><?php endif; ?></div>
      </form>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="rmi-card p-3">
      <h6>Policy List</h6>
      <div class="table-responsive">
        <table class="table table-sm table-dark table-hover">
          <thead><tr><th>ID</th><th>Scope</th><th>Window</th><th>Max</th><th>Active</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="6" class="text-muted">No policies.</td></tr><?php endif; ?>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= rp_h((string)$r['scope_key']) ?></td>
              <td><?= (int)$r['window_seconds'] ?></td>
              <td><?= (int)$r['max_hits'] ?></td>
              <td><?= (int)$r['is_active']===1?'YES':'NO' ?></td>
              <td class="d-flex gap-1">
                <a class="btn btn-sm btn-outline-light" href="?id=<?= (int)$r['id'] ?>">Edit</a>
                <form method="post" onsubmit="return confirm('Delete policy?')">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
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
            <td><?= rp_h($a['created_at'] ?? '') ?></td>
            <td><?= rp_h($a['action'] ?? '') ?></td>
            <td><?= rp_h($a['record_code'] ?? '') ?></td>
            <td><?= rp_h($a['username'] ?? '') ?></td>
            <td><?= rp_h($a['description'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>
