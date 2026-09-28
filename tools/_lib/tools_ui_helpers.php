<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$rootHelpers = defined('APP_ROOT') ? (APP_ROOT . '/tools/tools_ui_helpers.php') : (__DIR__ . '/../tools_ui_helpers.php');
if (is_file($rootHelpers)) {
    require_once $rootHelpers;
}

if (!function_exists('tools_json_read_safe')) {
    function tools_json_read_safe(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = (string)@file_get_contents($path);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('tools_json_write_atomic')) {
    function tools_json_write_atomic(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.tmp.' . getmypid() . '.' . substr(hash('sha256', (string)microtime(true)), 0, 8);
        $ok = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if ($ok === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}

if (!function_exists('tools_lock_dir')) {
    function tools_lock_dir(): string
    {
        $dir = APP_ROOT . '/storage/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('tools_lock_acquire')) {
    function tools_lock_acquire(string $name, int $ttl = 900): array
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($name)) ?: 'default';
        $path = tools_lock_dir() . '/' . $safe . '.lock.json';
        $now = time();
        $current = tools_json_read_safe($path);
        $lockedAt = (int)($current['locked_at_epoch'] ?? 0);
        if ($lockedAt > 0 && ($now - $lockedAt) <= $ttl) {
            return ['ok' => false, 'path' => $path, 'reason' => 'LOCKED'];
        }
        $payload = [
            'state_version' => 1,
            'name' => $safe,
            'pid' => getmypid(),
            'locked_at' => date(DateTimeInterface::ATOM),
            'locked_at_epoch' => $now,
            'ttl_seconds' => $ttl,
        ];
        $ok = tools_json_write_atomic($path, $payload);
        return ['ok' => $ok, 'path' => $path, 'reason' => $ok ? 'ACQUIRED' : 'WRITE_FAILED'];
    }
}

if (!function_exists('tools_lock_release')) {
    function tools_lock_release(string $name): bool
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($name)) ?: 'default';
        $path = tools_lock_dir() . '/' . $safe . '.lock.json';
        if (!is_file($path)) {
            return true;
        }
        return @unlink($path);
    }
}
