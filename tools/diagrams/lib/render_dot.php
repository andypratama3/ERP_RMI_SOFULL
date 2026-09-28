<?php
declare(strict_types=1);
/**
 * Render DOT to SVG via graphviz dot.
 */
if (!function_exists('diagrams_render_dot_to_svg')) {
    function diagrams_render_dot_to_svg(string $dot, string $outPath): bool {
        $tmp = tempnam(sys_get_temp_dir(), 'dot_');
        file_put_contents($tmp . '.dot', $dot);
        $cmd = sprintf('dot -Tsvg -o%s %s 2>/dev/null', escapeshellarg($outPath), escapeshellarg($tmp . '.dot'));
        exec($cmd, $o, $code);
        @unlink($tmp . '.dot');
        @unlink($tmp);
        return $code === 0 && is_file($outPath);
    }
}
