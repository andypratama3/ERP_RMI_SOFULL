<?php
declare(strict_types=1);

if (!function_exists('bpz_create')) {
    /**
     * Create ZIP from pack directory. Returns path to zip or empty on failure.
     */
    function bpz_create(string $packDir, string $zipPath): string
    {
        if (!is_dir($packDir)) {
            return '';
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return '';
        }
        $baseLen = strlen($packDir) + 1;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $fi) {
            $path = $fi->getPathname();
            $rel = substr($path, $baseLen);
            if ($fi->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($path, $rel);
            }
        }
        $zip->close();
        return is_file($zipPath) ? $zipPath : '';
    }
}

if (!function_exists('bpz_checksums')) {
    /**
     * Generate sha256 checksums for all files in directory.
     */
    function bpz_checksums(string $packDir): array
    {
        $out = [];
        if (!is_dir($packDir)) return $out;
        $baseLen = strlen(rtrim($packDir, '/')) + 1;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $fi) {
            if ($fi->isFile()) {
                $path = $fi->getPathname();
                $rel = substr($path, $baseLen);
                $hash = @hash_file('sha256', $path);
                if ($hash !== false) {
                    $out[$rel] = $hash;
                }
            }
        }
        return $out;
    }
}
