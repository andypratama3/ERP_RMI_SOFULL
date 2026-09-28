<?php
declare(strict_types=1);

$export = strtolower(trim((string)($_GET['export'] ?? '')));
$providedToken = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_EXPORT_TOKEN'] ?? ''));
$expectedToken = (string)(getenv('TOOLS_HEALTH_EXPORT_TOKEN') ?: '');
$hasValidExportToken = ($providedToken !== '' && $expectedToken !== '' && hash_equals($expectedToken, $providedToken));
$tokenExportAllowed = ($export === 'json' && $hasValidExportToken);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

if (!$tokenExportAllowed) {
    tools_require_access('qa/web_wrapper_health_matrix.php');
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wm_status_from_state')) {
    function wm_status_from_state(array $state): array
    {
        $ok = null;
        if (array_key_exists('overall_ok', $state)) $ok = (bool)$state['overall_ok'];
        elseif (array_key_exists('ok', $state)) $ok = (bool)$state['ok'];
        elseif (array_key_exists('passed', $state)) $ok = (bool)$state['passed'];
        $runAt = (string)($state['run_at'] ?? $state['checked_at'] ?? $state['generated_at'] ?? $state['ts'] ?? '');
        return ['ok' => $ok, 'run_at' => $runAt];
    }
}

if (!function_exists('wm_http_req')) {
    function wm_http_req(string $method, string $url, string $cookieFile, array $post = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['code' => $code, 'body' => $body];
    }
}

$wrappers = [
    ['path' => 'tools/qa/mobile_quality_gate.php', 'state' => '/mobile_quality_gate_web.last.json'],
    ['path' => 'tools/qa/smoke_http_web.php', 'state' => '/smoke_http_web.last.json'],
    ['path' => 'tools/qa/all_checks_web.php', 'state' => '/all_checks_web.last.json'],
    ['path' => 'tools/qa/cutover_checks_web.php', 'state' => '/cutover_checks_web.last.json'],
    ['path' => 'tools/qa/negative_tests_web.php', 'state' => '/negative_tests_web.last.json'],
    ['path' => 'tools/release/deploy_size_audit.php', 'state' => '/deploy_size_audit_last.json'],
    ['path' => 'tools/qa/contract_check_web.php', 'state' => '/contract_check_web.last.json'],
    ['path' => 'tools/qa/sales_tracking_checks_web.php', 'state' => '/sales_tracking_checks_web.last.json'],
    ['path' => 'tools/qa/signoff_ops_web.php', 'state' => '/signoff_ops_web.last.json'],
    ['path' => 'tools/qa/hypercare_summary_web.php', 'state' => '/hypercare_summary_web.last.json'],
    ['path' => 'tools/qa/signoff_verdict_web.php', 'state' => '/signoff_verdict_web.last.json'],
    ['path' => 'tools/qa/tools_dashboard_smoke_web.php', 'state' => '/tools_dashboard_smoke_web.last.json'],
    ['path' => 'tools/qa/hypercare_checkpoint_web.php', 'state' => '/hypercare_checkpoint_web.last.json'],
    ['path' => 'tools/qa/mobile_api_smoke_web.php', 'state' => '/mobile_api_smoke_web.last.json'],
    ['path' => 'tools/qa/mobile_auth_policy_web.php', 'state' => '/mobile_auth_policy_web.last.json'],
    ['path' => 'tools/qa/mobile_api_contract_check_web.php', 'state' => '/mobile_api_contract_check_web.last.json'],
    ['path' => 'tools/qa/dashboard_role_smoke_web.php', 'state' => '/dashboard_role_smoke_web.last.json'],
    ['path' => 'tools/qa/chat_api_smoke_web.php', 'state' => '/chat_api_smoke_web.last.json'],
    ['path' => 'tools/qa/evidence_pack_web.php', 'state' => '/evidence_pack_web.last.json'],
    ['path' => 'tools/qa/chat_schema_smoke_web.php', 'state' => '/chat_schema_smoke_web.last.json'],
    ['path' => 'tools/qa/sales_tracking_smoke_web.php', 'state' => '/sales_tracking_smoke_web.last.json'],
];

