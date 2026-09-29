<?php
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../app/Services/SalesTrackingService.php';
require_login();

// Manager Finance diberi akses READ/MONITOR ke Sales Control Tower agar dapat
// memantau status PAID dan memfilter berdasarkan tanggal pembayaran.
// Ini BUKAN bypass untuk aksi Sales/SCM/ACT; endpoint aksi tetap mengikuti RBAC masing-masing.
$__sctUser = function_exists('auth_user') ? (array)auth_user() : [];
$__sctDept = strtoupper(trim((string)(
    $__sctUser['department'] ?? $__sctUser['dept'] ?? $__sctUser['dept_code']
    ?? ($_SESSION['department'] ?? $_SESSION['dept'] ?? $_SESSION['dept_code'] ?? '')
)));
$__sctRole = strtoupper(trim((string)($__sctUser['role'] ?? ($_SESSION['role'] ?? ''))));
$__sctLevel = strtoupper(trim((string)($__sctUser['level'] ?? ($_SESSION['level'] ?? ''))));
$__sctUsername = strtoupper(trim((string)(
    $__sctUser['username'] ?? ($_SESSION['username'] ?? $_SESSION['user_name'] ?? '')
)));
$__sctIsMgrFin = in_array($__sctDept, ['FIN','FINANCE'], true)
    && (
        in_array($__sctRole, ['MANAGER','MGR','HEAD'], true)
        || in_array($__sctLevel, ['MANAGER','MGR','HEAD'], true)
        || strpos($__sctUsername, 'MGRFIN') !== false
    );

// P1: RBAC — akses route normal tetap ketat. Hanya Mgr FIN yang mendapat tambahan
// akses read-only untuk kebutuhan monitoring pembayaran.
if (!$__sctIsMgrFin) {
    if (function_exists('require_route_access')) {
        require_route_access(['SALES.CONTROL_TOWER_VIEW', 'SALES.EDIT']);
    } elseif (function_exists('require_any_permission')) {
        require_any_permission(['SALES.CONTROL_TOWER_VIEW', 'SALES.EDIT']);
    }
}

// Office scope: BRANCH hanya lihat DO kantornya sendiri
require_once __DIR__ . '/_do_office_scope.php';

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
$trackingSvc = new \App\Services\SalesTrackingService();

// -----------------------
// Helpers
// -----------------------
if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('up')) {
    function up($s): string { return strtoupper(trim((string)($s ?? ''))); }
}

// Effective category item: snapshot transaksi menang; master hanya fallback untuk data legacy.
function sct_item_category_sql(string $itemAlias='si', string $productAlias='sp'): string {
    return "CASE
        WHEN UPPER(TRIM(COALESCE({$itemAlias}.category,''))) IN ('BMHP','ALKES','AKSESORIS')
            THEN UPPER(TRIM({$itemAlias}.category))
        WHEN UPPER(TRIM(COALESCE({$productAlias}.category,''))) IN ('BMHP','ALKES','AKSESORIS')
            THEN UPPER(TRIM({$productAlias}.category))
        ELSE UPPER(TRIM(COALESCE({$itemAlias}.category,{$productAlias}.category,'')))
    END";
}
function sct_business_group_sql(string $doAlias='d'): string {
    return "CASE
        WHEN UPPER(TRIM(COALESCE({$doAlias}.business_group,'')))='UNIT_ACC' THEN 'UNIT_ACC'
        WHEN UPPER(TRIM(COALESCE({$doAlias}.category,'')))='UNIT_ACC' THEN 'UNIT_ACC'
        WHEN EXISTS (SELECT 1 FROM sales_do_items sbg WHERE sbg.do_id={$doAlias}.id AND UPPER(TRIM(COALESCE(sbg.business_group,'')))='UNIT_ACC') THEN 'UNIT_ACC'
        ELSE 'BMHP'
    END";
}

function sct_generate_token(): string { return bin2hex(random_bytes(24)); }

function sct_tracking_public_url(string $token): string {
  if ($token === '') return '';
  $base = (string)(defined('BASE_PROJECT') ? BASE_PROJECT : (function_exists('auth_base_project') ? auth_base_project() : ''));
  $path = ($base !== '' ? rtrim($base, '/') . '/' : '') . 'sales/tracking_public.php?t=' . rawurlencode($token);
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme  = $isHttps ? 'https' : 'http';
    $host    = (string)($_SERVER['HTTP_HOST'] ?? '');
  if ($host === '') return '/' . ltrim($path, '/');
  return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function sct_wa_share_url(string $doCode, string $trackingUrl): string {
  $msg = "Halo, berikut link tracking pengiriman DO {$doCode}:\n{$trackingUrl}";
  return 'https://wa.me/?text=' . rawurlencode($msg);
}

function sct_ensure_col(PDO $pdo, string $table, string $col, string $def): void {
  $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    if ($st->rowCount() === 0) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$def}");
}

