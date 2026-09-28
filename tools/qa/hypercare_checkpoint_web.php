<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/hypercare_checkpoint_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/hypercare_checkpoint_web.last.json';
$msg = '';
$msgType = 'info';
$noSmoke = ((string)($_POST['no_smoke'] ?? '0') === '1');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    $noSmoke = ((string)($_POST['no_smoke'] ?? '0') === '1');
    $cmd = escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php') . ' ' .
        escapeshellarg($root . '/tools/qa/hypercare_checkpoint.php') . ($noSmoke ? ' --no-smoke' : '');
    $runOutput = [];
    $runExit = 1;
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);
    $checkpoint = [];
    if ($runOutput) {
        $last = trim((string)end($runOutput));
        $j = json_decode($last, true);
        if (is_array($j)) $checkpoint = $j;
    }

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'no_smoke' => $noSmoke,
        'checkpoint' => $checkpoint,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('hypercare_checkpoint_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/hypercare_checkpoint_web.php',
        'no_smoke' => $noSmoke,
        'exit_code' => (int)$runExit,
    ]);
    $msg = ((int)$runExit === 0) ? 'Hypercare checkpoint selesai: PASS.' : 'Hypercare checkpoint selesai: FAIL.';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'checkpoint', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$checkpoint = is_array($data['checkpoint'] ?? null) ? (array)$data['checkpoint'] : [];
$gates = is_array($checkpoint['gates'] ?? null) ? (array)$checkpoint['gates'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Hypercare Checkpoint (Web)', [
    'active' => 'tools',
    'subtitle' => 'Jalankan hypercare checkpoint dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Hypercare Checkpoint Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <div class="col-lg-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="no_smoke" value="1" id="noSmoke" <?= $noSmoke ? 'checked' : '' ?>>
            <label class="form-check-label small" for="noSmoke">Skip tools dashboard smoke</label>
          </div>
        </div>
        <div class="col-lg-2">
          <button class="btn btn-rmi btn-sm w-100" type="submit">Run Checkpoint</button>
        </div>
      </form>
      <div class="small mt-2">
        CLI: <code>php tools/qa/hypercare_checkpoint.php --no-smoke</code> (optional)
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
        <div class="small">contract_ok=<?= !empty($gates['contract_ok']) ? '1' : '0' ?> · smoke_fail=<?= (int)($gates['smoke_fail'] ?? -1) ?> · readiness=<?= (int)($gates['readiness_score'] ?? 0) ?></div>
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
