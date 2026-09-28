<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker
/**
 * absensi/_inc/geo.php
 * Enterprise+++ GeoFence
 *
 * Update (MASTER_OFFICE):
 * - Sumber titik kantor 100% dari tabel ERP: master_office
 * - absensi_offices hanya menjadi fallback kalau master_office tidak tersedia (legacy)
 *
 * Kolom yang didukung (auto-detect):
 * - office_lat / office_lng / office_radius_m (recommended)
 * - geo_lat / geo_lng / geo_radius_m
 * - lat / lng / radius_m
 */

function absensi_setting(PDO $pdo, string $k, ?string $default=null): ?string {
  $stmt = $pdo->prepare("SELECT v FROM absensi_settings WHERE k=? LIMIT 1");
  $stmt->execute([$k]);
  $v = $stmt->fetchColumn();
  return ($v === false) ? $default : (string)$v;
}

function absensi_office_code_normalize(string $code): string {
  $c = strtoupper(trim($code));
  // mapping legacy -> master_office code (jaga kompatibilitas)
  $map = [
    'BOGOR'     => 'BGR',
    'BEKASI'    => 'BKS',
    'TANGERANG' => 'TGR',
    'BANDUNG'   => 'BDG',
    'SOLO'      => 'SLO',
    'SURAKARTA' => 'SLO',
    'SEMARANG'  => 'SMG',
  ];
  return $map[$c] ?? $c;
}

function absensi_master_office_exists(PDO $pdo): bool {
  try {
    if (function_exists('absensi_table_exists')) {
      return absensi_table_exists($pdo, 'master_office');
    }
  } catch (Throwable $e) {}
  try {
    $pdo->query("SELECT 1 FROM master_office LIMIT 1");
    return true;
  } catch (Throwable $e) {}
  return false;
}

function absensi_master_office_colmap(PDO $pdo): array {
  static $cache = null;
  if (is_array($cache)) return $cache;

  $cols = [];
  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM master_office");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $cols[strtolower((string)$r['Field'])] = (string)$r['Field'];
    }
  } catch (Throwable $e) {
    $cache = [];
    return $cache;
  }

  $pick = function(array $candidates) use ($cols): ?string {
    foreach ($candidates as $c) {
      $k = strtolower($c);
      if (isset($cols[$k])) return $cols[$k];
    }
    return null;
  };

  $map = [
    'code'   => $pick(['office_code','code']),
    'name'   => $pick(['office_name','name']),
    'lat'    => $pick(['office_lat','geo_lat','lat']),
    'lng'    => $pick(['office_lng','geo_lng','lng','lon','long']),
    'radius' => $pick(['office_radius_m','geo_radius_m','radius_m','radius']),
    'active' => $pick(['is_active','active','status']),
  ];

  // sanitize: only allow safe column names in SQL
  foreach ($map as $k => $v) {
    if ($v !== null && !preg_match('/^[A-Za-z0-9_]+$/', $v)) {
      $map[$k] = null;
    }
  }

  $cache = $map;
  return $cache;
}

/**
 * Fetch office from master_office (preferred) or absensi_offices (fallback)
 * Returns keys: office_code, office_name, lat, lng, radius_m, is_active
 */
function absensi_office(PDO $pdo, string $officeCode): ?array {
  $codeRaw = strtoupper(trim($officeCode));
  if ($codeRaw === '') return null;

  $codesToTry = [];
  $codesToTry[] = $codeRaw;
  $norm = absensi_office_code_normalize($codeRaw);
  if ($norm !== $codeRaw) $codesToTry[] = $norm;

  // 1) MASTER: master_office
  if (absensi_master_office_exists($pdo)) {
    $m = absensi_master_office_colmap($pdo);
    if (!empty($m['code']) && !empty($m['name'])) {
      $latCol = $m['lat'] ?? null;
      $lngCol = $m['lng'] ?? null;
      $radCol = $m['radius'] ?? null;
      $actCol = $m['active'] ?? null;

      // if lat/lng missing in schema, still allow read name/code, but geofence will mark missing
      $sel = "`{$m['code']}` AS office_code, `{$m['name']}` AS office_name";
      if ($latCol) $sel .= ", `{$latCol}` AS lat"; else $sel .= ", NULL AS lat";
      if ($lngCol) $sel .= ", `{$lngCol}` AS lng"; else $sel .= ", NULL AS lng";
      if ($radCol) $sel .= ", `{$radCol}` AS radius_m"; else $sel .= ", NULL AS radius_m";
      if ($actCol) $sel .= ", `{$actCol}` AS is_active"; else $sel .= ", 1 AS is_active";

      $sql = "SELECT {$sel} FROM master_office WHERE `{$m['code']}`=? LIMIT 1";
      foreach ($codesToTry as $c) {
        try {
          $stmt = $pdo->prepare($sql);
          $stmt->execute([$c]);
          $r = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($r) {
            // normalize
            $r['office_code'] = strtoupper((string)$r['office_code']);
            $r['office_name'] = (string)$r['office_name'];
            $r['radius_m'] = ($r['radius_m'] === null || $r['radius_m']==='') ? null : (int)$r['radius_m'];
            $r['is_active'] = (int)($r['is_active'] ?? 1);
            return $r;
          }
        } catch (Throwable $e) {}
      }
    }
  }

  // 2) FALLBACK: absensi_offices (legacy)
  try {
    $stmt = $pdo->prepare("SELECT office_code, office_name, lat, lng, radius_m, is_active FROM absensi_offices WHERE office_code=? LIMIT 1");
    foreach ($codesToTry as $c) {
      $stmt->execute([$c]);
      $r = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($r) return $r;
    }
  } catch (Throwable $e) {}

  return null;
}

