<?php
/**
 * build_audit_exec_summary.php — Build 1-page exec summary from audit_live_last.json
 * Called by audit_live.php --write-last. Writes HTML + MD to storage/logs.
 */
declare(strict_types=1);

// Library file: included by other scripts. Direct web access not intended.
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403); exit('Forbidden');
}

if (!function_exists('build_audit_exec_summary')) {
    function build_audit_exec_summary(array $audit): void
    {
        $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        $logsDir = $root . '/storage/logs';
        if (!is_dir($logsDir)) return;

        $ok = (bool)($audit['overall_ok'] ?? false);
        $critical = (int)($audit['critical_fail_count'] ?? 0);
        $warn = (int)($audit['warning_count'] ?? 0);
        $runId = (string)($audit['run_id'] ?? '');
        $generatedAt = (string)($audit['generated_at'] ?? date('c'));
        $checks = (array)($audit['checks'] ?? []);
        $assumptions = (array)($audit['assumptions'] ?? []);

        $fails = array_filter($checks, fn($c) => ($c['status'] ?? '') === 'fail');
        $warns = array_filter($checks, fn($c) => ($c['status'] ?? '') === 'warn');

        $md = "# Live Audit — Executive Summary\n\n";
        $md .= "**Generated:** {$generatedAt} | **Run ID:** {$runId}\n\n";
        $md .= "## Status: " . ($ok ? "PASS" : "FAIL") . "\n\n";
        $md .= "- Critical fails: {$critical}\n";
        $md .= "- Warnings: {$warn}\n\n";
        if (!empty($fails)) {
            $md .= "## Critical Fails\n\n";
            foreach ($fails as $c) {
                $md .= "- **" . ($c['name'] ?? '') . "**: " . ($c['detail_masked'] ?? '') . "\n";
            }
            $md .= "\n";
        }
        if (!empty($warns)) {
            $md .= "## Warnings\n\n";
            foreach ($warns as $c) {
                $md .= "- **" . ($c['name'] ?? '') . "**: " . ($c['detail_masked'] ?? '') . "\n";
            }
            $md .= "\n";
        }
        if (!empty($assumptions)) {
            $md .= "## Assumptions\n\n";
            foreach ($assumptions as $a) {
                $md .= "- {$a}\n";
            }
        }
        $md .= "\n---\n*ERP_RMI_SOFULL Live Audit — Real Data + Real Time*\n";

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Audit Exec Summary</title>';
        $html .= '<style>body{font-family:sans-serif;max-width:800px;margin:2em auto;padding:1em;}';
        $html .= '.badge{display:inline-block;padding:.25em .5em;border-radius:4px;font-weight:bold;}';
        $html .= '.badge-pass{background:#0a0;color:#fff;} .badge-fail{background:#c00;color:#fff;}';
        $html .= 'ul{list-style:none;padding-left:0;} li{margin:.5em 0;}</style></head><body>';
        $html .= '<h1>Live Audit — Executive Summary</h1>';
        $html .= '<p><span class="badge badge-' . ($ok ? 'pass' : 'fail') . '">' . ($ok ? 'PASS' : 'FAIL') . '</span> ';
        $html .= "Generated: " . htmlspecialchars($generatedAt) . " | Run ID: " . htmlspecialchars($runId) . "</p>";
        $html .= "<p>Critical fails: {$critical} | Warnings: {$warn}</p>";
        if (!empty($fails)) {
            $html .= "<h2>Critical Fails</h2><ul>";
            foreach ($fails as $c) {
                $html .= "<li><strong>" . htmlspecialchars($c['name'] ?? '') . "</strong>: " . htmlspecialchars($c['detail_masked'] ?? '') . "</li>";
            }
            $html .= "</ul>";
        }
        if (!empty($warns)) {
            $html .= "<h2>Warnings</h2><ul>";
            foreach ($warns as $c) {
                $html .= "<li><strong>" . htmlspecialchars($c['name'] ?? '') . "</strong>: " . htmlspecialchars($c['detail_masked'] ?? '') . "</li>";
            }
            $html .= "</ul>";
        }
        $html .= "<p><a href=\"../qa/audit_live_web.php\">Live Audit</a> | <a href=\"audit_exec_summary.php\">Raw JSON</a></p>";
        $html .= "</body></html>";

        @file_put_contents($logsDir . '/audit_exec_summary_last.md', $md);
        @file_put_contents($logsDir . '/audit_exec_summary_last.html', $html);
    }
}
