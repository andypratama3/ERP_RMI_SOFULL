<?php
declare(strict_types=1);

/**
 * Assert storage/logs artifact exists (for cutover step aliases without re-running heavy checks).
 *
 * Usage: php tools/qa/rbac_assert_artifact_exists.php storage/logs/rbac_smoke_matrix_ui_last.json
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$rel = (string)($_SERVER['argv'][1] ?? '');
if ($rel === '') {
    fwrite(STDERR, "Usage: php rbac_assert_artifact_exists.php <path-relative-to-project>\n");
    exit(2);
}
$path = $root . '/' . ltrim(str_replace('\\', '/', $rel), '/');
if (!is_file($path)) {
    fwrite(STDERR, "MISSING: {$rel}\n");
    exit(1);
}
echo json_encode(['ok' => true, 'path' => $rel], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
