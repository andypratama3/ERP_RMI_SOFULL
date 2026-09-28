<?php
declare(strict_types=1);

require_once __DIR__ . '/release_pack_lib.php';

if (!function_exists('rv_root')) {
    function rv_root(): string
    {
        return rn_root();
    }
}

if (!function_exists('rv_mask')) {
    function rv_mask(string $v): string
    {
        return rn_mask($v);
    }
}

if (!function_exists('safe_realpath_under_base')) {
    function safe_realpath_under_base(string $base, string $path): string
    {
        $baseReal = realpath($base);
        $pathReal = realpath($path);
        if ($baseReal === false || $pathReal === false) return '';
        if (!str_starts_with($pathReal, $baseReal . DIRECTORY_SEPARATOR) && $pathReal !== $baseReal) return '';
        return $pathReal;
    }
}

if (!function_exists('zip_entry_is_safe')) {
    function zip_entry_is_safe(string $name): bool
    {
        if ($name === '') return false;
        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) return false;
        if (str_contains($name, '..')) return false;
        if (preg_match('/[\x00-\x1F]/', $name) === 1) return false;
        if (str_contains($name, '\\')) return false;
        return true;
    }
}

if (!function_exists('validate_manifest_schema')) {
    function validate_manifest_schema(array $obj): array
    {
        $errors = [];
        if (!isset($obj['files']) || !is_array($obj['files'])) $errors[] = 'ERR_MANIFEST_SCHEMA_FILES';
        foreach ((array)($obj['files'] ?? []) as $i => $f) {
            if (!is_array($f)) {
                $errors[] = 'ERR_MANIFEST_SCHEMA_FILE_ITEM:' . $i;
                continue;
            }
            if (!isset($f['path']) || trim((string)$f['path']) === '') $errors[] = 'ERR_MANIFEST_SCHEMA_PATH:' . $i;
            if (!isset($f['sha256']) || trim((string)$f['sha256']) === '') $errors[] = 'ERR_MANIFEST_SCHEMA_SHA256:' . $i;
            if (isset($f['size_bytes']) && !is_int($f['size_bytes']) && !ctype_digit((string)$f['size_bytes'])) $errors[] = 'ERR_MANIFEST_SCHEMA_SIZE:' . $i;
        }
        return array_values(array_unique($errors));
    }
}

if (!function_exists('list_zip_entries')) {
    function list_zip_entries(ZipArchive $zip): array
    {
        $entries = [];
        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if (!is_array($st)) continue;
            $name = (string)($st['name'] ?? '');
            $size = (int)($st['size'] ?? 0);
            $entries[] = ['name' => $name, 'size' => $size];
            $totalUncompressed += max(0, $size);
        }
        return ['entries' => $entries, 'total_uncompressed' => $totalUncompressed];
    }
}

if (!function_exists('hash_zip_entry_stream')) {
    function hash_zip_entry_stream(string $zipAbs, string $entryName): array
    {
        $uri = 'zip://' . $zipAbs . '#' . $entryName;
        $fh = @fopen($uri, 'rb');
        if (!is_resource($fh)) return ['ok' => false, 'sha256' => '', 'error' => 'ERR_ENTRY_STREAM_OPEN'];
        $ctx = hash_init('sha256');
        while (!feof($fh)) {
            $chunk = fread($fh, 65536);
            if ($chunk === false) {
                fclose($fh);
                return ['ok' => false, 'sha256' => '', 'error' => 'ERR_ENTRY_STREAM_READ'];
            }
            if ($chunk !== '') hash_update($ctx, $chunk);
        }
        fclose($fh);
        return ['ok' => true, 'sha256' => hash_final($ctx), 'error' => ''];
    }
}

if (!function_exists('rv_status_from_checks')) {
    function rv_status_from_checks(array $warnings, array $fails): string
    {
        if ($fails !== []) return 'FAIL';
        if ($warnings !== []) return 'WARN';
        return 'OK';
    }
}

