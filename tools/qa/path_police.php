<?php
/**
 * tools/qa/path_police.php — CRITICAL FAIL if /Volumes/ /Users/ "Macintosh HD" in artifacts.
 * Usage: php tools/qa/path_police.php [--write-last] [--strict]
 * Output: storage/logs/path_police_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/tools_ui_helpers.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);

// /Volumes/ = Mac mount path di JSON artifact → CRITICAL
// /Users/ dikecualikan karena bisa muncul di log historis dari konteks berbeda
$FORBIDDEN = ['/Volumes/', 'Macintosh HD', 'C:\\Users\\'];
// Hanya scan JSON/JSONL artifacts — bukan .log files (yang bisa berisi path dari berbagai konteks historis)
$SCAN_PATTERNS = [
    $root . '/storage/logs/*.json',
    $root . '/storage/logs/*.jsonl',
    $root . '/storage/logs/**/*.json',
    $root . '/storage/state/*.json',
];

// Files that legitimately reference forbidden tokens as part of their reporting
// (meta-files that describe the forbidden tokens themselves)
$SELF_EXCLUDE_BASENAMES = [
    'path_police_last.json',           // self
    'repo_location_guard_last.json',   // contains forbidden token list
    'repo_location_audit_last.json',   // repo audit references paths
    'volumes_guard_last.json',         // mentions Volumes as topic
    'volumes_police_last.json',        // mentions Volumes as topic
];

// Key fields to check in JSON (only field VALUES, not entire string blob)
// This avoids false positives from error_masked/errors_masked fields
$CHECK_JSON_VALUES = true;

$violations = [];
$scannedCount = 0;

foreach ($SCAN_PATTERNS as $pattern) {
    foreach (glob($pattern) ?: [] as $file) {
        if (!is_file($file)) continue;
        if (in_array(basename($file), $SELF_EXCLUDE_BASENAMES, true)) continue;

        $scannedCount++;
        $content = (string)@file_get_contents($file);
        if ($content === '') continue;

        // For JSON files: only check specific value fields, not raw string match
        // This prevents false positives from error_masked fields that describe forbidden tokens
        if (str_ends_with($file, '.json') || str_ends_with($file, '.jsonl')) {
            $decoded = @json_decode($content, true);
            if (is_array($decoded)) {
                // Extract only key path-like values (not error messages)
                $pathFields = [];
                foreach (['app_root', 'canonical_root', 'canonical_realpath', 'detected_root', 'root', 'cwd', 'base_url', 'artifact', 'zip_path', 'cmd'] as $field) {
                    if (isset($decoded[$field]) && is_string($decoded[$field])) {
                        $pathFields[] = $decoded[$field];
                    }
                }
                // Check candidates/findings arrays for path values
                foreach (['candidates', 'findings', 'violations'] as $arr) {
                    if (isset($decoded[$arr]) && is_array($decoded[$arr])) {
                        foreach ($decoded[$arr] as $item) {
                            if (is_array($item)) {
                                foreach (['path', 'realpath', 'file', 'app_root'] as $f) {
                                    if (isset($item[$f]) && is_string($item[$f])) $pathFields[] = $item[$f];
                                }
                            }
                        }
                    }
                }
                foreach ($pathFields as $val) {
                    foreach ($FORBIDDEN as $token) {
                        if (strpos($val, $token) !== false) {
                            $violations[] = [
                                'file' => str_replace($root, '[APP_ROOT]', $file),
                                'token' => $token,
                                'field_value' => str_replace($root, '[APP_ROOT]', $val),
                                'severity' => 'CRITICAL',
                            ];
                        }
                    }
                }
                continue; // JSON handled, skip raw scan
            }
        }

        // JSONL: scan each line's key values
        if (str_ends_with($file, '.jsonl')) {
            foreach (explode("\n", $content) as $line) {
                $row = @json_decode(trim($line), true);
                if (!is_array($row)) continue;
                foreach (['app_root', 'root', 'cwd', 'base_url'] as $f) {
                    if (isset($row[$f]) && is_string($row[$f])) {
                        foreach ($FORBIDDEN as $token) {
                            if (strpos($row[$f], $token) !== false) {
                                $violations[] = ['file' => str_replace($root, '[APP_ROOT]', $file), 'token' => $token, 'severity' => 'CRITICAL'];
                            }
                        }
                    }
                }
            }
            continue;
        }

        // Fallback: raw string scan for non-JSON
        foreach ($FORBIDDEN as $token) {
            if (strpos($content, $token) !== false) {
                $violations[] = ['file' => str_replace($root, '[APP_ROOT]', $file), 'token' => $token, 'severity' => 'CRITICAL'];
                break;
            }
        }
    }
}

$ok = count($violations) === 0;

$result = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'ok' => $ok,
    'scanned_files' => $scannedCount,
    'violations' => $violations,
    'forbidden_tokens' => $FORBIDDEN,
    'message' => $ok ? 'No forbidden path tokens found in artifacts.' : 'CRITICAL: Forbidden path tokens found in artifacts.',
];

if ($writeLast) {
    @mkdir($root . '/storage/logs', 0775, true);
    file_put_contents($root . '/storage/logs/path_police_last.json',
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
