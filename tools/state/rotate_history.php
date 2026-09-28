<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$days = 30;
$maxSizeMb = 10;
$apply = false;
foreach ($args as $a) {
    if (str_starts_with($a, '--days=')) $days = max(1, (int)substr($a, 7));
    if (str_starts_with($a, '--max-size-mb=')) $maxSizeMb = max(1, (int)substr($a, 14));
    if ($a === '--apply') $apply = true;
}

$history = ts_storage_logs_dir() . '/tools_run_history.jsonl';
$archiveDir = ts_storage_logs_dir() . '/history_archive';
@mkdir($archiveDir, 0775, true);

if (!is_file($history)) {
    echo "History not found\n";
    exit(0);
}

$raw = @file($history, FILE_IGNORE_NEW_LINES) ?: [];
$threshold = time() - ($days * 86400);
$kept = [];
$dropped = 0;

foreach ($raw as $line) {
    $j = json_decode((string)$line, true);
    if (!is_array($j)) {
        $dropped++;
        continue;
    }
    $ts = isset($j['time']) ? strtotime((string)$j['time']) : 0;
    if ($ts > 0 && $ts < $threshold) {
        $dropped++;
        continue;
    }
    $kept[] = json_encode($j, JSON_UNESCAPED_SLASHES);
}

$sizeMb = ((is_file($history) ? (int)@filesize($history) : 0) / 1024 / 1024);
$needRotate = $sizeMb > $maxSizeMb;
$maxBytes = $maxSizeMb * 1024 * 1024;
$trimmedBySize = 0;
if ($needRotate && $kept) {
    $selected = [];
    $bytes = 0;
    for ($i = count($kept) - 1; $i >= 0; $i--) {
        $line = (string)$kept[$i];
        $lineBytes = strlen($line) + 1;
        if (($bytes + $lineBytes) > $maxBytes) {
            $trimmedBySize++;
            continue;
        }
        array_unshift($selected, $line);
        $bytes += $lineBytes;
    }
    $kept = $selected;
}

echo "rotate_history days={$days} max_size_mb={$maxSizeMb} apply=" . ($apply ? '1' : '0') . PHP_EOL;
echo "current_size_mb=" . round($sizeMb, 2) . " dropped={$dropped} trimmed_by_size={$trimmedBySize}" . PHP_EOL;

if (!$apply) {
    exit(0);
}

if ($needRotate) {
    $archive = $archiveDir . '/tools_run_history_' . date('Ymd_His') . '.jsonl.gz';
    $content = implode("\n", $raw) . "\n";
    @file_put_contents('compress.zlib://' . $archive, $content);
}

@file_put_contents($history, implode("\n", $kept) . (count($kept) ? "\n" : ''));
ts_write_json(ts_storage_logs_dir() . '/rotate_history.last.json', [
    'checked_at' => date(DateTimeInterface::ATOM),
    'ok' => true,
    'days' => $days,
    'max_size_mb' => $maxSizeMb,
    'rotated' => $needRotate,
    'dropped' => $dropped,
    'trimmed_by_size' => $trimmedBySize,
]);
echo "done\n";
