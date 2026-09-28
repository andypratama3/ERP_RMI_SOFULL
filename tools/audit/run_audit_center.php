<?php
declare(strict_types=1);

/**
 * Audit Center - CLI orchestrator
 * Menjalankan semua audit: PHP static analysis, security scanner, PHP lint, migration lint, readiness.
 * Jalankan: php tools/audit/run_audit_center.php [--quick] [--write-last]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$quick = in_array('--quick', $args, true);
$writeLast = in_array('--write-last', $args, true);
$strict = in_array('--strict', $args, true);
$phpBin = (string)(defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php');

$steps = [
    [
        'id' => 'php_static_analysis',
        'name' => 'PHP Static Analysis (PHPStan/php -l)',
        'cmd' => $phpBin . ' ' . escapeshellarg($root . '/tools/audit/php_static_analysis.php') . ' --scope=core' . ($writeLast ? ' --write-last' : ''),
        'artifact' => ts_storage_logs_dir() . '/audit_php_static_analysis_last.json',
    ],
    [
        'id' => 'security_scanner',
        'name' => 'Security Scanner (SQLi/XSS/Creds)',
        'cmd' => $phpBin . ' ' . escapeshellarg($root . '/tools/audit/security_scanner.php') . ' --scope=core' . ($writeLast ? ' --write-last' : ''),
        'artifact' => ts_storage_logs_dir() . '/audit_security_scanner_last.json',
    ],
    [
        'id' => 'php_lint',
        'name' => 'PHP Syntax Lint',
        'cmd' => '(cd ' . escapeshellarg($root) . ' && find master sales purchases stock dashboards hrl kpi mpr tools _shared api -name "*.php" 2>/dev/null | head -300 | xargs -n1 ' . escapeshellarg($phpBin) . ' -l 2>&1)',
        'artifact' => null,
    ],
];

if (!$quick) {
    $migrationLint = $root . '/tools/qa/migration_sql_lint.php';
    if (is_file($migrationLint)) {
        $steps[] = [
            'id' => 'migration_sql_lint',
            'name' => 'Migration SQL Lint',
            'cmd' => $phpBin . ' ' . escapeshellarg($migrationLint) . ' --dir=sql/migrations --write-last --strict',
            'artifact' => ts_storage_logs_dir() . '/migration_sql_lint_last.json',
        ];
    }
}

if ($strict) {
    $auditE2E = $root . '/tools/qa/audit_e2e_probe.php';
    if (is_file($auditE2E)) {
        $steps[] = [
            'id' => 'audit_trail_e2e',
            'name' => 'Audit Trail E2E (request_id, actor, mutation coverage)',
            'cmd' => $phpBin . ' ' . escapeshellarg($auditE2E) . ' --write-last --strict --lookback-days=14',
            'artifact' => ts_storage_logs_dir() . '/audit_e2e_probe_last.json',
        ];
    }
}

$results = [];
$overallOk = true;

foreach ($steps as $step) {
    $t0 = microtime(true);
    $out = [];
    $code = 1;
    @exec($step['cmd'] . ' 2>&1', $out, $code);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $ok = ((int)$code === 0);
    if (!$ok) $overallOk = false;
    $results[] = [
        'id' => $step['id'],
        'name' => $step['name'],
        'ok' => $ok,
        'exit_code' => (int)$code,
        'duration_ms' => $ms,
        'output_tail' => array_slice($out, -5),
    ];
}

$payload = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'mode' => $quick ? 'quick' : 'full',
    'overall_ok' => $overallOk,
    'steps' => $results,
    'summary' => [
        'total' => count($results),
        'pass' => count(array_filter($results, fn($r) => $r['ok'])),
        'fail' => count(array_filter($results, fn($r) => !$r['ok'])),
    ],
];

if ($writeLast) {
    ts_write_json(ts_storage_logs_dir() . '/audit_center_last.json', $payload);
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($overallOk ? 0 : 1);
