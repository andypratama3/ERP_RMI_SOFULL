<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/_lib/rfc_lib.php';

$args = $_SERVER['argv'] ?? [];
$rfc = '';
$strict = false;
$writeLast = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--strict') $strict = true;
    elseif ($arg === '--write-last') $writeLast = true;
    elseif (str_starts_with($arg, '--rfc=')) $rfc = strtoupper(trim((string)substr($arg, 6)));
}

$errors = [];
$file = '';
$fm = [];
if ($rfc === '') {
    $errors[] = 'rfc_required';
} else {
    $file = rfc_find_file_by_id($rfc);
    if ($file === '') {
        $errors[] = 'rfc_not_found';
    } else {
        $parsed = rfc_parse_file($file);
        if (!$parsed['ok']) {
            $errors[] = 'rfc_parse_failed:' . (string)$parsed['error'];
        } else {
            $fm = (array)$parsed['frontmatter'];
            $errors = array_merge($errors, rfc_validate_frontmatter($fm, true));
        }
    }
}
$ok = ($errors === []);

$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'overall_ok' => $ok,
    'strict' => $strict,
    'rfc_id' => $rfc,
    'rfc_file' => $file !== '' ? rfc_mask($file) : '',
    'status' => (string)($fm['STATUS'] ?? ''),
    'requires_approvals' => (array)($fm['REQUIRES_APPROVALS'] ?? []),
    'approvals_count' => count((array)($fm['APPROVALS'] ?? [])),
    'errors_masked' => array_map(static fn(string $e): string => rfc_mask($e), array_values(array_unique($errors))),
];

if ($writeLast) {
    ts_write_json(rfc_pipeline_dir() . '/rfc_validate_last.json', $payload);
    $md = [];
    $md[] = '# RFC Validate';
    $md[] = '';
    $md[] = '- generated_at: ' . rfc_mask((string)$payload['generated_at']);
    $md[] = '- overall_ok: ' . ($ok ? 'true' : 'false');
    $md[] = '- strict: ' . ($strict ? 'true' : 'false');
    $md[] = '- rfc_id: ' . rfc_mask($rfc);
    $md[] = '- rfc_file: ' . rfc_mask((string)$payload['rfc_file']);
    $md[] = '- status: ' . rfc_mask((string)$payload['status']);
    if ((array)$payload['errors_masked'] !== []) {
        $md[] = '';
        $md[] = '## Errors';
        foreach ((array)$payload['errors_masked'] as $e) $md[] = '- ' . rfc_mask((string)$e);
    }
    @file_put_contents(rfc_pipeline_dir() . '/rfc_validate_last.md', implode("\n", $md) . "\n");
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($strict) exit($ok ? 0 : 1);
exit(0);

