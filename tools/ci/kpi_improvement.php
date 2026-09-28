<?php
declare(strict_types=1);
require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
opsgov_require_admin();

$root = opsgov_root();
$incidents = 0;
$downtimeMin = 0;
$topModule = 'N/A';
$source = [];

$drLog = $root . '/tools/logs/dr_restore_log.jsonl';
if (is_file($drLog)) {
    $source[] = 'tools/logs/dr_restore_log.jsonl';
    $lines = file($drLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $ln) {
        $d = json_decode($ln, true);
        if (!is_array($d)) continue;
        $incidents++;
        $downtimeMin += (int)round(((float)($d['restore_duration_sec'] ?? 0)) / 60);
    }
}
$source[] = 'storage/logs/tools_run_history.jsonl (jika tersedia)';

$backlogPath = $root . '/docs/ops/ci/Improvement_Backlog.md';
if (!is_file($backlogPath)) @file_put_contents($backlogPath, "# Improvement Backlog\n\n## Data Source\n- " . implode("\n- ", $source) . "\n\n## Action Items\n- [ ] owner: OPS due: YYYY-MM-DD action: Isi improvement berbasis data.\n");

$baseProject = rmi_layout_base_project();
rmi_header('KPI Improvement', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'KPI Improvement']]);
?>
<div class="card p-3">
  <div class="mb-2">Incident Count: <b><?= opsgov_h((string)$incidents) ?></b></div>
  <div class="mb-2">Downtime (min): <b><?= opsgov_h((string)$downtimeMin) ?></b></div>
  <div class="mb-2">Top Module Error: <b><?= opsgov_h($topModule) ?></b></div>
  <div class="mb-2 small">Data Source: <?= opsgov_h(implode(', ', $source)) ?></div>
  <a class="btn btn-sm btn-outline-light" target="_blank" rel="noopener" href="<?= opsgov_h($baseProject . '/docs/ops/ci/Improvement_Backlog.md') ?>">Open Improvement Backlog</a>
</div>
<?php rmi_footer(); ?>
