<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/rfc_usage_lib.php';

$env = 'staging';
$runIdArg = '';
$writeLast = false;
$strict = false;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (!is_string($arg) || $arg === '') {
        continue;
    }
    if ($arg === '--write-last') {
        $writeLast = true;
        continue;
    }
    if ($arg === '--strict') {
        $strict = true;
        continue;
    }
    if (str_starts_with($arg, '--env=')) {
        $env = strtolower(trim((string)substr($arg, 6)));
        continue;
    }
    if (str_starts_with($arg, '--run-id=')) {
        $runIdArg = trim((string)substr($arg, 9));
        continue;
    }
}
if (!in_array($env, ['staging', 'production'], true)) {
    $env = 'staging';
}
if ($env === 'production' && $strict === false) {
    $strict = true;
}

$pipelineDir = rfc_usage_pipeline_dir();
$pipelineLastPath = $pipelineDir . '/pipeline_last.json';
$pipelineLastRead = rfc_usage_read_json_strict($pipelineLastPath);
$pipelineLast = (array)$pipelineLastRead['data'];

$targetRunId = $runIdArg;
if ($targetRunId === '' || strtolower($targetRunId) === 'last') {
    $targetRunId = trim((string)($pipelineLast['run_id'] ?? ''));
}
if ($targetRunId === '') {
    $targetRunId = 'UNKNOWN';
}

$index = ts_read_json(rfc_index_state_path());
if (!is_array($index) || !isset($index['rfcs']) || !is_array($index['rfcs'])) {
    $index = ['rfcs' => []];
}

$ids = [];
$dataMissing = [];
$inputHits = [];
$alertPolicyApplied = false;

foreach (rfc_usage_known_inputs() as $inp) {
    $source = (string)($inp['source'] ?? 'unknown.json');
    $path = (string)($inp['path'] ?? '');
    $read = rfc_usage_read_json_strict($path);
    if (!$read['ok']) {
        $dataMissing[] = ['source' => $source, 'reason' => (string)$read['error']];
        continue;
    }
    $data = (array)$read['data'];
    $dataRunId = trim((string)($data['run_id'] ?? ($data['build']['run_id'] ?? '')));
    if ($dataRunId === '') {
        $dataMissing[] = ['source' => $source, 'reason' => 'RUN_ID_MISSING'];
        continue;
    }
    if ($dataRunId !== $targetRunId) {
        $dataMissing[] = ['source' => $source, 'reason' => 'RUN_ID_MISMATCH'];
        continue;
    }
    $inputHits[$source] = true;
    foreach (rfc_usage_extract_ids($data) as $rid) {
        $ids[] = $rid;
    }
    if ($source === 'alert_policy_apply_last.json' && (bool)($data['apply'] ?? false) === true && (bool)($data['overall_ok'] ?? false) === true) {
        $alertPolicyApplied = true;
    }
}

$ids = array_values(array_unique($ids));
sort($ids);

$primaryRfcId = '';
$pipelineRfcId = strtoupper(trim((string)($pipelineLast['rfc_id'] ?? '')));
if ($pipelineRfcId !== '' && preg_match('/^RFC\-\d{4}\-\d{4}$/', $pipelineRfcId) === 1) {
    $primaryRfcId = $pipelineRfcId;
}
$releaseNotesLast = rfc_usage_read_json_strict(rfc_root() . '/storage/exports/release/release_notes_last.json');
if ($primaryRfcId === '' && $releaseNotesLast['ok']) {
    $rn = (array)$releaseNotesLast['data'];
    $rnRunId = trim((string)($rn['run_id'] ?? ($rn['build']['run_id'] ?? '')));
    $rnRfcId = strtoupper(trim((string)($rn['rfc']['id'] ?? '')));
    if ($rnRunId === $targetRunId && preg_match('/^RFC\-\d{4}\-\d{4}$/', $rnRfcId) === 1) {
        $primaryRfcId = $rnRfcId;
    }
}
if ($primaryRfcId !== '') {
    $ids[] = $primaryRfcId;
    $ids = array_values(array_unique($ids));
}