$msg = '';
$msgType = 'info';
$statePath = ts_storage_logs_dir() . '/web_wrapper_health_matrix.last.json';
$baseUrl = (string)($_POST['base_url'] ?? (getenv('APP_BASE_URL') ?: 'http://127.0.0.1:8080/ERP_RMI_SOFULL'));
$adminUser = (string)($_POST['admin_user'] ?? '');
$useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? $baseUrl)), '/');
    $adminUser = trim((string)($_POST['admin_user'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');

    $cookie = ts_storage_logs_dir() . '/_wrapper_health.cookie.txt';
    @unlink($cookie);
    if ($useCreds && $adminUser !== '' && $adminPass !== '') {
        wm_http_req('POST', $baseUrl . '/master/login.php', $cookie, [
            'username' => $adminUser,
            'password' => $adminPass,
        ]);
    }

    $rows = [];
    $pass = 0;
    $fail = 0;
    foreach ($wrappers as $w) {
        $url = $baseUrl . '/' . ltrim((string)$w['path'], '/');
        $r = wm_http_req('GET', $url, $cookie);
        $body = (string)$r['body'];
        $hasCrash = str_contains($body, 'Fatal error')
            || str_contains($body, 'Unhandled Exception')
            || str_contains($body, 'Parse error')
            || str_contains($body, 'SQLSTATE');
        $ok = ((int)$r['code'] === 200) && !$hasCrash;
        $rows[] = [
            'path' => (string)$w['path'],
            'http_code' => (int)$r['code'],
            'ok' => $ok,
            'crash' => $hasCrash,
        ];
        if ($ok) $pass++; else $fail++;
    }
    @unlink($cookie);

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'base_url' => $baseUrl,
        'credentials_used' => $useCreds && $adminUser !== '' && $adminPass !== '',
        'summary' => ['pass' => $pass, 'fail' => $fail, 'total' => count($rows)],
        'rows' => $rows,
        'overall_ok' => ($fail === 0),
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('web_wrapper_health_matrix_run', ($fail === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/web_wrapper_health_matrix.php',
        'pass' => $pass,
        'fail' => $fail,
        'total' => count($rows),
    ]);
    $msg = ($fail === 0)
        ? 'Web wrapper smoke matrix: PASS semua.'
        : 'Web wrapper smoke matrix: ada FAIL, cek tabel detail.';
    $msgType = ($fail === 0) ? 'success' : 'warning';
}

$latestSmoke = ts_read_json($statePath);
$latestRows = is_array($latestSmoke['rows'] ?? null) ? (array)$latestSmoke['rows'] : [];
$latestSummary = is_array($latestSmoke['summary'] ?? null) ? (array)$latestSmoke['summary'] : [];

$stateRows = [];
foreach ($wrappers as $w) {
    $stateFile = ts_storage_logs_dir() . (string)$w['state'];
    $j = ts_read_json($stateFile);
    $s = wm_status_from_state($j);
    $stateRows[] = [
        'path' => (string)$w['path'],
        'state_file' => ts_mask($stateFile),
        'state_exists' => is_file($stateFile),
        'state_ok' => $s['ok'],
        'last_run' => $s['run_at'],
    ];
}

if ($export === 'csv') {
    $filename = 'web_wrapper_health_matrix_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        echo "Failed to create CSV output.";
        exit;
    }

    fputcsv($out, ['section', 'wrapper_path', 'status', 'http_code', 'crash', 'state_exists', 'state_status', 'last_run', 'state_file']);
    foreach ($latestRows as $r) {
        $row = is_array($r) ? $r : [];
        fputcsv($out, [
            'latest_smoke',
            (string)($row['path'] ?? ''),
            !empty($row['ok']) ? 'PASS' : 'FAIL',
            (string)($row['http_code'] ?? ''),
            !empty($row['crash']) ? 'YES' : 'NO',
            '',
            '',
            (string)($latestSmoke['run_at'] ?? ''),
            '',
        ]);
    }
    foreach ($stateRows as $r) {
        $stateStatus = 'UNKNOWN';
        if (($r['state_ok'] ?? null) === true) $stateStatus = 'PASS';
        if (($r['state_ok'] ?? null) === false) $stateStatus = 'FAIL';
        fputcsv($out, [
            'state_matrix',
            (string)($r['path'] ?? ''),
            '',
            '',
            '',
            !empty($r['state_exists']) ? 'FOUND' : 'MISSING',
            $stateStatus,
            (string)($r['last_run'] ?? ''),
            (string)($r['state_file'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}
if ($export === 'json') {
    if (!$tokenExportAllowed && !in_array($remote, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'forbidden'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $stateMap = [];
    foreach ($stateRows as $r) {
        $k = (string)($r['path'] ?? '');
        if ($k === '') continue;
        $stateMap[$k] = $r;
    }
    $rows = [];
    foreach ($wrappers as $w) {
        $path = (string)$w['path'];
        $smoke = null;
        foreach ($latestRows as $lr) {
            if (is_array($lr) && (string)($lr['path'] ?? '') === $path) {
                $smoke = $lr;
                break;
            }
        }
        $st = is_array($stateMap[$path] ?? null) ? $stateMap[$path] : [];
        $rows[] = [
            'wrapper_path' => $path,
            'latest_smoke_ok' => is_array($smoke) ? (bool)($smoke['ok'] ?? false) : null,
            'latest_http_code' => is_array($smoke) ? (int)($smoke['http_code'] ?? 0) : null,
            'latest_crash' => is_array($smoke) ? (bool)($smoke['crash'] ?? false) : null,
            'state_exists' => (bool)($st['state_exists'] ?? false),
            'state_ok' => $st['state_ok'] ?? null,
            'last_run' => (string)($st['last_run'] ?? ''),
            'state_file' => (string)($st['state_file'] ?? ''),
        ];
    }
    echo json_encode([
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'base_url' => (string)($latestSmoke['base_url'] ?? ''),
        'summary' => [
            'total_wrappers' => count($wrappers),
            'latest_smoke' => [
                'pass' => (int)($latestSummary['pass'] ?? 0),
                'fail' => (int)($latestSummary['fail'] ?? 0),
                'total' => (int)($latestSummary['total'] ?? 0),
                'run_at' => (string)($latestSmoke['run_at'] ?? ''),
            ],
        ],
        'rows' => $rows,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$baseProject = rmi_layout_base_project();
rmi_header('Web Wrapper Health Matrix', [
    'active' => 'tools',
    'subtitle' => 'Monitoring status wrapper web + smoke HTTP satu klik.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Web Wrapper Health Matrix'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <div class="col-lg-5">
          <label class="form-label">Base URL</label>
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
        <div class="col-lg-1">
          <button class="btn btn-rmi btn-sm w-100" type="submit">Smoke</button>
        </div>
      </form>
      <div class="small mt-2">Smoke checks: HTTP=200 + no crash signature. · <a href="?export=csv">Export CSV</a> · <a href="?export=json">Export JSON</a></div>
      <div class="small text-muted">External JSON polling: set env <code>TOOLS_HEALTH_EXPORT_TOKEN</code> lalu akses <code>?export=json&token=...</code>.</div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Smoke Summary</div>
      <?php if ($latestSmoke): ?>
        <?php $fail = (int)($latestSummary['fail'] ?? 0); ?>
        <div class="small mb-1"><?= $fail === 0 ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Total: <b><?= (int)($latestSummary['total'] ?? 0) ?></b></div>
        <div class="small">Pass: <b><?= (int)($latestSummary['pass'] ?? 0) ?></b> · Fail: <b><?= (int)($latestSummary['fail'] ?? 0) ?></b></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($latestSmoke['run_at'] ?? ''))) ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada smoke matrix run.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Smoke Rows</div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead><tr><th>Wrapper</th><th>Status</th><th>HTTP</th><th>Crash</th></tr></thead>
          <tbody>
            <?php if ($latestRows): foreach ($latestRows as $r): $row = is_array($r) ? $r : []; ?>
              <tr>
                <td><code><?= h((string)($row['path'] ?? '-')) ?></code></td>
                <td><?= !empty($row['ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></td>
                <td><?= (int)($row['http_code'] ?? 0) ?></td>
                <td><?= !empty($row['crash']) ? tools_badge('CRITICAL', 'YES') : tools_badge('HEALTHY', 'NO') ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="small text-muted">Belum ada data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Wrapper State Matrix</div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead><tr><th>Wrapper</th><th>State</th><th>Last Status</th><th>Last Run</th><th>State File</th></tr></thead>
          <tbody>
            <?php foreach ($stateRows as $r): ?>
              <tr>
                <td><code><?= h((string)$r['path']) ?></code></td>
                <td><?= !empty($r['state_exists']) ? tools_badge('HEALTHY', 'FOUND') : tools_badge('ATTENTION', 'MISSING') ?></td>
                <td>
                  <?php if ($r['state_ok'] === true): ?>
                    <?= tools_badge('HEALTHY', 'PASS') ?>
                  <?php elseif ($r['state_ok'] === false): ?>
                    <?= tools_badge('CRITICAL', 'FAIL') ?>
                  <?php else: ?>
                    <?= tools_badge('ATTENTION', 'UNKNOWN') ?>
                  <?php endif; ?>
                </td>
                <td><?= h(tools_fmt_ts((string)$r['last_run'])) ?></td>
                <td><code><?= h((string)$r['state_file']) ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
