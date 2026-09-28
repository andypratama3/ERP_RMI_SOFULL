<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/_lib/safe_viewer_lib.php';

tools_require_access('ops/executive_summary_view.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

$mode = strtolower(trim((string)($_GET['mode'] ?? 'html')));
if (!in_array($mode, ['html', 'json'], true)) $mode = 'html';

$fileVariant = strtolower(trim((string)($_GET['file'] ?? 'ultimate')));
if (!in_array($fileVariant, ['last', 'ultimate'], true)) $fileVariant = 'ultimate';

$allowedNames = [
    'executive_ops_summary_last.html',
    'executive_ops_summary_last.json',
    'executive_ops_summary_ultimate_last.html',
    'executive_ops_summary_ultimate_last.json',
];
$prefix = $fileVariant === 'ultimate' ? 'executive_ops_summary_ultimate_last' : 'executive_ops_summary_last';
$file = $prefix . '.' . $mode;

$read = svl_safe_read_file(svl_pipeline_dir(), $file, $allowedNames, ['json', 'html']);
if (!$read['ok']) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n\n" . htmlspecialchars($read['error_code'] ?: 'file_unreadable', ENT_QUOTES, 'UTF-8') . "\n";
    exit;
}

$content = $read['content'];

if ($mode === 'html') {
    if (svl_has_deny_pattern($content)) {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Executive Ops Summary</title></head><body>';
        echo '<p><strong>ATTENTION: REDACTION_REQUIRED</strong></p>';
        echo '<p>Content contains sensitive patterns and cannot be displayed. Run generate_executive_summary to regenerate.</p>';
        echo '</body></html>';
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $content;
    exit;
}

if ($mode === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode($content, true);
    if (is_array($data)) {
        $masked = json_encode(svl_mask_recursive($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo $masked !== false ? $masked : tools_mask_sensitive($content);
    } else {
        echo tools_mask_sensitive($content);
    }
    exit;
}

http_response_code(400);
echo "Bad request\n";
