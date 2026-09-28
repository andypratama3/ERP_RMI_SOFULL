<?php
declare(strict_types=1);
/**
 * Parse PHP for includes, requires, DB tables (best effort).
 */
if (!function_exists('diagrams_parse_includes')) {
    function diagrams_parse_includes(string $content): array {
        $out = [];
        if (preg_match_all('/\b(?:require|include)(?:_once)?\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            $out = array_unique($m[1]);
        }
        return $out;
    }
}

if (!function_exists('diagrams_parse_tables')) {
    function diagrams_parse_tables(string $content): array {
        $out = [];
        if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-zA-Z0-9_]+)`?/i', $content, $m)) {
            $out = array_unique(array_map('strtolower', $m[1]));
        }
        return $out;
    }
}

if (!function_exists('diagrams_parse_guards')) {
    function diagrams_parse_guards(string $content): array {
        $guards = [];
        if (preg_match('/require_login/', $content)) $guards[] = 'require_login';
        if (preg_match('/require_role/', $content)) $guards[] = 'require_role';
        if (preg_match('/require_permission/', $content)) $guards[] = 'require_permission';
        if (preg_match('/verify_csrf/', $content)) $guards[] = 'verify_csrf';
        return $guards;
    }
}
