<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

// Page access override khusus Manager Finance.
// Tujuan: Mgr FIN dapat membuka Control Tower untuk monitoring/filter pembayaran,
// tanpa otomatis memperoleh hak mutasi PO (close/cancel/edit tetap dijaga di handler masing-masing).
$__pageUser = function_exists('auth_user') ? (array)auth_user() : [];
$__pageDept = strtoupper(trim((string)(
    $__pageUser['department']
    ?? $__pageUser['dept']
    ?? $__pageUser['dept_code']
    ?? ($_SESSION['department'] ?? ($_SESSION['dept'] ?? ($_SESSION['dept_code'] ?? '')))
)));
$__pageRole = strtoupper(trim((string)(
    $__pageUser['role']
    ?? ($_SESSION['role'] ?? '')
)));
$__pageLevel = strtoupper(trim((string)(
    $__pageUser['level']
    ?? ($_SESSION['level'] ?? '')
)));
$__pageUsername = strtoupper(trim((string)(
    $__pageUser['username']
    ?? ($_SESSION['username'] ?? ($_SESSION['user_name'] ?? ''))
)));

$__pageIsMgrFin = in_array($__pageDept, ['FIN','FINANCE'], true)
               && (
                    in_array($__pageRole, ['MANAGER','MGR'], true)
                    || in_array($__pageLevel, ['MANAGER','MGR'], true)
                    || str_starts_with($__pageUsername, 'MGRFIN')
                    || strpos($__pageUsername, 'MGR_FIN') !== false
                  );

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_login();

if (function_exists('require_any_permission')) {
    $allowed = $__pageIsMgrFin || (function_exists('can_any') && can_any([
        'PURCHASES.PO_VIEW',
        'PURCHASES.IMPORT_CONTROL',
        'PURCHASES.VIEW'
    ]));
    if (!$allowed && function_exists('require_role')) {
        require_role(['SCM','ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','BRANCH','MANAGER','STAFF']);
    } elseif (!$allowed) {
        http_response_code(403); echo 'Forbidden'; exit;
    }
}

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$flash = p_flash_get();

$__towerUser = function_exists('auth_user') ? (array)auth_user() : [];

$__towerDeptCandidates = [
    function_exists('auth_dept') ? (string)auth_dept() : '',
    (string)($__towerUser['department'] ?? ''),
    (string)($__towerUser['dept'] ?? ''),
    (string)($__towerUser['dept_code'] ?? ''),
    (string)($_SESSION['department'] ?? ''),
    (string)($_SESSION['dept'] ?? ''),
    (string)($_SESSION['dept_code'] ?? ''),
];
$__towerDept = '';
foreach ($__towerDeptCandidates as $__d) {
    $__d = strtoupper(trim($__d));
    if ($__d !== '') { $__towerDept = $__d; break; }
}

$__towerRole = strtoupper(trim((string)($__towerUser['role'] ?? ($_SESSION['role'] ?? ''))));
$__towerLevel = strtoupper(trim((string)($__towerUser['level'] ?? ($_SESSION['level'] ?? ''))));
$__towerUsername = strtoupper(trim((string)(
    $__towerUser['username']
    ?? ($_SESSION['username'] ?? ($_SESSION['user_name'] ?? ''))
)));

$__towerIsSys = in_array($__towerDept, ['SYS','SYSTEM','IT'], true)
             || in_array($__towerRole, ['SYS','SYSTEM','ADMIN','SUPERADMIN'], true)
             || in_array($__towerLevel, ['SYS','SYSTEM','ADMIN','SUPERADMIN'], true)
             || strpos($__towerUsername, 'SYS') !== false;

$__towerIsPqp = ($__towerDept === 'PQP')
             || strpos($__towerUsername, 'PQP') !== false;

$__towerIsFin = !$__towerIsSys
             && !$__towerIsPqp
             && (
                  in_array($__towerDept, ['FIN','FINANCE'], true)
                  || strpos($__towerUsername, 'FIN') !== false
                );


