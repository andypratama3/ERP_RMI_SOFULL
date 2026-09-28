<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/fix_backlog_trend_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$writeLast = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') {
        continue;
    }
    if ($arg === '--write-last') {
        $writeLast = true;
        continue;
    }
    if (str_starts_with($arg, '--env=')) {
        $env = strtolower(trim((string)substr($arg, 6)));
    }
}
if (!in_array($env, ['staging', 'production'], true)) {
    $env = 'staging';
}

$dir = fbt_pipeline_dir();
$fixLastPath = $dir . '/fix_backlog_last.json';
$fixRead = fbt_read_json_file($fixLastPath);
if (!$fixRead['ok']) {
    $err = 'fix_backlog_last_invalid_or_missing:' . (string)$fixRead['error'] . '|' . (string)$fixRead['path_masked'];
    fbt_append_maintenance($err);
    fwrite(STDERR, fbt_mask($err) . PHP_EOL);
    exit(1);
}
$data = (array)$fixRead['data'];

// Use pipeline_last run_id/env if exists.
$pipeLastRead = fbt_read_json_file($dir . '/pipeline_last.json');
$runId = (string)($data['run_id'] ?? '');
if ($runId === '' && $pipeLastRead['ok']) {
    $runId = (string)(($pipeLastRead['data']['run_id'] ?? ''));
}
if ($runId === '') {
    $runId = 'snapshot-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
}
$envFromData = strtolower((string)($data['env'] ?? ''));
if (in_array($envFromData, ['staging', 'production'], true)) {
    $env = $envFromData;
}

$summary = (array)($data['summary'] ?? []);
$mods = (array)($summary['by_module'] ?? []);
$moduleKeys = ['STOCK', 'SALES', 'PURCHASES', 'FINANCE', 'BANK_TAX', 'REPORTS', 'MOBILE', 'OPS', 'CI', 'PROD'];
$byModule = [];
foreach ($moduleKeys as $mk) {
    $entry = (array)($mods[$mk] ?? []);
    $byModule[$mk] = [
        'p0' => (int)($entry['p0'] ?? 0),
        'p1' => (int)($entry['p1'] ?? 0),
        'p2' => (int)($entry['p2'] ?? 0),
    ];
}

$snapshot = [
    'state_version' => 1,
    'ts' => fbt_now_iso(),
    'date' => date('Y-m-d'),
    'env' => $env,
    'run_id' => $runId,
    'overall_ok' => (bool)($data['overall_ok'] ?? false),
    'summary' => [
        'p0' => (int)($summary['p0'] ?? 0),
        'p1' => (int)($summary['p1'] ?? 0),
        'p2' => (int)($summary['p2'] ?? 0),
        'data_missing' => (int)($summary['data_missing'] ?? 0),
    ],
    'by_module' => $byModule,
];

$historyPath = fbt_history_file();
$logPath = fbt_maintenance_log();

if (!is_dir(dirname($historyPath)) || !is_writable(dirname($historyPath))) {
    $err = 'history_storage_not_writable:' . fbt_mask(dirname($historyPath));
    fbt_append_maintenance($err);
    fwrite(STDERR, fbt_mask($err) . PHP_EOL);
    exit(1);
}

// Rotate if history file too large.
if (is_file($historyPath)) {
    $size = (int)@filesize($historyPath);
    if ($size > (5 * 1024 * 1024)) {
        $rotated = $dir . '/fix_backlog_history_' . date('Ymd_His') . '.jsonl';
        if (@rename($historyPath, $rotated)) {
            fbt_append_maintenance('history_rotated_to:' . $rotated);
        } else {
            fbt_append_maintenance('history_rotate_failed:' . $historyPath);
        }
    }
}

@file_put_contents($historyPath, json_encode($snapshot, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
fbt_append_maintenance('snapshot_appended:' . $historyPath . '|run_id=' . $runId . '|env=' . $env);

// Keep only last 90 days in current history file.
$allCurrent = fbt_jsonl_read_records($historyPath);
$kept = fbt_filter_records_last_days((array)$allCurrent['records'], 90);
if (count($kept) !== count((array)$allCurrent['records'])) {
    if (fbt_jsonl_write_records($historyPath, $kept)) {
        fbt_append_maintenance('history_trimmed_to_90_days:' . $historyPath);
    } else {
        fbt_append_maintenance('history_trim_failed:' . $historyPath);
    }
}
if ((int)$allCurrent['invalid_lines'] > 0) {
    fbt_append_maintenance('history_invalid_lines_detected:' . (string)$allCurrent['invalid_lines'] . '|path=' . $historyPath);
}

// Delete archives older than 90 days.
$cutoff = fbt_days_ago_cutoff_ts(90);
foreach (fbt_history_archives() as $archive) {
    $mtime = (int)@filemtime($archive);
    if ($mtime > 0 && $mtime < $cutoff) {
        if (@unlink($archive)) {
            fbt_append_maintenance('archive_deleted:' . $archive);
        } else {
            fbt_append_maintenance('archive_delete_failed:' . $archive);
        }
    }
}

$out = [
    'state_version' => 1,
    'ok' => true,
    'env' => $env,
    'run_id' => $runId,
    'history_path' => fbt_mask($historyPath),
    'maintenance_log' => fbt_mask($logPath),
    'write_last' => $writeLast,
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);

