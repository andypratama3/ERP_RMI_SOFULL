<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/manifest_lock_lib.php';

function mlock_fail_item(string $code, string $message, string $details = ''): array
{
    return [
        'code' => $code,
        'message' => manifest_lock_mask($message),
        'details' => manifest_lock_mask($details),
    ];
}

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
        continue;
    }
}

$stage = manifest_lock_stage_normalize((string)$opts['stage']);
$env = (string)$opts['env'];
$runId = trim((string)$opts['run_id']);
$strict = (bool)$opts['strict'];
$writeLast = (bool)$opts['write_last'];

if ($stage === '' || !in_array($env, ['staging', 'production'], true)) {
    fwrite(STDERR, "Usage: php tools/qa/validate_stage_assets.php --stage=00..16 --env=staging|production [--run-id=...] [--write-last] [--strict=true]\n");
    exit(2);
}
if ($runId === '') {
    $runId = manifest_lock_generate_run_id($stage, $env);
}

$checks = [
    ['name' => 'required_files_exist', 'ok' => false, 'missing' => []],
    ['name' => 'required_http_whitelist_file', 'ok' => false],
    ['name' => 'required_contract_whitelist_file', 'ok' => false],
    ['name' => 'required_http_routes_covered', 'ok' => false, 'missing' => []],
    ['name' => 'required_contract_routes_covered', 'ok' => false, 'missing' => []],
    ['name' => 'required_env_present', 'ok' => false, 'missing_env' => []],
];
$fails = [];

$manifestRead = manifest_lock_read_manifest();
if (!$manifestRead['ok']) {
    $fails[] = mlock_fail_item('ERR_MANIFEST_INVALID', 'stage manifest invalid', (string)$manifestRead['error']);
}
$manifest = (array)($manifestRead['data'] ?? []);
$stageReqRead = manifest_lock_get_stage_requirements($manifest, $stage);
if (!$stageReqRead['ok']) {
    $fails[] = mlock_fail_item('ERR_MANIFEST_INVALID', 'stage requirements missing', (string)$stageReqRead['error']);
}
$requirements = (array)($stageReqRead['requirements'] ?? []);

// 1) Clean-room guard first.
$cleanRoomScript = manifest_lock_root() . '/tools/dev/verify_clean_room.php';
if (!is_file($cleanRoomScript)) {
    $fails[] = mlock_fail_item('ERR_CLEAN_ROOM_FAILED', 'clean-room verifier missing', manifest_lock_mask($cleanRoomScript));
} else {
    $cmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg($cleanRoomScript);
    $out = [];
    $code = 1;
    @exec($cmd . ' 2>&1', $out, $code);
    if ((int)$code !== 0) {
        $fails[] = mlock_fail_item('ERR_CLEAN_ROOM_FAILED', 'clean-room verification failed', implode(' | ', array_map('manifest_lock_mask', array_slice($out, -3))));
    }
}

// 2) Required files.
$requiredFiles = isset($requirements['required_files']) && is_array($requirements['required_files'])
    ? $requirements['required_files']
    : [];
$missingFiles = [];
foreach ($requiredFiles as $requiredPath) {
    if (!is_string($requiredPath)) {
        continue;
    }
    $check = manifest_lock_file_requirement_check($requiredPath);
    if (!$check['ok']) {
        $missingFiles[] = (string)$check['masked'];
    }
}
$checks[0]['ok'] = ($missingFiles === []);
$checks[0]['missing'] = $missingFiles;
if ($missingFiles !== []) {
    $fails[] = mlock_fail_item('ERR_REQUIRED_FILE_MISSING', 'required file missing', implode(', ', $missingFiles));
}

