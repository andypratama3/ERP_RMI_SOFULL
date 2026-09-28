<?php
/**
 * workspace_lock.php — Hard lock: block Mac Vol+umes mount paths, enforce NAS-only.
 *
 * Tujuan: Agent/tools tidak boleh jalan dari Mac SMB mount (path Vol+umes) atau nulis ke path forbidden.
 * APP_ROOT wajib: /volume4/web/ERP_RMI_SOFULL (production).
 *
 * Usage:
 *   require_once 'tools/_shared/workspace_lock.php';
 *   tools_assert_workspace_lock();
 */
declare(strict_types=1);

if (!function_exists('tools_get_app_root')) {
    /**
     * Realpath root project dari file ini (jangan pakai cwd).
     */
    function tools_get_app_root(): string
    {
        $guardDir = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        return rtrim(str_replace('\\', '/', $guardDir), '/');
    }
}

if (!function_exists('tools_is_forbidden_path')) {
    /**
     * True jika path mengandung prefix mount Mac (Vol+umes+slash).
     */
    function tools_is_forbidden_path(string $path): bool
    {
        $p = str_replace('\\', '/', $path);
        $m = '/' . 'Volumes' . '/';
        if (strpos($p, $m) !== false) {
            return true;
        }
        if (preg_match('#' . preg_quote($m, '#') . '[^/]+#', $p)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('tools_guard_forbidden_path')) {
    /**
     * Kalau forbidden → throw Exception.
     *
     * @throws RuntimeException
     */
    function tools_guard_forbidden_path(string $path, string $context = 'unknown'): void
    {
        if (tools_is_forbidden_path($path)) {
            throw new RuntimeException(
                'FORBIDDEN_PATH: path contains Mac mount prefix (context: ' . $context . ')'
            );
        }
    }
}

if (!function_exists('tools_assert_workspace_lock')) {
    /**
     * Validasi APP_ROOT = /volume4/web/ERP_RMI_SOFULL saat production.
     * Baca storage/.app_root_lock bila ada sebagai source-of-truth.
     *
     * @return array{ok:bool, severity?:string, code?:string} Error object jika fail
     */
    function tools_assert_workspace_lock(): array
    {
        $root = tools_get_app_root();
        $expected = '/volume4/web/ERP_RMI_SOFULL';
        $expected = rtrim($expected, '/');
        $appEnv = strtolower((string)(getenv('APP_ENV') ?: 'local'));

        // Baca lock marker bila ada
        $lockFile = $root . '/storage/.app_root_lock';
        if (is_file($lockFile)) {
            $lockContent = trim((string)@file_get_contents($lockFile));
            if ($lockContent !== '') {
                $expected = rtrim(str_replace('\\', '/', $lockContent), '/');
            }
        }

        // WORKSPACE_LOCK_MISMATCH (CRITICAL) jika root != expected di prod
        if (in_array($appEnv, ['prod', 'production'], true)) {
            $rootReal = realpath($root) ?: $root;
            if ($rootReal !== $expected) {
                return [
                    'ok' => false,
                    'severity' => 'CRITICAL',
                    'code' => 'WORKSPACE_LOCK_MISMATCH',
                    'message' => 'APP_ROOT must be [APP_ROOT], got mismatch in production',
                ];
            }
        }

        // FORBIDDEN_PATH_DETECTED (CRITICAL) jika APP_ROOT mengandung prefix mount Mac
        if (tools_is_forbidden_path($root)) {
            return [
                'ok' => false,
                'severity' => 'CRITICAL',
                'code' => 'FORBIDDEN_PATH_DETECTED',
                'message' => 'APP_ROOT is Mac mount path — run from NAS only',
            ];
        }

        return ['ok' => true];
    }
}
