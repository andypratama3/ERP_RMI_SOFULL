<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/hypercare_summary_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/hypercare_summary_web.last.json';
$msg = '';
$msgType = 'info';
$windowHours = (string)($_POST['window_hours'] ?? '24');
$minCheckpoints = (string)($_POST['min_checkpoints'] ?? '6');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    $wh = max(1, (int)$windowHours);
    $mc = max(1, (int)$minCheckpoints);
    $cmd = escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php') . ' ' .
        escapeshellarg($root . '/tools/qa/hypercare_summary.php') .
        ' --window-hours=' . escapeshellarg((string)$wh) .
        ' --min-checkpoints=' . escapeshellarg((string)$mc);
    $runOutput = [];
    $runExit = 1;
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $summary = ts_read_json(ts_storage_logs_dir() . '/hypercare_summary_last.json');
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'window_hours' => $wh,
        'min_checkpoints' => $mc,
        'summary' => $summary,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('hypercare_summary_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/hypercare_summary_web.php',
        'window_hours' => $wh,
        'min_checkpoints' => $mc,
        'exit_code' => (int)$runExit,
    ]);
    $msg = ((int)$runExit === 0) ? 'Hypercare summary selesai: PASS.' : 'Hypercare summary selesai: FAIL.';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'summary', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$summary = is_array($data['summary'] ?? null) ? (array)$data['summary'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Hypercare Summary (Web)', [
    'active' => 'tools',
    'subtitle' => 'Generate hypercare summary dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Hypercare Summary Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <div class="col-lg-2">
          <label class="form-label">window_hours</label>
          <input class="form-control form-control-sm" type="number" min="1" name="window_hours" value="<?= h($windowHours) ?>">
        </div>
        <div class="col-lg-2">
          <label class="form-label">min_checkpoints</label>
          <input class="form-control form-control-sm" type="number" min="1" name="min_checkpoints" value="<?= h($minCheckpoints) ?>">
        </div>
        <div class="col-lg-2">
          <button class="btn btn-rmi btn-sm w-100" type="submit">Run Summary</button>
        </div>
      </form>
      <div class="small mt-2">
        CLI: <code>php tools/qa/hypercare_summary.php --window-hours=24 --min-checkpoints=6</code>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($summary['hypercare_complete_24h']) ? tools_badge('HEALTHY', 'COMPLETE') : tools_badge('ATTENTION', 'INCOMPLETE') ?></div>
        <div class="small">Window covered: <b><?= h((string)($summary['window_hours_covered'] ?? 0)) ?>h</b></div>
        <div class="small">Checkpoints: <b><?= (int)($summary['total_checkpoints'] ?? 0) ?></b></div>
        <div class="small">Critical incidents: <b><?= (int)($summary['critical_incidents_count'] ?? 0) ?></b></div>
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
