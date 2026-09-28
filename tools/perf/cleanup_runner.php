<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

function perf_cleanup_run(bool $apply): array
{
    $root = opsgov_root();
    $days = max(1, (int)opsgov_env('CLEANUP_DAYS', '14'));
    $targets = [
        $root . '/tools/logs',
        $root . '/storage/logs',
        $root . '/storage/tmp',
    ];
    $cut = time() - ($days * 86400);
    $deleted = [];
    $protected = [
        '/tools_run_history.jsonl',
        '/readiness_report_last.json',
        '/business_signoff_last.json',
        '/release_gate_last.json',
    ];
    foreach ($targets as $dir) {
        if (!is_dir($dir)) continue;
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $p = $f->getPathname();
                if ((int)$f->getMTime() > $cut) continue;
                if (preg_match('/\.(jsonl|log|tmp|txt)$/i', $p) !== 1) continue;
                $isProtected = false;
                foreach ($protected as $suffix) {
                    if (str_ends_with($p, $suffix)) {
                        $isProtected = true;
                        break;
                    }
                }
                if ($isProtected) continue;
                if ($apply) @unlink($p);
                $deleted[] = opsgov_mask($p);
            }
        } catch (Throwable $e) {
        }
    }
    ts_append_run_history('perf_cleanup_run', 'OK', ['source' => 'tools/perf/cleanup_runner.php', 'apply' => $apply, 'affected' => count($deleted)]);
    return ['ok' => true, 'apply' => $apply, 'days' => $days, 'affected' => count($deleted), 'paths' => array_slice($deleted, 0, 100)];
}

if (PHP_SAPI === 'cli') {
    $apply = in_array('--apply', $_SERVER['argv'] ?? [], true);
    echo json_encode(perf_cleanup_run($apply), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

opsgov_require_admin();
$msg = ''; $res = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $apply = (string)($_POST['mode'] ?? 'dry') === 'apply';
    $res = perf_cleanup_run($apply);
    $msg = $apply ? 'Cleanup apply selesai.' : 'Cleanup dry-run selesai.';
}
$baseProject = rmi_layout_base_project();
rmi_header('Cleanup Runner', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Cleanup Runner']]);
?>
<div class="card p-3">
  <?php if ($msg !== ''): ?><div class="alert alert-info"><?= opsgov_h($msg) ?></div><?php endif; ?>
  <div class="small mb-2">Cleanup aman untuk file lama. Default dry-run.</div>
  <form method="post" class="d-flex gap-2 mb-2">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <button class="btn btn-sm btn-outline-light" name="mode" value="dry">Dry Run</button>
    <button class="btn btn-sm btn-warning" name="mode" value="apply" onclick="return confirm('Apply cleanup file lama?')">Apply Cleanup</button>
  </form>
  <?php if ($res): ?><pre class="mb-0"><?= opsgov_h(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
</div>
<?php rmi_footer(); ?>
