<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/_lib/rfc_lib.php';

$args = $_SERVER['argv'] ?? [];
$type = 'PRODUCTION_DEPLOY';
$title = '';
$env = 'staging';
$severity = 'MED';
$actor = '';
$write = false;
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--write') { $write = true; continue; }
    if (str_starts_with($arg, '--type=')) $type = strtoupper(trim((string)substr($arg, 7)));
    elseif (str_starts_with($arg, '--title=')) $title = trim((string)substr($arg, 8));
    elseif (str_starts_with($arg, '--env=')) $env = strtolower(trim((string)substr($arg, 6)));
    elseif (str_starts_with($arg, '--severity=')) $severity = strtoupper(trim((string)substr($arg, 11)));
    elseif (str_starts_with($arg, '--actor=')) $actor = trim((string)substr($arg, 8));
}

$errors = [];
if ($title === '') $errors[] = 'title_required';
if ($actor === '') $errors[] = 'actor_required';
if (!in_array($env, ['staging', 'production'], true)) $errors[] = 'invalid_env';
if (!in_array($severity, ['HIGH', 'MED', 'LOW'], true)) $errors[] = 'invalid_severity';
if (!in_array($type, array_merge(rfc_required_types(), rfc_optional_types()), true)) $errors[] = 'invalid_type';

$year = (int)date('Y');
$idMeta = rfc_next_id($year);
$rfcId = (string)$idMeta['id'];
$slug = rfc_slug($title);
$path = rfc_dir() . '/' . $rfcId . '-' . $slug . '.md';
$fm = rfc_frontmatter_defaults();
$fm['RFC_ID'] = $rfcId;
$fm['TITLE'] = $title;
$fm['TYPE'] = $type;
$fm['STATUS'] = 'DRAFT';
$fm['SEVERITY'] = $severity;
$fm['CREATED_AT'] = date(DateTimeInterface::ATOM);
$fm['CREATED_BY'] = $actor;
$fm['TARGET_ENV'] = $env;
$fm['SCHEDULE'] = date('Y-m-d H:i T');
$fm['AFFECTED_AREAS'] = ['OPS'];
$fm['REQUIRES_APPROVALS'] = rfc_approval_matrix()[$type] ?? ['ENG_LEAD', 'QA'];
$fm['APPROVALS'] = [];

$templatePath = rfc_dir() . '/TEMPLATE_RFC.md';
$body = is_file($templatePath) ? (string)@file_get_contents($templatePath) : "# RFC Body\n\nFill sections.\n";
if ($write && $errors === []) {
    $content = rfc_render_file($fm, $body);
    if (!rfc_atomic_write($path, $content)) $errors[] = 'write_failed';
}

$ok = ($errors === []);
$requestId = 'rfc-create-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
if ($ok && $write) {
    $evt = [
        'ts' => date(DateTimeInterface::ATOM),
        'actor_username' => $actor,
        'action' => 'RFC_CREATED',
        'rfc_id' => $rfcId,
        'request_id' => $requestId,
        'meta_masked' => rfc_mask('type=' . $type . ';env=' . $env),
    ];
    rfc_append_audit($evt);
    rfc_write_core_audit('RFC_CREATED', ['rfc_id' => $rfcId, 'actor_username' => $actor, 'type' => $type, 'target_env' => $env, 'request_id' => $requestId]);
}

$out = [
    'state_version' => 1,
    'overall_ok' => $ok,
    'rfc_id' => $rfcId,
    'path' => rfc_mask($path),
    'errors_masked' => array_map(static fn(string $e): string => rfc_mask($e), $errors),
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

