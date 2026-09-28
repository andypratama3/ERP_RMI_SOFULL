<?php
declare(strict_types=1);

require_once __DIR__ . '/release_notes_lib.php';

if (!function_exists('rns_mask_text')) {
    function rns_mask_text(string $text): string
    {
        return rn_mask($text);
    }
}

if (!function_exists('mask_text')) {
    function mask_text(string $text): string
    {
        return rns_mask_text($text);
    }
}

if (!function_exists('rns_pipeline_last_run_id')) {
    function rns_pipeline_last_run_id(): string
    {
        $p = rn_root() . '/storage/logs/pipeline/pipeline_last.json';
        $read = rn_read_json($p);
        if (!$read['ok']) {
            return '';
        }
        return trim((string)($read['data']['run_id'] ?? ''));
    }
}

if (!function_exists('find_rfc_file_by_id')) {
    function find_rfc_file_by_id(string $rfcId): string
    {
        $id = strtoupper(trim($rfcId));
        if ($id === '') {
            return '';
        }
        $dir = rn_root() . '/docs/governance/RFC';
        $candidates = glob($dir . '/' . $id . '*.md') ?: [];
        sort($candidates, SORT_STRING);
        if ($candidates === []) {
            return '';
        }
        $exact = '';
        foreach ($candidates as $file) {
            $raw = (string)@file_get_contents($file);
            $fm = parse_rfc_front_matter($raw);
            if (strtoupper((string)($fm['RFC_ID'] ?? '')) === $id) {
                $exact = $file;
                break;
            }
        }
        return $exact !== '' ? $exact : (string)$candidates[0];
    }
}

if (!function_exists('parse_rfc_front_matter')) {
    function parse_rfc_front_matter(string $md): array
    {
        $fm = [];
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
            if (preg_match('/^APPROVALS:\s*$/', $ln) === 1) {
                $inApprovals = true;
                if (!isset($fm['APPROVALS']) || !is_array($fm['APPROVALS'])) {
                    $fm['APPROVALS'] = [];
                }
                continue;
            }
            if ($inApprovals) {
                if (preg_match('/^\-\s*ROLE:\s*"?([^"]+)"?\s*$/', $ln, $m) === 1) {
                    $fm['APPROVALS'][] = [
                        'ROLE' => strtoupper(trim((string)$m[1])),
                        'USERNAME' => '',
                        'APPROVED_AT' => '',
                        'NOTE' => '',
                    ];
                    continue;
                }
                if (preg_match('/^(USERNAME|APPROVED_AT|NOTE):\s*"?(.+?)"?\s*$/', $ln, $m) === 1) {
                    $idx = count((array)($fm['APPROVALS'] ?? [])) - 1;
                    if ($idx >= 0) {
                        $fm['APPROVALS'][$idx][(string)$m[1]] = trim((string)$m[2]);
                    }
                    continue;
                }
                if (preg_match('/^[A-Z_]+\:/', $ln) === 1) {
                    $inApprovals = false;
                }
            }
            if (preg_match('/^(RFC_ID|TITLE|TYPE|STATUS|TARGET_ENV|SCHEDULE|CREATED_BY|REQUIRES_APPROVALS):\s*(.+)$/', $ln, $m) === 1) {
                $k = (string)$m[1];
                $v = trim((string)$m[2], "\"'");
                if ($k === 'REQUIRES_APPROVALS') {
                    $v2 = trim((string)$m[2]);
                    $vals = [];
                    if (str_starts_with($v2, '[') && str_ends_with($v2, ']')) {
                        $inner = trim(substr($v2, 1, -1));
                        foreach (explode(',', $inner) as $part) {
                            $p = strtoupper(trim((string)$part, " \t\n\r\0\x0B\"'"));
                            if ($p !== '') {
                                $vals[] = $p;
                            }
                        }
                    }
                    $fm[$k] = array_values(array_unique($vals));
                } else {
                    $fm[$k] = $v;
                }
            }
        }
        return $fm;
    }
}

if (!function_exists('rns_body_from_rfc_md')) {
    function rns_body_from_rfc_md(string $md): string
    {
        $marker = '<!-- RFC_BODY_START -->';
        $pos = strpos($md, $marker);
        if ($pos === false) {
            return $md;
        }
        return ltrim((string)substr($md, $pos + strlen($marker)), "\r\n");
    }
}

