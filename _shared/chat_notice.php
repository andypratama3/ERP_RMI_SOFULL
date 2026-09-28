<?php
/**
 * Chat Notice Helper - kirim notifikasi ke Chat saat ada trigger dari proses bisnis.
 *
 * Usage (dari sales_do, purchases_po, dll):
 *   require_once __DIR__ . '/../_shared/chat_notice.php';
 *   chat_notice_do($pdo, $doId, 'delivered', 'DO #' . $doId . ' telah delivered', [2, 5]);  // user 2,5 dapat @mention
 *   chat_notice_po($pdo, $poId, 'approved', 'PO #' . $poId . ' disetujui', [3]);
 *
 * User yang di-mention akan dapat badge unread di Chat sidebar.
 */
declare(strict_types=1);

if (!function_exists('chat_notice_post')) {
    /**
     * Post notice ke channel context. Non-fatal: jika chat error, proses utama tetap jalan.
     *
     * @param PDO $pdo
     * @param string $entityType DO|PO|AP
     * @param int $entityId
     * @param string $event Nama event (delivered, approved, created, dll)
     * @param string $message Pesan singkat
     * @param array<int> $mentionUserIds User ID yang di-@mention (dapat notifikasi)
     * @param array<int> $addAsMembers User ID yang ditambah sebagai member (opsional)
     */
    function chat_notice_post(
        PDO $pdo,
        string $entityType,
        int $entityId,
        string $event,
        string $message,
        array $mentionUserIds = [],
        array $addAsMembers = []
    ): void {
        try {
            if (!class_exists(\App\Services\ChatService::class)) {
                $path = dirname(__DIR__) . '/app/Services/ChatService.php';
                if (is_file($path)) {
                    require_once $path;
                    require_once dirname(__DIR__) . '/app/Services/ChatMentionService.php';
                    require_once dirname(__DIR__) . '/app/Services/ChatAttachmentService.php';
                }
            }
            $svc = new \App\Services\ChatService();
            $svc->ensureReady($pdo);
            $svc->postProcessNotice($pdo, $entityType, $entityId, $event, $message, $mentionUserIds, $addAsMembers);
        } catch (\Throwable $e) {
            error_log('chat_notice_post: ' . $e->getMessage());
        }
    }
}

if (!function_exists('chat_notice_do')) {
    /** Helper: notice untuk DO (sales). */
    function chat_notice_do(PDO $pdo, int $doId, string $event, string $message, array $mentionUserIds = []): void {
        chat_notice_post($pdo, 'DO', $doId, $event, $message, $mentionUserIds);
    }
}

if (!function_exists('chat_notice_po')) {
    /** Helper: notice untuk PO (purchases). */
    function chat_notice_po(PDO $pdo, int $poId, string $event, string $message, array $mentionUserIds = []): void {
        chat_notice_post($pdo, 'PO', $poId, $event, $message, $mentionUserIds);
    }
}

if (!function_exists('chat_notice_ap')) {
    /** Helper: notice untuk AP (invoice). */
    function chat_notice_ap(PDO $pdo, int $apId, string $event, string $message, array $mentionUserIds = []): void {
        chat_notice_post($pdo, 'AP', $apId, $event, $message, $mentionUserIds);
    }
}
