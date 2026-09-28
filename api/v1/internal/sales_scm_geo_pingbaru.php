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

if (!function_exists('scm_geo_column_exists')) {
    function scm_geo_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $st->execute([$column]);
            return (bool)$st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('scm_geo_ensure_column')) {
    function scm_geo_ensure_column(PDO $pdo, string $table, string $column, string $ddl): void
    {
        if (scm_geo_column_exists($pdo, $table, $column)) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        } catch (Throwable $e) {
            // Fail-soft. Kolom opsional tidak boleh memutus flow SCM.
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
        $dt = new DateTimeImmutable($capturedAtRaw);
        $capturedAt = $dt->format('Y-m-d H:i:s');
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
    if ($status !== 'on_delivery') {
        ApiResponse::fail('GPS live hanya aktif saat DO berstatus ON DELIVERY.', 'ERR_FLOW_LOCK', 409);
    }

    $mapsUrl = 'https://maps.google.com/?q=' . rawurlencode(number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', ''));

    // Kolom inti live GPS harus tersedia. Coba buat bila belum ada; jika DB user tidak punya ALTER,
    // berikan error schema yang jelas dan jangan menyentuh status DO.
    foreach ([
        'scm_live_lat' => 'scm_live_lat DECIMAL(10,7) NULL',
        'scm_live_lng' => 'scm_live_lng DECIMAL(10,7) NULL',
        'scm_live_accuracy_m' => 'scm_live_accuracy_m DECIMAL(8,2) NULL',
        'scm_live_at' => 'scm_live_at DATETIME NULL',
    ] as $col => $ddl) {
        scm_geo_ensure_column($pdo, 'sales_do', $col, $ddl);
    }
    if (!scm_geo_column_exists($pdo, 'sales_do', 'scm_live_lat')
        || !scm_geo_column_exists($pdo, 'sales_do', 'scm_live_lng')
        || !scm_geo_column_exists($pdo, 'sales_do', 'scm_live_at')) {
        ApiResponse::fail('Kolom live GPS belum tersedia di sales_do. Jalankan migrasi schema terlebih dahulu.', 'ERR_SCHEMA', 500);
    }

    // Bangun UPDATE hanya dari kolom yang benar-benar ada agar kolom audit opsional
    // seperti last_updated_by tidak pernah menyebabkan Unknown column.
    $sets = ['scm_live_lat=?', 'scm_live_lng=?'];
    $params = [$lat, $lng];
    if (scm_geo_column_exists($pdo, 'sales_do', 'scm_live_accuracy_m')) {
        $sets[] = 'scm_live_accuracy_m=?';
        $params[] = $acc;
    }
    $sets[] = 'scm_live_at=NOW()';
    if (scm_geo_column_exists($pdo, 'sales_do', 'fallback_live_location_url')) {
        $sets[] = 'fallback_live_location_url=?';
        $params[] = $mapsUrl;
    }
    if (scm_geo_column_exists($pdo, 'sales_do', 'last_updated_by')) {
        $sets[] = 'last_updated_by=?';
        $params[] = 'SCM_GPS';
    }
    if (scm_geo_column_exists($pdo, 'sales_do', 'last_updated_at')) {
        $sets[] = 'last_updated_at=NOW()';
    }
    $params[] = $doId;
    $upd = $pdo->prepare('UPDATE sales_do SET ' . implode(', ', $sets) . ' WHERE id=?');
    $upd->execute($params);

    // Simpan histori titik GPS pada tabel khusus. Fail-soft agar tracking utama tetap jalan
    // bila user DB belum punya hak CREATE TABLE.
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_shipment_location_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                do_id INT NOT NULL,
                latitude DECIMAL(10,7) NOT NULL,
                longitude DECIMAL(10,7) NOT NULL,
                accuracy_m DECIMAL(8,2) NULL,
                speed_kmh DECIMAL(8,2) NULL,
                heading DECIMAL(7,2) NULL,
                source VARCHAR(30) NOT NULL DEFAULT 'SCM_MOBILE',
                user_id INT NULL,
                username VARCHAR(100) NULL,
                recorded_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ssl_do_recorded (do_id, recorded_at),
                KEY idx_ssl_recorded (recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $username = trim((string)($_SESSION['username'] ?? $_SESSION['user'] ?? ''));
        $recordedAt = $capturedAt ?: date('Y-m-d H:i:s');
        $h = $pdo->prepare("
            INSERT INTO sales_shipment_location_logs
                (do_id, latitude, longitude, accuracy_m, speed_kmh, heading, source, user_id, username, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, 'SCM_MOBILE', ?, ?, ?)
        ");
        $h->execute([$doId, $lat, $lng, $acc, $speedKmh, $heading, $uid ?: null, $username !== '' ? $username : null, $recordedAt]);
    } catch (Throwable $e) {
        // History khusus tidak boleh memutus live tracking. Timeline generik tetap dicoba di bawah.
    }

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
        $raw = json_encode(['lat' => $lat, 'lng' => $lng, 'accuracy_m' => $acc, 'speed_kmh' => $speedKmh, 'heading' => $heading, 'captured_at' => $capturedAt, 'by' => $uid], JSON_UNESCAPED_SLASHES);
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
        'speed_kmh' => $speedKmh,
        'heading' => $heading,
        'maps_url' => $mapsUrl,
        'captured_at' => $capturedAt ?: date('c'),
    ]);
} catch (Throwable $e) {
    ApiResponse::fail('Gagal simpan GPS ping.', 'ERR_SCM_GEO_PING', 500);
}