if (!function_exists('extract_section_by_heading')) {
    function extract_section_by_heading(string $md, string $heading): string
    {
        $pattern = '/^##\s+' . preg_quote($heading, '/') . '\s*$(.*?)(?=^##\s+|\z)/ms';
        if (preg_match($pattern, $md, $m) === 1) {
            return trim((string)$m[1]);
        }
        return '';
    }
}

if (!function_exists('rns_extract_subsection')) {
    function rns_extract_subsection(string $section, string $subheading): string
    {
        $pattern = '/^###\s+' . preg_quote($subheading, '/') . '\s*$(.*?)(?=^###\s+|\z)/ms';
        if (preg_match($pattern, $section, $m) === 1) {
            return trim((string)$m[1]);
        }
        return '';
    }
}

if (!function_exists('fallback_heading_map')) {
    function fallback_heading_map(): array
    {
        return [
            'Rollback Plan' => ['Rollback'],
            'Risk Assessment' => ['Risks'],
            'Execution Steps' => ['Steps'],
        ];
    }
}

if (!function_exists('rns_get_section_best_effort')) {
    function rns_get_section_best_effort(string $body, string $heading, array &$notes, bool &$usedFallback): string
    {
        $v = extract_section_by_heading($body, $heading);
        if ($v !== '') {
            return $v;
        }
        $map = fallback_heading_map();
        foreach ((array)($map[$heading] ?? []) as $alt) {
            $v2 = extract_section_by_heading($body, (string)$alt);
            if ($v2 !== '') {
                $usedFallback = true;
                $notes[] = 'fallback_heading_used:' . $heading . '<-' . $alt;
                return $v2;
            }
        }
        return '';
    }
}

if (!function_exists('rns_tbd')) {
    function rns_tbd(string $raw, string $sectionKey, array &$missing): string
    {
        $t = trim($raw);
        if ($t === '') {
            $missing[] = $sectionKey;
            return 'TBD';
        }
        return rns_mask_text($t);
    }
}

if (!function_exists('build_skeleton_json')) {
    function build_skeleton_json(array $data): array
    {
        return [
            'state_version' => 1,
            'generated_at' => (string)$data['generated_at'],
            'env' => (string)$data['env'],
            'run_id' => (string)$data['run_id'],
            'mode' => (string)$data['mode'],
            'rfc' => (array)$data['rfc'],
            'sections' => (array)$data['sections'],
            'insert_points' => [
                'GATES_TABLE',
                'CHANGES_BY_MODULE',
                'FINAL_GO_NO_GO',
                'APPROVALS_STATUS',
                'EVIDENCE_LINKS',
            ],
            'parse_report' => (array)$data['parse_report'],
        ];
    }
}

