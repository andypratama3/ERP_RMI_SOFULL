<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/sales_tracking_checks_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/sales_tracking_checks_web.last.json';
$msg = '';
$msgType = 'info';
$runOutput = [];
$runExit = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/run_sales_tracking_checks.php');
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $tracking = ts_read_json(ts_storage_logs_dir() . '/sales_tracking_checks.last.json');
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'tracking' => $tracking,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('sales_tracking_checks_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/sales_tracking_checks_web.php',
        'exit_code' => (int)$runExit,
    ]);
    $msg = ((int)$runExit === 0) ? 'Sales tracking checks selesai: PASS.' : 'Sales tracking checks selesai: FAIL.';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'tracking', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$tracking = is_array($data['tracking'] ?? null) ? (array)$data['tracking'] : [];
// Fallback: baca langsung dari sales_tracking_checks.last.json (hasil run CLI)
if (empty($tracking)) {
    $tracking = ts_read_json(ts_storage_logs_dir() . '/sales_tracking_checks.last.json');
    $tracking = is_array($tracking) ? $tracking : [];
}
$summary = is_array($tracking['summary'] ?? null) ? (array)$tracking['summary'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];
if (!isset($data['overall_ok']) && isset($tracking['overall_ok'])) {
    $data['overall_ok'] = $tracking['overall_ok'];
}
if (empty($data['run_at']) && !empty($tracking['run_at'])) {
    $data['run_at'] = $tracking['run_at'];
}

$baseProject = rmi_layout_base_project();
rmi_header('Sales Tracking Checks (Web)', [
    'active' => 'tools',
    'subtitle' => 'Run sales tracking integrity checks dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Sales Tracking Checks Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <button class="btn btn-rmi btn-sm" type="submit">Run Sales Tracking Checks</button>
      </form>
      <div class="small mt-2">CLI: <code>php tools/qa/run_sales_tracking_checks.php</code></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok'] || !empty($tracking)): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
        <div class="small">Fail: <b><?= (int)($summary['fail_count'] ?? 0) ?></b> · Warn: <b><?= (int)($summary['warn_count'] ?? 0) ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada run valid.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Checks Detail</div>
      <?php
      $checks = (array)($tracking['checks'] ?? []);
      if ($checks): ?>
        <?php foreach ($checks as $c): ?>
          <div class="small border-bottom py-1">
            <code><?= h((string)($c['name'] ?? '-')) ?></code> ·
            <?= !empty($c['ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?> ·
            <?= h((string)($c['status'] ?? '')) ?> · <?= h((string)($c['message'] ?? '')) ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="small rmi-muted">Belum ada detail check.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Latest Output</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h($output ? implode("\n", $output) : 'Jalankan dari web atau CLI untuk melihat output.') ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
