<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$retentionDays = 30;
$apply = false;
$archive = true;
$actor = getenv('USER') ?: 'SYSTEM';
foreach ($args as $a) {
    if (str_starts_with($a, '--retention-days=')) {
        $retentionDays = max(1, (int)substr($a, 17));
    } elseif ($a === '--apply') {
        $apply = true;
    } elseif ($a === '--delete') {
        $archive = false;
    }
}

$logsDir = ts_storage_logs_dir();
$archiveDir = $logsDir . '/_obsolete_state';
@mkdir($archiveDir, 0775, true);
$threshold = time() - ($retentionDays * 86400);

$candidates = [];
foreach (glob($logsDir . '/*') ?: [] as $path) {
    if (!is_file($path)) continue;
    $base = basename($path);
    if (in_array($base, ['smoke_http_last.json', 'health.last.json', 'preflight_check.last.json', 'readiness_report_last.json', 'readiness_report_last.md'], true)) {
        continue;
    }
    if (!preg_match('/(readiness_report_|diag_.*\.last\.json|smoke_.*\.json|.*\.state\.json|.*\.last\.json)/i', $base)) {
        continue;
    }
    $mtime = (int)(@filemtime($path) ?: 0);
    if ($mtime <= 0 || $mtime >= $threshold) continue;
    $candidates[] = $path;
}

echo "State cleanup retention_days={$retentionDays} apply=" . ($apply ? '1' : '0') . " archive=" . ($archive ? '1' : '0') . PHP_EOL;
echo 'Candidates: ' . count($candidates) . PHP_EOL;

$moved = 0;
$deleted = 0;
$failed = 0;
foreach ($candidates as $p) {
    $base = basename($p);
    $target = $archiveDir . '/' . date('Ymd_His') . '_' . $base;
    if (!$apply) {
        echo "[DRY] " . ts_mask($p) . PHP_EOL;
        continue;
    }
    if ($archive) {
        if (@rename($p, $target)) {
            $moved++;
        } else {
            $failed++;
        }
    } else {
        if (@unlink($p)) {
            $deleted++;
        } else {
            $failed++;
        }
    }
}

ts_write_json($logsDir . '/state_cleanup.last.json', [
    'ok' => $failed === 0,
    'checked_at' => date(DateTimeInterface::ATOM),
    'retention_days' => $retentionDays,
    'apply' => $apply,
    'archive' => $archive,
    'moved' => $moved,
    'deleted' => $deleted,
    'failed' => $failed,
    'actor_username' => $actor,
]);

echo "Done. moved={$moved} deleted={$deleted} failed={$failed}" . PHP_EOL;
