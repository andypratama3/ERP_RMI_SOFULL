<?php
/**
 * INSIGHT REAL — ERP_RMI_SOFULL
 *
 * Read-only analytics + artifacts. Writes:
 *   storage/logs/insight_real_last.json
 *   storage/logs/insight_real_last.md
 *   storage/logs/insight_real_do_backlog.csv
 *   storage/logs/insight_real_query_log.md
 * Optional audit row: system_audit_logs INSIGHT_REAL_RUN (tools_insight module).
 *
 * Usage (NAS): ./tools/nas/erp.sh php tools/qa/insight_real.php [--no-audit] [--actor=USERNAME]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/_shared/db.php';
require_once $root . '/tools/qa/insight_real_lib.php';

$args = $_SERVER['argv'] ?? [];
$writeAudit = !in_array('--no-audit', $args, true);
$actor = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--actor=')) {
        $actor = substr($a, strlen('--actor='));
    }
}
if ($actor === null || trim($actor) === '') {
    $actor = trim((string)(getenv('USER') ?: getenv('LOGNAME') ?: 'cli'));
}

$requestId = bin2hex(random_bytes(8));
$logsDir = ts_storage_logs_dir();

try {
    $pdo = rmi_db_pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$payload = irlib_run($pdo, [
    'logs_dir' => $logsDir,
    'actor' => $actor,
    'write_audit' => $writeAudit,
    'request_id' => $requestId,
]);

$qEntries = $payload['_query_log_entries'] ?? [];
unset($payload['_query_log_entries']);
$payload['sales_do']['o2c_mapping_reference'] = 'sales/sales_dashboard.php $STATUS_TO_STAGE';

$jsonPath = $logsDir . '/insight_real_last.json';
$mdPath = $logsDir . '/insight_real_last.md';
$csvPath = $logsDir . '/insight_real_do_backlog.csv';
$qlogPath = $logsDir . '/insight_real_query_log.md';

file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

$qlog = new InsightRealQueryLog();
foreach ($qEntries as $e) {
    $qlog->log($e['sql'] ?? '', $e['params'] ?? [], (string)($e['note'] ?? ''));
}
$qlog->writeMarkdown($qlogPath, 'INSIGHT REAL — Query log');

$auditLine = $writeAudit ? 'yes (unless failed — see notes)' : 'skipped (--no-audit)';
file_put_contents($mdPath, ir_insight_real_render_md($payload, $auditLine) . "\n");

$fh = fopen($csvPath, 'wb');
if ($fh !== false) {
    fputcsv($fh, ['doc_code', 'office', 'status', 'age_days']);
    foreach (($payload['sales_do']['backlog_stuck_gt3d'] ?? []) as $row) {
        fputcsv($fh, [
            (string)($row['doc_code'] ?? ''),
            (string)($row['office_code'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['age_days'] ?? ''),
        ]);
    }
    fclose($fh);
}

echo "OK wrote:\n  {$jsonPath}\n  {$mdPath}\n  {$csvPath}\n  {$qlogPath}\n";
exit(0);
