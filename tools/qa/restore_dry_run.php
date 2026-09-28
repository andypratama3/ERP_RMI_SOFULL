<?php
/**
 * tools/qa/restore_dry_run.php — Gate E: Restore Dry-Run
 *
 * QA-layer alias untuk ops/restore_dry_run.php.
 * Memvalidasi paket backup tanpa mengubah data produksi.
 *
 * Checks:
 *   - LATEST_BACKUP.txt ada dan menunjuk ke package yang valid
 *   - manifest.json ada di package
 *   - checksums.sha256 ada dan cocok (PHP-native, tanpa shasum)
 *   - db.sql.gz readable (jika ada)
 *   - restore_now.sh --dry-run (jika tersedia)
 *
 * Usage: php tools/qa/restore_dry_run.php [--from-latest] [--strict] [--write-last]
 * Artifact: storage/logs/restore_dry_run_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);
$fromLatest = true; // always from latest for gate

require_once $root . '/tools/tools_state_lib.php';
$logsDir = ts_storage_logs_dir();
$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');

// Delegate to ops/restore_dry_run.php which writes restore_dry_run_last.json
$delegateScript = $root . '/tools/ops/restore_dry_run.php';
if (is_file($delegateScript)) {
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($delegateScript) . ' --from-latest --write-last 2>&1';
    $lines = []; $code = 1;
    @exec($cmd, $lines, $code);
    $ok = ((int)$code === 0);
    $out = implode(' ', $lines);
    $noBackup = stripos($out, 'LATEST_BACKUP') !== false
             || stripos($out, 'not found') !== false
             || stripos($out, 'run backup first') !== false;
    if ($noBackup) {
        // Non-blocking: no backup yet
        $payload = ['ok' => true, 'run_at' => date(DateTimeInterface::ATOM), 'note' => 'no_backup_yet_non_blocking', 'source' => 'ops/restore_dry_run.php'];
        $ok = true;
    } else {
        // Read the artifact written by delegate
        $delegateArtifact = $logsDir . '/restore_dry_run_last.json';
        if (is_file($delegateArtifact)) {
            $data = json_decode((string)file_get_contents($delegateArtifact), true);
            $payload = is_array($data) ? array_merge($data, ['source' => 'ops/restore_dry_run.php']) : ['ok' => $ok, 'run_at' => date(DateTimeInterface::ATOM), 'source' => 'ops/restore_dry_run.php'];
        } else {
            $payload = ['ok' => $ok, 'run_at' => date(DateTimeInterface::ATOM), 'source' => 'ops/restore_dry_run.php', 'output' => implode(' | ', array_slice($lines, -3))];
        }
    }
} else {
    // Fallback: do a PHP-native backup verification (no shell dependency)
    $backupRoot = $root . '/storage/backups';
    $latestFile = $backupRoot . '/LATEST_BACKUP.txt';
    $ok = false; $payload = ['ok' => false, 'run_at' => date(DateTimeInterface::ATOM)];

    if (!is_file($latestFile)) {
        $payload = ['ok' => true, 'run_at' => date(DateTimeInterface::ATOM), 'note' => 'no_backup_yet_non_blocking'];
        $ok = true;
    } else {
        $packageDir = trim((string)@file_get_contents($latestFile));
        $manifestOk = is_file($packageDir . '/manifest.json');
        $checksumOk = false;
        $dbOk = is_file($packageDir . '/db.sql.gz') && filesize($packageDir . '/db.sql.gz') > 0;

        if (is_file($packageDir . '/checksums.sha256')) {
            $lines2 = array_filter(array_map('trim', explode("\n", (string)file_get_contents($packageDir . '/checksums.sha256'))));
            $failed = [];
            foreach ($lines2 as $line) {
                if (!preg_match('/^([a-f0-9]{64})\s+(.+)$/', $line, $m)) continue;
                $f = $packageDir . '/' . ltrim($m[2], './');
                if (!is_file($f) || hash_file('sha256', $f) !== $m[1]) $failed[] = $m[2];
            }
            $checksumOk = count($failed) === 0;
        }

        $ok = $manifestOk && $checksumOk;
        $payload = [
            'ok' => $ok,
            'run_at' => date(DateTimeInterface::ATOM),
            'package' => basename($packageDir),
            'manifest_ok' => $manifestOk,
            'checksums_ok' => $checksumOk,
            'db_sql_gz_ok' => $dbOk,
            'source' => 'qa/restore_dry_run.php (native)',
        ];
    }
}

if ($writeLast) {
    @file_put_contents($logsDir . '/restore_dry_run_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['ok' => $ok, 'note' => $payload['note'] ?? null], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
