<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/tools_state_lib.php';
require_once __DIR__ . '/tools_ui_helpers.php';
require_once __DIR__ . '/tools_access_helpers.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

tools_require_access('ops/control_center.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

$root = dirname(__DIR__);
$state = ts_read_json($root . '/storage/state/release_verify_all_last.json');
$summary = is_array($state['summary'] ?? null) ? (array)$state['summary'] : [];
$banner = ts_read_json($root . '/storage/state/ops_banner.json');
$phpErrorScan = ts_read_json($root . '/storage/logs/pipeline/php_error_scan_last.json');
$phpErrorCounts = (array)($phpErrorScan['counts'] ?? []);
$phpErrorFatal = (int)($phpErrorCounts['fatal'] ?? 0);
$phpErrorWarn = (int)($phpErrorCounts['warn'] ?? 0);
$phpErrorBadge = 'OK';
if (!is_file($root . '/storage/logs/pipeline/php_error_scan_last.json')) {
    $phpErrorBadge = 'ATTENTION';
} elseif ($phpErrorFatal > 0) {
    $phpErrorBadge = 'CRITICAL';
} elseif ($phpErrorWarn > 0) {
    $phpErrorBadge = 'ATTENTION';
}
$bannerLevel = strtoupper((string)($banner['level'] ?? 'HEALTHY'));
$bannerOwner = (string)($banner['primary_owner'] ?? 'TBD');
$bannerDue = (string)($banner['sla_due_at'] ?? '');
$bannerStatus = (string)($banner['workflow_status'] ?? 'OPEN');
$bannerBreach = (bool)($banner['breached'] ?? false);
$badge = 'WARN';
if (!empty($state)) {
    $bf = (int)($summary['bundle_fail'] ?? 0);
    $bw = (int)($summary['bundle_warn'] ?? 0);
    if ($bf > 0) $badge = 'FAIL';
    elseif ($bw > 0) $badge = 'WARN';
    else $badge = 'OK';
}

$baseProject = rmi_layout_base_project();
rmi_header('Control Center', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Control Center']]);
?>
<div class="card p-3">
  <div class="fw-semibold mb-2">Control Center Links</div>
  <div class="small mb-2">Ops center dan release verification ringkas.</div>
  <div class="small mb-2">
    <?= tools_badge($bannerLevel, $bannerLevel) ?>
    Owner <b><?= htmlspecialchars($bannerOwner, ENT_QUOTES, 'UTF-8') ?></b> · Due <b><?= htmlspecialchars(tools_fmt_ts($bannerDue), ENT_QUOTES, 'UTF-8') ?></b> · Status <b><?= htmlspecialchars($bannerStatus, ENT_QUOTES, 'UTF-8') ?></b>
    <?= $bannerBreach ? tools_badge('CRITICAL', 'SLA BREACH') : '' ?>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-sm btn-outline-light" href="ops/control_center.php">Open Ops Control Center</a>
    <?php
    $bugPackLast = ts_read_json($root . '/storage/logs/pipeline/bug_pack_last.json');
    $bugPackZipPath = (string)($bugPackLast['zip_path'] ?? '');
    $bugPackRunId = (string)($bugPackLast['run_id'] ?? '');
    if ($bugPackZipPath !== '' && $bugPackRunId !== ''):
    ?>
    <span class="small text-muted align-self-center">Latest Bug Pack: <code><?= htmlspecialchars($bugPackZipPath, ENT_QUOTES, 'UTF-8') ?></code> — Run: <code><?= htmlspecialchars($bugPackRunId, ENT_QUOTES, 'UTF-8') ?></code>. Generate: <code>php tools/qa/collect_bug_pack.php --run-id=auto</code></span>
    <?php else: ?>
    <span class="small text-muted align-self-center">Bug Pack: belum ada. Generate: <code>php tools/qa/collect_bug_pack.php --run-id=auto</code></span>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline-light" href="ops/plan_exec_summary_view.php?mode=html&file=last">Plan Exec Summary</a>
    <a class="btn btn-sm btn-outline-light" href="release/release_artifacts.php">Release Verify (<?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?>)</a>
    <a class="btn btn-sm btn-outline-light" href="ops/control_center.php#php-errors">PHP Errors (<?= htmlspecialchars($phpErrorBadge, ENT_QUOTES, 'UTF-8') ?>)</a>
    <a class="btn btn-sm btn-outline-light" href="ops/alerts.php">Ops Alerts Workflow</a>
    <a class="btn btn-sm btn-outline-light" href="rfc/rfc_dashboard.php">RFC Dashboard</a>
  </div>
</div>
<?php rmi_footer(); ?>

