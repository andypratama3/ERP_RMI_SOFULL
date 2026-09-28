<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/negative_tests_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h($v) { return rmi_h($v); }
}

$statePath = ts_storage_logs_dir() . '/negative_tests_web.last.json';
$msg = '';
$msgType = 'info';
$runOutput = [];
$runExit = null;
$skipCutover = isset($_POST['skip_cutover']) ? ((string)$_POST['skip_cutover'] === '1') : true;
$skipRotate = isset($_POST['skip_rotate']) ? ((string)$_POST['skip_rotate'] === '1') : true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator unavailable');
    }

    $skipCutover = ((string)($_POST['skip_cutover'] ?? '1') === '1');
    $skipRotate = ((string)($_POST['skip_rotate'] ?? '1') === '1');

    $root = ts_root();
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/qa/run_negative_tests.php';
    $baseUrl = trim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('TOOLS_BASE_URL') ?: getenv('APP_URL') ?: ''));
    if ($baseUrl === '') $baseUrl = tools_default_base_url();
    $baseUrl = rtrim(preg_replace('#(https?://)/+#', '$1', $baseUrl), '/');
    $envPrefix = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl) . ' TOOLS_BASE_URL=' . escapeshellarg($baseUrl) . ' APP_URL=' . escapeshellarg($baseUrl) . ' ';
    $cmd = $envPrefix . escapeshellarg($phpBin) . ' ' . escapeshellarg($script);
    if ($skipCutover) $cmd .= ' --skip-cutover';
    if ($skipRotate) $cmd .= ' --skip-rotate';
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $neg = ts_read_json(ts_storage_logs_dir() . '/negative_tests.last.json');
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'skip_cutover' => $skipCutover,
        'skip_rotate' => $skipRotate,
        'negative_tests' => $neg,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('negative_tests_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/negative_tests_web.php',
        'skip_cutover' => $skipCutover,
        'skip_rotate' => $skipRotate,
        'exit_code' => (int)$runExit,
    ]);

    if ((int)$runExit === 0) {
        $msg = 'Negative tests selesai: PASS.';
        $msgType = 'success';
    } else {
        $msg = 'Negative tests selesai: FAIL.';
        $msgType = 'warning';
    }
}

$state = tools_read_state_json($statePath, ['overall_ok', 'negative_tests', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$neg = is_array($data['negative_tests'] ?? null) ? (array)$data['negative_tests'] : [];
$summary = is_array($neg['summary'] ?? null) ? (array)$neg['summary'] : [];
$results = is_array($neg['results'] ?? null) ? (array)$neg['results'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Negative Tests (Web)', [
    'active' => 'tools',
    'subtitle' => 'Jalankan paket negative tests dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Negative Tests Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-3 flex-wrap align-items-center">
        <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
        <label class="form-check-label small">
          <input class="form-check-input me-1" type="checkbox" name="skip_cutover" value="1" <?= $skipCutover ? 'checked' : '' ?>>
          --skip-cutover
        </label>
        <label class="form-check-label small">
          <input class="form-check-input me-1" type="checkbox" name="skip_rotate" value="1" <?= $skipRotate ? 'checked' : '' ?>>
          --skip-rotate
        </label>
        <button class="btn btn-rmi btn-sm" type="submit">Run Negative Tests</button>
      </form>
      <div class="small mt-2">CLI: <code>php tools/qa/run_negative_tests.php --skip-cutover --skip-rotate</code></div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
        <div class="small">Score: <b><?= (int)($summary['score'] ?? 0) ?>/100</b> · Fail: <b><?= (int)($summary['fail_count'] ?? 0) ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada run valid.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Results</div>
      <?php if (!$results): ?>
        <div class="small rmi-muted">Belum ada detail hasil.</div>
      <?php else: ?>
        <?php foreach ($results as $st): ?>
          <div class="small border-bottom py-1">
            <code><?= h((string)($st['name'] ?? '-')) ?></code> ·
            <?= !empty($st['ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?> ·
            <?= h((string)($st['detail'] ?? '-')) ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Latest Output</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h($output ? implode("\n", $output) : 'Belum ada output.') ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
