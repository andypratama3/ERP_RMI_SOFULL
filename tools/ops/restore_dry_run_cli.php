<?php
/**
 * restore_dry_run_cli.php — CLI alias for restore_dry_run.php.
 *
 * Usage: php tools/ops/restore_dry_run_cli.php --from-latest --write-last
 * Artifact: storage/logs/restore_dry_run_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$target = $root . '/tools/ops/restore_dry_run.php';

if (!is_file($target)) {
    fwrite(STDERR, "FAIL: restore_dry_run.php not found\n");
    exit(1);
}

// Pass through all args except script name
$fwd = array_slice($_SERVER['argv'] ?? [], 1);
$phpBin = PHP_BINARY ?: 'php';
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($target) . ' ' . implode(' ', array_map('escapeshellarg', $fwd));
passthru($cmd, $code);
exit((int)$code);
