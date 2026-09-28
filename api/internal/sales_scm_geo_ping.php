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

if (!function_exists('scm_geo_ensure_column')) {
    function scm_geo_ensure_column(PDO $pdo, string $table, string $column, string $ddl): void
    {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $st->execute([$column]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
            }
        } catch (Throwable $e) {
        }
    }
}

require_login();
require_role(['SCM', 'ADMIN', 'SUPERADMIN', 'SYS', 'MANAGER']);
internal_api_require_write_guard();

$doId = (int)($_POST['do_id'] ?? 0);
$lat = isset($_POST['lat']) ? (float)$_POST['lat'] : 0.0;
$lng = isset($_POST['lng']) ? (float)$_POST['lng'] : 0.0;
$acc = isset($_POST['accuracy_m']) ? (float)$_POST['accuracy_m'] : null;

if ($doId <= 0) {
    ApiResponse::fail('do_id wajib diisi.', 'ERR_VALIDATION', 422);
}
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    ApiResponse::fail('Koordinat tidak valid.', 'ERR_VALIDATION', 422);
}

try {
    $pdo = rmi_db_pdo();

    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ip = trim(explode(',', $ip)[0] ?? '0.0.0.0');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $actorKey = 'U' . $uid . '|IP' . $ip;

    $limiter = new RateLimiterService($pdo);
    $rate = $limiter->hit('SALES_SCM_GEO_PING', $actorKey, 60, 60);
    if (!$rate['allowed']) {
        ApiResponse::fail('Rate limit exceeded.', 'ERR_RATE_LIMIT', 429);
    }

    scm_geo_ensure_column($pdo, 'sales_do', 'scm_live_lat', 'scm_live_lat DECIMAL(10,7) NULL');
    scm_geo_ensure_column($pdo, 'sales_do', 'scm_live_lng', 'scm_live_lng DECIMAL(10,7) NULL');
    scm_geo_ensure_column($pdo, 'sales_do', 'scm_live_accuracy_m', 'scm_live_accuracy_m DECIMAL(8,2) NULL');
    scm_geo_ensure_column($pdo, 'sales_do', 'scm_live_at', 'scm_live_at DATETIME NULL');
} catch (Throwable $e) {}

try {
    $st = $pdo->prepare("SELECT id, do_code, status FROM sales_do WHERE id=? LIMIT 1");
    $st->execute([$doId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        ApiResponse::fail('DO tidak ditemukan.', 'ERR_NOT_FOUND', 404);
    }

    $status = strtolower((string)($row['status'] ?? ''));
    if (!in_array($status, ['ready_scm', 'on_delivery', 'delivered'], true)) {
        ApiResponse::fail('GPS ping hanya untuk DO di flow SCM.', 'ERR_FLOW_LOCK', 409);
    }

    $mapsUrl = 'https://maps.google.com/?q=' . rawurlencode(number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', ''));
    $upd = $pdo->prepare("
        UPDATE sales_do
        SET scm_live_lat=?,
            scm_live_lng=?,
            scm_live_accuracy_m=?,
            scm_live_at=NOW(),
            fallback_live_location_url=?,
            last_updated_by='SCM_GPS',
            last_updated_at=NOW()
        WHERE id=?
    ");
    $upd->execute([$lat, $lng, $acc, $mapsUrl, $doId]);

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_do_tracking_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                do_id INT NOT NULL,
                event_time DATETIME NOT NULL,
                provider_status VARCHAR(100) NULL,
                internal_status VARCHAR(50) NULL,
                description VARCHAR(255) NULL,
                location VARCHAR(255) NULL,
                raw_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sdt_do_time (do_id, event_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $ins = $pdo->prepare("
            INSERT INTO sales_do_tracking_events
                (do_id, event_time, provider_status, internal_status, description, location, raw_json)
            VALUES
                (?, NOW(), 'GPS_PING', ?, ?, ?, ?)
        ");
        $desc = 'SCM live GPS ping';
        $location = number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', '');
        $raw = json_encode(['lat' => $lat, 'lng' => $lng, 'accuracy_m' => $acc, 'by' => $uid], JSON_UNESCAPED_SLASHES);
        $ins->execute([$doId, $status, $desc, $location, $raw]);
    } catch (Throwable $e) {
        // fail-soft for timeline insert
    }

    ApiResponse::ok([
        'do_id' => $doId,
        'do_code' => (string)$row['do_code'],
        'status' => $status,
        'lat' => $lat,
        'lng' => $lng,
        'accuracy_m' => $acc,
        'maps_url' => $mapsUrl,
        'captured_at' => date('c'),
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Gagal simpan GPS ping.', 'ERR_SCM_GEO_PING', 500);
}