// PQP final close LOCAL PO.
// FIN hanya AP/Payment; setelah Receiving + AP + Payment selesai, PQP yang CLOSED.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'close_local_po') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));

    if (!$__towerIsPqp && !$__towerIsSys) {
        p_flash_set('danger', 'Hanya PQP/SYS yang boleh menutup PO LOCAL.');
        rmi_redirect('purchases_control_tower.php');
    }

    $closePoId = (int)($_POST['po_id'] ?? 0);
    if ($closePoId <= 0) {
        p_flash_set('danger', 'PO tidak valid.');
        rmi_redirect('purchases_control_tower.php');
    }

    try {
        $st = $pdo->prepare("SELECT po.*,
            (SELECT COUNT(*) FROM purchases_import_control ic WHERE ic.po_id=po.id) AS import_control_id,
            (SELECT COUNT(*) FROM purchases_ceisa_pib cp WHERE cp.po_id=po.id) AS ceisa_pib_id,
            (SELECT COUNT(*) FROM purchases_forwarding_docs fd WHERE fd.po_id=po.id AND fd.deleted_at IS NULL) AS forwarding_docs_count
            FROM purchases_po po
            WHERE po.id=? AND po.deleted_at IS NULL
            LIMIT 1");
        $st->execute([$closePoId]);
        $poClose = $st->fetch(PDO::FETCH_ASSOC);
        if (!$poClose) throw new RuntimeException('PO tidak ditemukan.');
        if (p_po_flow($poClose) !== 'LOCAL') throw new RuntimeException('Hanya PO LOCAL yang dapat ditutup dari Local Purchase Control Tower.');

        $currentStatus = up((string)($poClose['status'] ?? ''));
        if ($currentStatus === 'CLOSED') {
            p_flash_set('success', 'PO sudah CLOSED.');
            rmi_redirect('purchases_control_tower.php?q=' . urlencode((string)$poClose['po_code']));
        }
        if ($currentStatus === 'CANCELLED') throw new RuntimeException('PO CANCELLED tidak dapat di-CLOSED.');

        // Gate 1: barang sudah diterima WQS.
        $receivedCount = 0;
        $incCols = p_cols($pdo, 'wqs_incoming');
        $incDeleted = in_array('deleted_at', $incCols, true) ? ' AND deleted_at IS NULL' : '';
        $st = $pdo->prepare("SELECT COUNT(*) FROM wqs_incoming WHERE po_code=?{$incDeleted}");
        $st->execute([(string)$poClose['po_code']]);
        $receivedCount = (int)$st->fetchColumn();
        if ($receivedCount <= 0) throw new RuntimeException('Belum bisa CLOSED: Receiving WQS belum ada.');

        // Gate 2: AP sudah dibuat.
        $st = $pdo->prepare("SELECT id,total_amount,status FROM purchases_invoice_ap WHERE po_id=? AND deleted_at IS NULL");
        $st->execute([$closePoId]);
        $apsClose = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$apsClose) throw new RuntimeException('Belum bisa CLOSED: AP Invoice belum dibuat.');

        // Gate 3: seluruh AP sudah lunas.
        foreach ($apsClose as $apClose) {
            $apId = (int)$apClose['id'];
            $apTotal = (float)($apClose['total_amount'] ?? 0);
            $stPay = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM purchases_payment_ap WHERE ap_id=? AND deleted_at IS NULL");
            $stPay->execute([$apId]);
            $paid = (float)$stPay->fetchColumn();
            if ($apTotal > 0.00001 && $paid + 0.00001 < $apTotal) {
                throw new RuntimeException('Belum bisa CLOSED: masih ada AP yang belum lunas.');
            }
        }

        $before = $poClose;
        $pdo->prepare("UPDATE purchases_po SET status='CLOSED' WHERE id=?")->execute([$closePoId]);
        $st = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? LIMIT 1");
        $st->execute([$closePoId]);
        $after = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        p_audit($pdo, 'PO', (string)$poClose['po_code'], 'CLOSE_LOCAL_PO', [
            'po_id' => $closePoId,
            'receiving_count' => $receivedCount,
            'ap_count' => count($apsClose),
            'closed_by' => p_username(),
        ]);
        if (function_exists('master_audit')) {
            master_audit($pdo, 'purchases_po', 'purchases_po', 'CLOSE_LOCAL_PO', $closePoId,
                (string)$poClose['po_code'], 'LOCAL PO closed after Receiving + AP + Payment complete',
                ['receiving_count'=>$receivedCount, 'ap_count'=>count($apsClose)]);
        }

        p_flash_set('success', 'PO ' . (string)$poClose['po_code'] . ' berhasil CLOSED.');
        rmi_redirect('purchases_control_tower.php?q=' . urlencode((string)$poClose['po_code']));
    } catch (Throwable $e) {
        if (function_exists('rmi_log_module_error')) {
            rmi_log_module_error('purchases_control_tower', $e, ['action'=>'CLOSE_LOCAL_PO','po_id'=>$closePoId]);
        }
        p_flash_set('danger', $e->getMessage());
        rmi_redirect('purchases_control_tower.php');
    }
}

