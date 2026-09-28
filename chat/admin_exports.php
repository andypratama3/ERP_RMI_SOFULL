<?php
declare(strict_types=1);

require_once __DIR__ . '/_chat_bootstrap.php';
if (function_exists('require_any_permission')) {
    require_any_permission(['CHAT.ADMIN_SETTINGS', 'SYSTEM.CONFIG_MANAGE']);
} elseif (!chat_is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/../_shared/db.php';

$pdo = rmi_db_pdo();
$base = rmi_layout_base_project();

$st = $pdo->query("SELECT id, channel_id, requested_by, requested_at, range_start, range_end, format, status, file_path, row_count FROM chat_exports ORDER BY id DESC LIMIT 300");
$rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

rmi_header('Chat Admin Exports', [
    'active' => 'chat',
    'subtitle' => 'Export governance',
    'breadcrumbs' => [
        ['label' => 'Chat', 'url' => $base . '/chat/index.php'],
        'Admin Exports',
    ],
]);
?>
<div class="table-responsive">
  <table class="<?= rmi_ui_table_class() ?>">
    <thead>
      <tr>
        <th>ID</th><th>Channel</th><th>Requested By</th><th>Range</th><th>Format</th><th>Status</th><th>Rows</th><th>Download</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= (int)$r['channel_id'] ?></td>
        <td><?= htmlspecialchars((string)$r['requested_by'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)$r['range_start'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string)$r['range_end'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)$r['format'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= (int)$r['row_count'] ?></td>
        <td><a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($base . '/api/v1/chat/export_download.php?export_id=' . (int)$r['id'], ENT_QUOTES, 'UTF-8') ?>">Download</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
      <tr><td colspan="8" class="text-center text-muted">No exports yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php rmi_footer(); ?>

