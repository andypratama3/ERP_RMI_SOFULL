<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

function glr_compact_step_json(string $title, array $json): array
{
    $t = strtolower($title);
    if (str_contains($t, 'verify')) {
        return [
            'checked_at' => (string)($json['checked_at'] ?? ''),
            'mode' => (string)($json['mode'] ?? ''),
            'summary' => [
                'OK_packs' => (int)($json['summary']['OK_packs'] ?? 0),
                'WARN_packs' => (int)($json['summary']['WARN_packs'] ?? 0),
                'FAIL_packs' => (int)($json['summary']['FAIL_packs'] ?? 0),
                'latest_pack' => (string)($json['summary']['latest_pack'] ?? ''),
            ],
        ];
    }
    if (str_contains($t, 'handover')) {
        return [
            'status' => (string)($json['status'] ?? 'UNKNOWN'),
            'zip_path' => (string)($json['zip_path'] ?? ''),
            'included_files' => (int)($json['summary']['included_files'] ?? $json['included_files'] ?? 0),
            'missing_required' => (int)($json['summary']['missing_required'] ?? count((array)($json['missing_required'] ?? []))),
        ];
    }
    if (str_contains($t, 'closeout')) {
        return [
            'ok' => (bool)($json['ok'] ?? false),
            'path' => (string)($json['path'] ?? ''),
        ];
    }
    return $json;
}

function glr_run_step(string $title, string $scriptPath, array $args = []): array
{
    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php';
    $argStr = '';
    foreach ($args as $a) {
        $argStr .= ' ' . escapeshellarg((string)$a);
    }
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . $argStr . ' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    $stdout = trim(implode("\n", $out));
    $json = [];
    if ($stdout !== '') {
        $decoded = json_decode($stdout, true);
        if (is_array($decoded)) {
            $json = $decoded;
        } else {
            $lines = preg_split('/\r?\n/', $stdout) ?: [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $d = json_decode((string)$lines[$i], true);
                if (is_array($d)) {
                    $json = $d;
                    break;
                }
            }
        }
    }
    $tail = substr($stdout, -800);
    $tail = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $tail) ?? '';
    return [
        'title' => $title,
        'command_masked' => opsgov_mask($cmd),
        'exit_code' => $code,
        'ok' => $code === 0,
        'output_tail' => opsgov_mask($tail),
        'json' => glr_compact_step_json($title, $json),
    ];
}

function glr_compute_status(array $steps): string
{
    foreach ($steps as $s) {
        if (empty($s['ok'])) return 'FAIL';
    }
    $handover = (array)($steps[1]['json'] ?? []);
    $verify = (array)($steps[2]['json'] ?? []);
    $handoverStatus = strtoupper((string)($handover['status'] ?? 'UNKNOWN'));
    $failPacks = (int)($verify['summary']['FAIL_packs'] ?? 1);
    $warnPacks = (int)($verify['summary']['WARN_packs'] ?? 0);
    if ($failPacks > 0 || $handoverStatus === 'FAIL') return 'FAIL';
    if ($warnPacks > 0 || $handoverStatus === 'WARN') return 'WARN';
    return 'PASS';
}

function go_live_readiness_run(bool $latestOnlyVerify = true): array
{
    $root = opsgov_root();
    $steps = [
        glr_run_step('Program Closeout Audit', $root . '/tools/qa/program_closeout_audit.php'),
        glr_run_step('Generate Handover Final Pack', $root . '/tools/compliance/handover_final_pack.php'),
        glr_run_step('Verify Handover Final Pack', $root . '/tools/compliance/verify_handover_final_pack.php', $latestOnlyVerify ? ['--latest=1'] : []),
    ];
    $status = glr_compute_status($steps);
    $payload = [
        'state_version' => 1,
        'checked_at' => date(DateTimeInterface::ATOM),
        'status' => $status,
        'steps' => $steps,
        'artifacts' => [
            'closeout_json' => '[APP_ROOT]/storage/logs/program_closeout_audit_last.json',
            'handover_json' => '[APP_ROOT]/storage/logs/handover_final_pack_last.json',
            'handover_verify_json' => '[APP_ROOT]/storage/logs/handover_final_verify_last.json',
        ],
    ];
    opsgov_safe_write_json($root . '/storage/logs/go_live_readiness_one_click_last.json', $payload);

    $md = [];
    $md[] = '# Go Live Readiness One Click';
    $md[] = '';
    $md[] = '- Checked at: ' . $payload['checked_at'];
    $md[] = '- Status: ' . $payload['status'];
    $md[] = '';
    $md[] = '## Steps';
    foreach ($steps as $s) {
        $md[] = '- ' . (string)$s['title'] . ': ' . (!empty($s['ok']) ? 'OK' : 'FAIL') . ' (exit=' . (int)$s['exit_code'] . ')';
    }
    @file_put_contents($root . '/storage/logs/go_live_readiness_one_click_last.md', implode("\n", $md) . "\n");

    ts_append_run_history('go_live_readiness_one_click', $status === 'FAIL' ? 'FAIL' : 'OK', [
        'module' => 'tools.release',
        'action' => 'one_click',
        'result' => $status,
    ]);
    return $payload;
}

function glr_lock_path(): string
{
    return opsgov_root() . '/storage/locks/go_live_readiness_one_click.lock.json';
}

function glr_is_pid_running(int $pid): bool
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

