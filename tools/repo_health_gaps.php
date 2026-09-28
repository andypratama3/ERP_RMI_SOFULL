<?php
declare(strict_types=1);

require_once __DIR__ . '/tools_remote_check.php';

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/tools_state_lib.php';
require_once __DIR__ . '/tools_ui_helpers.php';
require_once __DIR__ . '/tools_access_helpers.php';

tools_require_access('repo_health_gaps.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$msg = '';
$msgType = 'info';
$runOutput = [];
$runExit = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator unavailable');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'run_audit') {
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/qa/repo_health_gaps.php');
        @exec($cmd . ' 2>&1', $runOutput, $runExit);
        $runOutput = array_map('tools_mask_sensitive', $runOutput);
        if ((int)$runExit === 0) {
            $msg = 'Repo health gaps audit selesai: PASS.';
            $msgType = 'success';
        } else {
            $msg = 'Repo health gaps audit selesai: FAIL. Cek findings di bawah.';
            $msgType = 'warning';
        }
        ts_append_run_history('repo_health_gaps_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
            'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
            'source' => 'tools/repo_health_gaps.php',
        ]);
    }
}

$state = tools_read_state_json(ts_storage_logs_dir() . '/repo_health_gaps.last.json', ['overall_ok', 'summary', 'findings']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$summary = is_array($data['summary'] ?? null) ? (array)$data['summary'] : [];
$findings = is_array($data['findings'] ?? null) ? (array)$data['findings'] : [];
$overallOk = (bool)($data['overall_ok'] ?? false);
$runAt = (string)($data['run_at'] ?? '');
$reportMd = ts_storage_logs_dir() . '/repo_health_gaps_last.md';
$reportTxt = is_file($reportMd) ? tools_mask_sensitive((string)@file_get_contents($reportMd)) : '';

$baseProject = rmi_layout_base_project();
rmi_header('Repo Health Gaps', [
    'active' => 'tools',
    'subtitle' => 'Audit readiness gaps dari UI (engine tetap CLI).',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Repo Health Gaps'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <div class="fw-semibold">Overall Status</div>
          <?php if ($state['ok']): ?>
            <div class="small"><?= $overallOk ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?> · Last run: <b><?= h(tools_fmt_ts($runAt)) ?></b></div>
          <?php else: ?>
            <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada state valid. Jalankan audit dulu.</div>
          <?php endif; ?>
        </div>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
          <input type="hidden" name="action" value="run_audit">
          <button class="btn btn-rmi btn-sm" type="submit">Run Audit Now</button>
        </form>
      </div>
      <div class="small mt-2">
        CLI: <code>php tools/qa/repo_health_gaps.php</code> ·
        JSON: <code><?= h(ts_mask(ts_storage_logs_dir() . '/repo_health_gaps.last.json')) ?></code>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Summary</div>
      <?php if ($state['ok']): ?>
        <div class="small">Score: <b><?= (int)($summary['score'] ?? 0) ?>/100</b></div>
        <div class="small">Critical fail: <b><?= (int)($summary['critical_fail'] ?? 0) ?></b></div>
        <div class="small">High fail: <b><?= (int)($summary['high_fail'] ?? 0) ?></b></div>
        <div class="small">Warn: <b><?= (int)($summary['warn'] ?? 0) ?></b></div>
      <?php else: ?>
        <div class="small rmi-muted">No summary yet.</div>
      <?php endif; ?>
      <?php if ($runOutput): ?>
        <div class="fw-semibold mt-3 mb-1">Latest Run Output</div>
        <pre class="small mb-0" style="white-space:pre-wrap"><?= h(implode("\n", $runOutput)) ?></pre>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Findings</div>
      <?php if (!$findings): ?>
        <div class="small rmi-muted">No findings yet.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-dark-custom mb-0 align-middle">
            <thead><tr><th>Severity</th><th>ID</th><th>File</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($findings as $f): ?>
                <?php
                  $sev = strtoupper((string)($f['severity'] ?? 'UNKNOWN'));
                  $ok = !empty($f['ok']);
                ?>
                <tr>
                  <td><?= $sev === 'CRITICAL' ? tools_badge('CRITICAL', 'CRITICAL') : ($sev === 'HIGH' ? tools_badge('ATTENTION', 'HIGH') : tools_badge('UNKNOWN', $sev)) ?></td>
                  <td><code><?= h((string)($f['id'] ?? '-')) ?></code></td>
                  <td><code><?= h((string)($f['file'] ?? '-')) ?></code></td>
                  <td><?= $ok ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></td>
                </tr>
                <tr><td colspan="4" class="small rmi-muted"><?= h((string)($f['message'] ?? '')) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Markdown Report Snapshot</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h($reportTxt !== '' ? $reportTxt : 'report markdown belum tersedia') ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
