<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../rfc/_lib/rfc_lib.php';
require_once __DIR__ . '/../../_shared/bootstrap.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';

$args = $_SERVER['argv'] ?? [];
$apply = false;
$rfc = '';
foreach (array_slice($args, 1) as $arg) {
    if (!is_string($arg) || $arg === '') continue;
    if ($arg === '--apply') $apply = true;
    if (str_starts_with($arg, '--rfc=')) $rfc = strtoupper(trim((string)substr($arg, 6)));
}

$env = defined('APP_ENV') ? strtolower((string)APP_ENV) : 'staging';
$errors = [];
if ($apply && $env === 'production') {
    $gate = rfc_gate_check('RESTORE_APPLY', 'production', $rfc, true);
    if (!$gate['ok']) {
        $errors[] = 'rfc_gate_failed';
        $errors = array_merge($errors, (array)$gate['errors']);
    }
}

$ok = ($errors === []);
$requestId = 'restore-gate-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
if ($ok && $apply && function_exists('auth_pdo') && function_exists('audit_event')) {
    rfc_append_audit([
        'ts' => date(DateTimeInterface::ATOM),
        'actor_username' => 'system',
        'action' => 'RFC_USED_FOR_CHANGE',
        'rfc_id' => $rfc,
        'request_id' => $requestId,
        'meta_masked' => rfc_mask('change_type=RESTORE_APPLY;script=tools/backup/restore.php'),
    ]);
    $pdo = auth_pdo();
    if ($pdo instanceof PDO) {
        audit_event($pdo, 'RFC_USED_FOR_CHANGE', 'OPS', 'restore_apply', 'restore', 'RFC used for restore apply', [
            'rfc_id' => $rfc,
            'change_type' => 'RESTORE_APPLY',
            'script_name' => 'tools/backup/restore.php',
            'request_id' => $requestId,
        ]);
    }
}

$out = [
    'state_version' => 1,
    'overall_ok' => $ok,
    'env' => $env,
    'apply' => $apply,
    'rfc_id' => $rfc,
    'errors_masked' => array_map(static fn(string $e): string => rfc_mask((string)$e), array_values(array_unique($errors))),
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

