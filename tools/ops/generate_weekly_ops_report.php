<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/tools_paths.php';
tools_assert_app_root_locked_cli();

require_once __DIR__ . '/_lib/ops_helpers.php';
require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("Method Not Allowed\n");
}

$now = new DateTimeImmutable('now');
$weekKey = ops_week_key($now);
$snapshots = ops_collect_snapshots(7);

$readiness = array_map(static fn(array $s): int => (int)($s['readiness_score'] ?? 0), $snapshots);
$smokeFailDays = array_values(array_filter($snapshots, static fn(array $s): bool => ((int)($s['smoke_fail'] ?? 0)) > 0));
$contractFailDays = array_values(array_filter($snapshots, static fn(array $s): bool => (($s['contract_ok'] ?? true) === false)));
$backupGt24 = 0;
$backupGt72 = 0;
foreach ($snapshots as $s) {
    $age = $s['backup_age_hours'] ?? null;
    if ($age === null) continue;
    $f = (float)$age;
    if ($f > 24.0) $backupGt24++;
    if ($f > 72.0) $backupGt72++;
}

$rMin = $readiness ? min($readiness) : 0;
$rMax = $readiness ? max($readiness) : 0;
$rAvg = $readiness ? round(array_sum($readiness) / count($readiness), 2) : 0.0;

$alerts = ops_read_json_safe(ops_state_path('ops_alerts_last.json'));
$findings = ops_read_json_safe(ops_state_path('ops_findings.json'));
$topIssues = [];
if ($alerts['ok']) {
    foreach ((array)($alerts['data']['alerts'] ?? []) as $a) {
        if (!($a['active'] ?? false)) continue;
        $topIssues[] = [
            'code' => (string)($a['code'] ?? ''),
            'severity' => (string)($a['severity'] ?? 'MEDIUM'),
            'title' => (string)($a['title'] ?? ''),
        ];
        if (count($topIssues) >= 5) break;
    }
}
if (!$topIssues && $findings['ok']) {
    foreach ((array)($findings['data']['findings'] ?? []) as $f) {
        if (!in_array((string)($f['status'] ?? ''), ['OPEN', 'IN_PROGRESS'], true)) continue;
        $topIssues[] = [
            'code' => (string)($f['code'] ?? ''),
            'severity' => (string)($f['severity'] ?? 'MEDIUM'),
            'title' => (string)($f['title'] ?? ''),
        ];
        if (count($topIssues) >= 5) break;
    }
}

$jsonPayload = [
    'state_version' => 1,
    'generated_at' => $now->format(DateTimeInterface::ATOM),
    'week' => $weekKey,
    'window_days' => count($snapshots),
    'kpi' => [
        'readiness_min' => $rMin,
        'readiness_avg' => $rAvg,
        'readiness_max' => $rMax,
        'smoke_fail_total' => array_sum(array_map(static fn(array $s): int => (int)($s['smoke_fail'] ?? 0), $snapshots)),
        'smoke_fail_days' => array_values(array_map(static fn(array $s): string => (string)$s['date'], $smokeFailDays)),
        'backup_days_gt_24h' => $backupGt24,
        'backup_days_gt_72h' => $backupGt72,
        'contract_fail_days' => array_values(array_map(static fn(array $s): string => (string)$s['date'], $contractFailDays)),
    ],
    'top_issues' => $topIssues,
    'artifacts_masked' => [
        'weekly_md' => '[APP_ROOT]/storage/logs/weekly_ops_report_' . $weekKey . '.md',
        'weekly_json' => '[APP_ROOT]/storage/logs/weekly_ops_report_' . $weekKey . '.json',
        'trend_7d' => '[APP_ROOT]/storage/logs/ops_trend_7d_last.json',
        'trend_30d' => '[APP_ROOT]/storage/logs/ops_trend_30d_last.json',
    ],
];
$jsonContract = ops_contract_check_types($jsonPayload, [
    'state_version' => 'int',
    'generated_at' => 'string',
    'week' => 'string',
    'window_days' => 'int',
    'kpi' => 'array',
    'top_issues' => 'array',
    'artifacts_masked' => 'array',
]);
if (!$jsonContract['ok']) {
    fwrite(STDERR, "weekly contract mismatch\n");
    exit(2);
}

