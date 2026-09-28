<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/db.php';

$pdo = rmi_db_pdo();
$checks = [];
$ok = true;
$needTables = [
    'chat_channels',
    'chat_channel_members',
    'chat_messages',
    'chat_user_presence',
    'chat_user_channel_settings',
    'chat_exports',
    'chat_quota_config',
    'chat_quota_usage',
    'chat_message_idempotency',
    'chat_message_context',
];

foreach ($needTables as $table) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $st->execute([$table]);
    $exists = (int)$st->fetchColumn() > 0;
    $checks[] = ['table' => $table, 'exists' => $exists];
    if (!$exists) {
        $ok = false;
    }
}

$out = [
    'state_version' => 1,
    'checked_at' => date('c'),
    'ok' => $ok,
    'checks' => $checks,
];

$path = __DIR__ . '/../../storage/logs/chat_smoke_last.json';
@file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

