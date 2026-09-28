<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/_lib/ops_helpers.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

tools_require_access('ops/executive_ops_summary.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

function eos_hardening_lock_path(): string
{
    return ops_root() . '/storage/locks/ops_hardening_full.lock.json';
}

function eos_pid_running(int $pid): bool
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

function eos_hardening_status(): array
{
    $lock = ops_read_json_safe(eos_hardening_lock_path());
    $pid = (int)($lock['data']['pid'] ?? 0);
    if ($pid > 0 && eos_pid_running($pid)) {
        return ['running' => true, 'pid' => $pid, 'mode' => (string)($lock['data']['mode'] ?? 'normal')];
    }
    if (is_file(eos_hardening_lock_path())) {
        @unlink(eos_hardening_lock_path());
    }
    return ['running' => false, 'pid' => $pid, 'mode' => (string)($lock['data']['mode'] ?? 'normal')];
}

function eos_start_hardening_async(string $mode): array
{
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['normal', 'dry-run', 'reset'], true)) {
        $mode = 'normal';
    }
    $st = eos_hardening_status();
    if (!empty($st['running'])) {
        return ['ok' => false, 'message' => 'Hardening masih berjalan di background (PID ' . (int)$st['pid'] . ').'];
    }

    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php';
    $script = ops_root() . '/tools/ops/mutation_hardening_audit.php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --scope=full';
    if ($mode === 'dry-run') $cmd .= ' --baseline-dry-run';
    if ($mode === 'reset') $cmd .= ' --baseline-reset';
    $log = ops_root() . '/storage/logs/ops_hardening_async.log';
    $spawn = 'nohup ' . $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
    $out = [];
    $code = 1;
    @exec($spawn, $out, $code);
    $pid = (int)trim((string)($out[0] ?? '0'));
    if ($code !== 0 || $pid <= 0) {
        return ['ok' => false, 'message' => 'Gagal menjalankan hardening async.'];
    }
    ops_write_json(eos_hardening_lock_path(), [
        'state_version' => 1,
        'pid' => $pid,
        'mode' => $mode,
        'started_at' => date(DateTimeInterface::ATOM),
    ]);
    ts_append_run_history('ops_hardening_async_start', 'OK', [
        'module' => 'tools.ops',
        'action' => 'hardening_async_start',
        'mode' => $mode,
        'pid' => $pid,
    ]);
    return ['ok' => true, 'message' => 'Hardening dijalankan di background (PID ' . $pid . ').'];
}

$msg = '';
$msgType = 'info';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator missing');
    }
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if ($action === 'run_ops_hardening') {
        if (function_exists('tools_can_access_web_tool') && tools_can_access_web_tool('__ops_hardening_audit_web')) {
            $mode = strtolower(trim((string)($_POST['mode'] ?? 'normal')));
            $run = eos_start_hardening_async($mode);
            if (!empty($run['ok'])) {
                $msg = (string)($run['message'] ?? 'Hardening dijalankan.');
                $msgType = 'success';
            } else {
                $msg = (string)($run['message'] ?? 'Hardening audit gagal.');
                $msgType = 'danger';
            }
        } else {
            $msg = 'Akses hardening audit ditolak.';
            $msgType = 'danger';
        }
    }
    rmi_redirect('executive_ops_summary.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType));
}
$msg = (string)($_GET['msg'] ?? '');
$msgType = (string)($_GET['type'] ?? 'info');
$hardeningRuntime = eos_hardening_status();

