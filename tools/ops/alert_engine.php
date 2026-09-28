<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/alerting_lib.php';
require_once __DIR__ . '/_lib/alert_policy_lib.php';

$args = $_SERVER['argv'] ?? [];
$env = 'staging';
$strict = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
    elseif ($arg === '--strict') $strict = true;
}
if (!in_array($env, ['staging', 'production'], true)) $env = 'staging';
if ($env === 'production') $strict = true;

$requestId = 'alert-engine-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
$lockPath = al_alert_lock_path();
if (is_file($lockPath)) {
    $lock = ts_read_json($lockPath);
    $ts = strtotime((string)($lock['ts'] ?? ''));
    $age = $ts === false ? 0 : (time() - $ts);
    if ($age < 900) {
        $out = ['ok' => false, 'error' => 'ERR_LOCKED', 'request_id' => $requestId];
        al_write_log_line($out);
        echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(1);
    }
}
al_atomic_write_json($lockPath, ['state_version' => 1, 'ts' => al_iso_now(), 'pid' => getmypid(), 'request_id' => $requestId]);

$clean = [];
$cleanCode = 1;
@exec(escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ts_root() . '/tools/dev/verify_clean_room.php') . ' 2>&1', $clean, $cleanCode);
if ($cleanCode !== 0) {
    $out = [
        'ok' => false,
        'error' => 'ERR_CLEAN_ROOM_GUARD_FAILED',
        'strict' => $strict,
        'request_id' => $requestId,
        'evidence_masked' => al_mask(implode(' | ', array_slice($clean, -3))),
    ];
    al_write_log_line($out);
    echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @unlink($lockPath);
    exit(1);
}

$policyLoad = load_alert_policy($env);
$policy = (array)$policyLoad['policy'];
$policyInvalid = !$policyLoad['ok'];
$policyFingerprint = (string)($policyLoad['policy_fingerprint'] ?? '');
$policySource = (string)($policyLoad['source'] ?? '');
$policyId = (string)($policyLoad['policy_id'] ?? '');
$policyUpdatedAt = (string)($policyLoad['policy_updated_at'] ?? '');
$policyUpdatedBy = (string)($policyLoad['policy_updated_by'] ?? '');
if ($env === 'production' && $policyInvalid) {
    $bannerFail = [
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'env' => $env,
        'level' => 'ATTENTION',
        'headline' => 'ALERT_POLICY_MISSING_OR_INVALID',
        'primary_owner' => 'OPS',
        'secondary_owner' => 'ENG_LEAD',
        'sla_due_at' => '',
        'workflow_status' => 'OPEN',
        'breached' => false,
        'badge_sla_breach' => false,
        'top_rule' => 'ALERT_POLICY_MISSING_OR_INVALID',
        'reasons_masked' => array_map('ap_mask', (array)($policyLoad['errors'] ?? ['ERR_POLICY_INVALID'])),
        'admin_link' => '/tools/ops/alerts.php',
        'policy_source' => $policySource,
        'policy_id' => $policyId,
        'policy_fingerprint' => $policyFingerprint,
    ];
    al_atomic_write_json(al_ops_banner_path(), $bannerFail);
    $alertsFail = [
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'env' => $env,
        'request_id' => $requestId,
        'level' => 'ATTENTION',
        'headline' => 'ALERT_POLICY_MISSING_OR_INVALID',
        'data_missing' => true,
        'policy_invalid' => true,
        'policy_source' => $policySource,
        'policy_id' => $policyId,
        'policy_fingerprint' => $policyFingerprint,
        'errors_masked' => array_map('ap_mask', (array)($policyLoad['errors'] ?? ['ERR_POLICY_INVALID'])),
        'active_rules' => [],
        'rules' => [],
    ];
    al_atomic_write_json(al_alerts_last_path(), $alertsFail);
    $out = [
        'ok' => false,
        'error' => 'ALERT_POLICY_MISSING_OR_INVALID',
        'strict' => true,
        'request_id' => $requestId,
        'errors_masked' => (array)$alertsFail['errors_masked'],
        'policy_source' => $policySource,
        'policy_path' => (string)($policyLoad['path_masked'] ?? ''),
    ];
    al_write_log_line($out);
    echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @unlink($lockPath);
    exit(1);
}

