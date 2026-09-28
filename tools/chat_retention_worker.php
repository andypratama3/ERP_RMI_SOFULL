<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../app/Services/ChatService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SESSION['username'] = $_SESSION['username'] ?? 'SYSTEM';

$pdo = rmi_db_pdo();
erp_audit_ensure($pdo);
$svc = new \App\Services\ChatService();
$ret = $svc->retentionPurgeSoftDeleted($pdo, 1000);

erp_audit(
    $pdo,
    'CHAT',
    'CHAN:RETENTION',
    'CHAT_RETENTION_PURGE',
    [
        'purged_count' => (int)($ret['purged_messages'] ?? 0),
        'purged_attachments' => (int)($ret['purged_attachments'] ?? 0),
    ]
);

echo json_encode(['ok' => true, 'result' => $ret], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

