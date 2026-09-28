<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/_lib/bootstrap.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/tools_ui_helpers.php';
require_once __DIR__ . '/tools_state_lib.php';
require_once __DIR__ . '/tools_access_helpers.php';

tools_require_access('smoke_schedule.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

require_once __DIR__ . '/tools_remote_check.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function ss_tail(string $file, int $n = 120): array {
    if (!is_file($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
    return array_slice($lines, -$n);
}

function ss_exec(string $cmd, int $timeoutSec = 30): array {
    $descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptorspec, $pipes);
    if (!is_resource($proc)) return ['exit_code' => 1, 'output' => ['ERROR: cannot start process']];
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $start = time();
    $stdout = '';
    $stderr = '';
    $timedOut = false;
    while (true) {
        $status = proc_get_status($proc);
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        if (!$status['running']) break;
        if ((time() - $start) >= $timeoutSec) {
            proc_terminate($proc, 15);
            $timedOut = true;
            break;
        }
        usleep(120000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($timedOut) $code = 124;
    $merged = trim($stdout . "\n" . $stderr);
    $lines = $merged === '' ? [] : preg_split('/\R/', $merged);
    return ['exit_code' => (int)$code, 'output' => array_values(array_filter($lines, static fn($x) => trim((string)$x) !== ''))];
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$logDir = $root . '/storage/logs';
$stateFile = $logDir . '/smoke_nightly_schedule.state';
$runStateFile = $logDir . '/smoke_nightly.state';
$logFile = $logDir . '/smoke_nightly.log';

$installScript = $root . '/tools/install_daily_smoke_2330.sh';
$uninstallScript = $root . '/tools/uninstall_daily_smoke_2330.sh';
$checkScript = $root . '/tools/check_daily_smoke_2330.sh';
$runScript = $root . '/tools/smoke_nightly.sh';

@mkdir($logDir, 0775, true);
@touch($logFile);

$action = trim((string)($_POST['action'] ?? 'check'));
$output = [];
$exitCode = 0;
$flash = '';
$err = '';
$actor = function_exists('current_actor_username') ? current_actor_username() : ((string)($_SESSION['username'] ?? 'SYSTEM'));

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require_post();
    verify_csrf();
}

function ss_has_cron_permission_error(array $lines): bool {
    $joined = strtolower(implode(' | ', $lines));
    return str_contains($joined, 'operation not permitted')
        || str_contains($joined, 'not permitted')
        || str_contains($joined, 'permission denied')
        || str_contains($joined, 'crontab:');
}

function ss_read_smoke_http_failure(string $root): array {
    $f = $root . '/storage/logs/smoke_http_last.json';
    if (!is_file($f)) {
        return ['fail' => 0, 'items' => []];
    }
    $raw = (string)@file_get_contents($f);
    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        return ['fail' => 0, 'items' => []];
    }
    $fail = (int)($dec['fail'] ?? 0);
    $checks = is_array($dec['checks'] ?? null) ? $dec['checks'] : [];
    $items = [];
    foreach ($checks as $row) {
        if (!is_array($row) || !empty($row['ok'])) {
            continue;
        }
        $items[] = trim((string)($row['name'] ?? 'unknown') . ' :: ' . (string)($row['detail'] ?? ''));
        if (count($items) >= 3) {
            break;
        }
    }
    return ['fail' => $fail, 'items' => $items];
}

if ($action === 'install') {
    $res = ss_exec('/bin/bash ' . escapeshellarg($installScript), 25);
    $output = $res['output'];
    $exitCode = $res['exit_code'];
    if ($exitCode === 0) {
        $flash = 'Scheduler nightly smoke berhasil diaktifkan (23:30 WIB).';
    } else {
        $err = ss_has_cron_permission_error($output)
            ? 'Gagal install scheduler via crontab (permission terbatas). Jalankan scheduler dari panel host/cron OS, atau gunakan Run Smoke Now manual.'
            : 'Gagal install scheduler nightly smoke.';
    }
    ts_append_run_history('smoke_scheduler_enable', $exitCode === 0 ? 'ok' : 'fail', ['actor_username' => $actor, 'source' => 'smoke_schedule.php']);
    try {
        $pdo = rmi_db_pdo(); erp_audit_ensure($pdo);
        audit_event($pdo, 'SMOKE_SCHEDULER_ENABLE', 'TOOLS_SMOKE', 'SCHEDULER', '2330', 'Enable nightly smoke schedule', [
            'actor_username' => $actor,
            'status' => $exitCode === 0 ? 'ok' : 'failed',
            'output_lines' => count($output),
        ]);
    } catch (Throwable $e) {}
} elseif ($action === 'uninstall') {
    $res = ss_exec('/bin/bash ' . escapeshellarg($uninstallScript), 25);
    $output = $res['output'];
    $exitCode = $res['exit_code'];
    if ($exitCode === 0) {
        $flash = 'Scheduler nightly smoke berhasil dimatikan.';
    } else {
        $err = ss_has_cron_permission_error($output)
            ? 'Gagal uninstall scheduler via crontab (permission terbatas).'
            : 'Gagal uninstall scheduler nightly smoke.';
    }
    ts_append_run_history('smoke_scheduler_disable', $exitCode === 0 ? 'ok' : 'fail', ['actor_username' => $actor, 'source' => 'smoke_schedule.php']);
    try {
        $pdo = rmi_db_pdo(); erp_audit_ensure($pdo);
        audit_event($pdo, 'SMOKE_SCHEDULER_DISABLE', 'TOOLS_SMOKE', 'SCHEDULER', '2330', 'Disable nightly smoke schedule', [
            'actor_username' => $actor,
            'status' => $exitCode === 0 ? 'ok' : 'failed',
            'output_lines' => count($output),
        ]);
    } catch (Throwable $e) {}
} elseif ($action === 'run_now') {
    $res = ss_exec('/bin/bash ' . escapeshellarg($runScript), 240);
    $output = $res['output'];
    $exitCode = $res['exit_code'];
    if ($exitCode === 0) $flash = 'Nightly smoke test berhasil dijalankan sekarang.';
    else {
        $f = ss_read_smoke_http_failure($root);
        $joined = strtolower(implode(' | ', $output));
        if (str_contains($joined, 'db connect failed')) {
            $err = 'Nightly smoke test gagal: koneksi database gagal pada preflight.';
        } elseif (str_contains($joined, 'php runtime not found')) {
            $err = 'Nightly smoke test gagal: runtime PHP tidak ditemukan untuk eksekusi scheduler.';
        } elseif ((int)$f['fail'] > 0) {
            $err = 'Nightly smoke test gagal: ada ' . (int)$f['fail'] . ' check FAIL (lihat ringkasan di Command Output).';
        } else {
            $err = 'Nightly smoke test gagal.';
        }
        if ((int)$f['fail'] > 0) {
            $output[] = '--- smoke_http_last.json failure summary ---';
            $output[] = 'fail_count=' . (int)$f['fail'];
            foreach ($f['items'] as $it) {
                $output[] = '- ' . $it;
            }
            $output[] = 'detail_file=storage/logs/smoke_http_last.md';
        }
    }
    ts_append_run_history('smoke_run', $exitCode === 0 ? 'ok' : 'fail', ['actor_username' => $actor, 'source' => 'smoke_schedule.php', 'exit_code' => $exitCode]);
    try {
        $pdo = rmi_db_pdo(); erp_audit_ensure($pdo);
        audit_event($pdo, 'SMOKE_RUN_NOW', 'TOOLS_SMOKE', 'RUNNER', 'nightly', 'Run smoke nightly now', [
            'actor_username' => $actor,
            'status' => $exitCode === 0 ? 'ok' : 'failed',
            'output_lines' => count($output),
        ]);
    } catch (Throwable $e) {}
} else {
    $res = ss_exec('/bin/bash ' . escapeshellarg($checkScript), 20);
    $output = $res['output'];
    $exitCode = $res['exit_code'];
    try {
        $pdo = rmi_db_pdo(); erp_audit_ensure($pdo);
        audit_event($pdo, 'SMOKE_SCHEDULER_CHECK', 'TOOLS_SMOKE', 'SCHEDULER', '2330', 'Check nightly smoke schedule', [
            'actor_username' => $actor,
            'status' => $exitCode === 0 ? 'ok' : 'failed',
        ]);
    } catch (Throwable $e) {}
}

$state = is_file($stateFile) ? (string)@file_get_contents($stateFile) : '';
$runState = is_file($runStateFile) ? (string)@file_get_contents($runStateFile) : '';
$tail = ss_tail($logFile, 120);
$stateMap = [];
foreach (preg_split('/\R/', $state) as $line) {
    $line = trim((string)$line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
    $stateMap[trim($k)] = trim($v, "\"'");
}
$runMap = [];
foreach (preg_split('/\R/', $runState) as $line) {
    $line = trim((string)$line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
    $runMap[trim($k)] = trim($v, "\"'");
}
$enabled = ((string)($stateMap['enabled'] ?? '0') === '1');
$schedulerMode = strtolower((string)($stateMap['scheduler_mode'] ?? 'unknown'));
$entryPresent = ((string)($stateMap['entry_present'] ?? '0') === '1');
if (!$entryPresent) {
    $entryPresent = in_array($schedulerMode, ['cron', 'launchd'], true) || (stripos($state, 'cron=') !== false);
}
$lastRunAt = (string)($runMap['last_run_at'] ?? '');
$lastStatus = strtoupper((string)($runMap['last_status'] ?? 'UNKNOWN'));
$level = 'ATTENTION';
if ($enabled && $entryPresent && $lastStatus === 'PASS') $level = 'HEALTHY';
if (!$enabled || !$entryPresent || $lastStatus === 'FAIL') $level = 'CRITICAL';
$baseProject = rmi_layout_base_project();
$cutoverLink = tools_cutover_one_pager_link($baseProject);

rmi_header('Nightly Smoke Scheduler', [
    'active' => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Nightly Smoke Scheduler',
    ],
]);
?>
<div class="row g-3">
  <div class="col-12">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">Nightly Smoke Scheduler (23:30 WIB)</h5>
        <div class="d-flex gap-2 align-items-center">
          <?= tools_badge($level) ?>
          <a class="btn btn-sm btn-outline-light" href="<?= h($cutoverLink) ?>">Cutover One Pager</a>
          <a class="btn btn-sm btn-outline-light" href="<?= h($baseProject) ?>/tools/index.php">Back to Tools</a>
        </div>
      </div>
      <div class="muted mt-2">Install/check/uninstall scheduler smoke harian via UI admin. Engine: <code>tools/smoke_nightly.sh</code>.</div>
      <?php if ($flash !== ''): ?><div class="alert alert-success mt-3 mb-0"><?= h($flash) ?></div><?php endif; ?>
      <?php if ($err !== ''): ?><div class="alert alert-danger mt-3 mb-0"><?= h($err) ?></div><?php endif; ?>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <form method="post" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
          <input type="hidden" name="action" value="install">
          <button class="btn btn-primary btn-sm" type="submit">Install 23:30 WIB</button>
        </form>
        <form method="post" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
          <input type="hidden" name="action" value="check">
          <button class="btn btn-outline-light btn-sm" type="submit">Check Status</button>
        </form>
        <form method="post" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
          <input type="hidden" name="action" value="run_now">
          <button class="btn btn-outline-success btn-sm" type="submit">Run Smoke Now</button>
        </form>
        <form method="post" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
          <input type="hidden" name="action" value="uninstall">
          <button class="btn btn-outline-danger btn-sm" type="submit" onclick="return confirm('Matikan scheduler nightly smoke?')">Uninstall</button>
        </form>
      </div>
      <div class="muted small mt-2">Scheduler mode terdeteksi: <code><?= h(strtoupper($schedulerMode)) ?></code></div>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="row g-3">
        <div class="col-md-3"><div class="rmi-muted">Scheduler</div><div class="fw-semibold"><?= tools_badge($enabled ? 'ENABLED' : 'DISABLED') ?></div></div>
        <div class="col-md-3"><div class="rmi-muted">Scheduler Entry</div><div class="fw-semibold"><?= tools_badge($entryPresent ? 'PRESENT' : 'MISSING') ?></div></div>
        <div class="col-md-3"><div class="rmi-muted">Last Run</div><div class="fw-semibold"><?= h(tools_fmt_ts($lastRunAt)) ?></div></div>
        <div class="col-md-3"><div class="rmi-muted">Last Status</div><div class="fw-semibold"><?php if ($lastStatus === 'PASS') { echo tools_badge('PASS'); } elseif ($lastStatus === 'FAIL') { echo tools_badge('FAIL'); } else { echo tools_badge('UNKNOWN'); } ?></div></div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card p-3">
      <h6>Schedule State</h6>
      <pre class="small mb-0" style="max-height:180px; overflow:auto;"><?= h(tools_mask_sensitive($state)) ?></pre>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card p-3">
      <h6>Run State</h6>
      <pre class="small mb-0" style="max-height:180px; overflow:auto;"><?= h(tools_mask_sensitive($runState)) ?></pre>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <h6>Command Output</h6>
      <pre class="small mb-0" style="max-height:220px; overflow:auto;"><?= h(tools_mask_sensitive(implode("\n", $output))) ?></pre>
    </div>
  </div>
  <div class="col-12">
    <div class="card p-3">
      <h6>Nightly Smoke Log (tail)</h6>
      <pre class="small mb-0" style="max-height:240px; overflow:auto;"><?= h(tools_mask_sensitive(implode("\n", $tail))) ?></pre>
      <div class="muted small mt-2">Log file: <code><?= h(tools_mask_sensitive($logFile)) ?></code></div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>

