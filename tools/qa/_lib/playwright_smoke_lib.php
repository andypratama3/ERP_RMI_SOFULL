<?php
declare(strict_types=1);

if (!function_exists('pwsmoke_root')) {
    function pwsmoke_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('pwsmoke_has_node')) {
    function pwsmoke_has_node(): bool
    {
        $out = [];
        $code = 1;
        @exec('node -v 2>/dev/null', $out, $code);
        return (int)$code === 0;
    }
}

if (!function_exists('pwsmoke_has_npm')) {
    function pwsmoke_has_npm(): bool
    {
        $out = [];
        $code = 1;
        @exec('npm -v 2>/dev/null', $out, $code);
        return (int)$code === 0;
    }
}

if (!function_exists('pwsmoke_config_paths')) {
    /** @return string[] */
    function pwsmoke_config_paths(string $root): array
    {
        return [
            $root . '/tools/qa/playwright/playwright.config.ts',
            $root . '/tools/qa/playwright/playwright.config.js',
            $root . '/playwright.config.ts',
            $root . '/playwright.config.js',
        ];
    }
}

if (!function_exists('pwsmoke_find_config')) {
    function pwsmoke_find_config(string $root): ?string
    {
        foreach (pwsmoke_config_paths($root) as $p) {
            if (is_file($p)) return $p;
        }
        return null;
    }
}

if (!function_exists('pwsmoke_test_dir')) {
    function pwsmoke_test_dir(string $root): ?string
    {
        $configPath = pwsmoke_find_config($root);
        if ($configPath === null) return null;
        $dir = dirname($configPath);
        $candidates = [
            $dir . '/tests',
            $root . '/tests/playwright',
        ];
        foreach ($candidates as $c) {
            if (is_dir($c)) return $c;
        }
        return $root . '/tests/playwright';
    }
}
