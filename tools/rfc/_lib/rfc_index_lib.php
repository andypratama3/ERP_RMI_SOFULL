<?php
declare(strict_types=1);

require_once __DIR__ . '/rfc_lib.php';

if (!function_exists('rfc_index_approval_summary')) {
    function rfc_index_approval_summary(array $fm): array
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

if (!function_exists('rfc_index_state_path')) {
    function rfc_index_state_path(): string
    {
        return rfc_root() . '/storage/state/rfc_index_last.json';
    }
}

if (!function_exists('rfc_rel_path')) {
    function rfc_rel_path(string $abs): string
    {
        $root = rfc_root();
        $real = realpath($abs);
        if ($real === false) {
            return '';
        }
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return '';
        }
        return ltrim(str_replace($root, '', $real), '/');
    }
}

if (!function_exists('rfc_index_record_from_file')) {
    function rfc_index_record_from_file(string $file): array
    {
        $parsed = rfc_parse_file($file);
        if (!$parsed['ok']) {
            return [];
        }
        $fm = (array)($parsed['frontmatter'] ?? []);
        $id = strtoupper(trim((string)($fm['RFC_ID'] ?? '')));
        if ($id === '') {
            return [];
        }
        $approvals = rfc_index_approval_summary($fm);
        return [
            'id' => $id,
            'file' => rfc_rel_path($file),
            'type' => strtoupper(trim((string)($fm['TYPE'] ?? ''))),
            'status' => strtoupper(trim((string)($fm['STATUS'] ?? ''))),
            'title' => trim((string)($fm['TITLE'] ?? '')),
            'approvals_completed' => array_values((array)($approvals['completed'] ?? [])),
            'approvals_missing' => array_values((array)($approvals['missing'] ?? [])),
        ];
    }
}

if (!function_exists('rfc_scan_index')) {
    function rfc_scan_index(): array
    {
        $rfcs = [];
        foreach (rfc_list_files() as $file) {
            $row = rfc_index_record_from_file($file);
            if ($row !== []) {
                $rfcs[] = $row;
            }
        }
        usort($rfcs, static function (array $a, array $b): int {
            return strcmp((string)$a['id'], (string)$b['id']);
        });
        return [
            'state_version' => 1,
            'generated_at' => date(DateTimeInterface::ATOM),
            'count' => count($rfcs),
            'rfcs' => $rfcs,
        ];
    }
}

if (!function_exists('rfc_index_find')) {
    function rfc_index_find(array $index, string $rfcId): array
    {
        $target = strtoupper(trim($rfcId));
        foreach ((array)($index['rfcs'] ?? []) as $row) {
            if (strtoupper((string)($row['id'] ?? '')) === $target) {
                return is_array($row) ? $row : [];
            }
        }
        return [];
    }
}

