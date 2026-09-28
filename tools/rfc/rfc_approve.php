<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/_lib/rfc_lib.php';

$args = $_SERVER['argv'] ?? [];
$rfc = '';
$role = '';
$actor = '';
$note = '';
$understand = false;
$write = false;
$actorRole = 'USER';
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--i-understand') $understand = true;
    elseif ($arg === '--write') $write = true;
    elseif (str_starts_with($arg, '--rfc=')) $rfc = strtoupper(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--role=')) $role = strtoupper(trim((string)substr($arg, 7)));
    elseif (str_starts_with($arg, '--actor=')) $actor = trim((string)substr($arg, 8));
    elseif (str_starts_with($arg, '--note=')) $note = trim((string)substr($arg, 7));
    elseif (str_starts_with($arg, '--actor-role=')) $actorRole = strtoupper(trim((string)substr($arg, 13)));
}

$errors = [];
if ($rfc === '') $errors[] = 'rfc_required';
if ($role === '') $errors[] = 'role_required';
if ($actor === '') $errors[] = 'actor_required';
if (!$understand) $errors[] = 'missing_flag_i_understand';

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

$approvals = (array)($fm['APPROVALS'] ?? []);
$creator = strtolower((string)($fm['CREATED_BY'] ?? ''));
if ($creator !== '' && strtolower($actor) === $creator && $actorRole !== 'SUPERADMIN') {
    $errors[] = 'maker_checker_violation';
}
foreach ($approvals as $a) {
    if (strtoupper((string)($a['ROLE'] ?? '')) === $role) {
        $errors[] = 'approval_role_already_exists';
        break;
    }
}

if ($errors === []) {
    $approvals[] = [
        'ROLE' => $role,
        'USERNAME' => $actor,
        'APPROVED_AT' => date(DateTimeInterface::ATOM),
        'NOTE' => $note === '' ? 'approved' : $note,
    ];
    $fm['APPROVALS'] = $approvals;
    $required = (array)($fm['REQUIRES_APPROVALS'] ?? []);
    $approvedRoles = array_map(static fn(array $a): string => strtoupper((string)($a['ROLE'] ?? '')), $approvals);
    $all = true;
    foreach ($required as $r) {
        if (!in_array(strtoupper((string)$r), $approvedRoles, true)) {
            $all = false;
            break;
        }
    }
    $fm['STATUS'] = $all ? 'APPROVED' : 'IN_REVIEW';
    $val = rfc_validate_frontmatter($fm, true);
    if ($val !== []) $errors = array_merge($errors, $val);
}

$ok = ($errors === []);
$requestId = 'rfc-approve-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
if ($ok && $write) {
    $content = rfc_render_file($fm, $body);
    if (!rfc_atomic_write($file, $content)) {
        $ok = false;
        $errors[] = 'write_failed';
    } else {
        rfc_append_audit([
            'ts' => date(DateTimeInterface::ATOM),
            'actor_username' => $actor,
            'action' => 'RFC_APPROVED',
            'rfc_id' => $rfc,
            'role' => $role,
            'request_id' => $requestId,
            'meta_masked' => rfc_mask($note),
        ]);
        rfc_write_core_audit('RFC_APPROVED', ['rfc_id' => $rfc, 'role' => $role, 'actor_username' => $actor, 'request_id' => $requestId]);
    }
}

$out = [
    'state_version' => 1,
    'overall_ok' => $ok,
    'rfc_id' => $rfc,
    'status' => (string)($fm['STATUS'] ?? ''),
    'file' => $file !== '' ? rfc_mask($file) : '',
    'errors_masked' => array_map(static fn(string $e): string => rfc_mask($e), array_values(array_unique($errors))),
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

