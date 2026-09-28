<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/_lib/plan_exec_summary_view_lib.php';
require_once __DIR__ . '/_lib/pinned_links_panel_lib.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

tools_require_access('ops/control_center.php');
require_login();
if (function_exists('require_any_permission')) {
    require_once __DIR__ . '/../../_shared/rbac.php';
    require_any_permission(['TOOLS.VIEW']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

if (!function_exists('h')) {
    function h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

function occ_level_by_age(?int $ageSec): string {
    if ($ageSec === null) return 'UNKNOWN';
    if ($ageSec <= 3600) return 'OK';
    if ($ageSec <= 86400) return 'WARN';
    return 'FAIL';
}

function occ_state_row(string $label, string $path): array {
    $exists = is_file($path);
    $mtime = $exists ? (int)@filemtime($path) : 0;
    $age = $exists && $mtime > 0 ? max(0, time() - $mtime) : null;
    return [
        'label' => $label,
        'path_masked' => tools_mask_sensitive($path),
        'exists' => $exists,
        'age_sec' => $age,
        'status' => occ_level_by_age($age),
    ];
}

function occ_php_error_row(string $root): array {
    $path = $root . '/storage/logs/pipeline/php_error_scan_last.json';
    $exists = is_file($path);
    $data = $exists ? ts_read_json($path) : [];
    $counts = (array)($data['counts'] ?? []);
    $fatal = (int)($counts['fatal'] ?? 0);
    $warn = (int)($counts['warn'] ?? 0);
    $status = 'OK';
    if (!$exists) {
        $status = 'ATTENTION';
    } elseif ($fatal > 0) {
        $status = 'CRITICAL';
    } elseif ($warn > 0) {
        $status = 'ATTENTION';
    }
    $mtime = $exists ? (int)@filemtime($path) : 0;
    $age = $exists && $mtime > 0 ? max(0, time() - $mtime) : null;
    return [
        'label' => 'PHP Errors',
        'path_masked' => tools_mask_sensitive($path),
        'exists' => $exists,
        'age_sec' => $age,
        'status' => $status,
        'fix_command' => 'php tools/qa/php_error_scan.php --write-last',
        'counts' => $counts,
    ];
}

function occ_status_rank(string $status): int {
    $s = strtoupper($status);
    if ($s === 'FAIL' || $s === 'CRITICAL') return 0;
    if ($s === 'WARN' || $s === 'ATTENTION') return 1;
    if ($s === 'UNKNOWN') return 2;
    return 3;
}

function occ_badge_class(string $status): string
{
    $s = strtoupper($status);
    if ($s === 'PASS' || $s === 'OK') return 'text-bg-success';
    if ($s === 'WARN' || $s === 'ATTENTION') return 'text-bg-warning';
    if ($s === 'FAIL' || $s === 'CRITICAL') return 'text-bg-danger';
    return 'text-bg-secondary';
}

function occ_one_click_lock_path(string $root): string
{
    return $root . '/storage/locks/go_live_one_click.lock.json';
}

function occ_is_pid_running(int $pid): bool
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

function occ_one_click_async_status(string $root): array
{
    $lockPath = occ_one_click_lock_path($root);
    $lock = ts_read_json($lockPath);
    $pid = (int)($lock['pid'] ?? 0);
    if ($pid > 0 && occ_is_pid_running($pid)) {
        return ['running' => true, 'pid' => $pid];
    }
    if (is_file($lockPath)) @unlink($lockPath);
    return ['running' => false, 'pid' => $pid];
}

function occ_start_one_click_async(string $root): array
{
    $running = occ_one_click_async_status($root);
    if (!empty($running['running'])) {
        return ['started' => false, 'message' => 'One-click masih berjalan (background).'];
    }
    $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php');
    $script = $root . '/tools/release/go_live_readiness_one_click.php';
    $log = $root . '/storage/logs/go_live_readiness_one_click_async.log';
    $cmd = 'nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --latest=1'
        . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    $pid = (int)trim((string)($out[0] ?? '0'));
    if ($code !== 0 || $pid <= 0) {
        return ['started' => false, 'message' => 'Gagal start one-click async.'];
    }
    ts_write_json(occ_one_click_lock_path($root), [
        'state_version' => 1,
        'pid' => $pid,
        'started_at' => date(DateTimeInterface::ATOM),
    ]);
    return ['started' => true, 'message' => 'One-click dijalankan di background.'];
}

$root = dirname(__DIR__, 2);
$oneClickResult = ts_read_json($root . '/storage/logs/go_live_readiness_one_click_last.json');
$oneClickAsync = occ_one_click_async_status($root);

if (($_GET['occ_action'] ?? '') === 'one_click_status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'status' => strtoupper((string)($oneClickResult['status'] ?? 'UNKNOWN')),
        'checked_at' => (string)($oneClickResult['checked_at'] ?? ''),
        'running' => !empty($oneClickAsync['running']),
        'pid' => (int)($oneClickAsync['pid'] ?? 0),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$runMessage = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'run_one_click_embedded') {
        if (!function_exists('tools_can_access_web_tool') || tools_can_access_web_tool('release/go_live_readiness_one_click.php')) {
            $start = occ_start_one_click_async($root);
            $oneClickAsync = occ_one_click_async_status($root);
            $runMessage = (string)($start['message'] ?? 'Request diproses.');
        } else {
            $runMessage = 'Akses ditolak oleh RBAC matrix.';
        }
    }
}

$rows = [
    occ_state_row('Smoke HTTP', $root . '/storage/logs/smoke_http_last.json'),
    occ_state_row('Release Gate', $root . '/storage/logs/release_gate_last.json'),
    occ_state_row('Evidence Status', $root . '/storage/logs/evidence_status_last.json'),
    occ_state_row('SOP Link Verify', $root . '/storage/logs/sop_link_verify_last.json'),
    occ_state_row('Tools Run History', $root . '/storage/logs/tools_run_history.jsonl'),
    occ_php_error_row($root),
];
$releaseVerifyState = ts_read_json($root . '/storage/state/release_verify_all_last.json');
$releaseVerifySummary = is_array($releaseVerifyState['summary'] ?? null) ? (array)$releaseVerifyState['summary'] : [];
$releaseVerifyBadge = 'WARN';
if (!empty($releaseVerifyState)) {
    $bf = (int)($releaseVerifySummary['bundle_fail'] ?? 0);
    $bw = (int)($releaseVerifySummary['bundle_warn'] ?? 0);
    if ($bf > 0) $releaseVerifyBadge = 'FAIL';
    elseif ($bw > 0) $releaseVerifyBadge = 'WARN';
    else $releaseVerifyBadge = 'OK';
}
$prio = [
    'Release Gate' => 10,
    'Smoke HTTP' => 20,
    'Evidence Status' => 30,
    'SOP Link Verify' => 40,
    'Tools Run History' => 50,
    'PHP Errors' => 55,
];
foreach ($rows as &$r) {
    $r['priority'] = (int)($prio[(string)$r['label']] ?? 999);
}
unset($r);
usort($rows, static function(array $a, array $b): int {
    if ((int)$a['priority'] !== (int)$b['priority']) {
        return (int)$a['priority'] <=> (int)$b['priority'];
    }
    return occ_status_rank((string)$a['status']) <=> occ_status_rank((string)$b['status']);
});
$warnOrFail = array_values(array_filter($rows, static fn(array $r): bool => in_array((string)$r['status'], ['WARN', 'FAIL', 'ATTENTION', 'CRITICAL'], true)));

$planExecState = pesv_load_panel_state();
$baseProject = rmi_layout_base_project();
rmi_header('Ops Control Center', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Ops Control Center']]);
?>
<style>
  body { background: #03102a !important; }
  .occ-subtle { color: rgba(230, 238, 255, .88); }
  .occ-grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
  .occ-card {
    background: rgba(9, 19, 40, .70);
    border: 1px solid rgba(255, 255, 255, .14);
    border-radius: 12px;
    padding: 12px;
    color: #eaf1ff;
  }
  .occ-k { font-size: 11px; letter-spacing: .4px; text-transform: uppercase; color: rgba(210, 224, 255, .7); }
  .occ-v { font-size: 13px; color: #f5f8ff; }
  .occ-v code { color: #d8e4ff; background: rgba(255,255,255,.08); padding: 2px 6px; border-radius: 6px; }
  .occ-card-warn { border-color: rgba(255,193,7,.55); box-shadow: 0 0 0 1px rgba(255,193,7,.2) inset; }
  .occ-card-fail { border-color: rgba(220,53,69,.65); box-shadow: 0 0 0 1px rgba(220,53,69,.24) inset; }
</style>
<div class="card p-3">
  <div class="fw-semibold mb-2">Control Center Status</div>
  <div class="small occ-subtle mb-3">Semua status mengikuti standar `OK/WARN/FAIL/UNKNOWN` + freshness age.</div>
  <?php if ($runMessage !== ''): ?>
    <div class="alert alert-info py-2 small"><?= h($runMessage) ?></div>
  <?php endif; ?>
  <?php
  $execState = plp_load_exec_summary_state();
  $planState = plp_load_plan_exec_state();
  $archState = plp_load_arch_audit_state();
  ?>
  <div class="mb-3">
    <div class="fw-semibold mb-2">Pinned Links (Latest)</div>
    <div class="occ-grid">
      <div class="occ-card <?= !$execState['ok'] ? 'occ-card-warn' : (in_array($execState['badge'], ['NO-GO','CRITICAL'], true) ? 'occ-card-fail' : '') ?>">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold">Executive Ops Summary ULTIMATE</div>
          <div><?= tools_badge($execState['badge']) ?></div>
        </div>
        <div class="occ-k">Freshness</div>
        <div class="occ-v mb-2"><?= $execState['age_hours'] !== null ? h((string)$execState['age_hours']) . 'h' : '-' ?></div>
        <?php if ($execState['ok']): ?>
          <a href="<?= h($baseProject . '/tools/ops/executive_summary_view.php?mode=html&file=ultimate') ?>" class="btn btn-sm btn-outline-light">Open</a>
        <?php else: ?>
          <div class="occ-k">Fix command</div>
          <div class="occ-v"><code class="small"><?= h($execState['fix_command']) ?></code></div>
        <?php endif; ?>
      </div>
      <div class="occ-card <?= !$planState['ok'] ? 'occ-card-warn' : (in_array($planState['badge'], ['NO-GO','CRITICAL'], true) ? 'occ-card-fail' : '') ?>">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold">Plan Exec Summary</div>
          <div><?= tools_badge($planState['badge']) ?></div>
        </div>
        <div class="occ-k">Freshness</div>
        <div class="occ-v mb-2"><?= $planState['age_hours'] !== null ? h((string)$planState['age_hours']) . 'h' : '-' ?></div>
        <?php if ($planState['ok']): ?>
          <a href="<?= h($baseProject . '/tools/ops/plan_exec_summary_view.php?mode=html&file=last') ?>" class="btn btn-sm btn-outline-light">Open</a>
        <?php else: ?>
          <div class="occ-k">Fix command</div>
          <div class="occ-v"><code class="small"><?= h($planState['fix_command']) ?></code></div>
        <?php endif; ?>
      </div>
      <div class="occ-card <?= !$archState['ok'] ? 'occ-card-warn' : (in_array($archState['badge'], ['CRITICAL'], true) ? 'occ-card-fail' : '') ?>">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold">Architecture Audit One Pager</div>
          <div><?= tools_badge($archState['badge']) ?></div>
        </div>
        <div class="occ-k">Freshness</div>
        <div class="occ-v mb-2"><?= $archState['age_hours'] !== null ? h((string)$archState['age_hours']) . 'h' : '-' ?></div>
        <?php if ($archState['ok']): ?>
          <a href="<?= h($baseProject . '/tools/ops/arch_audit_view.php?mode=md') ?>" class="btn btn-sm btn-outline-light">Open</a>
        <?php else: ?>
          <div class="occ-k">Fix command</div>
          <div class="occ-v"><code class="small"><?= h($archState['fix_command']) ?></code></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($warnOrFail): ?>
    <div class="alert alert-warning py-2 small">
      Perhatian: ditemukan <?= h((string)count($warnOrFail)) ?> panel dengan status WARN/FAIL.
    </div>
  <?php endif; ?>
  <div class="mb-3 d-flex flex-wrap gap-2">
    <?php if (!function_exists('tools_can_access_web_tool') || tools_can_access_web_tool('ops/refresh_control_center.php')): ?>
      <button type="button" id="occRefreshBtn" class="btn btn-sm btn-outline-light" data-refresh-url="<?= h($baseProject . '/tools/ops/refresh_control_center.php') ?>">Refresh Control Center</button>
    <?php endif; ?>
    <?php if (!function_exists('tools_can_access_web_tool') || tools_can_access_web_tool('release/go_live_readiness_one_click.php')): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <input type="hidden" name="action" value="run_one_click_embedded">
        <button class="btn btn-sm btn-rmi">Run One Click (Embedded)</button>
      </form>
      <a href="<?= h($baseProject . '/tools/release/go_live_readiness_one_click.php') ?>" class="btn btn-sm btn-outline-light">Open One Click Page</a>
    <?php endif; ?>
    <?php if (!function_exists('tools_can_access_web_tool') || tools_can_access_web_tool('release/release_gate.php')): ?>
      <a href="<?= h($baseProject . '/tools/release/release_gate.php') ?>" class="btn btn-sm btn-outline-light">Open Release Gate</a>
    <?php endif; ?>
    <a href="<?= h($baseProject . '/docs/governance/POST_STABILIZATION_CHECKLIST.md') ?>" class="btn btn-sm btn-outline-light">Post-Stabilization Checklist</a>
    <a href="<?= h($baseProject . '/tools/release/release_artifacts.php') ?>" class="btn btn-sm btn-outline-light">Release Verify (<?= h($releaseVerifyBadge) ?>)</a>
  </div>
  <?php if ($oneClickResult): ?>
    <?php $ocStatus = strtoupper((string)($oneClickResult['status'] ?? 'UNKNOWN')); ?>
    <div class="mb-3 p-2 rounded" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);">
      <div class="small d-flex flex-wrap gap-2 align-items-center">
        <span class="occ-subtle">Last One Click:</span>
        <span id="ocStatusBadge" class="badge <?= h(occ_badge_class($ocStatus)) ?>"><?= h($ocStatus) ?></span>
        <span id="ocCheckedAt" class="occ-subtle">at <?= h((string)($oneClickResult['checked_at'] ?? '-')) ?></span>
        <span id="ocRunningBadge" class="badge text-bg-warning" style="<?= !empty($oneClickAsync['running']) ? '' : 'display:none;' ?>">
          RUNNING (PID <span id="ocPid"><?= h((string)($oneClickAsync['pid'] ?? '-')) ?></span>)
        </span>
      </div>
    </div>
  <?php endif; ?>
  <div class="mb-3">
    <div class="fw-semibold mb-2">Latest Plan Executive Summary</div>
    <div class="occ-card <?php
      $pesErr = (string)($planExecState['error'] ?? '');
      $pesData = (array)($planExecState['data'] ?? []);
      $pesMismatch = (bool)($planExecState['state_mismatch'] ?? false);
      $pesPerRun = (bool)($planExecState['per_run_exists'] ?? false);
      if ($pesErr !== '' || $pesMismatch || !$pesPerRun) echo 'occ-card-warn';
      elseif (strtoupper((string)($pesData['decision']['go_no_go'] ?? '')) === 'NO-GO') echo 'occ-card-fail';
      else echo '';
    ?>">
      <?php if (!$planExecState['ok']): ?>
        <div class="occ-k">Status</div>
        <div class="occ-v mb-2"><?= tools_badge('ATTENTION') ?> <?= h($planExecState['error'] === 'STATE_CORRUPT' ? 'STATE_CORRUPT' : 'DATA_MISSING') ?></div>
        <div class="occ-k">Fix command</div>
        <div class="occ-v mb-2"><code>php tools/qa/run_pipeline_final.php --plan=full_staging_00_16 --run-id=auto --write-last</code></div>
      <?php else:
        $dec = (array)($pesData['decision'] ?? []);
        $goNoGo = (string)($dec['go_no_go'] ?? 'UNKNOWN');
        $level = (string)($dec['level'] ?? 'UNKNOWN');
        $arch = (array)($pesData['architecture'] ?? []);
        $archStatus = (string)($arch['status'] ?? 'DATA_MISSING');
        $sum = (array)($pesData['summary'] ?? []);
      ?>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
          <?= tools_badge($goNoGo, $goNoGo) ?>
          <?= tools_badge($level, $level) ?>
          <?= tools_badge($archStatus, 'Arch: ' . $archStatus) ?>
          <?php if ($pesMismatch): ?><?= tools_badge('ATTENTION', 'STATE_MISMATCH') ?><?php endif; ?>
          <?php if (!$pesPerRun): ?><?= tools_badge('ATTENTION', 'PER_RUN_MISSING') ?><?php endif; ?>
        </div>
        <div class="occ-k">Plan ID</div>
        <div class="occ-v mb-1"><?= h((string)($pesData['plan_id'] ?? '-')) ?></div>
        <div class="occ-k">Master Run ID</div>
        <div class="occ-v mb-1"><?= h((string)($pesData['master_run_id'] ?? '-')) ?></div>
        <div class="occ-k">Generated</div>
        <div class="occ-v mb-1"><?= h(tools_fmt_ts((string)($pesData['generated_at'] ?? ''))) ?></div>
        <div class="occ-k">Freshness</div>
        <div class="occ-v mb-1"><?= $planExecState['age_hours'] !== null ? h((string)$planExecState['age_hours']) . 'h' : '-' ?></div>
        <div class="occ-k">Stages</div>
        <div class="occ-v mb-1"><?= (int)($sum['stages_pass'] ?? 0) ?> pass / <?= (int)($sum['stages_fail'] ?? 0) ?> fail</div>
        <?php if (!empty($dec['first_failure_stage'])): ?>
        <div class="occ-k">First failure</div>
        <div class="occ-v mb-1"><?= h((string)$dec['first_failure_stage']) ?></div>
        <?php endif; ?>
        <div class="d-flex gap-2 mt-2 flex-wrap">
          <a href="<?= h($baseProject . '/tools/ops/plan_exec_summary_view.php?mode=html&file=last') ?>" class="btn btn-sm btn-outline-light">Open Summary</a>
          <a href="<?= h($baseProject . '/tools/ops/plan_exec_summary_view.php?mode=json&file=last') ?>" class="btn btn-sm btn-outline-light">Open JSON</a>
          <a href="<?= h($baseProject . '/tools/ops/plan_exec_summary_view.php?mode=md&file=last') ?>" class="btn btn-sm btn-outline-light">Open MD</a>
        </div>
        <?php if ($goNoGo === 'NO-GO' && !empty($pesData['next_actions'])): ?>
        <div class="occ-k mt-2">Next Actions</div>
        <div class="occ-v mt-1">
          <?php foreach (array_slice((array)$pesData['next_actions'], 0, 2) as $na): ?>
            <div><code class="small"><?= h(tools_mask_sensitive((string)$na)) ?></code></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="occ-grid">
    <?php foreach ($rows as $r): ?>
      <?php
        $st = strtoupper((string)$r['status']);
        $cardClass = 'occ-card';
        if ($st === 'WARN' || $st === 'ATTENTION') $cardClass .= ' occ-card-warn';
        if ($st === 'FAIL' || $st === 'CRITICAL') $cardClass .= ' occ-card-fail';
        $cardId = ((string)$r['label'] === 'PHP Errors') ? ' id="php-errors"' : '';
      ?>
      <div class="<?= h($cardClass) ?>"<?= $cardId ?>>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold"><?= h((string)$r['label']) ?></div>
          <div><?= tools_badge((string)$r['status']) ?></div>
        </div>
        <div class="occ-k">Age</div>
        <div class="occ-v mb-2"><?php if ($r['age_sec'] === null): ?>-<?php else: ?><?= h((string)round(((int)$r['age_sec']) / 60, 1)) ?> min<?php endif; ?></div>
        <?php if (!empty($r['counts'])): ?>
        <div class="occ-k">Counts</div>
        <div class="occ-v mb-2">warn: <?= (int)($r['counts']['warn'] ?? 0) ?> · fatal: <?= (int)($r['counts']['fatal'] ?? 0) ?></div>
        <?php endif; ?>
        <div class="occ-k">State File</div>
        <div class="occ-v mb-2"><code><?= h((string)$r['path_masked']) ?></code></div>
        <?php if (!empty($r['fix_command']) && in_array((string)$r['status'], ['ATTENTION', 'CRITICAL'], true)): ?>
        <div class="occ-k">Fix command</div>
        <div class="occ-v mb-2"><code class="small"><?= h((string)$r['fix_command']) ?></code></div>
        <?php endif; ?>
        <a href="<?= h($baseProject . '/docs/governance/OPS_RUNBOOK_FINAL.md#general') ?>" class="btn btn-sm btn-outline-light">Open Runbook</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function () {
  const refreshBtn = document.getElementById('occRefreshBtn');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      const url = refreshBtn.getAttribute('data-refresh-url');
      if (!url) return;
      refreshBtn.disabled = true;
      refreshBtn.textContent = 'Refreshing…';
      fetch(url, { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) {
            window.location.reload();
          } else {
            alert(j && j.error ? j.error : 'Refresh gagal. Jalankan di NAS: php tools/ops/refresh_control_center.php');
            refreshBtn.disabled = false;
            refreshBtn.textContent = 'Refresh Control Center';
          }
        })
        .catch(function () {
          alert('Refresh gagal. Periksa koneksi atau jalankan di NAS: php tools/ops/refresh_control_center.php');
          refreshBtn.disabled = false;
          refreshBtn.textContent = 'Refresh Control Center';
        });
    });
  }

  const statusBadge = document.getElementById('ocStatusBadge');
  const checkedAt = document.getElementById('ocCheckedAt');
  const runningBadge = document.getElementById('ocRunningBadge');
  const pidEl = document.getElementById('ocPid');
  if (!statusBadge || !checkedAt) return;

  function badgeClass(status) {
    if (status === 'PASS' || status === 'OK') return 'text-bg-success';
    if (status === 'WARN') return 'text-bg-warning';
    if (status === 'FAIL') return 'text-bg-danger';
    return 'text-bg-secondary';
  }

  function refreshOneClickStatus() {
    fetch('<?= h($baseProject . '/tools/ops/control_center.php') ?>?occ_action=one_click_status&_=' + Date.now(), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) return;
        const s = String(j.status || 'UNKNOWN').toUpperCase();
        statusBadge.textContent = s;
        statusBadge.className = 'badge ' + badgeClass(s);
        checkedAt.textContent = 'at ' + (j.checked_at || '-');
        if (runningBadge) {
          if (j.running) {
            runningBadge.style.display = '';
            if (pidEl) pidEl.textContent = String(j.pid || '-');
          } else {
            runningBadge.style.display = 'none';
          }
        }
      })
      .catch(function () { /* keep silent on polling errors */ });
  }

  setInterval(refreshOneClickStatus, 10000);
})();
</script>
<?php rmi_footer(); ?>
