<?php
require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../../_shared/helpers.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
if (function_exists('require_login')) { @require_login(); }

// WQS Manager dashboard scope:
// Mgr WQS harus dapat melihat nilai agregat seluruh WQS sama seperti SYS di dashboard WQS,
// tanpa mengubah RBAC/action pada modul operasional lain. Staff/Branch tetap mengikuti office scope.
$__wqsDashUser = function_exists('auth_user') ? (array)auth_user() : [];
$__wqsDashDept = strtoupper(trim((string)($__wqsDashUser['department'] ?? $__wqsDashUser['dept'] ?? ($_SESSION['department'] ?? $_SESSION['dept'] ?? ''))));
$__wqsDashRole = strtoupper(trim((string)($__wqsDashUser['role'] ?? ($_SESSION['role'] ?? ''))));
$__wqsDashLevel = strtoupper(trim((string)($__wqsDashUser['level'] ?? ($_SESSION['level'] ?? ''))));
$__wqsDashUsername = strtoupper(trim((string)($__wqsDashUser['username'] ?? ($_SESSION['username'] ?? ''))));
$__wqsDashIsManager = in_array($__wqsDashRole, ['MANAGER','MGR'], true)
    || in_array($__wqsDashLevel, ['MANAGER','MGR'], true)
    || str_starts_with($__wqsDashUsername, 'MGR');
$__wqsDashGlobalScope = ($__wqsDashDept === 'WQS' && $__wqsDashIsManager)
    || (strpos($__wqsDashUsername, 'MGRWQS') !== false);
$GLOBALS['WQS_DASHBOARD_GLOBAL_SCOPE'] = $__wqsDashGlobalScope;

$scopeCtx = function_exists('ds_scope_ctx') ? ds_scope_ctx() : ['is_admin' => true, 'office_code' => ''];
if ($__wqsDashGlobalScope) {
    $scopeCtx['is_admin'] = true;
    $scopeCtx['office_code'] = '';
}
$scopeOffice       = (string)($scopeCtx['office_code'] ?? '');
$scopeOfficeFilter = (!$scopeCtx['is_admin'] && $scopeOffice !== '');

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('normalize_code')) {
    function normalize_code(string $s): string { return strtoupper(preg_replace('/[^A-Z0-9_\-]/i', '', $s)); }
}

$pdo          = $GLOBALS['pdo'] ?? null;
$BASE_PROJECT = $GLOBALS['BASE_PROJECT'] ?? '';

function u($path) {
    $bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
    return rtrim($bp, '/') . '/' . ltrim($path, '/');
}
function wqs_money(float $v): string {
    if ($v >= 1e9)  return 'Rp ' . number_format($v / 1e9, 1, ',', '.') . 'M';
    if ($v >= 1e6)  return 'Rp ' . number_format($v / 1e6, 1, ',', '.') . 'jt';
    if ($v >= 1e3)  return 'Rp ' . number_format($v / 1e3, 0, ',', '.') . 'rb';
    return 'Rp ' . number_format($v, 0, ',', '.');
}
function wqs_duration_label(float $minutes): string {
    if ($minutes <= 0) return '0 m';
    $mins = (int)round($minutes);
    $days = intdiv($mins, 1440);
    $mins %= 1440;
    $hours = intdiv($mins, 60);
    $mins %= 60;
    if ($days > 0) return $days . ' h ' . $hours . ' j';
    if ($hours > 0) return $hours . ' j ' . $mins . ' m';
    return $mins . ' m';
}

// ── Helpers ───────────────────────────────────────────────────────────────
function t_exists($pdo, string $t): bool {
    if (!$pdo) return false;
    if (function_exists('kpi_table_exists')) return kpi_table_exists($pdo, $t);
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch (Throwable $e) { return false; }
}
function wqs_scalar($pdo, string $sql, array $params = []) {
    if (!$pdo) return 0;
    try {
        $st = $pdo->prepare($sql); $st->execute($params);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? 0 : $v;
    } catch (Throwable $e) { return 0; }
}