if (!function_exists('build_skeleton_md')) {
    function build_skeleton_md(array $data): string
    {
        $rfc = (array)$data['rfc'];
        $s = (array)$data['sections'];
        $lines = [];
        $lines[] = '# Release Notes (DRAFT Skeleton) - ' . rns_mask_text((string)$rfc['id']) . ' - ' . rns_mask_text((string)$data['env']);
        $lines[] = '';
        $lines[] = 'Generated At: ' . rns_mask_text((string)$data['generated_at']);
        $lines[] = 'Run ID: ' . rns_mask_text((string)$data['run_id']);
        $lines[] = 'RFC Title: ' . rns_mask_text((string)$rfc['title']);
        $lines[] = 'RFC Type: ' . rns_mask_text((string)$rfc['type']);
        $lines[] = 'RFC Status: ' . rns_mask_text((string)$rfc['status']);
        $lines[] = 'Schedule: ' . rns_mask_text((string)($rfc['schedule'] ?? 'TBD'));
        $lines[] = '';
        $lines[] = '## Executive Summary';
        $lines[] = '[AUTO_FROM_RFC: Summary]';
        $lines[] = (string)($s['summary']['text'] ?? 'TBD');
        $lines[] = '';
        $lines[] = '## Scope';
        $lines[] = '### In Scope';
        $lines[] = '[AUTO_FROM_RFC: Scope/In Scope]';
        $lines[] = (string)($s['scope_in']['text'] ?? 'TBD');
        $lines[] = '';
        $lines[] = '### Out of Scope';
        $lines[] = '[AUTO_FROM_RFC: Scope/Out of Scope]';
        $lines[] = (string)($s['scope_out']['text'] ?? 'TBD');
        $lines[] = '';
        $lines[] = '## Gates & Quality (AUTO-FILL BY PIPELINE)';
        $lines[] = '[AUTO_INSERT_POINT: GATES_TABLE]';
        $lines[] = '- Contract Check: TBD';
        $lines[] = '- Smoke HTTP STRICT: TBD';
        $lines[] = '- Core Flows: TBD';
        $lines[] = '- Readiness Score: TBD';
        $lines[] = '- GO/NO-GO: TBD';
        $lines[] = '';
        $lines[] = '## Changes by Module (AUTO-FILL BY IMPLEMENTATION)';
        $lines[] = '[AUTO_INSERT_POINT: CHANGES_BY_MODULE]';
        $lines[] = '- STOCK: TBD';
        $lines[] = '- SALES: TBD';
        $lines[] = '- PURCHASES: TBD';
        $lines[] = '- FINANCE: TBD';
        $lines[] = '- BANK/TAX: TBD';
        $lines[] = '- REPORTS: TBD';
        $lines[] = '- MOBILE: TBD';
        $lines[] = '- OPS/TOOLS: TBD';
        $lines[] = '- CI/RELEASE: TBD';
        $lines[] = '';
        $lines[] = '## Risk Assessment';
        $lines[] = '[AUTO_FROM_RFC: Risk Assessment]';
        $lines[] = (string)($s['risk']['text'] ?? 'TBD');
        $lines[] = '';
        $lines[] = '## Rollback Plan';
        $lines[] = '[AUTO_FROM_RFC: Rollback Plan]';
        $lines[] = (string)($s['rollback']['text'] ?? 'TBD');
        $lines[] = 'Note: Sensitive paths must be masked to [APP_ROOT]. Secrets redacted.';
        $lines[] = '';
        $lines[] = '## Evidence Plan';
        $lines[] = '[AUTO_FROM_RFC: Evidence Plan]';
        $lines[] = (string)($s['evidence_plan']['text'] ?? 'TBD');
        $lines[] = '';
        $lines[] = '## Go/No-Go Criteria';
        $lines[] = '[AUTO_FROM_RFC: Go/No-Go Criteria]';
        $lines[] = (string)($s['go_no_go']['text'] ?? 'TBD');
        $lines[] = '[AUTO_INSERT_POINT: FINAL_GO_NO_GO]';
        $lines[] = '';
        $lines[] = '## Execution Steps';
        $lines[] = '### Pre-Deploy';
        $lines[] = '[AUTO_FROM_RFC: Execution Steps/Pre-Deploy]';
        $lines[] = (string)($s['steps_pre']['text'] ?? 'TBD');
        $lines[] = '';
        $lines[] = '### Deploy';
        $lines[] = '[AUTO_FROM_RFC: Execution Steps/Deploy]';
        $lines[] = (string)($s['steps_deploy']['text'] ?? 'TBD');
        $lines[] = '';
        $lines[] = '### Post-Deploy';
        $lines[] = '[AUTO_FROM_RFC: Execution Steps/Post-Deploy]';
        $lines[] = (string)($s['steps_post']['text'] ?? 'TBD');
        $lines[] = '';
        $lines[] = '## Hypercare Plan';
        $lines[] = '[AUTO_FROM_RFC: Hypercare Plan]';
        $lines[] = (string)($s['hypercare']['text'] ?? 'TBD');
        $lines[] = '';
        $lines[] = '## Approvals';
        $lines[] = '[AUTO_FROM_RFC: Approvals]';
        $lines[] = (string)($s['approvals_text']['text'] ?? 'TBD');
        $lines[] = '[AUTO_INSERT_POINT: APPROVALS_STATUS]';
        $lines[] = '';
        $lines[] = '## Evidence Links (AUTO-FILL BY PIPELINE)';
        $lines[] = '[AUTO_INSERT_POINT: EVIDENCE_LINKS]';
        $lines[] = '- executive_ops_summary_last.html';
        $lines[] = '- readiness_report_last.md';
        $lines[] = '- smoke_http_last.json';
        $lines[] = '- contract_check_last.json';
        $lines[] = '- smoke_core_flows_last.json';
        $lines[] = '- release_pack_<...>.zip';
        $lines[] = '';
        $lines[] = '## Sign-off';
        $lines[] = 'Release Engineer: ____________________  Date: ________';
        $lines[] = 'Engineering Lead: ____________________ Date: ________';
        $lines[] = 'Security Lead: _______________________ Date: ________';
        $lines[] = 'QA Lead: ____________________________ Date: ________';
        $lines[] = 'Business Approver: ___________________ Date: ________';
        return implode("\n", $lines) . "\n";
    }
}

