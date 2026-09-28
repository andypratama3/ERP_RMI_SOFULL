<?php
require_once dirname(__DIR__) . '/master/auth.php'; // enforce login (static scan + runtime)
require_login(); // enforce login guard (static scan marker + runtime)
require_once __DIR__ . '/_inc/bootstrap.php';

$flash = payroll_flash_get();

// Filters
$q = trim($_GET['q'] ?? '');
$action = trim($_GET['action'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$viewId = (int)($_GET['view'] ?? 0);

$where = "WHERE module = 'PAYROLL'";
$params = [];

if ($q !== '') {
    $where .= " AND (entity_key LIKE ? OR action LIKE ? OR username LIKE ? OR payload_json LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
if ($action !== '') {
    $where .= " AND action = ?";
    $params[] = $action;
}
if ($from !== '') {
    $where .= " AND created_at >= ?";
    $params[] = $from . " 00:00:00";
}
if ($to !== '') {
    $where .= " AND created_at <= ?";
    $params[] = $to . " 23:59:59";
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = "SELECT id, created_at, username, role, level, action, entity_key, payload_json
            FROM erp_audit_log
            {$where}
            ORDER BY id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_audit.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','created_at','username','role','level','action','entity_key','payload_json']);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$r['id'],$r['created_at'],$r['username'],$r['role'],$r['level'],$r['action'],$r['entity_key'],$r['payload_json']]);
    }
    fclose($out);
    exit;
}

$detail = null;
if ($viewId > 0) {
    $st = $pdo->prepare("SELECT * FROM erp_audit_log WHERE id=? AND module='PAYROLL' LIMIT 1");
    $st->execute([$viewId]);
    $detail = $st->fetch(PDO::FETCH_ASSOC);
}

$sql = "SELECT id, created_at, username, role, level, action, entity_key, payload_json
        FROM erp_audit_log
        {$where}
        ORDER BY id DESC
        LIMIT 200";
$st = $pdo->prepare($sql);
$st->execute($params);
$logs = $st->fetchAll(PDO::FETCH_ASSOC);

// distinct actions for dropdown
$actSt = $pdo->query("SELECT DISTINCT action FROM erp_audit_log WHERE module='PAYROLL' ORDER BY action ASC");
$actions = $actSt->fetchAll(PDO::FETCH_COLUMN);

rmi_header('Audit Log • Payroll', 'payroll', ['base_project'=>$BASE_PROJECT]);
?>

<?php if ($flash): ?>
  <div class="alert alert-<?= payroll_h($flash['type']) ?>"><?= payroll_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card rmi-card">
      <div class="card-header">
        <div class="fw-bold">Filter Audit</div>
        <div class="rmi-muted" style="font-size:12px;">Menampilkan maksimal 200 log terakhir.</div>
      </div>
      <div class="card-body">
        <form method="get" class="row g-2">
          <div class="col-12">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" value="<?= payroll_h($q) ?>" placeholder="entity / action / username / payload...">
          </div>

          <div class="col-12">
            <label class="form-label">Action</label>
            <select name="action" class="form-select">
              <option value="">All</option>
              <?php foreach ($actions as $a): ?>
                <option value="<?= payroll_h($a) ?>" <?= ($a===$action)?'selected':'' ?>><?= payroll_h($a) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6">
            <label class="form-label">From</label>
            <input type="date" name="from" class="form-control" value="<?= payroll_h($from) ?>">
          </div>
          <div class="col-6">
            <label class="form-label">To</label>
            <input type="date" name="to" class="form-control" value="<?= payroll_h($to) ?>">
          </div>

          <div class="col-12 d-grid mt-2">
            <button class="btn btn-outline-light" type="submit">Apply Filter</button>
          </div>
          <div class="col-12 d-grid">
            <a class="btn btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/audit.php?export=csv&q=<?= urlencode($q) ?>&action=<?= urlencode($action) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">Export CSV</a>
          </div>
          <div class="col-12 d-grid">
            <a class="btn btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/index.php">← Back to Dashboard</a>
          </div>
        </form>
      </div>
    </div>

    <?php if ($detail): ?>
      <div class="card rmi-card mt-3">
        <div class="card-header">
          <div class="fw-bold">Detail Log #<?= (int)$detail['id'] ?></div>
          <div class="rmi-muted" style="font-size:12px;"><?= payroll_h($detail['created_at']) ?> • <?= payroll_h($detail['username'] ?? '-') ?> • <?= payroll_h($detail['action']) ?></div>
        </div>
        <div class="card-body">
          <div class="rmi-muted" style="font-size:12px;">Entity</div>
          <div class="mb-2"><b><?= payroll_h($detail['entity_key'] ?? '-') ?></b></div>
          <div class="rmi-muted" style="font-size:12px;">Payload (JSON)</div>
          <pre style="white-space:pre-wrap; font-size:12px;" class="mt-1"><?= payroll_h($detail['payload_json'] ?? '') ?></pre>
          <div class="d-grid">
            <a class="btn btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/audit.php?q=<?= urlencode($q) ?>&action=<?= urlencode($action) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">Tutup Detail</a>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div>

  <div class="col-12 col-lg-8">
    <div class="card rmi-card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <div>
          <div class="fw-bold">Audit Log</div>
          <div class="rmi-muted" style="font-size:12px;">Module: PAYROLL</div>
        </div>
        <div class="rmi-muted" style="font-size:12px;">Rows: <?= count($logs) ?></div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm rmi-table mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Payload</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$logs): ?>
                <tr><td colspan="7" class="text-center rmi-muted py-4">Belum ada audit log.</td></tr>
              <?php else: foreach ($logs as $l): ?>
                <tr>
                  <td><?= (int)$l['id'] ?></td>
                  <td><?= payroll_h($l['created_at']) ?></td>
                  <td>
                    <div class="fw-bold"><?= payroll_h($l['username'] ?? '-') ?></div>
                    <div class="rmi-muted" style="font-size:12px;"><?= payroll_h(($l['level'] ?? '') ?: ($l['role'] ?? '')) ?></div>
                  </td>
                  <td><span class="badge rmi-badge info"><?= payroll_h($l['action']) ?></span></td>
                  <td><?= payroll_h($l['entity_key'] ?? '-') ?></td>
                  <td class="rmi-muted" style="font-size:12px; max-width:360px;">
                    <?= payroll_h(erp_audit_excerpt($l['payload_json'] ?? '', 120)) ?>
                  </td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/audit.php?view=<?= (int)$l['id'] ?>&q=<?= urlencode($q) ?>&action=<?= urlencode($action) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">View</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
