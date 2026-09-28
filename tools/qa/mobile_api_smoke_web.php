<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/mobile_api_smoke_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/mobile_api_smoke_web.last.json';
$msg = '';
$msgType = 'info';
$baseUrl = (string)($_POST['app_base_url'] ?? (getenv('APP_BASE_URL') ?: ''));
$attempts = (string)($_POST['rate_limit_attempts'] ?? (getenv('MOBILE_QA_RATE_LIMIT_ATTEMPTS') ?: '40'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    $baseUrl = trim((string)($_POST['app_base_url'] ?? ''));
    $attempts = trim((string)($_POST['rate_limit_attempts'] ?? '40'));
    $maxAttempts = max(1, (int)$attempts);

    $cmd = 'APP_BASE_URL=' . escapeshellarg($baseUrl) . ' MOBILE_QA_RATE_LIMIT_ATTEMPTS=' . escapeshellarg((string)$maxAttempts) . ' ' .
        escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php') . ' ' .
        escapeshellarg($root . '/tools/qa/mobile_api_smoke.php');
    $runOutput = [];
    $runExit = 1;
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'app_base_url' => $baseUrl,
        'rate_limit_attempts' => $maxAttempts,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('mobile_api_smoke_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/mobile_api_smoke_web.php',
        'app_base_url' => $baseUrl,
        'rate_limit_attempts' => $maxAttempts,
        'exit_code' => (int)$runExit,
    ]);
    $msg = ((int)$runExit === 0) ? 'Mobile API smoke selesai: PASS.' : 'Mobile API smoke selesai: FAIL.';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Mobile API Smoke (Web)', [
    'active' => 'tools',
    'subtitle' => 'Run smoke basic mobile API dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Mobile API Smoke Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <div class="col-lg-5">
          <label class="form-label">APP_BASE_URL</label>
          <input class="form-control form-control-sm" name="app_base_url" value="<?= h($baseUrl) ?>" placeholder="http://127.0.0.1:8080/ERP_RMI_SOFULL">
        </div>
        <div class="col-lg-2">
          <label class="form-label">Rate limit attempts</label>
          <input class="form-control form-control-sm" type="number" min="1" name="rate_limit_attempts" value="<?= h($attempts) ?>">
        </div>
        <div class="col-lg-2">
          <button class="btn btn-rmi btn-sm w-100" type="submit">Run Smoke</button>
        </div>
      </form>
      <div class="small mt-2">CLI: <code>APP_BASE_URL=... php tools/qa/mobile_api_smoke.php</code></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
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
