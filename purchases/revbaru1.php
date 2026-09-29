<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
    $allowed = function_exists('can_any') && can_any(['PURCHASES.IMPORT_CONTROL']);
    if (!$allowed && function_exists('require_role')) {
        require_role(['SCM','ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','BRANCH','MANAGER','STAFF']);
    } elseif (!$allowed) {
        http_response_code(403); echo 'Forbidden'; exit;
    }
} else {
    require_role(['SCM','ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','BRANCH','MANAGER','STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$flash = p_flash_get();

$f_office = up($_GET['office_code'] ?? '');
$f_status = up($_GET['status'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));

// Offices for filter
$offices=[];
try { $offices=$pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$where = "po.deleted_at IS NULL
  AND (
       po.note LIKE '%[FLOW:IMPORT]%'
       OR (
            COALESCE(po.note,'') NOT LIKE '%[FLOW:LOCAL]%'
            AND COALESCE(po.note,'') NOT LIKE '%[FLOW:IMPORT]%'
            AND (
                 ic.po_id IS NOT NULL
                 OR po.forwarder_vendor_id IS NOT NULL
                 OR cp.po_id IS NOT NULL
                 OR EXISTS (
                      SELECT 1
                      FROM purchases_forwarding_docs pfd0
                      WHERE pfd0.po_id=po.id
                        AND pfd0.deleted_at IS NULL
                 )
            )
       )
  )";
$params = [];
if ($f_office !== '') { $where .= " AND po.office_code=?"; $params[] = $f_office; }
if ($f_status !== '') { $where .= " AND po.status=?"; $params[] = $f_status; }
if ($q !== '') {
  $where .= " AND (po.po_code LIKE ? OR m.manufacture_name LIKE ? OR o.office_name LIKE ?)";
  $like = '%'.$q.'%';
  $params[] = $like; $params[] = $like; $params[] = $like;
}

// Base rows
$rows=[];
try {
  $st = $pdo->prepare("
    SELECT
      po.id, po.po_code, po.po_date, po.status, po.office_code, po.currency, po.total_amount, po.note,
      o.office_name,
      m.manufacture_name,
      po.forwarder_vendor_id, po.forwarder_status,
      v.vendors_name AS forwarder_name,
      ic.production_done_date, ic.pickup_date, ic.etd, ic.eta, ic.arrived_warehouse_date,
      cp.ceisa_status, cp.billing_amount, cp.sppb_no,
      ic.id AS import_control_id,
      cp.id AS ceisa_pib_id,
      CASE WHEN EXISTS (
        SELECT 1 FROM purchases_forwarding_docs pfdx
        WHERE pfdx.po_id=po.id AND pfdx.deleted_at IS NULL
      ) THEN 1 ELSE 0 END AS forwarding_docs_count
    FROM purchases_po po
    LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
    LEFT JOIN master_office o ON o.office_code=po.office_code
    LEFT JOIN master_vendors v ON v.id=po.forwarder_vendor_id
    LEFT JOIN purchases_import_control ic ON ic.po_id=po.id
    LEFT JOIN purchases_ceisa_pib cp ON cp.po_id=po.id
    WHERE {$where}
    ORDER BY COALESCE(po.po_date, '0000-00-00') DESC, po.id DESC
    LIMIT 500
  ");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Aggregations: AP DP/FINAL
$apMap = [];
try {
  $agg = $pdo->query("
    SELECT ap.po_id,
      SUM(CASE WHEN ap.invoice_type='PROFORMA' THEN ap.total_amount ELSE 0 END) AS dp_total,
      SUM(CASE WHEN ap.invoice_type='PROFORMA' THEN COALESCE(paid.paid_amount,0) ELSE 0 END) AS dp_paid,
      SUM(CASE WHEN ap.invoice_type='FINAL' THEN ap.total_amount ELSE 0 END) AS final_total,
      SUM(CASE WHEN ap.invoice_type='FINAL' THEN COALESCE(paid.paid_amount,0) ELSE 0 END) AS final_paid
    FROM purchases_invoice_ap ap
    LEFT JOIN (
      SELECT ap_id, SUM(amount) paid_amount
      FROM purchases_payment_ap
      WHERE deleted_at IS NULL
      GROUP BY ap_id
    ) paid ON paid.ap_id = ap.id
    WHERE ap.deleted_at IS NULL AND ap.po_id IS NOT NULL
    GROUP BY ap.po_id
  ")->fetchAll(PDO::FETCH_ASSOC);

  foreach ($agg as $a) {
    $apMap[(int)$a['po_id']] = $a;
  }
} catch (Throwable $e) {}

// Aggregations: docs required checklist
$docMap = [];
try {
  $docsAgg = $pdo->query("
    SELECT po_id,
      SUM(CASE WHEN doc_type='CIPL' THEN 1 ELSE 0 END) AS cipl,
      SUM(CASE WHEN doc_type='NIE_AKL' THEN 1 ELSE 0 END) AS nie_akl,
      SUM(CASE WHEN doc_type='HS_CODE' THEN 1 ELSE 0 END) AS hs_code,
      SUM(CASE WHEN doc_type='BL_FINAL' THEN 1 ELSE 0 END) AS bl_final,
      SUM(CASE WHEN doc_type='FORM_E' THEN 1 ELSE 0 END) AS form_e,
      SUM(CASE WHEN doc_type='BC11' THEN 1 ELSE 0 END) AS bc11,
      SUM(CASE WHEN doc_type='NOA' THEN 1 ELSE 0 END) AS noa,
      SUM(CASE WHEN doc_type='SPPB' THEN 1 ELSE 0 END) AS sppb
    FROM purchases_forwarding_docs
    WHERE deleted_at IS NULL
    GROUP BY po_id
  ")->fetchAll(PDO::FETCH_ASSOC);

  foreach ($docsAgg as $d) {
    $docMap[(int)$d['po_id']] = $d;
  }
} catch (Throwable $e) {}

// Aggregations: WQS incoming posted
$incomingMap = [];
try {
  $incAgg = $pdo->query("
    SELECT po_code, COUNT(*) cnt, MAX(received_date) last_received
    FROM wqs_incoming
    WHERE deleted_at IS NULL
    GROUP BY po_code
  ")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($incAgg as $r) {
    $incomingMap[(string)$r['po_code']] = $r;
  }
} catch (Throwable $e) {}

// Aggregations: PIB payment vs billing amount
$pibMap = [];
try {
  $pibAgg = $pdo->query("
    SELECT cp.po_id,
           cp.id pib_id,
           cp.billing_amount,
           COALESCE(SUM(pay.amount),0) paid_amount
    FROM purchases_ceisa_pib cp
    LEFT JOIN purchases_ceisa_payment pay ON pay.pib_id = cp.id AND pay.deleted_at IS NULL
    GROUP BY cp.po_id, cp.id, cp.billing_amount
  ")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($pibAgg as $p) {
    $pibMap[(int)$p['po_id']] = $p;
  }
} catch (Throwable $e) {}

// Aggregations: Forwarder invoice & payment (per PO)
$fwdMap = [];
try {
  $fwdAgg = $pdo->query("
    SELECT f.po_id,
           SUM(f.total_amount) total_amount,
           SUM(COALESCE(paid.paid_amount,0)) paid_amount
    FROM purchases_forwarder_invoice f
    LEFT JOIN (
      SELECT fap_id, SUM(amount) paid_amount
      FROM purchases_forwarder_payment
      WHERE deleted_at IS NULL
      GROUP BY fap_id
    ) paid ON paid.fap_id = f.id
    WHERE f.deleted_at IS NULL
    GROUP BY f.po_id
  ")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($fwdAgg as $x) {
    $fwdMap[(int)$x['po_id']] = $x;
  }
} catch (Throwable $e) {}

function pill($state, $label){
  $cls = 'no';
  $dot = rmi_icon('cross');
  if ($state === true) { $cls = 'ok'; $dot = rmi_icon('check'); }
  elseif ($state === false) { $cls = 'no'; $dot = rmi_icon('cross'); }
  elseif ($state === 'WAIT') { $cls = 'wait'; $dot = rmi_icon('warn'); }
  elseif ($state === 'LOCK') { $cls = 'lock'; $dot = rmi_icon('question'); }
  else { $cls = 'lock'; $dot = rmi_icon('question'); }
  return "<span class='pill {$cls}'>{$dot} ".h($label)."</span>";
}
function chip_role($role){
  $role = strtoupper(trim((string)$role));
  if ($role==='') $role='-';
  $cls = 'done';
  if ($role==='FIN') $cls='fin';
  elseif ($role==='PQP') $cls='pqp';
  elseif ($role==='SCM') $cls='scm';
  elseif ($role==='ACT') $cls='act';
  elseif ($role==='WQS') $cls='wqs';
  elseif ($role==='WAIT') $cls='wait';
  return "<span class='chip {$cls}'>".h($role)."</span>";
}

function is_done_amount($paid, $total){
  $paid = (float)$paid; $total=(float)$total;
  if ($total <= 0.00001) return ($paid > 0.00001); // best-effort
  return ($paid + 0.00001 >= $total);
}
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Import Control Tower', [
  'active' => 'purchases',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    'Import Control Tower',
  ],
  'actions' => [
    ['label' => rmi_icon('books').' Panduan', 'url' => $baseProject . '/purchases/panduan_import_tower.php', 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<style>body{background:radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
    .muted{color:#9ca3af;font-size:12px}
    .form-control,.form-select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.14)!important}
    .btn-soft{border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e5e7eb}
    .btn-soft:hover{background:rgba(255,255,255,.10);color:#fff}
    table.dataTable thead th,table.dataTable tbody td{color:#e5e7eb}
    .dt-buttons .btn,.dataTables_wrapper .dt-buttons button{border-radius:10px!important;border:1px solid rgba(255,255,255,.15)!important;background:rgba(255,255,255,.08)!important;color:#e5e7eb!important;padding:6px 10px!important;font-size:12px!important;}
    .dataTables_wrapper .dataTables_filter input,.dataTables_wrapper .dataTables_length select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.12)!important;border-radius:10px!important;}
    .pill{display:inline-flex;gap:6px;align-items:center;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);font-size:12px;margin:2px 2px}
    .pill.ok{border-color:rgba(16,185,129,.35)}
    .pill.no{border-color:rgba(239,68,68,.35)}
    .pill.wait{border-color:rgba(234,179,8,.45)}
    .pill.lock{border-color:rgba(148,163,184,.35);opacity:.85}
    .num{text-align:right}
    .actions a{margin:2px 4px 2px 0;display:inline-block;white-space:nowrap}
    .chip{display:inline-flex;gap:6px;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);font-size:12px;margin:2px 2px}
    .chip.fin{border-color:rgba(59,130,246,.45)}
    .chip.pqp{border-color:rgba(168,85,247,.45)}
    .chip.scm{border-color:rgba(245,158,11,.45)}
    .chip.act{border-color:rgba(16,185,129,.45)}
    .chip.wqs{border-color:rgba(239,68,68,.45)}
    .chip.wait{border-color:rgba(156,163,175,.55)}
    .chip.done{border-color:rgba(156,163,175,.35)}</style>',
]);
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted">Purchases • Import only</div>
    <h3 class="mb-0">Import Control Tower</h3>
    <div class="muted">Khusus PO IMPORT. PO dengan marker [FLOW:LOCAL] tidak pernah masuk ke halaman ini. Alur: DP → Produksi → Pelunasan → Forwarder/BL → Dokumen → BC1.1/NOA → CEISA/PIB → SPPB → Gudang → Receiving → Forwarder Payment</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-soft btn-sm" href="purchases_control_tower.php">← Purchases Control Tower</a>
    <a class="btn btn-soft btn-sm" href="purchases_dashboard.php">← PQP Dashboard</a>
    <a class="btn btn-soft btn-sm" href="../dashboards/index.php">Dashboard Center</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2" method="get">
      <div class="col-md-3">
        <label class="form-label muted">Office</label>
        <select class="form-select form-select-sm" name="office_code">
          <option value="">-- all --</option>
          <?php foreach($offices as $o): ?>
            <option value="<?=h($o['office_code'])?>" <?=($f_office===$o['office_code']?'selected':'')?>><?=h($o['office_name'].' ('.$o['office_code'].')')?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label muted">PO Status</label>
        <select class="form-select form-select-sm" name="status">
          <option value="">-- all --</option>
          <?php foreach(['DRAFT','OPEN','IN_PRODUCTION','READY','CLOSED','CANCELLED'] as $s): ?>
            <option value="<?=$s?>" <?=($f_status===$s?'selected':'')?>><?=$s?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label muted">Search</label>
        <input class="form-control form-control-sm" name="q" value="<?=h($q)?>" placeholder="PO code / manufacture / office">
      </div>
      <div class="col-md-2 d-flex align-items-end gap-2">
        <button class="btn btn-primary btn-sm w-100">Filter</button>
        <a class="btn btn-soft btn-sm" href="purchases_import_control_tower.php">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2">PO List</div>
    <div class="table-responsive">
      <table id="tbl" class="display" style="width:100%">
        <thead>
          <tr>
            <th>PO</th>
            <th>Office</th>
            <th>Manufacture</th>
            <th>Status</th>
            <th>Next (PIC)</th>
            <th>Milestones</th>
            <th>Customs</th>
            <th>Receiving</th>
            <th class="num">PO Amount</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r):
          $po_id = (int)$r['id'];
          $po_code = (string)$r['po_code'];
          $ap = $apMap[$po_id] ?? ['dp_total'=>0,'dp_paid'=>0,'final_total'=>0,'final_paid'=>0];
          $docs = $docMap[$po_id] ?? ['cipl'=>0,'nie_akl'=>0,'hs_code'=>0,'bl_final'=>0,'form_e'=>0,'bc11'=>0,'noa'=>0,'sppb'=>0];
          $inc = $incomingMap[$po_code] ?? ['cnt'=>0,'last_received'=>null];
          $pib = $pibMap[$po_id] ?? ['billing_amount'=>0,'paid_amount'=>0];

          $dpOk = (((float)($ap['dp_total'] ?? 0)) > 0.00001) && is_done_amount($ap['dp_paid'] ?? 0, $ap['dp_total'] ?? 0);
          $prodOk = !empty($r['production_done_date']);
          $finalNeed = ((float)($ap['final_total'] ?? 0)) > 0.00001;
          $finalOk = $finalNeed && is_done_amount($ap['final_paid'] ?? 0, $ap['final_total'] ?? 0);
          $ciplOk = ((int)($docs['cipl']??0) > 0);
          $fwSelected = !empty($r['forwarder_vendor_id']);
          $blOk = ((int)($docs['bl_final']??0) > 0);
          $pqpDocsOk = $ciplOk
                    && ((int)($docs['nie_akl']??0) > 0)
                    && ((int)($docs['hs_code']??0) > 0)
                    && ((int)($docs['form_e']??0) > 0);
          $arrivalDocsOk = ((int)($docs['bc11']??0) > 0) && ((int)($docs['noa']??0) > 0);
          $sppbOk = !empty($r['sppb_no']) || ((int)($docs['sppb']??0) > 0);
          $pibNeed = (((float)($pib['billing_amount'] ?? 0)) > 0.00001);
          $pibOk = $pibNeed && is_done_amount($pib['paid_amount'] ?? 0, $pib['billing_amount'] ?? 0);
          $recvOk = ((int)($inc['cnt']??0) > 0);

          $fwd = $fwdMap[$po_id] ?? ['total_amount'=>0,'paid_amount'=>0];
          $fwdInvoiceExists = ((float)($fwd['total_amount'] ?? 0)) > 0.00001;
          $fwdPaid = $fwdInvoiceExists && is_done_amount($fwd['paid_amount'] ?? 0, $fwd['total_amount'] ?? 0);

          $ceisa = strtoupper(trim((string)($r['ceisa_status'] ?? '')));
          // CEISA dianggap berhasil hanya pada status final/accepted. Dipakai oleh indikator Customs.
          // APPROVED terlihat pada data produksi saat ini; ACCEPTED/SUCCESS/OK dipertahankan untuk kompatibilitas status lama.
          $customsOk = in_array($ceisa, ['APPROVED', 'ACCEPTED', 'SUCCESS', 'OK'], true);
          $poStatus = strtoupper((string)($r['status'] ?? ''));

          $nextRole = 'DONE';
          $nextAction = 'Alur import selesai';

          // Urutan mengikuti handoff bisnis. Tidak mengubah data/status lama; hanya menentukan PIC berikutnya.
          if ($poStatus === 'CANCELLED') {
            $nextRole = 'DONE'; $nextAction = 'PO cancelled';
          } elseif (((float)($ap['dp_total'] ?? 0)) <= 0.00001) {
            $nextRole = 'FIN'; $nextAction = 'Buat/terima invoice DP (PROFORMA)';
          } elseif (!$dpOk) {
            $nextRole = 'FIN'; $nextAction = 'Bayar DP 30%';
          } elseif (!$prodOk) {
            $nextRole = 'PQP'; $nextAction = 'Pantau produksi / update produksi selesai';
          } elseif (!$finalNeed || !$ciplOk) {
            $nextRole = 'PQP'; $nextAction = 'Minta/unggah Final Invoice + CIPL';
          } elseif (!$finalOk) {
            $nextRole = 'FIN'; $nextAction = 'Pelunasan barang setelah produksi selesai';
          } elseif (!$fwSelected) {
            $nextRole = 'SCM'; $nextAction = 'RFQ, review & pilih forwarder';
          } elseif (!$blOk) {
            $nextRole = 'SCM'; $nextAction = 'Pickup + koreksi/finalisasi BL bersama PQP';
          } elseif (!$pqpDocsOk) {
            $nextRole = 'PQP'; $nextAction = 'Lengkapi NIE/AKL + HS Code + Form E';
          } elseif (!$arrivalDocsOk) {
            $nextRole = 'ACT'; $nextAction = 'Siapkan Draft PIB; tunggu/input BC 1.1 + NOA';
          } elseif ($ceisa === 'REJECT' || $ceisa === 'REJECTED') {
            $nextRole = 'ACT'; $nextAction = 'Perbaiki reject CEISA & resubmit';
          } elseif ($ceisa === '' || $ceisa === 'DRAFT') {
            $nextRole = 'ACT'; $nextAction = 'Submit PIB ke CEISA';
          } elseif ($ceisa === 'SUBMITTED' && !$pibNeed) {
            $nextRole = 'WAIT'; $nextAction = 'Tunggu hasil CEISA / ID Billing Aju';
          } elseif ($pibNeed && !$pibOk) {
            $nextRole = 'FIN'; $nextAction = 'Bayar Billing PIB';
          } elseif (!$sppbOk) {
            $nextRole = 'ACT'; $nextAction = 'Tunggu/unggah SPPB + Final PIB';
          } elseif (empty($r['arrived_warehouse_date'])) {
            $nextRole = 'SCM'; $nextAction = 'SK.DO bila perlu + tracking ke gudang';
          } elseif (!$recvOk) {
            $nextRole = 'WQS'; $nextAction = 'Cek barang & post receiving/incoming';
          } elseif (!$fwdInvoiceExists) {
            $nextRole = 'FIN'; $nextAction = 'Tunggu/registrasi invoice tagihan forwarder';
          } elseif (!$fwdPaid) {
            $nextRole = 'FIN'; $nextAction = 'Bayar biaya forwarder';
          } elseif ($poStatus !== 'CLOSED') {
            $nextRole = 'PQP'; $nextAction = 'Final check dokumen/Accurate lalu tutup PO';
          }

        ?>
          <?php $poSortKey = (string)($r['po_date'] ?: '0000-00-00') . '-' . str_pad((string)$po_id, 10, '0', STR_PAD_LEFT); ?>
          <tr>
            <td data-order="<?=h($poSortKey)?>">
              <?php
                $flowLabel = p_po_flow($r);
                $flowSource = p_po_flow_source($r);
              ?>
              <div><b><?=h(strtoupper($po_code ?? ''))?></b> <span class="pill ok"><?= rmi_icon('check') ?> <?=h($flowLabel)?></span></div>
              <div class="muted"><?=h($r['po_date'] ?? '')?></div>
              <?php if ($flowSource !== 'EXPLICIT'): ?>
                <div class="muted">Legacy flow: <?=h($flowSource)?></div>
              <?php endif; ?>
            </td>
            <td>
              <div><?=h(strtoupper($r['office_name'] ?? $r['office_code'] ?? ''))?></div>
              <div class="muted"><?=h(strtoupper($r['office_code'] ?? ''))?></div>
            </td>
            <td><?=h(strtoupper($r['manufacture_name'] ?? '-'))?></td>
            <td><?=h(strtoupper($r['status'] ?? ''))?></td>
            <td>
              <?=chip_role($nextRole)?>
              <div class="muted"><?=h($nextAction)?></div>
            </td>
            <td>
              <?php $prodState = $prodOk ? true : (($poStatus==='IN_PRODUCTION') ? 'WAIT' : false); ?>
              <?=pill($dpOk,'DP')?>
              <?=pill($prodState,'PROD')?>
              <?=pill($finalOk,'BAL')?>
              <?=pill($fwSelected,'FWD')?>
              <?=pill($blOk,'BL')?>
              <?=pill($pqpDocsOk,'DOCS')?>
              <?=pill($sppbOk,'SPPB')?>
              <?=pill(!empty($r['arrived_warehouse_date']),'WH')?>
            </td>
            <td>
              <?php
                $customsDocsReady = $pqpDocsOk && $blOk;
                $customsReady = $customsDocsReady && $arrivalDocsOk;

                // CEISA state: locked until DOCS+BL ready
                $ceisaState = 'LOCK';
                if ($customsReady) {
                  if ($customsOk) {
                    $ceisaState = true;
                  } elseif ($ceisa === 'SUBMITTED') {
                    $ceisaState = 'WAIT';
                  } elseif ($ceisa === 'REJECT' || $ceisa === 'REJECTED') {
                    $ceisaState = false;
                  } elseif ($ceisa === '' || $ceisa === 'DRAFT') {
                    $ceisaState = false;
                  } else {
                    $ceisaState = 'WAIT';
                  }
                }

                // PIB state: locked until CEISA/billing stage
                $pibState = 'LOCK';
                $pibLabel = 'PIB';
                if ($customsReady) {
                  if ($pibNeed) {
                    $pibState = $pibOk ? true : false;
                    $pibLabel = $pibOk ? 'PIB PAID' : 'PIB PAY';
                  } else {
                    // Billing belum keluar. Tampilkan WAIT hanya setelah CEISA submitted.
                    if ($ceisa === 'SUBMITTED') {
                      $pibState = 'WAIT';
                      $pibLabel = 'PIB WAIT';
                    } else {
                      $pibState = 'LOCK';
                      $pibLabel = 'PIB';
                    }
                  }
                }
              ?>
              <?=pill($ceisaState,'CEISA')?>
              <?=pill($pibState, $pibLabel)?>
              <div class="muted">Status: <?=h(strtoupper($r['ceisa_status'] ?? '-'))?></div>
              <?php if(!$customsDocsReady): ?><div class="muted">Menunggu: CIPL + NIE/AKL + HS + Form E + Final BL</div><?php elseif(!$arrivalDocsOk): ?><div class="muted">Draft PIB siap • menunggu BC 1.1 + NOA untuk submit</div><?php endif; ?>
            </td>
            <td>
              <?=pill($recvOk,'WQS IN')?>
              <?=pill($fwdPaid,'FWD PAY')?>
              <div class="muted">Last: <?=h($inc['last_received'] ?? '-')?></div>
            </td>
            <td class="num"><?=h(p_money($r['total_amount'] ?? 0, $r['currency'] ?? 'IDR'))?></td>
            <td>
              <div class="actions">
                <a class="btn btn-soft btn-sm" href="purchases_import_control_view.php?id=<?=h($po_id)?>">Open</a>
                <a class="btn btn-soft btn-sm" href="purchases_forwarder_quotes.php?po_id=<?=h($po_id)?>">Quotes</a>
                <a class="btn btn-soft btn-sm" href="purchases_ceisa_pib_view.php?id=<?=h($po_id)?>">CEISA/PIB</a>
                <a class="btn btn-soft btn-sm" href="purchases_forwarder_invoice.php?po_id=<?=h($po_id)?>">FWD Inv</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="mt-3 muted">
      Catatan: indikator hijau/merah bersifat <i>best-effort</i> dari data ERP yang ada. Kalau belum ada invoice/dokumen, indikator akan merah.
    </div>
  </div>
</div>

<?php
$auditModules = ['PURCHASES', 'PO', 'AP_INVOICE', 'FORWARDING', 'GR', 'PIB', 'PR_WQS'];
$auditLimit   = 10;
require __DIR__ . '/../dashboards/_audit_log_widget.php';
?>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script>
$(function(){
  $('#tbl').DataTable({
    pageLength: 25,
    order: [[0,'desc']],
    dom: 'Bfrtip',
    buttons: ['copy','csv','excel','print']
  });
});
</script>
<?php rmi_footer(); ?>
