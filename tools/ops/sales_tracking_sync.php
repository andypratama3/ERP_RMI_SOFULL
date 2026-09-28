<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/bootstrap.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';
require_once __DIR__ . '/../../app/Services/SalesTrackingService.php';

use App\Services\SalesTrackingService;

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
$svc = new SalesTrackingService();

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_login();
    require_role(['SYS','SUPERADMIN','ADMIN','MANAGER','SCM']);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }
    verify_csrf();
}

$cfgEnabled = '0';
try {
    $st = $pdo->prepare("SELECT config_value FROM system_config WHERE config_group='SALES_TRACKING' AND config_key='ONLINE_SYNC_ENABLED' AND is_active=1 ORDER BY id DESC LIMIT 1");
    $st->execute();
    $cfgEnabled = (string)$st->fetchColumn();
} catch (Throwable $e) {
    $cfgEnabled = '0';
}

$force = false;
$limit = 50;
if ($isCli) {
    $force = in_array('--force', $_SERVER['argv'] ?? [], true);
    foreach (($_SERVER['argv'] ?? []) as $arg) {
        if (str_starts_with((string)$arg, '--limit=')) {
            $limit = max(1, min(500, (int)substr((string)$arg, 8)));
        }
    }
} else {
    $force = ((string)($_POST['force'] ?? '0') === '1');
    $limit = max(1, min(500, (int)($_POST['limit'] ?? 50)));
}

if (!$force && trim($cfgEnabled) !== '1') {
    $out = ['ok' => false, 'message' => 'ONLINE_SYNC_ENABLED=0', 'hint' => 'Aktifkan di system_config SALES_TRACKING'];
    if ($isCli) {
        echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
    exit;
}

$ret = $svc->syncActive($pdo, $limit, true);
try {
    erp_audit_ensure($pdo);
    erp_audit($pdo, 'SALES', 'TRACKING_SYNC', 'SYNC_ACTIVE', [
        'total' => $ret['total'] ?? 0,
        'ok' => $ret['ok'] ?? 0,
        'failed' => $ret['failed'] ?? 0,
        'limit' => $limit,
        'forced' => $force ? 1 : 0,
    ]);
} catch (Throwable $e) {
}

if ($isCli) {
    echo json_encode($ret, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'data' => $ret], JSON_UNESCAPED_SLASHES);
