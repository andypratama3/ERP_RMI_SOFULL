<?php
declare(strict_types=1);
/**
 * Scan modules and files for diagram generation.
 */
if (!function_exists('diagrams_scan_modules')) {
    function diagrams_scan_modules(string $appRoot): array {
        $mods = ['master','purchases','sales','stock','rbac','hrl','payroll','Fixed_Asset','chat','dashboards','kpi','mpr','absensi','api','tools','hrl_process','hrl_reg_alkes'];
        $found = [];
        foreach ($mods as $m) {
            $d = $appRoot . '/' . $m;
            if (is_dir($d)) {
                $found[] = $m;
            }
        }
        return $found;
    }
}

if (!function_exists('diagrams_scan_php_files')) {
    function diagrams_scan_php_files(string $appRoot, array $excludeDirs = []): array {
        $exclude = array_flip(array_merge($excludeDirs, ['vendor','storage','uploads','docs','tests','android_app','node_modules','.git']));
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $rel = str_replace($appRoot . '/', '', $f->getPathname());
            $parts = explode('/', $rel);
            if (isset($exclude[$parts[0]])) continue;
            $files[] = $rel;
        }
        return $files;
    }
}
