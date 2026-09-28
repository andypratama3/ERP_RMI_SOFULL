<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/fix_backlog_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$fromStage = '07';
$toStage = '16';
$inputMode = 'last';
$runId = '';
$writeLast = true;
$strict = false;

foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') {
        continue;
    }
    if ($arg === '--write-last') {
        $writeLast = true;
        continue;
    }
    if ($arg === '--no-write-last') {
        $writeLast = false;
        continue;
    }
    if (str_starts_with($arg, '--env=')) {
        $env = strtolower(trim((string)substr($arg, 6)));
        continue;
    }
    if (str_starts_with($arg, '--from-stage=')) {
        $fromStage = (string)substr($arg, 13);
        continue;
    }
    if (str_starts_with($arg, '--to-stage=')) {
        $toStage = (string)substr($arg, 11);
        continue;
    }
    if (str_starts_with($arg, '--input-mode=')) {
        $inputMode = strtolower(trim((string)substr($arg, 13)));
        continue;
    }
    if (str_starts_with($arg, '--run-id=')) {
        $runId = trim((string)substr($arg, 9));
        continue;
    }
    if ($arg === '--strict') {
        $strict = true;
        continue;
    }
    if (str_starts_with($arg, '--strict=')) {
        $raw = strtolower(trim((string)substr($arg, 9)));
        $strict = !in_array($raw, ['0', 'false', 'no', 'off'], true);
    }
}

$fromStage = fb_stage_normalize($fromStage);
$toStage = fb_stage_normalize($toStage);
if ($fromStage === '') $fromStage = '07';
if ($toStage === '') $toStage = '16';
if ((int)$fromStage > (int)$toStage) {
    [$fromStage, $toStage] = [$toStage, $fromStage];
}
if (!in_array($env, ['staging', 'production'], true)) {
    $env = 'staging';
}
if (!in_array($inputMode, ['last', 'run-id'], true)) {
    $inputMode = 'last';
}
if ($inputMode === 'run-id' && $runId === '') {
    fwrite(STDERR, "run-id is required when input-mode=run-id\n");
    exit(2);
}

$pipelineLastMeta = fb_pipeline_last_meta();
$pipelineLast = (array)($pipelineLastMeta['data'] ?? []);
if ($runId === '' && $inputMode === 'last') {
    $runId = (string)($pipelineLast['run_id'] ?? '');
}
if ($runId === '') {
    $runId = 'fix-backlog-' . $env . '-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
}

