<?php
/**
 * backup_now_cli.php — CLI wrapper untuk backup now.
 *
 * Usage: php tools/ops/backup_now_cli.php [--label=<label>] [--write-last]
 * Artifact: storage/logs/backup_now_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);
$label = 'cli_manual';
foreach ($args as $a) {
    if (is_string($a) && str_starts_with($a, '--label=')) {
        $label = trim(substr($a, 8));
        break;
    }
}

$backupScript = $root . '/tools/backup_now.sh';
$result = [
    'ok' => false,
    'run_at' => date(DateTimeInterface::ATOM),
    'label' => $label,
    'package' => null,
    'error' => null,
];

if (!is_file($backupScript) || !is_executable($backupScript)) {
    $result['error'] = 'backup_now.sh not found or not executable';
} else {
    $cmd = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($backupScript) . ' --label ' . escapeshellarg($label) . ' 2>&1';
    $lines = [];
    $code = 1;
    @exec($cmd, $lines, $code);
    $result['ok'] = ((int)$code === 0);
    if ($result['ok']) {
        $latestFile = $root . '/storage/backups/LATEST_BACKUP.txt';
        if (is_file($latestFile)) {
            $result['package'] = basename(trim((string)@file_get_contents($latestFile)));
        }
    } else {
        $result['error'] = 'backup_now.sh exit=' . $code . ': ' . implode(' | ', array_slice($lines, -3));
    }
}

if ($writeLast) {
    $logsDir = ts_storage_logs_dir();
    @file_put_contents($logsDir . '/backup_now_last.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
