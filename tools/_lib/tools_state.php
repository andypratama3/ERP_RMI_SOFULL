<?php
/**
 * Tools State — safe JSON read/write, age, rotate.
 * Writes scrub Mac SMB mount tokens (base_path_guard).
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/tools_path_redact.php';

if (!defined('APP_ROOT')) {
    define('APP_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

if (!function_exists('tools_read_json_safe')) {
    function tools_read_json_safe(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'data' => null, 'error' => 'missing'];
        }
        $raw = (string)@file_get_contents($path);
        if ($raw === '') {
            return ['ok' => false, 'data' => null, 'error' => 'empty'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'data' => null, 'error' => 'invalid_json'];
        }
        return ['ok' => true, 'data' => $decoded, 'error' => ''];
    }
}

if (!function_exists('tools_write_json_atomic')) {
    function tools_write_json_atomic(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $clean = tools_deep_scrub_mount_paths_for_log($data);
        $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $json = tools_scrub_mount_token_in_string($json) . "\n";
        $tmp = $path . '.tmp.' . getmypid() . '.' . substr(hash('sha256', (string)microtime(true)), 0, 8);
        $ok = @file_put_contents($tmp, $json);
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

if (!function_exists('tools_state_age')) {
    function tools_state_age(string $path): ?int
    {
        if (!is_file($path)) return null;
        $mtime = (int)@filemtime($path);
        if ($mtime <= 0) return null;
        return max(0, time() - $mtime);
    }
}

if (!function_exists('tools_rotate_jsonl')) {
    function tools_rotate_jsonl(string $path, int $maxDays = 30, int $maxMB = 20): array
    {
        if (!is_file($path)) return ['ok' => true, 'dropped' => 0];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $threshold = time() - ($maxDays * 86400);
        $kept = [];
        $dropped = 0;
        foreach ($lines as $line) {
            $j = json_decode((string)$line, true);
            if (!is_array($j)) { $dropped++; continue; }
            $ts = isset($j['ts']) ? strtotime((string)$j['ts']) : (isset($j['time']) ? strtotime((string)$j['time']) : 0);
            if ($ts > 0 && $ts < $threshold) { $dropped++; continue; }
            $kept[] = $line;
        }
        $sizeMb = (int)@filesize($path) / 1024 / 1024;
        if ($sizeMb > $maxMB && !empty($kept)) {
            $maxBytes = $maxMB * 1024 * 1024;
            $selected = [];
            $bytes = 0;
            for ($i = count($kept) - 1; $i >= 0; $i--) {
                $line = (string)$kept[$i];
                if (($bytes + strlen($line) + 1) > $maxBytes) { $dropped++; continue; }
                array_unshift($selected, $line);
                $bytes += strlen($line) + 1;
            }
            $kept = $selected;
        }
        @file_put_contents($path, implode("\n", $kept) . (count($kept) ? "\n" : ''));
        return ['ok' => true, 'dropped' => $dropped];
    }
}