$rfcRows = [];
foreach ($ids as $rid) {
    $meta = rfc_usage_metadata($rid, $index);
    if ($meta === []) {
        $dataMissing[] = ['source' => $rid, 'reason' => 'RFC_METADATA_MISSING'];
        continue;
    }
    $type = (string)($meta['type'] ?? '');
    $rfcRows[] = [
        'id' => (string)$meta['id'],
        'primary' => ((string)$meta['id'] === $primaryRfcId),
        'type' => $type,
        'status' => (string)($meta['status'] ?? ''),
        'title' => (string)($meta['title'] ?? ''),
        'schedule' => $meta['schedule'] ?? null,
        'approvals' => (array)($meta['approvals'] ?? ['required' => [], 'completed' => [], 'missing' => []]),
        'tags' => rfc_usage_type_tags($type),
        'file_rel_path' => (string)($meta['file_rel_path'] ?? ''),
    ];
}

$notes = [];
$overallOk = true;
if ($strict) {
    if ($targetRunId === 'UNKNOWN') {
        $overallOk = false;
        $notes[] = 'critical: run_id_unknown_in_strict_mode';
    }
    if ($primaryRfcId === '') {
        $overallOk = false;
        $notes[] = 'critical: primary_rfc_missing';
    } else {
        $primary = [];
        foreach ($rfcRows as $row) {
            if ((string)$row['id'] === $primaryRfcId) {
                $primary = $row;
                break;
            }
        }
        if ($primary === [] || strtoupper((string)($primary['status'] ?? '')) !== 'APPROVED') {
            $overallOk = false;
            $notes[] = 'critical: primary_rfc_not_approved';
        }
    }
    if ($alertPolicyApplied) {
        $foundApprovedAlertPolicyRfc = false;
        foreach ($rfcRows as $row) {
            if (strtoupper((string)($row['type'] ?? '')) === 'OPS_ALERT_POLICY_CHANGE' && strtoupper((string)($row['status'] ?? '')) === 'APPROVED') {
                $foundApprovedAlertPolicyRfc = true;
                break;
            }
        }
        if (!$foundApprovedAlertPolicyRfc) {
            $overallOk = false;
            $notes[] = 'critical: alert_policy_applied_without_approved_rfc';
        }
    }
} else {
    $notes[] = 'non_strict_mode: approvals_may_be_incomplete';
}

if ($dataMissing !== []) {
    $notes[] = 'DATA_MISSING detected; run helper commands below';
}

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'env' => $env,
    'run_id' => $targetRunId,
    'overall_ok' => $overallOk,
    'primary_rfc_id' => $primaryRfcId !== '' ? $primaryRfcId : null,
    'rfcs' => $rfcRows,
    'data_missing' => array_values($dataMissing),
    'notes' => array_values(array_unique(array_map(static fn(string $v): string => rfc_mask($v), $notes))),
];

$safeRunId = preg_replace('/[^A-Za-z0-9_\-]/', '_', $targetRunId) ?: 'UNKNOWN';
$jsonRunPath = $pipelineDir . '/rfc_usage_' . $safeRunId . '.json';
$mdRunPath = $pipelineDir . '/rfc_usage_' . $safeRunId . '.md';
$jsonLastPath = $pipelineDir . '/rfc_usage_last.json';
$mdLastPath = $pipelineDir . '/rfc_usage_last.md';
ts_write_json($jsonRunPath, $payload);

