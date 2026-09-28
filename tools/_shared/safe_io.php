<?php
/**
 * safe_io.php — Safe write helpers dengan path guard (block /Volumes).
 *
 * Semua write wajib lewat helper ini agar path forbidden terdeteksi.
 * Whitelist: [APP_ROOT]/storage/ atau [APP_ROOT]/tmp/
 */
declare(strict_types=1);

if (!file_exists(__DIR__ . '/workspace_lock.php')) {
    throw new RuntimeException('workspace_lock.php required');
}
require_once __DIR__ . '/workspace_lock.php';

if (!function_exists('tools_safe_write_allowed')) {
    /**
     * True jika path berada di storage/ atau tmp/ dalam APP_ROOT.
     */
    function tools_safe_write_allowed(string $absPath): bool
    {
        $root = tools_get_app_root();
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $absPath);
        $storagePrefix = $root . '/storage/';
        $tmpPrefix = $root . '/tmp/';
        return str_starts_with($path, $storagePrefix) || str_starts_with($path, $tmpPrefix);
    }
}

if (!function_exists('tools_safe_write_file')) {
    /**
     * Write file dengan guard path + atomic (tulis ke .tmp lalu rename).
     *
     * @param array{context?:string} $opts
     */
    function tools_safe_write_file(string $absPath, string $content, array $opts = []): bool
    {
        $context = (string)($opts['context'] ?? 'write_file');
        tools_guard_forbidden_path($absPath, $context);
        if (!tools_safe_write_allowed($absPath)) {
            throw new RuntimeException('Path not in storage/ or tmp/: ' . $context);
        }
        $dir = dirname($absPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $tmpPath = $absPath . '.tmp.' . getmypid();
        if (@file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            @unlink($tmpPath);
            return false;
        }
        $ok = @rename($tmpPath, $absPath);
        if (!$ok) {
            @unlink($tmpPath);
        } else {
            // Ensure file is readable by web server (http user)
            @chmod($absPath, 0644);
        }
        return $ok;
    }
}

if (!function_exists('tools_safe_write_json')) {
    /**
     * Write JSON dengan atomic + guard.
     */
    function tools_safe_write_json(string $absPath, array $arr): bool
    {
        if (!array_key_exists('state_version', $arr)) {
            $arr['state_version'] = 1;
        }
        $content = json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return tools_safe_write_file($absPath, $content, ['context' => 'write_json']);
    }
}

if (!function_exists('tools_safe_append_jsonl')) {
    /**
     * Append line ke JSONL dengan guard + masking meta.
     */
    function tools_safe_append_jsonl(string $absPath, array $lineArr): bool
    {
        tools_guard_forbidden_path($absPath, 'append_jsonl');
        if (!tools_safe_write_allowed($absPath)) {
            throw new RuntimeException('Path not in storage/ or tmp/: append_jsonl');
        }
        $dir = dirname($absPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $line = json_encode($lineArr, JSON_UNESCAPED_SLASHES) . "\n";
        return @file_put_contents($absPath, $line, FILE_APPEND | LOCK_EX) !== false;
    }
}
