<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../../_shared/db.php';

function perf_run_baseline(): array
{
    $root = opsgov_root();
    $tmpDir = $root . '/tools/logs/perf_tmp';
    @mkdir($tmpDir, 0775, true);
    $dbMs = null; $dbOk = false; $dbErr = '';
    $t = microtime(true);
    try {
        $pdo = rmi_db_pdo();
        $pdo->query('SELECT 1')->fetchColumn();
        $dbMs = round((microtime(true) - $t) * 1000, 2);
        $dbOk = true;
    } catch (Throwable $e) {
        $dbErr = opsgov_mask($e->getMessage());
    }
    $f = $tmpDir . '/perf_' . date('Ymd_His') . '.tmp';
    $t2 = microtime(true);
    @file_put_contents($f, str_repeat('A', 4096));
    $rwMs = round((microtime(true) - $t2) * 1000, 2);
    @unlink($f);
    $memMb = round(memory_get_peak_usage(true) / 1048576, 2);
    $diskTotal = (float)(@disk_total_space($root) ?: 0);
    $diskFree = (float)(@disk_free_space($root) ?: 0);
    $diskUsedPct = ($diskTotal > 0) ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 2) : 0;
    $payload = [
        'state_version' => 1,
        'ts' => date(DateTimeInterface::ATOM),
        'db_ok' => $dbOk,
        'db_latency_ms' => $dbMs,
        'db_error_masked' => $dbErr,
        'file_rw_ms' => $rwMs,
        'memory_mb' => $memMb,
        'disk_used_pct' => $diskUsedPct,
    ];
    opsgov_safe_write_json(opsgov_root() . '/storage/logs/perf_baseline_last.json', $payload);
    ts_append_run_history('perf_baseline_run', $dbOk ? 'OK' : 'WARN', ['source' => 'tools/perf/perf_baseline.php', 'db_latency_ms' => $dbMs, 'disk_used_pct' => $diskUsedPct]);
    return $payload;
}

if (PHP_SAPI === 'cli') {
    echo json_encode(perf_run_baseline(), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

opsgov_require_admin();
$msg = ''; $state = opsgov_read_json(opsgov_root() . '/storage/logs/perf_baseline_last.json');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $state = perf_run_baseline();
    $msg = 'Baseline measurement selesai.';
}
$baseProject = rmi_layout_base_project();
rmi_header('Perf Baseline', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Perf Baseline']]);
?>
<div class="card p-3">
  <?php if ($msg !== ''): ?><div class="alert alert-success"><?= opsgov_h($msg) ?></div><?php endif; ?>
  <form method="post" class="mb-2">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <button class="btn btn-sm btn-rmi">Run Baseline</button>
  </form>
  <pre class="mb-0"><?= opsgov_h(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php rmi_footer(); ?>
