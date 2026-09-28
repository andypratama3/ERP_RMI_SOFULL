<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';

$root = ts_root();
$logs = ts_storage_logs_dir();
$args = $_SERVER['argv'] ?? [];
$mode = 'quick';
foreach ($args as $a) {
    if (str_starts_with((string)$a, '--mode=')) {
        $m = strtolower(trim((string)substr((string)$a, 7)));
        if (in_array($m, ['quick', 'strict'], true)) {
            $mode = $m;
        }
    }
}

$hypercare = ts_read_json($logs . '/hypercare_summary_last.json');
// Try canonical path first, fallback ke business_signoff_last.json
// ts_read_json returns ['ok'=>false,...] when missing — check ok flag, not array check
$_bRaw = ts_read_json(business_signoff_state_path());
if (!empty($_bRaw['ok']) && isset($_bRaw['signed'])) {
    $business = $_bRaw;
} else {
    // Fallback: business_signoff_last.json (canonical dari signoff form)
    $_bFallback = ts_read_json($logs . '/business_signoff_last.json');
    $business = (!empty($_bFallback['ok']) || isset($_bFallback['signed'])) ? $_bFallback : $_bRaw;
    // Sync canonical state file agar next run konsisten
    if (isset($business['signed'])) {
        ts_write_json(business_signoff_state_path(), $business);
    }
}
$contract = ts_read_json($logs . '/contract_check_last.json');
$cutover = ts_read_json($logs . '/cutover_checks.last.json');
$readiness = ts_read_json($logs . '/readiness_report_last.json');
$evidence = ts_read_json($logs . '/evidence_pack_last.json');

if (!$business) {
    $business = [
        'state_version' => 1,
        'signed' => false,
        'signed_at' => null,
        'approver_name' => '',
        'evidence_file' => '',
        'sha256' => '',
        'notes' => '',
    ];
    ts_write_json(business_signoff_state_path(), $business);
}

$checks = [
    'hypercare_complete_24h' => (bool)($hypercare['hypercare_complete_24h'] ?? false),
    'business_signed' => (bool)($business['signed'] ?? false),
    'contract_ok' => (bool)($contract['ok'] ?? false),
    'cutover_ok' => ((bool)($cutover['overall_ok'] ?? false) && (int)($cutover['summary']['fail_count'] ?? 1) === 0),
    'readiness_100' => ((int)($readiness['score'] ?? 0) === 100),
];

$requiredChecks = ['contract_ok', 'cutover_ok', 'readiness_100'];
if ($mode === 'strict') {
    $requiredChecks[] = 'hypercare_complete_24h';
    $requiredChecks[] = 'business_signed';
}
$go = true;
foreach ($requiredChecks as $k) {
    if (empty($checks[$k])) {
        $go = false;
        break;
    }
}
$reasons = [];
if ($mode === 'strict' && !$checks['hypercare_complete_24h']) $reasons[] = 'Hypercare 24h belum complete';
if ($mode === 'strict' && !$checks['business_signed']) $reasons[] = 'Business sign-off belum tersedia';
if (!$checks['contract_ok']) $reasons[] = 'Contract check belum OK';
if (!$checks['cutover_ok']) $reasons[] = 'Cutover checks belum OK';
if (!$checks['readiness_100']) $reasons[] = 'Readiness score belum 100';

$gitCommit = trim((string)@shell_exec('git rev-parse HEAD 2>/dev/null'));
$gitTag = trim((string)@shell_exec('git describe --tags --exact-match 2>/dev/null'));
if ($gitCommit === '') $gitCommit = 'N/A';
if ($gitTag === '') $gitTag = 'N/A';

$tz = new DateTimeZone('Asia/Jakarta');
$nowWib = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s T');
$packRef = (string)($evidence['pack_dir_masked'] ?? '[APP_ROOT]/storage/logs/evidence_pack/<missing>');

$doc = "# FINAL_PROD_SIGNOFF\n\n";
$doc .= "- Verdict: **" . ($go ? 'GO' : 'NO-GO') . "**\n";
$doc .= "- Timestamp WIB: `" . $nowWib . "`\n";
$doc .= "- Mode: `" . strtoupper($mode) . "`\n";
$doc .= "- Commit: `" . tools_mask_sensitive($gitCommit) . "`\n";
$doc .= "- Tag: `" . tools_mask_sensitive($gitTag) . "`\n";
$doc .= "- Environment: `" . strtolower((string)(getenv('APP_ENV') ?: 'local')) . "`\n\n";
$doc .= "## Gate Status\n";
$doc .= "- hypercare_complete_24h: " . ($checks['hypercare_complete_24h'] ? 'PASS' : 'FAIL') . "\n";
$doc .= "- business_signoff_signed: " . ($checks['business_signed'] ? 'PASS' : 'FAIL') . "\n";
$doc .= "- contract_ok: " . ($checks['contract_ok'] ? 'PASS' : 'FAIL') . "\n";
$doc .= "- cutover_checks_ok: " . ($checks['cutover_ok'] ? 'PASS' : 'FAIL') . "\n";
$doc .= "- readiness_score_100: " . ($checks['readiness_100'] ? 'PASS' : 'FAIL') . "\n\n";

if (!$go) {
    $doc .= "## Reasons (NO-GO)\n";
    foreach ($reasons as $r) {
        $doc .= "- " . tools_mask_sensitive($r) . "\n";
    }
    $doc .= "\n## Required Next Actions\n";
    if ($mode === 'strict') {
        $doc .= "- Lengkapi checkpoint hypercare sampai `hypercare_complete_24h=true`.\n";
        $doc .= "- Upload/rekam business sign-off formal.\n";
    }
    $doc .= "- Regenerate evidence pack dan rerun verdict generator.\n";
}

$doc .= "\n## Evidence Pack Pointer\n";
$doc .= "- Latest: `" . tools_mask_sensitive($packRef) . "`\n";
$doc .= "\n## Signature Block\n";
$doc .= "- Release Engineer: ____________________\n";
$doc .= "- Lead PHP Engineer: ____________________\n";
$doc .= "- Security Engineer: ____________________\n";
$doc .= "- QA Lead: ____________________\n";
$doc .= "- Business Approver: ____________________\n";

@file_put_contents($root . '/FINAL_PROD_SIGNOFF.md', $doc);
@file_put_contents($root . '/SIGNOFF_VERDICT_NO_GO_OR_GO.md', $doc);

$state = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'mode' => $mode,
    'verdict' => $go ? 'GO' : 'NO-GO',
    'checks' => $checks,
    'reasons' => array_map('tools_mask_sensitive', $reasons),
    'evidence_pack' => tools_mask_sensitive($packRef),
    'final_doc' => ts_mask($root . '/FINAL_PROD_SIGNOFF.md'),
];
ts_write_json($logs . '/signoff_verdict_last.json', $state);
ts_append_run_history('signoff_verdict_generate', $go ? 'OK' : 'ATTENTION', [
    'actor_username' => getenv('USER') ?: 'SYSTEM',
    'source' => 'tools/qa/generate_signoff_verdict.php',
    'verdict' => $state['verdict'],
]);

echo json_encode($state, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($go ? 0 : 1);
