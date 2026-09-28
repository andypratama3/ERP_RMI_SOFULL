<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/ops_thresholds_lib.php';
require_once __DIR__ . '/../rfc/_lib/rfc_lib.php';
require_once __DIR__ . '/../../_shared/bootstrap.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';

$args = $_SERVER['argv'] ?? [];
$from = ops_thresholds_baseline_path();
$apply = false;
$dryRun = true;
$understand = false;
$confirm = '';
$actor = '';
$writeLast = false;
$rfcId = '';
$runId = trim((string)(getenv('PIPELINE_RUN_ID') ?: ''));
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--dry-run') {
        $dryRun = true;
        $apply = false;
        continue;
    }
    if ($arg === '--apply') {
        $apply = true;
        $dryRun = false;
        continue;
    }
    if ($arg === '--i-understand') {
        $understand = true;
        continue;
    }
    if ($arg === '--write-last') {
        $writeLast = true;
        continue;
    }
    if (str_starts_with($arg, '--confirm=')) {
        $confirm = trim((string)substr($arg, 10));
        continue;
    }
    if (str_starts_with($arg, '--actor=')) {
        $actor = trim((string)substr($arg, 8));
        continue;
    }
    if (str_starts_with($arg, '--from=')) {
        $raw = trim((string)substr($arg, 7));
        if ($raw !== '') {
            $from = str_starts_with($raw, '/') ? $raw : (ops_root() . '/' . ltrim($raw, '/'));
        }
    }
    if (str_starts_with($arg, '--rfc=')) {
        $rfcId = strtoupper(trim((string)substr($arg, 6)));
    }
    if (str_starts_with($arg, '--run-id=')) {
        $runId = trim((string)substr($arg, 9));
    }
}
$runId = $runId !== '' ? $runId : 'UNKNOWN';

$requestId = 'ops-policy-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
$errors = [];
if ($actor === '') $errors[] = 'actor_required';
if ($apply && defined('APP_ENV') && strtolower((string)APP_ENV) === 'production' && $runId === 'UNKNOWN') $errors[] = 'run_id_required_production';
$read = ops_thresholds_read_file($from);
if (!$read['ok']) {
    $errors[] = 'invalid_source';
    $errors = array_merge($errors, (array)$read['errors']);
}
if ($apply) {
    if (!$understand) $errors[] = 'missing_flag_i_understand';
    if ($confirm !== 'APPLY_OPS_POLICY') $errors[] = 'confirm_phrase_invalid';
    if (defined('APP_ENV') && strtolower((string)APP_ENV) === 'production') {
        $gate = rfc_gate_check('OPS_POLICY_CHANGE', 'production', $rfcId, true);
        if (!$gate['ok']) {
            $errors[] = 'rfc_gate_failed';
            $errors = array_merge($errors, (array)$gate['errors']);
        }
    }
}