$itemsRaw = [];
$stagesWithMissingSuggestion = [];
for ($stageNum = (int)$fromStage; $stageNum <= (int)$toStage; $stageNum++) {
    $stage = str_pad((string)$stageNum, 2, '0', STR_PAD_LEFT);
    $sourcePath = fb_suggestion_path($stage, $inputMode, $runId);
    $read = fb_read_json($sourcePath);
    $sourceMasked = (string)$read['path_masked'];

    if (!$read['ok'] || !isset($read['data']['suggestions']) || !is_array($read['data']['suggestions'])) {
        $stagesWithMissingSuggestion[] = $stage;
        $priority = 'P1';
        if (fb_stage_executed_in_pipeline($pipelineLast, $stage)) {
            $priority = 'P0';
        }
        $itemsRaw[] = [
            'backlog_id' => fb_deterministic_backlog_id($stage, 'DATA_MISSING'),
            'stage' => $stage,
            'module' => fb_module_for_stage($stage, 'DATA_MISSING', '', ''),
            'priority' => $priority,
            'severity' => 'MED',
            'category' => 'DATA_MISSING',
            'title' => 'Suggestion artifact missing/invalid for stage ' . $stage,
            'details_masked' => fb_mask('Unable to read suggestion artifact: ' . $sourceMasked . ' | reason=' . (string)$read['error']),
            'recommended_actions' => [
                ['action' => 'RUN_COMMAND', 'value_masked' => 'php tools/qa/validate_stage_assets.php --stage=' . $stage . ' --env=' . $env . ' --write-last'],
                ['action' => 'RUN_COMMAND', 'value_masked' => 'php tools/qa/manifest_lock_suggest.php --stage=' . $stage . ' --env=' . $env . ' --write-last'],
            ],
            'status' => 'OPEN',
            'owner' => 'TBD',
            'sla_target' => fb_sla_by_priority($priority),
            'created_at' => tools_fmt_ts(date(DateTimeInterface::ATOM)),
            'source_artifact' => $sourceMasked,
            'request_id' => (string)($pipelineLast['run_id'] ?? ''),
        ];
        continue;
    }

    $suggestions = (array)$read['data']['suggestions'];
    if ($suggestions === []) {
        $stagesWithMissingSuggestion[] = $stage;
        $itemsRaw[] = [
            'backlog_id' => fb_deterministic_backlog_id($stage, 'DATA_MISSING_EMPTY'),
            'stage' => $stage,
            'module' => fb_module_for_stage($stage, 'DATA_MISSING', '', ''),
            'priority' => 'P1',
            'severity' => 'MED',
            'category' => 'DATA_MISSING',
            'title' => 'Suggestion artifact missing/invalid for stage ' . $stage,
            'details_masked' => fb_mask('Suggestion artifact has no suggestions: ' . $sourceMasked),
            'recommended_actions' => [
                ['action' => 'RUN_COMMAND', 'value_masked' => 'php tools/qa/validate_stage_assets.php --stage=' . $stage . ' --env=' . $env . ' --write-last'],
                ['action' => 'RUN_COMMAND', 'value_masked' => 'php tools/qa/manifest_lock_suggest.php --stage=' . $stage . ' --env=' . $env . ' --write-last'],
            ],
            'status' => 'OPEN',
            'owner' => 'TBD',
            'sla_target' => fb_sla_by_priority('P1'),
            'created_at' => tools_fmt_ts(date(DateTimeInterface::ATOM)),
            'source_artifact' => $sourceMasked,
            'request_id' => (string)($read['data']['run_id'] ?? ''),
        ];
        continue;
    }

    foreach ($suggestions as $s) {
        if (!is_array($s)) {
            continue;
        }
        $suggestionId = (string)($s['id'] ?? 'UNKNOWN');
        $severity = strtoupper(trim((string)($s['severity'] ?? 'MED')));
        if (!in_array($severity, ['CRITICAL', 'HIGH', 'MED', 'LOW'], true)) {
            $severity = 'MED';
        }
        $priority = strtoupper(trim((string)($s['priority'] ?? '')));
        if (!in_array($priority, ['P0', 'P1', 'P2'], true)) {
            $priority = fb_priority_from_severity($severity);
        }
        $category = strtoupper(trim((string)($s['category'] ?? 'OTHER')));
        if (!in_array($category, ['MISSING_FILE', 'MISSING_ROUTE', 'MISSING_WHITELIST', 'MISSING_ENV', 'DATA_MISSING', 'OTHER'], true)) {
            $category = 'OTHER';
        }
        $title = trim((string)($s['title'] ?? 'Untitled suggestion'));
        if ($title === '') $title = 'Untitled suggestion';
        $details = (string)($s['details_masked'] ?? '');
        $actions = isset($s['recommended_actions']) && is_array($s['recommended_actions']) ? $s['recommended_actions'] : [];
        $itemsRaw[] = [
            'backlog_id' => fb_deterministic_backlog_id($stage, $suggestionId),
            'stage' => $stage,
            'module' => fb_module_for_stage($stage, $category, $title, $details),
            'priority' => $priority,
            'severity' => $severity,
            'category' => $category,
            'title' => $title,
            'details_masked' => fb_mask($details),
            'recommended_actions' => $actions,
            'status' => 'OPEN',
            'owner' => 'TBD',
            'sla_target' => fb_sla_by_priority($priority),
            'created_at' => tools_fmt_ts(date(DateTimeInterface::ATOM)),
            'source_artifact' => $sourceMasked,
            'request_id' => (string)($read['data']['run_id'] ?? ''),
        ];
    }
}

// Deduplicate category+title within same stage.
$merged = [];
foreach ($itemsRaw as $item) {
    $key = (string)$item['stage'] . '|' . (string)$item['category'] . '|' . mb_strtolower((string)$item['title']);
    if (!isset($merged[$key])) {
        $merged[$key] = $item;
        continue;
    }
    $current = $merged[$key];
    if (fb_severity_rank((string)$item['severity']) > fb_severity_rank((string)$current['severity'])) {
        $current['severity'] = $item['severity'];
    }
    if (fb_priority_rank((string)$item['priority']) > fb_priority_rank((string)$current['priority'])) {
        $current['priority'] = $item['priority'];
    }
    $current['sla_target'] = fb_sla_by_priority((string)$current['priority']);
    $current['recommended_actions'] = fb_merge_actions((array)$current['recommended_actions'], (array)$item['recommended_actions']);
    $merged[$key] = $current;
}
$items = fb_sort_items(array_values($merged));

