<?php
/**
 * ban_non_ascii_paths.php — Scan paths, FAIL if any non-ASCII.
 * Gate: prevent new non-ASCII file/folder names.
 * Usage: php tools/dev/ban_non_ascii_paths.php
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

function has_non_ascii(string $s): bool {
    return !preg_match('/^[\x00-\x7F]*$/u', $s);
}

$found = [];
class SkipEaDirFilter extends RecursiveFilterIterator {
    public function accept(): bool {
        $name = $this->current()->getFilename();
        return $name !== '@eaDir' && ($name === '' || $name[0] !== '.');
    }
    public function getChildren(): static {
        try {
            return new static(parent::getChildren());
        } catch (\Exception $e) {
            return new static(new \EmptyIterator());
        }
    }
}
$it = new RecursiveIteratorIterator(
    new SkipEaDirFilter(new RecursiveDirectoryIterator($ROOT, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS)),
    RecursiveIteratorIterator::SELF_FIRST,
    RecursiveIteratorIterator::CATCH_GET_CHILD
);

foreach ($it as $fi) {
    $path = $fi->getPathname();
    $rel = str_replace($ROOT . DIRECTORY_SEPARATOR, '', str_replace('\\', '/', $path));
    if (excluded('/' . $rel)) continue;

    $name = basename($path);
    if (has_non_ascii($name)) {
        $found[] = $rel;
    }
}

$logDir = $ROOT . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$out = [
    'state_version' => 'ban_non_ascii_v1',
    'generated_at' => date('c'),
    'found' => $found,
    'count' => count($found),
    'ok' => empty($found),
];
file_put_contents($logDir . '/ban_non_ascii_paths_last.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if (!empty($found)) {
    fwrite(STDERR, "FAIL: Non-ASCII paths found:\n");
    foreach ($found as $p) {
        fwrite(STDERR, "  - $p\n");
    }
    exit(1);
}
echo "OK: No non-ASCII paths\n";
exit(0);