if (!function_exists('verify_pack_quick')) {
    function verify_pack_quick(string $zipAbs, string $manifestAbs, array $policy = []): array
    {
        $checks = [];
        $warnings = [];
        $fails = [];
        $maxBytes = isset($policy['max_zip_bytes']) ? (int)$policy['max_zip_bytes'] : rp_max_bytes();
        $allowMissingManifest = !empty($policy['allow_missing_manifest_in_quick']);

        $zipReal = safe_realpath_under_base(rv_root(), $zipAbs);
        $manifestReal = safe_realpath_under_base(rv_root(), $manifestAbs);

        if ($zipReal === '' || !is_file($zipAbs)) {
            $fails[] = ['code' => 'ERR_ZIP_MISSING', 'message' => rv_mask($zipAbs)];
        }
        $manifestMissing = ($manifestReal === '' || !is_file($manifestAbs));
        if ($manifestMissing) {
            if ($allowMissingManifest && ($zipReal !== '' && is_file($zipAbs))) {
                $warnings[] = ['code' => 'WARN_MANIFEST_MISSING', 'message' => rv_mask($manifestAbs)];
            } else {
                $fails[] = ['code' => 'ERR_MANIFEST_MISSING', 'message' => rv_mask($manifestAbs)];
            }
        }

        $sigOk = false;
        if ($zipReal !== '' && is_file($zipReal)) {
            $sig = (string)@file_get_contents($zipReal, false, null, 0, 4);
            $sigOk = in_array($sig, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
        }
        $checks[] = ['name' => 'zip_signature', 'ok' => $sigOk, 'code' => $sigOk ? 'OK' : 'ERR_ZIP_SIGNATURE', 'message' => $sigOk ? 'ok' : rv_mask('zip signature invalid')];
        if (!$sigOk) $fails[] = ['code' => 'ERR_ZIP_SIGNATURE', 'message' => rv_mask($zipAbs)];

        $zipOpen = false;
        $entries = [];
        $totalUncompressed = 0;
        $zip = new ZipArchive();
        if ($zipReal !== '' && $zip->open($zipReal) === true) {
            $zipOpen = true;
            $lz = list_zip_entries($zip);
            $entries = (array)$lz['entries'];
            $totalUncompressed = (int)$lz['total_uncompressed'];
            $zip->close();
        }
        $checks[] = ['name' => 'zip_open', 'ok' => $zipOpen, 'code' => $zipOpen ? 'OK' : 'ERR_ZIP_OPEN', 'message' => $zipOpen ? 'ok' : rv_mask('zip cannot open')];
        if (!$zipOpen) $fails[] = ['code' => 'ERR_ZIP_OPEN', 'message' => rv_mask($zipAbs)];

        $manifestObj = [];
        $manifestValid = false;
        if ($manifestReal !== '' && is_file($manifestReal)) {
            $read = rn_read_json($manifestReal);
            if ($read['ok']) {
                $manifestObj = (array)$read['data'];
                $schemaErrors = validate_manifest_schema($manifestObj);
                if ($schemaErrors === []) $manifestValid = true;
                foreach ($schemaErrors as $se) $fails[] = ['code' => $se, 'message' => rv_mask($manifestAbs)];
            } else {
                $fails[] = ['code' => 'ERR_MANIFEST_INVALID_JSON', 'message' => rv_mask($manifestAbs)];
            }
        }
        $checks[] = ['name' => 'manifest_valid', 'ok' => $manifestValid, 'code' => $manifestValid ? 'OK' : 'ERR_MANIFEST_SCHEMA', 'message' => $manifestValid ? 'ok' : rv_mask('manifest invalid')];

        $zipSize = is_file($zipAbs) ? (int)@filesize($zipAbs) : 0;
        if ($zipSize > $maxBytes) {
            $fails[] = ['code' => 'ERR_ZIP_SIZE_LIMIT', 'message' => rv_mask('zip exceeds max bytes')];
        }

        $zipHash = is_file($zipAbs) ? rn_file_sha256($zipAbs) : '';
        $expectedZipHash = (string)($manifestObj['sha256_zip'] ?? '');
        $hashOk = ($expectedZipHash === '') ? ($zipHash !== '') : (strtolower($expectedZipHash) === strtolower($zipHash));
        $checks[] = ['name' => 'zip_sha256_match', 'ok' => $hashOk, 'code' => $hashOk ? 'OK' : 'ERR_ZIP_SHA256_MISMATCH', 'message' => $hashOk ? 'ok' : rv_mask('zip sha mismatch'), 'computed_sha256' => $zipHash];
        if (!$hashOk) $fails[] = ['code' => 'ERR_ZIP_SHA256_MISMATCH', 'message' => rv_mask($zipAbs)];

        $entryNames = array_map(static fn(array $e): string => (string)($e['name'] ?? ''), $entries);
        $entryNames = array_values(array_filter($entryNames, static fn(string $v): bool => $v !== ''));
        $manifestNames = [];
        foreach ((array)($manifestObj['files'] ?? []) as $f) {
            $manifestNames[] = (string)($f['path'] ?? '');
        }
        $manifestNames = array_values(array_filter($manifestNames, static fn(string $v): bool => $v !== ''));

        $missing = [];
        foreach ($manifestNames as $mn) if (!in_array($mn, $entryNames, true)) $missing[] = $mn;
        $extra = [];
        foreach ($entryNames as $en) if (!in_array($en, $manifestNames, true)) $extra[] = $en;

        $unsafe = [];
        $badExt = [];
        foreach ($entryNames as $en) {
            if (!zip_entry_is_safe($en)) $unsafe[] = $en;
            $ext = strtolower((string)pathinfo($en, PATHINFO_EXTENSION));
            if (!in_array($ext, rp_allowed_extensions(), true)) $badExt[] = $en;
        }
        $entriesOk = ($missing === [] && $unsafe === [] && $badExt === []);
        $checks[] = [
            'name' => 'entries_match_manifest',
            'ok' => $entriesOk,
            'code' => $entriesOk ? 'OK' : 'ERR_ENTRIES_MISMATCH',
            'message' => $entriesOk ? 'ok' : rv_mask('entries mismatch/safety issue'),
            'missing_entries' => array_values(array_unique($missing)),
            'extra_entries' => array_values(array_unique($extra)),
        ];
        if ($missing !== []) $fails[] = ['code' => 'ERR_ENTRY_MISSING', 'message' => rv_mask(implode(', ', array_slice($missing, 0, 5)))];
        if ($unsafe !== []) $fails[] = ['code' => 'ERR_ENTRY_PATH_UNSAFE', 'message' => rv_mask(implode(', ', array_slice($unsafe, 0, 5)))];
        if ($badExt !== []) $fails[] = ['code' => 'ERR_ENTRY_EXTENSION_INVALID', 'message' => rv_mask(implode(', ', array_slice($badExt, 0, 5)))];
        if ($extra !== []) $warnings[] = ['code' => 'WARN_EXTRA_ENTRY', 'message' => rv_mask(implode(', ', array_slice($extra, 0, 5)))];

        $status = rv_status_from_checks($warnings, $fails);
        return [
            'status' => $status,
            'checks' => $checks,
            'warnings' => $warnings,
            'fails' => $fails,
            'meta' => [
                'zip_size_bytes' => $zipSize,
                'total_uncompressed_bytes' => $totalUncompressed,
            ],
        ];
    }
}

if (!function_exists('verify_pack_full')) {
    function verify_pack_full(string $zipAbs, string $manifestAbs, array $policy = []): array
    {
        $quick = verify_pack_quick($zipAbs, $manifestAbs, $policy);
        $checks = (array)$quick['checks'];
        $warnings = (array)$quick['warnings'];
        $fails = (array)$quick['fails'];
        $maxEntries = 2000;
        $maxUncompressed = 2147483648;

        $manifestRead = rn_read_json($manifestAbs);
        if (!$manifestRead['ok']) {
            return [
                'status' => rv_status_from_checks($warnings, $fails),
                'checks' => $checks,
                'warnings' => $warnings,
                'fails' => $fails,
                'meta' => (array)($quick['meta'] ?? []),
            ];
        }
        $manifestObj = (array)$manifestRead['data'];
        $zip = new ZipArchive();
        if ($zip->open($zipAbs) !== true) {
            $fails[] = ['code' => 'ERR_ZIP_OPEN', 'message' => rv_mask($zipAbs)];
            return [
                'status' => rv_status_from_checks($warnings, $fails),
                'checks' => $checks,
                'warnings' => $warnings,
                'fails' => $fails,
                'meta' => (array)($quick['meta'] ?? []),
            ];
        }
        $lz = list_zip_entries($zip);
        $zip->close();
        $entryCount = count((array)$lz['entries']);
        $totalUncompressed = (int)$lz['total_uncompressed'];
        if ($entryCount > $maxEntries || $totalUncompressed > $maxUncompressed) {
            $warnings[] = ['code' => 'FULL_DEGRADED', 'message' => rv_mask('full verify degraded: entries/size too large')];
            $checks[] = ['name' => 'entry_sha256_match', 'ok' => false, 'code' => 'FULL_DEGRADED', 'message' => rv_mask('full sha skipped due size/entry constraints'), 'mismatches' => []];
            return [
                'status' => rv_status_from_checks($warnings, $fails),
                'checks' => $checks,
                'warnings' => $warnings,
                'fails' => $fails,
                'meta' => [
                    'entry_count' => $entryCount,
                    'total_uncompressed_bytes' => $totalUncompressed,
                    'degraded' => true,
                ],
            ];
        }

        $mismatches = [];
        foreach ((array)($manifestObj['files'] ?? []) as $f) {
            $entry = (string)($f['path'] ?? '');
            $expected = strtolower((string)($f['sha256'] ?? ''));
            if ($entry === '' || $expected === '') continue;
            $h = hash_zip_entry_stream($zipAbs, $entry);
            if (!$h['ok']) {
                $mismatches[] = ['entry' => $entry, 'expected' => $expected, 'got' => ''];
                continue;
            }
            if (strtolower((string)$h['sha256']) !== $expected) {
                $mismatches[] = ['entry' => $entry, 'expected' => $expected, 'got' => strtolower((string)$h['sha256'])];
            }
        }
        $shaOk = ($mismatches === []);
        $checks[] = ['name' => 'entry_sha256_match', 'ok' => $shaOk, 'code' => $shaOk ? 'OK' : 'ERR_ENTRY_SHA256_MISMATCH', 'message' => $shaOk ? 'ok' : rv_mask('entry sha mismatch'), 'mismatches' => $mismatches];
        if (!$shaOk) $fails[] = ['code' => 'ERR_ENTRY_SHA256_MISMATCH', 'message' => rv_mask('entry checksum mismatch')];

        return [
            'status' => rv_status_from_checks($warnings, $fails),
            'checks' => $checks,
            'warnings' => $warnings,
            'fails' => $fails,
            'meta' => [
                'entry_count' => $entryCount,
                'total_uncompressed_bytes' => $totalUncompressed,
                'degraded' => false,
            ],
        ];
    }
}

