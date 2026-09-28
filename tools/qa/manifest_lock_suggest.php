<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/manifest_lock_suggest_lib.php';

$args = $_SERVER['argv'] ?? [];
$opts = [
    'stage' => '',
    'env' => '',
    'run_id' => '',
    'write_last' => false,
    'strict' => true,
];
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') {
        continue;
    }
    if ($arg === '--write-last') {
        $opts['write_last'] = true;
        continue;
    }
    if (str_starts_with($arg, '--stage=')) {
        $opts['stage'] = (string)substr($arg, 8);
        continue;
    }
    if (str_starts_with($arg, '--env=')) {
        $opts['env'] = strtolower(trim((string)substr($arg, 6)));
        continue;
    }
    if (str_starts_with($arg, '--run-id=')) {
        $opts['run_id'] = trim((string)substr($arg, 9));
        continue;
    }
    if (str_starts_with($arg, '--strict=')) {
        $raw = strtolower(trim((string)substr($arg, 9)));
        $opts['strict'] = !in_array($raw, ['0', 'false', 'no', 'off'], true);
    }
}

$stage = manifest_lock_stage_normalize((string)$opts['stage']);
$env = (string)$opts['env'];
$runId = trim((string)$opts['run_id']);
$writeLast = (bool)$opts['write_last'];
$strict = (bool)$opts['strict'];

if ($stage === '' || !in_array($env, ['staging', 'production'], true)) {
    fwrite(STDERR, "Usage: php tools/qa/manifest_lock_suggest.php --stage=00..16 --env=staging|production [--run-id=...] [--write-last] [--strict=true]\n");
    exit(2);
}
if ($runId === '') {
    $runId = 'suggest-' . strtolower($env) . '-s' . $stage . '-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 10);
}

$suggestions = [];
$manifestRead = manifest_lock_read_manifest();
$requirements = [];

if (!$manifestRead['ok']) {
    manifest_suggest_push(
        $suggestions,
        'CRITICAL',
        'P0',
        'MISSING_FILE',
        'Stage manifest unavailable',
        'Manifest source-of-truth missing/invalid: ' . (string)$manifestRead['error'],
        [
            [
                'action' => 'RUN_COMMAND',
                'value_masked' => 'php -r "$j=file_get_contents(\'tools/qa/stage_pipeline_manifest_v1.json\'); json_decode($j,true,512,JSON_THROW_ON_ERROR);"',
            ],
            [
                'action' => 'RUN_COMMAND',
                'value_masked' => 'unknown: investigate tools/qa/stage_pipeline_manifest_v1.json integrity',
            ],
        ]
    );
} else {
    $stageReq = manifest_lock_get_stage_requirements((array)$manifestRead['data'], $stage);
    if (!$stageReq['ok']) {
        manifest_suggest_push(
            $suggestions,
            'CRITICAL',
            'P0',
            'MISSING_FILE',
            'Stage requirements missing',
            'Requirements for stage not found in manifest: ' . (string)$stageReq['error'],
            [
                [
                    'action' => 'RUN_COMMAND',
                    'value_masked' => 'unknown: add stages["' . $stage . '"].requirements into tools/qa/stage_pipeline_manifest_v1.json',
                ],
            ]
        );
    } else {
        $requirements = (array)$stageReq['requirements'];
    }
}

$requiredFiles = isset($requirements['required_files']) && is_array($requirements['required_files'])
    ? $requirements['required_files']
    : [];
$missingFiles = [];
foreach ($requiredFiles as $file) {
    if (!is_string($file)) {
        continue;
    }
    $check = manifest_lock_file_requirement_check($file);
    if (!$check['ok']) {
        $missingFiles[] = (string)$check['masked'];
    }
}
if ($missingFiles !== []) {
    $actions = [
        [
            'action' => 'FILE_SKELETON',
            'target' => 'web/example/index.php',
            'snippet' => manifest_suggest_template_web_page(),
        ],
        [
            'action' => 'FILE_SKELETON',
            'target' => 'api/v1/example/list.php',
            'snippet' => manifest_suggest_template_api_endpoint(),
        ],
        [
            'action' => 'FILE_SKELETON',
            'target' => 'tools/qa/example_check.php',
            'snippet' => manifest_suggest_template_qa_tool(),
        ],
        [
            'action' => 'RUN_COMMAND',
            'value_masked' => 'php tools/qa/validate_stage_assets.php --stage=' . $stage . ' --env=' . $env . ' --write-last',
        ],
    ];
    manifest_suggest_push(
        $suggestions,
        'CRITICAL',
        'P0',
        'MISSING_FILE',
        'Required files missing in stage ' . $stage,
        'Missing required files: ' . implode(', ', $missingFiles),
        $actions
    );
}

