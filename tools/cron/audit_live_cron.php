<?php
/**
 * audit_live_cron.php — Cron entry runner for Live Audit.
 * Run: php tools/cron/audit_live_cron.php
 * Cron (every 15 min): php /volume4/web/ERP_RMI_SOFULL/tools/cron/audit_live_cron.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$real = str_replace('\\', '/', (string)realpath($root));
$forbidMount = '/' . 'Volumes' . '/';
if (strpos($real, $forbidMount) !== false) {
    fwrite(STDERR, "CRITICAL: Run from NAS only (/volume4/...).\n");
    exit(2);
}

$lockDir = $root . '/storage/locks';
$lockFile = $lockDir . '/audit_live.lock';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lockFp = @fopen($lockFile, 'c');
if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    echo date('c') . " SKIP: audit_live already running\n";
    exit(0);
}

$phpBin = (string)(getenv('ERP_PHP_BIN') ?: 'php');
$script = $root . '/tools/qa/audit_live.php';
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --env=production --mode=cron --write-last --strict 2>&1';
$out = [];
$code = 1;
@exec($cmd, $out, $code);

if ($lockFp) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

echo date('c') . ' audit_live_cron exit=' . $code . "\n";
exit($code);
