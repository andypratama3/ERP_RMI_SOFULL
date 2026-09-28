<?php
declare(strict_types=1);

/**
 * Backup/Restore Gate — QA verification for cutover.
 *
 * 1. Trigger backup (or use latest existing if recent)
 * 2. Verify manifest + checksums (PHP native, no shasum dependency)
 * 3. Restore dry-run: parse manifest, validate SQL dump, no apply
 *
 * FAIL if any step fails.
 * Output: storage/logs/backup_restore_gate_last.json
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/_shared/app_root_guard.php';
tools_assert_expected_app_root();
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);
$strict = in_array('--strict', $args, true);
$useExistingOnly = in_array('--use-existing', $args, true);
$forceNew = in_array('--force-new', $args, true);
$maxAgeHours = 24;
foreach ($args as $a) {
    if (is_string($a) && str_starts_with($a, '--max-age-hours=')) {
        $v = (int)trim(substr($a, 16));
        if ($v > 0) $maxAgeHours = $v;
        break;
    }
}

$backupRoot = $root . '/storage/backups';
$logsDir = ts_storage_logs_dir();
$result = [
    'ok' => false,
    'run_at' => date(DateTimeInterface::ATOM),
    'steps' => [],
    'package' => null,
    'error' => null,
];

function add_step(array &$result, string $name, bool $ok, ?string $error = null): void {
    $result['steps'][$name] = [
        'ok' => $ok,
        'error' => $error,
    ];
}

// Step 1: Obtain backup package
$packageDir = null;
$latestFile = $backupRoot . '/LATEST_BACKUP.txt';
if (!$forceNew && is_file($latestFile)) {
    $latest = trim((string)@file_get_contents($latestFile));
    if ($latest !== '' && is_dir($latest)) {
        $mtime = @filemtime($latest);
        if ($mtime !== false) {
            $ageHours = (time() - $mtime) / 3600;
            if ($ageHours <= $maxAgeHours) {
                $manifestPath = $latest . '/manifest.json';
                $dbPath = $latest . '/db.sql.gz';
                if (is_file($manifestPath) && is_file($dbPath)) {
                    $packageDir = $latest;
                    add_step($result, 'obtain_backup', true, null);
                    $result['steps']['obtain_backup']['source'] = 'existing';
                    $result['steps']['obtain_backup']['age_hours'] = round($ageHours, 2);
                }
            }
        }
    }
}

if ($packageDir === null && !$useExistingOnly) {
    // Run backup
    $backupScript = $root . '/tools/backup_now.sh';
    if (!is_file($backupScript) || !is_executable($backupScript)) {
        add_step($result, 'obtain_backup', false, 'backup_now.sh not found or not executable');
        $result['error'] = 'obtain_backup_failed';
        goto output;
    }
    $cmd = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($backupScript) . ' --label gate 2>&1';
    $lines = [];
    $code = 1;
    @exec($cmd, $lines, $code);
    if ($code !== 0) {
        add_step($result, 'obtain_backup', false, 'backup_now.sh exit=' . $code . ': ' . implode(' ', array_slice($lines, -3)));
        $result['error'] = 'obtain_backup_failed';
        goto output;
    }
    $latest = trim((string)@file_get_contents($latestFile));
    if ($latest !== '' && is_dir($latest)) {
        $packageDir = $latest;
        add_step($result, 'obtain_backup', true, null);
        $result['steps']['obtain_backup']['source'] = 'new';
    } else {
        add_step($result, 'obtain_backup', false, 'LATEST_BACKUP.txt empty or dir missing');
        $result['error'] = 'obtain_backup_failed';
        goto output;
    }
}

if ($packageDir === null && $useExistingOnly) {
    add_step($result, 'obtain_backup', false, 'no recent backup with db.sql.gz; use --force-new to create');
    $result['error'] = 'obtain_backup_failed';
    goto output;
}

$result['package'] = basename($packageDir);

// Step 2: Verify manifest
$manifestPath = $packageDir . '/manifest.json';
$manifestRaw = @file_get_contents($manifestPath);
if ($manifestRaw === false || $manifestRaw === '') {
    add_step($result, 'verify_manifest', false, 'manifest.json missing or empty');
    $result['error'] = 'verify_manifest_failed';
    goto output;
}
$manifest = json_decode($manifestRaw, true);
if (!is_array($manifest)) {
    add_step($result, 'verify_manifest', false, 'manifest.json invalid JSON');
    $result['error'] = 'verify_manifest_failed';
    goto output;
}
$requiredKeys = ['app', 'package', 'created_at', 'files_zip', 'files_sha256'];
foreach ($requiredKeys as $k) {
    if (!array_key_exists($k, $manifest)) {
        add_step($result, 'verify_manifest', false, 'manifest missing key: ' . $k);
        $result['error'] = 'verify_manifest_failed';
        goto output;
    }
}
add_step($result, 'verify_manifest', true, null);

// Step 3: Verify checksums (PHP native)
$checksumsPath = $packageDir . '/checksums.sha256';
$checksumsRaw = @file_get_contents($checksumsPath);
if ($checksumsRaw === false || $checksumsRaw === '') {
    add_step($result, 'verify_checksums', false, 'checksums.sha256 missing or empty');
    $result['error'] = 'verify_checksums_failed';
    goto output;
}
$checksumOk = true;
$checksumErr = null;
foreach (explode("\n", $checksumsRaw) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    if (preg_match('/^([a-f0-9]{64})\s+(\S+)$/i', $line, $m)) {
        $expected = strtolower($m[1]);
        $file = $m[2];
        $filePath = $packageDir . '/' . $file;
        if (!is_file($filePath)) {
            $checksumOk = false;
            $checksumErr = 'file missing: ' . $file;
            break;
        }
        $actual = hash_file('sha256', $filePath);
        if ($actual === false || strtolower($actual) !== $expected) {
            $checksumOk = false;
            $checksumErr = 'checksum mismatch: ' . $file;
            break;
        }
    }
}
if (!$checksumOk) {
    add_step($result, 'verify_checksums', false, $checksumErr);
    $result['error'] = 'verify_checksums_failed';
    goto output;
}
add_step($result, 'verify_checksums', true, null);

// Step 4: Validate SQL dump (decompress + header)
$dbPath = $packageDir . '/db.sql.gz';
if (is_file($dbPath)) {
    $fp = @gzopen($dbPath, 'rb');
    if ($fp === false) {
        add_step($result, 'validate_sql_dump', false, 'gzip open failed');
        $result['error'] = 'validate_sql_dump_failed';
        goto output;
    }
    $header = '';
    $len = 0;
    while (!gzeof($fp) && $len < 512) {
        $chunk = gzread($fp, 256);
        if ($chunk === false) break;
        $header .= $chunk;
        $len += strlen($chunk);
    }
    gzclose($fp);
    $valid = (
        stripos($header, '-- MySQL dump') !== false ||
        stripos($header, '-- MariaDB dump') !== false ||
        stripos($header, '/*!') !== false
    );
    if (!$valid) {
        add_step($result, 'validate_sql_dump', false, 'SQL dump header invalid (missing MySQL/MariaDB signature)');
        $result['error'] = 'validate_sql_dump_failed';
        goto output;
    }
    $size = filesize($dbPath);
    add_step($result, 'validate_sql_dump', true, null);
    $result['steps']['validate_sql_dump']['size_bytes'] = $size;
} else {
    add_step($result, 'validate_sql_dump', false, 'db.sql.gz missing');
    $result['error'] = 'validate_sql_dump_failed';
    goto output;
}

// Step 5: Restore dry-run (script validates and exits without apply)
$restoreScript = $root . '/tools/restore_now.sh';
if (is_file($restoreScript) && is_executable($restoreScript)) {
    $cmd = escapeshellarg($restoreScript) . ' --from ' . escapeshellarg($packageDir) . ' --dry-run --restore-db --restore-files 2>&1';
    $lines = [];
    $code = 1;
    @exec($cmd, $lines, $code);
    if ($code !== 0) {
        add_step($result, 'restore_dry_run', false, 'restore_now.sh exit=' . $code . ': ' . implode(' ', array_slice($lines, -3)));
        $result['error'] = 'restore_dry_run_failed';
        goto output;
    }
    add_step($result, 'restore_dry_run', true, null);
} else {
    add_step($result, 'restore_dry_run', false, 'restore_now.sh not found or not executable');
    $result['error'] = 'restore_dry_run_failed';
    goto output;
}

$result['ok'] = true;
$result['error'] = null;

output:
if ($writeLast) {
    $outPath = $logsDir . '/backup_restore_gate_last.json';
    @file_put_contents($outPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

if ($result['ok']) {
    echo "OK backup_restore_gate\n";
    exit(0);
}
if ($strict) {
    fwrite(STDERR, "FAIL backup_restore_gate: " . ($result['error'] ?? 'unknown') . "\n");
    exit(1);
}
exit(1);
