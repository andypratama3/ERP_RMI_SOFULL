<?php
declare(strict_types=1);

if (!function_exists('sscan_root')) {
    function sscan_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('sscan_mask')) {
    function sscan_mask(string $value): string
    {
        if (function_exists('tools_mask_sensitive')) {
            $v = tools_mask_sensitive($value);
        } else {
            $v = preg_replace('/\/Users\/[^\s]+/', '[APP_ROOT]', $value);
            $v = preg_replace('/[A-Za-z0-9_-]{20,}/', '[REDACTED]', $v);
        }
        return function_exists('ts_mask') ? ts_mask($v) : $v;
    }
}

if (!function_exists('sscan_include_dirs')) {
    function sscan_include_dirs(): array
    {
        return ['app', 'modules', 'api', 'tools'];
    }
}

if (!function_exists('sscan_exclude_dirs')) {
    function sscan_exclude_dirs(): array
    {
        return ['storage', 'vendor', 'node_modules', 'public/assets/vendor'];
    }
}

if (!function_exists('sscan_exclude_files')) {
    /** Paths to skip (substring match). */
    function sscan_exclude_files(): array
    {
        return ['/secret_scan_lib.php', '/secret_scan.php', '/rfc_lint_lib.php', '/release_notes_lib.php', '/executive_summary_schema.php', '/mobile_master_policy_smoke.php'];
    }
}

if (!function_exists('sscan_fail_patterns')) {
    /** @return array<array{pattern:string,label:string}> */
    function sscan_fail_patterns(): array
    {
        return [
            ['pattern' => 'BEGIN PRIVATE KEY', 'label' => 'PRIVATE_KEY'],
            ['pattern' => 'Authorization: Bearer ', 'label' => 'BEARER_TOKEN'],
            ['pattern' => 'AWS_SECRET_ACCESS_KEY', 'label' => 'AWS_SECRET'],
            ['pattern' => 'xoxb-', 'label' => 'SLACK_TOKEN'],
            ['pattern' => 'ghp_', 'label' => 'GITHUB_TOKEN'],
            ['pattern' => 'AIzaSy', 'label' => 'GOOGLE_API_KEY'],
            ['pattern' => 'DB_PASS=', 'label' => 'DB_PASS'],
            ['pattern' => 'PASSWORD=', 'label' => 'PASSWORD_EQ'],
        ];
    }
}

if (!function_exists('sscan_warn_patterns')) {
    /** @return array<array{pattern:string,label:string}> */
    function sscan_warn_patterns(): array
    {
        return [
            ['pattern' => 'token=', 'label' => 'TOKEN_EQ'],
            ['pattern' => 'secret=', 'label' => 'SECRET_EQ'],
        ];
    }
}

if (!function_exists('sscan_load_allowlist')) {
    function sscan_load_allowlist(string $root): array
    {
        $path = $root . '/tools/qa/secret_scan_allowlist.txt';
        if (!is_file($path)) return [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $filtered = array_filter($lines, static function ($l) {
            $s = trim((string)$l);
            return $s !== '' && !str_starts_with($s, '#');
        });
        return array_map('trim', $filtered);
    }
}

if (!function_exists('sscan_is_allowed')) {
    function sscan_is_allowed(string $line, array $allowlist): bool
    {
        foreach ($allowlist as $sub) {
            if ($sub !== '' && str_contains($line, $sub)) return true;
        }
        return false;
    }
}

if (!function_exists('sscan_mask_line')) {
    function sscan_mask_line(string $line): string
    {
        $trimmed = trim($line);
        if ($trimmed === '') return '';
        if (strlen($trimmed) > 80) {
            return substr($trimmed, 0, 20) . '....[REDACTED]....' . substr($trimmed, -10);
        }
        return preg_replace('/[A-Za-z0-9_-]{16,}/', '[REDACTED]', $trimmed) ?: $trimmed;
    }
}

if (!function_exists('sscan_scan_file')) {
    /**
     * @return array<array{severity:string,line:int,label:string,context_masked:string}>
     */
    function sscan_scan_file(string $path, string $root, array $allowlist, bool $strict): array
    {
        $findings = [];
        $relPath = str_replace($root . '/', '', $path);
        $content = @file_get_contents($path);
        if ($content === false) return [];
        $lines = explode("\n", $content);
        $failPatterns = sscan_fail_patterns();
        $warnPatterns = sscan_warn_patterns();
        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;
            if (sscan_is_allowed($line, $allowlist)) continue;
            foreach ($failPatterns as $fp) {
                if (stripos($line, $fp['pattern']) !== false) {
                    $findings[] = [
                        'severity' => 'FAIL',
                        'line' => $lineNum,
                        'label' => $fp['label'],
                        'context_masked' => sscan_mask_line($line),
                        'file_rel' => $relPath,
                    ];
                    break;
                }
            }
            if ($strict) continue;
            foreach ($warnPatterns as $wp) {
                if (stripos($line, $wp['pattern']) !== false) {
                    $findings[] = [
                        'severity' => 'WARN',
                        'line' => $lineNum,
                        'label' => $wp['label'],
                        'context_masked' => sscan_mask_line($line),
                        'file_rel' => $relPath,
                    ];
                    break;
                }
            }
        }
        return $findings;
    }
}

if (!function_exists('sscan_collect_files')) {
    /** @return string[] */
    function sscan_collect_files(string $root): array
    {
        $include = sscan_include_dirs();
        $exclude = sscan_exclude_dirs();
        $files = [];
        $excludeFiles = sscan_exclude_files();
        foreach ($include as $dir) {
            $full = $root . '/' . $dir;
            if (!is_dir($full)) continue;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $fi) {
                if (!$fi->isFile()) continue;
                $path = $fi->getPathname();
                $rel = str_replace($root . '/', '', $path);
                $parts = explode('/', $rel);
                $skip = false;
                foreach ($exclude as $ex) {
                    if (in_array($ex, $parts, true)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;
                $skipFile = false;
                foreach ($excludeFiles as $ex) {
                    if (str_contains($path, $ex)) {
                        $skipFile = true;
                        break;
                    }
                }
                if ($skipFile) continue;
                $ext = strtolower($fi->getExtension());
                if (in_array($ext, ['php', 'env', 'yml', 'yaml', 'json', 'md', 'txt', 'ini', 'config'], true)) {
                    $files[] = $path;
                }
            }
        }
        return $files;
    }
}
