<?php
declare(strict_types=1);

/**
 * Audit Center - Halaman terpusat untuk menjalankan semua lint/audit sekaligus.
 */

require_once __DIR__ . '/../tools_remote_check.php';
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('audit/audit_center.php');
require_login();
if (function_exists('require_any_permission')) {
    require_once __DIR__ . '/../../_shared/rbac.php';
    require_any_permission(['TOOLS.ENTERPRISE_AUDIT_VIEW', 'TOOLS.READINESS_AUDIT', 'TOOLS.VIEW']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$root = ts_root();
$logsDir = ts_storage_logs_dir();
$msg = trim((string)($_GET['msg'] ?? ''));
$msgType = in_array($_GET['msg_type'] ?? '', ['success', 'warning', 'danger', 'info'], true) ? (string)$_GET['msg_type'] : 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    @set_time_limit(120);
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator unavailable');
    }

    $mode = ((string)($_POST['mode'] ?? 'quick') === 'full') ? 'full' : 'quick';
    $phpBin = (string)(defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php');

    require_once $root . '/tools/audit/_lib/php_static_analysis_lib.php';
    require_once $root . '/tools/audit/_lib/security_scanner_lib.php';
    $phpBin = function_exists('psal_resolve_php_cli') ? psal_resolve_php_cli($phpBin) : $phpBin;

    $results = [];
    $overallOk = true;

    $t0 = microtime(true);
    $phpStatic = psal_run($root, 'core', $phpBin);
    $ms1 = (int)round((microtime(true) - $t0) * 1000);
    $results[] = ['id' => 'php_static_analysis', 'name' => 'PHP Static Analysis', 'ok' => $phpStatic['ok'], 'duration_ms' => $ms1];
    if (!$phpStatic['ok']) $overallOk = false;
    ts_write_json($logsDir . '/audit_php_static_analysis_last.json', $phpStatic);

    $t0 = microtime(true);
    $secScan = secscan_run($root, 'core');
    $ms2 = (int)round((microtime(true) - $t0) * 1000);
    $results[] = ['id' => 'security_scanner', 'name' => 'Security Scanner', 'ok' => $secScan['ok'], 'duration_ms' => $ms2];
    if (!$secScan['ok']) $overallOk = false;
    ts_write_json($logsDir . '/audit_security_scanner_last.json', $secScan);

    $t0 = microtime(true);
    $lintCmd = '(cd ' . escapeshellarg($root) . ' && find master sales purchases stock dashboards hrl kpi mpr tools _shared api -name "*.php" 2>/dev/null | head -300 | xargs -n1 ' . escapeshellarg($phpBin) . ' -l 2>&1)';
    $out = [];
    $code = 1;
    @exec($lintCmd, $out, $code);
    $ms3 = (int)round((microtime(true) - $t0) * 1000);
    $lintOk = ((int)$code === 0);
    $results[] = ['id' => 'php_lint', 'name' => 'PHP Syntax Lint', 'ok' => $lintOk, 'duration_ms' => $ms3];
    if (!$lintOk) $overallOk = false;

    if ($mode === 'full') {
        $migrationLint = $root . '/tools/qa/migration_sql_lint.php';
        if (is_file($migrationLint)) {
            $t0 = microtime(true);
            @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($migrationLint) . ' --dir=sql/migrations --write-last --strict 2>&1', $mOut, $mCode);
            $ms4 = (int)round((microtime(true) - $t0) * 1000);
            $mOk = ((int)$mCode === 0);
            $results[] = ['id' => 'migration_sql_lint', 'name' => 'Migration SQL Lint', 'ok' => $mOk, 'duration_ms' => $ms4];
            if (!$mOk) $overallOk = false;
        }
    }

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'mode' => $mode,
        'overall_ok' => $overallOk,
        'steps' => $results,
        'summary' => [
            'total' => count($results),
            'pass' => count(array_filter($results, fn($r) => $r['ok'])),
            'fail' => count(array_filter($results, fn($r) => !$r['ok'])),
        ],
        'last_security_scanner' => $secScan,
        'last_php_static' => $phpStatic,
    ];
    ts_write_json($logsDir . '/audit_center_last.json', $payload);

    $msg = $overallOk ? 'Audit Center selesai: PASS.' : 'Audit Center selesai: ada FAIL.';
    $msgType = $overallOk ? 'success' : 'warning';
    rmi_redirect('audit_center.php?msg=' . urlencode($msg) . '&msg_type=' . urlencode($msgType));
}

$statePath = $logsDir . '/audit_center_last.json';
$lastRun = ts_read_json($statePath);
$steps = is_array($lastRun['steps'] ?? null) ? (array)$lastRun['steps'] : [];
$summary = is_array($lastRun['summary'] ?? null) ? (array)$lastRun['summary'] : [];
$overallOk = (bool)($lastRun['overall_ok'] ?? false);
$runAt = (string)($lastRun['run_at'] ?? '');

