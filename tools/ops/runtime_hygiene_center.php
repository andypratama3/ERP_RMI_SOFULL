<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/../tools_state_lib.php';

tools_require_access('ops/runtime_hygiene_center.php');
if (PHP_SAPI !== 'cli') {
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

function rh_human_bytes(int $b): string
{
    if ($b < 1024) return $b . ' B';
    $u = ['KB', 'MB', 'GB', 'TB'];
    $v = (float)$b;
    $i = -1;
    while ($v >= 1024.0 && $i < count($u) - 1) {
        $v /= 1024.0;
        $i++;
    }
    return number_format($v, 2) . ' ' . $u[$i];
}

function rh_dir_size(string $dir): int
{
    if (!is_dir($dir)) return 0;
    $size = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) $size += (int)$f->getSize();
        }
    } catch (Throwable $e) {
        return 0;
    }
    return $size;
}

function rh_rm_tree(string $path): bool
{
    if (!is_dir($path)) return false;
    $ok = true;
    $items = scandir($path);
    if (!is_array($items)) return false;
    foreach ($items as $i) {
        if ($i === '.' || $i === '..') continue;
        $p = $path . DIRECTORY_SEPARATOR . $i;
        if (is_dir($p)) {
            $ok = rh_rm_tree($p) && $ok;
            continue;
        }
        if (!@unlink($p)) $ok = false;
    }
    return @rmdir($path) && $ok;
}

function rh_latest_backup_ref(string $root): string
{
    $p = $root . '/storage/backups/LATEST_BACKUP.txt';
    if (!is_file($p)) return '';
    return trim((string)@file_get_contents($p));
}

function rh_collect_old_backup_dirs(string $root, int $keepLatest, int $maxAgeDays): array
{
    $dir = $root . '/storage/backups';
    $all = glob($dir . '/ERP_RMI_SOFULL_backup_*', GLOB_ONLYDIR) ?: [];
    usort($all, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $latestRef = rh_latest_backup_ref($root);
    $cut = time() - (max(1, $maxAgeDays) * 86400);
    $toDelete = [];
    foreach ($all as $idx => $d) {
        $isKeptByCount = $idx < max(1, $keepLatest);
        $isLatestRef = ($latestRef !== '' && realpath($latestRef) === realpath($d));
        $isOld = ((int)@filemtime($d)) < $cut;
        // Main guard by count (keep latest N) and keep latest reference, then age as additional guard.
        if (!$isKeptByCount && !$isLatestRef && ($isOld || $idx >= max(1, $keepLatest))) {
            $toDelete[] = $d;
        }
    }
    return $toDelete;
}

function rh_collect_old_exports_by_count(string $root, int $keepLatest): array
{
    $out = [];
    $targets = [
        $root . '/exports/deploy',
        $root . '/exports/evidence',
        $root . '/exports/handover',
    ];
    foreach ($targets as $base) {
        $zips = [];
        if (is_dir($base)) {
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && str_ends_with(strtolower((string)$f->getFilename()), '.zip')) {
                        $zips[] = (string)$f->getPathname();
                    }
                }
            } catch (Throwable $e) {
                $zips = [];
            }
        }
        if (!$zips) continue;
        usort($zips, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach ($zips as $i => $p) {
            if ($i < max(1, $keepLatest)) continue;
            $out[] = $p;
        }
    }
    return $out;
}

