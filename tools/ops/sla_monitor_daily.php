<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';

tools_require_access('ops/sla_monitor_daily.php');

$hist = APP_ROOT . '/storage/logs/erp_hardening_triage_history.jsonl';
$lines = is_file($hist) ? (@file($hist, FILE_IGNORE_NEW_LINES) ?: []) : [];
$breach = false;
$breachSince = null;
$openCritical = 0;

foreach ($lines as $line) {
    $row = json_decode((string)$line, true);
    if (!is_array($row)) continue;
    $p0 = (int)($row['summary']['p0'] ?? 0);
    $ts = strtotime((string)($row['generated_at'] ?? ''));
    if ($p0 > 0) {
        $openCritical = $p0;
        if ($ts !== false && (time() - $ts) > 86400) {
            $breach = true;
            $breachSince = date(DateTimeInterface::ATOM, $ts);
            break;
        }
    }
}

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'sla' => [
        'target' => 'P0 must be 0 within 24h',
        'breach' => $breach,
        'breach_since' => $breachSince,
    ],
    'open_critical_count' => $openCritical,
    'owner_matrix' => [
        ['owner' => 'SEC', 'target_hours' => 24, 'open' => $openCritical],
        ['owner' => 'ENG', 'target_hours' => 24, 'open' => 0],
        ['owner' => 'OPS', 'target_hours' => 24, 'open' => 0],
    ],
];
tools_json_write_atomic(APP_ROOT . '/storage/logs/sla_monitor_last.json', $payload);
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
