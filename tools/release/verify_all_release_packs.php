<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ERR: PHP Zip extension required. Synology: Web Station → PHP 8.4 → Edit → centang zip\n");
    exit(1);
}

require_once __DIR__ . '/_lib/release_verify_lib.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../../_shared/bootstrap.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';

function rav_mask(string $v): string { return rv_mask($v); }

function rav_write_json(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function rav_storage_writable_check(string $root): array
{
    $paths = [
        $root . '/storage/logs',
        $root . '/storage/state',
        $root . '/storage/locks',
    ];
    $errs = [];
    foreach ($paths as $p) {
        if (!is_dir($p) && !@mkdir($p, 0775, true) && !is_dir($p)) {
            $errs[] = 'ERR_STORAGE_DIR:' . rav_mask($p);
            continue;
        }
        $probe = $p . '/.release_verify_probe';
        if (@file_put_contents($probe, 'ok') === false) $errs[] = 'ERR_STORAGE_NOT_WRITABLE:' . rav_mask($p);
        else @unlink($probe);
    }
    return $errs;
}

function rav_load_registry_bundles(string $registryPath): array
{
    $read = rn_read_json($registryPath);
    if (!$read['ok']) return ['ok' => false, 'bundles' => [], 'error' => 'ERR_REGISTRY_MISSING_OR_INVALID'];
    $d = (array)$read['data'];
    $rows = [];
    foreach ((array)($d['bundles'] ?? []) as $b) {
        if (!is_array($b)) continue;
        $rows[] = $b;
    }
    if ($rows === []) return ['ok' => false, 'bundles' => [], 'error' => 'ERR_REGISTRY_SCHEMA'];
    return ['ok' => true, 'bundles' => $rows, 'error' => ''];
}

function rav_scan_bundles_from_exports(string $exportsDir, int $limit = 0): array
{
    $zips = glob($exportsDir . '/release_pack_*.zip') ?: [];
    sort($zips);
    if ($limit > 0) $zips = array_slice($zips, 0, $limit);
    $bundles = [];
    foreach ($zips as $zip) {
        $base = basename($zip, '.zip');
        $manifest = $exportsDir . '/' . $base . '_manifest.json';
        $key = str_replace('release_pack_', '', $base);
        $bundles[] = [
            'bundle_key' => $key,
            'packs' => [
                [
                    'zip' => $zip,
                    'manifest' => $manifest,
                ],
            ],
        ];
    }
    return $bundles;
}

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$mode = 'quick';
$writeLast = true;
$strictGiven = false;
$strict = false;
$limitBundles = 0;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--write-last') $writeLast = true;
    elseif ($arg === '--strict') { $strict = true; $strictGiven = true; }
    elseif (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--mode=')) $mode = strtolower(trim((string)substr($arg, 7)));
    elseif (str_starts_with($arg, '--limit-bundles=')) $limitBundles = max(0, (int)trim((string)substr($arg, 16)));
}
if (!in_array($env, ['staging', 'production'], true)) $env = 'staging';
if (!in_array($mode, ['quick', 'full'], true)) $mode = 'quick';
if (!$strictGiven) $strict = ($env === 'production');

$root = rv_root();
$requestId = 'release-verify-all-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
$lockPath = $root . '/storage/locks/release_verify_all.lock';
$errors = [];
$notes = [];
$warnings = [];
$criticalStop = false;

// Precheck clean room guard
$cleanCmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg($root . '/tools/dev/verify_clean_room.php');
$cleanOut = [];
$cleanCode = 1;
@exec($cleanCmd . ' 2>&1', $cleanOut, $cleanCode);
if ($cleanCode !== 0) {
    $errors[] = 'ERR_CLEAN_ROOM_GUARD_FAILED';
    $criticalStop = true;
}

$errors = array_merge($errors, rav_storage_writable_check($root));

// Policy precheck (strict validation command from previous stage)
$policyPath = $root . '/docs/governance/RELEASE_RETENTION.yaml';
$policyCmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg($root . '/tools/release/validate_release_retention_policy.php') . ' --strict';
$policyOut = [];
$policyCode = 1;
if (is_file($root . '/tools/release/validate_release_retention_policy.php')) {
    @exec($policyCmd . ' 2>&1', $policyOut, $policyCode);
    if ($policyCode !== 0) {
        if ($strict) $errors[] = 'ERR_POLICY_INVALID';
        else $warnings[] = ['code' => 'WARN_POLICY_INVALID', 'message' => rav_mask(implode(' | ', array_slice($policyOut, -2)))];
    }
} else {
    if ($strict) $errors[] = 'ERR_POLICY_VALIDATOR_MISSING';
    else $warnings[] = ['code' => 'WARN_POLICY_VALIDATOR_MISSING', 'message' => rav_mask($root . '/tools/release/validate_release_retention_policy.php')];
}
if (!is_file($policyPath)) {
    if ($strict) $errors[] = 'ERR_POLICY_MISSING';
    else $warnings[] = ['code' => 'WARN_POLICY_MISSING', 'message' => rav_mask($policyPath)];
}

// lock precheck — remove stale lock if creator process is dead
if (is_file($lockPath)) {
    $lockData = rn_read_json($lockPath);
    $lockRow = (array)($lockData['data'] ?? []);
    $lockTs = strtotime((string)($lockRow['ts'] ?? ''));
    $age = $lockTs !== false ? (time() - $lockTs) : 0;
    $pid = isset($lockRow['pid']) ? (int)$lockRow['pid'] : 0;
    $stale = false;
    if ($age >= 3600) {
        $stale = true; // lock older than 1h
    } elseif ($pid > 0 && function_exists('posix_kill') && !@posix_kill($pid, 0)) {
        $stale = true; // creator process no longer running
    }
    if ($stale) {
        @unlink($lockPath);
    } elseif ($age < 3600) {
        $errors[] = 'ERR_LOCKED';
        $criticalStop = true;
    }
}
if (!$criticalStop) {
    rav_write_json($lockPath, ['state_version' => 1, 'ts' => date(DateTimeInterface::ATOM), 'pid' => getmypid(), 'request_id' => $requestId]);
}

$bundlesIn = [];
if (!$criticalStop) {
    $registryPath = $root . '/storage/state/release_registry_last.json';
    $reg = rav_load_registry_bundles($registryPath);
    if (!$reg['ok']) {
        if ($strict) $errors[] = (string)$reg['error'];
        $warnings[] = ['code' => 'WARN_REGISTRY_FALLBACK_SCAN', 'message' => rav_mask('run scan_release_exports.php --write-last')];
        $bundlesIn = rav_scan_bundles_from_exports($root . '/storage/exports/release', $limitBundles);
    } else {
        $bundlesIn = (array)$reg['bundles'];
    }
    if ($limitBundles > 0) $bundlesIn = array_slice($bundlesIn, 0, $limitBundles);
}

$bundleResults = [];
$packTotal = 0; $packOk = 0; $packWarn = 0; $packFail = 0;
foreach ($bundlesIn as $bundle) {
    $bundleKey = (string)($bundle['bundle_key'] ?? $bundle['key'] ?? ('bundle_' . (count($bundleResults) + 1)));
    $packs = [];
    if (isset($bundle['packs']) && is_array($bundle['packs'])) {
        foreach ($bundle['packs'] as $p) {
            if (!is_array($p)) continue;
            $zip = (string)($p['zip'] ?? '');
            $manifest = (string)($p['manifest'] ?? '');
            if ($zip !== '' && !str_starts_with($zip, '/')) $zip = $root . '/' . ltrim($zip, '/');
            if ($manifest !== '' && !str_starts_with($manifest, '/')) $manifest = $root . '/' . ltrim($manifest, '/');
            $packs[] = ['zip' => $zip, 'manifest' => $manifest];
        }
    }
    if ($packs === []) {
        $k = (string)($bundle['bundle_key'] ?? '');
        $candidate = glob($root . '/storage/exports/release/release_pack_' . $k . '.zip') ?: [];
        foreach ($candidate as $zip) {
            $manifest = substr($zip, 0, -4) . '_manifest.json';
            $packs[] = ['zip' => $zip, 'manifest' => $manifest];
        }
    }
    if ($packs === []) {
        $bundleResults[] = [
            'bundle_key' => $bundleKey,
            'status' => 'FAIL',
            'packs' => [],
            'notes' => ['no_pack_found_for_bundle'],
        ];
        $packFail++;
        continue;
    }

    $packResults = [];
    $bFail = 0; $bWarn = 0; $bOk = 0;
    foreach ($packs as $p) {
        $packTotal++;
        $zipAbs = (string)$p['zip'];
        $manifestAbs = (string)$p['manifest'];
        $quickPolicy = ['max_zip_bytes' => rp_max_bytes(), 'allow_missing_manifest_in_quick' => true];
        $packRes = $mode === 'full'
            ? verify_pack_full($zipAbs, $manifestAbs, ['max_zip_bytes' => rp_max_bytes()])
            : verify_pack_quick($zipAbs, $manifestAbs, $quickPolicy);
        $status = (string)($packRes['status'] ?? 'FAIL');
        if ($status === 'FAIL') { $packFail++; $bFail++; }
        elseif ($status === 'WARN') { $packWarn++; $bWarn++; }
        else { $packOk++; $bOk++; }
        $packResults[] = [
            'rel_path_zip' => ltrim(str_replace($root, '', $zipAbs), '/'),
            'rel_path_manifest' => ltrim(str_replace($root, '', $manifestAbs), '/'),
            'status' => $status,
            'checks' => (array)($packRes['checks'] ?? []),
            'warnings' => (array)($packRes['warnings'] ?? []),
            'fails' => (array)($packRes['fails'] ?? []),
        ];
    }
    $bundleStatus = $bFail > 0 ? 'FAIL' : ($bWarn > 0 ? 'WARN' : 'OK');
    $bundleResults[] = [
        'bundle_key' => $bundleKey,
        'status' => $bundleStatus,
        'packs' => $packResults,
    ];
}

$bundleTotal = count($bundleResults);
$bundleOk = 0; $bundleWarn = 0; $bundleFail = 0;
foreach ($bundleResults as $b) {
    $st = (string)($b['status'] ?? 'FAIL');
    if ($st === 'OK') $bundleOk++;
    elseif ($st === 'WARN') $bundleWarn++;
    else $bundleFail++;
}
$overallOk = ($bundleFail === 0 && $errors === []);
if ($errors !== []) $overallOk = false;
$resultLevel = $bundleFail > 0 || $errors !== [] ? 'FAIL' : ($bundleWarn > 0 || $warnings !== [] ? 'WARN' : 'OK');

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'env' => $env,
    'mode' => $mode,
    'strict' => $strict,
    'overall_ok' => $overallOk,
    'summary' => [
        'bundle_total' => $bundleTotal,
        'bundle_ok' => $bundleOk,
        'bundle_warn' => $bundleWarn,
        'bundle_fail' => $bundleFail,
        'pack_total' => $packTotal,
        'pack_ok' => $packOk,
        'pack_warn' => $packWarn,
        'pack_fail' => $packFail,
    ],
    'bundles' => $bundleResults,
    'notes' => array_values(array_unique(array_map(static fn(string $v): string => rav_mask($v), $notes))),
    'warnings' => $warnings,
    'errors_masked' => array_values(array_unique(array_map(static fn(string $v): string => rav_mask($v), $errors))),
];

