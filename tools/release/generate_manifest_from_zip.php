<?php
declare(strict_types=1);

/**
 * CLI: Generate manifest JSON for release pack zips that lack one.
 * Fixes WARN_MANIFEST_MISSING and WARN_EXTRA_ENTRY by creating manifests
 * that list all zip entries — verify will then pass (or show real issues).
 *
 * Usage: php tools/release/generate_manifest_from_zip.php [--dry-run]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("CLI only\n");
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ERR: PHP Zip extension required. Install php-zip (e.g. Synology: Web Station → PHP 8.4 → Edit → centang zip)\n");
    exit(1);
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/release/_lib/release_notes_lib.php';
require_once $root . '/tools/release/_lib/release_verify_lib.php';

$dryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);
$exportsDir = $root . '/storage/exports/release';
if (!is_dir($exportsDir)) {
    fwrite(STDERR, "Exports dir not found: {$exportsDir}\n");
    exit(1);
}

$zips = glob($exportsDir . '/release_pack_*.zip') ?: [];
$created = 0;
$skipped = 0;

foreach ($zips as $zipAbs) {
    $base = basename($zipAbs, '.zip');
    $manifestAbs = $exportsDir . '/' . $base . '_manifest.json';
    if (is_file($manifestAbs)) {
        $read = rn_read_json($manifestAbs);
        if ($read['ok'] && isset($read['data']['files']) && is_array($read['data']['files'])) {
            $skipped++;
            continue;
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($zipAbs) !== true) {
        fwrite(STDERR, "Skip (cannot open): " . basename($zipAbs) . "\n");
        continue;
    }

    $lz = list_zip_entries($zip);
    $entries = (array)($lz['entries'] ?? []);
    $totalUncompressed = (int)($lz['total_uncompressed'] ?? 0);
    $zip->close();

    $manifestFiles = [];
    foreach ($entries as $e) {
        $name = (string)($e['name'] ?? '');
        $size = (int)($e['size'] ?? 0);
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..')) continue;

        $sha = '';
        if ($size === 0 || str_ends_with($name, '/')) {
            $sha = hash('sha256', '');
        } else {
            $h = hash_zip_entry_stream($zipAbs, $name);
            $sha = $h['ok'] ? $h['sha256'] : hash('sha256', '');
        }
        $manifestFiles[] = ['path' => $name, 'sha256' => $sha, 'size_bytes' => $size];
    }

    $zipSha = rn_file_sha256($zipAbs);
    $manifestPayload = [
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'source' => 'generate_manifest_from_zip',
        'zip_file' => basename($zipAbs),
        'sha256_zip' => $zipSha,
        'files' => $manifestFiles,
        'size_bytes_total' => $totalUncompressed,
    ];

    if ($dryRun) {
        echo "Would create: {$base}_manifest.json (" . count($manifestFiles) . " entries)\n";
        $created++;
        continue;
    }

    $ok = @file_put_contents(
        $manifestAbs,
        json_encode($manifestPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    ) !== false;
    if ($ok) {
        echo "Created: {$base}_manifest.json (" . count($manifestFiles) . " entries)\n";
        $created++;
    } else {
        fwrite(STDERR, "Failed to write: {$manifestAbs}\n");
    }
}

echo "Done: created={$created} skipped={$skipped}\n";
exit(0);
