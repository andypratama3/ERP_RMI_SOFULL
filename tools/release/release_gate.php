<?php
declare(strict_types=1);
require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

function release_gate_run(array $changedFiles): array
{
    $root = opsgov_root();
    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php';
    $changed = array_values(array_filter(array_map('trim', $changedFiles), static fn($v) => $v !== ''));
    if (!$changed && is_dir($root . '/.git')) {
        $out = []; $code = 1;
        @exec('cd ' . escapeshellarg($root) . ' && git diff --name-only HEAD~1..HEAD 2>/dev/null', $out, $code);
        if ($code === 0) $changed = $out;
    }
    $lint = [];
    foreach ($changed as $f) {
        if (!str_ends_with(strtolower($f), '.php')) continue;
        $path = $root . '/' . ltrim($f, '/');
        if (!is_file($path)) continue;
        $o = []; $c = 1;
        @exec(escapeshellcmd($phpBin) . ' -l ' . escapeshellarg($path) . ' 2>&1', $o, $c);
        $lint[] = ['file' => $f, 'ok' => $c === 0, 'output' => implode("\n", $o)];
    }
    $lintFail = count(array_filter($lint, static fn($r) => empty($r['ok'])));

    $highPrefixes = ['/_shared/', '/rbac/', '/purchases/fin', '/tools/dr/', '/tools/compliance/'];
    $highImpact = [];
    foreach ($changed as $f) {
        $norm = '/' . ltrim(str_replace('\\', '/', $f), '/');
        foreach ($highPrefixes as $p) {
            if (str_starts_with($norm, $p)) {
                $highImpact[] = $f;
                break;
            }
        }
    }
    $changelog = is_file($root . '/CHANGELOG.md') ? (string)file_get_contents($root . '/CHANGELOG.md') : '';
    $checklist = is_file($root . '/docs/ops/release/Release_Checklist.md') ? (string)file_get_contents($root . '/docs/ops/release/Release_Checklist.md') : '';
    $hasRfcRef = preg_match('/RFC-\d{8}-\d{3}/', $changelog . "\n" . $checklist) === 1;
    $rfcNeeded = !empty($highImpact);
    $rfcOk = !$rfcNeeded || $hasRfcRef;

    $logsDir = $root . '/storage/logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0775, true);
    }
    $tmp = $logsDir . '/release_gate_tmp_' . date('Ymd_His') . '.tmp';
    $smokeDbOk = false;
    $smokeDbErr = '';
    try {
        if (function_exists('rmi_db_pdo')) {
            $pdo = rmi_db_pdo();
            $smokeDbOk = (bool)$pdo->query('SELECT 1')->fetchColumn();
        } else {
            $smokeDbErr = 'rmi_db_pdo() unavailable';
        }
    } catch (Throwable $e) {
        $smokeDbErr = opsgov_mask($e->getMessage());
    }
    $smokeFileOk = (@file_put_contents($tmp, 'release_gate_probe') !== false);
    if (is_file($tmp)) @unlink($tmp);
    $perfOk = $smokeDbOk && $smokeFileOk;

    $ok = ($lintFail === 0) && $rfcOk && $perfOk;
    $failures = [];
    if ($lintFail > 0) {
        $failures[] = [
            'failing_step' => 'php_lint',
            'reason' => 'Ada file PHP yang tidak lolos lint.',
            'how_to_fix' => 'Jalankan `php -l <file>` lalu perbaiki syntax sampai PASS.',
        ];
    }
    if (!$rfcOk) {
        $failures[] = [
            'failing_step' => 'rfc_gate',
            'reason' => 'High-impact change terdeteksi tetapi referensi RFC tidak ditemukan.',
            'how_to_fix' => 'Tambahkan ID RFC format RFC-YYYYMMDD-SEQ pada CHANGELOG.md atau Release_Checklist.md.',
        ];
    }
    if (!$perfOk) {
        $failures[] = [
            'failing_step' => 'smoke_probe',
            'reason' => 'Smoke probe DB/file-write gagal.',
            'how_to_fix' => 'Periksa koneksi DB runtime, permission tulis `storage/logs`, lalu jalankan gate ulang.',
        ];
    }
    $payload = [
        'state_version' => 1,
        'ts' => date(DateTimeInterface::ATOM),
        'gate' => $ok ? 'PASS' : 'FAIL',
        'lint_fail_count' => $lintFail,
        'lint' => $lint,
        'high_impact_files' => $highImpact,
        'rfc_required' => $rfcNeeded,
        'rfc_reference_found' => $hasRfcRef,
        'smoke_perf_ok' => $perfOk,
        'smoke_probe' => [
            'db_ok' => $smokeDbOk,
            'db_error_masked' => $smokeDbErr,
            'file_write_ok' => $smokeFileOk,
            'hint' => $perfOk ? 'Smoke baseline OK' : 'Periksa koneksi DB/env dan permission tulis storage/logs',
        ],
        'failures' => $failures,
    ];
    opsgov_safe_write_json($root . '/storage/logs/release_gate_last.json', $payload);
    $md = [];
    $md[] = '# Release Gate Result';
    $md[] = '';
    $md[] = '- Timestamp: ' . $payload['ts'];
    $md[] = '- Gate: **' . $payload['gate'] . '**';
    $md[] = '- Lint fail count: ' . $lintFail;
    $md[] = '- RFC required: ' . ($rfcNeeded ? 'YES' : 'NO');
    $md[] = '- RFC reference found: ' . ($hasRfcRef ? 'YES' : 'NO');
    $md[] = '- Smoke probe: ' . ($perfOk ? 'PASS' : 'FAIL');
    if ($failures) {
        $md[] = '';
        $md[] = '## Failures';
        foreach ($failures as $f) {
            $md[] = '- Step: `' . $f['failing_step'] . '`';
            $md[] = '  - Reason: ' . $f['reason'];
            $md[] = '  - Fix: ' . $f['how_to_fix'];
        }
    }
    @file_put_contents($root . '/storage/logs/release_gate_last.md', implode("\n", $md) . "\n");
    ts_append_run_history('release_gate_run', $ok ? 'OK' : 'FAIL', ['source' => 'tools/release/release_gate.php', 'gate' => $payload['gate']]);
    return $payload;
}