$summary = ops_read_json_safe(ops_state_path('executive_ops_summary_last.json'));
$trend7 = ops_read_json_safe(ops_state_path('ops_trend_7d_last.json'));
$trend30 = ops_read_json_safe(ops_state_path('ops_trend_30d_last.json'));
$alerts = ops_read_json_safe(ops_state_path('ops_alerts_last.json'));
$findings = ops_read_json_safe(ops_state_path('ops_findings.json'));
$weekly = ops_read_json_safe(ops_state_path('weekly_ops_report_last.json'));
$hypercare = ops_read_json_safe(ops_state_path('hypercare_summary_last.json'));
$signoffRes = function_exists('business_signoff_read_state') ? business_signoff_read_state() : ['ok' => false, 'data' => [], 'error' => 'missing'];
$signoff = ['ok' => $signoffRes['ok'], 'data' => $signoffRes['data'] ?? [], 'error' => $signoffRes['error'] ?? ''];
$hardeningBaseline = ops_read_json_safe(ops_state_path('ops_hardening_full_baseline.json'));
$hardeningSummary = (array)($hardeningBaseline['data']['summary'] ?? []);
$hardeningHistory = function_exists('tools_tail_jsonl') ? tools_tail_jsonl(ops_state_path('tools_run_history.jsonl'), 400) : ['exists' => false, 'items' => []];
$hardeningRunCounts = [];
foreach (array_reverse((array)($hardeningHistory['items'] ?? [])) as $ev) {
    $eventName = strtolower((string)($ev['event'] ?? ''));
    $meta = is_array($ev['meta'] ?? null) ? (array)$ev['meta'] : [];
    if ($eventName !== 'ops_mutation_hardening_audit') {
        continue;
    }
    if (strtolower((string)($meta['scan_scope'] ?? 'ops')) !== 'full') {
        continue;
    }
    $hardeningRunCounts[] = (int)($meta['finding_count'] ?? 0);
    if (count($hardeningRunCounts) >= 3) {
        break;
    }
}
$hardeningTrendLabel = 'N/A';
$hardeningTrendBadge = tools_badge('UNKNOWN', 'N/A');
$hardeningSeriesClass = 'ops-trend-neutral';
$hardeningTrendGlyph = '?';
if (count($hardeningRunCounts) >= 2) {
    $latest = (int)$hardeningRunCounts[0];
    $prev = (int)$hardeningRunCounts[1];
    if ($latest < $prev) {
        $hardeningTrendLabel = 'DOWN';
        $hardeningTrendBadge = tools_badge('HEALTHY', 'DOWN');
        $hardeningSeriesClass = 'ops-trend-down';
        $hardeningTrendGlyph = 'v';
    } elseif ($latest > $prev) {
        $hardeningTrendLabel = 'UP';
        $hardeningTrendBadge = tools_badge('ATTENTION', 'UP');
        $hardeningSeriesClass = 'ops-trend-up';
        $hardeningTrendGlyph = '^';
    } else {
        $hardeningTrendLabel = 'STABLE';
        $hardeningTrendBadge = tools_badge('HEALTHY', 'STABLE');
        $hardeningSeriesClass = 'ops-trend-stable';
        $hardeningTrendGlyph = '=';
    }
}
$hardeningSeries = $hardeningRunCounts ? implode(' / ', array_map(static fn(int $n): string => (string)$n, $hardeningRunCounts)) : '-';

$status = strtoupper((string)($summary['data']['status'] ?? 'ATTENTION'));
$statusClass = $status === 'CRITICAL' ? 'danger' : ($status === 'HEALTHY' ? 'success' : 'warning');
$aCounts = (array)($alerts['data']['active_counts'] ?? []);
$critical = (int)($aCounts['CRITICAL'] ?? 0);
$high = (int)($aCounts['HIGH'] ?? 0);
$medium = (int)($aCounts['MEDIUM'] ?? 0);
$low = (int)($aCounts['LOW'] ?? 0);

$openBySeverity = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
foreach ((array)($findings['data']['findings'] ?? []) as $f) {
    $st = strtoupper((string)($f['status'] ?? 'OPEN'));
    if (!in_array($st, ['OPEN', 'IN_PROGRESS'], true)) continue;
    $sev = strtoupper((string)($f['severity'] ?? 'LOW'));
    if (!isset($openBySeverity[$sev])) $openBySeverity[$sev] = 0;
    $openBySeverity[$sev]++;
}

$readiness7 = (array)($trend7['data']['series']['readiness_score'] ?? []);
$smoke7 = (array)($trend7['data']['series']['smoke_fail'] ?? []);
$backup7 = (array)($trend7['data']['series']['backup_age_hours'] ?? []);
$readiness30 = (array)($trend30['data']['series']['readiness_score'] ?? []);
$smoke30 = (array)($trend30['data']['series']['smoke_fail'] ?? []);
$backup30 = (array)($trend30['data']['series']['backup_age_hours'] ?? []);

