<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/Controllers/Api/V1/ChatApiController.php';

$ctl = new \App\Controllers\Api\V1\ChatApiController();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $ctl->messagesPost();
}
$ctl->messagesGet();

