<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/tools_ui_helpers.php';
require_once $root . '/_shared/db.php';

/**
 * @return array{name:string,status:string,ok:bool,message:string,meta:array<string,mixed>}
 */
function st_check_result(string $name, string $status, string $message, array $meta = []): array
{
    $normalized = strtolower(trim($status));
    if (!in_array($normalized, ['pass', 'fail', 'warn'], true)) {
        $normalized = 'fail';
    }

    return [
        'name' => $name,
        'status' => $normalized,
        'ok' => $normalized !== 'fail',
        'message' => $message,
        'meta' => $meta,
    ];
}

function st_has_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name=? AND column_name=?'
    );
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * @return array{ok:bool,db_name:string}
 */
function st_db_ready(PDO $pdo): array
{
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    return ['ok' => $db !== '', 'db_name' => $db];
}

$strict = in_array('--strict', $_SERVER['argv'] ?? [], true);
// SALES_TRACKING_ALLOW_LEGACY=1: ubah act_fin_integrity FAIL → WARN agar tidak NO-GO di production
// dengan data legacy. Set via env atau config.
$allowLegacy = (string)(getenv('SALES_TRACKING_ALLOW_LEGACY') ?: '0') === '1'
    || (is_file($root . '/config/sales_tracking.php') && (bool)(include $root . '/config/sales_tracking.php')['allow_legacy'] ?? false);
$results = [];
$errors = [];