$pipelineDir = $root . '/storage/logs/pipeline';
if (!is_dir($pipelineDir)) @mkdir($pipelineDir, 0775, true);
$statePath = $root . '/storage/state/release_verify_all_last.json';
$runBase = 'release_verify_all_' . date('YmdHis');
$jsonRunPath = $pipelineDir . '/' . $runBase . '.json';
$mdRunPath = $pipelineDir . '/' . $runBase . '.md';
$jsonLast = $pipelineDir . '/release_verify_all_last.json';
$mdLast = $pipelineDir . '/release_verify_all_last.md';
rav_write_json($statePath, $payload);
rav_write_json($jsonRunPath, $payload);

$md = [];
$md[] = '# Release Verify All';
$md[] = '';
$md[] = '- env/mode/strict: ' . rav_mask($env) . ' / ' . rav_mask($mode) . ' / ' . ($strict ? 'true' : 'false');
$md[] = '- generated_at: ' . rav_mask((string)$payload['generated_at']);
$md[] = '- overall_ok: ' . ($overallOk ? 'true' : 'false');
$md[] = '';
$md[] = '| bundle_key | status | packs_ok/warn/fail | notes |';
$md[] = '|---|---|---:|---|';
foreach ($bundleResults as $b) {
    $okC = 0; $wC = 0; $fC = 0;
    foreach ((array)($b['packs'] ?? []) as $p) {
        $s = (string)($p['status'] ?? 'FAIL');
        if ($s === 'OK') $okC++; elseif ($s === 'WARN') $wC++; else $fC++;
    }
    $md[] = '| ' . rav_mask((string)$b['bundle_key']) . ' | ' . rav_mask((string)$b['status']) . ' | ' . $okC . '/' . $wC . '/' . $fC . ' | - |';
}
$md[] = '';
$md[] = '## WARN/FAIL Detail';
foreach ($bundleResults as $b) {
    foreach ((array)($b['packs'] ?? []) as $p) {
        if (in_array((string)$p['status'], ['WARN', 'FAIL'], true)) {
            $md[] = '- bundle=' . rav_mask((string)$b['bundle_key']) . ' pack=' . rav_mask((string)$p['rel_path_zip']) . ' status=' . rav_mask((string)$p['status']);
            foreach ((array)$p['fails'] as $f) {
                $md[] = '  - FAIL ' . rav_mask((string)($f['code'] ?? '')) . ' :: ' . rav_mask((string)($f['message'] ?? '')) . ' -> Re-run verify_release_pack --strict';
            }
            foreach ((array)$p['warnings'] as $w) {
                $md[] = '  - WARN ' . rav_mask((string)($w['code'] ?? '')) . ' :: ' . rav_mask((string)($w['message'] ?? '')) . ' -> Re-run generate_release_notes';
            }
        }
    }
}
foreach ((array)$warnings as $w) {
    $md[] = '- WARN ' . rav_mask((string)($w['code'] ?? '')) . ' :: ' . rav_mask((string)($w['message'] ?? '')) . ' -> Re-scan registry';
}
foreach ((array)$errors as $e) {
    $md[] = '- FAIL ' . rav_mask((string)$e) . ' -> Re-scan registry / policy validate';
}
$md[] = '';
$md[] = '## Commands';
$md[] = '- php tools/release/scan_release_exports.php --write-last';
$md[] = '- php tools/release/verify_all_release_packs.php --env=' . rav_mask($env) . ' --mode=full --write-last';
@file_put_contents($mdRunPath, implode("\n", $md) . "\n");

