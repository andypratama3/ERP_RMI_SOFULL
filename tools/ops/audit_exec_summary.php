<?php
/**
 * audit_exec_summary.php — Web viewer for 1-page exec summary
 */
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('ops/audit_exec_summary.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

$root = ts_root();
$baseProject = rmi_layout_base_project();
$logsDir = $root . '/storage/logs';
$htmlPath = $logsDir . '/audit_exec_summary_last.html';
$jsonPath = $logsDir . '/audit_live_last.json';

$html = '';
$json = [];
if (is_file($htmlPath)) {
    $html = (string)@file_get_contents($htmlPath);
} else {
    $html = '<p>No exec summary yet. Run: <code>php tools/qa/audit_live.php --env=production --write-last --strict</code></p>';
}
if (is_file($jsonPath)) {
    $json = json_decode((string)@file_get_contents($jsonPath), true) ?: [];
}

rmi_header('Audit Exec Summary', [
    'active' => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Audit Exec Summary',
    ],
]);
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Audit Exec Summary</h3>
        <a class="btn btn-outline-secondary" href="<?= rmi_h($baseProject) ?>/tools/qa/audit_live_web.php">Live Audit</a>
    </div>
    <div class="card">
        <div class="card-body">
            <?php if (!empty($html) && strpos($html, '<body>') !== false): ?>
                <?= preg_replace('#^.*<body[^>]*>#s', '', preg_replace('#</body>.*$#s', '', $html)) ?>
            <?php else: ?>
                <?= $html ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="mt-3">
        <a class="btn btn-sm btn-outline-secondary" href="<?= rmi_h($baseProject) ?>/storage/logs/audit_live_last.json" target="_blank">View JSON</a>
    </div>
</div>
<?php rmi_footer(); ?>
