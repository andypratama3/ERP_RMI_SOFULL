<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

function evidence_verify_scan_dir(string $dir): array
{
    $zips = glob(rtrim($dir, '/') . '/*/*.zip') ?: [];
    $zips = array_merge($zips, glob(rtrim($dir, '/') . '/*.zip') ?: []);
    rsort($zips);
    return $zips;
}

function evidence_verify_zip(string $zipPath): array
{
    $required = [
        'SMOKE_RESULTS_FINAL.md',
        'cutover_checks.last.json',
        'all_checks.last.json',
        'contract_check_last.json',
        'readiness_report_last.json',
        'smoke_http_last.json',
        'tools_run_history.jsonl',
        'SIGNOFF_TEMPLATE.md',
    ];
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['zip' => basename($zipPath), 'status' => 'FAIL', 'reason' => 'cannot_open_zip', 'files' => []];
    }
    $manifestRaw = $zip->getFromName('manifest.json');
    if ($manifestRaw === false) {
        $zip->close();
        return ['zip' => basename($zipPath), 'status' => 'FAIL', 'reason' => 'manifest_missing', 'files' => []];
    }
    $manifest = json_decode((string)$manifestRaw, true);
    if (!is_array($manifest)) {
        $zip->close();
        return ['zip' => basename($zipPath), 'status' => 'FAIL', 'reason' => 'manifest_invalid', 'files' => []];
    }
    $files = (array)($manifest['files'] ?? []);
    $rows = [];
    $fail = 0; $warn = 0; $ok = 0;
    foreach ($files as $f) {
        $name = (string)($f['name'] ?? '');
        $sha = (string)($f['sha256'] ?? '');
        if ($name === '') continue;
        $body = $zip->getFromName($name);
        if ($body === false) {
            $rows[] = ['file' => $name, 'status' => 'FAIL', 'reason' => 'file_missing_in_zip'];
            $fail++;
            continue;
        }
        $hash = hash('sha256', (string)$body);
        if ($sha !== '' && $sha !== $hash) {
            $rows[] = ['file' => $name, 'status' => 'FAIL', 'reason' => 'sha256_mismatch'];
            $fail++;
            continue;
        }
        $rows[] = ['file' => $name, 'status' => 'OK', 'reason' => 'verified'];
        $ok++;
    }
    $present = array_map(static fn(array $r): string => (string)($r['file'] ?? ''), $rows);
    foreach ($required as $must) {
        if (!in_array($must, $present, true)) {
            $rows[] = ['file' => $must, 'status' => 'FAIL', 'reason' => 'mandatory_missing'];
            $fail++;
        }
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

function evidence_verify_run(): array
{
    $root = opsgov_root();
    $dir = $root . '/exports/evidence';
    $zips = evidence_verify_scan_dir($dir);
    $rows = [];
    $okP = 0; $warnP = 0; $failP = 0;
    foreach ($zips as $z) {
        $r = evidence_verify_zip($z);
        $rows[] = $r;
        if ($r['status'] === 'OK') $okP++;
        if ($r['status'] === 'WARN') $warnP++;
        if ($r['status'] === 'FAIL') $failP++;
    }
    $payload = [
        'state_version' => 1,
        'checked_at' => date(DateTimeInterface::ATOM),
        'packs' => $rows,
        'summary' => [
            'OK_packs' => $okP,
            'WARN_packs' => $warnP,
            'FAIL_packs' => $failP,
            'latest_pack' => $rows[0]['zip'] ?? null,
        ],
    ];
    opsgov_safe_write_json($root . '/storage/logs/evidence_status_last.json', [
        'state_version' => 1,
        'checked_at' => $payload['checked_at'],
        'OK_packs' => $okP,
        'WARN_packs' => $warnP,
        'FAIL_packs' => $failP,
        'latest_pack' => $rows[0]['zip'] ?? null,
    ]);
    opsgov_safe_write_json($root . '/storage/logs/evidence_verify_last.json', $payload);
    ts_append_run_history('verify_evidence_pack', $failP > 0 ? 'WARN' : 'OK', [
        'module' => 'tools.compliance',
        'action' => 'verify_pack',
        'result' => $failP > 0 ? 'WARN' : 'OK',
        'packs_total' => count($rows),
        'fail_packs' => $failP,
    ]);
    return $payload;
}

if (PHP_SAPI === 'cli') {
    echo json_encode(evidence_verify_run(), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

opsgov_require_admin();
$result = opsgov_read_json(opsgov_root() . '/storage/logs/evidence_verify_last.json');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $result = evidence_verify_run();
}
$baseProject = rmi_layout_base_project();
rmi_header('Verify Evidence Pack', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Verify Evidence Pack']]);
?>
<div class="card p-3">
  <div class="small mb-2">Verifikasi checksum `manifest.json` dan mandatory evidence dalam ZIP.</div>
  <form method="post" class="mb-3">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <button class="btn btn-sm btn-rmi">Run Verify</button>
  </form>
  <pre class="mb-0 small"><?= opsgov_h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php rmi_footer(); ?>
