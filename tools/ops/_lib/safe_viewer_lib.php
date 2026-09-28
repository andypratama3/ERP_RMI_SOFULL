<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_ui_helpers.php';

if (!function_exists('svl_pipeline_dir')) {
    function svl_pipeline_dir(): string
    {
        $root = tools_app_root();
        return $root . '/storage/logs/pipeline';
    }
}

if (!function_exists('svl_deny_patterns')) {
    function svl_deny_patterns(): array
    {
        return ['/Users/', 'C:\\', 'BEGIN PRIVATE KEY', 'Authorization:', 'DB_PASS'];
    }
}

if (!function_exists('svl_has_deny_pattern')) {
    function svl_has_deny_pattern(string $content): bool
    {
        foreach (svl_deny_patterns() as $p) {
            if (str_contains($content, $p)) return true;
        }
        return false;
    }
}

if (!function_exists('svl_safe_read_file')) {
    /**
     * Safe file read with allowlist. No traversal.
     * @param string $baseDir Base directory (e.g. storage/logs/pipeline)
     * @param string $fileName Exact filename (e.g. executive_ops_summary_last.html)
     * @param array $allowedNames Allowed filenames (exact match)
     * @param array $allowedExts Allowed extensions (e.g. ['json','html','md'])
     * @param int $maxSize Max file size bytes (default 2MB)
     * @return array{ok:bool, content:string, error_code:string}
     */
    function svl_safe_read_file(string $baseDir, string $fileName, array $allowedNames, array $allowedExts, int $maxSize = 2097152): array
    {
        $fileName = basename($fileName);
        if (!in_array($fileName, $allowedNames, true)) {
            return ['ok' => false, 'content' => '', 'error_code' => 'FILE_NOT_ALLOWED'];
        }
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            return ['ok' => false, 'content' => '', 'error_code' => 'EXT_NOT_ALLOWED'];
        }
        $base = realpath($baseDir);
        if ($base === false || !is_dir($base)) {
            return ['ok' => false, 'content' => '', 'error_code' => 'BASE_DIR_MISSING'];
        }
        $path = $base . '/' . $fileName;
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            return ['ok' => false, 'content' => '', 'error_code' => 'FILE_NOT_FOUND'];
        }
        if (!str_starts_with($resolved, $base)) {
            return ['ok' => false, 'content' => '', 'error_code' => 'PATH_TRAVERSAL'];
        }
        $size = (int)@filesize($resolved);
        if ($size > $maxSize) {
            return ['ok' => false, 'content' => '', 'error_code' => 'FILE_TOO_LARGE'];
        }
        $content = (string)@file_get_contents($resolved);
        return ['ok' => true, 'content' => $content, 'error_code' => ''];
    }
}

if (!function_exists('svl_mask_recursive')) {
    function svl_mask_recursive(mixed $v): mixed
    {
        if (is_string($v)) {
            return tools_mask_sensitive($v);
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $val) {
                $out[$k] = svl_mask_recursive($val);
            }
            return $out;
        }
        return $v;
    }
}
