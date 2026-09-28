<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/_lib/rfc_lib.php';

$args = $_SERVER['argv'] ?? [];
$rfc = '';
$status = '';
$actor = '';
$actorRole = 'USER';
$env = 'staging';
$understand = false;
$write = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--i-understand') $understand = true;
    elseif ($arg === '--write') $write = true;
    elseif (str_starts_with($arg, '--rfc=')) $rfc = strtoupper(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--status=')) $status = strtoupper(trim((string)substr($arg, 9)));
    elseif (str_starts_with($arg, '--actor=')) $actor = trim((string)substr($arg, 8));
    elseif (str_starts_with($arg, '--actor-role=')) $actorRole = strtoupper(trim((string)substr($arg, 13)));
    elseif (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
}

$errors = [];
if ($rfc === '') $errors[] = 'rfc_required';
if ($status === '' || !in_array($status, rfc_statuses(), true)) $errors[] = 'invalid_status';
if ($actor === '') $errors[] = 'actor_required';
if (!$understand) $errors[] = 'missing_flag_i_understand';
if ($env === 'production' && !in_array($actorRole, ['ADMIN', 'SUPERADMIN'], true)) $errors[] = 'actor_role_not_allowed_for_production';

$file = rfc_find_file_by_id($rfc);
if ($file === '') $errors[] = 'rfc_not_found';
$fm = [];
$body = '';
if ($file !== '') {
    $parsed = rfc_parse_file($file);
    if (!$parsed['ok']) $errors[] = 'rfc_parse_failed';
    else {
        $fm = (array)$parsed['frontmatter'];
        $body = (string)$parsed['body'];
    }
}
if ($errors === []) {
    $fm['STATUS'] = $status;
    $val = rfc_validate_frontmatter($fm, true);
    if ($val !== []) $errors = array_merge($errors, $val);
}

$ok = ($errors === []);
$requestId = 'rfc-status-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
if ($ok && $write) {
    $content = rfc_render_file($fm, $body);
    if (!rfc_atomic_write($file, $content)) {
        $ok = false;
        $errors[] = 'write_failed';
    } else {
        $evt = [
            'ts' => date(DateTimeInterface::ATOM),
            'actor_username' => $actor,
            'action' => 'RFC_STATUS_CHANGED',
            'rfc_id' => $rfc,
            'status' => $status,
            'request_id' => $requestId,
            'meta_masked' => rfc_mask('env=' . $env . ';actor_role=' . $actorRole),
        ];
        rfc_append_audit($evt);
        rfc_write_core_audit('RFC_STATUS_CHANGED', ['rfc_id' => $rfc, 'status' => $status, 'actor_username' => $actor, 'request_id' => $requestId, 'env' => $env]);
    }
}

$out = [
    'state_version' => 1,
    'overall_ok' => $ok,
    'rfc_id' => $rfc,
    'status' => $status,
    'file' => $file !== '' ? rfc_mask($file) : '',
    'errors_masked' => array_map(static fn(string $e): string => rfc_mask($e), array_values(array_unique($errors))),
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