function glr_runtime_status(): array
{
    $lock = opsgov_read_json(glr_lock_path());
    $pid = (int)($lock['pid'] ?? 0);
    if ($pid > 0 && glr_is_pid_running($pid)) {
        return ['running' => true, 'pid' => $pid];
    }
    if (is_file(glr_lock_path())) {
        @unlink(glr_lock_path());
    }
    return ['running' => false, 'pid' => $pid];
}

function glr_start_async(bool $latestOnly): array
{
    $rt = glr_runtime_status();
    if (!empty($rt['running'])) {
        return ['ok' => false, 'message' => 'One-click masih berjalan (PID ' . (int)$rt['pid'] . ').'];
    }
    $root = opsgov_root();
    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php';
    $script = $root . '/tools/release/go_live_readiness_one_click.php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script);
    if (!$latestOnly) {
        $cmd .= ' --verify-all';
    }
    $log = $root . '/storage/logs/go_live_readiness_one_click_async.log';
    $spawn = 'nohup ' . $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
    $out = [];
    $code = 1;
    @exec($spawn, $out, $code);
    $pid = (int)trim((string)($out[0] ?? '0'));
    if ($code !== 0 || $pid <= 0) {
        return ['ok' => false, 'message' => 'Gagal start one-click async.'];
    }
    opsgov_safe_write_json(glr_lock_path(), [
        'state_version' => 1,
        'pid' => $pid,
        'started_at' => date(DateTimeInterface::ATOM),
        'verify_mode' => $latestOnly ? 'latest_only' : 'all_packs',
    ]);
    return ['ok' => true, 'message' => 'One-click dijalankan di background (PID ' . $pid . ').'];
}

if (PHP_SAPI === 'cli') {
    $latestOnly = true;
    foreach (($_SERVER['argv'] ?? []) as $arg) {
        if ((string)$arg === '--verify-all') {
            $latestOnly = false;
        }
    }
    echo json_encode(go_live_readiness_run($latestOnly), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

opsgov_require_admin();
$result = opsgov_read_json(opsgov_root() . '/storage/logs/go_live_readiness_one_click_last.json');
$runtime = glr_runtime_status();
$flash = '';
if (($_GET['glr_action'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'running' => !empty($runtime['running']),
        'pid' => (int)($runtime['pid'] ?? 0),
        'status' => strtoupper((string)($result['status'] ?? 'UNKNOWN')),
        'checked_at' => (string)($result['checked_at'] ?? ''),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $latestOnly = ((string)($_POST['verify_mode'] ?? 'latest_only')) !== 'all_packs';
    $start = glr_start_async($latestOnly);
    $runtime = glr_runtime_status();
    $flash = (string)($start['message'] ?? 'Request diproses.');
}

$status = strtoupper((string)($result['status'] ?? 'UNKNOWN'));
$alertClass = $status === 'PASS' ? 'alert-success' : ($status === 'WARN' ? 'alert-warning' : ($status === 'FAIL' ? 'alert-danger' : 'alert-secondary'));
$resultPretty = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($resultPretty) || $resultPretty === '') {
    $resultPretty = '{"error":"unable_to_render_json"}';
}
$baseProject = rmi_layout_base_project();
rmi_header('Go Live Readiness One Click', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Go Live Readiness One Click']]);
?>
<div class="card p-3">
  <div class="small mb-2">Eksekusi berurutan: closeout audit → handover pack → verify handover, lalu hasilkan status final merah/kuning/hijau.</div>
  <?php if ($flash !== ''): ?>
    <div class="alert alert-info py-2 small"><?= opsgov_h($flash) ?></div>
  <?php endif; ?>
  <form method="post" class="mb-3">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <select name="verify_mode" class="form-select form-select-sm mb-2" style="max-width:220px;">
      <option value="latest_only">Verify latest pack only (cepat)</option>
      <option value="all_packs">Verify all packs (lebih berat)</option>
    </select>
    <button class="btn btn-sm btn-rmi" <?= !empty($runtime['running']) ? 'disabled' : '' ?>>Run One Click</button>
    <span id="glrRunningBadge" class="badge text-bg-warning ms-2" style="<?= !empty($runtime['running']) ? '' : 'display:none;' ?>">RUNNING (PID <span id="glrPid"><?= opsgov_h((string)($runtime['pid'] ?? '-')) ?></span>)</span>
  </form>

  <div class="alert <?= opsgov_h($alertClass) ?> py-2 small">
    Final status: <?= tools_badge($status) ?>
  </div>

  <details class="mt-2">
    <summary class="small text-muted" style="cursor:pointer;">Tampilkan raw JSON result</summary>
    <pre class="mb-0 mt-2"><?= opsgov_h($resultPretty) ?></pre>
  </details>
</div>
<script>
(function () {
  const runningBadge = document.getElementById('glrRunningBadge');
  const pidEl = document.getElementById('glrPid');
  function poll() {
    fetch('<?= opsgov_h($baseProject . '/tools/release/go_live_readiness_one_click.php') ?>?glr_action=status&_=' + Date.now(), {cache:'no-store'})
      .then(r => r.json())
      .then(j => {
        if (!j || !j.ok || !runningBadge) return;
        if (j.running) {
          runningBadge.style.display = '';
          if (pidEl) pidEl.textContent = String(j.pid || '-');
        } else {
          runningBadge.style.display = 'none';
        }
      })
      .catch(() => {});
  }
  setInterval(poll, 8000);
})();
</script>
<?php rmi_footer(); ?>
