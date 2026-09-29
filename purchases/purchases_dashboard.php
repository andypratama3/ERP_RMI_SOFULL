<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();

// Dashboard PQP adalah landing utama untuk akun PQP.
// Staff/Manager PQP boleh membuka dashboard ini walaupun belum punya permission
// PURCHASES.IMPORT_CONTROL. Permission detail per menu tetap dijaga di halaman masing-masing.
$__deptForPqpDashboard = function_exists('auth_dept')
    ? strtoupper((string)auth_dept())
    : strtoupper((string)($_SESSION['department'] ?? ''));

$__isPqpDashboardUser = ($__deptForPqpDashboard === 'PQP');

if (!$__isPqpDashboardUser) {
    if (function_exists('require_any_permission')) {
        require_any_permission(['PURCHASES.VIEW', 'DASHBOARD.PROCUREMENT_VIEW']);
    } else {
        require_role(['ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
    }
}
require_once __DIR__ . '/../dashboards/_manager_scope.php';

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

// KPI summary (best-effort)
$kpi = ['pr_submitted'=>0,'po_open'=>0,'po_value_month'=>0,'ap_outstanding'=>0,'ap_unpaid'=>0,'rfq_open'=>0,'rfq_pending'=>0,'manufactures_active'=>0,'manufactures_total'=>0,'manufactures_new_month'=>0,'gr_mtd'=>0,'ap_3wm'=>0,'po_overdue'=>0,'forwarder_open'=>0,'forwarder_source'=>'MISSING','avg_sla_minutes'=>0.0,'avg_sla_count'=>0,'avg_sla_source'=>'N/A'];
$scopeCtx = ds_scope_ctx();

// Manager PQP dashboard harus membaca agregat ALL sama seperti SYS.
// Ini hanya membuka scope baca pada dashboard PQP; permission/action di halaman operasional tetap mengikuti RBAC masing-masing.
$__pqpDashUser = function_exists('auth_user') ? (array)auth_user() : [];
$__pqpDashDept = strtoupper(trim((string)($__pqpDashUser['department'] ?? $__pqpDashUser['dept'] ?? ($_SESSION['department'] ?? $_SESSION['dept'] ?? ''))));
$__pqpDashRole = strtoupper(trim((string)($__pqpDashUser['role'] ?? ($_SESSION['role'] ?? ''))));
$__pqpDashLevel = strtoupper(trim((string)($__pqpDashUser['level'] ?? ($_SESSION['level'] ?? ''))));
$__pqpDashUsername = strtoupper(trim((string)($__pqpDashUser['username'] ?? ($_SESSION['username'] ?? ''))));
$__pqpDashIsManager = in_array($__pqpDashRole, ['MANAGER','MGR'], true)
    || in_array($__pqpDashLevel, ['MANAGER','MGR'], true)
    || str_starts_with($__pqpDashUsername, 'MGR');
$__pqpDashGlobalScope = ($__pqpDashDept === 'PQP' && $__pqpDashIsManager)
    || (strpos($__pqpDashUsername, 'MGRPQP') !== false);
if ($__pqpDashGlobalScope) {
    $scopeCtx['is_admin'] = true;
    $scopeCtx['office_code'] = '';
}

$scopeOffice = (string)($scopeCtx['office_code'] ?? '');
$scopeOfficeSql = (!$scopeCtx['is_admin'] && $scopeOffice !== '') ? " AND UPPER(COALESCE(office_code,'')) = UPPER(:scope_office)" : '';
$scopeParams = (!$scopeCtx['is_admin'] && $scopeOffice !== '') ? [':scope_office' => $scopeOffice] : [];

// Manager PQP boleh melihat nilai pembelian pada dashboard PQP seperti SYS, tetapi ini tidak membuka aksi pembayaran/edit PO.
$pqpDashboardCanPrice = p_can_view_buy_price() || $__pqpDashGlobalScope;

/**
 * Scope canonical untuk Forwarding = PO IMPORT saja.
 * Prioritas: marker eksplisit PO -> marker PR asal -> fallback purchases_import_control.
 * Marker eksplisit tidak digabung fallback agar PR/PO LOCAL yang punya data stale tidak ikut.
 */
if (!function_exists('pqp_text_import_condition')) {
  function pqp_text_import_condition(string $expr): string {
    $u = "UPPER(TRIM(COALESCE({$expr},'')))";
    return "(({$u} IN ('IMPORT','IMPOR','OVERSEAS','FOREIGN','INTERNATIONAL') OR {$u} LIKE '%IMPORT%' OR {$u} LIKE '%IMPOR%') AND {$u} NOT LIKE '%LOCAL%' AND {$u} NOT LIKE '%LOKAL%')";
  }
}
if (!function_exists('pqp_import_po_scope')) {
  function pqp_import_po_scope(PDO $pdo, string $poAlias='p'): array {
    if (!ds_table_exists($pdo, 'purchases_po')) return ['sql'=>'1=0','source'=>'NO_PURCHASES_PO'];
    $pc = ds_table_columns($pdo, 'purchases_po');

    foreach (['is_import','import_flag','is_overseas'] as $c) {
      if (isset($pc[$c])) return ['sql'=>"COALESCE({$poAlias}.`{$c}`,0)=1", 'source'=>"purchases_po.{$c}"];
    }
    foreach (['purchase_type','po_type','procurement_type','source_type','purchase_source','order_type'] as $c) {
      if (isset($pc[$c])) return ['sql'=>pqp_text_import_condition("{$poAlias}.`{$c}`"), 'source'=>"purchases_po.{$c}"];
    }

    if (isset($pc['pr_id']) && ds_table_exists($pdo, 'wqs_pr')) {
      $prc = ds_table_columns($pdo, 'wqs_pr');
      foreach (['is_import','import_flag','is_overseas'] as $c) {
        if (isset($prc[$c])) {
          return ['sql'=>"EXISTS (SELECT 1 FROM wqs_pr fpr WHERE fpr.id={$poAlias}.pr_id AND COALESCE(fpr.`{$c}`,0)=1)", 'source'=>"wqs_pr.{$c}"];
        }
      }
      foreach (['purchase_type','pr_type','request_type','procurement_type','source_type','purchase_source'] as $c) {
        if (isset($prc[$c])) {
          $cond = pqp_text_import_condition("fpr.`{$c}`");
          return ['sql'=>"EXISTS (SELECT 1 FROM wqs_pr fpr WHERE fpr.id={$poAlias}.pr_id AND {$cond})", 'source'=>"wqs_pr.{$c}"];
        }
      }
    }

    /*
     * Schema produksi saat ini tidak mempunyai marker LOCAL/IMPORT eksplisit.
     * purchases_import_control berisi data legacy PO lokal IDR, jadi row pada tabel
     * tersebut tidak boleh dipakai sendiri sebagai bukti PO import.
     *
     * Fallback canonical schema sekarang: valuta asing = import.
     * Daftar eksplisit membuat IDR/kosong/unknown fail-closed dari Forwarding.
     */
    if (isset($pc['currency'])) {
      return [
        'sql'=>"UPPER(TRIM(COALESCE({$poAlias}.currency,''))) IN ('USD','CNY','RMB','EUR','SGD','JPY','HKD','GBP','AUD')",
        'source'=>'purchases_po.currency'
      ];
    }
    return ['sql'=>'1=0','source'=>'NO_IMPORT_MARKER'];
  }
}

try {
  // PR SUBMITTED yang benar-benar masih menunggu PO aktif.
  // Definisi disamakan dengan stock/wqs_pr.php: deleted PR diabaikan dan PR yang sudah
  // mempunyai PO aktif (direct ID / exact PR code / legacy note) tidak lagi dihitung waiting PO.
  $prWaitingWhere = [
    "pr.deleted_at IS NULL",
    "UPPER(TRIM(COALESCE(pr.status,'')))='SUBMITTED'"
  ];
  $prWaitingParams = [];
  if (!$scopeCtx['is_admin'] && $scopeOffice !== '') {
    $prWaitingWhere[] = "UPPER(COALESCE(pr.office_code,'')) = UPPER(:scope_office_pr)";
    $prWaitingParams[':scope_office_pr'] = $scopeOffice;
  }

  $poLink = [];
  if (ds_table_exists($pdo, 'purchases_po')) {
    $poColsWaiting = ds_table_columns($pdo, 'purchases_po');
    if (isset($poColsWaiting['pr_id']))        $poLink[] = "po.pr_id=pr.id";
    if (isset($poColsWaiting['source_pr_id'])) $poLink[] = "po.source_pr_id=pr.id";
    if (isset($poColsWaiting['wqs_pr_id']))    $poLink[] = "po.wqs_pr_id=pr.id";
    foreach (['pr_code','source_pr_code','wqs_pr_code'] as $c) {
      if (isset($poColsWaiting[$c])) {
        $poLink[] = "TRIM(COALESCE(pr.pr_code,''))<>'' AND UPPER(TRIM(COALESCE(po.`{$c}`,'')))=UPPER(TRIM(COALESCE(pr.pr_code,'')))";
      }
    }
    if (isset($poColsWaiting['note'])) {
      $poLink[] = "TRIM(COALESCE(pr.pr_code,''))<>'' AND INSTR(UPPER(COALESCE(po.note,'')),UPPER(TRIM(pr.pr_code)))>0";
    }
  }
  if ($poLink) {
    $prWaitingWhere[] = "NOT EXISTS (SELECT 1 FROM purchases_po po WHERE (" . implode(' OR ', $poLink) . ") AND po.deleted_at IS NULL AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID'))";
  }
  $st = $pdo->prepare("SELECT COUNT(*) FROM wqs_pr pr WHERE " . implode(' AND ', $prWaitingWhere));
  $st->execute($prWaitingParams);
  $kpi['pr_submitted'] = (int)$st->fetchColumn();

  // List PR aktif harus memakai definisi yang SAMA PERSIS dengan KPI PR SUBMITTED:
  // SUBMITTED + belum deleted + belum mempunyai PO aktif + mengikuti scope office user.
  // Dengan demikian angka kartu dan daftar di bawah Quick Links tidak bisa berbeda definisi.
  $activePrRows = [];
  if ($kpi['pr_submitted'] > 0) {
    $sqlActivePr = "SELECT pr.id, pr.pr_code, pr.office_code, pr.status, pr.submitted_at
                    FROM wqs_pr pr
                    WHERE " . implode(' AND ', $prWaitingWhere) . "
                    ORDER BY pr.submitted_at ASC, pr.id ASC
                    LIMIT 100";
    $stActivePr = $pdo->prepare($sqlActivePr);
    $stActivePr->execute($prWaitingParams);
    $activePrRows = $stActivePr->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // PO open
  if ($scopeOfficeSql !== '') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE deleted_at IS NULL AND status IN ('OPEN','IN_PRODUCTION','READY'){$scopeOfficeSql}");
    $st->execute($scopeParams);
    $kpi['po_open'] = (int)$st->fetchColumn();
  } else {
    $kpi['po_open'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases_po WHERE deleted_at IS NULL AND status IN ('OPEN','IN_PRODUCTION','READY')")->fetchColumn();
  }

  // Avg Durasi SLA PQP (CANONICAL): START = wqs_pr.submitted_at, STOP = purchases_po.created_at.
  // Jangan fallback ke created_at/pr_date karena waktu membuat PR bukan START SLA PQP.
  // Hanya PO aktif/non-cancelled dengan evidence submit PR yang valid yang masuk AVG.
  try {
    if (ds_table_exists($pdo, 'wqs_pr') && ds_table_exists($pdo, 'purchases_po')) {
      $prCols = ds_table_columns($pdo, 'wqs_pr');
      $poColsAvg = ds_table_columns($pdo, 'purchases_po');

      if (isset($prCols['submitted_at']) && isset($poColsAvg['created_at'])) {
        // Samakan resolusi relasi PR→PO dengan logika PR Waiting, tanpa mengubah workflow.
        $avgLinks = [];
        if (isset($poColsAvg['pr_id']))        $avgLinks[] = "po.pr_id=pr.id";
        if (isset($poColsAvg['source_pr_id'])) $avgLinks[] = "po.source_pr_id=pr.id";
        if (isset($poColsAvg['wqs_pr_id']))    $avgLinks[] = "po.wqs_pr_id=pr.id";
        foreach (['pr_code','source_pr_code','wqs_pr_code'] as $c) {
          if (isset($poColsAvg[$c]) && isset($prCols['pr_code'])) {
            $avgLinks[] = "TRIM(COALESCE(pr.pr_code,''))<>'' AND UPPER(TRIM(COALESCE(po.`{$c}`,'')))=UPPER(TRIM(COALESCE(pr.pr_code,'')))";
          }
        }
        if (isset($poColsAvg['note']) && isset($prCols['pr_code'])) {
          $avgLinks[] = "TRIM(COALESCE(pr.pr_code,''))<>'' AND INSTR(UPPER(COALESCE(po.note,'')),UPPER(TRIM(pr.pr_code)))>0";
        }

        if ($avgLinks) {
          // Satu PR dihitung sekali. Jika ada lebih dari satu PO valid yang mereferensikan PR,
          // STOP SLA adalah PO valid pertama yang dibuat setelah submitted_at.
          $poOfficeColAvg = ds_pick_col($poColsAvg, ['office_code','office','branch_code']);
          $officeCond = '';
          $avgParams = [];
          if (!$scopeCtx['is_admin'] && $scopeOffice !== '' && $poOfficeColAvg !== null) {
            $officeCond = " AND UPPER(COALESCE(po.`{$poOfficeColAvg}`,''))=UPPER(?)";
            $avgParams[] = $scopeOffice;
          }

          $sqlAvg = "SELECT AVG(x.sla_seconds)/60 AS avg_min, COUNT(*) AS cnt FROM (" .
                    " SELECT pr.id, MIN(TIMESTAMPDIFF(SECOND, pr.submitted_at, po.created_at)) AS sla_seconds" .
                    " FROM wqs_pr pr INNER JOIN purchases_po po ON (" . implode(' OR ', $avgLinks) . ")" .
                    " WHERE pr.deleted_at IS NULL AND po.deleted_at IS NULL" .
                    " AND pr.submitted_at IS NOT NULL AND po.created_at IS NOT NULL" .
                    " AND po.created_at >= pr.submitted_at" .
                    " AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')" .
                    $officeCond .
                    " GROUP BY pr.id" .
                    ") x";

          $stAvg = $pdo->prepare($sqlAvg);
          $stAvg->execute($avgParams);
          $avgRow = $stAvg->fetch(PDO::FETCH_ASSOC) ?: [];
          $kpi['avg_sla_minutes'] = ($avgRow['avg_min'] !== null) ? (float)$avgRow['avg_min'] : 0.0;
          $kpi['avg_sla_count'] = (int)($avgRow['cnt'] ?? 0);
          $kpi['avg_sla_source'] = 'SUBMITTED_AT → PO.CREATED_AT';
        }
      }
    }
  } catch (Throwable $e) {}

  // PO value this month (only if can view buy price)
  $canPrice = $pqpDashboardCanPrice;
  if ($canPrice) {
    $ym = date('Y-m');
    $sql = "SELECT COALESCE(SUM(total_amount),0) FROM purchases_po WHERE deleted_at IS NULL AND DATE_FORMAT(po_date,'%Y-%m')=:ym";
    if ($scopeOfficeSql !== '') {
      $sql .= $scopeOfficeSql;
    }
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([':ym'=>$ym], $scopeParams));
    $kpi['po_value_month'] = (float)$st->fetchColumn();
  }

  // AP outstanding (purchases_invoice_ap: UNPAID/PARTIAL, outstanding = total - paid)
  $apWhere = "ap.deleted_at IS NULL AND ap.status IN ('UNPAID','PARTIAL')";
  $apOfficeSql = ($scopeOfficeSql !== '' && strpos($scopeOfficeSql, 'office_code') !== false)
    ? str_replace('office_code', 'ap.office_code', $scopeOfficeSql) : '';
  if ($apOfficeSql !== '') {
    $st = $pdo->prepare("
      SELECT COUNT(*) FROM purchases_invoice_ap ap
      WHERE {$apWhere}{$apOfficeSql}
    ");
    $st->execute($scopeParams);
    $kpi['ap_outstanding'] = (int)$st->fetchColumn();
  } else {
    $kpi['ap_outstanding'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases_invoice_ap ap WHERE {$apWhere}")->fetchColumn();
  }

  // unpaid amount (total - paid)
  if ($canPrice) {
    $apSumSql = "
      SELECT COALESCE(SUM(ap.total_amount - COALESCE(paid.paid_amount,0)),0)
      FROM purchases_invoice_ap ap
      LEFT JOIN (SELECT ap_id, SUM(amount) paid_amount FROM purchases_payment_ap WHERE deleted_at IS NULL GROUP BY ap_id) paid ON paid.ap_id = ap.id
      WHERE {$apWhere}
    ";
    if ($apOfficeSql !== '') {
      $st = $pdo->prepare($apSumSql . $apOfficeSql);
      $st->execute($scopeParams);
      $kpi['ap_unpaid'] = (float)$st->fetchColumn();
    } else {
      $kpi['ap_unpaid'] = (float)$pdo->query($apSumSql)->fetchColumn();
    }
  }

  // Manufactures count — exclude internal "Kantor/Depo" entries
  try {
    $kpi['manufactures_active']    = (int)$pdo->query("SELECT COUNT(*) FROM master_manufactures WHERE status=1 AND manufacture_name NOT LIKE 'Kantor%'")->fetchColumn();
    $kpi['manufactures_total']     = (int)$pdo->query("SELECT COUNT(*) FROM master_manufactures WHERE manufacture_name NOT LIKE 'Kantor%'")->fetchColumn();
    $kpi['manufactures_new_month'] = (int)$pdo->query("SELECT COUNT(*) FROM master_manufactures WHERE manufacture_name NOT LIKE 'Kantor%' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')")->fetchColumn();
  } catch (Exception $e) { /* silent */ }

  // RFQ KPI — scoped jika tabel menyediakan office_code.
  // RFQ Open = seluruh RFQ aktif. RFQ Pending adalah subset RFQ Open yang belum punya quotation submitted.
  try {
    if (ds_table_exists($pdo, 'pqp_rfq')) {
      $rfqCols = ds_table_columns($pdo, 'pqp_rfq');
      $rfqOfficeCol = ds_pick_col($rfqCols, ['office_code','office','branch_code']);
      $rfqWhere = "LOWER(COALESCE(r.status,''))='open'";
      $rfqParams = [];
      if (!$scopeCtx['is_admin'] && $scopeOffice !== '' && $rfqOfficeCol !== null) {
        $rfqWhere .= " AND UPPER(COALESCE(r.`{$rfqOfficeCol}`,''))=UPPER(?)";
        $rfqParams[] = $scopeOffice;
      }
      $st = $pdo->prepare("SELECT COUNT(*) FROM pqp_rfq r WHERE {$rfqWhere}");
      $st->execute($rfqParams);
      $kpi['rfq_open'] = (int)$st->fetchColumn();

      if (ds_table_exists($pdo, 'pqp_rfq_quotations')) {
        $st = $pdo->prepare("
          SELECT COUNT(*) FROM pqp_rfq r
          WHERE {$rfqWhere}
            AND NOT EXISTS (
              SELECT 1 FROM pqp_rfq_quotations q
              WHERE q.rfq_id=r.id AND LOWER(COALESCE(q.status,''))='submitted'
            )
        ");
        $st->execute($rfqParams);
        $kpi['rfq_pending'] = (int)$st->fetchColumn();
      }
    }
  } catch (Throwable $e) {}

  // GR this month — barang yang benar-benar diterima WQS pada periode berjalan.
  try {
    if (ds_table_exists($pdo, 'wqs_incoming')) {
      $grCols = ds_table_columns($pdo, 'wqs_incoming');
      $grDateCol = ds_pick_col($grCols, ['received_date','received_at','gr_date','created_at']);
      $grOfficeCol = ds_pick_col($grCols, ['office_code','office','branch_code']);
      if ($grDateCol !== null) {
        $sql = "SELECT COUNT(*) FROM wqs_incoming WHERE DATE_FORMAT(`{$grDateCol}`,'%Y-%m')=?";
        $params = [date('Y-m')];
        if (!$scopeCtx['is_admin'] && $scopeOffice !== '' && $grOfficeCol !== null) {
          $sql .= " AND UPPER(COALESCE(`{$grOfficeCol}`,''))=UPPER(?)";
          $params[] = $scopeOffice;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $kpi['gr_mtd'] = (int)$st->fetchColumn();
      }
    }
  } catch (Throwable $e) {}

  // NEW: AP HOLD_3WM
  try {
    $kpi['ap_3wm'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases_invoice_ap WHERE deleted_at IS NULL AND status='HOLD_3WM'")->fetchColumn();
  } catch (Throwable $e) {}

  // PO Overdue / SLA — hanya PO yang masih benar-benar outstanding.
  // Prioritas target: ETA Import Control -> lead time quote forwarder SELECTED -> fallback 60 hari.
  // PO yang qty-nya sudah seluruhnya diterima WQS tidak dihitung overdue walaupun header belum CLOSED.
  try {
    if (ds_table_exists($pdo, 'purchases_po')) {
      $poCols = ds_table_columns($pdo, 'purchases_po');
      $poOfficeCol = ds_pick_col($poCols, ['office_code','office','branch_code']);

      $joins = [];
      $dueCases = [];

      if (ds_table_exists($pdo, 'purchases_import_control')) {
        $icCols = ds_table_columns($pdo, 'purchases_import_control');
        if (isset($icCols['po_id'])) {
          $joins[] = "LEFT JOIN purchases_import_control ic ON ic.po_id=p.id";
          if (isset($icCols['eta'])) {
            $arrivedCond = isset($icCols['arrived_warehouse_date'])
              ? "ic.arrived_warehouse_date IS NULL"
              : "1=1";
            $dueCases[] = "(ic.eta IS NOT NULL AND DATE(ic.eta)<CURDATE() AND {$arrivedCond})";
          }
        }
      }

      if (ds_table_exists($pdo, 'purchases_forwarder_quotes')) {
        $qCols = ds_table_columns($pdo, 'purchases_forwarder_quotes');
        if (isset($qCols['po_id']) && isset($qCols['status']) && isset($qCols['quote_date']) && isset($qCols['leadtime_days'])) {
          $qDeleted = isset($qCols['deleted_at']) ? " AND q.deleted_at IS NULL" : '';
          $dueCases[] = "EXISTS (SELECT 1 FROM purchases_forwarder_quotes q WHERE q.po_id=p.id AND UPPER(COALESCE(q.status,''))='SELECTED' AND COALESCE(q.leadtime_days,0)>0 AND DATE_ADD(DATE(q.quote_date), INTERVAL q.leadtime_days DAY)<CURDATE(){$qDeleted})";
        }
      }

      // Fallback untuk PO yang belum memiliki ETA/lead time spesifik.
      $specificTargetExists = [];
      if (ds_table_exists($pdo, 'purchases_import_control')) {
        $icCols2 = ds_table_columns($pdo, 'purchases_import_control');
        if (isset($icCols2['po_id']) && isset($icCols2['eta'])) $specificTargetExists[] = "ic.eta IS NOT NULL";
      }
      if (ds_table_exists($pdo, 'purchases_forwarder_quotes')) {
        $qCols2 = ds_table_columns($pdo, 'purchases_forwarder_quotes');
        if (isset($qCols2['po_id']) && isset($qCols2['status']) && isset($qCols2['quote_date']) && isset($qCols2['leadtime_days'])) {
          $qDeleted2 = isset($qCols2['deleted_at']) ? " AND q2.deleted_at IS NULL" : '';
          $specificTargetExists[] = "EXISTS (SELECT 1 FROM purchases_forwarder_quotes q2 WHERE q2.po_id=p.id AND UPPER(COALESCE(q2.status,''))='SELECTED' AND COALESCE(q2.leadtime_days,0)>0{$qDeleted2})";
        }
      }
      $noSpecificTarget = $specificTargetExists ? 'NOT (' . implode(' OR ', $specificTargetExists) . ')' : '1=1';
      $dueCases[] = "({$noSpecificTarget} AND DATE(p.po_date)<=DATE_SUB(CURDATE(),INTERVAL 60 DAY))";

      // Outstanding qty: PO harus masih memiliki item yang belum diterima penuh.
      $outstandingSql = '1=1';
      if (ds_table_exists($pdo, 'purchases_po_items') && ds_table_exists($pdo, 'wqs_incoming') && ds_table_exists($pdo, 'wqs_incoming_items')) {
        $piCols = ds_table_columns($pdo, 'purchases_po_items');
        $wiCols = ds_table_columns($pdo, 'wqs_incoming');
        $wiiCols = ds_table_columns($pdo, 'wqs_incoming_items');
        if (isset($piCols['po_id']) && isset($piCols['qty']) && isset($wiiCols['incoming_id']) && isset($wiiCols['qty']) && isset($wiCols['id']) && isset($wiCols['po_id'])) {
          $piDel = isset($piCols['deleted_at']) ? " AND (pi.deleted_at IS NULL OR pi.deleted_at='0000-00-00 00:00:00')" : '';
          $wiDel = isset($wiCols['deleted_at']) ? " AND wi.deleted_at IS NULL" : '';
          // Agregasi per PO; tidak bergantung SKU sehingga aman untuk dashboard summary.
          $outstandingSql = "COALESCE((SELECT SUM(pi.qty) FROM purchases_po_items pi WHERE pi.po_id=p.id{$piDel}),0) > COALESCE((SELECT SUM(wii.qty) FROM wqs_incoming_items wii JOIN wqs_incoming wi ON wi.id=wii.incoming_id WHERE wi.po_id=p.id{$wiDel}),0)";
        }
      }

      $sql = "SELECT COUNT(DISTINCT p.id) FROM purchases_po p " . implode(' ', $joins) . " WHERE p.deleted_at IS NULL AND UPPER(COALESCE(p.status,'')) IN ('OPEN','IN_PRODUCTION','READY') AND ({$outstandingSql}) AND (" . implode(' OR ', $dueCases) . ")";
      $params = [];
      if (!$scopeCtx['is_admin'] && $scopeOffice !== '' && $poOfficeCol !== null) {
        $sql .= " AND UPPER(COALESCE(p.`{$poOfficeCol}`,''))=UPPER(?)";
        $params[] = $scopeOffice;
      }
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $kpi['po_overdue'] = (int)$st->fetchColumn();
    }
  } catch (Throwable $e) {}

  // Forwarding Open — HANYA PO IMPORT yang memang punya proses forwarding aktif.
  // PR/PO LOCAL tidak boleh masuk walaupun ada data legacy/stale pada field forwarder.
  try {
    if (ds_table_exists($pdo, 'purchases_po')) {
      $pc = ds_table_columns($pdo, 'purchases_po');
      $fwdStatus = ds_pick_col($pc, ['forwarder_status','forwarding_status']);
      $fwdVendor = ds_pick_col($pc, ['forwarder_vendor_id','forwarding_vendor_id']);
      $poStatus = ds_pick_col($pc, ['status','po_status']);
      $poOffice = ds_pick_col($pc, ['office_code','office','branch_code']);
      $importScope = pqp_import_po_scope($pdo, 'p');
      $evidence = [];

      // Status PENDING/IN_PROGRESS hanya sah setelah PO lolos scope IMPORT.
      if ($fwdStatus !== null) {
        $evidence[] = "UPPER(COALESCE(p.`{$fwdStatus}`,'')) IN ('PENDING','IN_PROGRESS')";
      }
      if ($fwdVendor !== null) $evidence[] = "p.`{$fwdVendor}` IS NOT NULL";

      if (ds_table_exists($pdo, 'purchases_forwarder_quotes')) {
        $qc = ds_table_columns($pdo, 'purchases_forwarder_quotes');
        if (isset($qc['po_id']) && isset($qc['status'])) {
          $qDel = isset($qc['deleted_at']) ? " AND q.deleted_at IS NULL" : '';
          $evidence[] = "EXISTS (SELECT 1 FROM purchases_forwarder_quotes q WHERE q.po_id=p.id AND UPPER(COALESCE(q.status,''))='SELECTED'{$qDel})";
        }
      }
      if (ds_table_exists($pdo, 'purchases_forwarding_docs')) {
        $dc = ds_table_columns($pdo, 'purchases_forwarding_docs');
        if (isset($dc['po_id'])) {
          $dDel = isset($dc['deleted_at']) ? " AND fd.deleted_at IS NULL" : '';
          $evidence[] = "EXISTS (SELECT 1 FROM purchases_forwarding_docs fd WHERE fd.po_id=p.id{$dDel})";
        }
      }
      if (ds_table_exists($pdo, 'purchases_import_control')) {
        $ic = ds_table_columns($pdo, 'purchases_import_control');
        if (isset($ic['po_id'])) {
          // Hanya milestone logistik/pengiriman. Production start/done tidak otomatis berarti forwarding aktif.
          $milestones = [];
          foreach (['pickup_date','etd','eta','arrived_id_date'] as $c) {
            if (isset($ic[$c])) $milestones[] = "ic.`{$c}` IS NOT NULL";
          }
          $notWarehouse = isset($ic['arrived_warehouse_date']) ? " AND ic.arrived_warehouse_date IS NULL" : '';
          if ($milestones) $evidence[] = "EXISTS (SELECT 1 FROM purchases_import_control ic WHERE ic.po_id=p.id AND (" . implode(' OR ', $milestones) . "){$notWarehouse})";
        }
      }

      if ($evidence) {
        $sql = "SELECT COUNT(DISTINCT p.id) FROM purchases_po p WHERE (" . $importScope['sql'] . ") AND (" . implode(' OR ', $evidence) . ")";
        if (isset($pc['deleted_at'])) $sql .= " AND p.deleted_at IS NULL";
        if ($poStatus !== null) $sql .= " AND UPPER(COALESCE(p.`{$poStatus}`,'')) NOT IN ('DONE','CLOSED','CANCELLED','CANCELED','COMPLETED','DELIVERED','PAID','VOID')";
        if ($fwdStatus !== null) $sql .= " AND UPPER(COALESCE(p.`{$fwdStatus}`,'')) NOT IN ('DONE','CLOSED','CANCELLED','CANCELED','COMPLETED','DELIVERED')";

        // Jika barang sudah ARRIVED WAREHOUSE, tugas forwarding secara bisnis selesai
        // walaupun header PO/forwarder legacy belum ditutup.
        if (ds_table_exists($pdo, 'purchases_import_control')) {
          $icDoneCols = ds_table_columns($pdo, 'purchases_import_control');
          if (isset($icDoneCols['po_id']) && isset($icDoneCols['arrived_warehouse_date'])) {
            $sql .= " AND NOT EXISTS (
              SELECT 1 FROM purchases_import_control ic_done
              WHERE ic_done.po_id=p.id
                AND ic_done.arrived_warehouse_date IS NOT NULL
            )";
          }
        }

        $params = [];
        if (!$scopeCtx['is_admin'] && $scopeOffice !== '' && $poOffice !== null) {
          $sql .= " AND UPPER(COALESCE(p.`{$poOffice}`,''))=UPPER(?)";
          $params[] = $scopeOffice;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $kpi['forwarder_open'] = (int)$st->fetchColumn();
        $kpi['forwarder_source'] = 'AVAILABLE';
      } else {
        $kpi['forwarder_open'] = 0;
        $kpi['forwarder_source'] = 'AVAILABLE';
      }
    }
  } catch (Throwable $e) {
    $kpi['forwarder_source'] = 'ERROR';
  }

} catch(Exception $e) {
  // silent - dashboard tetap render
}

if (!function_exists('pqp_duration_label')) {
  function pqp_duration_label(float $minutes): string {
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
}

// Manager Controlling PQP: backlog adalah pekerjaan aktif lintas tahap, bukan hanya PO.
// RFQ Pending adalah subset RFQ Open sehingga tidak dijumlahkan dua kali.
$managerBacklogPQP = (int)$kpi['pr_submitted'] + (int)$kpi['rfq_open'] + (int)$kpi['po_open'];

// Exception = kondisi yang memerlukan intervensi, bukan seluruh AP outstanding.
$managerExceptionsPQP = (int)$kpi['po_overdue'] + (int)$kpi['ap_3wm'];

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';

$actions = [
  ['label'=>'RFQ (Request Quotation)', 'url'=>'pqp_rfq.php',   'class'=>'btn btn-sm btn-rmi'],
  ['label'=>'PO',                      'url'=>'purchases_po.php','class'=>'btn btn-sm btn-rmi'],
  ['label'=>'Local Tower',             'url'=>'purchases_control_tower.php','class'=>'btn btn-sm btn-outline-info'],
  ['label'=>'Import Tower',            'url'=>'purchases_import_control_tower.php','class'=>'btn btn-sm btn-outline-warning'],
  ['label'=>rmi_icon('receipt').' Pembelian Aset',       'url'=>'../Fixed_Asset/assets.php#asset-purchase-flow','class'=>'btn btn-sm btn-outline-light'],
  ['label'=>rmi_icon('books').' Panduan',              'url'=>'panduan.php',    'class'=>'btn btn-sm btn-outline-light'],
  ['label'=>rmi_icon('receipt').' Audit Log',            'url'=>'../master/audit_logs.php','class'=>'btn btn-sm btn-outline-light'],
];

$extraHeadPQP = <<<'STYLE'
<style>
.pqp-header{background:linear-gradient(135deg,rgba(76,29,149,.8),rgba(109,40,217,.6));border:1px solid rgba(139,92,246,.3);border-radius:18px;padding:20px 24px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.pqp-header h2{margin:0;font-size:20px;font-weight:800;color:#fff}
.pqp-header p{margin:4px 0 0;font-size:12px;color:rgba(255,255,255,.65)}
.pqp-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px}
.pqp-k{padding:14px;border-radius:14px;background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-top:3px solid var(--kc);display:block;color:inherit;text-decoration:none;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
.pqp-k[href]{cursor:pointer}
.pqp-k[href]:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.28);border-color:rgba(255,255,255,.18);color:inherit;text-decoration:none}
.pqp-k[href]:focus-visible{outline:2px solid #60a5fa;outline-offset:2px}
.pqp-k-icon{font-size:20px;margin-bottom:6px}
.pqp-k-n{font-size:24px;font-weight:800;color:#fff}
.pqp-k-n.sm{font-size:15px}
.pqp-k-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-top:3px}
.pqp-k-sub{font-size:10px;color:var(--rmi-muted,#94a3b8);margin-top:2px}
.pqp-links{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}
.pqp-link{padding:7px 13px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid rgba(255,255,255,.12);color:var(--rmi-text,#e2e8f0);background:rgba(255,255,255,.06);transition:all .2s;display:inline-flex;align-items:center;gap:5px}
.pqp-link:hover{background:rgba(255,255,255,.14);color:var(--rmi-text,#fff)}
.pqp-link.primary{background:linear-gradient(135deg,#7c3aed,#8b5cf6);border-color:transparent;color:#fff}
</style>
STYLE;

rmi_header('PQP Dashboard', [
  'active'     => 'purchases',
  'subtitle'   => 'Flow: WQS PR → PQP PO → FIN AP/Payment → WQS Incoming',
  'breadcrumbs'=> [['label'=>'PQP', 'url'=>'index.php'], 'Dashboard'],
  'actions'    => $actions,
  'extra_head' => $extraHeadPQP,
]);
?>

<?php
// Manager Controlling Staff sengaja tidak ditampilkan pada dashboard PQP.
// KPI utama di bawah tetap memakai sumber data dan logika yang sama.
?>

<!-- PQP Header -->
<div class="pqp-header">
  <div>
    <h2><?= rmi_icon('cart') ?> PQP Dashboard</h2>
    <p>Flow: WQS PR → PQP RFQ/PO → FIN AP/Payment → WQS Incoming
      &nbsp;·&nbsp; Buy price: <?= $pqpDashboardCanPrice ? '<span style="color:#4ade80;font-weight:700">Visible</span>' : '<span style="color:#94a3b8">Restricted</span>' ?>
    </p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="pqp-link" style="background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.15);color:var(--rmi-text,#e2e8f0)" href="<?= rmi_h($baseProject . '/dashboards/index.php') ?>"><?= rmi_icon('home') ?> Home</a>
  </div>
</div>

<!-- KPI Cards -->
<div class="pqp-kpi">
  <a class="pqp-k" href="<?= rmi_h($baseProject . '/stock/wqs_pr.php?status=SUBMITTED_WAITING_PO') ?>" style="--kc:#f97316">
    <div class="pqp-k-icon"><?= rmi_icon('memo') ?></div>
    <div class="pqp-k-n"><?= (int)$kpi['pr_submitted'] ?></div>
    <div class="pqp-k-lbl">PR Submitted</div>
    <div class="pqp-k-sub">Menunggu dibuat PO</div>
  </a>
  <a class="pqp-k" href="purchases_po.php" style="--kc:#3b82f6">
    <div class="pqp-k-icon"><?= rmi_icon('cart') ?></div>
    <div class="pqp-k-n"><?= (int)$kpi['po_open'] ?></div>
    <div class="pqp-k-lbl">PO Open</div>
    <div class="pqp-k-sub">On progress</div>
  </a>
  <a class="pqp-k" href="purchases_po.php" style="--kc:#22c55e">
    <div class="pqp-k-icon"><?= rmi_icon('money') ?></div>
    <div class="pqp-k-n sm"><?= $pqpDashboardCanPrice ? 'Rp '.number_format((float)$kpi['po_value_month'],0,',','.') : '—' ?></div>
    <div class="pqp-k-lbl">PO Value MTD</div>
    <div class="pqp-k-sub">Bulan ini</div>
  </a>
  <!-- NEW: PO Overdue -->
  <a class="pqp-k" href="purchases_po.php?overdue=1" style="--kc:<?= $kpi['po_overdue']>0?'#f97316':'#64748b' ?>">
    <div class="pqp-k-icon"><?= rmi_icon('calendar') ?></div>
    <div class="pqp-k-n" style="color:<?= $kpi['po_overdue']>0?'#fb923c':'#fff' ?>"><?= (int)$kpi['po_overdue'] ?></div>
    <div class="pqp-k-lbl">PO Overdue / Delivery</div>
    <div class="pqp-k-sub"><?= $kpi['po_overdue']>0?rmi_icon('warn').' Lewat ETA / lead time / batas 60 hari':rmi_icon('tick').' Semua on track' ?></div>
  </a>

  <!-- Avg Durasi SLA PQP -->
  <a class="pqp-k" href="purchases_po.php" style="--kc:#06b6d4">
    <div class="pqp-k-icon"><?= rmi_icon('calendar') ?></div>
    <div class="pqp-k-n sm" style="color:#67e8f9"><?= (int)$kpi['avg_sla_count'] > 0 ? rmi_h(pqp_duration_label((float)$kpi['avg_sla_minutes'])) : '—' ?></div>
    <div class="pqp-k-lbl">Avg Durasi SLA PQP</div>
    <div class="pqp-k-sub"><?= (int)$kpi['avg_sla_count'] > 0 ? 'PR Submit → PO Created · n='.(int)$kpi['avg_sla_count'] : 'Belum ada SLA selesai' ?></div>
  </a>

  <!-- NEW: AP HOLD_3WM -->
  <?php if ($kpi['ap_3wm'] > 0): ?>
  <a class="pqp-k" href="purchases_invoice_ap.php" style="--kc:#f59e0b">
    <div class="pqp-k-icon"><?= rmi_icon('search') ?></div>
    <div class="pqp-k-n" style="color:#fbbf24"><?= (int)$kpi['ap_3wm'] ?></div>
    <div class="pqp-k-lbl">HOLD 3WM</div>
    <div class="pqp-k-sub">3-Way Match pending</div>
  </a>
  <?php endif; ?></div>

<!-- Alerts -->
<?php if ($kpi['po_overdue'] > 0): ?>
<div style="background:rgba(249,115,22,.1);border:1px solid rgba(249,115,22,.3);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:13px">
  <span style="font-size:18px"><?= rmi_icon('calendar') ?></span>
  <span><strong style="color:#fb923c"><?= $kpi['po_overdue'] ?> PO overdue pengadaan</strong>
  <span style="color:#94a3b8"> — melewati ETA / lead time / batas fallback 60 hari; perlu follow-up ke supplier</span></span>
  <a href="purchases_po.php?overdue=1" style="margin-left:auto;color:#fb923c;font-size:11px;text-decoration:none">Lihat PO Overdue →</a>
</div>
<?php endif; ?>
<?php if ($kpi['ap_3wm'] > 0): ?>
<div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:13px">
  <span style="font-size:18px"><?= rmi_icon('search') ?></span>
  <span><strong style="color:#fbbf24"><?= $kpi['ap_3wm'] ?> Invoice HOLD 3-Way Match</strong>
  <span style="color:#94a3b8"> — perlu validasi PO/GR/Invoice</span></span>
  <a href="purchases_invoice_ap.php" style="margin-left:auto;color:#fbbf24;font-size:11px;text-decoration:none">Review →</a>
</div>
<?php endif; ?>

<!-- Quick Links -->
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-3"><?= rmi_icon('zap') ?> Quick Links — PQP</div>
  <div class="pqp-links">
    <a class="pqp-link primary" href="pqp_rfq.php"><?= rmi_icon('clipboard') ?> RFQ</a>
    <a class="pqp-link primary" href="purchases_po.php"><?= rmi_icon('cart') ?> Purchase Order</a>
    <a class="pqp-link primary" href="purchases_gr.php"><?= rmi_icon('check') ?> Good Receipt (GR)</a>
    <a class="pqp-link primary" href="purchases_control_tower.php"><?= rmi_icon('home') ?> Local Purchase Control Tower</a>
    <a class="pqp-link" href="purchases_import_control_tower.php"><?= rmi_icon('outbox') ?> Import Control Tower</a>
    <a class="pqp-link primary" href="<?= rmi_h($baseProject . '/Fixed_Asset/assets.php#asset-purchase-flow') ?>"><?= rmi_icon('receipt') ?> Pembelian Aset</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/master/master_products.php') ?>"><?= rmi_icon('box') ?> Master Products</a>
    <a class="pqp-link" href="purchases_invoice_ap.php"><?= rmi_icon('money') ?> AP Invoice</a>
    <a class="pqp-link" href="purchases_payment_ap.php"><?= rmi_icon('money') ?> AP Payment</a>
    <a class="pqp-link" href="purchases_reports.php"><?= rmi_icon('chart') ?> Purchases Reports</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/master/audit_logs.php') ?>"><?= rmi_icon('receipt') ?> Audit Log</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/stock/wqs_pr.php') ?>"><?= rmi_icon('memo') ?> PR (WQS)</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/hrl_process/index.php') ?>"><?= rmi_icon('clipboard') ?> HRL Process</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/absensi/index.php') ?>"><?= rmi_icon('calendar') ?> Absensi</a>
    <a class="pqp-link" href="<?= rmi_h($baseProject . '/kpi/kpi_center.php') ?>"><?= rmi_icon('trend') ?> KPI Center</a>
  </div>
</div>

<?php if (!empty($activePrRows)): ?>
<!-- PR AKTIF — sumber dan definisi identik dengan KPI PR SUBMITTED -->
<section class="rmi-card p-3 mb-3">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
    <div class="fw-semibold"><?= rmi_icon('memo') ?> PR Aktif — Menunggu Dibuat PO</div>
    <span style="font-size:12px;color:#94a3b8"><?= (int)$kpi['pr_submitted'] ?> PR</span>
    <a href="<?= rmi_h($baseProject . '/stock/wqs_pr.php') ?>"
       style="margin-left:auto;color:#60a5fa;font-size:12px;text-decoration:none">Lihat semua →</a>
  </div>

  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead>
        <tr style="color:#94a3b8;text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
          <th style="padding:9px 10px">PR</th>
          <th style="padding:9px 10px">OFFICE</th>
          <th style="padding:9px 10px">STATUS</th>
          <th style="padding:9px 10px">SUBMITTED</th>
          <th style="padding:9px 10px;text-align:right">AKSI</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($activePrRows as $prRow): ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:10px;font-weight:700;color:var(--rmi-text,#e2e8f0)">
            <?= rmi_h((string)($prRow['pr_code'] ?? ('PR#'.($prRow['id'] ?? '')))) ?>
          </td>
          <td style="padding:10px;color:var(--rmi-text,#cbd5e1)"><?= rmi_h((string)($prRow['office_code'] ?? '-')) ?></td>
          <td style="padding:10px">
            <span style="display:inline-block;padding:3px 8px;border-radius:999px;background:rgba(249,115,22,.12);color:#fb923c;font-weight:700">
              <?= rmi_h((string)($prRow['status'] ?? 'SUBMITTED')) ?>
            </span>
          </td>
          <td style="padding:10px;color:#94a3b8">
            <?= !empty($prRow['submitted_at']) ? rmi_h(date('d-m-Y H:i', strtotime((string)$prRow['submitted_at']))) : '-' ?>
          </td>
          <td style="padding:10px;text-align:right">
            <a href="<?= rmi_h($baseProject . '/stock/wqs_pr_view.php?id=' . (int)($prRow['id'] ?? 0)) ?>"
               style="color:#60a5fa;text-decoration:none;font-weight:600">Buka →</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>


<!-- =========================================================
     AUDIT LOG TERBARU — PQP / PURCHASES
     Read-only. Sumber canonical: purchases_audit_log.
     ========================================================= -->
<?php
$pqpAuditRows = [];
try {
    // Sumber canonical PQP yang sudah dipakai alur purchases operasional.
    // Jangan membaca purchases_audit: tabel tersebut bukan sumber log transaksi PQP aktif.
    if (ds_table_exists($pdo, 'purchases_audit_log')) {
        $sqlAudit = "SELECT id, module, ref_code, action,
                            details_json, user_name, user_role, user_level, created_at
                     FROM purchases_audit_log
                     WHERE UPPER(TRIM(COALESCE(module,''))) REGEXP '^(PQP|PURCHASE|PURCHASES|PR|RFQ|PO|FORWARDING|GR|AP|PAY|FAP|FAP_PAY|IMPORT_DOC|IMPORT_CTRL|CEISA|CEISA_DOC|PIB_PAY|FWD_QUOTE)$'
                     ORDER BY created_at DESC, id DESC
                     LIMIT 12";
        $pqpAuditRows = $pdo->query($sqlAudit)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    // Dashboard harus tetap render jika audit source bermasalah.
    $pqpAuditRows = [];
}

if (!function_exists('pqp_audit_detail')) {
    function pqp_audit_detail($raw): string {
        $raw = trim((string)$raw);
        if ($raw === '') return '';
        $j = json_decode($raw, true);
        if (!is_array($j)) return $raw;
        foreach (['description','detail','message','note','notes'] as $k) {
            if (isset($j[$k]) && !is_array($j[$k]) && trim((string)$j[$k]) !== '') return trim((string)$j[$k]);
        }
        // Jangan menjembreng JSON besar; tampilkan ringkasan field yang berguna.
        $parts = [];
        foreach ($j as $k=>$v) {
            if (is_scalar($v) && $v !== '' && $v !== null) $parts[] = $k.'='.$v;
            if (count($parts) >= 4) break;
        }
        return implode(' · ', $parts);
    }
}
?>
<section class="rmi-card p-3 mb-3" style="margin-top:18px">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px">
    <div class="fw-semibold">
      <?= rmi_icon('clipboard') ?> Audit Log Terbaru
      <span style="font-size:12px;font-weight:400;opacity:.55;margin-left:8px">PQP, PURCHASE, PR, RFQ, PO, FORWARDING, GR, AP</span>
    </div>
    <a href="<?= rmi_h($baseProject . '/master/audit_logs.php') ?>" style="font-size:12px;text-decoration:none">Lihat semua →</a>
  </div>

  <?php if ($pqpAuditRows): ?>
    <div style="display:grid;gap:8px">
      <?php foreach ($pqpAuditRows as $log):
        $action = strtoupper(trim((string)($log['action'] ?? 'LOG')));
        $module = trim((string)($log['module'] ?? ''));
        $ref    = trim((string)($log['ref_code'] ?? ''));
        $user   = trim((string)($log['user_name'] ?? ''));
        $role   = trim((string)($log['user_role'] ?? ''));
        $level  = trim((string)($log['user_level'] ?? ''));
        $time   = trim((string)($log['created_at'] ?? ''));
        $detail = pqp_audit_detail($log['details_json'] ?? '');
      ?>
        <div style="border:1px solid rgba(148,163,184,.16);background:rgba(255,255,255,.035);border-radius:10px;padding:11px 13px;display:flex;justify-content:space-between;gap:16px;align-items:flex-start">
          <div style="min-width:0">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <span style="font-weight:800;color:#34d399"><?= rmi_h($action ?: 'LOG') ?></span>
              <?php if ($module !== ''): ?><span style="opacity:.58;font-size:12px"><?= rmi_h($module) ?></span><?php endif; ?>
              <?php if ($ref !== ''): ?><span style="font-family:monospace"><?= rmi_h($ref) ?></span><?php endif; ?>
            </div>
            <?php if ($detail !== ''): ?><div style="margin-top:6px;opacity:.82"><?= rmi_h($detail) ?></div><?php endif; ?>
          </div>
          <div style="text-align:right;white-space:nowrap;font-size:12px;opacity:.7">
            <?php if ($user !== ''): ?><div><?= rmi_h($user) ?></div><?php endif; ?>
            <?php if ($role !== '' || $level !== ''): ?>
              <div style="opacity:.75"><?= rmi_h(trim($role . ($role !== '' && $level !== '' ? ' · ' : '') . $level)) ?></div>
            <?php endif; ?>
            <?php if ($time !== ''): ?><div><?= rmi_h($time) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div style="padding:16px;border:1px dashed rgba(148,163,184,.25);border-radius:10px;opacity:.65">
      Belum ada Audit Log PQP pada purchases_audit_log.
    </div>
  <?php endif; ?>
</section>

<?php rmi_footer(); ?>
