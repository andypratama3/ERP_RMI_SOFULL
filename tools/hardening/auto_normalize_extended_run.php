<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';

tools_require_access('hardening/auto_normalize_extended_run.php');

$args = $_SERVER['argv'] ?? [];
$preview = !in_array('--apply', $args, true);
$apply = !$preview;
$confirm = in_array('--i-understand', $args, true);
$requestId = 'norm-' . date('YmdHis') . '-' . substr(hash('sha256', (string)microtime(true)), 0, 8);

if ($apply) {
    $env = strtolower((string)(getenv('APP_ENV') ?: 'production'));
    if ($env === 'production' && !$confirm) {
        fwrite(STDERR, "APP_ENV=production requires --i-understand\n");
        exit(2);
    }
}

$wl = tools_json_read_safe(APP_ROOT . '/tools/hardening/policies/triage_whitelist_v1.json');
$allowFiles = array_values(array_filter(array_map('strval', (array)($wl['allow_files'] ?? []))));
$touched = [];
$skipped = [];
$failed = [];
$diffs = [];
$backupRoot = APP_ROOT . '/storage/logs/_auto_normalize_backup/' . $requestId;

foreach ($allowFiles as $allowPath) {
    $abs = str_replace('[APP_ROOT]', APP_ROOT, $allowPath);
    $abs = str_replace('\\', '/', $abs);
    if (!is_file($abs)) {
        $skipped[] = ['path' => $allowPath, 'reason' => 'missing_or_not_file'];
        continue;
    }
    $src = (string)@file_get_contents($abs);
    if ($src === '') {
        $skipped[] = ['path' => $allowPath, 'reason' => 'empty_or_unreadable'];
        continue;
    }
    $new = $src;
    $new = preg_replace('/\bfailed\b/i', 'FAILED', $new) ?? $new;
    $new = preg_replace('/\bfail\b/i', 'FAIL', $new) ?? $new;
    $new = str_replace(APP_ROOT, '[APP_ROOT]', $new);
    if ($new === $src) {
        continue;
    }
    $relFile = str_replace('\\', '/', substr($abs, strlen(APP_ROOT) + 1));
    $diffs[] = '--- ' . $relFile . "\n+++ " . $relFile . "\n@@ normalized @@\n";
    $diffs[] = '- ' . substr(str_replace("\n", ' ', trim($src)), 0, 120) . "\n";
    $diffs[] = '+ ' . substr(str_replace("\n", ' ', trim($new)), 0, 120) . "\n";
    if ($apply) {
        $backupPath = $backupRoot . '/' . $relFile;
        $backupDir = dirname($backupPath);
        if (!is_dir($backupDir)) @mkdir($backupDir, 0775, true);
        if (@file_put_contents($backupPath, $src) === false) {
            $failed[] = ['path' => $relFile, 'reason' => 'backup_failed'];
            continue;
        }
        if (@file_put_contents($abs, $new) === false) {
            $failed[] = ['path' => $relFile, 'reason' => 'write_failed'];
            continue;
        }
    }
    $touched[] = $relFile;
}

$payload = [
    'state_version' => 1,
    'request_id' => $requestId,
    'generated_at' => date(DateTimeInterface::ATOM),
    'mode' => $apply ? 'apply' : 'preview',
    'touched_files' => $touched,
    'skipped' => $skipped,
    'failed' => $failed,
    'ok' => count($failed) === 0,
];
tools_json_write_atomic(APP_ROOT . '/storage/logs/auto_normalize_last.json', $payload);
@file_put_contents(APP_ROOT . '/storage/logs/auto_normalize_last.diff', implode('', $diffs));
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(count($failed) === 0 ? 0 : 1);
