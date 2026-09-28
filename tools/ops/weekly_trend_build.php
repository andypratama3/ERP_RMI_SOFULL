<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';

tools_require_access('ops/weekly_trend_build.php');

$histPath = APP_ROOT . '/storage/logs/erp_hardening_triage_history.jsonl';
$lines = is_file($histPath) ? (@file($histPath, FILE_IGNORE_NEW_LINES) ?: []) : [];
$items = [];
foreach ($lines as $line) {
    $row = json_decode((string)$line, true);
    if (!is_array($row)) continue;
    $items[] = [
        'date' => substr((string)($row['generated_at'] ?? ''), 0, 10),
        'score' => (int)($row['summary']['score'] ?? 0),
        'p0' => (int)($row['summary']['p0'] ?? 0),
        'p1' => (int)($row['summary']['p1'] ?? 0),
        'p2' => (int)($row['summary']['p2'] ?? 0),
        'status' => (string)($row['summary']['status'] ?? 'UNKNOWN'),
    ];
}
usort($items, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
$last7 = array_slice($items, -7);
$last30 = array_slice($items, -30);
$week = date('oW');

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'week' => $week,
    'trend_7d' => $last7,
    'trend_30d' => $last30,
];
tools_json_write_atomic(APP_ROOT . '/storage/logs/erp_hardening_weekly_trend_last.json', $payload);
tools_json_write_atomic(APP_ROOT . '/storage/logs/weekly_ops_report_' . $week . '.json', $payload);

$md = "# Weekly Ops Report {$week}\n\n";
$md .= "| date | score | p0 | p1 | p2 | status |\n";
$md .= "|---|---:|---:|---:|---:|---|\n";
foreach ($last7 as $r) {
    $md .= '| ' . $r['date'] . ' | ' . $r['score'] . ' | ' . $r['p0'] . ' | ' . $r['p1'] . ' | ' . $r['p2'] . ' | ' . $r['status'] . " |\n";
}
@file_put_contents(APP_ROOT . '/storage/logs/weekly_ops_report_' . $week . '.md', $md);
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
