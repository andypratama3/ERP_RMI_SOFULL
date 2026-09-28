<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/mobile_auth_policy_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/mobile_auth_policy_web.last.json';
$msg = '';
$msgType = 'info';
$baseUrl = (string)($_POST['app_base_url'] ?? (getenv('APP_BASE_URL') ?: ''));
$useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');
$qaUser = (string)($_POST['mobile_qa_user'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    $baseUrl = trim((string)($_POST['app_base_url'] ?? ''));
    $useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');
    $qaUser = trim((string)($_POST['mobile_qa_user'] ?? ''));
    $qaPass = (string)($_POST['mobile_qa_pass'] ?? '');

    $php = escapeshellarg(function_exists('tools_php_bin') ? tools_php_bin() : 'php');
    $env = 'APP_BASE_URL=' . escapeshellarg($baseUrl);
    if ($useCreds && $qaUser !== '' && $qaPass !== '') {
        $env .= ' MOBILE_QA_USER=' . escapeshellarg($qaUser) . ' MOBILE_QA_PASS=' . escapeshellarg($qaPass);
    }

    $steps = [];
    $scripts = [
        'mobile_negative_auth_test' => $root . '/tools/qa/mobile_negative_auth_test.php',
        'mobile_master_policy_smoke' => $root . '/tools/qa/mobile_master_policy_smoke.php',
    ];
    foreach ($scripts as $name => $path) {
        $cmd = $env . ' ' . $php . ' ' . escapeshellarg($path);
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $steps[] = [
            'name' => $name,
            'exit_code' => (int)$code,
            'ok' => ((int)$code === 0),
            'output' => array_map('tools_mask_sensitive', $out),
        ];
    }

    $overallOk = true;
    foreach ($steps as $s) {
        if (empty($s['ok'])) {
            $overallOk = false;
            break;
        }
    }

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'overall_ok' => $overallOk,
        'app_base_url' => $baseUrl,
        'credentials_used' => $useCreds && $qaUser !== '' && $qaPass !== '',
        'steps' => $steps,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('mobile_auth_policy_web_run', $overallOk ? 'OK' : 'FAIL', [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/mobile_auth_policy_web.php',
        'app_base_url' => $baseUrl,
        'credentials_used' => $payload['credentials_used'],
    ]);
    $msg = $overallOk ? 'Mobile auth/policy checks selesai: PASS.' : 'Mobile auth/policy checks selesai: FAIL.';
    $msgType = $overallOk ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'steps']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$steps = is_array($data['steps'] ?? null) ? (array)$data['steps'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Mobile Auth & Policy (Web)', [
    'active' => 'tools',
    'subtitle' => 'Run negative auth + master policy smoke dari UI.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Mobile Auth Policy Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <div class="col-lg-4">
          <label class="form-label">APP_BASE_URL</label>
          <input class="form-control form-control-sm" name="app_base_url" value="<?= h($baseUrl) ?>" placeholder="http://127.0.0.1:8080/ERP_RMI_SOFULL">
        </div>
        <div class="col-lg-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="use_credentials" value="1" id="useCreds" <?= $useCreds ? 'checked' : '' ?>>
            <label class="form-check-label small" for="useCreds">Use QA credentials</label>
          </div>
        </div>
        <div class="col-lg-2">
          <label class="form-label">MOBILE_QA_USER</label>
          <input class="form-control form-control-sm" name="mobile_qa_user" value="<?= h($qaUser) ?>">
        </div>
        <div class="col-lg-2">
          <label class="form-label">MOBILE_QA_PASS</label>
          <input class="form-control form-control-sm" type="password" name="mobile_qa_pass" value="">
        </div>
        <div class="col-lg-2 d-flex align-items-end">
          <button class="btn btn-rmi btn-sm w-100" type="submit">Run Checks</button>
        </div>
      </form>
      <div class="small mt-2">
        CLI: <code>php tools/qa/mobile_negative_auth_test.php</code> + <code>php tools/qa/mobile_master_policy_smoke.php</code>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Latest Steps</div>
      <?php if ($steps): ?>
        <?php foreach ($steps as $st): $s = is_array($st) ? $st : []; ?>
          <div class="small border-bottom py-2">
            <b><?= h((string)($s['name'] ?? '-')) ?></b> · <?= !empty($s['ok']) ? tools_badge('OK') : tools_badge('FAIL') ?> · exit=<?= (int)($s['exit_code'] ?? -1) ?>
            <pre class="small mb-0 mt-1" style="white-space:pre-wrap"><?= h(implode("\n", is_array($s['output'] ?? null) ? $s['output'] : [])) ?></pre>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="small rmi-muted">Belum ada output.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
