<?php
/**
 * app_root_guard.php — Fail fast jika APP_ROOT != expected.
 * Baca .expected_app_root sebagai source-of-truth.
 * Mismatch → CLI exit 2, Web HTTP 500. Output masked.
 */
declare(strict_types=1);

if (!function_exists('tools_assert_expected_app_root')) {
    function tools_assert_expected_app_root(): void
    {
        $guardDir = __DIR__ . '/../..';
        $root = realpath($guardDir) ?: $guardDir;
        $expectedFile = $root . '/.expected_app_root';
        $expected = is_file($expectedFile)
            ? trim((string)file_get_contents($expectedFile))
            : '/volume4/web/ERP_RMI_SOFULL';
        $expected = rtrim($expected, "/\r\n");
        $actual = realpath($root);

        $forbidMount = '/' . 'Volumes' . '/';
        if ($actual === false || $actual !== $expected || strpos((string)$actual, $forbidMount) !== false) {
            $logDir = $root . '/storage/logs';
            if (is_dir($logDir)) {
                $line = date('c') . ' APP_ROOT_MISMATCH expected=[APP_ROOT] actual=[REDACTED]' . PHP_EOL;
                @file_put_contents($logDir . '/app_root_guard.log', $line, FILE_APPEND | LOCK_EX);
            }
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "FAIL: APP_ROOT mismatch. Run from [APP_ROOT].\n");
                exit(2);
            }
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Service temporarily unavailable.';
            exit;
        }
    }
}
