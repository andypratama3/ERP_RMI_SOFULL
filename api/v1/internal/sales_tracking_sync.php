<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';
require_once __DIR__ . '/../../../_shared/erp_audit.php';
require_once __DIR__ . '/_internal_api_bootstrap.php';

use App\Api\ApiResponse;
use App\Security\RateLimiterService;
use App\Services\SalesTrackingService;

require_login();
require_role(['SCM', 'ADMIN', 'SUPERADMIN', 'SYS', 'MANAGER']);
internal_api_require_write_guard();

try {
    $pdo = rmi_db_pdo();
    erp_audit_ensure($pdo);
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ip = trim(explode(',', $ip)[0] ?? '0.0.0.0');

    $limiter = new RateLimiterService($pdo);
    $limit = $limiter->hit('SALES_TRACKING_SYNC', 'U' . $uid . '|IP' . $ip, 60, 30);
    if (!$limit['allowed']) {
        ApiResponse::fail('Rate limit exceeded. Try again shortly.', 'ERR_RATE_LIMIT', 429, [
            'rate_limit' => [
                'remaining' => $limit['remaining'],
                'reset_at' => date('c', (int)$limit['reset_at']),
            ],
        ]);
    }

    $svc = new SalesTrackingService();
    $allowTransition = ((int)($_POST['allow_status_transition'] ?? 0)) === 1;
    $doId = (int)($_POST['do_id'] ?? 0);
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'single')));

    if ($mode === 'active' || $doId <= 0) {
        $syncLimit = max(1, min(500, (int)($_POST['limit'] ?? 50)));
        $result = $svc->syncActive($pdo, $syncLimit, $allowTransition);
        erp_audit($pdo, 'SCM_TRACKING', 'BATCH', 'SYNC_ACTIVE', [
            'result' => $result,
            'allow_status_transition' => $allowTransition ? 1 : 0,
            'actor_user_id' => $uid,
        ]);
        ApiResponse::ok($result, [
            'rate_limit' => [
                'remaining' => $limit['remaining'],
                'reset_at' => date('c', (int)$limit['reset_at']),
            ],
        ]);
    }

    $item = $svc->syncByDoId($pdo, $doId, $allowTransition);
    erp_audit($pdo, 'SCM_TRACKING', 'DO#' . $doId, 'SYNC_SINGLE', [
        'result' => $item,
        'allow_status_transition' => $allowTransition ? 1 : 0,
        'actor_user_id' => $uid,
    ]);
    ApiResponse::ok(['item' => $item], [
        'rate_limit' => [
            'remaining' => $limit['remaining'],
            'reset_at' => date('c', (int)$limit['reset_at']),
        ],
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to sync sales tracking.', 'ERR_SALES_TRACKING_SYNC', 500);
}

__halt_compiler();

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';
require_once __DIR__ . '/../../../_shared/erp_audit.php';
require_once __DIR__ . '/../../../app/Api/ApiResponse.php';
require_once __DIR__ . '/../../../app/Security/RateLimiterService.php';
require_once __DIR__ . '/../../../app/Services/SalesTrackingService.php';
require_once __DIR__ . '/_internal_api_bootstrap.php';

use App\Api\ApiResponse;
use App\Security\RateLimiterService;
use App\Services\SalesTrackingService;

require_login();
require_role(['SCM', 'ADMIN', 'SUPERADMIN', 'SYS', 'MANAGER', 'CRM']);
internal_api_require_write_guard();

try {
    $pdo = rmi_db_pdo();
    erp_audit_ensure($pdo);

    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ip = trim(explode(',', $ip)[0] ?? '0.0.0.0');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $actorKey = 'U' . $uid . '|IP' . $ip;

    $limiter = new RateLimiterService($pdo);
    $rate = $limiter->hit('SALES_TRACKING_SYNC', $actorKey, 60, 30);
    if (!$rate['allowed']) {
        ApiResponse::fail('Rate limit exceeded. Try again shortly.', 'ERR_RATE_LIMIT', 429, [
            'rate_limit' => [
                'remaining' => $rate['remaining'],
                'reset_at' => date('c', (int)$rate['reset_at']),
            ],
        ]);
    }

    $svc = new SalesTrackingService();
    $doId = (int)($_POST['do_id'] ?? 0);
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'single')));
    $allowTransition = ((int)($_POST['allow_status_transition'] ?? 0)) === 1;

    if ($mode === 'active' || $doId <= 0) {
        $limitRows = max(1, min(500, (int)($_POST['limit'] ?? 50)));
        $result = $svc->syncActive($pdo, $limitRows, $allowTransition);
        erp_audit($pdo, 'SCM_TRACKING', 'BATCH', 'SYNC_ACTIVE', [
            'ok' => (int)($result['ok'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'total' => (int)($result['total'] ?? 0),
            'allow_status_transition' => $allowTransition ? 1 : 0,
            'actor_user_id' => $uid,
        ]);
        ApiResponse::ok($result, [
            'rate_limit' => [
                'remaining' => $rate['remaining'],
                'reset_at' => date('c', (int)$rate['reset_at']),
            ],
        ]);
    }

    $item = $svc->syncByDoId($pdo, $doId, $allowTransition);
    erp_audit($pdo, 'SCM_TRACKING', 'DO#' . $doId, 'SYNC_SINGLE', [
        'result' => $item,
        'allow_status_transition' => $allowTransition ? 1 : 0,
        'actor_user_id' => $uid,
    ]);
    ApiResponse::ok(['item' => $item], [
        'rate_limit' => [
            'remaining' => $rate['remaining'],
            'reset_at' => date('c', (int)$rate['reset_at']),
        ],
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to sync sales tracking.', 'ERR_SALES_TRACKING_SYNC', 500);
}