$f_office = up($_GET['office_code'] ?? '');
$f_status = up($_GET['status'] ?? '');
$f_pay    = up($_GET['pay'] ?? '');
$f_pay_from = trim((string)($_GET['pay_from'] ?? ''));
$f_pay_to   = trim((string)($_GET['pay_to'] ?? ''));
$f_flow   = 'LOCAL';
$q        = trim((string)($_GET['q'] ?? ''));

// Validasi filter tanggal PAY agar query tetap aman/terprediksi.
$isValidYmd = static function (string $v): bool {
    if ($v === '') return true;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
};
if (!$isValidYmd($f_pay_from)) $f_pay_from = '';
if (!$isValidYmd($f_pay_to)) $f_pay_to = '';
if ($f_pay_from !== '' && $f_pay_to !== '' && $f_pay_from > $f_pay_to) {
    [$f_pay_from, $f_pay_to] = [$f_pay_to, $f_pay_from];
}
if (!in_array($f_pay, ['', 'PAID', 'UNPAID'], true)) $f_pay = '';

// Cari kolom tanggal payment secara kompatibel dengan schema lama/baru.
$paymentDateCol = null;
try {
    $payCols = p_cols($pdo, 'purchases_payment_ap');
    foreach (['payment_date','paid_date','pay_date','payment_at','paid_at','created_at'] as $candidate) {
        if (in_array($candidate, $payCols, true)) { $paymentDateCol = $candidate; break; }
    }
} catch (Throwable $e) {}

$offices=[];
try {
  $offices=$pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$where = ["po.deleted_at IS NULL"];
$params = [];

if ($f_office !== '') { $where[] = "UPPER(TRIM(COALESCE(po.office_code,'')))=UPPER(TRIM(?))"; $params[] = $f_office; }
if ($f_status !== '') { $where[] = "UPPER(TRIM(COALESCE(po.status,'')))=?"; $params[] = $f_status; }
if ($q !== '') {
  $where[] = "(po.po_code LIKE ? OR m.manufacture_name LIKE ? OR o.office_name LIKE ? OR pr.pr_code LIKE ?)";
  $like = '%'.$q.'%';
  array_push($params, $like, $like, $like, $like);
}

// Filter tanggal PAY menggunakan tanggal transaksi payment aktual.
// Dibuat sebagai EXISTS agar PO lama yang baru dibayar pada periode terpilih tetap ikut terambil.
if ($paymentDateCol !== null && ($f_pay_from !== '' || $f_pay_to !== '')) {
  $payDateWhere = ["ppa.deleted_at IS NULL", "pia.deleted_at IS NULL", "pia.po_id=po.id"];
  if ($f_pay_from !== '') { $payDateWhere[] = "DATE(ppa.`{$paymentDateCol}`) >= ?"; $params[] = $f_pay_from; }
  if ($f_pay_to !== '')   { $payDateWhere[] = "DATE(ppa.`{$paymentDateCol}`) <= ?"; $params[] = $f_pay_to; }
  $where[] = "EXISTS (
    SELECT 1
    FROM purchases_invoice_ap pia
    INNER JOIN purchases_payment_ap ppa ON ppa.ap_id=pia.id
    WHERE " . implode(' AND ', $payDateWhere) . "
  )";
}