function wqs_cols($pdo, string $table): array {
    if (!$pdo || !t_exists($pdo, $table)) return [];
    $out = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`") as $r) {
            $f = (string)($r['Field'] ?? '');
            if ($f !== '') $out[$f] = true;
        }
    } catch (Throwable $e) {}
    return $out;
}
function wqs_pick_col(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) if (isset($cols[$c])) return $c;
    return null;
}
function wqs_office_clause(array $cols, string $alias = ''): array {
    // Mgr WQS mendapat aggregate ALL pada dashboard WQS, sama seperti SYS.
    // Ini hanya mempengaruhi query dashboard, bukan permission/action halaman operasional.
    if (!empty($GLOBALS['WQS_DASHBOARD_GLOBAL_SCOPE'])) return ['', []];
    $ctx = function_exists('ds_scope_ctx') ? ds_scope_ctx() : ['is_admin'=>true,'office_code'=>''];
    $office = strtoupper(trim((string)($ctx['office_code'] ?? '')));
    if (!empty($ctx['is_admin']) || $office === '') return ['', []];
    $officeCol = wqs_pick_col($cols, ['office_code','office','branch_code']);
    if ($officeCol === null) return ['', []];
    $prefix = $alias !== '' ? $alias . '.' : '';
    return [" AND UPPER(COALESCE({$prefix}`{$officeCol}`,''))=UPPER(?)", [$office]];
}

// ── Unified Period Filter ─────────────────────────────────────────────────
$rangePreset = trim((string)($_GET['range'] ?? '30'));
$dateFrom    = trim((string)($_GET['from'] ?? ''));
$dateTo      = trim((string)($_GET['to']   ?? ''));

if ($rangePreset === 'today') {
    $chartFrom = $chartTo = date('Y-m-d');
} elseif ($rangePreset === 'week') {
    $chartFrom = date('Y-m-d', strtotime('monday this week'));
    $chartTo   = date('Y-m-d');
} elseif ($rangePreset === '7') {
    $chartTo   = date('Y-m-d');
    $chartFrom = date('Y-m-d', strtotime('-7 days'));
} elseif ($rangePreset === '90') {
    $chartTo   = date('Y-m-d');
    $chartFrom = date('Y-m-d', strtotime('-90 days'));
} elseif ($rangePreset === 'month') {
    $chartFrom = date('Y-m-01');
    $chartTo   = date('Y-m-d');
} elseif ($rangePreset === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $chartFrom = $dateFrom; $chartTo = $dateTo;
    if (strtotime($chartFrom) > strtotime($chartTo)) { [$chartFrom, $chartTo] = [$chartTo, $chartFrom]; }
} else {
    $rangePreset = '30';
    $chartTo   = date('Y-m-d');
    $chartFrom = date('Y-m-d', strtotime('-30 days'));
}

// Period performance follows the selected dashboard range.
$mStart = $chartFrom;
$mEnd   = $chartTo;
$dtStart = $mStart . ' 00:00:00';
$dtEnd   = $mEnd   . ' 23:59:59';
$todayDate = date('Y-m-d');

// Legacy convenience filter. Queries below prefer schema-aware office clauses.
$oWhere  = $scopeOfficeFilter ? " AND UPPER(COALESCE(office_code,'')) = UPPER(?)" : '';
$oParam  = $scopeOfficeFilter ? [$scopeOffice] : [];
$oParam2 = $scopeOfficeFilter ? [$scopeOffice, $scopeOffice] : [];

// ── KPI Queries ───────────────────────────────────────────────────────────
$kpi = [
    'sku_onhand'        => 0,
    'qty_onhand'        => 0,
    'incoming_mtd'      => 0,
    'alloc_mtd'         => 0,
    'picking_mtd'       => 0,
    'unallocated_items' => 0,
    'unallocated_sku'   => 0,
    'unallocated_qty'   => 0.0,
    'exp_90'            => 0,
    'exp_expired'       => 0,
    'do_waiting'        => 0,
    'pr_open'           => 0,
    'low_stock_sku'     => 0,
    'quarantine_open'  => 0,
    'wqs_overdue'      => 0,
    'avg_wqs_minutes'  => 0.0,
    'avg_wqs_samples'  => 0,
    'picking_source'   => 'wqs_picking',
    'expiry_source'    => 'allocation_history',
];

if ($pdo) {
    // CURRENT INVENTORY SNAPSHOT. Prefer office-aware stock table when available.
    if (t_exists($pdo, 'wqs_stock_by_office')) {
        $cols = wqs_cols($pdo, 'wqs_stock_by_office');
        $qtyCol = wqs_pick_col($cols, ['qty','stock_qty','on_hand_qty']);
        $prodCol = wqs_pick_col($cols, ['product_id','sku']);
        [$ow,$op] = wqs_office_clause($cols);
        if ($qtyCol !== null) {
            // Snapshot harus konsisten per SKU: jumlahkan seluruh office dalam scope dahulu.
            // SKU on-hand hanya SKU dengan net stock > 0; qty on-hand juga hanya menjumlahkan net positif.
            if ($prodCol !== null) {
                $kpi['sku_onhand'] = (int)wqs_scalar($pdo, "SELECT COUNT(*) FROM (SELECT `{$prodCol}` product_key, SUM(COALESCE(`{$qtyCol}`,0)) total_stock FROM wqs_stock_by_office WHERE 1=1{$ow} GROUP BY `{$prodCol}` HAVING SUM(COALESCE(`{$qtyCol}`,0)) > 0) x", $op);
                $kpi['qty_onhand'] = (float)wqs_scalar($pdo, "SELECT COALESCE(SUM(total_stock),0) FROM (SELECT `{$prodCol}` product_key, SUM(COALESCE(`{$qtyCol}`,0)) total_stock FROM wqs_stock_by_office WHERE 1=1{$ow} GROUP BY `{$prodCol}` HAVING SUM(COALESCE(`{$qtyCol}`,0)) > 0) x", $op);
            } else {
                $kpi['sku_onhand'] = (int)wqs_scalar($pdo, "SELECT COUNT(*) FROM wqs_stock_by_office WHERE `{$qtyCol}` > 0{$ow}", $op);
                $kpi['qty_onhand'] = (float)wqs_scalar($pdo, "SELECT COALESCE(SUM(`{$qtyCol}`),0) FROM wqs_stock_by_office WHERE `{$qtyCol}` > 0{$ow}", $op);
            }
        }
    } elseif (t_exists($pdo, 'wqs_stock')) {
        $cols = wqs_cols($pdo, 'wqs_stock');
        [$ow,$op] = wqs_office_clause($cols);
        $kpi['sku_onhand'] = (int)wqs_scalar($pdo, "SELECT COUNT(*) FROM wqs_stock WHERE stock_qty > 0{$ow}", $op);
        $kpi['qty_onhand'] = (float)wqs_scalar($pdo, "SELECT COALESCE(SUM(stock_qty),0) FROM wqs_stock WHERE 1=1{$ow}", $op);
    }

    // Incoming MTD
    if (t_exists($pdo, 'wqs_incoming')) {
        $kpi['incoming_mtd'] = (int)wqs_scalar($pdo,
            "SELECT COUNT(*) FROM wqs_incoming WHERE received_date BETWEEN ? AND ?" . wqs_office_clause(wqs_cols($pdo, 'wqs_incoming'))[0],
            array_merge([$mStart, $mEnd], wqs_office_clause(wqs_cols($pdo, 'wqs_incoming'))[1]));
    }

    // Allocation count in selected period.
    if (t_exists($pdo, 'wqs_allocations')) {
        $ac = wqs_cols($pdo, 'wqs_allocations');
        [$aow,$aop] = wqs_office_clause($ac);
        $kpi['alloc_mtd'] = (int)wqs_scalar($pdo,
            "SELECT COUNT(*) FROM wqs_allocations WHERE allocated_at BETWEEN ? AND ?{$aow}",
            array_merge([$dtStart, $dtEnd], $aop));
    }

    // EXPIRY SNAPSHOT — source of truth:
    // wqs_incoming_items = lot/expiry/qty masuk
    // wqs_allocations    = qty yang sudah dialokasikan per incoming_item_id
    // Hanya lot dengan remaining qty > 0 yang dihitung.
    if (t_exists($pdo, 'wqs_incoming_items') && t_exists($pdo, 'wqs_allocations')) {
        $ic = wqs_cols($pdo, 'wqs_incoming_items');
        $ac = wqs_cols($pdo, 'wqs_allocations');

        if (isset($ic['id'], $ic['qty'], $ic['exp_date']) && isset($ac['incoming_item_id'], $ac['qty'])) {
            // Office scope:
            // - Mgr WQS/SYS = global, sesuai scope dashboard yang sudah ada.
            // - Staff/branch: bila incoming_items tidak punya office_code, scope diambil
            //   dari wqs_incoming melalui incoming_id. Tidak mengubah RBAC operasional.
            $expiryOfficeSql = '';
            $expiryOfficeParams = [];
            if (!$__wqsDashGlobalScope && $scopeOfficeFilter) {
                if (isset($ic['office_code'])) {
                    $expiryOfficeSql = " AND UPPER(COALESCE(i.office_code,'')) = UPPER(?)";
                    $expiryOfficeParams[] = $scopeOffice;
                } elseif (isset($ic['incoming_id']) && t_exists($pdo, 'wqs_incoming')) {
                    $wic = wqs_cols($pdo, 'wqs_incoming');
                    if (isset($wic['id'], $wic['office_code'])) {
                        $expiryOfficeSql = " AND EXISTS (
                            SELECT 1 FROM wqs_incoming wi
                            WHERE wi.id = i.incoming_id
                              AND UPPER(COALESCE(wi.office_code,'')) = UPPER(?)
                        )";
                        $expiryOfficeParams[] = $scopeOffice;
                    }
                }
            }

            $expiryBase = "
                FROM wqs_incoming_items i
                LEFT JOIN (
                    SELECT incoming_item_id, SUM(COALESCE(qty,0)) AS allocated_qty
                    FROM wqs_allocations
                    GROUP BY incoming_item_id
                ) a ON a.incoming_item_id = i.id
                WHERE i.exp_date IS NOT NULL
                  AND GREATEST(COALESCE(i.qty,0) - COALESCE(a.allocated_qty,0),0) > 0
                  {$expiryOfficeSql}
            ";

            $kpi['exp_90'] = (int)wqs_scalar(
                $pdo,
                "SELECT COUNT(*) {$expiryBase}
                 AND DATE(i.exp_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)",
                $expiryOfficeParams
            );
            $kpi['exp_expired'] = (int)wqs_scalar(
                $pdo,
                "SELECT COUNT(*) {$expiryBase}
                 AND DATE(i.exp_date) < CURDATE()",
                $expiryOfficeParams
            );
            $kpi['expiry_source'] = 'wqs_incoming_items_remaining';
        }
    }

    // WQS SELESAI in selected period.
    // Source-of-truth utama adalah sales_do: satu DO dihitung satu kali saat READY SCM / WQS selesai.
    // wqs_picking hanya fallback untuk instalasi/schema lama yang belum mempunyai timestamp workflow.
    if (t_exists($pdo, 'sales_do')) {
        $sdpc = wqs_cols($pdo, 'sales_do');
        $readyCol = wqs_pick_col($sdpc, ['wqs_ready_at','wqs_done_at','ready_scm_at']);
        $doCol = wqs_pick_col($sdpc, ['do_code','code']);
        if ($readyCol !== null) {
            [$pdow,$pdop] = wqs_office_clause($sdpc);
            $countExpr = $doCol !== null ? "COUNT(DISTINCT `{$doCol}`)" : 'COUNT(*)';
            $kpi['picking_mtd'] = (int)wqs_scalar($pdo,
                "SELECT {$countExpr} FROM sales_do WHERE `{$readyCol}` BETWEEN ? AND ?{$pdow}",
                array_merge([$dtStart,$dtEnd],$pdop));
            $kpi['picking_source'] = 'sales_do.' . $readyCol;
        }
    }
    // Fallback 1: audit workflow lama. Tetap DISTINCT DO agar tidak menghitung event ganda.
    if ((int)$kpi['picking_mtd'] === 0 && t_exists($pdo, 'sales_do_audit')) {
        $ac = wqs_cols($pdo, 'sales_do_audit');
        $timeCol = wqs_pick_col($ac, ['created_at','event_at','logged_at','updated_at']);
        $doCol = wqs_pick_col($ac, ['do_code','sales_do_code','code']);
        $stateCol = wqs_pick_col($ac, ['new_status','status','action','event']);
        if ($timeCol !== null && $doCol !== null && $stateCol !== null) {
            [$aow,$aop] = wqs_office_clause($ac);
            $sql = "SELECT COUNT(DISTINCT `{$doCol}`) FROM sales_do_audit WHERE `{$timeCol}` BETWEEN ? AND ? AND LOWER(COALESCE(`{$stateCol}`,'')) IN ('ready_scm','wqs_done','wqs_picked','scm_ready','wqs_ready'){$aow}";
            $kpi['picking_mtd'] = (int)wqs_scalar($pdo, $sql, array_merge([$dtStart,$dtEnd],$aop));
            if ($kpi['picking_mtd'] > 0) $kpi['picking_source'] = 'sales_do_audit';
        }
    }
    // Fallback 2: tabel picking. Hitung DISTINCT DO bila kolom DO tersedia; jangan COUNT(*) buta.
    if ((int)$kpi['picking_mtd'] === 0 && t_exists($pdo, 'wqs_picking')) {
        $pc = wqs_cols($pdo, 'wqs_picking'); [$pow,$pop] = wqs_office_clause($pc);
        $pickTimeCol = wqs_pick_col($pc, ['picked_at','created_at','updated_at']);
        $pickDoCol = wqs_pick_col($pc, ['do_code','sales_do_code','code']);
        if ($pickTimeCol !== null) {
            $pickCountExpr = $pickDoCol !== null ? "COUNT(DISTINCT `{$pickDoCol}`)" : 'COUNT(*)';
            $kpi['picking_mtd'] = (int)wqs_scalar($pdo,
                "SELECT {$pickCountExpr} FROM wqs_picking WHERE `{$pickTimeCol}` BETWEEN ? AND ?{$pow}",
                array_merge([$dtStart, $dtEnd], $pop));
            if ($kpi['picking_mtd'] > 0) $kpi['picking_source'] = 'wqs_picking';
        }
    }

    // Unallocated items — current outstanding allocation. Office filter applied when item table carries office.
    if (t_exists($pdo, 'wqs_incoming_items') && t_exists($pdo, 'wqs_allocations')) {
        $ic = wqs_cols($pdo, 'wqs_incoming_items'); [$iow,$iop] = wqs_office_clause($ic, 'i');
        // Pisahkan line, SKU dan qty supaya angka tidak ambigu.
        $iiProd = wqs_pick_col($ic, ['product_id','sku']);
        $baseUnalloc = "FROM (SELECT i.id" . ($iiProd !== null ? ", i.`{$iiProd}` AS product_key" : ", NULL AS product_key") . ", GREATEST(COALESCE(i.qty,0) - COALESCE(a.asum,0),0) AS remain FROM wqs_incoming_items i LEFT JOIN (SELECT incoming_item_id, SUM(qty) AS asum FROM wqs_allocations GROUP BY incoming_item_id) a ON a.incoming_item_id=i.id WHERE 1=1 {$iow}) x WHERE x.remain > 0";
        $kpi['unallocated_items'] = (int)wqs_scalar($pdo, "SELECT COUNT(*) {$baseUnalloc}", $iop);
        $kpi['unallocated_qty']   = (float)wqs_scalar($pdo, "SELECT COALESCE(SUM(remain),0) {$baseUnalloc}", $iop);
        if ($iiProd !== null) {
            $kpi['unallocated_sku'] = (int)wqs_scalar($pdo, "SELECT COUNT(DISTINCT product_key) {$baseUnalloc}", $iop);
        }
    }

    // NEW: DO Waiting WQS
    if (t_exists($pdo, 'sales_do')) {
        $wqsStatuses = ['crm_to_wqs', 'sent_wqs', 'wqs_processing'];
        $inPh = implode(',', array_fill(0, count($wqsStatuses), '?'));
        $sdCols = wqs_cols($pdo, 'sales_do');
        [$sdOw,$sdOp] = wqs_office_clause($sdCols);
        $kpi['do_waiting'] = (int)wqs_scalar($pdo,
            "SELECT COUNT(*) FROM sales_do WHERE LOWER(COALESCE(status,'')) IN ({$inPh}){$sdOw}",
            array_merge($wqsStatuses, $sdOp));

        // WQS SLA overdue: use stage/start timestamp when present, otherwise created/do date as conservative fallback.
        $tsCol = wqs_pick_col($sdCols, ['wqs_started_at','wqs_received_at','crm_sent_wqs_at','updated_at','created_at','do_date']);
        if ($tsCol !== null) {
            $slaMin = 240;
            if (t_exists($pdo, 'system_config')) {
                $cfg = wqs_scalar($pdo, "SELECT config_value FROM system_config WHERE config_key='SLA_WQS_MINUTES' LIMIT 1");
                if ((int)$cfg > 0) $slaMin = (int)$cfg;
            }
            $kpi['wqs_overdue'] = (int)wqs_scalar($pdo,
                "SELECT COUNT(*) FROM sales_do WHERE LOWER(COALESCE(status,'')) IN ({$inPh}){$sdOw} AND TIMESTAMPDIFF(MINUTE, `{$tsCol}`, NOW()) > ?",
                array_merge($wqsStatuses, $sdOp, [$slaMin]));
        }

        // AVG Durasi SLA WQS — hanya task WQS yang sudah selesai pada periode dashboard.
        // Start prioritas wqs_started_at; fallback created_at/do_date untuk data legacy.
        // Finish memakai wqs_ready_at agar durasi berhenti saat WQS selesai, bukan terus berjalan sampai FIN.
        $startCol = wqs_pick_col($sdCols, ['wqs_started_at','wqs_received_at','crm_sent_wqs_at','created_at','do_date']);
        $finishCol = wqs_pick_col($sdCols, ['wqs_ready_at']);
        if ($startCol !== null && $finishCol !== null) {
            $avgSql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, `{$startCol}`, `{$finishCol}`))
                       FROM sales_do
                       WHERE `{$startCol}` IS NOT NULL
                         AND `{$finishCol}` IS NOT NULL
                         AND `{$finishCol}` >= `{$startCol}`
                         AND `{$finishCol}` BETWEEN ? AND ?{$sdOw}";
            $sampleSql = "SELECT COUNT(*)
                          FROM sales_do
                          WHERE `{$startCol}` IS NOT NULL
                            AND `{$finishCol}` IS NOT NULL
                            AND `{$finishCol}` >= `{$startCol}`
                            AND `{$finishCol}` BETWEEN ? AND ?{$sdOw}";
            $avgParams = array_merge([$dtStart, $dtEnd], $sdOp);
            $kpi['avg_wqs_minutes'] = (float)wqs_scalar($pdo, $avgSql, $avgParams);
            $kpi['avg_wqs_samples'] = (int)wqs_scalar($pdo, $sampleSql, $avgParams);
        }
    }

    // PR Open = PR aktif yang benar-benar belum selesai.
    // IMPORTANT: wqs_pr.php memakai SOFT DELETE (deleted_at=NOW()) saat PR dihapus.
    // Karena itu deleted_at IS NULL wajib diterapkan; tanpa filter ini PR trial yang
    // sudah dihapus tetap terhitung sebagai DRAFT/SUBMITTED di dashboard.
    if (t_exists($pdo, 'wqs_pr')) {
        $prCols = wqs_cols($pdo, 'wqs_pr');
        [$prOw, $prOp] = wqs_office_clause($prCols);

        $prNotDeleted = isset($prCols['deleted_at'])
            ? " AND deleted_at IS NULL"
            : "";

        $kpi['pr_open'] = (int)wqs_scalar(
            $pdo,
            "SELECT COUNT(*)
             FROM wqs_pr
             WHERE UPPER(TRIM(COALESCE(status,''))) IN ('DRAFT','SUBMITTED')
               {$prNotDeleted}{$prOw}",
            $prOp
        );
    }

    // NEW: Karantina Open/Hold
    if (t_exists($pdo, 'wqs_stock_quarantine')) {
        $kpi['quarantine_open'] = (int)wqs_scalar($pdo,
            "SELECT COUNT(*) FROM wqs_stock_quarantine WHERE UPPER(COALESCE(quarantine_status,'')) IN ('OPEN','HOLD')" . $oWhere,
            $oParam);
    }

    // Low-stock operational count — current snapshot PER OFFICE + SKU.
    // Penting: kebutuhan WQS bersifat per lokasi. Stok besar di office lain tidak boleh
    // menutupi kondisi low-stock suatu office. Contoh: TGR 148, BGR 6 => BGR tetap Low Stock.
    // Stock 0 tidak masuk Low Stock (dipisahkan sebagai Out of Stock bila dibutuhkan).
    $lowThreshold = 10;
    if (t_exists($pdo, 'wqs_stock_by_office')) {
        $sc = wqs_cols($pdo, 'wqs_stock_by_office');
        $qtyCol    = wqs_pick_col($sc, ['qty','stock_qty','on_hand_qty']);
        $prodCol   = wqs_pick_col($sc, ['product_id','sku']);
        $officeCol = wqs_pick_col($sc, ['office_code','office','branch_code']);
        [$sow,$sop] = wqs_office_clause($sc);
        if ($qtyCol !== null && $prodCol !== null && $officeCol !== null) {
            $kpi['low_stock_sku'] = (int)wqs_scalar($pdo,
                "SELECT COUNT(*) FROM (
                    SELECT UPPER(COALESCE(`{$officeCol}`,'')) AS office_key,
                           `{$prodCol}` AS product_key,
                           SUM(COALESCE(`{$qtyCol}`,0)) AS office_stock
                    FROM wqs_stock_by_office
                    WHERE 1=1{$sow}
                    GROUP BY UPPER(COALESCE(`{$officeCol}`,'')), `{$prodCol}`
                    HAVING SUM(COALESCE(`{$qtyCol}`,0)) > 0
                       AND SUM(COALESCE(`{$qtyCol}`,0)) < ?
                ) low_office_sku",
                array_merge($sop, [$lowThreshold])
            );
        }
    } elseif (t_exists($pdo, 'wqs_stock')) {
        $sc = wqs_cols($pdo, 'wqs_stock');
        $qtyCol    = wqs_pick_col($sc, ['stock_qty','qty','on_hand_qty']);
        $prodCol   = wqs_pick_col($sc, ['product_id','sku']);
        $officeCol = wqs_pick_col($sc, ['office_code','office','branch_code']);
        [$sow,$sop] = wqs_office_clause($sc);
        if ($qtyCol !== null && $prodCol !== null && $officeCol !== null) {
            $kpi['low_stock_sku'] = (int)wqs_scalar($pdo,
                "SELECT COUNT(*) FROM (
                    SELECT UPPER(COALESCE(`{$officeCol}`,'')) AS office_key,
                           `{$prodCol}` AS product_key,
                           SUM(COALESCE(`{$qtyCol}`,0)) AS office_stock
                    FROM wqs_stock
                    WHERE 1=1{$sow}
                    GROUP BY UPPER(COALESCE(`{$officeCol}`,'')), `{$prodCol}`
                    HAVING SUM(COALESCE(`{$qtyCol}`,0)) > 0
                       AND SUM(COALESCE(`{$qtyCol}`,0)) < ?
                ) low_office_sku",
                array_merge($sop, [$lowThreshold])
            );
        }
    }

}

// ── Expiry Soon list (top 15) — same source/logic as KPI ─────────────────
$expSoon = [];
if ($pdo && t_exists($pdo, 'wqs_incoming_items') && t_exists($pdo, 'wqs_allocations')) {
    try {
        $ic = wqs_cols($pdo, 'wqs_incoming_items');
        $expiryOfficeSql = '';
        $expiryOfficeParams = [];

        if (!$__wqsDashGlobalScope && $scopeOfficeFilter) {
            if (isset($ic['office_code'])) {
                $expiryOfficeSql = " AND UPPER(COALESCE(i.office_code,'')) = UPPER(?)";
                $expiryOfficeParams[] = $scopeOffice;
            } elseif (isset($ic['incoming_id']) && t_exists($pdo, 'wqs_incoming')) {
                $wic = wqs_cols($pdo, 'wqs_incoming');
                if (isset($wic['id'], $wic['office_code'])) {
                    $expiryOfficeSql = " AND EXISTS (
                        SELECT 1 FROM wqs_incoming wi
                        WHERE wi.id = i.incoming_id
                          AND UPPER(COALESCE(wi.office_code,'')) = UPPER(?)
                    )";
                    $expiryOfficeParams[] = $scopeOffice;
                }
            }
        }

        $st = $pdo->prepare("
            SELECT
                i.id AS incoming_item_id,
                i.product_id,
                i.sku,
                i.lot_number,
                i.exp_date,
                COALESCE(i.qty,0) AS incoming_qty,
                COALESCE(a.allocated_qty,0) AS allocated_qty,
                GREATEST(COALESCE(i.qty,0) - COALESCE(a.allocated_qty,0),0) AS remaining_qty,
                DATEDIFF(i.exp_date, CURDATE()) AS days_left
            FROM wqs_incoming_items i
            LEFT JOIN (
                SELECT incoming_item_id, SUM(COALESCE(qty,0)) AS allocated_qty
                FROM wqs_allocations
                GROUP BY incoming_item_id
            ) a ON a.incoming_item_id = i.id
            WHERE i.exp_date IS NOT NULL
              AND DATE(i.exp_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
              AND GREATEST(COALESCE(i.qty,0) - COALESCE(a.allocated_qty,0),0) > 0
              {$expiryOfficeSql}
            ORDER BY i.exp_date ASC, i.sku ASC, i.lot_number ASC
            LIMIT 15
        ");
        $st->execute($expiryOfficeParams);
        $expSoon = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $expSoon = []; }
}

// ── Low-stock per Office + SKU list (Top 30) ─────────────────────────────
// Detail harus memakai definisi yang sama dengan tile: office + SKU, bukan total perusahaan.
$lowStockList = [];
if ($pdo) {
    try {
        $hasMp = t_exists($pdo, 'master_products');
        $stockTable = t_exists($pdo, 'wqs_stock_by_office') ? 'wqs_stock_by_office' : (t_exists($pdo, 'wqs_stock') ? 'wqs_stock' : null);
        if ($stockTable !== null) {
            $sc = wqs_cols($pdo, $stockTable);
            $qtyCol    = wqs_pick_col($sc, ['qty','stock_qty','on_hand_qty']);
            $prodCol   = wqs_pick_col($sc, ['product_id','sku']);
            $officeCol = wqs_pick_col($sc, ['office_code','office','branch_code']);
            [$sow,$sop] = wqs_office_clause($sc, 'ws');

            if ($qtyCol !== null && $prodCol !== null && $officeCol !== null) {
                if ($hasMp && $prodCol === 'product_id') {
                    $mpJoin   = "LEFT JOIN master_products mp ON mp.id = ws.`{$prodCol}`";
                    $skuExpr  = "COALESCE(mp.sku,'')";
                    $nameExpr = "COALESCE(NULLIF(mp.products_name,''),mp.sku,CONCAT('ID#',ws.`{$prodCol}`))";
                } elseif ($hasMp && $prodCol === 'sku') {
                    $mpJoin   = "LEFT JOIN master_products mp ON mp.sku = ws.`{$prodCol}`";
                    $skuExpr  = "ws.`{$prodCol}`";
                    $nameExpr = "COALESCE(NULLIF(mp.products_name,''),ws.`{$prodCol}`)";
                } else {
                    $mpJoin   = "";
                    $skuExpr  = ($prodCol === 'sku') ? "ws.`{$prodCol}`" : "''";
                    $nameExpr = ($prodCol === 'sku') ? "ws.`{$prodCol}`" : "CONCAT('ID#',ws.`{$prodCol}`)";
                }

                $sql = "SELECT UPPER(COALESCE(ws.`{$officeCol}`,'')) AS office_code,
                               ws.`{$prodCol}` AS product_id,
                               {$skuExpr} AS sku,
                               {$nameExpr} AS product_name,
                               SUM(COALESCE(ws.`{$qtyCol}`,0)) AS stock_qty
                        FROM `{$stockTable}` ws
                        {$mpJoin}
                        WHERE 1=1{$sow}
                        GROUP BY UPPER(COALESCE(ws.`{$officeCol}`,'')), ws.`{$prodCol}`, {$skuExpr}, {$nameExpr}
                        HAVING SUM(COALESCE(ws.`{$qtyCol}`,0)) > 0
                           AND SUM(COALESCE(ws.`{$qtyCol}`,0)) < ?
                        ORDER BY office_code ASC, stock_qty ASC, product_name ASC
                        LIMIT 30";
                $st = $pdo->prepare($sql);
                $st->execute(array_merge($sop, [$lowThreshold]));
                $lowStockList = $st->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) { $lowStockList = []; }
}

// ── NEW: Per-office stock breakdown ──────────────────────────────────────
$officeBreakdown = [];
if ($pdo) {
    try {
        // Try wqs_stock_by_office first, fallback to incoming summary per office
        if (t_exists($pdo, 'wqs_stock_by_office')) {
            $st = $pdo->query("
                SELECT office_code, COUNT(*) AS sku_count, COALESCE(SUM(qty),0) AS total_qty
                FROM wqs_stock_by_office
                WHERE qty > 0 AND office_code IS NOT NULL AND office_code != ''
                GROUP BY office_code
                ORDER BY total_qty DESC
                LIMIT 10
            ");
            $officeBreakdown = $st->fetchAll(PDO::FETCH_ASSOC);
        } elseif (t_exists($pdo, 'wqs_incoming')) {
            $st = $pdo->query("
                SELECT office_code,
                       COUNT(DISTINCT po_code) AS po_count,
                       COUNT(*) AS batch_count
                FROM wqs_incoming
                WHERE office_code IS NOT NULL AND office_code != ''
                  AND received_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                GROUP BY office_code
                ORDER BY batch_count DESC
                LIMIT 10
            ");
            $officeBreakdown = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) { $officeBreakdown = []; }
}

// ── NEW: Recent incoming (last 8) ─────────────────────────────────────────
$recentIncoming = [];
if ($pdo && t_exists($pdo, 'wqs_incoming')) {
    try {
        $hasPoCols = t_exists($pdo, 'purchases_po');
        $st = $pdo->prepare("
            SELECT i.id, i.po_code, i.office_code, i.received_date, i.received_by,
                   COALESCE(i.note,'') AS note
            FROM wqs_incoming i
            WHERE 1=1 " . $oWhere . "
            ORDER BY i.id DESC LIMIT 8
        ");
        $st->execute($oParam);
        $recentIncoming = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $recentIncoming = []; }
}

// ── NEW: Recent picking (last 8) ─────────────────────────────────────────
$recentPicking = [];
if ($pdo && t_exists($pdo, 'wqs_picking')) {
    try {
        $st = $pdo->prepare("
            SELECT id, do_code, office_code, picked_at, note
            FROM wqs_picking
            WHERE 1=1 " . $oWhere . "
            ORDER BY id DESC LIMIT 8
        ");
        $st->execute($oParam);
        $recentPicking = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $recentPicking = []; }
}

// Sales Trends dihapus dari WQS — tidak relevan untuk operasional gudang.
// WQS fokus pada: Incoming, Picking, Adjustment, Stock Level.
$chartSalesTrend = []; // kept empty to avoid breaking chart JS references

// ── Chart: Stock Movement (Incoming + Picking + Adjustment per day) ──────
// IMPORTANT:
// - Setiap series diambil independen. Error/kosong pada satu sumber TIDAK boleh
//   mengosongkan series lain.
// - Picking mengikuti source-of-truth yang sama dengan KPI Picking/WQS Selesai:
//   wqs_picking -> sales_do WQS-ready timestamp -> sales_do_audit.
// - Adjustment schema-aware agar perbedaan nama timestamp/office tidak mematikan chart.
$chartStockMovement = [];
$incomingByDay = [];
$pickingByDay = [];
$adjByDay = [];

if ($pdo) {
    // Incoming: sama dengan KPI Incoming Periode.
    if (t_exists($pdo, 'wqs_incoming')) {
        try {
            $ic = wqs_cols($pdo, 'wqs_incoming');
            $receivedCol = wqs_pick_col($ic, ['received_date','received_at','created_at']);
            if ($receivedCol !== null) {
                [$iow,$iop] = wqs_office_clause($ic);
                $st = $pdo->prepare("SELECT DATE(`{$receivedCol}`) AS d, COUNT(*) AS cnt
                                     FROM wqs_incoming
                                     WHERE `{$receivedCol}` BETWEEN ? AND ?{$iow}
                                     GROUP BY DATE(`{$receivedCol}`)
                                     ORDER BY d ASC");
                $st->execute(array_merge([$chartFrom . ' 00:00:00', $chartTo . ' 23:59:59'], $iop));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $d = (string)($r['d'] ?? '');
                    if ($d !== '') $incomingByDay[$d] = (int)($r['cnt'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            // Incoming gagal tidak boleh menghapus Picking/Adjustment.
            $incomingByDay = [];
        }
    }

    // Picking primary: tabel operasional wqs_picking.
    if (t_exists($pdo, 'wqs_picking')) {
        try {
            $pc = wqs_cols($pdo, 'wqs_picking');
            $pickedCol = wqs_pick_col($pc, ['picked_at','completed_at','created_at']);
            if ($pickedCol !== null) {
                [$pow,$pop] = wqs_office_clause($pc);
                $st = $pdo->prepare("SELECT DATE(`{$pickedCol}`) AS d, COUNT(*) AS cnt
                                     FROM wqs_picking
                                     WHERE `{$pickedCol}` BETWEEN ? AND ?{$pow}
                                     GROUP BY DATE(`{$pickedCol}`)
                                     ORDER BY d ASC");
                $st->execute(array_merge([$dtStart, $dtEnd], $pop));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $d = (string)($r['d'] ?? '');
                    if ($d !== '') $pickingByDay[$d] = (int)($r['cnt'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $pickingByDay = [];
        }
    }

    // Picking fallback 1: bila primary benar-benar tidak punya transaksi pada periode,
    // gunakan timestamp WQS selesai pada sales_do, sama seperti KPI.
    if (array_sum($pickingByDay) === 0 && t_exists($pdo, 'sales_do')) {
        try {
            $sdpc = wqs_cols($pdo, 'sales_do');
            $readyCol = wqs_pick_col($sdpc, ['wqs_ready_at','wqs_done_at','ready_scm_at']);
            $doCol = wqs_pick_col($sdpc, ['do_code','code']);
            if ($readyCol !== null) {
                [$pdow,$pdop] = wqs_office_clause($sdpc);
                $countExpr = $doCol !== null ? "COUNT(DISTINCT `{$doCol}`)" : 'COUNT(*)';
                $st = $pdo->prepare("SELECT DATE(`{$readyCol}`) AS d, {$countExpr} AS cnt
                                     FROM sales_do
                                     WHERE `{$readyCol}` BETWEEN ? AND ?{$pdow}
                                     GROUP BY DATE(`{$readyCol}`)
                                     ORDER BY d ASC");
                $st->execute(array_merge([$dtStart, $dtEnd], $pdop));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $d = (string)($r['d'] ?? '');
                    if ($d !== '') $pickingByDay[$d] = (int)($r['cnt'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $pickingByDay = [];
        }
    }

    // Picking fallback 2: audit legacy, hanya bila dua sumber di atas kosong.
    if (array_sum($pickingByDay) === 0 && t_exists($pdo, 'sales_do_audit')) {
        try {
            $ac = wqs_cols($pdo, 'sales_do_audit');
            $timeCol = wqs_pick_col($ac, ['created_at','event_at','logged_at','updated_at']);
            $doCol = wqs_pick_col($ac, ['do_code','sales_do_code','code']);
            $stateCol = wqs_pick_col($ac, ['new_status','status','action','event']);
            if ($timeCol !== null && $doCol !== null && $stateCol !== null) {
                [$aow,$aop] = wqs_office_clause($ac);
                $st = $pdo->prepare("SELECT DATE(`{$timeCol}`) AS d, COUNT(DISTINCT `{$doCol}`) AS cnt
                                     FROM sales_do_audit
                                     WHERE `{$timeCol}` BETWEEN ? AND ?
                                       AND LOWER(COALESCE(`{$stateCol}`,'')) IN ('ready_scm','wqs_done','wqs_picked','scm_ready'){$aow}
                                     GROUP BY DATE(`{$timeCol}`)
                                     ORDER BY d ASC");
                $st->execute(array_merge([$dtStart, $dtEnd], $aop));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $d = (string)($r['d'] ?? '');
                    if ($d !== '') $pickingByDay[$d] = (int)($r['cnt'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $pickingByDay = [];
        }
    }

    // Adjustment: schema-aware + office-aware. Jika tabel/kolom tidak ada, series = 0
    // tanpa mempengaruhi Incoming/Picking.
    if (t_exists($pdo, 'wqs_stock_adjustments')) {
        try {
            $jc = wqs_cols($pdo, 'wqs_stock_adjustments');
            $adjTimeCol = wqs_pick_col($jc, ['created_at','adjusted_at','updated_at','adjustment_date']);
            if ($adjTimeCol !== null) {
                [$jow,$jop] = wqs_office_clause($jc);
                $st = $pdo->prepare("SELECT DATE(`{$adjTimeCol}`) AS d, COUNT(*) AS cnt
                                     FROM wqs_stock_adjustments
                                     WHERE `{$adjTimeCol}` BETWEEN ? AND ?{$jow}
                                     GROUP BY DATE(`{$adjTimeCol}`)
                                     ORDER BY d ASC");
                $st->execute(array_merge([$dtStart, $dtEnd], $jop));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $d = (string)($r['d'] ?? '');
                    if ($d !== '') $adjByDay[$d] = (int)($r['cnt'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $adjByDay = [];
        }
    }
}

// Selalu bangun label periode, walaupun salah satu/semua series nol.
for ($ts = strtotime($chartFrom), $last = strtotime($chartTo); $ts <= $last; $ts += 86400) {
    $d = date('Y-m-d', $ts);
    $chartStockMovement[] = [
        'd'          => $d,
        'incoming'   => $incomingByDay[$d] ?? 0,
        'picking'    => $pickingByDay[$d] ?? 0,
        'adjustment' => $adjByDay[$d] ?? 0,
    ];
}

// ── Render ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../_shared/rmi_layout.php';

$extraHead = '<style>
body{background:#0b1220;color:#e8ecf4}
.card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.12);border-radius:16px}
.muted{color:#8b9bb4;font-size:12px}
table{color:#e8ecf4}

/* Filter bar */
.wqs-filter-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;padding:12px 16px;background:rgba(17,24,39,.85);border:1px solid rgba(249,115,22,.25);border-radius:12px;margin-bottom:14px}
.wqs-filter-bar label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;display:block;margin-bottom:3px}
.wqs-filter-bar .form-control,.wqs-filter-bar .form-select{background:rgba(255,255,255,.05)!important;border-color:rgba(255,255,255,.12)!important;color:#e2e8f0!important;font-size:12px}
.wqs-quick-btn{padding:5px 12px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:#94a3b8;font-size:11px;font-weight:600;cursor:pointer;text-decoration:none;transition:.12s;display:inline-block}
.wqs-quick-btn:hover,.wqs-quick-btn.act{background:rgba(249,115,22,.15);border-color:rgba(249,115,22,.4);color:#fb923c}

/* Header */
.wqs-header{background:linear-gradient(135deg,rgba(124,45,18,.7),rgba(234,88,12,.5));border:1px solid rgba(249,115,22,.3);border-radius:18px;padding:18px 22px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.wqs-header h2{margin:0;font-size:19px;font-weight:800;color:#fff}
.wqs-header p{margin:3px 0 0;font-size:11px;color:rgba(255,255,255,.65)}

/* KPI grid */
.wqs-kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:14px}
@media(max-width:576px){.wqs-kpi{grid-template-columns:1fr 1fr}}
.wqs-k{position:relative;z-index:2;pointer-events:auto;cursor:pointer;padding:14px;border-radius:14px;background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.1);border-top:3px solid var(--kc,#64748b);text-decoration:none;color:inherit;display:block;transition:all .2s}
.wqs-k:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.3);border-color:var(--kc)}
.wqs-k-icon{font-size:18px;margin-bottom:5px}
.wqs-k-n{font-size:22px;font-weight:800;color:#fff;line-height:1;font-variant-numeric:tabular-nums}
.wqs-k-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-top:3px}
.wqs-k-sub{font-size:10px;color:var(--rmi-muted);margin-top:2px}

/* Section heading */
.wqs-sh{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--rmi-muted);margin:16px 0 8px;display:flex;align-items:center;gap:8px}
.wqs-sh::after{content:"";flex:1;height:1px;background:rgba(255,255,255,.08)}

/* Mini table */
.wqs-mini-table{width:100%;border-collapse:collapse;font-size:12px}
.wqs-mini-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--rmi-muted);padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;white-space:nowrap}
.wqs-mini-table td{padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle}
.wqs-mini-table tr:hover td{background:rgba(255,255,255,.02)}
.wqs-mini-table tr:last-child td{border-bottom:none}

/* Quick links */
.wqs-links{display:flex;flex-wrap:wrap;gap:6px}
.wqs-link{padding:6px 13px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid rgba(255,255,255,.12);color:#e2e8f0;background:rgba(255,255,255,.05);transition:all .2s;display:inline-flex;align-items:center;gap:5px}
.wqs-link:hover{background:rgba(255,255,255,.12);color:#fff}
.wqs-link.primary{background:linear-gradient(135deg,#f97316,#ea580c);border-color:transparent;color:#fff}

/* Office bar */
.office-bar{height:6px;background:rgba(255,255,255,.07);border-radius:3px;overflow:hidden;margin-top:3px}
.office-bar-fill{height:6px;border-radius:3px;background:linear-gradient(90deg,#f97316,#fbbf24);transition:.3s}
</style>';

rmi_header('Warehouse Dashboard', [
    'active'     => 'stock',
    'subtitle'   => 'WQS — Stock, Incoming, Picking, Expiry, DO Tasks',
    'extra_head' => $extraHead,
    'actions'    => [
        ['label' => rmi_icon('books').' Panduan', 'url' => u('/dashboards/warehouse/panduan.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>
<div class="container py-3" style="max-width:1240px">
  <?php if ($__wqsDashGlobalScope): ?>
    <div class="alert alert-info py-2" style="background:rgba(59,130,246,.08);border-color:rgba(59,130,246,.25);color:#dbeafe">
      <b>Mgr WQS — Scope ALL:</b> KPI dan data dashboard WQS dihitung global seperti SYS. Hak mutasi di halaman operasional tetap mengikuti RBAC masing-masing.
    </div>
  <?php endif; ?>
  <!-- Header -->
  <div class="wqs-header">
    <div>
      <h2><?=rmi_icon('box')?> Warehouse (WQS) Dashboard</h2>
      <p>
        <?= h($chartFrom) ?> → <?= h($chartTo) ?>
        <?php if ($scopeOfficeFilter): ?> · Office: <b><?= h($scopeOffice) ?></b><?php endif; ?>
        <?php if ($kpi['exp_expired'] > 0): ?>
          &nbsp;<span style="background:rgba(239,68,68,.3);color:#fca5a5;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700"><?=rmi_icon('warn')?> <?= $kpi['exp_expired'] ?> EXPIRED</span>
        <?php endif; ?>
        <?php if ($kpi['do_waiting'] > 0): ?>
          &nbsp;<span style="background:rgba(249,115,22,.3);color:#fdba74;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700"><?=rmi_icon('clipboard')?> <?= $kpi['do_waiting'] ?> DO nunggu</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-sm btn-outline-light" href="<?= h(u('/dashboards/index.php')) ?>"><?=rmi_icon('home')?> Home</a>
      <form method="post" action="<?= h(u('/dashboards/warehouse/wqs_dashboard_export.php')) ?>" class="d-inline">
        <?= function_exists('csrf_field') ? csrf_field() : (function_exists('rmi_csrf_input') ? rmi_csrf_input() : '') ?>
        <input type="hidden" name="report_id" value="wqs_dashboard">
        <input type="hidden" name="from" value="<?= h($chartFrom) ?>">
        <input type="hidden" name="to"   value="<?= h($chartTo)   ?>">
        <button type="submit" class="btn btn-sm btn-outline-warning" name="format" value="csv"><?=rmi_icon('inbox')?> Export CSV</button>
      </form>
    </div>
  </div>

  <!-- Unified Filter Bar -->
  <div class="wqs-filter-bar">
    <div>
      <label>Periode Cepat</label>
      <div style="display:flex;gap:4px;flex-wrap:wrap">
        <?php
        $quickPeriods = [
            'today' => 'Hari Ini',
            'week'  => 'Minggu Ini',
            'month' => 'Bulan Ini',
            '7'     => '7 Hari',
            '30'    => '30 Hari',
            '90'    => '90 Hari',
        ];
        foreach ($quickPeriods as $val => $lbl):
            $isAct = $rangePreset === $val;
        ?>
          <a class="wqs-quick-btn <?= $isAct ? 'act' : '' ?>"
             href="?range=<?= urlencode($val) ?>"><?= h($lbl) ?></a>
        <?php endforeach; ?>
        <a class="wqs-quick-btn <?= $rangePreset === 'custom' ? 'act' : '' ?>"
           href="#" onclick="document.getElementById('wqsCustomForm').style.display='flex';return false">Custom</a>
      </div>
    </div>

    <form id="wqsCustomForm" method="get" style="display:<?= $rangePreset==='custom'?'flex':'none' ?>;gap:6px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="range" value="custom">
      <div>
        <label>Dari</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= h($chartFrom) ?>" style="width:130px">
      </div>
      <div>
        <label>Sampai</label>
        <input type="date" name="to"   class="form-control form-control-sm" value="<?= h($chartTo)   ?>" style="width:130px">
      </div>
      <div style="align-self:flex-end">
        <button type="submit" class="btn btn-sm btn-rmi">Apply</button>
      </div>
    </form>
  </div>

  <!-- KPI Tiles -->
  <div class="wqs-kpi">
    <!-- DO Waiting WQS — NEW critical tile -->
    <a class="wqs-k" href="<?= h(u('/stock/wqs_do_tasks.php')) ?>" style="--kc:<?= $kpi['do_waiting']>0?'#f97316':'#64748b' ?>">
      <div class="wqs-k-icon"><?=rmi_icon('clipboard')?></div>
      <div class="wqs-k-n" style="color:<?= $kpi['do_waiting']>0?'#fb923c':'#fff' ?>"><?= number_format((int)$kpi['do_waiting']) ?></div>
      <div class="wqs-k-lbl">DO Waiting WQS</div>
      <div class="wqs-k-sub"><?= $kpi['do_waiting']>0?rmi_icon('zap').' Current open backlog':rmi_icon('tick').' Tidak ada antrian' ?></div>
    </a>

    <!-- PR Open — NEW -->
    <a class="wqs-k" href="<?= h(u('/stock/wqs_pr.php')) ?>" style="--kc:#8b5cf6">
      <div class="wqs-k-icon"><?=rmi_icon('memo')?></div>
      <div class="wqs-k-n" style="color:<?= $kpi['pr_open']>0?'#c4b5fd':'#fff' ?>"><?= number_format((int)$kpi['pr_open']) ?></div>
      <div class="wqs-k-lbl">PR Open</div>
      <div class="wqs-k-sub">Current: DRAFT + SUBMITTED</div>
    </a>

    <!-- AVG Durasi SLA WQS -->
    <a class="wqs-k" href="<?= h(u('/stock/wqs_do_tasks.php')) ?>" style="--kc:#06b6d4" title="Buka sumber SLA WQS">
      <div class="wqs-k-icon"><?=rmi_icon('calendar')?></div>
      <div class="wqs-k-n" style="color:#67e8f9"><?= h(wqs_duration_label((float)$kpi['avg_wqs_minutes'])) ?></div>
      <div class="wqs-k-lbl">Avg Durasi SLA WQS</div>
      <div class="wqs-k-sub">Selesai WQS pada periode · n=<?= number_format((int)$kpi['avg_wqs_samples']) ?></div>
    </a>

    <a class="wqs-k" href="<?= h(u('/stock/wqs_stock.php')) ?>" style="--kc:#3b82f6">
      <div class="wqs-k-icon"><?=rmi_icon('chart')?></div>
      <div class="wqs-k-n"><?= number_format((int)$kpi['sku_onhand']) ?></div>
      <div class="wqs-k-lbl">SKU On-hand</div>
      <div class="wqs-k-sub">Total Qty: <?= number_format((float)$kpi['qty_onhand'], 0, ',', '.') ?></div>
    </a>

    <a class="wqs-k" href="<?= h(u('/stock/wqs_incoming.php?from=' . rawurlencode($mStart) . '&to=' . rawurlencode($mEnd))) ?>" style="--kc:#22c55e">
      <div class="wqs-k-icon"><?=rmi_icon('inbox')?></div>
      <div class="wqs-k-n"><?= number_format((int)$kpi['incoming_mtd']) ?></div>
      <div class="wqs-k-lbl">Incoming Periode</div>
      <div class="wqs-k-sub"><?= h($chartFrom) ?> → <?= h($chartTo) ?></div>
    </a>

    <a class="wqs-k" href="<?= h(u('/stock/wqs_do_tasks.php?date_from=' . rawurlencode($mStart) . '&date_to=' . rawurlencode($mEnd))) ?>" style="--kc:#f97316">
      <div class="wqs-k-icon"><?=rmi_icon('box')?></div>
      <div class="wqs-k-n"><?= number_format((int)$kpi['picking_mtd']) ?></div>
      <div class="wqs-k-lbl">Picking / WQS Selesai</div>
      <div class="wqs-k-sub"><?= h($chartFrom) ?> → <?= h($chartTo) ?> · <?= h($kpi['picking_source']) ?></div>
    </a>

    <?php /*
      Unallocated Lines sengaja tidak dirender di dashboard.
      Query/variabel lama dipertahankan agar tidak mengubah alur Allocation atau dependensi lain.
      KPI ini baru boleh ditampilkan kembali setelah tersedia drill-down yang merekonsiliasi
      line/SKU/qty dengan angka tile secara 1:1.
    */ ?>

    <!-- Low-stock — NEW -->
    <a class="wqs-k" href="<?= h(u('/dashboards/warehouse/wqs_low_stock_office.php')) ?>" style="--kc:<?= $kpi['low_stock_sku']>0?'#f59e0b':'#64748b' ?>">
      <div class="wqs-k-icon"><?=rmi_icon('trend')?></div>
      <div class="wqs-k-n" style="color:<?= $kpi['low_stock_sku']>0?'#fbbf24':'#fff' ?>"><?= number_format((int)$kpi['low_stock_sku']) ?></div>
      <div class="wqs-k-lbl">Low-Stock Office-SKU</div>
      <div class="wqs-k-sub">Stok &lt; <?= $lowThreshold ?> unit</div>
    </a>

    <!-- Expiry < 90 Hari — clickable, memakai remaining lot yang sama dengan detail -->
    <a class="wqs-k"
         href="#wqs-expiry-90-detail"
         style="--kc:<?= (int)$kpi['exp_90'] > 0 ? '#ef4444' : '#64748b' ?>"
         title="Buka detail lot dengan sisa stok dan expiry hari ini sampai 90 hari ke depan.">
      <div class="wqs-k-icon"><?=rmi_icon('calendar')?></div>
      <div class="wqs-k-n" style="color:<?= (int)$kpi['exp_90'] > 0 ? '#f87171' : '#fff' ?>">
        <?= number_format((int)$kpi['exp_90']) ?>
      </div>
      <div class="wqs-k-lbl">Expiry &lt; 90 Hari</div>
      <div class="wqs-k-sub">
        <?php if ((int)$kpi['exp_expired'] > 0): ?>
          <?=rmi_icon('cross')?> <?= number_format((int)$kpi['exp_expired']) ?> Expired
        <?php elseif ((int)$kpi['exp_90'] > 0): ?>
          <?=rmi_icon('warn')?> Mendekati expiry
        <?php else: ?>
          <?=rmi_icon('tick')?> Tidak ada expiry &lt; 90 hari
        <?php endif; ?>
      </div>
    </a>
  </div>

  <!-- Chart: Stock Movement (full width) -->
  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="card"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
          <div>
            <div class="fw-semibold" style="font-size:14px"><?=rmi_icon('chart')?> Aktivitas Gudang Harian</div>
            <div style="font-size:11px;color:var(--rmi-muted)">Incoming · Picking · Adjustment — <?= h($chartFrom) ?> → <?= h($chartTo) ?></div>
          </div>
          <div style="display:flex;gap:14px;font-size:11px;color:#64748b">
            <span><span style="display:inline-block;width:10px;height:10px;background:#3b82f6;border-radius:2px;margin-right:4px"></span>Incoming</span>
            <span><span style="display:inline-block;width:10px;height:10px;background:#f97316;border-radius:2px;margin-right:4px"></span>Picking</span>
            <span><span style="display:inline-block;width:10px;height:10px;background:#22c55e;border-radius:2px;margin-right:4px"></span>Adjustment</span>
          </div>
        </div>
        <?php
          // Server-side fallback: chart tetap terlihat walaupun Chart.js/helper JS gagal dimuat.
          // Data memakai $chartStockMovement yang sama dengan chart interaktif, jadi tidak mengubah sumber KPI/alur bisnis.
          $movementMax = 0;
          foreach ($chartStockMovement as $__mv) {
              $movementMax = max($movementMax, (int)($__mv['incoming'] ?? 0), (int)($__mv['picking'] ?? 0), (int)($__mv['adjustment'] ?? 0));
          }
          $movementMax = max(1, $movementMax);
        ?>
        <div id="chartStockMovementFallback" style="height:240px;display:flex;align-items:flex-end;gap:3px;padding:18px 4px 22px;border-bottom:1px solid rgba(255,255,255,.08);overflow:hidden">
          <?php foreach ($chartStockMovement as $__mv):
            $__in = (int)($__mv['incoming'] ?? 0);
            $__pk = (int)($__mv['picking'] ?? 0);
            $__ad = (int)($__mv['adjustment'] ?? 0);
            $__ih = $__in > 0 ? max(3, round($__in / $movementMax * 190)) : 0;
            $__ph = $__pk > 0 ? max(3, round($__pk / $movementMax * 190)) : 0;
            $__ah = $__ad > 0 ? max(3, round($__ad / $movementMax * 190)) : 0;
          ?>
            <div style="flex:1;min-width:6px;height:200px;display:flex;align-items:flex-end;justify-content:center;gap:1px;position:relative"
                 title="<?= h($__mv['d']) ?> · Incoming <?= $__in ?> · Picking <?= $__pk ?> · Adjustment <?= $__ad ?>">
              <span style="width:30%;height:<?= $__ih ?>px;background:#3b82f6;border-radius:2px 2px 0 0"></span>
              <span style="width:30%;height:<?= $__ph ?>px;background:#f97316;border-radius:2px 2px 0 0"></span>
              <span style="width:30%;height:<?= $__ah ?>px;background:#22c55e;border-radius:2px 2px 0 0"></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div id="chartStockMovementCanvasWrap" style="height:240px;display:none"><canvas id="chartStockMovement"></canvas></div>
      </div></div>
    </div>
  </div>

  <!-- Office Breakdown + Low-Stock Alert -->
  <div class="row g-3 mb-3">

    <!-- Per-Office Breakdown — NEW -->
    <?php if (!empty($officeBreakdown)): ?>
    <div class="col-lg-4">
      <div class="card"><div class="card-body">
        <div class="wqs-sh" style="margin-top:0"><?=rmi_icon('office')?> Stok per Office</div>
        <?php
        $maxQty = max(1, ...array_map(fn($o) => (float)($o['total_qty'] ?? $o['batch_count'] ?? 1), $officeBreakdown));
        foreach ($officeBreakdown as $o):
            $qty    = (float)($o['total_qty'] ?? $o['batch_count'] ?? 0);
            $sku    = (int)($o['sku_count']   ?? $o['po_count'] ?? 0);
            $barPct = round($qty / $maxQty * 100);
        ?>
          <div style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
              <span style="font-size:12px;font-weight:700;color:#e2e8f0"><?= h($o['office_code'] ?? '—') ?></span>
              <span style="font-size:11px;color:#f97316;font-weight:700"><?= number_format($qty, 0, ',', '.') ?> <?= isset($o['total_qty']) ? 'qty' : 'batch' ?></span>
            </div>
            <div class="office-bar"><div class="office-bar-fill" style="width:<?= $barPct ?>%"></div></div>
            <div style="font-size:10px;color:var(--rmi-muted);margin-top:2px"><?= $sku ?> <?= isset($o['total_qty']) ? 'SKU' : 'PO' ?></div>
          </div>
        <?php endforeach; ?>
      </div></div>
    </div>
    <?php endif; ?>

    <!-- Low-Stock Office-SKU: detail dipisahkan ke halaman khusus -->
    <div class="col-lg-<?= empty($officeBreakdown) ? '6' : '4' ?>">
      <div class="card"><div class="card-body">
        <div class="wqs-sh" style="margin-top:0"><?=rmi_icon('trend')?> Low-Stock per Kantor</div>
        <div style="font-size:28px;font-weight:800;color:<?= $kpi['low_stock_sku']>0?'#fbbf24':'#fff' ?>;margin:8px 0"><?= number_format((int)$kpi['low_stock_sku']) ?></div>
        <div class="muted" style="margin-bottom:12px">Office-SKU dengan stok 1–<?= $lowThreshold-1 ?> unit. Detail dipisahkan dari halaman Stock agar tidak mencampur snapshot stok dengan exception low-stock per kantor.</div>
        <a class="btn btn-sm btn-outline-warning" href="<?= h(u('/dashboards/warehouse/wqs_low_stock_office.php')) ?>">Buka Low-Stock per Kantor →</a>
      </div></div>
    </div>

    <!-- Expiry List: tampil hanya jika memang ada data expiry yang relevan. -->
    <?php if (!empty($expSoon) || (int)$kpi['exp_expired'] > 0): ?>
    <div class="col-lg-<?= empty($officeBreakdown) ? '6' : '4' ?>" id="wqs-expiry-90-detail">
      <div class="card"><div class="card-body">
        <div class="wqs-sh" style="margin-top:0"><?=rmi_icon('calendar')?> Expiry &lt; 90 Hari (Top 15)</div>
        <?php if (empty($expSoon)): ?>
          <div class="muted" style="padding:12px 0"><?=rmi_icon('check')?> Belum ada produk mendekati expiry.</div>
        <?php else: ?>
          <div class="table-responsive">
          <table class="wqs-mini-table">
            <thead><tr><th>SKU</th><th>Lot</th><th>Exp Date</th><th style="text-align:right">Sisa Qty</th></tr></thead>
            <tbody>
            <?php foreach ($expSoon as $r):
              $daysLeft  = (int)($r['days_left'] ?? round((strtotime($r['exp_date']) - time()) / 86400));
              $expColor  = $daysLeft <= 30 ? '#f87171' : '#fbbf24';
            ?>
              <tr>
                <td style="font-size:11px"><code style="color:#60a5fa"><?= h(function_exists('rmi_sku') ? rmi_sku($r['sku']) : $r['sku']) ?></code></td>
                <td style="font-size:11px;color:#64748b"><?= h($r['lot_number'] ?? '') ?></td>
                <td style="white-space:nowrap">
                  <span style="font-size:11px;color:<?= $expColor ?>;font-weight:700"><?= h($r['exp_date']) ?></span>
                  <span style="font-size:10px;color:var(--rmi-muted)"> (<?= $daysLeft ?>h)</span>
                </td>
                <td style="text-align:right;font-size:12px;font-weight:600"><?= number_format((float)($r['remaining_qty']??0), 0, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Recent Activity — NEW -->
  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card"><div class="card-body">
        <div class="wqs-sh" style="margin-top:0"><?=rmi_icon('inbox')?> Incoming Terbaru</div>
        <?php if (empty($recentIncoming)): ?>
          <div class="muted">Belum ada data incoming.</div>
        <?php else: ?>
          <div class="table-responsive">
          <table class="wqs-mini-table">
            <thead><tr><th>PO Code</th><th>Office</th><th>Tanggal</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($recentIncoming as $r): ?>
              <tr>
                <td>
                  <a href="<?= h(u('/stock/wqs_incoming_view.php?po_code=' . rawurlencode($r['po_code'] ?? ''))) ?>" style="color:#60a5fa;font-size:12px;font-weight:600"><?= h($r['po_code'] ?? '—') ?></a>
                  <?php if (!empty($r['note'])): ?><div style="font-size:10px;color:var(--rmi-muted)"><?= h(mb_strimwidth($r['note'], 0, 30, '…')) ?></div><?php endif; ?>
                </td>
                <td style="font-size:11px"><span style="background:rgba(249,115,22,.15);color:#fdba74;padding:1px 6px;border-radius:5px;font-weight:700"><?= h(strtoupper($r['office_code'] ?? '—')) ?></span></td>
                <td style="font-size:11px;color:#94a3b8;white-space:nowrap"><?= h($r['received_date'] ?? '') ?></td>
                <td style="font-size:11px;color:#64748b"><?= h(mb_strimwidth($r['received_by'] ?? '', 0, 12, '…')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <div style="margin-top:8px"><a href="<?= h(u('/stock/wqs_incoming.php')) ?>" style="font-size:11px;color:#f97316">Lihat Semua →</a></div>
        <?php endif; ?>
      </div></div>
    </div>

    <div class="col-lg-6">
      <div class="card"><div class="card-body">
        <div class="wqs-sh" style="margin-top:0"><?=rmi_icon('box')?> Picking Terbaru</div>
        <?php if (empty($recentPicking)): ?>
          <div class="muted">Belum ada data picking.</div>
        <?php else: ?>
          <div class="table-responsive">
          <table class="wqs-mini-table">
            <thead><tr><th>DO Code</th><th>Office</th><th>Waktu</th></tr></thead>
            <tbody>
            <?php foreach ($recentPicking as $r): ?>
              <tr>
                <td>
                  <a href="<?= h(u('/stock/wqs_picking_view.php?id=' . (int)$r['id'])) ?>" style="color:#60a5fa;font-size:12px;font-weight:600"><?= h($r['do_code'] ?? '—') ?></a>
                  <?php if (!empty($r['note'])): ?><div style="font-size:10px;color:var(--rmi-muted)"><?= h(mb_strimwidth($r['note'], 0, 30, '…')) ?></div><?php endif; ?>
                </td>
                <td style="font-size:11px"><span style="background:rgba(249,115,22,.15);color:#fdba74;padding:1px 6px;border-radius:5px;font-weight:700"><?= h(strtoupper($r['office_code'] ?? '—')) ?></span></td>
                <td style="font-size:11px;color:#94a3b8;white-space:nowrap"><?= h($r['picked_at'] ? date('d/m H:i', strtotime($r['picked_at'])) : '') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <div style="margin-top:8px"><a href="<?= h(u('/stock/wqs_picking.php')) ?>" style="font-size:11px;color:#f97316">Lihat Semua →</a></div>
        <?php endif; ?>
      </div></div>
    </div>
  </div>

  <!-- Quick Links -->
  <div class="card"><div class="card-body">
    <div class="wqs-sh" style="margin-top:0"><?=rmi_icon('zap')?> Quick Links</div>
    <div class="wqs-links">
      <a class="wqs-link primary" href="<?= h(u('/stock/wqs_do_tasks.php')) ?>"><?=rmi_icon('clipboard')?> DO Tasks</a>
      <a class="wqs-link primary" href="<?= h(u('/stock/wqs_picking.php')) ?>"><?=rmi_icon('box')?> Picking</a>
      <a class="wqs-link primary" href="<?= h(u('/stock/wqs_incoming.php')) ?>"><?=rmi_icon('inbox')?> Incoming</a>
      <a class="wqs-link primary" href="<?= h(u('/stock/wqs_stock.php')) ?>"><?=rmi_icon('chart')?> Lihat Stok</a>
      <a class="wqs-link" href="<?= h(u('/stock/wqs_allocation.php')) ?>"><?=rmi_icon('doc')?> Allocation</a>
      <a class="wqs-link" href="<?= h(u('/stock/wqs_pr.php')) ?>"><?=rmi_icon('memo')?> Purchase Request</a>
      <a class="wqs-link" href="<?= h(u('/stock/wqs_stock_opname.php')) ?>"><?=rmi_icon('chart')?> Stock Opname</a>
      <a class="wqs-link" href="<?= h(u('/stock/wqs_stock_opname_report.php')) ?>"><?=rmi_icon('clipboard')?> Opname Report</a>
      <a class="wqs-link" href="<?= h(u('/stock/wqs_stock_adjustment.php')) ?>"><?=rmi_icon('memo')?> Adjustment</a>
      <a class="wqs-link" href="<?= h(u('/stock/wqs_stock_audit.php')) ?>"><?=rmi_icon('search')?> Stock Audit</a>
      <a class="wqs-link" href="<?= h(u('/stock/wqs_stock_transfer.php')) ?>"><?=rmi_icon('refresh')?> Transfer Stok</a>
      <a class="wqs-link" href="<?= h(u('/sales/sales_control_tower.php')) ?>"><?=rmi_icon('tower')?> Control Tower</a>
      <a class="wqs-link" href="<?= h(u('/master/master_products.php')) ?>"><?=rmi_icon('box')?> Master Produk</a>
      <a class="wqs-link" href="<?= h(u('/absensi/index.php')) ?>"><?=rmi_icon('calendar')?> Absensi</a>
    </div>
  </div></div>

  <?php
  $auditModules = ['STOCK', 'WQS', 'WQS_INCOMING', 'STOCK_ADJUSTMENT', 'STOCK_TRANSFER', 'STOCK_OPNAME'];
  $auditLimit   = 10;
  require __DIR__ . '/../_audit_log_widget.php';
  ?>

</div>

<?php
$base = function_exists('rmi_assets_base') ? rmi_assets_base() : ($GLOBALS['BASE_PROJECT'] ?? '');
$stockLabels     = array_column($chartStockMovement, 'd');
$stockIncoming   = array_column($chartStockMovement, 'incoming');
$stockPicking    = array_column($chartStockMovement, 'picking');
$stockAdjustment = array_column($chartStockMovement, 'adjustment');

$chartJs = rmi_assets_foot(['chartjs'=>true,'jquery'=>true,'bootstrap'=>false,'datatables'=>false]);
$extraJs = $chartJs . "\n"
    . '<script src="' . h($base) . '/public/assets/js/dashboard_charts.js?v=20260209"></script>'
    . '<script>
(function(){
  var R = window.RmiDashboardCharts;
  if (!R) return;
  var stockData = ' . json_encode(['labels'=>$stockLabels,'incoming'=>$stockIncoming,'picking'=>$stockPicking,'adjustment'=>$stockAdjustment]) . ';
  var hasStock = stockData.labels && stockData.labels.length &&
    (stockData.incoming.some(function(v){return v>0;}) || stockData.picking.some(function(v){return v>0;}) || stockData.adjustment.some(function(v){return v>0;}));
  if (hasStock) {
    var fb = document.getElementById("chartStockMovementFallback");
    var cw = document.getElementById("chartStockMovementCanvasWrap");
    if (cw) cw.style.display = "block";
    R.createBar("chartStockMovement", stockData.labels, [
      { label:"Incoming",   data:stockData.incoming,   backgroundColor:"rgba(59,130,246,.7)", borderColor:"#3b82f6", borderWidth:1.5, borderRadius:3 },
      { label:"Picking",    data:stockData.picking,    backgroundColor:"rgba(249,115,22,.7)", borderColor:"#f97316", borderWidth:1.5, borderRadius:3 },
      { label:"Adjustment", data:stockData.adjustment, backgroundColor:"rgba(34,197,94,.7)",  borderColor:"#22c55e", borderWidth:1.5, borderRadius:3 }
    ], { legend:true });
    // Helper berhasil dipanggil: gunakan chart interaktif. Jika JS tidak pernah jalan,
    // fallback server-side di atas tetap terlihat sehingga panel tidak kosong.
    if (fb) fb.style.display = "none";
  } else {
    var el = document.getElementById("chartStockMovement");
    if (el && el.parentNode) el.parentNode.innerHTML = "<div style=\'color:var(--rmi-muted);padding:24px;font-size:12px;text-align:center\'>Belum ada data movement untuk periode ini.</div>";
  }
})();
</script>';
if (function_exists('rmi_ui_set_extra_js')) rmi_ui_set_extra_js($extraJs);
?>
<?php rmi_footer(); ?>
