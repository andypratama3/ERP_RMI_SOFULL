<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('release/deploy_size_audit.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dsa_lock_path')) {
    function dsa_lock_path(): string
    {
        return ts_root() . '/storage/locks/deploy_size_audit.lock.json';
    }
}

if (!function_exists('dsa_is_pid_running')) {
    function dsa_is_pid_running(int $pid): bool
    {
        if ($pid <= 0) return false;
        $out = [];
        $code = 1;
        @exec('ps -p ' . (int)$pid . ' -o pid= 2>/dev/null', $out, $code);
        if ($code !== 0) return false;
        foreach ($out as $line) {
            if ((int)trim((string)$line) === $pid) return true;
        }
        return false;
    }
}

if (!function_exists('dsa_runtime')) {
    function dsa_runtime(): array
    {
        $lock = ts_read_json(dsa_lock_path());
        $pid = (int)($lock['pid'] ?? 0);
        if ($pid > 0 && dsa_is_pid_running($pid)) {
            return ['running' => true, 'pid' => $pid, 'started_at' => (string)($lock['started_at'] ?? '')];
        }
        if (is_file(dsa_lock_path())) {
            @unlink(dsa_lock_path());
        }
        return ['running' => false, 'pid' => 0, 'started_at' => (string)($lock['started_at'] ?? '')];
    }
}

if (!function_exists('dsa_start_async')) {
    function dsa_start_async(): array
    {
        $rt = dsa_runtime();
        if (!empty($rt['running'])) {
            return ['ok' => false, 'message' => 'Deploy size audit masih berjalan (PID ' . (int)$rt['pid'] . ').'];
        }
        $root = ts_root();
        require_once $root . '/_shared/env.php';
        if (function_exists('rmi_env_load')) rmi_env_load();
        $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
        $runner = $root . '/tools/release/deploy_size_audit_runner.php';
        $log = ts_storage_logs_dir() . '/deploy_size_audit_async.log';
        $cmd = 'nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($runner) . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        $pid = (int)trim((string)($out[0] ?? '0'));
        if ($code !== 0 || $pid <= 0) {
            return ['ok' => false, 'message' => 'Gagal start deploy size audit background.'];
        }
        ts_write_json(dsa_lock_path(), [
            'state_version' => 1,
            'pid' => $pid,
            'started_at' => date(DateTimeInterface::ATOM),
        ]);
        ts_append_run_history('deploy_size_audit_async_start', 'OK', [
            'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
            'source' => 'tools/release/deploy_size_audit.php',
            'pid' => $pid,
        ]);
        return ['ok' => true, 'message' => 'Deploy size audit berjalan di background (PID ' . $pid . ').'];
    }
}

$msg = '';
$msgType = 'info';

$sizeStatePath = ts_storage_logs_dir() . '/deploy_size_audit_last.json';
$runtime = dsa_runtime();

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
    if ($action === 'generate_audit') {
        $res = dsa_start_async();
        $msg = (string)($res['message'] ?? 'Deploy size audit diproses.');
        $msgType = !empty($res['ok']) ? 'success' : 'warning';
        $runtime = dsa_runtime();
    }
}

$state = tools_read_state_json($sizeStatePath, ['summary', 'excluded_hotspots_mb', 'deploy_zip', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$summary = is_array($data['summary'] ?? null) ? (array)$data['summary'] : [];
$hotspots = is_array($data['excluded_hotspots_mb'] ?? null) ? (array)$data['excluded_hotspots_mb'] : [];
$deployZip = is_array($data['deploy_zip'] ?? null) ? (array)$data['deploy_zip'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('Deploy Size Audit', [
    'active' => 'tools',
    'subtitle' => 'Audit ukuran clean deploy package + sumber bloat utama.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Deploy Size Audit'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
        <input type="hidden" name="action" value="generate_audit">
        <button class="btn btn-rmi btn-sm" type="submit" <?= !empty($runtime['running']) ? 'disabled' : '' ?>>Start Background Audit</button>
      </form>
      <div class="small mt-2">
        Runtime:
        <?= !empty($runtime['running']) ? tools_badge('ATTENTION', 'RUNNING PID ' . (int)($runtime['pid'] ?? 0)) : tools_badge('HEALTHY', 'IDLE') ?>
        · CLI: <code>php tools/release/deploy_size_audit_runner.php</code>
        · State: <code><?= h(ts_mask($sizeStatePath)) ?></code>
        · Log: <code><?= h(ts_mask(ts_storage_logs_dir() . '/deploy_size_audit_async.log')) ?></code>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Clean Package Metrics</div>
      <?php if ($state['ok']): ?>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
        <div class="small">Zip path: <code><?= h((string)($deployZip['zip_path'] ?? '-')) ?></code></div>
        <div class="small mt-2">Compressed ZIP: <b><?= h((string)($summary['zip_size_mb'] ?? 0)) ?> MB</b> (<?= h((string)($summary['zip_size_mib'] ?? 0)) ?> MiB)</div>
        <div class="small">Extracted content: <b><?= h((string)($summary['included_mb'] ?? 0)) ?> MB</b> (<?= h((string)($summary['included_mib'] ?? 0)) ?> MiB)</div>
        <div class="small mt-2">Files added: <b><?= (int)($deployZip['files_added'] ?? 0) ?></b> · Files skipped: <b><?= (int)($deployZip['files_skipped'] ?? 0) ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada data audit.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Top Size Hotspots (Excluded/Bloat)</div>
      <?php if (!$hotspots): ?>
        <div class="small rmi-muted">Belum ada snapshot ukuran direktori.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-dark-custom mb-0 align-middle">
            <thead><tr><th>Path</th><th>Size (MB)</th></tr></thead>
            <tbody>
              <?php foreach ($hotspots as $row): ?>
                <tr>
                  <td><code><?= h((string)($row['path'] ?? '-')) ?></code></td>
                  <td><b><?= (int)($row['size_mb'] ?? 0) ?></b></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Latest Run Output</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h($output ? implode("\n", $output) : 'Belum ada output.') ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
