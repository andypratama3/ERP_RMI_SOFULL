<?php
declare(strict_types=1);

require_once __DIR__ . '/rfc_markdown_parse.php';

if (!function_exists('rfclint_root')) {
    function rfclint_root(): string
    {
        return rfc_root();
    }
}

if (!function_exists('rfclint_mask')) {
    function rfclint_mask(string $text): string
    {
        return rfcmd_mask($text);
    }
}

if (!function_exists('rfclint_actor')) {
    function rfclint_actor(): string
    {
        $actor = trim((string)(getenv('CI_ACTOR') ?: ''));
        if ($actor !== '') return $actor;
        return 'SYSTEM';
    }
}

if (!function_exists('rfclint_pipeline_dir')) {
    function rfclint_pipeline_dir(): string
    {
        $dir = rfclint_root() . '/storage/logs/pipeline';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('rfclint_resolve_run_id')) {
    function rfclint_resolve_run_id(string $runId): string
    {
        if ($runId !== '' && strtolower($runId) !== 'auto') return $runId;
        $last = ts_read_json(rfclint_pipeline_dir() . '/pipeline_last.json');
        $v = trim((string)($last['run_id'] ?? ''));
        if ($v !== '') return $v;
        return 'LOCAL_' . date('YmdHis');
    }
}

if (!function_exists('rfclint_discover_files')) {
    function rfclint_discover_files(string $scope, string $rfcId, string $range): array
    {
        $dir = rfclint_root() . '/docs/governance/RFC';
        $files = glob($dir . '/RFC-*.md') ?: [];
        $filtered = [];
        foreach ($files as $file) {
            $base = basename($file);
            if (str_starts_with($base, 'TEMPLATE_')) continue;
            if (strtoupper($base) === 'TEMPLATE_RFC.MD') continue;
            $filtered[] = $file;
        }
        $files = $filtered;

        if ($scope === 'rfc-id') {
            $target = strtoupper(trim($rfcId));
            $match = [];
            foreach ($files as $file) {
                $parsed = rfcmd_read_file($file);
                if (!$parsed['ok']) continue;
                $id = strtoupper(trim((string)($parsed['front_matter']['RFC_ID'] ?? '')));
                if ($id === $target) $match[] = $file;
            }
            sort($match, SORT_STRING);
            return $match;
        }

        if ($range === 'last30d') {
            $minTs = strtotime('-30 days') ?: 0;
            $rangeFiles = [];
            foreach ($files as $file) {
                $parsed = rfcmd_read_file($file);
                if (!$parsed['ok']) continue;
                $created = (string)($parsed['front_matter']['CREATED_AT'] ?? '');
                $ts = strtotime($created);
                if ($ts !== false && $ts >= $minTs) $rangeFiles[] = $file;
            }
            $files = $rangeFiles;
        }
        sort($files, SORT_STRING);
        return $files;
    }
}

if (!function_exists('rfclint_add_msg')) {
    function rfclint_add_msg(array &$bucket, string $code, string $message): void
    {
        $bucket[] = ['code' => $code, 'message' => rfclint_mask($message)];
    }
}

if (!function_exists('rfclint_section_filled')) {
    function rfclint_section_filled(string $text): bool
    {
        $n = preg_replace('/\s+/', '', $text) ?? '';
        return strlen($n) >= 30;
    }
}

if (!function_exists('rfclint_find_canonical_sections')) {
    function rfclint_find_canonical_sections(array $blocks): array
    {
        $fallbackUsed = false;
        $map = [
            'summary' => ['level' => 2, 'title' => 'Summary', 'fallback' => []],
            'scope' => ['level' => 2, 'title' => 'Scope', 'fallback' => []],
            'risk' => ['level' => 2, 'title' => 'Risk Assessment', 'fallback' => ['Risks']],
            'rollback' => ['level' => 2, 'title' => 'Rollback Plan', 'fallback' => ['Rollback']],
            'evidence' => ['level' => 2, 'title' => 'Evidence Plan', 'fallback' => ['Evidence']],
            'go_no_go' => ['level' => 2, 'title' => 'Go/No-Go Criteria', 'fallback' => []],
            'steps' => ['level' => 2, 'title' => 'Execution Steps', 'fallback' => ['Steps']],
            'hypercare' => ['level' => 2, 'title' => 'Hypercare Plan', 'fallback' => []],
            'approvals' => ['level' => 2, 'title' => 'Approvals', 'fallback' => []],
            'links' => ['level' => 2, 'title' => 'Links', 'fallback' => []],
            'scope_in' => ['level' => 3, 'title' => 'In Scope', 'fallback' => []],
            'scope_out' => ['level' => 3, 'title' => 'Out of Scope', 'fallback' => []],
            'steps_pre' => ['level' => 3, 'title' => 'Pre-Deploy', 'fallback' => []],
            'steps_deploy' => ['level' => 3, 'title' => 'Deploy', 'fallback' => []],
            'steps_post' => ['level' => 3, 'title' => 'Post-Deploy', 'fallback' => []],
        ];
        $sections = [];
        $missingHeadings = [];
        foreach ($map as $key => $cfg) {
            $text = rfcmd_get_block_text($blocks, (int)$cfg['level'], (string)$cfg['title']);
            if ($text === '') {
                foreach ((array)$cfg['fallback'] as $alt) {
                    $altText = rfcmd_get_block_text($blocks, (int)$cfg['level'], (string)$alt);
                    if ($altText !== '') {
                        $text = $altText;
                        $fallbackUsed = true;
                        break;
                    }
                }
            }
            if ($text === '') {
                $missingHeadings[] = (string)$cfg['title'];
            }
            $sections[$key] = $text;
        }
        return ['sections' => $sections, 'missing_headings' => $missingHeadings, 'fallback_used' => $fallbackUsed];
    }
}

if (!function_exists('rfclint_approvals_summary')) {
    function rfclint_approvals_summary(array $fm): array
    {
        $required = [];
        foreach ((array)($fm['REQUIRES_APPROVALS'] ?? []) as $r) {
            $v = strtoupper(trim((string)$r));
            if ($v !== '') $required[] = $v;
        }
        $required = array_values(array_unique($required));
        $completed = [];
        $records = [];
        foreach ((array)($fm['APPROVALS'] ?? []) as $a) {
            if (!is_array($a)) continue;
            $role = strtoupper(trim((string)($a['ROLE'] ?? '')));
            $user = trim((string)($a['USERNAME'] ?? ''));
            $note = trim((string)($a['NOTE'] ?? ''));
            if ($role !== '') $completed[] = $role;
            $records[] = ['role' => $role, 'username' => $user, 'note' => $note];
        }
        $completed = array_values(array_unique($completed));
        $missing = [];
        foreach ($required as $r) {
            if (!in_array($r, $completed, true)) $missing[] = $r;
        }
        return ['required' => $required, 'completed' => $completed, 'missing' => $missing, 'records' => $records];
    }
}

if (!function_exists('rfclint_scan_leaks')) {
    function rfclint_scan_leaks(string $raw): array
    {
        $patterns = ['Authorization:', 'BEGIN PRIVATE KEY', 'DB_PASS', '/Users/', 'C:\\'];
        $hits = [];
        foreach ($patterns as $p) {
            if (stripos($raw, $p) !== false) {
                $hits[] = $p;
            }
        }
        return array_values(array_unique($hits));
    }
}

if (!function_exists('rfclint_lint_one')) {
    function rfclint_lint_one(string $file, string $env, string $mode, bool $strict): array
    {
        $parsed = rfcmd_read_file($file);
        $fails = [];
        $warns = [];
        $infos = [];
        $suggestions = [];

        if (!$parsed['ok']) {
            rfclint_add_msg($fails, 'RFC_PARSE_FAIL', 'RFC parsing failed');
            return [
                'id' => 'UNKNOWN',
                'title' => '',
                'type' => '',
                'status' => '',
                'target_env' => '',
                'coverage_percent' => 0,
                'result' => 'FAIL',
                'fails' => $fails,
                'warns' => $warns,
                'infos' => $infos,
                'file_rel_path' => rfclint_mask(ltrim(str_replace(rfclint_root(), '', $file), '/')),
                'suggestions' => [['action' => 'FIX_PARSE', 'hint' => 'Ensure front-matter and body marker are valid']],
                'missing_headings' => [],
                'missing_approvals' => [],
            ];
        }

        $fm = (array)$parsed['front_matter'];
        $body = (string)$parsed['body'];
        $raw = (string)$parsed['raw'];
        $blocks = rfcmd_find_heading_blocks($body);
        $heading = rfclint_find_canonical_sections($blocks);
        $sections = (array)$heading['sections'];
        $missingHeadings = (array)$heading['missing_headings'];

        $id = strtoupper(trim((string)($fm['RFC_ID'] ?? '')));
        $title = trim((string)($fm['TITLE'] ?? ''));
        $type = strtoupper(trim((string)($fm['TYPE'] ?? '')));
        $status = strtoupper(trim((string)($fm['STATUS'] ?? '')));
        $targetEnv = strtolower(trim((string)($fm['TARGET_ENV'] ?? '')));
        $createdBy = trim((string)($fm['CREATED_BY'] ?? ''));
        $createdAt = trim((string)($fm['CREATED_AT'] ?? ''));
        $schedule = trim((string)($fm['SCHEDULE'] ?? ''));

        // C1 front matter required
        foreach (['RFC_ID', 'TITLE', 'TYPE', 'STATUS', 'CREATED_AT', 'CREATED_BY'] as $key) {
            if (trim((string)($fm[$key] ?? '')) === '') {
                rfclint_add_msg($fails, 'MISSING_FRONTMATTER_' . $key, 'Missing front-matter field: ' . $key);
                $suggestions[] = ['action' => 'ADD_FRONTMATTER', 'target' => $key, 'hint' => 'Fill required field'];
            }
        }
        if ($targetEnv === '') {
            rfclint_add_msg($warns, 'TARGET_ENV_MISSING', 'TARGET_ENV is missing');
            $suggestions[] = ['action' => 'ADD_FRONTMATTER', 'target' => 'TARGET_ENV', 'hint' => 'Set staging or production'];
        }

        // C2 STATUS
        $allowedStatus = ['DRAFT', 'IN_REVIEW', 'APPROVED', 'IMPLEMENTED', 'CLOSED', 'REJECTED'];
        if (!in_array($status, $allowedStatus, true)) {
            rfclint_add_msg($fails, 'STATUS_INVALID', 'Invalid STATUS');
        }

        // C3 approvals
        $approval = rfclint_approvals_summary($fm);
        $requiresMissing = ($approval['required'] === []);
        if (in_array($status, ['IN_REVIEW', 'APPROVED', 'IMPLEMENTED', 'CLOSED'], true) && $requiresMissing) {
            rfclint_add_msg($fails, 'REQUIRES_APPROVALS_MISSING', 'REQUIRES_APPROVALS is required for this STATUS');
            $suggestions[] = ['action' => 'COMPLETE_APPROVALS', 'target' => 'REQUIRES_APPROVALS', 'hint' => 'Set required roles'];
        }
        if ($status === 'APPROVED' && (array)$approval['missing'] !== []) {
            rfclint_add_msg($fails, 'APPROVALS_INCOMPLETE', 'APPROVED status but required approvals are missing');
            $suggestions[] = ['action' => 'COMPLETE_APPROVALS', 'hint' => 'Missing roles: ' . implode(', ', (array)$approval['missing'])];
        }
        if (in_array($status, ['IMPLEMENTED', 'CLOSED'], true) && (array)$approval['missing'] !== []) {
            rfclint_add_msg($warns, 'APPROVAL_HISTORY_INCOMPLETE', 'Status indicates post-approval phase but approvals appear incomplete');
        }
        if ($status === 'DRAFT' && (array)$approval['completed'] === []) {
            rfclint_add_msg($infos, 'DRAFT_APPROVALS_EMPTY_OK', 'Draft RFC can have empty approvals');
        }

        // C4 maker-checker
        foreach ((array)$approval['records'] as $rec) {
            $role = strtoupper(trim((string)($rec['role'] ?? '')));
            $user = trim((string)($rec['username'] ?? ''));
            $note = strtolower(trim((string)($rec['note'] ?? '')));
            if ($createdBy !== '' && $user !== '' && strcasecmp($createdBy, $user) === 0) {
                $isException = ($role === 'SUPERADMIN' && str_contains($note, 'exception'));
                if ($isException) {
                    rfclint_add_msg($warns, 'MAKER_CHECKER_EXCEPTION', 'Creator approved own RFC via SUPERADMIN exception');
                } else {
                    if ($mode === 'full') rfclint_add_msg($fails, 'MAKER_CHECKER_VIOLATION', 'Creator cannot approve own RFC');
                    else rfclint_add_msg($warns, 'MAKER_CHECKER_VIOLATION', 'Creator cannot approve own RFC');
                }
            }
            if ($mode === 'full' && $role !== '' && (array)$approval['required'] !== [] && !in_array($role, (array)$approval['required'], true)) {
                rfclint_add_msg($warns, 'APPROVAL_ROLE_EXTRA', 'Approver role is outside REQUIRES_APPROVALS');
            }
        }

        // C5 headings
        if ($missingHeadings !== []) {
            rfclint_add_msg($fails, 'MISSING_HEADINGS', 'Missing canonical headings: ' . implode(', ', $missingHeadings));
            $suggestions[] = ['action' => 'ADD_SECTION', 'target' => 'Missing Headings', 'hint' => implode(', ', $missingHeadings)];
        }
        if ((bool)$heading['fallback_used']) {
            rfclint_add_msg($warns, 'HEADING_FALLBACK_USED', 'Fallback mapping used for one or more headings');
        }

        // C6 coverage
        $coverageKeys = ['summary', 'scope_in', 'scope_out', 'risk', 'rollback', 'evidence', 'go_no_go', 'steps_pre', 'steps_deploy', 'steps_post', 'hypercare', 'approvals'];
        $filled = 0;
        foreach ($coverageKeys as $k) {
            if (rfclint_section_filled((string)($sections[$k] ?? ''))) $filled++;
        }
        $coverage = (int)floor(($filled / count($coverageKeys)) * 100);
        if ($coverage < 60) {
            if ($strict) rfclint_add_msg($fails, 'COVERAGE_LT_60', 'Coverage below 60%');
            else rfclint_add_msg($warns, 'COVERAGE_LT_60', 'Coverage below 60%');
            $suggestions[] = ['action' => 'FILL_SECTIONS', 'hint' => 'Add meaningful content to required sections'];
        }
        if (trim((string)($sections['rollback'] ?? '')) === '') {
            rfclint_add_msg($fails, 'ROLLBACK_EMPTY', 'Rollback Plan is mandatory');
            $suggestions[] = ['action' => 'ADD_SECTION', 'target' => 'Rollback Plan', 'hint' => 'Provide rollback steps'];
        }
        if (trim((string)($sections['go_no_go'] ?? '')) === '') {
            if ($strict) rfclint_add_msg($fails, 'GO_NO_GO_EMPTY', 'Go/No-Go Criteria is mandatory in strict mode');
            else rfclint_add_msg($warns, 'GO_NO_GO_EMPTY', 'Go/No-Go Criteria is empty');
        }
        if (trim((string)($sections['risk'] ?? '')) === '') {
            if ($strict && $env === 'production') rfclint_add_msg($fails, 'RISK_EMPTY', 'Risk Assessment is required for production strict');
            else rfclint_add_msg($warns, 'RISK_EMPTY', 'Risk Assessment is empty');
        }

        // C7 leak
        $leaks = rfclint_scan_leaks($raw);
        if ($leaks !== []) {
            rfclint_add_msg($fails, 'LEAK_PATTERN_FOUND', 'Deny patterns found: ' . implode(', ', $leaks));
            $suggestions[] = ['action' => 'MASK_PATHS', 'hint' => 'Replace absolute paths with [APP_ROOT] and redact secrets'];
        }

        // C8 schedule sanity (full)
        if ($mode === 'full') {
            if ($targetEnv === 'production' && in_array($status, ['IN_REVIEW', 'APPROVED'], true) && $schedule === '') {
                rfclint_add_msg($fails, 'SCHEDULE_REQUIRED', 'SCHEDULE is required for production RFC in review/approved state');
            } elseif ($schedule !== '' && strtotime($schedule) === false) {
                rfclint_add_msg($warns, 'SCHEDULE_PARSE_WARN', 'SCHEDULE is not parseable');
            }

            $rollbackText = trim((string)($sections['rollback'] ?? ''));
            if ($rollbackText !== '') {
                $commandLike = false;
                foreach (preg_split('/\r\n|\r|\n/', $rollbackText) ?: [] as $line) {
                    $ln = ltrim((string)$line);
                    if (preg_match('/^(php\s+|bash\s+|sql\s+|APP_ENV=)/', $ln) === 1) {
                        $commandLike = true;
                        break;
                    }
                }
                if (!$commandLike) {
                    if ($strict && $env === 'production') rfclint_add_msg($fails, 'ROLLBACK_COMMAND_HEURISTIC_MISSING', 'Rollback lacks command-like steps');
                    else rfclint_add_msg($warns, 'ROLLBACK_COMMAND_HEURISTIC_MISSING', 'Rollback lacks command-like steps');
                }
            }
        }

        // C9 links
        $linksText = trim((string)($sections['links'] ?? ''));
        $hasLink = false;
        foreach (preg_split('/\r\n|\r|\n/', $linksText) ?: [] as $line) {
            if (str_contains((string)$line, 'http://') || str_contains((string)$line, 'https://') || str_starts_with(trim((string)$line), '- ')) {
                $hasLink = true;
                break;
            }
        }
        if (!$hasLink) {
            rfclint_add_msg($warns, 'LINKS_SECTION_WEAK', 'Links section should include at least one actionable link entry');
        }

        // C10 evidence quality
        $ev = strtolower((string)($sections['evidence'] ?? ''));
        $kw = ['smoke', 'contract', 'readiness', 'release pack', 'logs'];
        $hits = 0;
        foreach ($kw as $k) {
            if (str_contains($ev, $k)) $hits++;
        }
        if (trim($ev) === '' || $hits < 2) {
            if ($strict && $env === 'production') rfclint_add_msg($fails, 'EVIDENCE_PLAN_WEAK', 'Evidence Plan is empty or too generic');
            else rfclint_add_msg($warns, 'EVIDENCE_PLAN_WEAK', 'Evidence Plan should mention at least two artifacts');
        }

        $result = 'PASS';
        if ($fails !== []) $result = 'FAIL';
        elseif ($warns !== []) $result = 'WARN';

        return [
            'id' => $id !== '' ? $id : 'UNKNOWN',
            'title' => rfclint_mask($title),
            'type' => rfclint_mask($type),
            'status' => rfclint_mask($status),
            'target_env' => rfclint_mask($targetEnv),
            'coverage_percent' => $coverage,
            'result' => $result,
            'fails' => $fails,
            'warns' => $warns,
            'infos' => $infos,
            'file_rel_path' => rfclint_mask(ltrim(str_replace(rfclint_root(), '', $file), '/')),
            'suggestions' => array_values($suggestions),
            'missing_headings' => array_values($missingHeadings),
            'missing_approvals' => array_values((array)$approval['missing']),
        ];
    }
}

if (!function_exists('rfclint_render_md')) {
    function rfclint_render_md(array $payload): string
    {
        $lines = [];
        $lines[] = '# RFC Quality Lint Report';
        $lines[] = '';
        $lines[] = '- generated_at: ' . rfclint_mask((string)($payload['generated_at'] ?? ''));
        $lines[] = '- env: ' . rfclint_mask((string)($payload['env'] ?? ''));
        $lines[] = '- run_id: ' . rfclint_mask((string)($payload['run_id'] ?? ''));
        $lines[] = '- strict: ' . ((bool)($payload['strict'] ?? false) ? 'true' : 'false');
        $lines[] = '- mode: ' . rfclint_mask((string)($payload['mode'] ?? ''));
        $s = (array)($payload['summary'] ?? []);
        $lines[] = '';
        $lines[] = '| RFC Count | FAIL | WARN | INFO | Coverage Avg |';
        $lines[] = '|---:|---:|---:|---:|---:|';
        $lines[] = '| ' . (int)($s['rfc_count'] ?? 0) . ' | ' . (int)($s['fail_count'] ?? 0) . ' | ' . (int)($s['warn_count'] ?? 0) . ' | ' . (int)($s['info_count'] ?? 0) . ' | ' . (float)($s['coverage_avg'] ?? 0) . '% |';
        $lines[] = '';
        $lines[] = '## Top FAIL (Deploy Blockers)';
        $hasFail = false;
        foreach ((array)($payload['rfcs'] ?? []) as $r) {
            if ((string)($r['result'] ?? '') !== 'FAIL') continue;
            $hasFail = true;
            $lines[] = '- ' . rfclint_mask((string)($r['id'] ?? 'UNKNOWN')) . ': '
                . implode('; ', array_map(static fn($f): string => (string)($f['code'] ?? ''), (array)($r['fails'] ?? [])));
        }
        if (!$hasFail) $lines[] = '- none';

        $lines[] = '';
        $lines[] = '## Per RFC Checklist';
        foreach ((array)($payload['rfcs'] ?? []) as $r) {
            $lines[] = '';
            $lines[] = '### ' . rfclint_mask((string)($r['id'] ?? 'UNKNOWN')) . ' - ' . rfclint_mask((string)($r['result'] ?? 'UNKNOWN'));
            $lines[] = '- Coverage: ' . (float)($r['coverage_percent'] ?? 0) . '%';
            $lines[] = '- Missing approvals: ' . rfclint_mask(implode(', ', (array)($r['missing_approvals'] ?? [])));
            $lines[] = '- Missing headings: ' . rfclint_mask(implode(', ', (array)($r['missing_headings'] ?? [])));
            $lines[] = '- Secret/path leak check: ' . (((array)($r['fails'] ?? []) !== [] && in_array('LEAK_PATTERN_FOUND', array_map(static fn($f): string => (string)($f['code'] ?? ''), (array)($r['fails'] ?? [])), true)) ? 'FAIL' : 'OK');
            if ((array)($r['fails'] ?? []) !== []) {
                $lines[] = '- FAIL codes: ' . rfclint_mask(implode(', ', array_map(static fn($f): string => (string)($f['code'] ?? ''), (array)$r['fails'])));
            }
            if ((array)($r['warns'] ?? []) !== []) {
                $lines[] = '- WARN codes: ' . rfclint_mask(implode(', ', array_map(static fn($w): string => (string)($w['code'] ?? ''), (array)$r['warns'])));
            }
        }

        $lines[] = '';
        $lines[] = '## How To Fix (Quick)';
        $lines[] = '- Add missing canonical headings exactly as RFC template.';
        $lines[] = '- Ensure rollback plan has explicit command-like steps.';
        $lines[] = '- Complete missing approvals for required roles before APPROVED.';
        $lines[] = '- Replace absolute paths with [APP_ROOT] and redact secrets with [REDACTED].';
        $lines[] = '- Improve coverage to >= 60% by filling required sections.';
        return implode("\n", $lines) . "\n";
    }
}

