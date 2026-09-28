<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/_lib/release_verify_lib.php';
require_once __DIR__ . '/../tools_state_lib.php';

$root = rv_root();
$exportsDir = $root . '/storage/exports/release';
$registryPath = $root . '/storage/state/release_registry_last.json';

$writeLast = false;
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if ($arg === '--write-last') $writeLast = true;
}

$zips = is_dir($exportsDir) ? (glob($exportsDir . '/release_pack_*.zip') ?: []) : [];
sort($zips);
$bundles = [];
foreach ($zips as $zip) {
    $base = basename($zip, '.zip');
    $manifest = $exportsDir . '/' . $base . '_manifest.json';
    $key = str_replace('release_pack_', '', $base);
    $bundles[] = [
        'bundle_key' => $key,
        'packs' => [
            ['zip' => $zip, 'manifest' => $manifest],
        ],
    ];
}

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'bundles' => $bundles,
    'bundle_count' => count($bundles),
];

if ($writeLast) {
    $dir = dirname($registryPath);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($registryPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