// 3) Whitelist files must exist and valid JSON.
$httpWhitelistFile = (string)($requirements['required_http_endpoints_file'] ?? '');
$contractWhitelistFile = (string)($requirements['required_contract_whitelist_file'] ?? '');
$httpWhitelistRead = manifest_lock_read_json_inside_root($httpWhitelistFile);
$contractWhitelistRead = manifest_lock_read_json_inside_root($contractWhitelistFile);
$checks[1]['ok'] = (bool)$httpWhitelistRead['ok'];
$checks[2]['ok'] = (bool)$contractWhitelistRead['ok'];
if (!$httpWhitelistRead['ok']) {
    $fails[] = mlock_fail_item(
        'ERR_WHITELIST_INVALID',
        'required HTTP whitelist missing/invalid',
        (string)($httpWhitelistRead['masked'] ?? '') . ' | ' . (string)$httpWhitelistRead['error']
    );
}
if (!$contractWhitelistRead['ok']) {
    $fails[] = mlock_fail_item(
        'ERR_WHITELIST_INVALID',
        'required contract whitelist missing/invalid',
        (string)($contractWhitelistRead['masked'] ?? '') . ' | ' . (string)$contractWhitelistRead['error']
    );
}

// 4) Route coverage.
$requiredHttpRoutes = manifest_lock_extract_required_routes($requirements, 'required_http_routes');
$requiredContractRoutes = manifest_lock_extract_required_routes($requirements, 'required_contract_routes');
$actualHttpRoutes = $httpWhitelistRead['ok'] ? manifest_lock_extract_route_set_from_whitelist((array)$httpWhitelistRead['data']) : [];
$actualContractRoutes = $contractWhitelistRead['ok'] ? manifest_lock_extract_route_set_from_whitelist((array)$contractWhitelistRead['data']) : [];

$missingHttpRoutes = manifest_lock_routes_missing($requiredHttpRoutes, $actualHttpRoutes);
$missingContractRoutes = manifest_lock_routes_missing($requiredContractRoutes, $actualContractRoutes);
$checks[3]['ok'] = ($missingHttpRoutes === []) && $httpWhitelistRead['ok'];
$checks[3]['missing'] = $missingHttpRoutes;
$checks[4]['ok'] = ($missingContractRoutes === []) && $contractWhitelistRead['ok'];
$checks[4]['missing'] = $missingContractRoutes;

if ($missingHttpRoutes !== []) {
    $fails[] = mlock_fail_item('ERR_ROUTE_NOT_COVERED', 'required HTTP route not in whitelist', implode(', ', $missingHttpRoutes));
}
if ($missingContractRoutes !== []) {
    $fails[] = mlock_fail_item('ERR_ROUTE_NOT_COVERED', 'required contract route not in whitelist', implode(', ', $missingContractRoutes));
}

// 5) Required environment variables.
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
    $value = getenv($envKey);
    if ($value === false || trim((string)$value) === '') {
        $missingEnv[] = $envKey;
    }
}
foreach ($requiredEnvEquals as $envKey => $expectedValue) {
    if (!is_string($envKey) || trim($envKey) === '') {
        continue;
    }
    $actual = getenv($envKey);
    $actualNorm = strtolower(trim((string)($actual === false ? '' : $actual)));
    $expectedNorm = strtolower(trim((string)$expectedValue));
    if ($actualNorm === '' || $actualNorm !== $expectedNorm) {
        $missingEnv[] = $envKey . '(expected=' . $expectedNorm . ')';
    }
}
$checks[5]['ok'] = ($missingEnv === []);
$checks[5]['missing_env'] = $missingEnv;
if ($missingEnv !== []) {
    $fails[] = mlock_fail_item('ERR_ENV_MISSING', 'required env missing', implode(', ', $missingEnv));
}

if ($strict && $checks[1]['ok'] === false) {
    $checks[3]['ok'] = false;
}
if ($strict && $checks[2]['ok'] === false) {
    $checks[4]['ok'] = false;
}

$overallOk = ($fails === []);
$payload = [
    'state_version' => 1,
    'run_id' => $runId,
    'env' => $env,
    'stage' => $stage,
    'generated_at' => manifest_lock_now_iso(),
    'overall_ok' => $overallOk,
    'fails' => $fails,
    'checks' => $checks,
];
$written = manifest_lock_write_evidence($payload, $writeLast);
$payload['evidence'] = $written;

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
