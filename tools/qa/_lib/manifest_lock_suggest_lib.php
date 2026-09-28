<?php
declare(strict_types=1);

require_once __DIR__ . '/manifest_lock_lib.php';

if (!function_exists('manifest_suggest_mask')) {
    function manifest_suggest_mask(string $value): string
    {
        return manifest_lock_mask($value);
    }
}

if (!function_exists('manifest_suggest_pipeline_log_dir')) {
    function manifest_suggest_pipeline_log_dir(): string
    {
        return manifest_lock_pipeline_log_dir();
    }
}

if (!function_exists('manifest_suggest_new_id')) {
    function manifest_suggest_new_id(int $idx): string
    {
        return 'SUGG-' . str_pad((string)$idx, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('manifest_suggest_default_summary')) {
    function manifest_suggest_default_summary(): array
    {
        return [
            'critical_count' => 0,
            'high_count' => 0,
            'med_count' => 0,
            'low_count' => 0,
        ];
    }
}

if (!function_exists('manifest_suggest_push')) {
    function manifest_suggest_push(array &$suggestions, string $severity, string $priority, string $category, string $title, string $detailsMasked, array $actions): void
    {
        $id = manifest_suggest_new_id(count($suggestions) + 1);
        $suggestions[] = [
            'id' => $id,
            'severity' => $severity,
            'priority' => $priority,
            'category' => $category,
            'title' => $title,
            'details_masked' => manifest_suggest_mask($detailsMasked),
            'recommended_actions' => $actions,
        ];
    }
}

if (!function_exists('manifest_suggest_count_summary')) {
    function manifest_suggest_count_summary(array $suggestions): array
    {
        $summary = manifest_suggest_default_summary();
        foreach ($suggestions as $s) {
            $sev = strtoupper((string)($s['severity'] ?? ''));
            if ($sev === 'CRITICAL') {
                $summary['critical_count']++;
            } elseif ($sev === 'HIGH') {
                $summary['high_count']++;
            } elseif ($sev === 'MED') {
                $summary['med_count']++;
            } elseif ($sev === 'LOW') {
                $summary['low_count']++;
            }
        }
        return $summary;
    }
}

if (!function_exists('manifest_suggest_template_web_page')) {
    function manifest_suggest_template_web_page(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/bootstrap.php';
require_login();
require_permission('module.view');

$title = 'Page Title';
$rows = [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
  <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
  <main>
    <?php if ($rows === []): ?>
      <p>No data.</p>
    <?php endif; ?>
  </main>
</body>
</html>
PHP;
    }
}

if (!function_exists('manifest_suggest_template_api_endpoint')) {
    function manifest_suggest_template_api_endpoint(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_shared/bootstrap.php';
header('Content-Type: application/json');

require_login(); // or require_mobile_auth();

try {
    $data = ['items' => []];
    echo json_encode([
        'state_version' => 1,
        'ok' => true,
        'data' => $data,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'state_version' => 1,
        'ok' => false,
        'error' => 'internal_error',
    ], JSON_UNESCAPED_SLASHES);
}
PHP;
    }
}

if (!function_exists('manifest_suggest_template_qa_tool')) {
    function manifest_suggest_template_qa_tool(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require_once __DIR__ . '/../tools_state_lib.php';

$ok = true;
$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'overall_ok' => $ok,
    'checks' => [],
];
ts_write_json(ts_storage_logs_dir() . '/sample_tool.last.json', $payload);
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
PHP;
    }
}

if (!function_exists('manifest_suggest_route_patch_snippet')) {
    function manifest_suggest_route_patch_snippet(string $stage, array $missingRoutes): string
    {
        $entries = [];
        foreach ($missingRoutes as $route) {
            $parts = explode(' ', (string)$route, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $entries[] = ['method' => strtoupper(trim($parts[0])), 'path' => trim($parts[1])];
        }
        $snippet = [
            'stages' => [
                $stage => [
                    'append' => $entries,
                ],
            ],
        ];
        return (string)json_encode($snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('manifest_suggest_actions_common_whitelist')) {
    function manifest_suggest_actions_common_whitelist(string $stage, string $target, string $patchSnippet): array
    {
        return [
            [
                'action' => 'RUN_COMMAND',
                'value_masked' => 'php tools/qa/generate_whitelists.php --apply --stage=' . $stage,
            ],
            [
                'action' => 'RUN_COMMAND',
                'value_masked' => 'php tools/qa/generate_whitelists.php --verify-only --stage=' . $stage,
            ],
            [
                'action' => 'PATCH_SNIPPET',
                'target' => $target,
                'snippet' => $patchSnippet,
            ],
        ];
    }
}

if (!function_exists('manifest_suggest_write_evidence')) {
    function manifest_suggest_write_evidence(array $payload, bool $writeLast): array
    {
        $stage = (string)($payload['stage'] ?? '');
        $runId = (string)($payload['run_id'] ?? '');
        $logDir = manifest_suggest_pipeline_log_dir();
        $jsonPath = $logDir . '/manifest_suggest_stage' . $stage . '_' . $runId . '.json';
        $mdPath = $logDir . '/manifest_suggest_stage' . $stage . '_' . $runId . '.md';
        ts_write_json($jsonPath, $payload);
        @file_put_contents($mdPath, manifest_suggest_md_from_payload($payload));

        $written = [
            'json' => manifest_suggest_mask($jsonPath),
            'md' => manifest_suggest_mask($mdPath),
        ];
        if ($writeLast) {
            $jsonLast = $logDir . '/manifest_suggest_stage' . $stage . '_last.json';
            $mdLast = $logDir . '/manifest_suggest_stage' . $stage . '_last.md';
            ts_write_json($jsonLast, $payload);
            @file_put_contents($mdLast, manifest_suggest_md_from_payload($payload));
            $written['json_last'] = manifest_suggest_mask($jsonLast);
            $written['md_last'] = manifest_suggest_mask($mdLast);
        }
        return $written;
    }
}

if (!function_exists('manifest_suggest_md_from_payload')) {
    function manifest_suggest_md_from_payload(array $payload): string
    {
        $stage = (string)($payload['stage'] ?? 'unknown');
        $runId = (string)($payload['run_id'] ?? 'unknown');
        $env = (string)($payload['env'] ?? 'unknown');
        $generated = (string)($payload['generated_at'] ?? manifest_lock_now_iso());
        $summary = (array)($payload['summary'] ?? manifest_suggest_default_summary());
        $suggestions = (array)($payload['suggestions'] ?? []);

        $lines = [];
        $lines[] = '# Stage ' . $stage . ' Suggest Patch';
        $lines[] = '';
        $lines[] = '- RUN_ID: ' . manifest_suggest_mask($runId);
        $lines[] = '- env: ' . manifest_suggest_mask($env);
        $lines[] = '- generated_at: ' . manifest_suggest_mask($generated);
        $lines[] = '';
        $lines[] = '## Summary';
        $lines[] = '- critical: ' . (int)($summary['critical_count'] ?? 0);
        $lines[] = '- high: ' . (int)($summary['high_count'] ?? 0);
        $lines[] = '- med: ' . (int)($summary['med_count'] ?? 0);
        $lines[] = '- low: ' . (int)($summary['low_count'] ?? 0);
        $lines[] = '';
        $lines[] = '## Table';
        $lines[] = '| category | severity | priority | short title |';
        $lines[] = '|---|---|---|---|';
        foreach ($suggestions as $s) {
            $lines[] = '| '
                . (string)($s['category'] ?? 'unknown') . ' | '
                . (string)($s['severity'] ?? 'unknown') . ' | '
                . (string)($s['priority'] ?? 'unknown') . ' | '
                . str_replace('|', '/', (string)($s['title'] ?? '-')) . ' |';
        }
        if ($suggestions === []) {
            $lines[] = '| unknown | LOW | P2 | no actionable suggestion generated |';
        }
        $lines[] = '';
        $lines[] = '## Details';
        foreach ($suggestions as $s) {
            $lines[] = '### ' . (string)($s['id'] ?? 'SUGG-XXXX') . ' - ' . (string)($s['title'] ?? 'untitled');
            $lines[] = '- category: ' . (string)($s['category'] ?? 'unknown');
            $lines[] = '- severity/priority: ' . (string)($s['severity'] ?? 'unknown') . ' / ' . (string)($s['priority'] ?? 'unknown');
            $lines[] = '- why: ' . manifest_suggest_mask((string)($s['details_masked'] ?? 'unknown'));
            $lines[] = '- steps:';
            $actions = (array)($s['recommended_actions'] ?? []);
            if ($actions === []) {
                $lines[] = '  - unknown: investigate manifest and stage outputs';
            }
            foreach ($actions as $a) {
                $act = (string)($a['action'] ?? 'ACTION');
                if (isset($a['value_masked'])) {
                    $lines[] = '  - [' . $act . '] ' . manifest_suggest_mask((string)$a['value_masked']);
                } elseif (isset($a['target'])) {
                    $lines[] = '  - [' . $act . '] target=' . manifest_suggest_mask((string)$a['target']);
                } else {
                    $lines[] = '  - [' . $act . ']';
                }
            }
            $lines[] = '';
        }

        $lines[] = '## Commands to run';
        $lines[] = '- php tools/qa/generate_whitelists.php --apply --stage=' . $stage;
        $lines[] = '- php tools/qa/generate_whitelists.php --verify-only --stage=' . $stage;
        $lines[] = '- php tools/qa/validate_stage_assets.php --stage=' . $stage . ' --env=' . $env . ' --write-last';
        $lines[] = '';

        $lines[] = '## Patch snippets';
        foreach ($suggestions as $s) {
            foreach ((array)($s['recommended_actions'] ?? []) as $a) {
                if (($a['action'] ?? '') === 'PATCH_SNIPPET') {
                    $lines[] = '- target: ' . manifest_suggest_mask((string)($a['target'] ?? 'unknown'));
                    $lines[] = '```json';
                    $lines[] = (string)($a['snippet'] ?? '{}');
                    $lines[] = '```';
                }
            }
        }
        $lines[] = '';

        $lines[] = '## Skeleton templates';
        $lines[] = '### Web page';
        $lines[] = '```php';
        $lines[] = manifest_suggest_template_web_page();
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '### API endpoint';
        $lines[] = '```php';
        $lines[] = manifest_suggest_template_api_endpoint();
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '### QA tool';
        $lines[] = '```php';
        $lines[] = manifest_suggest_template_qa_tool();
        $lines[] = '```';
        $lines[] = '';

        if (isset($payload['queue_note'])) {
            $lines[] = '## Queue integration';
            $lines[] = '- ' . manifest_suggest_mask((string)$payload['queue_note']);
            $lines[] = '';
        }
        return implode("\n", $lines);
    }
}
