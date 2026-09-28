<?php
declare(strict_types=1);

function rmi_health_base_structure(): array
{
    return [
        'db' => ['ok' => null, 'latency_ms' => null],
        'storage' => [
            'logs_writable' => null,
            'backups_writable' => null,
            'uploads_writable' => null,
        ],
        'cron' => [
            'ok' => null,
            'last_run_at' => null,
            'next_run_at' => null,
        ],
        'worker_queue' => [
            'ok' => null,
            'pending_jobs' => null,
            'failed_jobs' => null,
            'last_heartbeat_at' => null,
        ],
        'latest_backup' => [
            'name' => null,
            'created_at' => null,
            'age_minutes' => null,
        ],
    ];
}

function rmi_health_merge(array $base, array $override): array
{
    foreach ($override as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
            $base[$k] = rmi_health_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

