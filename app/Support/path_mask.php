<?php
declare(strict_types=1);

require_once __DIR__ . '/../Bootstrap/app_root.php';

if (!function_exists('app_mask_path')) {
    function app_mask_path(string $text): string
    {
        $out = str_replace('\\', '/', $text);
        $root = str_replace('\\', '/', APP_ROOT);
        if ($root !== '') {
            $out = str_replace($root, '[APP_ROOT]', $out);
        }
        return $out;
    }
}

if (!function_exists('app_mask_sensitive')) {
    function app_mask_sensitive(string $text): string
    {
        $out = app_mask_path($text);
        $out = preg_replace('/(--password=)([^\s]+)/i', '$1[REDACTED]', $out) ?? $out;
        $out = preg_replace('/\b(password|pass|db_pass|db_password|token|secret)\s*[:=]\s*([^\s,]+)/i', '$1=[REDACTED]', $out) ?? $out;
        return $out;
    }
}
