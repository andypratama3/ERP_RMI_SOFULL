<?php
/**
 * cyrillic_fix.php — Rename files/folders with Cyrillic in name. Apply mode.
 * Usage: php tools/dev/cyrillic_fix.php --apply --i-understand
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$args = $argv ?? [];
if (!in_array('--apply', $args, true) || !in_array('--i-understand', $args, true)) {
    fwrite(STDERR, "Usage: php tools/dev/cyrillic_fix.php --apply --i-understand\n");
    exit(1);
}

$ROOT = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$EXCLUDE = ['/vendor/', '/node_modules/', '/storage/', '/.git/', '/exports/'];

$CYRILLIC_TO_LATIN = [
    'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x',
    'А' => 'A', 'Е' => 'E', 'О' => 'O', 'Р' => 'P', 'С' => 'C', 'У' => 'Y', 'Х' => 'X',
];

function excluded(string $rel): bool {
    global $EXCLUDE;
    foreach ($EXCLUDE as $e) { if (strpos($rel, $e) !== false) return true; }
    return false;
}

function has_cyrillic(string $s): bool {
    return (bool)preg_match('/[\x{0400}-\x{04FF}]/u', $s);
}

function transliterate(string $s): string {
    global $CYRILLIC_TO_LATIN;
    $out = '';
    $len = mb_strlen($s, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($s, $i, 1, 'UTF-8');
        if (isset($CYRILLIC_TO_LATIN[$ch])) {
            $out .= $CYRILLIC_TO_LATIN[$ch];
        } elseif (preg_match('/[\x{0400}-\x{04FF}]/u', $ch)) {
            $out .= '_u' . strtoupper(dechex(mb_ord($ch, 'UTF-8')));
        } else {
            $out .= $ch;
        }
    }
    return $out;
}

function write_assumptions(string $reason, string $nextAction): void {
    global $ROOT;
    $logDir = $ROOT . '/storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    $payload = [
        'generated_at' => date('c'),
        'expected_root' => '[APP_ROOT]',
        'actual_root' => '[APP_ROOT]',
        'reason' => $reason,
        'next_action' => $nextAction,
    ];
    @file_put_contents($logDir . '/assumptions_log_last.json', json_encode($payload, JSON_PRETTY_PRINT));
}

$candidates = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $fi) {
    $path = $fi->getPathname();
    $rel = str_replace($ROOT . DIRECTORY_SEPARATOR, '', str_replace('\\', '/', $path));
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    if (excluded('/' . $rel)) continue;

    $name = basename($path);
    if (!has_cyrillic($name)) continue;

    $newName = transliterate($name);
    if ($newName === $name) continue;

    $parent = dirname($path);
    $newPath = $parent . DIRECTORY_SEPARATOR . $newName;

    if (file_exists($newPath)) {
        write_assumptions('cyrillic_rename_collision: ' . $rel . ' -> ' . $newName, 'Resolve collision manually');
        fwrite(STDERR, "FAIL: Collision - target exists: $newPath\n");
        exit(2);
    }

    $candidates[] = ['old' => $path, 'new' => $newPath, 'rel_old' => $rel, 'rel_new' => dirname($rel) . '/' . $newName];
}

usort($candidates, static fn($a, $b) => strlen($b['old']) - strlen($a['old']));

$renameMap = [];
foreach ($candidates as $c) {
    if (!@rename($c['old'], $c['new'])) {
        write_assumptions('cyrillic_rename_failed: ' . $c['rel_old'], 'Check permissions');
        fwrite(STDERR, "FAIL: Cannot rename {$c['old']}\n");
        exit(3);
    }
    $renameMap[$c['rel_old']] = $c['rel_new'];
}

if ($renameMap !== []) {
    uksort($renameMap, static fn($a, $b) => strlen($b) - strlen($a));
    foreach (['php', 'html', 'htm', 'js', 'css', 'json'] as $ext) {
        $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it2 as $fi) {
            if (!$fi->isFile()) continue;
            if (strtolower(pathinfo($fi->getFilename(), PATHINFO_EXTENSION)) !== $ext) continue;
            $rel = str_replace($ROOT . '/', '', str_replace('\\', '/', $fi->getPathname()));
            if (excluded('/' . $rel)) continue;

            $content = @file_get_contents($fi->getPathname());
            if ($content === false) continue;

            $changed = false;
            foreach ($renameMap as $old => $new) {
                if (strpos($content, $old) !== false) {
                    $content = str_replace($old, $new, $content);
                    $changed = true;
                }
            }
            if ($changed) {
                file_put_contents($fi->getPathname(), $content);
            }
        }
    }
}

$logDir = $ROOT . '/storage/logs';
$docsDir = $ROOT . '/docs/governance';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
if (!is_dir($docsDir)) @mkdir($docsDir, 0775, true);

$mapJson = ['state_version' => 'cyrillic_rename_v1', 'renames' => $renameMap, 'generated_at' => date('c')];
file_put_contents($logDir . '/cyrillic_rename_map_last.json', json_encode($mapJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$mapMd = "# Cyrillic Rename Map\n\n| Old | New |\n|-----|-----|\n";
foreach ($renameMap as $old => $new) {
    $mapMd .= "| $old | $new |\n";
}
file_put_contents($docsDir . '/CYRILLIC_RENAME_MAP.md', $mapMd);

echo "OK: Renamed " . count($renameMap) . " item(s)\n";
exit(0);