if ($writeLast) {
    rav_write_json($jsonLast, $payload);
    @file_put_contents($mdLast, implode("\n", $md) . "\n");
}

$logLine = json_encode([
    'ts' => date(DateTimeInterface::ATOM),
    'request_id' => $requestId,
    'env' => $env,
    'mode' => $mode,
    'strict' => $strict,
    'result' => $resultLevel,
    'summary' => $payload['summary'],
], JSON_UNESCAPED_SLASHES);
@file_put_contents($root . '/storage/logs/release_verify_all.log', $logLine . "\n", FILE_APPEND);
@file_put_contents($root . '/storage/logs/audit_release_verification.jsonl', json_encode([
    'ts' => date(DateTimeInterface::ATOM),
    'actor_username' => PHP_SAPI === 'cli' ? (getenv('CI_ACTOR') ?: 'SYSTEM') : (string)($_SESSION['username'] ?? 'SYSTEM'),
    'request_id' => $requestId,
    'action' => 'RELEASE_VERIFY_ALL',
    'env' => $env,
    'mode' => $mode,
    'overall_ok' => $overallOk,
    'summary' => $payload['summary'],
    'result' => $resultLevel,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

if (function_exists('ts_append_run_history')) {
    ts_append_run_history('release_verify_all', $resultLevel, [
        'module' => 'RELEASE',
        'action' => 'VERIFY_ALL',
        'result' => $resultLevel,
        'request_id' => $requestId,
        'mode' => $mode,
        'env' => $env,
        'bundle_fail_count' => $bundleFail,
    ]);
}

if (function_exists('auth_pdo') && function_exists('audit_event')) {
    $pdo = auth_pdo();
    if ($pdo instanceof PDO) {
        audit_event($pdo, 'RELEASE_VERIFY_ALL_RUN', 'RELEASE', 'release_verify_all', $requestId, 'Release verify all executed', [
            'request_id' => $requestId,
            'mode' => $mode,
            'env' => $env,
            'strict' => $strict,
            'overall_ok' => $overallOk,
            'summary' => $payload['summary'],
        ]);
    }
}

if (!$criticalStop) @unlink($lockPath);

echo json_encode([
    'state_version' => 1,
    'overall_ok' => $overallOk,
    'strict' => $strict,
    'env' => $env,
    'mode' => $mode,
    'result' => $resultLevel,
    'summary' => $payload['summary'],
    'artifacts' => [
        'state_last' => rav_mask($statePath),
        'json_last' => rav_mask($jsonLast),
        'md_last' => rav_mask($mdLast),
    ],
    'errors_masked' => $payload['errors_masked'],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

$exitCode = 0;
if ($criticalStop) $exitCode = 1;
elseif ($strict && (!$overallOk || $bundleFail > 0)) $exitCode = 1;
exit($exitCode);

