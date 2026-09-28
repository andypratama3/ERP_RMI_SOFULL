<?php
declare(strict_types=1);

/**
 * Security Scanner Lib
 * Scan SQL injection, XSS, path traversal, hardcoded credentials.
 * Pattern-based (regex) - heuristic, bukan AST.
 */

if (!function_exists('secscan_root')) {
    function secscan_root(): string
    {
        return defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 2));
    }
}

if (!function_exists('secscan_collect_php_files')) {
    function secscan_collect_php_files(string $root, int $maxFiles = 800): array
    {
        $exclude = ['vendor', 'node_modules', 'storage', 'uploads', '.git', 'exports'];
        $dirs = ['master', 'sales', 'purchases', 'stock', 'dashboards', 'hrl', 'kpi', 'mpr', 'tools', '_shared', 'api', 'chat', 'Fixed_Asset', 'rbac', 'payroll'];
        $out = [];
        foreach ($dirs as $dirRel) {
            $dir = $root . '/' . trim($dirRel, '/');
            if (!is_dir($dir)) continue;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (count($out) >= $maxFiles) break 2;
                if (!$f instanceof SplFileInfo || !$f->isFile()) continue;
                if (strtolower($f->getExtension()) !== 'php') continue;
                $path = str_replace('\\', '/', (string)$f->getPathname());
                foreach ($exclude as $ex) {
                    if (str_contains($path, '/' . $ex . '/')) continue 2;
                }
                $out[] = ltrim(str_replace($root, '', $path), '/');
            }
        }
        sort($out);
        return $out;
    }
}

if (!function_exists('secscan_sqli_patterns')) {
    function secscan_sqli_patterns(): array
    {
        return [
            ['pattern' => '/\$pdo\s*->\s*query\s*\(\s*[\'"]\s*SELECT.*\$\{?(\w+)\}?/', 'severity' => 'high', 'rule' => 'query_with_var_concat'],
            ['pattern' => '/\$pdo\s*->\s*exec\s*\(\s*[\'"].*\$/', 'severity' => 'high', 'rule' => 'exec_with_var'],
            ['pattern' => '/\$mysqli\s*->\s*query\s*\(\s*[\'"].*\$/', 'severity' => 'high', 'rule' => 'mysqli_query_var'],
            ['pattern' => '/\b(mysql_query|mysqli_query)\s*\([^)]*\$/', 'severity' => 'critical', 'rule' => 'legacy_query_var'],
        ];
    }
}

if (!function_exists('secscan_xss_patterns')) {
    function secscan_xss_patterns(): array
    {
        return [
            ['pattern' => '/<\?=\s*\$[a-zA-Z_][a-zA-Z0-9_]*\[\s*[\'"]message[\'"]\s*\]\s*\?>/', 'severity' => 'high', 'rule' => 'flash_message_no_escape', 'exclude' => '/\bh\s*\(/'],
            ['pattern' => '/<\?=\s*\$[a-zA-Z_][a-zA-Z0-9_]*\s*\?>/', 'severity' => 'medium', 'rule' => 'echo_var_no_h', 'exclude' => '/\bh\s*\(|htmlspecialchars|rmi_ui_h/'],
        ];
    }
}

if (!function_exists('secscan_credential_patterns')) {
    function secscan_credential_patterns(): array
    {
        return [
            ['pattern' => '/[\'"]pass(word)?[\'"]\s*=>\s*[\'"][^\'"]{6,}[\'"]/', 'severity' => 'high', 'rule' => 'hardcoded_password'],
            ['pattern' => '/DB_PASS\s*=\s*[\'"][^\'"]+[\'"]/', 'severity' => 'high', 'rule' => 'db_pass_literal'],
            ['pattern' => '/password\s*=\s*[\'"][^\'"]{4,}[\'"]/i', 'severity' => 'medium', 'rule' => 'password_literal'],
        ];
    }
}

if (!function_exists('secscan_path_traversal_patterns')) {
    function secscan_path_traversal_patterns(): array
    {
        return [
            ['pattern' => '/file_get_contents\s*\(\s*\$_/', 'severity' => 'high', 'rule' => 'file_get_contents_user_input'],
            ['pattern' => '/include\s*\(\s*\$_/', 'severity' => 'critical', 'rule' => 'include_user_input'],
            ['pattern' => '/require\s*\(\s*\$_/', 'severity' => 'critical', 'rule' => 'require_user_input'],
            ['pattern' => '/readfile\s*\(\s*\$_/', 'severity' => 'high', 'rule' => 'readfile_user_input'],
        ];
    }
}

if (!function_exists('secscan_scan_file')) {
    function secscan_scan_file(string $path, string $content): array
    {
        $findings = [];
        $lineNum = 0;
        foreach (explode("\n", $content) as $line) {
            $lineNum++;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) continue;

            foreach (array_merge(
                secscan_sqli_patterns(),
                secscan_xss_patterns(),
                secscan_credential_patterns(),
                secscan_path_traversal_patterns()
            ) as $rule) {
                if (!preg_match($rule['pattern'], $line)) continue;
                if (!empty($rule['exclude']) && preg_match($rule['exclude'], $line)) continue;
                $findings[] = [
                    'line' => $lineNum,
                    'rule' => $rule['rule'],
                    'severity' => $rule['severity'],
                    'snippet' => substr($line, 0, 120),
                ];
            }
        }
        return $findings;
    }
}

if (!function_exists('secscan_run')) {
    function secscan_run(string $root, string $scope = 'core'): array
    {
        $t0 = microtime(true);
        $files = secscan_collect_php_files($root);
        $allFindings = [];
        foreach ($files as $rel) {
            $abs = $root . '/' . $rel;
            if (!is_file($abs)) continue;
            $content = @file_get_contents($abs);
            if ($content === false) continue;
            $findings = secscan_scan_file($rel, $content);
            foreach ($findings as $f) {
                $f['file'] = $rel;
                $allFindings[] = $f;
            }
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $critical = count(array_filter($allFindings, fn($x) => ($x['severity'] ?? '') === 'critical'));
        $high = count(array_filter($allFindings, fn($x) => ($x['severity'] ?? '') === 'high'));
        $medium = count(array_filter($allFindings, fn($x) => ($x['severity'] ?? '') === 'medium'));
        return [
            'ok' => $critical === 0 && $high === 0,
            'findings' => $allFindings,
            'summary' => [
                'total' => count($allFindings),
                'critical' => $critical,
                'high' => $high,
                'medium' => $medium,
            ],
            'files_scanned' => count($files),
            'duration_ms' => $ms,
            'scope' => $scope,
        ];
    }
}
