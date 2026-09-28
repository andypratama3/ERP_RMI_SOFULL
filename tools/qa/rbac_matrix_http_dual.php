<?php
/**
 * rbac_matrix_http_dual.php — Run rbac_matrix_http_check for INTERNAL + PUBLIC base URLs.
 * Keeps canonical rbac_matrix_http_check_last.json = INTERNAL after run.
 *
 * Artifacts:
 *   rbac_matrix_http_check_internal_last.json
 *   rbac_matrix_http_check_public_last.json
 *
 * Usage: php tools/qa/rbac_matrix_http_dual.php [--strict] [--write-last] [--skip-public]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);
require_once $root . '/tools/_shared/tools_bootstrap.php';
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}

$args = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);
$skipPublic = in_array('--skip-public', $args, true)
    || trim((string)(getenv('RBAC_MATRIX_SKIP_PUBLIC') ?: '')) === '1';

$internalBase = trim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('TOOLS_BASE_URL') ?: getenv('SMOKE_BASE_URL') ?: 'http://10.10.60.20/ERP_RMI_SOFULL'));
$publicBase = trim((string)(getenv('TOOLS_BASE_URL_PUBLIC') ?: getenv('APP_PUBLIC_URL') ?: 'https://erp.rizqullahmediska.com/ERP_RMI_SOFULL'));
foreach ($args as $a) {
    if (is_string($a) && str_starts_with($a, '--internal-base=')) {
        $internalBase = trim(substr($a, strlen('--internal-base=')));
    }
    if (is_string($a) && str_starts_with($a, '--public-base=')) {
        $publicBase = trim(substr($a, strlen('--public-base=')));
    }
}
try {
    $internalBase = normalize_base_url($internalBase);
} catch (Throwable $e) {
    $internalBase = rtrim($internalBase, '/');
}
try {
    $publicBase = normalize_base_url($publicBase);
} catch (Throwable $e) {
    $publicBase = rtrim($publicBase, '/');
}

$phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
$matrixScript = $root . '/tools/qa/rbac_matrix_http_check.php';
$logsDir = $root . '/storage/logs';
$matrixArtifactNames = [
    'rbac_matrix_http_check_last.json',
    'rbac_smoke_matrix_last.json',
    'rbac_action_smoke_matrix_last.json',
    'menu_audit_report_last.json',
];
$backupDir = $logsDir . '/_rbac_internal_matrix_backup';

$run = static function (string $baseUrl, bool $doStrict) use ($phpBin, $matrixScript, $root): array {
    $envPrefix = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl)
        . ' TOOLS_BASE_URL=' . escapeshellarg($baseUrl)
        . ' SMOKE_BASE_URL=' . escapeshellarg($baseUrl)
        . ' APP_URL=' . escapeshellarg($baseUrl) . ' ';
    $flags = '--write-last';
    if ($doStrict) {
        $flags .= ' --strict';
    }
    $cmd = $envPrefix . escapeshellarg($phpBin) . ' ' . escapeshellarg($matrixScript)
        . ' --base-url=' . escapeshellarg($baseUrl) . ' ' . $flags;
    $lines = [];
    $code = 1;
    @exec($cmd . ' 2>&1', $lines, $code);
    $payload = [];
    $jp = $root . '/storage/logs/rbac_matrix_http_check_last.json';
    if (is_file($jp)) {
        $d = json_decode((string)file_get_contents($jp), true);
        if (is_array($d)) {
            $payload = $d;
        }
    }

    return ['payload' => $payload, 'code' => (int)$code, 'output' => implode("\n", $lines)];
};

$backup = static function () use ($logsDir, $backupDir, $matrixArtifactNames): void {
    @mkdir($backupDir, 0775, true);
    foreach ($matrixArtifactNames as $f) {
        $p = $logsDir . '/' . $f;
        if (is_file($p)) {
            @copy($p, $backupDir . '/' . $f);
        }
    }
};
$restore = static function () use ($logsDir, $backupDir, $matrixArtifactNames): void {
    foreach ($matrixArtifactNames as $f) {
        $b = $backupDir . '/' . $f;
        if (is_file($b)) {
            @copy($b, $logsDir . '/' . $f);
        }
    }
};

echo "RBAC matrix HTTP — INTERNAL: {$internalBase}\n";
$internal = $run($internalBase, $strict);
@mkdir($logsDir, 0775, true);
$backup();
@copy($logsDir . '/rbac_matrix_http_check_last.json', $logsDir . '/rbac_matrix_http_check_internal_last.json');

$public = ['payload' => [], 'code' => 0];
if (!$skipPublic) {
    echo "RBAC matrix HTTP — PUBLIC: {$publicBase}\n";
    $public = $run($publicBase, $strict);
    @copy($logsDir . '/rbac_matrix_http_check_last.json', $logsDir . '/rbac_matrix_http_check_public_last.json');
    $restore();
}

$mismatchInt = (int)($internal['payload']['mismatch'] ?? 999);
$mismatchPub = $skipPublic ? 0 : (int)($public['payload']['mismatch'] ?? 999);
$okInt = ($mismatchInt === 0) && ($internal['code'] === 0);
$okPub = $skipPublic ? true : (($mismatchPub === 0) && ($public['code'] === 0));
$overallOk = $okInt && $okPub;

if ($writeLast) {
    @file_put_contents($logsDir . '/rbac_matrix_http_dual_last.json', json_encode([
        'ok' => $overallOk,
        'run_at' => date(DateTimeInterface::ATOM),
        'mismatch_internal' => $mismatchInt,
        'mismatch_public' => $skipPublic ? null : $mismatchPub,
        'layers' => [
            'internal' => ['base_url' => $internalBase, 'ok' => $okInt],
            'public' => $skipPublic ? null : ['base_url' => $publicBase, 'ok' => $okPub],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode([
    'ok' => $overallOk,
    'mismatch_count_internal' => $mismatchInt,
    'mismatch_count_public' => $skipPublic ? null : $mismatchPub,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($overallOk ? 0 : ($strict ? 2 : 1));
