<?php
declare(strict_types=1);

require_once __DIR__ . '/rfc_lib.php';

if (!function_exists('rfcmd_mask')) {
    function rfcmd_mask(string $text): string
    {
        return rfc_mask($text);
    }
}

if (!function_exists('rfcmd_read_file')) {
    function rfcmd_read_file(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'missing', 'raw' => '', 'body' => '', 'front_matter' => []];
        }
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            return ['ok' => false, 'error' => 'empty', 'raw' => $raw, 'body' => '', 'front_matter' => []];
        }
        $fm = rfcmd_parse_front_matter($raw);
        $body = rfcmd_extract_body($raw);
        return ['ok' => true, 'error' => '', 'raw' => $raw, 'body' => $body, 'front_matter' => $fm];
    }
}

if (!function_exists('rfcmd_parse_front_matter')) {
    function rfcmd_parse_front_matter(string $md): array
    {
        $out = [];
        $marker = '<!-- RFC_BODY_START -->';
        $head = $md;
        $pos = strpos($md, $marker);
        if ($pos !== false) {
            $head = (string)substr($md, 0, $pos);
        }
        $lines = preg_split('/\r\n|\r|\n/', $head) ?: [];
        $inApprovals = false;
        foreach ($lines as $line) {
            $ln = trim((string)$line);
            if ($ln === '') {
                continue;
            }
            if (preg_match('/^APPROVALS:\s*$/i', $ln) === 1) {
                $inApprovals = true;
                if (!isset($out['APPROVALS']) || !is_array($out['APPROVALS'])) {
                    $out['APPROVALS'] = [];
                }
                continue;
            }
            if ($inApprovals) {
                if (preg_match('/^\-\s*ROLE:\s*"?([^"]+)"?\s*$/i', $ln, $m) === 1) {
                    $out['APPROVALS'][] = [
                        'ROLE' => strtoupper(trim((string)$m[1])),
                        'USERNAME' => '',
                        'APPROVED_AT' => '',
                        'NOTE' => '',
                    ];
                    continue;
                }
                if (preg_match('/^(USERNAME|APPROVED_AT|NOTE):\s*"?(.+?)"?\s*$/i', $ln, $m) === 1) {
                    $idx = count((array)($out['APPROVALS'] ?? [])) - 1;
                    if ($idx >= 0) {
                        $key = strtoupper((string)$m[1]);
                        $out['APPROVALS'][$idx][$key] = trim((string)$m[2]);
                    }
                    continue;
                }
                if (preg_match('/^[A-Z_]+\:/', $ln) === 1) {
                    $inApprovals = false;
                }
            }
            if (preg_match('/^([A-Z_]+):\s*(.*)$/', $ln, $m) === 1) {
                $k = strtoupper(trim((string)$m[1]));
                $v = trim((string)$m[2], "\"'");
                if ($k === 'REQUIRES_APPROVALS') {
                    $vRaw = trim((string)$m[2]);
                    $vals = [];
                    if (str_starts_with($vRaw, '[') && str_ends_with($vRaw, ']')) {
                        $inner = trim(substr($vRaw, 1, -1));
                        foreach (explode(',', $inner) as $part) {
                            $p = strtoupper(trim((string)$part, " \t\n\r\0\x0B\"'"));
                            if ($p !== '') {
                                $vals[] = $p;
                            }
                        }
                    } else {
                        foreach (explode(',', $vRaw) as $part) {
                            $p = strtoupper(trim((string)$part, " \t\n\r\0\x0B\"'"));
                            if ($p !== '') {
                                $vals[] = $p;
                            }
                        }
                    }
                    $out[$k] = array_values(array_unique($vals));
                } else {
                    $out[$k] = $v;
                }
            }
        }
        if (!isset($out['APPROVALS']) || !is_array($out['APPROVALS'])) {
            $out['APPROVALS'] = [];
        }
        return $out;
    }
}

if (!function_exists('rfcmd_extract_body')) {
    function rfcmd_extract_body(string $md): string
    {
        $marker = '<!-- RFC_BODY_START -->';
        $pos = strpos($md, $marker);
        if ($pos === false) {
            return $md;
        }
        return ltrim((string)substr($md, $pos + strlen($marker)), "\r\n");
    }
}

if (!function_exists('rfcmd_find_heading_blocks')) {
    function rfcmd_find_heading_blocks(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $blocks = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = (string)$lines[$i];
            if (preg_match('/^(#{2,3})\s*(.+?)\s*$/', trim($line), $m) !== 1) {
                continue;
            }
            $level = strlen((string)$m[1]);
            $title = trim((string)$m[2]);
            $start = $i + 1;
            $j = $start;
            while ($j < $count) {
                $next = trim((string)$lines[$j]);
                if (preg_match('/^#{2,3}\s+/', $next) === 1) {
                    break;
                }
                $j++;
            }
            $text = trim(implode("\n", array_slice($lines, $start, $j - $start)));
            $blocks[] = [
                'level' => $level,
                'title' => $title,
                'title_norm' => strtolower(trim($title)),
                'text' => $text,
            ];
        }
        return $blocks;
    }
}

if (!function_exists('rfcmd_get_block_text')) {
    function rfcmd_get_block_text(array $blocks, int $level, string $title): string
    {
        $target = strtolower(trim($title));
        foreach ($blocks as $b) {
            if ((int)($b['level'] ?? 0) !== $level) continue;
            if ((string)($b['title_norm'] ?? '') === $target) {
                return trim((string)($b['text'] ?? ''));
            }
        }
        return '';
    }
}

