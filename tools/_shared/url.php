<?php
/**
 * tools/_shared/url.php — Canonical URL helpers for gate/smoke/RBAC tools.
 *
 * Single entry for normalize + join (eliminates 301 from double slashes).
 * Implements FINAL GATE spec: normalize_base_url(), url_join().
 *
 * NAS canonical: /volume4/web/ERP_RMI_SOFULL
 * Typical bases (no trailing slash):
 *   internal: http://10.10.60.20/ERP_RMI_SOFULL
 *   public:   https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
 */
declare(strict_types=1);

require_once __DIR__ . '/url_utils.php';

if (!function_exists('normalize_base_url')) {
    /**
     * Strip trailing slash, collapse double slashes in path (not scheme).
     *
     * @throws InvalidArgumentException if empty or not http(s)
     */
    function normalize_base_url(string $url): string {
        return rmi_normalize_base_url($url);
    }
}

if (!function_exists('url_join')) {
    /**
     * Join base + path with exactly one slash; path must start with "/".
     * Uses canonical join (no double ERP_RMI_SOFULL segment, no // in path).
     *
     * @param string $path Must begin with "/" (e.g. "/master/login.php")
     */
    function url_join(string $base, string $path): string {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('url_join: path must start with /, got: ' . substr($path, 0, 40));
        }
        return rmi_canonical_url_join($base, $path);
    }
}

if (!function_exists('canonical_url_join')) {
    /** @see rmi_canonical_url_join */
    function canonical_url_join(string $base, string $path): string {
        return rmi_canonical_url_join($base, $path);
    }
}
