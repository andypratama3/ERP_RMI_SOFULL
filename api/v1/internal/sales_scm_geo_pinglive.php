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

$doId = (int)($_POST['do_id'] ?? 0);
$lat = isset($_POST['lat']) ? (float)$_POST['lat'] : 0.0;
$lng = isset($_POST['lng']) ? (float)$_POST['lng'] : 0.0;
$acc = isset($_POST['accuracy_m']) && $_POST['accuracy_m'] !== '' ? (float)$_POST['accuracy_m'] : null;
$speedKmh = isset($_POST['speed_kmh']) && $_POST['speed_kmh'] !== '' ? max(0.0, (float)$_POST['speed_kmh']) : null;
$heading = isset($_POST['heading']) && $_POST['heading'] !== '' ? (float)$_POST['heading'] : null;
$capturedAtRaw = trim((string)($_POST['captured_at'] ?? ''));

if ($doId <= 0) {
    ApiResponse::fail('do_id wajib diisi.', 'ERR_VALIDATION', 422);
}
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    ApiResponse::fail('Koordinat tidak valid.', 'ERR_VALIDATION', 422);
}
if ($heading !== null && ($heading < 0 || $heading > 360)) {
    $heading = null;
}

$capturedAt = null;
if ($capturedAtRaw !== '') {
    try {
        $capturedAt = (new DateTimeImmutable($capturedAtRaw))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $capturedAt = null;
    }
}

try {
    $pdo = rmi_db_pdo();

    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ip = trim(explode(',', $ip)[0] ?? '0.0.0.0');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $actorKey = 'U' . $uid . '|IP' . $ip;

    try {
        $limiter = new RateLimiterService($pdo);
        $rate = $limiter->hit('SALES_SCM_GEO_PING', $actorKey, 60, 60);
        if (!$rate['allowed']) {
            ApiResponse::fail('Rate limit exceeded.', 'ERR_RATE_LIMIT', 429);
        }
    } catch (Throwable $e) {
        // Rate limiter tidak boleh memutus flow GPS bila servicenya bermasalah.
    }

    $st = $pdo->prepare("SELECT id, do_code, status FROM sales_do WHERE id=? LIMIT 1");
    $st->execute([$doId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        ApiResponse::fail('DO tidak ditemukan.', 'ERR_NOT_FOUND', 404);
    }

    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status !== 'on_delivery') {
        ApiResponse::fail('GPS live hanya aktif saat DO berstatus ON DELIVERY.', 'ERR_FLOW_LOCK', 409);
    }

    // Empat kolom ini sudah dikonfirmasi ada di sales_do.
    // Tidak ada SHOW COLUMNS / INFORMATION_SCHEMA / ALTER TABLE saat runtime.
    $upd = $pdo->prepare("
        UPDATE sales_do
        SET scm_live_lat = ?,
            scm_live_lng = ?,
            scm_live_accuracy_m = ?,
            scm_live_at = NOW()
        WHERE id = ?
          AND LOWER(TRIM(status)) = 'on_delivery'
        LIMIT 1
    ");
    $upd->execute([$lat, $lng, $acc, $doId]);

    // Audit/tracking event lama dipertahankan secara fail-soft, tanpa CREATE TABLE runtime.
    try {
        $ins = $pdo->prepare("
            INSERT INTO sales_do_tracking_events
                (do_id, event_time, provider_status, internal_status, description, location, raw_json)
            VALUES
                (?, NOW(), 'GPS_PING', ?, ?, ?, ?)
        ");
        $location = number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', '');
        $raw = json_encode([
            'lat' => $lat,
            'lng' => $lng,
            'accuracy_m' => $acc,
            'speed_kmh' => $speedKmh,
            'heading' => $heading,
            'captured_at' => $capturedAt,
            'by' => $uid,
        ], JSON_UNESCAPED_SLASHES);
        $ins->execute([$doId, $status, 'SCM live GPS ping', $location, $raw]);
    } catch (Throwable $e) {
        // Timeline tidak boleh menggagalkan penyimpanan posisi utama.
    }

    ApiResponse::ok([
        'do_id' => $doId,
        'do_code' => (string)$row['do_code'],
        'status' => $status,
        'lat' => $lat,
        'lng' => $lng,
        'accuracy_m' => $acc,
        'speed_kmh' => $speedKmh,
        'heading' => $heading,
        'captured_at' => $capturedAt ?: date('c'),
        'server_version' => 'SCM-GPS-STABLE-20260819',
    ]);
} catch (PDOException $e) {
    error_log('[SCM_GEO_PING][PDO] ' . $e->getMessage());
    ApiResponse::fail('Gagal menyimpan GPS ke database. Cek log server.', 'ERR_GPS_DB', 500);
} catch (Throwable $e) {
    error_log('[SCM_GEO_PING] ' . $e->getMessage());
    ApiResponse::fail('Gagal simpan GPS ping.', 'ERR_SCM_GEO_PING', 500);
}