$baseProject = rmi_layout_base_project();
rmi_header('Executive Ops Summary', [
    'active' => 'tools',
    'subtitle' => 'One-page operational status for management',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Executive Ops Summary'],
]);
?>
<style>
.ops-big-badge{font-size:1.3rem;font-weight:700;padding:.55rem .9rem;border-radius:.65rem;display:inline-block}
.ops-panel{border:1px solid rgba(255,255,255,.12);border-radius:.75rem;padding:.85rem;background:rgba(255,255,255,.02)}
.ops-value{font-size:1.1rem;font-weight:700}
.ops-muted{opacity:.8;font-size:.9rem}
.ops-trend-down{color:#22c55e;font-weight:700}
.ops-trend-up{color:#f59e0b;font-weight:700}
.ops-trend-stable{color:#60a5fa;font-weight:700}
.ops-trend-neutral{color:#9ca3af}
@media print{
  nav,.sidebar,.btn,.breadcrumb,.rmi-navbar,.rmi-sidebar,.no-print{display:none!important}
  body{font-size:12px;background:#fff!important;color:#111!important}
  .card,.ops-panel{break-inside:avoid;border:1px solid #bbb!important;background:#fff!important}
  .ops-trend-down,.ops-trend-up,.ops-trend-stable,.ops-trend-neutral{color:#111!important}
}
</style>

<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> mb-0 py-2"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="card p-3 d-flex flex-row justify-content-between align-items-center">
      <div>
        <div class="ops-big-badge bg-<?= h($statusClass) ?> text-white"><?= h($status) ?></div>
        <div class="ops-muted mt-1">Generated snapshot: <?= h(tools_fmt_ts((string)($summary['data']['ts'] ?? ''))) ?></div>
      </div>
      <div class="text-end">
        <div>Alerts Active: <?= tools_badge($critical > 0 ? 'CRITICAL' : ($high > 0 ? 'ATTENTION' : 'HEALTHY'), 'C:' . $critical . ' H:' . $high . ' M:' . $medium . ' L:' . $low) ?></div>
        <div class="mt-2"><a class="btn btn-sm btn-outline-light" href="<?= h($baseProject) ?>/tools/ops/findings.php">Open Findings</a></div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="ops-panel h-100">
      <div class="fw-semibold mb-1">Readiness Trend</div>
      <div><?= ops_svg_sparkline($readiness7) ?></div>
      <div class="ops-muted">7d mini trend</div>
      <div class="mt-2"><?= ops_svg_sparkline($readiness30) ?></div>
      <div class="ops-muted">30d mini trend</div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="ops-panel h-100">
      <div class="fw-semibold mb-1">Smoke Fail Trend</div>
      <div><?= ops_svg_sparkline($smoke7) ?></div>
      <div class="ops-muted">7d fail count</div>
      <div class="mt-2"><?= ops_svg_sparkline($smoke30) ?></div>
      <div class="ops-muted">30d fail count</div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="ops-panel h-100">
      <div class="fw-semibold mb-1">Backup Freshness (hours)</div>
      <div><?= ops_svg_sparkline($backup7) ?></div>
      <div class="ops-muted">7d backup age</div>
      <div class="mt-2"><?= ops_svg_sparkline($backup30) ?></div>
      <div class="ops-muted">30d backup age</div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="fw-semibold mb-2">Sign-off & Hypercare</div>
      <div class="mb-1">Business Sign-off: <?= tools_badge(!empty($signoff['data']['signed']) ? 'HEALTHY' : 'ATTENTION', !empty($signoff['data']['signed']) ? 'SIGNED' : 'PENDING') ?></div>
      <div class="mb-1">Hypercare 24h: <?= tools_badge(!empty($hypercare['data']['hypercare_complete_24h']) ? 'HEALTHY' : 'ATTENTION', !empty($hypercare['data']['hypercare_complete_24h']) ? 'COMPLETE' : 'PENDING/N/A') ?></div>
      <div class="ops-muted">State output masked, no raw secret/path exposed.</div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="fw-semibold mb-2">Open Findings by Severity</div>
      <div class="mb-1">CRITICAL: <span class="ops-value"><?= (int)$openBySeverity['CRITICAL'] ?></span></div>
      <div class="mb-1">HIGH: <span class="ops-value"><?= (int)$openBySeverity['HIGH'] ?></span></div>
      <div class="mb-1">MEDIUM: <span class="ops-value"><?= (int)$openBySeverity['MEDIUM'] ?></span></div>
      <div class="mb-1">LOW: <span class="ops-value"><?= (int)$openBySeverity['LOW'] ?></span></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Weekly Report Pointer</div>
      <div class="small mb-1">Latest JSON: <code><?= h((string)($weekly['data']['latest_json'] ?? '-')) ?></code></div>
      <div class="small mb-1">Latest MD: <code><?= h((string)($weekly['data']['latest_md'] ?? '-')) ?></code></div>
      <div class="ops-muted">Use this page for screenshot/print in ops review meeting.</div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Hardening Quick Actions</div>
      <div class="small mb-2">
        Hardening Snapshot: <?= !empty($hardeningBaseline['ok']) ? tools_badge('HEALTHY', 'READY') : tools_badge('ATTENTION', 'MISSING') ?>
        · findings: <b><?= (int)($hardeningSummary['finding_count'] ?? 0) ?></b>
        · new: <b><?= (int)($hardeningSummary['new_count'] ?? 0) ?></b>
        · resolved: <b><?= (int)($hardeningSummary['resolved_count'] ?? 0) ?></b>
      </div>
      <div class="small mb-2">
        Trend (3 runs): <?= $hardeningTrendBadge ?>
        <span class="<?= h($hardeningSeriesClass) ?>">series <?= h($hardeningTrendGlyph) ?> <?= h($hardeningSeries) ?></span>
        <span class="ops-muted">(latest/prev/...)</span>
      </div>
      <div class="small ops-muted mb-2">Legend: <b>v</b> better (finding turun), <b>^</b> worse (finding naik), <b>=</b> stable.</div>
      <div class="small mb-2">
        Runtime: <?= !empty($hardeningRuntime['running']) ? tools_badge('ATTENTION', 'RUNNING PID ' . (int)$hardeningRuntime['pid']) : tools_badge('HEALTHY', 'IDLE') ?>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
          <input type="hidden" name="action" value="run_ops_hardening">
          <input type="hidden" name="mode" value="normal">
          <button class="btn btn-sm btn-rmi" <?= (function_exists('tools_can_access_web_tool') && tools_can_access_web_tool('__ops_hardening_audit_web') && empty($hardeningRuntime['running'])) ? '' : 'disabled' ?>>Run Full Hardening Audit</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
          <input type="hidden" name="action" value="run_ops_hardening">
          <input type="hidden" name="mode" value="dry-run">
          <button class="btn btn-sm btn-outline-light" <?= (function_exists('tools_can_access_web_tool') && tools_can_access_web_tool('__ops_hardening_audit_web') && empty($hardeningRuntime['running'])) ? '' : 'disabled' ?>>Dry-Run Delta</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
          <input type="hidden" name="action" value="run_ops_hardening">
          <input type="hidden" name="mode" value="reset">
          <button class="btn btn-sm btn-warning" onclick="return confirm('Reset Hardening Snapshot ke kondisi saat ini?')" <?= (function_exists('tools_can_access_web_tool') && tools_can_access_web_tool('__ops_hardening_audit_web') && empty($hardeningRuntime['running'])) ? '' : 'disabled' ?>>Reset Hardening Snapshot</button>
        </form>
      </div>
      <div class="small mt-2">
        <a href="<?= h($baseProject) ?>/docs/docs_view.php?f=<?= rawurlencode('governance/OPS_HARDENING_REPORT.md') ?>" target="_blank" rel="noopener">Open Ops Hardening Report</a>
        ·
        <a href="<?= h($baseProject) ?>/docs/docs_view.php?f=<?= rawurlencode('governance/OPS_HARDENING_REPORT_FULL.md') ?>" target="_blank" rel="noopener">Open Full Hardening Report</a>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
