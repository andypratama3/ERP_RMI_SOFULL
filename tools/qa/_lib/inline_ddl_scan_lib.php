<?php
declare(strict_types=1);

if (!function_exists('iddl_root')) {
    function iddl_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('iddl_include_dirs')) {
    /** @return string[] */
    function iddl_include_dirs(): array
    {
        return ['modules', 'api', 'app'];
    }
}

if (!function_exists('iddl_exclude_dirs')) {
    /** @return string[] */
    function iddl_exclude_dirs(): array
    {
        return ['storage', 'vendor', 'node_modules', 'sql/migrations'];
    }
}

if (!function_exists('iddl_fail_patterns')) {
    /** @return array<array{pattern:string,label:string}> */
    function iddl_fail_patterns(): array
    {
        return [
            ['pattern' => '/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', 'label' => 'DDL_TABLE'],
            ['pattern' => '/\bADD\s+COLUMN\b/i', 'label' => 'ADD_COLUMN'],
            ['pattern' => '/\bTRUNCATE\s+TABLE\b/i', 'label' => 'TRUNCATE_TABLE'],
        ];
    }
}

if (!function_exists('iddl_mask_snippet')) {
    function iddl_mask_snippet(string $line): string
    {
        $trimmed = trim($line);
        if ($trimmed === '') return '';
        if (strlen($trimmed) > 80) {
            return substr($trimmed, 0, 25) . '....[REDACTED]....' . substr($trimmed, -15);
        }
        return preg_replace('/[A-Za-z0-9_-]{16,}/', '[REDACTED]', $trimmed) ?: $trimmed;
    }
}

if (!function_exists('iddl_collect_files')) {
    /** @return string[] */
    function iddl_collect_files(string $root): array
    {
        $include = iddl_include_dirs();
        $exclude = iddl_exclude_dirs();
        $files = [];
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
                    if (str_contains($rel, $ex) || in_array($ex, $parts, true)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;
                $ext = strtolower($fi->getExtension());
                if ($ext === 'php') {
                    $files[] = $path;
                }
            }
        }
        return $files;
    }
}

if (!function_exists('iddl_scan_file')) {
    /**
     * @return array<array{file:string,line:int,snippet_masked:string,pattern:string}>
     */
    function iddl_scan_file(string $path, string $root, array $patterns): array
    {
        $hits = [];
        $relPath = str_replace($root . '/', '', $path);
        $content = @file_get_contents($path);
        if ($content === false) return [];
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;
            foreach ($patterns as $p) {
                if (preg_match($p['pattern'], $line) === 1) {
                    $hits[] = [
                        'file' => $relPath,
                        'line' => $lineNum,
                        'snippet_masked' => iddl_mask_snippet($line),
                        'pattern' => $p['label'],
                    ];
                    break;
                }
            }
        }
        return $hits;
    }
}
