<?php
declare(strict_types=1);

/**
 * PHP Static Analysis Lib
 * Wrapper untuk PHPStan/Psalm atau fallback ke php -l.
 * Output JSON untuk konsumsi Audit Center.
 */

if (!function_exists('psal_root')) {
    function psal_root(): string
    {
        return defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 2));
    }
}

if (!function_exists('psal_collect_php_files')) {
    function psal_collect_php_files(string $root, array $dirs, int $maxFiles = 500): array
    {
        $exclude = ['vendor', 'node_modules', 'storage', 'uploads', '.git', 'exports'];
        $out = [];
        foreach ($dirs as $dirRel) {
            $dir = $root . '/' . trim($dirRel, '/');
            if (!is_dir($dir)) continue;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $f) {
                if (count($out) >= $maxFiles) break 2;
                if (!$f instanceof SplFileInfo || !$f->isFile()) continue;
                if (strtolower($f->getExtension()) !== 'php') continue;
                $path = str_replace('\\', '/', (string)$f->getPathname());
                foreach ($exclude as $ex) {
                    if (str_contains($path, '/' . $ex . '/')) continue 2;
                }
                $rel = ltrim(str_replace($root, '', $path), '/');
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }
}

if (!function_exists('psal_run_php_lint')) {
    /**
     * Fallback: php -l untuk setiap file.
     * @return array{ok:bool, errors:array, total:int, fail_count:int, duration_ms:int}
     */
    function psal_run_php_lint(string $root, array $files, string $phpBin = 'php'): array
    {
        $errors = [];
        $t0 = microtime(true);
        foreach ($files as $rel) {
            $abs = $root . '/' . $rel;
            if (!is_file($abs)) continue;
            $out = [];
            $code = 1;
            @exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($abs) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $errors[] = [
                    'file' => $rel,
                    'message' => trim(implode(' ', $out)),
                    'line' => null,
                ];
            }
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        return [
            'ok' => count($errors) === 0,
            'errors' => $errors,
            'total' => count($files),
            'fail_count' => count($errors),
            'duration_ms' => $ms,
            'engine' => 'php_lint',
        ];
    }
}

if (!function_exists('psal_try_phpstan')) {
    /**
     * Coba jalankan PHPStan jika tersedia (vendor/bin/phpstan atau composer).
     * @return array{ok:bool, available:bool, output:array, errors:array, duration_ms:int}
     */
    function psal_try_phpstan(string $root, array $dirs): array
    {
        $t0 = microtime(true);
        $vendorBin = $root . '/vendor/bin/phpstan';
        $composerJson = $root . '/composer.json';
        if (!is_file($vendorBin) || !is_file($composerJson)) {
            return [
                'ok' => false,
                'available' => false,
                'output' => ['PHPStan not installed. Run: composer require --dev phpstan/phpstan'],
                'errors' => [],
                'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
                'engine' => 'phpstan',
            ];
        }
        $dirList = implode(' ', array_map('escapeshellarg', array_map(fn($d) => $root . '/' . trim($d, '/'), $dirs)));
        $cmd = escapeshellarg($vendorBin) . ' analyse --no-progress --error-format=json ' . $dirList . ' 2>&1';
        $out = [];
        @exec($cmd, $out, $code);
        $raw = implode("\n", $out);
        $json = @json_decode($raw, true);
        $errors = [];
        if (is_array($json)) {
            foreach ($json['files'] ?? [] as $file => $data) {
                $rel = ltrim(str_replace($root, '', $file), '/');
                foreach ($data['messages'] ?? [] as $m) {
                    $errors[] = [
                        'file' => $rel,
                        'message' => (string)($m['message'] ?? ''),
                        'line' => isset($m['line']) ? (int)$m['line'] : null,
                    ];
                }
            }
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        return [
            'ok' => $code === 0 && count($errors) === 0,
            'available' => true,
            'output' => array_slice($out, -5),
            'errors' => $errors,
            'duration_ms' => $ms,
            'engine' => 'phpstan',
        ];
    }
}

if (!function_exists('psal_resolve_php_cli')) {
    /**
     * Resolve PHP CLI binary (bukan FPM). Di Synology/NAS, `php` bisa mengarah ke php-fpm.
     */
    function psal_resolve_php_cli(string $prefer = 'php'): string
    {
        if (!function_exists('shell_exec')) {
            return $prefer;
        }
        $testFile = __DIR__ . '/php_static_analysis_lib.php';
        if (!is_file($testFile)) {
            return $prefer;
        }
        $candidates = array_unique(array_filter([$prefer, 'php-cli', 'php84-cli', 'php8.4-cli', '/usr/local/bin/php84', '/usr/local/bin/php82', 'php82-cli', '/usr/bin/php', '/usr/local/bin/php']));
        foreach ($candidates as $bin) {
            $test = @shell_exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($testFile) . ' 2>&1');
            if ($test !== null && $test !== '' && stripos((string)$test, 'No syntax errors') !== false) {
                return $bin;
            }
        }
        return $prefer;
    }
}

if (!function_exists('psal_run')) {
    /**
     * Jalankan static analysis: coba PHPStan dulu, fallback ke php -l.
     */
    function psal_run(string $root, string $scope = 'core', string $phpBin = 'php'): array
    {
        $phpBin = psal_resolve_php_cli($phpBin);
        $coreDirs = ['master', 'sales', 'purchases', 'stock', 'dashboards', 'hrl', 'kpi', 'mpr', 'tools', '_shared', 'api', 'chat', 'Fixed_Asset'];
        $fullDirs = $scope === 'full' ? array_filter(glob($root . '/*'), 'is_dir') : $coreDirs;
        if ($scope === 'full') {
            $fullDirs = array_map(fn($p) => basename((string)$p), array_filter($fullDirs, fn($p) => !in_array(basename((string)$p), ['.git', 'vendor', 'node_modules', 'storage', 'uploads', 'exports'], true)));
        }
        $dirs = is_array($fullDirs) ? $fullDirs : $coreDirs;
        $files = psal_collect_php_files($root, $dirs);

        $phpstan = psal_try_phpstan($root, $dirs);
        if ($phpstan['available'] && $phpstan['ok']) {
            return array_merge($phpstan, ['total_files' => count($files), 'scope' => $scope]);
        }
        if ($phpstan['available'] && !$phpstan['ok']) {
            return array_merge($phpstan, ['total_files' => count($files), 'scope' => $scope]);
        }

        $lint = psal_run_php_lint($root, $files, $phpBin);
        return array_merge($lint, ['total_files' => count($files), 'scope' => $scope]);
    }
}
