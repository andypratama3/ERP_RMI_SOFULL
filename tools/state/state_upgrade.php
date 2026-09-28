<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';

$args = $_SERVER['argv'] ?? [];
$to = 'v1';
$apply = in_array('--apply', $args, true);
$verifyOnly = in_array('--verify-only', $args, true); // Check-only: pass if state already at v1
$dryRun = in_array('--dry-run', $args, true) || (!$apply && !$verifyOnly);
$iUnderstand = in_array('--i-understand', $args, true);
foreach ($args as $arg) {
    if (str_starts_with((string)$arg, '--to=')) {
        $to = strtolower(substr((string)$arg, 5));
    }
}

if ($to !== 'v1') {
    fwrite(STDERR, "Only --to=v1 is supported.\n");
    exit(1);
}

$root = ts_root();
$logs = ts_storage_logs_dir();
$known = [
    $logs . '/health.last.json',
    $logs . '/preflight_check.last.json',
    $logs . '/readiness_report_last.json',
    $logs . '/smoke_http_last.json',
    $logs . '/cutover_checks.last.json',
    $logs . '/contract_check.last.json',
    $logs . '/contract_check_last.json',
    $logs . '/all_checks.last.json',
];
$diagStates = glob($logs . '/diag_*.last.json') ?: [];
$known = array_values(array_unique(array_merge($known, $diagStates)));

$appEnv = strtolower((string)(getenv('APP_ENV') ?: 'local'));
if ($apply && in_array($appEnv, ['prod', 'production'], true) && !$iUnderstand) {
    fwrite(STDERR, "Blocked in production. Use --i-understand with --apply.\n");
    exit(1);
}

$stamp = date('Ymd_His');
$backupDir = $logs . '/_state_backup/' . $stamp;
$updated = 0;
$skipped = 0;
$invalid = 0;
$messages = [];

foreach ($known as $path) {
    if (!is_file($path)) {
        $skipped++;
        $messages[] = '[SKIP] missing ' . tools_mask_sensitive($path);
        continue;
    }
    $raw = (string)@file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $invalid++;
        $messages[] = '[WARN] invalid_json ' . tools_mask_sensitive($path);
        continue;
    }
    $cur = (int)($decoded['state_version'] ?? 0);
    if ($cur >= 1) {
        $skipped++;
        $messages[] = '[SKIP] already_v1 ' . tools_mask_sensitive($path);
        continue;
    }
    $decoded['state_version'] = 1;
    if (str_ends_with($path, 'readiness_report_last.json') && isset($decoded['breakdown']) && is_array($decoded['breakdown'])) {
        $decoded['health_ok'] = (bool)($decoded['health_ok'] ?? ($decoded['breakdown']['health_ok'] ?? false));
        $decoded['smoke_ok'] = (bool)($decoded['smoke_ok'] ?? ($decoded['breakdown']['smoke_ok'] ?? false));
        $decoded['preflight_ok'] = (bool)($decoded['preflight_ok'] ?? ($decoded['breakdown']['preflight_ok'] ?? false));
    }

    if ($dryRun) {
        $messages[] = '[DRY] upgrade ' . tools_mask_sensitive($path);
        $updated++;
        continue;
    }

    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0775, true);
    }
    @copy($path, $backupDir . '/' . basename($path));
    $ok = @file_put_contents($path, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false;
    if ($ok) {
        $updated++;
        $messages[] = '[OK] upgraded ' . tools_mask_sensitive($path);
    } else {
        $invalid++;
        $messages[] = '[FAIL] write_failed ' . tools_mask_sensitive($path);
    }
}

$summary = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'to_version' => 'v1',
    'apply' => !$dryRun && !$verifyOnly,
    'verify_only' => $verifyOnly,
    'updated_count' => $updated,
    'skipped_count' => $skipped,
    'invalid_count' => $invalid,
    'backup_dir' => tools_mask_sensitive($backupDir),
    // verify-only: ok=true if nothing needs upgrading
    'current_version_ok' => $verifyOnly && $updated === 0 && $invalid === 0,
    'next_action' => ($updated > 0 && !$apply)
        ? 'Run: php tools/state/state_upgrade.php --to=v1 --apply --i-understand (after backup)'
        : 'none_required',
];
ts_write_json($logs . '/state_upgrade.last.json', $summary);

foreach ($messages as $m) {
    echo tools_mask_sensitive($m) . PHP_EOL;
}
echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
// verify-only: exit 0 if already at v1, exit 1 if needs upgrade
if ($verifyOnly) {
    exit($updated > 0 ? 1 : 0);
}
exit($invalid > 0 ? 1 : 0);
