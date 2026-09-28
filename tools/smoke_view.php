<?php
declare(strict_types=1);
require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/_lib/bootstrap.php';
require_once __DIR__ . '/tools_state_lib.php';
// Placeholder: render smoke_http_last.json
$smoke = ts_read_json(ts_storage_logs_dir() . '/smoke_http_last.json');
header('Content-Type: application/json');
echo json_encode($smoke, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