$md = [];
$md[] = '# Weekly Ops Report ' . $weekKey;
$md[] = '';
$md[] = 'Generated at: ' . $now->format(DateTimeInterface::ATOM);
$md[] = '';
$md[] = '## Executive Bullets';
$md[] = '- Readiness avg/min/max: **' . $rAvg . ' / ' . $rMin . ' / ' . $rMax . '**.';
$md[] = '- Smoke failure total: **' . $jsonPayload['kpi']['smoke_fail_total'] . '** across **' . count($smokeFailDays) . '** day(s).';
$md[] = '- Backup freshness breaches: **>24h=' . $backupGt24 . '** day(s), **>72h=' . $backupGt72 . '** day(s).';
$md[] = '- Contract failure day(s): **' . (count($contractFailDays) ? implode(', ', $jsonPayload['kpi']['contract_fail_days']) : 'none') . '**.';
$md[] = '';
$md[] = '## KPI Weekly Table';
$md[] = '| KPI | Value |';
$md[] = '|---|---:|';
$md[] = '| Readiness Min | ' . $rMin . ' |';
$md[] = '| Readiness Avg | ' . $rAvg . ' |';
$md[] = '| Readiness Max | ' . $rMax . ' |';
$md[] = '| Smoke Fail Total | ' . $jsonPayload['kpi']['smoke_fail_total'] . ' |';
$md[] = '| Backup >24h Days | ' . $backupGt24 . ' |';
$md[] = '| Backup >72h Days | ' . $backupGt72 . ' |';
$md[] = '| Contract Fail Days | ' . count($contractFailDays) . ' |';
$md[] = '';
$md[] = '## Top Issues';
if ($topIssues) {
    foreach ($topIssues as $i) {
        $md[] = '- [' . strtoupper((string)$i['severity']) . '] `' . $i['code'] . '` - ' . $i['title'];
    }
} else {
    $md[] = '- No active high-priority issue from alerts/findings.';
}
$md[] = '';
$md[] = '## Artifacts';
$md[] = '- Weekly JSON: ' . $jsonPayload['artifacts_masked']['weekly_json'];
$md[] = '- Trend 7d: ' . $jsonPayload['artifacts_masked']['trend_7d'];
$md[] = '- Trend 30d: ' . $jsonPayload['artifacts_masked']['trend_30d'];
$md[] = '';

$jsonFile = ops_state_path('weekly_ops_report_' . $weekKey . '.json');
$mdFile = ops_state_path('weekly_ops_report_' . $weekKey . '.md');
ops_write_json($jsonFile, $jsonPayload);
@file_put_contents($mdFile, implode(PHP_EOL, $md) . PHP_EOL);

$pointer = [
    'state_version' => 1,
    'ts' => $now->format(DateTimeInterface::ATOM),
    'week' => $weekKey,
    'latest_json' => '[APP_ROOT]/storage/logs/weekly_ops_report_' . $weekKey . '.json',
    'latest_md' => '[APP_ROOT]/storage/logs/weekly_ops_report_' . $weekKey . '.md',
];
ops_write_json(ops_state_path('weekly_ops_report_last.json'), $pointer);

ts_append_run_history('ops_weekly_report_generated', 'ok', [
    'at' => $now->format(DateTimeInterface::ATOM),
    'week' => $weekKey,
]);
try {
    $pdo = rmi_db_pdo();
    erp_audit_ensure($pdo);
    erp_audit($pdo, 'OPS', 'WEEKLY:' . $weekKey, 'OPS_WEEKLY_REPORT_GENERATED', $pointer);
} catch (Throwable $e) {
}

echo json_encode([
    'ok' => true,
    'week' => $weekKey,
    'json' => '[APP_ROOT]/storage/logs/weekly_ops_report_' . $weekKey . '.json',
    'md' => '[APP_ROOT]/storage/logs/weekly_ops_report_' . $weekKey . '.md',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
