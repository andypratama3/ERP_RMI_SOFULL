<?php
declare(strict_types=1);
require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
opsgov_require_admin();

$dir = opsgov_root() . '/docs/ops/ci/postmortems';
@mkdir($dir, 0775, true);
$msg = ''; $type = 'info';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $severity = strtoupper(trim((string)($_POST['severity'] ?? 'SEV-3')));
    $owner = trim((string)($_POST['owner'] ?? ''));
    $incidentDate = trim((string)($_POST['incident_date'] ?? date('Y-m-d')));
    if ($title === '' || $owner === '') {
        $msg = 'Title dan owner wajib.';
        $type = 'danger';
    } else {
        $id = 'PM-' . date('Ymd-His');
        $file = $dir . '/' . $id . '.md';
        $body = "# {$id} - {$title}\n\n- Severity: {$severity}\n- Owner: {$owner}\n- Incident Date: {$incidentDate}\n\n## Timeline\n- \n\n## Root Cause\n- \n\n## Data Source\n- tools/logs, storage/logs (isi yang dipakai)\n\n## Action Items\n- [ ] owner: ___ due: YYYY-MM-DD action: ___\n";
        @file_put_contents($file, $body);
        ts_append_run_history('postmortem_created', 'OK', ['source' => 'tools/ci/postmortem_new.php', 'postmortem_id' => $id]);
        $msg = 'Postmortem dibuat: ' . $id;
        $type = 'success';
    }
}
$baseProject = rmi_layout_base_project();
rmi_header('Postmortem New', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], ['label' => 'Postmortems', 'url' => 'postmortem_list.php'], 'New']]);
?>
<div class="card p-3">
  <?php if ($msg !== ''): ?><div class="alert alert-<?= opsgov_h($type) ?>"><?= opsgov_h($msg) ?></div><?php endif; ?>
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <div class="col-md-8"><label class="form-label small">Title</label><input class="form-control form-control-sm" name="title" required></div>
    <div class="col-md-2"><label class="form-label small">Severity</label><select class="form-select form-select-sm" name="severity"><option>SEV-1</option><option>SEV-2</option><option selected>SEV-3</option><option>SEV-4</option></select></div>
    <div class="col-md-2"><label class="form-label small">Date</label><input class="form-control form-control-sm" type="date" name="incident_date" value="<?= opsgov_h(date('Y-m-d')) ?>"></div>
    <div class="col-md-6"><label class="form-label small">Owner</label><input class="form-control form-control-sm" name="owner" required></div>
    <div class="col-12"><button class="btn btn-sm btn-rmi">Create Postmortem</button></div>
  </form>
</div>
<?php rmi_footer(); ?>
