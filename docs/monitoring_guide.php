<?php
declare(strict_types=1);

/**
 * Viewer panduan Monitoring & Control (sumber: MONITORING_AND_CONTROL.md).
 */
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_login();

if (function_exists('require_any_permission')) {
    require_any_permission([
        'SYSTEM.AUDIT_LOG_VIEW',
        'SYSTEM.JOBS_MONITOR',
        'SYSTEM.USER_MANAGE',
        'SYSTEM.CONFIG_MANAGE',
        'MASTER.ADMIN_CENTER',
    ]);
} elseif (function_exists('auth_is_admin') && !auth_is_admin()) {
    http_response_code(403);
    echo '<h3>Akses Ditolak</h3>';
    exit;
}

require_once __DIR__ . '/../_shared/rmi_layout.php';

$base = rmi_layout_base_project();
$mdPath = __DIR__ . '/MONITORING_AND_CONTROL.md';
$raw = (is_file($mdPath) && is_readable($mdPath)) ? (string) file_get_contents($mdPath) : 'Berkas panduan tidak ditemukan.';

rmi_header('Panduan — Monitoring & Control', [
    'active'      => 'monitoring_center',
    'subtitle'    => 'Ke mana melihat audit, log, dan pintasan operasional',
    'breadcrumbs' => [
        ['label' => 'Monitoring', 'url' => $base . '/master/monitoring_center.php'],
        'Panduan',
    ],
    'actions'     => [
        ['label' => '← Monitoring Center', 'url' => $base . '/master/monitoring_center.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Audit Log', 'url' => $base . '/master/audit_logs.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>
<div class="rmi-card p-3">
  <p class="small text-muted mb-2">Sumber: <code>docs/MONITORING_AND_CONTROL.md</code> (disalin di bawah untuk kemudahan akses dari browser).</p>
  <pre class="mb-0 text-start" style="white-space:pre-wrap;font-size:13px;line-height:1.45"><?= rmi_h($raw) ?></pre>
</div>
<?php rmi_footer(); ?>