$phpStaticPath = $logsDir . '/audit_php_static_analysis_last.json';
$secScanPath = $logsDir . '/audit_security_scanner_last.json';
$migrationLintPath = $root . '/storage/logs/pipeline/migration_sql_lint_last.json';
$phpStatic = ts_read_json($phpStaticPath);
$secScan = ts_read_json($secScanPath);
if (!is_array($secScan) || !array_key_exists('ok', $secScan)) {
    $secScan = is_array($lastRun['last_security_scanner'] ?? null) ? (array)$lastRun['last_security_scanner'] : [];
}
if (!is_array($phpStatic) || !isset($phpStatic['engine'])) {
    $phpStatic = is_array($lastRun['last_php_static'] ?? null) ? (array)$lastRun['last_php_static'] : [];
}
$migrationLint = is_file($migrationLintPath) ? ts_read_json($migrationLintPath) : [];
$acExists = is_file($logsDir . '/audit_center_last.json');
$psExists = is_file($logsDir . '/audit_php_static_analysis_last.json');
$ssExists = is_file($logsDir . '/audit_security_scanner_last.json');

$base = rmi_layout_base_project();
rmi_header('Audit Center', [
    'active' => 'tools',
    'subtitle' => 'Satu halaman untuk menjalankan semua lint & audit: PHPStan/php -l, Security Scanner, Migration Lint.',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $base . '/tools/index.php'],
        ['label' => 'Audit', 'url' => $base . '/tools/audit/audit_center.php'],
        'Audit Center',
    ],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?>
  <div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div>
  <?php endif; ?>

  <div class="col-12">
    <div class="rmi-card p-3">
      <h5 class="mb-2">Jalankan Audit</h5>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <form method="post" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
          <input type="hidden" name="mode" value="quick">
          <button type="submit" class="btn btn-sm btn-rmi">Run Audit (Quick)</button>
        </form>
        <form method="post" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
          <input type="hidden" name="mode" value="full">
          <button type="submit" class="btn btn-sm btn-outline-light">Run Audit (Full)</button>
        </form>
        <span class="small rmi-muted">Quick: PHP + Security + Lint. Full: + Migration SQL.</span>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <h5 class="mb-3">Hasil Terakhir</h5>
      <?php if (empty($steps)): ?>
      <div class="text-muted"><?= $msg !== '' ? 'Hasil belum tersedia. Coba refresh halaman.' : 'Belum pernah dijalankan. Klik Run Audit di atas.' ?></div>
      <?php else: ?>
      <div class="d-flex gap-2 mb-2">
        <span class="badge bg-<?= $overallOk ? 'success' : 'danger' ?>"><?= $overallOk ? 'PASS' : 'FAIL' ?></span>
        <span class="small"><?= h($runAt) ?></span>
        <span class="small">Pass: <?= (int)($summary['pass'] ?? 0) ?> / Fail: <?= (int)($summary['fail'] ?? 0) ?></span>
      </div>
      <table class="table table-sm table-bordered mb-0">
        <thead><tr><th>Step</th><th>Status</th><th>Duration</th></tr></thead>
        <tbody>
        <?php foreach ($steps as $s): ?>
        <tr>
          <td><?= h((string)($s['name'] ?? $s['id'] ?? '')) ?></td>
          <td><span class="badge bg-<?= $s['ok'] ? 'success' : 'danger' ?>"><?= $s['ok'] ? 'OK' : 'FAIL' ?></span></td>
          <td><?= (int)($s['duration_ms'] ?? 0) ?> ms</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100">
      <h6 class="mb-2">PHP Static Analysis</h6>
      <?php if (is_array($phpStatic) && isset($phpStatic['engine'])): ?>
      <div class="small mb-2">
        Engine: <code><?= h((string)($phpStatic['engine'] ?? '')) ?></code> ·
        Files: <?= (int)($phpStatic['total_files'] ?? 0) ?>
        <?php $fc = (int)($phpStatic['fail_count'] ?? 0); if ($fc > 0): ?> · <span class="text-danger fw-bold">Fail: <?= $fc ?></span><?php endif; ?>
      </div>
      <?php if (!empty($phpStatic['errors'])): ?>
      <div class="table-responsive" style="max-height:280px;overflow:auto">
        <table class="table table-sm table-bordered table-striped mb-0">
          <thead class="table-light"><tr><th>File</th><th>Message</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($phpStatic['errors'], 0, 50) as $e): ?>
          <tr><td class="text-nowrap small"><?= h((string)($e['file'] ?? '')) ?></td><td class="small"><?= h((string)($e['message'] ?? '')) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($phpStatic['errors']) > 50): ?><div class="small text-muted mt-1">Menampilkan 50 dari <?= count($phpStatic['errors']) ?>.</div><?php endif; ?>
      <?php else: ?>
      <div class="text-success small">✓ Tidak ada error.</div>
      <?php endif; ?>
      <?php else: ?>
      <div class="text-muted small">Belum dijalankan.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100">
      <h6 class="mb-2">Security Scanner</h6>
      <?php if (is_array($secScan) && array_key_exists('ok', $secScan)): ?>
      <div class="small mb-2">
        Files: <?= (int)($secScan['files_scanned'] ?? 0) ?> ·
        Critical: <span class="text-danger fw-bold"><?= (int)(($secScan['summary'] ?? [])['critical'] ?? 0) ?></span> ·
        High: <span class="text-warning"><?= (int)(($secScan['summary'] ?? [])['high'] ?? 0) ?></span> ·
        Medium: <?= (int)(($secScan['summary'] ?? [])['medium'] ?? 0) ?>
      </div>
      <?php if (!empty($secScan['findings'])): ?>
      <div class="table-responsive" style="max-height:280px;overflow:auto">
        <table class="table table-sm table-bordered table-striped mb-0">
          <thead class="table-light"><tr><th>File</th><th>Line</th><th>Severity</th><th>Rule</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($secScan['findings'], 0, 50) as $f): ?>
          <tr>
            <td class="text-nowrap small"><?= h((string)($f['file'] ?? '')) ?></td>
            <td class="text-center"><?= (int)($f['line'] ?? 0) ?></td>
            <td><span class="badge bg-<?= ($f['severity'] ?? '') === 'critical' ? 'danger' : (($f['severity'] ?? '') === 'high' ? 'warning' : 'secondary') ?>"><?= h((string)($f['severity'] ?? '')) ?></span></td>
            <td class="small"><?= h((string)($f['rule'] ?? '')) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($secScan['findings']) > 50): ?><div class="small text-muted mt-1">Menampilkan 50 dari <?= count($secScan['findings']) ?>.</div><?php endif; ?>
      <?php else: ?>
      <div class="text-success small">✓ Tidak ada findings.</div>
      <?php endif; ?>
      <?php elseif (in_array('security_scanner', array_column($steps, 'id'), true)): ?>
      <div class="text-warning small">File state tidak tersedia. Jalankan ulang audit.</div>
      <?php else: ?>
      <div class="text-muted small">Belum dijalankan.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <h6 class="mb-2">Migration SQL Lint</h6>
      <?php if (is_array($migrationLint) && isset($migrationLint['file_count'])): ?>
      <div class="small mb-2">
        Files: <?= (int)($migrationLint['file_count'] ?? 0) ?> ·
        Fail: <span class="text-danger fw-bold"><?= (int)($migrationLint['fail_count'] ?? 0) ?></span> ·
        Warn: <?= (int)($migrationLint['warn_count'] ?? 0) ?>
      </div>
      <?php if (!empty($migrationLint['issues'])): ?>
      <div class="table-responsive" style="max-height:240px;overflow:auto">
        <table class="table table-sm table-bordered table-striped mb-0">
          <thead class="table-light"><tr><th>File</th><th>Line</th><th>Severity</th><th>Rule</th><th>Message</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($migrationLint['issues'], 0, 50) as $i): ?>
          <tr>
            <td class="text-nowrap small"><?= h((string)($i['file'] ?? '')) ?></td>
            <td class="text-center"><?= (int)($i['line'] ?? 0) ?></td>
            <td><span class="badge bg-<?= ($i['severity'] ?? '') === 'FAIL' ? 'danger' : 'warning' ?>"><?= h((string)($i['severity'] ?? '')) ?></span></td>
            <td class="small"><?= h((string)($i['rule'] ?? '')) ?></td>
            <td class="small"><?= h((string)($i['message'] ?? '')) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($migrationLint['issues']) > 50): ?><div class="small text-muted mt-1">Menampilkan 50 dari <?= count($migrationLint['issues']) ?>.</div><?php endif; ?>
      <?php else: ?>
      <div class="text-success small">✓ Tidak ada issues.</div>
      <?php endif; ?>
      <?php else: ?>
      <div class="text-muted small">Jalankan Run Audit (Full) untuk melihat hasil.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="row align-items-center">
        <div class="col-md-6">
          <h6 class="mb-2">State Files</h6>
          <div class="small rmi-muted">
            <?= $acExists ? '✓' : '✗' ?> audit_center_last.json ·
            <?= $psExists ? '✓' : '✗' ?> audit_php_static_analysis_last.json ·
            <?= $ssExists ? '✓' : '✗' ?> audit_security_scanner_last.json
          </div>
        </div>
        <div class="col-md-6">
          <h6 class="mb-2">Link Terkait</h6>
          <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-sm btn-outline-light" href="<?= h($base . '/tools/readiness_audit.php') ?>">Readiness Audit</a>
            <a class="btn btn-sm btn-outline-light" href="<?= h($base . '/tools/security_audit.php') ?>">Security Audit</a>
            <a class="btn btn-sm btn-outline-light" href="<?= h($base . '/tools/qa/all_checks_web.php') ?>">All Checks</a>
            <a class="btn btn-sm btn-outline-light" href="<?= h($base . '/tools/review_kit_workspace.php') ?>">Review Kit</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
