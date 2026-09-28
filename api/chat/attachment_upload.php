<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/Controllers/Api/V1/ChatApiController.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    $rid = 'req-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 10);
    echo json_encode([
        'ok' => false,
        'message' => 'POST required',
        'data' => [],
        'request_id' => $rid,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_POST['message_text']) || trim((string)$_POST['message_text']) === '') {
    $_POST['message_text'] = '[Attachment]';
}

$ctl = new \App\Controllers\Api\V1\ChatApiController();
$ctl->messagesPost();

