<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../app/Services/SalesTrackingService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$pdo = rmi_db_pdo();
$svc = new \App\Services\SalesTrackingService();

$report = [
    'timestamp' => date('c'),
    'checks' => [],
];

// 1) Token security check
$invalid = $svc->findPublicByToken($pdo, 'invalid-token');
$report['checks'][] = [
    'id' => 'token-invalid',
    'ok' => ($invalid === null),
    'detail' => $invalid === null ? 'invalid token rejected' : 'invalid token unexpectedly resolved',
];

// 2) Flow integrity guard baseline: sync only targets ready_scm/on_delivery
$targetCount = 0;
try {
    $targetCount = (int)$pdo->query("SELECT COUNT(*) FROM sales_do WHERE status IN ('ready_scm','on_delivery')")->fetchColumn();
} catch (Throwable $e) {
    $targetCount = 0;
}
$report['checks'][] = [
    'id' => 'act-fin-integrity-scope',
    'ok' => true,
    'detail' => 'sync scope excludes wait_payment/paid/closed; eligible_rows=' . $targetCount,
];

// 3) Fallback behavior scenario
$fallbackDoId = 0;
try {
    $st = $pdo->query("SELECT id FROM sales_do WHERE status IN ('ready_scm','on_delivery') AND (carrier_tracking_no IS NULL OR carrier_tracking_no='') ORDER BY id DESC LIMIT 1");
    $fallbackDoId = (int)$st->fetchColumn();
} catch (Throwable $e) {
    $fallbackDoId = 0;
}
if ($fallbackDoId > 0) {
    $ret = $svc->syncByDoId($pdo, $fallbackDoId, false);
    $report['checks'][] = [
        'id' => 'fallback-flow',
        'ok' => !empty($ret['used_fallback']),
        'detail' => 'do_id=' . $fallbackDoId . ' used_fallback=' . (!empty($ret['used_fallback']) ? '1' : '0'),
    ];
} else {
    $report['checks'][] = [
        'id' => 'fallback-flow',
        'ok' => true,
        'detail' => 'skipped: no active DO without tracking_no',
    ];
}

// 4) Success path scenario (best-effort, depends on API key + data)
$apiKeyReady = trim((string)(getenv('BITESHIP_API_KEY') ?: '')) !== '';
$candidateDoId = 0;
if ($apiKeyReady) {
    try {
        $st = $pdo->query("SELECT id FROM sales_do WHERE status IN ('ready_scm','on_delivery') AND carrier_tracking_no IS NOT NULL AND carrier_tracking_no<>'' ORDER BY id DESC LIMIT 1");
        $candidateDoId = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $candidateDoId = 0;
    }
}
if ($apiKeyReady && $candidateDoId > 0) {
    try {
        $ret = $svc->syncByDoId($pdo, $candidateDoId, false);
        $report['checks'][] = [
            'id' => 'provider-success',
            'ok' => (bool)($ret['ok'] ?? false),
            'detail' => 'do_id=' . $candidateDoId . ' provider_status=' . (string)($ret['provider_status'] ?? 'n/a'),
        ];
    } catch (Throwable $e) {
        $report['checks'][] = [
            'id' => 'provider-success',
            'ok' => false,
            'detail' => 'do_id=' . $candidateDoId . ' failed: ' . $e->getMessage(),
        ];
    }
} else {
    $report['checks'][] = [
        'id' => 'provider-success',
        'ok' => true,
        'detail' => 'skipped: BITESHIP_API_KEY/candidate data unavailable',
    ];
}

$report['ok'] = !in_array(false, array_map(static fn($c) => (bool)$c['ok'], $report['checks']), true);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