if (PHP_SAPI === 'cli') {
    $changed = [];
    foreach (($_SERVER['argv'] ?? []) as $a) {
        if (strpos((string)$a, '--files=') === 0) $changed = array_filter(explode(',', substr((string)$a, 8)));
    }
    echo json_encode(release_gate_run($changed), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

opsgov_require_admin();
$result = opsgov_read_json(opsgov_root() . '/storage/logs/release_gate_last.json');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $changed = preg_split('/\r?\n/', (string)($_POST['changed_files'] ?? '')) ?: [];
    $result = release_gate_run($changed);
}
$baseProject = rmi_layout_base_project();
rmi_header('Release Gate', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Release Gate']]);

$gate = strtoupper((string)($result['gate'] ?? 'UNKNOWN'));
$lintFailCount = (int)($result['lint_fail_count'] ?? 0);
$rfcRequired = (bool)($result['rfc_required'] ?? false);
$rfcFound = (bool)($result['rfc_reference_found'] ?? false);
$smokeOk = (bool)($result['smoke_perf_ok'] ?? false);
$smokeProbe = (array)($result['smoke_probe'] ?? []);
$highImpact = array_values((array)($result['high_impact_files'] ?? []));
$lintRows = array_values((array)($result['lint'] ?? []));
$lintFailedFiles = array_values(array_map(
    static fn(array $row): string => (string)($row['file'] ?? ''),
    array_filter($lintRows, static fn(array $row): bool => empty($row['ok']))
));
?>
<div class="card p-3">
  <form method="post" class="mb-2">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <label class="form-label small">Changed files (opsional, satu per baris; kosong = auto git jika tersedia)</label>
    <textarea class="form-control form-control-sm mb-2" name="changed_files" rows="5"></textarea>
    <button class="btn btn-sm btn-rmi">Run Gate</button>
  </form>

  <div class="mt-3 mb-2 d-flex flex-wrap gap-2 align-items-center">
    <span class="small">Gate Status:</span>
    <?= tools_badge($gate) ?>
    <?php if ($gate !== 'PASS' && !empty($smokeProbe['hint'])): ?>
      <span class="small text-warning"><?= opsgov_h((string)$smokeProbe['hint']) ?></span>
    <?php endif; ?>
  </div>

  <div class="row g-2 mb-2">
    <div class="col-md-4">
      <div class="small p-2 rounded" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);">
        <div><b>PHP Lint</b>: <?= $lintFailCount === 0 ? tools_badge('PASS') : tools_badge('FAIL') ?></div>
        <div class="text-muted">Fail count: <?= opsgov_h((string)$lintFailCount) ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="small p-2 rounded" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);">
        <div><b>RFC Gate</b>: <?= (!$rfcRequired || $rfcFound) ? tools_badge('PASS') : tools_badge('FAIL') ?></div>
        <div class="text-muted"><?= $rfcRequired ? 'High-impact change terdeteksi' : 'High-impact change tidak terdeteksi' ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="small p-2 rounded" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);">
        <div><b>Smoke Probe</b>: <?= $smokeOk ? tools_badge('PASS') : tools_badge('FAIL') ?></div>
        <div class="text-muted">DB: <?= !empty($smokeProbe['db_ok']) ? 'OK' : 'FAIL' ?> • File write: <?= !empty($smokeProbe['file_write_ok']) ? 'OK' : 'FAIL' ?></div>
      </div>
    </div>
  </div>

  <?php if ($lintFailedFiles): ?>
    <div class="alert alert-danger py-2 small">
      Lint fail di file: <code><?= opsgov_h(implode(', ', $lintFailedFiles)) ?></code>
    </div>
  <?php endif; ?>
  <?php if ($highImpact): ?>
    <div class="alert alert-warning py-2 small">
      High-impact files: <code><?= opsgov_h(implode(', ', $highImpact)) ?></code>
      <?php if (!$rfcFound): ?> • RFC reference belum ditemukan (format `RFC-YYYYMMDD-SEQ`).<?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if (!$smokeOk && !empty($smokeProbe['db_error_masked'])): ?>
    <div class="alert alert-warning py-2 small">
      Smoke DB error (masked): <code><?= opsgov_h((string)$smokeProbe['db_error_masked']) ?></code>
    </div>
  <?php endif; ?>

  <details class="mt-2">
    <summary class="small text-muted" style="cursor:pointer;">Tampilkan raw JSON result</summary>
    <pre class="mb-0 mt-2"><?= opsgov_h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  </details>
</div>
<?php rmi_footer(); ?>
