<?php
declare(strict_types=1);

/**
 * CLI: Generate readiness report from smoke_http, preflight, health.
 * Writes storage/logs/readiness_report_last.json and .md.
 * Used by run_all_tools_auto.sh to refresh readiness score for Executive Summary.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("CLI only\n");
}

require_once __DIR__ . '/../_lib/tools_paths.php';
require_once __DIR__ . '/../tools_state_lib.php';

tools_assert_app_root_locked_cli();

$rep = ts_generate_readiness_report();
$score = (int)($rep['payload']['score'] ?? 0);
$jsonPath = ts_storage_logs_dir() . '/readiness_report_last.json';

echo "OK: readiness_report_last.json written, score={$score}/100\n";
exit(0);
