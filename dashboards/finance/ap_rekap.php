<?php
declare(strict_types=1);
require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_login();

if (function_exists('require_any_permission')) {
    $allowed = function_exists('can_any') && can_any([
        'PURCHASES.AP_INVOICE_VIEW','PURCHASES.AP_PAYMENT_VIEW','PURCHASES.REPORTS_VIEW','PURCHASES.VIEW','DASHBOARD.FINANCE_VIEW'
    ]);
    if (!$allowed && function_exists('require_role')) {
        require_role(['FIN','ADMIN','SUPERADMIN','SYS','PQP']);
    } elseif (!$allowed) {
        http_response_code(403); echo 'Forbidden'; exit;
    }
} else {
    require_role(['FIN','ADMIN','SUPERADMIN','SYS','PQP']);
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Database connection required.'); }

if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
function ap_r_money($v): string { return number_format((float)$v, 0, ',', '.'); }
function ap_r_table(PDO $pdo, string $t): bool { try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch (Throwable $e) { return false; } }

$q = trim((string)($_GET['q'] ?? ''));
$status = strtoupper(trim((string)($_GET['status'] ?? '')));
$source = strtoupper(trim((string)($_GET['source'] ?? '')));
$office = strtoupper(trim((string)($_GET['office'] ?? '')));
$asOf = trim((string)($_GET['as_of'] ?? date('Y-m-d')));
if (!in_array($status, ['', 'UNPAID','PARTIAL','PAID'], true)) $status = '';
if (!in_array($source, ['', 'ERP','IMPORT'], true)) $source = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) $asOf = date('Y-m-d');

$scopeCtx = function_exists('ds_scope_ctx') ? ds_scope_ctx() : ['is_admin'=>true,'office_code'=>''];
$scopeOffice = strtoupper((string)($scopeCtx['office_code'] ?? ''));
$scopeOfficeFilter = !($scopeCtx['is_admin'] ?? true) && $scopeOffice !== '';
if ($scopeOfficeFilter) $office = $scopeOffice;

$officeList = [];
try {
    $st = $pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active=1 ORDER BY office_name");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $oc = strtoupper(trim((string)($r['office_code'] ?? '')));
        if ($oc !== '') $officeList[$oc] = (string)($r['office_name'] ?? $oc);
    }
} catch (Throwable $e) {}

$rows = [];
$error = '';

