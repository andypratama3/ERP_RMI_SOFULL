<?php
declare(strict_types=1);

require_once __DIR__ . '/release_notes_lib.php';

if (!function_exists('rp_max_bytes')) {
    function rp_max_bytes(): int
    {
        $raw = trim((string)(getenv('RELEASE_PACK_MAX_BYTES') ?: ''));
        if ($raw === '' || !ctype_digit($raw)) return 52428800;
        $v = (int)$raw;
        return $v > 0 ? $v : 52428800;
    }
}

if (!function_exists('rp_allowed_extensions')) {
    function rp_allowed_extensions(): array
    {
        return ['md', 'json', 'html', 'txt', 'csv'];
    }
}

if (!function_exists('rp_safe_relpath')) {
    function rp_safe_relpath(string $abs): string
    {
        $root = rn_root();
        $real = realpath($abs);
        if ($real === false) return '';
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) return '';
        return ltrim(str_replace($root, '', $real), '/');
    }
}

if (!function_exists('rp_is_allowed_file')) {
    function rp_is_allowed_file(string $abs, array $allowedRelPrefixes, array $allowedNamesInLogs = []): bool
    {
        if (!is_file($abs) || is_link($abs)) return false;
        $rel = rp_safe_relpath($abs);
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) return false;
        $ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, rp_allowed_extensions(), true)) return false;
        if (str_starts_with($rel, 'docs/governance/RFC/')) {
            $size = (int)@filesize($abs);
            if ($size <= 0 || $size > 2 * 1024 * 1024) return false;
        }
        $okPrefix = false;
        foreach ($allowedRelPrefixes as $prefix) {
            $prefix = trim($prefix, '/');
            if ($prefix !== '' && str_starts_with($rel, $prefix . '/')) {
                $okPrefix = true;
                break;
            }
            if ($rel === $prefix) {
                $okPrefix = true;
                break;
            }
        }
        if (!$okPrefix) return false;

        if (str_starts_with($rel, 'storage/logs/')) {
            $base = basename($rel);
            if (!in_array($base, $allowedNamesInLogs, true) && !str_starts_with($rel, 'storage/logs/pipeline/')) return false;
        }
        return true;
    }
}

if (!function_exists('rp_collect_whitelisted_files')) {
    function rp_collect_whitelisted_files(array $inputs): array
    {
        $files = [];
        $allowedPrefixes = ['storage/logs/pipeline', 'storage/logs', 'docs/stages', 'docs/governance', 'docs/governance/RFC', 'storage/exports/release'];
        $allowedLogNames = [
            'readiness_report_last.json',
            'readiness_report_last.md',
            'smoke_http_last.json',
            'contract_check_last.json',
            'smoke_core_flows_last.json',
        ];
        foreach ($inputs as $path) {
            if (!is_string($path) || trim($path) === '') continue;
            if (rp_is_allowed_file($path, $allowedPrefixes, $allowedLogNames)) {
                $files[] = $path;
            }
        }
        foreach (rn_stage_reports() as $sr) {
            if (rp_is_allowed_file($sr, $allowedPrefixes, $allowedLogNames)) $files[] = $sr;
        }
        foreach ([
            rn_root() . '/storage/exports/release/release_notes_skeleton_last.json',
            rn_root() . '/storage/exports/release/release_notes_skeleton_last.md',
        ] as $extra) {
            if (rp_is_allowed_file($extra, $allowedPrefixes, $allowedLogNames)) $files[] = $extra;
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }
}

if (!function_exists('rp_create_pack')) {
    function rp_create_pack(string $zipPath, array $files, int $maxBytes = 52428800): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'errors' => ['zip_extension_missing'], 'manifest' => []];
        }
        $dir = dirname($zipPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'errors' => ['zip_dir_create_failed'], 'manifest' => []];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'errors' => ['zip_open_failed'], 'manifest' => []];
        }
        $manifestFiles = [];
        $total = 0;
        foreach ($files as $abs) {
            $rel = rp_safe_relpath($abs);
            if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) continue;
            $size = (int)@filesize($abs);
            $total += $size;
            $zip->addFile($abs, $rel);
            $manifestFiles[] = ['path' => $rel, 'sha256' => rn_file_sha256($abs), 'size_bytes' => $size];
        }
        $zip->close();
        $errors = [];
        if (!is_file($zipPath)) $errors[] = 'zip_not_created';
        $zipSize = is_file($zipPath) ? (int)@filesize($zipPath) : 0;
        if ($zipSize > $maxBytes) $errors[] = 'zip_size_exceeds_limit';
        return [
            'ok' => ($errors === []),
            'errors' => $errors,
            'manifest' => [
                'files' => $manifestFiles,
                'size_bytes_total' => $total,
                'zip_size_bytes' => $zipSize,
                'sha256_zip' => is_file($zipPath) ? rn_file_sha256($zipPath) : '',
            ],
        ];
    }
}

