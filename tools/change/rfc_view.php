<?php
declare(strict_types=1);
require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
opsgov_require_admin();

$dir = opsgov_root() . '/docs/rfc';
$id = trim((string)($_GET['id'] ?? ''));
$jsonFiles = [];
if ($id !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1) {
    $jsonFiles = glob($dir . '/' . $id . '-*.json') ?: [];
}
$metaFile = $jsonFiles[0] ?? '';
$meta = $metaFile ? opsgov_read_json($metaFile) : [];
$mdFile = $metaFile ? str_replace('.json', '.md', $metaFile) : '';
$msg = ''; $type = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $metaFile) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $meta['status'] = strtoupper(trim((string)($_POST['status'] ?? 'DRAFT')));
    $meta['approval_note'] = trim((string)($_POST['approval_note'] ?? ''));
    $meta['updated_at'] = date(DateTimeInterface::ATOM);
    opsgov_safe_write_json($metaFile, $meta);
    ts_append_run_history('rfc_status_updated', 'OK', ['source' => 'tools/change/rfc_view.php', 'rfc_id' => $id, 'status' => $meta['status']]);
    $msg = 'Status RFC diperbarui.';
    $type = 'success';
}

$md = ($mdFile && is_file($mdFile)) ? (string)file_get_contents($mdFile) : '';
$baseProject = rmi_layout_base_project();
rmi_header('RFC View', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], ['label' => 'RFC List', 'url' => 'rfc_list.php'], 'RFC View']]);
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card p-3">
      <?php if ($msg !== ''): ?><div class="alert alert-<?= opsgov_h($type) ?>"><?= opsgov_h($msg) ?></div><?php endif; ?>
      <?php if (!$meta): ?><div class="alert alert-warning mb-0">RFC tidak ditemukan.</div><?php else: ?>
      <div class="small mb-2"><b>ID:</b> <?= opsgov_h((string)($meta['id'] ?? '-')) ?></div>
      <div class="small mb-2"><b>Owner:</b> <?= opsgov_h((string)($meta['owner'] ?? '-')) ?></div>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
        <div class="col-12"><label class="form-label small">Status</label><select class="form-select form-select-sm" name="status"><?php foreach (['DRAFT','APPROVED','REJECTED','DONE'] as $s): ?><option <?= (($meta['status'] ?? '') === $s) ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small">Approval Note</label><textarea class="form-control form-control-sm" rows="3" name="approval_note"><?= opsgov_h((string)($meta['approval_note'] ?? '')) ?></textarea></div>
        <div class="col-12"><button class="btn btn-sm btn-rmi">Update</button></div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card p-3">
      <pre class="mb-0" style="white-space:pre-wrap;"><?= opsgov_h($md !== '' ? $md : 'Dokumen RFC tidak tersedia.') ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
