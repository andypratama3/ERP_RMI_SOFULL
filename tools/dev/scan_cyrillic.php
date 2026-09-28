<?php
/**
 * scan_cyrillic.php — Scan path & content untuk Cyrillic (U+0400–U+04FF).
 * Output: storage/logs/cyrillic_scan_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$ROOT = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$EXCLUDE = ['/vendor/', '/node_modules/', '/storage/', '/.git/', '/exports/'];

function excluded(string $rel): bool {
    global $EXCLUDE;
    foreach ($EXCLUDE as $e) { if (strpos($rel, $e) !== false) return true; }
    return false;
}

function has_cyrillic(string $s): bool {
    return (bool)preg_match('/[\x{0400}-\x{04FF}]/u', $s);
}

function is_route_public(string $rel): bool {
    return (bool)preg_match('#^(api/|master/|sales/|purchases/|stock/|dashboards/|index\.php)#', $rel);
}

$foundPaths = [];
$foundContents = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS)
);

foreach ($it as $fi) {
    $path = $fi->getPathname();
    $rel = str_replace($ROOT . DIRECTORY_SEPARATOR, '', str_replace('\\', '/', $path));
    if (excluded('/' . $rel)) continue;

    $name = basename($path);
    if (has_cyrillic($name)) {
        $severity = is_route_public($rel) ? 'CRITICAL' : 'WARN';
        $foundPaths[] = ['path' => $rel, 'severity' => $severity];
    }

    if (!$fi->isFile()) continue;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'html', 'htm', 'js', 'css'], true)) continue;

    $content = @file_get_contents($path);
    if ($content === false || strlen($content) > 2 * 1024 * 1024) continue;

    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if (!has_cyrillic($line)) continue;
        $inPathContext = (strpos($line, '/') !== false || strpos($line, '.php') !== false
            || stripos($line, 'href=') !== false || stripos($line, 'require') !== false);
        $severity = $inPathContext ? 'CRITICAL' : 'WARN';
        $foundContents[] = [
            'file' => $rel,
            'line' => $i + 1,
            'severity' => $severity,
            'snippet_masked' => '[REDACTED]',
        ];
    }
}

$hasCritical = !empty(array_filter($foundPaths, fn($p) => $p['severity'] === 'CRITICAL'))
    || !empty(array_filter($foundContents, fn($c) => $c['severity'] === 'CRITICAL'));

$out = [
    'state_version' => 'cyrillic_scan_v1',
    'generated_at' => date('c'),
    'found_paths' => $foundPaths,
    'found_contents' => $foundContents,
    'path_count' => count($foundPaths),
    'content_count' => count($foundContents),
    'has_critical' => $hasCritical,
];

$logDir = $ROOT . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
file_put_contents($logDir . '/cyrillic_scan_last.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit($hasCritical ? 1 : 0);
