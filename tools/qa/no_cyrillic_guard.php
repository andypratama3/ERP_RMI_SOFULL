<?php
/**
 * no_cyrillic_guard.php — Check-only: scan path & content for Cyrillic (U+0400–U+04FF).
 * Output: storage/logs/no_cyrillic_guard_last.json
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
    foreach ($EXCLUDE as $e) {
        if (strpos($rel, $e) !== false) return true;
    }
    return false;
}

function has_cyrillic(string $s): bool {
    return (bool)preg_match('/[\x{0400}-\x{04FF}]/u', $s);
}

function url_route_context(string $line): bool {
    return (strpos($line, '/') !== false || strpos($line, '.php') !== false
        || stripos($line, 'href=') !== false || stripos($line, 'src=') !== false
        || stripos($line, 'action=') !== false || stripos($line, 'Location:') !== false
        || stripos($line, 'require') !== false || stripos($line, 'include') !== false
        || preg_match('#["\'][^"\']*\.(php|html|js|css)#', $line));
}

function mask_snippet(string $s): string {
    return preg_replace('#/[^\s"\']+#', '[PATH]', $s) ?? $s;
}

$hits = [];
$totalHits = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
    RecursiveIteratorIterator::SELF_FIRST,
    RecursiveIteratorIterator::CATCH_GET_CHILD
);

foreach ($it as $fi) {
    $path = $fi->getPathname();
    $rel = str_replace($ROOT . DIRECTORY_SEPARATOR, '', str_replace('\\', '/', $path));
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    if (excluded('/' . $rel)) continue;

    $name = basename($path);

    if (has_cyrillic($name)) {
        $hits[] = [
            'type' => 'path',
            'file' => $rel,
            'snippet_masked' => mask_snippet($name),
            'code' => 'CYRILLIC_DETECTED',
        ];
        $totalHits++;
    }

    if (!$fi->isFile()) continue;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'html', 'htm', 'js', 'css'], true)) continue;

    $content = @file_get_contents($path);
    if ($content === false || strlen($content) > 2 * 1024 * 1024) continue;

    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if (!has_cyrillic($line)) continue;
        if (!url_route_context($line)) continue;

        $snippet = mb_substr(trim($line), 0, 100);
        $hits[] = [
            'type' => 'content',
            'file' => $rel,
            'snippet_masked' => mask_snippet($snippet),
            'code' => 'CYRILLIC_DETECTED',
            'line' => $i + 1,
        ];
        $totalHits++;
    }
}

$ok = ($totalHits === 0);

$out = [
    'state_version' => 'no_cyrillic_guard_v1',
    'ok' => $ok,
    'hits' => $hits,
    'total_hits' => $totalHits,
    'generated_at' => date('c'),
];

$logDir = $ROOT . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
file_put_contents($logDir . '/no_cyrillic_guard_last.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit($ok ? 0 : 1);
