<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';
require_once __DIR__ . '/../../../_shared/erp_audit.php';

use App\Api\ApiResponse;

require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['CHAT.VIEW']);
}

if (!function_exists('chat_api_uid')) {
    function chat_api_uid(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('chat_api_username')) {
    function chat_api_username(): string
    {
        return trim((string)($_SESSION['username'] ?? 'user'));
    }
}

if (!function_exists('chat_api_role')) {
    function chat_api_role(): string
    {
        return strtoupper(trim((string)($_SESSION['level'] ?? ($_SESSION['role'] ?? ''))));
    }
}

if (!function_exists('chat_api_can_delete')) {
    function chat_api_can_delete(): bool
    {
        return in_array(chat_api_role(), ['SYS', 'ADMIN', 'SUPERADMIN'], true);
    }
}

if (!function_exists('chat_api_require_write_guard')) {
    function chat_api_require_write_guard(): void
    {
        require_post();
        verify_csrf();
    }
}

if (!function_exists('chat_api_pdo')) {
    function chat_api_pdo(): PDO
    {
        return rmi_db_pdo();
    }
}

if (!function_exists('chat_api_ensure_ready')) {
    function chat_api_ensure_ready(PDO $pdo): void
    {
        $needed = ['chat_channels', 'chat_channel_members', 'chat_messages'];
        foreach ($needed as $t) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
            $st->execute([$t]);
            if ((int)$st->fetchColumn() <= 0) {
                ApiResponse::fail('Chat module is not migrated yet.', 'ERR_CHAT_NOT_READY', 503);
            }
        }
    }
}

if (!function_exists('chat_api_find_channel')) {
    function chat_api_find_channel(PDO $pdo, int $channelId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM chat_channels WHERE id=? LIMIT 1");
        $st->execute([$channelId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('chat_api_is_member')) {
    function chat_api_is_member(PDO $pdo, int $channelId, int $userId): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM chat_channel_members WHERE channel_id=? AND user_id=? LIMIT 1");
        $st->execute([$channelId, $userId]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('chat_api_can_access_channel')) {
    function chat_api_can_access_channel(PDO $pdo, array $channel, int $userId): bool
    {
        $type = strtoupper((string)($channel['type'] ?? 'CHANNEL'));
        if ($type === 'DM') {
            $a = (int)($channel['dm_user_low'] ?? 0);
            $b = (int)($channel['dm_user_high'] ?? 0);
            if ($userId > 0 && ($userId === $a || $userId === $b)) {
                return true;
            }
            return chat_api_is_member($pdo, (int)$channel['id'], $userId);
        }

        $isPrivate = (int)($channel['is_private'] ?? 0) === 1;
        if (!$isPrivate) {
            return true;
        }
        return chat_api_is_member($pdo, (int)$channel['id'], $userId);
    }
}

if (!function_exists('chat_api_ensure_member')) {
    function chat_api_ensure_member(PDO $pdo, int $channelId, int $userId): void
    {
        $st = $pdo->prepare("INSERT INTO chat_channel_members (channel_id, user_id, joined_at) VALUES (?, ?, NOW())
                             ON DUPLICATE KEY UPDATE joined_at=joined_at");
        $st->execute([$channelId, $userId]);
    }
}

if (!function_exists('chat_api_user_exists')) {
    function chat_api_user_exists(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $st = $pdo->prepare("SELECT 1 FROM master_system_login WHERE id=? LIMIT 1");
            $st->execute([$userId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('chat_api_rate_limit_send')) {
    function chat_api_rate_limit_send(PDO $pdo, int $userId, string $ip, int $maxPerMinute = 25): void
    {
        $win = date('YmdHi');
        $st = $pdo->prepare("INSERT INTO chat_rate_limits (user_id, ip_address, window_minute, send_count, created_at, updated_at)
                             VALUES (?, ?, ?, 1, NOW(), NOW())
                             ON DUPLICATE KEY UPDATE send_count = send_count + 1, updated_at=NOW()");
        $st->execute([$userId > 0 ? $userId : null, $ip, $win]);

        $st2 = $pdo->prepare("SELECT send_count FROM chat_rate_limits WHERE user_id <=> ? AND ip_address=? AND window_minute=? LIMIT 1");
        $st2->execute([$userId > 0 ? $userId : null, $ip, $win]);
        $n = (int)$st2->fetchColumn();
        if ($n > $maxPerMinute) {
            ApiResponse::fail('Rate limit exceeded. Try again shortly.', 'ERR_CHAT_RATE_LIMIT', 429);
        }
    }
}

