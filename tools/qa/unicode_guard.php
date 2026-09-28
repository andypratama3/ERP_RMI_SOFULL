<?php
/**
 * Unicode Guard — Cyrillic purge scanner
 * Usage: php tools/qa/unicode_guard.php [--scan] [--strict] [--write-last]
 * Output: storage/logs/unicode_guard_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$ROOT = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$EXCLUDE = ['/vendor/', '/node_modules/', '/storage/', '/uploads/', '/.git/', '/exports/', '/tools/dev/cyrillic', '/docs/'];

function excluded(string $rel): bool {
    global $EXCLUDE;
    foreach ($EXCLUDE as $e) { if (strpos($rel, $e) !== false) return true; }
    if (strpos($rel, '/storage/logs/') !== false && !preg_match('#\.last\.json$#', $rel)) return true;
    return false;
}

function has_cyrillic(string $s): bool {
    return (bool)preg_match('/[\x{0400}-\x{04FF}]/u', $s);
}

function path_context(string $l): bool {
    return (strpos($l, '/') !== false || strpos($l, '.php') !== false
        || stripos($l, 'href=') !== false || stripos($l, 'src=') !== false
        || stripos($l, 'action=') !== false || stripos($l, 'Location:') !== false
        || preg_match('#["\'][^"\']*\.php#', $l));
}

function extract_cyrillic_chars(string $s): array {
    $chars = [];
    if (!preg_match_all('/[\x{0400}-\x{04FF}]/u', $s, $m)) return $chars;
    foreach (array_unique($m[0]) as $ch) {
        $codepoint = 'U+' . strtoupper(dechex(mb_ord($ch, 'UTF-8')));
        $chars[] = ['ch' => $ch, 'codepoint' => $codepoint, 'name' => 'CYRILLIC'];
    }
    return $chars;
}

$args = $argv ?? [];
$writeLast = in_array('--write-last', $args, true);
$strict = in_array('--strict', $args, true);

function unicode_guard_output_error(Throwable $e, bool $writeLast): void {
    global $ROOT;
    if (!function_exists('tools_mask_sensitive')) {
        require_once __DIR__ . '/../tools_ui_helpers.php';
    }
    $msg = function_exists('tools_mask_sensitive') ? tools_mask_sensitive($e->getMessage()) : $e->getMessage();
    $out = [
        'state_version' => 'unicode_guard_v1',
        'generated_at' => date('c'),
        'app_root' => '[APP_ROOT]',
        'ok' => false,
        'errors' => [$msg],
        'findings' => [],
        'counts' => ['path_cyrillic' => 0, 'content_cyrillic_path_ctx' => 0, 'content_cyrillic_general' => 0],
    ];
    if ($writeLast && is_dir($ROOT . '/storage/logs')) {
        @file_put_contents($ROOT . '/storage/logs/unicode_guard_last.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(2);
}

$findings = [];
$counts = ['path_cyrillic' => 0, 'content_cyrillic_path_ctx' => 0, 'content_cyrillic_general' => 0];

try {
// 1) Scan paths + 2) content in single pass
$exts = ['php', 'json', 'html', 'htm', 'js', 'css', 'md', 'yml'];
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

    // Path: check basename (file or dir)
    $name = basename($path);
    if (has_cyrillic($name)) {
        $findings[] = ['type' => 'PATH_CYRILLIC', 'path' => $rel, 'chars' => extract_cyrillic_chars($name)];
        $counts['path_cyrillic']++;
    }

    // Content: only for relevant file types
    if (!$fi->isFile()) continue;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) continue;
    $content = @file_get_contents($path);
    if ($content === false || strlen($content) > 2 * 1024 * 1024) continue; // skip >2MB
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if (!has_cyrillic($line)) continue;
        $lineNo = $i + 1;
        $snippet = mb_substr(trim($line), 0, 120);
        if (path_context($line)) {
            $findings[] = ['type' => 'CONTENT_CYRILLIC_IN_PATH_CONTEXT', 'path' => $rel, 'line' => $lineNo, 'snippet' => $snippet];
            $counts['content_cyrillic_path_ctx']++;
        } else {
            $findings[] = ['type' => 'CONTENT_CYRILLIC_GENERAL', 'path' => $rel, 'line' => $lineNo, 'snippet' => $snippet];
            $counts['content_cyrillic_general']++;
        }
    }
}

$ok = ($counts['path_cyrillic'] === 0 && $counts['content_cyrillic_path_ctx'] === 0);
if ($strict) {
    $ok = $ok && ($counts['content_cyrillic_general'] === 0);
}
} catch (Throwable $e) {
    unicode_guard_output_error($e, $writeLast);
}

$out = [
    'state_version' => 'unicode_guard_v1',
    'generated_at' => date('c'),
    'app_root' => '[APP_ROOT]',
    'ok' => $ok,
    'counts' => $counts,
    'findings' => $findings,
];

$logDir = $ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
if ($writeLast) {
    file_put_contents($logDir . '/unicode_guard_last.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit($ok ? 0 : 2);
