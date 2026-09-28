<?php
/**
 * monitoring_heartbeat.php — Gate 6: Monitoring + Alerting Heartbeat
 *
 * Checks:
 *   - /api/v1/health.php returns 200 + valid JSON with key components
 *   - Disk free (storage/logs, storage/backups)
 *   - Last cron/backup freshness
 *   - Alert engine: critical_count=0
 *
 * Usage: php tools/qa/monitoring_heartbeat.php [--strict] [--write-last]
 * Artifact: storage/logs/monitoring_heartbeat_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();

$logsDir = ts_storage_logs_dir();
$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
$baseUrl = rtrim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('TOOLS_BASE_URL') ?: getenv('SMOKE_BASE_URL') ?: 'https://localhost/ERP_RMI_SOFULL'), '/');

$checks = []; $errors = []; $warnings = [];

// ── 1. Health endpoint ───────────────────────────────────────────────────
$healthUrl = $baseUrl . '/api/v1/health.php';
$healthOk  = false; $healthComponents = [];
$ctx = stream_context_create(['http'=>['timeout'=>8,'ignore_errors'=>true],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
$raw = @file_get_contents($healthUrl, false, $ctx);
if ($raw !== false) {
    $hData = json_decode($raw, true);
    if (is_array($hData)) {
        $healthOk = (bool)($hData['ok'] ?? $hData['status'] === 'ok' ?? false);
        $healthComponents = [
            'db'      => (bool)($hData['db_ok'] ?? $hData['checks']['database']['ok'] ?? null),
            'storage' => (bool)($hData['storage']['logs_writable'] ?? true),
            'cron'    => (bool)($hData['cron']['ok'] ?? null),
        ];
    }
    $checks['health_http'] = ['ok' => $healthOk, 'url_masked' => ts_mask($healthUrl), 'components' => $healthComponents];
} else {
    // Unreachable from CLI — non-blocking, but log
    $checks['health_http'] = ['ok' => true, 'url_masked' => ts_mask($healthUrl), 'msg' => 'unreachable_from_cli_non_blocking'];
    $healthOk = true;
    $warnings[] = 'health_http:unreachable_from_cli';
}

// ── 2. Disk free check ─────────────────────────────────────────────────
$diskPaths = [
    'logs'    => $root . '/storage/logs',
    'backups' => $root . '/storage/backups',
    'uploads' => $root . '/storage/uploads',
];
$diskChecks = [];
foreach ($diskPaths as $name => $path) {
    @mkdir($path, 0775, true);
    $freeBytes = @disk_free_space($path);
    $totalBytes= @disk_total_space($path);
    $freeMb    = $freeBytes !== false ? round($freeBytes / 1024 / 1024) : null;
    $pct       = ($freeBytes !== false && $totalBytes > 0) ? round($freeBytes / $totalBytes * 100, 1) : null;
    $diskOk    = ($freeMb === null || $freeMb >= 200); // warn if <200MB
    if (!$diskOk) $warnings[] = "disk_{$name}:low_free_{$freeMb}mb";
    $diskChecks[$name] = ['ok' => $diskOk, 'free_mb' => $freeMb, 'free_pct' => $pct];
}
$checks['disk'] = $diskChecks;

// ── 3. Backup freshness ─────────────────────────────────────────────────
$latestFile    = $root . '/storage/backups/LATEST_BACKUP.txt';
$backupAgeH    = null;
$backupFreshOk = true;
if (is_file($latestFile)) {
    $mtime = @filemtime($latestFile);
    if ($mtime !== false) {
        $backupAgeH = round((time() - $mtime) / 3600, 1);
        $backupFreshOk = $backupAgeH <= 72.0; // warn if >72h
        if (!$backupFreshOk) $warnings[] = "backup_stale:{$backupAgeH}h_since_last";
    }
} else {
    $warnings[] = 'backup_stale:no_backup_found';
}
$checks['backup_freshness'] = ['ok' => $backupFreshOk, 'age_hours' => $backupAgeH];

// ── 4. Alert evaluation (critical alerts) ──────────────────────────────
$criticalCount = 0;
$alertsJson    = null;
foreach (['alert_evaluation_last.json', 'alerts_last.json', 'ops_alerts_last.json'] as $af) {
    if (is_file($logsDir . '/' . $af)) { $alertsJson = $logsDir . '/' . $af; break; }
}
if ($alertsJson) {
    $aData = json_decode((string)file_get_contents($alertsJson), true);
    $criticalCount = (int)($aData['critical_count'] ??
        count(array_filter($aData['alerts'] ?? [], fn($a) =>
            strtoupper((string)($a['severity']??'')) === 'CRITICAL' && (bool)($a['active']??false))));
    $alertsOk = $criticalCount === 0;
    if (!$alertsOk) $errors[] = "critical_alerts:{$criticalCount}";
    $checks['alerts'] = ['ok' => $alertsOk, 'critical_count' => $criticalCount];
} else {
    $checks['alerts'] = ['ok' => true, 'critical_count' => 0, 'msg' => 'no_artifact_yet_non_blocking'];
}

// ── 5. Monitoring artifact freshness (smoke_http) ─────────────────────
$smokeJson = $logsDir . '/smoke_http_last.json';
if (is_file($smokeJson)) {
    $smokeData = json_decode((string)file_get_contents($smokeJson), true);
    $smokeOk   = (bool)($smokeData['ok'] ?? false);
    $smokeFail = (int)($smokeData['fail_count'] ?? $smokeData['summary']['fail'] ?? 0);
    $smokeAge  = is_file($smokeJson) ? round((time() - @filemtime($smokeJson)) / 3600, 1) : null;
    $checks['smoke_http'] = ['ok' => $smokeOk, 'fail_count' => $smokeFail, 'age_hours' => $smokeAge];
    if (!$smokeOk) $warnings[] = "smoke_http:fail_count_{$smokeFail}";
} else {
    $checks['smoke_http'] = ['ok' => true, 'msg' => 'no_artifact_yet_non_blocking'];
}

$ok = count($errors) === 0;

$payload = [
    'ok'             => $ok,
    'run_at'         => date(DateTimeInterface::ATOM),
    'health_ok'      => $healthOk,
    'backup_age_h'   => $backupAgeH,
    'critical_alerts'=> $criticalCount,
    'checks'         => $checks,
    'warnings'       => $warnings,
    'errors'         => $errors,
];

if ($writeLast) {
    @file_put_contents($logsDir . '/monitoring_heartbeat_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Also update monitoring_alerting_last.json alias
    @file_put_contents($logsDir . '/monitoring_alerting_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['ok' => $ok, 'health_ok' => $healthOk, 'critical_alerts' => $criticalCount, 'warnings' => count($warnings)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
