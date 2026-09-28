<?php
declare(strict_types=1);

require_once __DIR__ . '/rfc_index_lib.php';

if (!function_exists('rfc_usage_approval_summary')) {
    function rfc_usage_approval_summary(array $fm): array
    {
        $required = array_values(array_unique(array_map(static fn($v): string => strtoupper(trim((string)$v)), (array)($fm['REQUIRES_APPROVALS'] ?? []))));
        $completed = [];
        foreach ((array)($fm['APPROVALS'] ?? []) as $a) {
            $role = strtoupper(trim((string)($a['ROLE'] ?? '')));
            if ($role !== '') {
                $completed[] = $role;
            }
        }
        $completed = array_values(array_unique($completed));
        $missing = [];
        foreach ($required as $r) {
            if (!in_array($r, $completed, true)) {
                $missing[] = $r;
            }
        }
        return ['required' => $required, 'completed' => $completed, 'missing' => $missing];
    }
}

if (!function_exists('rfc_usage_pipeline_dir')) {
    function rfc_usage_pipeline_dir(): string
    {
        $dir = rfc_root() . '/storage/logs/pipeline';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('rfc_usage_read_json_strict')) {
    function rfc_usage_read_json_strict(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'MISSING', 'data' => []];
        }
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            return ['ok' => false, 'error' => 'INVALID_JSON', 'data' => []];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return ['ok' => false, 'error' => 'INVALID_JSON', 'data' => []];
            }
            return ['ok' => true, 'error' => '', 'data' => $decoded];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'INVALID_JSON', 'data' => []];
        }
    }
}

if (!function_exists('rfc_usage_known_inputs')) {
    function rfc_usage_known_inputs(): array
    {
        $p = rfc_usage_pipeline_dir();
        return [
            ['source' => 'alert_policy_apply_last.json', 'path' => $p . '/alert_policy_apply_last.json'],
            ['source' => 'ops_thresholds_apply_last.json', 'path' => $p . '/ops_thresholds_apply_last.json'],
            ['source' => 'migration_apply_last.json', 'path' => $p . '/migration_apply_last.json'],
            ['source' => 'restore_apply_last.json', 'path' => $p . '/restore_apply_last.json'],
            ['source' => 'release_notes_last.json', 'path' => rfc_root() . '/storage/exports/release/release_notes_last.json'],
            ['source' => 'rfc_gate_last.json', 'path' => $p . '/rfc_gate_last.json'],
            ['source' => 'pipeline_last.json', 'path' => $p . '/pipeline_last.json'],
        ];
    }
}

if (!function_exists('rfc_usage_extract_ids')) {
    function rfc_usage_extract_ids(array $data): array
    {
        $ids = [];
        $fields = [
            (string)($data['rfc_id'] ?? ''),
            (string)($data['meta']['rfc_id'] ?? ''),
            (string)($data['rfc']['id'] ?? ''),
            (string)($data['rfc']['rfc_id'] ?? ''),
        ];
        foreach ($fields as $candidate) {
            $id = strtoupper(trim($candidate));
            if (preg_match('/^RFC\-\d{4}\-\d{4}$/', $id) === 1) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('rfc_usage_type_tags')) {
    function rfc_usage_type_tags(string $type): array
    {
        return match (strtoupper(trim($type))) {
            'OPS_ALERT_POLICY_CHANGE' => ['ALERT_POLICY'],
            'OPS_POLICY_CHANGE' => ['OPS_THRESHOLDS'],
            'PRODUCTION_DEPLOY' => ['DEPLOY'],
            default => ['OTHER'],
        };
    }
}

if (!function_exists('rfc_usage_metadata')) {
    function rfc_usage_metadata(string $rfcId, array $index): array
    {
        $row = rfc_index_find($index, $rfcId);
        if ($row !== []) {
            return [
                'id' => (string)$row['id'],
                'type' => (string)($row['type'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'file_rel_path' => (string)($row['file'] ?? ''),
                'approvals' => [
                    'required' => [],
                    'completed' => array_values((array)($row['approvals_completed'] ?? [])),
                    'missing' => array_values((array)($row['approvals_missing'] ?? [])),
                ],
                'schedule' => null,
            ];
        }

        $file = rfc_find_file_by_id($rfcId);
        if ($file === '') {
            return [];
        }
        $parsed = rfc_parse_file($file);
        if (!$parsed['ok']) {
            return [];
        }
        $fm = (array)($parsed['frontmatter'] ?? []);
        $approval = rfc_usage_approval_summary($fm);
        return [
            'id' => strtoupper(trim((string)($fm['RFC_ID'] ?? $rfcId))),
            'type' => strtoupper(trim((string)($fm['TYPE'] ?? ''))),
            'status' => strtoupper(trim((string)($fm['STATUS'] ?? ''))),
            'title' => (string)($fm['TITLE'] ?? ''),
            'file_rel_path' => rfc_rel_path($file),
            'approvals' => [
                'required' => array_values((array)($approval['required'] ?? [])),
                'completed' => array_values((array)($approval['completed'] ?? [])),
                'missing' => array_values((array)($approval['missing'] ?? [])),
            ],
            'schedule' => isset($fm['SCHEDULE']) ? (string)$fm['SCHEDULE'] : null,
        ];
    }
}

if (!function_exists('rfc_usage_append_audit')) {
    function rfc_usage_append_audit(array $event): void
    {
        $path = rfc_root() . '/storage/logs/audit_rfc_linking.jsonl';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, json_encode($event, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }
}

