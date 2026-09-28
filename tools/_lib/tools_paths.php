<?php
/**
 * Tools Path Helpers — app root, lock read, assert (NAS workdir lock).
 * Dipakai oleh semua CLI runner/doctor.
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/tools_path_redact.php';

// Load .env early so TOOLS_BASE_URL etc available to CLI
$__env = dirname(__DIR__, 2) . '/_shared/env.php';
if (is_file($__env)) {
    require_once $__env;
    if (function_exists('rmi_env_load')) {
        rmi_env_load();
    }
}

if (!function_exists('tools_app_root')) {
    function tools_app_root(): string
    {
        return realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
    }
}


if (!function_exists('tools_read_app_root_lock')) {
    function tools_read_app_root_lock(): array
    {
        $root = tools_app_root();
        $path = $root . '/storage/logs/app_root_lock.json';
        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'error' => 'APP_ROOT_LOCK_MISSING'];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'APP_ROOT_LOCK_UNREADABLE'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['app_root'])) {
            return ['ok' => false, 'error' => 'APP_ROOT_LOCK_INVALID', 'data' => $data];
        }
        return ['ok' => true, 'data' => $data];
    }
}

if (!function_exists('tools_assert_app_root_locked_cli')) {
    function tools_assert_app_root_locked_cli(): void
    {
        $root = tools_app_root();
        $logsDir = $root . '/storage/logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0775, true);
        }
        $lastPath = $logsDir . '/assumptions_last.json';

        $res = tools_read_app_root_lock();
        if (!$res['ok']) {
            $payload = [
                'code' => $res['error'] ?? 'APP_ROOT_LOCK_MISSING',
                'hint' => 'Run php tools/dev/lock_app_root.php from /volume4/web/ERP_RMI_SOFULL',
                'generated_at' => date('c'),
            ];
            tools_safe_json_file_put_contents($lastPath, $payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            echo "FAIL: " . ($payload['code']) . " — " . ($payload['hint']) . "\n";
            exit(1);
        }

        $locked = (string)($res['data']['app_root'] ?? '');
        if ($root !== $locked) {
            $payload = [
                'code' => 'APP_ROOT_MISMATCH',
                'expected' => tools_redact_mac_mount_paths_in_string($locked),
                'actual' => tools_redact_mac_mount_paths_in_string($root),
                'hint' => 'cd ' . $locked . ' && php tools/dev/lock_app_root.php',
                'generated_at' => date('c'),
            ];
            tools_safe_json_file_put_contents($lastPath, $payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            echo "FAIL: APP_ROOT_MISMATCH\n";
            echo "  Expected: {$locked}\n";
            echo "  Actual:   {$root}\n";
            exit(2);
        }
    }
}
