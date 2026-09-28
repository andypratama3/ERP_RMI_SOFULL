<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../master/auth.php';
require_once __DIR__ . '/../../../_shared/app_init.php';
require_once __DIR__ . '/../../../_shared/db.php';
require_once __DIR__ . '/../../../app/Api/ApiResponse.php';
require_once __DIR__ . '/../../../app/Security/RateLimiterService.php';
require_once __DIR__ . '/_internal_api_bootstrap.php';

use App\Api\ApiResponse;
use App\Security\RateLimiterService;

require_login();
require_role(['SCM', 'ADMIN', 'SUPERADMIN', 'SYS', 'MANAGER']);
internal_api_require_write_guard();

$action = strtolower(trim((string)($_POST['tracking_action'] ?? 'ping')));
if (!in_array($action, ['start', 'ping', 'stop'], true)) $action = 'ping';

$doId = (int)($_POST['do_id'] ?? 0);
$deviceKey = substr(trim((string)($_POST['device_key'] ?? '')), 0, 120);
$lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
$acc = isset($_POST['accuracy_m']) && $_POST['accuracy_m'] !== '' ? (float)$_POST['accuracy_m'] : null;
$speedKmh = isset($_POST['speed_kmh']) && $_POST['speed_kmh'] !== '' ? max(0.0, (float)$_POST['speed_kmh']) : null;
$heading = isset($_POST['heading']) && $_POST['heading'] !== '' ? (float)$_POST['heading'] : null;
$capturedAtRaw = trim((string)($_POST['captured_at'] ?? ''));

if ($doId <= 0) ApiResponse::fail('do_id wajib diisi.', 'ERR_VALIDATION', 422);
if ($action === 'ping') {
    if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        ApiResponse::fail('Koordinat tidak valid.', 'ERR_VALIDATION', 422);
    }
}
if ($heading !== null && ($heading < 0 || $heading > 360)) $heading = null;

$capturedAt = null;
if ($capturedAtRaw !== '') {
    try { $capturedAt = (new DateTimeImmutable($capturedAtRaw))->format('Y-m-d H:i:s'); }
    catch (Throwable $e) { $capturedAt = null; }
}

