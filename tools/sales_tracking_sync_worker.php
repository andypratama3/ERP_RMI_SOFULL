<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../app/Services/SalesTrackingService.php';

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

$enabled = '0';
try {
    $st = $pdo->prepare(
        "SELECT config_value
         FROM system_config
         WHERE config_group='SALES_TRACKING' AND config_key='ONLINE_SYNC_ENABLED' AND is_active=1
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute();
    $enabled = strtolower(trim((string)$st->fetchColumn()));
} catch (Throwable $e) {
    $enabled = '0';
}
if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'ONLINE_SYNC_ENABLED is off'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

$limit = (int)($argv[1] ?? 50);
$limit = max(1, min(500, $limit));
$allowTransition = in_array(strtolower((string)($argv[2] ?? '0')), ['1', 'true', 'yes', 'on'], true);

$svc = new \App\Services\SalesTrackingService();
$ret = $svc->syncActive($pdo, $limit, $allowTransition);

erp_audit(
    $pdo,
    'SCM_TRACKING',
    'CRON',
    'SYNC_ACTIVE',
    [
        'limit' => $limit,
        'allow_status_transition' => $allowTransition ? 1 : 0,
        'result' => $ret,
    ]
);

echo json_encode(['ok' => true, 'result' => $ret], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