$importEvidenceSql = "(
  EXISTS (SELECT 1 FROM purchases_import_control icf WHERE icf.po_id=po.id)
  OR po.forwarder_vendor_id IS NOT NULL
  OR EXISTS (SELECT 1 FROM purchases_ceisa_pib cpf WHERE cpf.po_id=po.id)
  OR EXISTS (
      SELECT 1 FROM purchases_forwarding_docs pfdf
      WHERE pfdf.po_id=po.id AND pfdf.deleted_at IS NULL
  )
)";

// Local Control Tower hanya menampilkan PO LOCAL.
// Explicit [FLOW:IMPORT] selalu dikeluarkan.
// PO legacy tanpa marker dianggap LOCAL hanya bila tidak memiliki evidence import.
$where[] = "(
  po.note LIKE '%[FLOW:LOCAL]%'
  OR (
    COALESCE(po.note,'') NOT LIKE '%[FLOW:LOCAL]%'
    AND COALESCE(po.note,'') NOT LIKE '%[FLOW:IMPORT]%'
    AND NOT {$importEvidenceSql}
  )
)";

$rows = [];

// Base PO query dibuat sengaja sederhana/robust.
// Jangan gabungkan semua tabel milestone dalam 1 SELECT, karena 1 kolom legacy yang
// tidak ada dapat membuat seluruh Control Tower kosong.
try {
  $sql = "
    SELECT
      po.*,
      o.office_name,
      m.manufacture_name,
      pr.pr_code
    FROM purchases_po po
    LEFT JOIN master_office o ON o.office_code=po.office_code
    LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
    LEFT JOIN wqs_pr pr ON pr.id=po.pr_id
    WHERE ".implode(" AND ", $where)."
    ORDER BY COALESCE(po.po_date,'0000-00-00') DESC, po.id DESC
    LIMIT 500
  ";
  $st=$pdo->prepare($sql);
  $st->execute($params);
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  if (function_exists('rmi_log_module_error')) {
    rmi_log_module_error('purchases_control_tower', $e, [
      'stage' => 'BASE_PO_QUERY',
      'office' => $f_office,
      'status' => $f_status,
      'flow' => $f_flow,
      'q' => $q,
    ]);
  }
}

// Map milestone dipisah. Kalau salah satu tabel/kolom legacy bermasalah,
// daftar PO tetap tampil dan hanya indikator terkait yang menjadi kosong.
$incomingMap = [];
try {
  $incCols = p_cols($pdo, 'wqs_incoming');
  $dateCol = in_array('received_date', $incCols, true)
      ? 'received_date'
      : (in_array('incoming_date', $incCols, true)
          ? 'incoming_date'
          : (in_array('created_at', $incCols, true) ? 'created_at' : null));

  $deletedWhere = in_array('deleted_at', $incCols, true) ? " WHERE deleted_at IS NULL" : "";
  $lastExpr = $dateCol !== null ? "MAX(`{$dateCol}`)" : "NULL";

  $st = $pdo->query("
    SELECT po_code, COUNT(*) AS cnt, {$lastExpr} AS last_received
    FROM wqs_incoming
    {$deletedWhere}
    GROUP BY po_code
  ");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
    $incomingMap[(string)$x['po_code']] = $x;
  }
} catch (Throwable $e) {}

$apMap = [];
try {
  $payLastExpr = $paymentDateCol !== null ? "MAX(`{$paymentDateCol}`)" : "NULL";
  $st = $pdo->query("
    SELECT
      ap.po_id,
      COUNT(*) AS ap_count,
      COALESCE(SUM(ap.total_amount),0) AS ap_total,
      COALESCE(SUM(COALESCE(pay.paid_amount,0)),0) AS ap_paid,
      MAX(pay.last_payment) AS last_payment
    FROM purchases_invoice_ap ap
    LEFT JOIN (
      SELECT ap_id, SUM(amount) AS paid_amount, {$payLastExpr} AS last_payment
      FROM purchases_payment_ap
      WHERE deleted_at IS NULL
      GROUP BY ap_id
    ) pay ON pay.ap_id=ap.id
    WHERE ap.deleted_at IS NULL
      AND ap.po_id IS NOT NULL
    GROUP BY ap.po_id
  ");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
    $apMap[(int)$x['po_id']] = $x;
  }
} catch (Throwable $e) {}