$md = [];
$md[] = '# RFC Usage Pack - ' . rfc_mask($env) . ' - ' . rfc_mask($targetRunId) . ' - ' . rfc_mask((string)$payload['generated_at']);
$md[] = '';
$md[] = 'Primary RFC: ' . rfc_mask($primaryRfcId !== '' ? $primaryRfcId : 'DATA_MISSING');
$md[] = '';
if ($rfcRows !== []) {
    $md[] = '| id | type | status | approvals missing | tags | file |';
    $md[] = '|---|---|---|---|---|---|';
    foreach ($rfcRows as $row) {
        $md[] = '| '
            . rfc_mask((string)$row['id']) . ' | '
            . rfc_mask((string)$row['type']) . ' | '
            . rfc_mask((string)$row['status']) . ' | '
            . rfc_mask(implode(',', (array)($row['approvals']['missing'] ?? []))) . ' | '
            . rfc_mask(implode(',', (array)($row['tags'] ?? []))) . ' | '
            . rfc_mask((string)$row['file_rel_path']) . ' |';
    }
} else {
    $md[] = 'DATA_MISSING: no RFCs linked for this run_id.';
}
$md[] = '';
$hasAlertPolicy = false;
foreach ($rfcRows as $row) {
    if (in_array('ALERT_POLICY', (array)($row['tags'] ?? []), true)) {
        $hasAlertPolicy = true;
        $md[] = '## Alert Policy RFC';
        $md[] = '- ' . rfc_mask((string)$row['id']) . ' | status=' . rfc_mask((string)$row['status']) . ' | type=' . rfc_mask((string)$row['type']);
    }
}
if (!$hasAlertPolicy) {
    $md[] = '## Alert Policy RFC';
    $md[] = '- DATA_MISSING';
}
if ($dataMissing !== []) {
    $md[] = '';
    $md[] = '## DATA_MISSING';
    foreach ($dataMissing as $dm) {
        $md[] = '- ' . rfc_mask((string)($dm['source'] ?? 'unknown')) . ': ' . rfc_mask((string)($dm['reason'] ?? 'UNKNOWN'));
    }
    $md[] = '';
    $md[] = '## Commands';
    $md[] = '- `php tools/rfc/rfc_index_scan.php --write-last`';
    $md[] = '- `php tools/rfc/collect_rfc_usage.php --env=' . $env . ' --run-id=' . $targetRunId . ' --write-last' . ($strict ? ' --strict' : '') . '`';
    $md[] = '- `php tools/ops/update_alerting_policy.php --apply --i-understand --confirm=APPLY_ALERT_POLICY --run-id=' . $targetRunId . ' --write-last`';
    $md[] = '- `php tools/ops/update_ops_thresholds.php --apply --i-understand --confirm=APPLY_OPS_POLICY --run-id=' . $targetRunId . ' --write-last --actor=<username>`';
}
@file_put_contents($mdRunPath, implode("\n", $md) . "\n");

if ($writeLast) {
    ts_write_json($jsonLastPath, $payload);
    @copy($mdRunPath, $mdLastPath);
}

$requestId = 'rfc-link-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
rfc_usage_append_audit([
    'ts' => date(DateTimeInterface::ATOM),
    'actor_username' => getenv('USER') ?: 'SYSTEM',
    'env' => $env,
    'run_id' => $targetRunId,
    'action' => 'RFC_USAGE_COLLECTED',
    'primary_rfc_id' => $primaryRfcId !== '' ? $primaryRfcId : null,
    'count' => count($rfcRows),
    'request_id' => $requestId,
]);

$out = [
    'state_version' => 1,
    'overall_ok' => $overallOk,
    'env' => $env,
    'run_id' => $targetRunId,
    'primary_rfc_id' => $primaryRfcId !== '' ? $primaryRfcId : null,
    'count' => count($rfcRows),
    'artifacts' => [
        'json' => rfc_mask($jsonRunPath),
        'md' => rfc_mask($mdRunPath),
        'json_last' => $writeLast ? rfc_mask($jsonLastPath) : null,
        'md_last' => $writeLast ? rfc_mask($mdLastPath) : null,
    ],
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);

