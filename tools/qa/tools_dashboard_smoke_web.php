<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/tools_dashboard_smoke_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/tools_dashboard_smoke_web.last.json';
$msg = '';
$msgType = 'info';
$baseUrl = (string)($_POST['base_url'] ?? (getenv('SMOKE_BASE_URL') ?: ''));
$adminUser = (string)($_POST['admin_user'] ?? '');
$useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    $baseUrl = trim((string)($_POST['base_url'] ?? ''));
    $adminUser = trim((string)($_POST['admin_user'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');

    $envParts = [];
    if ($useCreds && $adminUser !== '' && $adminPass !== '') {
        $envParts[] = 'SMOKE_ADMIN_USER=' . escapeshellarg($adminUser);
        $envParts[] = 'SMOKE_ADMIN_PASS=' . escapeshellarg($adminPass);
    }
    $cmd = implode(' ', $envParts);
    if ($cmd !== '') $cmd .= ' ';
    $cmd .= escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php') . ' ' .
        escapeshellarg($root . '/tools/qa/tools_dashboard_smoke.php');
    if ($baseUrl !== '') {
        $cmd .= ' --base-url=' . escapeshellarg($baseUrl);
    }

    $runOutput = [];
    $runExit = 1;
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);
    $smokeData = ts_read_json(ts_storage_logs_dir() . '/tools_dashboard_smoke.last.json');

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'base_url' => $baseUrl,
        'credentials_used' => $useCreds && $adminUser !== '' && $adminPass !== '',
        'smoke' => $smokeData,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('tools_dashboard_smoke_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/tools_dashboard_smoke_web.php',
        'base_url' => $baseUrl,
        'credentials_used' => $payload['credentials_used'],
        'exit_code' => (int)$runExit,
    ]);
    $msg = ((int)$runExit === 0) ? 'Tools dashboard smoke selesai: PASS.' : 'Tools dashboard smoke selesai: FAIL.';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'smoke', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$smoke = is_array($data['smoke'] ?? null) ? (array)$data['smoke'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];
$errors = is_array($smoke['errors_masked'] ?? null) ? (array)$smoke['errors_masked'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Tools Dashboard Smoke (Web)', [
    'active' => 'tools',
    'subtitle' => 'Run smoke untuk tools dashboard dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Tools Dashboard Smoke Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <div class="col-lg-4">
          <label class="form-label">Base URL (optional)</label>
          <input class="form-control form-control-sm" name="base_url" value="<?= h($baseUrl) ?>" placeholder="http://127.0.0.1:8080/ERP_RMI_SOFULL">
        </div>
        <div class="col-lg-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="use_credentials" value="1" id="useCreds" <?= $useCreds ? 'checked' : '' ?>>
            <label class="form-check-label small" for="useCreds">Use admin creds</label>
          </div>
        </div>
        <div class="col-lg-2">
          <label class="form-label">Admin user</label>
          <input class="form-control form-control-sm" name="admin_user" value="<?= h($adminUser) ?>">
        </div>
        <div class="col-lg-2">
          <label class="form-label">Admin pass</label>
          <input class="form-control form-control-sm" type="password" name="admin_pass" value="">
        </div>
        <div class="col-lg-2 d-flex align-items-end">
          <button class="btn btn-rmi btn-sm w-100" type="submit">Run Smoke</button>
        </div>
      </form>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
        <div class="small">Errors: <b><?= count($errors) ?></b></div>
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
