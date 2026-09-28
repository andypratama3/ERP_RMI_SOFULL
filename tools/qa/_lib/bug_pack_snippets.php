<?php
declare(strict_types=1);

require_once __DIR__ . '/bug_pack_redact.php';
require_once __DIR__ . '/bug_pack_allowlist.php';

if (!function_exists('bps_extract_file_lines')) {
    /**
     * Extract file:line from stacktrace/error text.
     * @return array<array{file:string, line:int}>
     */
    function bps_extract_file_lines(string $text, string $root): array
    {
        $results = [];
        $seen = [];
        if (preg_match_all('/([^\s:]+\.php):(\d+)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $file = $m[1];
                $line = (int)$m[2];
                $key = $file . ':' . $line;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $results[] = ['file' => $file, 'line' => $line];
                }
            }
        }
        if (preg_match_all('/in\s+([^\s]+\.php)\s+on\s+line\s+(\d+)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $file = $m[1];
                $line = (int)$m[2];
                $key = $file . ':' . $line;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $results[] = ['file' => $file, 'line' => $line];
                }
            }
        }
        return $results;
    }
}

if (!function_exists('bps_resolve_file_path')) {
    function bps_resolve_file_path(string $fileHint, string $root): ?string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $hint = str_replace('\\', '/', $fileHint);
        $candidates = [];
        if (str_starts_with($hint, $root)) {
            $candidates[] = $hint;
        }
        $candidates[] = $root . '/' . ltrim($hint, '/');
        $candidates[] = $root . '/' . $hint;
        foreach (['modules/', 'app/', 'api/', 'tools/'] as $prefix) {
            if (str_contains($hint, $prefix)) {
                $rel = substr($hint, strpos($hint, $prefix));
                $candidates[] = $root . '/' . $rel;
            }
        }
        foreach ($candidates as $p) {
            $resolved = realpath($p);
            if ($resolved !== false && is_file($resolved) && bpa_is_source_file_allowed($resolved)) {
                return $resolved;
            }
        }
        return null;
    }
}

if (!function_exists('bps_collect_snippets')) {
    /**
     * Collect source snippets from error text.
     * @param string $errorText
     * @param string $root
     * @param int $contextLines
     * @param int $maxFiles
     * @param int $maxLinesPerFile
     * @return array{included:array, note:string|null}
     */
    function bps_collect_snippets(string $errorText, string $root, int $contextLines = 12, int $maxFiles = 20, int $maxLinesPerFile = 50): array
    {
        $fileLines = bps_extract_file_lines($errorText, $root);
        if (empty($fileLines)) {
            return ['included' => [], 'note' => 'NO_STACKTRACE_FILELINE_FOUND'];
        }
        $included = [];
        $count = 0;
        foreach ($fileLines as $fl) {
            if ($count >= $maxFiles) break;
            $resolved = bps_resolve_file_path($fl['file'], $root);
            if ($resolved === null) continue;
            $lines = @file($resolved, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) continue;
            $lineNum = max(1, min($fl['line'], count($lines)));
            $start = max(0, $lineNum - 1 - $contextLines);
            $end = min(count($lines), $lineNum + $contextLines);
            $snippetLines = array_slice($lines, $start, min($end - $start, $maxLinesPerFile));
            $relPath = str_replace($root . '/', '', $resolved);
            $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $relPath) . '_L' . $lineNum;
            $header = "--- " . $relPath . " (line " . $lineNum . ") ---\n";
            $body = '';
            foreach ($snippetLines as $i => $line) {
                $actualLine = $start + $i + 1;
                $marker = ($actualLine === $lineNum) ? '>>>' : '   ';
                $body .= sprintf("%s %4d | %s\n", $marker, $actualLine, $line);
            }
            $content = $header . $body;
            $redacted = bpr_redact_text($content);
            $scan = bpr_deny_pattern_scan($redacted, $root);
            if (!$scan['ok']) continue;
            $rel = 'source_snippets/snippet_' . sprintf('%03d', $count + 1) . '_' . $safeName . '.txt';
            $included[] = [
                'rel' => $rel,
                'content' => $redacted,
                'bytes' => strlen($redacted),
                'redacted' => true,
                'original_file' => bpr_mask_path($resolved, $root),
                'original_line' => $lineNum,
            ];
            $count++;
        }
        $note = null;
        if (empty($included) && !empty($fileLines)) {
            $note = 'NO_STACKTRACE_FILES_RESOLVED';
        }
        return ['included' => $included, 'note' => $note];
    }
}
