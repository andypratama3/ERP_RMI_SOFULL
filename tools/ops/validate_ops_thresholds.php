<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/ops_thresholds_lib.php';

$args = $_SERVER['argv'] ?? [];
$path = ops_thresholds_baseline_path();
$strict = false;
$writeLast = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--strict') {
        $strict = true;
        continue;
    }
    if ($arg === '--write-last') {
        $writeLast = true;
        continue;
    }
    if (str_starts_with($arg, '--path=')) {
        $pathRaw = trim((string)substr($arg, 7));
        if ($pathRaw !== '') {
            $path = str_starts_with($pathRaw, '/')
                ? $pathRaw
                : ops_root() . '/' . ltrim($pathRaw, '/');
        }
    }
}

$read = ops_thresholds_read_file($path);
$ok = (bool)$read['ok'];
$errors = array_values(array_unique(array_map('strval', (array)$read['errors'])));
if (!$ok && $errors === []) {
    $errors[] = ops_mask((string)$read['error']);
}
$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'overall_ok' => $ok,
    'strict' => $strict,
    'path' => ops_mask($path),
    'source_error' => (string)($read['error'] ?? ''),
    'policy_id' => $ok ? (string)($read['policy']['policy_id'] ?? '') : '',
    'fingerprint' => $ok ? (string)($read['fingerprint'] ?? '') : '',
    'errors_masked' => $errors,
    'suggested_commands' => [
        'php tools/ops/validate_ops_thresholds.php --path=docs/governance/OPS_THRESHOLDS.yaml --strict --write-last',
        'php tools/ops/update_ops_thresholds.php --from=docs/governance/OPS_THRESHOLDS.yaml --apply --i-understand --confirm="APPLY_OPS_POLICY" --actor=<username> --write-last',
    ],
];

if ($writeLast) {
    ops_write_json(ops_thresholds_validate_last_json_path(), $payload);
    $md = [];
    $md[] = '# Ops Thresholds Validation';
    $md[] = '';
    $md[] = '- generated_at: ' . ops_mask((string)$payload['generated_at']);
    $md[] = '- overall_ok: ' . ($ok ? 'true' : 'false');
    $md[] = '- strict: ' . ($strict ? 'true' : 'false');
    $md[] = '- path: ' . ops_mask($path);
    if ($ok) {
        $md[] = '- policy_id: ' . ops_mask((string)$payload['policy_id']);
        $md[] = '- fingerprint: ' . ops_mask((string)$payload['fingerprint']);
    } else {
        $md[] = '';
        $md[] = '## Errors';
        foreach ($errors as $e) $md[] = '- ' . ops_mask((string)$e);
    }
    $md[] = '';
    $md[] = '## Commands';
    foreach ((array)$payload['suggested_commands'] as $cmd) $md[] = '- `' . ops_mask((string)$cmd) . '`';
    @file_put_contents(ops_thresholds_validate_last_md_path(), implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($strict) exit($ok ? 0 : 1);
exit(0);

