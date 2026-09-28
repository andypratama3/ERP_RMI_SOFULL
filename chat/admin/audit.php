<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['CHAT.ADMIN_SETTINGS', 'SYSTEM.CONFIG_MANAGE']);
} else {
    if (!function_exists('chat_is_admin')) {
        function chat_is_admin(): bool
        {
            $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
            return in_array($level, ['ADMIN', 'SUPERADMIN'], true);
        }
    }
    if (!chat_is_admin()) {
        http_response_code(403);
        exit('Forbidden');
    }
}
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../../_shared/db.php';

if (!function_exists('chat_admin_h')) {
    function chat_admin_h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

$pdo = rmi_db_pdo();
$base = rmi_layout_base_project();
$channelId = (int)($_GET['channel_id'] ?? 0);
$actor = trim((string)($_GET['actor'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$where = ["module='CHAT'"];
$params = [];
if ($actor !== '') {
    $where[] = "username LIKE ?";
    $params[] = '%' . $actor . '%';
}
if ($dateFrom !== '') {
    $where[] = "created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = "created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}
if ($channelId > 0) {
    $where[] = "(entity_key=? OR payload_json LIKE ?)";
    $params[] = 'CHANNEL#' . $channelId;
    $params[] = '%"channel_id":' . $channelId . '%';
}

$sql = "SELECT id, module, entity_key, action, username, payload_json, created_at
        FROM erp_audit_log
        WHERE " . implode(' AND ', $where) . "
        ORDER BY id DESC
        LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

rmi_header('Chat Admin Audit', [
    'active' => 'chat',
    'subtitle' => 'Moderation and compliance audit trail',
    'breadcrumbs' => [
        ['label' => 'Chat', 'url' => $base . '/chat/index.php'],
        'Admin Audit',
    ],
]);
?>
<div class="card rmi-card mb-3">
  <div class="card-header rmi-card-header">Filter Audit</div>
  <div class="card-body">
    <form method="get" class="row g-2">
      <div class="col-md-2"><input class="form-control" type="number" name="channel_id" placeholder="Channel ID" value="<?= chat_admin_h((string)$channelId) ?>"></div>
      <div class="col-md-2"><input class="form-control" type="text" name="actor" placeholder="Actor username" value="<?= chat_admin_h($actor) ?>"></div>
      <div class="col-md-2"><input class="form-control" type="date" name="date_from" value="<?= chat_admin_h($dateFrom) ?>"></div>
      <div class="col-md-2"><input class="form-control" type="date" name="date_to" value="<?= chat_admin_h($dateTo) ?>"></div>
      <div class="col-md-4 d-flex gap-2">
        <button class="btn btn-primary" type="submit">Apply</button>
        <a class="btn btn-outline-secondary" href="<?= chat_admin_h($base . '/chat/admin/audit.php') ?>">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card rmi-card">
  <div class="card-header rmi-card-header d-flex justify-content-between">
    <span>Audit Rows (latest 500)</span>
    <a class="btn btn-sm btn-outline-light" href="<?= chat_admin_h($base . '/chat/index.php') ?>">Back to Chat</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0">
        <thead><tr><th>ID</th><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Payload</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= chat_admin_h((string)$r['created_at']) ?></td>
            <td><?= chat_admin_h((string)($r['username'] ?: 'SYSTEM')) ?></td>
            <td><?= chat_admin_h((string)$r['action']) ?></td>
            <td><?= chat_admin_h((string)$r['entity_key']) ?></td>
            <td><code><?= chat_admin_h(mb_substr((string)($r['payload_json'] ?? ''), 0, 240)) ?></code></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted">No audit rows.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>

