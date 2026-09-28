<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';

tools_require_access('hardening/freeze_rules_v1.php');

$rulesPath = APP_ROOT . '/tools/hardening/policies/triage_rules_v1.json';
$rules = tools_json_read_safe($rulesPath);
$history = @file(APP_ROOT . '/storage/logs/erp_hardening_triage_history.jsonl', FILE_IGNORE_NEW_LINES) ?: [];

$byDate = [];
foreach ($history as $line) {
    $row = json_decode((string)$line, true);
    if (!is_array($row)) continue;
    $date = substr((string)($row['generated_at'] ?? ''), 0, 10);
    if ($date === '') continue;
    $byDate[$date] = [
        'p0' => (int)($row['summary']['p0'] ?? 0),
        'signature' => (string)($row['signature'] ?? ''),
    ];
}
krsort($byDate);
$last3 = array_slice(array_values($byDate), 0, 3);
$eligible = count($last3) === 3;
if ($eligible) {
    $sig = $last3[0]['signature'];
    foreach ($last3 as $d) {
        if ((int)$d['p0'] !== 0 || (string)$d['signature'] !== $sig) {
            $eligible = false;
            break;
        }
    }
}

$result = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'eligible' => $eligible,
    'reason' => $eligible ? 'criteria met for 3 consecutive days' : 'criteria not met',
];
if ($eligible) {
    $rules['frozen'] = true;
    $rules['frozen_at'] = date(DateTimeInterface::ATOM);
    tools_json_write_atomic($rulesPath, $rules);
}
tools_json_write_atomic(APP_ROOT . '/storage/logs/triage_rules_freeze_last.json', $result);
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