function rh_collect_old_files(string $dir, int $maxAgeDays, array $protectedBase = []): array
{
    if (!is_dir($dir)) return [];
    $cut = time() - (max(1, $maxAgeDays) * 86400);
    $out = [];
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            if ((int)$f->getMTime() >= $cut) continue;
            $base = basename((string)$f->getPathname());
            if (in_array($base, $protectedBase, true)) continue;
            $out[] = (string)$f->getPathname();
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function rh_run(bool $apply): array
{
    $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    $keepBackups = max(1, (int)(getenv('RUNTIME_KEEP_BACKUPS') ?: 5));
    $maxBackupAge = max(1, (int)(getenv('RUNTIME_MAX_BACKUP_AGE_DAYS') ?: 21));
    $maxLogAge = max(1, (int)(getenv('RUNTIME_MAX_LOG_AGE_DAYS') ?: 14));
    $maxExportAge = max(1, (int)(getenv('RUNTIME_MAX_EXPORT_AGE_DAYS') ?: 14));

    $backupDirs = rh_collect_old_backup_dirs($root, $keepBackups, $maxBackupAge);
    $oldExportZips = rh_collect_old_exports_by_count($root, $keepBackups);
    $logFiles = rh_collect_old_files($root . '/storage/logs', $maxLogAge, [
        'tools_run_history.jsonl',
        'smoke_nightly.log',
        'smoke_nightly.state',
        'readiness_report_last.json',
        'release_gate_last.json',
        'all_checks.last.json',
    ]);
    $exportFiles = rh_collect_old_files($root . '/storage/exports', $maxExportAge);

    $targets = [];
    foreach ($backupDirs as $p) $targets[] = ['path' => $p, 'type' => 'backup_dir'];
    foreach ($oldExportZips as $p) $targets[] = ['path' => $p, 'type' => 'export_zip'];
    foreach ($logFiles as $p) $targets[] = ['path' => $p, 'type' => 'log_file'];
    foreach ($exportFiles as $p) $targets[] = ['path' => $p, 'type' => 'export_file'];

    $deleted = [];
    $failed = [];
    $reclaimed = 0;
    foreach ($targets as $t) {
        $p = (string)$t['path'];
        $size = is_file($p) ? (int)@filesize($p) : rh_dir_size($p);
        if ($apply) {
            $ok = is_dir($p) ? rh_rm_tree($p) : @unlink($p);
            if ($ok) {
                $reclaimed += max(0, $size);
                $deleted[] = ['path' => tools_mask_sensitive($p), 'type' => $t['type'], 'bytes' => $size];
            } else {
                $failed[] = ['path' => tools_mask_sensitive($p), 'type' => $t['type']];
            }
        } else {
            $deleted[] = ['path' => tools_mask_sensitive($p), 'type' => $t['type'], 'bytes' => $size];
            $reclaimed += max(0, $size);
        }
    }

    $beforeStorage = rh_dir_size($root . '/storage');
    $afterStorage = rh_dir_size($root . '/storage');
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'mode' => $apply ? 'apply' : 'dry-run',
        'policy' => [
            'keep_backups' => $keepBackups,
            'max_backup_age_days' => $maxBackupAge,
            'max_log_age_days' => $maxLogAge,
            'max_export_age_days' => $maxExportAge,
        ],
        'summary' => [
            'candidates' => count($targets),
            'deleted' => $apply ? count($deleted) : 0,
            'failed' => count($failed),
            'estimated_reclaim_bytes' => $reclaimed,
            'estimated_reclaim_human' => rh_human_bytes($reclaimed),
            'storage_size_before_bytes' => $beforeStorage,
            'storage_size_after_bytes' => $afterStorage,
            'storage_size_after_human' => rh_human_bytes($afterStorage),
        ],
        'preview' => array_slice($deleted, 0, 80),
        'failed_items' => array_slice($failed, 0, 80),
    ];

    ts_write_json($root . '/storage/logs/runtime_hygiene_last.json', $payload);
    ts_append_run_history('runtime_hygiene_run', $apply ? 'OK' : 'DRY', [
        'source' => 'tools/ops/runtime_hygiene_center.php',
        'mode' => $payload['mode'],
        'candidates' => $payload['summary']['candidates'],
        'estimated_reclaim_bytes' => $payload['summary']['estimated_reclaim_bytes'],
    ]);
    return $payload;
}

if (PHP_SAPI === 'cli') {
    $apply = in_array('--apply', $_SERVER['argv'] ?? [], true);
    echo json_encode(rh_run($apply), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

$result = tools_read_state_json(ts_storage_logs_dir() . '/runtime_hygiene_last.json');
$data = is_array($result['data'] ?? null) ? (array)$result['data'] : [];
$flash = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'dry')));
    $apply = ($mode === 'apply');
    $data = rh_run($apply);
    $flash = $apply ? 'Runtime hygiene apply selesai.' : 'Runtime hygiene dry-run selesai.';
}

$baseProject = rmi_layout_base_project();
rmi_header('Runtime Hygiene Center', [
    'active' => 'tools',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Runtime Hygiene Center'],
]);
?>
<div class="card p-3">
  <?php if ($flash !== ''): ?><div class="alert alert-info"><?= h($flash) ?></div><?php endif; ?>
  <div class="small mb-2">Kurangi bloat storage otomatis: prune backup lama, log lama, dan export lama. Jalankan dry-run dulu sebelum apply.</div>
  <form method="post" class="d-flex gap-2 flex-wrap mb-3">
    <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
    <button class="btn btn-sm btn-outline-light" name="mode" value="dry">Dry Run</button>
    <button class="btn btn-sm btn-warning" name="mode" value="apply" onclick="return confirm('Apply runtime hygiene sekarang?')">Apply Hygiene</button>
  </form>
  <div class="small mb-2">CLI: <code>php tools/ops/runtime_hygiene_center.php --apply</code></div>
  <pre class="small mb-0"><?= h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php rmi_footer(); ?>

