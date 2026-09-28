<?php
$TITLE = "Print HRL Request";
require_once __DIR__ . '/_inc/bootstrap.php';

require_login(); // static scan marker
if (!hrlp_can_enter_module()) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo "ID invalid"; exit; }

$st = $pdo->prepare("SELECT * FROM hrl_requests WHERE id=? LIMIT 1");
$st->execute([$id]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); echo "Not found"; exit; }

if (!hrlp_can_view($r)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$HRLP_TYPES = [
    'CUTI' => 'Cuti',
    'IZIN' => 'Izin',
    'LEMBUR' => 'Lembur',
    'PERJADIN' => 'Perjadin',
    'PERMINTAAN_KARYAWAN' => 'Permintaan Karyawan',
    'KENAIKAN_GAJI' => 'Kenaikan Gaji',
    'REKRUTMEN' => 'Rekrutmen',
];
$typeLabel = $HRLP_TYPES[strtoupper((string)($r['req_type'] ?? ''))] ?? ($r['req_type'] ?? '');
$status = strtoupper((string)($r['status'] ?? ''));
$reqFin = hrlp_need_fin($r);

$stf = $pdo->prepare("SELECT * FROM hrl_request_files WHERE request_id=? ORDER BY id DESC");
$stf->execute([$id]);
$files = $stf->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$extraHead = '<style>
  @media print { .rmi-topbar, .rmi-topbar-actions, .offcanvas { display:none !important; } .rmi-content { padding:0 !important; } }';
$extraHead .= '
</style><style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111;margin:24px}
  .box{border:1px solid #ddd;border-radius:12px;padding:16px}
  h1{font-size:18px;margin:0 0 8px}
  .mut{color:#666;font-size:12px}
  table{width:100%;border-collapse:collapse;margin-top:10px}
  td,th{border:1px solid #ddd;padding:8px;font-size:13px;vertical-align:top}
  .sig{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:14px}
  .sig .sbox{border:1px dashed #bbb;border-radius:10px;padding:10px;min-height:110px}
  .sig .who{margin-top:46px;font-weight:700}
  .actions{margin:12px 0}
  @media print {.actions{display:none}}
</style>';

rmi_header(h($r['req_code'] ?? ('#'.$id)) . ' - Print', [
  'active' => 'hrl_process',
  'breadcrumbs' => [
    ['label' => 'HRL Process', 'url' => $baseProject . '/hrl_process/index.php'],
    'Print Request',
  ],
  'actions' => [
    ['label' => 'Print', 'url' => 'javascript:window.print()', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Back', 'url' => $baseProject . '/hrl_process/request_view.php?id=' . (int)$id, 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'extra_head' => $extraHead,
]);
?>
<div class="actions">
  <button onclick="window.print()">Print / Save PDF</button>
  <a href="<?= h($baseProject) ?>/hrl_process/request_view.php?id=<?= (int)$id ?>">Back</a>
</div>

<div class="box">
  <h1>HRL Request — <?= h($r['req_code'] ?? ('#'.$id)) ?></h1>
  <div class="mut"><?= h($typeLabel) ?> · Status: <?= h($status) ?></div>

  <table>
    <tr><th style="width:20%">Judul</th><td><?= h($r['title']) ?></td></tr>
    <tr><th>Pemohon</th><td><?= h($r['created_by'] ?? '-') ?></td></tr>
    <tr><th>Dept / Office</th><td><?= h($r['dept_code'] ?? '-') ?> / <?= h($r['office_code'] ?? '-') ?></td></tr>
    <tr><th>Tanggal</th><td><?= h($r['start_date'] ?? '-') ?> → <?= h($r['end_date'] ?? '-') ?></td></tr>
    <tr><th>Nominal</th><td><?= number_format((float)($r['amount'] ?? 0), 2) ?> <?= $reqFin ? '(requires FIN)' : '' ?></td></tr>
    <tr><th>Deskripsi</th><td style="white-space:pre-wrap"><?= h($r['description'] ?? '-') ?></td></tr>
  </table>

  <?php if ($files): ?>
    <div class="mut" style="margin-top:10px">Lampiran:</div>
    <ul>
      <?php foreach ($files as $f): ?>
        <li class="mut"><?= h($f['file_name']) ?> (<?= h($f['uploaded_by']) ?> · <?= h($f['uploaded_at'] ?? '') ?>)</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="sig">
    <div class="sbox">
      <div class="mut">Manager Dept</div>
      <div class="who"><?= h($r['manager_approved_by'] ?? '________') ?></div>
      <div class="mut"><?= h($r['manager_approved_at'] ?? '') ?></div>
    </div>
    <div class="sbox">
      <div class="mut">HRL</div>
      <div class="who"><?= h($r['hrl_approved_by'] ?? '________') ?></div>
      <div class="mut"><?= h($r['hrl_approved_at'] ?? '') ?></div>
    </div>
    <div class="sbox">
      <div class="mut">FIN <?= $reqFin ? '(jika ada biaya)' : '' ?></div>
      <div class="who"><?= h($r['fin_approved_by'] ?? '________') ?></div>
      <div class="mut"><?= h($r['fin_approved_at'] ?? '') ?></div>
      <div class="mut" style="margin-top:6px">PAID: <?= h($r['paid_by'] ?? '') ?> <?= h($r['paid_at'] ?? '') ?></div>
    </div>
  </div>

  <div class="mut" style="margin-top:10px">
    Dibuat: <?= h($r['created_at']) ?> oleh <?= h($r['created_by']) ?> ·
    Submit: <?= h($r['submitted_at'] ?? '-') ?>
  </div>
</div>
<?php rmi_footer(); ?>