if (!function_exists('rp_verify_pack')) {
    function rp_verify_pack(string $zipPath, string $manifestPath, bool $strict = true, int $maxBytes = 52428800): array
    {
        $errors = [];
        if (!class_exists('ZipArchive')) $errors[] = 'zip_extension_missing';
        if (!is_file($zipPath)) $errors[] = 'zip_missing';
        if (!is_file($manifestPath)) $errors[] = 'manifest_missing';
        $manifest = rn_read_json($manifestPath);
        if (!$manifest['ok']) $errors[] = 'manifest_invalid';
        $zipSize = is_file($zipPath) ? (int)@filesize($zipPath) : 0;
        if ($zipSize > $maxBytes) $errors[] = 'zip_size_exceeds_limit';

        $entries = [];
        if (is_file($zipPath)) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                $errors[] = 'zip_open_failed';
            } else {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if (!is_array($stat)) continue;
                    $name = (string)($stat['name'] ?? '');
                    if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..')) {
                        $errors[] = 'zip_entry_path_invalid';
                        continue;
                    }
                    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, rp_allowed_extensions(), true)) {
                        $errors[] = 'zip_entry_extension_invalid:' . $name;
                    }
                    $entries[$name] = [
                        'size' => (int)($stat['size'] ?? 0),
                        'sha256' => hash('sha256', (string)$zip->getFromIndex($i)),
                    ];
                }
                $zip->close();
            }
        }
        $manifestFiles = (array)($manifest['data']['files'] ?? []);
        foreach ($manifestFiles as $mf) {
            $p = (string)($mf['path'] ?? '');
            $h = (string)($mf['sha256'] ?? '');
            $s = (int)($mf['size_bytes'] ?? -1);
            if (!isset($entries[$p])) {
                $errors[] = 'manifest_entry_missing_in_zip:' . $p;
                continue;
            }
            if ($h !== '' && $entries[$p]['sha256'] !== $h) $errors[] = 'sha256_mismatch:' . $p;
            if ($s >= 0 && (int)$entries[$p]['size'] !== $s) $errors[] = 'size_mismatch:' . $p;
        }
        if ($manifest['ok']) {
            $zipHash = rn_file_sha256($zipPath);
            $manifestZipHash = (string)($manifest['data']['sha256_zip'] ?? '');
            if ($manifestZipHash !== '' && $zipHash !== $manifestZipHash) {
                $errors[] = 'zip_sha256_mismatch';
            }
        }
        $ok = $strict ? ($errors === []) : true;
        return [
            'ok' => $ok,
            'strict' => $strict,
            'errors' => array_values(array_unique(array_map(static fn(string $e): string => rn_mask($e), $errors))),
            'zip_masked' => rn_mask($zipPath),
            'manifest_masked' => rn_mask($manifestPath),
            'size_bytes' => $zipSize,
        ];
    }
}

