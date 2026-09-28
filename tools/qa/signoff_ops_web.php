<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/signoff_ops_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/signoff_ops_web.last.json';
$msg = '';
$msgType = 'info';
$modeInput = (string)($_POST['mode'] ?? 'quick');
$noSmoke = ((string)($_POST['no_smoke'] ?? '0') === '1');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    $modeInput = in_array($modeInput, ['quick', 'strict'], true) ? $modeInput : 'quick';

    $phpBin = escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php');
    $checkpointCmd = $phpBin . ' ' . escapeshellarg($root . '/tools/qa/hypercare_checkpoint.php') . ($noSmoke ? ' --no-smoke' : '');
    $summaryCmd = $phpBin . ' ' . escapeshellarg($root . '/tools/qa/hypercare_summary.php');
    $verdictCmd = $phpBin . ' ' . escapeshellarg($root . '/tools/qa/generate_signoff_verdict.php') . ' --mode=' . escapeshellarg($modeInput);

    $steps = [];
    foreach ([
        'hypercare_checkpoint' => $checkpointCmd,
        'hypercare_summary' => $summaryCmd,
        'signoff_verdict' => $verdictCmd,
    ] as $name => $cmd) {
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $steps[] = [
            'name' => $name,
            'cmd' => $cmd,
            'exit_code' => (int)$code,
            'ok' => ((int)$code === 0),
            'output' => array_map('tools_mask_sensitive', $out),
        ];
        if ((int)$code !== 0 && $name !== 'signoff_verdict') {
            break;
        }
    }

    $verdict = ts_read_json(ts_storage_logs_dir() . '/signoff_verdict_last.json');
    $overallOk = !empty($verdict) && strtoupper((string)($verdict['verdict'] ?? 'NO-GO')) === 'GO';
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'overall_ok' => $overallOk,
        'mode' => $modeInput,
        'no_smoke' => $noSmoke,
        'steps' => $steps,
        'verdict' => $verdict,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('signoff_ops_web_run', $overallOk ? 'OK' : 'ATTENTION', [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/signoff_ops_web.php',
        'mode' => $modeInput,
        'no_smoke' => $noSmoke,
        'verdict' => strtoupper((string)($verdict['verdict'] ?? 'NO-GO')),
    ]);

    $msg = $overallOk ? 'Signoff ops chain selesai: GO.' : 'Signoff ops chain selesai: NO-GO / ATTENTION.';
    $msgType = $overallOk ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'verdict', 'steps']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$verdictData = is_array($data['verdict'] ?? null) ? (array)$data['verdict'] : [];
$stepsData = is_array($data['steps'] ?? null) ? (array)$data['steps'] : [];
$verdictLabel = strtoupper((string)($verdictData['verdict'] ?? 'NO-GO'));

$baseProject = rmi_layout_base_project();
rmi_header('Signoff Ops Chain (Web)', [
    'active' => 'tools',
    'subtitle' => 'Jalankan hypercare checkpoint + summary + signoff verdict dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Signoff Ops Web'],
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
            <option value="quick" <?= $modeInput === 'quick' ? 'selected' : '' ?>>quick</option>
            <option value="strict" <?= $modeInput === 'strict' ? 'selected' : '' ?>>strict</option>
          </select>
        </div>
        <div class="col-lg-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="noSmoke" name="no_smoke" value="1" <?= $noSmoke ? 'checked' : '' ?>>
            <label class="form-check-label small" for="noSmoke">Skip smoke in checkpoint</label>
          </div>
        </div>
        <div class="col-lg-2">
          <button class="btn btn-rmi btn-sm w-100" type="submit">Run Chain</button>
        </div>
      </form>
      <div class="small mt-2">
        CLI chain: <code>php tools/qa/hypercare_checkpoint.php</code> -> <code>php tools/qa/hypercare_summary.php</code> -> <code>php tools/qa/generate_signoff_verdict.php --mode=quick|strict</code>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'GO') : tools_badge('ATTENTION', $verdictLabel !== '' ? $verdictLabel : 'NO-GO') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
        <div class="small">Mode: <b><?= h((string)($data['mode'] ?? 'quick')) ?></b> · no_smoke: <b><?= !empty($data['no_smoke']) ? 'YES' : 'NO' ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada run valid.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Step Outputs</div>
      <?php if ($stepsData): ?>
        <?php foreach ($stepsData as $st): $step = is_array($st) ? $st : []; ?>
          <div class="small border-bottom py-1">
            <b><?= h((string)($step['name'] ?? '-')) ?></b> · <?= !empty($step['ok']) ? tools_badge('OK') : tools_badge('FAIL') ?> · exit=<?= (int)($step['exit_code'] ?? -1) ?>
            <pre class="small mb-0 mt-1" style="white-space:pre-wrap"><?= h(implode("\n", is_array($step['output'] ?? null) ? $step['output'] : [])) ?></pre>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="small rmi-muted">Belum ada output.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
