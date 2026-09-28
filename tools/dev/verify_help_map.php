<?php
declare(strict_types=1);
/**
 * Verify help_sop_map.json: structure, referenced files, completeness.
 */
$root = dirname(__DIR__, 2);
$mapPath = $root . '/docs/help_sop_map.json';
$opBase = $root . '/docs/officepack/';

$raw = @file_get_contents($mapPath);
if ($raw === false) {
    echo "ERROR: Cannot read help_sop_map.json\n";
    exit(1);
}
$j = json_decode($raw, true);
if (json_last_error() !== 0) {
    echo "ERROR: Invalid JSON - " . json_last_error_msg() . "\n";
    exit(1);
}

$map = $j['map'] ?? [];
$issues = [];
$files = ['sop' => [], 'manual' => [], 'diagram' => []];

foreach ($map as $i => $e) {
    $path = $e['path'] ?? '';
    $purpose = trim($e['purpose'] ?? '');
    $steps = $e['steps'] ?? [];
    $tips = $e['tips'] ?? [];

    if (strlen($path) === 0) $issues[] = "[$i] empty path";
    if (strlen($purpose) < 15) $issues[] = "[$i] $path: purpose too short";
    if (count($steps) < 2) $issues[] = "[$i] $path: steps=" . count($steps);
    if (count($tips) < 2) $issues[] = "[$i] $path: tips=" . count($tips);

    foreach (['sop', 'manual', 'diagram'] as $k) {
        $v = $e[$k] ?? '';
        if (empty($v)) continue;
        if (preg_match('/f=([^&"\']+)/', $v, $m)) {
            $files[$k][$m[1]] = 1;
        }
    }
    foreach (['sop_keluhan', 'sop_recall', 'quick_start'] as $k) {
        $v = $e[$k] ?? '';
        if (empty($v)) continue;
        if (preg_match('/f=([^&"\']+)/', $v, $m)) {
            $files['sop'][$m[1]] = 1;
        }
    }
}

echo "=== help_sop_map.json Verification ===\n";
echo "Entries: " . count($map) . "\n";
echo "Issues: " . count($issues) . "\n";
foreach (array_slice($issues, 0, 15) as $x) echo "  $x\n";
if (count($issues) > 15) echo "  ... +" . (count($issues) - 15) . " more\n";

$missing = [];
foreach ($files as $type => $paths) {
    foreach (array_keys($paths) as $f) {
        $full = $opBase . $f;
        if (!file_exists($full)) {
            $missing[] = "$type: $f";
        }
    }
}
echo "\nMissing files: " . count($missing) . "\n";
foreach (array_slice($missing, 0, 15) as $m) echo "  $m\n";
if (count($missing) > 15) echo "  ... +" . (count($missing) - 15) . " more\n";

$masterCount = 0;
foreach ($map as $e) {
    if (strpos($e['path'] ?? '', '/master/') === 0) $masterCount++;
}
echo "\nMaster pages (untouched): $masterCount\n";

// Path format check: officepack_view expects f=path relative to docs/officepack/
echo "\n=== Path format (officepack_view.php?f=...) ===\n";
echo "Base: docs/officepack/\n";
echo "Format: f=dept_training/xxx.html atau f=diagrams/xxx.svg\n";
$allRefs = [];
foreach ($files as $paths) {
    foreach (array_keys($paths) as $f) {
        $allRefs[$f] = 1;
    }
}
$allRefs = array_keys($allRefs);
sort($allRefs);
echo "Unique refs: " . count($allRefs) . "\n";
foreach (array_slice($allRefs, 0, 20) as $r) {
    $exists = file_exists($opBase . $r) ? "OK" : "MISS";
    echo "  [$exists] $r\n";
}
if (count($allRefs) > 20) echo "  ... +" . (count($allRefs) - 20) . " more (all verified)\n";

echo "\n" . (count($issues) === 0 && count($missing) === 0 ? "OK: Semua file & path sesuai" : "WARN: Some issues found") . "\n";
