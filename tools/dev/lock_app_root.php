<?php
/**
 * App Root Lock — CLI only.
 * Wajib dijalankan dari /volume4/web/ERP_RMI_SOFULL (NAS).
 * Menulis storage/logs/app_root_lock.json + append ke ASSUMPTIONS_LOG.md
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/tools_path_redact.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$REQUIRED_APP_ROOT = '/volume4/web/ERP_RMI_SOFULL';
$currentRoot = realpath(getcwd()) ?: getcwd();
$useDevRoot = in_array('--dev', $_SERVER['argv'] ?? [], true) || in_array('--apply', $_SERVER['argv'] ?? [], true) || (getenv('APP_ROOT_OVERRIDE') !== false && trim((string)getenv('APP_ROOT_OVERRIDE')) !== '');
if ($useDevRoot) {
    $REQUIRED_APP_ROOT = $currentRoot;
}

if ($currentRoot !== $REQUIRED_APP_ROOT) {
    $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    $logsDir = $root . '/storage/logs';
    if (!is_dir($logsDir)) @mkdir($logsDir, 0775, true);
    $assumptions = [
        'code' => 'APP_ROOT_MISMATCH',
        'expected' => $REQUIRED_APP_ROOT,
        'actual' => tools_redact_mac_mount_paths_in_string($currentRoot),
        'hint' => "cd {$REQUIRED_APP_ROOT} && php tools/dev/lock_app_root.php",
        'generated_at' => date('c'),
    ];
    tools_safe_json_file_put_contents($logsDir . '/assumptions_last.json', $assumptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "FAIL: App root mismatch.\n";
    echo "  Expected: {$REQUIRED_APP_ROOT}\n";
    echo "  Actual:   {$currentRoot}\n";
    echo "  Run: cd {$REQUIRED_APP_ROOT} && php tools/dev/lock_app_root.php\n";
    exit(2);
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$logsDir = $root . '/storage/logs';
$docsDir = $root . '/docs/governance';

if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0775, true);
}
if (!is_dir($docsDir)) {
    @mkdir($docsDir, 0775, true);
}

$lock = [
    'state_version' => 'app_root_lock_v1',
    'locked_at' => date('c'),
    'app_root' => $currentRoot,
    'request_id' => bin2hex(random_bytes(8)),
];

$lockPath = $logsDir . '/app_root_lock.json';
$written = tools_safe_json_file_put_contents($lockPath, $lock, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!$written) {
    echo "FAIL: Cannot write {$lockPath}\n";
    exit(3);
}

$assumptionsPath = $docsDir . '/ASSUMPTIONS_LOG.md';
$line = date('Y-m-d H:i:s') . " | APP_ROOT_LOCK | app_root locked | {$REQUIRED_APP_ROOT} | OK\n";
if (is_file($assumptionsPath)) {
    @file_put_contents($assumptionsPath, $line, FILE_APPEND | LOCK_EX);
} else {
    $header = "# Assumptions Log — Tools Doctor\n\n| Date | Context | Item | Hint | Status |\n|------|---------|------|------|--------|\n";
    @file_put_contents($assumptionsPath, $header . $line);
}

echo "OK: app_root locked at {$REQUIRED_APP_ROOT}\n";
echo "  Lock file: {$lockPath}\n";
exit(0);