$nowTs = time();
$nowIso = al_iso_now($nowTs);
$triggered = al_eval_triggered_rules(['env' => $env]);
$triggeredKeys = array_keys($triggered);

$workflowWrap = al_load_workflow_state($env);
$workflow = (array)$workflowWrap['data'];
$rulesState = (array)($workflow['rules'] ?? []);

$allRuleKeys = array_values(array_unique(array_merge(
    array_keys((array)($policy['owners']['rules'] ?? [])),
    array_keys($rulesState),
    $triggeredKeys
)));

$escalations = [];
foreach ($allRuleKeys as $ruleKey) {
    $isTriggered = isset($triggered[$ruleKey]);
    $cur = (array)($rulesState[$ruleKey] ?? []);
    $severity = al_rule_severity($ruleKey, []);
    $owners = al_get_rule_owners($ruleKey, $policy, $cur);
    $statusBefore = strtoupper((string)($cur['status'] ?? ''));

    if ($cur === []) {
        $cur = [
            'status' => 'RESOLVED',
            'opened_at' => $nowIso,
            'opened_request_id' => $requestId,
            'last_seen_at' => $nowIso,
            'ack' => ['acknowledged_at' => null, 'acknowledged_by' => null, 'note' => null],
            'in_progress' => ['set_at' => null, 'set_by' => null],
            'resolved' => ['resolved_at' => $nowIso, 'resolved_by' => 'SYSTEM', 'resolution_type' => 'AUTO'],
            'owners' => $owners,
            'sla' => al_build_sla($severity, $nowTs, $policy),
            'severity' => $severity,
        ];
        $statusBefore = 'RESOLVED';
    }
    if (!isset($cur['status']) || trim((string)$cur['status']) === '') $cur['status'] = 'RESOLVED';
    if (!isset($cur['opened_at']) || trim((string)$cur['opened_at']) === '') $cur['opened_at'] = $nowIso;
    if (!isset($cur['opened_request_id']) || trim((string)$cur['opened_request_id']) === '') $cur['opened_request_id'] = $requestId;
    if (!isset($cur['ack']) || !is_array($cur['ack'])) $cur['ack'] = ['acknowledged_at' => null, 'acknowledged_by' => null, 'note' => null];
    if (!isset($cur['in_progress']) || !is_array($cur['in_progress'])) $cur['in_progress'] = ['set_at' => null, 'set_by' => null];
    if (!isset($cur['resolved']) || !is_array($cur['resolved'])) $cur['resolved'] = ['resolved_at' => null, 'resolved_by' => null, 'resolution_type' => null];

    if ($isTriggered) {
        if (in_array($statusBefore, ['RESOLVED', 'SUPPRESSED'], true)) {
            $cur['status'] = 'OPEN';
            $cur['opened_at'] = $nowIso;
            $cur['opened_request_id'] = $requestId;
            $cur['ack'] = ['acknowledged_at' => null, 'acknowledged_by' => null, 'note' => null];
            $cur['in_progress'] = ['set_at' => null, 'set_by' => null];
            $cur['resolved'] = ['resolved_at' => null, 'resolved_by' => null, 'resolution_type' => null];
        }
        $openedTs = al_parse_iso((string)($cur['opened_at'] ?? $nowIso));
        $cur['sla'] = al_build_sla($severity, $openedTs, $policy);
    } else {
        if (in_array($statusBefore, ['OPEN', 'ACKNOWLEDGED', 'IN_PROGRESS'], true)) {
            $cur['status'] = 'RESOLVED';
            $cur['resolved'] = [
                'resolved_at' => $nowIso,
                'resolved_by' => 'SYSTEM',
                'resolution_type' => 'AUTO',
            ];
        }
        if (!isset($cur['sla']) || !is_array($cur['sla'])) {
            $cur['sla'] = al_build_sla($severity, $nowTs, $policy);
        }
    }

    $cur['last_seen_at'] = $nowIso;
    $cur['severity'] = $severity;
    $cur['owners'] = $owners;
    $breached = al_detect_breach($cur, $nowTs);
    $cur['sla']['breached'] = $breached;
    if ($breached && !in_array($ruleKey, $triggeredKeys, true)) {
        // if rule not triggered but due metadata stale, do not escalate
        $cur['sla']['breached'] = false;
        $breached = false;
    }
    if ($breached && (bool)($policy['escalation']['enabled'] ?? true)) {
        $escalations[] = $ruleKey;
    }
    $rulesState[$ruleKey] = $cur;
}

