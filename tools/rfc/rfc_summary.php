<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/_lib/rfc_lib.php';

$args = $_SERVER['argv'] ?? [];
$range = 'last30d';
$env = 'production';
$writeLast = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--write-last') $writeLast = true;
    elseif (str_starts_with($arg, '--range=')) $range = strtolower(trim((string)substr($arg, 8)));
    elseif (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
}
$days = $range === 'last7d' ? 7 : 30;
$cutoff = strtotime('-' . $days . ' days');

$items = [];
foreach (rfc_list_files() as $file) {
    $p = rfc_parse_file($file);
    if (!$p['ok']) continue;
    $fm = (array)$p['frontmatter'];
    $ts = strtotime((string)($fm['CREATED_AT'] ?? ''));
    if ($ts === false || $ts < $cutoff) continue;
    if ($env !== '' && in_array($env, ['staging', 'production'], true) && strtolower((string)($fm['TARGET_ENV'] ?? '')) !== $env) continue;
    $required = (array)($fm['REQUIRES_APPROVALS'] ?? []);
    $approved = array_map(static fn(array $a): string => strtoupper((string)($a['ROLE'] ?? '')), (array)($fm['APPROVALS'] ?? []));
    $completion = 0;
    if ($required !== []) {
        $okCount = 0;
        foreach ($required as $r) if (in_array(strtoupper((string)$r), $approved, true)) $okCount++;
        $completion = (int)round(($okCount / max(1, count($required))) * 100);
    }
    $items[] = [
        'rfc_id' => (string)($fm['RFC_ID'] ?? ''),
        'title' => (string)($fm['TITLE'] ?? ''),
        'type' => (string)($fm['TYPE'] ?? ''),
        'status' => (string)($fm['STATUS'] ?? ''),
        'target_env' => (string)($fm['TARGET_ENV'] ?? ''),
        'schedule' => (string)($fm['SCHEDULE'] ?? ''),
        'approvals_completion' => $completion,
        'file' => rfc_mask($file),
    ];
}
usort($items, static fn(array $a, array $b): int => strcmp((string)$b['rfc_id'], (string)$a['rfc_id']));
$upcomingProd = array_values(array_filter($items, static fn(array $i): bool => ($i['target_env'] ?? '') === 'production' && in_array((string)($i['status'] ?? ''), ['IN_REVIEW', 'APPROVED'], true)));
$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'range' => $range,
    'env' => $env,
    'count' => count($items),
    'upcoming_production' => array_slice($upcomingProd, 0, 10),
    'items' => $items,
];

$dir = rfc_pipeline_dir();
ts_write_json($dir . '/rfc_summary_last.json', $payload);
$md = [];
$md[] = '# RFC Summary';
$md[] = '';
$md[] = '- generated_at: ' . rfc_mask((string)$payload['generated_at']);
$md[] = '- range/env: ' . rfc_mask($range) . ' / ' . rfc_mask($env);
$md[] = '- count: ' . (string)count($items);
$md[] = '';
$md[] = '| RFC | Title | Type | Status | Schedule | Approvals % |';
$md[] = '|---|---|---|---|---|---:|';
foreach ($items as $i) {
    $md[] = '| ' . rfc_mask((string)$i['rfc_id']) . ' | ' . rfc_mask((string)$i['title']) . ' | ' . rfc_mask((string)$i['type']) . ' | ' . rfc_mask((string)$i['status']) . ' | ' . rfc_mask((string)$i['schedule']) . ' | ' . (int)$i['approvals_completion'] . ' |';
}
if ($items === []) $md[] = '| - | DATA_MISSING | - | - | - | 0 |';
@file_put_contents($dir . '/rfc_summary_last.md', implode("\n", $md) . "\n");

$rows = '';
foreach ($items as $i) {
    $rows .= '<tr><td>' . htmlspecialchars((string)$i['rfc_id'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$i['title'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$i['type'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$i['status'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$i['schedule'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int)$i['approvals_completion'] . '%</td></tr>';
}
if ($rows === '') $rows = '<tr><td colspan="6">DATA_MISSING</td></tr>';
$html = '<!doctype html><html><head><meta charset="utf-8"><title>RFC Summary</title><style>body{font-family:Arial,sans-serif;margin:14px}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #ddd;padding:6px}@media print{@page{size:A4;margin:10mm}body{margin:0;font-size:11px}}</style></head><body><h1>RFC Summary (Last 30 Days)</h1><p>generated_at: ' . htmlspecialchars((string)$payload['generated_at'], ENT_QUOTES, 'UTF-8') . '</p><table><tr><th>RFC</th><th>Title</th><th>Type</th><th>Status</th><th>Schedule</th><th>Approvals</th></tr>' . $rows . '</table></body></html>';
@file_put_contents($dir . '/rfc_summary_last.html', $html);

$cache = [
    'state_version' => 1,
    'generated_at' => $payload['generated_at'],
    'count' => count($items),
    'latest_rfc_id' => (string)($items[0]['rfc_id'] ?? ''),
];
ts_write_json(rfc_state_cache_path(), $cache);

echo json_encode(['state_version' => 1, 'overall_ok' => true, 'count' => count($items), 'artifacts' => ['json' => rfc_mask($dir . '/rfc_summary_last.json'), 'md' => rfc_mask($dir . '/rfc_summary_last.md'), 'html' => rfc_mask($dir . '/rfc_summary_last.html')]], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);

