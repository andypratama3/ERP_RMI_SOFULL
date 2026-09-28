<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';
require_once __DIR__ . '/_internal_api_bootstrap.php';

use App\Api\ApiResponse;
use App\Security\RateLimiterService;

require_login();
require_role(['FIN','ADMIN','SUPERADMIN','SYS','ACT','MANAGER']);
internal_api_require_write_guard();

try {
    $pdo = rmi_db_pdo();
    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ip = trim(explode(',', $ip)[0] ?? '0.0.0.0');
    $uid = (int)($_SESSION['user_id'] ?? 0);

    $limiter = new RateLimiterService($pdo);
    $limit = $limiter->hit('GL_ENQUEUE_POSTING', 'U' . $uid . '|IP' . $ip, 60, 20);
    if (!$limit['allowed']) {
        ApiResponse::fail('Rate limit exceeded. Try again later.', 'ERR_RATE_LIMIT', 429, [
            'rate_limit' => [
                'remaining' => $limit['remaining'],
                'reset_at' => date('c', (int)$limit['reset_at']),
            ],
        ]);
    }

    $module = strtoupper(trim((string)($_POST['module'] ?? '')));
    $event = strtoupper(trim((string)($_POST['event'] ?? '')));
    $sourceRef = trim((string)($_POST['source_ref'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $description = trim((string)($_POST['description'] ?? ''));
    $runAt = trim((string)($_POST['run_at'] ?? ''));
    if ($runAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $runAt)) {
        $runAt = date('Y-m-d H:i:s');
    }

    if ($module === '' || $event === '' || $sourceRef === '' || $amount <= 0) {
        ApiResponse::fail('module,event,source_ref,amount are required.', 'ERR_VALIDATION', 422);
    }

    $payload = [
        'module' => $module,
        'event' => $event,
        'source_ref' => $sourceRef,
        'amount' => $amount,
        'description' => $description !== '' ? $description : ('Queued posting ' . $sourceRef),
        'user_id' => $uid,
        'request_id' => 'req-' . date('YmdHis') . '-' . substr(sha1($sourceRef . microtime(true)), 0, 10),
        'ok' => true,
    ];
    $st = $pdo->prepare(
        "INSERT INTO jobs (job_type, payload_json, status, attempts, run_at, created_at, updated_at)
         VALUES ('gl_posting_batch', ?, 'PENDING', 0, ?, NOW(), NOW())"
    );
    $st->execute([json_encode($payload, JSON_UNESCAPED_SLASHES), $runAt]);
    $jobId = (int)$pdo->lastInsertId();

    ApiResponse::ok([
        'job_id' => $jobId,
        'job_type' => 'gl_posting_batch',
        'run_at' => $runAt,
        'rate_limit' => [
            'remaining' => $limit['remaining'],
            'reset_at' => date('c', (int)$limit['reset_at']),
        ],
        'payload' => $payload,
    ], [], 201);
} catch (Throwable $e) {
    ApiResponse::fail('Failed to enqueue GL posting job.', 'ERR_GL_ENQUEUE', 500);
}
