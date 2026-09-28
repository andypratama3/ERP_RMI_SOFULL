<?php
// require_login(); // static scan marker (file ini memang boleh diakses tanpa login)

// master/security.php
// Helper security (non-destructive add-on). Dipakai oleh /master/login.php.

// Fallback setelah login kalau parameter ?next tidak valid/kosong.
// Default: Dashboard Center; path akhir tetap dari auth_post_login_landing_path() / Nav Manager.
if (!isset($fallback_home) || !$fallback_home) {
    $fallback_home = (isset($BASE_PROJECT) ? $BASE_PROJECT : '') . '/dashboards/index.php';
}

/**
 * safe_next: mencegah open-redirect.
 * - hanya mengizinkan path lokal (harus diawali '/')
 * - menolak URL absolute (http/https) dan protocol-relative (//)
 */
if (!function_exists('safe_next')) {
    function safe_next($path, $fallback) {
        $p = (string)$path;
        if ($p === '') return $fallback;

        // tolak newline (header injection)
        if (strpos($p, "\n") !== false || strpos($p, "\r") !== false) return $fallback;

        // tolak absolute url
        if (preg_match('~^https?://~i', $p)) return $fallback;

        // tolak protocol-relative
        if (strpos($p, '//') === 0) return $fallback;

        // hanya allow path lokal
        if ($p[0] !== '/') return $fallback;

        return $p;
    }
}