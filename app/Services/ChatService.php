<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class ChatService
{
    public function __construct(
        private readonly ChatMentionService $mentionService = new ChatMentionService(),
        private readonly ChatAttachmentService $attachmentService = new ChatAttachmentService()
    ) {}

    public function ensureReady(PDO $pdo): void
    {
        $needed = ['chat_channels', 'chat_channel_members', 'chat_messages', 'chat_attachments', 'chat_config'];
        foreach ($needed as $t) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
            $st->execute([$t]);
            if ((int)$st->fetchColumn() <= 0) {
                throw new \RuntimeException('Chat module is not migrated yet.');
            }
        }
        $hasMentions = $this->tableExists($pdo, 'chat_mentions') || $this->tableExists($pdo, 'chat_message_mentions');
        if (!$hasMentions) {
            throw new \RuntimeException('Chat module is not migrated yet (mentions table missing).');
        }
    }

    public function isAdminLike(string $role): bool
    {
        $r = strtoupper(trim($role));
        return in_array($r, ['ADMIN', 'SUPERADMIN'], true);
    }

    public function userExists(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) return false;
        try {
            $st = $pdo->prepare("SELECT 1 FROM master_system_login WHERE id=? LIMIT 1");
            $st->execute([$userId]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function channelById(PDO $pdo, int $channelId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM chat_channels WHERE id=? LIMIT 1");
        $st->execute([$channelId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        return $c ?: null;
    }

    public function isMember(PDO $pdo, int $channelId, int $userId): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM chat_channel_members WHERE channel_id=? AND user_id=? LIMIT 1");
        $st->execute([$channelId, $userId]);
        return (bool)$st->fetchColumn();
    }

    public function canAccessChannel(PDO $pdo, array $channel, int $userId): bool
    {
        $type = strtoupper((string)($channel['type'] ?? 'CHANNEL'));
        if ($type === 'DM') {
            $a = (int)($channel['dm_user_low'] ?? 0);
            $b = (int)($channel['dm_user_high'] ?? 0);
            return $userId === $a || $userId === $b || $this->isMember($pdo, (int)$channel['id'], $userId);
        }
        if ((int)($channel['is_private'] ?? 0) === 0) {
            return true;
        }
        return $this->isMember($pdo, (int)$channel['id'], $userId);
    }

    public function ensureMember(PDO $pdo, int $channelId, int $userId): void
    {
        if ($this->columnExists($pdo, 'chat_channel_members', 'username')) {
            $username = 'user' . $userId;
            if ($this->tableExists($pdo, 'master_system_login')) {
                $u = $pdo->prepare("SELECT username FROM master_system_login WHERE id=? LIMIT 1");
                $u->execute([$userId]);
                $un = $u->fetchColumn();
                if ($un !== false && $un !== null && trim((string)$un) !== '') {
                    $username = trim((string)$un);
                }
            }
            $st = $pdo->prepare("INSERT INTO chat_channel_members (channel_id, user_id, username, joined_at, last_read_message_id)
                                 VALUES (?, ?, ?, NOW(), 0)
                                 ON DUPLICATE KEY UPDATE joined_at=joined_at");
            $st->execute([$channelId, $userId, $username]);
        } else {
            $st = $pdo->prepare("INSERT INTO chat_channel_members (channel_id, user_id, joined_at, last_read_message_id)
                                 VALUES (?, ?, NOW(), 0)
                                 ON DUPLICATE KEY UPDATE joined_at=joined_at");
            $st->execute([$channelId, $userId]);
        }
    }

    public function createChannel(PDO $pdo, int $actorId, string $name, bool $isPrivate): int
    {
        $name = strtolower(trim($name));
        if (!preg_match('/^[a-z0-9_\-]{2,120}$/', $name)) {
            throw new \RuntimeException('Invalid channel name.');
        }
        $hasType = $this->columnExists($pdo, 'chat_channels', 'type');
        $hasChannelType = $this->columnExists($pdo, 'chat_channels', 'channel_type');
        if ($hasType) {
            $st = $pdo->prepare("SELECT id FROM chat_channels WHERE type='CHANNEL' AND LOWER(COALESCE(name,''))=? LIMIT 1");
        } elseif ($hasChannelType) {
            $st = $pdo->prepare("SELECT id FROM chat_channels WHERE channel_type IN ('PUBLIC','PRIVATE') AND LOWER(COALESCE(name,''))=? LIMIT 1");
        } else {
            throw new \RuntimeException('Chat channels table schema not supported.');
        }
        $st->execute([$name]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $createdBy = $actorId > 0 ? (string)$actorId : null;
        if ($hasType) {
            $defaultPinPolicy = strtoupper($this->getConfig($pdo, 'default_pin_policy', 'ADMIN_ONLY'));
            if (!in_array($defaultPinPolicy, ['ADMIN_ONLY', 'MEMBER'], true)) {
                $defaultPinPolicy = 'ADMIN_ONLY';
            }
            if ($this->columnExists($pdo, 'chat_channels', 'pin_policy')) {
                $ins = $pdo->prepare("INSERT INTO chat_channels (type,name,created_by,created_at,is_private,pin_policy) VALUES ('CHANNEL',?,?,NOW(),?,?)");
                $ins->execute([$name, $actorId > 0 ? $actorId : null, $isPrivate ? 1 : 0, $defaultPinPolicy]);
            } else {
                $ins = $pdo->prepare("INSERT INTO chat_channels (type,name,created_by,created_at,is_private) VALUES ('CHANNEL',?,?,NOW(),?)");
                $ins->execute([$name, $actorId > 0 ? $actorId : null, $isPrivate ? 1 : 0]);
            }
        } else {
            $chType = $isPrivate ? 'PRIVATE' : 'PUBLIC';
            $ins = $pdo->prepare("INSERT INTO chat_channels (channel_type,name,created_by,created_at) VALUES (?,?,?,NOW())");
            $ins->execute([$chType, $name, $createdBy]);
        }
        return (int)$pdo->lastInsertId();
    }

    public function createDm(PDO $pdo, int $actorId, int $targetUserId): int
    {
        if ($actorId <= 0 || $targetUserId <= 0 || $actorId === $targetUserId) {
            throw new \RuntimeException('Invalid DM target.');
        }
        if (!$this->userExists($pdo, $targetUserId)) {
            throw new \RuntimeException('Target user not found.');
        }
        $low = min($actorId, $targetUserId);
        $high = max($actorId, $targetUserId);
        $hasType = $this->columnExists($pdo, 'chat_channels', 'type');
        $hasChannelType = $this->columnExists($pdo, 'chat_channels', 'channel_type');
        if ($hasType && $this->columnExists($pdo, 'chat_channels', 'dm_user_low')) {
            $dmName = 'dm_' . $low . '_' . $high;
            $st = $pdo->prepare("SELECT id FROM chat_channels WHERE type='DM' AND dm_user_low=? AND dm_user_high=? LIMIT 1");
            $st->execute([$low, $high]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id <= 0) {
                $ins = $pdo->prepare("INSERT INTO chat_channels (type,name,created_by,created_at,is_private,dm_user_low,dm_user_high)
                                      VALUES ('DM',?,?,NOW(),1,?,?)");
                $ins->execute([$dmName, $actorId, $low, $high]);
                $id = (int)$pdo->lastInsertId();
            }
        } elseif ($hasChannelType) {
            $dmName = 'dm_' . $low . '_' . $high;
            $st = $pdo->prepare("SELECT id FROM chat_channels WHERE channel_type='DM' AND name=? LIMIT 1");
            $st->execute([$dmName]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id <= 0) {
                $ins = $pdo->prepare("INSERT INTO chat_channels (channel_type,name,created_by,created_at) VALUES ('DM',?,?,NOW())");
                $ins->execute([$dmName, (string)$actorId]);
                $id = (int)$pdo->lastInsertId();
            }
        } else {
            throw new \RuntimeException('Chat channels table schema not supported for DM.');
        }
        $this->ensureMember($pdo, $id, $actorId);
        $this->ensureMember($pdo, $id, $targetUserId);
        return $id;
    }

    public function joinChannel(PDO $pdo, int $actorId, int $channelId, bool $isAdminLike): void
    {
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch) {
            throw new \RuntimeException('Channel not found.');
        }
        if (strtoupper((string)$ch['type']) !== 'CHANNEL') {
            throw new \RuntimeException('Join only supports CHANNEL type.');
        }
        if ((int)$ch['is_private'] === 1 && !$isAdminLike) {
            throw new \RuntimeException('Forbidden private channel join.');
        }
        $this->ensureMember($pdo, $channelId, $actorId);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listChannelsWithUnread(PDO $pdo, int $uid, string $role = ''): array
    {
        $hasType = $this->columnExists($pdo, 'chat_channels', 'type');
        $hasChannelType = $this->columnExists($pdo, 'chat_channels', 'channel_type');
        if ($hasChannelType) {
            return $this->listChannelsWithUnread096($pdo, $uid, $role);
        }
        $hasUserTable = $this->tableExists($pdo, 'master_system_login');
        $hasPrefTable = $this->tableExists($pdo, 'chat_user_channel_prefs');
        $hasSettingTable = $this->tableExists($pdo, 'chat_user_channel_settings');
        $joinUsers = $hasUserTable
            ? "LEFT JOIN master_system_login ul ON ul.id = c.dm_user_low
               LEFT JOIN master_system_login uh ON uh.id = c.dm_user_high"
            : "";
        $joinPrefs = $hasPrefTable
            ? "LEFT JOIN chat_user_channel_prefs pref ON pref.channel_id=c.id AND pref.user_id=?"
            : "";
        $joinSettings = $hasSettingTable
            ? "LEFT JOIN chat_user_channel_settings pref2 ON pref2.channel_id=c.id AND pref2.user_id=?"
            : "";
        $mentionBadgeEnabled = $this->getConfigBool($pdo, 'mention_badge_enabled', true) && ($this->tableExists($pdo, 'chat_mentions') || $this->tableExists($pdo, 'chat_message_mentions'));
        $sql = "SELECT c.id, c.type, c.name, c.is_private, c.created_at,
                       cm.joined_at, COALESCE(cm.last_read_message_id,0) AS last_read_message_id,
                       " . ($hasSettingTable ? "CASE WHEN pref2.muted_until IS NOT NULL AND pref2.muted_until > NOW() THEN 1 ELSE 0 END" : ($hasPrefTable ? "COALESCE(pref.is_muted,0)" : "0")) . " AS is_muted,
                       " . ($hasSettingTable ? "COALESCE(pref2.notification_level,'ALL')" : ($hasPrefTable ? "COALESCE(pref.notify_level,'ALL')" : "'ALL'")) . " AS notify_level,
                       " . ($hasSettingTable ? "pref2.muted_until" : ($hasPrefTable ? "pref.mute_until" : "NULL")) . " AS mute_until,
                       CASE
                         WHEN c.type='DM' THEN
                           CASE
                             WHEN c.dm_user_low=? THEN " . ($hasUserTable ? "COALESCE(NULLIF(uh.full_name,''), uh.username, CONCAT('User#', c.dm_user_high))" : "CONCAT('User#', c.dm_user_high)") . "
                             WHEN c.dm_user_high=? THEN " . ($hasUserTable ? "COALESCE(NULLIF(ul.full_name,''), ul.username, CONCAT('User#', c.dm_user_low))" : "CONCAT('User#', c.dm_user_low)") . "
                             ELSE CONCAT('DM #', c.id)
                           END
                         ELSE CONCAT('#', COALESCE(c.name, 'channel'))
                       END AS display_name,
                       CASE
                         WHEN c.type='DM' THEN
                           CASE
                             WHEN c.dm_user_low=? THEN " . ($hasUserTable ? "uh.username" : "NULL") . "
                             WHEN c.dm_user_high=? THEN " . ($hasUserTable ? "ul.username" : "NULL") . "
                             ELSE NULL
                           END
                         ELSE NULL
                       END AS dm_partner_username,
                       CASE
                         WHEN c.type='DM' THEN
                           CASE
                             WHEN c.dm_user_low=? THEN c.dm_user_high
                             WHEN c.dm_user_high=? THEN c.dm_user_low
                             ELSE NULL
                           END
                         ELSE NULL
                       END AS dm_partner_user_id,
                       (
                         SELECT COUNT(*)
                         FROM chat_messages m
                         WHERE m.channel_id=c.id
                           AND m.id > COALESCE(cm.last_read_message_id,0)
                           AND m.user_id <> ?
                       ) AS unread_count,
                       " . ($mentionBadgeEnabled ? "(
                         SELECT COUNT(*)
                         FROM chat_mentions mt
                         JOIN chat_messages mm ON mm.id = mt.message_id
                         WHERE mm.channel_id=c.id
                           AND mt.mentioned_user_id=?
                           AND mm.id > COALESCE(cm.last_read_message_id,0)
                       )" : "0") . " AS mention_unread_count,
                       (
                         SELECT m2.created_at
                         FROM chat_messages m2
                         WHERE m2.channel_id=c.id
                         ORDER BY m2.id DESC
                         LIMIT 1
                       ) AS last_message_at
                FROM chat_channels c
                LEFT JOIN chat_channel_members cm ON cm.channel_id=c.id AND cm.user_id=?
                $joinUsers
                $joinPrefs
                $joinSettings
                WHERE (
                    c.type='CHANNEL' AND (c.is_private=0 OR cm.user_id IS NOT NULL)
                ) OR (
                    c.type='DM' AND (
                        c.dm_user_low=? OR c.dm_user_high=? OR cm.user_id IS NOT NULL
                    )
                )
                ORDER BY COALESCE(last_message_at, c.created_at) DESC, c.id DESC";
        $params = [$uid, $uid, $uid, $uid, $uid, $uid, $uid];
        if ($mentionBadgeEnabled) {
            $params[] = $uid;
        }
        $params[] = $uid;
        if ($hasPrefTable) {
            $params[] = $uid;
        }
        if ($hasSettingTable) {
            $params[] = $uid;
        }
        $params[] = $uid;
        $params[] = $uid;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['is_private'] = (int)$r['is_private'];
            $r['unread_count'] = (int)$r['unread_count'];
            $r['last_read_message_id'] = (int)$r['last_read_message_id'];
            $r['mention_unread_count'] = (int)($r['mention_unread_count'] ?? 0);
            $r['is_muted'] = (int)($r['is_muted'] ?? 0);
            $r['notify_level'] = (string)($r['notify_level'] ?? 'ALL');
            $r['mute_until'] = $r['mute_until'] ?? null;
            $r['dm_partner_user_id'] = (int)($r['dm_partner_user_id'] ?? 0);
            $r['joined'] = !empty($r['joined_at']);
            $perm = $this->channelRoleMatrix($pdo, (int)$r['id'], $role);
            $r['perm_read'] = (int)$perm['can_read'];
            $r['perm_send'] = (int)$perm['can_send'];
            $r['perm_pin'] = (int)$perm['can_pin'];
            $r['perm_manage'] = (int)$perm['can_manage'];
        }
        unset($r);
        return $rows;
    }

    /**
     * listChannelsWithUnread for 096 schema (channel_type, no type/is_private/dm_user_*)
     * @return array<int,array<string,mixed>>
     */
    private function listChannelsWithUnread096(PDO $pdo, int $uid, string $role): array
    {
        $hasUserTable = $this->tableExists($pdo, 'master_system_login');
        $hasSettingTable = $this->tableExists($pdo, 'chat_user_channel_settings');
        $joinSettings = $hasSettingTable ? "LEFT JOIN chat_user_channel_settings pref2 ON pref2.channel_id=c.id AND pref2.user_id=?" : "";
        $mentionTable = $this->tableExists($pdo, 'chat_message_mentions') ? 'chat_message_mentions' : 'chat_mentions';
        $mentionBadgeEnabled = $this->getConfigBool($pdo, 'mention_badge_enabled', true);
        $userCol = $this->columnExists($pdo, 'chat_messages', 'sender_user_id') ? 'sender_user_id' : 'user_id';
        $sql = "SELECT c.id, c.channel_type AS type, c.name,
                       (CASE WHEN c.channel_type IN ('PRIVATE','DM') THEN 1 ELSE 0 END) AS is_private,
                       c.created_at,
                       cm.joined_at, COALESCE(cm.last_read_message_id,0) AS last_read_message_id,
                       " . ($hasSettingTable ? "CASE WHEN pref2.muted_until IS NOT NULL AND pref2.muted_until > NOW() THEN 1 ELSE 0 END" : "0") . " AS is_muted,
                       'ALL' AS notify_level,
                       " . ($hasSettingTable ? "pref2.muted_until" : "NULL") . " AS mute_until,
                       CASE
                         WHEN c.channel_type='DM' THEN
                           (SELECT COALESCE(u.full_name, m2.username, CONCAT('User#', m2.user_id))
                            FROM chat_channel_members m2
                            LEFT JOIN master_system_login u ON u.id=m2.user_id
                            WHERE m2.channel_id=c.id AND m2.user_id<>? LIMIT 1)
                         ELSE CONCAT('#', COALESCE(c.name, 'channel'))
                       END AS display_name,
                       CASE WHEN c.channel_type='DM' THEN
                         (SELECT m2.username FROM chat_channel_members m2 WHERE m2.channel_id=c.id AND m2.user_id<>? LIMIT 1)
                       ELSE NULL END AS dm_partner_username,
                       CASE WHEN c.channel_type='DM' THEN
                         (SELECT m2.user_id FROM chat_channel_members m2 WHERE m2.channel_id=c.id AND m2.user_id<>? LIMIT 1)
                       ELSE NULL END AS dm_partner_user_id,
                       (SELECT COUNT(*) FROM chat_messages m
                        WHERE m.channel_id=c.id AND m.id > COALESCE(cm.last_read_message_id,0)
                          AND COALESCE(m.{$userCol}, 0) <> ?) AS unread_count,
                       " . ($mentionBadgeEnabled ? "(SELECT COUNT(*) FROM $mentionTable mt
                        JOIN chat_messages mm ON mm.id = mt.message_id
                        WHERE mm.channel_id=c.id AND mt.mentioned_user_id=?
                          AND mm.id > COALESCE(cm.last_read_message_id,0))" : "0") . " AS mention_unread_count,
                       (SELECT m2.created_at FROM chat_messages m2 WHERE m2.channel_id=c.id ORDER BY m2.id DESC LIMIT 1) AS last_message_at
                FROM chat_channels c
                LEFT JOIN chat_channel_members cm ON cm.channel_id=c.id AND cm.user_id=?
                $joinSettings
                WHERE (
                    c.channel_type IN ('PUBLIC','PRIVATE') AND (c.channel_type='PUBLIC' OR cm.user_id IS NOT NULL)
                ) OR (
                    c.channel_type='DM' AND cm.user_id IS NOT NULL
                )
                ORDER BY COALESCE(last_message_at, c.created_at) DESC, c.id DESC";
        $params = [$uid, $uid, $uid, $uid];
        if ($mentionBadgeEnabled) $params[] = $uid;
        $params[] = $uid;
        if ($hasSettingTable) $params[] = $uid;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['is_private'] = (int)($r['is_private'] ?? 0);
            $r['unread_count'] = (int)($r['unread_count'] ?? 0);
            $r['last_read_message_id'] = (int)($r['last_read_message_id'] ?? 0);
            $r['mention_unread_count'] = (int)($r['mention_unread_count'] ?? 0);
            $r['is_muted'] = (int)($r['is_muted'] ?? 0);
            $r['notify_level'] = (string)($r['notify_level'] ?? 'ALL');
            $r['mute_until'] = $r['mute_until'] ?? null;
            $r['dm_partner_user_id'] = (int)($r['dm_partner_user_id'] ?? 0);
            $r['joined'] = !empty($r['joined_at']);
            $perm = $this->channelRoleMatrix($pdo, (int)$r['id'], $role);
            $r['perm_read'] = (int)$perm['can_read'];
            $r['perm_send'] = (int)$perm['can_send'];
            $r['perm_pin'] = (int)$perm['can_pin'];
            $r['perm_manage'] = (int)$perm['can_manage'];
        }
        unset($r);
        return $rows;
    }

    /**
     * @return array{messages:array<int,array<string,mixed>>, oldest_id:int, newest_id:int}
     */
    public function listMessages(PDO $pdo, int $uid, string $role, int $channelId, int $limit, int $beforeId, int $afterId, string $q): array
    {
        $limit = max(1, min(100, $limit));
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch) {
            throw new \RuntimeException('Channel not found.');
        }
        if (!$this->canAccessChannel($pdo, $ch, $uid)) {
            throw new \RuntimeException('Forbidden.');
        }
        if (!$this->canRoleAction($pdo, $ch, $role, 'read')) {
            throw new \RuntimeException('Forbidden by channel ACL.');
        }

        $where = ["m.channel_id=?"];
        $params = [$channelId];
        if ($beforeId > 0) {
            $where[] = "m.id < ?";
            $params[] = $beforeId;
        }
        if ($afterId > 0) {
            $where[] = "m.id > ?";
            $params[] = $afterId;
        }
        if ($q !== '') {
            $where[] = "(m.message_text LIKE ? OR m.sender_username LIKE ?)";
            $kw = '%' . $q . '%';
            $params[] = $kw;
            $params[] = $kw;
        }
        $orderDesc = $beforeId > 0 || ($afterId === 0 && $beforeId === 0 && $q === '');
        $msgUserIdCol = $this->columnExists($pdo, 'chat_messages', 'user_id') ? 'm.user_id' : 'm.sender_user_id';
        $hasReply = $this->columnExists($pdo, 'chat_messages', 'reply_to_message_id');
        $msgReplyCol = $hasReply ? 'm.reply_to_message_id' : 'NULL';
        $msgEditCol = $this->columnExists($pdo, 'chat_messages', 'edited_at') ? 'm.edited_at' : 'NULL';
        $msgDelReasonCol = $this->columnExists($pdo, 'chat_messages', 'delete_reason') ? 'm.delete_reason' : ($this->columnExists($pdo, 'chat_messages', 'deleted_reason') ? 'm.deleted_reason' : 'NULL');
        $joinReply = $hasReply ? "LEFT JOIN chat_messages rm ON rm.id = m.reply_to_message_id" : "LEFT JOIN chat_messages rm ON 0";
        $sql = "SELECT m.id, m.channel_id, $msgUserIdCol AS user_id, m.sender_username, $msgReplyCol AS reply_to_message_id, m.message_text, m.created_at, $msgEditCol AS edited_at,
                       m.is_deleted, m.deleted_at, m.deleted_by, $msgDelReasonCol AS delete_reason,
                       d.username AS deleted_by_username,
                       rm.sender_username AS reply_sender_username,
                       rm.message_text AS reply_message_text,
                       rm.is_deleted AS reply_is_deleted
                FROM chat_messages m
                LEFT JOIN master_system_login d ON d.id = m.deleted_by
                $joinReply
                WHERE " . implode(' AND ', $where) . "
                ORDER BY m.id " . ($orderDesc ? 'DESC' : 'ASC') . "
                LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($orderDesc) {
            $rows = array_reverse($rows);
        }
        $replyNames = [];
        foreach ($rows as $r0) {
            $rs = trim((string)($r0['reply_sender_username'] ?? ''));
            if ($rs !== '') {
                $replyNames[] = $rs;
            }
        }
        $senderDispMap = $this->buildSenderDisplayMap($pdo, $rows, $replyNames);
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        $attachments = $this->attachmentsByMessageIds($pdo, $ids);
        $mentions = $this->mentionsByMessageIds($pdo, $ids);

        $out = [];
        $oldest = 0;
        $newest = 0;
        $reactions = $this->reactionsByMessageIds($pdo, $ids, $uid);
        $allowReaction = $this->getConfigBool($pdo, 'allow_reactions', true);
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $oldest = $oldest === 0 ? $id : min($oldest, $id);
            $newest = max($newest, $id);
            $deleted = (int)$r['is_deleted'] === 1;
            $senderUn = (string)($r['sender_username'] ?: ('user#' . (int)$r['user_id']));
            $senderDn = $this->resolveSenderDisplayName($senderDispMap, (int)$r['user_id'], (string)($r['sender_username'] ?? ''));
            $replySn = (string)($r['reply_sender_username'] ?: '');
            $replyDn = $replySn !== '' ? $this->resolveSenderDisplayName($senderDispMap, 0, $replySn) : '';
            $out[] = [
                'id' => $id,
                'channel_id' => (int)$r['channel_id'],
                'user_id' => (int)$r['user_id'],
                'sender_username' => $senderUn,
                'sender_display_name' => $senderDn,
                'message_text' => $deleted ? '[Message deleted by Admin]' : (string)$r['message_text'],
                'reply' => (int)($r['reply_to_message_id'] ?? 0) > 0 ? [
                    'message_id' => (int)$r['reply_to_message_id'],
                    'sender_username' => $replySn,
                    'sender_display_name' => $replyDn,
                    'message_text' => ((int)($r['reply_is_deleted'] ?? 0) === 1) ? '[Message deleted by Admin]' : mb_substr((string)($r['reply_message_text'] ?? ''), 0, 180),
                ] : null,
                'created_at' => (string)$r['created_at'],
                'edited_at' => $r['edited_at'],
                'is_deleted' => $deleted,
                'deleted_at' => $r['deleted_at'],
                'deleted_by' => $r['deleted_by'],
                'deleted_by_username' => $r['deleted_by_username'] ?: null,
                'delete_reason' => $r['delete_reason'],
                'mentions' => $mentions[$id] ?? [],
                'attachments' => $attachments[$id] ?? [],
                'reactions' => $allowReaction ? ($reactions[$id] ?? []) : [],
            ];
        }
        $dmReadReceipt = $this->dmReadReceipt($pdo, $channelId, $uid);
        return [
            'messages' => $out,
            'oldest_id' => $oldest,
            'newest_id' => $newest,
            'dm_read_receipt' => $dmReadReceipt,
        ];
    }

    /**
     * @param array<int,int> $messageIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function attachmentsByMessageIds(PDO $pdo, array $messageIds): array
    {
        if (!$messageIds) return [];
        $in = implode(',', array_fill(0, count($messageIds), '?'));
        $origCol = $this->columnExists($pdo, 'chat_attachments', 'original_filename') ? 'original_filename' : 'original_name';
        $mimeCol = $this->columnExists($pdo, 'chat_attachments', 'mime_type') ? 'mime_type' : 'mime';
        $st = $pdo->prepare("SELECT id, message_id, $origCol AS orig, $mimeCol AS mime, size_bytes
                             FROM chat_attachments
                             WHERE message_id IN ($in)
                             ORDER BY id ASC");
        $st->execute($messageIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $mid = (int)$r['message_id'];
            $out[$mid][] = [
                'id' => (int)$r['id'],
                'original_filename' => (string)($r['orig'] ?? ''),
                'mime_type' => (string)($r['mime'] ?? ''),
                'size_bytes' => (int)$r['size_bytes'],
            ];
        }
        return $out;
    }

    /**
     * @param array<int,int> $messageIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function mentionsByMessageIds(PDO $pdo, array $messageIds): array
    {
        if (!$messageIds) return [];
        $in = implode(',', array_fill(0, count($messageIds), '?'));
        $st = $pdo->prepare("SELECT m.message_id, m.mentioned_user_id, u.username
                             FROM chat_mentions m
                             LEFT JOIN master_system_login u ON u.id = m.mentioned_user_id
                             WHERE m.message_id IN ($in)
                             ORDER BY m.id ASC");
        $st->execute($messageIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $mid = (int)$r['message_id'];
            $out[$mid][] = [
                'mentioned_user_id' => (int)$r['mentioned_user_id'],
                'username' => (string)($r['username'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param array<int,int> $messageIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function reactionsByMessageIds(PDO $pdo, array $messageIds, int $viewerId): array
    {
        if (!$messageIds) return [];
        try {
            $in = implode(',', array_fill(0, count($messageIds), '?'));
            $st = $pdo->prepare("SELECT message_id, emoji, COUNT(*) AS c,
                                        SUM(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS mine
                                 FROM chat_message_reactions
                                 WHERE message_id IN ($in)
                                 GROUP BY message_id, emoji
                                 ORDER BY message_id ASC");
            $st->execute(array_merge([$viewerId], $messageIds));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $mid = (int)$r['message_id'];
                $out[$mid][] = [
                    'emoji' => (string)$r['emoji'],
                    'count' => (int)$r['c'],
                    'mine' => (int)$r['mine'] > 0,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function markRead(PDO $pdo, int $uid, int $channelId, int $lastReadMessageId): int
    {
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch) throw new \RuntimeException('Channel not found.');
        if (!$this->canAccessChannel($pdo, $ch, $uid)) throw new \RuntimeException('Forbidden.');
        $this->ensureMember($pdo, $channelId, $uid);
        if ($lastReadMessageId <= 0) {
            $sx = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE channel_id=?");
            $sx->execute([$channelId]);
            $lastReadMessageId = (int)$sx->fetchColumn();
        }
        $st = $pdo->prepare("UPDATE chat_channel_members
                             SET last_read_message_id = GREATEST(COALESCE(last_read_message_id,0), ?),
                                 last_read_at = NOW()
                             WHERE channel_id=? AND user_id=?");
        $st->execute([max(0, $lastReadMessageId), $channelId, $uid]);
        return max(0, $lastReadMessageId);
    }

    public function enforceSendRate(PDO $pdo, int $uid, string $ip, int $maxIn10Sec = 10): void
    {
        $win = gmdate('YmdHis', (int)(floor(time() / 10) * 10));
        $st = $pdo->prepare("INSERT INTO chat_rate_limits (user_id, ip_address, window_minute, send_count, created_at, updated_at)
                             VALUES (?, ?, ?, 1, NOW(), NOW())
                             ON DUPLICATE KEY UPDATE send_count=send_count+1, updated_at=NOW()");
        $st->execute([$uid > 0 ? $uid : null, $ip, $win]);
        $st2 = $pdo->prepare("SELECT send_count FROM chat_rate_limits WHERE user_id <=> ? AND ip_address=? AND window_minute=? LIMIT 1");
        $st2->execute([$uid > 0 ? $uid : null, $ip, $win]);
        $n = (int)$st2->fetchColumn();
        if ($n > $maxIn10Sec) {
            throw new \RuntimeException('Rate limit exceeded.');
        }
    }

    /**
     * @return array{id:int,mentions:array<int,array{id:int,username:string,full_name:string}>}
     */
    public function sendMessage(
        PDO $pdo,
        int $uid,
        string $role,
        string $username,
        int $channelId,
        string $text,
        array $attachmentFiles,
        string $ip,
        string $rootPath,
        int $replyToMessageId = 0,
        ?string $idempotencyKey = null,
        array $context = []
    ): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 5000) {
            throw new \RuntimeException('message_text must be 1..5000 chars.');
        }
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch) throw new \RuntimeException('Channel not found.');
        if (!$this->canAccessChannel($pdo, $ch, $uid)) throw new \RuntimeException('Forbidden.');
        if (!$this->canRoleAction($pdo, $ch, $role, 'send')) throw new \RuntimeException('Forbidden by channel ACL.');
        if (strtoupper((string)$ch['type']) === 'CHANNEL') {
            $this->ensureMember($pdo, $channelId, $uid);
        }
        if ($replyToMessageId > 0) {
            $sr = $pdo->prepare("SELECT id FROM chat_messages WHERE id=? AND channel_id=? LIMIT 1");
            $sr->execute([$replyToMessageId, $channelId]);
            if (!(int)$sr->fetchColumn()) {
                throw new \RuntimeException('Reply target not found in this channel.');
            }
        }
        $this->enforceSendRate($pdo, $uid, $ip, 10);

        $allowedMimes = $this->attachmentService->allowedMimes($pdo);
        $maxSize = $this->attachmentService->maxSizeBytes($pdo);
        $normalized = $this->attachmentService->normalizeFilesArray($attachmentFiles);
        $idempotencyKey = trim((string)$idempotencyKey);
        if ($idempotencyKey !== '' && mb_strlen($idempotencyKey) > 80) {
            throw new \RuntimeException('idempotency_key too long.');
        }
        $attachmentBytes = 0;
        foreach ($normalized as $f) {
            if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $attachmentBytes += max(0, (int)($f['size'] ?? 0));
            }
        }
        if ($attachmentBytes > 0) {
            $this->assertAttachmentQuota($pdo, $uid, $channelId, $attachmentBytes);
        }
        if ($idempotencyKey !== '' && $this->tableExists($pdo, 'chat_message_idempotency')) {
            $stIdem = $pdo->prepare("SELECT message_id FROM chat_message_idempotency WHERE user_id=? AND channel_id=? AND idempotency_key=? LIMIT 1");
            $stIdem->execute([$uid, $channelId, $idempotencyKey]);
            $existingId = (int)$stIdem->fetchColumn();
            if ($existingId > 0) {
                return ['id' => $existingId, 'mentions' => [], 'idempotent' => true];
            }
        }

        $pdo->beginTransaction();
        try {
            $hasUserId = $this->columnExists($pdo, 'chat_messages', 'user_id');
            $hasSenderUserId = $this->columnExists($pdo, 'chat_messages', 'sender_user_id');
            $uidCol = $hasUserId ? 'user_id' : ($hasSenderUserId ? 'sender_user_id' : 'sender_user_id');
            $hasReply = $this->columnExists($pdo, 'chat_messages', 'reply_to_message_id');
            $hasThreadRoot = $this->columnExists($pdo, 'chat_messages', 'thread_root_message_id');
            $cols = ['channel_id', $uidCol, 'sender_username', 'message_text', 'created_at', 'is_deleted'];
            $placeholders = ['?', '?', '?', '?', 'NOW()', '0'];
            $vals = [$channelId, $uid, $username, $text];
            if ($hasReply) {
                $cols[] = 'reply_to_message_id';
                $placeholders[] = '?';
                $vals[] = $replyToMessageId > 0 ? $replyToMessageId : null;
            }
            $threadRootId = null;
            if ($hasThreadRoot) {
                $cols[] = 'thread_root_message_id';
                $placeholders[] = '?';
                $threadRootId = $replyToMessageId > 0 ? $this->resolveThreadRootId($pdo, $replyToMessageId) : null;
                $vals[] = $threadRootId;
            }
            $ins = $pdo->prepare("INSERT INTO chat_messages (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")");
            $ins->execute($vals);
            $mid = (int)$pdo->lastInsertId();
            if ($threadRootId === null && $hasThreadRoot) {
                $pdo->prepare("UPDATE chat_messages SET thread_root_message_id=? WHERE id=?")->execute([$mid, $mid]);
            }

            $mentions = $this->mentionService->parseMentionUsernames($text);
            $resolved = $this->mentionService->resolveUsersByUsername($pdo, $mentions, 50);
            if ($resolved) {
                $stm = $pdo->prepare("INSERT INTO chat_mentions (message_id, mentioned_user_id, created_at) VALUES (?, ?, NOW())");
                foreach ($resolved as $u) {
                    $stm->execute([$mid, (int)$u['id']]);
                }
            }

            if ($normalized) {
                $origCol = $this->columnExists($pdo, 'chat_attachments', 'original_filename') ? 'original_filename' : 'original_name';
                $storedCol = $this->columnExists($pdo, 'chat_attachments', 'stored_filename') ? 'stored_filename' : 'stored_name';
                $mimeCol = $this->columnExists($pdo, 'chat_attachments', 'mime_type') ? 'mime_type' : 'mime';
                $hasChannelId = $this->columnExists($pdo, 'chat_attachments', 'channel_id');
                $cols = ['message_id', $origCol, $storedCol, $mimeCol, 'size_bytes', 'sha256', 'storage_path', 'created_at'];
                $vals = ['?', '?', '?', '?', '?', '?', '?', 'NOW()'];
                if ($hasChannelId) {
                    array_splice($cols, 1, 0, ['channel_id']);
                    array_splice($vals, 1, 0, ['?']);
                }
                $insAtt = $pdo->prepare("INSERT INTO chat_attachments (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")");
                foreach ($normalized as $f) {
                    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                    $meta = $this->attachmentService->validateFileMeta($f, $allowedMimes, $maxSize);
                    $saved = $this->attachmentService->saveFileToStorage($rootPath, $meta);
                    $row = [$mid, (string)$meta['original_filename'], (string)$saved['stored_filename'], (string)$meta['mime_type'], (int)$meta['size_bytes'], (string)$saved['sha256'], (string)$saved['storage_path']];
                    if ($hasChannelId) {
                        array_splice($row, 1, 0, [$channelId]);
                    }
                    $insAtt->execute($row);
                }
            }

            if ($attachmentBytes > 0) {
                $this->consumeAttachmentQuota($pdo, $uid, $channelId, $attachmentBytes);
            }
            if ($idempotencyKey !== '' && $this->tableExists($pdo, 'chat_message_idempotency')) {
                $pdo->prepare("INSERT INTO chat_message_idempotency (user_id, channel_id, idempotency_key, message_id, created_at)
                               VALUES (?, ?, ?, ?, NOW())")
                    ->execute([$uid, $channelId, $idempotencyKey, $mid]);
            }
            if ($this->tableExists($pdo, 'chat_message_context')) {
                $entityType = strtoupper(trim((string)($context['entity_type'] ?? '')));
                $entityId = trim((string)($context['entity_id'] ?? ''));
                $entityCode = trim((string)($context['entity_code'] ?? ''));
                $entityUrl = trim((string)($context['entity_url'] ?? ''));
                if ($entityType !== '') {
                    $pdo->prepare("INSERT INTO chat_message_context (message_id, entity_type, entity_id, entity_code, entity_url, created_at)
                                   VALUES (?, ?, ?, ?, ?, NOW())")
                        ->execute([$mid, mb_substr($entityType, 0, 40), $entityId !== '' ? mb_substr($entityId, 0, 80) : null, $entityCode !== '' ? mb_substr($entityCode, 0, 120) : null, $entityUrl !== '' ? mb_substr($entityUrl, 0, 500) : null]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return [
            'id' => $mid,
            'mentions' => $resolved ?? [],
            'idempotent' => false,
        ];
    }

    public function deleteMessage(PDO $pdo, int $uid, string $role, int $messageId, string $reason): array
    {
        if (!$this->isAdminLike($role)) {
            throw new \RuntimeException('Only ADMIN/SUPERADMIN can delete.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Delete reason is required.');
        }
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id, channel_id, is_deleted FROM chat_messages WHERE id=? FOR UPDATE");
            $st->execute([$messageId]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m) throw new \RuntimeException('Message not found.');
            if ((int)$m['is_deleted'] === 1) {
                $pdo->commit();
                return ['id' => $messageId, 'idempotent' => true, 'channel_id' => (int)$m['channel_id']];
            }
            $hasDeletedReason = $this->columnExists($pdo, 'chat_messages', 'deleted_reason');
            if ($hasDeletedReason) {
                $up = $pdo->prepare("UPDATE chat_messages SET is_deleted=1, deleted_at=NOW(), deleted_by=?, delete_reason=?, deleted_reason=? WHERE id=?");
                $up->execute([$uid, $reason, $reason, $messageId]);
            } else {
                $up = $pdo->prepare("UPDATE chat_messages SET is_deleted=1, deleted_at=NOW(), deleted_by=?, delete_reason=? WHERE id=?");
                $up->execute([$uid, $reason, $messageId]);
            }
            $pdo->commit();
            return ['id' => $messageId, 'idempotent' => false, 'channel_id' => (int)$m['channel_id']];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function toggleReaction(PDO $pdo, int $uid, int $messageId, string $emoji): array
    {
        if (!$this->getConfigBool($pdo, 'allow_reactions', true)) {
            throw new \RuntimeException('Reactions disabled by admin.');
        }
        $emoji = trim($emoji);
        if ($emoji === '' || mb_strlen($emoji) > 16) {
            throw new \RuntimeException('Invalid emoji.');
        }
        $st = $pdo->prepare("SELECT channel_id FROM chat_messages WHERE id=? LIMIT 1");
        $st->execute([$messageId]);
        $channelId = (int)$st->fetchColumn();
        if ($channelId <= 0) throw new \RuntimeException('Message not found.');
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid)) throw new \RuntimeException('Forbidden.');
        if (!$this->isAllowedEmoji($pdo, $emoji)) {
            throw new \RuntimeException('Emoji not allowed by policy.');
        }

        $pdo->beginTransaction();
        try {
            $sx = $pdo->prepare("SELECT id FROM chat_message_reactions WHERE message_id=? AND user_id=? AND emoji=? LIMIT 1 FOR UPDATE");
            $sx->execute([$messageId, $uid, $emoji]);
            $rid = (int)$sx->fetchColumn();
            $toggledOn = false;
            if ($rid > 0) {
                $pdo->prepare("DELETE FROM chat_message_reactions WHERE id=?")->execute([$rid]);
            } else {
                $pdo->prepare("INSERT INTO chat_message_reactions (message_id,user_id,emoji,created_at) VALUES (?,?,?,NOW())")
                    ->execute([$messageId, $uid, $emoji]);
                $toggledOn = true;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['message_id' => $messageId, 'emoji' => $emoji, 'toggled_on' => $toggledOn];
    }

    public function typingPing(PDO $pdo, int $uid, string $role, int $channelId): void
    {
        if (!$this->getConfigBool($pdo, 'allow_typing_indicator', true)) {
            return;
        }
        if (!$this->tableExists($pdo, 'chat_typing_status')) {
            return;
        }
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid) || !$this->canRoleAction($pdo, $ch, $role, 'read')) {
            throw new \RuntimeException('Forbidden.');
        }
        if (!$this->canRoleAction($pdo, $ch, $role, 'send')) {
            throw new \RuntimeException('Forbidden by channel ACL.');
        }
        $pdo->prepare("INSERT INTO chat_typing_status (channel_id, user_id, updated_at)
                       VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE updated_at=NOW()")
            ->execute([$channelId, $uid]);
    }

    /**
     * @return array<int,array{id:int,username:string,full_name:string}>
     */
    public function typingList(PDO $pdo, int $uid, string $role, int $channelId): array
    {
        if (!$this->getConfigBool($pdo, 'allow_typing_indicator', true)) return [];
        if (!$this->tableExists($pdo, 'chat_typing_status')) return [];
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid)) {
            throw new \RuntimeException('Forbidden.');
        }
        if (!$this->canRoleAction($pdo, $ch, $role, 'read')) {
            throw new \RuntimeException('Forbidden by channel ACL.');
        }
        $st = $pdo->prepare("SELECT t.user_id AS id, u.username, COALESCE(u.full_name,u.username) AS full_name
                             FROM chat_typing_status t
                             LEFT JOIN master_system_login u ON u.id = t.user_id
                             WHERE t.channel_id=? AND t.user_id<>?
                               AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 8 SECOND)
                             ORDER BY t.updated_at DESC
                             LIMIT 8");
        $st->execute([$channelId, $uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'username' => (string)($r['username'] ?? ''),
                'full_name' => (string)($r['full_name'] ?? ''),
            ];
        }
        return $out;
    }

    public function pinMessage(PDO $pdo, int $uid, string $role, int $messageId): array
    {
        if (!$this->getConfigBool($pdo, 'allow_message_pin', true)) {
            throw new \RuntimeException('Pin disabled by admin.');
        }
        $sm = $pdo->prepare("SELECT id, channel_id FROM chat_messages WHERE id=? LIMIT 1");
        $sm->execute([$messageId]);
        $m = $sm->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new \RuntimeException('Message not found.');
        $channelId = (int)$m['channel_id'];
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid)) {
            throw new \RuntimeException('Forbidden.');
        }
        if (!$this->canRoleAction($pdo, $ch, $role, 'pin')) {
            throw new \RuntimeException('Forbidden by channel ACL.');
        }
        $policy = strtoupper((string)($ch['pin_policy'] ?? 'ADMIN_ONLY'));
        if (!in_array($policy, ['ADMIN_ONLY', 'MEMBER'], true)) {
            $policy = 'ADMIN_ONLY';
        }
        if ($policy === 'ADMIN_ONLY' && !$this->isAdminLike($role)) {
            throw new \RuntimeException('Only ADMIN/SUPERADMIN can pin in this channel.');
        }
        $st = $pdo->prepare("INSERT INTO chat_pins (channel_id,message_id,pinned_by,pinned_at,is_active)
                             VALUES (?,?,?,NOW(),1)
                             ON DUPLICATE KEY UPDATE pinned_by=VALUES(pinned_by), pinned_at=NOW(), is_active=1, unpin_by=NULL, unpin_at=NULL");
        $st->execute([$channelId, $messageId, $uid]);
        return ['channel_id' => $channelId, 'message_id' => $messageId, 'pinned' => true, 'pin_policy' => $policy];
    }

    public function unpinMessage(PDO $pdo, int $uid, string $role, int $messageId): array
    {
        $sm = $pdo->prepare("SELECT id, channel_id FROM chat_messages WHERE id=? LIMIT 1");
        $sm->execute([$messageId]);
        $m = $sm->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new \RuntimeException('Message not found.');
        $channelId = (int)$m['channel_id'];
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid)) {
            throw new \RuntimeException('Forbidden.');
        }
        if (!$this->canRoleAction($pdo, $ch, $role, 'pin')) {
            throw new \RuntimeException('Forbidden by channel ACL.');
        }
        $policy = strtoupper((string)($ch['pin_policy'] ?? 'ADMIN_ONLY'));
        if (!in_array($policy, ['ADMIN_ONLY', 'MEMBER'], true)) {
            $policy = 'ADMIN_ONLY';
        }
        if ($policy === 'ADMIN_ONLY' && !$this->isAdminLike($role)) {
            throw new \RuntimeException('Only ADMIN/SUPERADMIN can unpin in this channel.');
        }
        $st = $pdo->prepare("UPDATE chat_pins SET is_active=0, unpin_by=?, unpin_at=NOW()
                             WHERE message_id=? AND is_active=1");
        $st->execute([$uid, $messageId]);
        return ['message_id' => $messageId, 'unpinned' => true];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listPinned(PDO $pdo, int $uid, string $role, int $channelId): array
    {
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid)) throw new \RuntimeException('Forbidden.');
        if (!$this->canRoleAction($pdo, $ch, $role, 'read')) throw new \RuntimeException('Forbidden by channel ACL.');
        $msgUidCol = $this->columnExists($pdo, 'chat_messages', 'user_id') ? 'm.user_id' : 'm.sender_user_id';
        $st = $pdo->prepare("SELECT p.message_id, p.pinned_at, p.pinned_by,
                                    m.sender_username, {$msgUidCol} AS user_id, m.message_text, m.is_deleted
                             FROM chat_pins p
                             JOIN chat_messages m ON m.id = p.message_id
                             WHERE p.channel_id=? AND p.is_active=1
                             ORDER BY p.pinned_at DESC
                             LIMIT 20");
        $st->execute([$channelId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $pinMap = $this->buildSenderDisplayMap($pdo, $rows);
        $out = [];
        foreach ($rows as $r) {
            $sun = (string)($r['sender_username'] ?: '');
            $out[] = [
                'message_id' => (int)$r['message_id'],
                'pinned_at' => (string)$r['pinned_at'],
                'pinned_by' => (int)$r['pinned_by'],
                'sender_username' => $sun,
                'sender_display_name' => $this->resolveSenderDisplayName($pinMap, (int)($r['user_id'] ?? 0), $sun),
                'message_text' => ((int)($r['is_deleted'] ?? 0) === 1) ? '[Message deleted by Admin]' : mb_substr((string)$r['message_text'], 0, 240),
            ];
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function threadMessages(PDO $pdo, int $uid, string $role, int $messageId): array
    {
        $st = $pdo->prepare("SELECT channel_id, id FROM chat_messages WHERE id=? LIMIT 1");
        $st->execute([$messageId]);
        $root = $st->fetch(PDO::FETCH_ASSOC);
        if (!$root) {
            throw new \RuntimeException('Message not found.');
        }
        $channelId = (int)$root['channel_id'];
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid)) {
            throw new \RuntimeException('Forbidden.');
        }
        if (!$this->canRoleAction($pdo, $ch, $role, 'read')) {
            throw new \RuntimeException('Forbidden by channel ACL.');
        }
        $rootId = $this->resolveThreadRootId($pdo, $messageId);
        $tUidCol = $this->columnExists($pdo, 'chat_messages', 'user_id') ? 'm.user_id' : 'm.sender_user_id';
        if ($this->columnExists($pdo, 'chat_messages', 'thread_root_message_id')) {
            $sql = "SELECT m.id, m.channel_id, {$tUidCol} AS user_id, m.sender_username, m.reply_to_message_id, m.message_text, m.created_at, m.is_deleted
                    FROM chat_messages m
                    WHERE m.thread_root_message_id=? OR m.id=?
                    ORDER BY m.created_at ASC, m.id ASC
                    LIMIT 500";
            $sx = $pdo->prepare($sql);
            $sx->execute([$rootId, $rootId]);
        } else {
            $sql = "SELECT m.id, m.channel_id, {$tUidCol} AS user_id, m.sender_username, m.reply_to_message_id, m.message_text, m.created_at, m.is_deleted
                    FROM chat_messages m
                    WHERE m.id=? OR m.reply_to_message_id=?
                    ORDER BY m.created_at ASC, m.id ASC
                    LIMIT 200";
            $sx = $pdo->prepare($sql);
            $sx->execute([$messageId, $messageId]);
        }
        $rows = $sx->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $thrMap = $this->buildSenderDisplayMap($pdo, $rows);
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        $reactions = $this->reactionsByMessageIds($pdo, $ids, $uid);
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $uidR = (int)$r['user_id'];
            $sun = (string)($r['sender_username'] ?: ('user#' . $uidR));
            $out[] = [
                'id' => $id,
                'channel_id' => (int)$r['channel_id'],
                'user_id' => $uidR,
                'sender_username' => $sun,
                'sender_display_name' => $this->resolveSenderDisplayName($thrMap, $uidR, (string)($r['sender_username'] ?? '')),
                'reply_to_message_id' => (int)($r['reply_to_message_id'] ?? 0),
                'message_text' => ((int)($r['is_deleted'] ?? 0) === 1) ? '[Message deleted by Admin]' : (string)$r['message_text'],
                'created_at' => (string)$r['created_at'],
                'is_deleted' => (int)($r['is_deleted'] ?? 0) === 1,
                'reactions' => $reactions[$id] ?? [],
            ];
        }
        return $out;
    }

    /**
     * @return array<int,array{emoji:string,label:string}>
     */
    public function listEmojiSet(PDO $pdo): array
    {
        $defaults = [
            ['emoji' => '👍', 'label' => 'thumbs_up'],
            ['emoji' => '❤️', 'label' => 'heart'],
            ['emoji' => '😂', 'label' => 'joy'],
            ['emoji' => '🎉', 'label' => 'tada'],
            ['emoji' => '✅', 'label' => 'check'],
            ['emoji' => '🔥', 'label' => 'fire'],
        ];
        if (!$this->tableExists($pdo, 'chat_custom_emojis')) {
            return $defaults;
        }
        try {
            $st = $pdo->query("SELECT emoji_code, emoji_char FROM chat_custom_emojis WHERE is_active=1 ORDER BY id ASC LIMIT 100");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as $r) {
                $defaults[] = [
                    'emoji' => (string)($r['emoji_char'] ?? ''),
                    'label' => (string)($r['emoji_code'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            // keep defaults
        }
        $uniq = [];
        $out = [];
        foreach ($defaults as $d) {
            $e = trim((string)$d['emoji']);
            if ($e === '' || isset($uniq[$e])) continue;
            $uniq[$e] = true;
            $out[] = ['emoji' => $e, 'label' => (string)$d['label']];
        }
        return $out;
    }

    public function setPresence(PDO $pdo, int $uid, string $status): void
    {
        $presenceTable = $this->tableExists($pdo, 'chat_user_presence') ? 'chat_user_presence' : ($this->tableExists($pdo, 'chat_presence') ? 'chat_presence' : '');
        if ($presenceTable === '') return;
        $maskedIp = isset($_SERVER['REMOTE_ADDR']) ? $this->maskIp((string)$_SERVER['REMOTE_ADDR']) : '';
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($presenceTable === 'chat_user_presence') {
            $st = $pdo->prepare("INSERT INTO chat_user_presence (user_id,last_seen_at,last_ip_masked,last_user_agent,updated_at)
                                 VALUES (?, NOW(), ?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE last_seen_at=NOW(), last_ip_masked=VALUES(last_ip_masked), last_user_agent=VALUES(last_user_agent), updated_at=NOW()");
            $st->execute([$uid, $maskedIp !== '' ? mb_substr($maskedIp, 0, 64) : null, $ua !== '' ? mb_substr($ua, 0, 255) : null]);
            return;
        }
        $status = strtoupper(trim($status));
        if (!in_array($status, ['ONLINE', 'AWAY', 'OFFLINE'], true)) $status = 'ONLINE';
        $pdo->prepare("INSERT INTO chat_presence (user_id,status,last_seen_at)
                       VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE status=VALUES(status), last_seen_at=NOW()")
            ->execute([$uid, $status]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function channelPresence(PDO $pdo, int $uid, string $role, int $channelId): array
    {
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid)) {
            throw new \RuntimeException('Forbidden.');
        }
        if (!$this->canRoleAction($pdo, $ch, $role, 'read')) {
            throw new \RuntimeException('Forbidden by channel ACL.');
        }
        $onlineWindowSec = max(60, min(120, (int)$this->getConfig($pdo, 'presence_online_window_seconds', '90')));
        if ($this->tableExists($pdo, 'chat_user_presence')) {
            $sql = "SELECT m.user_id AS id, u.username, COALESCE(u.full_name,u.username) AS full_name,
                           CASE WHEN p.last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND) THEN 'ONLINE' ELSE 'OFFLINE' END AS status,
                           p.last_seen_at
                    FROM chat_channel_members m
                    LEFT JOIN master_system_login u ON u.id = m.user_id
                    LEFT JOIN chat_user_presence p ON p.user_id = m.user_id
                    WHERE m.channel_id=?
                    ORDER BY m.user_id ASC";
        } elseif ($this->tableExists($pdo, 'chat_presence')) {
            $sql = "SELECT m.user_id AS id, u.username, COALESCE(u.full_name,u.username) AS full_name,
                           CASE WHEN p.last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND) THEN 'ONLINE' ELSE COALESCE(p.status, 'OFFLINE') END AS status,
                           p.last_seen_at
                    FROM chat_channel_members m
                    LEFT JOIN master_system_login u ON u.id = m.user_id
                    LEFT JOIN chat_presence p ON p.user_id = m.user_id
                    WHERE m.channel_id=?
                    ORDER BY m.user_id ASC";
        } else {
            return [];
        }
        $st = $pdo->prepare($sql);
        $st->execute([$onlineWindowSec, $channelId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['status'] = strtoupper((string)$r['status']);
        }
        unset($r);
        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function getChannelPreference(PDO $pdo, int $uid, string $role, int $channelId): array
    {
        $settingsTable = $this->tableExists($pdo, 'chat_user_channel_settings') ? 'chat_user_channel_settings' : ($this->tableExists($pdo, 'chat_user_channel_prefs') ? 'chat_user_channel_prefs' : '');
        if ($settingsTable === '') {
            return ['is_muted' => 0, 'notify_level' => 'ALL', 'mute_until' => null];
        }
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid) || !$this->canRoleAction($pdo, $ch, $role, 'read')) {
            throw new \RuntimeException('Forbidden.');
        }
        if ($settingsTable === 'chat_user_channel_settings') {
            $hasNotify = $this->columnExists($pdo, 'chat_user_channel_settings', 'notification_level');
            $notifyCol = $hasNotify ? 'notification_level AS notify_level' : "'ALL' AS notify_level";
            $st = $pdo->prepare("SELECT CASE WHEN muted_until IS NOT NULL AND muted_until > NOW() THEN 1 ELSE 0 END AS is_muted,
                                        $notifyCol, muted_until AS mute_until
                                 FROM chat_user_channel_settings
                                 WHERE channel_id=? AND user_id=? LIMIT 1");
            $st->execute([$channelId, $uid]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } else {
            $st = $pdo->prepare("SELECT is_muted, notify_level, mute_until
                                 FROM chat_user_channel_prefs
                                 WHERE channel_id=? AND user_id=? LIMIT 1");
            $st->execute([$channelId, $uid]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        return [
            'is_muted' => (int)($r['is_muted'] ?? 0),
            'notify_level' => (string)($r['notify_level'] ?? 'ALL'),
            'mute_until' => $r['mute_until'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function setChannelPreference(PDO $pdo, int $uid, string $role, int $channelId, int $isMuted, string $notifyLevel, ?string $muteUntil): array
    {
        $settingsTable = $this->tableExists($pdo, 'chat_user_channel_settings') ? 'chat_user_channel_settings' : ($this->tableExists($pdo, 'chat_user_channel_prefs') ? 'chat_user_channel_prefs' : '');
        if ($settingsTable === '') {
            return ['is_muted' => 0, 'notify_level' => 'ALL', 'mute_until' => null];
        }
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid)) {
            throw new \RuntimeException('Forbidden.');
        }
        $notifyLevel = strtoupper(trim($notifyLevel));
        if (!in_array($notifyLevel, ['ALL', 'MENTIONS', 'NONE'], true)) {
            $notifyLevel = 'ALL';
        }
        if ($settingsTable === 'chat_user_channel_settings') {
            $effectiveMuteUntil = $muteUntil;
            if ($isMuted && $effectiveMuteUntil === null) {
                $effectiveMuteUntil = date('Y-m-d H:i:s', strtotime('+8 hours'));
            }
            if (!$isMuted) {
                $effectiveMuteUntil = null;
            }
            $hasNotify = $this->columnExists($pdo, 'chat_user_channel_settings', 'notification_level');
            if ($hasNotify) {
                $st = $pdo->prepare("INSERT INTO chat_user_channel_settings (channel_id,user_id,muted_until,notification_level,updated_at)
                                     VALUES (?,?,?,?,NOW())
                                     ON DUPLICATE KEY UPDATE muted_until=VALUES(muted_until), notification_level=VALUES(notification_level), updated_at=NOW()");
                $st->execute([$channelId, $uid, $effectiveMuteUntil, $notifyLevel]);
            } else {
                $st = $pdo->prepare("INSERT INTO chat_user_channel_settings (channel_id,user_id,muted_until)
                                     VALUES (?,?,?)
                                     ON DUPLICATE KEY UPDATE muted_until=VALUES(muted_until)");
                $st->execute([$channelId, $uid, $effectiveMuteUntil]);
            }
        } else {
            $st = $pdo->prepare("INSERT INTO chat_user_channel_prefs (channel_id,user_id,is_muted,notify_level,mute_until,updated_at)
                                 VALUES (?,?,?,?,?,NOW())
                                 ON DUPLICATE KEY UPDATE is_muted=VALUES(is_muted), notify_level=VALUES(notify_level), mute_until=VALUES(mute_until), updated_at=NOW()");
            $st->execute([$channelId, $uid, $isMuted ? 1 : 0, $notifyLevel, $muteUntil]);
        }
        return $this->getChannelPreference($pdo, $uid, $role, $channelId);
    }

    public function setChannelPinPolicy(PDO $pdo, int $uid, string $role, int $channelId, string $policy): array
    {
        if (!$this->isAdminLike($role)) {
            throw new \RuntimeException('Only ADMIN/SUPERADMIN can update pin policy.');
        }
        $policy = strtoupper(trim($policy));
        if (!in_array($policy, ['ADMIN_ONLY', 'MEMBER'], true)) {
            throw new \RuntimeException('Invalid pin policy.');
        }
        if (!$this->columnExists($pdo, 'chat_channels', 'pin_policy')) {
            throw new \RuntimeException('pin_policy column not available.');
        }
        $st = $pdo->prepare("UPDATE chat_channels SET pin_policy=? WHERE id=?");
        $st->execute([$policy, $channelId]);
        return ['channel_id' => $channelId, 'pin_policy' => $policy];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getChannelAcl(PDO $pdo, int $uid, string $role, int $channelId): array
    {
        if (!$this->isAdminLike($role)) {
            throw new \RuntimeException('Only ADMIN/SUPERADMIN can view ACL.');
        }
        if (!$this->tableExists($pdo, 'chat_channel_acl')) {
            return [];
        }
        $st = $pdo->prepare("SELECT role_code, can_read, can_send, can_pin, can_manage, updated_at
                             FROM chat_channel_acl
                             WHERE channel_id=?
                             ORDER BY role_code ASC");
        $st->execute([$channelId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['can_read'] = (int)$r['can_read'];
            $r['can_send'] = (int)$r['can_send'];
            $r['can_pin'] = (int)$r['can_pin'];
            $r['can_manage'] = (int)$r['can_manage'];
        }
        unset($r);
        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function setChannelAcl(PDO $pdo, int $uid, string $role, int $channelId, array $rows): void
    {
        if (!$this->isAdminLike($role)) {
            throw new \RuntimeException('Only ADMIN/SUPERADMIN can update ACL.');
        }
        if (!$this->tableExists($pdo, 'chat_channel_acl')) {
            throw new \RuntimeException('ACL table not available.');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM chat_channel_acl WHERE channel_id=?")->execute([$channelId]);
            $ins = $pdo->prepare("INSERT INTO chat_channel_acl
                (channel_id, role_code, can_read, can_send, can_pin, can_manage, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            foreach ($rows as $r) {
                $roleCode = strtoupper(trim((string)($r['role_code'] ?? '')));
                if ($roleCode === '') continue;
                $ins->execute([
                    $channelId,
                    $roleCode,
                    !empty($r['can_read']) ? 1 : 0,
                    !empty($r['can_send']) ? 1 : 0,
                    !empty($r['can_pin']) ? 1 : 0,
                    !empty($r['can_manage']) ? 1 : 0,
                    $uid,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function searchUsers(PDO $pdo, string $q, int $limit = 8): array
    {
        $limit = max(1, min(15, $limit));
        $q = trim($q);
        $kw = '%' . $q . '%';
        $rows = [];
        try {
            $st = $pdo->prepare("SELECT id, username, COALESCE(full_name, username) AS full_name, COALESCE(department,'') AS department, COALESCE(office_code,'') AS office_code
                                 FROM master_system_login
                                 WHERE status='active' AND (username LIKE ? OR full_name LIKE ?)
                                 ORDER BY username ASC
                                 LIMIT $limit");
            $st->execute([$kw, $kw]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $rows = [];
        }
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
        }
        unset($r);
        return $rows;
    }

    public function getAttachmentForDownload(PDO $pdo, int $uid, int $attachmentId, string $rootPath): array
    {
        $st = $pdo->prepare("SELECT a.*, m.channel_id
                             FROM chat_attachments a
                             JOIN chat_messages m ON m.id = a.message_id
                             WHERE a.id=? LIMIT 1");
        $st->execute([$attachmentId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new \RuntimeException('Attachment not found.');
        $ch = $this->channelById($pdo, (int)$a['channel_id']);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid)) {
            throw new \RuntimeException('Forbidden.');
        }
        $storagePath = (string)($a['storage_path'] ?? '');
        $abs = rtrim($rootPath, '/') . '/' . ltrim($storagePath, '/');
        if (!is_file($abs)) {
            throw new \RuntimeException('Attachment file missing.');
        }
        return [
            'id' => (int)$a['id'],
            'original_filename' => (string)($a['original_filename'] ?? $a['original_name'] ?? ''),
            'mime_type' => (string)($a['mime_type'] ?? $a['mime'] ?? ''),
            'size_bytes' => (int)$a['size_bytes'],
            'abs_path' => $abs,
            'channel_id' => (int)$a['channel_id'],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function dmReadReceipt(PDO $pdo, int $channelId, int $uid): ?array
    {
        if (!$this->getConfigBool($pdo, 'allow_dm_read_receipt', true)) {
            return null;
        }
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || strtoupper((string)($ch['type'] ?? '')) !== 'DM') {
            return null;
        }
        $partnerId = ((int)$ch['dm_user_low'] === $uid) ? (int)$ch['dm_user_high'] : (int)$ch['dm_user_low'];
        if ($partnerId <= 0) return null;
        $st = $pdo->prepare("SELECT last_read_message_id, last_read_at FROM chat_channel_members WHERE channel_id=? AND user_id=? LIMIT 1");
        $st->execute([$channelId, $partnerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $u = $pdo->prepare("SELECT username, COALESCE(full_name, username) AS full_name FROM master_system_login WHERE id=? LIMIT 1");
        $u->execute([$partnerId]);
        $usr = $u->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'partner_user_id' => $partnerId,
            'partner_username' => (string)($usr['username'] ?? ('user#' . $partnerId)),
            'partner_full_name' => (string)($usr['full_name'] ?? ''),
            'partner_last_read_message_id' => (int)($r['last_read_message_id'] ?? 0),
            'partner_last_read_at' => $r['last_read_at'] ?? null,
        ];
    }

    public function retentionPurgeSoftDeleted(PDO $pdo, int $batch = 500): array
    {
        $batch = max(1, min(500, $batch));
        $channelPurge = $this->retentionPurgeByChannelPolicy($pdo, $batch);
        $mode = $this->getConfig($pdo, 'retention_mode', 'purge_soft_deleted');
        if ($mode !== 'purge_soft_deleted') {
            return [
                'purged_messages' => (int)$channelPurge['purged_messages'],
                'purged_attachments' => (int)$channelPurge['purged_attachments'],
                'mode' => $mode,
                'channel_policy' => $channelPurge,
            ];
        }
        $days = max(1, (int)$this->getConfig($pdo, 'purge_soft_deleted_after_days', '90'));

        $st = $pdo->prepare("SELECT id FROM chat_messages
                             WHERE is_deleted=1 AND deleted_at IS NOT NULL
                               AND deleted_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
                             ORDER BY id ASC
                             LIMIT $batch");
        $st->execute([$days]);
        $ids = array_map(static fn($r) => (int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        if (!$ids) {
            return [
                'purged_messages' => (int)$channelPurge['purged_messages'],
                'purged_attachments' => (int)$channelPurge['purged_attachments'],
                'mode' => $mode,
                'channel_policy' => $channelPurge,
            ];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        $stAtt = $pdo->prepare("SELECT id, storage_path FROM chat_attachments WHERE message_id IN ($in)");
        $stAtt->execute($ids);
        $atts = $stAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $deletedFiles = 0;
        $root = defined('RMI_ROOT') ? (string)RMI_ROOT : dirname(__DIR__, 2);
        foreach ($atts as $a) {
            $abs = rtrim($root, '/') . '/' . ltrim((string)$a['storage_path'], '/');
            if (is_file($abs) && @unlink($abs)) {
                $deletedFiles++;
            }
        }
        $delA = $pdo->prepare("DELETE FROM chat_attachments WHERE message_id IN ($in)");
        $delA->execute($ids);
        $delM = $pdo->prepare("DELETE FROM chat_messages WHERE id IN ($in)");
        $delM->execute($ids);

        return [
            'purged_messages' => count($ids) + (int)$channelPurge['purged_messages'],
            'purged_attachments' => $deletedFiles + (int)$channelPurge['purged_attachments'],
            'mode' => $mode,
            'channel_policy' => $channelPurge,
        ];
    }

    public function markAllRead(PDO $pdo, int $uid): array
    {
        $st = $pdo->prepare("SELECT id FROM chat_channels WHERE type='CHANNEL' OR (type='DM' AND (dm_user_low=? OR dm_user_high=?))");
        $st->execute([$uid, $uid]);
        $channelIds = array_map(static fn($r) => (int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $updated = 0;
        foreach ($channelIds as $channelId) {
            try {
                $last = $this->markRead($pdo, $uid, $channelId, 0);
                if ($last >= 0) {
                    $updated++;
                }
            } catch (\Throwable $e) {
                // skip channels not accessible anymore
            }
        }
        return ['updated_channels' => $updated];
    }

    public function setChannelMute(PDO $pdo, int $uid, int $channelId, int $durationSeconds): array
    {
        $durationSeconds = max(60, min(86400, $durationSeconds));
        $muteUntil = date('Y-m-d H:i:s', time() + $durationSeconds);
        return $this->setChannelPreference($pdo, $uid, 'USER', $channelId, 1, 'NONE', $muteUntil);
    }

    public function clearChannelMute(PDO $pdo, int $uid, int $channelId): array
    {
        return $this->setChannelPreference($pdo, $uid, 'USER', $channelId, 0, 'ALL', null);
    }

    /**
     * @param array<int,int> $userIds
     * @return array<int,array{user_id:int,status:string,last_seen_at:?string}>
     */
    public function presenceBatch(PDO $pdo, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $userIds), static fn($v) => $v > 0)));
        if (!$userIds) return [];
        $window = max(60, min(120, (int)$this->getConfig($pdo, 'presence_online_window_seconds', '90')));
        $in = implode(',', array_fill(0, count($userIds), '?'));
        if ($this->tableExists($pdo, 'chat_user_presence')) {
            $sql = "SELECT user_id, last_seen_at,
                           CASE WHEN last_seen_at >= DATE_SUB(NOW(), INTERVAL $window SECOND) THEN 'ONLINE' ELSE 'OFFLINE' END AS status
                    FROM chat_user_presence
                    WHERE user_id IN ($in)";
        } elseif ($this->tableExists($pdo, 'chat_presence')) {
            $sql = "SELECT user_id, last_seen_at,
                           CASE WHEN last_seen_at >= DATE_SUB(NOW(), INTERVAL $window SECOND) THEN 'ONLINE' ELSE 'OFFLINE' END AS status
                    FROM chat_presence
                    WHERE user_id IN ($in)";
        } else {
            return [];
        }
        $st = $pdo->prepare($sql);
        $st->execute($userIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['user_id']] = [
                'user_id' => (int)$r['user_id'],
                'status' => (string)$r['status'],
                'last_seen_at' => $r['last_seen_at'] ?? null,
            ];
        }
        $out = [];
        foreach ($userIds as $uid) {
            $out[] = $map[$uid] ?? ['user_id' => $uid, 'status' => 'OFFLINE', 'last_seen_at' => null];
        }
        return $out;
    }

    /**
     * @return array{messages:array<int,array<string,mixed>>,total:int}
     */
    public function searchMessages(PDO $pdo, int $uid, string $role, array $filters): array
    {
        $q = trim((string)($filters['q'] ?? ''));
        $channelId = (int)($filters['channel_id'] ?? 0);
        $userId = (int)($filters['user_id'] ?? 0);
        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        $dateTo = trim((string)($filters['date_to'] ?? ''));
        $hasAttachment = (int)($filters['has_attachment'] ?? -1);
        $hasMention = (int)($filters['has_mention'] ?? -1);
        $limit = max(1, min(100, (int)($filters['limit'] ?? 30)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $where = ["(c.type='CHANNEL' OR (c.type='DM' AND (c.dm_user_low=? OR c.dm_user_high=?)))"];
        $params = [$uid, $uid];
        if ($channelId > 0) {
            $where[] = "m.channel_id=?";
            $params[] = $channelId;
        }
        if ($userId > 0) {
            $where[] = "m.user_id=?";
            $params[] = $userId;
        }
        if ($dateFrom !== '') {
            $where[] = "m.created_at>=?";
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = "m.created_at<=?";
            $params[] = $dateTo . ' 23:59:59';
        }
        if ($hasAttachment === 1) {
            $where[] = "EXISTS (SELECT 1 FROM chat_attachments ca WHERE ca.message_id=m.id)";
        }
        if ($hasMention === 1) {
            $where[] = "EXISTS (SELECT 1 FROM chat_mentions cm WHERE cm.message_id=m.id)";
        }
        if ($q !== '') {
            $attachNameCol = $this->columnExists($pdo, 'chat_attachments', 'original_filename') ? 'a.original_filename' : 'a.original_name';
            $where[] = "(m.message_text LIKE ? OR m.sender_username LIKE ? OR EXISTS (SELECT 1 FROM chat_attachments a WHERE a.message_id=m.id AND $attachNameCol LIKE ?))";
            $kw = '%' . $q . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        $sql = "SELECT m.id, m.channel_id, m.user_id, m.sender_username, m.message_text, m.created_at, m.is_deleted
                FROM chat_messages m
                JOIN chat_channels c ON c.id = m.channel_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY m.id DESC
                LIMIT $limit OFFSET $offset";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'channel_id' => (int)$r['channel_id'],
                'user_id' => (int)$r['user_id'],
                'sender_username' => (string)($r['sender_username'] ?? ''),
                'message_text' => (int)($r['is_deleted'] ?? 0) === 1 ? '[Message deleted by Admin]' : (string)$r['message_text'],
                'created_at' => (string)$r['created_at'],
            ];
        }
        return ['messages' => $out, 'total' => count($out)];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function messageContext(PDO $pdo, int $uid, string $role, int $messageId, int $before, int $after): array
    {
        $before = max(1, min(100, $before));
        $after = max(1, min(100, $after));
        $sm = $pdo->prepare("SELECT id, channel_id FROM chat_messages WHERE id=? LIMIT 1");
        $sm->execute([$messageId]);
        $m = $sm->fetch(PDO::FETCH_ASSOC);
        if (!$m) {
            throw new \RuntimeException('Message not found.');
        }
        $channelId = (int)$m['channel_id'];
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid) || !$this->canRoleAction($pdo, $ch, $role, 'read')) {
            throw new \RuntimeException('Forbidden.');
        }
        $low = max(0, $messageId - $before);
        $high = $messageId + $after;
        $st = $pdo->prepare("SELECT id, channel_id, user_id, sender_username, message_text, created_at, is_deleted
                             FROM chat_messages
                             WHERE channel_id=? AND id BETWEEN ? AND ?
                             ORDER BY id ASC");
        $st->execute([$channelId, $low, $high]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'channel_id' => (int)$r['channel_id'],
                'user_id' => (int)$r['user_id'],
                'sender_username' => (string)($r['sender_username'] ?? ''),
                'message_text' => (int)($r['is_deleted'] ?? 0) === 1 ? '[Message deleted by Admin]' : (string)$r['message_text'],
                'created_at' => (string)$r['created_at'],
                'is_anchor' => (int)$r['id'] === $messageId,
            ];
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function channelEvents(PDO $pdo, int $uid, string $role, int $channelId, int $sinceId, int $timeoutSec = 20): array
    {
        $timeoutSec = max(1, min(20, $timeoutSec));
        $sinceId = max(0, $sinceId);
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch || !$this->canAccessChannel($pdo, $ch, $uid) || !$this->canRoleAction($pdo, $ch, $role, 'read')) {
            throw new \RuntimeException('Forbidden.');
        }
        $start = time();
        do {
            $st = $pdo->prepare("SELECT id, channel_id, user_id, sender_username, message_text, created_at, is_deleted
                                 FROM chat_messages
                                 WHERE channel_id=? AND id>?
                                 ORDER BY id ASC
                                 LIMIT 50");
            $st->execute([$channelId, $sinceId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows) {
                $events = [];
                foreach ($rows as $r) {
                    $events[] = [
                        'id' => (int)$r['id'],
                        'type' => 'message',
                        'channel_id' => (int)$r['channel_id'],
                        'sender_username' => (string)($r['sender_username'] ?? ''),
                        'message_text' => (int)($r['is_deleted'] ?? 0) === 1 ? '[Message deleted by Admin]' : (string)$r['message_text'],
                        'created_at' => (string)$r['created_at'],
                    ];
                }
                return ['events' => $events, 'timeout' => false];
            }
            usleep(400000);
        } while ((time() - $start) < $timeoutSec);
        return ['events' => [], 'timeout' => true];
    }

    /**
     * @return array{id:int,file_path:string,row_count:int,sha256:string}
     */
    public function createExport(PDO $pdo, int $actorId, int $channelId, string $start, string $end, string $format, string $rootPath): array
    {
        if (!$this->tableExists($pdo, 'chat_exports')) {
            throw new \RuntimeException('chat_exports table not available.');
        }
        $format = strtolower(trim($format));
        if (!in_array($format, ['csv', 'json'], true)) {
            $format = 'csv';
        }
        $ch = $this->channelById($pdo, $channelId);
        if (!$ch) {
            throw new \RuntimeException('Channel not found.');
        }
        $attachNameCol = $this->columnExists($pdo, 'chat_attachments', 'original_filename') ? 'a.original_filename' : 'a.original_name';
        $st = $pdo->prepare("SELECT m.id, m.created_at, m.sender_username, m.message_text, m.is_deleted,
                                    GROUP_CONCAT(DISTINCT $attachNameCol ORDER BY a.id SEPARATOR '|') AS attachments
                             FROM chat_messages m
                             LEFT JOIN chat_attachments a ON a.message_id=m.id
                             WHERE m.channel_id=? AND m.created_at BETWEEN ? AND ?
                             GROUP BY m.id
                             ORDER BY m.id ASC
                             LIMIT 20000");
        $st->execute([$channelId, $start, $end]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dir = rtrim($rootPath, '/') . '/storage/chat/exports';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create export directory.');
        }
        $file = 'chat_export_' . date('Ymd_His') . '_' . $channelId . '.' . $format;
        $abs = $dir . '/' . $file;
        if ($format === 'json') {
            $payload = [];
            foreach ($rows as $r) {
                $payload[] = [
                    'message_id' => (int)$r['id'],
                    'timestamp' => (string)$r['created_at'],
                    'sender_username' => (string)$r['sender_username'],
                    'message_text' => (int)($r['is_deleted'] ?? 0) === 1 ? '[Message deleted by Admin]' : (string)$r['message_text'],
                    'attachments' => (string)($r['attachments'] ?? ''),
                ];
            }
            file_put_contents($abs, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $fh = fopen($abs, 'wb');
            if (!$fh) {
                throw new \RuntimeException('Failed to write export file.');
            }
            fputcsv($fh, ['message_id', 'timestamp', 'sender_username', 'message_text', 'attachments'], ',', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($fh, [
                    (int)$r['id'],
                    (string)$r['created_at'],
                    (string)$r['sender_username'],
                    (int)($r['is_deleted'] ?? 0) === 1 ? '[Message deleted by Admin]' : (string)$r['message_text'],
                    (string)($r['attachments'] ?? ''),
                ], ',', '"', '\\');
            }
            fclose($fh);
        }
        $sha = (string)hash_file('sha256', $abs);
        $rel = 'storage/chat/exports/' . $file;
        $ins = $pdo->prepare("INSERT INTO chat_exports (channel_id, requested_by, requested_at, range_start, range_end, format, status, file_path, sha256, row_count, meta_json)
                              VALUES (?, ?, NOW(), ?, ?, ?, 'READY', ?, ?, ?, ?)");
        $ins->execute([$channelId, $actorId > 0 ? $actorId : null, $start, $end, $format, $rel, $sha, count($rows), json_encode(['actor_username' => $_SESSION['username'] ?? 'SYSTEM'])]);
        return [
            'id' => (int)$pdo->lastInsertId(),
            'file_path' => $rel,
            'row_count' => count($rows),
            'sha256' => $sha,
        ];
    }

    public function getExportDownload(PDO $pdo, int $actorId, string $role, int $exportId, string $rootPath): array
    {
        if (!$this->isAdminLike($role)) {
            throw new \RuntimeException('Only ADMIN/SUPERADMIN can download export.');
        }
        $st = $pdo->prepare("SELECT * FROM chat_exports WHERE id=? LIMIT 1");
        $st->execute([$exportId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Export not found.');
        }
        $rel = (string)($row['file_path'] ?? '');
        $abs = rtrim($rootPath, '/') . '/' . ltrim($rel, '/');
        if ($rel === '' || !is_file($abs)) {
            throw new \RuntimeException('Export file missing.');
        }
        return [
            'id' => (int)$row['id'],
            'abs_path' => $abs,
            'file_name' => basename($rel),
            'mime_type' => strtolower((string)($row['format'] ?? 'csv')) === 'json' ? 'application/json' : 'text/csv',
        ];
    }

    public function updateChannelRetention(PDO $pdo, int $channelId, string $mode, ?int $days): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['none', 'purge'], true)) {
            $mode = 'none';
        }
        $days = $days !== null ? max(1, min(3650, $days)) : null;
        if ($mode === 'none') {
            $days = null;
        }
        if (!$this->columnExists($pdo, 'chat_channels', 'retention_mode') || !$this->columnExists($pdo, 'chat_channels', 'retention_days')) {
            return ['channel_id' => $channelId, 'retention_mode' => 'none', 'retention_days' => null];
        }
        $pdo->prepare("UPDATE chat_channels SET retention_mode=?, retention_days=? WHERE id=?")->execute([$mode, $days, $channelId]);
        return ['channel_id' => $channelId, 'retention_mode' => $mode, 'retention_days' => $days];
    }

    /** Daftar channel per modul (PUBLIC, semua user bisa akses). */
    private const MODULE_CHANNELS = [
        'sales-do' => 'Penjualan / DO',
        'purchases-po' => 'Pembelian / PO',
        'purchases-ap' => 'Invoice AP',
        'stock' => 'Stock / WQS',
        'hrl' => 'HR Legal',
        'mpr' => 'MPR',
        'general' => 'Umum',
    ];

    /**
     * Pastikan channel per modul ada. Dipanggil saat load Chat.
     */
    public function ensureModuleChannels(PDO $pdo): void
    {
        foreach (self::MODULE_CHANNELS as $name => $label) {
            try {
                $this->createChannel($pdo, 0, $name, false);
            } catch (\Throwable $e) {
                // Channel mungkin sudah ada
            }
        }
    }

    /**
     * Ambil channel_id untuk modul (untuk post notice).
     */
    public function getModuleChannelId(PDO $pdo, string $module): ?int
    {
        $module = strtolower(trim($module));
        if (!isset(self::MODULE_CHANNELS[$module])) {
            return null;
        }
        $hasType = $this->columnExists($pdo, 'chat_channels', 'type');
        $hasChannelType = $this->columnExists($pdo, 'chat_channels', 'channel_type');
        if ($hasType) {
            $st = $pdo->prepare("SELECT id FROM chat_channels WHERE type='CHANNEL' AND LOWER(COALESCE(name,''))=? LIMIT 1");
            $st->execute([$module]);
            return (int)($st->fetchColumn() ?: 0) ?: null;
        }
        if ($hasChannelType) {
            $st = $pdo->prepare("SELECT id FROM chat_channels WHERE channel_type IN ('PUBLIC','PRIVATE') AND LOWER(COALESCE(name,''))=? LIMIT 1");
            $st->execute([$module]);
            return (int)($st->fetchColumn() ?: 0) ?: null;
        }
        return null;
    }

    public function ensureOrgChannelsForUser(PDO $pdo, int $userId, ?string $officeCode, ?string $department): void
    {
        $officeCode = strtolower(trim((string)$officeCode));
        $department = strtolower(trim((string)$department));
        $targets = [];
        if ($officeCode !== '') {
            $targets[] = 'office-' . preg_replace('/[^a-z0-9_\-]/', '', $officeCode);
        }
        if ($department !== '') {
            $targets[] = 'dept-' . preg_replace('/[^a-z0-9_\-]/', '', $department);
        }
        foreach ($targets as $name) {
            if ($name === '' || $userId <= 0) {
                continue;
            }
            $cid = $this->createChannel($pdo, $userId, $name, false);
            $this->ensureMember($pdo, $cid, $userId);
        }
    }

    /**
     * @return array{purged_messages:int,purged_attachments:int}
     */
    private function retentionPurgeByChannelPolicy(PDO $pdo, int $batch): array
    {
        if (!$this->columnExists($pdo, 'chat_channels', 'retention_mode') || !$this->columnExists($pdo, 'chat_channels', 'retention_days')) {
            return ['purged_messages' => 0, 'purged_attachments' => 0];
        }
        $st = $pdo->query("SELECT id, retention_days FROM chat_channels WHERE retention_mode='purge' AND retention_days IS NOT NULL AND retention_days > 0 ORDER BY id ASC LIMIT 200");
        $channels = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        if (!$channels) {
            return ['purged_messages' => 0, 'purged_attachments' => 0];
        }
        $root = defined('RMI_ROOT') ? (string)RMI_ROOT : dirname(__DIR__, 2);
        $purgedMessages = 0;
        $purgedFiles = 0;
        foreach ($channels as $c) {
            $channelId = (int)$c['id'];
            $days = max(1, (int)$c['retention_days']);
            $sx = $pdo->prepare("SELECT id FROM chat_messages WHERE channel_id=? AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY id ASC LIMIT $batch");
            $sx->execute([$channelId, $days]);
            $ids = array_map(static fn($r) => (int)$r['id'], $sx->fetchAll(PDO::FETCH_ASSOC) ?: []);
            if (!$ids) {
                continue;
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $sa = $pdo->prepare("SELECT storage_path FROM chat_attachments WHERE message_id IN ($in)");
            $sa->execute($ids);
            $atts = $sa->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($atts as $a) {
                $abs = rtrim($root, '/') . '/' . ltrim((string)($a['storage_path'] ?? ''), '/');
                if (is_file($abs) && @unlink($abs)) {
                    $purgedFiles++;
                }
            }
            $pdo->prepare("DELETE FROM chat_attachments WHERE message_id IN ($in)")->execute($ids);
            $pdo->prepare("DELETE FROM chat_messages WHERE id IN ($in)")->execute($ids);
            $purgedMessages += count($ids);
        }
        return ['purged_messages' => $purgedMessages, 'purged_attachments' => $purgedFiles];
    }

    private function assertAttachmentQuota(PDO $pdo, int $uid, int $channelId, int $incomingBytes): void
    {
        if (!$this->tableExists($pdo, 'chat_quota_config') || !$this->tableExists($pdo, 'chat_quota_usage')) {
            return;
        }
        $cfgSt = $pdo->query("SELECT max_attachment_mb_per_user_per_day, max_attachment_mb_per_channel_per_day FROM chat_quota_config WHERE id=1 LIMIT 1");
        $cfg = $cfgSt ? ($cfgSt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $userMax = max(1, (int)($cfg['max_attachment_mb_per_user_per_day'] ?? 100)) * 1024 * 1024;
        $channelMax = max(1, (int)($cfg['max_attachment_mb_per_channel_per_day'] ?? 500)) * 1024 * 1024;

        $su = $pdo->prepare("SELECT COALESCE(SUM(used_bytes),0) FROM chat_quota_usage WHERE usage_date=CURDATE() AND user_id=?");
        $su->execute([$uid]);
        $userUsed = (int)$su->fetchColumn();
        if (($userUsed + $incomingBytes) > $userMax) {
            throw new \RuntimeException('Attachment quota exceeded (user/day).');
        }
        $sc = $pdo->prepare("SELECT COALESCE(SUM(used_bytes),0) FROM chat_quota_usage WHERE usage_date=CURDATE() AND channel_id=?");
        $sc->execute([$channelId]);
        $channelUsed = (int)$sc->fetchColumn();
        if (($channelUsed + $incomingBytes) > $channelMax) {
            throw new \RuntimeException('Attachment quota exceeded (channel/day).');
        }
    }

    private function consumeAttachmentQuota(PDO $pdo, int $uid, int $channelId, int $bytes): void
    {
        if ($bytes <= 0 || !$this->tableExists($pdo, 'chat_quota_usage')) {
            return;
        }
        $pdo->prepare("INSERT INTO chat_quota_usage (usage_date, user_id, channel_id, used_bytes, updated_at)
                       VALUES (CURDATE(), ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE used_bytes=used_bytes+VALUES(used_bytes), updated_at=NOW()")
            ->execute([$uid, $channelId, $bytes]);
    }

    private function maskIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') return '';
        if (str_contains($ip, '.')) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = 'x';
                return implode('.', $parts);
            }
        }
        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);
            $n = count($parts);
            if ($n > 1) {
                $parts[$n - 1] = 'x';
                return implode(':', $parts);
            }
        }
        return 'x';
    }

    private function getConfig(PDO $pdo, string $key, string $default): string
    {
        try {
            $st = $pdo->prepare("SELECT config_value FROM chat_config WHERE config_key=? LIMIT 1");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v !== false ? (string)$v : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function isAllowedEmoji(PDO $pdo, string $emoji): bool
    {
        $emoji = trim($emoji);
        if ($emoji === '' || mb_strlen($emoji) > 16) {
            return false;
        }
        $allowed = array_map(static fn($r) => (string)$r['emoji'], $this->listEmojiSet($pdo));
        return in_array($emoji, $allowed, true);
    }

    /**
     * @return array{can_read:int,can_send:int,can_pin:int,can_manage:int}
     */
    private function channelRoleMatrix(PDO $pdo, int $channelId, string $role): array
    {
        $defaults = ['can_read' => 1, 'can_send' => 1, 'can_pin' => 0, 'can_manage' => 0];
        if (!$this->getConfigBool($pdo, 'enable_channel_acl', true)) {
            return $defaults;
        }
        if (!$this->tableExists($pdo, 'chat_channel_acl')) {
            return $defaults;
        }
        $roleCode = strtoupper(trim($role));
        if ($roleCode === '') {
            $roleCode = 'USER';
        }
        try {
            if ($this->columnExists($pdo, 'chat_channel_acl', 'subject_type') && $this->columnExists($pdo, 'chat_channel_acl', 'subject_key')) {
                $st = $pdo->prepare("SELECT can_read, can_send, can_pin, can_manage
                                     FROM chat_channel_acl
                                     WHERE channel_id=? AND subject_type='ROLE' AND subject_key=?
                                     LIMIT 1");
                $st->execute([$channelId, $roleCode]);
            } else {
                $st = $pdo->prepare("SELECT can_read, can_send, can_pin, can_manage
                                     FROM chat_channel_acl
                                     WHERE channel_id=? AND role_code=?
                                     LIMIT 1");
                $st->execute([$channelId, $roleCode]);
            }
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return $defaults;
            return [
                'can_read' => (int)$r['can_read'],
                'can_send' => (int)$r['can_send'],
                'can_pin' => (int)$r['can_pin'],
                'can_manage' => (int)$r['can_manage'],
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    private function canRoleAction(PDO $pdo, array $channel, string $role, string $action): bool
    {
        $action = strtolower(trim($action));
        if ($this->columnExists($pdo, 'chat_channel_acl', 'subject_type') && $this->columnExists($pdo, 'chat_channel_acl', 'subject_key')) {
            $channelId = (int)$channel['id'];
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $roleCode = strtoupper(trim($role !== '' ? $role : (string)($_SESSION['role'] ?? 'USER')));
            $dept = strtoupper(trim((string)($_SESSION['department'] ?? '')));
            $office = strtoupper(trim((string)($_SESSION['office_code'] ?? '')));
            $subjects = [
                ['type' => 'USER', 'key' => (string)$uid],
                ['type' => 'ROLE', 'key' => $roleCode],
                ['type' => 'DEPT', 'key' => $dept],
                ['type' => 'OFFICE', 'key' => $office],
            ];
            foreach ($subjects as $s) {
                if ($s['key'] === '' || $s['key'] === '0') {
                    continue;
                }
                $st = $pdo->prepare("SELECT can_read, can_send, can_pin, can_manage FROM chat_channel_acl WHERE channel_id=? AND subject_type=? AND subject_key=? LIMIT 1");
                $st->execute([$channelId, $s['type'], $s['key']]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return match ($action) {
                        'read' => (int)$row['can_read'] === 1,
                        'send' => (int)$row['can_send'] === 1,
                        'pin' => (int)$row['can_pin'] === 1,
                        'manage' => (int)$row['can_manage'] === 1,
                        default => true,
                    };
                }
            }
        }
        $mx = $this->channelRoleMatrix($pdo, (int)$channel['id'], $role);
        return match ($action) {
            'read' => (int)$mx['can_read'] === 1,
            'send' => (int)$mx['can_send'] === 1,
            'pin' => (int)$mx['can_pin'] === 1,
            'manage' => (int)$mx['can_manage'] === 1,
            default => true,
        };
    }

    private function resolveThreadRootId(PDO $pdo, int $messageId): int
    {
        try {
            if ($this->columnExists($pdo, 'chat_messages', 'thread_root_message_id')) {
                $st = $pdo->prepare("SELECT thread_root_message_id, reply_to_message_id, id FROM chat_messages WHERE id=? LIMIT 1");
                $st->execute([$messageId]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $root = (int)($r['thread_root_message_id'] ?? 0);
                if ($root > 0) return $root;
                $reply = (int)($r['reply_to_message_id'] ?? 0);
                return $reply > 0 ? $reply : (int)($r['id'] ?? $messageId);
            }
        } catch (\Throwable $e) {
            // fallback below
        }
        return $messageId;
    }

    /**
     * Batch-resolve display names for chat message senders (master_employees.employee_name lalu master_system_login.full_name).
     *
     * @param list<array<string,mixed>> $rows Each row should include user_id + sender_username when possible
     * @param list<string> $extraUsernames Additional usernames to resolve (e.g. reply authors)
     * @return array{by_id: array<int,string>, by_user: array<string,string>}
     */
    private function buildSenderDisplayMap(PDO $pdo, array $rows, array $extraUsernames = []): array
    {
        $ids = [];
        $names = [];
        foreach ($rows as $r) {
            $uid = (int)($r['user_id'] ?? 0);
            if ($uid > 0) {
                $ids[$uid] = true;
            }
            $un = trim((string)($r['sender_username'] ?? ''));
            if ($un !== '' && stripos($un, 'user#') !== 0) {
                $names[$un] = true;
            }
        }
        foreach ($extraUsernames as $x) {
            $x = trim((string)$x);
            if ($x !== '' && stripos($x, 'user#') !== 0) {
                $names[$x] = true;
            }
        }
        if ($ids === [] && $names === []) {
            return ['by_id' => [], 'by_user' => []];
        }
        $hasEmp = $this->tableExists($pdo, 'master_employees');
        $empJoin = $hasEmp
            ? "LEFT JOIN master_employees e ON e.employee_code = u.holder_employee_code AND LOWER(COALESCE(e.status,'active'))='active'"
            : '';
        $empSel = $hasEmp ? 'e.employee_name' : 'NULL AS employee_name';
        $parts = [];
        $params = [];
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $parts[] = "u.id IN ($in)";
            foreach (array_keys($ids) as $id) {
                $params[] = (int)$id;
            }
        }
        if ($names !== []) {
            $in2 = implode(',', array_fill(0, count($names), '?'));
            $parts[] = "u.username IN ($in2)";
            foreach (array_keys($names) as $n) {
                $params[] = $n;
            }
        }
        $where = implode(' OR ', $parts);
        $sql = "SELECT u.id, u.username, u.full_name, {$empSel} AS employee_name
                FROM master_system_login u
                {$empJoin}
                WHERE {$where}";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $resolved = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return ['by_id' => [], 'by_user' => []];
        }
        $byId = [];
        $byUser = [];
        foreach ($resolved as $u) {
            $id = (int)($u['id'] ?? 0);
            $uname = trim((string)($u['username'] ?? ''));
            $emp = trim((string)($u['employee_name'] ?? ''));
            $fn = trim((string)($u['full_name'] ?? ''));
            $disp = $emp !== '' ? $emp : $fn;
            if ($id > 0) {
                $byId[$id] = $disp;
            }
            if ($uname !== '') {
                $byUser[strtolower($uname)] = $disp;
            }
        }

        return ['by_id' => $byId, 'by_user' => $byUser];
    }

    /**
     * @param array{by_id: array<int,string>, by_user: array<string,string>} $map
     */
    private function resolveSenderDisplayName(array $map, int $userId, string $senderUsername): string
    {
        if ($userId > 0 && isset($map['by_id'][$userId])) {
            return (string)$map['by_id'][$userId];
        }
        $k = strtolower(trim($senderUsername));
        if ($k !== '' && isset($map['by_user'][$k])) {
            return (string)$map['by_user'][$k];
        }

        return '';
    }

    private function getConfigBool(PDO $pdo, string $key, bool $default): bool
    {
        $v = strtolower(trim($this->getConfig($pdo, $key, $default ? '1' : '0')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name=? AND column_name=?");
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Post system notice ke channel modul (sales-do, purchases-po, purchases-ap) saat ada trigger dari proses bisnis.
     * User yang di-mention akan dapat notifikasi unread di Chat.
     *
     * @param array<int> $mentionUserIds User ID yang di-@mention (akan dapat notice)
     * @param array<int> $addAsMembers User ID yang ditambahkan sebagai member channel (bisa kosong)
     */
    public function postProcessNotice(
        PDO $pdo,
        string $entityType,
        int $entityId,
        string $event,
        string $message,
        array $mentionUserIds = [],
        array $addAsMembers = []
    ): ?int {
        $entityType = strtoupper(trim($entityType));
        if (!in_array($entityType, ['DO', 'PO', 'AP'], true)) {
            return null;
        }
        $moduleMap = ['DO' => 'sales-do', 'PO' => 'purchases-po', 'AP' => 'purchases-ap'];
        $moduleName = $moduleMap[$entityType];
        $this->ensureModuleChannels($pdo);
        $channelId = $this->getModuleChannelId($pdo, $moduleName);
        if ($channelId <= 0) {
            $channelId = $this->createChannel($pdo, 0, $moduleName, false);
        }
        $link = match ($entityType) {
            'DO' => 'sales/sales_do_view.php?id=' . $entityId,
            'PO' => 'purchases/purchases_po_view.php?id=' . $entityId,
            default => 'purchases/purchases_invoice_ap_edit.php?id=' . $entityId,
        };
        $allMembers = array_unique(array_merge($mentionUserIds, $addAsMembers));
        foreach ($allMembers as $uid) {
            if ($uid > 0) {
                $this->ensureMember($pdo, $channelId, $uid);
            }
        }
        $mentionPart = '';
        if (!empty($mentionUserIds)) {
            $usernames = [];
            foreach ($mentionUserIds as $uid) {
                if ($uid <= 0) continue;
                $u = $pdo->prepare("SELECT username FROM master_system_login WHERE id=? LIMIT 1");
                $u->execute([$uid]);
                $un = $u->fetchColumn();
                if ($un !== false && trim((string)$un) !== '') {
                    $usernames[] = '@' . trim((string)$un);
                }
            }
            if (!empty($usernames)) {
                $mentionPart = implode(' ', $usernames) . ' ';
            }
        }
        $fullMsg = $mentionPart . '[' . $event . '] ' . $message . ' [' . $link . ']';
        $hasUserId = $this->columnExists($pdo, 'chat_messages', 'user_id');
        $hasSenderUserId = $this->columnExists($pdo, 'chat_messages', 'sender_user_id');
        $uidCol = $hasUserId ? 'user_id' : ($hasSenderUserId ? 'sender_user_id' : 'sender_user_id');
        $ins = $pdo->prepare("INSERT INTO chat_messages (channel_id, $uidCol, sender_username, message_text, created_at, is_deleted) VALUES (?, 0, 'SYSTEM', ?, NOW(), 0)");
        $ins->execute([$channelId, $fullMsg]);
        $mid = (int)$pdo->lastInsertId();
        if ($mid > 0 && !empty($mentionUserIds) && ($this->tableExists($pdo, 'chat_mentions') || $this->tableExists($pdo, 'chat_message_mentions'))) {
            $mentionTable = $this->tableExists($pdo, 'chat_message_mentions') ? 'chat_message_mentions' : 'chat_mentions';
            $hasMentionUsername = $this->columnExists($pdo, $mentionTable, 'mentioned_username');
            foreach ($mentionUserIds as $uid) {
                if ($uid <= 0) continue;
                $un = '';
                $u = $pdo->prepare("SELECT username FROM master_system_login WHERE id=? LIMIT 1");
                $u->execute([$uid]);
                $un = trim((string)($u->fetchColumn() ?: ''));
                if ($mentionTable === 'chat_message_mentions') {
                    if ($hasMentionUsername) {
                        $pdo->prepare("INSERT INTO $mentionTable (message_id, mentioned_user_id, mentioned_username) VALUES (?, ?, ?)")->execute([$mid, $uid, $un ?: 'user' . $uid]);
                    } else {
                        $pdo->prepare("INSERT INTO $mentionTable (message_id, mentioned_user_id) VALUES (?, ?)")->execute([$mid, $uid]);
                    }
                } else {
                    $pdo->prepare("INSERT INTO $mentionTable (message_id, mentioned_user_id, created_at) VALUES (?, ?, NOW())")->execute([$mid, $uid]);
                }
            }
        }
        return $channelId;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $pdo->query("SELECT 1 FROM " . $table . " LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

