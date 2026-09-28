<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/ops_score_trend_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$window = 'both';
$writeLast = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--write-last') {
        $writeLast = true;
        continue;
    }
    if (str_starts_with($arg, '--env=')) {
        $env = strtolower(trim((string)substr($arg, 6)));
        continue;
    }
    if (str_starts_with($arg, '--window=')) {
        $window = strtolower(trim((string)substr($arg, 9)));
    }
}
if (!in_array($env, ['staging', 'production'], true)) $env = 'staging';
if (!in_array($window, ['7', '30', 'both'], true)) $window = 'both';

$policyLoaded = load_ops_thresholds('active-first', false);
$policyData = (array)($policyLoaded['policy'] ?? ops_thresholds_defaults());
$historyDays = (int)($policyData['retention']['history_days'] ?? 90);

$all = [];
$invalid = 0;
foreach ([ops_score_history_path(), ...ops_score_history_archives()] as $src) {
    $read = ops_score_read_jsonl($src);
    $all = array_merge($all, (array)$read['records']);
    $invalid += (int)$read['invalid_lines'];
}
$all = ops_score_keep_last_days($all, $historyDays);
$byDay = ops_score_latest_per_day($all, $env);
$w7 = ops_score_build_window($byDay, 7);
$w30 = ops_score_build_window($byDay, 30);

$windowPayload = static function (array $w): array {
    $series = (array)$w['series'];
    $go = (array)($series['go_no_go'] ?? []);
    $summary = [
        'no_go_days' => count(array_filter($go, static fn(string $v): bool => $v === 'NO-GO')),
        'go_days' => count(array_filter($go, static fn(string $v): bool => $v === 'GO')),
        'unknown_days' => count(array_filter($go, static fn(string $v): bool => $v === 'UNKNOWN')),
    ];
    $currentRecords = (array)($w['records'] ?? []);
    $current = $currentRecords === [] ? [] : (array)end($currentRecords);
    return [
        'days' => (array)($w['days'] ?? []),
        'series' => $series,
        'delta' => [
            'ops_score' => ops_score_window_delta((array)($series['ops_score'] ?? [])),
            'readiness_score' => ops_score_window_delta((array)($series['readiness_score'] ?? [])),
            'backlog_p0' => ops_score_window_delta((array)($series['backlog_p0'] ?? [])),
            'smoke_http_fail' => ops_score_window_delta((array)($series['smoke_http_fail'] ?? [])),
        ],
        'flags' => ops_score_window_flags($w),
        'summary' => $summary,
        'sparkline_ops_score' => ops_score_sparkline_ascii((array)($series['ops_score'] ?? [])),
        'sparkline_svg_path' => ops_score_sparkline_svg_path((array)($series['ops_score'] ?? [])),
        'current' => $current,
    ];
};

$windows = [];
if ($window === '7' || $window === 'both') $windows['7'] = $windowPayload($w7);
if ($window === '30' || $window === 'both') $windows['30'] = $windowPayload($w30);

$notes = [];
if ($byDay === []) $notes[] = ops_score_mask('DATA_MISSING: ops score history empty');
if ($invalid > 0) $notes[] = ops_score_mask('ATTENTION: invalid JSON lines in history');
if (count((array)$w7['days']) < 7) $notes[] = ops_score_mask('ATTENTION: partial 7-day coverage');
if (count((array)$w30['days']) < 30) $notes[] = ops_score_mask('ATTENTION: partial 30-day coverage');
$notes = array_merge(
    $notes,
    array_map(static fn(string $v): string => ops_score_mask((string)$v), (array)($policyLoaded['notes'] ?? [])),
    array_map(static fn(string $v): string => ops_score_mask((string)$v), (array)($policyLoaded['errors'] ?? []))
);

