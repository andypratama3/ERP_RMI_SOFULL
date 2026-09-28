<?php
// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once dirname(__DIR__, 1) . '/master/auth.php';
require_login();
// -------------------------------------------------------------

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
if (function_exists('require_any_permission')) {
    require_any_permission(['WQS.PR_VIEW', 'WQS.PR_CREATE', 'WQS.PR_EDIT', 'PURCHASES.PO_VIEW', 'PURCHASES.PO_CREATE', 'PURCHASES.PO_EDIT']);
} else {
    require_role(['ADMIN','SUPERADMIN','SYS','WQS','PQP','FIN','ACT','BRANCH','MANAGER','STAFF']);
}
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

require_once __DIR__ . '/../purchases/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$id=(int)($_GET['id'] ?? 0);
if ($id<=0) {
  http_response_code(400);
  rmi_header('Print PR', [
  'active' => 'stock',
  'breadcrumbs' => [
    ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
    'Print PR',
  ],
  'extra_head' => '<style>body{font-family:Arial,Helvetica,sans-serif;margin:24px}a{color:#0b5ed7;text-decoration:none}a:hover{text-decoration:underline}.btn{display:inline-block;padding:8px 12px;border:1px solid #bbb;border-radius:8px;margin-right:8px}</style>',
]);
?>

    <h2>PR ID tidak valid</h2>
    <p>Halaman print membutuhkan parameter <code>id</code>. Silakan buka dari daftar PR.</p>
    <p>
      <a class="btn" href="wqs_pr.php">Kembali ke WQS PR</a>
      <a class="btn" href="../master/logout.php">Logout</a>
    </p>
  <?php
  rmi_footer();
  exit;
}

$pr=null; $items=[];
try {
  $st=$pdo->prepare("SELECT pr.*, o.office_name FROM wqs_pr pr LEFT JOIN master_office o ON o.office_code=pr.office_code WHERE pr.id=? LIMIT 1");
  $st->execute([$id]); $pr=$st->fetch(PDO::FETCH_ASSOC);

  $st2=$pdo->prepare("SELECT * FROM wqs_pr_items WHERE pr_id=? AND deleted_at IS NULL ORDER BY line_no ASC");
  $st2->execute([$id]); $items=$st2->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!$pr) {
  http_response_code(404);
  rmi_header('Print PR', [
    'active' => 'stock',
    'breadcrumbs' => [
      ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
      'Print PR',
    ],
  ]);
  echo '<div class="alert alert-danger">PR not found.</div>';
  rmi_footer();
  exit;
}

// BRANCH office guard
$_prp_user_dept  = function_exists('auth_dept') ? strtoupper(auth_dept()) : '';
$_prp_user_role  = function_exists('auth_user') ? strtoupper(trim((string)((auth_user())['role'] ?? ''))) : '';
$_prp_user_office = function_exists('auth_office_code') ? strtoupper(trim(auth_office_code())) : '';
$_prp_is_branch = ($_prp_user_dept === 'BRANCH')
               && !in_array($_prp_user_role, ['SYS','ADMIN','SUPERADMIN'], true)
               && $_prp_user_office !== '';
if ($_prp_is_branch && strtoupper(trim($pr['office_code'] ?? '')) !== $_prp_user_office) {
  http_response_code(403);
  rmi_header('Print PR', ['active' => 'stock']);
  echo '<div class="alert alert-danger">Akses ditolak: PR ini bukan milik kantor Anda.</div>';
  rmi_footer();
  exit;
}

rmi_header('Print PR ' . (string)($pr['pr_code'] ?? ''), [
  'active' => 'stock',
  'subtitle' => 'Preview dokumen PR siap cetak',
  'breadcrumbs' => [
    ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
    ['label' => 'WQS PR', 'url' => $baseProject . '/stock/wqs_pr.php'],
    'Print PR',
  ],
  'extra_head' => '<style>
  .print-doc{font-family:Arial, sans-serif; font-size:12px; color:#111; background:#fff; border-radius:14px; padding:20px;}
  .print-doc h2{margin:0 0 6px 0;}
  .print-doc table{width:100%; border-collapse:collapse; margin-top:12px;}
  .print-doc th,.print-doc td{border:1px solid #333; padding:6px;}
  .print-doc th{background:#f2f2f2;}
  .print-doc .right{text-align:right;}
  .print-doc .muted{color:#666;}
  @media print {.rmi-topbar,.offcanvas,.no-print{display:none!important}.rmi-content{padding:0!important}.print-doc{border:0;border-radius:0;padding:0}}
</style>',
]);
?>
<div class="print-doc">
<div class="no-print" style="margin-bottom:10px">
  <button onclick="window.print()">Print</button>
</div>

<h2>PURCHASE REQUEST (PR)</h2>
<div class="muted">Rizqullah Mediska Indonesia</div>

<table style="margin-top:8px">
  <tr>
    <td style="width:50%">
      <b>PR Code</b><br><?=h($pr['pr_code'])?><br>
      Date: <?=h($pr['pr_date'])?><br>
      Status: <?=h($pr['status'])?><br>
      Kategori: <b><?=h(strtoupper($pr['category'] ?? 'BMHP'))?></b>
    </td>
    <td style="width:50%">
      <b>Office</b><br><?=h(strtoupper($pr['office_name'] ?? $pr['office_code'] ?? ''))?><br>
      <span class="muted"><?=h($pr['office_code'])?></span>
    </td>
  </tr>
</table>

<table>
  <thead>
    <tr><th style="width:40px">No</th><th style="width:90px">SKU</th><th>Product</th><th style="width:90px" class="right">Qty</th><th style="width:70px">Unit</th></tr>
  </thead>
  <tbody>
    <?php $no=1; foreach($items as $it): ?>
      <tr>
        <td class="right"><?=h($no++)?></td>
        <td><?=h(rmi_sku($it['sku']))?></td>
        <td><?=h(rmi_product_name($it['products_name']))?></td>
        <td class="right"><?=h($it['qty'])?></td>
        <td><?=h($it['unit'])?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div style="margin-top:14px">
  <b>Note:</b><br><?=nl2br(h($pr['note'] ?? ''))?>
</div>

<div style="margin-top:30px; display:flex; gap:40px">
  <div style="text-align:center; width:240px">
    <div>Requested By (WQS)</div>
    <div style="margin-top:60px">(__________________)</div>
  </div>
  <div style="text-align:center; width:240px">
    <div>Reviewed By</div>
    <div style="margin-top:60px">(__________________)</div>
  </div>
</div>
</div>
<?php rmi_footer(); ?>
