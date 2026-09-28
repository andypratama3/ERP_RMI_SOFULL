<?php
declare(strict_types=1);
require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

opsgov_require_admin();
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $title = trim((string)($_POST['title'] ?? 'Release Notes'));
    $changes = trim((string)($_POST['changes'] ?? ''));
    $rfc = trim((string)($_POST['rfc_ref'] ?? ''));
    $body = "# {$title}\n\nDate: " . date('Y-m-d H:i:s') . "\n\n";
    if ($rfc !== '') $body .= "RFC Reference: {$rfc}\n\n";
    $body .= "## Changes\n{$changes}\n";
    @file_put_contents(opsgov_root() . '/docs/ops/release/Release_Notes_Latest.md', $body);
    $msg = 'Release notes dibuat di docs/ops/release/Release_Notes_Latest.md';
}

$latest = is_file(opsgov_root() . '/docs/ops/release/Release_Notes_Latest.md') ? (string)file_get_contents(opsgov_root() . '/docs/ops/release/Release_Notes_Latest.md') : '';
$baseProject = rmi_layout_base_project();
rmi_header('Release Notes', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Release Notes']]);
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card p-3">
      <?php if ($msg !== ''): ?><div class="alert alert-success"><?= opsgov_h($msg) ?></div><?php endif; ?>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
        <div class="col-12"><label class="form-label small">Title</label><input class="form-control form-control-sm" name="title" value="Release Notes"></div>
        <div class="col-12"><label class="form-label small">RFC Reference (opsional)</label><input class="form-control form-control-sm" name="rfc_ref" placeholder="RFC-YYYYMMDD-001"></div>
        <div class="col-12"><label class="form-label small">Changes</label><textarea class="form-control form-control-sm" name="changes" rows="8" placeholder="- item 1&#10;- item 2"></textarea></div>
        <div class="col-12"><button class="btn btn-sm btn-rmi">Generate</button></div>
      </form>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card p-3"><pre class="mb-0" style="white-space:pre-wrap;"><?= opsgov_h($latest !== '' ? $latest : 'Belum ada release notes.') ?></pre></div>
  </div>
</div>
<?php rmi_footer(); ?>