/**
 * List offices from master_office (for admin screens)
 */
function absensi_master_office_list(PDO $pdo, bool $onlyActive=true): array {
  if (!absensi_master_office_exists($pdo)) return [];
  $m = absensi_master_office_colmap($pdo);
  if (empty($m['code']) || empty($m['name'])) return [];

  $latCol = $m['lat'] ?? null;
  $lngCol = $m['lng'] ?? null;
  $radCol = $m['radius'] ?? null;
  $actCol = $m['active'] ?? null;

  $sel = "`{$m['code']}` AS office_code, `{$m['name']}` AS office_name";
  if ($latCol) $sel .= ", `{$latCol}` AS lat"; else $sel .= ", NULL AS lat";
  if ($lngCol) $sel .= ", `{$lngCol}` AS lng"; else $sel .= ", NULL AS lng";
  if ($radCol) $sel .= ", `{$radCol}` AS radius_m"; else $sel .= ", NULL AS radius_m";
  if ($actCol) $sel .= ", `{$actCol}` AS is_active"; else $sel .= ", 1 AS is_active";

  $where = "";
  if ($onlyActive && $actCol) {
    // support status string or boolean numeric
    $where = " WHERE (`{$actCol}`=1 OR UPPER(CAST(`{$actCol}` AS CHAR))='ACTIVE')";
  }

  try {
    $stmt = $pdo->query("SELECT {$sel} FROM master_office{$where} ORDER BY office_code");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
      $r['office_code'] = strtoupper((string)$r['office_code']);
      $r['office_name'] = (string)$r['office_name'];
      $r['radius_m'] = ($r['radius_m'] === null || $r['radius_m']==='') ? null : (int)$r['radius_m'];
      $r['is_active'] = (int)($r['is_active'] ?? 1);
    }
    return $rows ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * Update geofence columns on master_office
 */
function absensi_master_office_update_geofence(PDO $pdo, string $officeCode, ?float $lat, ?float $lng, ?int $radius): bool {
  if (!absensi_master_office_exists($pdo)) return false;
  $m = absensi_master_office_colmap($pdo);
  if (empty($m['code']) || empty($m['lat']) || empty($m['lng']) || empty($m['radius'])) return false;

  $sql = "UPDATE master_office
          SET `{$m['lat']}` = ?, `{$m['lng']}` = ?, `{$m['radius']}` = ?
          WHERE `{$m['code']}` = ?
          LIMIT 1";
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$lat, $lng, $radius, $officeCode]);
    return $stmt->rowCount() >= 0;
  } catch (Throwable $e) {
    return false;
  }
}

function absensi_haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
  $R = 6371000.0;
  $dLat = deg2rad($lat2 - $lat1);
  $dLon = deg2rad($lon2 - $lon1);
  $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
  $c = 2 * atan2(sqrt($a), sqrt(1-$a));
  return $R * $c;
}

