#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * audit_xss_csrf_scan.php — Heuristik audit XSS (output) & CSRF (POST).
 *
 * Bukan pemastian formal; hasil wajib ditinjau manusia (false positive/negative).
 *
 * Usage:
 *   php tools/dev/audit_xss_csrf_scan.php
 *   php tools/dev/audit_xss_csrf_scan.php /path/to/ERP_RMI_SOFULL
 *
 * Direkomendasikan di NAS: cd /volume4/web/ERP_RMI_SOFULL && php tools/dev/audit_xss_csrf_scan.php
 * Dari Mac mount: cd /Volumes/web/ERP_RMI_SOFULL && php tools/dev/audit_xss_csrf_scan.php
 */

$root = $argv[1] ?? (dirname(__DIR__, 2));
$root = realpath($root) ?: $root;

$skipDirs = [
    'vendor', 'node_modules', '.git', '_backup', 'exports',
    'canvas', 'storage', 'uploads', 'public',
];

$skipPathParts = [
    '/vendor/', '/node_modules/', '/_backup/', '/exports/',
    '/sql/', '/docs/', '/canvas/', '/.cursor/', '/tests/',
];

$extOk = ['php'];

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile()) {
        continue;
    }
    $path = $f->getPathname();
    $rel = substr($path, strlen($root) + 1);
    foreach ($skipPathParts as $part) {
        if (str_contains($path, $part)) {
            continue 2;
        }
    }
    // API: biasanya bearer / signed request, bukan form CSRF
    if (str_contains($path, DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $base = basename(dirname($path));
    if (in_array($base, $skipDirs, true)) {
        continue;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, $extOk, true)) {
        continue;
    }
    $files[] = $path;
}
sort($files);

$csrfHits = [];
$xssHits = [];

foreach ($files as $path) {
    $rel = substr($path, strlen($root) + 1);
    $content = @file_get_contents($path);
    if ($content === false || $content === '') {
        continue;
    }

    // --- CSRF heuristik: ada indikasi POST ke $_POST tapi tidak ada token/verify ---
    $hasPostUsage = (bool) preg_match('/\$_POST\s*\[/', $content);
    $hasMethodPost = stripos($content, "'POST'") !== false
        || stripos($content, '"POST"') !== false
        || stripos($content, '=== \'POST\'') !== false
        || stripos($content, '=== "POST"') !== false;
    $requestPost = stripos($content, 'REQUEST_METHOD') !== false && stripos($content, 'POST') !== false;

    if ($hasPostUsage && ($hasMethodPost || $requestPost)) {
        $hasCsrfHint = stripos($content, 'verify_csrf') !== false
            || stripos($content, 'csrf_token') !== false
            || stripos($content, 'rmi_csrf') !== false
            || stripos($content, 'csrf_verify') !== false
            || stripos($content, 'csrf_check_or_die') !== false
            || stripos($content, 'csrf_check(') !== false
            || stripos($content, 'csrf_field') !== false
            || stripos($content, 'verify_csrf_token') !== false;
        // API JSON-only sering tidak pakai form token; kurangi noise
        $maybeApiOnly = stripos($content, 'rmi_json') !== false
            && stripos($content, 'application/json') !== false;

        if (!$hasCsrfHint && !$maybeApiOnly) {
            $module = preg_match('#^([^/\\\\]+)#', $rel, $m) ? $m[1] : '?';
            $csrfHits[] = ['module' => $module, 'file' => $rel];
        }
    }

    // --- XSS heuristik: echo langsung superglobal ---
    if (preg_match_all('/\becho\s+(\$_(?:GET|POST|REQUEST|COOKIE)\b[^;]*);/m', $content, $mm)) {
        foreach ($mm[0] as $line) {
            if (stripos($line, 'rmi_h(') !== false || stripos($line, 'htmlspecialchars') !== false) {
                continue;
            }
            $module = preg_match('#^([^/\\\\]+)#', $rel, $m) ? $m[1] : '?';
            $xssHits[] = ['module' => $module, 'file' => $rel, 'sample' => trim($line)];
        }
    }
}

// Dedup CSRF by file
$csrfByFile = [];
foreach ($csrfHits as $h) {
    $csrfByFile[$h['file']] = $h;
}
$csrfHits = array_values($csrfByFile);
usort($csrfHits, static fn($a, $b) => [$a['module'], $a['file']] <=> [$b['module'], $b['file']]);

echo "=== audit_xss_csrf_scan ===\n";
echo "Root: {$root}\n";
echo "Files scanned: " . count($files) . "\n\n";

echo "--- CSRF (perlu review): POST + \$_POST tanpa verify_csrf/csrf_token/rmi_csrf ---\n";
echo "Count: " . count($csrfHits) . "\n";
foreach (array_slice($csrfHits, 0, 200) as $h) {
    echo "  [{$h['module']}] {$h['file']}\n";
}
if (count($csrfHits) > 200) {
    echo "  ... +" . (count($csrfHits) - 200) . " more\n";
}

echo "\n--- XSS (perlu review): echo \$_(GET|POST|REQUEST|COOKIE) tanpa rmi_h/htmlspecialchars ---\n";
echo "Count: " . count($xssHits) . "\n";
foreach (array_slice($xssHits, 0, 100) as $h) {
    echo "  [{$h['module']}] {$h['file']}\n      " . $h['sample'] . "\n";
}
if (count($xssHits) > 100) {
    echo "  ... +" . (count($xssHits) - 100) . " more\n";
}

echo "\nSelesai. Tinjau manual setiap temuan; heuristik bisa salah.\n";
exit(0);
