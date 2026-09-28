<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/_lib/playwright_smoke_lib.php';

$args = $_SERVER['argv'] ?? [];
$baseUrl = '';
$runId = '';
$writeLast = false;
foreach ($args as $arg) {
    if (!is_string($arg)) continue;
    if (str_starts_with($arg, '--base-url=')) $baseUrl = trim((string)substr($arg, 11));
    if (str_starts_with($arg, '--run-id=')) $runId = trim((string)substr($arg, 9));
    if ($arg === '--write-last') $writeLast = true;
}

$root = pwsmoke_root();
$runId = $runId !== '' ? $runId : ('playwright-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8));
$baseUrl = $baseUrl !== '' ? $baseUrl : (trim((string)getenv('TOOLS_BASE_URL')) ?: trim((string)getenv('SMOKE_BASE_URL')) ?: 'http://127.0.0.1');

$ran = false;
$warnOnly = false;
$failures = [];
$screenshotsCount = 0;
$overallOk = true;

if (!pwsmoke_has_node() || !pwsmoke_has_npm()) {
    $warnOnly = true;
    $failures[] = ['code' => 'NODE_OR_NPM_MISSING', 'message' => 'node or npm not found; skip Playwright'];
} elseif (pwsmoke_find_config($root) === null) {
    $warnOnly = true;
    $failures[] = ['code' => 'PLAYWRIGHT_NOT_CONFIGURED', 'message' => 'Playwright config not found'];
} else {
    $configPath = pwsmoke_find_config($root);
    $env = 'PW_BASE_URL=' . escapeshellarg($baseUrl);
    if (trim((string)getenv('PW_ADMIN_USER')) !== '') $env .= ' PW_ADMIN_USER=' . escapeshellarg(getenv('PW_ADMIN_USER'));
    if (trim((string)getenv('PW_ADMIN_PASS')) !== '') $env .= ' PW_ADMIN_PASS=' . escapeshellarg(getenv('PW_ADMIN_PASS'));
    $cmd = 'cd ' . escapeshellarg($root) . ' && ' . $env . ' npx playwright test --reporter=line 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    $ran = true;
    $overallOk = ((int)$code === 0);
    if ((int)$code !== 0) {
        $failures[] = ['code' => 'PLAYWRIGHT_FAIL', 'message' => implode(' ', array_slice($out, -3))];
    }
    $testResultsDir = $root . '/test-results';
    if (is_dir($testResultsDir)) {
        $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testResultsDir, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
        foreach ($it as $fi) {
            if ($fi->isFile() && in_array(strtolower($fi->getExtension()), ['png', 'jpg', 'jpeg'], true)) {
                $screenshotsCount++;
            }
        }
    }
}

$exportsDir = $root . '/storage/exports/release';
$screenshotsDir = $exportsDir . '/ui_screenshots_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId);
$zipPath = $exportsDir . '/ui_screenshots_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) . '.zip';
if ($ran && $screenshotsCount > 0 && is_dir($root . '/test-results')) {
    if (!is_dir($exportsDir)) @mkdir($exportsDir, 0775, true);
    if (!is_dir($screenshotsDir)) @mkdir($screenshotsDir, 0775, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/test-results', RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($it as $fi) {
        if ($fi->isFile() && in_array(strtolower($fi->getExtension()), ['png', 'jpg', 'jpeg'], true)) {
            @copy($fi->getPathname(), $screenshotsDir . '/' . basename($fi->getPathname()));
        }
    }
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $files = glob($screenshotsDir . '/*');
            foreach ($files ?: [] as $f) {
                if (is_file($f)) $zip->addFile($f, basename($f));
            }
            $zip->close();
        }
    }
}

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'run_id' => $runId,
    'overall_ok' => $overallOk || $warnOnly,
    'ran' => $ran,
    'warn_only' => $warnOnly,
    'screenshots_count' => $screenshotsCount,
    'failures' => array_map(static function (array $f): array {
        return [
            'code' => $f['code'] ?? '',
            'message' => function_exists('ts_mask') ? ts_mask((string)($f['message'] ?? '')) : ($f['message'] ?? ''),
        ];
    }, $failures),
];

$logDir = $root . '/storage/logs/pipeline';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$jsonPath = $logDir . '/playwright_smoke_last.json';
$mdPath = $logDir . '/playwright_smoke_last.md';
if ($writeLast) {
    ts_write_json($jsonPath, $payload);
    $md = [];
    $md[] = '# Playwright Smoke Last';
    $md[] = '';
    $md[] = '- generated_at: ' . $payload['generated_at'];
    $md[] = '- run_id: ' . (function_exists('ts_mask') ? ts_mask($runId) : $runId);
    $md[] = '- overall_ok: ' . ($payload['overall_ok'] ? 'true' : 'false');
    $md[] = '- ran: ' . ($ran ? 'true' : 'false');
    $md[] = '- warn_only: ' . ($warnOnly ? 'true' : 'false');
    $md[] = '- screenshots_count: ' . $screenshotsCount;
    $md[] = '';
    $md[] = '## Failures';
    foreach ($payload['failures'] as $f) {
        $md[] = '- [' . ($f['code'] ?? '') . '] ' . ($f['message'] ?? '');
    }
    @file_put_contents($mdPath, implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($payload['overall_ok'] ? 0 : 1);
