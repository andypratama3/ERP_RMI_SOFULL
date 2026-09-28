<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';

$args = $_SERVER['argv'] ?? [];
$windowHours = 24;
$minCheckpoints = 6;
foreach ($args as $a) {
    if (str_starts_with((string)$a, '--window-hours=')) {
        $windowHours = max(1, (int)substr((string)$a, 15));
    } elseif (str_starts_with((string)$a, '--min-checkpoints=')) {
        $minCheckpoints = max(1, (int)substr((string)$a, 18));
    }
}

$logs = ts_storage_logs_dir();
$path = $logs . '/hypercare_checkpoints.jsonl';
$events = [];
if (is_file($path)) {
    $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line) {
        $j = json_decode((string)$line, true);
        if (!is_array($j) || !isset($j['ts'])) {
            continue;
        }
        $tsEpoch = strtotime((string)$j['ts']);
        if ($tsEpoch === false) {
            continue;
        }
        $j['_ts_epoch'] = $tsEpoch;
        $events[] = $j;
    }
}

usort($events, static fn(array $a, array $b): int => ((int)$a['_ts_epoch']) <=> ((int)$b['_ts_epoch']));

$latestEpoch = $events ? (int)$events[count($events) - 1]['_ts_epoch'] : time();
$windowStartEpoch = $latestEpoch - ($windowHours * 3600);
$windowEvents = array_values(array_filter($events, static fn(array $e): bool => (int)$e['_ts_epoch'] >= $windowStartEpoch));

$criticalIncidents = 0;
foreach ($windowEvents as $e) {
    $contractOk = (bool)($e['gates']['contract_ok'] ?? false);
    $smokeFail = (int)($e['gates']['smoke_fail'] ?? 999);
    $backupAgeHours = (float)($e['gates']['backup_age_hours'] ?? 9999);
    $backupThresholdHours = (float)(getenv('HYPERCARE_BACKUP_MAX_AGE_HOURS') ?: '72');
    $isCritical = !$contractOk || $smokeFail > 0 || $backupAgeHours > $backupThresholdHours || empty($e['overall_ok']);
    if ($isCritical) {
        $criticalIncidents++;
    }
}

$windowHoursCovered = 0.0;
if (count($windowEvents) >= 2) {
    $first = (int)$windowEvents[0]['_ts_epoch'];
    $last = (int)$windowEvents[count($windowEvents) - 1]['_ts_epoch'];
    $windowHoursCovered = round(max(0, $last - $first) / 3600, 2);
}

$consecutiveOkHours = 0.0;
if ($events) {
    $rev = array_reverse($events);
    $latest = (int)$rev[0]['_ts_epoch'];
    $oldestConsecutive = $latest;
    foreach ($rev as $e) {
        $contractOk = (bool)($e['gates']['contract_ok'] ?? false);
        $smokeFail = (int)($e['gates']['smoke_fail'] ?? 999);
        $backupAgeHours = (float)($e['gates']['backup_age_hours'] ?? 9999);
        $backupThresholdHours = (float)(getenv('HYPERCARE_BACKUP_MAX_AGE_HOURS') ?: '72');
        $isCritical = !$contractOk || $smokeFail > 0 || $backupAgeHours > $backupThresholdHours || empty($e['overall_ok']);
        if ($isCritical) {
            break;
        }
        $oldestConsecutive = (int)$e['_ts_epoch'];
    }
    $consecutiveOkHours = round(max(0, $latest - $oldestConsecutive) / 3600, 2);
}

$complete24h = $windowHoursCovered >= 24
    && $criticalIncidents === 0
    && count($windowEvents) >= $minCheckpoints;

$summary = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'window_hours_target' => $windowHours,
    'window_start' => $windowEvents ? date(DateTimeInterface::ATOM, (int)$windowEvents[0]['_ts_epoch']) : null,
    'window_end' => date(DateTimeInterface::ATOM, $latestEpoch),
    'window_hours_covered' => $windowHoursCovered,
    'total_checkpoints' => count($windowEvents),
    'critical_incidents_count' => $criticalIncidents,
    'consecutive_ok_hours' => $consecutiveOkHours,
    'min_checkpoints_required' => $minCheckpoints,
    'hypercare_complete_24h' => $complete24h,
    'source_file' => ts_mask($path),
];

ts_write_json($logs . '/hypercare_summary_last.json', $summary);
ts_append_run_history('hypercare_summary', $complete24h ? 'OK' : 'ATTENTION', [
    'actor_username' => getenv('USER') ?: 'SYSTEM',
    'source' => 'tools/qa/hypercare_summary.php',
    'covered_hours' => $windowHoursCovered,
]);

echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
