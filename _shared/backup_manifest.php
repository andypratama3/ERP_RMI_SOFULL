<?php
declare(strict_types=1);

function rmi_sha256_file(string $path): string
{
    return hash_file('sha256', $path) ?: '';
}

function rmi_create_backup_manifest_and_checksums(
    string $packageDir,
    string $appName,
    array $filesMap,
    array $meta = []
): array {
    if (!is_dir($packageDir)) {
        throw new RuntimeException('Package dir not found');
    }
    $checksums = [];
    foreach ($filesMap as $alias => $filename) {
        $full = rtrim($packageDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($full)) continue;
        $checksums[$alias] = rmi_sha256_file($full);
    }
    $manifest = array_merge([
        'app' => $appName,
        'created_at' => gmdate('c'),
        'files' => $filesMap,
        'sha256' => $checksums,
    ], $meta);

    $manifestFile = rtrim($packageDir, '/\\') . DIRECTORY_SEPARATOR . 'manifest.json';
    $checksumFile = rtrim($packageDir, '/\\') . DIRECTORY_SEPARATOR . 'checksums.sha256';
    file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    $lines = [];
    foreach ($filesMap as $alias => $filename) {
        if (!isset($checksums[$alias]) || $checksums[$alias] === '') continue;
        $lines[] = $checksums[$alias] . '  ' . $filename;
    }
    file_put_contents($checksumFile, implode("\n", $lines) . "\n");

    return ['manifest' => $manifestFile, 'checksums' => $checksumFile, 'sha256' => $checksums];
}