function scm_tracking_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function scm_tracking_actor(): string {
    if (function_exists('auth_user')) {
        $u = auth_user();
        foreach (['username','user_name','name','full_name'] as $k) {
            $v = trim((string)($u[$k] ?? ''));
            if ($v !== '') return $v;
        }
    }
    foreach (['username','user_name','full_name'] as $k) {
        $v = trim((string)($_SESSION[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return 'SCM';
}

try {
    $pdo = rmi_db_pdo();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $username = scm_tracking_actor();
    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ip = trim(explode(',', $ip)[0] ?? '0.0.0.0');

    if ($action === 'ping') {
        try {
            $limiter = new RateLimiterService($pdo);
            $rate = $limiter->hit('SALES_SCM_GEO_PING', 'U'.$uid.'|IP'.$ip, 60, 60);
            if (!$rate['allowed']) ApiResponse::fail('Rate limit exceeded.', 'ERR_RATE_LIMIT', 429);
        } catch (Throwable $e) {
            // Fail-soft: rate limiter tidak boleh memutus live GPS bila service limiter bermasalah.
        }
    }

    $st = $pdo->prepare("SELECT id, do_code, status FROM sales_do WHERE id=? LIMIT 1");
    $st->execute([$doId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) ApiResponse::fail('DO tidak ditemukan.', 'ERR_NOT_FOUND', 404);

    $status = strtolower(trim((string)($row['status'] ?? '')));
    $hasSessions = scm_tracking_table_exists($pdo, 'sales_shipment_tracking_sessions');
    $hasLogs = scm_tracking_table_exists($pdo, 'sales_shipment_location_logs');

    if ($action === 'stop') {
        if ($hasSessions) {
            $sql = "UPDATE sales_shipment_tracking_sessions
                    SET status='STOPPED', stopped_at=COALESCE(stopped_at,NOW()), stop_reason=COALESCE(stop_reason,'MANUAL'), updated_at=NOW()
                    WHERE do_id=? AND status='ACTIVE'";
            $params = [$doId];
            if ($deviceKey !== '') { $sql .= " AND (device_key=? OR device_key IS NULL OR device_key='')"; $params[] = $deviceKey; }
            $pdo->prepare($sql)->execute($params);
        }
        ApiResponse::ok([
            'action' => 'stop', 'do_id' => $doId, 'do_code' => (string)$row['do_code'],
            'status' => $status, 'history_enabled' => ($hasSessions && $hasLogs),
            'server_version' => 'SCM-GPS-HISTORY-20260821'
        ]);
    }

    if ($status !== 'on_delivery') {
        ApiResponse::fail('GPS live hanya aktif saat DO berstatus ON DELIVERY.', 'ERR_FLOW_LOCK', 409);
    }

    $sessionId = null;
    if ($hasSessions) {
        // Hanya satu session ACTIVE per DO. Session lama dipakai ulang agar pindah halaman/perangkat tidak memecah histori.
        $q = $pdo->prepare("SELECT id FROM sales_shipment_tracking_sessions WHERE do_id=? AND status='ACTIVE' ORDER BY id DESC LIMIT 1");
        $q->execute([$doId]);
        $sessionId = (int)($q->fetchColumn() ?: 0);
        if ($sessionId <= 0) {
            $ins = $pdo->prepare("INSERT INTO sales_shipment_tracking_sessions
                (do_id, do_code, user_id, username, device_key, started_at, last_ping_at, status, created_at, updated_at)
                VALUES (?,?,?,?,?,NOW(),NULL,'ACTIVE',NOW(),NOW())");
            $ins->execute([$doId, (string)$row['do_code'], $uid ?: null, $username, $deviceKey !== '' ? $deviceKey : null]);
            $sessionId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE sales_shipment_tracking_sessions
                           SET user_id=?, username=?, device_key=COALESCE(NULLIF(?,''),device_key), updated_at=NOW()
                           WHERE id=?")
                ->execute([$uid ?: null, $username, $deviceKey, $sessionId]);
        }
    }

    if ($action === 'start') {
        ApiResponse::ok([
            'action' => 'start', 'do_id' => $doId, 'do_code' => (string)$row['do_code'],
            'status' => $status, 'session_id' => $sessionId ?: null,
            'history_enabled' => ($hasSessions && $hasLogs),
            'server_version' => 'SCM-GPS-HISTORY-20260821'
        ]);
    }

    // PING: posisi terakhir tetap disimpan di sales_do agar Control Tower existing tidak berubah.
    $upd = $pdo->prepare("UPDATE sales_do
        SET scm_live_lat=?, scm_live_lng=?, scm_live_accuracy_m=?, scm_live_at=NOW()
        WHERE id=? AND LOWER(TRIM(status))='on_delivery' LIMIT 1");
    $upd->execute([$lat, $lng, $acc, $doId]);

    if ($hasSessions && $sessionId) {
        $pdo->prepare("UPDATE sales_shipment_tracking_sessions SET last_ping_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$sessionId]);
    }

    if ($hasLogs && $sessionId) {
        $log = $pdo->prepare("INSERT INTO sales_shipment_location_logs
            (session_id, do_id, latitude, longitude, accuracy_m, speed_kmh, heading, captured_at, received_at, user_id, username, device_key)
            VALUES (?,?,?,?,?,?,?,?,NOW(),?,?,?)");
        $log->execute([
            $sessionId, $doId, $lat, $lng, $acc, $speedKmh, $heading,
            $capturedAt ?: date('Y-m-d H:i:s'), $uid ?: null, $username, $deviceKey !== '' ? $deviceKey : null
        ]);
    }

    // Timeline existing dipertahankan fail-soft.
    try {
        $ins = $pdo->prepare("INSERT INTO sales_do_tracking_events
            (do_id,event_time,provider_status,internal_status,description,location,raw_json)
            VALUES (?,NOW(),'GPS_PING',?,?,?,?)");
        $location = number_format((float)$lat, 7, '.', '') . ',' . number_format((float)$lng, 7, '.', '');
        $raw = json_encode([
            'lat'=>$lat,'lng'=>$lng,'accuracy_m'=>$acc,'speed_kmh'=>$speedKmh,'heading'=>$heading,
            'captured_at'=>$capturedAt,'by'=>$uid,'session_id'=>$sessionId,'device_key'=>$deviceKey
        ], JSON_UNESCAPED_SLASHES);
        $ins->execute([$doId, $status, 'SCM live GPS ping', $location, $raw]);
    } catch (Throwable $e) {}

    ApiResponse::ok([
        'action'=>'ping','do_id'=>$doId,'do_code'=>(string)$row['do_code'],'status'=>$status,
        'lat'=>$lat,'lng'=>$lng,'accuracy_m'=>$acc,'speed_kmh'=>$speedKmh,'heading'=>$heading,
        'captured_at'=>$capturedAt ?: date('c'),'session_id'=>$sessionId ?: null,
        'history_enabled'=>($hasSessions && $hasLogs),'server_version'=>'SCM-GPS-HISTORY-20260821'
    ]);
} catch (PDOException $e) {
    error_log('[SCM_GEO_PING][PDO] '.$e->getMessage());
    ApiResponse::fail('Gagal menyimpan GPS ke database. Cek log server.', 'ERR_GPS_DB', 500);
} catch (Throwable $e) {
    error_log('[SCM_GEO_PING] '.$e->getMessage());
    ApiResponse::fail('Gagal simpan GPS ping.', 'ERR_SCM_GEO_PING', 500);
}
