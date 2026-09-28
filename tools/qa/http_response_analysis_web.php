<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../_lib/tools_runtime_config.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/http_response_analysis_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('hra_http_probe')) {
    function hra_http_probe(string $url, string $cookieFile): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_HEADER => false,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno > 0 ? (string)curl_error($ch) : '';
        $info = curl_getinfo($ch);
        $httpCode = (int)($info['http_code'] ?? 0);
        $timeNamelookup = (float)($info['namelookup_time'] ?? 0.0);
        $timeConnect = (float)($info['connect_time'] ?? 0.0);
        $timeStartTransfer = (float)($info['starttransfer_time'] ?? 0.0);
        $timeTotal = (float)($info['total_time'] ?? 0.0);
        $size = (int)($info['size_download'] ?? 0);
        $redirectCount = (int)($info['redirect_count'] ?? 0);
        $contentType = (string)($info['content_type'] ?? '');
        $effectiveUrl = (string)($info['url'] ?? $url);

        $crash = is_string($body) && (
            str_contains($body, 'Fatal error') ||
            str_contains($body, 'Unhandled Exception') ||
            str_contains($body, 'Parse error') ||
            str_contains($body, 'SQLSTATE')
        );

        $status = 'FAIL';
        if ($errno > 0) {
            $status = 'FAIL';
        } elseif (in_array($httpCode, [200, 302], true) && !$crash) {
            $status = 'PASS';
        } elseif (in_array($httpCode, [401, 403], true)) {
            $status = 'WARN';
        }

        return [
            'url' => $url,
            'effective_url' => $effectiveUrl,
            'http_code' => $httpCode,
            'status' => $status,
            'curl_errno' => $errno,
            'curl_error_masked' => tools_mask_sensitive($error),
            'time_namelookup_ms' => (int)round($timeNamelookup * 1000),
            'time_connect_ms' => (int)round($timeConnect * 1000),
            'time_starttransfer_ms' => (int)round($timeStartTransfer * 1000),
            'time_total_ms' => (int)round($timeTotal * 1000),
            'size_download_bytes' => $size,
            'redirect_count' => $redirectCount,
            'content_type' => $contentType,
            'crash_signature' => $crash,
        ];
    }
}

$statePath = APP_ROOT . '/storage/logs/http_response_analysis_last.json';
$historyPath = APP_ROOT . '/storage/logs/http_response_analysis_history.jsonl';
$defaultBase = tools_get_base_url();
if ($defaultBase === '' || $defaultBase === null) {
    $defaultBase = trim((string)(getenv('APP_BASE_URL') ?: getenv('SMOKE_BASE_URL') ?: ''));
}
if ($defaultBase === '') {
    $defaultBase = 'http://127.0.0.1/ERP_RMI_SOFULL';
}
$defaultPaths = implode("\n", [
    '/tools/index.php',
    '/tools/doctor/index.php',
    '/tools/hardening/erp_hardening_triage_web.php',
    '/tools/release/release_final_checklist.php',
    '/tools/qa/all_checks_web.php',
    '/tools/ops/sla_monitor.php',
]);

