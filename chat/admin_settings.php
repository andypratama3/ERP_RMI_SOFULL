<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['CHAT.ADMIN_SETTINGS', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_admin_critical();
}
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../master/_audit_master.php';
if (!function_exists('chat_h')) {
    function chat_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = rmi_db_pdo();
erp_audit_ensure($pdo);
$base = rmi_layout_base_project();
$flash = '';
$error = '';

$keys = [
    'allow_reactions',
    'allow_typing_indicator',
    'allow_message_pin',
    'allow_dm_read_receipt',
    'enable_channel_acl',
    'mention_badge_enabled',
    'context_include_employee_name',
    'context_employee_name_format',
    'default_pin_policy',
    'retention_mode',
    'purge_soft_deleted_after_days',
    'attachment_purge_after_days',
    'max_attachment_size_mb',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    verify_csrf();
    try {
        $fmtCtx = strtolower(trim((string)($_POST['context_employee_name_format'] ?? 'parentheses')));
        if (!in_array($fmtCtx, ['parentheses', 'comma', 'dash'], true)) {
            $fmtCtx = 'parentheses';
        }
        $vals = [
            'allow_reactions' => isset($_POST['allow_reactions']) ? '1' : '0',
            'allow_typing_indicator' => isset($_POST['allow_typing_indicator']) ? '1' : '0',
            'allow_message_pin' => isset($_POST['allow_message_pin']) ? '1' : '0',
            'allow_dm_read_receipt' => isset($_POST['allow_dm_read_receipt']) ? '1' : '0',
            'enable_channel_acl' => isset($_POST['enable_channel_acl']) ? '1' : '0',
            'mention_badge_enabled' => isset($_POST['mention_badge_enabled']) ? '1' : '0',
            'context_include_employee_name' => isset($_POST['context_include_employee_name']) ? '1' : '0',
            'context_employee_name_format' => $fmtCtx,
            'default_pin_policy' => in_array((string)($_POST['default_pin_policy'] ?? ''), ['ADMIN_ONLY', 'MEMBER'], true) ? (string)$_POST['default_pin_policy'] : 'ADMIN_ONLY',
            'retention_mode' => in_array((string)($_POST['retention_mode'] ?? ''), ['none', 'archive', 'purge_soft_deleted'], true) ? (string)$_POST['retention_mode'] : 'purge_soft_deleted',
            'purge_soft_deleted_after_days' => (string)max(1, min(3650, (int)($_POST['purge_soft_deleted_after_days'] ?? 90))),
            'attachment_purge_after_days' => (string)max(1, min(3650, (int)($_POST['attachment_purge_after_days'] ?? 180))),
            'max_attachment_size_mb' => (string)max(1, min(100, (int)($_POST['max_attachment_size_mb'] ?? 10))),
        ];
        $st = $pdo->prepare("INSERT INTO chat_config (config_key, config_value, updated_at)
                             VALUES (?, ?, NOW())
                             ON DUPLICATE KEY UPDATE config_value=VALUES(config_value), updated_at=NOW()");
        foreach ($vals as $k => $v) {
            $st->execute([$k, $v]);
        }

        // Optional custom emoji admin list: comma/newline separated.
        $emojiRaw = trim((string)($_POST['custom_emojis'] ?? ''));
        if ($emojiRaw !== '') {
            try {
                $pdo->query("SELECT 1 FROM chat_custom_emojis LIMIT 1");
                $parts = preg_split('/[\r\n,]+/', $emojiRaw) ?: [];
                $set = [];
                foreach ($parts as $p) {
                    $e = trim((string)$p);
                    if ($e === '' || mb_strlen($e) > 16) continue;
                    $set[$e] = true;
                }
                $pdo->beginTransaction();
                $pdo->exec("UPDATE chat_custom_emojis SET is_active=0");
                $insE = $pdo->prepare("INSERT INTO chat_custom_emojis (emoji_code, emoji_char, is_active, created_by, updated_at)
                                       VALUES (?, ?, 1, ?, NOW())
                                       ON DUPLICATE KEY UPDATE emoji_char=VALUES(emoji_char), is_active=1, updated_at=NOW()");
                $i = 1;
                foreach (array_keys($set) as $emo) {
                    $insE->execute(['custom_' . $i, $emo, (int)($_SESSION['user_id'] ?? 0)]);
                    $i++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
            }
        }
        erp_audit($pdo, 'CHAT', 'CONFIG', 'CHAT_ADMIN_SETTINGS_UPDATE', ['updated_by' => (int)($_SESSION['user_id'] ?? 0), 'keys' => array_keys($vals)]);
        if (function_exists('master_audit')) {
            master_audit($pdo, 'chat_admin', 'chat_config', 'SETTINGS_UPDATE', null, 'CONFIG', 'Chat admin settings updated', ['keys' => array_keys($vals)]);
        }
        $flash = 'Chat settings saved.';
    } catch (Throwable $e) {
        $error = 'Failed saving settings.';
    }
}

$cfg = [];
try {
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT config_key, config_value FROM chat_config WHERE config_key IN ($in)");
    $st->execute($keys);
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $cfg[(string)$r['config_key']] = (string)$r['config_value'];
    }
} catch (Throwable $e) {}

$get = static function(string $k, string $d='') use ($cfg): string {
    return array_key_exists($k, $cfg) ? (string)$cfg[$k] : $d;
};

$customEmojiText = '';
try {
    $pdo->query("SELECT 1 FROM chat_custom_emojis LIMIT 1");
    $stE = $pdo->query("SELECT emoji_char FROM chat_custom_emojis WHERE is_active=1 ORDER BY id ASC LIMIT 100");
    $rowsE = $stE ? ($stE->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $customEmojiText = implode(', ', array_map(static fn($r) => (string)($r['emoji_char'] ?? ''), $rowsE));
} catch (Throwable $e) {
    $customEmojiText = '';
}

rmi_header('Chat Admin Settings', [
    'active' => 'chat',
    'subtitle' => 'Synology-like governance controls for internal chat',
    'breadcrumbs' => [
        ['label' => 'Chat', 'url' => $base . '/chat/index.php'],
        'Admin Settings',
    ],
]);
?>
<div class="card rmi-card">
  <div class="card-header rmi-card-header d-flex justify-content-between align-items-center">
    <div>Chat Governance Settings</div>
    <a class="btn btn-sm btn-outline-light" href="<?= chat_h($base . '/chat/index.php') ?>">Back to Chat</a>
  </div>
  <div class="card-body">
    <?php if ($flash !== ''): ?><div class="alert alert-success rmi-alert"><?= chat_h($flash) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger rmi-alert"><?= chat_h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= chat_h(csrf_token()) ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Feature Toggles</label>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="allow_reactions" name="allow_reactions" <?= $get('allow_reactions','1')==='1'?'checked':'' ?>><label class="form-check-label" for="allow_reactions">Allow reactions (emoji)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="allow_typing_indicator" name="allow_typing_indicator" <?= $get('allow_typing_indicator','1')==='1'?'checked':'' ?>><label class="form-check-label" for="allow_typing_indicator">Allow typing indicator</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="allow_message_pin" name="allow_message_pin" <?= $get('allow_message_pin','1')==='1'?'checked':'' ?>><label class="form-check-label" for="allow_message_pin">Allow message pin</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="allow_dm_read_receipt" name="allow_dm_read_receipt" <?= $get('allow_dm_read_receipt','1')==='1'?'checked':'' ?>><label class="form-check-label" for="allow_dm_read_receipt">Allow DM read receipt</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="enable_channel_acl" name="enable_channel_acl" <?= $get('enable_channel_acl','1')==='1'?'checked':'' ?>><label class="form-check-label" for="enable_channel_acl">Enable channel ACL matrix</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="mention_badge_enabled" name="mention_badge_enabled" <?= $get('mention_badge_enabled','1')==='1'?'checked':'' ?>><label class="form-check-label" for="mention_badge_enabled">Enable mention unread badge</label></div>
          <hr class="border-secondary my-3">
          <div class="fw-semibold mb-1">Context chat (DO / PO / AP)</div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="context_include_employee_name" name="context_include_employee_name" <?= $get('context_include_employee_name','1')==='1'?'checked':'' ?>>
            <label class="form-check-label" for="context_include_employee_name">Sertakan nama karyawan pada mention PIC di pesan sistem pembuka channel</label>
          </div>
          <div class="form-text mb-2">Nama untuk mention: prioritas <strong>master_employees.employee_name</strong> (user login harus punya <strong>holder_employee_code</strong> yang cocok dengan <strong>employee_code</strong> di Master Karyawan). Jika kosong, dipakai <strong>master_system_login.full_name</strong>.</div>
          <div class="mt-2">
            <label class="form-label" for="context_employee_name_format">Format tampilan username + nama</label>
            <?php $cef = $get('context_employee_name_format', 'parentheses'); ?>
            <select class="form-select" id="context_employee_name_format" name="context_employee_name_format">
              <option value="parentheses" <?= $cef === 'parentheses' ? 'selected' : '' ?>>@username (Nama Lengkap)</option>
              <option value="comma" <?= $cef === 'comma' ? 'selected' : '' ?>>@username, Nama Lengkap</option>
              <option value="dash" <?= $cef === 'dash' ? 'selected' : '' ?>>@username — Nama Lengkap</option>
            </select>
          </div>
          <div class="mt-2">
            <label class="form-label" for="default_pin_policy">Default pin policy for new channels</label>
            <select class="form-select" id="default_pin_policy" name="default_pin_policy">
              <?php $dpp = $get('default_pin_policy','ADMIN_ONLY'); ?>
              <option value="ADMIN_ONLY" <?= $dpp==='ADMIN_ONLY'?'selected':'' ?>>ADMIN_ONLY</option>
              <option value="MEMBER" <?= $dpp==='MEMBER'?'selected':'' ?>>MEMBER</option>
            </select>
          </div>
          <div class="mt-2">
            <label class="form-label" for="custom_emojis">Custom emoji set (comma/newline separated)</label>
            <textarea class="form-control" id="custom_emojis" name="custom_emojis" rows="3" placeholder="😄, 🚀, 🛠️"><?= chat_h($customEmojiText) ?></textarea>
            <div class="form-text">Ini mengganti daftar custom emoji aktif.</div>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="retention_mode">Retention Mode</label>
          <select class="form-select" id="retention_mode" name="retention_mode">
            <?php $rm = $get('retention_mode','purge_soft_deleted'); ?>
            <option value="purge_soft_deleted" <?= $rm==='purge_soft_deleted'?'selected':'' ?>>purge_soft_deleted</option>
            <option value="none" <?= $rm==='none'?'selected':'' ?>>none</option>
            <option value="archive" <?= $rm==='archive'?'selected':'' ?>>archive</option>
          </select>
          <div class="mt-2">
            <label class="form-label" for="purge_soft_deleted_after_days">Purge soft-deleted after (days)</label>
            <input class="form-control" type="number" min="1" max="3650" id="purge_soft_deleted_after_days" name="purge_soft_deleted_after_days" value="<?= chat_h($get('purge_soft_deleted_after_days','90')) ?>">
          </div>
          <div class="mt-2">
            <label class="form-label" for="attachment_purge_after_days">Attachment purge after (days)</label>
            <input class="form-control" type="number" min="1" max="3650" id="attachment_purge_after_days" name="attachment_purge_after_days" value="<?= chat_h($get('attachment_purge_after_days','180')) ?>">
          </div>
          <div class="mt-2">
            <label class="form-label" for="max_attachment_size_mb">Max attachment size (MB)</label>
            <input class="form-control" type="number" min="1" max="100" id="max_attachment_size_mb" name="max_attachment_size_mb" value="<?= chat_h($get('max_attachment_size_mb','10')) ?>">
          </div>
        </div>
      </div>
      <div class="mt-3">
        <button class="btn btn-primary" type="submit">Save Chat Settings</button>
      </div>
    </form>
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
              <td><?= chat_h($a['created_at'] ?? '') ?></td>
              <td><?= chat_h($a['action'] ?? '') ?></td>
              <td><?= chat_h($a['record_code'] ?? '') ?></td>
              <td><?= chat_h($a['username'] ?? '') ?></td>
              <td><?= chat_h($a['description'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>