$importMap = [];
try {
  $st = $pdo->query("
    SELECT po.id AS po_id,
      CASE WHEN ic.po_id IS NOT NULL THEN 1 ELSE 0 END AS import_control_id,
      CASE WHEN cp.po_id IS NOT NULL THEN 1 ELSE 0 END AS ceisa_pib_id,
      CASE WHEN EXISTS (
        SELECT 1
        FROM purchases_forwarding_docs pfd
        WHERE pfd.po_id=po.id
          AND pfd.deleted_at IS NULL
      ) THEN 1 ELSE 0 END AS forwarding_docs_count
    FROM purchases_po po
    LEFT JOIN purchases_import_control ic ON ic.po_id=po.id
    LEFT JOIN purchases_ceisa_pib cp ON cp.po_id=po.id
    WHERE po.deleted_at IS NULL
  ");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
    $importMap[(int)$x['po_id']] = $x;
  }
} catch (Throwable $e) {}

// Enrich base rows in PHP so one optional subsystem cannot blank the table.
foreach ($rows as &$r) {
  $poId = (int)($r['id'] ?? 0);
  $poCode = (string)($r['po_code'] ?? '');

  $inc = $incomingMap[$poCode] ?? ['cnt'=>0, 'last_received'=>null];
  $ap  = $apMap[$poId] ?? ['ap_count'=>0, 'ap_total'=>0, 'ap_paid'=>0, 'last_payment'=>null];
  $imp = $importMap[$poId] ?? ['import_control_id'=>0, 'ceisa_pib_id'=>0, 'forwarding_docs_count'=>0];

  $r['incoming_count'] = (int)($inc['cnt'] ?? 0);
  $r['last_received'] = $inc['last_received'] ?? null;
  $r['ap_count'] = (int)($ap['ap_count'] ?? 0);
  $r['ap_total'] = (float)($ap['ap_total'] ?? 0);
  $r['ap_paid'] = (float)($ap['ap_paid'] ?? 0);
  $r['last_payment'] = $ap['last_payment'] ?? null;
  $r['import_control_id'] = (int)($imp['import_control_id'] ?? 0);
  $r['ceisa_pib_id'] = (int)($imp['ceisa_pib_id'] ?? 0);
  $r['forwarding_docs_count'] = (int)($imp['forwarding_docs_count'] ?? 0);
}
unset($r);

// PAY status ditentukan dari total seluruh AP vs total payment, bukan sekadar ada baris payment.
// Tanggal PAY memakai transaksi payment aktual terakhir untuk PO tersebut.
// Filter dilakukan setelah enrichment agar definisi PAID dan tanggal yang tampil sama dengan filter.
if ($f_pay !== '' || $f_pay_from !== '' || $f_pay_to !== '') {
  $rows = array_values(array_filter($rows, static function(array $r) use ($f_pay, $f_pay_from, $f_pay_to): bool {
    $apExists = (int)($r['ap_count'] ?? 0) > 0;
    $apTotal = (float)($r['ap_total'] ?? 0);
    $apPaid = (float)($r['ap_paid'] ?? 0);
    $paidOk = $apExists && ($apTotal <= 0.00001 ? $apPaid > 0.00001 : $apPaid + 0.00001 >= $apTotal);

    if ($f_pay === 'PAID' && !$paidOk) return false;
    if ($f_pay === 'UNPAID' && $paidOk) return false;

    if ($f_pay_from !== '' || $f_pay_to !== '') {
      $lastPayment = trim((string)($r['last_payment'] ?? ''));
      if ($lastPayment === '') return false;
      $paymentYmd = substr($lastPayment, 0, 10);
      if ($f_pay_from !== '' && $paymentYmd < $f_pay_from) return false;
      if ($f_pay_to !== '' && $paymentYmd > $f_pay_to) return false;
    }
    return true;
  }));
}

