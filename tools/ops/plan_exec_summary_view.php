<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/_lib/plan_exec_summary_view_lib.php';

tools_require_access('ops/plan_exec_summary_view.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

$mode = strtolower(trim((string)($_GET['mode'] ?? 'html')));
if (!in_array($mode, ['html', 'json', 'md'], true)) $mode = 'html';

$file = strtolower(trim((string)($_GET['file'] ?? 'last')));
if (!in_array($file, ['last', 'run'], true)) $file = 'last';

$planId = trim((string)($_GET['plan_id'] ?? ''));
$masterRunId = trim((string)($_GET['master_run_id'] ?? ''));

$res = pesv_resolve_path($mode, $file, $planId !== '' ? $planId : null, $masterRunId !== '' ? $masterRunId : null);
if (!$res['ok']) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n\n" . htmlspecialchars($res['error'] ?? 'file_not_found', ENT_QUOTES, 'UTF-8') . "\n";
    exit;
}

$renderMdFromJson = !empty($res['render_md_from_json']);

$read = pesv_read_safe($res['path']);
if (!$read['ok']) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n\nFile unreadable.\n";
    exit;
}

$content = $read['content'];

if ($mode === 'html') {
    if (pesv_has_deny_pattern($content)) {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Plan Exec Summary</title></head><body>';
        echo '<p><strong>ATTENTION: REDACTION_REQUIRED</strong></p>';
        echo '<p>Content contains sensitive patterns and cannot be displayed. Run pipeline to regenerate.</p>';
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
        $masked = json_encode(pesv_mask_recursive($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo $masked !== false ? $masked : $content;
    } else {
        echo tools_mask_sensitive($content);
    }
    exit;
}

if ($mode === 'md') {
    header('Content-Type: text/html; charset=utf-8');
    if ($renderMdFromJson) {
        $data = json_decode($content, true);
        if (is_array($data)) {
            require_once __DIR__ . '/../qa/_lib/plan_exec_summary_render.php';
            $content = pes_render_md($data);
        }
    }
    $lines = explode("\n", $content);
    $masked = [];
    foreach ($lines as $line) {
        $masked[] = htmlspecialchars(tools_mask_sensitive($line), ENT_QUOTES, 'UTF-8');
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Plan Exec Summary</title>';
    echo '<style>pre{font-family:monospace;font-size:12px;white-space:pre-wrap;}</style></head><body><pre>';
    echo implode("\n", $masked);
    echo '</pre></body></html>';
    exit;
}

http_response_code(400);
echo "Bad request\n";
