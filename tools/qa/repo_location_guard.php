<?php
/**
 * repo_location_guard.php — Enforce canonical root. CRITICAL if APP_ROOT != /volume4/web/ERP_RMI_SOFULL.
 * Artifact scanning is delegated to path_police.php (avoid circular /Volumes/ detection).
 * Usage: php tools/qa/repo_location_guard.php [--write-last] [--strict]
 * Exit: 0=OK 2=FAIL
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$argv      = $_SERVER['argv'] ?? [];
$write     = in_array('--write-last', $argv, true);
$root      = (string)(realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
$canonical = '/volume4/web/ERP_RMI_SOFULL';
$canonicalReal = file_exists($canonical) ? (string)realpath($canonical) : '';
$osFamily  = PHP_OS_FAMILY;
$errors    = [];

// 1. OS check — must be Linux
if ($osFamily === 'Darwin') {
    $errors[] = 'CRITICAL: macOS detected. Run via SSH on NAS at ' . $canonical;
}
if ($osFamily !== 'Linux') {
    $errors[] = 'CRITICAL: OS not Linux (detected: ' . $osFamily . '). Tools must run on NAS.';
}

// 2. Canonical root check — APP_ROOT must match exactly
if ($canonicalReal === '') {
    $errors[] = 'CRITICAL: Canonical root not found: ' . $canonical;
} else {
    if ($root !== $canonicalReal) {
        $errors[] = 'CRITICAL: APP_ROOT mismatch. detected=' . $root . ' expected=' . $canonicalReal;
    }
    if (strpos($root, '/Volumes/') === 0) {
        $errors[] = 'CRITICAL: APP_ROOT is a Mac /Volumes/ mount. Forbidden.';
    }
}

// NOTE: Artifact scanning for /Volumes/ in log files is handled by path_police.php
// This guard only validates runtime environment (OS + APP_ROOT).

$ok = count($errors) === 0;
$result = [
    'state_version'  => 'repo_location_guard_v2',
    'generated_at'   => date('c'),
    'os_family'      => $osFamily,
    'detected_root'  => $root,
    'canonical_root' => $canonical,
    'ok'             => $ok,
    'errors'         => $errors,
    'note'           => 'Artifact /Volumes/ scan delegated to path_police.php',
];

if ($write) {
    @mkdir($root . '/storage/logs', 0775, true);
    file_put_contents(
        $root . '/storage/logs/repo_location_guard_last.json',
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