function pct_pill($state, $label) {
  $cls = $state === true ? 'ok' : ($state === 'WAIT' ? 'wait' : ($state === 'LOCK' ? 'lock' : 'no'));
  $dot = $state === true ? rmi_icon('check') : ($state === 'WAIT' ? rmi_icon('warn') : ($state === 'LOCK' ? rmi_icon('question') : rmi_icon('cross')));
  return "<span class='pill {$cls}'>{$dot} ".h($label)."</span>";
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Local Purchase Control Tower', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Local Purchase Control Tower',
  ],
  'actions' => [
    ['label' => 'PO List', 'url' => 'purchases_po.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Import Detail', 'url' => 'purchases_import_control_tower.php', 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/jquery.dataTables.min.css?v=20260209" rel="stylesheet">'
    . '<style>
      body{background:radial-gradient(1200px 800px at 20% 10%,#1f2937 0%,#0b1220 55%,#050814 100%);color:#e5e7eb;min-height:100vh;padding:18px}
      .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
      .muted{color:#9ca3af;font-size:12px}
      .form-control,.form-select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.14)!important}
      .btn-soft{border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e5e7eb}
      .pill{display:inline-flex;gap:6px;align-items:center;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);font-size:12px;margin:2px}
      .pill.ok{border-color:rgba(16,185,129,.35)} .pill.no{border-color:rgba(239,68,68,.35)}
      .pill.wait{border-color:rgba(234,179,8,.45)} .pill.lock{border-color:rgba(148,163,184,.35);opacity:.85}
      .num{text-align:right}
    </style>',
]);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted">Purchases • LOCAL</div>
    <h3 class="mb-0">Local Purchase Control Tower</h3>
    <div class="muted">
      Khusus PO LOCAL: PO → Supplier/Delivery → Receiving → AP → Payment → Closed.
      PO IMPORT dipantau terpisah di Import Control Tower.
    </div>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="card mb-3"><div class="card-body">
<form class="row g-2" method="get">
  <div class="col-md-2">
    <label class="form-label muted">Flow</label>
    <input class="form-control form-control-sm" value="LOCAL" readonly>
  </div>
  <div class="col-md-3">
    <label class="form-label muted">Office</label>
    <select class="form-select form-select-sm" name="office_code">
      <option value="">-- all --</option>
      <?php foreach($offices as $o): ?>
        <option value="<?=h($o['office_code'])?>" <?=$f_office===$o['office_code']?'selected':''?>><?=h($o['office_name'].' ('.$o['office_code'].')')?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label muted">Status</label>
    <select class="form-select form-select-sm" name="status">
      <option value="">-- all --</option>
      <?php foreach(['DRAFT','OPEN','IN_PRODUCTION','READY','CLOSED','CANCELLED'] as $s): ?>
        <option value="<?=h($s)?>" <?=$f_status===$s?'selected':''?>><?=h($s)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label muted">PAY</label>
    <select class="form-select form-select-sm" name="pay">
      <option value="">-- all --</option>
      <option value="PAID" <?=$f_pay==='PAID'?'selected':''?>>PAID / LUNAS</option>
      <option value="UNPAID" <?=$f_pay==='UNPAID'?'selected':''?>>UNPAID / BELUM LUNAS</option>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label muted">PAY dari tanggal</label>
    <input type="date" class="form-control form-control-sm" name="pay_from" value="<?=h($f_pay_from)?>" <?=$paymentDateCol===null?'disabled':''?>>
  </div>
  <div class="col-md-2">
    <label class="form-label muted">PAY s.d. tanggal</label>
    <input type="date" class="form-control form-control-sm" name="pay_to" value="<?=h($f_pay_to)?>" <?=$paymentDateCol===null?'disabled':''?>>
  </div>
  <div class="col-md-3">
    <label class="form-label muted">Search</label>
    <input class="form-control form-control-sm" name="q" value="<?=h($q)?>" placeholder="PO / PR / manufacture / office">
  </div>
  <div class="col-md-1 d-flex align-items-end gap-1">
    <button class="btn btn-primary btn-sm w-100">Filter</button>
    <a class="btn btn-soft btn-sm" href="purchases_control_tower.php" title="Reset filter">Reset</a>
  </div>
  <?php if ($paymentDateCol === null): ?>
    <div class="col-12"><div class="muted">Filter tanggal PAY dinonaktifkan karena kolom tanggal pada purchases_payment_ap tidak terdeteksi. Filter status PAY tetap dapat digunakan.</div></div>
  <?php endif; ?>
