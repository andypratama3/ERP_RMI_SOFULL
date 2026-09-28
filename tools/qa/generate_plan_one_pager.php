<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/_lib/pipeline_plans_lib.php';
require_once __DIR__ . '/_lib/plan_one_pager_lib.php';

$root = pop_root();
$planPath = $root . '/tools/qa/plans/pipeline_plans.yaml';
$outDir = $root . '/docs/governance';

if (!is_file($planPath)) {
    fwrite(STDERR, "FAIL: pipeline_plans.yaml tidak ada.\n");
    fwrite(STDERR, "Fix: php tools/qa/_lib/pipeline_plan_runner.php --validate\n");
    fwrite(STDERR, "Atau buat file: tools/qa/plans/pipeline_plans.yaml\n");
    exit(1);
}

$load = load_plans_yaml($planPath);
if (!$load['ok'] || !isset($load['data']['plans'])) {
    fwrite(STDERR, "FAIL: pipeline_plans.yaml invalid atau kosong.\n");
    exit(1);
}

$plans = (array)$load['data']['plans'];
$rows = pop_build_rows($plans);
$generatedAt = date(DateTimeInterface::ATOM);

$assumptions = [
    'ETA heuristics: pr_gate 1–3 min, pr_gate_strict_http 2–5 min, pr_gate_security_lite 2–6 min, pr_gate_migration_hygiene 2–5 min, nightly 6–15 min, release_candidate 10–25 min, ui_regression 5–20 min, mobile 2–8 min, ops_freshness <1 min, full_staging_00_16 20–90 min.',
];

$jsonPayload = [
    'state_version' => 1,
    'generated_at' => $generatedAt,
    'plans' => array_map(static function (array $r): array {
        return [
            'id' => $r['id'],
            'when' => $r['when'],
            'who' => $r['who'],
            'eta' => $r['eta'],
            'outputs' => ['plan_<id>_<run>.json', 'plan_<id>_<run>.md', 'plan_<id>_<run>.html', 'exec_summary_last.html'],
            'notes' => $r['notes'],
        ];
    }, $rows),
    'assumptions' => $assumptions,
];

$jsonPath = $outDir . '/PIPELINE_PLANS_ONE_PAGER.json';
$mdPath = $outDir . '/PIPELINE_PLANS_ONE_PAGER.md';
$htmlPath = $outDir . '/PIPELINE_PLANS_ONE_PAGER.html';

if (!is_dir($outDir)) {
    @mkdir($outDir, 0775, true);
}

$md = [];
$md[] = '# Pipeline Plans — One Pager (Non‑IT)';
$md[] = 'Generated at: ' . $generatedAt;
$md[] = '';
$md[] = '## Cara Memilih Plan (ringkas)';
$md[] = '- **PR kecil:** staging_gate_before_merge';
$md[] = '- **PR besar / banyak ubah route/API:** staging_gate_before_merge_strict_http';
$md[] = '- **Perubahan security/dependency:** staging_gate_security_lite';
$md[] = '- **Perubahan DB/migration:** staging_gate_migration_hygiene';
$md[] = '- **Nightly baseline:** nightly_staging_full_gate';
$md[] = '- **Release candidate:** staging_release_candidate';
$md[] = '- **Cutover final production:** prod_stage16_final';
$md[] = '- **Ops cek cepat freshness:** ops_freshness_gate';
$md[] = '- **UI changes besar:** ui_regression_optional_playwright';
$md[] = '- **Mobile API changes:** mobile_contract_gate';
$md[] = '';
$md[] = '## Tabel 1 halaman';
$md[] = '';
$md[] = '| Plan ID | Kapan Dipakai | Siapa (DEV/QA/OPS/RELEASE) | Durasi Perkiraan (ETA) | Output | Catatan |';
$md[] = '|---------|---------------|---------------------------|------------------------|--------|---------|';
foreach ($rows as $r) {
    $who = implode(', ', $r['who']);
    $md[] = '| ' . $r['id'] . ' | ' . $r['when'] . ' | ' . $who . ' | ' . $r['eta'] . ' | ' . $r['outputs'] . ' | ' . ($r['notes'] !== '' ? $r['notes'] : '-') . ' |';
}

@file_put_contents($jsonPath, json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
@file_put_contents($mdPath, implode("\n", $md) . "\n");

$html = [];
$html[] = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pipeline Plans — One Pager</title>';
$html[] = '<style>body{font-family:Arial,sans-serif;font-size:11px;margin:12px;color:#111}';
$html[] = 'table{width:100%;border-collapse:collapse;font-size:10px}th,td{border:1px solid #ddd;padding:4px;vertical-align:top}';
$html[] = 'h1,h2{margin:4px 0}@media print{@page{size:A4;margin:7mm}body{margin:0;font-size:10px}}</style></head><body>';
$html[] = '<h1>Pipeline Plans — One Pager (Non‑IT)</h1>';
$html[] = '<p>Generated at: ' . htmlspecialchars($generatedAt, ENT_QUOTES, 'UTF-8') . '</p>';
$html[] = '<h2>Cara Memilih Plan (ringkas)</h2><ul>';
$html[] = '<li><b>PR kecil:</b> staging_gate_before_merge</li>';
$html[] = '<li><b>PR besar / banyak ubah route/API:</b> staging_gate_before_merge_strict_http</li>';
$html[] = '<li><b>Perubahan security/dependency:</b> staging_gate_security_lite</li>';
$html[] = '<li><b>Perubahan DB/migration:</b> staging_gate_migration_hygiene</li>';
$html[] = '<li><b>Nightly baseline:</b> nightly_staging_full_gate</li>';
$html[] = '<li><b>Release candidate:</b> staging_release_candidate</li>';
$html[] = '<li><b>Cutover final production:</b> prod_stage16_final</li>';
$html[] = '<li><b>Ops cek cepat freshness:</b> ops_freshness_gate</li>';
$html[] = '<li><b>UI changes besar:</b> ui_regression_optional_playwright</li>';
$html[] = '<li><b>Mobile API changes:</b> mobile_contract_gate</li>';
$html[] = '</ul><h2>Tabel 1 halaman</h2><table><thead><tr><th>Plan ID</th><th>Kapan Dipakai</th><th>Siapa</th><th>ETA</th><th>Output</th><th>Catatan</th></tr></thead><tbody>';
foreach ($rows as $r) {
    $who = implode(', ', $r['who']);
    $html[] = '<tr><td>' . htmlspecialchars($r['id'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($r['when'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($who, ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($r['eta'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($r['outputs'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($r['notes'] !== '' ? $r['notes'] : '-', ENT_QUOTES, 'UTF-8') . '</td></tr>';
}
$html[] = '</tbody></table></body></html>';
@file_put_contents($htmlPath, implode("\n", $html) . "\n");

echo "OK: " . pop_mask($mdPath) . ", " . pop_mask($jsonPath) . ", " . pop_mask($htmlPath) . "\n";
exit(0);
