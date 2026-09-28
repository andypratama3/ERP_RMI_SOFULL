<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';

$args = $_SERVER['argv'] ?? [];
$label = 'final_hypercare';
foreach ($args as $a) {
    if (str_starts_with((string)$a, '--label=')) {
        $raw = trim((string)substr((string)$a, 8));
        $clean = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $raw) ?? '';
        if ($clean !== '') {
            $label = $clean;
        }
    }
}

$root = ts_root();
$logs = ts_storage_logs_dir();
$stamp = date('Ymd_His');
$packDir = $logs . '/evidence_pack/' . $stamp . '_' . $label;
@mkdir($packDir, 0775, true);

$required = [
    $root . '/SMOKE_RESULTS_FINAL.md',
    $logs . '/cutover_checks.last.json',
    $logs . '/all_checks.last.json',
    $logs . '/contract_check_last.json',
    $logs . '/readiness_report_last.json',
    $logs . '/smoke_http_last.json',
    $logs . '/tools_run_history.jsonl',
    $logs . '/hypercare_summary_last.json',
    $logs . '/business_signoff_last.json',
];

$copied = [];
$missing = [];
foreach ($required as $src) {
    if (!is_file($src)) {
        $missing[] = ts_mask($src);
        continue;
    }
    $dst = $packDir . '/' . basename($src);
    if (@copy($src, $dst)) {
        $copied[] = $dst;
    }
}

$checksumsPath = $packDir . '/checksums.txt';
$idxPath = $packDir . '/EVIDENCE_PACK_INDEX.md';
$lines = [];
$idx = [];
foreach ($copied as $p) {
    $sha = (string)hash_file('sha256', $p);
    $size = (int)@filesize($p);
    $mtime = (int)@filemtime($p);
    $rel = str_replace($root . '/', '', $p);
    $lines[] = $sha . '  ' . $rel;
    $idx[] = [
        'file' => basename($p),
        'size' => $size,
        'sha256' => $sha,
        'timestamp' => $mtime > 0 ? date(DateTimeInterface::ATOM, $mtime) : '',
        'source' => ts_mask(str_replace($packDir . '/', '', $p)),
    ];
}
@file_put_contents($checksumsPath, implode("\n", $lines) . (count($lines) ? "\n" : ''));

$md = "# EVIDENCE_PACK_INDEX\n\n";
$md .= "- Pack: `" . basename($packDir) . "`\n";
$md .= "- Generated: `" . date(DateTimeInterface::ATOM) . "`\n\n";
$md .= "| file | size | sha256 | timestamp | source |\n";
$md .= "|---|---:|---|---|---|\n";
foreach ($idx as $r) {
    $md .= "| `" . $r['file'] . "` | " . (int)$r['size'] . " | `" . $r['sha256'] . "` | " . tools_mask_sensitive((string)$r['timestamp']) . " | `" . tools_mask_sensitive((string)$r['source']) . "` |\n";
}
if ($missing) {
    $md .= "\n## Missing Files\n";
    foreach ($missing as $m) {
        $md .= "- `" . tools_mask_sensitive($m) . "`\n";
    }
}
@file_put_contents($idxPath, $md);

$state = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'label' => $label,
    'pack_dir_masked' => ts_mask($packDir),
    'copied_count' => count($copied),
    'missing_count' => count($missing),
    'checksums_file' => ts_mask($checksumsPath),
    'index_file' => ts_mask($idxPath),
];
ts_write_json($logs . '/evidence_pack_last.json', $state);
ts_append_run_history('evidence_pack_generate', count($missing) === 0 ? 'OK' : 'ATTENTION', [
    'actor_username' => getenv('USER') ?: 'SYSTEM',
    'source' => 'tools/qa/generate_evidence_pack.php',
    'copied' => count($copied),
    'missing' => count($missing),
]);

echo json_encode($state, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(count($missing) === 0 ? 0 : 1);
