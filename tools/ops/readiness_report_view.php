<?php
/**
 * Serve readiness report (JSON/MD) with auth. Storage is blocked by .htaccess.
 * ?format=json | format=md
 */
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/../tools_state_lib.php';

tools_require_access('ops/readiness_report_view.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

$format = strtolower(trim((string)($_GET['format'] ?? 'json')));
if (!in_array($format, ['json', 'md'], true)) $format = 'json';

$ext = $format === 'json' ? '.json' : '.md';
$path = ts_storage_logs_dir() . '/readiness_report_last' . $ext;

if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n\nFile unreadable or missing.\n";
    exit;
}

$content = (string)@file_get_contents($path);
if ($content === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n\nFile empty.\n";
    exit;
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo $content;
    exit;
}

header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: inline; filename="readiness_report_last.md"');
echo $content;
