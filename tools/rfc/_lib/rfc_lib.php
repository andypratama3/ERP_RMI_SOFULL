<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_state_lib.php';
require_once __DIR__ . '/../../tools_ui_helpers.php';
require_once __DIR__ . '/../../../_shared/bootstrap.php';
require_once __DIR__ . '/../../../_shared/erp_audit.php';

if (!function_exists('rfc_root')) {
    function rfc_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('rfc_mask')) {
    function rfc_mask(string $text): string
    {
        return ts_mask(tools_mask_sensitive($text));
    }
}

if (!function_exists('rfc_dir')) {
    function rfc_dir(): string
    {
        $dir = rfc_root() . '/docs/governance/RFC';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('rfc_decisions_dir')) {
    function rfc_decisions_dir(): string
    {
        $dir = rfc_dir() . '/DECISIONS';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('rfc_audit_log_path')) {
    function rfc_audit_log_path(): string
    {
        return rfc_root() . '/storage/logs/audit_rfc.jsonl';
    }
}

if (!function_exists('rfc_pipeline_dir')) {
    function rfc_pipeline_dir(): string
    {
        $dir = rfc_root() . '/storage/logs/pipeline';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('rfc_state_cache_path')) {
    function rfc_state_cache_path(): string
    {
        return rfc_root() . '/storage/state/rfc_cache.json';
    }
}

if (!function_exists('rfc_required_types')) {
    function rfc_required_types(): array
    {
        return [
            'PRODUCTION_DEPLOY',
            'DB_MIGRATION_APPLY',
            'RESTORE_APPLY',
            'OPS_POLICY_CHANGE',
            'SECURITY_HARDENING_APPLY',
            'RBAC_CHANGE',
            'MOBILE_RELEASE',
        ];
    }
}

if (!function_exists('rfc_optional_types')) {
    function rfc_optional_types(): array
    {
        return ['UI_COPY_CHANGE', 'DOCS_ONLY', 'STAGING_EXPERIMENT'];
    }
}

if (!function_exists('rfc_statuses')) {
    function rfc_statuses(): array
    {
        return ['DRAFT', 'IN_REVIEW', 'APPROVED', 'IMPLEMENTED', 'CLOSED', 'REJECTED'];
    }
}

if (!function_exists('rfc_approval_matrix')) {
    function rfc_approval_matrix(): array
    {
        return [
            'PRODUCTION_DEPLOY' => ['RELEASE', 'ENG_LEAD', 'QA', 'SECURITY', 'BUSINESS'],
            'DB_MIGRATION_APPLY' => ['ENG_LEAD', 'DBA_OWNER', 'QA'],
            'RESTORE_APPLY' => ['RELEASE', 'ENG_LEAD', 'SECURITY'],
            'OPS_POLICY_CHANGE' => ['OPS_OWNER', 'ENG_LEAD'],
            'SECURITY_HARDENING_APPLY' => ['SECURITY', 'ENG_LEAD'],
            'MOBILE_RELEASE' => ['MOBILE_LEAD', 'QA', 'RELEASE'],
            'RBAC_CHANGE' => ['SECURITY', 'ENG_LEAD', 'QA'],
        ];
    }
}

if (!function_exists('rfc_slug')) {
    function rfc_slug(string $text): string
    {
        $t = strtoupper(trim($text));
        $t = preg_replace('/[^A-Z0-9]+/', '_', $t) ?? '';
        $t = trim($t, '_');
        return $t === '' ? 'UNTITLED' : $t;
    }
}

if (!function_exists('rfc_list_files')) {
    function rfc_list_files(): array
    {
        $files = glob(rfc_dir() . '/RFC-*.md') ?: [];
        sort($files);
        return $files;
    }
}

if (!function_exists('rfc_next_id')) {
    function rfc_next_id(int $year): array
    {
        $max = 0;
        foreach (rfc_list_files() as $file) {
            $name = basename($file);
            if (preg_match('/^RFC\-' . preg_quote((string)$year, '/') . '\-(\d{4})\-/i', $name, $m) === 1) {
                $max = max($max, (int)$m[1]);
            }
        }
        $next = $max + 1;
        $id = 'RFC-' . $year . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
        return ['id' => $id, 'num' => $next];
    }
}

if (!function_exists('rfc_frontmatter_defaults')) {
    function rfc_frontmatter_defaults(): array
    {
        return [
            'RFC_ID' => '',
            'TITLE' => '',
            'TYPE' => 'PRODUCTION_DEPLOY',
            'STATUS' => 'DRAFT',
            'SEVERITY' => 'MED',
            'CREATED_AT' => date(DateTimeInterface::ATOM),
            'CREATED_BY' => '',
            'TARGET_ENV' => 'staging',
            'SCHEDULE' => date('Y-m-d H:i T'),
            'AFFECTED_AREAS' => ['OPS'],
            'REQUIRES_APPROVALS' => ['ENG_LEAD', 'QA'],
            'APPROVALS' => [],
        ];
    }
}

if (!function_exists('rfc_parse_bracket_list')) {
    function rfc_parse_bracket_list(string $s): array
    {
        $v = trim($s);
        if (!str_starts_with($v, '[') || !str_ends_with($v, ']')) return [];
        $inner = trim(substr($v, 1, -1));
        if ($inner === '') return [];
        $parts = array_map('trim', explode(',', $inner));
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p, " \t\n\r\0\x0B\"'");
            if ($p !== '') $out[] = strtoupper($p);
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('rfc_format_bracket_list')) {
    function rfc_format_bracket_list(array $items): string
    {
        $norm = [];
        foreach ($items as $i) {
            $v = strtoupper(trim((string)$i));
            if ($v !== '') $norm[] = $v;
        }
        $norm = array_values(array_unique($norm));
        return '[' . implode(', ', $norm) . ']';
    }
}

if (!function_exists('rfc_parse_file')) {
    function rfc_parse_file(string $path): array
    {
        $res = [
            'ok' => false,
            'error' => 'missing',
            'path' => $path,
            'path_masked' => rfc_mask($path),
            'frontmatter' => rfc_frontmatter_defaults(),
            'body' => '',
            'errors' => [],
        ];
        if (!is_file($path)) return $res;
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            $res['error'] = 'empty';
            return $res;
        }
        $marker = '<!-- RFC_BODY_START -->';
        $pos = strpos($raw, $marker);
        if ($pos === false) {
            $res['error'] = 'marker_missing';
            return $res;
        }
        $head = trim(substr($raw, 0, $pos));
        $body = (string)substr($raw, $pos + strlen($marker));
        $fm = rfc_frontmatter_defaults();
        $lines = preg_split('/\r\n|\r|\n/', $head) ?: [];
        $inApprovals = false;
        $current = null;
        foreach ($lines as $line) {
            $ln = trim((string)$line);
            if ($ln === '') continue;
            if (preg_match('/^APPROVALS:\s*$/', $ln) === 1) {
                $inApprovals = true;
                if (!isset($fm['APPROVALS']) || !is_array($fm['APPROVALS'])) $fm['APPROVALS'] = [];
                continue;
            }
            if ($inApprovals) {
                if (preg_match('/^\-\s*ROLE:\s*"?([^"]+)"?\s*$/', $ln, $m) === 1) {
                    $current = [
                        'ROLE' => strtoupper(trim((string)$m[1])),
                        'USERNAME' => '',
                        'APPROVED_AT' => '',
                        'NOTE' => '',
                    ];
                    $fm['APPROVALS'][] = $current;
                    continue;
                }
                if (preg_match('/^(USERNAME|APPROVED_AT|NOTE):\s*"?(.+?)"?\s*$/', $ln, $m) === 1) {
                    if ($current === null || $fm['APPROVALS'] === []) continue;
                    $idx = count($fm['APPROVALS']) - 1;
                    $fm['APPROVALS'][$idx][(string)$m[1]] = trim((string)$m[2]);
                    continue;
                }
                if (preg_match('/^[A-Z_]+\:/', $ln) === 1) {
                    $inApprovals = false;
                }
            }
            if (preg_match('/^(RFC_ID|TITLE|TYPE|STATUS|SEVERITY|CREATED_AT|CREATED_BY|TARGET_ENV|SCHEDULE|AFFECTED_AREAS|REQUIRES_APPROVALS):\s*(.+)$/', $ln, $m) === 1) {
                $k = (string)$m[1];
                $v = trim((string)$m[2]);
                if (in_array($k, ['AFFECTED_AREAS', 'REQUIRES_APPROVALS'], true)) {
                    $fm[$k] = rfc_parse_bracket_list($v);
                } else {
                    $fm[$k] = trim($v, "\"'");
                }
            }
        }
        $res['ok'] = true;
        $res['error'] = '';
        $res['frontmatter'] = $fm;
        $res['body'] = ltrim($body, "\r\n");
        return $res;
    }
}

if (!function_exists('rfc_render_file')) {
    function rfc_render_file(array $fm, string $body): string
    {
        $lines = [];
        $lines[] = 'RFC_ID: ' . (string)$fm['RFC_ID'];
        $lines[] = 'TITLE: "' . str_replace('"', '\"', (string)$fm['TITLE']) . '"';
        $lines[] = 'TYPE: ' . (string)$fm['TYPE'];
        $lines[] = 'STATUS: ' . (string)$fm['STATUS'];
        $lines[] = 'SEVERITY: ' . (string)$fm['SEVERITY'];
        $lines[] = 'CREATED_AT: ' . (string)$fm['CREATED_AT'];
        $lines[] = 'CREATED_BY: ' . (string)$fm['CREATED_BY'];
        $lines[] = 'TARGET_ENV: ' . (string)$fm['TARGET_ENV'];
        $lines[] = 'SCHEDULE: "' . str_replace('"', '\"', (string)$fm['SCHEDULE']) . '"';
        $lines[] = 'AFFECTED_AREAS: ' . rfc_format_bracket_list((array)($fm['AFFECTED_AREAS'] ?? []));
        $lines[] = 'REQUIRES_APPROVALS: ' . rfc_format_bracket_list((array)($fm['REQUIRES_APPROVALS'] ?? []));
        $lines[] = 'APPROVALS:';
        foreach ((array)($fm['APPROVALS'] ?? []) as $a) {
            $lines[] = '  - ROLE: "' . str_replace('"', '\"', (string)($a['ROLE'] ?? '')) . '"';
            $lines[] = '    USERNAME: "' . str_replace('"', '\"', (string)($a['USERNAME'] ?? '')) . '"';
            $lines[] = '    APPROVED_AT: "' . str_replace('"', '\"', (string)($a['APPROVED_AT'] ?? '')) . '"';
            $lines[] = '    NOTE: "' . str_replace('"', '\"', (string)($a['NOTE'] ?? '')) . '"';
        }
        return implode("\n", $lines) . "\n\n<!-- RFC_BODY_START -->\n\n" . ltrim($body, "\r\n");
    }
}

if (!function_exists('rfc_atomic_write')) {
    function rfc_atomic_write(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
        $tmp = $path . '.tmp.' . substr(sha1((string)microtime(true)), 0, 8);
        if (@file_put_contents($tmp, $content) === false) return false;
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}

if (!function_exists('rfc_validate_frontmatter')) {
    function rfc_validate_frontmatter(array $fm, bool $strict = true): array
    {
        $errors = [];
        $requiredKeys = ['RFC_ID', 'TITLE', 'TYPE', 'STATUS', 'SEVERITY', 'CREATED_AT', 'CREATED_BY', 'TARGET_ENV', 'SCHEDULE', 'AFFECTED_AREAS', 'REQUIRES_APPROVALS', 'APPROVALS'];
        foreach ($requiredKeys as $k) {
            if (!array_key_exists($k, $fm)) $errors[] = 'missing:' . $k;
        }
        if (!preg_match('/^RFC\-\d{4}\-\d{4}$/', (string)($fm['RFC_ID'] ?? ''))) $errors[] = 'invalid:RFC_ID';
        if (!in_array((string)($fm['STATUS'] ?? ''), rfc_statuses(), true)) $errors[] = 'invalid:STATUS';
        if (!in_array((string)($fm['SEVERITY'] ?? ''), ['HIGH', 'MED', 'LOW'], true)) $errors[] = 'invalid:SEVERITY';
        $type = (string)($fm['TYPE'] ?? '');
        if (!in_array($type, array_merge(rfc_required_types(), rfc_optional_types()), true)) $errors[] = 'invalid:TYPE';
        $env = (string)($fm['TARGET_ENV'] ?? '');
        if (!in_array($env, ['staging', 'production'], true)) $errors[] = 'invalid:TARGET_ENV';
        if (strtotime((string)($fm['CREATED_AT'] ?? '')) === false) $errors[] = 'invalid:CREATED_AT';
        $areas = (array)($fm['AFFECTED_AREAS'] ?? []);
        if ($areas === []) $errors[] = 'invalid:AFFECTED_AREAS';
        $req = array_values(array_unique(array_map(static fn($v): string => strtoupper(trim((string)$v)), (array)($fm['REQUIRES_APPROVALS'] ?? []))));
        if ($req === []) {
            $req = (array)(rfc_approval_matrix()[$type] ?? []);
        }
        $fm['REQUIRES_APPROVALS'] = $req;
        $approvals = (array)($fm['APPROVALS'] ?? []);
        $approvedRoles = [];
        foreach ($approvals as $idx => $a) {
            $role = strtoupper(trim((string)($a['ROLE'] ?? '')));
            $user = trim((string)($a['USERNAME'] ?? ''));
            $at = trim((string)($a['APPROVED_AT'] ?? ''));
            if ($role === '' || $user === '' || $at === '') {
                $errors[] = 'invalid:APPROVALS:' . $idx;
                continue;
            }
            if (strtotime($at) === false) $errors[] = 'invalid:APPROVED_AT:' . $idx;
            if (in_array($role, $approvedRoles, true)) $errors[] = 'duplicate:APPROVAL_ROLE:' . $role;
            $approvedRoles[] = $role;
            if (strtolower($user) === strtolower((string)($fm['CREATED_BY'] ?? '')) && $role !== 'SUPERADMIN') {
                $errors[] = 'maker_checker_violation:' . $role;
            }
        }
        if ((string)($fm['STATUS'] ?? '') === 'APPROVED') {
            foreach ($req as $r) {
                if (!in_array($r, $approvedRoles, true)) {
                    $errors[] = 'approval_missing:' . $r;
                }
            }
        }
        if ((string)($fm['STATUS'] ?? '') === 'CLOSED' && (string)($fm['STATUS'] ?? '') !== 'APPROVED') {
            // no-op placeholder rule slot
        }
        if ($strict) {
            $forbidden = ['password', 'token', 'secret', 'db_pass', 'authorization:'];
            $joined = strtolower(json_encode($fm, JSON_UNESCAPED_SLASHES) ?: '');
            foreach ($forbidden as $f) {
                if (str_contains($joined, $f)) $errors[] = 'forbidden_content:' . $f;
            }
        }
        return array_values(array_unique($errors));
    }
}

if (!function_exists('rfc_find_file_by_id')) {
    function rfc_find_file_by_id(string $rfcId): string
    {
        $id = strtoupper(trim($rfcId));
        foreach (rfc_list_files() as $f) {
            $p = rfc_parse_file($f);
            if (!$p['ok']) continue;
            if (strtoupper((string)($p['frontmatter']['RFC_ID'] ?? '')) === $id) return $f;
        }
        $guess = glob(rfc_dir() . '/' . $id . '-*.md') ?: [];
        if ($guess !== []) return (string)$guess[0];
        return '';
    }
}

if (!function_exists('rfc_append_audit')) {
    function rfc_append_audit(array $event): void
    {
        $path = rfc_audit_log_path();
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($path, json_encode($event, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }
}

if (!function_exists('rfc_write_core_audit')) {
    function rfc_write_core_audit(string $action, array $meta): void
    {
        if (!function_exists('auth_pdo') || !function_exists('audit_event')) return;
        $pdo = auth_pdo();
        if (!$pdo instanceof PDO) return;
        audit_event($pdo, $action, 'RFC', 'rfc', (string)($meta['rfc_id'] ?? ''), 'RFC workflow action', $meta);
    }
}

if (!function_exists('rfc_is_required_for_type')) {
    function rfc_is_required_for_type(string $type): bool
    {
        return in_array(strtoupper(trim($type)), rfc_required_types(), true);
    }
}

if (!function_exists('rfc_gate_check')) {
    function rfc_gate_check(string $type, string $env, string $rfcId, bool $strict = true): array
    {
        $type = strtoupper(trim($type));
        $env = strtolower(trim($env));
        $required = ($env === 'production') && rfc_is_required_for_type($type);
        $errors = [];
        $notes = [];
        $status = 'OK';
        $rfcData = [];
        $file = '';
        if ($required && trim($rfcId) === '') {
            $errors[] = 'rfc_required_missing';
        }
        if (trim($rfcId) !== '') {
            $file = rfc_find_file_by_id($rfcId);
            if ($file === '') {
                $errors[] = 'rfc_not_found';
            } else {
                $parsed = rfc_parse_file($file);
                if (!$parsed['ok']) {
                    $errors[] = 'rfc_parse_failed';
                } else {
                    $rfcData = (array)$parsed['frontmatter'];
                    $valErrors = rfc_validate_frontmatter($rfcData, true);
                    if ($valErrors !== []) {
                        $errors = array_merge($errors, $valErrors);
                    } elseif ($required && strtoupper((string)($rfcData['TYPE'] ?? '')) !== $type) {
                        $errors[] = 'rfc_type_mismatch';
                    } elseif ($required && (string)($rfcData['STATUS'] ?? '') !== 'APPROVED') {
                        $errors[] = 'rfc_not_approved';
                    }
                }
            }
        } else {
            if ($env === 'staging') {
                $notes[] = 'RFC optional in staging';
                $status = 'ATTENTION';
            }
        }
        if ($errors !== []) {
            $status = 'CRITICAL';
        }
        $ok = $strict ? ($errors === []) : true;
        return [
            'ok' => $ok,
            'status' => $status,
            'required' => $required,
            'type' => $type,
            'env' => $env,
            'rfc_id' => strtoupper(trim($rfcId)),
            'rfc_file_masked' => $file !== '' ? rfc_mask($file) : '',
            'rfc_status' => (string)($rfcData['STATUS'] ?? ''),
            'errors' => array_values(array_unique(array_map(static fn($v): string => rfc_mask((string)$v), $errors))),
            'notes' => array_values(array_unique(array_map(static fn($v): string => rfc_mask((string)$v), $notes))),
            'frontmatter' => $rfcData,
        ];
    }
}