try {
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
    $dbInfo = st_db_ready($pdo);
    if (!$dbInfo['ok']) {
        throw new RuntimeException('database_not_selected');
    }

    $requiredColumns = [
        'carrier_tracking_no',
        'tracking_last_sync_at',
        'tracking_last_status',
        'tracking_public_token',
        'fallback_live_location_url',
        'scm_delivered_at',
        'act_ready_fin_at',
    ];
    $missingColumns = [];
    foreach ($requiredColumns as $col) {
        if (!st_has_column($pdo, 'sales_do', $col)) {
            $missingColumns[] = $col;
        }
    }
    if ($missingColumns !== []) {
        $results[] = st_check_result(
            'tracking_schema_prerequisite',
            'warn',
            'Kolom tracking belum lengkap; jalankan migrasi tracking dulu sebelum E2E strict.',
            ['missing_columns' => $missingColumns]
        );
    }

    if ($missingColumns === []) {
    // 1) Skenario sukses: ada sample DO yang pernah sync sukses.
    $sqlSuccess = "
        SELECT COUNT(*) FROM sales_do d
        WHERE COALESCE(TRIM(d.carrier_tracking_no), '') <> ''
          AND d.tracking_last_sync_at IS NOT NULL
          AND COALESCE(TRIM(d.tracking_last_status), '') <> ''
          AND UPPER(TRIM(d.tracking_last_status)) <> 'SYNC_ERROR'
          AND LOWER(TRIM(d.status)) IN ('on_delivery','delivered','wait_payment','paid','closed')
    ";
    $successCount = (int)$pdo->query($sqlSuccess)->fetchColumn();
    if ($successCount > 0) {
        $results[] = st_check_result('success_sync', 'pass', 'Ada DO dengan tracking sync sukses.', [
            'synced_rows' => $successCount,
        ]);
    } else {
        $results[] = st_check_result('success_sync', 'warn', 'Belum ada sampel DO sync sukses untuk divalidasi.', [
            'synced_rows' => 0,
        ]);
    }

    // 2) Skenario fallback: URL fallback valid (https) dan dipakai saat sync error.
    $sqlInvalidFallback = "
        SELECT COUNT(*) FROM sales_do
        WHERE COALESCE(TRIM(fallback_live_location_url), '') <> ''
          AND fallback_live_location_url NOT LIKE 'https://%'
    ";
    $invalidFallbackCount = (int)$pdo->query($sqlInvalidFallback)->fetchColumn();

    $sqlSyncErrorWithoutFallback = "
        SELECT COUNT(*) FROM sales_do
        WHERE UPPER(TRIM(COALESCE(tracking_last_status, ''))) = 'SYNC_ERROR'
          AND LOWER(TRIM(COALESCE(status, ''))) IN ('ready_scm','on_delivery')
          AND COALESCE(TRIM(fallback_live_location_url), '') = ''
    ";
    $syncErrorWithoutFallback = (int)$pdo->query($sqlSyncErrorWithoutFallback)->fetchColumn();

    $sqlSyncErrorWithFallback = "
        SELECT COUNT(*) FROM sales_do
        WHERE UPPER(TRIM(COALESCE(tracking_last_status, ''))) = 'SYNC_ERROR'
          AND COALESCE(TRIM(fallback_live_location_url), '') <> ''
          AND fallback_live_location_url LIKE 'https://%'
    ";
    $syncErrorWithFallback = (int)$pdo->query($sqlSyncErrorWithFallback)->fetchColumn();

    $fallbackFail = ($invalidFallbackCount > 0) || ($syncErrorWithoutFallback > 0);
    $fallbackStatus = $fallbackFail ? 'fail' : (($syncErrorWithFallback > 0) ? 'pass' : 'warn');
    $fallbackMessage = $fallbackFail
        ? 'Ditemukan pelanggaran fallback URL/coverage pada DO aktif.'
        : (($syncErrorWithFallback > 0)
            ? 'Fallback live location tervalidasi untuk kasus sync error.'
            : 'Belum ada sampel sync error+fallback untuk divalidasi.');
    $results[] = st_check_result('fallback_tracking', $fallbackStatus, $fallbackMessage, [
        'invalid_https_url_rows' => $invalidFallbackCount,
        'sync_error_without_fallback_rows' => $syncErrorWithoutFallback,
        'sync_error_with_fallback_rows' => $syncErrorWithFallback,
    ]);

    // 3) Skenario keamanan token: unik, panjang aman, dan endpoint publik tersedia.
    $sqlDuplicateToken = "
        SELECT COUNT(*) FROM (
            SELECT tracking_public_token
            FROM sales_do
            WHERE COALESCE(TRIM(tracking_public_token), '') <> ''
            GROUP BY tracking_public_token
            HAVING COUNT(*) > 1
        ) x
    ";
    $duplicateTokenCount = (int)$pdo->query($sqlDuplicateToken)->fetchColumn();

    $sqlWeakToken = "
        SELECT COUNT(*) FROM sales_do
        WHERE COALESCE(TRIM(tracking_public_token), '') <> ''
          AND (
            CHAR_LENGTH(TRIM(tracking_public_token)) < 32
            OR tracking_public_token NOT REGEXP '^[A-Za-z0-9_-]+$'
          )
    ";
    $weakTokenCount = (int)$pdo->query($sqlWeakToken)->fetchColumn();

    $publicTrackingPage = $root . '/sales/tracking_public.php';
    $publicPageExists = is_file($publicTrackingPage);
    $tokenStatus = ($duplicateTokenCount > 0 || $weakTokenCount > 0)
        ? 'fail'
        : ($publicPageExists ? 'pass' : 'warn');
    $tokenMessage = ($duplicateTokenCount > 0 || $weakTokenCount > 0)
        ? 'Ditemukan token publik yang tidak aman/duplikat.'
        : ($publicPageExists
            ? 'Token publik aman secara struktur dan endpoint publik tersedia.'
            : 'Token publik aman secara struktur, tetapi endpoint publik belum tersedia.');
    $results[] = st_check_result('token_security', $tokenStatus, $tokenMessage, [
        'duplicate_token_groups' => $duplicateTokenCount,
        'weak_token_rows' => $weakTokenCount,
        'public_tracking_page_exists' => $publicPageExists,
    ]);

    // 4) Integritas flow ACT/FIN: tidak boleh melompat tanpa timestamp transisi wajib.
    $hasFinPaidAt = st_has_column($pdo, 'sales_do', 'fin_paid_at');
    $sqlFlowViolation = "
        SELECT COUNT(*) FROM sales_do
        WHERE LOWER(TRIM(COALESCE(status,''))) IN ('wait_payment','paid','closed','fin_done','paid_done')
          AND (
            scm_delivered_at IS NULL
            OR act_ready_fin_at IS NULL
            OR act_ready_fin_at < scm_delivered_at
          )
    ";
    $flowViolationCount = (int)$pdo->query($sqlFlowViolation)->fetchColumn();

    $paidOrderViolationCount = 0;
    if ($hasFinPaidAt) {
        $sqlPaidOrderViolation = "
            SELECT COUNT(*) FROM sales_do
            WHERE LOWER(TRIM(COALESCE(status,''))) IN ('paid','closed','fin_done','paid_done')
              AND (
                fin_paid_at IS NULL
                OR fin_paid_at < act_ready_fin_at
              )
        ";
        $paidOrderViolationCount = (int)$pdo->query($sqlPaidOrderViolation)->fetchColumn();
    }

    $integrityFail = ($flowViolationCount > 0) || ($paidOrderViolationCount > 0);
    // allowLegacy: ubah FAIL → WARN agar tidak memblokir cutover di production dengan data legacy
    $integrityStatus = $integrityFail ? ($allowLegacy ? 'warn' : 'fail') : 'pass';
    $integrityMsg = $integrityFail
        ? 'Ditemukan pelanggaran integritas transisi ACT/FIN.' . ($allowLegacy ? ' [LEGACY_ALLOWED]' : '')
        : 'Integritas transisi ACT/FIN terjaga.';
    $results[] = st_check_result(
        'act_fin_integrity',
        $integrityStatus,
        $integrityMsg,
        [
            'flow_violation_rows' => $flowViolationCount,
            'paid_order_violation_rows' => $paidOrderViolationCount,
            'fin_paid_at_column' => $hasFinPaidAt,
        ]
    );
    }
} catch (Throwable $e) {
    $results[] = st_check_result('sales_tracking_checks', 'fail', 'Eksekusi check gagal.', [
        'error' => tools_mask_sensitive($e->getMessage()),
    ]);
    $errors[] = 'runtime:' . tools_mask_sensitive($e->getMessage());
}

