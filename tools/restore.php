<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden — CLI only.\n";
    exit(1);
}

$authFile = __DIR__ . '/../master/auth.php';
$auditFile = __DIR__ . '/../_shared/erp_audit.php';
if (is_file($authFile)) require_once $authFile;
if (is_file($auditFile)) require_once $auditFile;

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$restoreScript = $root . '/tools/restore_now.sh';
$backupRoot = $root . '/storage/backups';

$args = $argv;
array_shift($args);
$from = '';
$confirm = '';
$restoreDb = false;
$restoreFiles = false;
$dryRun = false;
$iUnderstand = false;

foreach ($args as $a) {
    if (str_starts_with($a, '--from=')) {
        $from = substr($a, 7);
    } elseif ($a === '--restore-db') {
        $restoreDb = true;
    } elseif ($a === '--restore-files') {
        $restoreFiles = true;
    } elseif ($a === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($a, '--confirm=')) {
        $confirm = strtoupper(trim(substr($a, 10)));
    } elseif ($a === '--i-understand') {
        $iUnderstand = true;
    }
}

if ($from === '') {
    fwrite(STDERR, "Usage: php tools/restore.php --from=<backup_package|path> [--restore-db] [--restore-files] [--dry-run] --confirm=RESTORE\n");
    exit(1);
}

if (!$dryRun && $confirm !== 'RESTORE') {
    fwrite(STDERR, "Blocked: typed confirmation missing. Use --confirm=RESTORE\n");
    exit(1);
}
$appEnv = strtolower((string)(getenv('APP_ENV') ?: 'local'));
if (!$dryRun && in_array($appEnv, ['prod', 'production'], true) && !$iUnderstand) {
    fwrite(STDERR, "Blocked in production: add --i-understand for apply mode.\n");
    exit(2);
}

if (!$restoreDb && !$restoreFiles) {
    $restoreDb = true;
    $restoreFiles = true;
}

$candidate = $from;
if (!str_starts_with($candidate, '/')) {
    $candidate = $backupRoot . '/' . ltrim($candidate, '/');
}
$real = realpath($candidate);
if ($real === false || !is_dir($real)) {
    fwrite(STDERR, "Backup package not found: {$from}\n");
    exit(1);
}

$cmd = ['/bin/bash', $restoreScript, '--from', $real];
if ($restoreDb) $cmd[] = '--restore-db';
if ($restoreFiles) $cmd[] = '--restore-files';
$cmd[] = $dryRun ? '--dry-run' : '--apply';

$escaped = implode(' ', array_map('escapeshellarg', $cmd));
passthru($escaped, $code);
try {
    if (function_exists('rmi_db_pdo')) {
        $pdo = rmi_db_pdo();
        erp_audit_ensure($pdo);
        audit_event($pdo, 'RESTORE_CLI_' . ($dryRun ? 'DRY_RUN' : 'APPLY'), 'TOOLS_BACKUP', 'RESTORE', basename($real), 'CLI restore invocation', [
            'actor_username' => getenv('USER') ?: (function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM'),
            'status' => ((int)$code === 0 ? 'ok' : 'failed'),
            'restore_db' => $restoreDb,
            'restore_files' => $restoreFiles,
            'env' => $appEnv,
        ]);
    }
} catch (Throwable $e) {
    // no-op
}
exit((int)$code);

