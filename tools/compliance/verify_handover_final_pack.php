<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

function vhfp_scan_dir(string $dir): array
{
    $zips = glob(rtrim($dir, '/') . '/*/*.zip') ?: [];
    $zips = array_merge($zips, glob(rtrim($dir, '/') . '/*.zip') ?: []);
    usort($zips, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return $zips;
}

function vhfp_verify_zip(string $zipPath): array
{
    $required = [
        'logs/program_closeout_audit_last.json',
        'logs/smoke_http_last.json',
        'logs/release_gate_last.json',
        'logs/evidence_status_last.json',
        'logs/batch3_governance_audit_last.json',
        'logs/rbac_effective_permissions.last.json',
        'docs/PROGRAM_CLOSEOUT_AUDIT_PACK.md',
        'docs/PATCH_DIFF_MASTER.md',
        'docs/TESTPLAN_MASTER.md',
        'docs/OPS_RUNBOOK_FINAL.md',
        'docs/RELEASE_GATE_POLICY.md',
        'docs/DEFINITION_OF_DONE.md',
        'manifest.json',
        'README.txt',
    ];

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['zip' => basename($zipPath), 'status' => 'FAIL', 'reason' => 'cannot_open_zip', 'summary' => ['ok' => 0, 'warn' => 0, 'fail' => 1], 'files' => []];
    }

    $manifestRaw = $zip->getFromName('manifest.json');
    if ($manifestRaw === false) {
        $zip->close();
        return ['zip' => basename($zipPath), 'status' => 'FAIL', 'reason' => 'manifest_missing', 'summary' => ['ok' => 0, 'warn' => 0, 'fail' => 1], 'files' => []];
    }
    $manifest = json_decode((string)$manifestRaw, true);
    if (!is_array($manifest)) {
        $zip->close();
        return ['zip' => basename($zipPath), 'status' => 'FAIL', 'reason' => 'manifest_invalid', 'summary' => ['ok' => 0, 'warn' => 0, 'fail' => 1], 'files' => []];
    }

    $rows = [];
    $ok = 0;
    $warn = 0;
    $fail = 0;
    $presentTargets = [];

    $manifestFiles = (array)($manifest['files'] ?? []);
    foreach ($manifestFiles as $entry) {
        $target = (string)($entry['target'] ?? '');
        $sha = (string)($entry['sha256'] ?? '');
        if ($target === '') {
            continue;
        }
        $presentTargets[] = $target;
        $body = $zip->getFromName($target);
        if ($body === false) {
            $rows[] = ['file' => $target, 'status' => 'FAIL', 'reason' => 'missing_in_zip'];
            $fail++;
            continue;
        }
        if ($sha !== '' && hash('sha256', (string)$body) !== $sha) {
            $rows[] = ['file' => $target, 'status' => 'FAIL', 'reason' => 'sha256_mismatch'];
            $fail++;
            continue;
        }
        $rows[] = ['file' => $target, 'status' => 'OK', 'reason' => 'verified'];
        $ok++;
    }

    foreach ($required as $must) {
        if (!in_array($must, $presentTargets, true) && $zip->getFromName($must) === false) {
            $rows[] = ['file' => $must, 'status' => 'FAIL', 'reason' => 'mandatory_missing'];
            $fail++;
        }
    }

    $manifestMissingRequired = (array)($manifest['missing_required'] ?? []);
    if (!empty($manifestMissingRequired)) {
        $rows[] = ['file' => 'manifest.missing_required', 'status' => 'WARN', 'reason' => 'pack_was_generated_with_missing_required'];
        $warn++;
    }

    $zip->close();
    $status = $fail > 0 ? 'FAIL' : ($warn > 0 ? 'WARN' : 'OK');
    return [
        'zip' => basename($zipPath),
        'status' => $status,
        'summary' => ['ok' => $ok, 'warn' => $warn, 'fail' => $fail],
        'files' => $rows,
    ];
}

function vhfp_run(bool $latestOnly = false): array
{
    $root = opsgov_root();
    $dir = $root . '/exports/handover';
    $zips = vhfp_scan_dir($dir);
    if ($latestOnly && !empty($zips)) {
        $zips = [$zips[0]];
    }
    $packs = [];
    $okP = 0;
    $warnP = 0;
    $failP = 0;
    foreach ($zips as $z) {
        $r = vhfp_verify_zip($z);
        $packs[] = $r;
        if ($r['status'] === 'OK') $okP++;
        if ($r['status'] === 'WARN') $warnP++;
        if ($r['status'] === 'FAIL') $failP++;
    }
    $payload = [
        'state_version' => 1,
        'checked_at' => date(DateTimeInterface::ATOM),
        'mode' => $latestOnly ? 'latest_only' : 'all_packs',
        'summary' => [
            'OK_packs' => $okP,
            'WARN_packs' => $warnP,
            'FAIL_packs' => $failP,
            'latest_pack' => $packs[0]['zip'] ?? null,
        ],
        'packs' => $packs,
    ];

    opsgov_safe_write_json($root . '/storage/logs/handover_final_verify_last.json', $payload);
    $md = [];
    $md[] = '# Verify Handover Final Pack';
    $md[] = '';
    $md[] = '- Checked at: ' . $payload['checked_at'];
    $md[] = '- OK packs: ' . (string)$okP;
    $md[] = '- WARN packs: ' . (string)$warnP;
    $md[] = '- FAIL packs: ' . (string)$failP;
    $md[] = '- Latest pack: ' . (string)($payload['summary']['latest_pack'] ?? '-');
    @file_put_contents($root . '/storage/logs/handover_final_verify_last.md', implode("\n", $md) . "\n");

    ts_append_run_history('verify_handover_final_pack', $failP > 0 ? 'WARN' : 'OK', [
        'module' => 'tools.compliance',
        'action' => 'verify_handover_pack',
        'result' => $failP > 0 ? 'WARN' : 'OK',
        'packs_total' => count($packs),
        'fail_packs' => $failP,
    ]);
    return $payload;
}

if (PHP_SAPI === 'cli') {
    $latestOnly = false;
    foreach (($_SERVER['argv'] ?? []) as $arg) {
        $a = (string)$arg;
        if ($a === '--latest' || $a === '--latest=1' || $a === '--latest-only') {
            $latestOnly = true;
        }
    }
    echo json_encode(vhfp_run($latestOnly), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

opsgov_require_admin();
$result = opsgov_read_json(opsgov_root() . '/storage/logs/handover_final_verify_last.json');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $latestOnly = ((string)($_POST['mode'] ?? 'latest_only')) !== 'all_packs';
    $result = vhfp_run($latestOnly);
}
$baseProject = rmi_layout_base_project();
rmi_header('Verify Handover Final Pack', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Verify Handover Final Pack']]);
?>
<div class="card p-3">
  <div class="small mb-2">Verifikasi ZIP handover final (manifest checksum + mandatory files).</div>
  <form method="post" class="mb-3">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <select name="mode" class="form-select form-select-sm mb-2" style="max-width:220px;">
      <option value="latest_only">Latest pack only (cepat)</option>
      <option value="all_packs">Semua pack (lebih berat)</option>
    </select>
    <button class="btn btn-sm btn-rmi">Run Verify</button>
  </form>
  <pre class="mb-0 small"><?= opsgov_h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php rmi_footer(); ?>
