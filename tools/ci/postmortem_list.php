<?php
declare(strict_types=1);
require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
opsgov_require_admin();

$dir = opsgov_root() . '/docs/ops/ci/postmortems';
@mkdir($dir, 0775, true);
$files = glob($dir . '/PM-*.md') ?: [];
rsort($files);
$baseProject = rmi_layout_base_project();
rmi_header('Postmortem List', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Postmortems']]);
?>
<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">Postmortems</div>
    <a class="btn btn-sm btn-rmi" href="postmortem_new.php">+ New</a>
  </div>
  <ul class="mb-0">
    <?php if (!$files): ?><li class="text-muted">Belum ada postmortem.</li><?php endif; ?>
    <?php foreach ($files as $f): ?>
      <li><a href="<?= opsgov_h($baseProject . '/docs/ops/ci/postmortems/' . basename($f)) ?>" target="_blank" rel="noopener"><?= opsgov_h(basename($f)) ?></a></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php rmi_footer(); ?>