try {
    if (($source === '' || $source === 'ERP') && ap_r_table($pdo, 'purchases_invoice_ap')) {
        $hasPayments = ap_r_table($pdo, 'purchases_payment_ap');
        $hasMfr = ap_r_table($pdo, 'master_manufactures');
        $paidJoin = $hasPayments
            ? "LEFT JOIN (SELECT ap_id, SUM(amount) paid_amount FROM purchases_payment_ap WHERE deleted_at IS NULL AND pay_date<=? GROUP BY ap_id) paid ON paid.ap_id=ap.id"
            : "LEFT JOIN (SELECT NULL ap_id, 0 paid_amount) paid ON 1=0";
        $mfrJoin = $hasMfr ? "LEFT JOIN master_manufactures m ON m.id=ap.manufacture_id" : '';
        $supplierExpr = $hasMfr ? "COALESCE(NULLIF(m.manufacture_name,''), ap.ap_code)" : "ap.ap_code";

        $sql = "SELECT ap.id, ap.ap_code document_no, ap.invoice_date document_date, ap.due_date,
                       ap.invoice_number legacy_document_no, ap.office_code, ap.currency,
                       {$supplierExpr} supplier_name,
                       '' supplier_code,
                       ap.total_amount original_amount,
                       COALESCE(paid.paid_amount,0) paid_amount,
                       GREATEST(ap.total_amount-COALESCE(paid.paid_amount,0),0) outstanding_amount,
                       CASE
                         WHEN GREATEST(ap.total_amount-COALESCE(paid.paid_amount,0),0)<=0.01 THEN 'PAID'
                         WHEN COALESCE(paid.paid_amount,0)>0 THEN 'PARTIAL'
                         ELSE 'UNPAID' END ap_status,
                       'ERP' source
                FROM purchases_invoice_ap ap
                {$paidJoin}
                {$mfrJoin}
                WHERE ap.deleted_at IS NULL AND ap.invoice_date<=?";
        $params = $hasPayments ? [$asOf, $asOf] : [$asOf];
        if ($scopeOfficeFilter) { $sql .= " AND UPPER(COALESCE(ap.office_code,''))=?"; $params[]=$scopeOffice; }
        elseif ($office !== '') { $sql .= " AND UPPER(COALESCE(ap.office_code,''))=?"; $params[]=$office; }
        $st = $pdo->prepare($sql); $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $rows[] = $r;
    }

    if (($source === '' || $source === 'IMPORT') && ap_r_table($pdo, 'fin_ap_opening')) {
        $hasOpeningPays = ap_r_table($pdo, 'fin_ap_opening_payments');
        $paidJoin = $hasOpeningPays
            ? "LEFT JOIN (SELECT opening_ap_id, SUM(amount) paid_amount FROM fin_ap_opening_payments WHERE deleted_at IS NULL AND pay_date<=? GROUP BY opening_ap_id) px ON px.opening_ap_id=o.id"
            : "LEFT JOIN (SELECT NULL opening_ap_id, 0 paid_amount) px ON 1=0";
        $sql = "SELECT o.id, o.legacy_document_no document_no, o.document_date, o.due_date,
                       o.legacy_document_no, o.office_code, COALESCE(NULLIF(o.currency,''),'IDR') currency,
                       o.supplier_name, o.supplier_code,
                       o.original_amount,
                       (COALESCE(o.paid_amount,0)+COALESCE(px.paid_amount,0)) paid_amount,
                       GREATEST(o.original_amount-COALESCE(o.paid_amount,0)-COALESCE(px.paid_amount,0),0) outstanding_amount,
                       CASE
                         WHEN GREATEST(o.original_amount-COALESCE(o.paid_amount,0)-COALESCE(px.paid_amount,0),0)<=0.01 THEN 'PAID'
                         WHEN (COALESCE(o.paid_amount,0)+COALESCE(px.paid_amount,0))>0 THEN 'PARTIAL'
                         ELSE 'UNPAID' END ap_status,
                       'IMPORT' source
                FROM fin_ap_opening o
                {$paidJoin}
                WHERE UPPER(COALESCE(o.status,'UNPAID'))<>'CANCELLED' AND o.document_date<=?";
        $params = $hasOpeningPays ? [$asOf, $asOf] : [$asOf];
        if ($scopeOfficeFilter) { $sql .= " AND UPPER(COALESCE(o.office_code,''))=?"; $params[]=$scopeOffice; }
        elseif ($office !== '') { $sql .= " AND UPPER(COALESCE(o.office_code,''))=?"; $params[]=$office; }
        $st = $pdo->prepare($sql); $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $rows[] = $r;
    }
} catch (Throwable $e) { $error = $e->getMessage(); }

$rows = array_values(array_filter($rows, static function(array $r) use ($q,$status): bool {
    if ($status !== '' && strtoupper((string)($r['ap_status'] ?? '')) !== $status) return false;
    if ($q === '') return true;
    $hay = mb_strtolower(implode(' ', [
        $r['document_no'] ?? '', $r['legacy_document_no'] ?? '', $r['supplier_code'] ?? '',
        $r['supplier_name'] ?? '', $r['office_code'] ?? ''
    ]));
    return mb_strpos($hay, mb_strtolower($q)) !== false;
}));

usort($rows, fn($a,$b) => strcmp((string)($b['document_date'] ?? ''), (string)($a['document_date'] ?? '')));
$sumOriginal = array_sum(array_map(fn($r)=>(float)($r['original_amount'] ?? 0), $rows));
$sumPaid = array_sum(array_map(fn($r)=>(float)($r['paid_amount'] ?? 0), $rows));
$sumOutstanding = array_sum(array_map(fn($r)=>(float)($r['outstanding_amount'] ?? 0), $rows));