$workflow['state_version'] = 1;
$workflow['updated_at'] = $nowIso;
$workflow['env'] = $env;
$workflow['rules'] = $rulesState;
al_atomic_write_json(al_workflow_path(), $workflow);

$top = al_pick_top_rule_for_banner($rulesState, $triggeredKeys);
$topRule = (string)($top['key'] ?? '');
$topRow = (array)($top['row'] ?? []);
$level = 'HEALTHY';
if ($topRule !== '') {
    $level = strtoupper((string)($topRow['severity'] ?? 'ATTENTION')) === 'CRITICAL' ? 'CRITICAL' : 'ATTENTION';
}
$headline = match ($level) {
    'CRITICAL' => 'Critical action required',
    'ATTENTION' => 'Attention required',
    default => 'All checks healthy',
};
$workflowStatus = (string)($topRow['status'] ?? ($topRule === '' ? 'RESOLVED' : 'OPEN'));
$slaDue = '';
if ($topRule !== '') {
    if ($workflowStatus === 'OPEN') $slaDue = (string)($topRow['sla']['ack_due_at'] ?? '');
    else $slaDue = (string)($topRow['sla']['resolve_due_at'] ?? '');
}
$hasBreach = false;
foreach ($triggeredKeys as $k) {
    if (!empty($rulesState[$k]['sla']['breached'])) {
        $hasBreach = true;
        break;
    }
}

$banner = [
    'state_version' => 1,
    'generated_at' => $nowIso,
    'env' => $env,
    'level' => $level,
    'headline' => $headline,
    'primary_owner' => (string)($topRow['owners']['primary'] ?? 'TBD'),
    'secondary_owner' => (string)($topRow['owners']['secondary'] ?? 'TBD'),
    'sla_due_at' => $slaDue,
    'workflow_status' => $workflowStatus,
    'breached' => $hasBreach,
    'badge_sla_breach' => $hasBreach && (bool)($policy['escalation']['banner_add_sla_breach_badge'] ?? true),
    'top_rule' => $topRule,
    'reasons_masked' => array_map(static fn(string $k): string => al_mask($k . ':' . (string)($triggered[$k]['reason'] ?? 'triggered')), $triggeredKeys),
    'admin_link' => '/tools/ops/alerts.php',
    'policy_source' => $policySource,
    'policy_id' => $policyId,
    'policy_fingerprint' => $policyFingerprint,
    'policy_updated_at' => $policyUpdatedAt,
    'policy_updated_by' => $policyUpdatedBy,
];
al_atomic_write_json(al_ops_banner_path(), $banner);

