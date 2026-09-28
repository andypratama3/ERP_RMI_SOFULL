<?php
/**
 * tools/qa/rbac_smoke_matrix.php
 * Reads rbac_smoke_matrix_last.json (from rbac_matrix_http_check) or runs it if missing.
 * Output: storage/logs/rbac_smoke_matrix_last.csv
 * FAIL if mismatch_count>0
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
if (function_exists('rmi_env_load')) rmi_env_load();

$jsonPath = $root . '/storage/logs/rbac_smoke_matrix_last.json';

// If JSON missing, run rbac_matrix_http_check first
if (!is_file($jsonPath)) {
    $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $baseUrl = function_exists('tools_base_url') ? tools_base_url() : (string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('SMOKE_BASE_URL') ?: 'https://localhost/ERP_RMI_SOFULL');
    try {
        $baseUrl = normalize_base_url($baseUrl);
    } catch (Throwable $e) {
        $baseUrl = rtrim($baseUrl, '/');
    }
    $envPrefix = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl) . ' TOOLS_BASE_URL=' . escapeshellarg($baseUrl) . ' SMOKE_BASE_URL=' . escapeshellarg($baseUrl) . ' ';
    $cmd = $envPrefix . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/rbac_matrix_http_check.php') . ' --strict --write-last';
    exec($cmd . ' 2>&1', $_, $code);
    if ((int)$code !== 0) {
        echo json_encode(['ok' => false, 'mismatch_count' => 999, 'error' => 'rbac_matrix_http_check failed']);
        exit(2);
    }
}

$writeLast = in_array('--write-last', $_SERVER['argv'] ?? [], true);
$strict = in_array('--strict', $_SERVER['argv'] ?? [], true);
$csvPath = $root . '/storage/logs/rbac_smoke_matrix_last.csv';

$mismatch = 0;
if (is_file($jsonPath)) {
    $data = json_decode((string)file_get_contents($jsonPath), true);
    foreach ($data['results'] ?? [] as $r) {
        if (!($r['guest_test']['ok'] ?? true)) $mismatch++;
        foreach ($r['role_tests'] ?? [] as $t) {
            if (!($t['ok'] ?? true)) $mismatch++;
        }
    }
    if ($writeLast && isset($data['results'])) {
        $fp = fopen($csvPath, 'w');
        if ($fp) {
            fputcsv($fp, ['id', 'label', 'path', 'guest_ok', 'role_fail_count'], ',', '"', '\\');
            foreach ($data['results'] as $r) {
                $roleFail = count(array_filter($r['role_tests'] ?? [], fn($t) => !($t['ok'] ?? true)));
                fputcsv($fp, [
                    $r['id'] ?? '',
                    $r['label'] ?? '',
                    $r['path'] ?? '',
                    ($r['guest_test']['ok'] ?? false) ? '1' : '0',
                    $roleFail,
                ], ',', '"', '\\');
            }
            fclose($fp);
        }
    }
}

$ok = ($mismatch === 0);
// Alias artifact for cutover gate (UI matrix = same dataset as rbac_smoke_matrix_last.json).
if (is_file($jsonPath)) {
    @copy($jsonPath, $root . '/storage/logs/rbac_smoke_matrix_ui_last.json');
}
echo json_encode(['ok' => $ok, 'mismatch_count' => $mismatch], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : ($strict ? 2 : 1));
