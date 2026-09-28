<?php
/**
 * Cutover gate: Business Sign-off check.
 * Exit 0 if signed, sha256 present, evidence file exists.
 * Controlled by CUTOVER_REQUIRE_BUSINESS_SIGNOFF (default 1 in production).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';

$require = (int)(getenv('CUTOVER_REQUIRE_BUSINESS_SIGNOFF') ?: 1);
if ($require === 0) {
    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'reason' => 'CUTOVER_REQUIRE_BUSINESS_SIGNOFF=0',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$state = business_signoff_read_state();
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$signed = (bool)($data['signed'] ?? false);
$sha256 = trim((string)($data['sha256'] ?? ''));
$evidenceMasked = trim((string)($data['evidence_file'] ?? $data['file_path_masked'] ?? ''));

$evidenceExists = false;
if ($evidenceMasked !== '') {
    $root = ts_root();
    $actualPath = str_replace('[APP_ROOT]', $root, $evidenceMasked);
    $evidenceExists = is_file($actualPath);
}

$ok = $state['ok'] && $signed && $sha256 !== '' && $evidenceExists;

$payload = [
    'state_version' => 1,
    'ok' => $ok,
    'signed' => $signed,
    'sha256_present' => $sha256 !== '',
    'evidence_exists' => $evidenceExists,
    'state_ok' => $state['ok'],
    'checked_at' => date(DateTimeInterface::ATOM),
];

$args = $_SERVER['argv'] ?? [];
if (in_array('--write-last', $args, true)) {
    ts_write_json(ts_storage_logs_dir() . '/business_signoff_check.last.json', $payload);
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
