<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/contract_check_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h($v) { return rmi_h($v); }
}

$statePath = ts_storage_logs_dir() . '/contract_check_web.last.json';
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
    $cmd = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg(tools_default_base_url()) . ' ' . escapeshellarg($phpBin) . ' ' .
        escapeshellarg($root . '/tools/qa/contract_check.php') . ' --strict --write-last';
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $contract = ts_read_json(ts_storage_logs_dir() . '/contract_check_last.json');
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'contract' => $contract,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('contract_check_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/contract_check_web.php',
        'exit_code' => (int)$runExit,
    ]);
    $msg = ((int)$runExit === 0) ? 'Contract check selesai: PASS.' : 'Contract check selesai: FAIL.';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'contract', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$contract = is_array($data['contract'] ?? null) ? (array)$data['contract'] : [];
if (empty($contract)) {
    $contract = ts_read_json(ts_storage_logs_dir() . '/contract_check_last.json');
    $contract = is_array($contract) ? $contract : [];
}
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];
if (!isset($data['overall_ok']) && isset($contract['ok'])) $data['overall_ok'] = $contract['ok'];
if (empty($data['run_at']) && !empty($contract['run_at'])) $data['run_at'] = $contract['run_at'];

$baseProject = rmi_layout_base_project();
rmi_header('Contract Check (Web)', [
    'active' => 'tools',
    'subtitle' => 'Run contract check strict dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Contract Check Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <button class="btn btn-rmi btn-sm" type="submit">Run Contract Check</button>
      </form>
      <div class="small mt-2">CLI: <code>php tools/qa/contract_check.php --strict --write-last</code></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok'] || !empty($contract)): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
        <div class="small">Summary ok: <b><?= !empty($contract['ok']) ? 'YES' : 'NO' ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada run valid.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Checks Detail</div>
      <?php $checks = (array)($contract['checks'] ?? []); if ($checks): ?>
        <?php foreach ($checks as $c): ?>
          <div class="small border-bottom py-1">
            <code><?= h((string)($c['name'] ?? '-')) ?></code> ·
            <?= !empty($c['ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?> ·
            code=<?= (int)($c['code'] ?? 0) ?> · <?= h((string)($c['detail'] ?? '')) ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="small rmi-muted">Belum ada detail. Jalankan dari web atau CLI.</div>
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
