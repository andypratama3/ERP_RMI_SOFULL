<?php
declare(strict_types=1);

require_once __DIR__ . '/_lib/ops_helpers.php';
require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("Method Not Allowed\n");
}

$now = new DateTimeImmutable('now');
$isoNow = $now->format(DateTimeInterface::ATOM);
$snapshot = ops_collect_snapshots(1);
$latest = $snapshot[0] ?? null;
if (!$latest) {
    echo json_encode(['ok' => false, 'error' => 'no_snapshot']) . PHP_EOL;
    exit(1);
}

$readiness = (int)($latest['readiness_score'] ?? 0);
$smokeFail = (int)($latest['smoke_fail'] ?? 0);
$contractOk = (bool)($latest['contract_ok'] ?? false);
$backupAge = isset($latest['backup_age_hours']) && $latest['backup_age_hours'] !== null ? (float)$latest['backup_age_hours'] : null;
$allChecksOk = $latest['all_checks_overall_ok'] ?? null;

$alerts = [];
$addAlert = static function (string $code, string $severity, string $title, string $details) use (&$alerts): void {
    $alerts[$code] = [
        'code' => $code,
        'severity' => $severity,
        'title' => $title,
        'details_masked' => ops_mask($details),
        'active' => true,
    ];
};

if ($contractOk === false) {
    $addAlert('contract_fail', 'CRITICAL', 'Contract check failed', 'contract_ok=false on latest snapshot');
}
if ($smokeFail > 0) {
    $addAlert('smoke_fail', 'CRITICAL', 'Smoke checks failing', 'smoke_fail=' . $smokeFail);
}
if ($backupAge !== null && $backupAge > 72.0) {
    $addAlert('backup_stale_72h', 'CRITICAL', 'Backup age above 72h', 'backup_age_hours=' . $backupAge);
}
if ($allChecksOk === false) {
    $addAlert('all_checks_fail', 'CRITICAL', 'All checks overall failed', 'all_checks_overall_ok=false');
}
if ($readiness < 100) {
    $addAlert('readiness_below_100', 'HIGH', 'Readiness below 100', 'readiness_score=' . $readiness);
}
if ($backupAge !== null && $backupAge > 24.0 && $backupAge <= 72.0) {
    $addAlert('backup_stale_24h', 'HIGH', 'Backup age above 24h', 'backup_age_hours=' . $backupAge);
}

$readinessArtifact = ops_read_json_safe(ops_state_path('readiness_report_last.json'));
$smokeArtifact = ops_read_json_safe(ops_state_path('smoke_http_last.json'));
if (!$readinessArtifact['ok'] || !$smokeArtifact['ok']) {
    $addAlert('ops_artifact_integrity', 'MEDIUM', 'Required artifact invalid', 'readiness/smoke state invalid');
}

$last3 = ops_collect_snapshots(3);
if (count($last3) === 3) {
    $r0 = (int)$last3[0]['readiness_score'];
    $r1 = (int)$last3[1]['readiness_score'];
    $r2 = (int)$last3[2]['readiness_score'];
    if ($r0 > $r1 && $r1 > $r2) {
        $addAlert('readiness_3day_downtrend', 'LOW', 'Readiness downtrend 3 days', 'trend=' . $r0 . '>' . $r1 . '>' . $r2);
    }
}

$prev = ops_read_json_safe(ops_state_path('ops_alerts_last.json'));
$prevMap = [];
if ($prev['ok']) {
    foreach ((array)($prev['data']['alerts'] ?? []) as $a) {
        $code = (string)($a['code'] ?? '');
        if ($code === '') continue;
        $prevMap[$code] = $a;
    }
}

$throttle = ops_read_json_safe(ops_state_path('ops_alert_throttle.json'));
$throttleData = $throttle['ok'] ? (array)$throttle['data'] : ['state_version' => 1, 'last_notified_at' => []];
$lastNotified = (array)($throttleData['last_notified_at'] ?? []);

$resultAlerts = [];
$activeCounts = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];

foreach ($alerts as $code => $alert) {
    $sev = strtoupper((string)$alert['severity']);
    $activeCounts[$sev] = ($activeCounts[$sev] ?? 0) + 1;
    $prevA = $prevMap[$code] ?? [];
    $firstSeen = (string)($prevA['first_seen'] ?? $isoNow);
    $lastSeen = $isoNow;
    $interval = 86400;
    if ($sev === 'CRITICAL') $interval = 3600;
    if ($sev === 'HIGH') $interval = 21600;
    $lastN = (string)($lastNotified[$code] ?? '');
    $shouldNotify = true;
    if ($lastN !== '') {
        $delta = strtotime($isoNow) - strtotime($lastN);
        if ($delta >= 0 && $delta < $interval) $shouldNotify = false;
    }
    if ($shouldNotify) {
        $lastNotified[$code] = $isoNow;
    }
    $resultAlerts[] = array_merge($alert, [
        'first_seen' => $firstSeen,
        'last_seen' => $lastSeen,
        'notify' => $shouldNotify,
    ]);
}