</form>
</div></div>

<div class="card mb-3"><div class="card-body">
<?php if ($__towerIsSys): ?>
  <b>SYS:</b> full access. SYS dapat membuka PO Full View, melihat Process / AP-Pay, memantau seluruh alur LOCAL, dan melakukan <b>Close PO</b> bila Receiving + AP + Payment selesai.
<?php elseif ($__towerIsPqp): ?>
  <b>PQP:</b> owner PO LOCAL. Alur: PO/Supplier → monitor Receiving → tunggu FIN AP + Payment → final check → <b>Close PO</b>.
<?php elseif ($__towerIsFin): ?>
  <b>FIN:</b> tugas hanya AP Invoice → AP Payment. FIN tidak edit/cancel/close PO. Setelah lunas, handoff kembali ke PQP untuk closing.
<?php else: ?>
  <span class="muted">Monitoring Local Purchase sesuai kewenangan role.</span>
<?php endif; ?>
</div></div>

<div class="card"><div class="card-body">
<div class="table-responsive">
<table id="tbl" class="display" style="width:100%">
<thead>
<tr>
  <th>PO</th><th>Flow</th><th>Office</th><th>Manufacture</th><th>Status</th>
  <th>Next / Progress</th><th>Milestones</th><th class="num">Amount</th><th>Action</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r):
  $flow = p_po_flow($r);
  $flowSource = p_po_flow_source($r);
  $status = up($r['status'] ?? '');
  $incomingOk = (int)($r['incoming_count'] ?? 0) > 0;
  $apExists = (int)($r['ap_count'] ?? 0) > 0;
  $apTotal = (float)($r['ap_total'] ?? 0);
  $apPaid = (float)($r['ap_paid'] ?? 0);
  $apPaidOk = $apExists && ($apTotal <= 0.00001 ? $apPaid > 0.00001 : $apPaid + 0.00001 >= $apTotal);

  $next = 'Final check / close PO';
  $nextPic = 'PQP';
  if ($status === 'CANCELLED') {
    $next = 'PO cancelled'; $nextPic = 'DONE';
  } elseif (!$incomingOk) {
    $next = 'Supplier / Receiving WQS'; $nextPic = 'PQP/WQS';
  } elseif (!$apExists) {
    $next = 'Buat / registrasi AP Invoice'; $nextPic = 'FIN';
  } elseif (!$apPaidOk) {
    $next = 'Proses pembayaran AP'; $nextPic = 'FIN';
  } elseif ($status !== 'CLOSED') {
    $next = 'FIN selesai — final check & Close PO'; $nextPic = 'PQP';
  } else {
    $next = 'Selesai'; $nextPic = 'DONE';
  }
?>
<tr>
<td>
  <b><?=h(up($r['po_code'] ?? ''))?></b>
  <div class="muted"><?=h($r['po_date'] ?? '')?> • PR <?=h(up($r['pr_code'] ?? '-'))?></div>
</td>
<td>
  <span class="pill <?=$flow==='IMPORT'?'wait':'ok'?>"><?=h($flow)?></span>
  <?php if ($flowSource !== 'EXPLICIT'): ?><div class="muted"><?=h($flowSource)?></div><?php endif; ?>
</td>
<td><?=h(up($r['office_name'] ?? $r['office_code'] ?? '-'))?></td>
<td><?=h(up($r['manufacture_name'] ?? '-'))?></td>
<td><?=h($status)?></td>
<td>
  <div><b><?=h($nextPic)?></b></div>
  <div><?=h($next)?></div>
  <?php if ($nextPic === 'FIN'): ?>
    <div class="muted">Tugas FIN hanya AP Invoice → AP Payment. Closing PO tetap PQP.</div>
  <?php endif; ?>
