<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/cutover_checks_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h($v) { return rmi_h($v); }
}

$statePath = ts_storage_logs_dir() . '/cutover_checks_web.last.json';
$msg = '';
$msgType = 'info';
$runOutput = [];
$runExit = null;
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

    $allowProd = ((string)($_POST['allow_prod'] ?? '0') === '1');
    $root = ts_root();
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/qa/run_cutover_checks.php';
    $cmd = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg(tools_default_base_url()) . ' ERP_PHP_BIN=' . escapeshellarg($phpBin) . ' ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --env=staging --write-last --strict --allow-sales-tracking-data';
    if ($allowProd) {
        $cmd .= ' --i-understand';
    }
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $cutover = ts_read_json(ts_storage_logs_dir() . '/cutover_checks.last.json');
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'allow_prod' => $allowProd,
        'cutover' => $cutover,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('cutover_checks_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/cutover_checks_web.php',
        'allow_prod' => $allowProd,
        'exit_code' => (int)$runExit,
    ]);

    if ((int)$runExit === 0) {
        $msg = 'Cutover checks selesai: PASS.';
        $msgType = 'success';
    } else {
        $msg = 'Cutover checks selesai: FAIL.';
        $msgType = 'warning';
    }
}

$state = tools_read_state_json($statePath, ['overall_ok', 'cutover', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$cutover = is_array($data['cutover'] ?? null) ? (array)$data['cutover'] : [];
// Fallback: baca langsung dari cutover_checks.last.json (hasil run CLI) jika belum ada dari web run
if (empty($cutover)) {
    $cutover = ts_read_json(ts_storage_logs_dir() . '/cutover_checks.last.json');
    $cutover = is_array($cutover) ? $cutover : [];
}
$summary = is_array($cutover['summary'] ?? null) ? (array)$cutover['summary'] : [];
$steps = is_array($cutover['steps'] ?? null) ? (array)$cutover['steps'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];
// Data run_at dari cutover jika belum ada di data
if (empty($data['run_at']) && !empty($cutover['run_at'])) {
    $data['run_at'] = $cutover['run_at'];
}
if (!isset($data['overall_ok']) && isset($cutover['overall_ok'])) {
    $data['overall_ok'] = $cutover['overall_ok'];
}

$baseProject = rmi_layout_base_project();
rmi_header('Cutover Checks (Web)', [
    'active' => 'tools',
    'subtitle' => 'Jalankan cutover checks dari UI dengan ringkasan step.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Cutover Checks Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-2 flex-wrap align-items-center">
        <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
        <label class="form-check-label small">
          <input class="form-check-input me-1" type="checkbox" name="allow_prod" value="1" <?= $allowProd ? 'checked' : '' ?>>
          --i-understand (prod safeguard)
        </label>
        <button class="btn btn-rmi btn-sm" type="submit">Run Cutover Checks</button>
      </form>
      <div class="small mt-2">CLI: <code>php tools/qa/run_cutover_checks.php</code></div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok'] || !empty($cutover)): ?>
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
      <div class="fw-semibold mb-2">Steps</div>
      <?php if (!$steps): ?>
        <div class="small rmi-muted">Belum ada detail langkah.</div>
      <?php else: ?>
        <?php foreach ($steps as $st): ?>
          <div class="small border-bottom py-1">
            <code><?= h((string)($st['name'] ?? '-')) ?></code> ·
            <?= !empty($st['ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?> ·
            <?= h((string)($st['duration_ms'] ?? 0)) ?> ms
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($cutover['errors_masked'])): ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2 text-danger">Errors (masked)</div>
      <ul class="small mb-0">
        <?php foreach ((array)$cutover['errors_masked'] as $err): ?>
          <li><?= h(tools_mask_sensitive((string)$err)) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Latest Output</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h($output ? implode("\n", $output) : 'Jalankan dari web atau CLI untuk melihat output.') ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
