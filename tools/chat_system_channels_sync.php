<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/erp_audit.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SESSION['username'] = $_SESSION['username'] ?? 'SYSTEM';

$pdo = rmi_db_pdo();
erp_audit_ensure($pdo);

$users = $pdo->query("
    SELECT id, username, UPPER(TRIM(COALESCE(level,''))) AS level_code,
           UPPER(TRIM(COALESCE(department,''))) AS dept_code,
           UPPER(TRIM(COALESCE(office_code,''))) AS office_code
    FROM master_system_login
    WHERE LOWER(COALESCE(status,'active'))='active'
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$ensureChannel = $pdo->prepare("
    INSERT INTO chat_channels (type, name, channel_key, is_private, created_at, created_by)
    VALUES ('CHANNEL', ?, ?, 0, NOW(), ?)
    ON DUPLICATE KEY UPDATE name=VALUES(name)
");
$getChannel = $pdo->prepare("SELECT id FROM chat_channels WHERE channel_key=? LIMIT 1");
$ensureMember = $pdo->prepare("
    INSERT INTO chat_channel_members (channel_id, user_id, joined_at, last_read_message_id)
    VALUES (?, ?, NOW(), 0)
    ON DUPLICATE KEY UPDATE joined_at=joined_at
");
$ensureAcl = $pdo->prepare("
    INSERT INTO chat_channel_acl
    (channel_id, subject_type, subject_key, role_code, can_read, can_send, can_pin, can_manage, updated_by, updated_at)
    VALUES (?, 'ROLE', ?, ?, 1, 1, ?, ?, 0, NOW())
    ON DUPLICATE KEY UPDATE
      can_read=VALUES(can_read), can_send=VALUES(can_send), can_pin=VALUES(can_pin), can_manage=VALUES(can_manage), updated_at=NOW()
");

$channelsEnsured = 0;
$membersEnsured = 0;

foreach ($users as $u) {
    $dept = (string)$u['dept_code'];
    $office = (string)$u['office_code'];
    if ($dept === '' || $office === '') {
        continue;
    }
    $channelKey = 'SYS:' . $dept . ':' . $office;
    $name = '#' . $dept . '-' . $office;
    $ensureChannel->execute([$name, $channelKey, 'SYSTEM']);
    $getChannel->execute([$channelKey]);
    $cid = (int)$getChannel->fetchColumn();
    if ($cid <= 0) {
        continue;
    }
    $channelsEnsured++;
    $ensureMember->execute([$cid, (int)$u['id']]);
    $membersEnsured++;
}

// Baseline ACL for system channels.
$sysChannels = $pdo->query("SELECT id FROM chat_channels WHERE channel_key LIKE 'SYS:%'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($sysChannels as $sc) {
    $cid = (int)$sc['id'];
    $ensureAcl->execute([$cid, 'MANAGER', 'MANAGER', 1, 1]);
    $ensureAcl->execute([$cid, 'ADMIN', 'ADMIN', 1, 1]);
    $ensureAcl->execute([$cid, 'SUPERADMIN', 'SUPERADMIN', 1, 1]);
}

erp_audit($pdo, 'CHAT', 'CHAN:SYS_SYNC', 'CHAT_SYSTEM_CHANNELS_SYNC', [
    'channels_ensured' => $channelsEnsured,
    'members_ensured' => $membersEnsured,
]);

echo json_encode([
    'ok' => true,
    'channels_ensured' => $channelsEnsured,
    'members_ensured' => $membersEnsured,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