$failCount = count(array_filter($results, static fn(array $r): bool => ($r['status'] ?? '') === 'fail'));
$warnCount = count(array_filter($results, static fn(array $r): bool => ($r['status'] ?? '') === 'warn'));
$overallOk = $failCount === 0 && (!$strict || $warnCount === 0);

foreach ($results as $row) {
    if (($row['status'] ?? '') === 'fail') {
        $errors[] = (string)$row['name'] . ':' . tools_mask_sensitive((string)$row['message']);
    }
}

$payload = [
    'state_version' => 1,
    'run_at' => date(DateTimeInterface::ATOM),
    'strict' => $strict,
    'overall_ok' => $overallOk,
    'summary' => [
        'total' => count($results),
        'fail_count' => $failCount,
        'warning_count' => $warnCount,
        'score' => max(0, 100 - ($failCount * 25) - ($warnCount * 10)),
    ],
    'checks' => $results,
    'errors_masked' => $errors,
];

ts_write_json(ts_storage_logs_dir() . '/sales_tracking_checks.last.json', $payload);

// Write violations detail JSON for governance
$violationsJson = ['state_version' => 1, 'run_at' => date(DateTimeInterface::ATOM), 'violations' => []];
foreach ($payload['checks'] ?? [] as $chk) {
    if (($chk['status'] ?? 'pass') !== 'pass' && isset($chk['meta'])) {
        $violationsJson['violations'][] = [
            'check' => $chk['name'],
            'status' => $chk['status'],
            'message' => $chk['message'],
            'meta' => $chk['meta'],
            'owner' => in_array($chk['name'] ?? '', ['act_fin_integrity'], true) ? 'ACT/FIN' : 'SALES',
            'action' => $allowLegacy ? 'LEGACY_WARN_ALLOWED' : 'FIX_REQUIRED',
        ];
    }
}
if ($violationsJson['violations']) {
    ts_write_json(ts_storage_logs_dir() . '/sales_tracking_violations_last.json', $violationsJson);
}

ts_append_run_history('sales_tracking_checks', $overallOk ? 'OK' : 'FAIL', [
    'actor_username' => getenv('USER') ?: 'SYSTEM',
    'source' => 'tools/qa/run_sales_tracking_checks.php',
    'score' => $payload['summary']['score'],
    'strict' => $strict ? 1 : 0,
]);

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
