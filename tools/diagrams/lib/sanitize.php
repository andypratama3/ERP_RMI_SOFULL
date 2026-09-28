<?php
declare(strict_types=1);
/**
 * Sanitize filenames: ASCII only, no Cyrillic/Unicode.
 */
if (!function_exists('diagrams_sanitize_filename')) {
    function diagrams_sanitize_filename(string $name): string {
        $s = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $name);
        $s = preg_replace('/_+/', '_', $s);
        return trim($s, '_') ?: 'unnamed';
    }
}