function sd_ensure_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_do` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `do_code` varchar(50) NOT NULL,
    `tracking_code` varchar(50) DEFAULT NULL,
    `do_date` date NOT NULL,
    `customers_code` varchar(50) NOT NULL,
    `office_code` varchar(20) NOT NULL,
    `sales_emp_code` varchar(50) DEFAULT NULL,
    `shipping_address` text DEFAULT NULL,
    `customer_pic` varchar(150) DEFAULT NULL,
    `customer_phone` varchar(50) DEFAULT NULL,
    `note` varchar(255) DEFAULT NULL,
    `total_amount` decimal(18,2) DEFAULT 0,
    `tax_code` varchar(20) DEFAULT NULL,
    `tax_rate_percent` decimal(5,2) DEFAULT 0,
    `tax_amount` decimal(18,2) DEFAULT 0,
    `grand_total` decimal(18,2) DEFAULT 0,
    `is_price_include_tax` tinyint(1) DEFAULT 0,
    `status` varchar(50) NOT NULL DEFAULT 'crm_to_wqs',
    `crm_created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_do_code` (`do_code`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Identitas dokumen baru: kelompok bisnis terpisah dari kategori item.
    sct_ensure_col($pdo,'sales_do','category',"category VARCHAR(20) NOT NULL DEFAULT 'BMHP'");
    sct_ensure_col($pdo,'sales_do','business_group',"business_group VARCHAR(20) NOT NULL DEFAULT 'BMHP'");
    sct_ensure_col($pdo,'sales_do','return_status',"return_status VARCHAR(50) NULL");
    sct_ensure_col($pdo,'sales_do','return_last_at',"return_last_at DATETIME NULL");
    // Item snapshot dipakai untuk filter komposisi DO tanpa JOIN yang menggandakan baris.
    sct_ensure_col($pdo,'sales_do_items','category',"category VARCHAR(20) NOT NULL DEFAULT 'BMHP'");
    sct_ensure_col($pdo,'sales_do_items','business_group',"business_group VARCHAR(20) NOT NULL DEFAULT 'BMHP'");

    sct_ensure_col($pdo,'sales_do','wqs_status',"wqs_status VARCHAR(20) NULL");
    sct_ensure_col($pdo,'sales_do','wqs_note',"wqs_note TEXT NULL");
    sct_ensure_col($pdo,'sales_do','wqs_stock_before',"wqs_stock_before VARCHAR(255) NULL");
    sct_ensure_col($pdo,'sales_do','wqs_stock_after',"wqs_stock_after VARCHAR(255) NULL");
    sct_ensure_col($pdo,'sales_do','wqs_started_at',"wqs_started_at DATETIME NULL");
    sct_ensure_col($pdo,'sales_do','wqs_ready_at',"wqs_ready_at DATETIME NULL");

    sct_ensure_col($pdo,'sales_do','scm_status',"scm_status VARCHAR(20) NULL");
    sct_ensure_col($pdo,'sales_do','scm_note',"scm_note TEXT NULL");
    sct_ensure_col($pdo,'sales_do','scm_delivered_at',"scm_delivered_at DATETIME NULL");
    sct_ensure_col($pdo,'sales_do','scm_on_delivery_at',"scm_on_delivery_at DATETIME NULL");
    // P1: kolom GPS SCM (sebelumnya di-SELECT tapi tidak pernah di-create di sini)
    sct_ensure_col($pdo,'sales_do','scm_live_lat',"scm_live_lat DECIMAL(10,7) NULL");
    sct_ensure_col($pdo,'sales_do','scm_live_lng',"scm_live_lng DECIMAL(10,7) NULL");
    sct_ensure_col($pdo,'sales_do','scm_live_accuracy_m',"scm_live_accuracy_m DECIMAL(8,2) NULL");
    sct_ensure_col($pdo,'sales_do','scm_live_at',"scm_live_at DATETIME NULL");

    sct_ensure_col($pdo,'sales_do','delivery_mode',"delivery_mode VARCHAR(20) NULL");
    sct_ensure_col($pdo,'sales_do','delivery_vendor_id',"delivery_vendor_id INT NULL");
    sct_ensure_col($pdo,'sales_do','carrier_provider',"carrier_provider VARCHAR(30) NULL");
    sct_ensure_col($pdo,'sales_do','carrier_tracking_no',"carrier_tracking_no VARCHAR(100) NULL");
    sct_ensure_col($pdo,'sales_do','carrier_courier_code',"carrier_courier_code VARCHAR(50) NULL");
    sct_ensure_col($pdo,'sales_do','tracking_public_token',"tracking_public_token VARCHAR(100) NULL");
    sct_ensure_col($pdo,'sales_do','tracking_last_sync_at',"tracking_last_sync_at DATETIME NULL");
    sct_ensure_col($pdo,'sales_do','tracking_last_status',"tracking_last_status VARCHAR(100) NULL");
    sct_ensure_col($pdo,'sales_do','tracking_last_payload_json',"tracking_last_payload_json LONGTEXT NULL");
    sct_ensure_col($pdo,'sales_do','fallback_live_location_url',"fallback_live_location_url VARCHAR(500) NULL");

    sct_ensure_col($pdo,'sales_do','act_status',"act_status VARCHAR(20) NULL");
    sct_ensure_col($pdo,'sales_do','act_note',"act_note TEXT NULL");
    sct_ensure_col($pdo,'sales_do','act_due_date',"act_due_date DATE NULL");
    sct_ensure_col($pdo,'sales_do','act_amount',"act_amount DECIMAL(15,2) NULL");
    sct_ensure_col($pdo,'sales_do','act_ready_fin_at',"act_ready_fin_at DATETIME NULL");

    sct_ensure_col($pdo,'sales_do','fin_status',"fin_status VARCHAR(20) NULL");
    sct_ensure_col($pdo,'sales_do','fin_note',"fin_note TEXT NULL");
    sct_ensure_col($pdo,'sales_do','fin_paid_date',"fin_paid_date DATE NULL");
    sct_ensure_col($pdo,'sales_do','fin_paid_at',"fin_paid_at DATETIME NULL");

    sct_ensure_col($pdo,'sales_do','last_updated_by',"last_updated_by VARCHAR(50) NULL");
    sct_ensure_col($pdo,'sales_do','last_updated_at',"last_updated_at DATETIME NULL");

  $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_do_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `do_id` INT NOT NULL,
    `status_from` VARCHAR(50) NULL,
    `status_to` VARCHAR(50) NULL,
    `actor_dept` VARCHAR(50) NULL,
    `actor_name` VARCHAR(100) NULL,
    `note` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_do_id` (`do_id`),
    KEY `idx_created_at` (`created_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

try { sd_ensure_schema($pdo); } catch (Throwable $e) {}

$success = '';
$error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (function_exists('require_post')) require_post();
    if (function_exists('verify_csrf')) verify_csrf();
    $action = (string)($_POST['action'] ?? '');
        $id     = (int)($_POST['id'] ?? 0);
    // Akses tambahan Mgr FIN pada halaman ini bersifat monitoring/read-only.
    if ($__sctIsMgrFin && $action !== '') {
      throw new Exception('Akses Manager Finance pada Sales Control Tower adalah monitoring/filter. Aksi operasional tetap mengikuti kewenangan departemen terkait.');
    }
    if ($action === 'refresh_tracking' && $id > 0) {
      $sync = $trackingSvc->syncByDoId($pdo, $id, true);
      if (empty($sync['ok'])) throw new Exception((string)($sync['error'] ?? 'Sync gagal.'));
      $success = "Tracking di-refresh: " . h((string)($sync['provider_status'] ?? 'UNKNOWN'));
    }
  } catch (Throwable $e) {
    $error = "Error: " . $e->getMessage();
  }
}

// -----------------------
// Filters (P3: tambah date range)
// -----------------------
$f_office          = up($_GET['office_code'] ?? '');
$f_status          = trim((string)($_GET['status'] ?? ''));
$f_business_group  = strtoupper(trim((string)($_GET['business_group'] ?? '')));
$f_item_category   = strtoupper(trim((string)($_GET['item_category'] ?? '')));
// Backward compatibility URL lama ?category=ALKES/AKSESORIS/BMHP.
$legacy_category   = strtoupper(trim((string)($_GET['category'] ?? '')));
if ($f_business_group === '' && $f_item_category === '' && in_array($legacy_category, ['BMHP','ALKES','AKSESORIS'], true)) {
    if ($legacy_category === 'BMHP') { $f_business_group = 'BMHP'; $f_item_category = 'BMHP'; }
    else { $f_business_group = 'UNIT_ACC'; $f_item_category = $legacy_category; }
}
if (!in_array($f_business_group, ['', 'BMHP','UNIT_ACC'], true)) $f_business_group = '';
if (!in_array($f_item_category, ['', 'BMHP','ALKES','AKSESORIS'], true)) $f_item_category = '';
$f_date_from = trim((string)($_GET['date_from'] ?? ''));
$f_date_to   = trim((string)($_GET['date_to'] ?? ''));
$f_paid      = strtoupper(trim((string)($_GET['paid'] ?? '')));
$f_paid_from = trim((string)($_GET['paid_from'] ?? ''));
$f_paid_to   = trim((string)($_GET['paid_to'] ?? ''));
$q           = trim((string)($_GET['q'] ?? ''));

if ($f_date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_from)) $f_date_from = '';
if ($f_date_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_to))   $f_date_to   = '';
if ($f_paid_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_paid_from)) $f_paid_from = '';
if ($f_paid_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_paid_to))   $f_paid_to   = '';
if ($f_paid_from !== '' && $f_paid_to !== '' && $f_paid_from > $f_paid_to) {
    [$f_paid_from, $f_paid_to] = [$f_paid_to, $f_paid_from];
}
if (!in_array($f_paid, ['', 'PAID', 'UNPAID'], true)) $f_paid = '';

$sctBusinessGroupSql = sct_business_group_sql('d');
$sctItemCategorySql = sct_item_category_sql('fic','ficp');

$offices = [];
try {
  $offices = $pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$baseStatus    = ['revision_requested','crm_to_wqs','sent_wqs','wqs_processing','wqs_done','ready_scm','scm_done','on_delivery','delivered','act_done','wait_payment','fin_done','paid','paid_done','cancelled','closed'];
$dbStatus      = [];
try {
  $dbStatus = $pdo->query("SELECT DISTINCT status FROM sales_do WHERE status IS NOT NULL AND status<>'' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
$statusOptions = array_values(array_unique(array_merge($baseStatus, array_map('strval', $dbStatus))));

// FULL RETURN filter: dihitung dari qty retur selesai SCM.
// PARTIAL return tetap dianggap shipment aktif selama masih ada qty belum diretur.
$sctFullReturnExcludeSql = '';
try {
    $hasRetTbl = (bool)$pdo->query("SHOW TABLES LIKE 'sales_do_returns'")->fetchColumn();
    $hasRetItemTbl = (bool)$pdo->query("SHOW TABLES LIKE 'sales_do_return_items'")->fetchColumn();
    $hasDoItemTbl = (bool)$pdo->query("SHOW TABLES LIKE 'sales_do_items'")->fetchColumn();
    if ($hasRetTbl && $hasRetItemTbl && $hasDoItemTbl) {
        $sctFullReturnExcludeSql = <<<'SQL'

        AND NOT (
            COALESCE((
                SELECT SUM(ri.qty_return)
                FROM sales_do_return_items ri
                JOIN sales_do_returns rr ON rr.id=ri.return_id
                WHERE rr.do_id=d.id
                  AND LOWER(COALESCE(rr.status,'')) IN ('return_scm_completed','return_completed','completed','closed')
            ),0) >= COALESCE((SELECT SUM(di.qty) FROM sales_do_items di WHERE di.do_id=d.id),0)
            AND COALESCE((SELECT SUM(di2.qty) FROM sales_do_items di2 WHERE di2.do_id=d.id),0) > 0
        )
SQL;
    }
} catch (Throwable $e) {
    $sctFullReturnExcludeSql = '';
}



// -----------------------
// Live tracking feed (read-only, tidak mengubah status/workflow DO)
// Dipakai Control Tower untuk refresh posisi ON DELIVERY tanpa reload halaman penuh.
// -----------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'live_positions') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $liveWhere = "LOWER(TRIM(d.status))='on_delivery'" . $sctFullReturnExcludeSql;
    $liveParams = [];
    if ($DO_SCOPE_OFFICE !== null) {
        $liveWhere .= " AND d.office_code=?";
        $liveParams[] = $DO_SCOPE_OFFICE;
    } elseif ($f_office !== '') {
        $liveWhere .= " AND d.office_code=?";
        $liveParams[] = $f_office;
    }
    if ($f_business_group !== '') {
        $liveWhere .= " AND {$sctBusinessGroupSql}=?";
        $liveParams[] = $f_business_group;
    }
    if ($f_item_category !== '') {
        $liveWhere .= " AND EXISTS (SELECT 1 FROM sales_do_items fic LEFT JOIN master_products ficp ON ficp.id=fic.product_id WHERE fic.do_id=d.id AND {$sctItemCategorySql}=?)";
        $liveParams[] = $f_item_category;
    }
    if ($q !== '') {
        $liveWhere .= " AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR c.customers_name LIKE ? OR o.office_name LIKE ?)";
        $like = '%' . $q . '%';
        array_push($liveParams, $like, $like, $like, $like);
    }

    try {
        $stLive = $pdo->prepare("SELECT
            d.id, d.do_code, d.customers_code, c.customers_name,
            d.office_code, o.office_name, d.status,
            d.scm_live_lat, d.scm_live_lng, d.scm_live_accuracy_m, d.scm_live_at
          FROM sales_do d
          LEFT JOIN master_customers c ON c.customers_code=d.customers_code
          LEFT JOIN master_office o ON o.office_code=d.office_code
          WHERE {$liveWhere}
          ORDER BY d.scm_live_at DESC, d.id DESC
          LIMIT 300");
        $stLive->execute($liveParams);
        $items = [];
        foreach ($stLive->fetchAll(PDO::FETCH_ASSOC) as $lr) {
            [$gpsState, $gpsColor, $ageSec, $ageLabel] = sct_live_state($lr['scm_live_at'] ?? null);
            $items[] = [
                'id' => (int)$lr['id'],
                'do_code' => (string)$lr['do_code'],
                'customers_code' => (string)($lr['customers_code'] ?? ''),
                'customers_name' => (string)($lr['customers_name'] ?? ''),
                'office_code' => (string)($lr['office_code'] ?? ''),
                'office_name' => (string)($lr['office_name'] ?? ''),
                'status' => (string)($lr['status'] ?? ''),
                'lat' => $lr['scm_live_lat'] !== null ? (float)$lr['scm_live_lat'] : null,
                'lng' => $lr['scm_live_lng'] !== null ? (float)$lr['scm_live_lng'] : null,
                'accuracy_m' => $lr['scm_live_accuracy_m'] !== null ? (float)$lr['scm_live_accuracy_m'] : null,
                'live_at' => (string)($lr['scm_live_at'] ?? ''),
                'gps_state' => $gpsState,
                'gps_color' => $gpsColor,
                'age_seconds' => $ageSec,
                'age_label' => $ageLabel,
                'map_url' => sct_map_url($lr['scm_live_lat'] ?? null, $lr['scm_live_lng'] ?? null),
            ];
        }
        echo json_encode(['ok'=>true, 'server_time'=>date('Y-m-d H:i:s'), 'items'=>$items], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>'Gagal membaca live tracking.'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    exit;
}

// WHERE clause (shared)
$where  = "1=1";
$params = [];
if ($DO_SCOPE_OFFICE !== null) {
    $where .= " AND d.office_code=?"; $params[] = $DO_SCOPE_OFFICE;
} elseif ($f_office !== '') {
    $where .= " AND d.office_code=?"; $params[] = $f_office;
}
if ($f_status !== '') { $where .= " AND d.status=?"; $params[] = $f_status; }
if ($f_business_group !== '') { $where .= " AND {$sctBusinessGroupSql}=?"; $params[] = $f_business_group; }
if ($f_item_category !== '') {
    $where .= " AND EXISTS (SELECT 1 FROM sales_do_items fic LEFT JOIN master_products ficp ON ficp.id=fic.product_id WHERE fic.do_id=d.id AND {$sctItemCategorySql}=?)";
    $params[] = $f_item_category;
}
if ($f_date_from !== '') { $where .= " AND d.do_date >= ?";  $params[] = $f_date_from; }
if ($f_date_to   !== '') { $where .= " AND d.do_date <= ?";  $params[] = $f_date_to; }

// Filter PAID menggunakan tanggal FIN aktual. Untuk data legacy yang statusnya sudah
// PAID/FIN_DONE tetapi fin_paid_at/fin_paid_date kosong, gunakan audit transisi FIN
// sebagai fallback. last_updated_at hanya dipakai fallback terakhir untuk status final.
$paidDateSql = "COALESCE(
    DATE(d.fin_paid_at),
    d.fin_paid_date,
    DATE((SELECT MAX(a1.created_at) FROM sales_do_audit a1
          WHERE a1.do_id=d.id
            AND LOWER(TRIM(COALESCE(a1.status_to,''))) IN ('paid','paid_done','fin_done'))),
    CASE WHEN LOWER(TRIM(COALESCE(d.status,''))) IN ('paid','paid_done','fin_done','closed')
         THEN DATE(d.last_updated_at) ELSE NULL END
)";
if ($f_paid === 'PAID') {
    $where .= " AND (LOWER(TRIM(COALESCE(d.status,''))) IN ('paid','paid_done','closed','fin_done') OR {$paidDateSql} IS NOT NULL)";
} elseif ($f_paid === 'UNPAID') {
    $where .= " AND LOWER(TRIM(COALESCE(d.status,''))) NOT IN ('paid','paid_done','closed','fin_done') AND {$paidDateSql} IS NULL";
}
if ($f_paid_from !== '') { $where .= " AND {$paidDateSql} >= ?"; $params[] = $f_paid_from; }
if ($f_paid_to   !== '') { $where .= " AND {$paidDateSql} <= ?"; $params[] = $f_paid_to; }

if ($q !== '') {
  $where .= " AND (d.do_code LIKE ? OR d.tracking_code LIKE ? OR d.customers_code LIKE ? OR c.customers_name LIKE ? OR o.office_name LIKE ?)";
    $like   = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

// -----------------------
// P2: Summary cards (query ringan, tanpa JOIN/text filter)
// -----------------------
$smWhere  = "1=1";
$smParams = [];
if ($DO_SCOPE_OFFICE !== null) { $smWhere .= " AND d.office_code=?"; $smParams[] = $DO_SCOPE_OFFICE; }
elseif ($f_office !== '')      { $smWhere .= " AND d.office_code=?"; $smParams[] = $f_office; }

$summary = ['active' => 0, 'on_delivery' => 0, 'accounting' => 0, 'done_today' => 0, 'stuck' => 0, 'cancelled' => 0];
try {
    $stSm = $pdo->prepare("SELECT d.id, d.status, d.last_updated_at FROM sales_do d WHERE {$smWhere}");
    $stSm->execute($smParams);
    $smRows  = $stSm->fetchAll(PDO::FETCH_ASSOC);
    $stIsFullReturn = null;
    if ($sctFullReturnExcludeSql !== '') {
        $stIsFullReturn = $pdo->prepare("SELECT CASE WHEN
            COALESCE((SELECT SUM(ri.qty_return) FROM sales_do_return_items ri JOIN sales_do_returns rr ON rr.id=ri.return_id WHERE rr.do_id=? AND LOWER(COALESCE(rr.status,'')) IN ('return_scm_completed','return_completed','completed','closed')),0)
            >= COALESCE((SELECT SUM(di.qty) FROM sales_do_items di WHERE di.do_id=?),0)
            AND COALESCE((SELECT SUM(di2.qty) FROM sales_do_items di2 WHERE di2.do_id=?),0) > 0
            THEN 1 ELSE 0 END");
    }
    $today   = date('Y-m-d');
    $nowDt   = new DateTime();
    $STUCK_DAYS = 3;
    foreach ($smRows as $sr) {
        $s       = strtolower((string)($sr['status'] ?? ''));
        $isFinal = in_array($s, ['paid','paid_done','closed','fin_done'], true);

        if (in_array($s, ['revision_requested','crm_to_wqs','sent_wqs','wqs_processing','wqs_done','ready_scm'], true)) {
            $summary['active']++;
        } elseif ($s === 'on_delivery') {
            $isFullReturn = false;
            if ($stIsFullReturn instanceof PDOStatement) {
                try {
                    $doId = (int)($sr['id'] ?? 0);
                    $stIsFullReturn->execute([$doId,$doId,$doId]);
                    $isFullReturn = ((int)$stIsFullReturn->fetchColumn() === 1);
                    $stIsFullReturn->closeCursor();
                } catch (Throwable $e) {
                    $isFullReturn = false;
                }
            }
            if (!$isFullReturn) $summary['on_delivery']++;
        } elseif (in_array($s, ['delivered','scm_done','act_done','wait_payment'], true)) {
            $summary['accounting']++;
        } elseif ($s === 'cancelled') {
            $summary['cancelled']++;
        } elseif ($isFinal) {
            $lu = (string)($sr['last_updated_at'] ?? '');
            if ($lu !== '' && str_starts_with($lu, $today)) $summary['done_today']++;
        }

        // Stuck: non-final, tidak diupdate > STUCK_DAYS hari
        if (!$isFinal && $s !== 'cancelled' && !empty($sr['last_updated_at'])) {
            try {
                $luDt = new DateTime($sr['last_updated_at']);
                if ((int)$nowDt->diff($luDt)->days > $STUCK_DAYS) $summary['stuck']++;
            } catch (Throwable $e) {}
        }
    }
} catch (Throwable $e) {}

// -----------------------
// Main data query (P3: tambah crm_created_at / created_at)
// -----------------------
$rows = [];
try {
  $st = $pdo->prepare("SELECT
      d.id, d.do_code, d.tracking_code, d.do_date, d.status,
      d.office_code, o.office_name,
      d.customers_code, c.customers_name,
      d.grand_total,
      d.carrier_provider, d.carrier_tracking_no, d.tracking_public_token,
      d.tracking_last_sync_at, d.tracking_last_status, d.fallback_live_location_url,
      d.scm_live_lat, d.scm_live_lng, d.scm_live_accuracy_m, d.scm_live_at,
      d.wqs_ready_at, d.scm_delivered_at, d.act_ready_fin_at, d.fin_paid_at, d.fin_paid_date,
        {$paidDateSql} AS paid_date_effective,
        d.crm_created_at, d.created_at,
        d.last_updated_by, d.last_updated_at,
        COALESCE(d.category,'BMHP') AS category,
        {$sctBusinessGroupSql} AS business_group,
        d.return_status, d.return_last_at,
        CASE
          WHEN EXISTS (SELECT 1 FROM sales_do_items ca LEFT JOIN master_products cap ON cap.id=ca.product_id WHERE ca.do_id=d.id AND " . sct_item_category_sql('ca','cap') . "='ALKES')
           AND EXISTS (SELECT 1 FROM sales_do_items cb LEFT JOIN master_products cbp ON cbp.id=cb.product_id WHERE cb.do_id=d.id AND " . sct_item_category_sql('cb','cbp') . "='AKSESORIS') THEN 'ALKES + AKSESORIS'
          WHEN EXISTS (SELECT 1 FROM sales_do_items ca LEFT JOIN master_products cap ON cap.id=ca.product_id WHERE ca.do_id=d.id AND " . sct_item_category_sql('ca','cap') . "='ALKES') THEN 'ALKES'
          WHEN EXISTS (SELECT 1 FROM sales_do_items cb LEFT JOIN master_products cbp ON cbp.id=cb.product_id WHERE cb.do_id=d.id AND " . sct_item_category_sql('cb','cbp') . "='AKSESORIS') THEN 'AKSESORIS'
          ELSE 'BMHP'
        END AS item_categories
    FROM sales_do d
    LEFT JOIN master_customers c ON c.customers_code = d.customers_code
    LEFT JOIN master_office o ON o.office_code = d.office_code
    WHERE {$where}
    ORDER BY d.id DESC
    LIMIT 500");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rows = []; }

if (!empty($rows)) {
  foreach ($rows as &$rowRef) {
    $token = trim((string)($rowRef['tracking_public_token'] ?? ''));
    if ($token === '') {
      try {
        $token = $trackingSvc->ensurePublicToken($pdo, (int)$rowRef['id']);
        $rowRef['tracking_public_token'] = $token;
            } catch (Throwable $e) {}
    }
  }
  unset($rowRef);
}

// -----------------------
// UI helpers
// -----------------------
function chip_role(string $role): string {
  $role = strtoupper(trim($role));
    if ($role === '') $role = '-';
    $cls = match($role) {
        'CRM'  => 'crm', 'WQS' => 'wqs', 'SCM' => 'scm',
        'ACT'  => 'act', 'FIN' => 'fin', 'PAID' => 'fin',
        'WAIT' => 'wait', default => 'done'
    };
    return "<span class='chip {$cls}'>" . h($role) . "</span>";
}

function fmt_money($n): string { return number_format((float)$n, 2, '.', ','); }
function sct_live_state(?string $at): array {
    $at = trim((string)$at);
    if ($at === '') return ['OFFLINE', 'gray', null, 'Belum ada GPS'];
    try {
        $ts = new DateTime($at);
        $now = new DateTime();
        $age = max(0, $now->getTimestamp() - $ts->getTimestamp());
        if ($age < 120) return ['LIVE', 'green', $age, '< 2 menit'];
        if ($age < 300) return ['DELAY', 'yellow', $age, '2-5 menit'];
        return ['OFFLINE', 'red', $age, '> 5 menit'];
    } catch (Throwable $e) {
        return ['OFFLINE', 'gray', null, 'Waktu GPS tidak valid'];
    }
}

function sct_map_url($lat, $lng): string {
    if ($lat === null || $lng === null || $lat === '' || $lng === '') return '';
    if (!is_numeric($lat) || !is_numeric($lng)) return '';
    return 'https://www.google.com/maps?q=' . rawurlencode((string)$lat . ',' . (string)$lng);
}


function compute_next(string $status): array {
  $s = strtolower(trim($status));
    return match(true) {
        in_array($s, ['paid','paid_done','closed'], true) => ['DONE', 'Sudah PAID / closed.'],
        $s === 'cancelled'                                 => ['DONE', 'DO cancelled.'],
        $s === 'revision_requested'                         => ['CRM',  'Revisi DO lalu kirim ulang ke WQS.'],
        in_array($s, ['crm_to_wqs','sent_wqs'], true)     => ['WQS',  'Mulai proses (cek stok & siapkan barang).'],
        $s === 'wqs_processing'                            => ['WQS',  'Upload stok before/after lalu set READY SCM.'],
        in_array($s, ['ready_scm','wqs_done'], true)       => ['SCM',  'Atur pengiriman → set ON DELIVERY / SCM DONE.'],
        $s === 'on_delivery'                               => ['SCM',  'Konfirmasi DELIVERED + bukti terima.'],
        in_array($s, ['delivered','scm_done'], true)       => ['ACT',  'Tukar faktur pajak → WAIT PAYMENT / ACT DONE.'],
        in_array($s, ['wait_payment','act_done'], true)    => ['FIN',  'Proses pembayaran → set PAID / FIN DONE.'],
        $s === 'fin_done'                                  => ['DONE', 'Selesai di FIN.'],
        default                                            => ['WAIT', 'Status tidak dikenali: ' . strtoupper($s)],
    };
}

// P2: Hari sejak last_updated_at
function sct_days_since(string $dt): int {
    if ($dt === '') return 0;
    try { return (int)(new DateTime())->diff(new DateTime($dt))->days; }
    catch (Throwable $e) { return 0; }
}

// P2: Milestone progress bar horizontal (CRM → WQS → SCM → ACT → FIN)
function sct_milestone_bar(string $status, bool $wqsOk, bool $scmOk, bool $actOk, bool $finOk): string {
    $s           = strtolower(trim($status));
    $isCancelled = $s === 'cancelled';
    $steps = [
        ['CRM', $s !== 'revision_requested', 'crm'],
        ['WQS', $wqsOk, 'wqs'],
        ['SCM', $scmOk, 'scm'],
        ['ACT', $actOk, 'act'],
        ['FIN', $finOk, 'fin'],
    ];
    // Determine current active step index
    $currentStep = match(true) {
        in_array($s, ['paid','paid_done','closed','fin_done'], true)     => 5,
        in_array($s, ['wait_payment','act_done'], true)                  => 4,
        in_array($s, ['delivered','scm_done'], true)                     => 3,
        in_array($s, ['on_delivery','ready_scm','wqs_done'], true)       => 2,
        in_array($s, ['wqs_processing','sent_wqs'], true)                => 1,
        default                                                          => 0,
    };

    $out = '<div class="ms-bar">';
    foreach ($steps as $i => [$label, $done, $cls]) {
        $isActive = ($i === $currentStep && !$isCancelled && !$done);
        if ($isCancelled) {
            $stepCls = 'ms-step cancelled';
            $icon    = '✕';
        } elseif ($done) {
            $stepCls = "ms-step done {$cls}";
            $icon    = rmi_icon('tick');
        } elseif ($isActive) {
            $stepCls = "ms-step active {$cls}";
            $icon    = '●';
        } else {
            $stepCls = 'ms-step pending';
            $icon    = '○';
        }
        $out .= "<span class='{$stepCls}'><span class='ms-icon'>{$icon}</span><span class='ms-lbl'>" . h($label) . "</span></span>";
        if ($i < 4) {
            $lineDone = ($done && isset($steps[$i + 1]) && $steps[$i + 1][1]);
            $out .= "<span class='ms-line" . ($lineDone ? " done" : "") . ($isCancelled ? " cancelled" : "") . "'></span>";
        }
    }
    $out .= '</div>';
    return $out;
}

// -----------------------
// Render
// -----------------------
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

// Selaras sidebar nav key `sales` (_shared/rmi_layout.php): hanya SALES.VIEW — bukan DASHBOARD.SALES_VIEW
// (tanpa ini, staff cabang yang punya DASHBOARD.SALES_VIEW saja tetap melihat tombol padahal menu kiri disembunyikan).
$sctCanSalesDashboard = function_exists('can') && can('SALES.VIEW');
$sctCanMasterHub      = function_exists('can_any') && can_any(['MASTER.VIEW', 'MASTER.ADMIN_CENTER']);

$sctBreadcrumbs = [
    ['label' => 'Sales (CRM)', 'url' => $sctCanSalesDashboard ? ($baseProject . '/sales/sales_dashboard.php') : ''],
    'Control Tower',
];
$sctActions = [
    ['label' => rmi_icon('books') . ' Panduan', 'url' => $baseProject . '/sales/panduan_control_tower.php', 'class' => 'btn btn-sm btn-outline-light'],
];
if ($sctCanSalesDashboard) {
    $sctActions[] = ['label' => 'Sales Dashboard', 'url' => $baseProject . '/sales/sales_dashboard.php', 'class' => 'btn btn-sm btn-outline-light'];
}
if ($sctCanMasterHub) {
    $sctActions[] = ['label' => 'Master Data Center', 'url' => $baseProject . '/master/master_data.php', 'class' => 'btn btn-sm btn-outline-light'];
}

$extraHead = '<link rel="stylesheet" href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/jquery.dataTables.min.css?v=20260209">'
    . '<link rel="stylesheet" href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.dataTables.min.css?v=20260209">'
    . '<style>
    body{background:radial-gradient(1200px 800px at 20% 10%,#1f2937 0%,#0b1220 55%,#050814 100%);color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
    .muted{color:#9ca3af;font-size:12px}
    .form-control,.form-select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.14)!important}
    .btn-soft{border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e5e7eb}
    .btn-soft:hover{background:rgba(255,255,255,.10);color:#fff}
    table.dataTable thead th,table.dataTable tbody td{color:#e5e7eb}
    .dt-buttons .btn,.dataTables_wrapper .dt-buttons button{border-radius:10px!important;border:1px solid rgba(255,255,255,.15)!important;background:rgba(255,255,255,.08)!important;color:#e5e7eb!important;padding:6px 10px!important;font-size:12px!important}
    .dataTables_wrapper .dataTables_filter input,.dataTables_wrapper .dataTables_length select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.12)!important;border-radius:10px!important}
    .num{text-align:right}
    .actions a{margin:2px 4px 2px 0;display:inline-block;white-space:nowrap}
    .actions form{display:inline-block;margin:2px 4px 2px 0}
    .chip{display:inline-flex;gap:4px;align-items:center;padding:3px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);font-size:11px;font-weight:600}
    .chip.crm{border-color:rgba(59,130,246,.5);color:#93c5fd}
    .chip.wqs{border-color:rgba(239,68,68,.5);color:#fca5a5}
    .chip.scm{border-color:rgba(245,158,11,.5);color:#fcd34d}
    .chip.act{border-color:rgba(16,185,129,.5);color:#6ee7b7}
    .chip.fin{border-color:rgba(168,85,247,.5);color:#d8b4fe}
    .chip.wait{border-color:rgba(156,163,175,.5)}
    .chip.done{border-color:rgba(156,163,175,.3);color:#9ca3af}

    /* Summary cards */
    .sum-cards{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}
    .sum-card{flex:1;min-width:130px;padding:14px 16px;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);text-align:center}
    .sum-card .sc-num{font-size:28px;font-weight:700;line-height:1.1}
    .sum-card .sc-lbl{font-size:11px;color:#9ca3af;margin-top:4px}
    .sum-card.blue .sc-num{color:#60a5fa}
    .sum-card.blue{border-color:rgba(59,130,246,.25);background:rgba(59,130,246,.07)}
    .sum-card.yellow .sc-num{color:#fbbf24}
    .sum-card.yellow{border-color:rgba(245,158,11,.25);background:rgba(245,158,11,.06)}
    .sum-card.purple .sc-num{color:#c084fc}
    .sum-card.purple{border-color:rgba(168,85,247,.25);background:rgba(168,85,247,.06)}
    .sum-card.green .sc-num{color:#34d399}
    .sum-card.green{border-color:rgba(16,185,129,.25);background:rgba(16,185,129,.06)}
    .sum-card.red .sc-num{color:#f87171}
    .sum-card.red{border-color:rgba(239,68,68,.25);background:rgba(239,68,68,.06)}
    .sum-card.gray .sc-num{color:#9ca3af}
    .sum-card.gray{border-color:rgba(156,163,175,.2);background:rgba(156,163,175,.04)}

    /* Milestone bar */
    .ms-bar{display:flex;align-items:center;gap:0;white-space:nowrap;margin:2px 0}
    .ms-step{display:inline-flex;flex-direction:column;align-items:center;gap:1px;min-width:32px}
    .ms-icon{font-size:11px;line-height:1}
    .ms-lbl{font-size:9px;color:#6b7280;line-height:1}
    .ms-line{flex:1;height:2px;width:14px;background:rgba(255,255,255,.12);display:inline-block;margin-bottom:8px}
    .ms-line.done{background:rgba(16,185,129,.55)}
    .ms-line.cancelled{background:rgba(239,68,68,.3)}
    .ms-step.done .ms-icon{color:#34d399}
    .ms-step.done .ms-lbl{color:#34d399}
    .ms-step.active .ms-icon{color:#fbbf24}
    .ms-step.active .ms-lbl{color:#fbbf24}
    .ms-step.pending .ms-icon{color:#374151}
    .ms-step.cancelled .ms-icon{color:#f87171}
    .ms-step.crm.active .ms-icon{color:#60a5fa}
    .ms-step.wqs.active .ms-icon{color:#f87171}
    .ms-step.scm.active .ms-icon{color:#fbbf24}
    .ms-step.act.active .ms-icon{color:#34d399}
    .ms-step.fin.active .ms-icon{color:#c084fc}

    /* Stuck badge */
    .badge-stuck{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:600;border:1px solid rgba(239,68,68,.4);background:rgba(239,68,68,.12);color:#fca5a5}
    .badge-ontime{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:999px;font-size:10px;border:1px solid rgba(255,255,255,.1);background:transparent;color:#6b7280}

    /* Date info */
    .date-row{display:flex;flex-direction:column;gap:2px}
    .date-row .dr-main{font-size:12px}
    .date-row .dr-sub{font-size:10px;color:#6b7280}

    /* Category badge */
    .cat-badge{display:inline-block;padding:1px 7px;border-radius:5px;font-size:10px;font-weight:700;letter-spacing:.04em}
    .cat-BMHP{background:rgba(59,130,246,.15);color:#93c5fd;border:1px solid rgba(59,130,246,.25)}
    .cat-ALKES{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.25)}
    .cat-AKSESORIS{background:rgba(245,158,11,.15);color:#fcd34d;border:1px solid rgba(245,158,11,.25)}
    .cat-UNIT_ACC{background:rgba(245,158,11,.15);color:#fde68a;border:1px solid rgba(245,158,11,.32)}
    .item-mix{font-size:9px;color:#94a3b8;margin-top:2px;line-height:1.25}
    .return-chip{display:inline-block;margin-top:3px;padding:2px 6px;border-radius:999px;font-size:9px;border:1px solid rgba(251,146,60,.35);background:rgba(251,146,60,.08);color:#fdba74}

    /* Compact status badge */
    .st-badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
    .st-crm{background:rgba(59,130,246,.15);color:#93c5fd;border:1px solid rgba(59,130,246,.2)}
    .st-wqs{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.2)}
    .st-scm{background:rgba(245,158,11,.15);color:#fcd34d;border:1px solid rgba(245,158,11,.2)}
    .st-act{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.2)}
    .st-fin{background:rgba(168,85,247,.15);color:#d8b4fe;border:1px solid rgba(168,85,247,.2)}
    .st-done{background:rgba(16,185,129,.1);color:#34d399;border:1px solid rgba(16,185,129,.15)}
    .st-cancel{background:rgba(239,68,68,.08);color:#f87171;border:1px solid rgba(239,68,68,.15)}
    .st-default{background:rgba(255,255,255,.06);color:#9ca3af;border:1px solid rgba(255,255,255,.1)}

    /* Compact action group */
    .act-group{display:flex;flex-wrap:wrap;gap:3px}
    .act-btn{display:inline-flex;align-items:center;gap:3px;padding:3px 7px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:#cbd5e1;font-size:11px;text-decoration:none;white-space:nowrap;cursor:pointer;line-height:1.3}
    .act-btn:hover{background:rgba(255,255,255,.09);color:#fff}
    .act-btn.primary{border-color:rgba(59,130,246,.35);background:rgba(59,130,246,.12)}
    .act-sep{width:100%;height:0;margin:1px 0}
    .live-panel{margin-bottom:18px}
    .live-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
    .live-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}
    .live-item{border:1px solid rgba(255,255,255,.09);background:rgba(255,255,255,.035);border-radius:12px;padding:12px}
    .live-title{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
    .gps-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 8px;border-radius:999px;font-size:10px;font-weight:700;border:1px solid rgba(255,255,255,.12)}
    .gps-green{color:#34d399;border-color:rgba(52,211,153,.35);background:rgba(16,185,129,.10)}
    .gps-yellow{color:#fbbf24;border-color:rgba(251,191,36,.35);background:rgba(245,158,11,.10)}
    .gps-red{color:#f87171;border-color:rgba(248,113,113,.35);background:rgba(239,68,68,.10)}
    .gps-gray{color:#9ca3af;border-color:rgba(156,163,175,.25);background:rgba(156,163,175,.06)}
    .live-meta{font-size:11px;color:#9ca3af;line-height:1.55;margin-top:7px}
    .live-empty{padding:18px;text-align:center;color:#9ca3af;border:1px dashed rgba(255,255,255,.10);border-radius:12px}
  </style>';

rmi_header('Sales Control Tower', 'sales', [
    'subtitle'    => 'Monitoring CRM → WQS → SCM → ACT → FIN.',
    'breadcrumbs' => $sctBreadcrumbs,
    'actions'     => $sctActions,
    'extra_head'  => $extraHead,
]);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted">Sales • Control</div>
    <h3 class="mb-0">Sales Control Tower</h3>
    <div class="muted">1 layar untuk memantau: CRM → WQS → SCM → ACT → FIN → PAID</div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success py-2 mb-3"><?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger  py-2 mb-3"><?= h($error)   ?></div><?php endif; ?>

<!-- P2: Summary cards -->
<div class="sum-cards">
  <div class="sum-card blue">
    <div class="sc-num"><?= $summary['active'] ?></div>
    <div class="sc-lbl">🔵 Aktif (CRM–WQS)</div>
  </div>
  <div class="sum-card yellow">
    <div class="sc-num"><?= $summary['on_delivery'] ?></div>
    <div class="sc-lbl">🚚 On Delivery</div>
  </div>
  <div class="sum-card purple">
    <div class="sc-num"><?= $summary['accounting'] ?></div>
    <div class="sc-lbl">🟣 Menunggu ACT/FIN</div>
  </div>
  <div class="sum-card green">
    <div class="sc-num"><?= $summary['done_today'] ?></div>
    <div class="sc-lbl"><?= rmi_icon('check') ?> Done Hari Ini</div>
  </div>
  <div class="sum-card red">
    <div class="sc-num"><?= $summary['stuck'] ?></div>
    <div class="sc-lbl"><?= rmi_icon('warn') ?> Macet &gt;3 hari</div>
  </div>
  <div class="sum-card gray">
    <div class="sc-num"><?= $summary['cancelled'] ?></div>
    <div class="sc-lbl">Cancelled</div>
  </div>
</div>

<!-- Live SCM Tracking: read-only. Tidak mengubah status DO. -->
<div class="card live-panel">
  <div class="card-body">
    <div class="live-head">
      <div>
        <div class="fw-semibold">📍 Live SCM Tracking</div>
        <div class="muted">Hanya DO <b>ON DELIVERY</b>. Posisi dibaca dari GPS SCM Mobile Tracker; refresh otomatis 30 detik.</div>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <span id="liveServerTime" class="muted">-</span>
        <button type="button" class="btn btn-soft btn-sm" id="btnRefreshLive">↻ Refresh GPS</button>
      </div>
    </div>
    <div id="liveGrid" class="live-grid">
      <div class="live-empty">Memuat posisi GPS...</div>
    </div>
  </div>
</div>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2" method="get">
      <div class="col-md-2">
        <label class="form-label muted">Office</label>
        <select class="form-select form-select-sm" name="office_code">
          <option value="">-- all --</option>
          <?php foreach ($offices as $o): ?>
            <option value="<?= h($o['office_code']) ?>" <?= $f_office === $o['office_code'] ? 'selected' : '' ?>><?= h($o['office_name'] . ' (' . $o['office_code'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label muted">Status</label>
        <select class="form-select form-select-sm" name="status">
          <option value="">-- all --</option>
          <?php foreach ($statusOptions as $s): ?>
            <option value="<?= h($s) ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= h(strtoupper($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label muted">PAID</label>
        <select class="form-select form-select-sm" name="paid">
          <option value="">-- all --</option>
          <option value="PAID" <?= $f_paid==='PAID'?'selected':'' ?>>PAID / LUNAS</option>
          <option value="UNPAID" <?= $f_paid==='UNPAID'?'selected':'' ?>>UNPAID / BELUM LUNAS</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label muted">PAID dari tanggal</label>
        <input type="date" class="form-control form-control-sm" name="paid_from" value="<?= h($f_paid_from) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label muted">PAID s.d. tanggal</label>
        <input type="date" class="form-control form-control-sm" name="paid_to" value="<?= h($f_paid_to) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label muted">Kelompok DO</label>
        <select class="form-select form-select-sm" name="business_group">
          <option value="">-- semua --</option>
          <option value="BMHP" <?= $f_business_group==='BMHP'?'selected':'' ?>>🩺 BMHP</option>
          <option value="UNIT_ACC" <?= $f_business_group==='UNIT_ACC'?'selected':'' ?>>🧩 UNIT ACC</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label muted">Kategori Item</label>
        <select class="form-select form-select-sm" name="item_category">
          <option value="">-- semua --</option>
          <option value="BMHP" <?= $f_item_category==='BMHP'?'selected':'' ?>>BMHP</option>
          <option value="ALKES" <?= $f_item_category==='ALKES'?'selected':'' ?>>⚕️ ALKES</option>
          <option value="AKSESORIS" <?= $f_item_category==='AKSESORIS'?'selected':'' ?>>🔌 AKSESORIS</option>
        </select>
      </div>
      <!-- P3: Date range filter -->
      <div class="col-md-2">
        <label class="form-label muted">DO Date From</label>
        <input type="date" class="form-control form-control-sm" name="date_from" value="<?= h($f_date_from) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label muted">DO Date To</label>
        <input type="date" class="form-control form-control-sm" name="date_to" value="<?= h($f_date_to) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label muted">Search</label>
        <input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="DO code / tracking / customer / office">
      </div>
      <div class="col-md-1 d-flex align-items-end gap-2 flex-wrap">
        <button class="btn btn-primary btn-sm w-100">Filter</button>
        <a class="btn btn-soft btn-sm w-100" href="sales_control_tower.php">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">DO List <?php if (count($rows) >= 500): ?><span class="muted">(menampilkan maks 500 baris — gunakan filter untuk mempersempit)</span><?php endif; ?></div>

    <?php if (count($rows) === 0): ?>
      <div class="alert alert-warning" style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.25);color:var(--rmi-text)">
        Tidak ada DO yang cocok dengan filter saat ini.
        <div class="muted" style="color:#fde68a">Tips: set <b>Status</b> ke <b>-- all --</b> atau buat DO baru di <code>sales/sales_do.php</code>.</div>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <th>DO</th>
            <th>Customer / Office</th>
            <th>Status &amp; PIC</th>
            <th>Milestone</th>
            <th>Tracking</th>
            <th class="num">Amount</th>
            <th>Tanggal</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $id = (int)$r['id'];
          $st = strtolower((string)($r['status'] ?? ''));

            // Milestones
            $wqsOk = in_array($st, ['ready_scm','on_delivery','delivered','wait_payment','paid','closed','wqs_done','scm_done','act_done','fin_done','paid_done'], true) || !empty($r['wqs_ready_at']);
            $scmOk = in_array($st, ['delivered','wait_payment','paid','closed','scm_done','act_done','fin_done','paid_done'], true) || !empty($r['scm_delivered_at']);
            $actOk = in_array($st, ['wait_payment','paid','closed','act_done','fin_done','paid_done'], true) || !empty($r['act_ready_fin_at']);
            $finOk = in_array($st, ['paid','closed','fin_done','paid_done'], true) || !empty($r['fin_paid_at']);

          [$nextRole, $nextAction] = compute_next($st);

            // P2: hari macet
            $isFinalSt   = in_array($st, ['paid','paid_done','closed','fin_done','cancelled'], true);
            $lastUpdated = (string)($r['last_updated_at'] ?? '');
            $daysSince   = $lastUpdated !== '' ? sct_days_since($lastUpdated) : 0;
            $isStuck     = !$isFinalSt && $daysSince > 3;

            // P3: tanggal DO + created_at
            $doDate   = (string)($r['do_date'] ?? '');
            $createdAt = (string)($r['crm_created_at'] ?? $r['created_at'] ?? '');

            $token           = trim((string)($r['tracking_public_token'] ?? ''));
            $trackingPubUrl  = sct_tracking_public_url($token);
            $waShareUrl      = sct_wa_share_url((string)$r['do_code'], $trackingPubUrl);
            $provider        = strtoupper((string)($r['carrier_provider'] ?? 'BITESHIP'));
          if (!in_array($provider, ['BITESHIP','MANUAL'], true)) $provider = 'BITESHIP';
        ?>
          <?php
            // Status badge color class
            $stClass = match(true) {
                in_array($st, ['revision_requested','crm_to_wqs','sent_wqs'], true)             => 'st-crm',
                in_array($st, ['wqs_processing','wqs_done'], true)                      => 'st-wqs',
                in_array($st, ['ready_scm','on_delivery','scm_done'], true)             => 'st-scm',
                in_array($st, ['delivered','act_done'], true)                           => 'st-act',
                in_array($st, ['wait_payment','fin_done'], true)                        => 'st-fin',
                in_array($st, ['paid','paid_done','closed'], true)                      => 'st-done',
                $st === 'cancelled'                                                     => 'st-cancel',
                default                                                                 => 'st-default',
            };
        ?>
          <tr>
            <!-- DO: code + category badge + date + stuck badge -->
            <?php
              $docGroup = strtoupper(trim((string)($r['business_group'] ?? 'BMHP')));
              if (!in_array($docGroup, ['BMHP','UNIT_ACC'], true)) $docGroup='BMHP';
              $catIcon = $docGroup === 'UNIT_ACC' ? '🧩' : '🩺';
              $itemMix = strtoupper(trim((string)($r['item_categories'] ?? '')));
            ?>
            <td>
              <div><b><a href="sales_do_view.php?id=<?= $id ?>" style="color:inherit;text-decoration:none"><?= h($r['do_code']) ?></a></b></div>
              <div style="margin-top:2px"><span class="cat-badge cat-<?= h($docGroup) ?>"><?= $catIcon ?> <?= h($docGroup === 'UNIT_ACC' ? 'UNIT ACC' : 'BMHP') ?></span></div>
              <div class="item-mix">Item: <?= h($itemMix !== '' ? $itemMix : ($docGroup==='BMHP'?'BMHP':'-')) ?></div>
              <div class="muted" style="font-size:10px"><?= h($doDate) ?> · <?= h($r['tracking_code'] ?? '-') ?></div>
              <?php if ($isStuck): ?>
                <div style="margin-top:3px"><span class="badge-stuck"><?= rmi_icon('warn') ?> <?= $daysSince ?>h</span></div>
              <?php elseif ($lastUpdated !== ''): ?>
                <div style="margin-top:3px"><span class="badge-ontime"><?= $daysSince ?>h lalu</span></div>
              <?php endif; ?>
            </td>

            <!-- Customer + Office (merged) -->
            <td>
              <div style="font-size:12px;font-weight:600"><?= h($r['customers_name'] ?? $r['customers_code']) ?></div>
              <div class="muted" style="font-size:10px"><?= h($r['customers_code']) ?> · <?= h($r['office_code']) ?></div>
            </td>

            <!-- Status + Next PIC (merged, no duplicate badge) -->
            <td>
              <div><span class="st-badge <?= $stClass ?>"><?= h(str_replace('_',' ', strtoupper($st))) ?></span></div>
              <?php if (!empty($r['return_status'])): ?><div><span class="return-chip">RETUR: <?= h(str_replace('_',' ', strtoupper((string)$r['return_status']))) ?></span></div><?php endif; ?>
              <div style="margin-top:4px;display:flex;align-items:center;gap:4px">
                <?= chip_role($nextRole) ?>
              </div>
              <div class="muted" style="font-size:10px;margin-top:2px;max-width:160px;line-height:1.3"><?= h($nextAction) ?></div>
            </td>

            <!-- Milestone progress bar -->
            <td><?= sct_milestone_bar($st, $wqsOk, $scmOk, $actOk, $finOk) ?></td>

            <!-- Tracking: compact -->
            <td>
              <div style="font-size:11px"><?= h($provider) ?></div>
              <div class="muted" style="font-size:10px"><?= h($r['carrier_tracking_no'] ?: '—') ?></div>
              <?php if ($r['tracking_last_status'] ?? ''): ?>
                <div class="muted" style="font-size:10px"><?= h($r['tracking_last_status']) ?></div>
              <?php endif; ?>
              <?php if ($trackingPubUrl !== ''): ?>
                <div style="margin-top:2px"><a class="muted" style="font-size:10px" href="<?= h($trackingPubUrl) ?>" target="_blank" rel="noopener">🔗 link publik</a></div>
              <?php endif; ?>
              <?php if (!empty($r['fallback_live_location_url'])): ?>
                <div><a class="muted" style="font-size:10px" href="<?= h($r['fallback_live_location_url']) ?>" target="_blank" rel="noopener">📍 map</a></div>
              <?php endif; ?>
            </td>

            <!-- Amount -->
            <td class="num" style="font-size:12px"><?= h(fmt_money($r['grand_total'] ?? 0)) ?></td>

            <!-- Tanggal: compact -->
            <td>
              <div style="font-size:12px"><?= rmi_icon('calendar') ?> <?= h($doDate) ?></div>
              <?php
                $paidDateDisplay = '';
                if (!empty($r['paid_date_effective'])) $paidDateDisplay = substr((string)$r['paid_date_effective'], 0, 10);
                elseif (!empty($r['fin_paid_at'])) $paidDateDisplay = substr((string)$r['fin_paid_at'], 0, 10);
                elseif (!empty($r['fin_paid_date'])) $paidDateDisplay = substr((string)$r['fin_paid_date'], 0, 10);
              ?>
              <?php if ($paidDateDisplay !== ''): ?>
                <div class="muted" style="font-size:10px;color:#a7f3d0"><?= rmi_icon('money') ?> PAID: <?= h($paidDateDisplay) ?></div>
              <?php endif; ?>
              <?php if ($lastUpdated !== ''): ?>
                <div class="muted" style="font-size:10px"><?= h(substr($lastUpdated, 0, 16)) ?></div>
                <?php if ($r['last_updated_by'] ?? ''): ?>
                  <?php $__lub = trim((string)$r['last_updated_by']); $__dept = ['WQS','SCM','CRM','FIN','ACT','PQP','MPR','HRL','ITC','SYS','ADMIN','SUPERADMIN','MANAGER','STAFF','BRANCH','SYSTEM']; ?>
                  <?php if ($__lub !== '' && !in_array(strtoupper($__lub), $__dept, true)): ?>
                  <div class="muted" style="font-size:10px">oleh <?= h($__lub) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              <?php endif; ?>
            </td>

            <!-- Aksi: kompak, grouped -->
            <td>
              <div class="act-group">
                <a class="act-btn primary" href="sales_do_view.php?id=<?= $id ?>"><?= rmi_icon('search') ?> Open</a>
                <a class="act-btn" href="sales_do.php?edit=<?= $id ?>">CRM</a>
                <div class="act-sep"></div>
                <a class="act-btn" href="../stock/wqs_do_tasks.php?focus=<?= $id ?>">WQS</a>
                <a class="act-btn" href="scm_do_tasks.php?focus=<?= $id ?>">SCM</a>
                <a class="act-btn" href="act_do_tasks.php?focus=<?= $id ?>">ACT</a>
                <a class="act-btn" href="fin_do_tasks.php?focus=<?= $id ?>">FIN</a>
                <div class="act-sep"></div>
                <?php if ($waShareUrl): ?>
                  <a class="act-btn" href="<?= h($waShareUrl) ?>" target="_blank" rel="noopener">💬</a>
                <?php endif; ?>
                <form method="post" style="display:contents">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="act-btn" name="action" value="refresh_tracking" type="submit" title="Refresh tracking">↻</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="mt-3 muted">
      Milestone progress bar: <b><?= rmi_icon('tick') ?> hijau</b> = selesai · <b>● aktif</b> = sedang di tahap ini · <b>○</b> = belum.
      Badge <span class="badge-stuck"><?= rmi_icon('warn') ?> X hari</span> muncul jika DO tidak diupdate lebih dari 3 hari (non-final).
    </div>
  </div>
</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script>
function escHtml(v){
  return String(v ?? '').replace(/[&<>'"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];
  });
}
function gpsBadgeClass(c){ return ['green','yellow','red','gray'].includes(c) ? 'gps-'+c : 'gps-gray'; }
function renderLive(items){
  const grid = document.getElementById('liveGrid');
  if (!grid) return;
  if (!items || !items.length){
    grid.innerHTML = '<div class="live-empty">Tidak ada DO ON DELIVERY pada scope/filter saat ini.</div>';
    return;
  }
  grid.innerHTML = items.map(function(x){
    const customer = (x.customers_name || x.customers_code || '-');
    const coord = (x.lat !== null && x.lng !== null) ? (Number(x.lat).toFixed(6)+', '+Number(x.lng).toFixed(6)) : 'Belum ada koordinat';
    const acc = x.accuracy_m !== null ? Math.round(Number(x.accuracy_m))+' m' : '-';
    const mapBtn = x.map_url ? '<a class="act-btn primary" target="_blank" rel="noopener" href="'+escHtml(x.map_url)+'">🗺 Buka Map</a>' : '<span class="act-btn" style="opacity:.55;cursor:default">Map belum tersedia</span>';
    return '<div class="live-item">'
      +'<div class="live-title"><div><b>'+escHtml(x.do_code)+'</b><div class="muted">'+escHtml(customer)+' · '+escHtml(x.office_code)+'</div></div>'
      +'<span class="gps-badge '+gpsBadgeClass(x.gps_color)+'">● '+escHtml(x.gps_state)+'</span></div>'
      +'<div class="live-meta">📍 '+escHtml(coord)+'<br><?= rmi_icon('target') ?> Accuracy: '+escHtml(acc)+'<br>🕒 Last GPS: '+escHtml(x.live_at || 'belum ada')+' ('+escHtml(x.age_label || '-')+')</div>'
      +'<div style="margin-top:9px;display:flex;gap:6px;flex-wrap:wrap">'+mapBtn+'<a class="act-btn" href="scm_do_tasks.php?focus='+encodeURIComponent(x.id)+'">SCM Task</a></div>'
      +'</div>';
  }).join('');
}
async function loadLive(){
  const grid = document.getElementById('liveGrid');
  try {
    const u = new URL(window.location.href);
    u.searchParams.set('ajax','live_positions');
    const res = await fetch(u.toString(), {cache:'no-store', headers:{'X-Requested-With':'XMLHttpRequest'}});
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch(e) { throw new Error('Response live tracking bukan JSON'); }
    if (!res.ok || !data.ok) throw new Error(data.error || 'Gagal membaca live tracking');
    renderLive(data.items || []);
    const st = document.getElementById('liveServerTime');
    if (st) st.textContent = 'Server: '+(data.server_time || '-');
  } catch(e) {
    if (grid) grid.innerHTML = '<div class="live-empty" style="color:#fca5a5">Live tracking gagal dimuat. DO utama tetap aman/tidak berubah.</div>';
  }
}

$(function(){
  $('#tbl').DataTable({
    pageLength: 25,
    order: [[0,'desc']],
    dom: 'Bfrtip',
    buttons: ['copy','csv','excel','print']
  });
  $('#btnRefreshLive').on('click', loadLive);
  loadLive();
  setInterval(loadLive, 30000);
});
</script>

<?php rmi_footer(); ?>
