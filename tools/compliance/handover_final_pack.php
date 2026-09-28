<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

function handover_final_latest_evidence_zip(string $root): ?string
{
    $candidates = array_merge(
        glob($root . '/exports/evidence/*/*.zip') ?: [],
        glob($root . '/exports/evidence/*.zip') ?: []
    );
    if (!$candidates) {
        return null;
    }
    usort($candidates, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return $candidates[0] ?? null;
}

function handover_final_build_sources(string $root): array
{
    $required = [
        ['src' => $root . '/storage/logs/program_closeout_audit_last.json', 'target' => 'logs/program_closeout_audit_last.json'],
        ['src' => $root . '/storage/logs/program_closeout_audit_last.md', 'target' => 'logs/program_closeout_audit_last.md'],
        ['src' => $root . '/storage/logs/smoke_http_last.json', 'target' => 'logs/smoke_http_last.json'],
        ['src' => $root . '/storage/logs/release_gate_last.json', 'target' => 'logs/release_gate_last.json'],
        ['src' => $root . '/storage/logs/evidence_status_last.json', 'target' => 'logs/evidence_status_last.json'],
        ['src' => $root . '/storage/logs/batch3_governance_audit_last.json', 'target' => 'logs/batch3_governance_audit_last.json'],
        ['src' => $root . '/storage/logs/rbac_effective_permissions.last.json', 'target' => 'logs/rbac_effective_permissions.last.json'],
        ['src' => $root . '/docs/governance/PROGRAM_CLOSEOUT_AUDIT_PACK.md', 'target' => 'docs/PROGRAM_CLOSEOUT_AUDIT_PACK.md'],
        ['src' => $root . '/docs/governance/PATCH_DIFF_MASTER.md', 'target' => 'docs/PATCH_DIFF_MASTER.md'],
        ['src' => $root . '/docs/governance/TESTPLAN_MASTER.md', 'target' => 'docs/TESTPLAN_MASTER.md'],
        ['src' => $root . '/docs/governance/OPS_RUNBOOK_FINAL.md', 'target' => 'docs/OPS_RUNBOOK_FINAL.md'],
        ['src' => $root . '/docs/governance/RELEASE_GATE_POLICY.md', 'target' => 'docs/RELEASE_GATE_POLICY.md'],
        ['src' => $root . '/docs/governance/DEFINITION_OF_DONE.md', 'target' => 'docs/DEFINITION_OF_DONE.md'],
    ];

    $optional = [
        ['src' => $root . '/storage/logs/sop_link_verify_last.json', 'target' => 'logs/sop_link_verify_last.json'],
        ['src' => $root . '/storage/logs/evidence_verify_last.json', 'target' => 'logs/evidence_verify_last.json'],
        ['src' => $root . '/storage/logs/tools_run_history.jsonl', 'target' => 'logs/tools_run_history.jsonl'],
        ['src' => $root . '/storage/logs/SMOKE_RESULTS_FINAL.md', 'target' => 'logs/SMOKE_RESULTS_FINAL.md'],
        ['src' => $root . '/storage/logs/release_gate_last.md', 'target' => 'logs/release_gate_last.md'],
    ];

    $latestEvidence = handover_final_latest_evidence_zip($root);
    if ($latestEvidence !== null) {
        $optional[] = ['src' => $latestEvidence, 'target' => 'evidence/' . basename($latestEvidence)];
    }

    return ['required' => $required, 'optional' => $optional];
}

function handover_final_pack_generate(): array
{
    $root = opsgov_root();
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'status' => 'FAIL', 'message' => 'ZipArchive belum tersedia di environment PHP.'];
    }

    $sources = handover_final_build_sources($root);
    $stamp = date('Ymd_His');
    $bucket = date('Y-m');
    $outDir = $root . '/exports/handover/' . $bucket;
    @mkdir($root . '/exports', 0775, true);
    @mkdir($root . '/exports/handover', 0775, true);
    @mkdir($outDir, 0775, true);
    @file_put_contents($root . '/exports/.htaccess', "Deny from all\n");
    @file_put_contents($root . '/exports/handover/.htaccess', "Deny from all\n");
    @file_put_contents($outDir . '/.htaccess', "Deny from all\n");

    $zipPath = $outDir . '/handover_final_' . $stamp . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'status' => 'FAIL', 'message' => 'Gagal membuat ZIP handover final.'];
    }

    $manifest = [
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'type' => 'program_handover_final',
        'files' => [],
        'missing_required' => [],
        'missing_optional' => [],
    ];

    foreach ($sources['required'] as $row) {
        $src = (string)$row['src'];
        $target = (string)$row['target'];
        if (!is_file($src)) {
            $manifest['missing_required'][] = opsgov_mask($src);
            continue;
        }
        $zip->addFile($src, $target);
        $manifest['files'][] = [
            'target' => $target,
            'sha256' => (string)@hash_file('sha256', $src),
            'size_bytes' => (int)@filesize($src),
            'required' => true,
        ];
    }

    foreach ($sources['optional'] as $row) {
        $src = (string)$row['src'];
        $target = (string)$row['target'];
        if (!is_file($src)) {
            $manifest['missing_optional'][] = opsgov_mask($src);
            continue;
        }
        $zip->addFile($src, $target);
        $manifest['files'][] = [
            'target' => $target,
            'sha256' => (string)@hash_file('sha256', $src),
            'size_bytes' => (int)@filesize($src),
            'required' => false,
        ];
    }

    $summary = [
        'status' => count($manifest['missing_required']) > 0 ? 'WARN' : 'OK',
        'included_files' => count($manifest['files']),
        'missing_required' => count($manifest['missing_required']),
        'missing_optional' => count($manifest['missing_optional']),
        'zip_masked' => opsgov_mask($zipPath),
    ];
    $manifest['summary'] = $summary;

    $readme = "ERP_RMI_SOFULL Program Handover Final Pack\n\n";
    $readme .= "Generated at: " . $manifest['generated_at'] . "\n";
    $readme .= "Status: " . $summary['status'] . "\n";
    $readme .= "Included files: " . $summary['included_files'] . "\n";
    $readme .= "Missing required: " . $summary['missing_required'] . "\n";
    $readme .= "Missing optional: " . $summary['missing_optional'] . "\n";
    $readme .= "Masked zip path: " . $summary['zip_masked'] . "\n";
    $readme .= "\nUse manifest.json for checksum validation.\n";

    $zip->addFromString('README.txt', $readme);
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->close();

    $payload = [
        'state_version' => 1,
        'generated_at' => $manifest['generated_at'],
        'status' => $summary['status'],
        'zip_path' => $summary['zip_masked'],
        'included_files' => $summary['included_files'],
        'missing_required' => $manifest['missing_required'],
        'missing_optional_count' => $summary['missing_optional'],
    ];
    opsgov_safe_write_json($root . '/storage/logs/handover_final_pack_last.json', $payload);

    $md = [];
    $md[] = '# Handover Final Pack';
    $md[] = '';
    $md[] = '- Generated at: ' . $payload['generated_at'];
    $md[] = '- Status: ' . $payload['status'];
    $md[] = '- ZIP: `' . $payload['zip_path'] . '`';
    $md[] = '- Included files: ' . $payload['included_files'];
    $md[] = '- Missing optional count: ' . (string)$payload['missing_optional_count'];
    if (!empty($payload['missing_required'])) {
        $md[] = '';
        $md[] = '## Missing Required';
        foreach ($payload['missing_required'] as $mr) {
            $md[] = '- `' . (string)$mr . '`';
        }
    }
    @file_put_contents($root . '/storage/logs/handover_final_pack_last.md', implode("\n", $md) . "\n");

    ts_append_run_history('handover_final_pack', $summary['status'] === 'OK' ? 'OK' : 'WARN', [
        'module' => 'tools.compliance',
        'action' => 'generate_handover_pack',
        'result' => $summary['status'],
        'zip' => $summary['zip_masked'],
        'included_files' => $summary['included_files'],
        'missing_required' => $summary['missing_required'],
    ]);

    return [
        'ok' => true,
        'status' => $summary['status'],
        'zip_path' => $summary['zip_masked'],
        'summary' => $summary,
        'missing_required' => $manifest['missing_required'],
    ];
}

if (PHP_SAPI === 'cli') {
    echo json_encode(handover_final_pack_generate(), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

opsgov_require_admin();
$result = opsgov_read_json(opsgov_root() . '/storage/logs/handover_final_pack_last.json');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $result = handover_final_pack_generate();
}
$baseProject = rmi_layout_base_project();
rmi_header('Handover Final Pack', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Handover Final Pack']]);
?>
<div class="card p-3">
  <div class="small mb-2">Generate ZIP handover final (evidence + governance docs + manifest checksum).</div>
  <form method="post" class="mb-3">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <button class="btn btn-sm btn-rmi">Generate Handover Final ZIP</button>
  </form>
  <pre class="mb-0 small"><?= opsgov_h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php rmi_footer(); ?>
