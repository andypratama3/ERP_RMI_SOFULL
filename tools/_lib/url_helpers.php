<?php
/**
 * URL helpers — deterministic base URL normalize + join.
 * Hilangkan double slash, scheme://host/path (no trailing slash).
 */
declare(strict_types=1);

if (!function_exists('tools_normalize_base_url')) {
    /**
     * Normalize base URL: trim, scheme://host/path, no trailing slash.
     * Collapse multiple slashes in PATH only (preserve http://).
     */
    function tools_normalize_base_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        $url = preg_replace('#(https?://)/+#', '$1', $url);
        $url = preg_replace('#(?<!:)//+#', '/', $url);
        return rtrim($url, '/');
    }
}

if (!function_exists('tools_url_join')) {
    /**
     * Join base + path with exactly 1 slash. No // in path (except after scheme).
     * Prefer rmi_url_join when url_utils loaded (asserts no double-slash).
     */
    function tools_url_join(string $base, string $path): string
    {
        $utils = dirname(__DIR__) . '/_shared/url_utils.php';
        if (is_file($utils) && !function_exists('rmi_url_join')) {
            require_once $utils;
        }
        if (function_exists('rmi_url_join')) {
            $p = '/' . ltrim($path, '/');
            return rmi_url_join($base, $p);
        }
        $base = tools_normalize_base_url($base);
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path);
        return $base . $path;
    }
}