$summary = fb_build_summary($items);
$overallOk = ($summary['data_missing'] === 0) && (count($items) === 0);
if ($stagesWithMissingSuggestion !== []) {
    $overallOk = false;
}

// Optional enrich (read-only)
$enrich = [
    'pipeline_last' => $pipelineLastMeta['ok'] ? $pipelineLastMeta['path_masked'] : 'DATA_MISSING',
    'manifest_lock_last_present' => [],
    'readiness_report' => 'DATA_MISSING',
    'ops_banner' => 'DATA_MISSING',
];
for ($stageNum = (int)$fromStage; $stageNum <= (int)$toStage; $stageNum++) {
    $stage = str_pad((string)$stageNum, 2, '0', STR_PAD_LEFT);
    $lockPath = fb_pipeline_dir() . '/manifest_lock_stage' . $stage . '_last.json';
    $enrich['manifest_lock_last_present'][$stage] = is_file($lockPath);
}
$readinessPath = fb_root() . '/storage/logs/readiness_report_last.json';
if (is_file($readinessPath)) {
    $enrich['readiness_report'] = fb_mask($readinessPath);
}
$bannerPath = fb_root() . '/storage/state/ops_banner.json';
if (is_file($bannerPath)) {
    $enrich['ops_banner'] = fb_mask($bannerPath);
}

$payload = [
    'state_version' => 1,
    'run_id' => $runId,
    'env' => $env,
    'generated_at' => date(DateTimeInterface::ATOM),
    'range' => ['from' => $fromStage, 'to' => $toStage],
    'overall_ok' => $overallOk,
    'summary' => $summary,
    'items' => $items,
    'input_mode' => $inputMode,
    'strict' => $strict,
    'enrich' => $enrich,
];

$dir = fb_pipeline_dir();
$jsonPath = $dir . '/fix_backlog_' . $runId . '.json';
$mdPath = $dir . '/fix_backlog_' . $runId . '.md';
$csvPath = $dir . '/fix_backlog_' . $runId . '.csv';

ops_write_json($jsonPath, $payload);
@file_put_contents($mdPath, fb_md_from_payload($payload));

$csvRows = [];
$csvRows[] = 'backlog_id,stage,module,priority,severity,category,title,status,owner,sla_target,source_artifact,created_at';
foreach ($items as $item) {
    $csvRows[] = implode(',', [
        fb_csv_escape((string)($item['backlog_id'] ?? '')),
        fb_csv_escape((string)($item['stage'] ?? '')),
        fb_csv_escape((string)($item['module'] ?? '')),
        fb_csv_escape((string)($item['priority'] ?? '')),
        fb_csv_escape((string)($item['severity'] ?? '')),
        fb_csv_escape((string)($item['category'] ?? '')),
        fb_csv_escape((string)($item['title'] ?? '')),
        fb_csv_escape((string)($item['status'] ?? 'OPEN')),
        fb_csv_escape((string)($item['owner'] ?? 'TBD')),
        fb_csv_escape((string)($item['sla_target'] ?? '')),
        fb_csv_escape((string)($item['source_artifact'] ?? '')),
        fb_csv_escape((string)($item['created_at'] ?? '')),
    ]);
}
@file_put_contents($csvPath, implode("\n", $csvRows) . "\n");

$artifacts = [
    'json' => fb_mask($jsonPath),
    'md' => fb_mask($mdPath),
    'csv' => fb_mask($csvPath),
];
if ($writeLast) {
    $jsonLast = $dir . '/fix_backlog_last.json';
    $mdLast = $dir . '/fix_backlog_last.md';
    $csvLast = $dir . '/fix_backlog_last.csv';
    ops_write_json($jsonLast, $payload);
    @file_put_contents($mdLast, fb_md_from_payload($payload));
    @file_put_contents($csvLast, implode("\n", $csvRows) . "\n");
    $artifacts['json_last'] = fb_mask($jsonLast);
    $artifacts['md_last'] = fb_mask($mdLast);
    $artifacts['csv_last'] = fb_mask($csvLast);
}

$out = [
    'state_version' => 1,
    'run_id' => $runId,
    'overall_ok' => $overallOk,
    'summary' => $summary,
    'artifacts' => $artifacts,
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;

$exitCode = 0;
if ($strict && $stagesWithMissingSuggestion !== []) {
    $exitCode = 1;
}
exit($exitCode);

