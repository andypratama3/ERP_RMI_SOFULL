<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$warn = [];
$okCount = 0;
$skipDirs = ['vendor', '.git', 'storage'];

foreach ($rii as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    $rel = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
    $parts = explode('/', $rel);
    if (in_array($parts[0] ?? '', $skipDirs, true)) {
        continue;
    }

    $raw = (string)@file_get_contents($path);
    if ($raw === '') {
        continue;
    }
    if (preg_match('/\b(require|include)\b(?!_once)/', $raw)) {
        $warn[] = $rel;
        continue;
    }
    $okCount++;
}

echo "verify_includes\n";
echo "ok_files={$okCount}\n";
echo "warn_files=" . count($warn) . "\n";
foreach ($warn as $w) {
    echo "WARN include_without_once {$w}\n";
}
exit(count($warn) === 0 ? 0 : 1);
