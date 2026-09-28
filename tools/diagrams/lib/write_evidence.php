<?php
declare(strict_types=1);
/**
 * Write evidence logs.
 */
if (!function_exists('diagrams_write_evidence')) {
    function diagrams_write_evidence(string $path, array $data): void {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
