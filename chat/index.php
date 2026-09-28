<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['CHAT.VIEW']);
}
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/_chat_context.php';
require_once __DIR__ . '/../app/Controllers/ChatController.php';

const CHAT_CONTEXT_FLASH = 'chat_context_flash_error';

$baseProject = function_exists('auth_base_project') ? auth_base_project() : '';

/** @var array<string, mixed>|null $chatContextOffer */
$chatContextOffer = null;

$chatFlashError = isset($_SESSION[CHAT_CONTEXT_FLASH]) ? trim((string)$_SESSION[CHAT_CONTEXT_FLASH]) : '';
unset($_SESSION[CHAT_CONTEXT_FLASH]);

// ── POST: mutasi channel konteks (CSRF) + redirect PRG ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['chat_open_context'] ?? '') === '1') {
    if (function_exists('verify_csrf')) {
        verify_csrf($_POST['csrf_token'] ?? null);
    }
    $et = strtoupper(trim((string)($_POST['entity_type'] ?? '')));
    $eid = (int)($_POST['entity_id'] ?? 0);
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!in_array($et, ['DO', 'PO', 'AP'], true) || $eid <= 0 || $uid <= 0) {
        $_SESSION[CHAT_CONTEXT_FLASH] = 'Permintaan tidak valid.';
        rmi_redirect(chat_context_build_index_url($baseProject));
    }
    try {
        $pdo = rmi_db_pdo();
        $result = chat_context_ensure_channel($pdo, $uid, $et, $eid);
        if ($result['ok'] && $result['channel_id'] > 0) {
            rmi_redirect(chat_context_build_index_url($baseProject, ['cid' => $result['channel_id']]));
        }
        $_SESSION[CHAT_CONTEXT_FLASH] = $result['user_message'] !== ''
            ? $result['user_message']
            : 'Gagal membuka channel chat.';
    } catch (Throwable $e) {
        chat_context_log_error($e, $et, $eid, 'post_ensure');
        $_SESSION[CHAT_CONTEXT_FLASH] = 'Tidak dapat membuka channel chat. Tim IT telah menerima log error.';
    }
    rmi_redirect(chat_context_build_index_url($baseProject));
}

// ── GET ?context=DO:id — hanya baca: redirect ke cid jika sudah ada; else tawarkan POST ──
$context = trim((string)($_GET['context'] ?? ''));
if ($context !== '' && preg_match('/^(DO|PO|AP):(\d+)$/i', $context, $m)) {
    $entityType = strtoupper((string)$m[1]);
    $entityId = (int)$m[2];
    try {
        $pdo = rmi_db_pdo();
        if (!chat_context_document_exists($pdo, $entityType, $entityId)) {
            $msg = $entityType === 'DO' ? 'Delivery Order tidak ditemukan.' : ($entityType === 'PO' ? 'Purchase Order tidak ditemukan.' : 'Invoice AP tidak ditemukan.');
            $_SESSION[CHAT_CONTEXT_FLASH] = $msg;
            rmi_redirect(chat_context_build_index_url($baseProject));
        }
        $existingCid = chat_context_find_channel_id($pdo, $entityType, $entityId);
        if ($existingCid > 0) {
            rmi_redirect(chat_context_build_index_url($baseProject, ['cid' => $existingCid]));
        }
        $chatContextOffer = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'label' => chat_context_offer_label($entityType, $entityId),
        ];
    } catch (Throwable $e) {
        chat_context_log_error($e, $entityType, $entityId, 'get_context_readonly');
        $_SESSION[CHAT_CONTEXT_FLASH] = 'Tidak dapat memuat konteks chat. Tim IT telah menerima log error.';
        rmi_redirect(chat_context_build_index_url($baseProject));
    }
}

$controller = new \App\Controllers\ChatController();
$controller->setContextOffer($chatContextOffer);
$controller->setFlashError($chatFlashError !== '' ? $chatFlashError : null);
$controller->render();
