<?php
declare(strict_types=1);

/**
 * Generate PNG icons for PWA (Chrome Android compatibility).
 * Run: php tools/pwa/generate_icons.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD extension required.\n");
    exit(1);
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$outDir = $root . '/public';
if (!is_dir($outDir)) {
    fwrite(STDERR, "public/ not found.\n");
    exit(1);
}

$bgColor = [30, 41, 59];   // #1e293b
$textColor = [248, 250, 252]; // #f8fafc

function create_icon_simple(int $size, array $bg, array $fg) {
    $img = @imagecreatetruecolor($size, $size);
    if (!$img) return null;
    $bgCol = imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);
    $fgCol = imagecolorallocate($img, $fg[0], $fg[1], $fg[2]);
    imagefill($img, 0, 0, $bgCol);
    $font = 5;
    $tw = 8 * 3;
    $th = 13;
    $cx = (int)max(0, round(($size - $tw) / 2));
    $cy = (int)max(0, round(($size - $th) / 2));
    imagestring($img, $font, $cx, $cy, 'ERP', $fgCol);
    return $img;
}

$sizes = [192, 512];
$created = 0;
foreach ($sizes as $s) {
    $img = @create_icon_simple($s, $bgColor, $textColor);
    if (!$img) {
        fwrite(STDERR, "Failed to create {$s}x{$s}\n");
        continue;
    }
    $path = $outDir . "/icon-{$s}.png";
    if (imagepng($img, $path, 6)) {
        echo "Created: public/icon-{$s}.png\n";
        $created++;
    }
}

if ($created === 0) {
    exit(1);
}

// Update manifest to include PNG fallbacks
$manifestPath = $outDir . '/manifest.json';
if (is_file($manifestPath)) {
    $m = json_decode(file_get_contents($manifestPath), true);
    if (is_array($m) && isset($m['icons'])) {
        $hasPng = false;
        foreach ($m['icons'] as $i) {
            if (str_contains($i['src'] ?? '', '.png')) {
                $hasPng = true;
                break;
            }
        }
        if (!$hasPng && $created > 0) {
            $newIcons = [];
            foreach ([192, 512] as $s) {
                if (is_file($outDir . "/icon-{$s}.png")) {
                    $newIcons[] = [
                        'src' => "icon-{$s}.png",
                        'sizes' => "{$s}x{$s}",
                        'type' => 'image/png',
                        'purpose' => 'any maskable',
                    ];
                }
            }
            $m['icons'] = array_merge($newIcons, $m['icons']);
            file_put_contents($manifestPath, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            echo "Updated manifest.json with PNG icons.\n";
        }
    }
}

echo "Done. {$created} PNG icon(s) created.\n";
exit(0);
