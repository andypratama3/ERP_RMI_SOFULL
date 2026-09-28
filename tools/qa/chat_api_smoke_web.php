<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/chat_api_smoke_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/chat_api_smoke_web.last.json';
$msg = '';
$msgType = 'info';
$baseUrl = (string)($_POST['base_url'] ?? (getenv('SMOKE_BASE_URL') ?: 'http://127.0.0.1:18081'));
$user = (string)($_POST['admin_user'] ?? '');
$useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    $baseUrl = trim((string)($_POST['base_url'] ?? 'http://127.0.0.1:18081'));
    $user = trim((string)($_POST['admin_user'] ?? ''));
    $pass = (string)($_POST['admin_pass'] ?? '');
    $useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');

    $cmd = escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php') . ' ' .
        escapeshellarg($root . '/tools/qa/chat_api_smoke.php') . ' --base-url=' . escapeshellarg($baseUrl);
    if ($useCreds && $user !== '' && $pass !== '') {
        $cmd .= ' --user=' . escapeshellarg($user) . ' --pass=' . escapeshellarg($pass);
    }

    $runOutput = [];
    $runExit = 1;
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);
    $result = ts_read_json(ts_storage_logs_dir() . '/chat_api_smoke.last.json');

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'base_url' => $baseUrl,
        'credentials_used' => $useCreds && $user !== '' && $pass !== '',
        'result' => $result,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('chat_api_smoke_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/chat_api_smoke_web.php',
        'base_url' => $baseUrl,
        'credentials_used' => $payload['credentials_used'],
    ]);
    $msg = ((int)$runExit === 0) ? 'Chat API smoke selesai: PASS.' : 'Chat API smoke selesai: FAIL.';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'result', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$result = is_array($data['result'] ?? null) ? (array)$data['result'] : [];
$steps = is_array($result['steps'] ?? null) ? (array)$result['steps'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Chat API Smoke (Web)', [
    'active' => 'tools',
    'subtitle' => 'Run chat API smoke dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Chat API Smoke Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <div class="col-lg-4"><label class="form-label">Base URL</label><input class="form-control form-control-sm" name="base_url" value="<?= h($baseUrl) ?>"></div>
        <div class="col-lg-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="use_credentials" value="1" id="useCreds" <?= $useCreds ? 'checked' : '' ?>>
            <label class="form-check-label small" for="useCreds">Use admin creds</label>
          </div>
        </div>
        <div class="col-lg-2"><label class="form-label">Admin user</label><input class="form-control form-control-sm" name="admin_user" value="<?= h($user) ?>"></div>
        <div class="col-lg-2"><label class="form-label">Admin pass</label><input class="form-control form-control-sm" type="password" name="admin_pass" value=""></div>
        <div class="col-lg-2"><button class="btn btn-rmi btn-sm w-100" type="submit">Run Smoke</button></div>
      </form>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Steps: <b><?= count($steps) ?></b></div>
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