$httpWhitelist = (string)($requirements['required_http_endpoints_file'] ?? '');
$contractWhitelist = (string)($requirements['required_contract_whitelist_file'] ?? '');
$httpRead = manifest_lock_read_json_inside_root($httpWhitelist);
$contractRead = manifest_lock_read_json_inside_root($contractWhitelist);

if (!$httpRead['ok']) {
    $manifestHint = manifest_lock_resolve_relpath('tools/qa/whitelists/http_manifest_v1.json');
    $actions = [
        [
            'action' => 'RUN_COMMAND',
            'value_masked' => 'php tools/qa/generate_whitelists.php --apply --stage=' . $stage,
        ],
        [
            'action' => 'RUN_COMMAND',
            'value_masked' => 'php tools/qa/generate_whitelists.php --verify-only --stage=' . $stage,
        ],
        [
            'action' => 'RUN_COMMAND',
            'value_masked' => 'unknown: ensure stage routes exist in ' . (string)($manifestHint['ok'] ? $manifestHint['masked'] : 'tools/qa/whitelists/http_manifest_v1.json'),
        ],
    ];
    manifest_suggest_push(
        $suggestions,
        'CRITICAL',
        'P0',
        'MISSING_WHITELIST',
        'HTTP whitelist file missing/invalid',
        (string)($httpRead['masked'] ?? 'unknown') . ' | reason=' . (string)($httpRead['error'] ?? 'unknown'),
        $actions
    );
}
if (!$contractRead['ok']) {
    $manifestHint = manifest_lock_resolve_relpath('tools/qa/whitelists/contract_manifest_v1.json');
    $actions = [
        [
            'action' => 'RUN_COMMAND',
            'value_masked' => 'php tools/qa/generate_whitelists.php --apply --stage=' . $stage,
        ],
        [
            'action' => 'RUN_COMMAND',
            'value_masked' => 'php tools/qa/generate_whitelists.php --verify-only --stage=' . $stage,
        ],
        [
            'action' => 'RUN_COMMAND',
            'value_masked' => 'unknown: ensure stage routes exist in ' . (string)($manifestHint['ok'] ? $manifestHint['masked'] : 'tools/qa/whitelists/contract_manifest_v1.json'),
        ],
    ];
    manifest_suggest_push(
        $suggestions,
        'CRITICAL',
        'P0',
        'MISSING_WHITELIST',
        'Contract whitelist file missing/invalid',
        (string)($contractRead['masked'] ?? 'unknown') . ' | reason=' . (string)($contractRead['error'] ?? 'unknown'),
        $actions
    );
}

$requiredHttp = manifest_lock_extract_required_routes($requirements, 'required_http_routes');
$requiredContract = manifest_lock_extract_required_routes($requirements, 'required_contract_routes');
$actualHttp = $httpRead['ok'] ? manifest_lock_extract_route_set_from_whitelist((array)$httpRead['data']) : [];
$actualContract = $contractRead['ok'] ? manifest_lock_extract_route_set_from_whitelist((array)$contractRead['data']) : [];
$missingHttpRoutes = manifest_lock_routes_missing($requiredHttp, $actualHttp);
$missingContractRoutes = manifest_lock_routes_missing($requiredContract, $actualContract);

if ($missingHttpRoutes !== []) {
    manifest_suggest_push(
        $suggestions,
        'HIGH',
        'P0',
        'MISSING_ROUTE',
        'Required HTTP routes not covered',
        'Routes required but absent from HTTP whitelist output: ' . implode(', ', $missingHttpRoutes),
        manifest_suggest_actions_common_whitelist(
            $stage,
            'tools/qa/whitelists/http_manifest_v1.json',
            manifest_suggest_route_patch_snippet($stage, $missingHttpRoutes)
        )
    );
}
if ($missingContractRoutes !== []) {
    manifest_suggest_push(
        $suggestions,
        'HIGH',
        'P0',
        'MISSING_ROUTE',
        'Required contract routes not covered',
        'Routes required but absent from contract whitelist output: ' . implode(', ', $missingContractRoutes),
        manifest_suggest_actions_common_whitelist(
            $stage,
            'tools/qa/whitelists/contract_manifest_v1.json',
            manifest_suggest_route_patch_snippet($stage, $missingContractRoutes)
        )
    );
}