</td>
<td>
<?=pct_pill(true,'PO')?>
  <?=pct_pill($incomingOk,'RECEIVING')?>
  <?=pct_pill($apExists,'AP')?>
  <?=pct_pill($apPaidOk,'PAY')?>
  <?=pct_pill($status==='CLOSED','CLOSED')?>
  <div class="muted">Last receiving: <?=h($r['last_received'] ?? '-')?></div>
  <div class="muted">Last payment: <?=h($r['last_payment'] ?? '-')?></div>
</td>
<td class="num"><?=h(p_money($r['total_amount'] ?? 0, $r['currency'] ?? 'IDR'))?></td>
<td>
  <?php if ($__towerIsSys): ?>
    <a class="btn btn-primary btn-sm" href="purchases_fin_po_process.php?id=<?=h($r['id'])?>">Process / AP-Pay</a>
    <a class="btn btn-soft btn-sm mt-1" href="purchases_po_view.php?id=<?=h($r['id'])?>">PO Detail / Edit</a>
    <?php if ($incomingOk && $apExists && $apPaidOk && $status !== 'CLOSED' && $status !== 'CANCELLED'): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Tutup PO LOCAL ini? Receiving, AP Invoice, dan Payment harus sudah final.');">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="close_local_po">
        <input type="hidden" name="po_id" value="<?=h($r['id'])?>">
        <button class="btn btn-success btn-sm" type="submit">Close PO</button>
      </form>
    <?php elseif ($status === 'CLOSED'): ?>
      <div class="muted mt-1"><?= rmi_icon('tick') ?> CLOSED</div>
    <?php else: ?>
      <div class="muted mt-1">Close menunggu Receiving + AP + Payment</div>
    <?php endif; ?>

  <?php elseif ($__towerIsPqp): ?>
    <a class="btn btn-soft btn-sm" href="purchases_po_view.php?id=<?=h($r['id'])?>">PO / Final Check</a>
    <?php if ($incomingOk && $apExists && $apPaidOk && $status !== 'CLOSED' && $status !== 'CANCELLED'): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Final check selesai. Tutup PO LOCAL ini?');">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="close_local_po">
        <input type="hidden" name="po_id" value="<?=h($r['id'])?>">
        <button class="btn btn-success btn-sm" type="submit">Close PO</button>
      </form>
    <?php elseif ($status === 'CLOSED'): ?>
      <div class="muted mt-1"><?= rmi_icon('tick') ?> CLOSED</div>
    <?php elseif (!$incomingOk): ?>
      <div class="muted mt-1">Menunggu Receiving</div>
    <?php elseif (!$apExists || !$apPaidOk): ?>
      <div class="muted mt-1">Menunggu FIN AP/Payment</div>
    <?php endif; ?>

  <?php elseif ($__towerIsFin): ?>
    <a class="btn btn-primary btn-sm" href="purchases_fin_po_process.php?id=<?=h($r['id'])?>">Process / AP-Pay</a>
    <?php if (!$incomingOk): ?>
      <div class="muted mt-1">Waiting Receiving</div>
    <?php elseif (!$apExists): ?>
      <a class="btn btn-soft btn-sm mt-1" href="purchases_invoice_ap.php?po_id=<?=h($r['id'])?>">Create AP</a>
    <?php elseif (!$apPaidOk): ?>
      <a class="btn btn-soft btn-sm mt-1" href="purchases_payment_ap.php?po_id=<?=h($r['id'])?>">Pay AP</a>
    <?php else: ?>
      <div class="muted mt-1"><?= rmi_icon('tick') ?> FIN Done → PQP Close</div>
    <?php endif; ?>

  <?php else: ?>
    <a class="btn btn-soft btn-sm" href="purchases_po_readonly_view.php?id=<?=h($r['id'])?>">PO Detail</a>
  <?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="mt-3 muted">
  Halaman ini khusus PO LOCAL. PO IMPORT tidak ditampilkan dan tetap dipantau melalui Import Control Tower.
</div>
</div></div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script>
$(function(){
  $('#tbl').DataTable({pageLength:25, order:[[0,'desc']]});
});
</script>
<?php rmi_footer(); ?>
