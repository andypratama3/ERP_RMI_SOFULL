<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/evidence_pack_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/evidence_pack_web.last.json';
$msg = '';
$msgType = 'info';
$label = (string)($_POST['label'] ?? 'final_hypercare');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    $label = trim((string)($_POST['label'] ?? 'final_hypercare'));
    if ($label === '') $label = 'final_hypercare';

    $cmd = escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php') . ' ' .
        escapeshellarg($root . '/tools/qa/generate_evidence_pack.php') .
        ' --label=' . escapeshellarg($label);
    $runOutput = [];
    $runExit = 1;
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);
    $result = ts_read_json(ts_storage_logs_dir() . '/evidence_pack_last.json');

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'label' => $label,
        'result' => $result,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('evidence_pack_web_run', ((int)$runExit === 0 ? 'OK' : 'ATTENTION'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/evidence_pack_web.php',
        'label' => $label,
    ]);
    $msg = ((int)$runExit === 0) ? 'Evidence pack selesai: PASS.' : 'Evidence pack selesai: ATTENTION (ada missing file).';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'result', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$result = is_array($data['result'] ?? null) ? (array)$data['result'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Evidence Pack (Web)', [
    'active' => 'tools',
    'subtitle' => 'Generate evidence pack dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Evidence Pack Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <div class="col-lg-4">
          <label class="form-label">Label</label>
          <input class="form-control form-control-sm" name="label" value="<?= h($label) ?>">
        </div>
        <div class="col-lg-2">
          <button class="btn btn-rmi btn-sm w-100" type="submit">Generate Pack</button>
        </div>
      </form>
      <div class="small mt-2">CLI: <code>php tools/qa/generate_evidence_pack.php --label=final_hypercare</code></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('ATTENTION', 'CHECK') ?></div>
        <div class="small">Copied: <b><?= (int)($result['copied_count'] ?? 0) ?></b> · Missing: <b><?= (int)($result['missing_count'] ?? 0) ?></b></div>
        <div class="small">Pack dir: <code><?= h((string)($result['pack_dir_masked'] ?? '-')) ?></code></div>
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
