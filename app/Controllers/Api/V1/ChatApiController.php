<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1;

require_once __DIR__ . '/../../../../master/auth.php';
require_once __DIR__ . '/../../../../_shared/app_init.php';
require_once __DIR__ . '/../../../../_shared/db.php';
require_once __DIR__ . '/../../../../_shared/erp_audit.php';
require_once __DIR__ . '/../../../../app/Api/ApiResponse.php';
require_once __DIR__ . '/../../../../app/Services/ChatService.php';
require_once __DIR__ . '/../../../../app/Services/ChatMentionService.php';
require_once __DIR__ . '/../../../../app/Services/ChatAttachmentService.php';

use App\Api\ApiResponse;
use App\Services\ChatService;

final class ChatApiController
{
    private ChatService $svc;
    private \PDO $pdo;
    private int $uid;
    private string $username;
    private string $role;

    public function __construct()
    {
        require_login();
        $this->pdo = rmi_db_pdo();
        erp_audit_ensure($this->pdo);
        $this->svc = new ChatService();
        $this->svc->ensureReady($this->pdo);
        $this->uid = (int)($_SESSION['user_id'] ?? 0);
        $this->username = trim((string)($_SESSION['username'] ?? 'user'));
        $this->role = strtoupper(trim((string)($_SESSION['level'] ?? ($_SESSION['role'] ?? ''))));
        try {
            $this->svc->ensureModuleChannels($this->pdo);
            $this->svc->ensureOrgChannelsForUser(
                $this->pdo,
                $this->uid,
                (string)($_SESSION['office_code'] ?? ''),
                (string)($_SESSION['department'] ?? '')
            );
        } catch (\Throwable $e) {
            // Keep API resilient; org channel sync is best-effort.
        }
    }

    private function requireWriteGuard(): void
    {
        require_post();
        verify_csrf();
    }

