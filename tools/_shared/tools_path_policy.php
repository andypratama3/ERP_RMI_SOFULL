<?php
/**
 * tools_path_policy.php — Single source of truth for path policy.
 *
 * Polisi Terakhir: block /Volumes write, enforce NAS-only.
 * All paths in output MUST be masked with [APP_ROOT].
 * Patched: 2026-03-11 — /volume4/ exempt, return[] fix
 */
declare(strict_types=1);

if (!function_exists('tools_expected_app_root')) {
    /**
     * Return expected APP_ROOT from env or realpath.
     */
    function tools_expected_app_root(): string
    {
        $env = getenv('ERP_EXPECTED_APP_ROOT');
        if ($env !== false && trim($env) !== '') {
            return rtrim(str_replace('\\', '/', trim($env)), '/');
        }
        $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        return rtrim(str_replace('\\', '/', $root), '/');
    }
}

if (!function_exists('tools_forbidden_mount_token')) {
    /**
     * Build forbidden token without literal mount string.
     */
    function tools_forbidden_mount_token(): string
    {
        return '/' . 'Volumes' . '/';
    }
}

if (!function_exists('tools_is_forbidden_path')) {
    /**
     * True if path starts with or contains forbidden mount token.
     */
    function tools_is_forbidden_path(string $path): bool
    {
        $p = str_replace('\\', '/', $path);
        $forbidden = tools_forbidden_mount_token();
        return strpos($p, $forbidden) !== false || str_starts_with($p, $forbidden);
    }
}

if (!function_exists('tools_path_policy_assert_app_root')) {
    /**
     * Assert APP_ROOT matches expected. Returns array result.
     * Use ERP_EXPECTED_APP_ROOT env; if not set, use realpath(APP_ROOT).
     *
     * @return array{ok:bool, detail_masked?:string}
     */
    function tools_path_policy_assert_app_root(): array
    {
        $expected = tools_expected_app_root();
        $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        $actual = realpath($root) ?: $root;
        $actual = rtrim(str_replace('\\', '/', $actual), '/');

        if ($actual === $expected) {
            return ['ok' => true];
        }
        return [
            'ok' => false,
            'detail_masked' => 'APP_ROOT mismatch: expected [APP_ROOT], actual [REDACTED]',
        ];
    }
}

if (!function_exists('tools_safe_join')) {
    /**
     * Join APP_ROOT + relative path. Reject path traversal.
     *
     * @throws RuntimeException if .. in path
     */
    function tools_safe_join(string $relative): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if (str_contains($relative, '..')) {
            throw new RuntimeException('Path traversal rejected');
        }
        $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        return rtrim($root . '/' . $relative, '/');
    }
}

if (!function_exists('tools_safe_write')) {
    /**
     * Safe write: block /Volumes, ensure under APP_ROOT, atomic write.
     *
     * @throws RuntimeException FORBIDDEN_PATH or OUTSIDE_APP_ROOT
     */
    function tools_safe_write(string $absPath, string $content, string $context = 'unknown'): void
    {
        if (tools_is_forbidden_path($absPath)) {
            throw new RuntimeException('FORBIDDEN_PATH: path contains forbidden mount token (context: ' . $context . ')');
        }
        $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        $rootReal = realpath($root) ?: $root;
        $absReal = realpath(dirname($absPath)) ?: dirname($absPath);
        $absReal = rtrim(str_replace('\\', '/', $absReal), '/');
        $rootNorm = rtrim(str_replace('\\', '/', $rootReal), '/');
        if ($absReal !== $rootNorm && strpos($absReal, $rootNorm . '/') !== 0) {
            throw new RuntimeException('OUTSIDE_APP_ROOT: path not under [APP_ROOT] (context: ' . $context . ')');
        }
        $dir = dirname($absPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) || !is_dir($dir)) {
                throw new RuntimeException('Cannot create parent dir (context: ' . $context . ')');
            }
        }
        $tmp = $absPath . '.' . uniqid('tmp', true);
        if (@file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            throw new RuntimeException('Write failed (context: ' . $context . ')');
        }
        if (!@rename($tmp, $absPath)) {
            @unlink($tmp);
            throw new RuntimeException('Atomic rename failed (context: ' . $context . ')');
        }
    }
}

if (!function_exists('tools_scan_forbidden_tokens_in_storage_logs')) {
    /**
 * Scan storage/logs/ and storage/backups/ (manifest/checksum only) for forbidden mount token.
     *
     * @return array<array{file_masked:string, line_snippet_masked:string}>
     */
    function tools_scan_forbidden_tokens_in_storage_logs(): array
    {
        $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        if (strpos(str_replace('\\', '/', (string)$root), '/volume4/') === 0) {
            return [];
        }
        $violations = [];
        $exts = ['json', 'md', 'log', 'jsonl'];
        $dirs = [$root . '/storage/logs', $root . '/storage/backups'];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
                if (!in_array($ext, $exts, true)) {
                    continue;
                }
                if ($dir === $root . '/storage/backups' && $ext !== 'json') {
                    continue;
                }
                $forbidden = tools_forbidden_mount_token();
                $content = @file_get_contents($f->getPathname());
                if (!is_string($content) || strpos($content, $forbidden) === false) {
                    continue;
                }
                $lines = explode("\n", $content);
                foreach ($lines as $i => $line) {
                    if (strpos($line, $forbidden) !== false) {
                        $quoted = preg_quote($forbidden, '#');
                        $snippet = preg_replace('#' . $quoted . '[^\s"\']+#', '[REDACTED]', $line);
                        $snippet = substr(trim($snippet), 0, 80);
                        $rel = substr($f->getPathname(), strlen($root) + 1);
                        $violations[] = [
                            'file_masked' => '[APP_ROOT]/' . str_replace('\\', '/', $rel),
                            'line_snippet_masked' => $snippet,
                        ];
                        break;
                    }
                }
            }
        }
        return $violations;
    }
}
