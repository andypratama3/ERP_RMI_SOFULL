<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$writeLast = false;
$tailBytes = 262144;

foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg)) continue;
    if ($arg === '--write-last') {
        $writeLast = true;
    } elseif (str_starts_with($arg, '--tail-bytes=')) {
        $tailBytes = (int)trim(substr($arg, 13));
    }
}

$tailBytes = max(1024, min($tailBytes, 1048576));
$logsDir = $root . '/storage/logs';
$pipelineDir = $root . '/storage/logs/pipeline';

$logPath = $logsDir . '/php_errors.log';
if (!is_file($logPath)) {
    $appLogs = glob($logsDir . '/app-*.log') ?: [];
    rsort($appLogs);
    $logPath = $appLogs[0] ?? '';
}

$counts = ['notice' => 0, 'warn' => 0, 'fatal' => 0];
$topFindings = [];
$overallOk = true;

if ($logPath !== '' && is_file($logPath)) {
    $size = (int)@filesize($logPath);
    $len = min($size, $tailBytes);
    $offset = max(0, $size - $len);
    $raw = $len > 0 ? (string)@file_get_contents($logPath, false, null, $offset, $len) : '';
    $lines = explode("\n", $raw);
    $rootNorm = str_replace('\\', '/', $root);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $masked = str_replace($rootNorm, '[APP_ROOT]', $line);
        $masked = preg_replace('/\b(password|pass|token|secret|db_pass)\s*[:=]\s*\S+/i', '$1=[REDACTED]', $masked) ?? $masked;
        $masked = preg_replace('/Authorization:\s*\S+/i', 'Authorization: [REDACTED]', $masked) ?? $masked;

        if (stripos($line, 'Fatal error') !== false || stripos($line, 'Fatal Error') !== false) {
            $counts['fatal']++;
            $topFindings[] = ['type' => 'fatal', 'line_masked' => substr($masked, 0, 200)];
        } elseif (preg_match('/\b(Warning|Notice)\b/i', $line)) {
            if (stripos($line, 'Warning') !== false) {
                $counts['warn']++;
                $topFindings[] = ['type' => 'warn', 'line_masked' => substr($masked, 0, 200)];
            } else {
                $counts['notice']++;
                $topFindings[] = ['type' => 'notice', 'line_masked' => substr($masked, 0, 200)];
            }
        } elseif (stripos($line, 'PHP Error:') !== false) {
            $counts['warn']++;
            $topFindings[] = ['type' => 'warn', 'line_masked' => substr($masked, 0, 200)];
        }
    }

    $topFindings = array_slice($topFindings, 0, 20);
    $overallOk = ($counts['fatal'] === 0 && $counts['warn'] === 0);
} else {
    $overallOk = true;
}

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'overall_ok' => $overallOk,
    'counts' => $counts,
    'top_findings' => $topFindings,
    'source' => $logPath !== '' ? ts_mask($logPath) : 'MISSING',
];

if ($writeLast) {
    if (!is_dir($pipelineDir)) {
        @mkdir($pipelineDir, 0775, true);
    }
    $outPath = $pipelineDir . '/php_error_scan_last.json';
    @file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