$policy = $read['ok'] ? (array)$read['policy'] : [];
$fingerprint = $read['ok'] ? (string)$read['fingerprint'] : '';
$policyId = $read['ok'] ? (string)($policy['policy_id'] ?? '') : '';
$activePath = ops_thresholds_active_path();
$prevPath = ops_root() . '/storage/state/ops_thresholds_previous_' . date('Ymd_His') . '.yaml';
$applied = false;
$rollbackSnapshot = '';
if ($errors === [] && $apply) {
    $policy['updated_at'] = date(DateTimeInterface::ATOM);
    $policy['updated_by'] = $actor;
    $fingerprint = ops_thresholds_fingerprint($policy);
    $yamlNew = ops_thresholds_dump_yaml($policy);
    if (is_file($activePath)) {
        $rawOld = (string)@file_get_contents($activePath);
        if ($rawOld !== '') {
            @file_put_contents($prevPath, $rawOld);
            $rollbackSnapshot = ops_mask($prevPath);
        }
    }
    $dir = dirname($activePath);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (@file_put_contents($activePath, $yamlNew) === false) {
        $errors[] = 'write_active_failed';
    } else {
        $applied = true;
        $event = [
            'ts' => date(DateTimeInterface::ATOM),
            'actor_username' => $actor,
            'action' => 'OPS_POLICY_APPLIED',
            'policy_id' => $policyId,
            'fingerprint' => $fingerprint,
            'source' => 'cli',
            'request_id' => $requestId,
            'run_id' => $runId,
        ];
        ops_thresholds_append_audit_log($event);
        if (function_exists('auth_pdo') && function_exists('audit_event')) {
            $pdo = auth_pdo();
            if ($pdo instanceof PDO) {
                audit_event($pdo, 'OPS_POLICY_APPLIED', 'OPS', 'ops_policy', $policyId, 'Ops thresholds policy applied', [
                    'policy_id' => $policyId,
                    'fingerprint' => $fingerprint,
                    'request_id' => $requestId,
                    'actor_username' => $actor,
                    'source' => 'cli',
                ]);
                if ($rfcId !== '') {
                    rfc_append_audit([
                        'ts' => date(DateTimeInterface::ATOM),
                        'actor_username' => $actor,
                        'action' => 'RFC_USED_FOR_CHANGE',
                        'rfc_id' => $rfcId,
                        'request_id' => $requestId,
                        'meta_masked' => rfc_mask('change_type=OPS_POLICY_CHANGE;script=tools/ops/update_ops_thresholds.php'),
                    ]);
                    audit_event($pdo, 'RFC_USED_FOR_CHANGE', 'OPS', 'ops_policy', $policyId, 'RFC used for policy change', [
                        'rfc_id' => $rfcId,
                        'change_type' => 'OPS_POLICY_CHANGE',
                        'script_name' => 'tools/ops/update_ops_thresholds.php',
                        'request_id' => $requestId,
                    ]);
                }
            }
        }
    }
}

$ok = ($errors === []);
$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'applied_at' => date(DateTimeInterface::ATOM),
    'request_id' => $requestId,
    'run_id' => $runId,
    'env' => (defined('APP_ENV') ? strtolower((string)APP_ENV) : 'staging'),
    'overall_ok' => $ok,
    'mode' => $apply ? 'apply' : 'dry-run',
    'applied' => $applied,
    'source' => ops_mask($from),
    'active_target' => ops_mask($activePath),
    'policy_id' => $policyId,
    'fingerprint' => $fingerprint,
    'rollback_snapshot' => $rollbackSnapshot,
    'rfc_id' => $rfcId,
    'actor' => $actor,
    'errors_masked' => array_map(static fn(string $v): string => ops_mask((string)$v), $errors),
];
if ($writeLast) {
    ops_write_json(ops_thresholds_apply_last_json_path(), $payload);
    $md = [];
    $md[] = '# Ops Thresholds Apply';
    $md[] = '';
    $md[] = '- generated_at: ' . ops_mask((string)$payload['generated_at']);
    $md[] = '- request_id: ' . ops_mask($requestId);
    $md[] = '- run_id: ' . ops_mask($runId);
    $md[] = '- mode: ' . ($apply ? 'apply' : 'dry-run');
    $md[] = '- overall_ok: ' . ($ok ? 'true' : 'false');
    $md[] = '- applied: ' . ($applied ? 'true' : 'false');
    $md[] = '- source: ' . ops_mask($from);
    $md[] = '- active_target: ' . ops_mask($activePath);
    if ($policyId !== '') $md[] = '- policy_id: ' . ops_mask($policyId);
    if ($fingerprint !== '') $md[] = '- fingerprint: ' . ops_mask($fingerprint);
    if ($rollbackSnapshot !== '') $md[] = '- rollback_snapshot: ' . $rollbackSnapshot;
    if ((array)$payload['errors_masked'] !== []) {
        $md[] = '';
        $md[] = '## Errors';
        foreach ((array)$payload['errors_masked'] as $e) $md[] = '- ' . ops_mask((string)$e);
    }
    @file_put_contents(ops_thresholds_apply_last_md_path(), implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

