<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/signoff_verdict_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/signoff_verdict_web.last.json';
$msg = '';
$msgType = 'info';
$mode = (string)($_POST['mode'] ?? 'quick');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $mode = in_array($mode, ['quick', 'strict'], true) ? $mode : 'quick';
    $root = ts_root();
    $cmd = escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php') . ' ' .
        escapeshellarg($root . '/tools/qa/generate_signoff_verdict.php') .
        ' --mode=' . escapeshellarg($mode);
    $runOutput = [];
    $runExit = 1;
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $verdict = ts_read_json(ts_storage_logs_dir() . '/signoff_verdict_last.json');
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'mode' => $mode,
        'verdict' => $verdict,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('signoff_verdict_web_run', ((int)$runExit === 0 ? 'OK' : 'ATTENTION'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/signoff_verdict_web.php',
        'mode' => $mode,
        'exit_code' => (int)$runExit,
        'verdict' => strtoupper((string)($verdict['verdict'] ?? 'NO-GO')),
    ]);
    $msg = ((int)$runExit === 0) ? 'Generate signoff verdict selesai: GO.' : 'Generate signoff verdict selesai: NO-GO.';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'verdict', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$verdict = is_array($data['verdict'] ?? null) ? (array)$data['verdict'] : [];
$checks = is_array($verdict['checks'] ?? null) ? (array)$verdict['checks'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];
$verdictLabel = strtoupper((string)($verdict['verdict'] ?? 'NO-GO'));

$baseProject = rmi_layout_base_project();
rmi_header('Signoff Verdict (Web)', [
    'active' => 'tools',
    'subtitle' => 'Generate GO/NO-GO signoff verdict dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Signoff Verdict Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <div class="col-lg-2">
          <label class="form-label">Mode</label>
          <select class="form-select form-select-sm" name="mode">
            <option value="quick" <?= $mode === 'quick' ? 'selected' : '' ?>>quick</option>
            <option value="strict" <?= $mode === 'strict' ? 'selected' : '' ?>>strict</option>
          </select>
        </div>
        <div class="col-lg-2">
          <button class="btn btn-rmi btn-sm w-100" type="submit">Generate Verdict</button>
        </div>
      </form>
      <div class="small mt-2">
        CLI: <code>php tools/qa/generate_signoff_verdict.php --mode=quick|strict</code>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= $verdictLabel === 'GO' ? tools_badge('HEALTHY', 'GO') : tools_badge('ATTENTION', 'NO-GO') ?></div>
        <div class="small">Mode: <b><?= h((string)($data['mode'] ?? 'quick')) ?></b></div>
        <div class="small">Generated at: <b><?= h(tools_fmt_ts((string)($verdict['generated_at'] ?? ''))) ?></b></div>
        <div class="small mt-1">Checks: contract=<?= !empty($checks['contract_ok']) ? 'PASS' : 'FAIL' ?>, cutover=<?= !empty($checks['cutover_ok']) ? 'PASS' : 'FAIL' ?>, readiness=<?= !empty($checks['readiness_100']) ? 'PASS' : 'FAIL' ?></div>
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
