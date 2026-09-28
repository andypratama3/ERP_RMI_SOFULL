<?php
declare(strict_types=1);
/**
 * Build DOT graph from parsed data.
 */
if (!function_exists('diagrams_build_dot')) {
    function diagrams_build_dot(string $name, array $nodes, array $edges, string $type = 'module'): string {
        $dot = "digraph G {\n  rankdir=TB;\n  node [shape=box, fontname=Helvetica];\n";
        foreach ($nodes as $n) {
            $label = addslashes($n['label'] ?? $n['id']);
            $dot .= "  \"" . $n['id'] . "\" [label=\"" . $label . "\"];\n";
        }
        foreach ($edges as $e) {
            $dot .= "  \"" . $e['from'] . "\" -> \"" . $e['to'] . "\";\n";
        }
        $dot .= "}\n";
        return $dot;
    }
}
