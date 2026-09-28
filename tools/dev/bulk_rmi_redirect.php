<?php
/**
 * CLI: php tools/dev/bulk_rmi_redirect.php
 * Ganti header(Location)+exit statis → rmi_redirect.
 * Hanya memindai folder aplikasi (bukan seluruh tree uploads/vendor).
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);

$scanDirs = [
    'master', 'purchases', 'kpi', 'stock', 'payroll', 'sales', 'mpr', 'tools',
    'dashboards', 'rbac', 'chat', 'hrl', 'customer_portal', 'manufacturer_portal',
    'Fixed_Asset', 'hrl_process', 'hrl_reg_alkes', 'absensi', 'docs', 'crm', 'itc',
    'pqp', 'scm', 'wqs', 'fin', 'act', 'hrl_reg_alkes',
];
$rootPhp = ['index.php', 'bootstrap.php', 'master_system_config.php', 'helpers.php'];

$files = 0;

$apply = static function (string $path, string $rel) use (&$files): void {
    $text = @file_get_contents($path);
    if ($text === false || !str_contains($text, 'header(') || !str_contains($text, 'Location')) {
        return;
    }
    if (str_ends_with($path, '/_shared/helpers.php') || str_ends_with($path, '\\_shared\\helpers.php')) {
        return;
    }
    if (basename($path) === 'helpers.php' && str_contains($text, 'function rmi_redirect')) {
        return;
    }
    $orig = $text;
    $text = preg_replace('/header\s*\(\s*"Location:\s*([^"]+)"\s*\)\s*;\s*exit\s*;/', 'rmi_redirect("$1");', $text);
    $text = preg_replace("/header\s*\(\s*'Location:\s*([^']+)'\s*\)\s*;\s*exit\s*;/", "rmi_redirect('$1');", $text);
    $text = preg_replace('/header\s*\(\s*"Location:\s*([^"]+)"\s*\)\s*;\s*\R\s*exit\s*;/', 'rmi_redirect("$1");', $text);
    $text = preg_replace("/header\s*\(\s*'Location:\s*([^']+)'\s*\)\s*;\s*\R\s*exit\s*;/", "rmi_redirect('$1');", $text);
    if ($text !== $orig) {
        file_put_contents($path, $text);
        $files++;
        fwrite(STDERR, "$rel\n");
    }
};

foreach ($scanDirs as $sd) {
    $base = $root . '/' . $sd;
    if (!is_dir($base)) {
        continue;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        /** @var SplFileInfo $f */
        if (strtolower($f->getExtension()) !== 'php') {
            continue;
        }
        $path = $f->getPathname();
        $rel = substr($path, strlen($root) + 1);
        $apply($path, $rel);
    }
}

foreach ($rootPhp as $name) {
    $p = $root . '/' . $name;
    if (is_file($p)) {
        $apply($p, $name);
    }
}

$shared = $root . '/_shared';
if (is_dir($shared)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($shared, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if (strtolower($f->getExtension()) !== 'php') {
            continue;
        }
        $path = $f->getPathname();
        if (str_ends_with($path, 'helpers.php')) {
            continue;
        }
        $rel = substr($path, strlen($root) + 1);
        $apply($path, $rel);
    }
}

fwrite(STDERR, "Modified: $files files\n");
