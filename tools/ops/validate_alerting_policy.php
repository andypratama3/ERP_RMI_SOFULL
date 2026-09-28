<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/alert_policy_lib.php';

$args = $_SERVER['argv'] ?? [];
$path = '';
$strict = false;
$writeLast = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--strict') $strict = true;
    elseif ($arg === '--write-last') $writeLast = true;
    elseif (str_starts_with($arg, '--path=')) $path = trim((string)substr($arg, 7));
}
if ($path === '') {
    fwrite(STDERR, "Missing --path\n");
    exit(2);
}
if (!str_starts_with($path, '/')) $path = ap_root() . '/' . ltrim($path, '/');

$res = ap_load_policy_file($path);
$ok = (bool)$res['ok'];
$obj = (array)$res['data'];
$canonical = $ok ? canonicalize_yaml_for_hash($obj) : '';
$fingerprint = $ok ? policy_fingerprint($canonical) : '';

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'path_masked' => ap_mask($path),
    'strict' => $strict,
    'overall_ok' => $ok,
    'errors_masked' => mask_sensitive_in_errors((array)$res['errors']),
    'policy_fingerprint' => $fingerprint,
    'policy_id' => (string)($obj['policy_id'] ?? ''),
];

$pipeDir = ap_root() . '/storage/logs/pipeline';
if (!is_dir($pipeDir)) @mkdir($pipeDir, 0775, true);
$ts = date('YmdHis');
$jsonPath = $pipeDir . '/alert_policy_validate_' . $ts . '.json';
$mdPath = $pipeDir . '/alert_policy_validate_' . $ts . '.md';
@file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$md = [];
$md[] = '# Alert Policy Validate';
$md[] = '';
$md[] = '- path: ' . ap_mask($path);
$md[] = '- strict: ' . ($strict ? 'true' : 'false');
$md[] = '- overall_ok: ' . ($ok ? 'true' : 'false');
$md[] = '- policy_id: ' . ap_mask((string)($payload['policy_id'] ?? ''));
$md[] = '- fingerprint: ' . ap_mask((string)$fingerprint);
if ((array)$payload['errors_masked'] !== []) {
    $md[] = '';
    $md[] = '## Errors';
    foreach ((array)$payload['errors_masked'] as $e) $md[] = '- ' . ap_mask((string)$e);
}
@file_put_contents($mdPath, implode("\n", $md) . "\n");
if ($writeLast) {
    @file_put_contents($pipeDir . '/alert_policy_validate_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    @file_put_contents($pipeDir . '/alert_policy_validate_last.md', implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($strict && !$ok) exit(1);
exit(0);