$alertsLast = [
    'state_version' => 1,
    'generated_at' => $nowIso,
    'env' => $env,
    'request_id' => $requestId,
    'level' => $level,
    'headline' => $headline,
    'active_rule_count' => count($triggeredKeys),
    'active_rules' => $triggeredKeys,
    'policy_source' => $policySource,
    'policy_id' => $policyId,
    'policy_fingerprint' => $policyFingerprint,
    'policy_updated_at' => $policyUpdatedAt,
    'policy_updated_by' => $policyUpdatedBy,
    'rules' => $rulesState,
    'workflow_state_corrupt' => (bool)$workflowWrap['corrupt'],
    'errors_masked' => array_map('al_mask', (array)$workflowWrap['errors']),
];
if ($workflowWrap['corrupt']) {
    $alertsLast['level'] = $alertsLast['level'] === 'CRITICAL' ? 'CRITICAL' : 'ATTENTION';
    if (!in_array('WORKFLOW_STATE_CORRUPT', (array)$alertsLast['errors_masked'], true)) {
        $alertsLast['errors_masked'][] = 'WORKFLOW_STATE_CORRUPT';
    }
}
al_atomic_write_json(al_alerts_last_path(), $alertsLast);

if ($hasBreach && (bool)($policy['escalation']['enabled'] ?? true)) {
    foreach ($escalations as $ruleKey) {
        al_append_audit([
            'actor_username' => 'SYSTEM',
            'request_id' => $requestId,
            'action' => 'ALERT_ESCALATED',
            'env' => $env,
            'rule_key' => $ruleKey,
            'new_status' => (string)($rulesState[$ruleKey]['status'] ?? 'OPEN'),
            'owners' => (array)($rulesState[$ruleKey]['owners'] ?? []),
            'result' => 'OK',
        ]);
        if ((bool)($policy['escalation']['create_manual_action_queue'] ?? false)) {
            al_create_manual_action_queue_item($ruleKey, (array)$rulesState[$ruleKey]);
        }
    }
}

if ($hasBreach
    && trim((string)(getenv('ALERT_EMAIL_ENABLED') ?: '0')) === '1'
    && (bool)($policy['email']['enabled_default'] ?? false) === true
    && (bool)($policy['throttle']['enabled'] ?? false) === true
    && (bool)($policy['escalation']['email_on_sla_breach'] ?? false)
) {
    foreach ($escalations as $ruleKey) {
        $row = (array)($rulesState[$ruleKey] ?? []);
        if (strtoupper((string)($row['status'] ?? 'OPEN')) === 'ACKNOWLEDGED') {
            continue;
        }
        $subject = '[OPS ALERT][SLA BREACH] ' . $env . ' ' . $ruleKey . ' owner=' . (string)($row['owners']['primary'] ?? 'TBD');
        $body = "rule={$ruleKey}\nseverity=" . (string)($row['severity'] ?? 'ATTENTION') . "\nstatus=" . (string)($row['status'] ?? 'OPEN') .
            "\nowners=" . (string)($row['owners']['primary'] ?? 'TBD') . '/' . (string)($row['owners']['secondary'] ?? 'TBD') .
            "\nack_due=" . (string)($row['sla']['ack_due_at'] ?? '-') . "\nresolve_due=" . (string)($row['sla']['resolve_due_at'] ?? '-') .
            "\nbreached=true\nlink=/tools/ops/alerts.php\n";
        ops_email_send_sla_breach(['subject' => $subject, 'body' => al_mask($body)]);
    }
}

al_write_log_line([
    'ts' => $nowIso,
    'request_id' => $requestId,
    'env' => $env,
    'strict' => $strict,
    'level' => $level,
    'active_rules' => $triggeredKeys,
    'escalations' => $escalations,
    'workflow_corrupt' => (bool)$workflowWrap['corrupt'],
]);
ts_append_run_history('alert_engine_run', 'OK', [
    'module' => 'ALERTS',
    'action' => 'ENGINE',
    'result' => 'OK',
    'request_id' => $requestId,
    'env' => $env,
    'level' => $level,
    'active_rules' => $triggeredKeys,
]);

echo json_encode([
    'ok' => true,
    'request_id' => $requestId,
    'env' => $env,
    'strict' => $strict,
    'level' => $level,
    'active_rules' => $triggeredKeys,
    'banner' => al_mask(al_ops_banner_path()),
    'alerts_last' => al_mask(al_alerts_last_path()),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

@unlink($lockPath);
exit(0);

