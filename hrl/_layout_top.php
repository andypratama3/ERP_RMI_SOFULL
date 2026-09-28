<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
// require_login(); // static scan marker

// hrl/_layout_top.php
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/_inc/schema.php';
hrl_schema_ensure($pdo);

$flash = flash_get();
$cur = basename((string)($_SERVER['PHP_SELF'] ?? ''));

require_once __DIR__ . '/../_shared/rmi_layout.php';

$pageTitle = $pageTitle ?? 'HRL Docs';
$pageSubtitle = $pageSubtitle ?? 'EnterprisePPP • Fase 1–3 • Versioning • Approval • Acknowledgement • Import • Audit Log';
$extraHead = '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
  . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">';

rmi_header($pageTitle, [
  'active' => 'hrl',
  'subtitle' => $pageSubtitle,
  'extra_head' => $extraHead,
]);
?>

<div class="rmi-card mb-3">
  <div class="rmi-card-header d-flex flex-wrap gap-3 justify-content-between align-items-start">
    <div>
      <div class="fw-semibold">HRL Docs</div>
      <div class="rmi-muted small">EnterprisePPP • Fase 1–3 • Versioning • Approval • Acknowledgement • Import • Audit Log</div>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
      <span class="badge rmi-badge"><?=e($HRL_USER['username'])?> • <?=e($HRL_USER['role'] ?: 'USER')?> • <?=e($HRL_USER['department'] ?: '-')?> • <?=e($HRL_USER['office_code'] ?: '-')?></span>
      <a class="btn btn-outline-light btn-sm" href="<?=e(url_home())?>">Home</a>
      <a class="btn btn-outline-light btn-sm" href="<?=e(url_logout())?>">Logout</a>
    </div>
  </div>
  <div class="card-body">
    <div class="d-flex flex-wrap gap-2 mb-3">
      <a class="btn btn-outline-light btn-sm <?=($cur==='hrl_docs.php' && empty($_GET['unit']))?'active':''?>" href="<?=e(url_hrl('hrl_docs.php'))?>">Docs</a>
      <a class="btn btn-outline-light btn-sm <?=($cur==='hrl_docs.php' && (($_GET['unit'] ?? '')==='HR'))?'active':''?>" href="<?=e(url_hrl('hrl_docs.php?unit=HR'))?>">HR</a>
      <a class="btn btn-outline-light btn-sm <?=($cur==='hrl_docs.php' && (($_GET['unit'] ?? '')==='LEGAL'))?'active':''?>" href="<?=e(url_hrl('hrl_docs.php?unit=LEGAL'))?>">Legal</a>
      <?php if ($HRL_CAN_MANAGE): ?>
        <a class="btn btn-outline-light btn-sm <?=($cur==='hrl_ack_report.php')?'active':''?>" href="<?=e(url_hrl('hrl_ack_report.php'))?>">Ack Report</a>
        <a class="btn btn-outline-light btn-sm <?=($cur==='hr_report_center.php')?'active':''?>" href="<?=e(url_hrl('hr_report_center.php'))?>">Report Center</a>
      <?php endif; ?>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?=e($flash['type'])?>"><?=e($flash['msg'])?></div>
    <?php endif; ?>