$w30Records = (array)($w30['records'] ?? []);
$w7Records = (array)($w7['records'] ?? []);
$lastRec = $w30Records === [] ? [] : (array)end($w30Records);
if ($lastRec === []) $lastRec = $w7Records === [] ? [] : (array)end($w7Records);
$currentReasons = array_slice((array)($lastRec['reasons'] ?? []), 0, 3);

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'env' => $env,
    'windows' => $windows,
    'current' => [
        'ops_score' => $lastRec['ops_score'] ?? null,
        'status' => $lastRec['status'] ?? 'DATA_MISSING',
        'go_no_go' => $lastRec['go_no_go'] ?? 'UNKNOWN',
        'metrics' => $lastRec['metrics'] ?? [],
        'reasons' => $currentReasons,
    ],
    'policy' => [
        'policy_id' => (string)($policyData['policy_id'] ?? ''),
        'fingerprint' => (string)($policyLoaded['fingerprint'] ?? ops_thresholds_fingerprint($policyData)),
        'updated_at' => (string)($policyData['updated_at'] ?? ''),
        'updated_by' => (string)($policyData['updated_by'] ?? ''),
        'source' => (string)($policyLoaded['source'] ?? 'defaults'),
    ],
    'notes' => $notes,
];

$runId = 'opsscoretrend-' . $env . '-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
$dir = ops_score_trend_pipeline_dir();
$jsonPath = $dir . '/ops_score_trend_' . $runId . '.json';
$mdPath = $dir . '/ops_score_trend_' . $runId . '.md';
$htmlPath = $dir . '/ops_score_trend_' . $runId . '.html';
ops_write_json($jsonPath, $payload);

$table7 = (array)($windows['7'] ?? []);
$days7 = (array)($table7['days'] ?? []);
$s7 = (array)($table7['series'] ?? []);
$md = [];
$md[] = '# Ops Score Trend';
$md[] = '';
$md[] = '- generated_at: ' . ops_score_mask((string)$payload['generated_at']);
$md[] = '- env: ' . ops_score_mask($env);
$md[] = '- current score/status: ' . (string)($payload['current']['ops_score'] ?? 'null') . ' / ' . (string)($payload['current']['status'] ?? 'DATA_MISSING');
$md[] = '- current GO/NO-GO: ' . (string)($payload['current']['go_no_go'] ?? 'UNKNOWN');
$md[] = '';
$md[] = '## 7-Day Table';
$md[] = '| Date | Ops Score | Readiness | P0 | Smoke Fail | GO/NO-GO |';
$md[] = '|---|---:|---:|---:|---:|---|';
if ($days7 === []) {
    $md[] = '| DATA_MISSING | 0 | 0 | 0 | 0 | UNKNOWN |';
}
foreach ($days7 as $i => $day) {
    $md[] = '| ' . (string)$day
        . ' | ' . (int)($s7['ops_score'][$i] ?? 0)
        . ' | ' . (int)($s7['readiness_score'][$i] ?? 0)
        . ' | ' . (int)($s7['backlog_p0'][$i] ?? 0)
        . ' | ' . (int)($s7['smoke_http_fail'][$i] ?? 0)
        . ' | ' . (string)($s7['go_no_go'][$i] ?? 'UNKNOWN') . ' |';
}
$md[] = '';
$md[] = '## 30-Day Stats';
$md[] = '- delta ops_score: ' . (int)($windows['30']['delta']['ops_score'] ?? 0);
$md[] = '- delta readiness: ' . (int)($windows['30']['delta']['readiness_score'] ?? 0);
$md[] = '- delta backlog_p0: ' . (int)($windows['30']['delta']['backlog_p0'] ?? 0);
$md[] = '- delta smoke_http_fail: ' . (int)($windows['30']['delta']['smoke_http_fail'] ?? 0);
$md[] = '';
$md[] = '## Links';
$md[] = '- trend html: `' . ops_score_mask('storage/logs/pipeline/ops_score_trend_last.html') . '`';
$md[] = '- fix backlog pack: `' . ops_score_mask('storage/logs/pipeline/fix_backlog_last.md') . '`';
$md[] = '- readiness: `' . ops_score_mask('storage/logs/readiness_report_last.md') . '`';
$md[] = '';
@file_put_contents($mdPath, implode("\n", $md) . "\n");

$score7 = (string)($windows['7']['sparkline_ops_score'] ?? 'DATA_MISSING');
$score30 = (string)($windows['30']['sparkline_ops_score'] ?? 'DATA_MISSING');
$status = (string)($payload['current']['status'] ?? 'DATA_MISSING');
$goNoGo = (string)($payload['current']['go_no_go'] ?? 'UNKNOWN');
$reasonsHtml = '';
foreach ($currentReasons as $r) $reasonsHtml .= '<li>' . htmlspecialchars((string)$r, ENT_QUOTES, 'UTF-8') . '</li>';
if ($reasonsHtml === '') $reasonsHtml = '<li>none</li>';

