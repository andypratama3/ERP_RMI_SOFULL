<?php
declare(strict_types=1);
/**
 * CLI: generate nav parallel report → storage/logs/*.json & *.md
 *
 *   php tools/rbac/nav_parallel_sources_report.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/nav_parallel_sources_lib.php';

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$payload = nav_parallel_sources_build($root);
nav_parallel_sources_write_artifacts($root, $payload);

$g = $payload['gaps'];
echo json_encode([
    'ok' => true,
    'json' => 'storage/logs/nav_parallel_report.json',
    'markdown' => 'storage/logs/nav_parallel_report.md',
    'web' => [
        'path_under_project' => 'rbac/nav_parallel_report.php',
        'not_found_hint' => 'Jangan buka /rbac/... di root domain jika ERP di subfolder Web Station; gunakan https://HOST/<FOLDER_PROYEK>/rbac/nav_parallel_report.php (sama seperti RBAC Center).',
    ],
    'counts' => $payload['counts'],
    'gap_sizes' => [
        'registry_not_nav' => count($g['in_page_registry_not_in_sidebar_nav'] ?? []),
        'nav_not_registry' => count($g['in_sidebar_nav_not_in_page_registry'] ?? []),
        'bootstrap_not_nav' => count($g['in_bootstrap_toolbar_not_in_sidebar_nav'] ?? []),
        'mod_card_not_nav' => count($g['in_master_mod_card_not_in_sidebar_nav'] ?? []),
    ],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
