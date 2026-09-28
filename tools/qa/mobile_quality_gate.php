<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/mobile_quality_gate.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h($v) { return rmi_h($v); }
}

$statePath = ts_storage_logs_dir() . '/mobile_quality_gate_web.last.json';
$msg = '';
$msgType = 'info';
$runOutput = [];
$runExit = null;

$defaultBase = tools_default_base_url();
$baseInput = (string)($_POST['app_base_url'] ?? $defaultBase);
$userInput = (string)($_POST['mobile_qa_user'] ?? '');
$useCreds = isset($_POST['use_credentials']) ? ((string)$_POST['use_credentials'] === '1') : false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator unavailable');
    }

    $baseInput = trim((string)($_POST['app_base_url'] ?? $defaultBase));
    $userInput = trim((string)($_POST['mobile_qa_user'] ?? ''));
    $passInput = (string)($_POST['mobile_qa_pass'] ?? '');
    $useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');

    $root = ts_root();
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    $phpBin = (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/qa/run_mobile_quality_gate.sh';
    $cmdParts = ['ERP_PHP_BIN=' . escapeshellarg($phpBin)];
    if ($baseInput !== '') {
        $cmdParts[] = 'APP_BASE_URL=' . escapeshellarg($baseInput);
    }
    if ($useCreds && $userInput !== '' && $passInput !== '') {
        $cmdParts[] = 'MOBILE_QA_USER=' . escapeshellarg($userInput);
        $cmdParts[] = 'MOBILE_QA_PASS=' . escapeshellarg($passInput);
    }
    $cmdParts[] = 'bash ' . escapeshellarg($script);
    $cmd = implode(' ', $cmdParts);

    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $pass = 0;
    $warn = 0;
    $skip = 0;
    $fail = 0;
    foreach ($runOutput as $line) {
        $u = strtoupper(trim((string)$line));
        if (str_starts_with($u, 'PASS')) $pass++;
        if (str_starts_with($u, 'WARN')) $warn++;
        if (str_starts_with($u, 'SKIP')) $skip++;
        if (str_starts_with($u, 'FAIL')) $fail++;
    }

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'app_base_url' => $baseInput,
        'credentials_used' => $useCreds && $userInput !== '' && $passInput !== '',
        'mobile_qa_user' => $userInput,
        'summary' => [
            'pass_lines' => $pass,
            'warn_lines' => $warn,
            'skip_lines' => $skip,
            'fail_lines' => $fail,
        ],
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('mobile_quality_gate_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/mobile_quality_gate.php',
        'app_base_url' => $baseInput,
        'credentials_used' => $payload['credentials_used'],
        'mobile_qa_user' => $userInput,
        'exit_code' => (int)$runExit,
    ]);

    if ((int)$runExit === 0) {
        $msg = 'Mobile quality gate selesai: PASS.';
        $msgType = 'success';
    } else {
        $msg = 'Mobile quality gate selesai: FAIL.';
        $msgType = 'warning';
    }
}

$state = tools_read_state_json($statePath, ['overall_ok', 'summary', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$summary = is_array($data['summary'] ?? null) ? (array)$data['summary'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Mobile Quality Gate', [
    'active' => 'tools',
    'subtitle' => 'Jalankan gate mobile API dari UI dengan output lengkap.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Mobile Quality Gate'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
        <div class="col-lg-5">
          <label class="form-label">APP_BASE_URL</label>
          <input class="form-control form-control-sm" name="app_base_url" value="<?= h($baseInput) ?>" placeholder="http://127.0.0.1:8080/ERP_RMI_SOFULL">
        </div>
        <div class="col-lg-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="use_credentials" value="1" id="useCreds" <?= $useCreds ? 'checked' : '' ?>>
            <label class="form-check-label small" for="useCreds">Use QA credentials</label>
          </div>
        </div>
        <div class="col-lg-2">
          <label class="form-label">MOBILE_QA_USER</label>
          <input class="form-control form-control-sm" name="mobile_qa_user" value="<?= h($userInput) ?>">
        </div>
        <div class="col-lg-2">
          <label class="form-label">MOBILE_QA_PASS</label>
          <input class="form-control form-control-sm" type="password" name="mobile_qa_pass" value="">
        </div>
        <div class="col-lg-1 d-flex align-items-end">
          <button class="btn btn-rmi btn-sm w-100" type="submit">Run</button>
        </div>
      </form>
      <div class="small mt-2">
        CLI: <code>bash tools/qa/run_mobile_quality_gate.sh</code> · State: <code><?= h(ts_mask($statePath)) ?></code>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small mt-1">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
        <div class="small">Exit code: <b><?= (int)($data['exit_code'] ?? -1) ?></b></div>
        <div class="small mt-2">PASS: <b><?= (int)($summary['pass_lines'] ?? 0) ?></b> · WARN: <b><?= (int)($summary['warn_lines'] ?? 0) ?></b> · SKIP: <b><?= (int)($summary['skip_lines'] ?? 0) ?></b> · FAIL: <b><?= (int)($summary['fail_lines'] ?? 0) ?></b></div>
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