$bp = $GLOBALS['BASE_PROJECT'] ?? '';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
$extraHead = '';
if (is_file(__DIR__ . '/_rekap_styles.php')) { require __DIR__ . '/_rekap_styles.php'; $extraHead = $rekapExtraHead ?? ''; }
rmi_header('Rekap Hutang Supplier', [
    'active'=>'dashboard',
    'subtitle'=>'ERP + Hutang Lama • As of '.date('d-M-Y', strtotime($asOf)),
    'breadcrumbs'=>[
        ['label'=>'Finance','url'=>rtrim($bp,'/').'/dashboards/finance/ar_ap_cash_dashboard.php'],
        'Rekap Hutang'
    ],
    'actions'=>[
        ['label'=>'Import Hutang Lama','url'=>rtrim($bp,'/').'/purchases/purchases_ap_import.php','class'=>'btn btn-sm btn-outline-warning'],
        ['label'=>'AP Payment','url'=>rtrim($bp,'/').'/purchases/purchases_payment_ap.php','class'=>'btn btn-sm btn-outline-light'],
    ],
    'extra_head'=>$extraHead,
]);
?>
<div class="container-fluid py-4 px-4">
  <h3>Rekap Hutang Supplier</h3>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif; ?>
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-3"><input class="form-control" name="q" value="<?=h($q)?>" placeholder="Supplier / invoice / dokumen lama"></div>
    <div class="col-md-2"><select class="form-select" name="status">
      <option value="">Semua status</option>
      <option value="UNPAID" <?=$status==='UNPAID'?'selected':''?>>Belum Lunas</option>
      <option value="PARTIAL" <?=$status==='PARTIAL'?'selected':''?>>Sebagian</option>
      <option value="PAID" <?=$status==='PAID'?'selected':''?>>Lunas</option>
    </select></div>
    <div class="col-md-2"><select class="form-select" name="source">
      <option value="">Semua sumber</option>
      <option value="ERP" <?=$source==='ERP'?'selected':''?>>ERP</option>
      <option value="IMPORT" <?=$source==='IMPORT'?'selected':''?>>IMPORT</option>
    </select></div>
    <div class="col-md-2">
      <?php if ($scopeOfficeFilter): ?>
        <input type="hidden" name="office" value="<?=h($scopeOffice)?>"><input class="form-control" value="<?=h($scopeOffice)?>" disabled>
      <?php else: ?>
        <select class="form-select" name="office"><option value="">Semua Office</option><?php foreach($officeList as $oc=>$on): ?><option value="<?=h($oc)?>" <?=$office===$oc?'selected':''?>><?=h($on)?> (<?=h($oc)?>)</option><?php endforeach; ?></select>
      <?php endif; ?>
    </div>
    <div class="col-md-2"><input type="date" class="form-control" name="as_of" value="<?=h($asOf)?>"></div>
    <div class="col-md-1"><button class="btn btn-primary w-100">Filter</button></div>
  </form>

  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card p-3"><div class="text-muted small">Total Tagihan</div><div class="fs-5 fw-bold">Rp <?=ap_r_money($sumOriginal)?></div></div></div>
    <div class="col-md-4"><div class="card p-3"><div class="text-muted small">Total Sudah Dibayar</div><div class="fs-5 fw-bold">Rp <?=ap_r_money($sumPaid)?></div></div></div>
    <div class="col-md-4"><div class="card p-3"><div class="text-muted small">Total Belum Lunas</div><div class="fs-5 fw-bold">Rp <?=ap_r_money($sumOutstanding)?></div></div></div>
  </div>

  <div class="card p-3">
    <div class="table-responsive">
      <table class="table table-dark table-striped align-middle">
        <thead><tr><th>Dokumen</th><th>Tanggal</th><th>Supplier</th><th>Office</th><th>Tagihan</th><th>Dibayar</th><th>Outstanding</th><th>Jatuh Tempo</th><th>Status</th><th>Sumber</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $src = strtoupper((string)($r['source'] ?? ''));
          $payUrl = $src === 'ERP'
            ? rtrim($bp,'/').'/purchases/purchases_payment_ap.php?ap_id='.(int)($r['id']??0)
            : rtrim($bp,'/').'/purchases/purchases_payment_ap.php?opening_ap_id='.(int)($r['id']??0);
        ?>
          <tr>
            <td><strong><?=h($r['document_no'] ?? '-')?></strong></td>
            <td><?=h($r['document_date'] ?? '-')?></td>
            <td><strong><?=h($r['supplier_name'] ?? '-')?></strong><div class="small text-muted"><?=h($r['supplier_code'] ?? '')?></div></td>
            <td><?=h($r['office_code'] ?? '-')?></td>
            <td>Rp <?=ap_r_money($r['original_amount'] ?? 0)?></td>
            <td>Rp <?=ap_r_money($r['paid_amount'] ?? 0)?></td>
            <td><strong>Rp <?=ap_r_money($r['outstanding_amount'] ?? 0)?></strong></td>
            <td><?=h($r['due_date'] ?? '-')?></td>
            <td><?=h($r['ap_status'] ?? '-')?></td>
            <td><?=h($src)?></td>
            <td><?php if (($r['ap_status']??'') !== 'PAID'): ?><a class="btn btn-sm btn-outline-warning" href="<?=h($payUrl)?>">Bayar</a><?php else: ?><span class="text-success">Lunas</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted">Belum ada data sesuai filter.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
