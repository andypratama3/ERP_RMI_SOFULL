<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../_lib/tools_exec_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('ops/sla_monitor.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $cmd = escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' .
        escapeshellarg(APP_ROOT . '/tools/ops/sla_monitor_daily.php');
    tools_run_step($cmd, 120, ['idempotent' => true, 'max_retry' => 1]);
}
$state = tools_json_read_safe(APP_ROOT . '/storage/logs/sla_monitor_last.json');

$baseProject = rmi_layout_base_project();
rmi_header('SLA Monitor Harian', [
    'active' => 'tools',
    'subtitle' => 'P0 harus 0 dalam 24 jam.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'SLA Monitor'],
]);
?>
<div class="row g-3">
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <button class="btn btn-rmi btn-sm" type="submit">Refresh SLA</button>
      </form>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3">
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
