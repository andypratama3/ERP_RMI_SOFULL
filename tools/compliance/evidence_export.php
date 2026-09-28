<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';

function compliance_export_evidence(string $startDate, string $endDate): array
{
    $root = opsgov_root();
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'ZipArchive belum tersedia di environment PHP.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        return ['ok' => false, 'message' => 'Format tanggal tidak valid'];
    }
    $bucket = date('Y-m', strtotime($startDate));
    $dir = $root . '/exports/evidence/' . $bucket;
    @mkdir($root . '/exports', 0775, true);
    @mkdir($dir, 0775, true);
    @file_put_contents($root . '/exports/.htaccess', "Deny from all\n");
    @file_put_contents($root . '/exports/evidence/.htaccess', "Deny from all\n");
    @file_put_contents($dir . '/.htaccess', "Deny from all\n");

    $tag = str_replace('-', '', $startDate) . '_' . str_replace('-', '', $endDate);
    $work = $dir . '/tmp_pack_' . $tag;
    @mkdir($work, 0775, true);

    $sources = [
        'SMOKE_RESULTS_FINAL.md' => $root . '/storage/logs/SMOKE_RESULTS_FINAL.md',
        'cutover_checks.last.json' => $root . '/storage/logs/cutover_checks.last.json',
        'all_checks.last.json' => $root . '/storage/logs/all_checks.last.json',
        'contract_check_last.json' => $root . '/storage/logs/contract_check_last.json',
        'readiness_report_last.json' => $root . '/storage/logs/readiness_report_last.json',
        'smoke_http_last.json' => $root . '/storage/logs/smoke_http_last.json',
        'tools_run_history.jsonl' => $root . '/storage/logs/tools_run_history.jsonl',
        'dr_drill.jsonl' => $root . '/storage/logs/dr_drill.jsonl',
        'rbac_effective_permissions.last.json' => $root . '/storage/logs/rbac_effective_permissions.last.json',
    ];

    $manifest = [
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'period' => ['start_date' => $startDate, 'end_date' => $endDate],
        'files' => [],
    ];

    foreach ($sources as $targetName => $src) {
        if (!is_file($src)) continue;
        $dst = $work . '/' . $targetName;
        @copy($src, $dst);
        $manifest['files'][] = [
            'name' => basename($dst),
            'sha256' => (string)@hash_file('sha256', $dst),
            'size_bytes' => (int)@filesize($dst),
        ];
    }
    $signoffTpl = $root . '/docs/ops/compliance/Signoff_Template.md';
    if (is_file($signoffTpl)) {
        $dstTpl = $work . '/SIGNOFF_TEMPLATE.md';
        @copy($signoffTpl, $dstTpl);
        $manifest['files'][] = [
            'name' => basename($dstTpl),
            'sha256' => (string)@hash_file('sha256', $dstTpl),
            'size_bytes' => (int)@filesize($dstTpl),
        ];
    }

    $readme = "Evidence Pack Verification\n\n";
    $readme .= "Period: {$startDate} to {$endDate}\n";
    $readme .= "Verify file integrity with SHA256 from manifest.json.\n";
    @file_put_contents($work . '/README.txt', $readme);
    @file_put_contents($work . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $zipName = 'evidence_pack_' . $tag . '.zip';
    $zipPath = $dir . '/' . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'message' => 'Gagal membuat ZIP evidence'];
    }
    foreach (glob($work . '/*') ?: [] as $f) {
        if (is_file($f)) $zip->addFile($f, basename($f));
    }
    $zip->close();
    foreach (glob($work . '/*') ?: [] as $f) @unlink($f);
    @rmdir($work);

    ts_append_run_history('compliance_evidence_export', 'OK', ['source' => 'tools/compliance/evidence_export.php', 'period' => $tag, 'zip' => opsgov_mask($zipPath)]);
    return ['ok' => true, 'zip_path' => $zipPath, 'zip_masked' => opsgov_mask($zipPath), 'manifest' => $manifest];
}

if (PHP_SAPI === 'cli') {
    $s = date('Y-m-01'); $e = date('Y-m-t');
    foreach (($_SERVER['argv'] ?? []) as $a) {
        if (strpos((string)$a, '--start=') === 0) $s = substr((string)$a, 8);
        if (strpos((string)$a, '--end=') === 0) $e = substr((string)$a, 6);
    }
    echo json_encode(compliance_export_evidence($s, $e), JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
