<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/manual_action_queue_lib.php';

tools_require_access('ops/manual_action_queue.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$msg = '';
$msgType = 'info';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'generate') {
        maq_generate_from_triage();
        $msg = 'Queue digenerate dari findings triage.';
        $msgType = 'success';
    } elseif ($action === 'status') {
        $id = (string)($_POST['id'] ?? '');
        $status = (string)($_POST['status'] ?? 'OPEN');
        $pic = trim((string)($_POST['pic'] ?? ''));
        maq_update_status($id, $status, $pic);
        $msg = 'Status queue diperbarui.';
        $msgType = 'success';
    }
}

$state = tools_json_read_safe(maq_last_path());
$rows = (array)($state['rows'] ?? []);
$fSev = strtoupper(trim((string)($_GET['severity'] ?? 'ALL')));
$fOwner = strtoupper(trim((string)($_GET['owner'] ?? 'ALL')));
$fStatus = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));
$rows = array_values(array_filter($rows, static function (array $row) use ($fSev, $fOwner, $fStatus): bool {
    if ($fSev !== 'ALL' && strtoupper((string)($row['severity'] ?? '')) !== $fSev) return false;
    if ($fOwner !== 'ALL' && strtoupper((string)($row['owner'] ?? '')) !== $fOwner) return false;
    if ($fStatus !== 'ALL' && strtoupper((string)($row['status'] ?? '')) !== $fStatus) return false;
    return true;
}));

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="manual_action_queue.csv"');
    $fp = fopen('php://output', 'wb');
    fputcsv($fp, ['id', 'created_at', 'severity', 'title', 'owner', 'status', 'pic']);
    foreach ($rows as $r) {
        fputcsv($fp, [(string)$r['id'], (string)$r['created_at'], (string)$r['severity'], (string)$r['title'], (string)$r['owner'], (string)$r['status'], (string)($r['pic'] ?? '')]);
    }
    fclose($fp);
    exit;
}

$baseProject = rmi_layout_base_project();
rmi_header('Manual Action Queue', [
    'active' => 'tools',
    'subtitle' => 'Queue otomatis dari finding non-autofix sampai DONE.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Manual Action Queue'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="action" value="generate">
        <button class="btn btn-rmi btn-sm" type="submit">Generate from Triage</button>
      </form>
      <a class="btn btn-outline-light btn-sm ms-2" href="?export=csv">Export CSV</a>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead><tr><th>ID</th><th>Severity</th><th>Owner</th><th>Title</th><th>Status</th><th>PIC</th><th>Action</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><code><?= h((string)$r['id']) ?></code></td>
                <td><?= h((string)$r['severity']) ?></td>
                <td><?= h((string)$r['owner']) ?></td>
                <td><?= h((string)$r['title']) ?></td>
                <td><?= h((string)$r['status']) ?></td>
                <td><?= h((string)($r['pic'] ?? '')) ?></td>
                <td>
                  <form method="post" class="d-flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
                    <select class="form-select form-select-sm" name="status">
                      <?php foreach (['OPEN','IN_PROGRESS','DONE'] as $s): ?>
                        <option value="<?= h($s) ?>" <?= strtoupper((string)$r['status']) === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input class="form-control form-control-sm" name="pic" value="<?= h((string)($r['pic'] ?? '')) ?>" placeholder="Assign PIC">
                    <button class="btn btn-sm btn-outline-light" type="submit">Save</button>
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
<?php rmi_footer(); ?>
