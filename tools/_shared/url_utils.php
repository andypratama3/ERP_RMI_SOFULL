<?php
/**
 * url_utils.php — URL normalization & safe join utilities.
 *
 * Prevents double-slash paths (e.g. https://host//path) which cause 301
 * false-mismatches in smoke/RBAC tests.
 *
 * Usage:
 *   require_once __DIR__ . '/../_shared/url_utils.php';
 *   $url = rmi_url_join('https://10.10.60.20/ERP_RMI_SOFULL', '/purchases/po.php');
 *   // → https://10.10.60.20/ERP_RMI_SOFULL/purchases/po.php
 *
 * NAS base: /volume4/web/ERP_RMI_SOFULL
 */
declare(strict_types=1);

// Load _lib/url_helpers.php if available (for tools_url_join compatibility)
$_rmi_url_helpers = __DIR__ . '/../_lib/url_helpers.php';
if (is_file($_rmi_url_helpers) && !function_exists('tools_url_join')) {
    require_once $_rmi_url_helpers;
}
unset($_rmi_url_helpers);

if (!function_exists('rmi_normalize_url_slashes')) {
    /**
     * Normalize all slashes in a complete URL:
     *   - Fix double slashes AFTER protocol (https:////host → https://host)
     *   - Fix double slashes in path component (/foo//bar → /foo/bar)
     *   - Preserve "https://" and "http://" exactly (single double-slash after colon)
     *
     * rmi_normalize_url_slashes('https://host//path//to.php') → 'https://host/path/to.php'
     * rmi_normalize_url_slashes('http://host')               → 'http://host'
     */
    function rmi_normalize_url_slashes(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        // Step 1: Normalize extra slashes right after protocol colon
        //   https:////host → https://host
        $url = (string)preg_replace('#(https?:)//{2,}#', '$1//', $url);
        // Step 2: Fix double slashes in path (anything after host)
        //   Regex: match // NOT preceded by colon (so https:// stays)
        $url = (string)preg_replace('#([^:])/{2,}#', '$1/', $url);
        return $url;
    }
}

if (!function_exists('rmi_normalize_base_url')) {
    /**
     * Normalize a base URL: strip trailing slash, fix protocol double-slashes.
     * Throws if URL is empty or not http(s)://.
     */
    function rmi_normalize_base_url(string $url): string {
        $url = trim($url);
        if ($url === '') throw new \InvalidArgumentException('URL is empty');
        // Fix proto double-slash: https:////host → https://host
        $url = (string)preg_replace('#(https?:)//{2,}#', '$1//', $url);
        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('URL must start http(s)://: ' . substr($url, 0, 60));
        }
        $url = rtrim($url, '/');
        // Collapse duplicate /ERP_RMI_SOFULL in path (bad env / copy-paste)
        $url = (string)preg_replace('#(/ERP_RMI_SOFULL){2,}(?=/|$)#i', '/ERP_RMI_SOFULL', $url);

        return $url;
    }
}

if (!function_exists('rmi_url_join')) {
    /**
     * Join base URL + path segment, preventing double slashes.
     *
     * rmi_url_join('https://host/app', '/foo/bar.php') → 'https://host/app/foo/bar.php'
     * rmi_url_join('https://host/app/', '//foo/')      → 'https://host/app/foo/'
     */
    function rmi_url_join(string $baseUrl, string $path): string {
        $base = rmi_normalize_base_url($baseUrl);
        // Ensure single leading slash on path, collapse double slashes
        $path = '/' . ltrim($path, '/');
        $path = (string)preg_replace('#/{2,}#', '/', $path);
        $url  = rmi_normalize_url_slashes($base . $path);
        // Hard assertion: throw if result still has double slash
        if (rmi_url_has_double_slash_path($url)) {
            throw new \RuntimeException(
                'rmi_url_join produced double slash: base=' . substr($base, 0, 60)
                . ' path=' . substr($path, 0, 60) . ' result=' . substr($url, 0, 120)
            );
        }
        return $url;
    }
}

if (!function_exists('rmi_canonical_url_join')) {
    /**
     * Canonical join for gate tools: normalize base, strip duplicate /ERP_RMI_SOFULL from path
     * when base already ends with .../ERP_RMI_SOFULL (avoids 301 / wrong path).
     *
     * @param string $path Must start with "/"
     */
    function rmi_canonical_url_join(string $baseUrl, string $path): string {
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException('rmi_canonical_url_join: path must start with /');
        }
        $base = rmi_normalize_base_url($baseUrl);
        $path = '/' . ltrim($path, '/');
        $path = (string)preg_replace('#/{2,}#', '/', $path);
        if (preg_match('#/ERP_RMI_SOFULL$#i', $base) && preg_match('#^/ERP_RMI_SOFULL(?=/|$)#i', $path)) {
            $path = (string)preg_replace('#^/ERP_RMI_SOFULL#i', '', $path);
            $path = ($path === '' || $path[0] !== '/') ? ('/' . ltrim($path, '/')) : $path;
        }

        return rmi_url_join($base, $path);
    }
}

if (!function_exists('rmi_url_has_double_slash_path')) {
    /**
     * Returns true if URL has double slash in path component.
     * Does NOT flag "https://" protocol part.
     *
     * rmi_url_has_double_slash_path('https://host//path') → true  (BAD)
     * rmi_url_has_double_slash_path('https://host/path')  → false (OK)
     */
    function rmi_url_has_double_slash_path(string $url): bool {
        // Remove protocol (https:// or http://)
        $withoutProto = (string)preg_replace('#^https?://#i', '', $url);
        return str_contains($withoutProto, '//');
    }
}

if (!function_exists('rmi_assert_url_clean')) {
    /**
     * Assert URL has no double slash in path. Throw on violation.
     * Use in gate tools to catch join errors early.
     *
     * @throws \RuntimeException
     */
    function rmi_assert_url_clean(string $url, string $ctx = ''): void {
        if (rmi_url_has_double_slash_path($url)) {
            $ctxStr = $ctx !== '' ? " [{$ctx}]" : '';
            throw new \RuntimeException("Double slash in URL path{$ctxStr}: " . substr($url, 0, 150));
        }
    }
}

if (!function_exists('rmi_tools_base_url')) {
    /**
     * Canonical base URL resolver for tools.
     * Priority: TOOLS_BASE_URL_INTERNAL > TOOLS_BASE_URL > SMOKE_BASE_URL > APP_URL
     * Fallback: https://localhost/ERP_RMI_SOFULL
     */
    function rmi_tools_base_url(string $layer = 'internal'): string {
        if ($layer === 'public') {
            $candidates = [
                getenv('TOOLS_BASE_URL_PUBLIC'),
                getenv('PUBLIC_BASE_URL'),
                getenv('APP_PUBLIC_URL'),
            ];
        } else {
            $candidates = [
                getenv('TOOLS_BASE_URL_INTERNAL'),
                getenv('TOOLS_BASE_URL'),
                getenv('SMOKE_BASE_URL'),
                getenv('APP_URL'),
            ];
        }
        foreach ($candidates as $c) {
            $c = trim((string)($c ?: ''));
            if ($c !== '' && preg_match('#^https?://#i', $c)) {
                try { return rmi_normalize_base_url($c); } catch (\Throwable $e) {}
            }
        }
        return 'https://localhost/ERP_RMI_SOFULL';
    }
}