$export = strtolower(trim((string)($_GET['export'] ?? '')));
if ($export !== '') {
    $latestExport = tools_json_read_safe($statePath);
    $rowsExport = is_array($latestExport['rows'] ?? null) ? (array)$latestExport['rows'] : [];
    $runAtExport = (string)($latestExport['run_at'] ?? '');
    if ($export === 'csv') {
        $filename = 'http_response_analysis_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $fp = fopen('php://output', 'wb');
        if ($fp === false) {
            http_response_code(500);
            echo 'Failed to create CSV output.';
            exit;
        }
        fputcsv($fp, ['halaman', 'http_code', 'waktu_server_ms', 'status', 'ttfb_ms', 'dns_ms', 'connect_ms', 'size_bytes', 'redirect_count', 'run_at'], ',', '"', '\\');
        foreach ($rowsExport as $row) {
            $r = is_array($row) ? $row : [];
            fputcsv($fp, [
                (string)($r['effective_url'] ?? $r['url'] ?? ''),
                (int)($r['http_code'] ?? 0),
                (int)($r['time_total_ms'] ?? 0),
                (string)($r['status'] ?? 'FAIL'),
                (int)($r['time_starttransfer_ms'] ?? 0),
                (int)($r['time_namelookup_ms'] ?? 0),
                (int)($r['time_connect_ms'] ?? 0),
                (int)($r['size_download_bytes'] ?? 0),
                (int)($r['redirect_count'] ?? 0),
                $runAtExport,
            ], ',', '"', '\\');
        }
        fclose($fp);
        exit;
    }
    if ($export === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'state_version' => 1,
            'generated_at' => date(DateTimeInterface::ATOM),
            'source' => 'http_response_analysis_last.json',
            'data' => $latestExport,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$baseUrl = (string)($_POST['base_url'] ?? $defaultBase);
$pathsRaw = (string)($_POST['paths'] ?? $defaultPaths);
$useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');
$adminUser = (string)($_POST['admin_user'] ?? '');
$rows = [];
$summary = [];
$msg = '';
$msgType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? $defaultBase)), '/');
    $pathsRaw = (string)($_POST['paths'] ?? $defaultPaths);
    $useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');
    $adminUser = trim((string)($_POST['admin_user'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');

    $paths = array_values(array_filter(array_map(static function (string $line): string {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) return '';
        return $line;
    }, preg_split('/\R/', $pathsRaw) ?: [])));

    $cookie = APP_ROOT . '/storage/logs/_http_response_analysis.cookie.txt';
    @unlink($cookie);
    if ($useCreds && $adminUser !== '' && $adminPass !== '') {
        $login = curl_init($baseUrl . '/master/login.php');
        curl_setopt_array($login, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIEJAR => $cookie,
            CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $adminUser,
                'password' => $adminPass,
            ]),
        ]);
        curl_exec($login);
    }

    foreach ($paths as $path) {
        $url = $path;
        if (!preg_match('#^https?://#i', $path)) {
            $url = $baseUrl . '/' . ltrim($path, '/');
        }
        $rows[] = hra_http_probe($url, $cookie);
    }
    @unlink($cookie);

    $pass = 0;
    $warn = 0;
    $fail = 0;
    $sumTtfb = 0;
    $sumTotal = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'PASS') $pass++;
        elseif ($r['status'] === 'WARN') $warn++;
        else $fail++;
        $sumTtfb += (int)$r['time_starttransfer_ms'];
        $sumTotal += (int)$r['time_total_ms'];
    }
    $count = count($rows);
    $summary = [
        'total' => $count,
        'pass' => $pass,
        'warn' => $warn,
        'fail' => $fail,
        'avg_ttfb_ms' => $count > 0 ? (int)round($sumTtfb / $count) : 0,
        'avg_total_ms' => $count > 0 ? (int)round($sumTotal / $count) : 0,
    ];

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'base_url' => $baseUrl,
        'credentials_used' => $useCreds && $adminUser !== '',
        'summary' => $summary,
        'rows' => $rows,
    ];
    tools_json_write_atomic($statePath, $payload);
    @file_put_contents($historyPath, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

    ts_append_run_history('http_response_analysis_web_run', ($fail === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => tools_current_actor_username(),
        'source' => 'tools/qa/http_response_analysis_web.php',
        'total' => $count,
        'pass' => $pass,
        'warn' => $warn,
        'fail' => $fail,
    ]);

    $msg = $fail === 0
        ? 'HTTP response analysis selesai: PASS.'
        : 'HTTP response analysis selesai: ada FAIL/WARN.';
    $msgType = $fail === 0 ? 'success' : 'warning';
}

$latest = tools_json_read_safe($statePath);
if (!$summary && is_array($latest['summary'] ?? null)) {
    $summary = (array)$latest['summary'];
}
if (!$rows && is_array($latest['rows'] ?? null)) {
    $rows = (array)$latest['rows'];
}

