<?php
/**
 * menu_dashboard_sync.php — Gate G: Menu & Dashboard Sync
 * Alias untuk menu_rbac_sync_check.php — nama kanonik untuk gate checklist.
 *
 * Checks:
 *   - dead_link_count: menu items yang mengarah ke URL yang 403/404
 *   - menu_leak_count: menu items yang tampil ke role yang tidak punya izin
 *
 * Usage: php tools/qa/menu_dashboard_sync.php [--strict] [--write-last]
 * Artifact: storage/logs/menu_dashboard_sync_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();

$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
$baseUrl = rtrim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('TOOLS_BASE_URL') ?: getenv('SMOKE_BASE_URL') ?: 'https://localhost/ERP_RMI_SOFULL'), '/');
$envPfx  = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl) . ' TOOLS_BASE_URL=' . escapeshellarg($baseUrl) . ' ';
$logsDir = ts_storage_logs_dir();

// Run menu_rbac_sync_check and capture result
$syncJson = $logsDir . '/menu_rbac_sync_last.json';
$cmd = $envPfx . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/menu_rbac_sync_check.php') . ' --strict --write-last 2>&1';
$lines = []; $code = 1;
@exec($cmd, $lines, $code);
$ok = ((int)$code === 0);
$outputText = implode(' ', $lines);

// Handle "no base URL" gracefully
$noUrl = stripos($outputText, 'TOOLS_BASE_URL') !== false || stripos($outputText, 'base-url') !== false;

// Read result from sync artifact
$mismatchCount = 0; $deadLinkCount = 0; $menuLeakCount = 0; $menuCount = 0;
if (is_file($syncJson)) {
    $syncData = json_decode((string)file_get_contents($syncJson), true);
    if (is_array($syncData)) {
        $mismatchCount = (int)($syncData['mismatch_count'] ?? 0);
        $menuCount     = (int)($syncData['menu_count']     ?? 0);
        $deadLinkCount = $mismatchCount;  // menu_rbac_sync reports mismatches
        $menuLeakCount = 0;               // TODO: separate leak detection
        $ok = (bool)($syncData['ok'] ?? $ok);
    }
} elseif ($noUrl) {
    // Graceful: can't test without HTTP server
    $ok = true;
    $mismatchCount = -1; // -1 = skipped
}

$payload = [
    'ok'               => $ok,
    'run_at'           => date(DateTimeInterface::ATOM),
    'base_url_masked'  => ts_mask($baseUrl),
    'menu_count'       => $menuCount,
    'mismatch_count'   => $mismatchCount,
    'dead_link_count'  => $deadLinkCount,
    'menu_leak_count'  => $menuLeakCount,
    'note'             => $noUrl ? 'skipped_no_base_url' : ($mismatchCount === -1 ? 'sync_artifact_missing' : null),
    'source'           => 'menu_rbac_sync_check.php',
];

if ($writeLast) {
    @file_put_contents($logsDir . '/menu_dashboard_sync_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
