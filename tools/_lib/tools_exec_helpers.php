<?php
declare(strict_types=1);

// Pastikan APP_ROOT terdefinisi (tools_exec dipanggil setelah bootstrap)
if (!defined('APP_ROOT')) {
    define('APP_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}
require_once __DIR__ . '/../tools_ui_helpers.php';

if (!function_exists('tools_run_step_once')) {
    function tools_run_step_once(string $cmd, int $timeoutSec = 120): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $start = microtime(true);
        $process = @proc_open($cmd, $descriptor, $pipes, APP_ROOT);
        if (!is_resource($process)) {
            return [
                'ok' => false,
                'exit_code' => 127,
                'stdout_masked' => '',
                'stderr_masked' => tools_mask_sensitive('failed to spawn process'),
                'elapsed_ms' => (int)round((microtime(true) - $start) * 1000),
                'timeout' => false,
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $timeout = false;
        $deadline = microtime(true) + max(1, $timeoutSec);

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timeout = true;
                @proc_terminate($process, 15);
                usleep(150000);
                $statusAfter = proc_get_status($process);
                if (!empty($statusAfter['running'])) {
                    @proc_terminate($process, 9);
                }
                break;
            }
            usleep(100000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = (int)proc_close($process);
        if ($timeout) {
            $exitCode = 124;
        }

        return [
            'ok' => !$timeout && $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout_masked' => tools_mask_sensitive($stdout),
            'stderr_masked' => tools_mask_sensitive($stderr),
            'elapsed_ms' => (int)round((microtime(true) - $start) * 1000),
            'timeout' => $timeout,
        ];
    }
}

if (!function_exists('tools_run_step')) {
    function tools_run_step(string $cmd, int $timeout = 120, array $retryPolicy = []): array
    {
        $idempotent = (bool)($retryPolicy['idempotent'] ?? false);
        $maxRetry = (int)($retryPolicy['max_retry'] ?? 0);
        if (!$idempotent) {
            $maxRetry = 0;
        }
        $attempts = max(1, $maxRetry + 1);
        $last = [];
        for ($i = 1; $i <= $attempts; $i++) {
            $last = tools_run_step_once($cmd, $timeout);
            $last['attempt'] = $i;
            $last['attempts'] = $attempts;
            $last['attempts_used'] = $i;
            if (!empty($last['ok'])) {
                return $last;
            }
            if ($i < $attempts) {
                usleep(250000);
            }
        }
        return $last + ['ok' => false, 'attempts' => $attempts, 'attempts_used' => $attempts];
    }
}
