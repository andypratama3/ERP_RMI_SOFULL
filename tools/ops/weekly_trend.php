<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../_lib/tools_exec_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('ops/weekly_trend.php');
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
    $cmd1 = escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' .
        escapeshellarg(APP_ROOT . '/tools/ops/weekly_trend_build.php');
    $cmd2 = escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' .
        escapeshellarg(APP_ROOT . '/tools/ops/sla_monitor_daily.php');
    $r1 = tools_run_step($cmd1, 120, ['idempotent' => true, 'max_retry' => 1]);
    $r2 = tools_run_step($cmd2, 120, ['idempotent' => true, 'max_retry' => 1]);
    $ok = $r1['ok'] && $r2['ok'];
    $msg = $ok ? 'Trend & SLA berhasil diperbarui.' : 'Trend/SLA update ada warning.';
    $msgType = $ok ? 'success' : 'warning';
}

$trend = tools_json_read_safe(APP_ROOT . '/storage/logs/erp_hardening_weekly_trend_last.json');
$sla = tools_json_read_safe(APP_ROOT . '/storage/logs/sla_monitor_last.json');

$baseProject = rmi_layout_base_project();
rmi_header('Weekly Trend & SLA', [
    'active' => 'tools',
    'subtitle' => 'Trend 7/30 hari + SLA monitor harian.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Weekly Trend'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <button class="btn btn-rmi btn-sm" type="submit">Rebuild Trend + SLA</button>
      </form>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Trend State</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h(json_encode($trend, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">SLA State</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h(json_encode($sla, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
