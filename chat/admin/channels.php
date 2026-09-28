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
require_once __DIR__ . '/../../_shared/erp_audit.php';
require_once __DIR__ . '/../../master/_audit_master.php';

if (!function_exists('chat_ch_h')) {
    function chat_ch_h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

$pdo = rmi_db_pdo();
erp_audit_ensure($pdo);
$base = rmi_layout_base_project();
$flash = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    verify_csrf();
    try {
        $channelId = (int)($_POST['channel_id'] ?? 0);
        if ($channelId <= 0) {
            throw new RuntimeException('Invalid channel id.');
        }
        $retMode = strtolower(trim((string)($_POST['retention_mode'] ?? 'none')));
        $retDaysRaw = trim((string)($_POST['retention_days'] ?? ''));
        $retDays = $retDaysRaw === '' ? null : max(1, min(180, (int)$retDaysRaw));
        if ($retMode === 'none') {
            $retDays = null;
        }
        $pdo->prepare("UPDATE chat_channels SET retention_mode=?, retention_days=? WHERE id=?")->execute([$retMode, $retDays, $channelId]);

        $rowsJson = trim((string)($_POST['acl_rows_json'] ?? '[]'));
        $rows = json_decode($rowsJson, true);
        if (!is_array($rows)) {
            $rows = [];
        }
        if ($rows) {
            $pdo->prepare("DELETE FROM chat_channel_acl WHERE channel_id=?")->execute([$channelId]);
            $ins = $pdo->prepare("INSERT INTO chat_channel_acl
                (channel_id, subject_type, subject_key, role_code, can_read, can_send, can_pin, can_manage, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            foreach ($rows as $r) {
                $subjectType = strtoupper(trim((string)($r['subject_type'] ?? 'ROLE')));
                $subjectKey = strtoupper(trim((string)($r['subject_key'] ?? '')));
                if ($subjectKey === '') {
                    continue;
                }
                $ins->execute([
                    $channelId,
                    $subjectType,
                    $subjectKey,
                    $subjectType === 'ROLE' ? $subjectKey : null,
                    !empty($r['perm_read']) ? 1 : 0,
                    !empty($r['perm_send']) ? 1 : 0,
                    !empty($r['perm_pin']) ? 1 : 0,
                    !empty($r['perm_manage']) ? 1 : 0,
                    (int)($_SESSION['user_id'] ?? 0),
                ]);
            }
        }
        erp_audit($pdo, 'CHAT', 'CHANNEL#' . $channelId, 'CHAT_CHANNEL_ACL_UPDATED', [
            'actor_username' => (string)($_SESSION['username'] ?? 'SYSTEM'),
            'retention_mode' => $retMode,
            'retention_days' => $retDays,
        ]);
        if (function_exists('master_audit')) {
            master_audit($pdo, 'chat_admin', 'chat_channels', 'CHANNEL_ACL_UPDATED', $channelId, 'CHANNEL#' . $channelId, "Chat channel ACL updated: #{$channelId}", ['retention_mode' => $retMode, 'retention_days' => $retDays]);
        }
        $flash = 'Channel policy updated.';
    } catch (Throwable $e) {
        $error = 'Failed to update channel policy.';
    }
}

$st = $pdo->query("SELECT id, channel_type AS type, name, (channel_type IN ('PRIVATE','DM')) AS is_private, COALESCE(retention_mode,'none') AS retention_mode, retention_days FROM chat_channels ORDER BY id ASC LIMIT 500");
$channels = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$audit_rows = [];
if (function_exists('master_audit')) {
    try {
        $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='chat_admin' ORDER BY created_at DESC LIMIT 50");
        $st->execute();
        $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

rmi_header('Chat Admin Channels', [
    'active' => 'chat',
    'subtitle' => 'Retention + ACL management',
    'breadcrumbs' => [
        ['label' => 'Chat', 'url' => $base . '/chat/index.php'],
        'Admin Channels',
    ],
]);
?>
<div class="card rmi-card mb-3">
  <div class="card-header rmi-card-header d-flex justify-content-between">
    <span>Channels</span>
    <a class="btn btn-sm btn-outline-light" href="<?= chat_ch_h($base . '/chat/index.php') ?>">Back to Chat</a>
  </div>
  <div class="card-body">
    <?php if ($flash !== ''): ?><div class="alert alert-success"><?= chat_ch_h($flash) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= chat_ch_h($error) ?></div><?php endif; ?>
    <p class="text-muted mb-2">Retention presets: none / 30 / 90 / 180 (purge otomatis). ACL rows format JSON: <code>[{"subject_type":"ROLE","subject_key":"ADMIN","perm_read":1,"perm_send":1,"perm_pin":1,"perm_manage":1}]</code></p>
    <div class="table-responsive">
      <table class="table table-sm table-striped">
        <thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Private</th><th>Retention</th><th>ACL JSON</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($channels as $c): ?>
          <tr>
            <form method="post">
              <td>
                <?= (int)$c['id'] ?>
                <input type="hidden" name="csrf_token" value="<?= chat_ch_h(csrf_token()) ?>">
                <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
              </td>
              <td><?= chat_ch_h((string)($c['name'] ?? 'dm')) ?></td>
              <td><?= chat_ch_h((string)$c['type']) ?></td>
              <td><?= (int)$c['is_private'] === 1 ? 'yes' : 'no' ?></td>
              <td class="d-flex gap-2">
                <select class="form-select form-select-sm" name="retention_mode" style="min-width:100px">
                  <?php $rm = strtolower((string)($c['retention_mode'] ?? 'none')); ?>
                  <option value="none" <?= $rm === 'none' ? 'selected' : '' ?>>none</option>
                  <option value="purge" <?= $rm === 'purge' ? 'selected' : '' ?>>purge</option>
                </select>
                <select class="form-select form-select-sm" name="retention_days" style="min-width:100px">
                  <?php $rd = (int)($c['retention_days'] ?? 0); ?>
                  <option value="">-</option>
                  <option value="30" <?= $rd === 30 ? 'selected' : '' ?>>30</option>
                  <option value="90" <?= $rd === 90 ? 'selected' : '' ?>>90</option>
                  <option value="180" <?= $rd === 180 ? 'selected' : '' ?>>180</option>
                </select>
              </td>
              <td><input class="form-control form-control-sm" type="text" name="acl_rows_json" placeholder='[{"subject_type":"ROLE","subject_key":"USER","perm_read":1}]'></td>
              <td><button class="btn btn-sm btn-primary" type="submit">Save</button></td>
            </form>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (!empty($audit_rows)): ?>
<div class="card rmi-card mt-3">
  <div class="card-header rmi-card-header">Audit Log (Last 50 events)</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0">
        <thead><tr><th>Time</th><th>Action</th><th>Code</th><th>User</th><th>Description</th></tr></thead>
        <tbody>
          <?php foreach ($audit_rows as $a): ?>
            <tr>
              <td><?= chat_ch_h($a['created_at'] ?? '') ?></td>
              <td><?= chat_ch_h($a['action'] ?? '') ?></td>
              <td><?= chat_ch_h($a['record_code'] ?? '') ?></td>
              <td><?= chat_ch_h($a['username'] ?? '') ?></td>
              <td><?= chat_ch_h($a['description'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>

