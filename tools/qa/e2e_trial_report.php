<?php
/**
 * e2e_trial_report.php — Generate REPORT.md, REPORT.json, SUMMARY.txt from evidence.
 * Usage: php tools/qa/e2e_trial_report.php --evidence-dir=storage/logs/e2e_trial/RUN_ID --run-id=RUN_ID
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$evidenceDir = '';
$runId = date('Ymd_His');
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (is_string($arg) && str_starts_with($arg, '--evidence-dir=')) {
        $evidenceDir = rtrim(substr($arg, 15), '/');
        break;
    }
}
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (is_string($arg) && str_starts_with($arg, '--run-id=')) {
        $runId = trim(substr($arg, 9));
        break;
    }
}

if ($evidenceDir === '' || !is_dir($evidenceDir)) {
    fwrite(STDERR, "FAIL: --evidence-dir required and must exist\n");
    exit(1);
}

$baseUrl = getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('TOOLS_BASE_URL') ?: 'http://10.10.60.20/ERP_RMI_SOFULL';

// Load artifacts
$cutover = [];
if (is_file($evidenceDir . '/cutover_checks.last.json')) {
    $cutover = json_decode(file_get_contents($evidenceDir . '/cutover_checks.last.json'), true) ?: [];
}
$e2eTrail = [];
if (is_file($evidenceDir . '/e2e_trail_last.json')) {
    $e2eTrail = json_decode(file_get_contents($evidenceDir . '/e2e_trail_last.json'), true) ?: [];
}
$audit = [];
if (is_file($evidenceDir . '/audit_realtime_last.json')) {
    $audit = json_decode(file_get_contents($evidenceDir . '/audit_realtime_last.json'), true) ?: [];
}
$smoke = [];
if (is_file($evidenceDir . '/smoke_http_last.json')) {
    $smoke = json_decode(file_get_contents($evidenceDir . '/smoke_http_last.json'), true) ?: [];
}

$gateOk = ($cutover['overall_ok'] ?? false) === true;
$guestOk = (int)($e2eTrail['guest_ok'] ?? 0);
$guestFail = (int)($e2eTrail['guest_fail'] ?? 0);
$totalPages = $guestOk + $guestFail;
$auditOk = ($audit['ok'] ?? true) === true;
$anomalies = $audit['anomalies'] ?? [];

$verdict = $gateOk && $guestFail === 0 && $auditOk ? 'GO' : 'NO-GO';
$top5 = array_slice($anomalies, 0, 5);
if (!$gateOk) {
    array_unshift($top5, ['code' => 'GATE_FAIL', 'message' => 'Cutover gate overall_ok=false']);
}
if ($guestFail > 0) {
    array_unshift($top5, ['code' => 'ROUTE_FAIL', 'count' => $guestFail, 'message' => "{$guestFail} URLs failed guest check"]);
}

// REPORT.md
$md = "# E2E Trial Report — ERP_RMI_SOFULL\n\n";
$md .= "**Run ID:** {$runId}  \n**Generated:** " . date('c') . "  \n**Base URL:** [BASE_URL]\n\n";

$md .= "## 1. Executive Summary\n\n";
$md .= "| Item | Status |\n|------|--------|\n";
$md .= "| Gate Cutover | " . ($gateOk ? 'PASS' : 'FAIL') . " |\n";
$md .= "| Total halaman diuji | {$totalPages} (PASS: {$guestOk}, FAIL: {$guestFail}) |\n";
$md .= "| Audit Realtime | " . ($auditOk ? 'PASS' : 'FAIL') . " |\n";
$md .= "| **Verdict** | **{$verdict}** |\n\n";

$md .= "### Top 5 P0 Issues\n\n";
if (empty($top5)) {
    $md .= "Tidak ada.\n\n";
} else {
    foreach ($top5 as $i) {
        $md .= "- **" . ($i['code'] ?? 'ISSUE') . "** " . json_encode($i, JSON_UNESCAPED_SLASHES) . "\n";
    }
    $md .= "\n";
}

$md .= "## 2. Gate Evidence\n\n";
$md .= "- cutover_checks.last.json\n";
$md .= "- smoke_http_last.json\n";
$md .= "- contract_check_last.json\n";
$md .= "- unicode_guard_last.json\n";
$md .= "- e2e_trail_last.json\n";
$md .= "- audit_realtime_last.json\n\n";

$md .= "## 3. Modul-by-Modul\n\n";
$modules = $e2eTrail['modules'] ?? [];
foreach ($modules as $m) {
    $name = $m['name'] ?? 'UNKNOWN';
    $ok = $m['ok'] ?? false;
    $status = $ok ? 'PASS' : 'FAIL';
    $issues = $m['issues'] ?? [];
    $md .= "### {$name}\n";
    $md .= "- **Status:** {$status}\n";
    if (!empty($issues)) {
        $md .= "- **Temuan:** " . implode('; ', array_slice($issues, 0, 5)) . "\n";
    }
    $md .= "\n";
}

$md .= "## 4. Workflow E2E Evidence\n\n";
$md .= "- P2P/O2C/Stock: lihat audit_realtime_last.json (aggregates, anomalies)\n";
$md .= "- Route coverage: e2e_trail_last.json\n\n";

$md .= "## 5. Security & Consistency\n\n";
$md .= "- unicode_guard: " . (is_file($evidenceDir . '/unicode_guard_last.json') ? 'artifact ada' : 'N/A') . "\n";
$md .= "- Anti-/Volumes: RUN_META.txt (CRITICAL_FAIL jika /Volumes detected)\n\n";

$md .= "## 6. Rekomendasi\n\n";
if ($verdict === 'NO-GO') {
    $md .= "- Perbaiki gate/route/audit sebelum production sign-off.\n";
}
$md .= "- Backup/restore tested\n";
$md .= "- RBAC matrix update\n";
$md .= "- Docs up to date (ERP_MENU_WORKFLOW_REFERENCE.md)\n\n";

$md .= "## 7. Evidence Paths\n\n";
$md .= "Evidence folder: `{$evidenceDir}`\n";

file_put_contents($evidenceDir . '/REPORT.md', $md);

// REPORT.json
$reportJson = [
    'run_id' => $runId,
    'generated_at' => date('c'),
    'base_urls' => ['internal' => '[BASE_URL]', 'public' => 'https://erp.rizqullahmediska.com/ERP_RMI_SOFULL'],
    'gate_results' => [
        'cutover_ok' => $gateOk,
        'smoke_ok' => (($smoke['pass'] ?? 0) === ($smoke['total'] ?? 0)),
    ],
    'module_results' => $modules,
    'e2e_samples' => [
        'guest_ok' => $guestOk,
        'guest_fail' => $guestFail,
    ],
    'anomalies' => $anomalies,
    'verdict' => $verdict,
    'evidence_paths' => [
        'evidence_dir' => $evidenceDir,
        'report_md' => $evidenceDir . '/REPORT.md',
        'report_json' => $evidenceDir . '/REPORT.json',
    ],
];
file_put_contents($evidenceDir . '/REPORT.json', json_encode($reportJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// EVIDENCE_PACK.zip
$zipPath = $evidenceDir . '/EVIDENCE_PACK.zip';
$zipFiles = [
    'REPORT.md', 'REPORT.json', 'SUMMARY.txt',
    'cutover_checks.last.json', 'smoke_http_last.json', 'contract_check_last.json',
    'e2e_trail_last.json', 'audit_realtime_last.json', 'audit_realtime_rmi.json',
    'menu_routes_extracted.json', 'RUN_META.txt', 'ERP_MENU_WORKFLOW_REFERENCE.md',
];
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach ($zipFiles as $f) {
            $fp = $evidenceDir . '/' . $f;
            if (is_file($fp)) {
                $zip->addFile($fp, $f);
            }
        }
        foreach (['unicode_guard_last.json', 'tools_doctor_last.json'] as $opt) {
            $fp = $evidenceDir . '/' . $opt;
            if (is_file($fp)) $zip->addFile($fp, $opt);
        }
        $zip->close();
    }
}

// SUMMARY.txt
$summary = "E2E Trial — Run ID: {$runId}\n";
$summary .= "Verdict: {$verdict}\n";
$summary .= "Gate: " . ($gateOk ? 'PASS' : 'FAIL') . " | Routes: {$guestOk}/{$totalPages} | Audit: " . ($auditOk ? 'PASS' : 'FAIL') . "\n";
$summary .= "Evidence: {$evidenceDir}\n";
if (!empty($top5)) {
    $summary .= "Top issues: " . implode(', ', array_map(fn($x) => $x['code'] ?? '?', $top5)) . "\n";
}
file_put_contents($evidenceDir . '/SUMMARY.txt', $summary);

$zipPath = $evidenceDir . '/EVIDENCE_PACK.zip';
$zipExists = is_file($zipPath);

echo "\n";
echo "========================================\n";
echo "E2E TRIAL — FINAL CONSOLE OUTPUT\n";
echo "========================================\n";
echo "Verdict: {$verdict}\n";
echo "Report: {$evidenceDir}/REPORT.md\n";
echo "Summary: {$evidenceDir}/SUMMARY.txt\n";
echo "Evidence ZIP: " . ($zipExists ? $zipPath : 'N/A') . "\n";
echo "\nTop 5 issues:\n";
foreach (array_slice($top5, 0, 5) as $i => $x) {
    echo "  " . ($i + 1) . ". " . ($x['code'] ?? '?') . " — " . json_encode($x, JSON_UNESCAPED_SLASHES) . "\n";
}
if ($verdict === 'NO-GO' && $gateOk === false) {
    echo "\nNote: Cutover gate FAIL — triage non-bisnis (path/base_url/unicode/bootstrap) sebelum trail penuh.\n";
}
echo "========================================\n";