foreach ($prevMap as $code => $prevA) {
    if (isset($alerts[$code])) continue;
    $resultAlerts[] = [
        'code' => $code,
        'severity' => (string)($prevA['severity'] ?? 'LOW'),
        'title' => (string)($prevA['title'] ?? $code),
        'details_masked' => (string)($prevA['details_masked'] ?? ''),
        'first_seen' => (string)($prevA['first_seen'] ?? $isoNow),
        'last_seen' => $isoNow,
        'active' => false,
        'notify' => false,
    ];
}

$alertsPayload = [
    'state_version' => 1,
    'ts' => $isoNow,
    'alerts' => $resultAlerts,
    'active_counts' => $activeCounts,
];
$alertsContract = ops_contract_check_types($alertsPayload, [
    'state_version' => 'int',
    'ts' => 'string',
    'alerts' => 'array',
    'active_counts' => 'array',
]);
if (!$alertsContract['ok']) {
    fwrite(STDERR, "alerts contract mismatch\n");
    exit(2);
}
ops_write_json(ops_state_path('ops_alerts_last.json'), $alertsPayload);
ops_write_json(ops_state_path('alerts_last.json'), $alertsPayload);
ops_write_json(ops_state_path('ops_alert_throttle.json'), [
    'state_version' => 1,
    'ts' => $isoNow,
    'last_notified_at' => $lastNotified,
]);

$slaMap = ops_load_sla_owners();
$findingsPath = ops_state_path('ops_findings.json');
$findingsFile = ops_read_json_safe($findingsPath);
$findings = $findingsFile['ok'] ? (array)($findingsFile['data']['findings'] ?? []) : [];

$indexByCode = [];
foreach ($findings as $idx => $f) {
    $status = strtoupper((string)($f['status'] ?? 'OPEN'));
    if (!in_array($status, ['OPEN', 'IN_PROGRESS'], true)) continue;
    $code = (string)($f['code'] ?? '');
    if ($code === '') continue;
    $indexByCode[$code] = $idx;
}

$findingEvents = [];
foreach ($resultAlerts as $a) {
    if (!($a['active'] ?? false)) continue;
    $code = (string)$a['code'];
    if (isset($indexByCode[$code])) {
        $idx = $indexByCode[$code];
        $findings[$idx]['updated_at'] = $isoNow;
        $findingEvents[] = ['event' => 'ops_finding_updated', 'code' => $code];
        continue;
    }
    $sla = (array)($slaMap[$code] ?? []);
    $newFinding = [
        'id' => 'OPSF-' . date('YmdHis') . '-' . strtoupper(substr(sha1($code . $isoNow), 0, 6)),
        'code' => $code,
        'severity' => strtoupper((string)($a['severity'] ?? 'MEDIUM')),
        'title' => (string)($a['title'] ?? $code),
        'owner' => (string)($sla['owner'] ?? 'OPS'),
        'sla_minutes' => (int)($sla['sla_minutes'] ?? 240),
        'status' => 'OPEN',
        'opened_at' => $isoNow,
        'updated_at' => $isoNow,
        'resolved_at' => null,
        'notes_masked' => [ops_mask('auto-opened by evaluate_alerts')],
    ];
    $findings[] = $newFinding;
    $findingEvents[] = ['event' => 'ops_finding_opened', 'code' => $code, 'id' => $newFinding['id']];
}

ops_write_json($findingsPath, [
    'state_version' => 1,
    'updated_at' => $isoNow,
    'findings' => $findings,
]);

ts_append_run_history('ops_alert_evaluation_run', 'ok', [
    'at' => $isoNow,
    'active_counts' => $activeCounts,
    'changes' => $findingEvents,
]);
foreach ($findingEvents as $ev) {
    $evName = (string)($ev['event'] ?? 'ops_finding_updated');
    ts_append_run_history($evName, 'ok', array_merge($ev, ['at' => $isoNow]));
}
try {
    $pdo = rmi_db_pdo();
    erp_audit_ensure($pdo);
    erp_audit($pdo, 'OPS', 'ALERTS', 'OPS_ALERT_EVALUATED', ['active_counts' => $activeCounts]);
} catch (Throwable $e) {
}

$emailEnabled = (string)getenv('ENABLE_EMAIL_ALERTS') === '1';
if ($emailEnabled) {
    ts_append_run_history('ops_alert_notify_email_skipped', 'noop', [
        'at' => $isoNow,
        'detail' => 'email integration not configured in this pack; state is throttled and ready',
    ]);
}

echo json_encode([
    'ok' => true,
    'active_counts' => $activeCounts,
    'findings_changes' => $findingEvents,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
