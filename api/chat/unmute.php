<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/Controllers/Api/V1/ChatApiController.php';

$ctl = new \App\Controllers\Api\V1\ChatApiController();
$ctl->unmutePost();