$baseProject = rmi_layout_base_project();
rmi_header('HTTP Response Analyzer (Web)', [
    'active' => 'tools',
    'subtitle' => 'Analisis detail HTTP code, TTFB, total, size, redirect, crash signature.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'HTTP Response Analyzer'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <div class="col-lg-5">
          <label class="form-label">Base URL</label>
          <input class="form-control form-control-sm" name="base_url" value="<?= h($baseUrl) ?>" placeholder="http://127.0.0.1/ERP_RMI_SOFULL atau https://erp.rizqullahmediska.com/ERP_RMI_SOFULL">
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
        <div class="col-lg-3">
          <label class="form-label">Admin pass</label>
          <input class="form-control form-control-sm" type="password" name="admin_pass" value="">
        </div>
        <div class="col-12">
          <label class="form-label">Path / URL Targets (satu baris satu target)</label>
          <textarea class="form-control form-control-sm" name="paths" rows="8"><?= h($pathsRaw) ?></textarea>
        </div>
        <div class="col-12">
          <button class="btn btn-rmi btn-sm" type="submit">Run HTTP Analysis</button>
          <a class="btn btn-outline-light btn-sm ms-2" href="?export=csv">Export CSV</a>
          <a class="btn btn-outline-light btn-sm ms-2" href="?export=json">Export JSON</a>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Summary</div>
      <?php if ($summary): ?>
        <?php $fail = (int)($summary['fail'] ?? 0); ?>
        <div class="small mb-1"><?= $fail === 0 ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Total: <b><?= (int)($summary['total'] ?? 0) ?></b></div>
        <div class="small">Pass: <b><?= (int)($summary['pass'] ?? 0) ?></b> · Warn: <b><?= (int)($summary['warn'] ?? 0) ?></b> · Fail: <b><?= (int)($summary['fail'] ?? 0) ?></b></div>
        <div class="small">Avg TTFB: <b><?= (int)($summary['avg_ttfb_ms'] ?? 0) ?> ms</b> · Avg Total: <b><?= (int)($summary['avg_total_ms'] ?? 0) ?> ms</b></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($latest['run_at'] ?? ''))) ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada run.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Ringkasan Laporan</div>
      <div class="table-responsive mb-3">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead>
            <tr><th>HALAMAN</th><th>HTTP Code</th><th>Waktu Server</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php if ($rows): foreach ($rows as $r): $row = is_array($r) ? $r : []; ?>
              <tr>
                <td><code><?= h((string)($row['effective_url'] ?? $row['url'] ?? '-')) ?></code></td>
                <td><?= (int)($row['http_code'] ?? 0) ?></td>
                <td><?= (int)($row['time_total_ms'] ?? 0) ?> ms</td>
                <td>
                  <?php
                    $s = strtoupper((string)($row['status'] ?? 'FAIL'));
                    echo $s === 'PASS' ? tools_badge('HEALTHY', 'PASS') : ($s === 'WARN' ? tools_badge('ATTENTION', 'WARN') : tools_badge('CRITICAL', 'FAIL'));
                  ?>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="small text-muted">Belum ada data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="fw-semibold mb-2">HTTP Metrics Table</div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th>Status</th><th>HTTP</th><th>TTFB</th><th>Total</th><th>DNS</th><th>Connect</th><th>Size</th><th>Redirect</th><th>URL</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows): foreach ($rows as $r): $row = is_array($r) ? $r : []; ?>
              <tr>
                <td>
                  <?php
                    $s = strtoupper((string)($row['status'] ?? 'FAIL'));
                    echo $s === 'PASS' ? tools_badge('HEALTHY', 'PASS') : ($s === 'WARN' ? tools_badge('ATTENTION', 'WARN') : tools_badge('CRITICAL', 'FAIL'));
                  ?>
                </td>
                <td><?= (int)($row['http_code'] ?? 0) ?></td>
                <td><?= (int)($row['time_starttransfer_ms'] ?? 0) ?> ms</td>
                <td><?= (int)($row['time_total_ms'] ?? 0) ?> ms</td>
                <td><?= (int)($row['time_namelookup_ms'] ?? 0) ?> ms</td>
                <td><?= (int)($row['time_connect_ms'] ?? 0) ?> ms</td>
                <td><?= (int)($row['size_download_bytes'] ?? 0) ?> B</td>
                <td><?= (int)($row['redirect_count'] ?? 0) ?></td>
                <td><code><?= h((string)($row['effective_url'] ?? $row['url'] ?? '-')) ?></code></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="9" class="small text-muted">Belum ada data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
