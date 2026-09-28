<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/fix_backlog_trend_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$window = 'both';
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
        continue;
    }
    if (str_starts_with($arg, '--window=')) {
        $window = strtolower(trim((string)substr($arg, 9)));
    }
}
if (!in_array($env, ['staging', 'production'], true)) {
    $env = 'staging';
}
if (!in_array($window, ['7', '30', 'both'], true)) {
    $window = 'both';
}

$dir = fbt_pipeline_dir();
$historyPath = fbt_history_file();
$sources = [$historyPath, ...fbt_history_archives()];

$allRecords = [];
$invalidLines = 0;
foreach ($sources as $source) {
    $read = fbt_jsonl_read_records($source);
    $allRecords = array_merge($allRecords, (array)$read['records']);
    $invalidLines += (int)$read['invalid_lines'];
}
$allRecords = fbt_filter_records_last_days($allRecords, 90);
$latestByDay = fbt_group_latest_per_day($allRecords, $env);

$window7 = fbt_build_window_from_latest($latestByDay, 7);
$window30 = fbt_build_window_from_latest($latestByDay, 30);
$trend7 = fbt_compute_trend_metrics($window7);
$trend30 = fbt_compute_trend_metrics($window30);
$flags7 = fbt_compute_flags($window7);
$flags30 = fbt_compute_flags($window30);
$topModules = fbt_top_modules($window30, $window7);

$windows = [];
if ($window === '7' || $window === 'both') {
    $windows['7'] = [
        'days' => $window7['days'],
        'series' => $window7['series'],
        'trend' => $trend7,
        'flags' => $flags7,
        'top_modules' => $topModules,
    ];
}
if ($window === '30' || $window === 'both') {
    $windows['30'] = [
        'days' => $window30['days'],
        'series' => $window30['series'],
        'trend' => $trend30,
        'flags' => $flags30,
        'top_modules' => $topModules,
    ];
}

$notes = [];
if ($allRecords === []) {
    $notes[] = 'DATA_MISSING: history has no valid records';
}
if ($invalidLines > 0) {
    $notes[] = 'ATTENTION: invalid json lines detected in history (masked)';
}
if (count((array)$window7['days']) < 7) {
    $notes[] = 'ATTENTION: 7-day window has partial coverage';
}
if (count((array)$window30['days']) < 30) {
    $notes[] = 'ATTENTION: 30-day window has partial coverage';
}

$overallOk = $allRecords !== [] && !in_array('DATA_MISSING', $flags7, true) && !in_array('DATA_MISSING', $flags30, true);
$runId = 'trend-' . $env . '-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);

$jsonPayload = [
    'state_version' => 1,
    'generated_at' => fbt_now_iso(),
    'env' => $env,
    'overall_ok' => $overallOk,
    'windows' => $windows,
    'notes' => array_map(static fn(string $v): string => fbt_mask($v), $notes),
    'history_source' => array_map(static fn(string $v): string => fbt_mask($v), $sources),
];

$renderTable = static function (array $windowData): string {
    $days = (array)($windowData['days'] ?? []);
    $series = (array)($windowData['series'] ?? []);
    $rows = [];
    $rows[] = '| Date | P0 | P1 | P2 | DATA_MISSING |';
    $rows[] = '|---|---:|---:|---:|---:|';
    if ($days === []) {
        $rows[] = '| DATA_MISSING | 0 | 0 | 0 | 0 |';
        return implode("\n", $rows);
    }
    foreach ($days as $i => $d) {
        $rows[] = '| ' . (string)$d
            . ' | ' . (int)($series['p0'][$i] ?? 0)
            . ' | ' . (int)($series['p1'][$i] ?? 0)
            . ' | ' . (int)($series['p2'][$i] ?? 0)
            . ' | ' . (int)($series['data_missing'][$i] ?? 0)
            . ' |';
    }
    return implode("\n", $rows);
};

$md = [];
$md[] = '# Fix Backlog Trend';
$md[] = '';
$md[] = '- env: ' . fbt_mask($env);
$md[] = '- generated_at: ' . fbt_mask((string)$jsonPayload['generated_at']);
$md[] = '- overall_ok: ' . ($overallOk ? 'true' : 'false');
$md[] = '';
$md[] = '## 7-Day Snapshot';
$md[] = isset($windows['7']) ? $renderTable($windows['7']) : 'DATA_MISSING';
$md[] = '';
$md[] = '## 30-Day Snapshot';
$md[] = isset($windows['30']) ? $renderTable($windows['30']) : 'DATA_MISSING';
$md[] = '';
$md[] = '## Trend Interpretation';
$md[] = '- 7d P0 trend: ' . (string)($windows['7']['trend']['p0']['trend'] ?? 'DATA_MISSING');
$md[] = '- 30d P0 trend: ' . (string)($windows['30']['trend']['p0']['trend'] ?? 'DATA_MISSING');
$md[] = '- Flags 7d: ' . implode(', ', (array)($windows['7']['flags'] ?? ['DATA_MISSING']));
$md[] = '- Flags 30d: ' . implode(', ', (array)($windows['30']['flags'] ?? ['DATA_MISSING']));
$md[] = '';
$md[] = '## Pointers';
$md[] = '- fix_backlog_last: ' . fbt_mask($dir . '/fix_backlog_last.md');
$md[] = '- pipeline_last: ' . fbt_mask($dir . '/pipeline_last.json');
$md[] = '';

