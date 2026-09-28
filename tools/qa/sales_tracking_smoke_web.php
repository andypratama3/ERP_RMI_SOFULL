<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/sales_tracking_smoke_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/sales_tracking_smoke_web.last.json';
$msg = '';
$msgType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    $cmd = escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php') . ' ' .
        escapeshellarg($root . '/tools/qa/sales_tracking_smoke.php');
    $runOutput = [];
    $runExit = 1;
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $decoded = [];
    if ($runOutput) {
        $joined = trim(implode("\n", $runOutput));
        $j = json_decode($joined, true);
        if (!is_array($j)) {
            $start = strpos($joined, '{');
            $end = strrpos($joined, '}');
            if ($start !== false && $end !== false && $end >= $start) {
                $slice = substr($joined, $start, $end - $start + 1);
                $j = json_decode($slice, true);
            }
        }
        if (is_array($j)) $decoded = $j;
    }

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'result' => $decoded,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('sales_tracking_smoke_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/sales_tracking_smoke_web.php',
        'exit_code' => (int)$runExit,
    ]);
    $msg = ((int)$runExit === 0) ? 'Sales tracking smoke selesai: PASS.' : 'Sales tracking smoke selesai: FAIL.';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'result', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$result = is_array($data['result'] ?? null) ? (array)$data['result'] : [];
$checks = is_array($result['checks'] ?? null) ? (array)$result['checks'] : [];
$failCount = 0;
foreach ($checks as $row) {
    if (is_array($row) && empty($row['ok'])) $failCount++;
}
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Sales Tracking Smoke (Web)', [
    'active' => 'tools',
    'subtitle' => 'Validasi kesiapan sales tracking (table/column/file) dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Sales Tracking Smoke Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <button class="btn btn-rmi btn-sm" type="submit">Run Sales Tracking Smoke</button>
      </form>
      <div class="small mt-2">CLI: <code>php tools/qa/sales_tracking_smoke.php</code></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Checks: <b><?= count($checks) ?></b> · Fail: <b><?= (int)$failCount ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada run valid.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Output</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h($output ? implode("\n", $output) : 'Belum ada output.') ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
