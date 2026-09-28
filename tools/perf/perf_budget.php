<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

opsgov_require_admin();
$state = opsgov_read_json(opsgov_root() . '/storage/logs/perf_baseline_last.json');
$budget = [
    'db_latency_ms_max' => (float)opsgov_env('PERF_DB_LATENCY_MS_MAX', '250'),
    'file_rw_ms_max' => (float)opsgov_env('PERF_FILE_RW_MS_MAX', '120'),
    'disk_used_pct_max' => (float)opsgov_env('PERF_DISK_USED_PCT_MAX', '85'),
];
$warn = [];
if (!empty($state)) {
    if ((float)($state['db_latency_ms'] ?? 0) > $budget['db_latency_ms_max']) $warn[] = 'DB latency di atas budget';
    if ((float)($state['file_rw_ms'] ?? 0) > $budget['file_rw_ms_max']) $warn[] = 'File RW latency di atas budget';
    if ((float)($state['disk_used_pct'] ?? 0) > $budget['disk_used_pct_max']) $warn[] = 'Disk usage di atas threshold';
}
$status = !$state ? 'ATTENTION' : (empty($warn) ? 'HEALTHY' : 'ATTENTION');

$baseProject = rmi_layout_base_project();
rmi_header('Perf Budget', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Perf Budget']]);
?>
<div class="card p-3">
  <div class="mb-2">Status: <?= tools_badge($status) ?></div>
  <?php if (!$state): ?>
    <div class="alert alert-warning">Belum ada baseline. Jalankan dulu <a href="perf_baseline.php">Perf Baseline</a>.</div>
  <?php else: ?>
    <div class="small mb-2">DB: <?= opsgov_h((string)($state['db_latency_ms'] ?? '-')) ?>ms (budget <?= opsgov_h((string)$budget['db_latency_ms_max']) ?>ms)</div>
    <div class="small mb-2">File RW: <?= opsgov_h((string)($state['file_rw_ms'] ?? '-')) ?>ms (budget <?= opsgov_h((string)$budget['file_rw_ms_max']) ?>ms)</div>
    <div class="small mb-2">Disk Used: <?= opsgov_h((string)($state['disk_used_pct'] ?? '-')) ?>% (max <?= opsgov_h((string)$budget['disk_used_pct_max']) ?>%)</div>
    <?php if ($warn): ?><div class="alert alert-warning mb-0"><?= opsgov_h(implode('; ', $warn)) ?>. Jalankan <a href="cleanup_runner.php">Cleanup Runner</a>.</div><?php endif; ?>
  <?php endif; ?>
</div>
<?php rmi_footer(); ?>