$svg7 = fbt_svg_polyline((array)($windows['7']['series']['p0'] ?? []), 440, 120);
$svg30 = fbt_svg_polyline((array)($windows['30']['series']['p0'] ?? []), 440, 120);
$p0Candidate = (array)($windows['30']['series']['p0'] ?? $windows['7']['series']['p0'] ?? [0]);
$lastP0 = (int)end($p0Candidate);
$actionTitle = $lastP0 > 0 ? 'NO-GO for deploy' : 'GO candidate';
$actionDesc = $lastP0 > 0
    ? 'P0 backlog still present. Resolve P0 blockers before deploy and review fix_backlog_last.md.'
    : 'P0 is zero. Candidate for deploy, continue monitoring trend and data integrity.';

$html = '<!doctype html><html><head><meta charset="utf-8"><title>Fix Backlog Trend</title>'
    . '<style>body{font-family:Arial,sans-serif;margin:18px;color:#111}h1,h2{margin:8px 0}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #ddd;padding:6px;text-align:left}.kpi{display:flex;gap:10px}.card{border:1px solid #ddd;padding:8px;border-radius:6px;flex:1}.action{border:2px solid #222;padding:10px;margin-top:10px}@media print{@page{size:A4;margin:10mm}body{margin:0}}</style></head><body>'
    . '<h1>Fix Backlog Trend Summary</h1>'
    . '<p><strong>env:</strong> ' . htmlspecialchars(fbt_mask($env), ENT_QUOTES, 'UTF-8') . ' | <strong>generated_at:</strong> ' . htmlspecialchars(fbt_mask((string)$jsonPayload['generated_at']), ENT_QUOTES, 'UTF-8') . '</p>'
    . '<h2>KPI Trend</h2>'
    . '<div class="kpi">'
    . '<div class="card"><strong>P0 7d delta:</strong> ' . (string)($windows['7']['trend']['p0']['delta'] ?? 0) . '<br><strong>P0 30d delta:</strong> ' . (string)($windows['30']['trend']['p0']['delta'] ?? 0) . '</div>'
    . '<div class="card"><strong>P1 7d delta:</strong> ' . (string)($windows['7']['trend']['p1']['delta'] ?? 0) . '<br><strong>P1 30d delta:</strong> ' . (string)($windows['30']['trend']['p1']['delta'] ?? 0) . '</div>'
    . '<div class="card"><strong>P2 7d delta:</strong> ' . (string)($windows['7']['trend']['p2']['delta'] ?? 0) . '<br><strong>P2 30d delta:</strong> ' . (string)($windows['30']['trend']['p2']['delta'] ?? 0) . '</div>'
    . '</div>'
    . '<h2>P0 Mini Chart (7d)</h2>' . $svg7
    . '<h2>P0 Mini Chart (30d)</h2>' . $svg30
    . '<h2>Top Modules Hotspot (Top 3 P0)</h2><table><tr><th>Module</th><th>P0 Last</th><th>P0 Delta 7d</th></tr>';
foreach ($topModules as $tm) {
    $html .= '<tr><td>' . htmlspecialchars((string)$tm['module'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int)$tm['p0_last'] . '</td><td>' . (int)$tm['p0_delta_7d'] . '</td></tr>';
}
if ($topModules === []) {
    $html .= '<tr><td colspan="3">DATA_MISSING</td></tr>';
}
$html .= '</table><div class="action"><strong>Action:</strong> ' . htmlspecialchars($actionTitle, ENT_QUOTES, 'UTF-8') . '<br>'
    . htmlspecialchars($actionDesc, ENT_QUOTES, 'UTF-8')
    . '</div></body></html>';

$prefix = $dir . '/fix_backlog_trend_' . $runId;
$written = fbt_write_outputs($prefix, $jsonPayload, implode("\n", $md) . "\n", $html, $writeLast);

$out = [
    'state_version' => 1,
    'run_id' => $runId,
    'overall_ok' => $overallOk,
    'artifacts' => $written,
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);