    public function channelsGet(): void
    {
        try {
            $rows = $this->svc->listChannelsWithUnread($this->pdo, $this->uid, $this->role);
            ApiResponse::ok([
                'channels' => $rows,
                'current_user' => [
                    'id' => $this->uid,
                    'username' => $this->username,
                    'role' => $this->role,
                'can_delete' => $this->svc->isAdminLike($this->role),
                ],
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            ApiResponse::fail($msg !== '' ? $msg : 'Failed to load chat channels.', 'ERR_CHAT_CHANNELS', 500);
        }
    }

    public function channelsPost(): void
    {
        $this->requireWriteGuard();
        $action = strtolower(trim((string)($_POST['action'] ?? 'join')));
        try {
            if ($action === 'join') {
                $channelId = (int)($_POST['channel_id'] ?? 0);
                if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
                $this->svc->joinChannel($this->pdo, $this->uid, $channelId, $this->svc->isAdminLike($this->role));
                erp_audit($this->pdo, 'CHAT', 'CHANNEL#' . $channelId, 'MEMBER_JOINED', ['user_id' => $this->uid]);
                ApiResponse::ok(['channel_id' => $channelId, 'joined' => true]);
            }
            if ($action === 'create_dm') {
                $target = (int)($_POST['target_user_id'] ?? 0);
                $cid = $this->svc->createDm($this->pdo, $this->uid, $target);
                erp_audit($this->pdo, 'CHAT', 'CHANNEL#' . $cid, 'DM_CREATED', ['by' => $this->uid, 'target_user_id' => $target]);
                ApiResponse::ok(['channel_id' => $cid, 'created' => true]);
            }
            if ($action === 'create_channel') {
                if (!$this->svc->isAdminLike($this->role)) {
                    ApiResponse::fail('Only ADMIN/SUPERADMIN can create channel.', 'ERR_CHAT_FORBIDDEN', 403);
                }
                $name = (string)($_POST['name'] ?? '');
                $isPrivate = (int)($_POST['is_private'] ?? 0) === 1;
                $cid = $this->svc->createChannel($this->pdo, $this->uid, $name, $isPrivate);
                $this->svc->ensureMember($this->pdo, $cid, $this->uid);
                erp_audit($this->pdo, 'CHAT', 'CHANNEL#' . $cid, 'CHANNEL_CREATED', ['name' => $name, 'is_private' => $isPrivate]);
                ApiResponse::ok(['channel_id' => $cid, 'created' => true]);
            }
            ApiResponse::fail('Unknown action.', 'ERR_CHAT_VALIDATION', 422);
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_ACTION', 400);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            ApiResponse::fail($msg !== '' ? $msg : 'Failed to process channel action.', 'ERR_CHAT_ACTION', 500);
        }
    }

    public function messagesGet(): void
    {
        $channelId = (int)($_GET['channel_id'] ?? 0);
        $limit = (int)($_GET['limit'] ?? 30);
        $before = (int)($_GET['before_id'] ?? 0);
        $after = (int)($_GET['after_id'] ?? 0);
        $q = trim((string)($_GET['q'] ?? ''));
        if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $data = $this->svc->listMessages($this->pdo, $this->uid, $this->role, $channelId, $limit, $before, $after, $q);
            foreach ($data['messages'] as &$m) {
                $m['can_delete'] = $this->svc->isAdminLike($this->role) && !$m['is_deleted'];
            }
            unset($m);
            ApiResponse::ok([
                'messages' => $data['messages'],
                'oldest_id' => $data['oldest_id'],
                'newest_id' => $data['newest_id'],
                'channel_id' => $channelId,
                'dm_read_receipt' => $data['dm_read_receipt'] ?? null,
                'channel_pref' => $this->svc->getChannelPreference($this->pdo, $this->uid, $this->role, $channelId),
            ]);
        } catch (\RuntimeException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'forbidden') ? 403 : 400;
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_MESSAGES', $code);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to load messages.', 'ERR_CHAT_MESSAGES', 500);
        }
    }

    public function messagesPost(): void
    {
        $this->requireWriteGuard();
        $channelId = (int)($_POST['channel_id'] ?? 0);
        $text = (string)($_POST['message_text'] ?? '');
        $replyTo = (int)($_POST['reply_to_message_id'] ?? 0);
        $idempotencyKey = trim((string)($_POST['idempotency_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')));
        $context = [
            'entity_type' => (string)($_POST['context_entity_type'] ?? ''),
            'entity_id' => (string)($_POST['context_entity_id'] ?? ''),
            'entity_code' => (string)($_POST['context_entity_code'] ?? ''),
            'entity_url' => (string)($_POST['context_entity_url'] ?? ''),
        ];
        if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        $root = defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/../../../../') ?: dirname(__DIR__, 4));
        $files = $_FILES['attachments'] ?? [];
        try {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $result = $this->svc->sendMessage($this->pdo, $this->uid, $this->role, $this->username, $channelId, $text, $files, $ip, $root, $replyTo, $idempotencyKey, $context);
            erp_audit($this->pdo, 'CHAT', 'MSG#' . (int)$result['id'], 'MESSAGE_SENT', [
                'channel_id' => $channelId,
                'mentions' => count($result['mentions']),
                'idempotency_key' => $idempotencyKey !== '' ? '[REDACTED]' : '',
                'actor_username' => $this->username !== '' ? $this->username : 'SYSTEM',
            ]);
            ApiResponse::ok([
                'message_id' => (int)$result['id'],
                'channel_id' => $channelId,
                'mentions' => $result['mentions'],
                'idempotent' => (bool)($result['idempotent'] ?? false),
            ]);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $status = str_contains(strtolower($msg), 'rate limit') ? 429 : (str_contains(strtolower($msg), 'forbidden') ? 403 : 422);
            ApiResponse::fail($msg, 'ERR_CHAT_SEND', $status);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to send message.', 'ERR_CHAT_SEND', 500);
        }
    }

    public function reactionPost(): void
    {
        $this->requireWriteGuard();
        $messageId = (int)($_POST['message_id'] ?? 0);
        $emoji = trim((string)($_POST['emoji'] ?? ''));
        if ($messageId <= 0 || $emoji === '') ApiResponse::fail('message_id and emoji required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $r = $this->svc->toggleReaction($this->pdo, $this->uid, $messageId, $emoji);
            erp_audit($this->pdo, 'CHAT', 'MSG#' . $messageId, 'CHAT_REACTION_TOGGLE', ['emoji' => $emoji, 'on' => (bool)$r['toggled_on'], 'user_id' => $this->uid]);
            ApiResponse::ok($r);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'forbidden') ? 403 : 422;
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_REACTION', $status);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to toggle reaction.', 'ERR_CHAT_REACTION', 500);
        }
    }

    public function typingPost(): void
    {
        $this->requireWriteGuard();
        $channelId = (int)($_POST['channel_id'] ?? 0);
        if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $this->svc->typingPing($this->pdo, $this->uid, $this->role, $channelId);
            ApiResponse::ok(['channel_id' => $channelId, 'typing' => true]);
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_TYPING', 403);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to ping typing.', 'ERR_CHAT_TYPING', 500);
        }
    }

    public function typingGet(): void
    {
        $channelId = (int)($_GET['channel_id'] ?? 0);
        if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $rows = $this->svc->typingList($this->pdo, $this->uid, $this->role, $channelId);
            ApiResponse::ok(['rows' => $rows, 'channel_id' => $channelId]);
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_TYPING', 403);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to get typing status.', 'ERR_CHAT_TYPING', 500);
        }
    }

    public function pinPost(): void
    {
        $this->requireWriteGuard();
        $action = strtolower(trim((string)($_POST['action'] ?? 'pin')));
        $messageId = (int)($_POST['message_id'] ?? 0);
        if ($messageId <= 0) ApiResponse::fail('message_id required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            if ($action === 'unpin') {
                $ret = $this->svc->unpinMessage($this->pdo, $this->uid, $this->role, $messageId);
                erp_audit($this->pdo, 'CHAT', 'MSG#' . $messageId, 'CHAT_UNPIN', ['user_id' => $this->uid]);
                ApiResponse::ok($ret);
            }
            $ret = $this->svc->pinMessage($this->pdo, $this->uid, $this->role, $messageId);
            erp_audit($this->pdo, 'CHAT', 'MSG#' . $messageId, 'CHAT_PIN', ['user_id' => $this->uid]);
            ApiResponse::ok($ret);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'only admin') ? 403 : 422;
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_PIN', $status);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to pin/unpin message.', 'ERR_CHAT_PIN', 500);
        }
    }

    public function pinsGet(): void
    {
        $channelId = (int)($_GET['channel_id'] ?? 0);
        if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $rows = $this->svc->listPinned($this->pdo, $this->uid, $this->role, $channelId);
            ApiResponse::ok(['rows' => $rows, 'channel_id' => $channelId]);
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_PINS', 403);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to list pinned messages.', 'ERR_CHAT_PINS', 500);
        }
    }

    public function emojisGet(): void
    {
        try {
            $rows = $this->svc->listEmojiSet($this->pdo);
            ApiResponse::ok(['rows' => $rows]);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to list emojis.', 'ERR_CHAT_EMOJI', 500);
        }
    }

    public function threadGet(): void
    {
        $messageId = (int)($_GET['message_id'] ?? 0);
        if ($messageId <= 0) ApiResponse::fail('message_id required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $rows = $this->svc->threadMessages($this->pdo, $this->uid, $this->role, $messageId);
            ApiResponse::ok(['rows' => $rows, 'message_id' => $messageId]);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'forbidden') ? 403 : 404;
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_THREAD', $status);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to load thread.', 'ERR_CHAT_THREAD', 500);
        }
    }

    public function presencePost(): void
    {
        $this->requireWriteGuard();
        try {
            $this->svc->setPresence($this->pdo, $this->uid, 'ONLINE');
            ApiResponse::ok(['status' => 'ONLINE', 'last_seen_at' => date('c')]);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to update presence.', 'ERR_CHAT_PRESENCE', 500);
        }
    }

    public function presenceGet(): void
    {
        try {
            $userIdsRaw = trim((string)($_GET['user_ids'] ?? ''));
            if ($userIdsRaw !== '') {
                $ids = array_filter(array_map('intval', explode(',', $userIdsRaw)), static fn($v) => $v > 0);
                $rows = $this->svc->presenceBatch($this->pdo, $ids);
                ApiResponse::ok(['rows' => $rows]);
            }
            $channelId = (int)($_GET['channel_id'] ?? 0);
            if ($channelId <= 0) ApiResponse::fail('channel_id or user_ids required.', 'ERR_CHAT_VALIDATION', 422);
            $rows = $this->svc->channelPresence($this->pdo, $this->uid, $this->role, $channelId);
            ApiResponse::ok(['rows' => $rows, 'channel_id' => $channelId]);
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_PRESENCE', 403);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to load presence.', 'ERR_CHAT_PRESENCE', 500);
        }
    }

    public function preferenceGet(): void
    {
        $channelId = (int)($_GET['channel_id'] ?? 0);
        if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $pref = $this->svc->getChannelPreference($this->pdo, $this->uid, $this->role, $channelId);
            ApiResponse::ok(['channel_id' => $channelId, 'pref' => $pref]);
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_PREF', 403);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to read preference.', 'ERR_CHAT_PREF', 500);
        }
    }

    public function preferencePost(): void
    {
        $this->requireWriteGuard();
        $channelId = (int)($_POST['channel_id'] ?? 0);
        $isMuted = (int)($_POST['is_muted'] ?? 0);
        $notifyLevel = (string)($_POST['notify_level'] ?? 'ALL');
        $muteUntil = trim((string)($_POST['mute_until'] ?? ''));
        if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $pref = $this->svc->setChannelPreference($this->pdo, $this->uid, $this->role, $channelId, $isMuted, $notifyLevel, $muteUntil !== '' ? $muteUntil : null);
            erp_audit($this->pdo, 'CHAT', 'CHANNEL#' . $channelId, 'CHAT_PREF_UPDATE', ['user_id' => $this->uid, 'pref' => $pref]);
            ApiResponse::ok(['channel_id' => $channelId, 'pref' => $pref]);
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_PREF', 403);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to update preference.', 'ERR_CHAT_PREF', 500);
        }
    }

    public function pinPolicyPost(): void
    {
        $this->requireWriteGuard();
        $channelId = (int)($_POST['channel_id'] ?? 0);
        $policy = (string)($_POST['pin_policy'] ?? '');
        if ($channelId <= 0 || $policy === '') ApiResponse::fail('channel_id and pin_policy required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $ret = $this->svc->setChannelPinPolicy($this->pdo, $this->uid, $this->role, $channelId, $policy);
            erp_audit($this->pdo, 'CHAT', 'CHANNEL#' . $channelId, 'CHAT_PIN_POLICY_UPDATE', ['policy' => $policy, 'user_id' => $this->uid]);
            ApiResponse::ok($ret);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'only admin') ? 403 : 422;
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_PIN_POLICY', $status);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to update pin policy.', 'ERR_CHAT_PIN_POLICY', 500);
        }
    }

    public function aclGet(): void
    {
        $channelId = (int)($_GET['channel_id'] ?? 0);
        if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $rows = $this->svc->getChannelAcl($this->pdo, $this->uid, $this->role, $channelId);
            ApiResponse::ok(['channel_id' => $channelId, 'rows' => $rows]);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'only admin') ? 403 : 422;
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_ACL', $status);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to read channel ACL.', 'ERR_CHAT_ACL', 500);
        }
    }

    public function aclPost(): void
    {
        $this->requireWriteGuard();
        $channelId = (int)($_POST['channel_id'] ?? 0);
        if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        $rowsJson = trim((string)($_POST['rows_json'] ?? '[]'));
        $rows = json_decode($rowsJson, true);
        if (!is_array($rows)) $rows = [];
        try {
            $this->svc->setChannelAcl($this->pdo, $this->uid, $this->role, $channelId, $rows);
            erp_audit($this->pdo, 'CHAT', 'CHANNEL#' . $channelId, 'CHAT_ACL_UPDATE', ['updated_by' => $this->uid, 'items' => count($rows)]);
            ApiResponse::ok(['channel_id' => $channelId, 'updated' => true]);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'only admin') ? 403 : 422;
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_ACL', $status);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to update channel ACL.', 'ERR_CHAT_ACL', 500);
        }
    }

    public function readPost(): void
    {
        $this->requireWriteGuard();
        $channelId = (int)($_POST['channel_id'] ?? 0);
        $lastRead = (int)($_POST['last_read_message_id'] ?? 0);
        if ($channelId <= 0) ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $last = $this->svc->markRead($this->pdo, $this->uid, $channelId, $lastRead);
            ApiResponse::ok(['channel_id' => $channelId, 'unread_count' => 0, 'last_read_message_id' => $last]);
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_READ', 403);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to mark read.', 'ERR_CHAT_READ', 500);
        }
    }

    public function markAllReadPost(): void
    {
        $this->requireWriteGuard();
        try {
            $ret = $this->svc->markAllRead($this->pdo, $this->uid);
            erp_audit($this->pdo, 'CHAT', 'USER#' . $this->uid, 'CHAT_MARK_ALL_READ', ['actor_username' => $this->username, 'meta' => $ret]);
            ApiResponse::ok($ret);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to mark all as read.', 'ERR_CHAT_READ_ALL', 500);
        }
    }

    public function mutePost(): void
    {
        $this->requireWriteGuard();
        $channelId = (int)($_POST['channel_id'] ?? 0);
        $duration = (int)($_POST['duration'] ?? 3600);
        if ($channelId <= 0) {
            ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        }
        try {
            $pref = $this->svc->setChannelMute($this->pdo, $this->uid, $channelId, $duration);
            erp_audit($this->pdo, 'CHAT', 'CHANNEL#' . $channelId, 'CHAT_MUTE_SET', ['duration' => $duration, 'actor_username' => $this->username]);
            ApiResponse::ok(['channel_id' => $channelId, 'pref' => $pref]);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to mute channel.', 'ERR_CHAT_MUTE', 500);
        }
    }

    public function unmutePost(): void
    {
        $this->requireWriteGuard();
        $channelId = (int)($_POST['channel_id'] ?? 0);
        if ($channelId <= 0) {
            ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        }
        try {
            $pref = $this->svc->clearChannelMute($this->pdo, $this->uid, $channelId);
            erp_audit($this->pdo, 'CHAT', 'CHANNEL#' . $channelId, 'CHAT_MUTE_CLEAR', ['actor_username' => $this->username]);
            ApiResponse::ok(['channel_id' => $channelId, 'pref' => $pref]);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to unmute channel.', 'ERR_CHAT_UNMUTE', 500);
        }
    }

    public function searchGet(): void
    {
        try {
            $ret = $this->svc->searchMessages($this->pdo, $this->uid, $this->role, $_GET);
            ApiResponse::ok($ret);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to search messages.', 'ERR_CHAT_SEARCH', 500);
        }
    }

    public function messageContextGet(): void
    {
        $messageId = (int)($_GET['message_id'] ?? 0);
        $before = (int)($_GET['before'] ?? 20);
        $after = (int)($_GET['after'] ?? 20);
        if ($messageId <= 0) {
            ApiResponse::fail('message_id required.', 'ERR_CHAT_VALIDATION', 422);
        }
        try {
            $rows = $this->svc->messageContext($this->pdo, $this->uid, $this->role, $messageId, $before, $after);
            ApiResponse::ok(['rows' => $rows, 'message_id' => $messageId]);
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_CONTEXT', 403);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to load context.', 'ERR_CHAT_CONTEXT', 500);
        }
    }

    public function eventsGet(): void
    {
        $channelId = (int)($_GET['channel_id'] ?? 0);
        $sinceId = (int)($_GET['since_id'] ?? 0);
        $timeout = (int)($_GET['timeout'] ?? 20);
        if ($channelId <= 0) {
            ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        }
        try {
            $ret = $this->svc->channelEvents($this->pdo, $this->uid, $this->role, $channelId, $sinceId, $timeout);
            ApiResponse::ok($ret);
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_EVENTS', 403);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to load events.', 'ERR_CHAT_EVENTS', 500);
        }
    }

    public function usersGet(): void
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 8);
        try {
            $users = $this->svc->searchUsers($this->pdo, $q, $limit);
            ApiResponse::ok(['rows' => $users]);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to search users.', 'ERR_CHAT_USERS', 500);
        }
    }

    public function deletePost(): void
    {
        $this->requireWriteGuard();
        $messageId = (int)($_POST['message_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($messageId <= 0) ApiResponse::fail('message_id required.', 'ERR_CHAT_VALIDATION', 422);
        if ($reason === '') ApiResponse::fail('reason required.', 'ERR_CHAT_VALIDATION', 422);
        try {
            $ret = $this->svc->deleteMessage($this->pdo, $this->uid, $this->role, $messageId, $reason);
            erp_audit($this->pdo, 'CHAT', 'MSG#' . $messageId, 'CHAT_MESSAGE_DELETED', [
                'channel_id' => (int)$ret['channel_id'],
                'reason' => $reason,
                'deleted_by' => $this->uid,
                'actor_username' => $this->username !== '' ? $this->username : 'SYSTEM',
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);
            ApiResponse::ok(['message_id' => $messageId, 'deleted' => true, 'idempotent' => (bool)$ret['idempotent']]);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'only admin') ? 403 : 404;
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_DELETE', $status);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to delete message.', 'ERR_CHAT_DELETE', 500);
        }
    }

    public function downloadGet(): void
    {
        $attachmentId = (int)($_GET['attachment_id'] ?? 0);
        if ($attachmentId <= 0) ApiResponse::fail('attachment_id required.', 'ERR_CHAT_VALIDATION', 422);
        $root = defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/../../../../') ?: dirname(__DIR__, 4));
        try {
            $f = $this->svc->getAttachmentForDownload($this->pdo, $this->uid, $attachmentId, $root);
            erp_audit($this->pdo, 'CHAT', 'ATT:' . $attachmentId, 'CHAT_ATTACHMENT_DOWNLOADED', [
                'channel_id' => $f['channel_id'],
                'user_id' => $this->uid,
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);
            if (!headers_sent()) {
                header('Content-Type: ' . ($f['mime_type'] ?: 'application/octet-stream'));
                header('Content-Length: ' . (string)$f['size_bytes']);
                header('Content-Disposition: attachment; filename="' . rawurlencode((string)$f['original_filename']) . '"');
            }
            readfile((string)$f['abs_path']);
            exit;
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'forbidden') ? 403 : 404;
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_ATTACHMENT', $status);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to download attachment.', 'ERR_CHAT_ATTACHMENT', 500);
        }
    }

    public function previewGet(): void
    {
        $attachmentId = (int)($_GET['attachment_id'] ?? 0);
        if ($attachmentId <= 0) ApiResponse::fail('attachment_id required.', 'ERR_CHAT_VALIDATION', 422);
        $root = defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/../../../../') ?: dirname(__DIR__, 4));
        try {
            $f = $this->svc->getAttachmentForDownload($this->pdo, $this->uid, $attachmentId, $root);
            $mime = strtolower((string)$f['mime_type']);
            if (!str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
                ApiResponse::fail('Preview is allowed only for image/pdf.', 'ERR_CHAT_PREVIEW', 422);
            }
            erp_audit($this->pdo, 'CHAT', 'ATT#' . $attachmentId, 'CHAT_ATTACHMENT_PREVIEW', [
                'channel_id' => $f['channel_id'],
                'actor_username' => $this->username,
            ]);
            if (!headers_sent()) {
                header('Content-Type: ' . ($f['mime_type'] ?: 'application/octet-stream'));
                header('Content-Length: ' . (string)$f['size_bytes']);
                header('Content-Disposition: inline; filename="' . rawurlencode((string)$f['original_filename']) . '"');
                header('X-Content-Type-Options: nosniff');
                header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
            }
            readfile((string)$f['abs_path']);
            exit;
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_PREVIEW', 404);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to preview attachment.', 'ERR_CHAT_PREVIEW', 500);
        }
    }

    public function exportPost(): void
    {
        $this->requireWriteGuard();
        if (!$this->svc->isAdminLike($this->role)) {
            ApiResponse::fail('Only ADMIN/SUPERADMIN can create export.', 'ERR_CHAT_FORBIDDEN', 403);
        }
        $channelId = (int)($_POST['channel_id'] ?? 0);
        $start = trim((string)($_POST['start'] ?? ''));
        $end = trim((string)($_POST['end'] ?? ''));
        $format = trim((string)($_POST['format'] ?? 'csv'));
        if ($channelId <= 0 || $start === '' || $end === '') {
            ApiResponse::fail('channel_id/start/end required.', 'ERR_CHAT_VALIDATION', 422);
        }
        $root = defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/../../../../') ?: dirname(__DIR__, 4));
        try {
            $ret = $this->svc->createExport($this->pdo, $this->uid, $channelId, $start . ' 00:00:00', $end . ' 23:59:59', $format, $root);
            erp_audit($this->pdo, 'CHAT', 'EXPORT#' . (int)$ret['id'], 'CHAT_EXPORT_CREATED', [
                'channel_id' => $channelId,
                'actor_username' => $this->username,
                'row_count' => (int)$ret['row_count'],
            ]);
            ApiResponse::ok($ret);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to create export.', 'ERR_CHAT_EXPORT', 500);
        }
    }

    public function exportDownloadGet(): void
    {
        $exportId = (int)($_GET['export_id'] ?? 0);
        if ($exportId <= 0) {
            ApiResponse::fail('export_id required.', 'ERR_CHAT_VALIDATION', 422);
        }
        $root = defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/../../../../') ?: dirname(__DIR__, 4));
        try {
            $f = $this->svc->getExportDownload($this->pdo, $this->uid, $this->role, $exportId, $root);
            erp_audit($this->pdo, 'CHAT', 'EXPORT#' . $exportId, 'CHAT_EXPORT_DOWNLOADED', ['actor_username' => $this->username]);
            if (!headers_sent()) {
                header('Content-Type: ' . $f['mime_type']);
                header('Content-Disposition: attachment; filename="' . rawurlencode((string)$f['file_name']) . '"');
            }
            readfile((string)$f['abs_path']);
            exit;
        } catch (\RuntimeException $e) {
            ApiResponse::fail($e->getMessage(), 'ERR_CHAT_EXPORT_DOWNLOAD', 404);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to download export.', 'ERR_CHAT_EXPORT_DOWNLOAD', 500);
        }
    }

    public function channelRetentionPost(): void
    {
        $this->requireWriteGuard();
        if (!$this->svc->isAdminLike($this->role)) {
            ApiResponse::fail('Only ADMIN/SUPERADMIN can update retention.', 'ERR_CHAT_FORBIDDEN', 403);
        }
        $channelId = (int)($_POST['channel_id'] ?? 0);
        $mode = trim((string)($_POST['retention_mode'] ?? 'none'));
        $daysRaw = trim((string)($_POST['retention_days'] ?? ''));
        $days = $daysRaw === '' ? null : (int)$daysRaw;
        if ($channelId <= 0) {
            ApiResponse::fail('channel_id required.', 'ERR_CHAT_VALIDATION', 422);
        }
        try {
            $ret = $this->svc->updateChannelRetention($this->pdo, $channelId, $mode, $days);
            erp_audit($this->pdo, 'CHAT', 'CHANNEL#' . $channelId, 'CHAT_CHANNEL_RETENTION_UPDATED', ['actor_username' => $this->username, 'meta' => $ret]);
            ApiResponse::ok($ret);
        } catch (\Throwable $e) {
            ApiResponse::fail('Failed to update retention.', 'ERR_CHAT_RETENTION', 500);
        }
    }
}

