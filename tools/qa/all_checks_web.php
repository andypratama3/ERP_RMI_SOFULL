<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/all_checks_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/all_checks_web.last.json';
$msg = '';
$msgType = 'info';
$runOutput = [];
$runExit = null;
$mode = (string)($_POST['mode'] ?? 'quick');
$allowProd = isset($_POST['allow_prod']) ? ((string)$_POST['allow_prod'] === '1') : false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator unavailable');
    }

    $mode = ((string)($_POST['mode'] ?? 'quick') === 'full') ? 'full' : 'quick';
    $allowProd = ((string)($_POST['allow_prod'] ?? '0') === '1');

    $root = ts_root();
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/qa/run_all_checks.php';
    $cmd = 'ERP_PHP_BIN=' . escapeshellarg($phpBin) . ' ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script);
    if ($mode === 'quick') $cmd .= ' --quick';
    if ($allowProd) $cmd .= ' --i-understand';
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $allChecks = ts_read_json(ts_storage_logs_dir() . '/all_checks.last.json');
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'mode' => $mode,
        'allow_prod' => $allowProd,
        'all_checks' => $allChecks,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('all_checks_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/all_checks_web.php',
        'mode' => $mode,
        'allow_prod' => $allowProd,
        'exit_code' => (int)$runExit,
    ]);

    if ((int)$runExit === 0) {
        $msg = 'All checks selesai: PASS.';
        $msgType = 'success';
    } else {
        $msg = 'All checks selesai: FAIL.';
        $msgType = 'warning';
    }
}

$state = tools_read_state_json($statePath, ['overall_ok', 'all_checks', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$allChecks = is_array($data['all_checks'] ?? null) ? (array)$data['all_checks'] : [];
$allSummary = is_array($allChecks['summary'] ?? null) ? (array)$allChecks['summary'] : [];
$allSteps = is_array($allChecks['steps'] ?? null) ? (array)$allChecks['steps'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('All Checks (Web)', [
    'active' => 'tools',
    'subtitle' => 'Jalankan orchestrator checks quick/full dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'All Checks Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-2 flex-wrap align-items-center">
        <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
        <select class="form-select form-select-sm" style="max-width:180px" name="mode">
          <option value="quick" <?= $mode === 'quick' ? 'selected' : '' ?>>Quick</option>
          <option value="full" <?= $mode === 'full' ? 'selected' : '' ?>>Full</option>
        </select>
        <label class="form-check-label small">
          <input class="form-check-input me-1" type="checkbox" name="allow_prod" value="1" <?= $allowProd ? 'checked' : '' ?>>
          --i-understand (prod safeguard)
        </label>
        <button class="btn btn-rmi btn-sm" type="submit">Run All Checks</button>
      </form>
      <div class="small mt-2">
        CLI: <code>php tools/qa/run_all_checks.php --quick</code> / <code>php tools/qa/run_all_checks.php</code>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
        <div class="small">Score: <b><?= (int)($allSummary['score'] ?? 0) ?>/100</b> · Fail: <b><?= (int)($allSummary['fail_count'] ?? 0) ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada run valid.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Steps</div>
      <?php if (!$allSteps): ?>
        <div class="small rmi-muted">Belum ada detail langkah.</div>
      <?php else: ?>
        <?php foreach ($allSteps as $st): ?>
          <div class="small border-bottom py-1">
            <code><?= h((string)($st['name'] ?? '-')) ?></code> ·
            <?= !empty($st['ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?> ·
            <?= h((string)($st['duration_ms'] ?? 0)) ?> ms
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
