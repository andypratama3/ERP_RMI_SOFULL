<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/ops_score_trend_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if (str_starts_with($arg, '--env=')) {
        $env = strtolower(trim((string)substr($arg, 6)));
    }
}
if (!in_array($env, ['staging', 'production'], true)) {
    $env = 'staging';
}

$loaded = ops_score_load_inputs($env);
$metrics = (array)$loaded['metrics'];
$policyData = (array)($loaded['policy']['policy_data'] ?? ops_thresholds_defaults());
$policyMeta = (array)($loaded['policy'] ?? []);
$calc = ops_score_compute($metrics, $env, $policyData);
$reasons = array_values(array_unique(array_merge((array)$loaded['missing_reasons'], (array)$calc['reasons'])));
$status = (string)$calc['status'];
$goNoGo = (string)$calc['go_no_go'];
$notes = array_map(static fn(string $v): string => ops_score_mask($v), (array)$calc['notes']);
$notes = array_merge(
    $notes,
    array_map(static fn(string $v): string => ops_score_mask((string)$v), (array)($policyMeta['notes'] ?? [])),
    array_map(static fn(string $v): string => ops_score_mask((string)$v), (array)($policyMeta['errors'] ?? []))
);
$opsScore = (int)$calc['ops_score'];

if ((int)$metrics['data_missing_count'] > 0) {
    $status = 'DATA_MISSING';
    $goNoGo = 'UNKNOWN';
    $opsScore = 0;
    $metrics = [
        'readiness_score' => null,
        'contract_ok' => null,
        'smoke_http_fail' => null,
        'smoke_http_warn' => null,
        'core_flows_ok' => null,
        'backlog_p0' => null,
        'backlog_p1' => null,
        'backlog_p2' => null,
        'backup_age_hours' => null,
        'sla_breach' => null,
        'data_missing_count' => (int)$loaded['metrics']['data_missing_count'],
    ];
    if (!in_array('DATA_MISSING', $reasons, true)) $reasons[] = 'DATA_MISSING';
}

$record = [
    'state_version' => 1,
    'ts' => date(DateTimeInterface::ATOM),
    'date' => date('Y-m-d'),
    'env' => $env,
    'run_id' => (string)$loaded['run_id'],
    'ops_score' => $opsScore,
    'status' => $status,
    'go_no_go' => $goNoGo,
    'reasons' => $reasons,
    'metrics' => [
        'readiness_score' => $metrics['readiness_score'],
        'contract_ok' => $metrics['contract_ok'],
        'smoke_http_fail' => $metrics['smoke_http_fail'],
        'smoke_http_warn' => $metrics['smoke_http_warn'],
        'core_flows_ok' => $metrics['core_flows_ok'],
        'backlog_p0' => $metrics['backlog_p0'],
        'backlog_p1' => $metrics['backlog_p1'],
        'backlog_p2' => $metrics['backlog_p2'],
        'backup_age_hours' => $metrics['backup_age_hours'],
        'sla_breach' => $metrics['sla_breach'],
        'data_missing_count' => (int)$metrics['data_missing_count'],
    ],
    'policy' => [
        'policy_id' => (string)($policyMeta['policy_id'] ?? ''),
        'fingerprint' => (string)($policyMeta['fingerprint'] ?? ''),
        'updated_at' => (string)($policyMeta['updated_at'] ?? ''),
        'updated_by' => (string)($policyMeta['updated_by'] ?? ''),
        'source' => (string)($policyMeta['source'] ?? 'defaults'),
    ],
    'notes' => $notes,
];

$historyPath = ops_score_history_path();
$dir = dirname($historyPath);
if (!is_dir($dir) || !is_writable($dir)) {
    fwrite(STDERR, ops_score_mask('history_not_writable:' . $dir) . PHP_EOL);
    exit(1);
}

$rotateMax = (int)get_threshold('retention.rotate_max_bytes', 5242880);
$historyDays = (int)get_threshold('retention.history_days', 90);
if (is_file($historyPath) && (int)@filesize($historyPath) > $rotateMax) {
    $rot = ops_score_trend_pipeline_dir() . '/ops_score_history_' . date('Ymd_His') . '.jsonl';
    if (@rename($historyPath, $rot)) {
        ops_score_log_maintenance('history_rotated:' . $rot);
    } else {
        ops_score_log_maintenance('history_rotate_failed:' . $historyPath);
    }
}

if (@file_put_contents($historyPath, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND) === false) {
    fwrite(STDERR, ops_score_mask('history_append_failed:' . $historyPath) . PHP_EOL);
    exit(1);
}
ops_score_log_maintenance('snapshot_appended:' . $historyPath . '|run=' . (string)$record['run_id']);

$current = ops_score_read_jsonl($historyPath);
$filtered = ops_score_keep_last_days((array)$current['records'], $historyDays);
if (count($filtered) !== count((array)$current['records'])) {
    ops_score_write_jsonl($historyPath, $filtered);
    ops_score_log_maintenance('history_trimmed_90d:' . $historyPath);
}
if ((int)$current['invalid_lines'] > 0) {
    ops_score_log_maintenance('history_invalid_lines:' . (string)$current['invalid_lines'] . '|path=' . $historyPath);
}

$cutoff = strtotime('-' . max(1, $historyDays) . ' days');
foreach (ops_score_history_archives() as $archive) {
    $mtime = (int)@filemtime($archive);
    if ($mtime > 0 && $mtime < $cutoff) {
        if (@unlink($archive)) {
            ops_score_log_maintenance('archive_deleted:' . $archive);
        }
    }
}

$out = [
    'state_version' => 1,
    'ok' => true,
    'run_id' => (string)$record['run_id'],
    'status' => $status,
    'go_no_go' => $goNoGo,
    'ops_score' => (int)$record['ops_score'],
    'history' => ops_score_mask($historyPath),
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);