$html = '<!doctype html><html><head><meta charset="utf-8"><title>Ops Score Trend</title>'
    . '<style>body{font-family:Arial,sans-serif;margin:14px;color:#111}h1,h2{margin:6px 0}.kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.box{border:1px solid #ddd;border-radius:6px;padding:8px}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #ddd;padding:6px}pre{margin:0}.badge{display:inline-block;padding:4px 8px;border-radius:12px;font-weight:700;background:#eee}.healthy{background:#d1fae5}.attention{background:#fef3c7}.critical{background:#fecaca}@media print{@page{size:A4;margin:10mm}body{margin:0;font-size:11px}.box,table{break-inside:avoid}}</style></head><body>'
    . '<h1>Ops Score Trend - 7/30 Day</h1>'
    . '<div class="kpi"><div class="box"><b>Ops Score</b><br>' . htmlspecialchars((string)($payload['current']['ops_score'] ?? 'null'), ENT_QUOTES, 'UTF-8') . '</div>'
    . '<div class="box"><b>Status</b><br><span class="badge ' . strtolower($status) . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span></div>'
    . '<div class="box"><b>GO/NO-GO</b><br>' . htmlspecialchars($goNoGo, ENT_QUOTES, 'UTF-8') . '</div>'
    . '<div class="box"><b>P0 / Smoke Fail</b><br>' . (int)($payload['current']['metrics']['backlog_p0'] ?? 0) . ' / ' . (int)($payload['current']['metrics']['smoke_http_fail'] ?? 0) . '</div></div>'
    . '<h2>Sparkline Ops Score</h2><div class="box"><b>7d</b><pre>' . htmlspecialchars($score7, ENT_QUOTES, 'UTF-8') . '</pre><b>30d</b><pre>' . htmlspecialchars($score30, ENT_QUOTES, 'UTF-8') . '</pre></div>'
    . '<h2>7-Day Mini Table</h2><table><tr><th>Date</th><th>Ops Score</th><th>Readiness</th><th>P0</th><th>Smoke Fail</th><th>GO/NO-GO</th></tr>';
if ($days7 === []) {
    $html .= '<tr><td colspan="6">DATA_MISSING</td></tr>';
} else {
    foreach ($days7 as $i => $day) {
        $html .= '<tr><td>' . htmlspecialchars((string)$day, ENT_QUOTES, 'UTF-8') . '</td><td>' . (int)($s7['ops_score'][$i] ?? 0) . '</td><td>' . (int)($s7['readiness_score'][$i] ?? 0) . '</td><td>' . (int)($s7['backlog_p0'][$i] ?? 0) . '</td><td>' . (int)($s7['smoke_http_fail'][$i] ?? 0) . '</td><td>' . htmlspecialchars((string)($s7['go_no_go'][$i] ?? 'UNKNOWN'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
}
$html .= '</table><h2>Reasons (Current Top 3)</h2><ul>' . $reasonsHtml . '</ul><h2>Action</h2><ul>'
    . '<li>Open fix backlog pack: fix_backlog_last.md</li>'
    . '<li>Run smoke strict: php tools/smoke_http.php</li>'
    . '<li>Run contract strict: php tools/qa/contract_check.php --strict --write-last</li>'
    . '<li>Run readiness report: php tools/ops/generate_report.php</li>'
    . '</ul></body></html>';
@file_put_contents($htmlPath, $html);

if ($writeLast) {
    ops_write_json($dir . '/ops_score_trend_last.json', $payload);
    @file_put_contents($dir . '/ops_score_trend_last.md', implode("\n", $md) . "\n");
    @file_put_contents($dir . '/ops_score_trend_last.html', $html);
}

$out = [
    'state_version' => 1,
    'run_id' => $runId,
    'artifacts' => [
        'json' => ops_score_mask($jsonPath),
        'md' => ops_score_mask($mdPath),
        'html' => ops_score_mask($htmlPath),
        'json_last' => $writeLast ? ops_score_mask($dir . '/ops_score_trend_last.json') : '',
        'md_last' => $writeLast ? ops_score_mask($dir . '/ops_score_trend_last.md') : '',
        'html_last' => $writeLast ? ops_score_mask($dir . '/ops_score_trend_last.html') : '',
    ],
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);