$requiredEnv = isset($requirements['required_env']) && is_array($requirements['required_env'])
    ? $requirements['required_env']
    : [];
$requiredEnvEquals = isset($requirements['required_env_equals']) && is_array($requirements['required_env_equals'])
    ? $requirements['required_env_equals']
    : [];
$missingEnv = [];
foreach ($requiredEnv as $envKey) {
    if (!is_string($envKey) || trim($envKey) === '') {
        continue;
    }
    $v = getenv($envKey);
    if ($v === false || trim((string)$v) === '') {
        $missingEnv[] = trim($envKey);
    }
}
foreach ($requiredEnvEquals as $envKey => $expected) {
    if (!is_string($envKey) || trim($envKey) === '') {
        continue;
    }
    $actual = strtolower(trim((string)(getenv($envKey) ?: '')));
    $expectedNorm = strtolower(trim((string)$expected));
    if ($actual === '' || $actual !== $expectedNorm) {
        $missingEnv[] = trim($envKey) . '(expected=' . $expectedNorm . ')';
    }
}
if ($missingEnv !== []) {
    $sev = $env === 'production' ? 'CRITICAL' : 'HIGH';
    manifest_suggest_push(
        $suggestions,
        $sev,
        'P0',
        'MISSING_ENV',
        'Required environment variables are missing',
        'Missing env keys: ' . implode(', ', $missingEnv),
        [
            [
                'action' => 'RUN_COMMAND',
                'value_masked' => 'unknown: set env keys in .env.' . $env . ' and CI secrets (names only, no raw values in logs)',
            ],
            [
                'action' => 'RUN_COMMAND',
                'value_masked' => 'php tools/qa/validate_stage_assets.php --stage=' . $stage . ' --env=' . $env . ' --write-last',
            ],
        ]
    );
}

if ($stage === '08') {
    $mobileMissing = array_values(array_filter($missingEnv, static fn(string $x): bool => str_starts_with($x, 'MOBILE_SMOKE_')));
    if ($mobileMissing !== []) {
        manifest_suggest_push(
            $suggestions,
            'LOW',
            'P2',
            'MISSING_ENV',
            'Optional mobile UI deferred note',
            'Mobile smoke credentials absent: ' . implode(', ', $mobileMissing) . '. Record deferment if mobile UI testing is intentionally deferred.',
            [
                [
                    'action' => 'RUN_COMMAND',
                    'value_masked' => 'unknown: add deferment note in governance docs and revisit before mobile contract expansion',
                ],
            ]
        );
    }
}

$payload = [
    'state_version' => 1,
    'run_id' => $runId,
    'env' => $env,
    'stage' => $stage,
    'generated_at' => manifest_lock_now_iso(),
    'overall_ok' => ($suggestions === []),
    'summary' => manifest_suggest_count_summary($suggestions),
    'suggestions' => $suggestions,
    'strict' => $strict,
];

// Optional queue integration: default OFF.
$queueEnabled = strtoupper(trim((string)(getenv('SUGGEST_TO_QUEUE') ?: 'OFF'))) === 'ON';
$queueGuardFile = manifest_lock_root() . '/tools/ops/manual_action_queue.php';
if ($queueEnabled && is_file($queueGuardFile)) {
    $queuePath = manifest_lock_root() . '/storage/state/manual_actions_queue.json';
    $existing = [];
    if (is_file($queuePath)) {
        $raw = (string)@file_get_contents($queuePath);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
    if (!isset($existing['state_version'])) {
        $existing['state_version'] = 1;
    }
    if (!isset($existing['items']) || !is_array($existing['items'])) {
        $existing['items'] = [];
    }
    $existing['items'][] = [
        'id' => 'queue-' . $runId . '-s' . $stage,
        'created_at' => manifest_lock_now_iso(),
        'stage' => $stage,
        'env' => $env,
        'summary' => $payload['summary'],
        'source' => 'manifest_lock_suggest',
    ];
    @file_put_contents($queuePath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $payload['queue_note'] = 'Queue integration enabled. Added suggestion batch into ' . manifest_suggest_mask($queuePath);
} else {
    $payload['queue_note'] = 'Queue integration disabled';
}

$artifacts = manifest_suggest_write_evidence($payload, $writeLast);
$payload['artifacts'] = $artifacts;

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($payload['overall_ok'] ? 0 : 1);
