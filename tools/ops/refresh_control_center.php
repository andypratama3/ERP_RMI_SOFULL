<?php
/**
 * refresh_control_center.php — Refresh state files untuk Ops Control Center.
 *
 * Menjalankan: release_gate, smoke_http, verify_sop_links, evidence stub.
 * Wajib di NAS. Output JSON untuk AJAX.
 */
declare(strict_types=1);

@set_time_limit(180);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_access_helpers.php';
if (file_exists(__DIR__ . '/../tools_ui_helpers.php')) {
    require_once __DIR__ . '/../tools_ui_helpers.php';
}

tools_require_access('ops/control_center.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__, 2);
if (strpos(realpath($root) ?: $root, '/Volumes/') !== false) {
    echo json_encode(['ok' => false, 'error' => 'Refresh harus dijalankan di NAS. SSH ke NAS lalu: php tools/ops/refresh_control_center.php'], JSON_UNESCAPED_SLASHES);
    exit;
}
$phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php');
$baseUrl = rtrim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('APP_URL') ?: 'http://10.10.60.20/ERP_RMI_SOFULL'), '/');
$envPrefix = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl) . ' APP_URL=' . escapeshellarg($baseUrl) . ' SMOKE_BASE_URL=' . escapeshellarg($baseUrl) . ' ';

$results = [];
$overallOk = true;

$steps = [
    ['name' => 'release_gate', 'cmd' => escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/release/release_gate.php')],
    ['name' => 'smoke_http', 'cmd' => $envPrefix . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/smoke_http.php') . ' --strict --write-last'],
    ['name' => 'sop_link_verify', 'cmd' => escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/dev/verify_sop_links.php')],
    ['name' => 'evidence_pack', 'cmd' => escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/compliance/verify_evidence_pack.php')],
];

foreach ($steps as $step) {
    $out = [];
    $code = 1;
    @exec((string)$step['cmd'] . ' 2>&1', $out, $code);
    $ok = ((int)$code === 0);
    if (!$ok) {
        $overallOk = false;
    }
    $results[$step['name']] = ['ok' => $ok, 'exit' => $code];
}

$evidencePath = $root . '/storage/logs/evidence_status_last.json';
if (!is_file($evidencePath)) {
    $stub = [
        'state_version' => 1,
        'checked_at' => date(DateTimeInterface::ATOM),
        'OK_packs' => 0,
        'WARN_packs' => 0,
        'FAIL_packs' => 0,
        'note' => 'Stub from refresh_control_center (verify_evidence_pack failed or not run)',
    ];
    $dir = dirname($evidencePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($evidencePath, json_encode($stub, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

echo json_encode([
    'ok' => $overallOk,
    'run_at' => date(DateTimeInterface::ATOM),
    'results' => $results,
], JSON_UNESCAPED_SLASHES);
