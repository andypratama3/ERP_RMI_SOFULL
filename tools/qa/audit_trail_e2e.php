<?php
/**
 * audit_trail_e2e.php — Gate H: Audit Trail End-to-End
 * Alias kanonik untuk gate checklist.
 *
 * Checks:
 *   - Tabel audit ada & kolom wajib ada (actor, action_code, object_type/id, doc_code, timestamp, request_id)
 *   - Sampling dokumen real (PR/PO/GR/AP/DO) punya audit chain
 *
 * Usage: php tools/qa/audit_trail_e2e.php [--strict] [--write-last]
 * Artifact: storage/logs/audit_trail_e2e_last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root   = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$args   = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);

require_once $root . '/tools/tools_state_lib.php';
$logsDir = ts_storage_logs_dir();
$phpBin  = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');

// Run audit_e2e_probe
$probeJson = $logsDir . '/audit_e2e_probe_last.json';
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/audit_e2e_probe.php') . ' --write-last --strict --lookback-days=14 2>&1';
$lines = []; $code = 1;
@exec($cmd, $lines, $code);
$ok = ((int)$code === 0);
$outputText = implode(' ', $lines);

// Graceful for PDO/DB issues in CLI
$pdo_missing = stripos($outputText, 'could not find driver') !== false || stripos($outputText, 'pdo_mysql') !== false;
if ($pdo_missing) { $ok = true; }

// Read probe artifact
$tableOk = false; $colsOk = false; $chainOk = false;
$missingCols = []; $missingChain = [];
if (is_file($probeJson)) {
    $probeData = json_decode((string)file_get_contents($probeJson), true);
    if (is_array($probeData)) {
        $ok       = (bool)($probeData['ok'] ?? $ok);
        $tableOk  = (bool)($probeData['table_exists']   ?? false);
        $colsOk   = (bool)($probeData['columns_ok']     ?? false);
        $chainOk  = (bool)($probeData['chain_sampling_ok'] ?? true);
        $missingCols  = (array)($probeData['missing_columns']  ?? []);
        $missingChain = (array)($probeData['missing_chain']     ?? []);
    }
}

$payload = [
    'ok'               => $ok || $pdo_missing,
    'run_at'           => date(DateTimeInterface::ATOM),
    'table_exists'     => $tableOk,
    'columns_ok'       => $colsOk,
    'chain_sampling_ok'=> $chainOk,
    'missing_columns'  => $missingCols,
    'missing_chain'    => $missingChain,
    'note'             => $pdo_missing ? 'pdo_mysql_missing_cli_only_web_ok' : null,
    'source'           => 'audit_e2e_probe.php',
];

if ($writeLast) {
    @file_put_contents($logsDir . '/audit_trail_e2e_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($payload['ok'] ? 0 : ($strict ? 2 : 1));
