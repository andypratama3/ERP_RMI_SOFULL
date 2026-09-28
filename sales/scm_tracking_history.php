<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();

$allowed = false;
if (function_exists('auth_is_admin') && auth_is_admin()) $allowed = true;
if (!$allowed && function_exists('user_has_any_permission')) {
    $allowed = user_has_any_permission([
        'SCM.SHIPMENT.TRACK.VIEW',
        'SCM.SHIPMENT.TRACK.UPDATE',
        'SALES.VIEW',
        'SALES.EDIT',
        'MASTER.ADMIN_CENTER'
    ]);
}
if (!$allowed && function_exists('require_any_permission')) {
    require_any_permission([
        'SCM.SHIPMENT.TRACK.VIEW',
        'SCM.SHIPMENT.TRACK.UPDATE',
        'SALES.VIEW',
        'SALES.EDIT',
        'MASTER.ADMIN_CENTER'
    ]);
    $allowed = true;
}
if (!$allowed) require_role(['SCM','ADMIN','SUPERADMIN','SYS','MANAGER']);

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();

function hh($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tbl_exists(PDO $pdo, string $t): bool {
    try {
        $s = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
        $s->execute([$t]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $s = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
        $s->execute([$table, $column]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371.0;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
}

/**
 * Resolver PIC canonical ERP RMI.
 * GPS_PING menyimpan session user_id di raw_json.by. Identitas user ERP bersumber dari
 * master_system_login; nama karyawan diprioritaskan dari master_employees.employee_name
 * melalui holder_employee_code, lalu fallback full_name, lalu username.
 * Read-only: tidak mengubah DB.
 */
function resolve_user_names(PDO $pdo, array $uids): array {
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn($v) => $v > 0)));
    if (!$uids || !tbl_exists($pdo, 'master_system_login')) return [];

    $idCol = null;
    foreach (['id','user_id','login_id','system_login_id'] as $c) {
        if (column_exists($pdo, 'master_system_login', $c)) { $idCol = $c; break; }
    }
    if (!$idCol) return [];

    $hasUsername = column_exists($pdo, 'master_system_login', 'username');
    $hasFullName = column_exists($pdo, 'master_system_login', 'full_name');
    $hasHolder   = column_exists($pdo, 'master_system_login', 'holder_employee_code');

    $joinEmployee = $hasHolder
        && tbl_exists($pdo, 'master_employees')
        && column_exists($pdo, 'master_employees', 'employee_code')
        && column_exists($pdo, 'master_employees', 'employee_name');

    try {
        $ph = implode(',', array_fill(0, count($uids), '?'));
        $select = ["l.`{$idCol}` AS uid"];
        $select[] = $hasUsername ? "l.`username` AS username" : "'' AS username";
        $select[] = $hasFullName ? "l.`full_name` AS full_name" : "'' AS full_name";
        if ($joinEmployee) {
            $select[] = "COALESCE(e.`employee_name`,'') AS employee_name";
        } else {
            $select[] = "'' AS employee_name";
        }

        $sql = 'SELECT '.implode(', ', $select).' FROM `master_system_login` l ';
        if ($joinEmployee) {
            $sql .= 'LEFT JOIN `master_employees` e ON e.`employee_code` = l.`holder_employee_code` ';
        }
        $sql .= "WHERE l.`{$idCol}` IN ({$ph})";

        $st = $pdo->prepare($sql);
        $st->execute($uids);
        $out = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $uid = (int)($r['uid'] ?? 0);
            if ($uid <= 0) continue;
            $username = trim((string)($r['username'] ?? ''));
            $employee = trim((string)($r['employee_name'] ?? ''));
            $fullName = trim((string)($r['full_name'] ?? ''));
            $name = $employee !== '' ? $employee : ($fullName !== '' ? $fullName : $username);
            if ($name === '') continue;
            $out[$uid] = ($username !== '' && strcasecmp($username, $name) !== 0)
                ? ($name . ' (' . $username . ')')
                : $name;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function pic_labels_from_csv(string $csv, array $userNames): string {
    $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', trim($csv)) ?: []), static fn($v) => $v > 0)));
    if (!$ids) return '-';
    $labels = [];
    foreach ($ids as $uid) {
        $labels[] = $userNames[$uid] ?? ('User #' . $uid);
    }
    return implode(', ', $labels);
}

$ready = tbl_exists($pdo, 'sales_do_tracking_events');

// Google Maps key dari config yang sama dengan tracking_public.php.
$googleMapsApiKey = '';
$googleMapsConfigFile = __DIR__ . '/../config/google_maps.php';
if (is_file($googleMapsConfigFile)) {
    try {
        $cfg = require $googleMapsConfigFile;
        if (is_array($cfg)) {
            $googleMapsApiKey = trim((string)($cfg['google_maps_api_key'] ?? ''));
        }
    } catch (Throwable $e) {
        $googleMapsApiKey = '';
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
if ($date === '') $date = trim((string)($_GET['date_fr'] ?? ''));
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$office = strtoupper(trim((string)($_GET['office'] ?? '')));
$picUid = (int)($_GET['pic_uid'] ?? 0);
$doId = (int)($_GET['do_id'] ?? 0);

$offices = [];
$picOptions = [];
$userNames = [];
$dailyRows = [];
$routePoints = [];
$visits = [];
$sessions = [];
$selected = null;
$selectedPoints = [];
$routePicUid = 0;
$routePicName = '-';
$routeStatus = 'Tidak ada data';
$totalKm = 0.0;
$durationMin = 0;
$firstAt = null;
$lastAt = null;
$validSegments = 0;
$ignoredSegments = 0;

if ($ready) {
    try {
        $offices = $pdo->query("SELECT DISTINCT office_code FROM sales_do WHERE office_code IS NOT NULL AND office_code<>'' ORDER BY office_code")
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $offices = [];
    }

    // PIC tersedia pada hari/filter yang dipilih. Endpoint aktif menyimpan user id di raw_json.by.
    try {
        $sqlPic = "
            SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.by')) AS UNSIGNED) AS pic_uid,
                   COUNT(*) AS ping_count,
                   MIN(e.event_time) AS first_at,
                   MAX(e.event_time) AS last_at
            FROM sales_do_tracking_events e
            JOIN sales_do d ON d.id=e.do_id
            WHERE e.provider_status='GPS_PING'
              AND DATE(e.event_time)=?
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.by')) AS UNSIGNED) > 0
        ";
        $pPic = [$date];
        if ($office !== '') { $sqlPic .= " AND UPPER(d.office_code)=?"; $pPic[] = $office; }
        if ($q !== '') {
            $sqlPic .= " AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR EXISTS (SELECT 1 FROM master_customers c2 WHERE c2.customers_code=d.customers_code AND c2.customers_name LIKE ?))";
            $lk = '%' . $q . '%';
            array_push($pPic, $lk, $lk, $lk);
        }
        $sqlPic .= " GROUP BY pic_uid ORDER BY first_at ASC";
        $stPic = $pdo->prepare($sqlPic);
        $stPic->execute($pPic);
        $picOptions = $stPic->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $picOptions = [];
    }

    $uids = array_map(static fn($r) => (int)($r['pic_uid'] ?? 0), $picOptions);
    $userNames = resolve_user_names($pdo, $uids);

    // Jika hanya ada satu PIC pada hari tersebut, pilih otomatis agar peta langsung muncul.
    if ($picUid <= 0 && count($picOptions) === 1) {
        $picUid = (int)($picOptions[0]['pic_uid'] ?? 0);
    }

    // Data harian. Jika lebih dari satu PIC dan user belum memilih, data tetap diload untuk summary,
    // tetapi route tidak digabung agar jejak antar driver tidak tersambung palsu.
    try {
        $sqlDaily = "
            SELECT e.id, e.do_id, e.event_time, e.location, e.raw_json,
                   d.do_code, d.customers_code, d.office_code, d.status AS do_status,
                   c.customers_name,
                   CAST(JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.by')) AS UNSIGNED) AS pic_uid,
                   CAST(SUBSTRING_INDEX(e.location,',',1) AS DECIMAL(10,7)) AS latitude,
                   CAST(SUBSTRING_INDEX(e.location,',',-1) AS DECIMAL(10,7)) AS longitude,
                   CAST(JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.accuracy_m')) AS DECIMAL(10,2)) AS accuracy_m
            FROM sales_do_tracking_events e
            JOIN sales_do d ON d.id=e.do_id
            LEFT JOIN master_customers c ON c.customers_code=d.customers_code
            WHERE e.provider_status='GPS_PING'
              AND DATE(e.event_time)=?
        ";
        $pDaily = [$date];
        if ($office !== '') { $sqlDaily .= " AND UPPER(d.office_code)=?"; $pDaily[] = $office; }
        if ($picUid > 0) {
            $sqlDaily .= " AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.by')) AS UNSIGNED)=?";
            $pDaily[] = $picUid;
        }
        if ($q !== '') {
            $sqlDaily .= " AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR c.customers_name LIKE ?)";
            $lk = '%' . $q . '%';
            array_push($pDaily, $lk, $lk, $lk);
        }
        $sqlDaily .= " ORDER BY e.event_time ASC, e.id ASC LIMIT 12000";
        $stDaily = $pdo->prepare($sqlDaily);
        $stDaily->execute($pDaily);
        $dailyRows = $stDaily->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $dailyRows = [];
    }

    if ($picUid > 0) {
        $routePicUid = $picUid;
        $routePicName = $userNames[$picUid] ?? ('User #' . $picUid);
    } elseif (count($picOptions) === 1) {
        $routePicUid = (int)($picOptions[0]['pic_uid'] ?? 0);
        $routePicName = $userNames[$routePicUid] ?? ($routePicUid > 0 ? ('User #' . $routePicUid) : '-');
    } elseif (count($picOptions) > 1) {
        $routePicName = 'Pilih PIC terlebih dahulu';
    }

    // Bangun route aktual dari GPS, hanya jika tidak mencampur banyak PIC.
    $canBuildRoute = $picUid > 0 || count($picOptions) <= 1;
    if ($canBuildRoute) {
        $prev = null;
        foreach ($dailyRows as $r) {
            $lat = (float)($r['latitude'] ?? 0);
            $lng = (float)($r['longitude'] ?? 0);
            if (($lat == 0.0 && $lng == 0.0) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;

            $ts = (string)($r['event_time'] ?? '');
            $tsUnix = $ts !== '' ? strtotime($ts) : false;
            $accept = true;
            $segKm = 0.0;

            if ($prev !== null) {
                $segKm = haversine_km((float)$prev['lat'], (float)$prev['lng'], $lat, $lng);
                $prevTs = (int)($prev['ts_unix'] ?? 0);
                $dtSec = ($tsUnix !== false && $prevTs > 0) ? max(0, (int)$tsUnix - $prevTs) : 0;
                // Buang loncatan GPS yang secara fisik tidak masuk akal; tetap fail-soft untuk gap waktu panjang.
                if ($dtSec > 0) {
                    $speedKmh = ($segKm / ($dtSec / 3600));
                    if ($speedKmh > 160 && $segKm > 0.50) $accept = false;
                } elseif ($segKm > 2.0) {
                    $accept = false;
                }
            }

            if (!$accept) {
                $ignoredSegments++;
                continue;
            }

            if ($firstAt === null) $firstAt = $ts;
            $lastAt = $ts;
            if ($prev !== null) {
                $totalKm += $segKm;
                $validSegments++;
            }

            $routePoints[] = [
                'lat' => $lat,
                'lng' => $lng,
                'at' => $ts,
                'do_id' => (int)($r['do_id'] ?? 0),
                'do_code' => (string)($r['do_code'] ?? ''),
                'customer' => (string)(($r['customers_name'] ?? '') ?: ($r['customers_code'] ?? '')),
                'office' => (string)($r['office_code'] ?? ''),
                'status' => strtolower(trim((string)($r['do_status'] ?? ''))),
                'accuracy_m' => $r['accuracy_m'] !== null ? (float)$r['accuracy_m'] : null,
            ];
            $prev = ['lat' => $lat, 'lng' => $lng, 'ts_unix' => $tsUnix !== false ? (int)$tsUnix : 0];
        }

        if ($firstAt && $lastAt) {
            $a = strtotime($firstAt);
            $b = strtotime($lastAt);
            if ($a !== false && $b !== false && $b >= $a) $durationMin = (int)round(($b - $a) / 60);
        }

        // Urutan kunjungan per DO berdasarkan ping pertama; marker memakai ping terakhir DO tersebut.
        $visitMap = [];
        foreach ($routePoints as $p) {
            $did = (int)$p['do_id'];
            if ($did <= 0) continue;
            if (!isset($visitMap[$did])) {
                $visitMap[$did] = [
                    'do_id' => $did,
                    'do_code' => $p['do_code'],
                    'customer' => $p['customer'],
                    'office' => $p['office'],
                    'status' => $p['status'],
                    'first_at' => $p['at'],
                    'last_at' => $p['at'],
                    'lat' => $p['lat'],
                    'lng' => $p['lng'],
                    'point_count' => 1,
                ];
            } else {
                $visitMap[$did]['last_at'] = $p['at'];
                $visitMap[$did]['lat'] = $p['lat'];
                $visitMap[$did]['lng'] = $p['lng'];
                $visitMap[$did]['status'] = $p['status'];
                $visitMap[$did]['point_count']++;
            }
        }
        $visits = array_values($visitMap);
        usort($visits, static fn($a, $b) => strcmp((string)$a['first_at'], (string)$b['first_at']));

        $statuses = array_values(array_unique(array_filter(array_map(static fn($v) => strtolower((string)($v['status'] ?? '')), $visits))));
        if (!$visits) {
            $routeStatus = 'Tidak ada data';
        } elseif (in_array('on_delivery', $statuses, true)) {
            $routeStatus = 'Berjalan';
        } elseif ($statuses && count(array_diff($statuses, ['delivered'])) === 0) {
            $routeStatus = 'Selesai';
        } else {
            $routeStatus = 'History';
        }
    }

    // Daftar DO untuk audit/detail pada filter harian yang sama.
    try {
        $sqlSessions = "
            SELECT d.id AS do_id, d.do_code, d.customers_code, d.office_code, d.status AS do_status,
                   c.customers_name,
                   MIN(e.event_time) AS started_at,
                   MAX(e.event_time) AS last_ping_at,
                   COUNT(*) AS point_count,
                   GROUP_CONCAT(DISTINCT CAST(JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.by')) AS UNSIGNED) ORDER BY 1 SEPARATOR ',') AS pic_uids
            FROM sales_do_tracking_events e
            JOIN sales_do d ON d.id=e.do_id
            LEFT JOIN master_customers c ON c.customers_code=d.customers_code
            WHERE e.provider_status='GPS_PING'
              AND DATE(e.event_time)=?
        ";
        $pSessions = [$date];
        if ($office !== '') { $sqlSessions .= " AND UPPER(d.office_code)=?"; $pSessions[] = $office; }
        if ($picUid > 0) {
            $sqlSessions .= " AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.by')) AS UNSIGNED)=?";
            $pSessions[] = $picUid;
        }
        if ($q !== '') {
            $sqlSessions .= " AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR c.customers_name LIKE ?)";
            $lk = '%' . $q . '%';
            array_push($pSessions, $lk, $lk, $lk);
        }
        $sqlSessions .= " GROUP BY d.id,d.do_code,d.customers_code,d.office_code,d.status,c.customers_name ORDER BY MIN(e.event_time) ASC LIMIT 500";
        $st = $pdo->prepare($sqlSessions);
        $st->execute($pSessions);
        $sessions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $sessions = [];
    }

    // Pertahankan detail per-DO dari versi sebelumnya.
    if ($doId > 0) {
        try {
            $ss = $pdo->prepare("SELECT d.id AS do_id,d.do_code,d.customers_code,d.office_code,d.status AS do_status,c.customers_name,
                                        MIN(e.event_time) AS started_at,MAX(e.event_time) AS last_ping_at,COUNT(*) AS point_count
                                 FROM sales_do_tracking_events e
                                 JOIN sales_do d ON d.id=e.do_id
                                 LEFT JOIN master_customers c ON c.customers_code=d.customers_code
                                 WHERE e.do_id=? AND e.provider_status='GPS_PING'
                                 GROUP BY d.id,d.do_code,d.customers_code,d.office_code,d.status,c.customers_name LIMIT 1");
            $ss->execute([$doId]);
            $selected = $ss->fetch(PDO::FETCH_ASSOC) ?: null;

            $pp = $pdo->prepare("SELECT e.id,e.do_id,e.event_time AS captured_at,e.raw_json,e.location,
                                        JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.accuracy_m')) AS accuracy_m,
                                        CAST(JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.by')) AS UNSIGNED) AS pic_uid,
                                        CAST(SUBSTRING_INDEX(e.location,',',1) AS DECIMAL(10,7)) AS latitude,
                                        CAST(SUBSTRING_INDEX(e.location,',',-1) AS DECIMAL(10,7)) AS longitude
                                 FROM sales_do_tracking_events e
                                 WHERE e.do_id=? AND e.provider_status='GPS_PING'
                                 ORDER BY e.event_time ASC,e.id ASC LIMIT 5000");
            $pp->execute([$doId]);
            $selectedPoints = $pp->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $selected = null;
            $selectedPoints = [];
        }
    }
}

$firstPoint = $routePoints[0] ?? null;
$lastPoint = $routePoints ? $routePoints[count($routePoints) - 1] : null;
$googleDirectionsUrl = '';
if ($firstPoint && $lastPoint) {
    $googleDirectionsUrl = 'https://www.google.com/maps/dir/?api=1&origin=' . rawurlencode($firstPoint['lat'] . ',' . $firstPoint['lng'])
        . '&destination=' . rawurlencode($lastPoint['lat'] . ',' . $lastPoint['lng']) . '&travelmode=driving';
    $waypoints = [];
    foreach (array_slice($visits, 0, 8) as $v) {
        $waypoints[] = $v['lat'] . ',' . $v['lng'];
    }
    if ($waypoints) $googleDirectionsUrl .= '&waypoints=' . rawurlencode(implode('|', $waypoints));
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$extraHead = <<<'HTML'
<style>
body{background:#08111f;color:#e5e7eb}.wrap{max-width:1540px;margin:22px auto;padding:0 16px}.card{background:#111827;border:1px solid #334155;border-radius:14px;padding:16px;margin-bottom:14px}.muted{color:#94a3b8}.warn{color:#fbbf24}.ok{color:#34d399}.mono{font-family:ui-monospace,monospace}.btnx{display:inline-flex;align-items:center;gap:6px;padding:9px 12px;border:1px solid #475569;border-radius:9px;color:#e5e7eb;text-decoration:none;background:#1f2937}.btnx.primary{background:#2563eb;border-color:#2563eb}.btnx:hover{filter:brightness(1.08);color:#fff}.field{width:100%;box-sizing:border-box;background:#0f172a;color:#e5e7eb;border:1px solid #475569;border-radius:9px;padding:9px}.filter-grid{display:grid;grid-template-columns:1.2fr 1fr 1fr 1.4fr auto;gap:10px}.daily-head{display:grid;grid-template-columns:1.5fr repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.head-box{background:#0f172a;border:1px solid #334155;border-radius:12px;padding:12px;min-height:74px}.head-title{font-size:20px;font-weight:800;margin-bottom:4px}.head-value{font-size:16px;font-weight:700}.daily-layout{display:grid;grid-template-columns:minmax(340px,.78fr) minmax(520px,1.5fr);gap:14px;align-items:stretch}.visit-panel{border:1px solid #334155;border-radius:12px;overflow:hidden;background:#0f172a;max-height:590px;overflow-y:auto}.visit-title{position:sticky;top:0;z-index:2;padding:12px 14px;background:#111827;border-bottom:1px solid #334155;font-weight:800}.visit-item{display:grid;grid-template-columns:42px 1fr auto;gap:10px;padding:12px;border-bottom:1px solid #263449;align-items:start}.visit-no{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#2563eb;color:#fff;font-weight:800}.visit-no.done{background:#16a34a}.visit-name{font-weight:750}.visit-meta{font-size:12px;color:#94a3b8;margin-top:3px}.visit-time{text-align:right;font-weight:700;white-space:nowrap}.status-pill{display:inline-block;margin-top:5px;padding:2px 8px;border-radius:999px;border:1px solid #475569;font-size:11px;color:#cbd5e1}.map-shell{position:relative;min-height:590px;border:1px solid #334155;border-radius:12px;overflow:hidden;background:#0b1220}.route-map{position:absolute;inset:0}.map-empty{display:flex;align-items:center;justify-content:center;height:590px;padding:30px;text-align:center;color:#94a3b8}.route-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.route-stat{background:#0f172a;border:1px solid #334155;border-radius:12px;padding:13px}.route-stat b{display:block;font-size:19px;margin-top:4px}.table-wrap{overflow:auto}.tbl{width:100%;border-collapse:collapse;min-width:1080px}.tbl th,.tbl td{padding:9px;border-bottom:1px solid #263449;text-align:left;font-size:12px}.tbl th{color:#94a3b8}.notice{padding:12px;border:1px solid #334155;border-radius:10px;background:#0f172a;margin-bottom:12px}.map-label{background:#2563eb;color:#fff;border:2px solid #fff;border-radius:999px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;font-weight:800;box-shadow:0 2px 8px rgba(0,0,0,.35)}
@media(max-width:1100px){.daily-head{grid-template-columns:1fr 1fr}.daily-layout{grid-template-columns:1fr}.map-shell,.map-empty{min-height:480px}.route-stats{grid-template-columns:1fr 1fr}.filter-grid{grid-template-columns:1fr 1fr}}
@media(max-width:650px){.daily-head,.route-stats,.filter-grid{grid-template-columns:1fr}.wrap{padding:0 10px}.map-shell,.map-empty{min-height:380px}.visit-item{grid-template-columns:36px 1fr}.visit-time{grid-column:2;text-align:left}}
</style>
HTML;

rmi_header('SCM - History Tracking', [
    'active' => 'sales',
    'breadcrumbs' => [
        ['label'=>'Sales (CRM)','url'=>$baseProject.'/sales/sales_dashboard.php'],
        ['label'=>'SCM Task','url'=>$baseProject.'/sales/scm_do_tasks.php'],
        'History Tracking'
    ],
    'actions' => [
        ['label'=>'← SCM Task','url'=>$baseProject.'/sales/scm_do_tasks.php','class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'📍 Tracker','url'=>$baseProject.'/sales/scm_tracker_mobile.php','class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'🗼 Control Tower','url'=>$baseProject.'/sales/sales_control_tower.php','class'=>'btn btn-sm btn-outline-light'],
    ],
    'extra_head' => $extraHead,
]);
?>
<div class="wrap">
  <div class="card">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
      <div>
        <h2 style="margin:0 0 6px">Rute Pengiriman SCM (1 Hari)</h2>
        <div class="muted">Read-only · rute aktual berdasarkan <b>GPS_PING</b> pada <code>sales_do_tracking_events</code>. Tidak mengubah status DO maupun workflow SCM.</div>
      </div>
      <?php if($googleDirectionsUrl !== ''): ?>
        <a class="btnx primary" target="_blank" rel="noopener" href="<?=hh($googleDirectionsUrl)?>">🗺️ Lihat di Google Maps ↗</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if(!$ready): ?>
    <div class="card"><div class="warn"><b>History GPS belum aktif.</b> Tabel <code>sales_do_tracking_events</code> belum tersedia.</div></div>
  <?php else: ?>

  <div class="card">
    <form method="get" class="filter-grid">
      <div><div class="muted">Tanggal</div><input class="field" type="date" name="date" value="<?=hh($date)?>"></div>
      <div><div class="muted">Office</div><select class="field" name="office"><option value="">Semua Office</option><?php foreach($offices as $o): ?><option value="<?=hh($o)?>" <?=$office===strtoupper((string)$o)?'selected':''?>><?=hh($o)?></option><?php endforeach; ?></select></div>
      <div><div class="muted">PIC / Driver</div><select class="field" name="pic_uid"><option value="0">Semua PIC</option><?php foreach($picOptions as $p): $uid=(int)($p['pic_uid']??0); $nm=$userNames[$uid]??('User #'.$uid); ?><option value="<?=$uid?>" <?=$picUid===$uid?'selected':''?>><?=hh($nm)?> · <?=number_format((int)($p['ping_count']??0))?> ping</option><?php endforeach; ?></select></div>
      <div><div class="muted">DO / Customer</div><input class="field" name="q" value="<?=hh($q)?>" placeholder="DO code / customer"></div>
      <div style="align-self:end"><button class="btnx primary" type="submit">Tampilkan</button> <a class="btnx" href="?date=<?=hh(date('Y-m-d'))?>">Reset</a></div>
    </form>
  </div>

  <div class="daily-head">
    <div class="head-box">
      <div class="head-title">🚚 Rute Harian SCM</div>
      <div class="muted"><?=hh(date('d M Y', strtotime($date)))?> · <?=hh($office !== '' ? $office : 'Semua Office')?></div>
      <div class="muted" style="margin-top:5px">Jejak aktual GPS, bukan rute perkiraan Google Directions.</div>
    </div>
    <div class="head-box"><div class="muted">Tanggal</div><div class="head-value">📅 <?=hh(date('d M Y', strtotime($date)))?></div></div>
    <div class="head-box"><div class="muted">Driver / PIC</div><div class="head-value">👤 <?=hh($routePicName)?></div><div class="muted"><?=$routePicUid>0?'User ID '.$routePicUid:'-'?></div></div>
    <div class="head-box"><div class="muted">Kendaraan</div><div class="head-value">🚚 Belum direkam</div><div class="muted">Tracker saat ini belum menyimpan vehicle</div></div>
    <div class="head-box"><div class="muted">Status</div><div class="head-value"><?=$routeStatus==='Selesai'?'✅':($routeStatus==='Berjalan'?'🟢':'📍')?> <?=hh($routeStatus)?></div><div class="muted"><?=count($visits)?> DO / kunjungan</div></div>
  </div>

  <?php if(count($picOptions) > 1 && $picUid <= 0): ?>
    <div class="card"><div class="notice warn"><b>Pilih PIC / Driver terlebih dahulu.</b> Ada lebih dari satu petugas yang mengirim GPS pada tanggal ini. Sistem sengaja tidak menggabungkan titik GPS antar petugas agar garis rute tidak zig-zag atau menyesatkan.</div></div>
  <?php endif; ?>

  <div class="card">
    <div class="daily-layout">
      <div class="visit-panel">
        <div class="visit-title">Urutan Kunjungan (<?=number_format(count($visits))?> DO)</div>
        <?php if(!$visits): ?>
          <div style="padding:16px" class="muted">Belum ada rute yang dapat ditampilkan untuk filter ini.</div>
        <?php else: ?>
          <?php foreach($visits as $i=>$v):
            $st = strtolower((string)($v['status']??''));
            $done = $st === 'delivered';
          ?>
          <div class="visit-item">
            <div class="visit-no <?=$done?'done':''?>"><?=($i+1)?></div>
            <div>
              <div class="visit-name"><?=hh(($v['customer']??'') ?: ($v['do_code']??''))?></div>
              <div class="visit-meta"><?=hh($v['do_code']??'')?> · <?=hh($v['office']??'')?> · <?=number_format((int)($v['point_count']??0))?> GPS</div>
              <span class="status-pill"><?=hh(strtoupper($st !== '' ? $st : '-'))?></span>
            </div>
            <div class="visit-time"><?=hh(date('H:i', strtotime((string)$v['first_at'])))?><div class="visit-meta">s/d <?=hh(date('H:i', strtotime((string)$v['last_at'])))?></div></div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="map-shell">
        <?php if($googleMapsApiKey === ''): ?>
          <div class="map-empty"><div><b>Google Maps API key belum terbaca.</b><br><span class="muted">Pastikan <code>config/google_maps.php</code> berisi <code>google_maps_api_key</code>.</span></div></div>
        <?php elseif(!$routePoints): ?>
          <div class="map-empty"><div><b>Belum ada titik GPS untuk rute ini.</b><br><span class="muted">Pilih tanggal / office / PIC yang memiliki GPS_PING.</span></div></div>
        <?php else: ?>
          <div id="dailyRouteMap" class="route-map"></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="route-stats">
      <div class="route-stat"><span class="muted">Total Jarak Aktual</span><b><?=number_format($totalKm,2,',','.')?> km</b><span class="muted"><?=number_format($validSegments)?> segmen valid</span></div>
      <div class="route-stat"><span class="muted">Total Durasi Tercatat</span><b><?=number_format($durationMin)?> menit</b><span class="muted"><?=hh($firstAt ? date('H:i',strtotime($firstAt)) : '-')?> – <?=hh($lastAt ? date('H:i',strtotime($lastAt)) : '-')?></span></div>
      <div class="route-stat"><span class="muted">Total Titik Kunjungan</span><b><?=number_format(count($visits))?> DO</b><span class="muted"><?=number_format(count($routePoints))?> GPS point</span></div>
      <div class="route-stat"><span class="muted">Estimasi BBM</span><b>—</b><span class="muted">km/l kendaraan belum direkam oleh tracker</span></div>
    </div>
    <?php if($ignoredSegments > 0): ?><div class="muted" style="margin-top:8px">Catatan: <?=number_format($ignoredSegments)?> loncatan GPS tidak wajar diabaikan dari perhitungan jarak.</div><?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin-top:0">DO yang Terekam pada <?=hh(date('d M Y', strtotime($date)))?></h3>
    <div class="table-wrap"><table class="tbl"><thead><tr><th>Urut</th><th>DO</th><th>Customer</th><th>Office</th><th>Start GPS</th><th>Last GPS</th><th>Status DO</th><th>GPS Points</th><th>PIC / Driver</th><th>Aksi</th></tr></thead><tbody>
      <?php if(!$sessions): ?><tr><td colspan="10" class="muted">Belum ada GPS_PING pada filter ini.</td></tr><?php endif; ?>
      <?php foreach($sessions as $i=>$s): ?>
      <tr>
        <td><?=($i+1)?></td>
        <td><b><?=hh($s['do_code']??'')?></b></td>
        <td><?=hh(($s['customers_name']??'') ?: ($s['customers_code']??''))?></td>
        <td><?=hh($s['office_code']??'')?></td>
        <td><?=hh($s['started_at']??'-')?></td>
        <td><?=hh($s['last_ping_at']??'-')?></td>
        <td><?=hh(strtoupper((string)($s['do_status']??'-')))?></td>
        <td><?=number_format((int)($s['point_count']??0))?></td>
        <td><?=hh(pic_labels_from_csv((string)($s['pic_uids']??''), $userNames))?><div class="muted">UID <?=hh($s['pic_uids']??'-')?></div></td>
        <td><a class="btnx" href="?date=<?=hh($date)?>&office=<?=rawurlencode($office)?>&pic_uid=<?=$picUid?>&q=<?=rawurlencode($q)?>&do_id=<?=(int)$s['do_id']?>">Detail GPS</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>

  <?php if($selected): ?>
  <div class="card">
    <h3 style="margin-top:0">Detail GPS — <?=hh($selected['do_code']??'')?></h3>
    <div class="muted" style="margin-bottom:10px"><?=hh(($selected['customers_name']??'') ?: ($selected['customers_code']??''))?> · <?=number_format((int)($selected['point_count']??0))?> titik</div>
    <div class="table-wrap"><table class="tbl"><thead><tr><th>#</th><th>Waktu</th><th>Latitude</th><th>Longitude</th><th>Accuracy</th><th>PIC / Driver</th><th>Map</th></tr></thead><tbody>
      <?php foreach($selectedPoints as $i=>$p):
        $lat=(string)($p['latitude']??''); $lng=(string)($p['longitude']??'');
        $map='https://www.google.com/maps?q='.rawurlencode($lat.','.$lng);
      ?>
      <tr><td><?=($i+1)?></td><td><?=hh($p['captured_at']??'')?></td><td class="mono"><?=hh($lat)?></td><td class="mono"><?=hh($lng)?></td><td><?=hh($p['accuracy_m']??'-')?> m</td><td><?=hh($userNames[(int)($p['pic_uid']??0)] ?? ('User #'.(int)($p['pic_uid']??0)))?><div class="muted">UID <?=hh($p['pic_uid']??'-')?></div></td><td><a class="btnx" target="_blank" rel="noopener" href="<?=hh($map)?>">Map ↗</a></td></tr>
      <?php endforeach; ?>
      <?php if(!$selectedPoints): ?><tr><td colspan="7" class="muted">Belum ada titik GPS.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?php if($googleMapsApiKey !== '' && $routePoints): ?>
<script>
window.RMI_DAILY_ROUTE = <?=json_encode($routePoints, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
window.RMI_DAILY_VISITS = <?=json_encode($visits, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
function initDailyRouteMap(){
  const points = Array.isArray(window.RMI_DAILY_ROUTE) ? window.RMI_DAILY_ROUTE : [];
  const visits = Array.isArray(window.RMI_DAILY_VISITS) ? window.RMI_DAILY_VISITS : [];
  const el = document.getElementById('dailyRouteMap');
  if (!el || !points.length || !window.google || !google.maps) return;

  const map = new google.maps.Map(el, {
    zoom: 13,
    center: {lat:Number(points[0].lat), lng:Number(points[0].lng)},
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: true,
    gestureHandling: 'greedy'
  });
  const bounds = new google.maps.LatLngBounds();
  const path = [];
  points.forEach(p => {
    const ll = {lat:Number(p.lat), lng:Number(p.lng)};
    if (!Number.isFinite(ll.lat) || !Number.isFinite(ll.lng)) return;
    path.push(ll); bounds.extend(ll);
  });

  if (path.length > 1) {
    new google.maps.Polyline({
      path,
      geodesic:true,
      strokeColor:'#2563eb',
      strokeOpacity:0.92,
      strokeWeight:5,
      map
    });
  }

  if (path.length) {
    new google.maps.Marker({
      position:path[0], map,
      title:'Mulai ' + String(points[0].at || ''),
      label:{text:'S',color:'#fff',fontWeight:'700'},
      icon:{path:google.maps.SymbolPath.CIRCLE,scale:12,fillColor:'#16a34a',fillOpacity:1,strokeColor:'#ffffff',strokeWeight:2}
    });
  }

  visits.forEach((v, idx) => {
    const pos = {lat:Number(v.lat), lng:Number(v.lng)};
    if (!Number.isFinite(pos.lat) || !Number.isFinite(pos.lng)) return;
    bounds.extend(pos);
    const marker = new google.maps.Marker({
      position:pos,
      map,
      title:String(v.customer || v.do_code || ''),
      label:{text:String(idx+1),color:'#fff',fontWeight:'700'},
      icon:{path:google.maps.SymbolPath.CIRCLE,scale:13,fillColor:(String(v.status||'').toLowerCase()==='delivered'?'#16a34a':'#2563eb'),fillOpacity:1,strokeColor:'#ffffff',strokeWeight:2}
    });
    const content = '<div style="min-width:210px"><b>'+escapeHtml(v.customer || v.do_code || '-')+'</b><br>'+escapeHtml(v.do_code || '')+'<br><small>'+escapeHtml(v.first_at || '')+' s/d '+escapeHtml(v.last_at || '')+'</small></div>';
    const iw = new google.maps.InfoWindow({content});
    marker.addListener('click', () => iw.open({anchor:marker,map}));
  });

  if (!bounds.isEmpty()) map.fitBounds(bounds, 45);
}
function escapeHtml(s){
  return String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
}
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?=rawurlencode($googleMapsApiKey)?>&callback=initDailyRouteMap&v=weekly"></script>
<?php endif; ?>

<?php if(function_exists('rmi_footer')) rmi_footer(); ?>