function absensi_geofence_check(PDO $pdo, string $office_code, ?float $lat, ?float $lng, ?float $acc=null): array {
  $enforce = (int)absensi_setting($pdo,'geofence_enforce', (string)ABSENSI_GEOFENCE_ENFORCE_DEFAULT);
  $radiusDefault = ABSENSI_DEFAULT_RADIUS_M;
  $maxAccuracy = defined('ABSENSI_MAX_GPS_ACCURACY_M') ? max(1, (int)ABSENSI_MAX_GPS_ACCURACY_M) : 40;

  // GPS wajib bila geofence enforcement aktif.
  if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    return ['enforce'=>$enforce,'ok'=>($enforce?false:true),'distance_m'=>null,'radius_m'=>$radiusDefault,'reason'=>'NO_GPS','accuracy_m'=>$acc,'max_accuracy_m'=>$maxAccuracy];
  }

  // Accuracy harus tersedia dan cukup baik. Jangan memperbesar radius berdasarkan accuracy.
  if ($acc === null || !is_finite($acc) || $acc <= 0) {
    return ['enforce'=>$enforce,'ok'=>($enforce?false:true),'distance_m'=>null,'radius_m'=>$radiusDefault,'reason'=>'NO_ACCURACY','accuracy_m'=>$acc,'max_accuracy_m'=>$maxAccuracy];
  }
  if ($acc > $maxAccuracy) {
    return ['enforce'=>$enforce,'ok'=>($enforce?false:true),'distance_m'=>null,'radius_m'=>$radiusDefault,'reason'=>'LOW_ACCURACY','accuracy_m'=>$acc,'max_accuracy_m'=>$maxAccuracy];
  }

  $office = absensi_office($pdo, $office_code);
  if (!$office || $office['lat'] === null || $office['lat'] === '' || $office['lng'] === null || $office['lng'] === '') {
    // Fail closed saat enforcement aktif: konfigurasi office harus benar agar lokasi dapat diverifikasi.
    return ['enforce'=>$enforce,'ok'=>($enforce?false:true),'distance_m'=>null,'radius_m'=>$radiusDefault,'reason'=>'GEO_MISSING','accuracy_m'=>$acc,'max_accuracy_m'=>$maxAccuracy];
  }

  $officeLat = (float)$office['lat'];
  $officeLng = (float)$office['lng'];
  if (!is_finite($officeLat) || !is_finite($officeLng) || $officeLat < -90 || $officeLat > 90 || $officeLng < -180 || $officeLng > 180) {
    return ['enforce'=>$enforce,'ok'=>($enforce?false:true),'distance_m'=>null,'radius_m'=>$radiusDefault,'reason'=>'GEO_INVALID','accuracy_m'=>$acc,'max_accuracy_m'=>$maxAccuracy];
  }

  $radius = (int)($office['radius_m'] ?? $radiusDefault);
  if ($radius <= 0) $radius = $radiusDefault;

  $dist = absensi_haversine_m($lat,$lng,$officeLat,$officeLng);
  $ok = ($dist <= $radius);
  return [
    'enforce'=>$enforce,
    'ok'=>$ok,
    'distance_m'=>$dist,
    'radius_m'=>$radius,
    'effective_radius_m'=>$radius, // kompatibilitas untuk consumer lama
    'accuracy_tolerance_m'=>0,    // radius tidak lagi diperlebar oleh accuracy
    'reason'=>$ok?'OK':'OUTSIDE',
    'accuracy_m'=>$acc,
    'max_accuracy_m'=>$maxAccuracy,
  ];
}

function absensi_geofence_or_die(array $geo): void {
  if ((int)($geo['enforce'] ?? 0) !== 1 || !empty($geo['ok'])) return;

  http_response_code(403);
  $reason = (string)($geo['reason'] ?? 'DENIED');
  $radius = (int)($geo['radius_m'] ?? ABSENSI_DEFAULT_RADIUS_M);
  $maxAcc = (int)($geo['max_accuracy_m'] ?? (defined('ABSENSI_MAX_GPS_ACCURACY_M') ? ABSENSI_MAX_GPS_ACCURACY_M : 40));
  $acc = isset($geo['accuracy_m']) && $geo['accuracy_m'] !== null ? (int)round((float)$geo['accuracy_m']) : null;

  switch ($reason) {
    case 'NO_GPS':
      die('Lokasi GPS wajib untuk absensi. Aktifkan GPS/lokasi lalu coba kembali.');
    case 'NO_ACCURACY':
      die('Akurasi GPS belum tersedia. Tunggu lokasi stabil lalu coba kembali.');
    case 'LOW_ACCURACY':
      $accText = $acc === null ? '-' : (string)$acc;
      die("Akurasi GPS belum cukup baik (±{$accText}m). Maksimum ±{$maxAcc}m. Tunggu lokasi lebih akurat lalu coba kembali.");
    case 'GEO_MISSING':
    case 'GEO_INVALID':
      die('GeoFence office belum dikonfigurasi dengan benar. Hubungi Admin HR/ITC sebelum melakukan absensi.');
    case 'OUTSIDE':
    default:
      $dist = $geo['distance_m'] === null ? '-' : (string)round((float)$geo['distance_m']);
      die("Di luar area kantor. Jarak: {$dist}m, batas: {$radius}m. Pastikan berada di area kantor dan GPS aktif.");
  }
}
