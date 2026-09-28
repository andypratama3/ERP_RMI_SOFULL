<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_state_lib.php';
require_once __DIR__ . '/../../tools_ui_helpers.php';

if (!function_exists('rfcsf_root')) {
    function rfcsf_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('rfcsf_mask')) {
    function rfcsf_mask(string $text): string
    {
        return ts_mask(tools_mask_sensitive($text));
    }
}

if (!function_exists('rfcsf_index_path')) {
    function rfcsf_index_path(): string
    {
        return rfcsf_root() . '/storage/state/rfc_index_last.json';
    }
}

if (!function_exists('rfcsf_rfc_dir')) {
    function rfcsf_rfc_dir(): string
    {
        return rfcsf_root() . '/docs/governance/RFC';
    }
}

if (!function_exists('rfcsf_is_valid_rfc_id')) {
    function rfcsf_is_valid_rfc_id(string $rfcId): bool
    {
        return preg_match('/^RFC\-\d{4}\-\d{4}$/', strtoupper(trim($rfcId))) === 1;
    }
}

if (!function_exists('rfcsf_read_index_last')) {
    function rfcsf_read_index_last(): array
    {
        $path = rfcsf_index_path();
        $data = ts_read_json($path);
        if (!is_array($data) || !isset($data['rfcs']) || !is_array($data['rfcs'])) {
            return ['ok' => false, 'error' => 'INDEX_MISSING_OR_INVALID', 'data' => [], 'path' => $path];
        }
        return ['ok' => true, 'error' => '', 'data' => $data, 'path' => $path];
    }
}

if (!function_exists('rfcsf_is_safe_rel_path')) {
    function rfcsf_is_safe_rel_path(string $rel): bool
    {
        $v = trim($rel);
        if ($v === '') return false;
        if (str_contains($v, '..')) return false;
        if (str_starts_with($v, '/')) return false;
        if (!str_starts_with($v, 'docs/governance/RFC/')) return false;
        if (strtolower((string)pathinfo($v, PATHINFO_EXTENSION)) !== 'md') return false;
        return true;
    }
}

if (!function_exists('rfcsf_resolve_rfc_file')) {
    function rfcsf_resolve_rfc_file(string $rfcId, ?array $indexData = null): array
    {
        $id = strtoupper(trim($rfcId));
        if (!rfcsf_is_valid_rfc_id($id)) {
            return ['ok' => false, 'error' => 'INVALID_RFC_ID', 'id' => $id];
        }

        $index = $indexData;
        if ($index === null) {
            $read = rfcsf_read_index_last();
            if (!$read['ok']) return ['ok' => false, 'error' => (string)$read['error'], 'id' => $id];
            $index = (array)$read['data'];
        }

        $rel = '';
        foreach ((array)($index['rfcs'] ?? []) as $row) {
            if (!is_array($row)) continue;
            if (strtoupper((string)($row['id'] ?? '')) === $id) {
                $rel = (string)($row['file'] ?? '');
                break;
            }
        }
        if (!rfcsf_is_safe_rel_path($rel)) {
            return ['ok' => false, 'error' => 'RFC_PATH_INVALID', 'id' => $id];
        }

        $abs = rfcsf_root() . '/' . $rel;
        $real = realpath($abs);
        if ($real === false || !is_file($real)) {
            return ['ok' => false, 'error' => 'RFC_FILE_MISSING', 'id' => $id];
        }
        $rfcDirReal = realpath(rfcsf_rfc_dir()) ?: rfcsf_rfc_dir();
        if (!str_starts_with($real, $rfcDirReal . DIRECTORY_SEPARATOR)) {
            return ['ok' => false, 'error' => 'TRAVERSAL_BLOCKED', 'id' => $id];
        }
        if (strtolower((string)pathinfo($real, PATHINFO_EXTENSION)) !== 'md') {
            return ['ok' => false, 'error' => 'INVALID_EXTENSION', 'id' => $id];
        }
        return [
            'ok' => true,
            'error' => '',
            'id' => $id,
            'rel_path' => $rel,
            'abs_path' => $real,
            'rel_path_masked' => rfcsf_mask($rel),
            'abs_path_masked' => rfcsf_mask($real),
        ];
    }
}

if (!function_exists('rfcsf_read_file_masked')) {
    function rfcsf_read_file_masked(string $absPath): array
    {
        $raw = is_file($absPath) ? (string)@file_get_contents($absPath) : '';
        if ($raw === '') return ['ok' => false, 'error' => 'READ_FAILED', 'content' => ''];
        return ['ok' => true, 'error' => '', 'content' => rfcsf_mask($raw)];
    }
}

