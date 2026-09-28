<?php
declare(strict_types=1);

if (!function_exists('bpa_root')) {
    function bpa_root(): string
    {
        return function_exists('ts_root') ? ts_root() : (realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
    }
}

if (!function_exists('bpa_allowed_base_dirs')) {
    function bpa_allowed_base_dirs(): array
    {
        $root = bpa_root();
        return [
            $root . '/storage/logs',
            $root . '/storage/logs/pipeline',
            $root . '/tools/qa',
            $root . '/app',
            $root . '/modules',
            $root . '/api',
            $root . '/tools',
        ];
    }
}

if (!function_exists('bpa_forbidden_dirs')) {
    function bpa_forbidden_dirs(): array
    {
        $root = bpa_root();
        return [
            $root . '/storage/uploads',
            $root . '/storage/backups',
            $root . '/vendor',
            $root . '/node_modules',
        ];
    }
}

if (!function_exists('bpa_is_path_allowed')) {
    function bpa_is_path_allowed(string $resolvedPath, string $purpose = 'read'): bool
    {
        $root = bpa_root();
        $resolved = str_replace('\\', '/', realpath($resolvedPath) ?: $resolvedPath);
        if ($resolved === '') return false;
        foreach (bpa_forbidden_dirs() as $forbidden) {
            $fb = str_replace('\\', '/', $forbidden);
            if (str_starts_with($resolved, $fb . '/') || $resolved === $fb) {
                return false;
            }
        }
        foreach (bpa_allowed_base_dirs() as $allowed) {
            $al = str_replace('\\', '/', realpath($allowed) ?: $allowed);
            if ($al !== '' && (str_starts_with($resolved, $al . '/') || $resolved === $al)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('bpa_resolve_safe')) {
    /**
     * Resolve path and ensure inside allowed base. Returns ['ok'=>bool, 'path'=>string].
     */
    function bpa_resolve_safe(string $path, string $purpose = 'read'): array
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            return ['ok' => false, 'path' => ''];
        }
        if (!bpa_is_path_allowed($resolved, $purpose)) {
            return ['ok' => false, 'path' => ''];
        }
        return ['ok' => true, 'path' => $resolved];
    }
}

if (!function_exists('bpa_source_snippet_allowed_ext')) {
    function bpa_source_snippet_allowed_ext(): array
    {
        return ['php'];
    }
}

if (!function_exists('bpa_source_snippet_allowed_dirs')) {
    function bpa_source_snippet_allowed_dirs(): array
    {
        $root = bpa_root();
        return [
            $root . '/app',
            $root . '/modules',
            $root . '/api',
            $root . '/tools',
        ];
    }
}

if (!function_exists('bpa_is_source_file_allowed')) {
    function bpa_is_source_file_allowed(string $resolvedPath): bool
    {
        $ext = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));
        if (!in_array($ext, bpa_source_snippet_allowed_ext(), true)) {
            return false;
        }
        foreach (bpa_source_snippet_allowed_dirs() as $dir) {
            $dirReal = realpath($dir);
            if ($dirReal !== false && str_starts_with($resolvedPath, $dirReal)) {
                return true;
            }
        }
        return false;
    }
}
