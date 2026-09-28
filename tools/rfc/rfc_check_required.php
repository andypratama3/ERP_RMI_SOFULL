<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/_lib/rfc_lib.php';

$args = $_SERVER['argv'] ?? [];
$type = '';
$env = 'staging';
$rfc = '';
$strict = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--strict') $strict = true;
    elseif (str_starts_with($arg, '--type=')) $type = strtoupper(trim((string)substr($arg, 7)));
    elseif (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--rfc=')) $rfc = strtoupper(trim((string)substr($arg, 6)));
}

if ($type === '') $type = 'PRODUCTION_DEPLOY';
$result = rfc_gate_check($type, $env, $rfc, $strict);
$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'overall_ok' => (bool)$result['ok'],
    'status' => (string)$result['status'],
    'required' => (bool)$result['required'],
    'type' => (string)$result['type'],
    'env' => (string)$result['env'],
    'rfc_id' => (string)$result['rfc_id'],
    'rfc_file' => (string)$result['rfc_file_masked'],
    'rfc_status' => (string)$result['rfc_status'],
    'errors_masked' => (array)$result['errors'],
    'notes' => (array)$result['notes'],
];
ts_write_json(rfc_pipeline_dir() . '/rfc_gate_last.json', $payload);
$md = [];
$md[] = '# RFC Gate Check';
$md[] = '';
$md[] = '- generated_at: ' . rfc_mask((string)$payload['generated_at']);
$md[] = '- overall_ok: ' . ($payload['overall_ok'] ? 'true' : 'false');
$md[] = '- status: ' . rfc_mask((string)$payload['status']);
$md[] = '- required: ' . ($payload['required'] ? 'true' : 'false');
$md[] = '- type/env: ' . rfc_mask((string)$payload['type']) . ' / ' . rfc_mask((string)$payload['env']);
$md[] = '- rfc_id: ' . rfc_mask((string)$payload['rfc_id']);
$md[] = '- rfc_status: ' . rfc_mask((string)$payload['rfc_status']);
if ((array)$payload['errors_masked'] !== []) {
    $md[] = '';
    $md[] = '## Errors';
    foreach ((array)$payload['errors_masked'] as $e) $md[] = '- ' . rfc_mask((string)$e);
}
if ((array)$payload['notes'] !== []) {
    $md[] = '';
    $md[] = '## Notes';
    foreach ((array)$payload['notes'] as $n) $md[] = '- ' . rfc_mask((string)$n);
}
@file_put_contents(rfc_pipeline_dir() . '/rfc_gate_last.md', implode("\n", $md) . "\n");

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

