<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/alert_policy_lib.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/../../_shared/bootstrap.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$from = 'docs/governance/ALERTING_POLICY.yaml';
$apply = false;
$writeLast = false;
$confirm = '';
$rfcId = '';
$actor = tools_current_actor_username();
$runId = trim((string)(getenv('PIPELINE_RUN_ID') ?: ''));
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--apply') $apply = true;
    elseif ($arg === '--dry-run') $apply = false;
    elseif ($arg === '--write-last') $writeLast = true;
    elseif (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--from=')) $from = trim((string)substr($arg, 7));
    elseif (str_starts_with($arg, '--confirm=')) $confirm = trim((string)substr($arg, 10));
    elseif (str_starts_with($arg, '--rfc=')) $rfcId = strtoupper(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--actor=')) $actor = trim((string)substr($arg, 8));
    elseif (str_starts_with($arg, '--run-id=')) $runId = trim((string)substr($arg, 9));
}
if (!in_array($env, ['staging', 'production'], true)) $env = 'staging';
if (!str_starts_with($from, '/')) $from = ap_root() . '/' . ltrim($from, '/');
$runId = $runId !== '' ? $runId : 'UNKNOWN';
$requestId = 'alert-policy-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);

$pipeDir = ap_root() . '/storage/logs/pipeline';
if (!is_dir($pipeDir)) @mkdir($pipeDir, 0775, true);

// clean-room guard
$cleanOut = [];
$cleanCode = 1;
@exec(escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ap_root() . '/tools/dev/verify_clean_room.php') . ' 2>&1', $cleanOut, $cleanCode);
if ($cleanCode !== 0) {
    $payload = [
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'request_id' => $requestId,
        'env' => $env,
        'apply' => $apply,
        'overall_ok' => false,
        'error' => 'ERR_CLEAN_ROOM_GUARD_FAILED',
        'evidence_masked' => ap_mask(implode(' | ', array_slice($cleanOut, -3))),
    ];
    @file_put_contents($pipeDir . '/alert_policy_apply_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$validate = ap_load_policy_file($from);
$okValidate = (bool)$validate['ok'];
$errors = mask_sensitive_in_errors((array)$validate['errors']);
$policy = (array)$validate['data'];
$canonical = $okValidate ? canonicalize_yaml_for_hash($policy) : '';
$fingerprint = $okValidate ? policy_fingerprint($canonical) : '';

$overallOk = $okValidate;
$errCode = '';
if (!$okValidate) $errCode = 'ERR_POLICY_INVALID';

if ($apply) {
    if ($confirm !== 'APPLY_ALERT_POLICY') {
        $overallOk = false;
        $errCode = 'ERR_CONFIRMATION_REQUIRED';
    }
    if (!in_array('--i-understand', $args, true)) {
        $overallOk = false;
        $errCode = 'ERR_UNDERSTAND_FLAG_REQUIRED';
    }
}

if ($apply && $env === 'production') {
    if ($runId === 'UNKNOWN') {
        $overallOk = false;
        $errCode = 'ERR_RUN_ID_REQUIRED';
    }
    if ($rfcId === '') {
        $overallOk = false;
        $errCode = 'ERR_RFC_REQUIRED';
    } else {
        $rfcCmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ap_root() . '/tools/rfc/rfc_check_required.php')
            . ' --type=OPS_ALERT_POLICY_CHANGE --env=production --rfc=' . escapeshellarg($rfcId) . ' --strict';
        $rfcOut = [];
        $rfcCode = 1;
        @exec($rfcCmd . ' 2>&1', $rfcOut, $rfcCode);
        if ((int)$rfcCode !== 0) {
            $overallOk = false;
            $errCode = 'ERR_RFC_GATE_FAILED';
            $errors[] = ap_mask(implode(' | ', array_slice($rfcOut, -2)));
        }
    }
}

$activePath = ap_active_policy_path();
$backupPath = '';
if ($overallOk && $apply) {
    if (!is_dir(dirname($activePath)) && !@mkdir(dirname($activePath), 0775, true) && !is_dir(dirname($activePath))) {
        $overallOk = false;
        $errCode = 'ERR_STORAGE_NOT_WRITABLE';
    }
    if ($overallOk && is_file($activePath)) {
        $backupPath = ap_root() . '/storage/state/alerting_policy_previous_' . date('YmdHis') . '.yaml';
        @copy($activePath, $backupPath);
    }
    if ($overallOk) {
        $policy['updated_at'] = date(DateTimeInterface::ATOM);
        $policy['updated_by'] = $actor;
        $policy['request_id'] = $requestId;
        if (!isset($policy['policy_id']) || trim((string)$policy['policy_id']) === '') {
            $policy['policy_id'] = 'ALERT-POLICY-' . date('YmdHis');
        }
        $content = json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $tmp = $activePath . '.tmp.' . getmypid();
        $w = @file_put_contents($tmp, $content);
        if ($w === false || !@rename($tmp, $activePath)) {
            $overallOk = false;
            $errCode = 'ERR_ACTIVE_WRITE_FAILED';
        } else {
            $canonical = canonicalize_yaml_for_hash($policy);
            $fingerprint = policy_fingerprint($canonical);
            try {
                if (function_exists('auth_pdo') && function_exists('audit_event')) {
                    $pdo = auth_pdo();
                    if ($pdo instanceof PDO) {
                        audit_event($pdo, 'ALERT_POLICY_APPLIED', 'OPS', 'alert_policy', $requestId, 'Alert policy applied', [
                            'policy_id' => (string)$policy['policy_id'],
                            'fingerprint' => $fingerprint,
                            'rfc_id' => $rfcId,
                        ]);
                    }
                }
            } catch (Throwable $e) {
            }
        }
    }
}

$auditLine = [
    'ts' => date(DateTimeInterface::ATOM),
    'actor_username' => $actor,
    'env' => $env,
    'action' => 'ALERT_POLICY_APPLIED',
    'rfc_id' => $rfcId,
    'fingerprint' => $fingerprint,
    'policy_id' => (string)($policy['policy_id'] ?? ''),
    'request_id' => $requestId,
    'run_id' => $runId,
    'result' => $overallOk ? 'OK' : 'FAIL',
];
if (!is_dir(ap_root() . '/storage/logs')) @mkdir(ap_root() . '/storage/logs', 0775, true);
@file_put_contents(ap_root() . '/storage/logs/audit_alert_policy.jsonl', json_encode($auditLine, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'applied_at' => date(DateTimeInterface::ATOM),
    'request_id' => $requestId,
    'run_id' => $runId,
    'env' => $env,
    'apply' => $apply,
    'from_masked' => ap_mask($from),
    'active_path_masked' => ap_mask($activePath),
    'backup_path_masked' => $backupPath !== '' ? ap_mask($backupPath) : '',
    'overall_ok' => $overallOk,
    'error' => $errCode,
    'errors_masked' => $errors,
    'rfc_id' => $rfcId,
    'actor' => $actor,
    'actor_username' => $actor,
    'policy_id' => (string)($policy['policy_id'] ?? ''),
    'fingerprint' => $fingerprint,
    'policy_fingerprint' => $fingerprint,
];
$md = [];
$md[] = '# Alert Policy Apply';
$md[] = '';
$md[] = '- request_id: ' . ap_mask($requestId);
$md[] = '- run_id: ' . ap_mask($runId);
$md[] = '- env: ' . ap_mask($env);
$md[] = '- apply: ' . ($apply ? 'true' : 'false');
$md[] = '- overall_ok: ' . ($overallOk ? 'true' : 'false');
$md[] = '- error: ' . ap_mask($errCode);
$md[] = '- policy_id: ' . ap_mask((string)($payload['policy_id'] ?? ''));
$md[] = '- fingerprint: ' . ap_mask($fingerprint);
if ($errors !== []) {
    $md[] = '';
    $md[] = '## Errors';
    foreach ($errors as $e) $md[] = '- ' . ap_mask((string)$e);
}
@file_put_contents($pipeDir . '/alert_policy_apply_' . date('YmdHis') . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
@file_put_contents($pipeDir . '/alert_policy_apply_' . date('YmdHis') . '.md', implode("\n", $md) . "\n");
if ($writeLast) {
    @file_put_contents($pipeDir . '/alert_policy_apply_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    @file_put_contents($pipeDir . '/alert_policy_apply_last.md', implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);

