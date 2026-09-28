<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/app_init.php';
require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/../../_shared/health_readiness.php';

use App\Api\ApiResponse;

try {
    $root = dirname(__DIR__, 2);
    $appEnv = defined('APP_ENV') ? (string)APP_ENV : (string)(getenv('APP_ENV') ?: 'local');
    $appVersion = defined('APP_VERSION') ? (string)APP_VERSION : 'dev';
    $tz = date_default_timezone_get() ?: 'UTC';

    $pdo = rmi_db_pdo();
    $t0 = microtime(true);
    $dbOk = (bool)$pdo->query('SELECT 1')->fetchColumn();
    $dbLatency = (int)round((microtime(true) - $t0) * 1000);

    $checkWritable = static function (string $path): bool {
        return is_dir($path) && is_writable($path);
    };
    $storage = [
        'logs_writable' => $checkWritable($root . '/storage/logs'),
        'backups_writable' => $checkWritable($root . '/storage/backups'),
        'uploads_writable' => $checkWritable($root . '/storage/uploads'),
    ];

    $cron = [
        'ok' => false,
        'last_run_at' => null,
        'next_run_at' => null,
    ];
    $stateFile = $root . '/storage/backups/autobackup_schedule_2300.state';
    if (is_file($stateFile)) {
        $enabled = false;
        $lastRunAt = null;
        foreach ((file($stateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            [$k, $v] = array_pad(explode('=', (string)$line, 2), 2, '');
            $k = trim($k);
            $v = trim($v);
            if ($k === 'enabled') $enabled = ($v === '1');
            if ($k === 'last_run_at' && $v !== '') $lastRunAt = $v;
        }
        $cron['ok'] = $enabled;
        $cron['last_run_at'] = $lastRunAt;
        if ($enabled) {
            $zone = new DateTimeZone('Asia/Jakarta');
            $now = new DateTimeImmutable('now', $zone);
            $next = $now->setTime(23, 0, 0);
            if ($next <= $now) $next = $next->modify('+1 day');
            $cron['next_run_at'] = $next->format('Y-m-d H:i:s T');
        }
    }

    $worker = ['ok' => null, 'pending_jobs' => null, 'failed_jobs' => null, 'last_heartbeat_at' => null];
    try {
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE UPPER(status) IN ('PENDING','RETRY')")->fetchColumn();
        $failed = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE UPPER(status) IN ('FAILED','ERROR')")->fetchColumn();
        $hb = null;
        try {
            $hbRaw = $pdo->query("SELECT MAX(updated_at) FROM jobs")->fetchColumn();
            $hb = $hbRaw !== false ? (string)$hbRaw : null;
        } catch (Throwable $e) {
            $hb = null;
        }
        $worker = ['ok' => true, 'pending_jobs' => $pending, 'failed_jobs' => $failed, 'last_heartbeat_at' => $hb];
    } catch (Throwable $e) {
        $worker = ['ok' => null, 'pending_jobs' => null, 'failed_jobs' => null, 'last_heartbeat_at' => null];
    }

    $latestBackup = ['name' => null, 'created_at' => null, 'age_minutes' => null];
    $dirs = glob($root . '/storage/backups/ERP_RMI_SOFULL_backup_*', GLOB_ONLYDIR) ?: [];
    rsort($dirs, SORT_STRING);
    if ($dirs) {
        $latest = $dirs[0];
        $ts = @filemtime($latest) ?: 0;
        if ($ts > 0) {
            $latestBackup = [
                'name' => basename($latest),
                'created_at' => gmdate('c', $ts),
                'age_minutes' => (int)floor((time() - $ts) / 60),
            ];
        }
    }

    $payload = rmi_health_merge(rmi_health_base_structure(), [
        'db' => ['ok' => $dbOk, 'latency_ms' => $dbLatency],
        'storage' => $storage,
        'cron' => $cron,
        'worker_queue' => $worker,
        'latest_backup' => $latestBackup,
    ]);

    ApiResponse::ok([
        'app_env' => $appEnv,
        'version' => $appVersion,
        'time' => gmdate('c'),
        'timezone' => $tz,
        'db' => $payload['db'],
        'storage' => $payload['storage'],
        'cron' => $payload['cron'],
        'worker_queue' => $payload['worker_queue'],
        'latest_backup' => $payload['latest_backup'],
    ]);
} catch (Throwable $e) {
    $base = rmi_health_base_structure();
    ApiResponse::ok([
        'app_env' => defined('APP_ENV') ? (string)APP_ENV : 'unknown',
        'version' => defined('APP_VERSION') ? (string)APP_VERSION : 'dev',
        'time' => gmdate('c'),
        'timezone' => date_default_timezone_get() ?: 'UTC',
        'db' => ['ok' => false, 'latency_ms' => null],
        'storage' => $base['storage'],
        'cron' => $base['cron'],
        'worker_queue' => $base['worker_queue'],
        'latest_backup' => $base['latest_backup'],
    ]);
}
